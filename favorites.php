<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['product_id'] ?? 0);
    if(($_POST['action'] ?? '')==='remove') query('DELETE FROM favorites WHERE user_id=? AND product_id=?',[$userId,$id]);
    elseif(one("SELECT id FROM products WHERE id=? AND status=1 AND audit_status='approved'",[$id])) query('INSERT IGNORE INTO favorites(user_id,product_id) VALUES(?,?)',[$userId,$id]);
    redirect_to('favorites.php','收藏已更新');
}
page_start('我的收藏');
$items=rows('SELECT p.* FROM favorites f JOIN products p ON p.id=f.product_id WHERE f.user_id=? ORDER BY f.created_at DESC',[$userId]);
if(!$items) echo '<section class="card empty">还没有收藏，<a href="index.php">去发现喜欢的商品</a></section>';
foreach($items as $item): ?><section class="card row"><div class="grow"><a href="detail.php?id=<?=(int)$item['id']?>"><?=h($item['title'])?></a><p class="price">¥ <?=h($item['price'])?></p><?php if($item['status']!=1 || $item['audit_status']!=='approved'): ?><span>暂不可购买</span><?php endif; ?></div><form method="post"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=(int)$item['id']?>"><button name="action" value="remove">取消收藏</button></form></section><?php endforeach; page_end(); ?>
