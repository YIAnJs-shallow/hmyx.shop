# 惠蒙优选 · 项目路线图（ROADMAP）

> 负责人：Claude Code（授权见权威规范第八章「项目负责人权限」）
> 规范来源：`C:\Users\Lenovo\ai_operation_hobby.md`（唯一权威，不可修改）
> 断言基线：282 条（2026-10-17，tests/integration.php）

## 当前优先级（P0 → P2）

| 优先级 | 事项 | 状态 |
|---|---|---|
| P0 | CI 流水线 + migrate 核心表幂等建表 | ✅ 2026-10-16（commit 3f89bc9） |
| P0 | 支付与资金链路复核（回调验签/幂等/退款/对账） | ✅ 2026-10-16，发现并修复拼团退款漏减会员折扣（1ca36f2） |
| P1 | 售后退款链路复核（refund_requests / after_sale_requests 状态机与资金口径） | ✅ 2026-10-16 用户已批准并完成（只读复核，2 项口径问题待拍板） |
| P1 | 后台 RBAC 权限矩阵自查（13 权限 × 5 角色覆盖面） | ✅ 2026-10-16 用户已批准并完成（全项通过） |
| P2 | 商品搜索升级（MySQL 全文索引 vs 现有 LIKE，需开源对比表） | ✅ 2026-10-17 用户拍板：维持 LIKE，暂不升级 |
| P2 | 未跟踪文件处置：egg-carton-13.png | ✅ 2026-10-17 用户已自行删除 |
| P2 | pay_notify 增加 total_amount 与库内应付金额比对（最佳实践增强） | ✅ 2026-10-17 用户已批准并完成 |
| P1 | **方向 A·运营深度：后台搜索词分析看板**（search_logs 已积累数据，后台无分析页） | ✅ 2026-10-17 完成，正式库 DDL 已获批准并执行（results 列已验证存在） |
| P1 | **方向 A 第二项：后台优惠券管理**（复核发现 admin 无券管理页，券只能靠种子进库） | ✅ 2026-10-17 完成：admin/coupons.php + coupon.manage 权限（用户批准，矩阵 13→14），断言 268→274 |
| P1 | **方向 A 第三项：首页推荐位管理**（用户 2026-10-17 拍板方向 A） | ✅ 2026-10-17 完成（测试库 282 断言全绿；正式库建表 DDL 已获批准并执行（表已验证存在）） |
| P1 | **方向 B：运营数据看板**（用户 2026-10-17 拍板按推荐 B 执行） | ✅ 2026-10-17 完成（b815a3a，286 断言全绿） |
| P1 | **方向 C 第一项：结算页最优券推荐**（2026-10-17 自主推进；领券中心经核查 my_coupons.php 已含"可领取"区，不重复造页） | ✅ 2026-10-17 完成（290 断言全绿） |
| P1 | **方向 C 第二项：优惠券玩法扩展**（2026-10-17 用户下发定死方案：券适用范围/领取后有效期/详情页领券/后台新字段） | ✅ 2026-10-17 完成（2c33913，310 断言全绿；正式库 DDL 已于 2026-10-20 执行并验证） |
| P2 | 异地备份 | ✅ 2026-10-17 闭环：私有仓库 github.com/YIAnJs-shallow/hmyx.shop，用户执行首次推送，本地与 origin/main 同步；CI 于 push 到 main 自动运行 |
| P2 | CI 闭环 | ✅ 2026-10-17 转绿：`4da34d8` 密钥生成去 DER 往返（修复 openssl wrong tag）、`71dffc7` 密钥目录 basename 对齐 project_private_config_dir()（修复 secrets.php 写错位置未加载）；远端 Actions 已绿 |
| P1 | **任务一：图片搜索（拍照搜同款）**（2026-10-18 用户下发定死方案，只做执行） | ✅ 2026-10-19 全链路闭环（05223ad + 用户 b1af770 过滤修正；ARK Key 已配置，远端已同步） |
| P1 | **任务二：预售定金（定金+尾款两段支付）**（2026-10-18 用户下发定死方案，只做执行） | ✅ 2026-10-19 全链路闭环（de08f0b，347 断言全绿；正式库 DDL 已执行并验证 5 列存在） |
| P1 | **正式库 migrate 积压清零**（P0，2026-10-20 用户确认执行） | ✅ 2026-10-20 执行 scripts/migrate.php 并逐项验证：10 处加列 + 六档种子全部在位，378 断言复跑全绿 |
| P1 | **方向 A·安全加固专项：认证接口限流 + 高德配额防护**（2026-10-20 用户拍板；盘点结论：CSRF 全局强制✅、写接口归属校验✅、邮箱码/找回密码按邮箱窗口✅，缺口=密码登录零限流+IP 维度缺失） | ✅ 2026-10-20 完成（391 断言；正式库 rate_limit_hits 已建） |
| P1 | **首页京东式改版专项（A+B）**：左侧分类导航 + 右侧悬浮 dock + 楼层电梯 + 秒杀楼层 + 卡片体验（2026-10-21 用户拍板，参考京东/淘宝） | 🚧 进行中 |
| P2 | **每日签到送积分（C）**：A+B 完成后执行；日签 +7 分连续递增 7 天封顶，日历视图，复用积分体系 | 📋 已排期 |
| P2 | **备选**：详情页"看了又看"+评价晒图（D）；站内客服会话（E）——等用户点名 | 📋 备选池 |

