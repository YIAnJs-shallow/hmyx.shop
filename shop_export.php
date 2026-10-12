<?php
// 商户对账单导出：按自然月导出本店订单 CSV（仅商户，权限判定同 shop_dashboard.php）
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if (($_SESSION['role'] ?? '')!=='merchant') { http_response_code(403); exit('仅商家可导出对账单。'); }
$shop=one('SELECT * FROM shops WHERE user_id=? AND status=1',[$userId]);
if(!$shop) { http_response_code(403); exit('未找到有效店铺，无法导出对账单。'); }
$month=(string)($_GET['month'] ?? date('Y-m',strtotime('-1 month')));
if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) { http_response_code(400); exit('月份参数格式须为 Y-m，如 2026-09。'); }
$rows=shop_statement_rows((int)$shop['id'],$month);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="对账单_'.$month.'.csv"');
echo "\xEF\xBB\xBF"; // BOM 头，防止 Excel 中文乱码
$out=fopen('php://output','w');
foreach($rows as $row) fputcsv($out,$row);
fclose($out);
