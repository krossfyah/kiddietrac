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

  /**
   * Who gets the greeting eyebrow ("Good evening · 12:38").
   *
   * Wider than roleOf() on purpose, and used ONLY by the greeting. boot() also calls
   * ensure(), the desktop top-bar builder, which is gated on roleOf() — widening that
   * would rebuild the admin DESKTOP into the parent top bar. Admins and directors want
   * the phone eyebrow, not a new desktop.
   */
  function roleOfGreet() {
    var r = roleOf();
    if (r) { return r; }
    var c = document.body.className || '';
    /* Hyphens: app-v2-shell builds the class as 'role-' + role.replace('_','-'). */
    return /\brole-(agency-admin|centre-director|platform-admin)\b/.test(c) ? 'admin' : '';
  }
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
    var t = (window.KT && KT.agencyHM) ? KT.agencyHM() : { h: new Date().getHours(), m: new Date().getMinutes() }, h = t.h, ap = h >= 12 ? 'PM' : 'AM', hh = h % 12; if (hh === 0) hh = 12;   // agency time (kt-tz.js)
    return hh + ':' + String(t.m).padStart(2, '0') + ' ' + ap;
  }
  // Short weekday — byte-identical to the admin bar's date ("Tue, August 11, 2026").
  // Repainted every tick, so translate at the source (2026-10-22); kt-i18n may not be loaded yet.
  function ktT(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function fmtDate() { try { var tag = { fr: 'fr-CA', es: 'es-ES', hi: 'hi-IN' }[localStorage.getItem('kt_locale')]; var o = { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' }; return (window.KT && KT.agencyNowText) ? KT.agencyNowText(o, tag) : new Date().toLocaleDateString(tag, o); } catch (e) { return ''; } }
  // Time-of-day greeting WITH its icon. Deliberately the same words + emoji as
  // kt-topbar.js's greetEmoji() (the admin/director bar) — parents and educators
  // were getting the bare words while admins got "🌆 Good evening".
  function greetParts() {
    var h = ((window.KT && KT.agencyHM) ? KT.agencyHM() : { h: new Date().getHours() }).h;   // the centre's hour
    if (h < 5) return { g: 'Good night', e: '🌙' };     // pre-dawn, not "morning"
    if (h < 12) return { g: 'Good morning', e: '🌅' };
    if (h < 17) return { g: 'Good afternoon', e: '☀️' };
    if (h < 22) return { g: 'Good evening', e: '🌆' };
    return { g: 'Good night', e: '🌙' };
  }
  function greetWord() { var p = greetParts(); return p.e + ' ' + ktT(p.g); }
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
        .then(function (r) { return r.json(); }).then(function (wx) { done(wxEmoji(wx.current.weather_code) + ' ' + Math.round(wx.current.temperature_2m) + (fahr ? '°F' : '°C')); });
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
      '@media(max-width:768px){#appSidebar .kt-pc-navgreet.kt-pc-navgreet{display:flex !important;gap:3px;min-width:0;overflow:visible !important;text-overflow:clip !important;}#appSidebar .kt-pc-gw{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}#appSidebar .kt-pc-gt{flex:0 0 auto;white-space:nowrap;}}',
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
      // A tooltip hanging below the bar must not make the bar scroll (that shifted the shortcuts 15px on hover), and the
      // floating Settings gear needs its own room at the right end instead of covering the clock.
      '@media(min-width:769px){body.role-guardian #appSidebar,body.role-educator #appSidebar,body.role-home-visitor #appSidebar,body.role-sales-rep #appSidebar{overflow-x:clip !important;overflow-y:visible !important;}body.kt-pc-gear .kt-pc-wrap{margin-right:58px;}}',
      /* SUPER-ADMIN PREVIEW (2026-11-04): row 1 is brand · name · the icon bar (right). The admin controls go on the
         rows below at their own size; the shared sidebar foot gives each control width:100%, which stacked them into
         full-width rows ABOVE the icon bar and pushed it onto a later line. */
      /* Every role with this header (guardian, educator, home visitor, sales rep), not only body.kt-parentview -
         that class is set for a guardian view alone, so the Educator preview stacked again (2026-11-04). */
      pcBarCss(),
      // Match the super-admin top-bar icon sizing (kt-tb-ico): 31x31, 9px radius,
      // 15px glyph — so the action icons are the same size across every role.
      '.kt-pc-btn{position:relative;width:31px;height:31px;border-radius:9px;border:1px solid rgba(15,23,42,.10);',
      '  background:rgba(15,23,42,.03);font-size:15px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#334155;padding:0;',
      '  transition:background .12s,border-color .12s;}',
      '.kt-pc-btn:hover{background:#F1F5F9;border-color:#64748B;}',
      // Hover label: kt-tooltip-global.js draws it (one bubble portal-wide - a local ::after here doubled it).
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
      // ...and the BANNER above it has to agree. Keeping the form narrow while its
      // banner still spanned the whole window left a 100px gradient bar 1865px wide
      // heading a 600px column - the same mismatch My Hours had, where the banner
      // promised a full-width screen and the content sat in the middle of it. A
      // banner is a heading for the thing under it, so it is as wide as that thing.
      // :has() is doing the looking-ahead CSS otherwise cannot; where it is not
      // supported the rule simply does not apply and nothing changes.
      'body.role-guardian #appMain:has(> div[style*="max-width:520px"]) .kt-hero,',
      'body.role-educator #appMain:has(> div[style*="max-width:520px"]) .kt-hero{max-width:600px !important;margin-left:auto !important;margin-right:auto !important;}',
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
  function fmtDateShort() { try { var o = { weekday: 'short', month: 'short', day: 'numeric' }; return (window.KT && KT.agencyNowText) ? KT.agencyNowText(o) : new Date().toLocaleDateString(undefined, o); } catch (e) { return ''; } }
  function fmtDateShort2() { try { var o = { month: 'short', day: 'numeric' }; return (window.KT && KT.agencyNowText) ? KT.agencyNowText(o) : new Date().toLocaleDateString(undefined, o); } catch (e) { return ''; } }

  // Mobile top header: a compact date + time (the desktop bar has weather/date/clock;
  // phones just get date + time, right-aligned before the floating settings gear).
  function ensureMobileMeta() {
    if (isDesktop() || !roleOfGreet()) return;
    // Date/time is folded into the greeting eyebrow now — remove any old right block.
    var old = document.getElementById('kt-pc-mobmeta'); if (old) old.remove();
    injectStyle();
    paintGreeting();
  }

  /* NOT ON THE DESKTOP ADMIN SIDEBAR — and nowhere else.

     Anthony, 2026-09-06: "on the side bar where it shows my name at the bottom remove the
     good night and the good night icon and role and just leave my display pic/avatar and
     first name". That is the admin/director/platform DESKTOP sidebar, where the user block
     sits at the foot of a real sidebar.

     It is NOT the parent/educator chrome, where this same user block IS the top bar, and
     it is NOT the phone. An earlier version of this returned unconditionally and took the
     greeting from guardians, educators, home visitors and sales reps on desktop, and from
     EVERY role on mobile — because ensureGreeting() is called from boot() for any role,
     and because paintGreeting() (which the phone eyebrow depends on) returns immediately
     when the element this function creates is missing.

     roleOf() null + desktop == the admin sidebar layout: roleOfGreet() is deliberately
     wider than roleOf() precisely so admins still get the phone eyebrow. */
  function ensureGreeting() {
    if (isDesktop() && !roleOf()) { return; }   // admin/director desktop sidebar only
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
      /* The educator's Check-in QR at desktop/tablet width (2026-10-05). On a phone it is
         the raised button in the bottom bar; wider screens have no bottom bar, so the QR
         parents scan at drop-off could not be reached. Same overlay (kt-mobilenav). */
      if (role === 'educator') {
        var oneTapBtn = iconBtn('📷', 'One-Tap', function () {
          // The same two choices as the phone bar (2026-11-24), dropping down from this icon.
          if (window.KT && window.KT.oneTapMenu) window.KT.oneTapMenu(oneTapBtn);
          else if (window.KT && window.KT.showCheckinQr) window.KT.showCheckinQr();
        });
        // The OFFICIAL One-Tap mark, as-is, instead of 📷 (2026-11-24).
        oneTapBtn.textContent = '';
        oneTapBtn.insertAdjacentHTML('afterbegin', '<svg width="22" height="22" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="display:block;"><defs><linearGradient id="ktOtmTop" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#12307A"/><stop offset="1" stop-color="#0A1E55"/></linearGradient></defs><rect width="64" height="64" rx="17" fill="url(#ktOtmTop)"/><path d="M19.55 31.01 A13 13 0 0 1 32.99 44.45" fill="none" stroke="#1DB9C3" stroke-width="4.6" stroke-linecap="round" opacity="1"/><path d="M19.27 23.01 A21 21 0 0 1 40.99 44.73" fill="none" stroke="#1DB9C3" stroke-width="4.6" stroke-linecap="round" opacity="0.8"/><path d="M18.99 15.02 A29 29 0 0 1 48.98 45.01" fill="none" stroke="#1DB9C3" stroke-width="4.6" stroke-linecap="round" opacity="0.6"/><circle cx="20" cy="44" r="7.2" fill="#fff"/><circle cx="20" cy="44" r="2.6" fill="#1DB9C3"/></svg>');
        wrap.appendChild(oneTapBtn);
      }
      if (role === 'sales_rep') {
        // Sales rep messaging is the shared portal chat dock (restricted to the sales
        // team + superadmins). Pipeline + Messages (unread indicator) + inbox.
        wrap.appendChild(iconBtn('📊', 'Pipeline', function () { location.hash = '#sales'; }));
        wrap.appendChild(iconBtn('💬', 'Messages', function () { if (window.KT && KT.SalesChat) KT.SalesChat.open(); else location.hash = '#sales-chat'; }, 'kt-sales-chat-badge'));
        wrap.appendChild(iconBtn('📮', 'Sales email', function () { location.hash = '#sales-email'; }, 'kt-pc-salesmail-badge'));
        wrap.appendChild(iconBtn('🔔', 'Inbox', function () { location.hash = '#notifications'; }));
      } else {
        wrap.appendChild(iconBtn('💬', 'Messages', function () { location.hash = msgHash; }, 'kt-pc-msg-badge'));
        // Team messages icon REMOVED (2026-08-11, per request): the portal keeps ONE
        // messaging surface — Messages/chat above. The #team-messages screen is left
        // registered so any existing deep link or notification still resolves, but it
        // is no longer advertised anywhere in the UI.
        // With its unread count (2026-11-09): the 📣 had no badge, so new announcements never showed on this bar.
        wrap.appendChild(iconBtn('📣', 'Announcements', function () { location.hash = '#announcements'; }, 'kt-pc-ann-badge'));
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
      gr.removeAttribute('data-k');
      gr.textContent = ktT(p.g) + ',';                // greeting text carries no emoji
      // The admin bar greets by FIRST name ("Good evening, Sarah"), so the name
      // beside us must match. The shell renders the full name into .nav-user-name;
      // rewrite it each paint (idempotent, and survives the shell re-rendering).
      var nameEl = document.querySelector('#appSidebar #navUser .nav-user-name');
      var fn = firstName();
      if (nameEl && fn && nameEl.textContent.trim() !== fn) nameEl.textContent = fn;
      return;
    }
    if (em) em.textContent = '';                 // phones keep the compact eyebrow
    /* Phones: the time rides in the eyebrow so the full name below keeps its width. The
       eyebrow is one short line, and as plain text its ellipsis always ate the END - the
       "AM/PM" (Anthony, iPhone 2026-10-29: "the clock at the top cut off"). Two spans: the
       greeting words give way, the time never does. Written only when it changes. */
    var gp = greetParts(), gw = gp.e + ' ' + ktT(gp.g) + ' ·', gt = fmtClock(), key = gw + '|' + gt;
    gr.title = ktT(gp.g);   // the words on a long-press; on a phone the eyebrow is the emoji + the time
    if (gr.getAttribute('data-k') === key) return;
    gr.setAttribute('data-k', key);
    gr.textContent = '';
    var a = document.createElement('span'); a.className = 'kt-pc-gw'; a.textContent = gw;
    var b = document.createElement('span'); b.className = 'kt-pc-gt'; b.textContent = gt;
    gr.appendChild(a); gr.appendChild(b);
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
    (window.KT && KT.badges ? KT.badges.get().then(function (b) { return b ? { unread: role === 'guardian' ? b.parent_messages : (KT.badges.msgs ? KT.badges.msgs(b) : b.chats) } : null; })
      : fetch(API + path, { headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t } }).then(function (r) { return r.ok ? r.json() : null; }))
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
    /* Write only on a change: every write wakes the page-wide observers (tooltips, line icons, switches...). */
    var txt = _notifCount > 99 ? '99+' : String(_notifCount), hide = !(_notifCount > 0);
    if (!hide && el.textContent !== txt) el.textContent = txt;
    if (el.hidden !== hide) el.hidden = hide;
  }
  function refreshNotif(force) {
    applyNotifBadge();
    if (! document.getElementById('kt-pc-notif-badge')) return;
    var now = Date.now(); if (! force && now - _lastNotif < 12000) return; _lastNotif = now;
    var t; try { t = store('kt_token'); } catch (e) {} if (! t) return;
    if (window.KT && KT.badges) { KT.badges.get(force).then(function (b) { if (b) { _notifCount = b.notifications || 0; applyNotifBadge(); } }); return; }
    fetch(API + '/notifications', { headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (! d) return; var rows = d.notifications || d.data || (Array.isArray(d) ? d : []); var n = 0; for (var i = 0; i < rows.length; i++) { if (! rows[i].read_at) n++; } _notifCount = n; applyNotifBadge(); })
      .catch(function () {});
  }

  // Real time (kt-badges.js, 2026-10-30): any change repaints at once.
  // The 📮 Sales email inbox's unread count (2026-11-04), from /badges like the others.
  function refreshSalesMail() {
    if (!document.getElementById('kt-pc-salesmail-badge') || !(window.KT && KT.badges)) return;
    KT.badges.get().then(function (b) {
      var el = document.getElementById('kt-pc-salesmail-badge'); if (!el || !b) return;
      var n = parseInt(b.sales_email, 10) || 0;
      if (n > 0) { el.textContent = n > 99 ? '99+' : n; el.hidden = false; } else { el.hidden = true; }
    }).catch(function () {});
  }
  /* THE FREEZE (tickets #257-#264, 2026-11-24). This listener called refreshNotif(true) -> KT.badges.get(true). The
     8s count cache in kt-live.js answers /badges at once, so apply() fired kt:badges again, which forced again: an
     endless loop (~334 events/s, 0% idle main thread) for as long as #home was open, felt as 11-34s freezes on a
     slower laptop. The event already CARRIES the counts - use them; never fetch from inside a kt:badges listener. */
  window.addEventListener('kt:badges', function (e) {
    try {
      refreshUnread();
      if (e && e.detail) { _notifCount = +e.detail.notifications || 0; applyNotifBadge(); }
      refreshSalesMail();
    } catch (x) {}
  });
  // 📣: the Announcements screen's own unread count, straight from the /badges answer.
  window.addEventListener('kt:badges', function (e) {
    var bd = document.getElementById('kt-pc-ann-badge'); if (!bd) return;
    var n = parseInt((e.detail && e.detail.announcements) || 0, 10) || 0;
    if (n > 0) { bd.textContent = n > 99 ? '99+' : String(n); bd.hidden = false; } else { bd.hidden = true; }
    var btn = bd.parentNode; if (btn) btn.setAttribute('aria-label', 'Announcements' + (n > 0 ? ', ' + n + ' unread' : ''));
  });

  /* The preview bar rules, one per header role (see the style block). */
  /* A function declaration, not a var: injectStyle() can run before this line on a phone (the var was still
     undefined there, so the phone rules never landed). */
  function pcBarCss() {
    var R = ['role-guardian', 'role-educator', 'role-home-visitor', 'role-sales-rep'];
    function sel(tail) { return R.map(function (r) { return 'body.' + r + ' #appSidebar > ' + tail; }).join(','); }
    /* AT THE VERY TOP (2026-11-04, Anthony: "for impersonation add the viewing as, agency selector at the very top and
       above the kiddietrac top bar" - the educator / parent / home visitor / sales rep previews). Row 1, one compact
       line: the role being previewed, the agency, "View as..." (the person picker) and "Hide admin controls" at the
       end. Row 2: brand, name, icon bar - the bar exactly as that person sees it. */
    /* ONLY WITH THE PREVIEW CONTROLS (2026-11-04, phone header fix): body.kt-pc-tworow (syncRows) - a person's own header
       is one row, and on a phone that row does not wrap, so a break there is not 0px: it took ~200px and pushed the logo
       and the name off the screen. */
    var brk = R.map(function (r) { return 'body.kt-pc-tworow.' + r + ' #appSidebar::after'; }).join(',');   // no :has() (phones); an empty break is 0px tall
    return '@media(min-width:769px){'
      // A line break (flex-basis 100%) between the two rows, so the admin controls always sit together on row 1.
      + brk + '{content:"";order:-1;flex-basis:100%;height:0;}'
      + sel('.kt-viewas-inbar') + ',' + sel('.kt-agency-inbar') + ',' + sel('#kt-sidebar-foot') + '{width:auto !important;margin:0 8px 6px 0 !important;align-self:center;}'
      + sel('.kt-viewas-inbar') + '{order:-4;flex:0 0 auto !important;flex-wrap:nowrap !important;padding-top:4px !important;padding-bottom:4px !important;}'
      + sel('.kt-viewas-inbar > span') + '{flex:0 0 auto !important;}'
      + sel('.kt-viewas-inbar > select') + '{flex:0 0 auto !important;width:auto !important;min-width:170px;}'
      + sel('.kt-agency-inbar') + '{order:-3;flex:0 0 auto !important;}'
      + sel('#kt-sidebar-foot') + '{order:-2;flex:1 1 320px !important;flex-direction:row !important;align-items:center;gap:8px !important;padding:0 !important;margin-right:0 !important;}'
      + sel('#kt-sidebar-foot > *') + '{flex:0 1 auto;width:auto !important;}'
      + sel('#kt-sidebar-foot > #kt-adminctl') + '{flex:0 0 auto !important;order:2;margin-left:auto !important;}}'
      // Phones: the same order - the admin controls (all inside the foot there) first, the header below them.
      + '@media(max-width:768px){' + sel('#kt-sidebar-foot') + '{order:-2 !important;}' + brk + '{content:"";order:-1;flex-basis:100%;height:0;}'
      + R.map(function (r) { return 'body.kt-pc-tworow.' + r + ' #appSidebar'; }).join(',') + '{flex-wrap:wrap !important;}}'
      /* THE FIT GUARD's way out (guardHeaderFit): the header wraps and no break is drawn, so nothing stays off screen. */
      + R.map(function (r) { return 'body.kt-pc-fitfix.' + r + ' #appSidebar'; }).join(',') + '{flex-wrap:wrap !important;}'
      + R.map(function (r) { return 'body.kt-pc-fitfix.' + r + ' #appSidebar::after'; }).join(',') + '{display:none !important;}';
  }

  /* Two rows only while an admin is previewing this header (the view-as / agency / admin controls are in it). */
  function syncRows() {
    var bar = document.getElementById('appSidebar');
    var on = !!bar && !!bar.querySelector('#kt-sidebar-foot, .kt-viewas-inbar, .kt-agency-inbar');
    if (document.body.classList.contains('kt-pc-tworow') !== on) document.body.classList.toggle('kt-pc-tworow', on);
  }

  /* HEADER FIT GUARD (2026-11-04, "add guards to not allow this from happening again"). Whatever a future rule does, a
     part of this header that ends up outside the window (the name, the role, the logo pushed off the right edge) is
     caught here: the header is switched to wrapping (body.kt-pc-fitfix) so everything comes back on screen, and the
     device says so once per page load in storage/app/scroll-diag.log ("HEADER-FIT"), with what was off and where, so
     the rule behind it can be found and fixed. The admin top bar (kt-topbar.js) has its own fit logic. */
  var _fitSent = false;
  function guardHeaderFit() {
    if (!roleOf()) return;
    var bar = document.getElementById('appSidebar');
    if (!bar || document.body.classList.contains('kt-pc-fitfix')) return;
    var vw = w.innerWidth || document.documentElement.clientWidth, off = [];
    /* NOT FROM A WINDOW WITH NO WIDTH (2026-11-06, Anthony's home visitor header stuck on two rows): a beacon read
       "HEADER-FIT ... vw=1 off: navBrand 18-185 ..." - measured while the page had no real width (a device-mode switch
       or a hidden frame), every part was "off screen", and the wrap stayed on for the rest of the session. No phone
       is narrower than 240px, so a smaller window is not a layout to judge. */
    if (vw < 240) return;
    var els = bar.querySelectorAll('#navBrand, #navUser, #navUser .nav-user-text, #navRole, .kt-pc-gt, .kt-pc-wrap, .kt-pc-mobmeta, #kt-sidebar-foot');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (!el.offsetParent) continue;
      var r = el.getBoundingClientRect();
      if (!r.width || !r.height) continue;
      if (r.right > vw + 2 || r.left < -2) off.push((el.id || el.className.split(' ')[0]) + ' ' + Math.round(r.left) + '-' + Math.round(r.right));
    }
    if (!off.length) return;
    document.body.classList.add('kt-pc-fitfix');
    if (_fitSent) return;
    _fitSent = true;
    try {
      var api = (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
      fetch(api + '/diag/scroll', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ data: ('HEADER-FIT ' + document.body.className + ' vw=' + vw + ' off: ' + off.join(', ')).slice(0, 900) }) }).catch(function () {});
    } catch (e) {}
  }

  /* Room for the floating Settings gear (kt-mobilenav.js) only while it is actually on screen: a phone or tablet app.
     A desktop browser hides it, and the bar then runs to the right edge like the staff top bar. No :has() (phones). */
  function syncGear() {
    var g = document.getElementById('kt-gear');
    var on = !!g && w.getComputedStyle(g).display !== 'none';
    if (document.body.classList.contains('kt-pc-gear') !== on) document.body.classList.toggle('kt-pc-gear', on);
  }

  var _booting = false;
  function boot() {
    if (_booting) return;
    _booting = true;
    try { ensureGreeting(); ensureMobileMeta(); ensure(); placeBack(); syncGear(); syncRows(); refreshUnread(); refreshNotif(); } catch (e) {} finally { _booting = false; }
    // After layout settles: a header part pushed off screen is put back (and reported) - see guardHeaderFit.
    try { (w.requestAnimationFrame || setTimeout)(function () { try { guardHeaderFit(); } catch (e) {} }); } catch (e) {}
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
    // A new width is a new layout: the fit guard judges it again (boot() re-runs it) instead of keeping an old verdict.
    document.body.classList.remove('kt-pc-fitfix');
    var wrap = document.getElementById('kt-pc-wrap');
    if (!isDesktop()) { if (wrap) wrap.remove(); ensureGreeting(); try { (w.requestAnimationFrame || setTimeout)(function () { try { guardHeaderFit(); } catch (e) {} }); } catch (e) {} }
    else boot();
  });
})(window);
