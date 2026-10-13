<?php
require_once __DIR__ . '/lib/security.php';

require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/geocoding.php';
$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    die("<div style='text-align:center; padding:50px;'>未检测到数据库连接对象</div>");
}

if (!isset($_SESSION['user_id'])) {
    login_redirect(current_page_url());
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// ==================== 1. 处理 AJAX 删除地址请求 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $conn->begin_transaction();
    try {
        one('SELECT id FROM users WHERE id=? FOR UPDATE', [(int)$user_id]);
        $address_id=(int)($_POST['address_id'] ?? 0);
        $address=one('SELECT * FROM user_addresses WHERE id=? AND user_id=?',[$address_id,(int)$user_id]);
        if (!$address) throw new DomainException('地址不存在或无权操作');
        query('DELETE FROM user_addresses WHERE id=? AND user_id=?',[$address_id,(int)$user_id]);
        if ($address['is_default']) query('UPDATE user_addresses SET is_default=1 WHERE user_id=? ORDER BY id DESC LIMIT 1',[(int)$user_id]);
        $conn->commit(); echo json_encode(['status'=>1,'msg'=>'地址已删除']);
    } catch (Throwable $e) {
        $conn->rollback(); if (!($e instanceof DomainException)) error_log($e);
        echo json_encode(['status'=>0,'msg'=>$e instanceof DomainException?$e->getMessage():'删除失败，请稍后再试']);
    }
    exit;
}

// ==================== 2. 后端逆地理地址解析接口 ====================
// 高德 Web服务 Key：优先读环境变量 AMAP_KEY，其次读 Web 根目录外的私有配置文件。
// 申请地址：https://console.amap.com/dev/key/app
$AMAP_KEY = getenv('AMAP_KEY') ?: '';
$secretsPath = project_private_config_dir() . '/secrets.php';
if ($AMAP_KEY === '' && is_file($secretsPath)) {
    require_once $secretsPath;
    $AMAP_KEY = defined('AMAP_KEY') ? AMAP_KEY : '';
}

// GCJ-02（高德/火星坐标，即地图瓦片坐标系）转 WGS-84（GPS 坐标）
function gcj02ToWgs84($lng, $lat) {
    $a = 6378245.0;
    $ee = 0.00669342162296594323;

    $transformLat = function ($x, $y) use ($ee) {
        $ret = -100.0 + 2.0 * $x + 3.0 * $y + 0.2 * $y * $y + 0.1 * $x * $y + 0.2 * sqrt(abs($x));
        $ret += (20.0 * sin(6.0 * $x * M_PI) + 20.0 * sin(2.0 * $x * M_PI)) * 2.0 / 3.0;
        $ret += (20.0 * sin($y * M_PI) + 40.0 * sin($y / 3.0 * M_PI)) * 2.0 / 3.0;
        $ret += (160.0 * sin($y / 12.0 * M_PI) + 320 * sin($y * M_PI / 30.0)) * 2.0 / 3.0;
        return $ret;
    };
    $transformLng = function ($x, $y) use ($ee) {
        $ret = 300.0 + $x + 2.0 * $y + 0.1 * $x * $x + 0.1 * $x * $y + 0.1 * sqrt(abs($x));
        $ret += (20.0 * sin(6.0 * $x * M_PI) + 20.0 * sin(2.0 * $x * M_PI)) * 2.0 / 3.0;
        $ret += (20.0 * sin($x * M_PI) + 40.0 * sin($x / 3.0 * M_PI)) * 2.0 / 3.0;
        $ret += (150.0 * sin($x / 12.0 * M_PI) + 300.0 * sin($x / 30.0 * M_PI)) * 2.0 / 3.0;
        return $ret;
    };

    $dLat = $transformLat($lng - 105.0, $lat - 35.0);
    $dLng = $transformLng($lng - 105.0, $lat - 35.0);
    $radLat = $lat / 180.0 * M_PI;
    $magic = sin($radLat);
    $magic = 1 - $ee * $magic * $magic;
    $sqrtMagic = sqrt($magic);
    $dLat = ($dLat * 180.0) / (($a * (1 - $ee)) / ($magic * $sqrtMagic) * M_PI);
    $dLng = ($dLng * 180.0) / ($a / $sqrtMagic * cos($radLat) * M_PI);
    return [$lng - $dLng, $lat - $dLat];
}

