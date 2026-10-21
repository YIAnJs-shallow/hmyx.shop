<?php
// 秒杀楼层管理：进行中场次的商品/秒杀价/限量/已抢进度/场次时间/排序，上下架切换
// 金额分口径；秒杀为引流展示位，不挂接下单/资金路径（负面清单见 ROADMAP A+B 专项）
require __DIR__ . '/inc/auth.php';
require_permission('coupon.manage');
$page_title = '秒杀楼层管理';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $done = false;
    try {
        if ($action === 'save') {
            $sid = admin_save_seckill((int)($_POST['id'] ?? 0), (int)($_POST['product_id'] ?? 0), (int)($_POST['seckill_price_cents'] ?? 0), (int)($_POST['total_stock'] ?? 0), (int)($_POST['sold'] ?? 0), trim($_POST['start_at'] ?? ''), trim($_POST['end_at'] ?? ''), (int)($_POST['sort'] ?? 0));
            audit_log('seckill.save', 'seckill_item', $sid, ['product_id' => (int)($_POST['product_id'] ?? 0), 'seckill_price_cents' => (int)($_POST['seckill_price_cents'] ?? 0), 'total_stock' => (int)($_POST['total_stock'] ?? 0), 'sold' => (int)($_POST['sold'] ?? 0), 'start_at' => trim($_POST['start_at'] ?? ''), 'end_at' => trim($_POST['end_at'] ?? ''), 'sort' => (int)($_POST['sort'] ?? 0)], 'success', true);
            $done = true;
        } elseif ($action === 'toggle') {
            $tid = (int)($_POST['id'] ?? 0);
            admin_toggle_seckill($tid);
            audit_log('seckill.toggle', 'seckill_item', $tid, ['status' => one('SELECT status FROM seckill_items WHERE id=?', [$tid])['status'] ?? null], 'success', true);
            $done = true;
        }
    } catch (DomainException $e) { $error = $e->getMessage(); }
    if ($done) { header('Location: seckill.php', true, 303); exit; }
}

$list = rows('SELECT si.*, p.title FROM seckill_items si JOIN products p ON p.id = si.product_id ORDER BY si.id DESC');
$productOptions = rows('SELECT id, title FROM products ORDER BY id DESC LIMIT 500');
$editId = (int)($_GET['edit'] ?? 0);
$editItem = $editId ? one('SELECT * FROM seckill_items WHERE id=?', [$editId]) : null;
require __DIR__ . '/inc/header.php'; ?>
<h1>秒杀楼层管理</h1>
<?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
<h2><?= $editItem ? '编辑秒杀配置 #' . (int)$editItem['id'] : '新增秒杀配置' ?></h2>
<form method="post" class="crud-table"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editItem ? (int)$editItem['id'] : 0 ?>">
    <label>商品 <select name="product_id" required><option value="">选择商品</option><?php foreach ($productOptions as $po): ?><option value="<?=(int)$po['id']?>" <?= $editItem && (int)$editItem['product_id'] === (int)$po['id'] ? 'selected' : '' ?>><?= h(mb_substr($po['title'], 0, 30)) ?>（#<?=(int)$po['id']?>）</option><?php endforeach; ?></select></label>
    <label>秒杀价（分）<input name="seckill_price_cents" type="number" min="1" max="99999999" required value="<?= $editItem ? (int)$editItem['seckill_price_cents'] : '' ?>"></label>
    <label>活动限量（件）<input name="total_stock" type="number" min="1" max="1000000" required value="<?= $editItem ? (int)$editItem['total_stock'] : '' ?>"></label>
    <label>已抢件数（进度展示）<input name="sold" type="number" min="0" max="1000000" value="<?= $editItem ? (int)$editItem['sold'] : 0 ?>"></label>
    <label>开始时间 <input name="start_at" placeholder="2026-10-21 10:00:00" required value="<?= $editItem ? h($editItem['start_at']) : '' ?>"></label>
    <label>结束时间 <input name="end_at" placeholder="2026-10-21 22:00:00" required value="<?= $editItem ? h($editItem['end_at']) : '' ?>"></label>
    <label>排序（小在前）<input name="sort" type="number" value="<?= $editItem ? (int)$editItem['sort'] : 0 ?>"></label>
    <button><?= $editItem ? '保存修改' : '创建' ?></button>
</form>
<?php if ($editItem): ?><p><a href="seckill.php">← 取消编辑，返回新增</a></p><?php endif; ?>
<h2>配置列表（<?= count($list) ?>）</h2>
<table class="crud-table">
    <tr><th>ID</th><th>商品</th><th>秒杀价</th><th>进度</th><th>场次</th><th>排序</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($list as $si): ?>
    <tr>
        <td><?=(int)$si['id']?></td>
        <td><?= h(mb_substr($si['title'], 0, 24)) ?>（#<?=(int)$si['product_id']?>）</td>
        <td>¥<?= number_format($si['seckill_price_cents'] / 100, 2) ?></td>
        <td><?= (int)$si['sold'] ?>/<?= (int)$si['total_stock'] ?></td>
        <td class="small"><?= h($si['start_at']) ?> ~ <?= h($si['end_at']) ?></td>
        <td><?=(int)$si['sort']?></td>
        <td><?= (int)$si['status'] === 1 ? '上架' : '下架' ?></td>
        <td>
            <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$si['id']?>"><button><?= (int)$si['status'] === 1 ? '下架' : '上架' ?></button></form>
            <a class="small" href="seckill.php?edit=<?=(int)$si['id']?>">编辑</a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="8">暂无配置</td></tr><?php endif; ?>
</table>
<?php require __DIR__ . '/inc/footer.php'; ?>
