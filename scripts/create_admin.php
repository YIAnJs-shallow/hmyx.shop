<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 一次性引导脚本：创建唯一的超级管理员（主账号），首登强制改密
// 用法：php scripts/create_admin.php [--from-user <旧users表admin用户名>]
require_once __DIR__ . '/../lib/shop.php';

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

$from_user = '';
if (($i = array_search('--from-user', $argv)) !== false && isset($argv[$i + 1])) $from_user = trim($argv[$i + 1]);

// 唯一超管检查
if (one('SELECT id FROM admin_users WHERE is_super=1')) {
    fwrite(STDERR, "创建失败：超级管理员已存在（全库唯一），如需更换请先登录后台操作。\n");
    exit(1);
}

if ($from_user !== '') {
    $legacy = one('SELECT username FROM users WHERE username=? AND role=?', [$from_user, 'admin']);
    if (!$legacy) {
        fwrite(STDERR, "创建失败：users 表中不存在 role=admin 的用户 \"{$from_user}\"。\n");
        exit(1);
    }
    $username = $legacy['username'];
    echo "将沿用旧账号用户名 \"{$username}\" 创建超级管理员（不复制旧密码）。\n";
} else {
    echo "请输入超级管理员用户名: ";
    $username = trim(fgets(STDIN));
}
if (!preg_match('/^[A-Za-z0-9_]{3,50}$/D', $username)) {
    fwrite(STDERR, "创建失败：用户名需为 3-50 位字母/数字/下划线。\n");
    exit(1);
}
if (one('SELECT id FROM admin_users WHERE username=?', [$username])) {
    fwrite(STDERR, "创建失败：该用户名已被占用。\n");
    exit(1);
}

echo "请输入密码（本地命令行无法隐藏输入，请在本机执行）: ";
$password = trim(fgets(STDIN));
echo "请再次输入密码: ";
$confirm = trim(fgets(STDIN));
if ($password !== $confirm) { fwrite(STDERR, "创建失败：两次输入不一致。\n"); exit(1); }
if (($error = validate_admin_password($password, $username)) !== '') { fwrite(STDERR, "创建失败：{$error}。\n"); exit(1); }

$super_role = one("SELECT id FROM admin_roles WHERE role_code='super'");
if (!$super_role) { fwrite(STDERR, "创建失败：请先运行 php scripts/migrate.php 初始化角色表。\n"); exit(1); }

$hash = password_hash($password, PASSWORD_DEFAULT);
query('INSERT INTO admin_users (username,password,role_id,is_super,must_change_password) VALUES (?,?,?,1,1)',
    [$username, $hash, (int)$super_role['id']]);

echo "超级管理员创建成功。\n";
echo "请访问 admin/login.php 登录，首次登录需修改密码。\n";
if ($from_user !== '') {
    echo "提示：旧 users 表中该账号的 admin 特权已不再生效，可执行以下 SQL 降为普通用户：\n";
    echo "  UPDATE users SET role='user' WHERE username='" . addslashes($username) . "';\n";
}
