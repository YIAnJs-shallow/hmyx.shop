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
function csrf_check() {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('请求已过期或来源无效，请刷新页面重试。');
    }
}
function account_password_needs_reset($stored) {
    return !is_string($stored) || !password_get_info($stored)['algo'];
}
function verify_account_password($input, $stored) {
    return is_string($input) && !account_password_needs_reset($stored) && password_verify($input, $stored);
}
function login_account($user, $input) {
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
    if (!move_uploaded_file($file['tmp_name'],__DIR__.'/../'.$path)) throw new DomainException('图片保存失败');
    return $path;
}
