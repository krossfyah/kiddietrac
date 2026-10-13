/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — phone numbers: AREA CODE + NUMBER (2026-10-13).

   Anthony: "adding telephone phone number with adding a family needs to add area code
   and phone number but auto fill area code and move to phone number for Canada and do
   the same for the USA - full sweep for the same behaviour through the portal". He chose
   two boxes:

       ┌─────┐ ┌──────────┐
       │ 416 │ │ 555-0199 │      3 digits in the area code → the cursor jumps right
       └─────┘ └──────────┘      Backspace in an empty number → back to the area code
                                 Paste a whole number into either → it splits itself

   Canada and the USA share the North American plan (+1, 3-digit area code, 7-digit
   number), so one control serves both; a leading 1 on a pasted number is dropped.

   HOW, WITHOUT TOUCHING ANY FORM. The phone <input> every form already reads stays where
   it is, hidden, and keeps holding the value in the same canonical shape as before —
   "(416) 555-0199" — so every save handler, FormData and `obj[key] = input.value` keeps
   working. Two visible boxes write into it and fire input/change on it. A value set on it
   from code (record loaded into a form) redraws the boxes.

   This file was written on 2026-09-02 as a one-box mask and committed, but it was NEVER
   added to dashboard.html — so no phone field in the portal ever formatted at all. It is
   loaded now (dashboard + the public pages that ask for a phone).

   Left alone, exactly as before:
     • a field whose value is a note ("call the office", "ext 4471"): letters → one box
     • a number longer than North American: stays one box, untouched
     • search / filter / verification-code fields
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.Phone && KT.Phone.v2) return;

  function dig(s) { return String(s == null ? '' : s).replace(/\D+/g, ''); }

  /* The canonical stored shape: (416) 989-2621. Partial input formats as far as it can. */
  function format(v) {
    var d = dig(v);
    if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);   // leading country code
    d = d.slice(0, 10);
    if (!d) return '';
    if (d.length <= 3) return '(' + d;
    if (d.length <= 6) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
    return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
  }
  /* The local part as shown in the number box: 555-0199. */
  function local(v) {
    var d = dig(v).slice(0, 7);
    return d.length <= 3 ? d : d.slice(0, 3) + '-' + d.slice(3);
  }
  function split(v) {
    var d = dig(v);
    if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
    if (d.length >= 10) return { a: d.slice(0, 3), n: d.slice(3, 10) };
    if (d.length > 3) return { a: d.slice(0, 3), n: d.slice(3) };
    return { a: '', n: v || '' };
  }
  function combine(a, n) {
    var ad = dig(a), nd = dig(n);
    if (!ad && !nd) return n ? String(n) : '';
    return format(ad + nd);
  }

  /* A note, not a number: anything with a letter, or more digits than +1 NNN NNN NNNN. */
  function maskable(v) {
    var s = String(v == null ? '' : v).trim();
    if (!s) return true;
    if (/[A-Za-z]/.test(s)) return false;
    return dig(s).length <= 11;
  }

  /* Is this input asking for a phone number? */
  function wants(el) {
    if (!el || el.dataset.ktPhone || el.dataset.ktPhonePart) return false;
    if (el.disabled || el.readOnly) return false;
    if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio') return false;
    if (el.hasAttribute('data-parea')) return false;
    // A form that already has its own area-code box beside this one (onboarding).
    if (el.parentElement && el.parentElement.querySelector('[data-parea]')) return false;
    var hay = [el.name, el.id, el.placeholder, el.getAttribute('aria-label')].join(' ').toLowerCase();
    if (/search|filter|query|otp|verif|code\b/.test(hay)) return false;
    if (/\bphone\b|\bmobile\b|\bcell\b|telephone|\bfax\b/.test(hay)) return true;
    var lab = el.parentElement && el.parentElement.querySelector('label');
    var t = lab ? (lab.textContent || '').toLowerCase() : '';
    if (/search|filter/.test(t)) return false;
    if (/\bphone\b|\bmobile\b|\bcell\b|telephone|\bfax\b/.test(t)) return true;
    return el.type === 'tel';
  }

  var nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

  function css() {
    if (document.getElementById('kt-phone-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-phone-css';
    s.textContent = '.kt-phone2{display:flex;gap:8px;align-items:stretch;width:100%;min-width:0;box-sizing:border-box;}'
      + '.kt-phone2 > input{box-sizing:border-box;min-width:0;}'
      + '.kt-phone2 > input.kt-pa{flex:0 0 76px !important;width:76px !important;max-width:76px !important;text-align:center;letter-spacing:.04em;}'
      + '.kt-phone2 > input.kt-pn{flex:1 1 auto !important;width:auto !important;}';
    (document.head || document.documentElement).appendChild(s);
  }

  function attach(el) {
    if (!el || el.dataset.ktPhone) return;
    el.dataset.ktPhone = '1';
    if (!maskable(nativeValue.get.call(el))) { el.dataset.ktPhone = 'note'; return; }   // somebody's note: leave it
    var tries = 0;
    (function build() {
      // A form often wires the input before it is in the page; wait until it is.
      if (!el.parentNode || !el.isConnected) { if (tries++ < 80) setTimeout(build, 50); return; }
      split2(el);
    })();
  }

  function split2(el) {
    css();
    var wrap = document.createElement('div');
    wrap.className = 'kt-phone2';
    var area = document.createElement('input');
    var num = document.createElement('input');
    [area, num].forEach(function (b) {
      b.type = 'tel';
      b.setAttribute('inputmode', 'numeric');
      b.dataset.ktPhonePart = '1';
      b.style.cssText = el.style.cssText;
      if (el.className) b.className = el.className;
      b.disabled = el.disabled;
    });
    area.classList.add('kt-pa'); num.classList.add('kt-pn');
    area.placeholder = '416'; area.maxLength = 3; area.setAttribute('aria-label', 'Area code'); area.setAttribute('autocomplete', 'tel-area-code');
    num.placeholder = '555-0199'; num.maxLength = 8; num.setAttribute('aria-label', 'Phone number'); num.setAttribute('autocomplete', 'tel-local');
    area.title = 'Area code'; num.title = 'Phone number';
    if (el.required) { el.required = false; area.required = true; num.required = true; }

    el.parentNode.insertBefore(wrap, el);
    wrap.appendChild(area); wrap.appendChild(num);
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
    el.tabIndex = -1;

    var syncing = false;
    function paint(v) {
      var p = split(v);
      area.value = dig(p.a).slice(0, 3);
      num.value = local(p.n);
    }
    function push() {
      var v = combine(area.value, num.value);
      syncing = true;
      try { nativeValue.set.call(el, v); } finally { syncing = false; }
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // A whole number typed or pasted into either box splits across both.
    function spread(d) {
      if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
      area.value = d.slice(0, 3); num.value = local(d.slice(3, 10));
      push();
      if (d.length >= 3) { num.focus(); try { num.setSelectionRange(num.value.length, num.value.length); } catch (e) {} }
    }

    area.addEventListener('input', function () {
      var d = dig(area.value);
      if (d.length > 3) { spread(d); return; }
      if (area.value !== d) area.value = d;
      push();
      if (d.length === 3 && area.selectionStart === 3) {
        num.focus(); try { num.setSelectionRange(num.value.length, num.value.length); } catch (e) {}
      }
    });
    area.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight' && area.selectionStart === area.value.length) { e.preventDefault(); num.focus(); try { num.setSelectionRange(0, 0); } catch (x) {} }
    });
    area.addEventListener('paste', function (e) {
      var t = (e.clipboardData || w.clipboardData) ? (e.clipboardData || w.clipboardData).getData('text') : '';
      var d = dig(t);
      if (d.length > 3) { e.preventDefault(); spread(d); }
    });

    num.addEventListener('input', function () {
      var d = dig(num.value);
      if (d.length >= 10) { spread(d); return; }
      // Caret by digit count, so a correction in the middle stays where it was typed.
      var pos = num.selectionStart, before = dig(num.value.slice(0, pos == null ? num.value.length : pos)).length;
      var out = local(d);
      if (num.value !== out) {
        num.value = out;
        var p = out.length, seen = 0;
        for (var i = 0; i < out.length; i++) { if (/\d/.test(out.charAt(i)) && ++seen === before) { p = i + 1; break; } }
        if (before === 0) p = 0;
        try { num.setSelectionRange(p, p); } catch (e) {}
      }
      push();
    });
    num.addEventListener('keydown', function (e) {
      if ((e.key === 'Backspace' || e.key === 'ArrowLeft') && num.selectionStart === 0 && num.selectionEnd === 0) {
        e.preventDefault(); area.focus(); try { area.setSelectionRange(area.value.length, area.value.length); } catch (x) {}
        if (e.key === 'Backspace' && area.value) { area.value = area.value.slice(0, -1); push(); }
      }
    });
    num.addEventListener('paste', function (e) {
      var t = (e.clipboardData || w.clipboardData) ? (e.clipboardData || w.clipboardData).getData('text') : '';
      var d = dig(t);
      if (d.length >= 10) { e.preventDefault(); spread(d); }
    });

    // A value put on the original from code (a record loaded into the form) redraws the boxes.
    try {
      Object.defineProperty(el, 'value', {
        configurable: true,
        get: function () { return nativeValue.get.call(this); },
        set: function (v) { nativeValue.set.call(this, v); if (!syncing) paint(v); },
      });
    } catch (e) {}
    el.focus = function () { area.focus(); };
    // Disabled / read-only toggled later follow the original.
    if (w.MutationObserver) {
      new MutationObserver(function () { area.disabled = num.disabled = el.disabled; area.readOnly = num.readOnly = el.readOnly; })
        .observe(el, { attributes: true, attributeFilter: ['disabled', 'readonly'] });
    }

    paint(nativeValue.get.call(el));
  }

  /* The forms that already have their own area-code box (data-parea, the onboarding
     wizard) get the same jump: 3 digits → the number beside it, Backspace back. */
  function wireAreaPair(a) {
    if (a.dataset.ktPhoneJump) return;
    a.dataset.ktPhoneJump = '1';
    var n = null, inputs = a.parentElement ? a.parentElement.querySelectorAll('input') : [];
    for (var i = 0; i < inputs.length; i++) { if (inputs[i] !== a && !inputs[i].hasAttribute('data-parea')) { n = inputs[i]; break; } }
    if (!n) return;
    a.setAttribute('inputmode', 'numeric');
    a.addEventListener('input', function () {
      var d = dig(a.value);
      if (d.length > 3) {
        if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
        a.value = d.slice(0, 3); n.value = local(d.slice(3, 10));
        n.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (dig(a.value).length >= 3) { n.focus(); }
    });
    n.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && n.selectionStart === 0 && n.selectionEnd === 0) { e.preventDefault(); a.focus(); }
    });
  }

  function sweep() {
    var roots = [document.getElementById('appMain'), document.getElementById('modalRoot')];
    // The public pages (enrolment, sign-up, RSVP) have neither: sweep the whole page there.
    if (!roots[0] && !roots[1]) roots = [document.body];
    for (var r = 0; r < roots.length; r++) {
      if (!roots[r]) continue;
      var list;
      try { list = roots[r].querySelectorAll('input:not([data-kt-phone]):not([data-kt-phone-part])'); } catch (e) { continue; }
      for (var i = 0; i < list.length; i++) {
        try {
          if (list[i].hasAttribute('data-parea')) wireAreaPair(list[i]);
          else if (wants(list[i])) attach(list[i]);
        } catch (e) { /* never break a screen */ }
      }
    }
  }

  KT.Phone = { v2: true, format: format, local: local, split: split, combine: combine, attach: attach, sweep: sweep };

  if (KT.sweepBus) KT.sweepBus.on(sweep);
  else setInterval(sweep, 4000);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 140); setTimeout(sweep, 600); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sweep(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(sweep, 50); });
  else setTimeout(sweep, 50);

  /* #modalRoot is a SIBLING of #appMain, so the bus never sees it — and dialogs are where
     most phone fields are. One observer, on that one node. */
  (function watchModals() {
    var mr = document.getElementById('modalRoot');
    if (!mr) { if (document.getElementById('appMain') || document.readyState === 'loading') setTimeout(watchModals, 300); return; }
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
