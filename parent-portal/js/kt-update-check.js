/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — tell people when they are running yesterday's app.

   WHY THIS EXISTS (2026-09-14).
   Three fixes were deployed to the row-action menu and reported as "still not
   working". They were on the server the whole time. The access log settles it:
   on 14 September this office's desktop made 1,224 API calls and ZERO requests
   for dashboard.html or any /js/ file. The tab had been open since before the
   deploys, polling the API quite happily, serving every script from cache.

   Nothing was broken except the assumption that a long-lived tab ever asks for
   new code. It does not. A single-page app that never navigates never re-fetches
   its own shell, and the service worker cannot help: it only revalidates when
   something asks it to.

   So this asks. It is the difference between "we shipped it" and "they have it".

   DELIBERATELY A NOTICE, NOT A RELOAD. An earlier version of the deploy machinery
   force-reloaded the APK on every resume and that was rightly backed out — losing
   a half-typed message to a silent reload is its own bug. This offers; the reader
   decides. See kiddietrac-deploy-churn.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktUpdateCheck) { return; }
  w.__ktUpdateCheck = true;

  var EVERY_MS = 10 * 60 * 1000;   // ten minutes, and only while on screen
  var SETTLE_MS = 60 * 1000;       // don't ask the moment the page loads

  /* THE BUILD IS THE WHOLE SET OF STAMPS, NOT ANY ONE OF THEM.

     My first attempt took the first /js/ tag's ?v= as a build id. It is not one:
     deploy.py re-stamps only the files it is given, so every file carries the date
     of ITS last deploy and most of them never move. The first tag on this page reads
     202609080033 — 8 September — on a page built today.

     A fingerprint of every stamp does work, because a deploy always moves at least
     one of them. Compare the joined list: any difference is a new build, and no
     difference means there is genuinely nothing to fetch. */
  /* SCREENS LOAD ON DEMAND (2026-11-02): most screen files are no longer tags but entries in the
     page's window.__KT_LAZY list ("js/<file>?v=<stamp>", no leading slash), fetched when opened. Both
     sides count them, so a deploy of a lazy file is still a new build, and a file the loader injected
     (data-kt-lazy) is not counted twice. A quote after "=" is a tag's src="js/…", which was never counted. */
  function uniq(out) { return out.sort().filter(function (x, i) { return x !== out[i - 1]; }).join('|'); }
  function fingerprint(html) {
    var out = [];
    var re = /(?:\/|(?:^|[^=])["'])js\/([A-Za-z0-9._-]+\.js)\?v=([^"'&\s>]+)/g;
    var m;
    while ((m = re.exec(html)) !== null) { out.push(m[1] + '@' + m[2]); }
    return uniq(out);
  }

  function runningFingerprint() {
    var out = [];
    var tags = d.querySelectorAll('script[src*="/js/"]:not([data-kt-lazy])');
    for (var i = 0; i < tags.length; i++) {
      var m = /\/js\/([A-Za-z0-9._-]+\.js)\?v=([^"'&\s]+)/.exec(tags[i].getAttribute('src') || '');
      if (m) { out.push(m[1] + '@' + m[2]); }
    }
    try {
      Object.keys(w.__KT_LAZY || {}).forEach(function (k) {
        var m2 = /^\/?js\/([A-Za-z0-9._-]+\.js)\?v=([^"'&\s]+)/.exec(k);
        if (m2) { out.push(m2[1] + '@' + m2[2]); }
      });
    } catch (e) {}
    return uniq(out);
  }

  var mine = runningFingerprint();
  var told = false;

  /* IN THE INSTALLED APP: NO POPUP (2026-10-30, Anthony's iPhone recording showed "A fresh update is ready -
     Update now / Not now" inside the app). A phone app does not ask people to reload it; it is simply current.
     So in the native shells (iPhone and Android) the newer web build is taken at the next safe moment instead:
     when the app comes back to the front after five minutes or more away, provided nothing has been typed that a reload
     would lose (and on every cold start, which always loads the current build). Store
     updates of the app itself are a different thing and stay with kt-appupdate.js. The browser keeps the card. */
  function isNativeShell() {
    try { return !!(w.Capacitor && w.Capacitor.isNativePlatform && w.Capacitor.isNativePlatform()); } catch (e) { return false; }
  }
  var pendingQuiet = false, awayAt = 0;
  function unsavedTyping() {
    var f = d.querySelectorAll('textarea, input:not([type]), input[type="text"], input[type="email"], input[type="tel"], input[type="number"], [contenteditable="true"]');
    for (var i = 0; i < f.length; i++) {
      var el = f[i];
      if (el.closest && el.closest('[data-kt-no-filter], .kt-tf-box, [role="search"]')) { continue; }   // a filter box loses nothing
      if (el.isContentEditable) { if ((el.textContent || '').trim()) { return true; } continue; }
      if ((el.value || '') !== (el.defaultValue || '')) { return true; }
    }
    return false;
  }
  function quietReload() {
    if (!pendingQuiet || d.hidden || unsavedTyping()) { return; }
    if (w.KT_mediaBusy && w.KT_mediaBusy()) { return; }   // watching a clip (ticket #223)
    pendingQuiet = false;
    try { if (w.KT && w.KT.crumb) { w.KT.crumb('update', 'native: newer web build taken on resume'); } } catch (e) {}
    try {
      var u = new URL(w.location.href);
      u.searchParams.set('_b', String(Date.now()));
      w.location.replace(u.toString());
    } catch (e) { w.location.reload(); }
  }

  function offer(theirs) {
    if (told) { return; }
    told = true;
    /* No "A fresh update is ready" card for a website demo person either: like the native apps, the new build loads
       quietly on the next calm reload (2026-10-31). */
    if (isNativeShell() || window.KT_PUBLIC_DEMO) { pendingQuiet = true; return; }

    /* A CENTRED CARD, not a pill at the foot of the screen (2026-10-13, Anthony: "its
       truncated and should show up in the middle of the screen as a popup"). The pill sat
       behind the phone's own navigation bar and wrapped its sentence into four lines on a
       narrow screen, so "is ready." disappeared under the system bar on the APK. */
    if (!d.getElementById('kt-upd-css')) {
      var st = d.createElement('style');
      st.id = 'kt-upd-css';
      st.textContent = [
        /* REDESIGN (2026-10-25, Anthony: "make that update available prompt ... look better"): a gradient
           header with a small illustration, a friendlier title, a reassurance line and a gradient button, over
           a softly blurred page. No logo, so it suits white-label agencies too. */
        '#kt-upd{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;',
        '  padding:20px max(16px,env(safe-area-inset-right)) calc(20px + var(--kt-safe-bottom, env(safe-area-inset-bottom,0px))) max(16px,env(safe-area-inset-left));',
        '  background:rgba(8,16,36,.42);-webkit-backdrop-filter:blur(6px) saturate(.9);backdrop-filter:blur(6px) saturate(.9);animation:kt-upd-fade .2s ease-out both;}',
        '#kt-upd .kt-upd-card{width:100%;max-width:360px;background:#fff;border-radius:22px;overflow:hidden;text-align:center;',
        '  box-shadow:0 30px 70px -20px rgba(8,20,45,.55),0 0 0 1px rgba(15,23,42,.05);font:500 15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#0F172A;',
        '  animation:kt-upd-pop .28s cubic-bezier(.16,.84,.44,1) both;}',
        '#kt-upd .kt-upd-top{position:relative;height:118px;background:linear-gradient(135deg,#1DB9C3 0%,#2563EB 55%,#7C3AED 100%);display:flex;align-items:center;justify-content:center;overflow:hidden;}',
        '#kt-upd .kt-upd-top::before,#kt-upd .kt-upd-top::after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.14);}',
        '#kt-upd .kt-upd-top::before{width:150px;height:150px;right:-40px;top:-60px;}',
        '#kt-upd .kt-upd-top::after{width:110px;height:110px;left:-30px;bottom:-55px;}',
        '#kt-upd .kt-upd-badge{position:relative;width:64px;height:64px;border-radius:20px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.45);',
        '  display:flex;align-items:center;justify-content:center;box-shadow:0 10px 24px -10px rgba(0,0,0,.35);animation:kt-upd-float 3s ease-in-out infinite;}',
        '#kt-upd .kt-upd-badge svg{width:34px;height:34px;}',
        '#kt-upd .kt-upd-spark{position:absolute;color:#fff;opacity:.9;font-size:14px;animation:kt-upd-twinkle 2.2s ease-in-out infinite;}',
        '#kt-upd .kt-upd-body{padding:20px 24px 18px;}',
        '#kt-upd .kt-upd-eyebrow{display:inline-block;font-size:11px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:#2563EB;background:#EFF6FF;border-radius:999px;padding:3px 10px;margin-bottom:10px;}',
        '#kt-upd h2{margin:0 0 6px;font-size:19px;font-weight:800;letter-spacing:-.01em;}',
        '#kt-upd p{margin:0 0 14px;color:#475569;font-size:14.5px;}',
        '#kt-upd .kt-upd-safe{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12.5px;color:#047857;font-weight:700;margin:0 0 16px;}',
        '#kt-upd button{appearance:none;display:block;width:100%;border:0;border-radius:999px;font:inherit;font-weight:800;cursor:pointer;-webkit-tap-highlight-color:transparent;}',
        '#kt-upd .kt-upd-go{background:linear-gradient(135deg,#1DB9C3,#2563EB 55%,#7C3AED);color:#fff;padding:13px 16px;font-size:15.5px;box-shadow:0 12px 24px -12px rgba(37,99,235,.8);transition:transform .12s ease,box-shadow .12s ease;}',
        '#kt-upd .kt-upd-go:hover{box-shadow:0 14px 28px -12px rgba(37,99,235,.95);}',
        '#kt-upd .kt-upd-go:active{transform:scale(.98);}',
        '#kt-upd button:focus{outline:none;}#kt-upd button:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.35);}',
        '#kt-upd .kt-upd-later{background:none;color:#64748B;padding:11px 16px 2px;font-size:14px;font-weight:700;}',
        '#kt-upd .kt-upd-later:hover{color:#334155;}',
        '@keyframes kt-upd-fade{from{opacity:0}to{opacity:1}}',
        '@keyframes kt-upd-pop{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:none}}',
        '@keyframes kt-upd-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}',
        '@keyframes kt-upd-twinkle{0%,100%{opacity:.35;transform:scale(.8)}50%{opacity:1;transform:scale(1.1)}}',
        '@media (prefers-reduced-motion:reduce){#kt-upd,#kt-upd .kt-upd-card,#kt-upd .kt-upd-badge,#kt-upd .kt-upd-spark{animation:none}}'
      ].join('\n');
      (d.head || d.documentElement).appendChild(st);
    }

    var bar = d.createElement('div');
    bar.id = 'kt-upd';
    // alertdialog, not dialog: kt-live and the shell treat [role="dialog"] as "a modal is
    // open" and hold refreshes, which this notice has no reason to do.
    bar.setAttribute('role', 'alertdialog');
    bar.setAttribute('aria-modal', 'true');
    bar.setAttribute('aria-labelledby', 'kt-upd-h');
    bar.innerHTML = '<div class="kt-upd-card">'
      + '<div class="kt-upd-top" aria-hidden="true">'
      +   '<span class="kt-upd-spark" style="left:28%;top:24%;">✦</span><span class="kt-upd-spark" style="right:27%;top:30%;animation-delay:.7s;">✦</span><span class="kt-upd-spark" style="left:36%;bottom:20%;font-size:10px;animation-delay:1.3s;">✦</span>'
      +   '<div class="kt-upd-badge"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/><path d="M12 8v4l2.5 2.5"/></svg></div>'
      + '</div>'
      + '<div class="kt-upd-body">'
      +   '<span class="kt-upd-eyebrow">What\'s new</span>'
      +   '<h2 id="kt-upd-h">A fresh update is ready</h2>'
      +   '<p>We\'ve just improved the portal. Update now to get the latest fixes and features.</p>'
      +   '<div class="kt-upd-safe"><span aria-hidden="true">✓</span> Takes a second · finish anything you\'re typing first</div>'
      +   '<button type="button" class="kt-upd-go">Update now</button>'
      // aria-label="Close" tells kt-modal-guard.js this card already has a close control.
      +   '<button type="button" class="kt-upd-later" aria-label="Close">Not now</button>'
      + '</div>'
      + '</div>';

    var go = bar.querySelector('.kt-upd-go');
    go.addEventListener('click', function () {
      /* Cache-busted on purpose. A plain reload can be answered from the very
         caches that caused this, which would dismiss the notice and change
         nothing — the exact failure this file exists to end. */
      go.textContent = 'Updating…'; go.disabled = true;
      try {
        var u = new URL(w.location.href);
        u.searchParams.set('_b', String(Date.now()));
        w.location.replace(u.toString());
      } catch (e) { w.location.reload(); }
    });
    bar.querySelector('.kt-upd-later').addEventListener('click', function () { bar.remove(); });
    // Not closed by a tap on the backdrop: a scroll or a drag that ends there is not "Later".

    (d.body || d.documentElement).appendChild(bar);
    try { go.focus({ preventScroll: true }); } catch (e) {}

    /* Name only what CHANGED. The fingerprint is a few kilobytes of filenames and
       would bury the breadcrumb it is meant to explain. */
    try {
      if (w.KT && w.KT.crumb) {
        var was = mine.split('|'), now = String(theirs).split('|');
        var moved = now.filter(function (x) { return was.indexOf(x) === -1; });
        w.KT.crumb('update', 'offered — ' + moved.length + ' file(s) changed: '
          + moved.slice(0, 4).join(', '));
      }
    } catch (e) {}
  }

  async function check() {
    if (told || d.hidden || !mine) { return; }
    try {
      /* cache:'reload' bypasses the browser's own cache; the shell is
         no-cache/must-revalidate anyway, so this is cheap — and it is the one
         request a long-lived tab otherwise never makes. */
      var res = await fetch('/dashboard.html', { cache: 'reload', credentials: 'same-origin' });
      if (!res || !res.ok) { return; }
      var theirs = fingerprint(await res.text());
      if (theirs && theirs !== mine) { offer(theirs); }
    } catch (e) {
      // Offline, or the shell moved. Either way this must never be noisy.
    }
  }

  setTimeout(check, SETTLE_MS);
  setInterval(check, EVERY_MS);

  /* Coming back to the tab is the moment a deploy is most likely to have landed
     since you last looked, and the moment a reload costs least. */
  d.addEventListener('visibilitychange', function () {
    if (!d.hidden) { setTimeout(check, 2000); }
  });

  // Same fact, the one signal a native web view is guaranteed to get.
  try {
    var App = w.Capacitor && w.Capacitor.Plugins && w.Capacitor.Plugins.App;
    if (App && App.addListener) {
      App.addListener('appStateChange', function (st) {
        if (st && !st.isActive) { awayAt = Date.now(); return; }
        // Back after five minutes or more: it is a fresh visit, the moment a reload costs nothing.
        if (st && st.isActive) { if (pendingQuiet && awayAt && Date.now() - awayAt >= 5 * 60 * 1000) { setTimeout(quietReload, 300); } else { setTimeout(check, 2000); } }
      });
    }
  } catch (e) {}

  // For a support call: KT.updateCheck() answers "am I on the current build?"
  w.KT = w.KT || {};
  w.KT.updateCheck = function () { told = false; return check(); };
  /* For a support call: which files this tab is running, and how many. */
  w.KT.buildStamp = function () {
    var n = mine ? mine.split('|').length : 0;
    return n + ' file(s); fingerprint ' + (mine ? mine.length : 0) + ' chars';
  };
  w.KT.buildFingerprint = function () { return mine; };
})(window, document);
