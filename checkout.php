<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/coupon.php';
$userId=uid(); expire_orders($userId);
$productId=(int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$qty=$_POST['quantity'] ?? $_GET['quantity'] ?? 1;
$skuId=(int)($_POST['sku_id'] ?? $_GET['sku_id'] ?? 0);
$groupBuyId=(int)($_POST['group_buy_id'] ?? $_GET['group_buy_id'] ?? 0);
$error=''; $lines=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_order'])) {
    try {
        $token=$_POST['checkout_token'] ?? '';
        if (!is_string($token) || empty($_SESSION['checkout_tokens'][$token])) throw new DomainException('结算页面已过期，请重新打开');
        $draft=$_SESSION['checkout_tokens'][$token];
        if ($draft['product_id']!==$productId || (string)$draft['quantity']!==(string)$qty || (int)$draft['sku_id']!==$skuId || (int)($draft['group_buy_id'] ?? 0)!==$groupBuyId) throw new DomainException('结算内容已改变，请重新打开');
        $ids=create_orders($userId,$productId,$qty,(int)($_POST['address_id'] ?? 0),$token,(int)($_POST['user_coupon_id'] ?? 0),$skuId,(int)($_POST['use_points'] ?? 0)===1?1:0,$groupBuyId);
        redirect_to(count($ids)===1?'pay.php?order_id='.$ids[0]:'my_orders.php?status=pending','订单已创建，请在 3 小时内付款。');
    } catch (Throwable $e) { if (!($e instanceof DomainException)) error_log($e); $error=$e instanceof DomainException?$e->getMessage():'下单失败，请稍后重试'; }
}
try { $lines=checkout_lines($userId,$productId,$qty,false,$skuId); } catch (DomainException $e) { $error=$e->getMessage(); }
// 拼团结算：校验活动生效且属于该商品，团购价覆盖展示行（金额整数分；拼团订单不支持优惠券与积分）
$groupBuy=null;
if ($groupBuyId) {
    $groupBuy=group_buy_active($productId);
    if (!$groupBuy || (int)$groupBuy['id']!==$groupBuyId) { $error='拼团活动已结束，请返回商品页重新下单'; $lines=[]; }
    elseif ($lines) {
        $lines[0]['price']=money((int)$groupBuy['price_cents']);
        $lines[0]['subtotal_cents']=(int)$groupBuy['price_cents'];
    }
}
$grandTotal=0; foreach ($lines as $line) $grandTotal+=$line['subtotal_cents'];
// 会员等级折扣：先于优惠券计算（叠加顺序 会员折扣 → 券 → 积分），券门槛以折后小计为基数
$memberPercent=level_discount_percent($userId);
$memberDiscountCents=$memberPercent<100?(int)round($grandTotal*(100-$memberPercent)/100):0;
$couponBaseTotal=$grandTotal-$memberDiscountCents;
$availableCoupons=($lines && !$groupBuyId)?coupons_for_user($userId,$couponBaseTotal):[];
$selectedCouponId=0;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach ($availableCoupons as $c) if ((int)$c['id']===(int)($_POST['user_coupon_id'] ?? 0)) $selectedCouponId=(int)$c['id'];
}
$selectedCoupon=null;
foreach ($availableCoupons as $c) if ((int)$c['id']===$selectedCouponId) $selectedCoupon=$c;
$couponDiscountCents=$selectedCoupon?coupon_preview($selectedCoupon,$couponBaseTotal):0;
$payable=max(0,$grandTotal-$memberDiscountCents-$couponDiscountCents);
// 积分抵现预览：100 积分 = 1 元，单笔最多抵应付金额的 10%（向下取整到分），受当前余额限制
$availablePoints=(!$groupBuyId)?(int)(one('SELECT points FROM users WHERE id=?',[$userId])['points'] ?? 0):0;
$pointsDeductible=min(intdiv($payable,10),$availablePoints);
$addresses=rows('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC',[$userId]);
$token=bin2hex(random_bytes(24));
$_SESSION['checkout_tokens'][$token]=['product_id'=>$productId,'quantity'=>$qty,'sku_id'=>$skuId,'group_buy_id'=>$groupBuyId];
if (count($_SESSION['checkout_tokens'])>20) array_shift($_SESSION['checkout_tokens']);
page_start('确认订单');
if ($error) echo '<p class="notice error" role="alert">'.h($error).'</p>';
?>
<form method="post"><?=csrf_field()?>
<input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="quantity" value="<?=h($qty)?>"><input type="hidden" name="sku_id" value="<?=$skuId?>"><input type="hidden" name="checkout_token" value="<?=h($token)?>"><?php if ($groupBuyId): ?><input type="hidden" name="group_buy_id" value="<?=$groupBuyId?>"><?php endif; ?>
<section class="card"><h2>收货地址</h2><a href="addresses.php">新增 / 管理地址</a>
<?php foreach($addresses as $i=>$a): ?><label><input type="radio" name="address_id" value="<?=(int)$a['id']?>" <?=$i===0?'checked':''?> required><?=h($a['receiver_name'])?> · <?=h($a['receiver_phone'])?><br><?=h($a['receiver_address'])?></label><?php endforeach; if (!$addresses) echo '<p>请先添加收货地址，再返回此页面刷新。</p>'; ?></section>
<section class="card"><h2>商品明细</h2><?php foreach($lines as $line): ?><p><?=h($line['title'])?><?= $line['sku_name'] ? '（'.h($line['sku_name']).'）' : '' ?> × <?=(int)$line['quantity']?> <strong>¥ <?=money($line['subtotal_cents'])?></strong></p><?php endforeach; ?>
<?php if ($groupBuyId && $lines): ?><p class="notice">当前为拼团订单：<?=((int)$groupBuy['size'])?> 人成团，团购价单件 ¥ <?=money((int)$groupBuy['price_cents'])?>；未成团前商家不发货，超时未成团将自动退款。拼团订单暂不支持优惠券与积分抵扣。</p><?php endif; ?>
<?php if ($availableCoupons): ?><hr class="divider"><label>使用优惠券：<select name="user_coupon_id"><option value="0">不使用</option><?php foreach($availableCoupons as $c): ?><option value="<?=(int)$c['id']?>" <?=(int)$c['id']===$selectedCouponId?'selected':''?>><?=h($c['title'])?>（立减 ¥<?=money((int)$c['discount_cents'])?><?=((int)$c['min_spend_cents']>0?'，满 ¥'.money((int)$c['min_spend_cents']):'')?>）</option><?php endforeach; ?></select></label>
<?php elseif ($lines): ?><p class="muted">暂无可用优惠券，<a href="my_coupons.php">去领券</a></p><?php endif; ?>
<?php if ($availablePoints>0 && $payable>0): ?><hr class="divider"><label><input type="checkbox" name="use_points" value="1" <?=(int)($_POST['use_points'] ?? 0)===1?'checked':''?>> 使用积分抵扣（可用积分 <strong><?=(int)$availablePoints?></strong>，本次最多可抵 <strong>¥ <?=money($pointsDeductible)?></strong>，上限为应付金额的 10%，1 元 = 100 积分）</label><?php endif; ?>
<hr class="divider"><p>商品合计 <strong>¥ <?=money($grandTotal)?></strong></p><?php if ($memberDiscountCents>0): ?><p>会员折扣 <strong>- ¥ <?=money($memberDiscountCents)?></strong></p><?php endif; ?><p>优惠券优惠 <strong>- ¥ <?=money($couponDiscountCents)?></strong></p><p>实付 <strong class="price">¥ <?=money($payable)?></strong></p><p class="muted">当前商品免运费；不同店铺分别下单、支付和发货。未付款订单 3 小时后关闭。积分抵扣在支付成功时才扣减，余额不足自动按无抵扣支付。</p><button name="submit_order" value="1" <?=(!$addresses||!$lines)?'disabled':''?>>提交订单</button></section></form>
<?php page_end(); ?>
