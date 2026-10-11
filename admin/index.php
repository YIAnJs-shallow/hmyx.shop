<?php
require_once __DIR__ . '/inc/auth.php';
require_admin_login();
foreach (['dashboard.view'=>'dashboard.php','product.view'=>'products.php','product.audit'=>'product_audit.php','order.view'=>'orders.php','user.view'=>'users.php','audit.view'=>'audit.php'] as $permission=>$url) { if(admin_has_permission($permission)) { header('Location: '.$url,true,303);exit; } }
http_response_code(403);echo '账号尚未分配业务权限，请联系超级管理员。';
exit;
