<?php
// 优惠券管理：创建（标题/面额/门槛/总量/有效期/新人券标记）、上下架切换、领取与核销统计
// 金额一律以分为单位（与 lib/coupon.php 约定一致）；领券/核销/回补逻辑不在本页改动范围
require __DIR__ . '/inc/auth.php';
require_permission('coupon.manage');
$page_title = '优惠券管理';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        try {
            $scopePid = (int)($_POST['scope_product_id'] ?? 0);
            $couponId = admin_create_coupon($_POST['title'] ?? '', $_POST['discount_cents'] ?? 0, $_POST['min_spend_cents'] ?? 0, $_POST['total_count'] ?? 0, ($_POST['is_newuser'] ?? 0) === '1' ? 1 : 0, $_POST['expire_at'] ?? '', $scopePid > 0 ? 'product' : 'all', $scopePid, $_POST['valid_days'] ?? '');
            audit_log('coupon.create', 'coupon', $couponId, ['title' => trim($_POST['title'] ?? ''), 'discount_cents' => (int)($_POST['discount_cents'] ?? 0), 'total_count' => (int)($_POST['total_count'] ?? 0), 'scope' => $scopePid > 0 ? 'product' : 'all', 'product_id' => $scopePid, 'valid_days' => $_POST['valid_days'] ?? ''], 'success', true);
            header('Location: coupons.php', true, 303); exit;
        } catch (DomainException $e) { $error = $e->getMessage(); }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = query('UPDATE coupons SET active = 1 - active WHERE id=?', [$id]);
        if ($stmt->affected_rows === 1) audit_log('coupon.toggle', 'coupon', $id, ['active' => one('SELECT active FROM coupons WHERE id=?', [$id])['active'] ?? null], 'success', true);
        header('Location: coupons.php', true, 303); exit;
    }
    header('Location: coupons.php', true, 303); exit;
}

$list = rows('SELECT c.*, p.title AS scope_product_title,
                     (SELECT COUNT(*) FROM user_coupons uc WHERE uc.coupon_id = c.id) AS claimed,
                     (SELECT COUNT(*) FROM user_coupons uc WHERE uc.coupon_id = c.id AND uc.status = "used") AS used
              FROM coupons c LEFT JOIN products p ON p.id = c.product_id ORDER BY c.id DESC');
require __DIR__ . '/inc/header.php'; ?>
<h1>优惠券管理</h1>
<?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
<h2>创建优惠券</h2>
<form method="post" class="crud-table"><?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>标题 <input name="title" maxlength="100" required></label>
    <label>面额（分）<input name="discount_cents" type="number" min="1" max="99999999" required></label>
    <label>最低消费（分，0 为无门槛）<input name="min_spend_cents" type="number" min="0" max="99999999" value="0" required></label>
    <label>发放总量（张）<input name="total_count" type="number" min="1" max="1000000" required></label>
    <label>有效期（空 = 永不过期）<input name="expire_at" placeholder="2026-12-31 23:59:59"></label>
    <label>适用商品 <select name="scope_product_id"><option value="0">全场通用</option><?php $productOptions = rows('SELECT id,title FROM products ORDER BY id DESC LIMIT 500'); foreach ($productOptions as $po): ?><option value="<?=(int)$po['id']?>"><?= h(mb_substr($po['title'], 0, 30)) ?></option><?php endforeach; ?></select></label>
    <label>领取后有效天数（空 = 永久）<input name="valid_days" type="number" min="1" max="3650"></label>
    <label class="inline"><input type="checkbox" name="is_newuser" value="1"> 新用户注册自动发放</label>
    <button>创建</button>
</form>
<h2>券列表</h2>
<table class="crud-table">
    <tr><th>编号</th><th>标题</th><th>面额</th><th>门槛</th><th>已领/总量</th><th>已核销</th><th>新人券</th><th>有效期</th><th>适用范围</th><th>领取有效</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($list as $c): ?>
    <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><?= h($c['title']) ?></td>
        <td>¥ <?= money((int)$c['discount_cents']) ?></td>
        <td><?= (int)$c['min_spend_cents'] > 0 ? '¥ ' . money((int)$c['min_spend_cents']) : '无' ?></td>
        <td><?= (int)$c['claimed'] ?> / <?= (int)$c['total_count'] ?></td>
        <td><?= (int)$c['used'] ?></td>
        <td><?= (int)$c['is_newuser'] ? '是' : '否' ?></td>
        <td><?= $c['expire_at'] ? h($c['expire_at']) : '永久' ?></td>
        <td><?= ($c['scope'] ?? 'all') === 'product' ? '指定：' . h($c['scope_product_title'] ?? ('#' . (int)$c['product_id'])) : '全场' ?></td>
        <td><?= (int)$c['valid_days'] > 0 ? (int)$c['valid_days'] . ' 天' : '永久' ?></td>
        <td><?= (int)$c['active'] ? '上架' : '下架' ?></td>
        <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button><?= (int)$c['active'] ? '下架' : '上架' ?></button></form></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="12">暂无优惠券</td></tr><?php endif; ?>
</table>
<?php require __DIR__ . '/inc/footer.php'; ?>
