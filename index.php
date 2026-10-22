<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/shop.php';

// 清空我的搜索记录（仅删除当前登录用户自己的 search_logs）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_search_history') {
    csrf_check();
    if (!empty($_SESSION['user_id'])) query('DELETE FROM search_logs WHERE user_id=?', [(int)$_SESSION['user_id']]);
    header('Location: index.php', true, 303);
    exit;
}

// 1. 获取顶级分类及其二级细分分类
$cat_sql = "SELECT c1.id AS main_id, c1.category_name AS main_name, 
                   c2.id AS sub_id, c2.category_name AS sub_name 
            FROM categories c1 
            LEFT JOIN categories c2 ON c2.parent_id = c1.id 
            WHERE c1.parent_id = 0 
            ORDER BY c1.sort_order ASC, c1.id ASC, c2.id ASC";
$cat_result = $conn->query($cat_sql);

// 将分类数据组装为层级结构
$categories = [];
if ($cat_result && $cat_result->num_rows > 0) {
    while ($row = $cat_result->fetch_assoc()) {
        $m_id = $row['main_id'];
        if (!isset($categories[$m_id])) {
            $categories[$m_id] = [
                'name' => $row['main_name'],
                'subs' => []
            ];
        }
        if (!empty($row['sub_id'])) {
            $categories[$m_id]['subs'][] = [
                'id' => $row['sub_id'],
                'name' => $row['sub_name']
            ];
        }
    }
}

// 2. 获取搜索和筛选参数
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
// GET 搜索时写入搜索日志（未登录 user_id 记 NULL），供联想词与热门搜索统计；同步记录关键词独立命中数（后台搜索词分析用）
if ($keyword !== '' && mb_strlen($keyword) >= 2 && mb_strlen($keyword) <= 100) {
    $kw_like = "%" . addcslashes($keyword, "%_\\") . "%";
    $hitCount = (int)one("SELECT COUNT(*) c FROM products p WHERE p.status = 1 AND p.audit_status = 'approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id = p.shop_id AND s.status = 1)) AND (p.title LIKE ? OR p.detail LIKE ?)", [$kw_like, $kw_like])['c'];
    query('INSERT INTO search_logs(user_id,keyword,results) VALUES(?,?,?)', [!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, $keyword, $hitCount]);
}
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

// 只展示已上架且审核通过的商品
$where = " WHERE p.status = 1 AND p.audit_status = 'approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops active_shop WHERE active_shop.id=p.shop_id AND active_shop.status=1))";
$params = [];
$types = "";

// 关键词搜索：匹配标题或详情（修复：原仅匹配 title，导致详情含关键词的商品搜不到）
if (!empty($keyword)) {
    $where .= " AND (p.title LIKE ? OR p.detail LIKE ?)";
    $params[] = "%" . $keyword . "%";
    $params[] = "%" . $keyword . "%";
    $types .= "ss";
}

// 分类筛选处理（支持大类连带子类查询）
if ($category_id > 0) {
    // 检查选中的分类是否有子分类
    $sub_ids = [];
    if (isset($categories[$category_id])) {
        // 如果选择的是一级大类，获取其旗下所有二级子分类 ID
        foreach ($categories[$category_id]['subs'] as $sub) {
            $sub_ids[] = $sub['id'];
        }
    }

    if (!empty($sub_ids)) {
        // 包含了父分类 ID 本身和所有子分类 ID
        $all_cat_ids = array_merge([$category_id], $sub_ids);
        $in_clause = implode(',', array_fill(0, count($all_cat_ids), '?'));
        $where .= " AND p.category_id IN ({$in_clause})";
        foreach ($all_cat_ids as $cid) {
            $params[] = $cid;
            $types .= "i";
        }
    } else {
        // 选中的是二级细分小类
        $where .= " AND p.category_id = ?";
        $params[] = $category_id;
        $types .= "i";
    }
}

// 品类→facet 维度配置：材质(面料/涂层)仅服饰/家居作为搜索细分，数码/美妆只做品牌+价格
$FACET_DIMS = [
    1 => ['brand','price'],            // 数码电子：品牌+价格，材质不作为搜索细分
    2 => ['brand','material','price'], // 服装服饰
    3 => ['brand','material','price'], // 家居生活
    4 => ['brand','price'],            // 美妆个护
];
// 二级分类 id => 顶级分类 id 映射（category_id 向上找 root 用）
$cat_root = [];
foreach ($categories as $main_id => $mc) foreach ($mc['subs'] as $sub) $cat_root[$sub['id']] = $main_id;

// 命中范围涉及的顶级分类集合：当前搜索/筛选结果集的 DISTINCT category_id 向上映射
$involved_roots = [];
$rstmt = $conn->prepare("SELECT DISTINCT p.category_id FROM products p {$where}");
if ($params) $rstmt->bind_param($types, ...$params);
$rstmt->execute(); $rres = $rstmt->get_result();
while ($rrow = $rres->fetch_assoc()) {
    $cid = (int)$rrow['category_id'];
    if (isset($categories[$cid])) $involved_roots[$cid] = true;
    elseif (isset($cat_root[$cid])) $involved_roots[$cat_root[$cid]] = true;
}
// 材质行仅当涉及的所有顶级分类都含 material 维度时显示（品牌/价格恒显示）
$show_material = !empty($involved_roots);
foreach (array_keys($involved_roots) as $rid) {
    if (!in_array('material', $FACET_DIMS[$rid] ?? [], true)) { $show_material = false; break; }
}

