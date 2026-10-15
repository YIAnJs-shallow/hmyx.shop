<?php
// ===== 拼团功能：虚拟总团池（自动匹配参团）+ 支付回调判团 + 超时退款 =====
// 团 = group_members 中的团长行（group_id = 自身 id），无需独立团状态表：
// 成团由该团已支付成员数 count >= group_buys.size 动态判定，成团结果落 orders.group_success。

// 商品当前生效的拼团活动（active 且仅一条）
function group_buy_active($productId) {
    return one("SELECT * FROM group_buys WHERE product_id=? AND status='active' LIMIT 1", [(int)$productId]);
}

// 商户端"开通拼团"四字段解析：全空返回 [null,null,null,null]（不开通/取消）；任一填写则全部校验。
// $noSkus 为 false（商品存在生效规格）时拒绝开通——拼团仅支持无规格商品，团购价才有唯一口径。
function parse_group_buy_fields($originalPriceCents, array $post, $noSkus = true) {
    $price = trim((string)($post['group_price'] ?? ''));
    $size = trim((string)($post['group_size'] ?? ''));
    $max = trim((string)($post['group_max_groups'] ?? ''));
    if ($max === '') $max = '0';
    $hours = trim((string)($post['group_duration_hours'] ?? ''));
    if ($hours === '') $hours = '3';
    if ($price === '' && $size === '') return [null, null, null, null];
    if ($price === '' || $size === '') throw new DomainException('团购价与成团人数须同时填写');
    if (!$noSkus) throw new DomainException('多规格商品暂不支持拼团，请先清空规格');
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $price) || (float)$price <= 0) throw new DomainException('团购价须为大于零的金额（最多两位小数）');
    $parts = explode('.', $price);
    $priceCents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    if ($priceCents >= (int)$originalPriceCents) throw new DomainException('团购价必须低于商品原价');
    if (!preg_match('/^\d+$/D', $size) || (int)$size < 2 || (int)$size > 10) throw new DomainException('成团人数须为 2 至 10 的整数');
    if (!preg_match('/^\d+$/D', $max) || (int)$max > 99999) throw new DomainException('总团数上限须为 0 至 99999 的整数（0 表示不限）');
    if (!preg_match('/^\d+$/D', $hours) || (int)$hours < 1 || (int)$hours > 72) throw new DomainException('成团时限须为 1 至 72 小时（与订单关单窗口一致取 3）');
    return [$priceCents, (int)$size, (int)$max, (int)$hours];
}

// 保存/更新拼团活动：同商品旧活动一律置 ended 后新插一行 active（归属校验由调用方保证）
function group_buy_save($productId, $priceCents, $size, $maxGroups, $durationHours) {
    query("UPDATE group_buys SET status='ended' WHERE product_id=? AND status='active'", [(int)$productId]);
    query("INSERT INTO group_buys(product_id,sku_id,price_cents,size,max_groups,duration_hours,status) VALUES(?,NULL,?,?,?,?,'active')",
        [(int)$productId, (int)$priceCents, (int)$size, (int)$maxGroups, (int)$durationHours]);
    return (int)$GLOBALS['conn']->insert_id;
}

// 手动结束拼团活动：返回结束的行数（0 表示本就没有进行中的活动）
function group_buy_end($productId) {
    $stmt = query("UPDATE group_buys SET status='ended' WHERE product_id=? AND status='active'", [(int)$productId]);
    return (int)$stmt->affected_rows;
}

