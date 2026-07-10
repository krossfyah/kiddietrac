/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — admin MFA enrolment nudge (2026-07-08, SOC 2 CC6).
   Phase 1 of MFA enforcement: WARN only, zero lockout risk. If the signed-in
   user holds a privileged role and has NOT enabled two-factor, show a firm,
   persistent banner steering them to the #mfa setup screen. It never blocks
   sign-in — server-side hard enforcement is a later phase, to be flipped only
   once admins have enrolled. Once two-factor is on, the banner disappears.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  var ADMIN_ROLES = ['platform_admin', 'agency_admin', 'centre_director'];
  var needsMfa = false, decided = false;

  function store(k) { try { return sessionStorage.getItem(k) || localStorage.getItem(k); } catch (e) { return null; } }
  function token() { return store('kt_token'); }
  function user() { try { return JSON.parse(store('kt_user') || '{}'); } catch (e) { return {}; } }
  function isAdmin(u) { var r = (u && u.roles) || []; return ADMIN_ROLES.some(function (x) { return r.indexOf(x) !== -1; }); }
  function dismissed() { try { return sessionStorage.getItem('kt_mfa_nudge_dismiss') === '1'; } catch (e) { return false; } }

  function inject() {
    var host = document.getElementById('appMain');
    if (!host || document.getElementById('kt-mfa-nudge') || dismissed()) return;
    var b = document.createElement('div');
    b.id = 'kt-mfa-nudge';
    b.style.cssText = 'display:flex;align-items:center;gap:14px;justify-content:center;flex-wrap:wrap;'
      + 'background:linear-gradient(135deg,#8A2F2A,#BE4038);color:#fff;padding:11px 18px;margin:0 0 14px;'
      + 'border-radius:12px;font:600 13.5px/1.4 system-ui,-apple-system,sans-serif;'
      + 'box-shadow:0 6px 18px -8px rgba(190,64,56,.6);';
    b.innerHTML =
      '<span>🔐 Two-factor authentication is required for administrator accounts.</span>'
      + '<a href="#mfa" id="kt-mfa-go" style="background:#fff;color:#BE4038;font-weight:700;text-decoration:none;padding:7px 15px;border-radius:8px;white-space:nowrap;">Set it up now →</a>'
      + '<button id="kt-mfa-x" title="Remind me later this session" aria-label="Dismiss" style="background:transparent;border:0;color:rgba(255,255,255,.85);font-size:20px;cursor:pointer;line-height:1;padding:0 4px;">×</button>';
    host.insertBefore(b, host.firstChild);
    document.getElementById('kt-mfa-x').onclick = function () {
      try { sessionStorage.setItem('kt_mfa_nudge_dismiss', '1'); } catch (e) {}
      b.remove();
    };
    document.getElementById('kt-mfa-go').onclick = function () { setTimeout(function () { b.remove(); }, 60); };
  }

  function decide() {
    var t = token(), u = user();
    if (!t || !isAdmin(u)) { decided = true; return; }
    fetch(API + '/auth/mfa/status', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { decided = true; needsMfa = !!(d && !d.enabled); if (needsMfa) inject(); else clearNudge(); })
      .catch(function () { decided = true; });
  }

  // Called when MFA gets turned on IN THIS SESSION (screen-mfa dispatches the
  // event on a successful /confirm). Without this the banner kept re-injecting
  // every 1.5s because needsMfa was cached true from the initial page load and
  // never re-evaluated — so it lingered until a full reload even after enabling.
  function clearNudge() {
    needsMfa = false; decided = true;
    var b = document.getElementById('kt-mfa-nudge');
    if (b) b.remove();
    try { clearInterval(iv); } catch (e) {}
  }
  window.addEventListener('kt:mfa-enabled', clearNudge);
  window.KT = window.KT || {}; window.KT.clearMfaNudge = clearNudge;

  // #appMain is built by the shell after load; wait for it, decide once, then
  // keep the banner present across screen changes (each screen re-renders #appMain).
  var waited = 0;
  var iv = setInterval(function () {
    if (!document.getElementById('appMain')) { if ((waited += 1) > 40) clearInterval(iv); return; }
    if (!decided) { decide(); return; }
    if (needsMfa) inject();
  }, 1500);
})();
