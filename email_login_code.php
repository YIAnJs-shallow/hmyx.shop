<?php
// 邮箱验证码登录：获取验证码接口（POST 邮箱 + CSRF）
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';
require_once __DIR__ . '/lib/email_login.php';

header('Content-Type: application/json; charset=utf-8');

function json_exit($success, $message) {
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_exit(false, '请求方式不正确');
}

// CSRF：与页面共用同一 token 机制（JSON 版校验，避免输出 HTML 文本）
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    json_exit(false, '请求已过期或来源无效，请刷新页面重试。');
}

$email = trim($_POST['email'] ?? '');

// 格式无效可直接明确提示（不涉及账号枚举）
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    json_exit(false, '请输入有效的邮箱地址！');
}

email_login_issue($email);

// 无论邮箱是否注册、60 秒内是否重复请求，对外提示完全一致（防枚举/防探测）
json_exit(true, '验证码已发送，请查收邮件（10 分钟内有效，同一邮箱 60 秒内只能获取一次）。');
