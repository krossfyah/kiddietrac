/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — parent & educator DESKTOP top-bar chrome (2026-07-14).
   On desktop the parent/educator top bar (the sidebar restyled horizontally)
   showed only a logo and the user block. Super admins get a Home button, the
   date/clock, weather and a settings gear — this gives parents and educators
   the same, in their own top bar, on desktop only. Mobile keeps its bottom nav
   + floating gear untouched (this module no-ops below 769px).
   Purely additive: one controls cluster injected before the user block.
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

  // ── clock / date / weather (same look + source as the admin top bar) ──
  function fmtClock() {
    var d = new Date(), h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM', hh = h % 12; if (hh === 0) hh = 12;
    return hh + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap;
  }
  function fmtDate() { try { return new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }); } catch (e) { return ''; } }
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
    b.type = 'button';
    b.className = 'kt-pc-btn';
    b.title = title;
    b.setAttribute('aria-label', title);
    b.textContent = emoji;
    b.addEventListener('click', function (e) { e.preventDefault(); fn(); });
    return b;
  }

  function injectStyle() {
    if (document.getElementById('kt-pc-style')) return;
    var s = document.createElement('style'); s.id = 'kt-pc-style';
    s.textContent = [
      '.kt-pc-wrap{display:flex;align-items:center;gap:10px;margin-left:auto;margin-right:14px;}',
      '.kt-pc-btn{width:38px;height:38px;border-radius:11px;border:1px solid rgba(15,23,42,.10);',
      '  background:#fff;font-size:17px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;',
      '  box-shadow:0 1px 2px rgba(16,40,64,.05);transition:background .12s,border-color .12s;}',
      '.kt-pc-btn:hover{background:#F1F5F9;border-color:#94A3B8;}',
      '.kt-pc-wx{font-size:12.5px;font-weight:600;color:#475569;white-space:nowrap;}',
      '.kt-pc-date{font-size:12.5px;font-weight:600;color:#334155;white-space:nowrap;}',
      '.kt-pc-clock{font-size:13px;font-weight:800;color:#0F172A;white-space:nowrap;}',
      '@media(max-width:768px){.kt-pc-wrap{display:none !important;}}',   // mobile keeps its own chrome
      '@media(max-width:1180px){.kt-pc-date{display:none;}}'
    ].join('');
    document.head.appendChild(s);
  }

  function ensure() {
    if (!isDesktop()) return;
    var role = roleOf();
    if (!role) return;
    var bar = document.getElementById('appSidebar');
    if (!bar) return;
    if (document.getElementById('kt-pc-wrap')) return;   // already injected
    var userBlock = bar.querySelector('.nav-user');
    injectStyle();

    var wrap = document.createElement('div');
    wrap.className = 'kt-pc-wrap'; wrap.id = 'kt-pc-wrap';

    // Home — parents land on #home, educators on #dashboard (their tile launcher).
    wrap.appendChild(iconBtn('🏠', 'Home', function () {
      location.hash = (role === 'educator') ? '#dashboard' : '#home';
    }));

    var wx = document.createElement('span'); wx.className = 'kt-pc-wx'; wx.id = 'kt-pc-wx'; wx.style.display = 'none'; wrap.appendChild(wx);
    var dt = document.createElement('span'); dt.className = 'kt-pc-date'; dt.id = 'kt-pc-date'; dt.textContent = fmtDate(); wrap.appendChild(dt);
    var ck = document.createElement('span'); ck.className = 'kt-pc-clock'; ck.id = 'kt-pc-clock'; ck.textContent = fmtClock(); wrap.appendChild(ck);

    // Settings gear — same destination as the mobile floating gear.
    wrap.appendChild(iconBtn('⚙️', 'Settings', function () { location.hash = '#settings'; }));

    if (userBlock && userBlock.parentNode === bar) bar.insertBefore(wrap, userBlock);
    else bar.appendChild(wrap);

    loadWeather(function (txt) { var el = document.getElementById('kt-pc-wx'); if (el && txt) { el.textContent = txt; el.style.display = ''; } });
    placeBack();
  }

  function tick() {
    var c = document.getElementById('kt-pc-clock'); if (c) c.textContent = fmtClock();
    var d = document.getElementById('kt-pc-date'); if (d) d.textContent = fmtDate();
  }

  // Keep the floating "← Back" button clear of the top bar whatever its height (it is
  // taller in the super-admin "View as" preview, and can wrap on narrow widths). Position
  // it just below the sticky bar's actual bottom instead of a hard-coded offset.
  function placeBack() {
    if (!isDesktop() || !roleOf()) return;
    var back = document.getElementById('kt-role-back');
    var bar = document.getElementById('appSidebar');
    if (!back || !bar) return;
    var b = Math.round(bar.getBoundingClientRect().bottom);
    if (b > 0) back.style.top = (b + 12) + 'px';
  }

  ensure();
  placeBack();
  setInterval(function(){ ensure(); placeBack(); }, 1200);   // re-inject if the bar is rebuilt; no-op once present
  setInterval(tick, 15000);
  w.addEventListener('resize', function () {
    var wrap = document.getElementById('kt-pc-wrap');
    if (!isDesktop() && wrap) { wrap.remove(); }
    else if (isDesktop()) ensure();
  });
})(window);
