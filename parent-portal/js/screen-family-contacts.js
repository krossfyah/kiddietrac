/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Family & pick-ups (parent view).

   2026-10-13, Anthony: "add ability to request account for emergency guardian under
   parent view". A parent sees the people on their family's file — guardians, then the
   emergency contacts and authorised pick-ups — and can ASK the centre to give a contact
   their own pick-up login (their own check-in/out QR code and the parent view, never
   billing). The centre approves it on the child's record; a parent never creates a login.

   API: GET /parent/family-contacts, POST /parent/family-contacts/{ec}/login-request,
   DELETE /parent/pickup-login-requests/{id} (PickupLoginController).

   Parents also KEEP the list (2026-10-13, Anthony: "pick up authorizations for parents view
   should show the guardians allowed to pick up child and can add or remove/edit"): add, edit,
   remove a contact and say whether they may pick up — POST/PATCH/DELETE
   /parent/family-contacts. The centre's staff and the family's other parents are told of
   every change, and it is logged. This screen is also #pickup-auth ("Pickup people").
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

  /* The add / edit form. A plain overlay marked as a dialog, so the shell's Back handling
     leaves its buttons alone. */
  function editContact(famId, c) {
    var D = dom();
    var isNew = !c; c = c || { can_pickup: true };
    var FLD = 'width:100%;box-sizing:border-box;height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;background:#fff;color:#0F172A;';
    var LBL = 'display:block;font-size:12px;font-weight:700;color:#334155;margin:10px 0 4px;';
    var e = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; }); };
    var ov = document.createElement('div');
    ov.className = 'kt-modal kt-fc-modal';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.style.cssText = 'position:fixed;inset:0;z-index:10050;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;padding:16px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:14px;width:100%;max-width:480px;max-height:90vh;overflow:auto;padding:20px 20px 16px;box-sizing:border-box;">'
      + '<h3 style="margin:0 0 4px;font-size:18px;color:#0F172A;">' + (isNew ? 'Add a contact' : 'Edit ' + e(c.name)) + '</h3>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.45;">The centre is told about every change.</div>'
      + '<label style="' + LBL + '">Full name *</label><input data-f="name" maxlength="120" style="' + FLD + '" value="' + e(c.name) + '">'
      + '<label style="' + LBL + '">Relationship to the child</label><input data-f="relationship" maxlength="60" placeholder="e.g. Grandmother, Aunt, Neighbour" style="' + FLD + '" value="' + e(c.relationship) + '">'
      + '<label style="' + LBL + '">Phone *</label><input data-f="phone" type="tel" maxlength="40" style="' + FLD + '" value="' + e(c.phone) + '">'
      + '<div style="display:grid;grid-template-columns:1fr 110px 80px;gap:8px;">'
      +   '<div><label style="' + LBL + '">Second phone</label><input data-f="alt_phone" type="tel" maxlength="40" style="' + FLD + '" value="' + e(c.alt_phone) + '"></div>'
      +   '<div><label style="' + LBL + '">Type</label><select data-f="alt_phone_type" style="' + FLD + '">'
      +     ['mobile', 'home', 'work', 'other'].map(function (t) { return '<option value="' + t + '"' + ((c.alt_phone_type || 'mobile') === t ? ' selected' : '') + '>' + t.charAt(0).toUpperCase() + t.slice(1) + '</option>'; }).join('') + '</select></div>'
      +   '<div><label style="' + LBL + '">Ext.</label><input data-f="alt_phone_ext" maxlength="10" style="' + FLD + '" value="' + e(c.alt_phone_ext) + '"></div>'
      + '</div>'
      + '<label style="' + LBL + '">Email</label><input data-f="email" type="email" maxlength="180" style="' + FLD + '" value="' + e(c.email) + '">'
      + '<label style="display:flex;align-items:center;gap:10px;margin-top:14px;font-size:14px;font-weight:700;color:#0F172A;cursor:pointer;">'
      +   '<input data-f="can_pickup" type="checkbox" style="width:18px;height:18px;"' + (c.can_pickup ? ' checked' : '') + '> Allowed to pick up the children</label>'
      + '<div style="font-size:12px;color:#64748B;margin:3px 0 0 28px;line-height:1.4;">Unticked, they are an emergency contact only. The centre will ask for photo ID the first time.</div>'
      + '<label style="' + LBL + '">Notes for the centre</label><textarea data-f="notes" maxlength="500" rows="2" style="' + FLD + 'height:auto;min-height:54px;padding:8px 10px;font-family:inherit;resize:vertical;">' + e(c.notes) + '</textarea>'
      + '<div data-err style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:8px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
      +   '<button type="button" data-cancel style="height:32px;padding:0 14px;border-radius:8px;border:0;background:#F1F5F9;color:#334155;font-weight:700;cursor:pointer;">Cancel</button>'
      +   '<button type="button" data-save style="height:32px;padding:0 16px;border-radius:8px;border:0;background:var(--kt-primary,#1F6080);color:#fff;font-weight:800;cursor:pointer;">' + (isNew ? 'Add contact' : 'Save changes') + '</button>'
      + '</div></div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('[data-cancel]').onclick = close;
    var q = function (k) { return ov.querySelector('[data-f="' + k + '"]'); };
    var syncExt = function () { q('alt_phone_ext').disabled = q('alt_phone_type').value !== 'work'; q('alt_phone_ext').style.opacity = q('alt_phone_ext').disabled ? '.5' : '1'; };
    q('alt_phone_type').onchange = syncExt; syncExt();
    setTimeout(function () { try { q('name').focus(); } catch (x) {} }, 30);
    ov.querySelector('[data-save]').onclick = function () {
      var b = this, err = ov.querySelector('[data-err]');
      var body = {
        name: q('name').value.trim(), relationship: q('relationship').value.trim(), phone: q('phone').value.trim(),
        alt_phone: q('alt_phone').value.trim(), alt_phone_type: q('alt_phone_type').value, alt_phone_ext: q('alt_phone_ext').value.trim(),
        email: q('email').value.trim(), can_pickup: q('can_pickup').checked, notes: q('notes').value.trim(),
      };
      if (!body.name) { err.textContent = 'Add their name.'; q('name').focus(); return; }
      if (!body.phone) { err.textContent = 'Add a phone number the centre can reach them on.'; q('phone').focus(); return; }
      if (body.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.email)) { err.textContent = 'That email address does not look right.'; q('email').focus(); return; }
      b.disabled = true; err.textContent = '';
      var go = function () {
        var p = isNew ? api().post('/parent/family-contacts', Object.assign({ family_id: famId }, body))
                      : api().patch('/parent/family-contacts/' + c.id, body);
        p.then(function () { close(); toast(isNew ? 'Contact added — the centre has been told' : 'Saved — the centre has been told', 'success'); rerender(); },
          function (x) { b.disabled = false; err.textContent = (x && x.message) || 'Could not save.'; });
      };
      if (!isNew && c.has_login && !body.can_pickup && KT.confirm) {
        KT.confirm({ title: 'Stop ' + c.name + ' picking up?', description: 'Their pick-up login will stop working too.', okLabel: 'Yes, stop' })
          .then(function (y) { if (y) go(); else b.disabled = false; });
      } else go();
    };
  }
  function removeContact(c) {
    var go = function () {
      api().delete('/parent/family-contacts/' + c.id).then(function () { toast(c.name + ' removed — the centre has been told', 'success'); rerender(); },
        function (x) { toast((x && x.message) || 'Could not remove', 'error'); });
    };
    if (KT.confirm) KT.confirm({ title: 'Remove ' + c.name + '?', description: 'They will no longer be on file as an emergency contact or allowed to pick up' + (c.has_login ? ', and their pick-up login stops working' : '') + '. The centre is told.', okLabel: 'Remove' })
      .then(function (y) { if (y) go(); });
    else go();
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
        var sub = [g.relationship, g.is_primary ? 'primary' : '', g.can_pickup ? '✓ may pick up' : 'not on the pick-up list', g.pickup_only ? 'pick-up login' : '', g.phone].filter(Boolean).join(' · ');
        card.appendChild(row(D, g.name + (g.is_me ? ' (you)' : ''), sub, [
          g.has_login ? pill(D, '✓ Has a login', '#047857', '#ECFDF5') : pill(D, 'Not signed in yet', '#92400E', '#FEF3C7'),
        ]));
      });
      if (fam.can_request) {
        card.appendChild(D.el('div', { style: 'font-size:12px;color:var(--kt-text-muted,#64748B);padding:2px 0 4px;' }, 'To change a parent or guardian, contact the centre.'));
      }

      var hd = D.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:8px;' }, [heading(D, 'Emergency contacts & pick-ups')]);
      if (fam.can_request) hd.appendChild(btn(D, '＋ Add contact', true, function () { editContact(fam.family_id, null); }));
      card.appendChild(hd);
      var cs = fam.contacts || [];
      if (!cs.length) card.appendChild(D.el('div', { style: 'font-size:13px;color:var(--kt-text-muted);padding:6px 0 10px;' }, fam.can_request ? 'None on file yet. Add the people who may collect your children or be called in an emergency.' : 'None on file.'));
      cs.forEach(function (c) {
        var sub = [c.relationship, c.can_pickup ? '✓ may pick up' : 'emergency contact only', c.phone, c.alt_phone ? c.alt_phone + ' (' + (c.alt_phone_type || 'mobile') + (c.alt_phone_ext ? ' ext. ' + c.alt_phone_ext : '') + ')' : ''].filter(Boolean).join(' · ');
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
        if (fam.can_request) {
          right.push(btn(D, 'Edit', false, function () { editContact(fam.family_id, c); }));
          right.push(btn(D, 'Remove', false, function () { removeContact(c); }));
        }
        card.appendChild(row(D, c.name || 'Contact', sub, right));
      });
      list.appendChild(card);
    });
  }

  KT.FamilyContacts = { render: render };
  var Shell = (window.KT && window.KT.Shell) || window.Shell;
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('guardian:family-contacts', render);
    // "Pickup people" (home tile #pickup-auth) is this same list now.
    Shell.registerScreen('guardian:pickup-auth', render);
  }
})(window);
