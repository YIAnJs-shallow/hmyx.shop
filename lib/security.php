<?php
// Shared by legacy pages and the new transaction flow (PHP 7.3+).
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
if (!isset($_SESSION)) $_SESSION = [];
date_default_timezone_set('Asia/Shanghai');
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_field() { return '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">'; }
// AJAX 请求识别：fetch 调用点统一带 X-Requested-With 头（XMLHttpRequest 或 fetch 均算），失败时返回 JSON 而非重定向
function request_wants_json() { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== ''; }
function csrf_check() {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (is_string($token) && hash_equals(csrf_token(), $token)) return;
    // 失败分流：AJAX 回 JSON（前端可提示真实原因并自愈）；表单 303 跳回当前页，
    // 页面重载即携带新 token，用户重新提交即可，不再落在纯文本 403 死胡同（夸克老内核实测卡死）。
    if (request_wants_json()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'message' => '页面已过期，请刷新页面后重试。', 'csrf_expired' => true], JSON_UNESCAPED_UNICODE));
    }
    $_SESSION['notice'] = '页面已过期，请重新提交。';
    $target = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: '.(is_string($target) && isset($target[0]) && $target[0] === '/' ? $target : 'index.php'), true, 303);
    exit;
}

// ===== 接口限流原语（2026-10-20 方向 A 安全加固）=====
// DB 计数桶：bucket_key 存桶名 sha256（防超长/特殊字符），窗口内 COUNT 达上限即拒绝。
// 依赖 query()/one()（lib/shop.php，调用点均在 config/db.php 加载之后，函数体内才解析故加载顺序安全）。
// 窗口口径对齐现有：登录失败窗口 10 分钟（=验证码 TTL），发码 IP 配额 1 小时，清理窗口 2 天。
const RATE_LOGIN_ID_MAX = 10;     // 同一账号密码失败上限 / 窗口
const RATE_LOGIN_ID_WINDOW = 600; // 10 分钟（对齐验证码 10 分钟 TTL）
const RATE_LOGIN_IP_MAX = 30;     // 同一 IP 密码失败上限 / 窗口
const RATE_LOGIN_IP_WINDOW = 600;
const RATE_SEND_IP_MAX = 20;      // 同一 IP 发码/找回/注册上限 / 窗口
const RATE_SEND_IP_WINDOW = 3600; // 1 小时
const RATE_GEO_IP_MAX = 120;      // 同一 IP 高德定位解析上限 / 窗口（保护配额，登录态内）
const RATE_GEO_IP_WINDOW = 3600;

function client_rate_ip() {
    // 只取 REMOTE_ADDR：X-Forwarded-For 等头可被伪造，不能作为限流依据
    return ($_SERVER['REMOTE_ADDR'] ?? '') !== '' ? $_SERVER['REMOTE_ADDR'] : 'cli';
}
function rate_limit_bucket_key($bucket) {
    return hash('sha256', (string)$bucket, false);
}
function rate_limit_count($bucket, $windowSeconds) {
    return (int)(one('SELECT COUNT(*) AS c FROM rate_limit_hits WHERE bucket_key=? AND hit_at > NOW() - INTERVAL ' . (int)$windowSeconds . ' SECOND', [rate_limit_bucket_key($bucket)])['c'] ?? 0);
}
// 仅查询不记账：用于"先判断是否已被限流"（如登录入口，被限流则不进凭据校验）
function rate_limit_ok($bucket, $max, $windowSeconds) {
    return rate_limit_count($bucket, $windowSeconds) < (int)$max;
}
// 查询并记账一次；超限返回 false（该次不计入，避免被拒请求无限续窗）
function rate_limit_hit($bucket, $max, $windowSeconds) {
    if (!rate_limit_ok($bucket, $max, $windowSeconds)) return false;
    query('INSERT INTO rate_limit_hits (bucket_key, hit_at) VALUES (?, NOW())', [rate_limit_bucket_key($bucket)]);
    // 顺带清理 2 天前旧行（表小无负担，免维护 cron）
    query('DELETE FROM rate_limit_hits WHERE hit_at < NOW() - INTERVAL 172800 SECOND', []);
    return true;
}
// 成功后清账：如登录成功清该账号失败计数，避免真实用户偶错后被锁
function rate_limit_clear($bucket) {
    query('DELETE FROM rate_limit_hits WHERE bucket_key=?', [rate_limit_bucket_key($bucket)]);
}
function account_password_needs_reset($stored) {
    return !is_string($stored) || !password_get_info($stored)['algo'];
}
function verify_account_password($input, $stored) {
    return is_string($input) && !account_password_needs_reset($stored) && password_verify($input, $stored);
}
function login_account($user, $input) {
    if (($user['role'] ?? '') === 'admin') return false;
    if (!verify_account_password($input, $user['password'])) return false;
    session_regenerate_id(true);
    // 重建前台登录态时保留 admin 键，前后台可同时登录
    $_SESSION = (isset($_SESSION['admin']) ? ['admin' => $_SESSION['admin']] : [])
        + ['user_id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']];
    return true;
}
function safe_next_url($url) {
    if (!is_string($url) || $url === '' || strlen($url) > 500 || preg_match('/[\r\n]/', $url)) return '';
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['port'], $parts['fragment'])) return '';
    $path = $parts['path'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/D', $path) || !is_file(__DIR__.'/../'.$path)) return '';
    return $path . (isset($parts['query']) ? '?'.$parts['query'] : '');
}
function login_redirect($next = '') {
    $target = safe_next_url($next);
    header('Location: user_login.php'.($target === '' ? '' : '?next='.rawurlencode($target)), true, 303);
    exit;
}
function current_page_url() {
    $path = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $query = $_SERVER['QUERY_STRING'] ?? '';
    return safe_next_url($path.($query === '' ? '' : '?'.$query));
}
function save_image_upload($file, $subdirectory = '') {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) throw new DomainException('图片上传失败或超过 5MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($extensions[$mime]) || !getimagesize($file['tmp_name'])) throw new DomainException('请上传有效的 JPG、PNG、GIF 或 WEBP 图片');
    $directory = 'uploads/'.($subdirectory !== '' ? $subdirectory.'/' : '');
    if (!is_dir(__DIR__.'/../'.$directory)) mkdir(__DIR__.'/../'.$directory,0755,true);
    $path = $directory.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
    $moved = move_uploaded_file($file['tmp_name'],__DIR__.'/../'.$path);
    // CLI（测试套件）下 $_FILES 为手工构造，move_uploaded_file 必然失败：允许本地文件改名落盘，MIME/尺寸校验已在上方完成
    if (!$moved && PHP_SAPI === 'cli' && is_file($file['tmp_name'])) $moved = @rename($file['tmp_name'], __DIR__.'/../'.$path);
    if (!$moved) throw new DomainException('图片保存失败');
    return $path;
}
