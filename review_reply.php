<?php
// 商家回复买家评价：仅本店商品，每条仅可回复一次；POST 专用
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if (($_SESSION['role'] ?? '')!=='merchant') { http_response_code(403); exit('仅商家可回复评价'); }
if($_SERVER['REQUEST_METHOD']!=='POST') redirect_to('admin_orders.php');
csrf_check();
try { merchant_reply_review($userId,(int)($_POST['review_id'] ?? 0),$_POST['reply'] ?? ''); }
catch(DomainException $e) { redirect_to('admin_orders.php','回复失败：'.$e->getMessage()); }
redirect_to('admin_orders.php','回复已发布');
