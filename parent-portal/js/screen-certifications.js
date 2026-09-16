/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Certifications + Timesheets
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
  // Humanise stored enum values for display: "First_Aid" → "First Aid",
  // "food_handler" → "Food Handler" (leaves already-caps acronyms like "CPR" alone).
  function prettyType(s) { return s == null ? '' : String(s).replace(/[_-]+/g, ' ').replace(/\b[a-z]/g, function (m) { return m.toUpperCase(); }); }
  function $(s, r) { return (r || document).querySelector(s); }

  let activeCentreId = null;
  /* The timesheet's own centre filter. null means ALL centres, which is the default: an
     agency whose centres are its providers has no single "current" centre, and pinning one
     hid eight ninths of the staff with nothing on screen to say so. Kept separate from
     activeCentreId — that belongs to Certifications, and sharing it made one screen
     silently steer the other. */
  let tsCentreId = null;
  /* THE DATE RANGE THE READER ASKED FOR.

     The two date boxes on this screen did nothing. Reload read them into `f` and `t`,
     threw both away and called renderTimesheets(), which recomputes a fixed trailing
     30 days every single time. So a payroll period that ended five weeks ago could be
     typed into the boxes, and the screen would answer with the last thirty days and no
     indication that it had ignored the question.

     It matters more now that the rows can be corrected: you fix hours for a pay period,
     and you have to be able to reach the pay period. Held at module scope like the centre
     so a correction can re-render without dropping the reader back to today. */
  let tsFrom = null;
  let tsTo = null;
  // The centres this person can see, and the clock the sheet's times are on — both
  // needed by the row menu's dialogs, which open long after the render that loaded them.
  let tsCentres = [];
  let tsTimezone = null;

  async function getCentres() {
    // v21: hit /director/centres when user is a director, /admin/centres for agency_admin
    const user = (window.KT && window.KT.Auth && window.KT.Auth.user()) || {};
    const role = (window.KT && window.KT.Shell && window.KT.Shell.Roles && window.KT.Shell.Roles.primaryRoleOf(user)) || '';
    const url = role === 'agency_admin' ? '/admin/centres' : '/director/centres';
    try { const r = await api('GET', url); return r.centres || []; }
    catch (e) { return []; }
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
      <div style="padding:24px;max-width:1800px;">
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
                    <th style="${th()}"></th>
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

    // Wire per-row actions → the standard ⋮ kebab (view / edit / delete).
    const byId = {};
    (data.certifications || []).forEach(c => { byId[String(c.id)] = c; });
    container.querySelectorAll('[data-cert-view]').forEach(b => b.addEventListener('click', () => openCertModal(container, byId[b.getAttribute('data-cert-view')], true)));
    container.querySelectorAll('[data-cert-edit]').forEach(b => b.addEventListener('click', () => openCertModal(container, byId[b.getAttribute('data-cert-edit')], false)));
    container.querySelectorAll('[data-cert-del]').forEach(b => b.addEventListener('click', async () => {
      const c = byId[b.getAttribute('data-cert-del')];
      const ok = (window.KT && KT.confirm) ? await KT.confirm('Delete the ' + prettyType(c.cert_type) + ' certification for ' + c.staff_name + '?') : window.confirm('Delete this certification?');
      if (!ok) return;
      try { await api('DELETE', '/director/certifications/' + c.id); renderCerts(container); }
      catch (e) { alert('Could not delete: ' + e.message); }
    }));
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
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
        <td style="${td()}"><strong>${esc(prettyType(c.cert_type))}</strong>${c.certifier ? `<div style="font-size:11px;color:#64748B;">${esc(c.certifier)}</div>` : ''}</td>
        <td style="${td()}">${esc(c.issued_at || '—')}</td>
        <td style="${td()}">${esc(c.expires_at || '—')}${c.days_until_expiry != null && c.status !== 'expired' ? `<div style="font-size:11px;color:#64748B;">in ${c.days_until_expiry} days</div>` : ''}</td>
        <td style="${td()}"><span style="background:${b.bg};color:${b.fg};padding:4px 10px;border-radius:12px;font-size:12px;font-weight:700;">${b.txt}</span></td>
        <td style="${td()};text-align:right;white-space:nowrap;">
          <button data-cert-view="${c.id}" class="kt-act-icon kt-act-info kt-icon-tip" data-kttip="View" aria-label="View">👁️</button>
          <button data-cert-edit="${c.id}" class="kt-act-icon kt-act-edit kt-icon-tip" data-kttip="Edit" aria-label="Edit">✏️</button>
          <button data-cert-del="${c.id}" class="kt-act-icon kt-act-danger kt-icon-tip" data-kttip="Delete" aria-label="Delete">🗑️</button>
        </td>
      </tr>
    `;
  }

  const CERT_TYPES = ['RECE', 'First_Aid', 'CPR', 'Vulnerable_Sector_Check', 'Health_Card', 'Other'];

  // View (read-only) or Edit a certification. Edit PATCHes /director/certifications/{id}.
  function openCertModal(container, c, readOnly) {
    if (!c) return;
    const dis = readOnly ? 'disabled' : '';
    const inp = 'width:100%;padding:9px 11px;border:1.5px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;';
    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;';
    ov.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:460px;width:100%;padding:22px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div style="font-weight:800;font-size:18px;color:#0F172A;">${readOnly ? 'Certification' : 'Edit certification'}</div>
        <button type="button" data-x style="background:none;border:none;font-size:22px;cursor:pointer;color:#94A3B8;line-height:1;">×</button>
      </div>
      <div style="display:grid;gap:12px;">
        <div style="font-size:13px;color:#64748B;">Staff: <strong style="color:#0F172A;">${esc(c.staff_name)}</strong></div>
        <label style="font-size:13px;font-weight:600;">Type
          <select id="ce-type" ${dis} style="${inp}">${CERT_TYPES.map(t => `<option value="${t}" ${t === c.cert_type ? 'selected' : ''}>${esc(prettyType(t))}</option>`).join('')}</select></label>
        <label style="font-size:13px;font-weight:600;">Certifier
          <input id="ce-certifier" type="text" maxlength="120" value="${esc(c.certifier || '')}" ${dis} style="${inp}"></label>
        <label style="font-size:13px;font-weight:600;">Issued
          <input id="ce-issued" type="date" value="${esc(String(c.issued_at || '').slice(0, 10))}" ${dis} style="${inp}"></label>
        <label style="font-size:13px;font-weight:600;">Expires
          <input id="ce-expires" type="date" value="${esc(String(c.expires_at || '').slice(0, 10))}" ${dis} style="${inp}"></label>
      </div>
      ${readOnly
        ? '<div style="display:flex;justify-content:flex-end;margin-top:16px;"><button type="button" data-x style="background:#F1F5F9;color:#475569;border:none;border-radius:10px;padding:9px 18px;font-weight:800;cursor:pointer;">Close</button></div>'
        : '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;"><button type="button" data-x style="background:#F1F5F9;color:#475569;border:none;border-radius:10px;padding:9px 16px;font-weight:800;cursor:pointer;">Cancel</button><button type="button" id="ce-save" style="background:#1F6080;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-weight:800;cursor:pointer;">Save changes</button></div>'}
    </div>`;
    document.body.appendChild(ov);
    const close = () => { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
    ov.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', close));
    const save = ov.querySelector('#ce-save');
    if (save) save.addEventListener('click', async () => {
      const payload = {
        cert_type: ov.querySelector('#ce-type').value,
        certifier: ov.querySelector('#ce-certifier').value.trim(),
        issued_at: ov.querySelector('#ce-issued').value || null,
        expires_at: ov.querySelector('#ce-expires').value || null,
      };
      save.disabled = true;
      try { await api('PATCH', '/director/certifications/' + c.id, payload); close(); renderCerts(container); }
      catch (e) { save.disabled = false; alert('Could not save: ' + e.message); }
    });
  }

  // ─── TIMESHEETS ─────────────────────────────────────────────────
  async function renderTimesheets(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    const centres = await getCentres();
    if (centres.length === 0) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">No centres.</div>'; return; }
    // No default centre: all of them, unless one is chosen below.

    // v22p98: default to a trailing 30-day window (was first-of-month → today,
    // which is a single empty day when opened on the 1st, hiding all entries).
    // A DEFAULT, not an override — whatever the reader typed into the date boxes wins,
    // and is kept across a re-render so correcting an old shift does not snap the screen
    // back to this month. See tsFrom/tsTo above.
    const today = new Date();
    const startWin = new Date(); startWin.setDate(today.getDate() - 30);
    const from = tsFrom || startWin.toISOString().split('T')[0];
    const to = tsTo || today.toISOString().split('T')[0];
    tsFrom = from; tsTo = to;

    let data;
    // Omitted entirely when showing all centres — the endpoint reads that as "everything
    // this person can see" rather than as centre 0.
    var ctrQ = tsCentreId ? ('centre_id=' + tsCentreId + '&') : '';
    try { data = await api('GET', '/director/timesheets?' + ctrQ + 'from=' + from + '&to=' + to); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>'; return; }

    tsCentres = centres;
    tsTimezone = data.timezone || null;

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📊 Timesheets</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">${data.total_hours} hours across ${data.staff_count} staff · ${esc(data.from)} → ${esc(data.to)}</p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <select id="kt-ts-centre" style="padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:white;">
              <option value="" ${!tsCentreId ? 'selected' : ''}>All centres</option>
              ${centres.map(c => `<option value="${c.id}" ${c.id == tsCentreId ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
            </select>
            <input type="date" id="kt-from" value="${from}" style="padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">
            <input type="date" id="kt-to" value="${to}" style="padding:10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">
            <button id="kt-reload" class="kt-icon-tip" title="Reload" data-kttip="Reload" aria-label="Reload" style="width:38px;height:38px;box-sizing:border-box;background:#1F6080;color:#fff;border:none;border-radius:8px;font-size:16px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">🔄</button>
            <button id="kt-csv" class="kt-icon-tip" title="Download CSV" data-kttip="Download CSV" aria-label="Download CSV" style="width:38px;height:38px;box-sizing:border-box;background:#16A34A;color:#fff;border:none;border-radius:8px;font-size:16px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">⬇️</button>
          </div>
        </div>

        ${(data.rows || []).length === 0
          ? '<div style="padding:48px;background:white;border-radius:14px;text-align:center;color:#6B7280;">No timesheets in this date range.</div>'
          : `<div style="background:white;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);">
              <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead style="background:#F9FAFB;">
                  <tr><th style="${th()}">Date</th><th style="${th()}">Staff</th><th style="${th()}">In</th><th style="${th()}">Out</th><th style="${th()}">Break</th><th style="${th()}">Hours</th><th style="${th()}"></th></tr>
                </thead>
                <tbody>
                  ${data.rows.map((r, i) => `<tr style="border-top:1px solid #F3F4F6;">
                    <td style="${td()}white-space:nowrap;">${esc(r.date_label || r.date || '—')}</td>
                    <td style="${td()}">${esc(r.staff_name)}</td>
                    <td style="${td()}white-space:nowrap;">${esc(r.clock_in_label || r.clock_in)}</td>
                    <td style="${td()}white-space:nowrap;">${esc(r.clock_out_label || r.clock_out)}</td>
                    <td style="${td()}">${r.break_min} min</td>
                    <td style="${td()}"><strong>${r.worked_hours}h</strong></td>
                    ${tsActionCell(r, i)}
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>`
        }
      </div>
    `;

    /* Reload used to read these two values and drop them on the floor. Now they ARE the
       range. Reversed ends are swapped rather than refused: somebody who fills the boxes
       right to left has said what they meant perfectly clearly. */
    function applyRange() {
      var f = $('#kt-from', container).value, t = $('#kt-to', container).value;
      if (f && t && f > t) { var sw = f; f = t; t = sw; }
      tsFrom = f || null; tsTo = t || null;
      renderTimesheets(container);
    }
    $('#kt-reload', container).addEventListener('click', applyRange);
    // Changing a date applies immediately, like the centre filter below. A filter you
    // have to press Reload after is a filter people think is broken.
    $('#kt-from', container).addEventListener('change', applyRange);
    $('#kt-to', container).addEventListener('change', applyRange);
    $('#kt-csv', container).addEventListener('click', () => downloadCsv(data));

    /* ── The row menu ──────────────────────────────────────────────────────────

       THE SHEET WAS A PICTURE OF THE HOURS, and nothing more. A director could see on
       this screen that somebody's Tuesday ran to 11:47pm because nobody pressed
       clock-out, and had no way to do anything about it here: the correction lives on
       that person's own user record, behind a tab most directors have never opened. The
       endpoint has existed since 30 August. It simply had no door on the screen where
       the problem is actually noticed.

       This is payroll, not decoration — screen-manual-payroll reads these same punches,
       so a clock-out nobody pressed is a figure on somebody's payslip.

       Buttons in the LAST cell is the portal-wide recipe: kt-row-actions.js collapses
       them into the one ⋮ and reads each button's data-kttip as the menu wording.
       (Anthony, 2026-09-16) */
    const rows = data.rows || [];
    container.querySelectorAll('[data-ts-edit]').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      openShiftDialog(container, rows[+b.getAttribute('data-ts-edit')], 'edit');
    }));
    container.querySelectorAll('[data-ts-add]').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      openShiftDialog(container, rows[+b.getAttribute('data-ts-add')], 'add');
    }));
    container.querySelectorAll('[data-ts-del]').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      removeShift(container, rows[+b.getAttribute('data-ts-del')]);
    }));
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
    // Changing the centre re-runs immediately — a filter you have to press Reload after
    // is a filter people think is broken.
    $('#kt-ts-centre', container).addEventListener('change', (e) => {
      var v = e.target.value;
      tsCentreId = v ? parseInt(v, 10) : null;
      renderTimesheets(container);
    });
  }

  function tsActionCell(r, i) {
    var btns = [];
    if (r.id) {
      btns.push('<button data-ts-edit="' + i + '" class="kt-act-icon kt-act-edit kt-icon-tip" '
        + 'data-kttip="Edit hours" aria-label="Edit hours">✏️</button>');
      btns.push('<button data-ts-del="' + i + '" class="kt-act-icon kt-act-danger kt-icon-tip" '
        + 'data-kttip="Remove shift" aria-label="Remove shift">🗑️</button>');
    }
    /* Offered on every row, not only the empty ones: a split shift or a second visit the
       same day is a second punch for somebody who already has one. On a "No hours logged"
       row it is the only thing to offer — there is no punch to edit, and that is exactly
       the row where a flat tablet or a forgotten press needs entering by hand. */
    if (r.user_id) {
      btns.push('<button data-ts-add="' + i + '" class="kt-act-icon kt-act-teal kt-icon-tip" '
        + 'data-kttip="Add a shift" aria-label="Add a shift">➕</button>');
    }
    if (!btns.length) { return '<td style="' + td() + '"></td>'; }
    return '<td style="' + td() + 'text-align:right;white-space:nowrap;">' + btns.join('') + '</td>';
  }

  // ─── The shift editor ───────────────────────────────────────────
  const FIELD = 'width:100%;height:32px;padding:0 10px;border:1.5px solid #D1D5DB;'
    + 'border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;';

  function fld(type, value, placeholder) {
    var i = document.createElement('input');
    i.type = type;
    if (value) { i.value = value; }
    if (placeholder) { i.placeholder = placeholder; }
    i.style.cssText = FIELD;
    return i;
  }
  function labelled(text, node, hint) {
    var w = document.createElement('label');
    w.style.cssText = 'display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:12px;';
    var t = document.createElement('div');
    t.style.cssText = 'margin-bottom:4px;';
    t.textContent = text;
    w.appendChild(t);
    w.appendChild(node);
    if (hint) {
      var h = document.createElement('div');
      h.style.cssText = 'font-weight:500;font-size:11.5px;color:#94A3B8;margin-top:4px;';
      h.textContent = hint;
      w.appendChild(h);
    }
    return w;
  }
  // "America/Toronto" is a database key, not something to show a director.
  function clockName() {
    if (!tsTimezone) { return "the centre's clock"; }
    return String(tsTimezone).split('/').pop().replace(/_/g, ' ') + ' time';
  }

  /* Edit an existing shift, or enter one that was never clocked.
     Both write through the same admin punch endpoints the user record uses, so a
     correction made here is stamped on the punch and audited exactly as one made
     there — there is one story about a shift, not two. */
  function openShiftDialog(container, r, mode) {
    if (!r) { return; }
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) { return; }
    var adding = mode === 'add';

    var body = document.createElement('div');
    var who = document.createElement('div');
    who.style.cssText = 'font-size:13px;color:#64748B;margin-bottom:14px;';
    who.innerHTML = 'Staff: <strong style="color:#0F172A;">' + esc(r.staff_name) + '</strong>'
      + (adding || !r.date_label || r.date_label === '—' ? '' : ' · ' + esc(r.date_label));
    body.appendChild(who);

    /* WHICH CENTRE THE SHIFT IS FILED AT. Somebody posted across several — Safia Ali
       holds an active educator role at nine — has no single answer, and the API used to
       pick whichever role row sorted first, which is how hours land on the wrong
       provider's payroll. Shown and chosen rather than guessed. Only when adding: an
       existing punch already records where it was worked. */
    var fCentre = null;
    if (adding && tsCentres.length) {
      fCentre = document.createElement('select');
      fCentre.style.cssText = FIELD;
      tsCentres.forEach(function (c) {
        var o = document.createElement('option');
        o.value = c.id; o.textContent = c.name;
        if (String(c.id) === String(r.centre_id || tsCentreId || '')) { o.selected = true; }
        fCentre.appendChild(o);
      });
      body.appendChild(labelled('Centre', fCentre, 'The shift is filed here, and paid from here.'));
    }

    /* A new shift is seeded on the last day of the range being looked at, at 9am — the
       reader is looking at a period, not at today, and defaulting to today means the
       entry lands outside the sheet that prompted it and vanishes on save. */
    var fIn = fld('datetime-local', adding ? ((tsTo || '') + 'T09:00') : (r.in_local || ''));
    var fOut = fld('datetime-local', adding ? '' : (r.out_local || ''));
    body.appendChild(labelled('Clock in', fIn));
    body.appendChild(labelled('Clock out', fOut,
      (!adding && r.open)
        ? 'This shift is still open — nobody clocked out. Fill this in to close it.'
        : 'Leave empty to leave the shift open.'));

    var fWhy = fld('text', '', adding ? 'Why is this being entered by hand?' : 'Why are these times changing?');
    fWhy.maxLength = 200;
    body.appendChild(labelled(adding ? 'Reason' : 'Reason (optional)', fWhy));

    var note = document.createElement('div');
    note.style.cssText = 'font-size:11.5px;color:#94A3B8;line-height:1.55;';
    note.textContent = 'Times are ' + clockName() + ', not your device’s. '
      + 'This is recorded against your name, shows on the shift itself, and changes what payroll reads.';
    body.appendChild(note);

    var err = document.createElement('div');
    err.style.cssText = 'font-size:12.5px;color:#DC2626;margin-top:10px;font-weight:600;';
    body.appendChild(err);

    M.open({
      title: adding ? 'Add a shift' : 'Edit hours',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: adding ? 'Add shift' : 'Save changes',
          style: 'btn-primary',
          busyLabel: 'Saving…',
          // Returning false keeps the dialog open with the reason showing — a refusal
          // the reader never sees is a save they think worked.
          handler: async function () {
            err.textContent = '';
            if (!fIn.value) { err.textContent = 'A clock-in time is needed.'; return false; }
            if (fOut.value && fOut.value <= fIn.value) {
              err.textContent = 'The clock-out has to be after the clock-in.'; return false;
            }
            if (adding && !fWhy.value.trim()) {
              err.textContent = 'Please say why this shift is being entered by hand.'; return false;
            }
            try {
              if (adding) {
                await api('POST', '/admin/users/' + r.user_id + '/punches', {
                  punched_in_at: fIn.value,
                  punched_out_at: fOut.value || null,
                  reason: fWhy.value.trim(),
                  centre_id: fCentre ? parseInt(fCentre.value, 10) : (r.centre_id || tsCentreId || null),
                });
              } else {
                await api('PATCH', '/admin/users/' + r.user_id + '/punches/' + r.id, {
                  punched_in_at: fIn.value,
                  punched_out_at: fOut.value || null,
                  reason: fWhy.value.trim() || null,
                });
              }
            } catch (e) {
              err.textContent = (e && e.message) || 'Could not save.';
              return false;
            }
            if (window.KT && KT.Dom && KT.Dom.toast) {
              KT.Dom.toast(adding ? 'Shift added' : 'Hours corrected', 'success');
            }
            renderTimesheets(container);
          },
        },
      ],
    });
  }

  /* A correction answers "these times are wrong". It has no answer for "this shift never
     happened" — a double punch, a clock-in on somebody else's tablet, an entry typed
     against the wrong name. Without this, every mistyped manual entry would sit on
     payroll for good, which is not a thing to ship alongside a create button. */
  function removeShift(container, r) {
    if (!r || !r.id) { return; }
    var M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) { return; }

    var body = document.createElement('div');
    var p = document.createElement('p');
    p.style.cssText = 'font-size:14.5px;line-height:1.6;margin:0 0 14px;color:#0F172A;';
    p.innerHTML = 'Remove the shift for <strong>' + esc(r.staff_name) + '</strong> on '
      + esc(r.date_label || r.date || '') + '?<br><span style="color:#64748B;font-size:13px;">'
      + esc(r.clock_in_label || r.clock_in || '') + ' → ' + esc(r.clock_out_label || r.clock_out || '')
      + (r.worked_hours ? ' · ' + r.worked_hours + 'h' : '') + '</span>';
    body.appendChild(p);

    var fWhy = fld('text', '', 'Why is this shift being removed?');
    fWhy.maxLength = 200;
    body.appendChild(labelled('Reason', fWhy));

    var note = document.createElement('div');
    note.style.cssText = 'font-size:11.5px;color:#94A3B8;line-height:1.55;';
    note.textContent = 'The hours go with it. The removal is written to the audit log with your '
      + 'name and the times it held, which is the only record of it afterwards.';
    body.appendChild(note);

    var err = document.createElement('div');
    err.style.cssText = 'font-size:12.5px;color:#DC2626;margin-top:10px;font-weight:600;';
    body.appendChild(err);

    M.open({
      title: 'Remove shift',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: 'Remove shift',
          style: 'btn-danger',
          busyLabel: 'Removing…',
          handler: async function () {
            err.textContent = '';
            if (!fWhy.value.trim()) { err.textContent = 'Please say why.'; return false; }
            try {
              await api('DELETE', '/admin/users/' + r.user_id + '/punches/' + r.id, { reason: fWhy.value.trim() });
            } catch (e) {
              err.textContent = (e && e.message) || 'Could not remove.';
              return false;
            }
            if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast('Shift removed', 'success'); }
            renderTimesheets(container);
          },
        },
      ],
    });
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
