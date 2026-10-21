<?php
require_once __DIR__ . '/../lib/security.php';
// 全站 HTML 响应统一禁缓存（2026-10-19 治"改版后浏览器死缓存"根因）：
// 所有动态页面（前台经 lib/shop.php、后台经 admin/inc/auth.php、AJAX/JSON 出口直接）都经本文件加载。
// 仅 Web SAPI 且输出未开始时发送（CLI 的 tests/cron 不发头）；静态资源不动（css/js 由 ?v=N 版本号管变更）。
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
}
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

// 建立连接（先显式初始化：try 块内赋值属条件定义，静态分析无法确认后续路径必有值）
$conn = null;
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
    // 仅清除前台登录态，保留 $_SESSION['admin']（后台管理员登录互不干扰）
    else { unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']); }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') csrf_check();
