<?php
/**
 * 敏感配置模板（入库参考用）
 * 使用方法：复制到 <phpStudy root>/private/my_project/secrets.php 后填入真实值；目标目录必须位于 WWW 和 Web 根目录之外
 */

// 高德开放平台 Web服务 Key（服务端：地理编码/静态地图，express_detail 物流页使用）
// 申请地址：https://console.amap.com/dev/key/app
define('AMAP_KEY', '');

// 高德开放平台 Web端(JS API) Key + 安全密钥（前端交互式物流地图；两个值必须来自同一"Web端(JS API)"类型 Key）
// 未配置时物流页自动降级为 AMAP_KEY 的静态路线图，互不影响
define('AMAP_JS_KEY', '');
define('AMAP_JS_SECURITY', '');

// SMTP 发件配置（用于找回密码验证码邮件；也可改用同名环境变量）
// 以 QQ 邮箱为例：SMTP_HOST=smtp.qq.com，SMTP_PORT=465，SMTP_SECURE='ssl'
// SMTP_PASS 不是 QQ 密码，而是“授权码”：QQ 邮箱网页版 → 设置 → 账号 → 开启 SMTP 服务 → 生成授权码
define('SMTP_HOST', '');        // SMTP 服务器地址，如 smtp.qq.com / smtp.163.com
define('SMTP_PORT', 465);       // 端口：SSL 一般 465，STARTTLS 一般 587
define('SMTP_SECURE', 'ssl');   // 加密方式：'ssl' 或 'tls'（STARTTLS）
define('SMTP_USER', '');        // 发件邮箱账号，如 xxx@qq.com
define('SMTP_PASS', '');        // SMTP 授权码（QQ/163 等邮箱在设置中生成的授权码，非登录密码）
define('MAIL_FROM', '');        // 发件人地址，一般与 SMTP_USER 相同
define('MAIL_FROM_NAME', '惠蒙优选'); // 发件人显示名

// 管理后台 IP 白名单：逗号分隔的 IPv4/IPv6 字面量精确匹配
// 非白名单 IP 访问 admin/ 目录任何入口（含登录页）一律返回 404；未定义该常量时默认仅允许本机
// 示例：'127.0.0.1,::1,192.168.1.100'（支持 IPv4 与 IPv6 字面量，不支持网段）
define('ADMIN_ALLOWED_IPS', '127.0.0.1,::1');

// ===== 支付宝沙箱支付 =====
// 来源：支付宝开放平台（open.alipay.com）→ 控制台 → 沙箱环境 → 自定义密钥（RSA2/SHA256WithRSA）
// app_id 为沙箱应用 APPID；gateway 为沙箱网关；两把密钥均为不含 PEM 头尾的 Base64 单行文本
define('ALIPAY_SANDBOX_APP_ID', '');          // 沙箱应用 APPID
define('ALIPAY_GATEWAY', '');                 // 沙箱网关地址
define('ALIPAY_APP_PRIVATE_KEY', '');         // 应用私钥（PKCS8，Base64 单行）
define('ALIPAY_ALIPAY_PUBLIC_KEY', '');       // 支付宝公钥（Base64 单行，用于验签）

// ===== 火山方舟（拍照搜同款） =====
// 来源：火山引擎控制台 → 方舟（Ark）→ API Key 管理；Key 以 ark- 开头
// 用于 search_image.php：调视觉模型生成商品搜索关键词；留空/未定义时图片搜索功能自动降级为"暂不可用"，不影响正常搜索
define('ARK_API_KEY', '');
