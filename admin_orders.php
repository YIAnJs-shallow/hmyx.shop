<?php
// 商户订单发货与售后页（前台登录态）
// 管理员订单管理已独立到 admin/orders.php（RBAC 权限体系）
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if (($_SESSION['role'] ?? '')==='admin') { http_response_code(301); header('Location: admin/orders.php'); exit; }
if (($_SESSION['role'] ?? '')!=='merchant') { http_response_code(403); exit('仅商家可访问，管理员请使用后台'); }
$status=$_GET['status'] ?? 'paid'; if($status==='unshipped') $status='paid';
$states=['all'=>'全部订单','pending'=>'待付款','paid'=>'待发货','shipped'=>'已发货','completed'=>'已完成','cancelled'=>'已取消']; if(!isset($states[$status])) $status='paid';
$where='EXISTS(SELECT 1 FROM shops s WHERE s.id=o.shop_id AND s.user_id=? AND s.status=1)'; $params=[$userId];
if($status!=='all') {$where.=' AND o.status=?'; $params[]=$status;}
$page=max(1,(int)($_GET['page'] ?? 1)); $offset=($page-1)*15;
$count=one('SELECT COUNT(*) AS n FROM orders o WHERE '.$where,$params)['n'];
$orders=rows('SELECT o.*,r.status AS refund_status,r.reason,a.status AS after_sale_status,a.reason AS after_sale_reason,a.return_express_company,a.return_express_no FROM orders o LEFT JOIN refund_requests r ON r.order_id=o.id LEFT JOIN after_sale_requests a ON a.order_id=o.id WHERE '.$where.' ORDER BY o.id DESC LIMIT 15 OFFSET '.$offset,$params);
$unreadMessages=order_message_unread_counts(array_column($orders,'id'),'merchant');
page_start('订单发货与售后');
echo '<p><a href="product_list.php">返回店铺管理</a></p><nav class="tabs">'; foreach($states as $key=>$label) echo '<a class="'.($status===$key?'active':'').'" href="?status='.$key.'">'.$label.'</a>'; echo '</nav>';
if(!$orders) echo '<section class="card empty">暂无符合条件的订单</section>';
foreach($orders as $order): $id=(int)$order['id']; ?>
<section class="card"><h2><?=h($order['order_no'])?> <span class="badge"><?=h(status_name($order['status']))?></span></h2><p class="price">¥ <?=h($order['total_amount'])?></p>
<?php foreach(rows('SELECT i.*,COALESCE(i.product_title,p.title,\'历史商品\') AS title FROM order_items i LEFT JOIN products p ON p.id=i.product_id WHERE i.order_id=?',[$id]) as $item): ?><p><?=h($item['title'])?> × <?=(int)$item['quantity']?></p><?php endforeach; ?>
<p>收货人：<?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?><br><?=h($order['receiver_address'])?></p>
<?php if($order['after_sale_status']==='pending'): ?><p class="notice">退货退款申请：<?=h($order['after_sale_reason'])?></p><?=action_button($id,'after_sale_approve','同意退货')?><?=action_button($id,'after_sale_reject','拒绝申请')?>
<?php elseif($order['after_sale_status']==='approved'): ?><p class="notice">退货退款：<?=h(after_sale_status_name($order['after_sale_status']))?> · <?=h($order['after_sale_reason'])?></p>
<?php if($order['return_express_no']): ?><p>退货物流：<?=h($order['return_express_company'])?> · <?=h($order['return_express_no'])?></p><?=action_button($id,'after_sale_receive','确认收货并处理退款')?><?php else: ?><p class="muted">等待买家寄回商品并填写退货物流</p><?php endif; ?>
<?php elseif($order['after_sale_status']): ?><p class="notice">退货退款：<?=h(after_sale_status_name($order['after_sale_status']))?> · <?=h($order['after_sale_reason'])?></p>
<?php endif; ?>
<?php if($order['refund_status']==='pending'): ?><p class="notice">退款原因：<?=h($order['reason'])?></p><?=action_button($id,'refund_approve','批准演示退款')?><?=action_button($id,'refund_reject','拒绝退款')?>
<?php elseif($order['status']==='paid'): ?><form class="row" method="post" action="order_action.php"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$id?>"><label>快递公司 <input name="express_company" maxlength="50" required></label><label>运单号 <input name="express_no" pattern="[A-Za-z0-9-]{5,100}" maxlength="100" required></label><button name="action" value="ship">确认发货</button></form><?php endif; ?>
<?php if($order['express_no']): ?><a href="express_detail.php?order_id=<?=$id?>"><?=h($order['express_company'])?> <?=h($order['express_no'])?></a><?php endif; ?>
<p><a href="order_messages.php?order_id=<?=$id?>">订单留言<?php if(!empty($unreadMessages[$id])): ?>（<?=(int)$unreadMessages[$id]?> 条新留言）<?php endif; ?></a></p></section>
<?php endforeach; ?><nav class="tabs"><?php if($page>1): ?><a href="?status=<?=h($status)?>&page=<?=$page-1?>">上一页</a><?php endif; ?><span>第 <?=$page?> 页 · 共 <?=(int)$count?> 笔</span><?php if($page*15<$count): ?><a href="?status=<?=h($status)?>&page=<?=$page+1?>">下一页</a><?php endif; ?></nav><?php page_end(); ?>
