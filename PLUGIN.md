# 插件开发规范（PLUGIN.md）

本文件是 AI 新建、修改和审查 Owlsgo-Chat 插件时的规范。开始工作前先读完本文件，再检查核心文件（`core/plugin.php`）和功能最接近的现有插件（`plugins/user-manager/`）；实现时以当前代码为准，不臆造接口。

> 本文档只描述 Owlsgo-Chat 自身的插件机制，全部内容以 `core/plugin.php` 与现有插件的实际代码为准。

## 执行顺序

1. 明确插件 ID、功能边界、页面入口、权限要求与计划任务。
2. 优先复用核心函数与 `Plugin::*` 既有 API；插件机制能完成时，不修改 `index.php` 或 `core/` 下的核心文件。
3. 插件只写在 `plugins/<插件ID>/` 目录内，结构与命名遵守本文「基础约束」。
4. 新建或修改插件后：PHP 语法检查（`php -l`）、JS 语法检查（`node --check`）、在「插件管理」里启用后真实走一遍功能，并按文末清单复核。
5. 修改插件必须递增 `plugin.json` 的 `version`（至少补丁位）；`description` 与当前能力保持一致。

## 基础约束

- 兼容 PHP 8.1+ 与 SQLite / MySQL / PostgreSQL 三驱动，不引入框架、Composer 包或构建依赖；跨库差异处理遵循项目既有写法（见 `core/db.php` 的 `DB::driver()` 分支与 `DB::rebuildTableWithout()` 等助手）。
- 插件目录与插件 ID 相同，只允许小写字母、数字、下划线或短横线。
- 插件自有的 PHP 函数、JS 全局对象、CSS 类、文件名必须以插件 ID 相关的名称开头，避免与核心或其他插件冲突。例：插件 `user-manager` 的 JS 全局对象是 `OwUM`（自有命名），API 路由前缀 `plugin_user_manager_`。
- 插件路由的 action 名**必须**以 `plugin_<插件ID下划线形式>_` 开头（如 `plugin_user_manager_search`）。`index.php` 对未知 action 会调用 `Plugin::dispatch()` 兜底，带前缀可避免与核心 action 或其他插件冲突。
- `main.php` 开头必须包含 `if (!defined('OWLSGO_VERSION')) exit;`，禁止直接 HTTP 访问。
- `plugin.json` 声明 `name`（显示名）、`version`、`description`（面向用户的简短说明）、`author`。
- 插件数据库操作一律使用核心 `DB` 类（`DB::run/one/all/val/insert/upsert`），禁止自行 new PDO；表名建议带 `plugin_<id>_` 前缀。建表/改表如需跨驱动兼容，参考 `core/db.php` 既有实现，不要照搬单驱动 SQL。

## 目录结构与最小插件

```
plugins/<插件ID>/
├── plugin.json     # {"name":"显示名","version":"1.0.0","description":"...","author":"..."}
├── main.php        # 插件逻辑：注册后台页面、API 路由、钩子、资源
└── admin.js        # （可选）后台交互脚本，经 Plugin::asset() 注册
```

最小示例（页脚追加内容）：

```php
<?php
if (!defined('OWLSGO_VERSION')) exit;

Plugin::on('page.footer', function () {
    echo '<p style="text-align:center">Hello</p>';
});
```

## 加载与启停

- 启用状态保存在 `plugins` 表（`enabled` 字段）。**只有启用的插件**，其 `main.php` 才会在每个请求中被 `Plugin::init()` 加载；未启用插件完全不执行。
- 安装方式两种：后台「插件管理」上传 zip（包内须有 `<插件ID>/plugin.json`），或直接把插件目录放进 `plugins/`。
- 安装/上传后默认停用，需在「插件管理」列表中启用。
- 插件启停后整页刷新，侧栏子菜单与可用页面随之更新；**未启用的插件**在侧栏显示灰色「`<ID>（未启用）`」子项，点进去是停用说明 + 「启用插件」一键按钮。
- `plugins/` 目录已加入 `.gitignore`，不随 Git 仓库分发；插件随部署环境安装维护。
- 启停与安装会写安全日志（`Sec::log`）。

## 后台页面（Plugin::adminPage）

```php
Plugin::adminPage('user-manager', '用户管理', function () {
    return '<h2>用户管理</h2> ... ';   // return 或 echo 均可，见下
});
```

- 注册后，后台侧栏「插件管理」分类下出现该插件的子页（一插件一页）；分类标题本身仍指向插件列表页。
- 页面 HTML 通过 `admin_plugin_page` 接口取回并注入 `#owAdminMain`；回调 **echo 输出**与 **return 字符串**两种写法都支持。
- 页面 HTML 是经 `innerHTML` 注入的，**内联 `<script>` 不会执行**——交互函数必须写在插件自己的 JS 文件里（见下）。

