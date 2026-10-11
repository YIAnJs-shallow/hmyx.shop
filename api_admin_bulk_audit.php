<?php
// 旧接口已迁移至 admin/api_bulk_audit.php（保留跳转兼容）
http_response_code(301);
header('Location: admin/api_bulk_audit.php');
exit;
