<?php
/**
 * 安全机制：API 签名、SVG 验证码、数据库频率限制、登录保护、安全日志、输出转义
 */
class Sec
{
    private static array $cfg = [];

    public static function init(array $cfg): void { self::$cfg = $cfg; }

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
        DB::insert('security_logs', [
            'action' => $action, 'actor' => $actor, 'ip' => self::ip(),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => time(),
        ]);
    }

    /** 简单 CSRF 防护已并入签名校验；会话 Cookie 参数统一在此设置 */
    public static function sessionStart(array $cfg): void
    {
        session_name($cfg['session_name'] ?? 'OWLSESSID');
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }
}
