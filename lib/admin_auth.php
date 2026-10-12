<?php
// 管理员后台三层校验：认证(L1) / 授权(L2) / 风险管控(L3)
// 依赖 config/db.php（$conn）与 lib/security.php，页面经 admin/inc/auth.php 统一加载
if (!isset($conn)) { http_response_code(503); exit('数据库未连接'); }

// 后台 IP 白名单常量放在 Web 根目录外的 secrets.php（模板见 config/secrets.example.php）
if (!defined('ADMIN_ALLOWED_IPS')) {
    $admin_secrets_path = project_private_config_dir() . '/secrets.php';
    if (is_file($admin_secrets_path)) require_once $admin_secrets_path;
}

if (!function_exists('validate_admin_password')) {
    // 管理员强密码策略：>=10 位，大写/小写/数字/特殊字符至少三类，不含用户名
    function validate_admin_password($password, $username) {
        if (!is_string($password) || strlen($password) < 10) return '密码至少需要 10 位';
        if (stripos($password, (string)$username) !== false) return '密码不能包含用户名';
        $classes = 0;
        if (preg_match('/[A-Z]/', $password)) $classes++;
        if (preg_match('/[a-z]/', $password)) $classes++;
        if (preg_match('/\d/', $password)) $classes++;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $classes++;
        if ($classes < 3) return '密码需同时包含大写字母、小写字母、数字、特殊字符中的至少三类';
        return '';
    }
}

function admin_client_ip() { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }
function admin_user_agent() { return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255); }

// ============ L0 IP 白名单层 ============

// 登录失败计数基准：用于账号不存在路径的耗时对齐（不区分“账号不存在/密码错”）
if (!defined('ADMIN_LOGIN_TIMING_HASH')) define('ADMIN_LOGIN_TIMING_HASH', '$2y$10$L2EbDhP/ZUKYA5IrkUD3huNpi2a3iMg5bVsLGIWjBzVF3MoMfLcae');

// 白名单：逗号分隔的 IPv4/IPv6 字面量精确匹配；常量未定义时默认仅允许本机回环
function admin_allowed_ip_list() {
    $raw = defined('ADMIN_ALLOWED_IPS') ? (string)ADMIN_ALLOWED_IPS : '127.0.0.1,::1';
    $list = [];
    foreach (explode(',', $raw) as $ip) {
        $ip = strtolower(trim($ip));
        if ($ip !== '') $list[] = $ip;
    }
    return $list === [] ? ['127.0.0.1', '::1'] : $list;
}

function admin_ip_allowed($ip = null) {
    $ip = strtolower(trim((string)($ip !== null ? $ip : ($_SERVER['REMOTE_ADDR'] ?? ''))));
    if ($ip === '') return false;
    return in_array($ip, admin_allowed_ip_list(), true);
}

// 后台所有入口统一调用：非白名单 IP 一律 404（与不存在的页面表现一致，不暴露后台存在性）
function admin_require_allowed_ip() {
    if (admin_ip_allowed()) return;
    audit_log('admin.ip.denied', 'login', '', ['ip' => admin_client_ip()], 'failure');
    http_response_code(404);
    exit;
}

// ============ L1 认证层 ============

// 每请求从 DB 刷新管理员状态与权限：被禁用/锁死/权限变更即时生效
function refresh_admin_session() {
    global $conn;
    if (empty($_SESSION['admin']['id'])) return;
    $admin_id = (int)$_SESSION['admin']['id'];
    $stmt = $conn->prepare('SELECT a.id, a.username, a.status, a.is_super, a.must_change_password, a.locked_until, a.role_id, r.role_code
                            FROM admin_users a JOIN admin_roles r ON r.id = a.role_id WHERE a.id = ?');
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || $row['status'] !== 'active' || admin_lock_remaining($row) > 0) { unset($_SESSION['admin']); return; }

    $role_id = (int)$row['role_id'];
    $stmt = $conn->prepare('SELECT p.permission_code FROM admin_role_permissions rp
                            JOIN admin_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $perms = [];
    while ($p = $result->fetch_assoc()) $perms[] = $p['permission_code'];

    $login_at = $_SESSION['admin']['login_at'] ?? time();
    $_SESSION['admin'] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'is_super' => (int)$row['is_super'],
        'role_code' => $row['role_code'],
        'permissions' => $perms,
        'must_change_password' => (int)$row['must_change_password'],
        'login_at' => $login_at,
    ];
}

function is_admin_logged_in() { return !empty($_SESSION['admin']['id']); }
function is_super_admin() { return is_admin_logged_in() && ($_SESSION['admin']['is_super'] ?? 0) === 1; }

function require_admin_login() {
    refresh_admin_session();
    if (!is_admin_logged_in()) {
        header('Location: login.php?next=' . rawurlencode(admin_current_page_url()), true, 303);
        exit;
    }
    if (($_SESSION['admin']['must_change_password'] ?? 0) === 1 && basename($_SERVER['PHP_SELF']) !== 'password.php') {
        header('Location: password.php', true, 303);
        exit;
    }
}

// ============ L2 授权层 ============

function admin_has_permission($code) {
    if (is_super_admin()) return true; // 超管隐式拥有全部权限
    return in_array($code, $_SESSION['admin']['permissions'] ?? [], true);
}

