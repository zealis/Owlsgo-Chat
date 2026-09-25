<?php
/**
 * 聊天核心：房间、消息、长轮询、撤回、历史分页、敏感词、禁言、公告、在线列表
 */
class Chat
{
    // ---------- 房间 ----------
    public static function rooms(array $actor): array
    {
        $list = DB::all('SELECT * FROM rooms WHERE status=1 ORDER BY id');
        $out = [];
        foreach ($list as $r) {
            if (!self::canEnter($r, $actor, true)) continue;
            $out[] = [
                'id' => (int)$r['id'], 'name' => $r['name'], 'slug' => $r['slug'],
                'type' => $r['type'], 'need_password' => $r['type'] === 'password',
                'description' => $r['description'] ?? '',
            ];
        }
        return $out;
    }

    public static function room(int $id): ?array
    {
        return DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$id]);
    }

    /** 进入权限检查（$silent 仅判断可见性） */
    public static function canEnter(array $room, array $actor, bool $silent = false): bool
    {
        if ($room['type'] === 'role') {
            $need = Auth::roleLevel($room['min_role']);
            return Auth::roleLevel($actor['role'] ?? 'guest') >= $need;
        }
        if ($actor['kind'] === 'none' && DB::setting('guest_browse', '1') !== '1') return false;
        return true;
    }

    public static function checkRoomPassword(array $room, string $password): bool
    {
        return $room['type'] !== 'password' || hash_equals((string)$room['password'], $password);
    }

    // ---------- 禁言检查 ----------
    public static function isBanned(array $actor, int $roomId): ?string
    {
        $now = time();
        $checks = [];
        if ($actor['kind'] === 'user') $checks[] = ['user', (string)$actor['id']];
        if ($actor['kind'] === 'guest') $checks[] = ['guest', $actor['nickname']];
        $checks[] = ['ip', Sec::ip()];
        foreach ($checks as [$type, $target]) {
            $b = DB::one(
                'SELECT * FROM bans WHERE type=? AND target=? AND (room_id=0 OR room_id=?) AND (expires_at IS NULL OR expires_at=0 OR expires_at>?) ORDER BY id DESC LIMIT 1',
                [$type, $target, $roomId, $now]
            );
            if ($b) {
                $exp = $b['expires_at'] ? '，解封时间 ' . date('Y-m-d H:i', (int)$b['expires_at']) : '，永久';
                return '您已被禁言' . $exp . ($b['reason'] ? '，原因：' . $b['reason'] : '');
            }
        }
        return null;
    }

    // ---------- 敏感词 ----------
    public static function filterWords(string $content): string
    {
        static $words = null;
        if ($words === null) $words = DB::all('SELECT word, replacement FROM sensitive_words WHERE enabled=1');
        foreach ($words as $w) {
            if ($w['word'] !== '') {
                $content = mb_ereg_replace(preg_quote($w['word'], '/'), $w['replacement'], $content);
            }
        }
        return $content;
    }

    // ---------- 发送消息 ----------
    public static function send(array $actor, int $roomId, string $type, string $content, array $opt = []): array
    {
        $room = self::room($roomId);
        if (!$room) return [false, '聊天室不存在'];
        if (!self::canEnter($room, $actor)) return [false, '无权进入该聊天室'];

        // 游客发言权限
        if ($actor['kind'] === 'guest') {
            if (DB::setting('guest_chat', '1') !== '1') return [false, '站点已禁止游客发言'];
            // 游客每日限额
            $g = Auth::guest();
            $today = date('Y-m-d');
            if ($g['daily_date'] !== $today) {
                DB::run('UPDATE guests SET daily_count=0, daily_date=? WHERE id=?', [$today, $g['id']]);
                $g['daily_count'] = 0;
            }
            $limit = (int)DB::setting('guest_daily_limit', 50);
            if ($limit > 0 && (int)$g['daily_count'] >= $limit) return [false, "游客每日最多发言 $limit 条，请注册账号"];
        }
        if ($actor['kind'] === 'none') return [false, '请先登录或刷新页面'];

        // 禁言
        if ($ban = self::isBanned($actor, $roomId)) return [false, $ban];

        // 发言频率限制（数据库计数，替代 Redis）
        $win = (int)DB::setting('msg_rate_window', 10);
        $max = (int)DB::setting('msg_rate_max', 8);
        if (!Sec::rateLimit('msg', $actor['kind'] . $actor['id'], $win, $max)) {
            return [false, '发言过于频繁，请稍后再试'];
        }

        // 消息类型与内容
        $toUserId = null; $toGuestId = null; $toNickname = null;
        if ($type === 'private') {
            $toUserId = isset($opt['to_user_id']) ? (int)$opt['to_user_id'] : null;
            $toGuestId = isset($opt['to_guest_id']) ? (int)$opt['to_guest_id'] : null;
            $toNickname = trim((string)($opt['to_nickname'] ?? ''));
            if (!$toUserId && !$toGuestId) return [false, '私信缺少接收对象'];
        } elseif ($type === 'text' && preg_match('/(^|\s)@[^\s@]+/u', $content)) {
            $type = 'mention';
        } elseif (!in_array($type, ['text', 'image', 'system'], true)) {
            $type = 'text';
        }
        if ($type !== 'image') {
            $content = trim($content);
            if ($content === '') return [false, '消息不能为空'];
            if (mb_strlen($content) > 2000) return [false, '消息过长（最多 2000 字）'];
            $content = self::filterWords($content);
        }

        Plugin::fire('message.before_send', [&$content, $actor, $roomId]);

        $id = DB::insert('messages', [
            'room_id' => $roomId,
            'user_id' => $actor['kind'] === 'user' ? $actor['id'] : null,
            'guest_id' => $actor['kind'] === 'guest' ? $actor['id'] : null,
            'nickname' => $actor['nickname'], 'role' => $actor['role'],
            'title' => $actor['title'] ?? '', 'avatar' => $actor['avatar'] ?? '',
            'type' => $type, 'content' => $content,
            'to_user_id' => $toUserId, 'to_guest_id' => $toGuestId, 'to_nickname' => $toNickname,
            'recalled' => 0, 'ip' => Sec::ip(), 'created_at' => time(),
        ]);
        if ($actor['kind'] === 'guest') {
            DB::run('UPDATE guests SET daily_count=daily_count+1 WHERE id=?', [$actor['id']]);
        }
        Plugin::fire('message.after_send', [$id, $actor, $roomId]);
        return [true, 'ok', $id];
    }

    // ---------- 消息序列化（含可见性过滤） ----------
    private static function visible(array $m, array $actor): bool
    {
        if ($m['type'] !== 'private') return true;
        if ($actor['role'] === 'admin') return true;
        if ($actor['kind'] === 'user' && ((int)$m['user_id'] === $actor['id'] || (int)$m['to_user_id'] === $actor['id'])) return true;
        if ($actor['kind'] === 'guest' && ((int)$m['guest_id'] === $actor['id'] || (int)$m['to_guest_id'] === $actor['id'])) return true;
        return false;
    }

    public static function pack(array $m, array $actor): array
    {
        $admin = $actor['role'] === 'admin';
        return [
            'id' => (int)$m['id'], 'room' => (int)$m['room_id'],
            'uid' => $m['user_id'] ? (int)$m['user_id'] : null,
            'gid' => $m['guest_id'] ? (int)$m['guest_id'] : null,
            'nickname' => $m['nickname'], 'role' => $m['role'],
            'title' => $m['title'] ?? '', 'avatar' => $m['avatar'] ?? '',
            'type' => $m['type'],
            'content' => $m['recalled'] ? '' : $m['content'],
            'recalled' => (int)$m['recalled'],
            'to_uid' => $m['to_user_id'] ? (int)$m['to_user_id'] : null,
            'to_gid' => $m['to_guest_id'] ? (int)$m['to_guest_id'] : null,
            'to_nickname' => $m['to_nickname'] ?? '',
            'time' => date('H:i', (int)$m['created_at']),
            'date' => date('m-d', (int)$m['created_at']),
            'ts' => (int)$m['created_at'],
            'ip' => $admin ? $m['ip'] : null,
            'mine' => ($actor['kind'] === 'user' && (int)$m['user_id'] === $actor['id'])
                  || ($actor['kind'] === 'guest' && (int)$m['guest_id'] === $actor['id']),
        ];
    }

    // ---------- 历史消息（向上翻页） ----------
    public static function history(array $actor, int $roomId, int $beforeId, int $limit = 30): array
    {
        $sql = 'SELECT * FROM messages WHERE room_id=?';
        $args = [$roomId];
        if ($beforeId > 0) { $sql .= ' AND id<?'; $args[] = $beforeId; }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit));
        $rows = DB::all($sql, $args);
        $out = [];
        foreach (array_reverse($rows) as $m) {
            if (self::visible($m, $actor)) $out[] = self::pack($m, $actor);
        }
        return $out;
    }

    // ---------- 长轮询（主通道，零依赖替代 WebSocket） ----------
    public static function poll(array $actor, int $roomId, int $sinceId, int $timeout = 20): array
    {
        self::heartbeat($actor, $roomId);
        $deadline = time() + max(5, min(30, $timeout));
        $new = [];
        while (time() < $deadline) {
            $rows = DB::all('SELECT * FROM messages WHERE room_id=? AND id>? ORDER BY id LIMIT 200', [$roomId, $sinceId]);
            if ($rows) {
                foreach ($rows as $m) {
                    if (self::visible($m, $actor)) $new[] = self::pack($m, $actor);
                    $sinceId = max($sinceId, (int)$m['id']);
                }
                break;
            }
            Plugin::cronTick();
            usleep(500000); // 0.5s
            if (connection_aborted()) exit;
        }
        return [
            'since' => $sinceId,
            'messages' => $new,
            'online' => self::onlineList($roomId),
            'announcements' => self::announcements($roomId),
            'server_time' => time(),
        ];
    }

    // ---------- 在线状态 ----------
    public static function heartbeat(array $actor, int $roomId): void
    {
        if ($actor['kind'] === 'none') return;
        $token = $actor['kind'] === 'user' ? 'u' . $actor['id'] : 'g' . $actor['id'];
        DB::upsert('online', [
            'token' => $token,
            'user_id' => $actor['kind'] === 'user' ? $actor['id'] : null,
            'guest_id' => $actor['kind'] === 'guest' ? $actor['id'] : null,
            'nickname' => $actor['nickname'], 'role' => $actor['role'],
            'title' => $actor['title'] ?? '', 'avatar' => $actor['avatar'] ?? '',
            'room_id' => $roomId, 'ip' => Sec::ip(), 'last_seen' => time(),
        ], ['token']);
        DB::run('DELETE FROM online WHERE last_seen<?', [time() - 60]);
    }

    public static function onlineList(int $roomId): array
    {
        $rows = DB::all('SELECT * FROM online WHERE room_id=? AND last_seen>? ORDER BY role=\'admin\' DESC, last_seen DESC LIMIT 300', [$roomId, time() - 45]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'uid' => $r['user_id'] ? (int)$r['user_id'] : null,
                'gid' => $r['guest_id'] ? (int)$r['guest_id'] : null,
                'nickname' => $r['nickname'], 'role' => $r['role'],
                'title' => $r['title'] ?? '', 'avatar' => $r['avatar'] ?? '',
            ];
        }
        return $out;
    }

    // ---------- 撤回 ----------
    public static function recall(array $actor, int $msgId): array
    {
        $m = DB::one('SELECT * FROM messages WHERE id=?', [$msgId]);
        if (!$m || $m['recalled']) return [false, '消息不存在或已撤回'];
        $mine = ($actor['kind'] === 'user' && (int)$m['user_id'] === $actor['id'])
             || ($actor['kind'] === 'guest' && (int)$m['guest_id'] === $actor['id']);
        $can = false;
        if ($actor['role'] === 'admin') $can = true;
        if (!$can && $mine && time() - (int)$m['created_at'] <= 180) $can = true;
        if (!$can) {
            $room = self::room((int)$m['room_id']);
            if ($room && $actor['kind'] === 'user' && (int)$room['owner_id'] === $actor['id']) $can = true;
        }
        if (!$can) return [false, '只能撤回 3 分钟内自己发送的消息'];
        DB::run('UPDATE messages SET recalled=1 WHERE id=?', [$msgId]);
        return [true, '已撤回'];
    }

    // ---------- 公告 ----------
    public static function announcements(int $roomId): array
    {
        $rows = DB::all(
            'SELECT * FROM announcements WHERE enabled=1 AND (room_id=0 OR room_id=?) ORDER BY priority DESC, id DESC LIMIT 10',
            [$roomId]
        );
        return array_map(fn($r) => ['id' => (int)$r['id'], 'content' => $r['content'], 'type' => $r['type']], $rows);
    }

    // ---------- IP 归属地（管理员，ip-api.com） ----------
    public static function ipLocation(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '内网地址';
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents("http://ip-api.com/json/$ip?lang=zh-CN", false, $ctx);
        if (!$json) return '查询失败';
        $d = json_decode($json, true);
        if (($d['status'] ?? '') !== 'success') return '未知';
        return trim(($d['country'] ?? '') . ' ' . ($d['regionName'] ?? '') . ' ' . ($d['city'] ?? ''));
    }
}
