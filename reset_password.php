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
    <link rel="stylesheet" href="css/auth.css?v=8">
</head>
<body class="auth-page">
    <div class="auth-wrap">
        <!-- Canvas 天空背景：极光 / 繁星 / 雪原（js/sky.js 绘制，纯装饰） -->
        <canvas id="sky-canvas" aria-hidden="true"></canvas>
        <!-- 左区：品牌展示（与登录/注册页一致） -->
        <div class="auth-brand">
            <h1 class="brand-name">惠蒙优选</h1>
            <p class="brand-slogan"><span class="slogan-main">好货精选</span><span class="slogan-sub">多商户入驻</span></p>
            <ul class="brand-points">
                <li>
                    <span class="point-icon">🛍️</span>
                    <div>
                        <p class="point-title">精选好物</p>
                        <p class="point-desc">热销榜单每日更新</p>
                    </div>
                </li>
                <li>
                    <span class="point-icon">🏪</span>
                    <div>
                        <p class="point-title">多商户入驻</p>
                        <p class="point-desc">店铺商品一键直达</p>
                    </div>
                </li>
                <li>
                    <span class="point-icon">🎁</span>
                    <div>
                        <p class="point-title">新人礼包</p>
                        <p class="point-desc">注册即领满 100 减 20</p>
                    </div>
                </li>
            </ul>
        </div>

        <!-- 右区：表单卡片 -->
        <div class="auth-form">
            <div class="auth-card">
        <h2>重置密码</h2>

        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $success ? 'msg-succ' : ''; ?>">
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
            <a href="forgot_password.php">没收到验证码？重新获取</a>
            <a href="user_login.php">← 返回登录</a>
        </div>
            </div>
        </div>
    </div>

    <div class="auth-footer">© 2026 惠蒙优选 · 本站为课程演示项目</div>

    <script src="js/sky.js?v=9"></script>
</body>
</html>
