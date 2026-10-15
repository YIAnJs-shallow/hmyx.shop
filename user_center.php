<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/shop.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    die("数据库连接失败，请检查 config/db.php");
}

// 检查登录状态
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'guest';

$user = [
    'username' => $_SESSION['username'] ?? '',
    'phone' => '',
    'email' => '',
    'avatar' => ''
];

$msg = '';
$msg_type = 'success';

// 动态检测数据库表中存在哪些字段（function_exists 守卫：允许本页被测试在函数作用域内重复 include）
if (!function_exists('getTableColumns')) {
function getTableColumns($db) {
    $columns = [];
    if ($db instanceof mysqli) {
        $res = $db->query("SHOW COLUMNS FROM users");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
        }
    } else {
        $stmt = $db->query("SHOW COLUMNS FROM users");
        if ($stmt) {
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                $columns[] = $c['Field'];
            }
        }
    }
    return $columns;
}
}

$user_cols = $is_logged_in ? getTableColumns($db) : [];
$has_phone = in_array('phone', $user_cols);
$has_email = in_array('email', $user_cols);
$has_avatar = in_array('avatar', $user_cols);

// 已登录状态下动态查询用户资料
if ($is_logged_in) {
    $select_fields = ['username', 'password'];
    if ($has_phone) $select_fields[] = 'phone';
    if ($has_email) $select_fields[] = 'email';
    if ($has_avatar) $select_fields[] = 'avatar';

    $sql = "SELECT " . implode(', ', $select_fields) . " FROM users WHERE id = ?";

    if ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res) $user = array_merge($user, $res);
        }
    } else {
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->execute([$user_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) $user = array_merge($user, $res);
        }
    }
}

// 处理资料修改提交
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['username'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $old_pwd = trim($_POST['old_password'] ?? '');
    $new_pwd = trim($_POST['new_password'] ?? '');

    $avatar_path = $user['avatar'];

    // 处理头像上传
    if ($has_avatar && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        try { $avatar_path = save_image_upload($_FILES['avatar'], 'avatars'); }
        catch (DomainException $e) { $msg=$e->getMessage(); $msg_type='error'; }
    }

    // 校验新旧密码
    $final_password = null;
    if ($msg_type !== 'error' && !empty($new_pwd)) {
        if (empty($old_pwd)) {
            $msg = '修改密码时必须输入原密码！';
            $msg_type = 'error';
        } else {
            $db_pwd = $user['password'] ?? '';
            $is_pwd_correct = verify_account_password($old_pwd, $db_pwd);
            if ($is_pwd_correct && strlen($new_pwd) >= 8 && strlen($new_pwd) <= 72) {
                $final_password = password_hash($new_pwd, PASSWORD_DEFAULT);
            } else {
                $msg = '原密码输入不正确！';
                $msg_type = 'error';
            }
        }
    }

    // 动态组装 UPDATE 语句
    if ($msg_type !== 'error') {
        $update_fields = ["username = ?"];
        $bind_params = [$new_name];

        if ($has_phone) { $update_fields[] = "phone = ?"; $bind_params[] = $new_phone; }
        if ($has_email) { $update_fields[] = "email = ?"; $bind_params[] = $new_email; }
        if ($has_avatar) { $update_fields[] = "avatar = ?"; $bind_params[] = $avatar_path; }
        if ($final_password) { $update_fields[] = "password = ?"; $bind_params[] = $final_password; }

        $bind_params[] = $user_id;
        $sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $types = str_repeat('s', count($bind_params) - 1) . 'i';
                $stmt->bind_param($types, ...$bind_params);
                $success = $stmt->execute();
            } else {
                $success = false;
            }
        } else {
            $stmt = $db->prepare($sql);
            $success = $stmt ? $stmt->execute($bind_params) : false;
        }

        if ($success) {
            $_SESSION['username'] = $new_name;
            $user['username'] = $new_name;
            $user['phone'] = $new_phone;
            $user['email'] = $new_email;
            $user['avatar'] = $avatar_path;
            $msg = '资料更新成功！';
            $msg_type = 'success';
        } else {
            $msg = '更新失败，请稍后再试！';
            $msg_type = 'error';
        }
    }
}