### 开工方案：首页京东式改版专项（A+B，2026-10-21 用户拍板）

- **目标**：① 首页左侧常驻分类导航（一级分类竖排，hover 展开二级面板，点击进筛选结果，窄屏隐藏）；② 全前台右侧悬浮 dock（回到顶部/我的订单/购物车带数量角标/帮助，滚动后浮现）；③ 首页楼层电梯导航（圆点点击平滑定位、滚动高亮）；④ 秒杀楼层（后台配置商品/秒杀价/限量/场次，前台倒计时+已抢进度条，点击进详情页）；⑤ 商品卡片 hover 抬升 + 图片懒加载。
- **决策定死**：新表 `seckill_items`（id/product_id FK CASCADE/seckill_price_cents/total_stock/sold/start_at/end_at/sort/status/created_at，KEY(start_at,end_at,status)）；秒杀楼层=引流展示位（sold/total 配置制进度），**不新增下单/资金路径，不改 detail.php 价格逻辑**；后台页 admin/seckill.php 复用 coupon.manage 权限（不新增权限码），CRUD 仿 admin/coupons.php（校验下沉 lib/shop.php admin_save_seckill，分转元入口、start<end、商品须存在）；dock 购物车角标=SUM(cart.quantity)（登录态）；电梯导航由 JS 扫描 [data-floor] 生成；楼层锚点=秒杀/三推荐位/热销榜/猜你喜欢。
- **改动**：scripts/migrate.php、lib/shop.php（seckill_active_items/admin_save_seckill/admin_toggle_seckill/page_end dock）、admin/seckill.php（新页）、index.php、css/shop.css（v6）、tests/integration.php。
- **负面清单**：不加独立下单/支付/资金路径、不改 detail.php、不动推荐位/搜索/券逻辑、不新增权限码、不引入依赖、不做真实秒杀库存扣减、不加后台导航菜单（与其他后台新页一致）、窄屏仅隐藏左栏不做抽屉。
- **验收口径**：php -l 全部改动文件；php tests/integration.php 398 只增不减（新增：seckill 表存在、后台权限授予/拒绝、保存校验（非法价格/时序/商品拒绝）、前台楼层渲染/倒计时落点/进度条、dock/电梯/分类导航/懒加载落点）；正式库建表 DDL 测试通过后请示执行。

### 开工方案：方向 A·认证接口限流（2026-10-20，用户拍板）

- **目标**：堵住恶意用户视角的三个缺口——① 密码登录可无限爆破；② 发码/找回接口缺 IP 维度限制（换邮箱轰炸）；③ addresses.php 高德定位接口可刷配额。
- **方案**：lib/security.php 新增 `rate_limit_ok / rate_limit_hit / rate_limit_clear / client_rate_ip`（DB 计数表 bucket_key=sha256(桶名)，窗口 COUNT+INSERT，顺带清理 2 天前旧行）；migrate.php 幂等建表 `rate_limit_hits`。限流口径（对齐现有 10 分钟码 TTL / 60 秒重发 / 30 分钟锁定）：密码登录按账号 10 次失败/10 分钟 + 按 IP 30 次失败/10 分钟（只记失败、成功清零，避免误伤办公网 NAT）；发码类（email_login_code/forgot_password/register）按 IP 20 次/小时（按邮箱窗口既有，不动）；高德定位按 IP 120 次/小时。被限流时统一友好提示，不暴露技术细节；被限流的登录请求不进凭据校验（防时间侧信道）。
- **改动**：lib/security.php、scripts/migrate.php、user_login.php、email_login_code.php、forgot_password.php、register.php、addresses.php、tests/integration.php。
- **负面清单**：不引入依赖、不动既有 email_login_codes/password_reset_codes 的按邮箱窗口、不改 admin 后台登录、不加验证码图形改造、不删 login_process.php（死文件，另行请示）、不动 CSS/JS。
- **验收口径**：php -l 全部改动文件；断言 378 只增不减（新增：限流表存在、rate_limit_hit 计满拒绝/清空恢复、登录失败计数与成功清零、限流后登录被挡、发码 IP 限流、找回 IP 限流、定位 IP 限流）；正式库建表 DDL 上线前请示。
- **发现（盘点附带）**：login_process.php 为无引用死文件（删除需用户确认）；addresses.php 归属校验齐全无越权。
| P1 | **个人中心重组 + 会员体系六档升级**（2026-10-19 用户提案，方向性输入，负责人自主规划） | ✅ 2026-10-19 完成（358 断言全绿；正式库 DDL 已于 2026-10-20 执行并验证） |
| P1 | **个人中心京东化改版（枢纽 Dashboard + 左侧栏）**（2026-10-19 用户提案，结构定死） | ✅ 2026-10-19 完成（365 断言全绿；无 DDL 无依赖） |
| P1 | **首页导航栏京东式头像下拉菜单（个人中心唯一入口）**（2026-10-19 用户提案，交互定死） | ✅ 2026-10-19 完成（368 断言全绿；无 DDL 无依赖） |
| P1 | **头像点击目标修正直达 profile.php + 可点性修复**（2026-10-19 用户提案，改动定死） | ✅ 2026-10-19 完成（369 断言全绿；css ?v=2） |
| P1 | **全站 PHP 响应统一禁缓存**（2026-10-19 用户提案，治"改版后浏览器死缓存"根因） | ✅ 2026-10-19 完成（371 断言全绿；curl 实测 HTML 带 no-cache、静态资源不受影响） |

