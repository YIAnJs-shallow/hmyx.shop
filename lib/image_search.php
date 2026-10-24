<?php
// 拍照搜同款：上传图片 → 火山方舟视觉模型（OpenAI 兼容接口）生成电商搜索关键词 → 复用现有 LIKE 搜索。
// 密钥常量 ARK_API_KEY 来自 Web 根目录外 private/my_project/secrets.php（模板见 config/secrets.example.php），禁止硬编码入库。
// 密钥缺失或调用失败时优雅降级：image_search_keywords 返回 null，页面提示"图片搜索暂不可用"，不影响正常搜索。
require_once __DIR__ . '/../config/db.php';
if (!defined('ARK_API_KEY')) {
    $arkSecretsPath = project_private_config_dir() . '/secrets.php';
    if (is_file($arkSecretsPath)) require_once $arkSecretsPath;
}

// 2026-10-24 实测切换：账号 ARK Key 为 Coding Plan 套餐 Key，仅 /api/coding/v3 端点可用（/api/v3 全部 ModelNotOpen，拍照搜同款因此一直静默降级）。
// 套餐模型名不带日期版本号（doubao-seed-2.1-lite），含在订阅额度内不单独计费。视觉传图已实测可用（2026-10-24，data:image/png base64）。
define('ARK_API_URL', 'https://ark.cn-beijing.volces.com/api/coding/v3/chat/completions');
define('ARK_MODEL', 'doubao-seed-2.1-lite');

/**
 * 调方舟视觉模型生成商品关键词。
 * 返回：清洗后的关键词数组（0~8 个）；模型无法识别返回 []；未配置密钥或调用失败返回 null（功能降级）。
 * 测试注入口：IMAGE_SEARCH_OVERRIDE 环境变量注入模型响应文本（同 ALIPAY_GATEWAY_OVERRIDE 惯例），单测不真调外网。
 */
function image_search_keywords($imageDataUrl, $apiKey = null) {
    $override = getenv('IMAGE_SEARCH_OVERRIDE');
    if (is_string($override) && $override !== '') return image_search_sanitize($override);
    if ($apiKey === null) $apiKey = defined('ARK_API_KEY') ? ARK_API_KEY : '';
    if (!is_string($apiKey) || trim($apiKey) === '') return null;
    if (!is_string($imageDataUrl) || strpos($imageDataUrl, 'data:image/') !== 0 || strlen($imageDataUrl) > 8 * 1024 * 1024) return null;
    $body = json_encode([
        'model' => ARK_MODEL,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
                ['type' => 'text', 'text' => '输出 3~8 个适合在电商站搜索该商品的中文关键词，JSON 数组，不要其他内容'],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init(ARK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        error_log('image_search curl: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || $content === '') {
        error_log('image_search: unexpected ark response: ' . substr((string)$resp, 0, 300));
        return null;
    }
    return image_search_sanitize($content);
}

// 响应防御解析：提取 JSON 数组 → 仅保留字符串 → 过滤非法字符（仅留中文/字母/数字/空格）→ 去重 → 每词截 12 字 → 最多 8 词
function image_search_sanitize($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) && preg_match('/\[.*\]/s', $raw, $m)) {
        $decoded = json_decode($m[0], true); // 容错：模型可能把数组包进说明文字
    }
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $item) {
        if (!is_string($item)) continue;
        // 用明确码位区间（CJK 基本区）而非 \p{Han}：新 PCRE2 已把【】等括号归入 Han 文字属性，跨版本行为会漂移
        $word = preg_replace('/[^\x{4E00}-\x{9FFF}A-Za-z0-9 ]+/u', '', trim($item));
        $word = trim(preg_replace('/\s+/u', ' ', $word));
        if ($word === '' || in_array($word, $out, true)) continue;
        $out[] = mb_substr($word, 0, 12);
        if (count($out) >= 8) break;
    }
    return $out;
}

// 关键词逐词 LIKE 检索：可见口径/匹配列/排序与 index.php 搜索一致，每词最多 $limit 件，按词分组返回（供结果页按词渲染与跳转全量搜索）
function image_search_results(array $keywords, $limit = 12) {
    $out = [];
    foreach ($keywords as $kw) {
        $kw = trim((string)$kw);
        if ($kw === '') continue;
        $like = '%' . addcslashes($kw, "%_\\") . '%';
        $out[$kw] = rows("SELECT p.id, p.title, p.price, p.image_url, s.shop_name
            FROM products p
            LEFT JOIN shops s ON s.id = p.shop_id
            WHERE p.status = 1 AND p.audit_status = 'approved'
              AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops active_shop WHERE active_shop.id = p.shop_id AND active_shop.status = 1))
              AND (p.title LIKE ? OR p.detail LIKE ?)
            ORDER BY p.id DESC LIMIT " . (int)$limit, [$like, $like]);
    }
    return $out;
}
