/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — WHICH APP IS SIGNING IN (2026-10-30, App\Support\BrandLock).

   Anthony: "make the emulator only use its credentials assigned to its brand ... for live production as well based on
   the white labelled approach". Every sign-in call to the API (/auth/...) carries X-KT-Brand: the white-label tenant
   this app was opened as (?tenant=<slug>, or the tab's kt_tenant), else "kiddietrac". The server lets a person in only
   through the app their agency belongs to. Only /auth/ calls get the header, so ordinary requests gain no preflight.
   Loaded first on the sign-in page and the dashboard, so every later fetch wrapper (kt-net, retries...) wraps this one.
   KT_BRAND() is shared with kt-social-login.js, which puts it on the Google / Microsoft redirect.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.__ktBrandHeader) return;
  w.__ktBrandHeader = true;
  function brand() {
    var s = '';
    try { s = new URLSearchParams(w.location.search).get('tenant') || w.sessionStorage.getItem('kt_tenant') || ''; } catch (e) {}
    s = String(s).toLowerCase();
    return /^[a-z0-9-]{1,80}$/.test(s) ? s : 'kiddietrac';
  }
  w.KT_BRAND = brand;
  var nf = w.fetch;
  if (typeof nf !== 'function') return;
  var AUTH = /^(https:\/\/api\.kiddietrac\.com)?\/api\/v1\/auth\//;
  w.fetch = function (input, init) {
    try {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      if (AUTH.test(url)) {
        if (typeof input === 'string' || (w.URL && input instanceof w.URL)) {
          init = init ? Object.assign({}, init) : {};
          var h = new Headers(init.headers || {});
          h.set('X-KT-Brand', brand());
          init.headers = h;
          return nf.call(this, input, init);
        }
        if (input && typeof input.clone === 'function') {
          var req = new Request(input, init);
          req.headers.set('X-KT-Brand', brand());
          return nf.call(this, req);
        }
      }
    } catch (e) {}
    return nf.apply(this, arguments);
  };
})(window);