### 开工方案：个人中心重组 + 会员体系六档升级（2026-10-19，用户提案，默认自主执行）

- **目标**：① 等级 3 档 → 6 档（白银→黄金→铂金→白钻→黑钻→至尊），id 语义不变（普通→白银、银卡→黄金、金卡→铂金，折扣不变存量无感）；② user_center.php 定位"个人中心"导航枢纽（含"修改个人资料"板块，表单逻辑零改动），新增 member.php"我的会员"独立子页（档位 Hero + 进度条 + 六档权益对比表），解决徽章挤在头像旁的局促问题。
- **六档设计**：门槛 ¥0/500/2000/5000/10000/20000；折扣 100/98/95/93/92/90；积分倍率 points_rate_x10（10/10/10/11/12/15，白钻起加成，确认收货发分按当时等级倍率向下取整）。差异化奖励落在现有能力（折扣+积分），不做等级专属券/升级礼包券（需 min_level 列与领券门禁，列后续可选）。
- **改动**：① migrate.php：user_levels 种子改 6 档（INSERT IGNORE 带 id + 按 id 幂等 UPDATE 改名/改门槛）、add_column points_rate_x10；② lib/level.php 加 levels_all / level_progress；③ lib/shop.php 确认收货发分接倍率（rate=10 时与既有口径完全一致）；④ user_center.php 会员区块收敛为单行入口条跳 member.php；⑤ 新页 member.php（登录门禁仿 points.php，CLI 拒绝提示）；⑥ points.php/help.php 文案同步；⑦ tests：阈值断言改 6 档 + 新增倍率发分、member.php 渲染、个人中心入口条断言。
- **负面清单**：不加后台等级管理页、不新增权限码、不新增依赖、不动资金链路（折扣/退款/对账口径零改动）、不做等级专属券、不动 css/js 文件（新页内联样式，无需 ?v 递增）。
- **验收口径**：php -l 全部改动文件；php tests/integration.php 断言 347 只增不减全绿（套件自带 migrate 连跑两次幂等验证）。
- **边界**：正式库 DDL（user_levels 种子行级更新 + points_rate_x10 加列）测试通过后展示，等用户确认执行 migrate。
- **完成情况**：✅ 2026-10-19 完成，358 断言全绿（347→358，+11：六档种子 3、member.php 门禁/Hero/六档列表/当前档高亮/进度条/至尊顶档 6、白钻折扣 1、白钻倍率发分 1）。执行中失误三次并均已当场修正：① lib/level.php 与 member.php 各有一次编辑输出损坏（git restore + 重写恢复，另误建一个项目外临时目录文件已删、空目录留待用户手删）；② 测试断言对 name 列误加 (int) 强转、③ SELECT 漏 points_rate_x10 列。遗留：正式库 DDL 待确认执行 migrate（user_levels 行级种子更新 + points_rate_x10 加列）；个人中心会员区块收敛为单行入口条，表单逻辑零改动。

### 开工方案：个人中心京东化改版（2026-10-19，用户提案，结构定死）

