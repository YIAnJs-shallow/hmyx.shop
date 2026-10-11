<?php
require __DIR__.'/inc/auth.php';require_super_admin();require_permission('admin.manage');require __DIR__.'/inc/forms.php';$page_title='管理员账号';
if($_SERVER['REQUEST_METHOD']==='POST'){
 admin_transaction(function(){global $conn;$id=(int)($_POST['id'] ?? 0);$action=$_POST['action'] ?? '';
 $a=$id?one('SELECT * FROM admin_users WHERE id=? FOR UPDATE',[$id]):null;
 if($id && (!$a || $a['is_super'] || $id===(int)$_SESSION['admin']['id']))throw new DomainException('不能操作主账号或自己');
 if($action!=='create' && !$a)throw new DomainException('管理员不存在');
 if($action==='create' || $action==='reset'){
 $username=$action==='create'?trim($_POST['username'] ?? ''):$a['username'];$password=$_POST['password'] ?? '';if(!preg_match('/^[A-Za-z0-9_]{3,50}$/D',$username))throw new DomainException('用户名需为3至50位字母数字或下划线');$error=validate_admin_password($password,$username);if($error)throw new DomainException($error);$hash=password_hash($password,PASSWORD_DEFAULT);
 if($action==='create'){$role=(int)($_POST['role_id'] ?? 0);$r=one("SELECT id FROM admin_roles WHERE id=? AND role_code<>'super'",[$role]);if(!$r)throw new DomainException('请选择普通管理员或岗位角色');query('INSERT INTO admin_users(username,password,role_id,must_change_password) VALUES(?,?,?,1)',[$username,$hash,$role]);$id=(int)$conn->insert_id;}else query('UPDATE admin_users SET password=?,must_change_password=1,failed_attempts=0,locked_until=NULL WHERE id=?',[$hash,$id]);
 }elseif($action==='enable' || $action==='disable'){query('UPDATE admin_users SET status=? WHERE id=?',[$action==='enable'?'active':'disabled',$id]);}
 elseif($action==='role'){$role=(int)($_POST['role_id'] ?? 0);if(!one("SELECT id FROM admin_roles WHERE id=? AND role_code<>'super'",[$role]))throw new DomainException('角色不存在');query('UPDATE admin_users SET role_id=? WHERE id=?',[$role,$id]);}
 else throw new DomainException('不支持的操作');audit_log('admin.'.$action,'admin',$id,[],'success',true);
 });header('Location: admins.php',true,303);exit;
}
$roles=rows("SELECT * FROM admin_roles WHERE role_code<>'super' ORDER BY id");$admins=rows('SELECT a.id,a.username,a.role_id,a.is_super,a.status,r.role_name FROM admin_users a JOIN admin_roles r ON r.id=a.role_id ORDER BY a.id');
function role_select($roles,$selected=0){$s='<select name="role_id">';foreach($roles as $r)$s.='<option value="'.(int)$r['id'].'"'.($selected==$r['id']?' selected':'').'>'.h($r['role_name']).'</option>';return $s.'</select>';}
require __DIR__.'/inc/header.php'; ?><h1>管理员账号</h1><p>新建或重置账号后，首次登录必须改密。主账号禁止禁用、降级或删除。</p>
<form method="post" class="card"><?=csrf_field()?><label>新用户名 <input name="username" required maxlength="50" autocomplete="off"></label><label>初始密码 <input type="password" name="password" required minlength="10" autocomplete="new-password"></label><?=role_select($roles)?><button name="action" value="create">创建管理员</button></form>
<?php foreach($admins as $a): ?><section class="card"><h2><?=h($a['username'])?></h2><p><?=h($a['role_name'])?> · <?=h($a['status'])?></p><?php if(!$a['is_super']): ?><?=admin_form($a['status']==='active'?'disable':'enable',$a['id'],$a['status']==='active'?'禁用':'启用')?><?=admin_form('role',$a['id'],'调整岗位',role_select($roles,$a['role_id']))?><?=admin_form('reset',$a['id'],'重置密码','<input type="password" name="password" minlength="10" required autocomplete="new-password" placeholder="新的临时强密码">')?><?php endif; ?></section><?php endforeach;require __DIR__.'/inc/footer.php'; ?>
