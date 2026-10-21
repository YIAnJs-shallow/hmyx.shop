<?php
// 客服私聊会话页（2026-10-21 E-v2 IM 化改版）：微信式全屏对话——顶栏/消息流/固定输入条，AJAX 发送秒上屏 + 5 秒轮询
// 双方共用：买家从详情页/店铺页进入，商家从工作台会话列表进入（无 user_id 参数时显示会话列表）
require_once __DIR__ . '/lib/shop.php';
$userId = uid();
$shopId = (int)($_GET['shop_id'] ?? 0);
$ctxProduct = (int)($_GET['product_id'] ?? 0);
$shop = $shopId > 0 ? one('SELECT * FROM shops WHERE id=? AND status=1', [$shopId]) : null;
if (!$shop) {
    page_start('会话不可用');
    echo '<section class="card"><p>店铺不存在或已关闭，无法发起会话，<a href="index.php">回首页逛逛</a>。</p></section>';
    page_end();
    return; // include 场景（测试套件）只结束本文件
}
$isMerchantOwner = (($_SESSION['role'] ?? '') === 'merchant') && (int)$shop['user_id'] === $userId;
if (!$isMerchantOwner && ($_SESSION['role'] ?? '') === 'merchant') {
    http_response_code(403);
    page_start('无权访问');
    echo '<section class="card"><p>商家账号只能与自己的店铺会话。</p></section>';
    page_end();
    return;
}
$role = $isMerchantOwner ? 'merchant' : 'buyer';
$threadUser = $role === 'merchant' ? (int)($_GET['user_id'] ?? 0) : $userId;

// 商户未指定会话对象：展示会话列表（微信会话首页式），不报错
if ($role === 'merchant' && $threadUser <= 0) {
    $threads = shop_chat_threads_merchant($shopId);
    page_start('客服会话 · ' . h($shop['shop_name']));
    echo '<section class="card"><h2>💬 客服会话列表</h2>';
    if (!$threads) echo '<p class="muted">暂无买家会话；买家在商品详情页点击"联系客服"后会出现在这里。</p>';
    foreach ($threads as $t) {
        echo '<div class="row"><div class="grow"><a href="chat.php?shop_id=' . $shopId . '&user_id=' . (int)$t['user_id'] . '"><strong>' . h($t['username']) . '</strong></a>'
            . '<p class="muted small" style="margin:2px 0;">' . h($t['last_at']) . '</p>'
            . '<p style="margin:4px 0;">' . h(mb_substr((string)$t['last_content'], 0, 40)) . '</p></div>'
            . '<a class="btn-buy-link" href="chat.php?shop_id=' . $shopId . '&user_id=' . (int)$t['user_id'] . '">打开（' . (int)$t['unread'] . '）</a></div>';
    }
    echo '</section>';
    page_end();
    return;
}

$threadName = $role === 'merchant'
    ? (string)(one('SELECT username FROM users WHERE id=?', [$threadUser])['username'] ?? '买家')
    : $shop['shop_name'];
