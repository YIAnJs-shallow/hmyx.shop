<?php
// ===== 积分体系：grant_points 流水发放/扣减 + try_signin 每日签到 =====
// 依赖 lib/shop.php 的 query/one（由调用方先行加载）；积分规则写死：签到+5、连签逢7天额外+20、首评+10、追评+5、订单实付每1元=1分。
// 100 积分 = 1 元（1 积分 = 1 分），单笔订单抵扣上限为应付金额的 10%。

// 当前连接是否已在外层事务内：在则直接参与，不开新事务（MySQL 无嵌套事务）
// MySQL 无 @@in_transaction（MariaDB 专有），用 SAVEPOINT 探测：事务内可建保存点，事务外报 1305
function points_in_transaction() {
    global $conn;
    try {
        $conn->query('SAVEPOINT points_tx_probe');
        $conn->query('RELEASE SAVEPOINT points_tx_probe');
        return true;
    } catch (Throwable $e) { return false; }
}

// 发放/扣减积分：事务内 INSERT points_log + UPDATE users.points
// 幂等防重：非 signin 类型且 ref_id 非空时，(user_id,type,ref_id) 已存在同向记录则不重复发放；
// 扣减（points<0）先锁行查余额，不足返回 false 不扣。
function grant_points($userId, $type, $points, $refId = null, $remark = null) {
    global $conn;
    $userId = (int)$userId;
    $points = (int)$points;
    $refId = $refId === null ? null : (int)$refId;
    if ($userId <= 0 || $points === 0 || !in_array($type, ['signin','order','review','followup','redeem','pay'], true)) return false;
    if ($type !== 'signin' && $refId !== null) {
        if (one('SELECT id FROM points_log WHERE user_id=? AND type=? AND ref_id=? AND ((change_points>0 AND ?>0) OR (change_points<0 AND ?<0))', [$userId, $type, $refId, $points, $points])) return true;
    }
    $own = points_in_transaction();
    if (!$own) $conn->begin_transaction();
    try {
        if ($points < 0) {
            $user = one('SELECT points FROM users WHERE id=? FOR UPDATE', [$userId]);
            if (!$user || (int)$user['points'] + $points < 0) { if (!$own) $conn->rollback(); return false; }
        }
        query('INSERT INTO points_log(user_id,change_points,type,ref_id,remark) VALUES(?,?,?,?,?)', [$userId, $points, $type, $refId, $remark === null ? null : mb_substr($remark, 0, 100)]);
        query('UPDATE users SET points=points+? WHERE id=?', [$points, $userId]);
        if (!$own) $conn->commit();
        return true;
    } catch (Throwable $e) { if (!$own) $conn->rollback(); throw $e; }
}

// 每日签到：事务内 INSERT user_signins（唯一键防重）；昨日有记录则 streak+1，否则重置 1；
// streak 为 7 的整数倍当天额外 +20（当天共 +25）。重复签到返回已有记录与 ok=false。
function try_signin($userId) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0) return ['ok' => false, 'record' => null, 'streak' => 0, 'points' => 0];
    $own = points_in_transaction();
    if (!$own) $conn->begin_transaction();
    try {
        $yesterday = one('SELECT streak FROM user_signins WHERE user_id=? AND sign_date=CURDATE()-INTERVAL 1 DAY', [$userId]);
        $streak = $yesterday ? (int)$yesterday['streak'] + 1 : 1;
        query('INSERT INTO user_signins(user_id,sign_date,streak) VALUES(?,CURDATE(),?)', [$userId, $streak]);
        $bonus = ($streak % 7 === 0) ? 20 : 0;
        $points = 5 + $bonus;
        grant_points($userId, 'signin', $points, 0, '每日签到'.($bonus > 0 ? '（连续签到 '.$streak.' 天，额外 +'.$bonus.'）' : ''));
        if (!$own) $conn->commit();
        return ['ok' => true, 'record' => one('SELECT * FROM user_signins WHERE user_id=? AND sign_date=CURDATE()', [$userId]), 'streak' => $streak, 'points' => $points];
    } catch (mysqli_sql_exception $e) {
        if (!$own) $conn->rollback();
        if ((int)$e->getCode() === 1062) {
            $record = one('SELECT * FROM user_signins WHERE user_id=? AND sign_date=CURDATE()', [$userId]);
            return ['ok' => false, 'record' => $record, 'streak' => (int)($record['streak'] ?? 0), 'points' => 0];
        }
        throw $e;
    } catch (Throwable $e) { if (!$own) $conn->rollback(); throw $e; }
}
