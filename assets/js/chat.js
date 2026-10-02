/* ==========================================================================
   Owlsgo-Chat 前端（纯原生 ES5，无框架无依赖，兼容旧内核浏览器）
   包含：OwAuth（登录/注册/找回）、OwChat（聊天主程序，长轮询）、OwAdmin（管理后台）
   ========================================================================== */
(function (w) {
    'use strict';

    /* ---------- 纯 JS MD5（标准实现，用于 API 签名 sign = md5(key|ts|action)） ----------
       注意：MD5 的 64 个 T 常量必须逐一写死，不能用公式推导，否则与服务端 md5 不一致。 */
    function md5(s) {
        function safeAdd(x, y) {
            var lsw = (x & 0xffff) + (y & 0xffff);
            var msw = (x >> 16) + (y >> 16) + (lsw >> 16);
            return (msw << 16) | (lsw & 0xffff);
        }
        function rol(num, cnt) { return (num << cnt) | (num >>> (32 - cnt)); }
        function cmn(q, a, b, x, s, t) { return safeAdd(rol(safeAdd(safeAdd(a, q), safeAdd(x, t)), s), b); }
        function ff(a, b, c, d, x, s, t) { return cmn((b & c) | (~b & d), a, b, x, s, t); }
        function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & ~d), a, b, x, s, t); }
        function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t); }
        function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | ~d), a, b, x, s, t); }
        function cycle(x, k) {
            var a = x[0], b = x[1], c = x[2], d = x[3];
            a = ff(a, b, c, d, k[0], 7, -680876936);    d = ff(d, a, b, c, k[1], 12, -389564586);
            c = ff(c, d, a, b, k[2], 17, 606105819);    b = ff(b, c, d, a, k[3], 22, -1044525330);
            a = ff(a, b, c, d, k[4], 7, -176418897);    d = ff(d, a, b, c, k[5], 12, 1200080426);
            c = ff(c, d, a, b, k[6], 17, -1473231341);  b = ff(b, c, d, a, k[7], 22, -45705983);
            a = ff(a, b, c, d, k[8], 7, 1770035416);    d = ff(d, a, b, c, k[9], 12, -1958414417);
            c = ff(c, d, a, b, k[10], 17, -42063);      b = ff(b, c, d, a, k[11], 22, -1990404162);
            a = ff(a, b, c, d, k[12], 7, 1804603682);   d = ff(d, a, b, c, k[13], 12, -40341101);
            c = ff(c, d, a, b, k[14], 17, -1502002290); b = ff(b, c, d, a, k[15], 22, 1236535329);

            a = gg(a, b, c, d, k[1], 5, -165796510);    d = gg(d, a, b, c, k[6], 9, -1069501632);
            c = gg(c, d, a, b, k[11], 14, 643717713);   b = gg(b, c, d, a, k[0], 20, -373897302);
            a = gg(a, b, c, d, k[5], 5, -701558691);    d = gg(d, a, b, c, k[10], 9, 38016083);
            c = gg(c, d, a, b, k[15], 14, -660478335);  b = gg(b, c, d, a, k[4], 20, -405537848);
            a = gg(a, b, c, d, k[9], 5, 568446438);     d = gg(d, a, b, c, k[14], 9, -1019803690);
            c = gg(c, d, a, b, k[3], 14, -187363961);   b = gg(b, c, d, a, k[8], 20, 1163531501);
            a = gg(a, b, c, d, k[13], 5, -1444681467);  d = gg(d, a, b, c, k[2], 9, -51403784);
            c = gg(c, d, a, b, k[7], 14, 1735328473);   b = gg(b, c, d, a, k[12], 20, -1926607734);

            a = hh(a, b, c, d, k[5], 4, -378558);       d = hh(d, a, b, c, k[8], 11, -2022574463);
            c = hh(c, d, a, b, k[11], 16, 1839030562);  b = hh(b, c, d, a, k[14], 23, -35309556);
            a = hh(a, b, c, d, k[1], 4, -1530992060);   d = hh(d, a, b, c, k[4], 11, 1272893353);
            c = hh(c, d, a, b, k[7], 16, -155497632);   b = hh(b, c, d, a, k[10], 23, -1094730640);
            a = hh(a, b, c, d, k[13], 4, 681279174);    d = hh(d, a, b, c, k[0], 11, -358537222);
            c = hh(c, d, a, b, k[3], 16, -722521979);   b = hh(b, c, d, a, k[6], 23, 76029189);
            a = hh(a, b, c, d, k[9], 4, -640364487);    d = hh(d, a, b, c, k[12], 11, -421815835);
            c = hh(c, d, a, b, k[15], 16, 530742520);   b = hh(b, c, d, a, k[2], 23, -995338651);

            a = ii(a, b, c, d, k[0], 6, -198630844);    d = ii(d, a, b, c, k[7], 10, 1126891415);
            c = ii(c, d, a, b, k[14], 15, -1416354905); b = ii(b, c, d, a, k[5], 21, -57434055);
            a = ii(a, b, c, d, k[12], 6, 1700485571);   d = ii(d, a, b, c, k[3], 10, -1894986606);
            c = ii(c, d, a, b, k[10], 15, -1051523);    b = ii(b, c, d, a, k[1], 21, -2054922799);
            a = ii(a, b, c, d, k[8], 6, 1873313359);    d = ii(d, a, b, c, k[15], 10, -30611744);
            c = ii(c, d, a, b, k[6], 15, -1560198380);  b = ii(b, c, d, a, k[13], 21, 1309151649);
            a = ii(a, b, c, d, k[4], 6, -145523070);    d = ii(d, a, b, c, k[11], 10, -1120210379);
            c = ii(c, d, a, b, k[2], 15, 718787259);    b = ii(b, c, d, a, k[9], 21, -343485551);

            x[0] = safeAdd(a, x[0]); x[1] = safeAdd(b, x[1]);
            x[2] = safeAdd(c, x[2]); x[3] = safeAdd(d, x[3]);
        }
        function blk(s) {
            var out = [], i;
            for (i = 0; i < 64; i += 4) {
                out[i >> 2] = s.charCodeAt(i) + (s.charCodeAt(i + 1) << 8) +
                    (s.charCodeAt(i + 2) << 16) + (s.charCodeAt(i + 3) << 24);
            }
            return out;
        }
        function hex(n) {
            var s2 = '', i, v;
            for (i = 0; i < 4; i++) {
                v = (n >> (i * 8)) & 0xff;
                s2 += ('0' + v.toString(16)).slice(-2);
            }
            return s2;
        }
        var str = String(s == null ? '' : s), n, i, tail;
        try { str = unescape(encodeURIComponent(str)); } catch (e) { /* 旧内核降级：按原串处理 */ }
        n = str.length;
        var state = [1732584193, -271733879, -1732584194, 271733878];
        for (i = 64; i <= n; i += 64) cycle(state, blk(str.substring(i - 64, i)));
        str = str.substring(i - 64);
        tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        for (i = 0; i < str.length; i++) tail[i >> 2] |= str.charCodeAt(i) << ((i % 4) << 3);
        tail[i >> 2] |= 0x80 << ((i % 4) << 3);
        if (i > 55) { cycle(state, tail); for (i = 0; i < 16; i++) tail[i] = 0; }
        tail[14] = n * 8;
        tail[15] = Math.floor(n / 0x20000000);
        cycle(state, tail);
        return hex(state[0]) + hex(state[1]) + hex(state[2]) + hex(state[3]);
    }

    /* ---------- 通用工具 ---------- */
    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function toast(msg, ms) {
        var t = $('owToast'); if (!t) return;
        t.innerHTML = esc(msg); t.style.display = 'block';
        clearTimeout(t._tm);
        t._tm = setTimeout(function () { t.style.display = 'none'; }, ms || 2200);
    }
    /* 用户 ID 展示补零：至少 3 位（001），超出 3 位按实际位数（1000 起）。
       仅影响显示，传输与存储始终是数字，后端搜索兼容 001 输入（(int) 归一）。 */
    function fmtUid(id) {
        var s = String(id == null ? 0 : id);
        var w = s.length > 3 ? s.length : 3;
        while (s.length < w) s = '0' + s;
        return s;
    }
    /** 归一化 class 字符串：去掉多余空格（增删 class 时避免累积空白） */
    function trimCls(s) { return String(s || '').replace(/\s+/g, ' ').replace(/^ | $/g, ''); }

    /* 枚举值中文显示（提交时仍用英文原始值，仅界面本地化） */
    var ROOM_TYPE_CN = { 'public': '公开', 'password': '密码房', 'role': '角色限定' };
    var ROLE_CN = { 'guest': '游客', 'member': '普通用户', 'vip': 'VIP', 'admin': '超级管理员' };
    function cn(map, v) { return map[v] || v; }
    function opts(map, keys, current) {
        var h = '', i;
        for (i = 0; i < keys.length; i++) {
            h += '<option value="' + esc(keys[i]) + '"' + (current === keys[i] ? ' selected' : '') + '>' + esc(cn(map, keys[i])) + '</option>';
        }
        return h;
    }

    /* 文件消息卡片：图标 + 文件名 + 大小 + 下载（下载链接带签名，服务端再校验房间权限） */
    function fileCardHtml(m) {
        var info = null;
        try { info = JSON.parse(m.content); } catch (e) { info = null; }
        if (!info) return '<span class="ow-file-card">文件内容已失效</span>';
        var s = OwApi.sign('file_download');
        var dl = '?action=file_download&id=' + m.id + '&ts=' + s.ts + '&sign=' + s.sign;
        return '<div class="ow-file-card">'
            + '<span class="ow-file-ico">' + owSvg('file', 20) + '</span>'
            + '<span class="ow-file-meta"><span class="ow-file-name">' + esc(info.name || '文件') + '</span>'
            + '<span class="ow-file-size">' + esc(String(info.ext || '').toUpperCase()) + ' · ' + esc(sizeText(info.size)) + '</span></span>'
            + '<a class="ow-file-dl" href="' + dl + '" title="下载">' + owSvg('download', 18) + '</a></div>';
    }
    function sizeText(n) {
        n = parseInt(n, 10) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }
    /** 生成 ow 线性 SVG 图标（与服务端 ow_icon 保持一致的描边风格） */
    var OW_SVG_PATHS = {
        'file': '<path d="M13 3.5H7a1.5 1.5 0 0 0-1.5 1.5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V9z"/><path d="M13 3.5V9h5.5"/>',
        'download': '<path d="M12 4v11"/><path d="M7.5 11L12 15.5 16.5 11"/><path d="M4.5 19.5h15"/>'
    };
    function owSvg(name, size) {
        var p = OW_SVG_PATHS[name] || '';
        return '<svg class="ow-icon" width="' + (size || 18) + '" height="' + (size || 18) + '" viewBox="0 0 24 24" fill="none" '
            + 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>';
    }

    var OwApi = {
        key: '',
        tsOffset: 0,   // 客户端时钟与服务器的偏差（秒），由页面下发的服务器时间校正
        // 关键：签名用的 ts 以「服务器时间」为准，客户端系统时钟不准也不会导致签名失败
        setServerTime: function (ts) {
            if (!ts) return;
            this.tsOffset = parseInt(ts, 10) - Math.floor(new Date().getTime() / 1000);
        },
        sign: function (action) {
            var ts = Math.floor(new Date().getTime() / 1000) + this.tsOffset;
            return { ts: ts, sign: md5(this.key + '|' + ts + '|' + action) };
        },
        // 带文件的 multipart 上传：upload(action, file, cb) 或 upload(action, file, extraFields, cb)
        upload: function (action, file, extra, cb) {
            if (typeof extra === 'function') { cb = extra; extra = null; }
            var s = this.sign(action), fd, x, k;
            try { fd = new FormData(); } catch (e) { cb({ ok: false, msg: '当前浏览器不支持文件上传' }); return; }
            fd.append('ts', s.ts);
            fd.append('sign', s.sign);
            if (extra) { for (k in extra) if (extra.hasOwnProperty(k)) fd.append(k, extra[k]); }
            fd.append('file', file);
            x = new XMLHttpRequest();
            x.open('POST', '?action=' + action, true);
            x.onreadystatechange = function () {
                if (x.readyState !== 4) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                r = r || { ok: false, msg: '网络错误（' + x.status + '）' };
                if (r.ok === false && r.msg && r.msg.indexOf('签名验证失败') >= 0) {
                    OwApi.onSignExpired(function () { cb(r, x.status); });
                    return;
                }
                cb(r, x.status);
            };
            x.send(fd);
            return x;
        },

        /* 签名失效自愈：会话重建/页面为旧缓存时密钥对不上，自动刷新一次取新密钥 */
        onSignExpired: function (cb) {
            var flag = 'owl_sig_reload_at', now = new Date().getTime(), last = 0;
            try { last = parseInt(w.sessionStorage.getItem(flag) || '0', 10); } catch (e) {}
            if (last && now - last < 15000) { if (cb) cb(); return; } // 15 秒内只自动刷新一次，避免死循环
            try { w.sessionStorage.setItem(flag, String(now)); } catch (e) {}
            if (cb) cb();
            setTimeout(function () { location.reload(); }, 800);
        },
        post: function (action, data, cb) {
            var s = this.sign(action), body = 'ts=' + s.ts + '&sign=' + s.sign, k;
            for (k in (data || {})) if (data.hasOwnProperty(k)) body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
            var x = new XMLHttpRequest();
            x.open('POST', '?action=' + encodeURIComponent(action), true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            x.onreadystatechange = function () {
                if (x.readyState !== 4) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                r = r || { ok: false, msg: '网络错误（' + x.status + '）' };
                if (r.ok === false && r.msg && r.msg.indexOf('签名验证失败') >= 0) {
                    OwApi.onSignExpired(function () { cb(r, x.status); });
                    return;
                }
                cb(r, x.status);
            };
            x.send(body);
            return x;
        },

        /**
         * 敏感操作（v1.0.91）：退出登录 / 删除内容 / 管理员删·恢复 / 禁用等。
         * 先取一次性操作票据（?action=ticket，签名保护），随请求提交，服务端校验后作废，
         * 防止签名窗口期内的请求重放与跨站劫持。用法与 post 相同：OwApi.secure(action, data, cb)
         */
        secure: function (action, data, cb) {
            this.post('ticket', {}, function (t) {
                if (!t.ok || !t.ticket) { cb({ ok: false, msg: t.msg || '安全校验组件不可用' }); return; }
                var d = {}, k;
                for (k in (data || {})) if (data.hasOwnProperty(k)) d[k] = data[k];
                d.ticket = t.ticket;
                OwApi.post(action, d, cb);
            });
        },
    };

    /* 提示音（内置短音 data URI，旧浏览器静默降级） */
    var BEEP = 'data:audio/wav;base64,UklGRl9vT1dQV0ZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAD//w==';
    function beep() {
        try {
            var AC = w.AudioContext || w.webkitAudioContext;
            if (AC) {
                var ctx = beep._ctx || (beep._ctx = new AC());
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = 880;
                g.gain.setValueAtTime(0.08, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                o.connect(g); g.connect(ctx.destination);
                o.start(); o.stop(ctx.currentTime + 0.25);
            } else {
                var a = new Audio(BEEP); a.play();
            }
        } catch (e) {}
    }

    /* 前台身份标签（v1.0.86）：只保留「群主 / 会员」两类身份展示——
       群主=当前群聊 owner（橙色），VIP=会员（保留 VIP 配色），普通用户与超级管理员=会员（灰色），
       游客与其它角色不再展示身份标签。超级管理员在别人创建的群聊里同样显示「会员」。 */
    var CUR_OWNER = 0;   // 当前群聊的 owner 用户 ID（OwChat 切换群聊时同步）
    function roleTag(role, title, uid) {
        var h = '';
        if (uid && uid === CUR_OWNER) h = '<span class="ow-tag ow-tag-owner">群主</span>';
        else if (role === 'vip') h = '<span class="ow-tag ow-tag-vip">会员</span>';
        else if (role === 'member' || role === 'admin') h = '<span class="ow-tag ow-tag-member">会员</span>';
        if (title) h += (h ? ' ' : '') + '<span class="ow-tag ow-tag-title">' + esc(title) + '</span>';
        return h;
    }

    /* 头像：有图用图；无图时游客固定米金底（#E5D5A0，深字保证可读），
       用户按昵称长度从色盘取色 */
    function avatarHtml(url, name, sm, role) {
        // 尺寸档：'xs'=20px（设置弹窗）、true/sm=28px（列表）、'md'=32px、false=40px（资料区）
        var sizeCls = sm === 'xs' ? ' ow-avatar-xs' : (sm === 'md' ? ' ow-avatar-md' : (sm ? ' ow-avatar-sm' : ''));
        var cls = 'ow-avatar' + sizeCls;
        if (url) return '<span class="' + cls + '"><img src="' + esc(url) + '" alt=""></span>';
        var ch = esc((name || '?').charAt(0));
        if (role === 'guest') {
            return '<span class="' + cls + '" style="background:#E5D5A0;color:#5C4500">' + ch + '</span>';
        }
        var colors = ['#0099FF', '#00558F', '#A05000', '#237804', '#5B21B6'];
        var ci = (name || '').length % colors.length;
        return '<span class="' + cls + '" style="background:' + colors[ci] + '">' + ch + '</span>';
    }

    /* ==========================================================================
       OwAuth：登录 / 注册 / 找回密码
       ========================================================================== */
    var OwAuth = {
        init: function (opt) {
            OwApi.key = opt.key;
            OwApi.setServerTime(opt.ts);   // 用服务器时间校正本机时钟偏差
            var form = document.querySelector('.ow-auth-form');
            if (!form) return;
            var mode = form.getAttribute('data-mode');
            var msg = form.querySelector('.ow-form-msg');

            var img = $('owCaptchaImg');
            if (img) img.onclick = function () { img.src = '?action=captcha&_=' + new Date().getTime(); };

            var codeBtn = form.querySelector('[data-sendcode]');
            if (codeBtn) codeBtn.onclick = function () {
                var email = form.querySelector('[name=email]').value;
                if (!email) { msg.innerHTML = '<span style="color:#C41D1F">请先填写邮箱</span>'; return; }
                codeBtn.disabled = true;
                OwApi.post('send_code', { email: email, type: codeBtn.getAttribute('data-sendcode') }, function (r) {
                    msg.innerHTML = '<span style="color:' + (r.ok ? '#237804' : '#C41D1F') + '">' + esc(r.msg) + '</span>';
                    var n = 60;
                    if (r.ok) {
                        var tm = setInterval(function () {
                            codeBtn.innerHTML = n + 's';
                            if (--n < 0) { clearInterval(tm); codeBtn.disabled = false; codeBtn.innerHTML = '发验证码'; }
                        }, 1000);
                    } else codeBtn.disabled = false;
                });
            };

            form.onsubmit = function (e) {
                e.preventDefault();
                var data = {}, i, els = form.elements;
                // 跳过服务端预置的 ts/sign 隐藏域：由 OwApi 用实时值重新签名
                for (i = 0; i < els.length; i++) {
                    if (!els[i].name || els[i].name === 'ts' || els[i].name === 'sign') continue;
                    data[els[i].name] = els[i].value;
                }
                msg.innerHTML = '提交中…';
                OwApi.post(mode === 'login' ? 'login' : mode, data, function (r) {
                    if (r.ok) {
                        msg.innerHTML = '<span style="color:#237804">' + esc(r.msg) + '</span>';
                        // 注册成功不进入聊天（后端注册本就不建会话）：跳登录页由用户手动登录
                        setTimeout(function () {
                            location.href = mode === 'login' ? '?page=chat'
                                : mode === 'reset' ? '?page=login'
                                : '?page=login&registered=1';
                        }, 800);
                    } else {
                        msg.innerHTML = '<span style="color:#C41D1F">' + esc(r.msg) + '</span>';
                        if (r.captcha && $('owCaptchaRow')) {
                            $('owCaptchaRow').style.display = 'block';
                            if (img) img.src = '?action=captcha&_=' + new Date().getTime();
                        }
                    }
                });
            };
        }
    };

    /* ==========================================================================
       OwChat：聊天主程序
       ========================================================================== */
    /**
     * 通用聊天列表轮子（v1.1.0）：群聊与私聊会话共用的列表渲染器。
     * 与业务无关——只负责「头像 + 名称 + 右侧摘要/时间 + 标签 + 选中态」的 DOM 组装与点击分发，
     * 数据形状：[{ conv:'room'|'dm', id, peer, name, avatar, last_at, last_text, tag }]
     * opts: { container, activeKey, onClick(item, el), emptyText }
     */
    var ChatList = {
        time: function (ts) {
            if (!ts) return '';
            var d = new Date(ts * 1000), now = new Date();
            function p(n) { return (n < 10 ? '0' : '') + n; }
            var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
            if (sameDay) return p(d.getHours()) + ':' + p(d.getMinutes());
            if (d.getFullYear() === now.getFullYear()) return (d.getMonth() + 1) + '/' + d.getDate();
            return d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
        },
        render: function (list, opts) {
            var box = typeof opts.container === 'string' ? $(opts.container) : opts.container;
            if (!box) return;
            if (!list || !list.length) {
                box.innerHTML = '<li class="ow-cl-empty">' + esc(opts.emptyText || '暂无会话') + '</li>';
                return;
            }
            var html = '', i, c;
            for (i = 0; i < list.length; i++) {
                c = list[i];
                var key = c.conv + ':' + (c.conv === 'dm' ? c.peer : c.id);
                var icon = c.avatar
                    ? '<span class="ow-cl-icon"><img src="' + esc(c.avatar) + '" alt=""></span>'
                    : '<span class="ow-cl-icon">' + esc((c.name || '?').charAt(0)) + '</span>';
                html += '<li class="ow-cl-item' + (key === opts.activeKey ? ' active' : '') + '" data-key="' + esc(key) + '"'
                      + (c.conv === 'dm' ? ' data-dm="' + esc(c.peer) + '"' : ' data-room="' + (c.conv === 'room' ? c.id : 0) + '"')
                      + ' data-name="' + esc(c.name) + '" data-pw="' + (c.need_password ? 1 : 0) + '">'
                      + icon
                      + '<span class="ow-cl-main">'
                      + '<span class="ow-cl-title">' + esc(c.name) + (c.tag ? '<span class="ow-cl-tag">' + esc(c.tag) + '</span>' : '') + '</span>'
                      + '<span class="ow-cl-sub">' + esc(c.last_text || '') + '</span>'
                      + '</span>'
                      + '<span class="ow-cl-time">' + ChatList.time(c.last_at) + '</span>'
                      + '</li>';
            }
            box.innerHTML = html;
            var items = box.getElementsByTagName('li'), k;
            for (k = 0; k < items.length; k++) {
                if (items[k]._noClick) continue;
                items[k].onclick = function () { if (opts.onClick) opts.onClick(this); };
            }
        },
        /** 定位并高亮当前会话对应的行 */
        activate: function (container, key) {
            var box = typeof container === 'string' ? $(container) : container;
            if (!box) return;
            var items = box.getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                items[i].className = items[i].className.replace(' active', '');
                if (items[i].getAttribute('data-key') === key) items[i].className += ' active';
            }
        }
    };
    w.ChatList = ChatList;

    var OwChat = {
        cfg: null, room: 0, since: 0, polling: false, failCount: 0,
        historyDone: false, loadingHistory: false, sound: true, lastMsgId: 0,
        emojis: '😀 😁 😂 🤣 😊 😍 😘 😜 🤔 😎 😴 😷 🤒 😱 😭 😡 👍 👎 👏 🙏 💪 🤝 ❤️ 💔 🎉 🔥 ⭐ 🌹 🍀 🎂 ☕ 🍺 ⚽ 🏀 🚀 ✈️ 🐱 🐶 🦉 🌙 ☀️ 🌈'.split(' '),

        init: function (cfg) {
            this.cfg = cfg;
            OwApi.key = cfg.key;
            OwApi.setServerTime(cfg.ts);
            this.room = cfg.room;
            this.syncRoomOwner();
            this.sound = cfg.settings.sound === '1';
            var self = this;

            this.loadConversations();   // 会话列表（群聊+私聊聚合）——私聊路由要靠它取昵称
            this.renderConversations();
            this.startConvPoll();       // v1.1.0：列表低频轮询，新消息会话自动前置（不打断当前聊天）
            this.renderMe();
            this.buildEmojiPanel();
            this.bindEvents();
            this.renderRoomPanel();      // v1.1.1：初始化右侧栏群聊设置区

            // 地址路由：私聊 ?dm=user:12 优先（v1.1.0），否则规范化为 ?room=当前群聊（replace）
            var dmFromUrl = this.dmFromUrl();
            // 前进/后退（或手动改 URL 回车）→ 切换到对应群聊 / 私聊
            w.onpopstate = function () {
                // ★ v1.1.0：dm 与 room 在 URL 上互斥（见 chat-url-mutex 约束）。
                //   正常情况下只会命中其中一个；这里以 URL 实际内容为准，
                //   若两个都在（历史遗留 / 外部链接），优先认群聊 room，避免莫名跳进私聊。
                var rid = self.roomFromUrl();
                if (rid) {
                    if (self.dm || rid !== self.room) {
                        for (var i = 0; i < cfg.rooms.length; i++) {
                            if (cfg.rooms[i].id === rid) { self.switchRoom(rid, cfg.rooms[i].name, null, true); return; }
                        }
                    }
                    return;
                }
                var dpeer = self.dmFromUrl();
                if (!dpeer) return;
                if (self.dm && self.dm.peer === dpeer) return;
                self.openDm(dpeer, self.dmNameOf(dpeer));
            };

            if (dmFromUrl) {
                // 私聊视图：昵称取自会话列表，但列表是异步到达的。
                // openDm 允许传入临时名（'私聊'），列表到达后 loadConversations 会重渲染并补上正确昵称，
                // 所以这里直接打开即可，无需轮询等待。
                this.setDmUrl(dmFromUrl, true);
                this.openDm(dmFromUrl, this.dmNameOf(dmFromUrl));
                // ★ 不触发 _fireRoomSwitch：私聊下 this.room=0，插件按 room_id=0 取数据是错的；
                //   群级装饰由 openDm 末尾的 _fireViewChange 通知插件清理。
                return;   // 私聊入口不加载群聊历史、不启动群聊长轮询
            }
            this.setRoomUrl(this.room, true);

            // 初始加载历史：是否需密码由服务端判定（管理员/已授权会直接放行，不会弹窗）
            var first = null, i;
            for (i = 0; i < cfg.rooms.length; i++) if (cfg.rooms[i].id === this.room) first = cfg.rooms[i];
            var load = function (password) {
                OwApi.post('room_join', { room_id: self.room, password: password || '' }, function (j) {
                    if (!j.ok) {
                        if (j.need_password) {
                            self.passForget(self.room);
                            self.askRoomPassword(self.room, first ? first.name : '', function (pw) { load(pw); });
                            return;
                        }
                        if (j.need_login) { location.href = '?page=login'; return; }
                    } else {
                        self.passRemember(self.room, j.ttl);
                    }
                    if (self.dm) return;   // 等待期间用户已切到私聊
                    OwApi.post('history', { room_id: self.room, before: 0 }, function (r) {
                        if (self.dm) return;
                        if (r.ok) {
                            for (var k = 0; k < r.data.length; k++) self.addMessage(r.data[k], true);
                            if (r.data.length) self.since = r.data[r.data.length - 1].id;
                            self.scrollBottom();
                            if (r.data.length < 30) self.historyDone = true;
                        } else if (r.need_password) {
                            self.passForget(self.room);
                            self.askRoomPassword(self.room, first ? first.name : '', function (pw) { load(pw); });
                            return;
                        }
                        self._roomPollRunning = true;
                        self.startPoll();
                    });
                });
            };
            load('');
            this._fireRoomSwitch();   // 首次进入也通知插件（公告等按群拉取）
        },

        /** 从已加载的会话列表里取私聊对方昵称（供 URL 直达 / 前进后退时补名） */
        dmNameOf: function (peer) {
            var list = this.conversations || [], i;
            for (i = 0; i < list.length; i++) if (list[i].conv === 'dm' && list[i].peer === peer) return list[i].name;
            return '私聊';
        },

        bindEvents: function () {
            var self = this;
            $('owBtnSend').onclick = function () { self.send(); };
            var input = $('owInput');
            // 随内容自动增高；恢复上次手动拖出的高度
            try { self.inputUserH = parseInt(w.localStorage.getItem('owl_input_h') || '0', 10) || 0; } catch (e) {}
            self.bindInputResize();
            self.autoGrow();
            input.oninput = function () { self.autoGrow(); };
            input.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13 && !e.shiftKey) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); self.send(); }
            };
            input.onpaste = function (e) {
                var items = (e.clipboardData || w.clipboardData).items;
                if (!items) return;
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') === 0) {
                        var f = items[i].getAsFile();
                        if (f) self.uploadImage(f);
                    }
                }
            };
            $('owBtnFile').onclick = function () { $('owFileAttach').click(); };
            $('owFileAttach').onchange = function () {
                var f = this.files && this.files[0];
                this.value = '';
                if (f) self.uploadFile(f);
            };
            $('owBtnImage').onclick = function () { $('owFileInput').click(); };
            $('owFileInput').onchange = function () {
                if (this.files && this.files[0]) self.uploadImage(this.files[0]);
                this.value = '';
            };
            // 表情面板：按钮切换 + 点击外部/Esc 关闭（原来只能靠再点一次按钮）
            var setEmoji = function (open) {
                var p = $('owEmojiPanel');
                if (!p) return;
                p.style.display = open ? 'block' : 'none';
            };
            $('owBtnEmoji').onclick = function () {
                var p = $('owEmojiPanel');
                setEmoji(getComputedStyle(p).display === 'none');
            };
            $('owBtnSound').onclick = function () {
                self.sound = !self.sound;
                this.innerHTML = this.getAttribute(self.sound ? 'data-on' : 'data-off');
                toast(self.sound ? '提示音已开启' : '提示音已关闭');
            };
            // 窄屏浮层遮罩：侧栏或成员面板打开时显示
            var syncMask = function () {
                var m = $('owMask');
                if (!m) return;
                var open = $('owSidebar').className.indexOf('open') >= 0 || $('owOnline').className.indexOf('open') >= 0;
                m.className = open ? 'ow-mask show' : 'ow-mask';
            };
            // 侧栏开关：真正的切换（原写法只加不减，打开后无法关闭）
            var setSide = function (open) {
                var s = $('owSidebar');
                var c = s.className.replace(' open', '');
                s.className = c + (open ? ' open' : '');
                syncMask();
            };
            $('owToggleSide').onclick = function () {
                setSide($('owSidebar').className.indexOf('open') < 0);
            };
            // 群聊信息面板：宽屏用 hidden 收起（常驻侧栏），窄屏用 open 浮层（默认收起）
            // v1.1.1：侧栏内容改为「上方群聊设置 + 下方所有成员」，开关时需重渲染设置区
            var isNarrow = function () { return (document.documentElement.clientWidth || w.innerWidth || 1024) <= 960; };
            var setPanel = function (open) {
                var o = $('owOnline');
                var c = o.className.replace(' open', '').replace(' hidden', '');
                var narrow = isNarrow();
                o.className = c + (open ? (narrow ? ' open' : '') : (narrow ? '' : ' hidden'));
                syncMask();
            };
            var togglePanel = function () {
                var o = $('owOnline');
                var open = isNarrow() ? (o.className.indexOf('open') >= 0) : (o.className.indexOf('hidden') < 0);
                setPanel(!open);
                if (!open) self.renderRoomPanel();   // 打开时刷新设置区（切群后内容可能已过期）
            };
            $('owTogglePanel').onclick = togglePanel;
            $('owOnlineClose').onclick = function () { setPanel(false); };
            // 所有成员面板默认一律不展开（v1.0.101，v1.0.119 恢复：游客入口已移到顶栏）
            setPanel(false);
            $('owMask').onclick = function () { setSide(false); setPanel(false); };
            // 创建群聊：侧栏底部按钮（仅登录用户渲染）→ 弹窗
            if ($('owBtnCreateRoom')) $('owBtnCreateRoom').onclick = function () {
                setEmoji(false);
                self.roomCreateModal();
            };
            // 窄屏浮层：点击浮层外部时收起（成员面板 / 左侧栏 / 表情面板 / 「+」菜单）
            document.addEventListener ? document.addEventListener('click', function (e) {
                var o = $('owOnline'), s = $('owSidebar'), em = $('owEmojiPanel');
                var t = e.target || e.srcElement, inside = false, n = t;
                while (n) {
                    if (n === o || n === $('owTogglePanel') || n === s || n === $('owToggleSide')
                        || n === em || n === $('owBtnEmoji')) { inside = true; break; }
                    n = n.parentNode;
                }
                if (inside) return;
                setEmoji(false);          // 点空白处顺手收起表情面板
                if (o && o.className.indexOf('open') >= 0) setPanel(false);
                if (s && s.className.indexOf('open') >= 0) setSide(false);
            }) : (document.onclick = null);
            $('owMessages').onscroll = function () {
                if (this.scrollTop < 40 && !self.historyDone && !self.loadingHistory) self.loadHistory();
            };
            /* 消息时间：悬停「消息气泡」满 2 秒才显示，移开立即隐藏（v1.1.0）
               为什么不用 CSS :hover —— CSS 无法表达「持续满 N 秒」，
               一 hover 就出现会与快速扫读打架，也会让昵称行一直跳。
               用父容器委托而非逐条绑定：消息频繁重渲染（innerHTML 重建），
               逐条绑定会随重建丢失。
               计时器挂在 OwChat 上，切换会话时统一清理，避免残留。 */
            $('owMessages').onmouseover = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement, node = t;
                while (node && node !== this) {
                    if (node.className && (' ' + node.className + ' ').indexOf(' ow-msg ') >= 0) break;
                    node = node.parentNode;
                }
                if (!node || node === this) { self.hideMsgTime(); return; }
                if (self._timeMsg === node) return;          // 已在同一条计时中
                self.hideMsgTime();
                self._timeMsg = node;
                self._timeTimer = setTimeout(function () {
                    self._timeTimer = null;
                    if (self._timeMsg === node) node.className += ' ow-time-show';
                }, 2000);
            };
            $('owMessages').onmouseout = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement, node = t, to = e.relatedTarget || e.toElement;
                while (node && node !== this) {
                    if (node.className && (' ' + node.className + ' ').indexOf(' ow-msg ') >= 0) break;
                    node = node.parentNode;
                }
                if (!node || node === this) return;
                // 鼠标只是从消息内部移到了它自己的子元素上（不算真正离开）
                if (to && node.contains && node.contains(to)) return;
                self.hideMsgTime();
            };
            /* 「加载更早消息…」每次切换会话都会被 innerHTML 重建，元素换了，
               直接 onclick 绑定会失效（v1.1.0 私聊复用同一消息区后暴露）。改用父容器委托。
               注意：引用跳转仍走 buildMessage 里的内联 onclick（ow-quote-link），不归这里管。 */
            $('owMessages').onclick = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement;
                if (t && t.id === 'owLoadMore') self.loadHistory();
            };
            // 右键消息气泡 → 操作菜单（@/私信/收藏/撤回，插件可追加）
            $('owMessages').oncontextmenu = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement, node = t;
                while (node && node !== this) {
                    if (node.id && /^owMsg\d+$/.test(node.id)) break;
                    node = node.parentNode;
                }
                if (!node || node === this || !node.id) { self.hideCtxMenu(); return; }
                var m = self.msgCache[parseInt(node.id.replace('owMsg', ''), 10)];
                if (!m || m.type === 'system' || m.recalled || m.deleted) { self.hideCtxMenu(); return; }
                if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
                // 右键落点分流（v1.0.69）：点在头像上 → 对该「人」的操作菜单；
                // 点在消息内容 / 其它区域 → 对该「消息」的操作菜单（复制 / 引用 / 删除）
                var onAvatar = false, n2 = e.target || e.srcElement;
                while (n2 && n2 !== node) {
                    if (n2.className && String(n2.className).indexOf('ow-avatar') >= 0) { onAvatar = true; break; }
                    n2 = n2.parentNode;
                }
                if (onAvatar) self.showUserMenu(e.clientX || 0, e.clientY || 0, m);
                else self.showContentMenu(e.clientX || 0, e.clientY || 0, m);
                return false;
            };
            // 点击菜单外 / Esc 关闭
            document.onclick = function (e) {
                var menu = $('owCtxMenu');
                if (!menu || menu.style.display === 'none') return;
                var t = e.target || e.srcElement;
                var inside = false, n = t;
                while (n) { if (n === menu) { inside = true; break; } n = n.parentNode; }
                if (!inside) self.hideCtxMenu();
            };
            document.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 27) { self.hideCtxMenu(); setEmoji(false); }
            };
            // 菜单项点击（委托）：执行对应操作后收起菜单
            $('owCtxMenu').onclick = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement;
                if (!t || (t.tagName || '').toUpperCase() !== 'A') return;
                var it = self._ctxItems[parseInt(t.getAttribute('data-i'), 10)];
                self.hideCtxMenu();
                if (it && it.run) it.run();
            };
            $('owModalMask').onclick = function (e) { if (e.target === this) self.closeModal(); };
            $('owImgViewer').onclick = function () { this.style.display = 'none'; };
            var lo = $('owBtnLogout');
            if (lo) lo.onclick = function () {
                self.confirmModal('确定退出登录吗？', function () {
                    OwApi.secure('logout', {}, function () { location.href = '?page=login'; });
                });
            };
            var st = $('owBtnSettings');
            if (st) st.onclick = function () { self.openSettings(); };
        },

        /* ---------- 密码房：通行缓存 + 自研密码弹窗 ---------- */
        // 缓存键（按房间），仅存"已授权到几点"，不存密码本身
        passCacheKey: function (roomId) { return 'owl_room_pass_' + roomId; },
        passCached: function (roomId) {
            var v = 0;
            try { v = parseInt(w.sessionStorage.getItem(this.passCacheKey(roomId)) || '0', 10); } catch (e) {}
            return v > Math.floor(new Date().getTime() / 1000);
        },
        passRemember: function (roomId, ttl) {
            if (!ttl || ttl <= 0) { this.passForget(roomId); return; }
            // 比服务端有效期提前 60 秒失效，避免边界上反复弹窗
            var until = Math.floor(new Date().getTime() / 1000) + Math.max(60, ttl - 60);
            try { w.sessionStorage.setItem(this.passCacheKey(roomId), String(until)); } catch (e) {}
        },
        passForget: function (roomId) {
            try { w.sessionStorage.removeItem(this.passCacheKey(roomId)); } catch (e) {}
        },
        // 需要密码（且本地无有效缓存）时弹出自研弹窗，验证成功回调 onOk
        askRoomPassword: function (roomId, roomName, onOk) {
            var self = this;
            this.openModal(
                '<h3>需要密码</h3>'
                + '<p class="ow-modal-desc">进入「' + esc(roomName) + '」需要密码，验证成功后在有效期内不必重复输入。</p>'
                + '<div class="ow-form-item"><label>房间密码</label>'
                + '<input class="ow-input" type="password" id="owRoomPw" autocomplete="off" placeholder="请输入房间密码"></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owRoomPwCancel">取消</button>'
                + '<button class="ow-btn ow-btn-primary" id="owRoomPwOk">进 入</button></div>'
                + '<div class="ow-form-msg" id="owRoomPwMsg"></div>'
            );
            var input = $('owRoomPw'), msg = $('owRoomPwMsg');
            var submit = function () {
                var pw = input.value;
                if (!pw) { msg.innerHTML = '<span style="color:#C41D1F">请输入密码</span>'; return; }
                msg.innerHTML = '验证中…';
                OwApi.post('room_join', { room_id: roomId, password: pw }, function (r) {
                    if (!r.ok) {
                        msg.innerHTML = '<span style="color:#C41D1F">' + esc(r.msg) + '</span>';
                        input.select();
                        return;
                    }
                    self.passRemember(roomId, r.ttl);
                    self.closeModal();
                    if (onOk) onOk();
                });
            };
            $('owRoomPwOk').onclick = submit;
            $('owRoomPwCancel').onclick = function () { self.closeModal(); };
            input.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); submit(); }
            };
            try { input.focus(); } catch (e) {}
        },

        /* ---------- 房间 ---------- */
        /**
         * 拉取会话列表（群聊 + 私聊聚合，服务端已按最后活跃时间倒序），交给通用轮子渲染。
         * v1.1.0：取代旧的 renderRooms —— 群聊与私聊共用同一列表与同一交互。
         */
        loadConversations: function () {            var self = this;
            OwApi.post('conversations', {}, function (r) {
                if (!r.ok) return;
                self.conversations = r.data;
                // 侧栏「聊天」徽标 = 会话总数（群聊数 + 私聊会话数），服务端已回传 total
                var badge = $('owRoomCount');
                if (badge) badge.innerHTML = (r.total != null ? r.total : r.data.length);
                // 私聊视图的标题用的是进入时的昵称；若当时列表未到（标题为占位「私聊」），
                // 这里用刚取到的真实昵称补正，避免刷新后标题一直停在占位文案
                if (self.dm && self.roomName === '私聊') {
                    var nm = self.dmNameOf(self.dm.peer);
                    if (nm && nm !== '私聊') {
                        self.roomName = nm;
                        $('owRoomName').innerHTML = esc(nm);
                    }
                }
                self.renderConversations();
            });
        },

        /* ---------- 会话列表轻量轮询（v1.1.0） ----------
           需求：任何会话来了新消息，该会话自动排到列表最前，用户不必手动刷新。
           为什么不能靠消息长轮询带出来：群聊 poll 只监听「当前所在群」，
           私聊 dm_poll 只监听「当前所在私聊」——停在群聊2 时收不到群聊1 的消息。
           所以这里单独开一路低频轮询（10s），只重渲染左侧栏，
           完全不碰消息区 / 输入栏 / 当前会话，因此不会打断正在进行的聊天。 */
        _convTimer: null,
        startConvPoll: function () {
            var self = this;
            if (this._convTimer) return;
            var loop = function () {
                if (!self.cfg) return;
                self.loadConversations();   // 内部重渲染列表，幂等
                self._convTimer = setTimeout(loop, 10000);
            };
            this._convTimer = setTimeout(loop, 10000);
        },
        stopConvPoll: function () {
            if (this._convTimer) { clearTimeout(this._convTimer); this._convTimer = null; }
        },

        /** 会话列表渲染 + 行点击分发（群聊走密码房流程，私聊进私聊页） */
        renderConversations: function () {
            var self = this, list = this.conversations || [];
            var activeKey = this.dm ? ('dm:' + this.dm.peer) : ('room:' + this.room);
            ChatList.render(list, {
                container: 'owRoomList',
                activeKey: activeKey,
                emptyText: '暂无会话',
                onClick: function (el) {
                    var dm = el.getAttribute('data-dm');
                    if (dm) return self.openDm(dm, el.getAttribute('data-name'));
                    var id = parseInt(el.getAttribute('data-room'), 10) || 0;
                    var name = el.getAttribute('data-name');
                    if (!id) return;
                    // 群聊：先不带密码尝试一次（是否需要密码由服务端判定）
                    var tryJoin = function (password) {
                        OwApi.post('room_join', { room_id: id, password: password || '' }, function (rr) {
                            if (!rr.ok) {
                                if (rr.need_password) {
                                    if (password) toast(rr.msg);
                                    self.passForget(id);
                                    self.askRoomPassword(id, name, function (pw) { tryJoin(pw); });
                                    return;
                                }
                                toast(rr.msg);
                                if (rr.need_login) location.href = '?page=login';
                                return;
                            }
                            self.passRemember(id, rr.ttl);
                            self.switchRoom(id, rr.room.name, el);
                        });
                    };
                    tryJoin('');
                }
            });
            // 密码房标签：轮子渲染后补（数据里 need_password 时显示）
            var items = $('owRoomList').getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                if (items[i].getAttribute('data-pw') === '1' && items[i].querySelector('.ow-cl-lock')) continue;
                if (items[i].getAttribute('data-pw') === '1') {
                    var t = items[i].querySelector('.ow-cl-title');
                    if (t && !t.querySelector('.ow-cl-lock')) {
                        t.innerHTML += '<span class="ow-cl-tag ow-cl-lock">密码房</span>';
                    }
                }
            }
        },

        /**
         * 打开私聊会话（v1.1.0）：复用群聊骨架——消息区、输入栏、轮询全部沿用，
         * 仅切换「对方昵称」标题、会话目标（room_id=0 + to_user_id）与列表高亮。
         * peer 形如 'user:12' / 'guest:34'（服务端据此做双方可见性校验）。
         */
        openDm: function (peer, name) {
            var self = this;
            var m = /^(\w+):(\d+)$/.exec(peer || '');
            if (!m) return;
            // 已在该私聊：若只是补来了真实昵称（此前为占位「私聊」），只更新标题即可，不重载历史
            if (this.dm && this.dm.peer === peer) {
                if (name && name !== this.roomName && name !== '私聊') {
                    this.roomName = name;
                    $('owRoomName').innerHTML = esc(name);
                }
                return;
            }
            // 先切状态：startPoll 的 alive() 依赖 !this.dm，赋值即让群聊长轮询自杀
            this.dm = { peer: peer, kind: m[1], id: parseInt(m[2], 10) };
            this.pollGen = (this.pollGen || 0) + 1;          // 作废在途的群聊轮询回调
            this._roomPollRunning = false;                  // 群聊长轮询就此停摆
            this.room = 0;
            this.since = 0;
            this.historyDone = false;
            this.loadingHistory = false;
            this.roomName = name || '私聊';
            this.syncRoomOwner();
            this.renderMe();
            this.clearQuote();
            $('owRoomName').innerHTML = esc(this.roomName);
            this.hideMsgTime();   // v1.1.0：消息区重渲染前清掉悬停计时（引用的元素已不存在）
            $('owMessages').innerHTML = '<div class="ow-load-more" id="owLoadMore">加载更早消息…</div>';
            ChatList.activate('owRoomList', 'dm:' + peer);
            $('owSidebar').className = $('owSidebar').className.replace(' open', '');
            this.setDmUrl(peer);
            this.scrollBottom();
            // 历史：迟到响应需校验仍停留在同一私聊，否则丢弃（避免串到别的会话）
            var myPeer = peer;
            OwApi.post('dm_history', { peer: myPeer, before_id: 0 }, function (r) {
                if (!self.dm || self.dm.peer !== myPeer) return;
                if (!r.ok) { toast(r.msg); return; }
                // 服务端一并回传对方资料：首次私聊时会话列表里还没有该项，
                // 靠这里把标题从占位「私聊」换成真实昵称
                if (r.peer && r.peer.name && r.peer.name !== self.roomName) {
                    self.roomName = r.peer.name;
                    $('owRoomName').innerHTML = esc(r.peer.name);
                }
                for (var i = 0; i < r.data.length; i++) self.addMessage(r.data[i], true);
                if (r.data.length) self.since = r.data[r.data.length - 1].id;
                self.scrollBottom();
                if (r.data.length < 30) self.historyDone = true;
                // 没有历史时移除「加载更早消息…」，避免空会话里悬空一个不可用入口
                if (!r.data.length) {
                    self.historyDone = true;
                    var lm = $('owLoadMore');
                    if (lm) lm.parentNode.removeChild(lm);
                }
            });
            this.dmPollLoop();
            this.renderRoomPanel();   // v1.1.1：私聊视图下侧栏群设置区显示占位提示
            // v1.1.0：进入私聊视图 → 通知插件清理群级装饰（公告条等）
            this._fireViewChange();
        },

        /**
         * 私聊增量轮询（与群聊 poll 同构，20s 长挂起）。
         * 用 dmGen 世代号与「当前是否仍在私聊」双重判定，保证切回群聊后立刻停摆。
         */
        dmPollLoop: function () {
            var self = this;
            if (!this.dm) return;
            if (this._dmPollTimer) { clearTimeout(this._dmPollTimer); this._dmPollTimer = 0; }
            if (this._dmPollBusy) return;
            var myGen = this.dmGen = (this.dmGen || 0) + 1;
            var peer = this.dm.peer, since = this.since;
            var alive = function () { return self.dm && self.dm.peer === peer && self.dmGen === myGen; };
            this._dmPollBusy = true;
            var t0 = new Date().getTime();
            OwApi.post('dm_poll', { peer: peer, since_id: since }, function (r) {
                if (!alive()) { self._dmPollBusy = false; return; }
                self._dmPollBusy = false;
                if (r && r.ok) {
                    var hasNew = false;
                    for (var i = 0; i < r.messages.length; i++) { self.addMessage(r.messages[i]); hasNew = true; }
                    if (r.messages.length) self.since = r.messages[r.messages.length - 1].id;
                    if (hasNew) {
                        self.scrollBottom();
                        if (self.sound) beep();
                    }
                    $('owLatency').innerHTML = '● ' + (new Date().getTime() - t0) + ' ms';
                    $('owLatency').style.color = '#237804';
                    // 有新消息即刷新会话列表（排序会因这条消息而变）
                    if (hasNew) self.loadConversations();
                }
                if (alive()) self._dmPollTimer = setTimeout(function () { self.dmPollLoop(); }, 100);
            });
        },

        /**
         * 私聊地址路由：?dm=user:12（v1.1.0）。
         * 与 setRoomUrl 对称：互斥清理对方参数（私聊页不保留 room=），并保留其余查询参数。
         *
         * ⚠️ 冒号不能被编码：peer 形如 'user:12'，若用 encodeURIComponent 会变成 'user%3A12'，
         * 而 dmFromUrl 的正则按字面冒号匹配 → 应用自己写出的链接自己都解析不出来。
         * ':' 是 RFC 3986 允许出现在 query 中的字符，直接拼接即可。
         */
        setDmUrl: function (peer, replace) {
            try {
                if (!w.history || !w.history.pushState) return;
                var search = (w.location.search || '').replace(/^\?/, '')
                    .replace(/(^|&)dm=[^&]*/g, '').replace(/(^|&)room=[^&]*/g, '')
                    .replace(/^&+|&+$/g, '');
                var q = search ? search + '&dm=' + peer : 'dm=' + peer;
                var url = w.location.pathname + '?' + q;
                if (replace) w.history.replaceState({ dm: peer }, '', url);
                else w.history.pushState({ dm: peer }, '', url);
            } catch (e) {}
        },

        /**
         * 地址路由：把当前群聊 id 写进 URL（?page=chat&room=ID）。
         * 刷新、分享链接、前进/后退都停留在对应群聊；保留其他查询参数。
         */
        setRoomUrl: function (rid, replace) {
            try {
                if (!w.history || !w.history.pushState) return;
                // ★ v1.1.0 修复：dm 与 room 必须互斥。
                //   原先只删 room=，从私聊切回群聊会留下 ?page=chat&dm=user:20&room=2，
                //   刷新时 dmFromUrl() 优先解析 → 错误跳回私聊（表现为「跳到第一个群聊」）。
                //   这里同时清掉 dm= 与 room=，再写入 room=。
                var search = (w.location.search || '').replace(/^\?/, '')
                    .replace(/(^|&)dm=[^&]*/g, '')
                    .replace(/(^|&)room=[^&]*/g, '').replace(/^&+|&+$/g, '');
                var q = search ? search + '&room=' + rid : 'room=' + rid;
                var url = w.location.pathname + '?' + q;
                if (replace) w.history.replaceState({ room: rid }, '', url);
                else w.history.pushState({ room: rid }, '', url);
            } catch (e) {}
        },
        /** 从当前 URL 解析 room id（无则 0） */
        roomFromUrl: function () {
            var mt = (w.location.search || '').match(/[?&]room=(\d+)/);
            return mt ? parseInt(mt[1], 10) || 0 : 0;
        },

        /** 私聊地址解析：?dm=user:12 / ?dm=guest:34（v1.1.0，兼容 %3A 编码形式） */
        dmFromUrl: function () {
            var mt = (w.location.search || '').match(/[?&]dm=([^&]+)/);
            if (!mt) return '';
            var v = decodeURIComponent(mt[1]);   // 外部链接可能带 %3A，需还原
            return /^(\w+:\d{1,10})$/.test(v) ? v : '';
        },

        /** 同步当前群聊的 owner 用户 ID 到模块变量 CUR_OWNER（roleTag 群主标签用） */
        syncRoomOwner: function () {
            var rooms = (this.cfg && this.cfg.rooms) || [];
            for (var i = 0; i < rooms.length; i++) {
                if (rooms[i].id === this.room) { CUR_OWNER = rooms[i].owner_id || 0; return; }
            }
            CUR_OWNER = 0;
        },

        /* ---------- 前端扩展钩子（v1.0.102，供插件注册） ---------- */
        _roomSwitchHooks: [],
        _roomEditHooks: [],
        _viewChangeHooks: [],
        /** 注册「切换群聊」回调：fn({ roomId, ownerId, isAdmin })，切群时触发；注册时立即补发当前状态（插件脚本晚于 init 加载） */
        onRoomSwitch: function (fn) {
            if (typeof fn !== 'function') return;
            this._roomSwitchHooks.push(fn);
            try { fn({ roomId: this.room, ownerId: CUR_OWNER, isAdmin: this.cfg.actor.role === 'admin' }); } catch (e) {}
        },
        /**
         * 注册「群聊设置区渲染」回调：fn({ roomId, ownerId, isAdmin, isOwner })。
         * v1.1.1：触发时机从「打开群聊设置弹窗」改为「右侧栏群聊设置区渲染」
         * （init / 切群 / 进私聊 / 打开侧栏 / 保存群资料后都会触发），
         * 可往 #owREExtras 追加入口。非群主渲染的是只读版，#owREExtras 依然存在，
         * 但插件应按 ctx.isOwner || ctx.isAdmin 自行决定是否填充。
         */
        onRoomEdit: function (fn) { if (typeof fn === 'function') this._roomEditHooks.push(fn); },
        _fireRoomSwitch: function () {
            for (var i = 0; i < this._roomSwitchHooks.length; i++) {
                try { this._roomSwitchHooks[i]({ roomId: this.room, ownerId: CUR_OWNER, isAdmin: this.cfg.actor.role === 'admin' }); } catch (e) {}
            }
        },

        /* ---------- 「视图切换」钩子（v1.1.0） ----------
           群聊与私聊共用同一套消息区/输入栏/顶部标题，但群级装饰（公告条、群设置入口等）
           只在群聊视图成立。核心不直接操作插件 DOM——由插件自己注册本钩子，
           在 view='dm' 时清理自己的群级装饰，view='room' 时按 roomId 复原。
           ctx: { view: 'room'|'dm', roomId, peer, ownerId, isAdmin }
           注册时立即补发当前视图（插件脚本晚于 init 加载）。 */
        onViewChange: function (fn) {
            if (typeof fn !== 'function') return;
            this._viewChangeHooks.push(fn);
            this._fireViewChangeTo(fn);
        },
        _fireViewChangeTo: function (fn) {
            try {
                fn({
                    view: this.dm ? 'dm' : 'room',
                    roomId: this.dm ? 0 : this.room,
                    peer: this.dm ? this.dm.peer : '',
                    ownerId: CUR_OWNER,
                    isAdmin: this.cfg && this.cfg.actor ? this.cfg.actor.role === 'admin' : false
                });
            } catch (e) {}
        },
        _fireViewChange: function () {
            for (var i = 0; i < this._viewChangeHooks.length; i++) this._fireViewChangeTo(this._viewChangeHooks[i]);
        },

        switchRoom: function (id, name, el, fromPop) {
            // v1.1.0：离开私聊态 —— 作废私聊轮询世代号，随后 startPoll 会接管长轮询
            if (this.dm) { this.dmGen = (this.dmGen || 0) + 1; this.dm = null; }
            // v1.1.1：群聊↔群聊切换同样要作废在途轮询。
            // 原先只在「私聊→群聊」时重启，导致 A 群切 B 群时在途的那个长轮询
            // （最长 20s）仍会醒来用 **A 群的 online 列表**刷一次侧栏，
            // 表现为「切了群但成员列表还是上一个群的」延迟二十秒。
            if (this._roomPollRunning) {
                this._roomPollRunning = false;
                this.pollGen = (this.pollGen || 0) + 1;   // 旧循环醒来即自杀
            }
            this.room = id; this.roomName = name; this.since = 0; this.historyDone = false;
            this.syncRoomOwner();
            this.renderMe();   // 资料区身份标签随群聊变化（群主/会员归属当前群）
            this.clearQuote();
            $('owRoomName').innerHTML = esc(name);
            this.hideMsgTime();   // v1.1.0：消息区重渲染前清掉悬停计时（引用的元素已不存在）
            $('owMessages').innerHTML = '<div class="ow-load-more" id="owLoadMore">加载更早消息…</div>';
            var items = $('owRoomList').getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                items[i].className = items[i].className.replace(' active', '');
                // 未传 el（前进/后退、创建群聊跳转等）时按 data-room 自动定位高亮
                if (!el && String(items[i].getAttribute('data-room')) === String(id)) el = items[i];
            }
            if (el) el.className += ' active';
            $('owSidebar').className = $('owSidebar').className.replace(' open', '');
            if (!fromPop) this.setRoomUrl(id, false);
            var self = this;
            var load = function () {
                OwApi.post('history', { room_id: id, before: 0 }, function (r) {
                    // 已切走（切到私聊或别的群）则丢弃迟到响应
                    if (self.dm || self.room !== id) return;
                    if (r.ok) {
                        for (var i = 0; i < r.data.length; i++) self.addMessage(r.data[i], true);
                        if (r.data.length) self.since = r.data[r.data.length - 1].id;
                        self.scrollBottom();
                        if (r.data.length < 30) self.historyDone = true;
                        // 从私聊切回群聊时群聊长轮询是停的，需在此重新拉起
                        if (!self._roomPollRunning) { self._roomPollRunning = true; self.startPoll(); }
                    } else if (r.need_password) {
                        // 通行授权已过期 → 重新验证，验证成功后自动重试
                        self.passForget(id);
                        self.askRoomPassword(id, name, load);
                    }
                });
            };
            load();
            this.renderRoomPanel();     // v1.1.1：右侧栏群聊设置区跟随切群刷新
            this._fireRoomSwitch();   // 插件钩子：切换群聊（公告等按群拉取）
            this._fireViewChange();   // v1.1.0：回到群聊视图 → 插件按 roomId 复原群级装饰
        },

        /* ---------- 长轮询（主通道）+ 断线降级短轮询 ----------
           v1.1.0：引入 pollGen 世代号。群聊与私聊共用同一套消息区/输入栏，
           两条长轮询必须互斥——切换视图时自增世代号，旧循环醒来即自杀，
           避免两个 in-flight 请求同时刷新同一个 #owMessages、互相覆盖 since。 */
        startPoll: function () {
            var self = this;
            var myGen = this.pollGen = (this.pollGen || 0) + 1;
            var alive = function () { return self.pollGen === myGen && !self.dm; };
            function loop() {
                if (!alive()) return;
                var roomId = self.room, since = self.since;
                var t0 = new Date().getTime();
                OwApi.post('poll', { room_id: roomId, since: since }, function (r, status) {
                    if (!alive()) return;
                    if (!r || !r.ok) {
                        // 密码房授权过期：停止轮询，重新验证后继续
                        if (r && r.need_password) {
                            self.passForget(roomId);
                            self.askRoomPassword(roomId, self.roomName || '', function () { self.startPoll(); });
                            return;
                        }
                        self.failCount++;
                        // 降级：短轮询 + 指数退避（2s → 10s 封顶）
                        var wait = Math.min(10000, 2000 * self.failCount);
                        $('owLatency').innerHTML = '重连中…';
                        $('owLatency').style.color = '#C41D1F';
                        setTimeout(loop, wait);
                        return;
                    }
                    self.failCount = 0;
                    var ms = new Date().getTime() - t0;
                    $('owLatency').innerHTML = '● ' + ms + ' ms';
                    $('owLatency').style.color = '#237804';
                    self.since = r.since;
                    var i, hasNew = false;
                    for (i = 0; i < r.messages.length; i++) {
                        self.addMessage(r.messages[i]);
                        hasNew = true;
                    }
                    if (hasNew) {
                        self.scrollBottom();
                        if (self.sound) beep();
                        // v1.1.0：当前群有消息时立刻前置该会话（其余会话由 startConvPoll 兜底）
                        self.loadConversations();
                    }
                    self.renderOnline(r.online, r.online_status);
                    setTimeout(loop, 100);
                });
            }
            loop();
        },

        /* ---------- 输入框高度：随内容自动增高 + 拖拽手柄手动拉高 ---------- */
        inputMaxH: 120,      // 自动增高上限（拖拽可上调）
        inputUserH: 0,       // 用户手动拖出的高度（0=未设置，走自动增高）
        autoGrow: function () {
            var el = $('owInput');
            if (!el) return;
            el.style.height = 'auto';
            var h = el.scrollHeight + 2;
            var min = 40;
            if (this.inputUserH > 0) {
                // 手动设定过高度：内容再多也不超过用户设定，内容少时也不缩回去
                el.style.height = Math.max(min, Math.min(Math.max(h, this.inputUserH), 320)) + 'px';
                return;
            }
            el.style.height = Math.max(min, Math.min(h, this.inputMaxH)) + 'px';
        },
        bindInputResize: function () {
            var self = this, el = $('owInput'), handle = $('owInputResize');
            if (!el || !handle) return;
            handle.onmousedown = function (e) {
                e = e || w.event;
                var startY = e.clientY, startH = el.offsetHeight;
                if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
                document.onmousemove = function (ev) {
                    ev = ev || w.event;
                    var h = startH + ((ev.clientY || 0) - startY);
                    h = Math.max(40, Math.min(h, 320));
                    self.inputUserH = h;
                    el.style.height = h + 'px';
                    try { w.localStorage.setItem('owl_input_h', String(h)); } catch (err) {}
                };
                document.onmouseup = function () {
                    document.onmousemove = null;
                    document.onmouseup = null;
                };
                return false;
            };
        },
        msgCache: {},

        // 统一构建消息 DOM：头像一侧依次是「用户组标签、昵称」；
        // 时间不直接显示，悬停气泡满 2 秒才显示；操作（@/私信/收藏/撤回等）改为右键菜单
        buildMessage: function (m) {
            var cls = 'ow-msg';
            if (m.mine) cls += ' mine';
            if (m.type === 'mention') cls += ' mention';
            if (m.type === 'private') cls += ' private';
            if (m.type === 'system') cls += ' system';
            if (m.recalled) cls += ' recalled';
            // v1.1.0 软删除：服务端已清空 content，前台只显示占位文案
            if (m.deleted) cls += ' deleted';

            var content;
            if (m.deleted) content = '<span class="ow-msg-content">该消息已删除</span>';
            else if (m.recalled) content = '<span class="ow-msg-content">此消息已撤回</span>';
            else if (m.type === 'file') content = '<span class="ow-msg-content" style="padding:4px">' + fileCardHtml(m) + '</span>';
            else if (m.type === 'image') content = '<span class="ow-msg-content" style="padding:4px"><img class="ow-msg-img" src="' + esc(m.content) + '" onclick="OwChat.viewImg(this.src)" alt="图片"></span>';
            else content = '<span class="ow-msg-content">' + (m.quote && (m.quote.nick || m.quote.text)
                    ? '<span class="ow-msg-quote' + (m.quote.id ? ' ow-quote-link' : '') + '"'
                      + (m.quote.id ? ' title="点击查看原消息" onclick="OwChat.jumpToQuote(' + (m.quote.id | 0) + ')"' : '')
                      + '><b>' + esc(m.quote.nick || '') + '</b>'
                      + (m.quote.nick ? '：' : '') + esc(m.quote.text || '') + '</span>'
                    : '') + esc(m.content) + '</span>';

            var isSys = m.type === 'system';
            // meta 行：头像一侧依次是「用户组标签、昵称」；时间不直接显示，
            // 悬停满 2 秒才显示（见 ow-time-show 类与 hideMsgTime）
            var timeHtml = '<span class="ow-msg-time">' + esc(m.date + ' ' + m.time) + '</span>';
            var mainPart = roleTag(m.role, m.title, m.uid)
                + ' <span class="ow-msg-nick" onclick="OwChat.userCard(' + (m.uid || 0) + ',\'' + esc(m.nickname) + '\')">' + esc(m.nickname) + '</span>';
            // v1.1.0：去掉昵称后的「→ 昵称」私信文字标签。
            // 私聊会话页双方已确定；群聊内的 @提及 足以定位发给人，额外标注纯噪音。
            var meta = isSys ? '' :
                '<div class="ow-msg-meta">' + mainPart + timeHtml + '</div>';

            return {
                cls: cls,
                html: (isSys ? '' : avatarHtml(m.avatar, m.nickname, false, m.role))
                    + '<div class="ow-msg-body">' + meta + content + '</div>'
            };
        },

        /** 创建群聊弹窗（用户也可创建，含后台创建房间的全部选项） */
        roomCreateModal: function () {
            var self = this;
            var TYPE = { 'public': '公开', 'password': '密码房', 'role': '角色限定' };
            var ROLE = { 'guest': '游客', 'member': '普通用户', 'vip': 'VIP', 'admin': '超级管理员' };
            var opts = function (map, keys, cur) {
                var h = '';
                for (var i = 0; i < keys.length; i++) {
                    h += '<option value="' + esc(keys[i]) + '"' + (cur === keys[i] ? ' selected' : '') + '>' + esc(map[keys[i]] || keys[i]) + '</option>';
                }
                return h;
            };
            this.openModal(
                '<h3>创建群聊</h3>'
                + '<div class="ow-form-item"><label>群名称</label><input class="ow-input" id="owRCName" maxlength="30" placeholder="2-30 个字符"></div>'
                + '<div class="ow-form-item"><label>类型</label><select class="ow-input" id="owRCType">'
                + opts(TYPE, ['public', 'password', 'role'], 'public') + '</select></div>'
                + '<div class="ow-form-item" id="owRCPassRow" style="display:none"><label>房间密码</label><input class="ow-input" type="password" id="owRCPass" placeholder="密码群必须设置密码"></div>'
                + '<div class="ow-form-item" id="owRCRoleRow" style="display:none"><label>最低进入角色</label><select class="ow-input" id="owRCRole">'
                + opts(ROLE, ['guest', 'member', 'vip', 'admin'], 'guest') + '</select></div>'
                + '<div class="ow-form-item"><label>群简介（可选）</label><input class="ow-input" id="owRCDesc" maxlength="200" placeholder="一句话介绍这个群"></div>'
                + '<div class="ow-room-form-tip" id="owRCTip"></div>'
                + '<div class="ow-form-msg" id="owRCMsg"></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owRCCancel">取消</button>'
                + '<button class="ow-btn ow-btn-primary" id="owRCCreate">创 建</button></div>'
            );
            var typeSel = $('owRCType'), tip = $('owRCTip'), msg = $('owRCMsg');
            var refreshTip = function () {
                var t = typeSel.value;
                $('owRCPassRow').style.display = t === 'password' ? 'block' : 'none';
                $('owRCRoleRow').style.display = t === 'role' ? 'block' : 'none';
            };
            typeSel.onchange = refreshTip;
            refreshTip();
            // 积分提示（创建成本由后台配置，管理员免费）
            var me = this.cfg.me || {};
            var cost = parseInt(this.cfg.settings.room_create_cost, 10) || 0;
            var pts = parseInt(me.points, 10) || 0;
            var notEnough = cost > 0 && me.role !== 'admin' && pts < cost;   // 管理员免费
            if (notEnough) {
                tip.innerHTML = '<span style="color:#C41D1F">积分不足：创建需要 <b>' + cost + '</b> 积分，当前 <b>' + pts + '</b>。</span>';
                $('owRCCreate').disabled = true;
                $('owRCCreate').style.opacity = '.5';
                $('owRCCreate').style.cursor = 'not-allowed';
            } else if (cost > 0) {
                tip.innerHTML = (me.role === 'admin')
                    ? '管理员创建免费（普通用户需 <b>' + cost + '</b> 积分，当前 ' + pts + '）。'
                    : '创建将扣除 <b>' + cost + '</b> 积分（当前 ' + pts + '）。';
            } else {
                tip.innerHTML = '创建免费。';
            }
            var submit = function () {
                if (notEnough) { msg.innerHTML = '<span style="color:#C41D1F">积分不足，无法创建</span>'; return; }
                var name = $('owRCName').value.replace(/^\s+|\s+$/g, '');
                if (name.length < 2) { msg.innerHTML = '<span style="color:#C41D1F">群名称至少 2 个字符</span>'; return; }
                var t = typeSel.value;
                if (t === 'password' && !$('owRCPass').value) {
                    msg.innerHTML = '<span style="color:#C41D1F">密码群必须设置密码</span>'; return;
                }
                msg.innerHTML = '创建中…';
                OwApi.post('room_create', {
                    name: name, type: t, password: $('owRCPass') ? $('owRCPass').value : '',
                    min_role: $('owRCRole').value,
                    description: $('owRCDesc').value
                }, function (r) {
                    if (!r.ok) { msg.innerHTML = '<span style="color:#C41D1F">' + esc(r.msg) + '</span>'; return; }
                    self.closeModal();
                    toast('群聊「' + r.name + '」已创建' + (r.cost > 0 ? '，扣除 ' + r.cost + ' 积分' : ''));
                    self.refreshRooms(r.id, r.name);
                });
            };
            $('owRCCreate').onclick = submit;
            $('owRCCancel').onclick = function () { self.closeModal(); };
        },

        /** 重新拉取房间列表并定位到指定房间 */
        refreshRooms: function (gotoId, gotoName) {
            var self = this;
            OwApi.post('rooms', {}, function (r) {
                if (!r.ok) return;
                self.cfg.rooms = r.data;
                self.loadConversations();   // v1.1.0：列表为群聊+私聊聚合，须走 conversations
                var found = null, i, j;
                for (i = 0; i < r.data.length; i++) if (r.data[i].id === gotoId) found = r.data[i];
                if (found) {
                    self.switchRoom(gotoId, found.name, null);
                } else if (gotoName) {
                    $('owRoomName').innerHTML = esc(gotoName);
                }
            });
        },

        addMessage: function (m, batch) {

            var box = $('owMessages');
            var exist = document.getElementById('owMsg' + m.id);
            if (exist) {
                // 轮询带回撤回状态时，同步更新已渲染的气泡（否则撤回后界面不变）
                if (m.recalled) this.markRecalled(m.id);
                return;
            }
            this.msgCache[m.id] = m;
            var b = this.buildMessage(m);
            var div = document.createElement('div');
            div.className = b.cls;
            div.id = 'owMsg' + m.id;
            div.innerHTML = b.html;
            box.appendChild(div);
            if (!batch && box.children.length > 500) {
                var old = box.children[1];
                var oid = parseInt((old.id || '').replace('owMsg', ''), 10);
                if (oid) delete this.msgCache[oid];
                box.removeChild(old);
            }
        },

        /** 把某条消息的气泡更新为「已撤回」状态 */
        markRecalled: function (id) {
            var el = document.getElementById('owMsg' + id);
            if (!el || el.className.indexOf('recalled') >= 0) return;
            el.className += ' recalled';
            var cs = el.querySelector('.ow-msg-content');
            if (cs) cs.outerHTML = '<span class="ow-msg-content">此消息已撤回</span>';
            this.msgCache[id] = this.msgCache[id] || {};
            this.msgCache[id].recalled = 1;
        },

        /** 自研确认弹窗（替代原生 confirm） */
        confirmModal: function (text, onOk) {
            var self = this;
            this.openModal(
                '<h3>确认操作</h3>'
                + '<p class="ow-modal-desc">' + esc(text) + '</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owCfmNo">取消</button>'
                + '<button class="ow-btn ow-btn-danger" id="owCfmOk">确定</button></div>'
            );
            $('owCfmOk').onclick = function () { self.closeModal(); if (onOk) onOk(); };
            $('owCfmNo').onclick = function () { self.closeModal(); };
        },

        scrollBottom: function () {
            var box = $('owMessages');
            box.scrollTop = box.scrollHeight;
        },

        /**
         * 加载更早消息（向上翻页）。v1.1.0：按当前视图分流——
         * 群聊走 history(room_id)，私聊走 dm_history(peer)，两者都是「取 before 之前的 30 条」。
         */
        loadHistory: function () {
            var self = this, box = $('owMessages');
            var first = box.querySelector('.ow-msg');
            if (!first) { this.historyDone = true; return; }
            var before = parseInt(first.id.replace('owMsg', ''), 10);
            var isDm = !!this.dm, peer = isDm ? this.dm.peer : '';
            var action = isDm ? 'dm_history' : 'history';
            var payload = isDm ? { peer: peer, before_id: before } : { room_id: this.room, before: before };
            this.loadingHistory = true;
            OwApi.post(action, payload, function (r) {
                self.loadingHistory = false;
                if (self.dm !== isDm || (isDm && (!self.dm || self.dm.peer !== peer))) return;  // 已切走
                if (!r.ok || !r.data.length) { self.historyDone = true; if ($('owLoadMore')) $('owLoadMore').innerHTML = '没有更早的消息了'; return; }
                var oldH = box.scrollHeight, i;
                for (i = r.data.length - 1; i >= 0; i--) {
                    self.addMessageBefore(r.data[i], first);
                }
                box.scrollTop = box.scrollHeight - oldH;
            });
        },

        addMessageBefore: function (m, ref) {
            var box = $('owMessages');
            if (document.getElementById('owMsg' + m.id)) return;
            this.msgCache[m.id] = m;
            var b = this.buildMessage(m);
            var div = document.createElement('div');
            div.className = b.cls;
            div.id = 'owMsg' + m.id;
            div.innerHTML = b.html;
            box.insertBefore(div, ref);
        },

        /* ---------- 消息右键菜单（@ / 私信 / 收藏贴纸 / 撤回，插件可扩展） ---------- */
        _ctxItems: [],
        /**
         * 消息右键菜单扩展点（供插件追加菜单项，如禁言插件的「禁言」）。
         * 回调签名：function (items, msg, env) —— 直接 items.push({t:'文案', run:fn}) 即可。
         * env：{ roomId: 当前房间ID, actor: 当前身份对象 }
         */
        _ctxExt: [],
        _ctxExtContent: [],   // 右键「内容」菜单的插件扩展
        _quoteExt: [],        // 引用内容钩子（前端侧）
        quote: null,          // 当前待发送的引用 {nick,text}
        onMsgCtx: function (fn) { if (typeof fn === 'function') this._ctxExt.push(fn); },
        /** 插件扩展点：构造引用内容时可改写（服务端另有 message.quote 钩子做最终校验） */
        onQuote: function (fn) { if (typeof fn === 'function') this._quoteExt.push(fn); },
        showCtxMenu: function (x, y, m) { this.showUserMenu(x, y, m); },
        hideCtxMenu: function () {
            var menu = $('owCtxMenu');
            if (menu) { menu.style.display = 'none'; menu._from = ''; }
        },
        /* ---------- 消息时间显隐（v1.1.0）----------
           悬停消息气泡满 2 秒才显示时间，移开立即隐藏。
           计时状态集中在这里，切换会话 / 消息区重渲染前统一 hideMsgTime()，
           避免「已经移开鼠标但定时器还在跑」导致时间凭空出现。 */
        _timeMsg: null,
        _timeTimer: null,
        hideMsgTime: function () {
            if (this._timeTimer) { clearTimeout(this._timeTimer); this._timeTimer = null; }
            if (this._timeMsg && this._timeMsg.className) {
                this._timeMsg.className = this._timeMsg.className.replace(' ow-time-show', '');
            }
            this._timeMsg = null;
        },
        /**
         * 右键「头像」的用户菜单：对该发言人的操作（@ / 私信 / 收藏 / 撤回 / 禁言…）。
         * 插件通过 OwChat.onMsgCtx 追加的项也进这里（都是针对「人」的能力）。
         * @deprecated showCtxMenu 保留为别名，兼容既有插件 / 调用
         */
        showUserMenu: function (x, y, m) {
            var self = this, admin = this.cfg.actor.role === 'admin', items = [];
            if (!m.recalled && !m.deleted) {
                items.push({ t: '@ ' + m.nickname, run: function () { self.mention(m.nickname); } });
                if (!m.mine && this.cfg.actor.kind === 'user')
                    items.push({ t: '私信', run: function () { self.openDmWith(m); } });
                // 收藏贴纸已移到内容菜单（showContentMenu）：它是对「图片」的操作，不是对「人」的操作
            }
            // 插件扩展（v1.0.54）：如禁言插件按「管理员 / 房主」身份追加菜单项；
            // IP 归属地已移出核心，插件可在此注册（服务端走 ip_loc + ip.location 钩子）
            for (i = 0; i < this._ctxExt.length; i++) {
                try { this._ctxExt[i](items, m, { roomId: this.room, actor: this.cfg.actor }); } catch (e) {}
            }
            if (!items.length) return;

            this._ctxItems = items;
            var menu = $('owCtxMenu');
            menu._from = 'msg';
            var html = '', i;
            for (i = 0; i < items.length; i++) {
                html += '<a href="javascript:;" data-i="' + i + '">' + esc(items[i].t) + '</a>';
            }
            menu.innerHTML = html;
            menu.style.display = 'block';
            // 视口边界：菜单放不下时往回挪
            var vw = w.innerWidth || document.documentElement.clientWidth;
            var vh = w.innerHeight || document.documentElement.clientHeight;
            var mw = menu.offsetWidth || 140, mh = menu.offsetHeight || items.length * 32;
            menu.style.left = Math.max(4, x + mw > vw ? x - mw : x) + 'px';
            menu.style.top = Math.max(4, y + mh > vh ? y - mh : y) + 'px';
        },

        /* ---------- 发送 ---------- */
        /**
         * 发送消息。v1.1.0：私聊视图下自动改写为「私聊消息」——
         * room_id=0（虚拟私聊空间）+ type=private + 对方标识（user:<id> / guest:<id>）。
         * 昵称仅作展示快照，不作身份；服务端以数字 ID 判定双方可见性。
         */
        send: function (opt) {
            opt = opt || {};
            var input = $('owInput');
            var content = opt.content != null ? opt.content : input.value;
            if (!content || !content.replace(/^\s+|\s+$/g, '')) return;
            var self = this;
            var payload = {
                room_id: this.room,
                type: opt.type || 'text',
                content: content,
                to_user_id: opt.to_user_id || '', to_guest_id: opt.to_guest_id || '',
                to_nickname: opt.to_nickname || '',
                // 引用快照：JSON 字符串，服务端会再次校验截断
                quote: this.quote ? JSON.stringify(this.quote) : ''
            };
            // 私聊视图：未显式指定消息类型（文本/图片/文件等富类型）时，一律发往对方
            if (this.dm && !opt.type) {
                payload.room_id = 0;
                payload.type = 'private';
                payload.to_user_id = this.dm.kind === 'user' ? this.dm.id : '';
                payload.to_guest_id = this.dm.kind === 'guest' ? this.dm.id : '';
                payload.to_nickname = this.roomName;
            }
            var wasDm = !!this.dm;
            OwApi.post('send', payload, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                if (!opt.type || opt.type === 'text') input.value = '';
                self.clearQuote();   // 发送成功后清掉引用条
                self.autoGrow();   // 发送后回到单行（若手动拉高过则保持用户高度）
                if (wasDm && self.dm) self.loadConversations();   // 刷新会话排序（自己发的排最前）
            });
        },

        /** 上传文件附件，并作为一条 file 消息发送 */
        uploadFile: function (file) {
            var self = this;
            toast('文件上传中…');
            OwApi.upload('upload_file', file, {}, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                self.send({
                    type: 'file',
                    content: JSON.stringify({
                        name: r.file.name, size: r.file.size,
                        ext: r.file.ext, path: r.file.path
                    })
                });
            });
        },

        uploadImage: function (file) {
            var self = this;
            toast('图片上传中…');
            OwApi.upload('upload', file, { kind: 'image' }, function (r) {
                if (r.ok) self.send({ type: 'image', content: r.url });
                else toast(r.msg);
            });
        },

        recall: function (id) {
            var self = this;
            this.confirmModal('确定撤回这条消息吗？', function () {
                OwApi.secure('recall', { id: id }, function (r) {
                    if (r.ok) self.markRecalled(id);
                    else toast(r.msg);
                });
            });
        },

        mention: function (nick) {
            var input = $('owInput');
            input.value += '@' + nick + ' ';
            input.focus();
            OwChat.autoGrow();
        },

        /**
         * 从一条消息进入与该作者的私聊（v1.1.0）。
         * 私聊不再用「弹窗写一条」的一次性交互，而是进入完整会话页——
         * 历史可翻、双方可继续对话，与群聊共用同一套消息区与输入栏。
         * 对象标识用 user:<id> / guest:<id>（昵称允许重名，不能当身份用）。
         */
        openDmWith: function (m) {
            var peer = m.uid ? ('user:' + m.uid) : (m.gid ? ('guest:' + m.gid) : '');
            if (!peer) { toast('无法确定私聊对象'); return; }
            this.openDm(peer, m.nickname);
        },
        /** 兼容旧调用点（插件可能仍调 pm）；v1.1.0 起统一进入私聊会话页 */
        pm: function (nick, uid, gid) {
            if (uid) return this.openDm('user:' + uid, nick);
            if (gid) return this.openDm('guest:' + gid, nick);
            toast('无法确定私聊对象');
        },

        collect: function (url) {
            OwApi.post('sticker_add', { url: url }, function (r) { toast(r.msg); });
        },

        viewImg: function (src) {
            $('owImgViewerImg').src = src;
            $('owImgViewer').style.display = '-webkit-flex';
            $('owImgViewer').style.display = 'flex';
        },

        /* ---------- 用户资料卡 ---------- */
        userCard: function (uid, nick) {
            if (!uid) { this.pmHint(nick); return; }
            var self = this;
            OwApi.post('user_card', { id: uid }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var u = r.data;
                // v1.1.0：资料卡加「发私信」入口，与头像右键菜单走同一条私聊路径
                var canPm = self.cfg.actor.kind === 'user' && self.cfg.actor.id !== u.id;
                OwChat.openModal(
                    '<h3>用户资料</h3>'
                    + '<div style="text-align:center;margin-bottom:14px">' + avatarHtml(u.avatar, u.nickname, false, u.role)
                    + '<div class="ow-me-name" style="margin-top:8px">' + esc(u.nickname) + '</div>'
                    + '<div style="margin-top:4px">' + roleTag(u.role, u.title, u.id) + '</div></div>'
                    // 用户名已取消：资料卡以用户 ID 作为唯一标识，昵称可重名只作展示
                    + '<p style="font-size:13px;color:#5C5C5C">用户 ID：' + esc(fmtUid(u.id)) + '<br>'
                    + '积分：' + esc(u.points || 0) + '<br>'
                    + '注册：' + esc(u.created_at ? new Date(u.created_at * 1000).toLocaleDateString() : '-') + '</p>'
                    + (canPm ? '<div class="ow-modal-actions">'
                        + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">关闭</button>'
                        + '<button class="ow-btn ow-btn-primary" onclick="OwChat.closeModal();OwChat.openDm(\'user:' + (u.id) + '\',' + JSON.stringify(u.nickname).replace(/"/g, '&quot;') + ')">发私信</button></div>' : '')
                );
            });
        },

        pmHint: function (nick) { toast('游客用户无法查看资料卡'); },

        /* ---------- 成员列表 ---------- */
        /**
         * 渲染成员列表。
         *
         * v1.1.1：在线状态（绿点/灰点）**仅超级管理员与群主可见**，
         * 口径由服务端 poll 返回的 online_status 决定，前端不自行判身份——
         * 否则两处判定漂移，就会重演「服务端允许、前台没有按钮」的契约不一致。
         * 成员名字对所有人可见（含游客）。
         */
        renderOnline: function (list, canStatus) {
            var box = $('owOnlineList'), cnt = $('owOnlineCount');
            if (cnt) cnt.innerHTML = list.length;
            if (!box) return;
            var html = '', i;
            for (i = 0; i < list.length; i++) {
                var o = list[i];
                html += '<li class="ow-online-item">'
                      + (canStatus ? '<span class="ow-online-dot"></span>' : '')
                      + avatarHtml(o.avatar, o.nickname, true, o.role)
                      + '<span class="ow-online-name" onclick="OwChat.userCard(' + (o.uid || 0) + ',\'' + esc(o.nickname) + '\')">' + esc(o.nickname) + '</span>'
                      + roleTag(o.role, '', o.uid) + '</li>';
            }
            box.innerHTML = html;
        },

        /* ---------- 右侧栏：群聊设置区 ---------- */
        /**
         * 渲染右侧栏上方的「群聊设置」。
         *
         * v1.1.1：原先群设置是列表项里的三点按钮 → 弹窗，现改为常驻侧栏区块。
         * 群主 / 超级管理员（room.can_edit）看到可编辑表单 + 保存按钮；
         * 普通会员与游客看到只读信息。插件入口（群公告等）通过 onRoomEdit
         * 钩子往 #owREExtras 追加，钩子契约与原弹窗版保持一致。
         */
        renderRoomPanel: function () {
            var box = $('owRoomPanel');
            if (!box) return;
            var me = this.cfg.me || {}, isAdmin = this.cfg.actor.role === 'admin';
            var isDm = this.room === 0;                    // 私聊是 room_id=0 的虚拟空间
            var r = null, list = this.cfg.rooms || [], i;
            for (i = 0; i < list.length; i++) { if (list[i].id === this.room) { r = list[i]; break; } }

            if (isDm || !r) {
                box.innerHTML = '<div class="ow-panel-hint">' + (isDm ? '私聊会话没有群聊设置' : '请先选择一个群聊') + '</div>';
                return;
            }
            var canEdit = !!r.can_edit;
            var meId = me.id || 0;
            var isOwner = !!meId && meId === (r.owner_id || 0);
            var typeName = r.type === 'password' ? '密码群' : (r.type === 'role' ? '角色限定' : '公开群');
            // 待保存头像按群缓存：切群时必须重置，否则会把上一个群的头像带过来
            if (this._roomAvatarRoom !== this.room) {
                this._roomAvatarRoom = this.room;
                this._roomAvatar = r.avatar || '';
            }

            box.innerHTML = '<div class="ow-panel-room-head">'
                + '<span class="ow-set-avatar-btn" id="owRoomAvatarPreview"'
                + (canEdit ? ' title="点击更换群头像" onclick="OwChat.roomAvatarPick()"' : '') + '>'
                + avatarHtml(this._roomAvatar, r.name, false, 'member') + '</span>'
                + '<input type="file" id="owRoomAvatarFile" accept="image/*" style="display:none">'
                + '<div class="ow-panel-room-meta"><b>' + esc(r.name) + '</b>'
                + '<span class="ow-panel-room-type">' + typeName + '</span></div></div>'
                + (canEdit
                    ? '<div class="ow-form-item"><label>群名称</label><input class="ow-input" id="owRoomEditName" value="' + esc(r.name) + '" maxlength="30"></div>'
                      + '<div class="ow-form-item"><label>群简介</label><input class="ow-input" id="owRoomEditDesc" value="' + esc(r.description || '') + '" maxlength="200" placeholder="一句话介绍这个群（可选）"></div>'
                      + '<div class="ow-form-row" id="owREExtras"></div>'
                      + '<button class="ow-btn ow-btn-primary ow-btn-block" onclick="OwChat.roomEditSave(' + r.id + ')">保存</button>'
                    // 只读：非群主会员也能看到群名称 / 简介 / 群主，信息不设限，仅不可改
                    : '<div class="ow-panel-ro">'
                      + (r.description ? '<p class="ow-panel-ro-desc">' + esc(r.description) + '</p>' : '<p class="ow-panel-ro-desc ow-panel-empty">群主还没有写简介</p>')
                      + '<div class="ow-form-row" id="owREExtras"></div>'
                      + '<p class="ow-panel-ro-tip">群主：' + esc(fmtUid(r.owner_id))
                      + (isAdmin || isOwner ? '' : '　·　仅群主与超级管理员可修改') + '</p></div>');

            var f = $('owRoomAvatarFile');
            if (f) f.onchange = function () {
                if (!this.files || !this.files[0]) return;
                var self2 = OwChat;
                self2.roomAvatarCrop(this.files[0]);   // 裁剪浮层独立，侧栏保持完好
                this.value = '';
            };
            // 插件扩展钩子（v1.0.102）：群公告等入口往 #owREExtras 追加
            var ctx = { roomId: this.room, ownerId: r.owner_id || 0, isAdmin: isAdmin, isOwner: isOwner };
            for (var hi = 0; hi < this._roomEditHooks.length; hi++) {
                try { this._roomEditHooks[hi](ctx); } catch (e) {}
            }
        },

        /* ---------- 公告轮播 ---------- */
        renderAnnounce: null,   // v1.0.102 公告已剥离为 announcements 插件（见 plugins/announcements/）

        /* ---------- 表情面板 ---------- */
        buildEmojiPanel: function () {
            var self = this, html = '<div class="ow-emoji-tabs">'
                + '<button class="ow-emoji-tab active" data-tab="emoji">Emoji</button>'
                + '<button class="ow-emoji-tab" data-tab="sticker">我的贴纸</button></div>'
                + '<div class="ow-emoji-grid" id="owEmojiGrid"></div>';
            $('owEmojiPanel').innerHTML = html;
            var tabs = $('owEmojiPanel').querySelectorAll('.ow-emoji-tab'), i;
            for (i = 0; i < tabs.length; i++) {
                tabs[i].onclick = function () {
                    var t = $('owEmojiPanel').querySelectorAll('.ow-emoji-tab'), j;
                    for (j = 0; j < t.length; j++) t[j].className = 'ow-emoji-tab';
                    this.className = 'ow-emoji-tab active';
                    self.renderEmojiGrid(this.getAttribute('data-tab'));
                };
            }
            this.renderEmojiGrid('emoji');
        },

        renderEmojiGrid: function (tab) {
            var grid = $('owEmojiGrid'), self = this, html = '', i;
            if (tab === 'emoji') {
                for (i = 0; i < this.emojis.length; i++) html += '<span class="ow-emoji-item">' + this.emojis[i] + '</span>';
                grid.innerHTML = html;
                var items = grid.getElementsByTagName('span');
                for (i = 0; i < items.length; i++) {
                    items[i].onclick = function () {
                        $('owInput').value += this.innerHTML;
                        $('owInput').focus();
                    };
                }
            } else {
                OwApi.post('stickers', {}, function (r) {
                    if (!r.ok || !r.data.length) { grid.innerHTML = '<p style="padding:20px;color:#5C5C5C;font-size:12px">暂无贴纸：把鼠标悬停在图片消息上点击「收藏贴纸」即可添加</p>'; return; }
                    for (i = 0; i < r.data.length; i++) html += '<img class="ow-sticker-item" src="' + esc(r.data[i].url) + '">';
                    grid.innerHTML = html;
                    var imgs = grid.getElementsByTagName('img');
                    for (i = 0; i < imgs.length; i++) {
                        imgs[i].onclick = function () {
                            self.send({ type: 'image', content: this.src });
                            $('owEmojiPanel').style.display = 'none';
                        };
                    }
                });
            }
        },

        /* ---------- 我的面板 / 设置 ---------- */
        renderMe: function () {
            var self = this, me = this.cfg.me, el = $('owMe');
            if (!el) return;
            if (me) {
                // 昵称与身份标签同行；整块可点击 → 弹出操作菜单（创建群聊/设置/管理后台/退出）
                // v1.0.93：侧栏不展示任何身份标签（群主/会员只在消息区、在线成员列表、资料卡显示）
                el.className = 'ow-me ow-me-click';
                el.innerHTML = avatarHtml(me.avatar, me.nickname, false, me.role)
                    + '<div class="ow-me-info">'
                    + '<div class="ow-me-line"><span class="ow-me-name">' + esc(me.nickname) + '</span>' + (me.title ? '<span class="ow-tag ow-tag-title">' + esc(me.title) + '</span>' : '') + '</div>'
                    + '<div style="font-size:11px;color:var(--ow-text-sub)">ID ' + esc(fmtUid(me.id || 0)) + ' · 积分 ' + esc(me.points || 0) + '</div></div>';
                el.onclick = function (e) {
                    // 阻止冒泡：否则 document 级「点击菜单外关闭」会立刻把刚打开的菜单关掉
                    e = e || w.event;
                    if (e.stopPropagation) e.stopPropagation(); else e.cancelBubble = true;
                    self.toggleMeMenu();
                };
            } else {
                el.className = 'ow-me';
                el.onclick = null;
                el.innerHTML = avatarHtml('', this.cfg.actor.nickname, false, 'guest')
                    + '<div><div class="ow-me-name">' + esc(this.cfg.actor.nickname) + '</div></div>';
            }
        },

        /**
         * 个人资料区操作菜单：复用消息右键菜单（owCtxMenu）的展示 / 委托点击 /
         * 点击外部与 Esc 关闭，向上弹出（资料区位于侧栏底部）。
         */
        toggleMeMenu: function () {
            var self = this, me = this.cfg.me, menu = $('owCtxMenu');
            if (!me || !menu) return;
            // 再次点击资料区 = 收起
            if (menu.style.display !== 'none' && menu._from === 'me') { this.hideCtxMenu(); return; }
            var items = [
                { t: '创建群聊', run: function () { self.roomCreateModal(); } },
                { t: '设置', run: function () { self.openSettings(); } },
            ];
            if (this.cfg.actor.role === 'admin') items.push({ t: '管理后台', run: function () { location.href = '?page=admin'; } });
            items.push({ t: '退出登录', run: function () {
                self.confirmModal('确定退出登录吗？', function () {
                    OwApi.secure('logout', {}, function () { location.href = '?page=login'; });
                });
            } });
            this._ctxItems = items;
            menu._from = 'me';
            var html = '';
            for (var i = 0; i < items.length; i++) html += '<a href="javascript:;" data-i="' + i + '">' + esc(items[i].t) + '</a>';
            menu.innerHTML = html;
            menu.style.display = 'block';
            // 定位：贴着资料区上缘，左边对齐侧栏
            var r = $('owMe').getBoundingClientRect();
            var mh = menu.offsetHeight || items.length * 34;
            menu.style.left = Math.max(4, r.left) + 'px';
            menu.style.top = Math.max(4, r.top - mh - 8) + 'px';
        },

        openSettings: function () {
            var me = this.cfg.me;
            if (!me) return;
            this.openModal(
                '<h3>个人设置</h3>'
                // 头像置顶：点击当前头像即触发上传（不另设上传按钮）
                + '<div class="ow-set-avatar">'
                + '<span id="owSetAvatarPreview" class="ow-set-avatar-btn" title="点击更换头像" onclick="document.getElementById(\'owSetAvatarFile\').click()">'
                + avatarHtml(me.avatar, me.nickname, 'xs', me.role) + '</span>'
                + '<input type="file" id="owSetAvatarFile" accept="image/*" style="display:none">'
                + '</div>'
                + '<div class="ow-form-item"><label>昵称</label><input class="ow-input" id="owSetNick" value="' + esc(me.nickname) + '">'
                + '<p style="font-size:12px;color:#5C5C5C;margin-top:4px">2-20 个字符，支持中英文、数字、下划线与短横线，不含空格或 @；允许重名。</p></div>'
                + '<button class="ow-btn ow-btn-primary ow-btn-block" onclick="OwChat.saveSettings()">保存</button>'
            );
            var self = this;
            $('owSetAvatarFile').onchange = function () {
                if (!this.files || !this.files[0]) return;
                // 选完图不直接上传：先进入裁剪弹窗，由滑块手动缩放后再导出
                self.avatarCrop(this.files[0]);
                this.value = '';
            };
        },

        /* ---------- 头像裁剪：滑块手动缩放 + 圆形取景 ---------- */

        /** 裁剪目标边长（与服务端 Upload::AVATAR_SIZE 保持一致） */
        avatarCropSize: 100,

        /**
         * 打开裁剪弹窗：圆形取景框内即最终头像，拖动滑块缩放图片。
         * @param File file 用户选择的原始图片
         */
        /**
         * 独立裁剪浮层（参考论坛 dialog 方案）：不复用 owModal——
         * 裁剪时编辑弹窗 / 后台表单保持完好，裁剪完直接回填预览。
         */
        openCropOverlay: function (html) {
            this.closeCropOverlay();
            var mask = document.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.id = 'owCropMask';
            mask.style.zIndex = '110';   // 盖在普通弹窗（z100）之上
            mask.innerHTML = '<div class="ow-modal" id="owCropModal">'
                + '<button class="ow-modal-close" onclick="OwChat.closeCropOverlay()">✕</button>' + html + '</div>';
            document.body.appendChild(mask);
        },

        closeCropOverlay: function () {
            var m = $('owCropMask');
            if (m && m.parentNode) m.parentNode.removeChild(m);
        },

        avatarCrop: function (file) {
            this._cropTarget = 'me';
            this.cropForTarget(file);
        },

        /** 群聊头像裁剪：复用同一裁剪弹窗，保存时上传到群聊头像 */
        roomAvatarCrop: function (file) {
            this._cropTarget = 'room';
            this.cropForTarget(file);
        },

        /** 按 _cropTarget 走裁剪流程（me=个人头像 / room=群聊头像） */
        cropForTarget: function (file) {
            var self = this;
            if (!w.FileReader || !document.createElement('canvas').getContext) {
                // 老浏览器无裁剪能力：退回直接上传，由服务端兜底裁方形
                if (this._cropTarget === 'room') self.roomAvatarUpload(file);
                else self.avatarUpload(file);
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                self.openCropOverlay(
                    '<h3>调整头像</h3>'
                    + '<div class="ow-crop-wrap"><canvas id="owCropCanvas" width="200" height="200"></canvas></div>'
                    + '<div class="ow-crop-ctrl">'
                    + '<input type="range" id="owCropZoom" min="1" max="3" step="0.01" value="1">'
                    + '<span class="ow-crop-val" id="owCropVal">100%</span>'
                    + '</div>'
                    + '<div class="ow-modal-actions">'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.avatarCropCancel()">取消</button>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwChat.avatarCropSave()">确定</button></div>'
                );
                var canvas = $('owCropCanvas'), zoom = $('owCropZoom'), val = $('owCropVal');
                var ctx = canvas.getContext('2d');
                var SIZE = canvas.width;                 // 200：取景框即 canvas 本身
                var img = new Image();
                var draw = function () {
                    if (!img.width || !ctx) return;
                    ctx.clearRect(0, 0, SIZE, SIZE);
                    ctx.fillStyle = '#fff';              // 白底：透明区转 jpg 不返黑
                    ctx.fillRect(0, 0, SIZE, SIZE);
                    // cover 基准：铺满画布所需最小缩放；滑块在此基础上 1~3 倍
                    var base = Math.max(SIZE / img.width, SIZE / img.height);
                    var z = parseFloat(zoom.value);
                    if (!isFinite(z) || z < 1) z = 1;
                    var s2 = base * z;
                    var dw = img.width * s2, dh = img.height * s2;
                    ctx.drawImage(img, (SIZE - dw) / 2, (SIZE - dh) / 2, dw, dh);
                    val.textContent = Math.round(z * 100) + '%';
                };
                img.onload = function () { draw(); };
                img.src = String(ev.target.result);
                zoom.oninput = draw;
                zoom.onchange = draw;                    // 老浏览器无 input 事件时兜底
            };
            reader.readAsDataURL(file);
        },

        /**
         * 右键「消息内容」的菜单：复制 / 引用 / 删除。
         * 插件可通过 OwChat.onMsgContent 追加项（如翻译、举报、复制原文…）。
         */
        showContentMenu: function (x, y, m) {
            var self = this, admin = this.cfg.actor.role === 'admin', items = [];
            if (!m.recalled && !m.deleted) {
                items.push({ t: '复制', run: function () { self.copyMsg(m); } });
                if (m.type === 'image' && this.cfg.actor.kind === 'user')
                    items.push({ t: '收藏为贴纸', run: function () { self.collect(m.content); } });
                if (this.cfg.actor.kind !== 'none')
                    items.push({ t: '引用', run: function () { self.quoteMsg(m); } });
                if (m.mine || admin)
                    items.push({ t: '撤回', run: function () { self.recall(m.id); } });
                if (m.mine || admin)
                    items.push({ t: '删除', run: function () { self.deleteMsg(m.id); } });
                for (var i = 0; i < this._ctxExtContent.length; i++) {
                    try { this._ctxExtContent[i](items, m, { roomId: this.room, actor: this.cfg.actor }); } catch (e) {}
                }
            }
            this._ctxItems = items;
            if (!items.length) return;
            var menu = $('owCtxMenu'), html = '', i2;
            menu._from = 'msg';
            for (i2 = 0; i2 < items.length; i2++) html += '<a href="javascript:;" data-i="' + i2 + '">' + esc(items[i2].t) + '</a>';
            menu.innerHTML = html;
            menu.style.display = 'block';
            var vw = w.innerWidth || document.documentElement.clientWidth, vh = w.innerHeight || document.documentElement.clientHeight;
            var mw = menu.offsetWidth || 140, mh = menu.offsetHeight || items.length * 32;
            menu.style.left = Math.max(4, x + mw > vw ? x - mw : x) + 'px';
            menu.style.top = Math.max(4, y + mh > vh ? y - mh : y) + 'px';
        },

        /** 插件扩展点：右键消息「内容」时追加菜单项 */
        onMsgContent: function (fn) { if (typeof fn === 'function') this._ctxExtContent.push(fn); },

        /** 复制消息内容（图片/文件消息复制其可读文本） */
        copyMsg: function (m) {
            var text = m.type === 'file' ? (function () {
                try { return JSON.parse(m.content).name || m.content; } catch (e) { return m.content; }
            })() : m.content;
            text = String(text || '');
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;left:-9999px;top:0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            toast(ok ? '已复制' : '复制失败，请手动选择文本');
        },

        /**
         * 引用消息：在输入框上方生成引用条（可点 × 取消）；发送时随消息提交。
         * 触发 msg.quote 钩子，插件可改写引用内容。
         */
        quoteMsg: function (m) {
            var text = m.type === 'image' ? '[图片]' : (m.type === 'file' ? (function () {
                try { return '[文件] ' + (JSON.parse(m.content).name || ''); } catch (e) { return '[文件]'; }
            })() : String(m.content || ''));
            var q = { nick: m.nickname || '', text: text, id: m.id || 0 };
            for (var i = 0; i < this._quoteExt.length; i++) {
                try { this._quoteExt[i](q, m); } catch (e) {}
            }
            this.quote = { nick: String(q.nick || '').slice(0, 40), text: String(q.text || '').slice(0, 120), id: q.id || 0 };
            this.renderQuote();
            var input = $('owInput');
            if (input) input.focus();
        },

        /** 渲染 / 清除输入框上方的引用条 */
        renderQuote: function () {
            var box = $('owQuoteBar');
            if (!box) return;
            var q = this.quote;
            var input = $('owInput');
            // 有引用时输入框顶部留白，让引用条独占输入框内第一行
            if (input) {
                if (q && (q.nick || q.text)) input.className = 'ow-input ow-has-quote';
                else input.className = 'ow-input';
                this.autoGrow();   // padding 变化后重算高度
            }
            if (!q || (!q.nick && !q.text)) { this.quote = null; box.style.display = 'none'; box.innerHTML = ''; return; }
            box.style.display = 'block';
            box.innerHTML = '<div class="ow-quote-inner"><span class="ow-quote-nick">' + esc(q.nick) + '：</span>'
                + '<span class="ow-quote-text">' + esc(q.text) + '</span>'
                + '<button class="ow-quote-del" type="button" title="取消引用" onclick="OwChat.clearQuote()">✕</button></div>';
        },

        /** 取消引用 */
        clearQuote: function () { this.quote = null; this.renderQuote(); },

        /**
         * 触发群头像文件选择（右侧栏内的隐藏 input，v1.1.1）
         */
        roomAvatarPick: function () { var f = $('owRoomAvatarFile'); if (f) f.click(); },

        /** 保存群聊设置（右侧栏内联表单，v1.1.1 起不再走弹窗） */
        roomEditSave: function (id) {
            var self = this;
            var nameEl = $('owRoomEditName'), descEl = $('owRoomEditDesc');
            if (!nameEl || !descEl) { toast('请先打开群聊信息侧栏'); return; }
            OwApi.post('room_update', {
                id: id,
                name: nameEl.value,
                description: descEl.value,
                avatar: this._roomAvatar || ''
            }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                toast('群聊信息已更新');
                self.reloadRooms();
            });
        },

        /** 重新拉取群聊列表并重渲染 */
        reloadRooms: function () {
            var self = this;
            OwApi.post('rooms', {}, function (r) {
                if (!r.ok) return;
                self.cfg.rooms = r.data;
                // v1.1.0：列表已改为「群聊+私聊」聚合，走 conversations 重新拉取，
                // 直接 renderRooms 会把私聊行冲掉。
                self.loadConversations();
                // v1.1.1：群资料已变（名称/简介/头像），侧栏设置区同步刷新。
                // _roomAvatar 不用动：保存后它与服务端值一致；renderRoomPanel
                // 只在「换了群」时才重置它（见 _roomAvatarRoom 判断）。
                self.renderRoomPanel();
            });
        },

        /**
         * 点击引用块 → 滚动到被引用的原消息并高亮闪烁。
         * 原消息不在当前页面（更早的历史未加载）时给出提示。
         */
        jumpToQuote: function (msgId) {
            var el = $('owMsg' + msgId);
            if (!el) { toast('原消息不在当前页面，请加载更早的消息'); return; }
            try {
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            } catch (e) {
                // 老浏览器无 smooth 参数：手动滚动到居中
                var box = $('owMessages'), r = el.getBoundingClientRect(), br = box.getBoundingClientRect();
                box.scrollTop += r.top - br.top - br.height / 2 + r.height / 2;
            }
            el.classList.remove('ow-msg-jump');
            // 强制重排以重启动画
            void el.offsetWidth;
            el.classList.add('ow-msg-jump');
            setTimeout(function () { el.classList.remove('ow-msg-jump'); }, 1800);
        },

        /** 删除消息（内容右键）：确认弹窗 → 物理删除 → 就地移除 */
        deleteMsg: function (id) {
            var self = this;
            this.confirmModal('确定删除这条消息吗？删除后不可恢复。', function () {
                OwApi.secure('msg_delete', { id: id }, function (r) {
                    if (!r.ok) { toast(r.msg); return; }
                    var el = $('owMsg' + id);
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                    delete self.msgCache[id];
                    toast('已删除');
                });
            });
        },

        /** 取消裁剪：仅关闭独立裁剪浮层（底下的弹窗/表单保持原状） */
        avatarCropCancel: function () {
            this.closeCropOverlay();
        },

        /** 按当前缩放导出正方形头像并上传（上传后仍需点「保存」写入资料） */
        avatarCropSave: function () {
            var self = this, canvas = $('owCropCanvas');
            if (!canvas) { toast('裁剪弹窗已关闭'); return; }
            var done = function (blob) {
                self.closeCropOverlay();
                if (!blob) { toast('当前浏览器无法处理图片，请更换浏览器'); return; }
                if (self._cropTarget === 'room') self.roomAvatarUpload(blob, 'room.jpg');
                else self.avatarUpload(blob, 'avatar.jpg');
            };
            if (canvas.toBlob) {
                canvas.toBlob(function (b) { done(b); }, 'image/jpeg', 0.9);
            } else {
                // 老浏览器：toDataURL → 手工转 Blob
                var b64 = canvas.toDataURL('image/jpeg', 0.9).split(',')[1] || '';
                var bin = w.atob ? w.atob(b64) : '';
                var arr = new Uint8Array(bin.length), i;
                for (i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                done(new Blob([arr], { type: 'image/jpeg' }));
            }
        },

        /**
         * 通用头像上传（复用用户头像上传 API：kind=avatar，服务端统一裁 100x100）。
         * 上传成功后调用 onOk(url)；UI 行为由调用方决定（个人头像 / 群聊头像）。
         */
        uploadAvatarBlob: function (file, filename, onOk) {
            var fd = new FormData();
            var s = OwApi.sign('upload');
            fd.append('ts', s.ts);
            fd.append('sign', s.sign);
            fd.append('kind', 'avatar');
            fd.append('file', file, filename || (file.name || 'avatar.jpg'));
            var x = new XMLHttpRequest();
            x.open('POST', '?action=upload', true);
            x.onreadystatechange = function () {
                if (x.readyState !== 4) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                r = r || { ok: false, msg: '网络错误' };
                if (!r.ok) { toast(r.msg); return; }
                if (onOk) onOk(r.url);
            };
            x.onerror = function () { toast('上传失败，请重试'); };
            x.send(fd);
        },

        /** 上传个人头像（file 可为 File 或 canvas 导出的 Blob），成功后刷新预览 */
        avatarUpload: function (file, filename) {
            var self = this;
            try {
                self.uploadAvatarBlob(file, filename, function (url) {
                    self.cfg.me.avatar = url;
                    var pv = $('owSetAvatarPreview');
                    if (pv) pv.innerHTML = avatarHtml(url, self.cfg.me.nickname, 'xs', self.cfg.me.role);
                    self.renderMe();
                    toast('头像已上传，点击保存生效');
                });
            } catch (e) {
                toast('上传失败，请重试');
            }
        },

        /** 上传群聊头像：右侧栏内回显（保存时随 room_update 提交） */
        roomAvatarUpload: function (file, filename) {
            var self = this;
            self.uploadAvatarBlob(file, filename, function (url) {
                self._roomAvatar = url;
                var pv = $('owRoomAvatarPreview');
                if (pv) {
                    // 侧栏用头像组件，后台表单用图片预览（裁剪浮层独立，两者都完好）
                    if (pv.getAttribute('class').indexOf('ow-set-avatar-btn') >= 0)
                        pv.innerHTML = avatarHtml(url, '', false, 'member');
                    else
                        pv.innerHTML = '<img src="' + esc(url) + '" alt="">';
                }
                toast('群头像已上传，点击保存生效');
            });
        },

        saveSettings: function () {
            var self = this;
            OwApi.post('profile_save', {
                nickname: $('owSetNick').value,
                avatar: this.cfg.me.avatar || ''
            }, function (r) {
                toast(r.msg);
                if (r.ok) { self.cfg.me.nickname = $('owSetNick').value; self.renderMe(); self.closeModal(); }
            });
        },

        /* ---------- 弹层 ---------- */
        /** 打开弹窗；width 可选（px），供内容较宽的弹窗（如群公告页面）覆盖默认 380px */
        openModal: function (html, width) {
            // 后台页（OwAdmin）没有静态浮层：动态补建（closeModal 同样兼容）
            if (!$('owModalMask') || !$('owModal')) {
                var mask = document.createElement('div');
                mask.className = 'ow-modal-mask';
                mask.id = 'owModalMask';
                mask.style.display = 'none';
                mask.innerHTML = '<div class="ow-modal" id="owModal"></div>';
                document.body.appendChild(mask);
            }
            $('owModal').style.maxWidth = width ? (parseInt(width, 10) + 'px') : '';
            $('owModal').innerHTML = '<button class="ow-modal-close" onclick="OwChat.closeModal()">✕</button>' + html;
            $('owModalMask').style.display = '-webkit-flex';
            $('owModalMask').style.display = 'flex';
        },
        closeModal: function () { $('owModalMask').style.display = 'none'; },

        /** 自研确认弹窗（v1.0.112 前台版）：替代原生 confirm——全站禁止浏览器原生弹窗 */
        confirm: function (text, onOk) {
            var mask = document.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.style.display = 'flex';
            mask.style.zIndex = 200;   // 叠在普通弹窗（z-index 100）之上
            mask.innerHTML = '<div class="ow-modal" style="width:340px;max-width:92%">'
                + '<button class="ow-modal-close">✕</button>'
                + '<h3>确认操作</h3>'
                + '<p class="ow-modal-desc">' + esc(text).replace(/\n/g, '<br>') + '</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost">取消</button>'
                + '<button class="ow-btn ow-btn-danger">确定</button></div></div>';
            document.body.appendChild(mask);
            var close = function () { if (mask.parentNode) document.body.removeChild(mask); };
            mask.querySelector('.ow-modal-close').onclick = close;
            var bs = mask.querySelectorAll('.ow-modal-actions .ow-btn');
            bs[0].onclick = close;
            bs[1].onclick = function () { close(); if (onOk) onOk(); };
            mask.onclick = function (e) { if (e.target === mask) close(); };
        },
    };

    /* ==========================================================================
       OwAdmin：管理后台
       ========================================================================== */
    var OwAdmin = {
        /**
         * 通用确认弹窗（与前台 confirmModal 同一样式，v1.0.50）。
         * 所有删除 / 卸载 / 禁用等危险操作统一调用，不再使用原生 confirm。
         */
        /* ---------- 通用列表组件（v1.0.84）：分页条 / 多选批量计数，供各管理页复用 ---------- */

        /**
         * 分页条。go 回调接收新页码。
         * @param string|Element el 分页容器
         */
        uiPager: function (el, page, total, size, go) {
            el = typeof el === 'string' ? $(el) : el;
            if (!el) return;
            var pages = Math.max(1, Math.ceil(total / size));
            if (pages <= 1) {
                el.innerHTML = '<span style="font-size:12px;color:var(--ow-text-sub)">共 ' + total + ' 条</span>';
                return;
            }
            var h = '<div class="ow-pager">';
            if (page > 1) h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="' + (page - 1) + '">上一页</button>';
            // 数字页码：当前页前后各 2 页，首末页与区间之间用省略号
            var start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
            if (start > 1) {
                h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="1">1</button>';
                if (start > 2) h += '<span class="ow-pager-dots">…</span>';
            }
            for (var i = start; i <= end; i++) {
                h += '<button type="button" class="ow-btn ' + (i === page ? 'ow-btn-primary' : 'ow-btn-ghost') + '"' + (i === page ? ' disabled' : '') + ' data-pg="' + i + '">' + i + '</button>';
            }
            if (end < pages) {
                if (end < pages - 1) h += '<span class="ow-pager-dots">…</span>';
                h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="' + pages + '">' + pages + '</button>';
            }
            if (page < pages) h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="' + (page + 1) + '">下一页</button>';
            // 页码跳转：输入页码回车直接跳
            h += '<span class="ow-pager-jump">跳至<input type="number" class="ow-pager-jump-input" min="1" max="' + pages + '" value="' + page + '">页</span>';
            h += '<span class="ow-pager-info">共 ' + total + ' 条</span>';
            h += '</div>';
            el.innerHTML = h;
            var btns = el.getElementsByTagName('button');
            for (var b = 0; b < btns.length; b++) {
                btns[b].onclick = function () {
                    var pg = parseInt(this.getAttribute('data-pg'), 10);
                    if (pg >= 1 && pg <= pages && pg !== page) go(pg);
                };
            }
            var jump = el.querySelector('.ow-pager-jump-input');
            if (jump) {
                var doJump = function () {
                    var v = parseInt(jump.value, 10);
                    if (v >= 1 && v <= pages && v !== page) go(v); else jump.value = page;
                };
                jump.onkeydown = function (e) { e = e || window.event; if (e.key === 'Enter' || e.keyCode === 13) doJump(); };
                jump.onchange = doJump;
            }
        },

        /**
         * 多选批量计数与按钮启停。约定：行复选框 class=chkCls，按钮 id 在 btnIds。
         * @return number 已选数量
         */
        uiBatchSync: function (chkCls, btnIds, statEl, base) {
            var boxes = document.getElementsByClassName(chkCls), n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            for (var j = 0; j < btnIds.length; j++) { var b = $(btnIds[j]); if (b) b.disabled = n === 0; }
            if (statEl) {
                statEl = typeof statEl === 'string' ? $(statEl) : statEl;
                if (statEl) statEl.textContent = n ? base + '，已选 ' + n + ' 条' : base;
            }
            return n;
        },

        confirm: function (text, onOk) {
            var mask = document.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.style.display = 'flex';
            mask.innerHTML = '<div class="ow-modal" style="width:340px;max-width:92%">'
                + '<button class="ow-modal-close">✕</button>'
                + '<h3>确认操作</h3>'
                + '<p class="ow-modal-desc">' + esc(text).replace(/\n/g, '<br>') + '</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost">取消</button>'
                + '<button class="ow-btn ow-btn-danger">确定</button></div></div>';
            document.body.appendChild(mask);
            var close = function () { if (mask.parentNode) document.body.removeChild(mask); };
            mask.querySelector('.ow-modal-close').onclick = close;
            var bs = mask.querySelectorAll('.ow-modal-actions .ow-btn');
            bs[0].onclick = close;
            bs[1].onclick = function () { close(); if (onOk) onOk(); };
            mask.onclick = function (e) { if (e.target === mask) close(); };
        },
        init: function (opt) {
            OwApi.key = opt.key;
            OwApi.setServerTime(opt.ts);
            var menu = $('owAdminMenu'), self = this;
            // 移动端抽屉：顶栏汉堡开合 + 遮罩点击收起 + 回到桌面宽度自动复位
            var side = $('owAdminSide'), mask = $('owAdminMask');
            var setSide = function (open) {
                if (!side) return;
                side.className = 'ow-admin-side' + (open ? ' open' : '');
                if (mask) mask.style.display = open ? 'block' : 'none';
            };
            if ($('owAdminToggle')) $('owAdminToggle').onclick = function () { setSide(side.className.indexOf('open') < 0); };
            if (mask) mask.onclick = function () { setSide(false); };
            window.onresize = function () {
                if ((document.documentElement.clientWidth || window.innerWidth || 1024) > 720) setSide(false);
            };
            var items = menu.getElementsByTagName('li'), i;
            // 记忆当前页面：启停插件等操作刷新后停留在原页面，而不是跳回默认页
            var remember = function (ap) {
                try { sessionStorage.setItem('owAdminPage', ap); } catch (e) {}
            };
            for (i = 0; i < items.length; i++) {
                items[i].onclick = function () {
                    var ap = this.getAttribute('data-apage'), all = menu.getElementsByTagName('li'), j;
                    // ① 只摘掉选中态，保留分组/子项/展开等布局类（否则子菜单会被一起抹掉）
                    for (j = 0; j < all.length; j++) {
                        all[j].className = trimCls(all[j].className.replace(/\bactive\b/g, ''));
                    }
                    // ② 插件分类：点标题自行折叠/展开；点插件子页面时保持展开
                    if (ap === 'plugins') self.togglePluginSub(false);
                    else if (ap.indexOf('plugin:') === 0) self.togglePluginSub(true);
                    // ③ 选中态最后加，避免被上面的类名重置覆盖
                    this.className += ' active';
                    remember(ap);
                    self.page(ap);
                    setSide(false);   // 移动端点完菜单收起抽屉
                };
            }
            /* 恢复上次所在页面（启停插件刷新后不跳回默认页）；无记录时进群聊管理 */
            var lastPage = 'rooms';
            try { lastPage = sessionStorage.getItem('owAdminPage') || 'rooms'; } catch (e) {}
            try { if (sessionStorage.getItem('owAdminPluginsOpen') === '1') self.togglePluginSub(true); } catch (e) {}
            // 摘掉服务端预置的默认选中态（rooms），避免双高亮
            var all0 = menu.getElementsByTagName('li'), k0;
            for (k0 = 0; k0 < all0.length; k0++) {
                all0[k0].className = trimCls(all0[k0].className.replace(/\bactive\b/g, ''));
            }
            if (menu.querySelector('li[data-apage="' + lastPage + '"]')) {
                if (lastPage.indexOf('plugin:') === 0) self.togglePluginSub(true);
                var li0 = menu.querySelector('li[data-apage="' + lastPage + '"]');
                li0.className += ' active';
                this.page(lastPage);
            } else {
                this.page('rooms');
            }
        },

        /**
         * 展开/收起「插件管理」下的插件子页面
         * @param {boolean} forceOpen true=强制展开（点击插件子页面时用），false=切换
         */
        togglePluginSub: function (forceOpen) {
            var menu = $('owAdminMenu'), all = menu.getElementsByTagName('li'), i, el, group = null;
            for (i = 0; i < all.length; i++) {
                if (all[i].className.indexOf('ow-admin-group') >= 0) { group = all[i]; break; }
            }
            if (!group) return;   // 没有任何插件声明后台页面 → 插件管理是普通菜单项
            var willOpen = forceOpen ? true : group.className.indexOf('ow-group-open') < 0;
            group.className = trimCls((group.className.replace(/\bow-group-open\b/g, ''))
                + (willOpen ? ' ow-group-open' : ''));
            for (i = 0; i < all.length; i++) {
                el = all[i];
                if (el.className.indexOf('ow-admin-sub') < 0) continue;
                el.className = trimCls((el.className.replace(/\bow-sub-open\b/g, ''))
                    + (willOpen ? ' ow-sub-open' : ''));
            }
            // 记录展开状态：刷新后恢复
            try {
                if (willOpen) sessionStorage.setItem('owAdminPluginsOpen', '1');
                else sessionStorage.removeItem('owAdminPluginsOpen');
            } catch (e) {}
        },

        page: function (name) {
            var main = $('owAdminMain');
            var M = OwAdmin.pages[name];
            // 移动端适配：主区内任何表格自动套横滚容器（含异步渲染与插件页）
            if (!main._tableObserver) {
                main._tableObserver = new MutationObserver(function () {
                    var tables = main.querySelectorAll('table.ow-table');
                    for (var i = 0; i < tables.length; i++) {
                        var t = tables[i];
                        if (t.parentNode.className !== 'ow-table-wrap') {
                            var w = document.createElement('div');
                            w.className = 'ow-table-wrap';
                            t.parentNode.insertBefore(w, t);
                            w.appendChild(t);
                        }
                    }
                });
                main._tableObserver.observe(main, { childList: true, subtree: true });
            }
            if (name.indexOf('plugin:') === 0) {
                OwApi.post('admin_plugin_page', { slug: name.substr(7) }, function (r) {
                    main.innerHTML = r.ok ? r.html : '<div class="ow-card">' + esc(r.msg) + '</div>';
                });
                return;
            }
            if (M) M(main);
        },

        pages: {
            /* 用户管理（v1.0.44）、禁言管理（v1.0.52）、系统公告（v1.0.102）、
               敏感词过滤（v1.0.104）已剥离为插件，见 plugins/ 对应目录 */
            plugins: function (main) {
                OwApi.post('admin_plugins', {}, function (r) {
                    var h = '<h2>插件管理</h2><p class="ow-admin-desc">安装（上传 zip）、启用 / 停用、下载与卸载插件。插件存放于 plugins/ 目录。</p>'
                        + '<div class="ow-card ow-upload-row">'
                        + '<input type="file" id="owPluginZip" accept=".zip" style="display:none">'
                        + '<button type="button" class="ow-btn ow-btn-ghost" onclick="document.getElementById(\'owPluginZip\').click()">选择文件</button>'
                        + '<span class="ow-upload-name" id="owPluginZipName">未选择文件</span>'
                        + '<button type="button" class="ow-btn ow-btn-primary" style="margin-left:auto" onclick="OwAdmin.pluginInstall()">上传安装</button>'
                        + '</div>'
                        + '<div class="ow-plugin-list">';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<div class="ow-plugin-card">'
                           + '<div class="ow-plugin-head"><b>' + esc(d.name) + '</b>'
                           + (d.enabled ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">未启用</span>') + '</div>'
                           + '<div class="ow-plugin-meta">' + esc(d.id) + ' · v' + esc(d.version) + ' · ' + esc(d.source || '本地')
                           + ' · 钩子 ' + (d.hooks || 0) + ' · 路由 ' + (d.routes || 0) + ' · 后台页 ' + (d.pages || 0) + '</div>'
                           + '<div class="ow-plugin-desc">' + esc(d.description || '') + '</div>'
                           + '<div class="ow-plugin-actions">'
                           + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.pluginToggle(\'' + esc(d.id) + '\',1)"' + (d.enabled ? ' disabled' : '') + '>启用</button>'
                           + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.pluginToggle(\'' + esc(d.id) + '\',0)"' + (d.enabled ? '' : ' disabled') + '>停用</button>'
                           + '<a class="ow-btn ow-btn-ghost" href="?action=admin_plugin_download&name=' + esc(d.id) + '">下载</a>'
                           + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.pluginUninstall(\'' + esc(d.id) + '\')">卸载</button>'
                           + '</div></div>';
                    }
                    if (!r.data.length) h += '<div class="ow-card" style="color:#5C5C5C">暂无插件</div>';
                    main.innerHTML = h + '</div>';
                    /* 自研上传控件：隐藏原生 file input，选择后回显文件名 */
                    var zip = $('owPluginZip');
                    if (zip) zip.onchange = function () {
                        var name = $('owPluginZipName');
                        if (this.files && this.files[0]) {
                            name.textContent = this.files[0].name;
                            name.className = 'ow-upload-name ow-has-file';
                        } else {
                            name.textContent = '未选择文件';
                            name.className = 'ow-upload-name';
                        }
                    };
                });
            },
            rooms: function (main) {
                // 群聊审核（v1.0.84）：服务端分页 + 搜索 + 多选批量 + 回收站可撤销
                OwAdmin._roomPage = 1;
                OwAdmin._roomTrashPage = 1;
                main.innerHTML = '<h2>群聊审核</h2><p class="ow-admin-desc">对群聊做合规处置：名称 / 头像不合法可重置，违规群聊可封禁或删除。所有处置均可在回收站撤销。</p>'
                    + '<div class="ow-card"><div class="ow-form-row">'
                    + '<div class="ow-form-item" style="min-width:120px"><label>房主用户ID</label>'
                    + '<input class="ow-input" id="owRVRoomOwner" type="number" min="0" placeholder="0=全部" value="0" onkeydown="if(event.key===\'Enter\')OwAdmin.roomLoad(1)"></div>'
                    + '<div class="ow-form-item" style="min-width:120px"><label>群聊ID</label>'
                    + '<input class="ow-input" id="owRVRoomId" type="number" min="0" placeholder="0=全部" value="0" onkeydown="if(event.key===\'Enter\')OwAdmin.roomLoad(1)"></div>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.roomLoad(1)">搜索</button>'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.roomResetFilter()">重置</button>'
                    + '</div></div>'
                    + '<div class="ow-card">'
                    + '<div class="ow-admin-batch">'
                    + '<button class="ow-btn ow-btn-ghost" id="owRVBatchName" onclick="OwAdmin.roomBatch(\'reset_name\')" disabled>批量重置名称</button>'
                    + '<button class="ow-btn ow-btn-ghost" id="owRVBatchAvatar" onclick="OwAdmin.roomBatch(\'reset_avatar\')" disabled>批量重置头像</button>'
                    + '<button class="ow-btn ow-btn-ghost" id="owRVBatchBan" onclick="OwAdmin.roomBatch(\'toggle_status\')" disabled>批量封禁</button>'
                    + '<button class="ow-btn ow-btn-danger" id="owRVBatchDel" onclick="OwAdmin.roomBatch(\'delete\')" disabled>批量删除</button>'
                    + '<span id="owRVStat" style="color:var(--ow-text-sub);font-size:12px"></span>'
                    + '</div>'
                    + '<div class="ow-table-wrap"><table class="ow-table" id="owRVTable"></table></div>'
                    + '<div id="owRVPager" style="margin-top:10px"></div></div>'
                    + '<div class="ow-card"><h3 style="margin:0 0 10px;font-size:14px">审核回收站</h3>'
                    + '<div class="ow-table-wrap"><table class="ow-table" id="owRoomTrash"></table></div>'
                    + '<div id="owTrashPager" style="margin-top:10px"></div>'
                    + '<p style="font-size:12px;color:#5C5C5C;margin:8px 0 0">撤销有顺序依赖：群聊被删除后，需先撤销「删除」才能恢复其之前的名称 / 头像 / 封禁状态。</p></div>';
                OwAdmin.roomLoad(1);
                OwAdmin.roomTrashLoad(1);
            },
logs: function (main) {
                OwApi.post('admin_logs', {}, function (r) {
                    var h = '<h2>安全日志</h2><p class="ow-admin-desc">记录登录、注册等关键操作的 IP 与请求数据（已脱敏）。</p>'
                        + '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>动作</th><th>操作者</th><th>IP</th><th>数据</th><th>时间</th></tr>';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<tr><td>' + d.id + '</td><td>' + esc(d.action) + '</td><td>' + esc(d.actor || '') + '</td><td>' + esc(d.ip || '') + '</td>'
                           + '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(d.data || '') + '</td>'
                           + '<td>' + new Date(d.created_at * 1000).toLocaleString() + '</td></tr>';
                    }
                    main.innerHTML = h + '</table></div>';
                });
            },
            settings: function (main) {
                OwApi.post('admin_settings_get', {}, function (r) {
                    var d = r.data;
                    function sel(k, opts) {
                        var h = '<select class="ow-input" id="owS_' + k + '">';
                        for (var v in opts) h += '<option value="' + v + '"' + (d[k] === v ? ' selected' : '') + '>' + opts[v] + '</option>';
                        return h + '</select>';
                    }
                    main.innerHTML = '<h2>系统设置</h2><p class="ow-admin-desc">站点、注册控制、游客与发言限制、存储方式。</p><div class="ow-card">'
                        + '<div class="ow-form-item"><label>站点名称</label><input class="ow-input" id="owS_site_name" value="' + esc(d.site_name || '') + '"></div>'
                        + '<div class="ow-form-item"><label>固定网站地址</label><input class="ow-input" id="owS_site_url" value="' + esc(d.site_url || '') + '" placeholder="留空自动识别"></div>'
                        + '<div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>开放注册</label>' + sel('allow_register', { '1': '开放', '0': '关闭' }) + '</div>'
                        + '<div class="ow-form-item"><label>注册需邮箱验证</label>' + sel('reg_email_verify', { '1': '需要', '0': '不需要' }) + '</div>'
                        + '<div class="ow-form-item"><label>游客可浏览</label>' + sel('guest_browse', { '1': '允许', '0': '禁止' }) + '</div>'
                        + '<div class="ow-form-item"><label>游客可发言</label>' + sel('guest_chat', { '1': '允许', '0': '禁止' }) + '</div>'
                        + '</div><div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>游客发言间隔(秒)</label><input class="ow-input" id="owS_guest_msg_interval" value="' + esc(d.guest_msg_interval || '30') + '"></div>'
                        + '<div class="ow-form-item"><label>发言频率窗口(秒)</label><input class="ow-input" id="owS_msg_rate_window" value="' + esc(d.msg_rate_window || '10') + '"></div>'
                        + '<div class="ow-form-item"><label>窗口内最大条数</label><input class="ow-input" id="owS_msg_rate_max" value="' + esc(d.msg_rate_max || '8') + '"></div>'
                        + '<div class="ow-form-item"><label>邮件发送间隔(秒)</label><input class="ow-input" id="owS_mail_rate_limit" value="' + esc(d.mail_rate_limit || '60') + '"></div>'
                        + '</div>'
                        + '<div class="ow-form-item"><label>密码房通行缓存(秒)</label><input class="ow-input" id="owS_room_pass_ttl" value="' + esc(d.room_pass_ttl || '1800') + '">'
                        + '<p style="font-size:12px;color:#5C5C5C;margin-top:4px">验证一次密码后，该时间内进入同一房间无需重复输入；填 0 表示每次进入都要输入。</p></div>'
                        + '<div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>登录失败几次后要求验证码</label><input class="ow-input" id="owS_login_fail_captcha" value="' + esc(d.login_fail_captcha || '3') + '"></div>'
                        + '<div class="ow-form-item"><label>登录失败几次后锁定</label><input class="ow-input" id="owS_login_fail_lock" value="' + esc(d.login_fail_lock || '10') + '"></div>'
                        + '<div class="ow-form-item"><label>锁定时长(分钟)</label><input class="ow-input" id="owS_login_lock_minutes" value="' + esc(d.login_lock_minutes || '15') + '"></div>'
                        + '</div>'
                        + '<div class="ow-form-item"><label>注册最低年龄(周岁)</label><input class="ow-input" id="owS_min_register_age" value="' + esc(d.min_register_age || '0') + '">'
                        + '<p style="font-size:12px;color:#5C5C5C;margin-top:4px">填 0 表示不限制；填 18 则注册时必须选择出生日期且年满 18 周岁（按日期精确计算）。</p></div>'
                        + '<div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>允许上传文件</label>' + sel('file_upload', { '1': '允许', '0': '禁止' }) + '</div>'
                        + '<div class="ow-form-item"><label>单文件大小上限(MB)</label><input class="ow-input" id="owS_file_max_size" value="' + esc(d.file_max_size || '10') + '"></div>'
                        + '</div>'
                        + '<div class="ow-form-item"><label>允许的文件扩展名</label><input class="ow-input" id="owS_file_exts" value="' + esc(d.file_exts || 'zip,rar,7z,pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4') + '">'
                        + '<p style="font-size:12px;color:#5C5C5C;margin-top:4px">逗号分隔。只有内置安全类型表内登记过的扩展名才会生效；'
                        + 'svg/php/html 等可执行或可内嵌脚本的类型不予登记（即使填了也不会放行）。</p></div>'
                        + '<p style="font-size:12px;color:#5C5C5C;margin-bottom:12px">登录保护：验证码填错也计入失败次数（保证锁定可达），锁定按「账号+IP」记录，成功后清零。全部填 0 表示关闭对应保护。</p>'
                        + '<div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>允许用户创建群聊</label>' + sel('room_create_allow', { '1': '允许', '0': '仅管理员' }) + '</div>'
                        + '<div class="ow-form-item"><label>创建群聊扣除积分</label><input class="ow-input" id="owS_room_create_cost" value="' + esc(d.room_create_cost || '0') + '"></div>'
                        + '</div>'
                        + '<p style="font-size:12px;color:#5C5C5C;margin-bottom:12px">创建群聊：填 0 表示免费创建；管理员创建始终免费。用户创建的群聊 owner 归属创建者，可在群聊管理中调整。</p>'
                        // v1.1.0 软删除：删除消息只清空正文并留行（供审计），到期才物理清除
                        + '<div class="ow-form-item"><label>已删除消息保留期(天)</label><input class="ow-input" id="owS_msg_deleted_retain_days" value="' + esc(d.msg_deleted_retain_days || '30') + '"></div>'
                        + '<p style="font-size:12px;color:#5C5C5C;margin:4px 0 12px">删除消息时正文立即清空（原文不可恢复），但记录行会保留到本期限满后物理清除，'
                        + '期间仍可用于审计（谁在何时删了谁的消息）。填 0 表示永久保留、永不物理删除。</p>'
                        + '<div class="ow-form-item"><label>新消息提示音默认</label>' + sel('sound_default', { '1': '开', '0': '关' }) + '</div>'
                        + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.settingsSave()">保存设置</button></div>';
                });
            },
            /* 禁言管理自 v1.0.52 起剥离为插件 ban-manager，页面与交互见 plugins/ban-manager/ */
        },

        /* ---------- 用户管理动作已随 v1.0.44 剥离为插件（OwUM，plugins/user-manager/） ---------- */

        /* ---------- 房间动作 ---------- */
        /** 群聊审核动作：重置名称 / 恢复默认头像 / 封禁解封 */
        /** 审核回收站：列出最近处置，可撤销 */
        roomTrash: function () {
            OwApi.post('admin_room_trash_list', {}, function (r) {
                var el = document.getElementById('owRoomTrash');
                if (!el) return;
                var ACT = { reset_name: '重置名称', reset_avatar: '重置头像', toggle_status: '封禁/解封', delete: '删除' };
                if (!r.data.length) { el.innerHTML = '<span style="font-size:13px;color:#5C5C5C">暂无审核记录</span>'; return; }
                var h = '<table class="ow-table"><tr><th>时间</th><th>群聊</th><th>操作</th><th>操作前</th><th>状态</th><th></th></tr>';
                for (var i = 0; i < r.data.length; i++) {
                    var d = r.data[i];
                    var before = d.before_data && d.before_data.row ? '（整条群聊记录）'
                        : (d.before_data && typeof d.before_data === 'object' ? esc(Object.keys(d.before_data).map(function (k) { return k + '=' + d.before_data[k]; }).join('，')) : '-');
                    h += '<tr><td>' + new Date(d.created_at * 1000).toLocaleString() + '</td>'
                       + '<td>' + esc(d.room_name) + '（' + esc(fmtUid(d.room_id)) + '）</td>'
                       + '<td>' + esc(ACT[d.action] || d.action) + '</td>'
                       + '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + before + '</td>'
                       + '<td>' + (d.undone == 1 ? '<span style="color:#5C5C5C">已撤销</span>' : '-') + '</td>'
                       + '<td>' + (d.undone == 1 ? '' : '<a href="javascript:;" onclick="OwAdmin.roomTrashUndo(' + d.id + ')">撤销</a>') + '</td></tr>';
                }
                el.innerHTML = h + '</table>'
                    + '<p style="font-size:12px;color:#5C5C5C;margin:8px 0 0">撤销有顺序依赖：群聊被删除后，需先撤销「删除」才能恢复其之前的名称 / 头像 / 封禁状态。</p>';
            });
        },
        roomTrashUndo: function (id) {
            OwAdmin.confirm('确定撤销该审核操作？', function () {
                OwApi.secure('admin_room_trash_undo', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) { OwAdmin.roomTrashLoad(OwAdmin._roomTrashPage || 1); OwAdmin.roomLoad(OwAdmin._roomPage || 1); }
                });
            });
        },
        /* ---------- 群聊审核：搜索过滤 / 多选批量（v1.0.83） ---------- */

        /** 按房主ID / 群聊ID 过滤并渲染表格 */
        roomLoad: function (page) {
            this._roomPage = page || this._roomPage || 1;
            var self = this;
            OwApi.post('admin_rooms', {
                page: this._roomPage, size: 20,
                owner: ($('owRVRoomOwner') || {}).value || 0,
                rid: ($('owRVRoomId') || {}).value || 0
            }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var d = r.data;
                self._roomAll = d.list;
                var h = '';
                for (var i = 0; i < d.list.length; i++) {
                    var d2 = d.list[i];
                    var av = d2.avatar
                        ? '<img src="' + esc(d2.avatar) + '" style="width:28px;height:28px;border-radius:50%;object-fit:cover;display:block">'
                        : '<span style="display:block;width:28px;height:28px;border-radius:50%;background:var(--ow-bg-sub);color:var(--ow-text-sub);font-size:12px;line-height:28px;text-align:center">' + esc(d2.name.charAt(0)) + '</span>';
                    h += '<tr><td><input type="checkbox" class="owRVChk" value="' + d2.id + '" onchange="OwAdmin.roomSyncBatch()"></td>'
                       + '<td>' + esc(fmtUid(d2.id)) + '</td><td>' + av + '</td><td>' + esc(d2.name) + '</td>'
                       + '<td>' + (d2.owner_id ? esc(fmtUid(d2.owner_id)) : '-') + '</td>'
                       + '<td>' + (d2.status == 1 ? '正常' : '<span style="color:#C41D1F">已封禁</span>') + '</td>'
                       + '<td style="white-space:nowrap">'
                       + '<a href="javascript:;" onclick="OwAdmin.roomReview(' + d2.id + ',\'reset_name\')">名称不合法</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.roomReview(' + d2.id + ',\'reset_avatar\')">头像不合法</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.roomReview(' + d2.id + ',\'toggle_status\')">' + (d2.status == 1 ? '封禁' : '解封') + '</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.roomDel(' + d2.id + ')">删除</a></td></tr>';
                }
                $('owRVTable').innerHTML = '<tr><th style="width:32px"><input type="checkbox" id="owRVCheckAll" onchange="OwAdmin.roomToggleAll(this)"></th>'
                    + '<th>ID</th><th>头像</th><th>名称</th><th>房主ID</th><th>状态</th><th>操作</th></tr>'
                    + (h || '<tr><td colspan="7" style="color:var(--ow-text-sub)">无匹配的群聊</td></tr>');
                self.uiPager('owRVPager', d.page, d.total, d.size, function (pg) { self.roomLoad(pg); });
                self.roomSyncBatch();
            });
        },

        roomResetFilter: function () {
            $('owRVRoomOwner').value = '0';
            $('owRVRoomId').value = '0';
            this.roomLoad(1);
        },

        roomToggleAll: function (cb) {
            var boxes = document.getElementsByClassName('owRVChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            this.roomSyncBatch();
        },

        roomSyncBatch: function () {
            this.uiBatchSync('owRVChk', ['owRVBatchName', 'owRVBatchAvatar', 'owRVBatchBan', 'owRVBatchDel'], 'owRVStat', '共 ' + ((this._roomAll || []).length) + ' 条');
        },

        /** 批量审核（v1.0.83 补实现 v1.0.91）：act = reset_name / reset_avatar / toggle_status / delete */
        roomBatch: function (act) {
            var boxes = document.getElementsByClassName('owRVChk'), ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(parseInt(boxes[i].value, 10) || 0);
            if (!ids.length) { toast('未选择群聊'); return; }
            var ACT = { reset_name: '批量重置名称', reset_avatar: '批量重置头像', toggle_status: '批量封禁/解封', delete: '批量删除' };
            var self = this;
            this.confirm('确定对已选 ' + ids.length + ' 个群聊执行「' + (ACT[act] || act) + '」？', function () {
                OwApi.secure('admin_room_batch', { act: act, ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) {
                        self.roomLoad(self._roomPage || 1);
                        self.roomTrashLoad(self._roomTrashPage || 1);
                    }
                });
            });
        },

        /** 审核回收站：服务端分页列出最近处置，可撤销 */
        roomTrashLoad: function (page) {
            this._roomTrashPage = page || this._roomTrashPage || 1;
            var self = this;
            OwApi.post('admin_room_trash_list', { page: this._roomTrashPage, size: 20 }, function (r) {
                var el = document.getElementById('owRoomTrash');
                if (!el || !r.ok) return;
                var d = r.data;
                var ACT = { reset_name: '重置名称', reset_avatar: '重置头像', toggle_status: '封禁/解封', delete: '删除' };
                if (!d.list.length) { el.innerHTML = '<tr><td colspan="6" style="color:var(--ow-text-sub)">暂无审核记录</td></tr>'; }
                else {
                    var h = '';
                    for (var i = 0; i < d.list.length; i++) {
                        var t = d.list[i];
                        var before = t.before_data && t.before_data.row ? '（整条群聊记录）'
                            : (t.before_data && typeof t.before_data === 'object' ? esc(Object.keys(t.before_data).map(function (k) { return k + '=' + t.before_data[k]; }).join('，')) : '-');
                        h += '<tr><td>' + new Date(t.created_at * 1000).toLocaleString() + '</td>'
                           + '<td>' + esc(t.room_name) + '（' + esc(fmtUid(t.room_id)) + '）</td>'
                           + '<td>' + esc(ACT[t.action] || t.action) + '</td>'
                           + '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + before + '</td>'
                           + '<td>' + (t.undone == 1 ? '<span style="color:#5C5C5C">已撤销</span>' : '-') + '</td>'
                           + '<td>' + (t.undone == 1 ? '' : '<a href="javascript:;" onclick="OwAdmin.roomTrashUndo(' + t.id + ')">撤销</a>') + '</td></tr>';
                    }
                    el.innerHTML = h;
                }
                self.uiPager('owTrashPager', d.page, d.total, d.size, function (pg) { self.roomTrashLoad(pg); });
            });
        },

        roomReview: function (id, act) {
            var tips = {
                reset_name: '确定该群聊名称不合法？将重置为「未命名群聊」。',
                reset_avatar: '确定该群聊头像不合法？将恢复默认头像。',
                toggle_status: ''
            };
            var run = function () {
                OwApi.post('admin_room_review', { id: id, act: act }, function (r) {
                    toast(r.msg);
                    if (r.ok) { OwAdmin.roomTrashLoad(OwAdmin._roomTrashPage || 1); OwAdmin.roomLoad(OwAdmin._roomPage || 1); }
                });
            };
            if (tips[act]) OwAdmin.confirm(tips[act], run);
            else run();
        },
        roomDel: function (id) {
            OwAdmin.confirm('确定删除该群聊？删除后可在「审核回收站」撤销恢复。', function () {
                OwApi.secure('admin_room_del', { id: id }, function (r) { toast(r.msg); OwAdmin.page('rooms'); });
            });
        },

        /* ---------- 其他动作（banAdd/banDel 已随 v1.0.52 剥离为插件 ban-manager；
           wordAdd/wordToggle/wordDel 已随 v1.0.104 剥离为插件 sensitive-words） ---------- */
        /* 系统公告管理（v1.0.102）已随公告剥离为 announcements 插件 */
        pluginToggle: function (name, en) {
            OwApi.post('admin_plugin_toggle', { name: name, enabled: en }, function (r) {
                toast(r.msg);
                // 启停改变侧栏子菜单与可用页面，整页刷新保证状态一致
                setTimeout(function () { location.reload(); }, 500);
            });
        },
        /* 卸载：删除插件目录，二次确认后执行 */
        pluginUninstall: function (name) {
            OwAdmin.confirm('确定卸载插件「' + name + '」吗？\n将停用并删除 plugins/' + name + ' 目录，不可恢复！', function () {
                OwApi.secure('admin_plugin_uninstall', { name: name }, function (r) {
                    toast(r.msg);
                    setTimeout(function () { location.reload(); }, 500);
                });
            });
        },
        pluginInstall: function () {
            var f = $('owPluginZip');
            if (!f.files || !f.files[0]) { toast('请选择 zip 文件'); return; }
            OwApi.upload('admin_plugin_install', f.files[0], {}, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('plugins'); });
        },
        settingsSave: function () {
            OwApi.post('admin_settings_save', {
                site_name: $('owS_site_name').value,
                site_url: $('owS_site_url') ? $('owS_site_url').value : '',
                allow_register: $('owS_allow_register').value,
                reg_email_verify: $('owS_reg_email_verify').value,
                guest_browse: $('owS_guest_browse').value,
                guest_chat: $('owS_guest_chat').value,
                guest_msg_interval: $('owS_guest_msg_interval') ? $('owS_guest_msg_interval').value : '',
                msg_deleted_retain_days: $('owS_msg_deleted_retain_days') ? $('owS_msg_deleted_retain_days').value : '',
                msg_rate_window: $('owS_msg_rate_window').value,
                msg_rate_max: $('owS_msg_rate_max').value,
                mail_rate_limit: $('owS_mail_rate_limit').value,
                room_pass_ttl: $('owS_room_pass_ttl') ? $('owS_room_pass_ttl').value : '',
                min_register_age: $('owS_min_register_age') ? $('owS_min_register_age').value : '',
                file_upload: $('owS_file_upload') ? $('owS_file_upload').value : '',
                file_max_size: $('owS_file_max_size') ? $('owS_file_max_size').value : '',
                file_exts: $('owS_file_exts') ? $('owS_file_exts').value : '',
                room_create_allow: $('owS_room_create_allow') ? $('owS_room_create_allow').value : '',
                room_create_cost: $('owS_room_create_cost') ? $('owS_room_create_cost').value : '',
                login_fail_captcha: $('owS_login_fail_captcha') ? $('owS_login_fail_captcha').value : '',
                login_fail_lock: $('owS_login_fail_lock') ? $('owS_login_fail_lock').value : '',
                login_lock_minutes: $('owS_login_lock_minutes') ? $('owS_login_lock_minutes').value : '',
                sound_default: $('owS_sound_default').value
            }, function (r) { toast(r.msg); });
        }
    };

    w.OwAuth = OwAuth;
    w.OwChat = OwChat;
    w.OwAdmin = OwAdmin;
    w.OwApi = OwApi;   // 暴露给插件脚本（如用户管理插件 OwUM）使用
    // 通用助手同样暴露：插件脚本与主程序共用渲染与提示
    w.esc = esc; w.toast = toast; w.fmtUid = fmtUid; w.opts = opts; w.ROLE_CN = ROLE_CN;
})(window);
