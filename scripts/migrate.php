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
echo "Migration complete.\n";
