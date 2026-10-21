<?php
// 客服会话 AJAX 发送端点（E-v2 IM 化改版）：POST 消息 → 落库 → 返回该消息 JSON（服务端完成转义）
// 双方角色校验与 chat_poll.php 完全一致
require_once __DIR__ . '/lib/shop.php';
header('Content-Type: application/json; charset=utf-8');
function json_out($ok, $payload = []) {
    echo json_encode(array_merge(['success' => $ok], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['user_id'])) json_out(false, ['message' => '请先登录']);
$userId = (int)$_SESSION['user_id'];
$shopId = (int)($_POST['shop_id'] ?? 0);
$threadUser = (int)($_POST['user_id'] ?? 0);
$ctxProduct = (int)($_POST['product_id'] ?? 0);
$shop = $shopId > 0 ? one('SELECT id, user_id FROM shops WHERE id=? AND status=1', [$shopId]) : null;
if (!$shop) json_out(false, ['message' => '会话不可用']);
$isMerchantOwner = (($_SESSION['role'] ?? '') === 'merchant') && (int)$shop['user_id'] === $userId;
if ($isMerchantOwner) {
    $role = 'merchant';
    if ($threadUser <= 0) json_out(false, ['message' => '缺少会话对象']);
} else {
    if (($_SESSION['role'] ?? '') === 'merchant') json_out(false, ['message' => '商家账号只能与自己的店铺会话']);
    if ($threadUser !== $userId) json_out(false, ['message' => '无权访问该会话']);
    $role = 'buyer';
}
try {
    shop_chat_send($shopId, $threadUser, $role, (string)($_POST['content'] ?? ''), $ctxProduct ?: null);
} catch (DomainException $e) {
    json_out(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log($e);
    json_out(false, ['message' => '发送失败，请稍后再试']);
}
$row = one('SELECT sc.id, sc.sender_role, sc.content, sc.created_at, p.title AS product_title FROM shop_chats sc LEFT JOIN products p ON p.id = sc.product_id WHERE sc.shop_id=? AND sc.user_id=? ORDER BY sc.id DESC LIMIT 1', [$shopId, $threadUser]);
json_out(true, ['message' => [
    'id' => (int)$row['id'],
    'sender_role' => $row['sender_role'],
    'escaped' => h((string)$row['content']),
    'created_at' => h((string)$row['created_at']),
    'product_id' => (int)($row['product_id'] ?? 0),
    'product_title' => !empty($row['product_title']) ? h(mb_substr((string)$row['product_title'], 0, 20)) : '',
]]);
