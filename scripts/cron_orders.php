<?php
// 订单定时任务：CLI 运行 `php scripts/cron_orders.php`
// 1) 超时关单：待支付超过 3 小时的订单逐单在事务内取消并回补库存，写站内取消通知（窗口与 expire_orders/pay.php 倒计时的 10800 秒一致）
// 2) 自动确认收货：已发货超过 7 天的订单复用 change_order 的 receive 分支（等价于用户手动确认）
// 幂等依赖 FOR UPDATE + WHERE 状态条件：重复运行不会产生重复副作用
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/shop.php';

function cron_close_timeout_orders() {
    global $conn;
    $closed = 0;
    foreach (rows("SELECT id FROM orders WHERE status='pending' AND created_at < NOW() - INTERVAL 3 HOUR") as $candidate) {
        $conn->begin_transaction();
        try {
            // 事务内锁定订单行并复核状态与超时条件，防止与支付/取消并发产生重复回补
            $order = one("SELECT * FROM orders WHERE id=? AND status='pending' AND created_at < NOW() - INTERVAL 3 HOUR FOR UPDATE", [(int)$candidate['id']]);
            if (!$order) { $conn->commit(); continue; }
            release_stock($order);
            query("UPDATE orders SET status='cancelled' WHERE id=?", [(int)$order['id']]);
            notify((int)$order['user_id'], 'order', '订单超时未支付已自动取消', '订单 '.$order['order_no'].' 超过 3 小时未支付，已自动取消并退回库存，如仍需购买请重新下单。', 'my_orders.php');
            $conn->commit();
            $closed++;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('cron close order '.$candidate['id'].' failed: '.$e->getMessage());
        }
    }
    return $closed;
}

function cron_auto_receive_orders() {
    $received = 0;
    foreach (rows("SELECT id, user_id FROM orders WHERE status='shipped' AND shipped_at < NOW() - INTERVAL 7 DAY") as $candidate) {
        try {
            // 复用确认收货事务逻辑：FOR UPDATE 锁单 + shipped 状态校验 + 流转到 completed
            change_order((int)$candidate['id'], (int)$candidate['user_id'], 'receive');
            $received++;
        } catch (Throwable $e) {
            error_log('cron auto receive order '.$candidate['id'].' failed: '.$e->getMessage());
        }
    }
    return $received;
}

// 被测试 include 时不自动执行，仅作为独立 CLI 脚本运行时生效
if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $closed = cron_close_timeout_orders();
    $received = cron_auto_receive_orders();
    echo "关单 {$closed} 单，自动收货 {$received} 单\n";
}
