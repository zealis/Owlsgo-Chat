/**
 * 群聊公告插件 - 后台交互（v1.0.108 模仿附件列表：群聊ID搜索 + 多选批量 + 通用分页）
 * 全局对象 OwOA；依赖 OwApi / esc / toast / OwAdmin.uiPager / OwApi.secure（敏感操作）
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    /* 当前查询状态（v1.1.2：room_id 必 > 0，0 = 不按群过滤 = 全部） */
    var state = { room_id: 0, page: 1, size: 30, total: 0 };

    w.OwOA = {
        init: function () { OwOA.load(1); },

        resetFilter: function () {
            $('oaAdmRoom').value = '';
            OwOA.load(1);
        },

        /** 加载列表（带群聊ID过滤） */
        load: function (page) {
            state.room_id = parseInt($('oaAdmRoom').value, 10) || 0;
            state.page = page || 1;
            OwApi.post('plugin_announcements_admin', {
                room_id: state.room_id, page: state.page, size: state.size
            }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                state.total = r.data.total;
                OwOA.render(r.data.list);
                w.OwAdmin.uiPager($('oaAdmPager'), state.page, state.total, state.size, function (pg) { OwOA.load(pg); });
                $('oaAdmStat').textContent = '共 ' + state.total + ' 条公告';
            });
        },

        render: function (rows) {
            var tbl = $('oaAdmTable');
            var h = '<tr><th style="width:32px"><input type="checkbox" id="oaAdmCheckAll" onchange="OwOA.toggleAll(this)"></th>'
                + '<th>ID</th><th>群聊ID</th><th>发布者</th><th>内容</th><th>类型</th><th>置顶</th><th>时间</th><th>操作</th></tr>';
            for (var i = 0; i < rows.length; i++) {
                var a = rows[i];
                h += '<tr><td><input type="checkbox" class="oaAdmChk" value="' + a.id + '" onchange="OwOA.syncBatch()"></td>'
                    + '<td>' + a.id + '</td><td>' + esc(String(a.room_id)) + '</td>'
                    + '<td>' + esc(a.nickname) + '</td>'
                    + '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(a.content) + '</td>'
                    + '<td>' + (a.type === 'popup' ? '弹窗通知' : '公告条') + '</td>'
                    + '<td>' + (a.pinned == 1 ? '<span class="ow-tag ow-tag-green">置顶</span>' : '-') + '</td>'
                    + '<td>' + new Date(a.created_at * 1000).toLocaleString() + '</td>'
                    // v1.1.2：room_id=0 语义已删，不再有「全部」分支；删除改由服务端按公告自身归属判权限
                    + '<td><a href="javascript:;" onclick="OwOA.delOne(' + a.id + ')">删除</a></td></tr>';
            }
            if (!rows.length) h += '<tr><td colspan="9" style="color:#5C5C5C">暂无公告</td></tr>';
            tbl.innerHTML = h;
            var all = $('oaAdmCheckAll');
            if (all) all.checked = false;
            OwOA.syncBatch();
        },

        toggleAll: function (cb) {
            var boxes = d.getElementsByClassName('oaAdmChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            OwOA.syncBatch();
        },

        syncBatch: function () {
            var boxes = d.getElementsByClassName('oaAdmChk'), n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            var btn = $('oaAdmBatchDel');
            if (btn) { btn.disabled = n === 0; btn.innerHTML = n > 0 ? '批量删除（' + n + '）' : '批量删除'; }
        },

        batchDelete: function () {
            var boxes = d.getElementsByClassName('oaAdmChk'), ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(boxes[i].value);
            if (!ids.length) { toast('请先选择要删除的公告'); return; }
            w.OwAdmin.confirm('确认删除选中的 ' + ids.length + ' 条公告？删除后成员端立即不再展示。', function () {
                OwApi.secure('plugin_announcements_batch', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) OwOA.load(state.page);
                });
            });
        },

        delOne: function (id) {
            w.OwAdmin.confirm('确定删除该公告？删除后成员端立即不再展示。', function () {
                OwApi.secure('plugin_announcements_del', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) OwOA.load(state.page);
                });
            });
        }
    };

    /* 后台页 HTML 由 innerHTML 注入（script 不执行）：MutationObserver 检测搜索框出现后自动加载 */
    var mo = new MutationObserver(function () {
        var inp = $('oaAdmRoom');
        if (inp && inp.getAttribute('data-oa-inited') !== '1') {
            inp.setAttribute('data-oa-inited', '1');
            OwOA.load(1);
        }
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });
})(window, document);
