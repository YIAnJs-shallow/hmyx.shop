<?php
// 搜索词分析看板：近 30 天搜索日志聚合（次数/无结果次数/平均命中/最近时间）+ 无结果词缺口列表，指导选品与补词
require __DIR__ . '/inc/auth.php';
require_permission('dashboard.view');
$page_title = '搜索词分析';

$summary = one("SELECT COUNT(*) AS total_searches,
                       SUM(results = 0) AS no_result_searches,
                       COUNT(DISTINCT keyword) AS distinct_keywords
                FROM search_logs
                WHERE created_at >= NOW() - INTERVAL 30 DAY AND results IS NOT NULL");
// 热搜 TOP 100：按次数降序，无结果次数一并列出（0 = 每次都有结果）
$topKeywords = rows("SELECT keyword,
                            COUNT(*) AS searches,
                            SUM(results = 0) AS no_result,
                            ROUND(AVG(results), 1) AS avg_results,
                            MAX(created_at) AS last_searched_at
                     FROM search_logs
                     WHERE created_at >= NOW() - INTERVAL 30 DAY AND results IS NOT NULL
                     GROUP BY keyword
                     ORDER BY searches DESC, keyword ASC
                     LIMIT 100");
// 无结果词缺口：全部搜索均无结果的词，按搜索次数降序（这些词站内在售商品一个都没命中）
$gapKeywords = rows("SELECT keyword,
                            COUNT(*) AS searches,
                            MAX(created_at) AS last_searched_at
                     FROM search_logs
                     WHERE created_at >= NOW() - INTERVAL 30 DAY AND results IS NOT NULL
                     GROUP BY keyword
                     HAVING SUM(results = 0) = COUNT(*)
                     ORDER BY searches DESC, keyword ASC
                     LIMIT 50");
require __DIR__ . '/inc/header.php'; ?>
<h1>搜索词分析（近 30 天）</h1>
<p>共 <?= (int)($summary['total_searches'] ?? 0) ?> 次搜索、<?= (int)($summary['distinct_keywords'] ?? 0) ?> 个不同关键词，其中 <?= (int)($summary['no_result_searches'] ?? 0) ?> 次无结果（统计口径：仅含新近日志行，历史行未记录命中数）。</p>
<h2>无结果词（选品缺口）</h2>
<p>以下关键词站内在售商品全部未命中，按搜索次数排序，可据此补货或优化标题/详情关键词。</p>
<table class="crud-table">
    <tr><th>关键词</th><th>搜索次数</th><th>最近搜索</th></tr>
    <?php foreach ($gapKeywords as $g): ?>
    <tr><td><?= h($g['keyword']) ?></td><td><?= (int)$g['searches'] ?></td><td><?= h($g['last_searched_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$gapKeywords): ?>
    <tr><td colspan="3">暂无数据</td></tr>
    <?php endif; ?>
</table>
<h2>热搜关键词 TOP 100</h2>
<table class="crud-table">
    <tr><th>关键词</th><th>搜索次数</th><th>无结果次数</th><th>平均命中</th><th>最近搜索</th></tr>
    <?php foreach ($topKeywords as $t): ?>
    <tr><td><?= h($t['keyword']) ?></td><td><?= (int)$t['searches'] ?></td><td><?= (int)$t['no_result'] ?></td><td><?= h($t['avg_results']) ?></td><td><?= h($t['last_searched_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$topKeywords): ?>
    <tr><td colspan="5">暂无数据</td></tr>
    <?php endif; ?>
</table>
<?php require __DIR__ . '/inc/footer.php'; ?>
