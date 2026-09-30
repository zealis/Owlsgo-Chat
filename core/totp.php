<?php
/**
 * TOTP 两步验证（RFC 6238）—— 零依赖实现
 *
 * 算法：TOTP = HOTP(HMAC-SHA1(密钥, 时间步), 6 位动态截断)
 *   - 时间步（period）：默认 30 秒，与 Google Authenticator / Microsoft Authenticator /
 *     1Password / 微信「动态口令」等主流验证器一致
 *   - 校验窗口：默认前后各 1 个时间步（±30 秒），容忍手机与服务器的时钟偏差
 *
 * 密钥以 Base32 明文存储（TOTP 必须凭原始密钥才能算出动态码，无法像密码那样只存哈希），
 * 因此 user_2fa 表不得对外暴露；恢复码则只存哈希（见 Auth::genRecoveryCodes）。
 */
class Totp
{
    /** 时间步长（秒）：主流验证器均为 30 */
    public const PERIOD = 30;
    /** 动态码位数 */
    public const DIGITS = 6;
    /** 密钥字节数（160 bit，RFC 4226 推荐） */
    private const SECRET_BYTES = 20;

    /** 生成新密钥（Base32 字符串，未分组） */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * 计算指定时间点（或时间步）的动态码
     * @param string   $secret Base32 密钥
     * @param int|null $time   时间戳；null 表示当前时间
     */
    public static function code(string $secret, ?int $time = null): string
    {
        $slice = intdiv($time ?? time(), self::PERIOD);
        return self::hotp($secret, $slice);
    }

    /**
     * 校验动态码
     * @param string $secret Base32 密钥
     * @param string $code   用户输入的 6 位码
     * @param int    $window 前后容忍的时间步数（默认 1，即 ±30 秒）
     * @return bool 通过返回 true；返回 false 时不会泄露是「过期」还是「错误」
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', (string)$code);
        if ($code === '' || strlen($code) !== self::DIGITS) return false;
        $now = time();
        $cur = intdiv($now, self::PERIOD);
        // 定长比较，避免时序差异
        $match = false;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secret, $cur + $i), $code)) $match = true;
        }
        return $match;
    }

    /**
     * 生成 otpauth:// 配置串：验证器 App 可手动粘贴或用扫码工具录入
     * @param string $secret  密钥
     * @param string $account 账号标识（本项目用「昵称(用户ID)」）
     * @param string $issuer  发行方（站点名）
     */
    public static function provisionUri(string $secret, string $account, string $issuer = 'Owlsgo-Chat'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** 密钥按 4 字符分组，方便人工抄写 */
    public static function prettySecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /* ================= RFC 4226 HOTP ================= */

    private static function hotp(string $secret, int $counter): string
    {
        $bin = self::base32Decode($secret);
        if ($bin === '') return '';
        // 计数器为 64 位大端
        $packed = pack('J', $counter);
        $hash = hash_hmac('sha1', $packed, $bin, true);
        // 动态截断：取最后一个字节的低 4 位作为偏移量
        $offset = ord($hash[19]) & 0x0F;
        $binCode = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($binCode % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /* ================= Base32（RFC 4648） ================= */

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bin): string
    {
        $out = '';
        $bits = 0; $value = 0;
        for ($i = 0, $len = strlen($bin); $i < $len; $i++) {
            $value = ($value << 8) | ord($bin[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) $out .= self::ALPHABET[($value << (5 - $bits)) & 31];
        return $out;
    }

    /** 宽松解析：忽略空格、大小写与末尾填充符 '=' */
    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=]+/', '', (string)$s) ?? '');
        $out = '';
        $bits = 0; $value = 0;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $p = strpos(self::ALPHABET, $s[$i]);
            if ($p === false) return '';
            $value = ($value << 5) | $p;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }
        return $out;
    }
}
