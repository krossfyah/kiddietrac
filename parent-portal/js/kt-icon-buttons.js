/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — icon-ify page-header action buttons for a cleaner look.
   Converts buttons in a page hero's action row (`.kt-hero-actions`) from
   "+ New closure" / "✏️ Edit agency" / "↻ Refresh" into a compact icon button
   with a hover tooltip (reusing the site-wide `.kt-icon-tip[data-kttip]` CSS).

   Deliberately SCOPED to `.kt-hero-actions` only — never touches buttons inside
   forms, modals, cards or tables, so form Save/Cancel/Submit/Delete are untouched.
   Mutates the button in place (keeps its click handlers); only maps clearly
   icon-able actions (add/edit/refresh/upload/…), leaving anything else as text.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.iconButtonsLoaded) return;
  KT.iconButtonsLoaded = true;

  // Safety: even within a hero row, never icon-only these (meaning/safety needs words).
  var SKIP = /\b(save|cancel|send|submit|delete|remove|approve|confirm|discard|sign out|log ?out|pay|checkout)\b/i;

  /* "I'm back" (ending a lunch or break) says the person is back; it is not a go-back control. Turned into ⬅️ on a
     phone, the shell's back handler then took the tap and the break never ended (2026-11-06). Same rule as
     isBackControl in app-v2-shell.js. */
  var SAYS_BACK = /\b(i['’]?m|i am|we['’]?re|we are|be|come|came|welcome|right)\s+back\b/i;
  function iconFor(t) {
    t = t.toLowerCase();
    if (/refresh|reload/.test(t)) return '🔄';
    if (/\bedit\b|customi[sz]e/.test(t)) return '✏️';
    if (/upload/.test(t)) return '📤';
    if (/download/.test(t)) return '⬇️';
    if (/print/.test(t)) return '🖨️';
    if (/\bimport\b/.test(t)) return '⬆️';
    if (/export/.test(t)) return '📊';
    if (/\bsearch\b/.test(t)) return '🔍';
    if (/\bview\b|\bopen\b|\bpreview\b|\bdetails\b/.test(t)) return 'ℹ️';
    if (/\bcopy\b|duplicate/.test(t)) return '📋';
    if (/settings|configure|\bmanage\b/.test(t)) return '⚙️';
    if (/\bback\b/.test(t) && !SAYS_BACK.test(t)) return '⬅️';
    // NOTE: add/new/create/invite are handled by applyAddStyle (a standard white
    // pill that keeps the label), NOT iconified — see the sweep loop.
    return null; // leave as a text button
  }

  // ONE standard look for every add/new action portal-wide: a white bordered pill
  // matching the "Deactivated" button under User management. Keeps the label; uses
  // !important so it overrides each screen's own inline button styling.
  function applyAddStyle(b) {
    if (b.dataset.ktAddStyled) return;
    b.dataset.ktAddStyled = '1';
    var s = b.style;
    s.setProperty('background', '#fff', 'important');
    s.setProperty('color', '#475569', 'important');
    s.setProperty('border', '1px solid #CBD5E1', 'important');
    s.setProperty('border-radius', '8px', 'important');
    s.setProperty('padding', '8px 14px', 'important');
    s.setProperty('font-size', '12px', 'important');
    s.setProperty('font-weight', '600', 'important');
    s.setProperty('box-shadow', 'none', 'important');
    s.setProperty('cursor', 'pointer', 'important');
    s.setProperty('width', 'auto', 'important');
    /* NEVER NARROWER THAN ITS WORDS (2026-11-08, Anthony's phone: "look at the button that says passkey - full sweep").
       min-width:0 let a row squeeze the pill below its label: beside a long description "Add a passkey" was crushed
       to 68px and its words ran out of the box. It keeps its label's width now (the text beside it gives way), and
       only a label wider than the whole row wraps. */
    s.setProperty('min-width', '0', 'important');
    s.setProperty('flex-shrink', '0', 'important');
    s.setProperty('max-width', '100%', 'important');
    s.setProperty('height', 'auto', 'important');
    s.setProperty('background-image', 'none', 'important');
    b.classList.add('kt-add-btn-std');
  }
  function isFilterChoice(b, label) {
    if (/\S\s+\(?\d+\)?$/.test(label)) return true;                         // "Open 2", "Upcoming (2)"
    if (b.getAttribute('role') === 'tab' || b.hasAttribute('aria-pressed') || b.hasAttribute('aria-selected')) return true;
    for (var i = 0; i < b.attributes.length; i++) {
      if (/^data-(v|view|filter|status|scope|mode|seg|tab|[a-z0-9-]*tab|[a-z0-9-]*filter)$/.test(b.attributes[i].name)) return true;
    }
    var p = b.parentElement;
    if (p) {
      var sib = p.children;
      for (var j = 0; j < sib.length; j++) {
        if (sib[j] !== b && sib[j].tagName === 'BUTTON' && /^\s*all\b/i.test(sib[j].textContent || '')) return true;
      }
    }
    return false;
  }
  function isAddLabel(t) { return /^\s*\+/.test(t) || /\b(add|new|create|invite)\b/i.test(t); }
  // A "go back" control (not pagination). data-back / .kt-back always count; else
  // the label must contain the word "back" and NOT be a prev/next pager.
  function isBackLabel(t, b) {
    if (b && ((b.hasAttribute && b.hasAttribute('data-back')) || (b.classList && b.classList.contains('kt-back')))) return true;
    var s = (t || '').toLowerCase();
    if (/\b(prev|next|newer|older|forward)\b/.test(s)) return false;
    if (SAYS_BACK.test(s)) return false;
    return /\bback\b/.test(s) && t.length <= 14;
  }

  function ensureCss() {
    if (document.getElementById('kt-hero-iconbtn-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-hero-iconbtn-css';
    s.textContent =
      '.kt-hero-iconbtn{min-width:0!important;width:38px;height:38px;padding:0!important;' +
      'display:inline-flex;align-items:center;justify-content:center;font-size:17px;line-height:1;gap:0!important;}' +
      /* (2026-10-09) [data-kt-pretty] .kt-btn / .kt-btn-sm set padding 11px 22px !important
         at higher specificity, so a 38px icon button kept text-button padding and clipped
         its emoji (Trip GPS refresh, Document templates import). Same rule, specific enough. */
      '[data-kt-pretty] .kt-btn.kt-hero-iconbtn,[data-kt-pretty] .kt-btn-sm.kt-hero-iconbtn,.kt-btn.kt-hero-iconbtn,.kt-btn-sm.kt-hero-iconbtn' +
      '{padding:0!important;gap:0!important;font-size:17px!important;min-width:38px!important;overflow:visible!important;}' +
      '.kt-add-btn-std{transition:background .12s ease,border-color .12s ease;line-height:1.1;}' +
      '.kt-add-btn-std:hover{background:#F8FAFC!important;border-color:#94A3B8!important;}';
    document.head.appendChild(s);
  }

  function sweep() {
    // Portal-wide: every button in the app content area. Guards below keep it away from
    // forms, modals, dialogs and tab strips (where a text label is essential), and only
    // buttons with a clear icon mapping + short label get converted — everything else is
    // left as text. Click handlers are preserved (we mutate the button in place).
    if (!document.getElementById('appMain')) return;
    var btns = document.querySelectorAll('#appMain button');
    ensureCss();
    /* Asked once, before anything is written: inside the loop window.innerWidth forced a full layout for every
       button after the previous one was restyled - 0.95 s of a phone Children render (PERF 2026-10-28). */
    var WIDE = false;
    try { WIDE = w.matchMedia ? !w.matchMedia('(max-width:768px)').matches : window.innerWidth > 768; } catch (e) {}
    /* PARENTS GET WORDS (2026-11-06, Anthony: "awards under parent view instead of the icon to click to view the award
       add a button - full sweep"). "🏆 View certificate", "📋 View Ava's record" and every other short View / Open /
       Details button became a bare ℹ️ for parents, who do not know the staff icon set. In the parent view (a parent,
       or someone viewing as one) buttons keep their label; only the add-button restyle still applies. */
    var PARENT = !!(document.body && (document.body.classList.contains('kt-parentview') || document.body.classList.contains('role-guardian')));
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      if (b.dataset.ktIconized) continue;
      // Never reach into a form / modal / dialog / tab strip / segmented toggle.
      // .kt-help-wrap: the Help & Guides screen — its "buttons" are content tiles /
      // category pills (e.g. the "Settings" topic), NOT actions. Iconizing them wiped
      // the tile's icon+label, leaving a bare gear with no header.
      if (b.closest('form, .kt-modal, [role="dialog"], .modal, [role="tablist"], .kt-tabs, .kt-admin-tabs, .kt-segmented, .kt-toggle, .kt-help-wrap')) { b.dataset.ktIconized = '1'; continue; }
      var label = (b.textContent || '').replace(/\s+/g, ' ').trim();
      if (!label) { b.dataset.ktIconized = '1'; continue; }
      /* FILTERS ARE NOT ACTIONS (2026-10-23, Anthony: Support tickets showed "Open 2" as a
         clipped ℹ️ pill, so the Open filter could not be seen at all). A button that is one
         choice of a filter or tab group keeps its words: it carries a count ("Open 2"), a
         tab / filter / pressed marker, or sits in a row with an "All" choice. */
      if (isFilterChoice(b, label)) { b.dataset.ktIconized = '1'; continue; }
      // Standard DELETE icon (matches the Closures 🗑): normalise any icon-only
      // trash button to the canonical 32px danger icon. Skip tiny inline deletes
      // inside chat message bubbles (their own micro-layout).
      if ((label === '🗑️' || label === '🗑') && !b.closest('[data-del-mid], .kt-msg, .kt-bubble, .kt-message, .kt-chat-msg')) {
        b.removeAttribute('style');
        b.classList.add('kt-act-icon', 'kt-act-danger');
        if (b.className.indexOf('kt-icon-tip') === -1) b.classList.add('kt-icon-tip');
        if (!b.getAttribute('data-kttip')) b.setAttribute('data-kttip', b.title || b.getAttribute('aria-label') || 'Delete');
        b.dataset.ktIconized = '1';
        continue;
      }
      if (SKIP.test(label)) { b.dataset.ktIconized = '1'; continue; }
      // Back buttons → the standard "‹ Back" white pill (matches add/deactivate).
      // DESKTOP ONLY — mobile back controls (top-bar, thread header, bottom nav)
      // are left exactly as they are so the mobile view is unchanged.
      if (WIDE && isBackLabel(label, b)
          && !b.closest('.kt-back-inbar, #kt-topbar, #kt-chat-dock, .kt-cd-body, .kt-thread-header, .kt-mobilenav')) {
        // Pure restyle — keep the existing label + click behaviour untouched (so we
        // never double-fire the global back handler); just give it the pill look.
        applyAddStyle(b);
        b.dataset.ktIconized = '1';
        continue;
      }
      // A bespoke ➕ / + glyph icon button → adopt its aria-label as the text and
      // join the ONE standard add pill (was a one-off icon that didn't conform).
      if (label === '➕' || label === '+') {
        var al = (b.getAttribute('aria-label') || b.title || '').trim().replace(/^\+\s*/, '');
        if (al) b.textContent = '+ ' + al;
        applyAddStyle(b);
        b.dataset.ktIconized = '1';
        continue;
      }
      // Add / new / create / invite → ONE standard white pill (keeps its label),
      // applied regardless of length so every "add" action matches portal-wide.
      if (isAddLabel(label)) { applyAddStyle(b); b.dataset.ktIconized = '1'; continue; }
      if (PARENT) { b.dataset.ktIconized = '1'; continue; }
      if (label.length > 26) { b.dataset.ktIconized = '1'; continue; } // long = descriptive/primary, keep text
      var icon = iconFor(label);
      if (!icon) { b.dataset.ktIconized = '1'; continue; } // no clean icon → leave as text
      // Tooltip = the label minus any leading symbol/emoji ("+ Add centre" → "Add centre").
      var tip = label.replace(/^[^A-Za-z0-9]+/, '').trim() || label;
      b.textContent = icon;
      b.title = tip;
      b.setAttribute('aria-label', tip);
      b.setAttribute('data-kttip', tip);
      if (b.className.indexOf('kt-icon-tip') === -1) b.className += ' kt-icon-tip';
      // Row actions (inside a table) → the canonical 32px .kt-act-icon so view /
      // edit / delete in a row all match. Hero-row actions keep the 38px pill.
      if (b.closest('td, th, tr, table')) {
        b.removeAttribute('style');
        b.classList.add('kt-act-icon');
        var tone = toneFor(icon);
        if (tone) b.classList.add(tone);
      } else {
        b.classList.add('kt-hero-iconbtn');
      }
      b.dataset.ktIconized = '1';
    }

    // Back LINKS (<a>) too — many "← Back" controls are anchors, which the button
    // loop above never sees (e.g. the home-visit report view). Desktop only, same
    // mobile exclusions; pure restyle so the href / navigation is untouched.
    if (WIDE) {
      var alinks = document.querySelectorAll('#appMain a:not([data-ktbk])');
      for (var k = 0; k < alinks.length; k++) {
        var la = alinks[k];
        la.setAttribute('data-ktbk', '1');
        var ll = (la.textContent || '').replace(/\s+/g, ' ').trim();
        if (isBackLabel(ll, la) && !la.closest('.kt-back-inbar, #kt-topbar, #kt-chat-dock, .kt-cd-body, .kt-thread-header, .kt-mobilenav')) {
          applyAddStyle(la);
        }
      }
    }
  }

  // Tone (glyph colour) for a sweep-converted row-action icon.
  function toneFor(icon) {
    if (icon === 'ℹ️') return 'kt-act-info';
    if (icon === '✏️') return 'kt-act-edit';
    if (icon === '🗑️' || icon === '🗑') return 'kt-act-danger';
    if (icon === '⬇️' || icon === '⬆️' || icon === '📤' || icon === '📊' || icon === '🖨️') return 'kt-act-teal';
    return '';
  }

  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 2500);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 120); });
  setTimeout(sweep, 500);
})(window);
