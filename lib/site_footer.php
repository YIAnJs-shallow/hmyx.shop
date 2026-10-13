<?php
/**
 * 全站公共页脚（京东式四层结构：服务保障条 + 5 列帮助导航 + 横链接行 + 备案版权行）
 * 纯静态输出：不查库、不依赖 session。占位链接（js-todo）仅剩「积分商城/友情链接」，其余均已接通真实页面或 help.php 锚点。
 */
if (!function_exists('render_site_footer')) {
    function render_site_footer(): void
    {
        ?>
<footer class="site-footer">
    <div class="footer-service">
        <div class="fs-item">
            <span class="fs-badge">正</span>
            <span><span class="fs-title">正品保障</span><br><span class="fs-sub">商家资质严格审核</span></span>
        </div>
        <div class="fs-item">
            <span class="fs-badge">速</span>
            <span><span class="fs-title">极速发货</span><br><span class="fs-sub">多地仓储极速直达</span></span>
        </div>
        <div class="fs-item">
            <span class="fs-badge">退</span>
            <span><span class="fs-title">7天退换</span><br><span class="fs-sub">无理由退换有保障</span></span>
        </div>
        <div class="fs-item">
            <span class="fs-badge">客</span>
            <span><span class="fs-title">贴心客服</span><br><span class="fs-sub">全天在线答疑</span></span>
        </div>
    </div>
    <div class="footer-nav">
        <div>
            <h4>新手指南</h4>
            <a href="help.php#shopping-flow" class="footer-link-real">购物流程</a>
            <a href="help.php#vip" class="footer-link-real">会员介绍</a>
            <a href="welcome.php" class="footer-link-real">新人礼包</a>
            <a href="help.php#faq" class="footer-link-real">常见问题</a>
        </div>
        <div>
            <h4>购物方式</h4>
            <a href="index.php" class="footer-link-real">热门商品</a>
            <a href="favorites.php" class="footer-link-real">我的收藏</a>
            <a href="cart.php" class="footer-link-real">购物车</a>
            <a href="my_orders.php" class="footer-link-real">我的订单</a>
            <a href="my_coupons.php" class="footer-link-real">我的优惠券</a>
            <a href="#" class="js-todo">积分商城</a>
        </div>
        <div>
            <h4>支付方式</h4>
            <a href="help.php#payment" class="footer-link-real">在线支付</a>
            <a href="help.php#payment" class="footer-link-real">货到付款</a>
            <a href="help.php#payment" class="footer-link-real">分期支付</a>
        </div>
        <div>
            <h4>售后服务</h4>
            <a href="help.php#after-sale" class="footer-link-real">售后政策</a>
            <a href="help.php#return-flow" class="footer-link-real">退换货流程</a>
            <a href="help.php#refund" class="footer-link-real">退款说明</a>
            <a href="help.php#contact" class="footer-link-real">联系客服</a>
        </div>
        <div>
            <h4>特色服务</h4>
            <a href="index.php" class="footer-link-real">秒杀专区</a>
            <a href="welcome.php" class="footer-link-real">新人专享</a>
            <a href="help.php#about" class="footer-link-real">关于我们</a>
            <a href="help.php#merchant" class="footer-link-real">商家入驻</a>
        </div>
    </div>
    <div class="footer-links">
        <a href="help.php#about" class="footer-link-real">关于我们</a>|<a href="help.php#contact" class="footer-link-real">联系我们</a>|<a href="help.php#privacy" class="footer-link-real">隐私政策</a>|<a href="help.php" class="footer-link-real">帮助中心</a>|<a href="help.php#merchant" class="footer-link-real">商家入驻</a>|<a href="#" class="js-todo">友情链接</a>
    </div>
    <div class="footer-legal">
        <div>ICP备案号：占位（上线前替换） | 增值电信业务经营许可证：占位（上线前替换）</div>
        <div>&copy; <?php echo date('Y'); ?> 惠蒙优选 All Rights Reserved. | 消费者维权热线：400-000-0000（占位）</div>
    </div>
</footer>
        <?php
    }
}
