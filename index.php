<?php
/**
 * Owlsgo-Chat (OChat) — 根目录统一入口
 * 纯原生 PHP 8.1+，无框架 / 无 Composer 依赖，支持 SQLite / MySQL / PostgreSQL。
 * 页面渲染与 AJAX API 统一由本文件分发（?page= / ?action=）。
 */
declare(strict_types=1);

const OWLSGO_VERSION = '1.0.10';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$CFG = require __DIR__ . '/core/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Asia/Shanghai');

require __DIR__ . '/core/db.php';
require __DIR__ . '/core/security.php';
require __DIR__ . '/core/mail.php';
require __DIR__ . '/core/auth.php';
require __DIR__ . '/core/plugin.php';
require __DIR__ . '/core/chat.php';
require __DIR__ . '/core/upload.php';
require __DIR__ . '/core/admin.php';

class Api
{
    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

Sec::sessionStart($CFG);
Sec::init($CFG);

// 匿名会话密钥（登录前 API 签名用）：同时写入 cookie 备份，
// 避免 php-cgi 多进程下 PHP session 偶发丢失/重建导致签名对不上
if (empty($_COOKIE['owl_akey']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['owl_akey'])) {
    $akey = Sec::clientKey();
    setcookie('owl_akey', $akey, [
        'expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE['owl_akey'] = $akey;
}
if (empty($_SESSION['anon_key'])) $_SESSION['anon_key'] = $_COOKIE['owl_akey'];

$LOCK = $CFG['data_dir'] . '/install.lock';
$installed = is_file($LOCK);

// ---------- 数据库初始化 ----------
$dbOk = true;
try {
    DB::init($CFG);
    if ($installed) {
        DB::migrate();
        DB::defaults();
        // 站点设置可覆盖图片存储模式
        $mode = DB::setting('image_mode');
        if ($mode) $CFG['upload']['image_mode'] = $mode;
    }
} catch (Throwable $e) {
    $dbOk = false;
    $dbErr = $e->getMessage();
}

Upload::init($CFG);

// ---------- 当前访问者 ----------
$user = $installed && $dbOk ? Auth::user() : null;
$guest = null;
if ($installed && $dbOk && !$user) {
    $guest = Auth::guest();
}
$actor = Auth::actor($user, $guest);
if ($installed && $dbOk) Plugin::init($CFG['plugin_dir']);

$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'chat';

// ================= 安装向导 =================
if (!$installed) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'install') {
        try {
            $driver = $_POST['driver'] ?? 'sqlite';
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) throw new RuntimeException('非法数据库类型');
            // 改写 config.php
            $cfgFile = __DIR__ . '/core/config.php';
            $src = file_get_contents($cfgFile);
            $secret = bin2hex(random_bytes(32));
            $src = preg_replace("/'secret'\s*=>\s*'[^']*'/", "'secret'     => '$secret'", $src);
            $src = preg_replace("/'driver'\s*=>\s*'[a-z]*'/", "'driver'   => '$driver'", $src, 1);
            if ($driver !== 'sqlite') {
                $map = ['host' => $_POST['db_host'] ?? '127.0.0.1', 'port' => (int)($_POST['db_port'] ?? 3306),
                        'name' => $_POST['db_name'] ?? 'owlsgo', 'user' => $_POST['db_user'] ?? 'root',
                        'pass' => $_POST['db_pass'] ?? ''];
                foreach ($map as $k => $v) {
                    $src = preg_replace("/'$k'\s*=>\s*'[^']*'/", "'$k'     => '" . addslashes((string)$v) . "'", $src, 1);
                }
            }
            foreach (['host' => 'mail', 'user' => 'mail', 'pass' => 'mail', 'from' => 'mail'] as $k => $g) { /* SMTP 可后配 */ }
            if (!empty($_POST['smtp_host'])) {
                $src = preg_replace("/'host'\s*=>\s*''/", "'host'    => '" . addslashes($_POST['smtp_host']) . "'", $src, 1);
                $src = preg_replace("/'user'\s*=>\s*''/", "'user'    => '" . addslashes($_POST['smtp_user'] ?? '') . "'", $src, 1);
                $src = preg_replace("/'pass'\s*=>\s*''/", "'pass'    => '" . addslashes($_POST['smtp_pass'] ?? '') . "'", $src, 1);
                $src = preg_replace("/'from'\s*=>\s*''/", "'from'    => '" . addslashes($_POST['smtp_from'] ?? ($_POST['smtp_user'] ?? '')) . "'", $src, 1);
            }
            file_put_contents($cfgFile, $src);

            $CFG = require $cfgFile;
            DB::init($CFG);
            DB::migrate();
            DB::defaults();

            $u = trim($_POST['username'] ?? '');
            $e = trim($_POST['email'] ?? '');
            $pw = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $u)) throw new RuntimeException('管理员用户名需 3-20 位字母数字下划线');
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('管理员邮箱格式不正确');
            if (strlen($pw) < 6) throw new RuntimeException('管理员密码至少 6 位');
            DB::insert('users', [
                'username' => $u, 'email' => $e,
                'password' => password_hash($pw, PASSWORD_DEFAULT),
                'nickname' => $u, 'avatar' => '', 'role' => 'admin',
                'client_key' => Sec::clientKey(), 'status' => 1,
                'email_verified' => 1, 'created_at' => time(),
            ]);
            @mkdir($CFG['data_dir'], 0775, true);
            file_put_contents($LOCK, date('c') . ' v' . OWLSGO_VERSION);
            Api::json(['ok' => true, 'msg' => '安装完成']);
        } catch (Throwable $e) {
            Api::json(['ok' => false, 'msg' => '安装失败：' . $e->getMessage()]);
        }
    }
    renderInstall($dbOk ? '' : ($dbErr ?? ''));
    exit;
}

