<?php
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/points.php';
require_once __DIR__.'/level.php';
require_once __DIR__.'/group_buy.php';

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
// ===== 商品浏览足迹：登录用户浏览详情页时记录（未登录 userId=0 不写入；失败仅记日志，不影响页面渲染） =====
function record_browsing($userId, $productId) {
    if ((int)$userId <= 0 || (int)$productId <= 0) return false;
    try {
        query('INSERT INTO user_browsing(user_id,product_id,viewed_at) VALUES(?,?,NOW())', [(int)$userId, (int)$productId]);
        return true;
    } catch (Throwable $e) { error_log('record_browsing failed: '.$e->getMessage()); return false; }
}
// 用户最近浏览足迹：同一商品只取最新一条，关联商品取名称/价格/图片（下架商品不展示）
function browsing_history($userId, $limit = 20) {
    return rows('SELECT b.product_id, MAX(b.viewed_at) AS last_viewed, p.title, p.price, p.image_url
                 FROM user_browsing b JOIN products p ON p.id = b.product_id
                 WHERE b.user_id = ? AND p.status = 1 AND p.audit_status = "approved"
                 GROUP BY b.product_id, p.title, p.price, p.image_url
                 ORDER BY last_viewed DESC LIMIT '.(int)$limit, [(int)$userId]);
}
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
// ===== 消息中心：站内通知写入（失败仅记日志，不影响主流程） =====
function notify($userId, $type, $title, $body, $link = '') {
    try {
        query('INSERT INTO user_notifications(user_id,type,title,body,link) VALUES(?,?,?,?,?)',
            [(int)$userId, $type, mb_substr($title, 0, 100), mb_substr($body, 0, 255), (string)$link]);
    } catch (Throwable $e) { error_log('notify failed: '.$e->getMessage()); }
}
// ===== 收藏商品降价提醒：改价后对收藏该商品的所有用户各发一条站内通知（须在改价事务内调用，一次降价一轮） =====
function notify_price_drop(array $product, $newPriceCents) {
    $oldCents = (int)round((float)$product['price'] * 100);
    $newCents = (int)$newPriceCents;
    if ($newCents <= 0 || $newCents >= $oldCents) return 0;
    $body = '你收藏的《'.$product['title'].'》降价了：原价 '.money($oldCents).' 元 → 现价 '.money($newCents).' 元';
    $link = 'detail.php?id='.(int)$product['id'];
    $count = 0;
    foreach (rows('SELECT user_id FROM favorites WHERE product_id=?', [(int)$product['id']]) as $f) {
        notify((int)$f['user_id'], 'price', '收藏商品降价', $body, $link);
        $count++;
    }
    return $count;
}
// ===== 限时特价：NOW() 落在 flash 区间且特价低于原价时生效（SKU 模式不参与） =====
function flash_price_cents(array $product) {
    $flash = (int)($product['flash_price_cents'] ?? 0);
    if ($flash <= 0 || empty($product['flash_start']) || empty($product['flash_end'])) return null;
    $now = date('Y-m-d H:i:s');
    if ($product['flash_start'] > $now || $product['flash_end'] < $now) return null;
    $original = (int)round(((float)$product['price']) * 100);
    if ($flash >= $original) return null;
    return $flash;
}
// 商户端特价/限购字段解析与校验：三者须同时填写，特价须低于原价，结束须晚于开始
function parse_flash_fields($originalPriceCents, array $post) {
    $raw = trim((string)($post['flash_price'] ?? ''));
    $startRaw = trim((string)($post['flash_start'] ?? ''));
    $endRaw = trim((string)($post['flash_end'] ?? ''));
    $limitRaw = trim((string)($post['limit_per_user'] ?? '0'));
    if ($limitRaw === '') $limitRaw = '0';
    if (!preg_match('/^\d+$/D', $limitRaw) || (int)$limitRaw > 99999) throw new DomainException('限购件数须为 0 至 99999 的整数（0 表示不限购）');
    $flash = null; $start = null; $end = null;
    if ($raw !== '' || $startRaw !== '' || $endRaw !== '') {
        if ($raw === '' || $startRaw === '' || $endRaw === '') throw new DomainException('特价、开始时间与结束时间须同时填写');
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $raw) || (float)$raw <= 0) throw new DomainException('特价须为大于零的金额（最多两位小数）');
        $parts = explode('.', $raw);
        $flash = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        foreach (['start' => $startRaw, 'end' => $endRaw] as $value) {
            $value = str_replace('T', ' ', $value);
            if (strlen($value) === 16) $value .= ':00';
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) || strtotime($value) === false) throw new DomainException('特价起止时间格式无效');
            if ($start === null) $start = $value; else $end = $value;
        }
        if ($flash >= (int)$originalPriceCents) throw new DomainException('特价必须低于商品原价');
        if ($end <= $start) throw new DomainException('特价结束时间必须晚于开始时间');
    }
    return [$flash, $start, $end, (int)$limitRaw];
}
// ===== 预售定金：字段解析（服务端权威校验：定金>0 且 < 售价；截止时间必填且晚于当前）与阶段应付金额 =====
// 未勾选预售时返回 [0, null, null]（保存即清空预售配置）
function parse_presale_fields($priceCents, array $post) {
    if (empty($post['presale'])) return [0, null, null];
    $raw = trim((string)($post['deposit_price'] ?? ''));
    $endRaw = trim((string)($post['presale_end_at'] ?? ''));
    if ($raw === '' || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $raw) || (float)$raw <= 0) throw new DomainException('预售定金须为大于零的金额（最多两位小数）');
    $parts = explode('.', $raw);
    $depositCents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    if ($depositCents >= (int)$priceCents) throw new DomainException('预售定金必须低于商品售价');
    $end = str_replace('T', ' ', $endRaw);
    if (strlen($end) === 16) $end .= ':00';
    if ($end === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $end) || strtotime($end) === false) throw new DomainException('预售须填写定金支付截止时间，格式为 Y-m-d H:i:s');
    if (strtotime($end) <= time()) throw new DomainException('预售截止时间必须晚于当前时间');
    return [1, $depositCents, $end];
}
// 订单当前阶段应付金额（分）：普通单沿用 小计−券−积分−会员折扣 口径；预售定金阶段取商品定金×数量，尾款阶段取 total−已付
function order_payable_cents(array $order) {
    $total = (int)cents((string)$order['total_amount']);
    $paid = (int)round(((float)($order['paid_cents'] ?? 0)) * 100);
    if (($order['pay_stage'] ?? 'full') === 'deposit') {
        if ($paid > 0) return max(0, $total - $paid);
        // 定金阶段：金额取商品定金 × 数量（订单不留存定金快照，以商品列当前值为准）
        $row = one('SELECT p.deposit_cents, i.quantity FROM order_items i JOIN products p ON p.id=i.product_id WHERE i.order_id=? LIMIT 1', [(int)$order['id']]);
        return $row ? (int)$row['deposit_cents'] * (int)$row['quantity'] : 0;
    }
    return max(0, $total - (int)cents((string)$order['discount_amount']) - (int)$order['points_discount_cents'] - (int)$order['member_discount_cents']);
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
            $lines[] = array_merge($product, ['quantity' => $qty, 'sku_id' => (int)$found['id'], 'sku_name' => $found['sku_name'], 'sku_image' => $found['image_url'] ?? null]);
        } else {
            if ($skuId) throw new DomainException('商品规格已失效，请重新选择');
            $flash = flash_price_cents($product);
            if ($flash !== null) $product['price'] = money($flash);
            $lines[] = array_merge($product, ['quantity' => $qty, 'sku_id' => 0, 'sku_name' => null, 'sku_image' => null]);
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
            $line = array_merge($products[$pid], ['quantity' => $row['quantity'], 'sku_id' => 0, 'sku_name' => null, 'sku_image' => null]);
            $skus = product_active_skus($pid, $lock);
            if ($skus) {
                $found = null;
                foreach ($skus as $sku) if ((int)$sku['id'] === (int)$row['sku_id']) $found = $sku;
                if (!$found) throw new DomainException($line['title'].' 的规格已失效，请返回购物车处理');
                $line['price'] = money((int)$found['price_cents']);
                $line['stock'] = (int)$found['stock'];
                $line['sku_id'] = (int)$found['id'];
                $line['sku_name'] = $found['sku_name'];
                $line['sku_image'] = $found['image_url'] ?? null;
            } elseif ((int)$row['sku_id'] !== 0) {
                throw new DomainException($line['title'].' 的规格已失效，请返回购物车处理');
            }
            if (!$skus) {
                $flash = flash_price_cents($line);
                if ($flash !== null) $line['price'] = money($flash);
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
function create_orders($userId, $productId, $qty, $addressId, $token, $userCouponId = 0, $skuId = 0, $usePoints = 0, $groupBuyId = 0, $presale = 0) {
    global $conn;
    $conn->begin_transaction();
    try {
        // 预售定金下单：仅详情页立即购买（productId>0），禁券/积分/拼团/购物车路径，服务端强制校验
        if ($presale) {
            if ($userCouponId || $usePoints) throw new DomainException('预售订单不支持优惠券与积分抵扣');
            if ($groupBuyId) throw new DomainException('预售商品不支持拼团');
            if (!$productId) throw new DomainException('预售商品仅支持详情页立即购买');
        }
        // 拼团下单：先锁活动行（与支付判团同一把锁，串行化匹配）；拼团价覆盖一切展示价，单次限购 1 件
        $groupBuy = null; $groupTargetId = 0;
        if ($groupBuyId) {
            if ($userCouponId || $usePoints) throw new DomainException('拼团订单暂不支持优惠券与积分抵扣');
            if ((int)$qty !== 1) throw new DomainException('拼团单次限购 1 件');
            if ($skuId || product_active_skus($productId)) throw new DomainException('多规格商品暂不支持拼团');
            $groupBuy = one("SELECT * FROM group_buys WHERE id=? AND status='active' FOR UPDATE", [(int)$groupBuyId]);
            if (!$groupBuy || (int)$groupBuy['product_id'] !== (int)$productId) throw new DomainException('拼团活动已结束或商品不符');
            if (group_buy_user_slot_exists($userId, (int)$groupBuy['id'], (int)$groupBuy['duration_hours'])) throw new DomainException('您已有进行中的拼团，请等待其成团或结束后再参团');
            $groupTargetId = group_buy_pick_group($groupBuy);
            if (!$groupTargetId && (int)$groupBuy['max_groups'] > 0 && group_buy_opened_groups((int)$groupBuy['id']) >= (int)$groupBuy['max_groups']) throw new DomainException('该拼团活动已达开团上限');
        }
        $buyer = one('SELECT id, points FROM users WHERE id=? FOR UPDATE', [$userId]);
        $prior = rows('SELECT id FROM orders WHERE user_id=? AND checkout_token=? ORDER BY id', [$userId, $token]);
        if ($prior) { $conn->commit(); return array_column($prior, 'id'); }
        $address = one('SELECT * FROM user_addresses WHERE id=? AND user_id=?', [$addressId, $userId]);
        if (!$address) throw new DomainException('请选择自己的有效收货地址');
        if (!$address['receiver_name'] || !$address['receiver_phone'] || !$address['receiver_address']) throw new DomainException('请先补全收货地址');
        $lines = checkout_lines($userId, $productId, $qty, true, $skuId);
        // 预售权威校验（锁内）：必须是预售商品且走预售通道；非预售通道遇到预售商品一律拒绝（购物车结算兜底）
        if ($presale) {
            $line0 = $lines[0];
            if ((int)($line0['presale'] ?? 0) !== 1) throw new DomainException('该商品不是预售商品，请按普通流程下单');
            if (product_active_skus((int)$line0['id'])) throw new DomainException('多规格商品暂不支持预售');
            if (empty($line0['presale_end_at']) || strtotime($line0['presale_end_at']) <= time()) throw new DomainException('预售已截止，无法支付定金下单');
            $depCents = (int)($line0['deposit_cents'] ?? 0);
            if ($depCents <= 0 || $depCents >= cents($line0['price'])) throw new DomainException('预售定金配置无效');
        } elseif (!$productId) {
            foreach ($lines as $line) {
                if ((int)($line['presale'] ?? 0) === 1) throw new DomainException('预售商品请从详情页立即购买支付定金');
            }
        } elseif ((int)($lines[0]['presale'] ?? 0) === 1) {
            throw new DomainException('预售商品请从详情页立即购买支付定金');
        }
        if ($groupBuy) {
            // 团购价覆盖：单商品单件，subtotal 直接取团购价（整数分）
            $lines[0]['price'] = money((int)$groupBuy['price_cents']);
            $lines[0]['subtotal_cents'] = (int)$groupBuy['price_cents'];
        }
        // 限购校验：按 product_id 汇总本次数量与历史有效订单（不含已取消）数量
        $limitTotals = [];
        foreach ($lines as $line) {
            $pid = (int)$line['id'];
            if (!isset($limitTotals[$pid])) $limitTotals[$pid] = ['limit' => (int)($line['limit_per_user'] ?? 0), 'qty' => 0];
            $limitTotals[$pid]['qty'] += (int)$line['quantity'];
        }
        foreach ($limitTotals as $pid => $info) {
            if ($info['limit'] <= 0) continue;
            $bought = (int)one("SELECT COALESCE(SUM(i.quantity),0) AS n FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.user_id=? AND i.product_id=? AND o.status<>'cancelled'", [$userId, $pid])['n'];
            if ($bought + $info['qty'] > $info['limit']) throw new DomainException('该商品限购 '.$info['limit'].' 件');
        }
        $groups = [];
        foreach ($lines as $line) $groups[(int)$line['shop_id']][] = $line;
        $ids = [];
        require_once __DIR__ . '/coupon.php';
        // 优惠券在写入订单前核销：校验最低消费并锁定持券记录
        $grandTotal = 0;
        $orderTotals = [];
        $shares = [];
        foreach ($groups as $items) $grandTotal += array_sum(array_column($items, 'subtotal_cents'));
        // 会员等级折扣：叠加顺序写死为 会员折扣 → 优惠券 → 积分抵现（折扣基数逐级缩小，不改行单价）；
        // 券门槛与积分上限均以折后金额为基数；100%（普通会员）时金额与既有行为完全一致
        $discountPercent = level_discount_percent($userId);
        $memberShares = [];
        if ($discountPercent < 100 && !$presale) {
            foreach ($groups as $shopId => $items) {
                $memberShares[$shopId] = (int)round(array_sum(array_column($items, 'subtotal_cents')) * (100 - $discountPercent) / 100);
            }
        }
        $grandMemberDiscount = array_sum($memberShares);
        if ($userCouponId) {
            $discountTotal = coupon_consume_locked($userId, $userCouponId, $grandTotal - $grandMemberDiscount, array_map(function ($l) { return (int)$l['id']; }, $lines));
            foreach ($groups as $shopId => $items) $orderTotals[$shopId] = array_sum(array_column($items, 'subtotal_cents'));
            $shares = coupon_split_discount($discountTotal, $orderTotals);
        }
        // 积分抵现：1 积分 = 1 分，单笔订单最多抵应付金额的 10%（向下取整到分）；可用积分按下单顺序逐单分配
        $availablePoints = $usePoints ? max(0, (int)($buyer['points'] ?? 0)) : 0;
        foreach ($groups as $shopId => $items) {
            $total = array_sum(array_column($items, 'subtotal_cents'));
            if ($total > 9999999999) throw new DomainException('订单金额超出允许范围');
            $pointsPaid = 0; $pointsDiscount = 0;
            $memberDiscount = (int)($memberShares[$shopId] ?? 0);
            // 实付 = 小计 − 会员折扣 − 券分摊 − 积分抵现，负数截断为 0
            $payableCents = max(0, $total - $memberDiscount - (int)($shares[$shopId] ?? 0));
            if ($availablePoints > 0 && $payableCents > 0) {
                $pointsDiscount = min(intdiv($payableCents, 10), $availablePoints);
                if ($pointsDiscount > 0) { $pointsPaid = $pointsDiscount; $availablePoints -= $pointsDiscount; }
            }
            $number = 'HM'.date('YmdHis').bin2hex(random_bytes(6));
            query("INSERT INTO orders(order_no,user_id,total_amount,status,pay_stage,receiver_name,receiver_phone,receiver_address,shop_id,checkout_token,stock_reserved,points_paid,points_discount_cents,member_discount_cents) VALUES(?,?,?,'pending',?,?,?,?,?,?,1,?,?,?)",
                [$number,$userId,money($total),$presale ? 'deposit' : 'full',$address['receiver_name'],$address['receiver_phone'],$address['receiver_address'],$shopId ?: null,$token,$pointsPaid,$pointsDiscount,$memberDiscount]);
            $orderId = (int)$conn->insert_id;
            if (isset($shares[$shopId])) query('UPDATE orders SET discount_amount=?, coupon_user_id=? WHERE id=?', [money($shares[$shopId]), (int)$userCouponId, $orderId]);
            $ids[] = $orderId;
            if ($groupBuy) {
                // 虚拟总团池落位：加入既有团（group_id=目标团）或开新团（团长行 group_id 回填为自身 id）
                query('INSERT INTO group_members(group_id,user_id,order_id,role) VALUES(?,?,?,?)',
                    [$groupTargetId, $userId, $orderId, $groupTargetId ? 'member' : 'leader']);
                $memberId = (int)$conn->insert_id;
                if (!$groupTargetId) { $groupTargetId = $memberId; query('UPDATE group_members SET group_id=? WHERE id=?', [$memberId, $memberId]); }
                query('UPDATE orders SET group_buy_id=?, group_member_id=? WHERE id=?', [(int)$groupBuy['id'], $memberId, $orderId]);
            }
            foreach ($items as $item) {
                // SKU 模式扣减 product_skus 行（行锁），默认规格仍扣 products 行
                if ((int)$item['sku_id'] > 0) {
                    $stockUpdate = query('UPDATE product_skus SET stock=stock-? WHERE id=? AND stock>=?', [$item['quantity'],(int)$item['sku_id'],$item['quantity']]);
                } else {
                    $stockUpdate = query('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?', [$item['quantity'],(int)$item['id'],$item['quantity']]);
                }
                if ($stockUpdate->affected_rows !== 1) throw new DomainException('库存发生变化，请重新下单');
                query('INSERT INTO order_items(order_id,product_id,price,quantity,product_title,product_image,sku_id,sku_name,sku_image) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$orderId,(int)$item['id'],$item['price'],$item['quantity'],$item['title'],$item['image_url'] ?? '',(int)$item['sku_id'] ?: null,$item['sku_name'],$item['sku_image'] ?: null]);
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
function expire_orders($uid = null) {
    global $conn;
    // 已付定金待付尾款的预售订单（pay_stage=deposit 且 paid_cents>0）不受 3 小时窗口约束，由预售尾款超时任务单独处理
    $sql = "SELECT id FROM orders WHERE status='pending' AND created_at < NOW() - INTERVAL 3 HOUR AND NOT (pay_stage='deposit' AND paid_cents > 0)";
    $params = [];
    if ($uid !== null) { $sql .= ' AND user_id=?'; $params[] = $uid; }
    foreach (rows($sql, $params) as $candidate) {
        $conn->begin_transaction();
        try {
            $order = one("SELECT * FROM orders WHERE id=? AND status='pending' AND created_at < NOW() - INTERVAL 3 HOUR AND NOT (pay_stage='deposit' AND paid_cents > 0) FOR UPDATE", [(int)$candidate['id']]);
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
            // 已付定金的预售订单不可自行取消（涉及定金退款），超时未付尾款由定时任务关单退定金
            if (($order['pay_stage'] ?? 'full') === 'deposit' && (float)$order['paid_cents'] > 0) {
                throw new DomainException('已支付定金的预售订单不可自行取消，超时未付尾款将自动关单退定金');
            }
            release_stock($order);
            query("UPDATE orders SET status='cancelled' WHERE id=?", [$id]);
        } elseif ($action === 'pay' && $owner && $order['status'] === 'pending') {
            // 支付渠道：默认演示支付；支付宝沙箱经 return/notify 验签+主动查单确认后传入 alipay_sandbox
            $paymentMethod = $data['payment_method'] ?? 'demo';
            if (!in_array($paymentMethod, ['demo', 'alipay_sandbox'], true)) throw new DomainException('未知支付渠道');
            if ($paymentMethod === 'demo' && getenv('SHOP_DEMO_PAYMENT') !== '1') throw new DomainException('支付演示未开启，尚未接入真实支付');
            if (!(int)$order['stock_reserved']) throw new DomainException('旧订单未预留库存，请取消后重新下单');
            $presaleStage = ($order['pay_stage'] ?? 'full') === 'deposit';
            // 未付定金的单复用现有 3 小时关单窗口；预售已付定金（待付尾款）不受 3 小时窗口限制
            if (!$presaleStage && strtotime($order['created_at']) <= time()-10800) throw new DomainException('订单已超时，请返回订单中心');
            if ($presaleStage) {
                $paidCents = (int)round(((float)$order['paid_cents']) * 100);
                $payableCents = order_payable_cents($order);
                if ($payableCents <= 0) throw new DomainException('预售应付金额无效');
                if ($paidCents === 0) {
                    // 定金支付：须在 presale_end_at 截止前；成功后订单保持 pending，展示"待付尾款"
                    $pend = one('SELECT presale_end_at FROM products p JOIN order_items i ON i.product_id=p.id WHERE i.order_id=?', [(int)$id]);
                    if (!$pend || empty($pend['presale_end_at']) || strtotime($pend['presale_end_at']) <= time()) throw new DomainException('预售已截止，无法支付定金');
                    query('UPDATE orders SET paid_cents=?,paid_at=NOW(),payment_method=? WHERE id=?', [money($payableCents), $paymentMethod, (int)$id]);
                } else {
                    // 尾款支付：付 total_amount − paid_cents，成功后 pay_stage='balance'，走正常发货/收货流程
                    query("UPDATE orders SET status='paid',paid_at=NOW(),payment_method=?,paid_cents=?,pay_stage='balance' WHERE id=?", [$paymentMethod, money($paidCents + $payableCents), (int)$id]);
                }
            } else {
                // 积分抵现原子扣减：余额充足则按下单记录扣（grant_points 幂等 + 余额校验），不足则放弃抵扣按原价支付（取消/超时关单不涉及积分，不退）
                if ((int)$order['points_paid'] > 0) {
                    $payer = one('SELECT points FROM users WHERE id=? FOR UPDATE', [(int)$order['user_id']]);
                    if ($payer && (int)$payer['points'] >= (int)$order['points_paid']) {
                        grant_points((int)$order['user_id'], 'pay', -(int)$order['points_paid'], (int)$id, '订单支付积分抵扣');
                    } else {
                        query('UPDATE orders SET points_paid=0, points_discount_cents=0 WHERE id=?', [$id]);
                    }
                }
                query("UPDATE orders SET status='paid',paid_at=NOW(),payment_method=? WHERE id=?", [$paymentMethod, $id]);
                // 拼团订单：支付成功事务内成团判定（group_buy_settle 内部锁 group_buys 行防并发判团竞态）
                if (!empty($order['group_member_id'])) group_buy_settle((int)$id);
            }
        } elseif ($action === 'ship' && $manager && $order['status'] === 'paid') {
            if (one("SELECT id FROM refund_requests WHERE order_id=? AND status='pending'", [$id])) throw new DomainException('此订单正在申请退款，请先处理');
            // 拼团订单未成团前禁止发货（成团结果以 orders.group_success 为准）
            if (!empty($order['group_member_id']) && !(int)$order['group_success']) throw new DomainException('拼团尚未成功，暂不能发货');
            $company = trim($data['express_company'] ?? ''); $number = trim($data['express_no'] ?? '');
            if (!$company || mb_strlen($company)>50 || !preg_match('/^[A-Za-z0-9-]{5,100}$/D',$number)) throw new DomainException('请填写快递公司及有效运单号');
            query("UPDATE orders SET status='shipped',express_company=?,express_no=?,shipped_at=NOW() WHERE id=?", [$company,$number,$id]);
            notify((int)$order['user_id'], 'order', '您的订单已发货（运单号 '.$number.'）', '订单 '.$order['order_no'].' 已由'.$company.'发出，运单号 '.$number.'，请注意查收。', 'my_orders.php');
        } elseif ($action === 'receive' && $owner && $order['status'] === 'shipped') {
            query("UPDATE orders SET status='completed',completed_at=NOW() WHERE id=?", [$id]);
            // 确认收货发分：实付 = 商品合计 - 优惠券优惠 - 积分抵扣 - 会员折扣，每 1 元 = 1 分（向下取整）；
            // 2026-10-19 六档升级：白钻及以上按等级积分倍率加成（倍率取发分时点等级，×10 整数口径）
            $paidCents = (int)round(((float)$order['total_amount']) * 100) - (int)round(((float)$order['discount_amount']) * 100) - (int)$order['points_discount_cents'] - (int)$order['member_discount_cents'];
            $rateRow = one('SELECT l.points_rate_x10 FROM users u JOIN user_levels l ON l.id=u.level_id WHERE u.id=?', [(int)$order['user_id']]);
            $pointsRate = $rateRow ? max(10, (int)$rateRow['points_rate_x10']) : 10;
            $earned = max(0, (int)floor($paidCents * $pointsRate / 1000));
            if ($earned > 0) grant_points((int)$order['user_id'], 'order', $earned, (int)$id, '确认收货赠送积分' . ($pointsRate > 10 ? '（含会员积分倍率加成）' : ''));
            // 会员等级积累：与发分同一事务同一实付口径（completed 触发，退款不扣减）
            level_accrue((int)$order['user_id'], (int)$id, max(0, $paidCents));
            // 会员等级积累：与发分同一事务同一实付口径（completed 触发，退款不扣减）
            level_accrue((int)$order['user_id'], (int)$id, max(0, $paidCents));
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
                // 商家同意退款：返还支付时扣减的积分抵扣（与优惠券恢复对称；grant_points 同向幂等防重）
                if ((int)$order['points_paid'] > 0) grant_points((int)$order['user_id'], 'pay', (int)$order['points_paid'], (int)$id, '退款返还积分抵扣');
                query("UPDATE orders SET status='cancelled' WHERE id=?",[$id]);
            }
            query('UPDATE refund_requests SET status=?,processed_at=NOW() WHERE id=?',[$action==='refund_approve'?'approved':'rejected',(int)$request['id']]);
            notify((int)$order['user_id'], 'refund', $action==='refund_approve' ? '退款申请已同意' : '退款申请未被通过',
                '订单 '.$order['order_no'].' 的退款申请'.($action==='refund_approve' ? '已由商家同意' : '被商家拒绝').($action==='refund_approve' ? '，退款将按原支付方式退回。' : '，如有疑问请与商家留言沟通。'), 'my_orders.php');
        } elseif ($action === 'after_sale' && $owner && in_array($order['status'], ['shipped','completed'], true)) {
            if (one('SELECT id FROM after_sale_requests WHERE order_id=?', [$id])) throw new DomainException('已提交售后申请');
            $reason = trim($data['reason'] ?? '');
            if (mb_strlen($reason) < 2 || mb_strlen($reason) > 500) throw new DomainException('退货原因需为 2 至 500 个字符');
            query("INSERT INTO after_sale_requests(order_id,user_id,reason,status) VALUES(?,?,?,'pending')", [$id,$actor,$reason]);
        } elseif ($action === 'after_sale_ship' && $owner && in_array($order['status'], ['shipped','completed'], true)) {
            // 买家在商家同意退货后填写退货物流（校验规则与发货分支一致）
            $request = one("SELECT * FROM after_sale_requests WHERE order_id=? AND status='approved' FOR UPDATE", [$id]);
            if (!$request) throw new DomainException('该售后单状态已变化');
            $company = trim($data['return_express_company'] ?? ''); $number = trim($data['return_express_no'] ?? '');
            if (!$company || mb_strlen($company) > 50 || !preg_match('/^[A-Za-z0-9-]{5,100}$/D', $number)) throw new DomainException('请填写快递公司及有效运单号');
            query('UPDATE after_sale_requests SET return_express_company=?, return_express_no=? WHERE id=?', [$company, $number, (int)$request['id']]);
        } elseif (in_array($action, ['after_sale_approve','after_sale_reject'], true) && $manager && in_array($order['status'], ['shipped','completed'], true)) {
            $request = one("SELECT * FROM after_sale_requests WHERE order_id=? AND status='pending' FOR UPDATE", [$id]);
            if (!$request) throw new DomainException('没有待处理的退货退款申请');
            query('UPDATE after_sale_requests SET status=?,processed_at=NOW() WHERE id=?', [$action === 'after_sale_approve' ? 'approved' : 'rejected', (int)$request['id']]);
        } elseif ($action === 'after_sale_receive' && $manager && in_array($order['status'], ['shipped','completed'], true)) {
            // 事务内 FOR UPDATE 锁定 approved 状态的售后单：状态一旦流转（received/refunded）即拒绝重复回补
            $request = one("SELECT * FROM after_sale_requests WHERE order_id=? AND status='approved' FOR UPDATE", [$id]);
            if (!$request) throw new DomainException('该售后单状态已变化');
            if ($order['payment_method'] === 'demo') {
                // release_stock 依 stock_reserved 标志位整单只回补一次：sku_id>0 回补 product_skus，默认规格回补 products
                release_stock($order);
                // 已发货订单售后完成：买家已寄回商品、库存已回补，订单闭环置 cancelled（与仅退款/拼团超时退款口径一致，退款不积累等级消费）
                // 已是 completed 的订单保持完成态：等级与积分按"退款终身制不扣减"保留
                if ($order['status'] === 'shipped') {
                    query("UPDATE orders SET status='cancelled' WHERE id=?", [$id]);
                }
                // 退货退款同样返还支付时扣减的积分抵扣（grant_points 同向幂等防重）
                if ((int)$order['points_paid'] > 0) grant_points((int)$order['user_id'], 'pay', (int)$order['points_paid'], (int)$id, '退货退款返还积分抵扣');
                query("UPDATE after_sale_requests SET status='refunded', received_at=NOW() WHERE id=?", [(int)$request['id']]);
            } else {
                query("UPDATE after_sale_requests SET status='received', received_at=NOW() WHERE id=?", [(int)$request['id']]);
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
    // 消息中心联动：买家留言通知商户，商户/管理员留言通知买家
    if ($role === 'buyer' && !empty($order['shop_id'])) {
        $shopOwner = one('SELECT user_id FROM shops WHERE id=?', [(int)$order['shop_id']]);
        if ($shopOwner) notify((int)$shopOwner['user_id'], 'message', '您收到一条新的订单留言', '订单 '.$order['order_no'].' 买家留言：'.$content, 'order_messages.php?order_id='.(int)$id);
    } elseif ($role === 'merchant' || $role === 'admin') {
        notify((int)$order['user_id'], 'message', $role === 'merchant' ? '商家回复了您的订单留言' : '平台回复了您的订单留言', '订单 '.$order['order_no'].' 回复：'.$content, 'order_messages.php?order_id='.(int)$id);
    }
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
// ===== 首页推荐位：楼位白名单固定三席，后台配置、前台按 sort 升序渲染 =====
function home_recommend_slots() {
    return ['today' => '今日推荐', 'new' => '新品速递', 'clearance' => '清仓特卖'];
}
function home_slot_products($slot, $limit = 8) {
    if (!isset(home_recommend_slots()[$slot])) return [];
    return rows("SELECT p.id, p.title, p.image_url, p.price
        FROM home_recommendations hr
        JOIN products p ON p.id = hr.product_id
        WHERE hr.slot = ? AND p.status = 1 AND p.audit_status = 'approved'
          AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id = p.shop_id AND s.status = 1))
        ORDER BY hr.sort ASC, hr.id ASC
        LIMIT " . (int)$limit, [$slot]);
}
// ===== 首页改版：秒杀楼层（2026-10-21 A+B 专项）=====
// 进行中的秒杀商品（status=1 且当前处于场次窗口内），可见口径与 home_slot_products 完全一致，sort 升序
// 防御：楼层为展示层功能，正式库表未建等异常时返回空数组隐藏楼层，不允许炸掉整个首页
function seckill_active_items($limit = 8) {
    try {
        return seckill_active_items_unsafe($limit);
    } catch (Throwable $e) {
        error_log('seckill floor degraded: ' . $e->getMessage());
        return [];
    }
}
function seckill_active_items_unsafe($limit = 8) {
    return rows("SELECT si.id, si.seckill_price_cents, si.total_stock, si.sold, si.end_at, p.id AS pid, p.title, p.image_url, p.price
        FROM seckill_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.status = 1 AND p.status = 1 AND p.audit_status = 'approved'
          AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id = p.shop_id AND s.status = 1))
          AND si.start_at <= NOW() AND si.end_at > NOW()
        ORDER BY si.sort ASC, si.id ASC
        LIMIT " . (int)$limit, []);
}
// 后台保存秒杀配置（校验下沉，分口径入参；仿 admin_create_coupon 模式，审计由页面记录）
function admin_save_seckill(int $itemId, int $productId, int $priceCents, int $totalStock, int $sold, string $startAt, string $endAt, int $sort): int {
    if ($priceCents <= 0) throw new DomainException('秒杀价必须大于 0');
    if ($totalStock <= 0 || $totalStock > 1000000) throw new DomainException('活动限量须在 1 至 1000000 之间');
    if ($sold < 0 || $sold > $totalStock) throw new DomainException('已抢件数须在 0 与限量之间');
    if ($productId <= 0 || !one('SELECT id FROM products WHERE id=?', [$productId])) throw new DomainException('商品不存在');
    if (strtotime($startAt) === false || strtotime($endAt) === false) throw new DomainException('场次时间格式无效');
    if (strtotime($endAt) <= strtotime($startAt)) throw new DomainException('结束时间必须晚于开始时间');
    if (strtotime($endAt) - strtotime($startAt) > 86400 * 7) throw new DomainException('单场时长不可超过 7 天');
    if ($itemId > 0) {
        if (!one('SELECT id FROM seckill_items WHERE id=?', [$itemId])) throw new DomainException('秒杀配置不存在');
        query('UPDATE seckill_items SET product_id=?,seckill_price_cents=?,total_stock=?,sold=?,start_at=?,end_at=?,sort=? WHERE id=?', [$productId, $priceCents, $totalStock, $sold, $startAt, $endAt, $sort, $itemId]);
        return $itemId;
    }
    query('INSERT INTO seckill_items(product_id,seckill_price_cents,total_stock,sold,start_at,end_at,sort) VALUES(?,?,?,?,?,?,?)', [$productId, $priceCents, $totalStock, $sold, $startAt, $endAt, $sort]);
    return (int)one('SELECT id FROM seckill_items ORDER BY id DESC LIMIT 1', [])['id'];
}
// 后台上下架切换
function admin_toggle_seckill(int $itemId): void {
    query('UPDATE seckill_items SET status = 1 - status WHERE id=?', [$itemId]);
}
function admin_set_recommendation($slot, $productId) {
    if (!isset(home_recommend_slots()[$slot])) throw new DomainException('无效的推荐楼位');
    $product = one("SELECT id FROM products p WHERE p.id=? AND p.status=1 AND p.audit_status='approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id=p.shop_id AND s.status=1))", [(int)$productId]);
    if (!$product) throw new DomainException('仅可推荐在售且审核通过的商品');
    $exists = one('SELECT id FROM home_recommendations WHERE slot=? AND product_id=?', [$slot, (int)$productId]);
    if ($exists) throw new DomainException('该商品已在此楼位');
    $maxSort = one('SELECT COALESCE(MAX(sort), 0) AS m FROM home_recommendations WHERE slot=?', [$slot]);
    query('INSERT INTO home_recommendations(slot,product_id,sort) VALUES(?,?,?)', [$slot, (int)$productId, (int)$maxSort['m'] + 1]);
    return (int)$GLOBALS['conn']->insert_id;
}
function admin_remove_recommendation($id) {
    $stmt = query('DELETE FROM home_recommendations WHERE id=?', [(int)$id]);
    if ($stmt->affected_rows !== 1) throw new DomainException('该推荐不存在或已移除');
    return true;
}
// 上移/下移：与相邻行交换 sort（同楼位内），已是边界时抛出提示
function admin_move_recommendation($id, $dir) {
    if (!in_array($dir, ['up', 'down'], true)) throw new DomainException('无效的移动方向');
    $row = one('SELECT * FROM home_recommendations WHERE id=? FOR UPDATE', [(int)$id]);
    if (!$row) throw new DomainException('该推荐不存在');
    $cmp = $dir === 'up' ? '<' : '>';
    $order = $dir === 'up' ? 'DESC' : 'ASC';
    $neighbor = one("SELECT * FROM home_recommendations WHERE slot=? AND (sort $cmp ? OR (sort = ? AND id $cmp ?)) ORDER BY sort $order, id $order LIMIT 1", [$row['slot'], (int)$row['sort'], (int)$row['sort'], (int)$row['id']]);
    if (!$neighbor) throw new DomainException($dir === 'up' ? '已在楼位顶部' : '已在楼位底部');
    query('UPDATE home_recommendations SET sort=? WHERE id=?', [(int)$neighbor['sort'], (int)$row['id']]);
    query('UPDATE home_recommendations SET sort=? WHERE id=?', [(int)$row['sort'], (int)$neighbor['id']]);
    return true;
}
// ===== 运营数据看板：近 7/30 天核心指标纯读聚合（口径：实付分 = total_amount − discount_amount − points_discount_cents − member_discount_cents，与退款口径决策一致） =====
// 返回各维度数组：窗口内订单数/已支付单数/GMV 分、状态分布、支付方式分布、销量 TOP10、退款率
function admin_stats_overview() {
    $paidStates = "('paid','shipped','completed')";
    $out = [];
    foreach ([7 => 'last7', 30 => 'last30'] as $days => $key) {
        $row = one("SELECT COUNT(*) AS orders,
                           SUM(status IN $paidStates) AS paid_orders,
                           COALESCE(SUM(CASE WHEN status IN $paidStates
                                             THEN ROUND((total_amount - discount_amount) * 100) - points_discount_cents - member_discount_cents
                                             ELSE 0 END), 0) AS gmv_cents
                    FROM orders
                    WHERE created_at >= NOW() - INTERVAL $days DAY");
        $out[$key . '_orders'] = (int)$row['orders'];
        $out[$key . '_paid_orders'] = (int)$row['paid_orders'];
        $out[$key . '_gmv_cents'] = (int)$row['gmv_cents'];
    }
    // 近 30 天订单状态分布
    $out['status_dist'] = [];
    foreach (rows("SELECT status, COUNT(*) AS cnt FROM orders
                   WHERE created_at >= NOW() - INTERVAL 30 DAY
                   GROUP BY status ORDER BY cnt DESC") as $r) {
        $out['status_dist'][$r['status']] = (int)$r['cnt'];
    }
    // 近 30 天支付方式分布（已支付口径）
    $out['payment_dist'] = [];
    foreach (rows("SELECT payment_method, COUNT(*) AS cnt FROM orders
                   WHERE created_at >= NOW() - INTERVAL 30 DAY AND status IN $paidStates AND payment_method IS NOT NULL
                   GROUP BY payment_method ORDER BY cnt DESC") as $r) {
        $out['payment_dist'][$r['payment_method']] = (int)$r['cnt'];
    }
    // 近 30 天销量 TOP10 商品（已支付口径，order_items 聚合，商品可能已删则用快照标题）
    $out['top_products'] = rows("SELECT oi.product_id, COALESCE(oi.product_title, p.title, '（已删除商品）') AS title,
                                        SUM(oi.quantity) AS sold
                                 FROM order_items oi
                                 JOIN orders o ON o.id = oi.order_id
                                 LEFT JOIN products p ON p.id = oi.product_id
                                 WHERE o.created_at >= NOW() - INTERVAL 30 DAY AND o.status IN $paidStates
                                 GROUP BY oi.product_id, oi.product_title, p.title
                                 ORDER BY sold DESC, oi.product_id ASC
                                 LIMIT 10");
    // 退款率：近 30 天审批通过的退款单 / 近 30 天已支付单（分母为 0 时返回 null 而非除零）
    $refunds = (int)one("SELECT COUNT(*) AS c FROM refund_requests
                         WHERE created_at >= NOW() - INTERVAL 30 DAY AND status='approved'")['c'];
    $out['refund_approved_30d'] = $refunds;
    $out['refund_rate_30d'] = $out['last30_paid_orders'] > 0
        ? round($refunds / $out['last30_paid_orders'] * 100, 1)
        : null;
    return $out;
}
// ===== 全站统一品牌 logo（2026-10-21）：SVG 商标此前只在首页内联，其余页面是纯文字，统一抽公共函数复用 =====
// SVG 商标：蓝色渐变圆角购物包 + 白色提手/分隔线 + 橙色星。help.php 为不连库静态链路，内联了同款 SVG（改动需两处同步）
function site_logo_svg(int $size = 36): string {
    return '<svg viewBox="0 0 100 100" width="'.$size.'" height="'.$size.'" aria-hidden="true"><defs><linearGradient id="logoBlue" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3b82f6"/><stop offset="100%" stop-color="#1d4ed8"/></linearGradient></defs><rect x="15" y="25" width="70" height="65" rx="14" fill="url(#logoBlue)"/><path d="M 35 25 A 15 15 0 0 1 65 25" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/><path d="M 35 45 L 35 70 M 65 45 L 65 70 M 35 57 L 65 57" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/><path d="M 75 18 L 77 25 L 84 27 L 77 29 L 75 36 L 73 29 L 66 27 L 73 25 Z" fill="#f59e0b"/></svg>';
}
// 完整品牌锁定版式（SVG + 中英文双行字标），内联样式自包含，任何页面无需额外 CSS 即可正确渲染
function site_logo_html(): string {
    return '<div class="logo"><a href="index.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;">'.site_logo_svg()
        .'<span style="display:flex;flex-direction:column;line-height:1.1;"><span style="font-size:1.3rem;font-weight:800;color:#1e293b;">惠蒙优选</span><span style="font-size:0.55rem;font-weight:700;color:#2563eb;letter-spacing:1.2px;">HUIMENG CHOICE</span></span></a></div>';
}
function page_start($title) {
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).' · 惠蒙优选</title><link rel="stylesheet" href="css/shop.css?v=6"></head><body><header>'.site_logo_html().'<nav><a href="index.php">选购商品</a><a href="cart.php">购物车</a><a href="favorites.php">收藏</a><a href="my_coupons.php">优惠券</a><a href="my_orders.php">我的订单</a><a href="groups.php">我的拼团</a><a href="notifications.php">消息';
    if (!empty($_SESSION['user_id'])) {
        // 未读角标：一次性 COUNT 查询
        $unread = (int)one('SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=? AND read_at IS NULL', [(int)$_SESSION['user_id']])['n'];
        if ($unread > 0) echo '<sup style="color:#dc2626;font-weight:700;">'.(int)$unread.'</sup>';
    }
    echo '</a><a href="addresses.php">收货地址</a><a href="user_center.php">个人中心</a>';
    if (in_array($_SESSION['role'] ?? '',['admin','merchant'],true)) echo '<a href="admin_orders.php">发货 / 售后</a>';
    echo '</nav></header><main><h1>'.h($title).'</h1>';
    if (isset($_SESSION['notice'])) { echo '<p class="notice" role="status">'.h($_SESSION['notice']).'</p>'; unset($_SESSION['notice']); }
}
function page_end() {
    // 购物车角标（登录态，一次 COUNT 汇总）
    $dockCart = 0;
    if (!empty($_SESSION['user_id'])) {
        $dockCart = (int)one('SELECT COALESCE(SUM(quantity),0) AS q FROM cart WHERE user_id=?', [(int)$_SESSION['user_id']])['q'];
    }
    echo '</main><footer>惠蒙优选 · 精选好物，安心选购</footer>';
    // 全前台右侧悬浮 dock（京东式）：默认潜藏，滚动超 300px 浮现（.dock-on），纯 CSS/JS 无依赖
    echo '<div class="site-dock" id="site-dock">'
        . '<button type="button" class="dock-btn" id="dock-top" title="回到顶部">🔝</button>'
        . '<a class="dock-btn" href="my_orders.php" title="我的订单">📋</a>'
        . '<a class="dock-btn" href="cart.php" title="购物车">🛒' . ($dockCart > 0 ? '<i class="dock-badge">' . min($dockCart, 99) . '</i>' : '') . '</a>'
        . '<a class="dock-btn" href="help.php" title="帮助中心">❓</a>'
        . '</div>';
    echo '<script>(function(){var d=document.getElementById("site-dock"),t=document.getElementById("dock-top");if(!d)return;'
        . 'window.addEventListener("scroll",function(){d.classList.toggle("dock-on",window.scrollY>300);},{passive:true});'
        . 'if(t)t.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"});});})();</script>';
    echo '</body></html>';
}
function action_button($orderId, $action, $label) {
    return '<form class="inline" method="post" action="order_action.php">'.csrf_field().'<input type="hidden" name="order_id" value="'.(int)$orderId.'"><button name="action" value="'.h($action).'">'.h($label).'</button></form>';
}
// ===== 评价体系升级：首评唯一性由查询保证（唯一索引已降级为普通索引，以支持追评多行） =====
// ===== 评价印象标签：固定 8 个候选，白名单严格校验（in_array 第三参 true），逗号分隔存储 =====
function review_tag_whitelist() {
    return ['质量好', '物流快', '描述相符', '包装仔细', '性价比高', '服务态度好', '有瑕疵', '与图片不符'];
}
// 清洗用户勾选：非白名单值丢弃、去重、保序，返回逗号分隔串（未勾选返回空串）
function sanitize_review_tags($raw) {
    $out = [];
    foreach ((array)$raw as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '' && in_array($tag, review_tag_whitelist(), true) && !in_array($tag, $out, true)) $out[] = $tag;
    }
    return implode(',', $out);
}
// 商品评价印象标签聚合计数：拆分 tags 计数，取次数前 5（同次数按先出现顺序），无标签返回空数组
function review_tag_counts($productId) {
    $counts = [];
    foreach (rows("SELECT tags FROM reviews WHERE product_id=? AND tags<>''", [(int)$productId]) as $r) {
        foreach (explode(',', $r['tags']) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
    }
    arsort($counts);
    $out = [];
    $i = 0;
    foreach ($counts as $tag => $n) {
        if (++$i > 5) break;
        $out[] = ['tag' => $tag, 'count' => $n];
    }
    return $out;
}
function create_review($userId, $orderItemId, $rating, $content, $tags = '') {
    $item = one("SELECT i.* FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.id=? AND o.user_id=? AND o.status='completed'", [(int)$orderItemId, $userId]);
    if (!$item || !$item['product_id']) throw new DomainException('仅可评价本人已完成订单中的商品');
    if (one('SELECT id FROM reviews WHERE order_item_id=? AND parent_id IS NULL', [(int)$orderItemId])) throw new DomainException('该商品订单已经评价过了');
    $rating = (int)$rating;
    if ($rating < 1 || $rating > 5) throw new DomainException('请选择 1 至 5 星');
    $content = trim((string)$content);
    if (mb_strlen($content) < 2 || mb_strlen($content) > 1000) throw new DomainException('评价内容为 2 至 1000 字');
    $tags = sanitize_review_tags($tags);
    query('INSERT INTO reviews(user_id,order_item_id,product_id,rating,content,tags) VALUES(?,?,?,?,?,?)', [$userId, (int)$orderItemId, (int)$item['product_id'], $rating, $content, $tags]);
    $reviewId = (int)$GLOBALS['conn']->insert_id;
    grant_points($userId, 'review', 10, $reviewId, '首次评价奖励');
    return $reviewId;
}
// 追评：parent_id 指向首评，rating 固定 0；每个首评仅允许一条（查询保证，不加唯一索引）
function append_review($userId, $orderItemId, $content) {
    $item = one("SELECT i.*,o.completed_at FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.id=? AND o.user_id=? AND o.status='completed' AND o.completed_at >= NOW() - INTERVAL 30 DAY", [(int)$orderItemId, $userId]);
    if (!$item || !$item['product_id']) throw new DomainException('仅可在确认收货 30 天内追评本人已完成订单的商品');
    $first = one('SELECT id FROM reviews WHERE order_item_id=? AND parent_id IS NULL', [(int)$orderItemId]);
    if (!$first) throw new DomainException('请先评价商品后再追加评价');
    if (one('SELECT id FROM reviews WHERE parent_id=?', [(int)$first['id']])) throw new DomainException('该商品订单已追加过评价');
    $content = trim((string)$content);
    if (mb_strlen($content) < 2 || mb_strlen($content) > 1000) throw new DomainException('追评内容为 2 至 1000 字');
    query('INSERT INTO reviews(user_id,order_item_id,product_id,rating,content,parent_id) VALUES(?,?,?,0,?,?)', [$userId, (int)$orderItemId, (int)$item['product_id'], $content, (int)$first['id']]);
    $followupId = (int)$GLOBALS['conn']->insert_id;
    grant_points($userId, 'followup', 5, $followupId, '追评奖励');
    return $followupId;
}
// 商家回复：仅本店商品的首评，每条仅可回复一次（replied_at 为准）
function merchant_reply_review($merchantUserId, $reviewId, $content) {
    $review = one('SELECT r.*,p.shop_id FROM reviews r JOIN products p ON p.id=r.product_id WHERE r.id=? AND r.parent_id IS NULL', [(int)$reviewId]);
    if (!$review || !$review['shop_id'] || !one('SELECT id FROM shops WHERE id=? AND user_id=? AND status=1', [(int)$review['shop_id'], $merchantUserId])) throw new DomainException('仅可回复本店商品的评价');
    $content = trim((string)$content);
    if (mb_strlen($content) < 2 || mb_strlen($content) > 500) throw new DomainException('回复内容需为 2 至 500 个字符');
    $stmt = query('UPDATE reviews SET reply_content=?, replied_at=NOW() WHERE id=? AND replied_at IS NULL', [$content, (int)$reviewId]);
    if ((int)$stmt->affected_rows !== 1) throw new DomainException('该评价已回复过，不能重复回复');
    return true;
}
// 晒图：整理 $_FILES 多文件结构，超过 3 张直接拒绝（不落库）
function validate_review_images($files) {
    $list = [];
    foreach ((array)($files['name'] ?? []) as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $list[] = ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int)($files['size'][$i] ?? 0)];
    }
    if (count($list) > 3) throw new DomainException('评价图片最多上传 3 张');
    return $list;
}
// ===== 商户对账单：按自然月导出本店订单 CSV 行（页面输出与测试共用；金额以分为单位，数字列前加制表符防 Excel 科学计数） =====
function shop_statement_rows($shopId, $month) {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$month)) throw new DomainException('月份格式须为 Y-m');
    $start = $month.'-01 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start.' +1 month'));
    $orders = rows("SELECT order_no,created_at,paid_at,status,total_amount,discount_amount,express_no
        FROM orders WHERE shop_id=? AND status IN ('paid','shipped','completed','cancelled') AND created_at>=? AND created_at<? ORDER BY id",
        [(int)$shopId, $start, $end]);
    $rows = [['订单号','下单时间','支付时间','订单状态','商品合计（元）','优惠金额（元）','实付金额（元）','运单号']];
    $sales = 0; $discount = 0; $paid = 0;
    foreach ($orders as $o) {
        $s = (int)round(((float)$o['total_amount']) * 100);
        $d = (int)round(((float)$o['discount_amount']) * 100);
        $sales += $s; $discount += $d; $paid += $s - $d;
        $rows[] = [$o['order_no'], (string)$o['created_at'], $o['paid_at'] !== null ? (string)$o['paid_at'] : '', status_name($o['status']),
            "\t".money($s), "\t".money($d), "\t".money($s - $d), (string)($o['express_no'] ?? '')];
    }
    $rows[] = ['汇总', "\t".count($orders), '', '', "\t".money($sales), "\t".money($discount), "\t".money($paid), ''];
    return $rows;
}
// ===== 搜索联想：日志前缀热词 + 在售商品名合并去重，最多 8 条；未登录可访问 =====
function search_suggest_words($keyword) {
    $keyword = trim((string)$keyword);
    if (mb_strlen($keyword) < 2 || mb_strlen($keyword) > 100) return [];
    $like = addcslashes($keyword, "%_\\");
    $words = [];
    foreach (rows('SELECT keyword, COUNT(*) AS n FROM search_logs WHERE keyword LIKE ? GROUP BY keyword HAVING n >= 2 ORDER BY n DESC, keyword ASC LIMIT 5', [$like.'%']) as $row) $words[] = $row['keyword'];
    foreach (rows("SELECT p.title FROM products p WHERE p.status=1 AND p.audit_status='approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops s WHERE s.id=p.shop_id AND s.status=1)) AND p.title LIKE ? ORDER BY p.id DESC LIMIT 5", ['%'.$like.'%']) as $row) {
        if (!in_array($row['title'], $words, true)) $words[] = $row['title'];
    }
    return array_slice($words, 0, 8);
}

// 品牌规范化：去重归并到库内已有规范写法（大小写不敏感），无匹配则返回清理后的新值
if (!function_exists('canonical_brand')) {
function canonical_brand($raw) {
    $raw = str_replace('　', ' ', trim((string)$raw)); // 全角空格先行转半角
    if ($raw === '') return '';
    $raw = preg_replace('/\s+/u', ' ', $raw); // 压缩连续空白
    if (function_exists('mb_strtolower')) { $lower = mb_strtolower($raw, 'UTF-8'); }
    else { $lower = strtolower($raw); }
    $row = one("SELECT brand FROM products WHERE brand <> '' AND LOWER(brand) = ? ORDER BY id ASC LIMIT 1", [$lower]);
    return $row ? $row['brand'] : $raw;
}}