// facet 聚合：范围回归当前搜索/筛选结果集（复用主查询条件），取前 20 个
$facet_where = $where; $facet_params = $params; $facet_types = $types;
$brand_facets = []; $material_facets = [];
$fstmt = $conn->prepare("SELECT DISTINCT p.brand FROM products p {$facet_where} AND p.brand<>'' ORDER BY p.brand LIMIT 20");
if ($facet_params) $fstmt->bind_param($facet_types, ...$facet_params);
$fstmt->execute(); $fres = $fstmt->get_result();
while ($frow = $fres->fetch_assoc()) $brand_facets[] = $frow['brand'];
$fstmt = $conn->prepare("SELECT DISTINCT p.material FROM products p {$facet_where} AND p.material<>'' ORDER BY p.material LIMIT 20");
if ($facet_params) $fstmt->bind_param($facet_types, ...$facet_params);
$fstmt->execute(); $fres = $fstmt->get_result();
while ($frow = $fres->fetch_assoc()) $material_facets[] = $frow['material'];

// 品牌/材质筛选：trim 后最长 50，值必须出现在 facet 白名单中否则忽略
$brand = trim((string)($_GET['brand'] ?? ''));
if ($brand === '' || mb_strlen($brand) > 50 || !in_array($brand, $brand_facets, true)) $brand = '';
$material = trim((string)($_GET['material'] ?? ''));
if ($material === '' || mb_strlen($material) > 50 || !in_array($material, $material_facets, true)) $material = '';

// 价格区间：强制 (float) 后 0~999999，min>max 时忽略
$price_min = isset($_GET['price_min']) && $_GET['price_min'] !== '' ? (float)$_GET['price_min'] : null;
$price_max = isset($_GET['price_max']) && $_GET['price_max'] !== '' ? (float)$_GET['price_max'] : null;
if ($price_min !== null && ($price_min < 0 || $price_min > 999999)) $price_min = null;
if ($price_max !== null && ($price_max < 0 || $price_max > 999999)) $price_max = null;
if ($price_min !== null && $price_max !== null && $price_min > $price_max) { $price_min = null; $price_max = null; }

// 追加筛选条件到主查询（空值条件不拼接，全部参数化绑定）
if ($brand !== '') { $where .= " AND p.brand = ?"; $params[] = $brand; $types .= "s"; }
if ($material !== '') { $where .= " AND p.material = ?"; $params[] = $material; $types .= "s"; }
if ($price_min !== null) { $where .= " AND p.price >= ?"; $params[] = (float)$price_min; $types .= "d"; }
if ($price_max !== null) { $where .= " AND p.price <= ?"; $params[] = (float)$price_max; $types .= "d"; }

// 仅显示有货：in_stock=1 时按现有列表库存口径（products.stock）过滤
$in_stock = (string)($_GET['in_stock'] ?? '') === '1';
if ($in_stock) { $where .= " AND p.stock > 0"; }

$sort = $_GET['sort'] ?? 'default';
// 排序白名单：固定映射 ORDER BY 子句，禁止拼接用户输入；newest 为旧链接兼容别名（与 default 同为综合排序）
// 销量口径沿用热销榜 best_sellers（有效订单 order_items 按 product_id 汇总），评分口径沿用详情页首评均值（追评 rating=0 不计入）
$sales_order = "(SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status IN ('paid','shipped','completed'))";
$rating_order = "(SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.product_id=p.id AND r.parent_id IS NULL)";
$sort_options = [
    'default' => 'p.id DESC',
    'sales' => $sales_order.' DESC, p.id DESC',
    'price_asc' => 'p.price ASC, p.id DESC',
    'price_desc' => 'p.price DESC, p.id DESC',
    'rating' => $rating_order.' DESC, p.id DESC',
];
if ($sort === 'newest') $sort = 'default';
if (!isset($sort_options[$sort])) $sort = 'default';
$sort_labels = ['default'=>'综合','sales'=>'销量','price_asc'=>'价格从低到高','price_desc'=>'价格从高到低','rating'=>'评分'];
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*12;
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products p {$where}");
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute(); $total_products = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$order_by = $sort_options[$sort];
// 3. 构建商品列表查询 SQL
$sql = "SELECT p.*, c.category_name, s.shop_name, gb.price_cents AS group_price_cents
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN shops s ON p.shop_id = s.id
        LEFT JOIN group_buys gb ON gb.product_id = p.id AND gb.status='active'
        {$where}
        ORDER BY {$order_by} LIMIT 12 OFFSET {$offset}";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("数据库查询准备失败: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 4. 检查是否登录
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['username']);

// 搜索联想数据：近 7 日热门搜索 Top 5；已登录用户另取个人最近 10 条去重搜索词
$hot_searches = rows('SELECT keyword, COUNT(*) AS n FROM search_logs WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY keyword ORDER BY n DESC, keyword ASC LIMIT 5');
$my_searches = $is_logged_in && !empty($_SESSION['user_id'])
    ? rows('SELECT keyword, MAX(id) AS last_id FROM search_logs WHERE user_id=? GROUP BY keyword ORDER BY last_id DESC LIMIT 10', [(int)$_SESSION['user_id']])
    : [];

// 导航栏头像（京东式下拉菜单）：取 users.avatar，空值回退默认图标（与 user_center 顶卡口径一致，列由 migrate 幂等管理）
$nav_avatar = '';
if ($is_logged_in && !empty($_SESSION['user_id'])) {
    $nav_avatar = (string)(one('SELECT avatar FROM users WHERE id=?', [(int)$_SESSION['user_id']])['avatar'] ?? '');
}

