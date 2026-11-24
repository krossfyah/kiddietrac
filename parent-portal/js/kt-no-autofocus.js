/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the keyboard opens when YOU tap a box, never on its own (2026-10-23)

   Anthony: "new chat in educator view or any other mobile view pops up start a new
   conversation but pops up the keyboard right away again (full sweep to not allow this
   behaviour throughout)". Dozens of popups call .focus() on their first field when they
   open (Shell.Modal does it for every popup), which on a phone throws the keyboard over half
   the screen before the person has read anything. Editing each one is the fix that does not
   hold, so on a phone or in the app this decides centrally:

     A text box may take focus when
       - the person just tapped it (or its label, or something inside / around it), or
       - the keyboard is already up (moving to the next field with the keyboard's Next, or a
         chat box re-focused right after Send so typing can go on), or
       - it carries data-kt-allow-autofocus.
     Otherwise the focus is simply not given (and a sneaked-in focus is taken back).

   Checkboxes, buttons, selects and the like are not affected; nor is anything on a computer.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktNoAutoFocus) return; w.__ktNoAutoFocus = true;

  function phone() {
    try { var C = w.Capacitor; if (C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)) return true; } catch (e) {}
    return w.innerWidth <= 768;
  }
  var NOT_TYPING = { checkbox: 1, radio: 1, button: 1, submit: 1, reset: 1, range: 1, color: 1, file: 1, hidden: 1, image: 1 };
  function typing(el) {
    if (!el || el.nodeType !== 1) return false;
    if (el.tagName === 'TEXTAREA') return true;
    if (el.tagName === 'INPUT') return !NOT_TYPING[(el.type || 'text').toLowerCase()];
    return el.isContentEditable === true;
  }

  var tap = { el: null, t: 0 }, lastTypingBlur = 0;
  function noteTap(e) { tap = { el: e.target, t: Date.now() }; }
  d.addEventListener('pointerdown', noteTap, true);
  d.addEventListener('touchstart', noteTap, { capture: true, passive: true });
  d.addEventListener('focusout', function (e) { if (typing(e.target)) lastTypingBlur = Date.now(); }, true);

  function tappedIt(el) {
    var t = tap.el;
    if (!t || Date.now() - tap.t > 900) return false;
    if (t === el || el.contains(t) || (t.contains && t.contains(el))) return true;
    var lab = t.closest && t.closest('label');
    if (lab && (lab.control === el || lab.contains(el))) return true;
    return false;
  }
  function allowed(el) {
    if (!phone() || !typing(el)) return true;
    if (d.activeElement === el) return true;
    if (el.hasAttribute('data-kt-allow-autofocus') || (el.closest && el.closest('[data-kt-allow-autofocus]'))) return true;
    if (tappedIt(el)) return true;
    if (typing(d.activeElement) || Date.now() - lastTypingBlur < 1200) return true;   // the keyboard is already up
    return false;
  }

  var focus0 = HTMLElement.prototype.focus;
  HTMLElement.prototype.focus = function () {
    if (!allowed(this)) return;
    return focus0.apply(this, arguments);
  };
  [w.HTMLInputElement, w.HTMLTextAreaElement].forEach(function (C) {
    if (!C || !C.prototype.select) return;
    var sel0 = C.prototype.select;
    C.prototype.select = function () {
      if (!allowed(this)) return;
      return sel0.apply(this, arguments);
    };
  });
  /* The autofocus attribute, and any other route in: taken back at once. The check runs
     before this focus counts as "already focused". */
  d.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!phone() || !typing(el)) return;
    var prev = e.relatedTarget;
    if (tappedIt(el) || typing(prev) || Date.now() - lastTypingBlur < 1200) return;
    if (el.hasAttribute('data-kt-allow-autofocus') || (el.closest && el.closest('[data-kt-allow-autofocus]'))) return;
    try { el.blur(); } catch (er) {}
  }, true);

  w.KT = w.KT || {}; w.KT.noAutoFocus = { allowed: allowed };
})(window, document);
