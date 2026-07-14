/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — mobile bottom navigation (2026-07-08).
   On phones (≤768px, incl. the Android APK's WebView) a fixed bottom bar gives
   one-tap access to the most-used functions — Home, Messages, Alerts — plus a
   Menu button that slides the full sidebar in as an overlay. Larger touch
   targets + no hunting through a hidden menu.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktMobileNav) return; window.__ktMobileNav = true;
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }

  function injectStyle() {
    if (document.getElementById('kt-mobilenav-style')) return;
    var s = document.createElement('style'); s.id = 'kt-mobilenav-style';
    s.textContent = [
      '#kt-mobilenav{display:none;}',
      '@media(max-width:768px){',
        '#kt-mobilenav{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:9500;background:#fff;',
        'border-top:1px solid #E5E7EB;box-shadow:0 -4px 16px -8px rgba(15,23,42,.2);',
        'padding:5px 4px calc(env(safe-area-inset-bottom,0px) + 5px);justify-content:space-around;}',
        '#kt-mobilenav button{position:relative;flex:1;background:transparent;border:none;display:flex;flex-direction:column;',
        'align-items:center;gap:2px;padding:8px 2px 6px;cursor:pointer;color:#64748B;font-weight:600;font-size:10.5px;line-height:1.2;min-height:52px;}',
        '#kt-mobilenav button .ic{font-size:21px;line-height:1;transition:transform .12s ease;}',
        '#kt-mobilenav button:active{color:#1F6080;}',
        '#kt-mobilenav button.on{color:#0E7C90;font-weight:800;}',
        '#kt-mobilenav button.on .ic{transform:translateY(-2px) scale(1.06);}',
        '#kt-mobilenav button.on::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:26px;height:3px;border-radius:0 0 3px 3px;background:#0E7C90;}',
        // Center raised camera/QR check-in button (restored to the original look).
        '#kt-mobilenav button.scan{color:#0E7C90;font-weight:800;}',
        '#kt-mobilenav button.scan .ic{width:50px;height:50px;margin-top:-22px;border-radius:50%;background:#0E7C90;color:#fff;display:flex;align-items:center;justify-content:center;font-size:23px;box-shadow:0 6px 16px -4px rgba(14,124,144,.7);border:3px solid #fff;}',
        '#kt-mobilenav button.scan.on .ic{transform:none;}',
        '#kt-mobilenav button.scan.on::before{display:none;}',
        // Keep the parent profile name clear of the floating settings gear.
        '#navUser{padding-right:56px !important;box-sizing:border-box !important;}',
        // The `font` shorthand needs a real family — `font:800 10px/16px inherit` is
        // INVALID, so the browser threw the whole declaration away and the badge
        // inherited the emoji's 21px size, which clipped the digit inside a 16px
        // pill. Longhand, so each property stands on its own.
        '#kt-mobilenav button .badge{position:absolute;top:-3px;left:50%;margin-left:5px;min-width:17px;height:17px;'
          + 'padding:0 4px;box-sizing:border-box;border-radius:9px;background:#EF4444;color:#fff;'
          + 'font-family:system-ui,-apple-system,sans-serif;font-weight:800;font-size:10.5px;line-height:17px;'
          + 'text-align:center;white-space:nowrap;box-shadow:0 0 0 2px #fff;}',
        // clear the bottom bar, nothing more — and never force a full-screen
        // min-height, which padded short sections with dead white space.
        // 62px was the flat-bar height. The bar is taller now (the raised
        // check-in/QR button pushes it to ~87px), so 62px left the last control
        // on a screen — the Sign out button on the home launcher — sitting
        // UNDERNEATH the bar and untappable. Clear the real height, with room to
        // spare; pinToVisualViewport() refines it from the measured bar.
        '.app-main,#appMain{padding-bottom:calc(env(safe-area-inset-bottom,0px) + 100px) !important;min-height:0 !important;}',
        // Kill browser scroll-anchoring — as tall screens (Home tiles) render in
        // stages it shoves the scroll down, then our reset yanks it up = the flash.
        'html,body,#appMain{overflow-anchor:none !important;}',
        '.app-shell,.app-shell--sidebar{min-height:0 !important;}',
        '#kt-mobile-bottom-nav{display:none !important;}',  /* kill the legacy overlapping bar */
        '#kt-topbar{top:4px;}',
        /* Menu → slide the sidebar in as an overlay */
        'body.kt-mnav-open #appSidebar{position:fixed !important;left:0;top:0;bottom:0;z-index:9600;display:block !important;',
        'width:84% !important;max-width:320px;transform:none !important;overflow-y:auto;-webkit-overflow-scrolling:touch;box-shadow:0 0 44px rgba(0,0,0,.45);}',
        'body.kt-mnav-open .app-shell.app-shell--sidebar{grid-template-columns:0 1fr !important;}',
        /* The whole nav is display:none on phones (bottom bar replaces it) — un-hide it inside the menu overlay. */
        'body.kt-mnav-open #appSidebar #navLinks{display:block !important;}',
        'body.kt-mnav-open #appSidebar .nav-label,body.kt-mnav-open #appSidebar .sidebar-section-label,body.kt-mnav-open #appSidebar .sidebar-section{display:revert !important;opacity:1 !important;}',
        'body.kt-mnav-open #appSidebar .nav-icon{display:inline-flex !important;}',
        '#kt-mnav-scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9550;}',
        'body.kt-mnav-open #kt-mnav-scrim{display:block;}',
      '}',
      '@media(min-width:769px){#kt-gear{display:none !important;}}',
      'body.kt-mnav-open #kt-gear{display:none;}',
      // Hide the gear while a full-screen chat thread is open (it would float over the header).
      'body:has(.kt-thread-compose) #kt-gear{display:none !important;}'
    ].join('');
    document.head.appendChild(s);
  }

  function closeMenu() { document.body.classList.remove('kt-mnav-open'); }
  function go(hash) { location.hash = hash; closeMenu(); }

  // Keep the top-right user block (avatar + name) clear of the floating settings
  // gear. Stylesheet rules get out-specified by the sidebar's own #navUser rule,
  // so we force it inline (inline !important always wins) and re-apply as the SPA
  // re-renders the header.
  function padGearClearance() {
    if (window.innerWidth > 768) return;
    if (!document.getElementById('kt-gear')) return;
    var nu = document.getElementById('navUser');
    if (!nu) return;
    // Each floating button is 40px wide with an 8px gap; the QR button (educators)
    // sits left of the gear, so the name/role block has to clear both.
    var pad = (document.getElementById('kt-eduqr') ? 106 : 58) + 'px';
    if (nu.style.paddingRight !== pad) nu.style.setProperty('padding-right', pad, 'important');
  }

  // A parent (guardian) — either by their own role, or a super-admin previewing
  // the parent view — gets a parent-appropriate bottom bar.
  function isParentView() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va) return va === 'guardian';
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var r = u.roles || [];
      return r.indexOf('guardian') > -1 && !['agency_admin', 'platform_admin', 'centre_director', 'educator'].some(function (x) { return r.indexOf(x) > -1; });
    } catch (e) { return false; }
  }

  // An educator (by role, or previewed via view-as). Educators are phone-first
  // too, so they get the same floating settings gear + a check-in QR button.
  function isEducatorView() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va) return va === 'educator';
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var r = u.roles || [];
      return r.indexOf('educator') > -1 && !['agency_admin', 'platform_admin', 'centre_director'].some(function (x) { return r.indexOf(x) > -1; });
    } catch (e) { return false; }
  }

  // Fullscreen check-in QR for the educator's centre — parents scan it off the
  // educator's phone (or print it). The code is fetched fresh and rotates daily
  // server-side (CheckinScanController::centreCode → KTCHK.<centre>.<Ymd>.<sig>).
  function showCheckinQr() {
    var u = {}; try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) {}
    var centreId = u.centre_id;
    var tok = null; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var ov = document.createElement('div');
    ov.id = 'kt-qr-overlay';
    ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(8,28,65,.95);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center;color:#fff;';
    ov.innerHTML =
      '<div style="font-size:21px;font-weight:800;margin-bottom:4px;">📲 Check-in QR</div>'
      + '<div id="kt-qr-sub" style="font-size:13.5px;opacity:.85;margin-bottom:18px;">Loading…</div>'
      + '<div id="kt-qr-holder" style="background:#fff;padding:16px;border-radius:18px;width:250px;height:250px;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:13px;">Generating…</div>'
      + '<div style="font-size:12px;opacity:.75;margin-top:14px;max-width:300px;line-height:1.5;">Parents scan this with the KiddieTrac app to sign in or out. It refreshes every day for security.</div>'
      + '<div style="display:flex;gap:12px;margin-top:22px;">'
      +   '<button id="kt-qr-print" style="background:#fff;color:#0B2545;border:none;border-radius:12px;padding:12px 24px;font-size:15px;font-weight:800;cursor:pointer;">🖨 Print</button>'
      +   '<button id="kt-qr-close" style="background:transparent;color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.4);border-radius:12px;padding:12px 24px;font-size:15px;font-weight:700;cursor:pointer;">Close</button>'
      + '</div>';
    document.body.appendChild(ov);
    var close = function () { if (ov && ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('#kt-qr-close').addEventListener('click', close);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(ov, close); // Android back closes it
    if (!centreId) { ov.querySelector('#kt-qr-sub').textContent = 'No centre is assigned to your account.'; ov.querySelector('#kt-qr-holder').textContent = '—'; return; }
    fetch(base + '/checkin/centre-code/' + centreId, { headers: { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('load failed')); })
      .then(function (d) {
        var sub = ov.querySelector('#kt-qr-sub'); if (sub) sub.textContent = (d.centre_name || '') + ' · valid ' + (d.valid_for || 'today');
        var holder = ov.querySelector('#kt-qr-holder');
        if (window.KT && KT.qrImg && d.code) {
          KT.qrImg(d.code, { size: 224, cell: 6, margin: 2 })
            .then(function (img) { if (holder) { holder.innerHTML = ''; holder.appendChild(img); } })
            .catch(function () { if (holder) holder.textContent = 'QR unavailable'; });
          var pb = ov.querySelector('#kt-qr-print');
          if (pb) pb.addEventListener('click', function () {
            if (window.KT && KT.printQRPoster) KT.printQRPoster({
              url: d.code, title: (d.centre_name || 'Check-in'),
              subtitle: 'Scan to sign your child in or out',
              steps: '<strong>1.</strong> Open the KiddieTrac app.<br><strong>2.</strong> Tap the camera / scan button.<br><strong>3.</strong> Point it at this code.',
              footer: 'Valid ' + (d.valid_for || 'today') + ' · Powered by KiddieTrac',
            });
          });
        } else if (holder) { holder.textContent = 'QR unavailable'; }
      })
      .catch(function () { var s = ov.querySelector('#kt-qr-sub'); if (s) s.textContent = 'Could not load the check-in code.'; var h = ov.querySelector('#kt-qr-holder'); if (h) h.textContent = '—'; });
  }

  function ensure() {
    if (!tok() || document.getElementById('kt-mobilenav')) return;
    injectStyle();
    var scrim = document.createElement('div'); scrim.id = 'kt-mnav-scrim'; scrim.addEventListener('click', closeMenu); document.body.appendChild(scrim);
    var nav = document.createElement('div'); nav.id = 'kt-mobilenav';
    var btn = function (icon, label, fn, badgeId, hash) {
      var b = document.createElement('button'); b.type = 'button'; if (hash) b.setAttribute('data-hash', hash);
      b.innerHTML = '<span class="ic" style="position:relative;">' + icon + (badgeId ? '<span class="badge" id="' + badgeId + '" hidden></span>' : '') + '</span>' + label;
      b.addEventListener('click', fn); return b;
    };
    var parent = isParentView();
    document.body.classList.toggle('kt-parentview', parent);
    if (parent) {
      // Parents get a full, self-contained bottom bar — every section one tap away.
      // Home returns to the icon-tile launcher (#home), the parent's main screen.
      nav.appendChild(btn('🏠', 'Home', function () { go('#home'); }, null, 'home'));
      nav.appendChild(btn('🖼️', 'Photos', function () { go('#photos'); }, 'kt-b-photos', 'photos'));
      // Center, raised camera button — QR check-in / out. The "camera button".
      var scanBtn = btn('📷', 'Check in', function () { go('#scan'); }, null, 'scan');
      scanBtn.classList.add('scan');
      nav.appendChild(scanBtn);
      nav.appendChild(btn('💬', 'Messages', function () { go('#messages'); }, 'kt-mnav-msg', 'messages'));
      nav.appendChild(btn('💳', 'Billing', function () { go('#billing'); }, 'kt-b-billing', 'billing'));
    } else {
      // No Menu button for anyone now. The dashboard carries the tile launcher
      // (every section, one tap), so the sidebar drawer was a second, redundant
      // way to reach the same places — and it was the only thing on the bar that
      // didn't navigate.
      nav.appendChild(btn('🏠', 'Home', function () { go('#dashboard'); }, null, 'dashboard'));
      // Daily log is the thing an educator reaches for most times in a day —
      // it belongs on the bar, not two taps deep in the launcher.
      nav.appendChild(btn('📝', 'Daily log', function () { go('#care-log'); }, null, 'care-log'));
      if (isEducatorView()) {
        // The educator's check-in QR, as a raised centre button — the same
        // affordance parents get for scanning. A 🔳 glyph floating in the corner
        // was too easy to miss for something parents queue up to scan.
        var qrBtn = btn('📷', 'Check-in QR', function () { showCheckinQr(); }, null, 'eduqr');
        qrBtn.classList.add('scan');
        nav.appendChild(qrBtn);
      }
      nav.appendChild(btn('💬', 'Messages', function () { go('#chat'); }, 'kt-mnav-msg', 'chat'));
      // Alerts folded INTO the Inbox: an announcement already lands in the inbox
      // as a notification, so a separate Alerts tab showed the same thing twice.
      // (Composing an alert lives on the Home launcher, under Alerts.)
      nav.appendChild(btn('🔔', 'Inbox', function () { go('#notifications'); }, 'kt-b-inbox', 'notifications'));
    }
    document.body.appendChild(nav);
    pinToVisualViewport();

    // Settings gear — top-right of the parent/educator mobile app (change
    // password, biometrics, PIN, photo, contact). Hidden on desktop (the top bar
    // covers it). Educators are phone-first too and had no gear before.
    var showGear = parent || isEducatorView();
    if (showGear && !document.getElementById('kt-gear')) {
      var gear = document.createElement('button');
      gear.id = 'kt-gear'; gear.type = 'button'; gear.setAttribute('aria-label', 'Settings'); gear.textContent = '⚙️';
      gear.style.cssText = 'position:fixed;top:calc(env(safe-area-inset-top,0px) + 8px);right:12px;z-index:9450;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.94);box-shadow:0 2px 10px rgba(15,23,42,.2);font-size:20px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;';
      gear.addEventListener('click', function () { go('#settings'); });
      document.body.appendChild(gear);
    }

    // The check-in QR now lives on the bottom bar as a raised centre button
    // (see above), so the old floating 🔳 is gone — one obvious entry point,
    // not two easy-to-miss ones. Any stale button from a cached build is removed.
    var staleQr = document.getElementById('kt-eduqr');
    if (staleQr) staleQr.remove();
    // The Menu button is gone, so nothing can open the drawer any more; make sure
    // a cached page can't leave the body stuck in the open state.
    document.body.classList.remove('kt-mnav-open');
    padGearClearance();

    // Highlight the active section.
    var updateActive = function () {
      var h = (location.hash || '#dashboard').replace('#', '').split('?')[0];
      // For parents the launcher (#home) is the base; Today/dashboard are drilled in from it.
      if (nav.querySelector('button[data-hash="home"]') && (h === 'today' || h === 'dashboard')) h = 'home';
      [].forEach.call(nav.querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-hash') === h); });
    };
    updateActive();
    window.addEventListener('hashchange', updateActive);
    // close the sidebar overlay whenever a nav link is tapped inside it
    var sb = document.getElementById('appSidebar');
    if (sb && !sb.__ktMnavBound) { sb.__ktMnavBound = true; sb.addEventListener('click', function (e) { if (e.target.closest('a,.nav-link')) closeMenu(); }); }
  }

  // ── Pin the bar to the VISIBLE viewport bottom ──────────────────────
  // On some mobile WebViews the layout viewport (window.innerHeight) is TALLER
  // than the visible area (visualViewport) — e.g. 1513 vs 915 — so a plain
  // `bottom:0` fixed bar lands hundreds of px below the fold, invisible. We offset
  // it up by the difference so it's always glued to the visible bottom (and it
  // rides up with the on-screen keyboard instead of hiding behind it).
  function pinToVisualViewport() {
    var nav = document.getElementById('kt-mobilenav'); if (!nav) return;
    var vv = window.visualViewport;
    if (vv) {
      var offset = window.innerHeight - (vv.offsetTop + vv.height);
      nav.style.bottom = (offset > 1 ? Math.round(offset) : 0) + 'px';
    } else {
      nav.style.bottom = '0px';
    }
    // Reserve exactly as much room as the bar actually occupies. Hard-coding the
    // clearance is how the Sign out button ended up hidden under it — the bar's
    // height changes with the raised QR button and the safe-area inset.
    var main = document.getElementById('appMain');
    if (main && window.innerWidth <= 600) {
      var need = Math.round(nav.getBoundingClientRect().height) + 16;
      if (need > 20 && main.style.paddingBottom !== need + 'px') {
        main.style.setProperty('padding-bottom', need + 'px', 'important');
      }
    }
  }
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', pinToVisualViewport);
    window.visualViewport.addEventListener('scroll', pinToVisualViewport);
  }
  window.addEventListener('resize', pinToVisualViewport);
  window.addEventListener('orientationchange', pinToVisualViewport);
  setInterval(function () { pinToVisualViewport(); padGearClearance(); }, 700);   // catch SPA navigations / URL-bar changes

  // ── Screen-transition animation (phones only) ───────────────────────
  // A real navigation (hashchange) arms a one-shot; the first #appMain childList
  // mutation after it tags the new top-level nodes with .kt-screen-in so the CSS
  // plays a slide-up+fade. In-place data refreshes (no hashchange) are NOT armed,
  // so live-updating screens (chat, dashboards) don't flicker.
  // A nav opens a short window; every top-level node that lands during it gets
  // tagged once (screens often render in a few stages, so we can't just fire on
  // the first mutation). Tagging children — not #appMain itself — keeps a
  // transform off the container, which would otherwise re-anchor the fixed chat
  // overlay. Each node animates at most once, so nothing restarts mid-flight.
  var animUntil = 0;
  // Stop the WebView from restoring the previous scroll position on navigation
  // (that restore, fighting our reset, is the "scrolls down then jumps up" flicker).
  try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}
  function scrollTop0() {
    try {
      window.scrollTo(0, 0);
      if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
      if (document.documentElement) document.documentElement.scrollTop = 0;
      if (document.body) document.body.scrollTop = 0;
      var mm = document.getElementById('appMain'); if (mm) mm.scrollTop = 0;
    } catch (e) {}
  }
  window.addEventListener('hashchange', function () {
    // The screen renders ASYNC (data fetch), so a single reset on hashchange gets
    // undone by the post-render layout shift. Reset now AND across the render window.
    scrollTop0();
    requestAnimationFrame(scrollTop0);
    [40, 120, 260, 450].forEach(function (d) { setTimeout(scrollTop0, d); });
    if (window.innerWidth > 600) return;
    animUntil = Date.now() + 450;
  });
  function playScreenAnim(m) {
    if (Date.now() > animUntil) return;
    [].forEach.call(m.children, function (c) {
      if (c.__ktAnimated) return;
      c.__ktAnimated = true;
      c.classList.add('kt-screen-in');
    });
  }
  (function watchAppMain() {
    var m = document.getElementById('appMain');
    if (!m) { setTimeout(watchAppMain, 300); return; }
    if (m.__ktAnimObs) return; m.__ktAnimObs = true;
    new MutationObserver(function () { playScreenAnim(m); })
      .observe(m, { childList: true });
  })();

  // ── Unread counters on the bottom bar ──────────────────────────────
  // Messages badge = unread chats; Photos/Billing/Home badges = unread
  // notifications of that kind (photos / invoices+payments / everything else).
  function _apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function _get(path) {
    var t = tok(); if (!t) return Promise.resolve(null);
    var h = { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' };
    try { var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid; var va = sessionStorage.getItem('kt_view_as'); if (va) h['X-View-As-Role'] = va; } catch (e) {}
    return fetch(_apiBase() + path, { headers: h }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }
  function _setBadge(id, n) {
    var el = document.getElementById(id); if (!el) return;
    if (n > 0) { el.textContent = n > 99 ? '99+' : String(n); el.hidden = false; }
    else { el.hidden = true; el.textContent = ''; }
  }
  // Sections the user is currently viewing shouldn't keep nagging with a badge —
  // suppress the matching badge while its section is open (server-side read state
  // is marked by the screen; this is the instant local echo so it clears at once).
  function curSection() { return (location.hash || '').replace('#', '').split('?')[0]; }
  function refreshBadges() {
    if (!tok()) return;
    // Parents use the /parent/messages store (not /chats); staff use /chats.
    var msgPath = isParentView() ? '/parent/messages/unread-count' : '/chats/unread-count';
    _get(msgPath).then(function (d) { if (d) _setBadge('kt-mnav-msg', curSection() === 'messages' || curSection() === 'chat' ? 0 : (d.unread || 0)); });
    _get('/notifications').then(function (d) {
      if (!d) return;
      var rows = d.data || d.notifications || (Array.isArray(d) ? d : []);
      if (!Array.isArray(rows)) rows = [];
      var cat = { photos: 0, billing: 0, other: 0 };
      rows.forEach(function (n) {
        if (n.read_at) return;
        var s = ((n.type || '') + ' ' + (n.title || '') + ' ' + (n.body || '') + ' ' + (typeof n.data === 'string' ? n.data : JSON.stringify(n.data || ''))).toLowerCase();
        if (/photo|image|gallery|picture/.test(s)) cat.photos++;
        else if (/invoice|billing|payment|receipt/.test(s)) cat.billing++;
        else cat.other++;
      });
      var sec = curSection();
      _setBadge('kt-b-photos', sec === 'photos' ? 0 : cat.photos);
      _setBadge('kt-b-billing', sec === 'billing' ? 0 : cat.billing);
      // Staff bar: everything unread that isn't a message lands in Inbox, so the
      // count matches what the Notifications screen will actually show them.
      var totalUnread = cat.photos + cat.billing + cat.other;
      _setBadge('kt-b-inbox', sec === 'notifications' ? 0 : totalUnread);
    });
  }
  setInterval(refreshBadges, 30000);
  // On entering a section, instantly clear its badge, then re-sync shortly after
  // (the screen marks its notifications read on open).
  window.addEventListener('hashchange', function () {
    var sec = curSection();
    if (sec === 'billing') _setBadge('kt-b-billing', 0);
    if (sec === 'photos') _setBadge('kt-b-photos', 0);
    if (sec === 'notifications') _setBadge('kt-b-inbox', 0);
    if (sec === 'messages' || sec === 'chat') _setBadge('kt-mnav-msg', 0);
    setTimeout(refreshBadges, 1500);
  });

  setInterval(ensure, 1500);
  function boot() { ensure(); setTimeout(refreshBadges, 1200); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // ── One-shot DEVICE scroll diagnostic (native only) ─────────────────
  // On the first Home nav after launch, sample the real scroll state every 50ms
  // and POST it so we can SEE the phone's behaviour (which desktop can't reproduce).
  (function () {
    try {
      var C = window.Capacitor; var native = C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative);
      if (!native) return;
    } catch (e) { return; }
    var done = false;
    window.addEventListener('hashchange', function () {
      var h = (location.hash || '').replace('#', '').split('?')[0];
      if (h !== 'home' || done) return;
      done = true;
      var samples = [], t0 = Date.now();
      var iv = setInterval(function () {
        var m = document.getElementById('appMain');
        var de = document.scrollingElement || document.documentElement;
        samples.push((Date.now() - t0) + ':aM=' + (m ? Math.round(m.scrollTop) : -1) + ',doc=' + Math.round(de.scrollTop) + ',vvTop=' + (window.visualViewport ? Math.round(window.visualViewport.offsetTop) : -1));
        if (Date.now() - t0 > 1300) {
          clearInterval(iv);
          try {
            var m2 = document.getElementById('appMain');
            var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
            var meta = 'aMh=' + (m2 ? m2.scrollHeight : '?') + ' aMclient=' + (m2 ? m2.clientHeight : '?') + ' innerH=' + innerHeight + ' vvH=' + (window.visualViewport ? Math.round(window.visualViewport.height) : '?') + ' shellDisp=' + (function () { var s = document.getElementById('appShell'); return s ? getComputedStyle(s).display : '?'; })();
            fetch(base + '/diag/scroll', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: meta + ' || ' + samples.join('  ') }) }).catch(function () {});
          } catch (e) {}
        }
      }, 50);
    });
  })();
})();
