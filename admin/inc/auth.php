<?php
// 后台页面统一入口守卫：加载依赖 + 刷新管理员会话
// 页面在 require 本文件后自行调用 require_admin_login() / require_permission()
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';
require_once __DIR__ . '/../../lib/shop.php'; // query()/rows()/one() 辅助函数

// L0 IP 白名单：非白名单 IP 与不存在的页面表现一致（404），不暴露后台存在性
admin_require_allowed_ip();

refresh_admin_session();

// 后台安全响应头
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
}
