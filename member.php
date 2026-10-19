<?php
// ===== 我的会员（会员体系独立子页，2026-10-19 六档升级配套）=====
// 定位：个人中心（user_center.php）的会员板块子页。展示当前档位/进度条/权益速览/六档对比/等级规则。
// 数据来自 lib/level.php 的 levels_all / level_progress（user_levels 表 + users 快照），纯读渲染，无写路径。
require_once __DIR__ . '/lib/shop.php';

// 未登录：Web 跳登录页；CLI（测试进程内渲染）输出拒绝提示（与 points.php 门禁口径一致）
if (empty($_SESSION['user_id'])) {
    if (PHP_SAPI !== 'cli') login_redirect(current_page_url());
    page_start('我的会员');
    echo '<p class="notice error" role="alert">请先登录后查看我的会员，<a href="user_login.php">去登录</a>。</p>';
    page_end();
    return;
}

$userId = (int)$_SESSION['user_id'];
$prog = level_progress($userId);
$allLevels = levels_all();
if (!$prog) { $prog = null; }

// 档位视觉：emoji 标识 + 主题色（六档递进感，仅展示层）。function_exists 守卫允许测试进程内重复 include
if (!function_exists('member_tier_theme')) {
function member_tier_theme($id) {
    static $themes = null;
    if ($themes === null) {
        $themes = [
            1 => ['icon' => '🥈', 'color' => '#64748b'],
            2 => ['icon' => '🥇', 'color' => '#b45309'],
            3 => ['icon' => '💠', 'color' => '#0284c7'],
            4 => ['icon' => '💎', 'color' => '#2563eb'],
            5 => ['icon' => '🖤', 'color' => '#1e293b'],
            6 => ['icon' => '👑', 'color' => '#dc2626'],
        ];
    }
    return $themes[$id] ?? ['icon' => '🥈', 'color' => '#64748b'];
}
}
?>
<?php page_start('我的会员'); ?>
<style>
.mm-hero { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); border-radius: 12px; padding: 25px; color: #fff; margin-bottom: 18px; }
.mm-hero-top { display: flex; align-items: center; gap: 15px; }
.mm-tier-icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-size: 2rem; flex: none; }
.mm-hero h2 { margin: 0 0 4px 0; font-size: 1.25rem; }
.mm-hero .mm-sub { margin: 0; font-size: .85rem; opacity: .85; }
.mm-progress-wrap { margin-top: 18px; }
.mm-progress-track { height: 10px; border-radius: 5px; background: rgba(255,255,255,.18); overflow: hidden; }
.mm-progress-bar { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.mm-progress-meta { display: flex; justify-content: space-between; font-size: .78rem; opacity: .85; margin-top: 6px; }
.mm-perks { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
.mm-perk { background: rgba(255,255,255,.1); border-radius: 8px; padding: 10px 14px; font-size: .85rem; }
.mm-perk strong { color: #fbbf24; }
.mm-section { background: #fff; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,.03); border-radius: 12px; padding: 20px; margin-bottom: 18px; }
.mm-section h3 { margin: 0 0 14px 0; font-size: 1.05rem; color: #0f172a; border-left: 4px solid #2563eb; padding-left: 10px; }
.mm-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.mm-table th, .mm-table td { padding: 10px 8px; border-bottom: 1px solid #f1f5f9; text-align: center; }
.mm-table th { color: #475569; background: #f8fafc; }
.mm-current td { background: #eff6ff; font-weight: 700; }
.mm-max-note { color: #fbbf24; font-size: .85rem; margin: 8px 0 0 0; }
.mm-rules { color: #64748b; font-size: .85rem; line-height: 1.9; margin: 0; padding-left: 18px; }
</style>
<div class="mm-wrap" style="max-width: 850px; margin: 25px auto; padding: 0 15px;">
<?php if ($prog): $cur = $prog['level']; $next = $prog['next']; $curTheme = member_tier_theme((int)$cur['id']); ?>
    <!-- 档位 Hero：当前档位徽章 + 累计实付 + 距下一档进度条 -->
    <div class="mm-hero">
        <div class="mm-hero-top">
            <div class="mm-tier-icon" style="border: 2px solid <?php echo h($curTheme['color']); ?>;"><?php echo $curTheme['icon']; ?></div>
            <div class="grow">
                <h2><?php echo $curTheme['icon']; ?> <?php echo h($cur['name']); ?> 会员</h2>
                <p class="mm-sub">累计实付 ¥ <?php echo h(number_format(((int)$prog['spent_cents']) / 100, 2)); ?></p>
            </div>
            <a href="user_center.php" style="margin-left: auto; color: #fff; font-size: .85rem; opacity: .9;">返回个人中心 ›</a>
        </div>
        <div class="mm-progress-wrap">
            <?php if ($next): ?>
            <div class="mm-progress-track"><div class="mm-progress-bar" style="width: <?php echo (int)$prog['percent']; ?>%;"></div></div>
            <div class="mm-progress-meta">
                <span>距 <?php echo h($next['name']); ?>（¥ <?php echo h(number_format(((int)$next['min_spent_cents']) / 100, 0)); ?>）还差 ¥ <?php echo h(number_format(((int)$prog['next_remain_cents']) / 100, 2)); ?></span>
                <span><?php echo (int)$prog['percent']; ?>%</span>
            </div>
            <?php else: ?>
            <p class="mm-max-note">👑 已达最高等级至尊，感谢您对惠蒙优选的信任！</p>
            <?php endif; ?>
        </div>
        <div class="mm-perks">
            <span class="mm-perk">会员折扣：<strong><?php echo (int)$cur['discount_percent'] < 100 ? h(rtrim(rtrim(number_format((int)$cur['discount_percent'] / 10, 1), '0'), '.') . ' 折') : '暂无折扣'; ?></strong></span>
            <span class="mm-perk">积分倍率：<strong>×<?php echo h(rtrim(rtrim(number_format((int)$cur['points_rate_x10'] / 10, 1), '0'), '.')); ?></strong></span>
            <span class="mm-perk">升级口径：确认收货后计入，退款不扣减</span>
        </div>
    </div>
<?php else: ?>
    <p class="notice error">会员信息加载失败，请稍后再试。</p>
<?php endif; ?>

    <!-- 六档权益对比表：当前档高亮，展示门槛/折扣/积分倍率 -->
    <div class="mm-section">
        <h3>会员档位与权益对比</h3>
        <table class="mm-table">
            <tr><th>档位</th><th>升级门槛（累计实付）</th><th>会员折扣</th><th>积分倍率</th></tr>
            <?php foreach ($allLevels as $lv): $t = member_tier_theme((int)$lv['id']); $isCur = $prog && (int)$prog['level']['id'] === (int)$lv['id']; ?>
            <tr class="<?php echo $isCur ? 'mm-current' : ''; ?>">
                <td><?php echo $t['icon'] . ' ' . h($lv['name']); ?><?php echo $isCur ? '（当前）' : ''; ?></td>
                <td>¥ <?php echo h(number_format(((int)$lv['min_spent_cents']) / 100, 0)); ?></td>
                <td><?php echo (int)$lv['discount_percent'] < 100 ? h(rtrim(rtrim(number_format((int)$lv['discount_percent'] / 10, 1), '0'), '.') . ' 折') : '—'; ?></td>
                <td>×<?php echo h(rtrim(rtrim(number_format((int)$lv['points_rate_x10'] / 10, 1), '0'), '.')); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- 等级规则说明 -->
    <div class="mm-section">
        <h3>等级规则</h3>
        <ul class="mm-rules">
            <li>等级按<b>累计实付</b>自动升级：订单确认收货后计入（口径 = 商品合计 − 优惠券 − 积分抵现 − 会员折扣）；</li>
            <li>退款 / 售后 / 拼团超时退款一律不扣减累计实付、不降级（终身制）；</li>
            <li>会员折扣在下单时自动生效，券门槛与积分上限均以折后金额为基数；</li>
            <li>积分倍率作用于确认收货发放积分（每 1 元 = 1 分 × 档位倍率，向下取整）；</li>
            <li>普通买家默认为白银档，等级提升后立即生效、长期有效。</li>
        </ul>
    </div>
</div>
<?php page_end(); ?>
