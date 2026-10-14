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
echo '<div id="msg-list">';
if(!$messages) echo '<section class="card empty">暂无留言，双方可在此就订单问题沟通。</section>';
foreach($messages as $message): ?>
<section class="card" data-mid="<?= (int)$message['id'] ?>"><div class="row"><div class="grow"><strong><?=h($labels[$message['sender_role']] ?? $message['sender_role'])?><?=($message['sender_role']===$role?'（我）':'')?></strong><p class="muted"><?=h($message['created_at'])?></p></div></div><p><?=h($message['content'])?></p></section>
<?php endforeach;
echo '</div>'; ?>
<section class="card"><form method="post" action="order_messages.php" id="msg-form"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=$orderId?>"><label>发表留言<textarea name="content" minlength="2" maxlength="500" required placeholder="2~500 字，与对方沟通订单问题"></textarea></label><button type="submit">发送留言</button></form></section>
<script>
// 准实时对话：30 秒轮询增量拉取新留言（仅本订单参与方可达此页，服务端同样校验）
(function () {
    var list = document.getElementById('msg-list');
    if (!list) return;
    var orderId = <?= (int)$orderId ?>;
    var myRole = <?= json_encode($role) ?>;
    var labels = { buyer: '买家', merchant: '商家', admin: '平台管理员' };
    var maxId = 0;
    Array.prototype.forEach.call(list.querySelectorAll('[data-mid]'), function (el) {
        var id = parseInt(el.getAttribute('data-mid'), 10) || 0;
        if (id > maxId) maxId = id;
    });
    // 新留言全部用 textContent 插入（防 XSS），追加时不改变滚动位置
    function appendMessage(m) {
        var emptyTip = list.querySelector('.empty');
        if (emptyTip) emptyTip.parentNode.removeChild(emptyTip);
        var sec = document.createElement('section');
        sec.className = 'card';
        sec.setAttribute('data-mid', m.id || 0);
        var head = document.createElement('div'); head.className = 'row';
        var grow = document.createElement('div'); grow.className = 'grow';
        var who = document.createElement('strong');
        who.textContent = (labels[m.sender_role] || m.sender_role) + (m.sender_role === myRole ? '（我）' : '');
        var when = document.createElement('p'); when.className = 'muted'; when.textContent = m.created_at;
        grow.appendChild(who); grow.appendChild(when); head.appendChild(grow);
        var body = document.createElement('p'); body.textContent = m.content;
        sec.appendChild(head); sec.appendChild(body);
        list.appendChild(sec);
    }
    function poll() {
        // 页面隐藏时暂停轮询
        if (document.hidden) return;
        fetch('api_order_messages.php?order_id=' + orderId + '&after_id=' + maxId)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) return;
                data.messages.forEach(function (m) {
                    if (m.id > maxId) { appendMessage(m); maxId = m.id; }
                });
            })
            .catch(function () {});
    }
    setInterval(poll, 30000);
    // 恢复可见立即拉一次
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    // 发送留言后立即本地追加（占位 id 0，不推进 maxId，避免跳过服务端真实自增 id），随后表单照常提交整页刷新
    var form = document.getElementById('msg-form');
    form.addEventListener('submit', function () {
        var textarea = form.querySelector('textarea[name="content"]');
        var content = textarea.value.trim();
        if (content.length < 2) return;
        appendMessage({ id: 0, sender_role: myRole, content: content, created_at: '刚刚' });
    });
})();
</script>
<?php page_end(); ?>
