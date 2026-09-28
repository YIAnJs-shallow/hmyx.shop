<?php
require_once 'admin_header.php';
require_once 'config/db.php';

// ==================== 管理员仪表盘 ====================

// 商品统计
$productStats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'on_sale' => 0];
$res = $conn->query("SELECT audit_status, COUNT(*) AS cnt FROM products GROUP BY audit_status");
while ($row = $res->fetch_assoc()) {
    if (isset($productStats[$row['audit_status']])) $productStats[$row['audit_status']] = intval($row['cnt']);
}
$res2 = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE status = 1");
if ($r = $res2->fetch_assoc()) $productStats['on_sale'] = intval($r['cnt']);
$res3 = $conn->query("SELECT COUNT(*) AS cnt FROM products");
if ($r = $res3->fetch_assoc()) $productStats['total'] = intval($r['cnt']);

// 用户统计
$userStats = ['total' => 0, 'user' => 0, 'merchant' => 0, 'admin' => 0];
$res = $conn->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
while ($row = $res->fetch_assoc()) {
    if (isset($userStats[$row['role']])) $userStats[$row['role']] = intval($row['cnt']);
}
$res2 = $conn->query("SELECT COUNT(*) AS cnt FROM users");
if ($r = $res2->fetch_assoc()) $userStats['total'] = intval($r['cnt']);

// 最近 10 条审核记录
$recentAudits = [];
$res = $conn->query("SELECT r.*, p.title, p.image_url, u.username AS admin_name
    FROM audit_records r
    LEFT JOIN products p ON r.product_id = p.id
    LEFT JOIN users u ON r.admin_id = u.id
    ORDER BY r.created_at DESC LIMIT 10");
while ($row = $res->fetch_assoc()) $recentAudits[] = $row;

// 各分类商品数量
$categoryStats = [];
$res = $conn->query("SELECT c.category_name, COUNT(p.id) AS cnt
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id, c.category_name
    ORDER BY cnt DESC");
while ($row = $res->fetch_assoc()) $categoryStats[] = $row;

$audit_badge = [
    'pending'  => '<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:12px;font-size:12px;">待审核</span>',
    'approved' => '<span style="background:#d1fae5;color:#047857;padding:2px 8px;border-radius:12px;font-size:12px;">已通过</span>',
    'rejected' => '<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:12px;font-size:12px;">已驳回</span>',
];
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
    .cat-bar .bar { height: 22px; background: #3b82f6; border-radius: 4px; min-width: 20px; transition: width 0.3s; }
    .cat-bar .cnt { font-size: 12px; color: #64748b; flex-shrink: 0; }
</style>

<div style="padding: 20px 0;">
    <h2 style="margin: 10px 0 5px; color:#0f172a;">仪表盘</h2>
    <p style="color:#64748b; font-size:14px; margin:0;">欢迎回来，<?php echo htmlspecialchars($_SESSION['username']); ?>！以下是平台运营概览。</p>

    <!-- 核心数据卡片 -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="label">商品总数</div>
            <div class="value blue"><?php echo $productStats['total']; ?></div>
        </div>
        <div class="dash-card">
            <div class="label">待审核</div>
            <div class="value amber">
                <?php echo $productStats['pending']; ?>
                <?php if ($productStats['pending'] > 0): ?>
                    <a href="admin_audit.php?tab=pending" style="font-size:12px;color:#d97706;text-decoration:none;margin-left:6px;">去审核 →</a>
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
            <div class="label">用户总数</div>
            <div class="value blue"><?php echo $userStats['total']; ?></div>
        </div>
        <div class="dash-card">
            <div class="label">商家数</div>
            <div class="value"><?php echo $userStats['merchant']; ?></div>
        </div>
    </div>

    <!-- 下半部分：审核记录 + 分类分布 -->
    <div class="dash-grid">
        <!-- 最近审核记录 -->
        <div class="dash-section">
            <h3>最近审核记录 <a href="admin_audit.php?tab=records" style="font-size:12px;color:#2563eb;font-weight:normal;text-decoration:none;">查看全部 →</a></h3>
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
                                <td style="padding:8px 6px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($r['title'] ?? '（已删除）'); ?></td>
                                <td style="padding:8px 6px;"><?php echo $audit_badge[$r['result']]; ?></td>
                                <td style="padding:8px 6px;color:#64748b;"><?php echo htmlspecialchars($r['admin_name'] ?? '—'); ?></td>
                                <td style="padding:8px 6px;color:#94a3b8;font-size:12px;"><?php echo htmlspecialchars($r['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- 分类商品分布 -->
        <div class="dash-section">
            <h3>分类商品分布</h3>
            <?php if (empty($categoryStats)): ?>
                <div style="text-align:center;color:#94a3b8;padding:30px 0;">暂无分类数据</div>
            <?php else: ?>
                <?php $maxCnt = max(array_column($categoryStats, 'cnt')); ?>
                <?php foreach ($categoryStats as $c): ?>
                    <div class="cat-bar">
                        <div class="name" title="<?php echo htmlspecialchars($c['category_name']); ?>"><?php echo htmlspecialchars($c['category_name']); ?></div>
                        <div class="bar" style="width:<?php echo $maxCnt > 0 ? round(($c['cnt'] / $maxCnt) * 150) : 0; ?>px;"></div>
                        <div class="cnt"><?php echo $c['cnt']; ?> 件</div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>
</body>
</html>