// 供 HTML 模板使用的全局 username 变量
$username = !empty($user['username']) ? $user['username'] : ($_SESSION['username'] ?? '用户');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人中心 - 惠蒙优选</title>
    <style>
        body { background-color: #f8fafc; margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #334155; }
        header { background: #fff; padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; }
        .logo a { font-size: 1.4rem; font-weight: bold; color: #2563eb; text-decoration: none; }
        nav ul { list-style: none; margin: 0; padding: 0; display: flex; gap: 20px; }
        .btn-home { text-decoration: none; color: #475569; font-weight: 600; padding: 6px 14px; border: 1px solid #cbd5e1; border-radius: 6px; transition: all 0.2s; }
        .btn-home:hover { background: #f1f5f9; color: #2563eb; border-color: #2563eb; }

        .uc-container { max-width: 850px; margin: 30px auto; padding: 0 15px; }
        .user-card { background: #fff; padding: 25px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
        .user-info-left { display: flex; align-items: center; gap: 20px; }
        .avatar-box { width: 75px; height: 75px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; font-size: 2rem; border: 2px solid #2563eb; color: #2563eb; overflow: hidden; }
        .avatar-box img { width: 100%; height: 100%; object-fit: cover; }
        .user-meta h3 { margin: 0; font-size: 1.3rem; color: #1e293b; }
        .role-badge { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; background: #eff6ff; color: #2563eb; font-weight: 600; }
        
        .btn-login { padding: 9px 24px; background: #2563eb; color: #fff; border-radius: 20px; text-decoration: none; font-weight: bold; }
        .btn-logout { padding: 6px 14px; background: #ef4444; color: #fff; border-radius: 6px; text-decoration: none; font-size: 0.85rem; }
        
        .order-section { background: #fff; margin-top: 20px; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
        .section-title { font-weight: bold; font-size: 1.05rem; margin-bottom: 15px; color: #0f172a; border-left: 4px solid #2563eb; padding-left: 10px; }
        .order-grid { display: grid; grid-template-columns: repeat(4, 1fr); text-align: center; gap: 10px; }
        .order-item { padding: 15px 10px; border-radius: 8px; background: #f8fafc; text-decoration: none; color: #475569; font-size: 0.9rem; }
        .order-item:hover { background: #f1f5f9; color: #2563eb; }
        .order-icon { font-size: 1.6rem; margin-bottom: 6px; }

        /* 商家管理按钮美化 */
        .merchant-section { background: #0f172a; color: #fff; margin-top: 20px; padding: 15px 20px; border-radius: 12px; }
        .merchant-btn { display: block; padding: 14px; text-align: center; background: #1e293b; color: #38bdf8; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 1.05rem; transition: background 0.2s; }
        .merchant-btn:hover { background: #334155; color: #38bdf8; }

        .profile-edit { background: #fff; margin-top: 20px; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .form-row { margin-bottom: 5px; }
        .form-row.full { grid-column: span 2; }
        .form-row label { display: block; margin-bottom: 6px; color: #475569; font-size: 0.88rem; font-weight: 600; }
        .form-row input[type="text"], .form-row input[type="password"], .form-row input[type="email"], .form-row input[type="tel"] { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; outline: none; }
        .form-row input:focus { border-color: #2563eb; }
        
        .avatar-upload-box { display: flex; align-items: center; gap: 15px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px dashed #cbd5e1; }
        .avatar-preview-small { width: 50px; height: 50px; border-radius: 50%; background: #e2e8f0; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .avatar-preview-small img { width: 100%; height: 100%; object-fit: cover; }

        .btn-save { padding: 11px 25px; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.95rem; margin-top: 10px; }
        .btn-save:hover { background: #1d4ed8; }

        .alert-msg { padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9rem; font-weight: 500; }
        .alert-msg.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-msg.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        /* 底部统一操作区域外层容器 */
.shop-manage-box {
    margin-top: 20px;
    background: #182232; /* 截图中的深蓝色/黑蓝色背景 */
    border-radius: 12px;
    padding: 12px 16px;
    display: flex;
    flex-wrap: wrap; /* 适配手机/小屏自适应换行 */
    gap: 12px;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* 统一按钮样式（保持与原商铺管理一致） */
.manage-btn {
    flex: 1;
    min-width: 140px; /* 保证按钮最小宽度 */
    background: #233147; /* 稍亮一点的卡片深色背景，增强按钮质感 */
    color: #38bdf8; /* 科技蓝文字颜色 */
    text-decoration: none;
    text-align: center;
    padding: 12px 16px;
    font-size: 15px;
    font-weight: bold;
    border-radius: 8px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-sizing: border-box;
}

/* 悬停鼠标时的反馈效果 */
.manage-btn:hover {
    background: #0071e3;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0, 113, 227, 0.3);
}
    </style>
</head>
<body>
    <div class="logo">
    <a href="index.php" style="display: flex; align-items: center; gap: 10px; text-decoration: none;">
        <svg viewBox="0 0 100 100" width="36" height="36">
            <defs>
                <linearGradient id="logoBlue" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#3b82f6" />
                    <stop offset="100%" stop-color="#1d4ed8" />
                </linearGradient>
            </defs>
            <rect x="15" y="25" width="70" height="65" rx="14" fill="url(#logoBlue)" />
            <path d="M 35 25 A 15 15 0 0 1 65 25" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
            <path d="M 35 45 L 35 70 M 65 45 L 65 70 M 35 57 L 65 57" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
            <path d="M 75 18 L 77 25 L 84 27 L 77 29 L 75 36 L 73 29 L 66 27 L 73 25 Z" fill="#f59e0b"/>
        </svg>
        <div style="display: flex; flex-direction: column; line-height: 1.1;">
            <span style="font-size: 1.3rem; font-weight: 800; color: #1e293b; letter-spacing: 0.5px;">惠蒙优选</span>
            <span style="font-size: 0.55rem; font-weight: 700; color: #2563eb; letter-spacing: 1.2px;">HUIMENG CHOICE</span>
        </div>
    </a >
</div>

    <div class="uc-container">
        <?php if (!empty($msg)): ?>
            <div class="alert-msg <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <!-- 1. 顶部个人信息卡片（已修复返回首页链接层级与防遮挡样式） -->
<div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; position: relative;">
    
    <!-- 左上角返回首页按钮：增加 z-index: 10 确保不会被透明遮罩挡住 -->
    <a href="index.php" style="position: absolute; top: 18px; left: 20px; z-index: 10; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: #475569; font-size: 0.9rem; font-weight: 600; background: #f1f5f9; padding: 6px 14px; border-radius: 20px; border: 1px solid #cbd5e1;">
        🏠 返回首页
    </a >

    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 35px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; border: 2px solid #2563eb;">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <span style="font-size: 1.8rem;">👤</span>
                <?php endif; ?>
            </div>
            <div>
                <h3 style="margin: 0 0 5px 0; color: #1e293b; font-size: 1.2rem;"><?php echo htmlspecialchars($username); ?></h3>
                <span style="background: #eff6ff; color: #2563eb; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold;">
                    <?php echo ($role === 'admin') ? '🏪 商家账号' : '👤 普通买家'; ?>
                </span>
                <?php if ($is_logged_in): $memberLevel = one('SELECT l.name, l.discount_percent, u.total_spent_cents FROM users u JOIN user_levels l ON l.id = u.level_id WHERE u.id = ?', [(int)$user_id]); ?>
                <?php if ($memberLevel): ?>
                <span class="role-badge" style="background:#fef9c3;color:#a16207;">
                    会员等级：<?php echo htmlspecialchars($memberLevel['name']); ?><?php echo ((int)$memberLevel['discount_percent'] < 100) ? '（' . (100 - (int)$memberLevel['discount_percent']) / 10 . ' 折）' : ''; ?>
                </span>
                <p style="margin: 6px 0 0 0; font-size: 0.82rem; color: #64748b;">
                    累计实付 <strong style="color:#1e293b;">¥ <?php echo number_format(((int)$memberLevel['total_spent_cents']) / 100, 2); ?></strong>
                    <?php $nextLevel = one('SELECT name, min_spent_cents FROM user_levels WHERE min_spent_cents > ? ORDER BY min_spent_cents LIMIT 1', [(int)$memberLevel['total_spent_cents']]); ?>
                    <?php if ($nextLevel): ?>
                    · 距<?php echo htmlspecialchars($nextLevel['name']); ?>还差 <strong style="color:#dc2626;">¥ <?php echo number_format((((int)$nextLevel['min_spent_cents']) - ((int)$memberLevel['total_spent_cents'])) / 100, 2); ?></strong>
                    <?php else: ?>
                    · 已达最高等级
                    <?php endif; ?>
                </p>
                <p style="margin: 2px 0 0 0; font-size: 0.75rem; color: #94a3b8;">等级规则：按累计实付自动升级（银卡 ≥ ¥500、金卡 ≥ ¥2000），确认收货后计入，退款不扣减。</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <a href="logout.php" style="background: #ef4444; color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: bold;">
            退出登录
        </a >
    </div>
</div>

<!-- 2. 我的订单区域（跳转时附带分类参数 status） -->
<div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h4 style="margin: 0; color: #1e293b; font-size: 1rem; border-left: 4px solid #2563eb; padding-left: 8px;">我的订单</h4>
        <a href="my_orders.php" style="color: #64748b; text-decoration: none; font-size: 0.85rem;">查看全部订单 ›</a >
    </div>

    <!-- 4 个状态分类按钮 -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; text-align: center;">
        
        <!-- 待付款 -->
        <a href="my_orders.php?status=pending" style="text-decoration: none; color: #334155; background: #f8fafc; padding: 18px 10px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <div style="font-size: 1.8rem;">💳</div>
            <span style="font-size: 0.85rem; font-weight: bold;">待付款</span>
        </a >

        <!-- 待发货 -->
        <a href="my_orders.php?status=unshipped" style="text-decoration: none; color: #334155; background: #f8fafc; padding: 18px 10px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <div style="font-size: 1.8rem;">📦</div>
            <span style="font-size: 0.85rem; font-weight: bold;">待发货</span>
        </a >

        <!-- 待收货 -->
        <a href="my_orders.php?status=shipped" style="text-decoration: none; color: #334155; background: #f8fafc; padding: 18px 10px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <div style="font-size: 1.8rem;">🚚</div>
            <span style="font-size: 0.85rem; font-weight: bold;">待收货</span>
        </a >

        <!-- 已完成 -->
        <a href="my_orders.php?status=completed" style="text-decoration: none; color: #334155; background: #f8fafc; padding: 18px 10px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <div style="font-size: 1.8rem;">✅</div>
            <span style="font-size: 0.85rem; font-weight: bold;">已完成</span>
        </a >

    </div>
</div>

        <!-- 我的积分入口：余额 + 签到/明细链接（与 points.php 互链） -->
        <?php if ($is_logged_in): $pointsBalance = (int)one('SELECT points FROM users WHERE id=?', [(int)$user_id])['points']; ?>
        <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h4 style="margin: 0 0 5px 0; color: #1e293b; font-size: 1rem; border-left: 4px solid #f59e0b; padding-left: 8px;">我的积分</h4>
                <p style="margin: 0; color: #475569; font-size: 0.9rem;">当前积分余额：<strong style="color: #c75022; font-size: 1.2rem;"><?php echo (int)$pointsBalance; ?></strong> 分 · 每日签到 +5，连续满 7 天额外 +20</p>
            </div>
            <a href="points.php" style="background: #f59e0b; color: #fff; padding: 9px 20px; border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9rem;">📅 去签到 / 查看明细</a>
        </div>
        <?php endif; ?>

        <!-- 3. 浏览足迹：最近浏览的商品（同一商品只显示最新一次浏览） -->
        <?php $browsing = $is_logged_in ? browsing_history($user_id) : []; ?>
        <?php if ($browsing): ?>
        <div style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h4 style="margin: 0; color: #1e293b; font-size: 1rem; border-left: 4px solid #2563eb; padding-left: 8px;">浏览足迹</h4>
                <span style="color: #64748b; font-size: 0.85rem;">最近浏览的 <?= count($browsing) ?> 件商品</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 15px;">
                <?php foreach ($browsing as $b): ?>
                <a href="detail.php?id=<?= (int)$b['product_id'] ?>" style="text-decoration: none; color: #334155; background: #f8fafc; border-radius: 8px; padding: 10px; display: flex; flex-direction: column; gap: 8px;">
                    <?php if (!empty($b['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($b['image_url']); ?>" alt="<?php echo htmlspecialchars($b['title']); ?>" style="width: 100%; height: 100px; object-fit: cover; border-radius: 6px; background: #e2e8f0;">
                    <?php else: ?>
                        <div style="width: 100%; height: 100px; border-radius: 6px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 1.6rem;">🛍️</div>
                    <?php endif; ?>
                    <span style="font-size: 0.85rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($b['title']); ?></span>
                    <span style="font-size: 0.9rem; font-weight: bold; color: #c75022;">¥ <?php echo htmlspecialchars($b['price']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 商家/个人管理中心操作区 -->
<div class="shop-manage-box">
    <!-- 原左上角 1：我的买家订单 -->
    <a href="my_orders.php" class="manage-btn">
        📦 我的买家订单
    </a >

    <!-- 原左上角 2：收货地址管理 -->
    <a href="addresses.php" class="manage-btn">
        📍 收货地址管理
    </a >

    <!-- 原左上角 3：商家发货管理中心 -->
    <?php if (in_array($role, ['merchant','admin'], true)): ?>
    <a href="admin_orders.php" class="manage-btn">
        🚚 商家发货管理中心
    </a >

    <?php endif; ?>
    <a href="favorites.php" class="manage-btn">♡ 我的收藏</a>
    <!-- 4：商铺管理 -->
    <a href="shop_manage.php" class="manage-btn">
        🏪 商铺管理
    </a >
</div>

        <!-- 4. 资料及安全修改表单 -->
        <?php if ($is_logged_in): ?>
            <div class="profile-edit">
                <div class="section-title">修改个人资料与安全设置</div>
                <form action="user_center.php" method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?>
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="form-row full" style="margin-bottom: 15px;">
                        <label>更换个人头像</label>
                        <div class="avatar-upload-box">
                            <div class="avatar-preview-small">
                                <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="预览">
                                <?php else: ?>
                                    👤
                                <?php endif; ?>
                            </div>
                            <input type="file" name="avatar" accept="image/*">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-row">
                            <label>用户名称 / 昵称</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>

                        <div class="form-row">
                            <label>手机号码</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="添加或修改手机号">
                        </div>

                        <div class="form-row full">
                            <label>电子邮箱</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="如：example@domain.com">
                        </div>

                        <div class="form-row">
                            <label>原密码（修改密码时必填）</label>
                            <input type="password" name="old_password" placeholder="若不修改密码请留空">
                        </div>

                        <div class="form-row">
                            <label>设置新密码</label>
                            <input type="password" name="new_password" placeholder="输入新密码">
                        </div>
                    </div>

                    <button type="submit" class="btn-save">保存更新资料</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>