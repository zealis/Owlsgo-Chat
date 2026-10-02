/**
 * 私聊合规查阅 · 后台页面交互
 *
 * 复用主程序的后台列表与提示组件（OwAdmin / toast / esc），
 * 视觉与观感随主程序，不另起一套。
 */
(function (w, d) {
    if (!w.OwAdmin) return;
    var esc   = w.esc || function (s) { return String(s == null ? '' : s); };
    var toast = w.toast || function (m) { /* 无提示组件时静默 */ };

    /** 把一组私聊记录按「对方」分组渲染，便于还原对话上下文 */
    function groupByPeer(list) {
        var map = {}, order = [], i, k;
        for (i = 0; i < list.length; i++) {
            k = (list[i].peer_uid > 0 ? 'u' : 'g') + ':' + (list[i].peer_uid || 0);
            if (!map[k]) { map[k] = []; order.push(k); }
            map[k].push(list[i]);
        }
        return order.map(function (key) { return { key: key, list: map[key] }; });
    }

    OwAdmin.dmComplianceSearch = function () {
        var uid = d.getElementById('owDmTarget').value;
        if (!uid || parseInt(uid, 10) <= 0) { toast('请填写有效的目标用户 ID'); return; }
        var btn = null;
        var result = d.getElementById('owDmResult');
        result.innerHTML = '<div class="ow-card" style="color:#5C5C5C">正在查阅…</div>';

        OwApi.secure('plugin_dm_compliance_read', {
            uid: uid,
            from: d.getElementById('owDmFrom').value || '',
            to: d.getElementById('owDmTo').value || '',
            limit: d.getElementById('owDmLimit').value || 200
        }, function (r) {
            if (!r.ok) {
                result.innerHTML = '<div class="ow-card" style="color:#C41D1F">' + esc(r.msg || '查阅失败') + '</div>';
                toast(r.msg || '查阅失败');
                return;
            }
            var list = r.data || [];
            if (!list.length) {
                result.innerHTML = '<div class="ow-card" style="color:#5C5C5C">'
                    + '该用户在此条件下没有私聊记录。<br>'
                    + '<span style="font-size:12px">本次查询已记入安全日志（合规答复需举证「查过但没有」）。</span></div>';
                return;
            }
            var groups = groupByPeer(list);
            var h = '<div class="ow-card">'
                  + '<p style="margin:0 0 10px;color:#C41D1F;font-size:13px">'
                  + '⚠ 本次查阅已记入安全日志，不可删除。</p>'
                  + '<p style="margin:0 0 12px">共命中 <b>' + list.length + '</b> 条，涉及 <b>' + groups.length + '</b> 个对话对象。</p>';
            for (var g = 0; g < groups.length; g++) {
                var items = groups[g].list;
                var other = items[0];
                h += '<div style="border-top:1px solid var(--ow-border);padding-top:10px;margin-top:10px">'
                   + '<div style="font-weight:600;margin-bottom:6px">与 '
                   + esc(other.peer_nick || ('对象 ' + other.peer_uid))
                   + ' 的对话（' + items.length + ' 条）</div>'
                   + '<table class="ow-table"><tr><th style="width:150px">时间</th>'
                   + '<th style="width:110px">发送方</th><th>内容</th><th style="width:130px">IP</th></tr>';
                for (var i = 0; i < items.length; i++) {
                    var m = items[i];
                    var body;
                    if (m.deleted) body = '<span style="color:#5C5C5C">[该消息已删除]</span>';
                    else if (m.recalled) body = '<span style="color:#5C5C5C">[已撤回]</span>';
                    else body = esc(m.content).replace(/\n/g, '<br>');
                    h += '<tr><td style="white-space:nowrap">' + esc(m.time) + '</td>'
                       + '<td>' + esc(m.from_nick) + (m.from_user ? '（' + m.from_user + '）' : '（游客）') + '</td>'
                       + '<td>' + body + '</td>'
                       + '<td style="font-size:12px;color:#5C5C5C">' + esc(m.ip || '-') + '</td></tr>';
                }
                h += '</table></div>';
            }
            h += '</div>';
            result.innerHTML = h;
        });
    };

    OwAdmin.dmComplianceReset = function () {
        ['owDmTarget', 'owDmFrom', 'owDmTo', 'owDmLimit'].forEach(function (id) {
            var el = d.getElementById(id);
            if (el) el.value = (id === 'owDmLimit') ? '200' : '';
        });
        d.getElementById('owDmResult').innerHTML = '';
    };
})(window, document);
