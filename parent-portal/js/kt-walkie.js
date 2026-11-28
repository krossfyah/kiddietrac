/* ═══════════════════════════════════════════════════════════════════════
   📻 WALKIE-TALKIE (2026-11-27). Live push-to-talk between an agency's own staff (never parents).
   Server: App\Support\Walkie (how it works, who may talk to whom), WalkieController (the API).

   This file does three things, for staff roles only:
   1. LISTENS in every open portal tab: reads the person's "doorbell" (/wt/b/<hmac>.txt, a static file) every half
      second; a ring for a new talk carries the talk itself (sealed under the person's bell_key, 2026-11-28), so it
      plays at once - GET /walkie/inbox follows for the badge and replies (and is the way in for an old-style ring).
      Pieces as they arrive: AES-GCM pieces /wt/<dir>/<seq>.bin -> G.711 mu-law 16 kHz -> Web Audio, ~0.3 s jitter buffer. One tab plays
      (the leader, like kt-reminders.js). Inside the Android app the native listener plays instead (KtWalkie plugin),
      so the page only shows the "Amna is talking" card.
   2. TALKS: hold the 🎙 button (or the spacebar on the Walkie-talkie screen). The mic is captured at once, cut into
      ~400 ms mu-law pieces and posted to /walkie/talk/{id}/chunk; one speaker per channel (409 = someone is talking).
   3. The #walkie screen: channels, people, history (play back). Its settings live in Settings (2026-11-27, Anthony:
      "move the settings from the walkie talkie to the settings section"): yours (Do not disturb, Walkie sounds) in
      My settings › Notifications › 📻 Walkie-talkie (KT.walkie.myCard), the agency's in Settings › Agency
      notifications › 📻 Walkie-talkie (KT.walkie.agencyPane). The screen keeps a ⚙️ link to each.
   4. RADIO SOUNDS (see KIT below): press, release (roger), incoming, end of transmission.
   ═══════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT = w.KT || {};
  var STAFF_CLASSES = ['role-agency-admin', 'role-centre-director', 'role-educator', 'role-home-visitor'];
  var RATE = 16000, PIECE = 6400;            // 400 ms of 16 kHz samples
  /* FASTER TO THE EAR (2026-11-28, Anthony: "long delay receiving walkie-talkie messages, can you check to see why it's
     not instant"). Measured press -> voice heard was 1.8-4.8 s (median 3.7 s with the server busy). The path had four
     server round trips in a row (start, then the first piece; the listener's doorbell read, then walkie/inbox for the
     key), a 400 ms first piece, a 1 s doorbell read, and the 0.6 s incoming sound played only AFTER the first piece came.
     Now: the first piece is 200 ms and rides inside the start request; the doorbell carries the talk (no walkie/inbox
     wait); the doorbell is read every 0.5 s by the leader tab, on a worker clock (a hidden tab's own timers are slowed
     to once a minute by Chrome); the incoming sound plays the moment the ring is seen, the voice right after it. */
  var FIRST = 3200, POLL_MS = 500, POLL_SLOW_MS = 1500, START_WAIT_MS = 300, LEAD = 0.25;
  /* A WORKER CLOCK: timers inside a Worker are not throttled when the tab is in the background (main-page timers are,
     down to once a minute after five minutes hidden). later(fn, ms) = setTimeout that keeps time in a hidden tab. */
  var later = (function () {
    var wk = null, n = 0, cbs = {};
    try {
      var src = 'onmessage=function(e){var d=e.data;setTimeout(function(){postMessage(d)},d.ms)};';
      wk = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
      wk.onmessage = function (e) { var f = cbs[e.data.id]; delete cbs[e.data.id]; if (f) try { f(); } catch (x) {} };
      wk.onerror = function () { wk = null; };
    } catch (e) { wk = null; }
    return function (fn, ms) { if (!wk) return setTimeout(fn, ms); var id = ++n; cbs[id] = fn; wk.postMessage({ id: id, ms: ms }); return id; };
  })();
  var ID = Math.random().toString(36).slice(2);
  var S = { me: null, bell: null, bellV: null, played: {}, queue: [], busyPlay: false, ctx: null, nativeOn: false, talk: null,
    sel: null, replies: {}, card: null, timer: null, booted: false, worker: null, lastInbox: 0, bellKey: null, nativeBuild: 0 };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function T(s, c) { try { return (KT.i18n && KT.i18n.t) ? KT.i18n.t(s, c) : s; } catch (e) { return s; } }   // c = context key ('walkie' -> 'walkie::Play')
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
    if (!S.ctx) { var C = w.AudioContext || w.webkitAudioContext; if (!C) return null; S.ctx = new C(); preload(S.ctx); }
    if (S.ctx.state === 'suspended') { try { S.ctx.resume(); } catch (e) {} }
    return S.ctx;
  }
  function unlocked() { return !!(S.ctx && S.ctx.state === 'running'); }
  ['pointerdown', 'keydown', 'touchend'].forEach(function (ev) { d.addEventListener(ev, function () { if (S.me) ctx(); }, { capture: true, passive: true }); });
  /* RADIO SOUNDS (2026-11-27, Anthony: "use a walkie talkie type sound like how zello has it for receiving and sending
     messages"). Our OWN sounds, made here from tones and a little filtered noise - no audio files, nothing copied:
       press     two quick rising blips: you have the channel, talk now;
       release   a roger beep and a short squelch tail: you let go;
       rx        a squelch click and a rising sweep: someone starts talking to you;
       rxEnd     a short beep and a soft squelch: they finished;
       emergency three urgent beeps (the emergency channel; plays even with Walkie sounds off).
     Each is 0.1-0.4 s, quiet (well under speech), and never on top of a voice: it plays before or after it. The SAME
     recipe (segments, the noise generator) is in the Android app, KtWalkie.sound(), so a phone sounds the same.
     Segment = [t]one or [n]oise, start s, length s, from Hz, to Hz, level. */
  var KIT = {
    press: [['t', 0, 0.045, 1800, 1800, 0.16], ['t', 0.07, 0.045, 2400, 2400, 0.16]],
    release: [['t', 0, 0.07, 1250, 1050, 0.14], ['n', 0.075, 0.13, 0, 0, 0.06]],
    rx: [['n', 0, 0.035, 0, 0, 0.05], ['t', 0.035, 0.085, 900, 1500, 0.15]],
    rxEnd: [['t', 0, 0.04, 1050, 1050, 0.1], ['n', 0.045, 0.16, 0, 0, 0.07]],
    emergency: [['t', 0, 0.09, 1500, 1500, 0.22], ['t', 0.16, 0.09, 1500, 1500, 0.22], ['t', 0.32, 0.09, 1900, 1900, 0.22]]
  };
  var PCM = {};
  function soundPcm(name) {
    var segs = KIT[name] || [], len = 0;
    segs.forEach(function (s) { len = Math.max(len, s[1] + s[2]); });
    var n = Math.ceil(len * RATE), out = new Float32Array(n), seed = 12345, lp1 = 0, lp2 = 0, edge = Math.round(0.003 * RATE);
    segs.forEach(function (s) {
      var i0 = Math.round(s[1] * RATE), m = Math.round(s[2] * RATE), ph = 0;
      for (var i = 0; i < m && i0 + i < n; i++) {
        var env = Math.min(1, i / edge, (m - i) / edge), v;
        if (s[0] === 't') { ph += 2 * Math.PI * (s[3] + (s[4] - s[3]) * i / m) / RATE; v = Math.sin(ph); }
        else {   // band-limited hiss (two one-pole low-passes, subtracted), fading out: a radio squelch
          seed = (Math.imul(seed, 1103515245) + 12345) & 0x7fffffff;
          var x = seed / 1073741824 - 1; lp1 += 0.55 * (x - lp1); lp2 += 0.08 * (x - lp2); v = (lp1 - lp2) * 2.2; env *= Math.pow(1 - i / m, 1.6);
        }
        out[i0 + i] += v * env * s[5];
      }
    });
    return out;
  }
  /* Walkie sounds on? My settings › Notifications › 📻 Walkie-talkie (walkie_prefs.sounds). Emergency always beeps. */
  function soundsOn() { return !(S.me && S.me.prefs && S.me.prefs.sounds === false); }
  /* WALKIE VIBRATION (2026-11-28, Anthony: "walkie-talkie should also vibrate phone"). A tiny tick when you press to
     talk and when you let go (the press registered), a short buzz as a talk comes in, a long triple one for the
     emergency channel (which, like its beep, always comes through). Off with "Walkie vibration" in My settings ›
     Notifications; nothing in Do not disturb. navigator.vibrate: Android Chrome and the app's WebView; iPhone Safari
     has none (skipped). With the app listening natively (screen off / closed) KtWalkie.buzz() does it instead. */
  function vibOn() { return !(S.me && S.me.prefs && S.me.prefs.vibrate === false); }
  var BUZZ = { press: [14], release: [8, 50, 8], rx: [90, 70, 90], em: [400, 150, 400, 150, 600] };
  function buzz(kind) {
    try {
      if (!navigator.vibrate) return;
      if (kind !== 'em' && (!vibOn() || (kind === 'rx' && S.me && S.me.prefs && S.me.prefs.dnd))) return;
      navigator.vibrate(BUZZ[kind] || 0);
    } catch (e) {}
  }
  /* REAL RADIO SOUNDS (2026-11-27, Anthony chose them; licences allow commercial use, credited in My settings › About,
     KT.walkie.creditsCard). Recorded walkie sounds, trimmed / mono / made quieter (ffmpeg), served from /sounds/:
       press    "Function Beep.wav" by JustinBW (Freesound 70106, CC BY 4.0)
       release  "Walkie Talkie - Roger Beep" by bruce965 (Freesound 321906, CC0)
       rx       "Walkie Talkie Static Beep" by BudgetPixel AI (CC BY 4.0), 0.22-0.47 s of it with short fades
                (2026-11-28, Anthony chose a shorter incoming sound: 0.25 s, was 0.63 s; the voice follows its real length)
       rxEnd    "walkietalkie_eot.wav" by IneQuation (Freesound 27878, CC BY 3.0)
     The synthesised KIT above stays as the stand-in until a file has loaded (and for emergency). The Android app
     plays the same rx / rxEnd recordings (res/raw walkie_incoming / walkie_eot). */
  var FILES = { press: 'walkie_press', release: 'walkie_roger', rx: 'walkie_incoming', rxEnd: 'walkie_eot' }, BUF = {}, LOADING = {};
  function preload(c) {
    Object.keys(FILES).forEach(function (n) {
      if (BUF[n] || LOADING[n]) return; LOADING[n] = 1;
      fetch('/sounds/' + FILES[n] + '.mp3?v=1128s').then(function (r) { if (!r.ok) throw r.status; return r.arrayBuffer(); }).then(function (ab) {
        return new Promise(function (res, rej) { c.decodeAudioData(ab, res, rej); });   // callback form: older Safari
      }).then(function (b) { BUF[n] = b; }, function () { LOADING[n] = 0; });
    });
  }
  /** Play one radio sound at `at` (AudioContext time); returns when it ends (= at when it does not play). */
  function sound(c, name, at) {
    if (!c || (name !== 'emergency' && !soundsOn())) return at;
    if (BUF[name]) { var fs = c.createBufferSource(); fs.buffer = BUF[name]; fs.connect(c.destination); fs.start(at); return at + BUF[name].duration; }
    if (FILES[name]) preload(c);
    var pcm = PCM[name] || (PCM[name] = soundPcm(name));
    if (!pcm.length) return at;
    var buf = c.createBuffer(1, pcm.length, RATE); buf.getChannelData(0).set(pcm);
    var src = c.createBufferSource(); src.buffer = buf; src.connect(c.destination); src.start(at);
    return at + buf.duration;
  }
  function chirp(c, at, emergency) { return sound(c, emergency ? 'emergency' : 'rx', at); }
  function schedule(c, u8, at) {
    var buf = c.createBuffer(1, u8.length, RATE), ch = buf.getChannelData(0);
    for (var i = 0; i < u8.length; i++) ch[i] = DEC[u8[i]];
    var src = c.createBufferSource(); src.buffer = buf; src.connect(c.destination); src.start(at);
    return at + buf.duration;
  }

  /* ---- listening ---- */
  function poll() {
    if (!S.me || !S.bell || d.visibilityState === 'hidden' && !isLeader()) return Promise.resolve();
    return fetch(S.bell + '?r=' + Date.now() + Math.random().toString(36).slice(2, 6), { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (v) {
      v = (v || '').trim();
      if (!v) return;
      if (S.bellV === null) { S.bellV = v; return; }
      if (v === S.bellV) return;
      S.bellV = v;
      return openBell(v).then(function (t) {
        if (t) { enqueue(t); later(inbox, 1500); }   // the talk is already playing; the badge / replies can wait
        else inbox();
      });
    }).catch(function () {});
  }
  /* The doorbell loop: the leader every 0.5 s (also while hidden: it is the tab that plays), other open tabs every
     1.5 s (they only show the card), hidden followers not at all. Each read starts when the last one is done. */
  function pollLoop() { poll().then(next1, next1); function next1() { later(pollLoop, isLeader() ? POLL_MS : POLL_SLOW_MS); } }
  /* "<ms>.<base64 iv|ciphertext|tag>" -> the live talk (walkie/inbox's row), or null (an old-style ring, a ring for
     something else, a stale one, no key) - then walkie/inbox is asked as before. */
  function openBell(v) {
    var i = v.indexOf('.');
    if (i < 0 || !S.me || !S.me.bell_key || !(w.crypto && w.crypto.subtle)) return Promise.resolve(null);
    var kp = S.bellKey || (S.bellKey = w.crypto.subtle.importKey('raw', unb64(S.me.bell_key), 'AES-GCM', false, ['decrypt']));
    return kp.then(function (k) {
      var u = unb64(v.slice(i + 1));
      return w.crypto.subtle.decrypt({ name: 'AES-GCM', iv: u.subarray(0, 12) }, k, u.subarray(12));
    }).then(function (b) {
      var o = JSON.parse(new TextDecoder().decode(b));
      if (!o || !o.live || !o.live.id || !o.live.key) return null;
      if (o.ts && Math.abs(Date.now() - o.ts) > 120000) return null;   // old (or a skewed clock): walkie/inbox decides
      return o.live;
    }).catch(function () { S.bellKey = null; return null; });
  }
  function enqueue(t) {
    if (!t || S.played[t.id]) return;
    S.played[t.id] = 1;
    nativePlay(t);
    S.queue.push(t); next();
  }
  /* Inside the Android app (3.13.5 / vc81+) the native listener plays it straight away from the ring, without waiting
     for Google's push (which still comes, and is skipped as already played). Older apps: the push plays it. */
  function nativePlay(t) {
    var n = native();
    if (!S.nativeOn || S.nativeBuild < 81 || !n || !n.play) return;
    try { n.play({ talk: JSON.stringify(t), sounds: soundsOn(), vibrate: vibOn() }).catch(function () {}); } catch (e) {}
  }
  function inbox() {
    S.lastInbox = Date.now();
    api().get('/walkie/inbox').then(function (r) {
      (r.live || []).forEach(function (t) { if (!S.played[t.id]) { S.played[t.id] = 1; nativePlay(t); S.queue.push(t); } });
      (r.replies || []).forEach(function (x) { var k = x.tx + ':' + x.from; if (!S.replies[k]) { S.replies[k] = 1; toast('📻', x.from + ' can\'t talk right now', 'Walkie-talkie reply', '#B45309'); } });
      emit('kt:walkie-inbox', { unplayed: +r.unplayed || 0, latest: r.latest || null });
      next();
    }).catch(function () {});
  }
  function next() {
    if (S.busyPlay || !S.queue.length) return;
    var t = S.queue.shift();
    S.busyPlay = true;
    play(t).then(function () { S.busyPlay = false; next(); }, function () { S.busyPlay = false; next(); });
  }

  /* THE REPLY CARD (2026-11-28, Anthony: "if a message comes through pop up the mic button to talk"). Someone talks to
     you anywhere in the portal or the app (walkie on, not Do not disturb): ONE card on top (just under the urgent and
     idle-warning layers) with who is talking + a live dot, a BIG hold-to-talk mic that answers straight back to them
     (their direct line for a direct talk, the channel for a channel talk; it becomes the last chat), ▶ Replay once they
     finish, and ✕. A new talk updates the same card (never a stack). It goes ~20s after the talk ends unless touched.
     Not on the Walkie-talkie screen (it has its own mic) and not for the emergency channel (its own alert stays). */
  var MIC = '<svg class="wt-mic" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="8.6" y="2.2" width="6.8" height="12.2" rx="3.4" fill="#fff" stroke="none"/><path d="M5 11a7 7 0 0 0 14 0M12 18.2v3.3M8.4 21.5h7.2"/></svg>';
  /* ONE HOLD-TO-TALK HANDLER for every talk button (the screen, the top-bar popover / phone sheet, the reply card).
     Press: pointerdown (finger, mouse, pen) or Space/Enter held on it. Let go: pointerup / pointercancel / touchend /
     touchcancel ANYWHERE on the page (the button may be re-drawn, disabled or under the finger no more), the finger
     sliding well off the button (> 48px), the window losing focus, the app/tab going to the background, the button
     leaving the page. Long-press callout, text selection and the context menu are blocked on it. getKey() says who to
     talk to; ui.state() as talkStart. Returns { release }. */
  function bindPtt(btn, getKey, ui) {
    if (!btn || btn._ptt) return btn && btn._ptt;
    var held = null, wd = null, box = null;
    btn.style.touchAction = 'none'; btn.style.webkitUserSelect = 'none'; btn.style.userSelect = 'none'; btn.style.webkitTouchCallout = 'none';
    btn.setAttribute('draggable', 'false');
    function on(t, f, o) { w.addEventListener(t, f, o); }
    function off(t, f, o) { w.removeEventListener(t, f, o); }
    function arm() {
      on('pointerup', onUp, true); on('pointercancel', onUp, true); on('pointermove', onMove, true);
      on('touchend', onTouch, true); on('touchcancel', onTouch, true); on('blur', release); on('pagehide', release);
      d.addEventListener('visibilitychange', onVis);
      wd = setInterval(function () { if (held === null) return; if (!btn.isConnected || (S.talk === null && !btn.classList.contains('on') && Date.now() - held.t > 1500)) release(); }, 700);
    }
    function disarm() {
      off('pointerup', onUp, true); off('pointercancel', onUp, true); off('pointermove', onMove, true);
      off('touchend', onTouch, true); off('touchcancel', onTouch, true); off('blur', release); off('pagehide', release);
      d.removeEventListener('visibilitychange', onVis); clearInterval(wd);
    }
    function press(pid, e) {
      if (held !== null || btn.disabled) return;
      var k = getKey(); if (!k) return;
      if (e && e.preventDefault) e.preventDefault();
      held = { id: pid, t: Date.now() }; box = btn.getBoundingClientRect();
      arm(); buzz('press'); talkStart(k, ui);
    }
    function release() { if (held === null) return; held = null; disarm(); buzz('release'); talkEnd(); }
    function onUp(e) { if (held !== null && (held.id === 'key' || e.pointerId === held.id || e.type === 'pointercancel')) release(); }
    function onTouch(e) { if (held !== null && held.id !== 'key' && (!e.touches || !e.touches.length)) release(); }
    function onVis() { if (d.hidden) release(); }
    function onMove(e) {
      if (held === null || e.pointerId !== held.id || !box) return;
      var m = 48; if (e.clientX < box.left - m || e.clientX > box.right + m || e.clientY < box.top - m || e.clientY > box.bottom + m) release();
    }
    btn.addEventListener('pointerdown', function (e) { if (e.button && e.button !== 0) return; try { btn.setPointerCapture(e.pointerId); } catch (x) {} press(e.pointerId, e); });
    btn.addEventListener('lostpointercapture', function () { if (held !== null && !btn.isConnected) release(); });
    btn.addEventListener('touchstart', function (e) { if (e.cancelable) e.preventDefault(); }, { passive: false });   // no long-press callout, no emulated mouse
    btn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    btn.addEventListener('click', function (e) { e.preventDefault(); });
    btn.addEventListener('keydown', function (e) { if ((e.key === ' ' || e.key === 'Enter') && !e.repeat) press('key', e); else if (e.key === ' ' || e.key === 'Enter') e.preventDefault(); });
    btn.addEventListener('keyup', function (e) { if ((e.key === ' ' || e.key === 'Enter') && held && held.id === 'key') { e.preventDefault(); release(); } });
    warm();
    btn._ptt = { release: release, held: function () { return held !== null; } };
    return btn._ptt;
  }
  function onWalkieScreen() { return /^#walkie\b/.test(location.hash || ''); }
  function showCard(t, needTap) {
    if (S.card && S.card.talking) return S.card.el;   // replying right now: leave that card (and the held mic) alone
    hideCard();
    var reply = !t.emergency && !onWalkieScreen() && !!(t.back || t.channel);
    var c = d.createElement('div'); c.id = 'kt-wt-card'; c.setAttribute('role', 'alert'); c.setAttribute('data-kt-iconized', '1');
    c.className = (t.emergency ? 'em' : '') + (reply ? ' wt-rc' : '');
    var who = '<div class="wt-who"><i class="wt-livedot" aria-hidden="true"></i><span><b data-no-i18n>' + esc(t.from) + '</b> <span data-wt-state>' + esc(T('is talking')) + '</span></span></div>';
    if (!reply) {
      c.innerHTML = '<div class="wt-ch">' + (t.emergency ? '🚨 ' : '📻 ') + esc(T(t.label)) + '</div>' + who
        + '<div class="wt-act">' + (needTap ? '<button type="button" class="btn btn-primary" data-wt="listen">▶ ' + esc(T('Listen')) + '</button>' : '')
        + '<button type="button" class="btn" data-wt="busy">' + esc(T('Busy, can\'t talk')) + '</button>'
        + '<button type="button" class="btn" data-wt="reply">🎙 ' + esc(T('Reply')) + '</button></div>';
    } else {
      c.innerHTML = '<div class="wt-ch"><span>📻 ' + esc(T(t.label)) + '</span><button type="button" class="wt-rx" data-wt="x" aria-label="' + esc(T('Close')) + '" data-kttip="' + esc(T('Close')) + '">✕</button></div>'
        + '<div class="wt-rbody"><button type="button" class="wt-rmic kt-ptt-o busy" data-wt-rmic aria-label="' + esc(T('Hold to reply')) + '">' + MIC + '</button><div class="wt-rside">' + who
        + '<div class="wt-rst" aria-live="polite">' + esc(T('Hold the mic to reply')) + '</div>'
        + '<div class="wt-act">' + (needTap ? '<button type="button" class="btn btn-primary" data-wt="listen">▶ ' + esc(T('Listen')) + '</button>' : '')
        + '<button type="button" class="btn" data-wt="replay" hidden>▶ ' + esc(T('Replay')) + '</button>'
        + '<button type="button" class="btn" data-wt="busy">' + esc(T('Busy, can\'t talk')) + '</button></div></div></div>';
    }
    d.body.appendChild(c);
    var card = S.card = { el: c, t: t, reply: reply, talking: false, touched: false, hideT: null };
    if (reply) wireReply(card);
    emit('kt:walkie-live', { on: true, from: t.from, label: t.label, channel: t.channel, emergency: !!t.emergency });
    return c;
  }
  function wireReply(card) {
    var c = card.el, t = card.t, mic = c.querySelector('[data-wt-rmic]'), st = c.querySelector('.wt-rst'), key = t.back || t.channel;
    function touch() { card.touched = true; clearTimeout(card.hideT); }
    c.addEventListener('pointerdown', touch, true);
    c.addEventListener('keydown', touch, true);
    var ui = { state: function (s, x) {
      card.talking = s === 'live' || s === 'opening';
      mic.classList.toggle('on', card.talking);
      if (s === 'opening') st.textContent = T('Starting…');
      else if (s === 'live') st.textContent = T('Talking…') + (x && x.recipients !== undefined ? ' · ' + x.recipients + ' ' + T('hearing') : '');
      else st.textContent = x && x.duration_ms ? T('Sent') + ' · ' + (Math.round(x.duration_ms / 100) / 10) + 's' : T('Hold the mic to reply');
      if (!card.talking && !S.card) c.remove();   // dismissed while talking: let go, then go
    } };
    bindPtt(mic, function () { remember(key); return key; }, ui);
    c.addEventListener('click', function (e) {
      var b = e.target.closest('[data-wt]'); if (!b) return;
      var a = b.getAttribute('data-wt');
      if (a === 'x') { if (card.talking) talkEnd(); hideCard(); }
      if (a === 'replay') { b.disabled = true; playClip(t.id).then(function (secs) { setTimeout(function () { b.disabled = false; }, Math.round((+secs || 0) * 1000)); }); }
    });
  }
  /* They finished: the reply card stays (live dot off, ▶ Replay), and goes ~20s later unless touched. */
  function endCard(t) {
    var card = S.card; if (!card || card.t !== t) return false;
    if (!card.reply) return false;
    var c = card.el; c.classList.add('ended');
    var rm = c.querySelector('[data-wt-rmic]'); if (rm) rm.classList.remove('busy');
    var s = c.querySelector('[data-wt-state]'); if (s) s.textContent = T('talked to you') + (t.label && t.label !== 'Direct' ? ' · ' + T(t.label) : '');
    var rp = c.querySelector('[data-wt="replay"]'); if (rp) rp.hidden = false;
    var ls1 = c.querySelector('[data-wt="listen"]'); if (ls1) ls1.remove();
    emit('kt:walkie-live', { on: false });
    clearTimeout(card.hideT);
    if (!card.touched) card.hideT = setTimeout(function () { if (S.card === card && !card.touched && !card.talking) hideCard(); }, 20000);
    return true;
  }
  function hideCard() {
    var had = !!S.card, card = S.card;
    if (card) { clearTimeout(card.hideT); if (!card.talking && card.el) card.el.remove(); }
    S.card = null; if (had) emit('kt:walkie-live', { on: false });
  }
  /* Hooks for the top-bar 📻 quick popover (kt-walkie-quick.js, 2026-11-28): window events, never a fetch of their own.
     kt:walkie-me (the /walkie/me answer), kt:walkie-inbox {unplayed, latest}, kt:walkie-live {on, from, label}. */
  function emit(name, detail) { try { w.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {} }
  function remember(k) {
    if (!k || (S.me && S.me.last === k)) return;
    if (S.me) S.me.last = k;
    emit('kt:walkie-last', { key: k });
    api().request('/walkie/prefs', { method: 'PUT', body: { last: k }, quiet: true }).catch(function () {});
  }

  function play(t) {
    return new Promise(function (resolve) {
      var c = ctx();
      var playHere = !S.nativeOn && isLeader() && !!c && !!(w.crypto && w.crypto.subtle);
      var card = showCard(t, playHere && !unlocked());
      if (!S.nativeOn) buzz(t.emergency ? 'em' : 'rx');   // the app's own listener buzzes when it is the one playing
      var stop = false, done = false, key = null, heard = false;
      function finish() { if (done) return; done = true; setTimeout(function () { if (S.card && S.card.t === t && (stop || !endCard(t))) hideCard(); if (S.view && d.querySelector('[data-wt-hist]')) S.view.history(); resolve(); }, 900); }
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
      var chirpEnd = null;
      w.crypto.subtle.importKey('raw', unb64(t.key), 'AES-GCM', false, ['decrypt']).then(function (k) {
        key = k;
        // The incoming sound NOW, while the first piece is still on its way (it used to wait for it: +0.6 s).
        if (unlocked()) { var c1 = ctx(); chirpEnd = chirp(c1, c1.currentTime + 0.02, t.emergency); }
        loop(0, 0, null, Date.now());
      }, finish);
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
            if (at === null) at = Math.max(now + LEAD, (chirpEnd !== null ? chirpEnd : chirp(c2, now + 0.02, t.emergency)) + 0.06);   // the incoming sound, then the voice
            else if (at < now + 0.05) at = now + 0.12;
            at = schedule(c2, new Uint8Array(pcm), at);
            markHeard(false);
            loop(seq + 1, 0, at, Date.now());
          });
        }).catch(function () {
          if (Date.now() - since > 12000) return finish();
          if (misses % 4 === 3) {
            fetch(t.live + 'end.txt?r=' + Date.now(), { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (v) {
              var n = parseInt(v, 10);
              if (!isNaN(n) && seq >= n) {
                var c3 = S.ctx, end = c3 && at ? sound(c3, 'rxEnd', Math.max(at, c3.currentTime) + 0.03) : 0;   // they finished
                var left = c3 && end ? Math.max(0, (end - c3.currentTime) * 1000) : 0; setTimeout(finish, left);
              }
              else later(function () { loop(seq, misses + 1, at, since); }, 100);
            }).catch(function () { later(function () { loop(seq, misses + 1, at, since); }, 150); });
          } else later(function () { loop(seq, misses + 1, at, since); }, 100);
        });
      }
    });
  }

  /* ---- talking ---- */
  /* A QUICK, NEVER-STUCK TALK BUTTON (2026-11-28, Anthony: "the walkie talkie is not quick on the mobile app, the mic
     button tends to stick"). Causes found: (1) every press asked for the microphone again (getUserMedia: the Android
     WebView goes through its permission bridge each time, ~0.3-1s) and the button only went "live" after the server
     answered too; (2) the release was heard only by the button itself - a re-drawn or disabled button, a finger lifted
     off it, a long-press callout or the app going to the background lost the "up" and the talk ran on, and a talk whose
     microphone or server answer never came left S.talk set, so every later press was ignored.
     Now: the microphone stays open ("warm") while a talk button is on screen and for 30s after the last talk, recording
     starts the moment it is pressed (the press sound and the red button with it) while the server start runs alongside
     (pieces wait for it), the release is caught anywhere on the page (bindPtt), and a watchdog lets go of anything that
     did not finish (no microphone after 6s, no server answer 8s after letting go, the button gone). */
  var WARM_MS = 30000, AUDIO = { audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } };
  function liveStream(st) { return !!(st && st.getAudioTracks && st.getAudioTracks().some(function (x) { return x.readyState === 'live'; })); }
  function cool() { clearTimeout(S.warmT); if (S.talk) { S.warmT = setTimeout(cool, WARM_MS); return; } var st = S.warm; S.warm = null; try { st && st.getTracks().forEach(function (x) { x.stop(); }); } catch (e) {} }
  function keepWarm() { clearTimeout(S.warmT); S.warmT = setTimeout(cool, WARM_MS); }
  /* Open the microphone ahead of the press - only when it is already allowed (never a permission prompt just for
     opening a screen). Where the browser cannot tell, the first talk opens it and later ones reuse it. */
  function warm() {
    keepWarm();
    if (liveStream(S.warm) || S.warming) return;
    var md = navigator.mediaDevices, pq = navigator.permissions && navigator.permissions.query;
    if (!md || !md.getUserMedia || !pq) return;
    S.warming = true;
    navigator.permissions.query({ name: 'microphone' }).then(function (r) {
      if (r.state !== 'granted') { S.warming = false; return; }
      return md.getUserMedia(AUDIO).then(function (st) { S.warming = false; if (liveStream(S.warm)) { st.getTracks().forEach(function (x) { x.stop(); }); return; } S.warm = st; keepWarm(); });
    }).catch(function () { S.warming = false; });
  }
  function talkStart(channel, ui) {
    if (S.talk) {   // a talk that never finished (no microphone / no server answer): let go of it rather than refuse
      if (Date.now() - S.talk.t0 < 1500 || S.talk.id) return;
      forceEnd(S.talk);
    }
    var md = navigator.mediaDevices;
    if (!md || !md.getUserMedia) { toast('⚠️', 'This browser cannot use the microphone', '', '#DC2626'); return; }
    var c = ctx(); if (!c) return;
    if (S.me && channel && channel.indexOf('emergency') !== 0) { S.me.last = channel; emit('kt:walkie-last', { key: channel }); }
    var tk = S.talk = { channel: channel, id: null, seq: 0, pend: [], buf: new Float32Array(PIECE), fill: 0, pos: 0, ups: [], released: false, t0: Date.now(), ui: ui, sent: false };
    ui && ui.state('opening');
    var gotMic = function (stream) {
      clearTimeout(tk.micT);
      if (tk.failed || tk.forced) { if (stream !== S.warm) { S.warm = liveStream(S.warm) ? S.warm : stream; } keepWarm(); return; }   // the start was refused meanwhile: keep the mic warm for the next press
      tk.stream = stream; S.warm = stream; keepWarm();
      var src = c.createMediaStreamSource(stream), proc = c.createScriptProcessor(2048, 1, 1), ratio = c.sampleRate / RATE;
      tk.src = src; tk.proc = proc;
      proc.onaudioprocess = function (e) {
        if (tk.stopped) return;
        var inp = e.inputBuffer.getChannelData(0);
        for (; tk.pos < inp.length; tk.pos += ratio) {
          var i = tk.pos | 0, f = tk.pos - i, v = inp[i] + ((inp[i + 1] !== undefined ? inp[i + 1] : inp[i]) - inp[i]) * f;
          tk.buf[tk.fill++] = v;
          if (tk.fill === (tk.seq === 0 ? FIRST : PIECE)) piece(tk);
        }
        tk.pos -= inp.length;
      };
      src.connect(proc); proc.connect(c.destination);
      // Recording from here: the press sound and the live button now, not after the server answers (it runs alongside).
      if (!tk.released) { sound(c, 'press', c.currentTime + 0.01); tk.liveUi = true; ui && ui.state('live', tk.ack || null); }
      if (tk.released) talkEnd();
    };
    var noMic = function () {
      clearTimeout(tk.micT); clearTimeout(tk.goT);
      if (tk.failed || tk.forced) return;
      tk.failed = true; teardown(tk); if (S.talk === tk) S.talk = null; ui && ui.state('idle');
      if (tk.id) api().post('/walkie/talk/' + tk.id + '/end', { chunks: 0 }, { quiet: true }).catch(function () {});
      toast('🎙', 'Microphone blocked', 'Allow the microphone for KiddieTrac to talk.', '#DC2626');
    };
    if (liveStream(S.warm)) gotMic(S.warm);   // warm: recording starts now, no wait at all
    else {
      md.getUserMedia(AUDIO).then(gotMic, noMic);
      tk.micT = setTimeout(function () { if (!tk.stream && !tk.failed) { noMic(); } }, 6000);   // a permission bridge that never answers
    }
    /* The start goes with the first 0.2 s piece (one round trip, and the piece is there when the doorbells ring); when
       the microphone is not running yet it goes on its own after START_WAIT_MS, and the pieces follow. */
    tk.go = function (first) {
      if (tk.sent || tk.failed || tk.forced) return;
      tk.sent = true; clearTimeout(tk.goT);
      var body = { channel: channel }; if (first) body.first = first;
      api().post('/walkie/talk', body, { quiet: true }).then(started, refused);
    };
    tk.goT = setTimeout(function () { tk.go(null); }, START_WAIT_MS);
    function started(r) {
      tk.id = r.id;
      if (tk.failed || tk.forced) { api().post('/walkie/talk/' + tk.id + '/end', { chunks: 0 }, { quiet: true }).catch(function () {}); return; }   // no microphone / let go of
      tk.ack = r;
      if (tk.liveUi && !tk.released) ui && ui.state('live', r);   // now with how many are hearing
      tk.pend.splice(0).forEach(function (p) { send(tk, p); });
      if (tk.released) talkEnd();
    }
    function refused(e) {
      tk.failed = true; teardown(tk); if (S.talk === tk) S.talk = null; ui && ui.state('idle');
      if (e && e.status === 409) toast('📻', (e.data && e.data.talking ? e.data.talking + ' is talking' : 'Someone is talking'), 'Try again in a moment.', '#B45309');
      else toast('⚠️', 'Could not start talking', errMsg(e), '#DC2626');
    }
    tk.cap = setTimeout(function () { if (S.talk === tk) talkEnd(); }, ((S.me && S.me.max_secs) || 60) * 1000);
  }
  function piece(tk) {
    var n = tk.fill, out = new Uint8Array(n);
    for (var i = 0; i < n; i++) out[i] = mulaw(tk.buf[i]);
    tk.fill = 0;
    var p = { seq: tk.seq++, data: b64(out) };
    if (p.seq === 0 && !tk.sent) { tk.go(p.data); return; }   // the first piece rides with the start
    if (tk.id) send(tk, p); else tk.pend.push(p);
  }
  function send(tk, p) {
    var pr = api().post('/walkie/talk/' + tk.id + '/chunk', p, { quiet: true }).then(function (r) { if (r && r.stop && S.talk === tk) talkEnd(); }, function () {});
    tk.ups.push(pr);
  }
  function teardown(tk) {
    tk.stopped = true;
    try { tk.proc && tk.proc.disconnect(); tk.src && tk.src.disconnect(); } catch (e) {}
    // The microphone itself stays open a little while (warm) for the next press; cool() closes it.
    if (tk.stream && tk.stream !== S.warm) { try { tk.stream.getTracks().forEach(function (x) { x.stop(); }); } catch (e) {} }
    keepWarm();
    clearTimeout(tk.cap); clearTimeout(tk.micT); clearTimeout(tk.relT); clearTimeout(tk.goT);
  }
  /* Let go of a talk that cannot finish normally (no server answer after the release): the button is free again. */
  function forceEnd(tk) {
    if (!tk || tk.forced) return;
    tk.forced = true; teardown(tk); if (S.talk === tk) S.talk = null;
    if (tk.id) api().post('/walkie/talk/' + tk.id + '/end', { chunks: tk.seq }, { quiet: true }).catch(function () {});
    tk.ui && tk.ui.state('idle');
  }
  function talkEnd() {
    var tk = S.talk; if (!tk) return;
    tk.released = true;
    if (!tk.sent && !(tk.stream && (tk.fill > 0 || tk.seq > 0))) {   // let go before anything was recorded: no talk at all
      tk.forced = true; teardown(tk); if (S.talk === tk) S.talk = null; tk.ui && tk.ui.state('idle'); return;
    }
    if (!tk.id || !tk.stream) {   // finishes when the start / mic answers - but never waits forever
      if (tk.failed) S.talk = null;
      else { if (tk.stream && tk.fill > 0) piece(tk); clearTimeout(tk.relT); tk.relT = setTimeout(function () { forceEnd(tk); }, 8000); }
      return;
    }
    if (tk.fill > 0) piece(tk);
    teardown(tk);
    if (S.ctx) sound(S.ctx, 'release', S.ctx.currentTime + 0.02);   // roger: the mic is already off
    S.talk = null;
    var ui = tk.ui;
    ui && ui.state('idle');   // the button lets go at once; "Sent · 2.1s" follows when the server has it all
    Promise.all(tk.ups).then(function () { return api().post('/walkie/talk/' + tk.id + '/end', { chunks: tk.seq }, { quiet: true }); }).then(function (r) {
      ui && ui.state('idle', r); if (S.view) S.view.history();
    }, function () { ui && ui.state('idle'); });
  }

  /* ---- boot ---- */
  function load() {
    return api().get('/walkie/me').then(function (r) {
      emit('kt:walkie-me', r || null);
      if (!r || !r.enabled) { S.me = r && r.me ? r : null; S.bell = null; return r; }
      S.me = r; S.bell = r.bell; S.bellKey = null;
      var n = native();
      if (n && n.status) {
        n.status().then(function (st) {
          S.nativeOn = !!(st && st.configured && String(st.userId) === String(r.me.id));
          S.nativeBuild = +(st && st.build) || 0;
          if (!S.nativeOn && st && st.pushToken) {
            api().post('/walkie/device', { push_token: st.pushToken, platform: 'android', app_build: st.build || '' }, { quiet: true }).then(function (x) {
              return n.configure({ base: location.origin, secret: x.secret, userId: String(r.me.id), name: r.me.name });
            }).then(function () { S.nativeOn = true; }).catch(function () {});
          }
        }).catch(function () {});
      }
      return r;
    }, function (e) {
      S.me = null; S.bell = null; emit('kt:walkie-me', null);
      var n = native(); if (n && n.disable && e && (e.status === 401 || e.status === 403)) { try { n.disable(); } catch (x) {} }
      throw e;
    });
  }
  function boot() {
    if (S.booted) return;
    if (!isStaff() || !api()) return;
    S.booted = true;
    load().then(function () { if (S.bell) inbox(); }).catch(function () {}).then(pollLoop);
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
      + '#kt-wt-card .wt-livedot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#16A34A;margin-right:7px;vertical-align:1px;animation:wtLive 1s ease-in-out infinite;}#kt-wt-card.em .wt-livedot{background:#DC2626;}#kt-wt-card.ended .wt-livedot{animation:none;background:#94A3B8;}'
      + '@keyframes wtLive{50%{opacity:.25;}}'
      + '#kt-wt-card.wt-rc{width:min(360px,calc(100vw - 24px));padding:10px 12px 12px;color:#0F172A;}'
      + '#kt-wt-card.wt-rc .wt-ch{display:flex;align-items:center;justify-content:space-between;gap:8px;}'
      + '#kt-wt-card button.wt-rx{width:28px;height:28px;min-width:0;min-height:0;padding:0;border:0;border-radius:8px;background:transparent;color:#334155;font-size:15px;font-weight:800;cursor:pointer;}#kt-wt-card button.wt-rx:hover{background:#F1F5F9;}'
      + '#kt-wt-card .wt-rbody{display:flex;align-items:center;gap:12px;margin-top:6px;}'
      + '#kt-wt-card button.wt-rmic{flex:0 0 auto;width:96px;height:96px;min-height:0;border-radius:50%;border:0;background:radial-gradient(circle at 35% 30%,#2DB3C7,#0E5A75);color:#fff;font-size:44px;box-shadow:0 8px 22px rgba(14,90,117,.35);cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;transition:transform .08s;}'
      + '#kt-wt-card button.wt-rmic.on{transform:scale(.96);background:radial-gradient(circle at 35% 30%,#F87171,#B91C1C);box-shadow:0 0 0 9px rgba(220,38,38,.15),0 8px 22px rgba(185,28,28,.35);}'
      + '#kt-wt-card button.wt-rmic:focus-visible{outline:3px solid #0E7490;outline-offset:3px;}'
      + '#kt-wt-card .wt-rside{flex:1;min-width:0;}#kt-wt-card.wt-rc .wt-who{margin:0 0 3px;}#kt-wt-card .wt-rst{font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;}'
      + '#kt-wt-card .btn[hidden]{display:none;}'
      /* phones: the reply mic big and central (2026-11-28, Anthony: "the mic button needs to be bigger") */
      + '@media (max-width:600px){#kt-wt-card.wt-rc .wt-rbody{flex-direction:column;align-items:stretch;gap:8px;}#kt-wt-card.wt-rc button.wt-rmic{align-self:center;width:clamp(150px,44vw,172px);height:clamp(150px,44vw,172px);font-size:clamp(62px,18vw,74px);order:2;}'
      +   '#kt-wt-card.wt-rc .wt-rside{display:contents;}#kt-wt-card.wt-rc .wt-who{order:1;}#kt-wt-card.wt-rc .wt-rst{order:3;text-align:center;margin:0;}#kt-wt-card.wt-rc .wt-act{order:4;justify-content:center;}}'
      /* THE TALK BUTTON IS ORANGE (2026-11-28, Anthony: "the hold-to-talk mic button should be a bold ORANGE that stands
         out"). Same everywhere (this screen, the top-bar popover, the phone sheet, the reply card): bright #F97316 rim
         into a #C2410C middle so the white mic sits on 5.2:1 (white on #F97316 alone is 2.8:1); transmitting = deeper
         #9A3412 with a glowing orange ring; someone else talking = slate; the emergency channel keeps red. A functional
         colour: white-label tints never restyle it. */
      + '.kt-ptt-o{background:radial-gradient(circle at 50% 50%,#C2410C 0,#C2410C 44%,#EA580C 68%,#F97316 100%) !important;color:#fff !important;box-shadow:0 8px 22px rgba(194,65,12,.38),inset 0 2px 0 rgba(255,255,255,.25) !important;}'
      + '.kt-ptt-o:hover{filter:brightness(1.05);}'
      + '.kt-ptt-o.on{background:radial-gradient(circle at 50% 50%,#9A3412 0,#9A3412 50%,#C2410C 78%,#EA580C 100%) !important;box-shadow:0 0 0 8px rgba(249,115,22,.30),0 0 26px 8px rgba(249,115,22,.45) !important;animation:ktPttGlow 1.1s ease-in-out infinite;}'
      + '@keyframes ktPttGlow{50%{box-shadow:0 0 0 12px rgba(249,115,22,.18),0 0 32px 12px rgba(249,115,22,.30);}}'
      + '.kt-ptt-o.busy:not(.on){background:radial-gradient(circle at 50% 50%,#475569 0,#475569 50%,#64748B 100%) !important;box-shadow:0 0 0 3px rgba(100,116,139,.25) !important;}'
      + '.kt-ptt-o.em:not(.busy){background:radial-gradient(circle at 50% 50%,#991B1B 0,#991B1B 46%,#B91C1C 72%,#DC2626 100%) !important;box-shadow:0 8px 22px rgba(153,27,27,.4) !important;}'
      + '.kt-ptt-o.em.on{background:radial-gradient(circle at 50% 50%,#7F1D1D 0,#7F1D1D 50%,#991B1B 100%) !important;box-shadow:0 0 0 8px rgba(220,38,38,.28),0 0 26px 8px rgba(220,38,38,.4) !important;}'
      + '.kt-ptt-o[disabled]{opacity:.45;}.kt-ptt-o .wt-mic{display:block;margin:auto;filter:drop-shadow(0 1px 1px rgba(0,0,0,.25));}'
      + '@media (prefers-reduced-motion:reduce){.kt-ptt-o.on{animation:none;}}'
      + '.wt-wrap{display:grid;grid-template-columns:minmax(250px,320px) 1fr;gap:12px;height:560px;min-height:0;}'
      + '.wt-list{display:flex;flex-direction:column;min-height:0;background:#fff;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;}'
      + '.wt-lhead{padding:8px;border-bottom:1px solid #E2E8F0;background:#F8FAFC;flex:0 0 auto;}.wt-q{display:block;width:100%;height:30px;border:1px solid #CBD5E1;border-radius:8px;padding:0 9px;font-size:13px;box-sizing:border-box;}'
      + '.wt-scroll{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#CBD5E1 transparent;}.wt-scroll::-webkit-scrollbar{width:6px;}.wt-scroll::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:6px;}'
      + '.wt-gh[data-gtog]{cursor:pointer;user-select:none;}.wt-gh[data-gtog]:hover{filter:brightness(.97);}.wt-gh[data-gtog]:focus-visible{outline:2px solid #0E7490;outline-offset:-2px;}'
      + '.wt-gchev{display:inline-block;margin-right:5px;transition:transform .15s;}.wt-gcol .wt-gchev{transform:rotate(-90deg);}'
      + '.wt-list:not(.wt-searching) .wt-grp.wt-gcol > .wt-row{display:none !important;}'
      + '.wt-gh{position:sticky;top:0;z-index:1;display:flex;justify-content:space-between;gap:6px;padding:6px 12px;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;background:#F1F7F9;border-bottom:1px solid #E2E8F0;}.wt-gn{font-weight:700;color:#64748B;text-transform:none;letter-spacing:0;white-space:nowrap;}'
      + '.wt-row{display:flex;align-items:center;gap:9px;width:100%;padding:5px 12px;border:0;border-bottom:1px solid #F1F5F9;background:#fff;text-align:left;font-size:13.5px;cursor:pointer;min-height:38px;}.wt-row:hover{background:#F0FAFC;}.wt-row.on{background:#E0F4F7;box-shadow:inset 3px 0 0 #159FB4;}'
      + '.wt-rt{display:flex;flex-direction:column;min-width:0;flex:1;}.wt-nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.wt-sub{font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.wt-row .wt-live{margin-left:auto;font-size:11.5px;font-weight:700;color:#B45309;white-space:nowrap;}.wt-ico{width:26px;text-align:center;font-size:17px;flex:0 0 26px;}'
      + '.wt-av{position:relative;display:inline-flex;flex:0 0 auto;}.wt-dot{position:absolute;right:-1px;bottom:-1px;width:9px;height:9px;border-radius:50%;background:#16A34A;border:2px solid #fff;}.wt-dot.ph{background:#159FB4;}'
      + '.wt-empty{padding:12px;font-size:13px;color:#64748B;}'
      + '.wt-right{display:flex;flex-direction:column;gap:12px;min-height:0;min-width:0;}'
      + '.wt-talk{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:10px 14px;flex:0 0 auto;}'
      + '.wt-tto{font-size:13px;font-weight:700;color:#475569;}.wt-chg{border:0;background:none;padding:0;min-height:0;color:#0E7490;font-size:13px;font-weight:700;cursor:pointer;text-decoration:underline;text-underline-offset:2px;}'
      + '.wt-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;}.wt-head h2{margin:0;font-size:17px;}.wt-state{font-size:13px;font-weight:700;color:#B45309;}'
      + '.wt-ptt{display:flex;flex-direction:column;align-items:center;gap:6px;margin:8px 0 4px;}'
      + '.wt-btn{width:136px;height:136px;border-radius:50%;border:0;background:radial-gradient(circle at 35% 30%,#2DB3C7,#0E5A75);color:#fff;font-size:58px;line-height:1;box-shadow:0 10px 26px rgba(14,90,117,.35);cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;transition:transform .08s;}'
      + '.wt-btn:active,.wt-btn.on{transform:scale(.96);background:radial-gradient(circle at 35% 30%,#F87171,#B91C1C);box-shadow:0 0 0 10px rgba(220,38,38,.15),0 10px 26px rgba(185,28,28,.35);}.wt-btn[disabled]{opacity:.45;cursor:not-allowed;}'
      + '.wt-hint{font-size:12px;color:#64748B;text-align:center;}.wt-tip{cursor:help;color:#64748B;font-size:13px;}'
      + '.wt-recent{display:flex;flex-direction:column;flex:1;min-height:0;background:#fff;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;}'
      + 'button.wt-rtog{display:inline-flex;align-items:center;gap:6px;border:0;background:none;padding:2px 4px;min-height:0;margin:0;color:#0F172A;font:inherit;cursor:pointer;border-radius:6px;}button.wt-rtog:hover{background:#EEF2F6;}'
      + '.wt-rchev{display:inline-block;font-size:12px;color:#475569;transition:transform .15s;}'
      + '@media (min-width:861px){.wt-recent.wt-rcol{flex:0 0 auto;}.wt-recent.wt-rcol .wt-rchev{transform:rotate(-90deg);}.wt-recent.wt-rcol .wt-chips,.wt-recent.wt-rcol .wt-auto,.wt-recent.wt-rcol [data-wt-hist]{display:none;}.wt-recent.wt-rcol .wt-rhead{border-bottom:0;}}'
      + '@media (max-width:860px){button.wt-rtog{display:none;}}'
      + '.wt-rhead{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 10px;border-bottom:1px solid #E2E8F0;background:#F8FAFC;flex:0 0 auto;}.wt-rt-title{font-size:13px;}'
      + '.wt-chips{display:flex;gap:4px;flex-wrap:wrap;}.wt-wrap .wt-chips button.wt-chip{height:26px;min-height:0;padding:0 10px;border-radius:13px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;line-height:24px;font-weight:600;color:#334155;cursor:pointer;box-shadow:none;}.wt-wrap .wt-chips button.wt-chip.on{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + '.wt-auto{margin-left:auto;font-size:12.5px;color:#334155;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;cursor:pointer;}'
      + '.wt-day{position:sticky;top:0;z-index:1;padding:5px 12px;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#475569;background:#F8FAFC;border-bottom:1px solid #EEF2F6;}'
      + '.wt-msg{display:flex;align-items:center;gap:10px;padding:7px 8px 7px 12px;border-bottom:1px solid #F1F5F9;}.wt-msg:hover{background:#F0FAFC;}.wt-msg.new{box-shadow:inset 3px 0 0 #159FB4;background:#F5FBFC;}.wt-msg.new .wt-m1 b{font-weight:800;}.wt-msg.em{box-shadow:inset 3px 0 0 #DC2626;}'
      + '.wt-mb{flex:1;min-width:0;}.wt-m1{font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.wt-m1 b{font-weight:600;}.wt-to{color:#94A3B8;}.wt-m2{font-size:12px;color:#64748B;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.wt-new{display:inline-block;margin-left:6px;padding:0 6px;border-radius:8px;background:#159FB4;color:#fff;font-size:10.5px;font-weight:800;vertical-align:1px;}'
      + '.wt-prog{height:3px;border-radius:2px;margin-top:4px;overflow:hidden;}.wt-msg.playing .wt-prog{background:#E2E8F0;}.wt-prog i{display:block;height:100%;width:0;background:#159FB4;}'
      + '.wt-play{flex:0 0 36px;width:36px;height:36px;min-height:0;padding:0;border-radius:50%;border:0;background:#0E7490;color:#fff;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}.wt-play:hover{background:#0B5F75;}.wt-msg.playing .wt-play{background:#B91C1C;}'
      + '.wt-back{flex:0 0 30px;width:30px;height:30px;min-height:0;padding:0;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#0E7490;font-size:15px;cursor:pointer;}.wt-back:hover{background:#F0FAFC;}.wt-back-sp{flex:0 0 30px;}'
      + '.wt-more{flex:0 0 28px;width:28px;height:30px;min-height:0;padding:0;border:0;background:none;font-size:18px;color:#475569;cursor:pointer;border-radius:8px;}.wt-more:hover{background:#F1F5F9;}'
      + '.wt-badge{display:inline-block;min-width:16px;padding:0 5px;border-radius:8px;background:#DC2626;color:#fff;font-size:11px;line-height:16px;}.wt-badge[hidden]{display:none;}'
      + '.wt-seg{display:none;}'
      + '@media (max-width:860px){.wt-wrap{display:flex;flex-direction:column;gap:8px;}.wt-right{display:contents;}.wt-talk{order:1;padding:8px 12px;}'
      + '.wt-seg{order:2;display:flex;gap:4px;background:#EEF2F6;border-radius:10px;padding:3px;flex:0 0 auto;}.wt-seg button{flex:1;height:32px;min-height:0;border:0;border-radius:8px;background:none;font-size:13.5px;font-weight:700;color:#475569;}.wt-seg button.on{background:#fff;color:#0E7490;box-shadow:0 1px 3px rgba(15,23,42,.12);}'
      + '.wt-list,.wt-recent{order:3;flex:1;min-height:0;}.wt-wrap:not(.show-recent) .wt-recent{display:none;}.wt-wrap.show-recent .wt-list{display:none;}'
      + '.wt-btn{width:clamp(150px,46vw,180px);height:clamp(150px,46vw,180px);font-size:clamp(64px,19vw,78px);}.wt-ptt{margin:4px 0 2px;}.wt-hint{display:none;}.wt-head h2{font-size:15px;}.wt-talk .wt-links{margin:2px 0 0;font-size:12.5px;gap:10px;}.wt-rhead .wt-rt-title{display:none;}}'
      + '.wt-sec{font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;margin:16px 0 8px;}'
      + '.wt-set{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(230px,100%),1fr));gap:10px;}.wt-set label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px;}.wt-set select,.wt-set input[type=text],.wt-set input[type=number]{width:100%;height:30px;border:1px solid #CBD5E1;border-radius:8px;padding:0 8px;font-size:13px;}'
      + '.wt-note{font-size:12.5px;color:#475569;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;margin-top:10px;}'
      + '.wt-links{display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:center;margin:4px 0 8px;font-size:13px;}.wt-links a{color:#0E7490;font-weight:700;text-decoration:none;}.wt-links a:hover{text-decoration:underline;}'
      + '.wt-dndon{font-size:12.5px;font-weight:700;color:#B45309;}.wt-card .wt-links{justify-content:flex-start;margin-top:10px;}';
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

  /* THE SCREEN (redesigned 2026-11-27, Anthony: "the list of all users gets too long can you make this scrollable
     within the view and the table with the recorded walkie talkie needs to be redesigned to look better (things need
     to be quick and easy to use here)").
       - The screen fits the window (fit()): nothing below the talk button pushes it away. Each list scrolls inside
         its own panel (one scroll region each, slim bar); the page itself does not scroll.
       - LEFT "Talk to": one search box for channels and people, then Channels, then People grouped by centre under
         sticky headers, people on shift or with the phone listening first (green dot / 📱). Tap = select.
       - RIGHT: the talk panel (who is selected, the 🎙 button), then "Recent talks": a message-style list grouped by
         day, newest first; each talk = who → to whom, "2 min ago", length, a big ▶ with its own progress bar,
         ↩ Talk back, and ⋮ for the rest (KT.openActionMenu). Chips: All · To me · Mine · This channel; "Play next".
         New (not yet heard) talks are marked.
       - PHONE (≤860px): the talk panel stays on top and small; under it a two-way switch, Talk to | Recent talks,
         shows one list at a time in the space left. */
  var HSCOPE = 'all';
  function ago(s) {
    s = +s || 0;
    if (s < 45) return T('just now');
    if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' ' + T('min ago');
    if (s < 86400) return Math.round(s / 3600) + ' ' + T('h ago');
    return '';
  }
  function hm(s) { var m = /[ T](\d{2}):(\d{2})/.exec(s || ''); if (!m) return ''; var h = +m[1]; return (h % 12 || 12) + ':' + m[2] + ' ' + (h >= 12 ? 'PM' : 'AM'); }
  function dayLabel(date, today) {
    if (date === today) return T('Today');
    var t = new Date(today + 'T12:00:00Z'); t.setUTCDate(t.getUTCDate() - 1);
    if (date === t.toISOString().slice(0, 10)) return T('Yesterday');
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date); if (!m) return date;
    var dd = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12));
    return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][dd.getUTCDay()] + ', ' + ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]);
  }
  /* The fixed bars along the bottom of the window (the phone bar, which educators also get on a computer). */
  function bottomInset() {
    var h = 0, H = w.innerHeight, W = w.innerWidth;
    [].slice.call(d.body.children).forEach(function (el) {
      [el].concat([].slice.call(el.children || [])).forEach(function (x) {
        if (!x.getBoundingClientRect || x.id === 'kt-wt-card') return;
        var cs = w.getComputedStyle(x); if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') return;
        var r = x.getBoundingClientRect();
        if (r.height > 0 && r.height < 160 && r.width > W * 0.6 && r.bottom >= H - 2) h = Math.max(h, H - r.top);
      });
    });
    return h;
  }
  function fitWrap(wrap) {
    if (!wrap || !d.body.contains(wrap)) return;
    var top = wrap.getBoundingClientRect().top + (w.scrollY || 0) - (w.scrollY || 0);
    var phone = w.innerWidth <= 860;
    var min = phone ? 430 : 470, hgt = Math.max(min, Math.floor(w.innerHeight - Math.max(0, top) - bottomInset() - 10));
    wrap.style.height = hgt + 'px';
    var se = d.scrollingElement || d.documentElement, over = se.scrollHeight - w.innerHeight;
    if (over > 0 && hgt > min) wrap.style.height = Math.max(min, hgt - over) + 'px';   // main's own bottom padding (room for the phone bar)
  }

  function draw(main, body, r) {
    if (!r.enabled) {
      body.innerHTML = '<div class="wt-note">' + esc(T('The walkie-talkie is switched off for your agency.')) + (r.can_admin ? '' : ' ' + esc(T('Ask an agency admin to turn it on.'))) + '</div>'
        + (r.can_admin ? '<div class="wt-links"><a href="' + AGENCY_LINK + '">⚙️ ' + esc(T('Agency walkie settings')) + '</a></div>' : '');
      return;
    }
    var q = (location.hash.split('?')[1] || '').split('&').reduce(function (a, kv) { var p = kv.split('='); if (p[0]) a[p[0]] = decodeURIComponent(p[1] || ''); return a; }, {});
    var all = {}; r.channels.forEach(function (c) { all[c.key] = { key: c.key, label: c.label, icon: c.icon, can_talk: c.can_talk, emergency: c.emergency, talking: c.talking }; });
    r.people.forEach(function (p) { all[p.key] = { key: p.key, label: p.name, person: p, can_talk: true, talking: p.talking }; });
    var first = r.channels.filter(function (c) { return c.can_talk && !c.emergency; })[0] || r.channels[0] || null;
    /* THE LAST CHAT (2026-11-28, Anthony: "keep that as the last chat to make it easier to continue talking"): the person
       or channel last talked to or picked, kept per person and agency on the server (/walkie/me "last") - so a refresh,
       another device and the top-bar 📻 all open on it. Gone out of reach: the default channel. */
    S.sel = all[q.ch] ? q.ch : (S.sel && all[S.sel] ? S.sel : (r.last && all[r.last] ? r.last : (first ? first.key : null)));

    // People by centre: the agency-wide people (admins, no centre) first, then each centre; on shift / listening first.
    var groups = {}, order = [], LEAD = T('Admins and leaders');
    r.people.forEach(function (p) { var g = (p.centres && p.centres[0]) || LEAD; if (!groups[g]) { groups[g] = []; order.push(g); } groups[g].push(p); });
    order.sort(function (a, b) { return a === LEAD ? -1 : b === LEAD ? 1 : a.localeCompare(b); });
    function up(p) { return p.on_shift || p.listening ? 0 : 1; }
    var chRows = r.channels.map(function (c) {
      return '<button type="button" class="wt-row" data-ch="' + esc(c.key) + '" data-name="' + esc(String(c.label).toLowerCase()) + '"><span class="wt-ico">' + c.icon + '</span><span class="wt-rt"><span class="wt-nm" data-no-i18n>' + esc(c.label) + '</span>'
        + (c.can_talk ? '' : '<span class="wt-sub">' + esc(T('Listen only')) + '</span>') + '</span>' + (c.talking ? '<span class="wt-live">' + esc(c.talking) + ' ' + esc(T('is talking')) + '</span>' : '') + '</button>';
    }).join('');
    var pRows = order.map(function (g) {
      var list = groups[g].slice().sort(function (a, b) { return up(a) - up(b) || String(a.name).localeCompare(String(b.name)); });
      var on = list.filter(function (p) { return up(p) === 0; }).length;
      return '<div class="wt-grp" data-grp data-gk="' + esc(g) + '"><div class="wt-gh" data-gtog role="button" tabindex="0" aria-expanded="true"><span data-no-i18n><span class="wt-gchev" aria-hidden="true">▾</span>' + esc(g) + '</span><span class="wt-gn">' + (on ? on + ' ' + esc(T('on now')) + ' · ' : '') + list.length + '</span></div>'
        + list.map(function (p) {
          var st = p.on_shift ? T('On shift') : (p.listening ? T('Phone listening') : '');
          return '<button type="button" class="wt-row" data-ch="' + esc(p.key) + '" data-name="' + esc(String(p.name).toLowerCase()) + '"><span class="wt-av">' + avatar(p) + (up(p) === 0 ? '<i class="wt-dot' + (p.on_shift ? '' : ' ph') + '"></i>' : '') + '</span>'
            + '<span class="wt-rt"><span class="wt-nm" data-no-i18n>' + esc(p.name) + '</span><span class="wt-sub">' + esc(T(p.role || '')) + (st ? ' · ' + esc(st) : '') + '</span></span>'
            + (p.talking ? '<span class="wt-live">' + esc(T('talking')) + '</span>' : '') + '</button>';
        }).join('') + '</div>';
    }).join('');
    var tipNote = native() ? T('This phone listens all the time, even with the screen off, unless you turn on Do not disturb.')
      : (/iPhone|iPad/.test(navigator.userAgent) ? T('On iPhone, talks arrive as a notification: tap it to listen.') : T('In a browser you hear talks only while a KiddieTrac tab is open. The Android app listens all the time.'));
    var chip = function (k, l) { return '<button type="button" class="wt-chip" data-scope="' + k + '">' + esc(T(l)) + '</button>'; };
    body.innerHTML = '<div class="wt-wrap" data-i18n-ctx="walkie" data-kt-nofill>'
      + '<div class="wt-list"><div class="wt-lhead"><input class="wt-q" type="search" data-wtq placeholder="' + esc(T('Search channels and people')) + '"></div>'
      + '<div class="wt-scroll" data-wt-lscroll><div class="wt-grp" data-grp data-gk="__channels"><div class="wt-gh wt-ghc" data-gtog role="button" tabindex="0" aria-expanded="true"><span><span class="wt-gchev" aria-hidden="true">▾</span>' + esc(T('Channels')) + '</span><span class="wt-gn">' + r.channels.length + '</span></div>' + chRows + '</div>'
      + (pRows || '<div class="wt-empty">' + esc(T('Nobody to call yet.')) + '</div>') + '<div class="wt-empty" data-wt-none hidden>' + esc(T('No match.')) + '</div></div></div>'
      + '<div class="wt-right"><div class="wt-talk"><div class="wt-head"><span class="wt-tto">' + esc(T('Talking to:')) + '</span><h2 data-wt-title data-no-i18n></h2><button type="button" class="wt-chg" data-wt-change data-kt-iconized="1">' + esc(T('change')) + '</button><span class="wt-state" data-wt-state></span></div>'
      + '<div class="wt-ptt"><button type="button" class="wt-btn kt-ptt-o" data-wt-ptt data-kt-iconized="1" aria-label="' + esc(T('Hold to talk')) + '">' + MIC + '</button>'
      + '<div class="wt-hint">' + esc(T('Hold to talk, let go to finish.')) + (matchMedia('(pointer:fine)').matches ? ' ' + esc(T('On a computer you can hold the spacebar.')) : '') + '</div></div>'
      + '<div class="wt-links">' + (r.prefs && r.prefs.dnd ? '<span class="wt-dndon">🔕 ' + esc(T('Do not disturb is on')) + '</span>' : '')
      + '<a href="' + MY_LINK + '">⚙️ ' + esc(T('My walkie settings')) + '</a>' + (r.can_admin ? '<a href="' + AGENCY_LINK + '">⚙️ ' + esc(T('Agency walkie settings')) + '</a>' : '')
      + '<span class="wt-tip" data-kttip="' + esc(tipNote + ' ' + T('The emergency channel always plays.') + ' ' + T('Talks are kept for') + ' ' + (r.settings.retention_days || 30) + ' ' + T('days, encrypted.')) + '">ⓘ</span></div></div>'
      + '<div class="wt-seg" role="tablist"><button type="button" role="tab" data-seg="list">' + esc(T('Talk to')) + '</button><button type="button" role="tab" data-seg="recent">' + esc(T('Recent talks')) + ' <span class="wt-badge" data-wt-newn hidden></span></button></div>'
      + '<div class="wt-recent"><div class="wt-rhead"><button type="button" class="wt-rtog" data-wt-rtog aria-expanded="true" data-kt-iconized="1"><span class="wt-rchev" aria-hidden="true">▾</span><b class="wt-rt-title">' + esc(T('Recent talks')) + '</b><span class="wt-badge" data-wt-newn2 hidden></span></button><div class="wt-chips" data-kt-noband>' + chip('all', 'All') + chip('to_me', 'To me') + chip('mine', 'Mine') + chip('channel', 'This channel') + '</div>'
      + '<label class="wt-auto"><input type="checkbox" data-wt-auto> ' + esc(T('Play next')) + '</label></div>'
      + '<div class="wt-scroll" data-wt-hist></div></div></div></div>';

    var wrap = body.querySelector('.wt-wrap');
    var title = body.querySelector('[data-wt-title]'), btn = body.querySelector('[data-wt-ptt]'), state = body.querySelector('[data-wt-state]');
    var segView = 'list';
    function seg(v) {
      segView = v; wrap.classList.toggle('show-recent', v === 'recent');
      body.querySelectorAll('[data-seg]').forEach(function (b) { var on = b.getAttribute('data-seg') === v; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    }
    body.querySelectorAll('[data-seg]').forEach(function (b) { b.addEventListener('click', function () { seg(b.getAttribute('data-seg')); }); });
    seg(q.play ? 'recent' : 'list');
    function select(k, keep) {
      S.sel = k; var c = all[k];
      if (keep && c && c.can_talk && !c.emergency) remember(k);
      body.querySelectorAll('.wt-row').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-ch') === k); });
      title.textContent = c ? ((c.icon ? c.icon + ' ' : '📻 ') + c.label) : T('Pick a channel or person');
      btn.disabled = !c || !c.can_talk;
      btn.classList.toggle('busy', !!(c && c.talking)); btn.classList.toggle('em', !!(c && c.emergency));
      state.textContent = c && !c.can_talk ? T('Listen only') : (c && c.talking ? c.talking + ' ' + T('is talking') : '');
      var chc = body.querySelector('[data-scope="channel"]'); if (chc) chc.title = c ? c.label : '';
      if (HSCOPE === 'channel') history();
    }
    var ui = { state: function (s, x) {
      clearInterval(ui._iv);
      btn.classList.toggle('on', s === 'live' || s === 'opening');
      if (s === 'opening') state.textContent = T('Starting…');
      else if (s === 'live') { var t0 = Date.now(); state.textContent = T('Talking…') + (x && x.recipients !== undefined ? ' · ' + x.recipients + ' ' + T('hearing') : ''); ui._iv = setInterval(function () { if (!btn.classList.contains('on')) return clearInterval(ui._iv); state.textContent = T('Talking…') + ' ' + Math.round((Date.now() - t0) / 1000) + 's' + (x && x.recipients !== undefined ? ' · ' + x.recipients + ' ' + T('hearing') : ''); }, 500); }
      else { clearInterval(ui._iv); state.textContent = x && x.duration_ms ? T('Sent') + ' · ' + (Math.round(x.duration_ms / 100) / 10) + 's' : ''; }
    } };
    var ptt = bindPtt(btn, function () { return S.sel; }, ui);
    function down(e) { if (btn.disabled || !S.sel || S.talk) return; if (e) e.preventDefault(); talkStart(S.sel, ui); }
    function upEv(e) { if (e) e.preventDefault(); if (ptt && ptt.held()) ptt.release(); else talkEnd(); }
    function kd(e) { if (e.code !== 'Space' || e.repeat || !d.body.contains(btn) || /INPUT|TEXTAREA|SELECT|BUTTON/.test((d.activeElement || {}).tagName || '') || (d.activeElement && d.activeElement.isContentEditable)) return; down(e); }
    function ku(e) { if (e.code !== 'Space' || !d.body.contains(btn)) return; if (S.talk) upEv(e); }
    if (S.keys) { d.removeEventListener('keydown', S.keys[0]); d.removeEventListener('keyup', S.keys[1]); }
    S.keys = [kd, ku]; d.addEventListener('keydown', kd); d.addEventListener('keyup', ku);
    body.querySelectorAll('.wt-row').forEach(function (b) { b.addEventListener('click', function () { select(b.getAttribute('data-ch'), true); b.blur(); }); });
    var chg = body.querySelector('[data-wt-change]');
    if (chg) chg.addEventListener('click', function () { seg('list'); var q2 = body.querySelector('[data-wtq]'); if (q2) { try { q2.focus({ preventScroll: true }); } catch (e) {} } });
    var qi = body.querySelector('[data-wtq]');
    qi.addEventListener('input', function () {
      var s = qi.value.trim().toLowerCase(), any = false;
      body.querySelectorAll('[data-wt-lscroll] .wt-row').forEach(function (b) { var hit = !s || b.getAttribute('data-name').indexOf(s) >= 0; b.style.display = hit ? '' : 'none'; if (hit) any = true; });
      body.querySelectorAll('[data-grp]').forEach(function (g) { g.style.display = [].some.call(g.querySelectorAll('.wt-row'), function (b) { return b.style.display !== 'none'; }) ? '' : 'none'; });
      body.querySelector('.wt-list').classList.toggle('wt-searching', !!s);   // a search looks inside folded groups too
      body.querySelector('[data-wt-none]').hidden = any;
    });

    /* Groups fold (2026-11-28, Anthony: "make CHANNELS collapsible too"): Channels, Admins and leaders and each centre;
       tap the header (▾), kept per person. */
    var gkey = 'kt_wt_gcol_' + ((r.me && r.me.id) || 0), gcol = {};
    try { gcol = JSON.parse(ls(gkey) || '{}') || {}; } catch (e) { gcol = {}; }
    function gfold(g, col) { g.classList.toggle('wt-gcol', col); var h = g.querySelector('[data-gtog]'); if (h) h.setAttribute('aria-expanded', col ? 'false' : 'true'); }
    body.querySelectorAll('[data-grp]').forEach(function (g) {
      gfold(g, !!gcol[g.getAttribute('data-gk')]);
      var h = g.querySelector('[data-gtog]'); if (!h) return;
      function tog(e) { if (e) e.preventDefault(); var k = g.getAttribute('data-gk'), col = !g.classList.contains('wt-gcol'); gfold(g, col); if (col) gcol[k] = 1; else delete gcol[k]; ls(gkey, Object.keys(gcol).length ? JSON.stringify(gcol) : null); }
      h.addEventListener('click', tog);
      h.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') tog(e); });
    });

    /* ---- Recent talks ---- */
    var hist = body.querySelector('[data-wt-hist]'), auto = body.querySelector('[data-wt-auto]');
    /* Recent talks folds (2026-11-28, Anthony: "make the recent list collapsible"): ▾ + the unplayed count, kept per person. */
    var rtog = body.querySelector('[data-wt-rtog]'), rpane = body.querySelector('.wt-recent'), rkey = 'kt_wt_rcol_' + ((r.me && r.me.id) || 0);
    function rfold(col) { rpane.classList.toggle('wt-rcol', col); rtog.setAttribute('aria-expanded', col ? 'false' : 'true'); }
    rfold(ls(rkey) === '1');
    rtog.addEventListener('click', function () { var col = !rpane.classList.contains('wt-rcol'); rfold(col); ls(rkey, col ? '1' : null); });
    try { auto.checked = ls('kt_walkie_autonext') === '1'; } catch (e) {}
    auto.addEventListener('change', function () { ls('kt_walkie_autonext', auto.checked ? '1' : null); });
    function chips() { body.querySelectorAll('[data-scope]').forEach(function (b) { b.classList.toggle("on", b.getAttribute("data-scope") === HSCOPE); }); /* class only: #appMain button[aria-pressed] is restyled by kt-consistency-polish */ }
    body.querySelectorAll('[data-scope]').forEach(function (b) { b.addEventListener('click', function () { HSCOPE = b.getAttribute('data-scope'); chips(); history(); }); });
    chips();
    var items = [];
    function history() {
      var params = { scope: HSCOPE === 'channel' ? 'all' : HSCOPE };
      if (HSCOPE === 'channel') { if (!S.sel) { hist.innerHTML = ''; return; } params.channel = S.sel; }
      if (!hist.children.length) hist.innerHTML = '<div class="wt-empty">' + esc(T('Loading…')) + '</div>';
      api().get('/walkie/history', params).then(function (h) {
        items = h.items || [];
        var fresh = items.filter(function (it) { return !it.mine && !it.played; }).length, badge = body.querySelector('[data-wt-newn]');
        if (badge) { badge.hidden = !fresh; badge.textContent = fresh; }
        var badge2 = body.querySelector('[data-wt-newn2]'); if (badge2) { badge2.hidden = !fresh; badge2.textContent = fresh; }
        if (!items.length) { hist.innerHTML = '<div class="wt-empty">' + esc(T(HSCOPE === 'all' ? 'No talks yet. Hold the button to say something.' : 'Nothing here yet.')) + '</div>'; return; }
        var day = null, out = '';
        items.forEach(function (it) {
          var dd = String(it.at || '').slice(0, 10);
          if (dd !== day) { day = dd; out += '<div class="wt-day">' + esc(dayLabel(dd, h.today)) + '</div>'; }
          var fresh1 = !it.mine && !it.played, a = ago(it.ago_s);
          var meta = [a, hm(it.at), it.seconds + 's'];
          if (it.mine) meta.push(T('Heard') + ' ' + it.heard + '/' + it.sent + (it.busy ? ' · ' + it.busy + ' ' + T('busy') : ''));
          else if (it.my_status && it.my_status !== 'heard' && it.my_status !== 'sent') meta.push({ busy: T('Busy'), dnd: T('Do not disturb'), off_shift: T('Not on shift') }[it.my_status] || '');
          out += '<div class="wt-msg' + (fresh1 ? ' new' : '') + (it.emergency ? ' em' : '') + '" data-id="' + it.id + '">'
            + '<span class="wt-av">' + avatar({ name: it.from_name, photo_url: it.from_photo, sex: it.from_sex, id: it.from_id }) + '</span>'
            + '<div class="wt-mb"><div class="wt-m1"><b data-no-i18n>' + (it.emergency ? '🚨 ' : '') + esc(it.mine ? T('You') : it.from_name) + '</b> <span class="wt-to">→</span> <span data-no-i18n>' + esc(it.to_label) + '</span>' + (fresh1 ? '<span class="wt-new">' + esc(T('New')) + '</span>' : '') + '</div>'
            + '<div class="wt-m2">' + meta.filter(Boolean).map(esc).join(' · ') + '</div><div class="wt-prog"><i></i></div></div>'
            + '<button type="button" class="wt-play" data-play aria-label="' + esc(T('Play', 'walkie')) + '" data-kttip="' + esc(T('Play', 'walkie')) + '">▶</button>'
            + (it.back ? '<button type="button" class="wt-back" data-back data-kttip="' + esc(T('Talk back')) + '" aria-label="' + esc(T('Talk back')) + '">↩</button>' : '<span class="wt-back-sp"></span>')
            + '<button type="button" class="kt-ka-kebab wt-more" data-more aria-label="' + esc(T('More')) + '">⋮</button></div>';
        });
        hist.innerHTML = out;
      }, function (e) { hist.innerHTML = '<div class="wt-note">' + esc(T(errMsg(e))) + '</div>'; });
    }
    hist.addEventListener('click', function (e) {
      var row = e.target.closest('.wt-msg'); if (!row) return;
      var it = items.filter(function (x) { return String(x.id) === row.getAttribute('data-id'); })[0]; if (!it) return;
      if (e.target.closest('[data-play]')) return playRow(row, it);
      if (e.target.closest('[data-back]')) return talkBack(it);
      if (e.target.closest('[data-more]')) {
        var mk = function (l, fn) { var b = d.createElement('button'); b.type = 'button'; b.textContent = l; b.addEventListener('click', fn); return b; };
        var acts = [mk('▶ ' + T('Play', 'walkie'), function () { playRow(row, it); })];
        if (it.back) acts.push(mk('↩ ' + T('Talk back'), function () { talkBack(it); }));
        if (all[it.channel] && it.channel !== S.sel) acts.push(mk('📻 ' + T('Open this channel'), function () { select(it.channel, true); seg('list'); }));
        if (!it.mine && !it.played) acts.push(mk('✓ ' + T('Mark as heard'), function () {
          api().post('/walkie/tx/' + it.id + '/heard', { busy: false }, { quiet: true }).then(function () { it.played = true; row.classList.remove('new'); var n = row.querySelector('.wt-new'); if (n) n.remove(); });
        }));
        if (it.mine) acts.push(mk('👂 ' + T('Heard by') + ' ' + it.heard + ' / ' + it.sent + (it.busy ? ' · ' + it.busy + ' ' + T('busy') : ''), function () {}));
        if (KT.openActionMenu) KT.openActionMenu(e.target.closest('[data-more]'), acts);
      }
    });
    function talkBack(it) {
      if (!all[it.back]) { toast('📻', 'You cannot call them from here', '', '#B45309'); return; }
      select(it.back, true); seg('list');
      var row = body.querySelector('.wt-row[data-ch="' + it.back.replace(/"/g, '') + '"]'); if (row && row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
      toast('📻', 'Hold the button to talk back', '', '#0E7490');
    }
    function playRow(row, it) {
      if (S.clip && S.clip.row === row) { stopClip(); return; }
      stopClip();
      var c = ctx(); if (!c) return;
      var bt = row.querySelector('[data-play]'), bar = row.querySelector('.wt-prog i');
      var me = S.clip = { row: row, srcs: [], raf: 0 };
      row.classList.add('playing'); bt.textContent = '■';
      api().get('/walkie/tx/' + it.id + '/audio').then(function (a) {
        if (S.clip !== me) return;
        var u = unb64(a.data || ''); if (!u.length) { stopClip(); return; }
        var t0 = sound(c, 'rx', c.currentTime + 0.05) + 0.04;
        var buf = c.createBuffer(1, u.length, RATE), ch = buf.getChannelData(0);
        for (var i = 0; i < u.length; i++) ch[i] = DEC[u[i]];
        var src = c.createBufferSource(); src.buffer = buf; src.connect(c.destination); src.start(t0); me.srcs.push(src);
        var t1 = t0 + buf.duration; sound(c, 'rxEnd', t1 + 0.03);
        if (!it.mine && !it.played) { it.played = true; row.classList.remove('new'); var n = row.querySelector('.wt-new'); if (n) n.remove(); }
        (function tick() {
          if (S.clip !== me) return;
          var p = Math.max(0, Math.min(1, (c.currentTime - t0) / (t1 - t0)));
          bar.style.width = (p * 100) + '%';
          if (c.currentTime < t1 + 0.1) { me.raf = requestAnimationFrame(tick); return; }
          stopClip();
          if (auto.checked) { var nx = row.nextElementSibling; while (nx && !nx.classList.contains('wt-msg')) nx = nx.nextElementSibling; if (nx) nx.querySelector('[data-play]').click(); }
        })();
      }, function (e) { stopClip(); toast('⚠️', 'Could not play it', errMsg(e), '#DC2626'); });
    }
    function stopClip() {
      var m = S.clip; if (!m) return; S.clip = null;
      cancelAnimationFrame(m.raf); m.srcs.forEach(function (s) { try { s.stop(); } catch (e) {} });
      m.row.classList.remove('playing'); var b = m.row.querySelector('[data-play]'); if (b) b.textContent = '▶';
      var bar = m.row.querySelector('.wt-prog i'); if (bar) bar.style.width = '0';
    }
    S.view = { history: history };
    select(S.sel);
    history();
    if (q.play) setTimeout(function () { var row = hist.querySelector('.wt-msg[data-id="' + (+q.play) + '"]'); var it = items.filter(function (x) { return x.id === +q.play; })[0]; if (row && it) playRow(row, it); else playClip(+q.play); }, 1200);
    fitWrap(wrap); setTimeout(function () { fitWrap(wrap); }, 400);
    if (S.fitter) w.removeEventListener('resize', S.fitter);
    S.fitter = function () { fitWrap(wrap); }; w.addEventListener('resize', S.fitter);
  }

  function playClip(id) {
    var c = ctx(); if (!c) return Promise.resolve(0);
    return api().get('/walkie/tx/' + id + '/audio').then(function (a) {
      var u = unb64(a.data || ''); if (!u.length) return 0;
      var at = sound(c, 'rx', c.currentTime + 0.05) + 0.04; at = schedule(c, u, at); sound(c, 'rxEnd', at + 0.03);
      return Math.max(0, at - c.currentTime);   // seconds until it finishes
    }, function (e) { toast('⚠️', 'Could not play it', errMsg(e), '#DC2626'); return 0; });
  }

  function settingsHtml(r) {
    var s = r.settings || {};
    function opt(v, l, cur) { return '<option value="' + v + '"' + (String(cur) === String(v) ? ' selected' : '') + '>' + esc(T(l)) + '</option>'; }
    return '<div class="wt-set" data-wt-set>'
      + '<div><label>' + esc(T('Walkie-talkie')) + '</label><select data-k="enabled">' + opt('1', 'On', s.enabled ? '1' : '0') + opt('0', 'Off', s.enabled ? '1' : '0') + '</select></div>'
      + '<div><label>' + esc(T('Phones listen')) + '</label><select data-k="listen">' + opt('always', 'All the time (24/7)', s.listen) + opt('on_shift', 'Only while clocked in', s.listen) + '</select></div>'
      + '<div><label>' + esc(T('Keep recordings for (days)')) + '</label><input type="number" min="1" max="365" data-k="retention_days" value="' + esc(s.retention_days || 30) + '"></div>'
      + '<div><label>' + esc(T('Emergency channel')) + '</label><select data-k="emergency">' + opt('1', 'On (always plays)', s.emergency ? '1' : '0') + opt('0', 'Off', s.emergency ? '1' : '0') + '</select></div>'
      + '<div><label>' + esc(T('Emergency channel name')) + '</label><input type="text" maxlength="40" data-k="emergency_name" value="' + esc(s.emergency_name || 'Emergency') + '"></div>'
      + '<div><label>' + esc(T('Who can talk on it')) + '</label><select data-k="emergency_talkers">' + opt('leaders', 'Directors and admins', s.emergency_talkers) + opt('admins', 'Admins only', s.emergency_talkers) + '</select></div>'
      + '<div><label>' + esc(T('Educators can call')) + '</label><select data-k="educators_agency">' + opt('0', 'Staff at their own centres', s.educators_agency ? '1' : '0') + opt('1', 'Anyone on staff in the agency', s.educators_agency ? '1' : '0') + '</select></div>'
      + '</div><div class="wt-note">' + esc(T('Changes save as you make them and are recorded in the audit log.')) + '</div>';
  }
  function wireSettings(body, r, repaint) {
    var box = body.querySelector('[data-wt-set]'); if (!box) return;
    box.addEventListener('change', function (e) {
      var k = e.target.getAttribute('data-k'); if (!k) return;
      var v = e.target.value, o = {};
      o[k] = (k === 'enabled' || k === 'emergency' || k === 'educators_agency') ? v === '1' : (k === 'retention_days' ? +v : v);
      api().request('/walkie/settings', { method: 'PUT', body: o }).then(function () {
        toast('✅', 'Saved', '', '#16A34A');
        if (S.me && S.me.settings) S.me.settings[k] = o[k];
        if (repaint && (k === 'enabled' || k === 'emergency')) repaint();
      }, function (x) { toast('⚠️', 'Could not save', errMsg(x), '#DC2626'); });
    });
  }

  /* ---- THE WALKIE'S SETTINGS, DRAWN IN THE SETTINGS SCREENS (2026-11-27, Anthony: "move the settings from the walkie
     talkie to the settings section somewhere appropriate"). Your own: My settings › Notifications › 📻 Walkie-talkie
     (screen-settings.js asks KT.walkie.myCard). The agency's: Settings › Agency notifications › 📻 Walkie-talkie
     (screen-notification-settings.js asks KT.walkie.agencyPane; agency admins and super admins). ---- */
  var MY_LINK = '#settings?tab=notifications&sub=walkie', AGENCY_LINK = '#notification-settings?tab=walkie';
  function cardHead(title, sub) {
    return '<div style="font-weight:800;font-size:15px;color:#0F172A;">' + title + '</div>' + (sub ? '<div class="wt-sub" style="font-size:12.5px;color:#64748B;margin:2px 0 10px;">' + esc(T(sub)) + '</div>' : '');
  }
  function myCard() {
    css();
    var box = d.createElement('div'); box.className = 'kt-card wt-card'; box.setAttribute('data-wt-my', '1');
    box.innerHTML = cardHead('📻 ' + esc(T('Walkie-talkie')), 'Do not disturb and the radio sounds. Only for you.') + '<div class="wt-sub">' + esc(T('Loading…')) + '</div>';
    load().then(function (r) { paintMy(box, r); }, function (e) {
      box.innerHTML = cardHead('📻 ' + esc(T('Walkie-talkie'))) + '<div class="wt-note">' + esc(T(errMsg(e))) + '</div>';
    });
    return box;
  }
  function paintMy(box, r) {
    var p = r.prefs || {};
    function opt(v, l) { return '<option value="' + v + '">' + esc(T(l)) + '</option>'; }
    var nativeNote = native() ? T('This phone listens all the time, even with the screen off, unless you turn on Do not disturb.')
      : (/iPhone|iPad/.test(navigator.userAgent) ? T('On iPhone, talks arrive as a notification: tap it to listen.') : T('In a browser you hear talks only while a KiddieTrac tab is open. The Android app listens all the time.'));
    box.innerHTML = cardHead('📻 ' + esc(T('Walkie-talkie')), 'Do not disturb, the radio sounds and vibration. Only for you.')
      + '<div class="wt-set">'
      + '<div><label>' + esc(T('Do not disturb')) + '</label><select data-wt-dnd>' + opt('off', 'Off') + opt('60', 'For 1 hour') + opt('240', 'For 4 hours') + opt('0', 'Until I turn it off') + '</select>'
      + '<div class="wt-sub" data-wt-dndnote style="font-size:12px;color:#64748B;margin-top:3px;"></div></div>'
      + '<div><label>' + esc(T('Walkie sounds')) + '</label><div style="display:flex;gap:6px;"><select data-wt-snd style="flex:1;">' + opt('1', 'On (radio beeps)') + opt('0', 'Off') + '</select>'
      + '<button type="button" class="btn" data-wt-try style="height:30px;font-size:13px;white-space:nowrap;">▶ ' + esc(T('Try')) + '</button></div></div>'
      + '<div><label>' + esc(T('Walkie vibration')) + '</label><div style="display:flex;gap:6px;"><select data-wt-vib style="flex:1;">' + opt('1', 'On') + opt('0', 'Off') + '</select>'
      + (navigator.vibrate || native() ? '<button type="button" class="btn" data-wt-vtry style="height:30px;font-size:13px;white-space:nowrap;">📳 ' + esc(T('Try')) + '</button>' : '') + '</div>'
      + (navigator.vibrate || native() ? '' : '<div class="wt-sub" style="font-size:12px;color:#64748B;margin-top:3px;">' + esc(T('This device cannot vibrate from a web page (iPhone). The Android app and Android Chrome can.')) + '</div>') + '</div>'
      + '</div><div class="wt-note">' + esc(T('A beep when you press to talk, a roger beep when you let go, a chirp when someone starts talking to you and a soft squelch when they finish. The emergency channel always beeps and always plays.'))
      + ' ' + esc(nativeNote) + '</div>'
      + (r.enabled ? '<div class="wt-links"><a href="#walkie">📻 ' + esc(T('Open the Walkie-talkie')) + '</a></div>' : '<div class="wt-note">' + esc(T('The walkie-talkie is switched off for your agency.')) + '</div>');
    var dnd = box.querySelector('[data-wt-dnd]'), dndNote = box.querySelector('[data-wt-dndnote]'), snd = box.querySelector('[data-wt-snd]');
    function showDnd(x) {
      dnd.value = x.dnd ? (x.dnd_until ? 'x' : '0') : 'off';
      if (dnd.value === '' || (x.dnd && x.dnd_until)) { if (!dnd.querySelector('option[value="x"]')) { var o = d.createElement('option'); o.value = 'x'; o.textContent = T('On'); dnd.appendChild(o); } dnd.value = 'x'; }
      dndNote.textContent = x.dnd ? (x.dnd_until ? T('On until') + ' ' + fmtWhen(x.dnd_until) : T('On until you turn it off')) : '';
    }
    showDnd(p);
    snd.value = p.sounds === false ? '0' : '1';
    var vib = box.querySelector('[data-wt-vib]'); vib.value = p.vibrate === false ? '0' : '1';
    vib.addEventListener('change', function () { save({ vibrate: vib.value === '1' }); });
    var vtry = box.querySelector('[data-wt-vtry]');
    if (vtry) vtry.addEventListener('click', function () {
      if (vib.value !== '1') { toast('📴', 'Walkie vibration is off', '', '#475569'); return; }
      try { navigator.vibrate && navigator.vibrate([14, 400, 90, 70, 90, 400, 8, 50, 8]); } catch (e) {}
    });
    function save(body, after) {
      return api().request('/walkie/prefs', { method: 'PUT', body: body }).then(function (x) {
        if (S.me) S.me.prefs = Object.assign({}, S.me.prefs || {}, x);
        after && after(x); toast('✅', 'Saved', '', '#16A34A');
      }, function (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); });
    }
    dnd.addEventListener('change', function () { var v = dnd.value; if (v === 'x') return; save({ dnd: v !== 'off', minutes: v === 'off' ? 0 : +v }, showDnd); });
    snd.addEventListener('change', function () { save({ sounds: snd.value === '1' }); });
    box.querySelector('[data-wt-try]').addEventListener('click', function () {
      var c = ctx(); if (!c) return;
      if (snd.value !== '1') { toast('🔇', 'Walkie sounds are off', '', '#475569'); return; }
      var t = c.currentTime + 0.05;
      ['press', 'release', 'rx', 'rxEnd'].forEach(function (n) { t = sound(c, n, t) + 0.35; });
    });
  }
  /* SOUND CREDITS (CC BY needs them; CC0 credited too). Shown in My settings › About for everyone. */
  var CREDITS = [
    ['Function Beep.wav', 'JustinBW', 'https://freesound.org/s/70106/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', 'Walkie-talkie: press to talk'],
    ['Walkie Talkie - Roger Beep', 'bruce965', 'https://freesound.org/s/321906/', 'CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/', 'Walkie-talkie: let go'],
    ['Walkie Talkie Static Beep', 'BudgetPixel AI', 'https://budgetpixel.com/sfx/walkie-talkie-static-beep-2d99529f', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', 'Walkie-talkie: someone starts talking'],
    ['walkietalkie_eot.wav', 'IneQuation', 'https://freesound.org/s/27878/', 'CC BY 3.0', 'https://creativecommons.org/licenses/by/3.0/', 'Walkie-talkie: they finished']
  ];
  function creditsCard() {
    var box = d.createElement('div'); box.className = 'kt-card'; box.setAttribute('data-wt-credits', '1');
    box.style.cssText = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:16px;margin-bottom:14px;';
    box.innerHTML = '<div style="font-weight:800;font-size:15px;margin-bottom:6px;">🔊 ' + esc(T('Sound credits')) + '</div>'
      + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.55;color:#334155;">' + CREDITS.map(function (c) {
        return '<li><span data-no-i18n>“<a href="' + c[2] + '" target="_blank" rel="noopener">' + esc(c[0]) + '</a>” ' + esc(T('by')) + ' ' + esc(c[1]) + ' — <a href="' + c[4] + '" target="_blank" rel="noopener">' + esc(c[3]) + '</a></span> <span style="color:#64748B;">(' + esc(T(c[5])) + ')</span></li>';
      }).join('') + '</ul><div style="font-size:12px;color:#64748B;margin-top:6px;">' + esc(T('Changes: shortened, made mono and quieter.')) + '</div>';
    return box;
  }
  function agencyPane(pane) {
    css();
    pane.innerHTML = '<div class="kt-card wt-card" style="max-width:860px;">' + cardHead('📻 ' + esc(T('Walkie-talkie'))) + '<div class="wt-sub">' + esc(T('Loading…')) + '</div></div>';
    return load().then(function (r) {
      var card = pane.firstChild;
      if (!r.can_admin) { card.innerHTML = cardHead('📻 ' + esc(T('Walkie-talkie'))) + '<div class="wt-note">' + esc(T('Only agency administrators can change this.')) + '</div>'; return; }
      card.innerHTML = cardHead('📻 ' + esc(T('Walkie-talkie')), 'For the whole agency: on or off, when phones listen, how long talks are kept, the emergency channel and who educators can call.')
        + settingsHtml(r) + '<div class="wt-links"><a href="#walkie">📻 ' + esc(T('Open the Walkie-talkie')) + '</a><a href="' + MY_LINK + '">⚙️ ' + esc(T('My walkie settings')) + '</a></div>';
      wireSettings(card, r, function () { agencyPane(pane); });
    }, function (e) {
      pane.firstChild.innerHTML = cardHead('📻 ' + esc(T('Walkie-talkie'))) + '<div class="wt-note">' + esc(T(errMsg(e))) + '</div>';
    });
  }

  function reg() {
    if (!KT.Shell || !KT.Shell.registerScreen) return setTimeout(reg, 200);
    ['agency_admin', 'centre_director', 'educator', 'home_visitor'].forEach(function (role) { KT.Shell.registerScreen(role + ':walkie', render); });
  }
  reg();
  KT.walkie = { talkStart: talkStart, talkEnd: talkEnd, bindPtt: bindPtt, warm: warm, myCard: myCard, agencyPane: agencyPane, creditsCard: creditsCard,
    me: function () { return S.me; }, load: load, remember: remember, playClip: playClip, refresh: function () { if (S.me && S.bell) inbox(); }, talking: function () { return !!S.talk; },
    live: function () { return S.card ? { from: S.card.t.from, label: S.card.t.label, emergency: !!S.card.t.emergency } : null; }, myLink: MY_LINK, sound: function (n) { var c = ctx(); return c ? sound(c, n, c.currentTime + 0.02) : 0; }, state: function () { return { me: !!S.me, native: S.nativeOn, leader: isLeader(), unlocked: unlocked(), queue: S.queue.length }; } };
})(window, document);
