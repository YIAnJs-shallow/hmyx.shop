<?php
// ===== 会员等级体系：阈值表（user_levels）+ users 快照（level_id/total_spent_cents）+ level_log 流水 =====
// 依赖 lib/shop.php 的 query/one 与 lib/points.php 的 points_in_transaction（由 lib/shop.php 先行加载）。
// 口径写死：等级按累计实付（订单 completed 时 total_amount - 优惠券 - 积分抵现 - 会员折扣）自动升级；
// 退款/售后/拼团超时退款一律不扣减 total_spent_cents、不降级（终身制，与 CRMEB/ECShop 共识一致）。

// 在外层事务内调用：FOR UPDATE 锁用户行，按 total_spent_cents 匹配最高阈值等级，
// 与当前 level_id 不同则回写快照并写 level_log（delta=0 仅记变更）；返回等级 id（用户不存在返回 0）
function level_recalc($userId, $orderId = null) {
    $user = one('SELECT level_id,total_spent_cents FROM users WHERE id=? FOR UPDATE', [(int)$userId]);
    if (!$user) return 0;
    $level = one('SELECT id FROM user_levels WHERE min_spent_cents<=? ORDER BY min_spent_cents DESC LIMIT 1', [(int)$user['total_spent_cents']]);
    $newId = $level ? (int)$level['id'] : 1;
    $oldId = (int)$user['level_id'];
    if ($newId !== $oldId) {
        query('UPDATE users SET level_id=? WHERE id=?', [$newId, (int)$userId]);
        query('INSERT INTO level_log(user_id,order_id,delta_cents,old_level_id,new_level_id) VALUES(?,?,?,?,?)',
            [(int)$userId, $orderId === null ? null : (int)$orderId, 0, $oldId, $newId]);
    }
    return $newId;
}

// 订单转 completed 的既有事务内调用（与积分发放同一触发点）：幂等（同一订单只积累一次），
// FOR UPDATE 累加 users.total_spent_cents、写 level_log（delta=实付分），随后 level_recalc 重算等级
function level_accrue($userId, $orderId, $actualPaidCents) {
    $userId = (int)$userId; $orderId = (int)$orderId; $actualPaidCents = (int)$actualPaidCents;
    if ($userId <= 0 || $orderId <= 0 || $actualPaidCents <= 0) return false;
    if (one('SELECT id FROM level_log WHERE user_id=? AND order_id=? AND delta_cents>0', [$userId, $orderId])) return true;
    $user = one('SELECT level_id FROM users WHERE id=? FOR UPDATE', [$userId]);
    if (!$user) return false;
    query('UPDATE users SET total_spent_cents=total_spent_cents+? WHERE id=?', [$actualPaidCents, $userId]);
    query('INSERT INTO level_log(user_id,order_id,delta_cents,old_level_id,new_level_id) VALUES(?,?,?,?,?)',
        [$userId, $orderId, $actualPaidCents, (int)$user['level_id'], (int)$user['level_id']]);
    level_recalc($userId, $orderId);
    return true;
}

// 当前等级折扣（100=无折扣）；用户或等级缺失时兜底 100，不影响既有金额计算
function level_discount_percent($userId) {
    $row = one('SELECT l.discount_percent FROM users u JOIN user_levels l ON l.id=u.level_id WHERE u.id=?', [(int)$userId]);
    return $row ? (int)$row['discount_percent'] : 100;
}