// 5. 首页推荐板块数据：热销榜 + 猜你喜欢
$best_sellers = best_sellers(10);
$recommend = recommend_for_user((int)($_SESSION['user_id'] ?? 0), 8);
// 6. 运营推荐楼位：后台配置的三席（today/new/clearance），仅首页无搜索/筛选条件时渲染
$show_home_slots = $keyword === '' && $category_id <= 0 && $brand === '' && $material === '' && $price_min === null && $price_max === null && !$in_stock;
$home_slot_data = [];
$seckillItems = $show_home_slots ? seckill_active_items() : [];
$sideCartQty = $is_logged_in ? (int)one('SELECT COALESCE(SUM(quantity),0) AS q FROM cart WHERE user_id=?', [(int)$_SESSION['user_id']])['q'] : 0;
if ($show_home_slots) foreach (home_recommend_slots() as $slot_code => $slot_title) $home_slot_data[$slot_code] = home_slot_products($slot_code);

// 筛选链接生成：每个链接只切换自身维度，其余维度原样携带（空值/默认值剔除）
$filter_query = function (array $override = []) use ($keyword, $category_id, $brand, $material, $price_min, $price_max, $sort, $in_stock) {
    $q = array_merge([
        'keyword' => $keyword,
        'category_id' => $category_id,
        'brand' => $brand,
        'material' => $material,
        'price_min' => $price_min === null ? '' : $price_min,
        'price_max' => $price_max === null ? '' : $price_max,
        'sort' => $sort,
        'in_stock' => $in_stock ? 1 : '',
    ], $override);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || $v === 0) unset($q[$k]);
        if ($k === 'sort' && ($v === 'newest' || $v === 'default')) unset($q[$k]);
    }
    return 'index.php?' . http_build_query($q);
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>惠蒙优选 - 精选优质商城</title>
    <!-- shop.css 提供推荐板块的卡片类名；style.css 在后以保持整页现有观感 -->
    <link rel="stylesheet" href="css/shop.css?v=13">
    <link rel="stylesheet" href="css/style.css?v=8">
    <link rel="stylesheet" href="css/user_menu.css?v=2">
</head>
<body class="page-home">
        <header>
        <div class="logo">
            <?php // 全站统一品牌 logo：公共函数输出（与 page_start/uc 侧栏同源），改 logo 只改 lib/shop.php 一处
            echo site_logo_html(); ?>
        </div>
        <nav>
            <ul>
                <!-- 购物车 -->
                <li>
                    <?php if ($is_logged_in): ?>
                        <a href="cart.php">🛒 购物车</a><a href="favorites.php">我的收藏</a><a href="my_orders.php">我的订单</a>
                    <?php else: ?>
                        <a href="javascript:void(0);" onclick="alert('请先登录账号！'); location.href='user_login.php';">🛒 购物车</a>
                    <?php endif; ?>
                </li>

                <!-- 账户设置 / 登录 入口（京东式：点头像/用户名直达修改资料页，悬停下拉含个人中心枢纽） -->
                <li>
                    <?php if ($is_logged_in): ?>
                        <div class="user-menu">
                            <a class="user-trigger" href="profile.php" aria-haspopup="true">
                                <span class="user-avatar"><?php if ($nav_avatar !== ''): ?><img src="<?php echo htmlspecialchars($nav_avatar); ?>" alt="头像"><?php else: ?>👤<?php endif; ?></span>
                                <span class="user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
                            </a>
                            <div class="user-dropdown">
                                <a href="user_center.php">🏠 个人中心</a>
                                <a href="member.php">🎫 我的会员</a>
                                <a href="my_orders.php">📦 我的订单</a>
                                <a href="logout.php" class="uc-drop-logout">🚪 退出登录</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="user_login.php" class="nav-cta">登录 / 注册</a>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    </header>

    <!-- 深空 Hero 横幅：低密度 Canvas 天空（js/sky.js data-density="low"）+ 渐变标语 + 搜索框整组迁入 -->
    <section class="home-hero">
        <canvas id="sky-canvas" class="hero-canvas" data-density="low" aria-hidden="true"></canvas>
        <div class="hero-inner">
            <h1 class="hero-brand">惠蒙优选 · 好货精选</h1>
            <div class="search-bar-container" style="position: relative;">
            <form action="index.php" method="GET" class="search-form">
                <input type="text" name="keyword" id="search-keyword" class="search-input" autocomplete="off" placeholder="🔍 输入商品名称关键词搜索..." value="<?php echo htmlspecialchars($keyword); ?>">

                <select name="category_id" class="search-select">
    <option value="0">全部分类</option>
    <?php foreach ($categories as $main_id => $main_cat): ?>
        <!-- 一级大类 -->
        <option value="<?php echo $main_id; ?>" <?php echo $category_id == $main_id ? 'selected' : ''; ?> style="font-weight: bold;">
            【<?php echo htmlspecialchars($main_cat['name']); ?>】
        </option>

        <!-- 二级细分分类 -->
        <?php foreach ($main_cat['subs'] as $sub): ?>
            <option value="<?php echo $sub['id']; ?>" <?php echo $category_id == $sub['id'] ? 'selected' : ''; ?> class="opt-sub">
                &nbsp;&nbsp;&nbsp;&nbsp;├─ <?php echo htmlspecialchars($sub['name']); ?>
            </option>
        <?php endforeach; ?>
    <?php endforeach; ?>
