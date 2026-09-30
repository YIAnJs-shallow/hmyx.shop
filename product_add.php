<?php
require_once __DIR__ . '/lib/security.php';

require_once 'config/db.php';
$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    die("<div style='text-align:center; padding:50px;'>未检测到数据库连接对象</div>");
}

if (!isset($_SESSION['user_id'])) {
    login_redirect(current_page_url());
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// ==================== 1. 身份分流：管理员 或 商户 才可发布商品 ====================
$is_admin = ($role === 'admin');
$shop = null;

if (!$is_admin) {
    // 非管理员则必须是商户（存在店铺记录）
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
    if (!$shop || $role !== 'merchant' || (int)$shop['status'] !== 1) {
        echo "<script>alert('请先申请成为入驻商户后再发布商品'); location.href='shop_manage.php';</script>";
        exit();
    }
}

// 管理员发布的商品 shop_id 为 NULL（平台自营）；商户发布的绑定本店
$shop_id = $shop ? intval($shop['id']) : null;

// ==================== 2. 查询分类（顶级 + 二级树状） ====================
$categories = [];
try {
    $cat_sql = "SELECT c1.id AS main_id, c1.category_name AS main_name,
                       c2.id AS sub_id, c2.category_name AS sub_name
                FROM categories c1
                LEFT JOIN categories c2 ON c2.parent_id = c1.id
                WHERE c1.parent_id = 0
                ORDER BY c1.sort_order ASC, c1.id ASC, c2.id ASC";
    if ($db instanceof PDO) {
        $rows = $db->query($cat_sql)->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($db instanceof mysqli) {
        $res = $db->query($cat_sql);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } else {
        $rows = [];
    }
    foreach ($rows as $row) {
        $m_id = $row['main_id'];
        if (!isset($categories[$m_id])) {
            $categories[$m_id] = ['name' => $row['main_name'], 'subs' => []];
        }
        if (!empty($row['sub_id'])) {
            $categories[$m_id]['subs'][] = ['id' => $row['sub_id'], 'name' => $row['sub_name']];
        }
    }
} catch (Exception $e) {}

$msg = '';
$msg_type = 'success';
$back_url = $is_admin ? 'admin.php' : 'product_list.php';

// ==================== 3. 处理表单提交 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);
    $stock = intval($_POST['stock'] ?? 0);
    $detail = trim($_POST['detail'] ?? '');
    $status = (($_POST['submit_action'] ?? 'publish') === 'draft') ? 0 : 1;
    $image_url = '';

    // 未选分类则置 NULL 以满足外键约束
    $category_id_val = ($category_id > 0) ? $category_id : null;

    // PHP7.3 mysqlnd 下 bind_param 混合 NULL 会破坏 'd' 类型参数，价格改用字符串绑定
    $price_str = number_format($price, 2, '.', '');

    // 处理图片上传
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        try { $image_url = save_image_upload($_FILES['image']); }
        catch (DomainException $e) { $msg = $e->getMessage(); $msg_type = 'error'; }
    }

    if ($msg_type !== 'error') {
        if ($price <= 0 || $price > 99999999.99 || $stock < 0 || $stock > 100000000 || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)($_POST['price'] ?? '')) || !preg_match('/^\d+$/D', (string)($_POST['stock'] ?? ''))) {
            $msg = '价格须大于零且最多两位小数，库存须为非负整数！';
            $msg_type = 'error';
        } elseif (empty($title) || mb_strlen($title) > 100) {
            $msg = '请填写商品名称！';
            $msg_type = 'error';
        } else {
            try {
                // 审核策略：管理员发布直接通过；商家发布需管理员审核
                $audit_status = $is_admin ? 'approved' : 'pending';
                if ($db instanceof PDO) {
                    $stmt = $db->prepare("INSERT INTO products (shop_id, title, category_id, price, stock, detail, image_url, status, audit_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$shop_id, $title, $category_id_val, $price, $stock, $detail, $image_url, $status, $audit_status]);
                    $ok = $stmt->rowCount() > 0;
                } elseif ($db instanceof mysqli) {
                    $stmt = $db->prepare("INSERT INTO products (shop_id, title, category_id, price, stock, detail, image_url, status, audit_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception('SQL 预处理失败');
                    $stmt->bind_param("isissisis", $shop_id, $title, $category_id_val, $price_str, $stock, $detail, $image_url, $status, $audit_status);
                    $exec_ok = $stmt->execute();
                    $ok = $exec_ok && $stmt->affected_rows > 0;
                    if (!$exec_ok) $mysqli_err = $stmt->error;
                }

                if (!empty($ok)) {
                    if ($status === 0) {
                        $status_tip = '商品已暂存为草稿！';
                    } elseif ($audit_status === 'pending') {
                        $status_tip = '商品提交成功，请等待管理员审核后自动上架！';
                    } else {
                        $status_tip = '商品发布成功！';
                    }
                    echo "<script>alert('{$status_tip}'); location.href='{$back_url}';</script>";
                    exit();
                } else {
                    $msg = '保存失败，请重试' . (isset($mysqli_err) ? '（' . $mysqli_err . '）' : '');
                    $msg_type = 'error';
                }
            } catch (Exception $e) {
                $msg = '保存失败：' . $e->getMessage();
                $msg_type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>发布新商品</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .main-container { max-width: 760px; margin: 20px auto 40px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="container main-container">
    <!-- 顶部导航 -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="<?= $back_url ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> 返回<?= $is_admin ? '商品管理' : '商品列表' ?>
        </a >
        <span class="text-muted small">
            <?= $is_admin ? '平台管理员 · 发布商品' : htmlspecialchars($shop['shop_name']) . ' · 发布新商品' ?>
        </span>
    </div>

    <div class="card p-4">
        <h5 class="fw-bold text-dark mb-4"><i class="bi bi-plus-circle text-success me-2"></i>发布新商品</h5>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <form action="product_add.php" method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label fw-bold small">商品名称 <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="请输入商品名称" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small">所属分类</label>
                <select name="category_id" class="form-select">
                    <option value="">-- 请选择商品所属分类（可不选）--</option>
                    <?php foreach ($categories as $main_cat): ?>
                        <optgroup label="【<?= htmlspecialchars($main_cat['name']) ?>】">
                            <?php foreach ($main_cat['subs'] as $sub): ?>
                                <option value="<?= $sub['id'] ?>">└─ <?= htmlspecialchars($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">未选择分类的商品将作为"未分类"商品保存</small>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small">商品价格（元）</label>
                    <input type="number" step="0.01" min="0.01" required name="price" value="0.00" class="form-control" placeholder="0.00">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small">库存数量</label>
                    <input type="number" min="0" name="stock" value="100" class="form-control">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small">商品主图</label>
                <input type="file" name="image" accept="image/*" class="form-control">
                <small class="text-muted">支持 JPG / PNG / GIF / WEBP，不上传则无主图</small>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small">商品详情描述</label>
                <textarea name="detail" rows="4" class="form-control" style="resize: vertical;" placeholder="请输入商品的详细介绍..."></textarea>
            </div>

            <div class="d-flex gap-3">
                <button type="submit" name="submit_action" value="publish" class="btn btn-primary flex-grow-1 py-2 fw-bold">
                    <i class="bi bi-rocket-takeoff"></i> 确认发布商品
                </button>
                <button type="submit" name="submit_action" value="draft" class="btn btn-warning py-2 fw-bold" style="min-width: 140px;">
                    <i class="bi bi-save"></i> 保存为草稿
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
