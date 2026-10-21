<?php
// 商户订单发货与售后页（前台登录态）
// 管理员订单管理已独立到 admin/orders.php（RBAC 权限体系）
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if (($_SESSION['role'] ?? '')==='admin') { http_response_code(301); header('Location: admin/orders.php'); exit; }
if (($_SESSION['role'] ?? '')!=='merchant') { http_response_code(403); exit('仅商家可访问，管理员请使用后台'); }
$status=$_GET['status'] ?? 'paid'; if($status==='unshipped') $status='paid';
$states=['all'=>'全部订单','pending'=>'待付款','paid'=>'待发货','shipped'=>'已发货','completed'=>'已完成','cancelled'=>'已取消']; if(!isset($states[$status])) $status='paid';
$mcShop=one('SELECT id, shop_name FROM shops WHERE user_id=? AND status=1',[$userId]);
$mcShopId=(int)($mcShop['id'] ?? 0);
$mcToday=(int)one("SELECT COUNT(*) AS n FROM orders WHERE shop_id=? AND created_at>=CURDATE()",[$mcShopId])['n'];
$mcUnship=(int)one("SELECT COUNT(*) AS n FROM orders WHERE shop_id=? AND status='paid'",[$mcShopId])['n'];
$mcAfter=(int)one("SELECT COUNT(*) AS n FROM after_sale_requests a JOIN orders o ON o.id=a.order_id WHERE o.shop_id=? AND a.status='pending'",[$mcShopId])['n']
       +(int)one("SELECT COUNT(*) AS n FROM refund_requests r JOIN orders o ON o.id=r.order_id WHERE o.shop_id=? AND r.status='pending'",[$mcShopId])['n'];
