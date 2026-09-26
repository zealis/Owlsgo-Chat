# Owlsgo-Chat (OChat)

纯原生 PHP 8.1+ 即时通讯聊天室 —— **零框架、零 Composer 依赖**，单入口 `index.php`，开箱即用。  
支持 SQLite / MySQL / PostgreSQL，适合 Web 在线聊天、低成本部署与 AI 二次开发。

## 快速开始

1. 将代码放到站点根目录（虚拟主机 / 宝塔 / phpStudy 均可，PHP ≥ 8.1，需 pdo、mbstring、curl、openssl、fileinfo 扩展）。
2. 浏览器访问 `index.php`，按安装向导创建管理员（默认 SQLite 零配置）。
3. 完成后进入聊天室；管理员可从左下角进入「管理后台」。

切换 MySQL / PostgreSQL：编辑 `core/config.php` 的 `db` 段，删除 `data/install.lock` 后重新访问安装向导。

> **php-cgi 进程数建议调大**：本项目实时通道是 AJAX 长轮询，每个挂起的轮询会占住一个 php-cgi 进程。  
> 默认只有 1~2 个进程，多标签页/多人在线时会出现请求排队（表现为发消息、加载历史变慢）。  
> 建议在php设置 → 对应 PHP 版本设置里把 **php-cgi 进程数调到 5 个以上**（实测调大后：一个 20 秒挂起的轮询期间，其他请求仍能在 3 秒内返回，不会互相阻塞）。

## 架构

鸟瞰图：.birdview/architecture.html

## 功能

- **聊天核心**：多聊天室（公开 / 密码私密 / 限定角色）、AJAX 长轮询实时推送 + 心跳 + 断线降级短轮询、普通 / @提及 / 私信 / 系统消息、3 分钟撤回（管理员与房主不限）、滚动加载历史、图片消息（粘贴 / 上传 / 大图预览）、Emoji 面板 + 自定义贴纸收藏、新消息提示音
- **用户系统**：用户名 + 邮箱注册、邮箱验证码、密码找回、游客模式（随机昵称、每日限额、可配置浏览 / 发言）、资料卡（昵称 / 头像）、管理员 / VIP / 普通成员 / 游客角色标签、自定义称号
- **在线状态**：实时在线列表、心跳同步、管理员可查 IP 归属地（ip-api.com）
- **管理后台**：用户管理、禁言（用户 / 游客昵称 / IP，房间隔离 + 过期时间）、敏感词替换（启停）、聊天室与房主、公告（绑定房间 / 优先级轮播）、安全日志、站点设置、插件管理
- **安全机制**：API 签名验证（按会话密钥）、数据库频率限制、登录保护（验证码 + 锁定）、邮件频率限制、SVG 图形验证码（零依赖）
- **插件机制**：Hook、API 路由、后台页面、资源合并、zip 在线安装、统一计划任务（长轮询驱动或 `?action=cron`）

## 目录结构

```
index.php          统一入口（页面 + AJAX API + 安装向导）
core/              配置 / 数据层 / 安全 / 邮件 / 认证 / 聊天 / 后台 / 上传 / 插件
assets/            ow 前缀自研扁平 UI（CSS + ES5 JS + SVG Logo）
plugins/           插件目录（plugin.json + main.php）
data/              SQLite 数据库与安装锁（运行时生成）
uploads/           头像 / 贴纸 / 图片（运行时生成）
```

## 二次开发（AI 友好）

- 所有 API 走 `index.php?action=<动作>`，POST 携带 `ts` + `sign=md5(key|ts|action)`
- 插件示例：在 `plugins/demo/` 放 `plugin.json` 与 `main.php`，`Plugin::on('message.after_send', fn)` 即可挂载钩子
- 前端为纯 ES5 + XHR，无构建步骤，改完即生效；兼容落后内核浏览器