## API 路由（Plugin::route）

```php
Plugin::route('plugin_user_manager_search', function (array $ctx) {
    $actor = $ctx['actor'];   // 当前访问者摘要（kind/id/nickname/role...）
    $post  = $ctx['post'];    // $_POST
    Api::json(['ok' => true, 'data' => []]);
});
```

- 路由在前台 AJAX 分发的最后兜底触发：核心 action 与 `admin_*` 后台动作之外，未命中的 action 会进入 `Plugin::dispatch()`。
- ctx 固定为 `['actor' => 当前访问者, 'post' => $_POST]`。
- **权限自查是插件路由的硬性要求**：核心不会替插件校验权限。需要管理员的接口，第一步必须做：

```php
if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
```

- 输出统一用 `Api::json()`；禁止 `echo` 混入 JSON 响应。

## 静态资源（Plugin::asset）

```php
Plugin::asset('js', 'user-manager/admin.js');   // 相对插件目录；css 同理
```

- 合并输出地址：`?action=assets&type=js` / `?action=assets&type=css`（免签名 GET，纯静态无写操作）。
- **后台页面**已自动引入 `<script src="?action=assets&type=js">`：需要后台交互的插件在此声明 JS，交互对象挂为全局（如 `window.OwUM`），页面 HTML 里用 `onclick="OwUM.search()"` 调用。
- 脚本可复用主程序暴露的全局：`OwApi`（AJAX + 自动签名）、`esc`、`toast`、`fmtUid`、`opts`、`ROLE_CN`、`OwAdmin`、`OwChat`。不要重复实现这些能力。
- 前端可从 `OwChat.cfg.site_url` 取当前站点根地址（后端 `ow_site_url()`，未配置时为自动识别值）。

### 站点地址（ow_site_url / ow_abs_url）

需要生成绝对 URL（邮件链接、分享、回调地址）时用主程序助手，不要自行拼 `$_SERVER`：

```php
ow_site_url();                 // 站点根地址：后台「固定网站地址」优先，留空则自动识别（兼容反代头与子目录）
ow_site_url(true);             // 只取「手动配置」的值，自动识别不参与
ow_abs_url('uploads/a.jpg');    // 拼接绝对地址；第二个参数默认 true（仅手动配置生效），
                                // 未配置时原样返回相对路径，避免自动识别误判写入不可访问的地址
```

- 自动识别顺序：`X-Forwarded-Proto` / `HTTPS` → `X-Forwarded-Host` / `Host` / `SERVER_NAME`（仅兜底时补非标准端口）→ 子目录部署路径。容器反代下不会误拼服务器内部端口（如 `:80`）。
- 后台设置项 `site_url`：留空自动识别；填写时后端校验必须以 `http://` 或 `https://` 开头。

## 钩子（Plugin::on / fire）

当前核心提供的钩子（以源码 `Plugin::fire()` 调用点为准，不臆造）：

| 钩子                    | 触发时机                             | 参数                                                                   |
| --------------------- | -------------------------------- | -------------------------------------------------------------------- |
| `message.before_send` | 消息入库前（`Chat::send` 内）            | `[&$content, $actor, $roomId]` —— `$content` 按引用传入，可改写（敏感词过滤之后、入库之前） |
| `message.after_send`  | 消息入库后                            | `[$msgId, $actor, $roomId]`                                          |
| `page.head`           | 各页面 `<head>` 输出时（`pageHead()` 内） | 无参，可直接 echo                                                          |
| `page.footer`         | 聊天页 / 后台页 body 输出末尾              | 无参，可直接 echo                                                          |
| `cron.minute`         | 统一计划任务（每分钟至多一次）                  | 无参                                                                   |
| `nickname.before_save` | 昵称校验（注册 / 改资料 / 安装向导，`Auth::checkNickname` 内） | `[&$nick, &$err, $ctx]` —— 可改写 `$nick`，或把 `$err` 设为非空字符串拦截（即用户看到的文案）；`$ctx['scene']` 为 `register` / `profile` / `install`。参考实现：`plugins/nickname-guard/` |
| `ban.check` | 消息发送禁言判定（`Chat::isBanned` 内，核心 bans 表无命中时触发，每条消息一次） | `[&$reason, $actor, $roomId]` —— `$reason` 初始为 **null**，设为非空字符串即拦截（即用户看到的文案）；回调签名必须用 **`?string &$reason`**（nullable，初始 null 传非 nullable 引用会 TypeError 且被 fire 静默吞掉）。参考：`plugins/ban-manager/` 的运行时说明 |
| `ban.after_add` / `ban.after_del` | 禁言添加 / 解除后（ban-manager 插件触发） | `[$banId, $type, $target, $actor]` / `[$banId, $actor]` —— 通知型，供审计、通知类插件扩展 |
| `ip.location` | 管理员查 IP 归属地（`?action=ip_loc`，核心 v1.0.55 起不再内置实现） | `[&$loc, $ip, $actor]` —— `$loc` 初始 `''`，设为非空字符串即作为归属地结果返回；无人响应时接口返回「未安装归属地查询插件」。菜单入口由插件用 `OwChat.onMsgCtx` 自行注册 |
| `login.after_verify` | 登录验证完成（`Auth::login` 内，密码校验通过且会话已建立） | `[$user, $method, $ctx]` —— 通知型，`$method` 为本通过验证的方式（核心仅 `password`；插件实现两步验证时可自行触发本钩子并传 `totp` / `recovery`）；`$ctx` 含 `ip`。**仅在成功登录时触发，验证失败不触发** |

