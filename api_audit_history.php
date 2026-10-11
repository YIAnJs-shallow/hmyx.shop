<?php
// 旧接口已迁移至 admin/api_audit_history.php（保留跳转兼容）
http_response_code(301);
header('Location: admin/api_audit_history.php');
exit;
