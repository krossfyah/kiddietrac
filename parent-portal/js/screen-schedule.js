/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Staff schedule (weekly view)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }

  function mondayOf(date) {
    const d = new Date(date); const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    return d.toISOString().split('T')[0];
  }

  let activeCentreId = null;
  let activeWeek = mondayOf(new Date());

  async function getCentres() {
    // v21: hit /director/centres when user is a director, /admin/centres for agency_admin
    const user = (window.KT && window.KT.Auth && window.KT.Auth.user()) || {};
    const role = (window.KT && window.KT.Shell && window.KT.Shell.Roles && window.KT.Shell.Roles.primaryRoleOf(user)) || '';
    const url = role === 'agency_admin' ? '/admin/centres' : '/director/centres';
    try { const r = await api('GET', url); return r.centres || []; }
    catch (e) { return []; }
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';

    const centres = await getCentres();
    if (centres.length === 0) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">No centres found.</div>';
      return;
    }
    if (!activeCentreId) activeCentreId = centres[0].id;

    let week, staff;
    try {
      week = await api('GET', '/director/schedule?centre_id=' + activeCentreId + '&week_starting=' + activeWeek);
      staff = await api('GET', '/director/schedule/staff?centre_id=' + activeCentreId);
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📅 Staff Schedule</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">${week.total_shifts} shift(s) this week</p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="kt-centre" style="${selectStyle()}">
              ${centres.map(c => `<option value="${c.id}" ${c.id==activeCentreId?'selected':''}>${esc(c.name)}</option>`).join('')}
            </select>
            <button id="kt-prev-week" style="${navBtnStyle()}">‹</button>
            <input type="date" id="kt-week" value="${activeWeek}" style="${selectStyle()};width:160px;">
            <button id="kt-next-week" style="${navBtnStyle()}">›</button>
            <button id="kt-new-shift" style="background:#1F6080;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;">+ New Shift</button>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;">
          ${Object.entries(week.days).map(([date, info]) => dayColumn(date, info)).join('')}
        </div>

        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
          <a href="#timesheets" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:10px 16px;border-radius:8px;font-weight:600;text-decoration:none;font-size:14px;">📊 Export Timesheets</a>
          <a href="#certifications" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:10px 16px;border-radius:8px;font-weight:600;text-decoration:none;font-size:14px;">🎓 Certifications</a>
        </div>

        <div id="kt-mount"></div>
      </div>
    `;

    $('#kt-centre', container).addEventListener('change', (e) => { activeCentreId = parseInt(e.target.value, 10); render(container); });
    $('#kt-week', container).addEventListener('change', (e) => { activeWeek = mondayOf(e.target.value); render(container); });
    $('#kt-prev-week', container).addEventListener('click', () => { const d = new Date(activeWeek); d.setDate(d.getDate()-7); activeWeek = mondayOf(d); render(container); });
    $('#kt-next-week', container).addEventListener('click', () => { const d = new Date(activeWeek); d.setDate(d.getDate()+7); activeWeek = mondayOf(d); render(container); });
    $('#kt-new-shift', container).addEventListener('click', () => openShiftModal(container, staff.staff || [], null));

    container.querySelectorAll('.kt-shift-card').forEach(el => el.addEventListener('click', () => {
      const shift = JSON.parse(el.dataset.shift);
      openShiftModal(container, staff.staff || [], shift);
    }));
  }

  function dayColumn(date, info) {
    const d = new Date(date);
    const dayLabel = d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    return `
      <div style="background:white;border-radius:10px;padding:10px;min-height:200px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280;letter-spacing:1px;margin-bottom:8px;">${esc(dayLabel)}</div>
        ${info.shifts.length === 0 ? '<div style="color:#D1D5DB;font-size:12px;">—</div>' : info.shifts.map(s => shiftCard(s)).join('')}
      </div>
    `;
  }

  function shiftCard(s) {
    const color = s.role === 'lead' ? '#1F6080' : s.role === 'support' ? '#8EC73C' : s.role === 'floater' ? '#F59E0B' : '#A78BFA';
    return `
      <div class="kt-shift-card" data-shift='${esc(JSON.stringify(s)).replace(/&quot;/g,'\"')}' style="background:${color}20;border-left:3px solid ${color};border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:11px;cursor:pointer;">
        <div style="font-weight:600;">${esc(s.starts_hm)}–${esc(s.ends_hm)}</div>
        <div style="margin-top:2px;color:#374151;">${esc(s.user_name)}</div>
        <div style="color:#6B7280;font-size:10px;">${esc(s.room_name)} · ${esc(s.role)}</div>
      </div>
    `;
  }

  function openShiftModal(container, staff, existing) {
    const isEdit = !!existing;
    const mount = $('#kt-mount', container);
    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;">
        <div style="background:white;border-radius:14px;max-width:480px;width:100%;padding:24px;">
          <h2 style="font-size:20px;margin:0 0 14px;">${isEdit ? 'Edit shift' : 'New shift'}</h2>
          <form id="kt-shift-form" onsubmit="return false;" style="display:grid;gap:10px;">
            <label style="font-size:13px;font-weight:600;">Staff *</label>
            <select name="user_id" required style="${selectStyle()}">
              <option value="">— Pick someone —</option>
              ${staff.map(u => `<option value="${u.id}" ${existing && existing.user_id==u.id?'selected':''}>${esc(u.name)}</option>`).join('')}
            </select>
            <label style="font-size:13px;font-weight:600;">Room *</label>
            <input type="number" name="room_id" required placeholder="Room ID" value="${existing?existing.room_id:''}" style="${selectStyle()}">
            <label style="font-size:13px;font-weight:600;">Starts</label>
            <input type="datetime-local" name="starts_at" required value="${existing?toLocalDt(existing.starts_at):activeWeek+'T09:00'}" style="${selectStyle()}">
            <label style="font-size:13px;font-weight:600;">Ends</label>
            <input type="datetime-local" name="ends_at" required value="${existing?toLocalDt(existing.ends_at):activeWeek+'T17:00'}" style="${selectStyle()}">
            <label style="font-size:13px;font-weight:600;">Role</label>
            <select name="role" style="${selectStyle()}">
              ${['lead','support','floater','volunteer'].map(r => `<option value="${r}" ${existing&&existing.role===r?'selected':''}>${r}</option>`).join('')}
            </select>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:space-between;gap:8px;">
              <div>${isEdit ? '<button type="button" id="kt-delete" style="background:#FEE2E2;color:#991B1B;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Delete</button>' : ''}</div>
              <div style="display:flex;gap:8px;">
                <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">${isEdit?'Update':'Create'}</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => mount.innerHTML = '';
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    if (isEdit) {
      $('#kt-delete', mount).addEventListener('click', async () => {
        if (!await KT.confirm('Delete this shift?')) return;
        try {
          await api('DELETE', '/director/schedule/shift/' + existing.id);
          close(); render(container);
        } catch (e) { alert('Could not delete: ' + e.message); }
      });
    }

    $('#kt-shift-form', mount).addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = {};
      new FormData(e.target).forEach((v, k) => data[k] = v);
      data.user_id = parseInt(data.user_id, 10);
      data.room_id = parseInt(data.room_id, 10);
      try {
        if (isEdit) await api('PATCH', '/director/schedule/shift/' + existing.id, data);
        else await api('POST', '/director/schedule/shift', data);
        $('#kt-status', mount).style.color = '#16A34A';
        $('#kt-status', mount).textContent = '✓ Saved';
        setTimeout(() => { close(); render(container); }, 600);
      } catch (e) {
        $('#kt-status', mount).style.color = '#DC2626';
        $('#kt-status', mount).textContent = '✗ ' + e.message;
      }
    });
  }

  function toLocalDt(iso) {
    if (!iso) return '';
    return iso.replace(' ', 'T').substring(0, 16);
  }
  function selectStyle() { return 'padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:white;'; }
  function navBtnStyle() { return 'background:white;border:1px solid #D1D5DB;width:36px;height:36px;border-radius:8px;font-size:18px;font-weight:700;cursor:pointer;color:#6B7280;'; }

  window.KT = window.KT || {};
  window.KT.Schedule = { render };
})(window);
