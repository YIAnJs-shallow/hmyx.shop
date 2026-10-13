<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 一次性播种脚本：emperor 店铺（shops.id=2「外星仔加速器」）新增 14 款非数码商品
// （服饰/家居/美妆/运动），全部 shop_id=2、审核通过并上架。
// 幂等：可重复执行不产生重复数据。用法：php scripts/seed_products_emperor2.php
require __DIR__ . '/../config/db.php';

$shopId = 2;

$newProducts = [
    ['云感纯棉白T恤', 9, '39.90', 1000, 'uploads/products/c_tshirt.jpg', '【经典白T】270g 重磅纯棉，领口三针五线不变形，透气亲肤，基础百搭，男女同款，S-XXL 码全。'],
    ['拼色连帽卫衣', 9, '89.00', 600, 'uploads/products/c_hoodie.jpg', '【秋季必备】420g 毛圈面料，加绒加厚可选，连帽抽绳设计，宽松版型不挑人，机洗不起球。'],
    ['法式碎花连衣裙', 10, '129.00', 400, 'uploads/products/c_dress.jpg', '【温柔风】泡泡袖+收腰 A 字版型，显瘦遮肉，雪纺面料垂感好，夏日穿不闷热，聚会度假皆宜。'],
    ['轻弹缓震运动鞋', 11, '159.00', 500, 'uploads/products/c_sneakers.webp', '【百搭配色】EVA 中底缓震回弹，网面透气不闷脚，耐磨橡胶大底，日常通勤慢跑一双搞定。'],
    ['大容量双肩背包', 11, '99.00', 450, 'uploads/products/c_backpack.jpg', '【通勤旅行两用】20L 容量，独立电脑仓可放 15.6 英寸笔记本，防泼水面料，背垫透气减压。'],
    ['NBR 加厚瑜伽垫', 12, '59.00', 350, 'uploads/products/c_yogamat.png', '【居家健身】15mm 加厚 NBR 材质，回弹防滑双面纹理，附赠绑带网袋，瑜伽/平板支撑/仰卧起坐全能垫。'],
    ['纯棉中筒袜 5 双装', 16, '29.90', 1200, 'uploads/products/c_socks.jpg', '【精梳棉】罗口不勒脚，加厚毛圈底吸汗防臭，5 双混色装，四季可穿，机洗不变形。'],
    ['纯棉长袖睡衣套装', 17, '79.00', 400, 'uploads/products/c_pajamas.jpg', '【可外穿家居服】纯棉针织面料柔软透气，圆领宽松剪裁，简约纯色，居家舒适出门得体。'],
    ['弹力直筒牛仔裤', 18, '119.00', 300, 'uploads/products/c_jeans.jpg', '【水洗浅色】加弹丹宁面料，直筒小脚版型修饰腿型，多次锁色工艺不掉色，四季百搭。'],
    ['硬顶棒球帽', 19, '49.90', 500, 'uploads/products/c_cap.jpg', '【百搭单品】优质棉斜纹帽身，硬顶版型挺括，可调节帽围 55-60cm，防晒遮阳凹造型两不误。'],
    ['麦饭石不粘煎锅 26cm', 15, '89.00', 260, 'uploads/products/c_pan.jpg', '【少油不粘】麦饭石涂层，燃气/电磁炉通用，锅体轻巧单手可颠，煎蛋煎牛排不粘不糊，附木质锅铲。'],
    ['全棉磨毛四件套 1.8m', 14, '169.00', 200, 'uploads/products/c_bedsheet.png', '【裸睡级触感】全棉磨毛工艺，亲肤保暖不起静电，活性印染不褪色，含被套/床单/枕套×2。'],
    ['智能显温保温杯 500ml', 15, '69.00', 480, 'uploads/products/c_bottle.jpg', '【一键显温】316 不锈钢内胆，杯盖轻触显示水温，6 小时保温 12 小时保冷，陶瓷涂层不挂垢。'],
    ['烟酰胺水乳护肤套装', 4, '139.00', 300, 'uploads/products/c_skincare.jpg', '【提亮保湿】烟酰胺+玻尿酸配方，水乳双件套，补水锁水改善暗沉，敏感肌友好，早晚各一次28天焕亮。'],
];

echo "数据库：{$db_name}\n";

$conn->begin_transaction();
try {
    $chk = $conn->prepare('SELECT id FROM products WHERE shop_id=? AND title=?');
    $ins = $conn->prepare(
        'INSERT INTO products (shop_id, category_id, title, price, stock, image_url, detail, status, audit_status, flash_price_cents, limit_per_user, flash_start, flash_end, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW())'
    );
    foreach ($newProducts as $p) {
        $chk->bind_param('is', $shopId, $p[0]);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc() !== null) {
            echo "skip  {$p[0]}（已存在）\n";
            continue;
        }
        $status = 1;
        $audit = 'approved';
        $flashZero = 0;
        $ins->bind_param(
            'iisdissisii',
            $shopId, $p[1], $p[0], $p[2], $p[3], $p[4], $p[5], $status, $audit, $flashZero, $flashZero
        );
        $ins->execute();
        if ($ins->affected_rows !== 1) {
            throw new RuntimeException('INSERT 失败：' . $p[0]);
        }
        echo "insert  {$p[0]}\n";
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, '执行失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}

$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM products WHERE shop_id=?');
$stmt->bind_param('i', $shopId);
$stmt->execute();
$cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
echo "shop_id=2 商品总数：{$cnt}\n";
