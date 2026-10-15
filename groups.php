<?php
require_once __DIR__.'/lib/shop.php';
// 我的拼团：需登录（未登录展示提示并提供登录链接，不重定向以免测试/嵌入场景死循环）
if (empty($_SESSION['user_id'])) {
    page_start('我的拼团');
    echo '<section class="card"><p>请先<a href="user_login.php?next=groups.php">登录</a>后查看您的拼团。</p></section>';
    page_end();
    return; // 直接访问即结束页面；被 include 渲染时安全返回
}
$userId=(int)$_SESSION['user_id'];
$myGroups=group_buy_my_groups($userId);
page_start('我的拼团');
?>
<section class="card">
<p class="muted">拼团采用自动匹配：下单后加入进行中的团或自动开新团，人满即成团、商家发货；超时未成团自动退款。共 <?=(int)count($myGroups)?> 条参与记录。</p>
<?php if(!$myGroups): ?>
<p class="muted">您还没有参与拼团，去 <a href="index.php">首页</a> 看看带「拼团价」标识的商品吧。</p>
<?php endif; ?>
<?php foreach($myGroups as $g): ?>
<article>
<strong><?=h($g['title'])?></strong> · 团购价 ¥ <?=money((int)$g['price_cents'])?> · <?=$g['role']==='leader'?'团长':'成员'?>
<p>订单号 <?=h($g['order_no'])?> · 订单状态 <?=h(status_name($g['order_status']))?></p>
<p>团状态：<?=h(group_buy_status_label($g))?> · 开团时间 <?=h($g['group_started'])?> · 成团时限 <?=(int)$g['duration_hours']?> 小时</p>
<?php if((int)$g['group_success']===1): ?><p class="notice">已成团，商家将尽快发货。</p><?php endif; ?>
<p><a href="detail.php?id=<?=(int)$g['product_id']?>">查看商品</a> · <a href="pay.php?order_id=<?=(int)$g['order_id']?>">查看订单</a></p>
<hr class="divider">
</article>
<?php endforeach; ?>
</section>
<?php page_end(); ?>
