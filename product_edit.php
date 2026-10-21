<?php
require_once __DIR__ . '/lib/security.php';

require_once 'config/db.php';

require_once __DIR__ . '/lib/shop.php';

require_once __DIR__ . '/lib/risk_check.php';
if (($_SESSION['role'] ?? '') === 'admin') { header('Location: admin/login.php', true, 303); exit; }
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

// ==================== 2.5 商品相册管理（2026-10-21 查漏补缺）：上传/删除，独立于主保存流程 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gallery_action'] ?? '') !== '') {
    try {
        if (($_POST['gallery_action'] ?? '') === 'add' && isset($_FILES['gallery_image'])) {
            product_images_add((int)$product['id'], $_FILES['gallery_image']);
            redirect_to('product_edit.php?id=' . (int)$product['id'], '相册图片已上传');
        }
        if (($_POST['gallery_action'] ?? '') === 'del') {
            product_images_delete((int)$product['id'], (int)($_POST['gallery_image_id'] ?? 0));
            redirect_to('product_edit.php?id=' . (int)$product['id'], '相册图片已删除');
        }
    } catch (DomainException $e) {
        redirect_to('product_edit.php?id=' . (int)$product['id'], $e->getMessage());
    } catch (Throwable $e) {
        if (PHP_SAPI !== 'cli') error_log($e);
        redirect_to('product_edit.php?id=' . (int)$product['id'], '相册操作失败，请稍后再试');
    }
}
$galleryImages = product_images_list((int)$product['id']);

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

// ==================== 读取现有生效规格（多规格编辑区预填） ====================
$existing_skus = [];
try {
    if ($db instanceof PDO) {
        $stmt = $db->prepare("SELECT id, sku_name, price_cents, stock, image_url FROM product_skus WHERE product_id = ? AND status = 1 ORDER BY id");
        $stmt->execute([$product_id]);
        $existing_skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT id, sku_name, price_cents, stock, image_url FROM product_skus WHERE product_id = ? AND status = 1 ORDER BY id");
        if ($stmt) {
            $stmt->bind_param('i', $product_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing_skus = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
        }
    }
} catch (Exception $e) {}

// 当前进行中的拼团活动（商户商品；用于预填与手动结束）
$existing_group = (!$is_admin) ? group_buy_active($product_id) : null;

// 手动结束拼团活动：独立小表单提交，不经商品编辑主事务
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['end_group_buy'] ?? '') === '1') {
    if ($is_admin || !$existing_group) {
        echo "<script>alert('没有进行中的拼团活动'); location.href='product_edit.php?id={$product_id}';</script>";
        exit();
    }
    group_buy_end($product_id);
    echo "<script>alert('拼团活动已手动结束'); location.href='product_edit.php?id={$product_id}';</script>";
    exit();
}