// 该用户在此活动是否已占用未成团名额（订单未取消、未成团、所在团未超时）
function group_buy_user_slot_exists($userId, $groupBuyId, $durationHours) {
    return one("SELECT m.id FROM group_members m
        JOIN orders o ON o.id = m.order_id
        WHERE m.user_id=? AND o.group_buy_id=? AND o.status<>'cancelled' AND o.group_success=0
          AND EXISTS (SELECT 1 FROM group_members l JOIN orders lo ON lo.id=l.order_id
                      WHERE l.id=m.group_id AND lo.status<>'cancelled'
                        AND l.created_at > NOW() - INTERVAL ".(int)$durationHours." HOUR)
        LIMIT 1", [(int)$userId, (int)$groupBuyId]) !== null;
}

// 选取可加入的团（虚拟总团池自动匹配）：未超时、未成团、已支付人数未满、团长订单未取消的最早一团；无则返回 0（开新团）
function group_buy_pick_group(array $groupBuy) {
    $row = one("SELECT m.id AS group_id FROM group_members m
        JOIN orders o ON o.id = m.order_id
        WHERE m.group_id = m.id AND o.group_buy_id=? AND o.status<>'cancelled'
          AND m.created_at > NOW() - INTERVAL ".(int)$groupBuy['duration_hours']." HOUR
          AND NOT EXISTS (SELECT 1 FROM group_members m2 JOIN orders o2 ON o2.id=m2.order_id
                          WHERE m2.group_id=m.id AND o2.group_success=1)
          AND (SELECT COUNT(*) FROM group_members m3 JOIN orders o3 ON o3.id=m3.order_id
               WHERE m3.group_id=m.id AND o3.status IN ('paid','shipped','completed')) < ?
        ORDER BY m.id ASC LIMIT 1", [(int)$groupBuy['id'], (int)$groupBuy['size']]);
    return $row ? (int)$row['group_id'] : 0;
}

// 该活动已开团数（含已成团/已失败的团，用于总团数上限）
function group_buy_opened_groups($groupBuyId) {
    return (int)one("SELECT COUNT(*) AS n FROM group_members m
        WHERE m.group_id = m.id
          AND EXISTS (SELECT 1 FROM orders o WHERE o.id=m.order_id AND o.group_buy_id=?)", [(int)$groupBuyId])['n'];
}

// 活动统计：[已开团数, 已拼件数（已支付成员数）]
function group_buy_stats($groupBuyId) {
    $groups = group_buy_opened_groups($groupBuyId);
    $sold = (int)one("SELECT COUNT(*) AS n FROM group_members m JOIN orders o ON o.id=m.order_id
        WHERE o.group_buy_id=? AND o.status IN ('paid','shipped','completed')", [(int)$groupBuyId])['n'];
    return [$groups, $sold];
}

// 支付成功后的成团判定：须在 change_order('pay') 事务内调用（先锁 group_buys 行串行化并发判团）。
// 该团已支付成员数 >= size 时，把团内全部已支付订单标记 group_success=1 并逐人发站内通知。
function group_buy_settle($orderId) {
    $member = one('SELECT * FROM group_members WHERE order_id=?', [(int)$orderId]);
    if (!$member) return false;
    $gb = one("SELECT * FROM group_buys WHERE id=(SELECT group_buy_id FROM orders WHERE id=?) AND status='active' FOR UPDATE", [(int)$orderId]);
    if (!$gb) return false;
    $members = rows("SELECT m.*, o.status AS order_status, o.group_success FROM group_members m
        JOIN orders o ON o.id = m.order_id WHERE m.group_id=?", [(int)$member['group_id']]);
    $paidOrderIds = [];
    $paidUserIds = [];
    foreach ($members as $m) {
        if (in_array($m['order_status'], ['paid', 'shipped', 'completed'], true)) {
            $paidOrderIds[] = (int)$m['order_id'];
            $paidUserIds[] = (int)$m['user_id'];
        }
    }
    if (count($paidOrderIds) < (int)$gb['size']) return false;
    foreach ($paidOrderIds as $id) query('UPDATE orders SET group_success=1 WHERE id=? AND group_success=0', [$id]);
    foreach ($paidUserIds as $uid) notify($uid, 'order', '拼团成功', '您参与的《'.mb_substr((string)one('SELECT title FROM products WHERE id=?', [(int)$gb['product_id']])['title'], 0, 50).'》拼团已成团，商家将尽快发货。', 'groups.php');
    return true;
}

// 我参与的团列表（groups.php 用）：成员视角 + 团维度统计与倒计时
function group_buy_my_groups($userId) {
    return rows("SELECT m.group_id, m.role, m.created_at AS joined_at,
            o.id AS order_id, o.order_no, o.status AS order_status, o.group_success, o.total_amount,
            gb.price_cents, gb.size, gb.duration_hours,
            p.id AS product_id, p.title, p.image_url,
            l.created_at AS group_started,
            (SELECT COUNT(*) FROM group_members m2 JOIN orders o2 ON o2.id=m2.order_id
             WHERE m2.group_id=m.group_id AND o2.status IN ('paid','shipped','completed')) AS paid_count,
            (SELECT COUNT(*) FROM group_members m3 WHERE m3.group_id=m.group_id) AS member_count
        FROM group_members m
        JOIN orders o ON o.id=m.order_id
        JOIN group_buys gb ON gb.id=o.group_buy_id
        JOIN products p ON p.id=gb.product_id
        JOIN group_members l ON l.id=m.group_id
        WHERE m.user_id=?
        ORDER BY m.id DESC", [(int)$userId]);
}

// 团展示状态：已成团 / 拼团中（含剩余时间）/ 未成团已结束
function group_buy_status_label(array $row) {
    if ((int)$row['group_success'] === 1) return '已成团';
    $deadline = strtotime($row['group_started']) + (int)$row['duration_hours'] * 3600;
    if (time() >= $deadline) return '未成团（已结束）';
    return '拼团中 · '.(int)$row['paid_count'].'/'.$row['size'].' 人 · 剩余 '.max(0, $deadline - time()).' 秒';
}
