<?php
/**
 * 跨引擎数据层：PDO 适配 SQLite / MySQL / PostgreSQL
 * 统一占位符(?)、分页与方言差异封装；schema 自动初始化与迁移。
 */
class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
        $db = $cfg['db'];
        self::$driver = $db['driver'];
        switch ($db['driver']) {
            case 'mysql':
                $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
                self::$pdo = new PDO($dsn, $db['user'], $db['pass'], self::opts());
                break;
            case 'pgsql':
                $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
                self::$pdo = new PDO($dsn, $db['user'], $db['pass'], self::opts());
                break;
            default:
                self::$driver = 'sqlite';
                @mkdir(dirname($db['sqlite']), 0775, true);
                self::$pdo = new PDO('sqlite:' . $db['sqlite'], null, null, self::opts());
                self::$pdo->exec('PRAGMA journal_mode=WAL');
                self::$pdo->exec('PRAGMA foreign_keys=ON');
        }
    }

    private static function opts(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    public static function pdo(): PDO { return self::$pdo; }
    public static function driver(): string { return self::$driver; }

    public static function run(string $sql, array $args = []): PDOStatement
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::run($sql, $args)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    public static function val(string $sql, array $args = [])
    {
        return self::run($sql, $args)->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" .
               implode(',', array_fill(0, count($cols), '?')) . ")";
        self::run($sql, array_values($data));
        return (int)self::$pdo->lastInsertId();
    }

    /** 跨引擎 UPSERT：$keys 为唯一键列 */
    public static function upsert(string $table, array $data, array $keys): void
    {
        $cols = array_keys($data);
        $sets = [];
        foreach ($cols as $c) {
            if (!in_array($c, $keys, true)) $sets[] = "$c=EXCLUDED_$c";
        }
        if (self::$driver === 'mysql') {
            $setSql = implode(',', array_map(fn($c) => "$c=VALUES($c)", array_diff($cols, $keys)));
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" .
                   implode(',', array_fill(0, count($cols), '?')) . ")" .
                   ($setSql ? " ON DUPLICATE KEY UPDATE $setSql" : " ON DUPLICATE KEY UPDATE " . $keys[0] . "=" . $keys[0]);
        } else {
            $setSql = $sets
                ? implode(',', array_map(fn($c) => "$c=excluded.$c", array_diff($cols, $keys)))
                : 'NOTHING';
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" .
                   implode(',', array_fill(0, count($cols), '?')) . ")" .
                   ($sets
                       ? " ON CONFLICT (" . implode(',', $keys) . ") DO UPDATE SET $setSql"
                       : " ON CONFLICT (" . implode(',', $keys) . ") DO NOTHING");
        }
        self::run($sql, array_values($data));
    }

    /** 自增主键方言 */
    private static function autoId(): string
    {
        return match (self::$driver) {
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'SERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    private static function t(string $type): string
    {
        // 统一类型映射
        $map = [
            'mysql' => ['text' => 'TEXT', 'str' => 'VARCHAR(191)', 'int' => 'INT', 'bigint' => 'BIGINT', 'ts' => 'BIGINT'],
            'pgsql' => ['text' => 'TEXT', 'str' => 'VARCHAR(191)', 'int' => 'INTEGER', 'bigint' => 'BIGINT', 'ts' => 'BIGINT'],
            'sqlite'=> ['text' => 'TEXT', 'str' => 'TEXT', 'int' => 'INTEGER', 'bigint' => 'INTEGER', 'ts' => 'INTEGER'],
        ];
        // 支持 varchar(N) 形式，其余走统一映射；未知类型原样返回，绝不返回 null
        if (preg_match('/^varchar\((\d+)\)$/i', $type, $m)) {
            return self::$driver === 'sqlite' ? 'TEXT' : 'VARCHAR(' . $m[1] . ')';
        }
        return $map[self::$driver][$type] ?? $type;
    }

    /** 初始化全部表结构（幂等） */
    public static function migrate(): void
    {
        $id = self::autoId();
        $text = self::t('text'); $str = self::t('str');
        $int = self::t('int');   $ts  = self::t('ts');

        $tables = [
            "CREATE TABLE IF NOT EXISTS settings (k $str PRIMARY KEY, v $text)",
            "CREATE TABLE IF NOT EXISTS users (
                id $id, nickname $str NOT NULL, email $str NOT NULL,
                password $str NOT NULL, avatar $text,
                role $str NOT NULL DEFAULT 'member', title $str,
                client_key $str, status $int NOT NULL DEFAULT 1,
                email_verified $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL, last_login $ts)",
            "CREATE TABLE IF NOT EXISTS guests (
                id $id, token $str NOT NULL UNIQUE, nickname $str NOT NULL,
                client_key $str, ip $str, daily_count $int NOT NULL DEFAULT 0,
                daily_date $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS email_codes (
                id $id, email $str NOT NULL, code $str NOT NULL, type $str NOT NULL,
                ip $str, used $int NOT NULL DEFAULT 0,
                expires_at $ts NOT NULL, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS rooms (
                id $id, name $str NOT NULL, slug $str NOT NULL UNIQUE,
                type $str NOT NULL DEFAULT 'public', password $str,
                min_role $str NOT NULL DEFAULT 'guest', owner_id $int,
                description $text, status $int NOT NULL DEFAULT 1, created_at $ts NOT NULL)",
            // 群成员（v1.1.11「不公开群聊」）：房间与用户的归属关系。
            // 设计要点：
            //  - 只有**注册用户**是成员，游客不占行（游客身份随浏览器会话消亡，
            //    同名游客背后可能是任意多个人，无法审计，也不能作为邀请对象）。
            //  - (room_id, user_id) 唯一，重复邀请直接 INSERT OR REPLACE 幂等。
            //  - invited_by 记下「谁邀请的」，与 rooms.owner_id（群主）区分：
            //    群主也可能不是直接邀请者。
            //  - created_at 单列存加入时间；角色（member/admin）暂不入表，
            //    群内权限一律以 rooms.owner_id + users.role 为准，避免两套口径打架。
            "CREATE TABLE IF NOT EXISTS room_members (
                id $id,
                room_id $int NOT NULL, user_id $int NOT NULL,
                invited_by $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS messages (
                id $id, room_id $int NOT NULL, user_id $int, guest_id $int,
                nickname $str NOT NULL, role $str NOT NULL DEFAULT 'guest',
                title $str, avatar $text,
                type $str NOT NULL DEFAULT 'text', content $text,
                to_user_id $int, to_guest_id $int, to_nickname $str,
                recalled $int NOT NULL DEFAULT 0,
                deleted $int NOT NULL DEFAULT 0, deleted_at $int NOT NULL DEFAULT 0,
                deleted_by $str,
                ip $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS bans (
                id $id, type $str NOT NULL, target $str NOT NULL,
                room_id $int NOT NULL DEFAULT 0, reason $text,
                expires_at $ts, created_by $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS sensitive_words (
                id $id, word $str NOT NULL, replacement $str NOT NULL DEFAULT '***',
                enabled $int NOT NULL DEFAULT 1)",
            // announcements（旧系统公告）表已随 v1.0.102 剥离为 announcements 插件，
            // 由插件表 plugin_announcements 接管；存量库中的旧表保留不删（含迁移源数据）
            "CREATE TABLE IF NOT EXISTS security_logs (
                id $id, action $str NOT NULL, actor $str, ip $str,
                data $text, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                identity $str PRIMARY KEY, fails $int NOT NULL DEFAULT 0,
                locked_until $ts, updated_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS rate_limits (
                bucket $str NOT NULL, ident $str NOT NULL,
                window_start $ts NOT NULL, count $int NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, ident))",
            "CREATE TABLE IF NOT EXISTS stickers (
                id $id, owner_key $str NOT NULL, url $text NOT NULL, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS online (
                token $str PRIMARY KEY, user_id $int, guest_id $int,
                nickname $str NOT NULL, role $str NOT NULL DEFAULT 'guest',
                title $str, avatar $text, room_id $int NOT NULL DEFAULT 0,
                ip $str, last_seen $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS plugins (
                name $str PRIMARY KEY, enabled $int NOT NULL DEFAULT 0, config $text)",
            // 群聊审核回收站：before 存操作前快照（delete 为整行 JSON），撤销=按快照还原
            "CREATE TABLE IF NOT EXISTS room_review_trash (
                id $id, room_id $int NOT NULL, room_name $str NOT NULL,
                action $str NOT NULL, before_data $text NOT NULL,
                undone $int NOT NULL DEFAULT 0, created_at $ts NOT NULL)",
            // 插件计划任务（v1.1.13）：由 Plugin::cron() 声明后同步入库，
            // 本表只存**调度状态**，执行逻辑在 Plugin::runCron()。
            // plugin+name 唯一 —— 插件重复加载（清单重建时也会真加载一次）不会插出重复行。
            "CREATE TABLE IF NOT EXISTS cron_tasks (
                id $id, plugin $str NOT NULL DEFAULT '', name $str NOT NULL,
                interval $int NOT NULL DEFAULT 3600,
                last_run_at $int NOT NULL DEFAULT 0, next_run_at $int NOT NULL DEFAULT 0,
                last_status $str NOT NULL DEFAULT '', run_count $int NOT NULL DEFAULT 0,
                enabled $int NOT NULL DEFAULT 1, created_at $ts NOT NULL, updated_at $ts NOT NULL)",
            // 计划任务执行日志：每次执行落一条（含被跳过 / 失败），后台可查最近成败。
            "CREATE TABLE IF NOT EXISTS cron_logs (
                id $id, name $str NOT NULL DEFAULT '', status $str NOT NULL DEFAULT 'ok',
                message $str NOT NULL DEFAULT '', duration $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL)",
            // v1.1.14「仅自己隐藏」的消息黑名单。
            // 语义边界（务必分清，勿与 messages.deleted 混用）：
            //   messages.deleted=1  = 真删除，**所有人**都不再看到（清空正文，留行审计）；
            //   本表一行            = 只是「我不想看这条」，**别人照常能看到**，
            //                           用于普通用户删除他人消息时的降级形态。
            // 因此读消息的每条通道（history / poll / dm_history / dm_poll / conversations）
            // 都必须扣掉本表命中的行，漏一处就会出现「删了却又刷出来」。
            "CREATE TABLE IF NOT EXISTS message_hides (
                id $id, user_id $int NOT NULL, message_id $int NOT NULL,
                created_at $ts NOT NULL)",
        ];
        foreach ($tables as $sql) self::$pdo->exec($sql);

        // 计划任务表的复合唯一索引：SQLite / MySQL / PostgreSQL 都要求先有唯一列才能建，
        // 且 SQLite 的 CREATE UNIQUE INDEX IF NOT EXISTS 三驱动均支持（MySQL 8 见下方兜底）。
        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_cron_tasks_key ON cron_tasks (plugin, name)',
            'CREATE INDEX IF NOT EXISTS idx_cron_tasks_due ON cron_tasks (enabled, next_run_at)',
            'CREATE INDEX IF NOT EXISTS idx_cron_logs_created ON cron_logs (created_at)',
            // 同一用户重复隐藏同一条消息必须幂等，否则反复点会插出一堆重复行
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_msg_hides_key ON message_hides (user_id, message_id)',
        ] as $sql) {
            try { self::$pdo->exec($sql); } catch (Throwable $e) { /* MySQL 8 不支持 IF NOT EXISTS，忽略 */ }
        }

        // ---------- 增量迁移（幂等） ----------
        self::addColumn('messages', 'quote', 'text', "''");   // 引用快照 JSON：{nick,text}（v1.0.69 引用功能）
        self::addColumn('rooms', 'avatar', 'text', "''");     // 群聊头像（v1.0.76，uploads/avatar/ 下的相对路径）
        self::addColumn('users', 'points', 'int', '0');   // 用户积分
        self::addColumn('users', 'birthdate', 'varchar(10)', "''");   // 出生日期（年龄限制注册用）
        // v1.1.0 软删除：deleted=1 表示「已删除」。行保留（昵称/时间/IP 可审计），
        // content 同步清空（原内容不可恢复），到期由 Chat::purgeDeleted() 物理删除。
        // 与 recalled（撤回）区分：撤回是用户自己的动作且不涉及合规留痕。
        self::addColumn('messages', 'deleted', 'int', '0');
        self::addColumn('messages', 'deleted_at', 'int', '0');
        self::addColumn('messages', 'deleted_by', 'varchar(64)', "''");
        // 已废弃字段：rooms.min_age（进入该房间的最低年龄）随 1.0.31 下线，应用层已不再读写。
        // 保留此行仅为兼容历史数据库（列仍存在且幂等），勿在业务代码中重新启用。
        self::addColumn('rooms', 'min_age', 'int', '0');
        // v1.1.11「公开 / 不公开」开关：与 type（public/password/role）**正交**。
        // type 管的是「进入方式」（要不要密码 / 要什么角色），is_public 管的是
        //「谁能发现这个群」——公开群进公开列表、游客可进可发言；
        // 不公开群不进公开列表，只有群主与 room_members 里的成员能进。
        // 默认 1（公开）：存量群全部保持原有可见性，不做隐式收紧。
        self::addColumn('rooms', 'is_public', 'int', '1');
        // 不公开群的邀请码：不公开群不出现在列表里，只能靠邀请链接进入。
        // 为空表示从未生成过（前端显示「生成邀请链接」按钮）；
        // 群主可在群聊设置里重置（重置后旧链接立即失效）。
        self::addColumn('rooms', 'invite_code', 'varchar(16)', "''");

        // v1.0.33 起取消「用户名」：账号不再有独立登录名，显示名统一为 nickname（昵称）。
        // 迁移策略（一次性、幂等）：先把昵称回填为原用户名（原 nickname 里用户自定义的值按需求丢弃），
        // 再物理删除 username 列——必须真删，否则新插入语句会因该列 NOT NULL UNIQUE 且无默认值而失败。
        if (self::hasColumn('users', 'username')) {
            self::$pdo->exec('UPDATE users SET nickname=username');
            self::dropColumn('users', 'username');
        }

        // ---------- v1.1.0：私聊搬进虚拟空间（room_id=0） ----------
        // 1.0.x 的私信是「在某群里发给某人」，room_id 指向那个群；v1.1.0 起私聊是独立会话，
        // 统一落在 room_id=0。若不迁移，存量私信既不会出现在私聊会话列表里，
        // 又会继续混在群聊历史中（对双方可见、对其他人不可见），语义割裂。
        // 幂等：仅处理 room_id>0 的存量行，迁移后 WHERE 不再命中。
        self::$pdo->exec("UPDATE messages SET room_id=0 WHERE type='private' AND room_id>0");

        // 索引（跨引擎兼容语法）
        $idx = [
            'CREATE INDEX IF NOT EXISTS idx_msg_room ON messages (room_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_msg_private ON messages (to_user_id, to_guest_id)',
            // v1.1.0 私聊：历史/增量轮询按「room_id=0 + 发送方 + id」过滤，补一条发送方索引
            'CREATE INDEX IF NOT EXISTS idx_msg_dm_sender ON messages (user_id, guest_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_online_seen ON online (last_seen)',
            'CREATE INDEX IF NOT EXISTS idx_email ON email_codes (email, type, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_logs ON security_logs (created_at)',
        ];
        if (self::$driver === 'mysql') {
            // MySQL 8 不支持 IF NOT EXISTS 的 CREATE INDEX，逐个捕获
            foreach ($idx as $sql) {
                try { self::$pdo->exec(str_replace(' IF NOT EXISTS', '', $sql)); }
                catch (PDOException $e) { /* 已存在则忽略 */ }
            }
        } else {
            foreach ($idx as $sql) self::$pdo->exec($sql);
        }
    }

    /** 列是否已存在（三引擎方言） */
    private static function hasColumn(string $table, string $col): bool
    {
        try {
            if (self::$driver === 'sqlite') {
                foreach (self::all("PRAGMA table_info($table)") as $r) {
                    if (($r['name'] ?? '') === $col) return true;
                }
                return false;
            }
            if (self::$driver === 'pgsql') {
                return (bool)self::val(
                    'SELECT COUNT(*) FROM information_schema.columns WHERE table_name=? AND column_name=?',
                    [$table, $col]
                );
            }
            return (bool)self::val(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
                [$table, $col]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 幂等加列的**公开入口**，供插件建表 / 升级时使用（v1.1.12）。
     *
     * 为什么不直接把 addColumn 改成 public：核心内部（含 migrate()）一律走
     * private addColumn，这里另开一个语义明确的薄封装，插件侧读代码时
     * 一眼就知道「这是给插件用的公开 API」，而不是误以为可以随便传表名。
     *
     * ⚠️ 插件踩过的坑（v1.1.12 亲历）：直接 DB::addColumn() 会抛
     * 「Call to private method DB::addColumn() from global scope」，
     * 而 Plugin::loadPlugin() 用 try/catch 吞掉插件异常只记 plugin_error，
     * **表现为插件「静默半个身位」—— 后续所有 Plugin::on 都没注册，
     * 但缓存清单仍写着旧的 hooks 列表**，排查时极易误判成「钩子没触发」。
     * 插件顶层做 schema 迁移前，务必确认调用的方法是 public。
     *
     * @param string $type    类型，如 'int' / 'varchar(16)'
     * @param string $default 带引号的默认值字面量，如 "'1'" / "''"（不是裸值）
     */
    public static function ensureColumn(string $table, string $col, string $type, string $default): void
    {
        self::addColumn($table, $col, $type, $default);
    }

    /** 幂等加列：已存在则跳过 */
    private static function addColumn(string $table, string $col, string $type, string $default): void
    {
        if (self::hasColumn($table, $col)) return;
        $t = self::t($type);
        self::$pdo->exec("ALTER TABLE $table ADD COLUMN $col $t NOT NULL DEFAULT $default");
    }

    /**
     * 幂等删列：已不存在则跳过。
     *
     * SQLite 拒绝删除带 UNIQUE 约束的列（"cannot drop UNIQUE column"），
     * MySQL / PostgreSQL 无此限制。因此 SQLite 失败时退化为「建新表 → 拷数据 → 换名」重建，
     * 并在重建后恢复原表的显式索引（隐式 unique 索引随列一起消失，无需处理）。
     */
    private static function dropColumn(string $table, string $col): void
    {
        if (!self::hasColumn($table, $col)) return;
        try {
            self::$pdo->exec("ALTER TABLE $table DROP COLUMN $col");
            return;
        } catch (Throwable $e) {
            if (self::$driver !== 'sqlite') throw $e;
            self::rebuildTableWithout($table, $col);
        }
    }

    /** SQLite 专用：重建表以移除列（保留其余列定义、主键自增与显式索引） */
    private static function rebuildTableWithout(string $table, string $col): void
    {
        $pdo = self::$pdo;
        // 先取结构与索引定义：DROP TABLE 之后 sqlite_master 里就没了
        $keep = [];
        foreach (self::all("PRAGMA table_info($table)") as $c) {
            if (($c['name'] ?? '') !== $col) $keep[] = $c;
        }
        $idx = self::all(
            "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL",
            [$table]
        );

        $defs = [];
        foreach ($keep as $c) {
            $t = $c['type'] ?: 'TEXT';
            $d = '"' . $c['name'] . '" ' . $t;
            if ((int)$c['pk'] === 1) {
                // INTEGER 主键需显式声明 AUTOINCREMENT，否则重建后 rowid 复用旧值语义变化
                $d .= ' PRIMARY KEY' . (strtolower($t) === 'integer' ? ' AUTOINCREMENT' : '');
            } elseif ((int)$c['notnull'] === 1) {
                $d .= ' NOT NULL';
            }
            if ($c['dflt_value'] !== null) $d .= ' DEFAULT ' . $c['dflt_value'];
            $defs[] = $d;
        }
        $names = implode(',', array_map(fn($c) => '"' . $c['name'] . '"', $keep));
        $tmp = $table . '__new';

        $oldFk = (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE IF EXISTS "' . $tmp . '"');
            $pdo->exec('CREATE TABLE "' . $tmp . '" (' . implode(',', $defs) . ')');
            $pdo->exec('INSERT INTO "' . $tmp . '" (' . $names . ') SELECT ' . $names . ' FROM "' . $table . '"');
            $pdo->exec('DROP TABLE "' . $table . '"');
            $pdo->exec('ALTER TABLE "' . $tmp . '" RENAME TO "' . $table . '"');
            foreach ($idx as $ix) $pdo->exec($ix['sql']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys=' . ($oldFk ? 'ON' : 'OFF'));
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys=' . ($oldFk ? 'ON' : 'OFF'));
    }

    // ---------- 站点设置 ----------
    public static function setting(string $k, $default = null)
    {
        $v = self::val('SELECT v FROM settings WHERE k=?', [$k]);
        return $v === false || $v === null ? $default : $v;
    }

    public static function setSetting(string $k, $v): void
    {
        self::upsert('settings', ['k' => $k, 'v' => (string)$v], ['k']);
    }

    public static function defaults(): void
    {
        $defs = [
            'site_name'        => 'Owlsgo-Chat',
            'allow_register'   => '1',
            'reg_email_verify' => '1',
            'guest_browse'     => '1',
            'guest_chat'       => '1',
            // v1.1.0 起游客发言限制改为「两条消息之间的最小间隔（秒）」，
            // 取代原先的「每日发言条数上限」——限额在天级粒度过粗，
            // 30 秒间隔既能防洪，又不会让正常聊天被卡死。0 = 不限制。
            'guest_msg_interval' => '30',
            'msg_rate_limit'   => '5',   // 每条消息最小间隔(秒)内的最大条数窗口
            'msg_rate_window'  => '10',  // 频率窗口(秒)
            'msg_rate_max'     => '8',   // 窗口内最大消息数
            // v1.1.0 软删除保留期（天）：超期后由 Chat::purgeDeleted() 物理清除。
            // 0 = 永久保留（不物理删除，行一直留着）
            'msg_deleted_retain_days' => '30',
            'mail_rate_limit'  => '60',  // 邮件发送最小间隔(秒)
            'sound_default'    => '1',
            'room_pass_ttl'    => '1800', // 密码房通行缓存(秒)，0=每次进入都要输入密码
            // 登录保护
            'login_fail_captcha' => '3',  // 连续失败达此次数后要求图形验证码（0=不启用）
            'login_fail_lock'    => '10', // 连续失败达此次数后临时锁定（0=不锁定）
            'login_lock_minutes' => '15', // 锁定时长（分钟）
            'min_register_age' => '0',   // 注册最低年龄（周岁），0=不限制
            // 文件附件上传
            'file_upload'   => '1',      // 是否允许上传文件（0=关闭）
            'file_exts'     => 'zip,rar,7z,pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4',
            'file_max_size' => '10',     // 单个文件上限（MB）
            // 用户创建群聊
            'room_create_allow' => '1',  // 是否允许普通用户创建群聊（管理员始终可创建）
            'room_create_cost'  => '0',  // 创建群聊扣除的积分（0=免费；管理员不扣）
            // v1.1.14：是否允许普通用户创建**不公开**群聊（管理员始终可创建）。
            // 与 room_create_allow 正交：那个管「能不能建群」，这个管「建出来的群能不能藏起来」。
            // 不公开群只靠邀请链接传播，容易变成灰色宣传阵地，故单独留一道总闸。
            'room_private_create_allow' => '1',
        ];
        foreach ($defs as $k => $v) {
            if (self::setting($k) === null) self::setSetting($k, $v);
        }
        if (!self::val('SELECT COUNT(*) FROM rooms')) {
            // 默认房间固定占用 001（v1.0.51 起新房间 ID 为随机 3 位起步），
            // 群主固定指向超级管理员（管理员固定占用用户 001，安装流程在其后创建）
            DB::insert('rooms', [
                'id' => 1,
                'name' => '综合闲聊', 'slug' => 'public', 'type' => 'public',
                'min_role' => 'guest', 'description' => '默认公共群聊',
                'owner_id' => 1,
                'status' => 1, 'created_at' => time(),
            ]);
        }
        // 存量库修正（v1.0.80）：老版本安装的默认群聊没有群主，补指向超级管理员
        DB::run('UPDATE rooms SET owner_id=1 WHERE id=1 AND (owner_id IS NULL OR owner_id=0) AND EXISTS(SELECT 1 FROM users WHERE id=1)');
    }
}
