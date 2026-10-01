<?php
/**
 * 群公告插件（v1.0.102 自核心剥离）
 *
 * 能力：
 *   - 每个群聊可由「群主 / 超级管理员」发布公告：内容、类型（bar=聊天室上方公告条 /
 *     popup=进群弹窗通知）、是否置顶（置顶优先展示）。
 *   - 成员点击聊天室上方公告条 → 进入群公告页面（群名称 + 全部公告卡片列表）。
 *   - 后台「群公告」管理页：查看 / 删除全部群公告。
 *
 * 安全约束：
 *   - 发布 / 删除路由标记 sensitive（一次性操作票据），且仅群主或超级管理员。
 *   - 内容经 Chat::filterWords 敏感词过滤，长度截断。
 *   - 旧系统公告（announcements 表）首次加载时一次性迁移为本插件表数据。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/** 建表 + 旧系统公告一次性迁移（v1.0.102 剥离时） */
$GLOBALS['oa_boot'] = function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $id = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ts = 'INTEGER NOT NULL';
    if (DB::driver() === 'mysql') {
        $id = 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $ts = 'BIGINT UNSIGNED NOT NULL';
    }
    DB::run("CREATE TABLE IF NOT EXISTS plugin_announcements (
        id {$id}, room_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        nickname VARCHAR(40) NOT NULL DEFAULT '',
        content TEXT NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'bar',
        pinned INTEGER NOT NULL DEFAULT 0,
        created_at {$ts})");
    // 旧 announcements 表（系统公告）一次性导入：room_id 原样、type→bar、priority>0 → 置顶
    try {
        $old = DB::all('SELECT room_id, content, type, priority FROM announcements WHERE enabled=1');
        if ($old && (int)DB::val('SELECT COUNT(*) FROM plugin_announcements') === 0) {
            foreach ($old as $r) {
                DB::insert('plugin_announcements', [
                    'room_id' => (int)$r['room_id'], 'user_id' => 0, 'nickname' => '系统公告',
                    'content' => (string)$r['content'], 'type' => 'bar',
                    'pinned' => (int)$r['priority'] > 0 ? 1 : 0, 'created_at' => time(),
                ]);
            }
        }
    } catch (Throwable $e) { /* 旧表不存在时忽略 */ }
};
$GLOBALS['oa_boot']();

/** 群主 / 超级管理员判定（发布与删除的统一权限闸） */
$oaCanManage = function (array $ctx, int $roomId): bool {
    $a = $ctx['actor'];
    if (($a['role'] ?? '') === 'admin') return true;
    if (($a['kind'] ?? '') !== 'user') return false;
    $owner = (int)(DB::val('SELECT owner_id FROM rooms WHERE id=?', [$roomId]) ?: 0);
    return $owner !== 0 && $owner === (int)$a['id'];
};

/* ---------- 后台管理页：全部群公告列表 ---------- */
Plugin::adminPage('announcements', '群公告', function () {
    $rows = DB::all('SELECT * FROM plugin_announcements ORDER BY pinned DESC, id DESC LIMIT 200');
    $h = '<h2>群公告</h2><p class="ow-admin-desc">各群聊由群主发布的公告（聊天室上方公告条 / 进群弹窗通知）。删除需谨慎，成员端立即不再展示。</p>'
        . '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>群聊ID</th><th>发布者</th><th>内容</th><th>类型</th><th>置顶</th><th>时间</th><th>操作</th></tr>';
    if (!$rows) $h .= '<tr><td colspan="8" style="color:#5C5C5C">暂无公告</td></tr>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . $r['id'] . '</td><td>' . ($r['room_id'] == 0 ? '全部' : $r['room_id']) . '</td>'
            . '<td>' . htmlspecialchars((string)$r['nickname'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars((string)$r['content'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . ($r['type'] === 'popup' ? '弹窗通知' : '公告条') . '</td>'
            . '<td>' . ($r['pinned'] == 1 ? '<span class="ow-tag ow-tag-green">置顶</span>' : '-') . '</td>'
            . '<td>' . date('m-d H:i', (int)$r['created_at']) . '</td>'
            . '<td><a href="javascript:;" onclick="OwOA.adminDel(' . $r['id'] . ',' . (int)$r['room_id'] . ')">删除</a></td></tr>';
    }
    return $h . '</table></div>';
});

/* ---------- 列表（按群） ---------- */
Plugin::route('plugin_announcements_list', function (array $ctx) {
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    if ($roomId <= 0) Api::json(['ok' => false, 'msg' => '非法群聊']);
    $rows = DB::all(
        'SELECT id, room_id, user_id, nickname, content, type, pinned, created_at FROM plugin_announcements
         WHERE room_id=? ORDER BY pinned DESC, id DESC LIMIT 100', [$roomId]);
    Api::json(['ok' => true, 'data' => $rows]);
});

/* ---------- 发布（群主 / 超级管理员；敏感操作） ---------- */
Plugin::route('plugin_announcements_add', function (array $ctx) use ($oaCanManage) {
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    if (!$oaCanManage($ctx, $roomId)) Api::json(['ok' => false, 'msg' => '仅群主或超级管理员可发布公告'], 403);
    $content = Chat::filterWords(trim((string)($ctx['post']['content'] ?? '')));
    if ($content === '') Api::json(['ok' => false, 'msg' => '公告内容不能为空']);
    $type = ($ctx['post']['type'] ?? '') === 'popup' ? 'popup' : 'bar';
    DB::insert('plugin_announcements', [
        'room_id' => $roomId,
        'user_id' => (int)($ctx['actor']['id'] ?? 0),
        'nickname' => (string)($ctx['actor']['nickname'] ?? ''),
        'content' => mb_substr($content, 0, 1000),
        'type' => $type,
        'pinned' => !empty($ctx['post']['pinned']) ? 1 : 0,
        'created_at' => time(),
    ]);
    Sec::log('group_ann_add', $ctx['actor']['nickname'], ['room' => $roomId, 'type' => $type]);
    Api::json(['ok' => true, 'msg' => '公告已发布']);
}, ['sensitive' => true]);

/* ---------- 删除（群主 / 超级管理员；敏感操作） ---------- */
Plugin::route('plugin_announcements_del', function (array $ctx) use ($oaCanManage) {
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    $id = (int)($ctx['post']['id'] ?? 0);
    if (!$oaCanManage($ctx, $roomId)) Api::json(['ok' => false, 'msg' => '仅群主或超级管理员可删除公告'], 403);
    DB::run('DELETE FROM plugin_announcements WHERE id=? AND room_id=?', [$id, $roomId]);
    Sec::log('group_ann_del', $ctx['actor']['nickname'], ['room' => $roomId, 'id' => $id]);
    Api::json(['ok' => true, 'msg' => '公告已删除']);
}, ['sensitive' => true]);

Plugin::asset('js', 'announcements/chat.js');
Plugin::asset('js', 'announcements/admin.js');
Plugin::asset('css', 'announcements/style.css');
