<?php
/**
 * 用户系统：注册 / 登录 / 找回 / 游客 / 资料卡 / 角色与称号
 */
class Auth
{
    /** 当前登录用户（数组）或 null */
    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) return null;
        $u = DB::one('SELECT * FROM users WHERE id=? AND status=1', [$_SESSION['uid']]);
        // client_key 为空（历史数据/手工建号）会导致该用户所有 API 签名失败，这里自动补全
        if ($u && (string)($u['client_key'] ?? '') === '') $u = self::ensureKey($u, 'users');
        return $u;
    }

    /** 保证访问者持有签名密钥 */
    private static function ensureKey(array $row, string $table): array
    {
        $key = Sec::clientKey();
        DB::run("UPDATE $table SET client_key=? WHERE id=?", [$key, $row['id']]);
        $row['client_key'] = $key;
        return $row;
    }

    /** 当前游客（数组）或 null */
    public static function guest(): ?array
    {
        $token = $_COOKIE['owl_guest'] ?? '';
        if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $g = DB::one('SELECT * FROM guests WHERE token=?', [$token]);
        if ($g && (string)($g['client_key'] ?? '') === '') $g = self::ensureKey($g, 'guests');
        return $g;
    }

    /** 确保游客身份存在（允许游客浏览时调用） */
    public static function ensureGuest(): ?array
    {
        $g = self::guest();
        if ($g) return $g;
        $token = md5(random_bytes(16));
        $nickname = '游客' . substr(bin2hex(random_bytes(3)), 0, 5);
        $id = DB::insert('guests', [
            'token' => $token, 'nickname' => $nickname,
            'client_key' => Sec::clientKey(), 'ip' => Sec::ip(),
            'daily_count' => 0, 'daily_date' => date('Y-m-d'),
            'created_at' => time(),
        ]);
        setcookie('owl_guest', $token, [
            'expires' => time() + 86400 * 365, 'path' => '/',
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        return DB::one('SELECT * FROM guests WHERE id=?', [$id]);
    }

    /**
     * 计算周岁：传入 Y-m-d，返回年龄；日期非法或为未来日期返回 -1
     */
    public static function age(string $birthdate): int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthdate)) return -1;
        $b = DateTimeImmutable::createFromFormat('Y-m-d', $birthdate);
        $today = new DateTimeImmutable('today');
        if (!$b || $b > $today) return -1;
        return (int)$today->diff($b)->y;
    }

    /**
     * 昵称（v1.0.33 起是唯一的账号显示名，不再有独立用户名）合法性校验。
     *
     * 约束：2-20 个字符，仅允许中英文、数字、下划线与短横线。
     * 禁止空格：@提及在服务端按 (^|\s)@[^\s@]+ 切词，含空格会导致无法被提及。
     * 禁止 @：避免与 @提及 前缀冲突。
     * 允许重名：昵称不再承担唯一性职责，区分用户一律使用 id。
     *
     * 插件钩子（v1.0.46）：内置规则通过后触发 nickname.before_save，
     * 回调签名 function (&$nick, &$err, $ctx)——插件可改写 $nick，
     * 或把 $err 设为非空字符串表示拒绝（$err 即展示给用户的文案）。
     * $ctx['scene']：register / profile / install。
     *
     * @return array [bool 是否合法, string 归一化值或错误文案]
     */
    public static function checkNickname(string $nick, array $ctx = []): array
    {
        $s = trim($nick);
        if ($s === '') return [false, '请填写昵称'];
        if (!preg_match('/^[\p{L}\p{N}_\-]{2,20}$/u', $s)) {
            return [false, '昵称需 2-20 个字符，支持中英文、数字、下划线与短横线，不含空格或 @'];
        }
        // 插件校验：可改写昵称（引用）或通过 $err 拦截
        $err = null;
        Plugin::fire('nickname.before_save', [&$s, &$err, $ctx]);
        if (is_string($err) && $err !== '') return [false, $err];
        return [true, $s];
    }

    /**
     * 通用随机位段 ID 分配（v1.0.37 用户 ID 引入，v1.0.51 泛化供聊天室复用）：
     * 随机 3 位数字（001-999），该段占满后自动升为随机 4 位（1000-9999），依此类推。
     *
     * 设计要点：
     * - 应用层分配、INSERT 时显式指定 id。SQLite / MySQL / PostgreSQL 的自增
     *   主键都接受显式值，无需改表结构；已有记录的 id 一律保持不变。
     * - 先随机试探（段内空位多时碰撞率极低），试探失败再收集段内空位精确
     *   随机取一个，避免段快满时随机反复撞车。
     * - 并发插入撞主键时，由调用方捕获并重试。
     *
     * @param string $table 目标表（users / rooms 等，主键须为自增整数 id）
     * @return int 可用的 ID
     */
    public static function nextId(string $table): int
    {
        $max    = (int)(DB::val("SELECT MAX(id) FROM $table") ?: 0);
        $digits = max(3, strlen((string)$max));          // 至少 3 位（001-999）
        while (true) {
            $lo = $digits === 3 ? 1 : 10 ** ($digits - 1);   // 3 位段 1-999；4 位段 1000 起
            $hi = 10 ** $digits - 1;
            $occupied = (int)DB::val("SELECT COUNT(*) FROM $table WHERE id BETWEEN ? AND ?", [$lo, $hi]);
            if ($occupied < $hi - $lo + 1) break;        // 当前位段未满，可用
            $digits++;                                   // 段满自动升一位
        }
        // 随机试探 32 次；失败（段接近占满）时收集全部空位精确随机
        for ($i = 0; $i < 32; $i++) {
            $id = random_int($lo, $hi);
            if (!DB::one("SELECT id FROM $table WHERE id=?", [$id])) return $id;
        }
        $used = array_map('intval', array_column(
            DB::all("SELECT id FROM $table WHERE id BETWEEN ? AND ?", [$lo, $hi]), 'id'
        ));
        $free = array_values(array_diff(range($lo, $hi), $used));
        if (!$free) return self::nextId($table);         // 理论不可达（上方已判满），防御性递归
        return (int)$free[array_rand($free)];
    }

    /** 用户 ID：随机 3 位起步（管理员固定 001，安装向导显式指定 id=1） */
    public static function nextUserId(): int
    {
        return self::nextId('users');
    }

    public static function register(string $nickname, string $email, string $password, string $code, string $birthdate = ''): array
    {
        if (DB::setting('allow_register', '1') !== '1') return [false, '站点已关闭注册'];
        [$nickOk, $nick] = self::checkNickname($nickname, ['scene' => 'register']);
        if (!$nickOk) return [false, $nick];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, '邮箱格式不正确'];
        if (strlen($password) < 6) return [false, '密码至少 6 位'];
        if (DB::one('SELECT id FROM users WHERE email=?', [$email])) return [false, '该邮箱已注册'];
        if (DB::setting('reg_email_verify', '1') === '1' && !Mailer::verifyCode($email, 'register', $code)) {
            return [false, '邮箱验证码错误或已过期'];
        }
        // 年龄限制（周岁，按出生日期精确计算）
        $minAge = (int)DB::setting('min_register_age', 0);
        if ($minAge > 0) {
            $age = self::age($birthdate);
            if ($age < 0) return [false, '请选择有效的出生日期'];
            if ($age < $minAge) return [false, '注册需年满 ' . $minAge . ' 周岁（当前 ' . $age . ' 周岁）'];
        }
        // 随机 ID 分配（nextUserId）：并发注册同时分到同一 id 会撞主键，
        // PDO 异常模式下捕获后重试（重新随机），最多 5 次
        $data = [
            'nickname' => $nick, 'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'avatar' => '', 'role' => 'member',
            'client_key' => Sec::clientKey(), 'status' => 1,
            'email_verified' => DB::setting('reg_email_verify', '1') === '1' ? 1 : 0,
            'birthdate' => $birthdate,
            'created_at' => time(),
        ];
        $attempts = 0;
        while (true) {
            try {
                $data['id'] = self::nextUserId();
                $id = DB::insert('users', $data);
                break;
            } catch (Throwable $e) {
                if (++$attempts >= 5) throw $e;
            }
        }
        Sec::log('register', $nick, ['email' => $email, 'id' => $id]);
        return [true, '注册成功', $id];
    }

    /**
     * 登录：$identity 可为注册邮箱或数字用户 ID（取消用户名后的两种入口）。
     * ID 优先于邮箱匹配，保证纯数字身份不会被同名邮箱干扰；均为一次索引命中。
     */
    public static function login(string $identity, string $password): array
    {
        $key = strtolower($identity) . '|' . Sec::ip();
        if (Sec::loginLocked($key)) return [false, '失败次数过多，账号已临时锁定 15 分钟', 'locked'];
        $user = null;
        if (preg_match('/^\d{1,19}$/', $identity)) {
            $user = DB::one('SELECT * FROM users WHERE id=?', [(int)$identity]);
        }
        if (!$user) $user = DB::one('SELECT * FROM users WHERE email=?', [$identity]);
        if (!$user || !password_verify($password, $user['password'])) {
            Sec::loginFail($key);
            Sec::log('login_fail', $identity);
            $left = 10 - Sec::loginFails($key);
            return [false, '邮箱或用户 ID 不正确，或密码错误' . ($left <= 3 ? "，剩余 $left 次尝试机会" : ''), 'fail'];
        }
        if ((int)$user['status'] !== 1) return [false, '账号已被禁用'];

        // 已启用两步验证：密码正确也「不」建立登录会话，先进入待验证中间态，
        // 由 login_2fa 用动态码 / 恢复码完成第二步（中间态 10 分钟有效）
        if (self::twoFactorOn((int)$user['id'])) {
            $_SESSION['2fa_pending'] = [
                'uid'  => (int)$user['id'],
                'key'  => $key,             // 沿用同一次登录的限流键，二次验证失败一并计入
                'until' => time() + 600,
            ];
            Sec::log('login_2fa_pending', $user['nickname']);
            return [true, '需要两步验证', $user, 'need_2fa'];
        }

        Sec::loginOk($key);
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        DB::run('UPDATE users SET last_login=? WHERE id=?', [time(), $user['id']]);
        Sec::log('login', $user['nickname']);
        // 钩子：登录验证完成（无两步验证的用户，method 为 password）
        Plugin::fire('login.after_verify', [$user, 'password', ['ip' => Sec::ip()]]);
        return [true, '登录成功', $user];
    }

    /* ================= 两步验证（TOTP + 恢复码） ================= */

    /** 取用户的两步验证记录；未设置返回 null */
    public static function twoFactor(int $uid): ?array
    {
        return DB::one('SELECT * FROM user_2fa WHERE user_id=?', [$uid]) ?: null;
    }

    /** 是否已启用（仅 enabled=1 视为启用，未确认的密钥不算） */
    public static function twoFactorOn(int $uid): bool
    {
        $r = DB::one('SELECT enabled FROM user_2fa WHERE user_id=?', [$uid]);
        return $r && (int)$r['enabled'] === 1;
    }

    /**
     * 第一步（设置）：生成新密钥并暂存为「未启用」状态，需再用动态码确认才生效。
     * 重复调用会覆盖旧的未启用密钥；已启用时拒绝（防止误触导致旧设备失效）。
     *
     * @return array [ok, msg|secret, uri, account]
     */
    public static function twoFactorInit(int $uid, string $account, string $issuer = ''): array
    {
        if (self::twoFactorOn($uid)) return [false, '两步验证已启用，请先关闭再重新设置'];
        $secret = Totp::generateSecret();
        $issuer = $issuer !== '' ? $issuer : (string)DB::setting('site_name', 'Owlsgo-Chat');
        DB::upsert('user_2fa', [
            'user_id' => $uid, 'secret' => $secret, 'recovery' => null,
            'enabled' => 0, 'created_at' => time(),
        ], ['user_id']);
        return [true, $secret, Totp::provisionUri($secret, $account, $issuer), $account];
    }

    /**
     * 第二步（确认启用）：校验动态码 → 生效并生成恢复码。
     * 恢复码明文**只在这一次返回**，之后库中仅有哈希，无法再找回。
     *
     * @return array [bool, string, array 明文恢复码]
     */
    public static function twoFactorEnable(int $uid, string $code): array
    {
        $row = self::twoFactor($uid);
        if (!$row) return [false, '请先获取密钥', []];
        if (!Totp::verify((string)$row['secret'], $code)) return [false, '动态验证码不正确，请确认手机时间准确', []];
        $codes = self::genRecoveryCodes();
        DB::run('UPDATE user_2fa SET enabled=1, recovery=?, last_used=? WHERE user_id=?', [
            json_encode(array_values($codes['hash']), JSON_UNESCAPED_SLASHES), time(), $uid,
        ]);
        Sec::log('2fa_enable', (string)$uid);
        return [true, '已启用两步验证', $codes['plain']];
    }

    /** 关闭两步验证：需动态码验证（不接受恢复码，避免恢复码丢失后仍能关闭） */
    public static function twoFactorDisable(int $uid, string $code): array
    {
        $row = self::twoFactor($uid);
        if (!$row) return [false, '未开启两步验证'];
        if (!Totp::verify((string)$row['secret'], $code)) return [false, '动态验证码不正确'];
        DB::run('DELETE FROM user_2fa WHERE user_id=?', [$uid]);
        Sec::log('2fa_disable', (string)$uid);
        return [true, '已关闭两步验证'];
    }

    /** 重置恢复码：需动态码验证，返回新的明文恢复码（仅此一次） */
    public static function recoveryReset(int $uid, string $code): array
    {
        $row = self::twoFactor($uid);
        if (!$row || (int)$row['enabled'] !== 1) return [false, '未开启两步验证', []];
        if (!Totp::verify((string)$row['secret'], $code)) return [false, '动态验证码不正确', []];
        $codes = self::genRecoveryCodes();
        DB::run('UPDATE user_2fa SET recovery=? WHERE user_id=?', [
            json_encode(array_values($codes['hash']), JSON_UNESCAPED_SLASHES), $uid,
        ]);
        Sec::log('2fa_recovery_reset', (string)$uid);
        return [true, '已重新生成恢复码', $codes['plain']];
    }

    /**
     * 第二步（登录）：校验动态码或恢复码 → 建立登录会话。
     * 失败沿用登录的限流键（identity|IP），与密码错误共用同一套失败/锁定策略。
     *
     * @param array  $pending $_SESSION['2fa_pending']
     * @param string $code    6 位动态码，或 10 位恢复码
     * @return array [bool, msg, $user|null, method]
     */
    public static function loginTwoStep(array $pending, string $code): array
    {
        $uid = (int)($pending['uid'] ?? 0);
        $key = (string)($pending['key'] ?? '');
        if ($uid <= 0 || $key === '') return [false, '验证状态无效，请重新登录', null, ''];
        if (Sec::loginLocked($key)) return [false, '失败次数过多，账号已临时锁定 15 分钟', null, 'locked'];

        $user = DB::one('SELECT * FROM users WHERE id=?', [$uid]);
        if (!$user || (int)$user['status'] !== 1) return [false, '账号状态异常，请重新登录', null, ''];
        $row = self::twoFactor($uid);
        if (!$row || (int)$row['enabled'] !== 1) return [false, '两步验证状态异常，请重新登录', null, ''];

        $method = '';
        if (Totp::verify((string)$row['secret'], $code)) $method = 'totp';
        elseif (self::useRecoveryCode($uid, $code)) $method = 'recovery';

        if ($method === '') {
            Sec::loginFail($key);
            Sec::log('login_2fa_fail', $user['nickname']);
            $left = 10 - Sec::loginFails($key);
            return [false, '动态验证码或恢复码不正确' . ($left <= 3 ? "，剩余 $left 次尝试机会" : ''), null, 'fail'];
        }

        DB::run('UPDATE user_2fa SET last_used=? WHERE user_id=?', [time(), $uid]);
        Sec::loginOk($key);
        session_regenerate_id(true);   // 登录成功更换会话 ID，防会话固定
        $_SESSION['uid'] = $uid;
        unset($_SESSION['2fa_pending']);
        DB::run('UPDATE users SET last_login=? WHERE id=?', [time(), $uid]);
        Sec::log('login', $user['nickname'], ['2fa' => $method]);
        // 钩子：两步验证通过（插件可做登录通知、异地提醒、设备白名单等）
        Plugin::fire('login.after_verify', [$user, $method, ['ip' => Sec::ip()]]);
        return [true, '验证通过', $user, $method];
    }

    /**
     * 生成恢复码：10 位小写十六进制 × 8 个，库里只存 password_hash 哈希
     * @return array ['plain' => string[], 'hash' => string[]]
     */
    private static function genRecoveryCodes(int $n = 8): array
    {
        $plain = []; $hash = [];
        for ($i = 0; $i < $n; $i++) {
            $c = bin2hex(random_bytes(5));
            $plain[] = $c;
            $hash[] = password_hash($c, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hash' => $hash];
    }

    /** 使用恢复码：命中即作废（一次性），从库中移除该哈希 */
    private static function useRecoveryCode(int $uid, string $code): bool
    {
        $row = self::twoFactor($uid);
        if (!$row) return false;
        $list = json_decode((string)($row['recovery'] ?? ''), true);
        if (!is_array($list)) return false;
        $code = strtolower(trim($code));
        foreach ($list as $i => $h) {
            if (is_string($h) && password_verify($code, $h)) {
                array_splice($list, $i, 1);
                DB::run('UPDATE user_2fa SET recovery=? WHERE user_id=?', [
                    json_encode(array_values($list), JSON_UNESCAPED_SLASHES), $uid,
                ]);
                return true;
            }
        }
        return false;
    }

    public static function logout(): void
    {
        Sec::log('logout', $_SESSION['uname'] ?? '');
        $_SESSION = [];
        session_destroy();
    }

    public static function resetPassword(string $email, string $code, string $password): array
    {
        $user = DB::one('SELECT * FROM users WHERE email=?', [$email]);
        if (!$user) return [false, '该邮箱未注册'];
        if (strlen($password) < 6) return [false, '密码至少 6 位'];
        if (!Mailer::verifyCode($email, 'reset', $code)) return [false, '验证码错误或已过期'];
        DB::run('UPDATE users SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        Sec::log('reset_password', $user['nickname']);
        return [true, '密码已重置，请重新登录'];
    }

    /**
     * 资料更新：昵称 / 头像。
     * 昵称已是账号显示名，规则与注册保持一致（见 checkNickname），避免注册能填、改资料填不了的割裂。
     *
     * 历史同步：messages 表的 nickname / avatar / to_nickname 是发送时的快照，
     * 改资料后必须一并刷新，否则历史消息仍显示旧昵称旧头像（v1.0.39 修复）。
     * online 在线表无需处理：每次心跳都用实时 users 数据重写。
     */
    public static function updateProfile(array $user, string $nickname, string $avatar): array
    {
        [$ok, $nick] = self::checkNickname($nickname, ['scene' => 'profile']);
        if (!$ok) return [false, $nick];
        $uid = (int)$user['id'];
        DB::run('UPDATE users SET nickname=?, avatar=? WHERE id=?', [$nick, $avatar, $uid]);
        // 同步本人发出的历史消息（含私信里的「对我」显示名）
        DB::run('UPDATE messages SET nickname=?, avatar=? WHERE user_id=?', [$nick, $avatar, $uid]);
        DB::run('UPDATE messages SET to_nickname=? WHERE to_user_id=?', [$nick, $uid]);
        return [true, '资料已更新'];
    }

    /** 角色权重（数值越大权限越高） */
    public static function roleLevel(string $role): int
    {
        return ['guest' => 0, 'member' => 1, 'vip' => 2, 'admin' => 9][$role] ?? 0;
    }

    public static function isAdmin(?array $user): bool
    {
        return $user && $user['role'] === 'admin';
    }

    /** 当前访问者摘要（前端展示/签名用） */
    public static function actor(?array $user, ?array $guest): array
    {
        if ($user) {
            return [
                'kind' => 'user', 'id' => (int)$user['id'],
                'nickname' => $user['nickname'],
                'role' => $user['role'], 'title' => $user['title'] ?? '',
                'avatar' => $user['avatar'] ?? '', 'key' => $user['client_key'],
                'birthdate' => (string)($user['birthdate'] ?? ''),
            ];
        }
        if ($guest) {
            return [
                'kind' => 'guest', 'id' => (int)$guest['id'],
                'nickname' => $guest['nickname'],
                'role' => 'guest', 'title' => '', 'avatar' => '',
                'key' => $guest['client_key'],
            ];
        }
        return ['kind' => 'none', 'key' => ''];
    }
}
