<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 一次性替换脚本：emperor 店铺（shops.id=2）6 个数码商品（id 15、17-21）
// 图片由 AI 生成 p_* 替换为网采素材 d_*，仅 UPDATE image_url，不动其他任何字段。
// 幂等：已指向 d_* 则输出已一致并跳过。用法：php scripts/replace_digital_images.php
require __DIR__ . '/../config/db.php';

$shopId = 2;

// products.id => 新图片相对路径
$replacements = [
    15 => 'uploads/products/d_booster_card.png',
    17 => 'uploads/products/d_router.jpg',
    18 => 'uploads/products/d_mouse.jpg',
    19 => 'uploads/products/d_keyboard.jpg',
    20 => 'uploads/products/d_powerbank.jpg',
    21 => 'uploads/products/d_speaker.jpg',
];

echo "数据库：{$db_name}\n";

// 先统一校验 6 个新文件全部存在，任一缺失则中止不提交
foreach ($replacements as $newPath) {
    if (!file_exists(__DIR__ . '/../' . $newPath)) {
        fwrite(STDERR, "中止：素材文件缺失 {$newPath}\n");
        exit(1);
    }
}

$conn->begin_transaction();
try {
    $sel = $conn->prepare('SELECT id, title, image_url FROM products WHERE id=? AND shop_id=?');
    $upd = $conn->prepare('UPDATE products SET image_url=? WHERE id=? AND shop_id=?');
    foreach ($replacements as $pid => $newPath) {
        $sel->bind_param('ii', $pid, $shopId);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        if ($row === null) {
            throw new RuntimeException("products.id={$pid} 不存在或不属于 shop_id=2，终止。");
        }
        echo "旧值留档  id={$row['id']}  {$row['title']}  {$row['image_url']}\n";
        if ($row['image_url'] === $newPath) {
            echo "skip      id={$pid}  {$row['title']}（已指向 {$newPath}）\n";
            continue;
        }
        $upd->bind_param('sii', $newPath, $pid, $shopId);
        $upd->execute();
        if ($upd->affected_rows !== 1) {
            throw new RuntimeException("UPDATE products.id={$pid} 失败，affected_rows=" . $upd->affected_rows);
        }
        echo "update    id={$pid}  {$row['title']}  =>  {$newPath}\n";
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '执行失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}

// 输出替换后清单
echo "---- 替换后清单 ----\n";
$stmt = $conn->prepare('SELECT id, title, image_url FROM products WHERE id IN (15,17,18,19,20,21) AND shop_id=? ORDER BY id');
$stmt->bind_param('i', $shopId);
$stmt->execute();
$res = $stmt->get_result();
while ($x = $res->fetch_assoc()) {
    echo "{$x['id']} | {$x['title']} | {$x['image_url']}\n";
}
