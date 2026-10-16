<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/shop.php';
// Additive, repeatable migration. No historical rows are removed or repriced.
if (!function_exists('add_column')) { function add_column($table,$column,$definition) {
    if (!one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])) query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
} }
// ===== 核心基础表（原生建站表）：幂等建表，保证空库可从零初始化（CI / 新环境部署）=====
// DDL 与正式库 SHOW CREATE TABLE 一致（已去除 AUTO_INCREMENT 当前值）；已存在的库执行为空操作
query('CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum(\'user\',\'admin\',\'merchant\') NOT NULL DEFAULT \'user\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `avatar` varchar(255) DEFAULT \'\',
  `points` int NOT NULL DEFAULT \'0\',
  `level_id` tinyint NOT NULL DEFAULT \'1\',
  `total_spent_cents` bigint NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `parent_id` int DEFAULT \'0\' COMMENT \'父级分类ID，0代表顶级分类\',
  `sort_order` int DEFAULT \'0\' COMMENT \'排序权重\',
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `shops` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `shop_name` varchar(100) NOT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `shop_desc` text,
  `status` tinyint DEFAULT \'1\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `business_scope` varchar(100) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `user_addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `receiver_name` varchar(50) NOT NULL,
  `receiver_phone` varchar(20) NOT NULL,
  `receiver_address` varchar(255) NOT NULL,
  `is_default` tinyint(1) DEFAULT \'0\',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shop_id` int DEFAULT NULL COMMENT \'归属店铺ID，NULL为平台自营\',
  `title` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT \'0.00\',
  `stock` int NOT NULL DEFAULT \'0\',
  `category_id` int DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `detail` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` tinyint DEFAULT \'1\' COMMENT \'1:已上架, 0:草稿/未上架\',
  `audit_status` enum(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'approved\' COMMENT \'审核状态\',
  `flash_price_cents` int DEFAULT NULL,
  `flash_start` datetime DEFAULT NULL,
  `flash_end` datetime DEFAULT NULL,
  `limit_per_user` int NOT NULL DEFAULT \'0\',
  `brand` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'品牌\',
  `material` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'材质\',
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `idx_shop_id` (`shop_id`),
  KEY `idx_audit_status` (`audit_status`),
  CONSTRAINT `fk_products_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_no` varchar(64) NOT NULL,
  `user_id` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT \'0.00\',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT \'0.00\',
  `coupon_user_id` int DEFAULT NULL,
  `status` enum(\'pending\',\'paid\',\'shipped\',\'completed\',\'cancelled\') DEFAULT \'pending\',
  `receiver_name` varchar(50) DEFAULT NULL,
  `receiver_phone` varchar(20) DEFAULT NULL,
  `receiver_address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `order_sn` varchar(64) DEFAULT NULL COMMENT \'订单号\',
  `product_id` int DEFAULT \'0\' COMMENT \'商品ID\',
  `products_id` int DEFAULT \'0\',
  `total_price` decimal(10,2) NOT NULL DEFAULT \'0.00\',
  `express_company` varchar(50) DEFAULT \'顺丰速运\' COMMENT \'快递公司\',
  `express_no` varchar(100) DEFAULT \'\' COMMENT \'快递单号\',
  `paid_at` datetime DEFAULT NULL COMMENT \'付款时间\',
  `estimated_shipping_time` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL COMMENT \'发货时间\',
  `shop_id` int DEFAULT NULL,
  `checkout_token` varchar(64) DEFAULT NULL,
  `stock_reserved` tinyint NOT NULL DEFAULT \'0\',
  `payment_method` varchar(20) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `points_paid` int NOT NULL DEFAULT \'0\',
  `points_discount_cents` int NOT NULL DEFAULT \'0\',
  `group_buy_id` int DEFAULT NULL,
  `group_member_id` int DEFAULT NULL,
  `group_success` tinyint NOT NULL DEFAULT \'0\',
  `member_discount_cents` int NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `idx_checkout` (`user_id`,`checkout_token`),
  KEY `idx_expiry` (`status`,`created_at`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int NOT NULL DEFAULT \'1\',
  `product_title` varchar(100) DEFAULT NULL,
  `product_image` varchar(255) DEFAULT NULL,
  `sku_id` int DEFAULT NULL,
  `sku_name` varchar(100) DEFAULT NULL,
  `sku_image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `cart` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT \'1\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sku_id` int NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `idx_user_product` (`user_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `order_item_id` int NOT NULL,
  `product_id` int NOT NULL,
  `rating` tinyint NOT NULL,
  `content` varchar(1000) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reply_content` varchar(500) DEFAULT NULL,
  `replied_at` datetime DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `tags` varchar(255) NOT NULL DEFAULT \'\',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_reviews_order_item` (`order_item_id`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
query('CREATE TABLE IF NOT EXISTS `audit_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL COMMENT \'商品ID\',
  `result` enum(\'approved\',\'rejected\') NOT NULL COMMENT \'审核结果\',
  `comment` varchar(255) DEFAULT \'\' COMMENT \'审核意见\',
  `admin_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'审核时间\',
  `admin_user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `audit_records_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT=\'商品审核记录表\'');
query('CREATE TABLE IF NOT EXISTS `after_sale_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `user_id` int NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum(\'pending\',\'approved\',\'rejected\',\'received\',\'refunded\') NOT NULL DEFAULT \'pending\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `return_express_company` varchar(50) DEFAULT NULL,
  `return_express_no` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `idx_after_sale_status` (`status`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `after_sale_requests_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `after_sale_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');

add_column('orders','shop_id','int NULL');
add_column('orders','checkout_token','varchar(64) NULL');
add_column('orders','stock_reserved','tinyint NOT NULL DEFAULT 0');
add_column('orders','payment_method','varchar(20) NULL');
add_column('orders','completed_at','datetime NULL');
add_column('order_items','product_title','varchar(100) NULL');
add_column('order_items','product_image','varchar(255) NULL');
add_column('products','audit_status',"enum('pending','approved','rejected') NOT NULL DEFAULT 'approved'");
query('UPDATE order_items i JOIN products p ON p.id=i.product_id SET i.product_title=p.title,i.product_image=p.image_url WHERE i.product_title IS NULL');
query('CREATE TABLE IF NOT EXISTS favorites (user_id int NOT NULL,product_id int NOT NULL,created_at timestamp DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,product_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
query('CREATE TABLE IF NOT EXISTS reviews (id int AUTO_INCREMENT PRIMARY KEY,user_id int NOT NULL,order_item_id int NOT NULL,product_id int NOT NULL,rating tinyint NOT NULL,content varchar(1000) NOT NULL,created_at timestamp DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY(order_item_id),KEY(product_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
query("CREATE TABLE IF NOT EXISTS refund_requests(id int AUTO_INCREMENT PRIMARY KEY,order_id int NOT NULL,user_id int NOT NULL,reason varchar(500) NOT NULL,status enum('pending','approved','rejected') DEFAULT 'pending',created_at timestamp DEFAULT CURRENT_TIMESTAMP,processed_at datetime NULL,UNIQUE KEY(order_id),FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
query("CREATE TABLE IF NOT EXISTS after_sale_requests(id int AUTO_INCREMENT PRIMARY KEY,order_id int NOT NULL,user_id int NOT NULL,reason varchar(500) NOT NULL,status enum('pending','approved','rejected','received','refunded') NOT NULL DEFAULT 'pending',created_at timestamp DEFAULT CURRENT_TIMESTAMP,processed_at datetime NULL,received_at datetime NULL,UNIQUE KEY(order_id),KEY idx_after_sale_status(status),FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['orders'=>['idx_checkout'=>'user_id,checkout_token','idx_expiry'=>'status,created_at'],'cart'=>['idx_user_product'=>'user_id,product_id'],'products'=>['idx_audit_status'=>'audit_status']] as $table=>$indexes) foreach($indexes as $name=>$columns) {
    if (!one('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$name])) query("ALTER TABLE `$table` ADD INDEX `$name` ($columns)");
}

// ===== 管理员后台独立化：RBAC + 审计（四张核心表 + 两张支撑表） =====
// 1. 角色表：超级管理员(主账号) / 普通管理员 / 岗位专属角色
query("CREATE TABLE IF NOT EXISTS admin_roles (
    id int AUTO_INCREMENT PRIMARY KEY,
    role_code varchar(30) NOT NULL COMMENT '角色标识',
    role_name varchar(50) NOT NULL COMMENT '角色名称',
    description varchar(200) NOT NULL DEFAULT '',
    is_system tinyint NOT NULL DEFAULT 0 COMMENT '系统内置角色不可删除',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_code (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
query("INSERT IGNORE INTO admin_roles (role_code,role_name,description,is_system) VALUES
    ('super','超级管理员','平台主账号，拥有全部权限（全库唯一）',1),
    ('normal_admin','普通管理员','全部业务权限，不含角色与管理员管理',1),
    ('product_auditor','商品审核员','商品查看与审核',0),
    ('order_manager','订单管理员','订单查看、发货、退款售后',0),
    ('user_manager','用户管理员','前台用户查看与管理',0)");

// 2. 权限表
query("CREATE TABLE IF NOT EXISTS admin_permissions (
    id int AUTO_INCREMENT PRIMARY KEY,
    permission_code varchar(50) NOT NULL COMMENT '如 product.audit',
    module varchar(30) NOT NULL COMMENT '所属模块，用于分组展示',
    description varchar(100) NOT NULL DEFAULT '',
    UNIQUE KEY uk_permission_code (permission_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
query("INSERT IGNORE INTO admin_permissions (permission_code,module,description) VALUES
    ('dashboard.view','仪表盘','查看后台仪表盘'),
    ('product.view','商品','查看商品列表'),
    ('product.audit','商品','商品审核'),
    ('product.manage','商品','商品增删改与上下架'),
    ('order.view','订单','查看订单'),
    ('order.ship','订单','订单发货'),
    ('order.refund','订单','退款与售后处理'),
    ('user.view','用户','查看前台用户'),
    ('user.manage','用户','修改前台用户角色'),
    ('role.view','权限','查看角色列表'),
    ('role.manage','权限','角色与授权管理（仅超管）'),
    ('admin.manage','管理员','管理员账号管理（仅超管）'),
    ('audit.view','审计','查看审计日志')");

// 3. 角色-权限关联表
query("CREATE TABLE IF NOT EXISTS admin_role_permissions (
    role_id int NOT NULL,
    permission_id int NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_permission (permission_id),
    FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES admin_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// 授权矩阵（幂等写入）：普通管理员=除 role.manage/admin.manage 外全部；岗位角色按职责最小化
query("INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM admin_roles r JOIN admin_permissions p
    WHERE (r.role_code, p.permission_code) IN (
        ('normal_admin','dashboard.view'),('normal_admin','product.view'),('normal_admin','product.audit'),
        ('normal_admin','product.manage'),('normal_admin','order.view'),('normal_admin','order.ship'),
        ('normal_admin','order.refund'),('normal_admin','user.view'),('normal_admin','user.manage'),
        ('normal_admin','role.view'),('normal_admin','audit.view'),
        ('product_auditor','dashboard.view'),('product_auditor','product.view'),('product_auditor','product.audit'),
        ('order_manager','dashboard.view'),('order_manager','order.view'),('order_manager','order.ship'),('order_manager','order.refund'),
        ('user_manager','dashboard.view'),('user_manager','user.view'),('user_manager','user.manage')
    )");

// 4. 管理员账号表（与前台 users 完全隔离）
query("CREATE TABLE IF NOT EXISTS admin_users (
    id int AUTO_INCREMENT PRIMARY KEY,
    username varchar(50) NOT NULL,
    password varchar(255) NOT NULL COMMENT 'bcrypt',
    real_name varchar(50) NOT NULL DEFAULT '',
    role_id int NOT NULL,
    is_super tinyint NOT NULL DEFAULT 0 COMMENT '超级管理员标记，全库唯一',
    status enum('active','disabled') NOT NULL DEFAULT 'active',
    must_change_password tinyint NOT NULL DEFAULT 0 COMMENT '首次登录/重置后强制改密',
    failed_attempts int NOT NULL DEFAULT 0,
    locked_until datetime NULL COMMENT '锁定截止时间',
    last_login_at datetime NULL,
    last_login_ip varchar(45) NOT NULL DEFAULT '',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_username (username),
    KEY idx_role (role_id),
    FOREIGN KEY (role_id) REFERENCES admin_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// 唯一超管约束：生成列仅在 is_super=1 时取值 1（否则 NULL），唯一索引允许多个 NULL
if (!one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_users' AND COLUMN_NAME='super_flag'")) {
    query("ALTER TABLE admin_users ADD COLUMN super_flag tinyint GENERATED ALWAYS AS (IF(is_super = 1, 1, NULL)) VIRTUAL");
    query("ALTER TABLE admin_users ADD UNIQUE KEY uk_only_one_super (super_flag)");
}

// 5. 通用审计日志（全后台写操作留痕，不删不改）
query("CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    admin_id int NULL COMMENT '失败登录且用户不存在时为 NULL',
    username varchar(50) NOT NULL DEFAULT '' COMMENT '快照，防删号后失查',
    action varchar(50) NOT NULL COMMENT '如 order.ship / user.role.change',
    target_type varchar(30) NOT NULL DEFAULT '' COMMENT 'product/order/user/admin/role/login',
    target_id varchar(64) NOT NULL DEFAULT '',
    detail varchar(1000) NOT NULL DEFAULT '' COMMENT 'JSON：旧值/新值/意见等',
    status enum('success','failure') NOT NULL DEFAULT 'success',
    ip varchar(45) NOT NULL DEFAULT '',
    user_agent varchar(255) NOT NULL DEFAULT '',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_time (admin_id, created_at),
    KEY idx_action_time (action, created_at),
    KEY idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 6. 登录尝试流水（撞库/扫描分析用；锁定判定走 admin_users.failed_attempts+locked_until）
query("CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    username varchar(50) NOT NULL,
    ip varchar(45) NOT NULL DEFAULT '',
    success tinyint NOT NULL DEFAULT 0,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    KEY idx_username_time (username, created_at),
    KEY idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

add_column('audit_records','admin_user_id','int NULL');
query('ALTER TABLE audit_records MODIFY admin_id int NULL');

// ===== 找回密码：用户邮箱唯一化 + 邮箱验证码表 =====
// 历史空字符串邮箱归一为 NULL（NULL 不参与唯一索引），保证一邮箱一账号
query("UPDATE users SET email=NULL WHERE email=''");
if (!one('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', ['users','uk_email'])) {
    query('ALTER TABLE users ADD UNIQUE KEY uk_email (email)');
}
// 验证码只存 sha256 哈希；failed_attempts/locked_until 实现“连错5次锁30分钟”
query("CREATE TABLE IF NOT EXISTS password_reset_codes (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    email varchar(100) NOT NULL,
    code_hash char(64) NOT NULL,
    expires_at datetime NOT NULL,
    failed_attempts int NOT NULL DEFAULT 0,
    locked_until datetime NULL,
    used_at datetime NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_id (email, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ===== 优惠券：券模板 + 用户持券 =====
query("CREATE TABLE IF NOT EXISTS coupons (
    id int AUTO_INCREMENT PRIMARY KEY,
    title varchar(100) NOT NULL,
    discount_cents int NOT NULL,
    min_spend_cents int NOT NULL DEFAULT 0,
    total_count int NOT NULL,
    claimed_count int NOT NULL DEFAULT 0,
    active tinyint NOT NULL DEFAULT 1,
    is_newuser tinyint NOT NULL DEFAULT 0,
    expire_at datetime NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
query("CREATE TABLE IF NOT EXISTS user_coupons (
    id int AUTO_INCREMENT PRIMARY KEY,
    coupon_id int NOT NULL,
    user_id int NOT NULL,
    status enum('unused','used') NOT NULL DEFAULT 'unused',
    claimed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at datetime NULL,
    UNIQUE KEY uk_coupon_user (coupon_id, user_id),
    KEY idx_uc_user (user_id, status),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND COLUMN_NAME="discount_amount"')) {
    query('ALTER TABLE orders ADD COLUMN discount_amount decimal(10,2) NOT NULL DEFAULT 0 AFTER total_amount');
}
if (!one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND COLUMN_NAME="coupon_user_id"')) {
    query('ALTER TABLE orders ADD COLUMN coupon_user_id int NULL AFTER discount_amount');
}
// 内置新人券与公开券（仅首次创建，可重复执行）
if (!one('SELECT id FROM coupons WHERE title="新人专享：满100减20" LIMIT 1')) {
    query("INSERT INTO coupons(title,discount_cents,min_spend_cents,total_count,is_newuser) VALUES('新人专享：满100减20',2000,10000,10000,1)");
}
if (!one('SELECT id FROM coupons WHERE title="满50减5" LIMIT 1')) {
    query("INSERT INTO coupons(title,discount_cents,min_spend_cents,total_count) VALUES('满50减5',500,5000,1000)");
}

// ===== 买卖家订单留言：按身份分别记录已读（买家/商户/管理员三方独立） =====
query("CREATE TABLE IF NOT EXISTS order_messages (
    id int AUTO_INCREMENT PRIMARY KEY,
    order_id int NOT NULL,
    sender_role enum('buyer','merchant','admin') NOT NULL COMMENT '发送方身份',
    sender_id int NOT NULL COMMENT '买家/商户为 users.id，管理员为 admin_users.id',
    content varchar(500) NOT NULL,
    read_by_buyer tinyint NOT NULL DEFAULT 0,
    read_by_merchant tinyint NOT NULL DEFAULT 0,
    read_by_admin tinyint NOT NULL DEFAULT 0,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_messages (order_id, id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ===== SKU 商品规格：products.price/stock 保留为默认规格，有 status=1 的 SKU 时走 SKU 逻辑 =====
query("CREATE TABLE IF NOT EXISTS product_skus (
    id int AUTO_INCREMENT PRIMARY KEY,
    product_id int NOT NULL,
    sku_name varchar(100) NOT NULL,
    price_cents int NOT NULL,
    stock int NOT NULL DEFAULT 0,
    status tinyint NOT NULL DEFAULT 1,
    KEY idx_sku_product_status (product_id, status),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// order_items 记录 sku_id/sku_name 快照（无外键，历史订单与规格改动互不影响）；cart 按 (product_id, sku_id) 聚合
add_column('order_items','sku_id','int NULL');
add_column('order_items','sku_name','varchar(100) NULL');
add_column('cart','sku_id','int NOT NULL DEFAULT 0');
// ===== SKU 规格图片：product_skus.image_url 为当前图，order_items.sku_image 为下单快照（无图 NULL，展示回退商品主图） =====
add_column('product_skus','image_url','varchar(255) NULL DEFAULT NULL AFTER stock');
add_column('order_items','sku_image','varchar(255) NULL');

// ===== 售后退货物流：买家在商家同意退货后填写退货快递（商户确认收货前展示） =====
add_column('after_sale_requests','return_express_company','varchar(50) NULL');
add_column('after_sale_requests','return_express_no','varchar(100) NULL');

// ===== 评价体系升级：商家回复 / 追评 / 晒图 =====
add_column('reviews','reply_content','varchar(500) NULL');
add_column('reviews','replied_at','datetime NULL');
add_column('reviews','parent_id','int NULL');
// 追评需同一 order_item_id 存在多行（首评 + 追评），将唯一索引降级为普通索引；首评唯一性改由写入前查询保证
$reviewItemIndex = one('SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', ['reviews','order_item_id']);
if ($reviewItemIndex && (int)$reviewItemIndex['NON_UNIQUE'] === 0) {
    query('ALTER TABLE reviews DROP INDEX order_item_id, ADD INDEX idx_reviews_order_item (order_item_id)');
}
query('CREATE TABLE IF NOT EXISTS review_images (
    id int AUTO_INCREMENT PRIMARY KEY,
    review_id int NOT NULL,
    image_url varchar(255) NOT NULL,
    KEY idx_review_images_review (review_id),
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// ===== 搜索联想与历史：仅日志表，联想与热门搜索均实时统计（无缓存表） =====
query('CREATE TABLE IF NOT EXISTS search_logs (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    user_id int NULL,
    keyword varchar(100) NOT NULL,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    KEY idx_search_logs_keyword (keyword),
    KEY idx_search_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// ===== 消息中心：站内通知（未读角标与消息列表共用 KEY(user_id, read_at)） =====
query("CREATE TABLE IF NOT EXISTS user_notifications (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    user_id int NOT NULL,
    type enum('message','order','refund','coupon') NOT NULL,
    title varchar(100) NOT NULL,
    body varchar(255) NOT NULL,
    link varchar(255) NOT NULL DEFAULT '',
    read_at datetime NULL,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_notifications (user_id, read_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// 收藏商品降价提醒：type 枚举幂等扩展 'price'（已有库升级，缺则补）
$notifType = one("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_notifications' AND COLUMN_NAME='type'");
if ($notifType && strpos($notifType['COLUMN_TYPE'], "'price'") === false) {
    query("ALTER TABLE user_notifications MODIFY type enum('message','order','refund','coupon','price') NOT NULL");
}

// ===== 限时特价与限购：flash 区间内展示价用特价，limit_per_user=0 表示不限购 =====
add_column('products','flash_price_cents','int NULL');
add_column('products','flash_start','datetime NULL');
add_column('products','flash_end','datetime NULL');
add_column('products','limit_per_user','int NOT NULL DEFAULT 0');

// ===== 邮箱验证码登录：验证码只存 sha256 哈希，10 分钟有效、60 秒限发、连错 5 次锁 30 分钟（沿用 password_reset_codes 字段模式） =====
query("CREATE TABLE IF NOT EXISTS email_login_codes (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    email varchar(100) NOT NULL,
    code_hash char(64) NOT NULL,
    expires_at datetime NOT NULL,
    used_at datetime NULL,
    failed_attempts int NOT NULL DEFAULT 0,
    locked_until datetime NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ===== 企业/商户注册：店铺经营范围（注册页新增三项之一，与 shop_manage.php 申请入驻共用 shops 表） =====
add_column('shops','business_scope','varchar(100) NOT NULL DEFAULT \'\'');

// ===== 搜索筛选增强：品牌/材质维度 =====
add_column('products','brand',"varchar(50) NOT NULL DEFAULT '' COMMENT '品牌'");
add_column('products','material',"varchar(50) NOT NULL DEFAULT '' COMMENT '材质'");
// 幂等回填：仅当当前值为空时按 id 精确更新，可重复执行
$brand_backfill = [
    15=>'外星仔',17=>'外星仔',18=>'幻影',19=>'星轨',20=>'闪极',21=>'回音',22=>'云感',23=>'云感',
    24=>'惠蒙女装',25=>'惠蒙运动',26=>'惠蒙箱包',27=>'惠蒙运动',28=>'云感',29=>'云感',30=>'惠蒙女装',
    31=>'惠蒙配件',32=>'惠蒙厨具',33=>'惠蒙家纺',34=>'惠蒙厨具',35=>'惠美研',
];
$material_backfill = [
    18=>'ABS',19=>'PBT键帽',20=>'铝合金',21=>'织物',22=>'纯棉',23=>'毛圈棉',24=>'雪纺',25=>'网面',
    26=>'尼龙',27=>'NBR',28=>'精梳棉',29=>'纯棉',30=>'丹宁',31=>'棉斜纹',32=>'麦饭石涂层',
    33=>'全棉磨毛',34=>'316不锈钢',
];
foreach ($brand_backfill as $pid=>$bval) {
    query('UPDATE products SET brand=? WHERE id=? AND brand=\'\'', [$bval, $pid]);
}
foreach ($material_backfill as $pid=>$mval) {
    query('UPDATE products SET material=? WHERE id=? AND material=\'\'', [$mval, $pid]);
}

// ===== 评价印象标签：首评可勾选固定候选标签，逗号分隔存储，旧评价空串兼容 =====
add_column('reviews','tags',"varchar(255) NOT NULL DEFAULT ''");

// ===== 商品浏览足迹：登录用户浏览详情页记录（插入不去重，展示端按商品最新一条去重） =====
query("CREATE TABLE IF NOT EXISTS user_browsing (
    id bigint AUTO_INCREMENT PRIMARY KEY,
    user_id int NOT NULL,
    product_id int NOT NULL,
    viewed_at datetime NOT NULL,
    KEY idx_user_time (user_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ===== 签到+积分体系：users 余额列 / 积分流水 / 签到记录 / 订单积分抵扣列 =====
add_column('users','points','int NOT NULL DEFAULT 0');
query("CREATE TABLE IF NOT EXISTS points_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    change_points INT NOT NULL COMMENT '正加负减',
    type ENUM('signin','order','review','followup','redeem','pay') NOT NULL,
    ref_id INT NULL COMMENT '关联 order_id/review_id，signin 传 0',
    remark VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
query("CREATE TABLE IF NOT EXISTS user_signins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sign_date DATE NOT NULL,
    streak INT NOT NULL DEFAULT 1 COMMENT '当日连续签到天数',
    UNIQUE KEY uk_user_date (user_id, sign_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
add_column('orders','points_paid','int NOT NULL DEFAULT 0');
add_column('orders','points_discount_cents','int NOT NULL DEFAULT 0');

// ===== 拼团功能：活动表 + 成员表（虚拟总团池，团=团长成员行，成团结果落 orders.group_success） =====
query("CREATE TABLE IF NOT EXISTS group_buys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    sku_id INT NULL COMMENT '无规格商品为 NULL（拼团仅支持无规格商品）',
    price_cents INT NOT NULL COMMENT '团购价（分）',
    size INT NOT NULL COMMENT '成团人数（>=2，上限 10）',
    max_groups INT NOT NULL DEFAULT 0 COMMENT '总团数上限，0=不限',
    duration_hours INT NOT NULL DEFAULT 3 COMMENT '成团时限（小时），与订单关单窗口一致',
    status ENUM('active','ended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product (product_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
query("CREATE TABLE IF NOT EXISTS group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL COMMENT '团 id（团长成员行 id，团长行回填为自身 id）',
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    role ENUM('leader','member') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入团时间；团长行时间即开团时间（成团时限起点）',
    UNIQUE KEY uk_group_user (group_id, user_id),
    KEY idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
add_column('group_members','created_at',"datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入团时间；团长行时间即开团时间'");
add_column('orders','group_buy_id','int NULL');
add_column('orders','group_member_id','int NULL');
add_column('orders','group_success','tinyint NOT NULL DEFAULT 0');

// ===== 会员等级体系：阈值表 + users 快照列 + 等级流水 + 订单会员折扣列 =====
// 建模为 CRMEB 混合式：user_levels 存阈值/折扣，users 冗余 level_id/total_spent_cents 快照，level_log 留痕对账
query("CREATE TABLE IF NOT EXISTS user_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(20) NOT NULL,
    min_spent_cents BIGINT NOT NULL COMMENT '升级门槛（累计实付，分）',
    discount_percent INT NOT NULL DEFAULT 100 COMMENT '100=无折扣，95=95折',
    sort INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// 种子（按 sort 顺序插入保证 普通=1 / 银卡=2 / 金卡=3；uk_name 保证幂等）
query("INSERT IGNORE INTO user_levels(name,min_spent_cents,discount_percent,sort) VALUES
    ('普通',0,100,1),
    ('银卡',50000,98,2),
    ('金卡',200000,95,3)");
add_column('users','level_id','tinyint NOT NULL DEFAULT 1');
add_column('users','total_spent_cents','bigint NOT NULL DEFAULT 0');
query("CREATE TABLE IF NOT EXISTS level_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id INT NULL,
    delta_cents BIGINT NOT NULL COMMENT '本次累计实付变动（分）；等级变更行恒为 0',
    old_level_id TINYINT NOT NULL,
    new_level_id TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
add_column('orders','member_discount_cents','int NOT NULL DEFAULT 0');

echo "Migration complete.\n";
