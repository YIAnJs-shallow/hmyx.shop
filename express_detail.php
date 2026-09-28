<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); $order=one('SELECT * FROM orders WHERE id=?',[(int)($_GET['order_id'] ?? 0)]);
if(!$order || ((int)$order['user_id']!==$userId && !can_manage_order($order,$userId))) { http_response_code(404); page_start('订单不存在或无权查看'); page_end(); exit; }
page_start('物流与订单进度'); ?>
<section class="card"><h2><?=h(status_name($order['status']))?></h2><p>订单 <?=h($order['order_no'])?></p><p><?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?><br><?=h($order['receiver_address'])?></p><hr class="divider"><p>创建订单：<?=h($order['created_at'])?></p><?php if($order['paid_at']): ?><p>付款记录：<?=h($order['paid_at'])?> <?=$order['payment_method']==='demo'?'（演示支付）':''?></p><?php endif; ?><?php if($order['shipped_at']): ?><p>商家发货：<?=h($order['shipped_at'])?></p><p>快递公司：<?=h($order['express_company'])?><br>运单号：<?=h($order['express_no'])?></p><p class="muted">尚未接入快递实时查询，请使用以上运单号前往快递公司官方渠道查询。</p><?php else: ?><p>暂无发货记录。</p><?php endif; ?><?php if($order['completed_at']): ?><p>确认收货：<?=h($order['completed_at'])?></p><?php endif; ?></section><?php page_end(); ?>
