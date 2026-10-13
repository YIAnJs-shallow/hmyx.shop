<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 数据真实化脚本：emperor 店铺（shops.id=2）商品品牌/材质按 CNPP 2026 品牌榜与
// 国标 GB/T 面料命名重定（id 18-35），id 18-21 同步改真实品牌 title 与 detail 首句，
// id 15/17 虚拟服务保持不变，另新增华为 FreeBuds SE 2 真无线蓝牙耳机（查重跳过）。
// 幂等：值已一致则输出 skip。用法：php scripts/fix_brand_material_real.php
require __DIR__ . '/../config/db.php';

$shopId = 2;

// products.id => [新 title(仅 18-21 有), 新 brand, 新 material]
// title 为 null 表示不改标题；detail 首句替换见 $detailHeads
$updates = [
    18 => ['罗技 G304 LIGHTSPEED 无线电竞鼠标', '罗技', 'ABS'],
    19 => ['达尔优 EK815 87键机械键盘', '达尔优', 'PBT键帽'],
    20 => ['小米移动电源3 20000mAh', '小米', '铝合金'],
    21 => ['漫步者 M120 便携蓝牙音箱', '漫步者', 'ABS+织物网罩'],
    22 => [null, '优衣库', '纯棉'],
    23 => [null, '森马', '棉混纺'],
    24 => [null, '伊芙丽', '聚酯纤维'],
    25 => [null, '安踏', '织物+EVA中底'],
    26 => [null, '卡拉羊', '锦纶'],
    27 => [null, '李宁', 'NBR'],
    28 => [null, '浪莎', '精梳棉'],
    29 => [null, '浪莎', '纯棉'],
    30 => [null, '森马', '棉+氨纶'],
    31 => [null, '李宁', '棉涤混纺'],
    32 => [null, '苏泊尔', '铝合金+麦饭石涂层'],
    33 => [null, '水星家纺', '全棉'],
    34 => [null, '富光', '316不锈钢'],
    35 => [null, '自然堂', ''],
];

// id => detail 前缀（新品牌型号开头拼在原首句前，原句其余部分保留）
$detailHeads = [
    18 => '罗技 G304 LIGHTSPEED 无线电竞鼠标，',
    19 => '达尔优 EK815 87键机械键盘，',
    20 => '小米移动电源3 20000mAh，',
    21 => '漫步者 M120 便携蓝牙音箱，',
];

// 新商品：华为 FreeBuds SE 2 真无线蓝牙耳机
$newProduct = [
    'title' => '华为 FreeBuds SE 2 真无线蓝牙耳机',
    'category_id' => 8,
    'price' => 199.00,
    'stock' => 400,
    'image_url' => 'uploads/products/d_earbuds.jpg',
    'brand' => '华为',
    'material' => 'ABS+液态硅胶',
    'detail' => '【真无线蓝牙】华为 FreeBuds SE 2，半入耳佩戴轻盈无感，蓝牙 5.3 低延迟连接，单次续航 9 小时配合充电盒 28 小时，通话降噪，双设备智慧连接。',
];

echo "数据库：{$db_name}\n";

// 校验新商品图片已就位
if (!file_exists(__DIR__ . '/../' . $newProduct['image_url'])) {
    fwrite(STDERR, "中止：素材文件缺失 {$newProduct['image_url']}\n");
    exit(1);
}

$conn->begin_transaction();
try {
    $sel = $conn->prepare('SELECT id, title, brand, material, detail FROM products WHERE id=? AND shop_id=?');
    $updT = $conn->prepare('UPDATE products SET title=?, brand=?, material=? WHERE id=? AND shop_id=?');
    $updB = $conn->prepare('UPDATE products SET brand=?, material=? WHERE id=? AND shop_id=?');
    $updD = $conn->prepare('UPDATE products SET detail=? WHERE id=? AND shop_id=?');
    foreach ($updates as $pid => $spec) {
        list($newTitle, $newBrand, $newMaterial) = $spec;
        $sel->bind_param('ii', $pid, $shopId);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        if ($row === null) {
            throw new RuntimeException("products.id={$pid} 不存在或不属于 shop_id=2，终止。");
        }
        $same = ($newTitle === null || $row['title'] === $newTitle)
            && $row['brand'] === $newBrand && $row['material'] === $newMaterial;
        if (isset($detailHeads[$pid]) && strpos($row['detail'], $detailHeads[$pid]) !== 0) $same = false;
        // 计算 detail 新值：前缀 + 原 detail（首句开头对齐新 title 品牌）
        if ($same) {
            echo "skip  id={$pid}  {$row['title']}（已一致）\n";
            continue;
        }
        if ($newTitle !== null) {
            $updT->bind_param('sssii', $newTitle, $newBrand, $newMaterial, $pid, $shopId);
            $updT->execute();
        } else {
            $updB->bind_param('ssii', $newBrand, $newMaterial, $pid, $shopId);
            $updB->execute();
        }
        echo "update id={$pid}  {$row['title']} => ";
        if (isset($detailHeads[$pid])) {
            $newDetail = $detailHeads[$pid] . $row['detail'];
            $updD->bind_param('sii', $newDetail, $pid, $shopId);
            $updD->execute();
        }
        echo ($newTitle ?? $row['title']), "  brand={$newBrand}  material={$newMaterial}\n";
    }

    // 新增华为 FreeBuds SE 2（按 title 查重跳过）
    $chk = $conn->prepare('SELECT id FROM products WHERE shop_id=? AND title=?');
    $chk->bind_param('is', $shopId, $newProduct['title']);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc() !== null) {
        echo "skip  INSERT {$newProduct['title']}（已存在）\n";
    } else {
        $ins = $conn->prepare(
            'INSERT INTO products (shop_id, category_id, title, price, stock, image_url, detail, status, audit_status, flash_price_cents, flash_start, flash_end, limit_per_user, brand, material, created_at)
             VALUES (?,?,?,?,?,?,?,1,\'approved\',0,NULL,NULL,0,?,?,NOW())'
        );
        $ins->bind_param('iisdsssss', $shopId, $newProduct['category_id'], $newProduct['title'], $newProduct['price'], $newProduct['stock'], $newProduct['image_url'], $newProduct['detail'], $newProduct['brand'], $newProduct['material']);
        $ins->execute();
        echo "insert id={$conn->insert_id}  {$newProduct['title']}  brand={$newProduct['brand']}  material={$newProduct['material']}\n";
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '执行失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}

// 输出店铺商品清单
$res = $conn->query('SELECT id, title, brand, material FROM products WHERE shop_id=' . (int)$shopId . ' ORDER BY id');
echo "\n--- 店铺 {$shopId} 商品清单 ---\n";
while ($row = $res->fetch_assoc()) {
    echo $row['id'], '|', $row['title'], '|', $row['brand'], '|', $row['material'], "\n";
}
echo '总数：', $res->num_rows, "（应为 21）\n";
