/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — clock-in status glow (2026-07-27)
   A soft pulsing border around the whole app screen that tells an educator
   their clock status at a glance, from any screen:
     · ORANGE glow  = NOT clocked in  (a nudge to clock in)
     · GREEN  glow  = clocked in
   Reads the shared clock state from KT.clockBar.isClockedIn() (kt-clockbar.js),
   so it flips the moment they clock in/out. Gated to the educator view only —
   parents/admins never see it. Pure overlay: pointer-events:none, so it never
   blocks a tap.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktClockGlow) return; window.__ktClockGlow = true;
  var doc = window.document;
  var KT = window.KT || (window.KT = {});

  // Active view is 'educator' (mirrors the shell's body.role-* class, which is set
  // from primaryRoleOf). We only glow for educators, per the clock-in workflow.
  function isEducatorView() {
    try {
      if (doc.body && doc.body.classList.contains('role-educator')) return true;
      var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
      if (va) return va === 'educator';
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var r = u.roles || [];
      // educator, and not out-ranked by an admin/director role (matches the shell)
      return r.indexOf('educator') > -1 &&
        ['agency_admin', 'platform_admin', 'centre_director'].every(function (x) { return r.indexOf(x) === -1; });
    } catch (e) { return false; }
  }

  function ensure() {
    if (doc.getElementById('kt-clockglow')) return doc.getElementById('kt-clockglow');
    var st = doc.createElement('style'); st.id = 'kt-clockglow-style';
    st.textContent =
      '#kt-clockglow{position:fixed;inset:0;z-index:2147480500;pointer-events:none;display:none;border-radius:2px;}' +
      '#kt-clockglow.kt-cg-on{display:block;}' +
      // ORANGE — not clocked in (a little more insistent).
      '@keyframes kt-cg-out{0%,100%{box-shadow:inset 0 0 0 3px rgba(249,115,22,.95),inset 0 0 15px 2px rgba(249,115,22,.55);}' +
      '50%{box-shadow:inset 0 0 0 4px rgba(249,115,22,1),inset 0 0 34px 7px rgba(249,115,22,.75);}}' +
      // GREEN — clocked in (calmer, slower).
      '@keyframes kt-cg-in{0%,100%{box-shadow:inset 0 0 0 3px rgba(34,197,94,.9),inset 0 0 13px 1px rgba(34,197,94,.4);}' +
      '50%{box-shadow:inset 0 0 0 3px rgba(34,197,94,1),inset 0 0 28px 4px rgba(34,197,94,.6);}}' +
      '#kt-clockglow.kt-cg-out{animation:kt-cg-out 2.3s ease-in-out infinite;}' +
      '#kt-clockglow.kt-cg-in{animation:kt-cg-in 3.4s ease-in-out infinite;}' +
      '@media(prefers-reduced-motion:reduce){#kt-clockglow.kt-cg-out,#kt-clockglow.kt-cg-in{animation:none;}}';
    (doc.head || doc.documentElement).appendChild(st);
    var el = doc.createElement('div');
    el.id = 'kt-clockglow'; el.setAttribute('aria-hidden', 'true');
    doc.body.appendChild(el);
    return el;
  }

  function update() {
    try {
      if (!doc.body) return;
      var el = ensure();
      if (!isEducatorView()) { el.classList.remove('kt-cg-on', 'kt-cg-in', 'kt-cg-out'); return; }
      var clockedIn = !!(KT.clockBar && KT.clockBar.isClockedIn && KT.clockBar.isClockedIn());
      el.classList.add('kt-cg-on');
      el.classList.toggle('kt-cg-in', clockedIn);
      el.classList.toggle('kt-cg-out', !clockedIn);
    } catch (e) {}
  }

  // Poll (the punch state changes rarely, but this also catches role/view changes
  // and the clock bar loading after us) + react to navigation.
  setInterval(update, 3000);
  window.addEventListener('hashchange', function () { setTimeout(update, 800); });
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', function () { setTimeout(update, 1500); });
  else setTimeout(update, 1500);

  KT.clockGlow = { update: update };
})(window);
