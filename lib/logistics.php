<?php
// ===== 演示物流引擎（provider=simulated）=====
// 设计哲学与"演示支付"一致：不接真实快递 API，按发货时间生成确定性轨迹，让全流程体验完整。
// 将来接真实物流：新增 provider（如 kuaidi100），在 logistics_track() 里按 provider 分发，页面零改动。

// AMAP_KEY 来自 Web 根目录外 private/my_project/secrets.php（模板见 config/secrets.example.php），禁止硬编码入库。
// 本文件是物流页的首个密钥使用点：主动引入 secrets.php（幂等），保证 express_detail 直连链路可用。
if (!defined('AMAP_KEY') && function_exists('project_private_config_dir')) {
    $logisticsSecretsPath = project_private_config_dir() . '/secrets.php';
    if (is_file($logisticsSecretsPath)) require_once $logisticsSecretsPath;
}
unset($logisticsSecretsPath);

const LOGISTICS_HOURS = 48; // 发货→签收总时长（小时）

function logistics_provider(): string
{
    return 'simulated';
}

// 从地址提取城市：取第一个"市"（含）之前的段落；解析失败退回截断
function logistics_city(string $addr): string
{
    $addr = trim($addr);
    if ($addr === '') return '发货地';
    $pos = mb_strpos($addr, '市');
    if ($pos !== false && $pos <= 20) return mb_substr($addr, 0, $pos + 1);
    return mb_substr($addr, 0, 12);
}

// 生成模拟轨迹：确定性（同一订单任意时刻查看结果一致，不引入随机数）
// 返回 ['events'=>[['time','text','done']], 'stage'=>'已揽收|运输中|派送中|已签收', 'percent'=>0-100, 'eta'=>str, 'from'=>str, 'to'=>str]
function logistics_track(array $order, string $shipFrom): array
{
    $t0 = strtotime((string)$order['shipped_at']);
    $now = time();
    $fromCity = logistics_city($shipFrom !== '' ? $shipFrom : '杭州市');
    $toCity = logistics_city((string)$order['receiver_address']);
    $plan = [
        [0,  '快件已由商家交付【' . $fromCity . '】快递网点揽收'],
        [6,  '快件已到达【' . $fromCity . '】转运中心'],
        [18, '快件已从【' . $fromCity . '】转运中心发出，正发往【' . $toCity . '】'],
        [30, '快件已到达【' . $toCity . '】转运中心'],
        [40, '快件已到达【' . $toCity . '】营业部，快递员正在为您派送'],
        [48, '快件已签收，感谢您在惠蒙优选购物'],
    ];
    $events = [];
    foreach ($plan as [$h, $text]) {
        $ts = $t0 + $h * 3600;
        $events[] = ['time' => date('Y-m-d H:i', $ts), 'text' => $text, 'done' => $ts <= $now];
    }
    $percent = (int)max(0, min(100, floor(($now - $t0) / (LOGISTICS_HOURS * 3600) * 100)));
    if ($percent >= 100) $stage = '已签收';
    elseif ($percent >= 83) $stage = '派送中';
    elseif ($percent >= 13) $stage = '运输中';
    else $stage = '已揽收';
    return [
        'events'  => $events,
        'stage'   => $stage,
        'percent' => $percent,
        'eta'     => date('Y-m-d H:i', $t0 + LOGISTICS_HOURS * 3600),
        'from'    => $fromCity,
        'to'      => $toCity,
    ];
}

// 高德地理编码（Web 服务 Key，私有 secrets 的 AMAP_KEY；结果文件缓存 30 天）
// 返回 ['lng','lat'] 或 null（未配置 Key / 解析失败 / 网络）
function logistics_geocode(string $addr): ?array
{
    if (!defined('AMAP_KEY') || AMAP_KEY === '' || trim($addr) === '') return null;
    $cacheFile = sys_get_temp_dir() . '/hm_geo_' . md5($addr) . '.json';
    if (is_file($cacheFile)) {
        $hit = json_decode((string)file_get_contents($cacheFile), true);
        // 成功结果缓存 30 天；失败结果只缓存 10 分钟（配好 Key / 修好地址后快速自愈）
        $ttl = (is_array($hit) && $hit) ? 30 * 86400 : 600;
        if (filemtime($cacheFile) > time() - $ttl) return $hit ?: null;
    }
    $ch = curl_init('https://restapi.amap.com/v3/geocode/geo?key=' . urlencode(AMAP_KEY) . '&address=' . urlencode($addr));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
    $body = curl_exec($ch);
    curl_close($ch);
    $out = null;
    if (is_string($body)) {
        $d = json_decode($body, true);
        $loc = $d['geocodes'][0]['location'] ?? '';
        if (strpos($loc, ',') !== false) {
            [$lng, $lat] = array_map('floatval', explode(',', $loc, 2));
            if ($lng && $lat) $out = ['lng' => $lng, 'lat' => $lat];
        }
    }
    file_put_contents($cacheFile, json_encode($out ?: []));
    return $out;
}

// 高德静态地图 URL（发货地/收货地双标记 + 连线路径）；任一端解析失败返回 null
function logistics_map_url(array $order, string $shipFrom): ?string
{
    if (!defined('AMAP_KEY') || AMAP_KEY === '') return null;
    $from = logistics_geocode($shipFrom !== '' ? $shipFrom : '杭州市');
    $to = logistics_geocode((string)$order['receiver_address']);
    if (!$from || !$to) return null;
    $path = $from['lng'] . ',' . $from['lat'] . ';' . $to['lng'] . ',' . $to['lat'];
    $params = [
        'key'     => AMAP_KEY,
        'size'    => '600*300',
        'markers' => 'mid,0x2563EB:A:' . $from['lng'] . ',' . $from['lat'] . ';mid,0xF59E0B:B:' . $to['lng'] . ',' . $to['lat'],
        'paths'   => '5,0x2563EB,1,,:' . $path,
    ];
    return 'https://restapi.amap.com/v3/staticmap?' . http_build_query($params);
}
