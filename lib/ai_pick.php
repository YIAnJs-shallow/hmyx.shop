<?php
// AI 智能导购（两段式召回，2026-10-24 第一阶段调研定稿：两段式召回 + 结构化输出 + id 白名单校验 + 非流式）：
// 用户自然语言需求 → ① 方舟模型抽取结构化检索条件（关键词/分类/价格带）→ ② 复用 index.php 既有搜索口径查库取候选
// → ③ 候选集注入让模型只从候选 id 中挑选并给出一句理由 → ④ 后端硬校验：越界 id 一律丢弃，商品卡片数据按幸存 id
// 重查库渲染（LLM 文本只出理由，不接触商品详情输出）——防幻觉靠机制而非提示词。
// 方舟接入蓝本同 lib/image_search.php（Coding Plan 端点 /api/coding/v3，Bearer 认证）；密钥缺失/超时/网络失败/解析失败一律返回 null 优雅降级。
// 测试注入点（同 IMAGE_SEARCH_OVERRIDE 惯例，不真调外网）：
//   AI_PICK_EXTRACT_OVERRIDE / AI_PICK_GENERATE_OVERRIDE：注入两次模型调用的响应文本（走与真实响应相同的防御解析路径）；
//   AI_PICK_FAIL=1：模拟 curl 网络失败，强制模型调用返回 null（验证"暂不可用"降级文案）。
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/image_search.php'; // 复用 ARK 端点常量定义、secrets 加载与关键词清洗原语（不改动其逻辑）
// 套餐模型名不带日期版本号（2026-10-24 实测 doubao-seed-2.1-pro 可用）；正式 secrets.php 由下发方维护，此处仅兜底
if (!defined('ARK_MODEL_GUIDE')) define('ARK_MODEL_GUIDE', 'doubao-seed-2.1-pro');

// 单轮方舟调用：返回 choices[0].message.content 文本；未配置密钥/网络失败/响应异常一律返回 null（降级）
function ai_pick_ark_call($systemPrompt, $userContent) {
    if (getenv('AI_PICK_FAIL') === '1') return null; // 测试注入：模拟网络失败
    $apiKey = defined('ARK_API_KEY') ? ARK_API_KEY : '';
    if (!is_string($apiKey) || trim($apiKey) === '') return null;
    $body = json_encode([
        'model' => ARK_MODEL_GUIDE,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ],
        'temperature' => 0,
        // 抽取/挑选属结构化小任务：关闭思考链提速（doubao-seed 系思考模型默认思考，20s 超时口徑下实测约半数超时）
        'thinking' => ['type' => 'disabled'],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init(ARK_API_URL);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    // Windows phpStudy 运行时缺 CA bundle（实测报 unable to get local issuer certificate）：
    // 本机 phpStudy 自带 private/certs/cacert.pem，存在则显式指定；Linux CI/生产不存在该路径，走系统默认 CA
    $caBundle = dirname(dirname(dirname(__DIR__))) . '/private/certs/cacert.pem';
    if (is_file($caBundle)) $opts[CURLOPT_CAINFO] = $caBundle;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        error_log('ai_pick curl: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || $content === '') {
        error_log('ai_pick: unexpected ark response: ' . substr((string)$resp, 0, 300));
        return null;
    }
    return $content;
}

// 第一段响应防御解析：keywords 复用图片搜索清洗原语（非法字符过滤/去重/截 12 字/最多 8 个），
// 分类同口径截断；价格带 clamp 到 index.php 价格筛选同口径（0~999999，min>max 双双作废）；无可用检索条件返回 null。
function ai_pick_parse_extract($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) && preg_match('/\{.*\}/s', $raw, $m)) {
        $decoded = json_decode($m[0], true); // 容错：模型可能把 JSON 包进说明文字
    }
    if (!is_array($decoded)) return null;
    // image_search_sanitize 入参是"模型响应文本"（字符串），数组需先 json_encode 走同一条清洗路径
    $keywords = image_search_sanitize(json_encode((array)($decoded['keywords'] ?? []), JSON_UNESCAPED_UNICODE));
    $catWords = image_search_sanitize(json_encode([(string)($decoded['category'] ?? '')], JSON_UNESCAPED_UNICODE));
    $category = $catWords[0] ?? '';
    $priceMin = isset($decoded['price_min']) && is_numeric($decoded['price_min']) ? (float)$decoded['price_min'] : null;
    $priceMax = isset($decoded['price_max']) && is_numeric($decoded['price_max']) ? (float)$decoded['price_max'] : null;
    if ($priceMin !== null && ($priceMin < 0 || $priceMin > 999999)) $priceMin = null;
    if ($priceMax !== null && ($priceMax < 0 || $priceMax > 999999)) $priceMax = null;
    if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) { $priceMin = null; $priceMax = null; }
    if (!$keywords && $category === '') return null; // 抽不出任何可用条件，避免无谓查库
    return ['keywords' => $keywords, 'category' => $category, 'price_min' => $priceMin, 'price_max' => $priceMax];
}

