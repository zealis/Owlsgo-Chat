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
        return $map[self::$driver][$type];
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
                id $id, username $str NOT NULL UNIQUE, email $str NOT NULL,
                password $str NOT NULL, nickname $str NOT NULL, avatar $text,
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
            "CREATE TABLE IF NOT EXISTS messages (
                id $id, room_id $int NOT NULL, user_id $int, guest_id $int,
                nickname $str NOT NULL, role $str NOT NULL DEFAULT 'guest',
                title $str, avatar $text,
                type $str NOT NULL DEFAULT 'text', content $text,
                to_user_id $int, to_guest_id $int, to_nickname $str,
                recalled $int NOT NULL DEFAULT 0, ip $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS bans (
                id $id, type $str NOT NULL, target $str NOT NULL,
                room_id $int NOT NULL DEFAULT 0, reason $text,
                expires_at $ts, created_by $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS sensitive_words (
                id $id, word $str NOT NULL, replacement $str NOT NULL DEFAULT '***',
                enabled $int NOT NULL DEFAULT 1)",
            "CREATE TABLE IF NOT EXISTS announcements (
                id $id, room_id $int NOT NULL DEFAULT 0, content $text NOT NULL,
                type $str NOT NULL DEFAULT 'announce', priority $int NOT NULL DEFAULT 0,
                enabled $int NOT NULL DEFAULT 1, created_at $ts NOT NULL)",
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
        ];
        foreach ($tables as $sql) self::$pdo->exec($sql);

        // 索引（跨引擎兼容语法）
        $idx = [
            'CREATE INDEX IF NOT EXISTS idx_msg_room ON messages (room_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_msg_private ON messages (to_user_id, to_guest_id)',
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
            'guest_daily_limit'=> '50',
            'msg_rate_limit'   => '5',   // 每条消息最小间隔(秒)内的最大条数窗口
            'msg_rate_window'  => '10',  // 频率窗口(秒)
            'msg_rate_max'     => '8',   // 窗口内最大消息数
            'mail_rate_limit'  => '60',  // 邮件发送最小间隔(秒)
            'sound_default'    => '1',
        ];
        foreach ($defs as $k => $v) {
            if (self::setting($k) === null) self::setSetting($k, $v);
        }
        if (!self::val('SELECT COUNT(*) FROM rooms')) {
            DB::insert('rooms', [
                'name' => '综合闲聊', 'slug' => 'public', 'type' => 'public',
                'min_role' => 'guest', 'description' => '默认公共聊天室',
                'status' => 1, 'created_at' => time(),
            ]);
        }
    }
}
