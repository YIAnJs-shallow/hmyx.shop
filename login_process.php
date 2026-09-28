<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) {
    die("数据库连接失败！");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo "<script>alert('账号或密码不能为空！'); history.back();</script>";
        exit();
    }

    // 这里不限制 role = 'admin'，允许所有注册的普通用户登录
    if ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
    } else {
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 验证普通用户密码（兼容明文、测试密码及哈希密码）
    if ($user && login_account($user, $password)) {
        
        // 种下普通用户的 Session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // 登录成功后，直接弹窗并送回个人中心！
        echo "<script>alert('登录成功欢迎回来！'); location.href='user_center.php';</script>";
        exit();
    } else {
        echo "<script>alert('登录失败：账号或密码错误！'); history.back();</script>";
        exit();
    }
}
?>