</select>

                <select name="sort" class="search-select" aria-label="排序"><?php foreach($sort_labels as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><button type="submit" class="search-btn">搜索</button>

                <a href="search_image.php" class="reset-btn" title="上传照片搜索同款商品">📷 拍照搜同款</a>
                <?php if (!empty($keyword) || $category_id > 0): ?>
                    <a href="index.php" class="reset-btn">重置条件</a>
                <?php endif; ?>
            </form>
            <ul id="search-suggest" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:50;list-style:none;margin:0;padding:0;background:#fff;border:1px solid #cbd5e1;border-top:none;border-radius:0 0 8px 8px;box-shadow:0 8px 16px rgba(15,23,42,.12);max-height:320px;overflow-y:auto;"></ul>
        </div>
        </div>
    </section>

    <!-- 滚动跟随固定搜索条：下滚超过 hero 底边后淡入（JS 同步主表单当前值） -->
    <div class="search-fixed-bar" id="search-fixed-bar">
        <div class="sf-inner">
            <form action="index.php" method="GET" class="search-form" style="flex:1;display:flex;gap:10px;">
                <input type="text" name="keyword" class="search-input" autocomplete="off" placeholder="🔍 搜索商品..." value="<?= h($keyword) ?>">
                <select name="category_id" class="search-select">
                    <option value="0">全部分类</option>
                    <?php foreach ($categories as $main_id => $main_cat): ?>
                        <option value="<?php echo $main_id; ?>" <?php echo $category_id == $main_id ? 'selected' : ''; ?>>【<?= h($main_cat['name']) ?>】</option>
                        <?php foreach ($main_cat['subs'] as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>" <?php echo $category_id == $sub['id'] ? 'selected' : ''; ?>>├─ <?= h($sub['name']) ?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
                <select name="sort" class="search-select" aria-label="排序"><?php foreach($sort_labels as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
                <input type="hidden" name="brand" value="<?= h($brand) ?>">
                <input type="hidden" name="material" value="<?= h($material) ?>">
                <input type="hidden" name="price_min" value="<?= $price_min === null ? '' : $price_min ?>">
                <input type="hidden" name="price_max" value="<?= $price_max === null ? '' : $price_max ?>">
                <input type="hidden" name="in_stock" value="<?= $in_stock ? '1' : '' ?>">
                <button type="submit" class="search-btn">搜索</button>
                <?php if ($brand !== '' || $material !== '' || $price_min !== null || $price_max !== null): ?>
                    <a href="<?= h($filter_query(['brand'=>'','material'=>'','price_min'=>'','price_max'=>'','keyword'=>$keyword,'category_id'=>$category_id])) ?>" class="reset-btn">清除筛选</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <main class="container" style="margin: 20px auto; padding: 0 15px; width: 100%; box-sizing: border-box;">
    <div class="home-body">
    <?php if ($show_home_slots): ?>
    <!-- 京东式左侧分类导航：一级分类常驻，hover 展开二级面板（窄屏由 CSS 隐藏） -->
    <aside class="home-cat-nav" aria-label="商品分类导航">
        <div class="cn-head">全部商品分类</div>
        <?php $cnIcons = [1 => '📱', 2 => '👗', 3 => '🏠', 4 => '💄']; ?>
        <?php foreach ($categories as $main_id => $main_cat): ?>
        <div class="cn-item">
            <a href="index.php?category_id=<?= (int)$main_id ?>"><span class="cn-icon"><?= $cnIcons[(int)$main_id] ?? '🛍️' ?></span><?= h($main_cat['name']) ?><span class="cn-arrow">›</span></a>
            <div class="cn-panel">
                <?php foreach ($main_cat['subs'] as $sub): ?>
                <a href="index.php?category_id=<?= (int)$sub['id'] ?>"><?= h($sub['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </aside>
    <?php endif; ?>
    <div class="home-main">
        <?php if (!empty($hot_searches) || !empty($my_searches)): ?>
        <div class="search-trends" style="margin:0 0 20px;font-size:.85rem;display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px;">
            <?php if (!empty($hot_searches)): ?>
                <span style="font-weight:700;color:#94a3b8;">🔥 热门搜索：</span>
                <?php foreach ($hot_searches as $hs): ?>
                    <a href="index.php?keyword=<?= urlencode($hs['keyword']); ?>" style="color:#2563eb;text-decoration:none;background:#eff6ff;border-radius:999px;padding:2px 12px;"><?= h($hs['keyword']); ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($my_searches)): ?>
                <span style="font-weight:700;color:#94a3b8;">🕘 我的搜索：</span>
                <?php foreach ($my_searches as $ms): ?>
                    <a href="index.php?keyword=<?= urlencode($ms['keyword']); ?>" style="color:#94a3b8;text-decoration:none;background:#f1f5f9;border-radius:999px;padding:2px 12px;"><?= h($ms['keyword']); ?></a>
                <?php endforeach; ?>
                <form method="post" action="index.php" style="display:inline;margin-left:4px;"><?= csrf_field(); ?><input type="hidden" name="action" value="clear_search_history"><button type="submit" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:.85rem;text-decoration:underline;padding:0;">清空我的搜索记录</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($brand_facets || $material_facets): ?>
        <!-- 京东式横向筛选 chips：全量在售聚合后常显（有 facet 值即渲染，分类继续用搜索框旁下拉） -->
        <div class="filter-row">
            <?php if ($brand_facets): ?>
            <span class="fr-label">品牌：</span>
            <?php foreach (array_slice($brand_facets, 0, 20) as $b): ?>
                <?php if ($brand === $b): ?>
                    <a href="<?= h($filter_query(['brand'=>'','page'=>null])) ?>" class="chip chip-on" title="点击清除">× <?= h($b) ?></a>
                <?php else: ?>
                    <a href="<?= h($filter_query(['brand'=>$b,'page'=>null])) ?>" class="chip"><?= h($b) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($show_material && $material_facets): ?>
            <span class="fr-label">材质：</span>
            <?php foreach (array_slice($material_facets, 0, 20) as $m): ?>
                <?php if ($material === $m): ?>
                    <a href="<?= h($filter_query(['material'=>'','page'=>null])) ?>" class="chip chip-on" title="点击清除">× <?= h($m) ?></a>
                <?php else: ?>
                    <a href="<?= h($filter_query(['material'=>$m,'page'=>null])) ?>" class="chip"><?= h($m) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <span class="fr-label">价格：</span>
            <form action="index.php" method="GET" class="fr-price" style="display:inline-flex;align-items:center;gap:6px;">
                <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
                <input type="hidden" name="category_id" value="<?= (int)$category_id ?>">
                <input type="hidden" name="brand" value="<?= h($brand) ?>">
                <input type="hidden" name="material" value="<?= h($material) ?>">
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <input type="hidden" name="in_stock" value="<?= $in_stock ? '1' : '' ?>">
                <input type="number" name="price_min" min="0" max="999999" step="0.01" placeholder="￥最低" value="<?= $price_min === null ? '' : $price_min ?>">
                <span style="color:#94a3b8;">—</span>
                <input type="number" name="price_max" min="0" max="999999" step="0.01" placeholder="￥最高" value="<?= $price_max === null ? '' : $price_max ?>">
                <button type="submit">确定</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (empty($keyword) && $category_id <= 0 && !$is_logged_in): ?>
        <!-- 新人专享横幅：通往落地页 -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;background:linear-gradient(120deg,#4f46e5,#2563eb 55%,#7c3aed);color:#fff;border-radius:16px;padding:22px 28px;margin-bottom:28px;box-shadow:0 10px 28px -10px rgba(37,99,235,0.55);">
            <div>
                <div style="font-size:1.15rem;font-weight:800;letter-spacing:1px;margin-bottom:4px;">🎉 新人专享 · 三重好礼等你领</div>
                <div style="font-size:0.9rem;color:rgba(255,255,255,0.85);">首单立减 ¥15 · 免运费 3 单 · 新人专享价</div>
            </div>
            <a href="welcome.php" style="background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;font-weight:800;padding:11px 28px;border-radius:999px;text-decoration:none;box-shadow:0 10px 24px -8px rgba(245,158,11,0.7);white-space:nowrap;">查看详情</a>
        </div>
        <?php endif; ?>

        <?php if ($show_home_slots && !empty($seckillItems)): ?>
        <!-- 限时秒杀楼层：后台配置引流位，倒计时+配置制进度条，点击进详情页（不新增下单路径） -->
        <section class="card sk-floor" data-floor="⚡ 限时秒杀">
            <div class="sk-head">
                <h3>⚡ 限时秒杀</h3>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="sk-countdown" id="sk-countdown" data-end="<?= h($seckillItems[0]['end_at']) ?>"><span>--</span>:<span>--</span>:<span>--</span></div>
                    <button type="button" class="sk-arrow" id="sk-prev" title="上一批">‹</button>
                    <button type="button" class="sk-arrow" id="sk-next" title="下一批">›</button>
                </div>
            </div>
            <div class="sk-track" id="sk-track">
                <?php foreach ($seckillItems as $si): $skPct = (int)$si['total_stock'] > 0 ? (int)min(100, floor($si['sold'] / $si['total_stock'] * 100)) : 0; ?>
                <article class="card sk-item">
                    <a href="detail.php?id=<?=(int)$si['pid'] ?>">
                        <?php if (!empty($si['image_url']) && file_exists($si['image_url'])): ?>
                            <img class="thumb" style="width:100%;height:150px;" loading="lazy" src="<?= h($si['image_url']) ?>" alt="<?= h($si['title']) ?>">
                        <?php else: ?>
                            <span class="thumb" style="display:inline-flex;width:100%;height:150px;align-items:center;justify-content:center;font-size:2.5rem;">📦</span>
                        <?php endif; ?>
                    </a>
                    <div class="sk-price"><span class="sk-now">¥<?= number_format($si['seckill_price_cents'] / 100, 2) ?></span><del class="sk-orig">¥<?= number_format((float)$si['price'], 2) ?></del></div>
                    <h3 class="sk-title"><a href="detail.php?id=<?=(int)$si['pid'] ?>"><?= h($si['title']) ?></a></h3>
                    <div class="sk-bar"><i style="width:<?= $skPct ?>%"></i></div>
                    <div class="sk-meta"><span>已抢 <?= $skPct ?>%</span><a class="sk-buy" href="detail.php?id=<?=(int)$si['pid'] ?>">马上抢</a></div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($home_slot_data)): foreach (home_recommend_slots() as $slot_code => $slot_title): if (empty($home_slot_data[$slot_code])) continue; ?>
        <!-- 运营推荐楼位：后台配置，sort 升序 -->
        <section class="card" data-floor="<?= h($slot_title) ?>">
            <h3>✨ <?= h($slot_title) ?></h3>
            <div class="grid">
                <?php foreach ($home_slot_data[$slot_code] as $item): ?>
                <article class="card">
                    <a href="detail.php?id=<?php echo (int)$item['id']; ?>">
                        <?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?>
                            <img class="thumb" style="width:100%;height:150px;" loading="lazy" src="<?php echo h($item['image_url']); ?>" alt="<?php echo h($item['title']); ?>">
                        <?php else: ?>
                            <span class="thumb" style="display:inline-flex;width:100%;height:150px;align-items:center;justify-content:center;font-size:2.5rem;">📦</span>
                        <?php endif; ?>
                    </a>
                    <h3 style="margin-top:10px;"><a href="detail.php?id=<?php echo (int)$item['id']; ?>"><?php echo h($item['title']); ?></a></h3>
                    <div class="price">￥<?php echo h(number_format((float)$item['price'], 2)); ?></div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; endif; ?>

        <?php if (!empty($best_sellers)): ?>
        <!-- 热销榜：按有效订单累计销量排序 -->
        <section class="card" data-floor="🔥 热销榜">
            <h3>🔥 热销榜</h3>
            <?php foreach ($best_sellers as $rank => $item): ?>
                <div class="row">
                    <span class="badge"><?php echo $rank + 1; ?></span>
                    <a href="detail.php?id=<?php echo (int)$item['id']; ?>">
                        <?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?>
                            <img class="thumb" loading="lazy" src="<?php echo h($item['image_url']); ?>" alt="<?php echo h($item['title']); ?>">
                        <?php else: ?>
                            <span class="thumb" style="display:inline-flex;align-items:center;justify-content:center;font-size:2rem;">📦</span>
                        <?php endif; ?>
                    </a>
                    <div class="grow">
                        <a href="detail.php?id=<?php echo (int)$item['id']; ?>"><?php echo h($item['title']); ?></a>
                        <div class="muted">已售 <?php echo (int)$item['sold']; ?> 件</div>
                    </div>
                    <span class="price">￥<?php echo h(number_format((float)$item['price'], 2)); ?></span>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($recommend)): ?>
        <!-- 猜你喜欢：基于收藏与历史购买类目，不足时由热销榜补齐 -->
        <section class="card" data-floor="💡 猜你喜欢">
            <h3>💡 猜你喜欢</h3>
            <div class="grid">
                <?php foreach ($recommend as $item): ?>
                    <article class="card">
                        <a href="detail.php?id=<?php echo (int)$item['id']; ?>">
                            <?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?>
                                <img class="thumb" style="width:100%;height:150px;" loading="lazy" src="<?php echo h($item['image_url']); ?>" alt="<?php echo h($item['title']); ?>">
                            <?php else: ?>
                                <span class="thumb" style="display:inline-flex;width:100%;height:150px;align-items:center;justify-content:center;font-size:2.5rem;">📦</span>
                            <?php endif; ?>
                        </a>
                        <h3 style="margin-top:10px;"><a href="detail.php?id=<?php echo (int)$item['id']; ?>"><?php echo h($item['title']); ?></a></h3>
                        <div class="price">￥<?php echo h(number_format((float)$item['price'], 2)); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- 排序条：白名单链接切换排序与有货筛选，chip-on 标记当前项 -->
        <div class="filter-row">
            <span class="fr-label">排序：</span>
            <?php foreach ($sort_labels as $key => $label): ?>
                <a href="<?= h($filter_query(['sort' => $key, 'page' => null])) ?>" class="chip<?= $sort === $key ? ' chip-on' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
            <span class="fr-label">有货：</span>
            <?php if ($in_stock): ?>
                <a href="<?= h($filter_query(['in_stock' => '', 'page' => null])) ?>" class="chip chip-on" title="点击清除">× 仅显示有货</a>
            <?php else: ?>
                <a href="<?= h($filter_query(['in_stock' => '1', 'page' => null])) ?>" class="chip">仅显示有货</a>
            <?php endif; ?>
        </div>

        <div class="page-header">
            <h2 class="section-title">
                <?php
                    if (!empty($keyword)) {
                        echo '搜索结果："' . htmlspecialchars($keyword) . '"';
                    } else {
                        echo '热门选购商品';
                    }
                ?>
            </h2>
            <div style="font-size:.85rem;color:#64748b;margin-top:4px;">
                共 <?php echo $total_products; ?> 件商品
                <?php if ($brand !== ''): ?>　<span style="background:#eff6ff;color:#2563eb;border-radius:999px;padding:2px 10px;">品牌：<?= h($brand) ?> <a href="<?= h($filter_query(['brand'=>'','page'=>null])) ?>" style="color:#dc2626;text-decoration:none;">×</a></span><?php endif; ?>
                <?php if ($material !== ''): ?>　<span style="background:#eff6ff;color:#2563eb;border-radius:999px;padding:2px 10px;">材质：<?= h($material) ?> <a href="<?= h($filter_query(['material'=>'','page'=>null])) ?>" style="color:#dc2626;text-decoration:none;">×</a></span><?php endif; ?>
                <?php if ($price_min !== null || $price_max !== null): ?>　<span style="background:#eff6ff;color:#2563eb;border-radius:999px;padding:2px 10px;">价格：<?= $price_min !== null ? h((string)$price_min) : '0' ?> - <?= $price_max !== null ? h((string)$price_max) : '不限' ?> <a href="<?= h($filter_query(['price_min'=>'','price_max'=>'','page'=>null])) ?>" style="color:#dc2626;text-decoration:none;">×</a></span><?php endif; ?>
            </div>
        </div>

        <div class="product-grid">
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                    <?php
                        $p_name = $row['title'] ?? ($row['name'] ?? '未命名商品');
                        $p_img = $row['image_url'] ?? ($row['image'] ?? '');
                        $p_price = $row['price'] ?? '0.00';
                        // 限时特价：区间内且低于原价时展示特价（划线原价）；拼团活动优先展示团购价
                        $p_flash = flash_price_cents($row);
                        $p_group = isset($row['group_price_cents']) ? (int)$row['group_price_cents'] : 0;
                    ?>
                    <article class="product-card">
                        <div>
                            <div class="product-img-wrapper">
                                <?php if (!empty($p_img) && file_exists($p_img)): ?>
                                    <img src="<?php echo htmlspecialchars($p_img); ?>" alt="<?php echo htmlspecialchars($p_name); ?>">
                                <?php else: ?>
                                    <span style="font-size: 2.5rem;">📦</span>
                                <?php endif; ?>
                            </div>
                            <div class="product-title"><?php echo htmlspecialchars($p_name); ?></div>
                            <?php if (!empty($row['shop_name'])): ?>
                                <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 4px;"><a href="shop.php?id=<?php echo (int)$row['shop_id']; ?>" style="color:#64748b;text-decoration:none;" title="进入店铺主页">🏪 <?php echo htmlspecialchars($row['shop_name']); ?></a></div>
                            <?php endif; ?>
                            <div class="product-price"><?php if ($p_group > 0): ?><span style="text-decoration:line-through;color:#94a3b8;font-size:.8rem;margin-right:4px;">￥<?php echo number_format($p_price, 2); ?></span><strong style="color:#dc2626;">￥<?php echo number_format($p_group / 100, 2); ?></strong><small style="color:#dc2626;"> 拼团价</small><?php elseif ($p_flash !== null): ?><span style="text-decoration:line-through;color:#94a3b8;font-size:.8rem;margin-right:4px;">￥<?php echo number_format($p_price, 2); ?></span><strong style="color:#dc2626;">￥<?php echo number_format($p_flash / 100, 2); ?></strong><small style="color:#dc2626;"> 限时特价</small><?php else: ?>￥<?php echo number_format($p_price, 2); ?><?php endif; ?></div>
                        </div>
                        
                        <a href="detail.php?id=<?php echo $row['id']; ?>" class="btn-buy-link">
                            👁️ 查看商品
                        </a>
                    </article>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-data">
                    <p>🌰 暂未找到符合条件的商品。</p >
                </div>
            <?php endif; ?>
        </div>
        <nav class="pagination" aria-label="分页">
        <?php if($page>1): ?><a href="<?= h($filter_query(['page'=>$page-1])) ?>">上一页</a><?php endif; ?>
        <span>第 <?php echo $page; ?> 页 · 共 <?php echo $total_products; ?> 件商品</span>
        <?php if($page*12<$total_products): ?><a href="<?= h($filter_query(['page'=>$page+1])) ?>">下一页</a><?php endif; ?>
        </nav>
    </div><!-- /home-main -->
    </div><!-- /home-body -->
    <div class="floor-elevator" id="floor-elevator" aria-label="楼层导航"></div>
    </main>

    <!-- 公共页脚 -->
    <?php require_once __DIR__.'/lib/site_footer.php'; render_site_footer(); ?>

    <!-- 右侧浮动挂件：优惠券（仅登录）+ 回到顶部，滚动 400px 后淡入 -->
    <div class="side-float" id="side-float">
        <?php if ($is_logged_in): ?>
        <a href="my_coupons.php" title="我的优惠券">🎟</a>
        <?php endif; ?>
        <a href="my_orders.php" title="我的订单">📋</a>
        <a href="cart.php" title="购物车">🛒<?php if ($is_logged_in && $sideCartQty > 0): ?><i class="dock-badge"><?= min($sideCartQty, 99) ?></i><?php endif; ?></a>
        <button type="button" class="motion-toggle" id="motion-toggle" title="开/关页面动效">✨</button><a href="javascript:void(0);" id="back-top" title="回到顶部">⬆</a>
    </div>

    <script>
    (function () {
        (function () {
        var mt = document.getElementById('motion-toggle');
        if (!mt) return;
        var sync = function () { mt.style.opacity = localStorage.getItem('hm_motion') === '0' ? '.4' : '1'; };
        mt.addEventListener('click', function () { localStorage.setItem('hm_motion', localStorage.getItem('hm_motion') === '0' ? '1' : '0'); sync(); location.reload(); });
        sync();
    })();
    // 楼层滚动入场 v2（用户反馈"感受不到"后加强）：楼层与楼层内卡片级联错峰入场，
// 一次波浪 0.45s + 每卡递进 45ms（封顶 240ms）；JS 注入类名保无 JS 回退；reduced-motion 守卫
if (localStorage.getItem('hm_motion') !== '0' && 'IntersectionObserver' in window) {
    try {
    var revealTargets = [];
document.querySelectorAll('[data-floor]').forEach(function (s) {
    s.classList.add('floor-reveal');
    revealTargets.push(s);
    s.querySelectorAll('.grid > article.card, .row').forEach(function (c, i) {
        c.classList.add('floor-reveal');
        c.style.setProperty('--reveal-delay', Math.min(i * 45, 240) + 'ms');
        revealTargets.push(c);
    });
});
// 动效开关：站内 localStorage 控制（hm_motion），OS 的 reduce 只作为首次访问初始值——避免 Windows 关动画时用户永远看不到效果
if (localStorage.getItem('hm_motion') === null) localStorage.setItem('hm_motion', window.matchMedia('(prefers-reduced-motion: reduce)').matches ? '0' : '1');
if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
            if (en.isIntersecting) { en.target.classList.add('floor-in'); io.unobserve(en.target); }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    revealTargets.forEach(function (el) { io.observe(el); });
} else {
    // 老内核降级（夸克 7.4 实测无 IntersectionObserver）：scroll + getBoundingClientRect 等效检测，全部点亮后自摘监听
    var pending = revealTargets.slice();
    var checkReveal = function () {
        pending = pending.filter(function (el) {
            if (el.getBoundingClientRect().top < window.innerHeight - 40) { el.classList.add('floor-in'); return false; }
            return true;
        });
        if (!pending.length) window.removeEventListener('scroll', checkReveal);
    };
    window.addEventListener('scroll', checkReveal, { passive: true });
    checkReveal();
    } catch (e) {
        // 任何异常立即移除隐藏类，保证内容可见（动画可牺牲，内容不可消失）
        document.querySelectorAll('.floor-reveal').forEach(function (el) { el.classList.remove('floor-reveal'); });
    }
}
// 秒杀横滑箭头：逐卡步进（卡宽 200 + 间距 12）
        var track = document.getElementById('sk-track');
        var prev = document.getElementById('sk-prev');
        var next = document.getElementById('sk-next');
        if (track && prev && next) {
            var step = function () { return 212 * (track.clientWidth > 480 ? 2 : 1); };
            prev.addEventListener('click', function () { track.scrollBy({ left: -step(), behavior: 'smooth' }); });
            next.addEventListener('click', function () { track.scrollBy({ left: step(), behavior: 'smooth' }); });
        }
    })();
    </script>
    <script>
    (function () {
        // 秒杀倒计时：以最近一场结束时间为终点，HH:MM:SS 逐秒递减，归零后整点刷新
        var cd = document.getElementById('sk-countdown');
        if (cd) {
            var endTs = Date.parse(cd.getAttribute('data-end').replace(' ', 'T'));
            var spans = cd.getElementsByTagName('span');
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var tick = function () {
                var left = Math.max(0, Math.floor((endTs - Date.now()) / 1000));
                spans[0].textContent = pad(Math.floor(left / 3600));
                spans[1].textContent = pad(Math.floor(left % 3600 / 60));
                spans[2].textContent = pad(left % 60);
                if (left <= 0) setTimeout(function () { location.reload(); }, 1500);
            };
            tick(); setInterval(tick, 1000);
        }
        // 楼层电梯导航：扫描 [data-floor] 生成圆点，点击平滑定位，滚动监听高亮当前楼层
        var floors = [].slice.call(document.querySelectorAll('[data-floor]'));
        var nav = document.getElementById('floor-elevator');
        if (nav && floors.length) {
            var dots = floors.map(function (s, i) {
                if (!s.id) s.id = 'home-floor-' + i;
                var a = document.createElement('a');
                a.href = '#' + s.id;
                a.textContent = s.getAttribute('data-floor');
                a.title = s.getAttribute('data-floor');
                a.addEventListener('click', function (e) { e.preventDefault(); s.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
                nav.appendChild(a);
                return a;
            });
            var spy = function () {
                nav.classList.toggle('fl-vis', window.scrollY > 400);
                var cur = 0;
                floors.forEach(function (s, i) { if (s.getBoundingClientRect().top < 200) cur = i; });
                dots.forEach(function (a, i) { a.className = i === cur ? 'fl-on' : ''; });
            };
            window.addEventListener('scroll', spy, { passive: true });
            spy();
        }
    })();
    </script>

    <script>
    (function () {
        var input = document.getElementById('search-keyword');
        var box = document.getElementById('search-suggest');
        if (!input || !box) return;
        var timer = null, seq = 0;
        function hide() { box.style.display = 'none'; box.innerHTML = ''; }
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var kw = input.value.trim();
            if (kw.length < 2) { hide(); return; }
            timer = setTimeout(function () {
                var current = ++seq;
                fetch('search_suggest.php?keyword=' + encodeURIComponent(kw))
                    .then(function (res) { return res.json(); })
                    .then(function (list) {
                        if (current !== seq || !Array.isArray(list) || !list.length) { hide(); return; }
                        box.innerHTML = '';
                        list.forEach(function (word) {
                            var li = document.createElement('li');
                            li.textContent = word;
                            li.style.cssText = 'padding:8px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;';
                            li.addEventListener('mouseenter', function () { li.style.background = '#eff6ff'; });
                            li.addEventListener('mouseleave', function () { li.style.background = '#fff'; });
                            li.addEventListener('click', function () {
                                input.value = word;
                                hide();
                                input.form.submit();
                            });
                            box.appendChild(li);
                        });
                        box.style.display = 'block';
                    })
                    .catch(function () { hide(); });
            }, 300);
        });
        document.addEventListener('click', function (e) {
            if (e.target !== input && !box.contains(e.target)) hide();
        });
    })();
    (function () {
        var sideFloat = document.getElementById('side-float');
        var backTop = document.getElementById('back-top');
        if (sideFloat) {
            var onScroll = function () {
                if (window.scrollY > 400) sideFloat.classList.add('visible');
                else sideFloat.classList.remove('visible');
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }
        if (backTop) {
            backTop.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    })();
    (function () {
        var bar = document.getElementById('search-fixed-bar');
        var hero = document.querySelector('.home-hero');
        if (!bar) return;
        var mainForm = document.querySelector('.home-hero form.search-form');
        var barForm = bar.querySelector('form');
        function sync() {
            if (!mainForm || !barForm) return;
            ['keyword', 'category_id', 'sort'].forEach(function (name) {
                var src = mainForm.elements[name], dst = barForm.elements[name];
                if (src && dst) dst.value = src.value;
            });
        }
        function onScroll() {
            var threshold = hero ? (hero.offsetTop + hero.offsetHeight) : 280;
            if (window.scrollY > threshold) {
                if (!bar.classList.contains('visible')) { sync(); bar.classList.add('visible'); }
            } else {
                bar.classList.remove('visible');
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    })();
    </script>

    <!-- Hero 深空天空（低密度：80 星 / 2 光带，无流星无彗星；登录/注册页仍用全屏正常密度） -->
    <script src="js/sky.js?v=9"></script>
</body>
</html>