/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — global top status bar (2026-07-08, v3).
   Subtle, translucent, blends into the light portal (no bold gradient).
   Time-of-day greeting + clickable name (→ profile) + role, action icons
   (Quick add / Messages+badge / Announcements / AI), weather, live clock.
   Also refreshes the signed-in user (photo_url) so the sidebar avatar shows
   the latest display picture. Re-injects after each screen render.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktTopbar) return; window.__ktTopbar = true;

  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  function store(k) { try { return sessionStorage.getItem(k) || localStorage.getItem(k); } catch (e) { return null; } }
  function user() { try { return JSON.parse(store('kt_user') || '{}'); } catch (e) { return {}; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function firstName(u) { var n = (u && (u.name || u.first_name || '')) || ''; return n.split(' ')[0] || 'there'; }

  var ROLE = { platform_admin: 'Super Admin', agency_admin: 'Agency Admin', centre_director: 'Centre Director', educator: 'Educator', guardian: 'Parent', home_visitor: 'Home Visitor', sales_rep: 'Sales Rep', reseller_admin: 'Reseller', auditor: 'Auditor' };
  function roleLabel(u) {
    var roles = (u && u.roles) || [];
    if (roles.indexOf('reseller_admin') !== -1 && roles.indexOf('platform_admin') === -1) return ROLE.reseller_admin;   // 2026-10-21
    var order = ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'auditor', 'guardian'];
    for (var i = 0; i < order.length; i++) { if (roles.indexOf(order[i]) !== -1) return ROLE[order[i]]; }
    return (u && u.primary_role && ROLE[u.primary_role]) || 'Member';
  }
  function isAdminRole(u) { var r = (u && u.roles) || []; return ['platform_admin', 'agency_admin', 'centre_director'].some(function (x) { return r.indexOf(x) !== -1; }); }
  function isStaffBarRole(u) { var r = (u && u.roles) || []; return r.indexOf('educator') !== -1 || r.indexOf('home_visitor') !== -1; }
  function isPlatformAdmin(u) { return ((u && u.roles) || []).indexOf('platform_admin') !== -1; }

  // Super-admin View-as + agency switcher are built NATIVELY in the bar (not moved
  // from the sidebar). Because screens wipe #appMain — and the bar lives there —
  // moved elements were destroyed on every navigation and never rebuilt. Building
  // them here means they are recreated together with the bar each time. The sidebar
  // originals are hidden via CSS (body.kt-tb-has-selectors).
  var AGENCIES = null;
  function ctl(id) { var d = document.createElement('div'); d.id = id; d.className = 'kt-tb-ctl'; return d; }
  /* `sfx` lets a second copy live somewhere else — the admin launcher builds its own
     with '-home', because the phone hides this bar entirely. Without a distinct id the
     single-instance guard below would make the second call a no-op. */
  function buildViewAs(host, sfx) {
    sfx = sfx || '';
    if (document.getElementById('kt-tb-viewas' + sfx)) return;
    var cur = ''; try { cur = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var roles = [['', 'Super admin (default)'], ['agency_admin', '🏢 Agency admin'], ['centre_director', '🏫 Centre director'], ['educator', '🎓 Educator'], ['guardian', '👪 Parent / guardian'], ['home_visitor', '🏡 Home visitor'], ['sales_rep', '💼 Sales rep'], ['auditor', '🔍 Auditor']];
    var wrap = ctl('kt-tb-viewas' + sfx); if (cur) wrap.classList.add('kt-tb-ctl-active');
    var lab = document.createElement('span'); lab.className = 'kt-tb-ctl-lab'; lab.textContent = cur ? '👁 Viewing as' : '👁 View as';
    var sel = document.createElement('select'); sel.className = 'kt-tb-ctl-sel'; sel.title = 'Preview the portal as another role';
    roles.forEach(function (r) { var o = document.createElement('option'); o.value = r[0]; o.textContent = r[1]; if (r[0] === cur) o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () { if (window.ktViewAs) window.ktViewAs(sel.value); });
    wrap.appendChild(lab); wrap.appendChild(sel); host.appendChild(wrap);
  }
  function buildAgency(host, sfx) {
    sfx = sfx || '';
    if (document.getElementById('kt-tb-agency' + sfx)) return;
    var wrap = ctl('kt-tb-agency' + sfx); host.appendChild(wrap);
    var render = function (list, isPlat) {
      if (!list || (!isPlat && list.length < 2)) { wrap.remove(); return; }
      var active = ''; try { active = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
      var lab = document.createElement('span'); lab.className = 'kt-tb-ctl-lab'; lab.textContent = '🏢';
      var sel = document.createElement('select'); sel.className = 'kt-tb-ctl-sel'; sel.title = 'Switch active agency';
      if (isPlat) { var oa = document.createElement('option'); oa.value = 'all'; oa.textContent = '🌐 All agencies'; if (active === 'all') oa.selected = true; sel.appendChild(oa); }
      list.forEach(function (a) { var o = document.createElement('option'); o.value = a.id; o.textContent = a.name; if (String(a.id) === String(active)) o.selected = true; sel.appendChild(o); });
      sel.addEventListener('change', function () {
        try {
          sessionStorage.setItem('kt_active_agency_id', sel.value);
          var nm = sel.value === 'all' ? 'All agencies' : ((list.filter(function (a) { return String(a.id) === sel.value; })[0] || {}).name || '');
          sessionStorage.setItem('kt_active_agency_name', nm);
        } catch (e) {}
        // Remembered for the next sign-in (2026-10-23). "All agencies" is a browser-only scope.
        if (sel.value === 'all') { window.location.reload(); return; }
        var go = function () { window.location.reload(); };
        var tk = store('kt_token'); if (!tk) { go(); return; }
        var tm = setTimeout(go, 2500);
        fetch(API + '/auth/active-agency', { method: 'POST', keepalive: true,
          headers: { 'Authorization': 'Bearer ' + tk, 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ agency_id: parseInt(sel.value, 10) }) })
          .catch(function () {}).then(function () { clearTimeout(tm); go(); });
      });
      wrap.appendChild(lab); wrap.appendChild(sel);
    };
    if (AGENCIES) { render(AGENCIES.agencies || [], !!AGENCIES.is_platform_admin); return; }
    var t = store('kt_token'); if (!t) { wrap.remove(); return; }
    fetch(API + '/auth/agencies', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (!d) { wrap.remove(); return; } AGENCIES = d; render(d.agencies || [], !!d.is_platform_admin); })
      .catch(function () { wrap.remove(); });
  }
  function buildSelectors() {
    var host = document.getElementById('kt-tb-selectors'); if (!host) return;
    document.body.classList.add('kt-tb-has-selectors');
    buildViewAs(host); buildAgency(host);
  }
  function greetEmoji(h) {
    if (h < 5) return { g: 'Good night', e: '🌙' };     // pre-dawn, not "morning"
    if (h < 12) return { g: 'Good morning', e: '🌅' };
    if (h < 17) return { g: 'Good afternoon', e: '☀️' };
    if (h < 22) return { g: 'Good evening', e: '🌆' };
    return { g: 'Good night', e: '🌙' };
  }
  function fmtClock() {
    var t = (window.KT && KT.agencyHM) ? KT.agencyHM() : { h: new Date().getHours(), m: new Date().getMinutes() }, h = t.h, ap = h >= 12 ? 'PM' : 'AM', hh = h % 12; if (hh === 0) hh = 12;   // agency time (kt-tz.js)
    return hh + ':' + String(t.m).padStart(2, '0') + ' ' + ap;
  }
  function wxEmoji(c) {
    if (c === 0) return '☀️'; if (c <= 2) return '🌤'; if (c === 3) return '☁️';
    if (c === 45 || c === 48) return '🌫'; if (c >= 51 && c <= 67) return '🌧';
    if (c >= 71 && c <= 77) return '❄️'; if (c >= 80 && c <= 82) return '🌦';
    if (c >= 85 && c <= 86) return '🌨'; if (c >= 95) return '⛈'; return '🌡';
  }
  function loadWeather(cb) {
    var tries = 0, sp0 = null;
    try { sp0 = sessionStorage.getItem('kt_spelling'); } catch (e) {}
    if (!sp0) {
      (function wait() {
        var v = null; try { v = sessionStorage.getItem('kt_spelling'); } catch (e) {}
        if (v || ++tries > 100) loadWeatherNow(cb); else setTimeout(wait, 100);
      })();
      return;
    }
    loadWeatherNow(cb);
  }
  function loadWeatherNow(cb) {
    var agF = false; try { agF = sessionStorage.getItem('kt_spelling') === 'us'; } catch (e) {}
    try { var c = sessionStorage.getItem('kt_wx'); if (c) { var o = JSON.parse(c); if (o && o.t && (Date.now() - o.t < 1800000) && !!o.agF === agF) { cb(o.txt); return; } } } catch (e) {}
    var done = function (txt) { try { sessionStorage.setItem('kt_wx', JSON.stringify({ t: Date.now(), txt: txt, agF: agF })); } catch (e) {} cb(txt); };
    fetch('https://ipapi.co/json/').then(function (r) { return r.json(); }).then(function (loc) {
      // Fahrenheit where the viewer is (2026-10-28: a US demo agency showed 13°C).
      var fahr = agF || ['US', 'PR', 'GU', 'VI', 'AS', 'MP', 'LR', 'MM', 'BS', 'BZ', 'KY', 'PW', 'FM', 'MH'].indexOf(String(loc.country_code || loc.country || '').toUpperCase()) >= 0;
      return fetch('https://api.open-meteo.com/v1/forecast?latitude=' + loc.latitude + '&longitude=' + loc.longitude + '&current=temperature_2m,weather_code' + (fahr ? '&temperature_unit=fahrenheit' : ''))
        .then(function (r) { return r.json(); }).then(function (w) { done(wxEmoji(w.current.weather_code) + ' ' + Math.round(w.current.temperature_2m) + (fahr ? '°F' : '°C')); });
    }).catch(function () { cb(null); });
  }

  function openQuickAdd() { var f = document.querySelector('.kt-qa-fab'); if (f) f.click(); }
  function openAI() { var h = document.getElementById('kt-floating-help'); if (h) { h.click(); return; } try { if (window.KT && typeof window.KT.openChatbot === 'function') return window.KT.openChatbot(); } catch (e) {} var f = document.getElementById('kt-chatbot-fab'); if (f) f.click(); }
  function go(hash) { location.hash = hash; }

  // ── Notification / activity feed (admins + directors; platform_admin sees ALL
  //    agencies, everyone else only their own — the backend isolates it). ────────
  /* ON THE ACCOUNT, NOT THE BROWSER.

     This read position was localStorage only, so clearing the bell on a laptop left it
     lit on the phone and every new browser started by claiming everything was unread.
     KT.markers keeps localStorage as a cache — the badge still paints instantly — and
     reconciles with the server, which refuses to move a marker backwards.

     Falls back to reading localStorage directly if kt-markers.js has not loaded: a bell
     that works per-device is worse than one that works everywhere, and far better than a
     bell that throws. */
  function notifSeen() {
    if (window.KT && KT.markers) { return KT.markers.get('kt_notif_seen'); }
    try { return localStorage.getItem('kt_notif_seen') || ''; } catch (e) { return ''; }
  }
  function setNotifSeen(v) {
    if (!v) { return; }
    if (window.KT && KT.markers) { KT.markers.set('kt_notif_seen', String(v)); return; }
    try {
      var cur = localStorage.getItem('kt_notif_seen') || '';
      if (cur && String(v) < cur) { return; }   // never backwards
      localStorage.setItem('kt_notif_seen', String(v));
    } catch (e) {}
  }
  function relTime(iso) {
    if (!iso) return '';
    // MySQL timestamps come back UTC with NO zone marker; without appending 'Z' the
    // browser parses them as LOCAL time, which for anyone west of UTC lands the
    // event in the FUTURE → negative age → the bell was stuck on "just now" forever.
    // (Matches the fix already in screen-notifications.js.)
    var v = String(iso).replace(' ', 'T');
    if (!/(Z|[+-]\d{2}:?\d{2})$/.test(v)) v += 'Z';
    var d = new Date(v); if (isNaN(d.getTime())) return '';
    var s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (s < 0) s = 0;
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    if (s < 604800) return Math.floor(s / 86400) + 'd ago';
    /* Older than a week falls back to a DATE, and that date must be the agency's, not
       the device's. The relative half above was already correct; this line was still
       rendering in whatever zone the phone happened to be in, so a late-evening event
       could show the wrong day. Standing rule: every displayed date/time is agency time. */
    var _tz = (window.KT && KT.tz) ? KT.tz() : undefined;
    return d.toLocaleDateString(undefined, _tz
      ? { month: 'short', day: 'numeric', timeZone: _tz }
      : { month: 'short', day: 'numeric' });
  }
  /* THE BELL OPENS ON WHAT IT ALREADY KNOWS (2026-10-28, Anthony's desktop video: "clicking on
     the bell does[n't] open up instantly and has this slow loading effect to it"). The panel
     opened on "Loading…" and waited for /admin/activity-feed - measured 200-650ms from a
     desktop browser - before a single row, so it popped open small and then grew. The badge
     poll asks for the very same feed every 15 seconds, so the last good answer is kept here
     (per token and agency, 5 minutes at most) and the panel draws it the moment it opens; the
     fresh request still goes out and redraws in place when it lands. */
  var __feedMemo = null;
  function feedMemo() {
    var m = __feedMemo, t = store('kt_token') || '', a = '';
    try { a = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    return m && m.tok === t.slice(-12) && m.agency === a && Date.now() - m.at < 300000 ? m.d : null;
  }
  function loadFeed(cb) {
    var t = store('kt_token'); if (!t) { cb(null); return; }
    /* ADMIN ONLY. The route is behind
       role:agency_admin,centre_director,platform_admin, so asking as an educator or
       a guardian earns a 403 on every poll -- 761 of them this month, about one
       activity-feed call in ten, and one audit row each since failing GETs started
       being recorded. ensure() already returns early for non-admins, so this should
       be unreachable; the refusals say otherwise, so the guard sits where the
       request is actually made. Same helper the bar builds itself with, so there is
       one answer to "is this person an admin". */
    if (!isAdminRole(user())) { cb(null); return; }
    /* SCOPED TO THE AGENCY ON SCREEN, like every other call in the portal.

       This sent no X-Active-Agency-Id, and the endpoint reads that header to decide what
       a platform admin may see: without it the response comes back `scope: "all"` — every
       agency's activity — while the rest of the portal is showing one agency. Measured:
       no header → scope "all", header → scope "agency". So a super admin's bell was
       counting other tenants' check-ins and sign-ins, which is both wrong and impossible
       to clear by reading anything on screen. (Anthony, 2026-09-09) */
    var h = { 'Authorization': 'Bearer ' + t };
    try { var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa; } catch (e) {}
    __feedAt = Date.now();   // asked now: a repaint in the meantime does not ask again
    fetch(API + '/admin/activity-feed?alerts=1', { headers: h })   // ?alerts=1: own safety alerts merged in time order (2026-11-26)
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.events) { __feedMemo = { at: Date.now(), tok: t.slice(-12), agency: h['X-Active-Agency-Id'] || '', d: d }; __feedLast = __feedMemo; __feedAt = Date.now(); }
        cb(d);
      }).catch(function () { cb(null); });
  }
  function setNotifBadge(n) {
    var b = document.getElementById('kt-tb-notif-badge'); if (!b) return;
    if (n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.hidden = false; } else { b.hidden = true; }
  }
  /* THE PERSON'S OWN ALERTS, which the admin bell never counted.

     There are two streams and only one of them was on screen for an admin:

       notifications        — addressed to a PERSON: an absence, a chat, a clock
                              reminder, a form. Written by the whole product.
       admin/activity-feed  — agency EVENTS addressed to nobody: a family created,
                              a sign-in, an inspection filed.

     The admin bell counted the second and ignored the first, so an alert could be
     written, delivered by push, emailed — and the indicator beside it never moved.
     Measured on 2026-09-10: 338 unread notifications for one agency admin, 13 for
     another, none of which the bell had ever counted. That is the whole of "alerts
     are logged but the indicator doesn't populate".

     Counted here, and the panel gains a row that opens the inbox, so the number
     always corresponds to something the reader can actually go and look at. */
  var __ownUnread = 0;
  function refreshOwnUnread(done) {
    var t = store('kt_token');
    if (!t) { __ownUnread = 0; if (done) done(); return; }
    var h = { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' };
    try { var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa; } catch (e) {}
    // Every badge reads one answer (kt-badges.js, 2026-10-30): "new since you last opened the inbox".
    (window.KT && KT.badges ? KT.badges.get().then(function (b) { return b ? { unread: b.notifications } : null; })
      : fetch(API + '/notifications/unread-count?type=*', { headers: h }).then(function (r) { return r.ok ? r.json() : null; }))   // * = every kind, not just announcements
      .then(function (d) {
        var n = d && (d.unread_count != null ? d.unread_count
                    : (d.count != null ? d.count : (d.unread != null ? d.unread : null)));
        __ownUnread = Number(n) || 0;
      })
      .catch(function () { /* leave the last known value; a blip must not zero the bell */ })
      .then(function () { if (done) done(); });
  }

  /* THE FEED IS NOT A COUNTER (2026-10-30, speed follow-up). Every badge repaint (kt:badges - each read, every 10 s)
     and the 15 s poll re-fetched /admin/activity-feed, ~10 queries over seven tables, on about 80% of screen changes and
     sometimes 3 times in one. Now the bell's number is worked out from the last feed answer plus the person's own
     unread; the feed itself is fetched on start, when the bell opens, when data changes (kt:data-changed: a write here
     or a change /pulse saw elsewhere - at most every 20 s), and otherwise every 2 minutes. */
  var __feedLast = null, __feedAt = 0;
  function feedFor() {
    var m = __feedLast, t = store('kt_token') || '', a = '';
    try { a = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    return m && m.tok === t.slice(-12) && m.agency === a ? m.d : null;
  }
  function refreshNotifBadge(fetchFeed) {
    var cached = feedFor();
    var stale = Date.now() - __feedAt > (cached ? 120000 : 8000);   // none yet: ask, unless a request just went out
    var feedNow = (fetchFeed === true || stale) ? loadFeed : function (cb) { cb(cached); };
    refreshOwnUnread(function () {
    feedNow(function (d) {
      // No feed (not an admin, or it failed) is not a reason to hide their own alerts.
      if (!d || !d.events) { setNotifBadge(__ownUnread); return; }
      var seen = notifSeen();

      /* NO MARKER YET = ESTABLISH THE BASELINE, do not shout.

         `!seen` counted EVERY event as unread, and the feed is capped at 40 — so any
         device without the marker showed a hard 40 that nothing on screen could explain.
         A new device, a cleared browser, a private window, a second computer: all of them
         opened on "40 unread", and reading the events did not help because the count was
         never about them.

         The marker is local, so "unread" here can only ever mean "since this device last
         looked". The honest first answer is therefore none — take the current newest as
         the starting point and report what arrives AFTER it. Same reasoning as the first
         poll in kt-urgent-alert.js, which establishes a baseline rather than announcing
         everything that already happened. (Anthony, 2026-09-09) */
      if (!seen) {
        if (d.events[0] && d.events[0].when) { setNotifSeen(d.events[0].when); }
        // The baseline is for the FEED only. Alerts addressed to this person are
        // server-side unread and were never "already seen" on any device.
        setNotifBadge(__ownUnread);
        return;
      }

      // Alert rows (e.alert) are the person's own Notify rows, already in __ownUnread: counted once, there.
      // Staff submissions too (2026-11-26): /badges counts them for every device (StaffSubmissionsFeed::unseenCount).
      var unread = d.events.filter(function (e) { return !e.alert && e.type !== 'submission' && e.when && e.when > seen; }).length;
      setNotifBadge(unread + __ownUnread);
    });
    });
  }
  var __notifStarted = false;
  function startNotif() {
    if (__notifStarted) return; __notifStarted = true;
    /* Immediately when the token is already there — the 1500ms wait was to let it
       land, but the bar is built after sign-in, so it only left the bell blank.
       The delayed attempt stays as a fallback for a bar built before storage. */
    if (store('kt_token')) { refreshNotifBadge(true); }
    setTimeout(function () { refreshNotifBadge(); }, 1500);
    setInterval(function () { refreshNotifBadge(); }, 15000);   // repaints from the last feed; fetches it when 2 min old
    var lastDataFeed = 0;
    window.addEventListener('kt:data-changed', function () {
      if (Date.now() - lastDataFeed < 20000) return;
      lastDataFeed = Date.now();
      setTimeout(function () { refreshNotifBadge(true); }, 1500);
    });
  }
  // Staff (educator / home visitor) bell badge — unread count from THEIR own
  // notifications inbox (role-relevant notifications addressed to them).
  var __staffNotifStarted = false;
  function refreshStaffNotifBadge() {
    var t = store('kt_token'); if (!t) return;
    if (window.KT && KT.badges) { KT.badges.get().then(function (b) { if (b) setNotifBadge(b.notifications || 0); }); return; }
    fetch(API + '/notifications', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var rows = d.notifications || d.data || (Array.isArray(d) ? d : []);
        var n = 0; for (var i = 0; i < rows.length; i++) { if (!rows[i].read_at) n++; }
        setNotifBadge(n);
      }).catch(function () {});
  }
  function startStaffNotif() {
    if (__staffNotifStarted) return; __staffNotifStarted = true;
    if (store('kt_token')) { refreshStaffNotifBadge(); }
    setTimeout(refreshStaffNotifBadge, 1500);
    setInterval(refreshStaffNotifBadge, 15000);
  }
  var notifPanel = null;
  function closeNotif() { if (notifPanel) { notifPanel.remove(); notifPanel = null; document.removeEventListener('click', outsideNotif, true); } }
  function outsideNotif(e) {
    if (!notifPanel) return;
    if (notifPanel.contains(e.target)) return;
    if (e.target.closest && e.target.closest('[data-kttip="Notifications"]')) return;
    closeNotif();
  }
  /* YOUR SAFETY ALERTS IN THE BELL (2026-11-26, Anthony: "the safe arrival alerts should show up in the bell as well").
     The admin bell lists the agency activity feed and only summed the person's own alerts in one "N alerts for you"
     row, so a Safe Arrival alert never appeared by name.

     IN TIME ORDER, NOT PINNED (same day, Anthony: "the notification bell at the top bar keeps showing the safe alerts as
     priority it should be treated the same as once new notifications are issue it should show based on the time"). They
     were drawn in their own "Alerts for you" block above the feed, so this morning's alert sat over everything since.
     Now /admin/activity-feed?alerts=1 merges them into the one list and sorts it by when things happened (server side,
     one page); each alert row keeps its own icon, a small "Alert" chip and an unread tint, and a click marks it read and
     opens its screen. They are Notify rows, so the badge counts them through /badges only (refreshNotifBadge skips
     e.alert). /notifications/alerts stays for a non-admin opening this panel, drawn the same way. */
  function loadAlerts(cb) {
    var t = store('kt_token'); if (!t) { cb(null); return; }
    var h = { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' };
    try { var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa; } catch (e) {}
    fetch(API + '/notifications/alerts', { headers: h })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.alerts) { cb(null); return; }
        cb({ events: d.alerts.map(function (x) {
          return { type: 'alert', alert: true, id: x.id, kind: x.type, text: x.title, body: x.body, when: x.when, link: x.link || '#notifications', read: !!x.read };
        }) });
      })
      .catch(function () { cb(null); });
  }
  function markAlertRead(id) {
    var t = store('kt_token'); if (!t) return;
    try { fetch(API + '/notifications/' + id + '/read', { method: 'PATCH', headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' }, ktQuiet: true }); } catch (e) {}
  }
  // One alert row, in the list wherever its time puts it.
  function alertRow(x) {
    var m = /^(\p{Extended_Pictographic}\uFE0F?)\s*/u.exec(x.text || '');
    var icon = m ? m[1] : '\uD83D\uDEE1\uFE0F';
    var title = m ? (x.text || '').slice(m[0].length) : (x.text || '');
    var row = document.createElement('div');
    var unread = !x.read;
    var bg = unread ? '#FFF7ED' : '';
    row.className = 'kt-notif-alert';
    row.style.cssText = 'display:flex;gap:11px;align-items:flex-start;padding:10px 16px;border-bottom:1px solid #F8FAFC;cursor:pointer;' + (bg ? 'background:' + bg + ';' : '');
    row.onmouseover = function () { row.style.background = '#F1F5F9'; };
    row.onmouseout = function () { row.style.background = bg; };
    row.innerHTML = '<span style="font-size:17px;line-height:1.25;flex-shrink:0;">' + esc(icon) + '</span>'
      + '<div style="min-width:0;flex:1;"><div style="font-size:13px;color:#1E293B;line-height:1.4;' + (unread ? 'font-weight:700;' : '') + '">' + esc(title) + '</div>'
      + (x.body ? '<div style="font-size:12px;color:#64748B;line-height:1.35;margin-top:1px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">' + esc(x.body) + '</div>' : '')
      + '<div style="font-size:11px;color:#94A3B8;margin-top:2px;display:flex;align-items:center;gap:6px;">'
      + '<span style="font-size:10px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#B45309;background:#FEF3C7;border-radius:999px;padding:0 6px;line-height:16px;">Alert</span>'
      + '<span>' + esc(relTime(x.when)) + '</span></div></div>';
    row.onclick = function () { if (unread) { x.read = true; markAlertRead(x.id); } closeNotif(); go(x.link || '#notifications'); };
    return row;
  }
  function toggleNotif() {
    if (notifPanel) { closeNotif(); return; }
    var openOwn = __ownUnread;   // what was waiting for them before this opening (seen() zeroes it next)
    // Opening the bell is opening the inbox: every alert counter resets, here and on the person's other devices.
    if (window.KT && KT.badges) { KT.badges.seen(); }
    notifPanel = document.createElement('div');
    notifPanel.id = 'kt-notif-panel';
    notifPanel.style.cssText = 'position:fixed;top:52px;right:14px;width:360px;max-width:calc(100vw - 20px);max-height:70vh;background:#fff;border:1px solid #E5E7EB;border-radius:14px;box-shadow:0 20px 50px -12px rgba(15,23,42,.4);z-index:10050;display:flex;flex-direction:column;overflow:hidden;font-family:inherit;';
    notifPanel.innerHTML = '<div style="padding:13px 16px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">'
      + '<strong style="font-size:14.5px;color:#0D1B2A;">🔔 Notifications</strong>'
      + '<span style="display:flex;align-items:center;gap:10px;"><span id="kt-notif-scope" style="font-size:11px;color:#94A3B8;font-weight:600;"></span>'
      + '<a id="kt-notif-sounds" href="#settings?tab=notifications&amp;sub=sounds" title="Choose your notification sounds" style="font-size:12px;font-weight:700;color:#0F766E;text-decoration:none;white-space:nowrap;">🔊 Sounds</a></span></div>'
      + '<div id="kt-notif-list" style="overflow-y:auto;padding:6px 0;"><div style="padding:24px;text-align:center;color:#94A3B8;font-size:13px;">Loading…</div></div>';
    document.body.appendChild(notifPanel);
    var _snd = notifPanel.querySelector('#kt-notif-sounds');
    if (_snd) { _snd.addEventListener('click', function () { closeNotif(); }); }
    setTimeout(function () { document.addEventListener('click', outsideNotif, true); }, 0);
    // "New" is measured against what was seen BEFORE this opening, so the quiet redraw keeps
    // the same rows highlighted as the first draw (which itself moves the marker on).
    var openSeen = notifSeen(), drewFromMemory = false;
    var drawFeed = function (d) {
      var list = document.getElementById('kt-notif-list'); if (!list) return;
      var oldOwn = document.getElementById('kt-notif-own'); if (oldOwn && oldOwn.parentNode) oldOwn.parentNode.removeChild(oldOwn);
      var scopeEl = document.getElementById('kt-notif-scope');
      if (scopeEl && d && d.scope === 'all') scopeEl.textContent = 'Across all agencies';
      var events = (d && d.events) || [];

      /* MARKED READ AS SOON AS IT IS OPENED, before a single row is drawn.

         setNotifSeen used to run at the very END of this callback, so any early return or
         throw between here and there left the panel read but the marker unwritten — and
         the 15s poll then put the number straight back. The empty case returned early and
         never marked anything at all, so a quiet feed could never be cleared.

         Writing it first costs nothing: the newest event is already in hand, and marking
         it seen is exactly what opening the panel means. */
      var seen = openSeen;
      if (events[0] && events[0].when) { setNotifSeen(events[0].when); }
      setNotifBadge(__ownUnread);   // opening the FEED does not read their inbox

      /* The way to the person's own inbox. Without it the badge could show a number with nothing in the panel to
         explain it. A footer under the one list, not a pinned block above it (2026-11-26, Anthony: "on the bell alerts
         for you and agency activity should be combined and not separated"). */
      if (openOwn > 0) {
        var own = document.createElement('div');
        own.id = 'kt-notif-own';
        own.style.cssText = 'display:flex;gap:11px;align-items:center;padding:9px 16px;flex-shrink:0;'
          + 'border-top:1px solid #E2E8F0;background:#F8FAFC;cursor:pointer;';
        own.innerHTML = '<span style="font-size:15px;">📬</span>'
          + '<span style="flex:1;min-width:0;font-size:12.5px;color:#0D1B2A;">Your inbox · '
          + '<strong>' + (openOwn > 99 ? '99+' : openOwn) + '</strong> new</span>'
          + '<span style="font-size:12px;color:#1F6080;font-weight:700;">Open ›</span>';
        own.addEventListener('click', function () { closeNotif(); go('#notifications'); });
        list.parentNode.appendChild(own);
      }

      if (!events.length) { list.innerHTML = '<div style="padding:28px;text-align:center;color:#94A3B8;font-size:13px;">Nothing new yet.</div>'; return; }
      list.innerHTML = '';
      events.forEach(function (e) {
        if (e.alert) { list.appendChild(alertRow(e)); return; }   // already in time order (server-sorted)
        var isNew = e.when && (!seen || e.when > seen);
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:11px;align-items:flex-start;padding:10px 16px;border-bottom:1px solid #F8FAFC;cursor:' + (e.link ? 'pointer' : 'default') + ';' + (isNew ? 'background:#F0F9FF;' : '');
        row.onmouseover = function () { row.style.background = '#F1F5F9'; };
        row.onmouseout = function () { row.style.background = isNew ? '#F0F9FF' : ''; };
        // A child/staff photo (e.avatar) shows as a round thumbnail; the emoji is the
        // fallback (photoless people, or a broken image via onerror).
        var iconHtml;
        if (e.avatar) {
          var av = /^https?:\/\//i.test(e.avatar) ? e.avatar : (API.replace(/\/api\/v1\/?$/, '') + e.avatar);
          iconHtml = '<img decoding="sync" src="' + esc(av) + '" alt="" onerror="this.style.display=\'none\';if(this.nextSibling)this.nextSibling.style.display=\'\';" style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;display:block;">'
            + '<span style="font-size:17px;line-height:1.25;flex-shrink:0;display:none;">' + (e.icon || '•') + '</span>';
        } else {
          iconHtml = '<span style="font-size:17px;line-height:1.25;flex-shrink:0;">' + (e.icon || '•') + '</span>';
        }
        row.innerHTML = iconHtml
          + '<div style="min-width:0;flex:1;"><div style="font-size:13px;color:#1E293B;line-height:1.4;">' + esc(e.text || '') + '</div>'
          + '<div style="font-size:11px;color:#94A3B8;margin-top:2px;">' + esc(relTime(e.when)) + '</div></div>';
        if (e.link) row.onclick = function () { closeNotif(); go(e.link); };
        list.appendChild(row);
      });
      // (seen + badge were already set above, before the rows were built)
    };
    var memo = feedMemo();
    if (memo) { drawFeed(memo); drewFromMemory = true; }
    // Admins: one server-sorted list (feed + own alerts). Anyone else who opens this panel: their own alerts alone.
    (isAdminRole(user()) ? loadFeed : loadAlerts)(function (d) {
      if (!d && drewFromMemory) return;   // a failed refresh keeps the rows already on screen
      var l = document.getElementById('kt-notif-list'), top = l ? l.scrollTop : 0;
      drawFeed(d);
      if (l && l.isConnected) l.scrollTop = top;
    });
  }
  function openProfile() {
    // Show the user's own account record (view + edit their details/roles) rather
    // than re-running the full onboarding wizard. Falls back to the profile editor
    // if the account view isn't available (e.g. non-admin roles).
    /* THE ONLY WAY IN NOW (2026-10-01). "My profile" was taken out of the Settings menu —
       your name up here is where your profile lives. KT.openMyAccount is the User
       management dialog, and screen-admin.js loads for every role, so a centre director
       got it too: Manage dialog on themselves, with Files, Pay and Centres all refused
       (403). Only agency/platform admins have that record; anyone else gets their own
       profile screen, which is what the menu item used to open for them. */
    var u = {};
    try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) {}
    var roles = [].concat(u.roles || [], u.primary_role || []);
    var admin = roles.indexOf('agency_admin') !== -1 || roles.indexOf('platform_admin') !== -1 || !!u.is_platform_admin;
    if (admin && window.KT && typeof KT.openMyAccount === 'function') { KT.openMyAccount(); return; }
    location.hash = roles.indexOf('centre_director') !== -1 ? '#my-profile' : '#settings';
  }
  /* YOUR NAME OPENS A MENU (2026-10-21). Anthony: "where in the desktop view can I change
     the notification sounds i dont see that under the user profile settings". For an admin
     the name opened the User-management record, which has no notification settings, so
     the personal Settings screen (sounds, two-step, notifications) was unreachable from
     where people look. The profile is still the first item. */
  var profileMenu = null;
  function closeProfileMenu() {
    if (profileMenu && profileMenu.parentNode) profileMenu.parentNode.removeChild(profileMenu);
    profileMenu = null;
    document.removeEventListener('click', outsideProfileMenu, true);
  }
  function outsideProfileMenu(e) { if (profileMenu && !profileMenu.contains(e.target)) closeProfileMenu(); }
  function openProfileMenu(anchor) {
    if (profileMenu) { closeProfileMenu(); return; }
    /* QUICK POPOVER (2026-11-24): this short menu of actions is KT.quickPop (kt-quickpop.js), the one quick-choice look. */
    if (window.KT && KT.quickPop) {
      KT.quickPop({ anchor: anchor, id: 'kt-profile-pop', label: 'My account', items: [
        { icon: '👤', label: 'My profile', run: function () { openProfile(); } },
        { icon: '⚙️', label: 'My settings', run: function () { location.hash = '#settings'; } },
        { icon: '🔊', label: 'Notification sounds', run: function () { location.hash = '#settings?tab=notifications&sub=sounds'; } },
      ] });
      return;
    }
    var r = anchor.getBoundingClientRect();
    profileMenu = document.createElement('div');
    profileMenu.id = 'kt-profile-menu';
    profileMenu.setAttribute('role', 'menu');
    profileMenu.style.cssText = 'position:fixed;z-index:10060;min-width:220px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;'
      + 'box-shadow:0 16px 40px -12px rgba(15,23,42,.35);padding:6px;font-family:inherit;top:' + Math.round(r.bottom + 6) + 'px;left:'
      + Math.max(8, Math.min(Math.round(r.left), window.innerWidth - 240)) + 'px;';
    [
      ['👤', 'My profile', function () { openProfile(); }],
      ['⚙️', 'My settings', function () { location.hash = '#settings'; }],
      ['🔊', 'Notification sounds', function () { location.hash = '#settings?tab=notifications&sub=sounds'; }],
    ].forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'menuitem');
      b.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;border:0;background:none;padding:9px 12px;border-radius:8px;'
        + 'font-size:13.5px;font-weight:600;color:#0D1B2A;cursor:pointer;text-align:left;';
      b.innerHTML = '<span style="width:20px;text-align:center;">' + it[0] + '</span>' + it[1];
      b.addEventListener('mouseenter', function () { b.style.background = '#F1F5F9'; });
      b.addEventListener('mouseleave', function () { b.style.background = 'none'; });
      b.addEventListener('click', function () { closeProfileMenu(); it[2](); });
      profileMenu.appendChild(b);
    });
    document.body.appendChild(profileMenu);
    setTimeout(function () { document.addEventListener('click', outsideProfileMenu, true); }, 0);
  }
  function openHome() { location.hash = '#dashboard'; }
  // Repainted every tick, so translate at the source (2026-10-22); kt-i18n may not be loaded yet.
  function ktT(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  // In the chosen language even when this runs before kt-i18n.js has loaded (2026-10-22).
  function fmtDate() { try { var tag = { fr: 'fr-CA', es: 'es-ES', hi: 'hi-IN' }[localStorage.getItem('kt_locale')]; var o = { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' }; return (window.KT && KT.agencyNowText) ? KT.agencyNowText(o, tag) : new Date().toLocaleDateString(tag, o); } catch (e) { return ''; } }

  /* THE BAR MUST NOT FADE IN (Anthony, 2026-10-06: "the top bar disappears and
     reappears"). Measured frame by frame: the bar was never missing — every removal (the
     #appMain swap, and each Dom.clear() a screen does) was put back by the microtask
     remount before paint. It was TRANSPARENT: `#appMain > * { animation: kt-fade-in
     0.12s both !important }` (kt-consistency-polish.css) is the screen-entrance fade, the
     bar is a direct child of #appMain, and a CSS animation restarts whenever its element
     is re-attached — so every navigation replayed it 2–3 times (opacity 0 for 2–4
     frames each time, e.g. at 9ms and again at ~600ms when the screen re-cleared). The
     bar is persistent chrome, not part of the screen being entered, so it opts out of
     that fade, of its own kt-tb-in, and of the phone .kt-screen-in slide — two ids
     outrank `#appMain > *` and `#appMain > .kt-screen-in`, both !important. */
  function injectStyle() {
    if (document.getElementById('kt-topbar-style')) return;
    var s = document.createElement('style'); s.id = 'kt-topbar-style';
    s.textContent = [
      '#appMain>#kt-topbar,#appMain>#kt-topbar.kt-screen-in,#appMain>#kt-topbar.kt-banner-fx{animation:none !important;opacity:1;}',
      '.kt-topbar{position:sticky;top:6px;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:14px;',
      'color:#1E293B;border-radius:13px;padding:7px 14px;margin-bottom:14px;',
      'background:rgba(255,255,255,.6);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);',
      'border:1px solid rgba(15,23,42,.07);box-shadow:0 2px 10px -7px rgba(15,23,42,.2);animation:kt-tb-in .4s ease both;}',
      '.kt-tb-left{display:flex;align-items:center;gap:10px;min-width:0;}',
      '.kt-tb-emoji{font-size:20px;line-height:1;}',
      '.kt-tb-ava{width:32px;height:32px;border-radius:50%;overflow:hidden;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;background:#E2E8F0;box-shadow:0 1px 3px rgba(15,23,42,.16);}',
      '.kt-tb-ava img{width:100%;height:100%;object-fit:cover;display:block;}',
      '.kt-tb-ava--init{color:#fff;font-weight:700;font-size:13px;letter-spacing:.3px;}',
      '.kt-tb-greet{font-size:14px;font-weight:700;color:#1E293B;white-space:nowrap;}',
      '.kt-tb-name{color:#0E7C90;cursor:pointer;text-decoration:none;}',
      '.kt-tb-name:hover{text-decoration:underline;}',
      '.kt-tb-role{margin-left:8px;font-size:9.5px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;',
      'background:rgba(14,124,144,.12);color:#0C6070;border:1px solid rgba(14,124,144,.22);padding:2px 8px;border-radius:100px;white-space:nowrap;}',
      '.kt-tb-right{display:flex;align-items:center;gap:7px;}',
      '.kt-tb-ico{position:relative;width:31px;height:31px;border:1px solid rgba(15,23,42,.1);background:rgba(15,23,42,.03);',
      'border-radius:9px;cursor:pointer;font-size:15px;line-height:1;display:flex;align-items:center;justify-content:center;color:#334155;padding:0;transition:background .14s;}',
      '.kt-tb-ico:hover{background:rgba(15,23,42,.08);}',
      // Tooltip is now rendered by the global kt-tooltip-global.js engine (body-appended,
      // position:fixed). This screen's own ::after bubble was a DUPLICATE — two tooltips
      // showed on hover — so it's disabled here.
      '.kt-tb-ico[data-kttip]:hover::after{content:none;}',
      '.kt-tb-badge{position:absolute;top:-5px;right:-5px;min-width:15px;height:15px;padding:0 4px;border-radius:8px;',
      'background:#EF4444;color:#fff;font-size:9.5px;font-weight:800;line-height:15px;text-align:center;box-shadow:0 0 0 2px rgba(255,255,255,.8);}',
      '.kt-tb-sep{width:1px;height:18px;background:rgba(15,23,42,.12);margin:0 2px;}',
      '.kt-tb-weather{font-size:12.5px;font-weight:600;color:#475569;white-space:nowrap;}',
      '.kt-tb-date{font-size:12.5px;font-weight:600;color:#475569;white-space:nowrap;}',
      '.kt-tb-clock{font-family:ui-monospace,"SF Mono",Consolas,monospace;font-size:13px;font-weight:700;color:#334155;',
      'font-variant-numeric:tabular-nums;background:rgba(15,23,42,.05);padding:5px 10px;border-radius:9px;white-space:nowrap;}',
      '@keyframes kt-tb-in{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:none;}}',
      '@media(prefers-reduced-motion:reduce){.kt-topbar{animation:none;}}',
      '@media(max-width:960px){.kt-tb-date{display:none;}}',
      '@media(max-width:720px){.kt-tb-weather,.kt-tb-role{display:none;}.kt-tb-greet{font-size:13px;}}',
      '@media(max-width:520px){.kt-tb-clock{display:none;}}',
      '@media(max-width:600px){.kt-topbar{padding:6px 10px;gap:6px;}.kt-tb-emoji{display:none;}.kt-tb-greet{font-size:12px;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.kt-tb-ico{width:29px;height:29px;font-size:14px;}}',
      '@media(max-width:430px){.kt-tb-greet{display:none;}}',
      /* Fit levels (fitBar below): each hides the next least important thing. */
      '.kt-topbar.kt-tb-fit1 .kt-tb-date{display:none !important;}',
      '.kt-topbar.kt-tb-fit2 .kt-tb-weather,.kt-topbar.kt-tb-fit2 .kt-tb-sep{display:none !important;}',
      '.kt-topbar.kt-tb-fit3 .kt-tb-emoji{display:none !important;}.kt-topbar.kt-tb-fit3 .kt-tb-right{gap:5px;}',
      '.kt-topbar.kt-tb-fit4 .kt-tb-role{display:none !important;}',
      '.kt-topbar.kt-tb-fit5 #kt-tb-locale{width:100px !important;max-width:100px !important;padding-left:4px !important;padding-right:2px !important;}.kt-topbar.kt-tb-fit5 .kt-tb-ico{width:28px;height:28px;font-size:14px;}',
      '.kt-topbar.kt-tb-fit6 .kt-tb-greet{display:none !important;}',
      // Super-admin: View-as + agency switcher relocated into the bar (centre group)
      '.kt-tb-selectors{display:flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:center;flex:1 1 auto;min-width:0;}',
      '.kt-tb-ctl{display:flex;align-items:center;gap:6px;background:rgba(15,23,42,.05);border:1px solid rgba(15,23,42,.1);border-radius:9px;padding:3px 8px;}',
      '.kt-tb-ctl.kt-tb-ctl-active{background:rgba(124,58,237,.12);border-color:rgba(124,58,237,.32);}',
      '.kt-tb-ctl-lab{font-size:11px;font-weight:700;color:#475569;white-space:nowrap;}',
      '.kt-tb-ctl-sel{border:1px solid rgba(15,23,42,.15);border-radius:6px;padding:3px 6px;font-size:12px;font-family:inherit;background:#fff;color:#1E293B;cursor:pointer;max-width:160px;}',
      // Hide the sidebar originals once the bar owns these controls (super admin).
      'body.kt-tb-has-selectors #kt-view-as,body.kt-tb-has-selectors #appSidebar #kt-agency-switcher{display:none !important;}',
      '@media(max-width:900px){.kt-tb-selectors{display:none;}body.kt-tb-has-selectors #kt-view-as,body.kt-tb-has-selectors #appSidebar #kt-agency-switcher{display:revert !important;}}'
    ].join('');
    document.head.appendChild(s);
  }

  function iconBtn(emoji, title, fn, badgeId) {
    var b = document.createElement('button');
    // Custom tooltip (data-kttip) instead of the native `title` — native tooltips
    // are positioned by the browser (they showed ABOVE the icons here but BELOW for
    // other roles), so a CSS tooltip keeps them consistently below across roles.
    b.className = 'kt-tb-ico'; b.type = 'button'; b.setAttribute('data-kttip', title); b.setAttribute('aria-label', title);
    b.textContent = emoji;
    if (badgeId) { var bd = document.createElement('span'); bd.className = 'kt-tb-badge'; bd.id = badgeId; bd.hidden = true; b.appendChild(bd); }
    b.addEventListener('click', function (e) { e.preventDefault(); fn(); });
    return b;
  }

  // Language picker. Replaces the old Language SCREEN — changing your language is a
  // preference, not somewhere you navigate to. Posts to the same /locale endpoint the
  // screen used, then reloads so the new strings take effect.
  var LOCALES = [['en', 'English'], ['fr', 'Fran\u00e7ais'], ['es', 'Espa\u00f1ol'], ['hi', '\u0939\u093f\u0928\u094d\u0926\u0940']];
  function languagePicker() {
    var wrap = document.createElement('div');
    wrap.className = 'kt-tb-lang';
    wrap.style.cssText = 'display:flex;align-items:center;gap:4px;';

    var sel = document.createElement('select');
    sel.id = 'kt-tb-locale';
    sel.setAttribute('aria-label', 'Language');
    sel.title = 'Language';
    sel.style.cssText = 'border:1px solid rgba(15,23,42,.12);border-radius:8px;background:#fff;'
      + 'padding:4px 6px;font-size:12px;font-weight:600;color:#334155;cursor:pointer;max-width:120px;';

    var current = 'en';
    try { current = localStorage.getItem('kt_locale') || 'en'; } catch (e) {}
    LOCALES.forEach(function (l) {
      var o = document.createElement('option');
      o.value = l[0]; o.textContent = '\ud83c\udf10 ' + l[1];
      if (l[0] === current) o.selected = true;
      sel.appendChild(o);
    });

    sel.addEventListener('change', function () {
      var loc = sel.value;
      try { localStorage.setItem('kt_locale', loc); } catch (e) {}
      var t = store('kt_token');
      fetch(API + '/locale', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t },
        body: JSON.stringify({ locale: loc })
      }).catch(function () {}).then(function () {
        // Reload so every screen re-renders in English first, then kt-i18n translates the
        // fresh DOM. (Translating in place would leave already-translated nodes stale.)
        window.location.reload();
      });
    });

    wrap.appendChild(sel);

    // Reflect what the server actually has on file.
    var t = store('kt_token');
    if (t) {
      fetch(API + '/locale', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.locale) return;
          try { localStorage.setItem('kt_locale', d.locale); } catch (e) {}
          var el = document.getElementById('kt-tb-locale');
          if (el) el.value = d.locale;
        }).catch(function () {});
    }
    return wrap;
  }

  /* THE SAME BAR, PUT BACK BEFORE THE FRAME (2026-09-28).

     Anthony: "when pages load it resizes and then loads correctly".

     The bar lives inside #appMain, and almost every screen begins by overwriting
     #appMain — so the bar the shell mounted before the render was deleted by the render,
     and came back only when the sweep bus next ran, AFTER the screen had painted. Every
     navigation drew the screen, then pushed all of it down 62px. Measured on six screens.

     Two changes. The built bar is KEPT and re-inserted (same node: listeners, weather,
     badges and clock intact, so nothing inside it pops in a second time either). And a
     MutationObserver on #appMain puts it back from a microtask — which runs before the
     browser paints — instead of waiting for the bus. Rebuilt only when who it is for
     changes. */
  var _bar = null, _barKey = '';
  /* THE LIVE BAR, not any element with the id. During a background refresh the shell lays
     a clone of #appMain over the page (#kt-refresh-snap) and the clone carries its own
     id="kt-topbar". getElementById found THAT one, so ensure() and the remount observer
     decided the bar was mounted and did nothing: when the cover came off the real bar was
     missing — ~170ms on most refreshes, for good on a screen that had finished drawing
     under the cover (measured 2026-10-06). The live bar is a direct child of the live
     #appMain (getElementById('appMain') is the live one; the clone is appended after it).
     Walked rather than :scope, which some app WebViews lack. */
  function liveBar(host) {
    if (!host) return null;
    for (var i = 0; i < host.children.length; i++) { if (host.children[i].id === 'kt-topbar') return host.children[i]; }
    return null;
  }
  /* THE BAR FITS THE WINDOW IT IS IN (2026-10-29). Anthony: "the portal resizing based on size
     screens its not resizing properly" / "add logic to auto size properly". The bar's width
     depends on the role (how many icons), the sidebar, and things that arrive late (weather,
     badges), so fixed breakpoints could not keep it in: at 1024px the clock sat 17px past the
     edge and the page scrolled sideways; at 1400px the language picker covered the SUPER ADMIN
     pill. This measures instead: if anything in the bar overflows its box, hide the next least
     important item (date, weather, emoji, role pill, wide language box, greeting) and measure
     again. Starts from nothing hidden each time, so widening the window brings things back. */
  var FIT_MAX = 6;
  function barFits(bar) {
    if (bar.scrollWidth > bar.clientWidth + 1) return false;
    for (var c = bar.firstElementChild; c; c = c.nextElementSibling) {
      if (c.offsetParent && c.scrollWidth > c.clientWidth + 1) return false;
    }
    return true;
  }
  function fitBar() {
    var bar = _bar;
    if (!bar || !bar.isConnected || !bar.offsetParent) return;
    var cur = 0;
    for (var i = 1; i <= FIT_MAX; i++) { if (bar.classList.contains('kt-tb-fit' + i)) cur = i; }
    for (var j = 1; j <= FIT_MAX; j++) bar.classList.remove('kt-tb-fit' + j);
    var lvl = 0;
    while (lvl < FIT_MAX && !barFits(bar)) { lvl++; bar.classList.add('kt-tb-fit' + lvl); }
    void cur;
  }
  var _fitT = null;
  function fitSoon() { if (_fitT) return; _fitT = setTimeout(function () { _fitT = null; fitBar(); }, 120); }
  try { (window.KT = window.KT || {}).topbarFit = fitSoon; } catch (e) {}   // a button shown later (📻) re-fits the bar
  window.addEventListener('resize', fitSoon);

  function ensure() {
    var host = document.getElementById('appMain');
    if (!host || !store('kt_token') || liveBar(host)) return;
    var u = user();
    // Top bar is for admins / directors / super admins only. While a platform
    // admin previews a lower role via "View as", honour that (hide the bar too).
    var viewAs = ''; try { viewAs = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    // Admins / directors ONLY. Educators + home visitors already have their own
    // horizontal top nav (app-v2-shell), so rendering kt-topbar for them DOUBLED
    // the top bar — their notification bell is added to that nav instead.
    if (!isAdminRole(u) || (viewAs && ['educator', 'guardian', 'auditor', 'home_visitor', 'sales_rep'].indexOf(viewAs) !== -1)) return;
    var key = [(u && u.id) || '', (u && u.name) || '', (u && u.photo_url) || '', viewAs].join('|');
    if (_bar && _barKey === key) {
      host.insertBefore(_bar, host.firstChild);
      fitSoon();
      try {
        var d0 = _bar.querySelector('#kt-tb-date'); if (d0) d0.textContent = fmtDate();
        var c0 = _bar.querySelector('#kt-tb-clock'); if (c0) c0.textContent = fmtClock();
      } catch (e) {}
      return;
    }
    var effAdmin = true;
    injectStyle();
    var pod = greetEmoji(((window.KT && KT.agencyHM) ? KT.agencyHM() : { h: new Date().getHours() }).h);
    var bar = document.createElement('div');
    bar.id = 'kt-topbar'; bar.className = 'kt-topbar';

    var left = document.createElement('div'); left.className = 'kt-tb-left';
    // The user's avatar (photo → coloured initials) PLUS the time-of-day emoji.
    left.innerHTML = tbAvatarHtml(u) + '<span class="kt-tb-emoji">' + pod.e + '</span><span class="kt-tb-greet">' + ktT(pod.g) + ', <a class="kt-tb-name" title="Your profile">' + esc(firstName(u)) + '</a></span><span class="kt-tb-role">' + esc(roleLabel(u)) + '</span>';
    var _avaImg = left.querySelector('.kt-tb-ava img');
    if (_avaImg) _avaImg.onerror = function () { var sp = left.querySelector('.kt-tb-ava'); if (sp) { sp.className = 'kt-tb-ava kt-tb-ava--init'; sp.style.background = avaColour((u && u.name) || ''); sp.textContent = tbInitials(u); } };
    var avaClick = left.querySelector('.kt-tb-ava'); if (avaClick) { avaClick.style.cursor = 'pointer'; avaClick.title = 'Your profile & settings'; avaClick.addEventListener('click', function (e) { e.stopPropagation(); openProfileMenu(avaClick); }); }
    var nameEl = left.querySelector('.kt-tb-name'); if (nameEl) { nameEl.title = 'Your profile & settings'; nameEl.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openProfileMenu(nameEl); }); }

    var right = document.createElement('div'); right.className = 'kt-tb-right';
    right.appendChild(languagePicker());
    right.appendChild(iconBtn('🏠', 'Home', openHome));
    /* The dial pad, next to Home (Anthony, 2026-10-23: "add shortcut to dialpad as a popout
       at the top bar next to the home button"). kt-dialer.js opens it as a floating,
       resizable popup and hides this button for anyone not allowed to call. */
    var phoneBtn = iconBtn('📞', 'Phone · dial pad', function () { if (window.KT && window.KT.dialpad) window.KT.dialpad.toggle(); }, 'kt-tb-phone-badge');
    phoneBtn.id = 'kt-tb-phone'; phoneBtn.setAttribute('aria-pressed', 'false');
    right.appendChild(phoneBtn);
    /* The walkie-talkie, next to the phone (2026-11-28, Anthony: "add walkie-talkie to the top bar with a quick action
       for walkie-talkie functions"). kt-walkie-quick.js shows it once /walkie/me says the walkie is on for this person
       in this agency, opens its quick popover (hold to talk, who to, play the latest, Do not disturb) and keeps the
       unplayed count and the "someone is talking" glow on it. */
    var wtBtn = iconBtn('📻', 'Walkie-talkie', function () { if (window.KT && window.KT.walkieQuick) window.KT.walkieQuick.open(wtBtn); else location.hash = '#walkie'; }, 'kt-tb-walkie-badge');
    wtBtn.id = 'kt-tb-walkie'; wtBtn.style.display = 'none'; wtBtn.setAttribute('aria-haspopup', 'menu');
    right.appendChild(wtBtn);
    try { if (window.KT && window.KT.walkieQuick) window.KT.walkieQuick.paint(); } catch (e) {}
    if (effAdmin) right.appendChild(iconBtn('⚡', 'Quick add', openQuickAdd));
    right.appendChild(iconBtn('💬', 'Message Centre', function () { go('#chat'); }, 'kt-tb-msg-badge'));
    // The email client for admins and directors; the sales@ mailbox for whoever may open it (sales team, superadmins).
    /* Each with its inbox unread count (2026-11-04, from /badges). 📮 for a super admin in ANY agency: inside an agency
       the cached roles are that agency's (agency_admin), so is_platform_admin and the badge feed's
       sales_email_access decide too. */
    right.appendChild(iconBtn('✉️', 'Email client', function () { go('#email'); }, 'kt-tb-mail-badge'));
    var salesBtn = iconBtn('📮', 'Sales email', function () { go('#sales-email'); }, 'kt-tb-salesmail-badge');
    salesBtn.id = 'kt-tb-salesmail';
    if (!(((u && u.roles) || []).some(function (r) { return r === 'sales_rep' || r === 'platform_admin'; }) || (u && u.is_platform_admin) || (AGENCIES && AGENCIES.is_platform_admin))) salesBtn.style.display = 'none';
    right.appendChild(salesBtn);
    // Its unread count (2026-11-09): the 📣 had no badge at all, so unread announcements never showed up here.
    right.appendChild(iconBtn('📣', 'Announcements', function () { go('#announcements'); }, 'kt-tb-ann-badge'));
    // Notification bell for EVERY bar role. Admins/directors get the agency
    // activity-feed dropdown; educators/home visitors get their OWN inbox (the
    // activity-feed endpoint is admin-only), so their bell opens #notifications.
    if (effAdmin) {
      right.appendChild(iconBtn('🔔', 'Notifications', toggleNotif, 'kt-tb-notif-badge')); startNotif();
    } else {
      right.appendChild(iconBtn('🔔', 'Notifications', function () { go('#notifications'); }, 'kt-tb-notif-badge')); startStaffNotif();
    }
    // Calculator (kt-calculator.js): the popup, and Alt+C / ⌥C from anywhere.
    var calcBtn = iconBtn('🧮', 'Calculator (Alt+C)', function () { if (window.KT && window.KT.toggleCalculator) window.KT.toggleCalculator(); });
    calcBtn.id = 'kt-tb-calc'; calcBtn.setAttribute('aria-pressed', 'false');
    right.appendChild(calcBtn);
    /* Quick notes moved to the right panel (2026-11-28, Anthony: "add quick notes to the side right bar where the popout
       calendar is and remove from the top bar"): its own icon on the panel's rail (kt-right-panel.js). Below the
       panel's width, the Notes screen in the menu and the phone drawer (KT.quickNotes.toggle) still reach it. */
    /* ONE AI BUTTON (Anthony, 2026-11-03: "should be combined into one on the top bar"). It was 🤖 (the help
       assistant) beside ✨ (Ask KiddieTrac). Decided at the tap, not when the bar is drawn, because kt-ask.js can load
       after the first bar: an admin or director with the AI Bundle gets the assistant that answers both "who owes
       money?" and "how do I…?"; everyone else gets the help assistant as before. */
    right.appendChild(iconBtn('🤖', 'AI assistant', function () {
      if (window.KT && window.KT.ask && window.KT.ask.available && window.KT.ask.available()) { window.KT.ask.open(); return; }
      openAI();
    }));
    if (effAdmin) right.appendChild(iconBtn('🎁', "What's new", function () { if (window.KT && window.KT.openWhatsNew) window.KT.openWhatsNew(); }, 'kt-tb-wn-badge'));
    var sep = document.createElement('div'); sep.className = 'kt-tb-sep'; right.appendChild(sep);
    var wx = document.createElement('span'); wx.className = 'kt-tb-weather'; wx.id = 'kt-tb-weather'; wx.style.display = 'none'; right.appendChild(wx);
    var dt = document.createElement('span'); dt.className = 'kt-tb-date'; dt.id = 'kt-tb-date'; dt.textContent = fmtDate(); right.appendChild(dt);
    var clock = document.createElement('span'); clock.className = 'kt-tb-clock'; clock.id = 'kt-tb-clock'; clock.textContent = fmtClock(); right.appendChild(clock);

    bar.appendChild(left);
    /* NO View-as / agency selector in the top bar any more.

       These were a second copy of the two sidebar controls: the bar built its own pair
       into #kt-tb-selectors and hid the sidebar originals via body.kt-tb-has-selectors,
       reversing it only below 900px. Anthony asked for them "at the bottom of the sidebar
       below my name to clean this up", and the header pair was what he was actually
       looking at.

       Not moved — REMOVED, so there is one implementation instead of two that had already
       drifted apart. Without this host, buildSelectors() returns immediately and never
       sets the flag, so the sidebar pair is visible at every width.

       buildSelectors() and its CSS are left intact below: restoring the header copies is
       putting this one line back. */
    void isPlatformAdmin;
    bar.appendChild(right);
    host.insertBefore(bar, host.firstChild);
    _bar = bar; _barKey = key;
    buildSelectors();

    loadWeather(function (txt) { var w = document.getElementById('kt-tb-weather'); if (w && txt) { w.textContent = txt; w.style.display = ''; fitSoon(); } });
    refreshUnread();
    fitBar();
  }

  /* Unread mail on ✉️ / 📮 (2026-11-04, Anthony: "if tehre is new mail add the unread notifications as well to the top
     bar for the email clients"). Counts come from /badges (App\Support\MailUnread); 📮 appears once the feed says
     this person may open Sales -> Email. */
  function paintMail(b) {
    if (!b) return;
    function put(id, n) {
      var bd = document.getElementById(id); if (!bd) return;
      n = parseInt(n, 10) || 0;
      if (n > 0) { bd.textContent = n > 99 ? '99+' : n; bd.hidden = false; } else { bd.hidden = true; }
      var btn = bd.parentNode; if (btn) btn.setAttribute('aria-label', btn.getAttribute('data-kttip') + (n > 0 ? ', ' + n + ' unread' : ''));
    }
    put('kt-tb-ann-badge', b.announcements);   // the Announcements screen's own unread (App\Support\AnnouncementInbox)
    put('kt-tb-mail-badge', b.email);
    put('kt-tb-salesmail-badge', b.sales_email);
    var sb = document.getElementById('kt-tb-salesmail');
    if (sb && b.sales_email_access && sb.style.display === 'none') { sb.style.display = ''; fitSoon(); }
  }

  function refreshUnread() {
    var t = store('kt_token'); if (!t) return;
    // Accurate UNREAD MESSAGES only (not general notifications like birthdays/tours).
    /* ALL MESSAGES, NOT FAMILY CHATS ALONE (2026-11-01, Anthony: "why doesnt the browser when logged in show that I have
     new notifications or chat messages"). This read b.chats - family conversations only - so a staff team message or a
     website chat moved KT.badges and nothing on screen. KT.badges.msgs() is the one sum of every kind. */
    (window.KT && KT.badges ? KT.badges.get().then(function (b) { paintMail(b); return b ? { unread: (KT.badges.msgs ? KT.badges.msgs(b) : b.chats) } : null; })
      : fetch(API + '/chats/unread-count', { headers: { 'Authorization': 'Bearer ' + t } }).then(function (r) { return r.ok ? r.json() : null; }))
      .then(function (d) {
        var n = 0;
        if (d) n = (d.unread_count != null ? d.unread_count : (d.count != null ? d.count : (d.unread != null ? d.unread : 0)));
        n = parseInt(n, 10) || 0;
        var bd = document.getElementById('kt-tb-msg-badge'); if (!bd) return;
        if (n > 0) { bd.textContent = n > 99 ? '99+' : n; bd.hidden = false; } else { bd.hidden = true; }
      }).catch(function () {});
  }

  // Refresh the signed-in user so the sidebar avatar reflects the latest photo.
  /* ALSO MID-SESSION, NOT ONLY AT BOOT (2026-11-26, Anthony: added the HR add-on to iLearn, opened HR and got
     "Not included in this plan"). This ran once per page load, so a licence or feature change made while the tab
     was open never reached it: the menu, the tiles and the route guard kept the old plan until a reload (proven on
     agency 6: a test add-on line on, the server's hidden list dropped the screen, the open tab still showed 🔒 after
     38 s and a re-navigation, and never asked /auth/me). Now it also runs on kt:plan-changed (kt-badges.js, when
     GET /badges plan_v moves - App\Support\PlanStamp) and from KT.refreshPlan (the 🔒 screen's re-check, an
     in-place agency switch). opts.now = redraw at once (the person made the change, or is looking at a 🔒);
     otherwise the redraw waits for their next navigation, so nobody loses a half-typed form to a reload.
     Resolves {changed, reloading}. */
  var __meBusy = null;
  var __planReloadArmed = false;
  function refreshUser(opts) {
    opts = opts || {};
    if (__meBusy) return __meBusy.then(function (r) { if (opts.now && r && r.changed && !r.reloading) { planRedraw(true); r.reloading = true; } return r; });
    var res = { changed: false, reloading: false };
    __meBusy = refreshUserNow(opts, res).then(function () { __meBusy = null; return res; }, function () { __meBusy = null; return res; });
    return __meBusy;
  }
  /* Redraw with the new plan: now, or on the next navigation. Reloading is how every surface (sidebar, phone bars,
     right panel, tiles, search, the native shell's web view) rebuilds from the one fresh kt_user. Not twice a
     minute - a server answer that flip-flops must never become a reload loop; then it waits for the next navigation. */
  function planRedraw(now) {
    var last = 0;
    try { last = +(sessionStorage.getItem('kt_plan_reload') || 0); } catch (e) {}
    if (now && Date.now() - last > 60000) {
      try { sessionStorage.setItem('kt_plan_reload', String(Date.now())); } catch (e) {}
      window.location.reload();
      return true;
    }
    if (!__planReloadArmed) {
      __planReloadArmed = true;
      window.addEventListener('hashchange', function () {
        try { sessionStorage.setItem('kt_plan_reload', String(Date.now())); } catch (e) {}
        window.location.reload();
      }, { once: true });
    }
    return false;
  }
  window.KT = window.KT || {};
  window.KT.refreshPlan = function (opts) { return refreshUser(opts || {}); };
  window.addEventListener('kt:plan-changed', function (e) {
    try { refreshUser({ now: !!(e && e.detail && e.detail.now) }); } catch (x) {}
  });
  function refreshUserNow(opts, res) {
    var t = store('kt_token'); if (!t) return Promise.resolve();
    /* The agency in view, so the plan answer below is that agency's (a super admin switches agencies). */
    var meHdrs = { 'Authorization': 'Bearer ' + t };
    var meAg = null;
    try { meAg = sessionStorage.getItem('kt_active_agency_id'); } catch (e) {}
    if (meAg && /^[0-9]+$/.test(meAg)) meHdrs['X-Active-Agency-Id'] = meAg;
    return fetch(API + '/auth/me', { headers: meHdrs, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var fresh = d && (d.user || d); if (!fresh) return;
        var cur = user();

        /* ROLES, NOT JUST THE PHOTO.
           This already asked /auth/me on every boot and then used one field from the
           answer. The client's entire nav and screen routing is driven by the cached
           kt_user.roles, and nothing ever refreshed it — so a role granted or revoked did
           not take effect until the person signed out and back in. The API enforces the
           truth either way, so a revoked admin kept being shown staff screens whose every
           request came back 403: one account on this platform produced 55 forbidden
           /provider/* calls over three days that way, filling their own audit log with
           failures for a UI they could no longer use.
           (Anthony, 2026-09-08) */
        var a = Array.isArray(fresh.roles) ? fresh.roles.slice().sort().join(',') : null;
        var b = Array.isArray(cur.roles) ? cur.roles.slice().sort().join(',') : null;
        /* ABSENCE IS NOT DISAGREEMENT. A stored user with no roles array at all — an
           older session, or one seeded by a path that saved a slimmer object — is not
           somebody whose access changed, and signing them out would be a logout for no
           reason mid-shift. Adopt the server's answer quietly and carry on; only a
           cached list that actually CONTRADICTS the server ends the session. */
        if (a !== null && b === null) {
          cur.roles = fresh.roles;
          if (typeof fresh.is_platform_admin !== 'undefined') { cur.is_platform_admin = fresh.is_platform_admin; }
          try { sessionStorage.setItem('kt_user', JSON.stringify(cur)); } catch (e) {}
        } else if (a !== null && a !== b) {
          /* SIGN THEM OUT. Reloading with the corrected roles was the first answer, but a
             role change is usually somebody being given or taken off access, and carrying
             a half-updated session through that is how a person ends up looking at a menu
             they no longer have. A fresh sign-in shows exactly what they may now do.
             The server ends the session too (App\Support\RoleChange), so this is the
             visible half of it, not the enforcement. (Anthony, 2026-09-08) */
          try {
            var msg = 'Your access has changed. Please sign in again.';
            // A tab-only session leaves the owner's shared sign-in alone (app.js ownsSharedSession, 2026-11-13).
            var shared = true;
            try { if (window.KT && KT.ownsSharedSession) shared = KT.ownsSharedSession(); } catch (e2) {}
            try { if (window.KT && KT.markSignedOut) KT.markSignedOut(); } catch (e2) {}   // before the session store goes (reads kt_tenant)
            sessionStorage.clear();
            if (shared) {
              localStorage.removeItem('kt_token');
              localStorage.removeItem('kt_user');
            }
            try { sessionStorage.setItem('kt_signout_reason', msg); } catch (e2) {}
          } catch (e) {}
          if (window.KT_out) window.KT_out.go('/index.html?reason=roles-changed'); else window.location.href = '/index.html?reason=roles-changed';
          return;
        }

        /* WAITING FOR THE AGENCY, OR NOT ANY MORE (2026-10-21). A parent not yet linked to
           a family sees a cut-down app (family_pending). The moment the agency links them —
           or for a session signed in before the flag existed — adopt the server's answer and
           redraw, so nobody has to sign out to see their family. */
        if (typeof fresh.family_pending === 'boolean' && fresh.family_pending !== !!cur.family_pending) {
          cur.family_pending = fresh.family_pending;
          cur.pending_agency = fresh.pending_agency || null;
          // Reload only once the new answer is stored, or it would reload forever.
          try {
            sessionStorage.setItem('kt_user', JSON.stringify(cur));
            if (!sessionStorage.getItem('kt_view_as')) { window.location.reload(); return; }
          } catch (e) {}
        }

        /* THE PLAN AND ADD-ONS TAKE EFFECT WITHOUT A NEW SIGN-IN (2026-11-26, Anthony: "when agency is setup and
           addon's are added does this reflect the addon's and functionality per agency setup correctly"). The menu,
           the tiles and every "✨ AI" button read hidden_screens / ai_bundle from the user saved AT SIGN-IN, and
           nothing refreshed them: an add-on bought (or a feature switched off) showed only after signing out and
           back in, and in the meantime the API - which follows the licence at once - refused what the menu still
           offered. Every boot already asks /auth/me; adopt its plan answer, and redraw once when it changed.
           "All agencies" (a super admin's client-side scope) has no one agency to ask about, so it keeps its list. */
        if (Array.isArray(fresh.hidden_screens) && meAg !== 'all') {
          var featSig = function (u) {
            return JSON.stringify([(Array.isArray(u.hidden_screens) ? u.hidden_screens.slice().sort() : null),
              !!u.ai_bundle, !!u.play_spark, !!u.licence_lapsed, u.licence_label || null,
              Object.keys((u.locked_screens && typeof u.locked_screens === 'object') ? u.locked_screens : {}).sort()]);
          };
          /* The fingerprint this answer was computed with (PlanStamp), kept even when nothing visible changed, so
             kt-badges stops asking until the server's plan_v moves again. */
          if (fresh.plan_v && fresh.plan_v !== cur.plan_v && featSig(fresh) === featSig(cur)) {
            cur.plan_v = fresh.plan_v;
            try { sessionStorage.setItem('kt_user', JSON.stringify(cur)); } catch (e) {}
          }
          if (featSig(fresh) !== featSig(cur)) {
            var hadPlan = Array.isArray(cur.hidden_screens);
            cur.plan_v = fresh.plan_v || null;
            res.changed = true;
            cur.hidden_screens = fresh.hidden_screens;
            cur.ai_bundle = !!fresh.ai_bundle;
            cur.play_spark = !!fresh.play_spark;   // the Play Spark licence (2026-11-26)
            cur.licence_lapsed = !!fresh.licence_lapsed;
            cur.licence_label = fresh.licence_label || null;
            cur.locked_screens = fresh.locked_screens || {};
            var savedPlan = false;
            try { sessionStorage.setItem('kt_user', JSON.stringify(cur)); savedPlan = true; } catch (e) {}
            try {
              var lu = JSON.parse(localStorage.getItem('kt_user') || 'null');
              if (lu && lu.id === cur.id) {
                lu.hidden_screens = cur.hidden_screens; lu.ai_bundle = cur.ai_bundle; lu.play_spark = cur.play_spark;
                lu.licence_lapsed = cur.licence_lapsed; lu.licence_label = cur.licence_label; lu.locked_screens = cur.locked_screens;
                lu.plan_v = cur.plan_v;
                localStorage.setItem('kt_user', JSON.stringify(lu));
              }
            } catch (e) {}
            // Redraw with the new menu - only when stored (or it would reload forever). At boot or on request: now;
            // a change noticed by the badges poll: on the next navigation (planRedraw).
            if (hadPlan && savedPlan) {
              if (planRedraw(opts.boot || opts.now)) { res.reloading = true; return; }
            }
          }
        }

        if (fresh.photo_url && fresh.photo_url !== cur.photo_url) {
          cur.photo_url = fresh.photo_url;
          try { sessionStorage.setItem('kt_user', JSON.stringify(cur)); } catch (e) {}
          paintSidebarAvatar(cur);
          paintTopbarAvatar(cur);
        }
      }).catch(function () {});
  }
  /* A 403 on an avatar is an EXPIRED SIGNATURE, not a missing photo — see the note in
     app-v2-shell.js. Neither painter here had an onerror at all, so a stale link left a
     broken-image glyph in the pill with nothing to recover it. One retry against a fresh
     link, then the initials. (2026-09-17) */
  function ktPhotoRetry(img, onGiveUp) {
    img.onerror = function () {
      if (img.getAttribute('data-kt-retried')) { if (onGiveUp) { onGiveUp(); } return; }
      img.setAttribute('data-kt-retried', '1');
      if (!(window.KT && KT.refreshUserPhoto)) { if (onGiveUp) { onGiveUp(); } return; }
      KT.refreshUserPhoto().then(function (fresh) {
        if (!fresh) { if (onGiveUp) { onGiveUp(); } return; }
        img.src = /^https?:\/\//i.test(fresh) ? fresh : (API.replace(/\/api\/v1\/?$/, '') + fresh);
      });
    };
  }

  function paintSidebarAvatar(u) {
    var av = document.getElementById('navAvatar'); if (!av || !u.photo_url) return;
    var apiHost = API.replace(/\/api\/v1\/?$/, '');
    var src = /^https?:\/\//i.test(u.photo_url) ? u.photo_url : (apiHost + u.photo_url);
    av.textContent = '';
    var img = document.createElement('img');
    img.alt = u.name || '';
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
    ktPhotoRetry(img, function () {
      if (img.parentNode) { img.parentNode.removeChild(img); }
      av.textContent = tbInitials(u);
    });
    img.src = src;
    av.appendChild(img);
  }
  // ── Top-bar user avatar (photo → coloured initials) ──────────────────
  function tbInitials(u) {
    var n = ((u && u.name) || '').trim(); if (!n) return '•';
    var p = n.split(/\s+/); return (((p[0] || '')[0] || '') + ((p[1] || '')[0] || '')).toUpperCase() || '•';
  }
  function avaColour(s) {
    s = String(s || ''); var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    var pal = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
    return pal[h % pal.length];
  }
  function tbAvatarHtml(u) {
    if (u && u.photo_url) {
      var src = /^https?:\/\//i.test(u.photo_url) ? u.photo_url : (API.replace(/\/api\/v1\/?$/, '') + u.photo_url);
      return '<span class="kt-tb-ava"><img decoding="sync" src="' + esc(src) + '" alt=""></span>';
    }
    return '<span class="kt-tb-ava kt-tb-ava--init" style="background:' + avaColour((u && u.name) || '') + '">' + esc(tbInitials(u)) + '</span>';
  }
  function paintTopbarAvatar(u) {
    var sp = document.querySelector('#kt-topbar .kt-tb-ava'); if (!sp || !u || !u.photo_url) return;
    var apiHost = API.replace(/\/api\/v1\/?$/, '');
    var src = /^https?:\/\//i.test(u.photo_url) ? u.photo_url : (apiHost + u.photo_url);
    sp.className = 'kt-tb-ava'; sp.style.background = '';
    sp.innerHTML = '<img decoding="sync" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
    var tbImg = sp.querySelector('img');
    ktPhotoRetry(tbImg, function () {
      sp.className = 'kt-tb-ava kt-tb-ava--init';
      sp.style.background = avaColour((u && u.name) || '');
      sp.textContent = tbInitials(u);
    });
    tbImg.src = src;
  }

  // Let the shell mount the bar the instant it clears #appMain, instead of leaving it
  // missing until the 1.2s poll below fires. Clearing the screen takes the bar with it,
  // so on every navigation the bar disappeared and popped back in AFTER the banner had
  // already drawn — that is the jank. ensure() is a no-op when the bar is present.
  window.KT = window.KT || {};
  window.KT.topbar = { ensure: ensure };

  /* Before-paint remount (see ensure()). CAPPED: a screen that re-renders on any
     #appMain mutation (Support tickets did) would otherwise wipe the bar, see this put it
     back, and wipe it again forever. More than 8 remounts in 2s = stand down to the bus
     for the rest of that window. */
  (function () {
    var stamps = [];
    function onMain() {
      if (liveBar(document.getElementById('appMain'))) return;
      var now = Date.now();
      stamps = stamps.filter(function (t) { return now - t < 2000; });
      if (stamps.length >= 8) return;
      stamps.push(now);
      try { ensure(); } catch (e) {}
    }
    try {
      if (window.KT && KT.observeMain) { KT.observeMain(onMain, { childList: true }); }
    } catch (e) {}
  })();

  setInterval(function () { var c = document.getElementById('kt-tb-clock'); if (c) c.textContent = fmtClock(); var d = document.getElementById('kt-tb-date'); if (d) d.textContent = fmtDate(); }, 15000);
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(ensure) : setInterval(ensure, 1200);   // safety net; the shell now calls ensure() on every render
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(buildSelectors) : setInterval(buildSelectors, 1400);   // rebuild View-as + agency switcher in the bar after any nav
  setInterval(refreshUnread, 15000);   // near-real-time unread badge
  /* REAL TIME (kt-badges.js, 2026-10-30): repaint the moment the counts change - a read here, in another tab, a
     resume - and when the inbox is opened anywhere, the agency activity also counts as seen. */
  // Only while this bar is on screen: in a parent or educator view it is not drawn, and the admin feed must not be asked for.
  window.addEventListener('kt:badges', function () { try { if (!document.getElementById('kt-topbar')) return; refreshUnread(); if (__staffNotifStarted) refreshStaffNotifBadge(); else refreshNotifBadge(false); } catch (e) {} });
  window.addEventListener('kt:badges-seen', function () {
    setNotifBadge(0);   // at once; the next repaint confirms it
    try { if (__feedMemo && __feedMemo.d && __feedMemo.d.events && __feedMemo.d.events[0] && __feedMemo.d.events[0].when) setNotifSeen(__feedMemo.d.events[0].when); } catch (e) {}
  });
  // No lag on return: refresh the bell + unread the instant the tab/app is
  // re-focused, instead of waiting up to a full poll interval.
  function refreshBellBadge() { try { if (__staffNotifStarted) refreshStaffNotifBadge(); else refreshNotifBadge(); } catch (e) {} }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { refreshBellBadge(); try { refreshUnread(); } catch (e) {} } });
  window.addEventListener('focus', function () { refreshBellBadge(); try { refreshUnread(); } catch (e) {} });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { ensure(); refreshUser({ boot: true }); });
  else { ensure(); refreshUser({ boot: true }); }
  /* Exposed so the admin launcher can build its own pair on a phone, where this
     bar is hidden by kt-mobile-app.css. Same builders, so the two copies can
     never disagree about what a role or an agency switch does. */
  window.KT = window.KT || {};
  window.KT.Topbar = { buildViewAs: buildViewAs, buildAgency: buildAgency };
  /* Exposed so an arriving push can move the bell AT ONCE instead of waiting up to a
     full 15s poll. "Realtime" is what a person expects of a notification indicator, and
     the push is the earliest moment the news exists on the device. */
  window.KT.refreshBell = function () {
    // KT.badges.get() alone hands back the last answer for up to 10 s - the push would have changed nothing (2026-11-05).
    if (window.KT.badges && window.KT.badges.refresh) {
      window.KT.badges.refresh().then(function () { refreshBellBadge(); try { refreshUnread(); } catch (e) {} });
      return;
    }
    refreshBellBadge(); try { refreshUnread(); } catch (e) {}
  };
})();
