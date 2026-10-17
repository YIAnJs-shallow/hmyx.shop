<?php
// 运营数据看板：近 7/30 天核心指标（订单数/GMV 实付口径/状态分布/支付方式分布/销量 TOP10/退款率），纯读聚合，逻辑在 lib/shop.php admin_stats_overview()
require __DIR__ . '/inc/auth.php';
require_permission('dashboard.view');
$page_title = '运营数据看板';

$stats = admin_stats_overview();
// 支付方式展示标签（未映射的原样展示，避免漏报新渠道）
$paymentLabels = ['demo' => '演示支付', 'online' => '在线支付', 'alipay_sandbox' => '支付宝沙箱', 'alipay' => '支付宝'];
$statusLabels = ['pending' => '待支付', 'paid' => '已支付', 'shipped' => '已发货', 'completed' => '已完成', 'cancelled' => '已取消'];
function stats_card($label, $value, $sub = '') {
    echo '<div style="flex:1;min-width:180px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;box-shadow:var(--shadow-sm);">';
    echo '<div style="color:#64748b;font-size:.85rem;">' . h($label) . '</div>';
    echo '<div style="font-size:1.5rem;font-weight:700;color:#0f172a;margin-top:6px;">' . h($value) . '</div>';
    if ($sub !== '') echo '<div style="color:#64748b;font-size:.8rem;margin-top:4px;">' . h($sub) . '</div>';
    echo '</div>';
}
require __DIR__ . '/inc/header.php'; ?>
<h1>运营数据看板</h1>
<p class="admin-page-sub">近 7 / 30 天核心经营指标。GMV 口径 = 订单实付（小计 − 券 − 积分抵现 − 会员折扣），仅统计已支付/已发货/已完成订单；窗口按下单时间计算。</p>

<h2>核心指标</h2>
<div style="display:flex;flex-wrap:wrap;gap:14px;">
    <?php
    stats_card('近 7 天订单数', $stats['last7_orders'], '其中已支付 ' . $stats['last7_paid_orders'] . ' 单');
    stats_card('近 7 天 GMV', '¥' . number_format($stats['last7_gmv_cents'] / 100, 2));
    stats_card('近 30 天订单数', $stats['last30_orders'], '其中已支付 ' . $stats['last30_paid_orders'] . ' 单');
    stats_card('近 30 天 GMV', '¥' . number_format($stats['last30_gmv_cents'] / 100, 2));
    stats_card('近 30 天退款率', $stats['refund_rate_30d'] === null ? '—' : $stats['refund_rate_30d'] . '%', '审批通过退款 ' . $stats['refund_approved_30d'] . ' 单');
    ?>
</div>

<h2>近 30 天订单状态分布</h2>
<table class="crud-table">
    <tr><th>状态</th><th>订单数</th><th>占比</th></tr>
    <?php $statusTotal = max(1, array_sum($stats['status_dist'])); ?>
    <?php foreach ($stats['status_dist'] as $st => $cnt): ?>
    <tr><td><?= h($statusLabels[$st] ?? $st) ?></td><td><?= (int)$cnt ?></td><td><?= round($cnt / $statusTotal * 100, 1) ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (!$stats['status_dist']): ?>
    <tr><td colspan="3">暂无数据</td></tr>
    <?php endif; ?>
</table>

<h2>近 30 天支付方式分布（已支付口径）</h2>
<table class="crud-table">
    <tr><th>支付方式</th><th>订单数</th></tr>
    <?php foreach ($stats['payment_dist'] as $pm => $cnt): ?>
    <tr><td><?= h($paymentLabels[$pm] ?? $pm) ?></td><td><?= (int)$cnt ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$stats['payment_dist']): ?>
    <tr><td colspan="2">暂无数据</td></tr>
    <?php endif; ?>
</table>

<h2>近 30 天销量 TOP10 商品</h2>
<table class="crud-table">
    <tr><th>商品</th><th>销量</th></tr>
    <?php foreach ($stats['top_products'] as $t): ?>
    <tr><td><?= h($t['title']) ?></td><td><?= (int)$t['sold'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$stats['top_products']): ?>
    <tr><td colspan="2">暂无数据</td></tr>
    <?php endif; ?>
</table>
<?php require __DIR__ . '/inc/footer.php'; ?>
