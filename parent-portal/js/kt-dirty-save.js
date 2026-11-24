/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Save buttons only when there is something to save (2026-10-25)

   Anthony: "the save changes button or save buttons in general should not be clickable if there is nothing to
   save and if there is no changes made - full sweep throughout the portal".

   Every button whose label starts with "Save" is disabled ("No changes to save") until a field in its form, popup,
   card or table row changes, and disabled again if the change is undone. After a save the new values become the
   baseline. Works without screens opting in, like kt-modal-guard.

   LEFT ALONE (never disabled):
     - a Save whose area has no fields at all (Save PDF, a save that reads a canvas or a list);
     - areas with drag-to-reorder, a signature/drawing canvas or contenteditable text - their changes are not fields;
     - anything marked data-kt-always-save (on the button or an ancestor).
   Fields filled in by the screen just after it draws are taken into the baseline (re-read until the person first
   touches the form), so a loaded form is not counted as "changed".
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var doc = window.document;
  if (window.__ktDirtySave) return;
  window.__ktDirtySave = true;

  var LABEL = /^\s*(💾\s*)?save\b/i;
  var AREA = 'form, [role="dialog"], [role="alertdialog"], .modal, .kt-modal, .kt-sheet, .kt-drawer, tr, .kt-card, .lic-card, section, [data-kt-form]';
  var FIELDS = 'input:not([type=button]):not([type=submit]):not([type=reset]):not([type=image]), select, textarea';

  function isSave(b) {
    if (!b || b.tagName !== 'BUTTON' && !(b.tagName === 'INPUT' && /submit|button/.test(b.type))) return false;
    var t = (b.tagName === 'INPUT' ? b.value : b.textContent) || '';
    return LABEL.test(t) || LABEL.test(b.getAttribute('aria-label') || '') || LABEL.test(b.getAttribute('title') || '');
  }

  /* The area a Save belongs to: everything that belongs to that button and no other Save.
     Start from its form/popup/card/row (or the button itself) and widen while the next ancestor holds no other Save
     button, keeping the widest one that has fields. Never past the screen root (#appMain) or the page body.
     WHY WIDEN (2026-10-27): the proposal editor's Save shares a footer row with the Status select; "the nearest
     ancestor with a field" was that row, so every other edit was invisible and Save never came back on. A Save that
     cannot see the fields it saves is worse than one that is always on. */
  function otherSave(n, b) {
    var bs = n.querySelectorAll('button, input[type=submit], input[type=button]');
    for (var i = 0; i < bs.length; i++) { if (bs[i] !== b && isSave(bs[i]) && !bs[i].closest('[data-kt-always-save]')) return true; }
    return false;
  }
  function areaOf(b) {
    var a = b.closest(AREA);
    if (a && !a.querySelector(FIELDS)) a = null;
    var best = a;
    for (var n = (a || b).parentElement; n && n !== doc.body && n.id !== 'appMain' && n !== doc.documentElement; n = n.parentElement) {
      if (otherSave(n, b)) break;
      if (n.querySelector(FIELDS)) best = n;
    }
    if (!best) return null;
    var list = [best];
    if (best.tagName === 'TR') {
      var nx = best.nextElementSibling;
      if (nx && nx.tagName === 'TR' && (nx.hasAttribute('data-biz-row') || (nx.cells[0] && nx.cells[0].colSpan > 1 && !nx.querySelector('button')))) list.push(nx);
    }
    return list;
  }
  function fieldsIn(list) {
    var out = [];
    list.forEach(function (n) { Array.prototype.push.apply(out, n.querySelectorAll(FIELDS)); });
    return out;
  }
  function risky(list) {
    return list.some(function (n) { return n.querySelector('[draggable="true"], canvas, [contenteditable="true"], [contenteditable=""], .kt-sig, .signature-pad'); });
  }
  function optedOut(b) { return !!b.closest('[data-kt-always-save]'); }

  function snapshot(list) {
    return fieldsIn(list).map(function (f) {
      if (f.type === 'checkbox' || f.type === 'radio') return (f.name || f.id || '') + ':' + (f.checked ? 1 : 0);
      if (f.type === 'file') return 'file:' + (f.files ? f.files.length : 0);
      if (f.tagName === 'SELECT' && f.multiple) return Array.prototype.map.call(f.selectedOptions || [], function (o) { return o.value; }).join('|');
      return String(f.value);
    }).join('␟') + '#' + fieldsIn(list).length    // adding or removing a field (a new line) is a change too
      + '#' + toggles(list);
  }
  /* TICKS THAT ARE NOT FIELDS (2026-10-26). A headcount ticks children by tapping cards (buttons that turn "on"), and
     other screens pick chips or tiles the same way. Only typed fields counted, so Save stayed greyed out until someone
     typed a note. The on/off state of the area's own buttons and toggles is part of what gets saved. */
  var TOGGLES = 'button, [role="button"], [role="checkbox"], [role="switch"], [role="option"], [role="radio"], [aria-pressed], [aria-checked], [aria-selected]';
  function toggles(list) {
    var out = [];
    list.forEach(function (n) {
      Array.prototype.forEach.call(n.querySelectorAll(TOGGLES), function (el) {
        if (isSave(el)) return;
        var c = el.className && el.className.baseVal != null ? el.className.baseVal : (el.className || '');
        out.push(String(c) + '|' + (el.getAttribute('aria-pressed') || '') + (el.getAttribute('aria-checked') || '') + (el.getAttribute('aria-selected') || ''));
      });
    });
    return out.join('␟');
  }

  var tracked = [];   // { b, list, base, touched, ours }

  /* FIELDS NO SAVE CAN SEE NEVER LEAVE A SAVE GREY (2026-11-09). Educators could not save the weekly menu all day:
     "💾 Save as template" (menu tools, 11-03) sits beside "💾 Save menu", so the widening above stopped at the
     toolbar and Save menu's area held only the status select - every dish written into the grid's hidden inputs
     was invisible and Save stayed "No changes to save". Anthony: "add guards to not allow this type of thing to ever
     happen". So the tracker can no longer be the reason a save is impossible: a field on the same screen that sits in
     NO tracked Save's area is an orphan, and when an orphan changes, every waiting Save on that screen comes on.
     Greying a Save is a nicety; a Save that cannot be pressed loses work. Orphans are re-read on every edit and on
     every repaint (dialogs write hidden inputs and redraw the cell, with no input event). */
  var ROOTS = '#appMain, [role="dialog"], [role="alertdialog"], .kt-modal, .modal, .kt-sheet, .kt-drawer';
  var orphanBase = new WeakMap();   // field -> { v: value it started with, at: first seen }
  function fieldVal(f) {
    if (f.type === 'checkbox' || f.type === 'radio') return f.checked ? '1' : '0';
    if (f.type === 'file') return 'file:' + (f.files ? f.files.length : 0);
    return String(f.value);
  }
  function rootOf(b) { return b.closest(ROOTS) || doc.body; }
  var orphanMemo = { at: 0, map: null };
  function orphansChanged(t) {
    if (!(t.b.disabled && t.ours) && snapshot(t.list) !== t.base) return false;   // already on for its own fields
    var root = rootOf(t.b), now = Date.now();
    if (now - orphanMemo.at > 150) orphanMemo = { at: now, map: new Map() };
    if (orphanMemo.map.has(root)) return orphanMemo.map.get(root);
    var covered = new Set();
    tracked.forEach(function (o) { if (o.b.isConnected && rootOf(o.b) === root) fieldsIn(o.list).forEach(function (f) { covered.add(f); }); });
    var hit = false;
    Array.prototype.forEach.call(root.querySelectorAll(FIELDS), function (f) {
      if (covered.has(f)) return;
      var o = orphanBase.get(f);
      // Like the areas: a value the screen fills in just after drawing is the starting point, not an edit.
      if (!o) { orphanBase.set(f, { v: fieldVal(f), at: now }); return; }
      if (now - o.at < 3000 && !o.touched) { o.v = fieldVal(f); return; }
      if (fieldVal(f) !== o.v) hit = true;
    });
    orphanMemo.map.set(root, hit);
    return hit;
  }
  function orphansSaved(b) {
    orphanMemo.at = 0;
    Array.prototype.forEach.call(rootOf(b).querySelectorAll(FIELDS), function (f) { orphanBase.set(f, { v: fieldVal(f), at: 0 }); });
  }

  function setState(t) {
    var changed = optedOut(t.b) || snapshot(t.list) !== t.base || orphansChanged(t);   // opted out later = always on
    if (!changed) {
      if (!t.b.disabled) { t.b.disabled = true; t.ours = true; }
      if (!t.b.hasAttribute('data-kt-nochange')) { t.b.setAttribute('data-kt-nochange', '1'); t.b.__ktTitle = t.b.getAttribute('title'); t.b.setAttribute('title', 'No changes to save'); }
    } else {
      if (t.b.disabled && t.ours) { t.b.disabled = false; t.ours = false; }
      if (t.b.hasAttribute('data-kt-nochange')) { t.b.removeAttribute('data-kt-nochange'); if (t.b.__ktTitle != null) t.b.setAttribute('title', t.b.__ktTitle); else t.b.removeAttribute('title'); }
    }
  }

  function track(b) {
    if (b.__ktDirty || optedOut(b)) return;
    var list = areaOf(b);
    if (!list) return;
    if (!fieldsIn(list).length || risky(list)) { b.__ktDirty = 'skip'; return; }
    var t = { b: b, list: list, base: snapshot(list), touched: false, ours: false };
    b.__ktDirty = t;
    tracked.push(t);
    // Values the screen fills in just after drawing belong to the baseline, until the person first touches the area.
    [500, 1400, 3000].forEach(function (ms) { setTimeout(function () { if (!t.touched && b.isConnected) { t.base = snapshot(t.list); setState(t); } }, ms); });
    setState(t);
    b.addEventListener('click', function () {
      if (b.disabled) return;
      // After a save: the new values are the baseline once the save has gone through (the button says Saved, or a
      // success toast appears); a failed save leaves it enabled so it can be tried again.
      var now = snapshot(t.list), start = Date.now();
      (function watch() {
        if (!b.isConnected) return;
        var ok = /saved|✓/i.test(b.textContent || '') || (window.__ktOkToastAt || 0) >= start;
        if (ok) { t.base = now; t.touched = false; orphansSaved(b); setState(t); return; }
        if (Date.now() - start < 5000) setTimeout(watch, 250);
      })();
    }, true);
  }

  // A success toast (green, or a ✓/✅ icon) is the signal that a save went through.
  function wrapToast() {
    var K = window.KT;
    if (!K || typeof K.toast !== 'function' || K.toast.__ktDirtyWrapped) return;
    var orig = K.toast;
    var w = function () {
      try {
        var a = Array.prototype.join.call(arguments, ' ');
        if (/#16A34A|#166534|#047857|#10B981|success|✓|✅|saved/i.test(a)) window.__ktOkToastAt = Date.now();
      } catch (e) {}
      return orig.apply(this, arguments);
    };
    w.__ktDirtyWrapped = true;
    K.toast = w;
  }

  function scan() {
    wrapToast();
    var btns = doc.querySelectorAll('button, input[type=submit], input[type=button]');
    for (var i = 0; i < btns.length; i++) { var b = btns[i]; if (!b.__ktDirty && isSave(b)) { try { track(b); } catch (e) {} } }
    tracked = tracked.filter(function (t) { return t.b.isConnected; });
    // A repaint can be the only sign of an edit (a dialog writes a hidden input and redraws the cell): re-check.
    orphanMemo.at = 0;
    tracked.forEach(function (t) { setState(t); });
  }
  var timer = null;
  function schedule() { if (!timer) timer = setTimeout(function () { timer = null; scan(); }, 250); }

  // Any change inside a tracked area re-checks its Save buttons (typing, picking, ticking, custom widgets that set
  // a hidden field on click).
  function onEdit(e) {
    var tgt = e.target;
    if (tgt && tgt.matches && tgt.matches(FIELDS)) { var ob = orphanBase.get(tgt); if (ob) ob.touched = true; }
    orphanMemo.at = 0;
    setTimeout(function () {
      tracked.forEach(function (t) {
        if (t.list.some(function (n) { return n.contains(tgt); })) {
          // A tap that changed the area (a tick, a chip) is an edit too, so it is never re-read as the starting point.
          if (e.type !== 'click' || snapshot(t.list) !== t.base) t.touched = true;
          setState(t);
        } else if (t.b.disabled && t.ours) {
          setState(t);   // an edit outside every area may be an orphan the Save owns
        }
      });
    }, 0);
  }
  ['input', 'change', 'keyup', 'click'].forEach(function (ev) { doc.addEventListener(ev, onEdit, true); });

  /* READABLE WHILE IT WAITS (2026-11-04, iPhone recording: Settings > Family directory showed a gradient bar with no
     words). A waiting Save is disabled, and the portal fades every disabled button to 55% - but these keep white text
     forced on (inline !important), so on the faded light gradient the words vanished. A waiting Save stays solid and
     turns grey instead: still plainly not ready, still saying what it is.
     LIGHT GREY, DARK WORDS (2026-11-06, Anthony: "some of them are in a dark grey that cannot be read"). Greying the
     button's own gradient left white words on mid grey, and a button whose colour the browser supplied got its 30%
     grey words on dark grey. A waiting Save is now one look everywhere: pale slate with slate words (7:1). */
  function style() {
    if (doc.getElementById('kt-dirty-save-css')) return;
    var st = doc.createElement('style'); st.id = 'kt-dirty-save-css';
    var sel = ['#appMain', '.kt-modal', '.modal', '[role="dialog"]'].map(function (p) { return p + ' button[data-kt-nochange="1"]:disabled'; });
    st.textContent = sel.join(',')
      + '{opacity:1 !important;filter:none !important;background:#E2E8F0 !important;background-image:none !important;color:#334155 !important;'
      + '-webkit-text-fill-color:#334155 !important;border-color:#CBD5E1 !important;box-shadow:none !important;text-shadow:none !important;}'
      + sel.map(function (s) { return s + ' *'; }).join(',') + '{color:inherit !important;-webkit-text-fill-color:inherit !important;}';
    (doc.head || doc.documentElement).appendChild(st);
  }

  function start() {
    style();
    scan();
    try { new MutationObserver(schedule).observe(doc.body, { childList: true, subtree: true }); } catch (e) {}
    if (window.KT && KT.observeMain) { try { KT.observeMain(schedule, { childList: true, subtree: true }); } catch (e) {} }
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();

  window.KT = window.KT || {};
  // For a screen that saves without a visible success signal: KT.dirtySave.saved(button) resets the baseline.
  window.KT.dirtySave = { saved: function (b) { var t = b && b.__ktDirty; if (t && t.list) { t.base = snapshot(t.list); t.touched = false; orphansSaved(b); setState(t); } }, rescan: scan };
})(window);
