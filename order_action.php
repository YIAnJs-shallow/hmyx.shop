<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: POST'); exit('请通过页面按钮提交操作'); }
$action=$_POST['action'] ?? '';
if ($action==='checkout_cart') redirect_to('checkout.php');
$id=(int)($_POST['order_id'] ?? 0);
$target=in_array($action,['ship','refund_approve','refund_reject','after_sale_approve','after_sale_reject','after_sale_receive'],true)?'admin_orders.php':'my_orders.php';
try {
    expire_orders($userId);
    change_order($id,$userId,$action,$_POST);
    redirect_to($target,'操作成功');
} catch (Throwable $e) {
    if (!($e instanceof DomainException)) error_log($e);
    redirect_to($target,$e instanceof DomainException?$e->getMessage():'操作未完成，请刷新后重试（退款申请不可重复提交）');
}
