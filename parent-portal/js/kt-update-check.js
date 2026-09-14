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

    var bar = d.createElement('div');
    bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:22px;'
      + 'z-index:2147483600;display:flex;align-items:center;gap:12px;'
      + 'background:#0F172A;color:#fff;border-radius:999px;padding:11px 14px 11px 18px;'
      + 'box-shadow:0 10px 30px rgba(15,23,42,.35);font:600 13.5px/1.2 -apple-system,'
      + 'BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;max-width:92vw;';

    var msg = d.createElement('span');
    msg.textContent = 'A newer version of KiddieTrac is ready.';
    bar.appendChild(msg);

    var go = d.createElement('button');
    go.type = 'button';
    go.textContent = 'Reload';
    go.style.cssText = 'appearance:none;border:0;border-radius:999px;background:#159FB4;'
      + 'color:#fff;font:inherit;font-weight:800;padding:7px 15px;cursor:pointer;';
    go.addEventListener('click', function () {
      /* Cache-busted on purpose. A plain reload can be answered from the very
         caches that caused this, which would dismiss the notice and change
         nothing — the exact failure this file exists to end. */
      try {
        var u = new URL(w.location.href);
        u.searchParams.set('_b', String(Date.now()));
        w.location.replace(u.toString());
      } catch (e) { w.location.reload(); }
    });
    bar.appendChild(go);

    var later = d.createElement('button');
    later.type = 'button';
    later.textContent = 'Later';
    later.setAttribute('aria-label', 'Dismiss');
    later.style.cssText = 'appearance:none;border:0;background:none;color:#94A3B8;'
      + 'font:inherit;font-weight:700;padding:7px 6px;cursor:pointer;';
    later.addEventListener('click', function () { bar.remove(); });
    bar.appendChild(later);

    (d.body || d.documentElement).appendChild(bar);

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
