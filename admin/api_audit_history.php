<?php
require_once __DIR__ . '/inc/auth.php';
/**
 * 审核历史 AJAX 接口
 * GET 参数：product_id
 * 返回 JSON：{success: bool, records: [...]}
 */

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in() || !admin_has_permission('product.audit') || !empty($_SESSION['admin']['must_change_password'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登录或权限不足', 'records' => []]);
    exit;
}

$productId = intval($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误', 'records' => []]);
    exit;
}



$stmt = $conn->prepare("SELECT r.result, r.comment, r.created_at, COALESCE(a.username,u.username) AS admin_name
    FROM audit_records r
    LEFT JOIN users u ON r.admin_id = u.id LEFT JOIN admin_users a ON r.admin_user_id = a.id
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
