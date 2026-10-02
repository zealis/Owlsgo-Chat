/**
 * 禁言插件 · 前台能力：群聊消息右键菜单「禁言」
 *
 * 可见范围（前端仅用于显示，真正的权限判定在服务端 plugin_ban_manager_quick）：
 *   - 管理员：任意房间的消息作者
 *   - 房主  ：仅本人拥有房间的消息作者，且不能禁言管理员
 *   - 自己  ：不显示（禁言自己无意义）
 *
 * 复用主程序弹窗样式（OwChat.openModal + ow-modal-desc / ow-modal-actions），
 * 与后台通用确认弹窗保持同一观感。
 */
(function (w, d) {
    // 非聊天页不注册（后台、登录注册页也会合并加载本脚本）；脚本在 OwChat.init 之后执行，cfg 已就绪
    if (!w.OwChat || !OwChat.cfg) return;
    // 幂等：脚本被重复引入时不再二次注册菜单项（否则右键会出现重复的「禁言 TA」）
    if (w.__owBanLoaded) return;
    w.__owBanLoaded = true;
    var pad   = w.fmtUid || function (n) { return String(n || ''); };
    var esc   = w.esc || function (s) { return String(s == null ? '' : s); };
    var toast = w.toast || function (m) { /* 无提示组件时静默 */ };

    /** 当前房间的房主用户 ID；取不到返回 0 */
    function roomOwnerId() {
        var cfg = OwChat.cfg || {}, rooms = cfg.rooms || [], i;
        for (i = 0; i < rooms.length; i++) {
            if (rooms[i].id === OwChat.room) return rooms[i].owner_id || 0;
        }
        return 0;
    }

    var BM = w.OwBM = w.OwBM || {};

    /** 当前身份对这条消息是否有禁言权限 */
    BM.canBan = function (m) {
        var cfg = OwChat.cfg || {}, actor = cfg.actor || {}, me = cfg.me || null;
        // 游客与未登录一律无禁言权限。cfg.me 仅在已登录时存在（游客为 null），
        // 这里显式判 kind，不依赖「me.id 为 undefined」这种顺带成立的副作用。
        if (!actor || actor.kind !== 'user' || !me) return false;
        if (!me.id) return false;
        if (!m || m.recalled) return false;                         // 系统消息等无明确作者
        if (!m.uid && !m.gid) return false;
        if (m.uid && m.uid === me.id) return false;                // 自己
        var isAdmin = actor.role === 'admin';
        var isOwner = roomOwnerId() && roomOwnerId() === me.id;
        if (!isAdmin && !isOwner) return false;                    // 仅管理员与房主
        if (!isAdmin && m.role === 'admin') return false;          // 房主不能禁言管理员
        return true;
    };

    /** 禁言弹窗：时长 + 原因，确认后调用插件路由 */
    BM.banDialog = function (m) {
        var who = m.uid
            ? '用户 ' + pad(m.uid) + '（' + esc(m.nickname) + '）'
            : '游客「' + esc(m.nickname) + '」';
        OwChat.openModal(
            '<h3>禁言 ' + esc(m.nickname) + '</h3>'
            + '<p class="ow-modal-desc">对象：' + who + '<br>范围：本群聊</p>'
            + '<div class="ow-form-item"><label>时长</label><select class="ow-input" id="owBMHours">'
            + '<option value="1">1 小时</option>'
            + '<option value="6">6 小时</option>'
            + '<option value="24" selected>24 小时</option>'
            + '<option value="168">7 天</option>'
            + '<option value="0">永久</option>'
            + '</select></div>'
            + '<div class="ow-form-item"><label>原因（可选）</label>'
            + '<input class="ow-input" id="owBMReason" maxlength="100" placeholder="将展示给被禁言用户"></div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-danger" id="owBMYes">确定禁言</button></div>'
        );
        var yes = d.getElementById('owBMYes');
        if (!yes) return;
        yes.onclick = function () {
            var hours = d.getElementById('owBMHours') ? d.getElementById('owBMHours').value : '24';
            var reason = d.getElementById('owBMReason') ? d.getElementById('owBMReason').value : '';
            yes.disabled = true;
            OwApi.secure('plugin_ban_manager_quick', {
                room_id: OwChat.room, uid: m.uid || 0, gid: m.gid || 0,
                nickname: m.nickname || '', hours: hours, reason: reason
            }, function (r) {
                yes.disabled = false;
                OwChat.closeModal();
                toast(r.msg || (r.ok ? '已禁言' : '操作失败'));
            });
        };
    };

    /* 注册到主程序右键菜单扩展点（v1.0.54 新增 OwChat.onMsgCtx） */
    if (typeof OwChat.onMsgCtx === 'function') {
        OwChat.onMsgCtx(function (items, m) {
            if (!m || m.recalled) return;
            if (!BM.canBan(m)) return;
            items.push({ t: '禁言 TA', run: function () { BM.banDialog(m); } });
        });
    }
})(window, document);