// HTTP GET 并返回 JSON 数组（失败返回 null）
function httpGetJson($url, $provider) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = preg_replace('/[\r\n]+/', ' ', curl_error($ch));
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        error_log('reverse_geocode_request_failed provider='.$provider.' errno='.$errno.' http='.$httpCode.' error='.substr($error, 0, 200));
        return null;
    }
    return json_decode($response, true);
}

// IP 定位兜底：浏览器禁用 geolocation 时使用（城市级精度，无需授权）
if (isset($_GET['action']) && $_GET['action'] === 'ip_locate') {
    header('Content-Type: application/json; charset=utf-8');
    $result = ['status' => 0, 'msg' => '未能通过 IP 定位，请手动填写地址'];
    if (!empty($AMAP_KEY)) {
        $res = httpGetJson("https://restapi.amap.com/v3/ip?key=" . urlencode($AMAP_KEY), 'amap_ip');
        if ($res && isset($res['status']) && $res['status'] === '1' && !empty($res['province'])) {
            $province = is_string($res['province']) ? $res['province'] : '';
            $city = is_string($res['city'] ?? '') ? $res['city'] : '';
            $text = trim($province . $city);
            if ($text !== '') {
                $suffix = '（IP定位到城市，请补充详细地址）';
                if (mb_strlen($text) + mb_strlen($suffix) > 255) $text = mb_substr($text, 0, 255 - mb_strlen($suffix));
                $result = ['status' => 1, 'precision' => 'city', 'address' => $text . $suffix];
            }
        }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'parse_geo') {
    header('Content-Type: application/json; charset=utf-8');
    $lat = floatval($_GET['lat'] ?? 0);
    $lng = floatval($_GET['lng'] ?? 0);

    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
        echo json_encode(['status' => 0, 'msg' => '经纬度无效']);
        exit;
    }

    // 解析优先级：1) 高德（填了 Key 且坐标系一致最精准）2) BigDataCloud（免费无 Key，国内可访问）
    $formatted = '';

    if (!empty($AMAP_KEY)) {
        $apiUrl = "https://restapi.amap.com/v3/geocode/regeo?output=json&location={$lng},{$lat}&key={$AMAP_KEY}&extensions=base";
        $res = httpGetJson($apiUrl, 'amap');

        if ($res && isset($res['status']) && $res['status'] === '1' && !empty($res['regeocode']['formatted_address'])) {
            $formatted = $res['regeocode']['formatted_address'];
            // 拼接最近 POI / 商圈，提升辨识度
            $poi = $res['regeocode']['addressComponent']['building']['name'] ?? '';
            if (is_array($poi)) $poi = '';
            $neighborhood = $res['regeocode']['addressComponent']['neighborhood']['name'] ?? '';
            if (is_array($neighborhood)) $neighborhood = '';
            $landmark = $poi ?: $neighborhood;
            if ($landmark) {
                $formatted .= " ({$landmark}附近)";
            }
        }
    }

    if ($formatted === '') {
        // BigDataCloud 免费接口（无需 Key）。注意：该接口使用 WGS-84，先从 GCJ-02 转换
        list($wgs_lng, $wgs_lat) = gcj02ToWgs84($lng, $lat);
        $apiUrl = "https://api.bigdatacloud.net/data/reverse-geocode-client?latitude={$wgs_lat}&longitude={$wgs_lng}&localityLanguage=zh";
        $res = httpGetJson($apiUrl, 'bigdatacloud');

        $formatted = reverse_geocode_address_suggestion($res);
    }

    if ($formatted !== '') {
        echo json_encode(['status' => 1, 'address' => $formatted]);
        exit;
    }

    echo json_encode(['status' => 0, 'reason' => 'address_unavailable', 'msg' => '未能解析详细地址，请手动补充']);
    exit;
}

