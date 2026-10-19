<?php
require_once __DIR__.'/lib/shop.php';
// 未登录：Web 跳登录页；CLI（测试进程内渲染）输出拒绝提示
if (empty($_SESSION['user_id'])) {
    if (PHP_SAPI !== 'cli') login_redirect(current_page_url());
    page_start('积分中心');
    echo '<p class="notice error" role="alert">请先登录后查看积分中心，<a href="user_login.php">去登录</a>。</p>';
    page_end();
    return;
}
$userId = (int)$_SESSION['user_id'];
// 签到提交（db.php 对 POST 统一做 CSRF 校验）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['signin'])) {
    $result = try_signin($userId);
    redirect_to('points.php', $result['ok'] ? '签到成功，积分 +'.$result['points'].'，已连续签到 '.$result['streak'].' 天。' : '今天已经签到过了，明天再来吧。');
}
$user = one('SELECT points FROM users WHERE id=?', [$userId]);
$balance = (int)($user['points'] ?? 0);
$todayRow = one('SELECT streak FROM user_signins WHERE user_id=? AND sign_date=CURDATE()', [$userId]);
$todaySigned = $todayRow !== null;
$todayStreak = (int)($todayRow['streak'] ?? 0);
// 本月签到日历
$monthStart = date('Y-m-01');
$daysInMonth = (int)date('t', strtotime($monthStart));
$firstWeekday = (int)date('w', strtotime($monthStart)); // 0=周日
$today = date('Y-m-d');
$signedMap = [];
foreach (rows('SELECT sign_date, streak FROM user_signins WHERE user_id=? AND sign_date>=? ORDER BY sign_date', [$userId, $monthStart]) as $row) $signedMap[$row['sign_date']] = (int)$row['streak'];
// 积分明细分页（每页 20 条，最新在前）
$page = max(1, (int)($_GET['page'] ?? 1));
$totalLogs = (int)one('SELECT COUNT(*) AS n FROM points_log WHERE user_id=?', [$userId])['n'];
$pages = max(1, (int)ceil($totalLogs / 20));
$page = min($page, $pages);
$logs = rows('SELECT * FROM points_log WHERE user_id=? ORDER BY id DESC LIMIT 20 OFFSET '.(($page - 1) * 20), [$userId]);
$typeNames = ['signin'=>'每日签到','order'=>'订单奖励','review'=>'首次评价','followup'=>'追评','redeem'=>'积分兑换','pay'=>'积分抵扣'];
page_start('积分中心');
?>
<section class="card"><h2>我的积分</h2>
<p>当前积分余额：<strong class="price"><?=(int)$balance?></strong> 分<?php if ($todaySigned): ?> · 已连续签到 <strong><?=(int)$todayStreak?></strong> 天<?php endif; ?></p>
<p class="muted">签到每日 +5 分，连续签到每满 7 天当天额外 +20 分；订单确认收货按实付金额每 1 元 = 1 分（白钻及以上按会员档位倍率加成，见<a href="member.php">我的会员</a>）；首次评价 +10 分、追评 +5 分；100 积分 = 1 元，结算时最多抵应付金额的 10%。</p>
<p><a href="user_center.php">返回个人中心</a></p>
<form method="post" class="inline"><?=csrf_field()?><button name="signin" value="1" <?=$todaySigned?'disabled':''?>><?=$todaySigned ? '今日已签到' : '立即签到（+5 分）'?></button></form>
</section>
<section class="card"><h2><?=date('Y年n月')?>签到日历</h2>
<table style="border-collapse:collapse;text-align:center;">
<tr><?php foreach (['日','一','二','三','四','五','六'] as $w): ?><th style="padding:4px 10px;"><?=h($w)?></th><?php endforeach; ?></tr>
<?php for ($day = 1 - $firstWeekday; $day <= $daysInMonth; $day += 7): ?>
<tr><?php for ($w = 0; $w < 7; $w++): $d = $day + $w; ?>
<td style="padding:4px 10px;"><?php if ($d >= 1 && $d <= $daysInMonth): $date = date('Y-m-').str_pad((string)$d, 2, '0', STR_PAD_LEFT); $isSigned = isset($signedMap[$date]); ?><span<?=$isSigned ? ' style="color:#15803d;font-weight:700;"' : ''?>><?=$isSigned ? '✓'.$d.'（'.$signedMap[$date].'天）' : $d ?><?=$date === $today ? '（今天）' : ''?></span><?php else: ?>&nbsp;<?php endif; ?></td>
<?php endfor; ?></tr>
<?php endfor; ?>
</table>
</section>
<section class="card"><h2>积分明细</h2>
<?php if (!$logs): ?><p class="muted">暂无积分记录，先去<a href="index.php">逛逛</a>或<a href="user_center.php">签到</a>吧。</p><?php else: ?>
<table style="border-collapse:collapse;width:100%;"><tr><th style="padding:6px;text-align:left;">时间</th><th style="padding:6px;text-align:left;">类型</th><th style="padding:6px;text-align:left;">说明</th><th style="padding:6px;text-align:right;">积分变动</th></tr>
<?php foreach ($logs as $log): ?><tr><td style="padding:6px;"><?=h((string)$log['created_at'])?></td><td style="padding:6px;"><?=h($typeNames[$log['type']] ?? $log['type'])?></td><td style="padding:6px;"><?=h($log['remark'] ?? '')?></td><td style="padding:6px;text-align:right;<?=((int)$log['change_points']<0?'color:#dc2626;':'color:#15803d;font-weight:700;')?>"><?=$log['change_points']>0?'+':'' ?><?=(int)$log['change_points']?></td></tr><?php endforeach; ?>
</table>
<p><?php if ($page>1): ?><a href="points.php?page=<?=$page-1?>">上一页</a><?php endif; ?> 第 <?=(int)$page?> / <?=(int)$pages?> 页 <?php if ($page<$pages): ?><a href="points.php?page=<?=$page+1?>">下一页</a><?php endif; ?></p>
<?php endif; ?>
</section>
<?php page_end(); ?>
