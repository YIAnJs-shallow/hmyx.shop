# 惠蒙优选 · 项目路线图（ROADMAP）

> 负责人：Claude Code（授权见 PROJECT-LEAD.md）
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
| P2 | 异地备份 | ✅ 2026-10-17 闭环：私有仓库 github.com/YIAnJs-shallow/hmyx.shop，用户执行首次推送，本地与 origin/main 同步；CI 于 push 到 main 自动运行 |
| P2 | CI 闭环 | ✅ 2026-10-17 转绿：`4da34d8` 密钥生成去 DER 往返（修复 openssl wrong tag）、`71dffc7` 密钥目录 basename 对齐 project_private_config_dir()（修复 secrets.php 写错位置未加载）；远端 Actions 已绿 |

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

- **备份与推送分工（2026-10-17）**：远端 origin=github.com/YIAnJs-shallow/hmyx.shop（私有）；**push 一律由用户本人执行**，Claude 每完成一个模块在汇报中提醒"该 git push 了"；CI 于 push 到 main 自动触发
- **不迁移框架**：维持原生 PHP + lib 收敛，理由=迁移成本远大于收益，且项目规范禁止为小功能拖入框架级依赖
- **CI 用 shivammathur/setup-php@v2**（MIT，PHP 生态事实标准），不自写安装逻辑；测试密钥现场生成一次性 RSA，不入库
- **退款口径全站统一**：小计 − 券 − 积分抵现 − 会员折扣（shop.php / pay_return / cron_orders 三处一致）

## 下一步

1. 等用户从下一阶段方向提案中拍板（见会话汇报：首页推荐位管理 / 运营数据看板 / 优惠券玩法扩展）
2. 拍板后按规范先出开工方案（涉及依赖的先走开源对比表）
