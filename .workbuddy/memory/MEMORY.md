# Owlsgo-Chat 项目长期记忆

## 项目约定（硬性规则）
- **零依赖**：禁止任何第三方框架与 Composer 包；需要引入依赖必须先建议并等用户确认。
- 根目录 index.php 单入口（页面 + AJAX API 统一分发，`?action=`），无需指定目录，低成本虚拟主机可部署。
- 支持 SQLite / MySQL / PostgreSQL（PDO，跨引擎方言封装在 core/db.php）。
- 实时通道：AJAX 长轮询 + 心跳 + 断线降级短轮询（2026-09-26 用户裁决，不引入 Workerman/Redis）；限流用 rate_limits 表计数。
- UI：ow 前缀自研扁平化，经典蓝 #00A0E9/#0078D4，禁 linear-gradient、禁 Emoji 作功能图标（用 core 里 ow_icon() 线性 SVG）、禁大面积毛玻璃、对比度 ≥ WCAG AA。
- 前端纯 ES5 + XHR（无 fetch/箭头函数），兼容落后内核浏览器。
- 图片分流：头像/贴纸永远本地；图片消息 local 模式客户端压缩、imgbed 模式走 img.scdn.io API 压缩（后台可切换）。
- Logo：设计文档/index.svg（已复制为 assets/img/logo.svg）。

## 每次修改的工作流（用户要求）
1. 递增版本号：VERSION 文件 + index.php 的 OWLSGO_VERSION 常量。
2. git commit 并推送到 GitHub zealis/Owlsgo-Chat（公开仓库，main 分支；GCM 已有凭据可直接 push）。

## 本地测试环境
- PHP CLI：`/d/tools/php83/php.exe`（8.3.33，无 php.ini，需 `-d extension_dir=/d/tools/php83/ext -d extension=mbstring -d extension=pdo_sqlite -d extension=curl -d extension=openssl -d extension=fileinfo -d extension=zip`）。
- Windows 内置服务器单进程（PHP_CLI_SERVER_WORKERS 无效），长轮询并发推送只能在类生产环境（PHP-FPM）验证。
- API 签名：sign = md5(client_key|ts|action)，匿名用会话 anon_key；冒烟测试可从页面 HTML 提取 key 后用 md5sum 计算。

## 架构资料
- Birdview 架构图：`.birdview/architecture.json` / `architecture.html`（rev 2）。
- 设计文档：`设计文档/`（开发文档、色彩搭配方案、图床 API、安全审核提示词）。
