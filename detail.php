<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/coupon.php';
$id=(int)($_GET['id'] ?? $_GET['product_id'] ?? 0);
// 领券提交：登录用户一键领取（复用 coupon_claim，幂等+唯一键防重）；未登录跳登录页带回跳
$message='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['claim_coupon_id'])) {
    if (empty($_SESSION['user_id'])) login_redirect(current_page_url());
    try {
        $_SESSION['notice']=coupon_claim((int)$_SESSION['user_id'],(int)$_POST['claim_coupon_id']);
        redirect_to('detail.php?id='.$id);
    } catch (Throwable $e) {
        if (!($e instanceof DomainException)) error_log($e);
        $message=$e instanceof DomainException?$e->getMessage():'领取失败，请稍后重试';
    }
}
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
<section class="card row"><?php if($p['image_url']): ?><img class="hero" src="<?=h($p['image_url'])?>" alt="<?=h($p['title'])?>"><?php endif; ?><div class="grow"><span class="badge"><?=h($p['shop_name'] ?? '平台自营')?></span><h2><?=h($p['title'])?></h2><?php if(!empty($_SESSION['user_id'])): $viewerLevel=one('SELECT l.name,l.discount_percent FROM users u JOIN user_levels l ON l.id=u.level_id WHERE u.id=?',[(int)$_SESSION['user_id']]); if($viewerLevel && (int)$viewerLevel['discount_percent']<100): ?><span class="badge" style="background:#fef9c3;color:#a16207;"><?=h($viewerLevel['name'])?>会员 <?=(int)$viewerLevel['discount_percent']?>折</span><?php endif; endif; ?><p class="price">¥ <?=h($skus ? money(min(array_map('intval',array_column($skus,'price_cents')))) : money($displayPriceCents))?></p><?php if($flash!==null): ?><p><s class="muted">原价 ¥ <?=h($p['price'])?></s> · <strong style="color:#dc2626;">限时特价</strong> · 活动截止时间 <?=h($p['flash_end'])?></p><?php endif; ?><?php if($gb): ?><p><s class="muted">原价 ¥ <?=h($p['price'])?></s> · <strong style="color:#dc2626;">拼团价 ¥ <?=money((int)$gb['price_cents'])?></strong> · <?=(int)$gb['size']?> 人成团 · 已开 <?=(int)$gbStats[0]?> 团 / 已拼 <?=(int)$gbStats[1]?> 件 · 限购 1 件</p><?php endif; ?><?php if((int)($p['presale'] ?? 0)===1): ?><p><span class="badge" style="background:#fef3c7;color:#b45309;">预售</span> 定金 ¥ <?=h(money((int)($p['deposit_cents'] ?? 0)))?>（商品总价 ¥ <?=h($p['price'])?>）<?php if(!empty($p['presale_end_at'])): ?> · 定金支付截止 <?=h($p['presale_end_at'])?><?php endif; ?></p><?php endif; ?><p>库存 <?=(int)$buyable?> 件 · 评价 <?=(int)$stats['n']?> 条<?php if($stats['score']): ?> · <?=h($stats['score'])?> / 5 星<?php endif; ?></p><?php if($p['material']!==''): ?><p>材质：<?=h($p['material'])?></p><?php endif; ?>
<form method="post" action="cart.php"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="add"><?php if($skus): $noneChecked=true; ?><fieldset class="skus"><legend>选择规格</legend><?php foreach($skus as $sku): $sellable=(int)$sku['stock']>0; ?><label><input type="radio" name="sku_id" value="<?=(int)$sku['id']?>" data-image="<?=h($sku['image_url'] ?? '')?>" data-price="<?=money((int)$sku['price_cents'])?>" <?=$sellable?'':'disabled'?> <?=$sellable&&$noneChecked?'required checked':''?>><?=h($sku['sku_name'])?> · ¥ <?=money((int)$sku['price_cents'])?> · 库存 <?=(int)$sku['stock']?><?=$sellable?'':'（售罄）'?></label><?php if($sellable) $noneChecked=false; endforeach; ?></fieldset><?php endif; ?><label>购买数量 <input name="quantity" type="number" value="1" min="1" max="<?=min(999,max(1,(int)$buyable))?>" required></label><div class="actions"><?php if((int)($p['presale'] ?? 0)!==1): ?><button <?=$buyable<=0?'disabled':''?>>加入购物车</button><?php endif; ?><button formaction="checkout.php" <?=$buyable<=0?'disabled':''?>><?=((int)($p['presale'] ?? 0)===1?'立即购买（支付定金）':'立即购买')?></button></div></form>
<?php if($gb): ?><form method="post" action="checkout.php" class="inline"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="quantity" value="1"><input type="hidden" name="group_buy_id" value="<?=(int)$gb['id']?>"><button <?=$buyable<=0?'disabled':''?>>发起拼团 / 参与拼团</button></form><p class="muted">未登录将先跳转登录；系统自动匹配进行中的团，人满即成团发货。</p><?php endif; ?>
<form method="post" action="favorites.php" class="inline"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$id?>"><button name="action" value="add">收藏商品</button></form></div></section>
<?php
// 领券区块：展示本商品可用券（全场 + 指定本商品），不含其他商品的指定券与新人券（新人券注册时自动发放）
$detailCoupons=rows("SELECT c.* FROM coupons c
    WHERE c.active=1 AND c.is_newuser=0 AND (c.expire_at IS NULL OR c.expire_at>NOW())
      AND c.claimed_count<c.total_count
      AND (c.scope='all' OR (c.scope='product' AND c.product_id=?))
      AND NOT EXISTS (SELECT 1 FROM user_coupons uc WHERE uc.coupon_id=c.id AND uc.user_id=?)
    ORDER BY c.discount_cents DESC",[$id,(int)($_SESSION['user_id'] ?? 0)]);
?>
<?php if($detailCoupons): ?>
<section class="card"><h2>领券</h2><?php if($message) echo '<p class="notice error" role="alert">'.h($message).'</p>'; ?><?php foreach($detailCoupons as $dc): ?><p><?=h($dc['title'])?> · 立减 ¥<?=money((int)$dc['discount_cents'])?><?=((int)$dc['min_spend_cents']>0?'，满 ¥'.money((int)$dc['min_spend_cents']).' 可用':'，无门槛')?><?=($dc['expire_at']?'，有效期至 '.h($dc['expire_at']):'')?><?=((int)$dc['valid_days']>0?'，领取后 '.(int)$dc['valid_days'].' 天内有效':'')?>
<?php if(!empty($_SESSION['user_id'])): ?><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="claim_coupon_id" value="<?=(int)$dc['id']?>"><button>领取</button></form>
<?php else: ?><a href="user_login.php?next=<?=rawurlencode('detail.php?id='.$id)?>">登录后领取</a><?php endif; ?></p>
<?php endforeach; ?></section>
<?php endif; ?>
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
