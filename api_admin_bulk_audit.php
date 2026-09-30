<?php
require_once __DIR__ . '/config/db.php';
/**
 * 批量审核 AJAX 接口。
 * JSON 请求体：product_ids（最多 100 个正整数）、action（approve | reject）、comment（驳回必填）。
 * 仅对获得行锁时仍为 pending 的商品写入状态和审核记录。
 */
header('Content-Type: application/json; charset=utf-8');

const MAX_BULK_AUDIT_BODY_BYTES = 65536;
const MAX_BULK_AUDIT_IDS = 100;
const MAX_BULK_AUDIT_COMMENT_LENGTH = 255;

function bulk_audit_response($status, $success, $message, $processed = 0) {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message, 'processed' => $processed]);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    bulk_audit_response(403, false, '未登录或权限不足');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    bulk_audit_response(405, false, '仅支持 POST 请求');
}

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '';
if ($contentLength !== '' && (!ctype_digit($contentLength) || (int)$contentLength > MAX_BULK_AUDIT_BODY_BYTES)) {
    bulk_audit_response(413, false, '请求内容过大');
}

$rawBody = file_get_contents('php://input');
if (strlen($rawBody) > MAX_BULK_AUDIT_BODY_BYTES) {
    bulk_audit_response(413, false, '请求内容过大');
}

$data = json_decode($rawBody);
if (!is_object($data) || json_last_error() !== JSON_ERROR_NONE) {
    bulk_audit_response(400, false, '请求体格式错误（需 JSON）');
}

$productIds = $data->product_ids ?? null;
$action = $data->action ?? null;
$comment = $data->comment ?? '';
if (!is_array($productIds)) {
    bulk_audit_response(400, false, '商品列表格式错误');
}
if (count($productIds) === 0 || count($productIds) > MAX_BULK_AUDIT_IDS) {
    bulk_audit_response(400, false, '单次请选择 1 至 100 个商品');
}
if (!is_string($action) || !in_array($action, ['approve', 'reject'], true)) {
    bulk_audit_response(400, false, '操作类型无效');
}
if (!is_string($comment)) {
    bulk_audit_response(400, false, '审核意见格式错误');
}
$comment = trim($comment);
if (mb_strlen($comment, 'UTF-8') > MAX_BULK_AUDIT_COMMENT_LENGTH) {
    bulk_audit_response(400, false, '审核意见不能超过 255 个字符');
}
if ($action === 'reject' && $comment === '') {
    bulk_audit_response(400, false, '驳回时必须填写审核意见');
}

$uniqueIds = [];
foreach ($productIds as $productId) {
    if (!is_int($productId) || $productId <= 0 || $productId > 2147483647) {
        bulk_audit_response(400, false, '商品编号格式错误');
    }
    $uniqueIds[$productId] = $productId;
}
$productIds = array_values($uniqueIds);
sort($productIds, SORT_NUMERIC);

$adminId = (int)$_SESSION['user_id'];
$resultStatus = $action === 'approve' ? 'approved' : 'rejected';
$transactionStarted = false;
try {
    $conn->begin_transaction();
    $transactionStarted = true;

    $lockStmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND audit_status = 'pending' FOR UPDATE");
    $updateSql = $action === 'approve'
        ? "UPDATE products SET audit_status = 'approved' WHERE id = ? AND audit_status = 'pending'"
        : "UPDATE products SET audit_status = 'rejected', status = 0 WHERE id = ? AND audit_status = 'pending'";
    $updateStmt = $conn->prepare($updateSql);
    $insertStmt = $conn->prepare('INSERT INTO audit_records (product_id, result, comment, admin_id) VALUES (?, ?, ?, ?)');

    $processed = 0;
    foreach ($productIds as $pid) {
        $lockStmt->bind_param('i', $pid);
        $lockStmt->execute();
        $lockResult = $lockStmt->get_result();
        $isPending = $lockResult && $lockResult->num_rows === 1;
        if ($lockResult) $lockResult->free();
        if (!$isPending) continue;

        $updateStmt->bind_param('i', $pid);
        $updateStmt->execute();
        if ($updateStmt->affected_rows !== 1) continue;

        $insertStmt->bind_param('issi', $pid, $resultStatus, $comment, $adminId);
        $insertStmt->execute();
        $processed++;
    }

    $lockStmt->close();
    $updateStmt->close();
    $insertStmt->close();
    $conn->commit();
    $transactionStarted = false;

    $message = $action === 'approve' ? "成功批量通过 {$processed} 个商品" : "成功批量驳回 {$processed} 个商品";
    echo json_encode(['success' => true, 'message' => $message, 'processed' => $processed]);
} catch (Exception $e) {
    if ($transactionStarted) $conn->rollback();
    error_log('bulk_audit_failed: ' . $e->getMessage());
    bulk_audit_response(500, false, '审核操作失败，请稍后重试');
}
