<?php
// 订单留言增量拉取 API（GET）：供留言页 30 秒轮询追加新留言
// 参数：order_id 必填；after_id 可选（仅纯数字，intval 后 AND id > after_id，非法值忽略即返回全部）
// 权限：仅该订单参与方（买家/商家，复用 order_message_role）；返回结构固定不变
require_once __DIR__.'/lib/shop.php';
header('Content-Type: application/json; charset=utf-8');
// 未登录：轮询不启动的前置条件，服务端同样拒绝
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'messages'=>[]]); return; }
$userId=(int)$_SESSION['user_id'];
$orderId=(int)($_GET['order_id'] ?? 0);
$order=$orderId>0?one('SELECT * FROM orders WHERE id=?',[$orderId]):null;
// 非订单参与方无法拉取
if(!$order || order_message_role($order,$userId)===null) { http_response_code(403); echo json_encode(['success'=>false,'messages'=>[]]); return; }
// after_id 白名单：仅接受纯数字，非法/负数一律忽略
$afterId=0;
if (isset($_GET['after_id']) && preg_match('/^\d+$/D',(string)$_GET['after_id'])) $afterId=(int)$_GET['after_id'];
$messages=rows('SELECT id,sender_role,content,created_at FROM order_messages WHERE order_id=?'.($afterId>0?' AND id > ?':'').' ORDER BY id', $afterId>0?[$orderId,$afterId]:[$orderId]);
echo json_encode(['success'=>true,'messages'=>$messages], JSON_UNESCAPED_UNICODE);
