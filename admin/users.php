<?php
require __DIR__.'/inc/auth.php';require_permission('user.view');require __DIR__.'/inc/forms.php';$page_title='用户管理';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 require_permission('user.manage');
 $id=(int)($_POST['id'] ?? 0);
 // 敏感操作（修改用户角色）：执行前需重新输入当前管理员密码
 require_admin_confirm_password('users.php','user',$id);
 admin_transaction(function(){ $id=(int)($_POST['id'] ?? 0);$role=$_POST['role'] ?? '';if(!in_array($role,['user','merchant'],true))throw new DomainException('只允许买家或商户角色');$u=one('SELECT role FROM users WHERE id=? FOR UPDATE',[$id]);if(!$u)throw new DomainException('用户不存在');query('UPDATE users SET role=? WHERE id=?',[$role,$id]);audit_log('user.role.change','user',$id,['old'=>$u['role'],'new'=>$role],'success',true); });header('Location: users.php',true,303);exit;
}
$page=max(1,(int)($_GET['page'] ?? 1));$offset=($page-1)*20;$q=trim($_GET['q'] ?? '');$params=['%'.$q.'%'];$count=(int)one('SELECT COUNT(*) n FROM users WHERE username LIKE ?',$params)['n'];$users=rows('SELECT id,username,role FROM users WHERE username LIKE ? ORDER BY id DESC LIMIT 20 OFFSET '.$offset,$params);
require __DIR__.'/inc/header.php'; ?><h1>用户管理</h1><p>后台管理员在独立的管理员账号页面维护。</p><form><input name="q" value="<?=h($q)?>" placeholder="用户名"><button>搜索</button></form><table class="crud-table"><tr><th>编号</th><th>用户名</th><th>身份</th><th>操作</th></tr><?php foreach($users as $u): ?><tr><td><?=(int)$u['id']?></td><td><?=h($u['username'])?></td><td><?=h($u['role'])?></td><td><?php if(admin_has_permission('user.manage'))echo admin_form('role',$u['id'],'更新身份','<select name="role"><option value="user">买家</option><option value="merchant"'.($u['role']==='merchant'?' selected':'').'>商户</option></select><input type="password" name="admin_confirm_password" required autocomplete="current-password" placeholder="当前管理员密码">'); ?></td></tr><?php endforeach; ?></table><?php admin_pagination($page,$count);require __DIR__.'/inc/footer.php'; ?>