// ① 抽取结构化检索条件（意图词如"送妈妈"由提示词剥离，只留商品词与价格带）
function ai_pick_extract($q) {
    $override = getenv('AI_PICK_EXTRACT_OVERRIDE');
    if (is_string($override) && $override !== '') return ai_pick_parse_extract($override);
    $q = trim((string)$q);
    if ($q === '' || mb_strlen($q) > 100) return null;
    $content = ai_pick_ark_call(
        '你是电商站的搜索条件抽取器。从用户需求中抽取结构化检索条件，只输出 JSON，不要任何其他内容：'
        .'{"keywords":["中文关键词"],"category":"商品分类名或空字符串","price_min":数字或null,"price_max":数字或null}。'
        .'keywords 给 2~5 个适合对商品标题/详情做包含匹配的关键词（去掉"送妈妈""预算"这类意图与话术词，保留商品品类词）；价格单位为元。',
        $q
    );
    if ($content === null) return null;
    return ai_pick_parse_extract($content);
}

// ② 复用 index.php 既有搜索口径取候选：可见性（status=1 + approved + 店铺在营）+ title/detail LIKE，
// 分类/价格带叠加；关键词 OR 连接。三级放宽梯子（2026-10-24 真调实测定级）：
//   L1 全条件 → L2 仅第一个关键词（下发方案的放宽条款）→ L3 去分类条件（LLM 猜的分类名常不在 categories 表内，
//   作硬过滤会埋掉库内真实商品——实测"保温杯"分类不存在但库内有 ¥69 保温杯；仅放宽召回面，不动 id 白名单防幻觉）。
function ai_pick_candidates(array $extract) {
    $vis = "p.status = 1 AND p.audit_status = 'approved' AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops active_shop WHERE active_shop.id = p.shop_id AND active_shop.status = 1))";
    $run = function (array $keywords, array $ex) use ($vis) {
        $where = " WHERE $vis";
        $params = [];
        $orParts = [];
        foreach ($keywords as $kw) {
            $like = '%' . addcslashes($kw, "%_\\") . '%';
            $orParts[] = '(p.title LIKE ? OR p.detail LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($orParts) $where .= ' AND (' . implode(' OR ', $orParts) . ')';
        if ($ex['category'] !== '') {
            $where .= ' AND p.category_id IN (SELECT id FROM categories WHERE category_name LIKE ?)';
            $params[] = '%' . addcslashes($ex['category'], "%_\\") . '%';
        }
        if ($ex['price_min'] !== null) { $where .= ' AND p.price >= ?'; $params[] = (float)$ex['price_min']; }
        if ($ex['price_max'] !== null) { $where .= ' AND p.price <= ?'; $params[] = (float)$ex['price_max']; }
        return rows("SELECT p.id, p.title, p.price, p.detail, c.category_name
            FROM products p LEFT JOIN categories c ON c.id = p.category_id
            $where ORDER BY p.id DESC LIMIT 10", $params);
    };
    $kw = is_array($extract['keywords'] ?? null) ? $extract['keywords'] : [];
    $found = $run($kw, $extract);
    if (!$found && count($kw) > 1) $found = $run([array_values($kw)[0]], $extract); // L2 放宽
    if (!$found && $extract['category'] !== '') {
        $soft = $extract;
        $soft['category'] = '';
        $found = $run($kw, $soft); // L3 去分类兜底
    }
    $out = [];
    foreach ($found as $r) {
        $blurb = trim(strip_tags((string)($r['detail'] ?? '')));
        $blurb = trim(preg_replace('/\s+/u', ' ', $blurb));
        $out[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'category' => (string)($r['category_name'] ?? ''),
            'sale_price_cents' => (int)round(((float)$r['price']) * 100),
            'blurb' => mb_substr($blurb, 0, 40), // 一句话卖点截 40 字，控制注入体积
        ];
    }
    return $out;
}

// 第三段响应防御解析：product_ids 整数化去重封顶 5 个；reason 去控制字符截 80 字；结构非法返回 null（降级）
function ai_pick_parse_generate($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) && preg_match('/\{.*\}/s', $raw, $m)) {
        $decoded = json_decode($m[0], true);
    }
    if (!is_array($decoded) || !isset($decoded['product_ids']) || !is_array($decoded['product_ids'])) return null;
    $ids = [];
    foreach ($decoded['product_ids'] as $v) {
        $id = (int)$v;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
        if (count($ids) >= 5) break;
    }
    $reason = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', (string)($decoded['reason'] ?? '')));
    return ['product_ids' => $ids, 'reason' => mb_substr($reason, 0, 80)];
}

