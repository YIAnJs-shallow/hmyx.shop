<?php
require_once 'config/db.php';
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('权限不足'); }
require_once 'config/db.php';

// 处理下架商品请求
$msg = '';
$msg_type = '';

if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    header('Location: product_delete.php?id=' . intval($_GET['id'] ?? 0)); exit;
}

require_once 'admin_header.php';
// 搜索与筛选逻辑
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$cat_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$audit_filter = isset($_GET['audit_status']) ? trim($_GET['audit_status']) : '';

$where = " WHERE 1=1";
$params = [];
$types = "";

if (!empty($keyword)) {
    $where .= " AND p.title LIKE ?";
    $params[] = "%" . $keyword . "%";
    $types .= "s";
}

if ($cat_id > 0) {
    $where .= " AND p.category_id = ?";
    $params[] = $cat_id;
    $types .= "i";
}

if (!empty($audit_filter) && in_array($audit_filter, ['pending', 'approved', 'rejected'])) {
    $where .= " AND p.audit_status = ?";
    $params[] = $audit_filter;
    $types .= "s";
}

// 查询所有分类供筛选
$categories_res = $conn->query("SELECT * FROM categories ORDER BY parent_id ASC, id ASC");

// 查询商品列表
$sql = "SELECT p.*, c.category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        {$where} 
        ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("数据库查询准备失败: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products_result = $stmt->get_result();
?>

<div style="max-width: 1100px; margin: 30px auto; padding: 0 20px;">

    <!-- 操作结果提示 -->
    <?php if (!empty($msg)): ?>
        <div style="padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; 
                    background: <?php echo $msg_type === 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
                    color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>;">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- 顶部操作栏 -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; color: #1e293b;">商品管理列表</h2>
        
        <button type="button" 
                onclick="window.location.href='product_add.php';" 
                style="background: #2563eb; color: #ffffff; padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; box-shadow: 0 2px 4px rgba(37,99,235,0.2);">
            + 添加新商品
        </button>
    </div>

    <!-- 搜索筛选栏 -->
    <form method="GET" action="admin.php" style="display: flex; gap: 12px; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <input type="text" name="keyword" placeholder="搜索商品名称..." value="<?php echo htmlspecialchars($keyword); ?>" style="flex: 1; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
        
        <select name="category_id" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
            <option value="0">全部分类</option>
            <?php if ($categories_res): ?>
                <?php while ($cat = $categories_res->fetch_assoc()): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $cat_id == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo $cat['parent_id'] > 0 ? '└─ ' : ''; ?><?php echo htmlspecialchars($cat['category_name']); ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>

        <select name="audit_status" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
            <option value="">全部审核状态</option>
            <option value="pending" <?php echo $audit_filter === 'pending' ? 'selected' : ''; ?>>待审核</option>
            <option value="approved" <?php echo $audit_filter === 'approved' ? 'selected' : ''; ?>>已通过</option>
            <option value="rejected" <?php echo $audit_filter === 'rejected' ? 'selected' : ''; ?>>已驳回</option>
        </select>

        <button type="submit" style="background: #0f172a; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; cursor: pointer;">搜索</button>
    </form>

    <!-- 商品数据表格 -->
    <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 0.9rem;">
                    <th style="padding: 12px 16px;">ID</th>
                    <th style="padding: 12px 16px;">商品主图</th>
                    <th style="padding: 12px 16px;">商品名称</th>
                    <th style="padding: 12px 16px;">分类</th>
                    <th style="padding: 12px 16px;">价格</th>
                    <th style="padding: 12px 16px;">库存</th>
                    <th style="padding: 12px 16px;">上架状态</th>
                    <th style="padding: 12px 16px;">审核状态</th>
                    <th style="padding: 12px 16px; text-align: center;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products_result && $products_result->num_rows > 0): ?>
                    <?php while ($p = $products_result->fetch_assoc()): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 16px; color: #64748b;"><?php echo $p['id']; ?></td>
                            <td style="padding: 12px 16px;">
                                <?php if (!empty($p['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($p['image_url']); ?>" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px;">
                                <?php else: ?>
                                    <div style="width: 48px; height: 48px; background: #f1f5f9; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; color: #94a3b8;">无图</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; font-weight: bold; color: #1e293b;"><?php echo htmlspecialchars($p['title']); ?></td>
                            <td style="padding: 12px 16px; color: #64748b;">
                                <?php echo htmlspecialchars($p['category_name'] ?? '未分类'); ?>
                            </td>
                            <td style="padding: 12px 16px; color: #dc2626; font-weight: bold;">￥<?php echo number_format($p['price'], 2); ?></td>
                            <td style="padding: 12px 16px; color: #334155;"><?php echo $p['stock']; ?> 件</td>
                            <td style="padding: 12px 16px;">
                                <?php if (isset($p['status']) && $p['status'] == 1): ?>
                                    <span style="background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">已上架</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">草稿</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px;">
                                <?php 
                                $audit_status = $p['audit_status'] ?? 'pending';
                                if ($audit_status === 'pending'): ?>
                                    <span style="background: #fef3c7; color: #b45309; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">待审核</span>
                                <?php elseif ($audit_status === 'approved'): ?>
                                    <span style="background: #d1fae5; color: #047857; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">已通过</span>
                                <?php elseif ($audit_status === 'rejected'): ?>
                                    <span style="background: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">已驳回</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; text-align: center;">
                                <a href="product_edit.php?id=<?php echo $p['id']; ?>" style="color: #2563eb; text-decoration: none; margin-right: 12px; font-weight: bold;">编辑</a>
                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo addslashes($p['title']); ?>')" style="color: #ef4444; text-decoration: none; font-weight: bold;">下架</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #94a3b8;">暂无商品数据</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmDelete(id, title) {
    if (confirm('确定要下架商品【' + title + '】吗？')) {
        window.location.href = 'admin.php?action=delete&id=' + id;
    }
}
</script>
