<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';

// 自动适配数据库连接变量
$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    die("数据库连接失败：请检查 config/db.php 中的连接配置！");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo "<script>alert('账号或密码不能为空！'); history.back();</script>";
        exit();
    }

    // 限制 SQL 只能查 role = 'admin' 的账号
    if ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND role = 'admin'");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
    } else {
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND role = 'admin'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 验证管理员密码
    if ($admin && login_account($admin, $password)) {
        
        // 【关键修复】：全方位补齐可能用到的 Session 变量名
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['username'] = $admin['username'];
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_name'] = $admin['username'];
        $_SESSION['is_login'] = true;
        $_SESSION['role'] = 'admin';

        echo "<script>alert('管理员登录成功！'); location.href='admin.php';</script>";
        exit();
    } else {
        echo "<script>alert('登录失败：账号密码错误或无管理员权限！'); history.back();</script>";
        exit();
    }
}
?>