$mcReply=(int)one("SELECT COUNT(*) AS n FROM reviews r JOIN products p ON p.id=r.product_id WHERE p.shop_id=? AND r.parent_id IS NULL AND r.reply_content IS NULL",[$mcShopId])['n'];
// 售前咨询：本店商品未答买家提问数（E-lite）；POST 回复处理
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['chat_action'] ?? '') === 'reply') {
    try { product_chat_reply((int)($_POST['chat_id'] ?? 0), $userId, $_POST['chat_content'] ?? ''); }
    catch (Throwable $e) { if (!($e instanceof DomainException)) error_log($e); redirect_to('admin_orders.php', $e instanceof DomainException ? $e->getMessage() : '回复未发布，请重试'); }
    redirect_to('admin_orders.php', '回复已发布。');
}
$mcChat = product_chat_pending_count($mcShopId);
$mcChatList = $mcChat ? rows('SELECT pc.id, pc.content, pc.created_at, p.id AS pid, p.title
    FROM product_chats pc JOIN products p ON p.id = pc.product_id
    WHERE p.shop_id=? AND pc.sender_role="buyer"
      AND NOT EXISTS (SELECT 1 FROM product_chats ans WHERE ans.product_id = pc.product_id AND ans.sender_role = "merchant" AND ans.id > pc.id)
    ORDER BY pc.id DESC LIMIT 20', [$mcShopId]) : [];
$where='EXISTS(SELECT 1 FROM shops s WHERE s.id=o.shop_id AND s.user_id=? AND s.status=1)'; $params=[$userId];
if($status!=='all') {$where.=' AND o.status=?'; $params[]=$status;}
$page=max(1,(int)($_GET['page'] ?? 1)); $offset=($page-1)*15;
$count=one('SELECT COUNT(*) AS n FROM orders o WHERE '.$where,$params)['n'];
$orders=rows('SELECT o.*,r.status AS refund_status,r.reason,a.status AS after_sale_status,a.reason AS after_sale_reason,a.return_express_company,a.return_express_no FROM orders o LEFT JOIN refund_requests r ON r.order_id=o.id LEFT JOIN after_sale_requests a ON a.order_id=o.id WHERE '.$where.' ORDER BY o.id DESC LIMIT 15 OFFSET '.$offset,$params);
$unreadMessages=order_message_unread_counts(array_column($orders,'id'),'merchant');
page_start('商家工作台 · 订单发货与售后');
?>
<div class="mc-head">
  <div class="mc-head-top">
    <strong class="mc-title">🏪 商家工作台</strong>
    <span class="mc-shop"><?=h($mcShop['shop_name'] ?? '我的店铺')?></span>
    <a class="mc-link" href="product_list.php">返回店铺管理 ›</a>
  </div>
  <div class="mc-stats">
    <div class="mc-stat"><b><?=(int)$mcToday?></b><span>今日新订单</span></div>
    <div class="mc-stat<?=(int)$mcUnship>0?' mc-hot':''?>"><b><?=(int)$mcUnship?></b><span>待发货</span></div>
    <div class="mc-stat<?=(int)$mcAfter>0?' mc-hot':''?>"><b><?=(int)$mcAfter?></b><span>处理中售后</span></div>
    <div class="mc-stat<?=(int)$mcReply>0?' mc-hot':''?>"><b><?=(int)$mcReply?></b><span>待回复评价</span></div>
    <div class="mc-stat<?=$mcChat>0?' mc-hot':''?>"><b><?=$mcChat?></b><span>待回复咨询</span></div>
  </div>
</div>
<?php if ($mcChatList): ?>
<section class="card">
  <h2>待回复商品咨询</h2>
  <?php foreach ($mcChatList as $mcc): ?>
  <div class="row" style="align-items:flex-start;">
    <div class="grow">
      <a href="detail.php?id=<?=(int)$mcc['pid'] ?>"><?= h(mb_substr($mcc['title'], 0, 24)) ?></a>
      <p class="muted small" style="margin:2px 0;"><?= h($mcc['created_at']) ?></p>
      <p style="margin:4px 0;"><?= h($mcc['content']) ?></p>
    </div>
    <form method="post" class="row" style="align-items:flex-end;"><?= csrf_field() ?><input type="hidden" name="chat_action" value="reply"><input type="hidden" name="chat_id" value="<?=(int)$mcc['id'] ?>"><label>回复（2 至 500 字）<input name="chat_content" minlength="2" maxlength="500" required></label><button>回复</button></form>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php
echo '<nav class="tabs">'; foreach($states as $key=>$label) echo '<a class="'.($status===$key?'active':'').'" href="?status='.$key.'">'.$label.'</a>'; echo '</nav>';
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
<?php endforeach; ?><nav class="tabs"><?php if($page>1): ?><a href="?status=<?=h($status)?>&page=<?=$page-1?>">上一页</a><?php endif; ?><span>第 <?=$page?> 页 · 共 <?=(int)$count?> 笔</span><?php if($page*15<$count): ?><a href="?status=<?=h($status)?>&page=<?=$page+1?>">下一页</a><?php endif; ?></nav>
<?php // 本店商品评价：每条首评仅可回复一次（已回复的只读展示）
$shopReviews=rows('SELECT r.id,r.rating,r.content,r.reply_content,r.replied_at,r.created_at,u.username,p.title FROM reviews r JOIN products p ON p.id=r.product_id JOIN shops s ON s.id=p.shop_id JOIN users u ON u.id=r.user_id WHERE s.user_id=? AND s.status=1 AND r.parent_id IS NULL ORDER BY r.id DESC LIMIT 20',[$userId]);
if($shopReviews): ?><section class="card"><h2>本店商品评价</h2>
<?php foreach($shopReviews as $r): ?><article><strong><?=h($r['title'])?> · <?=h(mb_substr($r['username'],0,1))?>*** · <?=(int)$r['rating']?> 星</strong><p class="product-copy"><?=h($r['content'])?></p><small><?=h($r['created_at'])?></small>
<?php if($r['reply_content']): ?><p class="notice">已回复：<?=h($r['reply_content'])?>（<?=h($r['replied_at'])?>）</p>
<?php else: ?><form method="post" action="review_reply.php" class="row"><?=csrf_field()?><input type="hidden" name="review_id" value="<?=(int)$r['id']?>"><label>回复（2 至 500 字）<input name="reply" minlength="2" maxlength="500" required></label><button>回复评价</button></form><?php endif; ?>
<hr class="divider"></article><?php endforeach; ?>
</section><?php endif; ?>
<?php page_end(); ?>
