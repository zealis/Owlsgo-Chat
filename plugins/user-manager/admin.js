/**
 * 用户管理插件 - 后台交互（v1.0.44 自后台剥离）
 * 全局对象 OwUM：页面 HTML 由插件后台页注入，函数在点击时执行，
 * 依赖的 OwApi / toast / esc / fmtUid / opts 由主 chat.js 提供。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    w.OwUM = {
        /* 搜索：仅数字用户 ID 精确查询；非法输入服务端返回 {ok:false} 需拦截提示 */
        search: function () {
            OwApi.post('plugin_user_manager_search', { q: $('owAQ').value }, function (r) {
                if (!r.ok) { toast(r.msg); $('owAResult').innerHTML = ''; return; }
                var h = '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>昵称</th><th>邮箱</th><th>角色</th><th>称号</th><th>积分</th><th>状态</th><th>操作</th></tr>';
                for (var i = 0; i < r.data.length; i++) {
                    var u = r.data[i];
                    h += '<tr><td>' + esc(fmtUid(u.id)) + '</td><td>' + esc(u.nickname) + '</td><td>' + esc(u.email) + '</td>'
                       + '<td><select class="ow-input" id="owUR' + u.id + '">'
                       + opts(ROLE_CN, ['member', 'vip', 'admin'], u.role)
                       + '</select></td>'
                       + '<td><input class="ow-input" id="owUT' + u.id + '" value="' + esc(u.title || '') + '"></td>'
                       + '<td><input class="ow-input" id="owUP' + u.id + '" value="' + esc(u.points || 0) + '" style="width:88px"></td>'
                       + '<td>' + (u.status == 1 ? '正常' : '禁用') + '</td>'
                       + '<td><a href="javascript:;" onclick="OwUM.save(' + u.id + ')">保存</a> '
                       + '<a href="javascript:;" onclick="OwUM.status(' + u.id + ',' + (u.status == 1 ? 0 : 1) + ')">' + (u.status == 1 ? '禁用' : '启用') + '</a></td></tr>';
                }
                if (!r.data.length) {
                    if (r.hint) toast(r.hint);
                    h += '<tr><td colspan="8" style="color:#999">无匹配用户</td></tr>';
                }
                $('owAResult').innerHTML = h + '</table></div>';
            });
        },

        /* 保存角色 / 称号 / 积分 */
        save: function (id) {
            OwApi.post('plugin_user_manager_save', {
                id: id, role: $('owUR' + id).value,
                title: $('owUT' + id).value, points: $('owUP' + id).value
            }, function (r) { toast(r.msg); });
        },

        /* 禁用 / 启用后重查刷新列表 */
        status: function (id, s) {
            OwApi.post('plugin_user_manager_status', { id: id, status: s }, function (r) { toast(r.msg); w.OwUM.search(); });
        }
    };
})(window, document);
