<?php
// 最近浏览接口：POST product_ids（表单数组或 JSON 数组）+ CSRF，返回在售商品摘要 JSON
// 数据查询复用 lib/shop.php 的 recent_view_products（单次 IN 批量查询）；未登录也可用
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/shop.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '仅支持 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}
csrf_check();

$ids = $_POST['product_ids'] ?? null;
if (is_string($ids)) {
    $decoded = json_decode($ids, true);
    $ids = is_array($decoded) ? $decoded : [];
}
echo json_encode(recent_view_products(is_array($ids) ? $ids : []), JSON_UNESCAPED_UNICODE);
