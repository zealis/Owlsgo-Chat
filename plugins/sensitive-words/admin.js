/**
 * 敏感词过滤插件 - 后台交互（v1.0.110 对齐通用列表样式：多选框 + 批量删除 + 分页轮子）
 * 全局对象 HaSW；依赖 HaApi / esc / toast / HaAdmin.uiPager / HaApi.secure（敏感操作）
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    var state = { page: 1, size: 30, total: 0 };

    w.HaSW = {
        init: function () { this.list(1); },

        /** 分页加载词库 */
        list: function (page) {
            HaApi.post('plugin_sensitive_words_admin', { page: page || 1, size: state.size }, function (r) {
                var table = $('haSWTable');
                var pager = $('haSWPager');
                if (!table || !r.ok) { if (table) table.innerHTML = '<tr><td style="color:#5C5C5C">加载失败</td></tr>'; return; }
                state.total = r.data.total;
                state.page = r.data.page;
                HaSW.render(r.data.list);
                w.HaAdmin.uiPager(pager, state.page, state.total, state.size, function (pg) { w.HaSW.list(pg); });
                $('haSWStat').textContent = '共 ' + state.total + ' 条敏感词';
            });
        },

        render: function (rows) {
            var table = $('haSWTable');
            var h = '<tr><th style="width:32px"><input type="checkbox" id="haSWCheckAll" onchange="HaSW.toggleAll(this)"></th>'
                + '<th>ID</th><th>敏感词</th><th>替换为</th><th>状态</th><th>操作</th></tr>';
            for (var i = 0; i < rows.length; i++) {
                var wd = rows[i];
                h += '<tr><td><input type="checkbox" class="haSWChk" value="' + wd.id + '" onchange="HaSW.syncBatch()"></td>'
                    + '<td>' + wd.id + '</td><td>' + esc(wd.word) + '</td><td>' + esc(wd.replacement) + '</td>'
                    + '<td>' + (wd.enabled == 1 ? '<span class="ha-tag ha-tag-green">启用</span>' : '<span class="ha-tag ha-tag-guest">停用</span>') + '</td>'
                    + '<td><a href="javascript:;" onclick="HaSW.wordToggle(' + wd.id + ',' + (wd.enabled == 1 ? 0 : 1) + ')">' + (wd.enabled == 1 ? '停用' : '启用') + '</a> '
                    + '<a href="javascript:;" onclick="HaSW.wordDel(' + wd.id + ')">删除</a></td></tr>';
            }
            if (!rows.length) h += '<tr><td colspan="6" style="color:#5C5C5C">词库为空</td></tr>';
            table.innerHTML = h;
            var all = $('haSWCheckAll');
            if (all) all.checked = false;
            HaSW.syncBatch();
        },

        toggleAll: function (cb) {
            var boxes = d.getElementsByClassName('haSWChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            HaSW.syncBatch();
        },

        syncBatch: function () {
            var boxes = d.getElementsByClassName('haSWChk'), n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            var btn = $('haSWBatchDel');
            if (btn) { btn.disabled = n === 0; btn.innerHTML = n > 0 ? '批量删除（' + n + '）' : '批量删除'; }
        },

        batchDelete: function () {
            var boxes = d.getElementsByClassName('haSWChk'), ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(boxes[i].value);
            if (!ids.length) { toast('请先选择要删除的敏感词'); return; }
            w.HaAdmin.confirm('确认删除选中的 ' + ids.length + ' 条敏感词？', function () {
                HaApi.secure('plugin_sensitive_words_batch', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.HaSW.list(state.page);
                });
            });
        },

        wordAdd: function () {
            HaApi.post('plugin_sensitive_words_add', { word: $('haWWord').value, replacement: $('haWRep').value }, function (r) {
                toast(r.msg);
                if (r.ok) w.HaSW.list(1);
            });
        },
        wordToggle: function (id, en) {
            HaApi.post('plugin_sensitive_words_toggle', { id: id, enabled: en }, function (r) { toast(r.msg); if (r.ok) w.HaSW.list(state.page); });
        },
        wordDel: function (id) {
            w.HaAdmin.confirm('确定删除该敏感词？', function () {
                HaApi.secure('plugin_sensitive_words_del', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.HaSW.list(state.page);
                });
            });
        }
    };

    /* 后台页 HTML 由 innerHTML 注入（script 不执行）：MutationObserver 检测表格出现后自动加载 */
    var mo = new MutationObserver(function () {
        var t = $('haSWTable');
        if (t && t.rows.length === 0) w.HaSW.init();
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });
})(window, document);
