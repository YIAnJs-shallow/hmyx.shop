<?php
require_once __DIR__ . '/config/db.php';
/**
 * 批量审核 AJAX 接口
 * POST 参数：
 *   product_ids[]  整数数组
 *   action         approve | reject
 *   comment        审核意见（驳回时必填）
 * 返回 JSON：{success: bool, message: string, processed: int}
 */
require_once __DIR__ . '/lib/security.php';
header('Content-Type: application/json; charset=utf-8');

// 管理员鉴权
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登录或权限不足', 'processed' => 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求', 'processed' => 0]);
    exit;
}

// 仅允许 JSON 输入
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '请求体格式错误（需 JSON）', 'processed' => 0]);
    exit;
}

$productIds = isset($data['product_ids']) ? (array)$data['product_ids'] : [];
$action     = $data['action'] ?? '';
$comment    = trim($data['comment'] ?? '');

// 参数校验
$productIds = array_filter(array_map('intval', $productIds));
if (empty($productIds)) {
    echo json_encode(['success' => false, 'message' => '未选择任何商品', 'processed' => 0]);
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => '操作类型无效', 'processed' => 0]);
    exit;
}
if ($action === 'reject' && $comment === '') {
    echo json_encode(['success' => false, 'message' => '驳回时必须填写审核意见', 'processed' => 0]);
    exit;
}

require_once 'config/db.php';
$adminId = intval($_SESSION['user_id']);
$resultStatus = ($action === 'approve') ? 'approved' : 'rejected';

$conn->begin_transaction();
try {
    $processed = 0;
    foreach ($productIds as $pid) {
        // 行锁，防止并发审核同一商品
        $lockStmt = $conn->prepare("SELECT id, audit_status FROM products WHERE id = ? FOR UPDATE");
        $lockStmt->bind_param("i", $pid);
        $lockStmt->execute();
        $lockRes = $lockStmt->get_result();
        if (!$lockRes || $lockRes->num_rows === 0) {
            $lockStmt->close();
            continue; // 商品不存在则跳过
        }
        $lockStmt->close();

        // 更新商品状态（驳回时自动下架）
        if ($action === 'approve') {
            $upd = $conn->prepare("UPDATE products SET audit_status = 'approved' WHERE id = ?");
            $upd->bind_param("i", $pid);
        } else {
            $upd = $conn->prepare("UPDATE products SET audit_status = 'rejected', status = 0 WHERE id = ?");
            $upd->bind_param("i", $pid);
        }
        $upd->execute();
        $upd->close();

        // 写入审核记录
        $ins = $conn->prepare("INSERT INTO audit_records (product_id, result, comment, admin_id) VALUES (?, ?, ?, ?)");
        $ins->bind_param("issi", $pid, $resultStatus, $comment, $adminId);
        $ins->execute();
        $ins->close();

        $processed++;
    }
    $conn->commit();
    $msg = ($action === 'approve') ? "成功批量通过 {$processed} 个商品" : "成功批量驳回 {$processed} 个商品";
    echo json_encode(['success' => true, 'message' => $msg, 'processed' => $processed]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败：' . $e->getMessage(), 'processed' => 0]);
}
