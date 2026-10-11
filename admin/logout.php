<?php
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/admin_auth.php';
// 后台登出：仅清除管理员登录态（前台登录不受影响）
if (is_admin_logged_in()) {
    audit_log('admin.logout', 'login', $_SESSION['admin']['username']);
}
unset($_SESSION['admin']);
session_write_close();
header('Location: login.php', true, 303);
exit;
