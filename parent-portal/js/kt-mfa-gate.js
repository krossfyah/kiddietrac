/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — forced MFA gate (2026-08-07)
   agency_admin + centre_director MUST enable two-factor before they can use
   the portal. A full-screen, non-dismissible overlay blocks every screen EXCEPT
   the MFA setup screen (#mfa) until /auth/mfa/status reports enabled.

   • platform_admin (superadmin) is EXEMPT — a support account must never be able
     to lock itself out. Educators / home-visitors / parents are NOT gated; they
     keep the soft banner in kt-mfa-nudge.js (which we suppress here for gated
     users so they don't see both).
   • The gate re-asserts on every hashchange, so navigating away from #mfa before
     enabling re-blocks. A 4s poll lifts the gate the moment MFA is confirmed.
   Loaded on dashboard.html only (never the login page).
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';

  /* 2026-11-10 (PIPEDA review): platform admins and resellers are covered too, after a 14-day grace - the server
     (App\Support\StrongSignIn, via /auth/mfa/status `blocked`) decides; these roles only say whether to ask it. */
  var GATED = ['agency_admin', 'centre_director', 'platform_admin', 'reseller'];

  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function isGatedRole(u) {
    var r = (u && u.roles) || [];
    return GATED.some(function (x) { return r.indexOf(x) !== -1; });
  }

  /* ONBOARDING GOES FIRST, OR NEITHER GATE CAN BE SATISFIED (2026-09-15).

     Two gates were forcing the route at once. This one sends an admin or director to
     #mfa; the onboarding gate sends anyone whose onboarded_at is null to #onboarding.
     For a NEW admin both are true, so pressing "Set up two-factor now" set the hash to
     mfa, onboarding bounced it straight back, and the dialog reappeared unchanged —
     from the outside, a button that does nothing. Both overlays were on screen at the
     same time, this one on top at z-index 2147482000.

     Lloydene King spent an afternoon on it (six sign-ins in twenty minutes, alternating
     between her two accounts) and nothing reached the audit log, because no request was
     ever made — she never got past the dialog.

     Standing down while onboarding is incomplete makes the two gates sequential rather
     than competing: finish onboarding, and the very next navigation hands over to this
     gate. Nothing is relaxed — an admin still cannot use the portal without two-factor,
     they simply reach it in an order that is possible.

     Read from the SERVER's view of the user where we have it. The cached blob is the
     thing that goes stale (see the notes on stale cached roles), and getting this wrong
     in the permissive direction only delays the gate by one screen. */
  function notOnboardedYet(u) {
    if (!u) { return false; }
    return !u.onboarded_at;
  }

  var engaged = false, overlay = null, poll = null, done = false;

  function onMfaScreen() { return String(w.location.hash || '').replace(/^#/, '') === 'mfa'; }

  function buildOverlay() {
    var o = document.createElement('div');
    o.id = 'kt-mfa-gate';
    o.setAttribute('role', 'dialog');
    o.setAttribute('aria-modal', 'true');
    o.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:linear-gradient(135deg,#0d2b3e,#123a52);display:flex;align-items:center;justify-content:center;padding:24px;';
    o.innerHTML =
      '<div style="background:#fff;max-width:460px;width:100%;border-radius:18px;padding:30px 28px;box-shadow:0 30px 70px rgba(0,0,0,.4);text-align:center;font-family:inherit;">'
      + '<div style="font-size:44px;line-height:1;margin-bottom:10px;">🛡️</div>'
      + '<h2 style="margin:0 0 8px;font-size:22px;font-weight:800;color:#0f172a;">Two-factor is required</h2>'
      + '<p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#475569;">For the security of the children and families in your care, accounts that can see children’s and families’ records must use two-factor sign-in. It takes about a minute with an authenticator app or a passkey.</p>'
      + '<button id="kt-mfa-gate-go" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:11px;padding:13px 26px;font-weight:800;font-size:14.5px;cursor:pointer;width:100%;">Set up two-factor now →</button>'
      + '<button id="kt-mfa-gate-out" style="margin-top:12px;background:transparent;border:0;color:#94a3b8;font-size:12.5px;cursor:pointer;">Sign out instead</button>'
      + '</div>';
    o.querySelector('#kt-mfa-gate-go').addEventListener('click', function () {
      if (w.location.hash === '#mfa') sync(); else w.location.hash = 'mfa';   // hashchange → sync hides overlay
    });
    o.querySelector('#kt-mfa-gate-out').addEventListener('click', function () {
      try { if (w.KT && KT.Auth && KT.Auth.logout) { KT.Auth.logout(); return; } } catch (e) {}
      try { sessionStorage.clear(); } catch (e) {}
      w.location.href = '/';
    });
    return o;
  }

  // Show the block on every screen except the MFA setup screen.
  function sync() {
    if (done) return;
    /* Let the onboarding wizard finish first — see notOnboardedYet(). */
    if (notOnboardedYet(user())) {
      if (overlay) { overlay.style.display = 'none'; }
      return;
    }
    if (onMfaScreen()) { if (overlay) overlay.style.display = 'none'; return; }
    if (!overlay) { overlay = buildOverlay(); document.body.appendChild(overlay); }
    overlay.style.display = 'flex';
  }

  function disengage() {
    done = true;
    if (poll) { clearInterval(poll); poll = null; }
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
    w.removeEventListener('hashchange', sync);
  }

  function checkStatus() {
    if (!(w.KT && KT.Api)) return;
    KT.Api.get('/auth/mfa/status').then(function (s) {
      if (s && (s.enabled || s.strong || s.blocked === false)) {
        disengage();
        if (w.KT && KT.Dom && KT.Dom.toast) KT.Dom.toast('✓ Two-factor enabled — thank you', 'success');
      }
    }).catch(function () {});
  }

  function engage() {
    if (engaged) return; engaged = true;
    try { sessionStorage.setItem('kt_mfa_nudge_dismiss', '1'); } catch (e) {}   // suppress the soft banner
    sync();
    w.addEventListener('hashchange', sync);
    poll = setInterval(checkStatus, 4000);
  }

  function boot() {
    if (window.KT_PUBLIC_DEMO) return;   // website demo person: no set-up nudges (2026-10-31)
    var u = user();
    if (!u || !u.id || !isGatedRole(u)) return;
    if (!(w.KT && KT.Api)) { setTimeout(boot, 500); return; }        // wait for the app shell
    KT.Api.get('/auth/mfa/status').then(function (s) {
      if (s && 'blocked' in s) { if (s.blocked) engage(); return; }  // the server's answer (StrongSignIn)
      if (s && s.enabled) return;                                    // already protected → no gate
      if (s && s.demo_exempt) return;                                // demo agency only (MfaController::demoExempt)
      engage();
    }).catch(function () { /* status unreachable → fail open, never trap the user */ });
  }

  // app.js calls this when the API answers 403 {mfa_setup_required} mid-session.
  w.__ktMfaGateShow = function () { engage(); };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})(window);
