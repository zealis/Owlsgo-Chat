/**
 * 敏感词过滤插件 - 后台交互（分页列表走通用轮子 OwAdmin.uiPager）
 */
(function (w, d) {
    'use strict';
    // 后台页 HTML 由 innerHTML 注入（script 不执行）：MutationObserver 检测表格出现后自动加载
    var mo = new MutationObserver(function () {
        var t = d.getElementById('owSWTable');
        if (t && t.rows.length === 0) w.OwSW.init();
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });

    w.OwSW = {
        _page: 1,

        init: function () { this._page = 1; this.list(1); },

        /** 分页加载词库 */
        list: function (page) {
            OwApi.post('plugin_sensitive_words_admin', { page: page || 1, size: 50 }, function (r) {
                var table = d.getElementById('owSWTable');
                var pager = d.getElementById('owSWPager');
                if (!table || !r.ok) { if (table) table.innerHTML = '<tr><td style="color:#5C5C5C">加载失败</td></tr>'; return; }
                var data = r.data, i;
                var h = '<tr><th>ID</th><th>敏感词</th><th>替换为</th><th>状态</th><th>操作</th></tr>';
                if (!data.list.length) h += '<tr><td colspan="5" style="color:#5C5C5C">词库为空</td></tr>';
                for (i = 0; i < data.list.length; i++) {
                    var wd = data.list[i];
                    h += '<tr><td>' + wd.id + '</td><td>' + esc(wd.word) + '</td><td>' + esc(wd.replacement) + '</td>'
                       + '<td>' + (wd.enabled == 1 ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">停用</span>') + '</td>'
                       + '<td><a href="javascript:;" onclick="OwSW.wordToggle(' + wd.id + ',' + (wd.enabled == 1 ? 0 : 1) + ')">' + (wd.enabled == 1 ? '停用' : '启用') + '</a> '
                       + '<a href="javascript:;" onclick="OwSW.wordDel(' + wd.id + ')">删除</a></td></tr>';
                }
                table.innerHTML = h;
                w.OwAdmin.uiPager(pager, data.page, data.total, data.size, function (pg) { w.OwSW.list(pg); });
            });
        },

        wordAdd: function () {
            OwApi.post('plugin_sensitive_words_add', { word: d.getElementById('owWWord').value, replacement: d.getElementById('owWRep').value }, function (r) {
                toast(r.msg);
                if (r.ok) w.OwSW.list(1);
            });
        },
        wordToggle: function (id, en) {
            OwApi.post('plugin_sensitive_words_toggle', { id: id, enabled: en }, function (r) { toast(r.msg); if (r.ok) w.OwSW.list(w.OwSW._page); });
        },
        wordDel: function (id) {
            w.OwAdmin.confirm('确定删除该敏感词？', function () {
                OwApi.secure('plugin_sensitive_words_del', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwSW.list(w.OwSW._page);
                });
            });
        }
    };
})(window, document);
