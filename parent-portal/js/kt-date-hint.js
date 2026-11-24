/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — show a format hint in EMPTY date/time fields on phone browsers (2026-10-21).

   Anthony: "when logging in to mobile and not apk through browser some of the fields are
   not presented like it does in the apk". Measured side by side on an Android 15 emulator
   (the 3.12 APK vs Chrome on the same device, same account, same screens): every control
   was present in both, but mobile Chrome draws an EMPTY <input type=date|time> as a blank
   box with only a ▾ — no "mm/dd/yyyy", no "--:-- --" — while the app's WebView shows the
   format. So date of birth, absence dates, time-off dates, pick-up times and form dates
   looked like missing fields in the browser.

   This adds the same hint, as a ::before label, ONLY where it is missing:
     · touch-first devices (pointer: coarse) — desktop browsers already draw the format;
     · not inside the app (html.kt-app) — the WebView already draws it.
   The hint disappears as soon as the field has a value, including values set by code
   (the value setter is wrapped) or picked from kt-date-sheet's bottom sheet.
   A field may opt out with data-kt-no-hint.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktDateHint) return; w.__ktDateHint = true;
  try { if (!(w.matchMedia && w.matchMedia('(pointer: coarse)').matches)) return; } catch (e) { return; }

  var HINT = { date: 'mm/dd/yyyy', time: '--:-- --', 'datetime-local': 'mm/dd/yyyy, --:-- --', month: 'Month, yyyy', week: 'Week, yyyy' };

  function inApp() { return d.documentElement.classList.contains('kt-app') || !!w.__KT_NATIVE; }

  function sync(el) {
    if (!el || el.tagName !== 'INPUT' || !HINT[el.type] || el.hasAttribute('data-kt-no-hint')) return;
    if (!el.hasAttribute('data-kt-hint')) {
      el.setAttribute('data-kt-hint', el.getAttribute('placeholder') || HINT[el.type]);
    }
    if (el.value) el.removeAttribute('data-kt-empty');
    else el.setAttribute('data-kt-empty', '');
  }

  function scan(root) {
    try { (root || d).querySelectorAll('input[type=date],input[type=time],input[type=datetime-local],input[type=month],input[type=week]').forEach(sync); } catch (e) {}
  }

  // The look. Scoped to html:not(.kt-app), so even if the class arrives late the app never
  // shows the hint twice.
  var css = 'html:not(.kt-app) input[data-kt-empty]::before{content:attr(data-kt-hint);color:#94A3B8;'
    + 'font-weight:400;pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + 'html:not(.kt-app) input[data-kt-empty]:focus::before{content:""}';
  function addStyle() {
    if (d.getElementById('kt-date-hint-css')) return;
    var s = d.createElement('style'); s.id = 'kt-date-hint-css'; s.textContent = css;
    (d.head || d.documentElement).appendChild(s);
  }

  // Values set by code (prefilled forms, the bottom-sheet picker) must drop the hint at once.
  try {
    var desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    if (desc && desc.set && desc.configurable) {
      Object.defineProperty(HTMLInputElement.prototype, 'value', {
        configurable: true, enumerable: desc.enumerable,
        get: function () { return desc.get.call(this); },
        set: function (v) { desc.set.call(this, v); if (HINT[this.type]) { try { sync(this); } catch (e) {} } },
      });
    }
  } catch (e) {}

  function start() {
    if (inApp()) return;
    addStyle();
    scan();
    ['input', 'change', 'focusout', 'focusin'].forEach(function (t) {
      d.addEventListener(t, function (e) { sync(e.target); }, true);
    });
    // New fields arrive with every screen and dialog. Overlays are appended to <body> and
    // screens rebuild #appMain, so watch the body subtree, throttled.
    var queued = false;
    try {
      new MutationObserver(function () {
        if (queued) return; queued = true;
        setTimeout(function () { queued = false; scan(); }, 150);
      }).observe(d.body || d.documentElement, { childList: true, subtree: true });
    } catch (e) {}
    // Belt and braces for anything set without going through .value (e.g. valueAsDate).
    setInterval(scan, 2000);
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
})(window, document);
