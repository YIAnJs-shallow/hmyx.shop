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
        // 信任经签名验证的主动查单结果；金额再与数据库当前阶段应付比对，不信任 return 参数
        $trade = alipay_query_trade($order['order_no']);
        $tradeStatus = $trade['trade_status'] ?? null;
        if ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED') {
            if (!order_payment_amount_matches($order, $trade['total_amount'] ?? null)) {
                $result = 'fail';
                $reason = '支付金额与订单应付不一致，请暂停重复付款并联系管理员核查';
                error_log('pay_return amount mismatch order_no=' . $order['order_no'] . ' user_id=' . $order['user_id'] . ' queried=' . var_export($trade['total_amount'] ?? null, true) . ' payable_cents=' . order_payable_cents($order));
            } else {
                $result='ok'; $reason='';
                if ($order['status'] === 'pending') {
                    try { change_order((int)$order['id'], (int)$order['user_id'], 'pay', ['payment_method' => 'alipay_sandbox']); }
                    catch (DomainException $e) { $result='fail'; $reason='支付未完成：'.$e->getMessage(); }
                }
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
    $payable = order_payable_cents($display);
    echo '<p>订单号 '.h($display['order_no']).'</p><p class="price">实付 ¥ '.money($payable).'</p>';
} else {
    echo '<p>订单号 '.h((string)($_GET['out_trade_no'] ?? '未知')).'</p>';
}
if ($result==='ok') echo '<p>支付成功，订单已进入待发货状态。</p><a href="my_orders.php">查看我的订单</a>';
elseif ($result==='pending') echo '<p class="notice">'.h($reason).'</p><a href="pay.php?order_id='.(int)($display['id'] ?? 0).'">返回继续支付</a> <a href="my_orders.php">查看我的订单</a>';
else echo '<p class="notice">'.h($reason ?: '验签失败').'，数据未被修改。请从订单中心重新发起支付。</p><a href="my_orders.php">返回订单中心</a>';
echo '</section>';
page_end();
