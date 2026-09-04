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

  var ROLE = { platform_admin: 'Super Admin', agency_admin: 'Agency Admin', centre_director: 'Centre Director', educator: 'Educator', guardian: 'Parent', home_visitor: 'Home Visitor', sales_rep: 'Sales Rep', auditor: 'Auditor' };
  function roleLabel(u) {
    var roles = (u && u.roles) || [];
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
        window.location.reload();
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
    var d = new Date(), h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM', hh = h % 12; if (hh === 0) hh = 12;
    return hh + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap;
  }
  function wxEmoji(c) {
    if (c === 0) return '☀️'; if (c <= 2) return '🌤'; if (c === 3) return '☁️';
    if (c === 45 || c === 48) return '🌫'; if (c >= 51 && c <= 67) return '🌧';
    if (c >= 71 && c <= 77) return '❄️'; if (c >= 80 && c <= 82) return '🌦';
    if (c >= 85 && c <= 86) return '🌨'; if (c >= 95) return '⛈'; return '🌡';
  }
  function loadWeather(cb) {
    try { var c = sessionStorage.getItem('kt_wx'); if (c) { var o = JSON.parse(c); if (o && o.t && (Date.now() - o.t < 1800000)) { cb(o.txt); return; } } } catch (e) {}
    var done = function (txt) { try { sessionStorage.setItem('kt_wx', JSON.stringify({ t: Date.now(), txt: txt })); } catch (e) {} cb(txt); };
    fetch('https://ipapi.co/json/').then(function (r) { return r.json(); }).then(function (loc) {
      return fetch('https://api.open-meteo.com/v1/forecast?latitude=' + loc.latitude + '&longitude=' + loc.longitude + '&current=temperature_2m,weather_code')
        .then(function (r) { return r.json(); }).then(function (w) { done(wxEmoji(w.current.weather_code) + ' ' + Math.round(w.current.temperature_2m) + '°C'); });
    }).catch(function () { cb(null); });
  }

  function openQuickAdd() { var f = document.querySelector('.kt-qa-fab'); if (f) f.click(); }
  function openAI() { var h = document.getElementById('kt-floating-help'); if (h) { h.click(); return; } try { if (window.KT && typeof window.KT.openChatbot === 'function') return window.KT.openChatbot(); } catch (e) {} var f = document.getElementById('kt-chatbot-fab'); if (f) f.click(); }
  function go(hash) { location.hash = hash; }

  // ── Notification / activity feed (admins + directors; platform_admin sees ALL
  //    agencies, everyone else only their own — the backend isolates it). ────────
  function notifSeen() { try { return localStorage.getItem('kt_notif_seen') || ''; } catch (e) { return ''; } }
  function setNotifSeen(v) { try { if (v) localStorage.setItem('kt_notif_seen', v); } catch (e) {} }
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
    fetch(API + '/admin/activity-feed', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; }).then(cb).catch(function () { cb(null); });
  }
  function setNotifBadge(n) {
    var b = document.getElementById('kt-tb-notif-badge'); if (!b) return;
    if (n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.hidden = false; } else { b.hidden = true; }
  }
  function refreshNotifBadge() {
    loadFeed(function (d) {
      if (!d || !d.events) return;
      var seen = notifSeen();
      var unread = d.events.filter(function (e) { return e.when && (!seen || e.when > seen); }).length;
      setNotifBadge(unread);
    });
  }
  var __notifStarted = false;
  function startNotif() {
    if (__notifStarted) return; __notifStarted = true;
    /* Immediately when the token is already there — the 1500ms wait was to let it
       land, but the bar is built after sign-in, so it only left the bell blank.
       The delayed attempt stays as a fallback for a bar built before storage. */
    if (store('kt_token')) { refreshNotifBadge(); }
    setTimeout(refreshNotifBadge, 1500);
    setInterval(refreshNotifBadge, 15000);
  }
  // Staff (educator / home visitor) bell badge — unread count from THEIR own
  // notifications inbox (role-relevant notifications addressed to them).
  var __staffNotifStarted = false;
  function refreshStaffNotifBadge() {
    var t = store('kt_token'); if (!t) return;
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
  function toggleNotif() {
    if (notifPanel) { closeNotif(); return; }
    notifPanel = document.createElement('div');
    notifPanel.id = 'kt-notif-panel';
    notifPanel.style.cssText = 'position:fixed;top:52px;right:14px;width:360px;max-width:calc(100vw - 20px);max-height:70vh;background:#fff;border:1px solid #E5E7EB;border-radius:14px;box-shadow:0 20px 50px -12px rgba(15,23,42,.4);z-index:10050;display:flex;flex-direction:column;overflow:hidden;font-family:inherit;';
    notifPanel.innerHTML = '<div style="padding:13px 16px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">'
      + '<strong style="font-size:14.5px;color:#0D1B2A;">🔔 Notifications</strong>'
      + '<span id="kt-notif-scope" style="font-size:11px;color:#94A3B8;font-weight:600;"></span></div>'
      + '<div id="kt-notif-list" style="overflow-y:auto;padding:6px 0;"><div style="padding:24px;text-align:center;color:#94A3B8;font-size:13px;">Loading…</div></div>';
    document.body.appendChild(notifPanel);
    setTimeout(function () { document.addEventListener('click', outsideNotif, true); }, 0);
    loadFeed(function (d) {
      var list = document.getElementById('kt-notif-list'); if (!list) return;
      var scopeEl = document.getElementById('kt-notif-scope');
      if (scopeEl && d && d.scope === 'all') scopeEl.textContent = 'Across all agencies';
      var events = (d && d.events) || [];
      if (!events.length) { list.innerHTML = '<div style="padding:28px;text-align:center;color:#94A3B8;font-size:13px;">Nothing new yet.</div>'; return; }
      var seen = notifSeen();
      list.innerHTML = '';
      events.forEach(function (e) {
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
          iconHtml = '<img src="' + esc(av) + '" alt="" onerror="this.style.display=\'none\';if(this.nextSibling)this.nextSibling.style.display=\'\';" style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;display:block;">'
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
      if (events[0] && events[0].when) setNotifSeen(events[0].when);
      setNotifBadge(0);
    });
  }
  function openProfile() {
    // Show the user's own account record (view + edit their details/roles) rather
    // than re-running the full onboarding wizard. Falls back to the profile editor
    // if the account view isn't available (e.g. non-admin roles).
    if (window.KT && typeof KT.openMyAccount === 'function') { KT.openMyAccount(); return; }
    location.hash = '#onboarding';
  }
  function openHome() { location.hash = '#dashboard'; }
  function fmtDate() { try { return new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' }); } catch (e) { return ''; } }

  function injectStyle() {
    if (document.getElementById('kt-topbar-style')) return;
    var s = document.createElement('style'); s.id = 'kt-topbar-style';
    s.textContent = [
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

  function ensure() {
    var host = document.getElementById('appMain');
    if (!host || !store('kt_token') || document.getElementById('kt-topbar')) return;
    var u = user();
    // Top bar is for admins / directors / super admins only. While a platform
    // admin previews a lower role via "View as", honour that (hide the bar too).
    var viewAs = ''; try { viewAs = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    // Admins / directors ONLY. Educators + home visitors already have their own
    // horizontal top nav (app-v2-shell), so rendering kt-topbar for them DOUBLED
    // the top bar — their notification bell is added to that nav instead.
    if (!isAdminRole(u) || (viewAs && ['educator', 'guardian', 'auditor', 'home_visitor', 'sales_rep'].indexOf(viewAs) !== -1)) return;
    var effAdmin = true;
    injectStyle();
    var pod = greetEmoji(new Date().getHours());
    var bar = document.createElement('div');
    bar.id = 'kt-topbar'; bar.className = 'kt-topbar';

    var left = document.createElement('div'); left.className = 'kt-tb-left';
    // The user's avatar (photo → coloured initials) PLUS the time-of-day emoji.
    left.innerHTML = tbAvatarHtml(u) + '<span class="kt-tb-emoji">' + pod.e + '</span><span class="kt-tb-greet">' + pod.g + ', <a class="kt-tb-name" title="Your profile">' + esc(firstName(u)) + '</a></span><span class="kt-tb-role">' + esc(roleLabel(u)) + '</span>';
    var _avaImg = left.querySelector('.kt-tb-ava img');
    if (_avaImg) _avaImg.onerror = function () { var sp = left.querySelector('.kt-tb-ava'); if (sp) { sp.className = 'kt-tb-ava kt-tb-ava--init'; sp.style.background = avaColour((u && u.name) || ''); sp.textContent = tbInitials(u); } };
    var avaClick = left.querySelector('.kt-tb-ava'); if (avaClick) { avaClick.style.cursor = 'pointer'; avaClick.title = 'Your profile'; avaClick.addEventListener('click', function () { openProfile(); }); }
    var nameEl = left.querySelector('.kt-tb-name'); if (nameEl) nameEl.addEventListener('click', function (e) { e.preventDefault(); openProfile(); });

    var right = document.createElement('div'); right.className = 'kt-tb-right';
    right.appendChild(languagePicker());
    right.appendChild(iconBtn('🏠', 'Home', openHome));
    if (effAdmin) right.appendChild(iconBtn('⚡', 'Quick add', openQuickAdd));
    right.appendChild(iconBtn('💬', 'Messenger', function () { go('#chat'); }, 'kt-tb-msg-badge'));
    right.appendChild(iconBtn('📣', 'Announcements', function () { go('#announcements'); }));
    // Notification bell for EVERY bar role. Admins/directors get the agency
    // activity-feed dropdown; educators/home visitors get their OWN inbox (the
    // activity-feed endpoint is admin-only), so their bell opens #notifications.
    if (effAdmin) {
      right.appendChild(iconBtn('🔔', 'Notifications', toggleNotif, 'kt-tb-notif-badge')); startNotif();
    } else {
      right.appendChild(iconBtn('🔔', 'Notifications', function () { go('#notifications'); }, 'kt-tb-notif-badge')); startStaffNotif();
    }
    right.appendChild(iconBtn('🤖', 'AI assistant', openAI));
    if (effAdmin) right.appendChild(iconBtn('🎁', "What's new", function () { if (window.KT && window.KT.openWhatsNew) window.KT.openWhatsNew(); }, 'kt-tb-wn-badge'));
    var sep = document.createElement('div'); sep.className = 'kt-tb-sep'; right.appendChild(sep);
    var wx = document.createElement('span'); wx.className = 'kt-tb-weather'; wx.id = 'kt-tb-weather'; wx.style.display = 'none'; right.appendChild(wx);
    var dt = document.createElement('span'); dt.className = 'kt-tb-date'; dt.id = 'kt-tb-date'; dt.textContent = fmtDate(); right.appendChild(dt);
    var clock = document.createElement('span'); clock.className = 'kt-tb-clock'; clock.id = 'kt-tb-clock'; clock.textContent = fmtClock(); right.appendChild(clock);

    bar.appendChild(left);
    if (isPlatformAdmin(u)) { var selBox = document.createElement('div'); selBox.id = 'kt-tb-selectors'; selBox.className = 'kt-tb-selectors'; bar.appendChild(selBox); }
    bar.appendChild(right);
    host.insertBefore(bar, host.firstChild);
    buildSelectors();

    loadWeather(function (txt) { var w = document.getElementById('kt-tb-weather'); if (w && txt) { w.textContent = txt; w.style.display = ''; } });
    refreshUnread();
  }

  function refreshUnread() {
    var t = store('kt_token'); if (!t) return;
    // Accurate UNREAD MESSAGES only (not general notifications like birthdays/tours).
    fetch(API + '/chats/unread-count', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var n = 0;
        if (d) n = (d.unread_count != null ? d.unread_count : (d.count != null ? d.count : (d.unread != null ? d.unread : 0)));
        n = parseInt(n, 10) || 0;
        var bd = document.getElementById('kt-tb-msg-badge'); if (!bd) return;
        if (n > 0) { bd.textContent = n > 99 ? '99+' : n; bd.hidden = false; } else { bd.hidden = true; }
      }).catch(function () {});
  }

  // Refresh the signed-in user so the sidebar avatar reflects the latest photo.
  function refreshUser() {
    var t = store('kt_token'); if (!t) return;
    fetch(API + '/auth/me', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var fresh = d && (d.user || d); if (!fresh) return;
        var cur = user();
        if (fresh.photo_url && fresh.photo_url !== cur.photo_url) {
          cur.photo_url = fresh.photo_url;
          try { sessionStorage.setItem('kt_user', JSON.stringify(cur)); } catch (e) {}
          paintSidebarAvatar(cur);
          paintTopbarAvatar(cur);
        }
      }).catch(function () {});
  }
  function paintSidebarAvatar(u) {
    var av = document.getElementById('navAvatar'); if (!av || !u.photo_url) return;
    var apiHost = API.replace(/\/api\/v1\/?$/, '');
    var src = /^https?:\/\//i.test(u.photo_url) ? u.photo_url : (apiHost + u.photo_url);
    av.textContent = '';
    var img = document.createElement('img');
    img.src = src; img.alt = u.name || '';
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
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
      return '<span class="kt-tb-ava"><img src="' + esc(src) + '" alt=""></span>';
    }
    return '<span class="kt-tb-ava kt-tb-ava--init" style="background:' + avaColour((u && u.name) || '') + '">' + esc(tbInitials(u)) + '</span>';
  }
  function paintTopbarAvatar(u) {
    var sp = document.querySelector('#kt-topbar .kt-tb-ava'); if (!sp || !u || !u.photo_url) return;
    var apiHost = API.replace(/\/api\/v1\/?$/, '');
    var src = /^https?:\/\//i.test(u.photo_url) ? u.photo_url : (apiHost + u.photo_url);
    sp.className = 'kt-tb-ava'; sp.style.background = '';
    sp.innerHTML = '<img alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
    sp.querySelector('img').src = src;
  }

  // Let the shell mount the bar the instant it clears #appMain, instead of leaving it
  // missing until the 1.2s poll below fires. Clearing the screen takes the bar with it,
  // so on every navigation the bar disappeared and popped back in AFTER the banner had
  // already drawn — that is the jank. ensure() is a no-op when the bar is present.
  window.KT = window.KT || {};
  window.KT.topbar = { ensure: ensure };

  setInterval(function () { var c = document.getElementById('kt-tb-clock'); if (c) c.textContent = fmtClock(); var d = document.getElementById('kt-tb-date'); if (d) d.textContent = fmtDate(); }, 15000);
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(ensure) : setInterval(ensure, 1200);   // safety net; the shell now calls ensure() on every render
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(buildSelectors) : setInterval(buildSelectors, 1400);   // rebuild View-as + agency switcher in the bar after any nav
  setInterval(refreshUnread, 15000);   // near-real-time unread badge
  // No lag on return: refresh the bell + unread the instant the tab/app is
  // re-focused, instead of waiting up to a full poll interval.
  function refreshBellBadge() { try { (__staffNotifStarted ? refreshStaffNotifBadge : refreshNotifBadge)(); } catch (e) {} }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { refreshBellBadge(); try { refreshUnread(); } catch (e) {} } });
  window.addEventListener('focus', function () { refreshBellBadge(); try { refreshUnread(); } catch (e) {} });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { ensure(); refreshUser(); });
  else { ensure(); refreshUser(); }
  /* Exposed so the admin launcher can build its own pair on a phone, where this
     bar is hidden by kt-mobile-app.css. Same builders, so the two copies can
     never disagree about what a role or an agency switch does. */
  window.KT = window.KT || {};
  window.KT.Topbar = { buildViewAs: buildViewAs, buildAgency: buildAgency };
})();
