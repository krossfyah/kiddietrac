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

  var GATED = ['agency_admin', 'centre_director'];

  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function isGatedRole(u) {
    var r = (u && u.roles) || [];
    if (r.indexOf('platform_admin') !== -1) return false;             // superadmin exempt
    return GATED.some(function (x) { return r.indexOf(x) !== -1; });
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
      + '<p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#475569;">For the security of the children and families in your care, administrators and directors must protect their account with two-factor authentication before continuing. It takes about a minute with any authenticator app.</p>'
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
      if (s && s.enabled) {
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
    var u = user();
    if (!u || !u.id || !isGatedRole(u)) return;
    if (!(w.KT && KT.Api)) { setTimeout(boot, 500); return; }        // wait for the app shell
    KT.Api.get('/auth/mfa/status').then(function (s) {
      if (s && s.enabled) return;                                    // already protected → no gate
      engage();
    }).catch(function () { /* status unreachable → fail open, never trap the user */ });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})(window);
