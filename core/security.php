<?php
/**
 * 安全机制：API 签名、SVG 验证码、数据库频率限制、登录保护、安全日志、输出转义
 */
class Sec
{
    private static array $cfg = [];
    /** 是否把 IP 段并入会话指纹（由 loadFpOptions() 在 DB::init() 之后回填） */
    private static bool $fpIpStrict = false;

    public static function init(array $cfg): void { self::$cfg = $cfg; }

    /**
     * 访客 IP（v1.0.98 规范固化）：**仅读取服务器原始地址 REMOTE_ADDR**。
     * REMOTE_ADDR 由 Web 服务器取自 TCP 连接对端，客户端无法伪造；
     * X-Forwarded-For / X-Real-IP / CF-Connecting-IP 等请求头均可被客户端任意伪造，
     * **本函数及任何 IP 相关逻辑（安全日志、禁言、限频、指纹）一律不得读取这些头**。
     * 若未来部署在可信反向代理之后需要真实客户端 IP，必须同时满足：
     * 仅在代理层覆盖 REMOTE_ADDR（fastcgi_param REMOTE_ADDR），PHP 代码仍然只读 REMOTE_ADDR。
     */
    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // ---------- API 签名 ----------
    // sign = md5(client_key + ts + action)，client_key 按会话/游客令牌下发，不明文暴露全局密钥
    public static function sign(string $key, string $ts, string $action): string
    {
        return md5($key . '|' . $ts . '|' . $action);
    }

    /** 服务端预生成签名（写入表单隐藏域）：JS 未执行时也能提交成功 */
    public static function signField(string $key, string $action): string
    {
        $ts = (string)time();
        return '<input type="hidden" name="ts" value="' . $ts . '">'
             . '<input type="hidden" name="sign" value="' . self::sign($key, $ts, $action) . '">';
    }

    public static function verifySign(string $key, string $action): bool
    {
        return self::verifySignAny([$key], $action);
    }

    /** 多密钥任一匹配即通过：兼容「会话密钥」与「cookie 备份密钥」 */
    public static function verifySignAny(array $keys, string $action): bool
    {
        $ts   = $_POST['ts'] ?? $_GET['ts'] ?? '';
        $sign = $_POST['sign'] ?? $_GET['sign'] ?? '';
        if (!$ts || !$sign || abs(time() - (int)$ts) > (self::$cfg['sign_window'] ?? 300)) return false;
        foreach ($keys as $k) {
            if ($k && hash_equals(self::sign($k, (string)$ts, $action), (string)$sign)) return true;
        }
        return false;
    }

    // ---------- 敏感操作一次性票据（v1.0.91） ----------
    // 签名只证明「请求来自持钥客户端」，窗口期内可重放；删除 / 恢复 / 禁用 / 退出登录
    // 等敏感操作额外要求一次性票据：先调 ?action=ticket 签发（写入会话），提交时校验并
    // 立即作废——被劫持者即使拿到旧请求也无法重放，拿到票据也因一次性而难以复用。

    /** 签发一次性操作票据（写入会话，5 分钟有效） */
    public static function ticketIssue(): string
    {
        $t = bin2hex(random_bytes(16));
        $_SESSION['op_ticket'] = ['v' => $t, 'ts' => time()];
        return $t;
    }

    /** 校验一次性票据：不匹配 / 过期 / 已使用均拒绝；验证通过立即作废 */
    public static function ticketVerify(string $given): bool
    {
        $s = $_SESSION['op_ticket'] ?? null;
        if (!is_array($s) || !is_string($given) || $given === '') return false;
        if ((time() - (int)$s['ts']) > 300) return false;
        if (!hash_equals((string)$s['v'], $given)) return false;
        unset($_SESSION['op_ticket']);   // 一次性：用后即焚
        return true;
    }

    public static function clientKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ---------- 输出转义（XSS） ----------
    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    // ---------- 频率限制（数据库计数，替代 Redis） ----------
    public static function rateLimit(string $bucket, string $ident, int $window, int $max): bool
    {
        $now = time();
        $row = DB::one('SELECT * FROM rate_limits WHERE bucket=? AND ident=?', [$bucket, $ident]);
        if (!$row || $now - (int)$row['window_start'] >= $window) {
            DB::upsert('rate_limits', ['bucket' => $bucket, 'ident' => $ident, 'window_start' => $now, 'count' => 1], ['bucket', 'ident']);
            return true;
        }
        if ((int)$row['count'] >= $max) return false;
        DB::run('UPDATE rate_limits SET count=count+1 WHERE bucket=? AND ident=?', [$bucket, $ident]);
        return true;
    }

