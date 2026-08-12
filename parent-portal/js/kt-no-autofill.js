/*
 * kt-no-autofill.js — stop the browser / password managers from silently
 * autofilling CONFIG forms.
 *
 * Chrome (and 1Password/LastPass) will happily drop the signed-in user's saved
 * email + password into any email/password-shaped input on the page — which made
 * empty admin config sections (white-label email "from", Microsoft 365 mailbox,
 * QuickBooks credentials) LOOK pre-filled with data like "safia_a@hotmail.com"
 * that nobody ever entered. Saving that would write garbage.
 *
 * Mark a container (or an input) with `data-kt-noautofill` and every field inside
 * is neutralised:
 *   - autocomplete="off" + password-manager ignore hints + a randomised name
 *   - readonly until the user actually focuses/taps it (Chrome does not autofill
 *     readonly fields on load — the single most reliable defeat for autofill),
 *     then editable as normal.
 */
(function () {
  function neutralize(el) {
    if (!el || el.__ktNoAf) return;
    var tag = (el.tagName || '').toLowerCase();
    if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') return;
    // Leave real checkboxes/toggles/files alone.
    var t = (el.getAttribute('type') || 'text').toLowerCase();
    if (['checkbox', 'radio', 'file', 'range', 'color', 'hidden', 'submit', 'button'].indexOf(t) !== -1) return;
    el.__ktNoAf = true;
    el.setAttribute('autocomplete', 'off');
    el.setAttribute('autocorrect', 'off');
    el.setAttribute('autocapitalize', 'off');
    el.setAttribute('spellcheck', 'false');
    el.setAttribute('data-lpignore', 'true');     // LastPass
    el.setAttribute('data-1p-ignore', 'true');     // 1Password
    el.setAttribute('data-bwignore', 'true');      // Bitwarden
    el.setAttribute('data-form-type', 'other');
    if (!el.getAttribute('name')) el.setAttribute('name', 'ktf_' + Math.random().toString(36).slice(2, 10));
    // Readonly-until-focus: Chrome won't autofill a readonly field on load.
    if (t !== 'select-one' && tag !== 'select') {
      el.setAttribute('readonly', 'readonly');
      var unlock = function () { el.removeAttribute('readonly'); };
      el.addEventListener('focus', unlock);
      el.addEventListener('pointerdown', unlock);
      el.addEventListener('touchstart', unlock, { passive: true });
    }
  }

  function scan(root) {
    try {
      var scopes = (root || document).querySelectorAll('[data-kt-noautofill]');
      for (var i = 0; i < scopes.length; i++) {
        var s = scopes[i];
        neutralize(s); // in case the attribute is on the input itself
        var kids = s.querySelectorAll('input, textarea, select');
        for (var j = 0; j < kids.length; j++) neutralize(kids[j]);
      }
    } catch (e) {}
  }

  var pending = false;
  function schedule() {
    if (pending) return;
    pending = true;
    (window.requestAnimationFrame || window.setTimeout)(function () { pending = false; scan(document); }, 0);
  }

  function start() {
    scan(document);
    try {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();

  window.KT = window.KT || {};
  window.KT.neutralizeAutofill = scan;
})();
