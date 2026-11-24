/* ═══════════════════════════════════════════════════════════════════════
   kt-pw-gate.js: a pending password change LOCKS the portal (2026-10-22)

   Anthony: "password reset when you log in allows you to navigate to other screens and
   sections within the portal - can you make it where as the password reset is a popup
   with a dimmed background and forces you to reset and do not leave that screen at all
   (only password reset prompts are shown nothing else)".

   Until today a pending change (an admin reset, or the 90-day limit) only sent the person
   to Settings > Security with a toast. The menu, every other screen and every popup stayed
   reachable, and each of them simply failed with 403 behind EnsurePasswordChanged.

   Now one dialog owns the screen:
   - shown on a dimmed, blurred backdrop that cannot be clicked through;
   - no X, Escape does nothing, focus cannot leave it;
   - everything else on the page is made inert, and any popup, toast or gate that tries
     to open while it is up is hidden (the MFA gate, the agreement, biometric offers...
     all come back after the reload that follows a successful change);
   - the only ways out are "Save new password" or "Sign out".

   Raised by any of three signals, whichever comes first:
   1. sessionStorage kt_force_password_change = '1' (set by the sign-in page when the
      login reply says must_change_password, and by the two below);
   2. GET /auth/me answering password_change_required: true (every boot asks it, so a
      restored session, a biometric/PIN unlock or an aged-out password is caught too);
      false clears a stale flag (e.g. a super admin viewing as somebody);
   3. any API call answering 403 {password_change_required: true}. The check sits on
      fetch itself because many screens call fetch through their own helpers, never Api
      (Closed Testing Report issues 5 and 6). app.js's Api calls KT.pwGate.show too.

   The server is still the real gate (EnsurePasswordChanged); this is what makes it a
   screen instead of a portal full of failures.
   ═══════════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.__ktPwGate || !w.fetch) return;
  w.__ktPwGate = true;

  var D = w.document;
  var ID = 'kt-force-password';
  var FLAG = 'kt_force_password_change';
  var WHY = 'kt_force_password_reason';
  var real = w.fetch;
  var active = false, shown = false, root = null, mo = null, msgOverride = null;

  function T(s) { try { return (w.KT && w.KT.i18n && w.KT.i18n.t) ? w.KT.i18n.t(s) : s; } catch (e) { return s; } }
  function ss(k, v) { try { if (v === undefined) return w.sessionStorage.getItem(k); if (v === null) w.sessionStorage.removeItem(k); else w.sessionStorage.setItem(k, v); } catch (e) { return null; } }
  function token() { try { return w.sessionStorage.getItem('kt_token') || w.localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (w.KT && w.KT.API_BASE) || (w.KT_CONFIG && w.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }

  function isApi(url) { return /\/api\/v1\//.test(String(url || '')); }
  function isAuth(url) { return /\/api\/v1\/auth\//.test(String(url || '')); }
  function isMe(url) { return /\/api\/v1\/auth\/me(\?|$)/.test(String(url || '')); }

  /* Keep the cached user in step, so code that reads kt_user (kt-biometric's
     mandatoryStepOpen, the passkey offer) agrees with the gate. */
  function stampUser(required) {
    ['sessionStorage', 'localStorage'].forEach(function (store) {
      try {
        var raw = w[store].getItem('kt_user'); if (!raw) return;
        var u = JSON.parse(raw); if (!u || typeof u !== 'object') return;
        if (!!u.must_change_password === !!required) return;
        u.must_change_password = !!required;
        w[store].setItem('kt_user', JSON.stringify(u));
      } catch (e) {}
    });
  }

  /* ── the lock ─────────────────────────────────────────────────────────── */
  var sealed = [];
  function seal(node) {
    if (!node || node.nodeType !== 1 || node.id === ID) return;
    var tag = node.tagName;
    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' || tag === 'TEMPLATE') return;
    if (node.hasAttribute('data-kt-pwseal')) return;
    node.setAttribute('data-kt-pwseal', '1');
    sealed.push([node, node.hasAttribute('inert'), node.getAttribute('aria-hidden')]);
    try { node.inert = true; } catch (e) {}
    node.setAttribute('inert', '');
    node.setAttribute('aria-hidden', 'true');
  }
  function hideLate(node) {
    // Anything that opens AFTER the lock (a toast, a popup, another gate) is not shown.
    if (!node || node.nodeType !== 1 || node.id === ID) return;
    var tag = node.tagName;
    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' || tag === 'TEMPLATE') return;
    seal(node);
    node.setAttribute('data-kt-pwhid', '1');
  }

  function lockPage() {
    if (active) return;
    active = true;
    var html = D.documentElement;
    html.classList.add('kt-pw-locked');
    Array.prototype.forEach.call(D.body.children, seal);
    mo = new MutationObserver(function (list) {
      list.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, hideLate);
        // Something removed the dialog (a screen clearing body): put it back.
        if (shown && root && !root.isConnected) D.body.appendChild(root);
      });
      // Always the last child, so nothing at the same z-index paints over it.
      if (shown && root && D.body.lastElementChild !== root) D.body.appendChild(root);
    });
    mo.observe(D.body, { childList: true });
    // Keys never reach the page behind: Escape cannot close, shortcuts cannot navigate.
    w.addEventListener('keydown', onKey, true);
    w.addEventListener('focusin', onFocus, true);
  }

  function onKey(e) {
    if (!active) return;
    var inside = root && root.contains(e.target);
    if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); return; }
    if (!inside) { e.preventDefault(); e.stopImmediatePropagation(); focusFirst(); return; }
    if (e.key === 'Tab') {
      var f = focusables(); if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && D.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && D.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    // Inside the dialog the key carries on to its input; the dialog's own bubble-phase
    // listener (build) stops it there, so the app's document-level shortcuts never see it.
  }
  function onFocus(e) { if (active && root && !root.contains(e.target)) focusFirst(); }
  function focusables() { return root ? Array.prototype.filter.call(root.querySelectorAll('input,button,a[href]'), function (n) { return !n.disabled && n.offsetParent !== null; }) : []; }
  function focusFirst() { var i = root && root.querySelector('#kfp-cur'); if (i) { try { i.focus({ preventScroll: true }); } catch (e) { i.focus(); } } }

  /* ── the dialog ───────────────────────────────────────────────────────── */
  var RULES = [
    { key: 'len', label: 'At least 8 characters', test: function (v) { return v.length >= 8; } },
    { key: 'case', label: 'Upper and lower case', test: function (v) { return /[a-z]/.test(v) && /[A-Z]/.test(v); } },
    { key: 'num', label: 'A number', test: function (v) { return /\d/.test(v); } },
    { key: 'sym', label: 'A symbol (!?@#…)', test: function (v) { return /[^A-Za-z0-9]/.test(v); } },
  ];

  function suggest() {
    var lower = 'abcdefghijkmnpqrstuvwxyz', upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ', nums = '23456789', syms = '!@#$%?&*';
    var all = lower + upper + nums + syms, c = w.crypto || w.msCrypto;
    function pick(set, n) { var o = '', a = new Uint32Array(n); c.getRandomValues(a); for (var i = 0; i < n; i++) o += set.charAt(a[i] % set.length); return o; }
    var ch = (pick(lower, 4) + pick(upper, 3) + pick(nums, 3) + pick(syms, 2) + pick(all, 4)).split('');
    var r = new Uint32Array(ch.length); c.getRandomValues(r);
    for (var i = ch.length - 1; i > 0; i--) { var j = r[i] % (i + 1), t = ch[i]; ch[i] = ch[j]; ch[j] = t; }
    return ch.join('');
  }

  var CSS = ''
    + 'html.kt-pw-locked,html.kt-pw-locked body{overflow:hidden!important;overscroll-behavior:none}'
    + 'html.kt-pw-locked [data-kt-pwhid],html.kt-pw-locked #kt-toast-wrap{visibility:hidden!important;pointer-events:none!important}'
    + '#' + ID + '{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:max(16px,var(--kt-safe-top, env(safe-area-inset-top, 0px))) 16px max(16px,var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)));'
    + 'background:rgba(8,20,40,.62);-webkit-backdrop-filter:blur(7px) saturate(.85);backdrop-filter:blur(7px) saturate(.85);overflow-y:auto;opacity:0;transition:opacity .28s ease;font-family:inherit;box-sizing:border-box}'
    + '#' + ID + '.on{opacity:1}'
    + '#' + ID + ' *{box-sizing:border-box}'
    + '#' + ID + ' .kfp-card{background:#fff;width:100%;max-width:420px;margin:auto;border-radius:18px;padding:24px 22px 18px;box-shadow:0 30px 70px rgba(0,0,0,.38);transform:translateY(8px) scale(.985);transition:transform .28s ease}'
    + '#' + ID + '.on .kfp-card{transform:none}'
    + '#' + ID + ' .kfp-ico{width:48px;height:48px;border-radius:14px;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:24px;background:#FEF3C7}'
    + '#' + ID + ' h2{margin:0 0 6px;text-align:center;font-size:20px;font-weight:800;color:#0f172a;line-height:1.25}'
    + '#' + ID + ' .kfp-why{margin:0 0 16px;text-align:center;font-size:13.5px;line-height:1.5;color:#475569}'
    + '#' + ID + ' label{display:block;font-size:12.5px;font-weight:700;color:#334155;margin:10px 0 4px}'
    + '#' + ID + ' .kfp-in{position:relative}'
    + '#' + ID + ' input{width:100%!important;max-width:none!important;min-width:0;margin:0!important;height:44px!important;min-height:44px!important;line-height:normal!important;box-sizing:border-box!important;border:1.5px solid #CBD5E1;border-radius:10px;padding:0 52px 0 12px!important;font-size:16px;color:#0f172a;background:#fff;outline:none;font-family:inherit}'
    + '#' + ID + ' input:focus{border-color:#1F6FB2;box-shadow:0 0 0 3px rgba(31,111,178,.15)}'
    + '#' + ID + ' .kfp-eye{position:absolute;right:4px;top:50%;transform:translateY(-50%);height:32px;min-width:44px;margin:0;padding:0 8px;line-height:32px;border:0;background:none;box-shadow:none;color:#64748B;font-size:12px;font-weight:700;cursor:pointer;border-radius:8px;display:flex;align-items:center;justify-content:center}'
    + '#' + ID + ' .kfp-eye svg{width:20px;height:20px;display:block;pointer-events:none}'
    + '#' + ID + ' .kfp-eye:hover{color:#0f172a;background:#F1F5F9}'
    + '#' + ID + ' .kfp-meter{height:6px;border-radius:99px;background:#E7EDF3;overflow:hidden;margin:8px 0 0}'
    + '#' + ID + ' .kfp-meter i{display:block;height:100%;width:0;border-radius:99px;background:#EF4444;transition:width .2s ease,background .2s ease}'
    + '#' + ID + ' .kfp-word{font-size:12px;font-weight:700;margin-top:5px;min-height:15px;color:#94A3B8}'
    + '#' + ID + ' .kfp-rules{display:flex;flex-wrap:wrap;gap:3px 12px;margin:7px 0 0}'
    + '#' + ID + ' .kfp-rules div{font-size:11.5px;color:#94A3B8}'
    + '#' + ID + ' .kfp-rules div.ok{color:#16A34A;font-weight:700}'
    + '#' + ID + ' .kfp-sug{background:none;border:0;color:#159FB4;font-size:12px;font-weight:700;cursor:pointer;padding:6px 0 0}'
    + '#' + ID + ' .kfp-msg{min-height:18px;font-size:13px;margin:10px 0 6px;color:#B91C1C;line-height:1.4}'
    + '#' + ID + ' .kfp-go{width:100%;height:44px;border:0;border-radius:11px;color:#fff;font-weight:800;font-size:14.5px;cursor:pointer;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);opacity:1!important;display:flex;align-items:center;justify-content:center;gap:8px}'
    + '#' + ID + ' .kfp-go.busy{opacity:.75!important;cursor:default}'
    + '#' + ID + ' .kfp-spin{width:16px;height:16px;border-radius:50%;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;animation:kfp-spin .8s linear infinite}'
    + '@keyframes kfp-spin{to{transform:rotate(360deg)}}'
    + '#' + ID + ' .kfp-out{display:block;margin:12px auto 0;background:none;border:0;color:#64748B;font-size:12.5px;cursor:pointer;padding:6px 10px}';

  function el(tag, attrs, text) {
    var n = D.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text != null) n.textContent = text;
    return n;
  }

  var SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
  var EYE = SVG + '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = SVG + '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.4 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94"/>'
    + '<path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.4 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';

  function pwField(labelText, auto, id) {
    var lab = el('label', { for: id }, T(labelText));
    var box = el('div', { class: 'kfp-in' });
    var inp = el('input', { type: 'password', id: id, autocomplete: auto, spellcheck: 'false', autocapitalize: 'off' });
    /* An eye icon, not the word "Show" (Anthony, 2026-10-22); the words stay as the
       accessible name and tooltip. */
    var eye = el('button', { type: 'button', class: 'kfp-eye', 'aria-label': T('Show password'), title: T('Show password') });
    eye.innerHTML = EYE;
    eye.addEventListener('click', function () {
      var vis = inp.type === 'password';
      inp.type = vis ? 'text' : 'password';
      eye.innerHTML = vis ? EYE_OFF : EYE;
      eye.setAttribute('aria-label', T(vis ? 'Hide password' : 'Show password'));
      eye.setAttribute('title', T(vis ? 'Hide password' : 'Show password'));
      try { inp.focus({ preventScroll: true }); } catch (e) { inp.focus(); }
    });
    inp.__kfpEye = eye;
    box.appendChild(inp); box.appendChild(eye);
    return { lab: lab, box: box, input: inp };
  }

  function build() {
    if (!D.getElementById('kt-force-password-css')) {
      var st = el('style', { id: 'kt-force-password-css' }); st.textContent = CSS; D.head.appendChild(st);
    }
    var reason = ss(WHY) || 'admin_reset';
    /* No X, ever: kt-modal-guard.js adds one to every dialog and closes on a backdrop
       click, so this one opts out of both; kt-dialog-move would make it draggable and
       resizable, and kt-field-fit would cap the fields at 340px, so out of those too. */
    var o = el('div', { id: ID, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'kfp-title', 'data-no-modal-guard': '', 'data-kt-no-close': '', 'data-kt-nomove': '', 'data-kt-nochrome': '', 'data-kt-nofit': '', 'data-kt-nogrid': '' });
    var card = el('form', { class: 'kfp-card', novalidate: '' });
    card.appendChild(el('div', { class: 'kfp-ico', 'aria-hidden': 'true' }, '\u{1F511}'));
    card.appendChild(el('h2', { id: 'kfp-title' }, T('Choose a new password')));
    // Our own sentences for the two known reasons (they are translated); the server's
    // wording only for a reason this file does not know.
    var why = reason === 'expired' ? 'Your password has reached its age limit. Please choose a new one to continue.'
      : reason === 'admin_reset' ? 'Your password was reset by an administrator. Please choose a new password to continue.'
      : (msgOverride || 'Your password was reset by an administrator. Please choose a new password to continue.');
    card.appendChild(el('p', { class: 'kfp-why' }, T(why)));

    // A hidden username field lets password managers file the new password correctly.
    var who = ''; try { var u = JSON.parse(ss('kt_user') || w.localStorage.getItem('kt_user') || '{}'); who = u.email || u.username || ''; } catch (e) {}
    var un = el('input', { type: 'text', autocomplete: 'username', value: who, tabindex: '-1', 'aria-hidden': 'true' });
    un.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;';
    card.appendChild(un);

    var cur = pwField(reason === 'expired' ? 'Current password' : 'Temporary password (from your email)', 'current-password', 'kfp-cur');
    var nw = pwField('New password', 'new-password', 'kfp-new');
    var cf = pwField('Confirm new password', 'new-password', 'kfp-cf');
    [cur, nw].forEach(function (f) { card.appendChild(f.lab); card.appendChild(f.box); });

    var meter = el('div', { class: 'kfp-meter', 'aria-hidden': 'true' });
    var fill = el('i'); meter.appendChild(fill);
    var word = el('div', { class: 'kfp-word', 'aria-live': 'polite' }, T('Enter a new password'));
    card.appendChild(meter); card.appendChild(word);
    var rules = el('div', { class: 'kfp-rules' });
    var marks = RULES.map(function (r) { var m = el('div', null, '○ ' + T(r.label)); rules.appendChild(m); return m; });
    card.appendChild(rules);
    var sug = el('button', { type: 'button', class: 'kfp-sug' }, T('Suggest a strong password'));
    card.appendChild(sug);
    card.appendChild(cf.lab); card.appendChild(cf.box);

    var msg = el('div', { class: 'kfp-msg', role: 'alert', 'aria-live': 'polite' });
    /* type=button with its own handlers: some page-level click handler swallows the
       default action of a submit button here (measured: click arrives, no submit). */
    var go = el('button', { type: 'button', class: 'kfp-go' }, T('Save new password'));
    var out = el('button', { type: 'button', class: 'kfp-out' }, T('Sign out instead'));
    card.appendChild(msg); card.appendChild(go); card.appendChild(out);
    o.appendChild(card);
    o.addEventListener('keydown', function (e) { e.stopPropagation(); });

    /* Same scale as Settings > Security: the rules met, with a long passphrase that
       meets them all reading as Excellent. */
    function paint() {
      var v = nw.input.value || '';
      var n = 0;
      RULES.forEach(function (r, i) { var ok = r.test(v); if (ok) n++; marks[i].className = ok ? 'ok' : ''; marks[i].textContent = (ok ? '✓ ' : '○ ') + T(r.label); });
      var pct = v ? Math.max(8, (n / RULES.length) * 100) : 0;
      if (n === RULES.length && v.length >= 14) pct = 100; else if (n === RULES.length) pct = 85;
      var colour = n <= 1 ? '#EF4444' : n === 2 ? '#F59E0B' : n === 3 ? '#EAB308' : '#16A34A';
      var text = !v ? 'Enter a new password' : n <= 1 ? 'Weak' : n === 2 ? 'Fair' : n === 3 ? 'Good' : (v.length >= 14 ? 'Excellent' : 'Strong');
      fill.style.width = pct + '%'; fill.style.background = colour;
      word.textContent = T(text); word.style.color = v ? colour : '#94A3B8';
    }
    nw.input.addEventListener('input', paint);
    sug.addEventListener('click', function () {
      var p = suggest();
      nw.input.value = p; cf.input.value = p;
      [nw, cf].forEach(function (f) { f.input.type = 'text'; if (f.input.__kfpEye) { f.input.__kfpEye.innerHTML = EYE_OFF; f.input.__kfpEye.setAttribute('aria-label', T('Hide password')); } });
      paint();
      msg.style.color = '#0F766E';
      msg.textContent = T('Copy or save this password before you continue.');
    });

    /* Its own flag, not go.disabled: the portal's double-tap guard disables every clicked
       button for a moment, so go.disabled is already true when this handler runs. */
    var inFlight = false;
    o.__kfpBusy = function () { return inFlight; };
    function busy(on, label) {
      inFlight = on;
      go.disabled = on; out.disabled = on;
      go.classList.toggle('busy', on);
      go.textContent = '';
      if (on) go.appendChild(el('span', { class: 'kfp-spin', 'aria-hidden': 'true' }));
      go.appendChild(D.createTextNode(T(label || 'Save new password')));
    }

    card.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
    go.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); submit(); });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') { e.preventDefault(); submit(); }
    });
    function submit() {
      if (inFlight) return;
      msg.style.color = '#B91C1C'; msg.textContent = '';
      if (!cur.input.value) { msg.textContent = T(reason === 'expired' ? 'Enter your current password.' : 'Enter the temporary password from your email.'); cur.input.focus(); return; }
      if (!RULES.every(function (r) { return r.test(nw.input.value || ''); })) { msg.textContent = T('Your new password doesn’t meet all the requirements yet.'); nw.input.focus(); return; }
      if (nw.input.value !== cf.input.value) { msg.textContent = T('New passwords don’t match.'); cf.input.focus(); return; }
      if (nw.input.value === cur.input.value) { msg.textContent = T('Choose a password different from the current one.'); nw.input.focus(); return; }
      busy(true, 'Saving…');
      var t = token();
      real.call(w, apiBase() + '/auth/change-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + t },
        body: JSON.stringify({ current_password: cur.input.value, new_password: nw.input.value }),
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (j) { return { res: res, j: j }; });
      }).then(function (r) {
        if (r.res.status === 401) { signOut(); return; }
        if (!r.res.ok) {
          var errs = (r.j && r.j.errors) || {};
          var first = (errs.current_password || errs.new_password || [])[0];
          busy(false);
          msg.textContent = T(first || (r.j && r.j.message) || 'Could not change your password. Please try again.');
          (errs.current_password ? cur : nw).input.focus();
          return;
        }
        // Done: the gate opens. Reload so every screen, gate and popup starts clean.
        ss(FLAG, null); ss(WHY, null); stampUser(false);
        w.__ktPwRedirect = false;
        busy(true, 'Password changed');
        try { if (w.PasswordCredential && w.navigator.credentials && who) { w.navigator.credentials.store(new w.PasswordCredential({ id: who, password: nw.input.value })).catch(function () {}); } } catch (e2) {}
        setTimeout(function () { w.location.replace('/dashboard.html'); }, 700);
      }).catch(function () {
        busy(false);
        msg.textContent = T('Could not reach the server. Check your connection and try again.');
      });
    }

    out.addEventListener('click', signOut);
    return o;
  }

  function signOut() {
    ss(FLAG, null); ss(WHY, null);
    try { if (w.KT && w.KT.Auth && w.KT.Auth.logout) { w.KT.Auth.logout(); return; } } catch (e) {}
    try { ['kt_token', 'kt_user'].forEach(function (k) { w.sessionStorage.removeItem(k); w.localStorage.removeItem(k); }); } catch (e) {}
    w.location.replace('/index.html');
  }

  /* The post-sign-in logo animation plays first, then the dialog fades in over the
     dimmed portal. The page is locked from the first moment either way. */
  function splashUp() {
    var s = D.getElementById('kt-splash');
    return !!(s && s.isConnected && !s.classList.contains('kt-hide') && s.getClientRects().length);
  }

  function present() {
    if (shown) return;
    var waited = 0;
    (function wait() {
      if (!active) return;          // unlocked (server said no change needed) while waiting
      if (splashUp() && waited < 6000) { waited += 150; setTimeout(wait, 150); return; }
      if (shown || !active) return;
      shown = true;
      root = build();
      D.body.appendChild(root);
      requestAnimationFrame(function () { root.classList.add('on'); setTimeout(focusFirst, 60); });
    })();
  }

  function show(reason, message) {
    ss(FLAG, '1');
    if (reason) ss(WHY, reason);
    if (message) msgOverride = message;
    stampUser(true);
    w.__ktPwRedirect = true;     // app.js's older path stands down
    if (!D.body) { D.addEventListener('DOMContentLoaded', function () { show(); }); return; }
    lockPage();
    present();
  }

  /* THE SERVER DECIDES, NOT A NOTE LEFT IN THE TAB (2026-10-22).

     Anthony, the same afternoon this shipped: "why when i logged into the desktop view
     ... I am being asked to choose a new password again when I already changed my
     password earlier today". His desktop tab signed in at 10:44 UTC while his password
     was past 90 days, so this tab's sessionStorage got the flag. He changed the password
     on his PHONE at 12:57. At 21:32 he signed in again in that same desktop tab: the
     server said no change was needed, but nothing removed the flag (sessionStorage
     outlives a sign-out in the same tab), so this file locked the page from the flag on
     load — and the line here refused to take an open dialog down when /auth/me then
     said "not required". A password changed on another device left every other open
     tab locked the same way.

     Now: the flag only paints the lock early, and is checked at once with the server
     (verify() below). "Not required" from /auth/me takes the dialog down and unlocks the
     page, unless a change is being submitted from it this moment (that ends in a reload
     anyway). The sign-in page and every sign-out also drop the flag. */
  function clearIfStale() {
    ss(FLAG, null); ss(WHY, null); stampUser(false);
    if (shown || active) unlock();
  }
  function unlock() {
    if (root && root.__kfpBusy && root.__kfpBusy()) return;
    shown = false; active = false;
    try { if (mo) mo.disconnect(); } catch (e) {}
    mo = null;
    w.removeEventListener('keydown', onKey, true);
    w.removeEventListener('focusin', onFocus, true);
    if (root && root.parentNode) root.parentNode.removeChild(root);
    root = null; msgOverride = null;
    D.documentElement.classList.remove('kt-pw-locked');
    sealed.forEach(function (rec) {
      var node = rec[0];
      node.removeAttribute('data-kt-pwseal');
      node.removeAttribute('data-kt-pwhid');
      if (!rec[1]) { try { node.inert = false; } catch (e) {} node.removeAttribute('inert'); }
      if (rec[2] === null) node.removeAttribute('aria-hidden'); else node.setAttribute('aria-hidden', rec[2]);
    });
    sealed = [];
    w.__ktPwRedirect = false;
  }
  /* Ask the server now rather than waiting for the app's own boot request. */
  function verify() {
    var t = token(); if (!t) { clearIfStale(); return; }
    real.call(w, apiBase() + '/auth/me', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (res) {
        if (res.status === 401) { clearIfStale(); return null; }
        return res.ok ? res.json() : null;
      })
      .then(function (j) {
        var u = j && ((j.user && 'password_change_required' in j.user) ? j.user : j);
        if (!u || typeof u !== 'object' || !('password_change_required' in u)) return;
        if (u.password_change_required) { if (u.password_change_reason) ss(WHY, u.password_change_reason); }
        else clearIfStale();
      })
      .catch(function () {});        // offline: keep the lock; the next /auth/me decides
  }

  /* app.js replaces window.KT wholesale when it loads (after this file), so the hook is
     also a plain global and is re-attached once the page has parsed. */
  var api = { show: show, active: function () { return active; } };
  w.__ktPwGateShow = show;
  function attach() { w.KT = w.KT || {}; w.KT.pwGate = api; }
  attach();
  D.addEventListener('DOMContentLoaded', attach);

  /* ── the signals ──────────────────────────────────────────────────────── */
  w.fetch = function (input, init) {
    var p = real.apply(this, arguments);
    try {
      var url = (input && input.url) || input;
      if (!isApi(url)) return p;
      var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
      p.then(function (res) {
        if (!res) return;
        if (res.status === 403 && !isAuth(url)) {
          res.clone().json().then(function (j) {
            if (j && j.password_change_required) show(j.reason, j.message);
          }).catch(function () {});
        } else if (res.status === 200 && method === 'GET' && isMe(url)) {
          res.clone().json().then(function (j) {
            var u = (j && j.user) || j;
            if (!u || typeof u !== 'object' || !('password_change_required' in u)) return;
            if (u.password_change_required) show(u.password_change_reason);
            else clearIfStale();
          }).catch(function () {});
        }
      }, function () {});
    } catch (e) {}
    return p;
  };

  // The sign-in page already knew: lock before the first screen paints — and confirm it
  // with the server straight away, so a flag left over in this tab cannot keep it shut.
  if (ss(FLAG) === '1') {
    if (token()) {
      if (D.body) show(); else D.addEventListener('DOMContentLoaded', function () { show(); });
      verify();
    } else {
      ss(FLAG, null); ss(WHY, null);
    }
  }
}(window));
