<?php
// 管理员后台三层校验：认证(L1) / 授权(L2) / 风险管控(L3)
// 依赖 config/db.php（$conn）与 lib/security.php，页面经 admin/inc/auth.php 统一加载
if (!isset($conn)) { http_response_code(503); exit('数据库未连接'); }

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
    if (!$row || $row['status'] !== 'active') { unset($_SESSION['admin']); return; }

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

function audit_log($action, $target_type = '', $target_id = '', $detail = [], $status = 'success') {
    global $conn;
    $admin = $_SESSION['admin'] ?? [];
    $admin_id = isset($admin['id']) ? (int)$admin['id'] : null;
    $username = (string)($admin['username'] ?? '');
    $action = (string)$action;
    $target_type = (string)$target_type;
    $target_id = is_array($target_id) ? implode(',', $target_id) : (string)$target_id;
    $json = json_encode($detail, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) $json = '';
    $json = substr($json, 0, 1000);
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
        error_log('audit_log failed: ' . $e->getMessage()); // 审计失败不阻断业务，但必须留痕到错误日志
    }
}

// ============ 登录辅助 ============

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
    if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['port'], $parts['fragment'])) return '';
    $path = basename($parts['path'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/D', $path) || !is_file(__DIR__ . '/../admin/' . $path)) return '';
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
