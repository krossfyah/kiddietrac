/* KT PICK-UP ONLY (2026-10-13). An emergency contact given a pick-up login
   (App\Support\PickupLogin) sees the parent view MINUS BILLING — Anthony's choice. The
   server refuses every billing request for them (App\Http\Middleware\PickupOnlyNoBilling);
   this takes the Billing links out of the app so they are not offered a page that
   cannot load. /auth/me says who they are: user.billing_hidden. */
(function () {
  'use strict';
  if (window.__ktPickupOnly) return;
  window.__ktPickupOnly = true;

  function hidden() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return !!u.billing_hidden;
    } catch (e) { return false; }
  }
  function apply() {
    var on = hidden();
    document.documentElement.classList.toggle('kt-no-billing', on);
    if (!on) return;
    if (!document.getElementById('kt-no-billing-css')) {
      var s = document.createElement('style');
      s.id = 'kt-no-billing-css';
      s.textContent = 'html.kt-no-billing #kt-b-billing,'
        + 'html.kt-no-billing a[href="#billing"],html.kt-no-billing a[href^="#billing?"],'
        + 'html.kt-no-billing [data-hash="billing"],html.kt-no-billing [data-hash^="billing?"]'
        + '{display:none !important;}';
      (document.head || document.documentElement).appendChild(s);
    }
    // Landed on a billing page (bookmark, old link): back to the home screen.
    var h = (location.hash || '').replace('#', '').split('?')[0];
    if (h === 'billing' || h === 'invoices' || h === 'payments' || h === 'statements') location.replace('#dashboard');
  }
  apply();
  window.addEventListener('hashchange', apply);
  // kt_user is written at sign-in, after this file may have run.
  window.addEventListener('storage', apply);
  setTimeout(apply, 1500); setTimeout(apply, 5000);
})();
