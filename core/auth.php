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
            'created_at' => time(),
        ]);
        setcookie('owl_guest', $token, [
            'expires' => time() + 86400 * 365, 'path' => '/',
            'httponly' => true, 'secure' => Sec::isHttps(), 'samesite' => 'Lax',
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
     * 通用随机位段 ID 分配（v1.0.37 用户 ID 引入，v1.0.51 泛化供群聊复用）：
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
        if ($nickOk) Chat::filterText($nick, 'nickname');   // 敏感词过滤（sensitive-words 插件经 text.filter 钩子处理）
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
     *
     * v1.1.12 起每次失败都会 fire('login.failed', ...)，插件可据此做失败提醒、
     * 撞库统计、异地异常登录告警等。**与 login.after_verify 严格成对**：
     * 一个只在成功时触发、一个只在失败时触发，插件不必再自己判断方向。
     */
    public static function login(string $identity, string $password): array
    {
        $key = strtolower($identity) . '|' . Sec::ip();
        // 闭包统一收口：把「失败原因 / 剩余次数 / 命中的用户」补全后交给插件，
        // 保证各失败分支的钩子载荷形状一致，插件侧无需按 reason 做字段兼容。
        $fail = function (string $reason, string $msg, array $user = null, int $left = -1) use ($identity): array {
            if (class_exists('Plugin')) {
                Plugin::fire('login.failed', [[
                    'identity' => $identity,        // 用户提交的登录标识（原样，邮箱或用户 ID）
                    'reason'   => $reason,          // fail / locked / disabled
                    'msg'      => $msg,             // 用户看到的文案
                    'left'     => $left,            // 剩余尝试次数，-1 表示不适用
                    'user'     => $user,            // 命中的 users 行（密码错时为 null，防枚举）
                    'ip'       => Sec::ip(),
                ]]);
            }
            return [false, $msg, $reason];
        };

        if (Sec::loginLocked($key)) return $fail('locked', '失败次数过多，账号已临时锁定 15 分钟');
        $user = null;
        if (preg_match('/^\d{1,19}$/', $identity)) {
            $user = DB::one('SELECT * FROM users WHERE id=?', [(int)$identity]);
        }
        if (!$user) $user = DB::one('SELECT * FROM users WHERE email=?', [$identity]);
        if (!$user || !password_verify($password, $user['password'])) {
            Sec::loginFail($key);
            Sec::log('login_fail', $identity);
            $left = 10 - Sec::loginFails($key);
            // ⚠️ 密码错时 $user 传 null：确认「账号存在」本身就是信息，
            // 交给插件就能变成账号枚举的侧信道，宁可少给一个字段。
            return $fail('fail', '邮箱或用户 ID 不正确，或密码错误' . ($left <= 3 ? "，剩余 $left 次尝试机会" : ''), null, $left);
        }
        if ((int)$user['status'] !== 1) return $fail('disabled', '账号已被禁用', $user);
        Sec::loginOk($key);
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        DB::run('UPDATE users SET last_login=? WHERE id=?', [time(), $user['id']]);
        Sec::log('login', $user['nickname']);
        // 登录验证完成钩子：供插件扩展两步验证、登录通知、异地提醒等。
        // $method 为本次通过验证的方式（核心仅有 password；插件实现两步验证时
        // 可自行触发本钩子并传入 totp / recovery 等）。仅成功登录触发，失败不触发。
        Plugin::fire('login.after_verify', [$user, 'password', ['ip' => Sec::ip()]]);
        return [true, '登录成功', $user];
    }

    /**
     * 登出。v1.1.12 起 fire('logout.before_destroy')：
     * **必须在 session_destroy() 之前触发**，否则 $_SESSION 已清空，
     * 插件拿不到 uid / 昵称 / 本次登录方式，只能记一条匿名日志。
     * 载荷为登录快照：$reason=manual（用户主动登出）| fingerprint（守卫踢出，见 session.destroyed）。
     */
    public static function logout(): void
    {
        $uid = (int)($_SESSION['uid'] ?? 0);
        $nick = (string)($_SESSION['uname'] ?? '');
        if (!$nick && $uid > 0) {
            // 项目已无 username（v1.0.33 起），此处只取昵称，**不得**反查邮箱
            $nick = (string)(DB::val('SELECT nickname FROM users WHERE id=?', [$uid]) ?: '');
        }
        if (class_exists('Plugin')) {
            Plugin::fire('logout.before_destroy', [[
                'uid'      => $uid,
                'nickname' => $nick,
                'had_uid'  => isset($_SESSION['uid']),
                'ip'       => Sec::ip(),
                'reason'   => 'manual',
            ]]);
        }
        Sec::log('logout', $nick);
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
        Chat::filterText($nick, 'nickname');   // 敏感词过滤（sensitive-words 插件经 text.filter 钩子处理）
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
