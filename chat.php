<?php
// 客服私聊会话页（2026-10-21 E-v2）：买家与商家一对一，气泡式对话；可携带商品上下文
// 双方共用：买家从详情页/店铺页进入，商家从工作台会话列表进入；页面内校验会话归属
require_once __DIR__ . '/lib/shop.php';
$userId = uid();
$shopId = (int)($_GET['shop_id'] ?? $_POST['shop_id'] ?? 0);
$ctxProduct = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['chat_action'] ?? '') === 'send') {
    $threadUser = $role === 'merchant' ? (int)($_GET['user_id'] ?? 0) : $userId;
    try {
        shop_chat_send($shopId, $threadUser, $role, $_POST['chat_content'] ?? '', $ctxProduct ?: null);
    } catch (DomainException $e) {
        redirect_to('chat.php?shop_id=' . $shopId . ($ctxProduct ? '&product_id=' . $ctxProduct : ''), $e->getMessage());
    } catch (Throwable $e) {
        error_log($e);
        redirect_to('chat.php?shop_id=' . $shopId . ($ctxProduct ? '&product_id=' . $ctxProduct : ''), '发送失败，请稍后再试');
    }
    redirect_to('chat.php?shop_id=' . $shopId . ($ctxProduct ? '&product_id=' . $ctxProduct : ''), '');
}
shop_chat_mark_read($shopId, $userId, $role);
$messages = shop_chat_list($shopId, $userId);
$ctxTitle = $ctxProduct ? (string)(one('SELECT title FROM products WHERE id=?', [$ctxProduct])['title'] ?? '') : '';
$lastId = $messages ? max(array_map('intval', array_column($messages, 'id'))) : 0;
page_start(($role === 'buyer' ? '与「' . h($shop['shop_name']) . '」的客服会话' : '客服会话 · ' . h((string)(one('SELECT username FROM users WHERE id=?', [(int)($_GET['user_id'] ?? 0)])['username'] ?? ''))));
?>
<section class="card chat-wrap">
    <?php if ($role === 'merchant'): ?>
    <p><a href="admin_orders.php">← 返回商家工作台</a> · 买家：<?= h((string)(one('SELECT username FROM users WHERE id=?', [(int)($_GET['user_id'] ?? 0)])['username'] ?? '')) ?></p>
    <?php else: ?>
    <p><a href="shop.php?id=<?= $shopId ?>">← 返回 <?= h($shop['shop_name']) ?> 店铺主页</a> · 客服通常在数分钟内回复</p>
    <?php endif; ?>
    <?php if ($ctxTitle !== ''): ?><p class="muted small">当前咨询商品：<a href="detail.php?id=<?= $ctxProduct ?>"><?= h(mb_substr($ctxTitle, 0, 30)) ?></a></p><?php endif; ?>
    <div class="chat-box" id="chat-box">
        <?php if (!$messages): ?><p class="muted" id="chat-empty">暂无消息，发送第一条开始对话吧。</p><?php endif; ?>
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
    <form method="post" class="chat-send"><?= csrf_field() ?>
        <input type="hidden" name="chat_action" value="send">
        <input type="hidden" name="product_id" value="<?= $ctxProduct ?>">
        <input name="chat_content" minlength="2" maxlength="500" placeholder="<?= $role === 'buyer' ? '向客服描述你想了解的问题…' : '回复买家…' ?>" required autocomplete="off">
        <button>发送</button>
    </form>
</section>
<script>
(function () {
    var box = document.getElementById('chat-box');
    if (!box) return;
    var after = <?= (int)$lastId ?>;
    var qs = 'shop_id=<?= (int)$shopId ?>&user_id=<?= $role === 'merchant' ? (int)($_GET['user_id'] ?? 0) : $userId ?>';
    var poll = function () {
        fetch('chat_poll.php?' + qs + '&after=' + after, { headers: { 'X-CSRF-Token': '<?= h(csrf_token()) ?>' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.messages || !d.messages.length) return;
                var empty = document.getElementById('chat-empty');
                if (empty) empty.remove();
                d.messages.forEach(function (m) {
                    after = Math.max(after, m.id);
                    var line = document.createElement('div');
                    line.className = 'chat-line ' + (m.sender_role === '<?= $role ?>' ? 'me' : 'other');
                    var b = document.createElement('div');
                    b.className = 'chat-bubble ' + (m.sender_role === '<?= $role ?>' ? 'me' : 'other');
                    b.innerHTML = (m.product_title ? '<a class="chat-ref" href="detail.php?id=' + m.product_id + '">🛍️ ' + m.product_title + '</a>' : '')
                        + '<p>' + m.escaped + '</p><small class="chat-time">' + m.created_at + '</small>';
                    line.appendChild(b);
                    box.appendChild(line);
                });
                box.scrollTop = box.scrollHeight;
            })
            .catch(function () {});
    };
    setInterval(poll, 15000);
    box.scrollTop = box.scrollHeight;
})();
</script>
<?php page_end(); ?>
