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
     * @return array [bool 是否合法, string 归一化值或错误文案]
     */
    public static function checkNickname(string $nick): array
    {
        $s = trim($nick);
        if ($s === '') return [false, '请填写昵称'];
        if (!preg_match('/^[\p{L}\p{N}_\-]{2,20}$/u', $s)) {
            return [false, '昵称需 2-20 个字符，支持中英文、数字、下划线与短横线，不含空格或 @'];
        }
        return [true, $s];
    }

    /**
     * 分配新用户 ID：随机 3 位数字（001-999），该段占满后自动升为随机 4 位
     * （1000-9999），依此类推。管理员固定 001（安装向导显式指定 id=1）。
     *
     * 设计要点：
     * - 应用层分配、INSERT 时显式指定 id。SQLite / MySQL / PostgreSQL 的自增
     *   主键都接受显式值，无需改表结构；已有用户的 id 一律保持不变。
     * - 先随机试探（段内空位多时碰撞率极低），试探失败再收集段内空位精确
     *   随机取一个，避免段快满时随机反复撞车。
     * - 并发注册同时分到同一 id 撞主键时，由 register() 捕获并重试。
     *
     * @return int 可用的用户 ID
     */
    public static function nextUserId(): int
    {
        $max    = (int)(DB::val('SELECT MAX(id) FROM users') ?: 0);
        $digits = max(3, strlen((string)$max));          // 至少 3 位（001-999）
        while (true) {
            $lo = $digits === 3 ? 1 : 10 ** ($digits - 1);   // 3 位段 1-999；4 位段 1000 起
            $hi = 10 ** $digits - 1;
            $occupied = (int)DB::val('SELECT COUNT(*) FROM users WHERE id BETWEEN ? AND ?', [$lo, $hi]);
            if ($occupied < $hi - $lo + 1) break;        // 当前位段未满，可用
            $digits++;                                   // 段满自动升一位
        }
        // 随机试探 32 次；失败（段接近占满）时收集全部空位精确随机
        for ($i = 0; $i < 32; $i++) {
            $id = random_int($lo, $hi);
            if (!DB::one('SELECT id FROM users WHERE id=?', [$id])) return $id;
        }
        $used = array_map('intval', array_column(
            DB::all('SELECT id FROM users WHERE id BETWEEN ? AND ?', [$lo, $hi]), 'id'
        ));
        $free = array_values(array_diff(range($lo, $hi), $used));
        if (!$free) return self::nextUserId();           // 理论不可达（上方已判满），防御性递归
        return (int)$free[array_rand($free)];
    }

    public static function register(string $nickname, string $email, string $password, string $code, string $birthdate = ''): array
    {
        if (DB::setting('allow_register', '1') !== '1') return [false, '站点已关闭注册'];
        [$nickOk, $nick] = self::checkNickname($nickname);
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
        Sec::loginOk($key);
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        DB::run('UPDATE users SET last_login=? WHERE id=?', [time(), $user['id']]);
        Sec::log('login', $user['nickname']);
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
        [$ok, $nick] = self::checkNickname($nickname);
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
