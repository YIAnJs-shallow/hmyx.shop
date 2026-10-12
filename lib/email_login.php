<?php
// 邮箱验证码登录（注册/登录二合一）：验证码签发/校验（哈希存储、10 分钟有效、60 秒限发、连错 5 次锁 30 分钟）
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/coupon.php';

const EMAIL_LOGIN_TTL_MINUTES = 10;      // 验证码有效期（分钟）
const EMAIL_LOGIN_RESEND_SECONDS = 60;  // 同一邮箱重发间隔（秒）
const EMAIL_LOGIN_MAX_ATTEMPTS = 5;     // 连续错误次数上限
const EMAIL_LOGIN_LOCK_MINUTES = 30;    // 锁定时长（分钟）

/**
 * 签发登录验证码。返回：
 *   'sent'          已生成并尝试发送（发送失败不影响验证码落库）
 *   'rate_limited'  60 秒内已发过
 * 不区分邮箱是否注册（验证码登录是注册/登录二合一，新邮箱同样发码，防枚举由接口层统一文案保证）
 */
function email_login_issue($email) {
    // 防轰炸：同一邮箱 60 秒内只发 1 次
    $recent = one('SELECT id FROM email_login_codes WHERE email=? AND created_at > NOW() - INTERVAL ' . EMAIL_LOGIN_RESEND_SECONDS . ' SECOND', [$email]);
    if ($recent) return 'rate_limited';

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code);
    query('INSERT INTO email_login_codes (email, code_hash, expires_at) VALUES (?,?,NOW() + INTERVAL ' . EMAIL_LOGIN_TTL_MINUTES . ' MINUTE)', [$email, $hash]);

    try {
        send_mail($email, '惠蒙优选 登录验证码',
            '<p>您正在登录惠蒙优选，验证码为：</p><p style="font-size:24px;font-weight:bold;letter-spacing:4px">' . $code . '</p>'
            . '<p>验证码 ' . EMAIL_LOGIN_TTL_MINUTES . ' 分钟内有效。若该邮箱未注册，验证通过后将自动为您创建账号。若非本人操作，请忽略本邮件。</p>');
    } catch (Exception $e) {
        // 测试/未配置 SMTP 环境无法外发：验证码已落库，不影响接口返回与后续校验
        error_log('email login code mail failed: ' . $e->getMessage());
    }
    return 'sent';
}

/**
 * 校验登录验证码。返回：
 *   'ok'      验证通过（该验证码已删除，不可重放）
 *   'invalid' 验证码错误或已过期（已累计失败次数）
 *   'locked'  连续错误次数过多，锁定 30 分钟
 */
function email_login_verify($email, $code) {
    $row = one('SELECT * FROM email_login_codes WHERE email=? ORDER BY id DESC LIMIT 1', [$email]);
    if ($row && $row['locked_until'] !== null && strtotime($row['locked_until']) > time()) return 'locked';

    if (!$row || strtotime($row['expires_at']) <= time()) return 'invalid';

    if (hash_equals($row['code_hash'], hash('sha256', (string)$code))) {
        // 验证成功即作废：直接删除该码，防止重放
        query('DELETE FROM email_login_codes WHERE id=?', [$row['id']]);
        return 'ok';
    }

    $attempts = (int)$row['failed_attempts'] + 1;
    if ($attempts >= EMAIL_LOGIN_MAX_ATTEMPTS) {
        query('UPDATE email_login_codes SET failed_attempts=?, locked_until=NOW() + INTERVAL ' . EMAIL_LOGIN_LOCK_MINUTES . ' MINUTE WHERE id=?', [$attempts, $row['id']]);
        return 'locked';
    }
    query('UPDATE email_login_codes SET failed_attempts=? WHERE id=?', [$attempts, $row['id']]);
    return 'invalid';
}

/**
 * 验证码登录的注册/登录二合一：邮箱已存在直接返回该用户，不存在则自动创建账号
 * （用户名取邮箱前缀+随机后缀，密码为随机 32 位十六进制、不输出给用户）。
 */
function email_login_find_or_create($email) {
    global $conn;
    $user = one("SELECT id, username, email, password, role FROM users WHERE email=?", [$email]);
    if ($user) return $user;

    $prefix = mb_substr(explode('@', $email)[0], 0, 20, 'UTF-8');
    if ($prefix === '') $prefix = 'user';
    $randomPassword = bin2hex(random_bytes(16)); // 32 位随机密码，仅存哈希，不告知用户
    $hash = password_hash($randomPassword, PASSWORD_DEFAULT);

    for ($i = 0; $i < 3; $i++) {
        $username = $prefix . '_' . bin2hex(random_bytes(4));
        try {
            query("INSERT INTO users (username, email, password, role) VALUES (?,?,?,'user')", [$username, $email, $hash]);
            $userId = (int)$conn->insert_id;
            return one("SELECT id, username, email, password, role FROM users WHERE id=?", [$userId]);
        } catch (mysqli_sql_exception $e) {
            // 并发下唯一索引兜底：邮箱已被并发注册则改查返回；用户名碰撞则换随机后缀重试
            if (one("SELECT id FROM users WHERE email=?", [$email])) {
                return one("SELECT id, username, email, password, role FROM users WHERE email=?", [$email]);
            }
            if ($i === 2) throw $e;
        }
    }
}
