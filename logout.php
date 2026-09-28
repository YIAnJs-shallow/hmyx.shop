<?php
require_once __DIR__ . '/lib/security.php';
// 清空所有 session
$_SESSION = array();
session_destroy();

// 退出后重定向回商城首页，而不是管理员登录页
header("Location: index.php");
exit();
?>