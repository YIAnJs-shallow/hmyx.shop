<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (strpos(getenv('DB_NAME') ?: '', 'shop_test_') !== 0) exit("Test database required\n");
require_once __DIR__.'/../lib/shop.php';
$data=json_decode(file_get_contents(__DIR__.'/state.json'),true);
$mode=$argv[1] ?? '';
if ($mode==='setup') {
    query("INSERT INTO products(title,price,stock,status,audit_status) VALUES('库存竞争测试','1.00',1,1,'approved')");
    $product=(int)$conn->insert_id;
    $addresses=[];
    foreach ([(int)$data['buyer'],(int)$data['other']] as $user) {
        $address=one('SELECT id FROM user_addresses WHERE user_id=? LIMIT 1',[$user]);
        if (!$address) { query("INSERT INTO user_addresses(user_id,receiver_name,receiver_phone,receiver_address) VALUES(?,'并发测试','13800000000','测试地址仅用于自动测试')",[$user]); $address=['id'=>(int)$conn->insert_id]; }
        $addresses[]=(int)$address['id'];
    }
    echo json_encode(['product'=>$product,'addresses'=>$addresses]);
} elseif ($mode==='buy') {
    $actor=(int)$argv[2]; $product=(int)$argv[3]; $address=(int)$argv[4]; $start=(float)$argv[5];
    while(microtime(true)<$start) usleep(5000);
    try { create_orders($actor,$product,1,$address,'race-'.$product.'-'.$actor); echo 'success'; }
    catch(DomainException $e) { echo 'sold-out'; }
} elseif ($mode==='check') {
    $product=(int)$argv[2];
    $stock=(int)one('SELECT stock FROM products WHERE id=?',[$product])['stock'];
    $orders=rows('SELECT o.* FROM orders o JOIN order_items i ON i.order_id=o.id WHERE i.product_id=?',[$product]);
    if ($stock!==0 || count($orders)!==1) throw new RuntimeException('Concurrent inventory invariant failed');
    $_SESSION['role']='user';
    change_order((int)$orders[0]['id'],(int)$orders[0]['user_id'],'cancel');
    if ((int)one('SELECT stock FROM products WHERE id=?',[$product])['stock'] !== 1) throw new RuntimeException('Stock was not restored');
    echo 'single order, zero oversell, cancellation restored stock';
}
