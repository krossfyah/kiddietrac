/* ============================================================
   KIDDIETRAC v22p20 — Multi-agency switcher widget
   Self-installs above the sidebar user pill when /auth/agencies
   returns more than one. Defaults the active agency to the user's
   first assignment if nothing in sessionStorage yet.
   ============================================================ */
(function (window) {
  'use strict';

  var STORAGE_KEY = 'kt_active_agency_id';

  // Which agency a platform admin lands in.
  //
  // This used to be agencies[0] — and the list is alphabetical, so a super admin
  // opened the app inside the LIVE agency, looking at real families, every single
  // time. The default now comes from the server (DEFAULT_ADMIN_AGENCY_ID, set to
  // the Test Agency), falling back to the old behaviour only if it isn't set or
  // the agency isn't in their list.
  function defaultAgencyId(agencies) {
    if (!agencies || !agencies.length) return null;
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      // landing_agency_id (/auth/me, 2026-10-21) is the same rule worked out by the server;
      // the splash and brand cache use it too, so all three open the same agency.
      var picks = [u.landing_agency_id, u.default_agency_id];
      for (var i = 0; i < picks.length; i++) {
        var want = picks[i];
        if (want && agencies.some(function (a) { return String(a.id) === String(want); })) {
          return want;
        }
      }
    } catch (e) {}
    return agencies[0].id;
  }

  var ACTIVE_NAME = 'kt_active_agency_name';
  var MOUNT_ATTEMPTS = 0;
  var MAX_MOUNT_ATTEMPTS = 60;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function token() {
    return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
  }

  function apiBase() {
    return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  }

  async function fetchAgencies() {
    var t = token();
    if (!t) return { agencies: [], is_platform_admin: false };
    try {
      var res = await fetch(apiBase() + '/auth/agencies', {
        headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' },
      });
      // 401/403 is an answer (signed out / no access). Anything else is a failure to ask, not a "no".
      if (!res.ok) return { agencies: [], is_platform_admin: false, failed: res.status !== 401 && res.status !== 403 };
      var data = await res.json();
      return { agencies: data.agencies || [], is_platform_admin: !!data.is_platform_admin };
    } catch (e) { return { agencies: [], is_platform_admin: false, failed: true }; }
  }

  /* THE BLOCK MUST NOT GO MISSING (2026-10-26). The signed-in account's own roles say whether it is a platform
     admin - not while impersonating someone (kt_user is then the other person) and not while previewing a role
     with View as. The server still guards every Platform/Sales route; this only decides whether the links show. */
  function ownPlatformAdmin() {
    try {
      if (sessionStorage.getItem('kt_impersonating') || sessionStorage.getItem('kt_view_as')) return false;
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return !!(u && Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1);
    } catch (e) { return false; }
  }
  /* BOTH SECTIONS, CHECKED APART (2026-10-27, Anthony: "when the portal refreshes i lose access
     to the sales and platform views"). Platform and Sales both carried data-platform-section, and
     this check asked only whether ANY such section existed - so when a sidebar rebuild left
     Platform and dropped Sales, Sales never came back (his phone's ROLEVIEW reading: Platform
     shown, Sales not, no View as). Each section now has its own marker; if either is missing,
     whatever is left is removed and both are put back. */
  function bothThere(nl) { return !!(nl.querySelector('[data-kt-sec="platform"]') && nl.querySelector('[data-kt-sec="sales"]')); }
  function ensurePlatformNav() {
    try {
      if (!token() || !ownPlatformAdmin()) return;
      var nl = document.getElementById('navLinks');
      if (!nl) return;
      if (!bothThere(nl)) {
        [].forEach.call(nl.querySelectorAll('[data-platform-section]'), function (n) { n.remove(); });
        injectPlatformNav();
      }
      // Watch the list itself too (a rebuilt sidebar is a new node, so re-attach when it changes).
      if (nl !== ensurePlatformNav.watched && window.MutationObserver) {
        ensurePlatformNav.watched = nl;
        new MutationObserver(function () {
          if (!bothThere(nl)) setTimeout(ensurePlatformNav, 0);
        }).observe(nl, { childList: true });
      }
    } catch (e) {}
  }
  window.addEventListener('hashchange', ensurePlatformNav);
  // A rebuilt sidebar is a NEW #navLinks the watcher above never saw: check every few seconds too.
  setInterval(ensurePlatformNav, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) ensurePlatformNav(); });

  // v22p22: inject a "Platform" sidebar section for platform_admin.
  function injectPlatformNav() {
    var navLinks = document.getElementById('navLinks');
    if (! navLinks || bothThere(navLinks)) return;
    [].forEach.call(navLinks.querySelectorAll('[data-platform-section]'), function (n) { n.remove(); });   // a partial set
    var section = document.createElement('div');
    section.className = 'sidebar-section';
    section.setAttribute('data-platform-section', '1');
    section.setAttribute('data-kt-sec', 'platform');
    section.style.marginTop = '12px';
    section.innerHTML =
      '<div class="sidebar-section-label" style="color:#7C3AED;">🌐 Platform</div>' +
      '<a href="#platform-overview" class="nav-link" data-hash="platform-overview">' +
        '<span class="nav-icon">🌐</span><span class="nav-label">Platform overview</span>' +
      '</a>' +
      '<a href="#platform-agencies" class="nav-link" data-hash="platform-agencies">' +
        '<span class="nav-icon">🏢</span><span class="nav-label">All agencies</span>' +
      '</a>' +
      // Server health (2026-10-22): the machine, database, jobs, errors, certificates, backups.
      '<a href="#server-health" class="nav-link" data-hash="server-health">' +
        '<span class="nav-icon">🖥️</span><span class="nav-label">Server health</span>' +
      '</a>' +
      // Licences (2026-10-24): plans and add-ons per agency, expiry, renewal, sales by part #.
      '<a href="#licences" class="nav-link" data-hash="licences">' +
        '<span class="nav-icon">🔑</span><span class="nav-label">Licences</span>' +
      '</a>' +
      // Careers (2026-10-30): job applications from the website, CVs and cover letters.
      '<a href="#platform-careers" class="nav-link" data-hash="platform-careers">' +
        '<span class="nav-icon">📨</span><span class="nav-label">Careers</span>' +
      '</a>' +
      // KiddieTrac's own invoices, beside the licences they bill (2026-10-25; was under Reseller).
      '<a href="#sales-invoices" class="nav-link" data-hash="sales-invoices">' +
        '<span class="nav-icon">🏦</span><span class="nav-label">Accounting AR/AP</span>' +
      '</a>' +
      // Money & usage, moved from Reseller (2026-10-25).
      '<a href="#admin-mrr" class="nav-link" data-hash="admin-mrr">' +
        '<span class="nav-icon">💰</span><span class="nav-label">MRR dashboard</span>' +
      '</a>' +
      '<a href="#usage-spend" class="nav-link" data-hash="usage-spend">' +
        '<span class="nav-icon">📶</span><span class="nav-label">Usage &amp; spend</span>' +
      '</a>' +
      // Claude key + model, live state, credits estimate, full AI stats (2026-10-28).
      '<a href="#ai-usage" class="nav-link" data-hash="ai-usage">' +
        '<span class="nav-icon">🤖</span><span class="nav-label">AI usage</span>' +
      '</a>' +
      // KiddieTrac's own sales figures (2026-10-24): by part number, kind, month, customer.
      '<a href="#platform-sales" class="nav-link" data-hash="platform-sales">' +
        '<span class="nav-icon">💹</span><span class="nav-label">Sales reports</span>' +
      '</a>' +
      // Platform controls + security & data, moved from Reseller and Settings (2026-10-25).
      '<a href="#admin-features" class="nav-link" data-hash="admin-features">' +
        '<span class="nav-icon">🚩</span><span class="nav-label">Feature flags</span>' +
      '</a>' +
      '<a href="#admin-branding" class="nav-link" data-hash="admin-branding">' +
        '<span class="nav-icon">🎨</span><span class="nav-label">Branding</span>' +
      '</a>' +
      '<a href="#maintenance" class="nav-link" data-hash="maintenance">' +
        '<span class="nav-icon">🛠️</span><span class="nav-label">Maintenance</span>' +
      '</a>' +
      '<a href="#mail-settings" class="nav-link" data-hash="mail-settings">' +
        '<span class="nav-icon">📬</span><span class="nav-label">Mail settings</span>' +
      '</a>' +
      '<a href="#digest-status" class="nav-link" data-hash="digest-status">' +
        '<span class="nav-icon">📰</span><span class="nav-label">AI digest status</span>' +
      '</a>' +
      '<a href="#backups" class="nav-link" data-hash="backups">' +
        '<span class="nav-icon">🗄️</span><span class="nav-label">Backups</span>' +
      '</a>' +
      '<a href="#compliance-evidence" class="nav-link" data-hash="compliance-evidence">' +
        '<span class="nav-icon">🛡️</span><span class="nav-label">Compliance evidence</span>' +
      '</a>' +
      '<a href="#social-settings" class="nav-link" data-hash="social-settings">' +
        '<span class="nav-icon">🔏</span><span class="nav-label">Sign-in methods</span>' +
      '</a>' +
      '<a href="#security-alerts" class="nav-link" data-hash="security-alerts">' +
        '<span class="nav-icon">🚨</span><span class="nav-label">Security alerts</span>' +
      '</a>' +
      // Website (2026-10-23, Anthony: "move website into platform overview on the sidebar").
      '<a href="#marketing-site" class="nav-link" data-hash="marketing-site">' +
        '<span class="nav-icon">🌍</span><span class="nav-label">Website</span>' +
      '</a>';
    /* First GROUP, not first child (2026-11-27): the list's own furniture in front of the groups (the "Collapse all"
       control) stays on top - this block arrives after its fetch and used to land above it. */
    var firstGroup = null;
    for (var c = navLinks.firstElementChild; c; c = c.nextElementSibling) { if (c.classList.contains('sidebar-section')) { firstGroup = c; break; } }
    navLinks.insertBefore(section, firstGroup || navLinks.firstChild);

    // Dedicated Sales section (platform sales CRM) — the full set of shortcuts,
    // mirroring the sales_rep nav. Sits directly under the Platform section.
    var sales = document.createElement('div');
    sales.className = 'sidebar-section';
    sales.setAttribute('data-platform-section', '1');
    sales.setAttribute('data-kt-sec', 'sales');
    sales.style.marginTop = '12px';
    sales.innerHTML =
      '<div class="sidebar-section-label" style="color:#0C6070;">💼 Sales</div>' +
      '<a href="#sales" class="nav-link" data-hash="sales">' +
        '<span class="nav-icon">📊</span><span class="nav-label">Pipeline</span></a>' +
      '<a href="#sales-webchat" class="nav-link" data-hash="sales-webchat">' +
        '<span class="nav-icon">🗨️</span><span class="nav-label">Website chat</span></a>' +
      '<a href="#sales-leads" class="nav-link" data-hash="sales-leads">' +
        '<span class="nav-icon">🎯</span><span class="nav-label">Leads</span></a>' +
      '<a href="#sales-quotes" class="nav-link" data-hash="sales-quotes">' +
        '<span class="nav-icon">📄</span><span class="nav-label">Proposals</span></a>' +
      // Every won lead, its services and AR (2026-11-10).
      '<a href="#sales-customers" class="nav-link" data-hash="sales-customers">' +
        '<span class="nav-icon">🥇</span><span class="nav-label">Customers</span></a>' +
      '<a href="#sales-expenses" class="nav-link" data-hash="sales-expenses">' +
        '<span class="nav-icon">🪙</span><span class="nav-label">Expenses</span></a>' +
      '<a href="#sales-campaigns" class="nav-link" data-hash="sales-campaigns">' +
        '<span class="nav-icon">📣</span><span class="nav-label">Campaigns</span></a>' +
      '<a href="#sales-email" class="nav-link" data-hash="sales-email">' +
        '<span class="nav-icon">📮</span><span class="nav-label">Sales email</span></a>' +
      '<a href="#sales-bookings" class="nav-link" data-hash="sales-bookings">' +
        '<span class="nav-icon">📌</span><span class="nav-label">Bookings</span></a>' +
      '<a href="#sales-calendar" class="nav-link" data-hash="sales-calendar">' +
        '<span class="nav-icon">📆</span><span class="nav-label">Calendar</span></a>' +
      '<a href="#sales-plans" class="nav-link" data-hash="sales-plans">' +
        '<span class="nav-icon">💲</span><span class="nav-label">Plans &amp; pricing</span></a>' +
      '<a href="#sales-demo" class="nav-link" data-hash="sales-demo">' +
        '<span class="nav-icon">🚀</span><span class="nav-label">Launch demo</span></a>' +
      /* Superadmin-only, and this whole section already is — it is injected behind
         the same platform check. Deliberately NOT mirrored into the sales_rep nav
         in app-v2-shell: the repository holds pricing and contract templates, and a
         rep gets 403 from the API. Showing them a door that does not open is worse
         than not showing it. */
      '<a href="#sales-library" class="nav-link" data-hash="sales-library">' +
        '<span class="nav-icon">📁</span><span class="nav-label">Files repository</span></a>';
    navLinks.insertBefore(sales, section.nextSibling);
    try { window.dispatchEvent(new CustomEvent('kt:platform-nav')); } catch (e) {}
  }

  async function setActiveServer(agencyId) {
    var t = token();
    if (!t) return false;
    try {
      var res = await fetch(apiBase() + '/auth/active-agency', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + t,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ agency_id: agencyId }),
      });
      return res.ok;
    } catch (e) { return false; }
  }

  function buildWidget(agencies, activeId, isPlatformAdmin) {
    var active = (String(activeId) === 'all')
      ? { id: 'all', name: 'All agencies' }
      : (agencies.find(function (a) { return String(a.id) === String(activeId); }) || agencies[0]);

    var wrap = document.createElement('div');
    wrap.id = 'kt-agency-switcher';
    /* position:relative is load-bearing — the dropdown is absolutely positioned
       against it. The margins and the divider are NOT: #kt-sidebar-foot supplies the
       padding and the gap now, and a rule halfway up a group of three separated
       controls that belong together. */
    wrap.setAttribute('style', 'position:relative');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('style', [
      'display:flex',
      'align-items:center',
      'gap:8px',
      'width:100%',
      'background:rgba(31,96,128,0.06)',
      'border:1px solid rgba(31,96,128,0.18)',
      'border-radius:8px',
      /* The BUTTON is the visible box — the wrapper around it is a bare
         position:relative anchor for the dropdown, so a height set there would be
         invisible slack. 40px matches the two controls beneath it. */
      'min-height:40px',
      'padding:8px 10px',
      'font-size:12px',
      'color:#1F6080',
      'font-weight:600',
      'cursor:pointer',
      'text-align:left',
      'transition:background .15s',
    ].join(';'));
    var badge = isPlatformAdmin
      ? '<span style="font-size:9px;background:#7C3AED;color:white;padding:1px 5px;border-radius:4px;letter-spacing:0.5px;font-weight:700;margin-right:4px;">PLAT</span>'
      : '';
    btn.innerHTML =
      '<span style="font-size:14px;">🏢</span>' +
      badge +
      '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(active.name) + '</span>' +
      '<span style="font-size:10px;color:#6B7280;">▾</span>';

    var dropdown = document.createElement('div');
    dropdown.setAttribute('style', [
      'position:absolute',
      'bottom:calc(100% + 4px)',
      'left:10px',
      'right:10px',
      'background:white',
      'border:1px solid #D1D5DB',
      'border-radius:10px',
      'box-shadow:0 8px 18px rgba(0,0,0,.14)',
      'z-index:9999',
      'overflow:hidden',
      'display:none',
    ].join(';'));

    var _allActive = String(active.id) === 'all';
    dropdown.innerHTML =
      '<div style="padding:8px 12px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:#6B7280;font-weight:700;background:#F9FAFB;border-bottom:1px solid #F3F4F6;">Switch agency</div>' +
      // Platform admins get an "All agencies" aggregate view at the top.
      (isPlatformAdmin
        ? '<button type="button" data-aid="all" style="display:flex;align-items:center;gap:8px;width:100%;background:' + (_allActive ? '#EFF6FF' : 'white') + ';border:none;padding:9px 12px;font-size:13px;color:#111827;cursor:pointer;text-align:left;border-bottom:1px solid #F3F4F6;">' +
            '<span style="font-size:14px;">🌐</span>' +
            '<span style="flex:1;font-weight:' + (_allActive ? '700' : '600') + ';">All agencies</span>' +
            (_allActive ? '<span style="font-size:11px;color:#1F6080;font-weight:700;">✓</span>' : '') +
          '</button>'
        : '') +
      agencies.map(function (a) {
        var isActive = String(a.id) === String(active.id);
        return '<button type="button" data-aid="' + a.id + '" style="display:flex;align-items:center;gap:8px;width:100%;background:' + (isActive ? '#EFF6FF' : 'white') + ';border:none;padding:9px 12px;font-size:13px;color:#111827;cursor:pointer;text-align:left;border-bottom:1px solid #F3F4F6;">' +
          '<span style="font-size:14px;">🏢</span>' +
          '<span style="flex:1;font-weight:' + (isActive ? '700' : '500') + ';">' + esc(a.name) + '</span>' +
          (isActive ? '<span style="font-size:11px;color:#1F6080;font-weight:700;">✓</span>' : '') +
        '</button>';
      }).join('');

    wrap.appendChild(btn);
    wrap.appendChild(dropdown);

    /* Place the menu against the button, in whichever direction has room, using fixed
       coordinates so no ancestor's overflow can clip it. Called on every open because the
       switcher sits at the bottom of the sidebar on a desktop and in the top bar on a
       phone, and the answer differs. */
    function placeMenu() {
      var r = btn.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var vw = window.innerWidth || document.documentElement.clientWidth;
      var openDown = r.top < vh / 2;
      var gap = 6;
      // Never taller than the room available on the chosen side, and always scrollable.
      var room = (openDown ? (vh - r.bottom) : r.top) - gap - 10;

      dropdown.style.position = 'fixed';
      dropdown.style.left = Math.max(8, Math.min(r.left, vw - Math.max(200, r.width) - 8)) + 'px';
      dropdown.style.width = Math.max(200, Math.min(r.width, vw - 16)) + 'px';
      dropdown.style.right = 'auto';
      dropdown.style.maxHeight = Math.max(140, room) + 'px';
      dropdown.style.overflowY = 'auto';
      dropdown.style.webkitOverflowScrolling = 'touch';
      if (openDown) {
        dropdown.style.top = (r.bottom + gap) + 'px';
        dropdown.style.bottom = 'auto';
      } else {
        dropdown.style.bottom = (vh - r.top + gap) + 'px';
        dropdown.style.top = 'auto';
      }
    }

    function closeMenu() { dropdown.style.display = 'none'; }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (dropdown.style.display === 'none') {
        // Measure with it laid out but not yet painted, so maxHeight is right first time.
        dropdown.style.visibility = 'hidden';
        dropdown.style.display = 'block';
        placeMenu();
        dropdown.style.visibility = '';
      } else {
        closeMenu();
      }
    });
    // Capture-phase pointerdown (2026-11-05): a bubbling click never arrived from a button that stops its click.
    document.addEventListener('pointerdown', function (e) {
      if (dropdown.style.display !== 'none' && !wrap.contains(e.target) && !dropdown.contains(e.target)) closeMenu();
    }, true);
    /* A fixed menu does not travel with its button, so anything that moves the button
       must close it rather than leave it stranded mid-screen. */
    window.addEventListener('resize', closeMenu);
    window.addEventListener('orientationchange', closeMenu);
    window.addEventListener('scroll', function () {
      if (dropdown.style.display !== 'none') placeMenu();
    }, true);

    Array.prototype.forEach.call(dropdown.querySelectorAll('button[data-aid]'), function (b) {
      b.addEventListener('click', async function () {
        var raw = b.getAttribute('data-aid');
        // "All agencies" is a client-side scope signal (header X-Active-Agency-Id:
        // all) — no server active-agency call (it expects a numeric id).
        if (raw === 'all') {
          if (String(active.id) === 'all') { dropdown.style.display = 'none'; return; }
          sessionStorage.setItem(STORAGE_KEY, 'all');
          sessionStorage.setItem(ACTIVE_NAME, 'All agencies');
          window.location.reload();
          return;
        }
        var aid = parseInt(raw, 10);
        if (String(aid) === String(active.id)) {
          dropdown.style.display = 'none';
          return;
        }
        var target = agencies.find(function (a) { return a.id === aid; });
        b.textContent = '⏳ Switching…';
        var ok = await setActiveServer(aid);
        if (!ok) {
          alert('Could not switch agency. You may not have access.');
          b.textContent = target ? target.name : 'Agency ' + aid;
          return;
        }
        sessionStorage.setItem(STORAGE_KEY, String(aid));
        if (target) sessionStorage.setItem(ACTIVE_NAME, target.name);
        // Reload to ensure every screen re-fetches with the new agency scope
        window.location.reload();
      });
    });

    return wrap;
  }

  async function tryMount() {
    MOUNT_ATTEMPTS++;
    if (MOUNT_ATTEMPTS > MAX_MOUNT_ATTEMPTS) return;

    if (!token()) return; // not signed in

    var sidebar = document.getElementById('appSidebar');
    var navUser = document.getElementById('navUser') || document.querySelector('.nav-user');
    if (!sidebar || !navUser) {
      setTimeout(tryMount, 150);
      return;
    }
    if (document.getElementById('kt-agency-switcher')) return; // already mounted

    var resp = await fetchAgencies();
    if (resp.failed) {
      /* The request did not get an answer. Do not decide "not an admin" from that: show the block from the
         account's own roles now, and ask again (2s, 4s, 8s ... up to 60s apart, and when the network returns). */
      ensurePlatformNav();
      MOUNT_ATTEMPTS = 0;
      tryMount.backoff = Math.min(60000, (tryMount.backoff || 1000) * 2);
      clearTimeout(tryMount.retry);
      tryMount.retry = setTimeout(tryMount, tryMount.backoff);
      if (!tryMount.onlineHooked) { tryMount.onlineHooked = true; window.addEventListener('online', function () { clearTimeout(tryMount.retry); tryMount(); }); }
      return;
    }
    tryMount.backoff = 0;
    var agencies = resp.agencies || [];
    var isPlatformAdmin = !!resp.is_platform_admin;
    sessionStorage.setItem('kt_is_platform_admin', isPlatformAdmin ? '1' : '0');

    // v22p22: inject the Platform sidebar section for platform_admin.
    // ...but NOT while impersonating a lower role via "View as" (kt_view_as).
    // Guardian/educator use a horizontal top-nav; injecting this sidebar-style
    // section there overflows and breaks the dashboard. Hiding it also makes the
    // "View as" preview faithful — those roles never see platform nav.
    var _viewingAs = ''; try { _viewingAs = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    // ...and watch it from here on (the watcher used to start only at the first navigation, so a
    // sidebar rebuild straight after a refresh could drop Sales for good - 2026-10-27).
    if (isPlatformAdmin && !_viewingAs) { injectPlatformNav(); ensurePlatformNav(); }

    if (! agencies.length || (! isPlatformAdmin && agencies.length < 2)) return;

    // v22p98: platform admins default to a CONCRETE agency, NOT the aggregate
    // 'all' view. List/compose screens (users, families, centres, announcement
    // composer) can't aggregate across agencies, and the backend no longer
    // silently defaults a header-less / 'all' platform_admin to the first tenant
    // (that was the "in Test Agency but seeing iLearn contacts" leak). Landing on
    // 'all' therefore left those screens empty. Land on a real agency instead;
    // "All agencies" stays available as an explicit dropdown choice for the
    // aggregate dashboard. Preserve any explicit prior selection (incl. 'all').
    var _hadAgency = !!sessionStorage.getItem(STORAGE_KEY);
    var activeId = sessionStorage.getItem(STORAGE_KEY) || defaultAgencyId(agencies);
    if (String(activeId) !== 'all'
        && ! agencies.find(function (a) { return String(a.id) === String(activeId); })) {
      activeId = defaultAgencyId(agencies);
    }
    sessionStorage.setItem(STORAGE_KEY, String(activeId));
    if (String(activeId) === 'all') {
      sessionStorage.setItem(ACTIVE_NAME, 'All agencies');
    } else if (! sessionStorage.getItem(ACTIVE_NAME)) {
      var a0 = agencies.find(function (a) { return String(a.id) === String(activeId); });
      if (a0) sessionStorage.setItem(ACTIVE_NAME, a0.name);
    }

    var widget = buildWidget(agencies, activeId, isPlatformAdmin);
    var navHidden = /\brole-(guardian|educator|auditor)\b/.test(document.body.className);
    /* PARENT / EDUCATOR VIEW (2026-11-04): into the header's admin strip beside "View as", opening downward
       (.kt-agency-inbar). The floating bottom-left pill below is only for a screen with no strip. */
    var _inFoot = navHidden && window.KT && KT.sidebarFoot && document.getElementById('appSidebar') && KT.sidebarFoot(10, widget);
    if (_inFoot) {
      widget.classList.add('kt-agency-inbar');
      var _dd = widget.querySelector('div[style*="absolute"]');
      if (_dd) { _dd.style.top = 'calc(100% + 4px)'; _dd.style.bottom = 'auto'; }
    } else if (navHidden) {
      // The top nav is hidden for these roles and the header is at the very top,
      // so the pill's upward dropdown would be clipped off-screen. Mount it
      // bottom-left, just above the "Viewing as" pill, where it opens into view.
      widget.setAttribute('style',
        'position:fixed;left:14px;bottom:66px;z-index:9001;background:#fff;border:1px solid #D1D5DB;' +
        'border-radius:12px;padding:6px 8px;box-shadow:0 8px 24px rgba(0,0,0,.18);min-width:190px;');
      document.body.appendChild(widget);
    } else {
      /* BELOW the name, in the shared sidebar foot (slot 10 — first of the three).
         It used to sit above the user pill, which broke the run of nav links with an
         unrelated control and pushed the name down. The dropdown opens upward, so the
         bottom is where it belongs: `.app-sidebar` is overflow:hidden and a downward
         menu would be clipped. */
      if (!(window.KT && KT.sidebarFoot && KT.sidebarFoot(10, widget))) {
        navUser.insertAdjacentElement('afterend', widget);
      }
    }

    // First login: the initial screen may have rendered BEFORE the active agency
    // was known (a platform_admin sends no X-Active-Agency-Id header until it's
    // set), so it showed "Could not load — no agency access". Now that the agency
    // is resolved, re-render the current screen so it loads properly.
    if (!_hadAgency && String(activeId) && String(activeId) !== 'null') {
      try { if (window.KT && window.KT.Shell && window.KT.Shell.renderScreen) window.KT.Shell.renderScreen(); } catch (e) {}
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryMount);
  } else {
    tryMount();
  }

  if (window.KT) window.KT.AgencySwitcher = { mount: tryMount };
})(window);
