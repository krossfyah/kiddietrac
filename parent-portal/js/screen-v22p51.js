/* v22p51 — all new screens in one module, registered via shim.
   Pattern: each render function takes the main container element.
   Uses window.KT.Api and window.KT.Dom helpers.
*/
(function (window) {
  'use strict';
  const Api = window.KT && window.KT.Api;
  const Dom = window.KT && window.KT.Dom;
  if (!Api || !Dom) { console.warn('v22p51: KT.Api / KT.Dom unavailable'); return; }

  const el = (tag, attrs, children) => Dom.el(tag, attrs || {}, children);
  const html = (s) => { const d = document.createElement('div'); d.innerHTML = s; return d; };

  // ============================ Time-off ============================
  async function renderTimeOff(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    // No status filter. Asking only for pending meant a request disappeared from this
    // screen the instant it was decided, so nobody could see what had been agreed to —
    // or who agreed to it.
    const [mine, all, hrMe] = await Promise.all([
      Api.get('/time-off/mine').catch(() => ({ data: [] })),
      isStaffOrAdmin() ? Api.get('/admin/time-off').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
      hrOn() ? Api.get('/hr/me').catch(() => null) : Promise.resolve(null),
    ]);
    HR_ME = hrMe;
    /* REPLACED WHILE LOADING (2026-11-08, ticket #240: Safia tapped Deny on the phone app and got "Cannot set
       properties of null"). The shell gives each render a fresh #appMain; if the screen was redrawn while this one
       was still fetching (the reload after Deny, a live refresh), this render's node is no longer on the page and
       document.getElementById found nothing. A stale render now stops, and its lookups stay inside its own node. */
    if (!main.isConnected) return;   // replaced while loading: the newer screen draws itself (ticket #240)
    const allRows = all.data || [];
    // KiddieTrac HR: leave that needed no approval is already approved but waits here "To review" until a manager clears it.
    const pending = { data: allRows.filter(r => (r.status || 'pending').toLowerCase() === 'pending' || torReview(r)) };
    const decided = { data: allRows.filter(r => (r.status || 'pending').toLowerCase() !== 'pending' && !torReview(r))
      .sort((a, b) => String(b.decided_at || '').localeCompare(String(a.decided_at || ''))).slice(0, 50) };
    main.innerHTML = '';
    main.appendChild(html(`
      <!-- padding stays 24px. kt-consistency-polish.css normalises every wrapper
           matching [style*="padding:24px"] to "14px 24px !important" portal-wide, so
           this inset is the house treatment for this wrapper family, not a quirk of
           this screen -- and the substring match means "24px 0" would not escape it
           anyway. -->
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <h2 style="margin:0 0 16px;color:#1F6080;">Time off</h2>
        <button id="tor-new" class="btn btn-primary" style="margin-top:6px;${isMobile() ? 'width:100%;' : ''}">🌴 Request time off</button>
        ${hrStrip()}
        <h3 style="margin-top:32px;font-size:16px;color:#374151;">Your requests</h3>
        <div id="tor-mine"></div>
        ${isStaffOrAdmin() ? '<h3 style="margin-top:32px;font-size:16px;color:#374151;">Team requests</h3>'
          + '<div id="tor-tabs" style="display:flex;gap:6px;margin:10px 0 4px;flex-wrap:wrap;"></div>'
          + '<div id="tor-team"></div>' : ''}
      </div>`).firstElementChild);

    renderTorList(main.querySelector('#tor-mine'), mine.data || [], false);
    if (isStaffOrAdmin()) {
      renderTorTabs(pending.data || [], decided.data || []);
    }
    main.querySelector('#tor-new').onclick = () => openTorModal();
  }
  function isMobile() { return window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native'); }
  /* KIDDIETRAC HR (2026-11-27): leave balances on this screen when the HR add-on is on. The balance is the
     server's (the leave ledger); this only shows it. Old requests keep their own type and are never changed. */
  var HR_ME = null;
  function hrOn() {
    try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; return (u.hidden_screens || []).indexOf('hr') < 0; } catch (e) { return false; }
  }
  function hrAmt(v, unit) { v = Math.round((+v || 0) * 100) / 100; return v + ' ' + (unit === 'hours' ? (v === 1 ? 'hour' : 'hours') : (v === 1 ? 'day' : 'days')); }
  function hrStrip() {
    var b = (HR_ME && HR_ME.balances || []).filter(function (x) { return x.tracked; });
    if (!b.length) return '';
    return '<div id="tor-balances" style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0;">' + b.map(function (x) {
      return '<div style="background:#fff;border:1px solid #E2E8F0;border-left:4px solid ' + escapeHtml(x.colour || '#64748B') + ';border-radius:10px;padding:7px 12px;min-width:120px;">'
        + '<div style="font-size:11.5px;font-weight:800;color:#475569;" data-no-i18n>' + escapeHtml(x.name) + '</div>'
        + '<div style="font-size:16px;font-weight:800;color:' + (x.balance < 0 ? '#B91C1C' : '#0F172A') + ';">' + escapeHtml(hrAmt(x.balance, x.unit)) + '</div>'
        + (x.pending ? '<div style="font-size:11.5px;color:#64748B;">' + escapeHtml(hrAmt(x.pending, x.unit)) + ' pending</div>' : '') + '</div>';
    }).join('') + '</div>';
  }
  function torReview(r) { return !!(+r.review_pending) && (r.status || '').toLowerCase() === 'approved'; }
  /* KIDDIETRAC HR PHASE 4 (2026-11-27): leave clashes. The server says how many others at the centre are off or have
     asked (clash_others) and whether approving goes below the centre's minimum staff (clash_short). Approvers see the
     names in the Approve popup; the person asking sees a count only. */
  function torClash(r) {
    if ((r.status || '').toLowerCase() !== 'pending') return '';
    var bits = [];
    if (+r.clash_others) bits.push(r.clash_others + ' other' + (+r.clash_others === 1 ? '' : 's') + ' off or asking');
    if (r.clash_short) bits.push('below minimum staff');
    return bits.length ? '⚠️ ' + bits.join(' · ') : '';
  }
  function torDay(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]) : (d || ''); }
  function clashHtml(c, names) {
    if (!c || !c.centres || !c.centres.length) return '';
    var out = '';
    c.centres.forEach(function (x) {
      var others = names ? (x.others || []) : [];
      if (c.warn !== false && (names ? others.length : x.others_count)) {
        out += '<div style="font-weight:800;margin-bottom:3px;">Also away at <span data-no-i18n>' + torEsc(x.centre_name || 'the centre') + '</span></div>'
          + (names ? others.map(function (o) {
            return '<div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;padding:2px 0;"><span><b data-no-i18n>' + torEsc(o.name) + '</b> · <span data-no-i18n>' + torEsc(o.type) + '</span>' + (o.part_day ? ' (part of a day)' : '') + '</span>'
              + '<span style="white-space:nowrap;">' + torEsc(o.when) + ' · ' + (o.status === 'approved' ? 'approved' : 'asked') + '</span></div>';
          }).join('') : '<div style="font-size:12.5px;">' + x.others_count + ' other' + (x.others_count === 1 ? ' person is' : ' people are') + ' already off or asking on some of these days.</div>');
      }
      if (x.short_days && x.short_days.length) {
        out += '<div style="font-weight:800;margin:6px 0 3px;color:#B91C1C;">Below the minimum of ' + x.min_staff + ' staff</div><div style="font-size:12.5px;">'
          + x.short_days.slice(0, 8).map(function (d) { return torEsc(torDay(d.date)) + ': ' + d.working + ' working'; }).join(' · ')
          + (x.short_days.length > 8 ? ' and ' + (x.short_days.length - 8) + ' more' : '') + '</div>'
          + (names && c.min_mode === 'block' ? '<div style="font-size:12.5px;margin-top:3px;">Your agency does not allow approving this: arrange cover first, or decline.</div>' : '');
      }
    });
    return out ? '<div data-clash style="background:#FFFBEB;border:1px solid #FCD34D;color:#78350F;border-radius:10px;padding:9px 12px;font-size:13px;line-height:1.45;margin:0 0 10px;">' + out + '</div>' : '';
  }
  var REVIEW_PILL = '<span style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:#FFFBEB;color:#B45309;font-size:11.5px;font-weight:800;">To review</span>';
  async function markReviewed(id) {
    try { await Api.post('/admin/time-off/' + id + '/reviewed', {}); if (KT.toast) KT.toast('✅', 'Saved', 'Marked as reviewed.', '#16A34A'); }
    catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not mark it reviewed', (e && e.data && e.data.message) || 'Please try again.', '#DC2626'); return; }
    torReload();
  }
  function hrLine(r) {
    if (r.leave_balance == null) return '';
    var u = r.leave_unit, st = (r.status || 'pending').toLowerCase();
    return (r.leave_amount != null ? hrAmt(r.leave_amount, u) + ' · ' : '')
      + (st === 'pending' ? 'balance ' + hrAmt(r.leave_balance, u) + ', ' + hrAmt(r.leave_balance_after, u) + ' after this' : 'balance now ' + hrAmt(r.leave_balance, u))
      + (st === 'pending' && hrMore(r) ? ', ' + hrAmt(r.leave_balance_projected, u) + ' after all pending' : '');
  }
  /* HR4B9 (2026-11-27): other requests of the same type are waiting too, so the balance after ALL of them is shown;
     and a closure changed after this was booked (leave_recheck): the amount may be wrong, nothing was changed. */
  function hrMore(r) { return r.leave_pending_all != null && r.leave_amount != null && (+r.leave_pending_all - +r.leave_amount) > 0.005; }
  var TOR_ROWS = {};
  function hrRecheckText(r) { var c = r.leave_recheck; return c ? '⚠️ Amount may be wrong: counts ' + hrAmt(c.fresh, c.unit) + ' now, booked ' + hrAmt(c.booked, c.unit) + '. Review it.' : ''; }
  function hrRecheckHtml(r, isApprover) {
    if (!isApprover || !r.leave_recheck || torOwn(r)) return '';
    return '<div style="font-size:11.5px;font-weight:700;color:#B45309;">' + escapeHtml(hrRecheckText(r)) + ' <a href="#time-off?tab=leavemaths" style="color:#0E7490;">Review</a></div>';
  }
  var TOR_META = {
    vacation:    { icon: '🌴', label: 'Vacation' },
    sick:        { icon: '🤒', label: 'Sick leave' },
    personal:    { icon: '🙋', label: 'Personal' },
    bereavement: { icon: '🕊️', label: 'Bereavement' },
    jury:        { icon: '⚖️', label: 'Jury duty' },
    other:       { icon: '🗓️', label: 'Other' },
  };
  function torMeta(t) { return TOR_META[String(t || '').toLowerCase()] || { icon: '🗓️', label: (t || 'Time off') }; }
  /* Who decided, and when. KT.fmtDateTime renders in the AGENCY timezone; this file's own
     fmtDate does not, and a decision timestamp shown in the wrong zone is exactly the
     complaint that started this. Falls back only if kt-tz.js has not loaded. */
  function decidedStamp(ts) {
    if (!ts) return '';
    if (window.KT && KT.fmtDateTime) return KT.fmtDateTime(ts);
    return fmtDate(ts);
  }
  function decidedText(r) {
    var st = (r.status || 'pending').toLowerCase();
    if (st === 'pending') return '';
    var verb = st === 'approved' ? 'Approved' : 'Declined';
    var who = r.decided_by_name || '';
    var when = decidedStamp(r.decided_at);
    return verb + (who ? ' by ' + who : '') + (when ? ' · ' + when : '');
  }
  function decidedCell(r) {
    var st = (r.status || 'pending').toLowerCase();
    if (st === 'pending') return '<span style="color:#94A3B8;">—</span>';
    var who = r.decided_by_name ? escapeHtml(r.decided_by_name) : '<span style="color:#94A3B8;">(unknown)</span>';
    var when = decidedStamp(r.decided_at);
    return who + (when ? '<div style="font-size:11.5px;color:#94A3B8;">' + escapeHtml(when) + '</div>' : '');
  }
  /* Mirrors TimeOffController::whenPhrase so the screen, the calendar and every email
     describe a request the same way. "Sep 3 – Sep 3" is what an afternoon off looked
     like when only the dates were known. */
  function torWhen(r) {
    var sameDay = String(r.start_at || '').slice(0, 10) === String(r.end_at || '').slice(0, 10);
    if (!r.all_day && r.start_time) {
      return fmtDate(r.start_at) + ', ' + torTime(r.start_time) + ' – ' + torTime(r.end_time);
    }
    return sameDay ? fmtDate(r.start_at) : fmtDate(r.start_at) + ' – ' + fmtDate(r.end_at);
  }
  function torTime(t) {
    if (!t) return '';
    var p = String(t).split(':');
    var h = parseInt(p[0], 10), mnt = p[1] || '00';
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + mnt + ' ' + ap;
  }

  /* Taking a request back. Allowed while it is pending AND after it has been approved —
     plans change, and withdrawing an approved one also removes the closure it created
     and tells the people who were told about it. */
  async function withdrawTor(r) {
    var wasApproved = (r.status || '').toLowerCase() === 'approved';
    if (!(await window.KT.confirm({
      title: wasApproved ? 'Withdraw this approved time off?' : 'Withdraw this request?',
      description: wasApproved
        ? 'The calendar goes back to normal and everyone who was told will be notified that you will be working as usual.'
        : 'The request is cancelled and nobody needs to review it.',
      okLabel: 'Withdraw',
    }))) return;
    try {
      await Api.post('/time-off/' + r.id + '/cancel', {});
      torReload();
    } catch (e) {
      alert((e && e.message) || 'Could not withdraw that request.');
    }
  }
  function canWithdraw(r) {
    var st = (r.status || 'pending').toLowerCase();
    return st === 'pending' || st === 'approved';
  }

  function torStatusColors(st) {
    return st === 'approved' ? { bg: '#DCFCE7', fg: '#15803D' }
      : st === 'denied' ? { bg: '#FEE2E2', fg: '#B91C1C' }
      : { bg: '#FEF3C7', fg: '#B45309' };
  }
  async function actOnTor(id, status) {
    if (status === 'approved') { return approveTor(id); }
    await Api.patch('/admin/time-off/' + id, { status: status });
    torReload();
  }

  /* APPROVE, AND SAY WHETHER THE CENTRE CLOSES (2026-10-13, Anthony: "when approving time off
     requests for educators add option to flag this educator/centre etc closed which will
     trigger emails out to parents, admins/directors"). The server's own rule only closes a
     centre when the person is its ONLY staff member, and it cannot tell that an admin who
     holds an educator role at a home daycare is not actually there — so the approver
     chooses. Pre-ticked when the rule would close it anyway. */
  function torEsc(v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function torReload() { renderTimeOff(document.getElementById('appMain') || document.querySelector('main')); }
  async function approveTor(id) {
    var o = null, cl = null;
    try { o = await Api.get('/admin/time-off/' + id + '/closure-options'); } catch (e) {}
    if (HR_ME) { try { cl = await Api.get('/hr/time-off/' + id + '/clash'); } catch (e) {} }   // HR Phase 4: leave clashes
    var clash = clashHtml(cl, true);
    /* HR4B9: the balance after this and after every pending request, and a booked amount a closure change made wrong. */
    var rr = TOR_ROWS[id] || {}, hrBox = '';
    if (rr.leave_balance != null) hrBox += '<div style="background:#F0F9FF;border:1px solid #BAE6FD;color:#0C4A6E;border-radius:10px;padding:9px 12px;font-size:13px;line-height:1.5;margin:0 0 10px;"><b data-no-i18n>' + torEsc(rr.leave_type_name || '') + '</b>: '
      + torEsc(hrAmt(rr.leave_balance, rr.leave_unit)) + ' now, ' + torEsc(hrAmt(rr.leave_balance_after, rr.leave_unit)) + ' after this'
      + (hrMore(rr) ? ', <b' + (rr.leave_balance_projected < 0 ? ' style="color:#B91C1C;"' : '') + '>' + torEsc(hrAmt(rr.leave_balance_projected, rr.leave_unit)) + ' after all pending requests</b>' : '') + '</div>';
    if (rr.leave_recheck) hrBox += '<div data-recheck style="background:#FFFBEB;border:1px solid #FCD34D;color:#78350F;border-radius:10px;padding:9px 12px;font-size:13px;line-height:1.45;margin:0 0 10px;">'
      + '<div style="font-weight:800;margin-bottom:4px;">The amount may be wrong</div><div data-no-i18n style="margin-bottom:6px;">' + torEsc(rr.leave_recheck.cause || 'A closure changed after this was booked.') + '</div>'
      + '<label style="display:flex;gap:8px;align-items:center;cursor:pointer;"><input type="radio" name="tor-rc" value="correct" checked> Use ' + torEsc(hrAmt(rr.leave_recheck.fresh, rr.leave_recheck.unit)) + ' (counts now)</label>'
      + '<label style="display:flex;gap:8px;align-items:center;cursor:pointer;"><input type="radio" name="tor-rc" value="keep"> Keep ' + torEsc(hrAmt(rr.leave_recheck.booked, rr.leave_recheck.unit)) + ' (as booked)</label></div>';
    if (hrBox && (hrMore(rr) || rr.leave_recheck || (o && o.centre_id) || clash)) clash = hrBox + clash;   // a plain one-click approve stays one click
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if ((!o || !o.centre_id) && !clash || !M) {
      await Api.patch('/admin/time-off/' + id, { status: 'approved' });
      return torReload();
    }
    var hasCentre = !!(o && o.centre_id);
    o = o || {};
    var closeDefault = hasCentre && !o.already_closed && o.sole_staff && o.all_day;
    var box = document.createElement('div');
    var parents = o.parents + ' parent' + (o.parents === 1 ? '' : 's');
    box.innerHTML = (o.when ? '<div style="font-size:13.5px;color:#334155;margin-bottom:10px;">' + torEsc(o.when) + (hasCentre ? ' &middot; <b>' + torEsc(o.centre_name || 'their centre') + '</b>' : '') + '</div>' : '')
      + clash
      + (!hasCentre ? '' : o.already_closed
        ? '<div style="background:#F1F5F9;border-radius:10px;padding:10px 12px;font-size:13px;color:#475569;">The centre is already closed for these dates, so nothing more is needed.</div>'
        : '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;margin-bottom:8px;cursor:pointer;">'
          + '<input type="radio" name="tor-close" value="none"' + (closeDefault ? '' : ' checked') + ' style="margin-top:3px;">'
          + '<span><b>Someone covers</b> &mdash; ' + torEsc(o.centre_name || 'the centre') + ' stays open<br><span style="font-size:12.5px;color:#64748B;">'
          + (o.other_staff && o.other_staff.length ? 'Also at this centre: ' + torEsc(o.other_staff.join(', ')) + '.' : 'Nobody else works at this centre.') + '</span></span></label>'
          + '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1.5px solid #FCD34D;background:#FFFBEB;border-radius:10px;cursor:pointer;">'
          + '<input type="radio" name="tor-close" value="centre"' + (closeDefault ? ' checked' : '') + ' style="margin-top:3px;">'
          + '<span><b>Close ' + torEsc(o.centre_name || 'the centre') + '</b> for these dates<br><span style="font-size:12.5px;color:#92400E;">Emails ' + parents
          + ' now, and admins and directors. Closure reminders follow on your usual schedule.</span></span></label>')
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin:12px 0 4px;">Note back to them (optional)</label>'
      + '<textarea data-note rows="2" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:9px;padding:8px 10px;font-family:inherit;font-size:13.5px;"></textarea>'
      + '<div data-out style="font-size:13px;color:#B91C1C;margin-top:6px;"></div>';
    M.open({
      title: 'Approve time off', body: box,
      actions: [
        { label: 'Cancel' },
        { label: 'Approve', primary: true, busyLabel: 'Approving…', handler: function () {
            var pick = box.querySelector('input[name="tor-close"]:checked');
            var payload = { status: 'approved', close: pick ? pick.value : 'none' };
            var note = (box.querySelector('[data-note]').value || '').trim();
            if (note) payload.decision_notes = note;
            if (!hasCentre) payload.close = 'auto';
            if (payload.close === 'centre') payload.close_centre_id = o.centre_id;
            var rcPick = box.querySelector('input[name="tor-rc"]:checked');   // HR4B9: settle the amount first
            var first = rcPick && rr.leave_recheck ? Api.post('/hr/leave-rechecks/' + rr.leave_recheck.id + '/resolve', { outcome: rcPick.value }) : Promise.resolve();
            return first.then(function () { return Api.patch('/admin/time-off/' + id, payload); }).then(function (r) {
              if (window.KT && KT.toast) KT.toast('✅', 'Approved', r && r.closure_created ? (o.centre_name || 'Centre') + ' marked closed; parents emailed.' : '', '#16A34A');
              torReload();
            }).catch(function (e) { box.querySelector('[data-out]').textContent = (e && e.data && e.data.message) || (e && e.message) || 'Could not approve.'; return false; });
          } },
      ],
    });
  }

  /* Close the centre for leave that is already approved (the same emails as at approval). */
  async function closeTor(r) {
    var msg = 'Mark ' + (r.close_centre_name || 'the centre') + ' closed for ' + torWhen(r) + '? Parents, admins and directors are emailed now.';
    var ok = window.KT && KT.confirm ? await KT.confirm(msg, { okLabel: 'Close centre', tone: 'default' }) : window.confirm(msg);
    if (!ok) return;
    try {
      var res = await Api.post('/admin/time-off/' + r.id + '/close', { centre_id: r.close_centre_id });
      if (window.KT && KT.toast) KT.toast('🔒', 'Centre closed', (res && res.parents != null ? res.parents + ' parent(s) emailed.' : ''), '#16A34A');
    } catch (e) {
      if (window.KT && KT.toast) KT.toast('⚠️', 'Not closed', (e && e.message) || '', '#B91C1C'); else alert((e && e.message) || 'Could not close.');
    }
    torReload();
  }
  /* NOBODY DECIDES THEIR OWN TIME OFF (2026-11-27). A director's or admin's own request also sits in Team requests;
     it gets no Approve / Deny / Reviewed there (the server refuses them too), only Withdraw. */
  function torMyId() {
    try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; return +u.id || 0; } catch (e) { return 0; }
  }
  function torOwn(r) { var me = torMyId(); return !!me && +r.user_id === me; }
  function torCanClose(r, isApprover) {
    return isApprover && (r.status || '').toLowerCase() === 'approved' && r.close_centre_id && !r.closed_for_dates;
  }
  function torCard(r, isApprover) {
    var status = (r.status || 'pending').toLowerCase();
    var sc = torStatusColors(status);
    var meta = torMeta(r.request_type);
    var card = el('div', { style: 'background:#fff;border:1px solid #EDF1F6;border-radius:15px;padding:14px;margin-bottom:11px;box-shadow:0 2px 8px -3px rgba(15,23,42,.12);' });
    card.setAttribute('data-tor-row', r.id);
    var head = el('div', { style: 'display:flex;align-items:center;gap:11px;' });
    head.appendChild(el('span', { style: 'flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:20px;' }, meta.icon));
    var mid = el('div', { style: 'flex:1;min-width:0;' });
    mid.appendChild(el('div', { style: 'font-weight:800;font-size:15px;color:#0F172A;' }, r.leave_type_name || meta.label));
    if (isApprover) mid.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, r.user_name || 'Team member'));
    head.appendChild(mid);
    head.appendChild(el('span', { style: 'flex:0 0 auto;font-size:11.5px;font-weight:800;text-transform:capitalize;padding:4px 11px;border-radius:20px;background:' + sc.bg + ';color:' + sc.fg + ';' }, status));
    card.appendChild(head);
    card.appendChild(el('div', { style: 'font-size:13.5px;font-weight:600;color:#334155;margin-top:11px;padding-top:11px;border-top:1px solid #F1F5F9;' }, '📅 ' + torWhen(r)));
    TOR_ROWS[r.id] = r;   // HR4B9
    if (hrLine(r)) card.appendChild(el('div', { style: 'font-size:12.5px;font-weight:700;color:' + ((r.leave_balance_after < 0 || (hrMore(r) && r.leave_balance_projected < 0)) ? '#B91C1C' : '#0E7490') + ';margin-top:6px;' }, hrLine(r)));
    if (isApprover && r.leave_recheck && !torOwn(r)) { var rcd = el('div', { style: 'margin-top:6px;' }); rcd.innerHTML = hrRecheckHtml(r, true); card.appendChild(rcd); }
    if (isApprover && torClash(r)) card.appendChild(el('div', { style: 'font-size:12.5px;font-weight:700;color:#B45309;margin-top:6px;' }, torClash(r)));
    if (r.reason) card.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.45;' }, r.reason));
    // Mobile renders cards, not the table, so the decision has to be added here too.
    var decidedLine = decidedText(r);
    if (decidedLine) {
      card.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:8px;padding-top:8px;border-top:1px solid #F1F5F9;' }, decidedLine));
    }
    if (torReview(r)) {
      var rv = el('div', { style: 'margin-top:10px;' });
      rv.innerHTML = REVIEW_PILL + '<span style="font-size:12px;color:#64748B;margin-left:6px;">No approval needed, already taken off the balance.</span>';
      card.appendChild(rv);
      if (isApprover && !torOwn(r)) {
        var rb = el('button', { type: 'button', class: 'kt-btn kt-btn-secondary', style: 'width:100%;margin-top:10px;justify-content:center;' }, 'Reviewed');
        rb.onclick = function () { markReviewed(r.id); };
        card.appendChild(rb);
      }
    }
    if (isApprover && torOwn(r) && (status === 'pending' || torReview(r))) {
      card.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:10px;' }, 'Your own request: another director or an agency admin decides it.'));
    }
    if (isApprover && status === 'pending' && !torOwn(r)) {
      var actions = el('div', { style: 'display:flex;gap:8px;margin-top:12px;' });
      var appr = el('button', { type: 'button', style: 'flex:1;background:#16A34A;color:#fff;border:0;padding:11px;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;' }, 'Approve');
      var deny = el('button', { type: 'button', style: 'flex:1;background:#fff;color:#B91C1C;border:1.5px solid #FCA5A5;padding:11px;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;' }, 'Deny');
      appr.onclick = function () { actOnTor(r.id, 'approved'); };
      deny.onclick = function () { actOnTor(r.id, 'denied'); };
      actions.appendChild(appr); actions.appendChild(deny);
      card.appendChild(actions);
    }
    if (r.closed_for_dates && status === 'approved') {
      card.appendChild(el('div', { style: 'font-size:12px;font-weight:700;color:#92400E;margin-top:8px;' }, '🔒 ' + (r.close_centre_name || 'Centre') + ' closed for these dates'));
    }
    if (torCanClose(r, isApprover)) {
      var cl = el('button', { type: 'button', style: 'width:100%;margin-top:10px;background:#FFFBEB;color:#92400E;border:1.5px solid #FCD34D;padding:10px;border-radius:11px;font-size:13.5px;font-weight:800;cursor:pointer;' }, '🔒 Mark ' + (r.close_centre_name || 'centre') + ' closed');
      cl.onclick = function () { closeTor(r); };
      card.appendChild(cl);
    }
    // Your own request — you can take it back, approved or not.
    if ((!isApprover || torOwn(r)) && canWithdraw(r)) {
      var wd = el('button', { type: 'button', class: 'kt-btn kt-btn-secondary',
        style: 'width:100%;margin-top:12px;justify-content:center;' }, 'Withdraw');
      wd.onclick = function () { withdrawTor(r); };
      card.appendChild(wd);
    }
    return card;
  }
  /* SICK NOTES ON TIME OFF (KiddieTrac HR Phase 4 batch 4, 2026-11-27). A photo (phone camera) or a file, and the dates it
     covers. Private: the person, their centre director and agency admins only (HrFileController); opened from a short-lived
     signed link in the house document viewer, never emailed. HR agencies only (HR_ME). */
  function torNoteErr(e) { var d = e && e.data; if (d && d.errors) { var k = Object.keys(d.errors)[0]; if (k) return d.errors[k][0]; } return (d && d.message) || 'Please try again.'; }
  function torNoteFields() {
    return '<div style="margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Note or photo (optional)</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:5px;align-items:center;">'
      + '<label class="kt-btn kt-btn-secondary kt-btn-sm" style="cursor:pointer;">📷 Take a photo<input type="file" accept="image/*" capture="environment" data-tor-pick style="display:none;"></label>'
      + '<label class="kt-btn kt-btn-secondary kt-btn-sm" style="cursor:pointer;">📎 Choose a file<input type="file" accept="image/*,application/pdf" data-tor-pick style="display:none;"></label>'
      + '<span data-tor-note-file style="font-size:12.5px;color:#475569;"></span></div>'
      + '<div data-tor-dates style="display:none;gap:10px;margin-top:8px;">'
      + '<label style="flex:1;display:block;font-size:12.5px;color:#374151;font-weight:600;">Note covers from<input data-tor-cf type="date" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:9px;margin-top:4px;font-size:15px;box-sizing:border-box;"></label>'
      + '<label style="flex:1;display:block;font-size:12.5px;color:#374151;font-weight:600;">to<input data-tor-ct type="date" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:9px;margin-top:4px;font-size:15px;box-sizing:border-box;"></label></div>'
      + '<div style="font-size:11.5px;color:#64748B;margin-top:4px;">🔒 Seen only by you, your director and your agency admin.</div>';
  }
  function torNoteWire(box) {
    box.querySelectorAll('[data-tor-pick]').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0]; if (!f) return;
        var out = box.querySelector('[data-tor-note-file]'); out._file = f; out.textContent = f.name;
        box.querySelector('[data-tor-dates]').style.display = 'flex';
      });
    });
  }
  function torNoteUpload(id, file, from, to) {
    var fd = new FormData(); fd.append('file', file);
    if (from) fd.append('covers_from', String(from).slice(0, 10));
    if (to) fd.append('covers_to', String(to).slice(0, 10));
    return Api.postForm('/hr/time-off/' + id + '/note', fd);
  }
  async function torNoteOpen(id) {
    try {
      var r = await Api.get('/hr/documents/' + id + '/open');
      if (KT.viewDocument && r.previewable) KT.viewDocument(r.url, { title: r.title, filename: r.name, mime: r.mime, label: 'Note' });
      else { var a = document.createElement('a'); a.href = r.url; a.setAttribute('download', r.name || 'note'); a.setAttribute('data-kt-download', '1'); document.body.appendChild(a); a.click(); a.remove(); }
    } catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not open the note', torNoteErr(e), '#DC2626'); }
  }
  function torNoteAdd(r) {
    var M = window.KT && KT.Shell && KT.Shell.Modal; if (!M) return;
    var box = document.createElement('div');
    box.innerHTML = torNoteFields() + '<div data-out style="font-size:13px;color:#B91C1C;margin-top:6px;"></div>';
    torNoteWire(box);
    box.querySelector('[data-tor-cf]').value = String(r.start_at || '').slice(0, 10);
    box.querySelector('[data-tor-ct]').value = String(r.end_at || r.start_at || '').slice(0, 10);
    M.open({ title: 'Add a note or photo', body: box, actions: [{ label: 'Cancel' }, { label: 'Attach', primary: true, busyLabel: 'Attaching…', handler: function () {
      var f = box.querySelector('[data-tor-note-file]')._file;
      if (!f) { box.querySelector('[data-out]').textContent = 'Take a photo or choose a file.'; return false; }
      return torNoteUpload(r.id, f, box.querySelector('[data-tor-cf]').value, box.querySelector('[data-tor-ct]').value)
        .then(function () { if (KT.toast) KT.toast('✅', 'Saved', 'Note attached.', '#16A34A'); torReload(); }, function (e) { box.querySelector('[data-out]').textContent = torNoteErr(e); return false; });
    } }] });
  }
  function torNotes(host, rows, isApprover) {
    if (!HR_ME || !rows.length) return;
    Api.get('/hr/time-off/notes?ids=' + rows.map(function (r) { return r.id; }).join(',')).then(function (res) {
      var notes = (res && res.notes) || {};
      rows.forEach(function (r) {
        var at = host.querySelector('[data-tor-row="' + r.id + '"]'); if (!at) return;
        var cell = at.tagName === 'TR' ? at.children[1] : at;
        var list = notes[r.id] || [], wrap = document.createElement('div');
        wrap.style.cssText = 'margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;';
        list.forEach(function (d) {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'kt-btn kt-btn-secondary kt-btn-sm'; b.setAttribute('data-kt-iconized', '1');
          b.textContent = '📎 ' + (d.doc_type === 'sick_note' ? 'Sick note' : 'Note');
          b.onclick = function () { torNoteOpen(d.id); }; wrap.appendChild(b);
        });
        var st = (r.status || 'pending').toLowerCase();
        if ((!isApprover || torOwn(r)) && list.length < 5 && st !== 'denied' && st !== 'cancelled') {
          var a = document.createElement('button'); a.type = 'button'; a.className = 'kt-btn kt-btn-secondary kt-btn-sm'; a.setAttribute('data-kt-iconized', '1');
          a.textContent = list.length ? '+ Another note' : '📎 Add a note or photo'; a.onclick = function () { torNoteAdd(r); }; wrap.appendChild(a);
        }
        if (wrap.children.length) cell.appendChild(wrap);
      });
    }, function () {});
  }
  function renderTorList(host, rows, isApprover) {
    if (!rows.length) { host.innerHTML = '<div style="color:#64748B;padding:16px;background:#fff;border:1px solid #EDF1F6;border-radius:14px;text-align:center;font-size:13.5px;">No requests yet.</div>'; return; }
    host.innerHTML = '';
    if (isMobile()) { rows.forEach(function (r) { host.appendChild(torCard(r, isApprover)); }); torNotes(host, rows, isApprover); return; }
    /* The table gets a card, like every other data table in the portal
       (`<div class="kt-card"><table>` -- #late-pickups does exactly this).
       It used to sit bare on the page background, which also made this screen
       disagree with itself: the EMPTY state below draws its own white rounded
       box, so "no requests" looked finished and "some requests" looked unstyled. */
    const card = document.createElement('div');
    card.className = 'kt-card';
    card.style.cssText = 'margin-top:8px;padding:6px 10px;';

    const tbl = document.createElement('table');
    tbl.style.cssText = 'width:100%;border-collapse:collapse;';
    tbl.innerHTML = '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Who</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Type</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Dates</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Decided by</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th><th></th></tr></thead><tbody></tbody>';
    const tb = tbl.querySelector('tbody');
    rows.forEach(r => {
      TOR_ROWS[r.id] = r;   // HR4B9
      const tr = document.createElement('tr');
      tr.setAttribute('data-tor-row', r.id);
      const status = (r.status || 'pending').toLowerCase();
      const color = status === 'approved' ? '#047857' : status === 'denied' ? '#B91C1C' : '#D97706';
      tr.innerHTML = `<td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name || 'You')}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${r.leave_type_name ? '<span data-no-i18n>' + escapeHtml(r.leave_type_name) + '</span>' : '<span style="text-transform:capitalize;">' + escapeHtml(r.request_type) + '</span>'}${hrLine(r) ? '<div style="font-size:11.5px;font-weight:700;color:' + ((r.leave_balance_after < 0 || (hrMore(r) && r.leave_balance_projected < 0)) ? '#B91C1C' : '#0E7490') + ';">' + escapeHtml(hrLine(r)) + '</div>' : ''}${hrRecheckHtml(r, isApprover)/* HR4B9 */}${isApprover && torClash(r) ? '<div style="font-size:11.5px;font-weight:700;color:#B45309;">' + escapeHtml(torClash(r)) + '</div>' : ''}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(torWhen(r))}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:12.5px;color:#475569;">${decidedCell(r)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="color:${color};font-weight:600;text-transform:capitalize;">${status}</span>${torReview(r) ? REVIEW_PILL : ''}${r.closed_for_dates && status === 'approved' ? '<div style="font-size:11.5px;color:#92400E;font-weight:700;">🔒 Centre closed</div>' : ''}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${isApprover && torReview(r) && !torOwn(r) ? `<button data-reviewed="${r.id}" class="kt-btn kt-btn-secondary kt-btn-sm">Reviewed</button>` : ''}${isApprover && status === 'pending' && !torOwn(r)
          ? `<button data-act="approved" data-id="${r.id}" class="kt-btn kt-btn-success kt-btn-sm" style="margin-right:6px;">Approve</button><button data-act="denied" data-id="${r.id}" class="kt-btn kt-btn-danger kt-btn-sm">Deny</button>`
          : (torCanClose(r, isApprover)
            ? `<button data-closetor="${r.id}" class="kt-btn kt-btn-secondary kt-btn-sm">Mark centre closed</button>`
            : ((!isApprover || torOwn(r)) && canWithdraw(r)
              ? `<button data-withdraw="${r.id}" class="kt-btn kt-btn-secondary kt-btn-sm">Withdraw</button>` : ''))}</td>`;
      tb.appendChild(tr);
    });
    card.appendChild(tbl);
    host.appendChild(card);
    torNotes(host, rows, isApprover);
    host.querySelectorAll('button[data-withdraw]').forEach(b => {
      b.onclick = function () {
        var row = rows.filter(function (x) { return String(x.id) === b.dataset.withdraw; })[0];
        if (row) withdrawTor(row);
      };
    });
    host.querySelectorAll('button[data-act]').forEach(b => {
      b.onclick = async () => {
        if (b.dataset.act === 'approved') { return approveTor(b.dataset.id); }
        await Api.patch(`/admin/time-off/${b.dataset.id}`, { status: b.dataset.act });
        torReload();
      };
    });
    host.querySelectorAll('button[data-reviewed]').forEach(b => { b.onclick = function () { markReviewed(b.dataset.reviewed); }; });
    host.querySelectorAll('button[data-closetor]').forEach(b => {
      b.onclick = function () {
        var row = rows.filter(function (x) { return String(x.id) === b.dataset.closetor; })[0];
        if (row) closeTor(row);
      };
    });
  }
  /* Pending is the default: it is the only tab with anything to DO. The decided tab is
     the record of approvals and declines — who decided, and when — which is worth keeping
     but is not what you open this screen for. Counts sit on the tabs so the history is
     visibly there without having to look. */
  var TOR_TAB = 'pending';
  function renderTorTabs(pendingRows, decidedRows) {
    var tabs = document.getElementById('tor-tabs');
    var host = document.getElementById('tor-team');
    if (!tabs || !host) return;
    var defs = [
      { key: 'pending', label: 'Awaiting decision', rows: pendingRows },
      { key: 'decided', label: 'Approved & declined', rows: decidedRows },
    ];
    tabs.innerHTML = '';
    defs.forEach(function (d) {
      var on = TOR_TAB === d.key;
      var b = el('button', {
        type: 'button',
        style: 'border-radius:999px;padding:8px 14px;font-size:13px;font-weight:800;cursor:pointer;'
          + 'border:1.5px solid ' + (on ? '#159FB4' : '#E2E8F0') + ';'
          + 'background:' + (on ? '#159FB4' : '#fff') + ';color:' + (on ? '#fff' : '#64748B') + ';',
      }, d.label + ' (' + d.rows.length + ')');
      b.dataset.ktIconized = '1';
      b.addEventListener('click', function () {
        TOR_TAB = d.key;
        renderTorTabs(pendingRows, decidedRows);
      });
      tabs.appendChild(b);
    });
    var rows = TOR_TAB === 'pending' ? pendingRows : decidedRows;
    renderTorList(host, rows, true);
  }

  function openTorModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:480px;width:90%;">
      <h3 style="margin:0 0 12px;">New time-off request</h3>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Type
        <select id="tor-type" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;">
          <option value="vacation">Vacation</option><option value="sick">Sick</option>
          <option value="personal">Personal</option><option value="bereavement">Bereavement</option>
          <option value="jury">Jury duty</option><option value="other">Other</option></select></label>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Start date
        <input id="tor-start" type="date" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">End date
        <input id="tor-end" type="date" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;"></label>
      <label style="display:flex;align-items:center;gap:9px;margin-top:14px;font-size:14px;color:#374151;font-weight:600;">
        <input id="tor-allday" type="checkbox" checked data-kt-switch="1"> All day</label>
      <div id="tor-times" style="display:none;gap:10px;margin-top:10px;">
        <label style="flex:1;display:block;font-size:13px;color:#374151;font-weight:600;">From
          <input id="tor-tstart" type="time" value="09:00" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;"></label>
        <label style="flex:1;display:block;font-size:13px;color:#374151;font-weight:600;">To
          <input id="tor-tend" type="time" value="12:00" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;"></label>
      </div>
      <div id="tor-partial-note" style="display:none;font-size:12px;color:#64748B;margin-top:6px;">
        Part of a day has to be a single date, and does not close the centre.</div>
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Reason
        <textarea id="tor-reason" rows="3" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;"></textarea></label>
      <div id="tor-err" style="font-size:13px;color:#B91C1C;margin-top:10px;min-height:18px;"></div>
      <div style="margin-top:14px;display:flex;justify-content:flex-end;gap:8px;">
        <button id="tor-cancel" class="kt-btn kt-btn-secondary" type="button">Cancel</button>
        <button id="tor-submit" class="kt-btn kt-btn-primary" type="button">Submit</button>
      </div></div>`;
    document.body.appendChild(m);
    var hrTypes = (HR_ME && HR_ME.types) || [];
    var hrBal = {};
    ((HR_ME && HR_ME.balances) || []).forEach(function (x) { hrBal[x.leave_type_id] = x; });
    if (hrTypes.length) {
      m.querySelector('#tor-type').innerHTML = hrTypes.map(function (t) { return '<option value="' + t.id + '">' + escapeHtml(t.name) + '</option>'; }).join('');
      var bl = document.createElement('div');
      bl.id = 'tor-bal';
      bl.style.cssText = 'font-size:12.5px;font-weight:700;color:#0E7490;margin-top:6px;min-height:16px;';
      m.querySelector('#tor-type').closest('label').after(bl);
    }
    function weekdays(a, b) {
      if (!a || !b || b < a) return 0;
      var d = new Date(a + 'T12:00:00'), e = new Date(b + 'T12:00:00'), n = 0, all = 0;
      var closed = {}; ((HR_ME && HR_ME.closed) || []).forEach(function (x) { closed[x] = 1; });
      var wk = 0, ok = 0, okAll = 0;
      for (; d <= e && all < 400; d.setDate(d.getDate() + 1), all++) {
        var w = d.getDay(), ymd = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        if (!closed[ymd]) okAll++;
        if (w !== 0 && w !== 6) { wk++; if (!closed[ymd]) ok++; }
      }
      return wk ? ok : okAll;   // the server's rule: working days, less holidays and closures
    }
    function syncBal() {
      var bl = m.querySelector('#tor-bal'); if (!bl) return;
      var t = hrTypes.filter(function (x) { return String(x.id) === m.querySelector('#tor-type').value; })[0], b = t && hrBal[t.id];
      if (!t || !b || !b.tracked) { bl.textContent = t && !t.needs_approval ? 'No approval needed: recorded and taken off your balance straight away.' : ''; bl.style.color = '#0E7490'; return; }
      var s = m.querySelector('#tor-start').value, e = allDayEl.checked ? m.querySelector('#tor-end').value : s, n = 0;
      if (s && e) {
        if (allDayEl.checked) n = weekdays(s, e);
        else { var a = (m.querySelector('#tor-tstart').value || '').split(':'), z = (m.querySelector('#tor-tend').value || '').split(':'); var hrs = Math.max(0, ((+z[0] * 60 + +z[1]) - (+a[0] * 60 + +a[1])) / 60); n = t.unit === 'hours' ? hrs : Math.round(hrs / ((HR_ME && HR_ME.hours_per_day) || 8) * 100) / 100; }
        if (allDayEl.checked && t.unit === 'hours') n = n * ((HR_ME && HR_ME.hours_per_day) || 8);
      }
      var left = Math.round((b.balance - b.pending) * 100) / 100, after = Math.round((left - n) * 100) / 100;
      bl.textContent = t.name + ': ' + hrAmt(left, t.unit) + ' available' + (n ? ', ' + hrAmt(after, t.unit) + ' after this request' : '') + (after < 0 && n ? (HR_ME.allow_negative ? ' (below zero)' : ' — not enough left') : '');
      bl.style.color = after < 0 && n ? '#B91C1C' : '#0E7490';
    }
    m.addEventListener('change', syncBal);
    m.addEventListener('input', syncBal);
    setTimeout(syncBal, 0);
    var clashEl = null, clashT = null, clashKey = '';
    if (HR_ME) {
      clashEl = document.createElement('div');
      clashEl.id = 'tor-clash';
      clashEl.style.cssText = 'margin-top:10px;';
      m.querySelector('#tor-reason').closest('label').before(clashEl);
      // HR Phase 4 batch 4 (2026-11-27): a sick note or photo, taken with the phone camera or chosen from files.
      var noteEl = document.createElement('div');
      noteEl.innerHTML = torNoteFields();
      m.querySelector('#tor-err').before(noteEl);
      torNoteWire(noteEl);
    }
    function syncClash() {
      if (!clashEl) return;
      var s0 = m.querySelector('#tor-start').value, all = m.querySelector('#tor-allday').checked, e0 = all ? m.querySelector('#tor-end').value : s0;
      if (!s0 || !e0 || e0 < s0) { clashEl.innerHTML = ''; clashKey = ''; return; }
      var key = s0 + '|' + e0 + '|' + all;
      if (key === clashKey) return;
      clashKey = key;
      clearTimeout(clashT);
      clashT = setTimeout(function () {
        Api.get('/hr/me/clash?start_at=' + s0 + '&end_at=' + e0 + '&all_day=' + (all ? 1 : 0)).then(function (c) {
          if (clashKey === key) clashEl.innerHTML = clashHtml(c, false);
        }, function () { if (clashKey === key) clashEl.innerHTML = ''; });
      }, 350);
    }
    m.addEventListener('change', syncClash);

    var allDayEl = m.querySelector('#tor-allday');
    var timesEl = m.querySelector('#tor-times');
    var noteEl = m.querySelector('#tor-partial-note');
    var startEl = m.querySelector('#tor-start');
    var endEl = m.querySelector('#tor-end');
    var errEl = m.querySelector('#tor-err');

    function syncAllDay() {
      var partial = !allDayEl.checked;
      timesEl.style.display = partial ? 'flex' : 'none';
      noteEl.style.display = partial ? 'block' : 'none';
      // Part of a day only means anything within one date, so the end follows the
      // start rather than letting someone ask for 2–4pm across a fortnight.
      endEl.disabled = partial;
      if (partial && startEl.value) { endEl.value = startEl.value; }
    }
    allDayEl.addEventListener('change', syncAllDay);
    /* THE END DATE FOLLOWS THE START (ticket #219, 2026-11-04). An educator picked Dec 21 to start, then opened the
       empty end date: its calendar opened on THIS month, so "27" became Nov 27, before the start. The server refused
       it, nothing on screen said why, and Submit just did nothing. Now the end can never be before the start (min,
       which kt-date-sheet greys out), and an empty or earlier end jumps to the start, so its calendar opens there. */
    startEl.addEventListener('change', function () {
      endEl.min = startEl.value || '';
      if (!allDayEl.checked || !endEl.value || (startEl.value && endEl.value < startEl.value)) { endEl.value = startEl.value; }
    });
    syncAllDay();

    m.querySelector('#tor-cancel').onclick = () => m.remove();
    m.querySelector('#tor-submit').onclick = async () => {
      var allDay = allDayEl.checked;
      const payload = {
        start_at: startEl.value,
        end_at: allDay ? endEl.value : startEl.value,
        reason: m.querySelector('#tor-reason').value,
        all_day: allDay,
      };
      if (hrTypes.length) payload.leave_type_id = +m.querySelector('#tor-type').value;
      else payload.request_type = m.querySelector('#tor-type').value;
      if (!allDay) {
        payload.start_time = m.querySelector('#tor-tstart').value;
        payload.end_time = m.querySelector('#tor-tend').value;
        if (!payload.start_time || !payload.end_time) { errEl.textContent = 'Give a start and end time.'; return; }
        if (payload.end_time <= payload.start_time) { errEl.textContent = 'The end time has to be after the start.'; return; }
      }
      if (!payload.start_at || !payload.end_at) { errEl.textContent = 'Pick the dates.'; return; }
      if (payload.end_at < payload.start_at) { errEl.textContent = 'The end date can\'t be before the start date.'; return; }
      errEl.textContent = '';
      // Said in the form, never a silent Submit (and never filed as a crash).
      var sent;
      try { sent = await Api.post('/time-off', payload); }
      catch (e) { errEl.textContent = (e && e.data && e.data.message) || (e && e.message) || 'Could not send the request. Please try again.'; return; }
      var nf = m.querySelector('[data-tor-note-file]');
      if (sent && sent.id && nf && nf._file) {
        try { await torNoteUpload(sent.id, nf._file, m.querySelector('[data-tor-cf]').value || payload.start_at, m.querySelector('[data-tor-ct]').value || payload.end_at || payload.start_at); }
        catch (e) { if (KT.toast) KT.toast('⚠️', 'Request sent, note not attached', torNoteErr(e) + ' Add it from Your requests.', '#D97706'); }
      }
      m.remove();
      try { if (sent && sent.warning && KT.toast) KT.toast('⚠️', 'Sent, below zero', sent.warning, '#D97706'); else if (sent && sent.status === 'approved' && KT.toast) KT.toast('✅', 'Recorded', 'No approval needed. It is taken off your balance, and your manager will review it.', '#16A34A'); } catch (e) {}
      torReload();
    };
  }

  // ============================ Background checks ============================
  /* REBUILT 2026-10-15. Anthony: "background checks section when adding new record doesnt
     pull staff and the table is not there with how we have other tables - full sweep also
     add a notes field to add notes".

     - The Staff dropdown was always empty: it asked /admin/users for three roles in one
       value and read the wrong key. The list endpoint now returns the staff a check can be
       recorded for (`staff`), scoped the same way as the rows.
     - The table is the house pattern (CONVENTIONS.md "Tables"): data-kt-pretty on main, a
       plain <table> in a .kt-card, labelled row buttons — so it gets the card, sortable
       headers, filter box, export bar, row count and ⋮ menu like every other list.
     - Notes on the form, in the table and on the record. Dates are calendar dates (no
       timezone shift: '2026-10-15' is the 15th everywhere). */
  const BGC = { main: null, rows: [], staff: [], status: '' };
  const BGC_TYPES = [{ code: 'vss', label: 'VSS' }, { code: 'criminal', label: 'Criminal' }, { code: 'driver', label: 'Driver' }, { code: 'reference', label: 'Reference' }, { code: 'other', label: 'Other' }];
  /* The hero line in the agency's own terms (2026-10-28): "vulnerable-sector screening" is Canadian; a US agency
     reads its profile's note (California: Live Scan + CACI, no expiry). */
  function bgcHeroSub() {
    const j = window.KT && KT.jurisdiction;
    if (j && /^US-/.test(j.jurisdiction || '')) {
      return 'Criminal record clearances and other screening for every staff member — when each one expires (if it does), the document on file, and any notes.'
        + (j.background_check_note ? ' ' + j.background_check_note : '');
    }
    return 'Police checks and vulnerable-sector screening for every staff member — when each one expires, the document on file, and any notes.';
  }
  // The profile can arrive after the first paint (kt-jurisdiction.js loads it ~1 s in): reword the hero then.
  window.addEventListener('kt:jurisdiction', function () {
    document.querySelectorAll('[data-bgc-sub]').forEach(function (el) { el.textContent = bgcHeroSub(); });
  });
  function bgcTypes() { return (window.KT && KT._jurChecks && KT._jurChecks.length) ? KT._jurChecks : BGC_TYPES; }
  function bgcTypeLabel(code) {
    const t = bgcTypes().concat(BGC_TYPES).find(x => x.code === code);
    return t ? t.label : String(code || '').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());
  }
  function bgcDay(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    if (!m) return '';
    return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }
  const BGC_TONE = { expired: ['#FEE2E2', '#B91C1C', 'Expired'], expiring: ['#FEF3C7', '#B45309', 'Expiring soon'], valid: ['#DCFCE7', '#15803D', 'Valid'] };
  function bgcChip(bucket) {
    const t = BGC_TONE[bucket] || BGC_TONE.valid;
    return `<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;background:${t[0]};color:${t[1]};white-space:nowrap;">${t[2]}</span>`;
  }
  function bgcWhen(r) {
    if (r.no_expiry || !r.expires_at) return 'does not expire';
    const n = Number(r.days_until_expiry);
    if (!isFinite(n)) return '';
    if (r.status_bucket === 'expired') return `${Math.abs(n)} day${Math.abs(n) === 1 ? '' : 's'} ago`;
    return n === 0 ? 'today' : `in ${n} day${n === 1 ? '' : 's'}`;
  }
  function bgcDocUrl(u) {
    const host = ((window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
    return u ? (/^https?:\/\//.test(u) ? u : host + u) : '';
  }

  async function renderBackgroundChecks(main) {
    BGC.main = main;
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:20px;color:#64748B;">Loading background checks…</div>';
    let res;
    try { res = await Api.get('/admin/background-checks'); }
    catch (e) { main.innerHTML = `<div class="kt-card" style="color:#B91C1C;">${escapeHtml((e && e.message) || 'Could not load background checks.')}</div>`; return; }
    BGC.rows = (res && res.data) || [];
    BGC.staff = (res && res.staff) || [];
    main.innerHTML = '';
    const root = html(`
      <div>
        <div class="kt-hero" style="background:linear-gradient(135deg,#0B2545 0%,#13315C 55%,#1F6FB2 100%);">
          <div class="kt-hero-greet">🛡️ STAFF COMPLIANCE</div><h1>Background checks</h1>
          <div class="kt-hero-sub" data-bgc-sub>${escapeHtml(bgcHeroSub())}</div>
        </div>
        <div id="bgc-bar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0 12px;">
          <select id="bgc-status" style="height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;">
            <option value="">All statuses</option><option value="expired">Expired</option><option value="expiring">Expiring soon (60 days)</option><option value="valid">Valid</option>
          </select>
          <span style="flex:1"></span>
          <button id="bgc-csv" type="button" class="btn btn-secondary" style="height:34px;">⤓ CSV</button>
          <button id="bgc-add" type="button" class="btn btn-primary" style="height:34px;">+ Add check</button>
        </div>
        <div id="bgc-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px;"></div>
        <div id="bgc-list"></div>
      </div>`).firstElementChild;
    main.appendChild(root);
    const sel = root.querySelector('#bgc-status');
    sel.value = BGC.status;
    sel.onchange = () => { BGC.status = sel.value; renderBgcList(root.querySelector('#bgc-list'), BGC.rows); };
    root.querySelector('#bgc-csv').onclick = () => downloadAuthed('/admin/background-checks?format=csv', 'background-checks.csv');
    root.querySelector('#bgc-add').onclick = () => openBgcModal(null);
    const count = b => BGC.rows.filter(r => r.status_bucket === b).length;
    const stat = (label, n, col) => `<div class="kt-card" style="margin:0;padding:12px 14px;"><div style="font-size:12px;color:#64748B;font-weight:700;">${label}</div><div style="font-size:22px;font-weight:800;color:${col};">${n}</div></div>`;
    root.querySelector('#bgc-stats').innerHTML = stat('Checks on file', BGC.rows.length, '#0F172A')
      + stat('Expired', count('expired'), count('expired') ? '#B91C1C' : '#0F172A')
      + stat('Expiring in 60 days', count('expiring'), count('expiring') ? '#B45309' : '#0F172A')
      + stat('Staff with no check', BGC.staff.filter(s => !BGC.rows.some(r => String(r.user_id) === String(s.id))).length, '#0F172A');
    renderBgcList(root.querySelector('#bgc-list'), BGC.rows);
  }

  function renderBgcList(host, all) {
    const rows = BGC.status ? all.filter(r => r.status_bucket === BGC.status) : all;
    if (!rows.length) {
      host.innerHTML = `<div class="kt-card" style="color:#475569;font-size:13.5px;">${all.length ? 'No checks with this status.' : 'No background checks recorded yet. Press <b>+ Add check</b> to record the first one.'}</div>`;
      return;
    }
    host.innerHTML = '<div class="kt-card"><table data-kt-no-bulk><thead><tr><th>Staff</th><th>Check</th><th>Reference</th><th>Issued</th><th>Expires</th><th>Status</th><th>Notes</th><th></th></tr></thead><tbody>'
      + rows.map(r => `<tr>
          <td style="min-width:160px;"><div style="font-weight:700;">${escapeHtml(r.user_name || '')}</div>${r.staff_ref ? `<div style="font-size:12px;color:#64748B;">${escapeHtml(r.staff_ref)}</div>` : ''}</td>
          <td>${escapeHtml(bgcTypeLabel(r.check_type))}</td>
          <td>${escapeHtml(r.reference || '—')}</td>
          <td style="white-space:nowrap;">${r.issued_at ? bgcDay(r.issued_at) : '—'}</td>
          <td style="white-space:nowrap;">${r.expires_at ? bgcDay(r.expires_at) : 'No expiry'}<div style="font-size:12px;color:#64748B;">${bgcWhen(r)}</div></td>
          <td>${bgcChip(r.status_bucket)}${r.document_url ? `<div style="margin-top:4px;"><a href="${escapeHtml(bgcDocUrl(r.document_url))}" target="_blank" rel="noopener" style="font-size:12px;color:#1D4ED8;font-weight:600;">📄 Document</a></div>` : ''}</td>
          <td style="max-width:260px;font-size:13px;color:#334155;">${r.notes ? `<div style="white-space:pre-wrap;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">${escapeHtml(r.notes)}</div>` : '<span style="color:#94A3B8;">—</span>'}</td>
          <td style="text-align:right;white-space:nowrap;">
            <button type="button" class="btn btn-secondary btn-sm" data-view="${r.id}">View</button>
            <button type="button" class="btn btn-secondary btn-sm" data-edit="${r.id}">✏️ Edit</button>
            <button type="button" class="btn btn-secondary btn-sm" data-del="${r.id}">🗑 Delete</button>
          </td></tr>`).join('')
      + '</tbody></table></div>';
    const byId = id => all.find(r => String(r.id) === String(id));
    host.querySelectorAll('[data-view]').forEach(b => b.onclick = () => openBgcDetail(byId(b.dataset.view)));
    host.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openBgcModal(byId(b.dataset.edit)));
    host.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      const r = byId(b.dataset.del);
      const ok = await KT.confirm({ title: 'Delete this background check?', description: (r ? `${r.user_name} · ${bgcTypeLabel(r.check_type)}` : '') + ' — this cannot be undone.', okLabel: 'Delete' });
      if (!ok) return;
      try { await Api.delete(`/admin/background-checks/${b.dataset.del}`); }
      catch (e) { if (Dom.toast) Dom.toast((e && e.message) || 'Could not delete', 'error'); return; }
      renderBackgroundChecks(BGC.main);
    });
  }

  // Read-only record + attach-a-file.
  function openBgcDetail(row) {
    if (!row) return;
    const M = window.KT && KT.Shell && KT.Shell.Modal;
    const cell = 'padding:6px 12px 6px 0;color:#64748B;vertical-align:top;white-space:nowrap;';
    const body = html(`
      <table data-kt-no-filter data-kt-noexport data-kt-no-bulk style="width:100%;border-collapse:collapse;font-size:13.5px;">
        <tr><td style="${cell}">Staff</td><td style="padding:6px 0;font-weight:700;">${escapeHtml(row.user_name || '')}${row.staff_ref ? ` <span style="font-weight:600;color:#64748B;">· ${escapeHtml(row.staff_ref)}</span>` : ''}</td></tr>
        <tr><td style="${cell}">Check</td><td style="padding:6px 0;">${escapeHtml(bgcTypeLabel(row.check_type))}</td></tr>
        <tr><td style="${cell}">Reference</td><td style="padding:6px 0;">${escapeHtml(row.reference || '—')}</td></tr>
        <tr><td style="${cell}">Issued</td><td style="padding:6px 0;">${row.issued_at ? bgcDay(row.issued_at) : '—'}</td></tr>
        <tr><td style="${cell}">Expires</td><td style="padding:6px 0;">${row.expires_at ? bgcDay(row.expires_at) + ' · ' : ''}${bgcWhen(row)} ${bgcChip(row.status_bucket)}</td></tr>
        <tr><td style="${cell}">Notes</td><td style="padding:6px 0;white-space:pre-wrap;">${escapeHtml(row.notes || '—')}</td></tr>
      </table>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid #F1F5F9;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#94A3B8;margin-bottom:8px;">Attached document</div>
        <div id="bgc-doc-cur">${row.document_url ? `<a href="${escapeHtml(bgcDocUrl(row.document_url))}" target="_blank" rel="noopener" style="color:#1D4ED8;font-weight:600;">📄 Open current document</a>` : '<span style="color:#64748B;">No document attached yet.</span>'}</div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
          <input id="bgc-file" type="file" accept=".pdf,image/*,.doc,.docx" style="font-size:13px;max-width:100%;">
          <button id="bgc-upload" type="button" class="btn btn-primary btn-sm">Upload</button>
        </div>
        <div id="bgc-up-status" style="font-size:12.5px;min-height:16px;margin-top:6px;"></div>
      </div>`).firstElementChild.parentNode;
    M.open({
      title: '🛡️ Background check',
      body,
      actions: [
        { label: '✏️ Edit', handler: () => { setTimeout(() => openBgcModal(row), 0); } },
        { label: 'Close', primary: true },
      ],
    });
    body.querySelector('#bgc-upload').onclick = async () => {
      const fileEl = body.querySelector('#bgc-file');
      const st = body.querySelector('#bgc-up-status');
      if (!fileEl.files || !fileEl.files[0]) { st.style.color = '#B91C1C'; st.textContent = 'Choose a file first.'; return; }
      const btn = body.querySelector('#bgc-upload');
      const done = (window.KT && KT.busy) ? KT.busy(btn) : function () {};
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('file', fileEl.files[0]);
        const res = await Api.postForm('/admin/background-checks/' + row.id + '/document', fd);
        row.document_url = res.document_url;
        body.querySelector('#bgc-doc-cur').innerHTML = `<a href="${escapeHtml(bgcDocUrl(res.document_url))}" target="_blank" rel="noopener" style="color:#1D4ED8;font-weight:600;">📄 Open current document</a>`;
        st.style.color = '#047857'; st.textContent = '✓ Uploaded.';
        if (BGC.main) { const h = BGC.main.querySelector('#bgc-list'); if (h) renderBgcList(h, BGC.rows); }
      } catch (e) {
        st.style.color = '#B91C1C'; st.textContent = '✗ ' + ((e && e.message) || 'Upload failed');
      } finally { btn.disabled = false; done(); }
    };
  }

  function openBgcModal(row) {
    const M = window.KT && KT.Shell && KT.Shell.Modal;
    const staff = BGC.staff.slice();
    // An existing record whose person is no longer on the list still shows who it is.
    if (row && !staff.some(s => String(s.id) === String(row.user_id))) staff.unshift({ id: row.user_id, name: row.user_name || ('Staff #' + row.user_id), ref: row.staff_ref });
    const types = bgcTypes().slice();
    if (row && row.check_type && !types.some(t => t.code === row.check_type)) types.push({ code: row.check_type, label: bgcTypeLabel(row.check_type) });
    const F = 'display:block;width:100%;box-sizing:border-box;height:38px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;background:#fff;margin-top:4px;';
    const L = 'display:block;font-size:13px;font-weight:700;color:#334155;';
    const body = html(`
      <div style="display:grid;gap:12px;">
        <label style="${L}">Staff member
          <select id="bgc-user" style="${F}">
            <option value="">${staff.length ? '— choose a staff member —' : 'No staff found for this agency'}</option>
            ${staff.map(s => `<option value="${s.id}" ${row && String(row.user_id) === String(s.id) ? 'selected' : ''}>${escapeHtml(s.name)}${s.ref ? ' · ' + escapeHtml(s.ref) : ''}</option>`).join('')}
          </select></label>
        <label style="${L}">Type of check
          <select id="bgc-type" style="${F}">
            ${types.map(t => `<option value="${escapeHtml(t.code)}" ${row && row.check_type === t.code ? 'selected' : ''}>${escapeHtml(t.label)}</option>`).join('')}
          </select></label>
        <label style="${L}">Reference / certificate #
          <input id="bgc-ref" maxlength="120" value="${row ? escapeHtml(row.reference || '') : ''}" style="${F}"></label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
          <label style="${L}">Issued
            <input id="bgc-issued" type="date" value="${row && row.issued_at ? String(row.issued_at).substring(0, 10) : ''}" style="${F}"></label>
          <label style="${L}">Expires
            <input id="bgc-expires" type="date" value="${row && row.expires_at ? String(row.expires_at).substring(0, 10) : ''}" style="${F}"></label>
        </div>
        <label style="${L}">Notes
          <textarea id="bgc-notes" maxlength="2000" rows="4" placeholder="Anything worth keeping with this check — e.g. offence declaration on file, renewal applied for, follow-up needed." style="${F}height:auto;min-height:90px;padding:8px 10px;font-family:inherit;resize:vertical;">${row ? escapeHtml(row.notes || '') : ''}</textarea></label>
        <div id="bgc-err" style="color:#B91C1C;font-size:13px;min-height:16px;"></div>
      </div>`).firstElementChild;
    M.open({
      title: row ? 'Edit background check' : 'Add background check',
      body,
      actions: [
        { label: 'Cancel' },
        { label: row ? 'Save changes' : 'Save check', primary: true, handler: async () => {
          const err = body.querySelector('#bgc-err');
          const userId = parseInt(body.querySelector('#bgc-user').value, 10);
          const expires = body.querySelector('#bgc-expires').value;
          const issued = body.querySelector('#bgc-issued').value;
          if (!userId) { err.textContent = 'Choose the staff member.'; return false; }
          // A check whose catalogue entry never renews (California Live Scan / CACI) may be left without an expiry.
          const tSel = bgcTypes().find(x => x.code === body.querySelector('#bgc-type').value);
          const noRenew = !!(tSel && Object.prototype.hasOwnProperty.call(tSel, 'renew_years') && !tSel.renew_years);
          if (!expires && !noRenew) { err.textContent = 'Enter the expiry date.'; return false; }
          if (issued && expires && issued > expires) { err.textContent = 'The expiry date is before the issue date.'; return false; }
          err.textContent = '';
          try {
            await Api.post('/admin/background-checks', {
              id: row ? row.id : null,
              user_id: userId,
              check_type: body.querySelector('#bgc-type').value,
              reference: body.querySelector('#bgc-ref').value.trim(),
              issued_at: issued || null,
              expires_at: expires || null,
              notes: body.querySelector('#bgc-notes').value.trim(),
            });
          } catch (e) { err.textContent = (e && e.message) || 'Could not save.'; return false; }
          if (Dom.toast) Dom.toast(row ? 'Background check updated' : 'Background check added', 'success');
          renderBackgroundChecks(BGC.main);
        } },
      ],
    });
  }

  // ============================ Payroll ============================
  async function renderPayroll(main) {
    if (!document.getElementById('kt-pp-css')) {
      const st = document.createElement('style'); st.id = 'kt-pp-css';
      st.textContent = '@media (max-width:900px){.pp-cards2{grid-template-columns:minmax(0,1fr)!important}}'
        + '@media (max-width:600px){#pp-cards{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important}'
        + '#pp-cards>.pp-cardx:last-child:nth-child(odd){grid-column:1/-1}' + '#pp-cards .pp-cardx>div:last-child{font-size:11.5px!important}#pp-cards .pp-cardx span[style*="font-size:24px"]{font-size:19px!important}}'
        + '@media (max-width:380px){#pp-cards{grid-template-columns:minmax(0,1fr)!important}}';
      document.head.appendChild(st);
    }
    main.innerHTML = `
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <h2 style="margin:0 0 12px;color:#1F6080;">Payroll</h2>
        <div role="tablist" aria-label="Payroll views" id="pr-mode" style="display:flex;gap:4px;margin:0 0 16px;">
          <button type="button" role="tab" aria-selected="true" data-pr-mode="hours" style="padding:9px 14px;font-size:13.5px;font-weight:700;white-space:nowrap;cursor:pointer;">⏱️ Hours &amp; payslips</button>
          <button type="button" role="tab" aria-selected="false" data-pr-mode="periods" style="padding:9px 14px;font-size:13.5px;font-weight:700;white-space:nowrap;cursor:pointer;">📅 Pay periods</button>
        </div>
        <div id="pr-periods-view" hidden></div>
        <div id="pr-hours-view">
        <!-- The "Documents issued" tab was here. Hours and documents are one
             question, so the documents now sit on the rows they belong to. -->
        <div style="display:flex;gap:12px;margin-bottom:18px;align-items:end;">
          <label style="font-size:13px;color:#374151;">From <input id="pr-from" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <label style="font-size:13px;color:#374151;">To <input id="pr-to" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <button id="pr-run" style="background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">Run</button>
          <button id="pr-csv" style="background:#059669;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">⤓ CSV</button>
          <button id="pr-manual" style="display:none;background:#7C3AED;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">＋ Generate payroll</button>
        </div>
        <div id="pr-quick" style="display:flex;gap:6px;flex-wrap:wrap;margin:-8px 0 16px;"></div>
        <div id="pr-result"></div>
        <div id="pr-docs" hidden></div>
        </div>
      </div>`;

    /* The two tabs are gone. What each person worked and what they were paid for it is
       one question, and answering it across two views meant matching names by eye. The
       documents are on the rows now; see paintPanes() in runPayroll(). */
    let prTab = 'hours';
    /* The Hours / Documents tab bar stood here and is gone: hours and documents are
       one question and now share one table. REMOVED rather than left guarded — its
       body referenced PR_TABS, which no longer exists, so restoring the #pr-tabs div
       would have thrown a ReferenceError instead of quietly doing nothing. */
    // v22p98: default to a trailing 30-day window (was first-of-month → today,
    // a single empty day on the 1st, hiding the whole prior pay period).
    const today = new Date();
    const startWin = new Date(); startWin.setDate(today.getDate() - 30);
    document.getElementById('pr-from').valueAsDate = startWin;
    document.getElementById('pr-to').valueAsDate = today;
    document.getElementById('pr-run').onclick = () => runPayroll();
    /* Views: hours (what was worked) and pay periods (what each period paid). */
    const modeBar = document.getElementById('pr-mode');
    const setMode = (m) => {
      if (!modeBar) return;
      modeBar.querySelectorAll('[data-pr-mode]').forEach(b => b.setAttribute('aria-selected', b.getAttribute('data-pr-mode') === m ? 'true' : 'false'));
      const hv = document.getElementById('pr-hours-view'), pv = document.getElementById('pr-periods-view');
      if (hv) hv.hidden = m !== 'hours';
      if (pv) { pv.hidden = m !== 'periods'; if (m === 'periods') renderPayPeriods(pv); }
      try { sessionStorage.setItem('kt_payroll_view', m); } catch (e) {}
    };
    if (modeBar) modeBar.querySelectorAll('[data-pr-mode]').forEach(b => { b.onclick = () => setMode(b.getAttribute('data-pr-mode')); });
    /* Quick periods for the hours report: the agency's pay schedule when one is set, else the periods its payslips
       cover; months always. Each sets From/To and runs. */
    const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const quickHost = document.getElementById('pr-quick');
    const quickBtn = (label, from, to, hint) => {
      const b = document.createElement('button');
      const tipText = (hint ? hint + ': ' : '') + (KT.dayLabel ? KT.dayLabel(from) + ' – ' + KT.dayLabel(to) : from + ' to ' + to);
      b.type = 'button'; b.textContent = label; b.title = tipText; b.setAttribute('data-kttip', tipText); b.setAttribute('aria-label', label + ' (' + tipText + ')');
      b.setAttribute('data-kt-iconized', '1'); b.setAttribute('data-kt-btn-free', '1');
      b.style.cssText = 'background:#fff;border:1px solid #CBD5E1;color:#1F6080;border-radius:999px;height:32px;padding:0 13px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;';
      b.onclick = () => {
        const fe = document.getElementById('pr-from'), te = document.getElementById('pr-to');
        if (fe) fe.value = from; if (te) te.value = to;
        runPayroll();
      };
      return b;
    };
    const paintQuick = (q) => {
      if (!quickHost) return;
      quickHost.innerHTML = '';
      const hint = q && q.source === 'schedule' ? 'Pay schedule' : 'From the payslips';
      if (q && q.this) quickHost.appendChild(quickBtn('This pay period', q.this.from, q.this.to, hint));
      if (q && q.last) quickHost.appendChild(quickBtn('Last pay period', q.last.from, q.last.to, hint));
      const n = new Date();
      quickHost.appendChild(quickBtn('This month', iso(new Date(n.getFullYear(), n.getMonth(), 1)), iso(new Date(n.getFullYear(), n.getMonth() + 1, 0))));
      quickHost.appendChild(quickBtn('Last month', iso(new Date(n.getFullYear(), n.getMonth() - 1, 1)), iso(new Date(n.getFullYear(), n.getMonth(), 0))));
    };
    paintQuick(null);
    /* "+ Generate payroll" shows only for people the payroll route lets in (2026-11-27): the server answers from the
       route's own permission (RouteAccess), so a director never meets a button that can only say "Forbidden". */
    Api.get('/admin/payroll/periods').then(r => {
      paintQuick(r && r.quick);
      const mb = document.getElementById('pr-manual');
      if (mb) mb.style.display = (r && r.can && r.can.generate) ? '' : 'none';
    }).catch(() => {});
    let startView = 'hours';
    try { if (sessionStorage.getItem('kt_payroll_view') === 'periods') startView = 'periods'; } catch (e) {}
    if (startView === 'periods') setTimeout(() => setMode('periods'), 0);
    /* Manual payroll — pay several people for one period in one pass. The wizard lives
       in its own module (screen-manual-payroll.js) rather than here: this file is large
       already, and a payroll RUN is a different thing from the hours report. */
    const manualBtn = document.getElementById('pr-manual');
    if (manualBtn) manualBtn.onclick = () => {
      // A finished run changes hours AND the documents on those rows, which are the
      // same table now, so the whole report is redrawn rather than a separate ledger.
      if (KT.ManualPayroll) KT.ManualPayroll.open(() => runPayroll());
    };
    // Same reason: by the time this runs the export module may already have removed it.
    const csvBtn = document.getElementById('pr-csv');
    if (csvBtn) csvBtn.onclick = () => {
      const f = document.getElementById('pr-from').value, t = document.getElementById('pr-to').value;
      downloadAuthed(`/admin/payroll?from=${f}&to=${t}&format=csv`, `payroll-${f}-to-${t}.csv`);
    };
    runPayroll();

  }
  /* The issued ledger, split the same way the hours report is. Module scope, not
     nested in renderPayroll: a declaration inside another function body is invisible to
     its siblings, which is the trap behind three crashes this month. */
  async function renderPayrollDocs() {
    const host = document.getElementById('pr-docs');
    if (!host) return;
    const fromEl = document.getElementById('pr-from'), toEl = document.getElementById('pr-to');
    host.innerHTML = '<div style="color:#64748B;padding:12px;">Loading…</div>';

    // A wider window than the hours report: documents are looked up by name far more
    // often than by date, and a 30-day default hides almost the whole ledger.
    const to = (toEl && toEl.value) || '';
    // Which build this page is actually running, read off this script's own URL. A
    // cached bundle is the usual reason a screen behaves like an older version of itself,
    // and it is invisible unless the page says so.
    const buildOf = () => {
      try {
        const src = (document.currentScript && document.currentScript.src)
          || Array.from(document.scripts).map(s => s.src).find(s => s && s.indexOf('screen-v22p51.js') !== -1)
          || '';
        const m = src.match(/[?&]v=([^&]+)/);
        return m ? m[1] : 'unknown';
      } catch (e) { return 'unknown'; }
    };

    let res;
    try {
      res = await Api.get(`/provider/payroll-documents${to ? '?to=' + to : ''}`);
    } catch (e) {
      const msg = (e && e.message) || 'error';
      const stale = /404|not found/i.test(msg);
      host.innerHTML = `<div style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:12px;padding:14px 16px;font-size:13.5px;">`
        + `<strong>Could not load payroll documents.</strong><div style="margin-top:4px;">${escapeHtml(msg)}</div>`
        + (stale
            ? `<div style="margin-top:8px;color:#7F1D1D;">This page is running an out-of-date build (<code>${escapeHtml(buildOf())}</code>) that calls an endpoint which has since moved. Reload the page — hold Shift and press Reload — and it will pick up the current one.</div>`
            : `<div style="margin-top:8px;color:#7F1D1D;">Build <code>${escapeHtml(buildOf())}</code>.</div>`)
        + `</div>`;
      return;
    }
    const rows = (res && res.data) || [];
    if (!rows.length) {
      // Say WHICH agency is empty. "No documents" while looking at the wrong agency is
      // the same screen as "no documents exist", and they need different actions.
      host.innerHTML = '<div style="color:#64748B;padding:14px;">'
        + '<strong>No payroll documents for the agency you are viewing.</strong>'
        + '<div style="margin-top:4px;">They appear here as payslips and payroll invoices are raised, or as they sync in from iLearn.</div>'
        + '</div>';
      return;
    }

    const money = (n) => '$' + (Number(n) || 0).toFixed(2);
    // Date-only: see KT.dayLabel — this was showing payroll periods a day early.
    const dt = (d) => (window.KT && KT.dayLabel) ? KT.dayLabel(d) : (d || '');

    /* ALL OF ONE PERSON'S PAYSLIPS, IN ONE PLACE.

       The table is a payroll RUN — every document across every person, which is the right
       shape for "what went out this fortnight" and the wrong one for "send Amna her March
       payslip". That question meant scrolling a mixed list hunting for her name, and there
       was nowhere at all to email one from.

       So a name opens the person: their documents, newest first, each with View, Download
       and Email. The rows come from what the screen already loaded — no second request —
       and every action goes through the same helpers the table uses, so the two can never
       start behaving differently. (Anthony, 2026-09-10) */
    function openStaffPayslips(userId, name) {
      /* Same dialog as the payroll run's — this table just carries the documents in a
         different shape, so it maps and hands them over. `after` re-renders this ledger
         rather than the run, which is the only thing the two callers differ on. */
      const mine = rows.filter(r => String(r.user_id) === String(userId));
      const email = (mine.find(r => r.payee_email) || {}).payee_email || '';
      payslipsPopup(host, {
        title: name || 'Payslips',
        subtitle: email,
        email: email,
        userId: userId,
        docs: mine.map(r => ({
          id: r.id, kind: r.kind, reference: r.reference,
          period_start: r.period_start, period_end: r.period_end,
          status: r.status, net: (r.net != null ? r.net : r.gross),
          email: r.payee_email || email,
        })),
        after: renderPayrollDocs,
      });
    }
    const chip = (s) => {
      const tone = s === 'paid' ? ['#DCFCE7', '#166534'] : (s === 'void' ? ['#F1F5F9', '#64748B'] : ['#E0F2FE', '#075985']);
      return `<span style="font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px;background:${tone[0]};color:${tone[1]};">${escapeHtml(s.charAt(0).toUpperCase() + s.slice(1))}</span>`;
    };

    // A rate of zero is not a rounding problem — nobody has set what that person is paid,
    // and a payslip reading $0.00 with no explanation looks like a bug rather than a gap.
    const noRate = rows.filter(r => Number(r.rate) === 0);
    const banner = noRate.length
      ? `<div style="background:#FFF7ED;border:1px solid #FED7AA;color:#9A3412;border-radius:12px;padding:12px 14px;font-size:13px;margin:0 0 14px;">
           <strong>${noRate.length} document${noRate.length === 1 ? '' : 's'} show $0.00</strong> because no pay rate is set for
           ${new Set(noRate.map(r => r.user_id)).size} staff member(s). The hours are right; set a rate on each staff record and these fill in.</div>`
      : '';

    const GROUPS = [{ key: 'educators', label: '🧑‍🏫 Educators' }, { key: 'other', label: '👥 Other staff' }];
    const th = (a) => `text-align:${a};padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;`;
    const td = (a, x) => `padding:9px 8px;border-bottom:1px solid #F3F4F6;text-align:${a};${x || ''}`;

    host.innerHTML = banner + GROUPS.map(g => {
      const list = rows.filter(r => (r.staff_group === 'educators' ? 'educators' : 'other') === g.key);
      if (!list.length) return '';
      const t = (res.totals && res.totals[g.key]) || {};
      return `<div style="margin-bottom:22px;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 6px;">
          <h3 style="margin:0;font-size:15px;color:#1F6080;">${g.label}</h3>
          <span style="font-size:12.5px;color:#64748B;">${list.length} document${list.length === 1 ? '' : 's'} · ${t.people || 0} people · <strong style="color:#0F172A;">${money(t.gross)}</strong> gross${t.unpaid ? ` · <span style="color:#9A3412;">${money(t.unpaid)} unpaid</span>` : ''}</span>
        </div>
        <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:720px;"><thead><tr>
          <th style="${th('left')}">Staff</th><th style="${th('left')}">Type</th><th style="${th('left')}">Reference</th>
          <th style="${th('left')}">Period</th><th style="${th('right')}">Units</th><th style="${th('right')}">Gross</th>
          <th style="${th('left')}">Status</th><th style="${th('left')}"></th>
        </tr></thead><tbody>${list.map(r => `<tr>
          <td style="${td('left')}">${r.user_id
            ? `<button data-staff="${r.user_id}" data-n="${escapeHtml(r.payee_name || '')}" style="background:none;border:0;padding:0;font:inherit;font-weight:600;color:#1F6080;cursor:pointer;text-align:left;text-decoration:underline;">${escapeHtml(r.payee_name || '')}</button>`
            : escapeHtml(r.payee_name || '')}<div style="font-size:11.5px;color:#94A3B8;">${escapeHtml(r.role_label || '')}</div></td>
          <td style="${td('left')}">${r.kind === 'invoice' ? 'Invoice' : 'Payslip'}</td>
          <td style="${td('left')}">${escapeHtml(r.reference || '')}</td>
          <td style="${td('left')}">${escapeHtml(dt(r.period_start))}</td>
          <td style="${td('right')}">${Number(r.units) || 0} <span style="color:#94A3B8;font-size:11.5px;">${escapeHtml(r.unit_label || '')}</span></td>
          <td style="${td('right', 'font-weight:600;')}">${money(r.gross)}${
            r.net != null && Number(r.net).toFixed(2) !== Number(r.gross).toFixed(2)
              ? `<div style="font-size:11.5px;color:#64748B;font-weight:400;">net ${money(r.net)}</div>` : ''}</td>
          <td style="${td('left')}">${chip(r.status || 'issued')}</td>
          <td style="${td('left')}"><button data-pd-view="${r.id}" style="background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:5px 10px;font-size:12.5px;cursor:pointer;">View</button>
            ${r.status !== 'paid' ? `<button data-pd-paid="${r.id}" style="background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:5px 10px;font-size:12.5px;cursor:pointer;">Mark paid</button>` : ''}</td>
        </tr>`).join('')}</tbody></table></div></div>`;
    }).join('');

    host.querySelectorAll('[data-staff]').forEach(b => {
      b.onclick = () => openStaffPayslips(b.getAttribute('data-staff'), b.getAttribute('data-n'));
    });

    host.querySelectorAll('[data-pd-view]').forEach(b => {
      b.onclick = async () => {
        const label = b.textContent;
        b.disabled = true; b.textContent = 'Opening…';
        try {
          const pid = b.getAttribute('data-pd-view');
          const office = window.KTPayslipView ? await window.KTPayslipView.blob(pid) : null;   // office view: no password, Download = protected
          let blob = office;
          if (!blob) {
            const r = await fetch(`${(KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'}/payroll-documents/${pid}/pdf`,
              { headers: { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token')), Accept: 'application/pdf' } });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            blob = await r.blob();
          }
          if (window.KT && KT.openFile) { KT.openFile(blob, { title: 'Payroll document', filename: 'payroll-document.pdf', label: 'Payroll', protectedDownload: office ? () => window.KTPayslipView.save(pid) : undefined }); }
          else { const url = URL.createObjectURL(blob); window.open(url, '_blank'); setTimeout(() => URL.revokeObjectURL(url), 60000); }
        } catch (e) {
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not open that document', 'error');
        }
        b.disabled = false; b.textContent = label;
      };
    });
    host.querySelectorAll('[data-pd-paid]').forEach(b => {
      b.onclick = async () => {
        b.disabled = true; b.textContent = 'Saving…';
        try {
          await Api.post(`/provider/payroll-documents/${b.getAttribute('data-pd-paid')}/status`, { status: 'paid' });
          renderPayrollDocs();
        } catch (e) {
          b.disabled = false; b.textContent = 'Mark paid';
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not update that document', 'error');
        }
      };
    });
  }

  /* Hours must agree (2026-11-27): a row's whole minutes (the server's figure), so totals are rounded once, not added up from rounded rows. */
  function payMins(r) { return r && r.total_minutes != null ? Math.round(+r.total_minutes || 0) : Math.round(parseFloat((r && r.total_hours) || 0) * 60); }
  async function runPayroll() {
    // Guard against the user navigating away mid-fetch: the from/to inputs and the
    // result host all live on this screen, and firing this after leaving it threw
    // "Cannot set properties of null (setting 'innerHTML')".
    const fromEl = document.getElementById('pr-from'), toEl = document.getElementById('pr-to');
    if (!fromEl || !toEl) return;
    const f = fromEl.value, t = toEl.value;
    const res = await Api.get(`/admin/payroll?from=${f}&to=${t}`);
    const host = document.getElementById('pr-result');
    if (!host) return;
    if (!res.data || !res.data.length) { host.innerHTML = '<div style="color:#64748B;padding:12px;">No punches in range.</div>'; return; }
    // Two payrolls, not one list: educators and everyone else are paid on different
    // terms and signed off by different people, so each carries its own subtotal.
    // staff_group comes from the API; if it is ever absent everybody falls into one
    // group and this renders exactly as it used to.
    const GROUPS = [
      { key: 'educators', label: '🧑‍🏫 Educators' },
      { key: 'other', label: '👥 Other staff' },
    ];
    const byGroup = {};
    res.data.forEach(r => {
      const g = r.staff_group === 'educators' ? 'educators' : 'other';
      (byGroup[g] = byGroup[g] || []).push(r);
    });

    const th = (align) => `text-align:${align};padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;`;
    const td = (align, extra) => `padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:${align};${extra || ''}`;
    let grand = 0;

    /* The documents belonging to one row, hidden until their count is clicked.

       The last cell holds plain labelled buttons on purpose - kt-row-actions.js turns
       exactly that into the portal's own kebab. `userId` is null for a contractor, and
       that is what decides whether Interac can be offered at all. */
    /* Parked for the dialog rather than rendered as hidden rows under the table. The
       markup those rows produced is now the dialog's, so there is one place a payslip
       action is written. */
    const docRows = (key, docs, userId) => {
      PR_DOCS[key] = { docs: docs || [], userId: userId || null };
      return '';
    };


    const section = (g) => {
      const rows = byGroup[g.key] || [];
      if (!rows.length) return '';
      let sub = 0;
      const body = rows.map(r => {
        sub += payMins(r);   // minutes, rounded once below (hours must agree, 2026-11-27)
        const docs = r.documents || [];
        const paidNet = Number(r.paid_net || 0);
        return `<tr><td style="${td('left')}">${escapeHtml(r.user_name)}</td>`
          + `<td style="${td('left')}"><span style="font-size:11.5px;font-weight:700;color:#475569;background:#F1F5F9;border-radius:999px;padding:2px 9px;">${escapeHtml(r.role || 'Staff')}</span></td>`
          + `<td style="${td('left')}">${escapeHtml(r.centre_name || '')}</td>`
          + `<td style="${td('right')}">${r.punch_count}</td>`
          + `<td style="${td('right', 'font-weight:600;')}">${r.total_hours}</td>`
          + `<td style="${td('right')}">${docs.length
              ? `<button type="button" data-pr-docs="u${r.user_id}" data-n="${escapeHtml(r.user_name || '')}" style="border:0;background:none;cursor:pointer;color:#1F6080;font-weight:700;font-size:12.5px;padding:2px 4px;text-decoration:underline;">${docs.length} payslip${docs.length > 1 ? 's' : ''} &middot; $${paidNet.toFixed(2)}</button>`
              : '<span style="color:#94A3B8;">&mdash;</span>'}</td></tr>`
          + docRows('u' + r.user_id, docs, r.user_id);
      }).join('');
      grand += sub;
      return `<div style="margin-bottom:22px;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin:0 0 6px;">
          <h3 style="margin:0;font-size:15px;color:#1F6080;">${g.label}</h3>
          <span style="font-size:12.5px;color:#64748B;">${rows.length} ${rows.length === 1 ? 'person' : 'people'} · <strong style="color:#0F172A;">${(sub / 60).toFixed(2)} h</strong></span>
        </div>
        <table style="width:100%;border-collapse:collapse;"><thead><tr>
          <th style="${th('left')}">Staff</th><th style="${th('left')}">Role</th><th style="${th('left')}">Centre</th>
          <th style="${th('right')}">Punches</th><th style="${th('right')}">Hours</th><th style="${th('right')}">Payslips</th>
        </tr></thead><tbody>${body}
        <tr><td colspan="4" style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">${escapeHtml(g.label.replace(/^\S+\s/, ''))} subtotal</td>
        <td style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">${(sub / 60).toFixed(2)}</td>
        <td style="border-top:2px solid #CBD5E1;"></td></tr>
        </tbody></table></div>`;
    };

    /* CONTRACTORS: paid, never clocked. No punches, so no hours — what they were
       actually paid in the period is the only true thing to report, and it is said
       plainly rather than shown as a zero in an hours column. */
    const contractors = res.contractors || [];
    const contractorPanel = () => {
      if (!contractors.length) {
        return `<div style="color:#64748B;padding:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;">
          No contractor was paid in this period. Contractors do not clock in — they are paid by name,
          so nothing appears here until a payroll run includes one.</div>`;
      }
      const rows = contractors.map((c, ci) => `<tr>
        <td style="${td('left')}">${escapeHtml(c.payee_name)}</td>
        <td style="${td('left')}"><span style="font-size:11.5px;font-weight:700;color:#5B21B6;background:#EDE9FE;border-radius:999px;padding:2px 9px;">Contractor</span></td>
        <td style="${td('left')}">${escapeHtml(c.first_period || '')}${c.last_period && c.last_period !== c.first_period ? ' - ' + escapeHtml(c.last_period) : ''}</td>
        <td style="${td('right')}"><button type="button" data-pr-docs="c${ci}" data-n="${escapeHtml(c.name || c.payee_name || 'Contractor')}" style="border:0;background:none;cursor:pointer;color:#1F6080;font-weight:700;font-size:12.5px;padding:2px 4px;text-decoration:underline;">${c.documents_count}</button></td>
        <td style="${td('right', 'font-weight:600;')}">$${Number(c.net).toFixed(2)}</td></tr>`
        /* No user_id, so no Interac: /director/zum/send resolves a person's account to
           send to, and a contractor is a name rather than an account. Marked paid by
           hand instead, with the reason stated rather than the button just missing. */
        + docRows('c' + ci, c.documents, null)).join('');
      const total = contractors.reduce((a, c) => a + Number(c.net || 0), 0);
      return `<div style="margin-bottom:10px;padding:11px 13px;background:#EEF2FF;border:1px solid #C7D2FE;border-radius:9px;color:#3730A3;font-size:12.5px;">
          Contractors are paid by name and do not clock in, so this shows what was <strong>paid</strong> in the period, not hours worked.
        </div>
        <table style="width:100%;border-collapse:collapse;"><thead><tr>
          <th style="${th('left')}">Payee</th><th style="${th('left')}">Type</th><th style="${th('left')}">Period</th>
          <th style="${th('right')}">Payslips</th><th style="${th('right')}">Paid (net)</th>
        </tr></thead><tbody>${rows}
        <tr><td colspan="4" style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">Total paid</td>
        <td style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">$${total.toFixed(2)}</td></tr>
        </tbody></table>`;
    };

    /* SUBTABS, not three tables stacked. Educators, other staff and contractors are
       separate payrolls — different terms, different approvers, and in the contractors'
       case a different unit entirely — so only one is on screen at a time and its own
       subtotal is the number in view. */
    const PANELS = [
      { key: 'educators', label: '🧑‍🏫 Educators', count: (byGroup.educators || []).length },
      { key: 'other', label: '👥 Other staff', count: (byGroup.other || []).length },
      { key: 'contractors', label: '📄 Contractors', count: contractors.length },
    ];
    let pane = 'educators';
    // Land on a tab that has something in it rather than an empty one.
    const firstFilled = PANELS.find(p => p.count > 0);
    if (firstFilled) pane = firstFilled.key;

    const paintPanes = () => {
      const bar = PANELS.map(p => `<button type="button" data-pay-pane="${p.key}"
          style="background:none;border:0;border-bottom:2px solid ${pane === p.key ? '#1F6080' : 'transparent'};
          padding:9px 13px;font-size:13.5px;font-weight:700;color:${pane === p.key ? '#0F172A' : '#64748B'};
          cursor:pointer;border-radius:8px 8px 0 0;white-space:nowrap;">${p.label}
          <span style="font-weight:800;color:${pane === p.key ? '#1F6080' : '#94A3B8'};">${p.count}</span></button>`).join('');

      let body;
      if (pane === 'contractors') {
        body = contractorPanel();
      } else {
        const g = GROUPS.find(x => x.key === pane);
        grand = 0;
        body = section(g) || `<div style="color:#64748B;padding:14px;">Nobody in this group has punches in this range.</div>`;
      }

      /* The overall hours figure survives the split. Contractors are excluded from it
         on purpose - they have no hours, and folding a zero into a total labelled
         "hours worked" would state something untrue about them. */
      const allHours = (res.data || []).reduce((a, r) => a + payMins(r), 0) / 60;
      const footer = pane === 'contractors' ? '' :
        `<div style="display:flex;justify-content:flex-end;gap:14px;align-items:baseline;border-top:2px solid #1F6080;padding:12px 8px 0;margin-top:6px;">
          <span style="font-size:13px;color:#64748B;">All staff, both groups</span>
          <span style="font-size:14px;font-weight:700;color:#0F172A;">Total hours</span>
          <span style="font-size:18px;font-weight:800;color:#1F6080;">${allHours.toFixed(2)}</span></div>`;

      host.innerHTML = `<div style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 16px;padding:0 0 2px;">${bar}</div>${body}${footer}`;
      host.querySelectorAll('[data-pay-pane]').forEach(b => {
        b.onclick = () => { pane = b.getAttribute('data-pay-pane'); paintPanes(); };
      });

      /* A person's payslips, in a dialog. The row shows the count; the dialog shows the
         documents and everything you can do with one. */
      host.querySelectorAll('[data-pr-docs]').forEach(b => {
        b.onclick = () => {
          const key = b.getAttribute('data-pr-docs');
          const entry = PR_DOCS[key] || { docs: [], userId: null };
          payslipsPopup(host, {
            title: b.getAttribute('data-n') || 'Payslips',
            docs: entry.docs,
            userId: entry.userId,
          });
        };
      });
    };

    /* One delegated listener for every document action. paintPanes() replaces the whole
       table on each tab switch and after each action, so per-button handlers would be
       discarded every render. */
    /* The delegated listener is now a two-line adapter: every action lives in the
       module-scope runDocAction(), so the dialog can offer the same six buttons without a
       second copy of the code behind them. */
    if (!host.__prDocsWired) {
      host.__prDocsWired = true;
      host.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('[data-doc-act]') : null;
        if (!b || !host.contains(b)) { return; }
        ev.preventDefault();
        runDocAction(b.getAttribute('data-doc-act'), b, runPayroll);
      });
    }

    paintPanes();
    return;

    // eslint-disable-next-line no-unreachable
    let tot = 0;
    host.innerHTML = `<table style="width:100%;border-collapse:collapse;"><thead><tr>
      <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Staff</th>
      <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
      <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Punches</th>
      <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Hours</th></tr></thead><tbody>${res.data.map(r => {
        tot += payMins(r);
        return `<tr><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name)}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.centre_name || '')}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${r.punch_count}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:600;">${r.total_hours}</td></tr>`;
      }).join('')}<tr><td colspan="3" style="padding:10px 8px;text-align:right;font-weight:700;border-top:2px solid #1F6080;">Total</td><td style="padding:10px 8px;text-align:right;font-weight:700;border-top:2px solid #1F6080;">${(tot / 60).toFixed(2)}</td></tr></tbody></table>`;
  }

  /* ============================ Pay periods (2026-10-31) ============================
     Anthony: "payroll gets read from iLearn into KiddieTrac today - how do we see staff's payroll for each
     period?" The hours report answers who worked what over typed dates; this answers what each PAY PERIOD
     paid and to whom: one row per period the payslips carry (iLearn's or KiddieTrac's own), then that
     period's payslips with KiddieTrac's clock hours beside the hours each payslip paid. */
  var PP = { year: '', q: '', status: '', page: 1, per: 25, data: null };

  function ppMoney(n) { return n == null ? '—' : '$' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function ppMoney0(n) { return '$' + Math.round(Number(n) || 0).toLocaleString(); }
  function ppShort(n) { n = Number(n) || 0; return n >= 1000 ? '$' + (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k' : '$' + Math.round(n); }
  function ppDay(d) { return (window.KT && KT.dayLabel) ? KT.dayLabel(d) : (d || ''); }
  function ppPeriod(r) { return r.period_start === r.period_end ? ppDay(r.period_end) : ppDay(r.period_start) + ' – ' + ppDay(r.period_end); }
  function ppPeriodHtml(r) { var n = function (d) { return '<span style="white-space:nowrap;">' + escapeHtml(ppDay(d)) + '</span>'; }; return r.period_start === r.period_end ? n(r.period_end) : n(r.period_start) + ' – ' + n(r.period_end); }
  function ppGroup(g) { return g === 'educators' ? '🧑‍🏫 Educators' : '👥 Other staff'; }
  function ppStatus(s) {
    var t = s === 'paid' ? ['#DCFCE7', '#166534', '✓ Paid'] : (s === 'part_paid' ? ['#FEF3C7', '#92400E', 'Part paid'] : ['#E0F2FE', '#075985', 'Issued']);
    return '<span style="font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px;white-space:nowrap;background:' + t[0] + ';color:' + t[1] + ';">' + t[2] + '</span>';
  }
  function ppAvatar(p, size) {
    var nm = (p && p.name) || '';
    if (window.KT && KT.avatar) return KT.avatar(nm, { size: size || 26, photoUrl: p && p.photo_url, userId: p && p.user_id, initials: !(p && p.photo_url) });
    return '<span style="display:inline-flex;width:' + (size || 26) + 'px;height:' + (size || 26) + 'px;border-radius:50%;background:#CBD5E1;"></span>';
  }
  // A delta badge: ▲ 12% / ▼ 4%, green when up for money paid out is neutral, so colour only says direction.
  function ppDelta(now, before, opts) {
    opts = opts || {};
    if (before == null || !isFinite(before) || before === 0 || now == null) return '';
    var pct = (now - before) / Math.abs(before) * 100;
    if (Math.abs(pct) < 0.5) return '<span style="font-size:11.5px;font-weight:700;color:#475569;background:#F1F5F9;border-radius:999px;padding:2px 8px;white-space:nowrap;">= same</span>';
    var up = pct > 0;
    return '<span title="' + escapeHtml(opts.title || 'vs previous') + '" style="font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 8px;white-space:nowrap;'
      + (up ? 'color:#075985;background:#E0F2FE;' : 'color:#7C2D12;background:#FFEDD5;') + '">' + (up ? '▲ ' : '▼ ') + Math.abs(pct).toFixed(pct > -10 && pct < 10 ? 1 : 0) + '%</span>';
  }
  function ppCsv(name, headers, rows) {
    var esc = function (v) { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var text = [headers].concat(rows).map(function (r) { return r.map(esc).join(','); }).join('\r\n');
    var url = URL.createObjectURL(new Blob(['﻿' + text], { type: 'text/csv;charset=utf-8' }));
    var a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
  }

  /* Card chrome: white card, a subtle tinted band with an icon and title, then the body and a one-line plain-English
     caption that says what the card means. data-kt-nosechead: the band is ours, kt-tab-band must not add a second. */
  var PP_TINT = { blue: ['#EFF6FF', '#DBEAFE', '#1D4ED8'], teal: ['#ECFEFF', '#CFFAFE', '#0E7490'], amber: ['#FFFBEB', '#FEF3C7', '#B45309'],
    violet: ['#F5F3FF', '#EDE9FE', '#6D28D9'], green: ['#F0FDF4', '#DCFCE7', '#15803D'], slate: ['#F8FAFC', '#E2E8F0', '#334155'] };
  function ppCard(tint, icon, title, body, caption, extra) {
    var t = PP_TINT[tint] || PP_TINT.slate;
    return '<div class="pp-cardx kt-sec-card" style="background:#fff;border:1px solid #E6EBF1;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;min-width:0;' + (extra || '') + '">'
      + '<div data-kt-nosechead="1" style="display:flex;align-items:center;gap:8px;padding:9px 14px;background:linear-gradient(135deg,' + t[0] + ' 0%,#fff 100%);border-bottom:1px solid ' + t[1] + ';">'
      +   '<span aria-hidden="true" style="width:26px;height:26px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:' + t[1] + ';font-size:14px;">' + icon + '</span>'
      +   '<span style="font-size:12px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:' + t[2] + ';">' + title + '</span></div>'
      + '<div style="padding:12px 14px 4px;flex:1;min-width:0;">' + body + '</div>'
      + (caption ? '<div style="padding:4px 14px 12px;font-size:12px;color:#64748B;line-height:1.45;">' + caption + '</div>' : '')
      + '</div>';
  }
  function ppBig(v, delta) {
    return '<div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;"><span style="font-size:24px;font-weight:800;color:#0F172A;letter-spacing:-.3px;">' + v + '</span>' + (delta || '') + '</div>';
  }
  // Small inline trend (sparkline) - thin line, end dot, no axes.
  function ppSpark(vals, color) {
    if (!vals || vals.length < 2) return '';
    var w = 160, h = 34, max = Math.max.apply(null, vals), min = Math.min.apply(null, vals), rng = (max - min) || 1;
    var pts = vals.map(function (v, i) { return [Math.round(i * (w - 6) / (vals.length - 1)) + 3, Math.round(h - 4 - (v - min) / rng * (h - 8))]; });
    var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0] + ' ' + p[1]; }).join(' ');
    var last = pts[pts.length - 1];
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" preserveAspectRatio="none" aria-hidden="true" style="display:block;margin-top:6px;max-width:220px;">'
      + '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
      + '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="3.5" fill="' + color + '" stroke="#fff" stroke-width="2"/></svg>';
  }

  /* Payroll by month: grouped bars (gross light, net dark), one axis in dollars, hover/tap tooltip per month. */
  var PP_GROSS = '#9CC3EE', PP_NET = '#2A78D6';
  function ppMonthChart(months, width) {
    var n = months.length;
    if (!n) return '<div style="color:#64748B;font-size:13px;">No payslips yet.</div>';
    var W = Math.round(width || 640), H = W < 500 ? 190 : 210, padL = 46, padB = 26, padT = 10, innerW = W - padL - 8, innerH = H - padB - padT;
    var max = Math.max.apply(null, months.map(function (m) { return Math.max(m.gross, m.net); })) || 1;
    var step = Math.pow(10, Math.floor(Math.log10(max))), nice = Math.ceil(max / step) * step;
    if (nice / step <= 2) { step = step / 2; } nice = Math.ceil(max / step) * step;
    var slot = innerW / n, bw = Math.max(4, Math.min(16, slot / 2 - 4));
    var y = function (v) { return padT + innerH - v / nice * innerH; };
    var grid = '', ticks = 4;
    for (var i = 0; i <= ticks; i++) {
      var v = nice * i / ticks, yy = Math.round(y(v)) + .5;
      grid += '<line x1="' + padL + '" x2="' + (W - 8) + '" y1="' + yy + '" y2="' + yy + '" stroke="#E2E8F0" stroke-width="1"/>'
        + '<text x="' + (padL - 6) + '" y="' + (yy + 4) + '" text-anchor="end" font-size="11" fill="#64748B">' + ppShort(v) + '</text>';
    }
    var bars = '', hits = '';
    var bar = function (x, v, c) {
      if (!v) return '';
      var top = y(v), hgt = padT + innerH - top, r = Math.min(4, bw / 2, hgt);
      return '<path d="M' + x + ' ' + (padT + innerH) + 'V' + (top + r) + 'Q' + x + ' ' + top + ' ' + (x + r) + ' ' + top + 'H' + (x + bw - r) + 'Q' + (x + bw) + ' ' + top + ' ' + (x + bw) + ' ' + (top + r) + 'V' + (padT + innerH) + 'Z" fill="' + c + '"/>';
    };
    months.forEach(function (m, i) {
      var cx = padL + slot * i + slot / 2;
      bars += bar(cx - bw - 1, m.gross, PP_GROSS) + bar(cx + 1, m.net, PP_NET);
      var lab = new Date(m.month + '-01T12:00:00').toLocaleDateString(undefined, { month: 'short' });
      if (slot >= 30 || i % 2 === (n - 1) % 2) bars += '<text x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle" font-size="11" fill="#64748B">' + lab + '</text>';
      hits += '<rect data-ppm="' + i + '" x="' + (padL + slot * i) + '" y="' + padT + '" width="' + slot + '" height="' + innerH + '" fill="transparent" style="cursor:pointer;"/>';
    });
    return '<div class="pp-chart" style="position:relative;">'
      + '<div style="display:flex;gap:14px;font-size:12px;color:#334155;margin:0 0 6px;flex-wrap:wrap;">'
      +   '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:' + PP_GROSS + ';"></span>Gross</span>'
      +   '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:' + PP_NET + ';"></span>Net paid</span></div>'
      + '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img" aria-label="Payroll by month, gross and net" style="display:block;overflow:visible;">' + grid
      + '<line x1="' + padL + '" x2="' + (W - 8) + '" y1="' + (padT + innerH + .5) + '" y2="' + (padT + innerH + .5) + '" stroke="#94A3B8" stroke-width="1"/>'
      + bars + hits + '</svg>'
      + '<div class="pp-tip" role="status" style="position:absolute;pointer-events:none;display:none;background:#0F172A;color:#fff;border-radius:10px;padding:8px 10px;font-size:12px;line-height:1.5;box-shadow:0 10px 24px -8px rgba(15,23,42,.5);white-space:nowrap;z-index:2;"></div>'
      + '</div>';
  }
  function ppWireChart(host, months) {
    var wrap = host.querySelector('.pp-chart'); if (!wrap) return;
    var tip = wrap.querySelector('.pp-tip');
    var show = function (el) {
      var m = months[+el.getAttribute('data-ppm')]; if (!m) return;
      var lab = new Date(m.month + '-01T12:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
      tip.innerHTML = '<div style="font-weight:800;margin-bottom:2px;">' + escapeHtml(lab) + '</div>'
        + '<div>Gross <strong>' + ppMoney(m.gross) + '</strong></div><div>Net paid <strong>' + ppMoney(m.net) + '</strong></div>'
        + '<div style="opacity:.75;">' + m.docs + ' payslip' + (m.docs === 1 ? '' : 's') + ' · ' + m.people + ' ' + (m.people === 1 ? 'person' : 'people') + '</div>';
      tip.style.display = 'block';
      var wr = wrap.getBoundingClientRect(), r = el.getBoundingClientRect();
      var x = r.left - wr.left + r.width / 2 - tip.offsetWidth / 2;
      tip.style.left = Math.max(0, Math.min(wr.width - tip.offsetWidth, x)) + 'px';
      tip.style.top = '18px';
      wrap.querySelectorAll('[data-ppm]').forEach(function (h) { h.setAttribute('fill', h === el ? 'rgba(42,120,214,.07)' : 'transparent'); });
    };
    var hide = function () { tip.style.display = 'none'; wrap.querySelectorAll('[data-ppm]').forEach(function (h) { h.setAttribute('fill', 'transparent'); }); };
    wrap.querySelectorAll('[data-ppm]').forEach(function (el) {
      el.addEventListener('mouseenter', function () { show(el); });
      el.addEventListener('click', function () { show(el); });
    });
    wrap.addEventListener('mouseleave', hide);
  }
  /* Where the gross goes: one stacked bar (net + each deduction), 2px gaps, legend with amounts and shares. */
  function ppBreakdown(b) {
    var parts = [['Net paid', b.net, '#2a78d6'], ['CPP', b.cpp, '#eb6834'], ['EI', b.ei, '#1baf7a'], ['Income tax', b.income_tax, '#eda100'], ['Other deductions', b.other_deductions, '#e87ba4']];
    var total = parts.reduce(function (a, p) { return a + (p[1] || 0); }, 0);
    if (!total) return '<div style="color:#64748B;font-size:13px;">Nothing paid yet.</div>';
    var segs = parts.filter(function (p) { return p[1] > 0; }).map(function (p) {
      return '<span title="' + escapeHtml(p[0] + ': ' + ppMoney(p[1])) + '" style="flex:' + p[1] + ' 1 0;min-width:4px;background:' + p[2] + ';"></span>';
    }).join('');
    return '<div style="display:flex;gap:2px;height:16px;border-radius:6px;overflow:hidden;background:#F1F5F9;">' + segs + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr auto auto;gap:4px 12px;margin-top:10px;font-size:12.5px;align-items:center;">'
      + parts.map(function (p) {
          return '<span style="display:inline-flex;align-items:center;gap:7px;color:#334155;min-width:0;"><span style="width:10px;height:10px;border-radius:3px;flex:none;background:' + p[2] + ';"></span>' + p[0] + '</span>'
            + '<span style="text-align:right;font-weight:700;color:#0F172A;">' + ppMoney(p[1]) + '</span>'
            + '<span style="text-align:right;color:#64748B;min-width:44px;">' + (total ? (p[1] / total * 100).toFixed(p[1] / total < .01 && p[1] ? 2 : 1) : '0') + '%</span>';
        }).join('')
      + (b.vacation_pay ? '<span style="color:#334155;">＋ Vacation pay</span><span style="text-align:right;font-weight:700;color:#0F172A;">' + ppMoney(b.vacation_pay) + '</span><span></span>' : '')
      + '</div>';
  }
  function ppTop(top) {
    if (!top || !top.length) return '<div style="color:#64748B;font-size:13px;">No payslips yet.</div>';
    var max = top[0].net || 1;
    return top.map(function (p, i) {
      return '<div style="display:flex;align-items:center;gap:10px;padding:5px 0;">' + ppAvatar(p, 28)
        + '<div style="flex:1;min-width:0;"><div style="display:flex;justify-content:space-between;gap:8px;font-size:13px;"><span style="font-weight:700;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(p.name) + '</span>'
        + '<span style="font-weight:700;color:#0F172A;white-space:nowrap;">' + ppMoney0(p.net) + '</span></div>'
        + '<div style="height:6px;border-radius:4px;background:#EEF2F7;margin-top:4px;overflow:hidden;"><div style="height:100%;width:' + Math.max(3, p.net / max * 100) + '%;background:#2a78d6;border-radius:4px;"></div></div></div></div>';
    }).join('');
  }
  // Staff column: overlapping avatars + the first names; "+N more" lists everyone on hover/tap.
  function ppStaffCell(list) {
    list = list || [];
    if (!list.length) return '<span style="color:#94A3B8;">—</span>';
    var shown = list.slice(0, 3), more = list.length - shown.length;
    var faces = list.slice(0, 4).map(function (p, i) { return '<span style="display:inline-flex;margin-left:' + (i ? -8 : 0) + 'px;border:2px solid #fff;border-radius:50%;">' + ppAvatar(p, 24) + '</span>'; }).join('');
    var all = list.map(function (p) { return p.name; }).join(', ');
    return '<div style="display:flex;align-items:center;gap:8px;min-width:0;">'
      + '<span style="display:inline-flex;flex:none;">' + faces + '</span>'
      + '<span style="font-size:12.5px;color:#334155;line-height:1.35;min-width:0;">' + shown.map(function (p) { return escapeHtml(p.name); }).join(', ')
      + (more > 0 ? ' <span title="' + escapeHtml(all) + '" data-pp-more="1" style="display:inline-block;font-size:11.5px;font-weight:700;color:#1D4ED8;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:999px;padding:0 7px;white-space:nowrap;cursor:help;">+' + more + ' more</span>' : '')
      + '</span></div>';
  }

  var PP_COLS = ['Period', 'Frequency', 'Group', 'Staff', 'Payslips', 'People', 'Gross', 'Deductions', 'Vacation', 'Net', 'Status', 'Source'];
  function ppExportRows(list) {
    return list.map(function (r) {
      return [r.period_start + ' to ' + r.period_end, r.pay_frequency || '', r.staff_group === 'educators' ? 'Educators' : 'Other staff',
        (r.staff || []).map(function (p) { return p.name; }).join('; '),
        r.docs, r.people, r.gross.toFixed(2), r.deductions.toFixed(2), r.vacation.toFixed(2), r.net.toFixed(2),
        r.status === 'paid' ? 'Paid' : (r.status === 'part_paid' ? 'Part paid' : 'Issued'), r.source];
    });
  }

  async function renderPayPeriods(host) {
    if (!host) return;
    host.innerHTML = '<div style="color:#64748B;padding:14px;">Loading pay periods…</div>';
    var res;
    try { res = await Api.get('/admin/payroll/periods' + (PP.year ? '?year=' + encodeURIComponent(PP.year) : '')); }
    catch (e) { host.innerHTML = '<div style="color:#B91C1C;padding:14px;">Could not load pay periods. ' + escapeHtml((e && e.message) || '') + '</div>'; return; }
    PP.data = res;
    var all = res.data || [];
    var years = res.years || [];
    var chip = function (key, label) {
      var on = PP.status === key;
      return '<button type="button" role="tab" data-pp-st="' + key + '" data-kt-iconized="1" data-kt-btn-free="1" aria-selected="' + (on ? 'true' : 'false') + '" style="padding:7px 13px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">' + label + '</button>';
    };

    host.innerHTML = '<div id="pp-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:12px;margin:0 0 12px;"></div>'
      + '<div id="pp-cards2" class="pp-cards2" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:12px;margin:0 0 16px;align-items:start;"></div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0 0 12px;">'
      +   '<label style="font-size:12.5px;color:#374151;font-weight:600;">Year<select id="pp-year" style="display:block;margin-top:4px;min-width:110px;">'
      +     '<option value="">All years</option>' + years.map(function (y) { return '<option value="' + y + '"' + (String(PP.year) === String(y) ? ' selected' : '') + '>' + y + '</option>'; }).join('')
      +   '</select></label>'
      +   '<label style="font-size:12.5px;color:#374151;font-weight:600;flex:1;min-width:180px;max-width:340px;">Search<input id="pp-q" type="search" placeholder="Name, month, frequency…" value="' + escapeHtml(PP.q) + '" style="display:block;margin-top:4px;width:100%;"></label>'
      +   '<div role="tablist" aria-label="Show" style="display:flex;gap:4px;align-items:center;">' + chip('', 'All') + chip('open', '⏳ Outstanding') + chip('paid', '✓ Paid') + '</div>'
      + '</div>'
      + '<div id="pp-list"></div><div id="pp-pager"></div>';

    // ── the cards ──────────────────────────────────────────────────────────────────────────────
    var t = res.totals || {}, b = res.breakdown || {}, out = res.outstanding || {}, months = res.months || [];
    var nowYm = (function () { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); })();
    var done = months.filter(function (m) { return m.docs > 0 && m.month < nowYm; });
    var mNow = done[done.length - 1] || null, mPrev = done[done.length - 2] || null;
    var mName = function (m) { return m ? new Date(m.month + '-01T12:00:00').toLocaleDateString(undefined, { month: 'long' }) : ''; };
    var seats = all.reduce(function (a, r) { return a + (r.people || 0); }, 0);
    var avg = seats ? (t.net || 0) / seats : 0;
    var avgNow = mNow && mNow.people ? mNow.net / mNow.people : null, avgPrev = mPrev && mPrev.people ? mPrev.net / mPrev.people : null;
    var trend = months.slice(-12);
    var cards = host.querySelector('#pp-cards');
    if (cards) cards.innerHTML =
      ppCard('blue', '💵', 'Net paid', ppBig(ppMoney(t.net || 0)) + '<div style="font-size:12.5px;color:#475569;margin-top:4px;">' + (t.docs || 0) + ' payslips · ' + all.length + ' pay period' + (all.length === 1 ? '' : 's') + '</div>'
        + (mNow ? '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:#334155;margin-top:8px;">' + escapeHtml(mName(mNow)) + ' <strong>' + ppMoney(mNow.net) + '</strong>' + ppDelta(mNow.net, mPrev && mPrev.net, { title: 'vs ' + mName(mPrev) }) + '</div>' : ''),
        'Take-home pay that went out ' + (PP.year ? 'in ' + PP.year : 'across all years') + (mPrev ? '. The badge compares ' + escapeHtml(mName(mNow)) + ' with ' + escapeHtml(mName(mPrev)) + '.' : '.'))
      + ppCard('teal', '🧑‍🤝‍🧑', 'People paid', ppBig(String(t.people || 0)) + ppSpark(trend.map(function (m) { return m.people || 0; }), '#0E7490'),
        'Different people with a payslip. The line shows how many were paid each month' + (PP.year ? ' of ' + PP.year : ' over the last 12 months') + '.')
      + ppCard('violet', '⚖️', 'Average per person', ppBig(ppMoney(avg)) + (avgNow != null ? '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:#334155;margin-top:6px;">' + escapeHtml(mName(mNow)) + ' <strong>' + ppMoney(avgNow) + '</strong>' + ppDelta(avgNow, avgPrev, { title: 'vs ' + mName(mPrev) }) + '</div>' : ''),
        'Net pay per person per pay period, on average. The month figure is what each person received that month.')
      + ppCard('amber', '⏳', 'Outstanding', ppBig(ppMoney(out.net || 0)) + '<div style="font-size:12.5px;color:#475569;margin-top:4px;">' + (out.docs || 0) + ' payslip' + ((out.docs || 0) === 1 ? '' : 's') + ' · ' + (out.people || 0) + ' ' + ((out.people || 0) === 1 ? 'person' : 'people') + '</div>'
        + (out.docs ? '<button type="button" data-pp-st="open" data-kt-iconized="1" data-kt-btn-free="1" style="margin-top:8px;height:30px;padding:0 12px;border-radius:999px;border:1px solid #FCD34D;background:#FFFBEB;color:#92400E;font-weight:700;font-size:12.5px;cursor:pointer;">Show them ›</button>' : ''),
        'Payslips issued but not marked paid yet.');
    var cards2 = host.querySelector('#pp-cards2');
    if (cards2) cards2.innerHTML =
      ppCard('blue', '📊', 'Payroll by month', ppMonthChart(months, host.clientWidth && host.clientWidth < 900 ? Math.max(300, host.clientWidth - 30) : 640), 'Gross and net pay by the month each pay period ends' + (PP.year ? ' in ' + PP.year : ', last 12 months') + '. Hover or tap a month for the figures.')
      + '<div style="display:grid;gap:12px;min-width:0;">'
      +   ppCard('green', '🧾', 'Where the gross goes', ppBreakdown(b), 'Of every dollar earned: what reached staff and what went to deductions.')
      +   ppCard('slate', '🏅', 'Highest paid', ppTop(res.top || []), 'Total net per person ' + (PP.year ? 'in ' + PP.year : 'across all years') + '. Only managers see this.')
      + '</div>';
    ppWireChart(host, months);
    // Hours check: the newest period with hourly payslips, against KiddieTrac's clock.
    var hourly = all.filter(function (r) { return r.docs; });
    if (cards) {
      var hc = document.createElement('div');
      hc.innerHTML = ppCard('amber', '⏱', 'Hours check', '<div class="pp-hc" style="font-size:13px;color:#475569;">Checking…</div>', 'Payslip hours against clock-in hours for the same dates. A difference over half an hour is flagged.');
      cards.appendChild(hc.firstChild);
      (async function () {
        var el = cards.querySelector('.pp-hc'); if (!el) return;
        for (var i = 0; i < Math.min(6, hourly.length); i++) {
          var r = hourly[i];
          try {
            var d = await Api.get('/admin/payroll/periods/detail?start=' + encodeURIComponent(r.period_start) + '&end=' + encodeURIComponent(r.period_end)
              + '&group=' + encodeURIComponent(r.staff_group) + '&frequency=' + encodeURIComponent(r.pay_frequency || ''));
            var rows = (d && d.data) || [];
            if (!rows.some(function (x) { return x.paid_hours != null; })) continue;
            var f = (d.totals && d.totals.flagged) || 0;
            el.innerHTML = ppBig(String(f), f ? '<span style="font-size:11.5px;font-weight:700;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 8px;">⚠ to review</span>' : '<span style="font-size:11.5px;font-weight:700;color:#166534;background:#DCFCE7;border-radius:999px;padding:2px 8px;">✓ all match</span>')
              + '<div style="margin-top:4px;">in ' + escapeHtml(ppPeriod(r)) + '</div>'
              + '<button type="button" data-pp-hc="' + all.indexOf(r) + '" data-kt-iconized="1" data-kt-btn-free="1" style="margin-top:8px;height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:700;font-size:12.5px;cursor:pointer;">Open period ›</button>';
            var ob = el.querySelector('[data-pp-hc]'); if (ob) ob.onclick = function () { openPayPeriod(host, all[+ob.getAttribute('data-pp-hc')]); };
            return;
          } catch (e) { break; }
        }
        el.innerHTML = ppBig('—') + '<div style="font-size:12.5px;color:#475569;margin-top:4px;line-height:1.45;">Flat-amount payslips, so no hours to compare. Each period still shows clock-in hours.</div>';
      })();
    }

    // ── the list ───────────────────────────────────────────────────────────────────────────────
    var view = [];
    function filter() {
      var q = PP.q.trim().toLowerCase();
      view = all.filter(function (r) {
        if (PP.status === 'open' && r.status === 'paid') return false;
        if (PP.status === 'paid' && r.status !== 'paid') return false;
        if (!q) return true;
        return (ppPeriod(r) + ' ' + r.period_start + ' ' + r.period_end + ' ' + (r.pay_frequency || '') + ' ' + ppGroup(r.staff_group) + ' ' + r.source + ' ' + r.status + ' '
          + (r.staff || []).map(function (p) { return p.name; }).join(' ')).toLowerCase().indexOf(q) !== -1;
      });
    }
    function draw() {
      var list = host.querySelector('#pp-list');
      if (!list) return;
      if (!view.length) {
        list.innerHTML = '<div style="color:#64748B;padding:16px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;">'
          + (all.length ? 'No pay period matches that filter.' : 'No payslips yet for the agency you are viewing. Periods appear here as payslips are issued or sync in from iLearn.') + '</div>';
        if (window.KT && KT.pagerBar) KT.pagerBar(host.querySelector('#pp-pager'), 1, 1, function () {});
        return;
      }
      var pages = Math.max(1, Math.ceil(view.length / PP.per));
      if (PP.page > pages) PP.page = pages;
      var slice = view.slice((PP.page - 1) * PP.per, PP.page * PP.per);
      var th = function (a) { return 'text-align:' + a + ';padding:9px 8px;border-bottom:1px solid #E5E7EB;font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;white-space:nowrap;'; };
      var td = function (a, x) { return 'padding:10px 8px;border-bottom:1px solid #F1F5F9;text-align:' + a + ';vertical-align:middle;' + (x || ''); };
      list.innerHTML = '<div style="background:#fff;border:1px solid #E6EBF1;border-radius:12px;overflow:hidden;"><div style="overflow-x:auto;">'
        + '<table id="pp-table" data-kt-no-filter="1" style="width:100%;border-collapse:collapse;font-size:13.5px;"><thead><tr>'
        + '<th style="' + th('left') + '">Period</th><th style="' + th('left') + '">Staff paid</th><th style="' + th('left') + '">Group</th>'
        + '<th style="' + th('right') + '">Gross</th><th style="' + th('right') + '">Deductions</th>'
        + '<th style="' + th('right') + '">Net</th><th style="' + th('left') + '">Status</th><th style="' + th('left') + '"></th>'
        + '</tr></thead><tbody>'
        + slice.map(function (r) {
            var i = all.indexOf(r);
            return '<tr data-pp-row="' + i + '" style="cursor:pointer;">'
              + '<td style="' + td('left', 'min-width:150px;') + '"><div style="font-weight:700;color:#0F172A;">' + ppPeriodHtml(r) + '</div>'
              +   '<div style="font-size:11.5px;color:#94A3B8;">' + escapeHtml([r.pay_frequency || '', r.source].filter(Boolean).join(' · ')) + '</div></td>'
              + '<td style="' + td('left', 'min-width:260px;max-width:400px;') + '">' + ppStaffCell(r.staff) + '</td>'
              + '<td style="' + td('left', 'white-space:nowrap;') + '">' + ppGroup(r.staff_group) + '<div style="font-size:11.5px;color:#94A3B8;">' + r.docs + ' payslip' + (r.docs === 1 ? '' : 's') + '</div></td>'
              + '<td style="' + td('right') + '" data-kt-sort="' + r.gross + '">' + ppMoney(r.gross) + '</td>'
              + '<td style="' + td('right') + '" data-kt-sort="' + r.deductions + '">' + ppMoney(r.deductions) + '</td>'
              + '<td style="' + td('right', 'font-weight:800;color:#0F172A;') + '" data-kt-sort="' + r.net + '">' + ppMoney(r.net) + '</td>'
              + '<td style="' + td('left') + '">' + ppStatus(r.status) + '</td>'
              + '<td style="' + td('right', 'white-space:nowrap;') + '"><button type="button" data-pp-open="' + i + '" data-kt-iconized="1" data-kt-btn-free="1" style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;border-radius:8px;height:30px;padding:0 11px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">Open ›</button>'
              + ' <button type="button" data-pp-resend="' + i + '" data-kt-iconized="1" style="background:#fff;border:1px solid #CBD5E1;color:#0F172A;border-radius:8px;height:30px;padding:0 11px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">✉️ Resend all payslips</button></td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div></div>';
      var tbl = list.querySelector('#pp-table');
      if (tbl) {
        tbl.__ktRecordTotal = view.length;
        tbl.__ktExportAll = function () { return { headers: PP_COLS, rows: ppExportRows(view) }; };
      }
      list.querySelectorAll('[data-pp-resend]').forEach(function (rb) {
        rb.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          resendPeriodSlips(all[+rb.getAttribute('data-pp-resend')], null, rb);
        });
      });
      list.querySelectorAll('[data-pp-row]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
          if (e.target.closest('[data-pp-more]') && window.matchMedia && matchMedia('(hover:hover)').matches) return;
          if (e.target.closest('button') && !e.target.closest('[data-pp-open]')) return;
          openPayPeriod(host, all[+tr.getAttribute('data-pp-row')]);
        });
      });
      if (window.KT && KT.pagerBar) {
        KT.pagerBar(host.querySelector('#pp-pager'), PP.page, pages, function (pg) { PP.page = pg; draw(); },
          { size: PP.per, total: view.length, allowAll: true, onSize: function (n) { PP.per = n === 'all' || n === 0 ? 100000 : (+n || 25); PP.page = 1; draw(); } });
      }
      if (window.KT && KT.enhanceTables) setTimeout(KT.enhanceTables, 0);
    }
    filter(); draw();
    var ySel = host.querySelector('#pp-year');
    if (ySel) ySel.onchange = function () { PP.year = ySel.value; PP.page = 1; renderPayPeriods(host); };
    var qIn = host.querySelector('#pp-q');
    if (qIn) qIn.oninput = function () { PP.q = qIn.value; PP.page = 1; filter(); draw(); };
    host.querySelectorAll('[data-pp-st]').forEach(function (b2) {
      b2.onclick = function () {
        PP.status = b2.getAttribute('data-pp-st'); PP.page = 1;
        host.querySelectorAll('[role="tab"][data-pp-st]').forEach(function (c) {
          c.setAttribute('aria-selected', c.getAttribute('data-pp-st') === PP.status ? 'true' : 'false');
        });
        filter(); draw();
        var l = host.querySelector('#pp-list'); if (l && b2.closest('#pp-cards') && l.scrollIntoView) l.scrollIntoView({ behavior: 'smooth', block: 'start' });
      };
    });
  }

  var PPD_COLS = ['Staff', 'Role', 'Centre', 'Payslip hours', 'Clock hours', 'Difference', 'Rate', 'Gross', 'CPP', 'EI', 'Income tax', 'Other deductions', 'Vacation', 'Net', 'Previous period net', 'Status', 'Reference'];
  function ppdRows(list) {
    return list.map(function (r) {
      return [r.name, r.role, r.centre || '', r.paid_hours == null ? (r.unit_label || 'flat') : r.paid_hours, r.clock_hours == null ? '' : r.clock_hours,
        r.hours_diff == null ? '' : r.hours_diff, r.rate == null ? '' : r.rate, r.gross == null ? '' : r.gross.toFixed(2),
        r.cpp.toFixed(2), r.ei.toFixed(2), r.income_tax.toFixed(2), r.other_deductions.toFixed(2), r.vacation_pay.toFixed(2),
        r.net == null ? '' : r.net.toFixed(2), r.prev_net == null ? '' : r.prev_net.toFixed(2), r.status, r.reference || ''];
    });
  }

  async function openPayPeriod(host, p) {
    if (!p) return;
    var stale = document.querySelector('.pp-ov'); if (stale) stale.remove();
    var ov = document.createElement('div');
    ov.className = 'kt-scrim pp-ov';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;justify-content:center;'
      + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 12px) 12px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 12px);'
      + 'overflow:hidden;background:rgba(8,20,40,.55);';   // 2026-11-02: the backdrop no longer scrolls; the body below does (header stays put)
    var title = ppPeriod(p);
    ov.innerHTML = '<div class="pp-card" role="dialog" aria-modal="true" aria-label="Pay period ' + escapeHtml(title) + '" style="background:#fff;border-radius:16px;max-width:1240px;width:100%;margin:auto;max-height:100%;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="flex:0 0 auto;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;padding:16px 20px 19px;display:flex;align-items:center;gap:12px;">'
      +   '<div style="min-width:0;"><div style="font-size:17px;font-weight:800;">📅 Pay period · ' + escapeHtml(title) + '</div>'
      +   '<div style="font-size:12.5px;opacity:.85;margin-top:2px;">' + escapeHtml(ppGroup(p.staff_group).replace(/^\S+\s/, '')) + (p.pay_frequency ? ' · ' + escapeHtml(p.pay_frequency) : '') + ' · ' + escapeHtml(p.source) + '</div></div>'
      +   '<button type="button" class="pp-x" aria-label="Close" data-kt-iconized="1" data-kt-btn-free="1" style="margin-left:auto;background:rgba(255,255,255,.16);border:0;border-radius:9px;width:34px;height:34px;min-height:0;font-size:17px;cursor:pointer;color:#fff;">✕</button>'
      + '</div>'
      + '<div class="pp-body kt-dlg-scroll" data-kt-scroll="1" style="flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:14px 18px 18px;"><div style="color:#64748B;padding:10px;">Loading payslips…</div></div></div>';
    (host.closest('#appMain') || document.body).appendChild(ov);
    var close = function () { ov.remove(); document.removeEventListener('keydown', onKey); };
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelector('.pp-x').onclick = close;

    var body = ov.querySelector('.pp-body');
    var res;
    try {
      res = await Api.get('/admin/payroll/periods/detail?start=' + encodeURIComponent(p.period_start) + '&end=' + encodeURIComponent(p.period_end)
        + '&group=' + encodeURIComponent(p.staff_group) + '&frequency=' + encodeURIComponent(p.pay_frequency || ''));
    } catch (e) { body.innerHTML = '<div style="color:#B91C1C;padding:10px;">Could not load this period. ' + escapeHtml((e && e.message) || '') + '</div>'; return; }
    var rows = res.data || [], t = res.totals || {};
    var prevTotal = rows.reduce(function (a, r) { return a + (r.prev_net || 0); }, 0);
    var hasPrev = rows.some(function (r) { return r.prev_net != null; });
    var pp = res.prev_period;
    var th = function (a) { return 'text-align:' + a + ';padding:8px 7px;border-bottom:1px solid #E5E7EB;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;white-space:nowrap;'; };
    var td = function (a, x) { return 'padding:9px 7px;border-bottom:1px solid #F1F5F9;text-align:' + a + ';vertical-align:middle;' + (x || ''); };
    var hrs = function (r) { return r.paid_hours == null ? '<span style="color:#94A3B8;">' + escapeHtml(r.unit_label || 'flat') + '</span>' : r.paid_hours.toFixed(2); };
    var clock = function (r) {
      if (r.clock_hours == null) return '<span style="color:#94A3B8;">—</span>';
      var flag = r.hours_flag ? ' <span title="Payslip hours and clock hours differ" style="font-size:11px;font-weight:800;color:#92400E;background:#FEF3C7;border-radius:999px;padding:1px 7px;white-space:nowrap;">⚠ ' + (r.hours_diff > 0 ? '+' : '') + r.hours_diff.toFixed(2) + 'h</span>' : '';
      return r.clock_hours.toFixed(2) + flag;
    };
    var maxNet = Math.max.apply(null, rows.map(function (r) { return Math.max(r.net || 0, r.prev_net || 0); }).concat([1]));
    var DCOLS = [['cpp', 'CPP'], ['ei', 'EI'], ['income_tax', 'Tax'], ['other_deductions', 'Other'], ['vacation_pay', 'Vacation']]
      .filter(function (c) { return rows.some(function (r) { return Number(r[c[0]]) !== 0; }); });
    var hidden = 5 - DCOLS.length;
    // Net vs last period: two thin bars (last period light, this period dark) and the change.
    var netCell = function (r) {
      var now = r.net || 0, before = r.prev_net;
      return '<div style="font-weight:800;color:#0F172A;">' + ppMoney(r.net) + '</div>'
        + '<div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;margin-top:3px;">'
        +   '<div style="width:70px;display:grid;gap:2px;">'
        +     (before != null ? '<div title="Last period ' + escapeHtml(ppMoney(before)) + '" style="height:4px;border-radius:3px;background:#9CC3EE;width:' + Math.max(2, before / maxNet * 100) + '%;margin-left:auto;"></div>' : '')
        +     '<div title="This period ' + escapeHtml(ppMoney(now)) + '" style="height:4px;border-radius:3px;background:#2A78D6;width:' + Math.max(2, now / maxNet * 100) + '%;margin-left:auto;"></div>'
        +   '</div>' + (before != null ? ppDelta(now, before, { title: 'vs ' + (pp ? ppPeriod({ period_start: pp.start, period_end: pp.end }) : 'the previous period') }) : '<span style="font-size:11px;color:#94A3B8;">new</span>')
        + '</div>';
    };
    var stat = function (label, val, sub) {
      return '<div style="background:#F8FAFC;border:1px solid #E6EBF1;border-radius:12px;padding:9px 12px;min-width:0;">'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;">' + label + '</div>'
        + '<div style="font-size:18px;font-weight:800;color:#0F172A;margin-top:2px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">' + val + '</div>'
        + (sub ? '<div style="font-size:11.5px;color:#64748B;">' + sub + '</div>' : '') + '</div>';
    };
    body.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 14px;">'
      + stat('People', String(rows.length), (rows.length === 1 ? 'one payslip' : rows.length + ' payslips') + (t.clock_hours ? ' · ' + Number(t.clock_hours).toFixed(1) + ' clock hrs' : ''))
      + stat('Gross', ppMoney(t.gross))
      + stat('Deductions', ppMoney(t.deductions), 'CPP ' + ppMoney0(t.cpp) + ' · EI ' + ppMoney0(t.ei) + ' · tax ' + ppMoney0(t.income_tax))
      + stat('Net paid', ppMoney(t.net) + (hasPrev ? ppDelta(t.net, prevTotal) : ''), hasPrev && pp ? 'vs ' + escapeHtml(ppPeriod({ period_start: pp.start, period_end: pp.end })) : '')
      + stat('Hours check', t.flagged ? '⚠ ' + t.flagged : (rows.some(function (r) { return r.paid_hours != null; }) ? '✓ 0' : '—'),
          t.flagged ? 'with a difference over ½h' : (rows.some(function (r) { return r.paid_hours != null; }) ? 'payslips match the clock' : 'flat payslips, no hours to compare'))
      + '</div>'
      + '<div style="display:flex;justify-content:flex-end;margin:0 0 10px;"><button type="button" class="ppd-resend-all" data-kt-btn-free="1" data-kt-iconized="1" style="background:#fff;border:1px solid #CBD5E1;color:#0F172A;border-radius:8px;height:32px;padding:0 12px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">✉️ Resend all payslips</button></div>'
      + '<div style="border:1px solid #E6EBF1;border-radius:12px;overflow:hidden;"><div style="overflow-x:auto;">'
      + '<table id="ppd-table" style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>'
      + '<th style="' + th('left') + '">Staff</th><th style="' + th('right') + '">Payslip hrs</th><th style="' + th('right') + '">Clock hrs</th>'
      + '<th style="' + th('right') + '">Gross</th>' + DCOLS.map(function (c) { return '<th style="' + th('right') + '">' + c[1] + '</th>'; }).join('')
      + '<th style="' + th('right') + '">Net · vs last</th>'
      + '<th style="' + th('left') + '">Status</th><th style="' + th('left') + '"></th></tr></thead><tbody>'
      + rows.map(function (r) {
          var paid = String(r.status) === 'paid';
          return '<tr>'
            + '<td style="' + td('left', 'min-width:200px;') + '" data-kt-sort="' + escapeHtml(r.name.toLowerCase()) + '"><div style="display:flex;align-items:center;gap:10px;">' + ppAvatar(r, 34)
            +   '<div style="min-width:0;"><div style="font-weight:700;color:#0F172A;">' + escapeHtml(r.name) + '</div>'
            +   '<div style="font-size:11.5px;color:#64748B;">' + escapeHtml([r.role || '', r.centre || ''].filter(Boolean).join(' · ')) + (r.rate ? ' · ' + ppMoney(r.rate) + '/' + escapeHtml(r.unit_label || 'h') : '') + '</div></div></div></td>'
            + '<td style="' + td('right') + '" data-kt-sort="' + (r.paid_hours == null ? -1 : r.paid_hours) + '">' + hrs(r) + '</td>'
            + '<td style="' + td('right', 'white-space:nowrap;') + '" data-kt-sort="' + (r.clock_hours == null ? -1 : r.clock_hours) + '">' + clock(r) + '</td>'
            + '<td style="' + td('right') + '" data-kt-sort="' + (r.gross || 0) + '">' + ppMoney(r.gross) + '</td>'
            + DCOLS.map(function (c) { return '<td style="' + td('right') + '" data-kt-sort="' + (r[c[0]] || 0) + '">' + ppMoney(r[c[0]]) + '</td>'; }).join('')
            + '<td style="' + td('right', 'min-width:150px;') + '" data-kt-sort="' + (r.net || 0) + '">' + netCell(r) + '</td>'
            + '<td style="' + td('left') + '">' + ppStatus(paid ? 'paid' : 'issued')
            +   (r.last_sent ? '<div style="font-size:11px;color:#64748B;margin-top:3px;white-space:nowrap;" title="Last emailed">✉️ Sent ' + escapeHtml(ppSentLabel(r.last_sent)) + '</div>' : '') + '</td>'
            + '<td style="' + td('right', 'white-space:nowrap;') + '">'
            +   '<button type="button" data-doc-act="pdf" data-doc="' + r.id + '">View payslip</button> '
            +   '<button type="button" data-doc-act="save" data-doc="' + r.id + '">Download payslip</button> '
            +   '<button type="button" data-doc-act="email" data-doc="' + r.id + '" data-email="' + escapeHtml(r.email || '') + '" data-who="' + escapeHtml(r.first_name || '') + '" data-last="' + escapeHtml(r.last_sent ? ppSentLabel(r.last_sent) : '') + '">✉️ Resend payslip</button>'
            +   (paid ? '' : ' <button type="button" data-doc-act="status" data-to="paid" data-doc="' + r.id + '">Mark paid</button>')
            + '</td></tr>';
        }).join('')
      + '</tbody>'
      + '</table></div></div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:10px;line-height:1.5;">'
      +   (rows.some(function (r) { return r.paid_hours == null; }) ? 'Payslips paid as a flat amount carry no hours, so only the clock-in hours are shown for them. ' : '')
      +   (hidden ? (hidden === 5 ? 'No deductions or vacation pay on these payslips, so those columns are hidden (the export still has them). ' : 'Columns that are $0 for everyone are hidden (the export still has them). ') : '')
      +   '"vs last" compares each person with ' + (pp ? escapeHtml(ppPeriod({ period_start: pp.start, period_end: pp.end })) : 'the previous period') + '.</div>';
    var rab = body.querySelector('.ppd-resend-all');
    if (rab) rab.onclick = function () { resendPeriodSlips(p, rows, rab, function () { openPayPeriod(host, p); }); };
    var tbl = body.querySelector('#ppd-table');
    if (tbl) { tbl.__ktRecordTotal = rows.length; tbl.__ktExportAll = function () { return { headers: PPD_COLS, rows: ppdRows(rows) }; }; }
    // The payslip actions are the same code the hours report and the payslips popup use.
    body.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('[data-doc-act]') : null;
      if (!b || !body.contains(b)) return;
      ev.preventDefault();
      runDocAction(b.getAttribute('data-doc-act'), b, function () {
        openPayPeriod(host, p);
        var ph = document.getElementById('pr-periods-view'); if (ph && !ph.hidden) renderPayPeriods(ph);
      });
    });
    if (window.KT && KT.enhanceTables) setTimeout(KT.enhanceTables, 0);
    if (window.KT && KT.sweepRowActions) setTimeout(KT.sweepRowActions, 0);
  }

  // ============================ Agency billing config ============================
  async function renderAgencyBilling(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/admin/billing-config');
    if (!main.isConnected) return;   // replaced while loading: the newer screen draws itself (ticket #240)
    const a = res.data || {};
    main.innerHTML = `<div style="padding:24px;max-width:600px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Billing settings</h2>
      <p style="color:#6B7280;font-size:14px;">Per-agency late-fee + SMS + locale defaults. Applies to all centres in this agency.</p>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Late-fee percent (of overdue balance)
        <input id="lfp" type="number" step="0.01" min="0" max="25" value="${a.late_fee_percent || 1.5}" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Late-fee cap ($)
        <input id="lfc" type="number" step="0.01" min="0" value="${a.late_fee_cap || 25}" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Grace days
        <input id="lfg" type="number" min="0" max="60" value="${a.late_fee_grace_days || 0}" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">
        <input id="sms-en" type="checkbox" ${a.sms_enabled ? 'checked' : ''}> Enable SMS for this agency</label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Default locale
        <select id="loc" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="en" ${a.default_locale === 'en' ? 'selected' : ''}>English</option>
          <option value="fr" ${a.default_locale === 'fr' ? 'selected' : ''}>Français</option>
          <option value="es" ${a.default_locale === 'es' ? 'selected' : ''}>Español</option></select></label>
      <button id="cfg-save" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;margin-top:20px;cursor:pointer;">Save</button>
      <div id="cfg-msg" style="margin-top:14px;font-size:13px;"></div></div>`;
    document.getElementById('cfg-save').onclick = async () => {
      await Api.patch('/admin/billing-config', {
        late_fee_percent: parseFloat(document.getElementById('lfp').value),
        late_fee_cap: parseFloat(document.getElementById('lfc').value),
        late_fee_grace_days: parseInt(document.getElementById('lfg').value, 10),
        sms_enabled: document.getElementById('sms-en').checked,
        default_locale: document.getElementById('loc').value,
      });
      document.getElementById('cfg-msg').innerHTML = '<span style="color:#047857;">Saved.</span>';
    };
  }

  // ============================ Stripe parent autopay ============================
  async function renderAutopay(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/parent/billing/status').catch(() => ({}));
    if (!main.isConnected) return;   // replaced while loading: the newer screen draws itself (ticket #240)
    main.innerHTML = `<div style="padding:24px;max-width:520px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Auto-pay</h2>
      <p style="color:#6B7280;font-size:14px;">Save a card and we'll automatically charge each invoice when it's due. Cancel any time.</p>
      <div id="ap-state" style="background:#F9FAFB;padding:18px;border-radius:8px;margin-top:16px;">
        ${res.has_card ? `<div><strong>Saved card:</strong> •••• ${res.card_last4 || '****'}</div>
          <div style="margin-top:8px;"><strong>Auto-pay:</strong> ${res.autopay_enabled ? '<span style="color:#047857;font-weight:600;">on</span>' : '<span style="color:#6B7280;">off</span>'}</div>
          <button id="ap-toggle" data-kt-autosave="1" style="background:${res.autopay_enabled ? '#EF4444' : '#10B981'};color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-top:14px;cursor:pointer;">${res.autopay_enabled ? 'Turn off auto-pay' : 'Turn on auto-pay'}</button>
          <button id="ap-replace" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:6px;margin-top:14px;margin-left:8px;cursor:pointer;">Replace card</button>` :
        `<div>No card saved yet.</div><button id="ap-add" style="background:#1F6080;color:#fff;border:0;padding:11px 20px;border-radius:6px;margin-top:14px;cursor:pointer;">+ Add card</button>`}
      </div></div>`;
    if (res.has_card) {
      document.getElementById('ap-toggle').onclick = async () => { await Api.post('/parent/billing/autopay', { enabled: !res.autopay_enabled }); renderAutopay(main); };
      document.getElementById('ap-replace').onclick = () => startStripeFlow();
    } else {
      document.getElementById('ap-add').onclick = () => startStripeFlow();
    }
  }
  async function startStripeFlow() {
    if (!window.Stripe) {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/v3/';
      document.head.appendChild(s);
      await new Promise(r => s.onload = r);
    }
    const intent = await Api.post('/parent/billing/setup-intent', {});
    const pubKey = intent.publishable_key || '';
    if (!pubKey) { alert('Stripe not configured for this agency.'); return; }
    const stripe = window.Stripe(pubKey);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:22px;border-radius:14px;max-width:460px;width:94%;max-height:100%;overflow-y:auto;box-sizing:border-box;">
      <h3 style="margin:0 0 12px;">Save a card</h3>
      <div id="ap-element"></div>
      <div id="ap-err" style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>
      <div style="margin-top:16px;text-align:right;">
        <button id="ap-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="ap-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Save card</button>
      </div></div>`;
    document.body.appendChild(m);
    void stripe;
    // The one card form (kt-card-form.js): labelled fields where the phone can offer its camera card scan.
    const form = await KT.cardForm.mount(m.querySelector('#ap-element'), { publishableKey: pubKey, clientSecret: intent.client_secret });
    m.querySelector('#ap-cancel').onclick = () => m.remove();
    m.querySelector('#ap-save').onclick = async () => {
      let pm;
      try { pm = await form.confirm(); } catch (e) { m.querySelector('#ap-err').textContent = e.message; return; }
      await Api.post('/parent/billing/save-card', { payment_method: pm });
      m.remove();
      renderAutopay(document.getElementById('main') || document.querySelector('main'));
    };
  }

  // ============================ SMS ============================
  /* The "never asked" count, shown on the Text alerts tab so the number that explains a
     broadcast reaching nobody is visible without opening the tab. loadSmsConsent() is a
     module-level function and the tab bar is built inside renderSms(), so they meet here
     rather than through a lookup that would break if either is renamed. */
  var smsConsentBadge = 0;
  var _smsTabsRepaint = null;
  // The agency's own word for a facility — Centre, Provider or Room. KT.term() does
  // not exist; kt-term exposes centreWord(plural, lower).
  function _cw(plural, lower) {
    return (window.KT && KT.centreWord) ? KT.centreWord(plural, lower) : (plural ? 'Centres' : 'Centre');
  }

  async function renderSms(main) {
    // The fields used to be stacked full-width labels of differing widths, which made
    // the form look ragged. One card, one column of aligned rows: label left, control
    // right, every control the same width.
    /* THREE JOBS, THREE TABS (2026-09-18).

       Anthony: "SMS broadcast section has alot going on can you add subtabs for the TEXT
       Alerts, the table where it shows optd in etc."

       Fair - I added the consent panel and its 31-row table straight underneath the
       compose form, so the screen became a compose box, a summary, a full roster and a
       message log stacked in one column. They are three different jobs: writing a
       message, managing who may receive one, and looking at what was sent.

       PANES, NOT RE-RENDERS. Each tab is a sibling div toggled with `hidden`, so the
       compose form keeps whatever is half-typed in it when you look at the roster and
       come back. Switching tabs does not re-fetch either. */
    main.innerHTML = `<div class="kt-section">
      <div id="sms-tabs" role="tablist" aria-label="SMS" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;"></div>

      <div id="sms-pane-send">
      <div class="kt-card" style="max-width:720px;padding:20px;">
        <div style="display:grid;grid-template-columns:120px 1fr;gap:12px 14px;align-items:center;">
          <!-- TEXT OR A PHONE CALL. Same audience, same guards, two very different
               things to be on the receiving end of — so the channel is the first choice
               on the form rather than a checkbox somewhere below the message. -->
          <label style="font-size:13px;font-weight:600;color:#334155;">Send by</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;" id="sms-chan">
            <button type="button" data-chan="sms" style="height:32px;padding:0 14px;border-radius:8px;border:1px solid #1F6080;background:#1F6080;color:#fff;font-weight:700;font-size:13px;cursor:pointer;">💬 Text message</button>
            <button type="button" data-chan="voice" style="height:32px;padding:0 14px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;font-size:13px;cursor:pointer;">📞 Voice call</button>
          </div>

          <label for="sms-aud" style="font-size:13px;font-weight:600;color:#334155;">Audience</label>
          <select id="sms-aud" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;">
            <option value="role">By role</option>
            <option value="centre">By ${_cw(false, true)}</option>
            <option value="room">By room</option>
            <option value="agency">Whole agency</option>
          </select>

          <label for="sms-centre" id="sms-centre-l" style="font-size:13px;font-weight:600;color:#334155;">${_cw()}</label>
          <select id="sms-centre" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;"></select>

          <label for="sms-room" id="sms-room-l" style="font-size:13px;font-weight:600;color:#334155;">Room</label>
          <select id="sms-room" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;"></select>

          <label for="sms-role" id="sms-role-l" style="font-size:13px;font-weight:600;color:#334155;">Role</label>
          <select id="sms-role" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;">
            <option value="guardian">Parents</option><option value="educator">Educators</option><option value="centre_director">Directors</option>
          </select>

          <label for="sms-cat" id="sms-cat-l" style="font-size:13px;font-weight:600;color:#334155;">Reason</label>
          <select id="sms-cat" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;">
            <option value="closure">Closure</option>
            <option value="evacuation">Evacuation</option>
            <option value="lockdown">Lockdown</option>
            <option value="illness">Illness at the centre</option>
            <option value="emergency">Other emergency</option>
            <option value="broadcast">Not an emergency</option>
          </select>

          <label for="sms-body" id="sms-body-l" style="font-size:13px;font-weight:600;color:#334155;align-self:start;padding-top:8px;">Message</label>
          <div>
            <textarea id="sms-body" maxlength="300" rows="4" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;resize:vertical;font:inherit;"></textarea>
            <!-- The counter must not be wrapped into the note. The voice note is three
                 times longer than the text one, and with both allowed to flex the digits
                 ended up interleaved with the sentence. -->
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:4px;align-items:flex-start;">
              <span id="sms-note" style="font-size:12px;color:#64748B;flex:1 1 auto;">Only recipients who opted in to SMS and have a phone number on file will receive it.</span>
              <span id="sms-count" style="font-size:12px;color:#64748B;flex:0 0 auto;white-space:nowrap;">0 / 300</span>
            </div>
          </div>

          <div></div>
          <div>
          <div id="sms-reach" style="margin:0 0 8px;font-size:12.5px;"></div>
          <div style="display:flex;align-items:center;gap:12px;">
            <button id="sms-send" class="kt-btn kt-btn-primary" style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Send broadcast</button>
            <span id="sms-msg" style="font-size:13px;"></span>
          </div>
          </div>
        </div>
      </div>

      </div>

      <div id="sms-pane-replies" hidden>
        <div id="sms-replies"></div>
      </div>

      <div id="sms-pane-consent" hidden>
        <div id="sms-consent"></div>
      </div>

      <div id="sms-pane-recent" hidden>
        <h3 id="sms-recent-h" style="margin:0 0 10px;font-size:13px;color:#64748B;text-transform:uppercase;letter-spacing:.06em;">Sent &amp; received</h3>
        <div id="sms-recent"></div>
      </div>
    </div>`;

    /* The tab bar, in the house pattern (see immTabBar in screen-immunizations.js).
       The counts are filled in once the consent roster has loaded, because "Text alerts"
       on its own does not tell you that twenty people are waiting to be asked - which is
       the entire reason this screen reached nobody. */
    const SMS_TABS = [
      ['send', 'Send a message'],
      ['replies', 'Replies'],
      ['consent', 'Text alerts'],
      ['recent', 'Sent & received'],
    ];
    let smsTab = 'send';

    function paintSmsTabs() {
      const bar = document.getElementById('sms-tabs');
      if (!bar) return;
      bar.innerHTML = SMS_TABS.map(([id, label]) => {
        const on = id === smsTab;
        const badge = (id === 'consent' && smsConsentBadge)
          ? `<span style="margin-left:8px;background:${on ? 'rgba(255,255,255,.22)' : '#FEF3C7'};`
            + `color:${on ? '#fff' : '#B45309'};border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;">`
            + `${smsConsentBadge}</span>`
          : (id === 'replies' && smsRepliesBadge)
          ? `<span aria-label="${smsRepliesBadge} unread" style="margin-left:6px;background:#DC2626;color:#FFFFFF;`
            + `border-radius:999px;padding:1px 7px;font-size:11px;font-weight:800;">${smsRepliesBadge > 99 ? '99+' : smsRepliesBadge}</span>`
          : '';
        return `<button type="button" role="tab" aria-selected="${on ? 'true' : 'false'}" data-sms-tab="${id}" class="kt-tab${on ? ' active' : ''}" style="padding:8px 16px;`
          + `border:1px solid ${on ? '#1F6080' : '#D1D5DB'};border-radius:8px;`
          + `background:${on ? '#1F6080' : 'white'};color:${on ? 'white' : '#374151'};`
          + `font-weight:600;cursor:pointer;">${label}${badge}</button>`;
      }).join('');
      bar.querySelectorAll('[data-sms-tab]').forEach(b => {
        b.addEventListener('click', () => showSmsTab(b.getAttribute('data-sms-tab')));
      });
    }

    function showSmsTab(id, openId) {
      if (!SMS_TABS.some(([t]) => t === id)) id = 'send';
      smsTab = id;
      SMS_TABS.forEach(([t]) => {
        const pane = document.getElementById('sms-pane-' + t);
        if (pane) pane.hidden = (t !== id);
      });
      paintSmsTabs();
      // The address follows the tab (replaceState: no re-render, so a half-typed message survives).
      if (id === 'replies') { if (!openId) _srAddress(_srOpenId()); loadSmsReplies(openId || 0); }
      else { try { if (/^#sms\?/.test(location.hash)) history.replaceState(history.state, '', '#sms'); } catch (e) {} }
      /* A table drawn behind a hidden pane gets no search box and no sortable headers:
         the sweeps are driven by hashchange and the sweep bus, and an in-screen tab fires
         neither. Ask for them here, where the table is finally on screen. See
         kiddietrac-enhance-tables. */
      try { if (window.KT && KT.enhanceTables) KT.enhanceTables(); } catch (e) {}
    }

    const body = document.getElementById('sms-body');
    const counter = document.getElementById('sms-count');

    /* A text is capped at 300 characters because every 160 is another billed segment.
       A spoken announcement is not segmented, so it is capped at 800 — which is about
       forty seconds read aloud, and past that people hang up. */
    let channel = 'sms';
    const LIMIT = { sms: 300, voice: 800 };
    const recount = () => { counter.textContent = `${body.value.length} / ${LIMIT[channel]}`; };
    body.addEventListener('input', recount);

    const catRow = document.getElementById('sms-cat-l');
    const catSel = document.getElementById('sms-cat');
    catSel.addEventListener('change', function () { try { refreshReach(); } catch (e) {} });

    /* Only the reasons this agency allows calls for (Carrier settings → Voice calls,
       2026-09-29). Unticked reasons are removed from the list; the server refuses
       them anyway. With none allowed, the voice channel says so instead. */
    let voiceCatsLoaded = false;
    let voiceCatsNone = false;
    function loadVoiceCats() {
      if (voiceCatsLoaded) { return Promise.resolve(); }
      return Api.get('/admin/voice/categories').then(function (r) {
        voiceCatsLoaded = true;
        const on = ((r && r.data) || []).filter(function (c) { return c.enabled; }).map(function (c) { return c.key; });
        Array.prototype.slice.call(catSel.options).forEach(function (o) {
          if (on.indexOf(o.value) === -1) { o.remove(); }
        });
        voiceCatsNone = catSel.options.length === 0;
        if (voiceCatsNone) {
          const o = document.createElement('option');
          o.value = ''; o.textContent = 'No reasons are switched on for calls';
          catSel.appendChild(o);
        }
        if (channel === 'voice') { syncChannel(); }
      }).catch(function () { voiceCatsLoaded = true; });
    }
    const note = document.getElementById('sms-note');
    const bodyLabel = document.getElementById('sms-body-l');
    const recentH = document.getElementById('sms-recent-h');
    const sendBtn = document.getElementById('sms-send');

    function syncChannel() {
      document.querySelectorAll('#sms-chan [data-chan]').forEach(b => {
        const on = b.getAttribute('data-chan') === channel;
        b.style.background = on ? '#1F6080' : '#fff';
        b.style.color = on ? '#fff' : '#334155';
        b.style.borderColor = on ? '#1F6080' : '#CBD5E1';
      });
      const voice = channel === 'voice';
      if (voice) { loadVoiceCats(); }
      catRow.style.display = voice ? '' : 'none';
      catSel.style.display = voice ? '' : 'none';
      sendBtn.disabled = voice && voiceCatsNone;
      bodyLabel.textContent = voice ? 'Announcement' : 'Message';
      sendBtn.textContent = voice ? 'Place calls' : 'Send broadcast';
      recentH.textContent = voice ? 'Recent calls' : 'Sent & received';
      body.maxLength = LIMIT[channel];
      note.textContent = voice && voiceCatsNone
        ? 'No reasons are switched on for calls. Choose them in Carrier settings → Voice calls.'
        : voice
        ? 'Read aloud by an automated voice. An emergency reason reaches everyone with a phone '
          + 'number on file; anything else only reaches people who agreed to be contacted. Nobody '
          + 'who asked not to be telephoned is ever called.'
        : 'Only recipients who opted in to SMS and have a phone number on file will receive it.';
      recount();
      loadSmsRecent(channel);
    }

    document.querySelectorAll('#sms-chan [data-chan]').forEach(b => {
      b.addEventListener('click', () => {
        channel = b.getAttribute('data-chan'); syncChannel();
        /* The reachable count is channel-dependent: a call on an urgent category waives
           consent, a text never does. */
        try { refreshReach(); } catch (e) {}
      });
    });
    syncChannel();

    const aud = document.getElementById('sms-aud');
    const roleRow = document.getElementById('sms-role-l');
    const roleSel = document.getElementById('sms-role');
    const centreRow = document.getElementById('sms-centre-l');
    const centreSel = document.getElementById('sms-centre');
    const roomRow = document.getElementById('sms-room-l');
    const roomSel = document.getElementById('sms-room');

    // Only the picker the chosen audience needs is on screen. Showing all three invites
    // somebody to set a room and then send to the whole agency anyway.
    const syncRole = () => {
      const show = (el, on) => { el.style.display = on ? '' : 'none'; };
      show(roleRow, aud.value === 'role');   show(roleSel, aud.value === 'role');
      show(centreRow, aud.value === 'centre' || aud.value === 'room');
      show(centreSel, aud.value === 'centre' || aud.value === 'room');
      show(roomRow, aud.value === 'room');   show(roomSel, aud.value === 'room');
    };
    /* WHO THIS WOULD ACTUALLY REACH (2026-09-18).

       Anthony: "when sending to whole agency i don't see anything being sent."

       It was sending to nobody, and saying so in green. Consent is per person and almost
       nobody had given it - iLearn: 52 people, 42 with a phone, ONE opted in; Test Agency:
       31, 22 with phones, none. The audience filter drops people before the send loop, so
       unlike every other way a message gets dropped it left no skipped row and no reason.

       Asked BEFORE the send, not reported after it, because "text 41 families" and "text
       nobody" are different decisions and the compose form gave no way to tell which one
       was about to happen. */
    var reachEl = document.getElementById('sms-reach');
    var lastReach = null;

    function audiencePayload() {
      var p = { audience: aud.value, channel: channel };
      if (channel === 'voice') { p.category = catSel.value; }
      if (aud.value === 'role') { p.role = roleSel.value; }
      if (aud.value === 'centre') { p.centre_id = parseInt(centreSel.value, 10) || null; }
      if (aud.value === 'room') {
        p.centre_id = parseInt(centreSel.value, 10) || null;
        p.room_id = parseInt(roomSel.value, 10) || null;
      }
      return p;
    }

    async function refreshReach() {
      if (!reachEl) return;
      var p = audiencePayload();
      if ((p.audience === 'centre' && !p.centre_id) || (p.audience === 'room' && !p.room_id)) {
        lastReach = null; reachEl.innerHTML = ''; return;
      }
      var qs = Object.keys(p).filter(function (k) { return p[k] !== null && p[k] !== undefined && p[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]); }).join('&');
      reachEl.innerHTML = '<span style="color:#94A3B8;">Checking who this reaches…</span>';
      try {
        var b = await Api.get('/admin/sms/audience?' + qs);
        lastReach = b;
        var word = channel === 'voice' ? 'can be called' : 'can be texted';
        var bits = [];
        if (b.no_phone) bits.push(b.no_phone + ' with no mobile number');
        if (b.not_consented) bits.push(b.not_consented + ' who have not agreed');
        /* Amber at zero, not red: nothing has gone wrong yet and nothing has been sent.
           It is a warning about what is ABOUT to happen. */
        var tone = b.reachable === 0 ? '#B45309' : '#047857';
        reachEl.innerHTML = '<span style="color:' + tone + ';font-weight:600;">'
          + b.reachable + ' of ' + b.in_audience + ' ' + word + '</span>'
          + (bits.length ? '<span style="color:#64748B;"> · ' + bits.join(' · ') + '</span>' : '')
          + (b.consent_waived ? '<span style="color:#64748B;"> · urgent category, consent not required</span>' : '')
          + (b.reachable === 0
              ? '<div style="color:#B45309;margin-top:4px;">Nobody in this audience can receive it. '
                + 'Open the <b>Text alerts</b> tab to ask them to opt in.</div>'
              : '');
      } catch (e) {
        lastReach = null;
        reachEl.innerHTML = '';
      }
    }

    aud.addEventListener('change', function () { syncRole(); refreshReach(); });
    syncRole();

    // Centres, then the rooms of whichever centre is selected.
    let centres = [];
    try {
      const cr = await Api.get('/director/centres');
      centres = cr.centres || cr.data || (Array.isArray(cr) ? cr : []);
    } catch (e) { centres = []; }
    centreSel.innerHTML = centres.length
      ? centres.map(c => `<option value="${c.id}">${(c.name || ('#' + c.id))}</option>`).join('')
      : '<option value="">None available</option>';

    // Every room in the agency, each carrying its centre_id, fetched once. NOT
    // /director/rooms — that one resolves the centre from the signed-in user and ignores
    // the centre_id you ask for, so picking a second centre would offer the first
    // centre's rooms.
    let allRooms = [];
    try {
      const rr = await Api.get('/provider/educator-rooms');
      allRooms = rr.rooms || [];
    } catch (e) { allRooms = []; }

    function loadRooms() {
      const mine = allRooms.filter(r => String(r.centre_id) === String(centreSel.value));
      roomSel.innerHTML = mine.length
        ? mine.map(r => `<option value="${r.id}">${(r.name || ('#' + r.id))}</option>`).join('')
        : '<option value="">No rooms here</option>';
    }
    centreSel.addEventListener('change', function () { loadRooms(); refreshReach(); });
    roomSel.addEventListener('change', refreshReach);
    roleSel.addEventListener('change', refreshReach);
    loadRooms();
    refreshReach();

    document.getElementById('sms-send').onclick = async () => {
      const payload = {
        audience: aud.value,
        body: body.value,
        category: channel === 'voice' ? catSel.value : 'broadcast',
      };
      if (aud.value === 'role') payload.role = roleSel.value;
      // Send the id the audience needs. Without this, "by centre" reached everybody.
      if (aud.value === 'centre') payload.centre_id = parseInt(centreSel.value, 10) || null;
      if (aud.value === 'room') {
        payload.centre_id = parseInt(centreSel.value, 10) || null;
        payload.room_id = parseInt(roomSel.value, 10) || null;
      }
      if ((aud.value === 'centre' && !payload.centre_id) || (aud.value === 'room' && !payload.room_id)) {
        document.getElementById('sms-msg').innerHTML =
          '<span style="color:#B91C1C;">Choose which one to send to first.</span>';
        return;
      }
      /* A phone call is not undoable and not silent. Confirmed by count, because
         "call 214 people" and "call 4 people" are different decisions and the audience
         picker above does not make which one this is obvious. */
      /* CONFIRM BY COUNT, ON BOTH CHANNELS (2026-09-18).

         The call channel already confirmed, because a ringing phone is not undoable. SMS
         did not, and it turned out to need it more: a send that reaches nobody was
         indistinguishable from one that reached everybody until this line existed. The
         numbers come from the same preview shown under the box, which is the same query
         the send itself runs. */
      await refreshReach();
      var r0 = lastReach;
      if (r0 && r0.reachable === 0) {
        var why = [];
        if (r0.no_phone) why.push(r0.no_phone + ' have no mobile number');
        if (r0.not_consented) why.push(r0.not_consented + ' have not agreed to be ' + (channel === 'voice' ? 'called' : 'texted'));
        var go = window.KT && KT.confirm
          ? await KT.confirm('This will reach nobody.', {
              description: 'Of the ' + r0.in_audience + ' people in this audience, '
                + (why.join(' and ') || 'none can be contacted') + '.\n\n'
                + 'Send anyway, or close this and use the Text alerts tab to ask them to opt in?',
              okLabel: 'Send anyway',
            })
          : window.confirm('This will reach nobody. Send anyway?');
        if (!go) return;
      } else if (r0) {
        var verbC = channel === 'voice' ? 'Place ' + r0.reachable + ' announcement call' : 'Send this text to ' + r0.reachable + ' person';
        if (r0.reachable !== 1) verbC = channel === 'voice' ? 'Place ' + r0.reachable + ' announcement calls' : 'Send this text to ' + r0.reachable + ' people';
        var ok2 = window.KT && KT.confirm
          ? await KT.confirm(verbC + '?', {
              description: r0.in_audience > r0.reachable
                ? (r0.in_audience - r0.reachable) + ' of the ' + r0.in_audience + ' in this audience cannot be reached'
                  + (r0.no_phone ? ' (' + r0.no_phone + ' with no mobile number' + (r0.not_consented ? ', ' + r0.not_consented + ' who have not agreed)' : ')') : '')
                  + '.'
                : 'Everyone in this audience will receive it.',
              okLabel: channel === 'voice' ? 'Place calls' : 'Send',
            })
          : window.confirm(verbC + '?');
        if (!ok2) return;
      } else if (channel === 'voice') {
        var okV = window.KT && KT.confirm
          ? await KT.confirm('Place announcement calls now? Everyone this reaches will have their phone ring.')
          : window.confirm('Place announcement calls now?');
        if (!okV) return;
      }

      try {
        const r = channel === 'voice'
          ? await Api.post('/admin/voice/announce', payload)
          : await Api.post('/admin/sms/broadcast', payload);
        const done = channel === 'voice' ? r.placed : r.sent;
        const verb = channel === 'voice' ? 'Calling' : 'Sent to';
        /* Zero is not a success. It used to print in the same green as a send that
           reached four hundred people, which is how "I don't see anything being sent"
           went unexplained for so long. */
        const b = r.breakdown || {};
        const why = [];
        if (b.no_phone) why.push(b.no_phone + ' with no mobile number');
        if (b.not_consented) why.push(b.not_consented + ' who have not agreed');
        document.getElementById('sms-msg').innerHTML = done === 0
          ? `<span style="color:#B45309;font-weight:600;">Reached nobody.</span>`
            + `<span style="color:#64748B;"> ${b.in_audience || r.total || 0} in this audience`
            + `${why.length ? ' · ' + why.join(' · ') : ''}.</span>`
          : `<span style="color:#047857;">${verb} ${done}`
            + `${r.skipped ? ' · skipped ' + r.skipped : ''}`
            + `${why.length ? ' · could not reach ' + why.join(' · ') : ''}</span>`;
        refreshReach();
      } catch (e) {
        document.getElementById('sms-msg').innerHTML =
          `<span style="color:#B91C1C;">${escapeHtml((e && e.message) || 'Send failed')}</span>`;
      }
      loadSmsRecent(channel);
    };
    // syncChannel() above already drew the list for the starting channel.
    _smsTabsRepaint = paintSmsTabs;
    paintSmsTabs();
    /* '#sms?tab=replies&id=1867' - a notification or push: open that tab with that text open. */
    try {
      const q = new URLSearchParams((location.hash.split('?')[1] || ''));
      const want = q.get('tab');
      try { if (window.KT && KT.smsTexts) KT.smsTexts.close(); } catch (e) {}
      if (want && want !== 'send') showSmsTab(want, +q.get('id') || 0);
    } catch (e) {}
    /* Both lists are fetched now rather than on first click: they are two small reads,
       and a tab that pauses on a spinner the first time it is opened feels broken. */
    loadSmsConsent();
  }

  // Recent broadcasts as a REAL table, so it picks up the same search, sort and record
  // count as every other table on the site (kt-table-filter + kt-table-export attach to
  // any #appMain table). It used to be a hand-rolled list of divs, which got none of it.
  /* WHO CAN BE TEXTED, AND HOW TO ASK THE REST (2026-09-18).

     The reason an agency-wide broadcast reached nobody was never visible anywhere: consent
     is a per-person column and the only way to set it was a toggle inside Settings >
     Notifications, behind a confirm dialog. A parent does not go there. iLearn had 52
     people, 42 with a phone, and one opted in.

     Three ways to fix that, and all three sit beside the thing they fix:

       - ASK EVERYONE emails a signed, expiring link to a one-page yes/no. This is the only
         one that moves people already on the system.
       - RECORD lets a director who was told yes in person record it, stored WITH THEIR NAME.
       - and new families are asked during onboarding, so the problem stops growing.

     There is deliberately NO "turn everyone on" button. Consent the person did not give is
     not consent; it is a carrier complaint, and a column full of ones nobody can account
     for is worse than an empty one. */
  async function loadSmsConsent() {
    const host = document.getElementById('sms-consent');
    if (!host) return;
    host.innerHTML = '<div class="kt-card" style="padding:18px;color:#64748B;font-size:13px;">Loading...</div>';

    let r;
    try { r = await Api.get('/admin/sms/consent-coverage'); }
    catch (e) {
      host.innerHTML = '<div class="kt-card" style="padding:18px;color:#B91C1C;font-size:13px;">'
        + 'Could not load text-alert consent.</div>';
      return;
    }
    const sum = r.summary || {};
    const rows = r.data || [];
    const pill = (n, label, tint) => `<span style="display:inline-flex;align-items:center;gap:6px;`
      + `background:${tint}1A;color:${tint};border-radius:999px;padding:4px 11px;font-size:12px;font-weight:700;">`
      + `${n} ${label}</span>`;

    /* WHO THE BUTTON WOULD ACTUALLY EMAIL (2026-09-18).

       Anthony: "The ask 41 people by email - can we see the list before this gets sent
       out as we could not want all 41 sent out and does this include staff/contractors
       etc?"

       Both halves of that were right, and the second one found a bug I put here this
       morning. The label counted people whose status is `never_asked`, which REQUIRES a
       mobile number on file - but the send has no such filter, so at iLearn the button
       said 41 and would have emailed 51, the extra ten being people we hold no number
       for. Label and action have to be the same set.

       They belong in the set: the consent page asks for a number when we hold none, so
       these are exactly the people worth asking. But that is a different thing to be
       doing and the preview names them separately.

       AND IT IS EVERYONE. The invite query joins role_assignments with no role filter, so
       at iLearn it is 37 guardians, 9 educators, 4 agency admins and 2 home visitors.
       That is defensible - an educator gets sign-in alerts for their own room - but it is
       not what "ask the parents" sounds like, and it was nowhere on screen. */
    const candidates = rows.filter(x => x.status === 'never_asked' || x.status === 'no_phone')
      .filter(x => (x.email || '').trim() !== '');
    const askable = candidates.length;
    smsConsentBadge = askable;
    try { if (_smsTabsRepaint) _smsTabsRepaint(); } catch (e) {}

    host.innerHTML = `
      <div class="kt-card" style="padding:18px;">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;">
          ${pill(sum.opted_in || 0, 'can be texted', '#047857')}
          ${pill(sum.never_asked || 0, 'never asked', '#B45309')}
          ${pill(sum.declined || 0, 'declined', '#64748B')}
          ${pill(sum.no_phone || 0, 'no mobile number', '#64748B')}
        </div>
        <div style="font-size:13px;color:#475569;line-height:1.6;margin-bottom:14px;">
          Only people who have agreed can be sent a text. Emailing the link below asks them
          in one tap &mdash; no sign-in needed &mdash; and records their answer with the exact
          wording they were shown.
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <button id="sms-ask-all" class="kt-btn" ${askable ? '' : 'disabled'}
            style="background:${askable ? '#1F6080' : '#CBD5E1'};color:#fff;border:0;padding:9px 18px;
            border-radius:8px;cursor:${askable ? 'pointer' : 'default'};font-weight:600;">
            ${askable ? 'Ask ' + askable + ' ' + (askable === 1 ? 'person' : 'people') + ' by email&hellip;' : 'Everyone has been asked'}</button>
          <span id="sms-ask-msg" style="font-size:13px;"></span>
        </div>
      </div>
      <div class="kt-card" style="margin-top:12px;padding:0;overflow-x:auto;">
      <table data-kt-filter-always="1">
        <thead><tr>
          ${['Name', 'Roles', 'Mobile', 'Text alerts', ''].map(h =>
            `<th style="text-align:left;padding:9px 12px;font-size:11px;font-weight:700;color:#6B7280;
             letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">${h}</th>`).join('')}
        </tr></thead>
        <tbody>${rows.map(x => consentRow(x)).join('')}</tbody>
      </table></div>`;

    /* A roster of thirty-one is a list you search, not one you scroll: this is where a
       director goes to answer "has Natasha been asked?". The sweeps that add the search
       box and sortable headers run on hashchange and the sweep bus, and neither fires for
       a table drawn inside a tab - so ask for them directly. data-kt-filter-always keeps
       the box on a short roster too, where it would otherwise be skipped. */
    try { if (window.KT && KT.enhanceTables) KT.enhanceTables(); } catch (e) {}

    const askBtn = document.getElementById('sms-ask-all');
    if (askBtn && askable) {
      askBtn.onclick = async () => {
        /* A confirm dialog that says "email 41 people" is not consent to email 41 people
           when you cannot see who they are. Pick them. */
        const chosen = await pickInviteRecipients(candidates);
        if (!chosen || !chosen.length) return;
        askBtn.disabled = true;
        const msg = document.getElementById('sms-ask-msg');
        msg.innerHTML = '<span style="color:#64748B;">Sending...</span>';
        try {
          const res = await Api.post('/admin/sms/consent-invites', { user_ids: chosen });
          /* Suppressed is its own outcome, not a success and not a failure: the mail was
             built and then deliberately stopped, almost always by an agency-level switch.
             Saying "asked 22 people" when 22 were blocked is the same false success that
             made a suppressed form package look delivered. */
          const extra = []
            .concat(res.suppressed ? [res.suppressed + ' blocked before delivery'
              + (res.suppressed_reason ? ' (' + escapeHtml(res.suppressed_reason) + ')' : '')] : [])
            .concat(res.failed ? [res.failed + ' could not be emailed'] : []);
          msg.innerHTML = res.sent
            ? `<span style="color:#047857;">Asked ${res.sent} ${res.sent === 1 ? 'person' : 'people'}</span>`
              + (extra.length ? `<span style="color:#B45309;"> &middot; ${extra.join(' &middot; ')}</span>` : '')
            : `<span style="color:#B45309;font-weight:600;">Nobody was emailed.</span>`
              + (extra.length ? `<span style="color:#64748B;"> ${extra.join(' &middot; ')}</span>` : '');
        } catch (e) {
          msg.innerHTML = `<span style="color:#B91C1C;">${escapeHtml((e && e.message) || 'Could not send')}</span>`;
        }
        loadSmsConsent();
      };
    }

    host.querySelectorAll('[data-consent-user]').forEach(btn => {
      btn.onclick = async () => {
        const id = btn.getAttribute('data-consent-user');
        const name = btn.getAttribute('data-consent-name') || 'this person';
        /* Recorded consent needs a human behind it, so the dialog says what the director
           is attesting to rather than just asking "are you sure". */
        const ok = window.KT && KT.confirm
          ? await KT.confirm('Record that ' + name + ' agreed to text alerts?', {
              description: 'Only do this if they told you so. It is stored against your name as the '
                + 'person who recorded it, with the date and the wording they were read.',
              okLabel: 'Yes, they agreed',
            })
          : window.confirm('Record that ' + name + ' agreed to text alerts?');
        if (!ok) return;
        try {
          await Api.post('/admin/sms/consent/' + id, { agreed: true, note: 'Told a director in person' });
        } catch (e) {
          if (window.KT && KT.toast) KT.toast((e && e.message) || 'Could not record that', 'error');
        }
        loadSmsConsent();
      };
    });
  }

  /* SEE THE LIST BEFORE IT GOES (2026-09-18).

     Anthony: "can we see the list before this gets sent out as we could not want all 41
     sent out and does this include staff/contractors etc?"

     Grouped BY ROLE, because that is the question actually being asked. "Ask everyone" at
     iLearn meant 37 guardians, 9 educators, 4 agency admins and 2 home visitors, and a
     director who wants to ask the families and not their own staff had no way to say so.
     A whole group toggles in one click; individuals toggle underneath.

     Everyone starts TICKED. The alternative - nothing selected - makes the common case
     (ask the lot) into forty clicks, and the list is right there to untick from. What is
     NOT allowed is sending without opening this first: the button no longer has a path
     that skips it.

     People with no mobile number are a separate group rather than hidden or dropped. The
     consent page asks for a number when we hold none, so they are worth asking - but that
     is a different errand from confirming a number we already have, and the previous code
     silently included them while the button counted only the others. */
  function pickInviteRecipients(candidates) {
    return new Promise(resolve => {
      const ROLE_WORDS = {
        guardian: 'Parents and guardians', educator: 'Educators',
        agency_admin: 'Agency admins', centre_director: 'Directors',
        home_visitor: 'Home visitors', platform_admin: 'Platform admins',
      };
      /* One person can hold several roles. File them under the first one, so the groups
         partition the list and a head count across groups is the real total rather than
         a number bigger than the list. */
      const groups = new Map();
      candidates.forEach(x => {
        const noNum = (x.phone || '').trim() === '';
        const key = noNum ? '_nonumber' : String(x.roles || '').split(',')[0].trim() || '_other';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(x);
      });
      const order = [...groups.keys()].sort((a, b) => {
        if (a === '_nonumber') return 1;
        if (b === '_nonumber') return -1;
        return groups.get(b).length - groups.get(a).length;
      });

      const o = document.createElement('div');
      o.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:2147483640;'
        + 'display:flex;align-items:center;justify-content:center;padding:16px;';
      o.innerHTML = `<div style="background:#fff;border-radius:14px;width:100%;max-width:620px;
          max-height:86vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(15,23,42,.25);">
        <div style="padding:20px 22px 12px;border-bottom:1px solid #E5E7EB;">
          <div style="font-size:17px;font-weight:700;">Who should be asked?</div>
          <div style="font-size:13px;color:#64748B;margin-top:5px;line-height:1.5;">
            Each person gets their own link to a one-question page. No sign-in needed, and
            nobody is opted in until they say yes.</div>
        </div>
        <div id="inv-list" style="overflow-y:auto;padding:6px 22px 12px;flex:1 1 auto;"></div>
        <div style="padding:14px 22px;border-top:1px solid #E5E7EB;display:flex;gap:10px;
          align-items:center;justify-content:space-between;flex-wrap:wrap;">
          <button id="inv-none" style="background:transparent;border:none;color:#64748B;
            font-size:13px;cursor:pointer;padding:0;">Clear all</button>
          <div style="display:flex;gap:10px;align-items:center;">
            <button id="inv-cancel" style="background:transparent;border:1px solid #CBD5E1;
              border-radius:8px;padding:9px 16px;cursor:pointer;font-weight:600;color:#334155;">Cancel</button>
            <button id="inv-go" style="background:#1F6080;color:#fff;border:0;border-radius:8px;
              padding:9px 18px;cursor:pointer;font-weight:600;"></button>
          </div>
        </div>
      </div>`;

      const list = o.querySelector('#inv-list');
      list.innerHTML = order.map(key => {
        const people = groups.get(key);
        const title = key === '_nonumber' ? 'No mobile number on file'
          : (ROLE_WORDS[key] || key.replace(/_/g, ' '));
        const note = key === '_nonumber'
          ? 'The page will ask them for a number before it can turn texts on.'
          : '';
        return `<div style="margin-top:14px;">
          <label style="display:flex;gap:9px;align-items:center;cursor:pointer;
            background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;padding:9px 11px;">
            <input type="checkbox" data-inv-group="${escapeHtml(key)}" checked
              style="width:17px;height:17px;cursor:pointer;">
            <span style="font-weight:700;font-size:13.5px;color:#0F172A;">${escapeHtml(title)}</span>
            <span style="color:#64748B;font-size:12.5px;">${people.length}</span>
          </label>
          ${note ? `<div style="font-size:12px;color:#94A3B8;margin:5px 0 0 28px;">${note}</div>` : ''}
          <div style="margin:4px 0 0 12px;">${people.map(x => `
            <label style="display:flex;gap:9px;align-items:baseline;cursor:pointer;padding:5px 0;">
              <input type="checkbox" data-inv-user="${x.id}" data-inv-in="${escapeHtml(key)}" checked
                style="width:15px;height:15px;cursor:pointer;">
              <span style="font-size:13px;color:#334155;">${escapeHtml(x.name || ('#' + x.id))}
                <span style="color:#94A3B8;">${escapeHtml(x.email || '')}</span></span>
            </label>`).join('')}</div>
        </div>`;
      }).join('');

      const boxes = () => [...list.querySelectorAll('[data-inv-user]')];
      const go = o.querySelector('#inv-go');
      function recount() {
        const n = boxes().filter(b => b.checked).length;
        go.textContent = n ? `Email ${n} ${n === 1 ? 'person' : 'people'}` : 'Nobody selected';
        go.disabled = !n;
        go.style.background = n ? '#1F6080' : '#CBD5E1';
        go.style.cursor = n ? 'pointer' : 'default';
        /* A group box reflects its members rather than commanding them, so unticking one
           person does not leave the header claiming the whole group is going. */
        list.querySelectorAll('[data-inv-group]').forEach(g => {
          const key = g.getAttribute('data-inv-group');
          const mine = boxes().filter(b => b.getAttribute('data-inv-in') === key);
          const on = mine.filter(b => b.checked).length;
          g.checked = on === mine.length;
          g.indeterminate = on > 0 && on < mine.length;
        });
      }
      list.addEventListener('change', e => {
        const g = e.target.getAttribute && e.target.getAttribute('data-inv-group');
        if (g) {
          boxes().filter(b => b.getAttribute('data-inv-in') === g)
            .forEach(b => { b.checked = e.target.checked; });
        }
        recount();
      });
      o.querySelector('#inv-none').onclick = () => {
        boxes().forEach(b => { b.checked = false; });
        recount();
      };

      const close = v => { o.remove(); document.removeEventListener('keydown', esc); resolve(v); };
      function esc(e) { if (e.key === 'Escape') close(null); }
      document.addEventListener('keydown', esc);
      o.querySelector('#inv-cancel').onclick = () => close(null);
      o.addEventListener('click', e => { if (e.target === o) close(null); });
      o.querySelector('#inv-go').onclick = () =>
        close(boxes().filter(b => b.checked).map(b => parseInt(b.getAttribute('data-inv-user'), 10)));

      document.body.appendChild(o);
      recount();
    });
  }

  function consentRow(x) {
    const tone = {
      opted_in:    ['#047857', 'On'],
      never_asked: ['#B45309', 'Never asked'],
      declined:    ['#64748B', 'Declined'],
      no_phone:    ['#64748B', 'No mobile number'],
    }[x.status] || ['#64748B', '-'];
    const sourceWords = {
      app: 'the app', sms: 'a text reply', email_link: 'the emailed link',
      admin: 'a director', onboarding: 'onboarding',
    };
    const src = x.sms_opt_in && x.sms_consent_source
      ? `<div style="font-size:11px;color:#94A3B8;">via ${escapeHtml(sourceWords[x.sms_consent_source] || x.sms_consent_source)}</div>`
      : '';
    /* Recording on someone's behalf is offered ONLY where it is meaningful: they have a
       number, and they have not already answered for themselves. */
    const canRecord = x.status === 'never_asked';
    return `<tr style="border-bottom:1px solid #F3F4F6;">
      <td style="padding:9px 12px;font-weight:600;color:#111827;">${escapeHtml(x.name || ('#' + x.id))}
        <div style="font-size:11px;color:#94A3B8;font-weight:400;">${escapeHtml(x.email || '')}</div></td>
      <td style="padding:9px 12px;color:#475569;">${escapeHtml(x.roles || '')}</td>
      <td style="padding:9px 12px;color:#475569;">${escapeHtml(x.phone || '-')}</td>
      <td style="padding:9px 12px;"><span style="color:${tone[0]};font-weight:700;">${tone[1]}</span>${src}</td>
      <td style="padding:9px 12px;text-align:right;">${canRecord
        ? `<button data-consent-user="${x.id}" data-consent-name="${escapeHtml(x.name || 'this person')}"
             style="background:transparent;border:1px solid #CBD5E1;border-radius:7px;padding:5px 11px;
             font-size:12px;cursor:pointer;color:#334155;">Record a yes</button>`
        : ''}</td>
    </tr>`;
  }

  /* ============================ SMS → REPLIES (2026-11-02) ============================

     Anthony tapped a phone notification "Text reply from Bruni Meeser" and landed on the
     broadcast SEND screen, where the only trace of her text was one row at the bottom of
     "Sent & received". Replies is the inbox for texts in to the agency's number: newest
     first, unread marked, the conversation with that number, and a reply box that texts
     back through the agency's ordinary send path (SmsController::sendOne - consent, STOP,
     suppression and the agency switch all still decide; the server says why in words).

     Links: '#sms?tab=replies&id=<sms id>' opens this tab with that text open and
     highlighted (notifications and pushes carry it, App\Support\SmsInbound). The badge on
     the tab and on the nav item is GET /badges' sms_replies: exactly the unread rows this
     list shows (App\Support\SmsReplies::unread).

     Layout: list beside conversation on desktop; on a phone (<=760px) one at a time, with
     "All replies" to go back. Not a table, so no row kebab (CONVENTIONS.md). */
  var smsRepliesBadge = 0;
  function _srSetBadge(n) {
    n = +n || 0;
    try { if (window.KT && KT.Shell && KT.Shell.setBadge) KT.Shell.setBadge('sms_replies', n); } catch (e) {}
    if (n === smsRepliesBadge) return;
    smsRepliesBadge = n;
    // A new text while the inbox is on screen shows at once: KT.smsTexts reloads its list on the same event.
    try { if (_smsTabsRepaint && document.getElementById('sms-tabs')) _smsTabsRepaint(); } catch (e) {}
  }
  window.addEventListener('kt:badges', function (e) { if (e && e.detail) _srSetBadge(e.detail.sms_replies); });
  try { var _srNow = window.KT && KT.badges && KT.badges.now && KT.badges.now(); if (_srNow) _srSetBadge(_srNow.sms_replies); } catch (e) {}

  // The address says which reply is open, without re-rendering the screen (no hashchange).
  function _srAddress(id) {
    try {
      const h = '#sms?tab=replies' + (id ? '&id=' + id : '');
      if (location.hash !== h) history.replaceState(history.state, '', h);
    } catch (e) {}
  }

  /* The inbox itself is KT.smsTexts (kt-sms-replies.js, 2026-11-02): the same list and conversation
     Message Centre -> Texts shows, so the two can never drift. This tab is the agency overview. */
  function loadSmsReplies(openId) {
    const host = document.getElementById('sms-replies');
    if (!host) return;
    if (!(window.KT && KT.smsTexts)) {
      host.innerHTML = '<div class="kt-card" style="color:#64748B;padding:28px;text-align:center;font-size:13px;">Text replies are loading. Open this tab again in a moment.</div>';
      return;
    }
    KT.smsTexts.render(host, { place: 'sms', openId: +openId || 0, address: _srAddress });
  }
  function _srOpenId() { try { return (window.KT && KT.smsTexts) ? (+KT.smsTexts.openId() || 0) : 0; } catch (e) { return 0; } }

  async function loadSmsRecent(channel) {
    const voice = channel === 'voice';
    const r = await Api.get(voice ? '/admin/voice/calls' : '/admin/sms/messages').catch(() => ({ data: [] }));
    const host = document.getElementById('sms-recent');
    if (!host) return;
    const rows = (r && r.data) || [];
    if (!rows.length) {
      host.innerHTML = '<div class="kt-card" style="color:#64748B;padding:40px;text-align:center;font-size:13px;">'
        + (voice ? 'No calls placed yet.' : 'Nothing sent or received yet.') + '</div>';
      return;
    }
    /* A call has more ways to end than a text has. Green is only for a call that was
       actually answered and heard out; "no answer" is amber because it is a normal
       outcome and not a fault, and only a genuine failure is red. */
    const colour = (st) => ['sent', 'delivered', 'completed', 'spoken', 'received'].includes(st) ? '#047857'
      : ['failed', 'rejected'].includes(st) ? '#B91C1C' : '#D97706';

    /* REPLIES BELONG IN THIS LIST (2026-09-24).

       It was headed "Sent" and showed only what we sent, because until now that was
       all there was. Inbound texts are filed as rows too, and a list of one half of a
       conversation is how somebody concludes a parent never answered.

       An arrow rather than a word: the column is scanned, not read, and every row in
       a hundred-row list saying "Outbound" teaches the eye to skip it - which is
       exactly the wrong habit when three of those rows say the other thing. */
    const isIn = (m) => (m.direction || 'out') === 'in';
    const wayBadge = (m) => isIn(m)
      ? '<span title="Reply received from this number" style="display:inline-flex;align-items:center;gap:4px;'
        + 'background:#ECFDF5;border:1px solid #A7F3D0;color:#047857;border-radius:999px;padding:1px 8px;'
        + 'font-size:11px;font-weight:800;white-space:nowrap;">\u2190 Reply</span>'
      : '<span title="Sent by KiddieTrac" style="display:inline-flex;align-items:center;gap:4px;'
        + 'background:#F1F5F9;border:1px solid #E2E8F0;color:#475569;border-radius:999px;padding:1px 8px;'
        + 'font-size:11px;font-weight:700;white-space:nowrap;">\u2192 Sent</span>';
    host.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:14px;background:#fff;">
      <thead style="background:#F8FAFC;">
        <tr>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">${voice ? 'Called' : 'When'}</th>
          ${voice ? '' : '<th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">Direction</th>'}
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">${voice ? 'Recipient' : 'Person'}</th>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">Number</th>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">Status</th>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">${voice ? 'Announcement' : 'Message'}</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map(m => `<tr${!voice && isIn(m) ? ' style="background:#F6FEFB;"' : ''}>
          <!-- A TIMESTAMP, NOT A DAY (2026-09-28). This was fmtDate: the date alone, in the
               device's zone. A broadcast and its replies all land on one day, so the column
               could not say which came first. Agency timezone, with the time; sorts on the
               raw instant. -->
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;white-space:nowrap;" data-kt-sort="${escapeHtml(m.created_at || '')}" title="${escapeHtml(smsStampFull(m.created_at))}">${smsStamp(m.created_at)}</td>
          ${voice ? '' : `<td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;" data-kt-sort="${isIn(m) ? '0' : '1'}">${wayBadge(m)}</td>`}
          <!-- WHO, THEN WHICH NUMBER (2026-09-22). This was one cell showing the name OR
               the number, and for SMS the API returned no name at all - so a broadcast
               list was a column of bare phone numbers that told nobody whether the right
               parents were reached. Two cells, so both are searchable and sortable by the
               table tools, and a row whose user has been deleted still shows its number
               rather than an empty cell. -->
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;white-space:nowrap;font-weight:600;color:#111827;">${escapeHtml(m.to_name || '—')}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;white-space:nowrap;font-family:ui-monospace,monospace;font-size:12.5px;color:#475569;">${escapeHtml(m.to_phone || '—')}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;"><span style="color:${colour(m.status)};font-weight:600;">${escapeHtml((m.status || '').replace(/_/g, ' '))}</span>${m.error ? `<div style="font-size:11.5px;color:#94A3B8;">${escapeHtml(String(m.error).substring(0, 120))}</div>` : ''}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;">${escapeHtml((m.body || '').substring(0, 200))}</td>
        </tr>`).join('')}
      </tbody>
    </table>`;
  }

  // ============================ AI churn risk ============================
  async function renderAiChurn(main) {
    main.innerHTML = '<div style="padding:20px;">Computing…</div>';
    const res = await Api.get('/ai/churn-risk').catch(() => ({ data: [] }));
    const rows = res.data || [];
    const hi = rows.filter(r => r.bucket === 'high').length;
    const md = rows.filter(r => r.bucket === 'medium').length;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 8px;color:#1F6080;">Churn risk</h2>
      <p style="color:#6B7280;font-size:14px;">Heuristic score per family based on observation activity, sign-in attendance, and payment status. Updated each time you load this page.</p>
      <div style="display:flex;gap:12px;margin:20px 0;">
        <div style="flex:1;background:#FEE2E2;padding:18px;border-radius:8px;"><div style="color:#B91C1C;font-size:12px;font-weight:600;">HIGH RISK</div><div style="font-size:28px;font-weight:700;color:#B91C1C;">${hi}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:18px;border-radius:8px;"><div style="color:#92400E;font-size:12px;font-weight:600;">MEDIUM</div><div style="font-size:28px;font-weight:700;color:#92400E;">${md}</div></div>
        <div style="flex:1;background:#DCFCE7;padding:18px;border-radius:8px;"><div style="color:#166534;font-size:12px;font-weight:600;">LOW</div><div style="font-size:28px;font-weight:700;color:#166534;">${rows.length - hi - md}</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Family</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Score</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Signals</th></tr></thead>
        <tbody>${rows.slice(0, 50).map(r => `<tr>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.family_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="background:${r.bucket === 'high' ? '#FEE2E2' : r.bucket === 'medium' ? '#FEF3C7' : '#DCFCE7'};color:${r.bucket === 'high' ? '#B91C1C' : r.bucket === 'medium' ? '#92400E' : '#166534'};font-weight:700;padding:2px 8px;border-radius:4px;">${r.risk_score}</span></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;color:#6B7280;">${(r.signals || []).join(' · ') || '—'}</td></tr>`).join('')}</tbody></table></div>`;
  }

  // ============================ AI doc extraction ============================
  async function renderAiDocs(main) {
    main.innerHTML = `<div style="padding:24px;max-width:760px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">AI document extraction</h2>
      <p style="color:#6B7280;font-size:14px;">Upload a photo, scan or PDF of a document and we'll read its fields for you. Useful for new staff certifications, immunization records, etc. The file is kept private: it is never put at a public link.</p>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Upload a file (photo, scan or PDF)
        <input id="aid-file" type="file" accept="image/png,image/jpeg,image/webp,application/pdf" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;background:#fff;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Document type
        <select id="aid-type" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="immunization">Immunization record</option>
          <option value="certification">Staff certification</option>
          <option value="background_check">Background / VSS check</option>
          <option value="id">ID document</option>
          <option value="enrollment">Enrollment form</option>
        </select></label>
      <button id="aid-go" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;margin-top:18px;cursor:pointer;">Extract fields</button>
      <div id="aid-out" style="margin-top:18px;"></div></div>`;
    /* What was read, as a list of labelled fields (2026-11-27): the result used to be dumped as raw JSON. */
    const label = (k) => String(k).replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^./, (c) => c.toUpperCase());
    const show = (v) => {
      if (v == null || v === '') return '<span style="color:#94A3B8;">Not found</span>';
      if (Array.isArray(v)) return v.map((x) => (x && typeof x === 'object') ? Object.entries(x).map(([a, b]) => label(a) + ': ' + (b == null ? '' : b)).join(' · ') : String(x)).map(escapeHtml).join('<br>');
      if (typeof v === 'object') return Object.entries(v).map(([a, b]) => escapeHtml(label(a) + ': ' + (b == null ? '' : (typeof b === 'object' ? JSON.stringify(b) : b)))).join('<br>');
      if (v === true) return 'Yes';
      if (v === false) return 'No';
      return escapeHtml(String(v));
    };
    const paint = (fields) => {
      const rows = Object.entries(fields || {});
      if (!rows.length) return '<div class="kt-card" style="padding:18px;color:#64748B;">Nothing could be read from this document. Try a clearer photo or scan.</div>';
      return '<div class="kt-card" style="padding:0;overflow:hidden;"><table style="width:100%;border-collapse:collapse;">'
        + rows.map(([k, v]) => `<tr><th style="text-align:left;vertical-align:top;padding:9px 14px;border-bottom:1px solid #F1F5F9;font-size:12.5px;color:#475569;width:34%;">${escapeHtml(label(k))}</th><td style="padding:9px 14px;border-bottom:1px solid #F1F5F9;font-size:13.5px;color:#0F172A;">${show(v)}</td></tr>`).join('')
        + '</table></div>';
    };
    document.getElementById('aid-go').onclick = async () => {
      const out = document.getElementById('aid-out');
      out.innerHTML = '<div style="color:#64748B;font-size:13px;">Extracting…</div>';
      try {
        const fileEl = document.getElementById('aid-file');
        const docType = document.getElementById('aid-type').value;
        if (!(fileEl && fileEl.files && fileEl.files[0])) { out.innerHTML = '<div style="color:#B45309;font-size:13px;">Choose the photo, scan or PDF to read first.</div>'; return; }
        const fd = new FormData();
        fd.append('file', fileEl.files[0]);
        fd.append('doc_type', docType);
        const r = await Api.postForm('/ai/doc-extract', fd);
        out.innerHTML = paint(r && r.fields);
      } catch (e) { out.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + escapeHtml((e && e.message) || 'The document could not be read. Please try again.') + '</div>'; }
    };
  }

  // ============================ Locale picker ============================
  async function renderLocale(main) {
    const res = await Api.get('/locale');
    if (!main.isConnected) return;   // replaced while loading: the newer screen draws itself (ticket #240)
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Language / Langue / Idioma</h2>
      <p style="color:#6B7280;font-size:14px;">Current: <strong>${res.locale}</strong></p>
      <div style="margin-top:18px;max-width:520px;">
        ${['en', 'fr', 'es'].map(l => `<button data-loc="${l}" style="background:${res.locale === l ? '#1F6080' : '#F3F4F6'};color:${res.locale === l ? '#fff' : '#374151'};border:0;padding:14px 22px;border-radius:6px;margin-right:8px;cursor:pointer;font-weight:600;">${{ en: 'English', fr: 'Français', es: 'Español' }[l]}</button>`).join('')}
      </div></div>`;
    main.querySelectorAll('button[data-loc]').forEach(b => b.onclick = async () => {
      await Api.post('/locale', { locale: b.dataset.loc });
      window.location.reload();
    });
  }

  // ============================ Helpers ============================
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  /* ONE WAY TO FETCH A PAYSLIP, AND ONE WAY TO SPEAK.

     Two screens in this file open payslips — the payroll run and the per-person popup —
     and a second copy of the fetch is where they would start disagreeing about which
     header to send or what a failure means. Lifted here so there is one.

     Fetched with the bearer rather than linked: a plain href gets a 401, and a payslip
     URL in the address bar is somebody's pay left in browser history. */
  async function payslipBlob(id) {
    const tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    const base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    const r = await fetch(`${base}/payroll-documents/${id}/pdf`, {
      headers: { Authorization: 'Bearer ' + tok, Accept: 'application/pdf' },
    });
    if (!r.ok) { throw new Error('That payslip could not be opened (' + r.status + ').'); }
    return await r.blob();
  }

  /* OFFICE VIEW (Payroll P2, 2026-11-27, Anthony: "admins/directors view payslips without the password - password only
     applies when sending the payslip to the staff member or downloading it to anyone's computer"). /view answers an
     unprotected copy, for reading in the portal only (no-store, audited payslip.viewed), to the people who may see this
     person's pay; anyone else gets null here and the protected /pdf, exactly as before. Every Download from the viewer
     goes through payslipSave(), i.e. the protected /pdf. */
  async function payslipViewBlob(id) {
    try {
      const tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      const base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      const r = await fetch(`${base}/provider/payroll-documents/${id}/view`, { headers: { Authorization: 'Bearer ' + tok, Accept: 'application/pdf' }, cache: 'no-store' });
      return r.ok ? await r.blob() : null;
    } catch (e) { return null; }
  }
  async function payslipSave(id) {
    try {
      const url = URL.createObjectURL(await payslipBlob(id));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'payslip-' + id + '.pdf';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch (e) { say('That payslip could not be downloaded. Please try again.'); }
  }
  window.KTPayslipView = { blob: payslipViewBlob, save: payslipSave };

  function say(m) { return (window.KT && KT.toast) ? KT.toast(m, 'info') : alert(m); }

  /* THE DOCUMENTS FOR ONE PERSON, KEYED BY THE BUTTON THAT OPENS THEM.

     The payroll run renders a row per person and knows their documents at render time;
     the click happens later. Rather than serialising a list into a data- attribute, the
     render parks it here and the handler looks it up. Cleared on each render so a stale
     person cannot be opened from a table that no longer shows them. */
  var PR_DOCS = {};

  /**
   * All of one person's payslips, in a dialog.
   *
   * The list used to expand INLINE under their row — which works for one person and falls
   * apart the moment somebody has six documents and you are trying to read the table
   * around them. A dialog gives the documents the whole width, keeps the run table intact
   * underneath, and is the shape somebody actually wants when the task is "send Amna her
   * March payslip" rather than "what went out this fortnight".
   *
   * Rendered INSIDE `host` on purpose. Every action here is served by the delegated
   * [data-doc-act] listener bound to that host, so putting the dialog anywhere else would
   * mean a second copy of the action code — and the two would drift. The overlay is
   * position:fixed, so where it sits in the DOM changes nothing about how it looks.
   * (Anthony, 2026-09-10)
   */
  function payslipsPopup(host, opts) {
    var docs = opts.docs || [];
    var stale = host.querySelector('.pr-slip-ov');
    if (stale) { stale.remove(); }

    var money = function (n) { return '$' + (Number(n) || 0).toFixed(2); };
    var day = function (d) { return (window.KT && KT.dayLabel) ? KT.dayLabel(d) : (d || ''); };

    var net = docs.reduce(function (a, d) { return a + (Number(d.net) || 0); }, 0);
    var unpaid = docs.filter(function (d) { return String(d.status) !== 'paid' && String(d.status) !== 'void'; })
      .reduce(function (a, d) { return a + (Number(d.net) || 0); }, 0);

    var ov = document.createElement('div');
    ov.className = 'kt-scrim pr-slip-ov';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
      + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';

    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:880px;width:100%;margin:auto;'
      + 'overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
      +   '<div style="min-width:0;">'
      +     '<div style="font-size:17px;font-weight:800;color:#0F172A;">💵 ' + escapeHtml(opts.title || 'Payslips') + '</div>'
      +     '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">'
      +       docs.length + ' document' + (docs.length === 1 ? '' : 's') + ' · ' + money(net) + ' net'
      +       (unpaid ? ' · <span style="color:#9A3412;font-weight:700;">' + money(unpaid) + ' unpaid</span>' : '')
      +       (opts.subtitle ? ' · ' + escapeHtml(opts.subtitle) : '')
      +     '</div>'
      +   '</div>'
      +   '<button class="pr-slip-x" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;'
      +     'background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
      + '</div>'
      + '<div style="padding:14px 22px 20px;max-height:min(72vh,760px);overflow-y:auto;" data-kt-scroll="1">'
      + (docs.length
          ? docs.map(function (d) {
              var isPaid = String(d.status) === 'paid';
              var period = (d.period_start === d.period_end)
                ? day(d.period_end)
                : (day(d.period_start) + ' – ' + day(d.period_end));
              return '<div style="border:1px solid #E5E7EB;border-radius:12px;padding:13px 15px;margin-bottom:10px;">'
                + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
                +   '<div style="font-weight:700;color:#0F172A;font-size:14px;">'
                +     escapeHtml(d.kind === 'invoice' ? 'Payroll invoice' : 'Payslip')
                +     (d.reference ? ' <span style="font-weight:500;color:#64748B;">· ' + escapeHtml(d.reference) + '</span>' : '')
                +   '</div>'
                +   (isPaid
                      ? '<span style="font-size:11px;font-weight:800;color:#166534;background:#DCFCE7;border-radius:999px;padding:2px 9px;">Paid</span>'
                      : '<span style="font-size:11px;font-weight:800;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 9px;">'
                        + escapeHtml(d.status || 'issued') + '</span>')
                +   '<div style="margin-left:auto;font-weight:800;color:#0F172A;">' + money(d.net) + '</div>'
                + '</div>'
                + '<div style="font-size:12.5px;color:#64748B;margin-top:3px;">' + escapeHtml(period) + '</div>'
                + '<div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:11px;">'
                +   '<button type="button" data-doc-act="pdf" data-doc="' + d.id + '" style="' + SLIP_BTN + '">👁 View</button>'
                +   '<button type="button" data-doc-act="save" data-doc="' + d.id + '" style="' + SLIP_BTN + '">⬇️ Download</button>'
                +   '<button type="button" data-doc-act="print" data-doc="' + d.id + '" style="' + SLIP_BTN + '">🖨 Print</button>'
                +   '<button type="button" data-doc-act="email" data-doc="' + d.id + '" data-email="'
                +     escapeHtml(d.email || opts.email || '') + '" data-who="' + escapeHtml(String(opts.title || '').split(' ')[0]) + '" style="' + SLIP_BTN_P + '">✉️ Resend payslip</button>'
                +   ((opts.userId && !isPaid)
                        ? '<button type="button" data-doc-act="zum" data-doc="' + d.id + '" data-user="' + opts.userId
                          + '" data-net="' + d.net + '" style="' + SLIP_BTN + '">Send by Interac</button>'
                        : '')
                +   '<button type="button" data-doc-act="status" data-doc="' + d.id + '" data-to="'
                +     (isPaid ? 'issued' : 'paid') + '" style="' + SLIP_BTN + '">'
                +     (isPaid ? 'Mark unpaid' : 'Mark as paid') + '</button>'
                + '</div></div>';
            }).join('')
          : '<div style="padding:26px;text-align:center;color:#64748B;">No payroll documents for this person in the period shown.</div>')
      + '</div></div>';

    host.appendChild(ov);
    ov.querySelector('.pr-slip-x').addEventListener('click', function () { ov.remove(); });

    /* Wired directly rather than left to a delegated listener: this dialog is opened from
       two different hosts and only one of them has that listener. Same function either
       way, so the buttons cannot behave differently depending on where you opened it. */
    ov.querySelectorAll('[data-doc-act]').forEach(function (b) {
      b.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();          // or the host's delegation would run it twice
        runDocAction(b.getAttribute('data-doc-act'), b, function () {
          ov.remove();                 // the numbers moved; the table behind is redrawing
          if (typeof opts.after === 'function') { opts.after(); }
        });
      });
    });
  }

  /**
   * Everything you can do to one payroll document.
   *
   * Was a closure inside the payroll run's delegated listener, which meant the per-person
   * dialog either duplicated it or offered fewer buttons. Neither is acceptable for a
   * screen that moves real money, so it moved here: one implementation, called from the
   * run table's delegation and from the dialog's own buttons.
   *
   * `after` is what to re-render when something changed — the caller knows that; this
   * does not. (Anthony, 2026-09-10)
   */
  async function runDocAction(act, b, after) {
    const id = b.getAttribute('data-doc');
    try {
      if (act === 'pdf' || act === 'print') {
        const office = await payslipViewBlob(id);
        const slip = office || await payslipBlob(id);
        if (window.KT && KT.openFile) {
          const h = KT.openFile(slip, { title: 'Payslip', filename: 'payslip-' + id + '.pdf', label: 'Payslip', protectedDownload: office ? () => payslipSave(id) : undefined });
          // Print = the viewer's Print, once the pages are drawn (2026-11-06; it was a click at 700ms, before pdf.js had drawn).
          if (act === 'print') { Promise.resolve(h).then((v) => { if (v && v.print) v.print(); }); }
          return;
        }
        const url = URL.createObjectURL(slip);
        const w = window.open(url, '_blank');
        if (!w) { URL.revokeObjectURL(url); return say('Allow pop-ups for this site to open the payslip.'); }
        if (act === 'print') { w.addEventListener('load', () => { try { w.print(); } catch (e) {} }); }
        setTimeout(() => URL.revokeObjectURL(url), 60000);
        return;
      }

      if (act === 'save') { await payslipSave(id); return; }

      if (act === 'email') {
        /* RESEND PAYSLIP (2026-10-31, Anthony: "resend the payslip from payroll / pay period using the kebab").
           It goes to the address on the person's own account, shown and not editable. Only when there is no
           account address (a synced payslip with nobody behind it) is one typed - never assumed. Agency admins
           may also send a copy to one more address; the server refuses that for anyone else. */
        const email = (b.getAttribute('data-email') || '').trim();
        const who = (b.getAttribute('data-who') || '').trim();
        const last = (b.getAttribute('data-last') || '').trim();
        const fields = [];
        if (!email) { fields.push({ key: 'to', label: 'Email address', type: 'email', value: '' }); }
        if (isPayrollAdmin()) { fields.push({ key: 'also', label: 'Also send a copy to (optional)', type: 'email', placeholder: 'e.g. office@yourcentre.ca' }); }
        fields.push({ key: 'message', label: 'Note (optional)', type: 'textarea', rows: 3, placeholder: 'Shown above the payslip in the email' });
        const res = await window.KT.prompt({
          title: '✉️ Resend payslip',
          description: email
            ? 'Sends ' + (who ? who + '\'s' : 'this') + ' payslip to ' + email + ' with the PDF attached.' + (last ? ' Last sent ' + last + '.' : '')
            : 'There is no email on this person\'s account. Type the address to send it to.',
          fields: fields,
          okLabel: 'Send payslip',
        });
        if (res === null || res === undefined) { return; }
        const vals = typeof res === 'string' ? { [fields[0].key]: res } : res;
        const to = email || String(vals.to || '').trim();
        if (!to) { return say('Add an email address first.'); }
        const body = { to: to };
        if (vals.message && String(vals.message).trim()) { body.message = String(vals.message).trim(); }
        if (vals.also && String(vals.also).trim()) { body.also = String(vals.also).trim(); }
        b.disabled = true;
        try {
          /* NOTE the /provider/ prefix. The admin payroll-document routes are
             namespaced there; only the PDF route is not. */
          const r = await Api.post(`/provider/payroll-documents/${id}/email`, body);
          let msg = r.sent
            ? 'Payslip sent to ' + to + (r.attachment ? '' : ' (without the PDF — it could not be built)')
            : 'Not sent to ' + to + ': ' + (r.reason || 'the mail layer held it back.');
          if (body.also) { msg += r.also_sent ? ' · copy sent to ' + body.also : ' · the copy to ' + body.also + ' was not sent'; }
          say(msg);
          if (after && (r.sent || r.also_sent)) { after(); }
        } finally {
          b.disabled = false;
        }
        return;
      }

      if (act === 'zum') {
        /* Real money leaving the account, so it is confirmed and the amount is named in
           the question rather than assumed from context. */
        const net = Number(b.getAttribute('data-net') || 0);
        const who = b.getAttribute('data-who') || '';
        if (!(await window.KT.confirm({
          title: `Send $${net.toFixed(2)} by Interac${who ? ' to ' + who : ''}?`,
          description: 'This moves real money: it leaves the agency account straight away.',
          okLabel: `Send $${net.toFixed(2)}`,
        }))) { return; }
        const was = b.textContent;
        b.disabled = true; b.textContent = 'Sending…';
        try {
          const r = await Api.post('/director/zum/send', {
            user_id: Number(b.getAttribute('data-user')),
            amount: net,
            payroll_document_id: Number(id),
            comment: 'Payroll payment',
          });
          say(r.message || 'Interac payment sent.');
          if (after) { after(); }
        } catch (e) {
          b.disabled = false; b.textContent = was;
          say(e.message || 'That could not be sent.');
        }
        return;
      }

      if (act === 'status') {
        const to = b.getAttribute('data-to');
        b.disabled = true;
        await Api.post(`/provider/payroll-documents/${id}/status`, { status: to });
        say(to === 'paid' ? 'Marked as paid.' : 'Marked unpaid.');
        if (after) { after(); }
        return;
      }
    } catch (e) {
      say((e && e.message) || 'That did not work.');
    }
  }

  var SLIP_BTN = 'background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:6px 12px;'
    + 'font-size:12.5px;font-weight:700;cursor:pointer;color:#334155;font-family:inherit;';
  var SLIP_BTN_P = 'background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:6px 12px;'
    + 'font-size:12.5px;font-weight:700;cursor:pointer;color:#1E40AF;font-family:inherit;';
  /* A DATE-ONLY VALUE IS A CALENDAR DAY, NOT AN INSTANT (2026-11-27). time_off_requests.start_at / end_at are DATE
     columns, so the API sends "2026-12-18"; new Date("2026-12-18") reads that as UTC midnight, which is the evening of
     Dec 17 anywhere west of UTC, and the list showed every request a day early (the approve popup, worded on the
     server, was right). A bare YYYY-MM-DD is now built as that local calendar day; timestamps are unchanged. */
  function fmtDate(s) {
    if (!s) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s));
    const d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(s);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }
  /* SMS / call timestamps, in the AGENCY timezone (kt-tz.js). "Today, 3:37 p.m." or
     "26 Sep, 3:37 p.m."; the tooltip adds the year and seconds for the record. */
  function smsStamp(ts) {
    if (!ts) return '';
    if (window.KT && KT.fmtDateTime) return KT.fmtDateTime(ts);
    return fmtDate(ts);
  }
  function smsStampFull(ts) {
    try {
      const d = (window.KT && KT.parseTs) ? KT.parseTs(ts) : new Date(ts);
      if (!d || isNaN(d)) return '';
      return new Intl.DateTimeFormat(undefined, { timeZone: (window.KT && KT.tz) ? KT.tz() : undefined,
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short' }).format(d);
    } catch (e) { return ''; }
  }
  // Approving time off is for directors & agency admins ONLY. Honour "View as":
  // when a super-admin previews a lower role (educator / home_visitor / guardian),
  // they must NOT see the approve/deny queue — only the effective role counts.
  // Agency admins (and platform admins) only - "View as" honoured, like isStaffOrAdmin below.
  function isPayrollAdmin() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va) return va === 'agency_admin';
    var u = {}; try { u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) {}
    return Array.isArray(u.roles) && u.roles.some(function (r) { return r === 'agency_admin' || r === 'platform_admin'; });
  }
  function ppSentLabel(ts) {
    try { if (window.KT && KT.fmtDateTime) return KT.fmtDateTime(ts); } catch (e) {}
    return fmtDate(ts);
  }
  /* RESEND ALL payslips of one pay period: each person gets their own, at their own account address. Confirmed with
     the count and the names first; people with no address on file are skipped and counted. `rows` when the detail
     is already loaded, else it is fetched. */
  async function resendPeriodSlips(p, rows, btn, after) {
    if (!p) return;
    try {
      if (!rows) {
        const d = await Api.get('/admin/payroll/periods/detail?start=' + encodeURIComponent(p.period_start) + '&end=' + encodeURIComponent(p.period_end)
          + '&group=' + encodeURIComponent(p.staff_group || '') + '&frequency=' + encodeURIComponent(p.pay_frequency || ''));
        rows = (d && d.data) || [];
      }
    } catch (e) { return say((e && e.message) || 'Could not load this pay period.'); }
    const withEmail = rows.filter(function (r) { return r.email; });
    const skipped = rows.length - withEmail.length;
    if (!withEmail.length) { return say('Nobody in this pay period has an email address on file.'); }
    const names = withEmail.slice(0, 6).map(function (r) { return r.name + ' (' + r.email + ')'; }).join(', ')
      + (withEmail.length > 6 ? ' and ' + (withEmail.length - 6) + ' more' : '');
    const n = withEmail.length;
    const ok = await window.KT.confirm({
      title: '✉️ Resend ' + n + ' payslip' + (n === 1 ? '' : 's') + '?',
      description: 'Pay period ' + ppPeriod(p) + '. Each person gets their own payslip, PDF attached: ' + names + '.'
        + (skipped ? ' ' + skipped + ' with no email on file will be skipped.' : ''),
      okLabel: 'Resend ' + n,
    });
    if (!ok) return;
    if (btn) { btn.disabled = true; }
    let sent = 0, held = 0;
    for (const r of withEmail) {
      try { const x = await Api.post('/provider/payroll-documents/' + r.id + '/email', { to: r.email }); if (x && x.sent) { sent++; } else { held++; } }
      catch (e) { held++; }
    }
    if (btn) { btn.disabled = false; }
    say(sent + ' payslip' + (sent === 1 ? '' : 's') + ' sent' + (held ? ' · ' + held + ' not sent' : '') + (skipped ? ' · ' + skipped + ' had no email' : ''));
    if (after) { after(); }
  }
  function isStaffOrAdmin() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va) return ['agency_admin', 'centre_director'].indexOf(va) !== -1;
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    return Array.isArray(u.roles) && u.roles.some(r => ['agency_admin', 'centre_director', 'platform_admin'].includes(r));
  }
  async function downloadAuthed(path, filename) {
    const tok = sessionStorage.getItem('kt_token');
    const r = await fetch(((window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1') + path, { headers: { Authorization: 'Bearer ' + tok } });
    const blob = await r.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename;
    a.click();
  }

  // Expose for shim
  window.KT = window.KT || {};
  window.KT.V22p51 = {
    renderTimeOff,
    renderBackgroundChecks,
    renderPayroll,
    renderAgencyBilling,
    renderAutopay,
    renderSms,
    renderAiChurn,
    renderAiDocs,
    renderLocale,
  };
})(window);
