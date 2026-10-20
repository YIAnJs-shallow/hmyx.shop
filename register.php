<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/account.php'; // 先经 shop.php 定义 query()，coupon.php 内同名守卫才不会重复声明
require_once __DIR__ . '/lib/coupon.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    die("数据库连接失败，请检查 config/db.php");
}

$message = '';
$success = false;
// 记住当前选中的标签页（白名单，默认个人注册）
$active_tab = $_POST['register_type'] ?? 'personal';
if (!in_array($active_tab, ['personal', 'merchant'], true)) { $active_tab = 'personal'; } // 先取值再白名单校验，修复 GET 时三元返回 null

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $captcha = strtolower(trim($_POST['captcha'] ?? ''));

    // 1. 验证码校验（个人/企业注册共用）
    $session_captcha = $_SESSION['captcha_code'] ?? '';
    unset($_SESSION['captcha_code']); // 用完销毁

    // IP 维度注册配额：图形验证码之外的第二道闸，防脚本批量注册（无效请求同样记账）
    if (!rate_limit_hit('reg-ip:' . client_rate_ip(), RATE_SEND_IP_MAX, RATE_SEND_IP_WINDOW)) {
        $message = '操作过于频繁，请 1 小时后再试。';
    }
    elseif (empty($captcha) || $captcha !== $session_captcha) {
        $message = '验证码错误或已过期，请重新输入！';
    }
    // 2. 基础非空校验
    elseif (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $message = '所有必填字段不能为空！';
    }
    // 2.5 邮箱格式校验
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        $message = '请输入有效的邮箱地址！';
    }
    // 3. 双重密码校验
    elseif ($password !== $confirm_password) {
        $message = '两次输入的密码不一致，请重新确认！';
    }
    // 4. 密码强度校验（至少8位，含字母和数字）
    elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
        $message = '密码强度不足！必须至少 8 位，且必须同时包含字母和数字。';
    }
    elseif ($active_tab === 'merchant') {
        // ===== 企业/商户注册：账号 + 店铺 + 新人券在同一事务内，任一失败整体回滚 =====
        try {
            $newMerchantId = register_merchant_account(
                $username, $email, $password,
                trim($_POST['shop_name'] ?? ''),
                trim($_POST['contact_phone'] ?? ''),
                trim($_POST['business_scope'] ?? '')
            );
            $success = true;
            $message = '商户注册成功！新人优惠券已发放到您的账户，请点击下方“前往登录”使用邮箱登录。';
        } catch (DomainException $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            $message = '注册失败，请稍后再试！';
        }
    } else {
        // ===== 个人注册：沿用现有唯一性校验与自动发新人券逻辑 =====
        if ($db instanceof mysqli) {
            $check = $db->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            $exists = (bool)$check->fetch();
        }

        if ($exists) {
            $message = '该用户名已被使用，请换一个！';
        } else {
            // 邮箱唯一性校验（找回密码按邮箱定位账号）
            if ($db instanceof mysqli) {
                $check_email = $db->prepare("SELECT id FROM users WHERE email = ?");
                $check_email->bind_param("s", $email);
                $check_email->execute();
                $email_exists = $check_email->get_result()->num_rows > 0;
            } else {
                $check_email = $db->prepare("SELECT id FROM users WHERE email = ?");
                $check_email->execute([$email]);
                $email_exists = (bool)$check_email->fetch();
            }
            if ($email_exists) {
                $message = '该邮箱已被注册，请换一个！';
            } else {
                $hash_pass = password_hash($password, PASSWORD_DEFAULT);

                if ($db instanceof mysqli) {
                    $ins = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
                    $ins->bind_param("sss", $username, $email, $hash_pass);
                    $exec_res = $ins->execute();
                } else {
                    $ins = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
                    $exec_res = $ins->execute([$username, $email, $hash_pass]);
                }

                if ($exec_res) {
                    $success = true;
                    $newUserId = (int)$db->insert_id;
                    coupon_grant_newuser($newUserId);
                    $message = '注册成功！新人优惠券已发放到您的账户，登录后可在“优惠券”页查看。请点击下方“前往登录”进行登录。';
                } else {
                    $message = '注册失败，请稍后再试！';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>注册账号 - 惠蒙优选</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/auth.css?v=8">
</head>
<body class="auth-page">
    <div class="auth-wrap">
        <!-- Canvas 天空背景：极光 / 繁星 / 雪原（js/sky.js 绘制，纯装饰） -->
        <canvas id="sky-canvas" aria-hidden="true"></canvas>
        <!-- 左区：品牌展示 -->
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
        <h2>创建新账号</h2>

        <?php if (!empty($message)): ?>
            <div class="msg <?php echo $success ? 'msg-succ' : 'msg-err'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- 双注册平级标签：个人注册 / 企业商户注册 -->
        <div class="tab-box">
            <button type="button" id="btn-personal" class="tab-btn <?php echo $active_tab === 'personal' ? 'active' : ''; ?>" onclick="setRegisterType('personal')">👤 个人注册</button>
            <button type="button" id="btn-merchant" class="tab-btn <?php echo $active_tab === 'merchant' ? 'active' : ''; ?>" onclick="setRegisterType('merchant')">🏪 企业/商户注册</button>
        </div>

        <!-- 个人注册 -->
        <form action="register.php" method="POST" id="form-personal" class="tab-pane <?php echo $active_tab === 'personal' ? 'active' : ''; ?>"><?php echo csrf_field(); ?>
            <input type="hidden" name="register_type" value="personal">

            <div class="input-group">
                <label>账号/用户名</label>
                <input type="text" name="username" required maxlength="50" placeholder="请输入新账号">
            </div>

            <div class="input-group">
                <label>注册邮箱</label>
                <input type="email" name="email" required maxlength="100" placeholder="用于找回密码" value="<?php echo h($_POST['email'] ?? ''); ?>">
                <div class="pwd-tip">必填：忘记密码时将通过该邮箱接收验证码</div>
            </div>

            <div class="input-group">
                <label>设置密码</label>
                <input type="password" name="password" required placeholder="请输入新密码">
                <div class="pwd-tip">必须至少 8 位，同时包含字母和数字</div>
            </div>

            <div class="input-group">
                <label>确认密码</label>
                <input type="password" name="confirm_password" required placeholder="请再次输入新密码">
            </div>

            <div class="input-group">
                <label>验证码</label>
                <div class="captcha-box">
                    <input type="text" name="captcha" required placeholder="验证码" autocomplete="off">
                    <img src="captcha.php" class="captcha-img" id="captcha-img" onclick="this.src='captcha.php?'+Math.random()" title="点击刷新验证码">
                </div>
            </div>

            <button type="submit" class="btn-submit">立即注册</button>
        </form>

        <!-- 企业/商户注册：个人字段 + 店铺名称 / 联系电话 / 经营范围 -->
        <form action="register.php" method="POST" id="form-merchant" class="tab-pane <?php echo $active_tab === 'merchant' ? 'active' : ''; ?>"><?php echo csrf_field(); ?>
            <input type="hidden" name="register_type" value="merchant">

            <div class="input-group">
                <label>账号/用户名</label>
                <input type="text" name="username" required maxlength="50" placeholder="请输入新账号">
            </div>

            <div class="input-group">
                <label>注册邮箱</label>
                <input type="email" name="email" required maxlength="100" placeholder="用于登录与找回密码">
            </div>

            <div class="input-group">
                <label>设置密码</label>
                <input type="password" name="password" required placeholder="请输入新密码">
                <div class="pwd-tip">必须至少 8 位，同时包含字母和数字</div>
            </div>

            <div class="input-group">
                <label>确认密码</label>
                <input type="password" name="confirm_password" required placeholder="请再次输入新密码">
            </div>

            <div class="input-group">
                <label>店铺名称</label>
                <input type="text" name="shop_name" required maxlength="100" placeholder="例如：长沙特色美食小店">
            </div>

            <div class="input-group">
                <label>联系电话</label>
                <input type="text" name="contact_phone" required maxlength="20" placeholder="请输入商户联系电话">
            </div>

            <div class="input-group">
                <label>经营范围</label>
                <input type="text" name="business_scope" maxlength="100" placeholder="例如：餐饮美食、地方特产">
            </div>

            <div class="input-group">
                <label>验证码</label>
                <div class="captcha-box">
                    <input type="text" name="captcha" required placeholder="验证码" autocomplete="off">
                    <img src="captcha.php" class="captcha-img" onclick="this.src='captcha.php?'+Math.random()" title="点击刷新验证码">
                </div>
            </div>

            <button type="submit" class="btn-submit">注册并开通店铺</button>
        </form>

        <!-- 彻底独立于 <form> 之外的跳转动作（防拦截机制） -->
        <div class="links">
            <button type="button" onclick="window.location.href='user_login.php';">已有账号？前往登录</button> |
            <button type="button" onclick="window.location.href='index.php';">返回商城首页</button>
        </div>
            </div>
        </div>
    </div>

    <div class="auth-footer">© 2026 惠蒙优选 · 本站为课程演示项目</div>

    <script>
        function setRegisterType(type) {
            document.getElementById('form-personal').classList.toggle('active', type === 'personal');
            document.getElementById('form-merchant').classList.toggle('active', type === 'merchant');
            document.getElementById('btn-personal').classList.toggle('active', type === 'personal');
            document.getElementById('btn-merchant').classList.toggle('active', type === 'merchant');
        }
    </script>

    <script src="js/sky.js?v=9"></script>
</body>
</html>
