# 惠蒙优选 · 项目路线图（ROADMAP）

> 负责人：Claude Code（授权见 PROJECT-LEAD.md）
> 规范来源：`C:\Users\Lenovo\ai_operation_hobby.md`（唯一权威，不可修改）
> 断言基线：259 条（2026-10-16，tests/integration.php）

## 当前优先级（P0 → P2）

| 优先级 | 事项 | 状态 |
|---|---|---|
| P0 | CI 流水线 + migrate 核心表幂等建表 | ✅ 2026-10-16（commit 3f89bc9） |
| P0 | 支付与资金链路复核（回调验签/幂等/退款/对账） | ✅ 2026-10-16，发现并修复拼团退款漏减会员折扣（1ca36f2） |
| P1 | 售后退款链路复核（refund_requests / after_sale_requests 状态机与资金口径） | ⬜ 待做 |
| P1 | 后台 RBAC 权限矩阵自查（13 权限 × 5 角色覆盖面） | ⬜ 待做 |
| P2 | 商品搜索升级（MySQL 全文索引 vs 现有 LIKE，需开源对比表） | ⬜ 需用户拍板 |
| P2 | 未跟踪文件处置：egg-carton-13.png（入库或删除，删除需用户确认） | ⬜ 待用户定 |
| P2 | pay_notify 增加 total_amount 与库内应付金额比对（最佳实践增强） | ⬜ 记录未做 |

## 已完成（近期）

- `3f89bc9` CI 流水线 + migrate 核心表幂等建表（空库可从零初始化，本机模拟 CI 全链路 259 断言全绿）
- `90c9f8c` 入库 AI 协作配置（.cursor / .github）
- `1ca36f2` 修复拼团超时退款漏减会员折扣，新增 2 条断言（257→259）
- `808a211` 会员等级体系、`543220c` 拼团功能（历史会话交付）

## 阻塞项 / 待用户决策

- egg-carton-13.png 用途不明，暂不动（删除需用户确认）
- 规范文件改版（AGENTS.md→指针 + CLAUDE.md + PROJECT-LEAD.md）已由用户完成并生效
- 远端推送（git push）属绝对禁止项，所有提交停留在本地

## 决策记录

- **不迁移框架**：维持原生 PHP + lib 收敛，理由=迁移成本远大于收益，且项目规范禁止为小功能拖入框架级依赖
- **CI 用 shivammathur/setup-php@v2**（MIT，PHP 生态事实标准），不自写安装逻辑；测试密钥现场生成一次性 RSA，不入库
- **退款口径全站统一**：小计 − 券 − 积分抵现 − 会员折扣（shop.php / pay_return / cron_orders 三处一致）

## 下一步

1. 售后退款链路复核（只读排查，发现问题先报告再改）
2. RBAC 权限矩阵自查
3. 视用户意向推进搜索升级（先出 GitHub 对比表）
