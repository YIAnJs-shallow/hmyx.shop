<?php
require_once __DIR__ . '/lib/security.php';
// 仅清除前台登录态（保留 $_SESSION['admin']，后台管理员登录不受影响）
unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']);
session_write_close();

// 退出后重定向回商城首页，而不是管理员登录页
header("Location: index.php");
exit();
?>