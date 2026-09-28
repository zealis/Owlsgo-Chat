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
     * 解析并校验「进入最低年龄」输入：接受 0-100 的整数，0 表示不限，留空等同于 0。
     *
     * 背景（1.0.30）：该字段此前是下拉选项（仅 0/12/14/16/18/21），后端用 (int) 强转
     * 加 clamp 兜底不会出问题；改为自由数字输入框后，非法输入会被静默改写——
     * "abc" 变 0、150 变 100，前端提示「已保存」但实际值与用户预期不符。
     * 因此改为显式校验：不合法时返回明确错误文案，由调用方返回给用户。
     *
     * @param string|null $raw 原始输入（来自 POST）
     * @return array [bool 是否合法, int 归一化后的值, string 错误文案]
     */
    public static function parseMinAge(?string $raw): array
    {
        $s = trim((string)$raw);
        // 留空视为不限，避免前端禁用 JS 或未填写时被误判为错误
        if ($s === '') return [true, 0, ''];
        // 仅接受纯数字（限长防止超大整数溢出）：借此排除负数（-5）、小数（18.5）
        // 与字母串（abc、12abc）；前导零（0018）视为 18 予以通过
        if (!preg_match('/^\d{1,9}$/', $s)) return [false, 0, '进入最低年龄请填 0-100 的整数（0 表示不限）'];
        $v = (int)$s;
        if ($v > 100) return [false, 0, '进入最低年龄需在 0-100 之间（0 表示不限）'];
        return [true, $v, ''];
    }

    public static function register(string $username, string $email, string $password, string $code, string $birthdate = ''): array
    {
        if (DB::setting('allow_register', '1') !== '1') return [false, '站点已关闭注册'];
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) return [false, '用户名需 3-20 位字母、数字或下划线'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, '邮箱格式不正确'];
        if (strlen($password) < 6) return [false, '密码至少 6 位'];
        if (DB::one('SELECT id FROM users WHERE username=?', [$username])) return [false, '用户名已被占用'];
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
        $id = DB::insert('users', [
            'username' => $username, 'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $username, 'avatar' => '', 'role' => 'member',
            'client_key' => Sec::clientKey(), 'status' => 1,
            'email_verified' => DB::setting('reg_email_verify', '1') === '1' ? 1 : 0,
            'birthdate' => $birthdate,
            'created_at' => time(),
        ]);
        Sec::log('register', $username, ['email' => $email]);
        return [true, '注册成功', $id];
    }

    public static function login(string $identity, string $password): array
    {
        $key = strtolower($identity) . '|' . Sec::ip();
        if (Sec::loginLocked($key)) return [false, '失败次数过多，账号已临时锁定 15 分钟', 'locked'];
        $user = DB::one('SELECT * FROM users WHERE username=? OR email=?', [$identity, $identity]);
        if (!$user || !password_verify($password, $user['password'])) {
            Sec::loginFail($key);
            Sec::log('login_fail', $identity);
            $left = 10 - Sec::loginFails($key);
            return [false, '用户名或密码错误' . ($left <= 3 ? "，剩余 $left 次尝试机会" : ''), 'fail'];
        }
        if ((int)$user['status'] !== 1) return [false, '账号已被禁用'];
        Sec::loginOk($key);
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        DB::run('UPDATE users SET last_login=? WHERE id=?', [time(), $user['id']]);
        Sec::log('login', $user['username']);
        return [true, '登录成功', $user];
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
        Sec::log('reset_password', $user['username']);
        return [true, '密码已重置，请重新登录'];
    }

    /** 资料更新：昵称 / 头像 */
    public static function updateProfile(array $user, string $nickname, string $avatar): array
    {
        $nickname = trim($nickname);
        if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 24) return [false, '昵称需 1-24 个字符'];
        DB::run('UPDATE users SET nickname=?, avatar=? WHERE id=?', [$nickname, $avatar, $user['id']]);
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
                'nickname' => $user['nickname'], 'username' => $user['username'],
                'role' => $user['role'], 'title' => $user['title'] ?? '',
                'avatar' => $user['avatar'] ?? '', 'key' => $user['client_key'],
                'birthdate' => (string)($user['birthdate'] ?? ''),
            ];
        }
        if ($guest) {
            return [
                'kind' => 'guest', 'id' => (int)$guest['id'],
                'nickname' => $guest['nickname'], 'username' => '',
                'role' => 'guest', 'title' => '', 'avatar' => '',
                'key' => $guest['client_key'],
            ];
        }
        return ['kind' => 'none', 'key' => ''];
    }
}
