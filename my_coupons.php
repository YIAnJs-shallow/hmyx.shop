<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/coupon.php';
$userId=uid();
$message='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['claim_coupon_id'])) {
    try {
        $message=coupon_claim($userId,(int)$_POST['claim_coupon_id']);
        $_SESSION['notice']=$message;
        redirect_to('my_coupons.php');
    } catch (Throwable $e) {
        if (!($e instanceof DomainException)) error_log($e);
        $message=$e instanceof DomainException?$e->getMessage():'领取失败，请稍后重试';
    }
}
$mine=rows('SELECT uc.id, uc.status, uc.claimed_at, c.title, c.discount_cents, c.min_spend_cents, c.expire_at
            FROM user_coupons uc JOIN coupons c ON c.id=uc.coupon_id
            WHERE uc.user_id=? ORDER BY uc.status ASC, uc.claimed_at DESC',[$userId]);
$public=rows('SELECT c.* FROM coupons c
              WHERE c.active=1 AND c.is_newuser=0 AND (c.expire_at IS NULL OR c.expire_at>NOW())
                AND c.claimed_count<c.total_count
                AND NOT EXISTS (SELECT 1 FROM user_coupons uc WHERE uc.coupon_id=c.id AND uc.user_id=?)
              ORDER BY c.id DESC',[$userId]);
page_start('我的优惠券');
if ($message) echo '<p class="notice error" role="alert">'.h($message).'</p>';
?>
<section class="card"><h2>我的优惠券</h2>
<?php if (!$mine): ?><p>还没有优惠券，快去下方领取。</p><?php endif; ?>
<?php foreach ($mine as $c): ?>
<p><?=h($c['title'])?> · 立减 ¥<?=money((int)$c['discount_cents'])?><?=((int)$c['min_spend_cents']>0?'，满 ¥'.money((int)$c['min_spend_cents']).' 可用':'，无门槛')?>
<?=($c['expire_at']?'，有效期至 '.h($c['expire_at']):'')?>
<strong><?= $c['status']==='unused'?'【未使用】':'【已使用】'?></strong></p>
<?php endforeach; ?>
</section>
<?php if ($public): ?>
<section class="card"><h2>可领取的优惠券</h2>
<?php foreach ($public as $c): ?>
<p><?=h($c['title'])?> · 立减 ¥<?=money((int)$c['discount_cents'])?><?=((int)$c['min_spend_cents']>0?'，满 ¥'.money((int)$c['min_spend_cents']).' 可用':'，无门槛')?>
<?=($c['expire_at']?'，有效期至 '.h($c['expire_at']):'')?>
<form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="claim_coupon_id" value="<?=(int)$c['id']?>"><button>领取</button></form></p>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php page_end(); ?>
