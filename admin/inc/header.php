<?php
// 后台布局头部：菜单按权限动态渲染（$page_title 由页面设置）
require_once __DIR__ . '/auth.php'; // 直接访问本文件时也走 IP 白名单等入口守卫
if (!isset($_SESSION['admin'])) { http_response_code(403); exit('未登录'); }
$current_page = basename($_SERVER['PHP_SELF']);
$admin_menu = [
    ['dashboard.php', '仪表盘', 'dashboard.view'],
    ['products.php', '商品管理', 'product.view'],
    ['product_audit.php', '商品审核', 'product.audit'],
    ['orders.php', '订单/售后', 'order.view'],
    ['users.php', '用户管理', 'user.view'],
    ['roles.php', '角色与权限', 'role.manage'],
    ['admins.php', '管理员账号', 'admin.manage'],
    ['audit.php', '审计日志', 'audit.view'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?=h(csrf_token())?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? h($page_title) : '后台管理'; ?> - 惠蒙优选</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/shop.css">
<style>
        .admin-nav { background: #0f172a; padding: 14px 3%; color: #fff; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
        .admin-nav a { color: #94a3b8; text-decoration: none; margin-left: 18px; font-weight: 500; transition: color 0.2s; }
        .admin-nav a:hover { color: #38bdf8; }
        .admin-nav .brand { color: #38bdf8; font-size: 1.1rem; font-weight: 700; }
        .admin-nav .who { font-size: 0.85rem; color: #64748b; margin-left: 10px; }
        .admin-nav .who .role-tag { background: #1e3a8a; color: #93c5fd; border-radius: 999px; padding: 2px 10px; font-size: 0.75rem; margin-left: 6px; }
        .admin-nav .links { display: flex; align-items: center; flex-wrap: wrap; }
        .crud-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; margin-top: 15px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); }
        .crud-table th, .crud-table td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; }
        .crud-table th { background: #f8fafc; color: #475569; font-weight: 600; }
        .crud-table tr:last-child td { border-bottom: none; }
        .crud-table tr:hover { background-color: #f8fafc; }
        .admin-page-title { font-size: 1.4rem; margin: 24px 0 4px; }
        .admin-page-sub { color: #64748b; font-size: 0.9rem; margin-bottom: 20px; }
     .card { margin:18px 0; padding:20px; } form { display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:10px 0; } form.inline { display:inline-flex; } input,select,button { padding:9px;border-radius:7px;border:1px solid #cbd5e1; } .permission-grid {display:flex;flex-wrap:wrap;gap:14px;width:100%;} .crud-table {display:block;overflow:auto;} .crud-table td {max-width:480px;overflow-wrap:anywhere;} </style>
</head>
<body>
    <div class="admin-nav">
        <div>
            <span class="brand">惠蒙优选 · 管理后台</span>
            <span class="who">
                <?php echo h($_SESSION['admin']['username']); ?>
                <span class="role-tag"><?php echo is_super_admin() ? '超级管理员' : h($role_name ?? ($_SESSION['admin']['role_code'] ?? '')); ?></span>
            </span>
        </div>
        <div class="links">
            <?php foreach ($admin_menu as $item): ?>
                <?php if ($item[0] !== $current_page && admin_has_permission($item[2])): ?>
                    <a href="<?php echo $item[0]; ?>"><?php echo $item[1]; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a href="../index.php" target="_blank">前台 ↗</a>
            <a href="password.php">改密</a>
            <a href="logout.php" style="color: #f87171;">退出</a>
        </div>
    </div>
    <div class="container">

<?php if(!empty($_SESSION['admin_notice'])) { echo '<p class="notice">'.h($_SESSION['admin_notice']).'</p>'; unset($_SESSION['admin_notice']); } ?>
