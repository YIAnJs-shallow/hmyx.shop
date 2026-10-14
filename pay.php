<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); expire_orders($userId);
$id=(int)($_GET['order_id'] ?? 0);
$order=one('SELECT * FROM orders WHERE id=? AND user_id=?',[$id,$userId]);
if (!$order) { http_response_code(404); page_start('订单不存在'); page_end(); exit; }
page_start('订单支付');
?>
<section class="card"><p>订单号 <?=h($order['order_no'])?></p><p>商品合计 ¥ <?=h($order['total_amount'])?><?php if ((float)$order['discount_amount']>0): ?> · 优惠券已减 ¥ <?=h($order['discount_amount'])?><?php endif; ?><?php if ((int)$order['points_discount_cents']>0): ?> · 积分抵扣 <?=money((int)$order['points_discount_cents'])?>（支付时扣减 <?=h((int)$order['points_paid'])?> 积分）<?php endif; ?></p><p class="price">实付 ¥ <?=money((int)cents($order['total_amount'])-(int)cents($order['discount_amount'])-(int)$order['points_discount_cents'])?></p><p><?=h(status_name($order['status']))?></p><p><?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?><br><?=h($order['receiver_address'])?></p>
<?php if ($order['status']==='pending'): ?>
<p id="reserve-line" data-remain="<?=(int)(strtotime($order['created_at'])+10800-time())?>">库存已为你保留 <span id="reserve-countdown">HH:MM:SS</span></p>
<p class="notice">当前未接入微信或支付宝真实支付。演示支付仅用于流程测试，不会扣款。</p>
<?php if (getenv('SHOP_DEMO_PAYMENT')==='1'): ?><?=action_button($id,'pay','确认演示支付（不扣款）')?><?php else: ?><p>演示支付未开启，请联系网站管理员。</p><?php endif; ?>
<?=action_button($id,'cancel','取消订单')?>
<?php endif; ?> <a href="my_orders.php">返回订单中心</a></section>
<?php if ($order['status']==='pending'): ?>
<script>
(function () {
    var remain = <?=(int)(strtotime($order['created_at'])+10800-time())?>;
    var line = document.getElementById('reserve-line');
    var span = document.getElementById('reserve-countdown');
    function render() {
        if (remain <= 0) {
            line.textContent = '保留已超时，订单即将自动关闭';
            document.querySelectorAll('button[name="action"][value="pay"]').forEach(function (b) { b.disabled = true; });
            clearInterval(timer);
            return;
        }
        var h = Math.floor(remain / 3600), m = Math.floor(remain % 3600 / 60), s = remain % 60;
        span.textContent = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    render();
    var timer = setInterval(function () { remain--; render(); }, 1000);
})();
</script>
<?php endif; ?>
<?php page_end(); ?>