- **目标**：user_center.php 升级为京东"我的京东"式枢纽 Dashboard（通栏 Hero 用户卡 + 订单四宫格 + 资产宫格 + 浏览足迹），左侧栏导航（个人中心与子页共用、当前项高亮、窄屏折叠为横向 tab），资料表单拆出独立子页 profile.php，member.php 并入左侧栏体系。
- **改动**：① 新建 css/user_center.css?v=1（共享样式，移动端媒体查询折叠侧栏）与 lib/uc.php（uc_page_start/uc_page_end/uc_nav_items/badge_html，function_exists 守卫）；② 新页 profile.php：user_center 资料表单与 POST 逻辑整体迁移（CSRF/动态列检测/save_image_upload/verify_account_password 口径不变，action 改 profile.php）；③ user_center.php 重写为 Dashboard：Hero（头像/昵称/角色与六档徽章/我的会员入口/进度条）+ 我的订单四宫格（待付款/待发货/待评价=completed 未评价经 reviews.order_item_id 判定/退款售后=pending 退款与售后单）+ 我的资产宫格（收藏/优惠券/积分/地址/拼团，COUNT 徽标）+ 浏览足迹；未登录渲染登录引导卡；④ member.php 换 uc 壳（内容与 mm- 类名不变，返回链接指 user_center.php）；⑤ tests：门禁/Hero/宫格/侧栏/表单迁移断言，358→365。
- **负面清单**：不新增依赖、无 DDL、不动各子页（my_orders/points/groups 等）内部逻辑、不改资金链路、表单校验口径零变更。
- **边界说明**：侧栏在用户定死的 7 项外，为商户/管理员追加条件项"商铺管理/商家发货"（普通用户不可见），否则商户失去入口——如不需要可下轮去掉。
- **完成情况**：✅ 2026-10-19 完成，365 断言全绿（358→365，+7）；执行中失误一次（待评价计数误用不存在的 order_items.review_id 列，报错后改为 LEFT JOIN reviews 口径）并当场修正；复跑稳定。旧 user_center.php 资料表单无外部专用入口（全站链接均指枢纽页），故无需旧 URL 兼容垫片。

### 开工方案：首页导航栏京东式头像下拉菜单（2026-10-19，用户提案，交互定死）

- **目标**：首页导航登录态"👤 个人中心"文字链接替换为 头像缩略图 + 用户名 组合，悬停/聚焦弹出下拉菜单（我的会员/修改资料/我的订单/退出登录），点击头像或用户名进入 user_center.php；未登录"登录 / 注册"不变。个人中心内部结构（lib/uc.php 侧栏壳 + 三页）保持不动。
- **改动**：① index.php 导航区加 user-menu 结构（头像读 users.avatar，空值回退默认图标；纯 CSS hover/focus-within 展开 + transition，无 JS）；② 新建 css/user_menu.css?v=1（深空 Hero 主题配色、悬停桥防闪烁、窄屏只留头像并右对齐防溢出）；③ tests +3（登录态头像与下拉锚点、退出与触发链接、未登录无下拉），365→368。
- **取舍说明**：无 JS 前提下触屏"点击头像展开菜单"与"点击头像进入个人中心"互斥，按"全站唯一入口"目标取舍为点击头像/用户名均直达 user_center.php；支持 hover-tap 的触屏浏览器首次点按仍先展开菜单（CSS 注释中已注明）。
- **负面清单**：不新增依赖、不改 lib/uc.php 与个人中心三页、无 DDL、不动其他页面文字入口、不做全站导航统一。
- **完成情况**：✅ 2026-10-19 完成，368 断言全绿（365→368），复跑稳定；执行零失误。

### 完成汇报：头像点击目标修正 + 可点性修复（2026-10-19，用户提案，改动定死）

- **做了什么**：① index.php `.user-trigger` href 改指 profile.php（点头像/用户名直达"账户设置"式修改资料页）；下拉调整为 个人中心（user_center.php）/ 我的会员 / 我的订单 / 退出登录 四项（修改资料项移除，触发本体即入口）；② 可点性排查与防御修复：header 为 sticky + z-index:1000 的层叠上下文（style.css:58-60），下拉 z-index:60 在其内部不会被 Hero canvas 遮挡、overflow 未裁剪——层叠链无阻塞；"感觉不可点击"的最可能吞点击点已消除：`.user-dropdown::before` 悬停桥由 -8px 收窄为精确 -6px 贴缝（不再上探 2px 遮挡触发区底部）、`.user-trigger` 提至 z-index:61 并显式 cursor:pointer、头像图与用户名文字加 pointer-events:none 使点击必落触发链接本体、hover 反馈增强（#e2e8f0）；css/user_menu.css 升 ?v=2 破缓存；③ tests：触发目标/下拉四项断言改写 + "修改资料项已移除"反断言，368→369。
- **边界**：未做浏览器实测（本机无自动化浏览器手段），已按层叠上下文链路排查并做防御修复；若用户实测仍不可点，请 F12 检查是否仍有元素覆盖触发区，把截图/元素信息反馈后继续排查。
- **负面清单**：不动 lib/uc.php、user_center.php、profile.php；无 DDL；不加 JS。

