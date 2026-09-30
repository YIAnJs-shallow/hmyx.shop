<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || trim($argv[1]) === '') {
    fwrite(STDERR, "用法：php scripts/reset_password.php <用户名>\n");
    exit(1);
}

function read_password($prompt) {
    if (DIRECTORY_SEPARATOR === '\\') {
        $command = 'powershell -NoProfile -Command "$p=Read-Host -AsSecureString;[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        fwrite(STDOUT, $prompt);
        $password = shell_exec($command);
        fwrite(STDOUT, PHP_EOL);
        return is_string($password) ? rtrim($password, "\r\n") : '';
    }
    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo');
    $password = fgets(STDIN);
    shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);
    return is_string($password) ? rtrim($password, "\r\n") : '';
}

$username = trim($argv[1]);
$password = read_password('请输入新密码：');
$confirmation = read_password('请再次输入新密码：');
if ($password !== $confirmation || !preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,72}$/', $password)) {
    fwrite(STDERR, "密码重置失败。\n");
    exit(1);
}

require_once __DIR__ . '/../config/db.php';
$stmt = $conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    fwrite(STDERR, "密码重置失败。\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
$stmt->bind_param('si', $hash, $user['id']);
if (!$stmt->execute()) {
    error_log($stmt->error);
    fwrite(STDERR, "密码重置失败。\n");
    exit(1);
}
fwrite(STDOUT, "密码已重置。\n");
