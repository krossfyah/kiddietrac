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
    const [mine, all] = await Promise.all([
      Api.get('/time-off/mine').catch(() => ({ data: [] })),
      isStaffOrAdmin() ? Api.get('/admin/time-off').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]);
    const allRows = all.data || [];
    const pending = { data: allRows.filter(r => (r.status || 'pending').toLowerCase() === 'pending') };
    const decided = { data: allRows.filter(r => (r.status || 'pending').toLowerCase() !== 'pending')
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
        <h3 style="margin-top:32px;font-size:16px;color:#374151;">Your requests</h3>
        <div id="tor-mine"></div>
        ${isStaffOrAdmin() ? '<h3 style="margin-top:32px;font-size:16px;color:#374151;">Team requests</h3>'
          + '<div id="tor-tabs" style="display:flex;gap:6px;margin:10px 0 4px;flex-wrap:wrap;"></div>'
          + '<div id="tor-team"></div>' : ''}
      </div>`).firstElementChild);

    renderTorList(document.getElementById('tor-mine'), mine.data || [], false);
    if (isStaffOrAdmin()) {
      renderTorTabs(pending.data || [], decided.data || []);
    }
    document.getElementById('tor-new').onclick = () => openTorModal();
  }
  function isMobile() { return window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native'); }
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
    var msg = wasApproved
      ? 'Withdraw this approved time off?\n\nThe calendar goes back to normal and everyone who'
        + ' was told will be notified that you will be working as usual.'
      : 'Withdraw this request?';
    if (!window.confirm(msg)) return;
    try {
      await Api.post('/time-off/' + r.id + '/cancel', {});
      renderTimeOff(document.getElementById('appMain') || document.querySelector('main'));
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
    await Api.patch('/admin/time-off/' + id, { status: status });
    renderTimeOff(document.getElementById('appMain') || document.querySelector('main'));
  }
  function torCard(r, isApprover) {
    var status = (r.status || 'pending').toLowerCase();
    var sc = torStatusColors(status);
    var meta = torMeta(r.request_type);
    var card = el('div', { style: 'background:#fff;border:1px solid #EDF1F6;border-radius:15px;padding:14px;margin-bottom:11px;box-shadow:0 2px 8px -3px rgba(15,23,42,.12);' });
    var head = el('div', { style: 'display:flex;align-items:center;gap:11px;' });
    head.appendChild(el('span', { style: 'flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:20px;' }, meta.icon));
    var mid = el('div', { style: 'flex:1;min-width:0;' });
    mid.appendChild(el('div', { style: 'font-weight:800;font-size:15px;color:#0F172A;' }, meta.label));
    if (isApprover) mid.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, r.user_name || 'Team member'));
    head.appendChild(mid);
    head.appendChild(el('span', { style: 'flex:0 0 auto;font-size:11.5px;font-weight:800;text-transform:capitalize;padding:4px 11px;border-radius:20px;background:' + sc.bg + ';color:' + sc.fg + ';' }, status));
    card.appendChild(head);
    card.appendChild(el('div', { style: 'font-size:13.5px;font-weight:600;color:#334155;margin-top:11px;padding-top:11px;border-top:1px solid #F1F5F9;' }, '📅 ' + torWhen(r)));
    if (r.reason) card.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.45;' }, r.reason));
    // Mobile renders cards, not the table, so the decision has to be added here too.
    var decidedLine = decidedText(r);
    if (decidedLine) {
      card.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:8px;padding-top:8px;border-top:1px solid #F1F5F9;' }, decidedLine));
    }
    if (isApprover && status === 'pending') {
      var actions = el('div', { style: 'display:flex;gap:8px;margin-top:12px;' });
      var appr = el('button', { type: 'button', style: 'flex:1;background:#16A34A;color:#fff;border:0;padding:11px;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;' }, 'Approve');
      var deny = el('button', { type: 'button', style: 'flex:1;background:#fff;color:#B91C1C;border:1.5px solid #FCA5A5;padding:11px;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;' }, 'Deny');
      appr.onclick = function () { actOnTor(r.id, 'approved'); };
      deny.onclick = function () { actOnTor(r.id, 'denied'); };
      actions.appendChild(appr); actions.appendChild(deny);
      card.appendChild(actions);
    }
    // Your own request — you can take it back, approved or not.
    if (!isApprover && canWithdraw(r)) {
      var wd = el('button', { type: 'button', class: 'kt-btn kt-btn-secondary',
        style: 'width:100%;margin-top:12px;justify-content:center;' }, 'Withdraw');
      wd.onclick = function () { withdrawTor(r); };
      card.appendChild(wd);
    }
    return card;
  }
  function renderTorList(host, rows, isApprover) {
    if (!rows.length) { host.innerHTML = '<div style="color:#64748B;padding:16px;background:#fff;border:1px solid #EDF1F6;border-radius:14px;text-align:center;font-size:13.5px;">No requests yet.</div>'; return; }
    host.innerHTML = '';
    if (isMobile()) { rows.forEach(function (r) { host.appendChild(torCard(r, isApprover)); }); return; }
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
      const tr = document.createElement('tr');
      const status = (r.status || 'pending').toLowerCase();
      const color = status === 'approved' ? '#047857' : status === 'denied' ? '#B91C1C' : '#D97706';
      tr.innerHTML = `<td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name || 'You')}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-transform:capitalize;">${escapeHtml(r.request_type)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(torWhen(r))}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:12.5px;color:#475569;">${decidedCell(r)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="color:${color};font-weight:600;text-transform:capitalize;">${status}</span></td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${isApprover && status === 'pending'
          ? `<button data-act="approved" data-id="${r.id}" class="kt-btn kt-btn-success kt-btn-sm" style="margin-right:6px;">Approve</button><button data-act="denied" data-id="${r.id}" class="kt-btn kt-btn-danger kt-btn-sm">Deny</button>`
          : (!isApprover && canWithdraw(r)
            ? `<button data-withdraw="${r.id}" class="kt-btn kt-btn-secondary kt-btn-sm">Withdraw</button>` : '')}</td>`;
      tb.appendChild(tr);
    });
    card.appendChild(tbl);
    host.appendChild(card);
    host.querySelectorAll('button[data-withdraw]').forEach(b => {
      b.onclick = function () {
        var row = rows.filter(function (x) { return String(x.id) === b.dataset.withdraw; })[0];
        if (row) withdrawTor(row);
      };
    });
    host.querySelectorAll('button[data-act]').forEach(b => {
      b.onclick = async () => {
        await Api.patch(`/admin/time-off/${b.dataset.id}`, { status: b.dataset.act });
        renderTimeOff(document.getElementById('main') || document.querySelector('main'));
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
    startEl.addEventListener('change', function () {
      if (!allDayEl.checked) { endEl.value = startEl.value; }
    });
    syncAllDay();

    m.querySelector('#tor-cancel').onclick = () => m.remove();
    m.querySelector('#tor-submit').onclick = async () => {
      var allDay = allDayEl.checked;
      const payload = {
        request_type: m.querySelector('#tor-type').value,
        start_at: startEl.value,
        end_at: allDay ? endEl.value : startEl.value,
        reason: m.querySelector('#tor-reason').value,
        all_day: allDay,
      };
      if (!allDay) {
        payload.start_time = m.querySelector('#tor-tstart').value;
        payload.end_time = m.querySelector('#tor-tend').value;
        if (!payload.start_time || !payload.end_time) { errEl.textContent = 'Give a start and end time.'; return; }
        if (payload.end_time <= payload.start_time) { errEl.textContent = 'The end time has to be after the start.'; return; }
      }
      if (!payload.start_at || !payload.end_at) { errEl.textContent = 'Pick the dates.'; return; }
      errEl.textContent = '';
      await Api.post('/time-off', payload);
      m.remove();
      renderTimeOff(document.getElementById('main') || document.querySelector('main'));
    };
  }

  // ============================ Background checks ============================
  async function renderBackgroundChecks(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/admin/background-checks').catch(() => ({ data: [] }));
    main.innerHTML = '';
    const root = html(`
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 style="margin:0;color:#1F6080;">Background checks</h2>
          <div>
            <button id="bgc-csv" style="background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-right:8px;cursor:pointer;">⤓ CSV</button>
            <button id="bgc-add" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;">+ Record</button>
          </div>
        </div>
        <div id="bgc-list" style="margin-top:18px;"></div>
      </div>`).firstElementChild;
    main.appendChild(root);
    renderBgcList(root.querySelector('#bgc-list'), res.data || []);
    root.querySelector('#bgc-csv').onclick = () => downloadAuthed('/admin/background-checks?format=csv', 'background-checks.csv');
    root.querySelector('#bgc-add').onclick = () => openBgcModal(null);
  }
  function renderBgcList(host, rows) {
    if (!rows.length) { host.innerHTML = '<div style="color:#64748B;padding:12px;">No records.</div>'; return; }
    host.innerHTML = '';
    const tbl = document.createElement('table');
    tbl.style.cssText = 'width:100%;border-collapse:collapse;';
    tbl.innerHTML = '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Staff</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Type</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Expires</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th><th></th></tr></thead><tbody></tbody>';
    const tb = tbl.querySelector('tbody');
    rows.forEach(r => {
      const color = r.status_bucket === 'expired' ? '#B91C1C' : r.status_bucket === 'expiring' ? '#D97706' : '#047857';
      const tr = document.createElement('tr');
      tr.innerHTML = `<td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-transform:uppercase;">${escapeHtml(r.check_type)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(r.expires_at)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="color:${color};font-weight:600;">${r.status_bucket}</span></td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;"><button data-id="${r.id}" data-view="1" style="background:#EFF6FF;color:#1D4ED8;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;margin-right:6px;">View</button><button data-id="${r.id}" data-edit="1" style="background:#F3F4F6;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;margin-right:6px;">Edit</button><button data-id="${r.id}" data-del="1" class="kt-icon-tip" title="Delete" aria-label="Delete" data-kttip="Delete" style="background:#FEE2E2;color:#B91C1C;border:0;padding:6px 11px;border-radius:4px;cursor:pointer;">🗑️</button></td>`;
      tb.appendChild(tr);
    });
    host.appendChild(tbl);
    host.querySelectorAll('button[data-view]').forEach(b => b.onclick = () => openBgcDetail(rows.find(r => r.id == b.dataset.id)));
    host.querySelectorAll('button[data-edit]').forEach(b => b.onclick = () => openBgcModal(rows.find(r => r.id == b.dataset.id)));
    host.querySelectorAll('button[data-del]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Delete this background check?')) return;
      await Api.delete(`/admin/background-checks/${b.dataset.id}`);
      renderBackgroundChecks(document.getElementById('main') || document.querySelector('main'));
    });
  }

  // Read-only detail + attach-a-file for a background-check record (dimmed modal).
  function openBgcDetail(row) {
    if (!row) return;
    const host = ((window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
    const abs = (u) => (u ? (/^https?:\/\//.test(u) ? u : host + u) : '');
    const docLink = (u) => '<a href="' + abs(u) + '" target="_blank" rel="noopener" style="color:#1D4ED8;font-weight:600;">📄 Open current document</a>';
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:520px;width:100%;padding:24px;box-shadow:0 20px 50px -12px rgba(15,23,42,.4);">
      <h3 style="margin:0 0 12px;color:#0F172A;">🛡️ Background check</h3>
      <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
        <tr><td style="padding:5px 12px 5px 0;color:#64748B;">Staff</td><td style="padding:5px 0;font-weight:700;">${escapeHtml(row.user_name || '')}</td></tr>
        <tr><td style="padding:5px 12px 5px 0;color:#64748B;">Type</td><td style="padding:5px 0;text-transform:uppercase;">${escapeHtml(row.check_type || '')}</td></tr>
        <tr><td style="padding:5px 12px 5px 0;color:#64748B;">Reference</td><td style="padding:5px 0;">${escapeHtml(row.reference || '—')}</td></tr>
        <tr><td style="padding:5px 12px 5px 0;color:#64748B;">Issued</td><td style="padding:5px 0;">${row.issued_at ? fmtDate(row.issued_at) : '—'}</td></tr>
        <tr><td style="padding:5px 12px 5px 0;color:#64748B;">Expires</td><td style="padding:5px 0;">${fmtDate(row.expires_at)}</td></tr>
        <tr><td style="padding:5px 12px 5px 0;color:#64748B;vertical-align:top;">Notes</td><td style="padding:5px 0;white-space:pre-wrap;">${escapeHtml(row.notes || '—')}</td></tr>
      </table>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid #F1F5F9;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#94A3B8;margin-bottom:8px;">Attached document</div>
        <div id="bgc-doc-cur">${row.document_url ? docLink(row.document_url) : '<span style="color:#64748B;">No document attached yet.</span>'}</div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
          <input id="bgc-file" type="file" accept=".pdf,image/*,.doc,.docx" style="font-size:13px;">
          <button id="bgc-upload" style="background:#1F6080;color:#fff;border:0;padding:8px 14px;border-radius:7px;cursor:pointer;font-weight:600;">Upload</button>
        </div>
        <div id="bgc-up-status" style="font-size:12.5px;min-height:16px;margin-top:6px;"></div>
      </div>
      <div style="text-align:right;margin-top:16px;"><button id="bgc-close" style="background:#F1F5F9;color:#475569;border:0;border-radius:9px;padding:8px 16px;font-weight:700;cursor:pointer;">Close</button></div>
    </div>`;
    const close = () => m.remove();
    m.addEventListener('click', e => { if (e.target === m) close(); });
    document.body.appendChild(m);
    m.querySelector('#bgc-close').onclick = close;
    m.querySelector('#bgc-upload').onclick = async () => {
      const fileEl = m.querySelector('#bgc-file');
      const st = m.querySelector('#bgc-up-status');
      if (!fileEl.files || !fileEl.files[0]) { st.style.color = '#B91C1C'; st.textContent = 'Choose a file first.'; return; }
      const btn = m.querySelector('#bgc-upload');
      const done = (window.KT && KT.busy) ? KT.busy(btn) : function () {};
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('file', fileEl.files[0]);
        const res = await Api.postForm('/admin/background-checks/' + row.id + '/document', fd);
        row.document_url = res.document_url;
        m.querySelector('#bgc-doc-cur').innerHTML = docLink(res.document_url);
        st.style.color = '#047857'; st.textContent = '✓ Uploaded.';
      } catch (e) {
        st.style.color = '#B91C1C'; st.textContent = '✗ ' + (e.message || 'Upload failed');
        btn.disabled = false;
      } finally { done(); }
    };
  }
  async function openBgcModal(row) {
    const staffRes = await Api.get('/admin/users?role=educator,centre_director,agency_admin').catch(() => ({ data: [] }));
    const staff = staffRes.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:480px;width:90%;">
      <h3 style="margin:0 0 12px;">${row ? 'Edit' : 'Add'} background check</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Staff
        <select id="bgc-user" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">
          ${staff.map(s => `<option value="${s.id}" ${row && row.user_id == s.id ? 'selected' : ''}>${escapeHtml(s.name)}</option>`).join('')}
        </select></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Type
        <select id="bgc-type" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">
          ${['vss', 'criminal', 'driver', 'reference', 'other'].map(t => `<option value="${t}" ${row && row.check_type === t ? 'selected' : ''}>${t.toUpperCase()}</option>`).join('')}
        </select></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Reference / cert #
        <input id="bgc-ref" value="${row ? escapeHtml(row.reference || '') : ''}" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Issued
        <input id="bgc-issued" type="date" value="${row && row.issued_at ? row.issued_at.substring(0, 10) : ''}" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Expires
        <input id="bgc-expires" type="date" value="${row ? row.expires_at.substring(0, 10) : ''}" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <div style="margin-top:18px;text-align:right;">
        <button id="bgc-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="bgc-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#bgc-cancel').onclick = () => m.remove();
    m.querySelector('#bgc-save').onclick = async () => {
      const payload = {
        id: row ? row.id : null,
        user_id: parseInt(m.querySelector('#bgc-user').value, 10),
        check_type: m.querySelector('#bgc-type').value,
        reference: m.querySelector('#bgc-ref').value,
        issued_at: m.querySelector('#bgc-issued').value || null,
        expires_at: m.querySelector('#bgc-expires').value,
      };
      await Api.post('/admin/background-checks', payload);
      m.remove();
      renderBackgroundChecks(document.getElementById('main') || document.querySelector('main'));
    };
  }

  // ============================ Payroll ============================
  async function renderPayroll(main) {
    main.innerHTML = `
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <h2 style="margin:0 0 12px;color:#1F6080;">Payroll</h2>
        <!-- The "Documents issued" tab was here. Hours and documents are one
             question, so the documents now sit on the rows they belong to. -->
        <div style="display:flex;gap:12px;margin-bottom:18px;align-items:end;">
          <label style="font-size:13px;color:#374151;">From <input id="pr-from" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <label style="font-size:13px;color:#374151;">To <input id="pr-to" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <button id="pr-run" style="background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">Run</button>
          <button id="pr-csv" style="background:#059669;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">⤓ CSV</button>
          <button id="pr-manual" style="background:#7C3AED;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">＋ Generate payroll</button>
        </div>
        <div id="pr-result"></div>
        <div id="pr-docs" hidden></div>
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
          const r = await fetch(`${(KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'}/payroll-documents/${b.getAttribute('data-pd-view')}/pdf`,
            { headers: { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token')), Accept: 'application/pdf' } });
          if (!r.ok) throw new Error('HTTP ' + r.status);
          const url = URL.createObjectURL(await r.blob());
          window.open(url, '_blank');
          setTimeout(() => URL.revokeObjectURL(url), 60000);
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
        sub += parseFloat(r.total_hours || 0);
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
          <span style="font-size:12.5px;color:#64748B;">${rows.length} ${rows.length === 1 ? 'person' : 'people'} · <strong style="color:#0F172A;">${sub.toFixed(2)} h</strong></span>
        </div>
        <table style="width:100%;border-collapse:collapse;"><thead><tr>
          <th style="${th('left')}">Staff</th><th style="${th('left')}">Role</th><th style="${th('left')}">Centre</th>
          <th style="${th('right')}">Punches</th><th style="${th('right')}">Hours</th><th style="${th('right')}">Payslips</th>
        </tr></thead><tbody>${body}
        <tr><td colspan="4" style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">${escapeHtml(g.label.replace(/^\S+\s/, ''))} subtotal</td>
        <td style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">${sub.toFixed(2)}</td>
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
      const allHours = (res.data || []).reduce((a, r) => a + parseFloat(r.total_hours || 0), 0);
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
        tot += parseFloat(r.total_hours || 0);
        return `<tr><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name)}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.centre_name || '')}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${r.punch_count}</td><td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:600;">${r.total_hours}</td></tr>`;
      }).join('')}<tr><td colspan="3" style="padding:10px 8px;text-align:right;font-weight:700;border-top:2px solid #1F6080;">Total</td><td style="padding:10px 8px;text-align:right;font-weight:700;border-top:2px solid #1F6080;">${tot.toFixed(2)}</td></tr></tbody></table>`;
  }

  // ============================ Agency billing config ============================
  async function renderAgencyBilling(main) {
    main.innerHTML = '<div style="padding:20px;">Loading…</div>';
    const res = await Api.get('/admin/billing-config');
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
    main.innerHTML = `<div style="padding:24px;max-width:520px;margin:0 auto;">
      <h2 style="margin:0 0 16px;color:#1F6080;">Auto-pay</h2>
      <p style="color:#6B7280;font-size:14px;">Save a card and we'll automatically charge each invoice when it's due. Cancel any time.</p>
      <div id="ap-state" style="background:#F9FAFB;padding:18px;border-radius:8px;margin-top:16px;">
        ${res.has_card ? `<div><strong>Saved card:</strong> •••• ${res.card_last4 || '****'}</div>
          <div style="margin-top:8px;"><strong>Auto-pay:</strong> ${res.autopay_enabled ? '<span style="color:#047857;font-weight:600;">on</span>' : '<span style="color:#6B7280;">off</span>'}</div>
          <button id="ap-toggle" style="background:${res.autopay_enabled ? '#EF4444' : '#10B981'};color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-top:14px;cursor:pointer;">${res.autopay_enabled ? 'Turn off auto-pay' : 'Turn on auto-pay'}</button>
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
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:440px;width:90%;">
      <h3 style="margin:0 0 12px;">Save a card</h3>
      <div id="ap-element" style="padding:12px;border:1px solid #E5E7EB;border-radius:4px;"></div>
      <div id="ap-err" style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>
      <div style="margin-top:16px;text-align:right;">
        <button id="ap-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="ap-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Save card</button>
      </div></div>`;
    document.body.appendChild(m);
    const elements = stripe.elements();
    const card = elements.create('card');
    card.mount('#ap-element');
    m.querySelector('#ap-cancel').onclick = () => m.remove();
    m.querySelector('#ap-save').onclick = async () => {
      const { setupIntent, error } = await stripe.confirmCardSetup(intent.client_secret, { payment_method: { card } });
      if (error) { m.querySelector('#ap-err').textContent = error.message; return; }
      await Api.post('/parent/billing/save-card', { payment_method: setupIntent.payment_method });
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
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div id="sms-tabs" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;"></div>

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
          : '';
        return `<button data-sms-tab="${id}" class="kt-tab${on ? ' active' : ''}" style="padding:8px 16px;`
          + `border:1px solid ${on ? '#1F6080' : '#D1D5DB'};border-radius:8px;`
          + `background:${on ? '#1F6080' : 'white'};color:${on ? 'white' : '#374151'};`
          + `font-weight:600;cursor:pointer;">${label}${badge}</button>`;
      }).join('');
      bar.querySelectorAll('[data-sms-tab]').forEach(b => {
        b.addEventListener('click', () => showSmsTab(b.getAttribute('data-sms-tab')));
      });
    }

    function showSmsTab(id) {
      smsTab = id;
      SMS_TABS.forEach(([t]) => {
        const pane = document.getElementById('sms-pane-' + t);
        if (pane) pane.hidden = (t !== id);
      });
      paintSmsTabs();
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
      catRow.style.display = voice ? '' : 'none';
      catSel.style.display = voice ? '' : 'none';
      bodyLabel.textContent = voice ? 'Announcement' : 'Message';
      sendBtn.textContent = voice ? 'Place calls' : 'Send broadcast';
      recentH.textContent = voice ? 'Recent calls' : 'Sent & received';
      body.maxLength = LIMIT[channel];
      note.textContent = voice
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
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;white-space:nowrap;">${fmtDate(m.created_at)}</td>
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
      <p style="color:#6B7280;font-size:14px;">Upload a photo or scan of a document and we'll extract structured fields into JSON. Useful for new staff certifications, immunization records, etc.</p>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Upload a file (image)
        <input id="aid-file" type="file" accept="image/png,image/jpeg,image/webp" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;background:#fff;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">…or a document URL (publicly reachable)
        <input id="aid-url" placeholder="https://…/document.png" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;"></label>
      <label style="display:block;margin-top:14px;font-size:13px;font-weight:600;">Document type
        <select id="aid-type" style="width:100%;padding:9px;border:1px solid #E5E7EB;border-radius:4px;margin-top:4px;">
          <option value="immunization">Immunization record</option>
          <option value="certification">Staff certification</option>
          <option value="background_check">Background / VSS check</option>
          <option value="id">ID document</option>
          <option value="enrollment">Enrollment form</option>
        </select></label>
      <button id="aid-go" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;margin-top:18px;cursor:pointer;">Extract fields</button>
      <pre id="aid-out" style="background:#1F2937;color:#10B981;padding:14px;border-radius:6px;margin-top:18px;font-size:12px;overflow:auto;max-height:400px;"></pre></div>`;
    document.getElementById('aid-go').onclick = async () => {
      const out = document.getElementById('aid-out');
      out.textContent = 'Extracting…';
      try {
        const fileEl = document.getElementById('aid-file');
        const docType = document.getElementById('aid-type').value;
        let r;
        if (fileEl && fileEl.files && fileEl.files[0]) {
          const fd = new FormData();
          fd.append('file', fileEl.files[0]);
          fd.append('doc_type', docType);
          r = await Api.postForm('/ai/doc-extract', fd);
        } else {
          r = await Api.post('/ai/doc-extract', {
            document_url: document.getElementById('aid-url').value,
            doc_type: docType,
          });
        }
        out.textContent = JSON.stringify(r.fields, null, 2);
      } catch (e) { out.textContent = 'Error: ' + (e.message || e); }
    };
  }

  // ============================ Locale picker ============================
  async function renderLocale(main) {
    const res = await Api.get('/locale');
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
                +     escapeHtml(d.email || opts.email || '') + '" style="' + SLIP_BTN_P + '">✉️ Email</button>'
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
        const url = URL.createObjectURL(await payslipBlob(id));
        const w = window.open(url, '_blank');
        if (!w) { URL.revokeObjectURL(url); return say('Allow pop-ups for this site to open the payslip.'); }
        if (act === 'print') { w.addEventListener('load', () => { try { w.print(); } catch (e) {} }); }
        setTimeout(() => URL.revokeObjectURL(url), 60000);
        return;
      }

      if (act === 'save') {
        const url = URL.createObjectURL(await payslipBlob(id));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'payslip-' + id + '.pdf';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
        return;
      }

      if (act === 'email') {
        /* Prefilled, never assumed — the endpoint requires an address for the same
           reason: a default recipient means a lost field posts somebody's pay to an
           address nobody chose. */
        const to = window.prompt('Email this payslip to:', b.getAttribute('data-email') || '');
        if (!to) { return; }
        b.disabled = true;
        try {
          /* NOTE the /provider/ prefix. The admin payroll-document routes are
             namespaced there; only the PDF route is not. */
          const res = await Api.post(`/provider/payroll-documents/${id}/email`, { to });
          say(res.sent
            ? 'Payslip emailed to ' + to + (res.attachment ? '' : ' (without the PDF — it could not be built)')
            : 'Not sent: ' + (res.reason || 'the mail layer held it back.'));
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
        if (!window.confirm(`Send $${net.toFixed(2)} by Interac${who ? ' to ' + who : ''}?\n\nThis moves real money.`)) { return; }
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
  function fmtDate(s) { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); }
  // Approving time off is for directors & agency admins ONLY. Honour "View as":
  // when a super-admin previews a lower role (educator / home_visitor / guardian),
  // they must NOT see the approve/deny queue — only the effective role counts.
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
