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
$is_admin = ($role === 'admin');
$back_url = $is_admin ? 'admin.php' : 'product_list.php';

// ==================== 1. 身份与归属校验 ====================
$shop = null;
if (!$is_admin) {
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
        echo "<script>alert('请先申请成为入驻商户'); location.href='shop_manage.php';</script>";
        exit();
    }
}
$shop_id = $shop ? intval($shop['id']) : null;

// ==================== 2. 读取待编辑商品（含归属校验） ====================
$product_id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$product = null;

if ($product_id > 0) {
    try {
        // 管理员可编辑任意商品；商户只能编辑本店商品
        $sql = $is_admin
            ? "SELECT * FROM products WHERE id = ? LIMIT 1"
            : "SELECT * FROM products WHERE id = ? AND shop_id = ? LIMIT 1";

        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($is_admin ? [$product_id] : [$product_id, $shop_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if ($stmt) {
                if ($is_admin) {
                    $stmt->bind_param("i", $product_id);
                } else {
                    $stmt->bind_param("ii", $product_id, $shop_id);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $product = $res ? $res->fetch_assoc() : null;
            }
        }
    } catch (Exception $e) {}
}

if (!$product) {
    echo "<script>alert('商品不存在或您无权编辑该商品'); location.href='{$back_url}';</script>";
    exit();
}

// ==================== 3. 查询分类（树状） ====================
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

// ==================== 4. 处理编辑提交（真正的 UPDATE） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);
    $stock = intval($_POST['stock'] ?? 0);
    $expected_stock_raw = (string)($_POST['expected_stock'] ?? '');
    $expected_stock = intval($expected_stock_raw);
    $detail = trim($_POST['detail'] ?? '');
    $status = (($_POST['submit_action'] ?? 'publish') === 'draft') ? 0 : 1;

    $category_id_val = ($category_id > 0) ? $category_id : null;

    // PHP7.3 mysqlnd 下 bind_param 混合 NULL 会破坏 'd' 类型参数，价格改用字符串绑定
    $price_str = number_format($price, 2, '.', '');

    if ($price <= 0 || $price > 99999999.99 || $stock < 0 || $stock > 100000000 || $expected_stock < 0 || $expected_stock > 100000000 || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)($_POST['price'] ?? '')) || !preg_match('/^\d+$/D', (string)($_POST['stock'] ?? '')) || !preg_match('/^\d+$/D', $expected_stock_raw)) {
        $msg = '价格须大于零且最多两位小数，库存须为非负整数！';
        $msg_type = 'error';
    } elseif (empty($title) || mb_strlen($title) > 100) {
        $msg = '请填写商品名称！';
        $msg_type = 'error';
    } else {
        $transactionStarted = false;
        try {
            $lock_sql = $is_admin
                ? "SELECT stock, image_url FROM products WHERE id = ? FOR UPDATE"
                : "SELECT stock, image_url FROM products WHERE id = ? AND shop_id = ? FOR UPDATE";
            if ($db instanceof PDO) {
                $db->beginTransaction();
                $transactionStarted = true;
                $stmt = $db->prepare($lock_sql);
                $stmt->execute($is_admin ? [$product_id] : [$product_id, $shop_id]);
                $locked_product = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($db instanceof mysqli) {
                $db->begin_transaction();
                $transactionStarted = true;
                $stmt = $db->prepare($lock_sql);
                if (!$stmt) throw new Exception('SQL 预处理失败');
                if ($is_admin) {
                    $stmt->bind_param('i', $product_id);
                } else {
                    $stmt->bind_param('ii', $product_id, $shop_id);
                }
                $stmt->execute();
                $locked_product = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            if (!$locked_product) {
                throw new RuntimeException('product_not_found');
            }
            if ((int)$locked_product['stock'] !== $expected_stock) {
                if ($db instanceof PDO) $db->rollBack(); else $db->rollback();
                $transactionStarted = false;
                $msg = '库存已被订单操作更新，请刷新页面后重新确认并保存。';
                $msg_type = 'error';
            } else {
                $image_url = $locked_product['image_url'] ?? '';
                if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $image_url = save_image_upload($_FILES['image']);
                }

                // 审核策略：管理员编辑无需重审；商家编辑后回到待审核状态
                $audit_status = $is_admin ? 'approved' : 'pending';
                $sql = $is_admin
                    ? "UPDATE products SET title = ?, category_id = ?, price = ?, stock = ?, detail = ?, image_url = ?, status = ?, audit_status = ? WHERE id = ?"
                    : "UPDATE products SET title = ?, category_id = ?, price = ?, stock = ?, detail = ?, image_url = ?, status = ?, audit_status = ? WHERE id = ? AND shop_id = ?";
                if ($db instanceof PDO) {
                    $params = [$title, $category_id_val, $price, $stock, $detail, $image_url, $status, $audit_status, $product_id];
                    if (!$is_admin) $params[] = $shop_id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } elseif ($db instanceof mysqli) {
                    $stmt = $db->prepare($sql);
                    if (!$stmt) throw new Exception('SQL 预处理失败');
                    if ($is_admin) {
                        $stmt->bind_param("sisissisi", $title, $category_id_val, $price_str, $stock, $detail, $image_url, $status, $audit_status, $product_id);
                    } else {
                        $stmt->bind_param("sisissisii", $title, $category_id_val, $price_str, $stock, $detail, $image_url, $status, $audit_status, $product_id, $shop_id);
                    }
                    $stmt->execute();
                    $stmt->close();
                }

                if ($db instanceof PDO) $db->commit(); else $db->commit();
                $transactionStarted = false;
                $success_tip = $is_admin ? '商品修改成功！' : '商品修改成功，已重新提交管理员审核！';
                echo "<script>alert('{$success_tip}'); location.href='{$back_url}';</script>";
                exit();
            }
        } catch (Exception $e) {
            if ($transactionStarted) {
                if ($db instanceof PDO) $db->rollBack(); else $db->rollback();
            }
            error_log('product_edit_failed: ' . $e->getMessage());
            $msg = '修改失败，请稍后重试。';
            $msg_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑商品</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .main-container { max-width: 760px; margin: 20px auto 40px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .current-img { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid #dee2e6; }
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
            <?= $is_admin ? '平台管理员 · 编辑商品' : htmlspecialchars($shop['shop_name']) . ' · 编辑商品' ?>
        </span>
    </div>

    <div class="card p-4">
        <h5 class="fw-bold text-dark mb-4"><i class="bi bi-pencil-square text-primary me-2"></i>编辑商品：<?= htmlspecialchars($product['title']) ?></h5>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <form action="product_edit.php?id=<?= $product['id'] ?>" method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?= $product['id'] ?>">
            <input type="hidden" name="expected_stock" value="<?= intval($product['stock']) ?>">

            <div class="mb-3">
                <label class="form-label fw-bold small">商品名称 <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($product['title']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small">所属分类</label>
                <select name="category_id" class="form-select">
                    <option value="">-- 请选择商品所属分类（可不选）--</option>
                    <?php foreach ($categories as $main_cat): ?>
                        <optgroup label="【<?= htmlspecialchars($main_cat['name']) ?>】">
                            <?php foreach ($main_cat['subs'] as $sub): ?>
                                <option value="<?= $sub['id'] ?>" <?= ($product['category_id'] == $sub['id']) ? 'selected' : '' ?>>
                                    └─ <?= htmlspecialchars($sub['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small">商品价格（元）</label>
                    <input type="number" step="0.01" min="0.01" required name="price" class="form-control" value="<?= htmlspecialchars($product['price']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small">当前可售库存</label>
                    <input type="number" min="0" name="stock" class="form-control" value="<?= intval($product['stock']) ?>">
                    <small class="text-muted">不含已下单但尚未释放的数量；如库存已变动，请刷新后重新确认。</small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small">商品主图</label>
                <?php if (!empty($product['image_url']) && file_exists($product['image_url'])): ?>
                    <div class="mb-2">
                        <img src="<?= htmlspecialchars($product['image_url']) ?>" class="current-img" alt="当前主图">
                        <div class="text-muted small mt-1">当前主图，上传新图将自动替换</div>
                    </div>
                <?php endif; ?>
                <input type="file" name="image" accept="image/*" class="form-control">
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small">商品详情描述</label>
                <textarea name="detail" rows="4" class="form-control" style="resize: vertical;" placeholder="请输入商品的详细介绍..."><?= htmlspecialchars($product['detail'] ?? '') ?></textarea>
            </div>

            <div class="d-flex gap-3">
                <button type="submit" name="submit_action" value="publish" class="btn btn-primary flex-grow-1 py-2 fw-bold">
                    <i class="bi bi-check-circle"></i> 保存并上架
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