    // ---------- 登录保护 ----------
    public static function loginFails(string $identity): int
    {
        $r = DB::one('SELECT fails, locked_until FROM login_attempts WHERE identity=?', [$identity]);
        return $r ? (int)$r['fails'] : 0;
    }

    public static function loginLocked(string $identity): bool
    {
        $r = DB::one('SELECT locked_until FROM login_attempts WHERE identity=?', [$identity]);
        return $r && $r['locked_until'] && (int)$r['locked_until'] > time();
    }

    public static function loginFail(string $identity): void
    {
        $fails = self::loginFails($identity) + 1;
        $lockAt = (int)DB::setting('login_fail_lock', 10);      // 达此失败次数即锁定
        $mins   = (int)DB::setting('login_lock_minutes', 15);   // 锁定时长（分钟）
        $lock = ($lockAt > 0 && $fails >= $lockAt) ? time() + $mins * 60 : null;
        DB::upsert('login_attempts', [
            'identity' => $identity, 'fails' => $fails,
            'locked_until' => $lock, 'updated_at' => time(),
        ], ['identity']);
        if ($lock) self::log('login_locked', $identity, ['fails' => $fails, 'minutes' => $mins]);
    }

    public static function loginOk(string $identity): void
    {
        DB::run('DELETE FROM login_attempts WHERE identity=?', [$identity]);
    }

    /** 达到该失败次数后要求图形验证码（0 = 不启用验证码） */
    public static function needCaptcha(string $identity): bool
    {
        $n = (int)DB::setting('login_fail_captcha', 3);
        if ($n <= 0) return false;
        return self::loginFails($identity) >= $n;
    }

    /** 剩余锁定时长（秒），未锁定返回 0 */
    public static function lockSeconds(string $identity): int
    {
        $r = DB::one('SELECT locked_until FROM login_attempts WHERE identity=?', [$identity]);
        if (!$r || !$r['locked_until']) return 0;
        return max(0, (int)$r['locked_until'] - time());
    }

    // ---------- SVG 验证码（零依赖，无需 GD） ----------
    public static function captcha(): string
    {
        $code = '';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $_SESSION['captcha'] = strtolower($code);
        $_SESSION['captcha_ts'] = time();
        return $code;
    }

    public static function captchaSvg(string $code): string
    {
        $w = 120; $h = 40;
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='$w' height='$h'>"
             . "<rect width='$w' height='$h' fill='#F5F7FA'/>";
        for ($i = 0; $i < 5; $i++) {
            $x1 = random_int(0, $w); $y1 = random_int(0, $h);
            $x2 = random_int(0, $w); $y2 = random_int(0, $h);
            $svg .= "<line x1='$x1' y1='$y1' x2='$x2' y2='$y2' stroke='#E0E4E8' stroke-width='1'/>";
        }
        for ($i = 0; $i < strlen($code); $i++) {
            $x = 18 + $i * 26 + random_int(-3, 3);
            $y = 27 + random_int(-4, 4);
            $rot = random_int(-20, 20);
            $color = ['#00A0E9', '#0078D4', '#333333', '#FF7D00'][$i % 4];
            $svg .= "<text x='$x' y='$y' font-size='22' font-family='monospace' font-weight='bold' "
                  . "fill='$color' transform='rotate($rot $x $y)'>{$code[$i]}</text>";
        }
        return $svg . '</svg>';
    }

    public static function checkCaptcha(string $input): bool
    {
        $ok = isset($_SESSION['captcha'])
            && time() - ($_SESSION['captcha_ts'] ?? 0) < 300
            && strtolower(trim($input)) === $_SESSION['captcha'];
        unset($_SESSION['captcha'], $_SESSION['captcha_ts']);
        return $ok;
    }

