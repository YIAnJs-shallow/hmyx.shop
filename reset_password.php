<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/password_reset.php';

// 与注册页一致的密码强度规则：至少8位，含字母和数字
const RESET_PASSWORD_RULE = '/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/';

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        $message = '请输入有效的邮箱地址！';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $message = '请输入 6 位数字验证码！';
    } elseif ($new_password !== $confirm_password) {
        $message = '两次输入的密码不一致，请重新确认！';
    } elseif (!preg_match(RESET_PASSWORD_RULE, $new_password)) {
        $message = '密码强度不足！必须至少 8 位，且必须同时包含字母和数字。';
    } else {
        $verify = password_reset_verify($email, $code);
        if ($verify === 'locked') {
            $message = '验证码错误次数过多，请 30 分钟后再试。';
        } elseif ($verify !== 'ok') {
            $message = '验证码错误或已过期！';
        } elseif (!password_reset_apply($email, $new_password)) {
            $message = '重置失败，请稍后再试！';
        } else {
            password_reset_audit('user.password.reset_done', $email, 'success');
            $success = true;
            $message = '密码重置成功！请使用新密码登录。';
        }
        if (!$success && $verify !== 'ok') {
            password_reset_audit('user.password.reset_verify', $email, 'failure', ['reason' => $verify]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重置密码 - 惠蒙优选</title>
    <style>
        body { background-color: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; font-family: sans-serif; }
        .box { background: #fff; padding: 30px; border-radius: 8px; width: 360px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #cbd5e1; }
        h2 { text-align: center; margin-bottom: 20px; color: #0f172a; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; font-size: 14px; color: #475569; }
        .input-group input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; }
        .pwd-tip { font-size: 12px; color: #64748b; margin-top: 4px; }
        .btn-submit { width: 100%; padding: 10px; background: #16a34a; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { background: #15803d; }
        .links { margin-top: 18px; text-align: center; font-size: 14px; }
        .links a { color: #2563eb; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
        .msg { padding: 8px; border-radius: 4px; margin-bottom: 15px; text-align: center; font-size: 13px; }
        .msg-err { background: #fef2f2; color: #dc2626; }
        .msg-succ { background: #f0fdf4; color: #166534; }
    </style>
</head>
<body>
    <div class="box">
        <h2>重置密码</h2>

        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $success ? 'msg-succ' : 'msg-err'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form action="reset_password.php" method="POST"><?php echo csrf_field(); ?>
            <div class="input-group">
                <label>注册邮箱</label>
                <input type="email" name="email" required maxlength="100" placeholder="请输入注册时填写的邮箱" value="<?php echo h($_POST['email'] ?? ''); ?>">
            </div>
            <div class="input-group">
                <label>邮箱验证码</label>
                <input type="text" name="code" required maxlength="6" pattern="\d{6}" placeholder="6 位数字验证码" autocomplete="off">
            </div>
            <div class="input-group">
                <label>新密码</label>
                <input type="password" name="new_password" required placeholder="请输入新密码">
                <div class="pwd-tip">必须至少 8 位，同时包含字母和数字</div>
            </div>
            <div class="input-group">
                <label>确认新密码</label>
                <input type="password" name="confirm_password" required placeholder="请再次输入新密码">
            </div>
            <button type="submit" class="btn-submit">重置密码</button>
        </form>
        <?php endif; ?>

        <div class="links">
            <a href="forgot_password.php">没收到验证码？重新获取</a><br>
            <a href="user_login.php">← 返回登录</a>
        </div>
    </div>
</body>
</html>
