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
        // IP 维度发码配额（同邮箱 10 分钟窗口已有）；超限静默跳过发送，对外提示不变（与按邮箱限流表现一致，防枚举）
        if (rate_limit_hit('send-ip:' . client_rate_ip(), RATE_SEND_IP_MAX, RATE_SEND_IP_WINDOW)) {
            password_reset_issue($email);
        }
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
        <h2>找回密码</h2>

        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $success ? 'msg-succ' : ''; ?>">
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
            <a href="reset_password.php">已收到验证码？去重置密码</a>
            <a href="user_login.php">← 返回登录</a>
        </div>
            </div>
        </div>
    </div>

    <div class="auth-footer">© 2026 惠蒙优选 · 本站为课程演示项目</div>

    <script src="js/sky.js?v=9"></script>
</body>
</html>
