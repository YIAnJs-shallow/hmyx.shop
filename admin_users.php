<?php
require_once 'admin_header.php';
require_once 'config/db.php';

// 处理修改用户角色请求
$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['new_role'])) {
    $user_id = intval($_POST['user_id']);
    $new_role = $_POST['new_role'];
    
    if (in_array($new_role, ['user', 'merchant', 'admin'])) {
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $new_role, $user_id);
        if ($stmt->execute()) {
            $msg = "用户角色已更新！";
            $msg_type = "success";
        } else {
            $msg = "更新失败：" . $conn->error;
            $msg_type = "error";
        }
    } else {
        $msg = "无效的角色类型";
        $msg_type = "error";
    }
}

// 搜索与筛选
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$role_filter = isset($_GET['role']) ? trim($_GET['role']) : '';

$where = " WHERE 1=1";
$params = [];
$types = "";

if (!empty($keyword)) {
    $where .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $keyword_param = "%" . $keyword . "%";
    $params[] = $keyword_param;
    $params[] = $keyword_param;
    $params[] = $keyword_param;
    $types .= "sss";
}

if (!empty($role_filter) && in_array($role_filter, ['user', 'merchant', 'admin'])) {
    $where .= " AND u.role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

// 查询用户列表（关联店铺信息）
$sql = "SELECT u.*, s.shop_name, s.contact_phone, s.status AS shop_status
        FROM users u
        LEFT JOIN shops s ON u.id = s.user_id
        {$where}
        ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users_result = $stmt->get_result();

// 统计各角色用户数
$role_counts = ['user' => 0, 'merchant' => 0, 'admin' => 0];
$res = $conn->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
while ($row = $res->fetch_assoc()) {
    if (isset($role_counts[$row['role']])) {
        $role_counts[$row['role']] = intval($row['cnt']);
    }
}
?>

<div style="max-width: 1100px; margin: 30px auto; padding: 0 20px;">

    <!-- 操作结果提示 -->
    <?php if (!empty($msg)): ?>
        <div style="padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; 
                    background: <?php echo $msg_type === 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
                    color: <?php echo $msg_type === 'success' ? '#166534' : '#991b1b'; ?>;">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- 顶部标题 -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; color: #1e293b;">用户管理</h2>
        <div style="display: flex; gap: 15px;">
            <span style="padding: 6px 12px; background: #f1f5f9; border-radius: 6px; font-size: 13px; color: #64748b;">
                普通用户: <strong style="color: #1e293b;"><?php echo $role_counts['user']; ?></strong>
            </span>
            <span style="padding: 6px 12px; background: #f1f5f9; border-radius: 6px; font-size: 13px; color: #64748b;">
                商家: <strong style="color: #1e293b;"><?php echo $role_counts['merchant']; ?></strong>
            </span>
            <span style="padding: 6px 12px; background: #f1f5f9; border-radius: 6px; font-size: 13px; color: #64748b;">
                管理员: <strong style="color: #1e293b;"><?php echo $role_counts['admin']; ?></strong>
            </span>
        </div>
    </div>

    <!-- 搜索筛选栏 -->
    <form method="GET" action="admin_users.php" style="display: flex; gap: 12px; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <input type="text" name="keyword" placeholder="搜索用户名、邮箱、手机号..." value="<?php echo htmlspecialchars($keyword); ?>" style="flex: 1; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
        
        <select name="role" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
            <option value="">全部角色</option>
            <option value="user" <?php echo $role_filter === 'user' ? 'selected' : ''; ?>>普通用户</option>
            <option value="merchant" <?php echo $role_filter === 'merchant' ? 'selected' : ''; ?>>商家</option>
            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>管理员</option>
        </select>

        <button type="submit" style="background: #0f172a; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; cursor: pointer;">搜索</button>
    </form>

    <!-- 用户数据表格 -->
    <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 0.9rem;">
                    <th style="padding: 12px 16px;">ID</th>
                    <th style="padding: 12px 16px;">用户名</th>
                    <th style="padding: 12px 16px;">邮箱/手机</th>
                    <th style="padding: 12px 16px;">角色</th>
                    <th style="padding: 12px 16px;">店铺信息</th>
                    <th style="padding: 12px 16px;">注册时间</th>
                    <th style="padding: 12px 16px; text-align: center;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_result && $users_result->num_rows > 0): ?>
                    <?php while ($u = $users_result->fetch_assoc()): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 16px; color: #64748b;"><?php echo $u['id']; ?></td>
                            <td style="padding: 12px 16px; font-weight: bold; color: #1e293b;">
                                <?php echo htmlspecialchars($u['username']); ?>
                            </td>
                            <td style="padding: 12px 16px; color: #64748b; font-size: 0.9rem;">
                                <?php if (!empty($u['email'])): ?>
                                    <div><?php echo htmlspecialchars($u['email']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($u['phone'])): ?>
                                    <div style="color: #94a3b8; font-size: 0.85rem;"><?php echo htmlspecialchars($u['phone']); ?></div>
                                <?php endif; ?>
                                <?php if (empty($u['email']) && empty($u['phone'])): ?>
                                    <span style="color: #cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px;">
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span style="background: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">管理员</span>
                                <?php elseif ($u['role'] === 'merchant'): ?>
                                    <span style="background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">商家</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">用户</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px;">
                                <?php if ($u['role'] === 'merchant' && !empty($u['shop_name'])): ?>
                                    <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($u['shop_name']); ?></div>
                                    <?php if (!empty($u['contact_phone'])): ?>
                                        <div style="color: #94a3b8; font-size: 0.85rem;"><?php echo htmlspecialchars($u['contact_phone']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; color: #94a3b8; font-size: 0.85rem;">
                                <?php echo htmlspecialchars(substr($u['created_at'], 0, 10)); ?>
                            </td>
                            <td style="padding: 12px 16px; text-align: center;">
                                <form method="POST" action="admin_users.php" style="display: inline-block; margin: 0;"><?php echo csrf_field(); ?>
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <select name="new_role" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.85rem;">
                                        <option value="user" <?php echo $u['role'] === 'user' ? 'selected' : ''; ?>>用户</option>
                                        <option value="merchant" <?php echo $u['role'] === 'merchant' ? 'selected' : ''; ?>>商家</option>
                                        <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>管理员</option>
                                    </select>
                                    <button type="submit" style="margin-left: 6px; padding: 4px 10px; background: #2563eb; color: #fff; border: none; border-radius: 4px; font-size: 0.85rem; cursor: pointer;">更新</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">暂无用户数据</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
