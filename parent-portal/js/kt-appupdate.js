/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — in-app update check (2026-07-09).
   Runs ONLY inside the Android APK (Capacitor bridge present) — a no-op in a
   normal browser. Compares the installed native build to /version.json and, if a
   newer build is published, shows a slide-in "Update available" banner linking to
   the new APK. Web/feature changes need no update (the app loads the live site);
   this covers native-shell releases.

   To publish a release: bump versionCode + versionName in android/app/build.gradle,
   rebuild the APK, upload it to /dl/, then bump "versionCode" (+ apkUrl/notes) in
   /version.json. Every app will then prompt to update.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktAppUpdate) return; window.__ktAppUpdate = true;

  function cap() { return window.Capacitor; }
  function isNative() { var c = cap(); return !!(c && (c.isNativePlatform ? c.isNativePlatform() : c.isNative)); }
  function platform() { var c = cap(); try { return c && c.getPlatform ? c.getPlatform() : 'android'; } catch (e) { return 'android'; } }

  async function appInfo() {
    try {
      var c = cap(); if (!c) return null;
      var App = (c.Plugins && c.Plugins.App) || window.CapacitorApp;
      if (App && App.getInfo) return await App.getInfo();
    } catch (e) {}
    return null;
  }

  function openUrl(url) {
    try { window.open(url, '_system'); } catch (e) { try { window.location.href = url; } catch (e2) {} }
  }

  function banner(meta) {
    if (document.getElementById('kt-appupdate')) return;
    var bar = document.createElement('div');
    bar.id = 'kt-appupdate';
    bar.style.cssText = 'position:fixed;left:12px;right:12px;bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 12px);z-index:13000;'
      + 'background:#0E7C90;color:#fff;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:12px;'
      + 'box-shadow:0 14px 34px -12px rgba(0,0,0,.5);font-family:system-ui,-apple-system,sans-serif;animation:kt-appup-in .35s ease both;';
    var txt = document.createElement('div'); txt.style.cssText = 'flex:1;min-width:0;';
    txt.innerHTML = '<div style="font-weight:800;font-size:14px;">Update available</div>'
      + '<div style="font-size:12px;opacity:.9;margin-top:1px;">' + (meta.notes ? String(meta.notes).replace(/[<>]/g, '') : 'A newer version of the app is ready.') + '</div>';
    var btn = document.createElement('button'); btn.textContent = 'Update';
    btn.style.cssText = 'flex-shrink:0;background:#fff;color:#0C6070;border:none;border-radius:9px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;';
    btn.addEventListener('click', function () { if (meta.onUpdate) meta.onUpdate(btn); else openUrl(meta.apkUrl); });
    var x = document.createElement('button'); x.textContent = '✕';
    x.style.cssText = 'flex-shrink:0;background:transparent;border:none;color:rgba(255,255,255,.85);font-size:16px;cursor:pointer;';
    x.addEventListener('click', function () { bar.remove(); if (meta.onDismiss) { meta.onDismiss(); return; } try { if (!meta.mandatory) sessionStorage.setItem('kt_update_dismissed', String(meta.versionCode)); } catch (e) {} });
    bar.appendChild(txt); bar.appendChild(btn); if (!meta.mandatory) bar.appendChild(x);
    var st = document.createElement('style'); st.textContent = '@keyframes kt-appup-in{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:none;}}';
    document.head.appendChild(st); document.body.appendChild(bar);
  }

  /* GOOGLE PLAY BUILD (2026-10-21). The Play app (KtBio.dist() === 'play') must not offer the
     sideload "download the new APK" banner (Play updates it) and has no background-location
     service. Older apps have no dist() and are sideload builds. */
  var _ktDist = null;
  function appDist() {
    if (_ktDist) { return Promise.resolve(_ktDist); }
    try {
      var bio = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.KtBio;
      if (!bio || typeof bio.dist !== 'function') { return Promise.resolve(_ktDist = 'sideload'); }
      return bio.dist().then(function (r) { return (_ktDist = (r && r.dist) || 'sideload'); },
        function () { return (_ktDist = 'sideload'); });
    } catch (e) { return Promise.resolve(_ktDist = 'sideload'); }
  }

  /* GOOGLE PLAY UPDATES (2026-10-30, Anthony: "if there is a new google play release can the app notify the user or
     force the user to download the new release?" → "Both, staged"). Platform → Maintenance → Mobile app updates sets
     the latest Play build and the oldest build still allowed (GET /app/versions, public):
       installed < minimum → a full-screen "Update required" (sign-out and help stay reachable);
       installed < latest  → "Update available", dismissible for 3 days.
     From 3.12.9 the app has Google's In-App Updates (Capacitor.Plugins.AppUpdate, @capawesome/capacitor-app-update):
     required = Google's immediate update, available = a flexible download in the background that restarts the app when
     it is ready. Older builds, or when Play says no update is offered yet, open the store page.
     iPhone: see IPHONE below (the App Store listing + a minimum / latest version). */
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function brand() { try { return (window.KT && typeof KT.brandName === 'function' && KT.brandName()) || 'KiddieTrac'; } catch (e) { return 'KiddieTrac'; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function auPlugin() { var c = cap(); var p = c && c.Plugins && c.Plugins.AppUpdate; return p && typeof p.getAppUpdateInfo === 'function' ? p : null; }
  function openStore(pkg) {
    var AU = auPlugin();
    if (AU && typeof AU.openAppStore === 'function') { AU.openAppStore().catch(function () { openStoreUrl(pkg); }); return; }
    openStoreUrl(pkg);
  }
  function openStoreUrl(pkg) {
    var id = encodeURIComponent(pkg || 'com.kiddietrac.app');
    try { window.location.href = 'market://details?id=' + id; } catch (e) {}
    setTimeout(function () { if (!document.hidden) openUrl('https://play.google.com/store/apps/details?id=' + id); }, 1200);
  }
  async function playInfo() {
    var AU = auPlugin(); if (!AU) return null;
    try { return await AU.getAppUpdateInfo(); } catch (e) { return null; }
  }
  async function flexible(pkg, btn) {
    var AU = auPlugin(), i = await playInfo();
    if (AU && i && i.updateAvailability === 2 && i.flexibleUpdateAllowed !== false) {
      try {
        if (btn) { btn.disabled = true; btn.textContent = 'Downloading…'; }
        if (typeof AU.addListener === 'function') {
          AU.addListener('onFlexibleUpdateStateChange', function (st) {
            if (st && st.installStatus === 11) { try { AU.completeFlexibleUpdate(); } catch (e) {} }   // 11 = DOWNLOADED: install + restart
          });
        }
        await AU.startFlexibleUpdate();
        return;
      } catch (e) { if (btn) { btn.disabled = false; btn.textContent = 'Update'; } }
    }
    openStore(pkg);
  }

  /* FORCED UPDATE (2026-10-30, Anthony: "if new android app exists in app store - the prompt should detect this and
     prompt right in the middle of the screen with existing app and dimmed background and do not allow to close the
     prompt with forcing the update").
     A card in the middle of the screen over the live app, the app dimmed behind it. It cannot be closed:
       - no X, taps on the dimmed app do nothing (kt-modal-guard leaves it alone: data-kt-no-close),
       - the Android back button does nothing while it is up (KT.goBack is held),
       - it sits above every other layer, the idle lock included, and puts itself back if anything removes it,
       - coming back to the app (after cancelling Google's update screen) shows it again.
     Update now = Google's own immediate update. An update already downloaded is installed; one Play is still busy
     with (or one Play will not run in the app) opens the store page, where Play shows the update. */
  var forced = null;   // { pkg, installed, available, title }
  function holdBack() {
    var KT = window.KT = window.KT || {};
    if (KT.__updHold) return; KT.__updHold = true;
    var wrap = function (orig) {
      return function () { if (document.getElementById('kt-update-required')) return true; return orig ? orig.apply(this, arguments) : false; };
    };
    // kt-back.js reads KT.goBack at the moment back is pressed, so wrapping it covers the hardware button.
    var cur = KT.goBack; KT.goBack = wrap(cur);
    try {
      Object.defineProperty(KT, 'goBack', {
        configurable: true,
        get: function () { return this.__updGoBack; },
        set: function (f) { this.__updGoBack = (f && f.__upd) ? f : (function (g) { var w = wrap(g); w.__upd = true; return w; })(f); },
      });
      KT.goBack = cur;
    } catch (e) {}
    // Our own listener too: with the shell not loaded yet (sign-in page) nothing else would swallow back.
    try {
      var App = cap() && cap().Plugins && cap().Plugins.App;
      if (App && App.addListener) App.addListener('backButton', function () {});
    } catch (e) {}
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.getElementById('kt-update-required')) { e.stopImmediatePropagation(); e.preventDefault(); }
    }, true);
  }

  function forceScreen(f) {
    forced = f;
    holdBack();
    var ov = document.getElementById('kt-update-required');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'kt-update-required';
      ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-labelledby', 'kt-ur-title');
      ov.setAttribute('data-kt-no-close', '1'); ov.setAttribute('data-kt-keep-open', '1');
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147483646;background:rgba(8,17,33,.62);display:flex;align-items:center;justify-content:center;'
        + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 20px) 20px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 20px);'
        + 'font-family:system-ui,-apple-system,sans-serif;touch-action:none;overscroll-behavior:contain;animation:kt-ur-fade .2s ease both;';
      var st = document.createElement('style'); st.id = 'kt-ur-css';
      st.textContent = '@keyframes kt-ur-fade{from{opacity:0}to{opacity:1}}@keyframes kt-ur-pop{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:none}}'
        + 'html.kt-ur-on,html.kt-ur-on body{overflow:hidden!important}'
        // The portal's phone popup rules top-anchor dialogs and theme buttons; this card stays centred and teal.
        + 'html body #kt-update-required{display:flex!important;align-items:center!important;justify-content:center!important;background:rgba(8,17,33,.62)!important;'
        + 'position:fixed!important;inset:0!important;z-index:2147483646!important;opacity:1!important;visibility:visible!important;transform:none!important}'
        + 'html body #kt-update-required #kt-ur-card{margin:auto!important;position:relative!important;top:auto!important;max-height:none!important}'
        + 'html body #kt-update-required #kt-ur-go{background:#0E7C90!important;color:#fff!important;border:0!important;height:44px!important;width:100%!important;border-radius:12px!important;font-size:15px!important;font-weight:800!important}'
        + 'html body #kt-update-required #kt-ur-go:disabled{opacity:.7!important}';
      if (!document.getElementById('kt-ur-css')) document.head.appendChild(st);
      var name = esc(brand());
      ov.innerHTML = '<div id="kt-ur-card" style="width:100%;max-width:360px;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 24px 60px -18px rgba(0,0,0,.55);animation:kt-ur-pop .22s ease both;">'
        + '<div style="background:linear-gradient(135deg,#1B3A5C 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;padding:16px 18px;color:#fff;display:flex;align-items:center;gap:12px;">'
        + '<img src="/icon-192.png" alt="" width="44" height="44" style="flex:none;width:44px;height:44px;border-radius:11px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.25);">'
        + '<div style="min-width:0;"><div id="kt-ur-title" style="font-size:17px;font-weight:800;line-height:1.25;">Update required</div>'
        + '<div id="kt-ur-os" style="font-size:12.5px;opacity:.9;margin-top:2px;">' + name + ' for Android</div></div></div>'
        + '<div style="padding:16px 18px 18px;color:#334155;font-size:14px;line-height:1.5;">'
        + '<p id="kt-ur-msg" style="margin:0 0 12px;"></p>'
        + '<div style="display:flex;gap:8px;margin:0 0 14px;font-size:12.5px;">'
        + '<div style="flex:1;min-width:0;border:1px solid #E2E8F0;border-radius:10px;padding:7px 10px;"><div style="color:#64748B;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">On this phone</div><div id="kt-ur-cur" style="font-weight:800;color:#0F172A;"></div></div>'
        + '<div style="flex:1;min-width:0;border:1px solid #99F6E4;background:#F0FDFA;border-radius:10px;padding:7px 10px;"><div style="color:#0F766E;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px;" id="kt-ur-newlbl">New on Google Play</div><div id="kt-ur-new" style="font-weight:800;color:#0F172A;"></div></div></div>'
        + '<button type="button" id="kt-ur-go" style="width:100%;height:44px;border:0;border-radius:12px;background:#0E7C90;color:#fff;font-size:15px;font-weight:800;cursor:pointer;">Update now</button>'
        + '<div id="kt-ur-note" style="display:none;margin-top:10px;font-size:12.5px;color:#64748B;text-align:center;"></div>'
        + '</div></div>';
      // Taps on the dimmed app go nowhere; taps inside the card work as normal.
      ['click', 'pointerdown', 'mousedown', 'touchstart', 'contextmenu'].forEach(function (t) {
        ov.addEventListener(t, function (e) { if (!e.target.closest('#kt-ur-card')) { e.stopPropagation(); if (t !== 'touchstart' && t !== 'pointerdown') e.preventDefault(); } }, { capture: true, passive: false });
      });
      ov.querySelector('#kt-ur-go').addEventListener('click', function () { goUpdate(this); });
    }
    var msg = (f.message || '').trim(), ios = f.os === 'ios', storeName = ios ? 'the App Store' : 'Google Play';
    ov.querySelector('#kt-ur-os').textContent = brand() + (ios ? ' for iPhone' : ' for Android');
    ov.querySelector('#kt-ur-newlbl').textContent = ios ? 'New on the App Store' : 'New on Google Play';
    ov.querySelector('#kt-ur-msg').textContent = msg || ('A new version of the ' + brand() + ' app is on ' + storeName + '. Update to keep using the app - it only takes a moment.');
    ov.querySelector('#kt-ur-cur').textContent = f.installedLabel;
    ov.querySelector('#kt-ur-new').textContent = f.availableLabel;
    if (!ov.parentNode || ov.parentNode !== document.body || document.body.lastElementChild !== ov) document.body.appendChild(ov);
    document.documentElement.classList.add('kt-ur-on');
    try { if (document.activeElement && document.activeElement.blur) document.activeElement.blur(); } catch (e) {}
    keepUp();
  }

  // Anything that removes it (a screen re-render, a "close all popups" sweep) or covers it puts it straight back on top.
  var mo = null;
  function keepUp() {
    if (mo || typeof MutationObserver !== 'function') return;
    mo = new MutationObserver(function () {
      if (!forced) return;
      var ov = document.getElementById('kt-update-required');
      if (!ov || document.body.lastElementChild !== ov || ov.style.display === 'none') { forceScreen(forced); if (ov) ov.style.display = 'flex'; }
    });
    mo.observe(document.body, { childList: true });
    mo.observe(document.documentElement, { childList: true });
  }

  async function goUpdate(btn) {
    var AU = auPlugin(), pkg = (forced && forced.pkg) || 'com.kiddietrac.app';
    var note = document.getElementById('kt-ur-note');
    function say(t) { if (note) { note.textContent = t || ''; note.style.display = t ? 'block' : 'none'; } }
    if (forced && forced.os === 'ios') {   // iPhone: no in-app update, the App Store page it is
      if (btn) { btn.disabled = true; btn.textContent = 'Opening the App Store…'; }
      say('The App Store is opening - tap Update there.');
      openAppStore(forced);
      if (btn) setTimeout(function () { btn.disabled = false; btn.textContent = 'Update now'; }, 1500);
      return;
    }
    if (btn) { btn.disabled = true; btn.textContent = 'Opening Google Play…'; }
    try {
      var i = await playInfo();   // also loads Play's update info into the plugin, which performImmediateUpdate needs
      if (AU && i) {
        if (i.installStatus === 11 && typeof AU.completeFlexibleUpdate === 'function') {   // 11 = DOWNLOADED: install + restart
          say('Installing the update…');
          await AU.completeFlexibleUpdate(); return;
        }
        if (i.updateAvailability === 2 && i.immediateUpdateAllowed !== false) {
          var r = await AU.performImmediateUpdate();
          // 0 = OK (Play installs and restarts the app). Cancelled or failed: the card stays, ready to try again.
          if (r && r.code === 1) say('The update was cancelled. Tap Update now to continue.');
          else if (r && r.code === 2) say('The update did not finish. Tap Update now to try again.');
          else if (r && r.code !== 0) openStore(pkg);
          return;
        }
      }
      // An update Play is already busy with (3), or one Play will not run in the app: the store page shows it.
      say('Google Play is opening - tap Update there.');
      openStore(pkg);
    } catch (e) {
      openStore(pkg);
    } finally {
      if (btn) setTimeout(function () { btn.disabled = false; btn.textContent = 'Update now'; }, 1500);
    }
  }

  function requiredScreen(cfg, pkg, installed, latest) {
    forceScreen({ pkg: pkg, message: cfg && cfg.message, installedLabel: installed.label, availableLabel: latest ? 'Build ' + latest : 'Newer version' });
  }

  /* SHOWN AT ONCE, ON EVERY PAGE (2026-11-02, Anthony: "the new update pops in and then out and when i log in then it
     pops up again (it should pop up right away when logging in)"). The check waited 4 s after each page load, so the
     card appeared on the sign-in page, went with it when the unlock moved on to the dashboard, and came back 4 s later.
     The forced answer is remembered for the installed build: the next page shows the card the moment it knows the
     build, and Play's fresh answer then confirms it or takes it away. A new install has a new build number, so an old
     answer never outlives the update it was about. */
  var REQ_KEY = 'kt_update_required';
  function rememberForced(build, f) {
    try { localStorage.setItem(REQ_KEY, JSON.stringify({ b: build, f: f, t: Date.now() })); } catch (e) {}
  }
  function forgetForced() { try { localStorage.removeItem(REQ_KEY); } catch (e) {} }
  function showRemembered(info) {
    // Android: the installed build number. iPhone: 'ios:' + the installed version (CFBundleShortVersionString).
    var installed = platform() === 'ios' ? 'ios:' + ((info && info.version) || '') : (parseInt(info && info.build, 10) || 0);
    try {
      var v = JSON.parse(localStorage.getItem(REQ_KEY) || 'null');
      if (!v || !v.f || v.b !== installed || Date.now() - (v.t || 0) > 7 * 86400000) { if (v) forgetForced(); return; }
      forceScreen(v.f);
    } catch (e) {}
  }
  function noUpdate() {
    forced = null;
    forgetForced();
    var old = document.getElementById('kt-update-required'); if (old) old.remove();
    document.documentElement.classList.remove('kt-ur-on');
  }

  async function checkPlay(info) {
    var installed = parseInt(info && info.build, 10) || 0;
    if (!installed) return;
    var cur = { label: (info.version ? info.version + ' ' : '') + '(' + installed + ')' };
    var pkg = (info && info.id) || 'com.kiddietrac.app';   // a white-label brand has its own store page
    /* ASK GOOGLE PLAY FIRST (2026-10-31, Anthony: "how can the app know that the actual update was submitted to google
       play and was approved before prompting"). Play is the only one that knows whether the release passed review and
       has reached THIS phone (staged rollouts included). Builds with Google's In-App Updates (3.12.9+):
         Play has an update for this phone (2 = available, 3 = already in progress) → the forced card;
         Play has none → nothing at all, whatever the numbers in Platform → Maintenance say.
       Builds without it (3.12.8 and older) fall back to those numbers: below the minimum → the forced card; below the
       latest → the "Update available" banner (they cannot tell whether Play has really released it). */
    var AU = auPlugin(), pi = AU ? await playInfo() : null;
    var playKnows = !!(pi && typeof pi.updateAvailability === 'number');
    if (playKnows) {
      if (pi.updateAvailability !== 2 && pi.updateAvailability !== 3) { noUpdate(); return; }
      var avail = parseInt(pi.availableVersionCode, 10) || 0;
      var f = { pkg: pkg, message: (forced && forced.message) || '', installedLabel: cur.label, availableLabel: avail > installed ? 'Build ' + avail : 'Newer version' };
      forceScreen(f);   // straight away; the agency's wording follows when the server answers
      try {
        var rr = await fetch(apiBase() + '/app/versions?t=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (rr.ok) { var pc = ((await rr.json()) || {}).play; if (pc && pc.message && forced) { f.message = pc.message; forceScreen(f); } }
      } catch (e) {}
      rememberForced(installed, f);
      return;
    }
    var cfg;
    try {
      var r = await fetch(apiBase() + '/app/versions?t=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!r.ok) return;
      cfg = ((await r.json()) || {}).play;
    } catch (e) { return; }
    if (!cfg) return;
    pkg = (info && info.id) || cfg.package || 'com.kiddietrac.app';
    var min = parseInt(cfg.min_build, 10) || 0, latest = parseInt(cfg.latest_build, 10) || 0;
    if (min && installed < min) { requiredScreen(cfg, pkg, cur, latest > installed ? latest : 0); rememberForced(installed, forced); return; }
    noUpdate();
    if (!latest || installed >= latest) return;
    var key = 'kt_play_update_dismissed', until = 0;
    try { var v = JSON.parse(localStorage.getItem(key) || 'null'); if (v && v.b === latest) until = v.u || 0; } catch (e) {}
    if (until > Date.now()) return;
    banner({
      versionCode: latest,
      notes: (cfg.message || '').trim() || ('A newer version of the ' + brand() + ' app is ready.'),
      onUpdate: function (btn) { flexible(pkg, btn); },
      onDismiss: function () { try { localStorage.setItem(key, JSON.stringify({ b: latest, u: Date.now() + 3 * 86400000 })); } catch (e) {} },
    });
  }

  /* IPHONE (2026-11-02, Anthony: the iPhone app should prompt "same as Android"). iOS has no in-app update, so the
     "is a newer version really released" answer is the App Store's own listing, which the server asks Apple for
     (GET /app/versions → ios.store_version, cached 15 minutes). Platform → Maintenance → Mobile app updates adds an
     optional minimum and latest version:
       store lists nothing (not on the App Store yet, or Apple unreachable) → nothing at all;
       installed < minimum, and the store has a version at least that new → the forced "Update required" card;
       installed < latest (the lower of the admin's latest and the store version; never one the store doesn't list)
                                                                            → "Update available", dismissible 3 days.
     Versions are compared number by number (3.12.10 > 3.12.9). The forced answer is remembered per installed
     version, like Android's per build, so the next page shows the card at once. */
  function vparts(v) { return String(v || '').split('.').map(function (n) { return parseInt(n, 10) || 0; }); }
  function vcmp(a, b) {
    var x = vparts(a), y = vparts(b), n = Math.max(x.length, y.length);
    for (var i = 0; i < n; i++) { var d = (x[i] || 0) - (y[i] || 0); if (d) return d < 0 ? -1 : 1; }
    return 0;
  }
  function validVer(v) { return /^\d+(\.\d+){0,3}$/.test(String(v || '').trim()); }
  function openAppStore(s) {
    s = s || {};
    var web = s.storeUrl || (s.trackId ? 'https://apps.apple.com/app/id' + s.trackId : '');
    // itms-apps:// opens the App Store app straight on the page; the https link (a universal link) is the fallback.
    if (s.trackId) { try { window.location.href = 'itms-apps://apps.apple.com/app/id' + s.trackId; } catch (e) {} }
    if (web) setTimeout(function () { if (!document.hidden) openUrl(web); }, s.trackId ? 1200 : 0);
  }

  async function checkIos(info) {
    var installed = String((info && info.version) || '').trim();
    if (!validVer(installed)) return;
    var key = 'ios:' + installed;
    var bundle = (info && info.id) || 'com.kiddietrac.app';   // a white-label iPhone app asks about its own listing
    var cfg;
    try {
      var r = await fetch(apiBase() + '/app/versions?bundle=' + encodeURIComponent(bundle) + '&t=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!r.ok) return;   // no answer: whatever is up stays up (a remembered card included)
      cfg = ((await r.json()) || {}).ios;
    } catch (e) { return; }
    var store = cfg && validVer(cfg.store_version) ? String(cfg.store_version) : '';
    if (!store) { noUpdate(); return; }            // not on the App Store (yet): never prompt
    var s = { storeUrl: cfg.store_url || '', trackId: cfg.track_id || 0 };
    var cur = installed + (info.build ? ' (' + info.build + ')' : '');
    var min = validVer(cfg.min_version) ? String(cfg.min_version) : '';
    if (min && vcmp(installed, min) < 0 && vcmp(store, min) >= 0) {
      forceScreen({ os: 'ios', pkg: bundle, storeUrl: s.storeUrl, trackId: s.trackId, message: cfg.message || '',
        installedLabel: cur, availableLabel: 'Version ' + store });
      rememberForced(key, forced);
      return;
    }
    noUpdate();
    var target = validVer(cfg.latest_version) ? String(cfg.latest_version) : store;
    if (vcmp(target, store) > 0) target = store;   // the admin's latest isn't on the App Store yet: prompt for what is
    if (vcmp(installed, target) >= 0) return;      // up to date
    var dkey = 'kt_ios_update_dismissed', until = 0;
    try { var v = JSON.parse(localStorage.getItem(dkey) || 'null'); if (v && v.v === target) until = v.u || 0; } catch (e) {}
    if (until > Date.now()) return;
    banner({
      versionCode: target,
      notes: (cfg.message || '').trim() || ('A newer version of the ' + brand() + ' app is on the App Store.'),
      onUpdate: function () { openAppStore(s); },
      onDismiss: function () { try { localStorage.setItem(dkey, JSON.stringify({ v: target, u: Date.now() + 3 * 86400000 })); } catch (e) {} },
    });
  }

  async function check() {
    if (!isNative()) return;                       // browser → nothing to do
    wireResume();
    if (platform() === 'ios') {                    // iPhone: the App Store listing (see IPHONE above)
      var iinfo = await appInfo();
      if (iinfo) { if (!forced) showRemembered(iinfo); await checkIos(iinfo); }
      return;
    }
    if ((await appDist()) === 'play') {            // Google Play: the Play build numbers, not the sideload APK
      var pinfo = await appInfo();
      if (pinfo) { if (!forced) showRemembered(pinfo); await checkPlay(pinfo); }
      return;
    }
    var info = await appInfo(); if (!info) return;
    var installed = parseInt(info.build, 10) || 0; // Capacitor 'build' == Android versionCode
    var r; try { r = await fetch('/version.json?t=' + Date.now()); } catch (e) { return; }
    if (!r || !r.ok) return;
    var j; try { j = await r.json(); } catch (e) { return; }
    var meta = j && j[platform()]; if (!meta || !meta.apkUrl) return;
    var latest = parseInt(meta.versionCode, 10) || 0;
    if (latest <= installed) return;               // up to date
    var dismissed = 0; try { dismissed = parseInt(sessionStorage.getItem('kt_update_dismissed'), 10) || 0; } catch (e) {}
    if (dismissed === latest && !meta.mandatory) return;
    banner(meta);
  }

  // At once (this file is deferred, so the page is parsed and the native bridge is in): see SHOWN AT ONCE above.
  check();
  /* Back in the app (Google's update screen cancelled, or the app left in the background): ask Play again. The card
     comes back if the update is still waiting, and goes away once it is installed. */
  var lastCheck = 0;
  function recheck() { if (Date.now() - lastCheck < 1500) return; lastCheck = Date.now(); check(); }
  function wireResume() {
    try {
      var App = cap() && cap().Plugins && cap().Plugins.App;
      if (App && App.addListener && !window.__ktUpdResume) { window.__ktUpdResume = true; App.addListener('resume', recheck); }
    } catch (e) {}
  }
  setTimeout(wireResume, 4000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && isNative()) recheck(); });
  window.KT = window.KT || {};
  window.KT.appUpdateCheck = check;   // for tests and the Platform → Maintenance preview
})();
