<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 一次性脚本：将仍为明文弱口令的账号重置为随机强密码（bcrypt 哈希存储）
require_once __DIR__ . '/../config/db.php';
$targets = ['admin', 'costomer'];
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@$!%*?&';
foreach ($targets as $u) {
    $stmt = $conn->prepare('SELECT id, password FROM users WHERE username=? LIMIT 1');
    $stmt->bind_param('s', $u);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { echo "$u: 不存在，跳过\n"; continue; }
    // 仅在密码仍是明文（非 $2y$ 哈希）时重置
    if (strpos($row['password'], '$2y$') === 0) { echo "$u: 已是哈希，跳过\n"; continue; }
    $pw = '';
    for ($i = 0; $i < 14; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $upd = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $upd->bind_param('si', $hash, $row['id']);
    $upd->execute();
    echo "$u: 已重置，新密码: $pw\n";
}
