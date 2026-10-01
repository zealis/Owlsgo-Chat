<?php
/**
 * 管理后台：用户 / 禁言 / 敏感词 / 群聊 / 公告 / 安全日志 / 站点设置
 */
class Admin
{
    public static function requireAdmin(array $actor): void
    {
        if ($actor['role'] !== 'admin') {
            Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
        }
    }

    public static function handle(string $action, array $actor): void
    {
        /**
         * 单条群聊审核处置（v1.0.78，v1.0.83 提取为闭包供单条/批量共用）。
         * 处置写入回收站（room_review_trash），可撤销；delete 走独立逻辑（见 admin_room_del）。
         * @return array [bool, string]
         */
        $roomReview = function (array $actor, int $id, string $act): array {
            $room = DB::one('SELECT * FROM rooms WHERE id=?', [$id]);
            if (!$room) return [false, '群聊不存在'];
            $trash = function (string $action, array $before) use ($id, $room): void {
                DB::insert('room_review_trash', [
                    'room_id' => $id, 'room_name' => $room['name'],
                    'action' => $action, 'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'undone' => 0, 'created_at' => time(),
                ]);
            };
            if ($act === 'reset_name') {
                $trash('reset_name', ['name' => $room['name']]);
                DB::run("UPDATE rooms SET name=? WHERE id=?", ['未命名群聊', $id]);
                Sec::log('room_review', (string)$id, ['act' => 'reset_name']);
                Plugin::fire('room.after_update', [$id, ['name' => '未命名群聊'], $actor]);
                return [true, '已重置为「未命名群聊」（可在回收站撤销）'];
            }
            if ($act === 'reset_avatar') {
                $old = (string)$room['avatar'];
                $trash('reset_avatar', ['avatar' => $old]);
                DB::run("UPDATE rooms SET avatar='' WHERE id=?", [$id]);
                // 违规头像文件移入回收目录（不删除，撤销时移回）
                if ($old !== '' && strpos($old, 'uploads/avatar/') === 0) {
                    $src = dirname(__DIR__) . '/' . $old;
                    if (is_file($src)) {
                        $trashDir = dirname(__DIR__) . '/data/avatar_trash';
                        if (!is_dir($trashDir)) @mkdir($trashDir, 0775, true);
                        @rename($src, $trashDir . '/' . basename($old));
                    }
                }
                Sec::log('room_review', (string)$id, ['act' => 'reset_avatar']);
                Plugin::fire('room.after_update', [$id, ['avatar' => ''], $actor]);
                return [true, '头像已恢复默认（可在回收站撤销）'];
            }
            if ($act === 'toggle_status') {
                $to = (int)$room['status'] === 1 ? 0 : 1;
                $trash('toggle_status', ['status' => (int)$room['status']]);
                DB::run('UPDATE rooms SET status=? WHERE id=?', [$to, $id]);
                Sec::log('room_review', (string)$id, ['act' => $to ? 'unban' : 'ban']);
                return [true, ($to ? '已解封' : '已封禁') . '（可在回收站撤销）'];
            }
            return [false, '未知审核动作'];
        };

        self::requireAdmin($actor);
        $p = fn($k, $d = '') => trim((string)($_POST[$k] ?? $d));

        switch ($action) {
            // ---------- 用户管理 ----------
            // 用户管理自 v1.0.44 起剥离为插件 user-manager（plugins/user-manager/），
            // 原搜索/角色/积分/禁用接口随迁：plugin_user_manager_search/save/status

            // ---------- 禁言管理 ----------
            // 禁言管理自 v1.0.52 起剥离为插件 ban-manager（plugins/ban-manager/），
            // 原列表/添加/解除接口随迁：plugin_ban_manager_list/add/del。
            // 运行时拦截（Chat::isBanned）与 bans 表保留在核心。

            // ---------- 敏感词 ----------
            case 'admin_words':
                Api::json(['ok' => true, 'data' => DB::all('SELECT * FROM sensitive_words ORDER BY id DESC LIMIT 500')]);

            case 'admin_word_add':
                if ($p('word') === '') Api::json(['ok' => false, 'msg' => '敏感词不能为空']);
                DB::insert('sensitive_words', ['word' => $p('word'), 'replacement' => $p('replacement', '***'), 'enabled' => 1]);
                Api::json(['ok' => true, 'msg' => '已添加']);

            case 'admin_word_toggle':
                DB::run('UPDATE sensitive_words SET enabled=? WHERE id=?', [(int)$p('enabled'), (int)$p('id')]);
                Api::json(['ok' => true, 'msg' => '已更新']);

            case 'admin_word_del':
                DB::run('DELETE FROM sensitive_words WHERE id=?', [(int)$p('id')]);
                Api::json(['ok' => true, 'msg' => '已删除']);

            // ---------- 群聊管理 ----------
            case 'admin_rooms':
                // 审核列表：服务端分页 + 房主/群聊ID搜索；不返回密码字段
                $pageR = max(1, (int)$p('page', '1'));
                $sizeR = min(100, max(1, (int)$p('size', '20')));
                $where = [];
                $args = [];
                $ownerR = (int)$p('owner', '0');
                if ($ownerR > 0) { $where[] = 'owner_id=?'; $args[] = $ownerR; }
                $ridR = (int)$p('rid', '0');
                if ($ridR > 0) { $where[] = 'id=?'; $args[] = $ridR; }
                $wR = $where ? ' WHERE ' . implode(' AND ', $where) : '';
                $totalR = (int)DB::val('SELECT COUNT(*) FROM rooms' . $wR, $args);
                $listR = DB::all('SELECT id, name, avatar, type, min_role, owner_id, status, created_at FROM rooms'
                    . $wR . ' ORDER BY id LIMIT ' . $sizeR . ' OFFSET ' . (($pageR - 1) * $sizeR), $args);
                Api::json(['ok' => true, 'data' => ['list' => $listR, 'total' => $totalR, 'page' => $pageR, 'size' => $sizeR]]);

            case 'admin_room_review':
                // 群聊审核（v1.0.78）：超级管理员仅能做合规处置，不代改群聊内容。
                // 处置写入回收站（room_review_trash），可撤销。
                [$rok, $rmsg] = $roomReview($actor, (int)$p('id', '0'), (string)$p('act', ''));
                Api::json(['ok' => $rok, 'msg' => $rmsg]);

            case 'admin_room_batch':
                // 批量审核（v1.0.83）：ids 逗号分隔，act 同单条（reset_name /
                // reset_avatar / toggle_status / delete）。逐条走同一逻辑（均入回收站），
                // 返回成功/失败计数；失败不影响其余条目。
                $actB = (string)$p('act', '');
                $idsB = array_filter(array_map('intval', explode(',', (string)$p('ids', ''))));
                if (!$idsB) Api::json(['ok' => false, 'msg' => '未选择群聊']);
                $okB = 0; $failB = 0; $lastMsg = '';
                foreach ($idsB as $idB) {
                    if ($actB === 'delete') {
                        // 批量删除：与 admin_room_del 同款（整行快照入回收站 + 默认房间保护）
                        $del = DB::one('SELECT * FROM rooms WHERE id=? AND slug!=?', [$idB, 'public']);
                        if (!$del) { $failB++; $lastMsg = '群聊不存在（默认房间不可删除）'; continue; }
                        DB::insert('room_review_trash', [
                            'room_id' => $idB, 'room_name' => $del['name'],
                            'action' => 'delete', 'before_data' => json_encode(['row' => $del], JSON_UNESCAPED_UNICODE),
                            'undone' => 0, 'created_at' => time(),
                        ]);
                        DB::run('DELETE FROM rooms WHERE id=? AND slug!=?', [$idB, 'public']);
                        Sec::log('room_review', (string)$idB, ['act' => 'delete']);
                        $okB++;
                        continue;
                    }
                    [$rok, $rmsg] = $roomReview($actor, $idB, $actB);
                    if ($rok) { $okB++; } else { $failB++; $lastMsg = $rmsg; }
                }
                Api::json(['ok' => $okB > 0,
                    'msg' => '批量' . ($actB === 'delete' ? '删除' : '处置') . '：成功 ' . $okB . ' 条'
                        . ($failB ? '，失败 ' . $failB . ' 条（' . $lastMsg . '）' : '')]);

            case 'admin_room_trash_list':            case 'admin_room_trash_list':
                // 回收站：服务端分页
                $pageT = max(1, (int)$p('page', '1'));
                $sizeT = min(100, max(1, (int)$p('size', '20')));
                $totalT = (int)DB::val('SELECT COUNT(*) FROM room_review_trash');
                $rowsT = DB::all('SELECT * FROM room_review_trash ORDER BY id DESC LIMIT ' . $sizeT . ' OFFSET ' . (($pageT - 1) * $sizeT));
                foreach ($rowsT as &$r) {
                    $r['before_data'] = json_decode((string)$r['before_data'], true) ?: new stdClass();
                }
                unset($r);
                Api::json(['ok' => true, 'data' => ['list' => $rowsT, 'total' => $totalT, 'page' => $pageT, 'size' => $sizeT]]);

            case 'admin_room_trash_undo':
                // 撤销审核操作。顺序约束：恢复名称/头像/状态前，群聊必须仍存在
                //（若已被删除，必须先撤销对应的「删除」记录）。
                $tid = (int)$p('id', '0');
                $t = DB::one('SELECT * FROM room_review_trash WHERE id=?', [$tid]);
                if (!$t) Api::json(['ok' => false, 'msg' => '回收站记录不存在']);
                if ((int)$t['undone'] === 1) Api::json(['ok' => false, 'msg' => '该记录已撤销过']);
                $before = json_decode((string)$t['before_data'], true) ?: [];
                $roomId = (int)$t['room_id'];
                $room = DB::one('SELECT id FROM rooms WHERE id=?', [$roomId]);
                if ($t['action'] !== 'delete' && !$room) {
                    Api::json(['ok' => false, 'msg' => '该群聊已被删除，请先在回收站撤销对应的「删除」操作']);
                }
                if ($t['action'] === 'reset_name') {
                    DB::run('UPDATE rooms SET name=? WHERE id=?', [(string)$before['name'], $roomId]);
                } elseif ($t['action'] === 'reset_avatar') {
                    $old = (string)$before['avatar'];
                    // 头像文件从回收目录移回
                    $trashFile = dirname(__DIR__) . '/data/avatar_trash/' . basename($old);
                    $dest = dirname(__DIR__) . '/' . $old;
                    if ($old !== '' && is_file($trashFile)) {
                        $destDir = dirname($dest);
                        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
                        @rename($trashFile, $dest);
                    }
                    DB::run('UPDATE rooms SET avatar=? WHERE id=?', [$old, $roomId]);
                } elseif ($t['action'] === 'toggle_status') {
                    DB::run('UPDATE rooms SET status=? WHERE id=?', [(int)$before['status'], $roomId]);
                } elseif ($t['action'] === 'delete') {
                    if ($room) Api::json(['ok' => false, 'msg' => '该群聊已存在，无需恢复']);
                    if ($roomId === 1) Api::json(['ok' => false, 'msg' => '默认群聊不可恢复为删除前状态']);
                    $row = $before['row'] ?? null;
                    if (!is_array($row)) Api::json(['ok' => false, 'msg' => '快照数据缺失，无法恢复']);
                    $cols = array_keys($row);
                    DB::run('INSERT INTO rooms (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
                        array_values($row));
                    Plugin::fire('room.restored', [$roomId, $row, $actor]);
                }
                DB::run('UPDATE room_review_trash SET undone=1 WHERE id=?', [$tid]);
                Sec::log('room_undo', (string)$roomId, ['act' => $t['action'], 'trash' => $tid]);
                Api::json(['ok' => true, 'msg' => '已撤销']);

            case 'admin_room_del':
                $id = (int)$p('id');
                $del = DB::one('SELECT * FROM rooms WHERE id=? AND slug!=?', [$id, 'public']);
                if (!$del) Api::json(['ok' => false, 'msg' => '群聊不存在（默认房间不可删除）']);
                // 整行快照入回收站，可撤销恢复
                DB::insert('room_review_trash', [
                    'room_id' => $id, 'room_name' => $del['name'],
                    'action' => 'delete', 'before_data' => json_encode(['row' => $del], JSON_UNESCAPED_UNICODE),
                    'undone' => 0, 'created_at' => time(),
                ]);
                DB::run('DELETE FROM rooms WHERE id=? AND slug!=?', [$id, 'public']);
                Sec::log('room_review', (string)$id, ['act' => 'delete']);
                Api::json(['ok' => true, 'msg' => '已删除（可在回收站撤销）']);

            // ---------- 公告 ----------
            case 'admin_anns':
                Api::json(['ok' => true, 'data' => DB::all('SELECT * FROM announcements ORDER BY priority DESC, id DESC LIMIT 100')]);

            case 'admin_ann_add':
                if ($p('content') === '') Api::json(['ok' => false, 'msg' => '内容不能为空']);
                DB::insert('announcements', [
                    'room_id' => (int)$p('room_id', '0'), 'content' => $p('content'),
                    'type' => $p('type') === 'welcome' ? 'welcome' : 'announce',
                    'priority' => (int)$p('priority', '0'), 'enabled' => 1, 'created_at' => time(),
                ]);
                Api::json(['ok' => true, 'msg' => '已发布']);

            case 'admin_ann_del':
                DB::run('DELETE FROM announcements WHERE id=?', [(int)$p('id')]);
                Api::json(['ok' => true, 'msg' => '已删除']);

            case 'admin_ann_toggle':
                DB::run('UPDATE announcements SET enabled=? WHERE id=?', [(int)$p('enabled'), (int)$p('id')]);
                Api::json(['ok' => true, 'msg' => '已更新']);

            // ---------- 安全日志 ----------
            case 'admin_logs':
                Api::json(['ok' => true, 'data' => DB::all('SELECT * FROM security_logs ORDER BY id DESC LIMIT 200')]);

            // ---------- 站点设置 ----------
            case 'admin_settings_get':
                $rows = DB::all('SELECT k, v FROM settings');
                Api::json(['ok' => true, 'data' => array_column($rows, 'v', 'k')]);

            case 'admin_settings_save':
                // 创建群聊扣分必须是 0-999999 的整数：填负数或小数会被 (int) 转成一个
                // 「看起来设了、实际不生效」的值（如 -5 → 整个校验被跳过），这里直接拒绝
                if (isset($_POST['room_create_cost']) && trim((string)$_POST['room_create_cost']) !== '') {
                    if (!preg_match('/^\d{1,6}$/', trim((string)$_POST['room_create_cost']))) {
                        Api::json(['ok' => false, 'msg' => '创建群聊扣除积分需填 0-999999 的整数（0 表示免费）']);
                    }
                }
                // 固定网站地址：允许留空（自动识别）；填写时必须是 http(s) 开头的合法地址
                if (isset($_POST['site_url']) && trim((string)$_POST['site_url']) !== '') {
                    $u = trim((string)$_POST['site_url']);
                    if (!preg_match('{^https?://[a-zA-Z0-9._~:/?#\[\]@!$&()*+,;=%-]+$}', $u)) {
                        Api::json(['ok' => false, 'msg' => '固定网站地址需以 http:// 或 https:// 开头']);
                    }
                }
                $allow = ['site_name', 'site_url', 'allow_register', 'reg_email_verify', 'guest_browse', 'guest_chat',
                          'guest_daily_limit', 'msg_rate_window', 'msg_rate_max', 'mail_rate_limit',
                          'sound_default', 'room_pass_ttl',
                          'login_fail_captcha', 'login_fail_lock', 'login_lock_minutes', 'min_register_age',
                          'file_upload', 'file_exts', 'file_max_size',
                          'room_create_allow', 'room_create_cost'];
                foreach ($allow as $k) {
                    if (isset($_POST[$k])) DB::setSetting($k, $p($k));
                }
                Sec::log('admin_settings', $actor['nickname']);
                Api::json(['ok' => true, 'msg' => '设置已保存']);

            // ---------- 插件管理 ----------
            case 'admin_plugins':
                Api::json(['ok' => true, 'data' => Plugin::listAll()]);

            case 'admin_plugin_toggle':
                Plugin::toggle($p('name'), $p('enabled') === '1');
                Api::json(['ok' => true, 'msg' => '已更新']);

            case 'admin_plugin_install':
                if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
                [$ok, $msg] = Plugin::installZip($_FILES['file']);
                Api::json(['ok' => $ok, 'msg' => $msg]);

            case 'admin_plugin_uninstall':
                if (!Plugin::uninstall($p('name'))) Api::json(['ok' => false, 'msg' => '卸载失败（插件不存在或名称非法）']);
                Api::json(['ok' => true, 'msg' => '已卸载']);

            case 'admin_plugin_page':
                $slug = $p('slug');
                $pages = Plugin::adminPages();
                if (!isset($pages[$slug])) {
                    // 已安装但未启用的插件：显示未启用占位页 + 一键启用按钮（v1.0.45）
                    $installed = array_column(Plugin::listAll(), null, 'id');
                    if (isset($installed[$slug])) {
                        Api::json(['ok' => true, 'html' =>
                            '<h2>' . Sec::e($installed[$slug]['name']) . '</h2>'
                            . '<div class="ow-card"><p style="margin:0 0 12px">该插件当前处于<b>停用</b>状态，设置页面不可用。启用后即可使用其功能与设置页。</p>'
                            . '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.pluginToggle(\'' . Sec::e($slug) . '\',1)">启用插件</button></div>']);
                    }
                    Api::json(['ok' => false, 'msg' => '页面不存在'], 404);
                }
                // 兼容两种插件页面写法：fn 内 echo 输出，或 fn 返回 HTML 字符串（v1.0.44）
                ob_start();
                $ret = call_user_func($pages[$slug]['fn']);
                $html = ob_get_clean();
                if ($html === '' && is_string($ret) && $ret !== '') $html = $ret;
                Api::json(['ok' => true, 'html' => $html]);

            default:
                Api::json(['ok' => false, 'msg' => '未知操作'], 404);
        }
    }
}