// ==================== 3. 处理新增 / 编辑地址提交 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id=(int)($_POST['edit_id'] ?? 0);
    $receiver_name=trim($_POST['receiver_name'] ?? '');
    $receiver_phone=trim($_POST['receiver_phone'] ?? '');
    $receiver_address=trim($_POST['receiver_address'] ?? '');
    $is_default=isset($_POST['is_default']) ? 1 : 0;
    $conn->begin_transaction();
    try {
        if (!$receiver_name || mb_strlen($receiver_name)>50 || !preg_match('/^[0-9+ -]{6,20}$/D',$receiver_phone) || mb_strlen($receiver_address)<5 || mb_strlen($receiver_address)>255) throw new DomainException('请填写有效的收货人、联系电话及详细地址（5 至 255 字）');
        one('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$user_id]);
        $existing=$edit_id ? one('SELECT * FROM user_addresses WHERE id=? AND user_id=?',[$edit_id,(int)$user_id]) : null;
        if ($edit_id && !$existing) throw new DomainException('地址不存在或无权修改');
        if (!one('SELECT id FROM user_addresses WHERE user_id=? AND is_default=1',[(int)$user_id])) $is_default=1;
        if ($existing && $existing['is_default']) $is_default=1;
        if ($is_default) query('UPDATE user_addresses SET is_default=0 WHERE user_id=?',[(int)$user_id]);
        if ($edit_id) query('UPDATE user_addresses SET receiver_name=?,receiver_phone=?,receiver_address=?,is_default=? WHERE id=? AND user_id=?',[$receiver_name,$receiver_phone,$receiver_address,$is_default,$edit_id,(int)$user_id]);
        else query('INSERT INTO user_addresses(user_id,receiver_name,receiver_phone,receiver_address,is_default) VALUES(?,?,?,?,?)',[(int)$user_id,$receiver_name,$receiver_phone,$receiver_address,$is_default]);
        $conn->commit(); header('Location: addresses.php?saved=1',true,303); exit;
    } catch (Throwable $e) {
        $conn->rollback(); if (!($e instanceof DomainException)) error_log($e);
        $error=$e instanceof DomainException?$e->getMessage():'保存失败，请稍后再试';
    }
}
if (isset($_GET['saved'])) $message='收货地址已保存';

