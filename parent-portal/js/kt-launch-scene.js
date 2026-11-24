/* ═══════════════════════════════════════════════════════════════════════════
   LAUNCH SCENE + SIGN-IN FADE (2026-10-21) — styles and the full story in
   /css/kt-launch-scene.css.

   Loaded SYNCHRONOUSLY as the first thing in index.html's <body>, so the scene is the
   first frame the sign-in page paints (the app's native splash is #0A1C3A; so is ours).

   ONE LOGO PER TRANSITION (Anthony, 2026-10-21: "there should not be a double loading of
   screens or logos"):
     launch      the scene's logo plays the training-video intro, holds, then GLIDES into
                 the sign-in page's own logo (same artwork, same spot) and hands over in a
                 single frame. Until then the sign-in logo is hidden (html.ktls-playing).
                 Where it cannot glide (the fingerprint lock is up, white-label, reduced
                 motion) the scene leaves first and the sign-in logo appears after it.
     sign-in     the sign-in page fades to the dashboard splash's bare first frame - its
                 background only - and the dashboard plays the intro once, holds, and
                 fades the portal in when the first screen has settled.
     unlock      fingerprint / PIN success goes straight to that bare frame
                 (KT_portalFade.prepare), with no "Signing you in" logo of its own.
     hand-off    a ?#kt_social / #kt_sso return covers the sign-in form at once.

     window.__ktLaunchScene   Promise, resolves when the scene has fully gone. The
                              fingerprint prompt waits on it.
     window.KT_portalFade(url, replace), KT_portalFade.prepare()
   ═══════════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var S, L;
  try { S = w.sessionStorage; L = w.localStorage; } catch (e) {}
  function sget(k) { try { return S.getItem(k); } catch (e) { return null; } }
  function sset(k, v) { try { S.setItem(k, v); } catch (e) {} }
  function sdel(k) { try { S.removeItem(k); } catch (e) {} }
  function lget(k) { try { return L.getItem(k); } catch (e) { return null; } }
  var reduced = false;
  try { reduced = w.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
  var root = d.documentElement;
  var onDash = /dashboard\.html/i.test(w.location.pathname || '');
  /* FIRST BOOT (2026-10-21): a new tab session, or back after 30+ minutes away - Chrome
     restores a tab with its sessionStorage, so "once per session" alone meant never again.
     kt_away_at is written whenever the page is hidden and cleared when it is seen again;
     kt_boot_scene is set by the dashboard's head when it decided this load is one. */
  try {
    d.addEventListener('visibilitychange', function () { if (d.visibilityState === 'hidden') sset('kt_away_at', String(Date.now())); else sdel('kt_away_at'); });
    w.addEventListener('pagehide', function () { sset('kt_away_at', String(Date.now())); });
  } catch (e) {}
  function firstBoot() {
    if (sget('kt_boot_scene') === '1') return true;
    var away = parseInt(sget('kt_away_at') || '0', 10) || 0;
    return sget('kt_launch_seen') !== '1' || (away > 0 && Date.now() - away > 18e5);
  }

  /* The brand, by the same rule the sign-in page's branding-loader uses before sign-in:
     the app build's tenant (?tenant= / kt_tenant), or the agency of the person this
     device unlocks with a fingerprint. Never someone else's. */
  function theme(agencyId) {
    try {
      // An account's own brand (kt_brand_a<id>, branding-loader) - fix 3, 2026-10-21.
      if (agencyId) {
        var own = JSON.parse(lget('kt_brand_a' + agencyId) || 'null');
        return own && own.theme && own.theme.white_label ? own.theme : null;
      }
      var c = JSON.parse(lget('kt_branding') || 'null');
      var t = c && c.theme;
      var slug = null;
      try { slug = new URLSearchParams(w.location.search).get('tenant') || sget('kt_tenant'); } catch (e) {}
      if (t && t.white_label && slug && (slug === c.agency_slug || slug === c.reseller_slug)) return t;
      // The account this device unlocks: its own brand, the same one its lock screen shows
      // (kt-biometric lockBrand) - not gated on the shared cache, which sign-out clears.
      if (lget('kt_biometric_enabled') === '1' && lget('kt_bio_token')) {
        var u = JSON.parse(lget('kt_user') || 'null');
        var ua = lget('kt_bio_agency') || (u && (u.landing_agency_id || u.agency_id));
        if (ua) { var o = JSON.parse(lget('kt_brand_a' + ua) || 'null'); return o && o.theme && o.theme.white_label ? o.theme : null; }
      }
    } catch (e) {}
    return null;
  }
  function abs(x) { return !x ? '' : (/^(https?:|data:)/i.test(x) ? x : 'https://api.kiddietrac.com' + (x.charAt(0) === '/' ? '' : '/') + x); }
  function safeColour(c) { return c && /^#[0-9a-f]{3,8}$|^rgba?\([0-9.,\s%]+\)$/i.test(c) ? c : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function ktLogo(cls) {
    return '<span class="ktls-logo ' + (cls || '') + '" aria-hidden="true"><i class="w"></i><i class="r"></i><i class="s"></i>'
      + '<span class="ktls-spk" aria-hidden="true"><b></b><b></b><b></b></span></span>';
  }
  function wlLogo(t) {
    var logo = abs(t.splash_logo_url || t.logo_wordmark_url);
    var name = esc(t.name || '');
    var colour = safeColour(t.primary_color) || '#1F6080';
    var inner = logo && !/app\.kiddietrac\.com\/(logo-wordmark|login-wordmark)/.test(logo)
      ? '<img src="' + esc(logo) + '" alt="' + name + '" data-colour="' + colour + '">'
      : '<span class="ktls-name" style="color:' + colour + '">' + name + '</span>';
    return '<div class="ktls-wl"><div class="ktls-plate">' + inner
      + '<span class="ktls-wl-spk" aria-hidden="true"><b></b><b></b><b></b></span></div>'
      + '<span class="ktls-wl-sw" aria-hidden="true"></span></div>';
  }

  /* THE SLOGAN (2026-10-21). Anthony: "the login in mobile says smart childcare and not our
     slogan 'Smart Childcare Management Platform' #1 in North America". KiddieTrac only - a
     white-label brand shows its own tagline (branding.tagline) or nothing. */
  /* Words drawn here run before kt-i18n.js has loaded, so they carry their own
     translations (same wording as the kt-i18n dictionary). 2026-10-22 */
  var KT_LS_T = {
    fr: { 'Smart Childcare Management Platform': 'Plateforme intelligente de gestion des services de garde', '#1 in North America': 'N° 1 en Amérique du Nord', 'Signing out…': 'Déconnexion…' },
    es: { 'Smart Childcare Management Platform': 'Plataforma inteligente de gestión de cuidado infantil', '#1 in North America': 'N.º 1 en Norteamérica', 'Signing out…': 'Cerrando sesión…' },
    hi: { 'Smart Childcare Management Platform': 'स्मार्ट चाइल्डकेयर प्रबंधन प्लेटफ़ॉर्म', '#1 in North America': 'उत्तरी अमेरिका में नंबर 1', 'Signing out…': 'साइन आउट हो रहा है…' }
  };
  function ktLsT(s) {
    try { var t = KT_LS_T[localStorage.getItem('kt_locale')]; return (t && t[s]) || s; } catch (e) { return s; }
  }

  var LOCKUP = '<span class="kt-slogan">' + ktLsT('Smart Childcare Management Platform') + '</span>'
    + '<span class="kt-no1"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#8EC73C" d="M12 2.6l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.0l-5.7 3.1 1.2-6.4L2.8 9.3l6.4-.8z"/></svg>' + ktLsT('#1 in North America') + '</span>';
  function wlTagline(t) {
    try {
      var c = JSON.parse(lget('kt_branding') || 'null');
      if (!c || !c.theme || !c.theme.white_label || !c.branding || !c.branding.tagline) return '';
      if (t && c.theme.name !== t.name) return '';
      return String(c.branding.tagline).slice(0, 90);
    } catch (e) { return ''; }
  }
  function lightBg(c) {
    var m = /^#([0-9a-f]{6})$/i.exec(c || '');
    if (!m) return false;
    var n = parseInt(m[1], 16);
    return (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) > 160;
  }
  function wlTag(t) {
    var s = wlTagline(t);
    if (!s) return '';
    var ink = lightBg(safeColour(t.splash_bg)) ? (safeColour(t.primary_color) || '#1F2937') : '#fff';
    return '<div class="ktl-tag kt-lockup kt-lockup-wl"><span class="kt-slogan" style="color:' + ink + '">' + esc(s) + '</span></div>';
  }
  // The phone sign-in under a white-label brand: its own tagline in place of the lockup.
  w.addEventListener('kt:brand', function (e) {
    try {
      var old = d.querySelector('.kt-wl-tagline');
      if (old && old.parentNode) old.parentNode.removeChild(old);
      var tag = d.querySelector('.ktl-mhero-tag.kt-lockup');
      var s = e && e.detail ? wlTagline(null) : '';
      if (!tag || !s) return;
      var p = d.createElement('p');
      p.className = 'kt-wl-tagline';
      p.textContent = s;
      tag.parentNode.insertBefore(p, tag.nextSibling);
    } catch (x) {}
  });

  // A logo that will not load becomes the brand's name, never a broken-image icon.
  function fixImgs(el, t) {
    var imgs = el.querySelectorAll('.ktls-plate img');
    for (var i = 0; i < imgs.length; i++) {
      imgs[i].onerror = function () {
        var s = d.createElement('span');
        s.className = 'ktls-name';
        s.style.color = this.getAttribute('data-colour') || '#1F6080';
        s.textContent = (t && t.name) || '';
        if (this.parentNode) this.parentNode.replaceChild(s, this);
      };
    }
  }

  /* The dashboard splash's FIRST frame, before its logo arrives: KiddieTrac's gradient, or
     the brand's splash colour - exactly what #kt-splash paints (kt-boot-intro.css /
     the branding block in dashboard.src.html). */
  function bareFrame(t) {
    return t ? (safeColour(t.splash_bg) || '#0A1C3A')
      : 'radial-gradient(120% 90% at 20% 15%,#1F6080 0%,#0F2E52 45%,#0A1C3A 100%)';
  }

  /* ── 0. The iPhone app's native cover (3.12.13) ───────────────────────────
     The app draws this very scene BEFORE the network (KtCoverPage, generated from this file's CSS and art) and
     writes its start time to kt_native_cover. Here the scene is built the same way and every animation is seeked
     to that clock; once the photo, wordmark and lockup faces are painted the page says "ready" and the app removes
     its cover in one frame - the same picture, one logo, no fade. Any other outcome says "gone" and the cover
     fades. Older apps never write the marker: nothing changes for them. */
  var NC = (function () {
    var at = 0, h = null;
    try { at = parseInt(lget('kt_native_cover') || '0', 10) || 0; if (at) L.removeItem('kt_native_cover'); } catch (e) {}
    try { h = w.webkit && w.webkit.messageHandlers && w.webkit.messageHandlers.ktScene; } catch (e) {}
    var age = Date.now() - at;
    /* Once the app has finished its cover (ready, gone, or its own failsafe) it removes the ktScene handler, and
       a message posted after that comes back as a REJECTED promise (iOS 14+ postMessage returns one) - App Review
       ticket #224, "UNHANDLED PROMISE postMessage@[native code]". So nothing is posted once the cover is gone, and
       a late rejection is caught: the cover is already off the screen either way. */
    return { at: (h && at && age >= -1000 && age < 20000) ? at : 0, post: function (m) {
      try { if (h && !coverGone) { var r = h.postMessage(m); if (r && r.then) r.then(null, function () {}); } } catch (e) {}
    } };
  })();
  var carrying = false, coverGone = false;
  w.__ktCoverGone = function () { coverGone = true; };

  /* ── 1. The launch scene ───────────────────────────────────────────────── */
  var done;
  w.__ktLaunchScene = new Promise(function (r) { done = r; });

  function shouldPlay() {
    var forced = false;
    try { forced = /[?&]kt_launch=1\b/.test(w.location.search); } catch (e) {}
    if (forced) return true;
    if (!firstBoot()) return false;                                   // once per app session (see firstBoot)
    if (sget('kt_token')) return false;                                // about to go to the portal
    if (lget('kt_signed_out') === '1') return false;                   // just signed out: straight to the form
    if (/kt_sso=|kt_social=/.test(w.location.hash || '')) return false;   // a token hand-off, not a launch
    var standalone = false;
    try { standalone = w.matchMedia('(display-mode: standalone)').matches || w.navigator.standalone === true; } catch (e) {}
    if (w.__KT_NATIVE || standalone) return true;                      // the app / the installed PWA
    /* A PHONE OR TABLET IN A BROWSER behaves like the app (Anthony, 2026-10-21: "on mobile
       or tablet the app.kiddietrac.com should behave like the mobile app"). Not on a link
       that came here to do something (a reset / invite / sign-out reason), and once per
       browser session (kt_launch_seen above) so a reload or Back never replays it. */
    if (/[?&](signed_out|reason|token|reset|invite|email|code|verify)=/i.test(w.location.search || '')) return false;
    return touchDevice();
  }
  /* Touch-first and device-sized - by what the screen can do, not the user-agent string.
     iPadOS Safari reports itself as a Mac but has no hover and a coarse pointer. A laptop
     with a touch screen keeps a fine pointer and hover, so desktops are unchanged. */
  function touchDevice() {
    try {
      var coarse = w.matchMedia('(pointer: coarse)').matches && w.matchMedia('(hover: none)').matches;
      if (!coarse) return false;
      var sc = w.screen || {};
      var edge = (sc.width && sc.height) ? Math.min(sc.width, sc.height) : (w.innerWidth || 0);
      return edge > 0 && edge <= 1100;                                  // phones and tablets
    } catch (e) { return false; }
  }

  // The sign-in page's own logo, IF the scene's logo can land on it exactly: the phone
  // sign-in card draws the very same artwork (login-wordmark.png) as its background.
  function landingSpot() {
    /* A LOCK IS UP: LAND ON ITS LOGO (2026-11-01, Anthony's APK video). The scene used to leave first and
       the lock's logo faded in after it - the logo gone, "Welcome back" alone, then the logo back. The
       fingerprint / PIN lock draws the same artwork (KT_lockLogo, img.kt-lock-logo), so the scene's logo
       glides onto it like the sign-in card's. A white-label lock (a plate, no img) still has nothing to land on. */
    var lk = d.querySelector('#kt-bio-lock img.kt-lock-logo, #kt-pin-lock img.kt-lock-logo');
    if (lk) {
      try { var lr = lk.getBoundingClientRect(); return lr.width >= 40 && lr.height >= 12 ? lr : null; } catch (e) { return null; }
    }
    if (d.getElementById('kt-bio-lock') || d.getElementById('kt-pin-lock')) return null;   // a white-label lock: nothing to land on
    var c = d.querySelector('.ktl-mhero-card');
    if (!c) return null;
    try {
      var cs = w.getComputedStyle(c);
      var r = c.getBoundingClientRect();
      if (cs.display === 'none' || r.width < 40 || r.height < 12) return null;
      if (!/login-wordmark/.test(cs.backgroundImage || '')) return null;
      return r;
    } catch (e) { return null; }
  }

  function play(dash) {
    sset('kt_launch_seen', '1');
    sdel('kt_boot_scene'); sdel('kt_away_at');
    root.classList.add('ktls-playing');                 // the sign-in logo waits for its turn
    var t = dash ? dashTheme() : theme();
    var el = d.createElement('div');
    el.id = 'kt-launch';
    /* ANNOUNCED, NOT HIDDEN (2026-11-02 va): while the scene covers the form, VoiceOver says the app is starting
       (the form underneath is not usable yet), and the iOS verify lane (XCUITest) times "scene up" / "form usable"
       from this label. The logo inside stays hidden from assistive tech. */
    el.setAttribute('role', 'img');
    el.setAttribute('aria-label', 'KiddieTrac is starting');
    if (reduced) el.className = 'ktls-static';
    // Dashboard, brand not cached yet: start brand-less, dress it once /branding answers.
    var pend = !!dash && !dashBrandKnown();
    function build(t) {
    if (t) {
      el.style.setProperty('--ktls-bg', safeColour(t.splash_bg) || safeColour(t.primary_color) || '#0A1C3A');
      el.style.setProperty('--ktls-accent', safeColour(t.accent_color) || safeColour(t.primary_color) || '#13B7CC');
      var img = abs(t.launch_image_url || t.splash_image_url);
      el.innerHTML = '<div class="ktl-bg"></div>'
        + (img ? '<div class="ktl-photo" style="background-image:url(\'' + esc(img).replace(/'/g, '%27') + '\')"></div><div class="ktl-shade"></div>' : '')
        + '<div class="ktl-stage">' + wlLogo(t) + wlTag(t) + '</div>';
      fixImgs(el, t);
    } else {
      el.innerHTML = '<div class="ktl-bg"></div><div class="ktl-photo"></div><div class="ktl-shade"></div>'
        + '<div class="ktl-stage">' + ktLogo() + '<div class="ktl-tag kt-lockup">' + LOCKUP + '</div></div>';
    }
    }
    build(t);
    if (pend) el.classList.add('pend');
    var carry = !!(NC.at && !dash && !t && !reduced);
    if (carry) { carrying = true; el.classList.add('go'); }
    (d.body || root).appendChild(el);
    try { root.style.background = '#0A1C3A'; } catch (e) {}
    /* ktls-nokb: the email field's autofocus opened the phone keyboard over the scene
       (Android 15 emulator, 2026-10-21). Nothing takes focus until the scene is gone. */
    function noKb(e) { try { if (e.target && e.target.blur) e.target.blur(); } catch (x) {} }
    d.addEventListener('focusin', noKb, true);
    try { if (d.activeElement && d.activeElement !== d.body && d.activeElement.blur) d.activeElement.blur(); } catch (e) {}

    /* The intro waits, paused on its first frame, until the wordmark is decoded - started
       before the image arrived, a phone saw the rays' pop happen off screen (see the CSS). */
    var t0 = Date.now(), born = t0, started = false;
    function go() { if (started) return; started = true; t0 = Date.now(); el.classList.add('go'); }
    function sync() {
      try { var now = Math.max(0, Date.now() - NC.at); el.getAnimations({ subtree: true }).forEach(function (a) { a.currentTime = now; }); } catch (e) {}
    }
    if (carry) {
      // The cover's clock: the intro, the hold and the glide all run from when the app started drawing it.
      started = true; t0 = NC.at;
      void el.offsetWidth; sync();
      var paints = [];
      ['/login-wordmark.png', '/img/launch/blocks-portrait.webp'].forEach(function (src) {
        paints.push(new Promise(function (r) { var im = new Image(); im.onload = function () { (im.decode ? im.decode() : Promise.resolve()).then(r, r); }; im.onerror = r; im.src = src; }));
      });
      try { paints.push(d.fonts.load("700 15.5px 'Baloo 2'")); paints.push(d.fonts.load("800 10.5px 'Plus Jakarta Sans'")); } catch (e) {}
      var said = false;
      var say = function () {
        if (said) return; said = true; sync();
        w.requestAnimationFrame(function () { w.requestAnimationFrame(function () { NC.post('ready'); }); });
      };
      Promise.all(paints).then(say, say);
      setTimeout(say, 4000);
    }
    function startGo() {
    if (t) { go(); } else {
      try {
        var im = new Image();
        im.onload = function () { (im.decode ? im.decode() : Promise.resolve()).then(go, go); };
        im.onerror = go;
        im.src = '/login-wordmark.png';
      } catch (e) { go(); }
      setTimeout(go, 2000);
    }
    }
    if (!pend) startGo();
    else {
      resolveBrand(function (th) { if (!pend) return; pend = false; t = th; build(t); el.classList.remove('pend'); startGo(); });
      // Still unknown after 1.5 s: the scene rests brand-less and leaves (never our logo, then theirs).
      setTimeout(function () { if (pend) { started = true; t0 = Date.now(); } }, 1500);
    }
    /* The logo has drawn by ~1.5 s and the tag line is in by ~1.6 s; then it rests a
       beat (Anthony: "needs to have a slight pause as it moves away so quickly"). */
    /* 2700 -> 2300 (2026-11-02, Anthony's iPhone recording: "boot time reduced but not enough, still has a wait").
       The "#1" pill has finished popping at 1.8 s; the rest after it was 0.9 s, now 0.5 s - still a visible pause. */
    /* 2300 -> 2000, glide 750 -> 600 (2026-11-02 if, Anthony's iPhone: "still a delay at the initial launch"). The whole
       intro still plays (word, rays, swoosh, sparkles, slogan, "#1" pill settled at 1.8 s); the rest after it is 0.2 s and
       the glide is quicker. The glide's fades in kt-launch-scene.css (.6s) end inside OUT - change them together. */
    var HOLD = reduced ? 1100 : 2000;
    var OUT = reduced ? 400 : 600;
    var CAP = dash ? 9000 : (carry ? 12000 : 6500);      // never hold anyone longer than this (from first paint)
    var gone = false;

    function finish() {
      if (el.parentNode) el.parentNode.removeChild(el);
      try { root.style.background = ''; } catch (e) {}
      root.classList.remove('ktls-playing');
      d.removeEventListener('focusin', noKb, true);
      done();
    }
    function leave() {
      if (gone) return; gone = true;
      var spot = (!t && !reduced) ? landingSpot() : null;
      var logo = el.querySelector('.ktls-logo');
      if (spot && logo) {
        // Glide onto the sign-in logo while everything else melts away; hand over in one frame.
        var r = logo.getBoundingClientRect();
        var s = spot.width / r.width;
        var dx = (spot.left + spot.width / 2) - (r.left + r.width / 2);
        var dy = (spot.top + spot.height / 2) - (r.top + r.height / 2);
        logo.style.transition = 'transform ' + OUT + 'ms cubic-bezier(.45,0,.2,1)';
        el.classList.add('glide');
        void logo.offsetWidth;
        logo.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(' + s + ')';
        setTimeout(function () { w.requestAnimationFrame(finish); }, OUT);
      } else {
        // Nothing to land on: the scene (logo and all) leaves first; the sign-in logo after.
        el.classList.add('out');
        setTimeout(function () {
          finish();
          root.classList.add('ktls-reveal');
          setTimeout(function () { root.classList.remove('ktls-reveal'); }, 400);
        }, OUT);
      }
    }
    // Leave once the logo has drawn and rested AND the sign-in page underneath has been
    // parsed, so it lands on a finished form - never later than CAP.
    // On the dashboard: until the portal is up (its splash / reload cover gone), then
    // the scene dissolves straight into it.
    function ready() {
      if (!dash) return d.readyState !== 'loading';
      var sp = d.getElementById('kt-splash');
      var spGone = !sp || sp.classList.contains('kt-hide') || sp.style.display === 'none' || !sp.parentNode;
      return spGone && !d.getElementById('kt-reload-gate') && d.readyState !== 'loading';
    }
    (function tick() {
      if (gone) return;
      var ms = Date.now() - t0;
      if ((started && ms >= HOLD && ready() && (!carry || coverGone)) || Date.now() - born >= CAP) { leave(); return; }
      setTimeout(tick, 60);
    })();
    el.addEventListener('click', function () { if (!carry || coverGone) leave(); });   // a tap skips it
  }

  /* ── 2. Sign-in → portal ───────────────────────────────────────────────── */
  var fadeEl = null, fadeShown = null, leaving = false, carried = false;
  /* THE UNLOCK KEEPS ITS LOGO (2026-11-01, Anthony's video of the APK opening: after the fingerprint the
     lock's logo faded to an empty blue screen, then the splash logo faded back in lower down). The lock
     already shows the logo, so the frame it fades to carries the splash's finished logo in the splash's
     own column (logo, spinner, note line) - the dashboard then holds it (html.kti-carry) instead of
     replaying the intro. KiddieTrac's brand only, and only when this device knows the brand is ours:
     a white-label account keeps the bare frame and its own intro. */
  function carryFrame() {
    var col = d.createElement('div');
    col.className = 'ktpf-col';
    /* THE SPLASH'S OWN INTRO, HERE (2026-11-01, Anthony: "after the unlock the logo still doesnt mimic the
       splash of the logo like we had it initially"). The launch scene's layered logo (ktLogo) at the splash's
       spot: the lock's logo glides onto it, then it plays the full intro - word rises, rays pop, swoosh
       wipes, sparkles - before the portal opens on the same finished logo (dashboard html.kti-carry). */
    /* ONE LOGO THE WHOLE WAY (2026-11-01, real app on the emulator): fading the lock's logo out and the intro's
       in left either an empty screen or two words on top of each other - both read as the flash. So the intro's
       word here starts VISIBLE, 28 px low and at 96 % (where the splash's word starts its rise - ktpf-rise), the
       lock's logo glides exactly there, its rays and swoosh fade under this frame, and the word rises into place
       as the rays pop, the swoosh wipes and the sparkles fly: the splash's intro, one logo. */
    col.innerHTML = ktLogo('ktpf-logo') + '<span class="ktpf-spin"></span>'
      + '<span class="ktpf-note">Please wait…</span>';
    return '<div class="ktpf-glow a"></div><div class="ktpf-glow b"></div>' + col.outerHTML;
  }
  /* SIGNING IN SHOWS THE SPLASH, NOT A BLANK (2026-11-03, Anthony's iPhone recording: after Log in, ~2 s of plain
     white - the white-label frame is its light splash colour with nothing on it - then the "Little Steps / Signing
     you in..." splash appeared). The frame a password sign-in fades to is now the dashboard splash's FINISHED pose,
     drawn still: the brand's logo (KiddieTrac's word, or the white-label plate), the spinner and "Signing you in...",
     in the splash's own column. The dashboard then holds that same pose (html.kti-carry) instead of starting over:
     one splash from the tap to the portal. Same brand rule as the bare frame (brandNow); KiddieTrac's frame only when
     the brand is known (canCarry), the white-label plate whenever brandNow found the account's own brand. */
  function signFrame(t) {
    var col = d.createElement('div');
    col.className = 'ktpf-col';
    col.innerHTML = (t ? wlLogo(t) : ktLogo('ktpf-logo')) + '<span class="ktpf-spin on"></span>'
      + '<span class="ktpf-note on">Signing you in…</span>';
    return (t ? '' : '<div class="ktpf-glow a"></div><div class="ktpf-glow b"></div>') + col.outerHTML;
  }
  function canCarry(wl) {
    if (brandNow()) return !!wl;                         // white-label: the sign-in frame only (signFrame)
    var u = null;
    try { u = JSON.parse(sget('kt_user') || 'null'); } catch (e) {}
    var act = sget('kt_active_agency_id');
    // An unlock calls this before kt-biometric restores the session: the agency it unlocks into, as there.
    var a = (act && act !== 'all' ? act : null) || lget('kt_bio_agency') || (u && (u.landing_agency_id || u.agency_id));
    return !!(a && lget('kt_brand_a' + a));             // brand known (the dashboard would hold a brand-less frame otherwise)
  }
  function brandNow() {
    var u = null;
    try { u = JSON.parse(sget('kt_user') || 'null'); } catch (e) {}
    // Same order as the dashboard's branding block: the active agency, then where the portal
    // will land (landing_agency_id), then the user's own.
    var act = sget('kt_active_agency_id');
    return theme((act && act !== 'all' ? act : null) || (u && (u.landing_agency_id || u.agency_id)));
  }
  /* carry: { glide(rect) } - the frame is built invisible, the caller moves its own logo onto the frame's
     logo (rect) and returns how long that takes, then the frame fades in over it. */
  function showFade(instant, carry, sign) {
    if (fadeShown) { if (fadeEl && !carried) fadeEl.style.background = bareFrame(brandNow()); return fadeShown; }
    var still = false;
    fadeShown = new Promise(function (res) {
      try {
        fadeEl = d.createElement('div');
        fadeEl.id = 'kt-portal-fade';
        fadeEl.setAttribute('aria-hidden', 'true');
        var bt = brandNow();
        fadeEl.style.background = bareFrame(bt);
        if (carry && canCarry()) { carried = true; fadeEl.innerHTML = carryFrame(); if (reduced) fadeEl.classList.add('ktls-static'); }
        else if (sign && canCarry(true)) {
          carried = still = true;
          if (bt) {
            fadeEl.style.setProperty('--ktls-accent', safeColour(bt.accent_color) || safeColour(bt.primary_color) || '#13B7CC');
            if (/^#[0-9a-f]{6}$/i.test(bt.accent_color || '')) fadeEl.style.setProperty('--ktpf-spin', bt.accent_color);
          }
          fadeEl.innerHTML = signFrame(bt);
          fadeEl.classList.add('ktls-static');
          fixImgs(fadeEl, bt);
        }
        if (instant) fadeEl.className = 'in now';
        (d.body || root).appendChild(fadeEl);
        if (instant) { res(); return; }
        var fadeIn = function () {
          try {
            void fadeEl.offsetWidth;                     // commit the transparent state, then fade
            fadeEl.classList.add('in');
            var ok = false;
            var once = function () {
              if (ok) return; ok = true;
              if (!carried || reduced || still) { res(); return; }
              fadeEl.classList.add('go');                // the intro, now the frame is up; the portal opens after it
              setTimeout(res, 1650);
            };
            fadeEl.addEventListener('transitionend', once);
            setTimeout(once, reduced ? 260 : 460);       // transitionend is not guaranteed
          } catch (e) { res(); }
        };
        var wait = 0;
        if (carried && carry && carry.glide) {   // the sign-in frame (signFrame) has no glide
          try {
            var lr = fadeEl.querySelector('.ktls-logo').getBoundingClientRect();
            // The word's starting pose (ktpf-rise 0%): 28 px down, 96 % around its centre.
            var sw = reduced ? lr.width : lr.width * .96, sh = reduced ? lr.height : lr.height * .96, dy = reduced ? 0 : 28;
            wait = +carry.glide({ left: lr.left + (lr.width - sw) / 2, top: lr.top + (lr.height - sh) / 2 + dy, width: sw, height: sh }) || 0;
          } catch (e) {}
        }
        if (wait > 0) setTimeout(fadeIn, wait); else fadeIn();
      } catch (e) { res(); }
    });
    return fadeShown;
  }
  if (!onDash) w.KT_portalFade = function (url, replace) {
    var go = function () { try { if (replace) w.location.replace(url); else w.location.href = url; } catch (e) { w.location.href = url; } };
    if (leaving) return;
    leaving = true;
    // The dashboard plays the full intro on the way in (once) - or, after an unlock that carried the
    // lock's logo onto this frame, holds that logo still.
    sdel('kt_splash_seen');
    if (carried) sset('kt_carry_logo', '1'); else sdel('kt_carry_logo');
    /* FIX 2 (2026-10-21): resolve the person's own brand before the fade, so this frame and
       the dashboard's first frames are theirs (KiddieTrac when their agency is not
       white-label). Never holds a sign-in up for more than 1.2 s. */
    var primed = (w.KT && typeof w.KT.primeBrand === 'function')
      ? Promise.race([w.KT.primeBrand(), new Promise(function (r) { setTimeout(r, 1200); })]) : Promise.resolve();
    /* TAKE THE UPDATE HERE, NOT AFTER (2026-10-31, Anthony: "the flash when logging into the mobile app").
       The portal only looked for a new build once it had booted, then reloaded on the first screen change -
       a white loading screen and a dark reload cover right after signing in. While the fade is up, ask for
       the new service worker and wait (at most 1.8 s) for it to take over, so the portal opens on the
       current build and has no reason to reload. */
    var fresh = function () {
      return new Promise(function (done) {
        var finished = false, fin = function () { if (!finished) { finished = true; done(); } };
        setTimeout(fin, 1800);
        try {
          var sw = navigator.serviceWorker;
          // No worker controls this page (a first launch after install): the portal loads from the network, so there
          // is no old build to swap out - nothing to wait for (2026-11-02, the 1.8 s of the 3 s white after sign-in).
          if (!sw || !sw.getRegistration || !sw.controller) { fin(); return; }
          sw.getRegistration().then(function (r) {
            if (!r) { fin(); return; }
            var pending = function () { return !!(r.installing || r.waiting); };
            sw.addEventListener('controllerchange', fin);
            var u = r.update ? r.update() : null;
            var check = function () { if (!pending()) { fin(); } };
            if (u && u.then) { u.then(function () { setTimeout(check, 60); }, fin); } else { check(); }
          }, fin);
        } catch (e) { fin(); }
      });
    };
    /* AT THE SAME TIME, NOT ONE AFTER THE OTHER (2026-11-02, Anthony's iPhone video: "once unlocked there is some white
       screen/delay"). The brand wait (up to 1.2 s) and then the update wait (up to 1.8 s) ran in turn behind a blank
       brand-coloured frame - 3.0 s of white on Little Steps. The update check starts now, alongside the brand. */
    var freshP = fresh();
    // The sign-in frame is decided inside showFade (after the brand is known), so the dashboard is told to hold it here.
    var after = function () { Promise.all([showFade(false, null, true), freshP]).then(function () { if (carried) sset('kt_carry_logo', '1'); go(); }, go); };
    primed.then(after, after);
  };
  // Fingerprint / PIN success: go to the bare frame now, while the session is checked.
  // opts.glide(rect): carry the lock's logo onto the frame's (see carryFrame) - returns the glide's ms.
  if (!onDash) w.KT_portalFade.prepare = function (opts) { showFade(false, opts && opts.glide ? opts : null); };

  /* ── 2b. Back from a sign-out (2026-10-21) ─────────────────────────────────
     The portal faded to the brand background (kt-signout-fade.js) and left it in
     kt_out_fade. Open on that same frame, let the sign-in page draw its logo underneath,
     then fade the frame away - no launch scene, no flash, one logo. */
  function arrive() {
    var bg = sget('kt_out_fade');
    sdel('kt_out_fade');
    sset('kt_launch_seen', '1');
    showFade(true);
    function noKb(e) { try { if (e.target && e.target.blur) e.target.blur(); } catch (x) {} }
    d.addEventListener('focusin', noKb, true);
    if (fadeEl && bg && /^(#[0-9a-f]{3,8}|(radial|linear)-gradient\([#0-9a-z%,. ()]+\))$/i.test(bg)) fadeEl.style.background = bg;
    // The sign-out spinner (kt-signout-fade.js) stays up until the sign-in logo is in place.
    var ACC = sget('kt_out_acc'); sdel('kt_out_acc');
    if (!/^#[0-9a-f]{3,8}$/i.test(ACC || '')) ACC = '#13B7CC';
    if (fadeEl) { try { fadeEl.innerHTML = '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;pointer-events:none">'
        + '<span class="ktso-spin" style="display:block;width:28px;height:28px;box-sizing:border-box;border-radius:50%;border:3px solid rgba(255,255,255,.2);border-top-color:' + ACC + ';animation:ktso-spin .8s linear infinite"></span>'
        + '<span style="font:600 13px/1 \'Plus Jakarta Sans\',system-ui,sans-serif;color:rgba(255,255,255,.85);letter-spacing:.02em">' + ktLsT('Signing out\u2026') + '</span></div>'; } catch (e) {} }
    var born = Date.now(), out = false;
    function lift() {
      if (out) return; out = true;
      d.removeEventListener('focusin', noKb, true);   // nothing refocuses: the keyboard stays down
      try { root.style.background = ''; } catch (e) {}   // the head's no-white-frame colour (index.html)
      var el = fadeEl;
      if (!el || leaving) { done(); return; }
      el.classList.remove('now'); void el.offsetWidth; el.classList.remove('in');
      setTimeout(function () {
        if (leaving) return;
        if (el.parentNode) el.parentNode.removeChild(el);
        if (fadeEl === el) { fadeEl = null; fadeShown = null; }
        done();
      }, reduced ? 240 : 460);
    }
    var waits = [new Promise(function (r) { if (d.readyState !== 'loading') r(); else d.addEventListener('DOMContentLoaded', r); })];
    try {
      var im = new Image();
      waits.push(new Promise(function (r) { im.onload = function () { (im.decode ? im.decode() : Promise.resolve()).then(r, r); }; im.onerror = r; }));
      im.src = '/login-wordmark.png';
    } catch (e) {}
    try { if (d.fonts && d.fonts.ready) waits.push(d.fonts.ready); } catch (e) {}
    // ...and the Google / Microsoft row (kt-social-login.js fetches it), which pushes the
    // logo down when it lands - lifting before it would show the page jump.
    var settled = function () {
      if (out) return;
      if (!w.__ktSocialDone && Date.now() - born < 1500) { setTimeout(settled, 50); return; }
      requestAnimationFrame(function () { requestAnimationFrame(function () { setTimeout(lift, Math.max(0, 140 - (Date.now() - born))); }); });
    };
    Promise.all(waits).then(settled, settled);
    setTimeout(lift, 1800);
  }

  function dashAgency() {
    var u = null;
    try { u = JSON.parse(sget('kt_user') || 'null'); } catch (e) {}
    var a = sget('kt_active_agency_id');
    return (a && a !== 'all') ? a : (u && (u.landing_agency_id || u.agency_id));
  }
  function dashBrandKnown() { var a = dashAgency(); return !a || !!lget('kt_brand_a' + a); }
  function dashTheme() { var a = dashAgency(); return a ? theme(a) : null; }
  function resolveBrand(cb) {
    var a = dashAgency();
    if (!a) { cb(null); return; }
    try {
      fetch('https://api.kiddietrac.com/api/v1/branding?agency_id=' + encodeURIComponent(a))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (x) {
          if (!x) return;
          var th = x.theme || null;
          try { if (!lget('kt_brand_a' + a)) L.setItem('kt_brand_a' + a, JSON.stringify({ theme: th, at: Date.now() })); } catch (e) {}
          cb(th && th.white_label ? th : null);
        }).catch(function () {});
    } catch (e) {}
  }

  /* ── 3. Start ──────────────────────────────────────────────────────────── */
  try {
    if (/kt_sso=|kt_social=/.test(w.location.hash || '')) {
      // A sign-in coming back from Google / Microsoft / the marketing site: never flash the
      // form on its way through. If the hand-off fails, the form comes back.
      showFade(true);
      setTimeout(function () {
        if (!leaving && fadeEl) { fadeEl.classList.remove('in', 'now'); setTimeout(function () { if (fadeEl && fadeEl.parentNode && !leaving) { fadeEl.parentNode.removeChild(fadeEl); fadeEl = null; fadeShown = null; } }, 500); }
      }, 9000);
      done();
    } else if (onDash) {
      /* The dashboard: the blocks scene IN PLACE of its own intro on a first boot (one
         logo - html.ktls-boot hides the splash's), only when the head chose it, signed
         in, and this account's brand is known (an unknown one is cb268db's brand-less hold). */
      // The app keeps its session in localStorage until app.js copies it over (2026-10-26).
      if (sget('kt_boot_scene') === '1' && (sget('kt_token') || (w.__KT_NATIVE && lget('kt_token')))) { root.classList.add('ktls-boot'); play(true); }
      else done();
    } else if (sget('kt_out_fade')) { arrive(); } else if (shouldPlay()) { play(); } else { done(); }
  } catch (e) { done(); }
  // The app's cover is up (3.12.13 has the ktScene handler) but this page is not carrying it - another screen, no
  // marker (storage not yet shared), reduced motion: let it fade. NC.post is a no-op in older apps and browsers.
  if (!carrying) NC.post('gone');
})(window, document);
