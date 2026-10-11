<?php
require_once __DIR__ . '/inc/auth.php';
require_permission('product.audit');
$page_title = '商品审核';

// ==================== 商品审核中心（管理员） ====================
$msg = '';
$msg_type = '';
$admin_id = null; // 历史字段指向前台 users，新管理员另存 admin_user_id

// 处理单个审核动作（通过 / 驳回）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['audit_action'], $_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
    $audit_action = $_POST['audit_action'] === 'approve' ? 'approved' : 'rejected';
    $comment = trim($_POST['comment'] ?? '');

    if (!in_array($_POST['audit_action'], ['approve','reject'], true) || mb_strlen($comment) > 255 || ($audit_action === 'rejected' && $comment === '')) {
        $msg = '驳回时请填写审核意见，方便商家修改！';
        $msg_type = 'error';
    } else {
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;

            $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND audit_status = 'pending' FOR UPDATE");
            $stmt->bind_param('i', $product_id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                $conn->commit();
                $transactionStarted = false;
                $msg = '商品不存在、已审核或状态已变化，请刷新后重试。';
                $msg_type = 'error';
            } else {
                $sql = $audit_action === 'approved'
                    ? "UPDATE products SET audit_status = 'approved' WHERE id = ? AND audit_status = 'pending'"
                    : "UPDATE products SET audit_status = 'rejected', status = 0 WHERE id = ? AND audit_status = 'pending'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $product_id);
                $stmt->execute();
                $updated = $stmt->affected_rows === 1;
                $stmt->close();

                if (!$updated) {
                    $conn->commit();
                    $transactionStarted = false;
                    $msg = '商品状态已变化，请刷新后重试。';
                    $msg_type = 'error';
                } else {
                    $stmt = $conn->prepare("INSERT INTO audit_records (product_id, result, comment, admin_id, admin_user_id) VALUES (?, ?, ?, ?, ?)");
                    $new_admin_id = (int)$_SESSION['admin']['id'];
                    $stmt->bind_param('issii', $product_id, $audit_action, $comment, $admin_id, $new_admin_id);
                    audit_log('product.audit.' . $audit_action, 'product', $product_id, ['comment' => $comment], 'success', true);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $transactionStarted = false;
                    $msg = $audit_action === 'approved' ? '商品已通过审核，可正常上架展示！' : '商品已驳回并自动下架！';
                    $msg_type = 'success';
                }
            }
        } catch (Exception $e) {
            if ($transactionStarted) $conn->rollback();
            audit_log('product.audit.failed', 'product', $product_id, [], 'failure');
            error_log('single_product_audit_failed: ' . $e->getMessage());
            $msg = '审核操作失败，请稍后重试。';
            $msg_type = 'error';
        }
    }
}

// 当前页签
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'approved', 'rejected', 'records'], true)) {
    $tab = 'pending';
}

// 日期筛选
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// 各状态商品数量（角标）
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$res = $conn->query("SELECT audit_status, COUNT(*) AS cnt FROM products GROUP BY audit_status");
while ($row = $res->fetch_assoc()) {
    if (isset($counts[$row['audit_status']])) $counts[$row['audit_status']] = intval($row['cnt']);
}

// 查询当前页签数据
$rows = [];
if ($tab === 'records') {
    $where = "1=1";
    $params_r = [];
    $types_r = "";
    if ($dateFrom) { $where .= " AND r.created_at >= ?"; $params_r[] = $dateFrom . ' 00:00:00'; $types_r .= "s"; }
    if ($dateTo)   { $where .= " AND r.created_at <= ?"; $params_r[] = $dateTo . ' 23:59:59';   $types_r .= "s"; }
    $sql = "SELECT r.*, p.title, p.image_url, COALESCE(a.username,u.username) AS admin_name
            FROM audit_records r
            LEFT JOIN products p ON r.product_id = p.id
            LEFT JOIN users u ON r.admin_id = u.id LEFT JOIN admin_users a ON r.admin_user_id = a.id
            WHERE {$where}
            ORDER BY r.id DESC LIMIT 200";
    $stmt = $conn->prepare($sql);
    if (!empty($params_r)) $stmt->bind_param($types_r, ...$params_r);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $where = "p.audit_status = ?";
    $params_p = [$tab];
    $types_p = "s";
    if ($dateFrom) { $where .= " AND p.created_at >= ?"; $params_p[] = $dateFrom . ' 00:00:00'; $types_p .= "s"; }
    if ($dateTo)   { $where .= " AND p.created_at <= ?"; $params_p[] = $dateTo . ' 23:59:59';   $types_p .= "s"; }
    $sql = "SELECT p.*, c.category_name, s.shop_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN shops s ON p.shop_id = s.id
            WHERE {$where}
            ORDER BY " . ($tab === 'pending' ? 'p.id ASC' : 'p.id DESC');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types_p, ...$params_p);
    $stmt->execute();
    $result = $stmt->get_result();
}
while ($row = $result->fetch_assoc()) $rows[] = $row;