// ==================== 4. 查询已保存地址列表 ====================
$addresses = [];
try {
    if ($db instanceof PDO) {
        $stmt = $db->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
        $stmt->execute([$user_id]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $addresses = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>收货地址管理</title>
    <link href="assets/vendor/bootstrap5/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body { background-color: #f8f9fa; }
        .main-container { max-width: 800px; margin: 20px auto 40px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .map-wrapper { position: relative; width: 100%; }
        #map-container {
            width: 100%;
            height: 350px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            margin-top: 10px;
            z-index: 1;
        }
        .btn-locate {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background-color: #ffffff;
            border: 1px solid #ced4da;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            border-radius: 50px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }
        .btn-locate:hover { background-color: #f8f9fa; color: #0d6efd; }
    </style>
</head>
<body>

<div class="container main-container">
    <!-- 返回个人中心导航 -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="user_center.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> 返回个人中心
        </a >
        <span class="text-muted small">收货地址管理</span>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card p-4 mb-4" id="form-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-dark mb-0" id="form-title">📍 新增收货地址</h5>
            <button type="button" class="btn btn-sm btn-link text-decoration-none text-muted d-none" id="btn-cancel-edit" onclick="resetForm()">
                <i class="bi bi-x-circle"></i> 取消编辑
            </button>
        </div>

        <form action="addresses.php" method="POST" id="address-form"><?php echo csrf_field(); ?>
            <input type="hidden" name="edit_id" id="edit_id" value="0">

            <div class="mb-3">
                <label class="form-label text-muted small">收货人姓名</label>
                <input type="text" name="receiver_name" id="receiver_name" class="form-control" placeholder="请输入收货人姓名" required maxlength="50">
            </div>

            <div class="mb-3">
                <label class="form-label text-muted small">联系电话</label>
                <input type="text" name="receiver_phone" id="receiver_phone" class="form-control" placeholder="请输入手机号码" required maxlength="20">
            </div>

            <div class="mb-3">
                <label class="form-label text-muted small">详细地址</label>
                <input type="text" id="detail_address" name="receiver_address" class="form-control" placeholder="请输入省市区、街道及门牌号，也可使用地图辅助" maxlength="255" autocomplete="off" required>
            </div>

            <div class="mb-3">
                <div class="map-wrapper">
                    <div id="map-container"></div>
                    <button type="button" class="btn btn-locate" onclick="getCurrentLocation()">
                        <i class="bi bi-geo-alt-fill text-danger"></i> 自动定位（也可直接拖动标记 / 点击地图选点）
                    </button>
                </div>
                <small class="text-muted mt-1 d-block"><i class="bi bi-info-circle"></i> 提示：拖动指针到具体楼栋或商铺，输入框将自动更新地址。</small>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="isDefault">
                <label class="form-check-label text-secondary" for="isDefault">设为默认地址</label>
            </div>

            <button type="submit" class="btn btn-primary px-4 py-2" id="submit-btn">保存新地址</button>
        </form>
    </div>

    <div class="card p-4">
        <h5 class="fw-bold mb-3 text-dark">已有收货地址</h5>
        
        <!-- 同级删除/修改局部提示 -->
        <div id="list-alert-area"></div>

        <?php if (empty($addresses)): ?>
            <p class="text-muted mb-0" id="no-address-msg">暂无保存的收货地址</p >
            <div class="list-group list-group-flush" id="address-list"></div>
        <?php else: ?>
            <div class="list-group list-group-flush" id="address-list">
                <?php foreach ($addresses as $addr): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-3" id="address-item-<?= $addr['id'] ?>">
                        <div>
                            <div class="fw-bold">
                                <?= htmlspecialchars($addr['receiver_name']) ?> 
                                <span class="text-muted ms-2 fw-normal"><?= htmlspecialchars($addr['receiver_phone']) ?></span>
                                <?php if (!empty($addr['is_default'])): ?>
                                    <span class="badge bg-danger ms-2">默认</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-secondary small mt-1"><?= htmlspecialchars($addr['receiver_address']) ?></div>
                        </div>

                        <div class="btn-group">
                            <!-- 编辑按钮 -->
                            <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="editAddress(<?= htmlspecialchars(json_encode($addr)) ?>)">
                                <i class="bi bi-pencil"></i> 编辑
                            </button>

                            <!-- 无刷新删除按钮 -->
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteAddress(<?= $addr['id'] ?>)">
                                <i class="bi bi-trash"></i> 删除
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let map = null;
let marker = null;

window.onload = function() {
    initMap(28.1123, 112.9889);
};

// 初始化地图并显式绑定指针 Marker
function initMap(lat, lng) {
    map = L.map('map-container', { zoomControl: true, attributionControl: false }).setView([lat, lng], 17);

    // 高德瓦片图层
    L.tileLayer('https://webrd0{s}.is.autonavi.com/appmaptile?lang=zh_cn&size=1&scale=1&style=8&x={x}&y={y}&z={z}', {
        subdomains: ['1', '2', '3', '4'],
        maxZoom: 19,
        minZoom: 3
    }).addTo(map);

    // 显式创建并绑定指针 Marker，修复指针不显示问题
    marker = L.marker([lat, lng], { draggable: true }).addTo(map);

    marker.on('dragend', function (e) {
        const pos = marker.getLatLng();
        parseBackendAddress(pos.lat, pos.lng);
    });

    map.on('click', function (e) {
        marker.setLatLng(e.latlng);
        parseBackendAddress(e.latlng.lat, e.latlng.lng);
    });

    // 不再自动定位：有线/无GPS环境会得到偏差很大的IP估算位置，由用户点击按钮或拖动地图选点
    setTimeout(() => { if (map) map.invalidateSize(); }, 300);
}

function outsideChina(lat, lng) {
    return lng < 72.004 || lng > 137.8347 || lat < 0.8293 || lat > 55.8271;
}

function wgs84ToGcj02(lat, lng) {
    if (outsideChina(lat, lng)) return [lat, lng];
    const a = 6378245.0;
    const ee = 0.00669342162296594323;
    const transformLat = (x, y) => {
        let value = -100.0 + 2.0 * x + 3.0 * y + 0.2 * y * y + 0.1 * x * y + 0.2 * Math.sqrt(Math.abs(x));
        value += (20.0 * Math.sin(6.0 * x * Math.PI) + 20.0 * Math.sin(2.0 * x * Math.PI)) * 2.0 / 3.0;
        value += (20.0 * Math.sin(y * Math.PI) + 40.0 * Math.sin(y / 3.0 * Math.PI)) * 2.0 / 3.0;
        return value + (160.0 * Math.sin(y / 12.0 * Math.PI) + 320.0 * Math.sin(y * Math.PI / 30.0)) * 2.0 / 3.0;
    };
    const transformLng = (x, y) => {
        let value = 300.0 + x + 2.0 * y + 0.1 * x * x + 0.1 * x * y + 0.1 * Math.sqrt(Math.abs(x));
        value += (20.0 * Math.sin(6.0 * x * Math.PI) + 20.0 * Math.sin(2.0 * x * Math.PI)) * 2.0 / 3.0;
        value += (20.0 * Math.sin(x * Math.PI) + 40.0 * Math.sin(x / 3.0 * Math.PI)) * 2.0 / 3.0;
        return value + (150.0 * Math.sin(x / 12.0 * Math.PI) + 300.0 * Math.sin(x / 30.0 * Math.PI)) * 2.0 / 3.0;
    };
    const dLat = transformLat(lng - 105.0, lat - 35.0);
    const dLng = transformLng(lng - 105.0, lat - 35.0);
    const radLat = lat / 180.0 * Math.PI;
    const magic = 1 - ee * Math.sin(radLat) * Math.sin(radLat);
    const sqrtMagic = Math.sqrt(magic);
    return [lat + (dLat * 180.0) / ((a * (1 - ee)) / (magic * sqrtMagic) * Math.PI), lng + (dLng * 180.0) / (a / sqrtMagic * Math.cos(radLat) * Math.PI)];
}

function showLocationHint(message) {
    const input = document.getElementById('detail_address');
    input.placeholder = message;
    if (input.value.startsWith('正在')) input.value = '';
}

// 获取定位
function getCurrentLocation() {
    const input = document.getElementById('detail_address');
    if (!navigator.geolocation) {
        showLocationHint('浏览器不支持自动定位，请手动填写地址');
        return;
    }
    input.value = '正在获取当前位置…';
    navigator.geolocation.getCurrentPosition(
        function (position) {
            const [lat, lng] = wgs84ToGcj02(position.coords.latitude, position.coords.longitude);
            map.setView([lat, lng], 17);
            marker.setLatLng([lat, lng]);
            const acc = Math.round(position.coords.accuracy || 0);
            if (acc > 500) {
                showLocationHint('定位精度约 ' + acc + ' 米（偏差可能较大），请拖动标记到准确位置后自动补全地址');
            }
            parseBackendAddress(lat, lng);
        },
        function (error) {
            // 浏览器在非 https / 非 localhost 页面会直接禁用定位，退化为 IP 定位
            fetch('addresses.php?action=ip_locate')
                .then(res => res.ok ? res.json() : Promise.reject())
                .then(data => {
                    if (data && data.status === 1 && typeof data.address === 'string') {
                        input.value = data.address;
                        showLocationHint('已自动定位到城市（精度有限），请补充详细地址');
                    } else {
                        showLocationHint(data && data.msg ? data.msg : '无法自动定位，请手动填写地址');
                    }
                })
                .catch(() => {
                    const messages = {
                        1: '未获定位授权，请允许浏览器定位或手动填写地址',
                        2: '暂时无法获取位置，请稍后重试或手动填写地址',
                        3: '定位超时，请重试或手动填写地址'
                    };
                    showLocationHint(messages[error.code] || '无法自动定位，请手动填写地址');
                });
        },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
    );
}

// 后端解析逆地理地址
function parseBackendAddress(lat, lng) {
    const input = document.getElementById('detail_address');
    input.value = '正在解析附近地址…';
    const query = new URLSearchParams({ action: 'parse_geo', lat: String(lat), lng: String(lng) });
    fetch(`addresses.php?${query}`)
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
        if (data && data.status === 1 && typeof data.address === 'string' && data.address.trim() !== '') input.value = data.address;
        else showLocationHint(data && data.msg ? data.msg : '未能解析详细地址，请手动补充');
    })
    .catch(() => showLocationHint('未能解析详细地址，请手动补充'));
}

// 填入数据进入编辑模式
function editAddress(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('receiver_name').value = data.receiver_name;
    document.getElementById('receiver_phone').value = data.receiver_phone;
    document.getElementById('detail_address').value = data.receiver_address;
    document.getElementById('isDefault').checked = parseInt(data.is_default) === 1;

    document.getElementById('form-title').innerText = "✏️ 修改收货地址";
    document.getElementById('submit-btn').innerText = "保存修改";
    document.getElementById('btn-cancel-edit').classList.remove('d-none');

    // 平滑滚动到表单顶部
    document.getElementById('form-card').scrollIntoView({ behavior: 'smooth' });
}

// 取消编辑，还原表单
function resetForm() {
    document.getElementById('edit_id').value = "0";
    document.getElementById('address-form').reset();
    document.getElementById('form-title').innerText = "📍 新增收货地址";
    document.getElementById('submit-btn').innerText = "保存新地址";
    document.getElementById('btn-cancel-edit').classList.add('d-none');
}

// AJAX 无刷新删除
function deleteAddress(addressId) {
    if (!confirm('确定要删除这个地址吗？')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
    formData.append('action', 'delete');
    formData.append('address_id', addressId);
    formData.append('is_ajax', '1');

    fetch('addresses.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (!res.ok) throw new Error('网络响应异常');
        return res.json();
    })
    .then(data => {
        if (data.status === 1) {
            const alertArea = document.getElementById('list-alert-area');
            if (alertArea) {
                alertArea.innerHTML = `
                    <div class="alert alert-success alert-dismissible fade show py-2 mb-3" role="alert">
                        <i class="bi bi-check-circle-fill me-1"></i> ${data.msg}
                        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                    </div>
                `;
            }

            const targetItem = document.getElementById(`address-item-${addressId}`);
            if (targetItem) {
                targetItem.remove();
            }

            const list = document.getElementById('address-list');
            if (list && list.children.length === 0) {
                list.insertAdjacentHTML('beforebegin', '<p class="text-muted mb-0" id="no-address-msg">暂无保存的收货地址</p >');
            }
        } else {
            alert(data.msg || '删除失败，请重试');
        }
    })
    .catch(err => {
        alert('请求失败，请稍后重试');
    });
}
</script>

<script src="assets/vendor/bootstrap5/bootstrap.bundle.min.js"></script>
</body>
</html>