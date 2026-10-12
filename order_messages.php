<?php
// 订单留言页：买家（我的订单）与商户（订单发货与售后）共用
// 权限：仅该订单的买家或订单所属店铺的商户（服务端校验），管理员请使用后台
require_once __DIR__.'/lib/shop.php';
$userId=uid();
$orderId=(int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$order=one('SELECT * FROM orders WHERE id=?',[$orderId]);
$role=$order?order_message_role($order,$userId):null;
if($role===null) { http_response_code(403); exit('只有该订单的买家或商家可以查看留言'); }
$back='order_messages.php?order_id='.$orderId;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try { send_order_message($orderId,$userId,$_POST['content'] ?? ''); }
    catch (Throwable $e) {
        if (!($e instanceof DomainException)) error_log($e);
        redirect_to($back,$e instanceof DomainException?$e->getMessage():'留言未发送，请刷新后重试');
    }
    redirect_to($back,'留言已发送');
}
// 进入详情即标记对方留言为已读
order_message_mark_read($orderId,$role);
$messages=rows('SELECT * FROM order_messages WHERE order_id=? ORDER BY id',[$orderId]);
$labels=['buyer'=>'买家','merchant'=>'商家','admin'=>'平台管理员'];
$returnUrl=$role==='buyer'?'my_orders.php':'admin_orders.php';
page_start('订单留言');
echo '<p><a href="'.h($returnUrl).'">返回订单列表</a> · 订单号：'.h($order['order_no']).' · '.h(status_name($order['status'])).'</p>';
if(!$messages) echo '<section class="card empty">暂无留言，双方可在此就订单问题沟通。</section>';
foreach($messages as $message): ?>
<section class="card"><div class="row"><div class="grow"><strong><?=h($labels[$message['sender_role']] ?? $message['sender_role'])?><?=($message['sender_role']===$role?'（我）':'')?></strong><p class="muted"><?=h($message['created_at'])?></p></div></div><p><?=h($message['content'])?></p></section>
<?php endforeach; ?>
<section class="card"><form method="post" action="order_messages.php"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$orderId?>"><label>发表留言<textarea name="content" minlength="2" maxlength="500" required placeholder="2~500 字，与对方沟通订单问题"></textarea></label><button type="submit">发送留言</button></form></section>
<?php page_end(); ?>