// ③ 候选集注入生成结构化推荐；候选为空直接返回空结果，不调模型（省一次调用）
function ai_pick_generate($q, array $candidates) {
    if (!$candidates) return ['reason' => '没有找到匹配的商品', 'product_ids' => []];
    $override = getenv('AI_PICK_GENERATE_OVERRIDE');
    if (is_string($override) && $override !== '') return ai_pick_parse_generate($override);
    $lines = [];
    foreach ($candidates as $c) {
        $lines[] = 'id=' . $c['id'] . ' | 标题:' . $c['title'] . ' | 分类:' . $c['category']
            . ' | 价格:' . number_format($c['sale_price_cents'] / 100, 2) . '元 | 卖点:' . $c['blurb'];
    }
    $content = ai_pick_ark_call(
        '你是电商导购。只能从下方候选商品（含 id）中为用户挑选最合适的，最多 5 个，绝不允许推荐候选之外的任何商品，'
        .'也绝不编造候选中不存在的 id。只输出 JSON，不要任何其他内容：{"product_ids":[候选中的id],"reason":"80字以内的中文推荐理由"}。',
        "用户需求：$q\n\n候选商品：\n" . implode("\n", $lines)
    );
    if ($content === null) return null;
    return ai_pick_parse_generate($content);
}

// 三步编排 + 后端硬校验。返回 ['reason'=>string, 'products'=>[商品卡数组(id/title/price/image_url/shop_name)]]；
// 任一模型调用失败/解析失败返回 null（页面显示"AI 导购暂不可用"）。防幻觉定稿：
// product_ids ∉ 候选集一律丢弃；最终卡片一律按幸存 id 用既有可见口径重查库——下架/未审商品在最后一道关仍进不了输出。
function ai_pick_answer($q) {
    $q = trim((string)$q);
    if ($q === '') return null;
    $extract = ai_pick_extract($q);
    if ($extract === null) return null;
    $candidates = ai_pick_candidates($extract);
    $gen = ai_pick_generate($q, $candidates);
    if ($gen === null) return null;
    $allowed = [];
    foreach ($candidates as $c) $allowed[$c['id']] = true;
    $ids = [];
    foreach ($gen['product_ids'] as $id) {
        if (isset($allowed[$id]) && !in_array($id, $ids, true)) $ids[] = $id;
    }
    $products = [];
    if ($ids) {
        // 按幸存 id 重查库（可见口径最后一道关），卡片数据与 LLM 输出完全解耦
        $in = implode(',', array_fill(0, count($ids), '?'));
        $products = rows("SELECT p.id, p.title, p.price, p.image_url, s.shop_name
            FROM products p LEFT JOIN shops s ON s.id = p.shop_id
            WHERE p.id IN ($in) AND p.status = 1 AND p.audit_status = 'approved'
              AND (p.shop_id IS NULL OR EXISTS (SELECT 1 FROM shops active_shop WHERE active_shop.id = p.shop_id AND active_shop.status = 1))
            ORDER BY p.id DESC", $ids);
    }
    // 查询落搜索日志（复用 index.php 写入口径），results 记实际返回商品数，供后台搜索词分析
    query('INSERT INTO search_logs(user_id,keyword,results) VALUES(?,?,?)', [
        !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, $q, count($products),
    ]);
    $reason = (string)$gen['reason'];
    if (!$products) $reason = '没有找到匹配的商品'; // 全被丢弃/候选为空 → 空结果文案
    elseif ($reason === '') $reason = '为你挑选了以下商品';
    return ['reason' => $reason, 'products' => $products];
}
