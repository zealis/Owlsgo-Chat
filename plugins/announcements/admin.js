/**
 * 群聊公告插件 - 后台交互（分页列表走通用轮子 OwAdmin.uiPager）
 */
(function (w, d) {
    'use strict';
    // 后台页 HTML 由 innerHTML 注入（script 不执行）：MutationObserver 检测表格出现后自动加载
    var mo = new MutationObserver(function () {
        var t = d.getElementById('oaAdmTable');
        if (t && t.rows.length === 0) w.OwOA.init();
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });

    w.OwOA = {
        /** 分页加载群公告列表 */
        list: function (page) {
            OwApi.post('plugin_announcements_admin', { page: page || 1, size: 20 }, function (r) {
                var table = d.getElementById('oaAdmTable');
                var pager = d.getElementById('oaAdmPager');
                if (!table || !r.ok) { if (table) table.innerHTML = '<tr><td style="color:#5C5C5C">加载失败</td></tr>'; return; }
                var data = r.data, i;
                var h = '<tr><th>ID</th><th>群聊ID</th><th>发布者</th><th>内容</th><th>类型</th><th>置顶</th><th>时间</th><th>操作</th></tr>';
                if (!data.list.length) h += '<tr><td colspan="8" style="color:#5C5C5C">暂无公告</td></tr>';
                for (i = 0; i < data.list.length; i++) {
                    var a = data.list[i];
                    h += '<tr><td>' + a.id + '</td><td>' + (a.room_id == 0 ? '全部' : a.room_id) + '</td>'
                        + '<td>' + esc(a.nickname) + '</td>'
                        + '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(a.content) + '</td>'
                        + '<td>' + (a.type === 'popup' ? '弹窗通知' : '公告条') + '</td>'
                        + '<td>' + (a.pinned == 1 ? '<span class="ow-tag ow-tag-green">置顶</span>' : '-') + '</td>'
                        + '<td>' + new Date(a.created_at * 1000).toLocaleString() + '</td>'
                        + '<td><a href="javascript:;" onclick="OwOA.adminDel(' + a.id + ',' + (a.room_id == 0 ? 0 : a.room_id) + ')">删除</a></td></tr>';
                }
                table.innerHTML = h;
                w.OwAdmin.uiPager(pager, data.page, data.total, data.size, function (pg) { w.OwOA.list(pg); });
            });
        },

        adminDel: function (id, roomId) {
            if (!w.confirm('确定删除该公告？删除后成员端立即不再展示。')) return;
            OwApi.secure('plugin_announcements_del', { id: id, room_id: roomId }, function (r) {
                toast(r.msg);
                if (r.ok) w.OwOA.list(w.OwOA._page || 1);
            });
        },

        _page: 1
    };
    // 首次进入后台页自动加载（page() 由 OwAdmin 路由渲染完成后调用）
    w.OwOA.init = function () { w.OwOA._page = 1; w.OwOA.list(1); };
})(window, document);
