# STATUS（此时此刻）

> 本文件由项目负责人（Claude Code）维护：每开始一个任务、每提交一次、遇到阻塞时立即更新。
> ROADMAP.md 管全局规划与历史；本文件只管"现在做到哪了"。

- 当前任务：优惠券玩法扩展（方向 C 第二项，用户下发定死方案）——已完成并提交
- 进行到的步骤：310 断言全绿（290→310）；提交 2c33913；工作区干净
- 待办：正式库 DDL（coupons 加 scope/product_id/valid_days 三列，幂等）待用户确认后执行 scripts/migrate.php
- 下一步：用户执行正式库 migrate 并 push（当前本地领先 2 个提交）
