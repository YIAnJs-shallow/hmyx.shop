<?php
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/admin_auth.php';
require_admin_login(); // 首登强制改密时 require_admin_login 会把用户重定向到这里

$message = '';
$message_ok = false;
$forced = ($_SESSION['admin']['must_change_password'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare('SELECT password FROM admin_users WHERE id = ? LIMIT 1');
    $admin_id = (int)$_SESSION['admin']['id'];
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !verify_account_password($old_password, $row['password'])) {
        $message = '原密码错误。';
    } elseif ($new_password !== $confirm_password) {
        $message = '两次输入的新密码不一致。';
    } elseif (($error = validate_admin_password($new_password, $_SESSION['admin']['username'])) !== '') {
        $message = $error . '。';
    } elseif (verify_account_password($new_password, $row['password'])) {
        $message = '新密码不能与原密码相同。';
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        query('UPDATE admin_users SET password=?, must_change_password=0 WHERE id=?', [$hash, $admin_id]);
        $_SESSION['admin']['must_change_password'] = 0;
        audit_log('admin.password.change', 'admin', (string)$admin_id);
        $message = '密码修改成功，正在进入后台…';
        $message_ok = true;
        header('refresh:2;url=index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改密码 - 惠蒙优选管理后台</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh;
               background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #312e81 100%); }
        .admin-login-box { background: #fff; padding: 40px 36px; border-radius: 16px; width: 400px;
                           box-shadow: 0 24px 48px -12px rgba(0,0,0,0.4); }
        .admin-login-box h1 { font-size: 1.3rem; text-align: center; margin-bottom: 6px; }
        .admin-login-box .sub { text-align: center; color: #64748b; font-size: 0.85rem; margin-bottom: 24px; }
        .notice { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 10px 12px;
                  border-radius: 8px; font-size: 0.85rem; margin-bottom: 18px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.88rem; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .field input { width: 100%; padding: 11px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px;
                       box-sizing: border-box; font-size: 0.95rem; outline: none; }
        .field input:focus { border-color: #2563eb; }
        .pwd-tip { font-size: 0.78rem; color: #94a3b8; margin-top: 4px; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; background: var(--grad-brand);
               color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .msg { padding: 10px 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.88rem; text-align: center; }
        .msg-err { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .msg-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    </style>
</head>
<body>
    <div class="admin-login-box">
        <h1>修改密码</h1>
        <div class="sub">账号：<?php echo h($_SESSION['admin']['username']); ?></div>

        <?php if ($forced): ?>
            <div class="notice">⚠️ 首次登录（或密码已被重置），必须修改密码后才能使用后台。</div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $message_ok ? 'msg-ok' : 'msg-err'; ?>"><?php echo h($message); ?></div>
        <?php endif; ?>

        <form action="password.php" method="POST"><?php echo csrf_field(); ?>
            <div class="field">
                <label>原密码</label>
                <input type="password" name="old_password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label>新密码</label>
                <input type="password" name="new_password" required autocomplete="new-password">
                <div class="pwd-tip">至少 10 位，需包含大写/小写/数字/特殊字符中至少三类，且不含用户名</div>
            </div>
            <div class="field">
                <label>确认新密码</label>
                <input type="password" name="confirm_password" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn">确认修改</button>
        </form>
    </div>
</body>
</html>
