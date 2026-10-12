<?php
// 商户经营数据看板（仅商户可访问；口径固定近 30 日，不提供筛选）
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if (($_SESSION['role'] ?? '')!=='merchant') { http_response_code(403); exit('仅商家可访问经营看板。'); }
$shop=one('SELECT * FROM shops WHERE user_id=? AND status=1',[$userId]);
if(!$shop) { http_response_code(403); exit('未找到有效店铺，无法查看经营看板。'); }
$shopId=(int)$shop['id'];
$validStatuses="status IN ('paid','shipped','completed')";

// 近 30 日按日汇总：订单数与销售额（total_amount - discount_amount，分）
$days=[]; // d => ['count'=>, 'amount_cents'=>]
for($i=29;$i>=0;$i--) $days[date('Y-m-d',strtotime("-$i day"))]=['count'=>0,'amount_cents'=>0];
$daily=rows("SELECT DATE(COALESCE(paid_at,created_at)) AS d,COUNT(*) AS n,ROUND(SUM(total_amount-discount_amount)*100) AS amount_cents
    FROM orders WHERE shop_id=? AND $validStatuses AND COALESCE(paid_at,created_at) >= CURDATE()-INTERVAL 29 DAY GROUP BY d",[$shopId]);
foreach($daily as $row){ $d=(string)$row['d']; if(isset($days[$d])) $days[$d]=['count'=>(int)$row['n'],'amount_cents'=>(int)$row['amount_cents']]; }
$totalOrders30=array_sum(array_column($days,'count'));
$totalSales30=array_sum(array_column($days,'amount_cents'));
$maxDaily=max(1,max(array_column($days,'amount_cents')));

// 商品维度：按销量 Top 5 与 Bottom 5
$soldRows=rows("SELECT COALESCE(NULLIF(i.product_title,''),p.title,'历史商品') AS title,SUM(i.quantity) AS sold
    FROM order_items i JOIN orders o ON o.id=i.order_id LEFT JOIN products p ON p.id=i.product_id
    WHERE o.shop_id=? AND o.$validStatuses GROUP BY i.product_id,COALESCE(NULLIF(i.product_title,''),p.title,'历史商品') ORDER BY sold DESC,i.product_id ASC",[$shopId]);
$top=array_slice($soldRows,0,5);
// 不足 5 个时直接取现有末尾；Bottom 为销量最低的 5 个（销量相同时按商品 id 升序与 Top 对齐）
$bottom=$soldRows?array_slice($soldRows,-(min(5,count($soldRows))),null,true):[];
$bottom=array_reverse($bottom,true); $bottom=array_values($bottom);

// 订单评分：本店商品 reviews 平均分与评价数
$rating=one('SELECT AVG(r.rating) AS avg_rating,COUNT(*) AS n FROM reviews r JOIN products p ON p.id=r.product_id WHERE p.shop_id=?',[$shopId]);
$avgRating=$rating?(float)$rating['avg_rating']:0.0; $reviewCount=$rating?(int)$rating['n']:0;

// 售后：待处理数量（refund + after_sale）
$pendingRefunds=(int)one("SELECT COUNT(*) AS n FROM refund_requests r JOIN orders o ON o.id=r.order_id WHERE o.shop_id=? AND r.status='pending'",[$shopId])['n'];
$pendingAfterSales=(int)one("SELECT COUNT(*) AS n FROM after_sale_requests a JOIN orders o ON o.id=a.order_id WHERE o.shop_id=? AND a.status='pending'",[$shopId])['n'];

// 近 30 日退款率：退款/售后单数 ÷ 支付单数（百分比保留 1 位）
$refundCount30=(int)one("SELECT (SELECT COUNT(*) FROM refund_requests r JOIN orders o ON o.id=r.order_id WHERE o.shop_id=? AND r.created_at >= CURDATE()-INTERVAL 29 DAY)
    +(SELECT COUNT(*) FROM after_sale_requests a JOIN orders o ON o.id=a.order_id WHERE o.shop_id=? AND a.created_at >= CURDATE()-INTERVAL 29 DAY) AS n",[$shopId,$shopId])['n'];
$refundRate=$totalOrders30>0?round($refundCount30/$totalOrders30*100,1):0.0;

page_start('经营数据看板');
$lastMonth=date('Y-m',strtotime('-1 month'));
echo '<p><a href="product_list.php">返回店铺管理</a> · <a href="shop_export.php?month='.h($lastMonth).'">导出上月对账单（'.h($lastMonth).'）</a></p>';
?>
<section class="card"><h2>近 30 日概览</h2>
<div class="grid">
<div><h3>销售额（30 日）</h3><p class="price">¥ <?=h(money($totalSales30))?></p></div>
<div><h3>订单数（30 日）</h3><p class="stat"><?=($totalOrders30)?></p></div>
<div><h3>商品评分</h3><p class="stat"><?=h($reviewCount?number_format($avgRating,1):'暂无')?><?=($reviewCount?' 分':'')?></p><small><?=($reviewCount)?> 条评价</small></div>
<div><h3>退款率（30 日）</h3><p class="stat"><?=h(number_format($refundRate,1))?>%</p><small>退款/售后 <?=($refundCount30)?> 单 · 支付 <?=($totalOrders30)?> 单</small></div>
</div></section>

<section class="card"><h2>近 30 日销售额趋势</h2>
<?php if($totalSales30>0): ?><div class="bars"><?php foreach($days as $d=>$day): ?><div class="bar-item"><small class="bar-label"><?=h(substr($d,5))?></small><div class="bar" title="<?=h($d)?> · 销售额 ¥<?=h(money($day['amount_cents']))?> · <?=((int)$day['count'])?> 单" style="width:<?=($day['amount_cents']>0?max(2,(int)round($day['amount_cents']/$maxDaily*100)):0)?>%"></div></div><?php endforeach; ?></div>
<p class="muted">柱宽按日销售额归一化，悬停可查看日期、金额与单数。</p>
<?php else: ?><p class="muted">近 30 日暂无有效订单数据。</p><?php endif; ?></section>

<section class="card"><h2>商品销量排行</h2>
<?php if(!$soldRows): ?><p class="muted">暂无销量数据。</p><?php else: ?>
<div class="grid"><div><h3>热销 Top 5</h3><table><tr><th>商品</th><th>销量</th></tr><?php foreach($top as $row): ?><tr><td><?=h($row['title'])?></td><td><?=((int)$row['sold'])?></td></tr><?php endforeach; ?></table></div>
<div><h3>滞销 Bottom 5</h3><table><tr><th>商品</th><th>销量</th></tr><?php foreach($bottom as $row): ?><tr><td><?=h($row['title'])?></td><td><?=((int)$row['sold'])?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?></section>

<section class="card"><h2>售后待处理</h2>
<p>待处理退款申请：<strong><?=($pendingRefunds)?></strong> 单<?php if($pendingRefunds): ?> · <a href="admin_orders.php?status=paid">去处理</a><?php endif; ?></p>
<p>待处理退货退款：<strong><?=($pendingAfterSales)?></strong> 单<?php if($pendingAfterSales): ?> · <a href="admin_orders.php?status=all">去处理</a><?php endif; ?></p>
</section>
<?php page_end(); ?>
