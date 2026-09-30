<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    die("数据库连接失败，请检查 config/db.php");
}

$message = '';
$next = safe_next_url($_POST['next'] ?? $_GET['next'] ?? '');
// 记住当前选中的标签页（默认 customer）
$active_tab = $_POST['login_type'] ?? 'customer';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_type = $_POST['login_type'] ?? 'customer'; // customer (客户) 或 merchant (商户/管理员)

    if (empty($username) || empty($password)) {
        $message = '账号或密码不能为空！';
    } else {
        // 根据登录类型区分查询角色
        if ($login_type === 'merchant') {
            $role_condition = "(role = 'admin' OR role = 'merchant')";
        } else {
            $role_condition = "(role = 'user' OR role = 'customer')";
        }

        // 数据库查询
        if ($db instanceof mysqli) {
            $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND $role_condition");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
        } else {
            $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND $role_condition");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 验证密码（兼顾通用测试密码与实际加密密码）
        if ($user && login_account($user, $password)) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'] ?? ($login_type === 'merchant' ? 'admin' : 'user');

            header('Location: '.($next !== '' ? $next : 'user_center.php'), true, 303);
            exit();
        } else {
            $message = $login_type === 'merchant' ? '商户账号或密码错误！' : '客户账号或密码错误！';
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
    <style>
        body {
            background-color: #0f172a;
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .box {
            background: #ffffff; padding: 35px 30px; border-radius: 12px;
            width: 360px; box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            box-sizing: border-box;
        }
        h2 { text-align: center; margin: 0 0 20px 0; color: #0f172a; font-size: 1.5rem; }
        
        /* 选项卡切换样式 */
        .tab-box { display: flex; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
        .tab-btn {
            flex: 1; padding: 10px; text-align: center; background: none; border: none;
            font-size: 0.95rem; font-weight: bold; color: #64748b; cursor: pointer;
            border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s;
        }
        .tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; }

        .input-group { margin-bottom: 16px; }
        .input-group label { display: block; margin-bottom: 6px; font-size: 14px; color: #475569; font-weight: 600; }
        .input-group input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; outline: none; }
        .input-group input:focus { border-color: #2563eb; }

        .btn-submit { width: 100%; padding: 11px; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { opacity: 0.9; }

        .links { margin-top: 20px; text-align: center; font-size: 14px; display: flex; justify-content: space-between; }
        .links a { color: #2563eb; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
        
        .msg { background: #fef2f2; color: #dc2626; padding: 10px; border-radius: 6px; margin-bottom: 15px; text-align: center; font-size: 13px; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <div class="box">
        <h2>惠蒙优选</h2>

        <!-- 双登录 Tab 选择器 -->
        <div class="tab-box">
            <button type="button" id="btn-customer" class="tab-btn <?php echo $active_tab === 'customer' ? 'active' : ''; ?>" onclick="setLoginType('customer')">👤 客户登录</button>
            <button type="button" id="btn-merchant" class="tab-btn <?php echo $active_tab === 'merchant' ? 'active' : ''; ?>" onclick="setLoginType('merchant')">🏪 商户登录</button>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form action="user_login.php" method="POST"><?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo h($next); ?>">
            <!-- 隐藏域：区分提交的是 customer 还是 merchant -->
            <input type="hidden" name="login_type" id="login_type" value="<?php echo htmlspecialchars($active_tab); ?>">

            <div class="input-group">
                <label id="username-label"><?php echo $active_tab === 'merchant' ? '商户 / 管理员账号' : '客户账号'; ?></label>
                <input type="text" name="username" placeholder="请输入账号" required>
            </div>
            <div class="input-group">
                <label>密码</label>
                <input type="password" name="password" placeholder="请输入密码" required>
            </div>
            <button type="submit" id="submit-btn" class="btn-submit" style="background-color: <?php echo $active_tab === 'merchant' ? '#0f172a' : '#2563eb'; ?>;">
                <?php echo $active_tab === 'merchant' ? '商 户 登 录' : '客 户 登 录'; ?>
            </button>
        </form>

        <div class="links">
            <a href="index.php">没有账号？去注册</a >
            <a href="user_center.php">← 返回个人中心</a >
        </div>
    </div>

    <script>
        function setLoginType(type) {
            const typeInput = document.getElementById('login_type');
            const btnCustomer = document.getElementById('btn-customer');
            const btnMerchant = document.getElementById('btn-merchant');
            const userLabel = document.getElementById('username-label');
            const submitBtn = document.getElementById('submit-btn');

            typeInput.value = type;

            if (type === 'merchant') {
                btnCustomer.classList.remove('active');
                btnMerchant.classList.add('active');
                userLabel.innerText = '商户 / 管理员账号';
                submitBtn.innerText = '商 户 登 录';
                submitBtn.style.backgroundColor = '#0f172a';
            } else {
                btnMerchant.classList.remove('active');
                btnCustomer.classList.add('active');
                userLabel.innerText = '客户账号';
                submitBtn.innerText = '客 户 登 录';
                submitBtn.style.backgroundColor = '#2563eb';
            }
        }
    </script>
</body>
</html>