function require_permission($code) {
    require_admin_login();
    if (!admin_has_permission($code)) {
        audit_log('permission.denied', 'permission', $code, [], 'failure');
        http_response_code(403);
        exit('无权限执行该操作。');
    }
}

// ============ L3 风险管控层 ============

function require_super_admin() {
    require_admin_login();
    if (!is_super_admin()) {
        audit_log('permission.denied', 'super', '', [], 'failure');
        http_response_code(403);
        exit('仅超级管理员可执行该操作。');
    }
}

// ============ 审计 ============

function audit_log($action, $target_type = '', $target_id = '', $detail = [], $status = 'success', $strict = false) {
    global $conn;
    $admin = $_SESSION['admin'] ?? [];
    $admin_id = isset($admin['id']) ? (int)$admin['id'] : null;
    $username = (string)($admin['username'] ?? '');
    $action = (string)$action;
    $target_type = (string)$target_type;
    $target_id = is_array($target_id) ? implode(',', $target_id) : (string)$target_id;
    $json = json_encode($detail, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) $json = '';
    if (strlen($json) > 1000) $json = json_encode(['summary'=>mb_strcut($json, 0, 700, 'UTF-8')], JSON_UNESCAPED_UNICODE);
    $target_id = substr($target_id, 0, 64);
    $status = $status === 'failure' ? 'failure' : 'success';
    $ip = admin_client_ip();
    $ua = admin_user_agent();
    try {
        $stmt = $conn->prepare('INSERT INTO admin_audit_logs
            (admin_id, username, action, target_type, target_id, detail, status, ip, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('issssssss', $admin_id, $username, $action, $target_type, $target_id, $json, $status, $ip, $ua);
        $stmt->execute();
    } catch (Exception $e) {
        if ($strict) throw $e;
        error_log('audit_log failed: ' . $e->getMessage()); // 审计失败不阻断业务，但必须留痕到错误日志
    }
}

// ============ 登录辅助 ============

// 管理员密码错误锁定阈值：连续 3 次锁 24 小时（阈值在代码里定，不改 admin_users 表结构）
if (!defined('ADMIN_LOGIN_MAX_FAILURES')) define('ADMIN_LOGIN_MAX_FAILURES', 3);
if (!defined('ADMIN_LOCK_HOURS')) define('ADMIN_LOCK_HOURS', 24);

// 记录一次管理员密码错误：达到阈值则锁定并返回 true（锁定提示不透露剩余次数）
function admin_record_login_failure(array $admin) {
    $failed = (int)($admin['failed_attempts'] ?? 0) + 1;
    if ($failed >= ADMIN_LOGIN_MAX_FAILURES) {
        query('UPDATE admin_users SET failed_attempts=0, locked_until=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?', [ADMIN_LOCK_HOURS, (int)$admin['id']]);
        return true;
    }
    query('UPDATE admin_users SET failed_attempts=? WHERE id=?', [$failed, (int)$admin['id']]);
    return false;
}

// ============ 敏感操作二次确认 ============

// 校验 POST 的 admin_confirm_password 是否为当前管理员密码；失败记审计（严格模式供测试/事务内使用）
function admin_confirm_password_valid($password, $target_type = '', $target_id = '') {
    if (!is_admin_logged_in() || !is_string($password) || $password === '') {
        if (is_admin_logged_in()) audit_log('admin.confirm.failed', $target_type, $target_id, ['reason' => 'empty_or_missing'], 'failure');
        return false;
    }
    $row = one('SELECT password FROM admin_users WHERE id=? LIMIT 1', [(int)$_SESSION['admin']['id']]);
    if ($row === null || !password_verify($password, $row['password'])) {
        audit_log('admin.confirm.failed', $target_type, $target_id, ['reason' => 'wrong_password'], 'failure');
        return false;
    }
    return true;
}

// 页面级守卫：敏感操作（删除/下架商品、改用户角色、重置用户密码等）执行前必须二次确认
function require_admin_confirm_password($return_url, $target_type = '', $target_id = '') {
    if (admin_confirm_password_valid($_POST['admin_confirm_password'] ?? null, $target_type, $target_id)) return;
    $_SESSION['admin_notice'] = '管理员密码确认失败，操作未执行。';
    header('Location: ' . $return_url, true, 303);
    exit;
}


// 返回剩余锁定秒数（0 = 未锁定）
function admin_lock_remaining($row) {
    if (empty($row['locked_until'])) return 0;
    $ts = strtotime($row['locked_until']);
    return ($ts !== false && $ts > time()) ? $ts - time() : 0;
}

// 后台专用 next 白名单：只允许 admin/ 目录内的 php 文件，防止跳回前台
function admin_current_page_url() {
    $path = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $url = $path . ($query === '' ? '' : '?' . $query);
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/D', $path) || !is_file(__DIR__ . '/../admin/' . $path)) return '';
    return $url;
}

function admin_safe_next_url($url) {
    if (!is_string($url) || $url === '' || strlen($url) > 500 || preg_match('/[\r\n]/', $url)) return '';
    $parts = parse_url($url);
    if ($parts === false || array_intersect(['scheme','host','user','pass','port','fragment'], array_keys($parts))) return '';
    $path = $parts['path'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/D', $path) || !is_file(__DIR__ . '/../admin/' . $path)) return '';
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
