/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — EVERY SUB-TAB STRIP LOOKS LIKE THE FAMILY RECORD'S (2026-10-20).

   Anthony: "i like how under families the bar that shows family, guardians, children with
   the gradient, we need to apply this to other sections that uses a subtab like this
   throughout the portal (full sweep here as well)".

   The portal draws sub-tabs a dozen different ways (underlines, pills, bordered buttons),
   each screen painting its own "current" state. Rewriting each is the fix that does not hold,
   so this recognises a tab strip and restyles it in place, on screens and in popups:

     - A STRIP is: role="tablist", data-kt-tabbar, or a row of 2+ buttons that are marked as
       tabs (role="tab", data-tab / data-t / data-pane / data-kt-inpage, or "tab" in their
       class). A plain row of action buttons is never touched.
     - The CURRENT tab: aria-selected / aria-current / an active|on|selected|current class;
       failing that, the one button whose own inline style differs from all the others
       (that is how most screens paint it). Re-read whenever the strip's buttons change, so
       the highlight follows the section you are in.
     - Look: the tinted band; the current tab filled with the navy gradient, white and bold;
       the rest quiet, highlighting on hover.
   The phone's bottom bar, the sidebar and the top bar are excluded; data-kt-noband opts out.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktTabBand) { return; }
  window.__ktTabBand = true;

  var GRAD = 'linear-gradient(135deg,#1B3A63 0%,#2A5580 55%,#4A7FA6 100%)';
  var EXCLUDE = '#kt-mobilenav, #appSidebar, #kt-topbar, .nav-links, [data-kt-noband]';

  function css() {
    if (document.getElementById('kt-tab-band-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-tab-band-css';
    st.textContent = ''
      + '.kt-tabband{background:#EEF3F8 !important;border:1px solid #E2E8F0 !important;border-radius:14px !important;padding:5px !important;gap:4px !important;box-shadow:none !important;}'
      + '.kt-tabband>.kt-tabbtn{border:1px solid transparent !important;border-radius:10px !important;background:transparent !important;color:#475569 !important;'
      + 'box-shadow:none !important;margin:0 !important;text-decoration:none !important;transition:background .15s,color .15s,box-shadow .15s;}'
      + '.kt-tabband>.kt-tabbtn:not(.kt-tab-on):hover{background:#FFFFFF !important;color:#1B3A63 !important;}'
      /* LIGHT, so it reads (2026-10-20: "band highlighting should be a lighter gradient as its
         hard to read"): pale blue gradient, navy text, a thin blue edge. */
      + '.kt-tabband>.kt-tabbtn.kt-tab-on{background:linear-gradient(135deg,#E3EFFA 0%,#CFE2F5 100%) !important;color:#12305A !important;font-weight:800 !important;'
      + 'border-color:#9EC2E3 !important;box-shadow:0 2px 6px -3px rgba(27,58,99,.35) !important;}'
      + '.kt-tabband>.kt-tabbtn.kt-tab-on .kt-tab-ink{color:#12305A !important;}'
      + '.kt-tabband>.kt-tabbtn:focus-visible{outline:2px solid #4A7FA6 !important;outline-offset:2px;}';
    document.head.appendChild(st);
  }

  function isTabButton(b) {
    if (!b || (b.tagName !== 'BUTTON' && b.tagName !== 'A' && b.getAttribute('role') !== 'tab')) { return false; }
    if (b.getAttribute('role') === 'tab') { return true; }
    if (b.hasAttribute('data-tab') || b.hasAttribute('data-t') || b.hasAttribute('data-pane') || b.hasAttribute('data-kt-inpage')) { return true; }
    return /(^|[\s_-])tabs?([\s_-]|$)|tab-?btn|tab-?link|-tab\b|\btab-/i.test((b.className || '').toString());
  }

  function buttonsOf(strip) {
    return [].filter.call(strip.children, function (k) { return k.tagName === 'BUTTON' || k.tagName === 'A' || k.getAttribute('role') === 'tab'; });
  }

  function isStrip(el) {
    if (!el || el.nodeType !== 1 || (el.closest && el.closest(EXCLUDE))) { return false; }
    var btns = buttonsOf(el);
    if (btns.length < 2) { return false; }
    // Tabs only: the row must be (almost) nothing but its buttons.
    if (btns.length < el.children.length - 1) { return false; }
    if (el.getAttribute('role') === 'tablist' || el.hasAttribute('data-kt-tabbar')) { return true; }
    var marked = btns.filter(isTabButton).length;
    if (marked >= 2 && marked >= btns.length - 1) { return true; }
    return looksLikeToggle(el, btns);
  }

  /* An UNMARKED segmented toggle ("Active | Archived", "Active users | Invited | De-boarded"):
     only buttons, 2-12 of them, short labels that are not actions, and exactly one painted
     differently from the rest (the current one). An action row ("Export · Print · Email")
     fails the label test; a row with no single odd one out fails the last test. */
  var ACTION = /^(add|save|export|print|delete|remove|send|email|new|edit|cancel|close|download|upload|invite|create|import|apply|clear|reset|submit|back|next|previous|prev|refresh|sync|copy|view all|show|hide|open|run|generate|book|pay|charge|void|approve|reject|archive|restore)\b/i;
  function looksLikeToggle(el, btns) {
    if (btns.length < 2 || btns.length > 12 || el.children.length !== btns.length) { return false; }
    if (el.closest('.modal-footer, [class*="footer"], [class*="actions"]')) { return false; }
    for (var i = 0; i < btns.length; i++) {
      var txt = (btns[i].textContent || '').replace(/[^\p{L}\p{N}\s&/-]/gu, '').trim();
      if (!txt || txt.length > 28 || ACTION.test(txt)) { return false; }
    }
    return !!currentOf(btns);
  }

  var OURS = /\bkt-tab(btn|-on)\b/g;
  function own(b) {
    // What the screen itself set, without our classes.
    return ((b.className || '').toString().replace(OURS, '').trim()) + '|' + (b.getAttribute('style') || '');
  }

  function currentOf(btns) {
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      if (b.getAttribute('aria-selected') === 'true' || b.getAttribute('aria-current') === 'page' || b.getAttribute('aria-current') === 'true') { return b; }
    }
    for (var j = 0; j < btns.length; j++) {
      if (/(^|\s)(active|is-active|on|selected|current|kt-active)(\s|$)/.test((btns[j].className || '').toString().replace(OURS, ''))) { return btns[j]; }
    }
    // The odd one out: the screen painted the current tab with a different inline style.
    var counts = {};
    btns.forEach(function (b) { var k = own(b); counts[k] = (counts[k] || 0) + 1; });
    var odd = btns.filter(function (b) { return counts[own(b)] === 1; });
    return (odd.length === 1 && btns.length >= 2 && Object.keys(counts).length === 2) ? odd[0] : null;
  }

  function paint(strip) {
    var btns = buttonsOf(strip);
    var cur = currentOf(btns);
    btns.forEach(function (b) {
      if (!b.classList.contains('kt-tabbtn')) { b.classList.add('kt-tabbtn'); }
      var on = b === cur;
      if (b.classList.contains('kt-tab-on') !== on) { b.classList.toggle('kt-tab-on', on); }
      /* White text on the filled tab - but not inside a badge that has its own background
         (a count pill keeps its colours, or its number would vanish white-on-white). */
      [].forEach.call(b.querySelectorAll('*'), function (k) {
        var mine = false;
        if (on) {
          mine = true;
          for (var n = k; n && n !== b; n = n.parentElement) {
            var cs = getComputedStyle(n);
            if ((cs.backgroundImage && cs.backgroundImage !== 'none') || !/rgba\([^)]*,\s*0\)$|transparent/.test(cs.backgroundColor)) { mine = false; break; }
          }
        }
        if (k.classList.contains('kt-tab-ink') !== mine) { k.classList.toggle('kt-tab-ink', mine); }
      });
    });
  }

  function adopt(strip) {
    if (strip.__ktBand) { paint(strip); return; }
    strip.__ktBand = true;
    css();
    strip.classList.add('kt-tabband');
    paint(strip);
    var t = null;
    try {
      new MutationObserver(function () {
        if (t) { return; }
        t = setTimeout(function () { t = null; paint(strip); }, 0);
      }).observe(strip, { attributes: true, subtree: true, childList: true, attributeFilter: ['class', 'style', 'aria-selected', 'aria-current'] });
    } catch (e) {}
    // Click: the screen repaints its own state synchronously; read it right after.
    strip.addEventListener('click', function () { setTimeout(function () { paint(strip); }, 0); }, true);
  }

  function scan() {
    var cands = document.querySelectorAll('[role="tablist"], [data-kt-tabbar], [role="tab"], button[data-tab], button[data-t], button[data-pane], button[data-kt-inpage], [class*="tab"], #appMain button, .modal button');
    var seen = [];
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      var strip = (c.getAttribute('role') === 'tablist' || c.hasAttribute('data-kt-tabbar')) ? c : c.parentElement;
      if (!strip || seen.indexOf(strip) !== -1) { continue; }
      seen.push(strip);
      try { if (isStrip(strip)) { adopt(strip); } } catch (e) {}
    }
  }

  var tm = null;
  function schedule() { if (!tm) { tm = setTimeout(function () { tm = null; scan(); }, 150); } }
  function boot() {
    try { new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
    schedule();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
