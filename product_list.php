<?php
require_once __DIR__ . '/lib/security.php';

require_once 'config/db.php';
if (($_SESSION['role'] ?? '') === 'admin') { header('Location: admin/login.php', true, 303); exit; }
$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    die("<div style='text-align:center; padding:50px;'>未检测到数据库连接对象</div>");
}

if (!isset($_SESSION['user_id'])) {
    login_redirect(current_page_url());
}

$user_id = $_SESSION['user_id'];

// ==================== 1. 查询当前用户店铺（仅商户可访问） ====================
$shop = null;
try {
    if ($db instanceof PDO) {
        $stmt = $db->prepare("SELECT * FROM shops WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT * FROM shops WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $shop = $res ? $res->fetch_assoc() : null;
        }
    }
} catch (Exception $e) {}

if (!$shop || ($_SESSION['role'] ?? '') !== 'merchant' || (int)$shop['status'] !== 1) {
    // 非商户（无店铺记录），引导回商户管理页申请入驻
    header("Location: shop_manage.php");
    exit();
}

// ==================== 1.5 店铺发货地设置（演示物流起点，express_detail.php 消费） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_ship_from') {
    csrf_check();
    $shipFrom = trim((string)($_POST['ship_from'] ?? ''));
    if (mb_strlen($shipFrom) > 255) $shipFrom = mb_substr($shipFrom, 0, 255);
    if ($db instanceof PDO) {
        $st = $db->prepare("UPDATE shops SET ship_from = ? WHERE id = ?");
        $st->execute([$shipFrom, $shop_id]);
    } elseif ($db instanceof mysqli) {
        $st = $db->prepare("UPDATE shops SET ship_from = ? WHERE id = ?");
        $st->bind_param("si", $shipFrom, $shop_id);
        $st->execute();
    }
    redirect_to('product_list.php', '发货地已保存');
}

$shop_id = intval($shop['id']);

// ==================== 2. AJAX 无刷新操作：下架 / 上下架切换 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['is_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $product_id = intval($_POST['product_id'] ?? 0);

    if ($product_id <= 0) {
        echo json_encode(['status' => 0, 'msg' => '参数错误']);
        exit;
    }

    // 预初始化，避免 IDE/静态分析报“变量可能未定义”警告
    $affected = 0;
    $new_status = null;

    try {
        if ($action === 'delete') {
            // 仅允许下架本店商品，防止越权
            if ($db instanceof PDO) {
                $stmt = $db->prepare("UPDATE products SET status = 0 WHERE id = ? AND shop_id = ?");
                $stmt->execute([$product_id, $shop_id]);
                $affected = $stmt->rowCount();
            } elseif ($db instanceof mysqli) {
                $stmt = $db->prepare("UPDATE products SET status = 0 WHERE id = ? AND shop_id = ?");
                if ($stmt) {
                    $stmt->bind_param("ii", $product_id, $shop_id);
                    $stmt->execute();
                    $affected = $db->affected_rows;
                } else {
                    $affected = 0;
                }
            }
            echo json_encode($affected > 0
                ? ['status' => 1, 'msg' => '商品已成功下架！']
                : ['status' => 0, 'msg' => '下架失败：商品不存在或无权操作']);
            exit;
        }

        if ($action === 'toggle_status') {
            // 已驳回商品仅可下架，重新上架前必须编辑并重新提交审核。
            $sql = "UPDATE products SET status = 1 - status WHERE id = ? AND shop_id = ? AND NOT (status = 0 AND audit_status = 'rejected')";
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute([$product_id, $shop_id]);
                $affected = $stmt->rowCount();

                $stmt2 = $db->prepare('SELECT status, audit_status FROM products WHERE id = ? AND shop_id = ?');
                $stmt2->execute([$product_id, $shop_id]);
                $productStatus = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
            } elseif ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $product_id, $shop_id);
                    $stmt->execute();
                    $affected = $db->affected_rows;

                    $stmt2 = $db->prepare('SELECT status, audit_status FROM products WHERE id = ? AND shop_id = ?');
                    $stmt2->bind_param("ii", $product_id, $shop_id);
                    $stmt2->execute();
                    $productStatus = $stmt2->get_result()->fetch_assoc() ?: null;
                } else {
                    $productStatus = null;
                }
            }
            $new_status = $productStatus['status'] ?? null;
            echo json_encode($affected > 0
                ? ['status' => 1, 'msg' => $new_status == 1 ? '商品已上架！' : '商品已下架（转入草稿）！', 'new_status' => intval($new_status)]
                : (($productStatus['audit_status'] ?? '') === 'rejected' && intval($new_status) === 0
                    ? ['status' => 0, 'msg' => '该商品审核未通过，请编辑后重新提交审核再上架']
                    : ['status' => 0, 'msg' => '操作失败：商品不存在或无权操作']));
            exit;
        }

        echo json_encode(['status' => 0, 'msg' => '未知操作']);
        exit;
    } catch (Exception $e) {
        error_log('merchant_product_status_change_failed: ' . $e->getMessage());
        echo json_encode(['status' => 0, 'msg' => '操作失败，请稍后重试']);
        exit;
    }
}

