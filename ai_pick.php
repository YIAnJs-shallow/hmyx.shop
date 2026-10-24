<?php
// AI 智能导购独立页（2026-10-24 第一阶段调研定稿：与 search_image.php 结果页同构，复用商品卡组件与结果页样式）。
// 门禁/限流口径：须登录（未登录重定向登录页带 next 回跳）；限流复用 rate_limit 原语，桶 ai-pick:uid，10 次/10 分钟（对齐登录失败窗口口径）。
// 不做 SSE 流式、不做多轮对话；失败降级文案与拍照搜同款一致。
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/security.php';
require_once __DIR__.'/lib/ai_pick.php';
// 登录门禁（对齐 points.php/member.php 惯例）：HTTP 重定向登录页带回跳；CLI 渲染提示供测试断言
if (empty($_SESSION['user_id'])) {
    if (PHP_SAPI !== 'cli') login_redirect(current_page_url());
    page_start('AI 帮我挑');
    echo '<p class="notice error" role="alert">请先登录后使用 AI 帮我挑，<a href="user_login.php?next=ai_pick.php">去登录</a>。</p>';
    page_end();
    return;
}
$error = ''; $unavailable = false; $answer = null; $q = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { // CSRF 由 config/db.php 对 POST 统一强制校验
    $q = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 100);
    if ($q === '') {
        $error = '请描述你想找的商品，例如：500 预算送妈妈的按摩仪';
    } elseif (!rate_limit_hit('ai-pick:'.(int)$_SESSION['user_id'], 10, 600)) { // rate_limit_hit 内建 rate_limit_ok 计数判断，被拒不记账
        $error = '操作过于频繁，请 10 分钟后再试。';
    } else {
        $answer = ai_pick_answer($q);
        if ($answer === null) $unavailable = true;
    }
}
page_start('AI 帮我挑');
?>
<section class="card">
<h2>✨ AI 帮我挑</h2>
<p class="muted">用一句话描述你的需求，AI 从站内在售商品中帮你挑选，最多推荐 5 件，点击商品进详情。</p>
<?php if ($error) echo '<p class="notice error" role="alert">'.h($error).'</p>'; ?>
<?php if ($unavailable) echo '<p class="notice" role="status">AI 导购暂不可用，请稍后再试，或直接使用关键词搜索。</p>'; ?>
<form method="post"><?=csrf_field()?>
<label>你的需求 <input type="text" name="q" maxlength="100" value="<?=h($q)?>" placeholder="例如：500 预算送妈妈的按摩仪" required></label>
<button type="submit">帮我挑</button>
</form>
</section>
<?php if (is_array($answer)): ?>
<section class="card">
<h2>AI 推荐理由</h2>
<p><?=h($answer['reason'])?></p>
</section>
<?php if ($answer['products']): ?>
<section class="card">
<h2>为你挑选（<?=count($answer['products'])?> 件）</h2>
<div class="grid"><?php foreach ($answer['products'] as $item): ?>
<article class="card">
<a href="detail.php?id=<?=(int)$item['id']?>">
<?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?><img class="thumb" style="width:100%;height:150px;" src="<?=h($item['image_url'])?>" alt="<?=h($item['title'])?>"><?php else: ?><span class="thumb" style="display:inline-flex;width:100%;height:150px;align-items:center;justify-content:center;font-size:2.5rem;">📦</span><?php endif; ?>
</a>
<h3 style="margin-top:10px;"><a href="detail.php?id=<?=(int)$item['id']?>"><?=h($item['title'])?></a></h3>
<?php if (!empty($item['shop_name'])): ?><div style="font-size:.8rem;color:#64748b;">🏪 <?=h($item['shop_name'])?></div><?php endif; ?>
<div class="price">￥<?=h(number_format((float)$item['price'], 2))?></div>
</article>
<?php endforeach; ?></div>
</section>
<?php else: ?>
<section class="card">
<p class="muted">没有找到匹配的商品，试试换个说法，或<a href="index.php">直接使用关键词搜索</a>。</p>
</section>
<?php endif; ?>
<?php endif; ?>
<?php page_end(); ?>
