<?php
// ===== 商品风控自动预检：商户提交/编辑商品保存前运行 =====
// 规则全部写死：违禁词 block、价格异常（<=0 block / 低于同类均价 10% warn）、主图内容重复 warn。
// block 拒绝保存；warn 正常保存并把预警原因写入审核日志（admin_audit_logs，action=product.risk.warn），
// 供商品审核中心待审列表展示；none 不影响保存。
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/admin_auth.php';

function risk_check_product(array $product): array {
    $blocks = [];
    $warns = [];

    // a) 违禁词：词库写死，标题或详情命中即 block（mb_stripos 大小写不敏感）
    $banned_words = ['发票', '代开发票', '枪', '弹药', '毒品', '赌博', '假证', '办证', '私服', '外挂', '色情', '仿冒', '高仿', '刷单'];
    $title = (string)($product['title'] ?? '');
    $detail = (string)($product['detail'] ?? '');
    $hits = [];
    foreach ($banned_words as $word) {
        if (mb_stripos($title, $word) !== false || mb_stripos($detail, $word) !== false) $hits[] = $word;
    }
    if ($hits) $blocks[] = '标题或详情含违禁词：' . implode('、', $hits);

    // b) 价格异常：<=0 直接 block；低于同分类在售（status=1 且 approved）商品均价 10% 时 warn（不足 2 个在售商品跳过）
    $price = (float)($product['price'] ?? 0);
    $self_id = (int)($product['id'] ?? 0);
    if ($price <= 0) {
        $blocks[] = '价格须大于零';
    } elseif ((int)($product['category_id'] ?? 0) > 0) {
        $avg = one("SELECT COUNT(*) AS n, AVG(price) AS avg_price FROM products WHERE category_id = ? AND status = 1 AND audit_status = 'approved' AND id <> ?", [(int)$product['category_id'], $self_id]);
        if ($avg && (int)$avg['n'] >= 2 && $price < (float)$avg['avg_price'] * 0.10) {
            $warns[] = '价格显著低于同类均价';
        }
    }

    // c) 重复图片：主图文件内容 md5 与其他商品主图逐一比对，完全同内容则 warn（编辑场景排除自身）
    $image = (string)($product['image_url'] ?? '');
    if ($image !== '' && is_file($image)) {
        $my_hash = md5_file($image);
        foreach (rows("SELECT id, image_url FROM products WHERE image_url <> '' AND id <> ?", [$self_id]) as $other) {
            if ($other['image_url'] !== '' && is_file($other['image_url']) && md5_file($other['image_url']) === $my_hash) {
                $warns[] = '图片与已有商品重复';
                break;
            }
        }
    }

    if ($blocks) return ['risk_level' => 'block', 'reasons' => array_merge($blocks, $warns)];
    if ($warns) return ['risk_level' => 'warn', 'reasons' => $warns];
    return ['risk_level' => 'none', 'reasons' => []];
}

// warn 预警写入审核日志备注（复用 audit_log / admin_audit_logs，无新表），审核中心按 action 读取展示
function risk_log_warn($productId, array $reasons) {
    audit_log('product.risk.warn', 'product', (int)$productId, ['reasons' => $reasons], 'success', true);
}
