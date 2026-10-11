<?php
require_once __DIR__ . '/inc/auth.php';
require_permission('dashboard.view');
$page_title = '仪表盘';

// 商品统计
$productStats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'on_sale' => 0];
$res = $conn->query("SELECT audit_status, COUNT(*) AS cnt FROM products GROUP BY audit_status");
while ($row = $res->fetch_assoc()) {
    if (isset($productStats[$row['audit_status']])) $productStats[$row['audit_status']] = intval($row['cnt']);
}
if ($r = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE status = 1")->fetch_assoc()) $productStats['on_sale'] = intval($r['cnt']);
if ($r = $conn->query("SELECT COUNT(*) AS cnt FROM products")->fetch_assoc()) $productStats['total'] = intval($r['cnt']);

// 用户统计
$userStats = ['total' => 0, 'user' => 0, 'merchant' => 0];
$res = $conn->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
while ($row = $res->fetch_assoc()) {
    if (isset($userStats[$row['role']])) $userStats[$row['role']] = intval($row['cnt']);
}
if ($r = $conn->query("SELECT COUNT(*) AS cnt FROM users")->fetch_assoc()) $userStats['total'] = intval($r['cnt']);

// 最近 10 条审核记录
// 注：audit_records.admin_id 历史上指向 users.id；新的后台审核详情记录在 admin_audit_logs
$recentAudits = rows("SELECT r.*, p.title, COALESCE(a.username,u.username) AS admin_name
    FROM audit_records r
    LEFT JOIN products p ON r.product_id = p.id
    LEFT JOIN users u ON u.id = r.admin_id
    LEFT JOIN admin_users a ON a.id = r.admin_user_id
    ORDER BY r.created_at DESC LIMIT 10");

// 各分类商品数量
$categoryStats = rows("SELECT c.category_name, COUNT(p.id) AS cnt
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id, c.category_name
    ORDER BY cnt DESC");

$audit_badge = [
    'pending'  => '<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:12px;font-size:12px;">待审核</span>',
    'approved' => '<span style="background:#d1fae5;color:#047857;padding:2px 8px;border-radius:12px;font-size:12px;">已通过</span>',
    'rejected' => '<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:12px;font-size:12px;">已驳回</span>',
];
require __DIR__ . '/inc/header.php';
?>

<style>
    .dash-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0; }
    .dash-card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .dash-card .label { font-size: 13px; color: #64748b; margin-bottom: 8px; }
    .dash-card .value { font-size: 28px; font-weight: 700; color: #0f172a; }
    .dash-card .value.blue { color: #2563eb; }
    .dash-card .value.amber { color: #d97706; }
    .dash-card .value.green { color: #059669; }
    .dash-card .value.red { color: #dc2626; }
    .dash-section { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .dash-section h3 { margin: 0 0 16px; font-size: 16px; color: #0f172a; }
    .dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .cat-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .cat-bar .name { width: 80px; font-size: 13px; color: #475569; text-align: right; flex-shrink: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cat-bar .bar { height: 22px; background: #3b82f6; border-radius: 4px; min-width: 20px; }
    .cat-bar .cnt { font-size: 12px; color: #64748b; flex-shrink: 0; }
    @media (max-width: 800px) { .dash-grid { grid-template-columns: 1fr; } }
</style>

<div style="padding: 20px 0;">
    <h2 class="admin-page-title">仪表盘</h2>
    <p class="admin-page-sub">欢迎回来，<?php echo h($_SESSION['admin']['username']); ?>！以下是平台运营概览。</p>

    <div class="dash-cards">
        <div class="dash-card">
            <div class="label">商品总数</div>
            <div class="value blue"><?php echo $productStats['total']; ?></div>
        </div>
        <div class="dash-card">
            <div class="label">待审核</div>
            <div class="value amber">
                <?php echo $productStats['pending']; ?>
                <?php if ($productStats['pending'] > 0 && admin_has_permission('product.audit')): ?>
                    <a href="product_audit.php?tab=pending" style="font-size:12px;color:#d97706;text-decoration:none;margin-left:6px;">去审核 →</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="dash-card">
            <div class="label">已通过（在售）</div>
            <div class="value green"><?php echo $productStats['approved']; ?> (<?php echo $productStats['on_sale']; ?>)</div>
        </div>
        <div class="dash-card">
            <div class="label">已驳回</div>
            <div class="value red"><?php echo $productStats['rejected']; ?></div>
        </div>
        <div class="dash-card">
            <div class="label">前台用户</div>
            <div class="value blue"><?php echo $userStats['total']; ?></div>
        </div>
        <div class="dash-card">
            <div class="label">商家数</div>
            <div class="value"><?php echo $userStats['merchant']; ?></div>
        </div>
    </div>

    <div class="dash-grid">
        <div class="dash-section">
            <h3>最近审核记录<?php if (admin_has_permission('product.audit')): ?> <a href="product_audit.php?tab=records" style="font-size:12px;color:#2563eb;font-weight:normal;text-decoration:none;">查看全部 →</a><?php endif; ?></h3>
            <?php if (empty($recentAudits)): ?>
                <div style="text-align:center;color:#94a3b8;padding:30px 0;">暂无审核记录</div>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <th style="padding:8px 6px;text-align:left;color:#64748b;font-weight:600;">商品</th>
                            <th style="padding:8px 6px;text-align:left;color:#64748b;font-weight:600;">结果</th>
                            <th style="padding:8px 6px;text-align:left;color:#64748b;font-weight:600;">审核人</th>
                            <th style="padding:8px 6px;text-align:left;color:#64748b;font-weight:600;">时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAudits as $r): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px 6px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo h($r['title'] ?? '（已删除）'); ?></td>
                                <td style="padding:8px 6px;"><?php echo $audit_badge[$r['result']] ?? h($r['result']); ?></td>
                                <td style="padding:8px 6px;color:#64748b;"><?php echo h($r['admin_name'] ?? '—'); ?></td>
                                <td style="padding:8px 6px;color:#94a3b8;font-size:12px;"><?php echo h($r['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="dash-section">
            <h3>分类商品分布</h3>
            <?php if (empty($categoryStats)): ?>
                <div style="text-align:center;color:#94a3b8;padding:30px 0;">暂无分类数据</div>
            <?php else: ?>
                <?php $maxCnt = max(array_column($categoryStats, 'cnt')); ?>
                <?php foreach ($categoryStats as $c): ?>
                    <div class="cat-bar">
                        <div class="name" title="<?php echo h($c['category_name']); ?>"><?php echo h($c['category_name']); ?></div>
                        <div class="bar" style="width:<?php echo $maxCnt > 0 ? round(($c['cnt'] / $maxCnt) * 150) : 0; ?>px;"></div>
                        <div class="cnt"><?php echo (int)$c['cnt']; ?> 件</div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
