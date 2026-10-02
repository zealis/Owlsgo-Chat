/**
 * 群公告插件 - 前端交互
 * 依赖主程序：OwApi / esc / toast / OwChat.onRoomSwitch / OwChat.onRoomEdit /
 *             OwChat.openModal / OwChat.closeModal / OwApi.secure（敏感操作票据）
 */
(function (w, d) {
    'use strict';

    var current = { roomId: 0, roomName: '', list: [], isAdmin: false, isOwner: false };

    function fmtTime(ts) {
        var dt = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return dt.getFullYear() + '/' + p(dt.getMonth() + 1) + '/' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    }

    /** 公告条：只显示一条 bar 类型（置顶优先，其次最新），点击进入群公告页面 */
    function renderBar() {
        var main = d.querySelector('.ow-main');
        if (!main) return;
        var bar = d.getElementById('oaBar');
        var top = null;
        for (var i = 0; i < current.list.length; i++) {
            if (current.list[i].type !== 'bar') continue;   // 弹窗通知不占公告条
            if (!top || (current.list[i].pinned === 1 && top.pinned !== 1)) top = current.list[i];
        }
        if (!top) { if (bar) bar.parentNode.removeChild(bar); return; }
        if (!bar) {
            bar = d.createElement('div');
            bar.id = 'oaBar';
            bar.className = 'oa-bar';
            main.insertBefore(bar, d.getElementById('owMessages'));
        }
        bar.innerHTML = '<span class="oa-bar-pin' + (top.pinned === 1 ? ' is-pin' : '') + '">' + (top.pinned === 1 ? '置顶' : '公告') + '</span>'
            + '<span class="oa-bar-text">' + esc(top.content) + '</span>'
            + '<span class="oa-bar-more" title="群公告">›</span>';
        bar.onclick = function () { showPage(); };
    }

    /** 拉取当前群公告并渲染公告条 */
    function load() {
        if (!current.roomId) return;
        OwApi.post('plugin_announcements_list', { room_id: current.roomId }, function (r) {
            current.list = r.ok ? (r.data || []) : [];
            renderBar();
            showPopupOnce();
        });
    }

    /** popup 类型：进群时弹窗通知一次（按 公告id 记录已读） */
    function showPopupOnce() {
        for (var i = current.list.length - 1; i >= 0; i--) {
            var a = current.list[i];
            if (a.type !== 'popup') continue;
            var key = 'oa_read_' + current.roomId + '_' + a.id;
            try { if (w.localStorage.getItem(key)) continue; } catch (e) { return; }
            w.localStorage.setItem(key, '1');
            w.OwChat.openModal(
                '<h3>群公告</h3>'
                + '<div class="oa-popup-meta"><b>' + esc(a.nickname) + '</b> ' + fmtTime(a.created_at)
                + (a.pinned === 1 ? ' <span class="oa-pin">置顶</span>' : '') + '</div>'
                + '<div class="oa-popup-body">' + esc(a.content) + '</div>'
                + '<div class="ow-modal-actions"><button class="ow-btn ow-btn-primary" onclick="OwChat.closeModal()">我知道了</button></div>'
            );
            return;
        }
    }

    /** 群公告页面（大弹窗）：群名称 + 全部公告卡片 */
    function showPage() {
        var cards = '', i, a;
        if (!current.list.length) cards = '<div class="oa-empty">本群还没有公告</div>';
        for (i = 0; i < current.list.length; i++) {
            a = current.list[i];
            cards += '<div class="oa-card">'
                + '<div class="oa-card-meta"><b>' + esc(a.nickname) + '</b><span class="oa-card-time">' + fmtTime(a.created_at) + '</span>'
                + (a.pinned === 1 ? '<span class="oa-pin">置顶</span>' : '')
                + (current.isOwner || current.isAdmin ? '<a class="oa-card-del" href="javascript:;" data-id="' + a.id + '">删除</a>' : '')
                + '</div>'
                + '<div class="oa-card-body"><div class="oa-card-text">' + esc(a.content) + '</div>'
                + '<a class="oa-card-toggle" href="javascript:;">展开 ∨</a></div>'
                + '</div>';
        }
        var manage = (current.isOwner || current.isAdmin)
            ? '<button class="ow-btn ow-btn-primary ow-btn-block" id="oaAddBtn">发布公告</button>' : '';
        w.OwChat.openModal(
            '<div class="oa-page"><div class="oa-page-title">' + esc(current.roomName) + '</div>'
            + '<div class="oa-page-sub">群公告</div>'
            + '<div class="oa-list">' + cards + '</div>' + manage + '</div>',
            560
        );
        // 卡片展开 / 收起（内容过长时折叠）
        var cards2 = d.querySelectorAll('#owModal .oa-card');
        for (var j = 0; j < cards2.length; j++) {
            (function (card) {
                var body = card.querySelector('.oa-card-text');
                var tog = card.querySelector('.oa-card-toggle');
                if (!body || !tog) return;
                if (body.scrollHeight <= 96) { tog.style.display = 'none'; return; }
                tog.onclick = function () {
                    var open = body.className.indexOf('open') >= 0;
                    body.className = open ? 'oa-card-text' : 'oa-card-text open';
                    tog.textContent = open ? '展开 ∨' : '收起 ∧';
                };
            })(cards2[j]);
        }
        // 删除（敏感操作，自研确认弹窗）
        var dels = d.querySelectorAll('#owModal .oa-card-del');
        for (var k = 0; k < dels.length; k++) {
            (function (el) {
                el.onclick = function () {
                    w.OwChat.confirm('确定删除该公告？删除后成员端立即不再展示。', function () {
                        OwApi.secure('plugin_announcements_del', { room_id: current.roomId, id: el.getAttribute('data-id') }, function (r) {
                            toast(r.msg);
                            if (r.ok) { w.OwChat.closeModal(); load(); }
                        });
                    });
                };
            })(dels[k]);
        }
        // 发布入口
        var addBtn = d.getElementById('oaAddBtn');
        if (addBtn) addBtn.onclick = function () { showPublish(); };
    }

    /** 发布 / 管理弹窗（群主）：内容、类型、置顶 */
    function showPublish() {
        w.OwChat.openModal(
            '<h3>发布群公告</h3>'
            + '<div class="ow-form-item"><label>公告内容</label>'
            + '<textarea class="ow-input" id="oaContent" rows="4" maxlength="600" placeholder="最多 600 字"></textarea></div>'
            + '<div class="ow-form-item"><label>展示类型</label>'
            + '<select class="ow-input" id="oaType"><option value="bar">聊天室上方公告条</option><option value="popup">进群弹窗通知</option></select></div>'
            + '<div class="ow-form-item"><label class="oa-check"><input type="checkbox" id="oaPinned"> 置顶该公告（在公告条与列表优先展示）</label></div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-primary" id="oaPubBtn">发布</button></div>'
        );
        d.getElementById('oaPubBtn').onclick = function () {
            var content = d.getElementById('oaContent').value.replace(/^\s+|\s+$/g, '');
            if (!content) { toast('公告内容不能为空'); return; }
            OwApi.secure('plugin_announcements_add', {
                room_id: current.roomId, content: content,
                type: d.getElementById('oaType').value,
                pinned: d.getElementById('oaPinned').checked ? 1 : 0
            }, function (r) {
                toast(r.msg);
                if (r.ok) { w.OwChat.closeModal(); load(); }
            });
        };
    }

    /**
     * 右侧栏「群聊设置」区里的「群公告」入口（群主 / 超级管理员可见）。
     * v1.1.1：入口从列表三点弹窗迁到常驻侧栏，#owREExtras 的位置与钩子契约不变。
     */
    w.OwChat.onRoomEdit(function (ctx) {
        current.roomId = ctx.roomId;
        current.isAdmin = !!ctx.isAdmin;
        current.isOwner = !!ctx.isOwner;
        var box = d.getElementById('owREExtras');
        if (!box || !(ctx.isOwner || ctx.isAdmin)) return;
        box.innerHTML = '<button class="ow-btn ow-btn-ghost ow-btn-block" id="oaManageBtn">群公告</button>';
        d.getElementById('oaManageBtn').onclick = function () {
            OwApi.post('plugin_announcements_list', { room_id: ctx.roomId }, function (r) {
                current.list = r.ok ? (r.data || []) : [];
                var rooms = (w.OwChat.cfg.rooms || []);
                for (var i = 0; i < rooms.length; i++) if (rooms[i].id === ctx.roomId) current.roomName = rooms[i].name;
                setTimeout(showPage, 60);   // 公告页仍是弹窗；侧栏本身不需要关
            });
        };
    });

    // 切换群聊：更新上下文并刷新公告条
    w.OwChat.onRoomSwitch(function (ctx) {
        current.roomId = ctx.roomId;
        current.isAdmin = !!ctx.isAdmin;
        current.isOwner = ctx.ownerId === ((w.OwChat.cfg.me || {}).id || 0);
        var rooms = (w.OwChat.cfg.rooms || []);
        for (var i = 0; i < rooms.length; i++) if (rooms[i].id === ctx.roomId) current.roomName = rooms[i].name;
        load();
    });

    // 视图切换（v1.1.0）：私聊是 room_id=0 的虚拟空间，语义上不存在「群公告」。
    // 群聊装饰只属于群聊视图 —— 进入私聊时移除公告条，切回群聊时由 onRoomSwitch 复原。
    w.OwChat.onViewChange(function (ctx) {
        if (ctx.view !== 'dm') return;      // 群聊视图：onRoomSwitch 已经负责刷新
        current.roomId = 0;
        current.list = [];
        renderBar();                        // list 为空时 renderBar 会自行移除 #oaBar
    });
})(window, document);