### 完成汇报：全站 PHP 响应统一禁缓存（2026-10-19，用户提案，改动定死）

- **做了什么**：① config/db.php 顶部（全站统一入口：前台经 lib/shop.php、后台经 admin/inc/auth.php、AJAX/JSON/验证码出口直接 require）统一发送 `Cache-Control: no-cache, must-revalidate` + `Pragma: no-cache`，守卫 `PHP_SAPI !== 'cli' && !headers_sent()`（CLI 的 tests/cron 不发头）；② 例外链路单独补头：help.php（纯静态内容页不连库，require lib/site_footer.php 不经过 db.php）；③ 五个 301/303 旧书签跳转文件（admin.php 等）无 HTML 正文不需处理；静态资源（css/js/img/uploads）策略未动，仍走 Apache 协商缓存 + ?v=N 管变更。
- **验证**：php -l 两文件全过；371 断言全绿（369→371，+2：db.php 入口守卫与头、help.php 独立链路；整套 CLI 渲染/cron 断言跑通本身即 CLI 不报错实证）；curl 实测正式库站点：index.php / user_center.php / help.php 均返回 no-cache 头，css/user_menu.css 无该头（静态策略不变）。
- **负面清单**：不动 .htaccess/Apache 配置、不改静态资源缓存策略、不加 JS、无 DDL。

### 开工方案：后台搜索词分析看板（2026-10-17，默认自主执行）

- **目标**：后台可看近 30 天搜索词聚合（次数/无结果次数/平均命中/最近时间）与"无结果词"缺口列表，指导选品与补词。
- **改动**：① migrate.php `add_column('search_logs','results','int NULL ...')`（幂等，历史行 NULL=未统计）；② index.php 写搜索日志时同步记录关键词独立命中数（口径=status=1+approved+有效店铺+title/detail LIKE，addcslashes 转义）；③ 新页 admin/search_logs.php，鉴权复用 `dashboard.view`（不新增权限码）；④ tests 新增 3 条断言（265→268）。
- **负面清单**：不动现有搜索主查询/联想/热门搜索逻辑、不新增权限码、不加缓存表、不引入图表库、不动 migrate 其他表。
- **边界**：results 列 DDL 上正式库前须向用户展示并确认（测试库先行）。

### 开工方案：后台优惠券管理（2026-10-17，补记；用户批准新增 coupon.manage 权限）

- **目标**：后台可创建优惠券（标题/面额分/门槛分/总量/有效期/新人券标记）、上下架切换、查看领取与核销统计。
- **改动**：① migrate.php 幂等 INSERT 权限 `coupon.manage`（营销模块）+ 授权 normal_admin；② admin/coupons.php 列表与创建/上下架（金额全分口径，POST 走 303 重定向）；③ lib/coupon.php 新增 `admin_create_coupon`（校验下沉，仿 create_review 模式），审计由页面记录；④ tests 新增 6 条断言（268→274：矩阵授予/拒绝、创建持久化、非法标题与过期时间拒绝、列表渲染）。
- **负面清单**：不动领券/核销/回补逻辑、不动前台任何页面、不加删除功能（有价证券物宁缺勿删）、不引入依赖。
- **边界**：无 DDL、无新依赖；权限矩阵 13→14 经用户批准（2026-10-17）。
- **完成情况**：✅ 已完成并提交 5948cef，274 断言全绿；正式库 migrate 已执行并验证授权生效。执行中失误一次：调试脚本误将测试券写入正式库，已当场清理（0 领取、删 1 行、无残留，审计日志按 append-only 保留），已向用户如实报告。

### 开工方案：首页推荐位管理（2026-10-17，用户拍板方向 A；默认自主执行，DDL 上正式库前确认）

