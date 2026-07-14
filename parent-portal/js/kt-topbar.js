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

  var ROLE = { platform_admin: 'Super Admin', agency_admin: 'Agency Admin', centre_director: 'Centre Director', educator: 'Educator', guardian: 'Parent', auditor: 'Auditor' };
  function roleLabel(u) {
    var roles = (u && u.roles) || [];
    var order = ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'auditor', 'guardian'];
    for (var i = 0; i < order.length; i++) { if (roles.indexOf(order[i]) !== -1) return ROLE[order[i]]; }
    return (u && u.primary_role && ROLE[u.primary_role]) || 'Member';
  }
  function isAdminRole(u) { var r = (u && u.roles) || []; return ['platform_admin', 'agency_admin', 'centre_director'].some(function (x) { return r.indexOf(x) !== -1; }); }
  function isPlatformAdmin(u) { return ((u && u.roles) || []).indexOf('platform_admin') !== -1; }

  // Super-admin View-as + agency switcher are built NATIVELY in the bar (not moved
  // from the sidebar). Because screens wipe #appMain — and the bar lives there —
  // moved elements were destroyed on every navigation and never rebuilt. Building
  // them here means they are recreated together with the bar each time. The sidebar
  // originals are hidden via CSS (body.kt-tb-has-selectors).
  var AGENCIES = null;
  function ctl(id) { var d = document.createElement('div'); d.id = id; d.className = 'kt-tb-ctl'; return d; }
  function buildViewAs(host) {
    if (document.getElementById('kt-tb-viewas')) return;
    var cur = ''; try { cur = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var roles = [['', 'Super admin (default)'], ['agency_admin', '🏢 Agency admin'], ['centre_director', '🏫 Centre director'], ['educator', '🎓 Educator'], ['guardian', '👪 Parent / guardian'], ['auditor', '🔍 Auditor']];
    var wrap = ctl('kt-tb-viewas'); if (cur) wrap.classList.add('kt-tb-ctl-active');
    var lab = document.createElement('span'); lab.className = 'kt-tb-ctl-lab'; lab.textContent = cur ? '👁 Viewing as' : '👁 View as';
    var sel = document.createElement('select'); sel.className = 'kt-tb-ctl-sel'; sel.title = 'Preview the portal as another role';
    roles.forEach(function (r) { var o = document.createElement('option'); o.value = r[0]; o.textContent = r[1]; if (r[0] === cur) o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () { if (window.ktViewAs) window.ktViewAs(sel.value); });
    wrap.appendChild(lab); wrap.appendChild(sel); host.appendChild(wrap);
  }
  function buildAgency(host) {
    if (document.getElementById('kt-tb-agency')) return;
    var wrap = ctl('kt-tb-agency'); host.appendChild(wrap);
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
  function openProfile() { location.hash = '#onboarding'; }   // profile editor (name / photo / contact)
  function openHome() { location.hash = '#dashboard'; }
  function fmtDate() { try { return new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }); } catch (e) { return ''; } }

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
      '.kt-tb-greet{font-size:14px;font-weight:700;color:#1E293B;white-space:nowrap;}',
      '.kt-tb-name{color:#0E7C90;cursor:pointer;text-decoration:none;}',
      '.kt-tb-name:hover{text-decoration:underline;}',
      '.kt-tb-role{margin-left:8px;font-size:9.5px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;',
      'background:rgba(14,124,144,.12);color:#0C6070;border:1px solid rgba(14,124,144,.22);padding:2px 8px;border-radius:100px;white-space:nowrap;}',
      '.kt-tb-right{display:flex;align-items:center;gap:7px;}',
      '.kt-tb-ico{position:relative;width:31px;height:31px;border:1px solid rgba(15,23,42,.1);background:rgba(15,23,42,.03);',
      'border-radius:9px;cursor:pointer;font-size:15px;line-height:1;display:flex;align-items:center;justify-content:center;color:#334155;padding:0;transition:background .14s;}',
      '.kt-tb-ico:hover{background:rgba(15,23,42,.08);}',
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
    b.className = 'kt-tb-ico'; b.type = 'button'; b.title = title; b.setAttribute('aria-label', title);
    b.textContent = emoji;
    if (badgeId) { var bd = document.createElement('span'); bd.className = 'kt-tb-badge'; bd.id = badgeId; bd.hidden = true; b.appendChild(bd); }
    b.addEventListener('click', function (e) { e.preventDefault(); fn(); });
    return b;
  }

  // Language picker. Replaces the old Language SCREEN — changing your language is a
  // preference, not somewhere you navigate to. Posts to the same /locale endpoint the
  // screen used, then reloads so the new strings take effect.
  var LOCALES = [['en', 'English'], ['fr', 'Fran\u00e7ais'], ['es', 'Espa\u00f1ol']];
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
      }).catch(function () {}).then(function () { window.location.reload(); });
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
    if (!isAdminRole(u) || (viewAs && ['educator', 'guardian', 'auditor'].indexOf(viewAs) !== -1)) return;
    injectStyle();
    var pod = greetEmoji(new Date().getHours());
    var bar = document.createElement('div');
    bar.id = 'kt-topbar'; bar.className = 'kt-topbar';

    var left = document.createElement('div'); left.className = 'kt-tb-left';
    left.innerHTML = '<span class="kt-tb-emoji">' + pod.e + '</span><span class="kt-tb-greet">' + pod.g + ', <a class="kt-tb-name" title="Your profile">' + esc(firstName(u)) + '</a></span><span class="kt-tb-role">' + esc(roleLabel(u)) + '</span>';
    var nameEl = left.querySelector('.kt-tb-name'); if (nameEl) nameEl.addEventListener('click', function (e) { e.preventDefault(); openProfile(); });

    var right = document.createElement('div'); right.className = 'kt-tb-right';
    right.appendChild(languagePicker());
    right.appendChild(iconBtn('🏠', 'Home', openHome));
    right.appendChild(iconBtn('⚡', 'Quick add', openQuickAdd));
    right.appendChild(iconBtn('💬', 'Messages', function () { go('#chat'); }, 'kt-tb-msg-badge'));
    right.appendChild(iconBtn('📣', 'Announcements', function () { go('#announcements'); }));
    right.appendChild(iconBtn('🤖', 'AI assistant', openAI));
    if (isAdminRole(u)) right.appendChild(iconBtn('🎁', "What's new", function () { if (window.KT && window.KT.openWhatsNew) window.KT.openWhatsNew(); }, 'kt-tb-wn-badge'));
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

  // Let the shell mount the bar the instant it clears #appMain, instead of leaving it
  // missing until the 1.2s poll below fires. Clearing the screen takes the bar with it,
  // so on every navigation the bar disappeared and popped back in AFTER the banner had
  // already drawn — that is the jank. ensure() is a no-op when the bar is present.
  window.KT = window.KT || {};
  window.KT.topbar = { ensure: ensure };

  setInterval(function () { var c = document.getElementById('kt-tb-clock'); if (c) c.textContent = fmtClock(); var d = document.getElementById('kt-tb-date'); if (d) d.textContent = fmtDate(); }, 15000);
  setInterval(ensure, 1200);   // safety net; the shell now calls ensure() on every render
  setInterval(buildSelectors, 1400);   // rebuild View-as + agency switcher in the bar after any nav
  setInterval(refreshUnread, 15000);   // near-real-time unread badge
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { ensure(); refreshUser(); });
  else { ensure(); refreshUser(); }
})();
