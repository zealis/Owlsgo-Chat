<?php
/**
 * 用户管理插件（v1.0.44 自后台剥离）
 *
 * 后台页面：Plugin::adminPage() 注册「用户管理」子页（插件管理分类下）。
 * API 路由：Plugin::route() 注册三条管理员操作，action 前缀 plugin_user_manager_。
 * 安全约束：所有路由第一步必须做管理员鉴权（插件路由不在 Admin::handle 内，
 *           不会自动获得管理员保护）；搜索仅支持数字用户 ID（昵称允许重名，见开发约束）。
 * 历史同步：改角色/称号时同步刷新 messages 快照（v1.0.39 语义，随功能迁移）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/** 管理员鉴权：插件路由的公共守卫 */
$umGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/* ---------- 后台页面（HTML 注入 #owAdminMain，交互函数见 assets/admin.js） ---------- */
Plugin::adminPage('user-manager', '用户管理', function () {
    return '<h2>用户管理</h2><p class="ow-admin-desc">搜索用户，管理身份与头衔。昵称允许重名，仅支持按用户 ID 精确查询。</p>'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">用户搜索</h3>'
        . '<div class="ow-form-row"><div class="ow-form-item" style="flex:1"><input class="ow-input" id="owAQ" placeholder="输入用户 ID（纯数字）"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwUM.search()">搜索用户</button></div></div>'
        . '<div id="owAResult"></div>';
});

/* ---------- 搜索：仅数字用户 ID 精确查询（原 admin_users） ---------- */
Plugin::route('plugin_user_manager_search', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $q = trim((string)($ctx['post']['q'] ?? ''));
    if ($q === '') Api::json(['ok' => true, 'data' => [], 'hint' => '请输入用户 ID']);
    if (!preg_match('/^\d{1,19}$/', $q)) Api::json(['ok' => false, 'msg' => '用户搜索仅支持数字用户 ID']);
    $rows = DB::all("SELECT id,nickname,email,role,title,points,status,created_at,last_login FROM users
        WHERE id=? LIMIT 1", [(int)$q]);
    Api::json(['ok' => true, 'data' => $rows]);
});

/* ---------- 保存：角色 / 称号 / 积分（原 admin_user_set） ---------- */
Plugin::route('plugin_user_manager_save', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $post = $ctx['post'];
    $actor = $ctx['actor'];
    $id = (int)($post['id'] ?? 0);
    if ($id <= 0) Api::json(['ok' => false, 'msg' => '非法用户']);
    $role = (string)($post['role'] ?? '');
    if (!in_array($role, ['member', 'vip', 'admin'], true)) Api::json(['ok' => false, 'msg' => '非法角色']);
    $title = (string)($post['title'] ?? '');
    DB::run('UPDATE users SET role=?, title=? WHERE id=?', [$role, $title, $id]);
    // 历史消息里的角色/称号为发送时快照，一并刷新（v1.0.39 语义随功能迁移）
    DB::run('UPDATE messages SET role=?, title=? WHERE user_id=?', [$role, $title, $id]);
    // 积分：允许单独调整（可为负数，但不接受非数字）
    if (isset($post['points']) && $post['points'] !== '') {
        $pts = (int)$post['points'];
        DB::run('UPDATE users SET points=? WHERE id=?', [$pts, $id]);
        Sec::log('admin_user_points', $actor['nickname'], ['id' => $id, 'points' => $pts]);
    }
    Sec::log('admin_user_set', $actor['nickname'], ['id' => $id, 'role' => $role]);
    Api::json(['ok' => true, 'msg' => '已更新']);
});

/* ---------- 禁用 / 启用（原 admin_user_status） ---------- */
Plugin::route('plugin_user_manager_status', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    DB::run('UPDATE users SET status=? WHERE id=?', [
        (int)($ctx['post']['status'] ?? 1) === 1 ? 1 : 0,
        (int)($ctx['post']['id'] ?? 0),
    ]);
    Api::json(['ok' => true, 'msg' => '已更新']);
});

// 注册后台交互脚本（由 ?action=assets&type=js 合并输出，仅后台页面引入）
Plugin::asset('js', 'user-manager/admin.js');
