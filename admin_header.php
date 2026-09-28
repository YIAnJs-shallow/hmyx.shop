<?php
require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/lib/security.php';
}

// 【关键修复】后台拦截：未登录，或者登录的不是管理员（role != 'admin'）
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo "<script>alert('越权操作：请先以管理员身份登录！'); location.href='login.html';</script>";
    exit();
}

// 获取当前运行的 PHP 文件名称
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台控制台 - 惠蒙优选</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .admin-nav { background: #0f172a; padding: 14px 5%; color: #fff; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .admin-nav a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-weight: 500; transition: color 0.2s; }
        .admin-nav a:hover { color: #38bdf8; }
        .crud-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; margin-top: 15px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); }
        .crud-table th, .crud-table td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .crud-table th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.9rem; }
        .crud-table tr:last-child td { border-bottom: none; }
        .crud-table tr:hover { background-color: #f8fafc; }
    </style>
</head>
<body>
    <div class="admin-nav">
        <div>
            <strong style="color: #38bdf8; font-size: 1.1rem;">惠蒙优选后台</strong> 
            <span style="font-size:0.85rem; color:#64748b; margin-left:10px;">管理员：<?php echo htmlspecialchars($_SESSION['username']); ?></span>
        </div>
        <div>
            <?php if ($current_page !== 'admin_dashboard.php'): ?>
                <a href="admin_dashboard.php">仪表盘</a >
            <?php endif; ?>
            <?php if ($current_page !== 'admin.php'): ?>
                <a href="admin.php">商品管理</a >
            <?php endif; ?>
            <?php if ($current_page !== 'admin_audit.php'): ?>
                <a href="admin_audit.php">商品审核</a >
            <?php endif; ?>
            <?php if ($current_page !== 'admin_users.php'): ?>
                <a href="admin_users.php">用户管理</a >
            <?php endif; ?>
            <a href="admin_orders.php">订单 / 售后</a>
            <a href="index.php" target="_blank">预览前台 ↗</a >
            <a href="logout.php" style="color: #f87171;">退出登录</a >
        </div>
    </div>
    <div class="container">
