<?php
require __DIR__.'/inc/auth.php'; require_permission('product.view'); require __DIR__.'/inc/forms.php';
$page_title='商品管理';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_permission('product.manage');
    admin_transaction(function() {
        $id=(int)($_POST['id'] ?? 0); $action=$_POST['action'] ?? '';
        if ($action==='create') {
            $title=trim($_POST['title'] ?? '');$price=$_POST['price'] ?? '';
            $stock=filter_var($_POST['stock'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000000]]);
            if(!$title || mb_strlen($title)>100 || cents($price)<=0 || $stock===false)throw new DomainException('请检查名称、价格和库存');
            query("INSERT INTO products(title,price,stock,status,audit_status) VALUES(?,?,?,0,'approved')",[$title,money(cents($price)),$stock]);
            global $conn;$id=(int)$conn->insert_id;
            audit_log('product.create','product',$id,['title'=>$title],'success',true);return;
        }
        $p=one('SELECT * FROM products WHERE id=? FOR UPDATE',[$id]);
        if (!$p) throw new DomainException('商品不存在');
        if ($action==='on' || $action==='off') {
            if ($action==='on' && $p['audit_status']!=='approved') throw new DomainException('仅审核通过的商品可上架');
            query('UPDATE products SET status=? WHERE id=?',[$action==='on'?1:0,$id]);
        } elseif ($action==='update') {
            $title=trim($_POST['title'] ?? ''); $price=$_POST['price'] ?? ''; $stock=filter_var($_POST['stock'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000000]]);
            if (!$title || mb_strlen($title)>100 || cents($price)<=0 || $stock===false) throw new DomainException('请检查名称、价格和库存');
            if ((string)$p['stock']!==(string)($_POST['expected_stock'] ?? '')) throw new DomainException('库存已发生变化，请刷新后保存');
            query('UPDATE products SET title=?,price=?,stock=? WHERE id=?',[$title,money(cents($price)),$stock,$id]);
        } else throw new DomainException('不支持的操作');
        audit_log('product.'.$action,'product',$id,['old_title'=>$p['title'],'old_stock'=>$p['stock']], 'success',true);
    }); header('Location: products.php',true,303); exit;
}
$page=max(1,(int)($_GET['page'] ?? 1));$offset=($page-1)*20;$q=trim($_GET['q'] ?? '');
$params=['%'.$q.'%'];$count=(int)one('SELECT COUNT(*) n FROM products WHERE title LIKE ?',$params)['n'];
$products=rows('SELECT * FROM products WHERE title LIKE ? ORDER BY id DESC LIMIT 20 OFFSET '.$offset,$params);
require __DIR__.'/inc/header.php'; ?>
<h1>商品管理</h1><form><input name="q" value="<?=h($q)?>" placeholder="搜索商品"><button>搜索</button></form>
<?php if(admin_has_permission('product.manage')): ?><form method="post" class="card"><?=csrf_field()?><label>新商品名称 <input name="title" maxlength="100" required></label><label>价格 <input name="price" required></label><label>库存 <input type="number" name="stock" min="0" required></label><button name="action" value="create">创建平台商品（下架）</button></form><?php endif; ?>
<?php foreach($products as $p): ?><section class="card"><h2><?=h($p['title'])?></h2><p>编号 <?=(int)$p['id']?> · ¥ <?=h($p['price'])?> · 库存 <?=(int)$p['stock']?> · <?=h($p['audit_status'])?> · <?=(int)$p['status']?'上架':'下架'?></p>
<?php if(admin_has_permission('product.manage')): ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$p['id']?>"><input type="hidden" name="expected_stock" value="<?=(int)$p['stock']?>"><label>名称 <input name="title" maxlength="100" value="<?=h($p['title'])?>" required></label><label>价格 <input name="price" value="<?=h($p['price'])?>" required></label><label>库存 <input name="stock" type="number" min="0" value="<?=(int)$p['stock']?>" required></label><button name="action" value="update">保存</button></form>
<?=admin_form('off',$p['id'],'下架')?><?php if($p['audit_status']==='approved'): ?><?=admin_form('on',$p['id'],'上架')?><?php endif; endif; ?></section><?php endforeach; ?>
<?php admin_pagination($page,$count);require __DIR__.'/inc/footer.php'; ?>
