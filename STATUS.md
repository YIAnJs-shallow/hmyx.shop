# STATUS（此时此刻）

> 本文件由项目负责人（Claude Code）维护：每开始一个任务、每提交一次、遇到阻塞时立即更新。
> ROADMAP.md 管全局规划与历史；本文件只管"现在做到哪了"。

- 当前任务：P0 正式库 migrate——已执行并逐项验证（2026-10-20，用户确认后执行）
- 进行到的步骤：scripts/migrate.php 跑通无报错；验证 10 处积压 DDL 全部在位（shops.ship_from、products.presale/deposit_cents/presale_end_at、orders.pay_stage/paid_cents、coupons.scope/product_id/valid_days、user_levels.points_rate_x10）+ 六档种子内容与方案逐项一致（白银0/100/×1 … 至尊 2000000/90/×1.5）；tests/integration.php 378 断言复跑全绿
- 遗留：①用户验收：商家设发货地→发货→买家看物流轨迹与地图；②头像可点性待用户实测反馈；③空目录 E:\e2中文路径占位\ 待用户手删；④AMAP_JS_KEY/SECURITY_JS_KEY 未配置则动态地图停在静态图降级层
- 下一步：用户 git push（本地领先 origin/main 6 个提交）→ CI 自动校验 → 浏览器实测物流链路
