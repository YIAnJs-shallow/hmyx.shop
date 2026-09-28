<?php
require_once __DIR__ . '/config/db.php';
/**
 * 审核历史 AJAX 接口
 * GET 参数：product_id
 * 返回 JSON：{success: bool, records: [...]}
 */
require_once __DIR__ . '/lib/security.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登录或权限不足', 'records' => []]);
    exit;
}

$productId = intval($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误', 'records' => []]);
    exit;
}

require_once 'config/db.php';

$stmt = $conn->prepare("SELECT r.result, r.comment, r.created_at, u.username AS admin_name
    FROM audit_records r
    LEFT JOIN users u ON r.admin_id = u.id
    WHERE r.product_id = ?
    ORDER BY r.created_at DESC");
$stmt->bind_param("i", $productId);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

echo json_encode(['success' => true, 'records' => $records]);
