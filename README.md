# Owlsgo-Chat (OChat)

纯原生 PHP 8.1+ 即时通讯聊天室 —— **零框架、零 Composer 依赖**，单入口 `index.php`，开箱即用。
支持 SQLite / MySQL / PostgreSQL，适合 Web 在线聊天、低成本部署与 AI 二次开发。

当前版本：**v1.0.118**（版本以根目录 `VERSION` 文件为准，完整更新记录见 `设计文档/CHANGELOG.md`）。

## 快速开始

1. 将代码放到站点根目录（虚拟主机 / 宝塔 / phpStudy 均可，PHP ≥ 8.1，需 pdo、mbstring、curl、openssl、fileinfo 扩展）。
2. 浏览器访问 `index.php`，按安装向导创建管理员（默认 SQLite 零配置）。
3. 完成后进入聊天室；管理员可从聊天室左下角进入「管理后台」。

切换 MySQL / PostgreSQL：编辑 `core/config.php` 的 `db` 段，删除 `data/install.lock` 后重新访问安装向导。

> **php-cgi 进程数建议调大**：本项目实时通道是 AJAX 长轮询，每个挂起的轮询会占住一个 php-cgi 进程。
> 默认只有 1~2 个进程，多标签页/多人在线时会出现请求排队（表现为发消息、加载历史变慢）。
> 建议在 PHP 设置 → 对应 PHP 版本设置里把 **php-cgi 进程数调到 5 个以上**（实测调大后：一个 20 秒挂起的轮询期间，其他请求仍能在 3 秒内返回，不会互相阻塞）。

## 架构

鸟瞰图：`.birdview/architecture.html`

核心分层：`index.php`（页面 + AJAX API + 安装向导统一分发）→ `core/`（配置 / DB / 安全 / 认证 / 聊天 / 后台 / 上传 / 插件）→ `plugins/`（可选功能）→ `assets/`（ow- 前缀自研扁平 UI）。

## 功能

- **聊天核心**：多聊天室（公开 / 密码私密 / 限定角色）、AJAX 长轮询实时推送 + 心跳 + 断线降级短轮询、普通 / @提及 / 私信 / 系统消息、3 分钟撤回（管理员与房主不限）、滚动加载历史、图片消息（粘贴 / 上传 / 大图预览）、Emoji 面板 + 自定义贴纸收藏、新消息提示音、聊天室背景自定义
- **群公告**（`announcements` 插件）：群主/超管发布，类型支持「聊天室上方公告条」与「进群弹窗通知」，可置顶；公告条只展示置顶或最新一条，点击进群公告页（群名 + 卡片列表 + 展开收起 + 置顶标）；`room_id=0` 为全站公告
- **用户系统**：邮箱注册（无用户名，账号一律以数字用户 ID 标识，显示名为昵称）、邮箱验证码、密码找回、游客模式（随机昵称、每日限额、可配置浏览 / 发言）、资料卡（昵称 / 头像）、角色标签与自定义称号、可选两步验证插件
- **在线状态**：实时在线列表（默认收起，点顶栏成员图标展开）、心跳同步
- **管理后台**（通用列表轮子：服务端分页 + 数字页码/跳转 + 多选框批量）：群聊审核与回收站（可撤销）、用户管理、禁言管理、群聊公告、敏感词过滤、安全日志、站点设置、插件管理
- **安全机制**：API 签名验证（按会话密钥）、**敏感操作一次性票据**（POST + ticket 用后即焚）、**会话指纹守卫**（UA + IP 段绑定，换环境重放即失效）、数据库频率限制、登录保护（验证码 + 锁定）、邮件频率限制、SVG 图形验证码、附件上传多重校验（真实 MIME + 扩展名白名单 + 随机名）、Nginx 层屏蔽敏感目录与上传目录执行、**全输入点敏感词过滤**（`text.filter` 钩子：发言 / 昵称 / 群名 / 群简介 / 群公告）
- **插件机制**：Hook、API 路由（`plugin_<name>_` 前缀）、后台页面、资源合并与按需加载、zip 在线安装、统一计划任务（长轮询驱动或 `?action=cron`）

### 内置插件（`plugins/`）

| 插件 | 说明 |
|---|---|
| `announcements` | 群公告体系（公告条 / 弹窗通知 / 置顶 / 公告页） |
| `sensitive-words` | 敏感词过滤（词库维护 + 全输入点生效） |
| `attachment-manager` | 附件管理（按群聊检索、下载、删除） |
| `user-manager` | 用户管理（后台页面） |
| `ban-manager` | 禁言管理（用户 / 游客昵称 / IP，房间隔离 + 过期时间） |
| `nickname-guard` | 昵称规则守卫（共用 `Auth::checkNickname`） |
| `twofa` | 两步验证（可启用 / 停用） |

## 目录结构

```
index.php              统一入口（页面 + AJAX API + 安装向导）
core/                  配置 / 数据层 / 安全 / 邮件 / 认证 / 聊天 / 后台 / 上传 / 插件
assets/                ow 前缀自研扁平 UI（CSS + ES5 JS + SVG Logo）
plugins/               插件目录（plugin.json + main.php）
data/                  SQLite 数据库、安装锁、缓存（运行时生成）
uploads/               头像 / 贴纸 / 图片 / 文件附件（运行时生成）
nginx-server.conf      随程序走的 Nginx 站点配置（含安全屏蔽规则，由 nginx.conf include）
.tools/                运维脚本（sync-www.sh 同步到 WWW、gen_changelog.py 生成更新日志）
设计文档/              开发文档、约定与 CHANGELOG.md
```

## UI 规范（二次开发必读）

- **禁止浏览器原生弹窗**（`confirm` / `alert` / `prompt`）：确认用 `OwChat.confirm(text, onOk)`（前台）/ `OwAdmin.confirm`（后台），提示用 `toast(msg)`，输入用 `OwChat.openModal(html)` 自建表单弹窗。
  发版前自检：`grep -rn "w\.confirm(\|window\.confirm(\|[^.a-zA-Z]alert(\|w\.prompt(" assets/js/chat.js plugins/*/*.js`
- 组件 0 间隙贴合 + 共享边框；配色走 CSS 变量（`--ow-primary-strong` 等），不使用渐变/毛玻璃（公告条渐变配色为特例）。
- 前端为纯 ES5 + XHR，无构建步骤，改完即生效；兼容落后内核浏览器。

## 二次开发

- 所有 API 走 `index.php?action=<动作>`，POST 携带 `ts` + `sign=md5(key|ts|action)`；敏感操作另需先取 `?action=ticket` 的票据并随请求提交（`OwApi.secure` 已封装）。
- 插件示例：在 `plugins/<name>/` 放 `plugin.json` 与 `main.php`，用 `Plugin::on('message.after_send', fn)` 挂载钩子、`Plugin::route('plugin_<name>_xxx', fn, ['sensitive' => true])` 注册路由、`Plugin::adminPage('<slug>', '标题', fn)` 挂后台页、`Plugin::asset('js'|'css', '<name>/file')` 注入资源。
- 前端扩展钩子：`OwChat.onRoomSwitch(fn)`（切群/进群）、`OwChat.onRoomEdit(fn)`（群聊设置弹窗，往 `#owREExtras` 加入口）。
- 发版流程：只改根目录 `VERSION` → 提交 → 合并主仓 → 运行 `.tools/sync-www.sh`；随后执行 `python .tools/gen_changelog.py` 更新 `设计文档/CHANGELOG.md`。