// 当前商品的最近一条审核意见
$last_comments = [];
if (!empty($rows) && $tab !== 'records') {
    $ids = array_column($rows, 'id');
    $id_list = implode(',', array_map('intval', $ids));
    $rres = $conn->query("SELECT product_id, comment FROM audit_records WHERE product_id IN ({$id_list}) ORDER BY id DESC");
    while ($r = $rres->fetch_assoc()) {
        if (!isset($last_comments[$r['product_id']])) $last_comments[$r['product_id']] = $r['comment'];
    }
}

$audit_badge = [
    'pending'  => '<span style="background:#fef3c7;color:#b45309;padding:3px 10px;border-radius:12px;font-size:12px;">待审核</span>',
    'approved' => '<span style="background:#d1fae5;color:#047857;padding:3px 10px;border-radius:12px;font-size:12px;">已通过</span>',
    'rejected' => '<span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:12px;font-size:12px;">已驳回</span>',
];
$tab_titles = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回', 'records' => '审核记录'];
?>

<?php require __DIR__ . '/inc/header.php'; ?>
<style>
    .audit-tabs { display: flex; gap: 10px; margin: 20px 0 5px; }
    .audit-tabs a { padding: 8px 18px; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 500; background: #fff; color: #64748b; border: 1px solid #e2e8f0; }
    .audit-tabs a.active { background: #0f172a; color: #fff; border-color: #0f172a; }
    .audit-tabs .badge { background: #ef4444; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 12px; margin-left: 4px; }
    .audit-tabs .badge.gray { background: #94a3b8; }
    .prod-img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; border: 1px solid #e2e8f0; }
    .btn-pass { background: #10b981; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .btn-pass:hover { background: #059669; }
    .btn-reject { background: #ef4444; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .btn-reject:hover { background: #dc2626; }
    .btn-history { background: #6366f1; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .btn-history:hover { background: #4f46e5; }
    .comment-input { width: 180px; padding: 6px 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; }
    .reject-reason { color: #b91c1c; font-size: 12px; margin-top: 4px; }
    .empty-tip { text-align: center; color: #94a3b8; padding: 40px 0; background: #fff; border-radius: 8px; margin-top: 15px; border: 1px solid #e2e8f0; }
    .bulk-bar { display: flex; gap: 12px; align-items: center; margin-top: 15px; padding: 12px 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
    .bulk-bar .selected-count { color: #64748b; font-size: 13px; }
    .date-filter { display: flex; gap: 10px; align-items: center; margin: 12px 0 0; flex-wrap: wrap; }
    .date-filter label { font-size: 13px; color: #64748b; }
    .date-filter input[type="date"] { padding: 5px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; }
    .date-filter button { padding: 5px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; background: #0f172a; color: #fff; }
    .date-filter a { font-size: 13px; color: #2563eb; text-decoration: none; }
    /* 模态框 */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
    .modal-overlay.active { display: flex; }
    .modal-box { background: #fff; border-radius: 12px; padding: 24px; width: 600px; max-width: 90vw; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
    .modal-box h3 { margin: 0 0 16px; font-size: 16px; color: #0f172a; }
    .modal-close { float: right; background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8; }
    .modal-close:hover { color: #ef4444; }
    .history-row { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .history-row:last-child { border-bottom: none; }
    .history-row .time { color: #94a3b8; font-size: 12px; }
    .history-row .admin { color: #64748b; }
    .cb-cell { width: 36px; text-align: center; }
    .cb-cell input { width: 16px; height: 16px; cursor: pointer; }
</style>

<div style="padding: 20px 0;">
    <h2 style="margin: 10px 0 5px; color:#0f172a;">商品审核中心</h2>
    <p style="color:#64748b; font-size:14px; margin:0;">审核商家发布的商品，通过后才会在前台展示。支持批量操作和审核历史查看。</p>

    <?php if ($msg): ?>
        <div id="msgBox" style="margin-top:12px; padding:12px 16px; border-radius:8px; font-size:14px;
            <?php echo $msg_type === 'success' ? 'background:#d1fae5;color:#047857;' : 'background:#fee2e2;color:#b91c1c;'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
    <!-- AJAX 操作提示容器 -->
    <div id="ajaxMsg" style="display:none;margin-top:12px;padding:12px 16px;border-radius:8px;font-size:14px;"></div>

    <div class="audit-tabs">
        <?php foreach (['pending', 'approved', 'rejected', 'records'] as $t): ?>
            <a href="product_audit.php?tab=<?php echo $t; ?>" class="<?php echo $tab === $t ? 'active' : ''; ?>">
                <?php echo $tab_titles[$t]; ?>
                <?php if (isset($counts[$t]) && $counts[$t] > 0): ?>
                    <span class="badge <?php echo $t === 'pending' ? '' : 'gray'; ?>"><?php echo $counts[$t]; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- 日期范围筛选 -->
    <div class="date-filter">
        <label>日期范围：</label>
        <form method="GET" action="product_audit.php" style="display:flex;gap:8px;align-items:center;margin:0;">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            <span style="color:#94a3b8;">至</span>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            <button type="submit">筛选</button>
            <?php if ($dateFrom || $dateTo): ?>
                <a href="product_audit.php?tab=<?php echo $tab; ?>">清除</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($rows)): ?>
        <div class="empty-tip"><?php echo $tab === 'pending' ? '暂无待审核的商品' : '暂无数据'; ?></div>
    <?php elseif ($tab === 'records'): ?>
        <table class="crud-table">
            <tr>
                <th>ID</th><th>商品</th><th>审核结果</th><th>审核意见</th><th>审核人</th><th>审核时间</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo intval($r['id']); ?></td>
                    <td><?php echo htmlspecialchars($r['title'] ?? '（商品已删除）'); ?></td>
                    <td><?php echo $audit_badge[$r['result']]; ?></td>
                    <td><?php echo htmlspecialchars($r['comment'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['admin_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div id="bulkForm">
        <table class="crud-table">
            <tr>
                <?php if ($tab === 'pending'): ?><th class="cb-cell"><input type="checkbox" id="selectAll" title="全选"></th><?php endif; ?>
                <th>图片</th><th>商品名称</th><th>店铺</th><th>分类</th><th>价格</th><th>库存</th><th>状态</th><th>操作</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <?php if ($tab === 'pending'): ?><td class="cb-cell"><input type="checkbox" class="row-cb" value="<?php echo intval($r['id']); ?>"></td><?php endif; ?>
                    <td>
                        <?php if (!empty($r['image_url'])): ?>
                            <img class="prod-img" src="../<?php echo htmlspecialchars($r['image_url']); ?>" alt="">
                        <?php else: ?>
                            <span style="color:#cbd5e1;font-size:12px;">无图</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="../detail.php?id=<?php echo intval($r['id']); ?>" target="_blank" style="color:#2563eb;text-decoration:none;font-weight:600;">
                            <?php echo htmlspecialchars($r['title']); ?>
                        </a>
                        <?php if ($tab === 'rejected' && !empty($last_comments[$r['id']])): ?>
                            <div class="reject-reason">驳回原因：<?php echo htmlspecialchars($last_comments[$r['id']]); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['shop_name'] ?? '平台自营'); ?></td>
                    <td><?php echo htmlspecialchars($r['category_name'] ?? '未分类'); ?></td>
                    <td>¥<?php echo htmlspecialchars($r['price']); ?></td>
                    <td><?php echo intval($r['stock']); ?></td>
                    <td><?php echo $audit_badge[$r['audit_status']]; ?></td>
                    <td>
                        <?php if ($tab === 'pending'): ?>
                            <form method="post" action="product_audit.php?tab=pending" style="display:inline-block;"><?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo intval($r['id']); ?>">
                                <input class="comment-input" type="text" name="comment" placeholder="审核意见（驳回必填）">
                                <button class="btn-pass" type="submit" name="audit_action" value="approve">通过</button>
                                <button class="btn-reject" type="submit" name="audit_action" value="reject">驳回</button>
                            </form>
                        <?php endif; ?>
                        <button class="btn-history" type="button" onclick="showHistory(<?php echo intval($r['id']); ?>, '<?php echo addslashes(htmlspecialchars($r['title'])); ?>')">历史</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php if ($tab === 'pending'): ?>
            <!-- 批量操作栏 -->
            <div class="bulk-bar">
                <span class="selected-count">已选 <strong id="selectedCount">0</strong> 件</span>
                <button class="btn-pass" type="button" onclick="bulkAction('approve')">批量通过</button>
                <input class="comment-input" type="text" id="bulkComment" placeholder="批量审核意见（驳回必填）" style="width:260px;">
                <button class="btn-reject" type="button" onclick="bulkAction('reject')">批量驳回</button>
            </div>
        <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 审核历史模态框 -->
<div class="modal-overlay" id="historyModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <h3 id="historyTitle">审核历史</h3>
        <div id="historyContent"><div style="text-align:center;color:#94a3b8;padding:20px;">加载中...</div></div>
    </div>
</div>

<script>
// 全选
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-cb').forEach(cb => cb.checked = this.checked);
    updateCount();
});
document.querySelectorAll('.row-cb').forEach(cb => {
    cb.addEventListener('change', updateCount);
});
function updateCount() {
    const count = document.querySelectorAll('.row-cb:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

// 获取已选商品 ID
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-cb:checked')).map(cb => parseInt(cb.value));
}

// 显示操作结果提示
function showAjaxMsg(text, success) {
    const el = document.getElementById('ajaxMsg');
    el.style.display = 'block';
    el.textContent = text;
    el.style.background = success ? '#d1fae5' : '#fee2e2';
    el.style.color = success ? '#047857' : '#b91c1c';
    setTimeout(() => { el.style.display = 'none'; }, 4000);
}

// 批量操作
function bulkAction(action) {
    const ids = getSelectedIds();
    if (ids.length === 0) { showAjaxMsg('请先选择商品', false); return; }
    if (ids.length > 100) { showAjaxMsg('单次最多审核 100 个商品，请分批操作', false); return; }
    const comment = document.getElementById('bulkComment').value.trim();
    if (Array.from(comment).length > 255) { showAjaxMsg('审核意见不能超过 255 个字符', false); return; }
    if (action === 'reject' && !comment) { showAjaxMsg('批量驳回时必须填写审核意见', false); return; }
    const label = action === 'approve' ? '通过' : '驳回';
    if (!confirm('确认将 ' + ids.length + ' 个商品批量' + label + '？')) return;

    fetch('api_bulk_audit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?php echo json_encode(csrf_token()); ?> },
        body: JSON.stringify({ product_ids: ids, action: action, comment: comment })
    })
    .then(res => res.json())
    .then(data => {
        showAjaxMsg(data.message, data.success === true);
        if (data.success === true) {
            setTimeout(() => location.reload(), 1200);
        }
    })
    .catch(() => showAjaxMsg('网络请求失败，请重试', false));
}

// 审核历史模态框
function showHistory(productId, title) {
    document.getElementById('historyTitle').textContent = '审核历史：' + title;
    document.getElementById('historyContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;">加载中...</div>';
    document.getElementById('historyModal').classList.add('active');

    fetch('api_audit_history.php?product_id=' + productId)
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('historyContent').innerHTML = '<div style="color:#b91c1c;padding:10px;">' + data.message + '</div>';
            return;
        }
        if (data.records.length === 0) {
            document.getElementById('historyContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;">暂无审核记录</div>';
            return;
        }
        let html = '';
        data.records.forEach(r => {
            const badgeColor = r.result === 'approved' ? '#d1fae5;color:#047857' : '#fee2e2;color:#b91c1c';
            const label = r.result === 'approved' ? '通过' : '驳回';
            html += '<div class="history-row">';
            html += '<span style="background:' + badgeColor.split(';')[0] + ';' + badgeColor.split(';')[1] + ';padding:2px 8px;border-radius:12px;font-size:12px;">' + label + '</span> ';
            html += '<span class="admin">' + (r.admin_name || '—') + '</span> ';
            html += '<span class="time">' + r.created_at + '</span>';
            if (r.comment) html += '<div style="margin-top:4px;color:#475569;">' + r.comment + '</div>';
            html += '</div>';
        });
        document.getElementById('historyContent').innerHTML = html;
    })
    .catch(() => {
        document.getElementById('historyContent').innerHTML = '<div style="color:#b91c1c;padding:10px;">加载失败</div>';
    });
}

function closeModal() {
    document.getElementById('historyModal').classList.remove('active');
}
// 点击遮罩关闭
document.getElementById('historyModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>



<?php require __DIR__ . '/inc/footer.php'; ?>
