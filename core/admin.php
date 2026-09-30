<?php
/**
 * 管理后台：用户 / 禁言 / 敏感词 / 聊天室 / 公告 / 安全日志 / 站点设置
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

            // ---------- 聊天室管理 ----------
            case 'admin_rooms':
                Api::json(['ok' => true, 'data' => DB::all('SELECT * FROM rooms ORDER BY id')]);

            case 'admin_room_save':
                $id = (int)$p('id', '0');
                if (!in_array($p('type'), ['public', 'password', 'role'], true)) Api::json(['ok' => false, 'msg' => '非法类型']);
                $data = [
                    'name' => $p('name') ?: '未命名房间',
                    'type' => $p('type'),
                    'password' => $p('type') === 'password' ? $p('password') : null,
                    'min_role' => in_array($p('min_role'), ['guest', 'member', 'vip', 'admin'], true) ? $p('min_role') : 'guest',
                    'owner_id' => (int)$p('owner_id', '0') ?: null,
                    'description' => $p('description'),
                    'status' => (int)$p('status', '1'),
                ];
                if ($id > 0) {
                    $sets = implode(',', array_map(fn($c) => "$c=?", array_keys($data)));
                    DB::run("UPDATE rooms SET $sets WHERE id=?", [...array_values($data), $id]);
                } else {
                    // 新建：随机位段 ID（同用户 ID 规则），并发撞主键重新分配重试
                    $attempts = 0;
                    while (true) {
                        try {
                            DB::insert('rooms', $data + [
                                'id' => Auth::nextId('rooms'),
                                'slug' => 'room' . time(),
                                'created_at' => time(),
                            ]);
                            break;
                        } catch (Throwable $e) {
                            if (++$attempts >= 5) throw $e;
                        }
                    }
                }
                Sec::log('admin_room_save', $actor['nickname'], ['id' => $id]);
                Api::json(['ok' => true, 'msg' => '已保存']);

            case 'admin_room_del':
                $id = (int)$p('id');
                DB::run('DELETE FROM rooms WHERE id=? AND slug!=?', [$id, 'public']);
                Api::json(['ok' => true, 'msg' => '已删除（默认房间保留）']);

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
                $data = array_column($rows, 'v', 'k');
                // 附带「自动识别」出的地址，供设置页提示当前识别结果（不入库）
                $data['site_url_detected'] = ow_site_url();
                Api::json(['ok' => true, 'data' => $data]);

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
