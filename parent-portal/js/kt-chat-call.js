/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — 📞 voice calls from a chat (2026-11-04, App\Support\ChatCall)

   Anthony: "can we wire up voice calls through the chat", "inside the app only", "staff and parents", then "it needs
   to be not through telynx where I need to pay, can you make this free some how and make sure its stable".

   FREE: browser to browser (WebRTC). KiddieTrac's server only passes the two pages' "how to reach me" notes (SDP)
   and keeps the call's state; the sound goes straight between the two devices.

   Calling (KT.chatCall.start(endpoint, body), the 📞 that kt-compose-plus.js puts beside the 👋):
     microphone → POST …/call (rings the others) → this page's offer → POST /chat-calls/{id}/offer → a ringback
     while it looks at GET /chat-calls/{id} → someone's answer arrives → the two connect → "On a call 0:12".
   Being called (KT.chatCall.ring(id)): the bell's 'call' row (kt-sounds.js → kt:notification), a tap on the
     call's push (#…&call=ID) or the app's push while open (kt-urgent-alert.js). Answer / Decline; Answer takes the
     offer, makes the answer and posts it (the first to answer gets the call; everyone else's card says so).
   Never stuck: no answer in 45 s = missed; connected-or-not within 20 s of answering; a dropped line gets 8 s to
   come back. Both pages look every 3 s during the call, so either hanging up ends it on both. Every failure keeps
   its reason (POST hangup {cause} → audit chat.call_not_connected).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktChatCall) return; window.__ktChatCall = true;
  var document = window.document;
  var cur = null;        // { id, role: 'out'|'in', name, pc, stream, poll, t0, timer, state }
  var card = null, ringer = null, ringback = null, endedT = null, seenRing = {};

  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function headers(json) {
    var h = { Authorization: 'Bearer ' + token(), Accept: 'application/json' };
    try { var a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; } catch (e) {}
    if (json) h['Content-Type'] = 'application/json';
    return h;
  }
  function api(method, path, body, keep) {
    return fetch(apiBase() + path, { method: method, headers: headers(!!body), body: body ? JSON.stringify(body) : undefined, keepalive: !!keep })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j || {} }; }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(title, msg, colour) { try { if (window.KT && KT.toast) KT.toast('📞', title, msg || '', colour || '#1F6080'); } catch (e) {} }
  function mmss(sec) { sec = Math.max(0, Math.floor(sec)); return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2); }
  function supported() { return !!(window.RTCPeerConnection && navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }
  function kb() { try { return window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.KtBio; } catch (e) { return null; } }

  // ── the card ──────────────────────────────────────────────────────────────────
  function css() {
    if (document.getElementById('kt-cc-css')) return;
    var st = document.createElement('style'); st.id = 'kt-cc-css';
    st.textContent = '#kt-cc{position:fixed;right:24px;bottom:96px;z-index:2147483000;width:min(330px,calc(100vw - 24px));background:#0F172A;color:#fff;border-radius:16px;'
      + 'box-shadow:0 18px 50px rgba(15,23,42,.45);padding:14px 16px;font-family:inherit;box-sizing:border-box;text-align:left}'
      + '#kt-cc .cc-top{font-size:11.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#7DD3FC}'
      + '#kt-cc .cc-who{font-size:19px;font-weight:900;margin:4px 0 2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}#kt-cc .cc-sub{font-size:12.5px;color:#CBD5E1;min-height:16px}'
      + '#kt-cc .cc-btns{display:flex;gap:8px;margin-top:12px}#kt-cc button{flex:1;height:40px;border:0;border-radius:11px;font-weight:800;font-size:14px;cursor:pointer;color:#fff;background:#334155;font-family:inherit}'
      + '#kt-cc .cc-yes{background:#16A34A}#kt-cc .cc-no{background:#DC2626}#kt-cc .bi{display:none}'
      + '#kt-cc .cc-ava{float:right;display:flex;width:46px;height:46px;margin:0 0 0 10px;border-radius:50%;background:rgba(255,255,255,.14);align-items:center;justify-content:center;font-size:16px;font-weight:800;overflow:hidden}'
      + '#kt-cc .cc-ava img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block}'
      + '#kt-cc .cc-net{margin-top:8px;padding:5px 10px;border-radius:999px;font-size:12.5px;font-weight:800;display:inline-block;background:#B45309;color:#fff}#kt-cc .cc-net.bad{background:#B91C1C}#kt-cc .cc-net[hidden]{display:none}'
      + '#kt-cc .cc-menu{display:flex;flex-direction:column;gap:6px;width:100%;margin-top:10px}#kt-cc .cc-menu button{flex:none;height:40px;text-align:left;padding:0 12px;background:rgba(255,255,255,.12);font-size:14px}'
      + '#kt-cc .cc-menu button.on{background:#0E7490}#kt-cc .cc-menu .cc-mnote{font-size:12.5px;color:#CBD5E1;line-height:1.45;padding:2px 2px 4px}'
      + '@keyframes ktccPulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.55)}100%{box-shadow:0 0 0 14px rgba(22,163,74,0)}}#kt-cc.ringing .cc-yes{animation:ktccPulse 1.1s infinite}'
      /* A phone: the whole screen, like the phone's own call screen. */
      + '@media (max-width:768px){#kt-cc{left:0;right:0;top:var(--kt-vv-top,0px);bottom:auto;height:var(--kt-vv-h,100%);width:auto;border-radius:0;'
      + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 40px) 24px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 34px);display:flex;flex-direction:column;align-items:center;text-align:center;'
      + 'background:linear-gradient(170deg,#0B1F3A 0%,#0F3B4F 55%,#064E3B 100%);box-shadow:none}'
      + '#kt-cc .cc-top{font-size:13px;letter-spacing:.12em}'
      + '#kt-cc .cc-ava{float:none;display:flex;width:104px;height:104px;border-radius:50%;background:rgba(255,255,255,.14);align-items:center;justify-content:center;font-size:38px;font-weight:800;margin:30px auto 0}'
      + '#kt-cc .cc-who{font-size:30px;margin:16px 0 4px;line-height:1.15;white-space:normal}#kt-cc .cc-sub{font-size:16px}'
      + '#kt-cc .cc-btns{margin-top:auto;width:100%;max-width:340px;justify-content:space-around;gap:10px}'
      + '#kt-cc .cc-menu{margin-top:auto;max-width:340px}#kt-cc .cc-menu+.cc-btns{margin-top:14px}#kt-cc .cc-net{font-size:14px;margin-top:14px}'
      + '#kt-cc .cc-btns button{flex:0 0 74px;width:74px;height:74px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:0}'
      + '#kt-cc .bi{display:block;font-size:26px;line-height:1}#kt-cc .bl{font-size:10.5px;font-weight:700;opacity:.9}#kt-cc .cc-no .bi{transform:rotate(135deg)}}';
    document.head.appendChild(st);
  }
  function btn(cls, icon, label) {
    return '<button type="button" class="' + cls + '" data-kt-iconized="1" data-kt-nospin="1" aria-label="' + esc(label) + '"><span class="bi">' + icon + '</span><span class="bl">' + esc(label) + '</span></button>';
  }
  /* Their display picture (2026-11-04), the initials when they have none or it will not load. */
  function ava(name, photo) {
    var n = String(name || '').replace(/[^A-Za-zÀ-ɏ ]/g, ' ').trim().split(/\s+/).filter(Boolean);
    var ini = esc(n.length ? (n[0][0] + (n.length > 1 ? n[n.length - 1][0] : '')).toUpperCase() : '📞');
    if (photo) return '<div class="cc-ava" data-ini="' + ini + '"><img src="' + esc(photo) + '" alt="" onerror="this.parentNode.textContent=this.parentNode.getAttribute(&quot;data-ini&quot;)"></div>';
    return '<div class="cc-ava">' + ini + '</div>';
  }
  /* A session description must end with a line break; the server's input trimming used to cut it (2026-11-04). */
  function sdpOf(s) { return String(s || '').replace(/[\r\n]+$/, '') + '\r\n'; }
  function draw(cls, top, who, sub, buttons, photo) {
    css();
    clearTimeout(endedT);
    if (!card) {
      card = document.createElement('div'); card.id = 'kt-cc';
      card.setAttribute('data-kt-keep-brand', '1'); card.setAttribute('data-kt-no-close', '1');
      /* Its own layout (2026-11-04, Anthony: "the answer and decline buttons not showing up correctly"): kt-modal-guard
         and kt-dialog-move took it for a popup - Decline became the gradient header bar, the text was pushed left and
         the button row became a scroll region. */
      ['data-no-modal-guard', 'data-kt-nochrome', 'data-kt-nomove', 'data-kt-own-head', 'data-kt-own-scroll'].forEach(function (a) { card.setAttribute(a, '1'); });
      document.body.appendChild(card);
    }
    card.className = cls || '';
    card.setAttribute('role', cls === 'ringing' ? 'alertdialog' : 'dialog');
    card.innerHTML = '<div class="cc-top">' + esc(top) + '</div>' + ava(who, photo) + '<div class="cc-who">' + esc(who) + '</div>'
      + '<div class="cc-sub" data-st>' + esc(sub || '') + '</div><div class="cc-btns">' + buttons + '</div>';
    return card;
  }
  function setSub(t) { var el = card && card.querySelector('[data-st]'); if (el) el.textContent = t; }
  function closeCard() { clearTimeout(endedT); if (card) { card.remove(); card = null; } }

  // ── sounds ────────────────────────────────────────────────────────────────────
  function tones(pattern, every, vib) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return function () {};
      var ctx = new Ctx(), on = true;
      var play = function () {
        if (!on) return;
        pattern.forEach(function (p) {
          var o = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime + p[1];
          o.frequency.value = p[0]; o.connect(g); g.connect(ctx.destination);
          if (p[4]) {   // trill: the pitch flips p[4] times a second between p[0] ± p[5], like a phone's bell
            var lfo = ctx.createOscillator(), lg = ctx.createGain();
            lfo.type = 'square'; lfo.frequency.value = p[4]; lg.gain.value = p[5] || 120;
            lfo.connect(lg); lg.connect(o.frequency); lfo.start(t); lfo.stop(t + p[2] + 0.02);
          }
          g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(p[3], t + 0.03);
          g.gain.setValueAtTime(p[3], t + p[2] - 0.05); g.gain.exponentialRampToValueAtTime(0.0001, t + p[2]);
          o.start(t); o.stop(t + p[2] + 0.02);
        });
        if (vib && window.KT && KT.sounds && KT.sounds.buzz) { try { KT.sounds.buzz([400, 200, 400]); } catch (e) {} }
      };
      play(); var iv = setInterval(play, every);
      return function () { on = false; clearInterval(iv); try { ctx.close(); } catch (e) {} try { if (vib && navigator.vibrate) navigator.vibrate(0); } catch (e) {} };
    } catch (e) { return function () {}; }
  }
  function ringOn() { ringOff(); ringer = tones([[1100, 0, 0.9, 0.22, 18, 130], [1100, 1.2, 0.9, 0.22, 18, 130]], 3600, true); }
  function ringOff() { if (ringer) { ringer(); ringer = null; } }
  function ringbackOn() { ringbackOff(); ringback = tones([[440, 0, 2, 0.05], [480, 0, 2, 0.05]], 4000, false); }
  function ringbackOff() { if (ringback) { ringback(); ringback = null; } }

  // ── louder both ways (2026-11-04) ─────────────────────────────────────────────
  /* Anthony: "increase speaker volume and mic volume". The microphone goes through a gain and a limiter before it is
     sent - louder, without clipping - on every device. In a browser their voice is played through a gain too (an
     <audio> element stops at 100%). In the phone apps their voice stays on the <audio> element, so the phone's call
     routing (earpiece, speaker, Bluetooth) keeps working; the phone's volume buttons set it there. */
  var MIC_GAIN = 1.8, SPK_GAIN = 2.2;
  function isApp() { try { return !!(window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()); } catch (e) { return false; } }
  // Made in the tap (Call / Answer), before anything is awaited, so the browser lets it run.
  function audioCtx(c) {
    try {
      if (!c.actx) { var Ctx = window.AudioContext || window.webkitAudioContext; if (Ctx) c.actx = new Ctx(); }
      if (c.actx && c.actx.state === 'suspended') c.actx.resume().catch(function () {});
    } catch (e) {}
    return c.actx || null;
  }
  // What is sent: the microphone, boosted. If the audio engine is not running, the plain microphone (never silence).
  function sendStream(c) {
    var raw = c.stream, ctx = c.actx;
    if (!ctx || ctx.state !== 'running') return raw;
    try {
      if (c.micNodes) c.micNodes.forEach(function (n) { try { n.disconnect(); } catch (e) {} });
      var src = ctx.createMediaStreamSource(raw), g = ctx.createGain(), lim = ctx.createDynamicsCompressor(), dst = ctx.createMediaStreamDestination();
      g.gain.value = MIC_GAIN;
      lim.threshold.value = -6; lim.knee.value = 0; lim.ratio.value = 20; lim.attack.value = 0.003; lim.release.value = 0.15;
      src.connect(g); g.connect(lim); lim.connect(dst);
      c.micNodes = [src, g, lim];
      return dst.stream;
    } catch (e) { return raw; }
  }
  // What is heard: their voice. Browsers: through a gain (the element stays attached, muted - Chrome only delivers a
  // remote stream to the audio engine while an element plays it). Apps: the element, at full volume.
  function playRemote(c, stream, audio) {
    audio.srcObject = stream; audio.volume = 1; audio.muted = false;
    var ctx = c.actx;
    if (!isApp() && ctx && ctx.state === 'running' && !c.spkNodes) {
      try {
        var s = ctx.createMediaStreamSource(stream), g = ctx.createGain(), lim = ctx.createDynamicsCompressor();
        g.gain.value = SPK_GAIN; lim.threshold.value = -3; lim.knee.value = 0; lim.ratio.value = 20; lim.attack.value = 0.003; lim.release.value = 0.15;
        s.connect(g); g.connect(lim); lim.connect(ctx.destination);
        c.spkNodes = [s, g, lim];
        audio.muted = true;
      } catch (e) { audio.muted = false; }
    }
    var p = audio.play(); if (p && p.catch) p.catch(function () {});
  }

  // ── connection quality (2026-11-04: "monitor connection, if its slow show slow connection on the screen") ────
  /* Every 2 s: round-trip time, lost audio packets and jitter from the call's own statistics, and whether any of
     their audio arrived at all. Shown after two bad readings in a row (a single blip is not news) and cleared after
     two good ones. Amber = slow, red = very slow / nothing coming through. */
  function watchNet(c) {
    clearInterval(c.netT);
    var last = null, badRun = 0, goodRun = 0, shown = 0;
    c.netT = setInterval(function () {
      if (cur !== c || !c.pc || c.done) { clearInterval(c.netT); return; }
      c.pc.getStats().then(function (rep) {
        var rtt = null, lost = 0, got = 0, jit = 0;
        rep.forEach(function (s) {
          if (s.type === 'candidate-pair' && s.state === 'succeeded' && (s.nominated || s.selected) && s.currentRoundTripTime != null) rtt = s.currentRoundTripTime;
          if (s.type === 'inbound-rtp' && (s.kind === 'audio' || s.mediaType === 'audio')) { lost = s.packetsLost || 0; got = s.packetsReceived || 0; jit = s.jitter || 0; }
          if (s.type === 'remote-inbound-rtp' && (s.kind === 'audio' || s.mediaType === 'audio') && rtt == null && s.roundTripTime != null) rtt = s.roundTripTime;
        });
        var dl = last ? Math.max(0, lost - last.lost) : 0, dg = last ? Math.max(0, got - last.got) : 0;
        var loss = (dl + dg) ? dl / (dl + dg) : 0, silent = !!last && dg === 0;
        last = { lost: lost, got: got };
        var lvl = (silent || (rtt != null && rtt > 0.6) || loss > 0.1 || jit > 0.1) ? 2
          : ((rtt != null && rtt > 0.3) || loss > 0.03 || jit > 0.05) ? 1 : 0;
        c.netWorst = Math.max(c.netWorst || 0, lvl);
        if (lvl) { badRun++; goodRun = 0; } else { goodRun++; badRun = 0; }
        var want = lvl && badRun >= 2 ? lvl : (!lvl && goodRun >= 2 ? 0 : shown);
        if (want === shown) return;
        shown = want;
        var el = card && card.querySelector('.cc-net'); if (!el) return;
        el.hidden = !want;
        el.className = 'cc-net' + (want === 2 ? ' bad' : '');
        el.textContent = want === 2 ? (silent ? '📶 Very slow connection - their sound is not coming through' : '📶 Very slow connection - sound may cut out')
          : '📶 Slow connection - the call may break up';
      }).catch(function () {});
    }, 2000);
  }

  // ── where the sound goes: phone, speaker, headset, Bluetooth (2026-11-04: "add bluetooth to the call") ────────
  var ROUTE_ICON = { earpiece: '📱', speaker: '🔊', wired: '🎧', bluetooth: '🎧' };
  async function audioMenu(c, btnEl) {
    var old = card && card.querySelector('.cc-menu'); if (old) { old.remove(); return; }
    var box = document.createElement('div'); box.className = 'cc-menu';
    box.innerHTML = '<div class="cc-mnote">Looking…</div>';
    var btns = card.querySelector('.cc-btns'); card.insertBefore(box, btns);
    var k = kb(), items = [];
    if (k && k.audioRoutes) {
      // The Android app: the phone's own routes (KtBio, as the dial pad uses them).
      try {
        var r = await k.audioRoutes();
        (r.routes || []).forEach(function (o) {
          items.push({ label: (o.kind === 'bluetooth' ? 'Bluetooth · ' : o.kind === 'wired' ? 'Headset · ' : '') + (o.name || o.kind), icon: ROUTE_ICON[o.kind] || '🔈', on: r.current === o.id,
            pick: function () { return k.setAudioRoute({ kind: o.kind, id: o.id }); } });
        });
        if (!(r.routes || []).some(function (o) { return o.kind === 'bluetooth'; })) {
          items.push({ label: r.bluetoothAllowed === false ? 'Bluetooth (allow access)' : 'Bluetooth', icon: '🎧', on: false, pick: function () { return k.setAudioRoute({ kind: 'bluetooth' }); } });
        }
      } catch (e) {}
    } else if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
      // A browser: its speakers and microphones (a Bluetooth headset is one of them once it is connected).
      try {
        var devs = await navigator.mediaDevices.enumerateDevices();
        var audio = document.getElementById('kt-cc-audio');
        var canOut = (c.spkNodes && c.actx && c.actx.setSinkId) || (audio && audio.setSinkId);
        if (canOut) devs.filter(function (d) { return d.kind === 'audiooutput' && d.deviceId !== 'communications'; }).forEach(function (d) {
          items.push({ label: d.label || 'Speaker', icon: /head|bluetooth|airpod|buds|hands-free/i.test(d.label) ? '🎧' : '🔊', on: c.outId === d.deviceId || (!c.outId && d.deviceId === 'default'),
            pick: function () { c.outId = d.deviceId; return (c.spkNodes && c.actx.setSinkId) ? c.actx.setSinkId(d.deviceId) : audio.setSinkId(d.deviceId); } });
        });
        devs.filter(function (d) { return d.kind === 'audioinput' && d.deviceId !== 'communications'; }).forEach(function (d) {
          items.push({ label: 'Microphone · ' + (d.label || 'default'), icon: '🎙️', on: c.inId === d.deviceId || (!c.inId && d.deviceId === 'default'), pick: function () { return switchMic(c, d.deviceId); } });
        });
      } catch (e) {}
    }
    if (!box.isConnected) return;
    if (!items.length) {
      box.innerHTML = '<div class="cc-mnote">' + (isApp() ? 'Update the KiddieTrac app to choose the speaker, a headset or Bluetooth. A connected Bluetooth headset is used by itself.'
        : 'This device picks where the sound goes itself. On an iPhone, choose AirPods or Bluetooth in Control Centre.') + '</div>';
      return;
    }
    box.innerHTML = items.map(function (it, i) { return '<button type="button" data-au="' + i + '" data-kt-iconized="1" data-kt-nospin="1"' + (it.on ? ' class="on"' : '') + '>' + it.icon + ' ' + esc(it.label) + (it.on ? ' ✓' : '') + '</button>'; }).join('');
    box.querySelectorAll('[data-au]').forEach(function (b) {
      b.onclick = function () {
        var it = items[+b.getAttribute('data-au')];
        Promise.resolve(it.pick()).then(function (res) {
          if (res && res.ok === false) { b.textContent = it.icon + ' ' + it.label + ' (not available)'; return; }
          if (it.icon !== '🎙️') { var l = btnEl.querySelector('.bl'), i = btnEl.querySelector('.bi'); if (l) l.textContent = it.label.replace(/^(Bluetooth|Headset) · /, '').slice(0, 12); if (i) i.textContent = it.icon; }
          box.remove();
        }).catch(function () { b.textContent = it.icon + ' ' + it.label + ' (not available)'; });
      };
    });
  }
  // A different microphone (a browser's headset): the new one, boosted, replaces the old on the call.
  async function switchMic(c, deviceId) {
    var s = await navigator.mediaDevices.getUserMedia({ audio: { deviceId: { exact: deviceId }, echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false });
    var old = c.stream; c.stream = s; c.inId = deviceId;
    s.getAudioTracks().forEach(function (t) { t.enabled = !c.muted; });
    var send = sendStream(c).getAudioTracks()[0];
    var sender = c.pc && c.pc.getSenders().find(function (x) { return x.track && x.track.kind === 'audio'; });
    if (sender) await sender.replaceTrack(send);
    try { old.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
  }

  // ── the connection ────────────────────────────────────────────────────────────
  async function mic() {
    if (!supported()) { toast('Calls are not available here', 'This browser cannot make calls. Try Chrome, Safari or the KiddieTrac app.', '#B45309'); return null; }
    // The Android app asks for the microphone in context (KtBio, 3.12.5+).
    try { var k = kb(); if (k && k.requestMic) { var r = await k.requestMic(); if (r && r.granted === false) { toast('Microphone needed', 'Allow the microphone for KiddieTrac to make and answer calls.', '#B45309'); return null; } } } catch (e) {}
    try {
      return await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false });
    } catch (e) {
      toast('Microphone needed', 'Allow the microphone for KiddieTrac to make and answer calls.', '#B45309');
      return null;
    }
  }
  function peer(c, ice) {
    var pc = new RTCPeerConnection({ iceServers: ice && ice.length ? ice : [{ urls: ['stun:stun.cloudflare.com:3478', 'stun:stun.l.google.com:19302'] }] });
    var out = sendStream(c);
    out.getAudioTracks().forEach(function (t) { pc.addTrack(t, out); });
    var audio = document.getElementById('kt-cc-audio');
    if (!audio) { audio = document.createElement('audio'); audio.id = 'kt-cc-audio'; audio.autoplay = true; audio.setAttribute('playsinline', ''); audio.style.display = 'none'; document.body.appendChild(audio); }
    pc.ontrack = function (ev) { playRemote(c, ev.streams[0], audio); };
    pc.onconnectionstatechange = function () { if (cur === c) onState(c, pc.connectionState); };
    pc.oniceconnectionstatechange = function () {
      // Safari reports through ICE only on some versions.
      if (cur !== c || pc.connectionState) return;
      var m = { connected: 'connected', completed: 'connected', failed: 'failed', disconnected: 'disconnected' }[pc.iceConnectionState];
      if (m) onState(c, m);
    };
    c.pc = pc;
    return pc;
  }
  /* Every address this device can be reached at, gathered before the note is sent (no trickling: two notes
     through the server, and done). Three seconds is plenty; whatever is found by then goes. */
  function gathered(pc) {
    return new Promise(function (ok) {
      if (pc.iceGatheringState === 'complete') return ok();
      var done = function () { pc.removeEventListener('icegatheringstatechange', chk); ok(); };
      var chk = function () { if (pc.iceGatheringState === 'complete') done(); };
      pc.addEventListener('icegatheringstatechange', chk);
      setTimeout(done, 3000);
    });
  }
  function onState(c, st) {
    if (st === 'connected') {
      clearTimeout(c.connectT); clearTimeout(c.dropT);
      if (!c.t0) {
        c.t0 = Date.now();
        ringbackOff();
        api('POST', '/chat-calls/' + c.id + '/talking', {}).catch(function () {});
        speakerDefault();
        drawTalking(c);
      } else { setSub('On a call ' + mmss((Date.now() - c.t0) / 1000)); }
    } else if (st === 'disconnected') {
      // A blip (Wi-Fi to mobile, a tunnel): 8 seconds to come back by itself.
      setSub('Reconnecting…');
      clearTimeout(c.dropT);
      c.dropT = setTimeout(function () { if (cur === c && c.pc && c.pc.connectionState !== 'connected') finish(c, 'Call dropped', 'The connection was lost.', true, 'connection lost'); }, 8000);
    } else if (st === 'failed') {
      finish(c, c.t0 ? 'Call dropped' : 'Couldn’t connect', c.t0 ? 'The connection was lost.' : 'The two devices could not reach each other.', true, 'connection failed (' + st + ')');
    }
  }
  function speakerDefault() {
    // A phone call through the app starts on the loudspeaker-or-headset route the person last chose (KtBio).
    var k = kb(); if (k && k.setAudioRoute) { try { k.setAudioRoute({ kind: 'auto' }); } catch (e) {} }
  }
  function drawTalking(c) {
    var c2 = draw('', '🟢 On a call', c.name, 'On a call 0:00',
      btn('cc-mute', '🎙️', 'Mute') + btn('cc-spk', '🔊', 'Audio') + btn('cc-no', '📞', 'Hang up'), c.photo);
    var net = document.createElement('div'); net.className = 'cc-net'; net.hidden = true; net.setAttribute('aria-live', 'polite');
    c2.insertBefore(net, c2.querySelector('.cc-btns'));
    c2.querySelector('.cc-mute').onclick = function () {
      c.muted = !c.muted;
      try { c.stream.getAudioTracks().forEach(function (t) { t.enabled = !c.muted; }); } catch (e) {}
      var l = this.querySelector('.bl'), i = this.querySelector('.bi'); if (l) l.textContent = c.muted ? 'Unmute' : 'Mute'; if (i) i.textContent = c.muted ? '🔇' : '🎙️';
    };
    var sb = c2.querySelector('.cc-spk');
    sb.onclick = function () { audioMenu(c, sb); };
    watchNet(c);
    c2.querySelector('.cc-no').onclick = function () { hangUp(c); };
    clearInterval(c.timer);
    c.timer = setInterval(function () { if (cur === c && c.t0 && (!c.pc || c.pc.connectionState === 'connected' || c.pc.iceConnectionState === 'connected')) setSub('On a call ' + mmss((Date.now() - c.t0) / 1000)); }, 1000);
  }
  /* The end, said once: tidy everything, tell the server unless it told us, show how it ended for a few seconds. */
  function finish(c, words, sub, tellServer, cause) {
    if (!c || c.done) return;
    c.done = true;
    clearInterval(c.poll); clearInterval(c.timer); clearInterval(c.netT); clearTimeout(c.connectT); clearTimeout(c.dropT); clearTimeout(c.answerT);
    try { if (c.actx) c.actx.close(); } catch (e) {}
    ringOff(); ringbackOff();
    try { if (c.pc) c.pc.close(); } catch (e) {}
    try { if (c.stream) c.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    try { var a = document.getElementById('kt-cc-audio'); if (a) a.srcObject = null; } catch (e) {}
    var k = kb(); if (k && k.resetAudio) { try { k.resetAudio(); } catch (e) {} }
    if (tellServer && c.id) api('POST', '/chat-calls/' + c.id + '/hangup', cause ? { reason: c.t0 ? 'dropped' : 'not-connected', cause: String(cause).slice(0, 200) } : {}, true).catch(function () {});
    if (cur === c) cur = null;
    if (!words) { closeCard(); return; }
    var c2 = draw('', '📞 ' + words, c.name || '', sub || (c.t0 ? mmss((Date.now() - c.t0) / 1000) : ''), btn('cc-x', '✕', 'Close'), c.photo);
    c2.querySelector('.cc-x').onclick = closeCard;
    endedT = setTimeout(closeCard, 6000);
  }
  function hangUp(c) { finish(c, c.t0 ? 'Call ended' : 'Call cancelled', '', true); }
  var ENDED = { declined: 'Declined', missed: 'No answer', canceled: 'Call cancelled', failed: 'Couldn’t connect', ended: 'Call ended' };

  /* Both ends look at the call every few seconds: the other side hanging up, or answering, shows here. */
  function watch(c, every) {
    clearInterval(c.poll);
    c.poll = setInterval(function () {
      if (cur !== c) { clearInterval(c.poll); return; }
      api('GET', '/chat-calls/' + c.id).then(function (x) {
        if (cur !== c || !x.ok || !x.j.call) { if (cur === c && x.status === 404) finish(c, 'Call ended', '', false); return; }
        var k = x.j.call;
        if (c.role === 'out') {
          if (k.status === 'connecting' && k.answer_sdp && !c.answered) {
            c.answered = true;
            ringbackOff();
            if (k.answered_by) { c.name = k.answered_by; var w = card && card.querySelector('.cc-who'); if (w) w.textContent = k.answered_by; }
            setSub('Connecting…');
            c.pc.setRemoteDescription({ type: 'answer', sdp: sdpOf(k.answer_sdp) }).catch(function (e) { finish(c, 'Couldn’t connect', '', true, 'answer refused: ' + ((e && e.message) || e)); });
            c.connectT = setTimeout(function () { if (cur === c && !c.t0) finish(c, 'Couldn’t connect', 'The two devices could not reach each other.', true, 'not connected 20 s after the answer'); }, 20000);
            clearInterval(c.poll); watch(c, 3000);
            return;
          }
          if (k.status === 'ringing') return;
        } else if (c.role === 'ring') {
          // Still ringing here: someone else answered, or the caller gave up.
          if (k.status === 'ringing') return;
          if ((k.status === 'connecting' || k.status === 'answered') && !k.answered_by_me) { finish(c, 'Answered by ' + (k.answered_by || 'someone else'), '', false); return; }
          if (k.status === 'declined') { finish(c, null, '', false); return; }
          finish(c, 'Missed call', 'from ' + c.name, false);
          return;
        }
        if (['ended', 'missed', 'declined', 'canceled', 'failed'].indexOf(k.status) !== -1) {
          finish(c, k.status === 'ended' && c.t0 ? 'Call ended' : (ENDED[k.status] || 'Call ended'), k.duration ? mmss(k.duration) : '', false);
        }
      }).catch(function () {});
    }, every);
  }

  // ── calling ───────────────────────────────────────────────────────────────────
  async function start(endpoint, body) {
    if (cur) { toast('You are already in a call', 'Hang it up first.', '#B45309'); return false; }
    try { if (window.KT && KT.portalPhone && KT.portalPhone.state && KT.portalPhone.state().busy) { toast('You are on a phone call', 'Hang it up first.', '#B45309'); return false; } } catch (e) {}
    var c = cur = { id: 0, role: 'out', name: 'Calling…' };
    audioCtx(c);
    var c1 = draw('', '📞 KiddieTrac call', 'Calling…', 'Getting ready…', btn('cc-no', '📞', 'Cancel'));
    c1.querySelector('.cc-no').onclick = function () { hangUp(c); };
    c.stream = await mic();
    if (cur !== c) { try { c.stream && c.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} return false; }
    if (!c.stream) { finish(c, null); return false; }
    var x = await api('POST', endpoint, body || {}).catch(function () { return { ok: false, j: {} }; });
    if (cur !== c) { if (x.ok && x.j.call) api('POST', '/chat-calls/' + x.j.call.id + '/hangup', {}).catch(function () {}); return false; }
    if (!x.ok || !x.j.call) { c.done = false; finish(c, 'Couldn’t call', (x.j && x.j.message) || 'Please try again.', false); return false; }
    c.id = x.j.call.id; c.name = x.j.call.other || 'KiddieTrac call'; c.photo = x.j.call.other_photo || '';
    draw('', '📞 KiddieTrac call', c.name, 'Ringing…', btn('cc-no', '📞', 'Hang up'), c.photo).querySelector('.cc-no').onclick = function () { hangUp(c); };
    ringbackOn();
    try {
      var pc = peer(c, x.j.ice_servers);
      await pc.setLocalDescription(await pc.createOffer({ offerToReceiveAudio: true }));
      await gathered(pc);
      if (cur !== c) return false;
      var o = await api('POST', '/chat-calls/' + c.id + '/offer', { sdp: pc.localDescription.sdp });
      if (!o.ok) { finish(c, (o.j.call && ENDED[o.j.call.status]) || 'Couldn’t call', '', o.status !== 410, 'offer not taken (' + o.status + ')'); return false; }
    } catch (e) {
      finish(c, 'Couldn’t connect', '', true, 'offer: ' + ((e && e.message) || e)); return false;
    }
    watch(c, 1500);
    return true;
  }

  // ── being called ──────────────────────────────────────────────────────────────
  function ring(id) {
    id = +id;
    if (!id || seenRing[id]) return;
    seenRing[id] = 1;
    api('GET', '/chat-calls/' + id).then(function (x) {
      var k = x.ok && x.j.call;
      if (!k || k.mine) return;
      if (k.status !== 'ringing') {
        if (k.status === 'missed' || k.status === 'canceled') toast('Missed call', 'from ' + (k.caller || 'someone') + '. Open the chat to call back.');
        return;
      }
      if (cur) return;   // already in a call: it shows as missed
      var c = cur = { id: id, role: 'ring', name: k.caller || 'KiddieTrac call', photo: k.caller_photo || '' };
      var c1 = draw('ringing', '📞 KiddieTrac call', c.name, 'Calling you from a chat', btn('cc-no', '📞', 'Decline') + btn('cc-yes', '📞', 'Answer'), c.photo);
      c1.querySelector('.cc-yes').onclick = function () { answer(c); };
      c1.querySelector('.cc-no').onclick = function () { api('POST', '/chat-calls/' + id + '/decline', {}).catch(function () {}); finish(c, null, '', false); };
      ringOn();
      watch(c, 2000);
    }).catch(function () {});
  }
  async function answer(c) {
    if (c.role !== 'ring' || cur !== c) return;
    c.role = 'in';
    audioCtx(c);
    clearInterval(c.poll); ringOff();
    draw('', '📞 KiddieTrac call', c.name, 'Connecting…', btn('cc-no', '📞', 'Hang up'), c.photo).querySelector('.cc-no').onclick = function () { hangUp(c); };
    c.stream = await mic();
    if (cur !== c) return;
    if (!c.stream) { api('POST', '/chat-calls/' + c.id + '/decline', {}).catch(function () {}); finish(c, 'Microphone needed', 'Allow the microphone to answer calls.', false); return; }
    // The caller's note can be a moment behind the ring: look for it for up to 6 seconds.
    var x = null;
    for (var i = 0; i < 6 && cur === c; i++) {
      x = await api('GET', '/chat-calls/' + c.id).catch(function () { return null; });
      if (!x || !x.ok || !x.j.call || x.j.call.status !== 'ringing' || x.j.offer_sdp) break;
      await new Promise(function (r) { setTimeout(r, 1000); });
    }
    if (cur !== c) return;
    if (!x || !x.ok || !x.j.offer_sdp) {
      var s = x && x.j && x.j.call;
      if (s && (s.status === 'connecting' || s.status === 'answered')) { finish(c, 'Answered by ' + (s.answered_by || 'someone else'), '', false); return; }
      finish(c, s && s.status !== 'ringing' ? 'The call has ended' : 'Couldn’t connect', '', false); return;
    }
    try {
      var pc = peer(c, x.j.ice_servers);
      await pc.setRemoteDescription({ type: 'offer', sdp: sdpOf(x.j.offer_sdp) });
      await pc.setLocalDescription(await pc.createAnswer());
      await gathered(pc);
      if (cur !== c) return;
      var a = await api('POST', '/chat-calls/' + c.id + '/answer', { sdp: pc.localDescription.sdp });
      if (!a.ok) { finish(c, a.j.reason === 'taken' ? 'Answered by someone else' : 'The call has ended', '', false); return; }
    } catch (e) {
      finish(c, 'Couldn’t connect', '', true, 'answer: ' + ((e && e.message) || e)); return;
    }
    c.connectT = setTimeout(function () { if (cur === c && !c.t0) finish(c, 'Couldn’t connect', 'The two devices could not reach each other.', true, 'not connected 20 s after answering'); }, 20000);
    watch(c, 3000);
  }

  // The bell's ringing row, a tap on the call's push, the app's push while open.
  window.addEventListener('kt:notification', function (e) {
    var d = e && e.detail;
    if (!d || d.type !== 'call') return;
    var data = d.data;
    try { if (typeof data === 'string') data = JSON.parse(data); } catch (x) { data = null; }
    if (data && data.call_id) ring(data.call_id);
  });
  function fromHash() {
    var m = /[?&]call=(\d+)/.exec(location.hash || '');
    if (!m) return;
    try { history.replaceState(null, '', location.pathname + location.search + location.hash.replace(/([?&])call=\d+(&?)/, function (all, a, b) { return b ? a : ''; })); } catch (e) {}
    ring(m[1]);
  }
  window.addEventListener('hashchange', fromHash);
  setTimeout(fromHash, 1500);
  // Closing the page mid-call ends it for the other side straight away.
  window.addEventListener('pagehide', function () {
    if (cur && cur.id && cur.role !== 'ring') api('POST', '/chat-calls/' + cur.id + '/hangup', { reason: cur.t0 ? 'page-closed' : 'not-connected' }, true).catch(function () {});
  });

  window.KT = window.KT || {};
  window.KT.chatCall = {
    start: start,
    ring: ring,
    supported: supported,
    state: function () { return cur ? { id: cur.id, role: cur.role, connected: !!cur.t0, pc: cur.pc ? cur.pc.connectionState : null } : null; },
  };
})(window);
