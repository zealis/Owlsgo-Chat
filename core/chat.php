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
                'owner_id' => (int)($r['owner_id'] ?? 0),
                'avatar' => (string)($r['avatar'] ?? ''),
                'mine' => (int)($r['owner_id'] ?? 0) === (int)($actor['id'] ?? 0) && $actor['kind'] === 'user',
            ];
            // 前台可编辑（⋮ 菜单）：仅房主（超级管理员走后台审核，不在此列）
            $out[count($out) - 1]['can_edit'] = $actor['kind'] === 'user'
                && (int)($r['owner_id'] ?? 0) === (int)$actor['id'];
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

    // ---------- 密码房通行缓存（避免每次进入都重新输密码） ----------
    /** 缓存时长（秒），0 表示每次都要输入 */
    public static function passTtl(): int
    {
        return (int)DB::setting('room_pass_ttl', 1800);
    }

    /** 该房间是否已持有未过期的通行授权 */
    public static function roomPassCached(int $roomId): bool
    {
        $ttl = self::passTtl();
        if ($ttl <= 0) return false;
        return !empty($_SESSION['room_pass'][$roomId]) && (int)$_SESSION['room_pass'][$roomId] > time();
    }

    /** 授予通行授权（默认保留 30 分钟，可在后台配置） */
    public static function grantRoomPass(int $roomId): void
    {
        $ttl = self::passTtl();
        if ($ttl <= 0) return;
        if (!isset($_SESSION['room_pass']) || !is_array($_SESSION['room_pass'])) $_SESSION['room_pass'] = [];
        $_SESSION['room_pass'][$roomId] = time() + $ttl;
    }

    /**
     * 完整进入校验：角色房查角色，密码房必须有有效通行授权（管理员免密）
     */
    public static function roomAccessOk(array $room, array $actor): bool
    {
        if (!self::canEnter($room, $actor)) return false;
        if ($room['type'] === 'password') {
            if ($actor['role'] === 'admin') return true;      // 管理员免密码
            return self::roomPassCached((int)$room['id']);
        }
        return true;
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
        // 插件扩展判定（v1.0.52）：核心表无禁言时，插件可通过 $reason 追加自定义
        // 禁言逻辑（返回原因字符串即拦截）。每条消息触发一次，回调内避免重查询。
        $reason = null;
        Plugin::fire('ban.check', [&$reason, $actor, $roomId]);
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    // ---------- 敏感词 ----------
    // （v1.0.104）过滤逻辑已剥离为 sensitive-words 插件；核心只提供 text.filter 钩子——
    // 所有输入的文字内容在入库前触发：Plugin::fire('text.filter', [&$text, $scene, $actor])，
    // 场景 scene：message / nickname / room_name / room_desc / announcement。
    public static function filterText(string &$text, string $scene, array $actor = []): void
    {
        Plugin::fire('text.filter', [&$text, $scene, $actor]);
    }

    // ---------- 发送消息 ----------
    public static function send(array $actor, int $roomId, string $type, string $content, array $opt = []): array
    {
        // v1.1.0：私聊走 room_id=0 虚拟私聊空间（不隶属任何群聊，故跳过群校验与群禁言）
        $isDm = $type === 'private' && $roomId === 0;
        $room = $isDm ? ['id' => 0, 'type' => 'dm'] : self::room($roomId);
        if (!$room) return [false, '群聊不存在'];
        if (!$isDm && !self::roomAccessOk($room, $actor)) return [false, '无权进入该群聊'];

        // 游客发言权限
        if ($actor['kind'] === 'guest') {
            if (DB::setting('guest_chat', '1') !== '1') return [false, '站点已禁止游客发言'];
            // v1.1.0：改为「发言间隔」而非「每日限额」。
            // 复用 rate_limits 表做通用计数（bucket=guest_gap），不新增表结构。
            // 间隔 0 = 不限制；返回剩余秒数让前端能提示「还需等 N 秒」。
            $gap = (int)DB::setting('guest_msg_interval', 30);
            if ($gap > 0 && !Sec::rateLimit('guest_gap', 'g' . $actor['id'], $gap, 1)) {
                return [false, "游客发言间隔为 $gap 秒，请稍后再试（登录账号不受此限制）"];
            }
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
        } elseif (!in_array($type, ['text', 'image', 'file', 'system'], true)) {
            $type = 'text';
        }
        if ($type === 'file') {
            // 文件消息 content 是 JSON：只允许 name/size/ext/path 四个字段，
            // 且 path 必须来自上传接口返回（Upload::fileAbs 会再校验一次）
            $info = json_decode($content, true);
            if (!is_array($info) || !Upload::fileAbs((string)($info['path'] ?? ''))) {
                return [false, '文件信息无效'];
            }
            $content = json_encode([
                'name' => mb_substr(preg_replace('/[\\\\\/\x00-\x1F\x7F]/u', '', (string)($info['name'] ?? 'file')), 0, 120),
                'size' => (int)($info['size'] ?? 0),
                'ext'  => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)($info['ext'] ?? ''))),
                'path' => (string)$info['path'],
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($type !== 'image') {
            $content = trim($content);
            if ($content === '') return [false, '消息不能为空'];
            if (mb_strlen($content) > 2000) return [false, '消息过长（最多 2000 字）'];
            self::filterText($content, 'message', $actor);
        }

        // 引用快照：前端传 {nick,text}，服务端只保留两个字段并截断，避免塞入任意结构
        $quote = '';
        if ($type !== 'system' && !empty($opt['quote'])) {
            $q = is_array($opt['quote']) ? $opt['quote'] : json_decode((string)$opt['quote'], true);
            if (is_array($q)) {
                $qn = trim((string)($q['nick'] ?? ''));
                $qt = trim((string)($q['text'] ?? ''));
                if ($qn !== '' || $qt !== '') {
                    // id：被引用消息的 ID，供前端「点击引用跳转到原消息」
                    $quote = json_encode([
                        'nick' => mb_substr($qn, 0, 40),
                        'text' => mb_substr($qt, 0, 120),
                        'id' => (int)($q['id'] ?? 0),
                    ], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        // 钩子：可改写引用内容（如脱敏、追加上下文），或直接清空以禁用该条引用
        if ($quote !== '') Plugin::fire('message.quote', [&$quote, $content, $actor, $roomId]);

        Plugin::fire('message.before_send', [&$content, $actor, $roomId]);

        $id = DB::insert('messages', [
            'room_id' => $roomId,
            'user_id' => $actor['kind'] === 'user' ? $actor['id'] : null,
            'guest_id' => $actor['kind'] === 'guest' ? $actor['id'] : null,
            'nickname' => $actor['nickname'], 'role' => $actor['role'],
            'title' => $actor['title'] ?? '', 'avatar' => $actor['avatar'] ?? '',
            'type' => $type, 'content' => $content,
            'to_user_id' => $toUserId, 'to_guest_id' => $toGuestId, 'to_nickname' => $toNickname,
            'quote' => $quote,
            // v1.1.0 新增：deleted 默认 0（新增列的 DEFAULT 已兜底，这里显式写清语义）
            'recalled' => 0, 'deleted' => 0, 'deleted_at' => 0, 'deleted_by' => '',
            'ip' => Sec::ip(), 'created_at' => time(),
        ]);
        // v1.1.0：游客每日计数（daily_count）已随「每日限额」下线而废弃，
        // 改为在 Sec::rateLimit('guest_gap', …) 里按间隔限流，无需再更新 guests 表。
        Plugin::fire('message.after_send', [$id, $actor, $roomId]);
        return [true, 'ok', $id];
    }

    // ---------- 消息序列化（含可见性过滤） ----------
    private static function visible(array $m, array $actor): bool
    {
        if ($m['type'] !== 'private') return true;
        // v1.1.0：私聊仅双方可见（超级管理员亦不例外，后台同样不可越权查看）
        if ($actor['kind'] === 'user' && ((int)$m['user_id'] === $actor['id'] || (int)$m['to_user_id'] === $actor['id'])) return true;
        if ($actor['kind'] === 'guest' && ((int)$m['guest_id'] === $actor['id'] || (int)$m['to_guest_id'] === $actor['id'])) return true;
        return false;
    }

    public static function pack(array $m, array $actor): array
    {
        $admin = $actor['role'] === 'admin';
        $deleted = (int)($m['deleted'] ?? 0) === 1;
        return [
            'id' => (int)$m['id'], 'room' => (int)$m['room_id'],
            'uid' => $m['user_id'] ? (int)$m['user_id'] : null,
            'gid' => $m['guest_id'] ? (int)$m['guest_id'] : null,
            'nickname' => $m['nickname'], 'role' => $m['role'],
            'title' => $m['title'] ?? '', 'avatar' => $m['avatar'] ?? '',
            'type' => $m['type'],
            // 软删除与撤回都清空正文：deleted 额外带 deleted 标记，
            // 前台据此显示「该消息已删除」而非「已撤回」，语义不同。
            'content' => ($m['recalled'] || $deleted) ? '' : $m['content'],
            'recalled' => (int)$m['recalled'],
            'deleted' => $deleted,
            'quote' => ($m['recalled'] || $deleted) ? null : (json_decode((string)($m['quote'] ?? ''), true) ?: null),
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
        self::purgeDeleted();   // v1.1.0：顺带清理过保留期的软删除消息（内部有小时级节流）
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
            'server_time' => time(),
        ];
    }

    // ---------- 私聊会话（v1.1.0） ----------
    // 存储约定：私聊消息 room_id=0（虚拟私聊空间），to_user_id / to_guest_id 标识接收方；
    // 可见性由 visible() 收口为「仅双方」，超管亦不可越权。会话列表 = 群聊 + 私聊按最后活跃时间倒序。

    /** 解析对方标识：'user:12' / 'guest:34' → [kind, id] */
    public static function dmPeerKey(array $actor, string $peer): ?array
    {
        if (!preg_match('/^(user|guest):(\d{1,10})$/', trim($peer), $m)) return null;
        $kind = $m[1];
        $id = (int)$m[2];
        if ($id <= 0) return null;
        // 不能与自己私聊
        if ($kind === $actor['kind'] && $id === (int)$actor['id']) return null;
        return [$kind, $id];
    }

    /** 对方资料（用于会话头与私聊页标题） */
    public static function dmPeerInfo(string $kind, int $id): array
    {
        if ($kind === 'user') {
            $u = DB::one('SELECT id, nickname, avatar FROM users WHERE id=?', [$id]);
            return $u ? ['kind' => 'user', 'id' => (int)$u['id'], 'name' => (string)$u['nickname'], 'avatar' => (string)($u['avatar'] ?? '')] : [];
        }
        $g = DB::one('SELECT id, nickname FROM guests WHERE id=?', [$id]);
        return $g ? ['kind' => 'guest', 'id' => (int)$g['id'], 'name' => '游客' . substr((string)$g['nickname'], 2), 'avatar' => ''] : [];
    }

    /**
     * 会话列表：群聊 + 私聊聚合，统一按最后活跃时间倒序（无消息的群聊排最后）。
     * @return array 每个项：{conv:'room'|'dm', id, peer, name, avatar, last_at, last_text, need_password, can_edit, mine}
     */
    public static function conversations(array $actor): array
    {
        $out = [];

        // 群聊：各房间最后一条消息（一条 GROUP BY 取回，避免逐房间查询）
        $last = [];
        foreach (DB::all('SELECT room_id, MAX(id) AS mid, MAX(created_at) AS at FROM messages WHERE room_id>0 GROUP BY room_id') as $row) {
            $last[(int)$row['room_id']] = ['at' => (int)$row['at'], 'id' => (int)$row['mid']];
        }
        $lastText = [];
        if ($last) {
            $ids = array_values(array_map(fn($x) => $x['id'], $last));
            $q = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all("SELECT id, type, content FROM messages WHERE id IN ($q)", $ids) as $m) {
                $lastText[(int)$m['id']] = self::convText($m);
            }
        }
        foreach (self::rooms($actor) as $r) {
            $meta = $last[$r['id']] ?? ['at' => 0, 'id' => 0];
            $out[] = [
                'conv' => 'room', 'id' => $r['id'], 'peer' => '',
                'name' => $r['name'], 'avatar' => $r['avatar'],
                'last_at' => $meta['at'], 'last_text' => $lastText[$meta['id']] ?? '',
                'need_password' => $r['need_password'], 'can_edit' => $r['can_edit'], 'mine' => $r['mine'],
            ];
        }

        // 私聊：与我有关的私聊消息按对方分组，各取最新一条
        if ($actor['kind'] === 'none') return self::sortConversations($out);
        $mineUser = $actor['kind'] === 'user' ? (int)$actor['id'] : 0;
        $mineGuest = $actor['kind'] === 'guest' ? (int)$actor['id'] : 0;

        // ★ v1.1.0 修复两个缺陷：
        //   ① 方向缺失：原条件只匹配发送方列（user_id / guest_id = 我），
        //      「对方发给我」的私聊在接收方列表里完全不出现——这就是「接收不到别人私聊」的根因。
        //   ② 越权泄漏：未使用的身份列存的是 0 而不是 NULL，
        //      所以 `to_guest_id = 0`（游客身份时 mineGuest=0）会匹配到所有人的消息，
        //      任何用户都能看到全站私聊会话。这里对未使用的身份列改用 `> 0` 严格大于判定，
        //      只让真正属于自己身份的那一支参与匹配。
        $conds = ['(user_id > 0 AND user_id = ?)', '(to_user_id > 0 AND to_user_id = ?)'];
        $args = [$mineUser ?: 0, $mineUser ?: 0];
        if ($mineGuest > 0) {
            $conds[] = '(guest_id > 0 AND guest_id = ?)';
            $args[] = $mineGuest;
            $conds[] = '(to_guest_id > 0 AND to_guest_id = ?)';
            $args[] = $mineGuest;
        }
        $rows = DB::all(
            'SELECT * FROM messages WHERE type=\'private\' AND room_id=0
             AND (' . implode(' OR ', $conds) . ')
             ORDER BY id DESC LIMIT 300',
            $args
        );
        $seen = [];
        foreach ($rows as $m) {
            // 对方 = 另一方。私聊可能是「用户↔用户」「游客↔游客」「用户↔游客」三种组合，
            // 所以两个分支都必须先判断【本条消息是谁发的】，再按接收列（to_user_id / to_guest_id，
            // 恒有且仅有一个非空）取对方——不能假定双方同 kind。
            $sentByMe = $actor['kind'] === 'user'
                ? ((int)($m['user_id'] ?? 0) === $mineUser)
                : ((int)($m['guest_id'] ?? 0) === $mineGuest);
            if ($sentByMe) {
                $pKind = ((int)($m['to_user_id'] ?? 0) > 0) ? 'user' : 'guest';
                $pId = (int)(($m['to_user_id'] ?? 0) ?: ($m['to_guest_id'] ?? 0));
            } else {
                $pKind = ((int)($m['user_id'] ?? 0) > 0) ? 'user' : 'guest';
                $pId = (int)(($m['user_id'] ?? 0) ?: ($m['guest_id'] ?? 0));
            }
            if ($pId <= 0) continue;           // 接收方缺失的异常数据，直接跳过
            $key = $pKind . ':' . $pId;
            if (isset($seen[$key])) continue;   // 已取到最新一条
            $seen[$key] = true;
            $info = self::dmPeerInfo($pKind, $pId);
            if (!$info) continue;              // 对方已注销
            $out[] = [
                'conv' => 'dm', 'id' => $pId, 'peer' => $key,
                'name' => $info['name'], 'avatar' => $info['avatar'],
                'last_at' => (int)$m['created_at'], 'last_text' => self::convText($m),
                'need_password' => false, 'can_edit' => false, 'mine' => false,
            ];
        }
        return self::sortConversations($out);
    }

    /** 会话排序：按最后活跃时间倒序；无消息（at=0）的沉底，其内按群聊 id 升序 */
    private static function sortConversations(array $list): array
    {
        usort($list, function ($a, $b) {
            if ($a['last_at'] !== $b['last_at']) return $b['last_at'] <=> $a['last_at'];
            return $a['id'] <=> $b['id'];
        });
        return $list;
    }

    /** 会话列表用的一句话摘要（按类型给出可读文本） */
    private static function convText(array $m): string
    {
        $t = (string)$m['type'];
        if ($t === 'image') return '[图片]';
        if ($t === 'file') {
            $info = json_decode((string)$m['content'], true);
            return '[文件] ' . (is_array($info) ? (string)($info['name'] ?? '') : '');
        }
        return mb_substr((string)$m['content'], 0, 60);
    }

    /** 私聊历史：仅双方可见；按 id 升序返回（向上翻页用 before_id） */
    public static function dmHistory(array $actor, array $peer, int $beforeId = 0, int $limit = 30): array
    {
        [$pk, $pid] = $peer;
        [$cond, $args] = self::dmPairSql($actor, $pk, $pid, $beforeId > 0 ? 'id<?' : '', $beforeId > 0 ? [$beforeId] : []);
        $sql = "SELECT * FROM messages WHERE type='private' AND room_id=0 AND $cond"
             . ' ORDER BY id DESC LIMIT ' . max(1, min(50, $limit));
        $rows = DB::all($sql, $args);
        $rows = array_reverse($rows);           // 升序返回给前端直接追加
        return array_map(fn($m) => self::pack($m, $actor), $rows);
    }

    /**
     * 私聊合规查阅（v1.1.0）：**唯一**允许非当事人读取私聊内容的入口。
     *
     * 背景：v1.1.0 定下「私聊仅双方可见，管理员亦不例外」这条硬约束，
     * 但部分司法辖区（如 GDPR 的合法利益 / 司法协助例外）要求数据控制者
     * 在有合法权限时必须能提供记录。两者需要共存，故：
     *
     *   1. **默认关闭**：本方法只在插件注册了 `dm.read` 钩子且回调明确放行时才可被调用；
     *      插件不存在（未安装 / 未启用）时，路由根本不存在，物理上无法查阅。
     *   2. **仅超级管理员**：`$actor['role']` 必须是 admin，member 与游客一律拒绝。
     *   3. **强制留痕**：无论是否查到内容，都写安全日志（含查询条件与命中条数）。
     *      合规场景下「没查到」本身也是需要举证的答复。
     *   4. **只读**：本方法不提供任何写路径，插件也拿不到 DB 句柄以外的东西。
     *
     * @param int    $targetId  被查阅的用户 ID（查该用户参与的所有私聊）
     * @param string $from      起始日期 Y-m-d（空 = 不限）
     * @param string $to        结束日期 Y-m-d（空 = 不限）
     * @param int    $limit     最多返回条数
     * @return array{ok:bool, msg:string, data:array, total:int}
     */
    public static function dmComplianceRead(array $actor, int $targetId, string $from = '', string $to = '', int $limit = 200): array
    {
        // 1) 仅超级管理员
        if (($actor['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'msg' => '仅超级管理员可查阅', 'data' => [], 'total' => 0];
        }
        if ($targetId <= 0) return ['ok' => false, 'msg' => '请填写有效的用户 ID', 'data' => [], 'total' => 0];

        // 2) 钩子放行：未注册 dm.read 的插件（如本插件被停用）一律拒绝。
        //    class_exists 防御：某些精简部署（如仅 CLI 批处理）可能不加载 Plugin 类，
        //    此时合规能力应「不可用」而不是 fatal error。
        $allow = false; $reason = '未启用合规查阅能力';
        if (class_exists('Plugin')) {
            Plugin::fire('dm.read', [&$allow, &$reason, $actor, $targetId, $from, $to]);
        }
        if (!$allow) return ['ok' => false, 'msg' => $reason, 'data' => [], 'total' => 0];

        // 3) 组装查询：目标用户参与的所有私聊（作为发送方或接收方都要覆盖）
        $cond = "type='private' AND room_id=0 AND ((user_id > 0 AND user_id = ?) OR (to_user_id > 0 AND to_user_id = ?))";
        $args = [$targetId, $targetId];
        if ($from !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                return ['ok' => false, 'msg' => '起始日期格式应为 YYYY-MM-DD', 'data' => [], 'total' => 0];
            }
            $cond .= ' AND created_at >= ?'; $args[] = strtotime($from . ' 00:00:00');
        }
        if ($to !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return ['ok' => false, 'msg' => '结束日期格式应为 YYYY-MM-DD', 'data' => [], 'total' => 0];
            }
            $cond .= ' AND created_at <= ?'; $args[] = strtotime($to . ' 23:59:59');
        }
        $limit = max(1, min(1000, $limit));
        $rows = DB::all("SELECT * FROM messages WHERE $cond ORDER BY id DESC LIMIT $limit", $args);

        // 4) 打包：按「对方」分组，标注每条的发送方与接收方，方便还原对话
        $target = DB::one('SELECT id,nickname FROM users WHERE id=?', [$targetId]);
        $data = [];
        foreach (array_reverse($rows) as $m) {
            $peerUid = (int)($m['user_id'] ?? 0) === $targetId
                ? (int)($m['to_user_id'] ?? 0) : (int)($m['user_id'] ?? 0);
            $peerNick = (string)($m['to_nickname'] ?? '');
            if ($peerUid > 0 && $peerNick === '') {
                $peerNick = (string)(DB::val('SELECT nickname FROM users WHERE id=?', [$peerUid]) ?: ('用户' . $peerUid));
            }
            $data[] = [
                'id' => (int)$m['id'],
                'from_user' => (int)($m['user_id'] ?? 0),      // 0 = 游客
                'from_nick' => (string)$m['nickname'],
                'to_user' => (int)($m['to_user_id'] ?? 0),
                'peer_uid' => $peerUid,
                'peer_nick' => $peerNick,
                'type' => (string)$m['type'],
                'deleted' => (int)($m['deleted'] ?? 0) === 1,
                'recalled' => (int)$m['recalled'] === 1,
                'content' => ((int)$m['recalled'] || (int)($m['deleted'] ?? 0) === 1) ? '' : (string)$m['content'],
                'ip' => (string)($m['ip'] ?? ''),
                'time' => date('Y-m-d H:i:s', (int)$m['created_at']),
                'ts' => (int)$m['created_at'],
            ];
        }

        // 5) 强制留痕：无论有无命中都要记（合规答复需要举证「查过但没有」）
        Sec::log('dm_compliance_read', (string)$actor['nickname'], [
            'target_id' => $targetId,
            'target_nick' => (string)($target['nickname'] ?? ''),
            'from' => $from, 'to' => $to, 'hit' => count($data),
        ]);
        return ['ok' => true, 'msg' => 'ok', 'data' => $data, 'total' => count($data)];
    }

    /** 私聊增量轮询：与 poll 同构（长挂起），返回新消息 */
    public static function dmPoll(array $actor, array $peer, int $sinceId, int $timeout = 20): array
    {
        [$pk, $pid] = $peer;
        [$cond, $args] = self::dmPairSql($actor, $pk, $pid, 'id>?', [$sinceId]);
        $sql = "SELECT * FROM messages WHERE type='private' AND room_id=0 AND $cond ORDER BY id LIMIT 200";
        $deadline = time() + max(5, min(30, $timeout));
        $new = [];
        while (time() < $deadline) {
            $rows = DB::all($sql, $args);
            if ($rows) {
                foreach ($rows as $m) {
                    if (self::visible($m, $actor)) $new[] = self::pack($m, $actor);
                    $sinceId = max($sinceId, (int)$m['id']);
                }
                break;
            }
            Plugin::cronTick();
            usleep(500000);
            if (connection_aborted()) exit;
        }
        return ['since' => $sinceId, 'messages' => $new, 'server_time' => time()];
    }

    /**
     * 私聊双方条件 + 对应参数（合一对返回，避免 SQL 与参数错位）。
     *
     * 私聊有三种组合：用户↔用户、游客↔游客、用户↔游客。消息的发送方落在
     * user_id / guest_id 之一，接收方落在 to_user_id / to_guest_id 之一，
     * 因此每条分支都要按该方向的【真实身份】选列。
     *
     * ⚠️ 两条硬约束（都是实测踩出来的，不是风格偏好）：
     * 1. 每个分支内部必须写成「发送方列 = ? AND 接收方列 = ?」，发送方列在前。
     *    若写成 `to_user_id=? AND user_id=?`（接收方在前），SQLite 在
     *    EMULATE_PREPARES=false 下会复用错位的绑定值 → 只查得到自己发出的那条。
     * 2. 「对方 → 我」这一支的发送方列必须换成对方的列（跨身份时是 guest_id ↔ user_id 互换），
     *    否则该支永远不成立。
     * 已验证：用户↔用户、用户↔游客、游客↔用户三种视角均能取到双向完整历史。
     *
     * @return array [ [cond1, args1], [cond2, args2] ]
     */
    private static function dmPairConds(array $actor, string $pk, int $pid): array
    {
        $my = (int)$actor['id'];
        $myCol = $actor['kind'] === 'user' ? 'user_id' : 'guest_id';       // 我的发送方列
        $myRecv = $actor['kind'] === 'user' ? 'to_user_id' : 'to_guest_id'; // 写给我的接收方列
        $pSend = $pk === 'user' ? 'user_id' : 'guest_id';                  // 对方的发送方列
        $pRecv = $pk === 'user' ? 'to_user_id' : 'to_guest_id';           // 我发给对方的接收方列
        return [
            [$myCol . '=? AND ' . $pRecv . '=?', [$my, $pid]],   // 我 → 对方
            [$pSend . '=? AND ' . $myRecv . '=?', [$pid, $my]],  // 对方 → 我
        ];
    }

    /**
     * 拼成完整条件与扁平参数数组。
     * tail 只接受不含占位符的附加条件；带占位符时由 tailArgs 按出现顺序追加。
     */
    private static function dmPairSql(array $actor, string $pk, int $pid, string $tail = '', array $tailArgs = []): array
    {
        $conds = self::dmPairConds($actor, $pk, $pid);
        $sql = '((' . $conds[0][0] . ') OR (' . $conds[1][0] . '))';
        $args = array_merge($conds[0][1], $conds[1][1]);
        if ($tail !== '') {
            $sql .= ' AND ' . $tail;
            $args = array_merge($args, $tailArgs);
        }
        return [$sql, $args];
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
    /**
     * 删除消息（内容右键「删除」）：物理删除，与「撤回」区分
     * ——撤回是标记 recalled 保留占位，删除是真的从库中移除。
     * 权限：作者本人（不限时间）、管理员、该群房主。
     *
     * @return array [bool, string]
     */
    /**
     * 删除消息（v1.1.0 起统一为软删除）。
     *
     * 需求背景：聊天记录涉及个人数据，法规要求「删除权」与「留痕举证」并存。
     * 因此**所有**删除（含超级管理员）都只做软删除——行保留，
     * content 清空（原文不可恢复），但昵称 / 时间 / IP 等元数据留存以备审计。
     * 到期后由 purgeDeleted() 物理清除，行彻底消失。
     *
     * 与 recall（撤回）的区别：撤回是用户对自己消息的 3 分钟内操作，不涉及合规；
     * 删除是管理动作，进安全日志。
     */
    public static function deleteMessage(array $actor, int $msgId): array
    {
        $m = DB::one('SELECT * FROM messages WHERE id=?', [$msgId]);
        if (!$m) return [false, '消息不存在'];
        if ((int)($m['deleted'] ?? 0) === 1) return [false, '消息已删除'];
        $mine = ($actor['kind'] === 'user' && (int)$m['user_id'] === $actor['id'])
             || ($actor['kind'] === 'guest' && (int)$m['guest_id'] === $actor['id']);
        $can = $actor['role'] === 'admin' || $mine;
        if (!$can) {
            $room = self::room((int)$m['room_id']);
            if ($room && $actor['kind'] === 'user' && (int)$room['owner_id'] === $actor['id']) $can = true;
        }
        // 钩子：可放行或拦截（$allow 置 false 即拒绝，$reason 为展示给用户的理由）
        $allow = $can; $reason = '';
        Plugin::fire('msg.before_delete', [&$allow, &$reason, $m, $actor]);
        if (!$allow) return [false, $reason !== '' ? $reason : '无权删除该消息'];

        // 软删除：清空正文与引用（原文不留存），行本身保留到保留期结束。
        // quote 列是 NOT NULL（历史建表约束），必须写空串而不是 NULL。
        DB::run('UPDATE messages SET deleted=1, content=?, quote=?, deleted_at=?, deleted_by=? WHERE id=?',
            ['', '', time(), mb_substr((string)($actor['nickname'] ?? ''), 0, 64), $msgId]);
        Sec::log('msg_delete', (string)$msgId, [
            'room' => (int)$m['room_id'],
            'type' => (string)$m['type'],
            'by' => (string)($actor['kind'] ?? ''),
        ]);
        Plugin::fire('msg.after_delete', [$msgId, $m, $actor]);
        return [true, '已删除'];
    }

    /**
     * 清理超过保留期的软删除消息（物理 DELETE，行彻底消失）。
     * 保留期由系统设置 msg_deleted_retain_days 控制，0 = 不清理（永久保留）。
     * 每次读消息时顺带触发一次（低频、不在事务里），避免额外的定时任务依赖。
     */
    public static function purgeDeleted(): void
    {
        static $lastRun = 0;
        $days = (int)DB::setting('msg_deleted_retain_days', 30);
        if ($days <= 0) return;                       // 0 = 永久保留
        if (time() - $lastRun < 3600) return;         // 每小时最多跑一次
        $lastRun = time();
        $before = time() - $days * 86400;
        try {
            $n = DB::run('DELETE FROM messages WHERE deleted=1 AND deleted_at > 0 AND deleted_at < ?', [$before]);
            if ($n > 0) Sec::log('msg_purge', 'system', ['count' => (int)$n, 'retain_days' => $days]);
        } catch (Throwable $e) {
            // 清理失败绝不能影响正常读消息，静默即可
        }
    }

    /**
     * 前台编辑群聊信息（列表 ⋮ 菜单）：群名称 / 简介 / 头像。
     * 权限：管理员或房主。slug、类型、密码等管理性字段不在前台开放。
     *
     * @return array [bool, string]
     */
    public static function updateRoom(array $actor, int $roomId, string $name, string $description, string $avatar = ''): array
    {
        $room = self::room($roomId);
        if (!$room) return [false, '群聊不存在'];
        // v1.0.78 起前台仅房主可编辑（超级管理员在后台只做审核，不再代改群聊信息）
        $isOwner = $actor['kind'] === 'user' && (int)$room['owner_id'] === (int)$actor['id'];
        if (!$isOwner) return [false, '仅群主可编辑群聊信息'];

        $name = trim($name);
        if (mb_strlen($name) < 1 || mb_strlen($name) > 30) return [false, '群名称需 1-30 个字符'];
        $description = trim($description);
        if (mb_strlen($description) > 200) return [false, '群简介不能超过 200 字'];
        // 敏感词过滤（与发言同一套词库：命中替换）
        self::filterText($name, 'room_name', $actor);
        self::filterText($description, 'room_desc', $actor);
        // 头像：仅接受本站头像目录下的相对路径（由上传接口产出），空串表示不修改
        $avatar = trim($avatar);
        if ($avatar !== '' && strpos($avatar, 'uploads/avatar/') !== 0) return [false, '头像路径不合法'];

        $sets = ['name' => $name, 'description' => $description];
        if ($avatar !== '') $sets['avatar'] = $avatar;
        $up = implode(',', array_map(fn($c) => "$c=?", array_keys($sets)));
        DB::run("UPDATE rooms SET $up WHERE id=?", [...array_values($sets), $roomId]);
        Sec::log('room_update', $name, ['id' => $roomId, 'by' => $actor['role']]);
        Plugin::fire('room.after_update', [$roomId, $sets, $actor]);
        return [true, '已保存'];
    }

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
    // （v1.0.102）系统公告已剥离为 announcements 插件（群公告体系，见 plugins/announcements/），
    // 核心不再下发 announcements 字段；插件经 onRoomSwitch 钩子按群自行拉取。
}
