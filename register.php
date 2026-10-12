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
$active_tab = in_array($_POST['register_type'] ?? 'personal', ['personal', 'merchant'], true) ? $_POST['register_type'] : 'personal';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $captcha = strtolower(trim($_POST['captcha'] ?? ''));

    // 1. 验证码校验（个人/企业注册共用）
    $session_captcha = $_SESSION['captcha_code'] ?? '';
    unset($_SESSION['captcha_code']); // 用完销毁

    if (empty($captcha) || $captcha !== $session_captcha) {
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
    <style>
        body { background-color: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; font-family: sans-serif; }
        .box { background: #fff; padding: 30px; border-radius: 8px; width: 360px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #cbd5e1; }
        h2 { text-align: center; margin-bottom: 20px; color: #0f172a; }
        .tab-box { display: flex; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
        .tab-btn { flex: 1; padding: 10px; text-align: center; background: none; border: none; font-size: 0.95rem; font-weight: bold; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .tab-btn.active { color: #16a34a; border-bottom-color: #16a34a; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; font-size: 14px; color: #475569; }
        .input-group input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; }
        .captcha-box { display: flex; gap: 10px; align-items: center; }
        .captcha-box input { flex: 1; }
        .captcha-img { cursor: pointer; border: 1px solid #cbd5e1; border-radius: 4px; height: 38px; }
        .pwd-tip { font-size: 12px; color: #64748b; margin-top: 4px; }
        .btn-submit { width: 100%; padding: 10px; background: #16a34a; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { background: #15803d; }
        .links { margin-top: 18px; text-align: center; font-size: 14px; }
        .links button { background: none; border: none; color: #2563eb; cursor: pointer; font-size: 14px; padding: 0; }
        .links button:hover { text-decoration: underline; }
        .msg { padding: 8px; border-radius: 4px; margin-bottom: 15px; text-align: center; font-size: 13px; }
        .msg-err { background: #fef2f2; color: #dc2626; }
        .msg-succ { background: #f0fdf4; color: #166534; }
    </style>
</head>
<body>
    <div class="box">
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

    <script>
        function setRegisterType(type) {
            document.getElementById('form-personal').classList.toggle('active', type === 'personal');
            document.getElementById('form-merchant').classList.toggle('active', type === 'merchant');
            document.getElementById('btn-personal').classList.toggle('active', type === 'personal');
            document.getElementById('btn-merchant').classList.toggle('active', type === 'merchant');
        }
    </script>
</body>
</html>
