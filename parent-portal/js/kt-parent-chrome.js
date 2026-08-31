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
    // Home visitor uses the exact same top-bar chrome as parent/educator.
    if (/\brole-home-visitor\b/.test(c)) return 'home_visitor';
    // Sales rep uses the exact same top-bar chrome as parent/educator.
    if (/\brole-sales-rep\b/.test(c)) return 'sales_rep';
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
  // Short weekday — byte-identical to the admin bar's date ("Tue, August 11, 2026").
  function fmtDate() { try { return new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' }); } catch (e) { return ''; } }
  // Time-of-day greeting WITH its icon. Deliberately the same words + emoji as
  // kt-topbar.js's greetEmoji() (the admin/director bar) — parents and educators
  // were getting the bare words while admins got "🌆 Good evening".
  function greetParts() {
    var h = new Date().getHours();
    if (h < 5) return { g: 'Good night', e: '🌙' };     // pre-dawn, not "morning"
    if (h < 12) return { g: 'Good morning', e: '🌅' };
    if (h < 17) return { g: 'Good afternoon', e: '☀️' };
    if (h < 22) return { g: 'Good evening', e: '🌆' };
    return { g: 'Good night', e: '🌙' };
  }
  function greetWord() { var p = greetParts(); return p.e + ' ' + p.g; }
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

  function iconBtn(emoji, title, fn, badgeId) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'kt-pc-btn'; b.setAttribute('data-kttip', title); b.setAttribute('aria-label', title);
    b.appendChild(document.createTextNode(emoji));
    if (badgeId) { var bd = document.createElement('span'); bd.className = 'kt-pc-badge'; bd.id = badgeId; bd.hidden = true; b.appendChild(bd); }
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

  async function doSignOut() {
    if (!await KT.confirm('Sign out of KiddieTrac?')) return;
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
      // Desktop matches the admin/director bar exactly (kt-topbar.js .kt-tb-greet):
      // one row reading "<emoji> Good evening, <name>" followed by the role pill,
      // instead of the old stacked eyebrow / name / pill.
      '.kt-pc-navgreet{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#64748B;line-height:1.15;}',
      '@media(min-width:769px){',
      '  body.role-guardian #appSidebar #navUser .nav-user-text,body.role-educator #appSidebar #navUser .nav-user-text,'
      + 'body.role-home-visitor #appSidebar #navUser .nav-user-text,body.role-sales-rep #appSidebar #navUser .nav-user-text'
      + '{display:flex !important;flex-direction:row !important;align-items:center !important;gap:10px;flex-wrap:nowrap;}',
      '  .kt-pc-navemoji{font-size:20px;line-height:1;flex:0 0 auto;}',
      '  .kt-pc-navgreet{font-size:14px !important;font-weight:700 !important;letter-spacing:normal !important;text-transform:none !important;color:#1E293B !important;white-space:nowrap;}',
      '  body.role-guardian #appSidebar #navUser .nav-user-name,body.role-educator #appSidebar #navUser .nav-user-name,'
      + 'body.role-home-visitor #appSidebar #navUser .nav-user-name,body.role-sales-rep #appSidebar #navUser .nav-user-name'
      + '{font-size:14px !important;font-weight:700 !important;color:#0E7C90 !important;white-space:nowrap;margin:0 !important;}',
      '  body.role-guardian #appSidebar #navUser .avatar,body.role-educator #appSidebar #navUser .avatar,'
      + 'body.role-home-visitor #appSidebar #navUser .avatar,body.role-sales-rep #appSidebar #navUser .avatar'
      + '{width:32px !important;height:32px !important;min-width:32px !important;min-height:32px !important;font-size:13px !important;'
      + 'box-shadow:0 1px 3px rgba(15,23,42,.18) !important;}',
      '}',
      // Role shown as a pill (like admin / director / super admin), not hidden.
      'body.role-guardian #appSidebar #navUser .nav-user-role,body.role-educator #appSidebar #navUser .nav-user-role,body.role-home-visitor #appSidebar #navUser .nav-user-role,body.role-sales-rep #appSidebar #navUser .nav-user-role{display:inline-block !important;margin-top:0;font-size:9.5px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;background:rgba(14,124,144,.12);color:#0C6070;border:1px solid rgba(14,124,144,.22);padding:2px 8px;border-radius:100px;white-space:nowrap;align-self:center;}',
      // Tighter top bar (less whitespace): smaller logo + slimmer vertical padding — DESKTOP only.
      '@media(min-width:601px){body.role-guardian #appSidebar,body.role-educator #appSidebar{padding-top:6px !important;padding-bottom:6px !important;}body.role-guardian #appSidebar #navBrand img,body.role-educator #appSidebar #navBrand img{height:60px !important;}}',
      // Home launcher: stop the banner replaying its fade-in (a "pop") when the
      // normalizer adds .kt-banner-fx after the tiles have already settled.
      'body.role-guardian #appMain .kt-tilehome .kt-banner-fx,body.role-educator #appMain .kt-tilehome .kt-banner-fx{animation:kt-hero-breathe 16s ease-in-out infinite !important;}',
      // Mobile top-header date/time.
      '.kt-pc-mobmeta{display:none;}',
      '@media(max-width:600px){body.role-guardian .kt-pc-mobmeta,body.role-educator .kt-pc-mobmeta{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2;margin-left:auto;margin-right:52px;text-align:right;flex-shrink:0;}body.role-guardian .kt-pc-mobmeta .d,body.role-educator .kt-pc-mobmeta .d{font-size:10px;font-weight:700;color:#64748B;white-space:nowrap;}body.role-guardian .kt-pc-mobmeta .t,body.role-educator .kt-pc-mobmeta .t{font-size:13px;font-weight:800;color:#0F172A;white-space:nowrap;}}',
      // Admin-only search box: hidden in parent/educator view.
      'body.role-guardian #kt-search-widget,body.role-educator #kt-search-widget{display:none !important;}',
      // Super-admin "view as": agency switcher relocated from bottom-left into the top bar.
      '.kt-agency-inbar{position:relative !important;left:auto !important;right:auto !important;top:auto !important;bottom:auto !important;margin:0 8px 0 0 !important;padding:0 !important;border-top:none !important;box-shadow:none !important;z-index:50 !important;min-width:150px;background:transparent !important;border:none !important;}',
      '.kt-agency-inbar > div[style*="position:absolute"]{top:calc(100% + 4px) !important;bottom:auto !important;}',
      // grouped cluster on the right
      '.kt-pc-wrap{display:flex;align-items:center;gap:9px;margin-left:auto;margin-right:12px;}',
      // Match the super-admin top-bar icon sizing (kt-tb-ico): 31x31, 9px radius,
      // 15px glyph — so the action icons are the same size across every role.
      '.kt-pc-btn{position:relative;width:31px;height:31px;border-radius:9px;border:1px solid rgba(15,23,42,.10);',
      '  background:rgba(15,23,42,.03);font-size:15px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#334155;padding:0;',
      '  transition:background .12s,border-color .12s;}',
      '.kt-pc-btn:hover{background:#F1F5F9;border-color:#64748B;}',
      // Custom tooltip below the icon — consistent with the super-admin top bar.
      '.kt-pc-btn[data-kttip]:hover::after{content:attr(data-kttip);position:absolute;top:calc(100% + 7px);left:50%;transform:translateX(-50%);background:#0F172A;color:#fff;font-size:11px;font-weight:600;white-space:nowrap;padding:4px 9px;border-radius:6px;z-index:10001;pointer-events:none;box-shadow:0 4px 14px rgba(0,0,0,.28);}',
      // Unread badge on the Messages icon — same look as the super-admin bar.
      '.kt-pc-badge{position:absolute;top:-5px;right:-5px;min-width:15px;height:15px;padding:0 4px;border-radius:8px;',
      '  background:#EF4444;color:#fff;font-size:9.5px;font-weight:800;line-height:15px;text-align:center;box-shadow:0 0 0 2px rgba(255,255,255,.8);}',
      // The language picker sizes itself to its widest option — never cap it.
      // kt-polish-v22.css forces `padding:5px 26px 5px 10px !important` on EVERY
      // select (26px of right padding reserved for a custom arrow this select does
      // not use). That stole 18px of content box inside the old hard
      // `max-width:118px`, which is what clipped "English" to "Englis". We win the
      // padding back with !important and drop the cap, so the native arrow gets its
      // own intrinsic space and no language can ever clip again.
      '.kt-pc-lang{border:1px solid rgba(15,23,42,.10);border-radius:10px;background:#fff;padding:5px 8px 5px 10px !important;font-size:12px;font-weight:600;color:#334155;cursor:pointer;max-width:none;box-shadow:0 1px 2px rgba(16,40,64,.05);}',
      '.kt-pc-wx{font-size:12.5px;font-weight:600;color:#475569;white-space:nowrap;}',
      '.kt-pc-date{font-size:12.5px;font-weight:600;color:#475569;white-space:nowrap;}',
      // Identical to the admin/director bar's .kt-tb-clock so the two top bars read
      // as one product: monospace tabular figures on a flat tinted chip (the old
      // white raised card sat differently from every admin screen).
      // NOTE: .kt-tb-clock (admin) DECLARES a monospace stack, but on the real admin
      // bar that declaration is overridden and the clock renders in Inter like the
      // rest of the chrome. Matching the stylesheet rather than the RENDERING left
      // this clock visibly monospace while the admin one was not — so we
      // deliberately declare no font-family here and inherit the same face.
      '.kt-pc-clock{font-size:13px;font-weight:700;color:#334155;'
      + 'font-variant-numeric:tabular-nums;background:rgba(15,23,42,.05);padding:5px 10px;border-radius:9px;white-space:nowrap;}',
      '.kt-pc-sep{width:1px;height:24px;background:rgba(15,23,42,.10);margin:0 2px;}',
      '.kt-back-inbar{position:static !important;top:auto !important;left:auto !important;right:auto !important;bottom:auto !important;margin:0 12px 0 0 !important;box-shadow:none !important;align-self:center !important;z-index:auto !important;}',
      // The in-page bottom "Sign out" is redundant now it's a top-bar icon (desktop).
      '@media(min-width:769px){body.role-guardian #kt-home-signout,body.role-educator #kt-home-signout{display:none !important;}}',
      // Educator #today: the roster shell carries min-height:calc(100vh-64px) (app-v2.css)
      // so a sparse roster left a huge empty gap ABOVE the "Everything else" tile launcher
      // that the shell appends below it. On desktop the launcher makes the page tall enough
      // on its own, so let the shell hug its content and sit the tiles right beneath it.
      '@media(min-width:769px){#appMain .educator-shell{min-height:0 !important;}#kt-edu-launcher{margin-top:10px !important;}}',
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
      'body.role-educator #appMain [style*="max-width:520px"]{max-width:600px !important;margin-left:auto !important;margin-right:auto !important;}',
      // Smoother home launcher entrance — fade the whole tilehome as ONE unit.
      // The home launcher paints all at once (no fade/slide that reads as the cards loading after the chrome).
      'body.role-guardian #appMain > .kt-tilehome,body.role-educator #appMain > .kt-tilehome{animation:none !important;opacity:1 !important;transform:none !important;}',
      // Desktop: the invoice pay sheet becomes a centred panel over a dim backdrop.
      '@media(min-width:601px){body.role-guardian:has(.kt-invoice-sheet)::after,body.role-educator:has(.kt-invoice-sheet)::after{content:"";position:fixed;inset:0;background:rgba(8,20,36,.42);z-index:9599;}body.role-guardian .kt-invoice-sheet,body.role-educator .kt-invoice-sheet{inset:auto !important;position:fixed !important;top:96px !important;left:50% !important;transform:translateX(-50%) !important;width:min(560px,94vw) !important;max-height:calc(100vh - 130px) !important;border-radius:18px !important;overflow:hidden !important;box-shadow:0 24px 70px rgba(8,20,36,.4) !important;border:1px solid #E5E7EB !important;z-index:9600 !important;animation:kt-fade-in .18s ease both !important;}}'
    ].join('');
    // Home visitor AND sales rep get the IDENTICAL chrome: every rule above is
    // scoped to role-guardian (paired with role-educator), so whole-sheet copies
    // with role-guardian → role-home-visitor / role-sales-rep give them the same
    // styling without hand-maintaining extra selectors on each rule.
    var _base = s.textContent;
    s.textContent = _base
      + _base.replace(/role-guardian/g, 'role-home-visitor')
      + _base.replace(/role-guardian/g, 'role-sales-rep');
    document.head.appendChild(s);
  }

  // The single "Good <time>" greeting, injected above the name in the user block.
  // Runs on desktop AND mobile (the app's own JS fills the name itself).
  function fmtDateShort() { try { return new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); } catch (e) { return ''; } }
  function fmtDateShort2() { try { return new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); } catch (e) { return ''; } }

  // Mobile top header: a compact date + time (the desktop bar has weather/date/clock;
  // phones just get date + time, right-aligned before the floating settings gear).
  function ensureMobileMeta() {
    if (isDesktop() || !roleOf()) return;
    // Date/time is folded into the greeting eyebrow now — remove any old right block.
    var old = document.getElementById('kt-pc-mobmeta'); if (old) old.remove();
    injectStyle();
    paintGreeting();
  }

  function ensureGreeting() {
    if (!roleOf()) return;
    injectStyle();
    var navUser = document.querySelector('#appSidebar #navUser, #appSidebar .nav-user');
    if (!navUser) return;
    var txt = navUser.querySelector('.nav-user-text');
    if (txt && !document.getElementById('kt-pc-navgreet')) {
      // Same element order as the admin bar's .kt-tb-left: emoji, greeting, name,
      // role — with the time-of-day emoji as its OWN 20px span rather than a 14px
      // character inside the greeting text.
      var gr = document.createElement('div');
      gr.id = 'kt-pc-navgreet'; gr.className = 'kt-pc-navgreet';
      var em = document.createElement('span');
      em.id = 'kt-pc-navemoji'; em.className = 'kt-pc-navemoji';
      txt.insertBefore(gr, txt.firstChild);
      txt.insertBefore(em, gr);
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

      // All the icon controls grouped together first (Home · Messages · Announcements ·
      // Settings · Sign out), then a separator, then the weather / date / clock.
      // Messages + Announcements were previously admin-only (kt-topbar) — parents,
      // educators and home visitors now get the same quick access + unread badge.
      var msgHash = (role === 'guardian') ? '#messages' : '#chat';
      // Home = the tile launcher for every role (#home renders it). The educator
      // Home used to point at #dashboard (the roster) — but the tiles live on the
      // launcher, so Home now takes every role there.
      wrap.appendChild(iconBtn('🏠', 'Home', function () { location.hash = '#home'; }));
      if (role === 'sales_rep') {
        // Sales rep messaging is the shared portal chat dock (restricted to the sales
        // team + superadmins). Pipeline + Messages (unread indicator) + inbox.
        wrap.appendChild(iconBtn('📊', 'Pipeline', function () { location.hash = '#sales'; }));
        wrap.appendChild(iconBtn('💬', 'Messages', function () { if (window.KT && KT.SalesChat) KT.SalesChat.open(); else location.hash = '#sales-chat'; }, 'kt-sales-chat-badge'));
        wrap.appendChild(iconBtn('🔔', 'Inbox', function () { location.hash = '#notifications'; }));
      } else {
        wrap.appendChild(iconBtn('💬', 'Messages', function () { location.hash = msgHash; }, 'kt-pc-msg-badge'));
        // Team messages icon REMOVED (2026-08-11, per request): the portal keeps ONE
        // messaging surface — Messages/chat above. The #team-messages screen is left
        // registered so any existing deep link or notification still resolves, but it
        // is no longer advertised anywhere in the UI.
        wrap.appendChild(iconBtn('📣', 'Announcements', function () { location.hash = '#announcements'; }));
        /* What's new — for the STAFF roles that now have release notes of their own.
           The 🎁 button lives in kt-topbar, which returns early for these roles, so
           until now an educator could not open it at all: features written for them
           were announced only to admins. Parents are left out deliberately — no entry
           targets them, and a button that always opens an empty panel is clutter. */
        if (['educator', 'home_visitor', 'auditor'].indexOf(role) !== -1) {
          wrap.appendChild(iconBtn('🎁', "What's new", function () {
            if (window.KT && window.KT.openWhatsNew) window.KT.openWhatsNew();
          }, 'kt-tb-wn-badge'));
        }
        // Notification bell for educators + home visitors (their role-relevant
        // inbox). Parents keep just Messages/Announcements.
        if (role !== 'guardian') wrap.appendChild(iconBtn('🔔', 'Notifications', function () { location.hash = '#notifications'; }, 'kt-pc-notif-badge'));
      }
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
    var nu = bar.querySelector('#navUser, .nav-user');
    // Place "Viewing as" + the agency switcher RIGHT AFTER the user's name (left),
    // like the super-admin view — not stranded at the far right. Order:
    // [name][View as][agency] … then the icon/clock cluster (margin-left:auto).
    // CRITICAL: idempotent — a bar MutationObserver re-runs ensure() on every
    // child change, so only move when not already positioned (else infinite loop).
    if (ag && va && nu) {
      var positioned = (va.previousElementSibling === nu) && (ag.previousElementSibling === va);
      if (!positioned) {
        va.classList.add('kt-viewas-inbar');
        bar.insertBefore(va, nu.nextSibling);
        ag.classList.add('kt-agency-inbar');
        bar.insertBefore(ag, va.nextSibling);
        var dd = ag.querySelector('div[style*="absolute"]');
        if (dd) { dd.style.top = 'calc(100% + 4px)'; dd.style.bottom = 'auto'; }
      }
    }

    placeBack();
  }

  function paintGreeting() {
    var gr = document.getElementById('kt-pc-navgreet');
    if (!gr) return;
    var p = greetParts();
    var em = document.getElementById('kt-pc-navemoji');
    if (isDesktop()) {
      if (em) em.textContent = p.e;              // 20px emoji in its own span
      gr.textContent = p.g + ',';                // greeting text carries no emoji
      // The admin bar greets by FIRST name ("Good evening, Sarah"), so the name
      // beside us must match. The shell renders the full name into .nav-user-name;
      // rewrite it each paint (idempotent, and survives the shell re-rendering).
      var nameEl = document.querySelector('#appSidebar #navUser .nav-user-name');
      var fn = firstName();
      if (nameEl && fn && nameEl.textContent.trim() !== fn) nameEl.textContent = fn;
      return;
    }
    if (em) em.textContent = '';                 // phones keep the compact eyebrow
    // Phones: fold date + time into the eyebrow so the full name below keeps its width.
    gr.textContent = greetWord() + ' \u00b7 ' + fmtClock();
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
  // Messages unread badge — parents read /parent/messages, everyone else the
  // team chat (/chats). Mirrors the super-admin bar's badge behaviour.
  function refreshUnread() {
    var bd = document.getElementById('kt-pc-msg-badge');
    if (!bd) return;
    var role = roleOf();
    if (!role) return;
    var t; try { t = store('kt_token'); } catch (e) {}
    if (!t) return;
    var path = (role === 'guardian') ? '/parent/messages/unread-count' : '/chats/unread-count';
    fetch(API + path, { headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var n = 0;
        if (d) n = (d.unread_count != null ? d.unread_count : (d.count != null ? d.count : (d.unread != null ? d.unread : 0)));
        var el = document.getElementById('kt-pc-msg-badge'); if (!el) return;
        if (n > 0) { el.textContent = n > 99 ? '99+' : n; el.hidden = false; }
        else { el.hidden = true; }
      })
      .catch(function () {});
  }

  // Notifications unread badge (educators / home visitors). Cached count is
  // re-applied on every bar re-render so the badge never flickers; the actual
  // fetch is throttled to ~12s so the 2s boot loop doesn't hammer the API.
  var _lastNotif = 0, _notifCount = 0;
  function applyNotifBadge() {
    var el = document.getElementById('kt-pc-notif-badge'); if (!el) return;
    if (_notifCount > 0) { el.textContent = _notifCount > 99 ? '99+' : _notifCount; el.hidden = false; } else { el.hidden = true; }
  }
  function refreshNotif(force) {
    applyNotifBadge();
    if (! document.getElementById('kt-pc-notif-badge')) return;
    var now = Date.now(); if (! force && now - _lastNotif < 12000) return; _lastNotif = now;
    var t; try { t = store('kt_token'); } catch (e) {} if (! t) return;
    fetch(API + '/notifications', { headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (! d) return; var rows = d.notifications || d.data || (Array.isArray(d) ? d : []); var n = 0; for (var i = 0; i < rows.length; i++) { if (! rows[i].read_at) n++; } _notifCount = n; applyNotifBadge(); })
      .catch(function () {});
  }

  var _booting = false;
  function boot() {
    if (_booting) return;
    _booting = true;
    try { ensureGreeting(); ensureMobileMeta(); ensure(); placeBack(); refreshUnread(); refreshNotif(); } catch (e) {} finally { _booting = false; }
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
  setInterval(refreshUnread, 15000);   // was 60s; matches the top bar so the two agree
  w.addEventListener('resize', function () {
    var wrap = document.getElementById('kt-pc-wrap');
    if (!isDesktop()) { if (wrap) wrap.remove(); ensureGreeting(); }
    else boot();
  });
})(window);
