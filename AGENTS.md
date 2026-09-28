# 项目协作规则（AI 编码助手与开发者均须遵守）

## 密钥与敏感信息（最高优先级）

- 禁止在任何源码中硬编码 API Key、密码、Token、内网地址。高德 Key 等真实值只允许写在 `config/secrets.php`（已被 .gitignore 排除，禁止入库）。
- 新增外部服务配置时，必须同步维护模板：真实值写入 `config/secrets.php`，并在 `config/secrets.example.php` 加注释占位。
- 本机环境差异配置（数据库密码等）写 `config/database.local.php`（不入库）；公共连接逻辑只在 `config/db.php`。
- 禁止删除或绕过 `.gitignore` 的任何排除项（secrets.php、database.local.php、backup_*/、*.sql、*.log 等）。
- 严禁 `git add -f` 强制添加被忽略的敏感文件。

## 项目结构

- 原生 PHP + MySQL（phpStudy 环境），数据库名 `my_web_db`。
- `backup_*/` 为历史手工备份，保持忽略状态，不要在其中改代码或新增文件。
- 提交 SQL 脚本前确认不含真实用户数据与明文密码。

## 提交规范

- 每完成一个独立模块或修复即提交一次，作为可回滚检查点。
- 提交前用 `git status` 自查：不得出现 secrets.php、*.sql、backup_*。
- 提交信息用中文简述改动范围，如"修复购物车数量校验"。
