<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 临时验证脚本：检查 RBAC 迁移结果（步骤1验收用）
require_once __DIR__ . '/../lib/shop.php';
$out = [];
$out[] = 'roles=' . count(rows('SELECT id FROM admin_roles ORDER BY id'));
$out[] = 'permissions=' . count(rows('SELECT id FROM admin_permissions ORDER BY id'));
$out[] = 'role_permissions=' . (int)one('SELECT COUNT(*) c FROM admin_role_permissions')['c'];
foreach (rows('SELECT r.role_code, COUNT(rp.permission_id) perms FROM admin_roles r LEFT JOIN admin_role_permissions rp ON rp.role_id=r.id GROUP BY r.role_code ORDER BY r.id') as $r) {
    $out[] = '  ' . $r['role_code'] . '=>' . $r['perms'];
}
$out[] = 'admin_users=' . (int)one('SELECT COUNT(*) c FROM admin_users')['c'];
$out[] = 'audit_logs=' . (int)one('SELECT COUNT(*) c FROM admin_audit_logs')['c'];
$out[] = 'login_attempts=' . (int)one('SELECT COUNT(*) c FROM admin_login_attempts')['c'];
$idx = one("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_users' AND INDEX_NAME='uk_only_one_super'");
$out[] = 'unique_super_index=' . ($idx ? 'yes' : 'no');
// 唯一超管约束实测
try {
    query('INSERT INTO admin_users (username,password,role_id,is_super) VALUES (?,?,?,1)', ['__dup_super_test', 'x', 1]);
    $out[] = 'duplicate_super_blocked=no (PROBLEM!)';
    query('DELETE FROM admin_users WHERE username=?', ['__dup_super_test']);
} catch (Exception $e) {
    $out[] = 'duplicate_super_blocked=yes';
}
file_put_contents(__DIR__ . '/rbac_check_result.txt', implode("\n", $out) . "\n");
exit(0);
