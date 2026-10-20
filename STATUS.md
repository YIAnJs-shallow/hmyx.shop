# STATUS（此时此刻）

> 本文件由项目负责人（Claude Code）维护：每开始一个任务、每提交一次、遇到阻塞时立即更新。
> ROADMAP.md 管全局规划与历史；本文件只管"现在做到哪了"。

- 当前任务：方向 A 安全加固（认证接口限流）——代码与测试已完成（391 断言全绿）
- 进行到的步骤：lib/security.php 限流原语 + rate_limit_hits 建表（migrate.php 幂等）+ 5 个接口接入限流；登录按账号 10 失败/10min + 按 IP 30 失败/10min（成功清账），发码/找回/注册按 IP 20/h，高德定位按 IP 120/h；断言 378→391
- 遗留：①**正式库需执行 scripts/migrate.php**（rate_limit_hits 建表，幂等，待用户确认）；②login_process.php 为无引用死文件，删除需用户确认；③用户验收：物流链路实测、头像可点性；④空目录手删
- 下一步：用户确认后正式库 migrate → push（push 由用户执行）→ CI 校验
