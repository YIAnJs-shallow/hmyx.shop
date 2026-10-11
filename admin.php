<?php
// 旧后台入口已迁移至 admin/ 目录（保留跳转兼容旧书签）
http_response_code(301);
header('Location: admin/products.php');
exit;
