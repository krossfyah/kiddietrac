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
    card.appendChild(el('div', { style: 'font-size:13.5px;font-weight:600;color:#334155;margin-top:11px;padding-top:11px;border-top:1px solid #F1F5F9;' }, '📅 ' + fmtDate(r.start_at) + ' – ' + fmtDate(r.end_at)));
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
    return card;
  }
  function renderTorList(host, rows, isApprover) {
    if (!rows.length) { host.innerHTML = '<div style="color:#64748B;padding:16px;background:#fff;border:1px solid #EDF1F6;border-radius:14px;text-align:center;font-size:13.5px;">No requests yet.</div>'; return; }
    host.innerHTML = '';
    if (isMobile()) { rows.forEach(function (r) { host.appendChild(torCard(r, isApprover)); }); return; }
    const tbl = document.createElement('table');
    tbl.style.cssText = 'width:100%;border-collapse:collapse;margin-top:8px;';
    tbl.innerHTML = '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Who</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Type</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Dates</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Decided by</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th><th></th></tr></thead><tbody></tbody>';
    const tb = tbl.querySelector('tbody');
    rows.forEach(r => {
      const tr = document.createElement('tr');
      const status = (r.status || 'pending').toLowerCase();
      const color = status === 'approved' ? '#047857' : status === 'denied' ? '#B91C1C' : '#D97706';
      tr.innerHTML = `<td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${escapeHtml(r.user_name || 'You')}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-transform:capitalize;">${escapeHtml(r.request_type)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(r.start_at)} – ${fmtDate(r.end_at)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:12.5px;color:#475569;">${decidedCell(r)}</td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="color:${color};font-weight:600;text-transform:capitalize;">${status}</span></td>
        <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${isApprover && status === 'pending' ? `<button data-act="approved" data-id="${r.id}" style="background:#10B981;color:#fff;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;margin-right:6px;">Approve</button><button data-act="denied" data-id="${r.id}" style="background:#EF4444;color:#fff;border:0;padding:6px 12px;border-radius:4px;cursor:pointer;">Deny</button>` : ''}</td>`;
      tb.appendChild(tr);
    });
    host.appendChild(tbl);
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
      <label style="display:block;margin-top:10px;font-size:13px;color:#374151;font-weight:600;">Reason
        <textarea id="tor-reason" rows="3" style="width:100%;padding:11px;border:1px solid #E5E7EB;border-radius:9px;margin-top:5px;font-size:16px;box-sizing:border-box;"></textarea></label>
      <div style="margin-top:20px;text-align:right;">
        <button id="tor-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="tor-submit" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Submit</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#tor-cancel').onclick = () => m.remove();
    m.querySelector('#tor-submit').onclick = async () => {
      const payload = {
        request_type: m.querySelector('#tor-type').value,
        start_at: m.querySelector('#tor-start').value,
        end_at: m.querySelector('#tor-end').value,
        reason: m.querySelector('#tor-reason').value,
      };
      if (!payload.start_at || !payload.end_at) { alert('Pick start and end dates'); return; }
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
        <div id="pr-tabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 16px;padding:0 0 2px;"></div>
        <div style="display:flex;gap:12px;margin-bottom:18px;align-items:end;">
          <label style="font-size:13px;color:#374151;">From <input id="pr-from" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <label style="font-size:13px;color:#374151;">To <input id="pr-to" type="date" style="display:block;margin-top:4px;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
          <button id="pr-run" style="background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">Run</button>
          <button id="pr-csv" style="background:#059669;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">⤓ CSV</button>
        </div>
        <div id="pr-result"></div>
        <div id="pr-docs" hidden></div>
      </div>`;

    // Hours worked and documents issued are two different questions about the same
    // payroll, and they were never both answerable: a payslip was computed on demand and
    // never written down, so "what did we issue this person" had no record to read.
    const PR_TABS = [{ key: 'hours', label: '⏱ Hours worked' }, { key: 'docs', label: '🧾 Documents issued' }];
    let prTab = 'hours';
    const paintPrTabs = () => {
      const bar = document.getElementById('pr-tabs');
      if (!bar) return;
      bar.innerHTML = PR_TABS.map(t => `<button type="button" data-pr-tab="${t.key}" style="background:none;border:0;border-bottom:2px solid ${prTab === t.key ? '#1F6080' : 'transparent'};padding:9px 13px;font-size:13.5px;font-weight:700;color:${prTab === t.key ? '#0F172A' : '#64748B'};cursor:pointer;border-radius:8px 8px 0 0;">${t.label}</button>`).join('');
      bar.querySelectorAll('[data-pr-tab]').forEach(b => {
        b.onclick = () => {
          // The screen can be torn down between the click and this handler running: an
          // idle sign-out redirects to the login page while this bar is still painted,
          // and every lookup below then returns null. Same guard runPayroll() carries.
          const result = document.getElementById('pr-result');
          const csv = document.getElementById('pr-csv');
          const docs = document.getElementById('pr-docs');
          if (!result || !csv || !docs) return;

          prTab = b.getAttribute('data-pr-tab');
          const hoursOn = prTab === 'hours';
          result.hidden = !hoursOn;
          csv.hidden = !hoursOn;
          docs.hidden = hoursOn;
          paintPrTabs();
          if (!hoursOn) renderPayrollDocs();
        };
      });
    };
    paintPrTabs();
    // v22p98: default to a trailing 30-day window (was first-of-month → today,
    // a single empty day on the 1st, hiding the whole prior pay period).
    const today = new Date();
    const startWin = new Date(); startWin.setDate(today.getDate() - 30);
    document.getElementById('pr-from').valueAsDate = startWin;
    document.getElementById('pr-to').valueAsDate = today;
    document.getElementById('pr-run').onclick = () => runPayroll();
    document.getElementById('pr-csv').onclick = () => {
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
    let res;
    try {
      res = await Api.get(`/provider/payroll-documents${to ? '?to=' + to : ''}`);
    } catch (e) {
      host.innerHTML = `<div style="color:#B91C1C;padding:12px;">Could not load payroll documents: ${escapeHtml((e && e.message) || 'error')}</div>`;
      return;
    }
    const rows = (res && res.data) || [];
    if (!rows.length) {
      host.innerHTML = '<div style="color:#64748B;padding:12px;">No payroll documents issued yet. They appear here as payslips and payroll invoices are raised.</div>';
      return;
    }

    const money = (n) => '$' + (Number(n) || 0).toFixed(2);
    const dt = (d) => { try { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { return d || ''; } };
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
          <td style="${td('left')}">${escapeHtml(r.payee_name || '')}<div style="font-size:11.5px;color:#94A3B8;">${escapeHtml(r.role_label || '')}</div></td>
          <td style="${td('left')}">${r.kind === 'invoice' ? 'Invoice' : 'Payslip'}</td>
          <td style="${td('left')}">${escapeHtml(r.reference || '')}</td>
          <td style="${td('left')}">${escapeHtml(dt(r.period_start))}</td>
          <td style="${td('right')}">${Number(r.units) || 0} <span style="color:#94A3B8;font-size:11.5px;">${escapeHtml(r.unit_label || '')}</span></td>
          <td style="${td('right', 'font-weight:600;')}">${money(r.gross)}</td>
          <td style="${td('left')}">${chip(r.status || 'issued')}</td>
          <td style="${td('left')}"><button data-pd-view="${r.id}" style="background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:5px 10px;font-size:12.5px;cursor:pointer;">View</button>
            ${r.status !== 'paid' ? `<button data-pd-paid="${r.id}" style="background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:5px 10px;font-size:12.5px;cursor:pointer;">Mark paid</button>` : ''}</td>
        </tr>`).join('')}</tbody></table></div></div>`;
    }).join('');

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

    const section = (g) => {
      const rows = byGroup[g.key] || [];
      if (!rows.length) return '';
      let sub = 0;
      const body = rows.map(r => {
        sub += parseFloat(r.total_hours || 0);
        return `<tr><td style="${td('left')}">${escapeHtml(r.user_name)}</td>`
          + `<td style="${td('left')}"><span style="font-size:11.5px;font-weight:700;color:#475569;background:#F1F5F9;border-radius:999px;padding:2px 9px;">${escapeHtml(r.role || 'Staff')}</span></td>`
          + `<td style="${td('left')}">${escapeHtml(r.centre_name || '')}</td>`
          + `<td style="${td('right')}">${r.punch_count}</td>`
          + `<td style="${td('right', 'font-weight:600;')}">${r.total_hours}</td></tr>`;
      }).join('');
      grand += sub;
      return `<div style="margin-bottom:22px;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin:0 0 6px;">
          <h3 style="margin:0;font-size:15px;color:#1F6080;">${g.label}</h3>
          <span style="font-size:12.5px;color:#64748B;">${rows.length} ${rows.length === 1 ? 'person' : 'people'} · <strong style="color:#0F172A;">${sub.toFixed(2)} h</strong></span>
        </div>
        <table style="width:100%;border-collapse:collapse;"><thead><tr>
          <th style="${th('left')}">Staff</th><th style="${th('left')}">Role</th><th style="${th('left')}">Centre</th>
          <th style="${th('right')}">Punches</th><th style="${th('right')}">Hours</th>
        </tr></thead><tbody>${body}
        <tr><td colspan="4" style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">${escapeHtml(g.label.replace(/^\S+\s/, ''))} subtotal</td>
        <td style="padding:9px 8px;text-align:right;font-weight:700;border-top:2px solid #CBD5E1;">${sub.toFixed(2)}</td></tr>
        </tbody></table></div>`;
    };

    host.innerHTML = GROUPS.map(section).join('')
      + `<div style="display:flex;justify-content:flex-end;gap:14px;align-items:baseline;border-top:2px solid #1F6080;padding:12px 8px 0;">
          <span style="font-size:14px;font-weight:700;color:#0F172A;">Total hours</span>
          <span style="font-size:18px;font-weight:800;color:#1F6080;">${grand.toFixed(2)}</span></div>`;
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
  async function renderSms(main) {
    // The fields used to be stacked full-width labels of differing widths, which made
    // the form look ragged. One card, one column of aligned rows: label left, control
    // right, every control the same width.
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-card" style="max-width:720px;padding:20px;">
        <div style="display:grid;grid-template-columns:120px 1fr;gap:12px 14px;align-items:center;">
          <label for="sms-aud" style="font-size:13px;font-weight:600;color:#334155;">Audience</label>
          <select id="sms-aud" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;">
            <option value="role">By role</option><option value="centre">By centre</option><option value="agency">Whole agency</option>
          </select>

          <label for="sms-role" id="sms-role-l" style="font-size:13px;font-weight:600;color:#334155;">Role</label>
          <select id="sms-role" class="kt-input" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;background:#fff;">
            <option value="guardian">Parents</option><option value="educator">Educators</option><option value="centre_director">Directors</option>
          </select>

          <label for="sms-body" style="font-size:13px;font-weight:600;color:#334155;align-self:start;padding-top:8px;">Message</label>
          <div>
            <textarea id="sms-body" maxlength="300" rows="4" style="width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;resize:vertical;font:inherit;"></textarea>
            <div style="display:flex;justify-content:space-between;margin-top:4px;">
              <span style="font-size:12px;color:#64748B;">Only recipients who opted in to SMS and have a phone number on file will receive it.</span>
              <span id="sms-count" style="font-size:12px;color:#64748B;">0 / 300</span>
            </div>
          </div>

          <div></div>
          <div style="display:flex;align-items:center;gap:12px;">
            <button id="sms-send" class="kt-btn kt-btn-primary" style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Send broadcast</button>
            <span id="sms-msg" style="font-size:13px;"></span>
          </div>
        </div>
      </div>

      <h3 style="margin:26px 0 10px;font-size:13px;color:#64748B;text-transform:uppercase;letter-spacing:.06em;">Recent broadcasts</h3>
      <div id="sms-recent"></div>
    </div>`;

    const body = document.getElementById('sms-body');
    const counter = document.getElementById('sms-count');
    body.addEventListener('input', () => { counter.textContent = `${body.value.length} / 300`; });

    const aud = document.getElementById('sms-aud');
    const roleRow = document.getElementById('sms-role-l');
    const roleSel = document.getElementById('sms-role');
    const syncRole = () => {
      const show = aud.value === 'role';
      roleRow.style.display = show ? '' : 'none';
      roleSel.style.display = show ? '' : 'none';
    };
    aud.addEventListener('change', syncRole);
    syncRole();

    document.getElementById('sms-send').onclick = async () => {
      const payload = { audience: aud.value, body: body.value, category: 'broadcast' };
      if (aud.value === 'role') payload.role = roleSel.value;
      try {
        const r = await Api.post('/admin/sms/broadcast', payload);
        document.getElementById('sms-msg').innerHTML = `<span style="color:#047857;">Sent ${r.sent} · skipped ${r.skipped} · total ${r.total}</span>`;
      } catch (e) { document.getElementById('sms-msg').innerHTML = '<span style="color:#B91C1C;">Send failed</span>'; }
      loadSmsRecent();
    };
    loadSmsRecent();
  }

  // Recent broadcasts as a REAL table, so it picks up the same search, sort and record
  // count as every other table on the site (kt-table-filter + kt-table-export attach to
  // any #appMain table). It used to be a hand-rolled list of divs, which got none of it.
  async function loadSmsRecent() {
    const r = await Api.get('/admin/sms/messages').catch(() => ({ data: [] }));
    const host = document.getElementById('sms-recent');
    if (!host) return;
    const rows = (r && r.data) || [];
    if (!rows.length) {
      host.innerHTML = '<div class="kt-card" style="color:#64748B;padding:40px;text-align:center;font-size:13px;">No broadcasts sent yet.</div>';
      return;
    }
    const colour = (st) => st === 'sent' ? '#047857' : st === 'failed' ? '#B91C1C' : '#D97706';
    host.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:14px;background:#fff;">
      <thead style="background:#F8FAFC;">
        <tr>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">Sent</th>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">To</th>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">Status</th>
          <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #E2E8F0;">Message</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map(m => `<tr>
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;white-space:nowrap;">${fmtDate(m.created_at)}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;white-space:nowrap;">${escapeHtml(m.to_phone || '')}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #F1F5F9;"><span style="color:${colour(m.status)};font-weight:600;">${escapeHtml(m.status || '')}</span></td>
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