- **目标**：后台为三个固定楼位（today 今日推荐 / new 新品速递 / clearance 清仓特卖）配置在售商品，前台首页在无搜索/筛选条件时按楼位渲染卡片（每楼位最多 8 个，sort 升序）。
- **改动**：① migrate.php 幂等建表 `home_recommendations`（id / slot varchar(20) / product_id / sort int / created_at，UNIQUE(slot,product_id)，KEY(slot,sort)，FK→products ON DELETE CASCADE）；② lib/shop.php 新增 `home_recommend_slots()`（楼位白名单与标题）、`home_slot_products($slot,$limit)`（可见口径 JOIN）、`admin_set_recommendation` / `admin_remove_recommendation` / `admin_move_recommendation`（上移下移=与相邻行交换 sort）；③ 新页 admin/recommendations.php，鉴权复用 `product.manage`（不新增权限码）；④ index.php 在无 keyword/分类/筛选时渲染三楼位 section（复用现有卡片 class，不改 CSS）；⑤ tests 新增 4 条断言（274→278）。
- **负面清单**：不动热销榜/猜你喜欢逻辑、不动 CSS/JS（无需 ?v 递增）、不新增权限码、不做定时上下架、不引入依赖。
- **边界**：建表 DDL 上正式库前须向用户展示并确认（测试库先行验证）。

### 开工方案：运营数据看板（2026-10-17，用户拍板按推荐 B 执行；默认自主执行）

- **目标**：后台可看近 7/30 天核心经营指标：订单数、GMV（实付分口径 = total_amount − discount_amount − points_discount_cents − member_discount_cents，与退款口径决策一致，作用于 paid/shipped/completed）、订单状态分布、支付方式分布、销量 TOP10 商品（order_items 聚合）、退款率（refund_requests approved / 已支付单）。
- **改动**：① lib/shop.php 新增 `admin_stats_overview()`（纯读聚合，返回各维度数组）；② 新页 admin/stats.php，鉴权复用 `dashboard.view`（不新增权限码），纯表格渲染不引图表库；③ 不加后台导航菜单（search_logs/coupons 亦未入菜单，保持一致）；④ tests 新增 4 条断言（282→286）。
- **负面清单**：不改 admin/inc/header.php 菜单、不动 CSS/JS（无需 ?v 递增）、不新增权限码、无 DDL、无新依赖、不改任何写路径/资金逻辑（纯读）。
- **边界**：全部为只读 SQL 聚合，零风险；时间窗口统一 NOW() - INTERVAL N DAY 口径。
- **完成情况**：✅ 2026-10-17 完成（b815a3a）。admin_stats_overview() 下沉 lib/shop.php，admin/stats.php 复用 dashboard.view 鉴权，断言 286 全绿（含 GMV 分口径独立复算比对）。无 DDL、无依赖、纯读。

### 开工方案：结算页最优券推荐（方向 C 第一项，2026-10-17，默认自主执行）

- **目标**：结算页 GET 首次打开时默认选中面额最大的可用券（coupons_for_user 已按面额降序，封顶后仍最优），并提示"已为你选中最优惠的优惠券"；POST 用户选择（含"不使用"）严格保持原有优先级不变。
- **改动**：仅 checkout.php——默认选中逻辑 + 一行提示；不动 coupons_for_user / coupon_consume / 任何金额计算、积分、拼团分支。
- **发现**：my_coupons.php 本已含"可领取的优惠券"领取区（领券中心已存在），故不重复造独立领券页。
- **负面清单**：不改 lib/coupon.php 任何逻辑、不加新页面、不加权限码、无 DDL、无依赖、不动 CSS/JS。
- **边界**：资金金额计算逻辑零改动，仅默认下拉选中项变化（用户可改选"不使用"），属展示层；断言 286→290（GET 默认最优、提示渲染、POST 显式不使用不被覆盖、无券用户无提示）。
- **完成情况**：✅ 2026-10-17 完成，290 断言全绿。调试中一次失误：断言误将 option value 比对为券 id（实为 user_coupons.id），已修正为按券名与面额断言。

### 完成汇报：图片搜索（任务一，2026-10-18，用户下发定死方案）

- **做了什么**：① 新建 lib/image_search.php：调方舟 OpenAI 兼容接口（ARK_API_URL=https://ark.cn-beijing.volces.com/api/v3/chat/completions，模型 doubao-seed-2-0-lite-260428），密钥 ARK_API_KEY 读 Web 根目录外 private/my_project/secrets.php（未配置/调用失败优雅降级返回 null）；响应防御解析 image_search_sanitize（提取 JSON 数组、仅留中文/字母/数字/空格、去重、每词截 12 字、最多 8 词）；image_search_results 逐词 LIKE 检索（可见口径/匹配列/排序与 index.php 一致）；② 新页 search_image.php：上传校验复用 save_image_upload 口径（≤5MB、仅 jpg/png/webp、getimagesize 验证），图片读为 base64 后临时文件用后即删不落盘，结果页复用现有卡片渲染 + 关键词跳转完整搜索；③ index.php 搜索框旁加"📷 拍照搜同款"入口；④ config/secrets.example.php 加 ARK_API_KEY 注释占位，private/secrets.php 追加空值占位常量（未读取该文件内容，仅验证可解析）；⑤ 测试注入口 IMAGE_SEARCH_OVERRIDE（同 ALIPAY_GATEWAY_OVERRIDE 惯例）。
- **验证**：php -l 五文件全过；320 断言全绿（310→320，+10：override 清洗/8 词截断/非法响应降级/无密钥降级/关键词检索命中与空结果/页面渲染与类型大小拒绝/首页入口）。
- **遗留**：真实 ARK Key 由用户填入 private/secrets.php 的 ARK_API_KEY（当前空值=功能自动降级为"暂不可用"）。

