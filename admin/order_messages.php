<?php
// 后台订单留言页：具备订单查看权限的管理员可查看与发送留言
require __DIR__.'/inc/auth.php'; require_permission('order.view');
define('ADMIN_ORDER_CONTEXT', true);
$orderId=(int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$order=one('SELECT * FROM orders WHERE id=?',[$orderId]);
if(!$order || order_message_role($order,0)===null) { http_response_code(403); exit('无权查看该订单留言'); }
$back='order_messages.php?order_id='.$orderId;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try { send_order_message($orderId,0,$_POST['content'] ?? ''); $_SESSION['admin_notice']='留言已发送'; }
    catch (Throwable $e) {
        if (!($e instanceof DomainException)) error_log($e);
        audit_log('order.message','order',(string)$orderId,['error'=>$e->getMessage()],'failure');
        $_SESSION['admin_notice']=$e instanceof DomainException?$e->getMessage():'留言未发送，请刷新后重试';
    }
    header('Location: '.$back, true, 303); exit;
}
// 进入详情即标记买卖双方留言为已读
order_message_mark_read($orderId,'admin');
$messages=rows('SELECT * FROM order_messages WHERE order_id=? ORDER BY id',[$orderId]);
$labels=['buyer'=>'买家','merchant'=>'商家','admin'=>'平台管理员'];
$page_title='订单留言';
require __DIR__.'/inc/header.php';
echo '<p><a href="orders.php">返回订单列表</a> · 订单号：'.h($order['order_no']).' · '.h(status_name($order['status'])).'</p>';
if(!$messages) echo '<section class="card empty">暂无留言。</section>';
foreach($messages as $message): ?>
<section class="card"><div class="row"><div class="grow"><strong><?=h($labels[$message['sender_role']] ?? $message['sender_role'])?><?=($message['sender_role']==='admin'?'（我）':'')?></strong><p class="muted"><?=h($message['created_at'])?></p></div></div><p><?=h($message['content'])?></p></section>
<?php endforeach; ?>
<section class="card"><form method="post" action="order_messages.php"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$orderId?>"><label>以平台管理员身份留言<textarea name="content" minlength="2" maxlength="500" required placeholder="2~500 字"></textarea></label><button type="submit">发送留言</button></form></section>
<?php require __DIR__.'/inc/footer.php'; ?>
