/* ═══════════════════════════════════════════════════════════════════════
   kt-css-guard — put back a stylesheet that failed to arrive (2026-10-13)

   Anthony, with a phone screenshot: "look what is happening in mobile/apk view - this
   sometimes happens". The Everything launcher showed ONE huge tile per row. Reproduced
   exactly by switching off kt-topnav-fix.css: that file is where .kt-tile-grid gets
   display:grid, so without it the tiles stack.

   Why a stylesheet goes missing: the app loads ~200 files at launch through the service
   worker. If a file is not in its cache yet and the network request is cut off (the logs
   show 75 such cut-off APK requests in October, one of them this very file), the browser
   simply renders without it — no error on screen, no retry. Everything that file styles
   looks broken until the next launch, which is why it is "sometimes".

   So, once the page has loaded: every same-origin stylesheet that has no rules (failed,
   or arrived empty) is fetched again under a fresh URL and put back in the same place.
   One retry per sheet per page, so a file that is truly gone cannot loop. What was healed
   is kept in sessionStorage.kt_css_healed for diagnosis.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var done = {};

  /* 'yes' | 'no' | 'empty'. Only our own /css/ files are looked at (same origin), so a
     sheet whose rules cannot be read is an error page, not a cross-origin font sheet. */
  function state(link) {
    try {
      var s = link.sheet;
      if (!s) return 'yes';                      // failed to load
      return (!s.cssRules || s.cssRules.length === 0) ? 'empty' : 'no';
    } catch (e) {
      return 'yes';                              // an error response the browser refused
    }
  }

  function heal() {
    var links = document.querySelectorAll('link[rel="stylesheet"][href]');
    for (var i = 0; i < links.length; i++) {
      var l = links[i];
      var href = l.getAttribute('href') || '';
      if (!/^\/(css|js)\//.test(href) || done[href] || l.disabled || l.media === 'print') continue;
      var st = state(l);
      if (st === 'no') continue;
      if (st === 'empty') {
        /* Some files are only comments (kt-mobile-brand-fix.css), so no rules can be
           right. Only reload when the file itself actually has some. */
        done[href] = true;
        (function (link, url) {
          fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (t) { if (/\{/.test(String(t).replace(/\/\*[\s\S]*?\*\//g, ''))) reload(link, url); })
            .catch(function () {});
        })(l, href);
        continue;
      }
      done[href] = true;
      reload(l, href);
    }
  }

  function reload(l, href) {
    if (!l.parentNode) return;
    var fresh = l.cloneNode();
    fresh.href = href + (href.indexOf('?') === -1 ? '?' : '&') + 'kt_heal=' + Date.now();
    fresh.setAttribute('data-kt-healed', href);
    l.parentNode.insertBefore(fresh, l.nextSibling);   // same place, so the cascade order is unchanged
    fresh.addEventListener('load', function () { try { l.remove(); } catch (e) {} });
    try {
      var log = JSON.parse(sessionStorage.getItem('kt_css_healed') || '[]');
      log.push({ href: href, at: new Date().toISOString() });
      sessionStorage.setItem('kt_css_healed', JSON.stringify(log.slice(-20)));
    } catch (e) {}
    try { console.warn('[kt-css-guard] stylesheet missing, reloaded:', href); } catch (e) {}
  }

  if (document.readyState === 'complete') { heal(); }
  else { window.addEventListener('load', heal); }
  // A second look shortly after, for a sheet still settling when load fired.
  window.addEventListener('load', function () { setTimeout(heal, 1500); });
})(window, document);
