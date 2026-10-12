<?php
require_once __DIR__ . '/lib/security.php';
require_once 'config/db.php';

// 4. 检查是否登录（已登录则右上角显示进入商城入口）
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>惠蒙优选 - 新人专享 · 开启品质购物之旅</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- 顶部通栏导航：右上角为登录入口 -->
    <div class="landing-nav">
        <div class="logo-light">
            <a href="welcome.php">
                <svg viewBox="0 0 100 100" width="40" height="40">
                    <defs>
                        <linearGradient id="logoWhite" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#ffffff" />
                            <stop offset="100%" stop-color="#dbeafe" />
                        </linearGradient>
                    </defs>
                    <rect x="15" y="25" width="70" height="65" rx="14" fill="url(#logoWhite)" />
                    <path d="M 35 25 A 15 15 0 0 1 65 25" fill="none" stroke="#2563eb" stroke-width="6" stroke-linecap="round"/>
                    <path d="M 35 45 L 35 70 M 65 45 L 65 70 M 35 57 L 65 57" fill="none" stroke="#2563eb" stroke-width="6" stroke-linecap="round"/>
                    <path d="M 75 18 L 77 25 L 84 27 L 77 29 L 75 36 L 73 29 L 66 27 L 73 25 Z" fill="#f59e0b"/>
                </svg>
                <span class="logo-text">惠蒙优选
                    <span class="logo-sub">HUIMENG CHOICE</span>
                </span>
            </a>
        </div>
        <div class="landing-nav-actions">
            <a href="index.php" class="btn-ghost-light">进入商城</a>
            <?php if ($is_logged_in): ?>
                <a href="user_center.php" class="btn-login-top">👤 个人中心</a>
                <a href="my_coupons.php" class="btn-ghost-light">查看我的优惠券</a>
            <?php else: ?>
                <a href="register.php" class="btn-ghost-light">注册领新人礼包</a>
                <a href="user_login.php" class="btn-login-top">登 录</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 主视觉区 -->
    <section class="hero">
        <span class="hero-badge">🎉 新人注册即享专属好礼</span>
        <h1>甄选好物，<span class="hl">惠</span>及每一位<br>追求品质的你</h1>
        <p>惠蒙优选汇聚数码电子、服饰家居、美妆个护等品类，多商户正品入驻、平台审核把关，让你买得放心、用得舒心。</p>
        <div class="hero-actions">
            <a href="register.php" class="btn-hero-primary">立即注册 · 领新人礼包</a>
            <a href="index.php" class="btn-hero-ghost">先逛逛商城</a>
        </div>
    </section>

    <!-- 网站简介 -->
    <section class="landing-section">
        <div class="section-eyebrow">ABOUT US · 关于我们</div>
        <h2>为什么选择<span class="hl">惠蒙优选</span></h2>
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon">🛡️</div>
                <h3>平台严审正品</h3>
                <p>所有商户与商品均经平台双重审核上架，杜绝来路不明的货源。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon warm">📦</div>
                <h3>品类一站购齐</h3>
                <p>数码、服饰、家居、美妆多品类覆盖，一个账号逛遍全场好物。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon green">🚚</div>
                <h3>订单全程可查</h3>
                <p>下单、支付、发货、签收全流程透明，物流信息实时跟踪。</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h3>评价真实可信</h3>
                <p>已购用户真实评价公开展示，为你下单前提供参考。</p>
            </div>
        </div>
    </section>

    <!-- 新人优惠条款 -->
    <div class="landing-section-alt">
        <section class="landing-section">
            <div class="section-eyebrow">NEW USER BENEFITS · 新人专享</div>
            <h2>注册即享<span class="hl">三重好礼</span></h2>
            <div class="perk-grid">
                <div class="perk-card">
                    <span class="perk-tag">好礼一 · 首单立减</span>
                    <h3>新人首单立减 ¥15</h3>
                    <p>注册成功后完成首笔订单，即可享受订单金额直减 15 元优惠。</p>
                    <p class="perk-fine">单笔订单满 ¥99 可用</p>
                </div>
                <div class="perk-card cool">
                    <span class="perk-tag">好礼二 · 免邮福利</span>
                    <h3>新人免运费 3 单</h3>
                    <p>注册之日起 30 天内，前 3 笔订单全额免除运费，省下每一分钱。</p>
                    <p class="perk-fine">不与店铺包邮活动叠加</p>
                </div>
                <div class="perk-card">
                    <span class="perk-tag">好礼三 · 专属价</span>
                    <h3>新人专享折扣价</h3>
                    <p>部分商品专设"新人价"，注册后 30 天内下单自动生效，无需领券。</p>
                    <p class="perk-fine">以商品页"新人价"标识为准</p>
                </div>
            </div>

            <div class="perk-terms">
                <h4>📌 新人优惠条款细则</h4>
                <ol>
                    <li>新人资格：自账号注册成功之日起 30 天内，且此前从未在本平台下过单的用户。</li>
                    <li>首单立减与免邮福利可同时享受；新人专享价商品不再叠加其他优惠券。</li>
                    <li>订单发生退款、取消时，若新人资格仍在有效期内，优惠资格自动返还；超过有效期则不再返还。</li>
                    <li>恶意注册、批量刷单等行为将取消优惠资格，本平台保留最终解释权。</li>
                </ol>
            </div>
        </section>
    </div>

    <!-- 结尾 CTA -->
    <section class="landing-cta">
        <h2>准备好开启你的品质购物之旅了吗？</h2>
        <p>加入惠蒙优选，好物与好礼，一站直达。</p>
        <?php if ($is_logged_in): ?>
            <a href="index.php" class="btn-hero-primary">进入商城逛逛</a>
            <a href="my_coupons.php" class="btn-hero-ghost">查看我的优惠券</a>
        <?php else: ?>
            <a href="user_login.php" class="btn-hero-primary">立即登录 / 注册</a>
            <a href="register.php" class="btn-hero-ghost">注册领新人礼包</a>
        <?php endif; ?>
    </section>

    <footer class="site-footer">
        <p>&copy; <?php echo date('Y'); ?> 惠蒙优选 All Rights Reserved. · 新人活动最终解释权归平台所有</p>
    </footer>
</body>
</html>
