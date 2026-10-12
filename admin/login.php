<?php
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/admin_auth.php';
require_once __DIR__ . '/../lib/shop.php'; // query() 辅助函数

// L0 IP 白名单：非白名单 IP 返回 404（与不存在的页面一致）
admin_require_allowed_ip();

// 已登录直接进入后台
if (is_admin_logged_in()) { refresh_admin_session(); if (is_admin_logged_in()) { header('Location: index.php', true, 303); exit; } }

$message = '';
$next = admin_safe_next_url($_POST['next'] ?? $_GET['next'] ?? '');
$username_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha = strtolower(trim($_POST['captcha'] ?? ''));
    $username_input = $username;
    $ip = admin_client_ip();

    // 1. 验证码（失败不计数密码错误）
    $session_captcha = $_SESSION['captcha_admin'] ?? '';
    unset($_SESSION['captcha_admin']); // 用完即毁，一次性
    if ($captcha === '' || !hash_equals((string)$session_captcha, $captcha)) {
        $message = '验证码错误或已过期，请重试。';
    } else {
        // 2. 查账号（统一错误提示，不泄露账号是否存在）
        $stmt = $conn->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if (!$admin) {
            // 与密码错误路径耗时对齐：同样做一次哈希校验，不区分“账号不存在/密码错”
            password_verify($password, ADMIN_LOGIN_TIMING_HASH);
            query('INSERT INTO admin_login_attempts (username, ip, success) VALUES (?,?,0)', [$username, $ip]);
            audit_log('admin.login.failed', 'login', $username, ['reason' => 'no_such_user'], 'failure');
            $message = '用户名或密码错误。';
        } elseif (($lock = admin_lock_remaining($admin)) > 0) {
            // 3. 锁定期内一律拒绝（即使密码正确；提示不透露剩余时间/次数）
            query('INSERT INTO admin_login_attempts (username, ip, success) VALUES (?,?,0)', [$username, $ip]);
            audit_log('admin.login.locked', 'login', $username, ['remaining_seconds' => $lock], 'failure');
            $message = '账号已锁定，请稍后再试。';
        } elseif (!verify_account_password($password, $admin['password'])) {
            // 4. 密码错误：计数 +1，连续 3 次锁 24 小时（提示不透露剩余次数）
            $locked = admin_record_login_failure($admin);
            query('INSERT INTO admin_login_attempts (username, ip, success) VALUES (?,?,0)', [$username, $ip]);
            audit_log('admin.login.failed', 'login', $username, ['reason' => 'wrong_password', 'locked' => $locked], 'failure');
            $message = '用户名或密码错误。' . ($locked ? '失败次数过多，账号已锁定。' : '');
        } elseif ($admin['status'] !== 'active') {
            audit_log('admin.login.failed', 'login', $username, ['reason' => 'disabled'], 'failure');
            $message = '该账号已被禁用，请联系超级管理员。';
        } else {
            // 5. 登录成功：清计数、记录信息、审计、防 fixation
            query('UPDATE admin_users SET failed_attempts=0, locked_until=NULL, last_login_at=NOW(), last_login_ip=? WHERE id=?', [$ip, $admin['id']]);
            query('INSERT INTO admin_login_attempts (username, ip, success) VALUES (?,?,1)', [$username, $ip]);
            session_regenerate_id(true);
            $_SESSION['admin'] = [
                'id' => (int)$admin['id'],
                'username' => $admin['username'],
                'is_super' => (int)$admin['is_super'],
                'role_code' => '',
                'permissions' => [],
                'must_change_password' => (int)$admin['must_change_password'],
                'login_at' => time(),
            ];
            refresh_admin_session();
            audit_log('admin.login.success', 'login', $username, ['ip' => $ip]);
            if ((int)$admin['must_change_password'] === 1) {
                header('Location: password.php', true, 303);
            } else {
                header('Location: ' . ($next !== '' ? $next : 'index.php'), true, 303);
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台登录 - 惠蒙优选</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh;
               background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #312e81 100%); }
        .admin-login-box { background: #fff; padding: 40px 36px; border-radius: 16px; width: 380px;
                           box-shadow: 0 24px 48px -12px rgba(0,0,0,0.4); }
        .admin-login-box h1 { font-size: 1.4rem; text-align: center; margin-bottom: 6px; }
        .admin-login-box .sub { text-align: center; color: #64748b; font-size: 0.85rem; margin-bottom: 26px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.88rem; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .field input { width: 100%; padding: 11px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px;
                       box-sizing: border-box; font-size: 0.95rem; outline: none; transition: border-color .2s, box-shadow .2s; }
        .field input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.12); }
        .captcha-row { display: flex; gap: 10px; align-items: center; }
        .captcha-row input { flex: 1; }
        .captcha-row img { height: 42px; border-radius: 6px; border: 1px solid #e2e8f0; cursor: pointer; }
        .btn-login { width: 100%; padding: 12px; margin-top: 8px; border: none; border-radius: 8px;
                     background: var(--grad-brand); color: #fff; font-size: 1rem; font-weight: 700;
                     cursor: pointer; box-shadow: var(--shadow-brand); }
        .btn-login:hover { filter: brightness(1.08); }
        .msg { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 10px 12px;
               border-radius: 8px; margin-bottom: 16px; font-size: 0.88rem; text-align: center; }
        .back-link { display: block; text-align: center; margin-top: 20px; font-size: 0.85rem; color: #64748b; }
    </style>
</head>
<body>
    <div class="admin-login-box">
        <h1>惠蒙优选 · 管理后台</h1>
        <div class="sub">仅限授权管理员访问</div>

        <?php if (!empty($message)): ?><div class="msg"><?php echo h($message); ?></div><?php endif; ?>

        <form action="login.php" method="POST"><?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo h($next); ?>">
            <div class="field">
                <label>管理员账号</label>
                <input type="text" name="username" value="<?php echo h($username_input); ?>" required autocomplete="username" autofocus>
            </div>
            <div class="field">
                <label>密码</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label>验证码</label>
                <div class="captcha-row">
                    <input type="text" name="captcha" required autocomplete="off" placeholder="不区分大小写">
                    <img src="../captcha.php?scope=admin" onclick="this.src='../captcha.php?scope=admin&r='+Math.random()" title="点击刷新" alt="验证码">
                </div>
            </div>
            <button type="submit" class="btn-login">登 录</button>
        </form>

        <a class="back-link" href="../index.php">← 返回商城前台</a>
    </div>
</body>
</html>
