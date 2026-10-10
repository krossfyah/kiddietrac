/* ═══════════════════════════════════════════════════════════════════════
   kt-jurisdiction.js — the agency's province / state, for screens (2026-10-09)

   App\Support\Jurisdictions decides the rules and wording that differ between Ontario,
   Ohio, Alberta and the rest. This loads the active agency's profile ONCE after sign-in
   (GET /admin/jurisdiction) into KT.jurisdiction, so a screen can ask for the
   serious-incident wording, the certification and background-check catalogues, or the
   drill rules without each fetching it. Until it arrives, screens use their old
   (Ontario) wording — which is what every agency was before this change.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var loading = false;

  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; }
  }

  KT.loadJurisdiction = function (force) {
    if ((KT.jurisdiction && !force) || loading || !token() || !KT.Api || !KT.Api.get) return;
    loading = true;
    KT.Api.get('/admin/jurisdiction').then(function (j) {
      KT.jurisdiction = j || null;
      KT._jurChecks = (j && j.background_checks) || null;
      try { window.dispatchEvent(new CustomEvent('kt:jurisdiction', { detail: j })); } catch (e) {}
    }).catch(function () {}).then(function () { loading = false; });
  };

  /* The serious-incident wording: { label, short }. Ontario keeps "(CCEYA)" on its form label. */
  KT.jurSerious = function () {
    var j = KT.jurisdiction;
    var short = (j && j.incident && j.incident.serious_label) || 'Serious occurrence';
    var code = (j && j.jurisdiction) || 'CA-ON';
    return { short: short, label: short + (code === 'CA-ON' ? ' (CCEYA)' : '') };
  };

  setTimeout(function () { KT.loadJurisdiction(); }, 1200);
  setTimeout(function () { KT.loadJurisdiction(); }, 6000);
  // An agency switch changes the answer.
  window.addEventListener('kt:agency-changed', function () { KT.loadJurisdiction(true); });
})(window);
