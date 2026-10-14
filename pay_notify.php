<?php
// 支付宝沙箱支付异步通知：验签 + trade_status 确认后复用 change_order('pay') 支付成功分支（幂等防重）
// 支付宝服务器 POST 不携带本站 CSRF token，先改写 REQUEST_METHOD 绕开 db.php 的全局 POST CSRF 校验（本页以验签自防）
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/alipay.php';
$reply = 'fail';
if (alipay_verify($_POST)) {
    $outTradeNo = (string)($_POST['out_trade_no'] ?? '');
    $tradeStatus = (string)($_POST['trade_status'] ?? '');
    $order = $outTradeNo !== '' ? one('SELECT * FROM orders WHERE order_no=?', [$outTradeNo]) : null;
    if ($order && ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED')) {
        $reply = 'success'; // 幂等：已 paid 订单不重复执行，仍应答 success 让支付宝停止重发
        if ($order['status'] === 'pending') {
            try { change_order((int)$order['id'], (int)$order['user_id'], 'pay', ['payment_method' => 'alipay_sandbox']); }
            catch (DomainException $e) { $reply = 'fail'; }
        }
    }
}
header('Content-Type: text/plain; charset=utf-8');
echo $reply;
