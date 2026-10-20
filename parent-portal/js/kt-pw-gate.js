/* ═══════════════════════════════════════════════════════════════════════
   kt-pw-gate.js: "choose a new password" reaches every screen (2026-10-20)

   EnsurePasswordChanged answers 403 {password_change_required:true} to every API call
   except auth/* while an admin reset (or the 90-day limit) is pending. app.js's Api
   already turns that into a toast and a trip to Settings > Security, but many screens
   call fetch() through their own small helper and never pass through Api. In the Closed
   Testing Report (issues 5 and 6) such a tester saw raw JSON in Message Centre, "No children
   loaded" in Daily log and "No children found in your rooms" on New award: every request
   was being refused, and nothing said why.

   So the check sits on fetch itself: any API reply of 403 is peeked at (a clone, so the
   caller still reads its own body), and password_change_required sends the person to the
   change-password screen once, exactly as Api does. Loads before app.js; same flag
   (kt_force_password_change) and the same once-only guard (window.__ktPwRedirect).
   ═══════════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.__ktPwGate || !w.fetch) return;
  w.__ktPwGate = true;

  function isApi(url) {
    return /\/api\/v1\//.test(String(url || '')) && !/\/api\/v1\/auth\//.test(String(url || ''));
  }

  function go(msg) {
    try { w.sessionStorage.setItem('kt_force_password_change', '1'); } catch (e) {}
    if (w.__ktPwRedirect) return;
    w.__ktPwRedirect = true;
    try {
      if (w.KT && w.KT.toast) {
        w.KT.toast('\u{1F511}', 'Choose a new password',
          msg || 'Your password needs changing before you can continue.', '#B45309');
      }
    } catch (e) {}
    setTimeout(function () {
      if (!/#settings/.test(w.location.hash)) w.location.hash = 'settings?tab=security';
    }, 300);
  }

  var real = w.fetch;
  w.fetch = function (input, init) {
    var p = real.apply(this, arguments);
    try {
      var url = (input && input.url) || input;
      if (!isApi(url)) return p;
      p.then(function (res) {
        if (!res || res.status !== 403) return;
        res.clone().json().then(function (j) {
          if (j && j.password_change_required) go(j.message);
        }).catch(function () {});
      }, function () {});
    } catch (e) {}
    return p;
  };
}(window));
