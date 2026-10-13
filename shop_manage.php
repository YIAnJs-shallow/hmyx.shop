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
$message = '';
$error = '';

// ==================== 1. 查询当前用户信息及店铺信息 ====================
$user = null;
$shop = null;

try {
    // 查询用户基本信息（包含 role 字段，假设 0/普通用户，1/商户）
    if ($db instanceof PDO) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 查询是否存在关联店铺记录（假设有 shops 表）
        $stmt = $db->prepare("SELECT * FROM shops WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
        }

        $stmt = $db->prepare("SELECT * FROM shops WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $shop = $res ? $res->fetch_assoc() : null;
        }
    }
} catch (Exception $e) {
    // 若不存在 shops 表可容错处理
}

// 判断当前是否已经是商户（角色为 merchant/1，或者已存在店铺记录）
$is_merchant = ($user && isset($user['role']) && ($user['role'] === 'merchant' || $user['role'] == 1)) || !empty($shop);

// ==================== 2. 处理提交注册商户申请 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_shop') {
    $shop_name = trim($_POST['shop_name'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $shop_desc = trim($_POST['shop_desc'] ?? '');

    if ($shop || ($_SESSION['role'] ?? '') === 'admin') {
        $error = '已有店铺或管理员账号不能重复申请';
    } elseif (empty($shop_name) || empty($contact_phone) || mb_strlen($shop_name)>100 || strlen($contact_phone)>20) {
        $error = '请填写完整的店铺名称和联系电话！';
    } else {
        try {
            if ($db instanceof PDO) {
                $db->beginTransaction();

                $stmt = $db->prepare("INSERT INTO shops (user_id, shop_name, contact_phone, category, shop_desc, status) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$user_id, $shop_name, $contact_phone, $category, $shop_desc]);

                $stmt = $db->prepare("UPDATE users SET role = 'merchant' WHERE id = ?");
                $stmt->execute([$user_id]);

                $db->commit();
            } elseif ($db instanceof mysqli) {
                $db->begin_transaction();
                $lock = $db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lock->bind_param('i', $user_id); $lock->execute();
                $lock->store_result(); // 取走加锁查询结果集，否则下一条查询报 Commands out of sync
                $check_shop = $db->prepare('SELECT id FROM shops WHERE user_id=?');
                $check_shop->bind_param('i', $user_id); $check_shop->execute();
                if ($check_shop->get_result()->num_rows) throw new Exception('您已经拥有店铺');

                $stmt = $db->prepare("INSERT INTO shops (user_id, shop_name, contact_phone, category, shop_desc, status) VALUES (?, ?, ?, ?, ?, 1)");
                if (!$stmt) throw new Exception('SQL 预处理失败');
                $stmt->bind_param("issss", $user_id, $shop_name, $contact_phone, $category, $shop_desc);
                $stmt->execute();

                $stmt = $db->prepare("UPDATE users SET role = 'merchant' WHERE id = ?");
                if (!$stmt) throw new Exception('SQL 预处理失败');
                $stmt->bind_param("i", $user_id);
                $stmt->execute();

                $db->commit();
            }

            // 更新当前 Session 标识并刷新状态
            $_SESSION['role'] = 'merchant';
            $message = '恭喜！商户账号注册成功，已升级为商户。';
            $is_merchant = true;

            // 重新获取店铺数据
            $shop = [
                'id' => 0,
                'shop_name' => $shop_name,
                'contact_phone' => $contact_phone,
                'category' => $category,
                'shop_desc' => $shop_desc,
                'status' => 1
            ];
        } catch (Exception $e) {
            // 任一步失败则回滚，避免出现店铺记录与角色不一致
            if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
            elseif ($db instanceof mysqli) $db->rollback();
            $error = '注册失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商户店铺管理</title>
    <link href="assets/vendor/bootstrap5/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .main-container { max-width: 800px; margin: 30px auto; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="container main-container">
    <!-- 顶部导航导航栏 -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="user_center.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> 返回个人中心
        </a >
        <span class="text-muted small">商户管理后台</span>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (!$is_merchant): ?>
        <!-- 场景 A：普通用户，显示“注册商户”表单 -->
        <div class="card p-4">
            <div class="text-center mb-4">
                <div class="display-6 text-primary mb-2"><i class="bi bi-shop"></i></div>
                <h4 class="fw-bold">申请成为入驻商户</h4>
                <p class="text-muted small">填写店铺基本信息，即可快速完成商户账号注册并管理商品</p >
            </div>

            <form action="shop_manage.php" method="POST"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="register_shop">

                <div class="mb-3">
                    <label class="form-label fw-bold small">店铺名称 <span class="text-danger">*</span></label>
                    <input type="text" name="shop_name" class="form-control" placeholder="例如：长沙特色美食小店" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small">联系电话 <span class="text-danger">*</span></label>
                    <input type="text" name="contact_phone" class="form-control" placeholder="请输入商户联系手机号" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small">主营品类</label>
                    <select name="category" class="form-select">
                        <option value="餐饮美食">餐饮美食</option>
                        <option value="数码家电">数码家电</option>
                        <option value="服饰鞋帽">服饰鞋帽</option>
                        <option value="日用百货">日用百货</option>
                        <option value="其他分类">其他分类</option>
                    </select>
                </div>

                <div class="mb-3">
    <label class="form-label fw-bold small">店铺简介 / 营业说明</label>
    <textarea name="shop_desc" class="form-control" style="min-height: 100px; resize: vertical;" placeholder="简单描述您的店铺主营特色..."></textarea>
</div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                    <i class="bi bi-check-circle-fill me-1"></i> 立即提交并注册商户
                </button>
            </form>
        </div>

    <?php else: ?>
        <!-- 场景 B：已是商户，显示商户面板 -->
        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-shop text-primary me-2"></i>我的店铺控制台</h5>
                <span class="badge bg-success"><?= ($shop['status'] ?? 1) == 1 ? '正常营业中' : '休息中' ?></span>
            </div>
            <hr>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-muted small">店铺名称</div>
                    <div class="fw-bold fs-5"><?= htmlspecialchars($shop['shop_name'] ?? '我的店铺') ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">联系电话</div>
                    <div class="fw-bold"><?= htmlspecialchars($shop['contact_phone'] ?? '未设置') ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">主营品类</div>
                    <div><?= htmlspecialchars($shop['category'] ?? '通用分类') ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">店铺简介</div>
                    <div class="text-secondary small"><?= htmlspecialchars($shop['shop_desc'] ?? '暂无简介') ?></div>
                </div>
            </div>
        </div>

        <!-- 功能快捷操作入口 -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card p-3 text-center">
                    <i class="bi bi-box-seam fs-1 text-primary mb-2"></i>
                    <h6 class="fw-bold">商品列表管理</h6>
                    <p class="text-muted small mb-3">查看、编辑或下架店铺已发布的商品</p >
                    <a href="product_list.php" class="btn btn-outline-primary btn-sm">前往商品列表</a >
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3 text-center">
                    <i class="bi bi-plus-circle fs-1 text-success mb-2"></i>
                    <h6 class="fw-bold">发布新商品</h6>
                    <p class="text-muted small mb-3">快速上架新的商品到商城销售</p >
                    <a href="product_add.php" class="btn btn-outline-success btn-sm">添加新商品</a >
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="assets/vendor/bootstrap5/bootstrap.bundle.min.js"></script>
</body>
</html>