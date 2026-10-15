<?php
require_once __DIR__.'/lib/shop.php';
$id=(int)($_GET['id'] ?? $_GET['product_id'] ?? 0);
$p=one("SELECT p.*,s.shop_name FROM products p LEFT JOIN shops s ON s.id=p.shop_id WHERE p.id=? AND p.status=1 AND p.audit_status='approved' AND (p.shop_id IS NULL OR s.status=1)",[$id]);
if(!$p) { http_response_code(404); page_start('商品不存在或已下架'); page_end(); exit; }
// 浏览足迹：登录用户访问详情页成功时记录（未登录不写入）
record_browsing((int)($_SESSION['user_id'] ?? 0), $id);
page_start($p['title']);
$reviews=rows('SELECT r.*,u.username FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.product_id=? AND r.parent_id IS NULL ORDER BY r.id DESC LIMIT 30',[$id]);
// 追评与晒图统计只针对当前展示的首评（评分均值不含 rating 固定为 0 的追评）
$stats=one('SELECT COUNT(*) AS n,ROUND(AVG(rating),1) AS score FROM reviews WHERE product_id=? AND parent_id IS NULL',[$id]);
$followups=[]; $reviewImages=[];
if($reviews) { $reviewIds=implode(',',array_map('intval',array_column($reviews,'id')));
    foreach(rows("SELECT * FROM reviews WHERE parent_id IN ($reviewIds) ORDER BY id") as $f) $followups[(int)$f['parent_id']][]=$f;
    foreach(rows("SELECT * FROM review_images WHERE review_id IN ($reviewIds) ORDER BY id") as $im) $reviewImages[(int)$im['review_id']][]=$im;
}
$skus=rows('SELECT * FROM product_skus WHERE product_id=? AND status=1 ORDER BY id',[$id]);
$buyable=$skus ? array_sum(array_column($skus,'stock')) : (int)$p['stock'];
// 限时特价：仅默认规格商品参与（SKU 价格以规格为准）；拼团活动同样仅无规格商品，团购价优先于特价展示（结算价同样以团购价覆盖）
$gb=$skus ? null : group_buy_active($id);
$flash=($skus || $gb) ? null : flash_price_cents($p);
$gbStats=$gb ? group_buy_stats((int)$gb['id']) : [0, 0];
$displayPriceCents=$gb ? (int)$gb['price_cents'] : ($flash!==null ? $flash : (int)round((float)$p['price']*100));
?>
<section class="card row"><?php if($p['image_url']): ?><img class="hero" src="<?=h($p['image_url'])?>" alt="<?=h($p['title'])?>"><?php endif; ?><div class="grow"><span class="badge"><?=h($p['shop_name'] ?? '平台自营')?></span><h2><?=h($p['title'])?></h2><p class="price">¥ <?=h($skus ? money(min(array_map('intval',array_column($skus,'price_cents')))) : money($displayPriceCents))?></p><?php if($flash!==null): ?><p><s class="muted">原价 ¥ <?=h($p['price'])?></s> · <strong style="color:#dc2626;">限时特价</strong> · 活动截止时间 <?=h($p['flash_end'])?></p><?php endif; ?><?php if($gb): ?><p><s class="muted">原价 ¥ <?=h($p['price'])?></s> · <strong style="color:#dc2626;">拼团价 ¥ <?=money((int)$gb['price_cents'])?></strong> · <?=(int)$gb['size']?> 人成团 · 已开 <?=(int)$gbStats[0]?> 团 / 已拼 <?=(int)$gbStats[1]?> 件 · 限购 1 件</p><?php endif; ?><p>库存 <?=(int)$buyable?> 件 · 评价 <?=(int)$stats['n']?> 条<?php if($stats['score']): ?> · <?=h($stats['score'])?> / 5 星<?php endif; ?></p><?php if($p['material']!==''): ?><p>材质：<?=h($p['material'])?></p><?php endif; ?>
<form method="post" action="cart.php"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="add"><?php if($skus): $noneChecked=true; ?><fieldset class="skus"><legend>选择规格</legend><?php foreach($skus as $sku): $sellable=(int)$sku['stock']>0; ?><label><input type="radio" name="sku_id" value="<?=(int)$sku['id']?>" data-image="<?=h($sku['image_url'] ?? '')?>" data-price="<?=money((int)$sku['price_cents'])?>" <?=$sellable?'':'disabled'?> <?=$sellable&&$noneChecked?'required checked':''?>><?=h($sku['sku_name'])?> · ¥ <?=money((int)$sku['price_cents'])?> · 库存 <?=(int)$sku['stock']?><?=$sellable?'':'（售罄）'?></label><?php if($sellable) $noneChecked=false; endforeach; ?></fieldset><?php endif; ?><label>购买数量 <input name="quantity" type="number" value="1" min="1" max="<?=min(999,max(1,(int)$buyable))?>" required></label><div class="actions"><button <?=$buyable<=0?'disabled':''?>>加入购物车</button><button formaction="checkout.php" <?=$buyable<=0?'disabled':''?>>立即购买</button></div></form>
<?php if($gb): ?><form method="post" action="checkout.php" class="inline"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="quantity" value="1"><input type="hidden" name="group_buy_id" value="<?=(int)$gb['id']?>"><button <?=$buyable<=0?'disabled':''?>>发起拼团 / 参与拼团</button></form><p class="muted">未登录将先跳转登录；系统自动匹配进行中的团，人满即成团发货。</p><?php endif; ?>
<form method="post" action="favorites.php" class="inline"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><button name="action" value="add">收藏商品</button></form></div></section>
<section class="card"><h2>商品介绍</h2><div class="product-copy"><?=h($p['detail'] ?: '暂无详细介绍')?></div></section>
<section class="card"><h2>购买者评价</h2><?php $tagCounts=review_tag_counts($id); if($tagCounts): ?><p class="tag-pills"><?php foreach($tagCounts as $tc): ?><span class="tag-pill"><?=h($tc['tag'])?> <?=(int)$tc['count']?> 条</span><?php endforeach; ?></p><?php endif; ?><p class="muted"><?php if($stats['score']): ?>平均 <?=h($stats['score'])?> / 5 星 · <?php endif; ?>共 <?=(int)$stats['n']?> 条评价</p><?php if(!$reviews) echo '<p class="muted">暂无评价，确认收货后可分享购买体验。</p>'; foreach($reviews as $review): ?><article><strong><?=h(mb_substr($review['username'],0,1))?>*** · <?=(int)$review['rating']?> 星</strong><p class="product-copy"><?=h($review['content'])?></p><?php foreach($reviewImages[(int)$review['id']] ?? [] as $im): ?><img class="thumb" src="<?=h($im['image_url'])?>" alt="评价晒图"><?php endforeach; ?><?php if($review['reply_content']): ?><p class="notice">商家回复：<?=h($review['reply_content'])?> <small><?=h($review['replied_at'])?></small></p><?php endif; ?><?php foreach($followups[(int)$review['id']] ?? [] as $fu): ?><div style="margin-left:24px;border-left:3px solid #e2e8f0;padding-left:12px;"><strong>追评</strong><p class="product-copy"><?=h($fu['content'])?></p><small><?=h($fu['created_at'])?></small></div><?php endforeach; ?><small><?=h($review['created_at'])?></small><hr class="divider"></article><?php endforeach; if($stats['n']>30) echo '<p class="muted">当前展示最新 30 条评价。</p>'; ?></section>
<?php if($skus): ?><script>
(function () {
    var hero = document.querySelector('img.hero');
    var price = document.querySelector('p.price');
    function apply(radio) {
        if (!radio) return;
        var image = radio.getAttribute('data-image');
        if (hero && image) hero.src = image;
        if (price) {
            var value = radio.getAttribute('data-price');
            if (value) price.textContent = '¥ ' + value;
        }
    }
    document.querySelectorAll('input[name="sku_id"]').forEach(function (radio) {
        radio.addEventListener('change', function () { if (radio.checked) apply(radio); });
    });
    apply(document.querySelector('input[name="sku_id"]:checked'));
})();
</script>
<?php endif; ?>
<?php page_end(); ?>
