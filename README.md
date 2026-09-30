# 惠蒙优选商城

原生 PHP + MySQL 商城，保留原有 phpStudy 项目结构，前台、交易服务和管理后台共用数据库。2026-09-28 更新已在当前 `my_web_db` 上执行增量迁移。

## 已接通的业务

- 买家注册登录 → 搜索、分类、价格排序、分页 → 商品详情 / 收藏。
- 加入购物车、调整数量 → 选择本人收货地址 → 按店铺拆单 → 演示支付。
- 商家发布商品 → 管理员审核 → 前台可见 → 买家下单 → 本店发货 → 买家查看运单、确认收货 → 购买后评价。
- 未付款取消 / 三小时超时 → 释放预留库存；已付款未发货的演示订单 → 申请退款 → 商家审批 → 关闭订单并恢复库存。
- 订单商品名称、图片、价格和地址均保存快照。修改商品或地址不会改写新订单历史。

## 本机运行

继续使用 phpStudy 原站点即可。也可以在项目目录打开 PowerShell，运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1 -DemoPayment
```

访问 `http://127.0.0.1:8088/index.php`。此命令仅在本机监听；关闭终端或按 Ctrl+C 停止。默认使用本机已有的 PHP 7.3.4，支持通过 `-PhpExecutable` 指定其他 PHP。MySQL 服务需要已启动。

不加 `-DemoPayment` 时关闭演示付款。phpStudy 中如需演示付款，需要为 PHP 进程设置 `SHOP_DEMO_PAYMENT=1` 并重启 PHP 服务。生产环境必须关闭此选项。

### 私有配置

真实数据库配置和地图密钥不得放在 `WWW`、项目目录或其他 Web 根目录中。默认私有配置目录是项目同级的 `<phpStudy 根目录>/private/my_project/`；环境变量 `DB_HOST`、`DB_PORT`、`DB_USER`、`DB_PASSWORD`、`DB_NAME`、`AMAP_KEY` 优先于其中的文件。

新环境可在项目目录执行以下命令创建**不含真实值**的模板文件，之后仅在 Web 根外填写真实配置：

```powershell
$private = (Join-Path (Split-Path (Split-Path $PWD -Parent) -Parent) 'private\my_project')
New-Item -ItemType Directory -Force -Path $private
Copy-Item .\config\database.example.php (Join-Path $private 'database.local.php')
Copy-Item .\config\secrets.example.php (Join-Path $private 'secrets.php')
```

已有安装应先停止 phpStudy 或本地 PHP 服务，再创建同一目录并**移动**旧文件（不要打印、复制保留或提交其内容）：

```powershell
$private = (Join-Path (Split-Path (Split-Path $PWD -Parent) -Parent) 'private\my_project')
New-Item -ItemType Directory -Force -Path $private
Move-Item .\config\database.local.php (Join-Path $private 'database.local.php')
Move-Item .\config\secrets.php (Join-Path $private 'secrets.php')
```

移动前确认 `$private` 不在 `WWW` 或任何站点根目录内，且 PHP 运行账户仅具有必要的读取权限。目标存在同名文件时不要使用 `-Force` 覆盖；移动后用 `Test-Path` 确认目标文件存在、项目 `config/` 下旧文件不存在，再重启服务。不要将私有目录配置为 Apache Alias、Nginx location 或虚拟目录。

登录统一使用 `user_login.php`，管理员和商家选择“商户 / 管理员”入口。只认可使用 `password_hash()` 保存的密码；历史明文密码账户必须由管理员通过 CLI 重置后才能重新登录。部署此版本前，先在命令行执行 `php scripts/reset_password.php <用户名>` 为管理员和遗留账户设置新密码；该脚本拒绝 Web 请求，且不会在命令行参数或输出中暴露密码。

## 数据库与备份

- 在另一份已有数据库上更新：`php scripts/migrate.php`。可重复执行，不删除已有订单、用户或商品。
- 初次安装先导入原项目数据库备份，再执行迁移；需要 MySQL 8、InnoDB，以及 PHP 的 mysqli、mysqlnd、mbstring、fileinfo 扩展。
- 备份：`php scripts/backup_database.php <网站目录之外的新文件路径.sql>`。恢复备份会覆盖对应表，应在停服并确认目标数据库后操作。
- 在 Windows 任务计划程序中每分钟执行 `php scripts/expire_orders.php`，工作目录设为项目目录，使无人访问时也能释放超时库存。未配置定时任务时，用户访问结算、订单或支付页面也会清理自己的超时订单。
- 旧订单不假定已扣过库存；只有新流程标记为已预留的订单才恢复库存。旧订单的店铺归属不做猜测，历史订单由管理员处理。旧待付款订单应取消后重新下单。

