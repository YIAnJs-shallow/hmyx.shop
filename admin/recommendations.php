<?php
// 首页推荐位管理：三楼位（today/new/clearance）添加在售商品、上移下移、移除
// 楼位白名单与查询逻辑在 lib/shop.php（home_recommend_slots / home_slot_products 等）；鉴权复用 product.manage
require __DIR__ . '/inc/auth.php';
require_permission('product.manage');
$page_title = '首页推荐位';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') admin_set_recommendation($_POST['slot'] ?? '', (int)($_POST['product_id'] ?? 0));
        elseif ($action === 'remove') admin_remove_recommendation((int)($_POST['id'] ?? 0));
        elseif ($action === 'move') admin_move_recommendation((int)($_POST['id'] ?? 0), $_POST['dir'] ?? '');
        else throw new DomainException('未知操作');
        header('Location: recommendations.php', true, 303); exit;
    } catch (DomainException $e) { $error = $e->getMessage(); }
}

$slots = home_recommend_slots();
$visibleProducts = rows("SELECT p.id, p.title FROM products p WHERE p.status = 1 AND p.audit_status = 'approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id = p.shop_id AND s.status = 1)) ORDER BY p.id DESC");
require __DIR__ . '/inc/header.php'; ?>
<h1>首页推荐位</h1>
<p>楼位仅在前台首页无搜索/筛选条件时展示，每楼位最多显示前 8 个商品（按下方顺序）。</p>
<?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
<?php foreach ($slots as $slotCode => $slotTitle): ?>
<?php
$items = rows("SELECT hr.id, hr.sort, p.title, p.price FROM home_recommendations hr JOIN products p ON p.id = hr.product_id WHERE hr.slot=? ORDER BY hr.sort ASC, hr.id ASC", [$slotCode]);
?>
<h2><?= h($slotTitle) ?>（<?= count($items) ?>）</h2>
<form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="slot" value="<?= h($slotCode) ?>">
    <label>添加商品
        <select name="product_id" required>
            <?php foreach ($visibleProducts as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= (int)$p['id'] ?> · <?= h($p['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button>添加</button>
</form>
<table class="crud-table">
    <tr><th>顺序</th><th>商品</th><th>操作</th></tr>
    <?php foreach ($items as $item): ?>
    <tr>
        <td><?= (int)$item['sort'] ?></td>
        <td><?= h($item['title']) ?>（￥<?= h(number_format((float)$item['price'], 2)) ?>）</td>
        <td>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="dir" value="up"><button>上移</button></form>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="dir" value="down"><button>下移</button></form>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button>移除</button></form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="3">该楼位暂无商品</td></tr><?php endif; ?>
</table>
<?php endforeach; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
