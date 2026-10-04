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
 * v1.1.2：**彻底删除「系统公告 / 全站公告」（room_id=0）这一整套语义**。
 *   历史包袱是：room_id=0 曾被当作「对所有群生效」的全站公告标记，
 *   与私聊虚拟空间（同样是 room_id=0）语义打架，且删除时必然假成功
 *   （前端传当前群 id，SQL 却按公告自身 room_id 过滤 → 匹配 0 行却无条件返回 ok）。
 *   现在公告一律隶属具体群聊：list 只查本群、add 拒绝 room_id<=0、
 *   del 按公告自身 room_id 判权限并检查实际删除行数。旧 announcements 表的迁移代码同步删除。
 *
 * 安全约束：
 *   - 发布 / 删除路由标记 sensitive（一次性操作票据），且仅群主或超级管理员。
 *   - 内容经 Chat::filterText 敏感词过滤，长度截断。
 */
if (!defined('HALOU_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/** 建表（v1.1.2：去掉旧 announcements 表迁移，公告只隶属具体群聊） */
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
        id {$id}, room_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        nickname VARCHAR(40) NOT NULL DEFAULT '',
        content TEXT NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'bar',
        pinned INTEGER NOT NULL DEFAULT 0,
        created_at {$ts})");
    // v1.1.2 一次性清理：旧版遗留的 room_id=0 全站公告（当时由旧 announcements 表迁移而来）。
    // 表已是空时这条 DELETE 影响 0 行，是幂等的安全操作。
    DB::run('DELETE FROM plugin_announcements WHERE room_id<=0');
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

/* ---------- 后台管理页（v1.0.108 模仿附件列表：群聊ID搜索 + 多选批量 + 通用分页） ---------- */
Plugin::adminPage('announcements', '群聊公告', function () {
    return '<h2>群聊公告</h2><p class="ha-admin-desc">各群聊由群主发布的公告（聊天室上方公告条 / 进群弹窗通知）。删除需谨慎，成员端立即不再展示。</p>'
        . '<div class="ha-card"><div class="ha-form-row">'
        . '<div class="ha-form-item" style="min-width:140px"><label>群聊ID</label>'
        . '<input class="ha-input" id="oaAdmRoom" type="number" min="1" placeholder="留空=全部" value="" onkeydown="if(event.key===\'Enter\')HaOA.load(1)"></div>'
        . '<button class="ha-btn ha-btn-primary" onclick="HaOA.load(1)">搜索</button>'
        . '<button class="ha-btn ha-btn-ghost" onclick="HaOA.resetFilter()">重置</button>'
        . '</div></div>'
        . '<div class="ha-card">'
        . '<div class="ha-admin-batch">'
        . '<button class="ha-btn ha-btn-danger" id="oaAdmBatchDel" onclick="HaOA.batchDelete()" disabled>批量删除</button>'
        . '<span id="oaAdmStat" style="color:var(--ha-text-sub);font-size:12px"></span>'
        . '</div>'
        . '<div class="ha-table-wrap"><table class="ha-table" id="oaAdmTable"></table></div>'
        . '<div id="oaAdmPager"></div></div>';
});

/** 后台分页数据（支持群聊ID过滤；v1.1.2 起 room_id 必须 > 0，留空=全部） */
Plugin::route('plugin_announcements_admin', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $page = max(1, (int)($ctx['post']['page'] ?? 1));
    $size = min(100, max(1, (int)($ctx['post']['size'] ?? 30)));
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    $where = $roomId > 0 ? ' WHERE room_id=' . $roomId : '';
    $total = (int)DB::val('SELECT COUNT(*) FROM plugin_announcements' . $where);
    $rows = DB::all('SELECT * FROM plugin_announcements' . $where . ' ORDER BY pinned DESC, id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size));
    Api::json(['ok' => true, 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]]);
});

/**
 * 批量删除（后台仅管理员可达；敏感操作）
 * v1.1.2：成功数按 rowCount 累计，不再无条件 +1（原来 ids 里混一个不存在的 id 也会报成功）
 */
