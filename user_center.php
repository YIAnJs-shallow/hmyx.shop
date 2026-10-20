<?php
// ===== 个人中心 · 枢纽 Dashboard（京东"我的京东"式改版，2026-10-19）=====
// 定位：导航枢纽——顶部通栏用户卡（头像/昵称/六档会员徽章/我的会员入口/进度条）+
// 我的订单四宫格（待付款/待发货/待评价/退款售后）+ 资产宫格（收藏/优惠券/积分/地址/拼团）+ 浏览足迹。
// 资料修改表单已整体迁移至 profile.php；各子页（profile/member）与本页共用 lib/uc.php 左侧栏。
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/lib/uc.php';

$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? 'guest';
$username = $_SESSION['username'] ?? '用户';

if (!$is_logged_in) {
    // 未登录：渲染登录引导（左侧栏保留导航，踩坑规则：不出现空白页/误导性报错）
    uc_page_start('个人中心');
    echo '<div class="uc-guest"><div style="font-size:2.4rem;">👤</div>';
    echo '<h3 style="margin:10px 0 4px 0;">登录后查看个人中心</h3>';
    echo '<p class="muted" style="color:#64748b;margin:0;">登录后可管理订单、会员档位、优惠券与积分</p>';
    echo '<a class="btn-login" href="user_login.php?next=user_center.php">登录 / 注册</a></div>';
    uc_page_end();
    return;
}

// 聚合各模块数量徽标（纯读 COUNT，与各子页口径一致）
$avatar = (string)one('SELECT avatar FROM users WHERE id=?', [$user_id])['avatar'];
$cntPending = (int)one("SELECT COUNT(*) AS n FROM orders WHERE user_id=? AND status='pending'", [$user_id])['n'];
$cntPaid = (int)one("SELECT COUNT(*) AS n FROM orders WHERE user_id=? AND status='paid'", [$user_id])['n'];
$cntReview = (int)one("SELECT COUNT(DISTINCT o.id) AS n FROM orders o JOIN order_items oi ON oi.order_id=o.id LEFT JOIN reviews rv ON rv.order_item_id=oi.id WHERE o.user_id=? AND o.status='completed' AND rv.id IS NULL", [$user_id])['n'];
$cntAfter = (int)one("SELECT COUNT(*) AS n FROM refund_requests r JOIN orders o ON o.id=r.order_id WHERE o.user_id=? AND r.status='pending'", [$user_id])['n']
          + (int)one("SELECT COUNT(*) AS n FROM after_sale_requests r JOIN orders o ON o.id=r.order_id WHERE o.user_id=? AND r.status='pending'", [$user_id])['n'];
$cntFav = (int)one('SELECT COUNT(*) AS n FROM favorites WHERE user_id=?', [$user_id])['n'];
$cntCoupons = (int)one("SELECT COUNT(*) AS n FROM user_coupons WHERE user_id=? AND status='unused'", [$user_id])['n'];
$cntAddr = (int)one('SELECT COUNT(*) AS n FROM user_addresses WHERE user_id=?', [$user_id])['n'];
$cntGroups = (int)one('SELECT COUNT(*) AS n FROM group_members WHERE user_id=?', [$user_id])['n'];
$pointsBalance = (int)one('SELECT points FROM users WHERE id=?', [$user_id])['points'];
$ucProg = level_progress($user_id);
$browsing = browsing_history($user_id);
?>
<?php uc_page_start('个人中心'); ?>
<!-- 顶部通栏用户卡：头像/昵称/角色与六档会员徽章/我的会员入口/会员进度条 -->
<div class="uc-hero">
    <div class="uc-hero-top">
        <a class="uc-hero-avatar" href="profile.php" title="修改资料">
            <?php if ($avatar !== ''): ?>
                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="头像">
            <?php else: ?>
                👤
            <?php endif; ?>
        </a>
        <div>
            <h3 class="uc-hero-name"><?php echo htmlspecialchars($username); ?></h3>
            <div>
                <span class="uc-hero-badge uc-role"><?php echo ($role === 'admin') ? '🛠️ 平台管理员' : (($role === 'merchant') ? '🏪 商家账号' : '👤 普通买家'); ?></span>
                <?php if ($ucProg): ?>
                <span class="uc-hero-badge">🎫 <?php echo htmlspecialchars($ucProg['level']['name']); ?>会员</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($ucProg): ?>
        <a class="uc-hero-link" href="member.php">我的会员 · 权益详情 ›</a>
        <?php endif; ?>
    </div>
    <?php if ($ucProg): ?>
    <div class="mm-progress-track"><div class="mm-progress-bar" style="width: <?php echo (int)$ucProg['percent']; ?>%;"></div></div>
    <div class="mm-progress-meta">
        <?php if ($ucProg['next']): ?>
        <span>距 <?php echo htmlspecialchars($ucProg['next']['name']); ?>（¥ <?php echo number_format(((int)$ucProg['next']['min_spent_cents']) / 100, 0); ?>）还差 ¥ <?php echo number_format(((int)$ucProg['next_remain_cents']) / 100, 2); ?></span>
        <?php else: ?>
        <span>已达最高等级至尊 · 感谢您的信任</span>
        <?php endif; ?>
        <span><?php echo (int)$ucProg['percent']; ?>%</span>
    </div>
    <?php endif; ?>