// ==================== 4. 处理编辑提交（真正的 UPDATE） ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $brand = mb_substr(canonical_brand($_POST['brand'] ?? ''), 0, 50);
    $material = mb_substr(trim($_POST['material'] ?? ''), 0, 50);
    $category_id = intval($_POST['category_id'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);
    $stock = intval($_POST['stock'] ?? 0);
    $expected_stock_raw = (string)($_POST['expected_stock'] ?? '');
    $expected_stock = intval($expected_stock_raw);
    $detail = trim($_POST['detail'] ?? '');
    $status = (($_POST['submit_action'] ?? 'publish') === 'draft') ? 0 : 1;

    $category_id_val = ($category_id > 0) ? $category_id : null;

    // 解析规格行：整行留空则忽略，只要填写就必须完整（名称/价格/库存）
    $sku_rows = [];
    $sku_invalid = false;
    $sku_names = $_POST['sku_name'] ?? [];
    foreach ($sku_names as $i => $raw_name) {
        $name = trim((string)$raw_name);
        $price_raw = (string)($_POST['sku_price'][$i] ?? '');
        $stock_raw = (string)($_POST['sku_stock'][$i] ?? '');
        if ($name === '' && $price_raw === '' && $stock_raw === '') continue;
        if ($name === '' || mb_strlen($name) > 100 || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $price_raw) || (float)$price_raw <= 0 || !preg_match('/^\d+$/D', $stock_raw)) {
            $sku_invalid = true; break;
        }
        // 规格图：新上传优先；未上传且未勾选清除则沿用该行原有图片；勾选清除或上传失败存 NULL
        $sku_image = null;
        if (isset($_FILES['sku_image']) && ($_FILES['sku_image']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $sku_image = save_image_upload([
                    'error' => $_FILES['sku_image']['error'][$i],
                    'size' => $_FILES['sku_image']['size'][$i],
                    'tmp_name' => $_FILES['sku_image']['tmp_name'][$i],
                ]);
            } catch (DomainException $e) { $sku_image = null; }
        }
        if ($sku_image === null && empty($_POST['sku_clear_image'][$i])) {
            $sku_image = $existing_skus[$i]['image_url'] ?? null;
        }
        $parts = explode('.', $price_raw);
        $sku_rows[] = ['name' => $name, 'price_cents' => (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0'), 'stock' => (int)$stock_raw, 'image_url' => $sku_image];
    }

    // 拼团字段解析（仅商户、仅无规格商品）：全空表示取消既有活动，任一填写则校验完整
    $group_fields = [null, null, null, null];
    if (!$is_admin) {
        try {
            $group_fields = parse_group_buy_fields((int)round($price * 100), $_POST, empty($sku_rows));
        } catch (DomainException $e) {
            $msg = $e->getMessage(); $msg_type = 'error';
        }
    }

    // PHP7.3 mysqlnd 下 bind_param 混合 NULL 会破坏 'd' 类型参数，价格改用字符串绑定
    $price_str = number_format($price, 2, '.', '');

    // 限时特价与限购：解析并校验（特价<原价、结束>开始、三者须同时填写）
    $flash_cents = null; $flash_start = null; $flash_end = null; $limit_per_user = 0;
    try {
        list($flash_cents, $flash_start, $flash_end, $limit_per_user) = parse_flash_fields((int)round($price * 100), $_POST);
    } catch (DomainException $e) {
        $msg = $e->getMessage(); $msg_type = 'error';
    }

    // 预售字段解析与校验（定金>0 且 < 售价、截止晚于当前；未勾选保存即清空预售配置）
    $presale_on = 0; $presale_deposit_cents = null; $presale_end_at = null;
    try {
        list($presale_on, $presale_deposit_cents, $presale_end_at) = parse_presale_fields((int)round($price * 100), $_POST);
    } catch (DomainException $e) {
        $msg = $e->getMessage(); $msg_type = 'error';
    }

    // 风控预检（商户提交审核时）：生效主图取新上传（临时文件可直接读内容）或沿用旧图；block 拒绝保存，warn 保存后写预警备注
    $riskEffectiveImage = $product['image_url'] ?? '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) $riskEffectiveImage = $_FILES['image']['tmp_name'];
    $riskPrecheck = (!$is_admin && $status === 1)
        ? risk_check_product(['title' => $title, 'detail' => $detail, 'price' => $price, 'category_id' => $category_id_val, 'image_url' => $riskEffectiveImage, 'id' => $product_id])
        : null;

    if ($msg_type === 'error') {
        // 特价/限购字段校验失败：$msg 已有提示，直接回到表单
    } elseif ($sku_invalid) {
        $msg = '每个规格行须完整填写名称、大于零的价格（最多两位小数）与非负整数库存！';
        $msg_type = 'error';
    } elseif ($price <= 0 || $price > 99999999.99 || $stock < 0 || $stock > 100000000 || $expected_stock < 0 || $expected_stock > 100000000 || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)($_POST['price'] ?? '')) || !preg_match('/^\d+$/D', (string)($_POST['stock'] ?? '')) || !preg_match('/^\d+$/D', $expected_stock_raw)) {
        $msg = '价格须大于零且最多两位小数，库存须为非负整数！';
        $msg_type = 'error';
    } elseif (empty($title) || mb_strlen($title) > 100) {
        $msg = '请填写商品名称！';
        $msg_type = 'error';
    } elseif ($riskPrecheck !== null && $riskPrecheck['risk_level'] === 'block') {
        $msg = '商品未通过风控预检：' . implode('；', $riskPrecheck['reasons']);
        $msg_type = 'error';
    } else {
        $transactionStarted = false;
        try {
            $lock_sql = $is_admin
                ? "SELECT stock, image_url FROM products WHERE id = ? FOR UPDATE"
                : "SELECT stock, image_url FROM products WHERE id = ? AND shop_id = ? FOR UPDATE";
            // 防御初始化：连接对象既非 PDO 也非 mysqli 时走下方 product_not_found 异常而非未定义变量
            $locked_product = null;
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
                    ? "UPDATE products SET title = ?, category_id = ?, price = ?, stock = ?, detail = ?, image_url = ?, status = ?, audit_status = ?, flash_price_cents = ?, flash_start = ?, flash_end = ?, limit_per_user = ?, brand = ?, material = ?, presale = ?, deposit_cents = ?, presale_end_at = ? WHERE id = ?"
                    : "UPDATE products SET title = ?, category_id = ?, price = ?, stock = ?, detail = ?, image_url = ?, status = ?, audit_status = ?, flash_price_cents = ?, flash_start = ?, flash_end = ?, limit_per_user = ?, brand = ?, material = ?, presale = ?, deposit_cents = ?, presale_end_at = ? WHERE id = ? AND shop_id = ?";
                if ($db instanceof PDO) {
                    $params = [$title, $category_id_val, $price, $stock, $detail, $image_url, $status, $audit_status, $flash_cents, $flash_start, $flash_end, $limit_per_user, $brand, $material, $product_id];
                    if (!$is_admin) $params[] = $shop_id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } elseif ($db instanceof mysqli) {
                    $stmt = $db->prepare($sql);
                    if (!$stmt) throw new Exception('SQL 预处理失败');
                    if ($is_admin) {
                        $stmt->bind_param("sisissisississiisi", $title, $category_id_val, $price_str, $stock, $detail, $image_url, $status, $audit_status, $flash_cents, $flash_start, $flash_end, $limit_per_user, $brand, $material, $presale_on, $presale_deposit_cents, $presale_end_at, $product_id);
                    } else {
                        $stmt->bind_param("sisissisississiisii", $title, $category_id_val, $price_str, $stock, $detail, $image_url, $status, $audit_status, $flash_cents, $flash_start, $flash_end, $limit_per_user, $brand, $material, $presale_on, $presale_deposit_cents, $presale_end_at, $product_id, $shop_id);
                    }
                    $stmt->execute();
                    $stmt->close();
                }

                // 规格更新：旧规格整体停用（保留未决订单的回补目标与历史快照），提交的新规格全新写入；全部留空则回到默认规格
                if ($db instanceof PDO) {
                    $stmt = $db->prepare("UPDATE product_skus SET status = 0 WHERE product_id = ?");
                    $stmt->execute([$product_id]);
                    if ($sku_rows) {
                        $stmt = $db->prepare("INSERT INTO product_skus (product_id, sku_name, price_cents, stock, image_url) VALUES (?, ?, ?, ?, ?)");
                        foreach ($sku_rows as $row) $stmt->execute([$product_id, $row['name'], $row['price_cents'], $row['stock'], $row['image_url']]);
                    }
                } elseif ($db instanceof mysqli) {
                    $stmt = $db->prepare("UPDATE product_skus SET status = 0 WHERE product_id = ?");
                    if (!$stmt) throw new Exception('SQL 预处理失败');
                    $stmt->bind_param('i', $product_id);
                    $stmt->execute();
                    $stmt->close();
                    if ($sku_rows) {
                        $stmt = $db->prepare("INSERT INTO product_skus (product_id, sku_name, price_cents, stock, image_url) VALUES (?, ?, ?, ?, ?)");
                        if (!$stmt) throw new Exception('SQL 预处理失败');
                        $nm = ''; $pc = 0; $st = 0; $im = null;
                        $stmt->bind_param("isiis", $product_id, $nm, $pc, $st, $im);
                        foreach ($sku_rows as $row) { $nm = $row['name']; $pc = $row['price_cents']; $st = $row['stock']; $im = $row['image_url']; $stmt->execute(); }
                        $stmt->close();
                    }
                }

                // 收藏降价提醒：新价低于原价时对收藏用户各发一条站内通知（同一事务内一次写入，天然不重复）
                notify_price_drop($product, (int)round($price * 100));

                if ($db instanceof PDO) $db->commit(); else $db->commit();
                $transactionStarted = false;
                // 拼团活动联动：填写字段且上架 → 更新活动；全部清空且有既有活动 → 结束活动
                if (!$is_admin) {
                    if ($status === 1 && $group_fields[0] !== null) {
                        group_buy_save($product_id, $group_fields[0], $group_fields[1], $group_fields[2], $group_fields[3]);
                    } elseif ($group_fields[0] === null && $existing_group) {
                        group_buy_end($product_id);
                    }
                }
                // 风控预警：warn 级别正常保存，原因写入审核日志备注供审核中心展示
                if ($riskPrecheck !== null && $riskPrecheck['risk_level'] === 'warn') risk_log_warn($product_id, $riskPrecheck['reasons']);
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
    <link href="assets/vendor/bootstrap5/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
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

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small">品牌</label>
                    <input type="text" name="brand" maxlength="50" class="form-control" placeholder="选填，如 云感" value="<?= htmlspecialchars($product['brand'] ?? '') ?>" list="brand-options">
                    <datalist id="brand-options">
                    <?php foreach (rows("SELECT DISTINCT brand FROM products WHERE brand <> '' ORDER BY brand") as $b): ?>
                        <option value="<?php echo h($b['brand']); ?>">
                    <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small">材质</label>
                    <input type="text" name="material" maxlength="50" class="form-control" placeholder="选填，如 纯棉" value="<?= htmlspecialchars($product['material'] ?? '') ?>">
                </div>
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
                <label class="form-label fw-bold small">商品规格（多规格）</label>
                <small class="text-muted d-block mb-2">每个规格一行：名称 / 价格（元）/ 库存 / 规格图（可选）；全部留空保存则回到默认规格，填写任意一格即须整行完整。修改后旧规格会停用，历史订单快照不受影响。</small>
                <div id="sku-rows">
                    <?php $display_skus = $existing_skus ?: [['sku_name' => '', 'price_cents' => null, 'stock' => '', 'image_url' => null]]; ?>
                    <?php foreach ($display_skus as $i => $sku): ?>
                    <div class="row g-2 mb-2 sku-row">
                        <div class="col-3"><input type="text" name="sku_name[]" class="form-control" placeholder="规格名称，如 500ml 装" value="<?= htmlspecialchars($sku['sku_name'] ?? '') ?>"></div>
                        <div class="col-2"><input type="number" step="0.01" min="0.01" name="sku_price[]" class="form-control" placeholder="价格（元）" value="<?= $sku['price_cents'] !== null ? sprintf('%d.%02d', intdiv((int)$sku['price_cents'], 100), (int)$sku['price_cents'] % 100) : '' ?>"></div>
                        <div class="col-2"><input type="number" min="0" name="sku_stock[]" class="form-control" placeholder="库存" value="<?= htmlspecialchars((string)($sku['stock'] ?? '')) ?>"></div>
                        <div class="col-3">
                            <div class="sku-current mb-1"><?php if (!empty($sku['image_url'])): ?><img src="<?= htmlspecialchars($sku['image_url']) ?>" class="current-img" style="width:60px;height:60px;" alt="规格图"><?php else: ?><span class="text-muted small">未设置</span><?php endif; ?></div>
                            <input type="file" name="sku_image[]" accept="image/*" class="form-control form-control-sm" title="规格图（可选）">
                            <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="sku_clear_image[<?= (int)$i ?>]" value="1" id="sku-clear-<?= (int)$i ?>"><label class="form-check-label small" for="sku-clear-<?= (int)$i ?>">清除图片</label></div>
                        </div>
                        <div class="col-2"><button type="button" class="btn btn-outline-danger w-100 sku-remove">删除规格</button></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="sku-add" class="btn btn-outline-secondary btn-sm">+ 添加规格</button>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold small">限时特价（元）</label>
                    <input type="number" step="0.01" min="0.01" name="flash_price" class="form-control" placeholder="可空，须低于原价" value="<?= $product['flash_price_cents'] !== null ? sprintf('%d.%02d', intdiv((int)$product['flash_price_cents'], 100), (int)$product['flash_price_cents'] % 100) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">特价开始时间</label>
                    <input type="datetime-local" name="flash_start" class="form-control" value="<?= $product['flash_start'] ? str_replace(' ', 'T', substr($product['flash_start'], 0, 16)) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">特价结束时间</label>
                    <input type="datetime-local" name="flash_end" class="form-control" value="<?= $product['flash_end'] ? str_replace(' ', 'T', substr($product['flash_end'], 0, 16)) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">限购件数</label>
                    <input type="number" min="0" name="limit_per_user" value="<?= (int)($product['limit_per_user'] ?? 0) ?>" class="form-control" placeholder="0 表示不限购">
                </div>
            </div>
            <small class="text-muted d-block mb-3">特价与起止时间须同时填写；清空并保存即取消限时特价。</small>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold small">预售开关</label>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="presale" value="1" id="presale-on" <?= ((int)($product['presale'] ?? 0)===1) ? 'checked' : '' ?>><label class="form-check-label small" for="presale-on">开启预售（定金+尾款）</label></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">预售定金（元）</label>
                    <input type="number" step="0.01" min="0.01" name="deposit_price" class="form-control" placeholder="须低于售价" value="<?= ($product['deposit_cents'] ?? null) !== null ? sprintf('%d.%02d', intdiv((int)$product['deposit_cents'], 100), (int)$product['deposit_cents'] % 100) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">预售截止时间</label>
                    <input type="datetime-local" name="presale_end_at" class="form-control" value="<?= !empty($product['presale_end_at']) ? str_replace(' ', 'T', substr($product['presale_end_at'], 0, 16)) : '' ?>">
                </div>
            </div>
            <small class="text-muted d-block mb-3">开启预售时定金与截止时间必填：定金须大于零且低于售价，截止须晚于当前；取消勾选并保存即关闭预售。</small>

            <?php if (!$is_admin): ?>
            <div class="mb-3 p-3" style="background:#fff8f1;border-radius:8px;">
                <label class="form-label fw-bold small">拼团活动<?php if ($existing_group): ?>（当前进行中：团购价 ¥ <?php echo sprintf('%d.%02d', intdiv((int)$existing_group['price_cents'], 100), (int)$existing_group['price_cents'] % 100); ?> · <?php echo (int)$existing_group['size']; ?> 人成团）<?php endif; ?></label>
                <div class="row g-2">
                    <div class="col-md-3"><input type="number" step="0.01" min="0.01" name="group_price" class="form-control" placeholder="团购价（元），须低于原价" value="<?php echo $existing_group ? sprintf('%d.%02d', intdiv((int)$existing_group['price_cents'], 100), (int)$existing_group['price_cents'] % 100) : ''; ?>"></div>
                    <div class="col-md-3"><input type="number" min="2" max="10" name="group_size" class="form-control" placeholder="成团人数（2-10）" value="<?php echo $existing_group ? (int)$existing_group['size'] : ''; ?>"></div>
                    <div class="col-md-3"><input type="number" min="0" name="group_max_groups" value="<?php echo $existing_group ? (int)$existing_group['max_groups'] : 0; ?>" class="form-control" placeholder="总团数上限，0 不限"></div>
                    <div class="col-md-3"><input type="number" min="1" max="72" name="group_duration_hours" value="<?php echo $existing_group ? (int)$existing_group['duration_hours'] : 3; ?>" class="form-control" placeholder="成团时限（小时）"></div>
                </div>
                <small class="text-muted d-block mt-2">拼团仅支持无规格商品；清空团购价与成团人数并保存即取消拼团。修改字段保存会以新参数重开活动。</small>
            </div>
            <?php endif; ?>

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
                <label class="form-label fw-bold small">商品相册（最多 <?= PRODUCT_GALLERY_MAX ?> 张，详情页主图区轮播展示）</label>
                <?php if ($galleryImages): ?>
                <div class="mb-2 d-flex gap-2 flex-wrap">
                    <?php foreach ($galleryImages as $gi): ?>
                    <div style="position:relative;">
                        <img src="<?= htmlspecialchars($gi['image_url']) ?>" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6;" alt="相册图">
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="gallery_action" value="del"><input type="hidden" name="gallery_image_id" value="<?=(int)$gi['id'] ?>"><button class="btn-close btn-sm" style="position:absolute;top:-4px;right:-4px;background:#fff;border-radius:50%;padding:2px;" title="删除" onclick="return confirm('删除这张相册图？')"></button></form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center"><?= csrf_field() ?>
                    <input type="hidden" name="gallery_action" value="add">
                    <input type="file" name="gallery_image" accept="image/*" class="form-control" style="max-width:280px;" required>
                    <button class="btn btn-sm btn-outline-primary">上传相册图</button>
                </form>
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

        <?php if (!$is_admin && $existing_group): ?>
        <form action="product_edit.php?id=<?= $product_id ?>" method="POST" class="mb-3"><?php echo csrf_field(); ?><input type="hidden" name="end_group_buy" value="1"><button type="submit" class="btn btn-outline-danger btn-sm">立即结束拼团活动（当前进行中：<?php echo (int)$existing_group['size']; ?> 人成团）</button></form>
        <?php endif; ?>
    </div>
</div>

<script src="assets/vendor/bootstrap5/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var rows = document.getElementById('sku-rows');
    var nextIndex = <?= count($display_skus) ?>;
    document.getElementById('sku-add').addEventListener('click', function () {
        var clone = rows.querySelector('.sku-row').cloneNode(true);
        clone.querySelectorAll('input').forEach(function (input) {
            input.value = '';
            input.checked = false;
        });
        var checkbox = clone.querySelector('input[type=checkbox]');
        if (checkbox) {
            checkbox.name = 'sku_clear_image[' + nextIndex + ']';
            checkbox.id = 'sku-clear-' + nextIndex;
            var label = clone.querySelector('label[for]');
            if (label) label.setAttribute('for', 'sku-clear-' + nextIndex);
            nextIndex++;
        }
        var current = clone.querySelector('.sku-current');
        if (current) current.innerHTML = '<span class="text-muted small">未设置</span>';
        rows.appendChild(clone);
    });
    rows.addEventListener('click', function (e) {
        if (e.target.classList.contains('sku-remove')) {
            if (rows.querySelectorAll('.sku-row').length > 1) e.target.closest('.sku-row').remove();
        }
    });
})();
</script>
</body>
</html>
