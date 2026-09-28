<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); expire_orders($userId);
$id=(int)($_GET['order_id'] ?? 0);
$order=one('SELECT * FROM orders WHERE id=? AND user_id=?',[$id,$userId]);
if (!$order) { http_response_code(404); page_start('订单不存在'); page_end(); exit; }
page_start('订单支付');
?>
<section class="card"><p>订单号 <?=h($order['order_no'])?></p><p class="price">¥ <?=h($order['total_amount'])?></p><p><?=h(status_name($order['status']))?></p><p><?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?><br><?=h($order['receiver_address'])?></p>
<?php if ($order['status']==='pending'): ?>
<p class="notice">当前未接入微信或支付宝真实支付。演示支付仅用于流程测试，不会扣款。</p>
<?php if (getenv('SHOP_DEMO_PAYMENT')==='1'): ?><?=action_button($id,'pay','确认演示支付（不扣款）')?><?php else: ?><p>演示支付未开启，请联系网站管理员。</p><?php endif; ?>
<?=action_button($id,'cancel','取消订单')?>
<?php endif; ?> <a href="my_orders.php">返回订单中心</a></section>
<?php page_end(); ?>