</div>

<!-- 商家工作台：仅 merchant 且已有店铺时展示（经营视角待办，京东千牛式；口径与 admin_orders.php 一致） -->
<?php if ($role === 'merchant'):
    $ucShop = one('SELECT id, shop_name FROM shops WHERE user_id=? AND status=1', [$user_id]);
    if ($ucShop):
        $ucShopId = (int)$ucShop['id'];
        $cntShopToday = (int)one("SELECT COUNT(*) AS n FROM orders WHERE shop_id=? AND created_at>=CURDATE()", [$ucShopId])['n'];
        $cntShopUnship = (int)one("SELECT COUNT(*) AS n FROM orders WHERE shop_id=? AND status='paid'", [$ucShopId])['n'];
        $cntShopAfter = (int)one("SELECT COUNT(*) AS n FROM after_sale_requests a JOIN orders o ON o.id=a.order_id WHERE o.shop_id=? AND a.status='pending'", [$ucShopId])['n']
                      + (int)one("SELECT COUNT(*) AS n FROM refund_requests r JOIN orders o ON o.id=r.order_id WHERE o.shop_id=? AND r.status='pending'", [$ucShopId])['n'];
        $cntShopReply = (int)one("SELECT COUNT(*) AS n FROM reviews r JOIN products p ON p.id=r.product_id WHERE p.shop_id=? AND r.parent_id IS NULL AND r.reply_content IS NULL", [$ucShopId])['n'];
?>
<div class="uc-card uc-merchant">
    <h4 class="uc-card-title">🏪 商家工作台 · <?php echo htmlspecialchars($ucShop['shop_name']); ?><a class="uc-more" href="admin_orders.php">进入工作台 ›</a></h4>
    <div class="uc-grid uc-grid-4">
        <a href="admin_orders.php?status=all"><span class="uc-grid-icon">📈<?php echo badge_html($cntShopToday); ?></span>今日新订单</a>
        <a href="admin_orders.php?status=paid"><span class="uc-grid-icon">📦<?php echo badge_html($cntShopUnship); ?></span>待发货</a>
        <a href="admin_orders.php?status=all"><span class="uc-grid-icon">🧾<?php echo badge_html($cntShopAfter); ?></span>处理中售后</a>
        <a href="admin_orders.php"><span class="uc-grid-icon">⭐<?php echo badge_html($cntShopReply); ?></span>待回复评价</a>
    </div>
</div>
<?php endif; endif; ?>

<!-- 我的订单：四宫格状态入口（口径与 my_orders.php 状态页签一致） -->
<div class="uc-card">
    <h4 class="uc-card-title">我的订单<a class="uc-more" href="my_orders.php">查看全部订单 ›</a></h4>
    <div class="uc-grid uc-grid-4">
        <a href="my_orders.php?status=pending"><span class="uc-grid-icon">💳<?php echo badge_html($cntPending); ?></span>待付款</a>
        <a href="my_orders.php?status=paid"><span class="uc-grid-icon">📦<?php echo badge_html($cntPaid); ?></span>待发货</a>
        <a href="my_orders.php?status=completed"><span class="uc-grid-icon">⭐<?php echo badge_html($cntReview); ?></span>待评价</a>
        <a href="my_orders.php"><span class="uc-grid-icon">🧾<?php echo badge_html($cntAfter); ?></span>退款 / 售后</a>
    </div>
</div>

<!-- 我的资产：收藏/优惠券/积分/地址/拼团 宫格入口（数量徽标） -->
<div class="uc-card">
    <h4 class="uc-card-title">我的资产</h4>
    <div class="uc-grid uc-grid-5">
        <a href="favorites.php"><span class="uc-grid-icon">♡<?php echo badge_html($cntFav); ?></span>我的收藏</a>
        <a href="my_coupons.php"><span class="uc-grid-icon">🎫<?php echo badge_html($cntCoupons); ?></span>我的优惠券</a>
        <a href="points.php"><span class="uc-grid-icon">💰<?php echo badge_html($pointsBalance); ?></span>我的积分</a>
        <a href="addresses.php"><span class="uc-grid-icon">📍<?php echo badge_html($cntAddr); ?></span>收货地址</a>
        <a href="groups.php"><span class="uc-grid-icon">👥<?php echo badge_html($cntGroups); ?></span>拼团管理</a>
    </div>
</div>

<!-- 浏览足迹：最近浏览的商品（同一商品只显示最新一次浏览，口径沿用 browsing_history） -->
<?php if ($browsing): ?>
<div class="uc-card">
    <h4 class="uc-card-title">浏览足迹</h4>
    <div class="uc-foot-grid">
        <?php foreach ($browsing as $b): ?>
        <a class="uc-foot-item" href="detail.php?id=<?php echo (int)$b['product_id']; ?>">
            <?php if (!empty($b['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($b['image_url']); ?>" alt="<?php echo htmlspecialchars($b['title']); ?>">
            <?php else: ?>
                <div class="uc-foot-ph">🛍️</div>
            <?php endif; ?>
            <span class="uc-foot-title"><?php echo htmlspecialchars($b['title']); ?></span>
            <span class="uc-foot-price">¥ <?php echo htmlspecialchars($b['price']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php uc_page_end(); ?>