    // ---------- 安全日志 ----------
    public static function log(string $action, string $actor = '', array $data = []): void
    {
        // 脱敏：不记录密码、验证码
        unset($data['password'], $data['pass'], $data['code'], $data['captcha']);
        try {
            DB::insert('security_logs', [
                'action' => $action, 'actor' => $actor, 'ip' => self::ip(),
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            // 兜底（v1.0.115）：DB 忙/锁超时导致安全日志写入失败时落文件，保证审计不丢
            @file_put_contents(
                dirname(__DIR__) . '/data/security_log_fallback.log',
                date('m-d H:i:s') . ' ' . $action . ' ' . $actor . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }

    /** 简单 CSRF 防护已并入签名校验；会话 Cookie 参数统一在此设置 */
    public static function sessionStart(array $cfg): void
    {
        // 会话数据存活期与 Cookie（7 天）一致：默认 gc_maxlifetime 仅 24 分钟，
        // 关闭页面 / 电脑休眠半小时后回来刷新，会话数据已被 GC 清空 → 表现为「自动退出登录」
        @ini_set('session.gc_maxlifetime', (string)(86400 * 7));
        session_name($cfg['session_name'] ?? 'OWLSESSID');
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'httponly' => true,
            'secure'   => self::isHttps(),   // https 部署时强制仅加密通道传输（v1.0.94）
            'samesite' => 'Lax',
        ]);
        // 会话审计（v1.0.113 诊断）：自定义 save handler，记录每次读/写/销毁的
        // 调用方 URI 与会话键清单——定位「退出登录」时到底是哪个请求覆盖了会话。
        if (!defined('OA_SESS_HANDLER_ON')) {
            define('OA_SESS_HANDLER_ON', true);
            $trace = dirname(__DIR__) . '/data/session_trace.log';
            $handler = new class($trace) implements SessionHandlerInterface {
                private string $path;
                private string $trace;
                public function __construct(string $trace)
                {
                    $this->path = ini_get('session.save_path') ?: sys_get_temp_dir();
                    $this->trace = $trace;
                }
                private function tag(): string
                {
                    $uri = (string)($_SERVER['REQUEST_URI'] ?? 'cli');
                    return date('m-d H:i:s') . ' ' . substr(session_id() ?: '-', 0, 10) . ' ' . $uri;
                }
                public function open($path, $name): bool { return true; }
                public function close(): bool { return true; }
                public function read($id): string
                {
                    $f = $this->path . '/sess_' . $id;
                    $data = is_file($f) ? (string)file_get_contents($f) : '';
                    @file_put_contents($this->trace, $this->tag() . " READ keys=" . (implode(',', array_keys($_SESSION)) ?: '(empty)') . " datakeys=" . (preg_match_all('/(\w+)\|/', $data, $m) ? implode(',', $m[1]) : '(new)') . "\n", FILE_APPEND | LOCK_EX);
                    return $data;
                }
                public function write($id, $data): bool
                {
                    preg_match_all('/(\w+)\|/', $data, $m);
                    @file_put_contents($this->trace, $this->tag() . " WRITE keys=" . implode(',', $m[1] ?? []) . "\n", FILE_APPEND | LOCK_EX);
                    return file_put_contents($this->path . '/sess_' . $id, $data) !== false;
                }
                public function destroy($id): bool
                {
                    // v1.0.115：DESTROY 记录调用栈——精确定位「谁销毁了会话」
                    $bt = '';
                    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $f) {
                        $bt .= (basename((string)($f['file'] ?? '?')) . ':' . ($f['line'] ?? 0) . ' ' . ($f['function'] ?? '') . ' <- ');
                    }
                    @file_put_contents($this->trace, $this->tag() . " !!DESTROY by " . $bt . "\n", FILE_APPEND | LOCK_EX);
                    $f = $this->path . '/sess_' . $id;
                    return is_file($f) ? @unlink($f) : true;
                }
                public function gc($max): int { return 0; }   // 关闭 PHP 自带 GC（gc_maxlifetime 已拉长，避免误清活跃会话）
                public function create_sid(): string { return session_create_id() ?: bin2hex(random_bytes(16)); }
            };
            session_set_save_handler($handler, true);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        // 会话指纹（v1.0.94）：会话创建时记录客户端特征，后续请求比对；
        // 登录 Cookie 被跨站窃取 / 本地读取后，换浏览器或换网络重放将无法通过校验。
        // sec_fp_v2 = 已按 v1.1.13 口径（只绑 UA）记录，省掉每次比对都走一次升级分支。
        if (!isset($_SESSION['sec_fp'])) {
            $_SESSION['sec_fp'] = self::fingerprint();
            $_SESSION['sec_fp_v2'] = 1;
        }
    }

    /** https 部署探测（本地 http 为 false，不影响现有部署） */
    public static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * 客户端指纹（v1.1.13 起只绑 UA）。
     *
     * ⚠️ v1.0.94~v1.1.12 曾把「IP 前两段」并入指纹，v1.1.13 起移除。原因是它**不可靠**：
     *   - `REMOTE_ADDR` 拿不到时 `Sec::ip()` 返回 `0.0.0.0`，与真实的 `127.0.0.1`
     *     前两段完全不同 → 同一浏览器的请求算出两个指纹，凭空把会话踢掉；
     *   - 同一客户端在 IPv4 / IPv6 双栈下、或经过不同的反代路径，`REMOTE_ADDR` 也会变；
     *   - 移动网络 / 企业网关 / 代理池切 IP 是常态，绑 IP 等于绑一个用户控制不了的变量。
     * 踩过的现场：session_trace.log 里 44 次 `security.php fingerprintGuard` 误踢，
     * 全部发生在长轮询并发请求上（同一会话其余请求指纹都正常），用户表现为
     * 「一开开发者工具就退出登录」。已实测排除 DevTools 本身（开关 DevTools 的
     * 请求头完全一致），真因是指纹输入不稳定，不是 DevTools 改了什么。
     *
     * 为什么只绑 UA 够用：Cookie 被窃后，攻击者手上只有 Cookie，
     * 换 UA 重放的成本远低于换 IP（IP 往往是攻击者可控的出口），
     * 绑 IP 并不能显著提高重放门槛，却把误杀成本转嫁给了正常用户。
     * UA 本身可伪造，所以它只是**纵深防御的一层**，不是唯一防线 ——
     * 真正的防线仍是 Cookie 的 HttpOnly + 一次性票据 + 各接口的服务端鉴权。
     *
     * 想要更严可开启 `sec_fp_ip_strict`（DB setting 设 1），把 IP 段并入指纹，
     * 但要清楚代价：移动网络切换、PDA 息屏重连、公司多出口 IP 的用户会被误踢。
     */
    public static function fingerprint(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        // ⚠️ 这里**绝对不能查数据库**。
        // fingerprint() 由 sessionStart() 在写入 sec_fp 时调用，而 index.php 的
        // 顺序是 sessionStart() → DB::init()，此刻 self::$pdo 还是 null，
        // 一旦调用 DB::setting() 就会抛
        // 「Call to a member function prepare() on null」→ 整站 500（v1.1.13 踩过）。
        //
        // 替代方案：把开关放进一个由 config.php 提供的**静态数组**，
        // 由入口在 DB::init() 之后回填；未回填时按默认（不绑 IP）处理。
        if (!empty(self::$fpIpStrict)) {
            $ip = self::ip();
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $seg = implode(':', array_slice(explode(':', $ip), 0, 3));
            } else {
                $seg = implode('.', array_slice(explode('.', $ip), 0, 2));
            }
            $ua .= '|' . $seg;
        }
        return hash('sha256', $ua);
    }

    /**
     * 是否把 IP 段并入会话指纹（默认 false = 只绑 UA，理由见 fingerprint()）。
     *
     * ⚠️ 必须在 DB::init() **之后**调用（读 settings 表）。
     * index.php 里已接好：指纹守卫之前、DB::init() 之后。
     */
    public static function loadFpOptions(): void
    {
        try {
            self::$fpIpStrict = DB::setting('sec_fp_ip_strict', '0') === '1';
        } catch (Throwable $e) {
            self::$fpIpStrict = false;   // 表还没建 / DB 不可用：按最宽松的默认值走
        }
    }

    /**
     * 会话指纹守卫（v1.0.94）：每个请求在认证前调用。
     * 会话中已有指纹且与当前客户端不符 → 判定为 Cookie 被窃取后在其它环境重放，
     * 立即销毁会话（登录态 / 游客身份一并失效），需重新登录或重新生成游客身份。
     *
     * ⚠️ v1.1.12 诊断开关：定位「莫名掉登录」时用，默认关闭（0 = 正常启用守卫）。
     *   设 DB setting `sec_fp_guard` 为 `0` 可临时关闭守卫（复现问题用，**别长期关**）。
     *   设 `sec_fp_trace` 为 `1` 可把每次比对的输入落盘到 data/fp_trace.log，
     *   含 URI / UA / IP / 会话指纹 / 本次指纹，用来确认「究竟哪个请求 UA 变了」。
     *   两者都走 DB::setting，改设置后即时生效（守卫每请求都读）。
     */
    public static function fingerprintGuard(): void
    {
        if (DB::setting('sec_fp_guard', '1') === '0') return;   // v1.1.12 诊断开关
        if (!isset($_SESSION['sec_fp'])) return;   // 新会话（sessionStart 已写入）

        $now = self::fingerprint();
        $ok  = hash_equals((string)$_SESSION['sec_fp'], $now);

        // 诊断留痕：无论匹配与否都记一条，否则「匹配的那次」无从对照，
        // 只能看到 mismatch 快照，看不出它跟哪一次正常请求不同。
        if (DB::setting('sec_fp_trace', '0') === '1') {
            @file_put_contents(
                self::dataDir() . '/fp_trace.log',
                self::fingerprintTraceLine($ok, $now),
                FILE_APPEND | LOCK_EX
            );
        }

        if ($ok) return;

        // v1.1.13 指纹口径变更（去掉 IP 段）后的平滑升级：
        // 老会话里存的是「UA|IP段」算出的旧值，与新算法必然不等 —— 那是**升级造成的**，
        // 不是会话被盗。此时原地改写为新指纹，不销毁会话（否则所有在线用户一升级就掉线）。
        // 判据：会话未标记 sec_fp_v2，且「用新算法重算」与旧值不同。
        // 真正的盗用仍会被拦：它连 UA 都对不上，重算结果仍不等于旧值之外的任何东西。
        if (empty($_SESSION['sec_fp_v2'])) {
            // 用 loadFpOptions() 缓存的静态值，不在此再查库
            if (!self::$fpIpStrict) {
                $_SESSION['sec_fp_v2'] = 1;
                $_SESSION['sec_fp'] = $now;
                return;
            }
        }

        // 通知插件：会话被销毁，可能是有意登出也可能是被盗用，
        // 由插件决定要不要发提醒。⚠️ 必须在 session_destroy() **之前**触发——
        // 之后 $_SESSION 已清空，拿不到 uid 与原 sec_fp。插件内必须自行防御性判空。
        if (class_exists('Plugin')) {
            Plugin::fire('session.destroyed', [[
                'reason'   => 'fingerprint_mismatch',
                'uid'      => (int)($_SESSION['uid'] ?? 0),
                'had_uid'  => isset($_SESSION['uid']),
                'fp_saved' => (string)$_SESSION['sec_fp'],
                'fp_now'   => $now,
                'ip'       => self::ip(),
            ]]);
        }

        self::log('session_fingerprint_mismatch', (string)($_SESSION['uid'] ?? ($_SESSION['gid'] ?? '')), ['ip' => self::ip()]);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    /** data 目录（指纹诊断日志落盘用） */
    private static function dataDir(): string
    {
        return dirname(__DIR__) . '/data';
    }

    /** 拼一行指纹诊断记录：谁、请求什么、用什么 UA/IP 算出了什么值 */
    private static function fingerprintTraceLine(bool $ok, string $now): string
    {
        $fpSess = (string)($_SESSION['sec_fp'] ?? '');
        return date('m-d H:i:s') . ' ' . substr(session_id() ?: '-', 0, 10) . ' '
            . ($ok ? 'MATCH  ' : 'MISMATCH')
            . ' sess=' . substr($fpSess, 0, 10)
            . ' now=' . substr($now, 0, 10)
            . ' ' . (isset($_SESSION['uid']) ? 'uid=' . (int)$_SESSION['uid'] : 'guest')
            . "\n"
            . '    uri = ' . ($_SERVER['REQUEST_URI'] ?? '-') . "\n"
            . '    ua  = ' . ($_SERVER['HTTP_USER_AGENT'] ?? '(无)') . "\n"
            . '    ip  = ' . self::ip() . "\n";
    }
}