### 完成汇报：预售定金（任务二，2026-10-18，用户下发定死方案）

- **做了什么**：① migrate.php 幂等加列 products.presale/deposit_cents/presale_end_at、orders.pay_stage(enum full/deposit/balance)/paid_cents；② lib/shop.php：parse_presale_fields（服务端权威校验定金>0 且<售价、截止晚于当前，未勾选保存即清空）、order_payable_cents（普通单口径不变；定金阶段=定金×数量，尾款=total−已付）、create_orders 加 $presale 通道（锁内校验预售商品/无规格/截止/定金配置，禁券/积分/拼团/购物车路径，免会员折扣，pay_stage='deposit' 入库）、change_order pay 拆分（定金支付后保持 pending 记 paid_cents；补尾款后 pay_stage='balance' 置 paid 走正常流程）、cancel 拒绝已付定金单、expire_orders 排除已付定金单；③ cron_orders.php：3 小时关单同样排除已付定金单，新增 cron_close_presale_balance_timeout（已付定金超 7 天未付尾款自动关单，沙箱走 alipay.trade.refund 失败留待重扫，演示支付直接关单）；④ pay.php/pay_notify.php/pay_return.php 统一改用 order_payable_cents（非预售单金额不变）并展示预售阶段信息（3 小时倒计时仅普通单渲染）；⑤ my_orders 待付尾款徽章+尾款金额/截止+补尾款按钮；detail.php 预售标记/定金/截止+隐藏加购；checkout.php 预售自动识别（禁券/积分/会员折扣，应付=定金×数量，展示两段说明）；cart.php 加购拒绝预售商品；product_edit.php（管理员与商户共用）加预售开关/定金/截止三字段（顺带修复 admin 分支 bind_param 16 类型对 15 参数的原有错位）。
- **验证**：php -l 十二文件全过；347 断言全绿（320→347，+27：后台字段校验 6、下单拒绝 6、定金订单与阶段应付 2、定金支付 1、3h 窗口豁免 1、补尾款+正常收发货 2、不可自取消 1、7 天超时关单退定金 3、未付定金仍走 3h 1、沙箱定金回调 1、详情/订单/结算页面落点 3）。
- **遗留**：正式库 DDL（5 列幂等加列）待用户确认后执行 scripts/migrate.php；预售暂限无规格商品（服务端拒绝多规格预售，与拼团同口径）。

### 完成汇报：优惠券玩法扩展（方向 C 第二项，2026-10-17，用户下发定死方案）

- **做了什么**：① migrate.php 幂等加列 coupons.scope / coupons.product_id / coupons.valid_days（测试套件连跑两次 migrate 已验证幂等）；② lib/coupon.php：coupons_for_user 增加范围过滤（可选参数，null 保持既有行为）与 valid_days 过滤、新增 coupon_user_expired（时点比较，status 枚举不动）、coupon_consume_locked 增加可选 $orderProductIds 参数并在金额计算前做范围+有效期权威校验、admin_create_coupon 扩展白名单校验（scope 枚举/指定商品须存在/有效天数 1-3650）；③ create_orders 与 checkout.php 传入订单商品清单；④ detail.php 领券区块（全场+指定本商品券、登录一键领取复用 coupon_claim、未登录 user_login.php?next= 带回跳）；⑤ my_coupons.php 过期券显示【已过期】（记录保留）；⑥ admin/coupons.php 表单加"适用商品"下拉（默认全场）与"领取后有效天数"，列表加两列。
- **验证**：php -l 七文件全过；php tests/integration.php 310 断言全绿（290→310，+20）；未触正式库。
- **执行中失误（均已当场修正）**：一次 Edit 引入乱码文本（min_spend_cents列出）已修复；一次 helper 重名（render_detail_page 已有既有版本，改用既有版本）；一次测试反例误用已核销券记录（改为独立第二张指定券）；一次断言漏先领券。
- **边界**：正式库 DDL（3 列幂等加列）待用户确认后执行 scripts/migrate.php。

## 已完成（近期）

