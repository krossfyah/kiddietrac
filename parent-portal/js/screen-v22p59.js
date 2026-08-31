/* v22p59 — 9 features, all hash-based screens. */
(function (window) {
  'use strict';
  const KT = (window.KT = window.KT || {});
  const Api = new Proxy({}, {
    get(_, prop) {
      const a = window.KT && window.KT.Api;
      if (!a) throw new Error('KT.Api not loaded yet — call after app.js initialises');
      const v = a[prop];
      return typeof v === 'function' ? v.bind(a) : v;
    }
  });

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
  // A timestamp, in the AGENCY's timezone — the standing rule for every date shown in
  // this portal. A support queue is read by time of day: two tickets raised eight hours
  // apart looked identical when only the date was shown, and one raised late evening
  // showed the wrong day to anyone reading from further west.
  const fmtStamp = (s) => {
    if (!s) return '';
    if (window.KT && KT.fmtDateTime) { return KT.fmtDateTime(s); }
    return fmtDate(s);
  };
  const fmtTime = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };

  // ============================ Family directory ============================
  async function renderDirectory(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const [dir, mine] = await Promise.all([
      Api.get('/directory').catch(() => ({ data: [] })),
      Api.get('/directory/me').catch(() => ({ data: null })),
    ]);
    const o = mine.data || {};
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>👪 Family directory</h2>
        <p>Connect with other families at your centre. Sharing your contact info is opt-in — uncheck anything you don't want to share.</p>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">My privacy</h3></div>
        <p style="color:#475569;font-size:14px;margin:4px 0;">Choose what you share with other families in <a href="#settings" style="color:#1F6080;font-weight:700;text-decoration:none;">Settings &rsaquo; Family directory</a>.</p>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Other families at your centre</h3></div>
        ${(dir.data || []).length ? `<div data-kt-list="1" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
          ${(dir.data || []).map(f => `<div style="background:#FAFCFE;padding:16px;border-radius:10px;border:1px solid #F1F5F9;">
            <strong style="color:#0F172A;font-size:15px;">${esc(f.family_name)}</strong>
            ${f.child_names && f.child_names.length ? `<div style="color:#475569;font-size:13px;margin-top:4px;">Children: ${f.child_names.map(esc).join(', ')}</div>` : ''}
            ${f.primary_email ? `<div style="color:#475569;font-size:13px;margin-top:6px;">📧 <a href="mailto:${esc(f.primary_email)}" style="color:#1F6080;">${esc(f.primary_email)}</a></div>` : ''}
            ${f.primary_phone ? `<div style="color:#475569;font-size:13px;margin-top:4px;">📞 ${esc(f.primary_phone)}</div>` : ''}
            ${f.city ? `<div style="color:#475569;font-size:13px;margin-top:4px;">📍 ${esc(f.city)}</div>` : ''}
          </div>`).join('')}
        </div>` : '<div style="color:#64748B;padding:20px;text-align:center;">No other families have opted in yet.</div>'}
      </div>
    </div>`;
    // Privacy preferences now live on the Settings screen (Family directory section).
  }

  // ============================ Conferences ============================
  async function renderConferences(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/conferences/slots').catch(() => ({ data: [] }));
    const slots = r.data || [];
    const u = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    const isStaff = Array.isArray(u.roles) && u.roles.some(r => ['educator', 'centre_director', 'agency_admin', 'platform_admin'].includes(r));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🗣 Parent-teacher conferences</h2>
        <p>${isStaff ? slots.length + ' slot(s) in your agency.' : (slots.length + ' open slot(s) at your centre. Pick one for each child.')}</p>
        ${isStaff ? '<div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="cf-new">+ Create slots</button></div>' : ''}
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>When</th><th>Teacher</th><th>Status</th>${isStaff ? '<th>Booked by</th>' : ''}<th></th></tr></thead>
          <tbody>${slots.map(s => `<tr>
            <td><strong>${fmtDate(s.slot_at)}</strong><div style="color:#64748B;font-size:12px;">${fmtTime(s.slot_at)} · ${s.duration_minutes} min</div></td>
            <td>${esc(s.teacher_name || 'Any teacher')}</td>
            <td><span class="kt-pill ${s.status === 'open' ? 'kt-pill-success' : 'kt-pill-warning'}">${esc(s.status)}</span></td>
            ${isStaff ? `<td>${esc(s.booked_by_name || '')} ${s.booked_child_name ? '(' + esc(s.booked_child_name) + ')' : ''}</td>` : ''}
            <td>${s.status === 'open' && !isStaff ? `<button class="kt-btn kt-btn-primary" data-book="${s.id}">Book</button>` : s.status === 'booked' ? `<button class="kt-btn kt-btn-danger" data-cancel="${s.id}" style="font-size:12px;">Cancel</button>` : ''}</td>
          </tr>`).join('') || '<tr><td colspan="' + (isStaff ? 5 : 4) + '" style="text-align:center;padding:40px;color:#64748B;">No slots scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    if (isStaff) document.getElementById('cf-new').onclick = () => openCreateSlotsModal();
    main.querySelectorAll('button[data-book]').forEach(b => b.onclick = async () => {
      const _kc = await Api.get('/parent/children'); const kids = _kc.children || _kc.data || [];
      const cid = kids.length === 1 ? kids[0].id : +prompt('Child ID for this booking? ' + kids.map(c => c.id + ': ' + c.first_name).join(', '));
      if (!cid) return;
      await Api.post(`/conferences/slots/${b.dataset.book}/book`, { child_id: cid });
      renderConferences(main);
    });
    main.querySelectorAll('button[data-cancel]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Cancel this conference?')) return;
      await Api.post(`/conferences/slots/${b.dataset.cancel}/cancel`, {});
      renderConferences(main);
    });
  }
  async function openCreateSlotsModal() {
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:460px;width:92%;">
      <h3 style="margin:0 0 16px;">Create conference slots</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Centre</label>
      <select id="cf-centre" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Date</label>
      <input id="cf-date" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <div style="display:flex;gap:8px;margin-top:14px;">
        <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;">Start</label>
          <input id="cf-start" type="time" value="15:00" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></div>
        <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;">End</label>
          <input id="cf-end" type="time" value="19:00" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></div>
        <div style="flex:1;"><label style="display:block;font-size:13px;font-weight:600;">Length (min)</label>
          <input id="cf-dur" type="number" value="20" min="10" max="120" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></div>
      </div>
      <div style="margin-top:20px;text-align:right;">
        <button id="cf-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="cf-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Generate slots</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#cf-cancel').onclick = () => m.remove();
    m.querySelector('#cf-save').onclick = async () => {
      await Api.post('/conferences/slots', {
        centre_id: +m.querySelector('#cf-centre').value,
        date: m.querySelector('#cf-date').value,
        start_time: m.querySelector('#cf-start').value,
        end_time: m.querySelector('#cf-end').value,
        duration_minutes: +m.querySelector('#cf-dur').value,
      });
      m.remove();
      renderConferences(document.querySelector('main'));
    };
  }

  // ============================ Field-trip GPS map ============================
  async function renderTripGps(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📍 Field-trip live tracker</h2>
        <p>Live location of staff lead on each active trip.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Trip ID</label>
        <div style="display:flex;gap:8px;">
          <input id="ft-id" type="number" placeholder="e.g. 1" style="flex:1;padding:11px;border:1px solid #E2E8F0;border-radius:8px;margin-top:6px;">
          <button id="ft-load" class="kt-btn kt-btn-primary" style="margin-top:6px;">Load</button>
        </div>
        <div id="ft-detail" style="margin-top:20px;"></div>
      </div>
    </div>`;
    document.getElementById('ft-load').onclick = async () => {
      const tid = +document.getElementById('ft-id').value;
      if (!tid) return;
      const r = await Api.get(`/field-trips/${tid}/location`);
      const det = document.getElementById('ft-detail');
      if (!r.latest) { det.innerHTML = '<div style="color:#64748B;padding:14px;">No location pings yet.</div>'; return; }
      det.innerHTML = `<div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Latitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lat).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Longitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lon).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Last ping</div><div class="kt-kpi-value" style="font-size:18px;">${fmtTime(r.latest.recorded_at)}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Trail length</div><div class="kt-kpi-value">${r.trail.length}</div></div>
      </div>
      <div style="margin-top:18px;">
        <iframe width="100%" height="500" frameborder="0" style="border-radius:12px;border:1px solid #E2E8F0;"
          src="https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(r.latest.lon)-0.01},${parseFloat(r.latest.lat)-0.01},${parseFloat(r.latest.lon)+0.01},${parseFloat(r.latest.lat)+0.01}&layer=mapnik&marker=${r.latest.lat},${r.latest.lon}"></iframe>
        <div style="margin-top:8px;font-size:12px;color:#64748B;">
          <a href="https://www.openstreetmap.org/?mlat=${r.latest.lat}&mlon=${r.latest.lon}#map=15/${r.latest.lat}/${r.latest.lon}" target="_blank" style="color:#1F6080;">View larger map</a>
        </div>
      </div>`;
    };
  }

  // ============================ Attendance pattern (parent + admin) ============================
  // Exposed so a child's record can embed this editor rather than growing a
  // second copy that drifts from it.
  function exposePatternApi(renderFn) {
    window.KT = window.KT || {};
    window.KT.AttendancePattern = {
      renderInto: function (el, childId) { if (el) return renderFn(el, childId); },
    };
  }

  async function renderAttendancePattern(main, preselectChildId) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';

    let ov;
    try {
      ov = await Api.get('/attendance/weekly-overview');
    } catch (e) {
      main.innerHTML = '<div class="kt-card" style="margin:24px;padding:32px;text-align:center;color:#B45309;">'
        + 'Could not load attendance patterns' + (e && e.message ? ' — ' + esc(e.message) : '') + '.</div>';
      return;
    }
    const rows = ov.data || [];
    const ROT = ov.rotations || [
      { key: 'full', label: 'Full day', short: 'Full' }, { key: 'am', label: 'Morning only', short: 'AM' },
      { key: 'pm', label: 'Afternoon only', short: 'PM' }, { key: 'before', label: 'Before school', short: 'Before' },
      { key: 'after', label: 'After school', short: 'After' }, { key: 'bna', label: 'Before and after school', short: 'B&A' },
    ];
    const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    const DAY_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    if (!rows.length) {
      main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#64748B;padding:40px;">No children yet.</div>';
      return;
    }

    // ── Why this is a grid ────────────────────────────────────────────────
    // The previous version showed ONE child at a time behind a dropdown, with
    // seven stacked rows of seven option pills — 49 pills per child, and no way
    // to see the agency at all. Setting a term's patterns meant 77 rounds of
    // pick-scroll-save, and answering "who is in on Wednesday?" was impossible.
    //
    // Children down, days across is the shape the data already has. It makes the
    // whole week visible, comparable between children, and editable in place —
    // and it gives room for the two things the old screen could not do at all:
    // apply a pattern to many children at once, and see who has no pattern yet.
    // Compact pill buttons, matching the tight control sizing used elsewhere.
    const BTN = 'height:28px;padding:0 11px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;font-size:12px;font-weight:700;color:#475569;cursor:pointer;';
    const dirty = {};                       // child_id -> true, rows with unsaved edits
    const draft = {};                       // child_id -> { monday: 'full', ... }
    rows.forEach((c) => { draft[c.id] = {}; DAYS.forEach((d) => { draft[c.id][d] = c[d] || ''; }); });

    const nameOf = (c) => ((c.first_name || '') + ' ' + (c.last_name || '')).trim();
    // A tint per rotation so a week reads at a glance rather than by reading text.
    const TINT = { full: '#DCFCE7', am: '#DBEAFE', pm: '#FEF3C7', before: '#EDE9FE', after: '#FFE4E6', bna: '#E0F2FE' };
    const INK = { full: '#166534', am: '#1E40AF', pm: '#92400E', before: '#5B21B6', after: '#9F1239', bna: '#075985' };
    // A rotation added on the server side should not silently come out grey like every
    // other unknown key. Any key without a hand-picked colour gets its own, derived from
    // the key itself so it is stable between loads rather than shuffling on each render.
    function tintFor(key) {
      if (TINT[key]) { return TINT[key]; }
      let h = 0;
      for (let i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) % 360; }
      return 'hsl(' + h + ',72%,91%)';
    }
    function inkFor(key) {
      if (INK[key]) { return INK[key]; }
      let h = 0;
      for (let i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) % 360; }
      return 'hsl(' + h + ',68%,28%)';
    }

    main.innerHTML =
      '<div style="padding:24px;max-width:1600px;margin:0 auto;">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);">'
      +   '<div class="kt-hero-greet">📅 PLANNING</div><h1>Attendance pattern</h1>'
      +   '<div class="kt-hero-sub">Which days each child normally attends, and when in the day. '
      +   'Drives ratios, room planning and tuition projections.</div></div>'
      + '<div class="kt-card" style="margin-top:16px;">'
      +   '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">'
      +     '<div style="flex:2 1 220px;"><label style="font-size:11.5px;font-weight:800;color:#64748B;">Search</label>'
      +       '<input id="ap-q" type="search" placeholder="Name, centre or room" autocomplete="off" '
      +       'style="width:100%;height:32px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;box-sizing:border-box;"></div>'
      +     '<div style="flex:1 1 170px;"><label style="font-size:11.5px;font-weight:800;color:#64748B;">Show</label>'
      +       '<select id="ap-filter" style="width:100%;height:32px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;padding:0 8px;">'
      +         '<option value="enrolled">Enrolled only</option>'
      +         '<option value="missing">Missing a pattern</option>'
      +         '<option value="all">Everyone</option>'
      +       '</select></div>'
      +     '<div style="flex:1 1 150px;"><label style="font-size:11.5px;font-weight:800;color:#64748B;">Changes apply from</label>'
      +       '<input id="ap-from" type="date" value="' + (new Date().toISOString().slice(0, 10)) + '" '
      +       'style="width:100%;height:32px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;padding:0 8px;box-sizing:border-box;"></div>'
      +     '<button id="ap-save" disabled style="height:32px;padding:0 16px;background:#1F6080;color:#fff;border:0;'
      +       'border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;opacity:.45;">Save changes</button>'
      +     '<span id="ap-msg" style="font-size:12.5px;font-weight:700;"></span>'
      +   '</div>'
      // Bulk fill: most children share one pattern, and setting it 77 times by
      // hand was the single biggest cost of the old screen.
      +   '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #EDF2F7;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
      +     '<span style="font-size:11.5px;font-weight:800;color:#64748B;">Apply to everyone shown:</span>'
      +     '<button class="ap-bulk" data-p="mf-full" style="' + BTN + '">Mon–Fri, full day</button>'
      +     '<button class="ap-bulk" data-p="mf-am" style="' + BTN + '">Mon–Fri, mornings</button>'
      +     '<button class="ap-bulk" data-p="mf-pm" style="' + BTN + '">Mon–Fri, afternoons</button>'
      +     '<button class="ap-bulk" data-p="mf-bna" style="' + BTN + '">Mon–Fri, before &amp; after</button>'
      +     '<button class="ap-bulk" data-p="clear" style="' + BTN + '">Clear all days</button>'
      +   '</div>'
      + '</div>'
      + '<div class="kt-card" style="margin-top:16px;padding:0;overflow-x:auto;">'
      +   '<table id="ap-grid" style="width:100%;border-collapse:collapse;font-size:13px;min-width:900px;"></table>'
      + '</div>'
      + '<div id="ap-empty" style="display:none;padding:34px;text-align:center;color:#64748B;">No children match.</div>'
      + '</div>';

    const q = document.getElementById('ap-q');
    const filter = document.getElementById('ap-filter');
    const grid = document.getElementById('ap-grid');
    const saveBtn = document.getElementById('ap-save');
    const msg = document.getElementById('ap-msg');

    function visible() {
      const term = (q.value || '').trim().toLowerCase();
      const mode = filter.value;
      return rows.filter((c) => {
        const st = String(c.enrollment_status || '').toLowerCase();
        if (mode === 'enrolled' && st !== 'enrolled') return false;
        if (mode === 'missing') {
          const any = DAYS.some((d) => draft[c.id][d]);
          if (any) return false;
        }
        if (!term) return true;
        return (nameOf(c) + ' ' + (c.centre_name || '') + ' ' + (c.room_name || '')).toLowerCase().indexOf(term) > -1;
      });
    }

    function cell(c, d) {
      const cur = draft[c.id][d] || '';
      // A day with nothing set is dimmed and dashed rather than left white. White is the
      // table's own colour, so a gap in a child's week disappeared into the background —
      // and a gap is exactly what you are scanning this grid for.
      const bg = cur ? tintFor(cur) : '#F8FAFC';
      const fg = cur ? inkFor(cur) : '#CBD5E1';
      const border = cur ? '1px solid transparent' : '1px dashed #E2E8F0';
      let html = '<td style="padding:4px;text-align:center;border-bottom:1px solid #F1F5F9;">'
        + '<select data-child="' + c.id + '" data-day="' + d + '" '
        + 'style="width:100%;min-width:74px;height:30px;border:' + border + ';'
        + 'border-radius:7px;background:' + bg + ';color:' + fg + ';font-size:12px;font-weight:700;'
        + 'text-align:center;cursor:pointer;padding:0 4px;">';
      // The options carry their category colour too, so the list is read by colour and
      // the cell then takes the same one — you see what you picked before you pick it.
      html += '<option value="" style="background:#F8FAFC;color:#94A3B8;"'
        + (cur === '' ? ' selected' : '') + '>—</option>';
      ROT.forEach((r) => {
        const on = cur === r.key;
        html += '<option value="' + esc(r.key) + '"'
          + ' style="background:' + tintFor(r.key) + ';color:' + inkFor(r.key) + ';'
          // Not the current choice = dimmed, so the one in force stands out in the list.
          + 'font-weight:' + (on ? '800' : '600') + ';opacity:' + (on ? '1' : '.72') + ';"'
          + (on ? ' selected' : '') + '>' + esc(r.short) + '</option>';
      });
      return html + '</select></td>';
    }

    function statusPill(st) {
      const s = String(st || '').toLowerCase();
      const col = s === 'enrolled' ? ['#065F46', '#D1FAE5']
        : (s === 'withdrawn' ? ['#991B1B', '#FEE2E2'] : ['#92400E', '#FEF3C7']);
      return '<span style="background:' + col[1] + ';color:' + col[0] + ';border-radius:999px;padding:1px 7px;'
        + 'font-size:10px;font-weight:800;text-transform:capitalize;">' + esc(s || 'unknown') + '</span>';
    }

    function draw() {
      const list = visible();
      document.getElementById('ap-empty').style.display = list.length ? 'none' : 'block';

      // Column totals answer "how many are in on Wednesday?" — a question the old
      // one-child-at-a-time screen could not answer at all.
      const totals = DAYS.map((d) => list.filter((c) => draft[c.id][d]).length);

      let html = '<thead><tr>'
        + '<th style="text-align:left;padding:10px 12px;position:sticky;left:0;background:#EEF3F8;z-index:2;">Child</th>';
      DAY_SHORT.forEach((s, i) => {
        html += '<th style="padding:8px 4px;text-align:center;min-width:80px;">' + s
          + '<div style="font-size:10.5px;font-weight:700;color:#64748B;margin-top:1px;">' + totals[i] + ' in</div></th>';
      });
      html += '</tr></thead><tbody>';

      list.forEach((c) => {
        const isDirty = !!dirty[c.id];
        html += '<tr data-row="' + c.id + '" style="background:' + (isDirty ? '#FFFBEB' : 'transparent') + ';">'
          + '<td style="padding:7px 12px;border-bottom:1px solid #F1F5F9;position:sticky;left:0;'
          + 'background:' + (isDirty ? '#FFFBEB' : '#fff') + ';z-index:1;">'
          +   '<div style="font-weight:700;color:#0F172A;">' + esc(nameOf(c))
          +   (isDirty ? ' <span style="color:#B45309;font-size:11px;font-weight:800;">• unsaved</span>' : '') + '</div>'
          +   '<div style="font-size:11px;color:#64748B;margin-top:1px;">'
          +     statusPill(c.enrollment_status)
          +     (c.centre_name ? ' ' + esc(c.centre_name) : '')
          +     (c.room_name ? ' · ' + esc(c.room_name) : '')
          +   '</div>'
          + '</td>';
        DAYS.forEach((d) => { html += cell(c, d); });
        html += '</tr>';
      });
      grid.innerHTML = html + '</tbody>';

      const n = Object.keys(dirty).length;
      saveBtn.textContent = n ? 'Save ' + n + ' change' + (n === 1 ? '' : 's') : 'Save changes';
      saveBtn.disabled = !n;
      saveBtn.style.opacity = n ? '1' : '.45';
    }

    // One delegated listener for the whole grid, rather than one per control that
    // the old version re-attached on every redraw.
    grid.addEventListener('change', (e) => {
      const s = e.target;
      if (!s || s.tagName !== 'SELECT' || !s.getAttribute('data-child')) return;
      const id = s.getAttribute('data-child');
      draft[id][s.getAttribute('data-day')] = s.value || '';
      dirty[id] = true;
      draw();
    });

    q.addEventListener('input', draw);
    filter.addEventListener('change', draw);

    [].slice.call(document.getElementsByClassName('ap-bulk')).forEach((b) => {
      b.addEventListener('click', async () => {
        const list = visible();
        if (!list.length) return;
        const p = b.getAttribute('data-p');
        const label = b.textContent.trim();
        const ok = await KT.confirm({
          title: 'Apply "' + label + '" to ' + list.length + ' child' + (list.length === 1 ? '' : 'ren') + '?',
          description: 'This fills the grid for everyone currently shown. Nothing is written until you press Save changes.',
          okLabel: 'Fill grid',
        });
        if (!ok) return;
        const rot = p === 'mf-full' ? 'full' : p === 'mf-am' ? 'am' : p === 'mf-pm' ? 'pm' : p === 'mf-bna' ? 'bna' : '';
        list.forEach((c) => {
          DAYS.forEach((d, i) => { draft[c.id][d] = (p === 'clear' || i > 4) ? '' : rot; });
          dirty[c.id] = true;
        });
        draw();
      });
    });

    saveBtn.addEventListener('click', async () => {
      const ids = Object.keys(dirty);
      if (!ids.length) return;
      const from = document.getElementById('ap-from').value || new Date().toISOString().slice(0, 10);
      saveBtn.disabled = true; saveBtn.style.opacity = '.45';
      let done = 0, failed = 0;
      for (const id of ids) {
        const c = rows.filter((r) => String(r.id) === String(id))[0];
        if (!c) continue;
        const payload = { effective_from: from, notes: c.notes || null };
        DAYS.forEach((d) => { payload[d] = draft[id][d] || null; });
        if (c.room_id) payload.room_id = c.room_id;
        msg.style.color = '#64748B';
        msg.textContent = 'Saving ' + (done + failed + 1) + ' of ' + ids.length + '…';
        try {
          await Api.post('/attendance/pattern/' + id, payload);
          DAYS.forEach((d) => { c[d] = draft[id][d] || null; });
          c.has_pattern = DAYS.some((d) => draft[id][d]);
          delete dirty[id];
          done++;
        } catch (e) { failed++; }
      }
      msg.style.color = failed ? '#B91C1C' : '#16A34A';
      msg.textContent = failed ? (done + ' saved, ' + failed + ' failed') : ('✓ Saved ' + done);
      draw();
    });

    // Opened from a child's record: show only them, and hide the tools that only
    // make sense across the agency.
    if (preselectChildId) {
      q.value = String(preselectChildId);
      const one = rows.filter((r) => String(r.id) === String(preselectChildId))[0];
      if (one) {
        q.value = nameOf(one);
        filter.value = 'all';
      }
      const bulk = document.getElementsByClassName('ap-bulk')[0];
      if (bulk && bulk.parentElement) bulk.parentElement.style.display = 'none';
      q.parentElement.style.display = 'none';
      filter.parentElement.style.display = 'none';
    }

    draw();
  }

  exposePatternApi(renderAttendancePattern);

  async function renderReportCards(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    // Children DROPDOWN (was a raw "Child ID" number box — with nowhere in the app to
    // look that number up, it was unusable). Pull the agency's children so staff pick a
    // name; /admin/children is agency-scoped and returns {children:[...]} (empty if no
    // active agency is selected; 403 for a platform_admin with none).
    let kids = [];
    let kidsErr = 0;
    try {
      const kr = await Api.get('/admin/children');
      kids = (kr && Array.isArray(kr.children)) ? kr.children
           : Array.isArray(kr) ? kr
           : (kr && Array.isArray(kr.data)) ? kr.data : [];
    } catch (e) { kidsErr = (e && e.status) || 0; }
    // Educators / home-visitors can't hit /admin/children (agency-admin only) → it
    // 403s and the picker was empty. Fall back to their own centre roster.
    if (!kids.length) {
      try {
        const pr = await Api.get('/provider/children');
        if (pr && Array.isArray(pr.children) && pr.children.length) { kids = pr.children; kidsErr = 0; }
      } catch (e2) {}
    }
    const kidLabel = (c) => {
      const nm = ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.preferred_name || ('Child #' + c.id);
      return esc(nm) + (c.centre_name ? ' · ' + esc(c.centre_name) : '');
    };
    const kidOptions = kids.map(c => `<option value="${c.id}">${kidLabel(c)}</option>`).join('');
    const noKidsMsg = kids.length ? '' :
      `<div style="margin-top:6px;color:#64748B;font-size:12.5px;">${kidsErr === 403 ? 'Select an agency (top-bar switcher) to load its children.' : 'No children found for this agency.'}</div>`;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📑 Report cards</h2>
        <p>Generate AI-drafted narratives per HDLH domain. Educators sign &amp; submit; a director/admin signs &amp; approves before it reaches the family.</p>
      </div>
      <div id="rc-pending"></div>
      <div class="kt-card">
        <div style="max-width:920px;">
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:620px;">
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Child</label>
              <style>#rc-cid,#rc-cid option{background:#fff !important;color:#0F172A !important;}</style>
              <select id="rc-cid" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;color:#0F172A;color-scheme:light;font-size:14px;">
                <option value="">Select a child…</option>
                ${kidOptions}
              </select>
              ${noKidsMsg}
            </div>
            <div>
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Term</label>
              <input id="rc-term" placeholder="e.g. 2026 Year-End" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
            </div>
          </div>
          <button id="rc-gen" class="kt-btn kt-btn-primary" style="margin-top:18px;">Generate via AI</button>
          <div id="rc-out" style="margin-top:20px;"></div>
        </div>
      </div>
    </div>`;
    document.getElementById('rc-gen').onclick = async () => {
      const cid = +document.getElementById('rc-cid').value;
      const term = document.getElementById('rc-term').value;
      if (!cid || !term) { (window.KT && window.KT.toast) ? KT.toast('Select a child and enter a term', /save|sent|added|created|approved|deleted|removed|done|charged/i.test('Select a child and enter a term') ? 'success' : 'info') : alert('Select a child and enter a term'); return; }
      const btn = document.getElementById('rc-gen');
      btn.disabled = true; btn.textContent = 'Generating… (15-30 sec)';
      try {
        const r = await Api.post('/report-cards/generate', { child_id: cid, term });
        document.getElementById('rc-out').innerHTML = ['belonging', 'wellbeing', 'engagement', 'expression'].map(d => `
          <h4 style="margin:16px 0 6px;color:#1F6080;text-transform:capitalize;">${d.replace('wellbeing', 'Well-being')}</h4>
          <textarea id="rc-${d}" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc((r.narratives || {})[d] || '')}</textarea>
        `).join('') + `
          <h4 style="margin:16px 0 6px;color:#F59E0B;">Next steps</h4>
          <textarea id="rc-next" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(r.next_steps || '')}</textarea>
          <div id="rc-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;"></div>
          <p style="font-size:12px;color:#94A3B8;margin-top:8px;line-height:1.5;">Educators sign &amp; submit for their director/admin to review, sign and send to the family.</p>
        `;
        var _rcRoles = []; try { _rcRoles = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) {}
        var _rcApprover = ['centre_director', 'agency_admin', 'platform_admin'].some(function (x) { return _rcRoles.indexOf(x) !== -1; });
        var saveEdits = async function (status) {
          await Api.patch(`/report-cards/${r.id}`, {
            narrative_belonging: document.getElementById('rc-belonging').value,
            narrative_wellbeing: document.getElementById('rc-wellbeing').value,
            narrative_engagement: document.getElementById('rc-engagement').value,
            narrative_expression: document.getElementById('rc-expression').value,
            next_steps: document.getElementById('rc-next').value,
            status: status || 'reviewed',
          });
        };
        var _acts = document.getElementById('rc-actions');
        _acts.innerHTML = '<button id="rc-save" class="kt-btn kt-btn-primary">💾 Save edits</button>'
          + (_rcApprover
            ? '<button id="rc-approve" class="kt-btn kt-btn-success">✍️ Sign, approve &amp; send</button>'
            : '<button id="rc-submit" class="kt-btn kt-btn-success">✍️ Sign &amp; submit for approval</button>');
        document.getElementById('rc-save').onclick = async () => { await saveEdits('reviewed'); (window.KT && KT.toast) ? KT.toast('Saved.', 'success') : alert('Saved.'); };
        if (_rcApprover) {
          document.getElementById('rc-approve').onclick = async () => {
            await saveEdits('reviewed');
            var sig = await KT.signaturePad({ title: 'Director / admin sign-off', subtitle: 'Your signature approves this report card and sends it to the family.', okLabel: 'Approve & send' });
            if (!sig) return;
            try { await Api.post(`/report-cards/${r.id}/approve`, { admin_signature: sig }); (window.KT && KT.toast) ? KT.toast('Approved & sent to the family. ✓', 'success') : alert('Approved & sent.'); }
            catch (e) { (window.KT && KT.toast) ? KT.toast(e.message || 'Could not approve', 'error') : alert(e.message); }
          };
        } else {
          document.getElementById('rc-submit').onclick = async () => {
            await saveEdits('reviewed');
            var sig = await KT.signaturePad({ title: 'Educator signature', subtitle: 'Sign to submit this report card to your director/admin for review & approval.', okLabel: 'Sign & submit' });
            if (!sig) return;
            try { await Api.post(`/report-cards/${r.id}/submit`, { educator_signature: sig }); (window.KT && KT.toast) ? KT.toast('Submitted for approval — your director/admin was notified. ✓', 'success') : alert('Submitted for approval.'); }
            catch (e) { (window.KT && KT.toast) ? KT.toast(e.message || 'Could not submit', 'error') : alert(e.message); }
          };
        }
      } catch (e) { const _m = 'AI failed: ' + (e.message || e); (window.KT && window.KT.toast) ? KT.toast(_m, 'error') : alert(_m); }
      finally { btn.disabled = false; btn.textContent = 'Generate via AI'; }
    };

    // ── Director/admin: report cards awaiting approval ──────────────────
    async function reviewCard(childId, cardId) {
      let list; try { list = await Api.get('/report-cards/child/' + childId); } catch (e) { alert('Could not load the card.'); return; }
      const card = ((list && list.data) || []).find(c => +c.id === cardId);
      if (!card) { alert('Card not found.'); return; }
      const out = document.getElementById('rc-out'); if (!out) return;
      out.innerHTML = ['belonging', 'wellbeing', 'engagement', 'expression'].map(d =>
        `<h4 style="margin:16px 0 6px;color:#1F6080;text-transform:capitalize;">${d.replace('wellbeing', 'Well-being')}</h4>` +
        `<textarea id="rc-${d}" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(card['narrative_' + d] || '')}</textarea>`).join('') +
        `<h4 style="margin:16px 0 6px;color:#F59E0B;">Next steps</h4><textarea id="rc-next" rows="5" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(card.next_steps || '')}</textarea>` +
        (card.educator_signature ? `<div style="margin-top:14px;font-size:12px;color:#64748B;">Educator signature</div><img src="${esc(card.educator_signature)}" style="height:66px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;padding:2px;">` : '') +
        `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
          <button id="rc-r-save" class="kt-btn kt-btn-primary">💾 Save edits</button>
          <button id="rc-r-approve" class="kt-btn kt-btn-success">✍️ Sign, approve &amp; send</button>
          <button id="rc-r-reject" class="kt-btn" style="background:#FEE2E2;color:#B91C1C;">↩ Send back for changes</button>
        </div>`;
      out.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const save = () => Api.patch('/report-cards/' + cardId, {
        narrative_belonging: document.getElementById('rc-belonging').value,
        narrative_wellbeing: document.getElementById('rc-wellbeing').value,
        narrative_engagement: document.getElementById('rc-engagement').value,
        narrative_expression: document.getElementById('rc-expression').value,
        next_steps: document.getElementById('rc-next').value,
      });
      document.getElementById('rc-r-save').onclick = async () => { await save(); (window.KT && KT.toast) ? KT.toast('Saved.', 'success') : alert('Saved.'); };
      document.getElementById('rc-r-approve').onclick = async () => {
        await save();
        const sig = await KT.signaturePad({ title: 'Director / admin sign-off', subtitle: 'Your signature approves this report card and sends it to the family.', okLabel: 'Approve & send' });
        if (!sig) return;
        try { await Api.post('/report-cards/' + cardId + '/approve', { admin_signature: sig }); (window.KT && KT.toast) ? KT.toast('Approved & sent to the family. ✓', 'success') : alert('Approved & sent.'); renderReportCards(main); }
        catch (e) { alert(e.message || 'Could not approve.'); }
      };
      document.getElementById('rc-r-reject').onclick = async () => {
        const note = prompt('What needs changing? The educator will see this note.'); if (note === null) return;
        try { await Api.post('/report-cards/' + cardId + '/reject', { note: note }); (window.KT && KT.toast) ? KT.toast('Sent back to the educator.', 'success') : alert('Sent back.'); renderReportCards(main); }
        catch (e) { alert(e.message || 'Could not send back.'); }
      };
    }

    (async () => {
      let roles = []; try { roles = (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) {}
      if (!['centre_director', 'agency_admin', 'platform_admin'].some(x => roles.indexOf(x) !== -1)) return;
      const host = document.getElementById('rc-pending'); if (!host) return;
      let r; try { r = await Api.get('/report-cards/pending'); } catch (e) { return; }
      const pend = (r && r.data) || [];
      if (!pend.length) return;
      host.innerHTML = '<div class="kt-card" style="border-left:4px solid #F59E0B;margin-bottom:16px;">' +
        `<h3 style="margin:0 0 4px;">✍️ Awaiting your approval <span style="background:#FEF3C7;color:#92400E;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;">${pend.length}</span></h3>` +
        '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Educators submitted these report cards for your review, signature and approval.</p>' +
        pend.map(p => `<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid #FDE68A;background:#FFFBEB;border-radius:12px;padding:12px 14px;margin-bottom:8px;">
          <div><b>${esc(p.child)}</b> — ${esc(p.term)}<div style="font-size:12px;color:#92400E;">Submitted by ${esc(p.submitted_by || 'an educator')}</div></div>
          <button class="kt-btn kt-btn-primary rc-review" data-id="${p.id}" data-cid="${p.child_id}">Review &amp; sign</button></div>`).join('') + '</div>';
      host.querySelectorAll('.rc-review').forEach(b => { b.onclick = () => reviewCard(+b.getAttribute('data-cid'), +b.getAttribute('data-id')); });
    })();
  }

  // ============================ Activity zones ============================
  async function renderZones(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    if (!centres.length) { main.innerHTML = '<div class="kt-card" style="margin:24px;text-align:center;color:#64748B;padding:40px;">No centres.</div>'; return; }
    const cid = centres[0].id;
    const today = new Date().toISOString().slice(0, 10);
    const [zones, report] = await Promise.all([
      Api.get(`/zones?centre_id=${cid}`),
      Api.get(`/zones/daily-report?centre_id=${cid}&date=${today}`),
    ]);
    const byZone = report.by_zone || {};
    const byChild = report.by_child || {};
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎨 Activity zones</h2>
        <p>${esc(centres[0].name)} · ${fmtDate(today)} · ${report.total_visits} visits today.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="z-new">+ New zone</button></div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Today's activity</h3></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
          ${(zones.data || []).map(z => `<div style="background:${esc(z.color)}22;border:2px solid ${esc(z.color)};padding:18px;border-radius:12px;text-align:center;">
            <div style="font-size:32px;">${esc(z.icon)}</div>
            <div style="font-weight:700;color:#0F172A;margin-top:6px;">${esc(z.name)}</div>
            <div style="color:${esc(z.color)};font-weight:700;font-size:22px;margin-top:4px;">${byZone[z.name] || 0}</div>
            <button class="kt-btn kt-btn-primary" data-log="${z.id}" data-name="${esc(z.name)}" style="margin-top:10px;font-size:12px;padding:6px 14px;">+ Log visit</button>
          </div>`).join('') || '<div style="color:#64748B;padding:40px;text-align:center;grid-column:1/-1;">No zones set up.</div>'}
        </div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Per-child visits today</h3></div>
        ${Object.keys(byChild).length ? `<table>
          <thead><tr><th>Child</th><th>Visited</th></tr></thead>
          <tbody>${Object.entries(byChild).map(([name, visits]) => `<tr>
            <td><strong>${esc(name)}</strong></td>
            <td>${visits.map(v => `<span class="kt-pill kt-pill-info">${v.icon} ${esc(v.zone)} <span style="opacity:.7">@ ${v.time}</span></span>`).join(' ')}</td>
          </tr>`).join('')}</tbody>
        </table>` : '<div style="color:#64748B;padding:20px;text-align:center;">No visits logged today.</div>'}
      </div>
    </div>`;
    document.getElementById('z-new').onclick = () => openZoneModal(cid);
    main.querySelectorAll('button[data-log]').forEach(b => b.onclick = async () => {
      const child = prompt(`Child ID visiting ${b.dataset.name}?`);
      if (!child) return;
      await Api.post('/zones/visit', { child_id: +child, zone_id: +b.dataset.log });
      renderZones(main);
    });
  }
  function openZoneModal(centreId) {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:440px;width:92%;">
      <h3 style="margin:0 0 16px;">New activity zone</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Name</label>
      <input id="z-name" placeholder="Art corner, Reading nook, Sensory…" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Icon (emoji)</label>
      <input id="z-icon" value="🎨" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Colour</label>
      <input id="z-color" type="color" value="#A855F7" style="width:100%;height:44px;padding:4px;border:1px solid #E2E8F0;border-radius:8px;">
      <div style="margin-top:20px;text-align:right;">
        <button id="z-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="z-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#z-cancel').onclick = () => m.remove();
    m.querySelector('#z-save').onclick = async () => {
      await Api.post('/zones', {
        centre_id: centreId,
        name: m.querySelector('#z-name').value,
        icon: m.querySelector('#z-icon').value,
        color: m.querySelector('#z-color').value,
      });
      m.remove();
      renderZones(document.querySelector('main'));
    };
  }

  // ============================ Support tickets ============================
  async function renderTickets(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/tickets').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎫 Support tickets</h2>
        <p>Operational issues that aren't chat-style conversations. Track to resolution with audit trail.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="tk-new">+ Raise ticket</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Ticket</th><th>Subject</th><th>Category</th><th>Severity</th><th>Status</th><th>Raised by</th><th>Created</th></tr></thead>
          <tbody>${(r.data || []).map(t => `<tr style="cursor:pointer;" data-open="${t.id}"><td style="font-weight:800;color:#475569;white-space:nowrap;">#${t.id}</td>
            <td><strong>${esc(t.subject)}</strong></td>
            <td><span class="kt-pill kt-pill-info">${esc(tkCat(t.category))}</span></td>
            <td><span class="kt-pill ${tkSev(t.priority).cls}">${esc(tkSev(t.priority).label)}</span></td>
            <td><span class="kt-pill ${tkStatus(t.status).cls}">${esc(tkStatus(t.status).label)}</span></td>
            <td>${esc(t.raised_by_name)}</td>
            <td>${fmtStamp(t.created_at)}</td></tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">No tickets yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('tk-new').onclick = () => openTicketModal();
    main.querySelectorAll('tr[data-open]').forEach(tr => tr.onclick = () => openTicket(+tr.dataset.open));
  }
  function openTicketModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';

    // Severity says what each level MEANS. "High" and "urgent" are indistinguishable
    // to somebody who is already having a bad morning, so everything lands as urgent.
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:560px;width:100%;max-height:calc(100vh - 40px);overflow-y:auto;">
      <h3 style="margin:0 0 16px;">Raise a ticket</h3>

      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Category</label>
      <select id="tk-cat" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="billing">Billing question</option><option value="enrollment">Enrollment</option>
        <option value="maintenance">Maintenance / repair</option><option value="technical">Technical / app issue</option>
        <option value="policy">Policy question</option><option value="other">Other</option>
      </select>

      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Severity</label>
      <select id="tk-pri" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="low">Low — a question, no rush</option>
        <option value="normal" selected>Normal — needs sorting, but we can work</option>
        <option value="high">High — something is broken and it is slowing us down</option>
        <option value="urgent">Urgent — we cannot run the day, or a child is affected</option>
      </select>

      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Subject</label>
      <input id="tk-sub" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">

      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">What happened?</label>
      <textarea id="tk-body" rows="5" placeholder="What you were doing, what you expected, and what happened instead."
        style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;box-sizing:border-box;"></textarea>

      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Screenshots or logs</label>
      <div style="font-size:12.5px;color:#64748B;margin-bottom:6px;">
        A screenshot usually explains it faster than a paragraph. Images, PDFs, logs, CSV or a zip — up to 10&nbsp;MB each.</div>
      <input id="tk-files" type="file" multiple accept="image/*,.pdf,.txt,.log,.csv,.json,.xml,.zip"
        style="width:100%;padding:9px;border:1px dashed #CBD5E1;border-radius:8px;font-size:13px;background:#F8FAFC;box-sizing:border-box;">
      <div id="tk-file-list" style="font-size:12.5px;color:#475569;margin-top:6px;"></div>

      <div id="tk-msg" style="font-size:13px;margin-top:12px;min-height:18px;"></div>

      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
        <button id="tk-cancel" class="kt-btn kt-btn-secondary" type="button">Cancel</button>
        <button id="tk-save" class="kt-btn kt-btn-primary" type="button">Raise ticket</button>
      </div></div>`;

    document.body.appendChild(m);

    const files = m.querySelector('#tk-files');
    const list = m.querySelector('#tk-file-list');
    const msg = m.querySelector('#tk-msg');

    files.addEventListener('change', function () {
      const names = Array.prototype.map.call(files.files || [], function (f) {
        return f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)';
      });
      list.textContent = names.length ? '📎 ' + names.join(', ') : '';
    });

    m.querySelector('#tk-cancel').onclick = () => m.remove();

    m.querySelector('#tk-save').onclick = async () => {
      const btn = m.querySelector('#tk-save');
      const subject = m.querySelector('#tk-sub').value.trim();
      if (!subject) {
        msg.style.color = '#B91C1C';
        msg.textContent = 'Please give it a subject so it can be found again.';
        return;
      }

      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Raising…';

      try {
        const res = await Api.post('/tickets', {
          category: m.querySelector('#tk-cat').value,
          priority: m.querySelector('#tk-pri').value,
          subject: subject,
          body: m.querySelector('#tk-body').value,
        });

        // Files go up AFTER the ticket exists — one needs the other to hang off. A
        // failed upload therefore costs the attachment, never the ticket itself.
        const id = (res && (res.id || res.ticket_id)) || null;
        const chosen = Array.prototype.slice.call(files.files || []);
        let failed = 0;

        if (id && chosen.length) {
          msg.textContent = 'Uploading ' + chosen.length + ' file' + (chosen.length === 1 ? '' : 's') + '…';
          for (const f of chosen) {
            const fd = new FormData();
            fd.append('file', f, f.name);
            try {
              // Api.post detects FormData, leaves Content-Type to the browser so the
              // multipart boundary is right, and carries the active-agency header.
              await Api.post('/tickets/' + id + '/files', fd);
            } catch (e) { failed++; }
          }
        }

        if (failed) {
          msg.style.color = '#B45309';
          msg.textContent = 'Ticket raised, but ' + failed + ' file' + (failed === 1 ? '' : 's')
            + ' could not be attached. You can add them from the ticket.';
          setTimeout(function () { m.remove(); refreshTickets(); }, 2600);
          return;
        }

        m.remove();
        refreshTickets();
      } catch (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not raise the ticket.';
      }
    };
  }

  /* Repaint the list after a change, without assuming which element is "main" — the
     old code passed document.querySelector('main'), which is not the portal container
     on every screen. */
  function refreshTickets() {
    const host = document.querySelector('#appMain') || document.querySelector('main');
    if (host) { renderTickets(host); }
  }
  /* ── Words, not column values ────────────────────────────────────────────
     A support screen is read by people who did not design the database. `awaiting_user`
     is a value; "Waiting on you" is what it means, and which of those two it is
     depends on who is reading. */
  const TK_STATUS = {
    open: { label: 'Open', cls: 'kt-pill-warning', dot: '#F59E0B' },
    awaiting_user: { label: 'Waiting on a reply', cls: 'kt-pill-info', dot: '#3B82F6' },
    resolved: { label: 'Resolved', cls: 'kt-pill-success', dot: '#10B981' },
    closed: { label: 'Closed', cls: 'kt-pill-success', dot: '#64748B' },
  };
  const TK_CAT = {
    billing: 'Billing question', enrollment: 'Enrollment',
    maintenance: 'Maintenance / repair', technical: 'Technical / app issue',
    policy: 'Policy question', crash: 'App crash',
    /* Raised by the help screen, not by the ticket form. */
    documentation: 'Help guide feedback', other: 'Other',
  };
  const TK_SEV = {
    low: { label: 'Low', cls: 'kt-pill-info' },
    normal: { label: 'Normal', cls: 'kt-pill-info' },
    high: { label: 'High', cls: 'kt-pill-warning' },
    urgent: { label: 'Urgent', cls: 'kt-pill-danger' },
  };
  function tkStatus(v) {
    return TK_STATUS[v] || { label: String(v || '—').replace(/_/g, ' '), cls: 'kt-pill-info', dot: '#94A3B8' };
  }
  function tkCat(v) { return TK_CAT[v] || String(v || '—').replace(/_/g, ' '); }
  function tkSev(v) { return TK_SEV[v] || { label: String(v || '—'), cls: 'kt-pill-info' }; }

  function tkSize(b) {
    b = +b || 0;
    if (b < 1024) { return b + ' B'; }
    if (b < 1024 * 1024) { return Math.round(b / 1024) + ' KB'; }
    return (b / 1048576).toFixed(1) + ' MB';
  }

  /* Attachments are access-controlled: the endpoint checks that this user may see
     this ticket before it streams a byte. That means an <img src> cannot fetch one —
     it carries no Authorization header — so each file is fetched with the token and
     turned into a blob URL. Which is also the reason these files are safe to keep:
     they are not sitting under the public root waiting to be guessed at. */
  async function tkFileBlob(ticketId, fileId) {
    const base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    const headers = { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') };
    const active = sessionStorage.getItem('kt_active_agency_id');
    if (active) { headers['X-Active-Agency-Id'] = active; }
    const r = await fetch(base + '/tickets/' + ticketId + '/files/' + fileId, { headers });
    if (!r.ok) { throw new Error('Could not load that file.'); }
    return URL.createObjectURL(await r.blob());
  }

  async function openTicket(id) {
    /* A failed load used to reject with nothing catching it, so the row simply did not
       open — no dialog, no message, nothing — and the unhandled rejection was picked up
       by the crash reporter and filed as a ticket of its own. That is how ticket #31 came
       to exist: a 403 opening ticket #27 became a crash report about "Error".

       Anything that can 403 or time out and is triggered by a click needs to say so. */
    let r;
    try {
      r = await Api.get(`/tickets/${id}`);
    } catch (e) {
      const code = e && e.status;
      const msg =
        code === 403 ? 'You do not have access to that ticket.'
        : code === 404 ? 'That ticket no longer exists.'
        : ('Could not open that ticket' + (e && e.message ? ': ' + e.message : '.'));
      (window.KT && KT.toast) ? KT.toast(msg, 'error') : alert(msg);
      return;
    }
    const t = r.ticket || {};
    const msgs = r.messages || [];
    const files = r.files || [];
    const events = r.events || [];

    const st = tkStatus(t.status);
    const sev = tkSev(t.priority);
    const done = t.status === 'resolved' || t.status === 'closed';

    /* One thread in time order. Replies and status changes were two separate lists,
       so a ticket that was answered and then reopened read as neither. */
    const thread = []
      .concat(msgs.map(x => ({ at: x.created_at, kind: 'msg', d: x })))
      .concat(events.map(x => ({ at: x.created_at, kind: 'evt', d: x })))
      .sort((a, b) => String(a.at || '').localeCompare(String(b.at || '')));

    function evtLine(e) {
      if (e.type === 'status') {
        return 'Status changed from <strong>' + esc(tkStatus(e.from_value).label)
          + '</strong> to <strong>' + esc(tkStatus(e.to_value).label) + '</strong>';
      }
      if (e.type === 'priority') {
        return 'Severity changed from <strong>' + esc(tkSev(e.from_value).label)
          + '</strong> to <strong>' + esc(tkSev(e.to_value).label) + '</strong>';
      }
      if (e.type === 'assigned') {
        return e.to_value ? 'Assigned to <strong>' + esc(e.to_value) + '</strong>' : 'Unassigned';
      }
      return esc(String(e.type || '').replace(/_/g, ' '));
    }

    // The facts, as a labelled list. They were a row of pills, which is fine for a
    // table and useless when you are trying to find out who raised something.
    const facts = [
      ['Raised by', esc(t.raised_by_name || '—')],
      ['Raised', esc(fmtStamp(t.created_at))],
      ['Category', esc(tkCat(t.category))],
      ['Centre', esc(t.centre_name || '—')],
      ['Assigned to', esc(t.assigned_name || 'Nobody yet')],
      ['Last activity', esc(fmtStamp(t.updated_at || t.created_at))],
    ].map(p => '<div><div style="font-size:11.5px;font-weight:800;letter-spacing:.4px;'
      + 'text-transform:uppercase;color:#94A3B8;margin-bottom:2px;">' + p[0] + '</div>'
      + '<div style="font-size:14px;color:#0F172A;">' + p[1] + '</div></div>').join('');

    const m = document.createElement('div');
    m.setAttribute('data-no-modal-guard', '1');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;'
      + 'display:flex;align-items:center;justify-content:center;padding:20px;';

    m.innerHTML = `<div style="background:#fff;border-radius:16px;max-width:760px;width:100%;
      max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;">

      <div style="padding:20px 24px 16px;border-bottom:1px solid #E2E8F0;flex:0 0 auto;">
        <div style="display:flex;align-items:flex-start;gap:12px;">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <span style="font-size:12.5px;font-weight:800;color:#94A3B8;">TICKET #${t.id}</span>
              <span class="kt-pill ${st.cls}">${esc(st.label)}</span>
              <span class="kt-pill ${sev.cls}">${esc(sev.label)} severity</span>
            </div>
            <h3 style="margin:0;font-size:19px;line-height:1.3;color:#0F172A;">${esc(t.subject)}</h3>
          </div>
          <button id="tk-x" class="kt-btn kt-btn-secondary kt-btn-sm" type="button"
            aria-label="Close" style="flex:0 0 auto;">✕</button>
        </div>
      </div>

      <div style="overflow-y:auto;flex:1 1 auto;padding:0 24px 4px;">

        ${done ? `<div style="margin:18px 0 0;padding:14px 16px;background:#ECFDF5;
          border:1px solid #A7F3D0;border-left:4px solid #10B981;border-radius:10px;">
          <div style="font-size:13px;font-weight:800;color:#065F46;margin-bottom:4px;">
            ✅ ${esc(st.label)}${t.resolved_by_name ? ' by ' + esc(t.resolved_by_name) : ''}
            ${t.resolved_at ? ' · ' + esc(fmtStamp(t.resolved_at)) : ''}</div>
          <div style="font-size:14px;color:#065F46;white-space:pre-wrap;line-height:1.55;">
${t.resolution ? esc(t.resolution) : '<em style="opacity:.75;">No note was left explaining what fixed it.</em>'}</div>
        </div>` : ''}

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
          gap:14px 18px;margin:18px 0 4px;padding-bottom:18px;border-bottom:1px solid #F1F5F9;">
          ${facts}
        </div>

        <div style="margin:18px 0 0;">
          <div style="font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
            color:#94A3B8;margin-bottom:8px;">What happened</div>
          <div style="background:#F8FAFC;border:1px solid #E2E8F0;padding:14px 16px;border-radius:10px;
            font-size:14.5px;line-height:1.6;color:#1E293B;white-space:pre-wrap;word-break:break-word;">
${t.body ? esc(t.body) : '<em style="color:#94A3B8;">Nothing was written in the description.</em>'}</div>
        </div>

        ${files.length ? `<div style="margin:18px 0 0;">
          <div style="font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
            color:#94A3B8;margin-bottom:8px;">Attached (${files.length})</div>
          <div id="tk-files-box" style="display:flex;flex-wrap:wrap;gap:10px;">
            ${files.map(f => `<button type="button" class="tk-file" data-fid="${f.id}"
              data-img="${/^image\//.test(f.mime || '') ? 1 : 0}"
              style="display:flex;align-items:center;gap:9px;padding:9px 12px;border:1px solid #E2E8F0;
              background:#fff;border-radius:10px;cursor:pointer;font-family:inherit;text-align:left;max-width:100%;">
              <span style="font-size:18px;flex:0 0 auto;">${/^image\//.test(f.mime || '') ? '🖼️' : '📎'}</span>
              <span style="min-width:0;">
                <span style="display:block;font-size:13.5px;font-weight:600;color:#0F172A;
                  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;">${esc(f.original_name)}</span>
                <span style="display:block;font-size:11.5px;color:#94A3B8;">${tkSize(f.size_bytes)}</span>
              </span></button>`).join('')}
          </div>
          <div id="tk-preview" style="margin-top:12px;"></div>
        </div>` : ''}

        <div style="margin:20px 0 0;">
          <div style="font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
            color:#94A3B8;margin-bottom:10px;">History</div>
          <div style="border-left:2px solid #E2E8F0;padding-left:16px;margin-left:5px;">

            <div style="position:relative;padding-bottom:16px;">
              <span style="position:absolute;left:-23px;top:3px;width:10px;height:10px;border-radius:50%;
                background:#1F6080;border:2px solid #fff;box-shadow:0 0 0 2px #E2E8F0;"></span>
              <div style="font-size:13px;color:#475569;">
                <strong style="color:#0F172A;">${esc(t.raised_by_name || 'Someone')}</strong> raised this
                · <span style="color:#94A3B8;">${esc(fmtStamp(t.created_at))}</span></div>
            </div>

            ${thread.map(item => item.kind === 'msg' ? `
              <div style="position:relative;padding-bottom:16px;">
                <span style="position:absolute;left:-23px;top:3px;width:10px;height:10px;border-radius:50%;
                  background:${item.d.is_staff ? '#159FB4' : '#CBD5E1'};border:2px solid #fff;
                  box-shadow:0 0 0 2px #E2E8F0;"></span>
                <div style="font-size:13px;color:#475569;margin-bottom:5px;">
                  <strong style="color:#0F172A;">${esc(item.d.author_name || 'Someone')}</strong>
                  ${item.d.is_staff ? '<span style="font-size:10.5px;font-weight:800;letter-spacing:.3px;color:#0E7490;background:#CFFAFE;padding:2px 6px;border-radius:5px;margin-left:4px;">SUPPORT</span>' : ''}
                  replied · <span style="color:#94A3B8;">${esc(fmtStamp(item.d.created_at))}</span></div>
                <div style="background:${item.d.is_staff ? '#F0FDFA' : '#F8FAFC'};
                  border:1px solid ${item.d.is_staff ? '#CCFBF1' : '#E2E8F0'};padding:11px 13px;
                  border-radius:9px;font-size:14px;line-height:1.55;color:#1E293B;
                  white-space:pre-wrap;word-break:break-word;">${esc(item.d.body)}</div>
              </div>` : `
              <div style="position:relative;padding-bottom:16px;">
                <span style="position:absolute;left:-22px;top:5px;width:8px;height:8px;border-radius:50%;
                  background:#CBD5E1;border:2px solid #fff;box-shadow:0 0 0 2px #E2E8F0;"></span>
                <div style="font-size:12.5px;color:#64748B;">${evtLine(item.d)}
                  ${item.d.actor_name ? ' by ' + esc(item.d.actor_name) : ''}
                  · <span style="color:#94A3B8;">${esc(fmtStamp(item.d.created_at))}</span></div>
                ${item.d.note ? `<div style="margin-top:5px;font-size:13px;color:#475569;
                  white-space:pre-wrap;border-left:2px solid #E2E8F0;padding-left:10px;">${esc(item.d.note)}</div>` : ''}
              </div>`).join('')}

          </div>
        </div>
      </div>

      <div style="flex:0 0 auto;border-top:1px solid #E2E8F0;padding:16px 24px;background:#FCFDFE;">
        <div id="tk-resolve-box" style="display:none;margin-bottom:12px;">
          <label style="display:block;font-size:13px;font-weight:700;margin-bottom:5px;color:#0F172A;">
            What fixed it?</label>
          <div style="font-size:12.5px;color:#64748B;margin-bottom:6px;">
            The next person to hit this reads your answer. One or two lines is plenty.</div>
          <textarea id="tk-resolution" rows="3" placeholder="e.g. The room\u2019s photo permission was set to staff-only. Switched it to parents + staff."
            style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;
            font-size:14px;box-sizing:border-box;"></textarea>
        </div>

        <div id="tk-reply-box" ${done ? 'style="display:none;"' : ''}>
          <textarea id="tk-reply" rows="2" placeholder="Write a reply…"
            style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;
            font-size:14px;box-sizing:border-box;"></textarea>
        </div>

        <div id="tk-err" style="font-size:13px;color:#B91C1C;min-height:0;margin-top:6px;"></div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;
          flex-wrap:wrap;">
          <div>${done
            ? '<button id="tk-reopen" class="kt-btn kt-btn-secondary" type="button">Reopen</button>'
            : '<button id="tk-resolve" class="kt-btn kt-btn-success" type="button">Mark resolved</button>'}</div>
          <div style="display:flex;gap:8px;">
            <button id="tk-close" class="kt-btn kt-btn-secondary" type="button">Close</button>
            ${done ? '' : '<button id="tk-send" class="kt-btn kt-btn-primary" type="button">Send reply</button>'}
          </div>
        </div>
      </div>
    </div>`;

    document.body.appendChild(m);

    const err = m.querySelector('#tk-err');
    const say = (t2) => { err.textContent = t2 || ''; };

    m.querySelector('#tk-x').onclick = () => m.remove();
    m.querySelector('#tk-close').onclick = () => m.remove();
    m.addEventListener('click', (e) => { if (e.target === m) { m.remove(); } });

    // Attachments open in place. Images are shown; anything else opens in a tab.
    m.querySelectorAll('.tk-file').forEach(btn => {
      btn.onclick = async () => {
        const prev = m.querySelector('#tk-preview');
        prev.innerHTML = '<div style="font-size:13px;color:#64748B;">Opening…</div>';
        try {
          const url = await tkFileBlob(id, btn.dataset.fid);
          if (btn.dataset.img === '1') {
            prev.innerHTML = '<img alt="" style="max-width:100%;border-radius:10px;'
              + 'border:1px solid #E2E8F0;">';
            prev.firstChild.src = url;
          } else {
            window.open(url, '_blank');
            prev.innerHTML = '';
          }
        } catch (e2) {
          prev.innerHTML = '<div style="font-size:13px;color:#B91C1C;">Could not open that file.</div>';
        }
      };
    });

    const sendBtn = m.querySelector('#tk-send');
    if (sendBtn) {
      sendBtn.onclick = async () => {
        const body = m.querySelector('#tk-reply').value.trim();
        if (!body) { say('Write something first.'); return; }
        sendBtn.disabled = true; say('');
        try {
          await Api.post(`/tickets/${id}/reply`, { body });
          m.remove();
          openTicket(id);
        } catch (e2) {
          sendBtn.disabled = false; say((e2 && e2.message) || 'Could not send that reply.');
        }
      };
    }

    /* Resolving takes two taps on purpose: the first asks what fixed it. A ticket
       closed with no explanation is where the next person's search ends. */
    const resolveBtn = m.querySelector('#tk-resolve');
    if (resolveBtn) {
      resolveBtn.onclick = async () => {
        const box = m.querySelector('#tk-resolve-box');
        const note = m.querySelector('#tk-resolution');
        if (box.style.display === 'none') {
          box.style.display = 'block';
          m.querySelector('#tk-reply-box').style.display = 'none';
          resolveBtn.textContent = 'Confirm resolved';
          note.focus();
          return;
        }
        resolveBtn.disabled = true; say('');
        try {
          await Api.patch(`/tickets/${id}/status`, { status: 'resolved', resolution: note.value.trim() });
          m.remove();
          refreshTickets();
        } catch (e2) {
          resolveBtn.disabled = false; say((e2 && e2.message) || 'Could not resolve that ticket.');
        }
      };
    }

    const reopenBtn = m.querySelector('#tk-reopen');
    if (reopenBtn) {
      reopenBtn.onclick = async () => {
        reopenBtn.disabled = true; say('');
        try {
          await Api.patch(`/tickets/${id}/status`, { status: 'open' });
          m.remove();
          openTicket(id);
        } catch (e2) {
          reopenBtn.disabled = false; say((e2 && e2.message) || 'Could not reopen that ticket.');
        }
      };
    }
  }
  async function renderPhotoTagging(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🪄 Auto-tag photos</h2>
        <p>Run AI classification on a photo to identify which children appear in it. Staff confirm or remove tags.</p>
      </div>
      <div class="kt-card">
        <label style="font-size:13px;font-weight:600;">Photo ID to classify</label>
        <div style="display:flex;gap:8px;margin-top:6px;">
          <input id="pt-id" type="number" placeholder="e.g. 12" style="flex:1;padding:11px;border:1px solid #E2E8F0;border-radius:8px;">
          <button id="pt-go" class="kt-btn kt-btn-primary">Classify</button>
        </div>
        <div id="pt-out" style="margin-top:18px;"></div>
      </div>
    </div>`;
    document.getElementById('pt-go').onclick = async () => {
      const pid = +document.getElementById('pt-id').value;
      const btn = document.getElementById('pt-go');
      btn.disabled = true; btn.textContent = 'AI running…';
      try {
        const r = await Api.post(`/photos/${pid}/classify`, {});
        const tags = await Api.get(`/photos/${pid}/tags`);
        document.getElementById('pt-out').innerHTML = `
          <div style="color:#15803D;font-weight:600;">✓ Tagged ${r.tagged} child(ren)</div>
          <table style="margin-top:14px;">
            <thead><tr><th>Child</th><th>Confidence</th><th>Source</th><th></th></tr></thead>
            <tbody>${(tags.data || []).map(t => `<tr>
              <td><strong>${esc(t.child_name)}</strong></td>
              <td>${t.confidence ? (parseFloat(t.confidence) * 100).toFixed(0) + '%' : '—'}</td>
              <td>${t.ai_tagged ? '🤖 AI' : '✋ Manual'}</td>
              <td>${t.confirmed ? '<span class="kt-pill kt-pill-success">Confirmed</span>' : `<button class="kt-btn kt-btn-primary" data-confirm="${t.photo_id}-${t.child_id}" style="font-size:12px;padding:6px 12px;">Confirm</button>`}</td>
            </tr>`).join('')}</tbody>
          </table>`;
        main.querySelectorAll('button[data-confirm]').forEach(b => b.onclick = async () => {
          const [pid, cid] = b.dataset.confirm.split('-').map(Number);
          await Api.post('/photos/tag-confirm', { photo_id: pid, child_id: cid, confirmed: true });
          b.outerHTML = '<span class="kt-pill kt-pill-success">Confirmed</span>';
        });
      } catch (e) { document.getElementById('pt-out').innerHTML = `<div style="color:#B91C1C;">${esc(e.message || 'Failed')}</div>`; }
      finally { btn.disabled = false; btn.textContent = 'Classify'; }
    };
  }

  window.KT = KT;
  window.KT.V22p59 = {
    renderDirectory, renderConferences, renderTripGps, renderAttendancePattern,
    renderReportCards, renderZones, renderTickets, renderPhotoTagging,
  };
})(window);
