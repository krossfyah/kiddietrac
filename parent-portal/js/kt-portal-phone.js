/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — toll-free calls ringing in the portal (2026-10-22, call menu stage 2)

   Anthony chose that a department's calls ring "both" its staff phones and the portal.
   For a signed-in staff member who is one of a department's people with "In the portal"
   switched on (Settings → Phone & carriers → Toll-free line → Call menu), this keeps a
   Telnyx WebRTC client registered while the portal is open. An incoming call shows a
   card with Answer / Decline and plays a ring; answering connects the caller through the
   browser's microphone and speaker. Phones ring at the same time; the first to answer
   gets the call, and the others stop.

   Backend: GET /voice/phone/session (a 24-hour login token, or enabled:false) —
   App\Support\PortalPhone. SDK: @telnyx/webrtc 2.27.10 (pinned, jsDelivr), loaded only
   for people who ring here. Rings only while the portal is open in a tab.

   CALLING OUT (2026-10-23, the outbound dialer, kt-dialer.js). The same phone places
   calls: the server rings THIS phone first (App\Support\Dialer), and because the portal
   asked for that call (expect()), it answers it by itself and shows a "Calling…" card
   while the person being called rings. The session also says whether, and from which
   line, this person may dial.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktPortalPhone) return; window.__ktPortalPhone = true;
  var SDK = 'https://cdn.jsdelivr.net/npm/@telnyx/webrtc@2.27.10/lib/bundle.js';
  var lastCaller = null, ready = false, client = null, call = null, card = null, ringer = null, timer = null, refresh = null, depts = [];
  var dial = null, expecting = null, outPoll = null, lastErr = '', watchdog = null;

  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  // Anyone who is not only a parent may ring or dial; the session says whether they do.
  function staff() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {};
      var r = u.roles || [];
      return r.some(function (x) { return x && x !== 'guardian'; });
    } catch (e) { return false; }
  }
  function agencyHeader() {
    var h = { Authorization: 'Bearer ' + token(), Accept: 'application/json' };
    try { var a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; } catch (e) {}
    return h;
  }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmt(n) { var d = String(n || '').replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') d = d.slice(1); return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : (n || 'Unknown caller'); }

  function loadSdk() {
    return new Promise(function (ok, bad) {
      if (window.TelnyxWebRTC) return ok();
      var s = document.createElement('script');
      s.src = SDK; s.async = true; s.onload = function () { window.TelnyxWebRTC ? ok() : bad(new Error('sdk')); }; s.onerror = function () { bad(new Error('sdk')); };
      document.head.appendChild(s);
    });
  }

  async function start() {
    if (!token() || !staff()) return;
    var d;
    try {
      var r = await fetch(apiBase() + '/voice/phone/session', { headers: agencyHeader() });
      if (!r.ok) return;
      d = await r.json();
    } catch (e) { return; }
    dial = (d && d.dial) || null;
    try { window.dispatchEvent(new CustomEvent('kt:phone-session', { detail: { dial: dial } })); } catch (e) {}
    if (!d || !d.enabled || !d.token) { stop(); return; }
    depts = d.departments || [];
    try { await loadSdk(); } catch (e) { return; }
    connect(d.token);
    clearTimeout(refresh);
    // The server re-uses a token while it has hours left; fetch the next one before this one ends.
    var life = Math.max(600, Math.min(20 * 3600, (+d.ttl || 86400) - 3600));
    refresh = setTimeout(start, life * 1000);
  }

  function stop() {
    var old = client;
    client = null; ready = false;          // first, so the old client's close does not restart the phone
    try { if (old) old.disconnect(); } catch (e) {}
  }

  function connect(loginToken) {
    stop();
    var audio = document.getElementById('kt-pp-audio');
    if (!audio) { audio = document.createElement('audio'); audio.id = 'kt-pp-audio'; audio.autoplay = true; audio.style.display = 'none'; document.body.appendChild(audio); }
    client = new window.TelnyxWebRTC.TelnyxRTC({ login_token: loginToken });
    client.remoteElement = 'kt-pp-audio';
    var me = client;
    client.on('telnyx.ready', function () { if (client === me) ready = true; });
    client.on('telnyx.error', function (e) { if (client !== me) return; ready = false; try { lastErr = (e && (e.error && (e.error.message || e.error.code) || e.message || e.type)) || 'phone error'; } catch (x) { lastErr = 'phone error'; } });
    // Only this phone's own drop counts, and only if the SDK has not reconnected by itself in 15 s.
    client.on('telnyx.socket.close', function () { if (client !== me) return; ready = false; setTimeout(function () { if (client === me && !ready) start(); }, 15000); });
    client.on('telnyx.notification', function (n) {
      if (!n || n.type !== 'callUpdate' || !n.call) return;
      var c = n.call, st = c.state;
      if (st === 'ringing' && (!call || call === c) && c.direction === 'inbound') {
        call = c;
        // Picked up from the app's ringing notification: this IS that call - answer it.
        if (pickup && Date.now() - pickup.at < 45000) {
          pickup = null;
          try { c.answer({ audio: true, video: false }); } catch (e) {}
          showActive();
          return;
        }
        // A call this portal asked for (the dialer): answer it, and show who is being called.
        if (expecting && Date.now() - expecting.at < 45000) {
          try { c.answer({ audio: true, video: false }); } catch (e) {}
          showOutbound();
        } else {
          showRinging();
        }
      }
      else if (st === 'active' && call === c) { if (expecting) { showOutbound(); } else { showActive(); } }
      else if ((st === 'hangup' || st === 'destroy' || st === 'purge') && call === c) {
        if (expecting && c.direction !== 'inbound') {
          // Let the server's answer (no answer / busy / talked) arrive, then show the end.
          var id = expecting.id, why = causeOf(c);
          clearTimeout(watchdog);
          setTimeout(function () {
            if (!expecting || expecting.id !== id) return;
            fetch(apiBase() + '/voice/dialer/calls/' + id, { headers: agencyHeader() }).then(function (r) { return r.ok ? r.json() : null; })
              .then(function (j) {
                var s = j && j.call && j.call.status;
                if (s === 'dialing') { notConnected(id, why); showEnded('Couldn’t connect'); return; }
                showEnded(OUT_WORDS[s] && s !== 'answered' && s !== 'ringing' ? OUT_WORDS[s] : (expecting && expecting.answeredSeen ? 'They hung up' : 'Call ended'));
              })
              .catch(function () { showEnded('Call ended'); });
          }, 1200);
        } else {
          endCall();
        }
      }
    });
    client.connect();
  }

  /* ── MICROPHONE AND AUDIO ROUTE (2026-10-23) ───────────────────────────────────────────
     Anthony: "microphone permission not working on mobile for the dialpad and allowing me to
     use headset, speaker or bluetooth for the call". In the app (KtBio, 3.12.5+) the
     microphone is asked for in context and a refusal opens App info; older app builds could
     never grant it (they lacked MODIFY_AUDIO_SETTINGS), so they are told to update. The 🔊
     button picks where the call is heard: the app's own route list (Phone, Speaker, wired or
     Bluetooth headset) or, in a browser, the computer's output devices. */
  function kb() { try { return window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.KtBio; } catch (e) { return null; } }
  function isApp() { try { return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()); } catch (e) { return false; } }
  function micHelp(kind) {
    css();
    if (card && !card.classList.contains('pp-help')) return;   // never over a live call card
    if (!card) { card = document.createElement('div'); card.id = 'kt-pp'; card.setAttribute('role', 'dialog'); card.setAttribute('data-kt-no-close', '1'); document.body.appendChild(card); }
    card.className = 'pp-help';
    var msg = kind === 'settings'
      ? 'The microphone is switched off for KiddieTrac. Open the app settings, tap <b>Permissions → Microphone → Allow</b>, then come back and call again.'
      : kind === 'update'
        ? 'This version of the KiddieTrac app cannot use the microphone for calls. Update the app (3.12.5 or later), then call again.'
        : 'Your browser blocked the microphone. Tap the lock or settings icon beside the address, allow <b>Microphone</b> for this site, then call again.';
    card.innerHTML = '<div class="pp-top">🎙️ Microphone needed</div>' + ava('') + '<div class="pp-sub" style="font-size:14px;line-height:1.55;margin-top:10px">' + msg + '</div>'
      + '<div class="pp-btns">' + btn('pp-mute', '✕', 'Close')
      + (kind === 'settings' ? btn('pp-yes', '⚙️', 'Open settings') : '')
      + (kind === 'update' ? btn('pp-yes', '⬇️', 'Update the app') : '') + '</div>';
    card.querySelector('.pp-mute').onclick = function () { if (card) { card.remove(); card = null; } };
    var y = card.querySelector('.pp-yes');
    if (y) y.onclick = function () {
      if (kind === 'settings') { try { kb().openAppSettings(); } catch (e) {} }
      else { window.open('https://app.kiddietrac.com/dl/kiddietrac-latest.apk', '_blank'); }
    };
  }
  async function ensureMic() {
    var k = kb();
    if (k && k.micState) {
      try {
        var st = await k.micState();
        if (st && st.state === 'granted') return true;
        var r = await k.requestMic();
        if (r && r.granted) return true;
        micHelp('settings'); return false;
      } catch (e) {}
    }
    try {
      var stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      stream.getTracks().forEach(function (t) { t.stop(); });
      return true;
    } catch (e) {
      micHelp(isApp() ? (k && k.micState ? 'settings' : 'update') : 'browser');
      return false;
    }
  }
  function audioStart() { var k = kb(); if (k && k.setAudioRoute) { try { k.setAudioRoute({ kind: 'auto' }); } catch (e) {} } }
  function audioEnd() { var k = kb(); if (k && k.resetAudio) { try { k.resetAudio(); } catch (e) {} } }
  var ROUTE_ICON = { earpiece: '📱', speaker: '🔊', wired: '🎧', bluetooth: '🎧' };
  async function audioMenu(btnEl) {
    if (!card) return;
    var old = card.querySelector('.pp-aumenu'); if (old) { old.remove(); return; }
    var box = document.createElement('div'); box.className = 'pp-aumenu';
    box.innerHTML = '<div style="padding:10px 12px;color:#94A3B8;font-size:12.5px">Looking…</div>';
    card.appendChild(box);
    var k = kb(), items = [];
    if (k && k.audioRoutes) {
      try {
        var r = await k.audioRoutes();
        (r.routes || []).forEach(function (o) {
          items.push({ label: (o.kind === 'bluetooth' ? 'Bluetooth · ' : o.kind === 'wired' ? 'Headset · ' : '') + (o.name || o.kind), icon: ROUTE_ICON[o.kind] || '🔈', on: r.current === o.id,
            pick: function () { return k.setAudioRoute({ kind: o.kind, id: o.id }); } });
        });
        if (!(r.routes || []).some(function (o) { return o.kind === 'bluetooth'; }) && r.bluetoothAllowed === false) {
          items.push({ label: 'Bluetooth headset (allow access)', icon: '🎧', on: false, pick: function () { return k.setAudioRoute({ kind: 'bluetooth' }); } });
        }
      } catch (e) {}
    } else if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices && call && call.setAudioOutDevice) {
      try {
        var devs = await navigator.mediaDevices.enumerateDevices();
        devs.filter(function (d) { return d.kind === 'audiooutput'; }).forEach(function (d) {
          items.push({ label: d.label || 'Speaker', icon: /head|bluetooth|airpod|buds/i.test(d.label) ? '🎧' : '🔊', on: false,
            pick: function () { return call.setAudioOutDevice(d.deviceId); } });
        });
        devs.filter(function (d) { return d.kind === 'audioinput'; }).forEach(function (d) {
          items.push({ label: 'Microphone · ' + (d.label || 'default'), icon: '🎙️', on: false, pick: function () { return call.setAudioInDevice(d.deviceId); } });
        });
      } catch (e) {}
    }
    if (!items.length) {
      box.innerHTML = '<div style="padding:12px;color:#CBD5E1;font-size:13px;line-height:1.5">' + (isApp() ? 'Update the KiddieTrac app (3.12.5 or later) to choose the speaker, a headset or Bluetooth.' : 'This browser picks the speaker itself. Connect a headset and it is used automatically.') + '</div>';
      return;
    }
    box.innerHTML = items.map(function (it, i) { return '<button type="button" data-au="' + i + '" data-kt-iconized="1">' + it.icon + ' ' + esc(it.label) + (it.on ? ' ✓' : '') + '</button>'; }).join('');
    box.querySelectorAll('[data-au]').forEach(function (b) {
      b.onclick = function () {
        var it = items[+b.getAttribute('data-au')];
        Promise.resolve(it.pick()).then(function (res) {
          if (res && res.ok === false) { b.textContent = it.icon + ' ' + it.label + ' (unavailable)'; return; }
          setLabel(btnEl, it.icon === '🎙️' ? 'Audio' : it.label.replace(/^(Bluetooth|Headset) · /, ''), it.icon);
          box.remove();
        }).catch(function () { b.textContent = it.icon + ' ' + it.label + ' (unavailable)'; });
      };
    });
  }

  /* ── the card ── */
  function btn(cls, icon, label) {
    return '<button type="button" class="' + cls + '" data-kt-iconized="1" aria-label="' + esc(label) + '"><span class="bi">' + icon + '</span><span class="bl">' + esc(label) + '</span></button>';
  }
  function ava(name) {
    var n = String(name || '').replace(/[^A-Za-zÀ-ɏ ]/g, ' ').trim().split(/\s+/).filter(Boolean);
    var t = n.length ? (n[0][0] + (n.length > 1 ? n[n.length - 1][0] : '')).toUpperCase() : '📞';
    return '<div class="pp-ava">' + esc(t) + '</div>';
  }
  function css() {
    if (document.getElementById('kt-pp-css')) return;
    var st = document.createElement('style'); st.id = 'kt-pp-css';
    st.textContent = '#kt-pp{position:fixed;right:24px;bottom:96px;z-index:2147483000;width:min(340px,calc(100vw - 24px));background:#0F172A;color:#fff;border-radius:16px;'
      + 'box-shadow:0 18px 50px rgba(15,23,42,.45);padding:14px 16px;font-family:inherit;box-sizing:border-box}'
      + '#kt-pp .pp-top{font-size:11.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#7DD3FC}'
      + '#kt-pp .pp-who{font-size:19px;font-weight:900;margin:4px 0 2px}#kt-pp .pp-sub{font-size:12.5px;color:#CBD5E1}'
      + '#kt-pp .pp-btns{display:flex;gap:8px;margin-top:12px}#kt-pp button{flex:1;height:40px;border:0;border-radius:11px;font-weight:800;font-size:14px;cursor:pointer;color:#fff}'
      + '#kt-pp .bi,#kt-pp .pp-ava{display:none}#kt-pp .pp-kp-grid{display:none}'
      + '#kt-pp .pp-kp-grid.on{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}'
      + '#kt-pp .pp-kp-grid button{height:42px;background:rgba(255,255,255,.12);font-size:18px}'
      /* ON A PHONE: the whole screen, like the phone's own call screen (2026-10-23). */
      + '@media (max-width:768px){'
      + '#kt-pp{left:0 !important;right:0 !important;top:var(--kt-vv-top,0px) !important;bottom:auto !important;height:var(--kt-vv-h,100%) !important;box-sizing:border-box;width:auto !important;border-radius:0 !important;'
      + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 40px) 24px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 34px) !important;display:flex;flex-direction:column;align-items:center;text-align:center;'
      + 'background:linear-gradient(170deg,#0B1F3A 0%,#0F3B4F 55%,#064E3B 100%) !important;box-shadow:none !important}'
      + '#kt-pp .pp-top{font-size:13px;letter-spacing:.12em}'
      + '#kt-pp .pp-ava{display:flex;width:104px;height:104px;border-radius:50%;background:rgba(255,255,255,.14);align-items:center;justify-content:center;font-size:38px;font-weight:800;margin:30px auto 0}'
      + '#kt-pp .pp-who{font-size:30px;margin:16px 0 4px;line-height:1.15}'
      + '#kt-pp .pp-sub{font-size:16px;color:#CBD5E1}'
      + '#kt-pp .pp-btns{margin-top:auto;width:100%;max-width:340px;justify-content:space-around;gap:10px}'
      + '#kt-pp .pp-btns button{flex:0 0 74px;width:74px;height:74px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:0}'
      + '#kt-pp .bi{display:block;font-size:26px;line-height:1}#kt-pp .bl{font-size:10.5px;font-weight:700;opacity:.9}'
      + '#kt-pp .pp-kp-grid.on{width:100%;max-width:300px;margin:auto auto 18px;gap:14px}'
      + '#kt-pp .pp-kp-grid button{height:62px;width:62px;margin:0 auto;border-radius:50%;font-size:26px}'
      + '}'
      + '#kt-pp .pp-yes{background:#16A34A}#kt-pp .pp-no{background:#DC2626}#kt-pp .pp-mute,#kt-pp .pp-kp,#kt-pp .pp-au{background:#334155}'
      + '#kt-pp .pp-aumenu{margin-top:10px;width:100%;max-width:340px;background:rgba(15,23,42,.92);border:1px solid rgba(255,255,255,.14);border-radius:14px;overflow:hidden;align-self:center}'
      + '#kt-pp .pp-aumenu button{display:block;width:100%;height:auto;min-height:44px;text-align:left;padding:10px 14px;background:transparent;border-radius:0;border-top:1px solid rgba(255,255,255,.08);font-weight:700;font-size:14px}'
      + '#kt-pp .pp-aumenu button:first-child{border-top:0}'
      + '#kt-pp .pp-no .bi{display:inline-block;transform:rotate(135deg)}'
      + '@keyframes ktppPulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.55)}100%{box-shadow:0 0 0 14px rgba(22,163,74,0)}}#kt-pp.ringing .pp-yes{animation:ktppPulse 1.1s infinite}'
      + '@media (max-width:640px){#kt-pp{left:12px;right:12px;top:auto;bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 92px);width:auto}}';
    document.head.appendChild(st);
  }

  function caller() {
    if (lastCaller) return lastCaller;
    var o = (call && call.options) || {};
    return fmt(o.remoteCallerNumber || o.callerNumber || o.remoteCallerName || '');
  }

  function showRinging() {
    css();
    if (!card) { card = document.createElement('div'); card.id = 'kt-pp'; card.setAttribute('role', 'alertdialog'); card.setAttribute('data-kt-keep-brand', '1'); card.setAttribute('data-kt-no-close', '1'); document.body.appendChild(card); }
    card.className = 'ringing';
    card.innerHTML = '<div class="pp-top">📞 Incoming KiddieTrac call</div>' + ava(caller()) + '<div class="pp-who">' + esc(caller()) + '</div>'
      + '<div class="pp-sub">Toll-free line' + (depts.length ? ' · ' + esc(depts.join(', ')) : '') + '</div>'
      + '<div class="pp-btns">' + btn('pp-no', '✕', 'Decline') + btn('pp-yes', '📞', 'Answer') + '</div>';
    /* The call reaches the portal from the KiddieTrac number; the menu knows the real
       caller and the department they chose. */
    fetch(apiBase() + '/platform/phone/webrtc/caller', { headers: { Authorization: 'Bearer ' + token(), Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
        if (!j || !j.caller || !card) return;
        var who = card.querySelector('.pp-who'), sub = card.querySelector('.pp-sub');
        if (who) who.textContent = j.caller === 'unknown' ? 'Unknown caller' : j.caller;
        if (sub && j.department) sub.textContent = 'Toll-free line · ' + j.department;
        lastCaller = who ? who.textContent : null;
      }).catch(function () {});
    card.querySelector('.pp-yes').onclick = function () {
      stopRing();
      ensureMic().then(function (ok) { if (!ok) { try { call.hangup(); } catch (e) {} return; } try { call.answer({ audio: true, video: false }); } catch (e) {} });
    };
    card.querySelector('.pp-no').onclick = function () { try { call.hangup(); } catch (e) {} endCall(); };
    ring();
  }

  function showActive() {
    stopRing();
    if (!card) return;
    card.className = '';
    var t0 = Date.now(), muted = false;
    card.innerHTML = '<div class="pp-top">🟢 On a call</div>' + ava(caller()) + '<div class="pp-who">' + esc(caller()) + '</div><div class="pp-sub" data-t>0:00</div>'
      + kpGrid() + '<div class="pp-btns">' + btn('pp-mute', '🎙️', 'Mute') + btn('pp-au', '🔊', 'Audio') + btn('pp-kp', '⌨️', 'Keypad') + btn('pp-no', '📞', 'Hang up') + '</div>';
    wireKp();
    audioStart();
    card.querySelector('.pp-au').onclick = function () { audioMenu(this); };
    clearInterval(timer);
    timer = setInterval(function () { var s = Math.floor((Date.now() - t0) / 1000); var el = card && card.querySelector('[data-t]'); if (el) el.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }, 1000);
    card.querySelector('.pp-mute').onclick = function () {
      try { if (muted) call.unmuteAudio(); else call.muteAudio(); } catch (e) {}
      muted = !muted; setLabel(this, muted ? 'Unmute' : 'Mute', muted ? '🔇' : '🎙️');
    };
    card.querySelector('.pp-no').onclick = function () { try { call.hangup(); } catch (e) {} endCall(); };
  }
  function setLabel(b, label, icon) {
    var l = b.querySelector('.bl'), i = b.querySelector('.bi');
    if (l) l.textContent = label; else b.textContent = label;
    if (i && icon) i.textContent = icon;
    b.setAttribute('aria-label', label);
  }
  /* A keypad during a call, for phone menus ("press 1 for…"): the tones go down the line. */
  function kpGrid() {
    return '<div class="pp-kp-grid">' + ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'].map(function (k) {
      return '<button type="button" data-dtmf="' + k + '" data-kt-iconized="1">' + k + '</button>';
    }).join('') + '</div>';
  }
  function wireKp() {
    if (!card) return;
    var grid = card.querySelector('.pp-kp-grid'), kb = card.querySelector('.pp-kp');
    if (!grid || !kb) return;
    kb.onclick = function () { grid.classList.toggle('on'); setLabel(kb, grid.classList.contains('on') ? 'Hide keys' : 'Keypad'); };
    grid.querySelectorAll('[data-dtmf]').forEach(function (b) {
      b.onclick = function () { try { if (call && call.dtmf) call.dtmf(b.getAttribute('data-dtmf')); } catch (e) {} };
    });
  }

  /* ── calling out ── the card while the person being called rings, then talks. */
  var OUT_WORDS = { dialing: 'Connecting…', ringing: 'Ringing…', answered: 'On a call',
    completed: 'Call ended', 'hung-up': 'You hung up', 'ended-by-them': 'They hung up', 'no-answer': 'No answer',
    busy: 'Line busy', failed: 'Couldn’t connect', canceled: 'Hung up before they answered' };
  function showOutbound() {
    css();
    if (!expecting) return;
    if (!card) { card = document.createElement('div'); card.id = 'kt-pp'; card.setAttribute('role', 'dialog'); card.setAttribute('data-kt-keep-brand', '1'); card.setAttribute('data-kt-no-close', '1'); document.body.appendChild(card); }
    if (card.getAttribute('data-out') === String(expecting.id)) return;
    card.setAttribute('data-out', String(expecting.id));
    card.className = '';
    var muted = false;
    card.innerHTML = '<div class="pp-top">📞 Calling' + (dial && dial.record ? ' · <span style="color:#FCA5A5">● Recorded</span>' : '') + '</div>'
      + ava(expecting.name || '') + '<div class="pp-who">' + esc(expecting.name || expecting.pretty) + '</div>'
      + '<div class="pp-sub" data-st>' + esc(expecting.name ? expecting.pretty + ' · ' : '') + 'Connecting…</div>'
      + kpGrid() + '<div class="pp-btns">' + btn('pp-mute', '🎙️', 'Mute') + btn('pp-au', '🔊', 'Audio') + btn('pp-kp', '⌨️', 'Keypad') + btn('pp-no', '📞', 'Hang up') + '</div>';
    wireKp();
    audioStart();
    card.querySelector('.pp-au').onclick = function () { audioMenu(this); };
    card.querySelector('.pp-mute').onclick = function () {
      try { if (muted) call.unmuteAudio(); else call.muteAudio(); } catch (e) {}
      muted = !muted; setLabel(this, muted ? 'Unmute' : 'Mute', muted ? '🔇' : '🎙️');
    };
    card.querySelector('.pp-no').onclick = function () { hangupOut(); };
    pollOut();
  }
  function pollOut() {
    clearInterval(outPoll);
    var t0 = 0, id = expecting && expecting.id;
    outPoll = setInterval(function () {
      if (!expecting || expecting.id !== id) { clearInterval(outPoll); return; }
      fetch(apiBase() + '/voice/dialer/calls/' + id, { headers: agencyHeader() }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
        if (!j || !j.call || !card) return;
        var c = j.call, el = card.querySelector('[data-st]');
        if (c.status === 'answered') {
          if (expecting) expecting.answeredSeen = true;
          if (!t0) t0 = Date.now();
          var sec = Math.floor((Date.now() - t0) / 1000);
          if (el) el.textContent = (expecting.name ? expecting.pretty + ' · ' : '') + 'On a call ' + Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
        } else if (el) {
          el.textContent = (expecting.name ? expecting.pretty + ' · ' : '') + (OUT_WORDS[c.status] || c.status);
        }
        if (['completed', 'hung-up', 'ended-by-them', 'no-answer', 'busy', 'failed', 'canceled'].indexOf(c.status) !== -1) {
          clearInterval(outPoll);
          showEnded(OUT_WORDS[c.status] || c.status);
        }
      }).catch(function () {});
    }, 1500);
  }
  function hangupOut() {
    var id = expecting && expecting.id;
    try { if (call) call.hangup(); } catch (e) {}
    var words = expecting && expecting.answeredSeen ? 'You hung up' : 'Hung up before they answered';
    if (id) { fetch(apiBase() + '/voice/dialer/calls/' + id + '/hangup', { method: 'POST', headers: agencyHeader() }).catch(function () {}); }
    showEnded(words);
  }
  /* The call is over: say how it ended, and offer to call again (Anthony: "add a redial
     option"). Closes by itself after 15 seconds. */
  var endedT = null;
  function showEnded(words) {
    var was = expecting;
    clearTimeout(watchdog);
    clearInterval(outPoll); clearInterval(timer); stopRing();
    call = null; expecting = null;
    try { window.dispatchEvent(new CustomEvent('kt:phone-call-ended')); } catch (e) {}
    if (!was || !card) { if (card) { card.remove(); card = null; } return; }
    card.removeAttribute('data-out');
    card.innerHTML = '<div class="pp-top">📞 ' + esc(words || 'Call ended') + '</div>'
      + ava(was.name || '') + '<div class="pp-who">' + esc(was.name || was.pretty) + '</div>'
      + (was.name ? '<div class="pp-sub">' + esc(was.pretty) + '</div>' : '')
      + '<div class="pp-btns">' + btn('pp-mute', '✕', 'Close') + btn('pp-yes', '↻', 'Call again') + '</div>';
    var close = function () { clearTimeout(endedT); if (card) { card.remove(); card = null; } };
    card.querySelector('.pp-yes').onclick = function () { close(); if (window.KT && KT.dialer) KT.dialer.call(was.number || was.pretty, { name: was.name, context: 'redial' }); };
    card.querySelector('.pp-mute').onclick = close;
    clearTimeout(endedT); endedT = setTimeout(close, 15000);
  }
  /* Place the call: this browser dials the dialer's own address for it (see App\Support\
     Dialer), so the call cannot go to another window signed in as the same person. */
  function dialOut(c) {
    if (!client || !ready) return false;
    expecting = { id: c.id, name: c.name || '', pretty: c.to_pretty || c.to, number: c.to, at: Date.now() };
    try {
      call = client.newCall({ destinationNumber: c.sip, callerNumber: dial ? dial.from : undefined, audio: true, video: false });
    } catch (e) { expecting = null; notConnected(c.id, 'newCall failed: ' + ((e && e.message) || e)); return false; }
    showOutbound();
    /* Never stuck "Connecting": 20 seconds without the call reaching KiddieTrac and it is
       ended, said plainly, and the reason recorded. */
    clearTimeout(watchdog);
    var wid = c.id;
    watchdog = setTimeout(function () {
      if (!expecting || expecting.id !== wid) return;
      fetch(apiBase() + '/voice/dialer/calls/' + wid, { headers: agencyHeader() }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
        if (!expecting || expecting.id !== wid) return;
        if (j && j.call && j.call.status !== 'dialing') return;
        try { if (call) call.hangup(); } catch (e) {}
        notConnected(wid, 'no answer from the phone service within 20 s' + (lastErr ? ' (' + lastErr + ')' : ''));
        showEnded('Couldn’t connect');
      }).catch(function () {});
    }, 20000);
    return true;
  }
  function causeOf(c) {
    try { return [c.cause, c.causeCode, c.sipCode, c.sipReason].filter(function (x) { return x != null && x !== ''; }).join(' / ') || lastErr || 'ended before connecting'; } catch (e) { return lastErr || 'unknown'; }
  }
  function notConnected(id, why) {
    fetch(apiBase() + '/voice/dialer/calls/' + id + '/hangup', { method: 'POST', keepalive: true,
      headers: Object.assign({ 'Content-Type': 'application/json' }, agencyHeader()),
      body: JSON.stringify({ reason: 'not-connected', cause: String(why || '').slice(0, 200) }) }).catch(function () {});
  }
  // Closing or reloading the page mid-call ends it properly instead of leaving it open.
  window.addEventListener('pagehide', function () {
    if (!expecting) return;
    try { if (call) call.hangup(); } catch (e) {}
    try {
      fetch(apiBase() + '/voice/dialer/calls/' + expecting.id + '/hangup', { method: 'POST', keepalive: true,
        headers: Object.assign({ 'Content-Type': 'application/json' }, agencyHeader()), body: JSON.stringify({ reason: 'page-closed' }) });
    } catch (e) {}
  });

  function endCall() {
    stopRing(); clearInterval(timer); clearInterval(outPoll); lastCaller = null;
    audioEnd();
    if (card) { card.remove(); card = null; }
    call = null; expecting = null;
    try { window.dispatchEvent(new CustomEvent('kt:phone-call-ended')); } catch (e) {}
  }

  /* A simple two-tone ring (Web Audio), until answered, declined or taken elsewhere. */
  function ring() {
    stopRing();
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return;
      var ctx = new Ctx(), on = true;
      var beep = function () {
        if (!on) return;
        [0, 0.45].forEach(function (off) {
          var o = ctx.createOscillator(), g = ctx.createGain();
          o.frequency.value = 440; o.connect(g); g.connect(ctx.destination);
          var t = ctx.currentTime + off; g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.25, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.38);
          o.start(t); o.stop(t + 0.4);
        });
      };
      /* Each ring also vibrates (2026-10-30, "notifications on the phone should also use vibrate"), unless this
         device's Vibrate is off. */
      var vib = function () { if (on && window.KT && KT.sounds && KT.sounds.buzz) KT.sounds.buzz([400, 200, 400]); };
      beep(); vib(); var iv = setInterval(function () { beep(); vib(); }, 2500);
      ringer = function () { on = false; clearInterval(iv); try { ctx.close(); } catch (e) {} try { if (navigator.vibrate) navigator.vibrate(0); } catch (e) {} };
    } catch (e) {}
  }
  function stopRing() { if (ringer) { ringer(); ringer = null; } }

  /* ANSWERED FROM THE PHONE'S RINGING NOTIFICATION (2026-10-23). The app was closed; a
     department call rang it (PhoneMenuController::ringMobiles) and the tap opened
     #phone?answer=<token>. Bring the phone up, then ask the server to put the held caller
     through to it; that call is answered the moment it arrives. */
  var pickup = null, pickingUp = false;
  function pickupCard(words, sub) {
    css();
    if (!card) { card = document.createElement('div'); card.id = 'kt-pp'; card.setAttribute('role', 'dialog'); card.setAttribute('data-kt-no-close', '1'); document.body.appendChild(card); }
    card.className = 'ringing';
    card.innerHTML = '<div class="pp-top">📞 ' + esc(words) + '</div>' + ava('') + '<div class="pp-who">' + esc(sub || 'KiddieTrac line') + '</div>'
      + '<div class="pp-btns">' + btn('pp-mute', '✕', 'Close') + '</div>';
    card.querySelector('.pp-mute').onclick = function () { if (card) { card.remove(); card = null; } };
  }
  function answerFromPush(tok) {
    if (pickingUp || !tok) return;
    pickingUp = true;
    pickupCard('Connecting the call…', 'Getting your phone ready');
    window.KT.portalPhone.whenReady().then(function (ok) {
      if (!ok) { pickingUp = false; pickupCard('Couldn’t connect', 'The phone could not start here. The caller can leave a voicemail.'); return; }
      pickup = { at: Date.now() };
      return fetch(apiBase() + '/voice/phone/answer', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, agencyHeader()), body: JSON.stringify({ token: tok }) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) {
          pickingUp = false;
          if (!x.ok) { pickup = null; pickupCard(x.j.reason === 'taken' ? 'Someone else answered' : 'The call has ended', x.j.message || ''); return; }
          pickupCard('Connecting the call…', [x.j.caller, x.j.department].filter(Boolean).join(' · '));
        });
    }).catch(function () { pickingUp = false; pickup = null; pickupCard('Couldn’t connect', ''); });
  }
  function answerFromHash() {
    var m = /[?&]answer=([A-Za-z0-9]{10,64})/.exec(location.hash || '');
    if (!m) return;
    try { history.replaceState(null, '', location.pathname + location.search + '#phone'); } catch (e) {}
    answerFromPush(m[1]);
  }
  window.addEventListener('hashchange', answerFromHash);
  setTimeout(answerFromHash, 1500);

  // After the portal has started; again if someone signs in later in this tab.
  setTimeout(start, 4000);
  window.addEventListener('storage', function (e) { if (e.key === 'kt_token' && !client) setTimeout(start, 2000); });
  window.KT = window.KT || {};
  /* state(): is this portal ready to ring? (the Call menu page and tests read it) */
  window.KT.portalPhone = {
    restart: start,
    state: function () { return { ready: ready, ringing: !!(call && card && card.className === 'ringing'), departments: depts.slice(), dial: dial, busy: !!(call || expecting) }; },
    /* The dialer: the call the server is about to ring this phone with. */
    expect: function (c) { expecting = { id: c.id, name: c.name || '', pretty: c.to_pretty || c.to, number: c.to, at: Date.now() }; showOutbound(); },
    dialOut: dialOut,
    ensureMic: ensureMic,
    answerFromPush: function (tok) { answerFromPush(tok); },
    cancelExpect: function () { if (expecting) hangupOut(); },
    /* Resolves true once the phone is registered (starting it if needed), false after 10 s. */
    whenReady: function () {
      if (ready) return Promise.resolve(true);
      if (!client) start();
      return new Promise(function (ok) { var n = 0; var iv = setInterval(function () { if (ready || ++n > 40) { clearInterval(iv); ok(ready); } }, 250); });
    },
  };
})(window);
