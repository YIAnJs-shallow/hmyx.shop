<?php
// ===== 个人中心体系共享布局（京东"我的京东"式：顶部通栏 + 左侧栏 + 内容区）=====
// 使用方：user_center.php（枢纽 Dashboard）/ profile.php（修改资料）/ member.php（我的会员）。
// 依赖 lib/shop.php 已加载（uid/csrf_field 等）；样式集中在 css/user_center.css?v=1，移动端左侧栏折叠为横向 tab。
// function_exists 守卫：允许测试进程内重复 include。

if (!function_exists('uc_page_start')) {
// 左侧栏导航清单（当前所在项高亮由 $active 键决定）；商铺管理/商家发货仅商户与管理员可见
function uc_nav_items($role) {
    $items = [
        'member'    => ['member.php', '🎫 我的会员'],
        'profile'   => ['profile.php', '⚙️ 修改资料'],
        'orders'    => ['my_orders.php', '📦 我的订单'],
        'addresses' => ['addresses.php', '📍 收货地址'],
        'points'    => ['points.php', '💰 我的钱包 · 积分'],
        'groups'    => ['groups.php', '👥 拼团管理'],
    ];
    if (in_array($role, ['merchant', 'admin'], true)) {
        $items['shop'] = ['shop_manage.php', '🏪 商铺管理'];
        $items['ship'] = ['admin_orders.php', '🚚 商家发货'];
    }
    return $items;
}

// 页头 + 左侧栏 + 内容区开标签；$active 传 uc_nav_items 的键名（如 'member'），无匹配则不高亮
function uc_page_start($title, $active = '') {
    $role = $_SESSION['role'] ?? 'guest';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . ' - 惠蒙优选</title>';
    echo '<link rel="stylesheet" href="css/user_center.css?v=1"></head><body>';
    echo '<div class="uc-topbar">';
    echo '<a class="uc-logo" href="index.php">🛍️ <strong>惠蒙优选</strong> · 个人中心</a>';
    echo '<div class="uc-topbar-links"><a href="index.php">返回首页</a><a href="cart.php">🛒 购物车</a></div>';
    echo '</div><div class="uc-layout"><nav class="uc-side">';
    foreach (uc_nav_items($role) as $key => $item) {
        echo '<a href="' . htmlspecialchars($item[0]) . '" class="' . ($key === $active ? 'active' : '') . '">' . $item[1] . '</a>';
    }
    echo '<a href="logout.php" class="uc-logout">🚪 安全退出</a>';
    echo '</nav><main class="uc-main">';
}

// 内容区闭标签 + 页脚
function uc_page_end() {
    echo '</main></div><div class="uc-footer">惠蒙优选 · 精选好物，安心选购</div></body></html>';
}

// 宫格数量徽标：0 时灰色样式（uc-zero），超过 99 显示 99+
function badge_html($n) {
    $n = (int)$n;
    if ($n <= 0) return ' <span class="uc-count uc-zero">0</span>';
    return ' <span class="uc-count">' . ($n > 99 ? '99+' : $n) . '</span>';
}
}
