<?php
// 优惠券模块：领取、结算校验、跨店拆单分摊、取消回补
// 金额一律以分为单位（与 lib/shop.php 的 cents()/money() 约定一致）
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../config/db.php';
if (!function_exists('query')) {
    function query($sql, $params = []) {
        global $conn;
        $stmt = $conn->prepare($sql);
        if ($params) { $types = ''; foreach ($params as $p) $types .= is_int($p) ? 'i' : 's'; $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        return $stmt;
    }
    function rows($sql, $params = []) { return query($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC); }
    function one($sql, $params = []) { return query($sql, $params)->get_result()->fetch_assoc(); }
}

/** 用户可用的未使用优惠券（可加最低消费过滤；$orderProductIds 传数组时按券适用范围过滤，null 保持既有行为） */
function coupons_for_user($userId, $grandTotalCents = null, $orderProductIds = null) {
    $sql = 'SELECT uc.id, c.title, c.discount_cents, c.min_spend_cents, c.expire_at, c.scope, c.product_id, c.valid_days
            FROM user_coupons uc JOIN coupons c ON c.id = uc.coupon_id
            WHERE uc.user_id = ? AND uc.status = "unused" AND c.active = 1
              AND (c.expire_at IS NULL OR c.expire_at > NOW())
              AND (c.valid_days IS NULL OR uc.claimed_at + INTERVAL c.valid_days DAY > NOW())';
    $params = [(int)$userId];
    if ($grandTotalCents !== null) {
        $sql .= ' AND c.min_spend_cents <= ?';
        $params[] = (int)$grandTotalCents;
    }
    // 适用范围过滤：scope=product 时本次结算商品必须包含券指定商品（供下拉展示与预览；权威校验在 coupon_consume_locked）
    if ($orderProductIds !== null) {
        $ids = array_values(array_unique(array_map('intval', (array)$orderProductIds)));
        $sql .= $ids
            ? ' AND (c.scope = "all" OR (c.scope = "product" AND c.product_id IN ('.implode(',', $ids).')))'
            : ' AND c.scope = "all"';
    }
    $sql .= ' ORDER BY c.discount_cents DESC, uc.id ASC';
    return rows($sql, $params);
}

/** 领取后有效期判定：valid_days 非空时按 claimed_at + valid_days 时点比较（status 枚举不动，过期仅表现为不可用与展示"已过期"） */
function coupon_user_expired(array $uc) {
    if ($uc['valid_days'] === null) return false;
    $days = (int)$uc['valid_days'];
    if ($days <= 0) return true;
    return strtotime((string)$uc['claimed_at']) + $days * 86400 <= time();
}

/**
 * 购物车凑单提示：取用户未使用券中门槛最低的一张，结合购物车合计（不含运费）计算提示数据
 * 返回 null（无可用券）或 ['coupon','met','threshold_cents','gap_cents','save_cents','progress']
 */
function coupon_threshold_hint($userId, $cartTotalCents) {
    $coupons = coupons_for_user($userId);
    if (!$coupons) return null;
    usort($coupons, function ($a, $b) { return [(int)$a['min_spend_cents'], (int)$a['id']] <=> [(int)$b['min_spend_cents'], (int)$b['id']]; });
    $coupon = $coupons[0];
    $threshold = (int)$coupon['min_spend_cents'];
    if ((int)$cartTotalCents >= $threshold) {
        return ['coupon' => $coupon, 'met' => true, 'threshold_cents' => $threshold, 'gap_cents' => 0,
                'save_cents' => coupon_preview($coupon, $cartTotalCents), 'progress' => 100];
    }
    return ['coupon' => $coupon, 'met' => false, 'threshold_cents' => $threshold,
            'gap_cents' => $threshold - (int)$cartTotalCents, 'save_cents' => 0,
            'progress' => (int)$cartTotalCents > 0 ? (int)round($cartTotalCents * 100 / $threshold) : 0];
}

/** 结算预览：选中券对总金额的优惠（不落库） */
function coupon_preview($coupon, $grandTotalCents) {
    $discount = (int)$coupon['discount_cents'];
    if ($discount > (int)$grandTotalCents) $discount = (int)$grandTotalCents;
    return max(0, $discount);
}

/** 领取公开优惠券（幂等：已领则返回提示）；$inOuterTransaction=true 时不自行开关事务，由外层事务保证原子性 */
function coupon_claim($userId, $couponId, $inOuterTransaction = false) {
    global $conn;
    if (!$inOuterTransaction) $conn->begin_transaction();
    try {
        $coupon = one('SELECT * FROM coupons WHERE id=? AND active=1 FOR UPDATE', [(int)$couponId]);
        if (!$coupon) throw new DomainException('优惠券不存在或已下架');
        if ($coupon['expire_at'] && strtotime($coupon['expire_at']) <= time()) throw new DomainException('该优惠券已过期');
        if (one('SELECT id FROM user_coupons WHERE coupon_id=? AND user_id=?', [(int)$couponId, (int)$userId])) {
            $conn->commit();
            return '您已领取过该优惠券';
        }
        $upd = query('UPDATE coupons SET claimed_count = claimed_count + 1 WHERE id=? AND claimed_count < total_count', [(int)$couponId]);
        if ($upd->affected_rows !== 1) throw new DomainException('该优惠券已被领完');
        query('INSERT INTO user_coupons(coupon_id, user_id) VALUES(?,?)', [(int)$couponId, (int)$userId]);
        if (!$inOuterTransaction) $conn->commit();
        if (function_exists('notify')) notify((int)$userId, 'coupon', '优惠券领取成功', '您已成功领取「'.$coupon['title'].'」，下单时可在结算页选用。', 'my_coupons.php');
        return '领取成功：' . $coupon['title'];
    } catch (Throwable $e) { if (!$inOuterTransaction) $conn->rollback(); throw $e; }
}

/** 新用户注册后自动发放新人券（幂等）；事务内调用时发券失败会抛异常以回滚整个注册 */
function coupon_grant_newuser($userId, $inOuterTransaction = false) {
    foreach (rows('SELECT id FROM coupons WHERE is_newuser=1 AND active=1 AND (expire_at IS NULL OR expire_at > NOW())', []) as $c) {
        try { coupon_claim($userId, (int)$c['id'], $inOuterTransaction); } catch (Throwable $e) {
            if ($inOuterTransaction) throw $e;
            error_log('新人券发放失败: ' . $e->getMessage());
        }
    }
}

/**
 * 下单时核销优惠券（在 create_orders 的事务内调用，不自行开关事务）
 * 返回优惠金额（分）；无效则抛出异常。
 */
function coupon_consume_locked($userId, $userCouponId, $grandTotalCents, $orderProductIds = null) {
    $uc = one('SELECT uc.*, c.discount_cents, c.min_spend_cents, c.active, c.expire_at, c.scope, c.product_id, c.valid_days
               FROM user_coupons uc JOIN coupons c ON c.id = uc.coupon_id
               WHERE uc.id=? AND uc.user_id=? FOR UPDATE', [(int)$userCouponId, (int)$userId]);
    if (!$uc) throw new DomainException('优惠券不存在');
    if ($uc['status'] !== 'unused') throw new DomainException('优惠券已使用，请重新选择');
    if (!(int)$uc['active']) throw new DomainException('优惠券已下架');
    if ($uc['expire_at'] && strtotime($uc['expire_at']) <= time()) throw new DomainException('优惠券已过期');
    if (coupon_user_expired($uc)) throw new DomainException('优惠券已过期');
    // 适用范围权威校验：scope=product 时本次订单商品必须包含指定商品（先校验后计价，服务端兜底防篡改）
    if (($uc['scope'] ?? 'all') === 'product' && !in_array((int)$uc['product_id'], array_map('intval', (array)$orderProductIds), true)) {
        throw new DomainException('该优惠券仅适用于指定商品');
    }
    if ((int)$grandTotalCents < (int)$uc['min_spend_cents']) throw new DomainException('未达到该优惠券的最低使用金额');
    query("UPDATE user_coupons SET status='used', used_at=NOW() WHERE id=? AND status='unused'", [(int)$uc['id']]);
    return coupon_preview($uc, $grandTotalCents);
}

/** 订单取消（含超时）后，若该券名下再无有效订单则回补为可使用 */
function coupon_restore_for_order($orderId) {
    $ucId = one('SELECT coupon_user_id FROM orders WHERE id=?', [(int)$orderId])['coupon_user_id'] ?? null;
    if (!$ucId) return;
    $active = one("SELECT id FROM orders WHERE coupon_user_id=? AND id<>? AND status NOT IN ('cancelled') LIMIT 1", [(int)$ucId, (int)$orderId]);
    if ($active) return;
    query("UPDATE user_coupons SET status='unused', used_at=NULL WHERE id=? AND status='used'", [(int)$ucId]);
}

/** 后台创建优惠券（校验后 INSERT，返回新券 id）；非法输入抛 DomainException，审计由调用方记录 */
function admin_create_coupon($title, $discountCents, $minSpendCents, $totalCount, $isNewuser, $expireAt, $scope = 'all', $productId = 0, $validDays = null) {
    $title = trim((string)$title);
    $discountCents = (int)$discountCents;
    $minSpendCents = (int)$minSpendCents;
    $totalCount = (int)$totalCount;
    $isNewuser = ((int)$isNewuser === 1) ? 1 : 0;
    $expireAt = trim((string)$expireAt);
    if (mb_strlen($title) < 2 || mb_strlen($title) > 100) throw new DomainException('券标题需为 2 至 100 个字符');
    if ($discountCents <= 0 || $discountCents > 99999999) throw new DomainException('面额须为 1 至 99999999 分');
    if ($minSpendCents < 0 || $minSpendCents > 99999999) throw new DomainException('最低消费门槛无效');
    if ($totalCount <= 0 || $totalCount > 1000000) throw new DomainException('发放总量须为 1 至 1000000 张');
    if ($expireAt === '') { $expireAt = null; }
    else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expireAt) || strtotime($expireAt) === false) throw new DomainException('有效期格式须为 Y-m-d H:i:s');
        if (strtotime($expireAt) <= time()) throw new DomainException('有效期须晚于当前时间');
    }
    // 适用范围与领取后有效天数（服务端白名单：scope 枚举校验、指定商品须存在、有效天数 1-3650）
    if (!in_array($scope, ['all', 'product'], true)) throw new DomainException('适用范围无效');
    if ($scope === 'product') {
        $productId = (int)$productId;
        if (!$productId || !one('SELECT id FROM products WHERE id=?', [$productId])) throw new DomainException('适用商品无效');
    } else {
        $productId = null;
    }
    $validDays = trim((string)$validDays);
    if ($validDays === '') {
        $validDays = null;
    } elseif (!preg_match('/^\d{1,4}$/D', $validDays) || (int)$validDays < 1 || (int)$validDays > 3650) {
        throw new DomainException('领取后有效天数须为 1 至 3650 的整数');
    } else {
        $validDays = (int)$validDays;
    }
    query('INSERT INTO coupons(title,discount_cents,min_spend_cents,total_count,is_newuser,expire_at,scope,product_id,valid_days) VALUES(?,?,?,?,?,?,?,?,?)',
        [$title, $discountCents, $minSpendCents, $totalCount, $isNewuser, $expireAt, $scope, $productId, $validDays]);
    return (int)$GLOBALS['conn']->insert_id;
}

/** 按各店订单金额比例把整单优惠分摊到各订单（分为单位，余数给第一单） */
function coupon_split_discount($discountCents, array $orderTotals) {
    $grand = array_sum($orderTotals);
    if ($grand <= 0 || $discountCents <= 0) return array_fill_keys(array_keys($orderTotals), 0);
    $shares = [];
    $allocated = 0;
    $last = null;
    foreach ($orderTotals as $key => $total) {
        $shares[$key] = (int)floor($discountCents * $total / $grand);
        $allocated += $shares[$key];
        $last = $key;
    }
    if ($last !== null) $shares[$last] += $discountCents - $allocated;
    return $shares;
}
