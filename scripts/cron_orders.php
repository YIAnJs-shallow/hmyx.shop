<?php
// 订单定时任务：CLI 运行 `php scripts/cron_orders.php`
// 1) 超时关单：待支付超过 3 小时的订单逐单在事务内取消并回补库存，写站内取消通知（窗口与 expire_orders/pay.php 倒计时的 10800 秒一致）
// 2) 自动确认收货：已发货超过 7 天的订单复用 change_order 的 receive 分支（等价于用户手动确认）
// 3) 拼团超时退款：成团时限内未成团的团逐单退款/关单，全部处理完把活动置 ended（重扫防重依赖活动状态条件）
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

// 拼团成团超时：扫 active 活动下创建超过 duration_hours 且未成团的团（团长行=团），逐单处理：
// 已支付订单走退款链路（演示支付直接置退款态；沙箱支付调 alipay_trade_refund，失败记日志留待下次重扫），
// 未支付订单直接取消回补库存；团内全部处理完成后活动置 ended。
function cron_expire_group_orders() {
    global $conn;
    $expired = 0;
    $groups = rows("SELECT gb.id AS activity_id, m.id AS group_id
        FROM group_members m
        JOIN orders o ON o.id = m.order_id
        JOIN group_buys gb ON gb.id = o.group_buy_id
        WHERE m.group_id = m.id AND gb.status='active'
          AND m.created_at < NOW() - INTERVAL gb.duration_hours HOUR
          AND NOT EXISTS (SELECT 1 FROM group_members m2 JOIN orders o2 ON o2.id=m2.order_id
                          WHERE m2.group_id = m.id AND o2.group_success=1)");
    foreach ($groups as $group) {
        $allDone = true;
        foreach (rows("SELECT o.* FROM group_members m JOIN orders o ON o.id=m.order_id WHERE m.group_id=?", [(int)$group['group_id']]) as $memberOrder) {
            if ($memberOrder['status'] === 'cancelled') continue;
            if ($memberOrder['status'] === 'pending') {
                // 未支付：直接关单回补库存
                $conn->begin_transaction();
                try {
                    $order = one("SELECT * FROM orders WHERE id=? AND status='pending' FOR UPDATE", [(int)$memberOrder['id']]);
                    if ($order) {
                        release_stock($order);
                        query("UPDATE orders SET status='cancelled' WHERE id=?", [(int)$order['id']]);
                        notify((int)$order['user_id'], 'order', '拼团超时未成团，订单已取消', '订单 '.$order['order_no'].' 所在拼团未在时限内成团，未支付订单已自动取消并退回库存。', 'my_orders.php');
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('cron expire group order '.$memberOrder['id'].' failed: '.$e->getMessage());
                    $allDone = false;
                }
                continue;
            }
            if (!in_array($memberOrder['status'], ['paid', 'shipped', 'completed'], true)) continue;
            // 已支付：演示支付直接置退款态；沙箱支付走 alipay.trade.refund，失败留待下次重扫
            $refundCents = (int)round(((float)$memberOrder['total_amount']) * 100) - (int)round(((float)$memberOrder['discount_amount']) * 100) - (int)$memberOrder['points_discount_cents'];
            $refundOk = true;
            if ($memberOrder['payment_method'] === 'alipay_sandbox') {
                require_once __DIR__.'/../lib/alipay.php';
                $refundOk = alipay_trade_refund($memberOrder['order_no'], $refundCents, $memberOrder['order_no'].'-G'.$group['group_id']);
            }
            if (!$refundOk) { $allDone = false; continue; }
            $conn->begin_transaction();
            try {
                $order = one("SELECT * FROM orders WHERE id=? AND status<> 'cancelled' FOR UPDATE", [(int)$memberOrder['id']]);
                if ($order && $order['status'] !== 'cancelled') {
                    release_stock($order);
                    query("UPDATE orders SET status='cancelled' WHERE id=?", [(int)$order['id']]);
                    notify((int)$order['user_id'], 'refund', '拼团超时未成团，退款已受理', '订单 '.$order['order_no'].' 所在拼团未在时限内成团，已支付款项将按原支付方式退回。', 'my_orders.php');
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('cron expire group refund '.$memberOrder['id'].' failed: '.$e->getMessage());
                $allDone = false;
            }
        }
        // 团内全部处理完才结束活动（有退款失败的团下次 cron 重扫重试）
        if ($allDone) {
            query("UPDATE group_buys SET status='ended' WHERE id=? AND status='active'", [(int)$group['activity_id']]);
            $expired++;
        }
    }
    return $expired;
}

// 被测试 include 时不自动执行，仅作为独立 CLI 脚本运行时生效
if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $closed = cron_close_timeout_orders();
    $received = cron_auto_receive_orders();
    $expiredGroups = cron_expire_group_orders();
    echo "关单 {$closed} 单，自动收货 {$received} 单，拼团超时处理 {$expiredGroups} 团\n";
}
