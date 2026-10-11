<?php
// 找回密码共享逻辑：验证码签发/校验（哈希存储、10 分钟有效、防轰炸、锁定）+ 前台审计
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/mailer.php';

const PASSWORD_RESET_TTL_MINUTES = 10;      // 验证码有效期（分钟）
const PASSWORD_RESET_RESEND_MINUTES = 10;   // 同一邮箱重发间隔（分钟）
const PASSWORD_RESET_MAX_ATTEMPTS = 5;      // 连续错误次数上限
const PASSWORD_RESET_LOCK_MINUTES = 30;     // 锁定时长（分钟）

/** 邮箱脱敏（审计展示用）：ab***@qq.com */
function mask_email($email) {
    $email = (string)$email;
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) return '***';
    $local = substr($email, 0, $at);
    $head = mb_substr($local, 0, 2, 'UTF-8');
    return $head . '***' . substr($email, $at);
}

/** 前台找回密码事件审计：沿用 admin_audit_logs 通用审计表（admin_id 为 NULL，username 存脱敏邮箱快照） */
function password_reset_audit($action, $email, $status, $detail = []) {
    global $conn;
    $masked = mask_email($email);
    $json = json_encode($detail, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) $json = '';
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $stmt = $conn->prepare('INSERT INTO admin_audit_logs
            (admin_id, username, action, target_type, target_id, detail, status, ip, user_agent)
            VALUES (NULL,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssssssss', $masked, $action, $masked, $masked, $json, $status, $ip, $ua);
        $stmt->execute();
    } catch (Exception $e) {
        error_log('password_reset_audit failed: ' . $e->getMessage()); // 审计失败不阻断业务，但必须留痕到错误日志
    }
}

/**
 * 签发验证码。返回：
 *   'sent'            已生成并发送
 *   'rate_limited'    10 分钟内已发过（防轰炸，不区分邮箱是否注册）
 *   'not_registered'  邮箱未注册（调用方对外仍显示统一提示）
 *   'send_failed'     邮件发送失败（内部用，对外仍统一提示）
 */
function password_reset_issue($email) {
    // 防轰炸：同一邮箱 10 分钟内只发 1 次
    $recent = one('SELECT id FROM password_reset_codes WHERE email=? AND created_at > NOW() - INTERVAL ' . PASSWORD_RESET_RESEND_MINUTES . ' MINUTE', [$email]);
    if ($recent) return 'rate_limited';

    $user = one('SELECT id FROM users WHERE email=?', [$email]);
    if (!$user) {
        password_reset_audit('user.password.reset_send', $email, 'success', ['registered' => false]);
        return 'not_registered';
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code);
    query('INSERT INTO password_reset_codes (email, code_hash, expires_at) VALUES (?,?,NOW() + INTERVAL ' . PASSWORD_RESET_TTL_MINUTES . ' MINUTE)', [$email, $hash]);

    try {
        send_mail($email, '惠蒙优选 找回密码验证码',
            '<p>您正在找回密码，验证码为：</p><p style="font-size:24px;font-weight:bold;letter-spacing:4px">' . $code . '</p>'
            . '<p>验证码 ' . PASSWORD_RESET_TTL_MINUTES . ' 分钟内有效，使用后立即作废。若非本人操作，请忽略本邮件。</p>');
    } catch (Exception $e) {
        error_log('password reset mail failed: ' . $e->getMessage());
        password_reset_audit('user.password.reset_send', $email, 'failure', ['error' => 'smtp_send_failed']);
        return 'send_failed';
    }
    password_reset_audit('user.password.reset_send', $email, 'success', ['registered' => true]);
    return 'sent';
}

/**
 * 校验验证码。返回：
 *   'ok'        验证通过（该验证码已标记作废，可继续设置新密码）
 *   'invalid'   验证码错误或已过期（已累计失败次数）
 *   'locked'    连续错误次数过多，锁定 30 分钟
 */
function password_reset_verify($email, $code) {
    $row = one('SELECT * FROM password_reset_codes WHERE email=? ORDER BY id DESC LIMIT 1', [$email]);
    if ($row && $row['locked_until'] !== null && strtotime($row['locked_until']) > time()) return 'locked';

    if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) <= time()) return 'invalid';

    if (hash_equals($row['code_hash'], hash('sha256', (string)$code))) {
        query('UPDATE password_reset_codes SET used_at=NOW() WHERE id=?', [$row['id']]);
        return 'ok';
    }

    $attempts = (int)$row['failed_attempts'] + 1;
    if ($attempts >= PASSWORD_RESET_MAX_ATTEMPTS) {
        query('UPDATE password_reset_codes SET failed_attempts=?, locked_until=NOW() + INTERVAL ' . PASSWORD_RESET_LOCK_MINUTES . ' MINUTE WHERE id=?', [$attempts, $row['id']]);
        password_reset_audit('user.password.reset_lock', $email, 'failure', ['attempts' => $attempts]);
        return 'locked';
    }
    query('UPDATE password_reset_codes SET failed_attempts=? WHERE id=?', [$attempts, $row['id']]);
    return 'invalid';
}

/** 用新密码更新该邮箱对应的账号（bcrypt），返回是否更新成功 */
function password_reset_apply($email, $newPassword) {
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    return query('UPDATE users SET password=? WHERE email=?', [$hash, $email])->affected_rows > 0;
}
