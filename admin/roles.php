<?php
require __DIR__.'/inc/auth.php';require_super_admin();require_permission('role.manage');require __DIR__.'/inc/forms.php';$page_title='角色与权限';
if($_SERVER['REQUEST_METHOD']==='POST'){
 admin_transaction(function(){
 $id=(int)($_POST['id'] ?? 0);$action=$_POST['action'] ?? ''; $r=$id?one('SELECT * FROM admin_roles WHERE id=? FOR UPDATE',[$id]):null;
 if($id && !$r)throw new DomainException('角色不存在');
 if($r && $r['role_code']==='super')throw new DomainException('超级管理员角色不能修改');
 if($action==='delete'){if(!$r || $r['is_system'] || one('SELECT id FROM admin_users WHERE role_id=? LIMIT 1',[$id]))throw new DomainException('内置角色或已分配角色不能删除');query('DELETE FROM admin_roles WHERE id=?',[$id]);}
 elseif(in_array($action,['create','update'],true)){
 $name=trim($_POST['role_name'] ?? '');$code=trim($_POST['role_code'] ?? '');if(!$name || mb_strlen($name)>50)throw new DomainException('角色名称需为1至50字');
 $ids=$_POST['permissions'] ?? [];if(!is_array($ids))throw new DomainException('权限格式错误');$ids=array_unique(array_map('intval',$ids));
 foreach($ids as $pid){$p=one('SELECT permission_code FROM admin_permissions WHERE id=?',[$pid]);if(!$p || in_array($p['permission_code'],['role.manage','admin.manage'],true))throw new DomainException('岗位角色不能授予超级管理员权限');}
 if($action==='create'){if(!preg_match('/^[a-z][a-z0-9_]{2,29}$/D',$code) || $code==='super')throw new DomainException('角色标识需为3至30位小写字母数字或下划线');query('INSERT INTO admin_roles(role_code,role_name) VALUES(?,?)',[$code,$name]);global $conn;$id=(int)$conn->insert_id;}else query('UPDATE admin_roles SET role_name=? WHERE id=?',[$name,$id]);
 query('DELETE FROM admin_role_permissions WHERE role_id=?',[$id]);foreach($ids as $pid)query('INSERT INTO admin_role_permissions(role_id,permission_id) VALUES(?,?)',[$id,$pid]);
 }else throw new DomainException('不支持的操作');
 audit_log('role.'.$action,'role',$id,['permissions'=>$_POST['permissions'] ?? []],'success',true);
 });header('Location: roles.php',true,303);exit;
}
$roles=rows('SELECT * FROM admin_roles ORDER BY id');$permissions=rows("SELECT * FROM admin_permissions WHERE permission_code NOT IN ('role.manage','admin.manage') ORDER BY module,id");require __DIR__.'/inc/header.php'; ?>
<h1>角色与权限</h1><p>管理员账号与角色授权仅由超级管理员维护，修改后在下次请求立即生效。</p>
<?php foreach(array_merge($roles,[['id'=>0,'role_name'=>'','role_code'=>'','is_system'=>0]]) as $r): ?>
<section class="card"><h2><?=h($r['role_name'] ?: '新建岗位角色')?></h2><?php if($r['role_code']==='super'): ?><p>主账号隐式拥有全部权限。</p><?php else:$selected=$r['id']?array_column(rows('SELECT permission_id FROM admin_role_permissions WHERE role_id=?',[(int)$r['id']]),'permission_id'):[]; ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><label>标识 <input name="role_code" value="<?=h($r['role_code'])?>" <?=$r['id']?'readonly':'required'?>></label><label>名称 <input name="role_name" value="<?=h($r['role_name'])?>" maxlength="50" required></label><div class="permission-grid"><?php foreach($permissions as $p): ?><label><input type="checkbox" name="permissions[]" value="<?=(int)$p['id']?>" <?=in_array($p['id'],$selected)?'checked':''?>><?=h($p['description'])?></label><?php endforeach; ?></div><button name="action" value="<?=$r['id']?'update':'create'?>">保存角色</button></form><?php if($r['id'] && !$r['is_system'])echo admin_form('delete',$r['id'],'删除未分配角色');endif; ?></section><?php endforeach;require __DIR__.'/inc/footer.php'; ?>