Plugin::route('plugin_announcements_batch', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $ids = array_filter(array_map('intval', explode(',', (string)($ctx['post']['ids'] ?? ''))));
    if (!$ids) Api::json(['ok' => false, 'msg' => '未选择公告']);
    $ok = 0;
    foreach ($ids as $id) {
        $st = DB::run('DELETE FROM plugin_announcements WHERE id=?', [$id]);
        if (is_object($st) && method_exists($st, 'rowCount')) $ok += (int)$st->rowCount();
    }
    Sec::log('group_ann_batch_del', $ctx['actor']['nickname'], ['count' => $ok]);
    Api::json(['ok' => $ok > 0, 'msg' => '批量删除：成功 ' . $ok . ' 条'
        . ($ok < count($ids) ? '（' . (count($ids) - $ok) . ' 条不存在或已删除）' : '')]);
}, ['sensitive' => true]);

/* ---------- 列表（只查本群；v1.1.2 已删除 room_id=0 全站公告） ---------- */
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
    // v1.1.2：room_id<=0 一律拒绝（room_id=0 是私聊虚拟空间，不是「全站公告」）
    if ($roomId <= 0) Api::json(['ok' => false, 'msg' => '非法群聊'], 400);
    if (!$oaCanManage($ctx, $roomId)) Api::json(['ok' => false, 'msg' => '仅群主或超级管理员可发布公告'], 403);
    $content = trim((string)($ctx['post']['content'] ?? ''));
    Chat::filterText($content, 'announcement', $ctx['actor']);   // 敏感词过滤（text.filter 钩子）
    if ($content === '') Api::json(['ok' => false, 'msg' => '公告内容不能为空']);
    $type = ($ctx['post']['type'] ?? '') === 'popup' ? 'popup' : 'bar';
    DB::insert('plugin_announcements', [
        'room_id' => $roomId,
        'user_id' => (int)($ctx['actor']['id'] ?? 0),
        'nickname' => (string)($ctx['actor']['nickname'] ?? ''),
        'content' => mb_substr($content, 0, 600),
        'type' => $type,
        'pinned' => !empty($ctx['post']['pinned']) ? 1 : 0,
        'created_at' => time(),
    ]);
    Sec::log('group_ann_add', $ctx['actor']['nickname'], ['room' => $roomId, 'type' => $type]);
    Api::json(['ok' => true, 'msg' => '公告已发布']);
}, ['sensitive' => true]);

/**
 * 删除（群主 / 超级管理员；敏感操作）
 *
 * v1.1.2 重写。原实现有两个叠加缺陷，本条是真·假成功的教科书案例：
 *   ① SQL 写成 `WHERE id=? AND room_id=?`，而 room_id 来自**当前群**。
 *      一旦目标公告的 room_id 与当前群不同（当时的全站公告 room_id=0），
 *      匹配 0 行，数据库什么都没删；
 *   ② 但代码**无条件返回 ok:true**，从不检查 rowCount()。
 *      于是接口说「公告已删除」、前端弹 toast 关弹窗，刷新后公告原封不动 —— 用户完全无从判断真假。
 *
 * 现在：先取出公告自身归属的 room_id（不是调用方传的），按它判权限，
 * 再仅以 id 为条件删除，并**以 rowCount 作为唯一成功判据**。
 */
Plugin::route('plugin_announcements_del', function (array $ctx) use ($oaCanManage) {
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) Api::json(['ok' => false, 'msg' => '非法的公告 ID'], 400);
    $row = DB::one('SELECT id, room_id FROM plugin_announcements WHERE id=?', [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '公告不存在或已被删除']);
    $roomId = (int)$row['room_id'];
    if (!$oaCanManage($ctx, $roomId)) Api::json(['ok' => false, 'msg' => '仅群主或超级管理员可删除公告'], 403);
    $st = DB::run('DELETE FROM plugin_announcements WHERE id=?', [$id]);
    $n = is_object($st) && method_exists($st, 'rowCount') ? (int)$st->rowCount() : 0;
    if ($n <= 0) Api::json(['ok' => false, 'msg' => '删除失败，公告可能已被删除'], 409);
    Sec::log('group_ann_del', $ctx['actor']['nickname'], ['room' => $roomId, 'id' => $id]);
    Api::json(['ok' => true, 'msg' => '公告已删除']);
}, ['sensitive' => true]);

Plugin::asset('js', 'announcements/chat.js');
Plugin::asset('js', 'announcements/admin.js');
Plugin::asset('css', 'announcements/style.css');
