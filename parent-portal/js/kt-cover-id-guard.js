/* ═══════════════════════════════════════════════════════════════════
   kt-cover-id-guard.js — a lookup never lands in the picture of the old screen (2026-11-10).

   Anthony's video (WhatsApp 2026-11-10 8.42 PM, Agency overview on a desk): every 45
   seconds the page jumped - the second widget row (calendar, Team on the floor,
   birthdays, Security alerts, Recent activity) vanished, "Your providers" rose into its
   place, then the row came back as "Loading…" shells and filled in.

   Cause. A background refresh (kt-auto-refresh every 45s, kt-live after a write) holds a
   COVER over the screen while it rebuilds: a cloneNode(true) of the old #appMain,
   appended to <body> (app-v2-shell __ktSnapshot; the nav cover at __ktNavCover is the
   same). A deep clone keeps every id inside it. So while the new screen is being built,
   the old screen's #kt-dash-extra, #kt-w-presence … exist ONCE in the document - inside
   the cover - and the new screen's do not exist yet. screen-dashboard-widgets.js asked
   `document.getElementById('kt-dash-extra')`, got the cover's copy, decided the row was
   already there and did nothing. The cover came off, the row was missing, and it was
   only added on the next unrelated mutation - the jump. kt-clockbar.js had already been
   bitten by the same thing and worked round it by hand (its closest() filter).

   The guard, for every module at once: document.getElementById / document.querySelector
   never return a node inside a cover. If the only match is in the cover, the answer is
   the live node, or null - exactly what the code would have seen with no cover up.
   Asking for the cover itself (a selector naming data-kt-refresh-snap / data-kt-nav-cover)
   is answered as before, so the shell can still find and remove it. Element-level
   lookups and querySelectorAll are untouched.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktCoverIdGuard) { return; }
  window.__ktCoverIdGuard = true;

  var COVER = '[data-kt-refresh-snap],[data-kt-nav-cover]';
  var D = Document.prototype;
  var nativeGet = D.getElementById;
  var nativeQS = D.querySelector;
  var nativeQSA = D.querySelectorAll;

  function inCover(el) {
    try { return !!(el && el.closest && el.closest(COVER)); } catch (e) { return false; }
  }
  function firstLive(doc, sel) {
    var all = nativeQSA.call(doc, sel);
    for (var i = 0; i < all.length; i++) { if (!inCover(all[i])) { return all[i]; } }
    return null;
  }

  D.getElementById = function (id) {
    var el = nativeGet.call(this, id);
    if (!el || !inCover(el)) { return el; }
    try {
      var esc = (window.CSS && CSS.escape) ? CSS.escape(String(id)) : String(id).replace(/["\\]/g, '\\$&');
      return firstLive(this, '[id="' + esc + '"]');
    } catch (e) { return null; }
  };

  D.querySelector = function (sel) {
    var el = nativeQS.call(this, sel);
    if (!el || !inCover(el)) { return el; }
    // Code that is looking for the cover itself gets it.
    if (/data-kt-refresh-snap|data-kt-nav-cover/.test(String(sel))) { return el; }
    try { return firstLive(this, sel); } catch (e) { return null; }
  };
})();