## 代码入口

| 文件 | 作用 |
| --- | --- |
| `lib/security.php` | 会话、密码迁移、CSRF 校验、安全图片上传 |
| `lib/shop.php` | 交易服务、金额计算、库存事务、状态转换、页面组件 |
| `index.php` / `detail.php` | 商品搜索、排序、分页、详情和评价展示 |
| `cart.php` / `checkout.php` | 购物车维护、地址确认和按店拆单 |
| `order_action.php` | POST 订单操作入口，统一校验身份和状态 |
| `my_orders.php` / `pay.php` | 买家订单、演示支付、取消、收货、退款申请 |
| `admin_orders.php` | 本店发货、演示退款处理；管理员可处理全站订单 |
| `favorites.php` / `review.php` | 收藏、购买后评价 |
| `scripts/migrate.php` | 可重复执行的增量数据库迁移 |

价格只从数据库读取，金额以分进行计算；下单锁定库存，订单和明细在同一事务内写入。结算令牌防重复提交。每次请求刷新数据库角色，权限撤销立即生效。商品“删除”改为下架，保留订单引用。购物车商品查询由逐条查询改为联表查询。

## 验证

`php tests/integration.php` 会创建独立 `shop_test_...` 数据库，仅复制表结构并生成测试账号和商品，不复制真实用户数据。结果写入 `tests/state.json`。需要数据库账号有创建数据库权限。

本次验证：29 项交易服务断言、23 项 HTTP 流程断言通过；两个独立连接争抢最后一件库存时仅生成一笔订单，取消后库存恢复；浏览器验证登录、立即购买跳转、桌面详情及 390px 手机结算和订单布局；全部 PHP 文件通过语法检查。

复测 HTTP：将测试服务的 `DB_NAME` 设置为 `tests/state.json` 中的数据库，开启 `SHOP_DEMO_PAYMENT=1`，使用项目路由启动在 `127.0.0.1:8099`，再执行 `python tests/http_flow.py`。浏览器检查使用 `node tests/browser_smoke.cjs`，需要 Playwright 与 Edge；可以设置 `PLAYWRIGHT_MODULE` 指向现有 Playwright 模块。每轮完整测试建议从新的测试数据库开始。

测试数据库保留用于检查。确认测试服务已停止后，可以在数据库管理工具中仅删除对应的 `shop_test_...` 数据库。

## 当前边界

- 未接入真实微信 / 支付宝收款或退款；演示付款不扣款，演示退款不转账。真实支付需要商户资质、密钥、回调验签及渠道对账后才能上线。
- 物流展示真实保存的运单号和订单事件，未接入快递实时轨迹，不生成虚构位置。
- 退款目前支持未发货演示订单的整单申请，拒绝后需联系商家；不含已发货退货、部分退款、优惠券、SKU 规格和运费模板。
- 原地址地图与部分旧后台样式使用外部 CDN / 地图服务；手动填写地址和新交易页面不依赖地图。高德密钥使用 `AMAP_KEY` 或 Web 根外 `<phpStudy 根目录>/private/my_project/secrets.php`，不得放在项目 `config/` 下。逆地理请求会强制验证 TLS 证书和主机名；phpStudy 的 PHP 必须配置可信 CA（优先在 `php.ini` 设置 `curl.cainfo`，必要时设置 `openssl.cafile`）并重启服务。证书校验失败时不得关闭 TLS 绕过，地址页面会安全降级为手动补充地址。
- Apache 请启用 `.htaccess` 的访问规则，并确认虚拟主机启用了 `mod_rewrite` 和 `AllowOverride FileInfo`（或 `All`）；PHP 本地启动脚本已包含敏感目录拦截。这些规则只是深度防御，不能替代 Web 根外存放真实配置。若使用 Nginx，必须在站点配置中禁止访问备份、配置、测试、脚本、隐藏目录及 SQL 文件，并禁止上传目录执行脚本。可按实际站点前缀调整以下规则，并放在 PHP 通用匹配之前：

```nginx
location ~* /(?:backup_[^/]+|config|lib|scripts|tests|\.[^/]+)(?:/|$) { deny all; }
location ~* \.(?:sql|zip|log|md|ini)$ { deny all; }
location ~* /uploads/.*\.(?:php[0-9]?|phtml|phar|cgi|pl|asp|aspx)$ { deny all; }
```

本机 PHP 7.3 已停止官方维护；本次按现有环境完成兼容验证，正式部署前应升级到受支持的 PHP 版本并复测。
