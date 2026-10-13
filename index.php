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
// GET 搜索时写入搜索日志（未登录 user_id 记 NULL），供联想词与热门搜索统计
if ($keyword !== '' && mb_strlen($keyword) >= 2 && mb_strlen($keyword) <= 100) {
    query('INSERT INTO search_logs(user_id,keyword) VALUES(?,?)', [!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, $keyword]);
}
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

// 只展示已上架且审核通过的商品
$where = " WHERE p.status = 1 AND p.audit_status = 'approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops active_shop WHERE active_shop.id=p.shop_id AND active_shop.status=1))";
$params = [];
$types = "";

// 关键词搜索（修正：仅查询存在的 p.title 字段）
if (!empty($keyword)) {
    $where .= " AND p.title LIKE ?";
    $params[] = "%" . $keyword . "%";
    $types .= "s";
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

// facet 聚合：品牌/材质（复用关键词与分类条件，但各自不带自身维度条件），取前 12 个
$facet_where = $where; $facet_params = $params; $facet_types = $types;
$brand_facets = []; $material_facets = [];
$fstmt = $conn->prepare("SELECT DISTINCT p.brand FROM products p {$facet_where} AND p.brand<>'' ORDER BY p.brand LIMIT 12");
if ($facet_params) $fstmt->bind_param($facet_types, ...$facet_params);
$fstmt->execute(); $fres = $fstmt->get_result();
while ($frow = $fres->fetch_assoc()) $brand_facets[] = $frow['brand'];
$fstmt = $conn->prepare("SELECT DISTINCT p.material FROM products p {$facet_where} AND p.material<>'' ORDER BY p.material LIMIT 12");
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

$sort = $_GET['sort'] ?? 'newest';
$sort_options = ['newest'=>'p.id DESC','price_asc'=>'p.price ASC,p.id DESC','price_desc'=>'p.price DESC,p.id DESC'];
if (!isset($sort_options[$sort])) $sort='newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*12;
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products p {$where}");
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute(); $total_products = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$order_by = $sort_options[$sort];
// 3. 构建商品列表查询 SQL
$sql = "SELECT p.*, c.category_name, s.shop_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN shops s ON p.shop_id = s.id 
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

// 5. 首页推荐板块数据：热销榜 + 猜你喜欢
$best_sellers = best_sellers(10);
$recommend = recommend_for_user((int)($_SESSION['user_id'] ?? 0), 8);

// 筛选链接生成：每个链接只切换自身维度，其余维度原样携带（空值/默认值剔除）
$filter_query = function (array $override = []) use ($keyword, $category_id, $brand, $material, $price_min, $price_max, $sort) {
    $q = array_merge([
        'keyword' => $keyword,
        'category_id' => $category_id,
        'brand' => $brand,
        'material' => $material,
        'price_min' => $price_min === null ? '' : $price_min,
        'price_max' => $price_max === null ? '' : $price_max,
        'sort' => $sort,
    ], $override);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || $v === 0) unset($q[$k]);
        if ($k === 'sort' && $v === 'newest') unset($q[$k]);
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
    <link rel="stylesheet" href="css/shop.css?v=3">
    <link rel="stylesheet" href="css/style.css?v=6">
</head>
<body class="page-home">
        <header>
        <div class="logo">
            <!-- 修复：补全 index.php 跳转链接 -->
            <a href="index.php" style="display: flex; align-items: center; gap: 10px; text-decoration: none;">
                <svg viewBox="0 0 100 100" width="36" height="36">
                    <defs>
                        <linearGradient id="logoBlue" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#3b82f6" />
                            <stop offset="100%" stop-color="#1d4ed8" />
                        </linearGradient>
                    </defs>
                    <rect x="15" y="25" width="70" height="65" rx="14" fill="url(#logoBlue)" />
                    <path d="M 35 25 A 15 15 0 0 1 65 25" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
                    <path d="M 35 45 L 35 70 M 65 45 L 65 70 M 35 57 L 65 57" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
                    <path d="M 75 18 L 77 25 L 84 27 L 77 29 L 75 36 L 73 29 L 66 27 L 73 25 Z" fill="#f59e0b"/>
                </svg>
                <div style="display: flex; flex-direction: column; line-height: 1.1;">
                    <span style="font-size: 1.3rem; font-weight: 800; color: #1e293b;">惠蒙优选</span>
                    <span style="font-size: 0.55rem; font-weight: 700; color: #2563eb; letter-spacing: 1.2px;">HUIMENG CHOICE</span>
                </div>
            </a >
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

                <!-- 个人中心 / 登录 入口 -->
                <li>
                    <?php if ($is_logged_in): ?>
                        <a href="user_center.php" class="nav-cta">👤 个人中心</a>
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

                <select name="sort" class="search-select" aria-label="排序"><?php foreach(['newest'=>'最新上架','price_asc'=>'价格从低到高','price_desc'=>'价格从高到低'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><button type="submit" class="search-btn">搜索</button>

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
                <select name="sort" class="search-select" aria-label="排序"><?php foreach(['newest'=>'最新上架','price_asc'=>'价格从低到高','price_desc'=>'价格从高到低'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
                <input type="hidden" name="brand" value="<?= h($brand) ?>">
                <input type="hidden" name="material" value="<?= h($material) ?>">
                <input type="hidden" name="price_min" value="<?= $price_min === null ? '' : $price_min ?>">
                <input type="hidden" name="price_max" value="<?= $price_max === null ? '' : $price_max ?>">
                <button type="submit" class="search-btn">搜索</button>
                <?php if ($brand !== '' || $material !== '' || $price_min !== null || $price_max !== null): ?>
                    <a href="<?= h($filter_query(['brand'=>'','material'=>'','price_min'=>'','price_max'=>'','keyword'=>$keyword,'category_id'=>$category_id])) ?>" class="reset-btn">清除筛选</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <main class="container" style="margin: 20px auto; padding: 0 15px; width: 100%; box-sizing: border-box;">
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

        <?php if (!empty($best_sellers)): ?>
        <!-- 热销榜：按有效订单累计销量排序 -->
        <section class="card">
            <h3>🔥 热销榜</h3>
            <?php foreach ($best_sellers as $rank => $item): ?>
                <div class="row">
                    <span class="badge"><?php echo $rank + 1; ?></span>
                    <a href="detail.php?id=<?php echo (int)$item['id']; ?>">
                        <?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?>
                            <img class="thumb" src="<?php echo h($item['image_url']); ?>" alt="<?php echo h($item['title']); ?>">
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
        <section class="card">
            <h3>💡 猜你喜欢</h3>
            <div class="grid">
                <?php foreach ($recommend as $item): ?>
                    <article class="card">
                        <a href="detail.php?id=<?php echo (int)$item['id']; ?>">
                            <?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?>
                                <img class="thumb" style="width:100%;height:150px;" src="<?php echo h($item['image_url']); ?>" alt="<?php echo h($item['title']); ?>">
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

        <div class="shop-layout">
        <!-- 筛选侧栏：分类 / 品牌 facet / 材质 facet / 价格区间 -->
        <aside class="filter-side">
            <h4>分类</h4>
            <a href="<?= h($filter_query(['category_id'=>0,'page'=>null])) ?>" class="<?= $category_id <= 0 ? 'f-on' : '' ?>">全部分类</a>
            <?php foreach ($categories as $main_id => $main_cat): ?>
                <a href="<?= h($filter_query(['category_id'=>$main_id,'page'=>null])) ?>" class="<?= $category_id == $main_id ? 'f-on' : '' ?>"><?= h($main_cat['name']) ?></a>
                <?php foreach ($main_cat['subs'] as $sub): ?>
                    <a href="<?= h($filter_query(['category_id'=>$sub['id'],'page'=>null])) ?>" class="<?= $category_id == $sub['id'] ? 'f-on' : '' ?>" style="padding-left:10px;">└ <?= h($sub['name']) ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php if ($brand_facets): ?>
            <h4>品牌</h4>
            <?php foreach ($brand_facets as $b): ?>
                <a href="<?= h($filter_query(['brand'=>$b,'page'=>null])) ?>" class="<?= $brand === $b ? 'f-on' : '' ?>"><?= h($b) ?></a>
                <?php if ($brand === $b): ?><a href="<?= h($filter_query(['brand'=>'','page'=>null])) ?>" class="f-clear" style="color:#dc2626;">× 清除</a><?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($material_facets): ?>
            <h4>材质</h4>
            <?php foreach ($material_facets as $m): ?>
                <a href="<?= h($filter_query(['material'=>$m,'page'=>null])) ?>" class="<?= $material === $m ? 'f-on' : '' ?>"><?= h($m) ?></a>
                <?php if ($material === $m): ?><a href="<?= h($filter_query(['material'=>'','page'=>null])) ?>" class="f-clear" style="color:#dc2626;">× 清除</a><?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <h4>价格区间</h4>
            <form action="index.php" method="GET" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
                <input type="hidden" name="category_id" value="<?= (int)$category_id ?>">
                <input type="hidden" name="brand" value="<?= h($brand) ?>">
                <input type="hidden" name="material" value="<?= h($material) ?>">
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <input type="number" name="price_min" min="0" max="999999" step="0.01" placeholder="最低" value="<?= $price_min === null ? '' : $price_min ?>">
                <span style="color:#94a3b8;font-size:12px;">—</span>
                <input type="number" name="price_max" min="0" max="999999" step="0.01" placeholder="最高" value="<?= $price_max === null ? '' : $price_max ?>">
                <button type="submit" class="f-price-btn">确定</button>
            </form>
        </aside>

        <div>
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
                        // 限时特价：区间内且低于原价时展示特价（划线原价）
                        $p_flash = flash_price_cents($row);
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
                                <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 4px;">🏪 <?php echo htmlspecialchars($row['shop_name']); ?></div>
                            <?php endif; ?>
                            <div class="product-price"><?php if ($p_flash !== null): ?><span style="text-decoration:line-through;color:#94a3b8;font-size:.8rem;margin-right:4px;">￥<?php echo number_format($p_price, 2); ?></span><strong style="color:#dc2626;">￥<?php echo number_format($p_flash / 100, 2); ?></strong><small style="color:#dc2626;"> 限时特价</small><?php else: ?>￥<?php echo number_format($p_price, 2); ?><?php endif; ?></div>
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
        </div>
        </div>
    </main>

    <!-- 公共页脚 -->
    <?php require_once __DIR__.'/lib/site_footer.php'; render_site_footer(); ?>

    <!-- 右侧浮动挂件：优惠券（仅登录）+ 回到顶部，滚动 400px 后淡入 -->
    <div class="side-float" id="side-float">
        <?php if ($is_logged_in): ?>
        <a href="my_coupons.php" title="我的优惠券">🎟</a>
        <?php endif; ?>
        <a href="javascript:void(0);" id="back-top" title="回到顶部">⬆</a>
    </div>

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
        var mainForm = document.querySelector('.home-hero form.search-form');
        if (!bar || !hero) return;
        var barForm = bar.querySelector('form');
        function sync() {
            if (!mainForm || !barForm) return;
            ['keyword', 'category_id', 'sort'].forEach(function (name) {
                var src = mainForm.elements[name], dst = barForm.elements[name];
                if (src && dst) dst.value = src.value;
            });
        }
        function onScroll() {
            var threshold = hero.offsetTop + hero.offsetHeight;
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