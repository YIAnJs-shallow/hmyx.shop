<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $conn->begin_transaction();
    try {
        one('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
        $action=$_POST['action'] ?? '';
        if ($action==='add') {
            $id=(int)($_POST['product_id'] ?? 0); $qty=quantity($_POST['quantity'] ?? 1);
            $p=one("SELECT * FROM products WHERE id=? AND status=1 AND audit_status='approved'",[$id]);
            if (!$p) throw new DomainException('商品已下架或不存在');
            $existing=one('SELECT COALESCE(SUM(quantity),0) AS qty FROM cart WHERE user_id=? AND product_id=?',[$userId,$id]);
            $qty=quantity($qty+(int)$existing['qty']);
            if ($qty>(int)$p['stock']) throw new DomainException('加入数量超过库存');
            query('DELETE FROM cart WHERE user_id=? AND product_id=?',[$userId,$id]);
            query('INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,?)',[$userId,$id,$qty]);
        } elseif ($action==='update') {
            $id=(int)($_POST['cart_id'] ?? 0); $qty=quantity($_POST['quantity'] ?? 1);
            $item=one('SELECT p.stock FROM cart c JOIN products p ON p.id=c.product_id WHERE c.id=? AND c.user_id=?',[$id,$userId]);
            if (!$item || $qty>(int)$item['stock']) throw new DomainException('商品不存在或库存不足');
            query('UPDATE cart SET quantity=? WHERE id=? AND user_id=?',[$qty,$id,$userId]);
        } elseif ($action==='delete') {
            query('DELETE FROM cart WHERE id=? AND user_id=?',[(int)($_POST['cart_id'] ?? 0),$userId]);
        } else throw new DomainException('操作无效');
        $conn->commit(); redirect_to('cart.php','购物车已更新');
    } catch (Throwable $e) { $conn->rollback(); if (!($e instanceof DomainException)) error_log($e); redirect_to('cart.php',$e instanceof DomainException?$e->getMessage():'操作失败，请稍后重试'); }
}
$items=rows('SELECT c.id AS cart_id,c.quantity,p.* FROM cart c LEFT JOIN products p ON p.id=c.product_id WHERE c.user_id=? ORDER BY c.id DESC',[$userId]);
page_start('我的购物车'); $total=0;
if (!$items) echo '<div class="card empty">购物车还是空的，<a href="index.php">去选购商品</a></div>';
foreach ($items as $item):
$valid=$item['id'] && $item['status']==1 && $item['audit_status']==='approved' && $item['quantity']<=$item['stock'];
$subtotal=$item['price']!==null ? cents($item['price'])*(int)$item['quantity'] : 0; $total+=$subtotal;
?>
<section class="card row">
<?php if ($item['image_url']): ?><img class="thumb" src="<?=h($item['image_url'])?>" alt="<?=h($item['title'])?>"><?php endif; ?>
<div class="grow"><h2><a href="detail.php?id=<?=(int)$item['id']?>"><?=h($item['title'] ?? '商品已删除')?></a></h2><p>¥ <?=money($subtotal)?> · 库存 <?=(int)$item['stock']?></p><?php if (!$valid): ?><p class="error">商品不可结算，请调整数量或移除。</p><?php endif; ?></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="cart_id" value="<?=(int)$item['cart_id']?>"><label>数量 <input type="number" name="quantity" min="1" max="999" value="<?=(int)$item['quantity']?>"></label><button name="action" value="update">更新</button> <button name="action" value="delete" formnovalidate>移除</button></form>
</section>
<?php endforeach; if ($items): ?><section class="card row"><div class="grow">合计 <strong class="price">¥ <?=money($total)?></strong><p class="muted">提交时会再次核验价格和库存，不同店铺分别生成订单。</p></div><a class="button" href="checkout.php">选择地址并结算</a></section><?php endif; page_end(); ?>
