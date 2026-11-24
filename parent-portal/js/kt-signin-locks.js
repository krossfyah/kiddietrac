/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Sign-in locks (2026-11-10).

   Pause sign-in for one person, a centre / home provider, or (KiddieTrac
   only) a whole agency, and lift it when ready. A centre lock only keeps
   out people whose every tie to the agency runs through locked centres:
   a parent with a child at another centre keeps access. Lifting it lets
   back in exactly the people it held, unless another lock still holds them.

   Every lock and unlock is a popup with notes that first shows who it
   reaches; the agency admins and the directors involved are emailed.
   Rules and API: App\Support\SignInLocks, SignInLockController.

   Used from: the Locked sign-ins screen (#signin-locks), a user's page
   (KT.signinLock.userButton), the Centres list and the provider ⋮ menu
   (KT.signinLock.centreButton / openFor), and Platform → Agencies.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  var BASE = '/admin/sign-in-locks';
  var ICON = '🚷';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function toast(icon, title, msg, colour) { if (KT.toast) KT.toast(icon, title, msg || '', colour); }
  function Api() { return KT.Api; }
  function Shell() { return KT.Shell; }
  function changed() { try { document.dispatchEvent(new CustomEvent('kt:signin-lock-changed')); } catch (e) {} }
  function n(k, word) { return k + ' ' + (k === 1 ? word : (word === 'person' ? 'people' : word + 's')); }

  var AREA = 'width:100%;box-sizing:border-box;min-height:84px;padding:7px 9px;border:1px solid #CBD5E1;border-radius:8px;'
    + 'font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#0F172A;';
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1E293B;font-size:12.5px;font-weight:600;cursor:pointer;';
  var INPUT = 'width:100%;box-sizing:border-box;height:30px;padding:4px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;background:#fff;color:#0F172A;';

  function box(bg, border, ink, html) {
    return '<div style="background:' + bg + ';border:1px solid ' + border + ';color:' + ink + ';border-radius:10px;padding:9px 12px;font-size:13px;line-height:1.5;margin:0 0 10px;">' + html + '</div>';
  }
  function scopeWord(scope) { return { user: 'Person', centre: 'Centre / provider', agency: 'Whole agency' }[scope] || scope; }

  /* A list of people. Long lists fold so the popup keeps ONE scroll region. */
  function people(title, list, note) {
    if (!list || !list.length) return '';
    var rows = list.map(function (p) {
      return '<li style="display:flex;justify-content:space-between;gap:10px;padding:4px 0;border-bottom:1px solid #F1F5F9;font-size:13px;">'
        + '<span>' + esc(p.name) + '</span><span style="color:#64748B;white-space:nowrap;">' + esc(p.roles) + '</span></li>';
    }).join('');
    var inner = '<ul style="list-style:none;margin:6px 0 0;padding:0;">' + rows + '</ul>';
    var head = '<b style="font-size:13px;color:#0F172A;">' + esc(title) + ' (' + list.length + ')</b>'
      + (note ? '<div style="font-size:12px;color:#64748B;margin-top:2px;">' + esc(note) + '</div>' : '');
    return '<div style="margin:0 0 12px;">' + (list.length > 12
      ? '<details><summary style="cursor:pointer;">' + head + '</summary>' + inner + '</details>'
      : head + inner) + '</div>';
  }
  function notesField(label, hint, required) {
    return '<label style="display:block;margin:4px 0 0;"><span style="display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">'
      + esc(label) + (required ? ' <span style="color:#BE4038;">*</span>' : '')
      + (hint ? ' <span style="font-weight:400;color:#94A3B8;">' + esc(hint) + '</span>' : '') + '</span>'
      + '<textarea data-sl-notes rows="4" maxlength="4000" style="' + AREA + '"></textarea></label>';
  }

  /* ── Lock popup ─────────────────────────────────────────────────── */
  /* opts: { scope: 'user'|'centre'|'agency', targetId, label, agencyId (platform, agency scope), onDone } */
  function openLock(opts) {
    var S = Shell(), A = Api();
    if (!S || !A) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = '<div style="padding:16px;color:#64748B;font-size:13px;">Checking who this reaches…</div>';
    var preview = null;
    var q = '?scope=' + encodeURIComponent(opts.scope) + '&target_id=' + encodeURIComponent(opts.targetId)
      + (opts.agencyId ? '&agency_id=' + encodeURIComponent(opts.agencyId) : '');

    var modal = S.Modal.open({
      title: ICON + ' Lock sign-in' + (opts.label ? ': ' + opts.label : ''),
      body: wrap,
      large: true,
      actions: [
        { label: 'Cancel' },
        { label: 'Lock sign-in', primary: true, busyLabel: 'Locking…', handler: function () {
          if (!preview) return false;
          if (preview.open_lock) { closeModal(modal); openUnlock(preview.open_lock.id, opts.onDone); return; }
          var notes = (wrap.querySelector('[data-sl-notes]') || {}).value || '';
          if (notes.trim().length < 3) {
            toast('⚠️', 'Add a note', 'Say why sign-in is being paused. It goes in the record and the email to admins.', '#DC2626');
            return false;
          }
          var body = { scope: opts.scope, target_id: opts.targetId, notes: notes.trim() };
          if (opts.agencyId) body.agency_id = opts.agencyId;
          return A.post(BASE, body).then(function (r) {
            toast('🚷', 'Sign-in paused', n(r.held, 'person') + ' can\'t sign in'
              + (r.kept ? ' · ' + r.kept + ' kept access' : '') + (r.emailed ? ' · ' + n(r.emailed, 'admin') + ' emailed' : ''), '#B45309');
            changed();
            if (opts.onDone) opts.onDone(r);
          }).catch(function (e) { toast('⚠️', 'Could not lock', e && e.message, '#DC2626'); return false; });
        } }
      ]
    });

    A.get(BASE + '/preview' + q).then(function (p) {
      preview = p;
      if (p.open_lock) {
        wrap.innerHTML = box('#FBF1DC', '#F3D9A4', '#7A4B0C', '<b>' + esc(p.label) + '</b> is already locked, since ' + esc(p.open_lock.locked_at)
          + (p.open_lock.locked_by ? ' by ' + esc(p.open_lock.locked_by) : '') + '.')
          + (p.open_lock.lock_notes ? '<div style="font-size:13px;white-space:pre-wrap;color:#334155;">' + esc(p.open_lock.lock_notes) + '</div>' : '');
        relabel(modal, 'Lock sign-in', 'Open unlock…');
        return;
      }
      var held = p.held || [], kept = p.kept || [], already = p.already || [];
      var what = opts.scope === 'centre'
        ? 'Everyone whose only link to the agency is this centre: its staff, and parents whose children are all here.'
        : opts.scope === 'agency' ? 'Every staff member and parent at this agency. Only KiddieTrac can lift it.'
        : 'This person, at this agency only.';
      wrap.innerHTML =
          '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px;">'
        +   '<span style="font:700 12px/1 system-ui,sans-serif;padding:6px 10px;border-radius:100px;background:#FBE9E6;color:#8F2A23;">' + n(held.length, 'person') + ' can\'t sign in</span>'
        +   (kept.length ? '<span style="font:700 12px/1 system-ui,sans-serif;padding:6px 10px;border-radius:100px;background:#E7F4EC;color:#1E6B47;">' + kept.length + ' keep access</span>' : '')
        +   (already.length ? '<span style="font:700 12px/1 system-ui,sans-serif;padding:6px 10px;border-radius:100px;background:#F1F5F9;color:#475569;">' + already.length + ' already locked</span>' : '')
        + '</div>'
        + box('#F8FAFC', '#E2E8F0', '#334155', esc(what) + ' They are signed out of every device right away and can\'t sign in until it is lifted; app notifications pause too. '
          + 'Emails and texts still reach them, nothing on their records changes, and they are not emailed. '
          + 'The agency admins' + (opts.scope === 'agency' ? '' : ' and directors') + ' get an email with your notes.')
        + (held.length ? '' : box('#FBF1DC', '#F3D9A4', '#7A4B0C', 'Nobody would be locked out right now. The lock still applies to anyone who joins only through this later.'))
        + people('Can\'t sign in', held)
        + people('Keep access', kept, opts.scope === 'centre' ? 'They also have a child at, or work at, another centre, or have an agency-wide role.' : 'Platform admins are never locked.')
        + people('Already locked by another lock', already, 'They stay locked until that lock is lifted too.')
        + notesField('Notes', 'why, and what has to happen before it is lifted', true);
    }).catch(function (e) {
      wrap.innerHTML = box('#FBE9E6', '#F2C9C3', '#8F2A23', esc((e && e.message) || 'Could not check who this reaches.'));
    });
    return modal;
  }

  /* ── Unlock popup ───────────────────────────────────────────────── */
  function openUnlock(lockId, onDone) {
    var S = Shell(), A = Api();
    if (!S || !A) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = '<div style="padding:16px;color:#64748B;font-size:13px;">Loading…</div>';
    var data = null;
    var modal = S.Modal.open({
      title: '🔓 Unlock sign-in',
      body: wrap,
      large: true,
      actions: [
        { label: 'Close' },
        { label: 'Unlock sign-in', primary: true, busyLabel: 'Unlocking…', handler: function () {
          if (!data || !data.lock.open) return;
          var notes = (wrap.querySelector('[data-sl-notes]') || {}).value || '';
          return A.post(BASE + '/' + lockId + '/unlock', { notes: notes.trim() }).then(function (r) {
            toast('🔓', 'Sign-in restored', n(r.restored, 'person') + ' can sign in again'
              + (r.still_locked ? ' · ' + r.still_locked + ' still locked by another lock' : '') + (r.emailed ? ' · ' + n(r.emailed, 'admin') + ' emailed' : ''), '#16A34A');
            changed();
          if (onDone) onDone(r);
          }).catch(function (e) { toast('⚠️', 'Could not unlock', e && e.message, '#DC2626'); return false; });
        } }
      ]
    });
    A.get(BASE + '/' + lockId).then(function (d) {
      data = d;
      var l = d.lock;
      var head = '<div style="display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:13px;margin:0 0 12px;">'
        + '<span style="color:#64748B;">Locked</span><b>' + esc(l.label) + ' <span style="font-weight:500;color:#64748B;">· ' + esc(scopeWord(l.scope)) + '</span></b>'
        + '<span style="color:#64748B;">Since</span><span>' + esc(l.locked_at) + (l.locked_by ? ' by ' + esc(l.locked_by) : '') + '</span>'
        + (l.open ? '' : '<span style="color:#64748B;">Unlocked</span><span>' + esc(l.unlocked_at) + (l.unlocked_by ? ' by ' + esc(l.unlocked_by) : '')
          + (l.restored_count != null ? ' · ' + n(l.restored_count, 'person') + ' restored' : '') + '</span>')
        + '</div>'
        + (l.lock_notes ? '<div style="font-size:12px;font-weight:600;color:#475569;">Notes when locked</div><div style="font-size:13px;white-space:pre-wrap;color:#0F172A;margin:2px 0 12px;">' + esc(l.lock_notes) + '</div>' : '')
        + (l.unlock_notes ? '<div style="font-size:12px;font-weight:600;color:#475569;">Notes when unlocked</div><div style="font-size:13px;white-space:pre-wrap;color:#0F172A;margin:2px 0 12px;">' + esc(l.unlock_notes) + '</div>' : '');
      if (!l.open) {
        wrap.innerHTML = head + people('Held by this lock', d.held || []) + exceptionsHtml(d.exceptions, false);
        relabel(modal, 'Unlock sign-in', null);
        return;
      }
      wrap.innerHTML = head
        + box('#E7F4EC', '#BFE3CD', '#1E6B47', '<b>' + n((d.would_restore || []).length, 'person') + '</b> can sign in again as soon as you unlock.'
          + ((d.still_locked || []).length ? ' <b>' + d.still_locked.length + '</b> stay locked because another lock holds them too.' : ''))
        + people('Can sign in again', d.would_restore || [])
        + people('Stay locked (another lock)', d.still_locked || [], 'Lift that lock too to let them in.')
        + exceptionsHtml(d.exceptions, true)
        + notesField('Notes', 'what changed', false);
      wireExceptions(wrap, d.exceptions, function () { closeModal(modal); openUnlock(lockId, onDone); });
    }).catch(function (e) {
      wrap.innerHTML = box('#FBE9E6', '#F2C9C3', '#8F2A23', esc((e && e.message) || 'Could not open this lock.'));
    });
    return modal;
  }

  /* People let in past this lock one at a time, with an End button while the lock is open. */
  function exceptionsHtml(list, canEnd) {
    if (!list || !list.length) return '';
    return '<div style="margin:0 0 12px;"><b style="font-size:13px;color:#0F172A;">Let in by exception (' + list.filter(function (e) { return e.open; }).length + ')</b>'
      + '<ul style="list-style:none;margin:6px 0 0;padding:0;">' + list.map(function (e) {
        return '<li style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:5px 0;border-bottom:1px solid #F1F5F9;font-size:13px;">'
          + '<span><b>' + esc(e.name) + '</b> <span style="color:#64748B;">· ' + esc(e.at) + (e.by ? ' by ' + esc(e.by) : '') + '</span>'
          + (e.notes ? '<div style="color:#334155;white-space:pre-wrap;">' + esc(e.notes) + '</div>' : '')
          + (e.open ? '' : '<div style="color:#64748B;">Ended ' + esc(e.ended_at || '') + (e.end_notes ? ' · ' + esc(e.end_notes) : '') + '</div>') + '</span>'
          + (e.open && canEnd ? '<button type="button" data-sl-endex="' + e.id + '" style="' + BTN + '">End</button>' : '')
          + '</li>';
      }).join('') + '</ul></div>';
  }
  function wireExceptions(wrap, list, after) {
    (list || []).forEach(function (e) {
      var b = wrap.querySelector('[data-sl-endex="' + e.id + '"]');
      if (b) b.onclick = function () { openEndException(e, after); };
    });
  }

  /* Rename or hide a popup action button by its label (Shell.Modal draws plain buttons). */
  function relabel(modal, from, to) {
    var root = (modal && modal.backdrop) || document;
    var btns = root.querySelectorAll ? root.querySelectorAll('button') : [];
    for (var i = btns.length - 1; i >= 0; i--) {
      if ((btns[i].textContent || '').trim() === from) {
        if (to) btns[i].textContent = to; else btns[i].style.display = 'none';
        return;
      }
    }
  }
  function closeModal(modal) {
    try { if (modal && modal.close) modal.close(); else if (Shell().Modal.close) Shell().Modal.close(); } catch (e) {}
  }

  /* ── One person (Anthony, 2026-11-10: "is there an unlock feature to unlock users individually?") ──
     Their own lock is unlocked on its own. A centre / provider lock is NEVER lifted from a person's
     page (that would let everyone it holds back in): instead they are let in by EXCEPTION, the centre
     staying locked for everyone else. An agency lock is KiddieTrac's and only explained here. */

  /* What this person's state calls for: { label, run } for a button, menu item or the row ⋮. */
  function personAction(s, uid, name, onDone) {
    if (s.platform_admin) return null;
    if (s.own_lock) return { label: '🔓 Unlock sign-in', run: function () { openUnlock(s.own_lock.id, onDone); } };
    if (s.locked && s.lock && s.lock.scope === 'centre') {
      return { label: '✅ Let this person sign in', run: function () { openException(s.lock, uid, name, onDone); } };
    }
    if (s.locked && s.lock) return { label: ICON + ' Locked with the whole agency', run: function () { openAgencyNote(s.lock); } };
    if (s.exception) return { label: ICON + ' End sign-in exception', run: function () { openEndException(s.exception, onDone); } };
    return { label: ICON + ' Lock sign-in', run: function () { openLock({ scope: 'user', targetId: uid, label: name, onDone: onDone }); } };
  }

  /* From a list row: read their state, then open the right popup. */
  function openPerson(uid, name, onDone) {
    var A = Api();
    if (!A) return;
    A.get(BASE + '/status?user_id=' + encodeURIComponent(uid)).then(function (s) {
      var act = personAction(s, uid, name || s.name, onDone);
      if (!act) { toast('ℹ️', 'Never locked', 'A KiddieTrac platform admin cannot be locked.', '#0891A6'); return; }
      act.run();
    }).catch(function (e) { toast('⚠️', 'Could not check sign-in', e && e.message, '#DC2626'); });
  }

  function lockSummary(l) {
    return '<b>' + esc(l.label) + '</b> is locked, since ' + esc(l.locked_at) + (l.locked_by ? ' by ' + esc(l.locked_by) : '') + '.'
      + (l.lock_notes ? '<div style="margin-top:6px;white-space:pre-wrap;">' + esc(l.lock_notes) + '</div>' : '');
  }

  /* Let one person in past a centre lock. */
  function openException(lock, uid, name, onDone) {
    var S = Shell(), A = Api();
    var wrap = document.createElement('div');
    wrap.innerHTML = box('#FBF1DC', '#F3D9A4', '#7A4B0C', lockSummary(lock))
      + box('#F8FAFC', '#E2E8F0', '#334155', '<b>' + esc(name) + '</b> can sign in again while <b>' + esc(lock.label) + '</b> stays locked for everyone else. '
        + 'Their own lock or another centre lock would still keep them out. It ends by itself when the centre is unlocked, or you can end it from their page. '
        + 'The agency admins and that centre\'s directors get an email with your notes.')
      + notesField('Notes', 'why they can sign in', true);
    S.Modal.open({
      title: '✅ Let ' + name + ' sign in', body: wrap, large: true,
      actions: [{ label: 'Cancel' }, { label: 'Let them sign in', primary: true, busyLabel: 'Saving…', handler: function () {
        var notes = (wrap.querySelector('[data-sl-notes]') || {}).value || '';
        if (notes.trim().length < 3) { toast('⚠️', 'Add a note', 'Say why this person can sign in while the centre is locked.', '#DC2626'); return false; }
        return A.post(BASE + '/' + lock.id + '/exceptions', { user_id: uid, notes: notes.trim() }).then(function (r) {
          if (r.still_locked) toast('⚠️', 'Saved, but still locked', name + ' is also kept out by ' + r.still_locked.label + '.', '#B45309');
          else toast('✅', name + ' can sign in', lock.label + ' stays locked for everyone else.' + (r.emailed ? ' ' + n(r.emailed, 'admin') + ' emailed.' : ''), '#16A34A');
          changed();
          if (onDone) onDone(r);
        }).catch(function (e) { toast('⚠️', 'Could not save', e && e.message, '#DC2626'); return false; });
      } }]
    });
  }

  /* Put them back under the centre lock. */
  function openEndException(ex, onDone) {
    var S = Shell(), A = Api();
    var wrap = document.createElement('div');
    wrap.innerHTML = box('#E7F4EC', '#BFE3CD', '#1E6B47', '<b>' + esc(ex.name) + '</b> was let in past the <b>' + esc(ex.lock_label || 'centre') + '</b> lock on '
        + esc(ex.at) + (ex.by ? ' by ' + esc(ex.by) : '') + '.' + (ex.notes ? '<div style="margin-top:6px;white-space:pre-wrap;">' + esc(ex.notes) + '</div>' : ''))
      + box('#F8FAFC', '#E2E8F0', '#334155', 'Ending it locks them out again, unless they now have a link to a centre that is not locked.')
      + notesField('Notes', 'why', false);
    S.Modal.open({
      title: ICON + ' End sign-in exception', body: wrap, large: true,
      actions: [{ label: 'Cancel' }, { label: 'End exception', primary: true, busyLabel: 'Saving…', handler: function () {
        var notes = (wrap.querySelector('[data-sl-notes]') || {}).value || '';
        return A.post(BASE + '/exceptions/' + ex.id + '/end', { notes: notes.trim() }).then(function (r) {
          toast(ICON, 'Exception ended', r.locked_again ? ex.name + ' cannot sign in.' : ex.name + ' can still sign in through another centre.', '#B45309');
          changed();
          if (onDone) onDone(r);
        }).catch(function (e) { toast('⚠️', 'Could not end it', e && e.message, '#DC2626'); return false; });
      } }]
    });
  }

  function openAgencyNote(lock) {
    var wrap = document.createElement('div');
    wrap.innerHTML = box('#FBF1DC', '#F3D9A4', '#7A4B0C', lockSummary(lock))
      + box('#F8FAFC', '#E2E8F0', '#334155', 'The whole agency is locked, so nobody can be let in one at a time. Only KiddieTrac can unlock it.');
    Shell().Modal.open({ title: ICON + ' Locked with the whole agency', body: wrap, actions: [{ label: 'Close' }] });
  }

  function openFor(scope, targetId, label, onDone, agencyId) {
    if (scope === 'user') return openPerson(targetId, label, onDone);
    return openLock({ scope: scope, targetId: targetId, label: label, onDone: onDone, agencyId: agencyId });
  }

  /* ── Hooks for other screens ────────────────────────────────────── */

  /* A user's page: one button that reads their state (lock, unlock, let in, end exception). */
  function userButton(actionRow, user) {
    var A = Api();
    if (!A || !actionRow || !user || !user.id) return;
    var btn = document.createElement('button');
    btn.className = 'kt-btn kt-btn-secondary';
    btn.type = 'button';
    btn.textContent = ICON + ' Sign-in lock…';
    btn.disabled = true;
    actionRow.appendChild(btn);
    var name = user.name || ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
    function paint() {
      A.get(BASE + '/status?user_id=' + encodeURIComponent(user.id)).then(function (s) {
        var act = personAction(s, user.id, name || s.name, paint);
        if (!act) { btn.remove(); return; }
        btn.disabled = false;
        btn.textContent = act.label;
        btn.onclick = act.run;
      }).catch(function () { btn.remove(); });   // not an admin here, or not in this agency
    }
    paint();
  }

  /* The Centres list: one more plain button in the row (kt-row-actions folds it into the ⋮). */
  function centreButton(c, mk) {
    var b = mk ? mk(ICON + ' Sign-in lock…') : document.createElement('button');
    if (!mk) { b.type = 'button'; b.textContent = ICON + ' Sign-in lock…'; }
    b.addEventListener('click', function (e) { e.stopPropagation(); openLock({ scope: 'centre', targetId: c.id, label: c.name }); });
    return b;
  }

  /* ── Locked sign-ins screen ─────────────────────────────────────── */
  function chooseTarget(main, data) {
    var S = Shell(), A = Api();
    var wrap = document.createElement('div');
    var centres = [];
    wrap.innerHTML =
        '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;" data-sl-kinds>'
      +   '<button type="button" data-k="user" style="' + BTN + '">A person</button>'
      +   '<button type="button" data-k="centre" style="' + BTN + '">A centre / provider</button>'
      +   (data.can_lock_agency ? '<button type="button" data-k="agency" style="' + BTN + '">The whole agency</button>' : '')
      + '</div><div data-sl-pick></div>';
    var pick = wrap.querySelector('[data-sl-pick]');
    var modal = S.Modal.open({ title: ICON + ' Lock sign-in', body: wrap, actions: [{ label: 'Cancel' }] });
    function go(o) { closeModal(modal); o.onDone = function () { render(main); }; openLock(o); }
    function kind(k) {
      wrap.querySelectorAll('[data-k]').forEach(function (b) {
        var on = b.getAttribute('data-k') === k;
        b.style.background = on ? '#0F2540' : '#fff'; b.style.color = on ? '#fff' : '#1E293B'; b.style.borderColor = on ? '#0F2540' : '#CBD5E1';
      });
      if (k === 'agency') { go({ scope: 'agency', targetId: data.agency_id, agencyId: data.agency_id, label: data.agency_name }); return; }
      if (k === 'centre') {
        pick.innerHTML = '<div style="color:#64748B;font-size:13px;">Loading…</div>';
        A.get(BASE + '/targets').then(function (t) {
          centres = t.centres || [];
          pick.innerHTML = centres.length ? centres.map(function (c) {
            return '<button type="button" data-c="' + c.id + '" style="' + BTN + 'display:block;width:100%;text-align:left;margin:0 0 6px;">' + esc(c.name) + '</button>';
          }).join('') : '<div style="color:#64748B;font-size:13px;">No centres.</div>';
          pick.querySelectorAll('[data-c]').forEach(function (b) {
            b.onclick = function () { go({ scope: 'centre', targetId: +b.getAttribute('data-c'), label: b.textContent }); };
          });
        });
        return;
      }
      pick.innerHTML = '<input data-sl-q placeholder="Type a name or email" style="' + INPUT + '"><div data-sl-res style="margin-top:8px;"></div>';
      var inp = pick.querySelector('[data-sl-q]'), res = pick.querySelector('[data-sl-res]'), timer = null;
      inp.focus();
      inp.oninput = function () {
        clearTimeout(timer);
        var q = inp.value.trim();
        if (q.length < 2) { res.innerHTML = ''; return; }
        timer = setTimeout(function () {
          A.get(BASE + '/targets?q=' + encodeURIComponent(q)).then(function (t) {
            var list = t.people || [];
            res.innerHTML = list.length ? list.map(function (p) {
              return '<button type="button" data-u="' + p.id + '" data-name="' + esc(p.name) + '" style="' + BTN + 'display:flex;justify-content:space-between;width:100%;margin:0 0 6px;">'
                + '<span>' + esc(p.name) + '</span><span style="color:#64748B;font-weight:500;">' + esc(p.roles) + '</span></button>';
            }).join('') : '<div style="color:#64748B;font-size:13px;">Nobody at this agency matches.</div>';
            res.querySelectorAll('[data-u]').forEach(function (b) {
              // Their state decides the popup: lock, unlock their own lock, or let in past a centre lock.
              b.onclick = function () { closeModal(modal); openPerson(+b.getAttribute('data-u'), b.getAttribute('data-name'), function () { render(main); }); };
            });
          }).catch(function () {});
        }, 250);
      };
    }
    wrap.querySelectorAll('[data-k]').forEach(function (b) { b.onclick = function () { kind(b.getAttribute('data-k')); }; });
    kind('user');
  }

  async function render(main) {
    var A = Api();
    main.setAttribute('data-kt-pretty', '1');
    var head = '<div class="kt-page-hero"><h2>' + ICON + ' Locked sign-ins</h2><p>Pause sign-in for a person, a centre or provider, and lift it when you are ready. '
      + 'A centre lock never keeps out a parent who has a child at another centre.</p></div>';
    if (!main.querySelector('[data-sl-root]')) main.innerHTML = '<div class="kt-section" data-sl-root>' + head + '<p style="color:#64748B;font-size:13px;">Loading…</p></div>';
    var data;
    try { data = await A.get(BASE); }
    catch (e) {
      main.innerHTML = '<div class="kt-section" data-sl-root>' + head + box('#FEF3C7', '#FDE68A', '#92400E', esc((e && e.message) || 'Only an agency admin can see locked sign-ins.')) + '</div>';
      return;
    }
    var list = data.locks || [];
    var open = list.filter(function (l) { return l.open; }).length;
    var rows = list.length ? list.map(function (l) {
      return '<tr>'
        + '<td><b style="font-size:13px;">' + esc(l.label) + '</b><div style="font-size:12px;color:#64748B;">' + esc(scopeWord(l.scope)) + '</div></td>'
        + '<td style="white-space:nowrap;font-size:13px;">' + esc(l.locked_at) + '<div style="font-size:12px;color:#64748B;">' + esc(l.locked_by || '') + '</div></td>'
        + '<td style="font-size:12.5px;color:#334155;max-width:340px;">' + esc((l.lock_notes || '').slice(0, 140)) + ((l.lock_notes || '').length > 140 ? '…' : '') + '</td>'
        + '<td>' + (l.open
          ? '<span style="font:700 11px/1 system-ui,sans-serif;padding:4px 9px;border-radius:100px;background:#FBE9E6;color:#8F2A23;white-space:nowrap;">Locked · ' + n(l.held_count, 'person') + '</span>'
            + (l.exception_count ? '<div style="font-size:12px;color:#1E6B47;margin-top:4px;">' + l.exception_count + ' let in by exception</div>' : '')
          : '<span style="font:700 11px/1 system-ui,sans-serif;padding:4px 9px;border-radius:100px;background:#E7F4EC;color:#1E6B47;white-space:nowrap;">Unlocked ' + esc(l.unlocked_at || '') + '</span>') + '</td>'
        + '<td style="text-align:right;"><button type="button" data-sl-open="' + l.id + '" style="' + BTN + '">' + (l.open ? 'Unlock…' : 'View') + '</button></td>'
        + '</tr>';
    }).join('') : '<tr><td colspan="5" style="padding:28px;text-align:center;color:#64748B;">Nobody is locked. Locks from the last 90 days show here.</td></tr>';

    main.innerHTML = '<div class="kt-section" data-sl-root>' + head
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 14px;">'
      +   '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;padding:10px 16px;min-width:130px;">'
      +     '<div style="font:600 24px/1 ui-monospace,monospace;color:' + (open ? '#BE4038' : '#1E8E60') + ';">' + open + '</div>'
      +     '<div style="font-size:12px;color:#64748B;margin-top:5px;">Locked now</div></div>'
      +   '<div style="margin-left:auto;"><button type="button" data-sl-new style="' + BTN + 'background:#0F2540;border-color:#0F2540;color:#fff;">' + ICON + ' Lock sign-in…</button></div>'
      + '</div>'
      + '<div class="kt-card"><table><thead><tr><th>Locked</th><th>When</th><th>Notes</th><th>Status</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';

    main.querySelector('[data-sl-new]').onclick = function () { chooseTarget(main, data); };
    main.querySelectorAll('[data-sl-open]').forEach(function (b) {
      b.onclick = function () { openUnlock(+b.getAttribute('data-sl-open'), function () { render(main); }); };
    });
    var m = /[?&]id=(\d+)/.exec(location.hash || '');
    if (m) {
      try { history.replaceState(null, '', location.pathname + location.search + '#signin-locks'); } catch (e) {}
      openUnlock(+m[1], function () { render(main); });
    }
  }

  /* ── Status everywhere (Anthony, 2026-11-10: "two users are now locked but the status on those user
     accounts didn't change"). A lock leaves the account status 'active' on purpose, so every place
     that shows a person's state asks here and shows 🚷 beside it. One request per screen: ids asked
     for in the same tick are batched; answers are kept 30s and dropped on any lock change. */
  var _known = {}, _when = {}, _queue = {}, _timer = null, _waiters = [];
  function forgetLocks() { _known = {}; _when = {}; }
  function lockedPeople(ids) {
    var now = Date.now(), want = [];
    (ids || []).forEach(function (id) { id = +id; if (id && !(_when[id] > now - 30000)) want.push(id); });
    if (!want.length) return Promise.resolve(_known);
    return new Promise(function (resolve) {
      want.forEach(function (id) { _queue[id] = true; });
      _waiters.push(resolve);
      if (_timer) return;
      _timer = setTimeout(function () {
        var batch = Object.keys(_queue), waiters = _waiters;
        _queue = {}; _waiters = []; _timer = null;
        var A = Api();
        var done = function () { waiters.forEach(function (w) { w(_known); }); };
        if (!A) return done();
        A.get(BASE + '/people?ids=' + batch.join(',')).then(function (r) {
          var t = Date.now(), map = (r && r.locked) || {};
          batch.forEach(function (id) { _when[id] = t; if (map[id]) _known[id] = map[id]; else delete _known[id]; });
        }).catch(function () {}).then(done);
      }, 0);
    });
  }
  function lockTip(l) {
    return 'Sign-in paused: ' + (l.scope === 'user' ? 'their own lock' : l.label) + ', since ' + l.since + '. Signed out; app notifications paused. Emails and texts still reach them.';
  }
  /* mode 'replace': the element becomes the 🚷 pill (a "✓ Signs in" pill); 'append': a 🚷 note is added after its text. */
  function markLocked(el, uid, mode) {
    if (!el || !uid) return;
    lockedPeople([uid]).then(function (map) {
      var l = map[+uid];
      if (!l || !el.isConnected && !el.parentNode) return;
      if (mode === 'append') {
        var s = document.createElement('span');
        s.style.cssText = 'color:#991B1B;font-weight:700;';
        s.textContent = ' · 🚷 Sign-in locked';
        s.setAttribute('data-kttip', lockTip(l));
        el.appendChild(s);
      } else {
        el.textContent = '🚷 Sign-in locked';
        el.style.color = '#991B1B'; el.style.background = '#FEE2E2';
        el.setAttribute('data-kttip', lockTip(l));
      }
    });
  }
  // Any lock, unlock or exception changes who is locked: drop what we know (changed() fires it).
  document.addEventListener('kt:signin-lock-changed', forgetLocks);

  KT.signinLock = { openLock: openLock, openUnlock: openUnlock, openFor: openFor, openPerson: openPerson,
    userButton: userButton, centreButton: centreButton, lockedPeople: lockedPeople, markLocked: markLocked, lockTip: lockTip,
    forget: forgetLocks };

  function register() {
    var S = Shell();
    if (!S || !S.registerScreen) return false;
    ['agency_admin', 'platform_admin'].forEach(function (role) { S.registerScreen(role + ':signin-locks', render); });
    return true;
  }
  if (!register()) document.addEventListener('DOMContentLoaded', register, { once: true });
})(window);
