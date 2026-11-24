/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — THE PHONE SIGN-IN FITS ONE SCREEN (2026-10-31).

   Anthony: "for iphone the emulator login page shrink it automatically to fit display size without
   needing to scroll - not sure if this impacts production but check as well".

   Measured (scratchpad lf/loginfit.mjs + demofit.mjs): the phone sign-in was sized for a tall phone.
   The website demo iPhone (393x852 with the 59/34 notch and home-indicator insets, Google +
   Microsoft buttons showing) ran 31px past the bottom - the copyright sat under the home indicator.
   In real phone browsers it scrolled on every short screen: 375x667 (iPhone SE/8) 117px,
   320x568 253px, Android 360x780 58px; the iPhone app on a 320x568 82px.

   Media queries can't know the sign-in's real height: the Google/Microsoft row is there or not
   (hidden in the iPhone app), a signed-out or error banner may show, the authenticator row opens,
   the brand's logo has its own shape, and the safe areas differ per phone. So this measures, and
   steps the page down only as far as it has to - one step at a time, re-measured after each:
     1  tighter spacing everywhere
     2  smaller logo; the "#1 in North America" pill goes
     3  the "Sign in to see how your little one's day is going" line goes; fields/buttons a little shorter
     4  the "No account yet?" helper and the copyright line go (Terms · Privacy stay)
     5  smaller logo and title; the slogan / brand tagline goes
     6  the Google/Microsoft row, an error or signed-out notice and the logo a little more compact
   Never hidden: the fields, Log in, Forgot?, passkey, Google/Microsoft, Terms and Privacy.
   Phone layout only (<=768px, or the app on a phone); desktop is untouched. While the keyboard is
   open (html.kt-kb-open, kt-ios-safearea.js) that rule owns the hero; this re-fits when it closes.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktLoginFit) { return; }
  w.__ktLoginFit = true;
  var STEPS = 6, root = d.documentElement;

  function css() {
    if (d.getElementById('kt-login-fit-css')) { return; }
    var P = 'html.kt-fit-', st = d.createElement('style');
    st.id = 'kt-login-fit-css';
    st.textContent = ''
      // 1 - tighter spacing
      + P + '1 body .ktl-form-side{padding-top:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 10px)!important;padding-bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 8px)!important}'
      + P + '1 .ktl-mobile-hero{margin-bottom:10px!important}'
      + P + '1 .ktl-mobile-hero .ktl-mhero-tag,' + P + '1 .ktl-mobile-hero .kt-wl-tagline{margin-top:8px!important}'
      + P + '1 .kt-lockup{gap:6px!important}'
      + P + '1 .ktl-title{margin-bottom:2px!important}'
      + P + '1 .ktl-sub{margin-bottom:10px!important}'
      + P + '1 .ktl-wrap #kt-social{margin:0 0 2px!important}'
      + P + '1 .ktl-wrap .auth-form .form-row{margin-bottom:12px!important}'
      // the password row keeps room for "Forgot?" hanging under it (it sits 21px below the field)
      + P + '1 .ktl-wrap .auth-form .form-row.kt-pw-row{margin-bottom:24px!important}'
      + P + '1 .ktl-wrap #submitBtn{margin-top:4px!important}'
      + P + '1 #ktPasskeyWrap{margin-top:8px!important}'
      + P + '1 #ktPasskeyWrap>div:first-child{margin:0 0 8px!important}'
      + P + '1 .ktl-foot{margin-top:8px!important}'
      + P + '1 .ktl-legal{margin-top:8px!important}'
      // 2 - smaller logo, no "#1" pill
      // zoom, not width: the KiddieTrac wordmark is the card's background, a white-label logo is an <img> on a white
      // tile - zoom shrinks either as a whole and the layout follows (a transform would leave the old space behind).
      + P + '2 .ktl-mobile-hero .ktl-mhero-card{zoom:.8}'
      + P + '2 .ktl-mobile-hero .kt-no1{display:none!important}'
      // 3 - no subtitle line; fields and buttons a little shorter
      + P + '3 .ktl-sub{display:none!important}'
      + P + '3 .ktl-title{margin-bottom:10px!important}'
      + P + '3 .ktl-wrap .auth-form input{padding-top:12px!important;padding-bottom:12px!important}'
      + P + '3 .ktl-wrap .auth-form .form-row>label,' + P + '3 .ktl-wrap .auth-form .pw-label-row label{top:14px}'
      + P + '3 .ktl-wrap #submitBtn{padding-top:11px!important;padding-bottom:11px!important}'
      + P + '3 #ktPasskeyBtn{padding:9px!important}'
      // 4 - no "No account yet?" helper, no copyright line (Terms · Privacy stay)
      + P + '4 .ktl-foot{display:none!important}'
      + P + '4 .ktl-legal .kt-copyright,' + P + '4 .ktl-legal br{display:none!important}'
      + P + '4 .ktl-legal{margin-top:10px!important}'
      // 5 - smaller logo and title, no slogan / brand tagline
      + P + '5 .ktl-mobile-hero .ktl-mhero-card{zoom:.62}'
      + P + '5 .ktl-mobile-hero .ktl-mhero-tag,' + P + '5 .ktl-mobile-hero .kt-wl-tagline{display:none!important}'
      + P + '5 .ktl-title{font-size:20px!important}'
      // 6 - the Google / Microsoft row and an error or signed-out notice a little more compact (a notice can arrive late)
      + P + '6 .ktl-wrap #kt-social{zoom:.82}'
      + P + '6 .ktl-wrap #errorMsg,' + P + '6 #signedOutBanner{padding:7px 10px!important;margin:0 0 8px!important;font-size:12px!important}'
      + P + '6 .ktl-mobile-hero .ktl-mhero-card{zoom:.54}';
    (d.head || root).appendChild(st);
  }

  function phone() {
    try { return w.matchMedia('(max-width: 768px)').matches || root.classList.contains('kt-native'); } catch (e) { return false; }
  }
  function px(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  var probe = null;
  function safeBottom() {
    var v = px(getComputedStyle(root).getPropertyValue('--kt-safe-bottom'));
    if (v) { return v; }
    if (!probe) {
      probe = d.createElement('div');
      probe.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:0;padding-bottom:env(safe-area-inset-bottom,0px);visibility:hidden;pointer-events:none';
      (d.body || root).appendChild(probe);
    }
    return probe.offsetHeight || 0;
  }
  function over() {
    var se = d.scrollingElement || root, sy = w.pageYOffset || se.scrollTop || 0;
    var o = se.scrollHeight - se.clientHeight;
    var legal = d.querySelector('.ktl-legal') || d.querySelector('.ktl-foot');
    if (legal && legal.offsetParent !== null) {
      o = Math.max(o, Math.ceil(legal.getBoundingClientRect().bottom + sy - ((w.innerHeight || se.clientHeight) - safeBottom())));
    }
    return o;
  }
  function set(n) {
    for (var i = 1; i <= STEPS; i++) { root.classList.toggle('kt-fit-' + i, i <= n); }
  }

  var busy = false, queued = false;
  function fit() {
    queued = false;
    if (!d.querySelector('.ktl-form-side')) { return; }
    if (root.classList.contains('kt-kb-open')) { return; }        // the keyboard rule owns the hero while typing
    if (!phone()) { set(0); return; }
    busy = true;
    set(0);
    var n = 0;
    while (n < STEPS && over() > 1) { n++; set(n); }
    root.setAttribute('data-kt-fit', String(n));
    busy = false;
  }
  // setTimeout, not requestAnimationFrame: a frame callback can be held back (a page still hidden while it loads),
  // and a fit that never runs leaves the page scrolling.
  function soon() { if (queued) { return; } queued = true; setTimeout(fit, 30); }
  /* Late arrivals that change nothing we watch (web fonts swapping in, the social sign-in row deciding, the launch
     scene handing over): look again every second for the first eight seconds - a few cheap reflows, phone sign-in only. */
  function later() { for (var i = 1; i <= 8; i++) { setTimeout(soon, i * 1000); } }

  function boot() {
    css();
    try { var pw = d.getElementById('password'), row = pw && pw.closest ? pw.closest('.form-row') : null; if (row) { row.classList.add('kt-pw-row'); } } catch (e) {}
    fit();
    later();
    w.addEventListener('resize', soon);
    w.addEventListener('load', soon);
    w.addEventListener('orientationchange', soon);
    if (d.fonts && d.fonts.ready) { d.fonts.ready.then(soon); }
    try { if (d.fonts && d.fonts.addEventListener) { d.fonts.addEventListener('loadingdone', soon); } } catch (e) {}
    d.addEventListener('visibilitychange', function () { if (!d.hidden) { soon(); } });
    // A logo that loads late (the brand's own) changes the hero's height.
    d.addEventListener('load', function (e) { if (e.target && e.target.tagName === 'IMG') { soon(); } }, true);
    try {
      var side = d.querySelector('.ktl-form-side');
      if (side) {
        new MutationObserver(function () { if (!busy) { soon(); } })
          .observe(side, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'style', 'src'] });
      }
      /* The brand arrives on its own schedule (boot.js / branding-loader add a stylesheet and swap the logo): a white-label
         sign-in measured before its brand styles landed fitted, then grew past the bottom (Starlight at 375x667). Watch the
         parts' own sizes, and stylesheets being added. A size we caused ourselves settles: the next fit lands on the same step. */
      if (w.ResizeObserver) {
        var ro = new ResizeObserver(function () { if (!busy) { soon(); } });
        ['.ktl-mobile-hero', '.ktl-form-inner', '.ktl-legal'].forEach(function (sel) { var el = d.querySelector(sel); if (el) { ro.observe(el); } });
      }
      if (d.head) { new MutationObserver(function () { if (!busy) { soon(); } }).observe(d.head, { childList: true }); }
      // Keyboard closed (kt-kb-open removed), the app shape decided (kt-native), white label applied (kt-wl): fit again.
      var last = '';
      new MutationObserver(function () {
        if (busy) { return; }
        var c = root.className.replace(/\bkt-fit-\d\b/g, '').replace(/\s+/g, ' ').trim();
        if (c !== last) { last = c; soon(); }
      }).observe(root, { attributes: true, attributeFilter: ['class', 'style'] });
    } catch (e) {}
  }
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  w.KT = w.KT || {}; w.KT.loginFit = fit;
})(window, document);
