/**
 * 群公告插件 - 后台交互（OwOA：后台管理页的删除等）
 */
(function (w, d) {
    'use strict';
    w.OwOA = {
        adminDel: function (id, roomId) {
            if (!w.confirm('确定删除该公告？删除后成员端立即不再展示。')) return;
            OwApi.secure('plugin_announcements_del', { id: id, room_id: roomId }, function (r) {
                toast(r.msg);
                if (r.ok) w.OwAdmin.page('plugin:announcements');
            });
        }
    };
})(window, document);
