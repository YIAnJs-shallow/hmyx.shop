<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/coupon.php';
$userId=uid(); expire_orders($userId);
$productId=(int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$qty=$_POST['quantity'] ?? $_GET['quantity'] ?? 1;
$error=''; $lines=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_order'])) {
    try {
        $token=$_POST['checkout_token'] ?? '';
        if (!is_string($token) || empty($_SESSION['checkout_tokens'][$token])) throw new DomainException('结算页面已过期，请重新打开');
        $draft=$_SESSION['checkout_tokens'][$token];
        if ($draft['product_id']!==$productId || (string)$draft['quantity']!==(string)$qty) throw new DomainException('结算内容已改变，请重新打开');
        $ids=create_orders($userId,$productId,$qty,(int)($_POST['address_id'] ?? 0),$token,(int)($_POST['user_coupon_id'] ?? 0));
        redirect_to(count($ids)===1?'pay.php?order_id='.$ids[0]:'my_orders.php?status=pending','订单已创建，请在 3 小时内付款。');
    } catch (Throwable $e) { if (!($e instanceof DomainException)) error_log($e); $error=$e instanceof DomainException?$e->getMessage():'下单失败，请稍后重试'; }
}
try { $lines=checkout_lines($userId,$productId,$qty); } catch (DomainException $e) { $error=$e->getMessage(); }
$grandTotal=0; foreach ($lines as $line) $grandTotal+=$line['subtotal_cents'];
$availableCoupons=$lines?coupons_for_user($userId,$grandTotal):[];
$selectedCouponId=0;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach ($availableCoupons as $c) if ((int)$c['id']===(int)($_POST['user_coupon_id'] ?? 0)) $selectedCouponId=(int)$c['id'];
}
$selectedCoupon=null;
foreach ($availableCoupons as $c) if ((int)$c['id']===$selectedCouponId) $selectedCoupon=$c;
$payable=$grandTotal-($selectedCoupon?coupon_preview($selectedCoupon,$grandTotal):0);
$addresses=rows('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC',[$userId]);
$token=bin2hex(random_bytes(24));
$_SESSION['checkout_tokens'][$token]=['product_id'=>$productId,'quantity'=>$qty];
if (count($_SESSION['checkout_tokens'])>20) array_shift($_SESSION['checkout_tokens']);
page_start('确认订单');
if ($error) echo '<p class="notice error" role="alert">'.h($error).'</p>';
?>
<form method="post"><?=csrf_field()?>
<input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="quantity" value="<?=h($qty)?>"><input type="hidden" name="checkout_token" value="<?=h($token)?>">
<section class="card"><h2>收货地址</h2><a href="addresses.php">新增 / 管理地址</a>
<?php foreach($addresses as $i=>$a): ?><label><input type="radio" name="address_id" value="<?=(int)$a['id']?>" <?=$i===0?'checked':''?> required><?=h($a['receiver_name'])?> · <?=h($a['receiver_phone'])?><br><?=h($a['receiver_address'])?></label><?php endforeach; if (!$addresses) echo '<p>请先添加收货地址，再返回此页面刷新。</p>'; ?></section>
<section class="card"><h2>商品明细</h2><?php foreach($lines as $line): ?><p><?=h($line['title'])?> × <?=(int)$line['quantity']?> <strong>¥ <?=money($line['subtotal_cents'])?></strong></p><?php endforeach; ?>
<?php if ($availableCoupons): ?><hr class="divider"><label>使用优惠券：<select name="user_coupon_id"><option value="0">不使用</option><?php foreach($availableCoupons as $c): ?><option value="<?=(int)$c['id']?>" <?=(int)$c['id']===$selectedCouponId?'selected':''?>><?=h($c['title'])?>（立减 ¥<?=money((int)$c['discount_cents'])?><?=((int)$c['min_spend_cents']>0?'，满 ¥'.money((int)$c['min_spend_cents']):'')?>）</option><?php endforeach; ?></select></label>
<?php elseif ($lines): ?><p class="muted">暂无可用优惠券，<a href="my_coupons.php">去领券</a></p><?php endif; ?>
<hr class="divider"><p>商品合计 <strong>¥ <?=money($grandTotal)?></strong></p><p>优惠券优惠 <strong>- ¥ <?=money($grandTotal-$payable)?></strong></p><p>实付 <strong class="price">¥ <?=money($payable)?></strong></p><p class="muted">当前商品免运费；不同店铺分别下单、支付和发货。未付款订单 3 小时后关闭。</p><button name="submit_order" value="1" <?=(!$addresses||!$lines)?'disabled':''?>>提交订单</button></section></form>
<?php page_end(); ?>
