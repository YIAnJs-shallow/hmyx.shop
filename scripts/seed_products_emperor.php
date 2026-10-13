<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 一次性播种脚本：emperor 店铺（shops.id=2「外星仔加速器」）商品数据
// 补全 products.id=15 的图片与描述 + 新增 5 个商品（均挂 shop_id=2）
// 幂等：可重复执行不产生重复数据。用法：php scripts/seed_products_emperor.php
require __DIR__ . '/../config/db.php';

$shopId = 2;

// 待新增商品（价格/库存/分类/图片/描述）
$newProducts = [
    [
        'title' => '星际加速器 Pro · 季卡',
        'category_id' => 20,
        'price' => '259.00',
        'stock' => 500,
        'image_url' => 'uploads/products/p_router.jpg',
        'detail' => '【旗舰季卡】Pro 版专属线路，独立带宽保障，支持 5 台设备同时在线。智能选路引擎每 30 秒动态切换最优节点，晚高峰依然稳如平地。有效期 90 天，含专属客服通道。',
    ],
    [
        'title' => '幻影无线电竞鼠标',
        'category_id' => 20,
        'price' => '129.00',
        'stock' => 300,
        'image_url' => 'uploads/products/p_mouse.jpg',
        'detail' => '26000 DPI 光学传感器，58g 轻量化人体工学设计，2.4G 无线低延迟连接。RGB 灯效可自定义，一节电池续航 120 小时，电竞办公两相宜。',
    ],
    [
        'title' => '星轨 87 键机械键盘',
        'category_id' => 20,
        'price' => '199.00',
        'stock' => 200,
        'image_url' => 'uploads/products/p_keyboard.jpg',
        'detail' => '87 键紧凑布局，热插拔轴座，原厂线性轴顺滑回弹。双色注塑键帽不褪色，全键无冲，蓝紫混光随音律律动，附赠拔键器与备用轴体。',
    ],
    [
        'title' => '闪极 20000mAh 移动电源',
        'category_id' => 21,
        'price' => '149.00',
        'stock' => 800,
        'image_url' => 'uploads/products/p_powerbank.jpg',
        'detail' => '20000mAh 大容量，22.5W 双向快充，Type-C/USB-A 双输出。航空级金属外壳，环形电量指示灯，可带上飞机，出行无忧。',
    ],
    [
        'title' => '回音便携蓝牙音箱',
        'category_id' => 22,
        'price' => '99.00',
        'stock' => 600,
        'image_url' => 'uploads/products/p_speaker.jpg',
        'detail' => '360° 环绕声场，双被动振膜加强低音。IPX7 防水，蓝牙 5.3 连接，续航 24 小时，露营聚会随身携带。',
    ],
];

echo "数据库：{$db_name}\n";

$conn->begin_transaction();
try {
    // 1) 补全商品 id=15（仅当 image_url 为空时）
    $stmt = $conn->prepare('SELECT id, title, image_url FROM products WHERE id=? AND shop_id=?');
    $pid15 = 15;
    $stmt->bind_param('ii', $pid15, $shopId);
    $stmt->execute();
    $row15 = $stmt->get_result()->fetch_assoc();

    if ($row15 === null) {
        throw new RuntimeException('products.id=15 不存在或不属于 shop_id=2，终止。');
    }
    if ($row15['image_url'] !== '' && $row15['image_url'] !== null) {
        echo "skip  {$row15['title']}（id=15 图片已存在，不覆盖）\n";
    } else {
        $image15 = 'uploads/products/p_booster_card.jpg';
        $detail15 = '【星际加速 · 月卡】外星仔加速器基础月卡，全球 32 个加速节点智能调度，全协议游戏一键加速。低至 8ms 延迟，丢包率 <0.1%。购买后卡密即时到账，兑换即用，有效期 30 天。';
        $upd = $conn->prepare('UPDATE products SET image_url=?, detail=? WHERE id=? AND shop_id=?');
        $upd->bind_param('ssii', $image15, $detail15, $pid15, $shopId);
        $upd->execute();
        if ($upd->affected_rows !== 1) {
            throw new RuntimeException('UPDATE products.id=15 失败，affected_rows=' . $upd->affected_rows);
        }
        echo "update  {$row15['title']}\n";
    }

    // 2) 新增 5 个商品（按 shop_id=2 AND title 查重）
    $chk = $conn->prepare('SELECT id FROM products WHERE shop_id=? AND title=?');
    $ins = $conn->prepare(
        'INSERT INTO products (shop_id, category_id, title, price, stock, image_url, detail, status, audit_status, flash_price_cents, limit_per_user, flash_start, flash_end, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW())'
    );
    foreach ($newProducts as $p) {
        $chk->bind_param('is', $shopId, $p['title']);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc() !== null) {
            echo "skip  {$p['title']}（已存在）\n";
            continue;
        }
        $status = 1;
        $audit = 'approved';
        $flashZero = 0;
        $ins->bind_param(
            'iisdissisii',
            $shopId, $p['category_id'], $p['title'], $p['price'], $p['stock'],
            $p['image_url'], $p['detail'], $status, $audit, $flashZero, $flashZero
        );
        $ins->execute();
        if ($ins->affected_rows !== 1) {
            throw new RuntimeException('INSERT 失败：' . $p['title']);
        }
        echo "insert  {$p['title']}\n";
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '执行失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}

// 3) 统计 shop_id=2 商品总数
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM products WHERE shop_id=?');
$stmt->bind_param('i', $shopId);
$stmt->execute();
$cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
echo "shop_id=2 商品总数：{$cnt}\n";
