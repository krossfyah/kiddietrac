/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — phone numbers format themselves as you type (2026-09-02).

   ONE field. The area code goes in brackets, a hyphen splits the local part:

       4169892621   ->   (416) 989-2621

   Phone numbers were stored however they happened to be typed — 4169892621,
   14169892621, (416) 999-9999, 416-989-2621 — because every form was a plain
   text box. The area code is the part people read back down a phone and the
   part that goes wrong, so it gets brackets, and the number gets its hyphen.

   Two separate boxes were the wrong answer: it doubles the tab stops, it cannot
   hold a number that is not North American, and it does not match how anybody
   writes a phone number down. This is a mask on the field that is already there.

   What it does NOT do:
     • It never touches a value it cannot read as a phone number. "call the
       office" and "ext 4471 ask for Dana" are somebody's note, and masking them
       turns "ext 4471" into "(447) 1".
     • It never rewrites a stored value on load — only what is actually typed —
       so opening a record does not silently mark it edited.
     • It leaves the caret where the typist expects it, counting DIGITS rather
       than characters, so inserting one in the middle does not fling the cursor
       to the end.

   The value lands in the same input every existing save handler already reads,
   so nothing else changes: no wrapper, no hidden field, no migration.

   Scope: #appMain through the shared sweep bus, plus #modalRoot, which sits
   OUTSIDE #appMain and so is invisible to that bus — and dialogs are where most
   of these forms live.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.Phone) return;

  function dig(s) { return String(s == null ? '' : s).replace(/\D+/g, ''); }

  /* The canonical shape: (416) 989-2621. Partial input formats as far as it can,
     so the brackets appear while the area code is still being typed rather than
     snapping into place at the end. */
  function format(v) {
    var d = dig(v);
    if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);   // leading country code
    d = d.slice(0, 10);
    if (!d) return '';
    if (d.length <= 3) return '(' + d;
    if (d.length <= 6) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
    return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
  }

  /* Kept for callers that still think in two parts (the family editor, the
     onboarding wizard), so there is ONE definition of what a phone number looks
     like rather than the three that were here before. */
  function split(v) {
    var d = dig(v);
    if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
    if (d.length >= 10) return { a: d.slice(0, 3), n: d.slice(3, 10) };
    if (d.length > 3) return { a: d.slice(0, 3), n: d.slice(3) };
    return { a: '', n: v || '' };
  }

  function combine(a, n) {
    var ad = dig(a), nd = dig(n);
    /* Returning '' here is what silently WIPED any phone that was not a number.
       Hand the text back: a value this cannot parse is still somebody's note. */
    if (!ad && !nd) return n ? String(n) : '';
    return format(ad + nd);
  }

  /* Anything with a letter in it is a note, not a number — masking "ext 4471"
     produces "(447) 1". Those fields are left exactly as they are. */
  function maskable(v) {
    var s = String(v == null ? '' : v).trim();
    if (!s) return true;
    if (/[A-Za-z]/.test(s)) return false;
    return dig(s).length <= 11;          // longer than a NANP number: leave it alone
  }

  /* ---- caret ---------------------------------------------------------
     Reformatting rewrites the whole value, which by default drops the caret at
     the end — so correcting a digit in the middle throws the typist to the end
     of the field on every keystroke. Counting digits before the caret and
     restoring that same digit offset keeps it where they left it. */
  function digitsBefore(str, pos) { return dig(String(str).slice(0, pos)).length; }

  function posAfterDigits(str, n) {
    if (n <= 0) return 0;
    var seen = 0;
    for (var i = 0; i < str.length; i++) {
      if (/\d/.test(str.charAt(i))) {
        seen++;
        if (seen === n) return i + 1;
      }
    }
    return str.length;
  }

  /* Is this input asking for a phone number? */
  function wants(el) {
    if (!el || el.dataset.ktPhone) return false;          // already wired
    if (el.disabled || el.readOnly) return false;
    if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio') return false;
    // One half of the older two-box controls — those own their own formatting.
    if (el.hasAttribute('data-parea')) return false;   // the wizard's own half-field, if any survives

    var hay = [el.name, el.id, el.placeholder, el.getAttribute('aria-label')].join(' ').toLowerCase();
    if (/search|filter|query|otp|verif/.test(hay)) return false;
    if (/\bphone\b|\bmobile\b|\bcell\b|telephone|\bfax\b/.test(hay)) return true;

    // Most of these forms put the label in the wrapper above the input.
    var lab = el.parentElement && el.parentElement.querySelector('label');
    var t = lab ? (lab.textContent || '').toLowerCase() : '';
    if (/search|filter/.test(t)) return false;
    if (/\bphone\b|\bmobile\b|\bcell\b|telephone|\bfax\b/.test(t)) return true;

    return el.type === 'tel';
  }

  function attach(el) {
    el.dataset.ktPhone = '1';
    if (!el.placeholder) el.placeholder = '(416) 555-0199';
    // Numeric keypad on a phone, without forcing type=tel on someone else's field.
    if (!el.getAttribute('inputmode')) el.setAttribute('inputmode', 'tel');

    /* Backspacing over ") " or "-" would otherwise delete a separator the mask
       immediately puts back, so the key appears to do nothing. Step the caret
       past the separator first and let it delete a real digit. */
    el.addEventListener('keydown', function (e) {
      if (e.key !== 'Backspace') return;
      var pos = el.selectionStart;
      if (pos !== el.selectionEnd || pos === 0) return;     // a selection: normal delete
      var i = pos;
      while (i > 0 && !/\d/.test(el.value.charAt(i - 1))) i--;
      if (i !== pos) { e.preventDefault(); el.setSelectionRange(i, i); }
    });

    function run() {
      if (!maskable(el.value)) return;                      // a note, not a number
      var before = el.value;
      var pos = el.selectionStart;
      var out = format(before);
      if (out === before) return;
      var n = digitsBefore(before, pos == null ? before.length : pos);
      el.value = out;
      if (pos != null) {
        try {
          var p = posAfterDigits(out, n);
          el.setSelectionRange(p, p);
        } catch (e) { /* not all inputs support selection */ }
      }
    }

    el.addEventListener('input', run);
    /* On blur, tidy a value that was pasted or autofilled without an input event
       ever firing. Only then — never on load, which would mark clean records dirty. */
    el.addEventListener('blur', function () {
      if (!maskable(el.value)) return;
      var out = format(el.value);
      if (out !== el.value) {
        el.value = out;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  function sweep() {
    var roots = [document.getElementById('appMain'), document.getElementById('modalRoot')];
    for (var r = 0; r < roots.length; r++) {
      if (!roots[r]) continue;
      var list;
      try { list = roots[r].querySelectorAll('input:not([data-kt-phone])'); } catch (e) { continue; }
      for (var i = 0; i < list.length; i++) {
        try { if (wants(list[i])) attach(list[i]); } catch (e) { /* never break a screen */ }
      }
    }
  }

  KT.Phone = { format: format, split: split, combine: combine, attach: attach, sweep: sweep };

  // #appMain: the shared bus, so this is not another timer.
  if (KT.sweepBus) KT.sweepBus.on(sweep);
  else setInterval(sweep, 4000);

  /* And a navigation hook of its own, as kt-row-actions has. The bus is one
     shared moving part; a field that silently stops formatting is the kind of
     bug nobody reports, so this does not depend on it alone. */
  w.addEventListener('hashchange', function () { setTimeout(sweep, 140); setTimeout(sweep, 600); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sweep(); });

  /* #modalRoot is a SIBLING of #appMain, so the bus never sees it — and dialogs
     are where most phone fields are. One observer, on that one node. */
  (function watchModals() {
    var mr = document.getElementById('modalRoot');
    if (!mr) { setTimeout(watchModals, 300); return; }
    if (!w.MutationObserver) return;
    var pending = false;
    var obs = new MutationObserver(function () {
      if (pending) return;
      pending = true;
      (w.requestAnimationFrame || function (f) { setTimeout(f, 16); })(function () {
        pending = false;
        obs.disconnect();
        try { sweep(); } catch (e) {}
        try { obs.observe(mr, { childList: true, subtree: true }); } catch (e) {}
      });
    });
    try { obs.observe(mr, { childList: true, subtree: true }); } catch (e) {}
  })();
})(window);
