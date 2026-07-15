/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — parent & educator DESKTOP top-bar chrome (2026-07-14).
   On desktop the parent/educator top bar (the sidebar restyled horizontally)
   gets the same chrome super admins have, grouped together on the right:
   Home · weather · date/clock · language · Settings · Sign out — plus a
   time-of-day greeting on the LEFT beside the logo. Desktop only; mobile keeps
   its bottom nav + floating gear (this module no-ops below 769px).
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.KT_PARENT_CHROME) return; w.KT_PARENT_CHROME = true;

  var API = (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  function isDesktop() { return w.matchMedia && w.matchMedia('(min-width: 769px)').matches; }
  function roleOf() {
    var c = document.body.className || '';
    if (/\brole-guardian\b/.test(c)) return 'guardian';
    if (/\brole-educator\b/.test(c)) return 'educator';
    return null;
  }
  function store(k) { try { return sessionStorage.getItem(k) || localStorage.getItem(k); } catch (e) { return null; } }
  function firstName() {
    try {
      var u = JSON.parse(store('kt_user') || '{}');
      return (u.first_name || u.preferred_name || (u.name || '').split(' ')[0] || '').trim();
    } catch (e) { return ''; }
  }

  // ── clock / date / weather / greeting ──
  function fmtClock() {
    var d = new Date(), h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM', hh = h % 12; if (hh === 0) hh = 12;
    return hh + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap;
  }
  function fmtDate() { try { return new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }); } catch (e) { return ''; } }
  function greetWord() {
    var h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    if (h < 22) return 'Good evening';
    return 'Good night';
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
        .then(function (r) { return r.json(); }).then(function (wx) { done(wxEmoji(wx.current.weather_code) + ' ' + Math.round(wx.current.temperature_2m) + '°C'); });
    }).catch(function () { cb(null); });
  }

  function iconBtn(emoji, title, fn) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'kt-pc-btn'; b.title = title; b.setAttribute('aria-label', title);
    b.textContent = emoji;
    b.addEventListener('click', function (e) { e.preventDefault(); fn(); });
    return b;
  }

  var LOCALES = [['en', '🌐 English'], ['fr', '🌐 Français'], ['es', '🌐 Español'], ['hi', '🌐 हिन्दी']];
  function langPicker() {
    var sel = document.createElement('select');
    sel.className = 'kt-pc-lang'; sel.title = 'Language'; sel.setAttribute('aria-label', 'Language');
    var cur = 'en'; try { cur = localStorage.getItem('kt_locale') || 'en'; } catch (e) {}
    LOCALES.forEach(function (l) { var o = document.createElement('option'); o.value = l[0]; o.textContent = l[1]; if (l[0] === cur) o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () {
      var loc = sel.value;
      try { localStorage.setItem('kt_locale', loc); } catch (e) {}
      var t = store('kt_token');
      fetch(API + '/locale', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t }, body: JSON.stringify({ locale: loc }) })
        .catch(function () {}).then(function () { w.location.reload(); });
    });
    return sel;
  }

  function doSignOut() {
    if (!w.confirm('Sign out of KiddieTrac?')) return;
    try { if (w.Auth && w.Auth.logout) return w.Auth.logout(); } catch (e) {}
    try { sessionStorage.clear(); } catch (e) {}
    w.location.href = '/index.html?signed_out=1';
  }

  function injectStyle() {
    if (document.getElementById('kt-pc-style')) return;
    var s = document.createElement('style'); s.id = 'kt-pc-style';
    s.textContent = [
      // The single greeting now lives INSIDE the user block, on the far left.
      '.kt-pc-user-left{margin-left:14px !important;cursor:pointer;}',
      '.kt-pc-navgreet{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#94A3B8;line-height:1.15;}',
      // Role shown as a pill (like admin / director / super admin), not hidden.
      'body.role-guardian #appSidebar #navUser .nav-user-role,body.role-educator #appSidebar #navUser .nav-user-role{display:inline-block !important;margin-top:3px;font-size:9.5px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;background:rgba(14,124,144,.12);color:#0C6070;border:1px solid rgba(14,124,144,.22);padding:2px 9px;border-radius:100px;white-space:nowrap;align-self:flex-start;}',
      // Tighter top bar (less whitespace): smaller logo + slimmer vertical padding — DESKTOP only.
      '@media(min-width:601px){body.role-guardian #appSidebar,body.role-educator #appSidebar{padding-top:6px !important;padding-bottom:6px !important;}body.role-guardian #appSidebar #navBrand img,body.role-educator #appSidebar #navBrand img{height:60px !important;}}',
      // Home launcher: stop the banner replaying its fade-in (a "pop") when the
      // normalizer adds .kt-banner-fx after the tiles have already settled.
      'body.role-guardian #appMain .kt-tilehome .kt-banner-fx,body.role-educator #appMain .kt-tilehome .kt-banner-fx{animation:kt-hero-breathe 16s ease-in-out infinite !important;}',
      // Mobile top-header date/time.
      '.kt-pc-mobmeta{display:none;}',
      '@media(max-width:600px){body.role-guardian .kt-pc-mobmeta,body.role-educator .kt-pc-mobmeta{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2;margin-left:auto;margin-right:52px;text-align:right;flex-shrink:0;}body.role-guardian .kt-pc-mobmeta .d,body.role-educator .kt-pc-mobmeta .d{font-size:10px;font-weight:700;color:#94A3B8;white-space:nowrap;}body.role-guardian .kt-pc-mobmeta .t,body.role-educator .kt-pc-mobmeta .t{font-size:13px;font-weight:800;color:#0F172A;white-space:nowrap;}}',
      // Admin-only search box: hidden in parent/educator view.
      'body.role-guardian #kt-search-widget,body.role-educator #kt-search-widget{display:none !important;}',
      // Super-admin "view as": agency switcher relocated from bottom-left into the top bar.
      '.kt-agency-inbar{position:relative !important;left:auto !important;right:auto !important;top:auto !important;bottom:auto !important;margin:0 8px 0 0 !important;padding:0 !important;border-top:none !important;box-shadow:none !important;z-index:50 !important;min-width:150px;background:transparent !important;border:none !important;}',
      '.kt-agency-inbar > div[style*="position:absolute"]{top:calc(100% + 4px) !important;bottom:auto !important;}',
      // grouped cluster on the right
      '.kt-pc-wrap{display:flex;align-items:center;gap:8px;margin-left:auto;margin-right:12px;}',
      '.kt-pc-btn{width:38px;height:38px;border-radius:11px;border:1px solid rgba(15,23,42,.10);',
      '  background:#fff;font-size:17px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;',
      '  box-shadow:0 1px 2px rgba(16,40,64,.05);transition:background .12s,border-color .12s;}',
      '.kt-pc-btn:hover{background:#F1F5F9;border-color:#94A3B8;}',
      '.kt-pc-lang{border:1px solid rgba(15,23,42,.10);border-radius:10px;background:#fff;padding:7px 8px;font-size:12px;font-weight:600;color:#334155;cursor:pointer;max-width:118px;box-shadow:0 1px 2px rgba(16,40,64,.05);}',
      '.kt-pc-wx{font-size:12.5px;font-weight:600;color:#475569;white-space:nowrap;}',
      '.kt-pc-date{font-size:12.5px;font-weight:600;color:#334155;white-space:nowrap;}',
      '.kt-pc-clock{font-size:13px;font-weight:800;color:#0F172A;white-space:nowrap;}',
      '.kt-pc-sep{width:1px;height:24px;background:rgba(15,23,42,.10);margin:0 2px;}',
      '.kt-back-inbar{position:static !important;top:auto !important;left:auto !important;right:auto !important;bottom:auto !important;margin:0 12px 0 0 !important;box-shadow:none !important;align-self:center !important;z-index:auto !important;}',
      // The in-page bottom "Sign out" is redundant now it's a top-bar icon (desktop).
      '@media(min-width:769px){body.role-guardian #kt-home-signout,body.role-educator #kt-home-signout{display:none !important;}}',
      '@media(max-width:768px){.kt-pc-wrap,.kt-pc-greet{display:none !important;}}',
      '@media(max-width:1240px){.kt-pc-date{display:none;}}',
      '@media(max-width:1080px){.kt-pc-greet{display:none;}}',
      // ── FULL SCREEN: parent/educator content fills the viewport. The 1800px cap
      //    (on #appMain via CSS, and on each screen's wrapper via an inline style) left
      //    empty gutters on wide monitors. Lift ONLY the 1800 caps — narrow panels
      //    (settings/forms at 640px etc.) keep their own smaller max-width. ──
      'body.role-guardian #appMain,body.role-educator #appMain,',
      'body.role-guardian .kt-tilehome,body.role-educator .kt-tilehome{max-width:none !important;margin-left:0 !important;margin-right:0 !important;}',
      // Only lift the WIDE (1800px) caps — narrow panels (settings at 520px, forms)
      // keep their own readable max-width, so a settings form never stretches edge to edge.
      'body.role-guardian #appMain [style*="max-width: 1800"],',
      'body.role-educator #appMain [style*="max-width: 1800"],',
      'body.role-guardian #appMain [style*="max-width:1800"],',
      'body.role-educator #appMain [style*="max-width:1800"]{max-width:none !important;}',
      // The settings/profile form stays a readable, centred width (a desktop rule was
      // stretching its own 520px wrap to 100%). Keep forms narrow while content is full.
      'body.role-guardian #appMain [style*="max-width:520px"],',
      'body.role-educator #appMain [style*="max-width:520px"]{max-width:600px !important;margin-left:auto !important;margin-right:auto !important;}'
    ].join('');
    document.head.appendChild(s);
  }

  // The single "Good <time>" greeting, injected above the name in the user block.
  // Runs on desktop AND mobile (the app's own JS fills the name itself).
  function fmtDateShort() { try { return new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); } catch (e) { return ''; } }

  // Mobile top header: a compact date + time (the desktop bar has weather/date/clock;
  // phones just get date + time, right-aligned before the floating settings gear).
  function ensureMobileMeta() {
    if (isDesktop() || !roleOf()) return;
    var bar = document.getElementById('appSidebar');
    if (!bar) return;
    injectStyle();
    var m = document.getElementById('kt-pc-mobmeta');
    if (!m) {
      m = document.createElement('div');
      m.id = 'kt-pc-mobmeta'; m.className = 'kt-pc-mobmeta';
      m.innerHTML = '<span class="d"></span><span class="t"></span>';
      bar.appendChild(m);
    }
    var d = m.querySelector('.d'), t = m.querySelector('.t');
    if (d) d.textContent = fmtDateShort();
    if (t) t.textContent = fmtClock();
  }

  function ensureGreeting() {
    if (!roleOf()) return;
    injectStyle();
    var navUser = document.querySelector('#appSidebar #navUser, #appSidebar .nav-user');
    if (!navUser) return;
    var txt = navUser.querySelector('.nav-user-text');
    if (txt && !document.getElementById('kt-pc-navgreet')) {
      var gr = document.createElement('div');
      gr.id = 'kt-pc-navgreet'; gr.className = 'kt-pc-navgreet';
      gr.textContent = greetWord();
      txt.insertBefore(gr, txt.firstChild);
    }
  }

  function ensure() {
    if (!isDesktop()) return;
    var role = roleOf();
    if (!role) return;
    var bar = document.getElementById('appSidebar');
    if (!bar) return;
    injectStyle();

    // ── LEFT: the user block (avatar + name) moved to the far left, with the ONE
    //    greeting injected above the name. ──
    var navUser = bar.querySelector('#navUser, .nav-user');
    if (navUser) {
      var brand = bar.querySelector('.nav-brand, #navBrand');
      if (brand && brand.parentNode === bar && navUser.previousElementSibling !== brand) {
        bar.insertBefore(navUser, brand.nextSibling);
      }
      navUser.classList.add('kt-pc-user-left');
      ensureGreeting();
      paintGreeting();
    }

    // ── RIGHT: the grouped cluster ──
    if (!document.getElementById('kt-pc-wrap')) {
      var userBlock = bar.querySelector('.nav-user');
      var wrap = document.createElement('div');
      wrap.className = 'kt-pc-wrap'; wrap.id = 'kt-pc-wrap';

      // All the icon controls grouped together first (Home · Language · Settings ·
      // Sign out), then a separator, then the weather / date / clock — per request.
      wrap.appendChild(iconBtn('🏠', 'Home', function () { location.hash = (role === 'educator') ? '#dashboard' : '#home'; }));
      wrap.appendChild(iconBtn('⚙️', 'Settings', function () { location.hash = '#settings'; }));
      wrap.appendChild(iconBtn('🚪', 'Sign out', doSignOut));
      wrap.appendChild(langPicker());

      var sep = document.createElement('span'); sep.className = 'kt-pc-sep'; wrap.appendChild(sep);

      var wx = document.createElement('span'); wx.className = 'kt-pc-wx'; wx.id = 'kt-pc-wx'; wx.style.display = 'none'; wrap.appendChild(wx);
      var dt = document.createElement('span'); dt.className = 'kt-pc-date'; dt.id = 'kt-pc-date'; dt.textContent = fmtDate(); wrap.appendChild(dt);
      var ck = document.createElement('span'); ck.className = 'kt-pc-clock'; ck.id = 'kt-pc-clock'; ck.textContent = fmtClock(); wrap.appendChild(ck);

      if (userBlock && userBlock.parentNode === bar) bar.insertBefore(wrap, userBlock);
      else bar.appendChild(wrap);

      loadWeather(function (txt) { var el = document.getElementById('kt-pc-wx'); if (el && txt) { el.textContent = txt; el.style.display = ''; } });
    }

    // Super-admin "view as": pull the agency switcher out of the bottom-left and
    // into the top bar, right after the "Viewing as" pill — like real super-admin mode.
    var ag = document.getElementById('kt-agency-switcher');
    var va = document.getElementById('kt-view-as');
    if (ag && va && va.parentNode === bar && ag.parentNode !== bar) {
      ag.classList.add('kt-agency-inbar');
      bar.insertBefore(ag, va.nextSibling);
      var dd = ag.querySelector('div[style*="absolute"]');
      if (dd) { dd.style.top = 'calc(100% + 4px)'; dd.style.bottom = 'auto'; }
    }

    placeBack();
  }

  function paintGreeting() {
    var gr = document.getElementById('kt-pc-navgreet');
    if (gr) gr.textContent = greetWord();   // name itself is set by the app's own auth JS
  }

  function tick() {
    var c = document.getElementById('kt-pc-clock'); if (c) c.textContent = fmtClock();
    var d = document.getElementById('kt-pc-date'); if (d) d.textContent = fmtDate();
    ensureMobileMeta();
    paintGreeting();
  }

  // Move the floating "← Back" button INTO the top bar (far left, before the logo)
  // so it can never overlap the banner that sits directly beneath the bar. It keeps
  // its own show/hide — screen-role-home toggles [hidden] — and in the bar it just flows.
  function placeBack() {
    if (!isDesktop() || !roleOf()) return;
    var back = document.getElementById('kt-role-back');
    var bar = document.getElementById('appSidebar');
    if (!back || !bar) return;
    if (back.parentNode !== bar) {
      back.classList.add('kt-back-inbar');
      back.style.top = ''; back.style.left = '';
      var brand = bar.querySelector('.nav-brand, #navBrand');
      bar.insertBefore(back, brand || bar.firstChild);
    }
  }

  // Mount the chrome the instant it's possible — NOT on a lazy 1.2s poll (which made
  // the static top bar paint first, then the icons/greeting pop in a beat later). A
  // MutationObserver fires ensure() the moment the role class lands or the bar gains
  // children (name, view-as, agency switcher), so the chrome appears WITH the bar.
  var _booting = false;
  function boot() {
    if (_booting) return;
    _booting = true;
    try { ensureGreeting(); ensureMobileMeta(); ensure(); placeBack(); } catch (e) {} finally { _booting = false; }
  }
  boot();

  if (w.MutationObserver) {
    // Role class (role-guardian / role-educator) is what flips the layout — react to it.
    try { new w.MutationObserver(boot).observe(document.body, { attributes: true, attributeFilter: ['class'] }); } catch (e) {}
    // Late-injected bar chrome (auth name, view-as pill, agency switcher) → re-run.
    var attachBarObs = function () {
      var bar = document.getElementById('appSidebar');
      if (bar && !bar.__ktPcObs) { bar.__ktPcObs = new w.MutationObserver(boot); bar.__ktPcObs.observe(bar, { childList: true }); }
    };
    attachBarObs();
    document.addEventListener('DOMContentLoaded', function () { attachBarObs(); boot(); });
  }
  w.addEventListener('hashchange', boot);
  // A slow safety net only — the observers do the real work.
  setInterval(boot, 2000);
  setInterval(tick, 15000);
  w.addEventListener('resize', function () {
    var wrap = document.getElementById('kt-pc-wrap');
    if (!isDesktop()) { if (wrap) wrap.remove(); ensureGreeting(); }
    else boot();
  });
})(window);
