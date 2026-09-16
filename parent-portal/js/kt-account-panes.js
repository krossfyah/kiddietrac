/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — account panes: one component, two modes (2026-09-16).

   "My profile & security" and the User management record had grown into two different
   views of the same person. The first knew how somebody is paid and whether two-factor
   is on; the second knew their roles, rooms and shifts. Neither could answer a question
   belonging to the other, and the parts they DID share — the profile fields, the filed
   documents — were written twice and had already drifted apart.

   Anthony, 2026-09-16: "my profile and security and all migrate to the users management
   and the user profile."

   So the shared panes live here and are rendered by both screens:

     KT.AccountPanes.payroll(host, subject, mode)
     KT.AccountPanes.security(host, subject, mode)
     KT.AccountPanes.documents(host, subject, mode)

   mode is 'self' or 'admin', and it is the ONLY thing that differs. Self means the
   person signed in, editing their own record. Admin means somebody looking at a
   colleague's, and the difference is deliberately not cosmetic:

   WHAT AN ADMIN IS SHOWN IS NARROWER, ON PURPOSE.

   Payout details are stored encrypted and write-only: the owner's own screen shows them
   "•••• 4821" and nothing more, because a stored account number never appears in a
   response, a log or a DOM node. An admin sees exactly the same hint and cannot change
   it. That answers the question an admin actually has — "are the right details on file"
   — without turning a payroll destination into something a colleague can edit.

   Two-factor reports whether it is on and when it was switched on. The secret is never
   read, by anybody, anywhere.

   Anything an admin can do that the owner cannot — reset a password, off-board them —
   stays on the admin screen and is not smuggled in here.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (KT.AccountPanes) { return; }

  function api() { return (window.KT && KT.Api) ? KT.Api : null; }

  function el(tag, style, text) {
    var e = document.createElement(tag);
    if (style) { e.style.cssText = style; }
    if (text != null) { e.textContent = text; }
    return e;
  }
  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var CARD = 'background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:16px 18px;margin-bottom:14px;';
  var SECT = 'font-size:11px;font-weight:800;color:#6B7280;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;';
  var MUTED = 'font-size:12.5px;color:#64748B;line-height:1.55;';

  /* A server timestamp carries no zone and is UTC; the agency's clock is the one to
     read it in. KT.Fmt settles both and is used rather than a fourth local formatter. */
  function when(ts, withTime) {
    if (!ts) { return null; }
    try {
      var F = window.KT && KT.Fmt;
      var d = (F && F.parse) ? F.parse(ts) : new Date(String(ts).replace(' ', 'T') + 'Z');
      if (!d || isNaN(d.getTime())) { return null; }
      var tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      var o = { year: 'numeric', month: 'short', day: 'numeric' };
      if (withTime) { o.hour = 'numeric'; o.minute = '2-digit'; }
      if (tz) { o.timeZone = tz; }
      return d.toLocaleString('en-CA', o);
    } catch (e) { return null; }
  }

  // A labelled fact: the thing on the left, the answer on the right.
  function factRow(label, value, tone) {
    var r = el('div', 'display:flex;justify-content:space-between;align-items:baseline;gap:16px;'
      + 'padding:7px 0;border-bottom:1px solid #F3F4F6;font-size:13.5px;');
    r.appendChild(el('span', 'color:#64748B;flex:0 0 auto;', label));
    var v = el('span', 'font-weight:700;text-align:right;min-width:0;color:' + (tone || '#0F172A') + ';');
    v.textContent = value == null || value === '' ? '—' : String(value);
    r.appendChild(v);
    return r;
  }

  function busy(host, text) {
    host.innerHTML = '';
    host.appendChild(el('div', 'padding:18px;color:#94A3B8;font-size:13px;', text || 'Loading…'));
  }
  function failed(host, msg) {
    host.innerHTML = '';
    host.appendChild(el('div', 'padding:16px;color:#B45309;font-size:13px;', msg));
  }

  /* One read for the admin side of every pane, cached per subject for the life of the
     dialog — three panes asking the same question three times is three round trips for
     one answer. */
  var _cache = {};
  function adminRead(userId, force) {
    var k = String(userId);
    if (!force && _cache[k]) { return _cache[k]; }
    var a = api();
    if (!a) { return Promise.reject(new Error('API not ready')); }
    _cache[k] = a.get('/admin/users/' + userId + '/account-profile');
    return _cache[k];
  }
  function forget(userId) { delete _cache[String(userId)]; }

  var METHOD_LABEL = { interac: 'Interac e-Transfer', direct_deposit: 'Direct deposit' };

  // ─────────────────────────────────────────────────────────── payroll ──
  function payroll(host, subject, mode) {
    host.innerHTML = '';
    if (mode === 'self') {
      /* The owner's own editor is unchanged and still lives on the settings screen —
         it is the one place a payout method may be written, and moving the write path
         is not what "show it on the user record" asked for. */
      if (KT.renderPayoutEditor) { KT.renderPayoutEditor(host); return; }
      failed(host, 'The payout editor is not loaded.');
      return;
    }

    busy(host);
    adminRead(subject.id).then(function (d) {
      host.innerHTML = '';
      var card = el('div', CARD);
      card.appendChild(el('div', SECT, 'How they are paid'));

      var p = d && d.payout;
      if (!p) {
        card.appendChild(el('div', MUTED,
          'Nothing on file. Payroll has nowhere to send their pay until they add it on '
          + 'their own profile.'));
        host.appendChild(card);
        return;
      }

      var on = el('div', 'display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #BBF7D0;'
        + 'background:#F0FDF4;border-radius:10px;margin-bottom:12px;');
      on.appendChild(el('span', 'font-size:15px;', '✓'));
      on.appendChild(el('span', 'font-size:13px;font-weight:800;color:#166534;',
        METHOD_LABEL[p.method] || p.method || 'On file'));
      if (p.hint) { on.appendChild(el('span', 'font-size:13px;color:#166534;', p.hint)); }
      card.appendChild(on);

      card.appendChild(factRow('Name on the account', p.legal_name));
      card.appendChild(factRow('On file since', when(p.updated_at) || '—'));

      /* Said outright, because the obvious next question is "why can I not fix this for
         them", and the answer is a deliberate one rather than a missing button. */
      card.appendChild(el('div', MUTED + 'margin-top:12px;',
        'Only this person can change these details. They are stored encrypted and this '
        + 'screen shows the last few digits — enough to confirm the right account is on '
        + 'file, and useless to anyone reading over a shoulder.'));
      host.appendChild(card);
    }).catch(function (e) {
      failed(host, 'Could not read their payment details' + (e && e.message ? ' — ' + e.message : '.'));
    });
  }

  // ────────────────────────────────────────────────────────── security ──
  function security(host, subject, mode) {
    host.innerHTML = '';
    if (mode === 'self') {
      if (KT.renderSecurityEditor) { KT.renderSecurityEditor(host); return; }
      failed(host, 'The security panel is not loaded.');
      return;
    }

    busy(host);
    adminRead(subject.id).then(function (d) {
      host.innerHTML = '';
      var s = (d && d.security) || {};
      var card = el('div', CARD);
      card.appendChild(el('div', SECT, 'Sign-in & security'));

      var since = when(s.two_factor_since);
      card.appendChild(factRow('Two-factor',
        s.two_factor_enabled ? ('On' + (since ? ' · since ' + since : '')) : 'Off',
        s.two_factor_enabled ? '#166534' : '#B45309'));

      var howWhen = when(s.password_changed_at, true);
      card.appendChild(factRow('Password last set',
        howWhen ? (howWhen + (s.password_changed_how ? ' · ' + s.password_changed_how : '')) : 'Not recorded'));

      if (s.must_change_password) {
        card.appendChild(factRow('Must choose a new password', 'Yes — they are holding a one-time key', '#B45309'));
      }
      card.appendChild(factRow('Last signed in', when(s.last_login_at, true) || 'Never',
        s.last_login_at ? '#0F172A' : '#B45309'));

      card.appendChild(el('div', MUTED + 'margin-top:12px;',
        'Two-factor is theirs to switch on or off, from their own profile. The '
        + 'authenticator secret is never readable — not here, not anywhere.'));
      host.appendChild(card);
    }).catch(function (e) {
      failed(host, 'Could not read their sign-in details' + (e && e.message ? ' — ' + e.message : '.'));
    });
  }

  // ───────────────────────────────────────────────────────── documents ──
  function documents(host, subject, mode) {
    host.innerHTML = '';
    if (mode === 'self') {
      if (KT.renderMyDocuments) { try { KT.renderMyDocuments(host); } catch (e) {} return; }
      failed(host, 'Documents are not loaded.');
      return;
    }
    /* The admin record already draws the person's filed documents with the actions only
       an admin has — attach, remove, re-file. That pane stays where it is; wrapping it
       here would be a rename rather than a merge. */
    failed(host, 'Documents for another person are on the Files & documents tab.');
  }

  KT.AccountPanes = {
    payroll: payroll,
    security: security,
    documents: documents,
    // Exposed so a screen can drop a stale read after it writes.
    forget: forget,
    _when: when,
  };
})(window);
