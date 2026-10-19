<?php
// ===== 修改资料（京东"账户设置"式独立子页，2026-10-19 自 user_center.php 整体迁移）=====
// 迁移范围：头像/昵称/手机/邮箱/改密码表单与 POST 处理逻辑，校验口径（CSRF、动态列检测、save_image_upload、
// verify_account_password）保持不变；user_center.php 不再承载表单，仅保留枢纽 Dashboard。
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/lib/uc.php';

// 未登录：Web 跳登录页并带回跳；CLI（测试进程内渲染）输出拒绝提示（与 member.php 门禁口径一致）
if (empty($_SESSION['user_id'])) {
    if (PHP_SAPI !== 'cli') login_redirect(current_page_url());
    uc_page_start('修改个人资料');
    echo '<p class="notice error" role="alert">请先登录后修改个人资料，<a href="user_login.php">去登录</a>。</p>';
    uc_page_end();
    return;
}

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) die("数据库连接失败，请检查 config/db.php");

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'guest';
$user = ['username' => $_SESSION['username'] ?? '', 'phone' => '', 'email' => '', 'avatar' => ''];
$msg = '';
$msg_type = 'success';

// 动态检测数据库表中存在哪些字段（function_exists 守卫：允许本页被测试在函数作用域内重复 include）
if (!function_exists('getTableColumns')) {
function getTableColumns($db) {
    $columns = [];
    if ($db instanceof mysqli) {
        $res = $db->query("SHOW COLUMNS FROM users");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
        }
    } else {
        $stmt = $db->query("SHOW COLUMNS FROM users");
        if ($stmt) {
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                $columns[] = $c['Field'];
            }
        }
    }
    return $columns;
}
}

$user_cols = getTableColumns($db);
$has_phone = in_array('phone', $user_cols);
$has_email = in_array('email', $user_cols);
$has_avatar = in_array('avatar', $user_cols);

// 已登录状态下动态查询用户资料
$select_fields = ['username', 'password'];
if ($has_phone) $select_fields[] = 'phone';
if ($has_email) $select_fields[] = 'email';
if ($has_avatar) $select_fields[] = 'avatar';

$sql = "SELECT " . implode(', ', $select_fields) . " FROM users WHERE id = ?";

if ($db instanceof mysqli) {
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res) $user = array_merge($user, $res);
    }
} else {
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->execute([$user_id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) $user = array_merge($user, $res);
    }
}

// 处理资料修改提交（原 user_center.php 逻辑整体迁移，口径不变）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['username'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $old_pwd = trim($_POST['old_password'] ?? '');
    $new_pwd = trim($_POST['new_password'] ?? '');

    $avatar_path = $user['avatar'];

    // 处理头像上传
    if ($has_avatar && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        try { $avatar_path = save_image_upload($_FILES['avatar'], 'avatars'); }
        catch (DomainException $e) { $msg=$e->getMessage(); $msg_type='error'; }
    }

    // 校验新旧密码
    $final_password = null;
    if ($msg_type !== 'error' && !empty($new_pwd)) {
        if (empty($old_pwd)) {
            $msg = '修改密码时必须输入原密码！';
            $msg_type = 'error';
        } else {
            $db_pwd = $user['password'] ?? '';
            $is_pwd_correct = verify_account_password($old_pwd, $db_pwd);
            if ($is_pwd_correct && strlen($new_pwd) >= 8 && strlen($new_pwd) <= 72) {
                $final_password = password_hash($new_pwd, PASSWORD_DEFAULT);
            } else {
                $msg = '原密码输入不正确！';
                $msg_type = 'error';
            }
        }
    }

    // 动态组装 UPDATE 语句
    if ($msg_type !== 'error') {
        $update_fields = ["username = ?"];
        $bind_params = [$new_name];

        if ($has_phone) { $update_fields[] = "phone = ?"; $bind_params[] = $new_phone; }
        if ($has_email) { $update_fields[] = "email = ?"; $bind_params[] = $new_email; }
        if ($has_avatar) { $update_fields[] = "avatar = ?"; $bind_params[] = $avatar_path; }
        if ($final_password) { $update_fields[] = "password = ?"; $bind_params[] = $final_password; }

        $bind_params[] = $user_id;
        $sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $types = str_repeat('s', count($bind_params) - 1) . 'i';
                $stmt->bind_param($types, ...$bind_params);
                $success = $stmt->execute();
            } else {
                $success = false;
            }
        } else {
            $stmt = $db->prepare($sql);
            $success = $stmt ? $stmt->execute($bind_params) : false;
        }

        if ($success) {
            $_SESSION['username'] = $new_name;
            $user['username'] = $new_name;
            $user['phone'] = $new_phone;
            $user['email'] = $new_email;
            $user['avatar'] = $avatar_path;
            $msg = '资料更新成功！';
            $msg_type = 'success';
        } else {
            $msg = '更新失败，请稍后再试！';
            $msg_type = 'error';
        }
    }
}

uc_page_start('修改个人资料', 'profile');
?>
<?php if (!empty($msg)): ?>
    <div class="alert-msg <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<div class="profile-edit">
    <div class="section-title">修改个人资料</div>
    <form action="profile.php" method="POST" enctype="multipart/form-data"><?php echo csrf_field(); ?>
        <input type="hidden" name="update_profile" value="1">

        <div class="form-row full" style="margin-bottom: 15px;">
            <label>更换个人头像</label>
            <div class="avatar-upload-box">
                <div class="avatar-preview-small">
                    <?php if (!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="预览">
                    <?php else: ?>
                        👤
                    <?php endif; ?>
                </div>
                <input type="file" name="avatar" accept="image/*">
            </div>
        </div>

        <div class="form-grid">
            <div class="form-row">
                <label>用户名称 / 昵称</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>

            <div class="form-row">
                <label>手机号码</label>
                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="添加或修改手机号">
            </div>

            <div class="form-row full">
                <label>电子邮箱</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="如：example@domain.com">
            </div>

            <div class="form-row">
                <label>原密码（修改密码时必填）</label>
                <input type="password" name="old_password" placeholder="若不修改密码请留空">
            </div>

            <div class="form-row">
                <label>设置新密码</label>
                <input type="password" name="new_password" placeholder="输入新密码">
            </div>
        </div>

        <button type="submit" class="btn-save">保存更新资料</button>
    </form>
</div>
<?php uc_page_end(); ?>