计划任务由长轮询驱动（`Plugin::cronTick()`），也可用系统计划任务调 `?action=cron` 强制触发；回调内自行判断是否到达执行周期，保证可重复运行。

## 前台页面输出

- 钩子回调里的 `echo` 直接进入页面输出；输出前所有用户数据必须经 `Sec::e()` 或前端 `esc()` 转义。
- 不修改核心文件即可扩展页面；确需新的注入点时，先在核心 `pageHead()` / 页面渲染处增加 `Plugin::fire()`，再讨论合入，不要在插件里用输出缓冲 hack。
- **前台资源（v1.0.55 起）**：聊天页与后台页**都由主程序统一引入** `<script src="?action=assets&type=js">`（聊天页位于 `OwChat.init` 之后），插件无需自行注入。多个插件各自用 `page.footer` 注入会导致脚本重复加载、菜单项重复注册——不要这么做。
- 脚本头部做场景判断与幂等保护：

```js
if (!w.OwChat || !OwChat.cfg) return;   // 后台 / 登录页也会加载本脚本
if (w.__owXxxLoaded) return;            // 幂等：防止重复引入时二次注册菜单项
w.__owXxxLoaded = true;
```

### 前端扩展点（OwChat.onMsgCtx）

聊天室消息右键菜单支持插件追加菜单项（v1.0.54 新增）：

```js
OwChat.onMsgCtx(function (items, msg, env) {
    // items.push({ t: '菜单文案', run: function () { ... } });
    // msg：消息对象（uid/gid/nickname/role/mine/recalled 等）
    // env：{ roomId, actor }
});
```

- 回调在菜单渲染前同步执行，`try/catch` 包裹（插件异常不影响基础菜单）。
- 显示判定只做用户体验过滤，**权限必须在服务端路由内重新校验**（参考 `plugin_ban_manager_quick`：管理员 / 房主守卫、不能禁言自己与管理员、房间归属校验）。
- 弹窗复用 `OwChat.openModal()` / `OwChat.closeModal()`，与全站确认框同一样式。参考实现：`plugins/ban-manager/chat.js`。

## 安全要点

- 插件路由第一步做权限自查（见上）；涉及写操作的只接受 POST + 核心签名（前端经 `OwApi` 自动携带，无需额外处理）。
- SQL 一律参数化（`DB::run/one/all/val` 的 `?` 占位），禁止拼接用户输入。
- 文件路径白名单校验，防目录穿越；对外请求设置超时。
- 错误信息简短，不暴露凭据与 SQL。
- 用户消息等展示数据的历史快照语义注意：`messages` 表的 `nickname/avatar/role/title/to_nickname` 是发送时快照，涉及用户资料变更的插件需同步刷新对应快照（参考 `Auth::updateProfile` 的做法）。

## 交付检查

- `php -l plugins/<ID>/main.php` 与 `node --check` 全部通过。
- 插件在「插件管理」中启用后，后台页面、API 路由、钩子输出真实走通一遍；停用后功能整体下线且不报错。
- 路由 action 带正确前缀；需要管理员的接口有权限自查；SQL 全部参数化。
- `plugin.json` 的 `version` 已按改动递增，`description` 与能力一致。
- 插件文件命名、JS 全局对象、路由前缀均带插件 ID，无与核心或其他插件冲突的通用名。
- 已核对 `plugins/` 在 `.gitignore` 内，插件不进入 Git 仓库。

## 最小提示（给 AI）

```text
请先阅读根目录 PLUGIN.md 与 core/plugin.php，并参考现有插件 plugins/user-manager/。
插件放在 plugins/<插件ID>/，使用 Plugin::adminPage / route / asset / on 注册能力。
需求：<清楚描述功能、入口、权限>
完成后执行 php -l 与 node --check，并在后台启用插件做真实验证。
```
