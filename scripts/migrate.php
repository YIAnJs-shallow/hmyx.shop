<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/shop.php';
// Additive, repeatable migration. No historical rows are removed or repriced.
if (!function_exists('add_column')) { function add_column($table,$column,$definition) {
    if (!one('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])) query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
} }
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

echo "Migration complete.\n";
