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
  function fingerprint(html) {
    var out = [];
    var re = /\/js\/([A-Za-z0-9._-]+\.js)\?v=([^"'&\s>]+)/g;
    var m;
    while ((m = re.exec(html)) !== null) { out.push(m[1] + '@' + m[2]); }
    return out.sort().join('|');
  }

  function runningFingerprint() {
    var out = [];
    var tags = d.querySelectorAll('script[src*="/js/"]');
    for (var i = 0; i < tags.length; i++) {
      var m = /\/js\/([A-Za-z0-9._-]+\.js)\?v=([^"'&\s]+)/.exec(tags[i].getAttribute('src') || '');
      if (m) { out.push(m[1] + '@' + m[2]); }
    }
    return out.sort().join('|');
  }

  var mine = runningFingerprint();
  var told = false;

  function offer(theirs) {
    if (told) { return; }
    told = true;

    /* A CENTRED CARD, not a pill at the foot of the screen (2026-10-13, Anthony: "its
       truncated and should show up in the middle of the screen as a popup"). The pill sat
       behind the phone's own navigation bar and wrapped its sentence into four lines on a
       narrow screen, so "is ready." disappeared under the system bar on the APK. */
    if (!d.getElementById('kt-upd-css')) {
      var st = d.createElement('style');
      st.id = 'kt-upd-css';
      st.textContent = [
        '#kt-upd{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;',
        '  padding:20px max(16px,env(safe-area-inset-right)) calc(20px + env(safe-area-inset-bottom,0px)) max(16px,env(safe-area-inset-left));',
        '  background:rgba(8,20,45,.45);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);animation:kt-upd-fade .18s ease-out both;}',
        '#kt-upd .kt-upd-card{width:100%;max-width:340px;background:#fff;border-radius:20px;padding:24px 22px 18px;text-align:center;',
        '  box-shadow:0 24px 60px -16px rgba(8,20,45,.5);font:500 15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#0F172A;',
        '  animation:kt-upd-pop .22s cubic-bezier(.16,.84,.44,1) both;}',
        '#kt-upd .kt-upd-ic{width:56px;height:56px;margin:0 auto 12px;border-radius:16px;display:flex;align-items:center;justify-content:center;',
        '  background:linear-gradient(135deg,#0E7C90,#14A1A8);color:#fff;font-size:26px;box-shadow:0 8px 18px -8px rgba(14,124,144,.7);}',
        '#kt-upd h2{margin:0 0 6px;font-size:18px;font-weight:800;letter-spacing:-.01em;}',
        '#kt-upd p{margin:0 0 18px;color:#475569;font-size:14.5px;}',
        '#kt-upd button{appearance:none;display:block;width:100%;border:0;border-radius:12px;font:inherit;font-weight:800;cursor:pointer;-webkit-tap-highlight-color:transparent;}',
        '#kt-upd .kt-upd-go{background:linear-gradient(135deg,#0E7C90,#14A1A8);color:#fff;padding:13px 16px;font-size:15.5px;}',
        '#kt-upd .kt-upd-go:active{transform:scale(.98);}',
        '#kt-upd button:focus{outline:none;}#kt-upd button:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(20,161,168,.35);}',
        '#kt-upd .kt-upd-later{background:none;color:#64748B;padding:11px 16px 4px;font-size:14px;font-weight:700;}',
        '@keyframes kt-upd-fade{from{opacity:0}to{opacity:1}}',
        '@keyframes kt-upd-pop{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}',
        '@media (prefers-reduced-motion:reduce){#kt-upd,#kt-upd .kt-upd-card{animation:none}}'
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
      + '<div class="kt-upd-ic" aria-hidden="true">⟳</div>'
      + '<h2 id="kt-upd-h">Update available</h2>'
      + '<p>A newer version of KiddieTrac is ready. Reload to get the latest changes.</p>'
      + '<button type="button" class="kt-upd-go">Reload now</button>'
      // aria-label="Close" tells kt-modal-guard.js this card already has a close control.
      + '<button type="button" class="kt-upd-later" aria-label="Close">Later</button>'
      + '</div>';

    var go = bar.querySelector('.kt-upd-go');
    go.addEventListener('click', function () {
      /* Cache-busted on purpose. A plain reload can be answered from the very
         caches that caused this, which would dismiss the notice and change
         nothing — the exact failure this file exists to end. */
      go.textContent = 'Reloading…'; go.disabled = true;
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
        if (st && st.isActive) { setTimeout(check, 2000); }
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
