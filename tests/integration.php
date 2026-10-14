<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start(); // Session fixtures must be created before test output is flushed.
require_once __DIR__.'/../lib/shop.php';
$source = $db_name;
$testDb = 'shop_test_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
query("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tables = rows('SHOW TABLES');
query('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $record) {
    $table = array_values($record)[0];
    $definition = one('SHOW CREATE TABLE `'.str_replace('`','``',$table).'`')['Create Table'];
    $conn->select_db($testDb); query($definition); $conn->select_db($source);
}
$conn->select_db($testDb); query('SET FOREIGN_KEY_CHECKS=1');
query('ALTER TABLE products DROP COLUMN audit_status');
query("INSERT INTO categories(category_name,parent_id) VALUES('旧库测试分类',0)"); $legacyCategory=(int)$conn->insert_id;
query("INSERT INTO products(title,price,stock,category_id,status) VALUES('旧库测试商品','9.90',1,?,1)",[$legacyCategory]); $legacyProduct=(int)$conn->insert_id;
require __DIR__.'/../scripts/migrate.php';
require __DIR__.'/../scripts/migrate.php';
$assertions = 0;
function check($value,$message) { global $assertions; if (!$value) throw new RuntimeException('FAIL: '.$message); $assertions++; echo 'PASS: '.$message."\n"; }
function rejects($fn,$message) { try { $fn(); } catch (DomainException $e) { check(true,$message); return; } throw new RuntimeException('FAIL: '.$message); }
function insert_user($name,$role) { global $conn; query('INSERT INTO users(username,password,role) VALUES(?,?,?)',[$name,password_hash('TestShop2026!',PASSWORD_DEFAULT),$role]); return (int)$conn->insert_id; }
require_once __DIR__.'/../lib/admin_auth.php';
check(count(rows('SELECT id FROM admin_roles'))===5,'RBAC roles seeded idempotently');
check(count(rows('SELECT id FROM admin_permissions'))===13,'RBAC permissions seeded idempotently');
query("INSERT INTO admin_users(username,password,role_id,is_super) SELECT 'qa_super',?,id,1 FROM admin_roles WHERE role_code='super'",[password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);
$superId=(int)$conn->insert_id;
query("INSERT INTO admin_users(username,password,role_id) SELECT 'qa_operator',?,id FROM admin_roles WHERE role_code='order_manager'",[password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);
$operatorId=(int)$conn->insert_id;
$_SESSION['admin']=['id'=>$operatorId];refresh_admin_session();
check(admin_has_permission('order.ship') && !admin_has_permission('admin.manage'),'job role has only assigned permissions');
query('UPDATE admin_users SET status=? WHERE id=?',['disabled',$operatorId]);refresh_admin_session();check(!is_admin_logged_in(),'disabled admin immediately loses session');
query('UPDATE admin_users SET status=? WHERE id=?',['active',$operatorId]);
check(admin_safe_next_url('https://example.com/orders.php')==='' && admin_safe_next_url('../orders.php')==='','admin redirects reject external and traversal paths');
check(validate_admin_password('short','operator')!=='' && validate_admin_password(bin2hex(random_bytes(12)).'Aa!','operator')==='','admin password policy enforced');
$_SESSION['admin']=['id'=>$superId];refresh_admin_session();
audit_log('test.audit','test','1',['text'=>str_repeat('中文',300)],'success',true);
$audit=one("SELECT detail FROM admin_audit_logs WHERE action='test.audit'");check(json_decode($audit['detail'],true)!==null,'long audit detail remains valid JSON');
try { query("INSERT INTO admin_users(username,password,role_id,is_super) SELECT 'qa_duplicate_super','unused',id,1 FROM admin_roles WHERE role_code='super'"); throw new RuntimeException('second super accepted'); } catch(mysqli_sql_exception $e) {check($e->getCode()===1062,'only one super administrator allowed');}
$auditColumn=one("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='audit_status'");
check($auditColumn && $auditColumn['COLUMN_TYPE']==="enum('pending','approved','rejected')" && $auditColumn['IS_NULLABLE']==='NO' && $auditColumn['COLUMN_DEFAULT']==='approved','legacy audit status column restored');
check(one('SELECT audit_status FROM products WHERE id=?',[$legacyProduct])['audit_status']==='approved','legacy product remains approved');
check((int)one("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='idx_audit_status'")['n']===1,'legacy audit status index restored');
$buyer=insert_user('qa_buyer','user'); $other=insert_user('qa_other','user'); $merchant=insert_user('qa_merchant','merchant'); $admin=insert_user('qa_admin','admin');
query("INSERT INTO shops(user_id,shop_name,contact_phone,status) VALUES(?,'QA Shop','13800000000',1)",[$merchant]); $shop=(int)$conn->insert_id;
query("INSERT INTO user_addresses(user_id,receiver_name,receiver_phone,receiver_address,is_default) VALUES(?,'测试买家','13800000000','测试省测试市测试地址',1)",[$buyer]); $address=(int)$conn->insert_id;
query("INSERT INTO categories(category_name,parent_id) VALUES('测试分类',0)"); $category=(int)$conn->insert_id;
query("INSERT INTO products(title,price,stock,category_id,status,audit_status) VALUES('平台测试商品','19.90',10,?,1,'approved')",[$category]); $p1=(int)$conn->insert_id;
query("INSERT INTO products(title,price,stock,category_id,shop_id,status,audit_status) VALUES('店铺测试商品','30.10',10,?,?,1,'approved')",[$category,$shop]); $p2=(int)$conn->insert_id;
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
check(!verify_account_password('123456',password_hash('different-password',PASSWORD_DEFAULT)),'wrong hash password rejected');
check(verify_account_password('TestShop2026!',password_hash('TestShop2026!',PASSWORD_DEFAULT)),'hash password accepted');
check(!verify_account_password('old-exact-password','old-exact-password'),'legacy plaintext password rejected');
check(account_password_needs_reset('old-exact-password'),'legacy plaintext password requires reset');
check(cents('19.90')===1990 && money(3001)==='30.01','integer money arithmetic');
rejects(function(){ quantity(-1); },'negative quantity rejected');
rejects(function(){ quantity('1.5'); },'fractional quantity rejected');
query('INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,2),(?,?,1)',[$buyer,$p1,$buyer,$p2]);
rejects(function()use($buyer,$other,$p1,$address){create_orders($other,$p1,1,$address,'wrong-owner');},'foreign address rejected');
$ids=create_orders($buyer,0,1,$address,'test-checkout');
check(count($ids)===2,'cross-shop checkout splits orders');
check(create_orders($buyer,0,1,$address,'test-checkout')===$ids,'checkout retry is idempotent');
check((int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock']===8,'stock reserved once');
check((int)one('SELECT COUNT(*) AS n FROM cart WHERE user_id=?',[$buyer])['n']===0,'ordered cart entries removed');
$platform=one('SELECT * FROM orders WHERE id=?',[(int)$ids[0]]);
check($platform['total_amount']==='39.80','server prices used for total');
rejects(function()use($buyer,$p1,$address){create_orders($buyer,$p1,999,$address,'too-many');},'overselling rejected');
check((int)one('SELECT COUNT(*) AS n FROM orders')['n']===2,'failed checkout leaves no partial order');
rejects(function()use($ids,$other){change_order((int)$ids[0],$other,'cancel');},'foreign buyer cannot cancel');
change_order((int)$ids[0],$buyer,'cancel');
check((int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock']===10,'cancellation restores inventory');
rejects(function()use($ids,$buyer){change_order((int)$ids[0],$buyer,'cancel');},'repeat cancellation cannot inflate stock');
$shopOrder=(int)$ids[1];
putenv('SHOP_DEMO_PAYMENT=0');
rejects(function()use($shopOrder,$buyer){change_order($shopOrder,$buyer,'pay');},'payment disabled unless explicitly enabled');
putenv('SHOP_DEMO_PAYMENT=1'); change_order($shopOrder,$buyer,'pay');
rejects(function()use($shopOrder,$buyer){change_order($shopOrder,$buyer,'pay');},'duplicate payment rejected');
$_SESSION['role']='merchant';
rejects(function()use($shopOrder,$other){change_order($shopOrder,$other,'ship',['express_company'=>'测试快递','express_no'=>'TEST12345']);},'another merchant cannot ship');
change_order($shopOrder,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'TEST12345']);
$_SESSION['role']='user'; change_order($shopOrder,$buyer,'receive');
check(one('SELECT status FROM orders WHERE id=?',[$shopOrder])['status']==='completed','pay ship receive complete');
$item=one('SELECT * FROM order_items WHERE order_id=?',[$shopOrder]);
$firstReviewId=create_review($buyer,(int)$item['id'],5,'测试购买评价');
check((int)one('SELECT COUNT(*) AS n FROM reviews WHERE product_id=?',[$p2])['n']===1,'review persists against purchased item');
rejects(function()use($buyer,$item){create_review($buyer,(int)$item['id'],5,'重复评价');},'duplicate review prevented');
$refund=(int)create_orders($buyer,$p2,1,$address,'refund-order')[0]; change_order($refund,$buyer,'pay'); change_order($refund,$buyer,'refund',['reason'=>'测试未发货退款']);
$_SESSION['role']='merchant';
rejects(function()use($refund,$merchant){change_order($refund,$merchant,'ship',['express_company'=>'测试','express_no'=>'TEST54321']);},'refund request blocks shipment');
change_order($refund,$merchant,'refund_approve');
check(one('SELECT status FROM orders WHERE id=?',[$refund])['status']==='cancelled','demo refund closes order');
check((int)one('SELECT stock FROM products WHERE id=?',[$p2])['stock']===9,'refund restores reserved inventory once');
$_SESSION['role']='user';
$afterSaleRejected=(int)create_orders($buyer,$p2,1,$address,'after-sale-reject')[0]; change_order($afterSaleRejected,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($afterSaleRejected,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'TEST98765']);
$_SESSION['role']='user'; change_order($afterSaleRejected,$buyer,'receive');
rejects(function()use($afterSaleRejected,$other){change_order($afterSaleRejected,$other,'after_sale',['reason'=>'无权申请']);},'foreign buyer cannot request after-sale');
change_order($afterSaleRejected,$buyer,'after_sale',['reason'=>'商品与描述不符']);
rejects(function()use($afterSaleRejected,$buyer){change_order($afterSaleRejected,$buyer,'after_sale',['reason'=>'重复申请']);},'duplicate after-sale request rejected');
$_SESSION['role']='merchant'; change_order($afterSaleRejected,$merchant,'after_sale_reject');
check(one('SELECT status FROM after_sale_requests WHERE order_id=?',[$afterSaleRejected])['status']==='rejected','merchant can reject after-sale request');
check((int)one('SELECT stock FROM products WHERE id=?',[$p2])['stock']===8,'rejected after-sale does not restore stock');
$_SESSION['role']='user';
$afterSaleApproved=(int)create_orders($buyer,$p2,1,$address,'after-sale-approved')[0]; change_order($afterSaleApproved,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($afterSaleApproved,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'TEST76543']);
$_SESSION['role']='user'; change_order($afterSaleApproved,$buyer,'receive'); change_order($afterSaleApproved,$buyer,'after_sale',['reason'=>'商品存在质量问题']);
$_SESSION['role']='merchant';
rejects(function()use($afterSaleApproved,$other){change_order($afterSaleApproved,$other,'after_sale_approve');},'another merchant cannot approve after-sale');
change_order($afterSaleApproved,$merchant,'after_sale_approve');
check(one('SELECT status FROM after_sale_requests WHERE order_id=?',[$afterSaleApproved])['status']==='approved','merchant can approve after-sale request');
change_order($afterSaleApproved,$merchant,'after_sale_receive');
check(one('SELECT status FROM after_sale_requests WHERE order_id=?',[$afterSaleApproved])['status']==='refunded','demo after-sale refund completes after receipt');
check((int)one('SELECT stock FROM products WHERE id=?',[$p2])['stock']===8,'approved after-sale restores stock once');
rejects(function()use($afterSaleApproved,$merchant){change_order($afterSaleApproved,$merchant,'after_sale_receive');},'repeat after-sale receipt cannot inflate stock');
$_SESSION['role']='user';
$afterSaleOnline=(int)create_orders($buyer,$p2,1,$address,'after-sale-online')[0]; change_order($afterSaleOnline,$buyer,'pay'); query("UPDATE orders SET payment_method='online' WHERE id=?",[$afterSaleOnline]);
$_SESSION['role']='merchant'; change_order($afterSaleOnline,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'TEST65432']);
$_SESSION['role']='user'; change_order($afterSaleOnline,$buyer,'receive'); change_order($afterSaleOnline,$buyer,'after_sale',['reason'=>'线上支付退货退款']);
$_SESSION['role']='merchant'; change_order($afterSaleOnline,$merchant,'after_sale_approve'); change_order($afterSaleOnline,$merchant,'after_sale_receive');
check(one('SELECT status FROM after_sale_requests WHERE order_id=?',[$afterSaleOnline])['status']==='received','online-payment after-sale awaits payment-channel refund');
check((int)one('SELECT stock FROM products WHERE id=?',[$p2])['stock']===7,'online-payment after-sale does not restore stock');
$_SESSION['role']='user';
$expired=(int)create_orders($buyer,$p1,1,$address,'expired-order')[0];
query('UPDATE orders SET created_at=NOW()-INTERVAL 4 HOUR WHERE id=?',[$expired]); expire_orders($buyer); expire_orders($buyer);
check(one('SELECT status FROM orders WHERE id=?',[$expired])['status']==='cancelled','expired order cancelled');
check((int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock']===10,'expiry restores stock once');
query("UPDATE products SET audit_status='pending' WHERE id=?",[$p1]);
rejects(function()use($buyer,$p1,$address){create_orders($buyer,$p1,1,$address,'unapproved');},'unapproved product cannot be purchased');
query("UPDATE products SET audit_status='approved' WHERE id=?",[$p1]);
query('INSERT INTO favorites(user_id,product_id) VALUES(?,?)',[$buyer,$p1]);
check((int)one('SELECT COUNT(*) AS n FROM favorites WHERE user_id=?',[$buyer])['n']===1,'favorites persist');
// ===== SKU 商品规格：默认规格兼容、双规格分扣、抢最后一件、取消回补 =====
query("INSERT INTO products(title,price,stock,category_id,status,audit_status) VALUES('SKU测试商品','50.00',99,?,1,'approved')",[$category]); $pSku=(int)$conn->insert_id;
query('INSERT INTO product_skus(product_id,sku_name,price_cents,stock) VALUES(?,?,?,?),(?,?,?,?)',[$pSku,'规格甲',1000,5,$pSku,'规格乙',2000,1]);
$skuA=(int)one('SELECT id FROM product_skus WHERE product_id=? AND sku_name=?',[$pSku,'规格甲'])['id'];
$skuB=(int)one('SELECT id FROM product_skus WHERE product_id=? AND sku_name=?',[$pSku,'规格乙'])['id'];
$legacyOrder=(int)create_orders($buyer,$p1,1,$address,'sku-legacy')[0];
$legacyItem=one('SELECT * FROM order_items WHERE order_id=?',[$legacyOrder]);
change_order($legacyOrder,$buyer,'cancel');
check($legacyItem['sku_id']===null && $legacyItem['sku_name']===null,'single-spec product keeps default behavior');
$skuOrder=(int)create_orders($buyer,$pSku,2,$address,'sku-order',0,$skuA)[0];
$skuItem=one('SELECT * FROM order_items WHERE order_id=?',[$skuOrder]);
check((int)one('SELECT stock FROM product_skus WHERE id=?',[$skuA])['stock']===3 && (int)one('SELECT stock FROM product_skus WHERE id=?',[$skuB])['stock']===1 && (int)$skuItem['sku_id']===$skuA && $skuItem['sku_name']==='规格甲' && $skuItem['price']==='10.00','dual-spec checkout deducts only the selected SKU');
$raceWinner=(int)create_orders($buyer,$pSku,1,$address,'sku-race-winner',0,$skuB)[0];
$raceLost=false;
try { create_orders($buyer,$pSku,1,$address,'sku-race-loser',0,$skuB); } catch (DomainException $e) { $raceLost=true; }
check($raceLost && (int)one('SELECT stock FROM product_skus WHERE id=?',[$skuB])['stock']===0 && (int)one('SELECT COUNT(*) AS n FROM order_items WHERE sku_id=?',[$skuB])['n']===1,'concurrent buy of last SKU item allows only one order');
change_order($raceWinner,$buyer,'cancel'); change_order($skuOrder,$buyer,'cancel');
check((int)one('SELECT stock FROM product_skus WHERE id=?',[$skuA])['stock']===5 && (int)one('SELECT stock FROM product_skus WHERE id=?',[$skuB])['stock']===1 && (int)one('SELECT stock FROM products WHERE id=?',[$pSku])['stock']===99,'cancellation restores stock to the matching SKU');
// ===== SKU 规格图片：下单快照、无图 NULL、清除后回退商品主图 =====
query("UPDATE product_skus SET image_url='uploads/sku-a.jpg' WHERE id=?",[$skuA]);
$imgOrder=(int)create_orders($buyer,$pSku,1,$address,'sku-image-order',0,$skuA)[0];
$imgItem=one('SELECT * FROM order_items WHERE order_id=?',[$imgOrder]);
check($imgItem['sku_image']==='uploads/sku-a.jpg','sku image url snapshotted into order_items');
$noImgOrder=(int)create_orders($buyer,$pSku,1,$address,'sku-noimage-order',0,$skuB)[0];
$noImgItem=one('SELECT * FROM order_items WHERE order_id=?',[$noImgOrder]);
check($noImgItem['sku_image']===null,'sku without image snapshots NULL');
change_order($imgOrder,$buyer,'cancel'); change_order($noImgOrder,$buyer,'cancel');
query("UPDATE products SET image_url='uploads/main.jpg' WHERE id=?",[$pSku]);
query("UPDATE product_skus SET image_url=NULL WHERE id=?",[$skuA]);
$clearedOrder=(int)create_orders($buyer,$pSku,1,$address,'sku-cleared-order',0,$skuA)[0];
$clearedItem=one('SELECT i.*,COALESCE(i.sku_image,i.product_image,p.image_url) AS display_image FROM order_items i LEFT JOIN products p ON p.id=i.product_id WHERE i.order_id=?',[$clearedOrder]);
check($clearedItem['sku_image']===null && $clearedItem['display_image']==='uploads/main.jpg','cleared sku image falls back to product main image');
change_order($clearedOrder,$buyer,'cancel');
// ===== 售后退货物流：已发货申请售后→批准→买家填退货单→商户确认收货（SKU 只回补一次、订单联动置已完成） =====
query("INSERT INTO products(title,price,stock,category_id,shop_id,status,audit_status) VALUES('售后SKU商品','20.00',9,?,?,1,'approved')",[$category,$shop]); $pAfterSale=(int)$conn->insert_id;
query('INSERT INTO product_skus(product_id,sku_name,price_cents,stock) VALUES(?,?,?,?)',[$pAfterSale,'售后规格',2000,3]);
$skuAfterSale=(int)$conn->insert_id;
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
$afterSaleShipOrder=(int)create_orders($buyer,$pAfterSale,1,$address,'after-sale-ship',0,$skuAfterSale)[0]; change_order($afterSaleShipOrder,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($afterSaleShipOrder,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'SHIP111222']);
$_SESSION['role']='user'; change_order($afterSaleShipOrder,$buyer,'after_sale',['reason'=>'七天内申请退货退款']);
$_SESSION['role']='merchant'; change_order($afterSaleShipOrder,$merchant,'after_sale_approve');
$_SESSION['role']='user';
rejects(function()use($afterSaleShipOrder,$other){change_order($afterSaleShipOrder,$other,'after_sale_ship',['return_express_company'=>'测试快递','return_express_no'=>'RET123456']);},'foreign buyer cannot submit return logistics');
change_order($afterSaleShipOrder,$buyer,'after_sale_ship',['return_express_company'=>'测试快递','return_express_no'=>'RET123456']);
$shipRequest=one('SELECT * FROM after_sale_requests WHERE order_id=?',[$afterSaleShipOrder]);
check($shipRequest['return_express_company']==='测试快递' && $shipRequest['return_express_no']==='RET123456','buyer can submit return express after approval');
$_SESSION['role']='merchant'; change_order($afterSaleShipOrder,$merchant,'after_sale_receive');
check((int)one('SELECT stock FROM product_skus WHERE id=?',[$skuAfterSale])['stock']===3 && (int)one('SELECT stock FROM products WHERE id=?',[$pAfterSale])['stock']===9 && one('SELECT status FROM orders WHERE id=?',[$afterSaleShipOrder])['status']==='completed','return receipt restores SKU stock exactly once and completes order');
rejects(function()use($afterSaleShipOrder,$merchant){change_order($afterSaleShipOrder,$merchant,'after_sale_receive');},'repeat return receipt rejected after status change');
rejects(function()use($afterSaleRejected,$buyer){change_order($afterSaleRejected,$buyer,'after_sale_ship',['return_express_company'=>'测试快递','return_express_no'=>'RET000001']);},'return logistics rejected after after-sale refusal');
// 已完成订单同样可走售后全流程（申请→批准→填退货单→确认收货）
$_SESSION['role']='user';
$afterSaleDone=(int)create_orders($buyer,$pAfterSale,1,$address,'after-sale-done',0,$skuAfterSale)[0]; change_order($afterSaleDone,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($afterSaleDone,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'SHIP333444']);
$_SESSION['role']='user'; change_order($afterSaleDone,$buyer,'receive'); change_order($afterSaleDone,$buyer,'after_sale',['reason'=>'完成后申请退货']);
$_SESSION['role']='merchant'; change_order($afterSaleDone,$merchant,'after_sale_approve');
$_SESSION['role']='user'; change_order($afterSaleDone,$buyer,'after_sale_ship',['return_express_company'=>'测试快递','return_express_no'=>'RET555666']);
$_SESSION['role']='merchant'; change_order($afterSaleDone,$merchant,'after_sale_receive');
check(one('SELECT status FROM after_sale_requests WHERE order_id=?',[$afterSaleDone])['status']==='refunded' && (int)one('SELECT stock FROM product_skus WHERE id=?',[$skuAfterSale])['stock']===3,'completed order can go through after-sale');
check((int)one('SELECT COUNT(*) AS n FROM refund_requests')['n']===1,'after-sale flows do not affect refund-rate statistics');
// ===== 评价升级 + 搜索联想：追评唯一性 / 商家回复仅一次 / 晒图 3 张上限 / 联想接口未登录可用 =====
append_review($buyer,(int)$item['id'],'使用一个月后追加评价：依然很好用');
rejects(function()use($buyer,$item){append_review($buyer,(int)$item['id'],'第二次追评');},'each first review allows only one follow-up');
merchant_reply_review($merchant,$firstReviewId,'感谢您的评价，我们会持续改进');
rejects(function()use($merchant,$firstReviewId){merchant_reply_review($merchant,$firstReviewId,'重复回复');},'merchant can reply to a review only once');
$fourImages=['name'=>['a.jpg','b.jpg','c.jpg','d.jpg'],'type'=>['image/jpeg','image/jpeg','image/jpeg','image/jpeg'],'tmp_name'=>['','','',''],'error'=>[0,0,0,0],'size'=>[100,100,100,100]];
rejects(function()use($fourImages){validate_review_images($fourImages);},'review allows at most 3 images');
$_SESSION=[];
query('INSERT INTO search_logs(keyword) VALUES("联想测试词"),("联想测试词"),("联想测试词")');
$suggestJson=json_encode(search_suggest_words('联想测试'),JSON_UNESCAPED_UNICODE);
check(json_decode($suggestJson,true)===['联想测试词'],'search suggest returns valid JSON keyword list without login');
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
// ===== 订单留言：权限、内容校验、未读计数与已读状态 =====
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
$msgOrder=(int)create_orders($buyer,$p2,1,$address,'message-order')[0]; change_order($msgOrder,$buyer,'pay');
rejects(function()use($msgOrder,$other){send_order_message($msgOrder,$other,'无关买家尝试留言');},'foreign buyer cannot message the order');
rejects(function()use($msgOrder,$buyer){send_order_message($msgOrder,$buyer,'短');},'too-short order message rejected');
rejects(function()use($msgOrder,$buyer){send_order_message($msgOrder,$buyer,str_repeat('长',501));},'too-long order message rejected');
send_order_message($msgOrder,$buyer,'买家留言：请尽快处理我的订单');
$_SESSION['role']='merchant';
rejects(function()use($msgOrder,$other){send_order_message($msgOrder,$other,'无关商家尝试留言');},'foreign merchant cannot message the order');
send_order_message($msgOrder,$merchant,'商家回复：已收到您的留言');
check(order_message_unread_counts([$msgOrder],'buyer')===[ $msgOrder=>1],'buyer sees one unread merchant message');
check(order_message_unread_counts([$msgOrder],'merchant')===[ $msgOrder=>1],'merchant sees one unread buyer message');
order_message_mark_read($msgOrder,'buyer');
check(order_message_unread_counts([$msgOrder],'buyer')===[],'read message no longer counted as unread');
check(order_message_unread_counts([$msgOrder],'merchant')===[ $msgOrder=>1],'marking buyer read does not affect merchant unread');
order_message_mark_read($msgOrder,'merchant');
check(order_message_unread_counts([$msgOrder],'merchant')===[],'merchant unread cleared after reading');
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
$backendOrder=(int)create_orders($buyer,$p2,1,$address,'backend-order')[0];change_order($backendOrder,$buyer,'pay');
$_SESSION['role']='admin';
rejects(function()use($backendOrder,$admin){change_order($backendOrder,$admin,'ship',['express_company'=>'测试','express_no'=>'TEST112233']);},'legacy front administrator cannot manage orders');
define('ADMIN_ORDER_CONTEXT',true);
$_SESSION['admin']=['id'=>$operatorId];refresh_admin_session();
change_order($backendOrder,0,'ship',['express_company'=>'测试','express_no'=>'TEST112233']);
check(one('SELECT status FROM orders WHERE id=?',[$backendOrder])['status']==='shipped','independent order administrator can ship');
check((int)one("SELECT COUNT(*) n FROM admin_audit_logs WHERE action='order.ship' AND target_id=?",[(string)$backendOrder])['n']===1,'backend shipment writes audit record');
send_order_message($backendOrder,0,'平台管理员介入沟通');
$adminMessage=one("SELECT * FROM order_messages WHERE order_id=? AND sender_role='admin'",[$backendOrder]);
check($adminMessage && (int)$adminMessage['sender_id']===$operatorId,'admin message records backend admin identity');
check(order_message_unread_counts([$backendOrder],'buyer')===[ $backendOrder=>1],'buyer sees unread admin message');
check(order_message_unread_counts([$backendOrder],'admin')===[],'admin sees no unread own message');
order_message_mark_read($backendOrder,'admin');
check(order_message_unread_counts([$backendOrder],'admin')===[],'admin unread stays empty after reading buyer messages');
rejects(function()use($backendOrder,$admin){send_order_message($backendOrder,$admin,'旧前台管理员尝试留言');},'legacy front administrator cannot message orders');
query('DELETE rp FROM admin_role_permissions rp JOIN admin_permissions p ON p.id=rp.permission_id JOIN admin_users a ON a.role_id=rp.role_id WHERE a.id=? AND p.permission_code=?',[$operatorId,'order.refund']);refresh_admin_session();
check(!admin_has_permission('order.refund'),'revoked permission takes effect immediately');
$_SESSION['admin']['must_change_password']=1;
rejects(function()use($backendOrder){change_order($backendOrder,0,'after_sale_approve');},'forced password reset cannot execute backend actions');
// ===== 消息中心 / 商户对账单 / 限时特价与限购 =====
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
// 1) notify 各触发点写入（领券、发货带运单号、退款审批、买家留言→商户、商户回复→买家）
$claimCouponId=(int)one('SELECT id FROM coupons WHERE title=? LIMIT 1',['满50减5'])['id'];
coupon_claim($buyer,$claimCouponId);
$notifyOrder=(int)create_orders($buyer,$p2,1,$address,'notify-order')[0]; change_order($notifyOrder,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($notifyOrder,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'NOTIFY001']);
$refundNotifyOrder=(int)create_orders($buyer,$p2,1,$address,'notify-refund')[0]; change_order($refundNotifyOrder,$buyer,'pay');
change_order($refundNotifyOrder,$buyer,'refund',['reason'=>'通知测试用的退款申请']);
change_order($refundNotifyOrder,$merchant,'refund_approve');
send_order_message($notifyOrder,$buyer,'买家留言：通知中心触发测试');
send_order_message($notifyOrder,$merchant,'商家回复：通知中心触发测试');
check(one("SELECT id FROM user_notifications WHERE user_id=? AND type='coupon'",[$buyer])!==null
    && one("SELECT id FROM user_notifications WHERE user_id=? AND type='order' AND title LIKE '%NOTIFY001%'",[$buyer])!==null
    && one("SELECT id FROM user_notifications WHERE user_id=? AND type='refund'",[$buyer])!==null
    && one("SELECT id FROM user_notifications WHERE user_id=? AND type='message' AND body LIKE '%买家留言：通知中心触发测试%'",[$merchant])!==null
    && one("SELECT id FROM user_notifications WHERE user_id=? AND type='message' AND body LIKE '%商家回复：通知中心触发测试%'",[$buyer])!==null,
    'all notify trigger points write notifications');
// 2) 限时特价：区间内结算用特价，时段外回退原价
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
query("INSERT INTO products(title,price,stock,category_id,shop_id,status,audit_status,flash_price_cents,flash_start,flash_end,limit_per_user) VALUES('特价限购商品','40.00',50,?,?,1,'approved',2500,NOW() - INTERVAL 1 HOUR,NOW() + INTERVAL 1 HOUR,2)",[$category,$shop]); $pFlash=(int)$conn->insert_id;
$flashActive=(int)create_orders($buyer,$pFlash,1,$address,'flash-active')[0];
query('UPDATE products SET flash_end=NOW() - INTERVAL 1 MINUTE WHERE id=?',[$pFlash]);
$flashExpired=(int)create_orders($buyer,$pFlash,1,$address,'flash-expired')[0];
check(one('SELECT price FROM order_items WHERE order_id=?',[$flashActive])['price']==='25.00' && one('SELECT price FROM order_items WHERE order_id=?',[$flashExpired])['price']==='40.00',
    'flash price applies inside window and original price outside');
// 3) 限购边界：恰好等于限额可下单
$limitBuyer=insert_user('qa_limit_buyer','user');
query('INSERT INTO user_addresses(user_id,receiver_name,receiver_phone,receiver_address,is_default) VALUES(?,\'限购买家\',\'13800000000\',\'测试省测试市测试地址\',1)',[$limitBuyer]); $limitAddress=(int)$conn->insert_id;
$limitOrder=(int)create_orders($limitBuyer,$pFlash,2,$limitAddress,'limit-boundary')[0];
check((int)one('SELECT COUNT(*) AS n FROM order_items WHERE order_id=?',[$limitOrder])['n']===1 && (int)one('SELECT quantity FROM order_items WHERE order_id=?',[$limitOrder])['quantity']===2,
    'purchase exactly at per-user limit is allowed');
// 4) 限购超量：下单被拒且未扣库存
$stockBefore=(int)one('SELECT stock FROM products WHERE id=?',[$pFlash])['stock'];
$overRejected=false;
try { create_orders($limitBuyer,$pFlash,1,$limitAddress,'limit-over'); } catch (DomainException $e) { $overRejected=true; }
check($overRejected && (int)one('SELECT stock FROM products WHERE id=?',[$pFlash])['stock']===$stockBefore && (int)one('SELECT COUNT(*) AS n FROM orders WHERE checkout_token=?',['limit-over'])['n']===0,
    'purchase above per-user limit rejected without deducting stock');
// 5) 对账单 CSV：列头与汇总行
$csv=shop_statement_rows($shop,date('Y-m'));
$expected=one("SELECT COUNT(*) AS n,COALESCE(ROUND(SUM(total_amount-discount_amount)*100),0) AS paid FROM orders WHERE shop_id=? AND status IN ('paid','shipped','completed','cancelled') AND created_at>=? AND created_at<?",
    [$shop,date('Y-m-01 00:00:00'),date('Y-m-d H:i:s',strtotime(date('Y-m-01').' +1 month'))]);
$lastRow=$csv[count($csv)-1];
check($csv[0]===['订单号','下单时间','支付时间','订单状态','商品合计（元）','优惠金额（元）','实付金额（元）','运单号']
    && (int)trim($lastRow[1])===(int)$expected['n'] && $lastRow[6]==="\t".money((int)$expected['paid']),
    'statement csv header and summary row correct');
// ===== 邮箱验证码登录（注册/登录二合一）+ 企业/商户注册事务 =====
require_once __DIR__.'/../lib/email_login.php';
require_once __DIR__.'/../lib/account.php';
// 测试环境无法真实外发：用环境变量覆盖为不可达 SMTP（mailer 优先读 env），触发现有容错——发送失败不影响验证码落库
putenv('SMTP_HOST=smtp.invalid.test');putenv('SMTP_PORT=25');putenv('SMTP_USER=qa');putenv('SMTP_PASS=qa');putenv('MAIL_FROM=qa@example.com');
// 1) 新邮箱验证码登录自动建号（验证码真实落库；测试改写 code_hash 造已知码完成校验）
$emailNew='qa_elogin_'.bin2hex(random_bytes(3)).'@example.com';
check(email_login_issue($emailNew)==='sent','email login code issued and persisted despite smtp failure');
$codeRow=one('SELECT id FROM email_login_codes WHERE email=? ORDER BY id DESC LIMIT 1',[$emailNew]);
$known=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
query('UPDATE email_login_codes SET code_hash=? WHERE id=?',[hash('sha256',$known),(int)$codeRow['id']]);
check(email_login_verify($emailNew,$known)==='ok','known email login code verifies via hash and is consumed');
$emailUser=email_login_find_or_create($emailNew);
check((int)$emailUser['id']>0 && $emailUser['email']===$emailNew && $emailUser['role']==='user' && strpos($emailUser['username'],'qa_elogin_')===0,'email login auto-creates account for unregistered email');
// 2) 错误验证码拒绝并累计失败次数
$emailWrong='qa_ewrong_'.bin2hex(random_bytes(3)).'@example.com';
email_login_issue($emailWrong);
$wrongRow=one('SELECT id FROM email_login_codes WHERE email=? ORDER BY id DESC LIMIT 1',[$emailWrong]);
query('UPDATE email_login_codes SET code_hash=? WHERE id=?',[hash('sha256','123456'),(int)$wrongRow['id']]);
check(email_login_verify($emailWrong,'654321')==='invalid','wrong email login code rejected');
check((int)one('SELECT failed_attempts FROM email_login_codes WHERE id=?',[(int)$wrongRow['id']])['failed_attempts']===1,'wrong email login code attempt is counted');
// 3) 60 秒限发
$emailRate='qa_erate_'.bin2hex(random_bytes(3)).'@example.com';
check(email_login_issue($emailRate)==='sent','first email login code request allowed');
check(email_login_issue($emailRate)==='rate_limited','resend within 60 seconds rejected');
check((int)one('SELECT COUNT(*) AS n FROM email_login_codes WHERE email=?',[$emailRate])['n']===1,'rate limit inserts no duplicate code rows');
// 4) 企业/商户注册：成功路径（merchant 账号 + status=1 店铺 + 新人券）与回滚完整性
$bizName='qa_biz_'.bin2hex(random_bytes(3));$bizEmail=$bizName.'@example.com';
$merchantRegId=register_merchant_account($bizName,$bizEmail,'Passw0rd123','QA企业店铺','13800000000','地方特产零售');
$regUser=one('SELECT role FROM users WHERE id=?',[$merchantRegId]);
$regShop=one('SELECT shop_name,status,business_scope FROM shops WHERE user_id=?',[$merchantRegId]);
check($regUser['role']==='merchant' && $regShop!==null && (int)$regShop['status']===1 && $regShop['shop_name']==='QA企业店铺' && $regShop['business_scope']==='地方特产零售','merchant registration creates merchant user and shop atomically');
check(one('SELECT id FROM user_coupons WHERE user_id=?',[$merchantRegId])!==null,'merchant registration grants new-user coupon');
// 把新人券领光再注册：发券一步失败，事务整体回滚，不残留 users 行
query('UPDATE coupons SET claimed_count=total_count WHERE is_newuser=1');
$bizName2='qa_biz2_'.bin2hex(random_bytes(3));
$rolledBack=false;
try { register_merchant_account($bizName2,$bizName2.'@example.com','Passw0rd123','QA企业店铺二','13800000001','数码'); } catch (Throwable $e) { $rolledBack=true; }
check($rolledBack && one('SELECT id FROM users WHERE username=?',[$bizName2])===null,'merchant registration rollback leaves no orphan users row');
check(one('SELECT id FROM shops WHERE user_id=(SELECT id FROM users WHERE username=?)',[$bizName2])===null,'merchant registration rollback leaves no orphan shops row');
// 5) 账号登录支持邮箱作为标识
$emailAcct='qa_acct_'.bin2hex(random_bytes(3)).'@example.com';
query('UPDATE users SET email=? WHERE id=?',[$emailAcct,$buyer]);
$foundAcct=find_user_by_login_identifier($emailAcct);
check($foundAcct!==null && (int)$foundAcct['id']===$buyer,'account login accepts email identifier');
check(find_user_by_login_identifier('qa_buyer')!==null,'account login still accepts username identifier');
// ===== 管理后台登录安全加固 =====
// 6) IP 白名单：非白名单 IP 访问后台一律 404（函数级断言，伪造 REMOTE_ADDR，不依赖真实 HTTP 头）
$origRemoteAddr=$_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR']='203.0.113.66';
check(admin_ip_allowed()===false,'admin panel rejects non-whitelisted IP (entry guard returns 404)');
$_SERVER['REMOTE_ADDR']=' 127.0.0.1 ';
check(admin_ip_allowed()===true,'admin panel allows whitelisted loopback IPv4 (trimmed exact match)');
$_SERVER['REMOTE_ADDR']='::1';
check(admin_ip_allowed()===true,'admin panel allows whitelisted loopback IPv6');
if ($origRemoteAddr===null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR']=$origRemoteAddr;
// 7) 管理员连续 3 次密码错误锁 24 小时，未达 3 次不锁
query("INSERT INTO admin_users(username,password,role_id) SELECT 'qa_lock',?,id FROM admin_roles WHERE role_code='order_manager'",[password_hash('LockTest#2026!',PASSWORD_DEFAULT)]);
$lockId=(int)$conn->insert_id;
$lockRow=one('SELECT * FROM admin_users WHERE id=?',[$lockId]);
check(admin_record_login_failure($lockRow)===false && admin_record_login_failure(one('SELECT * FROM admin_users WHERE id=?',[$lockId]))===false,'two wrong passwords do not lock admin account');
check((int)one('SELECT failed_attempts FROM admin_users WHERE id=?',[$lockId])['failed_attempts']===2,'failed attempts counted before lock');
check(admin_record_login_failure(one('SELECT * FROM admin_users WHERE id=?',[$lockId]))===true,'third consecutive wrong password locks admin account');
$lockRow=one('SELECT * FROM admin_users WHERE id=?',[$lockId]);
check(admin_lock_remaining($lockRow)>23*3600 && (int)$lockRow['failed_attempts']===0,'admin lock window is about 24 hours and counter resets');
// 8) 敏感操作二次确认：错误密码不执行且审计有记录
query('UPDATE admin_users SET password=? WHERE id=?',[password_hash('Confirm#2026!',PASSWORD_DEFAULT),$superId]);
$_SESSION['admin']=['id'=>$superId];refresh_admin_session();
$confirmProductId=(int)one("SELECT id FROM products WHERE status=1 ORDER BY id LIMIT 1")['id'];
check(admin_confirm_password_valid('wrong-confirm-password','product',$confirmProductId)===false,'sensitive operation blocked when confirm password is wrong (delete/off would not execute)');
check(one("SELECT id FROM admin_audit_logs WHERE action='admin.confirm.failed' AND target_type='product' AND target_id=? AND status='failure'",[$confirmProductId])!==null,'failed sensitive-operation confirmation is audited');
check(admin_confirm_password_valid('Confirm#2026!')===true,'sensitive operation allowed when confirm password matches current admin');
// 9) 前台登录页不再暴露后台登录入口
check(strpos(file_get_contents(__DIR__.'/../user_login.php'),'admin/login.php')===false,'front login page no longer exposes admin login link');
// 10) 登录/注册页 Canvas 天空背景：js/sky.js 存在且两页面均引用；auth.css 无旧流星/极光/彗星 CSS 动画残留
$skyJs=file_exists(__DIR__.'/../js/sky.js');
$loginHtml=file_get_contents(__DIR__.'/../user_login.php');
$registerHtml=file_get_contents(__DIR__.'/../register.php');
check($skyJs && strpos($loginHtml,'js/sky.js')!==false && strpos($registerHtml,'js/sky.js')!==false,'js/sky.js exists and both auth pages include it');
$authCss=file_get_contents(__DIR__.'/../css/auth.css');
check(strpos($authCss,'@keyframes meteorFly')===false && strpos($authCss,'@keyframes aurora')===false && strpos($authCss,'@keyframes cometFly')===false && strpos($authCss,'@keyframes cometGlow')===false && strpos($authCss,'@keyframes twinkle')===false,'auth.css has no meteor/aurora/comet/twinkle CSS animation residue');
// 11) 首页深空 Hero 恢复：引用 sky.js 且含 Hero 容器，但无三栏布局残留
$indexHtml=file_get_contents(__DIR__.'/../index.php');
check(strpos($indexHtml,'js/sky.js')!==false && strpos($indexHtml,'home-hero')!==false && strpos($indexHtml,'hero-brand')!==false
    && strpos($indexHtml,'home-grid')===false && strpos($indexHtml,'cat-panel')===false && strpos($indexHtml,'side-rail')===false,
    'homepage includes sky.js and hero banner without three-column layout residue');
// ===== 购物车凑单满减提示（复用 coupons_for_user，门槛最低券 + 差额/可省计算） =====
// buyer 已领「满50减5」（discount 500 / min_spend 5000）且未使用；购物车放 1 件 ¥19.90
query('INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,1)',[$buyer,$p1]);
$hintGap=coupon_threshold_hint($buyer,1990);
check($hintGap!==null && !$hintGap['met'] && $hintGap['gap_cents']===3010 && $hintGap['threshold_cents']===5000 && $hintGap['progress']===40 && $hintGap['coupon']['title']==='满50减5','cart coupon hint computes gap, threshold and progress below threshold');
$hintMet=coupon_threshold_hint($buyer,5970);
check($hintMet['met'] && $hintMet['save_cents']===500 && $hintMet['progress']===100,'cart coupon hint reports savings once threshold met');
$hintZero=coupon_threshold_hint($buyer,0);
check($hintZero!==null && !$hintZero['met'] && $hintZero['gap_cents']===5000 && $hintZero['progress']===0,'hint handles zero cart total with full gap and zero progress');
check(coupon_threshold_hint($other,5970)===null,'user without coupons gets no cart hint');
query('DELETE FROM cart WHERE user_id=?',[$buyer]);
check(strpos(file_get_contents(__DIR__.'/../cart.php'),'coupon-hint')!==false && strpos(file_get_contents(__DIR__.'/../css/shop.css'),'coupon-progress')!==false,'cart page renders coupon hint banner with progress bar styles');
// ===== 收藏商品降价提醒（改价低于原价→收藏用户各一条站内通知，同轮不重复） =====
$p1Row=one('SELECT * FROM products WHERE id=?',[$p1]); // 平台测试商品 ¥19.90，仅 buyer 收藏
check(notify_price_drop($p1Row,1500)===1,'price drop notifies every favoriting user once');
$dropNotice=one("SELECT * FROM user_notifications WHERE user_id=? AND type='price' ORDER BY id DESC LIMIT 1",[$buyer]);
check($dropNotice!==null && strpos($dropNotice['body'],'《平台测试商品》降价了：原价 19.90 元 → 现价 15.00 元')!==false && $dropNotice['link']==='detail.php?id='.$p1,'price drop notification body and product link correct');
check(notify_price_drop($p1Row,1990)===0 && notify_price_drop($p1Row,2500)===0,'no notification when price unchanged or raised');
check((int)one("SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=? AND type='price'",[$buyer])['n']===1,'one price-drop round writes exactly one notification per favoriting user');
// ===== 商品浏览足迹（登录写入不去重、展示按商品最新去重、未登录不写入） =====
check(record_browsing($buyer,$p1)===true,'logged-in detail view inserts browsing footprint');
check(record_browsing($buyer,$p1)===true,'repeat view inserts another row without dedup on write');
check(record_browsing(0,$p1)===false && record_browsing($buyer,0)===false && (int)one('SELECT COUNT(*) AS n FROM user_browsing WHERE product_id=?',[$p1])['n']===2,'guest or invalid product writes no footprint');
query('INSERT INTO user_browsing(user_id,product_id,viewed_at) VALUES(?,?,NOW() - INTERVAL 2 MINUTE)',[$buyer,$p2]);
query('INSERT INTO user_browsing(user_id,product_id,viewed_at) VALUES(?,?,NOW())',[$buyer,$p2]);
query('UPDATE user_browsing SET viewed_at = NOW() - INTERVAL 2 MINUTE WHERE product_id=?',[$p1]); // p2 最新浏览在前
$history=browsing_history($buyer);
check(count($history)===2,'browsing history shows each product once');
check((int)$history[0]['product_id']===$p2 && $history[0]['title']==='店铺测试商品' && $history[0]['price']==='30.10','history orders by latest view and joins product name/price');
query("UPDATE products SET audit_status='pending' WHERE id=?",[$p1]);
check(count(browsing_history($buyer))===1,'unapproved products hidden from browsing history');
query("UPDATE products SET audit_status='approved' WHERE id=?",[$p1]);
check(strpos(file_get_contents(__DIR__.'/../detail.php'),'record_browsing')!==false && strpos(file_get_contents(__DIR__.'/../user_center.php'),'浏览足迹')!==false,'detail page records footprint and user center renders browsing block');
// ===== 评价印象标签（提交带标签、聚合计数、白名单过滤、空标签兼容） =====
$_SESSION=['user_id'=>$buyer,'role'=>'user'];
$tagOrder1=(int)create_orders($buyer,$p2,1,$address,'tag-review-1')[0]; change_order($tagOrder1,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($tagOrder1,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'TAG00101']); $_SESSION['role']='user'; change_order($tagOrder1,$buyer,'receive');
$tagItem1=(int)one('SELECT id FROM order_items WHERE order_id=?',[$tagOrder1])['id'];
$tagReviewId=create_review($buyer,$tagItem1,5,'标签测试评价一',['质量好','物流快','质量好','白名单外的标签']);
check(one('SELECT tags FROM reviews WHERE id=?',[$tagReviewId])['tags']==='质量好,物流快','review stores whitelisted deduped tags as comma string');
$tagOrder2=(int)create_orders($buyer,$p2,1,$address,'tag-review-2')[0]; change_order($tagOrder2,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($tagOrder2,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'TAG00102']); $_SESSION['role']='user'; change_order($tagOrder2,$buyer,'receive');
$tagItem2=(int)one('SELECT id FROM order_items WHERE order_id=?',[$tagOrder2])['id'];
create_review($buyer,$tagItem2,4,'标签测试评价二',['质量好']);
$tagCounts=review_tag_counts($p2);
check(count($tagCounts)===2 && $tagCounts[0]===['tag'=>'质量好','count'=>2] && $tagCounts[1]['tag']==='物流快' && $tagCounts[1]['count']===1,'tag aggregation counts and ranks top tags');
check(sanitize_review_tags(['有瑕疵','自定义标签'])==='有瑕疵' && sanitize_review_tags([])==='' && sanitize_review_tags('性价比高')==='性价比高','whitelist filter drops unknown tags and tolerates empty input');
query("UPDATE reviews SET tags='' WHERE product_id=?",[$p2]);
check(review_tag_counts($p2)===[],'legacy empty-tag rows aggregate to nothing without errors');
check(strpos(file_get_contents(__DIR__.'/../review.php'),'tags[]')!==false && strpos(file_get_contents(__DIR__.'/../detail.php'),'tag-pill')!==false,'review form offers tag checkboxes and detail page renders tag pills');
// ===== 订单定时任务：超时关单与自动确认收货（scripts/cron_orders.php） =====
require_once __DIR__.'/../scripts/cron_orders.php';
putenv('SHOP_DEMO_PAYMENT=1');
$cronStockP1=(int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock'];
$cronNotifyBefore=(int)one("SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=? AND title='订单超时未支付已自动取消'",[$buyer])['n'];
$cronFresh=(int)create_orders($buyer,$p1,1,$address,'cron-fresh')[0];
$cronExpired=(int)create_orders($buyer,$p1,1,$address,'cron-expired')[0];
query('UPDATE orders SET created_at=NOW()-INTERVAL 4 HOUR WHERE id=?',[$cronExpired]);
$cronShip=(int)create_orders($buyer,$p2,1,$address,'cron-ship')[0]; change_order($cronShip,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($cronShip,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'CRON00001']); $_SESSION['role']='user';
query('UPDATE orders SET shipped_at=NOW()-INTERVAL 8 DAY WHERE id=?',[$cronShip]);
$cronRecentShip=(int)create_orders($buyer,$p2,1,$address,'cron-recent-ship')[0]; change_order($cronRecentShip,$buyer,'pay');
$_SESSION['role']='merchant'; change_order($cronRecentShip,$merchant,'ship',['express_company'=>'测试快递','express_no'=>'CRON00002']); $_SESSION['role']='user';
$cronClosed=cron_close_timeout_orders(); $cronReceived=cron_auto_receive_orders();
check($cronClosed===1,'cron closes exactly the timed-out pending order');
check($cronReceived===1,'cron receives exactly the overdue shipped order');
check(one('SELECT status FROM orders WHERE id=?',[$cronExpired])['status']==='cancelled','timed-out unpaid order cancelled');
check((int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock']===$cronStockP1-1,'fresh order still holds one reserved item after cron closes the other');
check((int)one('SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=? AND title=?',[$buyer,'订单超时未支付已自动取消'])['n']===$cronNotifyBefore+1,'cancellation notice written once');
check(one('SELECT status FROM orders WHERE id=?',[$cronFresh])['status']==='pending','recent pending order untouched by cron');
check(one('SELECT status FROM orders WHERE id=?',[$cronShip])['status']==='completed' && one('SELECT completed_at FROM orders WHERE id=?',[$cronShip])['completed_at']!==null,'overdue shipped order auto-completed');
check(one('SELECT status FROM orders WHERE id=?',[$cronRecentShip])['status']==='shipped','recently shipped order not auto-received');
$cronCompletedAt=one('SELECT completed_at FROM orders WHERE id=?',[$cronShip])['completed_at'];
$cronStockAfter=(int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock'];
$cronClosed2=cron_close_timeout_orders(); $cronReceived2=cron_auto_receive_orders();
check($cronClosed2===0 && $cronReceived2===0,'cron rerun finds nothing to do');
check((int)one('SELECT stock FROM products WHERE id=?',[$p1])['stock']===$cronStockAfter && one('SELECT completed_at FROM orders WHERE id=?',[$cronShip])['completed_at']===$cronCompletedAt,'cron rerun causes no duplicate side effects');
// ===== 商品列表排序与筛选（index.php：排序白名单 / 价格区间 / 有货筛选 / 分页透传） =====
// 进程内渲染首页：$_GET 注入后 include，捕获 HTML 再还原（include 包在函数内隔离变量作用域）
function render_home(array $get) {
    global $conn;
    $backup = $_GET; $_GET = $get;
    ob_start();
    include __DIR__.'/../index.php';
    $html = ob_get_clean();
    $_GET = $backup;
    return $html;
}
function home_titles($html) {
    preg_match_all('#<div class="product-title">([^<]*)</div>#', $html, $m);
    return $m[1];
}
// 可见商品口径与 index.php 主查询一致：上架+审核通过+店铺在售（或平台自营）
$visWhere = "p.status=1 AND p.audit_status='approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id=p.shop_id AND s.status=1))";
$visSales = "(SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ('paid','shipped','completed'))";
$visRating = "(SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.product_id=p.id AND r.parent_id IS NULL)";
function expect_titles($extraWhere, $orderSql) {
    return array_map('htmlspecialchars', array_column(rows("SELECT p.title FROM products p WHERE ".$GLOBALS['visWhere'].$extraWhere." ORDER BY ".$orderSql." LIMIT 12"), 'title'));
}
// 造 15 件在售商品保证超过一页（12 件/页），价格递增便于区间断言，其中 1 件零库存
for ($i = 1; $i <= 15; $i++) {
    query("INSERT INTO products(title,price,stock,category_id,status,audit_status) VALUES(?,?,?,?,1,'approved')",
        ['分页测试商品'.$i, sprintf('%.2f', 3 + $i * 3), $i === 15 ? 0 : 5, $category]);
}
// 排序白名单逐项生效：渲染顺序与按口径复算的期望顺序一致
foreach ([
    'default' => ['', 'p.id DESC'],
    'sales' => ['', $visSales.' DESC, p.id DESC'],
    'price_asc' => ['', 'p.price ASC, p.id DESC'],
    'price_desc' => ['', 'p.price DESC, p.id DESC'],
    'rating' => ['', $visRating.' DESC, p.id DESC'],
] as $sortKey => [$sortWhere, $sortOrder]) {
    check(home_titles(render_home(['sort' => $sortKey])) === expect_titles($sortWhere, $sortOrder), 'sort='.$sortKey.' orders products as whitelisted');
}
// 价格区间：仅 ¥15-¥20 商品出现，且平台测试商品（¥19.90）在列
$rangeHtml = render_home(['sort' => 'price_asc', 'price_min' => '15', 'price_max' => '20']);
check(home_titles($rangeHtml) === expect_titles(' AND p.price >= 15 AND p.price <= 20', 'p.price ASC, p.id DESC'), 'price range filters products between min and max');
check(in_array(htmlspecialchars('平台测试商品'), home_titles($rangeHtml), true), 'price range keeps the 19.90 product');
// 非法参数被忽略：负数价格、非法排序值、in_stock=0 均回退默认结果
check(home_titles(render_home(['sort' => 'p.id;DROP', 'price_min' => '-5', 'price_max' => '-3', 'in_stock' => '0'])) === expect_titles('', 'p.id DESC'), 'invalid sort and negative price fall back to default listing');
// 仅显示有货：零库存的分页测试商品15 被过滤
$stockHtml = render_home(['in_stock' => '1', 'sort' => 'price_desc']);
check(home_titles($stockHtml) === expect_titles(' AND p.stock > 0', 'p.price DESC, p.id DESC'), 'in_stock=1 hides zero-stock products');
check(!in_array(htmlspecialchars('分页测试商品15'), home_titles($stockHtml), true), 'zero-stock product excluded when in_stock filter on');
// 分页链接透传：下一页链接携带 sort/price_min/price_max/in_stock 全部筛选参数
$pageHtml = render_home(['sort' => 'price_desc', 'price_min' => '1', 'price_max' => '999', 'in_stock' => '1']);
check(preg_match('#<a href="([^"]*)">下一页</a>#', $pageHtml, $pageMatch) === 1, 'pagination next link rendered when results exceed one page');
$nextHref = html_entity_decode($pageMatch[1]);
check(strpos($nextHref, 'sort=price_desc') !== false && strpos($nextHref, 'price_min=1') !== false && strpos($nextHref, 'price_max=999') !== false && strpos($nextHref, 'in_stock=1') !== false && strpos($nextHref, 'page=2') !== false, 'pagination link carries all filter params');
putenv('SMTP_HOST');putenv('SMTP_PORT');putenv('SMTP_USER');putenv('SMTP_PASS');putenv('MAIL_FROM');
$superSession=bin2hex(random_bytes(24));$operatorSession=bin2hex(random_bytes(24));
session_save_path(sys_get_temp_dir());
foreach ([$superSession=>$superId,$operatorSession=>$operatorId] as $sid=>$aid) {
    session_id($sid);session_start();$_SESSION=['admin'=>['id'=>$aid]];refresh_admin_session();csrf_token();session_write_close();
}
file_put_contents(__DIR__.'/state.json',json_encode(['database'=>$testDb,'buyer'=>$buyer,'other'=>$other,'merchant'=>$merchant,'admin'=>$admin,'address'=>$address,'platform_product'=>$p1,'shop_product'=>$p2,'shop'=>$shop,'completed_item'=>(int)$item['id'],'super_id'=>$superId,'operator_id'=>$operatorId,'super_session'=>$superSession,'operator_session'=>$operatorSession],JSON_PRETTY_PRINT));
echo "$assertions integration assertions passed. Test database: $testDb\n";