- `（本次）` pay_notify 金额比对增强（用户已批准）：回调 total_amount 与库内应付比对（口径同 pay.php），不符应答 fail 并记日志；新增 ALIPAY_VERIFY_KEY_OVERRIDE 测试注入口径（同 GATEWAY_OVERRIDE 惯例，不触 secrets）；断言 263→265 全绿
- `cecd153` 规范文件改版检查点提交（AGENTS.md 指针化 + CLAUDE.md + PROJECT-LEAD.md）
- `（本次）` 售后口径修复：① 退款三处（仅退款/退货退款/拼团超时）事务内返还积分抵扣，复用 grant_points 同向幂等；② 退货退款不再积累等级消费——shipped 订单售后完成置 cancelled（与仅退款/拼团口径一致），已 completed 订单保持完成态（终身制不扣减）。断言 259→263 全绿
- `7df431f` P1 复核完成标记 + 2 项口径问题记录
- `3f89bc9` CI 流水线 + migrate 核心表幂等建表（空库可从零初始化，本机模拟 CI 全链路 259 断言全绿）
- `90c9f8c` 入库 AI 协作配置（.cursor / .github）
- `1ca36f2` 修复拼团超时退款漏减会员折扣，新增 2 条断言（257→259）
- `808a211` 会员等级体系、`543220c` 拼团功能（历史会话交付）

## 待拍板：商品搜索升级对比表（2026-10-16，只调研未写代码）

现状：`index.php:58` 用 `title LIKE %kw% OR detail LIKE %kw%`，products 表当前仅 **21 行**、MySQL 8.0.30。

| 方案 | Star | 维护 | 许可证 | 环境兼容 | 集成成本 | 结论 |
|---|---|---|---|---|---|---|
| 维持现状 LIKE | — | — | — | 零依赖 | 0 | 数据量小时性能与命中率均够用 |
| MySQL 内置 FULLTEXT ngram | MySQL 8 自带 | 官方 | 无新增 | InnoDB 原生支持 | 低：加 FULLTEXT 索引（DDL）+ 改 SQL 为 MATCH…AGAINST，单字搜索需 LIKE 兜底 | 推荐（若要升级） |
| TNTSearch（teamtnt） | 3.2k | 2026-08 仍有提交 | MIT | PHP 库，SQLite FTS | 中：需维护索引同步逻辑 | 集成成本接近现写，不引入 |
| Meilisearch | 59.6k | 活跃 | MIT | 独立常驻服务 | 高：需常驻进程+同步管道 | 为小功能拖入服务级依赖，不引入 |
| Typesense | 27k | 活跃 | GPL-3.0 | 独立服务 | — | 许可证红线，排除 |

推荐：商品量 < 千级前维持 LIKE；若拍板升级，选 MySQL FULLTEXT ngram（FULLTEXT + LIKE 兜底混合）。

## 阻塞项 / 待用户决策

- 无阻塞项；push 由用户执行（见下方推送协作规则），CI 待每次 push 后自动校验

## 推送协作规则（2026-10-17 用户确认）

- 远端仓库：github.com/YIAnJs-shallow/hmyx.shop（main），首次推送已完成，本地与 origin/main 同步。
- **push 由用户（或经用户指示的灵犀）执行；Claude 绝对禁止 push 不变**。每完成一个模块提交后，在汇报末尾提醒一行"已有 N 个本地提交未推送"。
- CI 在 push 到 main 时自动运行，若 Actions 红灯，把失败日志要点列入下次汇报。

## 决策记录

- **提案制分工生效（2026-10-19，PROJECT-LEAD.md 修订 0032e84）**：用户只提提案（一句话即可，不补全需求），负责人统筹发展方向/功能路线/任务拆解与发放，全流程"分析 → ROADMAP 定优先级 → 开工方案 → 执行 → 验收汇报"自主完成
- **备份与推送分工（2026-10-17）**：远端 origin=github.com/YIAnJs-shallow/hmyx.shop（私有）；**push 一律由用户本人执行**，Claude 每完成一个模块在汇报中提醒"该 git push 了"；CI 于 push 到 main 自动触发
- **不迁移框架**：维持原生 PHP + lib 收敛，理由=迁移成本远大于收益，且项目规范禁止为小功能拖入框架级依赖
- **CI 用 shivammathur/setup-php@v2**（MIT，PHP 生态事实标准），不自写安装逻辑；测试密钥现场生成一次性 RSA，不入库
- **退款口径全站统一**：小计 − 券 − 积分抵现 − 会员折扣（shop.php / pay_return / cron_orders 三处一致）

## 下一步

1. 等用户从下一阶段方向提案中拍板（见会话汇报：首页推荐位管理 / 运营数据看板 / 优惠券玩法扩展）
2. 拍板后按规范先出开工方案（涉及依赖的先走开源对比表）
