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
        upload: function (action, file, extra, cb) {
            var s = this.sign(action), fd = new FormData(), k;
            fd.append('ts', s.ts); fd.append('sign', s.sign); fd.append('file', file);
            for (k in (extra || {})) if (extra.hasOwnProperty(k)) fd.append(k, extra[k]);
            var x = new XMLHttpRequest();
            x.open('POST', '?action=' + encodeURIComponent(action), true);
            x.onreadystatechange = function () {
                if (x.readyState !== 4) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                cb(r || { ok: false, msg: '上传失败' });
            };
            x.send(fd);
        }
    };

    /* 客户端图片压缩（本地存储模式；旧浏览器无 canvas 时自动跳过直接上传） */
    function compressImage(file, cb) {
        if (!w.FileReader || !document.createElement('canvas').getContext || !file.type.match(/^image\/(jpeg|png|webp)/)) { cb(file); return; }
        var img = new Image(), reader = new FileReader();
        reader.onload = function (e) {
            img.onload = function () {
                var max = 1280, width = img.width, height = img.height;
                if (width <= max && height <= max && file.size < 300 * 1024) { cb(file); return; }
                if (width > height) { if (width > max) { height = Math.round(height * max / width); width = max; } }
                else { if (height > max) { width = Math.round(width * max / height); height = max; } }
                var c = document.createElement('canvas'); c.width = width; c.height = height;
                c.getContext('2d').drawImage(img, 0, 0, width, height);
                if (c.toBlob) {
                    c.toBlob(function (b) { cb(b || file); }, 'image/jpeg', 0.85);
                } else cb(file);
            };
            img.onerror = function () { cb(file); };
            img.src = e.target.result;
        };
        reader.onerror = function () { cb(file); };
        reader.readAsDataURL(file);
    }

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

    function roleTag(role, title) {
        var map = { admin: ['管理员', 'ow-tag-admin'], vip: ['VIP', 'ow-tag-vip'], member: ['普通用户', 'ow-tag-member'], guest: ['游客', 'ow-tag-guest'] };
        var r = map[role] || map.guest, h = '<span class="ow-tag ' + r[1] + '">' + r[0] + '</span>';
        if (title) h += ' <span class="ow-tag ow-tag-title">' + esc(title) + '</span>';
        return h;
    }

    function avatarHtml(url, name, sm) {
        var cls = 'ow-avatar' + (sm ? ' ow-avatar-sm' : '');
        if (url) return '<span class="' + cls + '"><img src="' + esc(url) + '" alt=""></span>';
        var colors = ['#00A0E9', '#0078D4', '#FF7D00', '#52C41A', '#722ed1'];
        var ch = (name || '?').charAt(0), ci = (name || '').length % colors.length;
        return '<span class="' + cls + '" style="background:' + colors[ci] + '">' + esc(ch) + '</span>';
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
                if (!email) { msg.innerHTML = '<span style="color:#F5222D">请先填写邮箱</span>'; return; }
                codeBtn.disabled = true;
                OwApi.post('send_code', { email: email, type: codeBtn.getAttribute('data-sendcode') }, function (r) {
                    msg.innerHTML = '<span style="color:' + (r.ok ? '#52C41A' : '#F5222D') + '">' + esc(r.msg) + '</span>';
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
                        msg.innerHTML = '<span style="color:#52C41A">' + esc(r.msg) + '</span>';
                        setTimeout(function () { location.href = mode === 'reset' ? '?page=login' : '?page=chat'; }, 600);
                    } else {
                        msg.innerHTML = '<span style="color:#F5222D">' + esc(r.msg) + '</span>';
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
    var OwChat = {
        cfg: null, room: 0, since: 0, polling: false, failCount: 0,
        historyDone: false, loadingHistory: false, sound: true, lastMsgId: 0,
        emojis: '😀 😁 😂 🤣 😊 😍 😘 😜 🤔 😎 😴 😷 🤒 😱 😭 😡 👍 👎 👏 🙏 💪 🤝 ❤️ 💔 🎉 🔥 ⭐ 🌹 🍀 🎂 ☕ 🍺 ⚽ 🏀 🚀 ✈️ 🐱 🐶 🦉 🌙 ☀️ 🌈'.split(' '),

        init: function (cfg) {
            this.cfg = cfg;
            OwApi.key = cfg.key;
            OwApi.setServerTime(cfg.ts);
            this.room = cfg.room;
            this.sound = cfg.settings.sound === '1';
            var self = this;

            this.renderRooms(cfg.rooms);
            this.renderMe();
            this.buildEmojiPanel();
            this.bindEvents();

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
                    OwApi.post('history', { room_id: self.room, before: 0 }, function (r) {
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
                        self.startPoll();
                    });
                });
            };
            load('');
        },

        bindEvents: function () {
            var self = this;
            $('owBtnSend').onclick = function () { self.send(); };
            var input = $('owInput');
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
            $('owBtnImage').onclick = function () { $('owFileInput').click(); };
            $('owFileInput').onchange = function () {
                if (this.files && this.files[0]) self.uploadImage(this.files[0]);
                this.value = '';
            };
            $('owBtnEmoji').onclick = function () {
                var p = $('owEmojiPanel');
                p.style.display = p.style.display === 'none' ? 'block' : 'none';
            };
            $('owBtnSound').onclick = function () {
                self.sound = !self.sound;
                this.innerHTML = this.getAttribute(self.sound ? 'data-on' : 'data-off');
                toast(self.sound ? '提示音已开启' : '提示音已关闭');
            };
            $('owToggleSide').onclick = function () { $('owSidebar').className += ($('owSidebar').className.indexOf('open') >= 0 ? '' : ' open'); };
            $('owToggleOnline').onclick = function () {
                var o = $('owOnline');
                o.className = o.className.indexOf('open') >= 0 ? o.className.replace(' open', '') : o.className + ' open';
            };
            $('owMessages').onscroll = function () {
                if (this.scrollTop < 40 && !self.historyDone && !self.loadingHistory) self.loadHistory();
            };
            $('owLoadMore').onclick = function () { self.loadHistory(); };
            $('owModalMask').onclick = function (e) { if (e.target === this) self.closeModal(); };
            $('owImgViewer').onclick = function () { this.style.display = 'none'; };
            var lo = $('owBtnLogout');
            if (lo) lo.onclick = function () { OwApi.post('logout', {}, function () { location.href = '?page=login'; }); };
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
                if (!pw) { msg.innerHTML = '<span style="color:#F5222D">请输入密码</span>'; return; }
                msg.innerHTML = '验证中…';
                OwApi.post('room_join', { room_id: roomId, password: pw }, function (r) {
                    if (!r.ok) {
                        msg.innerHTML = '<span style="color:#F5222D">' + esc(r.msg) + '</span>';
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
        renderRooms: function (rooms) {
            var html = '', i, self = this;
            for (i = 0; i < rooms.length; i++) {
                var r = rooms[i];
                html += '<li class="ow-room-item' + (r.id === this.room ? ' active' : '') + '" data-room="' + r.id + '" data-name="' + esc(r.name) + '" data-pw="' + (r.need_password ? 1 : 0) + '">'
                      + '<span class="ow-room-icon">' + esc(r.name.charAt(0)) + '</span>'
                      + '<span>' + esc(r.name) + '</span>'
                      + (r.need_password ? '<span class="ow-tag ow-tag-guest ow-room-lock">密码房</span>' : '')
                      + '</li>';
            }
            $('owRoomList').innerHTML = html;
            var items = $('owRoomList').getElementsByTagName('li');
            for (i = 0; i < items.length; i++) {
                items[i].onclick = function () {
                    var el = this;
                    var id = parseInt(el.getAttribute('data-room'), 10);
                    var name = el.getAttribute('data-name');
                    // 先不带密码尝试一次：是否真需要密码由服务端判定（管理员/已缓存都会直接放行）
                    var tryJoin = function (password) {
                        OwApi.post('room_join', { room_id: id, password: password || '' }, function (r) {
                            if (!r.ok) {
                                if (r.need_password) {
                                    if (password) toast(r.msg);           // 带密码仍失败 → 提示后重弹
                                    self.passForget(id);
                                    self.askRoomPassword(id, name, function (pw) { tryJoin(pw); });
                                    return;
                                }
                                toast(r.msg);
                                if (r.need_login) location.href = '?page=login';
                                return;
                            }
                            self.passRemember(id, r.ttl);
                            self.switchRoom(id, r.room.name, el);
                        });
                    };
                    tryJoin('');
                };
            }
        },

        switchRoom: function (id, name, el) {
            this.room = id; this.roomName = name; this.since = 0; this.historyDone = false;
            $('owRoomName').innerHTML = esc(name);
            $('owMessages').innerHTML = '<div class="ow-load-more" id="owLoadMore">加载更早消息…</div>';
            var items = $('owRoomList').getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) items[i].className = items[i].className.replace(' active', '');
            if (el) el.className += ' active';
            $('owSidebar').className = $('owSidebar').className.replace(' open', '');
            var self = this;
            var load = function () {
                OwApi.post('history', { room_id: id, before: 0 }, function (r) {
                    if (r.ok) {
                        for (var i = 0; i < r.data.length; i++) self.addMessage(r.data[i], true);
                        if (r.data.length) self.since = r.data[r.data.length - 1].id;
                        self.scrollBottom();
                        if (r.data.length < 30) self.historyDone = true;
                    } else if (r.need_password) {
                        // 通行授权已过期 → 重新验证，验证成功后自动重试
                        self.passForget(id);
                        self.askRoomPassword(id, name, load);
                    }
                });
            };
            load();
        },

        /* ---------- 长轮询（主通道）+ 断线降级短轮询 ---------- */
        startPoll: function () {
            var self = this;
            function loop() {
                var t0 = new Date().getTime();
                OwApi.post('poll', { room_id: self.room, since: self.since }, function (r, status) {
                    if (!r || !r.ok) {
                        // 密码房授权过期：停止轮询，重新验证后继续
                        if (r && r.need_password) {
                            self.passForget(self.room);
                            self.askRoomPassword(self.room, self.roomName || '', function () { self.startPoll(); });
                            return;
                        }
                        self.failCount++;
                        // 降级：短轮询 + 指数退避（2s → 10s 封顶）
                        var wait = Math.min(10000, 2000 * self.failCount);
                        $('owLatency').innerHTML = '重连中…';
                        $('owLatency').style.color = '#F5222D';
                        setTimeout(loop, wait);
                        return;
                    }
                    self.failCount = 0;
                    var ms = new Date().getTime() - t0;
                    $('owLatency').innerHTML = '● ' + ms + ' ms';
                    $('owLatency').style.color = '#52C41A';
                    self.since = r.since;
                    var i, hasNew = false;
                    for (i = 0; i < r.messages.length; i++) {
                        self.addMessage(r.messages[i]);
                        hasNew = true;
                    }
                    if (hasNew) {
                        self.scrollBottom();
                        if (self.sound) beep();
                    }
                    self.renderOnline(r.online);
                    self.renderAnnounce(r.announcements);
                    setTimeout(loop, 100);
                });
            }
            loop();
        },

        /* ---------- 消息渲染 ---------- */
        addMessage: function (m, batch) {
            var box = $('owMessages');
            if (document.getElementById('owMsg' + m.id)) return;
            var cls = 'ow-msg';
            if (m.mine) cls += ' mine';
            if (m.type === 'mention') cls += ' mention';
            if (m.type === 'private') cls += ' private';
            if (m.type === 'system') cls += ' system';
            if (m.recalled) cls += ' recalled';

            var content;
            if (m.recalled) content = '<span class="ow-msg-content">此消息已撤回</span>';
            else if (m.type === 'image') content = '<span class="ow-msg-content" style="padding:4px"><img class="ow-msg-img" src="' + esc(m.content) + '" onclick="OwChat.viewImg(this.src)" alt="图片"></span>';
            else content = '<span class="ow-msg-content">' + esc(m.content) + '</span>';

            var actions = '';
            var canRecall = m.mine || this.cfg.actor.role === 'admin';
            if (!m.recalled && m.type !== 'system') {
                actions = '<div class="ow-msg-actions">'
                        + '<a href="javascript:;" onclick="OwChat.mention(\'' + esc(m.nickname) + '\',' + (m.uid || 0) + ',' + (m.gid || 0) + ')">@</a>'
                        + (!m.mine && this.cfg.actor.kind === 'user' ? '<a href="javascript:;" onclick="OwChat.pm(\'' + esc(m.nickname) + '\',' + (m.uid || 0) + ',' + (m.gid || 0) + ')">私信</a>' : '')
                        + (m.type === 'image' && this.cfg.actor.kind === 'user' ? '<a href="javascript:;" onclick="OwChat.collect(\'' + esc(m.content) + '\')">收藏贴纸</a>' : '')
                        + (canRecall ? '<a href="javascript:;" onclick="OwChat.recall(' + m.id + ')">撤回</a>' : '')
                        + (this.cfg.actor.role === 'admin' && m.ip ? '<a href="javascript:;" onclick="OwChat.ipLoc(\'' + esc(m.ip) + '\')">归属地</a>' : '')
                        + '</div>';
            }

            var div = document.createElement('div');
            div.className = cls;
            div.id = 'owMsg' + m.id;
            div.innerHTML = (m.type === 'system' ? '' : avatarHtml(m.avatar, m.nickname))
                + '<div class="ow-msg-body">'
                + (m.type === 'system' ? '' : '<div class="ow-msg-meta"><span class="ow-msg-nick" onclick="OwChat.userCard(' + (m.uid || 0) + ',\'' + esc(m.nickname) + '\')">' + esc(m.nickname) + '</span>'
                    + roleTag(m.role, m.title)
                    + (m.type === 'private' && m.to_nickname ? ' <span style="color:#722ed1">→ ' + esc(m.to_nickname) + '</span>' : '')
                    + '<span class="ow-msg-time">' + esc(m.date + ' ' + m.time) + '</span></div>')
                + content + actions + '</div>';
            box.appendChild(div);
            if (!batch && box.children.length > 500) box.removeChild(box.children[1]);
        },

        scrollBottom: function () {
            var box = $('owMessages');
            box.scrollTop = box.scrollHeight;
        },

        loadHistory: function () {
            var self = this, box = $('owMessages');
            var first = box.querySelector('.ow-msg');
            if (!first) { this.historyDone = true; return; }
            var before = parseInt(first.id.replace('owMsg', ''), 10);
            this.loadingHistory = true;
            OwApi.post('history', { room_id: this.room, before: before }, function (r) {
                self.loadingHistory = false;
                if (!r.ok || !r.data.length) { self.historyDone = true; $('owLoadMore').innerHTML = '没有更早的消息了'; return; }
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
            var cls = 'ow-msg';
            if (m.mine) cls += ' mine';
            if (m.type === 'mention') cls += ' mention';
            if (m.type === 'private') cls += ' private';
            if (m.type === 'system') cls += ' system';
            if (m.recalled) cls += ' recalled';
            var content;
            if (m.recalled) content = '<span class="ow-msg-content">此消息已撤回</span>';
            else if (m.type === 'image') content = '<span class="ow-msg-content" style="padding:4px"><img class="ow-msg-img" src="' + esc(m.content) + '" onclick="OwChat.viewImg(this.src)" alt="图片"></span>';
            else content = '<span class="ow-msg-content">' + esc(m.content) + '</span>';
            var div = document.createElement('div');
            div.className = cls;
            div.id = 'owMsg' + m.id;
            div.innerHTML = (m.type === 'system' ? '' : avatarHtml(m.avatar, m.nickname))
                + '<div class="ow-msg-body">'
                + (m.type === 'system' ? '' : '<div class="ow-msg-meta"><span class="ow-msg-nick">' + esc(m.nickname) + '</span>'
                    + roleTag(m.role, m.title) + '<span class="ow-msg-time">' + esc(m.date + ' ' + m.time) + '</span></div>')
                + content + '</div>';
            box.insertBefore(div, ref);
        },

        /* ---------- 发送 ---------- */
        send: function (opt) {
            opt = opt || {};
            var input = $('owInput');
            var content = opt.content != null ? opt.content : input.value;
            if (!content || !content.replace(/^\s+|\s+$/g, '')) return;
            var self = this;
            OwApi.post('send', {
                room_id: this.room,
                type: opt.type || 'text',
                content: content,
                to_user_id: opt.to_user_id || '', to_guest_id: opt.to_guest_id || '',
                to_nickname: opt.to_nickname || ''
            }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                if (!opt.type || opt.type === 'text') input.value = '';
                input.style.height = 'auto';
            });
        },

        uploadImage: function (file) {
            var self = this;
            toast('图片上传中…');
            var doUpload = function (f) {
                OwApi.upload('upload', f, { kind: 'image' }, function (r) {
                    if (r.ok) self.send({ type: 'image', content: r.url });
                    else toast(r.msg);
                });
            };
            if (this.cfg.settings.image_mode === 'local') compressImage(file, doUpload);
            else doUpload(file); // 图床模式由 API 压缩，本地不处理
        },

        recall: function (id) {
            if (!w.confirm('确定撤回这条消息吗？')) return;
            OwApi.post('recall', { id: id }, function (r) { if (!r.ok) toast(r.msg); });
        },

        mention: function (nick) {
            var input = $('owInput');
            input.value += '@' + nick + ' ';
            input.focus();
        },

        pm: function (nick, uid, gid) {
            var content = w.prompt('私信 ' + nick + '：');
            if (content) this.send({ type: 'private', content: content, to_user_id: uid || '', to_guest_id: gid || '', to_nickname: nick });
        },

        collect: function (url) {
            OwApi.post('sticker_add', { url: url }, function (r) { toast(r.msg); });
        },

        ipLoc: function (ip) {
            OwApi.post('ip_loc', { ip: ip }, function (r) { toast(r.ok ? ip + ' → ' + r.loc : r.msg, 4000); });
        },

        viewImg: function (src) {
            $('owImgViewerImg').src = src;
            $('owImgViewer').style.display = '-webkit-flex';
            $('owImgViewer').style.display = 'flex';
        },

        /* ---------- 用户资料卡 ---------- */
        userCard: function (uid, nick) {
            if (!uid) { this.pmHint(nick); return; }
            OwApi.post('user_card', { id: uid }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var u = r.data;
                OwChat.openModal(
                    '<h3>用户资料</h3>'
                    + '<div style="text-align:center;margin-bottom:14px">' + avatarHtml(u.avatar, u.nickname)
                    + '<div class="ow-me-name" style="margin-top:8px">' + esc(u.nickname) + '</div>'
                    + '<div style="margin-top:4px">' + roleTag(u.role, u.title) + '</div></div>'
                    + '<p style="font-size:13px;color:#999">账号：' + esc(u.username) + '<br>注册：' + esc((u.created_at || '').toString().substr(0, 10)) + '</p>'
                );
            });
        },

        pmHint: function (nick) { toast('游客用户无法查看资料卡'); },

        /* ---------- 在线列表 ---------- */
        renderOnline: function (list) {
            $('owOnlineCount').innerHTML = list.length;
            var html = '', i;
            for (i = 0; i < list.length; i++) {
                var o = list[i];
                html += '<li class="ow-online-item"><span class="ow-online-dot"></span>'
                      + avatarHtml(o.avatar, o.nickname, true)
                      + '<span class="ow-online-name" onclick="OwChat.userCard(' + (o.uid || 0) + ',\'' + esc(o.nickname) + '\')">' + esc(o.nickname) + '</span>'
                      + roleTag(o.role, '') + '</li>';
            }
            $('owOnlineList').innerHTML = html;
        },

        /* ---------- 公告轮播 ---------- */
        renderAnnounce: function (list) {
            var box = $('owAnnounce'), track = $('owAnnounceTrack');
            if (!list || !list.length) { box.style.display = 'none'; return; }
            box.style.display = 'block';
            var html = '', i;
            for (i = 0; i < list.length; i++) {
                html += '<div class="ow-announce-item">' + (list[i].type === 'welcome' ? '[欢迎] ' : '[公告] ') + esc(list[i].content) + '</div>';
            }
            if (track._html === html) return;
            track._html = html;
            track.innerHTML = html;
            clearInterval(track._tm);
            var idx = 0;
            if (list.length > 1) {
                track._tm = setInterval(function () {
                    idx = (idx + 1) % list.length;
                    track.style.transform = 'translateY(-' + idx * 18 + 'px)';
                }, 4000);
            }
        },

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
                    if (!r.ok || !r.data.length) { grid.innerHTML = '<p style="padding:20px;color:#999;font-size:12px">暂无贴纸：把鼠标悬停在图片消息上点击「收藏贴纸」即可添加</p>'; return; }
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
            var me = this.cfg.me, el = $('owMe');
            if (!el) return;
            if (me) {
                el.innerHTML = avatarHtml(me.avatar, me.nickname)
                    + '<div><div class="ow-me-name">' + esc(me.nickname) + '</div>' + roleTag(me.role, me.title) + '</div>';
            } else {
                el.innerHTML = avatarHtml('', this.cfg.actor.nickname)
                    + '<div><div class="ow-me-name">' + esc(this.cfg.actor.nickname) + '</div>' + roleTag('guest', '') + '</div>';
            }
        },

        openSettings: function () {
            var me = this.cfg.me;
            if (!me) return;
            this.openModal(
                '<h3>个人设置</h3>'
                + '<div class="ow-form-item"><label>昵称</label><input class="ow-input" id="owSetNick" value="' + esc(me.nickname) + '"></div>'
                + '<div class="ow-form-item"><label>头像</label><div class="ow-captcha-row">'
                + '<span id="owSetAvatarPreview">' + avatarHtml(me.avatar, me.nickname) + '</span>'
                + '<button class="ow-btn ow-btn-ghost" onclick="document.getElementById(\'owSetAvatarFile\').click()">上传头像</button>'
                + '<input type="file" id="owSetAvatarFile" accept="image/*" style="display:none"></div></div>'
                + '<button class="ow-btn ow-btn-primary ow-btn-block" onclick="OwChat.saveSettings()">保存</button>'
            );
            var self = this;
            $('owSetAvatarFile').onchange = function () {
                if (!this.files || !this.files[0]) return;
                compressImage(this.files[0], function (f) {
                    OwApi.upload('upload', f, { kind: 'avatar' }, function (r) {
                        if (r.ok) {
                            self.cfg.me.avatar = r.url;
                            $('owSetAvatarPreview').innerHTML = avatarHtml(r.url, self.cfg.me.nickname);
                            toast('头像已上传，点击保存生效');
                        } else toast(r.msg);
                    });
                });
                this.value = '';
            };
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
        openModal: function (html) {
            $('owModal').innerHTML = '<button class="ow-modal-close" onclick="OwChat.closeModal()">✕</button>' + html;
            $('owModalMask').style.display = '-webkit-flex';
            $('owModalMask').style.display = 'flex';
        },
        closeModal: function () { $('owModalMask').style.display = 'none'; }
    };

    /* ==========================================================================
       OwAdmin：管理后台
       ========================================================================== */
    var OwAdmin = {
        init: function (opt) {
            OwApi.key = opt.key;
            OwApi.setServerTime(opt.ts);
            var menu = $('owAdminMenu'), self = this;
            var items = menu.getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                items[i].onclick = function () {
                    var all = menu.getElementsByTagName('li'), j;
                    for (j = 0; j < all.length; j++) all[j].className = '';
                    this.className = 'active';
                    self.page(this.getAttribute('data-apage'));
                };
            }
            this.page('users');
        },

        page: function (name) {
            var main = $('owAdminMain');
            var M = OwAdmin.pages[name];
            if (name.indexOf('plugin:') === 0) {
                OwApi.post('admin_plugin_page', { slug: name.substr(7) }, function (r) {
                    main.innerHTML = r.ok ? r.html : '<div class="ow-card">' + esc(r.msg) + '</div>';
                });
                return;
            }
            if (M) M(main);
        },

        pages: {
            users: function (main) {
                main.innerHTML = '<h2>用户管理</h2><p class="ow-admin-desc">搜索用户，管理身份与头衔。</p>'
                    + '<div class="ow-card"><h3 style="margin-bottom:10px">用户搜索</h3>'
                    + '<div class="ow-form-row"><div class="ow-form-item" style="flex:1"><input class="ow-input" id="owAQ" placeholder="输入 user_id、昵称或用户名"></div>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.searchUsers()">搜索用户</button></div>'
                    + '<p style="font-size:12px;color:#999">支持按 user_id、昵称或用户名模糊搜索。请输入关键词后再搜索，不再默认展示全部用户。</p></div>'
                    + '<div id="owAResult"></div>';
            },
            rooms: function (main) {
                OwApi.post('admin_rooms', {}, function (r) {
                    var h = '<h2>聊天室管理</h2><p class="ow-admin-desc">创建 / 编辑 / 删除聊天室，设置访问权限与房主。</p><div class="ow-card">'
                        + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.roomForm(0)">新建聊天室</button></div><div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>名称</th><th>类型</th><th>最低角色</th><th>房主ID</th><th>状态</th><th>操作</th></tr>';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<tr><td>' + d.id + '</td><td>' + esc(d.name) + '</td><td>' + esc(d.type) + '</td><td>' + esc(d.min_role) + '</td><td>' + (d.owner_id || '-') + '</td>'
                           + '<td>' + (d.status == 1 ? '开启' : '关闭') + '</td>'
                           + '<td><a href="javascript:;" onclick=\'OwAdmin.roomForm(' + JSON.stringify(d) + ')\'>编辑</a> '
                           + '<a href="javascript:;" onclick="OwAdmin.roomDel(' + d.id + ')">删除</a></td></tr>';
                    }
                    main.innerHTML = h + '</table></div>';
                });
            },
            bans: function (main) {
                OwApi.post('admin_bans', {}, function (r) {
                    var h = '<h2>禁言管理</h2><p class="ow-admin-desc">按用户 / 游客昵称 / IP 禁言，可按房间隔离，支持过期时间。</p>'
                        + '<div class="ow-card"><div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>类型</label><select class="ow-input" id="owBType"><option value="user">用户ID</option><option value="guest">游客昵称</option><option value="ip">IP 地址</option></select></div>'
                        + '<div class="ow-form-item"><label>目标</label><input class="ow-input" id="owBTarget"></div>'
                        + '<div class="ow-form-item"><label>房间ID（0=全局）</label><input class="ow-input" id="owBRoom" value="0"></div>'
                        + '<div class="ow-form-item"><label>时长（小时，0=永久）</label><input class="ow-input" id="owBHours" value="24"></div>'
                        + '<div class="ow-form-item"><label>原因</label><input class="ow-input" id="owBReason"></div>'
                        + '<button class="ow-btn ow-btn-danger" onclick="OwAdmin.banAdd()">添加禁言</button></div></div>'
                        + '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>类型</th><th>目标</th><th>房间</th><th>原因</th><th>过期时间</th><th>操作</th></tr>';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<tr><td>' + d.id + '</td><td>' + esc(d.type) + '</td><td>' + esc(d.target) + '</td><td>' + (d.room_id == 0 ? '全局' : d.room_id) + '</td><td>' + esc(d.reason || '') + '</td>'
                           + '<td>' + (d.expires_at ? new Date(d.expires_at * 1000).toLocaleString() : '永久') + '</td>'
                           + '<td><a href="javascript:;" onclick="OwAdmin.banDel(' + d.id + ')">解除</a></td></tr>';
                    }
                    main.innerHTML = h + '</table></div>';
                });
            },
            words: function (main) {
                OwApi.post('admin_words', {}, function (r) {
                    var h = '<h2>敏感词过滤</h2><p class="ow-admin-desc">添加敏感词及替换词，支持启用 / 停用。</p>'
                        + '<div class="ow-card"><div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>敏感词</label><input class="ow-input" id="owWWord"></div>'
                        + '<div class="ow-form-item"><label>替换为</label><input class="ow-input" id="owWRep" value="***"></div>'
                        + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.wordAdd()">添加</button></div></div>'
                        + '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>敏感词</th><th>替换为</th><th>状态</th><th>操作</th></tr>';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<tr><td>' + d.id + '</td><td>' + esc(d.word) + '</td><td>' + esc(d.replacement) + '</td>'
                           + '<td>' + (d.enabled == 1 ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">停用</span>') + '</td>'
                           + '<td><a href="javascript:;" onclick="OwAdmin.wordToggle(' + d.id + ',' + (d.enabled == 1 ? 0 : 1) + ')">' + (d.enabled == 1 ? '停用' : '启用') + '</a> '
                           + '<a href="javascript:;" onclick="OwAdmin.wordDel(' + d.id + ')">删除</a></td></tr>';
                    }
                    main.innerHTML = h + '</table></div>';
                });
            },
            anns: function (main) {
                OwApi.post('admin_anns', {}, function (r) {
                    var h = '<h2>系统公告</h2><p class="ow-admin-desc">创建公告与欢迎消息，可绑定聊天室，支持优先级排序与轮播展示。</p>'
                        + '<div class="ow-card"><div class="ow-form-row">'
                        + '<div class="ow-form-item" style="flex:1;min-width:220px"><label>内容</label><input class="ow-input" id="owAnContent"></div>'
                        + '<div class="ow-form-item"><label>房间ID（0=全部）</label><input class="ow-input" id="owAnRoom" value="0"></div>'
                        + '<div class="ow-form-item"><label>类型</label><select class="ow-input" id="owAnType"><option value="announce">公告</option><option value="welcome">欢迎消息</option></select></div>'
                        + '<div class="ow-form-item"><label>优先级</label><input class="ow-input" id="owAnPri" value="0"></div>'
                        + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.annAdd()">发布</button></div></div>'
                        + '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>内容</th><th>房间</th><th>类型</th><th>优先级</th><th>状态</th><th>操作</th></tr>';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<tr><td>' + d.id + '</td><td>' + esc(d.content) + '</td><td>' + (d.room_id == 0 ? '全部' : d.room_id) + '</td><td>' + esc(d.type) + '</td><td>' + d.priority + '</td>'
                           + '<td>' + (d.enabled == 1 ? '展示中' : '已停用') + '</td>'
                           + '<td><a href="javascript:;" onclick="OwAdmin.annToggle(' + d.id + ',' + (d.enabled == 1 ? 0 : 1) + ')">' + (d.enabled == 1 ? '停用' : '启用') + '</a> '
                           + '<a href="javascript:;" onclick="OwAdmin.annDel(' + d.id + ')">删除</a></td></tr>';
                    }
                    main.innerHTML = h + '</table></div>';
                });
            },
            plugins: function (main) {
                OwApi.post('admin_plugins', {}, function (r) {
                    var h = '<h2>插件管理</h2><p class="ow-admin-desc">安装（上传 zip）、启用 / 停用插件。插件存放于 plugins/ 目录。</p>'
                        + '<div class="ow-card"><input type="file" id="owPluginZip" accept=".zip"> <button class="ow-btn ow-btn-primary" onclick="OwAdmin.pluginInstall()">上传安装</button></div>'
                        + '<div class="ow-card"><table class="ow-table"><tr><th>标识</th><th>名称</th><th>版本</th><th>说明</th><th>状态</th><th>操作</th></tr>';
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        h += '<tr><td>' + esc(d.id) + '</td><td>' + esc(d.name) + '</td><td>' + esc(d.version) + '</td><td>' + esc(d.description) + '</td>'
                           + '<td>' + (d.enabled ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">停用</span>') + '</td>'
                           + '<td><a href="javascript:;" onclick="OwAdmin.pluginToggle(\'' + esc(d.id) + '\',' + (d.enabled ? 0 : 1) + ')">' + (d.enabled ? '停用' : '启用') + '</a></td></tr>';
                    }
                    if (!r.data.length) h += '<tr><td colspan="6" style="color:#999">暂无插件</td></tr>';
                    main.innerHTML = h + '</table></div>';
                });
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
                        + '<div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>开放注册</label>' + sel('allow_register', { '1': '开放', '0': '关闭' }) + '</div>'
                        + '<div class="ow-form-item"><label>注册需邮箱验证</label>' + sel('reg_email_verify', { '1': '需要', '0': '不需要' }) + '</div>'
                        + '<div class="ow-form-item"><label>游客可浏览</label>' + sel('guest_browse', { '1': '允许', '0': '禁止' }) + '</div>'
                        + '<div class="ow-form-item"><label>游客可发言</label>' + sel('guest_chat', { '1': '允许', '0': '禁止' }) + '</div>'
                        + '</div><div class="ow-form-row">'
                        + '<div class="ow-form-item"><label>游客每日发言限额</label><input class="ow-input" id="owS_guest_daily_limit" value="' + esc(d.guest_daily_limit || '50') + '"></div>'
                        + '<div class="ow-form-item"><label>发言频率窗口(秒)</label><input class="ow-input" id="owS_msg_rate_window" value="' + esc(d.msg_rate_window || '10') + '"></div>'
                        + '<div class="ow-form-item"><label>窗口内最大条数</label><input class="ow-input" id="owS_msg_rate_max" value="' + esc(d.msg_rate_max || '8') + '"></div>'
                        + '<div class="ow-form-item"><label>邮件发送间隔(秒)</label><input class="ow-input" id="owS_mail_rate_limit" value="' + esc(d.mail_rate_limit || '60') + '"></div>'
                        + '</div>'
                        + '<div class="ow-form-item"><label>图片消息存储</label>' + sel('image_mode', { 'local': '本地存储（客户端压缩）', 'imgbed': '图床（API 压缩）' }) + '</div>'
                        + '<div class="ow-form-item"><label>密码房通行缓存(秒)</label><input class="ow-input" id="owS_room_pass_ttl" value="' + esc(d.room_pass_ttl || '1800') + '">'
                        + '<p style="font-size:12px;color:#999;margin-top:4px">验证一次密码后，该时间内进入同一房间无需重复输入；填 0 表示每次进入都要输入。</p></div>'
                        + '<div class="ow-form-item"><label>新消息提示音默认</label>' + sel('sound_default', { '1': '开', '0': '关' }) + '</div>'
                        + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.settingsSave()">保存设置</button></div>';
                });
            }
        },

        /* ---------- 用户管理动作 ---------- */
        searchUsers: function () {
            OwApi.post('admin_users', { q: $('owAQ').value }, function (r) {
                var h = '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>用户名</th><th>昵称</th><th>邮箱</th><th>角色</th><th>称号</th><th>状态</th><th>操作</th></tr>';
                for (var i = 0; i < r.data.length; i++) {
                    var d = r.data[i];
                    h += '<tr><td>' + d.id + '</td><td>' + esc(d.username) + '</td><td>' + esc(d.nickname) + '</td><td>' + esc(d.email) + '</td>'
                       + '<td><select class="ow-input" id="owUR' + d.id + '">'
                       + ['member', 'vip', 'admin'].map(function (x) { return '<option' + (d.role === x ? ' selected' : '') + '>' + x + '</option>'; }).join('')
                       + '</select></td>'
                       + '<td><input class="ow-input" id="owUT' + d.id + '" value="' + esc(d.title || '') + '"></td>'
                       + '<td>' + (d.status == 1 ? '正常' : '禁用') + '</td>'
                       + '<td><a href="javascript:;" onclick="OwAdmin.userSave(' + d.id + ')">保存</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.userStatus(' + d.id + ',' + (d.status == 1 ? 0 : 1) + ')">' + (d.status == 1 ? '禁用' : '启用') + '</a></td></tr>';
                }
                if (!r.data.length) h += '<tr><td colspan="8" style="color:#999">无匹配结果</td></tr>';
                $('owAResult').innerHTML = h + '</table></div>';
            });
        },
        userSave: function (id) {
            OwApi.post('admin_user_set', { id: id, role: $('owUR' + id).value, title: $('owUT' + id).value }, function (r) { toast(r.msg); });
        },
        userStatus: function (id, s) {
            OwApi.post('admin_user_status', { id: id, status: s }, function (r) { toast(r.msg); OwAdmin.searchUsers(); });
        },

        /* ---------- 房间动作 ---------- */
        roomForm: function (d) {
            d = d || { id: 0, name: '', type: 'public', password: '', min_role: 'guest', owner_id: '', description: '', status: 1 };
            $('owAdminMain').innerHTML = '<h2>' + (d.id ? '编辑' : '新建') + '聊天室</h2><div class="ow-card">'
                + '<input type="hidden" id="owRId" value="' + d.id + '">'
                + '<div class="ow-form-item"><label>名称</label><input class="ow-input" id="owRName" value="' + esc(d.name) + '"></div>'
                + '<div class="ow-form-item"><label>类型</label><select class="ow-input" id="owRType">'
                + ['public', 'password', 'role'].map(function (x) { return '<option' + (d.type === x ? ' selected' : '') + '>' + x + '</option>'; }).join('') + '</select></div>'
                + '<div class="ow-form-item"><label>房间密码（password 类型时有效）</label><input class="ow-input" id="owRPass" value="' + esc(d.password || '') + '"></div>'
                + '<div class="ow-form-item"><label>最低进入角色（role 类型时有效）</label><select class="ow-input" id="owRRole">'
                + ['guest', 'member', 'vip', 'admin'].map(function (x) { return '<option' + (d.min_role === x ? ' selected' : '') + '>' + x + '</option>'; }).join('') + '</select></div>'
                + '<div class="ow-form-item"><label>房主用户ID（房主可撤回本房间任意消息）</label><input class="ow-input" id="owROwner" value="' + (d.owner_id || '') + '"></div>'
                + '<div class="ow-form-item"><label>描述</label><input class="ow-input" id="owRDesc" value="' + esc(d.description || '') + '"></div>'
                + '<div class="ow-form-item"><label>状态</label><select class="ow-input" id="owRStatus"><option value="1"' + (d.status == 1 ? ' selected' : '') + '>开启</option><option value="0"' + (d.status == 0 ? ' selected' : '') + '>关闭</option></select></div>'
                + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.roomSave()">保存</button> '
                + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.page(\'rooms\')">返回</button></div>';
        },
        roomSave: function () {
            OwApi.post('admin_room_save', {
                id: $('owRId').value, name: $('owRName').value, type: $('owRType').value,
                password: $('owRPass').value, min_role: $('owRRole').value,
                owner_id: $('owROwner').value, description: $('owRDesc').value, status: $('owRStatus').value
            }, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('rooms'); });
        },
        roomDel: function (id) {
            if (!w.confirm('确定删除该聊天室？消息将保留但不可访问。')) return;
            OwApi.post('admin_room_del', { id: id }, function (r) { toast(r.msg); OwAdmin.page('rooms'); });
        },

        /* ---------- 其他动作 ---------- */
        banAdd: function () {
            OwApi.post('admin_ban_add', {
                type: $('owBType').value, target: $('owBTarget').value, room_id: $('owBRoom').value,
                hours: $('owBHours').value, reason: $('owBReason').value
            }, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('bans'); });
        },
        banDel: function (id) { OwApi.post('admin_ban_del', { id: id }, function (r) { toast(r.msg); OwAdmin.page('bans'); }); },
        wordAdd: function () {
            OwApi.post('admin_word_add', { word: $('owWWord').value, replacement: $('owWRep').value }, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('words'); });
        },
        wordToggle: function (id, en) { OwApi.post('admin_word_toggle', { id: id, enabled: en }, function (r) { toast(r.msg); OwAdmin.page('words'); }); },
        wordDel: function (id) { OwApi.post('admin_word_del', { id: id }, function (r) { toast(r.msg); OwAdmin.page('words'); }); },
        annAdd: function () {
            OwApi.post('admin_ann_add', {
                content: $('owAnContent').value, room_id: $('owAnRoom').value,
                type: $('owAnType').value, priority: $('owAnPri').value
            }, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('anns'); });
        },
        annToggle: function (id, en) { OwApi.post('admin_ann_toggle', { id: id, enabled: en }, function (r) { toast(r.msg); OwAdmin.page('anns'); }); },
        annDel: function (id) { OwApi.post('admin_ann_del', { id: id }, function (r) { toast(r.msg); OwAdmin.page('anns'); }); },
        pluginToggle: function (name, en) { OwApi.post('admin_plugin_toggle', { name: name, enabled: en }, function (r) { toast(r.msg); OwAdmin.page('plugins'); }); },
        pluginInstall: function () {
            var f = $('owPluginZip');
            if (!f.files || !f.files[0]) { toast('请选择 zip 文件'); return; }
            OwApi.upload('admin_plugin_install', f.files[0], {}, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('plugins'); });
        },
        settingsSave: function () {
            OwApi.post('admin_settings_save', {
                site_name: $('owS_site_name').value,
                allow_register: $('owS_allow_register').value,
                reg_email_verify: $('owS_reg_email_verify').value,
                guest_browse: $('owS_guest_browse').value,
                guest_chat: $('owS_guest_chat').value,
                guest_daily_limit: $('owS_guest_daily_limit').value,
                msg_rate_window: $('owS_msg_rate_window').value,
                msg_rate_max: $('owS_msg_rate_max').value,
                mail_rate_limit: $('owS_mail_rate_limit').value,
                image_mode: $('owS_image_mode').value,
                room_pass_ttl: $('owS_room_pass_ttl') ? $('owS_room_pass_ttl').value : '',
                sound_default: $('owS_sound_default').value
            }, function (r) { toast(r.msg); });
        }
    };

    w.OwAuth = OwAuth;
    w.OwChat = OwChat;
    w.OwAdmin = OwAdmin;
})(window);
