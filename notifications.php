<?php
// 消息中心：登录用户站内通知列表（分页倒序、点击置已读并跳转、全部已读）
require_once __DIR__.'/lib/shop.php';
$userId=uid();
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='read_all') {
    csrf_check();
    query('UPDATE user_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL',[$userId]);
    redirect_to('notifications.php','已全部标为已读');
}
// 点击标题：该条置已读后跳转 link（仅允许站内 .php 链接，防开放重定向）
if (isset($_GET['read'])) {
    $item=one('SELECT * FROM user_notifications WHERE id=? AND user_id=?',[(int)$_GET['read'],$userId]);
    if ($item && $item['read_at']===null) query('UPDATE user_notifications SET read_at=NOW() WHERE id=?',[(int)$item['id']]);
    if ($item && preg_match('#^[A-Za-z0-9_.-]+\.php(\?[A-Za-z0-9%=&_.-]*)?$#D',(string)$item['link'])) {
        header('Location: '.$item['link'],true,303); exit;
    }
    redirect_to('notifications.php');
}
$page=max(1,(int)($_GET['page'] ?? 1));
$perPage=20;
$total=(int)one('SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=?',[$userId])['n'];
$unread=(int)one('SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=? AND read_at IS NULL',[$userId])['n'];
$items=rows('SELECT * FROM user_notifications WHERE user_id=? ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage),[$userId]);
$typeNames=['message'=>'留言','order'=>'订单','refund'=>'退款/售后','coupon'=>'优惠券'];
page_start('消息中心');
?>
<p>未读 <strong style="color:#dc2626;"><?=($unread)?></strong> 条 · 共 <?=($total)?> 条
<form class="inline" method="post" action="notifications.php"><?=csrf_field()?><input type="hidden" name="action" value="read_all"><button>全部标为已读</button></form></p>
<?php if(!$items): ?>
<section class="card empty">暂无消息。留言回复、发货、退款审批与优惠券领取都会在这里通知您。</section>
<?php endif; ?>
<?php foreach($items as $item): ?>
<section class="card">
    <div class="row"><div class="grow">
        <strong<?php if($item['read_at']===null) echo ' style="color:#dc2626;"'; ?>>
            <?php if($item['read_at']===null): ?><span class="badge">未读</span><?php endif; ?>
            <a href="notifications.php?read=<?=(int)$item['id']?>"><?=h($item['title'])?></a>
        </strong>
        <small class="muted">[<?=h($typeNames[$item['type']] ?? $item['type'])?>] <?=h($item['created_at'])?></small>
    </div></div>
    <p><?=h($item['body'])?></p>
</section>
<?php endforeach; ?>
<nav class="pagination" aria-label="分页">
<?php if($page>1): ?><a href="?page=<?=($page-1)?>">上一页</a><?php endif; ?>
<span>第 <?=($page)?> 页 · 共 <?=($total)?> 条消息</span>
<?php if($page*$perPage<$total): ?><a href="?page=<?=($page+1)?>">下一页</a><?php endif; ?>
</nav>
<?php page_end(); ?>
