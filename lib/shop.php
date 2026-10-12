<?php
require_once __DIR__.'/../config/db.php';

function query($sql, $params = []) {
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}
function rows($sql, $params = []) { return query($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC); }
function one($sql, $params = []) { return query($sql, $params)->get_result()->fetch_assoc(); }
function uid() {
    if (empty($_SESSION['user_id'])) login_redirect(current_page_url());
    return (int)$_SESSION['user_id'];
}
function redirect_to($url, $message = '') {
    if ($message !== '') $_SESSION['notice'] = $message;
    header('Location: '.$url, true, 303); exit;
}
function cents($value) {
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string)$value)) throw new DomainException('商品价格无效');
    $parts = explode('.', (string)$value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}
function money($cents) { return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100); }
function quantity($value) {
    $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
    if ($n === false) throw new DomainException('购买数量必须为 1 至 999 的整数');
    return $n;
}
function status_name($status) {
    return ['pending'=>'待付款','paid'=>'待发货','shipped'=>'已发货','completed'=>'已完成','cancelled'=>'已取消'][$status] ?? $status;
}
function after_sale_status_name($status) {
    return ['pending'=>'待商家处理','approved'=>'商家已同意，请寄回商品','rejected'=>'商家已拒绝','received'=>'商家已收货，待支付渠道退款','refunded'=>'退货退款已完成'][$status] ?? $status;
}
function can_manage_order($order, $userId) {

    return ($_SESSION['role'] ?? '') === 'merchant' && !empty($order['shop_id']) &&
        one('SELECT id FROM shops WHERE id=? AND user_id=? AND status=1', [(int)$order['shop_id'], $userId]);
}
// 商品是否处于 SKU 模式：仅当存在 status=1 的规格记录时生效，否则沿用 products.price/stock 默认规格
function product_active_skus($productId, $lock = false) {
    return rows('SELECT * FROM product_skus WHERE product_id=? AND status=1 ORDER BY id'.($lock ? ' FOR UPDATE' : ''), [(int)$productId]);
}
function checkout_lines($userId, $productId = 0, $qty = 1, $lock = false, $skuId = 0) {
    $suffix = $lock ? ' FOR UPDATE' : '';
    $lines = [];
    if ($productId) {
        $product = one('SELECT p.* FROM products p WHERE p.id=?'.$suffix, [(int)$productId]);
        if (!$product) throw new DomainException('没有可结算的商品');
        $skus = product_active_skus($productId, $lock);
        if ($skus) {
            $found = null;
            foreach ($skus as $sku) if ((int)$sku['id'] === (int)$skuId) $found = $sku;
            if (!$found) throw new DomainException('请选择有效的商品规格');
            // SKU 行的价格与库存以 product_skus 为准（锁粒度为该 sku 行）
            $product['price'] = money((int)$found['price_cents']);
            $product['stock'] = (int)$found['stock'];
            $lines[] = array_merge($product, ['quantity' => $qty, 'sku_id' => (int)$found['id'], 'sku_name' => $found['sku_name']]);
        } else {
            if ($skuId) throw new DomainException('商品规格已失效，请重新选择');
            $lines[] = array_merge($product, ['quantity' => $qty, 'sku_id' => 0, 'sku_name' => null]);
        }
    } else {
        $cartRows = rows('SELECT product_id, sku_id, SUM(quantity) AS quantity FROM cart WHERE user_id=? GROUP BY product_id, sku_id ORDER BY product_id, sku_id', [$userId]);
        if (!$cartRows) throw new DomainException('没有可结算的商品');
        // Lock each product in stable order; the user row serializes cart changes.
        $products = [];
        foreach (array_unique(array_map('intval', array_column($cartRows, 'product_id'))) as $pid) {
            $product = one('SELECT p.* FROM products p WHERE p.id=?'.$suffix, [$pid]);
            if (!$product) throw new DomainException('购物车商品已删除，请移除后重新结算');
            $products[$pid] = $product;
        }
        foreach ($cartRows as $row) {
            $pid = (int)$row['product_id'];
            $line = array_merge($products[$pid], ['quantity' => $row['quantity'], 'sku_id' => 0, 'sku_name' => null]);
            $skus = product_active_skus($pid, $lock);
            if ($skus) {
                $found = null;
                foreach ($skus as $sku) if ((int)$sku['id'] === (int)$row['sku_id']) $found = $sku;
                if (!$found) throw new DomainException($line['title'].' 的规格已失效，请返回购物车处理');
                $line['price'] = money((int)$found['price_cents']);
                $line['stock'] = (int)$found['stock'];
                $line['sku_id'] = (int)$found['id'];
                $line['sku_name'] = $found['sku_name'];
            } elseif ((int)$row['sku_id'] !== 0) {
                throw new DomainException($line['title'].' 的规格已失效，请返回购物车处理');
            }
            $lines[] = $line;
        }
    }
    foreach ($lines as &$line) {
        $line['quantity'] = quantity($line['quantity']);
        if (empty($line['id']) || (int)$line['status'] !== 1 || $line['audit_status'] !== 'approved') throw new DomainException('商品已下架或未通过审核，请返回购物车处理');
        if ($line['shop_id'] && !one('SELECT id FROM shops WHERE id=? AND status=1', [(int)$line['shop_id']])) throw new DomainException('店铺暂不可购买');
        if ($line['quantity'] > (int)$line['stock']) throw new DomainException($line['title'].' 库存不足');
        $line['subtotal_cents'] = cents($line['price']) * $line['quantity'];
        if ($line['subtotal_cents'] <= 0 || $line['subtotal_cents'] > 9999999999) throw new DomainException('商品金额超出允许范围');
    }
    unset($line);
    return $lines;
}
function create_orders($userId, $productId, $qty, $addressId, $token, $userCouponId = 0, $skuId = 0) {
    global $conn;
    $conn->begin_transaction();
    try {
        one('SELECT id FROM users WHERE id=? FOR UPDATE', [$userId]);
        $prior = rows('SELECT id FROM orders WHERE user_id=? AND checkout_token=? ORDER BY id', [$userId, $token]);
        if ($prior) { $conn->commit(); return array_column($prior, 'id'); }
        $address = one('SELECT * FROM user_addresses WHERE id=? AND user_id=?', [$addressId, $userId]);
        if (!$address) throw new DomainException('请选择自己的有效收货地址');
        if (!$address['receiver_name'] || !$address['receiver_phone'] || !$address['receiver_address']) throw new DomainException('请先补全收货地址');
        $lines = checkout_lines($userId, $productId, $qty, true, $skuId);
        $groups = [];
        foreach ($lines as $line) $groups[(int)$line['shop_id']][] = $line;
        $ids = [];
        require_once __DIR__ . '/coupon.php';
        // 优惠券在写入订单前核销：校验最低消费并锁定持券记录
        $grandTotal = 0;
        $orderTotals = [];
        $shares = [];
        foreach ($groups as $items) $grandTotal += array_sum(array_column($items, 'subtotal_cents'));
        if ($userCouponId) {
            $discountTotal = coupon_consume_locked($userId, $userCouponId, $grandTotal);
            foreach ($groups as $shopId => $items) $orderTotals[$shopId] = array_sum(array_column($items, 'subtotal_cents'));
            $shares = coupon_split_discount($discountTotal, $orderTotals);
        }
        foreach ($groups as $shopId => $items) {
            $total = array_sum(array_column($items, 'subtotal_cents'));
            if ($total > 9999999999) throw new DomainException('订单金额超出允许范围');
            $number = 'HM'.date('YmdHis').bin2hex(random_bytes(6));
            query("INSERT INTO orders(order_no,user_id,total_amount,status,receiver_name,receiver_phone,receiver_address,shop_id,checkout_token,stock_reserved) VALUES(?,?,?,'pending',?,?,?,?,?,1)",
                [$number,$userId,money($total),$address['receiver_name'],$address['receiver_phone'],$address['receiver_address'],$shopId ?: null,$token]);
            $orderId = (int)$conn->insert_id;
            if (isset($shares[$shopId])) query('UPDATE orders SET discount_amount=?, coupon_user_id=? WHERE id=?', [money($shares[$shopId]), (int)$userCouponId, $orderId]);
            $ids[] = $orderId;
            foreach ($items as $item) {
                // SKU 模式扣减 product_skus 行（行锁），默认规格仍扣 products 行
                if ((int)$item['sku_id'] > 0) {
                    $stockUpdate = query('UPDATE product_skus SET stock=stock-? WHERE id=? AND stock>=?', [$item['quantity'],(int)$item['sku_id'],$item['quantity']]);
                } else {
                    $stockUpdate = query('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?', [$item['quantity'],(int)$item['id'],$item['quantity']]);
                }
                if ($stockUpdate->affected_rows !== 1) throw new DomainException('库存发生变化，请重新下单');
                query('INSERT INTO order_items(order_id,product_id,price,quantity,product_title,product_image,sku_id,sku_name) VALUES(?,?,?,?,?,?,?,?)',
                    [$orderId,(int)$item['id'],$item['price'],$item['quantity'],$item['title'],$item['image_url'] ?? '',(int)$item['sku_id'] ?: null,$item['sku_name']]);
                if (!$productId) query('DELETE FROM cart WHERE user_id=? AND product_id=? AND sku_id=?', [$userId,(int)$item['id'],(int)$item['sku_id']]);
            }
        }
        $conn->commit(); return $ids;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
function release_stock($order) {
    require_once __DIR__ . '/coupon.php';
    coupon_restore_for_order((int)$order['id']);
    if (!(int)$order['stock_reserved']) return;
    foreach (rows('SELECT product_id,sku_id,quantity FROM order_items WHERE order_id=? ORDER BY product_id', [(int)$order['id']]) as $item) {
        // 历史订单与默认规格回补 products 行，SKU 订单回补对应 sku 行
        if (!empty($item['sku_id'])) query('UPDATE product_skus SET stock=stock+? WHERE id=?', [(int)$item['quantity'],(int)$item['sku_id']]);
        elseif ($item['product_id']) query('UPDATE products SET stock=stock+? WHERE id=?', [(int)$item['quantity'],(int)$item['product_id']]);
    }
    query('UPDATE orders SET stock_reserved=0 WHERE id=?', [(int)$order['id']]);
}
function expire_orders($userId = null) {
    global $conn;
    $sql = "SELECT id FROM orders WHERE status='pending' AND created_at < NOW() - INTERVAL 3 HOUR";
    $params = [];
    if ($userId !== null) { $sql .= ' AND user_id=?'; $params[] = $userId; }
    foreach (rows($sql, $params) as $candidate) {
        $conn->begin_transaction();
        try {
            $order = one("SELECT * FROM orders WHERE id=? AND status='pending' AND created_at < NOW() - INTERVAL 3 HOUR FOR UPDATE", [(int)$candidate['id']]);
            if ($order) { release_stock($order); query("UPDATE orders SET status='cancelled' WHERE id=?", [(int)$order['id']]); }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}
function change_order($id, $actor, $action, $data = []) {
    global $conn;
    $conn->begin_transaction();
    try {
        $order = one('SELECT * FROM orders WHERE id=? FOR UPDATE', [$id]);
        if (!$order) throw new DomainException('订单不存在');
        $owner = (int)$order['user_id'] === $actor;
         $adminPermissions = ['ship'=>'order.ship','refund_approve'=>'order.refund','refund_reject'=>'order.refund','after_sale_approve'=>'order.refund','after_sale_reject'=>'order.refund','after_sale_receive'=>'order.refund'];
        $backend = $actor === 0 && defined('ADMIN_ORDER_CONTEXT') && ADMIN_ORDER_CONTEXT && function_exists('is_admin_logged_in') && is_admin_logged_in() && empty($_SESSION['admin']['must_change_password']) && isset($adminPermissions[$action]) && admin_has_permission($adminPermissions[$action]);
        $manager = $backend || can_manage_order($order, $actor);
        if ($action === 'cancel' && $owner && $order['status'] === 'pending') {
            release_stock($order);
            query("UPDATE orders SET status='cancelled' WHERE id=?", [$id]);
        } elseif ($action === 'pay' && $owner && $order['status'] === 'pending') {
            if (getenv('SHOP_DEMO_PAYMENT') !== '1') throw new DomainException('支付演示未开启，尚未接入真实支付');
            if (!(int)$order['stock_reserved']) throw new DomainException('旧订单未预留库存，请取消后重新下单');
            if (strtotime($order['created_at']) <= time()-10800) throw new DomainException('订单已超时，请返回订单中心');
            query("UPDATE orders SET status='paid',paid_at=NOW(),payment_method='demo' WHERE id=?", [$id]);
        } elseif ($action === 'ship' && $manager && $order['status'] === 'paid') {
            if (one("SELECT id FROM refund_requests WHERE order_id=? AND status='pending'", [$id])) throw new DomainException('此订单正在申请退款，请先处理');
            $company = trim($data['express_company'] ?? ''); $number = trim($data['express_no'] ?? '');
            if (!$company || mb_strlen($company)>50 || !preg_match('/^[A-Za-z0-9-]{5,100}$/D',$number)) throw new DomainException('请填写快递公司及有效运单号');
            query("UPDATE orders SET status='shipped',express_company=?,express_no=?,shipped_at=NOW() WHERE id=?", [$company,$number,$id]);
        } elseif ($action === 'receive' && $owner && $order['status'] === 'shipped') {
            query("UPDATE orders SET status='completed',completed_at=NOW() WHERE id=?", [$id]);
        } elseif ($action === 'refund' && $owner && $order['status'] === 'paid') {
            if (one('SELECT id FROM refund_requests WHERE order_id=?', [$id])) throw new DomainException('已提交退款申请');
            if ($order['payment_method'] !== 'demo') throw new DomainException('此订单需联系商家核实支付后办理退款');
            $reason = trim($data['reason'] ?? '');
            if (mb_strlen($reason)<2 || mb_strlen($reason)>500) throw new DomainException('退款原因需为 2 至 500 个字符');
            query("INSERT INTO refund_requests(order_id,user_id,reason,status) VALUES(?,?,?,'pending')",[$id,$actor,$reason]);
        } elseif (in_array($action,['refund_approve','refund_reject'],true) && $manager && $order['status']==='paid') {
            $request = one("SELECT * FROM refund_requests WHERE order_id=? AND status='pending' FOR UPDATE",[$id]);
            if (!$request) throw new DomainException('没有待处理的退款申请');
            if ($action==='refund_approve') {
                if ($order['payment_method'] !== 'demo') throw new DomainException('真实退款须通过支付渠道核实');
                release_stock($order);
                query("UPDATE orders SET status='cancelled' WHERE id=?",[$id]);
            }
            query('UPDATE refund_requests SET status=?,processed_at=NOW() WHERE id=?',[$action==='refund_approve'?'approved':'rejected',(int)$request['id']]);
        } elseif ($action === 'after_sale' && $owner && in_array($order['status'], ['shipped','completed'], true)) {
            if (one('SELECT id FROM after_sale_requests WHERE order_id=?', [$id])) throw new DomainException('已提交售后申请');
            $reason = trim($data['reason'] ?? '');
            if (mb_strlen($reason) < 2 || mb_strlen($reason) > 500) throw new DomainException('退货原因需为 2 至 500 个字符');
            query("INSERT INTO after_sale_requests(order_id,user_id,reason,status) VALUES(?,?,?,'pending')", [$id,$actor,$reason]);
        } elseif (in_array($action, ['after_sale_approve','after_sale_reject'], true) && $manager && in_array($order['status'], ['shipped','completed'], true)) {
            $request = one("SELECT * FROM after_sale_requests WHERE order_id=? AND status='pending' FOR UPDATE", [$id]);
            if (!$request) throw new DomainException('没有待处理的退货退款申请');
            query('UPDATE after_sale_requests SET status=?,processed_at=NOW() WHERE id=?', [$action === 'after_sale_approve' ? 'approved' : 'rejected', (int)$request['id']]);
        } elseif ($action === 'after_sale_receive' && $manager && in_array($order['status'], ['shipped','completed'], true)) {
            $request = one("SELECT * FROM after_sale_requests WHERE order_id=? AND status='approved' FOR UPDATE", [$id]);
            if (!$request) throw new DomainException('没有待确认收货的退货申请');
            if ($order['payment_method'] === 'demo') {
                release_stock($order);
                query("UPDATE after_sale_requests SET status='refunded',received_at=NOW() WHERE id=?", [(int)$request['id']]);
            } else {
                query("UPDATE after_sale_requests SET status='received',received_at=NOW() WHERE id=?", [(int)$request['id']]);
            }
        } else { throw new DomainException('无权操作或订单状态已改变，请刷新查看'); }
        if ($backend) audit_log('order.' . $action, 'order', $id, ['previous_status'=>$order['status']], 'success', true);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
// ===== 订单留言：复用 change_order 的 $owner / $manager 判定思路 =====
function order_message_role($order, $actor) {
    if ($actor > 0) {
        if ((int)$order['user_id'] === $actor) return 'buyer';
        if (can_manage_order($order, $actor)) return 'merchant';
        return null;
    }
    // 管理员仅限后台上下文且具备订单查看权限
    if (defined('ADMIN_ORDER_CONTEXT') && ADMIN_ORDER_CONTEXT && function_exists('is_admin_logged_in') && is_admin_logged_in() && empty($_SESSION['admin']['must_change_password']) && admin_has_permission('order.view')) return 'admin';
    return null;
}
function order_message_read_column($role) {
    $columns = ['buyer'=>'read_by_buyer','merchant'=>'read_by_merchant','admin'=>'read_by_admin'];
    return $columns[$role] ?? null;
}
function send_order_message($id, $actor, $content) {
    $order = one('SELECT * FROM orders WHERE id=?', [$id]);
    if (!$order) throw new DomainException('订单不存在');
    $role = order_message_role($order, $actor);
    if ($role === null) throw new DomainException('无权在该订单留言');
    $content = trim((string)$content);
    $length = mb_strlen($content);
    if ($length < 2 || $length > 500) throw new DomainException('留言内容需为 2 至 500 个字符');
    $senderId = $actor > 0 ? $actor : (int)($_SESSION['admin']['id'] ?? 0);
    query('INSERT INTO order_messages(order_id,sender_role,sender_id,content) VALUES(?,?,?,?)', [$id, $role, $senderId, $content]);
    return (int)$GLOBALS['conn']->insert_id;
}
function order_message_unread_counts(array $orderIds, $role) {
    if (!$orderIds) return [];
    $column = order_message_read_column($role);
    if ($column === null) return [];
    $ids = implode(',', array_map('intval', $orderIds));
    $counts = [];
    foreach (rows("SELECT order_id, COUNT(*) AS n FROM order_messages WHERE order_id IN ($ids) AND sender_role<>? AND $column=0 GROUP BY order_id", [$role]) as $row) {
        $counts[(int)$row['order_id']] = (int)$row['n'];
    }
    return $counts;
}
function order_message_mark_read($id, $role) {
    $column = order_message_read_column($role);
    if ($column === null) return;
    query("UPDATE order_messages SET $column=1 WHERE order_id=? AND sender_role<>? AND $column=0", [$id, $role]);
}
// ===== 首页推荐：热销榜 / 猜你喜欢（仅用 orders(user_id,status)、order_items(order_id) 现有索引，不新增索引） =====
// 热销榜：有效订单（paid/shipped/completed）的 order_items 按 (product_id, sku_id) 汇总销量，只保留当前在售且审核通过的商品
function best_sellers($limit = 10) {
    $limit = max(1, (int)$limit);
    return rows("SELECT p.id, p.title, p.image_url, p.price, SUM(s.qty) AS sold
        FROM (SELECT oi.product_id, oi.sku_id, SUM(oi.quantity) AS qty
              FROM order_items oi
              JOIN orders o ON o.id = oi.order_id
              WHERE o.status IN ('paid','shipped','completed')
              GROUP BY oi.product_id, oi.sku_id) s
        JOIN products p ON p.id = s.product_id
        WHERE p.status = 1 AND p.audit_status = 'approved'
        GROUP BY p.id, p.title, p.image_url, p.price
        ORDER BY sold DESC, p.id ASC
        LIMIT $limit");
}
// 猜你喜欢：已登录用户按收藏+历史购买涉及的类目推荐在售商品（排除已购买），不足时用热销榜补齐；未登录直接回退热销榜
function recommend_for_user($userId, $limit = 8) {
    $userId = (int)$userId;
    $limit = max(1, (int)$limit);
    if ($userId <= 0) return array_slice(best_sellers($limit), 0, $limit);
    $categoryIds = [];
    foreach (rows("SELECT DISTINCT p.category_id FROM products p
            JOIN favorites f ON f.product_id = p.id AND f.user_id = ?
            WHERE p.category_id > 0
            UNION
            SELECT DISTINCT p.category_id FROM products p
            JOIN order_items oi ON oi.product_id = p.id
            JOIN orders o ON o.id = oi.order_id
            WHERE o.user_id = ? AND o.status IN ('paid','shipped','completed') AND p.category_id > 0",
            [$userId, $userId]) as $row) {
        $categoryIds[] = (int)$row['category_id'];
    }
    if (!$categoryIds) return array_slice(best_sellers($limit), 0, $limit);
    $in = implode(',', array_fill(0, count($categoryIds), '?'));
    $items = rows("SELECT p.id, p.title, p.image_url, p.price, COALESCE(s.qty, 0) AS sold
        FROM products p
        LEFT JOIN (SELECT oi.product_id, SUM(oi.quantity) AS qty
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                   WHERE o.status IN ('paid','shipped','completed')
                   GROUP BY oi.product_id) s ON s.product_id = p.id
        WHERE p.status = 1 AND p.audit_status = 'approved'
          AND p.category_id IN ($in)
          AND NOT EXISTS (SELECT 1 FROM order_items mine
                          JOIN orders mo ON mo.id = mine.order_id
                          WHERE mine.product_id = p.id AND mo.user_id = ?
                            AND mo.status IN ('paid','shipped','completed'))
        ORDER BY sold DESC, p.id DESC
        LIMIT $limit", array_merge($categoryIds, [$userId]));
    if (count($items) < $limit) {
        $seen = [];
        foreach ($items as $row) $seen[(int)$row['id']] = true;
        foreach (best_sellers($limit) as $row) {
            if (count($items) >= $limit) break;
            if (isset($seen[(int)$row['id']])) continue;
            $seen[(int)$row['id']] = true;
            $items[] = $row;
        }
    }
    return $items;
}
function page_start($title) {
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).' · 惠蒙优选</title><link rel="stylesheet" href="css/shop.css"></head><body><header><a class="brand" href="index.php">惠蒙优选</a><nav><a href="index.php">选购商品</a><a href="cart.php">购物车</a><a href="favorites.php">收藏</a><a href="my_coupons.php">优惠券</a><a href="my_orders.php">我的订单</a><a href="addresses.php">收货地址</a><a href="user_center.php">个人中心</a>';
    if (in_array($_SESSION['role'] ?? '',['admin','merchant'],true)) echo '<a href="admin_orders.php">发货 / 售后</a>';
    echo '</nav></header><main><h1>'.h($title).'</h1>';
    if (isset($_SESSION['notice'])) { echo '<p class="notice" role="status">'.h($_SESSION['notice']).'</p>'; unset($_SESSION['notice']); }
}
function page_end() { echo '</main><footer>惠蒙优选 · 精选好物，安心选购</footer></body></html>'; }
function action_button($orderId, $action, $label) {
    return '<form class="inline" method="post" action="order_action.php">'.csrf_field().'<input type="hidden" name="order_id" value="'.(int)$orderId.'"><button name="action" value="'.h($action).'">'.h($label).'</button></form>';
}