shop_chat_mark_read($shopId, $threadUser, $role);
$messages = shop_chat_list($shopId, $threadUser);
$ctxTitle = $ctxProduct ? (string)(one('SELECT title FROM products WHERE id=?', [$ctxProduct])['title'] ?? '') : '';
$lastId = $messages ? max(array_map('intval', array_column($messages, 'id'))) : 0;
page_start(($role === 'buyer' ? '与「' . h($threadName) . '」的客服对话' : '与买家「' . h($threadName) . '」的对话'));
?>
<div class="im-wrap">
    <div class="im-head">
        <a href="<?= $role === 'merchant' ? 'chat.php?shop_id=' . $shopId : 'shop.php?id=' . $shopId ?>" class="im-back">‹</a>
        <span class="im-avatar"><?= $role === 'merchant' ? '👤' : '🏪' ?></span>
        <div class="im-head-info">
            <strong><?= h($threadName) ?></strong>
            <small class="muted"><?= $role === 'merchant' ? '买家咨询' : '商家在线 · 通常数分钟内回复' ?></small>
        </div>
    </div>
    <?php if ($ctxTitle !== ''): ?><div class="im-ctx">当前咨询：<a href="detail.php?id=<?= $ctxProduct ?>"><?= h(mb_substr($ctxTitle, 0, 24)) ?></a></div><?php endif; ?>
    <div class="im-msgs" id="im-msgs">
        <?php if (!$messages): ?><p class="im-empty" id="im-empty">暂无消息，发送第一条开始对话吧</p><?php endif; ?>
        <?php foreach ($messages as $m): ?>
        <div class="chat-line <?= $m['sender_role'] === $role ? 'me' : 'other' ?>">
            <div class="chat-bubble <?= $m['sender_role'] === $role ? 'me' : 'other' ?>">
                <?php if (!empty($m['product_title'])): ?><a class="chat-ref" href="detail.php?id=<?= (int)$m['product_id'] ?>">🛍️ <?= h(mb_substr((string)$m['product_title'], 0, 20)) ?></a><?php endif; ?>
                <p><?= h($m['content']) ?></p>
                <small class="chat-time"><?= h($m['created_at']) ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="im-inputbar">
        <input id="im-input" maxlength="500" placeholder="<?= $role === 'buyer' ? '向客服描述你想了解的问题…' : '回复买家…' ?>" autocomplete="off">
        <button id="im-send-btn" type="button">发送</button>
    </div>
</div>
<script>
(function () {
    var box = document.getElementById('im-msgs');
    var input = document.getElementById('im-input');
    var btn = document.getElementById('im-send-btn');
    if (!box || !input || !btn) return;
    var role = '<?= $role ?>';
    var after = <?= (int)$lastId ?>;
    var base = 'shop_id=<?= (int)$shopId ?>&user_id=<?= $threadUser ?>&product_id=<?= $ctxProduct ?>';
    var csrf = '<?= h(csrf_token()) ?>';
    var scrollBottom = function () { box.scrollTop = box.scrollHeight; };
    var makeBubble = function (m) {
        var line = document.createElement('div');
        line.className = 'chat-line ' + (m.sender_role === role ? 'me' : 'other');
        var b = document.createElement('div');
        b.className = 'chat-bubble ' + (m.sender_role === role ? 'me' : 'other');
        b.innerHTML = (m.product_title ? '<a class="chat-ref" href="detail.php?id=' + m.product_id + '">🛍️ ' + m.product_title + '</a>' : '')
            + '<p>' + m.escaped + '</p><small class="chat-time">' + m.created_at + '</small>';
        line.appendChild(b);
        return line;
    };
    var empty = document.getElementById('im-empty');
    var receive = function (list) {
        if (!list || !list.length) return;
        if (empty) { empty.remove(); }
        list.forEach(function (m) { after = Math.max(after, m.id); box.appendChild(makeBubble(m)); });
        scrollBottom();
    };
    var send = function () {
        var text = input.value.trim();
        if (text.length < 2) { input.focus(); return; }
        btn.disabled = true;
        var body = new URLSearchParams(base);
        body.set('content', text);
        fetch('chat_send.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (r) { return r.ok ? r.json() : { success: false, message: '网络异常' }; })
            .then(function (d) {
                btn.disabled = false;
                if (!d.success) { alert(d.message || '发送失败'); return; }
                input.value = '';
                receive([d.message]);
                input.focus();
            })
            .catch(function () { btn.disabled = false; alert('网络异常，请稍后再试'); });
    };
    var poll = function () {
        fetch('chat_poll.php?' + base + '&after=' + after)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.success) receive(d.messages); })
            .catch(function () {});
    };
    btn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    setInterval(poll, 5000);
    scrollBottom();
    input.focus();
})();
</script>
<?php page_end(); ?>
