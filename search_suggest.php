<?php
// 搜索联想接口：无需登录；前端 fetch 后用 textContent 插入，JSON_UNESCAPED_UNICODE 保证中文可读
require_once __DIR__.'/lib/shop.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(search_suggest_words($_GET['keyword'] ?? ''), JSON_UNESCAPED_UNICODE);
