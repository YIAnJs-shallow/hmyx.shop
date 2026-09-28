<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); $id=(int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
$item=one("SELECT i.* FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.id=? AND o.user_id=? AND o.status='completed'",[$id,$userId]);
if(!$item || !$item['product_id']) { http_response_code(403); exit('仅可评价本人已完成订单中的商品'); }
$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    $rating=(int)($_POST['rating'] ?? 0); $content=trim($_POST['content'] ?? '');
    if($rating<1 || $rating>5 || mb_strlen($content)<2 || mb_strlen($content)>1000) $error='请选择 1 至 5 星，评价内容为 2 至 1000 字';
    else { try { query('INSERT INTO reviews(user_id,order_item_id,product_id,rating,content) VALUES(?,?,?,?,?)',[$userId,$id,(int)$item['product_id'],$rating,$content]); redirect_to('detail.php?id='.(int)$item['product_id'],'评价已发布'); } catch(mysqli_sql_exception $e) { if($e->getCode()!=1062) throw $e; $error='该商品订单已经评价过了'; } }
}
page_start('评价商品'); ?><section class="card"><h2><?=h($item['product_title'])?></h2><?php if($error): ?><p class="notice error"><?=h($error)?></p><?php endif; ?><form method="post"><?=csrf_field()?><input type="hidden" name="item_id" value="<?=$id?>"><label>评分 <select name="rating"><?php for($i=5;$i>=1;$i--): ?><option value="<?=$i?>"><?=$i?> 星</option><?php endfor; ?></select></label><label>评价内容<textarea name="content" minlength="2" maxlength="1000" required><?=h($_POST['content'] ?? '')?></textarea></label><button>提交评价</button></form></section><?php page_end(); ?>
