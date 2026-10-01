<?php
/**
 * 邮件验证码：生成 / 频控 / 校验。
 *
 * 发送通道不在核心（v1.0.81 起 SMTP 客户端已移除）：sendCode 触发
 * `mail.send` 钩子，由邮件插件（SMTP / 第三方 API 等）完成实际发送；
 * 无人响应时验证码仍入库但不发送，接口返回明确提示。
 */
class Mailer
{
    /**
     * 触发 mail.send 钩子。插件把 $sent 置为 true 即视为发送成功。
     *
     * @param string $to      收件邮箱
     * @param string $subject 主题
     * @param string $body    正文（纯文本）
     * @return bool 是否有插件完成发送
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        if (!class_exists('Plugin', false)) return false;
        $sent = false;
        Plugin::fire('mail.send', [&$sent, $to, $subject, $body]);
        return $sent === true;
    }

    /** 生成并持久化邮箱验证码，带邮件频率限制 */
    public static function sendCode(string $email, string $type, array $cfg): array
    {
        $interval = (int)DB::setting('mail_rate_limit', 60);
        if (!Sec::rateLimit('mail', $email, $interval, 1)) {
            return [false, '发送过于频繁，请 ' . $interval . ' 秒后再试'];
        }
        if (!Sec::rateLimit('mail_ip', Sec::ip(), 3600, 20)) {
            return [false, '当前 IP 邮件发送已达上限，请稍后再试'];
        }
        $code = (string)random_int(100000, 999999);
        DB::insert('email_codes', [
            'email' => $email, 'code' => $code, 'type' => $type,
            'ip' => Sec::ip(), 'used' => 0,
            'expires_at' => time() + 600, 'created_at' => time(),
        ]);
        $site = DB::setting('site_name', 'Owlsgo-Chat');
        $ok = self::send(
            $email,
            "[$site] 验证码 $code",
            "您的验证码是：$code（10 分钟内有效）。\n若非本人操作请忽略本邮件。"
        );
        if (!$ok) return [false, '站点未启用邮件发送（未安装邮件插件），请联系管理员'];
        return [true, '验证码已发送'];
    }

    public static function verifyCode(string $email, string $type, string $code): bool
    {
        $row = DB::one(
            'SELECT id FROM email_codes WHERE email=? AND type=? AND code=? AND used=0 AND expires_at>? ORDER BY id DESC LIMIT 1',
            [$email, $type, $code, time()]
        );
        if (!$row) return false;
        DB::run('UPDATE email_codes SET used=1 WHERE id=?', [$row['id']]);
        return true;
    }
}
