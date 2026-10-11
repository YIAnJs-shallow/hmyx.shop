<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/password_reset.php';

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        $message = '请输入有效的邮箱地址！';
    } else {
        password_reset_issue($email);
        // 无论邮箱是否注册、是否发送成功，对外提示完全一致，不泄露账号信息
        $success = true;
        $message = '如果该邮箱已注册，验证码已发送，请查收邮件（10 分钟内有效，同一邮箱 10 分钟内只能获取一次）。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>找回密码 - 惠蒙优选</title>
    <style>
        body { background-color: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; font-family: sans-serif; }
        .box { background: #fff; padding: 30px; border-radius: 8px; width: 360px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #cbd5e1; }
        h2 { text-align: center; margin-bottom: 20px; color: #0f172a; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; font-size: 14px; color: #475569; }
        .input-group input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; }
        .tip { font-size: 12px; color: #64748b; margin-top: 4px; }
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
        <h2>找回密码</h2>

        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $success ? 'msg-succ' : 'msg-err'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form action="forgot_password.php" method="POST"><?php echo csrf_field(); ?>
            <div class="input-group">
                <label>注册邮箱</label>
                <input type="email" name="email" required maxlength="100" placeholder="请输入注册时填写的邮箱" value="<?php echo h($_POST['email'] ?? ''); ?>">
                <div class="tip">我们将向该邮箱发送 6 位数字验证码，10 分钟内有效。</div>
            </div>
            <button type="submit" class="btn-submit">发送验证码</button>
        </form>

        <div class="links">
            <a href="reset_password.php">已收到验证码？去重置密码</a><br>
            <a href="user_login.php">← 返回登录</a>
        </div>
    </div>
</body>
</html>
