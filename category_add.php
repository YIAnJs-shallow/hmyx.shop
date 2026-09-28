<?php
require_once 'admin_header.php';
require_once 'config/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name']);
    $parent_id = intval($_POST['parent_id']);

    try {
        if ($category_name === '' || mb_strlen($category_name) > 50) throw new DomainException('分类名称需为 1 至 50 个字符');
        if ($parent_id) {
            $parent = $conn->prepare('SELECT id FROM categories WHERE id=? AND parent_id=0');
            $parent->bind_param('i', $parent_id); $parent->execute();
            if (!$parent->get_result()->fetch_assoc()) throw new DomainException('请选择有效的一级分类');
        }
        $stmt = $conn->prepare('INSERT INTO categories(category_name,parent_id) VALUES(?,?)');
        $stmt->bind_param('si', $category_name, $parent_id); $stmt->execute();
        $msg='分类已添加';
    } catch (DomainException $e) { $msg=$e->getMessage(); }
    catch (mysqli_sql_exception $e) { error_log($e->getMessage()); $msg=$e->getCode()===1062?'分类名称已存在':'添加失败，请稍后再试'; }
}

// 查询现有的父级大类
$main_cats = $conn->query("SELECT id, category_name FROM categories WHERE parent_id = 0");
?>

<div style="max-width: 500px; margin: 40px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
    <h3 style="margin-top: 0;">➕ 添加新分类</h3>
    <?php if ($msg): ?>
        <p style="color: green; font-weight: bold;"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p >
    <?php endif; ?>
    
    <form method="POST"><?php echo csrf_field(); ?>
        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom: 5px; font-weight: bold;">所属大类：</label>
            <select name="parent_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required>
                <option value="0">-- 新建独立大类 --</option>
                <?php while($row = $main_cats->fetch_assoc()): ?>
                    <option value="<?php echo $row['id']; ?>">【大类】<?php echo htmlspecialchars($row['category_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom: 5px; font-weight: bold;">分类名称：</label>
            <input type="text" name="category_name" placeholder="例如：袜子/袜品" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" required>
        </div>

        <button type="submit" style="padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">保存分类</button>
    </form>
</div>