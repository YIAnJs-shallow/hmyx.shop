<?php
require_once __DIR__.'/lib/shop.php';
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
        $ids=create_orders($userId,$productId,$qty,(int)($_POST['address_id'] ?? 0),$token);
        redirect_to(count($ids)===1?'pay.php?order_id='.$ids[0]:'my_orders.php?status=pending','订单已创建，请在 3 小时内付款。');
    } catch (Throwable $e) { if (!($e instanceof DomainException)) error_log($e); $error=$e instanceof DomainException?$e->getMessage():'下单失败，请稍后重试'; }
}
try { $lines=checkout_lines($userId,$productId,$qty); } catch (DomainException $e) { $error=$e->getMessage(); }
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
<section class="card"><h2>商品明细</h2><?php $total=0; foreach($lines as $line): $total+=$line['subtotal_cents']; ?><p><?=h($line['title'])?> × <?=(int)$line['quantity']?> <strong>¥ <?=money($line['subtotal_cents'])?></strong></p><?php endforeach; ?><hr class="divider"><p>商品合计 / 实付 <strong class="price">¥ <?=money($total)?></strong></p><p class="muted">当前商品免运费；不同店铺分别下单、支付和发货。未付款订单 3 小时后关闭。</p><button name="submit_order" value="1" <?=(!$addresses||!$lines)?'disabled':''?>>提交订单</button></section></form>
<?php page_end(); ?>
