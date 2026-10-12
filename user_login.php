<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/email_login.php';
require_once __DIR__ . '/lib/account.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    die("数据库连接失败，请检查 config/db.php");
}

$message = '';
$next = safe_next_url($_POST['next'] ?? $_GET['next'] ?? '');
// 记住当前选中的标签页（白名单，默认验证码登录）
$active_tab = $_POST['login_type'] ?? 'code';
if (!in_array($active_tab, ['code', 'account'], true)) { $active_tab = 'code'; } // 先取值再白名单校验（原写法条件用兜底值、取值用原始值，GET 时返回 null）

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($active_tab === 'code') {
        // ===== 验证码登录：邮箱 + 6 位验证码，登录注册二合一 =====
        $email = trim($_POST['email'] ?? '');
        $code = trim($_POST['email_code'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $message = '请输入有效的邮箱地址！';
        } elseif (!preg_match('/^\d{6}$/D', $code)) {
            $message = '请输入 6 位数字验证码！';
        } else {
            $result = email_login_verify($email, $code);
            if ($result === 'locked') {
                $message = '验证码错误次数过多，该邮箱已锁定 30 分钟，请稍后再试。';
            } elseif ($result !== 'ok') {
                $message = '验证码错误或已过期，请重新获取！';
            } else {
                // 验证通过：邮箱存在即登录，不存在则自动创建账号
                $user = email_login_find_or_create($email);
                if (($user['role'] ?? '') === 'admin') {
                    // 管理员账号只能通过后台独立登录入口
                    $message = '该账号请通过管理员后台登录入口登录。';
                } else {
                    session_regenerate_id(true);
                    $_SESSION = (isset($_SESSION['admin']) ? ['admin' => $_SESSION['admin']] : [])
                        + ['user_id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']];
                    header('Location: '.($next !== '' ? $next : 'user_center.php'), true, 303);
                    exit();
                }
            }
        }
    } else {
        // ===== 账号登录：用户名/邮箱/手机号 + 密码（角色由账号本身决定，页面不再选角色） =====
        $identifier = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $message = '账号或密码不能为空！';
        } else {
            $user = find_user_by_login_identifier($identifier);

            if ($user && login_account($user, $password)) {
                header('Location: '.($next !== '' ? $next : 'user_center.php'), true, 303);
                exit();
            } else {
                $message = '账号或密码错误！';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>账号登录 - 惠蒙优选</title>
    <link rel="stylesheet" href="css/auth.css">
</head>
<body class="auth-page">
    <div class="auth-wrap">
        <!-- 左区：品牌展示 -->
        <div class="auth-brand">
            <div class="aurora" aria-hidden="true">
                <span class="a1"></span>
                <span class="a2"></span>
                <span class="a3"></span>
            </div>
            <div class="stars" aria-hidden="true">
                <span class="star-s1"></span>
                <span class="star-s2"></span>
            </div>
            <div class="grid-layer" aria-hidden="true"></div>
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
        <h2>惠蒙优选</h2>

        <!-- 双登录平级标签：验证码登录 / 账号登录 -->
        <div class="tab-box">
            <button type="button" id="btn-code" class="tab-btn <?php echo $active_tab === 'code' ? 'active' : ''; ?>" onclick="setLoginType('code')">📧 验证码登录</button>
            <button type="button" id="btn-account" class="tab-btn <?php echo $active_tab === 'account' ? 'active' : ''; ?>" onclick="setLoginType('account')">🔒 账号登录</button>
        </div>

        <?php if (!empty($message)): ?>
            <div class="msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- 验证码登录：登录注册二合一，未注册邮箱验证通过后自动创建账号 -->
        <form action="user_login.php" method="POST" id="form-code" class="tab-pane <?php echo $active_tab === 'code' ? 'active' : ''; ?>"><?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo h($next); ?>">
            <input type="hidden" name="login_type" value="code">

            <div class="input-group">
                <label>邮箱</label>
                <input type="email" name="email" placeholder="请输入邮箱" maxlength="100" required value="<?php echo h($_POST['email'] ?? ''); ?>">
            </div>
            <div class="input-group">
                <label>验证码</label>
                <div class="code-box">
                    <input type="text" name="email_code" placeholder="6 位验证码" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required>
                    <button type="button" class="btn-code" id="send-code-btn" onclick="sendLoginCode()">获取验证码</button>
                </div>
                <div class="tip">未注册的邮箱验证通过后将自动创建账号</div>
            </div>
            <button type="submit" class="btn-submit">登 录 / 注 册</button>
        </form>

        <!-- 账号登录：用户名 / 邮箱 / 手机号 + 密码 -->
        <form action="user_login.php" method="POST" id="form-account" class="tab-pane <?php echo $active_tab === 'account' ? 'active' : ''; ?>"><?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo h($next); ?>">
            <input type="hidden" name="login_type" value="account">

            <div class="input-group">
                <label>账号</label>
                <input type="text" name="username" placeholder="用户名 / 邮箱 / 手机号" required value="<?php echo h($_POST['username'] ?? ''); ?>">
            </div>
            <div class="input-group">
                <label>密码</label>
                <input type="password" name="password" placeholder="请输入密码" required>
            </div>
            <button type="submit" class="btn-submit">登 录</button>
        </form>

        <p style="text-align:center"><a href="forgot_password.php">忘记密码？</a></p>

        <div class="links">
            <a href="register.php">没有账号？去注册</a >
            <a href="index.php">← 返回首页</a >
        </div>
            </div>
        </div>
    </div>

    <div class="auth-footer">© 2026 惠蒙优选 · 本站为课程演示项目</div>

    <script>
        function setLoginType(type) {
            document.getElementById('form-code').classList.toggle('active', type === 'code');
            document.getElementById('form-account').classList.toggle('active', type === 'account');
            document.getElementById('btn-code').classList.toggle('active', type === 'code');
            document.getElementById('btn-account').classList.toggle('active', type === 'account');
        }

        // 获取验证码：POST 邮箱 + CSRF，成功后倒计时 60 秒防重发
        let sendTimer = null;
        function sendLoginCode() {
            const form = document.getElementById('form-code');
            const email = form.querySelector('input[name="email"]').value.trim();
            if (!email) { alert('请先输入邮箱'); return; }
            const body = new URLSearchParams();
            body.append('email', email);
            body.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            fetch('email_login_code.php', { method: 'POST', body: body })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    alert(data.message);
                    if (data.success) startCountdown(60);
                })
                .catch(function () { alert('网络异常，请稍后重试'); });
        }
        function startCountdown(seconds) {
            const btn = document.getElementById('send-code-btn');
            clearInterval(sendTimer);
            btn.disabled = true;
            let left = seconds;
            btn.textContent = left + ' 秒后重发';
            sendTimer = setInterval(function () {
                left--;
                if (left <= 0) {
                    clearInterval(sendTimer);
                    btn.disabled = false;
                    btn.textContent = '获取验证码';
                } else {
                    btn.textContent = left + ' 秒后重发';
                }
            }, 1000);
        }
    </script>
</body>
</html>
