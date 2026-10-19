# STATUS（此时此刻）

> 本文件由项目负责人（Claude Code）维护：每开始一个任务、每提交一次、遇到阻塞时立即更新。
> ROADMAP.md 管全局规划与历史；本文件只管"现在做到哪了"。

- 当前任务：图片搜索（拍照搜同款）+ 预售定金——两项均已完成并提交（05223ad / de08f0b）
- 进行到的步骤：2026-10-19 复跑测试 347 断言全绿（310→320→347）；工作区仅余非本任务的未跟踪文件（package.json / node_modules / bs-config.js，未入库未改动）
- 遗留：①预售正式库 DDL（5 列幂等加列）待用户确认后执行 scripts/migrate.php；②ARK_API_KEY 待用户填入 private/secrets.php
- 下一步：用户执行正式库 migrate 并 push（本地领先 origin/main 2 个提交）
