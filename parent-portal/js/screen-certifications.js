/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Certifications + Timesheets
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  async function api(method, path) {
    const res = await fetch(apiBase() + path, { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }

  let activeCentreId = null;

  async function getCentres() {
    try { const r = await api('GET', '/admin/centres'); return r.centres || []; } catch (e) { return []; }
  }

  // ─── CERTIFICATIONS ─────────────────────────────────────────────
  async function renderCerts(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    const centres = await getCentres();
    if (centres.length === 0) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">No centres.</div>'; return; }
    if (!activeCentreId) activeCentreId = centres[0].id;

    let data;
    try { data = await api('GET', '/director/certifications?centre_id=' + activeCentreId); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>'; return; }

    const groups = { expired: [], expiring_soon: [], warning: [], ok: [] };
    (data.certifications || []).forEach(c => { (groups[c.status] || groups.ok).push(c); });

    container.innerHTML = `
      <div style="padding:24px;max-width:1100px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:24px;margin:0;">🎓 Staff Certifications</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">${data.total_active} active · ${data.expiring_soon} expiring in 30 days · ${data.expired} expired</p>
          </div>
          <select id="kt-centre" style="padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:white;">
            ${centres.map(c => `<option value="${c.id}" ${c.id==activeCentreId?'selected':''}>${esc(c.name)}</option>`).join('')}
          </select>
        </div>

        ${data.expired > 0 ? `<div style="background:#FEE2E2;border-left:4px solid #DC2626;padding:14px;border-radius:8px;margin-bottom:14px;">
          <strong style="color:#991B1B;">⚠ ${data.expired} certification(s) are expired</strong>
          <div style="font-size:13px;color:#7F1D1D;margin-top:4px;">Staff must renew before continuing to work in their certified role.</div>
        </div>` : ''}

        ${data.expiring_soon > 0 ? `<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:14px;border-radius:8px;margin-bottom:14px;">
          <strong style="color:#92400E;">⏰ ${data.expiring_soon} cert(s) expiring in 30 days</strong>
        </div>` : ''}

        ${(data.certifications || []).length === 0
          ? '<div style="padding:48px;background:white;border-radius:14px;text-align:center;color:#6B7280;">No certifications on file for this centre.</div>'
          : `<div style="background:white;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);">
              <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead style="background:#F9FAFB;">
                  <tr>
                    <th style="${th()}">Staff</th>
                    <th style="${th()}">Cert</th>
                    <th style="${th()}">Issued</th>
                    <th style="${th()}">Expires</th>
                    <th style="${th()}">Status</th>
                  </tr>
                </thead>
                <tbody>
                  ${data.certifications.map(c => certRow(c)).join('')}
                </tbody>
              </table>
            </div>`
        }
      </div>
    `;

    $('#kt-centre', container).addEventListener('change', (e) => {
      activeCentreId = parseInt(e.target.value, 10);
      renderCerts(container);
    });
  }

  function certRow(c) {
    const badges = {
      expired: { bg: '#FEE2E2', fg: '#991B1B', txt: '🚫 Expired' },
      expiring_soon: { bg: '#FEF3C7', fg: '#92400E', txt: '⏰ <30d' },
      warning: { bg: '#FEF9C3', fg: '#854D0E', txt: '60–90d' },
      ok: { bg: '#DCFCE7', fg: '#166534', txt: '✓ Good' },
    };
    const b = badges[c.status] || badges.ok;
    return `
      <tr style="border-top:1px solid #F3F4F6;">
        <td style="${td()}">${esc(c.staff_name)}</td>
        <td style="${td()}"><strong>${esc(c.cert_type)}</strong>${c.certifier ? `<div style="font-size:11px;color:#9CA3AF;">${esc(c.certifier)}</div>` : ''}</td>
        <td style="${td()}">${esc(c.issued_at || '—')}</td>
        <td style="${td()}">${esc(c.expires_at || '—')}${c.days_until_expiry != null && c.status !== 'expired' ? `<div style="font-size:11px;color:#9CA3AF;">in ${c.days_until_expiry} days</div>` : ''}</td>
        <td style="${td()}"><span style="background:${b.bg};color:${b.fg};padding:4px 10px;border-radius:12px;font-size:12px;font-weight:700;">${b.txt}</span></td>
      </tr>
    `;
  }

  // ─── TIMESHEETS ─────────────────────────────────────────────────
  async function renderTimesheets(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    const centres = await getCentres();
    if (centres.length === 0) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">No centres.</div>'; return; }
    if (!activeCentreId) activeCentreId = centres[0].id;

    const firstOfMonth = new Date(); firstOfMonth.setDate(1);
    const today = new Date();
    const from = firstOfMonth.toISOString().split('T')[0];
    const to = today.toISOString().split('T')[0];

    let data;
    try { data = await api('GET', '/director/timesheets?centre_id=' + activeCentreId + '&from=' + from + '&to=' + to); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>'; return; }

    container.innerHTML = `
      <div style="padding:24px;max-width:1200px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📊 Timesheets</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">${data.total_hours} hours across ${data.staff_count} staff · ${esc(data.from)} → ${esc(data.to)}</p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="date" id="kt-from" value="${from}" style="padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">
            <input type="date" id="kt-to" value="${to}" style="padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">
            <button id="kt-reload" style="background:#1F6080;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer;">Reload</button>
            <button id="kt-csv" style="background:#16A34A;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer;">⬇ CSV</button>
          </div>
        </div>

        ${(data.rows || []).length === 0
          ? '<div style="padding:48px;background:white;border-radius:14px;text-align:center;color:#6B7280;">No timesheets in this date range.</div>'
          : `<div style="background:white;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);">
              <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead style="background:#F9FAFB;">
                  <tr><th style="${th()}">Date</th><th style="${th()}">Staff</th><th style="${th()}">In</th><th style="${th()}">Out</th><th style="${th()}">Break</th><th style="${th()}">Hours</th></tr>
                </thead>
                <tbody>
                  ${data.rows.map(r => `<tr style="border-top:1px solid #F3F4F6;">
                    <td style="${td()}">${esc(r.date)}</td>
                    <td style="${td()}">${esc(r.staff_name)}</td>
                    <td style="${td()}">${esc(r.clock_in)}</td>
                    <td style="${td()}">${esc(r.clock_out)}</td>
                    <td style="${td()}">${r.break_min} min</td>
                    <td style="${td()}"><strong>${r.worked_hours}h</strong></td>
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>`
        }
      </div>
    `;

    $('#kt-reload', container).addEventListener('click', () => {
      const f = $('#kt-from', container).value, t = $('#kt-to', container).value;
      window.location.hash = '#timesheets';
      renderTimesheets(container);
    });
    $('#kt-csv', container).addEventListener('click', () => downloadCsv(data));
  }

  function downloadCsv(data) {
    const headers = ['Date','Staff','Email','Clock In','Clock Out','Break (min)','Worked (hrs)','Notes'];
    const rows = [headers.join(',')].concat((data.rows || []).map(r =>
      [r.date, r.staff_name, r.staff_email, r.clock_in, r.clock_out, r.break_min, r.worked_hours, (r.notes||'').replace(/,/g,';')].map(v => `"${v||''}"`).join(',')
    ));
    const blob = new Blob([rows.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'timesheets_' + data.from + '_to_' + data.to + '.csv';
    a.click();
    URL.revokeObjectURL(url);
  }

  function th() { return 'text-align:left;padding:12px 14px;font-size:12px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:1px;'; }
  function td() { return 'padding:12px 14px;color:#374151;'; }

  window.KT = window.KT || {};
  window.KT.Certifications = { render: renderCerts };
  window.KT.Timesheets = { render: renderTimesheets };
})(window);
