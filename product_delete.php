<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); $id=(int)($_POST['id'] ?? $_GET['id'] ?? 0);
$p=one('SELECT p.*,s.user_id AS merchant_id FROM products p LEFT JOIN shops s ON s.id=p.shop_id WHERE p.id=?',[$id]);
if(!$p || (($_SESSION['role'] ?? '')!=='admin' && !(($_SESSION['role'] ?? '')==='merchant' && (int)$p['merchant_id']===$userId))) { http_response_code(403); exit('无权操作此商品'); }
if($_SERVER['REQUEST_METHOD']==='POST') { query('UPDATE products SET status=0 WHERE id=?',[$id]); redirect_to(($_SESSION['role']==='admin')?'admin.php':'product_list.php','商品已下架'); }
page_start('下架商品'); ?><section class="card"><p>确认下架 <?=h($p['title'])?>？历史订单和评价会保留。</p><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><button>确认下架</button></form></section><?php page_end(); ?>
