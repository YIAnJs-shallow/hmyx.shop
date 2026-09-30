<?php
require_once __DIR__ . '/../lib/security.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!function_exists('project_private_config_dir')) {
    function project_private_config_dir() {
        $projectRoot = dirname(__DIR__);
        return dirname(dirname($projectRoot)) . '/private/' . basename($projectRoot);
    }
}
$databaseLocalPath = project_private_config_dir() . '/database.local.php';
$databaseLocal = is_file($databaseLocalPath) ? require $databaseLocalPath : [];
// 数据库连接参数
$host = getenv('DB_HOST') ?: ($databaseLocal['host'] ?? '127.0.0.1');
$db_user = getenv('DB_USER') ?: ($databaseLocal['user'] ?? 'root');
$db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($databaseLocal['password'] ?? '');
$db_name = getenv('DB_NAME') ?: ($databaseLocal['database'] ?? 'my_web_db');

// 建立连接
try {
    $conn = new mysqli($host, $db_user, $db_pass, $db_name, (int)(getenv('DB_PORT') ?: ($databaseLocal['port'] ?? 3306)));
} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(503);
    exit('数据库暂不可用，请检查数据库服务与连接配置。');
}

// 检查连接
if ($conn->connect_error) {
    error_log($conn->connect_error);
    http_response_code(503);
    exit('数据库暂不可用，请检查数据库服务与连接配置。');
}

// 设置字符集，防止中文乱码
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");
// Refresh roles on every request so revocation takes effect immediately.
if (!empty($_SESSION['user_id'])) {
    $stmt_user = $conn->prepare('SELECT username,role FROM users WHERE id=?');
    $stmt_user->bind_param('i', $_SESSION['user_id']);
    $stmt_user->execute();
    $current_user = $stmt_user->get_result()->fetch_assoc();
    if ($current_user) { $_SESSION['role']=$current_user['role']; $_SESSION['username']=$current_user['username']; }
    else { $_SESSION=[]; }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') csrf_check();
