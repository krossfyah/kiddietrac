/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — every badge from one answer, in real time (2026-10-30).

   Anthony: "app notification counters not reset on phones when opening up the notifications etc - this needs to be
   in realtime".

   Measured before: opening Notifications reset nothing - the bell stayed at 38, the bottom Inbox hid while the
   screen was open and came back at 17 on leaving (two different questions, two different answers). Reading a chat
   cleared its badge only on the next 15 s poll, plus up to 9 s of cached count. The phone's delivered notifications
   and the iPhone icon number (always 1) were never cleared.

   Now:
     - ONE request, GET /badges (App\Support\Badges), feeds the bell, the bottom bar (Inbox / Photos / Billing /
       Messages), the parent bar, the announcements badge and the phone's icon. kt-topbar, kt-mobilenav,
       kt-parent-chrome and nav-additions-v14 read KT.badges instead of asking their own questions.
     - Opening the inbox (the Notifications screen or the bell's panel) marks everything SEEN: the counters drop to 0
       at once (before the server answers) and stay there; the rows stay unread in the list until tapped.
     - Reading anything (a chat thread, a notification row, Read all, announcements) is seen as it happens - a
       PerformanceObserver watches the requests that mark things read - and the counts are fetched again at once.
     - Other tabs of the same browser update instantly (BroadcastChannel); other devices on their next poll (15 s)
       or the moment they come back to the foreground, and iPhones get the new icon number pushed (POST /badges/sync).
     - On the phone app: delivered notifications are cleared from the shade when what they announced has been seen
       (alerts) or read (messages), and all of them when nothing is left; the icon number follows (Android's dot
       follows the shade; iPhone via the badge push; home-screen web apps via navigator.setAppBadge).
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});

  /* ONE TILE BADGE (2026-11-06, Anthony: "the forms to sign is not as tight as the my tasks - all indicators should be
     consistent and the same"). My tasks, Forms to sign, Documents and Website chat each drew their own count on a home
     tile: on the icon, in the tile's corner, as a grey pill by the label. Now every tile count is this one: red, on the
     icon's top-right, with a white ring. KT.tileBadge(tile, n) - n <= 0 removes it. kt:screen-guard-check fails on a
     tile badge drawn anywhere else. */
  if (!KT.tileBadge) {
    KT.tileBadge = function (tile, n) {
      if (!tile) return;
      var host = tile.querySelector('.kt-tile-icon') || tile;
      var b = host.querySelector(':scope > .kt-tile-badge');
      n = +n || 0;
      if (n <= 0) { if (b) b.remove(); return; }
      if (!document.getElementById('kt-tile-badge-css')) {
        var st = document.createElement('style');
        st.id = 'kt-tile-badge-css';
        st.textContent = '.kt-tile-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;'
          + 'background:#DC2626;color:#fff;border-radius:999px;font-size:11px;font-weight:900;line-height:20px;text-align:center;'
          + 'box-shadow:0 0 0 2px #fff;box-sizing:border-box;z-index:2;pointer-events:none;font-family:inherit;}';
        document.head.appendChild(st);
      }
      if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
      if (!b) { b = document.createElement('span'); b.className = 'kt-tile-badge'; host.appendChild(b); }
      var txt = n > 99 ? '99+' : String(n);
      if (b.textContent !== txt) b.textContent = txt;
    };
  }

  if (KT.badges) return;

  var FRESH_MS = 10000;      // a caller that does not force gets the last answer this long
  var POLL_MS = 10000;       // while the page is visible (one small request replaces six pollers)
  var state = null, at = 0, inflight = null, lastSync = 0, syncTimer = null;

  function api() { return (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function uid() { try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || 'null'); return u && (u.id || (u.user && u.user.id)) || null; } catch (e) { return null; } }
  function headers() {
    var h = { 'Authorization': 'Bearer ' + tok(), 'Accept': 'application/json' };
    try {
      var a = sessionStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a;
      var v = sessionStorage.getItem('kt_view_as'); if (v) h['X-View-As-Role'] = v;
    } catch (e) {}
    return h;
  }
  /* WHOSE COUNT (2026-11-02, Anthony: "portal keeps showing i have new message open messags to read but there isn't").
     The numbers depend on the tab's agency and view-as (text replies, family chats), but tabs shared them by person
     alone: a tab on an agency with nothing unread took another tab's 1, said "New message", went back to 0 on its own
     poll, and took the 1 again - a toast every round. A shared answer now names its scope and only that scope uses it. */
  function scope() {
    try { return uid() + '|' + (sessionStorage.getItem('kt_active_agency_id') || '') + '|' + (sessionStorage.getItem('kt_view_as') || ''); } catch (e) { return String(uid()); }
  }
  function call(method, path) {
    if (!tok()) return Promise.resolve(null);
    return fetch(api() + path, { method: method, headers: headers(), cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }
  function msgs(d) { return Math.max(+d.chats || 0, +d.parent_messages || 0) + (+d.team_threads || 0) + (+d.web_chats || 0) + (+d.sms_replies || 0); }   // sms_replies: unread text replies in Message Centre → Texts (2026-11-02)   // web_chats: open website chats with an unread visitor message (super admins)
  function total(d) { return (+d.notifications || 0) + msgs(d); }

  var bc = null;
  try { bc = new BroadcastChannel('kt-badges'); } catch (e) {}

  /* THE LIST, ONLY WHEN SOMETHING NEW EXISTS (2026-10-30, speed follow-up). kt-urgent-alert fetched /notifications
     every 15 s and kt-sounds read that response (and fetched it itself when nobody had for 40 s), so every open page
     pulled the whole list four times a minute to find out whether anything had arrived. /badges already says so:
     newest_id is the person's newest notification. The list is fetched once at start (the baselines both need) and
     again only when newest_id rises; it is announced as kt:notification-list. Through window.fetch on purpose:
     kt-sounds reads that response (tones, and the website demo phone's banners). */
  var newest = null, listBusy = null;
  function fetchList() {
    if (listBusy) return listBusy;
    if (!tok()) return Promise.resolve(null);
    listBusy = w.fetch(api() + '/notifications', { headers: headers(), cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        listBusy = null;
        if (j) { try { w.dispatchEvent(new CustomEvent('kt:notification-list', { detail: j })); } catch (e) {} }
        return j;
      }, function () { listBusy = null; return null; });
    return listBusy;
  }

  function apply(d, from) {
    if (!d || typeof d !== 'object') return;
    var prev = state;
    d.total = total(d);
    state = d; at = Date.now();
    if (from === 'net' && d.newest_id != null && (newest === null || +d.newest_id > newest)) { newest = +d.newest_id; fetchList(); }
    paintCounts();
    try { w.dispatchEvent(new CustomEvent('kt:badges', { detail: d })); } catch (e) {}
    planCheck(d);
    if (from !== 'tab' && bc) { try { bc.postMessage({ t: 'state', u: uid(), k: scope(), d: d }); } catch (e) {} }
    phone(prev, d, from);
  }

  /* EVERY COUNT, ONE LOOK (2026-11-06, Anthony: "look at news under parent view its not consistent across how we
     designed it - full sweep"). News drew its own wide pill after the tile's label (nav-additions-v14), and
     Message Centre, Inbox, Billing and Photos had a count on the phone's bottom bar but none on their home tile.
     Now this one answer paints them all: menu items through their badge key (KT.Shell.setBadge), home tiles through
     KT.tileBadge. Forms to sign, My tasks, Documents and Website chat count elsewhere and call KT.tileBadge
     themselves. kt:screen-guard-check fails on a count drawn any other way. */
  function isParentView() { return /\brole-guardian\b/.test(document.body.className || ''); }
  function counts(d) {
    var cat = d.cat || {};
    var m = isParentView() ? (+d.parent_messages || 0) : msgs(d);
    return {
      keys: { announcement_unread: +d.announcements || 0, notif_unread: +d.notifications || 0,
        billing_due: +cat.billing || 0, photos_new: +cat.photos || 0 },
      tiles: { announcements: +d.announcements || 0, notifications: +d.notifications || 0, billing: +cat.billing || 0,
        photos: +cat.photos || 0, messages: m, chat: m }
    };
  }
  var paintTimer = null;
  function paintCounts() {
    if (!state || !document.body) return;
    var c = counts(state);
    var sec = section();
    try {
      if (KT.Shell && KT.Shell.setBadge) {
        Object.keys(c.keys).forEach(function (k) { KT.Shell.setBadge(k, c.keys[k]); });
      }
    } catch (e) {}
    if (!KT.tileBadge) return;
    Object.keys(c.tiles).forEach(function (h) {
      var n = sec === h ? 0 : c.tiles[h];   // the section you are in does not nag (as the bottom bar does)
      document.querySelectorAll('a.kt-tile[href="#' + h + '"]').forEach(function (a) { KT.tileBadge(a, n); });
    });
  }
  KT.paintCounts = paintCounts;
  // Home tiles are drawn on every visit: paint them as they appear.
  function watchTiles() {
    var go = function () { clearTimeout(paintTimer); paintTimer = setTimeout(paintCounts, 120); };
    if (KT.observeMain) { KT.observeMain(go, { childList: true, subtree: true }); }
    w.addEventListener('hashchange', go);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watchTiles); else setTimeout(watchTiles, 0);

  function get(force) {
    if (!force && state && Date.now() - at < FRESH_MS) return Promise.resolve(state);
    if (inflight) return inflight;
    inflight = call('GET', '/badges').then(function (d) { inflight = null; if (d) apply(d, 'net'); return state; },
      function () { inflight = null; return state; });
    return inflight;
  }

  var soonTimer = null;
  function soon(ms) { clearTimeout(soonTimer); soonTimer = setTimeout(function () { get(true); }, ms || 250); }

  /* The inbox was opened: alerts count as seen, here and on every other device. */
  var seenBusy = false;
  function seen() {
    if (!tok()) return Promise.resolve(state);
    if (state) {
      var o = {}; for (var k in state) o[k] = state[k];
      o.notifications = 0; o.cat = { photos: 0, billing: 0, other: 0 };
      apply(o, 'local');
    }
    try { w.dispatchEvent(new CustomEvent('kt:badges-seen')); } catch (e) {}
    if (bc) { try { bc.postMessage({ t: 'seen', u: uid() }); } catch (e) {} }
    if (seenBusy) return Promise.resolve(state);
    seenBusy = true;
    return call('POST', '/notifications/seen').then(function (d) { seenBusy = false; if (d) { lastSync = Date.now(); apply(d, 'net'); } return state; },
      function () { seenBusy = false; return state; });
  }

  if (bc) bc.onmessage = function (ev) {
    var m = ev && ev.data; if (!m || (m.u && uid() && m.u !== uid())) return;
    if (m.t === 'state' && m.d && m.k === scope()) apply(m.d, 'tab');   // another agency's or view's count is not this tab's
    if (m.t === 'seen') { try { w.dispatchEvent(new CustomEvent('kt:badges-seen')); } catch (e) {} }
  };

  /* ── The phone: shade, icon number ─────────────────────────────── */
  function native() {
    var C = w.Capacitor;
    return !!(C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative));
  }
  function plugin() { var C = w.Capacitor; return C && C.Plugins && C.Plugins.PushNotifications; }
  function isChat(n) { return /^\s*💬/.test(String((n && n.title) || '')) || /chat|message/i.test(String(n && n.data && (n.data.link || n.data.url) || '')); }
  function clearShade(which) {
    var PN = plugin(); if (!PN) return;
    try {
      if (which === 'all') { PN.removeAllDeliveredNotifications().catch(function () {}); return; }
      PN.getDeliveredNotifications().then(function (r) {
        var list = (r && r.notifications) || [];
        var drop = list.filter(function (n) { return which === 'chat' ? isChat(n) : !isChat(n); });
        if (drop.length) PN.removeDeliveredNotifications({ notifications: drop }).catch(function () {});
      }).catch(function () {});
    } catch (e) {}
  }
  function phone(prev, d, from) {
    var n = d.total || 0;
    // Home-screen web apps (Android Chrome, iPhone 16.4+) and the desktop PWA: the icon number.
    try {
      if (!native() && navigator.setAppBadge) { n > 0 ? navigator.setAppBadge(n).catch(function () {}) : navigator.clearAppBadge().catch(function () {}); }
    } catch (e) {}
    if (native()) {
      if (n === 0) clearShade('all');
      else {
        if (prev && prev.notifications > 0 && !d.notifications) clearShade('alerts');
        if (prev && msgs(prev) > 0 && !msgs(d)) clearShade('chat');
      }
    }
    // Fewer than before, and it happened HERE: tell the person's iPhones (server: only when it has iOS devices and
    // the number changed; at most every few seconds).
    if (prev && from !== 'tab' && n < (prev.total || 0)) {
      clearTimeout(syncTimer);
      syncTimer = setTimeout(function () {
        if (Date.now() - lastSync < 2500) return;
        lastSync = Date.now();
        call('POST', '/badges/sync');
      }, 1500);
    }
  }

  /* ── When to ask again ────────────────────────────────────────── */
  function section() { return String(location.hash || '').replace(/^#/, '').split('?')[0]; }
  function onHash() {
    var s = section();
    if (s === 'notifications') { seen(); return; }
    if (/^(chat|messages|team-chat|parent-messages)/.test(s)) { soon(1200); soon(3000); }
  }
  w.addEventListener('hashchange', onHash);

  // Anything that marks something read: refetch the moment it finishes (a chat thread is marked read by OPENING it,
  // a GET, so writes alone would miss it).
  var reads = [];   // what triggered a refetch (KT.badges.reads(), for checking)
  var READS = /\/api\/v1\/(?:(?:parent|provider|director)\/)?(?:chats\/\d+(?:\?|$)|chat\/conversations\/\d+|messages\/(?:\d+|thread)|team-threads\/\d+(?:\?|$|\/messages)|threads\/\d+(?:\?|$|\/messages)|notifications\/(?:\d+\/(?:read|unread)|\d+$|mark-read|delete)|announcements\/(?:\d+|state))/;
  // Opening a text reply (Message Centre → Texts, SMS → Replies) reads it (2026-11-02).
  var SMS_READ = /\/api\/v1\/(?:admin\/sms\/replies|sms-replies)\/\d+\/(?:read|reply)/;
  try {
    new PerformanceObserver(function (list) {
      list.getEntries().forEach(function (e) {
        var u = String(e.name || '');
        if (u.indexOf('/badges') !== -1 || u.indexOf('/notifications/seen') !== -1) return;
        if (READS.test(u) || SMS_READ.test(u)) { reads.push(u.slice(-60)); soon(250); }
      });
    }).observe({ type: 'resource', buffered: false });
  } catch (e) {}

  /* THE PLAN MOVED (2026-11-26, App\Support\PlanStamp). /badges carries plan_v, the fingerprint of the licence
     lines, feature switches and catalogue behind the agency in view; kt_user.plan_v is the one the tab's menu was
     built from. When they differ, kt:plan-changed - kt-topbar asks /auth/me ONCE and adopts the new plan. This is
     NOT a kt:badges listener and never fetches here (kt-badges-listener-no-fetch): it dispatches a different event,
     on a timer, once per distinct plan_v (retried after a minute if that answer never landed). "All agencies" has
     no one plan. now = this tab just changed a licence or a feature (Platform -> Licences / Agencies): redraw at once. */
  var planAsked = '', planAskedAt = 0, planTouch = 0;
  function planCheck(d) {
    try {
      var v = d && d.plan_v; if (!v) return;
      if ((sessionStorage.getItem('kt_active_agency_id') || '') === 'all') return;
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || 'null');
      if (!u || u.plan_v === v) return;
      if (planAsked === v && Date.now() - planAskedAt < 60000) return;
      planAsked = v; planAskedAt = Date.now();
      var now = Date.now() - planTouch < 15000;
      setTimeout(function () { try { w.dispatchEvent(new CustomEvent('kt:plan-changed', { detail: { plan_v: v, now: now } })); } catch (e) {} }, 0);
    } catch (e) {}
  }
  // A licence or feature switch saved in this tab: ask /badges now, so its plan_v (and the redraw) follow at once.
  var PLAN_WRITE = /\/api\/v1\/(?:platform\/licences|admin\/agencies\/\d+\/features)(?:[\/?]|$)/;
  try {
    new PerformanceObserver(function (list) {
      list.getEntries().forEach(function (e) {
        if (PLAN_WRITE.test(String(e.name || ''))) { planTouch = Date.now(); soon(400); }
      });
    }).observe({ type: 'resource', buffered: false });
  } catch (e) {}

  var pollTimer = null;
  function poll() { clearTimeout(pollTimer); pollTimer = setTimeout(function () { if (!document.hidden) get(true); poll(); }, POLL_MS); }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) get(true); });
  try {
    var App = w.Capacitor && w.Capacitor.Plugins && w.Capacitor.Plugins.App;
    if (App && App.addListener) App.addListener('resume', function () { get(true); });
  } catch (e) {}

  /* A PUSH IS THE ARRIVAL (2026-11-05). The page heard of new things only on its 10 s poll; now the push itself asks
     (the service worker's kt-push message in a browser, KT.badges.arrived() from the phone app's push listener). */
  function arrived() { soon(300); }
  try {
    if (navigator.serviceWorker && navigator.serviceWorker.addEventListener) {
      navigator.serviceWorker.addEventListener('message', function (ev) { if (ev && ev.data && ev.data.type === 'kt-push') arrived(); });
    }
  } catch (e) {}

  KT.badges = {
    get: get,                                   // Promise of the last answer (force = ask the server now)
    refresh: function () { return get(true); },
    arrived: arrived,                           // a push just came in: ask now
    seen: seen,                                 // the inbox was opened
    now: function () { return state; },
    msgs: msgs,
    reads: function () { return reads.slice(-20); },
    list: fetchList                             // the notification list now (announced as kt:notification-list)
  };

  function boot() { if (tok()) { get(true); poll(); if (section() === 'notifications') seen(); } else setTimeout(boot, 1500); }
  boot();
})(window);
