/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Family & pick-ups (parent view).

   2026-10-13, Anthony: "add ability to request account for emergency guardian under
   parent view". A parent sees the people on their family's file — guardians, then the
   emergency contacts and authorised pick-ups — and can ASK the centre to give a contact
   their own pick-up login (their own check-in/out QR code and the parent view, never
   billing). The centre approves it on the child's record; a parent never creates a login.

   API: GET /parent/family-contacts, POST /parent/family-contacts/{ec}/login-request,
   DELETE /parent/pickup-login-requests/{id} (PickupLoginController).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  function api() { return window.Api || KT.Api; }
  function dom() { return KT.Dom || window.Dom; }
  function toast(m, kind) { var D = dom(); if (D && D.toast) D.toast(m, kind); }

  function pill(D, t, fg, bg) {
    return D.el('span', { style: 'font-size:11.5px;font-weight:700;color:' + fg + ';background:' + bg + ';border-radius:999px;padding:3px 10px;white-space:nowrap;' }, t);
  }
  function btn(D, t, primary, fn) {
    var b = D.el('button', {
      type: 'button',
      style: 'height:30px;padding:0 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;'
        + (primary ? 'background:var(--kt-primary,#1F6080);color:#fff;border:1px solid var(--kt-primary,#1F6080);'
                   : 'background:var(--kt-surface,#fff);color:var(--kt-primary,#1F6080);border:1px solid var(--kt-border,#CBD5E1);'),
    }, t);
    b.addEventListener('click', function (e) { e.preventDefault(); fn(b); });
    return b;
  }
  function row(D, name, sub, right) {
    var r = D.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--kt-border,#EEF2F5);flex-wrap:wrap;' });
    var initials = String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
    r.appendChild(D.el('div', { style: 'width:34px;height:34px;border-radius:50%;background:#E0F2FE;color:#0369A1;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;' }, initials || '?'));
    r.appendChild(D.el('div', { style: 'min-width:0;flex:1;' }, [
      D.el('div', { style: 'font-weight:700;font-size:14px;color:var(--kt-text,#0F172A);' }, name),
      sub ? D.el('div', { style: 'font-size:12.5px;color:var(--kt-text-muted,#64748B);margin-top:1px;' }, sub) : D.el('span', {}),
    ]));
    r.appendChild(D.el('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-left:auto;' }, right));
    return r;
  }
  function heading(D, t) {
    return D.el('div', { style: 'font-size:11.5px;font-weight:800;color:var(--kt-text-muted,#6B7280);text-transform:uppercase;letter-spacing:.3px;margin:14px 0 4px;' }, t);
  }

  function ask(c, b, again) {
    var prompt = KT.prompt
      ? KT.prompt({
          title: 'Pick-up login for ' + c.name,
          description: 'The centre will set up a login so ' + c.name + ' can check the children in and out with their own QR code. They will not see billing. Where should their sign-in details go?',
          fields: [{ key: 'email', label: 'Their email', type: 'email', value: (c.request && c.request.email) || c.email || '', placeholder: 'name@example.com' }],
          okLabel: again ? 'Update request' : 'Send request',
        })
      : Promise.resolve(window.prompt('Email for ' + c.name + '’s pick-up login', c.email || ''));
    prompt.then(function (v) {
      v = String(v || '').trim();
      if (!v) return;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { toast('That email address does not look right.', 'error'); return; }
      b.disabled = true;
      api().post('/parent/family-contacts/' + c.id + '/login-request', { email: v }).then(function () {
        toast(again ? 'Request updated' : 'Request sent — the centre will let you know', 'success');
        rerender();
      }, function (err) { b.disabled = false; toast((err && err.message) || 'Could not send the request', 'error'); });
    });
  }

  var lastMain = null;
  function rerender() { if (lastMain && lastMain.isConnected) render(lastMain); }

  async function render(main) {
    var D = dom();
    lastMain = main;
    D.clear(main);
    var wrap = D.el('div', { style: 'padding:20px 16px 90px;max-width:820px;margin:0 auto;' });
    main.appendChild(wrap);
    wrap.appendChild(D.el('h1', { style: 'font-family:var(--kt-font-display);font-size:23px;margin:0 0 4px;' }, 'Family & pick-ups'));
    wrap.appendChild(D.el('p', { style: 'color:var(--kt-text-muted);font-size:14px;line-height:1.6;margin:0 0 18px;' },
      'The people on your family’s file at the centre. You can ask the centre to give an emergency contact their own pick-up login — their own QR code to check the children in and out. They never see billing.'));
    var list = D.el('div', {}, [D.el('div', { style: 'color:var(--kt-text-muted);font-size:13.5px;' }, 'Loading…')]);
    wrap.appendChild(list);

    var data;
    try { data = await api().get('/parent/family-contacts'); }
    catch (e) { D.clear(list); list.appendChild(D.el('div', { style: 'color:#DC2626;font-size:13.5px;' }, 'Could not load your family’s contacts. Please try again.')); return; }
    D.clear(list);
    var fams = (data && data.families) || [];
    if (!fams.length) { list.appendChild(D.el('div', { style: 'color:var(--kt-text-muted);font-size:13.5px;' }, 'No family is linked to your account yet.')); return; }

    fams.forEach(function (fam) {
      var card = D.el('div', { style: 'background:var(--kt-surface,#fff);border:1px solid var(--kt-border,#E5E7EB);border-radius:14px;padding:14px 16px 6px;margin-bottom:14px;' });
      if (fams.length > 1) card.appendChild(D.el('div', { style: 'font-weight:800;font-size:15px;margin-bottom:2px;' }, (fam.family_name || 'Family') + ' family'));

      card.appendChild(heading(D, 'Parents & guardians'));
      (fam.guardians || []).forEach(function (g) {
        var sub = [g.relationship, g.is_primary ? 'primary' : '', g.pickup_only ? 'pick-up login' : ''].filter(Boolean).join(' · ');
        card.appendChild(row(D, g.name + (g.is_me ? ' (you)' : ''), sub, [
          g.has_login ? pill(D, '✓ Has a login', '#047857', '#ECFDF5') : pill(D, 'Not signed in yet', '#92400E', '#FEF3C7'),
        ]));
      });

      card.appendChild(heading(D, 'Emergency contacts & pick-ups'));
      var cs = fam.contacts || [];
      if (!cs.length) card.appendChild(D.el('div', { style: 'font-size:13px;color:var(--kt-text-muted);padding:6px 0 10px;' }, 'None on file. Ask the centre to add your emergency contacts.'));
      cs.forEach(function (c) {
        var sub = [c.relationship, c.can_pickup ? 'can pick up' : 'emergency contact only', c.phone].filter(Boolean).join(' · ');
        var right = [];
        if (c.has_login) {
          right.push(pill(D, '🚸 Has a pick-up login', '#047857', '#ECFDF5'));
        } else if (c.request) {
          right.push(pill(D, '⏳ Requested — waiting for the centre', '#92400E', '#FEF3C7'));
          if (fam.can_request) {
            right.push(btn(D, 'Change email', false, function (b) { ask(c, b, true); }));
            right.push(btn(D, 'Cancel', false, function (b) {
              var go = function () {
                b.disabled = true;
                api().delete('/parent/pickup-login-requests/' + c.request.id).then(function () { toast('Request cancelled', 'success'); rerender(); },
                  function (err) { b.disabled = false; toast((err && err.message) || 'Could not cancel', 'error'); });
              };
              if (KT.confirm) KT.confirm({ title: 'Cancel this request?', description: 'The centre will not set up a login for ' + c.name + '.', okLabel: 'Cancel request', cancelLabel: 'Keep it' }).then(function (y) { if (y) go(); });
              else go();
            }));
          }
        } else if (fam.can_request) {
          right.push(btn(D, '🔑 Request pick-up login', true, function (b) { ask(c, b, false); }));
        }
        card.appendChild(row(D, c.name || 'Contact', sub, right));
      });
      list.appendChild(card);
    });
  }

  KT.FamilyContacts = { render: render };
  var Shell = (window.KT && window.KT.Shell) || window.Shell;
  if (Shell && Shell.registerScreen) Shell.registerScreen('guardian:family-contacts', render);
})(window);
