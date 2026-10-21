<?php
// 买家侧店铺主页（2026-10-21）：店铺信息 + 该店在售商品（可见口径，按销量排序，分页 12/页）
// 纯展示页：不涉及店铺管理与资金口径；未上架/不存在店铺一律按"店铺不存在"处理
require_once __DIR__ . '/lib/shop.php';
$shopId = (int)($_GET['id'] ?? 0);
$shop = $shopId > 0 ? one('SELECT * FROM shops WHERE id=? AND status=1', [$shopId]) : null;
$shopCerts = $shop ? shop_cert_list($shop) : [];
$shopChatUnread = ($shop && !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user') ? shop_chat_thread_unread($shopId, (int)$_SESSION['user_id']) : 0;
if (!$shop) {
    page_start('店铺不存在');
    echo '<section class="card"><p>店铺不存在或已关闭，<a href="index.php">回首页逛逛</a>。</p></section>';
    page_end();
    return; // include 场景（测试套件）中 return 只结束本文件，不会像 exit 一样终止整个进程
}
$page = max(1, min(200, (int)($_GET['page'] ?? 1))); // OFFSET 深分页上限 200 页
$per = 12;
$total = (int)one("SELECT COUNT(*) AS n FROM products p WHERE p.shop_id=? AND p.status=1 AND p.audit_status='approved'", [$shopId])['n'];
$items = $total ? rows("SELECT p.id, p.title, p.image_url, p.price, COALESCE(s.sold, 0) AS sold
    FROM products p
    LEFT JOIN (SELECT oi.product_id, SUM(oi.quantity) AS sold FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status IN ('paid','shipped','completed') GROUP BY oi.product_id) s ON s.product_id = p.id
    WHERE p.shop_id=? AND p.status=1 AND p.audit_status='approved'
    ORDER BY sold DESC, p.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), [$shopId]) : [];
$hasNext = count($items) > $per;
$items = array_slice($items, 0, $per);
page_start(h($shop['shop_name']) . ' · 店铺主页');
?>
<section class="card" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
    <span style="font-size:2.6rem;">🏪</span>
    <div style="flex:1;min-width:240px;">
        <h2 style="margin:0 0 6px;"><?= h($shop['shop_name']) ?></h2>
        <p class="muted" style="margin:0;">
            <?php if ($shop['business_scope'] !== ''): ?><span class="badge"><?= h($shop['business_scope']) ?></span> <?php endif; ?>
            <?php if ($shop['category'] !== ''): ?><span class="badge"><?= h($shop['category']) ?></span> <?php endif; ?>
            <?php if ($shop['ship_from'] !== ''): ?>发货地：<?= h($shop['ship_from']) ?> · <?php endif; ?>
            开店于 <?= h(substr((string)$shop['created_at'], 0, 10)) ?>
        </p>
        <?php if ($shop['shop_desc']): ?><p style="margin:8px 0 0;"><?= h($shop['shop_desc']) ?></p><?php endif; ?>
        <?php if ($shopCerts): ?><p style="margin:8px 0 0;"><?php foreach ($shopCerts as $sci): ?><span class="badge" style="background:#ecfdf5;color:#047857;">✅ <?= h($sci) ?></span> <?php endforeach; ?></p><?php endif; ?>
    </div>
    <div style="text-align:center;">
        <a class="btn-buy-link" href="chat.php?shop_id=<?= $shopId ?>">💬 联系客服<?= $shopChatUnread > 0 ? '（<strong style="color:#dc2626;">' . min($shopChatUnread, 99) . '</strong> 条未读）' : '' ?></a><br>
        <strong class="price"><?= $total ?></strong><br><span class="muted small">在售商品</span>
    </div>
</section>
<section class="card">
    <h3>🛍️ 全部商品（按销量）</h3>
    <?php if (!$items): ?>
        <p class="muted">该店铺暂无在售商品。</p>
    <?php else: ?>
    <div class="grid">
        <?php foreach ($items as $item): ?>
        <article class="card">
            <a href="detail.php?id=<?=(int)$item['id'] ?>">
                <?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?>
                    <img class="thumb" style="width:100%;height:150px;" loading="lazy" src="<?= h($item['image_url']) ?>" alt="<?= h($item['title']) ?>">
                <?php else: ?>
                    <span class="thumb" style="display:inline-flex;width:100%;height:150px;align-items:center;justify-content:center;font-size:2.5rem;">📦</span>
                <?php endif; ?>
            </a>
            <h3 style="margin-top:10px;font-size:.92rem;"><a href="detail.php?id=<?=(int)$item['id'] ?>"><?= h($item['title']) ?></a></h3>
            <div class="price">￥<?= h(number_format((float)$item['price'], 2)) ?></div>
            <?php if ((int)$item['sold'] > 0): ?><div class="muted small">已售 <?=(int)$item['sold'] ?> 件</div><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <nav class="pagination" aria-label="分页">
        <?php if ($page > 1): ?><a href="shop.php?id=<?= $shopId ?>&page=<?= $page - 1 ?>">上一页</a><?php endif; ?>
        <span>第 <?= $page ?> 页 · 共 <?= $total ?> 件商品</span>
        <?php if ($hasNext): ?><a href="shop.php?id=<?= $shopId ?>&page=<?= $page + 1 ?>">下一页</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</section>
<?php page_end(); ?>