if (!$dbOk) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>数据库连接失败</title><body style="font-family:sans-serif;padding:40px">'
       . '<h2>数据库连接失败</h2><p>' . Sec::e($dbErr ?? '') . '</p><p>请检查 core/config.php 配置。</p></body>';
    exit;
}

// ================= API =================
if ($action !== '') {
    // 验证码图片（无需签名）
    if ($action === 'captcha') {
        $code = Sec::captcha();
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-store');
        echo Sec::captchaSvg($code);
        exit;
    }
    // 系统 cron 入口（可选，供系统计划任务调用）
    if ($action === 'cron') {
        Plugin::cronTick(true);
        Api::json(['ok' => true]);
    }

    // 签名密钥：登录用户/游客用其 client_key，匿名用会话 key（并兼容 cookie 备份 key）
    $signKeys = array_values(array_unique(array_filter([
        (string)($actor['key'] ?? ''),
        (string)($_SESSION['anon_key'] ?? ''),
        (string)($_COOKIE['owl_akey'] ?? ''),
    ])));
    if (!Sec::verifySignAny($signKeys, $action)) {
        Api::json(['ok' => false, 'msg' => '签名验证失败，请刷新页面'], 403);
    }

    // 关键：长轮询等只读动作提前释放会话锁，避免阻塞同会话的发消息等请求（PHP-FPM 生产环境必需）
    // room_join 需要写入密码房通行缓存，必须保留会话写入能力
    if (!in_array($action, ['login', 'logout', 'register', 'reset', 'send_code', 'room_join'], true)) {
        session_write_close();
    }

    $p = fn($k, $d = '') => trim((string)($_POST[$k] ?? $d));

    switch ($action) {
        // ---------- 认证 ----------
        case 'send_code':
            $type = $p('type') === 'reset' ? 'reset' : 'register';
            $email = $p('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Api::json(['ok' => false, 'msg' => '邮箱格式不正确']);
            if ($type === 'register' && DB::one('SELECT id FROM users WHERE email=?', [$email])) Api::json(['ok' => false, 'msg' => '该邮箱已注册']);
            if ($type === 'reset' && !DB::one('SELECT id FROM users WHERE email=?', [$email])) Api::json(['ok' => false, 'msg' => '该邮箱未注册']);
            [$ok, $msg] = Mailer::sendCode($email, $type, $CFG);
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'register':
            [$ok, $msg] = Auth::register($p('username'), $p('email'), (string)($_POST['password'] ?? ''), $p('code'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'login':
            $identity = $p('identity');
            if (Sec::needCaptcha(strtolower($identity) . '|' . Sec::ip())) {
                if (!Sec::checkCaptcha($p('captcha'))) Api::json(['ok' => false, 'msg' => '图形验证码错误', 'captcha' => true]);
            }
            [$ok, $msg] = Auth::login($identity, (string)($_POST['password'] ?? ''));
            Api::json(['ok' => $ok, 'msg' => $msg, 'captcha' => !$ok && Sec::needCaptcha(strtolower($identity) . '|' . Sec::ip())]);

        case 'reset':
            [$ok, $msg] = Auth::resetPassword($p('email'), $p('code'), (string)($_POST['password'] ?? ''));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'logout':
            Auth::logout();
            Api::json(['ok' => true]);

        // ---------- 聊天 ----------
        case 'rooms':
            Api::json(['ok' => true, 'data' => Chat::rooms($actor)]);

        case 'room_join':
            $room = Chat::room((int)$p('room_id'));
            if (!$room) Api::json(['ok' => false, 'msg' => '聊天室不存在']);
            if (!Chat::canEnter($room, $actor)) Api::json(['ok' => false, 'msg' => '无权进入该聊天室']);
            if ($actor['kind'] === 'none') Api::json(['ok' => false, 'msg' => '请先登录', 'need_login' => true]);
            // 密码房：已持有有效通行授权则免密；管理员免密码。
            // 是否真的需要密码一律由服务端判定，前端只需先空密码尝试一次，返回 need_password 再弹窗。
            if ($room['type'] === 'password' && !Chat::roomPassCached((int)$room['id'])) {
                if ($actor['role'] !== 'admin' && !Chat::checkRoomPassword($room, $p('password'))) {
                    $empty = $p('password') === '';
                    if (!$empty) Sec::log('room_pass_fail', $actor['nickname'] ?? '', ['room' => (int)$room['id']]);
                    Api::json(['ok' => false, 'msg' => $empty ? '该房间需要密码' : '房间密码错误', 'need_password' => true]);
                }
                Chat::grantRoomPass((int)$room['id']);   // 管理员同样授予，避免每次点击都往返一次
            }
            Api::json([
                'ok' => true,
                'room' => ['id' => (int)$room['id'], 'name' => $room['name']],
                'ttl' => Chat::passTtl(),
            ]);

        case 'poll':
            $roomId = (int)$p('room_id');
            $room = Chat::room($roomId);
            if (!$room || !Chat::roomAccessOk($room, $actor)) {
                Api::json(['ok' => false, 'msg' => '无权访问该聊天室', 'need_password' => $room && $room['type'] === 'password']);
            }
            if ($actor['kind'] === 'none') Api::json(['ok' => false, 'msg' => '请先登录', 'need_login' => true]);
            Api::json(['ok' => true] + Chat::poll($actor, $roomId, (int)$p('since', '0')));

        case 'history':
            $roomId = (int)$p('room_id');
            $room = Chat::room($roomId);
            // 密码房必须持有有效通行授权，否则任何人都能绕过密码读取历史
            if (!$room || !Chat::roomAccessOk($room, $actor)) {
                Api::json(['ok' => false, 'msg' => '无权访问该聊天室', 'need_password' => $room && $room['type'] === 'password']);
            }
            Api::json(['ok' => true, 'data' => Chat::history($actor, $roomId, (int)$p('before', '0'))]);

        case 'send':
            [$ok, $msg, $id] = Chat::send($actor, (int)$p('room_id'), $p('type', 'text'), (string)($_POST['content'] ?? ''), [
                'to_user_id' => $p('to_user_id'), 'to_guest_id' => $p('to_guest_id'), 'to_nickname' => $p('to_nickname'),
            ]);
            Api::json(['ok' => $ok, 'msg' => $msg, 'id' => $id ?? null]);

        case 'recall':
            [$ok, $msg] = Chat::recall($actor, (int)$p('id'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        // ---------- 上传 ----------
        case 'upload':
            $kind = $p('kind', 'image');
            if ($actor['kind'] === 'none') Api::json(['ok' => false, 'msg' => '请先登录']);
            if (($kind === 'sticker' || $kind === 'avatar') && $actor['kind'] !== 'user') Api::json(['ok' => false, 'msg' => '游客仅可发送图片']);
            if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
            [$ok, $urlOrMsg] = Upload::handle($_FILES['file'], $kind);
            Api::json($ok ? ['ok' => true, 'url' => $urlOrMsg] : ['ok' => false, 'msg' => $urlOrMsg]);

        // ---------- 贴纸 ----------
        case 'stickers':
            Api::json(['ok' => true, 'data' => Upload::stickers($actor)]);

        case 'sticker_add':
            [$ok, $msg] = Upload::addSticker($actor, $p('url'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'sticker_del':
            [$ok, $msg] = Upload::delSticker($actor, (int)$p('id'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        // ---------- 资料 ----------
        case 'profile_save':
            if (!$user) Api::json(['ok' => false, 'msg' => '请先登录']);
            [$ok, $msg] = Auth::updateProfile($user, $p('nickname'), $p('avatar'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'user_card':
            $u = DB::one('SELECT id,username,nickname,role,title,avatar,points,created_at,last_login FROM users WHERE id=?', [(int)$p('id')]);
            if (!$u) Api::json(['ok' => false, 'msg' => '用户不存在']);
            Api::json(['ok' => true, 'data' => $u]);

        case 'ip_loc':
            if ($actor['role'] !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
            Api::json(['ok' => true, 'loc' => Chat::ipLocation($p('ip'))]);

        // ---------- 合并资源（插件 css/js） ----------
        case 'assets':
            $type = $p('type') === 'js' ? 'js' : 'css';
            header($type === 'js' ? 'Content-Type: text/javascript; charset=utf-8' : 'Content-Type: text/css; charset=utf-8');
            header('Cache-Control: no-store');
            echo Plugin::renderAssets($type);
            exit;
    }

    // 管理后台动作
    if (strpos($action, 'admin_') === 0) Admin::handle($action, $actor);

    // 插件路由
    $r = Plugin::dispatch($action, ['actor' => $actor, 'post' => $_POST]);
    if ($r !== null) Api::json(is_array($r) ? $r : ['ok' => true, 'data' => $r]);

    Api::json(['ok' => false, 'msg' => '未知操作'], 404);
}

// ================= 页面 =================
switch ($page) {
    case 'login':    renderAuth('login'); break;
    case 'register': renderAuth('register'); break;
    case 'forgot':   renderAuth('forgot'); break;
    case 'admin':
        if ($actor['role'] !== 'admin') { header('Location: ?page=login'); exit; }
        renderAdmin($actor);
        break;
    default:
        if (!$user && DB::setting('guest_browse', '1') !== '1') { header('Location: ?page=login'); exit; }
        if (!$user && !$guest) $guest = Auth::ensureGuest();
        renderChat(Auth::actor($user, $guest), $user, $guest);
}

// ================= 页面渲染函数 =================
/** 自研线性图标（零依赖 inline SVG，stroke 风格） */
function ow_icon(string $name, int $size = 18): string
{
    $paths = [
        'menu'   => '<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/>',
        'users'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19c1-3.5 3.5-5 6.5-5s5.5 1.5 6.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.5c2.5.3 4.5 1.8 5.5 4.5"/>',
        'smile'  => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1 1.5 2.2 2.2 3.5 2.2s2.5-.7 3.5-2.2"/><line x1="9" y1="9.5" x2="9" y2="10.5"/><line x1="15" y1="9.5" x2="15" y2="10.5"/>',
        'image'  => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3.5 3.5 3-3 5 5"/>',
        'bell'   => '<path d="M6 10a6 6 0 0 1 12 0c0 4 1.5 5.5 2 6H4c.5-.5 2-2 2-6z"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/>',
        'bell-off' => '<path d="M8 6.5A6 6 0 0 1 18 10c0 4 1.5 5.5 2 6h-3"/><path d="M6.2 8.5C6.1 9 6 9.5 6 10c0 4-1.5 5.5-2 6h11"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/><line x1="4" y1="4" x2="20" y2="20"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20c1.2-4 4-6 7.5-6s6.3 2 7.5 6"/>',
        'chat'   => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H9l-5 4z"/>',
        'mute'   => '<path d="M8 6.5A6 6 0 0 1 18 10c0 4 1.5 5.5 2 6h-3M6.2 8.5C6.1 9 6 9.5 6 10c0 4-1.5 5.5-2 6h11"/><line x1="4" y1="4" x2="20" y2="20"/>',
        'ban'    => '<circle cx="12" cy="12" r="9"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'mega'   => '<path d="M3 11v3l4 .5V10.5z"/><path d="M7 10.5L18 5v13l-11-4.5"/><path d="M9 15.5V18a2 2 0 0 0 4 .5"/>',
        'puzzle' => '<path d="M9 4h6v3.5a2 2 0 1 0 4 .5V4h1v6h-3.5a2 2 0 1 0 .5 4H20v6h-6v-3.5a2 2 0 1 0-4 .5V20H4v-6h3.5a2 2 0 1 0-.5-4H4V4h5z" transform="scale(0.9) translate(1 1)"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M2.5 12h3M18.5 12h3M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"/>',
        'lock'   => '<rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'close'  => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        'logout' => '<path d="M14 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H14"/><path d="M10 12h10M17 8.5l3.5 3.5-3.5 3.5"/>',
        'sound'  => '<path d="M4 9.5v5h3.5L13 19V5L7.5 9.5z"/><path d="M16 9a4.5 4.5 0 0 1 0 6"/>',
    ];
    $d = $paths[$name] ?? $paths['chat'];
    return '<svg class="ow-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function pageHead(string $title): void
{
    // 动态页禁止缓存：页面内含会话密钥，缓存旧页会导致提交时签名对不上
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $site = Sec::e(DB::setting('site_name', 'Owlsgo-Chat'));
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . Sec::e($title) . ' - ' . $site . '</title>'
       . '<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">'
       . '<link rel="stylesheet" href="assets/css/owlsgo.css?v=' . OWLSGO_VERSION . '">';
    Plugin::fire('page.head');
    echo '</head>';
}

function renderInstall(string $err): void
{
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>安装 Owlsgo-Chat</title><link rel="stylesheet" href="assets/css/owlsgo.css?v=' . OWLSGO_VERSION . '"></head>'
       . '<body class="ow-auth-body"><div class="ow-auth-card" style="max-width:520px">'
       . '<div class="ow-auth-logo"><img src="assets/img/logo.svg" alt="Owlsgo-Chat"><h1>安装 Owlsgo-Chat</h1><p>纯原生 PHP · 零依赖 · v' . OWLSGO_VERSION . '</p></div>'
       . ($err ? '<div class="ow-alert ow-alert-error">数据库连接失败：' . Sec::e($err) . '（SQLite 模式无需配置，可直接继续）</div>' : '')
       . '<form id="owInstallForm">'
       . '<div class="ow-form-item"><label>数据库类型</label><select name="driver" class="ow-input" onchange="document.getElementById(\'owDbMore\').style.display=this.value===\'sqlite\'?\'none\':\'block\'">'
       . '<option value="sqlite">SQLite（零配置，推荐）</option><option value="mysql">MySQL</option><option value="pgsql">PostgreSQL</option></select></div>'
       . '<div id="owDbMore" style="display:none">'
       . '<div class="ow-form-item"><label>数据库主机</label><input class="ow-input" name="db_host" value="127.0.0.1"></div>'
       . '<div class="ow-form-item"><label>端口</label><input class="ow-input" name="db_port" value="3306"></div>'
       . '<div class="ow-form-item"><label>数据库名</label><input class="ow-input" name="db_name" value="owlsgo"></div>'
       . '<div class="ow-form-item"><label>数据库用户</label><input class="ow-input" name="db_user" value="root"></div>'
       . '<div class="ow-form-item"><label>数据库密码</label><input class="ow-input" type="password" name="db_pass"></div></div>'
       . '<div class="ow-form-item"><label>管理员用户名</label><input class="ow-input" name="username" required></div>'
       . '<div class="ow-form-item"><label>管理员邮箱</label><input class="ow-input" type="email" name="email" required></div>'
       . '<div class="ow-form-item"><label>管理员密码</label><input class="ow-input" type="password" name="password" required></div>'
       . '<details style="margin-bottom:12px"><summary style="cursor:pointer;color:#0078D4;font-size:13px">SMTP 邮件配置（可选，稍后可在 config.php 修改）</summary>'
       . '<div class="ow-form-item"><label>SMTP 主机</label><input class="ow-input" name="smtp_host" placeholder="smtp.qq.com"></div>'
       . '<div class="ow-form-item"><label>SMTP 账号</label><input class="ow-input" name="smtp_user"></div>'
       . '<div class="ow-form-item"><label>SMTP 密码/授权码</label><input class="ow-input" type="password" name="smtp_pass"></div></details>'
       . '<button type="submit" class="ow-btn ow-btn-primary ow-btn-block">开始安装</button>'
       . '<div id="owInstallMsg" class="ow-form-msg"></div></form></div>'
       . '<script>document.getElementById("owInstallForm").onsubmit=function(e){e.preventDefault();'
       . 'var f=new FormData(this);var x=new XMLHttpRequest();'
       . 'x.open("POST","?action=install",true);x.onreadystatechange=function(){if(x.readyState===4){'
       . 'try{var r=JSON.parse(x.responseText);if(r.ok){document.getElementById("owInstallMsg").innerHTML="<span style=\"color:#52C41A\">安装成功，正在跳转...</span>";setTimeout(function(){location.href="?page=login"},800);}'
       . 'else{document.getElementById("owInstallMsg").innerHTML="<span style=\"color:#F5222D\">"+r.msg+"</span>";}}catch(_){}}};x.send(f);};</script>'
       . '</body></html>';
}

function renderAuth(string $mode): void
{
    pageHead(['login' => '登录', 'register' => '注册', 'forgot' => '找回密码'][$mode]);
    $titles = ['login' => '欢迎回来', 'register' => '创建账号', 'forgot' => '找回密码'];
    echo '<body class="ow-auth-body"><div class="ow-auth-card">'
       . '<div class="ow-auth-logo"><img src="assets/img/logo.svg" alt="Owlsgo-Chat"><h1>' . $titles[$mode] . '</h1>'
       . '<p>' . Sec::e(DB::setting('site_name', 'Owlsgo-Chat')) . '</p></div>';
    if ($mode === 'login') {
        echo '<form class="ow-auth-form" data-mode="login">'
           . Sec::signField($_SESSION['anon_key'], 'login')
           . '<div class="ow-form-item"><label>用户名或邮箱</label><input class="ow-input" name="identity" required autocomplete="username"></div>'
           . '<div class="ow-form-item"><label>密码</label><input class="ow-input" type="password" name="password" required autocomplete="current-password"></div>'
           . '<div class="ow-form-item" id="owCaptchaRow" style="display:none"><label>图形验证码</label>'
           . '<div class="ow-captcha-row"><input class="ow-input" name="captcha"><img src="?action=captcha" id="owCaptchaImg" alt="验证码" title="点击刷新"></div></div>'
           . '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">登 录</button><div class="ow-form-msg"></div></form>'
           . '<div class="ow-auth-links"><a href="?page=register">注册账号</a><a href="?page=forgot">忘记密码</a><a href="?page=chat">返回聊天</a></div>';
    } elseif ($mode === 'register') {
        echo '<form class="ow-auth-form" data-mode="register">'
           . Sec::signField($_SESSION['anon_key'], 'register')
           . '<div class="ow-form-item"><label>用户名</label><input class="ow-input" name="username" required placeholder="3-20 位字母、数字或下划线"></div>'
           . '<div class="ow-form-item"><label>邮箱</label><div class="ow-captcha-row"><input class="ow-input" type="email" name="email" required>'
           . '<button type="button" class="ow-btn ow-btn-ghost" data-sendcode="register">发验证码</button></div></div>'
           . '<div class="ow-form-item"><label>邮箱验证码</label><input class="ow-input" name="code" required></div>'
           . '<div class="ow-form-item"><label>密码</label><input class="ow-input" type="password" name="password" required placeholder="至少 6 位"></div>'
           . '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">注 册</button><div class="ow-form-msg"></div></form>'
           . '<div class="ow-auth-links"><a href="?page=login">已有账号，去登录</a><a href="?page=chat">返回聊天</a></div>';
    } else {
        echo '<form class="ow-auth-form" data-mode="reset">'
           . Sec::signField($_SESSION['anon_key'], 'reset')
           . '<div class="ow-form-item"><label>注册邮箱</label><div class="ow-captcha-row"><input class="ow-input" type="email" name="email" required>'
           . '<button type="button" class="ow-btn ow-btn-ghost" data-sendcode="reset">发验证码</button></div></div>'
           . '<div class="ow-form-item"><label>邮箱验证码</label><input class="ow-input" name="code" required></div>'
           . '<div class="ow-form-item"><label>新密码</label><input class="ow-input" type="password" name="password" required></div>'
           . '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">重置密码</button><div class="ow-form-msg"></div></form>'
           . '<div class="ow-auth-links"><a href="?page=login">返回登录</a></div>';
    }
    echo '</div><script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>'
       // 下发服务器时间：前端据此校正本机时钟偏差，避免签名 ts 超出时间窗
       . '<script>OwAuth.init(' . json_encode(['key' => $_SESSION['anon_key'], 'ts' => time()]) . ');</script>';
    Plugin::fire('page.footer');
    echo '</body></html>';
}

function renderChat(array $actor, ?array $user, ?array $guest): void
{
    $rooms = Chat::rooms($actor);
    if (!$rooms) { header('Location: ?page=login'); exit; }
    $first = $rooms[0];
    $settings = [
        'guest_chat' => DB::setting('guest_chat', '1'),
        'sound' => DB::setting('sound_default', '1'),
        'image_mode' => DB::setting('image_mode', 'local'),
    ];
    pageHead('聊天室');
    echo '<body class="ow-chat-body">';
    echo '<div class="ow-layout">';

    // 左侧栏
    echo '<aside class="ow-sidebar" id="owSidebar">'
       . '<div class="ow-brand"><img src="assets/img/logo.svg" alt="logo"><span>' . Sec::e(DB::setting('site_name', 'Owlsgo-Chat')) . '</span></div>'
       . '<div class="ow-side-title">聊天室 <span class="ow-badge-num" id="owRoomCount">' . count($rooms) . '</span></div>'
       . '<ul class="ow-room-list" id="owRoomList"></ul>'
       . '<div class="ow-me" id="owMe"></div>'
       . '<div class="ow-side-actions">'
       . ($user
           ? '<button class="ow-btn ow-btn-ghost" id="owBtnSettings">设置</button>'
             . ($actor['role'] === 'admin' ? '<a class="ow-btn ow-btn-ghost" href="?page=admin">管理后台</a>' : '')
             . '<button class="ow-btn ow-btn-ghost" id="owBtnLogout">退出</button>'
           : '<a class="ow-btn ow-btn-primary" href="?page=login">登录 / 注册</a>')
       . '</div></aside>';

    // 主聊天区
    echo '<main class="ow-main">'
       . '<header class="ow-topbar">'
       . '<button class="ow-icon-btn ow-only-mobile" id="owToggleSide" aria-label="菜单">' . ow_icon('menu') . '</button>'
       . '<h2 class="ow-room-name" id="owRoomName">' . Sec::e($first['name']) . '</h2>'
       . '<span class="ow-tag ow-tag-green" id="owSpeakTag">可发言</span>'
       . '<span class="ow-latency" id="owLatency"></span>'
       . '<button class="ow-icon-btn" id="owToggleOnline" aria-label="在线成员">' . ow_icon('users') . '</button>'
       . '</header>'
       . '<div class="ow-announce" id="owAnnounce" style="display:none"><div class="ow-announce-track" id="owAnnounceTrack"></div></div>'
       . '<div class="ow-messages" id="owMessages"><div class="ow-load-more" id="owLoadMore">加载更早消息…</div></div>'
       . '<div class="ow-inputbar">'
       . '<div class="ow-toolbar">'
       . '<button class="ow-icon-btn" id="owBtnEmoji" title="表情">' . ow_icon('smile') . '</button>'
       . '<button class="ow-icon-btn" id="owBtnImage" title="发送图片">' . ow_icon('image') . '</button>'
       . '<button class="ow-icon-btn" id="owBtnSound" title="提示音" data-on="' . Sec::e(ow_icon('bell')) . '" data-off="' . Sec::e(ow_icon('bell-off')) . '">' . ow_icon('bell') . '</button>'
       . '<input type="file" id="owFileInput" accept="image/*" style="display:none">'
       . '</div>'
       . '<div class="ow-input-row">'
       . '<textarea class="ow-input" id="owInput" rows="1" placeholder="输入消息，按 Enter 发送，Ctrl+V 粘贴图片"></textarea>'
       . '<button class="ow-btn ow-btn-primary ow-send" id="owBtnSend" aria-label="发送">↑</button>'
       . '</div></div>'
       . '<div class="ow-emoji-panel" id="owEmojiPanel" style="display:none"></div>'
       . '</main>';

    // 右侧在线列表
    echo '<aside class="ow-online" id="owOnline"><div class="ow-side-title">所有成员 <span class="ow-badge-num" id="owOnlineCount">0</span></div>'
       . '<ul class="ow-online-list" id="owOnlineList"></ul></aside>';
    echo '</div>';

    // 浮层：资料卡 / 图片预览 / 设置 / 密码房间
    echo '<div class="ow-modal-mask" id="owModalMask" style="display:none"><div class="ow-modal" id="owModal"></div></div>';
    echo '<div class="ow-img-viewer" id="owImgViewer" style="display:none"><img id="owImgViewerImg" alt="预览"></div>';
    echo '<div class="ow-ctx-menu" id="owCtxMenu" style="display:none"></div>';
    echo '<div class="ow-toast" id="owToast" style="display:none"></div>';

    $boot = [
        'key' => $actor['key'],
        'actor' => [
            'kind' => $actor['kind'], 'id' => $actor['id'] ?? 0,
            'nickname' => $actor['nickname'] ?? '', 'role' => $actor['role'] ?? 'guest',
        ],
        'rooms' => $rooms,
        'room' => $first['id'],
        'settings' => $settings,
        'me' => $user ? [
            'nickname' => $user['nickname'], 'username' => $user['username'],
            'role' => $user['role'], 'title' => $user['title'] ?? '',
            'avatar' => $user['avatar'] ?? '', 'points' => (int)($user['points'] ?? 0),
        ] : null,
        'ts' => time(),
        'version' => OWLSGO_VERSION,
    ];
    echo '<script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>'
       . '<script>OwChat.init(' . json_encode($boot, JSON_UNESCAPED_UNICODE) . ');</script>';
    Plugin::fire('page.footer');
    echo '</body></html>';
}

function renderAdmin(array $actor): void
{
    pageHead('管理后台');
    $pluginPages = '';
    foreach (Plugin::adminPages() as $slug => $pg) {
        $pluginPages .= '<li data-apage="plugin:' . Sec::e($slug) . '"><span class="ow-admin-ico">' . ow_icon('puzzle', 16) . '</span>' . Sec::e($pg['title']) . '</li>';
    }
    echo '<body class="ow-admin-body"><div class="ow-admin-layout">'
       . '<aside class="ow-admin-side">'
       . '<div class="ow-admin-brand">ADMIN CONSOLE<br><strong>管理后台</strong></div>'
       . '<a class="ow-btn ow-btn-ghost ow-btn-block" href="?page=chat">返回前台</a>'
       . '<ul class="ow-admin-menu" id="owAdminMenu">'
       . '<li data-apage="users" class="active"><span class="ow-admin-ico">' . ow_icon('user', 16) . '</span>用户管理</li>'
       . '<li data-apage="rooms"><span class="ow-admin-ico">' . ow_icon('chat', 16) . '</span>聊天室管理</li>'
       . '<li data-apage="bans"><span class="ow-admin-ico">' . ow_icon('mute', 16) . '</span>禁言管理</li>'
       . '<li data-apage="words"><span class="ow-admin-ico">' . ow_icon('ban', 16) . '</span>敏感词过滤</li>'
       . '<li data-apage="anns"><span class="ow-admin-ico">' . ow_icon('mega', 16) . '</span>系统公告</li>'
       . '<li data-apage="plugins"><span class="ow-admin-ico">' . ow_icon('puzzle', 16) . '</span>插件管理</li>'
       . '<li data-apage="logs"><span class="ow-admin-ico">' . ow_icon('shield', 16) . '</span>安全日志</li>'
       . '<li data-apage="settings"><span class="ow-admin-ico">' . ow_icon('gear', 16) . '</span>系统设置</li>'
       . $pluginPages
       . '</ul></aside>'
       . '<main class="ow-admin-main" id="owAdminMain"></main></div>'
       . '<div class="ow-toast" id="owToast" style="display:none"></div>'
       . '<script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>'
       . '<script>OwAdmin.init(' . json_encode(['key' => $actor['key'], 'ts' => time()]) . ');</script>'
       . '</body></html>';
}
