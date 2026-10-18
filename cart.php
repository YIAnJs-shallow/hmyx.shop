<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/coupon.php';
$userId=uid();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $conn->begin_transaction();
    try {
        one('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
        $action=$_POST['action'] ?? '';
        if ($action==='add') {
            $id=(int)($_POST['product_id'] ?? 0); $qty=quantity($_POST['quantity'] ?? 1); $skuId=(int)($_POST['sku_id'] ?? 0);
            $p=one("SELECT * FROM products WHERE id=? AND status=1 AND audit_status='approved'",[$id]);
            if (!$p) throw new DomainException('商品已下架或不存在');
            if ((int)($p['presale'] ?? 0) === 1) throw new DomainException('预售商品请从详情页立即购买支付定金');
            $skus=product_active_skus($id);
            if ($skus) {
                $found=null;
                foreach ($skus as $sku) if ((int)$sku['id']===$skuId) $found=$sku;
                if (!$found) throw new DomainException('请选择有效的商品规格');
                $stock=(int)$found['stock'];
            } else {
                if ($skuId) throw new DomainException('商品规格已失效，请重新选择');
                $stock=(int)$p['stock'];
            }
            $existing=one('SELECT COALESCE(SUM(quantity),0) AS qty FROM cart WHERE user_id=? AND product_id=? AND sku_id=?',[$userId,$id,$skuId]);
            $qty=quantity($qty+(int)$existing['qty']);
            if ($qty>$stock) throw new DomainException('加入数量超过库存');
            query('DELETE FROM cart WHERE user_id=? AND product_id=? AND sku_id=?',[$userId,$id,$skuId]);
            query('INSERT INTO cart(user_id,product_id,quantity,sku_id) VALUES(?,?,?,?)',[$userId,$id,$qty,$skuId]);
        } elseif ($action==='update') {
            $id=(int)($_POST['cart_id'] ?? 0); $qty=quantity($_POST['quantity'] ?? 1);
            $item=one('SELECT c.sku_id,p.title,p.stock AS pstock,s.stock AS sstock FROM cart c JOIN products p ON p.id=c.product_id LEFT JOIN product_skus s ON s.id=c.sku_id AND s.status=1 WHERE c.id=? AND c.user_id=?',[$id,$userId]);
            if (!$item) throw new DomainException('商品不存在或库存不足');
            $stock=$item['sku_id'] ? $item['sstock'] : $item['pstock'];
            if ($stock===null || $qty>(int)$stock) throw new DomainException('商品不存在或库存不足');
            query('UPDATE cart SET quantity=? WHERE id=? AND user_id=?',[$qty,$id,$userId]);
        } elseif ($action==='delete') {
            query('DELETE FROM cart WHERE id=? AND user_id=?',[(int)($_POST['cart_id'] ?? 0),$userId]);
        } else throw new DomainException('操作无效');
        $conn->commit(); redirect_to('cart.php','购物车已更新');
    } catch (Throwable $e) { $conn->rollback(); if (!($e instanceof DomainException)) error_log($e); redirect_to('cart.php',$e instanceof DomainException?$e->getMessage():'操作失败，请稍后重试'); }
}
$items=rows('SELECT c.id AS cart_id,c.quantity,c.sku_id,s.sku_name,s.price_cents AS sku_price_cents,s.stock AS sku_stock,p.* FROM cart c LEFT JOIN products p ON p.id=c.product_id LEFT JOIN product_skus s ON s.id=c.sku_id AND s.status=1 WHERE c.user_id=? ORDER BY c.id DESC',[$userId]);
page_start('我的购物车'); $total=0;
// 凑单提示用预合计（与下方逐项合计同一口径，不含运费）
$cartTotal=0;
foreach ($items as $item) {
    $unit=$item['sku_id']!==null && (int)$item['sku_id']>0 ? (int)($item['sku_price_cents'] ?? 0) : ($item['price']!==null ? cents($item['price']) : 0);
    $cartTotal+=$unit*(int)$item['quantity'];
}
$hint=$items ? coupon_threshold_hint($userId,$cartTotal) : null;
if (!$items) echo '<div class="card empty">购物车还是空的，<a href="index.php">去选购商品</a></div>';
if ($hint): ?>
<section class="card coupon-hint">
<?php if ($hint['met']): ?>
    <p>已满足满减条件，结算时可省 <strong class="price">¥ <?=money($hint['save_cents'])?></strong>（<?=h($hint['coupon']['title'])?>）。</p>
<?php else: ?>
    <p>还差 <strong class="price">¥ <?=money($hint['gap_cents'])?></strong> 可用「<?=h($hint['coupon']['title'])?>」，结算时立减 ¥ <?=money((int)$hint['coupon']['discount_cents'])?>。</p>
<?php endif; ?>
    <div class="coupon-progress"><div class="coupon-progress-bar" style="width:<?=min(100,(int)$hint['progress'])?>%"></div></div>
    <p class="muted">满额进度：¥ <?=money($cartTotal)?> / ¥ <?=money($hint['threshold_cents'])?></p>
</section>
<?php endif; ?>
<?php foreach ($items as $item):
$stock=$item['sku_id']!==null && (int)$item['sku_id']>0 ? (int)($item['sku_stock'] ?? -1) : (int)$item['stock'];
$valid=$item['id'] && $item['status']==1 && $item['audit_status']==='approved' && $stock>=0 && $item['quantity']<=$stock;
$unit=$item['sku_id']!==null && (int)$item['sku_id']>0 ? (int)($item['sku_price_cents'] ?? 0) : ($item['price']!==null ? cents($item['price']) : 0);
$subtotal=$unit*(int)$item['quantity']; $total+=$subtotal;
?>
<section class="card row">
<?php if ($item['image_url']): ?><img class="thumb" src="<?=h($item['image_url'])?>" alt="<?=h($item['title'])?>"><?php endif; ?>
<div class="grow"><h2><a href="detail.php?id=<?=(int)$item['id']?>"><?=h($item['title'] ?? '商品已删除')?></a></h2><?= (int)$item['sku_id']>0 ? '<p>规格：'.h($item['sku_name'] ?? '已失效').'</p>' : '' ?><p>¥ <?=money($subtotal)?> · 库存 <?=(int)max($stock,0)?></p><?php if (!$valid): ?><p class="error">商品不可结算，请调整数量或移除。</p><?php endif; ?></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="cart_id" value="<?=(int)$item['cart_id']?>"><label>数量 <input type="number" name="quantity" min="1" max="999" value="<?=(int)$item['quantity']?>"></label><button name="action" value="update">更新</button> <button name="action" value="delete" formnovalidate>移除</button></form>
</section>
<?php endforeach; if ($items): ?><section class="card row"><div class="grow">合计 <strong class="price">¥ <?=money($total)?></strong><p class="muted">提交时会再次核验价格和库存，不同店铺分别生成订单。</p></div><a class="button" href="checkout.php">选择地址并结算</a></section><?php endif; page_end(); ?>
