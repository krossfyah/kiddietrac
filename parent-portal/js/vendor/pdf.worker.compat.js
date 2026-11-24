/* pdf.js worker with the compatibility shims loaded first (tickets #175/#176): older
   Android WebViews lack .at() / structuredClone, which pdf.worker.min.js (3.11.174) uses.

   Two ways this file runs:
   - as the real worker (normal): importScripts both. Started cross-origin, pdf.js wraps it in
     a blob: worker whose origin is the PAGE's, so each base is tried in turn: the page's
     origin, then the app host (the no-login sign page lives on the API host, 2026-10-25);
   - as pdf.js's "fake worker" fallback, loaded as a <script> on the page when a worker
     can't start: there is no importScripts there, so load both synchronously (same-origin
     first, so no CORS is needed) and window.pdfjsWorker exists by this script's onload. */
(function (g) {
  var APP = 'https://app.kiddietrac.com';
  var origin = (g.location && g.location.origin && g.location.origin !== 'null') ? g.location.origin : '';
  var mine = '';
  try { var cs = g.document && g.document.currentScript; if (cs && cs.src) mine = new URL(cs.src).origin; } catch (e) {}
  var bases = [];
  [origin, mine, APP].forEach(function (b) { if (b && bases.indexOf(b) < 0) bases.push(b); });
  function files(b) { return [b + '/js/vendor/kt-compat.js?v=20261022b', b + '/js/vendor/pdf.worker.min.js']; }
  var i, last;
  if (typeof g.importScripts === 'function') {
    for (i = 0; i < bases.length; i++) {
      try { g.importScripts.apply(g, files(bases[i])); return; } catch (e) { last = e; }
    }
    throw last;
  }
  for (i = 0; i < bases.length; i++) {
    var got = files(bases[i]).map(function (u) {
      try { var x = new XMLHttpRequest(); x.open('GET', u, false); x.send(null); return (x.status >= 200 && x.status < 300) ? x.responseText : null; }
      catch (e) { return null; }
    });
    if (got[0] !== null && got[1] !== null) { got.forEach(function (t) { (0, eval)(t); }); return; }
  }
})(typeof self !== 'undefined' ? self : this);
