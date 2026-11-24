/* ═══════════════════════════════════════════════════════════════════════════
   KiddieTrac — inside the website's DEMO PHONE (2026-10-31).

   kiddietrac.com/demo-phone.html draws an iPhone or an Android phone and boots this
   portal inside it (an iframe: index.html?kt_frame=ios|android). This file is the
   portal's half of that, and it does NOTHING unless all of these hold:
     - the page is framed (window.top !== window), and
     - the framing page is https://www.kiddietrac.com or https://kiddietrac.com
       (location.ancestorOrigins where the browser has it, else the referrer on the
       first load, remembered for this tab), and
     - the first load carried the ?kt_frame hint.
   Not framed: the hint is ignored and anything remembered is dropped.

   When active:
     - the app is laid out as the phone app is (html.kt-app/kt-native, the safe-area
       insets of the phone drawn around it: --kt-safe-top/--kt-safe-bottom);
     - the phone may ask, by postMessage from that exact origin only:
         (kt-demo-fill was removed 2026-10-30: the site no longer publishes logins -
                        visitors get a private demo login from sales - and no password
                        lives in this file any more.)
         kt-demo-home   the home indicator was swiped: sign out and say so.
         kt-demo-open   a notification banner was tapped: go to its #screen.
         kt-demo-ringer / kt-demo-volume   the phone's mute switch / volume rocker.
         kt-demo-call   {id, action: answer|decline|missed|key|hangup, digit} - the phone's
                        incoming-call screen; the answer goes to /demo/phone/calls/{id} and the
                        words the call says back come back as kt-demo-call-reply.
         kt-demo-texts  read the Messages thread (-> kt-demo-texts back);
         kt-demo-text   {body} a reply typed in the phone's Messages;
         kt-demo-try    {what: safe_arrival|text} a scripted moment (-> kt-demo-try-result).
     - and, ONLY for a signed-in demo person (one of the three addresses):
         kt-notify      every new in-app notification the app's own poll finds
                        (kt-sounds.js raises kt:notification) goes to the phone,
                        which shows it as a banner; the app plays its own sound;
         /demo/pulse    a few scripted, fictional notifications in the first
                        minutes (server side: App\Http\Controllers\Api\WebDemoController);
         calls + texts  /demo/phone/events every few seconds: a Safe Arrival call or a text
                        to this demo person (App\Support\WebDemoPhone - never a carrier) goes
                        to the phone as kt-demo-event, which rings or shows it in Messages;
         GPS            the real location (the iframe is allowed geolocation); if
                        it is refused or missing, "Simulate a walk" feeds a moving
                        route around the fictional demo centre, labelled.
   Nothing here sends anything anywhere but this phone and our own API.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var ALLOWED = ['https://www.kiddietrac.com', 'https://kiddietrac.com'];
  /* The demo people (App\Support\PublicDemo::USERS / ACCOUNTS): who gets the phone's banners, calls, texts and GPS.
     By account id (2026-10-30: new @brightwood.kiddietrac.com addresses; ids never change with an address). */
  var DEMO = { 1214: 'parent', 1199: 'educator', 1198: 'director',
    /* The white-label demo (2026-10-30): Starlight Kids Academy, PublicDemo::ACCOUNTS_WL - the partner's own app. */
    1259: 'parent', 1244: 'educator', 1243: 'director',
    /* The agency admins (2026-10-30, the website's Demo experience page shows every role): PublicDemo::ACCOUNTS[_WL]['admin']. */
    1287: 'admin', 1288: 'admin' };
  var KEY = 'kt_demo_frame';
  /* THE PARENT'S PHONE (2026-10-31): the website's Demo experience page puts the matching demo parent's phone beside a
     staff phone. That phone's app frame is named kt-ns-companion (index.html/dashboard.html give it its own storage). */
  var COMPANION = w.name === 'kt-ns-companion';
  var S = null; try { S = w.sessionStorage; } catch (e) {}
  function sget(k) { try { return S.getItem(k); } catch (e) { return null; } }
  function sset(k, v) { try { S.setItem(k, v); } catch (e) {} }
  function sdel(k) { try { S.removeItem(k); } catch (e) {} }

  var framed = true;
  try { framed = w.top !== w.self; } catch (e) { framed = true; }
  if (!framed) { sdel(KEY); return; }

  function allowed(o) { return ALLOWED.indexOf(String(o || '')) !== -1; }
  /* Who framed us, if the browser can say. Every ancestor must be ours. */
  function ancestors() {
    try {
      var ao = w.location.ancestorOrigins;
      if (!ao || !ao.length) return null;
      for (var i = 0; i < ao.length; i++) { if (!allowed(ao[i])) return false; }
      return ao[0];
    } catch (e) { return null; }
  }
  var anc = ancestors();
  if (anc === false) { sdel(KEY); return; }

  var st = null;
  try { st = JSON.parse(sget(KEY) || 'null'); } catch (e) { st = null; }
  var hint = (/[?&]kt_frame=(ios|android)(-bare)?(?:&|$)/.exec(w.location.search || '') || []);
  if (hint[1]) {
    var origin = anc;
    if (!origin) { try { origin = new URL(d.referrer).origin; } catch (e) { origin = null; } }
    if (!allowed(origin)) { sdel(KEY); return; }
    /* WHICH APP WAS TAPPED (2026-10-30): KiddieTrac (no ?tenant) or a partner's white-label app (?tenant=<slug>, as its own
       build opens). The tab's kt_tenant follows the tap, so a KiddieTrac launch after a white-label one is KiddieTrac again. */
    var tq = ((/[?&]tenant=([a-z0-9-]{1,40})(?:&|$)/i.exec(w.location.search || '') || [])[1] || '').toLowerCase();
    if (tq) sset('kt_tenant', tq); else sdel('kt_tenant');
    st = { kind: hint[1], bare: !!hint[2], origin: origin, tenant: tq || null };
    sset(KEY, JSON.stringify(st));
  }
  if (!st || !allowed(st.origin) || (anc && anc !== st.origin)) { sdel(KEY); return; }
  /* Every launch from the phone (the ?kt_frame load) boots like a freshly opened app: whatever session this tab's demo
     phone had before is signed out first, so the visitor always meets the real sign-in page. */
  var launchTok = hint[1] ? sget('kt_token') : null;
  if (hint[1]) {
    /* A COLD START (2026-10-30, Anthony: "where is the boot up experience with the animation"): a real launch is a new
       web view, so the portal's launch scene (kt-launch-scene.js, once per session) plays every time - not just on the
       first launch in this browser tab. */
    if (/[?&]kt_swap=1(?:&|$)/.test(w.location.search || '') && COMPANION) { sdel('kt_away_at'); sdel('kt_boot_scene'); sset('kt_launch_seen', '1'); }
    else ['kt_launch_seen', 'kt_away_at', 'kt_boot_scene'].forEach(sdel);
    ['kt_token', 'kt_user', 'kt_login_at', 'kt_last_activity', 'kt_locked', 'kt_view_as', 'kt_force_password_change', 'kt_force_password_reason'].forEach(sdel);
    if (launchTok) { try { fetch(((w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1') + '/auth/logout', { method: 'POST', keepalive: true, headers: { 'Authorization': 'Bearer ' + launchTok, 'Accept': 'application/json' } }).catch(function () {}); } catch (e) {} }
  }

  /* NO SIGN-IN OUTLIVES THE APP (2026-10-30). The portal mirrors a session into localStorage (app.js rememberSession) so a
     phone app survives a WebView kill; inside the demo phone that meant the last demo person's sign-in (and their agency's
     brand - a white-label Starlight session) came back on the next launch, KiddieTrac's included. kt_no_persist keeps
     every demo session in this tab only, and a launch drops a DEMO person's mirrored sign-in left from before. Only a demo
     person's: the frame's storage is partitioned under kiddietrac.com, but nothing real is ever touched. */
  sset('kt_no_persist', '1');
  if (hint[1]) {
    try {
      var L = w.localStorage, lu = JSON.parse(L.getItem('kt_user') || 'null'), luid = lu ? Number(lu.id || (lu.user && lu.user.id) || 0) : 0;
      if (luid && Object.prototype.hasOwnProperty.call(DEMO, luid)) {
        var lt = L.getItem('kt_token');
        ['kt_token', 'kt_user', 'kt_login_at'].forEach(function (k) { L.removeItem(k); });
        if (lt && lt !== launchTok) fetch(((w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1') + '/auth/logout', { method: 'POST', keepalive: true, headers: { 'Authorization': 'Bearer ' + lt, 'Accept': 'application/json' } }).catch(function () {});
      }
    } catch (e) {}
  }
  w.__KT_DEMO_FRAME = st.kind;
  /* Not the visitor's own fingerprint / PIN lock: whatever this browser has enrolled for a REAL account must not come up
     inside the demo phone. Marked unlocked for this tab only (the iframe's own session storage). */
  sset('kt_bio_session', '1');
  var root = d.documentElement;
  var onLogin = !/dashboard\.html/i.test(w.location.pathname || '');

  // ── The phone's shape: app classes + the insets the real app has ─────────
  var top = st.bare ? 0 : (st.kind === 'ios' ? 59 : 32);
  /* Bare = the demo phone full screen on a real phone (no drawn bezel). The demo still draws its home indicator /
     gesture bar over the bottom of the app (it is the swipe-up-to-close control), so the app keeps room under its bottom
     bar for it, as the real app does above the system bar - with 0 here the bar sat over the bottom-bar labels
     (2026-10-31, Anthony: "the app is slightly off the display"). iOS bar: 5px at 9px up; Android: 4px at 7px up. */
  var bottom = st.bare ? (st.kind === 'ios' ? 22 : 18) : (st.kind === 'ios' ? 34 : 20);
  function shape() {
    root.classList.add('kt-demo-frame', 'kt-demo-' + st.kind, 'kt-app', 'kt-native');
    if (st.kind === 'ios' && !st.bare) root.classList.add('kt-ios-inset-fallback');
    root.style.setProperty('--kt-safe-top', top + 'px');
    root.style.setProperty('--kt-safe-bottom', bottom + 'px');
    /* The drawn screen's corner radius (demo-phone.html .ios/.android .screen), so the clock glow curves with it. Full screen
       on a real phone (bare) draws no corners of its own: the glow keeps its plain frame there. */
    if (!st.bare) root.style.setProperty('--kt-screen-radius', (st.kind === 'ios' ? 52 : 36) + 'px');
  }
  shape();
  // The portal re-decides kt-app/kt-native on resize (no Capacitor here): keep the phone's answer.
  try { new MutationObserver(function () { if (!root.classList.contains('kt-native') || !root.classList.contains('kt-app')) shape(); }).observe(root, { attributes: true, attributeFilter: ['class'] }); } catch (e) {}
  /* A phone has overlay scrollbars: no reserved gutter (html is scrollbar-gutter:stable for desktop browsers, which left
     a 15px empty column down the right of the phone) and no visible bars. */
  try {
    var css = d.createElement('style'); css.id = 'kt-demo-frame-css';
    css.textContent = 'html.kt-demo-frame{scrollbar-gutter:auto!important;scrollbar-width:none!important}html.kt-demo-frame *{scrollbar-width:none!important}html.kt-demo-frame ::-webkit-scrollbar{width:0!important;height:0!important}';
    (d.head || root).appendChild(css);
  } catch (e) {}
  if (!st.bare) {
    // The navy band under the phone's clock, as the app paints it (its own #kt-statusfill is for the native shell only).
    var fill = function () {
      if (d.getElementById('kt-demo-statusfill') || !d.body) return;
      var f = d.createElement('div'); f.id = 'kt-demo-statusfill';
      f.style.cssText = 'position:fixed;top:0;left:0;right:0;height:var(--kt-safe-top,0px);background:' + band + ';z-index:2147483600;pointer-events:none;transition:background .3s';
      root.appendChild(f);
    };
    /* The band wears the app's brand: KiddieTrac navy, or the white-label partner's own colour (its app paints its own). */
    var band = '#081C41';
    try {
      var cb = JSON.parse(w.localStorage.getItem('kt_branding') || 'null');
      if (st.tenant && cb && cb.theme && cb.theme.white_label && (cb.agency_slug === st.tenant || cb.reseller_slug === st.tenant)) band = cb.theme.hero_from || cb.theme.primary_color || band;
    } catch (e) {}
    w.addEventListener('kt:brand', function (ev) {
      var t = ev && ev.detail;
      band = t && t.white_label ? (t.hero_from || t.primary_color || '#081C41') : '#081C41';
      var f = d.getElementById('kt-demo-statusfill'); if (f) f.style.background = band;
      post({ type: 'kt-demo-brand', white_label: !!(t && t.white_label), name: t && t.white_label ? String(t.name || '') : 'KiddieTrac', color: band });
    });
    if (d.body) fill(); else d.addEventListener('DOMContentLoaded', fill);
  }

  function post(msg) { try { w.parent.postMessage(msg, st.origin); } catch (e) {} }
  function apiBase() { return (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function token() { return sget('kt_token'); }
  function me() { try { return JSON.parse(sget('kt_user') || 'null') || null; } catch (e) { return null; } }
  function demoId(u) { return u ? Number(u.id || (u.user && u.user.id) || 0) : 0; }
  /* The parent phone follows the child (2026-10-31): it may be signed in as ANY demo agency's parent, not only the
     published one - the server says so (/auth/me is_public_demo, App\Support\PublicDemo::isUser) and only ever issues
     the parent phone a parent's sign-in. Everywhere else it is the fixed list above. */
  function pubDemo(u) { return !!(u && (u.is_public_demo === true || (u.user && u.user.is_public_demo === true))); }
  function demoPerson() { var u = me(); return Object.prototype.hasOwnProperty.call(DEMO, demoId(u)) || (COMPANION && pubDemo(u)) ? u : null; }
  function demoRole() { var u = demoPerson(); return u ? (DEMO[demoId(u)] || 'parent') : null; }
  function api(method, path, body) {
    var t = token(); if (!t) return Promise.reject(new Error('signed out'));
    var h = { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' };
    if (body) h['Content-Type'] = 'application/json';
    return fetch(apiBase() + path, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); });
  }

  // ── Messages from the phone: exact origin, from our parent window only ─────
  function signOut() {
    var t = token();
    var done = function () {
      ['kt_token', 'kt_user', 'kt_login_at', 'kt_last_activity', 'kt_pk_offer', 'kt_force_password_change', 'kt_force_password_reason'].forEach(sdel);
      post({ type: 'kt-demo-signed-out' });
    };
    if (!t) { done(); return; }
    try {
      fetch(apiBase() + '/auth/logout', { method: 'POST', keepalive: true, headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
        .then(done, done);
    } catch (e) { done(); }
  }
  w.addEventListener('message', function (e) {
    if (e.source !== w.parent || e.origin !== st.origin) return;
    var m = e.data; if (!m || typeof m !== 'object' || typeof m.type !== 'string') return;
    if (m.type === 'kt-demo-home') {
      signOut();
    } else if (m.type === 'kt-demo-open') {
      var link = String(m.link || '');
      if (/^#[A-Za-z0-9_\-\/=&?.]{1,80}$/.test(link)) { if (w.location.hash === link) w.dispatchEvent(new HashChangeEvent('hashchange')); else w.location.hash = link; }
    } else if (m.type === 'kt-demo-hold-release') {
      var hr = holds[m.id]; if (hr) { delete holds[m.id]; hr(); }
    } else if (m.type === 'kt-demo-duo') {
      DUO = !!m.on;
    } else if (m.type === 'kt-demo-ringer') {
      sset('kt_demo_mute', m.on ? '0' : '1');
    } else if (m.type === 'kt-demo-volume') {
      var v = parseInt(m.value, 10); if (isFinite(v)) sset('kt_demo_vol', String(Math.max(0, Math.min(100, v))));
    } else if (!demoPerson()) {
      return;
    } else if (m.type === 'kt-demo-call') {
      var cid = parseInt(m.id, 10), act = String(m.action || '');
      if (!cid || !/^(answer|decline|missed|key|hangup)$/.test(act)) return;
      api('POST', '/demo/phone/calls/' + cid, { action: act, digit: act === 'key' ? String(m.digit || '').slice(0, 1) : null })
        .then(function (j) { post({ type: 'kt-demo-call-reply', id: cid, action: act, reply: j.reply || null }); poll(); },
          function () { post({ type: 'kt-demo-call-reply', id: cid, action: act, reply: null, error: true }); });
    } else if (m.type === 'kt-demo-etransfer-pay') {
      // The demo bank accepted an e-Transfer request: pay it through the demo's simulated payment (EtransferRequestController::demoPay).
      var rid = parseInt(m.id, 10); if (!rid) return;
      api('POST', '/parent/etransfer-requests/' + rid + '/demo-pay', {}).then(function (j) {
        post({ type: 'kt-demo-etransfer-reply', id: rid, ok: true, reference: (j.receipt && j.receipt.reference) || '' });
        if (w.location.hash === '#billing') w.dispatchEvent(new HashChangeEvent('hashchange'));
      }, function (e) { post({ type: 'kt-demo-etransfer-reply', id: rid, ok: false, message: (e && e.message) || '' }); });
    } else if (m.type === 'kt-demo-texts') {
      api('GET', '/demo/phone/texts').then(function (j) { post({ type: 'kt-demo-texts', from: j.from, number: j.number, messages: j.messages || [] }); }, function () {});
    } else if (m.type === 'kt-demo-text') {
      var b = String(m.body || '').trim().slice(0, 480); if (!b) return;
      api('POST', '/demo/phone/texts', { body: b }).then(function () {
        api('GET', '/demo/phone/texts').then(function (j) { post({ type: 'kt-demo-texts', from: j.from, number: j.number, messages: j.messages || [] }); }, function () {});
      }, function (e) { post({ type: 'kt-demo-try-result', ok: false, message: e.message }); });
    } else if (m.type === 'kt-demo-mail-open') {
      // The phone's Mail app (App\Support\WebDemoMail): one message of this demo person's own captured mail.
      var mid = parseInt(m.id, 10); if (!mid) return;
      api('GET', '/demo/mail/' + mid).then(function (j) {
        post({ type: 'kt-demo-mail-msg', id: mid, from: j.from || '', from_email: j.from_email || '', subject: j.subject || '', html: j.html || null, text: j.text || '', files: j.files || [], at: j.at || null });
        mailPoll();
      }, function () { post({ type: 'kt-demo-mail-msg', id: mid, error: true }); });
    } else if (m.type === 'kt-demo-mail-file') {
      var fid = parseInt(m.id, 10), fn = parseInt(m.n, 10); if (!fid || !(fn >= 0)) return;
      fetch(apiBase() + '/demo/mail/' + fid + '/files/' + fn, { headers: { 'Authorization': 'Bearer ' + token(), 'Accept': '*/*' } })
        .then(function (r) { if (!r.ok) throw new Error('x'); return r.blob(); })
        .then(function (b) { post({ type: 'kt-demo-mail-file', id: fid, n: fn, name: String(m.name || ''), blob: b }); },
          function () { post({ type: 'kt-demo-mail-file', id: fid, n: fn, error: true }); });
    } else if (m.type === 'kt-demo-companion-req') {
      if (COMPANION || !/^(educator|director|admin)$/.test(String(demoRole() || ''))) return;
      var want = {};
      ['child_id', 'family_id', 'invoice_id'].forEach(function (k) { var v = parseInt(m[k], 10); if (v > 0) want[k] = v; });
      api('POST', '/demo/companion', want).then(function (j) {
        if (j && j.token) post({ type: 'kt-demo-companion', token: String(j.token), brand: j.brand === 'wl' ? 'wl' : 'kt', name: String(j.name || ''), expires_at: j.expires_at || null,
          family_id: j.family_id || null, kids: j.kids || [], roster: j.roster || [] });
      }, function () { post({ type: 'kt-demo-companion', token: null }); });
    } else if (m.type === 'kt-demo-try') {
      var what = m.what === 'text' ? 'text' : 'safe_arrival';
      api('POST', '/demo/phone/try', { what: what, child_id: parseInt(m.child_id, 10) || null }).then(function (j) { post({ type: 'kt-demo-try-result', ok: true, what: what, message: j.message || '' }); setTimeout(poll, 800); },
        function (e) { post({ type: 'kt-demo-try-result', ok: false, what: what, message: e.message }); });
    }
  });

  /* THE PARENT PHONE FOLLOWS THE CHILD (2026-10-31, Anthony: "if I log any child off it should show up on the parent
     demo"). On the Demo experience page (DUO, told by the phone) a staff action that names a child, a family or an
     invoice waits a moment before it goes to the server: the page first switches the parent phone to THAT family's
     parent, so the in-app, email and text notifications the action causes land on the phone the visitor is watching. */
  var DUO = false, holds = {}, holdN = 0;
  function holdFor(info) {
    return new Promise(function (res) {
      var id = ++holdN; holds[id] = res;
      post({ type: 'kt-demo-hold', id: id, child_id: info.child_id || null, family_id: info.family_id || null, invoice_id: info.invoice_id || null });
      setTimeout(function () { if (holds[id]) { var r = holds[id]; delete holds[id]; r(); } }, 14000);
    });
  }
  function targetOf(url, body) {
    var mi = /\/admin\/invoices\/native\/(\d+)\/email/.exec(url); if (mi) return { invoice_id: +mi[1] };
    /* Accounting's invoice menu "Send e-Transfer request" (2026-11-02, the e-Transfer requests screen is gone): the bank's
       request goes to that invoice's family, so their parent's phone comes up first (a synced "ext" invoice: no switch). */
    var me2 = /\/billing\/etransfer\/kt\/(\d+)\/requested/.exec(url); if (me2) return { invoice_id: +me2[1] };
    var o = null;
    try {
      if (typeof body === 'string' && body.charAt(0) === '{') o = JSON.parse(body);
      else if (typeof FormData !== 'undefined' && body instanceof FormData) o = { child_id: body.get('child_id'), family_id: body.get('family_id') };
    } catch (e) { o = null; }
    if (!o) return null;
    var c = parseInt(o.child_id, 10) || (Array.isArray(o.child_ids) && o.child_ids.length ?parseInt(o.child_ids[0], 10) : 0);
    if (c > 0) return { child_id: c };
    var f = parseInt(o.family_id, 10); if (f > 0) return { family_id: f };
    return null;
  }
  if (!COMPANION) {
    var ofetch = w.fetch;
    w.fetch = function (input, init) {
      try {
        if (DUO && /^(educator|director|admin)$/.test(String(demoRole() || ''))) {
          var url = typeof input === 'string' ? input : ((input && input.url) || '');
          var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
          if (method !== 'GET' && /\/api\//.test(url) && !/\/(demo|auth|badges|notifications|pulse|diag)\b/.test(url)) {
            var info = targetOf(url, init && init.body);
            if (info) { var a = arguments, t = this; return holdFor(info).then(function () { return ofetch.apply(t, a); }); }
          }
        }
      } catch (e) {}
      return ofetch.apply(this, arguments);
    };
  }

  function ready() {
    var u = demoPerson();
    post({ type: 'kt-demo-ready', page: onLogin ? 'login' : 'app', demo: !!u, role: demoRole(), name: u ? (u.first_name || '') : '' });
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', ready); else ready();

  /* THE GROUND BETWEEN PAGES (2026-11-02, as the iOS app 3.12.12 KtGround does). Between one page leaving (the sign-in
     fading into the dashboard, a sign-out) and the next page's first paint the phone showed the app frame's own ground,
     always launch navy - after a Starlight sign-in a navy flash the real app no longer has. Just before the page leaves,
     the colour of the cover that is up (the same four covers the app reads) goes to the phone, which paints the frame
     with it. KiddieTrac's gradient gives its last stop, #0A1C3A, as before; no cover up = nothing sent = no change. */
  (function () {
    function hex(c) {
      c = String(c || '').trim();
      if (/^#[0-9a-f]{6}$/i.test(c)) return c.toLowerCase();
      var m = /^rgba?\(\s*(\d+(?:\.\d+)?)[,\s]+(\d+(?:\.\d+)?)[,\s]+(\d+(?:\.\d+)?)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/i.exec(c);
      if (!m) return null;
      if (m[4] !== undefined) { var a = parseFloat(m[4]); if (/%$/.test(m[4])) a /= 100; if (!(a >= 0.99)) return null; }
      return '#' + [m[1], m[2], m[3]].map(function (x) { x = Math.max(0, Math.min(255, Math.round(+x))); return (x < 16 ? '0' : '') + x.toString(16); }).join('');
    }
    function lastStop(img) {
      var all = String(img || '').match(/rgba?\([^)]*\)|#[0-9a-f]{6}\b/gi);
      if (!all) return null;
      for (var i = all.length - 1; i >= 0; i--) { var h = hex(all[i]); if (h) return h; }
      return null;
    }
    function ground() {
      var sel = ['#kt-portal-fade', '#kt-out-fade', '#kt-reload-gate', '#kt-launch .ktl-bg'];
      for (var i = 0; i < sel.length; i++) {
        var el = d.querySelector(sel[i]); if (!el) continue;
        var cs = w.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.5) continue;
        var g = hex(cs.backgroundColor) || lastStop(cs.backgroundImage);
        if (g) return g;
      }
      return null;
    }
    function leave() { try { var g = ground(); if (g) post({ type: 'kt-demo-ground', color: g }); } catch (e) {} }
    w.addEventListener('beforeunload', leave);
    w.addEventListener('pagehide', leave);
  })();

  /* No "Sign in faster next time? Add a passkey" for a demo person (2026-10-30): passkeys are switched off for the demo
     accounts (PublicDemoGuard), so the offer could only fail - and it covered the phone after every demo sign-in. */
  if (!onLogin && demoPerson()) sdel('kt_pk_offer');
  /* VIBRATION (2026-10-30). kt-sounds.js vibrates every in-app notification through navigator.vibrate (the pattern the
     person chose per category). Inside the demo phone there is no motor - and an iPhone visitor's browser has no
     navigator.vibrate at all - so the pattern goes to the drawn phone, which shakes with it (on silent too, as a phone
     does). Returns true like the real API. */
  try {
    var vib = function (p) {
      var a = Array.isArray(p) ? p : [p];
      a = a.map(function (x) { return Math.max(0, Math.min(5000, Number(x) || 0)); }).slice(0, 24);
      if (a.length && a.some(function (x) { return x > 0; })) post({ type: 'kt-demo-vibrate', pattern: a });
      return true;
    };
    Object.defineProperty(w.navigator, 'vibrate', { value: vib, configurable: true, writable: true });
  } catch (e) {}
  if (onLogin || !demoPerson()) return;

  // ── A demo person, signed in: notifications to the phone ──────────────────
  w.addEventListener('kt:notification', function (ev) {
    var n = ev && ev.detail; if (!n || !demoPerson()) return;
    var data = n.data; try { if (typeof data === 'string') data = JSON.parse(data); } catch (e) { data = null; }
    post({ type: 'kt-notify', id: n.id, title: String(n.title || '').slice(0, 120), body: String(n.body || '').slice(0, 300),
      link: (data && data.link) || '#dashboard', cat: ev.detail.cat || 'general' });
  });

  // A few fictional moments in the first minutes (server writes one demo notification at a time).
  var pulses = 0, started = Date.now();
  function lookNow() {
    var t = token(); if (!t) return;
    w.fetch(apiBase() + '/notifications', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } }).catch(function () {});
  }
  function pulse() {
    var t = token(); if (!t || !demoPerson() || ++pulses > 40 || Date.now() - started > 9 * 60000) return;
    fetch(apiBase() + '/demo/pulse', { method: 'POST', headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        if (j.created) lookNow();
        if (!j.done) setTimeout(pulse, Math.max(4000, Math.min(15000, ((j.next_in || 10) + 1) * 1000)));
      }).catch(function () { setTimeout(pulse, 15000); });
  }
  setTimeout(lookNow, 4000);      // the baseline: what is already there is not "new"
  if (!COMPANION) setTimeout(pulse, 9000);
  /* The parent's phone beside a staff phone: what the staff member just did should reach it within a couple of seconds
     (a check-in, a moment, a message, an announcement). The badges call is one small request; only this phone does it. */
  /* Measured 2026-10-31: the parent phone's in-app banner came 8-10 s after the email and the text, because kt-live.js
     hands every count-shaped GET (/badges, /notifications) the same response for 8 s - so this phone's 2.5 s look
     mostly got an old answer. On this phone only, those two reads are always fresh: an outermost fetch wrapper (put on
     at the first tick, after every other script has wrapped fetch) gives each one its own address. */
  function freshReads() {
    if (w.__ktCompFresh) return; w.__ktCompFresh = true;
    var f0 = w.fetch, n = 0;
    w.fetch = function (input, init) {
      try {
        var m = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        if (m === 'GET' && typeof input === 'string' && /\/api\/v1\/(badges|notifications)(\?|$)/.test(input))
          input = input + (input.indexOf('?') < 0 ? '?' : '&') + 'kt_f=' + (++n % 100000);
      } catch (e) {}
      return f0.call(this, input, init);
    };
  }
  if (COMPANION) setInterval(function () { try { freshReads(); if (!d.hidden && w.KT && w.KT.badges && w.KT.badges.refresh) w.KT.badges.refresh(); } catch (e) {} }, 2500);

  // ── Calls and texts to this demo person (App\Support\WebDemoPhone) ───────
  var evLast = null, evTimer = null, evBusy = false;
  function poll() {
    if (evBusy || !token() || !demoPerson()) return;
    evBusy = true;
    api('GET', '/demo/phone/events' + (evLast === null ? '' : '?after=' + evLast)).then(function (j) {
      evBusy = false;
      evLast = Math.max(evLast || 0, Number(j.last) || 0);
      (j.events || []).forEach(function (e) { post({ type: 'kt-demo-event', ev: e }); });
    }, function () { evBusy = false; });
  }
  function loop() { poll(); evTimer = setTimeout(loop, d.hidden ? 15000 : (COMPANION ? 2000 : 3500)); }
  setTimeout(loop, 1500);

  // ── The phone's Mail app: the email this demo person would have received (App\Support\WebDemoMail) ──
  var mailLast = null, mailBusy = false;
  function mailPoll() {
    if (mailBusy || !token() || !demoPerson()) return;
    mailBusy = true;
    api('GET', '/demo/mail').then(function (j) {
      mailBusy = false;
      var items = j.items || [];
      var fresh = mailLast === null ? [] : items.filter(function (x) { return x.id > mailLast; }).map(function (x) { return x.id; });
      mailLast = Math.max(mailLast || 0, Number(j.last) || 0);
      post({ type: 'kt-demo-mail', items: items, unread: j.unread || 0, fresh: fresh });
    }, function () { mailBusy = false; });
  }
  function mailLoop() { mailPoll(); setTimeout(mailLoop, d.hidden ? 15000 : (COMPANION ? 2500 : 5000)); }
  setTimeout(mailLoop, 2000);

  // ── GPS: the real thing, or a clearly-labelled simulated walk ─────────────
  var geo = navigator.geolocation;
  if (!geo) return;
  var realGet = geo.getCurrentPosition.bind(geo), realWatch = geo.watchPosition.bind(geo), realClear = geo.clearWatch.bind(geo);
  /* A loop through the streets beside the fictional Brightwood centre (Long Beach, CA), about 2 km. */
  var ROUTE = [[33.76705, -118.16410], [33.76655, -118.16180], [33.76600, -118.15930], [33.76545, -118.15680], [33.76490, -118.15430],
    [33.76432, -118.15180], [33.76560, -118.15140], [33.76700, -118.15100], [33.76760, -118.15350], [33.76815, -118.15600],
    [33.76870, -118.15850], [33.76925, -118.16100], [33.76980, -118.16350], [33.76840, -118.16380]];
  var sim = { on: false, timer: null, pos: 0, watches: {}, nextId: 900001, asked: false, pill: null };
  function lerp(a, b, t) { return a + (b - a) * t; }
  function dist(a, b) { var dy = (b[0] - a[0]) * 111320, dx = (b[1] - a[1]) * 111320 * Math.cos(a[0] * Math.PI / 180); return Math.sqrt(dx * dx + dy * dy); }
  function simFix() {
    // 1.3 m/s, one fix every 2 s
    var segs = [], total = 0;
    for (var i = 0; i < ROUTE.length; i++) { var a = ROUTE[i], b = ROUTE[(i + 1) % ROUTE.length], L = dist(a, b); segs.push([a, b, L]); total += L; }
    var at = sim.pos % total, k = 0;
    while (k < segs.length - 1 && at > segs[k][2]) { at -= segs[k][2]; k++; }
    var sg = segs[k], t = sg[2] ? at / sg[2] : 0;
    var lat = lerp(sg[0][0], sg[1][0], t), lng = lerp(sg[0][1], sg[1][1], t);
    var heading = (Math.atan2((sg[1][1] - sg[0][1]) * Math.cos(lat * Math.PI / 180), sg[1][0] - sg[0][0]) * 180 / Math.PI + 360) % 360;
    return { coords: { latitude: lat, longitude: lng, accuracy: 6 + Math.random() * 6, altitude: null, altitudeAccuracy: null, heading: heading, speed: 1.3 }, timestamp: Date.now(), simulated: true };
  }
  function showPill() {
    if (sim.pill || !d.body) return;
    var p = d.createElement('div');
    p.id = 'kt-demo-simgps';
    p.textContent = '📍 Simulated GPS · demo route';
    p.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);top:calc(var(--kt-safe-top,0px) + 8px);z-index:2147483000;background:#7c3aed;color:#fff;font:700 12px/1 system-ui,-apple-system,sans-serif;padding:7px 12px;border-radius:999px;box-shadow:0 6px 16px rgba(0,0,0,.25);pointer-events:none';
    d.body.appendChild(p); sim.pill = p;
  }
  function startSim() {
    if (sim.on) return; sim.on = true; showPill();
    sim.timer = setInterval(function () {
      sim.pos += 2.6;
      var f = simFix();
      Object.keys(sim.watches).forEach(function (id) { if (typeof sim.watches[id] === 'function') { try { sim.watches[id](f); } catch (e) {} } });
    }, 2000);
  }
  function stopSimIfIdle() {
    if (Object.keys(sim.watches).some(function (k) { return typeof sim.watches[k] === 'function'; })) return;
    clearInterval(sim.timer); sim.on = false; if (sim.pill) { sim.pill.remove(); sim.pill = null; }
  }
  var waiting = [];
  function offerSim(err) {
    return new Promise(function (resolve) {
      waiting.push(resolve);
      if (waiting.length > 1) return;
      var box = d.createElement('div');
      box.style.cssText = 'position:fixed;inset:0;z-index:2147483001;background:rgba(8,20,40,.45);display:flex;align-items:flex-end;justify-content:center';
      var card = d.createElement('div');
      card.style.cssText = 'width:100%;max-width:440px;background:#fff;color:#0f172a;border-radius:18px 18px 0 0;padding:18px 18px calc(18px + var(--kt-safe-bottom,0px));font:15px/1.45 system-ui,-apple-system,sans-serif;box-shadow:0 -10px 30px rgba(0,0,0,.2)';
      var h = d.createElement('b'); h.textContent = err && err.code === 1 ? 'Location is off for this demo' : 'No GPS on this device';
      h.style.cssText = 'display:block;font-size:17px;margin-bottom:6px';
      var p = d.createElement('div'); p.textContent = 'Walk Watch needs a location. Simulate a walk around the demo centre instead? The map, pings and route all work, and the screen says it is simulated.';
      p.style.cssText = 'color:#475569;margin-bottom:14px';
      var row = d.createElement('div'); row.style.cssText = 'display:flex;gap:10px';
      var yes = d.createElement('button'); yes.type = 'button'; yes.textContent = 'Simulate a walk';
      yes.style.cssText = 'flex:1;height:44px;border:0;border-radius:12px;background:#0d9488;color:#fff;font-weight:800;font-size:15px';
      var no = d.createElement('button'); no.type = 'button'; no.textContent = 'Not now';
      no.style.cssText = 'flex:1;height:44px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#0f172a;font-weight:700;font-size:15px';
      function close(v) { box.remove(); var q = waiting; waiting = []; q.forEach(function (r) { r(v); }); }
      yes.onclick = function () { close(true); }; no.onclick = function () { close(false); };
      row.appendChild(yes); row.appendChild(no); card.appendChild(h); card.appendChild(p); card.appendChild(row); box.appendChild(card);
      (d.body || root).appendChild(box);
    });
  }
  geo.watchPosition = function (ok, fail, opts) {
    if (sim.on) { var sid = sim.nextId++; sim.watches[sid] = ok; setTimeout(function () { ok(simFix()); }, 300); return sid; }
    var id = sim.nextId++, realId = null, gotOne = false;
    var giveUp = function (err) {
      if (gotOne || sim.watches[id] === null) return;
      if (realId !== null) { try { realClear(realId); } catch (e) {} realId = null; }
      sim.watches[id] = null;
      offerSim(err).then(function (yes) {
        if (yes) { sim.watches[id] = ok; startSim(); setTimeout(function () { ok(simFix()); }, 200); }
        else { delete sim.watches[id]; if (fail) fail(err); }
      });
    };
    realId = realWatch(function (p) { gotOne = true; ok(p); }, function (err) { if (!gotOne && (err.code === 1 || err.code === 2)) giveUp(err); else if (fail) fail(err); }, opts);
    // No fix at all within 25 s (a desktop with no location source): offer the walk too.
    setTimeout(function () { if (!gotOne && realId !== null) giveUp({ code: 2, message: 'No position' }); }, 25000);
    sim.watches[id] = undefined;   // a real watch (undefined), waiting for the visitor (null), or simulated (the callback)
    sim['real' + id] = function () { return realId; };
    return id;
  };
  geo.clearWatch = function (id) {
    if (Object.prototype.hasOwnProperty.call(sim.watches, id)) {
      var r = sim['real' + id] && sim['real' + id]();
      if (r !== null && r !== undefined) { try { realClear(r); } catch (e) {} }
      delete sim.watches[id]; delete sim['real' + id]; stopSimIfIdle(); return;
    }
    realClear(id);
  };
  geo.getCurrentPosition = function (ok, fail, opts) {
    if (sim.on) { setTimeout(function () { ok(simFix()); }, 200); return; }
    realGet(ok, function (err) {
      // Part of starting a walk (a watch was asked for alongside): one question for both, not an error first.
      if ((err.code === 1 || err.code === 2) && Object.keys(sim.watches).length) {
        offerSim(err).then(function (yes) { if (yes) { startSim(); ok(simFix()); } else if (fail) fail(err); });
        return;
      }
      if (fail) fail(err);
    }, opts);
  };
})(window, document);
