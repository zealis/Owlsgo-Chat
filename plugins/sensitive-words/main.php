<?php
/**
 * 敏感词过滤插件（v1.0.104 自核心剥离）
 *
 * 机制：
 *   - 监听核心 text.filter 钩子（Chat::filterText 在各文字输入点触发：
 *     message / nickname / room_name / room_desc / announcement 等），
 *     命中词库即替换为配置的替换词（默认 ***）。
 *   - 词库存 sensitive_words 表（沿用剥离前的表与数据，无需迁移）。
 *   - 后台「敏感词过滤」管理页：添加 / 启用停用 / 删除。
 */
if (!defined('HALOU_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/** 词库（static 缓存，进程内只查一次表） */
$GLOBALS['sw_words'] = function (): array {
    static $words = null;
    if ($words === null) $words = DB::all('SELECT word, replacement FROM sensitive_words WHERE enabled=1');
    return $words;
};

/** 核心 text.filter 钩子：对所有输入文字生效 */
Plugin::on('text.filter', function (&$text, $scene, $actor) {
    if (!is_string($text) || $text === '') return;
    foreach (($GLOBALS['sw_words'])() as $w) {
        if ($w['word'] !== '') {
            $text = mb_ereg_replace(preg_quote($w['word'], '/'), $w['replacement'], $text);
        }
    }
});

/* ---------- 后台管理页（v1.0.110 对齐通用列表样式：多选框 + 批量删除 + 分页轮子） ---------- */
Plugin::adminPage('sensitive-words', '敏感词过滤', function () {
    $rows = DB::all('SELECT * FROM sensitive_words ORDER BY id DESC LIMIT 200');
    $h = '<h2>敏感词过滤</h2><p class="ha-admin-desc">添加敏感词及替换词，支持启用 / 停用。对所有输入文字生效（发言、昵称、群名称、群简介、群公告等）。</p>'
        . '<div class="ha-card"><div class="ha-form-row">'
        . '<div class="ha-form-item"><label>敏感词</label><input class="ha-input" id="haWWord"></div>'
        . '<div class="ha-form-item"><label>替换为</label><input class="ha-input" id="haWRep" value="***"></div>'
        . '<button class="ha-btn ha-btn-primary" onclick="HaSW.wordAdd()">添加</button></div></div>'
        . '<div class="ha-card">'
        . '<div class="ha-admin-batch">'
        . '<button class="ha-btn ha-btn-danger" id="haSWBatchDel" onclick="HaSW.batchDelete()" disabled>批量删除</button>'
        . '<span id="haSWStat" style="color:var(--ha-text-sub);font-size:12px"></span>'
        . '</div>'
        . '<div class="ha-table-wrap"><table class="ha-table" id="haSWTable"></table></div>'
        . '<div id="haSWPager"></div></div>';
    return $h;
});

/** 后台分页数据（接入通用列表轮子） */
Plugin::route('plugin_sensitive_words_admin', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $page = max(1, (int)($ctx['post']['page'] ?? 1));
    $size = min(100, max(1, (int)($ctx['post']['size'] ?? 30)));
    $total = (int)DB::val('SELECT COUNT(*) FROM sensitive_words');
    $rows = DB::all('SELECT * FROM sensitive_words ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size));
    Api::json(['ok' => true, 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]]);
});

/** 批量删除（敏感操作） */
Plugin::route('plugin_sensitive_words_batch', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $ids = array_filter(array_map('intval', explode(',', (string)($ctx['post']['ids'] ?? ''))));
    if (!$ids) Api::json(['ok' => false, 'msg' => '未选择敏感词']);
    $ok = 0;
    foreach ($ids as $id) { DB::run('DELETE FROM sensitive_words WHERE id=?', [$id]); $ok++; }
    Sec::log('sensitive_word_batch_del', $ctx['actor']['nickname'], ['count' => $ok]);
    Api::json(['ok' => $ok > 0, 'msg' => '批量删除：成功 ' . $ok . ' 条']);
}, ['sensitive' => true]);

/* ---------- 添加 ---------- */
Plugin::route('plugin_sensitive_words_add', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $word = trim((string)($ctx['post']['word'] ?? ''));
    $rep = trim((string)($ctx['post']['replacement'] ?? ''));
    if ($word === '') Api::json(['ok' => false, 'msg' => '敏感词不能为空']);
    if ($rep === '') $rep = '***';
    if (DB::one('SELECT id FROM sensitive_words WHERE word=?', [$word])) Api::json(['ok' => false, 'msg' => '该敏感词已存在']);
    DB::insert('sensitive_words', ['word' => $word, 'replacement' => $rep, 'enabled' => 1]);
    Sec::log('sensitive_word_add', $ctx['actor']['nickname'], ['word' => $word]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});

/* ---------- 启用 / 停用 ---------- */
Plugin::route('plugin_sensitive_words_toggle', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    DB::run('UPDATE sensitive_words SET enabled=? WHERE id=?', [(int)($ctx['post']['enabled'] ?? 0), (int)($ctx['post']['id'] ?? 0)]);
    Api::json(['ok' => true, 'msg' => '已更新']);
});

/* ---------- 删除（敏感操作） ---------- */
Plugin::route('plugin_sensitive_words_del', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    DB::run('DELETE FROM sensitive_words WHERE id=?', [(int)($ctx['post']['id'] ?? 0)]);
    Sec::log('sensitive_word_del', $ctx['actor']['nickname'], ['id' => (int)($ctx['post']['id'] ?? 0)]);
    Api::json(['ok' => true, 'msg' => '已删除']);
}, ['sensitive' => true]);

Plugin::asset('js', 'sensitive-words/admin.js');
