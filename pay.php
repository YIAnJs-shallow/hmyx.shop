<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/alipay.php';
$userId=uid(); expire_orders($userId);
$id=(int)($_GET['order_id'] ?? 0);
$order=one('SELECT * FROM orders WHERE id=? AND user_id=?',[$id,$userId]);
if (!$order) { http_response_code(404); page_start('订单不存在'); page_end(); exit; }
// 支付宝沙箱支付发起：POST + CSRF（db.php 已统一校验），仅本人 pending 订单可付
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? '')==='alipay') {
    if ($order['status']!=='pending') redirect_to('pay.php?order_id='.$id, $order['status']==='paid' ? '订单已支付，请勿重复付款' : '当前订单状态不可支付');
    $payable = (int)cents($order['total_amount']) - (int)cents($order['discount_amount']) - (int)$order['points_discount_cents'] - (int)$order['member_discount_cents'];
    $titles = rows('SELECT product_title AS title FROM order_items WHERE order_id=?', [$id]);
    $subject = mb_substr(implode('、', array_column($titles, 'title')), 0, 60, 'UTF-8');
    // 跳转前预检：沙箱网关存在 invalid-signature 平台侧抖动，换签重试拿到可用参数再渲染表单，
    // 保证浏览器提交的 URL 与预检通过的 URL 完全一致（timestamp/sign 均来自预检结果）
    $params = alipay_signed_request_with_retry([
        'app_id' => ALIPAY_SANDBOX_APP_ID,
        'method' => 'alipay.trade.page.pay',
        'return_url' => ALIPAY_SITE_BASE.'/pay_return.php',
        'notify_url' => ALIPAY_SITE_BASE.'/pay_notify.php',
        'charset' => 'utf-8',
        'version' => '1.0',
        'biz_content' => json_encode([
            'out_trade_no' => $order['order_no'],
            'total_amount' => money($payable),
            'subject' => $subject,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    if ($params === null) {
        page_start('支付宝沙箱网关不稳定');
        echo '<section class="card"><p>订单号 '.h($order['order_no']).' · 实付 ¥ '.money($payable).'</p>'
            .'<p class="notice">支付宝沙箱网关暂时不稳定，请稍后重试或使用演示支付。</p>'
            .'<form class="inline" method="post" action="pay.php?order_id='.$id.'">'.csrf_field().'<input type="hidden" name="action" value="alipay"><button>重试支付宝支付</button></form> '
            .action_button($id,'cancel','取消订单').' <a href="my_orders.php">返回订单中心</a></section>';
        page_end(); exit;
    }
    page_start('正在跳转支付宝沙箱收银台');
    echo '<section class="card"><p>订单号 '.h($order['order_no']).' · 实付 ¥ '.money($payable).'</p><p>正在跳转支付宝沙箱收银台，请稍候……</p><form id="alipay-form" method="get" action="'.h(alipay_gateway()).'">';
    foreach ($params as $k => $v) echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">';
    echo '</form><script>document.getElementById("alipay-form").submit();</script></section>';
    page_end(); exit;
}
page_start('订单支付');
?>
<section class="card"><p>订单号 <?=h($order['order_no'])?></p><p>商品合计 ¥ <?=h($order['total_amount'])?><?php if ((int)$order['member_discount_cents']>0): ?> · 会员折扣已减 ¥ <?=money((int)$order['member_discount_cents'])?><?php endif; ?><?php if ((float)$order['discount_amount']>0): ?> · 优惠券已减 ¥ <?=h($order['discount_amount'])?><?php endif; ?><?php if ((int)$order['points_discount_cents']>0): ?> · 积分抵扣 <?=money((int)$order['points_discount_cents'])?>（支付时扣减 <?=h((int)$order['points_paid'])?> 积分）<?php endif; ?></p><p class="price">实付 ¥ <?=money(max(0,(int)cents($order['total_amount'])-(int)cents($order['discount_amount'])-(int)$order['points_discount_cents']-(int)$order['member_discount_cents']))?></p><p><?=h(status_name($order['status']))?></p><p><?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?><br><?=h($order['receiver_address'])?></p>
<?php if ($order['status']==='pending'): ?>
<p id="reserve-line" data-remain="<?=(int)(strtotime($order['created_at'])+10800-time())?>">库存已为你保留 <span id="reserve-countdown">HH:MM:SS</span></p>
<p class="notice">当前未接入微信或支付宝真实支付。演示支付仅用于流程测试，不会扣款。</p>
<?php if (getenv('SHOP_DEMO_PAYMENT')==='1'): ?><?=action_button($id,'pay','确认演示支付（不扣款）')?><?php else: ?><p>演示支付未开启，请联系网站管理员。</p><?php endif; ?>
<?php if (defined('ALIPAY_SANDBOX_APP_ID') && ALIPAY_SANDBOX_APP_ID!==''): ?><form class="inline" method="post" action="pay.php?order_id=<?=$id?>"><?=csrf_field()?><input type="hidden" name="action" value="alipay"><button>支付宝沙箱支付</button></form><?php endif; ?>
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