// ==================== 3. 查询本店商品列表 ====================
$products = [];
try {
    $sql = "SELECT p.*, c.category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.shop_id = ?
            ORDER BY p.status DESC, p.id DESC";
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$shop_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $shop_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $products = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
    }
} catch (Exception $e) {}

$on_sale = 0;
$off_sale = 0;
$total_stock = 0;
foreach ($products as $p) {
    if ($p['status'] == 1) $on_sale++; else $off_sale++;
    $total_stock += intval($p['stock']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商品列表管理</title>
    <link href="assets/vendor/bootstrap5/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { background: #f1f5f9; }
        .main-container { max-width: 960px; margin: 24px auto 48px; }
        .card { border: none; border-radius: 16px; box-shadow: 0 6px 24px rgba(15,23,42,.08); }
        .product-thumb {
            width: 72px; height: 72px; object-fit: cover;
            border-radius: 12px; background: #f1f5f9; border: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: center;
            color: #adb5bd; font-size: 1.4rem; flex-shrink: 0;
        }
        .stat-pill {
            background: #fff; border-radius: 14px; padding: 18px 12px; text-align: center;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .stat-pill:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(15,23,42,.12); }
        #product-list .list-group-item { border-radius: 12px; transition: background .2s ease; }
        #product-list .list-group-item:hover { background: #f8fafc; }
        .badge { border-radius: 999px; padding: .4em .8em; font-weight: 600; }
        .text-muted.small { color: #64748b; }
        .btn-success { background: #2563eb; border-color: #2563eb; }
        .btn-success:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-outline-success { color: #2563eb; border-color: #2563eb; }
        .btn-outline-success:hover { background: #2563eb; border-color: #2563eb; }
    </style>
</head>
<body>

<div class="container main-container">
    <!-- 顶部导航 -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="shop_manage.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> 返回店铺控制台
        </a >
        <span class="text-muted small"><?= htmlspecialchars($shop['shop_name']) ?> · 商品管理</span>
    </div>

    <!-- 发货地设置：演示物流轨迹的起点（买家在物流页看到"从哪里发货"） -->
    <div class="card mb-3"><div class="card-body py-2">
        <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_ship_from">
            <span class="text-muted small">发货地（演示物流起点，如：浙江省杭州市）</span>
            <input class="form-control form-control-sm" style="max-width:280px" name="ship_from" maxlength="255" value="<?= htmlspecialchars($shop['ship_from'] ?? '') ?>" placeholder="省份+城市">
            <button class="btn btn-sm btn-primary">保存发货地</button>
        </form>
    </div></div>

    <!-- 统计概览 -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="card stat-pill">
                <div class="fs-4 fw-bold text-primary mb-0"><?= count($products) ?></div>
                <div class="text-muted small">全部商品</div>
            </div>
        </div>
        <div class="col-4">
            <div class="card stat-pill">
                <div class="fs-4 fw-bold text-success mb-0" id="stat-onsale"><?= $on_sale ?></div>
                <div class="text-muted small">上架中</div>
            </div>
        </div>
        <div class="col-4">
            <div class="card stat-pill">
                <div class="fs-4 fw-bold text-warning mb-0" id="stat-offsale"><?= $off_sale ?></div>
                <div class="text-muted small">草稿 / 已下架</div>
            </div>
        </div>
    </div>

    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-box-seam text-primary me-2"></i>我的商品</h5>
            <a href="product_add.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-circle"></i> 发布新商品
            </a >
        </div>
        <hr>

        <!-- 同级局部提示区（AJAX 操作结果注入此处，页面不刷新不回顶） -->
        <div id="list-alert-area"></div>

        <?php if (empty($products)): ?>
            <div class="text-center py-5" id="empty-tip">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                <p class="text-muted mb-3">还没有发布任何商品</p >
                <a href="product_add.php" class="btn btn-outline-success btn-sm">立即发布第一个商品</a >
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush" id="product-list">
                <?php foreach ($products as $p): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-3 flex-wrap gap-2" id="product-item-<?= $p['id'] ?>">
                        <div class="d-flex align-items-center gap-3">
                            <?php if (!empty($p['image_url']) && file_exists($p['image_url'])): ?>
                                <img src="<?= htmlspecialchars($p['image_url']) ?>" class="product-thumb" alt="">
                            <?php else: ?>
                                <div class="product-thumb"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold">
                                    <?= htmlspecialchars($p['title']) ?>
                                    <?php if ($p['status'] == 1): ?>
                                        <span class="badge bg-success ms-1 status-badge">上架中</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary ms-1 status-badge">已下架</span>
                                    <?php endif; ?>
                                    <?php
                                        $as = $p['audit_status'] ?? 'approved';
                                        if ($as === 'pending'): ?>
                                        <span class="badge bg-warning text-dark ms-1">待审核</span>
                                    <?php elseif ($as === 'rejected'): ?>
                                        <span class="badge bg-danger ms-1">审核未通过</span>
                                    <?php endif; ?>
                                    <?php if (intval($p['stock']) <= 0): ?>
                                        <span class="badge bg-danger ms-1">缺货</span>
                                    <?php endif; ?>
                                    <?php if ($as === 'pending'): ?>
                                        <div class="text-warning small mt-1"><i class="bi bi-clock"></i> 商品正在等待管理员审核，通过后才会在前台展示。</div>
                                    <?php elseif ($as === 'rejected'): ?>
                                        <div class="text-danger small mt-1"><i class="bi bi-exclamation-circle"></i> 商品被管理员驳回，请修改商品信息后重新提交审核。</div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-secondary small mt-1">
                                    <span class="text-danger fw-bold">￥<?= number_format($p['price'], 2) ?></span>
                                    <span class="mx-2">|</span>库存：<?= intval($p['stock']) ?>
                                    <?php if (!empty($p['category_name'])): ?>
                                        <span class="mx-2">|</span><?= htmlspecialchars($p['category_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <a href="product_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-pencil"></i> 编辑
                            </a >
                            <?php if ($as === 'rejected' && $p['status'] == 0): ?>
                                <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">请编辑后重新提交审核</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-warning btn-sm toggle-btn" data-id="<?= $p['id'] ?>" data-status="<?= $p['status'] ?>">
                                    <i class="bi bi-arrow-repeat"></i> <?= $p['status'] == 1 ? '下架' : '上架' ?>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteProduct(<?= $p['id'] ?>)">
                                <i class="bi bi-trash"></i> 下架
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// 局部提示：注入 #list-alert-area，不刷新不回顶
function showAlert(msg, type = 'success') {
    const area = document.getElementById('list-alert-area');
    if (area) {
        area.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show py-2 mb-3" role="alert">
                <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} me-1"></i> ${msg}
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        `;
    }
}

function refreshStats() {
    const list = document.getElementById('product-list');
    if (!list) return;
    let on = 0, off = 0;
    list.querySelectorAll('.toggle-btn').forEach(btn => {
        if (btn.dataset.status == 1) on++; else off++;
    });
    const onEl = document.getElementById('stat-onsale');
    const offEl = document.getElementById('stat-offsale');
    if (onEl) onEl.innerText = on;
    if (offEl) offEl.innerText = off;
}

// AJAX 无刷新下架
function deleteProduct(productId) {
    if (!confirm('确定要下架这个商品吗？下架后不可恢复！')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
    formData.append('action', 'delete');
    formData.append('product_id', productId);
    formData.append('is_ajax', '1');

    fetch('product_list.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (!res.ok) throw new Error('网络响应异常');
        return res.json();
    })
    .then(data => {
        if (data.status === 1) {
            showAlert(data.msg);
            const item = document.getElementById(`product-item-${productId}`);
            if (item) item.remove();
            refreshStats();

            const list = document.getElementById('product-list');
            if (list && list.children.length === 0) {
                list.insertAdjacentHTML('beforebegin', `
                    <div class="text-center py-5">
                        <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                        <p class="text-muted mb-3">还没有发布任何商品</p >
                        <a href="product_add.php" class="btn btn-outline-success btn-sm">立即发布第一个商品</a >
                    </div>`);
                list.remove();
            }
        } else {
            showAlert(data.msg || '下架失败，请重试', 'danger');
        }
    })
    .catch(err => {
        showAlert('请求失败，请稍后重试', 'danger');
    });
}

// AJAX 无刷新上下架切换
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.toggle-btn');
    if (!btn) return;

    const productId = btn.dataset.id;

    const formData = new FormData();
    formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
    formData.append('action', 'toggle_status');
    formData.append('product_id', productId);
    formData.append('is_ajax', '1');

    btn.disabled = true;

    fetch('product_list.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (!res.ok) throw new Error('网络响应异常');
        return res.json();
    })
    .then(data => {
        btn.disabled = false;
        if (data.status === 1) {
            showAlert(data.msg);
            btn.dataset.status = data.new_status;
            btn.innerHTML = data.new_status == 1
                ? '<i class="bi bi-arrow-repeat"></i> 下架'
                : '<i class="bi bi-arrow-repeat"></i> 上架';

            const badge = document.querySelector(`#product-item-${productId} .status-badge`);
            if (badge) {
                badge.className = 'badge ms-1 status-badge ' + (data.new_status == 1 ? 'bg-success' : 'bg-secondary');
                badge.innerText = data.new_status == 1 ? '上架中' : '已下架';
            }
            refreshStats();
        } else {
            showAlert(data.msg || '操作失败，请重试', 'danger');
        }
    })
    .catch(err => {
        btn.disabled = false;
        showAlert('请求失败，请稍后重试', 'danger');
    });
});
</script>

<script src="assets/vendor/bootstrap5/bootstrap.bundle.min.js"></script>
</body>
</html>
