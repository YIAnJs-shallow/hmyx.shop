<?php
// 账号登录查找（用户名/邮箱/手机号单框）与企业/商户注册事务
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/coupon.php';

/** 账号登录：用户名、邮箱或手机号任一命中即返回用户（含密码哈希供 login_account 校验） */
function find_user_by_login_identifier($identifier) {
    return one('SELECT id, username, password, role FROM users WHERE username=? OR email=? OR phone=? LIMIT 1', [$identifier, $identifier, $identifier]);
}

/**
 * 企业/商户注册：事务内创建 users 账号（role=merchant）+ INSERT shops（status=1）+ 发新人券，
 * 任一失败整体回滚（不残留 users/shops 行）。返回新用户 id。
 */
function register_merchant_account($username, $email, $password, $shopName, $contactPhone, $businessScope) {
    global $conn;
    $username = trim((string)$username);
    $email = trim((string)$email);
    $shopName = trim((string)$shopName);
    $contactPhone = trim((string)$contactPhone);
    $businessScope = trim((string)$businessScope);

    if ($username === '' || mb_strlen($username) > 50) throw new DomainException('用户名不能为空且不超过 50 个字符');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) throw new DomainException('请输入有效的邮箱地址');
    // 密码规则与个人注册一致：至少 8 位，同时包含字母和数字
    if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/', (string)$password)) throw new DomainException('密码强度不足！必须至少 8 位，且必须同时包含字母和数字。');
    if ($shopName === '' || mb_strlen($shopName) > 100) throw new DomainException('请填写店铺名称（不超过 100 个字符）');
    if ($contactPhone === '' || mb_strlen($contactPhone) > 20) throw new DomainException('请填写联系电话（不超过 20 个字符）');
    if (mb_strlen($businessScope) > 100) throw new DomainException('经营范围不超过 100 个字符');

    $conn->begin_transaction();
    try {
        // 用户名/邮箱唯一性由唯一索引兜底（重复插入报 1062 整体回滚）
        query("INSERT INTO users (username, email, password, role) VALUES (?,?,?,'merchant')", [$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$conn->insert_id;
        query('INSERT INTO shops (user_id, shop_name, contact_phone, business_scope, status) VALUES (?,?,?,?,1)', [$userId, $shopName, $contactPhone, $businessScope]);
        // 发新人券在事务内：失败抛异常整体回滚，保证注册原子性
        coupon_grant_newuser($userId, true);
        $conn->commit();
        return $userId;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
