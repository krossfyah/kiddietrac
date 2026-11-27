/* ═══════════════════════════════════════════════════════════════════════
   📻 WALKIE-TALKIE (2026-11-27). Live push-to-talk between an agency's own staff (never parents).
   Server: App\Support\Walkie (how it works, who may talk to whom), WalkieController (the API).

   This file does three things, for staff roles only:
   1. LISTENS in every open portal tab: reads the person's "doorbell" (/wt/b/<hmac>.txt, a static file) once a
      second; when it changes, asks GET /walkie/inbox for live talks (with their one-time key) and plays them as they
      arrive: AES-GCM pieces /wt/<dir>/<seq>.bin -> G.711 mu-law 16 kHz -> Web Audio, ~0.3 s jitter buffer. One tab plays
      (the leader, like kt-reminders.js). Inside the Android app the native listener plays instead (KtWalkie plugin),
      so the page only shows the "Amna is talking" card.
   2. TALKS: hold the 🎙 button (or the spacebar on the Walkie-talkie screen). The mic is captured at once, cut into
      ~400 ms mu-law pieces and posted to /walkie/talk/{id}/chunk; one speaker per channel (409 = someone is talking).
   3. The #walkie screen: channels, people, history (play back), Do not disturb, and the agency settings for admins.
   ═══════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT = w.KT || {};
  var STAFF_CLASSES = ['role-agency-admin', 'role-centre-director', 'role-educator', 'role-home-visitor'];
  var RATE = 16000, PIECE = 6400;            // 400 ms of 16 kHz samples
  var ID = Math.random().toString(36).slice(2);
  var S = { me: null, bell: null, bellV: null, played: {}, queue: [], busyPlay: false, ctx: null, nativeOn: false, talk: null,
    sel: null, replies: {}, card: null, timer: null, booted: false, worker: null, lastInbox: 0 };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function T(s) { try { return (KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, T(t), m ? T(m) : '', c); } catch (e) {} }
  function api() { return KT.Api; }
  function errMsg(e) { var m = (e && ((e.data && e.data.message) || e.message)) || ''; return m && !/fetch|network|undefined|null|status \d/i.test(m) ? m : 'That did not work. Please try again.'; }
  function isStaff() { var b = d.body; if (!b) return false; for (var i = 0; i < STAFF_CLASSES.length; i++) if (b.classList.contains(STAFF_CLASSES[i])) return true; return false; }
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { return null; } }
  function native() { try { return w.Capacitor && w.Capacitor.Plugins && w.Capacitor.Plugins.KtWalkie; } catch (e) { return null; } }

  /* ---- one tab plays (the most recently focused), like kt-reminders.js ---- */
  var LKEY = 'kt_walkie_leader', STALE = 5000;
  function leaderRec() { try { return JSON.parse(ls(LKEY) || 'null'); } catch (e) { return null; } }
  function claim() { ls(LKEY, JSON.stringify({ id: ID, at: Date.now() })); }
  function isLeader() { var r = leaderRec(); if (!r || !r.id || Date.now() - (r.at || 0) > STALE) { claim(); return true; } return r.id === ID; }
  w.addEventListener('focus', claim);
  w.addEventListener('pagehide', function () { var r = leaderRec(); if (r && r.id === ID) ls(LKEY, null); });

  /* ---- G.711 mu-law ---- */
  var DEC = new Float32Array(256);
  (function () { for (var i = 0; i < 256; i++) { var u = ~i & 0xff, s = u & 0x80, e = (u >> 4) & 7, m = u & 15; var x = ((m << 3) + 0x84) << e; x -= 0x84; DEC[i] = (s ? -x : x) / 32768; } })();
  function mulaw(f) {
    var x = Math.max(-1, Math.min(1, f)) * 32767 | 0, s = x < 0 ? 0x80 : 0; if (s) x = -x; x = Math.min(x + 0x84, 32635 + 0x84);
    var e = 7; for (var m = 0x4000; (x & m) === 0 && e > 0; m >>= 1) e--;
    return ~(s | (e << 4) | ((x >> (e + 3)) & 15)) & 0xff;
  }
  function b64(u8) { var s = ''; for (var i = 0; i < u8.length; i += 8192) s += String.fromCharCode.apply(null, u8.subarray(i, i + 8192)); return btoa(s); }
  function unb64(s) { var b = atob(s), u = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) u[i] = b.charCodeAt(i); return u; }

  /* ---- audio out ---- */
  function ctx() {
    if (!S.ctx) { var C = w.AudioContext || w.webkitAudioContext; if (!C) return null; S.ctx = new C(); }
    if (S.ctx.state === 'suspended') { try { S.ctx.resume(); } catch (e) {} }
    return S.ctx;
  }
  function unlocked() { return !!(S.ctx && S.ctx.state === 'running'); }
  ['pointerdown', 'keydown', 'touchend'].forEach(function (ev) { d.addEventListener(ev, function () { if (S.me) ctx(); }, { capture: true, passive: true }); });
  function chirp(c, at, emergency) {
    [0, emergency ? 0.16 : -1].forEach(function (off) {
      if (off < 0) return; var o = c.createOscillator(), g = c.createGain(); o.frequency.value = emergency ? 1500 : 1180;
      g.gain.setValueAtTime(0.0001, at + off); g.gain.exponentialRampToValueAtTime(0.25, at + off + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, at + off + 0.09);
      o.connect(g); g.connect(c.destination); o.start(at + off); o.stop(at + off + 0.1);
    });
  }
  function schedule(c, u8, at) {
    var buf = c.createBuffer(1, u8.length, RATE), ch = buf.getChannelData(0);
    for (var i = 0; i < u8.length; i++) ch[i] = DEC[u8[i]];
    var src = c.createBufferSource(); src.buffer = buf; src.connect(c.destination); src.start(at);
    return at + buf.duration;
  }

  /* ---- listening ---- */
  function poll() {
    if (!S.me || !S.bell || d.visibilityState === 'hidden' && !isLeader()) return;
    if (!isLeader() && !S.card) { /* followers still show the card when the leader tells them (storage event) */ }
    fetch(S.bell + '?r=' + Date.now() + Math.random().toString(36).slice(2, 6), { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (v) {
      v = (v || '').trim();
      if (!v) return;
      if (S.bellV === null) { S.bellV = v; return; }
      if (v !== S.bellV) { S.bellV = v; inbox(); }
    }).catch(function () {});
  }
  function inbox() {
    S.lastInbox = Date.now();
    api().get('/walkie/inbox').then(function (r) {
      (r.live || []).forEach(function (t) { if (!S.played[t.id]) { S.played[t.id] = 1; S.queue.push(t); } });
      (r.replies || []).forEach(function (x) { var k = x.tx + ':' + x.from; if (!S.replies[k]) { S.replies[k] = 1; toast('📻', x.from + ' can\'t talk right now', 'Walkie-talkie reply', '#B45309'); } });
      next();
    }).catch(function () {});
  }
  function next() {
    if (S.busyPlay || !S.queue.length) return;
    var t = S.queue.shift();
    S.busyPlay = true;
    play(t).then(function () { S.busyPlay = false; next(); }, function () { S.busyPlay = false; next(); });
  }

  function showCard(t, needTap) {
    hideCard();
    var c = d.createElement('div'); c.id = 'kt-wt-card'; c.setAttribute('role', 'alert');
    c.className = t.emergency ? 'em' : '';
    c.innerHTML = '<div class="wt-ch">' + (t.emergency ? '🚨 ' : '📻 ') + esc(T(t.label)) + '</div><div class="wt-who"><b data-no-i18n>' + esc(t.from) + '</b> ' + esc(T('is talking')) + '</div>'
      + '<div class="wt-act">' + (needTap ? '<button type="button" class="btn btn-primary" data-wt="listen">▶ ' + esc(T('Listen')) + '</button>' : '')
      + '<button type="button" class="btn" data-wt="busy">' + esc(T('Busy, can\'t talk')) + '</button>'
      + '<button type="button" class="btn" data-wt="reply">🎙 ' + esc(T('Reply')) + '</button></div>';
    d.body.appendChild(c);
    S.card = { el: c, t: t };
    return c;
  }
  function hideCard() { if (S.card && S.card.el) S.card.el.remove(); S.card = null; }

  function play(t) {
    return new Promise(function (resolve) {
      var c = ctx();
      var playHere = !S.nativeOn && isLeader() && !!c && !!(w.crypto && w.crypto.subtle);
      var card = showCard(t, playHere && !unlocked());
      var stop = false, done = false, key = null, heard = false;
      function finish() { if (done) return; done = true; setTimeout(function () { if (S.card && S.card.t === t) hideCard(); resolve(); }, 900); }
      function markHeard(busy) { if (heard && !busy) return; heard = true; api().post('/walkie/tx/' + t.id + '/heard', { busy: !!busy }, { quiet: true }).catch(function () {}); }
      card.addEventListener('click', function (e) {
        var b = e.target.closest('[data-wt]'); if (!b) return;
        var a = b.getAttribute('data-wt');
        if (a === 'listen') { ctx(); b.remove(); }
        if (a === 'busy') { stop = true; markHeard(true); toast('📻', 'Told ' + t.from + ' you can\'t talk', '', '#475569'); hideCard(); finish(); }
        if (a === 'reply') { stop = true; hideCard(); finish(); location.hash = '#walkie?ch=' + encodeURIComponent(t.channel); }
      });
      if (!playHere) {   // the Android app's own listener plays it; this page only shows who is talking
        var waitT = setTimeout(finish, 15000);
        var iv = setInterval(function () { if (stop) { clearInterval(iv); clearTimeout(waitT); } }, 500);
        if (S.nativeOn) setTimeout(function () { clearInterval(iv); finish(); }, 6000);
        return;
      }
      w.crypto.subtle.importKey('raw', unb64(t.key), 'AES-GCM', false, ['decrypt']).then(function (k) { key = k; loop(0, 0, null, Date.now()); }, finish);
      function loop(seq, misses, at, since) {
        if (stop) return finish();
        fetch(t.live + seq + '.bin?r=' + Date.now(), { cache: 'no-store', credentials: 'omit' }).then(function (r) {
          if (!r.ok) throw 404;
          return r.arrayBuffer();
        }).then(function (ab) {
          var u = new Uint8Array(ab);
          return w.crypto.subtle.decrypt({ name: 'AES-GCM', iv: u.subarray(0, 12) }, key, u.subarray(12)).then(function (pcm) {
            var c2 = ctx();
            if (!unlocked()) { loop(seq + 1, 0, at, Date.now()); return; }    // locked tab: catch up silently until "Listen" is tapped
            var now = c2.currentTime;
            if (at === null || at < now + 0.05) { if (at === null) chirp(c2, now + 0.02, t.emergency); at = Math.max(now + (at === null ? 0.3 : 0.12), at || 0); }
            at = schedule(c2, new Uint8Array(pcm), at);
            markHeard(false);
            loop(seq + 1, 0, at, Date.now());
          });
        }).catch(function () {
          if (Date.now() - since > 12000) return finish();
          if (misses % 4 === 3) {
            fetch(t.live + 'end.txt?r=' + Date.now(), { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (v) {
              var n = parseInt(v, 10);
              if (!isNaN(n) && seq >= n) { var c3 = S.ctx; var left = c3 && at ? Math.max(0, (at - c3.currentTime) * 1000) : 0; setTimeout(finish, left); }
              else setTimeout(function () { loop(seq, misses + 1, at, since); }, 120);
            }).catch(function () { setTimeout(function () { loop(seq, misses + 1, at, since); }, 150); });
          } else setTimeout(function () { loop(seq, misses + 1, at, since); }, 120);
        });
      }
    });
  }

  /* ---- talking ---- */
  function talkStart(channel, ui) {
    if (S.talk) return;
    var md = navigator.mediaDevices;
    if (!md || !md.getUserMedia) { toast('⚠️', 'This browser cannot use the microphone', '', '#DC2626'); return; }
    var c = ctx(); if (!c) return;
    var tk = S.talk = { channel: channel, id: null, seq: 0, pend: [], buf: new Float32Array(PIECE), fill: 0, pos: 0, ups: [], released: false, t0: Date.now(), ui: ui };
    ui && ui.state('opening');
    md.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } }).then(function (stream) {
      if (tk.failed) { stream.getTracks().forEach(function (x) { x.stop(); }); return; }   // the start was refused meanwhile
      tk.stream = stream;
      var src = c.createMediaStreamSource(stream), proc = c.createScriptProcessor(2048, 1, 1), ratio = c.sampleRate / RATE;
      tk.src = src; tk.proc = proc;
      proc.onaudioprocess = function (e) {
        if (tk.stopped) return;
        var inp = e.inputBuffer.getChannelData(0);
        for (; tk.pos < inp.length; tk.pos += ratio) {
          var i = tk.pos | 0, f = tk.pos - i, v = inp[i] + ((inp[i + 1] !== undefined ? inp[i + 1] : inp[i]) - inp[i]) * f;
          tk.buf[tk.fill++] = v;
          if (tk.fill === PIECE) piece(tk);
        }
        tk.pos -= inp.length;
      };
      src.connect(proc); proc.connect(c.destination);
      if (tk.released) talkEnd();
    }, function () {
      tk.failed = true; teardown(tk); S.talk = null; ui && ui.state('idle');
      if (tk.id) api().post('/walkie/talk/' + tk.id + '/end', { chunks: 0 }, { quiet: true }).catch(function () {});
      toast('🎙', 'Microphone blocked', 'Allow the microphone for KiddieTrac to talk.', '#DC2626');
    });
    api().post('/walkie/talk', { channel: channel }, { quiet: true }).then(function (r) {
      tk.id = r.id;
      if (tk.failed) { api().post('/walkie/talk/' + tk.id + '/end', { chunks: 0 }, { quiet: true }).catch(function () {}); return; }   // no microphone
      ui && ui.state('live', r);
      tk.pend.splice(0).forEach(function (p) { send(tk, p); });
      if (tk.released) talkEnd();
    }, function (e) {
      tk.failed = true; teardown(tk); S.talk = null; ui && ui.state('idle');
      if (e && e.status === 409) toast('📻', (e.data && e.data.talking ? e.data.talking + ' is talking' : 'Someone is talking'), 'Try again in a moment.', '#B45309');
      else toast('⚠️', 'Could not start talking', errMsg(e), '#DC2626');
    });
    tk.cap = setTimeout(function () { if (S.talk === tk) talkEnd(); }, ((S.me && S.me.max_secs) || 60) * 1000);
  }
  function piece(tk) {
    var n = tk.fill, out = new Uint8Array(n);
    for (var i = 0; i < n; i++) out[i] = mulaw(tk.buf[i]);
    tk.fill = 0;
    var p = { seq: tk.seq++, data: b64(out) };
    if (tk.id) send(tk, p); else tk.pend.push(p);
  }
  function send(tk, p) {
    var pr = api().post('/walkie/talk/' + tk.id + '/chunk', p, { quiet: true }).then(function (r) { if (r && r.stop && S.talk === tk) talkEnd(); }, function () {});
    tk.ups.push(pr);
  }
  function teardown(tk) {
    tk.stopped = true;
    try { tk.proc && tk.proc.disconnect(); tk.src && tk.src.disconnect(); } catch (e) {}
    try { tk.stream && tk.stream.getTracks().forEach(function (x) { x.stop(); }); } catch (e) {}
    clearTimeout(tk.cap);
  }
  function talkEnd() {
    var tk = S.talk; if (!tk) return;
    tk.released = true;
    if (!tk.id || !tk.stream) { if (tk.failed) S.talk = null; return; }   // finishes when the start / mic answers
    if (tk.fill > 0) piece(tk);
    teardown(tk);
    S.talk = null;
    var ui = tk.ui;
    Promise.all(tk.ups).then(function () { return api().post('/walkie/talk/' + tk.id + '/end', { chunks: tk.seq }, { quiet: true }); }).then(function (r) {
      ui && ui.state('idle', r); if (S.view) S.view.history();
    }, function () { ui && ui.state('idle'); });
  }

  /* ---- boot ---- */
  function load() {
    return api().get('/walkie/me').then(function (r) {
      if (!r || !r.enabled) { S.me = r && r.me ? r : null; S.bell = null; return r; }
      S.me = r; S.bell = r.bell;
      var n = native();
      if (n && n.status) {
        n.status().then(function (st) {
          S.nativeOn = !!(st && st.configured && String(st.userId) === String(r.me.id));
          if (!S.nativeOn && st && st.pushToken) {
            api().post('/walkie/device', { push_token: st.pushToken, platform: 'android', app_build: st.build || '' }, { quiet: true }).then(function (x) {
              return n.configure({ base: location.origin, secret: x.secret, userId: String(r.me.id), name: r.me.name });
            }).then(function () { S.nativeOn = true; }).catch(function () {});
          }
        }).catch(function () {});
      }
      return r;
    }, function (e) {
      S.me = null; S.bell = null;
      var n = native(); if (n && n.disable && e && (e.status === 401 || e.status === 403)) { try { n.disable(); } catch (x) {} }
      throw e;
    });
  }
  function boot() {
    if (S.booted) return;
    if (!isStaff() || !api()) return;
    S.booted = true;
    load().then(function () { if (S.bell) { poll(); inbox(); } }).catch(function () {});
    S.timer = setInterval(poll, 1000);
    // A hidden tab's timers slow down: on return, ask straight away.
    d.addEventListener('visibilitychange', function () { if (!d.hidden && S.me) { claim(); if (Date.now() - S.lastInbox > 3000) inbox(); } });
    css();
  }
  var tries = 0;
  (function waitBody() { if (isStaff() && api()) return boot(); if (++tries < 120) setTimeout(waitBody, 1000); })();

  /* ---- styles (house look: tight controls, navy-teal) ---- */
  function css() {
    if (d.getElementById('kt-wt-css')) return;
    var s = d.createElement('style'); s.id = 'kt-wt-css';
    s.textContent = ''
      + '#kt-wt-card{position:fixed;right:16px;bottom:16px;z-index:2147483000;width:min(340px,calc(100vw - 32px));background:#fff;border:1px solid #CBD5E1;border-left:5px solid #159FB4;border-radius:14px;box-shadow:0 12px 32px rgba(15,23,42,.22);padding:12px 14px;font-size:14px;}'
      + '#kt-wt-card.em{border-left-color:#DC2626;}#kt-wt-card .wt-ch{font-size:12px;font-weight:800;color:#0E7490;text-transform:uppercase;letter-spacing:.04em;}#kt-wt-card.em .wt-ch{color:#B91C1C;}'
      + '#kt-wt-card .wt-who{margin:4px 0 9px;font-size:15px;}#kt-wt-card .wt-act{display:flex;gap:6px;flex-wrap:wrap;}#kt-wt-card .btn{height:30px;padding:0 10px;font-size:13px;}'
      + '@media (max-width:760px){#kt-wt-card{bottom:calc(env(safe-area-inset-bottom,0px) + 84px);}}'
      + '.wt-wrap{display:grid;grid-template-columns:minmax(240px,320px) 1fr;gap:14px;align-items:start;}@media (max-width:860px){.wt-wrap{grid-template-columns:1fr;}}'
      + '.wt-list{background:#fff;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;}.wt-list h3{margin:0;padding:9px 12px;font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;background:#F8FAFC;border-bottom:1px solid #E2E8F0;}'
      + '.wt-row{display:flex;align-items:center;gap:9px;width:100%;padding:7px 12px;border:0;border-bottom:1px solid #F1F5F9;background:#fff;text-align:left;font-size:13.5px;cursor:pointer;min-height:38px;}.wt-row:hover{background:#F0FAFC;}.wt-row.on{background:#E0F4F7;box-shadow:inset 3px 0 0 #159FB4;}'
      + '.wt-row .wt-sub{font-size:12px;color:#64748B;}.wt-row .wt-live{margin-left:auto;font-size:11.5px;font-weight:700;color:#B45309;white-space:nowrap;}.wt-row .wt-ico{width:26px;text-align:center;font-size:17px;}'
      + '.wt-scroll{max-height:calc(100vh - 330px);min-height:180px;overflow:auto;}.wt-q{margin:8px 10px;width:calc(100% - 20px);height:30px;border:1px solid #CBD5E1;border-radius:8px;padding:0 9px;font-size:13px;}'
      + '.wt-main{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px;}.wt-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}.wt-head h2{margin:0;font-size:18px;}'
      + '.wt-ptt{display:flex;flex-direction:column;align-items:center;gap:8px;margin:18px 0 14px;}'
      + '.wt-btn{width:148px;height:148px;border-radius:50%;border:0;background:radial-gradient(circle at 35% 30%,#2DB3C7,#0E5A75);color:#fff;font-size:44px;box-shadow:0 10px 26px rgba(14,90,117,.35);cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;transition:transform .08s;}'
      + '.wt-btn:active,.wt-btn.on{transform:scale(.96);background:radial-gradient(circle at 35% 30%,#F87171,#B91C1C);box-shadow:0 0 0 10px rgba(220,38,38,.15),0 10px 26px rgba(185,28,28,.35);}.wt-btn[disabled]{opacity:.45;cursor:not-allowed;}'
      + '.wt-state{font-size:14px;font-weight:700;color:#0F172A;min-height:20px;text-align:center;}.wt-hint{font-size:12.5px;color:#64748B;text-align:center;max-width:520px;}'
      + '.wt-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;margin-bottom:10px;}.wt-bar select,.wt-bar .btn{height:30px;font-size:13px;}'
      + '.wt-sec{font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;margin:16px 0 8px;}'
      + '.wt-set{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(230px,100%),1fr));gap:10px;}.wt-set label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px;}.wt-set select,.wt-set input[type=text],.wt-set input[type=number]{width:100%;height:30px;border:1px solid #CBD5E1;border-radius:8px;padding:0 8px;font-size:13px;}'
      + '.wt-note{font-size:12.5px;color:#475569;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;margin-top:10px;}';
    d.head.appendChild(s);
  }

  /* ---- the #walkie screen ---- */
  function fmtWhen(s) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); if (!m) return s || ''; var h = +m[4], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]) + ', ' + h + ':' + m[5] + ' ' + ap; }
  function avatar(p) { try { if (KT.avatar) return KT.avatar(p.name, { size: 26, photoUrl: p.photo_url, sex: p.sex, userId: p.id }); } catch (e) {} return '<span class="wt-ico">👤</span>'; }

  function render(main) {
    css();
    main.innerHTML = '<div class="kt-section"><div class="kt-hero"><div class="kt-hero-greet">📻 ' + esc(T('WALKIE-TALKIE')) + '</div><h1>' + esc(T('Walkie-talkie')) + '</h1>'
      + '<div class="kt-hero-sub">' + esc(T('Hold the button to talk. Everyone on the channel hears you live.')) + '</div></div><div id="wt-body"><div class="hr-sub" style="padding:12px 2px;">' + esc(T('Loading…')) + '</div></div></div>';
    var body = main.querySelector('#wt-body');
    if (!S.booted) boot();
    load().then(function (r) { draw(main, body, r); }, function (e) { body.innerHTML = '<div class="wt-note">' + esc(T(errMsg(e))) + '</div>'; });
  }

  function draw(main, body, r) {
    if (!r.enabled) {
      body.innerHTML = '<div class="wt-note">' + esc(T('The walkie-talkie is switched off for your agency.')) + (r.can_admin ? '' : ' ' + esc(T('Ask an agency admin to turn it on.'))) + '</div>' + (r.can_admin ? settingsHtml(r) : '');
      wireSettings(body, r, main); return;
    }
    var q = (location.hash.split('?')[1] || '').split('&').reduce(function (a, kv) { var p = kv.split('='); if (p[0]) a[p[0]] = decodeURIComponent(p[1] || ''); return a; }, {});
    var all = {}; r.channels.forEach(function (c) { all[c.key] = { key: c.key, label: c.label, icon: c.icon, can_talk: c.can_talk, emergency: c.emergency, talking: c.talking }; });
    r.people.forEach(function (p) { all[p.key] = { key: p.key, label: p.name, person: p, can_talk: true, talking: p.talking }; });
    var first = r.channels.filter(function (c) { return c.can_talk && !c.emergency; })[0] || r.channels[0] || null;
    S.sel = all[q.ch] ? q.ch : (S.sel && all[S.sel] ? S.sel : (first ? first.key : null));
    if (q.play) setTimeout(function () { playClip(+q.play); }, 300);
    var chRows = r.channels.map(function (c) {
      return '<button type="button" class="wt-row" data-ch="' + esc(c.key) + '"><span class="wt-ico">' + c.icon + '</span><span><span data-no-i18n>' + esc(c.label) + '</span>'
        + (c.can_talk ? '' : '<div class="wt-sub">' + esc(T('Listen only')) + '</div>') + '</span>' + (c.talking ? '<span class="wt-live">' + esc(c.talking) + ' ' + esc(T('is talking')) + '</span>' : '') + '</button>';
    }).join('');
    var pRows = r.people.map(function (p) {
      return '<button type="button" class="wt-row" data-ch="' + esc(p.key) + '" data-name="' + esc(String(p.name).toLowerCase()) + '">' + avatar(p) + '<span><span data-no-i18n>' + esc(p.name) + '</span><div class="wt-sub">'
        + esc(T(p.role || '')) + (p.centres && p.centres.length ? ' · <span data-no-i18n>' + esc(p.centres.join(', ')) + '</span>' : '') + '</div></span>' + (p.talking ? '<span class="wt-live">' + esc(T('talking')) + '</span>' : '') + '</button>';
    }).join('');
    var nativeNote = native() ? T('This phone listens all the time, even with the screen off, unless you turn on Do not disturb.')
      : (/iPhone|iPad/.test(navigator.userAgent) ? T('On iPhone, talks arrive as a notification: tap it to listen.') : T('In a browser you hear talks only while a KiddieTrac tab is open. The Android app listens all the time.'));
    body.innerHTML = '<div class="wt-wrap"><div class="wt-list"><h3>' + esc(T('Channels')) + '</h3>' + chRows
      + '<h3>' + esc(T('People')) + '</h3><input class="wt-q" type="search" data-wtq placeholder="' + esc(T('Search names')) + '"><div class="wt-scroll">' + (pRows || '<div class="wt-sub" style="padding:10px 12px;">' + esc(T('Nobody to call yet.')) + '</div>') + '</div></div>'
      + '<div class="wt-main"><div class="wt-head"><h2 data-wt-title data-no-i18n></h2></div>'
      + '<div class="wt-ptt"><button type="button" class="wt-btn" data-wt-ptt aria-label="' + esc(T('Hold to talk')) + '">🎙</button><div class="wt-state" data-wt-state></div>'
      + '<div class="wt-hint">' + esc(T('Hold to talk, let go to finish.')) + (matchMedia('(pointer:fine)').matches ? ' ' + esc(T('On a computer you can hold the spacebar.')) : '') + '</div></div>'
      + '<div class="wt-bar"><span style="font-size:13px;font-weight:700;">' + esc(T('Do not disturb')) + '</span><select data-wt-dnd><option value="off">' + esc(T('Off')) + '</option><option value="60">' + esc(T('For 1 hour')) + '</option><option value="240">' + esc(T('For 4 hours')) + '</option><option value="0">' + esc(T('Until I turn it off')) + '</option></select>'
      + '<span class="wt-sub" data-wt-dndnote style="font-size:12px;color:#64748B;"></span></div>'
      + '<div class="wt-note">' + esc(nativeNote) + ' ' + esc(T('The emergency channel always plays.')) + ' ' + esc(T('Talks are kept for')) + ' ' + (r.settings.retention_days || 30) + ' ' + esc(T('days, encrypted.')) + '</div>'
      + '<div class="wt-sec">' + esc(T('History')) + '</div><div data-wt-hist></div>'
      + (r.can_admin ? settingsHtml(r) : '') + '</div></div>';

    var title = body.querySelector('[data-wt-title]'), btn = body.querySelector('[data-wt-ptt]'), state = body.querySelector('[data-wt-state]');
    function select(k) {
      S.sel = k; var c = all[k];
      body.querySelectorAll('.wt-row').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-ch') === k); });
      title.textContent = c ? ((c.icon ? c.icon + ' ' : '📻 ') + c.label) : '';
      btn.disabled = !c || !c.can_talk;
      state.textContent = c && !c.can_talk ? T('You can listen to this channel but not talk on it.') : (c && c.talking ? c.talking + ' ' + T('is talking') : '');
      history();
    }
    var ui = { state: function (s, x) {
      btn.classList.toggle('on', s === 'live' || s === 'opening');
      if (s === 'opening') state.textContent = T('Starting…');
      else if (s === 'live') { var t0 = Date.now(); state.textContent = T('Talking…') + (x && x.recipients !== undefined ? ' · ' + x.recipients + ' ' + T('hearing') : ''); ui._iv = setInterval(function () { if (!btn.classList.contains('on')) return clearInterval(ui._iv); state.textContent = T('Talking…') + ' ' + Math.round((Date.now() - t0) / 1000) + 's' + (x && x.recipients !== undefined ? ' · ' + x.recipients + ' ' + T('hearing') : ''); }, 500); }
      else { clearInterval(ui._iv); state.textContent = x && x.duration_ms ? T('Sent') + ' · ' + (Math.round(x.duration_ms / 100) / 10) + 's' : ''; }
    } };
    function down(e) { if (btn.disabled || !S.sel) return; if (e) e.preventDefault(); talkStart(S.sel, ui); }
    function up(e) { if (e) e.preventDefault(); talkEnd(); }
    btn.addEventListener('pointerdown', function (e) { try { btn.setPointerCapture(e.pointerId); } catch (x) {} down(e); });
    btn.addEventListener('pointerup', up); btn.addEventListener('pointercancel', up);
    btn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    function kd(e) { if (e.code !== 'Space' || e.repeat || !d.body.contains(btn) || /INPUT|TEXTAREA|SELECT/.test((d.activeElement || {}).tagName || '') || (d.activeElement && d.activeElement.isContentEditable)) return; down(e); }
    function ku(e) { if (e.code !== 'Space' || !d.body.contains(btn)) return; if (S.talk) up(e); }
    if (S.keys) { d.removeEventListener('keydown', S.keys[0]); d.removeEventListener('keyup', S.keys[1]); }
    S.keys = [kd, ku]; d.addEventListener('keydown', kd); d.addEventListener('keyup', ku);
    body.querySelectorAll('.wt-row').forEach(function (b) { b.addEventListener('click', function () { select(b.getAttribute('data-ch')); }); });
    var qi = body.querySelector('[data-wtq]');
    qi.addEventListener('input', function () { var s = qi.value.trim().toLowerCase(); body.querySelectorAll('[data-name]').forEach(function (b) { b.style.display = !s || b.getAttribute('data-name').indexOf(s) >= 0 ? '' : 'none'; }); });

    var dnd = body.querySelector('[data-wt-dnd]'), dndNote = body.querySelector('[data-wt-dndnote]');
    function showDnd(p) { dnd.value = p.dnd ? (p.dnd_until ? '60' : '0') : 'off'; dndNote.textContent = p.dnd ? (p.dnd_until ? T('until') + ' ' + fmtWhen(p.dnd_until) : T('on')) : ''; if (p.dnd && p.dnd_until) dnd.value = 'x'; if (dnd.value === '') { var o = d.createElement('option'); o.value = 'x'; o.textContent = T('On'); dnd.appendChild(o); dnd.value = 'x'; } }
    showDnd(r.prefs);
    dnd.addEventListener('change', function () {
      var v = dnd.value; if (v === 'x') return;
      api().request('/walkie/prefs', { method: 'PUT', body: { dnd: v !== 'off', minutes: v === 'off' ? 0 : +v } }).then(function (p) { showDnd(p); toast('✅', 'Saved', '', '#16A34A'); }, function (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); });
    });

    var hist = body.querySelector('[data-wt-hist]');
    function history() {
      if (!S.sel) { hist.innerHTML = ''; return; }
      hist.innerHTML = '<div class="wt-sub" style="font-size:13px;color:#64748B;">' + esc(T('Loading…')) + '</div>';
      api().get('/walkie/history', { channel: S.sel }).then(function (h) {
        var items = h.items || [];
        if (!items.length) { hist.innerHTML = '<div class="wt-sub" style="font-size:13px;color:#64748B;">' + esc(T('Nothing said here yet.')) + '</div>'; return; }
        hist.innerHTML = '<table class="table" data-kt-pretty style="width:100%;"><thead><tr><th>' + esc(T('When')) + '</th><th>' + esc(T('Who')) + '</th><th>' + esc(T('Length')) + '</th><th>' + esc(T('Heard')) + '</th><th></th></tr></thead><tbody>'
          + items.map(function (it) {
            var heard = it.mine ? (it.heard + ' / ' + it.sent + (it.busy ? ' · ' + it.busy + ' ' + T('busy') : '')) : ({ heard: T('Heard'), busy: T('Busy'), dnd: T('Do not disturb'), off_shift: T('Not on shift'), sent: T('Not played') }[it.my_status] || '');
            return '<tr><td>' + esc(fmtWhen(it.at)) + '</td><td><span data-no-i18n>' + (it.emergency ? '🚨 ' : '') + esc(it.from) + '</span></td><td>' + it.seconds + 's</td><td>' + esc(heard) + '</td>'
              + '<td><button type="button" class="btn" data-play="' + it.id + '">▶ ' + esc(T('Play')) + '</button></td></tr>';
          }).join('') + '</tbody></table>';
        hist.querySelectorAll('[data-play]').forEach(function (b) { b.addEventListener('click', function () { playClip(+b.getAttribute('data-play')); }); });
      }, function (e) { hist.innerHTML = '<div class="wt-note">' + esc(T(errMsg(e))) + '</div>'; });
    }
    S.view = { history: history };
    wireSettings(body, r, main);
    select(S.sel);
  }

  function playClip(id) {
    var c = ctx(); if (!c) return;
    api().get('/walkie/tx/' + id + '/audio').then(function (a) {
      var u = unb64(a.data || ''); if (!u.length) return;
      var at = c.currentTime + 0.05; chirp(c, at, false); schedule(c, u, at + 0.12);
    }, function (e) { toast('⚠️', 'Could not play it', errMsg(e), '#DC2626'); });
  }

  function settingsHtml(r) {
    var s = r.settings || {};
    function opt(v, l, cur) { return '<option value="' + v + '"' + (String(cur) === String(v) ? ' selected' : '') + '>' + esc(T(l)) + '</option>'; }
    return '<div class="wt-sec">' + esc(T('Agency settings')) + '</div><div class="wt-set" data-wt-set>'
      + '<div><label>' + esc(T('Walkie-talkie')) + '</label><select data-k="enabled">' + opt('1', 'On', s.enabled ? '1' : '0') + opt('0', 'Off', s.enabled ? '1' : '0') + '</select></div>'
      + '<div><label>' + esc(T('Phones listen')) + '</label><select data-k="listen">' + opt('always', 'All the time (24/7)', s.listen) + opt('on_shift', 'Only while clocked in', s.listen) + '</select></div>'
      + '<div><label>' + esc(T('Keep recordings for (days)')) + '</label><input type="number" min="1" max="365" data-k="retention_days" value="' + esc(s.retention_days || 30) + '"></div>'
      + '<div><label>' + esc(T('Emergency channel')) + '</label><select data-k="emergency">' + opt('1', 'On (always plays)', s.emergency ? '1' : '0') + opt('0', 'Off', s.emergency ? '1' : '0') + '</select></div>'
      + '<div><label>' + esc(T('Emergency channel name')) + '</label><input type="text" maxlength="40" data-k="emergency_name" value="' + esc(s.emergency_name || 'Emergency') + '"></div>'
      + '<div><label>' + esc(T('Who can talk on it')) + '</label><select data-k="emergency_talkers">' + opt('leaders', 'Directors and admins', s.emergency_talkers) + opt('admins', 'Admins only', s.emergency_talkers) + '</select></div>'
      + '<div><label>' + esc(T('Educators can call')) + '</label><select data-k="educators_agency">' + opt('0', 'Staff at their own centres', s.educators_agency ? '1' : '0') + opt('1', 'Anyone on staff in the agency', s.educators_agency ? '1' : '0') + '</select></div>'
      + '</div><div class="wt-note">' + esc(T('Changes save as you make them and are recorded in the audit log.')) + '</div>';
  }
  function wireSettings(body, r, main) {
    var box = body.querySelector('[data-wt-set]'); if (!box) return;
    box.addEventListener('change', function (e) {
      var k = e.target.getAttribute('data-k'); if (!k) return;
      var v = e.target.value, o = {};
      o[k] = (k === 'enabled' || k === 'emergency' || k === 'educators_agency') ? v === '1' : (k === 'retention_days' ? +v : v);
      api().request('/walkie/settings', { method: 'PUT', body: o }).then(function () {
        toast('✅', 'Saved', '', '#16A34A');
        if (k === 'enabled' || k === 'emergency' || k === 'emergency_name' || k === 'emergency_talkers' || k === 'educators_agency') render(main);
      }, function (x) { toast('⚠️', 'Could not save', errMsg(x), '#DC2626'); });
    });
  }

  function reg() {
    if (!KT.Shell || !KT.Shell.registerScreen) return setTimeout(reg, 200);
    ['agency_admin', 'centre_director', 'educator', 'home_visitor'].forEach(function (role) { KT.Shell.registerScreen(role + ':walkie', render); });
  }
  reg();
  KT.walkie = { talkStart: talkStart, talkEnd: talkEnd, state: function () { return { me: !!S.me, native: S.nativeOn, leader: isLeader(), unlocked: unlocked(), queue: S.queue.length }; } };
})(window, document);
