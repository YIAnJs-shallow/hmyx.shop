<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); expire_orders($userId);
$status=$_GET['status'] ?? 'all'; if($status==='unshipped') $status='paid';
$states=['all'=>'全部','pending'=>'待付款','paid'=>'待发货','shipped'=>'待收货','completed'=>'已完成','cancelled'=>'已取消'];
if(!isset($states[$status])) $status='all';
$page=max(1,(int)($_GET['page'] ?? 1)); $offset=($page-1)*10;
$where='o.user_id=?'; $params=[$userId]; if($status!=='all') { $where.=' AND o.status=?'; $params[]=$status; }
$count=one('SELECT COUNT(*) AS n FROM orders o WHERE '.$where,$params)['n'];
$orders=rows('SELECT o.*,s.shop_name,r.status AS refund_status,r.reason,a.status AS after_sale_status,a.reason AS after_sale_reason,a.return_express_company,a.return_express_no FROM orders o LEFT JOIN shops s ON s.id=o.shop_id LEFT JOIN refund_requests r ON r.order_id=o.id LEFT JOIN after_sale_requests a ON a.order_id=o.id WHERE '.$where.' ORDER BY o.id DESC LIMIT 10 OFFSET '.$offset,$params);
$unreadMessages=order_message_unread_counts(array_column($orders,'id'),'buyer');
page_start('我的订单');
echo '<nav class="tabs">'; foreach($states as $key=>$label) echo '<a class="'.($status===$key?'active':'').'" href="?status='.$key.'">'.$label.'</a>'; echo '</nav>';
if(!$orders) echo '<div class="card empty">暂无订单，<a href="index.php">去逛逛</a></div>';
foreach($orders as $order): $id=(int)$order['id'];
$items=rows('SELECT i.*,COALESCE(i.product_title,p.title,\'历史商品\') AS title,COALESCE(i.sku_image,i.product_image,p.image_url) AS image_url,r.id AS review_id,(f.id IS NOT NULL) AS has_followup,(o.completed_at IS NOT NULL AND o.completed_at >= NOW() - INTERVAL 30 DAY) AS can_followup FROM order_items i JOIN orders o ON o.id=i.order_id LEFT JOIN products p ON p.id=i.product_id LEFT JOIN reviews r ON r.order_item_id=i.id AND r.parent_id IS NULL LEFT JOIN reviews f ON f.parent_id=r.id WHERE i.order_id=?',[$id]);
?>
<section class="card"><div class="row"><div class="grow"><strong><?=h($order['shop_name'] ?? '平台 / 历史订单')?></strong><p class="muted"><?=h($order['order_no'])?> · <?=h($order['created_at'])?></p></div><span class="badge"><?=h((($order['status']==='pending' && (($order['pay_stage'] ?? 'full')==='deposit' && (float)$order['paid_cents']>0)) ? '待付尾款' : status_name($order['status'])))?></span></div>
<?php foreach($items as $item): ?><div class="row"><?php if($item['image_url']): ?><img class="thumb" src="<?=h($item['image_url'])?>" alt="<?=h($item['title'])?>"><?php endif; ?><div class="grow"><a href="detail.php?id=<?=(int)$item['product_id']?>"><?=h($item['title'])?></a><?= $item['sku_name'] ? '<p>规格：'.h($item['sku_name']).'</p>' : '' ?><p>¥ <?=h($item['price'])?> × <?=(int)$item['quantity']?></p></div>
<?php if($order['status']==='completed' && $item['product_id'] && !$item['review_id']): ?><a class="button" href="review.php?item_id=<?=(int)$item['id']?>">评价商品</a>
<?php elseif($item['review_id'] && !(int)$item['has_followup'] && (int)$item['can_followup']): ?><a class="button" href="review.php?item_id=<?=(int)$item['id']?>&amp;append=1">追加评价</a><span>已评价</span>
<?php elseif($item['review_id'] && (int)$item['has_followup']): ?><span>已评价（已追评）</span>
<?php elseif($item['review_id']): ?><span>已评价</span><?php endif; ?></div><?php endforeach; ?>
<?php if(!$items): ?><p class="muted">历史订单未保存商品明细。</p><?php endif; ?>
<p><?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?> · <?=h($order['receiver_address'])?></p><hr class="divider"><div class="row"><strong class="price grow">¥ <?=h($order['total_amount'])?></strong><div class="actions">
<?php if($order['status']==='pending'): $presaleAwaitBalance = ((($order['pay_stage'] ?? 'full')==='deposit') && (float)$order['paid_cents'] > 0); if($presaleAwaitBalance): ?>
<p class="muted">预售订单已付定金，尾款 ¥ <?=h(money((int)cents($order['total_amount'])-(int)round(((float)$order['paid_cents'])*100)))?>，须于 <?=h(date('Y-m-d H:i', strtotime($order['paid_at'])+7*86400))?> 前补齐。</p><a class="button" href="pay.php?order_id=<?=$id?>">补尾款</a>
<?php else: ?><p class="muted"><?php $remain=(int)ceil((strtotime($order['created_at'])+10800-time())/3600); echo $remain>0 ? '约 '.$remain.' 小时后自动关闭' : '保留已超时，订单即将自动关闭'; ?></p><a class="button" href="pay.php?order_id=<?=$id?>">去付款</a><?=action_button($id,'cancel','取消订单')?>
<?php endif; endif; ?>
<?php if(in_array($order['status'],['shipped','completed'],true)): ?><a href="express_detail.php?order_id=<?=$id?>">查看物流</a><?php endif; ?>
<?php if($order['status']==='shipped') echo action_button($id,'receive','确认已收货'); ?>
<a href="order_messages.php?order_id=<?=$id?>">订单留言<?php if(!empty($unreadMessages[$id])): ?>（<?=(int)$unreadMessages[$id]?> 条新留言）<?php endif; ?></a>
</div></div>
<?php if($order['after_sale_status']): ?><p class="notice">退货退款：<?=h(after_sale_status_name($order['after_sale_status']))?> · <?=h($order['after_sale_reason'])?><?php if($order['after_sale_status']==='rejected'): ?>，如仍有异议可<a href="order_messages.php?order_id=<?=$id?>">留言与商家沟通</a><?php endif; ?></p>
<?php if($order['after_sale_status']==='approved'): ?><?php if($order['return_express_no']): ?><p class="muted">退货物流已填写：<?=h($order['return_express_company'])?> · <?=h($order['return_express_no'])?></p><?php else: ?><details><summary>填写退货物流</summary><form method="post" action="order_action.php"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$id?>"><label>快递公司 <input name="return_express_company" maxlength="50" required></label><label>退货运单号 <input name="return_express_no" pattern="[A-Za-z0-9-]{5,100}" maxlength="100" required></label><button name="action" value="after_sale_ship">提交退货物流</button></form></details><?php endif; ?><?php endif; ?>
<?php elseif(in_array($order['status'],['shipped','completed'],true)): ?><details><summary>申请退货退款</summary><form method="post" action="order_action.php"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$id?>"><label>退货原因<textarea name="reason" minlength="2" maxlength="500" required></textarea></label><button name="action" value="after_sale">提交退货退款申请</button></form></details><?php endif; ?>
<?php if($order['refund_status']): ?><p class="notice">退款申请：<?=h(['pending'=>'待商家处理','approved'=>'已批准（演示退款）','rejected'=>'已拒绝'][$order['refund_status']])?> · <?=h($order['reason'])?><?php if($order['refund_status']==='rejected'): ?>，如仍有异议可<a href="order_messages.php?order_id=<?=$id?>">留言与商家沟通</a><?php endif; ?></p>
<?php elseif($order['status']==='paid' && $order['payment_method']==='demo'): ?><details><summary>申请未发货退款（演示）</summary><form method="post" action="order_action.php"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$id?>"><label>退款原因<textarea name="reason" minlength="2" maxlength="500" required></textarea></label><button name="action" value="refund">提交退款申请</button></form></details><?php endif; ?>
</section><?php endforeach; ?>
<nav class="tabs"><?php if($page>1): ?><a href="?status=<?=h($status)?>&page=<?=$page-1?>">上一页</a><?php endif; ?><span>第 <?=$page?> 页 · 共 <?=(int)$count?> 笔</span><?php if($page*10<$count): ?><a href="?status=<?=h($status)?>&page=<?=$page+1?>">下一页</a><?php endif; ?></nav>
<?php page_end(); ?>
