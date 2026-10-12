<?php
require_once __DIR__.'/lib/shop.php';
$id=(int)($_GET['id'] ?? $_GET['product_id'] ?? 0);
$p=one("SELECT p.*,s.shop_name FROM products p LEFT JOIN shops s ON s.id=p.shop_id WHERE p.id=? AND p.status=1 AND p.audit_status='approved' AND (p.shop_id IS NULL OR s.status=1)",[$id]);
if(!$p) { http_response_code(404); page_start('商品不存在或已下架'); page_end(); exit; }
page_start($p['title']);
$reviews=rows('SELECT r.*,u.username FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.product_id=? ORDER BY r.id DESC LIMIT 30',[$id]);
$stats=one('SELECT COUNT(*) AS n,ROUND(AVG(rating),1) AS score FROM reviews WHERE product_id=?',[$id]);
$skus=rows('SELECT * FROM product_skus WHERE product_id=? AND status=1 ORDER BY id',[$id]);
$buyable=$skus ? array_sum(array_column($skus,'stock')) : (int)$p['stock'];
?>
<section class="card row"><?php if($p['image_url']): ?><img class="hero" src="<?=h($p['image_url'])?>" alt="<?=h($p['title'])?>"><?php endif; ?><div class="grow"><span class="badge"><?=h($p['shop_name'] ?? '平台自营')?></span><h2><?=h($p['title'])?></h2><p class="price">¥ <?=h($skus ? money(min(array_map('intval',array_column($skus,'price_cents')))) : $p['price'])?></p><p>库存 <?=(int)$buyable?> 件 · 评价 <?=(int)$stats['n']?> 条<?php if($stats['score']): ?> · <?=h($stats['score'])?> / 5 星<?php endif; ?></p>
<form method="post" action="cart.php"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="add"><?php if($skus): $noneChecked=true; ?><fieldset class="skus"><legend>选择规格</legend><?php foreach($skus as $sku): $sellable=(int)$sku['stock']>0; ?><label><input type="radio" name="sku_id" value="<?=(int)$sku['id']?>" data-image="<?=h($sku['image_url'] ?? '')?>" data-price="<?=money((int)$sku['price_cents'])?>" <?=$sellable?'':'disabled'?> <?=$sellable&&$noneChecked?'required checked':''?>><?=h($sku['sku_name'])?> · ¥ <?=money((int)$sku['price_cents'])?> · 库存 <?=(int)$sku['stock']?><?=$sellable?'':'（售罄）'?></label><?php if($sellable) $noneChecked=false; endforeach; ?></fieldset><?php endif; ?><label>购买数量 <input name="quantity" type="number" value="1" min="1" max="<?=min(999,max(1,(int)$buyable))?>" required></label><div class="actions"><button <?=$buyable<=0?'disabled':''?>>加入购物车</button><button formaction="checkout.php" <?=$buyable<=0?'disabled':''?>>立即购买</button></div></form>
<form method="post" action="favorites.php" class="inline"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><button name="action" value="add">收藏商品</button></form></div></section>
<section class="card"><h2>商品介绍</h2><div class="product-copy"><?=h($p['detail'] ?: '暂无详细介绍')?></div></section>
<section class="card"><h2>购买者评价</h2><?php if(!$reviews) echo '<p class="muted">暂无评价，确认收货后可分享购买体验。</p>'; foreach($reviews as $review): ?><article><strong><?=h(mb_substr($review['username'],0,1))?>*** · <?=(int)$review['rating']?> 星</strong><p class="product-copy"><?=h($review['content'])?></p><small><?=h($review['created_at'])?></small><hr class="divider"></article><?php endforeach; if($stats['n']>30) echo '<p class="muted">当前展示最新 30 条评价。</p>'; ?></section>
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
