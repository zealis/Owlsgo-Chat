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

    public static function verifySign(string $key, string $action): bool
    {
        $ts   = $_POST['ts'] ?? $_GET['ts'] ?? '';
        $sign = $_POST['sign'] ?? $_GET['sign'] ?? '';
        if (!$ts || !$sign || abs(time() - (int)$ts) > (self::$cfg['sign_window'] ?? 300)) return false;
        return hash_equals(self::sign($key, (string)$ts, $action), (string)$sign);
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
        $lock = $fails >= 10 ? time() + 900 : null; // 10 次失败锁 15 分钟
        DB::upsert('login_attempts', [
            'identity' => $identity, 'fails' => $fails,
            'locked_until' => $lock, 'updated_at' => time(),
        ], ['identity']);
    }

    public static function loginOk(string $identity): void
    {
        DB::run('DELETE FROM login_attempts WHERE identity=?', [$identity]);
    }

    public static function needCaptcha(string $identity): bool
    {
        return self::loginFails($identity) >= 3;
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
