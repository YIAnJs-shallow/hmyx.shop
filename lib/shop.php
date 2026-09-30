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
    if (empty($_SESSION['user_id'])) { header('Location: user_login.php'); exit; }
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
    if (($_SESSION['role'] ?? '') === 'admin') return true;
    return ($_SESSION['role'] ?? '') === 'merchant' && !empty($order['shop_id']) &&
        one('SELECT id FROM shops WHERE id=? AND user_id=? AND status=1', [(int)$order['shop_id'], $userId]);
}
function checkout_lines($userId, $productId = 0, $qty = 1, $lock = false) {
    $suffix = $lock ? ' FOR UPDATE' : '';
    if ($productId) {
        $lines = rows('SELECT p.*, ? AS quantity FROM products p WHERE p.id=?'.$suffix, [quantity($qty), $productId]);
    } else {
        $lines = rows('SELECT p.*, c.quantity FROM (SELECT product_id,SUM(quantity) AS quantity FROM cart WHERE user_id=? GROUP BY product_id) c LEFT JOIN products p ON p.id=c.product_id ORDER BY c.product_id', [$userId]);
        // Lock each product in stable order; the user row serializes cart changes.
        if ($lock) foreach ($lines as &$line) {
            $product = one('SELECT * FROM products WHERE id=? FOR UPDATE', [(int)$line['id']]);
            if (!$product) throw new DomainException('购物车商品已删除，请移除后重新结算');
            $line = array_merge($product, ['quantity' => $line['quantity']]);
        }
        unset($line);
    }
    if (!$lines) throw new DomainException('没有可结算的商品');
    foreach ($lines as &$line) {
        $line['quantity'] = quantity($line['quantity']);
        if (empty($line['id']) || (int)$line['status'] !== 1 || $line['audit_status'] !== 'approved') throw new DomainException('商品已下架或未通过审核，请返回购物车处理');
        if ($line['shop_id'] && !one('SELECT id FROM shops WHERE id=? AND status=1', [(int)$line['shop_id']])) throw new DomainException('店铺暂不可购买');
        if ($line['quantity'] > (int)$line['stock']) throw new DomainException($line['title'].' 库存不足');
        $line['subtotal_cents'] = cents($line['price']) * $line['quantity'];
        if ($line['subtotal_cents'] <= 0 || $line['subtotal_cents'] > 9999999999) throw new DomainException('商品金额超出允许范围');
    }
    return $lines;
}
function create_orders($userId, $productId, $qty, $addressId, $token) {
    global $conn;
    $conn->begin_transaction();
    try {
        one('SELECT id FROM users WHERE id=? FOR UPDATE', [$userId]);
        $prior = rows('SELECT id FROM orders WHERE user_id=? AND checkout_token=? ORDER BY id', [$userId, $token]);
        if ($prior) { $conn->commit(); return array_column($prior, 'id'); }
        $address = one('SELECT * FROM user_addresses WHERE id=? AND user_id=?', [$addressId, $userId]);
        if (!$address) throw new DomainException('请选择自己的有效收货地址');
        if (!$address['receiver_name'] || !$address['receiver_phone'] || !$address['receiver_address']) throw new DomainException('请先补全收货地址');
        $lines = checkout_lines($userId, $productId, $qty, true);
        $groups = [];
        foreach ($lines as $line) $groups[(int)$line['shop_id']][] = $line;
        $ids = [];
        foreach ($groups as $shopId => $items) {
            $total = array_sum(array_column($items, 'subtotal_cents'));
            if ($total > 9999999999) throw new DomainException('订单金额超出允许范围');
            $number = 'HM'.date('YmdHis').bin2hex(random_bytes(6));
            query("INSERT INTO orders(order_no,user_id,total_amount,status,receiver_name,receiver_phone,receiver_address,shop_id,checkout_token,stock_reserved) VALUES(?,?,?,'pending',?,?,?,?,?,1)",
                [$number,$userId,money($total),$address['receiver_name'],$address['receiver_phone'],$address['receiver_address'],$shopId ?: null,$token]);
            $orderId = (int)$conn->insert_id;
            $ids[] = $orderId;
            foreach ($items as $item) {
                $stockUpdate = query('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?', [$item['quantity'],(int)$item['id'],$item['quantity']]);
                if ($stockUpdate->affected_rows !== 1) throw new DomainException('库存发生变化，请重新下单');
                query('INSERT INTO order_items(order_id,product_id,price,quantity,product_title,product_image) VALUES(?,?,?,?,?,?)',
                    [$orderId,(int)$item['id'],$item['price'],$item['quantity'],$item['title'],$item['image_url'] ?? '']);
                if (!$productId) query('DELETE FROM cart WHERE user_id=? AND product_id=?', [$userId,(int)$item['id']]);
            }
        }
        $conn->commit(); return $ids;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
function release_stock($order) {
    if (!(int)$order['stock_reserved']) return;
    foreach (rows('SELECT product_id,quantity FROM order_items WHERE order_id=? ORDER BY product_id', [(int)$order['id']]) as $item) {
        if ($item['product_id']) query('UPDATE products SET stock=stock+? WHERE id=?', [(int)$item['quantity'],(int)$item['product_id']]);
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
        $manager = can_manage_order($order, $actor);
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
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
function page_start($title) {
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).' · 惠蒙优选</title><link rel="stylesheet" href="css/shop.css"></head><body><header><a class="brand" href="index.php">惠蒙优选</a><nav><a href="index.php">选购商品</a><a href="cart.php">购物车</a><a href="favorites.php">收藏</a><a href="my_orders.php">我的订单</a><a href="addresses.php">收货地址</a><a href="user_center.php">个人中心</a>';
    if (in_array($_SESSION['role'] ?? '',['admin','merchant'],true)) echo '<a href="admin_orders.php">发货 / 售后</a>';
    echo '</nav></header><main><h1>'.h($title).'</h1>';
    if (isset($_SESSION['notice'])) { echo '<p class="notice" role="status">'.h($_SESSION['notice']).'</p>'; unset($_SESSION['notice']); }
}
function page_end() { echo '</main><footer>惠蒙优选 · 精选好物，安心选购</footer></body></html>'; }
function action_button($orderId, $action, $label) {
    return '<form class="inline" method="post" action="order_action.php">'.csrf_field().'<input type="hidden" name="order_id" value="'.(int)$orderId.'"><button name="action" value="'.h($action).'">'.h($label).'</button></form>';
}
