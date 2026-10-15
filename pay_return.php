<?php
// 支付宝沙箱支付同步回跳：先验签，再主动查单确认（不以 return 参数为准），复用 change_order('pay') 支付成功分支
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/alipay.php';
$userId=uid(); expire_orders($userId);
$result='fail'; $reason='验签失败';
$order=null;
if (alipay_verify($_GET)) {
    $outTradeNo = (string)($_GET['out_trade_no'] ?? '');
    $order = $outTradeNo !== '' ? one('SELECT * FROM orders WHERE order_no=? AND user_id=?', [$outTradeNo, $userId]) : null;
    if ($order) {
        // 金额一律以数据库订单应付金额为准，不信任 return 参数中的 total_amount
        $tradeStatus = alipay_query_trade($order['order_no']);
        if ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED') {
            $result='ok'; $reason='';
            if ($order['status'] === 'pending') {
                try { change_order((int)$order['id'], (int)$order['user_id'], 'pay', ['payment_method' => 'alipay_sandbox']); }
                catch (DomainException $e) { $result='fail'; $reason='支付未完成：'.$e->getMessage(); }
            }
        } else {
            $result='pending'; $reason='支付未完成（当前状态：'.($tradeStatus ?? '查询失败').'），订单保持待付款';
        }
    } else {
        $reason='验签失败：未找到属于当前账号的订单';
    }
}
$display = $order ?? ($userId ? one('SELECT * FROM orders WHERE order_no=? AND user_id=?', [(string)($_GET['out_trade_no'] ?? ''), $userId]) : null);
page_start('支付结果');
echo '<section class="card">';
if ($display) {
    $payable = (int)cents($display['total_amount']) - (int)cents($display['discount_amount']) - (int)$display['points_discount_cents'] - (int)$display['member_discount_cents'];
    echo '<p>订单号 '.h($display['order_no']).'</p><p class="price">实付 ¥ '.money($payable).'</p>';
} else {
    echo '<p>订单号 '.h((string)($_GET['out_trade_no'] ?? '未知')).'</p>';
}
if ($result==='ok') echo '<p>支付成功，订单已进入待发货状态。</p><a href="my_orders.php">查看我的订单</a>';
elseif ($result==='pending') echo '<p class="notice">'.h($reason).'</p><a href="pay.php?order_id='.(int)($display['id'] ?? 0).'">返回继续支付</a> <a href="my_orders.php">查看我的订单</a>';
else echo '<p class="notice">'.h($reason ?: '验签失败').'，数据未被修改。请从订单中心重新发起支付。</p><a href="my_orders.php">返回订单中心</a>';
echo '</section>';
page_end();
