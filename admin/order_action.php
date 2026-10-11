<?php
// 后台订单操作处理器：权限映射 + 审计，复用 lib/shop.php 的 change_order 事务逻辑
require_once __DIR__ . '/inc/auth.php';
require_admin_login();
define('ADMIN_ORDER_CONTEXT', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('请通过页面按钮提交操作'); }

$action = $_POST['action'] ?? '';
$id = (int)($_POST['order_id'] ?? 0);

// 动作 -> 所需权限
$action_permissions = [
    'ship' => 'order.ship',
    'refund_approve' => 'order.refund',
    'refund_reject' => 'order.refund',
    'after_sale_approve' => 'order.refund',
    'after_sale_reject' => 'order.refund',
    'after_sale_receive' => 'order.refund',
];
if (!isset($action_permissions[$action])) { http_response_code(400); exit('不支持的操作'); }
require_permission($action_permissions[$action]);

$_SESSION['admin_notice'] = '';
try {
    expire_orders(null); // actor=0：后台管理员不是订单归属人，仅触发超时清理
    change_order($id, 0, $action, $_POST);
    $_SESSION['admin_notice'] = '操作成功';
} catch (Throwable $e) {
    if (!($e instanceof DomainException)) error_log($e);
    audit_log('order.' . $action, 'order', (string)$id, ['error' => $e->getMessage()], 'failure');
    $_SESSION['admin_notice'] = $e instanceof DomainException ? $e->getMessage() : '操作未完成，请刷新后重试';
}
header('Location: orders.php', true, 303);
exit;
