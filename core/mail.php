<?php
/**
 * 纯 PHP SMTP 客户端（零依赖），用于注册验证码 / 密码找回邮件
 */
class Mailer
{
    private array $cfg;

    public function __construct(array $cfg) { $this->cfg = $cfg['mail']; }

    public function send(string $to, string $subject, string $body): bool
    {
        if (($this->cfg['driver'] ?? 'smtp') === 'mail') {
            $headers = "From: =?UTF-8?B?" . base64_encode($this->cfg['from_name']) . "?= <{$this->cfg['from']}>\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n";
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        }
        return $this->smtp($to, $subject, $body);
    }

    private function smtp(string $to, string $subject, string $body): bool
    {
        $c = $this->cfg;
        if (!$c['host']) return false;
        $host = ($c['secure'] === 'ssl' ? 'ssl://' : '') . $c['host'];
        $fp = @fsockopen($host, (int)$c['port'], $errno, $errstr, 15);
        if (!$fp) return false;
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function (string $c) use ($fp, $read) {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        $read(); // 220
        $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if (($c['secure'] ?? '') === 'tls') {
            $cmd('STARTTLS');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        if ($c['user']) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($c['user']));
            $r = $cmd(base64_encode($c['pass']));
            if (strpos($r, '235') === false) { fclose($fp); return false; }
        }
        $cmd("MAIL FROM:<{$c['from']}>");
        $cmd("RCPT TO:<$to>");
        $r = $cmd('DATA');
        if (strpos($r, '354') === false) { fclose($fp); return false; }
        $headers = "From: =?UTF-8?B?" . base64_encode($c['from_name']) . "?= <{$c['from']}>\r\n"
                 . "To: <$to>\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
        $r = $read();
        $cmd('QUIT');
        fclose($fp);
        return strpos($r, '250') !== false;
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
        $ok = (new Mailer($cfg))->send(
            $email,
            "[$site] 验证码 $code",
            "您的验证码是：$code（10 分钟内有效）。\n若非本人操作请忽略本邮件。"
        );
        if (!$ok) return [false, '邮件发送失败，请联系管理员检查 SMTP 配置'];
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
