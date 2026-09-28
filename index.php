<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';

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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>惠蒙优选 - 精选优质商城</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; display: flex; flex-direction: column; min-height: 100vh; }
        header { background: #fff; padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; }
        
        .search-bar-container {
            background: #ffffff;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .search-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .search-input, .search-select {
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.95rem;
            outline: none;
        }
        .search-input { flex: 1; min-width: 200px; }
        .search-btn {
            background-color: #2563eb;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }
        .reset-btn {
            background-color: #f1f5f9;
            color: #64748b;
            padding: 10px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .product-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .product-img-wrapper {
            height: 180px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .product-img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-title {
            font-size: 1rem;
            font-weight: bold;
            color: #1e293b;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .product-price {
            font-size: 1.15rem;
            color: #dc2626;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .btn-buy-link {
            display: block;
            margin-top: 10px;
            padding: 9px 0;
            background-color: #2563eb;
            color: #ffffff;
            text-align: center;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        .btn-buy-link:hover { background-color: #1d4ed8; }
        .no-data { grid-column: 1 / -1; text-align: center; padding: 40px; color: #64748b; }

        /* 底部 Footer 样式 */
        .site-footer {
            margin-top: auto;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 20px 0;
            text-align: center;
            color: #64748b;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
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
            <ul style="list-style: none; display: flex; gap: 20px; align-items: center; margin: 0;">
                <!-- 购物车 -->
                <li>
                    <?php if ($is_logged_in): ?>
                        <a href="cart.php" style="text-decoration: none; color: #334155; font-weight: bold;">🛒 购物车</a > <a href="favorites.php">我的收藏</a> <a href="my_orders.php">我的订单</a>
                    <?php else: ?>
                        <a href="javascript:void(0);" onclick="alert('请先登录账号！'); location.href='user_login.php';" style="text-decoration: none; color: #334155; font-weight: bold;">🛒 购物车</a >
                    <?php endif; ?>
                </li>
                
                <!-- 个人中心 / 登录 入口 -->
                <li>
                    <?php if ($is_logged_in): ?>
                        <!-- 已登录：跳转 user_center.php -->
                        <a href="user_center.php" style="text-decoration: none; color: #2563eb; font-weight: bold; background: #eff6ff; padding: 6px 14px; border-radius: 20px; border: 1px solid #bfdbfe; display: inline-block;">
                            👤 个人中心
                        </a >
                    <?php else: ?>
                        <!-- 未登录：跳转 user_login.php（补全了 href="user_login.php"） -->
                        <a href="user_login.php" style="text-decoration: none; color: #fff; font-weight: bold; background: #2563eb; padding: 6px 16px; border-radius: 20px; display: inline-block;">
                            登录 / 注册
                        </a >
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    </header>

    <main class="container" style="max-width: 1200px; margin: 20px auto; padding: 0 15px; width: 100%; box-sizing: border-box;">
        <div class="search-bar-container">
            <form action="index.php" method="GET" class="search-form">
                <input type="text" name="keyword" class="search-input" placeholder="🔍 输入商品名称关键词搜索..." value="<?php echo htmlspecialchars($keyword); ?>">
                
                <select name="category_id" class="search-select">
    <option value="0">全部分类</option>
    <?php foreach ($categories as $main_id => $main_cat): ?>
        <!-- 一级大类 -->
        <option value="<?php echo $main_id; ?>" <?php echo $category_id == $main_id ? 'selected' : ''; ?> style="font-weight: bold;">
            【<?php echo htmlspecialchars($main_cat['name']); ?>】
        </option>
        
        <!-- 二级细分分类 -->
        <?php foreach ($main_cat['subs'] as $sub): ?>
            <option value="<?php echo $sub['id']; ?>" <?php echo $category_id == $sub['id'] ? 'selected' : ''; ?>>
                &nbsp;&nbsp;&nbsp;&nbsp;├─ <?php echo htmlspecialchars($sub['name']); ?>
            </option>
        <?php endforeach; ?>
    <?php endforeach; ?>
</select>

                <select name="sort" class="search-select" aria-label="排序"><?php foreach(['newest'=>'最新上架','price_asc'=>'价格从低到高','price_desc'=>'价格从高到低'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><button type="submit" class="search-btn">搜索</button>
                
                <?php if (!empty($keyword) || $category_id > 0): ?>
                    <a href="index.php" class="reset-btn">重置条件</a >
                <?php endif; ?>
            </form>
        </div>

        <div class="page-header">
            <h2 style="font-size: 1.25rem; color: #0f172a; margin-bottom: 15px;">
                <?php 
                    if (!empty($keyword)) {
                        echo '搜索结果："' . htmlspecialchars($keyword) . '"';
                    } else {
                        echo '热门选购商品';
                    }
                ?>
            </h2>
        </div>
        
        <div class="product-grid">
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                    <?php 
                        $p_name = $row['title'] ?? ($row['name'] ?? '未命名商品');
                        $p_img = $row['image_url'] ?? ($row['image'] ?? '');
                        $p_price = $row['price'] ?? '0.00';
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
                            <div class="product-price">￥<?php echo number_format($p_price, 2); ?></div>
                        </div>
                        
                        <a href="detail.php?id=<?php echo $row['id']; ?>" class="btn-buy-link" style="background-color: #2563eb;">
                            👁️ 查看商品
                        </a >
                    </article>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-data">
                    <p>🌰 暂未找到符合条件的商品。</p >
                </div>
            <?php endif; ?>
        </div>
        <nav style="display:flex;gap:20px;padding:24px 0;align-items:center;" aria-label="分页">
        <?php if($page>1): ?><a href="?<?php echo htmlspecialchars(http_build_query(['keyword'=>$keyword,'category_id'=>$category_id,'sort'=>$sort,'page'=>$page-1])); ?>">上一页</a><?php endif; ?>
        <span>第 <?php echo $page; ?> 页 · 共 <?php echo $total_products; ?> 件商品</span>
        <?php if($page*12<$total_products): ?><a href="?<?php echo htmlspecialchars(http_build_query(['keyword'=>$keyword,'category_id'=>$category_id,'sort'=>$sort,'page'=>$page+1])); ?>">下一页</a><?php endif; ?>
        </nav>
    </main>

    <!-- 底部版权信息 -->
    <footer class="site-footer">
        <p>&copy; <?php echo date('Y'); ?> 惠蒙优选 All Rights Reserved.</p >
    </footer>
</body>
</html>