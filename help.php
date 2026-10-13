<?php
/**
 * 帮助中心单页（纯静态内容页：不查库、不依赖登录、不引入 js）
 * 通过锚点章节承接页脚指南类链接，底部复用全站公共页脚。
 */
require_once __DIR__ . '/lib/site_footer.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帮助中心 - 惠蒙优选</title>
    <link rel="stylesheet" href="css/style.css?v=5">
</head>
<body>
<div class="help-wrap">
    <div class="help-topbar">
        <a href="index.php" class="help-logo">惠蒙优选</a>
        <a href="index.php" class="help-back-home">返回首页</a>
    </div>
    <h1>帮助中心</h1>

    <section id="shopping-flow">
        <h2>购物流程</h2>
        <ol>
            <li>浏览首页推荐或使用搜索功能查找心仪商品；</li>
            <li>选择规格与数量，点击「加入购物车」；</li>
            <li>进入购物车核对商品，点击「去结算」确认订单信息；</li>
            <li>选择支付方式并完成支付；</li>
            <li>商家确认订单后安排发货，可在「我的订单」中查看物流状态；</li>
            <li>收到商品后确认收货；</li>
            <li>对商品进行评价，分享购物体验。</li>
        </ol>
    </section>

    <section id="vip">
        <h2>会员介绍</h2>
        <ul>
            <li>注册即成为惠蒙优选会员，无需额外费用；</li>
            <li>每完成一笔有效订单可获得相应积分，积分可用于后续活动；</li>
            <li>平台会不定期发放优惠券，可在「我的优惠券」页面查看领取到的优惠券；</li>
            <li>优惠券的使用规则（面额、门槛、有效期）以「我的优惠券」页面展示为准；</li>
            <li>新人注册后可前往新人专享页领取新人礼包。</li>
        </ul>
    </section>

    <section id="faq">
        <h2>常见问题</h2>
        <ul>
            <li><strong>问：下单后查不到订单？</strong><br>答：请先确认是否已登录下单时使用的账号，再进入「我的订单」查看；若支付未完成，订单可能仍在待支付状态。</li>
            <li><strong>问：优惠券为什么不生效？</strong><br>答：请核对优惠券的使用门槛、有效期与适用商品范围，可在「我的优惠券」页面查看券的具体规则。</li>
            <li><strong>问：如何修改收货地址？</strong><br>答：订单发货前可在下单页面重新选择或修改收货地址；发货后请联系商家或客服协助处理。</li>
            <li><strong>问：忘记密码怎么办？</strong><br>答：请前往 <a href="forgot_password.php">找回密码</a> 页面，按提示通过注册邮箱重置密码。</li>
        </ul>
    </section>

    <section id="payment">
        <h2>支付方式</h2>
        <ul>
            <li>在线支付：下单确认后跳转支付页完成付款，支付成功后订单进入发货流程；</li>
            <li>货到付款：部分支持该方式的商品可在收货时向配送人员付款，具体以下单页可选项为准；</li>
            <li>支付完成后可在「我的订单」中查看订单支付状态；</li>
            <li>如遇支付异常，请勿重复提交订单，可先刷新订单状态或联系客服。</li>
        </ul>
    </section>

    <section id="after-sale">
        <h2>售后政策</h2>
        <ul>
            <li>7 天无理由：商品签收后 7 天内，在不影响二次销售的前提下可申请无理由退货；</li>
            <li>质量问题退换：商品存在质量问题的，可申请退换货，运费由商家承担；</li>
            <li>运费规则：无理由退货的来回运费由买家承担（商品质量问题除外）；</li>
            <li>退换商品请保持原包装、配件齐全，避免影响验货处理。</li>
        </ul>
    </section>

    <section id="return-flow">
        <h2>退换货流程</h2>
        <ol>
            <li>在订单详情中提交退换货申请，并说明原因；</li>
            <li>商家在收到申请后进行审核；</li>
            <li>审核通过后，按商家提供的退货地址寄回商品；</li>
            <li>商家收货验货；</li>
            <li>验货通过后办理退款或换货发货。</li>
        </ol>
    </section>

    <section id="refund">
        <h2>退款说明</h2>
        <ul>
            <li>退款将按原支付方式原路退回；</li>
            <li>退款到账时效视支付渠道而定，一般会在商家处理退款后数个工作日内到账；</li>
            <li>可在「我的订单」中查看退款处理进度；</li>
            <li>如超过预期时效仍未到账，请联系客服协助查询。</li>
        </ul>
    </section>

    <section id="contact">
        <h2>联系客服</h2>
        <ul>
            <li>站内消息：登录后可在个人中心通过站内消息与商家或平台沟通；</li>
            <li>客服邮箱：support@huimeng.example（示例占位邮箱，上线前替换）；</li>
            <li>咨询订单问题时，请一并提供订单号以便快速定位；</li>
            <li>客服工作时间内将尽快回复您的咨询。</li>
        </ul>
    </section>

    <section id="about">
        <h2>关于我们</h2>
        <ul>
            <li>惠蒙优选是一个精选优质商品的综合性电商平台，致力于为用户提供安心的购物体验；</li>
            <li>平台对入驻商家实行资质审核制度，商家须提交真实有效的经营资质，审核通过后方可开店销售；</li>
            <li>平台持续对商家商品与服务进行监督管理，保障消费者权益。</li>
        </ul>
    </section>

    <section id="merchant">
        <h2>商家入驻</h2>
        <ol>
            <li>注册平台账号；</li>
            <li>提交入驻申请及经营资质材料；</li>
            <li>平台审核资质；</li>
            <li>审核通过后开店并上架商品。</li>
        </ol>
        <p>入驻过程中如遇问题，请联系平台管理员协助处理。</p>
    </section>

    <section id="privacy">
        <h2>隐私政策</h2>
        <ul>
            <li>我们收集的信息：为完成购物服务，会收集您的账号信息、收货地址、订单与支付相关信息；</li>
            <li>信息使用：所收集信息仅用于订单履行、售后服务与平台运营改进，不会出售给任何第三方；</li>
            <li>信息安全：平台采取合理的安全措施保护您的个人信息，防止泄露、丢失或滥用；</li>
            <li>用户权利：您有权查询、更正自己的账户信息，如需注销账号可联系客服处理；</li>
            <li> cookie 与日志：我们可能记录必要的访问日志以保障服务安全与稳定。</li>
        </ul>
    </section>
</div>
<?php render_site_footer(); ?>
</body>
</html>
