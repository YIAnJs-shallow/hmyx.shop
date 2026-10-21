<?php
// 客服会话轮询端点（E-v2）：返回线程内 after_id 之后的消息（JSON，服务端完成转义）
// 双方角色校验：买家只能拉自己的线程，商家只能拉自己店铺的线程
require_once __DIR__ . '/lib/shop.php';
header('Content-Type: application/json; charset=utf-8');
function json_out($ok, $payload = []) {
    echo json_encode(array_merge(['success' => $ok], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['user_id'])) json_out(false, ['message' => '请先登录']);
$userId = (int)$_SESSION['user_id'];
$shopId = (int)($_GET['shop_id'] ?? 0);
$threadUser = (int)($_GET['user_id'] ?? 0);
$after = (int)($_GET['after'] ?? 0);
$shop = $shopId > 0 ? one('SELECT id, user_id FROM shops WHERE id=? AND status=1', [$shopId]) : null;
if (!$shop) json_out(false, ['message' => '会话不可用']);
$isMerchantOwner = (($_SESSION['role'] ?? '') === 'merchant') && (int)$shop['user_id'] === $userId;
if ($isMerchantOwner) {
    if ($threadUser <= 0) json_out(false, ['message' => '缺少会话对象']);
} else {
    if ($threadUser !== $userId) json_out(false, ['message' => '无权访问该会话']);
    if (($_SESSION['role'] ?? '') === 'merchant') json_out(false, ['message' => '商家账号只能与自己的店铺会话']);
}
$messages = shop_chat_list($shopId, $threadUser, $after);
$out = [];
foreach ($messages as $m) {
    $out[] = [
        'id' => (int)$m['id'],
        'sender_role' => $m['sender_role'],
        'escaped' => h((string)$m['content']),
        'created_at' => h((string)$m['created_at']),
        'product_id' => (int)($m['product_id'] ?? 0),
        'product_title' => !empty($m['product_title']) ? h(mb_substr((string)$m['product_title'], 0, 20)) : '',
    ];
}
json_out(true, ['messages' => $out]);
