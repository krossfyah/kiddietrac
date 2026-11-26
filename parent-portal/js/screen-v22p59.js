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
    const isStaff = (window.ktRoles ? window.ktRoles(u) : (u.roles || [])).some(r => ['educator', 'centre_director', 'agency_admin', 'platform_admin'].includes(r));
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
      const cid = kids.length === 1 ? kids[0].id : +(await window.KT.prompt({
        title: 'Which child is this booking for?',
        description: 'Enter the number beside their name: ' + kids.map(c => c.id + ': ' + c.first_name).join(', '),
        fields: [{ key: 'cid', label: 'Child number', type: 'number' }],
        okLabel: 'Book',
      }));
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

    /* A guardian's own children come from the PARENT route; the staff route is
       agency-wide and role-gated, and asking for it as a parent only ever returns 403.
       Keyed on the account's real roles — not a view-as preview — because it is the
       account the server authorises. (Anthony, 2026-09-08) */
    let _isGuardianOnly = false;
    try {
      var _u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var _r = (_u && _u.roles) || [];
      _isGuardianOnly = _r.indexOf('guardian') !== -1
        && !['educator', 'centre_director', 'agency_admin', 'platform_admin'].some(function (x) { return _r.indexOf(x) !== -1; });
    } catch (e) {}

    let ov;
    try {
      ov = await Api.get(_isGuardianOnly ? '/parent/attendance/weekly-overview' : '/attendance/weekly-overview');
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
    /* Today's column, in the AGENCY's timezone -- a parent may open this from another
       zone entirely and the device's idea of "today" can be a day out. Built from
       numeric parts on purpose: new Date('YYYY-MM-DD') parses as UTC and names the
       day before for anyone west of it. -1 simply means nothing is highlighted. */
    const _todayIdx = (function () {
      try {
        const p = String((window.KT && KT.agencyToday) ? KT.agencyToday() : '').split('-');
        if (p.length !== 3) return -1;
        return (new Date(+p[0], +p[1] - 1, +p[2]).getDay() + 6) % 7;   // JS 0=Sun -> DAYS 0=Mon
      } catch (e) { return -1; }
    })();

    /* PHONES DO NOT SHOW THIS AS A TABLE.
       kt-mobile-tables.js restacks every #appMain table into cards: thead is hidden and
       each <td> becomes a flex row "LABEL   control" with width:auto. That did two
       things to this grid. The seven day fields sized to their own content, so every one
       came out a different width; and the TODAY marker went with the hidden header.
       Stack the label above a full-width control so every day is exactly the same size,
       and mark today off the label, which is the only thing that survives into cards. */
    (function ensureApCss() {
      if (document.getElementById('ap-grid-css')) return;
      var st = document.createElement('style');
      st.id = 'ap-grid-css';
      st.textContent = [
        '#appMain table#ap-grid.kt-mcards td{display:block !important;width:100% !important;}',
        '#appMain table#ap-grid.kt-mcards td:not([data-label=""])::before{display:block;margin-bottom:5px;}',
        '#appMain table#ap-grid.kt-mcards td select{width:100% !important;min-width:0 !important;box-sizing:border-box;}',
        /* TODAY COULD NOT BE SEEN ON A PHONE (2026-11-06, Anthony: "the highlight for today is not highlighted
           properly or can be seen"). The pale #E8F4FA cell was lost on the white card and the card label rule
           kept its grey over the plain colour. Now: a stronger tint, a 2px navy frame and a bold navy label. */
        '#appMain table#ap-grid.kt-mcards td[data-label*="TODAY"]{background:#D6ECF6 !important;',
        'box-shadow:inset 0 0 0 2px #1F6080 !important;border:none !important;',
        '--kt-edge:#1F6080 !important;border-radius:9px !important;padding:7px 9px !important;}',
        '#appMain table#ap-grid.kt-mcards td[data-label*="TODAY"]::before{color:#0B4A63 !important;font-weight:800 !important;}',
        /* Beats [data-kt-pretty] table thead th, which is !important and was silently
           discarding today's header colour on every render. */
        '#appMain table#ap-grid thead th.ap-today{background:#1F6080 !important;}',
        '#appMain table#ap-grid thead th.ap-today,',
        '#appMain table#ap-grid thead th.ap-today div{color:#FFFFFF !important;}'
      ].join('');
      document.head.appendChild(st);
    })();
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
      +     '<div style="flex:0 1 280px;min-width:0;"><label style="font-size:11.5px;font-weight:800;color:#64748B;">Search</label>'
      +       '<input id="ap-q" type="search" placeholder="Name, centre or room" autocomplete="off" '
      +       'style="width:100%;height:32px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;box-sizing:border-box;"></div>'
      +     '<div style="flex:0 1 190px;min-width:0;"><label style="font-size:11.5px;font-weight:800;color:#64748B;">Show</label>'
      +       '<select id="ap-filter" style="width:100%;height:32px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;padding:0 8px;">'
      +         '<option value="enrolled">Enrolled only</option>'
      +         '<option value="missing">Missing a pattern</option>'
      +         '<option value="all">Everyone</option>'
      +       '</select></div>'
      +     '<div style="flex:0 1 180px;min-width:0;"><label style="font-size:11.5px;font-weight:800;color:#64748B;">Changes apply from</label>'
      +       '<input id="ap-from" type="date" value="' + (((window.KT && KT.agencyToday) ? KT.agencyToday() : new Date().toISOString().slice(0, 10))) + '" '
      +       'style="width:100%;height:32px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;padding:0 8px;box-sizing:border-box;"></div>'
      +     (_isGuardianOnly
            /* data-kt-iconized: kt-icon-buttons.js rewrote this to a bare "✏️". That is
               right for a row action sitting beside two siblings that explain it; it is
               wrong for the only control telling a parent this screen can be edited at
               all. The attribute is that engine's documented opt-out. */
            ? '<button id="ap-edit" data-kt-iconized="1" style="height:32px;padding:0 16px;background:#fff;color:#1F6080;'
              + 'border:1px solid #CBD5E1;border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;">'
              + '✏️ Edit days</button>'
            : '')
      +     '<button id="ap-save" disabled style="height:32px;padding:0 16px;background:#1F6080;color:#fff;border:0;'
      +       'border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;opacity:.45;">Save changes</button>'
      +     '<span id="ap-msg" style="font-size:12.5px;font-weight:700;"></span>'
      +   '</div>'
      // Bulk fill: most children share one pattern, and setting it 77 times by
      // hand was the single biggest cost of the old screen.
      +   '<div id="ap-bulkrow" style="margin-top:12px;padding-top:12px;border-top:1px solid #EDF2F7;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
      +     '<span style="font-size:11.5px;font-weight:800;color:#64748B;">Apply to everyone shown:</span>'
      +     '<button class="ap-bulk" data-p="mf-full" style="' + BTN + '">Mon–Fri, full day</button>'
      +     '<button class="ap-bulk" data-p="mf-am" style="' + BTN + '">Mon–Fri, mornings</button>'
      +     '<button class="ap-bulk" data-p="mf-pm" style="' + BTN + '">Mon–Fri, afternoons</button>'
      +     '<button class="ap-bulk" data-p="mf-bna" style="' + BTN + '">Mon–Fri, before &amp; after</button>'
      +     '<button class="ap-bulk" data-p="clear" style="' + BTN + '">Clear all days</button>'
      +   '</div>'
      + '</div>'
      + '<div id="ap-tabs" style="display:none;margin-top:16px;gap:8px;flex-wrap:wrap;"></div>'
      + '<div class="kt-card" style="margin-top:16px;padding:0;overflow-x:auto;">'
      /* table-layout:fixed -- the day columns were sized by their own content, so a
         column holding "Before" came out wider than one holding "AM" and the week
         read as ragged. Fixed layout gives the seven days one width. */
      /* data-kt-no-filter: this screen searches and filters the grid itself (ap-q,
         ap-filter), and "Apply to everyone shown" means the rows THAT search shows. The
         global toolbar added a second search box and split the grid into pages, so
         "shown" no longer meant what it said. (Anthony, 2026-09-28: pager sweep) */
      +   '<table id="ap-grid" data-kt-no-filter="1" style=""width:100%;border-collapse:collapse;font-size:13px;min-width:900px;table-layout:fixed;"></table>'
      + '</div>'
      + '<div id="ap-empty" style="display:none;padding:34px;text-align:center;color:#64748B;">No children match.</div>'
      + '</div>';

    const q = document.getElementById('ap-q');
    const filter = document.getElementById('ap-filter');
    const grid = document.getElementById('ap-grid');
    const saveBtn = document.getElementById('ap-save');

    /* PARENTS OPEN READ-ONLY. A parent came here to look; the rotation dropdowns, the
       bulk buttons and Save are the office's tools, and a mis-tap here changes the days a
       child is expected. Staff keep the live grid — editing these is their job and a
       click per child would be a tax on it. (Anthony, 2026-09-08) */
    /* A parent is not searching a roster -- they have one or two children and the tabs
       name them. Two controls sized for an agency list sitting beside one date field is
       also where the mismatched field widths came from. */
    if (_isGuardianOnly) {
      ['ap-q', 'ap-filter'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentElement) { el.parentElement.style.display = 'none'; }
      });
      /* The hidden filter still has a value, and its default is "Enrolled only" -- which
         would have shown a waitlisted or withdrawn child's parent an empty grid. */
      var _f = document.getElementById('ap-filter');
      if (_f) { _f.value = 'all'; }
      var _fr = document.getElementById('ap-from');
      if (_fr && _fr.parentElement) { _fr.parentElement.style.flex = '0 1 200px'; }
    }

    let _apEditing = !_isGuardianOnly;
    function applyApMode() {
      /* Leave background and border ALONE. cell() colours each dropdown by the rotation
         it holds, and that colour is the data on this grid. This ran immediately after
         every draw(), so it blanked all seven days to white or transparent the instant
         they were painted -- which is most of "the fields don't look right". Read-only
         now shows the same colours, just without the caret or the pointer. */
      Array.prototype.forEach.call(document.querySelectorAll('#ap-grid select[data-child]'), function (sel) {
        sel.disabled = !_apEditing;
        sel.style.cursor = _apEditing ? 'pointer' : 'default';
        // A disabled <select> is greyed by the browser; keep the rotation's own ink.
        try { sel.style.setProperty('-webkit-text-fill-color', sel.style.color || ''); } catch (e) {}
        sel.style.appearance = _apEditing ? '' : 'none';
        sel.style.webkitAppearance = _apEditing ? '' : 'none';
      });
      /* Hide the whole row, not just the buttons. Hiding the buttons alone left
         "Apply to everyone shown:" hanging under a divider with nothing beneath it. */
      var bulkRow = document.getElementById('ap-bulkrow');
      if (bulkRow) { bulkRow.style.display = _apEditing ? 'flex' : 'none'; }
      Array.prototype.forEach.call(document.querySelectorAll('.ap-bulk'), function (b) {
        b.style.display = _apEditing ? '' : 'none';
      });
      /* "Changes apply from" is a question about a change being made. There isn't one
         until a parent presses Edit, so it appears with the rest of the tools. */
      var fromEl = document.getElementById('ap-from');
      if (_isGuardianOnly && fromEl && fromEl.parentElement) {
        fromEl.parentElement.style.display = _apEditing ? '' : 'none';
      }
      if (saveBtn) { saveBtn.style.display = _apEditing ? '' : 'none'; }
      var eb = document.getElementById('ap-edit');
      if (eb) { eb.textContent = _apEditing ? '✓ Done' : '✏️ Edit days'; }
    }
    var _apEb = document.getElementById('ap-edit');
    if (_apEb) { _apEb.addEventListener('click', function () { _apEditing = !_apEditing; applyApMode(); }); }

    /* ONE CHILD AT A TIME FOR PARENTS.
       A parent with two children got two rows and a column of totals counting their own
       family -- this grid's shape is built for an office reading a roster, not for a
       family reading itself. A tab per child gives each one the full width and makes
       "which child is this" unmissable. Staff keep every row: comparing children across
       a week IS the job on that side. (Anthony, 2026-09-08) */
    var _apChild = (_isGuardianOnly && rows.length > 1) ? rows[0].id : null;
    function paintChildTabs() {
      Array.prototype.forEach.call(document.querySelectorAll('.ap-tab'), function (b) {
        var on = String(b.getAttribute('data-child-tab')) === String(_apChild);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.style.cssText = 'height:34px;padding:0 16px;border-radius:999px;font-weight:800;'
          + 'font-size:13px;cursor:pointer;transition:background .12s,border-color .12s;'
          + 'border:1px solid ' + (on ? '#1F6080' : '#CBD5E1') + ';'
          + 'background:' + (on ? '#1F6080' : '#fff') + ';color:' + (on ? '#fff' : '#334155') + ';';
      });
    }
    if (_apChild !== null) {
      var _tabHost = document.getElementById('ap-tabs');
      if (_tabHost) {
        _tabHost.style.display = 'flex';
        _tabHost.setAttribute('role', 'tablist');
        rows.forEach(function (c) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'ap-tab';
          b.setAttribute('role', 'tab');
          b.setAttribute('data-child-tab', c.id);
          b.textContent = nameOf(c);
          b.addEventListener('click', function () { _apChild = c.id; paintChildTabs(); draw(); });
          _tabHost.appendChild(b);
        });
        paintChildTabs();
      }
    }
    const msg = document.getElementById('ap-msg');

    function visible() {
      const term = (q.value || '').trim().toLowerCase();
      const mode = filter.value;
      return rows.filter((c) => {
        if (_apChild !== null && c.id !== _apChild) return false;
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

    function cell(c, d, i) {
      const cur = draft[c.id][d] || '';
      // A day with nothing set is dimmed and dashed rather than left white. White is the
      // table's own colour, so a gap in a child's week disappeared into the background —
      // and a gap is exactly what you are scanning this grid for.
      const bg = cur ? tintFor(cur) : '#F8FAFC';
      const fg = cur ? inkFor(cur) : '#CBD5E1';
      const border = cur ? '1px solid transparent' : '1px dashed #E2E8F0';
      // Today's column is banded the whole way down, so the header marker is not the
      // only thing tying a row's cell to the day it belongs to. Skipped on an unsaved
      // row, where the amber "you have changes here" is the more urgent signal.
      /* Was #F2F9FC -- two shades off white, and invisible in practice. Today's column
         is now tinted properly and fenced by the same accent as its header, so the eye
         lands on it. An unsaved row keeps its amber; it still gets the fence. */
      const todayBand = (i === _todayIdx)
        ? 'background:' + (dirty[c.id] ? '#FBF0D9' : '#D6ECF6')
          + ';border-left:2px solid #1F6080;border-right:2px solid #1F6080;'
        : '';
      let html = '<td style="padding:4px;text-align:center;border-bottom:1px solid #F1F5F9;' + todayBand + '">'
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
        + '<th style="width:250px;text-align:left;padding:10px 12px;position:sticky;left:0;'
        + 'background:#EEF3F8;z-index:2;">Child</th>';
      DAY_SHORT.forEach((s, i) => {
        const isToday = i === _todayIdx;
        /* The day and its count were one line apart by a single pixel and read as one
           smudged word. They are now two lines with real space, and the count is a pill
           so it reads as a figure rather than as part of the day's name. The pill row
           keeps its height when empty, so every header sits on the same baseline. */
        /* "(TODAY)", inline, with a real space in front of it. The marker used to be a
           pill on its own line, which read as two words jammed together on desktop --
           and far worse on a phone, where kt-mobile-tables builds each field's label
           from this cell's textContent: two adjacent divs concatenate with no
           whitespace at all, so the label came out "TUETODAY". One string, spaced and
           bracketed, is right in both places. */
        const dayLabel = s + (isToday ? ' (TODAY)' : '');
        html += '<th' + (isToday ? ' class="ap-today"' : '')
          + ' style="padding:9px 6px 11px;text-align:center;vertical-align:top;'
          + 'background:' + (isToday ? '#1F6080' : '#EEF3F8') + ';'
          + (isToday ? 'border-left:2px solid #1F6080;border-right:2px solid #1F6080;' : '') + '">'
          + '<div style="font-weight:800;font-size:12.5px;color:' + (isToday ? '#FFFFFF' : '#0F172A') + ';">'
          +   esc(dayLabel) + '</div>'
          + '<div style="margin-top:7px;display:flex;justify-content:center;gap:5px;min-height:17px;">'
          // A parent's own child counted per day is not a figure anyone needs.
          +   (_isGuardianOnly
                ? ''
                // Leading space so this does not run into the day name in a card label.
                : '<span style="background:#fff;color:#64748B;border:1px solid #DDE6EE;border-radius:999px;'
                  + 'padding:2px 8px;font-size:10px;font-weight:700;line-height:1.2;"> '
                  + totals[i] + ' in</span>')
          + '</div></th>';
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
        DAYS.forEach((d, i) => { html += cell(c, d, i); });
        html += '</tr>';
      });
      grid.innerHTML = html + '</tbody>';
      /* AFTER the rows exist. draw() rebuilds them from a string, so the dropdowns are
         new elements every time and anything set on the old ones is gone. */
      try { applyApMode(); } catch (e) {}

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
      const from = document.getElementById('ap-from').value || ((window.KT && KT.agencyToday) ? KT.agencyToday() : new Date().toISOString().slice(0, 10));
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
      /* Back to read-only for a parent once it is all saved — the save is the completion.
         A partial save stays open so they can see and retry what did not go through. */
      if (_isGuardianOnly && !failed) { _apEditing = false; }
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

  /* ═══════════════════ REPORT CARDS — staff screen (rebuilt 2026-11-26) ═══════════════════
     Two sub-tabs (Anthony: "generate report card using AI this needs to be in its own subtab and not carried across
     to all subtabs"):
       📚 Report cards      parents' requests, cards awaiting approval, the All report cards list (status chips,
                            Archived for agency admins) and the card itself, opened below the list
       ✨ Generate with AI  the child + term picker and the draft it makes; only for the roles that may generate
     Switching tabs re-draws the tab body only. Deep link: #report-cards?tab=generate[&child=ID&term=...].

     Every list row has the standard ⋮ (kt-row-actions collapses the last cell's buttons; phones get it on the card):
     View, Edit (a SENT card is edited as a new version, approved again), Download PDF, Print, Email to family,
     Approve / Send back, Delete draft. Only what this person may do is offered; the server re-checks each one. */
  const RC = {
    tab: 'cards', kids: null, kidsErr: 0, reqs: null,
    rows: [], approver: false, admin: false, archived: null, archivedCount: 0,
    f: { st: '', q: '', term: '', by: '', centre: '' }, prefill: null,
  };
  const RC_GEN_ROLES = ['educator', 'centre_director', 'agency_admin', 'platform_admin'];
  const rcRoles = () => { try { return window.ktRoles ? window.ktRoles() : ((JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []); } catch (e) { return []; } };
  const rcCanGenerate = () => rcRoles().some(r => RC_GEN_ROLES.indexOf(r) !== -1);
  const rcAiOn = () => { try { const u = (KT.Auth && KT.Auth.user && KT.Auth.user()) || {}; return u.ai_bundle === true; } catch (e) { return false; } };
  const rcToast = (m, t) => { if (window.KT && KT.toast) KT.toast(m, t || 'info'); };
  const rcSaid = (e, fb) => (e && e.message) || fb;
  const rcQuery = () => {
    const q = {}; const s = String(location.hash || '').split('?')[1] || '';
    s.split('&').forEach(p => { const i = p.indexOf('='); if (i > 0) { try { q[p.slice(0, i)] = decodeURIComponent(p.slice(i + 1).replace(/\+/g, ' ')); } catch (e) {} } });
    return q;
  };
  const rcSetHash = () => {
    const h = '#report-cards' + (RC.tab === 'generate' ? '?tab=generate' : '');
    try { if (location.hash !== h) history.replaceState(history.state, '', location.pathname + location.search + h); } catch (e) {}
  };
  const RC_ST = {
    draft: ['Draft', '#F1F5F9', '#334155'], reviewed: ['Draft', '#F1F5F9', '#334155'],
    submitted: ['Awaiting approval', '#FEF3C7', '#92400E'], sent: ['Sent', '#DCFCE7', '#166534'],
  };
  const RC_ARCH = { child_withdrawn: 'Child left', centre_closed: 'Centre closed' };
  const rcGroup = (s) => (s === 'submitted' || s === 'sent') ? s : 'draft';
  const rcChip = (label, bg, fg) => `<span class="rc-pill" style="background:${bg};color:${fg};border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;white-space:nowrap;">${esc(label)}</span>`;
  const rcPill = (s, replaced) => replaced ? rcChip('Replaced', '#E2E8F0', '#475569') : rcChip(...(RC_ST[s] || RC_ST.draft));
  const rcArchPill = (k) => rcChip(RC_ARCH[k] || RC_ARCH.child_withdrawn, '#E2E8F0', '#475569');
  const rcVer = (v) => (+v > 1) ? ` <span style="font-size:11.5px;color:#64748B;font-weight:700;">v${+v}</span>` : '';
  const rcDay = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || '')); return m ? new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''; };
  const rcPdfUrl = (id) => (KT.API_BASE || '') + '/report-cards/' + id + '/pdf';
  const rcName = (c) => (c.child || 'report-card') + ' — ' + (c.term || '');

  /* Narrative boxes: one per area of the agency's learning framework; older cards without `sections` are HDLH. */
  const rcLegacy = (card) => ['belonging', 'wellbeing', 'engagement', 'expression'].map(k => ({
    key: k, label: k === 'wellbeing' ? 'Well-being' : k.charAt(0).toUpperCase() + k.slice(1), text: (card && card['narrative_' + k]) || '' }));
  const rcBoxes = (sections) => (sections || []).map(x => `
        <h4 style="margin:16px 0 6px;color:#1F6080;">${esc(x.label)}</h4>
        <textarea data-rc-area="${esc(x.key)}" rows="5" style="width:100%;min-height:110px;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(x.text || '')}</textarea>`).join('');
  const rcCollect = () => { const out = {}; document.querySelectorAll('#rc-out [data-rc-area]').forEach(t => { out[t.getAttribute('data-rc-area')] = t.value; }); return out; };
  const rcFwNote = (fw) => fw && fw.name ? `<div style="font-size:12.5px;color:#64748B;margin-top:6px;">Framework: <strong>${esc(fw.name)}</strong></div>` : '';

  /* ── files ── */
  function rcDownload(c) {
    if (!(window.KT && KT.fetchFileBlob)) return;
    const name = ('report-card-' + (c.child || '') + '-' + (c.term || '')).replace(/[^A-Za-z0-9]+/g, '-').replace(/-+$/, '') + '.pdf';
    const p = KT.fetchFileBlob(rcPdfUrl(c.id));
    const nativeApp = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    if (nativeApp && KT.openFile) { KT.openFile(p, { title: rcName(c), filename: name, mime: 'application/pdf' }); return; }
    p.then(b => {
      const u = URL.createObjectURL(new Blob([b], { type: 'application/pdf' }));
      const a = document.createElement('a'); a.href = u; a.download = name; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(u), 4000);
    }, e => rcToast(rcSaid(e, 'Could not download the PDF.'), 'error'));
  }
  function rcPrint(c) {
    if (!(window.KT && KT.printDoc && KT.fetchFileBlob)) return;
    KT.fetchFileBlob(rcPdfUrl(c.id)).then(b => KT.printDoc({ blob: new Blob([b], { type: 'application/pdf' }) }, { title: rcName(c) }),
      e => rcToast(rcSaid(e, 'Could not load the PDF to print.'), 'error'));
  }

  /* ── actions (each one re-checked by the server) ── */
  async function rcApprove(id, save) {
    try { if (save) await save(); } catch (e) { rcToast(rcSaid(e, 'Could not save.'), 'error'); return; }
    const sig = await KT.signaturePad({ title: 'Director / admin sign-off', subtitle: 'Your signature approves this report card and sends it to the family.', okLabel: 'Approve & send' });
    if (!sig) return;
    try { await Api.post('/report-cards/' + id + '/approve', { admin_signature: sig }); rcToast('Approved & sent to the family. ✓', 'success'); rcAfter(id); return true; }
    catch (e) { rcToast(rcSaid(e, 'Could not approve.'), 'error'); return false; }
  }
  async function rcSubmit(id, save) {
    try { if (save) await save(); } catch (e) { rcToast(rcSaid(e, 'Could not save.'), 'error'); return; }
    const sig = await KT.signaturePad({ title: 'Educator signature', subtitle: 'Sign to submit this report card for review. An agency admin approves it before it reaches the family.', okLabel: 'Sign & submit' });
    if (!sig) return;
    try { await Api.post('/report-cards/' + id + '/submit', { educator_signature: sig }); rcToast('Submitted for approval — your director/admin was notified. ✓', 'success'); rcAfter(id); return true; }
    catch (e) { rcToast(rcSaid(e, 'Could not submit.'), 'error'); return false; }
  }
  async function rcSendBack(id) {
    const note = await window.KT.prompt({ title: 'Send back to the educator', description: 'What needs changing? The educator will see this note.',
      fields: [{ key: 'note', label: 'Note', type: 'text' }], okLabel: 'Send back' });
    if (note === null || note === undefined) return;
    const text = typeof note === 'object' ? (note.note || '') : note;
    try { await Api.post('/report-cards/' + id + '/reject', { note: text }); rcToast('Sent back to the educator.', 'success'); rcAfter(id); rcCloseCard(id); return true; }
    catch (e) { rcToast(rcSaid(e, 'Could not send back.'), 'error'); return false; }
  }
  async function rcRevise(id) {
    try {
      const r = await Api.post('/report-cards/' + id + '/revise', {});
      rcToast(r && r.existing ? 'A new version was already started. It is open now.' : 'New version started. Make your changes, then submit or approve it again. The sent one stays until then.', 'success');
      await rcOpen(r.id, { edit: true }); rcReloadList();
    } catch (e) { rcToast(rcSaid(e, 'Could not start a new version.'), 'error'); }
  }
  async function rcDelete(c) {
    const ok = await KT.confirm({ title: 'Delete this draft?', description: `The ${c.term || ''} draft for ${c.child || 'this child'} will be deleted. This cannot be undone.`, okLabel: 'Delete draft', tone: 'danger' });
    if (!ok) return;
    try {
      await Api.delete('/report-cards/' + c.id); rcToast('Draft deleted.', 'success');
      rcCloseCard(c.id);
      rcReloadList(); return true;
    } catch (e) { rcToast(rcSaid(e, 'Could not delete the draft.'), 'error'); return false; }
  }
  function rcAfter() { rcReloadList(); }

  /* ── email to the family: nobody pre-ticked, an optional note, a preview, then a secure 14-day link each ── */
  async function rcEmail(c) {
    let d; try { d = await Api.get('/report-cards/' + c.id + '/recipients'); } catch (e) { rcToast(rcSaid(e, 'Could not load the family.'), 'error'); return; }
    const gs = (d && d.guardians) || [];
    const body = document.createElement('div');
    body.style.cssText = 'display:flex;flex-direction:column;gap:10px;';
    body.innerHTML =
      (d.child_left ? '<div style="border:1px solid #FDE68A;background:#FFFBEB;color:#92400E;border-radius:10px;padding:8px 12px;font-size:13px;">This child has left. Guardians still on file can still receive the report card.</div>' : '') +
      (d.test_agency ? '<div style="border:1px solid #BAE6FD;background:#F0F9FF;color:#075985;border-radius:10px;padding:8px 12px;font-size:13px;">This is a test agency, so nothing is sent. You can still preview the email.</div>' : '') +
      '<div style="font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Send to</div>' +
      (gs.length ? gs.map(g => `<label style="display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border:1px solid #E2E8F0;border-radius:10px;cursor:pointer;">
          <input type="checkbox" data-rc-to="${g.id}" style="width:17px;height:17px;margin-top:2px;accent-color:#2F8C86;">
          <span><b>${esc(g.name)}</b>${g.relationship ? ' <span style="color:#64748B;font-size:12px;">· ' + esc(g.relationship) + '</span>' : ''}<br><span style="color:#475569;font-size:12.5px;">${esc(g.email)}</span></span></label>`).join('')
        : '<div style="color:#64748B;font-size:13px;">No guardian on file has an email address.</div>') +
      '<label style="font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-top:4px;">Message (optional)</label>' +
      '<textarea data-rc-msg rows="3" maxlength="2000" placeholder="Add a short note for the family" style="width:100%;min-height:80px;padding:9px;border:1px solid #CBD5E1;border-radius:8px;font-family:inherit;font-size:14px;"></textarea>' +
      '<div><button type="button" class="kt-btn" data-rc-preview data-kt-iconized="1">👁️ Preview the email</button></div>' +
      '<div data-rc-prev style="display:none;border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;"></div>' +
      '<div style="font-size:12px;color:#64748B;">Each person ticked gets their own secure link to the PDF. It works for 14 days.</div>' +
      '<div data-rc-msgline style="font-size:12.5px;font-weight:700;color:#B91C1C;min-height:16px;"></div>';
    const picked = () => Array.from(body.querySelectorAll('[data-rc-to]')).filter(x => x.checked).map(x => +x.getAttribute('data-rc-to'));
    const msg = () => body.querySelector('[data-rc-msg]').value;
    body.querySelector('[data-rc-preview]').onclick = async () => {
      const box = body.querySelector('[data-rc-prev]');
      try {
        const p = await Api.post('/report-cards/' + c.id + '/email/preview', { to: picked(), message: msg() });
        box.style.display = 'block';
        box.innerHTML = `<div style="padding:8px 12px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;font-size:13px;"><b>Subject:</b> ${esc(p.subject || '')}</div>`;
        const fr = document.createElement('iframe');
        fr.setAttribute('sandbox', ''); fr.style.cssText = 'width:100%;height:380px;border:0;background:#fff;display:block;';
        fr.srcdoc = p.html || ''; box.appendChild(fr);
      } catch (e) { rcToast(rcSaid(e, 'Could not build the preview.'), 'error'); }
    };
    KT.Shell.Modal.open({
      title: '✉️ Email ' + (d.child || 'report card') + "'s report card",
      body: body,
      actions: [
        { label: 'Cancel' },
        { label: d.test_agency ? 'Send (test: nothing goes out)' : 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
          const line = body.querySelector('[data-rc-msgline]'); line.textContent = '';
          const to = picked();
          if (!to.length) { line.textContent = 'Tick at least one person to send it to.'; return false; }
          return Api.post('/report-cards/' + c.id + '/email', { to: to, message: msg() }).then(r => {
            if (r && r.blocked) { rcToast(r.message || 'Test agency: nothing was sent.', 'info'); return; }
            rcToast('Emailed to ' + (r && r.sent) + (r && r.sent === 1 ? ' person' : ' people') + '.', 'success');
          }, e => { line.textContent = rcSaid(e, 'Could not send.'); return false; });
        } },
      ],
    });
  }

  /* ── the card: View (read-only) or Edit, in the house popup (2026-11-26, Anthony: "the kebab for the report cards
     when viewing or editing it isn't a popup - we need to keep thing consistent throughout the portal").
     KT.Shell.Modal gives the one header (gradient + teal line, ✕ top-right), the pinned header with ONE scroll
     region, Escape, and the phone sheet. The card's actions sit in its footer. The list underneath is never
     re-drawn by opening or closing it, so its filter and scroll stay where they were. ── */
  let RC_MODAL = null;
  /* The open card is in the address (replaceState: no hashchange, so the shell's navigate-away closer and the
     dialog stamp both keep treating it as the same screen). */
  function rcCardHash(id, edit) {
    if (RC.tab !== 'cards' || !/^#report-cards(\?|$)/.test(location.hash)) return;
    const h = '#report-cards' + (id ? '?card=' + id + (edit ? '&edit=1' : '') : '');
    try { if (location.hash !== h) history.replaceState(history.state, '', location.pathname + location.search + h); } catch (e) {}
  }
  function rcCloseCard(id) {
    if (!RC_MODAL || (id && +RC_MODAL.id !== +id)) return;
    const m = RC_MODAL; RC_MODAL = null;
    try { m.h.close(); } catch (e) {}
    rcCardHash(0);
  }
  /* VIEW = THE PDF (2026-11-26, Anthony: "when you hit view on the report card it should pop up with the pdf view").
     The same /pdf bytes Download gives (so the preview is what parents get; a draft carries its DRAFT watermark),
     drawn by the portal's viewer (KT.renderDocumentInto: a frame on desktop, pdf.js pages on phones and in the app)
     inside the house popup. The footer holds only what this person may do - the server's `can` decides. */
  function rcViewPdf(c, editableNow) {
    const can = c.can || {}, st = c.status || 'draft', replaced = !!c.replaced;
    const body = document.createElement('div');
    body.id = 'rc-out'; body.setAttribute('data-card', c.id); body.setAttribute('data-child', c.child || '');
    const meta = [c.made_by ? 'Made by ' + c.made_by : '', c.centre || '', c.room || '', c.ref ? 'Ref ' + c.ref : ''].filter(Boolean).map(esc).join(' · ');
    body.innerHTML =
      `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">${rcPill(st, replaced)}${c.archived ? rcArchPill(c.archived) : ''}<span style="font-size:12.5px;color:#64748B;">${meta}</span></div>` +
      (c.review_note && (st === 'draft' || st === 'reviewed') ? `<div style="margin:8px 0 0;border:1px solid #FECACA;background:#FEF2F2;color:#991B1B;border-radius:10px;padding:8px 12px;font-size:13px;"><b>Sent back:</b> ${esc(c.review_note)}</div>` : '') +
      (st === 'submitted' && !can.approver ? '<div style="margin-top:8px;font-size:13px;color:#92400E;">Waiting for an agency admin to approve it.</div>' : '') +
      (replaced ? '<div style="margin-top:8px;font-size:13px;color:#475569;">A newer version replaced this one. It is kept here, with its PDF, for the record.</div>' : '') +
      '<div class="rc-pdf-host" style="margin-top:10px;display:flex;flex-direction:column;height:calc(100vh - 290px);min-height:420px;background:#E2E8F0;border-radius:10px;padding:0;"></div>' +
      '<details id="rc-o-hist" style="margin-top:12px;"><summary style="cursor:pointer;font-weight:700;font-size:13px;color:#1F6080;">History &amp; versions</summary><div data-rc-hist style="margin-top:8px;font-size:13px;color:#475569;">Loading…</div></details>';
    const A = [];
    const stay = (fn) => async () => { await fn(); return false; };
    const done = (fn) => async () => ((await fn()) ? undefined : false);
    const live = !replaced;
    if (editableNow) A.push({ label: st === 'submitted' ? (can.approver ? '✍️ Review & approve' : '✍️ Review') : '✏️ Edit', primary: !can.approver, handler: stay(() => rcOpen(c.id, { edit: true })) });
    if (can.revise) A.push({ label: '✏️ Edit (new version)', busyLabel: 'Starting…', handler: stay(() => rcRevise(c.id)) });
    if (live && st !== 'sent' && can.approver) A.push({ label: '✅ Sign & approve', primary: true, handler: stay(() => { rcCloseCard(c.id); return rcQuickApprove([Object.assign({}, c, { can_approve: true })]); }) });
    if (live && st === 'submitted' && (can.approver || can.review)) A.push({ label: '↩ Send back', busyLabel: 'Sending back…', handler: done(() => rcSendBack(c.id)) });
    if (can.email) A.push({ label: '✉️ Email to family', handler: stay(() => { rcCloseCard(c.id); return rcEmail(c); }) });
    A.push({ label: '⬇️ Download PDF', busyLabel: 'Downloading…', handler: stay(() => rcDownload(c)) });
    A.push({ label: '🖨️ Print', busyLabel: 'Opening…', handler: stay(() => rcPrint(c)) });
    if (can.delete) A.push({ label: '🗑️ Delete draft', busyLabel: 'Deleting…', handler: done(() => rcDelete(c)) });
    rcCloseCard();
    let blobUrl = null;
    const h = KT.Shell.Modal.open({
      title: '📑 ' + (c.child || 'Report card') + ' — ' + (c.term || '') + (+c.version > 1 ? ' · v' + (+c.version) : ''),
      body: body, actions: A, large: true,
      onClose: () => { if (blobUrl) { try { URL.revokeObjectURL(blobUrl); } catch (e) {} } if (RC_MODAL && RC_MODAL.h === h) { RC_MODAL = null; rcCardHash(0); } },
    });
    RC_MODAL = { id: c.id, h: h };
    rcCardHash(c.id, false);
    try { h.backdrop.querySelector('.modal').setAttribute('data-rc-card', c.id); h.backdrop.querySelector('.modal').setAttribute('data-rc-view', 'pdf'); } catch (e) {}
    document.getElementById('rc-o-hist').addEventListener('toggle', function () { if (this.open) rcHistory(c.id); });
    const host = body.querySelector('.rc-pdf-host');
    host.innerHTML = '<div style="color:#64748B;font-size:13px;text-align:center;padding:24px;">Opening the report card…</div>';
    KT.fetchFileBlob(rcPdfUrl(c.id)).then(b => {
      if (!document.body.contains(host)) return;
      blobUrl = URL.createObjectURL(new Blob([b], { type: 'application/pdf' }));
      return KT.renderDocumentInto(host, blobUrl + '#view=FitH', { title: rcName(c) }).then(() => {
        // pdf.js pages (phones, the app) flow in the popup's one scroll region; a desktop frame keeps its height.
        if (!host.querySelector('iframe')) { host.style.height = 'auto'; host.style.minHeight = '0'; host.style.padding = '8px'; }
      });
    }).catch(e => { host.innerHTML = `<div style="max-width:420px;margin:40px auto;background:#fff;border-radius:12px;padding:20px;text-align:center;font-size:13.5px;color:#334155;">${esc(rcSaid(e, 'The report card could not be shown here. Use Download PDF.'))}</div>`; });
  }
  async function rcOpen(id, opts) {
    opts = opts || {};
    let r; try { r = await Api.get('/report-cards/' + id); } catch (e) { rcToast(rcSaid(e, 'Could not load the card.'), 'error'); return; }
    const c = (r && r.data) || {}; const can = c.can || {};
    const st = c.status || 'draft', replaced = !!c.replaced;
    const editableNow = !replaced && (st === 'draft' || st === 'reviewed' || (st === 'submitted' && (can.approver || can.review)));
    const editable = editableNow && !!opts.edit;
    if (!editable) return rcViewPdf(c, editableNow);   // View = the PDF the family gets (2026-11-26)
    const out = document.createElement('div');
    out.id = 'rc-out'; out.setAttribute('data-card', c.id); out.setAttribute('data-child', c.child || '');
    const meta = [c.made_by ? 'Made by ' + c.made_by : '', c.centre || '', c.room || '', c.ref ? 'Ref ' + c.ref : ''].filter(Boolean).map(esc).join(' · ');
    out.innerHTML =
      `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">${rcPill(st, replaced)}${c.archived ? rcArchPill(c.archived) : ''}${editable ? rcChip('Editing', '#E0F2FE', '#075985') : ''}</div>` +
      `<div style="font-size:12.5px;color:#64748B;">${meta}</div>` +
      (c.review_note && (st === 'draft' || st === 'reviewed') ? `<div style="margin-top:10px;border:1px solid #FECACA;background:#FEF2F2;color:#991B1B;border-radius:10px;padding:8px 12px;font-size:13px;"><b>Sent back:</b> ${esc(c.review_note)}</div>` : '') +
      (st === 'submitted' && !can.approver ? '<div style="margin-top:10px;font-size:13px;color:#92400E;">Waiting for a director or admin to approve it.</div>' : '') +
      (replaced ? '<div style="margin-top:10px;font-size:13px;color:#475569;">A newer version replaced this one. It is kept here, with its PDF, for the record.</div>'
        : st === 'sent' ? `<div style="margin-top:10px;font-size:13px;color:#166534;">Sent to the family${c.sent_at ? ' · ' + esc(rcDay(c.sent_at)) : ''}.${can.revise ? ' To change it, use Edit: it becomes a new version that is approved again.' : ''}</div>` : '') +
      (editableNow && !editable ? '<div style="margin-top:10px;font-size:13px;color:#475569;">Viewing. Use Edit to change it.</div>' : '') +
      rcFwNote(c.framework_info) + rcBoxes(Array.isArray(c.sections) && c.sections.length ? c.sections : rcLegacy(c)) +
      `<h4 style="margin:16px 0 6px;color:#F59E0B;">Next steps</h4><textarea id="rc-next" rows="5" style="width:100%;min-height:110px;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;font-size:14px;">${esc(c.next_steps || '')}</textarea>` +
      (c.educator_signature && st === 'submitted' ? `<div style="margin-top:14px;font-size:12px;color:#64748B;">Educator signature</div><img src="${esc(c.educator_signature)}" alt="" style="height:66px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;padding:2px;">` : '') +
      '<details id="rc-o-hist" style="margin-top:14px;"><summary style="cursor:pointer;font-weight:700;font-size:13px;color:#1F6080;">History &amp; versions</summary><div data-rc-hist style="margin-top:8px;font-size:13px;color:#475569;">Loading…</div></details>';
    if (!editable) out.querySelectorAll('textarea').forEach(t => { t.readOnly = true; t.style.background = '#F8FAFC'; });
    const save = () => Api.patch('/report-cards/' + c.id, { narratives: rcCollect(), next_steps: document.getElementById('rc-next').value, status: st === 'submitted' ? undefined : 'reviewed' });
    /* Footer actions. A handler that returns false keeps the popup open (Save, PDF, Print, a cancelled
       signature); one that finishes the card lets the popup close. */
    const A = [];
    const stay = (fn) => async () => { await fn(); return false; };
    const done = (fn) => async () => ((await fn()) ? undefined : false);
    if (editable) {
      A.push({ label: '💾 Save', busyLabel: 'Saving…', handler: stay(async () => { try { await save(); rcToast('Saved.', 'success'); rcReloadList(); } catch (e) { rcToast(rcSaid(e, 'Could not save.'), 'error'); } }) });
      if (st === 'submitted') A.push({ label: '↩ Send back', busyLabel: 'Sending back…', handler: done(() => rcSendBack(c.id)) });
      if (can.approver) A.push({ label: '✍️ Sign, approve & send', primary: true, busyLabel: 'Approving…', handler: done(() => rcApprove(c.id, save)) });
      else if (st !== 'submitted') A.push({ label: '✍️ Sign & submit', primary: true, busyLabel: 'Submitting…', handler: done(() => rcSubmit(c.id, save)) });
    } else if (editableNow) {
      A.push({ label: st === 'submitted' ? (can.approver ? '✍️ Review & approve' : '✍️ Review') : '✏️ Edit', primary: true, handler: stay(() => rcOpen(c.id, { edit: true })) });
    }
    if (can.revise) A.push({ label: '✏️ Edit (new version)', primary: !editableNow, busyLabel: 'Starting…', handler: stay(() => rcRevise(c.id)) });
    if (can.email) A.push({ label: '✉️ Email to family', handler: stay(() => { rcCloseCard(c.id); return rcEmail(c); }) });
    A.push({ label: '⬇️ Download PDF', busyLabel: 'Downloading…', handler: stay(() => rcDownload(c)) });
    A.push({ label: '🖨️ Print', busyLabel: 'Opening…', handler: stay(() => rcPrint(c)) });
    if (can.delete) A.push({ label: '🗑️ Delete draft', busyLabel: 'Deleting…', handler: done(() => rcDelete(c)) });
    rcCloseCard();
    const h = KT.Shell.Modal.open({
      title: '📑 ' + (c.child || 'Report card') + ' — ' + (c.term || '') + (+c.version > 1 ? ' · v' + (+c.version) : ''),
      body: out, actions: A, large: true,
      onClose: () => { if (RC_MODAL && RC_MODAL.h === h) { RC_MODAL = null; rcCardHash(0); } },
    });
    RC_MODAL = { id: c.id, h: h };
    rcCardHash(c.id, editable);
    try { h.backdrop.querySelector('.modal').setAttribute('data-rc-card', c.id); } catch (e) {}
    document.getElementById('rc-o-hist').addEventListener('toggle', function () { if (this.open) rcHistory(c.id); });
  }

  const RC_EV = { generated: 'Drafted', revised: 'New version started', new_version: 'A new version was started', edited: 'Edited',
    submitted: 'Signed & submitted', approved: 'Approved & sent', sent: 'Sent', sent_back: 'Sent back', replaced: 'Replaced by a newer version',
    emailed: 'Emailed to the family', email_blocked: 'Email not sent (test agency)', deleted: 'Deleted' };
  async function rcHistory(id, force) {
    const det = document.getElementById('rc-o-hist'); if (!det) return;
    if (force) det.open = true;
    const box = det.querySelector('[data-rc-hist]');
    let r; try { r = await Api.get('/report-cards/' + id + '/history'); } catch (e) { box.textContent = rcSaid(e, 'Could not load the history.'); return; }
    const vs = (r && r.versions) || [], evs = (r && r.events) || [];
    const vHtml = vs.length > 1 ? '<div style="font-weight:700;margin:0 0 4px;">Versions</div>' + vs.map(v =>
      `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:4px 0;">v${v.version} ${rcPill(v.status, v.replaced)} <span style="color:#64748B;">${esc(rcDay(v.approved_at || v.updated_at))}</span>
        <button type="button" class="kt-btn" data-rc-vpdf="${v.id}" data-kt-iconized="1" style="height:28px;padding:0 10px;font-size:12.5px;">⬇️ PDF</button>
        ${+v.id !== +id ? `<button type="button" class="kt-btn" data-rc-vopen="${v.id}" data-kt-iconized="1" style="height:28px;padding:0 10px;font-size:12.5px;">👁️ Open</button>` : ''}</div>`).join('') : '';
    const who = (e) => e.by ? esc(e.by) : 'Someone';
    const eHtml = evs.length ? '<div style="font-weight:700;margin:10px 0 4px;">History</div>' + evs.map(e => {
      const d = e.detail || {};
      const to = Array.isArray(d.to) ? d.to.map(t => esc(t.name || t.email) + (t.email ? ' (' + esc(t.email) + ')' : '')).join(', ') : '';
      return `<div style="padding:3px 0;"><b>${esc(RC_EV[e.action] || e.action)}</b>${to ? ' to ' + to : ''}${d.note ? ': “' + esc(d.note) + '”' : ''} · ${who(e)} · <span style="color:#64748B;">${esc(rcDay(e.at))}</span></div>`;
    }).join('') : '<div style="color:#64748B;">No history recorded yet. Actions from 2026-11-26 on are listed here.</div>';
    box.innerHTML = vHtml + eHtml;
    const byId = {}; vs.forEach(v => { byId[v.id] = v; });
    box.querySelectorAll('[data-rc-vpdf]').forEach(b => { b.onclick = () => { const cur = document.getElementById('rc-out'); rcDownload({ id: +b.getAttribute('data-rc-vpdf'), child: cur ? cur.getAttribute('data-child') : '', term: 'v' + (byId[b.getAttribute('data-rc-vpdf')] || {}).version }); }; });
    box.querySelectorAll('[data-rc-vopen]').forEach(b => { b.onclick = () => rcOpen(+b.getAttribute('data-rc-vopen'), {}); });
  }

  /* ── the list ── */
  function rcRowActions(r) {
    const st = r.status || 'draft', g = rcGroup(st), live = !r.replaced;
    const list = [['view', '👁️ View']];
    if (live && g === 'draft') list.push(['edit', '✏️ Edit']);
    if (live && st === 'submitted' && (r.can_approve || r.can_review)) list.push(['edit', r.can_approve ? '✍️ Review & approve' : '✍️ Review'], ['back', '↩ Send back']);
    if (live && st !== 'sent' && r.can_approve) list.push(['approve', '✅ Sign & approve']);
    if (live && st === 'sent' && (RC.approver || r.mine)) list.push(['revise', '✏️ Edit (new version)']);
    list.push(['pdf', '⬇️ Download PDF'], ['print', '🖨️ Print']);
    if (live && st === 'sent' && RC.approver) list.push(['email', '✉️ Email to family']);
    if (live && g === 'draft' && RC.admin) list.push(['delete', '🗑️ Delete draft']);
    return list;
  }
  function rcRunAction(k, r) {
    if (k === 'view') return rcOpen(r.id, {});
    if (k === 'edit') return rcOpen(r.id, { edit: true });
    if (k === 'back') return rcSendBack(r.id);
    if (k === 'revise') return rcRevise(r.id);
    if (k === 'pdf') return rcDownload(r);
    if (k === 'print') return rcPrint(r);
    if (k === 'email') return rcEmail(r);
    if (k === 'delete') return rcDelete(r);
    if (k === 'approve') return rcQuickApprove([r]);
  }

  /* SIGN & APPROVE - one card from its ⋮, or several from the bulk bar (2026-11-26, Anthony: "add in kebab option
     to sign/approve and allow ability to select multiple at once to sign and approve or delete"). One house popup:
     what will be approved, the signature pad (with "Use my saved signature" when one is saved), an optional note.
     Several cards ask once more before going. The server checks every card on its own and says which it skipped. */
  const rcCanApprove = (c) => !!c.can_approve && !c.replaced && (c.status || 'draft') !== 'sent';
  function rcQuickApprove(cards) {
    const ok = cards.filter(rcCanApprove), many = cards.length > 1;
    if (!ok.length) { rcToast('You can\'t approve any of the selected report cards.', 'error'); return; }
    const others = cards.length - ok.length;
    const body = document.createElement('div');
    const line = (c) => `<li><b>${esc(c.child)}</b> — ${esc(c.term)}${rcVer(c.version)} <span style="color:#64748B;">· ${esc((RC_ST[c.status || 'draft'] || RC_ST.draft)[0])}${c.made_by ? ' · ' + esc(c.made_by) : ''}</span></li>`;
    body.innerHTML = (many
        ? `<p style="margin:0 0 8px;font-size:14px;"><b>${ok.length}</b> report card${ok.length === 1 ? '' : 's'} will be approved and sent to ${ok.length === 1 ? 'the family' : 'their families'}.</p>`
          + (others ? `<p style="margin:0 0 8px;font-size:13px;color:#92400E;">${others} of the ${cards.length} selected can't be approved by you (already approved, or not yours to approve). They will be skipped.</p>` : '')
        : '<p style="margin:0 0 8px;font-size:14px;">This report card will be approved and sent to the family.</p>')
      + `<ul style="margin:0 0 12px;padding-left:20px;font-size:13.5px;line-height:1.6;">${ok.slice(0, 8).map(line).join('')}${ok.length > 8 ? `<li style="color:#64748B;">and ${ok.length - 8} more</li>` : ''}</ul>`
      + '<div style="font-size:13px;font-weight:700;color:#334155;margin:0 0 6px;">Your signature</div><div class="rc-qa-pad"></div>'
      + '<label for="rc-qa-note" style="display:block;font-size:13px;font-weight:700;color:#334155;margin:12px 0 6px;">Note <span style="font-weight:400;color:#64748B;">(optional, kept in the card\'s history)</span></label>'
      + '<textarea id="rc-qa-note" maxlength="1000" rows="2" style="width:100%;min-height:64px;padding:9px;border:1px solid #CBD5E1;border-radius:8px;font-family:inherit;font-size:14px;"></textarea>';
    const pad = KT.signaturePadInline(body.querySelector('.rc-qa-pad'), { hint: 'Sign here to approve', context: many ? 'report_cards:bulk' : 'report_card:' + ok[0].id });
    const go = async () => {
      if (pad.isEmpty()) { rcToast('Sign first - your signature approves ' + (ok.length === 1 ? 'the card.' : 'the cards.'), 'error'); return false; }
      if (many) {
        const yes = await KT.confirm({ title: `Approve ${ok.length} report card${ok.length === 1 ? '' : 's'}?`,
          description: `${ok.length === 1 ? 'It' : 'They'} will be signed with your signature and sent to ${ok.length === 1 ? 'the family' : 'the families'}. A sent card can only be changed by making a new version.`,
          okLabel: `Approve ${ok.length}` });
        if (!yes) return false;
      }
      const sig = pad.toDataURL(), note = (body.querySelector('#rc-qa-note').value || '').trim();
      if (!many) {
        try { await Api.post('/report-cards/' + ok[0].id + '/approve', { admin_signature: sig, note: note || undefined }); }
        catch (e) { rcToast(rcSaid(e, 'Could not approve.'), 'error'); return false; }
        rcToast('Approved & sent to the family. ✓', 'success'); rcReloadList(); return true;
      }
      let r; try { r = await Api.post('/report-cards/bulk-approve', { ids: cards.map(c => c.id), admin_signature: sig, note: note || undefined }); }
      catch (e) { rcToast(rcSaid(e, 'Could not approve the report cards.'), 'error'); return false; }
      rcReloadList(); setTimeout(() => rcBulkSummary('approved', r), 0); return true;
    };
    KT.Shell.Modal.open({
      title: many ? `✅ Sign & approve ${ok.length} report card${ok.length === 1 ? '' : 's'}` : `✅ Sign & approve — ${ok[0].child}`,
      body,
      actions: [{ label: 'Cancel' }, { label: many ? `✅ Approve ${ok.length}` : '✅ Approve & send', primary: true, busyLabel: 'Approving…', handler: async () => ((await go()) ? undefined : false) }],
    });
  }

  async function rcBulkDelete(cards) {
    const ok = cards.filter(c => !c.replaced && rcGroup(c.status) === 'draft');
    if (!ok.length) { rcToast('Only drafts can be deleted, and none are selected.', 'error'); return; }
    const others = cards.length - ok.length;
    const yes = await KT.confirm({ title: `Delete ${ok.length} draft${ok.length === 1 ? '' : 's'}?`,
      description: `${ok.length} report card draft${ok.length === 1 ? '' : 's'} will be deleted. This cannot be undone.` + (others ? ` ${others} selected card${others === 1 ? ' is' : 's are'} not a draft and will be skipped.` : ''),
      okLabel: `Delete ${ok.length}`, tone: 'danger' });
    if (!yes) return;
    let r; try { r = await Api.post('/report-cards/bulk-delete', { ids: cards.map(c => c.id) }); }
    catch (e) { rcToast(rcSaid(e, 'Could not delete the drafts.'), 'error'); return; }
    rcReloadList(); rcBulkSummary('deleted', r);
  }

  /* What happened, card by card: never a silent partial success. */
  function rcBulkSummary(verb, r) {
    const done = (r && r[verb]) || [], skipped = (r && r.skipped) || [];
    const body = document.createElement('div');
    const li = (x, why) => `<li><b>${esc(x.name || ('Report card #' + x.id))}</b>${why ? ` <span style="color:#92400E;">— ${esc(why)}</span>` : ''}</li>`;
    body.innerHTML = `<p style="margin:0 0 10px;font-size:15px;font-weight:800;color:#0F172A;">${done.length} ${verb}${skipped.length ? `, ${skipped.length} skipped` : ''}</p>`
      + (done.length ? `<ul style="margin:0 0 12px;padding-left:20px;font-size:13.5px;line-height:1.6;">${done.map(x => li(x)).join('')}</ul>` : '')
      + (skipped.length ? `<div style="font-size:13px;font-weight:700;color:#92400E;margin:0 0 4px;">Skipped</div><ul style="margin:0;padding-left:20px;font-size:13.5px;line-height:1.6;">${skipped.map(x => li(x, x.reason)).join('')}</ul>` : '');
    KT.Shell.Modal.open({ title: verb === 'approved' ? '✅ Report cards approved' : '🗑️ Drafts deleted', body, actions: [{ label: 'Done', primary: true }] });
    if (skipped.length) rcToast(`${done.length} ${verb}, ${skipped.length} skipped.`, done.length ? 'success' : 'error');
    else rcToast(`${done.length} ${verb}. ✓`, 'success');
  }

  /* The bulk bar on the list (the portal's one: kt-polish-v2's tick boxes + .kt-bulk-bar). Its buttons follow the
     selection - Sign & approve when one of them is yours to approve, Delete drafts for an admin when one is a draft. */
  function rcWireBulk(table, rows) {
    if (!table) return;
    const byId = {}; rows.forEach(r => { byId[r.id] = r; });
    const picked = (trs, all) => all ? rows : trs.map(tr => byId[+tr.getAttribute('data-id')]).filter(Boolean);
    const sel = () => { const trs = Array.from(table.querySelectorAll('.kt-row-check:checked')).map(cb => cb.closest('tr')); return picked(trs, !!table.ktBulkAllMatching); };
    table.ktBulkMatching = () => rows.length;
    Object.defineProperty(table, 'ktBulkActions', { configurable: true, get() {
      const cs = sel(), acts = [];
      const nA = cs.filter(rcCanApprove).length, nD = RC.admin ? cs.filter(c => !c.replaced && rcGroup(c.status) === 'draft').length : 0;
      if (nA) acts.push({ label: `✅ Sign & approve (${nA})`, run: (trs, o) => rcQuickApprove(picked(trs, o && o.allMatching)) });
      if (nD) acts.push({ label: `🗑️ Delete drafts (${nD})`, danger: true, run: (trs, o) => rcBulkDelete(picked(trs, o && o.allMatching)) });
      return acts;
    } });
    if (table.ktBulkRefresh) table.ktBulkRefresh();
  }
  function rcPaintAll() {
    const host = document.getElementById('rc-all'); if (!host) return;
    const f = RC.f;
    if (f.st === 'archived') f.st = '';   // no Archived tab now (2026-11-26): those cards are in All, marked
    const all = RC.rows;
    if (!RC.rows.length) { host.innerHTML = '<div class="kt-card" style="margin-bottom:16px;"><h3 style="margin:0 0 4px;">📚 All report cards</h3><div style="color:#64748B;font-size:13px;">No report cards yet.' + (rcCanGenerate() ? ' Use ✨ Generate with AI to write the first one.' : '') + '</div></div>'; return; }
    const uniq = (k) => Array.from(new Set(all.map(r => r[k]).filter(Boolean))).sort();
    const terms = uniq('term'), makers = uniq('made_by'), centres = uniq('centre');
    const cnt = { '': RC.rows.length, draft: 0, submitted: 0, sent: 0 };
    RC.rows.forEach(r => { cnt[rcGroup(r.status)]++; });
    const q = f.q.trim().toLowerCase();
    const rows = all.filter(r => (!f.st || rcGroup(r.status) === f.st) && (!f.term || r.term === f.term)
      && (!f.by || r.made_by === f.by) && (!f.centre || r.centre === f.centre) && (!q || (r.child || '').toLowerCase().indexOf(q) !== -1));
    const chip = (k, label) => `<button type="button" class="rc-chip${f.st === k ? ' on' : ''}" data-st="${k}">${esc(label)} <b>${cnt[k]}</b></button>`;
    const opt = (list, cur, any) => `<option value="">${esc(any)}</option>` + list.map(v => `<option value="${esc(v)}"${v === cur ? ' selected' : ''}>${esc(v)}</option>`).join('');
    host.innerHTML = '<div class="kt-card" style="--kt-edge:#1F6080;margin-bottom:16px;">' +
      `<h3 style="margin:0 0 4px;">📚 All report cards <span style="background:#E0F2FE;color:#075985;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;">${RC.rows.length}</span></h3>` +
      '<p style="font-size:12.5px;color:#64748B;margin:0 0 10px;">Every report card you can see, newest first. Tap one to open it, or use ⋮ for more.</p>' +
      '<div class="rc-bar">' + chip('', 'All') + chip('draft', 'Draft') + chip('submitted', 'Awaiting approval') + chip('sent', 'Sent') + '</div>' +
      '<div class="rc-bar">' +
        `<input type="search" id="rc-f-q" placeholder="Search a child" value="${esc(f.q)}">` +
        (terms.length > 1 ? `<select id="rc-f-term">${opt(terms, f.term, 'All terms')}</select>` : '') +
        (makers.length > 1 ? `<select id="rc-f-by">${opt(makers, f.by, 'Anyone')}</select>` : '') +
        (centres.length > 1 ? `<select id="rc-f-centre">${opt(centres, f.centre, 'All centres')}</select>` : '') +
      '</div>' +
      (rows.length ? '<div class="kt-table-scroll"><table class="rc-table" data-kt-no-filter="1"><thead><tr><th>Child</th><th>Term</th><th>Made by</th><th>Status</th><th>Centre</th><th>Updated</th><th></th></tr></thead><tbody>' +
        rows.slice(0, 300).map(r => `<tr class="rc-row" data-id="${r.id}" tabindex="0" style="cursor:pointer;"><td><b>${esc(r.child)}</b>${rcVer(r.version)}</td><td>${esc(r.term)}</td><td>${esc(r.made_by || '—')}${r.mine ? ' <span style="color:#64748B;font-size:11.5px;">(you)</span>' : ''}</td><td>${rcPill(r.status, r.replaced)}${r.archived ? ' ' + rcArchPill(r.archived) : ''}</td><td>${esc(r.centre || '')}${r.room ? ' · ' + esc(r.room) : ''}</td><td>${esc(rcDay(r.updated_at || r.created_at))}</td>` +
          '<td class="rc-acts">' + rcRowActions(r).map(a => `<button type="button" class="kt-btn" data-rc-row="${a[0]}">${a[1]}</button>`).join('') + '</td></tr>').join('') +
        '</tbody></table></div>' + (rows.length > 300 ? '<div style="font-size:12px;color:#64748B;margin-top:6px;">Showing the 300 most recent. Narrow the filters to see more.</div>' : '')
        : '<div style="padding:14px;color:#64748B;font-size:13px;">No report cards match.</div>') +
      '</div>';
    host.querySelectorAll('.rc-chip').forEach(b => { b.onclick = async () => {
      f.st = b.getAttribute('data-st'); rcPaintAll();
    }; });
    const qi = document.getElementById('rc-f-q');
    if (qi) qi.addEventListener('input', () => { f.q = qi.value; const pos = qi.selectionStart; rcPaintAll(); const n = document.getElementById('rc-f-q'); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} } });
    [['rc-f-term', 'term'], ['rc-f-by', 'by'], ['rc-f-centre', 'centre']].forEach(([id, k]) => { const s = document.getElementById(id); if (s) s.onchange = () => { f[k] = s.value; rcPaintAll(); }; });
    const byId = {}; all.forEach(r => { byId[r.id] = r; });
    host.querySelectorAll('.rc-row').forEach(tr => {
      const r = byId[+tr.getAttribute('data-id')];
      tr.querySelectorAll('[data-rc-row]').forEach(b => { b.addEventListener('click', (e) => { e.stopPropagation(); rcRunAction(b.getAttribute('data-rc-row'), r); }); });
      tr.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('.rc-acts, .kt-ka-kebab, button, a')) return; rcOpen(r.id, {}); });
      tr.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target === tr) rcOpen(r.id, {}); });
    });
    if (window.KT && KT.enhanceTables) { try { KT.enhanceTables(); } catch (e) {} }
    rcWireBulk(host.querySelector('table.rc-table'), rows);
  }
  async function rcReloadList() {
    if (!document.getElementById('rc-all')) return;
    let r; try { r = await Api.get('/report-cards/all'); } catch (e) { return; }
    // admins: cards for children who left / closed centres arrive as archived_rows; one list, newest first, each marked
    RC.rows = ((r && r.data) || []).concat((r && r.archived_rows) || []).sort((x, y) => String(y.updated_at || y.created_at || '').localeCompare(String(x.updated_at || x.created_at || '')));
    RC.approver = !!(r && r.approver); RC.admin = !!(r && r.admin);
    rcPaintAll(); rcLoadPending();
  }

  /* ── parents' requests + awaiting approval ── */
  async function rcLoadReqs() {
    if (RC.reqs) return RC.reqs;
    try { RC.reqs = await Api.get('/report-cards/requests'); } catch (e) { RC.reqs = { data: [], quarters: [], current: null }; }
    return RC.reqs;
  }
  async function rcPaintRequests() {
    const host = document.getElementById('rc-requests'); if (!host) return;
    const r = await rcLoadReqs(); const asks = (r && r.data) || [];
    if (!asks.length) { host.innerHTML = ''; return; }
    const day = (s) => rcDay(s);
    host.innerHTML = '<div class="kt-card" style="--kt-edge:#1F6080;margin-bottom:16px;">' +
      `<h3 style="margin:0 0 4px;">📨 Requested by parents <span style="background:#E0F2FE;color:#075985;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;">${asks.length}</span></h3>` +
      '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Families asked for these report cards. Write and submit each one; it reaches the family once a director or admin approves it.</p>' +
      asks.map(a => `<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid #BAE6FD;background:#F0F9FF;border-radius:12px;padding:10px 14px;margin-bottom:8px;">
        <div><b>${esc(((a.first_name || '') + ' ' + (a.last_name || '')).trim())}</b>${a.room ? ' · ' + esc(a.room) : ''} — ${esc(a.term)}<div style="font-size:12px;color:#075985;">Asked by ${esc(a.parent_first_name || 'a parent')} on ${esc(day(a.created_at))}</div></div>
        ${rcCanGenerate() ? `<button type="button" class="kt-btn kt-btn-primary rc-write" data-kt-iconized="1" data-cid="${a.child_id}" data-term="${esc(a.term)}" data-name="${esc(((a.first_name || '') + ' ' + (a.last_name || '')).trim())}">✍️ Write it</button>` : ''}</div>`).join('') + '</div>';
    host.querySelectorAll('.rc-write').forEach(b => {
      b.onclick = () => { RC.prefill = { cid: b.getAttribute('data-cid'), term: b.getAttribute('data-term'), name: b.getAttribute('data-name') }; rcGoTab('generate'); };
    });
  }
  async function rcLoadPending() {
    const host = document.getElementById('rc-pending'); if (!host) return;
    if (!RC.approver) { host.innerHTML = ''; return; }
    let r; try { r = await Api.get('/report-cards/pending'); } catch (e) { return; }
    const pend = (r && r.data) || []; RC.pendAdmin = !!(r && r.admin);
    if (!pend.length) { host.innerHTML = ''; return; }
    host.innerHTML = '<div class="kt-card" style="--kt-edge:#F59E0B;margin-bottom:16px;">' +
      `<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 4px;"><h3 style="margin:0;">✍️ ${RC.pendAdmin ? 'Awaiting your approval' : 'Awaiting approval'} <span style="background:#FEF3C7;color:#92400E;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;">${pend.length}</span></h3>`
      + (!RC.pendAdmin ? '<span style="font-size:12.5px;color:#64748B;">Review and send back here. An agency admin signs &amp; approves.</span>' : '')
      + (RC.pendAdmin && pend.length > 1 ? `<button type="button" class="kt-btn kt-btn-primary rc-approve-all" data-kt-iconized="1">✅ Sign &amp; approve all ${pend.length}</button>` : '') + '</div>' +
      '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Educators submitted these report cards for your review, signature and approval.</p>' +
      pend.map(p => `<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid #FDE68A;background:#FFFBEB;border-radius:12px;padding:12px 14px;margin-bottom:8px;">
        <div><b>${esc(p.child)}</b> — ${esc(p.term)}<div style="font-size:12px;color:#92400E;">Submitted by ${esc(p.submitted_by || 'an educator')}</div></div>
        <div style="display:flex;gap:6px;align-items:center;"><button type="button" class="kt-btn kt-btn-primary rc-review" data-kt-iconized="1" data-id="${p.id}">${p.can_approve ? 'Review &amp; sign' : 'Review'}</button>
        <button type="button" class="kt-ka-kebab rc-pmenu" data-id="${p.id}" aria-label="More actions" data-kttip="More actions">⋮</button></div></div>`).join('') + '</div>';
    const byId = {}; pend.forEach(p => { byId[p.id] = p; });
    host.querySelectorAll('.rc-review').forEach(b => { b.onclick = () => rcOpen(+b.getAttribute('data-id'), { edit: true }); });
    const all = host.querySelector('.rc-approve-all');
    if (all) all.onclick = () => rcQuickApprove(pend.map(p => Object.assign({ status: 'submitted' }, p)));
    host.querySelectorAll('.rc-pmenu').forEach(b => {
      b.onclick = () => {
        const p = byId[+b.getAttribute('data-id')], c = { id: p.id, child: p.child, term: p.term };
        const mk = (label, fn) => { const x = document.createElement('button'); x.type = 'button'; x.textContent = label; x.addEventListener('click', fn); return x; };
        KT.openActionMenu(b, [
          mk('👁️ View', () => rcOpen(p.id, {})), mk(p.can_approve ? '✍️ Review & approve' : '✍️ Review', () => rcOpen(p.id, { edit: true })),
          ...(p.can_approve ? [mk('✅ Sign & approve', () => rcQuickApprove([Object.assign({ status: 'submitted' }, p)]))] : []),
          mk('↩ Send back', () => rcSendBack(p.id)), mk('⬇️ Download PDF', () => rcDownload(c)), mk('🖨️ Print', () => rcPrint(c)),
        ]);
      };
    });
  }

  /* ── admin: report card settings ── */
  async function rcPaintSettings() {
    const host = document.getElementById('rc-settings'); if (!host || !RC.admin) { if (host) host.innerHTML = ''; return; }
    let s; try { s = await Api.get('/admin/report-card-settings'); } catch (e) { host.innerHTML = ''; return; }
    if (!host.isConnected) return;   // the screen repainted while this was loading; the new paint fills its own host
    host.innerHTML = '<div class="kt-card" style="margin-top:16px;"><h3 style="margin:0 0 8px;">⚙️ Report card settings</h3>' +
      `<label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-size:14px;"><input type="checkbox" id="rc-set-rece" ${s && s.show_registry ? 'checked' : ''} style="width:17px;height:17px;margin-top:2px;accent-color:#2F8C86;">` +
      '<span><b>Show the educator\'s RECE registration number</b><br><span style="color:#64748B;font-size:12.5px;">Printed under "Prepared by" on report card PDFs when the educator has one on file.</span></span></label></div>';
    const cb = host.querySelector('#rc-set-rece'); if (!cb) return;
    cb.onchange = async () => {
      try { await Api.post('/admin/report-card-settings', { show_registry: cb.checked }); rcToast('Saved.', 'success'); }
      catch (e) { cb.checked = !cb.checked; rcToast(rcSaid(e, 'Could not save.'), 'error'); }
    };
  }

  /* ── ✨ Generate with AI ── */
  async function rcPaintGenerate(body) {
    body.innerHTML = '<div class="kt-card"><div style="padding:8px;color:#64748B;">Loading…</div></div>';
    if (RC.kids === null) {
      let kids = [], err = 0;
      try { const kr = await Api.get('/admin/children'); kids = (kr && Array.isArray(kr.children)) ? kr.children : Array.isArray(kr) ? kr : (kr && Array.isArray(kr.data)) ? kr.data : []; }
      catch (e) { err = (e && e.status) || 0; }
      if (!kids.length) { try { const pr = await Api.get('/provider/children'); if (pr && Array.isArray(pr.children) && pr.children.length) { kids = pr.children; err = 0; } } catch (e2) {} }
      RC.kids = kids; RC.kidsErr = err;
    }
    const r = await rcLoadReqs();
    if (RC.tab !== 'generate' || !body.isConnected) return;
    const kidLabel = (c) => esc(((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.preferred_name || 'Child') + (c.centre_name ? ' · ' + esc(c.centre_name) : '');
    const ai = rcAiOn();
    body.innerHTML =
      (ai ? '' : '<div class="kt-card" data-rc-aioff style="margin-bottom:16px;display:flex;gap:12px;align-items:flex-start;"><div style="font-size:26px;">🔌</div><div><b>AI is off for this agency</b><div style="font-size:13px;color:#475569;margin-top:4px;">New drafts are written from the child\'s logged observations instead. Read and edit every section before you submit it.</div></div></div>') +
      '<div class="kt-card"><h3 style="margin:0 0 4px;">✨ Generate a report card</h3>' +
      '<p style="font-size:12.5px;color:#64748B;margin:0 0 12px;">Pick a child and a term. A draft is written for each area of your learning framework. You edit it, then sign and submit it (educators) or sign, approve and send it (directors and admins).</p>' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:640px;">' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Child</label>' +
          '<style>#rc-cid,#rc-cid option,#rc-term,#rc-term option{background-color:#fff !important;color:#0F172A !important;}</style>' +
          '<select id="rc-cid" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;background-color:#fff;color:#0F172A;color-scheme:light;font-size:14px;"><option value="">Select a child…</option>' +
          RC.kids.map(c => `<option value="${c.id}">${kidLabel(c)}</option>`).join('') + '</select>' +
          (RC.kids.length ? '' : `<div style="margin-top:6px;color:#64748B;font-size:12.5px;">${RC.kidsErr === 403 ? 'Select an agency (top-bar switcher) to load its children.' : 'No children found for this agency.'}</div>`) + '</div>' +
        '<div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Term</label>' +
          '<select id="rc-term" style="width:100%;padding:11px;border:1px solid #E2E8F0;border-radius:8px;background-color:#fff;color:#0F172A;color-scheme:light;font-size:14px;"></select>' +
          '<input id="rc-term-other" placeholder="Type the term, e.g. Summer 2027" maxlength="40" style="display:none;width:100%;margin-top:8px;padding:11px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;"></div>' +
      '</div>' +
      `<button type="button" id="rc-gen" class="kt-btn kt-btn-primary" data-kt-iconized="1" style="margin-top:18px;">${ai ? '✨ Generate with AI' : '📝 Draft from observations'}</button>` +
      '</div>';
    const OTHER = '__other';
    const sel = document.getElementById('rc-term'), other = document.getElementById('rc-term-other');
    const labels = ((r && r.quarters) || []).map(q => q && q.label).filter(Boolean);
    sel.innerHTML = (labels.length ? '' : '<option value="">Choose a term…</option>') + labels.map(l => `<option value="${esc(l)}">${esc(l)}</option>`).join('') + `<option value="${OTHER}">Another term…</option>`;
    const pick = (label) => {
      if (!label) return;
      if (!Array.prototype.some.call(sel.options, o => o.value === label)) { const o = document.createElement('option'); o.value = label; o.textContent = label; sel.insertBefore(o, sel.querySelector(`option[value="${OTHER}"]`)); }
      sel.value = label; other.style.display = 'none';
    };
    pick((r && r.current && r.current.label) || labels[0] || '');
    sel.addEventListener('change', () => { const show = sel.value === OTHER; other.style.display = show ? 'block' : 'none'; if (show) other.focus(); });
    const cidSel = document.getElementById('rc-cid');
    const pre = RC.prefill || (() => { const q = RC.q0 || {}; RC.q0 = null; return q.child ? { cid: q.child, term: q.term || '' } : null; })();
    RC.prefill = null;
    if (pre && pre.cid) {
      if (!cidSel.querySelector('option[value="' + pre.cid + '"]')) { const o = document.createElement('option'); o.value = pre.cid; o.textContent = pre.name || 'Child'; cidSel.appendChild(o); }
      cidSel.value = pre.cid; if (pre.term) pick(pre.term);
    }
    const btn = document.getElementById('rc-gen');
    btn.onclick = async () => {
      const cid = +cidSel.value; const term = sel.value === OTHER ? (other.value || '').trim() : sel.value;
      if (!cid || !term) { rcToast('Select a child and a term.', 'info'); return; }
      const label = btn.textContent;
      btn.disabled = true; btn.textContent = 'Writing the draft… (15-30 sec)';
      try {
        const g = await Api.post('/report-cards/generate', { child_id: cid, term });
        await rcOpen(g.id, { edit: true });
        RC.reqs = null;
      } catch (e) { rcToast(rcSaid(e, 'Could not write the draft.'), 'error'); }
      finally { btn.disabled = false; btn.textContent = label; }
    };
  }

  /* ── tabs ── */
  function rcGoTab(tab) {
    RC.tab = (tab === 'generate' && rcCanGenerate()) ? 'generate' : 'cards';
    rcSetHash();
    document.querySelectorAll('#rc-tabs [data-rc-tab]').forEach(b => {
      const on = b.getAttribute('data-rc-tab') === RC.tab;
      b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    const body = document.getElementById('rc-body'); if (!body) return;
    if (RC.tab === 'generate') { rcPaintGenerate(body); return; }
    body.innerHTML = '<div id="rc-requests"></div><div id="rc-pending"></div><div id="rc-all"><div class="kt-card" style="color:#64748B;">Loading report cards…</div></div><div id="rc-settings"></div>';
    rcPaintRequests();
    rcReloadList().then(() => { rcPaintSettings(); const q = RC.q0 || {}; RC.q0 = null; if (+q.card > 0) rcOpen(+q.card, { edit: q.edit === '1' }); });
  }
  (function rcCss() {
    if (document.getElementById('rc-all-css')) return;
    const st = document.createElement('style'); st.id = 'rc-all-css';
    st.textContent = '#rc-all .rc-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px}'
      + '#rc-all .rc-chip{height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#334155}'
      + '#rc-all .rc-chip b{margin-left:4px;font-weight:800}'
      + '#rc-all .rc-chip.on{background:#0F172A;color:#fff;border-color:#0F172A}'
      + '#rc-all .rc-bar input,#rc-all .rc-bar select{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;color:#0F172A;max-width:100%}'
      + '#rc-all .rc-bar input{min-width:200px;flex:1 1 200px}'
      + '#rc-all table.rc-table{width:100%;border-collapse:collapse;font-size:13.5px}'
      + '#rc-all table.rc-table th{text-align:left;font-size:11.5px;color:#64748B;font-weight:800;text-transform:uppercase;letter-spacing:.3px;padding:8px 10px;border-bottom:1px solid #E2E8F0}'
      + '#rc-all table.rc-table td{padding:9px 10px;border-bottom:1px solid #F1F5F9;vertical-align:middle}'
      + '#rc-all table.rc-table td.rc-acts{width:1%;white-space:nowrap;text-align:right}'
      + '#rc-all tr.rc-row:focus{outline:none}#rc-all tr.rc-row:focus-visible{outline:2px solid #2F8C86;outline-offset:-2px}'
      + '.rc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}';
    document.head.appendChild(st);
  })();

  async function renderReportCards(main) {
    main.setAttribute('data-kt-pretty', '1');
    const gen = rcCanGenerate();
    const q = rcQuery();
    RC.q0 = q; // read once by the card / generate deep link, after rcGoTab() has tidied the address
    RC.tab = (q.tab === 'generate' && gen) ? 'generate' : 'cards';
    RC.reqs = null; RC.kids = null; RC.archived = null; RC.f = { st: '', q: '', term: '', by: '', centre: '' };
    main.innerHTML = `<div style="padding:24px;">
      <div class="kt-page-hero">
        <h2>📑 Report cards</h2>
        <p>Every report card in one place. Educators sign &amp; submit; a director or admin signs &amp; approves before it reaches the family.</p>
      </div>
      ${gen ? `<div class="rc-tabs" id="rc-tabs" role="tablist" data-kt-tabbar="1">
        <button type="button" role="tab" class="rc-tab" data-rc-tab="cards" aria-selected="false">📚 Report cards</button>
        <button type="button" role="tab" class="rc-tab" data-rc-tab="generate" aria-selected="false">✨ Generate with AI</button>
      </div>` : ''}
      <div id="rc-body"></div>
    </div>`;
    main.querySelectorAll('[data-rc-tab]').forEach(b => { b.addEventListener('click', () => rcGoTab(b.getAttribute('data-rc-tab'))); });
    rcGoTab(RC.tab);
  }

  // ============================ Activity zones ============================
  /* WHOSE ZONES (2026-10-06). Anthony: "activity zone should show one educator selected
     and not the others in the agency". This took /admin/centres, used whichever centre
     came first, showed every zone and every child there, and logged a visit by typing a
     child's ID. Now the server decides the scope (GET /zones/options): an educator sees
     their own room(s) — those rooms' zones, the centre's shared zones, and only their
     children; a director their centre; an admin the agency, with a centre + room picker.
     A visit is logged by picking the child from the room's list. */
  const zState = { centreId: null, roomId: '', opts: null };
  const zDay = (d) => { const m = String(d || '').match(/^(\d{4})-(\d{2})-(\d{2})/); if (!m) return esc(d || ''); const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return M[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1]; };
  /* Icons were stored as NAMES in some rows ("book", "blocks") and printed as the word. */
  const Z_ICONS = { blocks: '🧱', block: '🧱', book: '📚', books: '📚', reading: '📚', mask: '🎭', drama: '🎭', palette: '🎨', art: '🎨', music: '🎵', sand: '🏖️', water: '💧', sensory: '🫧', science: '🔬', outdoor: '🌳', garden: '🌱', puzzle: '🧩', kitchen: '🍳', computer: '💻', lego: '🧱', paint: '🎨', dolls: '🧸', toys: '🧸', cars: '🚗', math: '🔢' };
  const zIcon = (i) => { const t = String(i || '').trim(); if (!t) return '🎨'; if (/^[a-z_ -]+$/i.test(t)) return Z_ICONS[t.toLowerCase()] || '🎨'; return t; };
  const zToast = (m, t) => { try { if (window.KT && KT.toast) { KT.toast(m, t || 'info'); return; } } catch (e) {} };
  const Z_CSS = '#appMain .zn-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}'
    + '#appMain .zn-bar select{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;max-width:100%}'
    + '#appMain .zn-chip{height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#334155}'
    + '#appMain .zn-chip.on{background:#0F172A;color:#fff;border-color:#0F172A}'
    + '#appMain .zn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}'
    + '#appMain .zn-card{padding:14px;border-radius:12px;text-align:center;border:2px solid #CBD5E1}'
    + '#appMain .zn-card .zn-room{font-size:11px;color:#64748B;margin-top:2px}'
    + '#appMain .zn-card button{margin-top:8px;height:30px;padding:0 12px;font-size:12.5px}'
    + '.zn-ov{position:fixed;inset:0;z-index:99990;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px}'
    + '.zn-dlg{background:#fff;border-radius:14px;width:100%;max-width:440px;max-height:86vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.3)}'
    + '.zn-dlg h3{margin:0;font-size:16px}.zn-dlg .zn-hd{display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid #E2E8F0}'
    + '.zn-dlg .zn-x{margin-left:auto;width:30px;height:30px;border:0;background:#F1F5F9;border-radius:8px;cursor:pointer;font-size:16px}'
    + '.zn-dlg .zn-body{padding:12px 16px;overflow:auto}.zn-dlg input,.zn-dlg select{width:100%;height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;box-sizing:border-box}'
    + '.zn-dlg label{display:block;font-size:12px;font-weight:700;color:#475569;margin:10px 0 4px}'
    + '.zn-kid{display:flex;align-items:center;gap:10px;width:100%;padding:8px;border:0;background:none;border-radius:10px;cursor:pointer;text-align:left;font-size:14px}'
    + '.zn-kid:hover{background:#F1F5F9}.zn-kid img,.zn-kid .zn-av{width:34px;height:34px;border-radius:50%;object-fit:cover;flex:none;background:#E0F2FE;color:#0369A1;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px}'
    + '.zn-dlg .zn-ft{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #E2E8F0}';
  function zCss() { if (document.getElementById('zn-css')) return; const s = document.createElement('style'); s.id = 'zn-css'; s.textContent = Z_CSS; document.head.appendChild(s); }
  function zDialog(title, bodyHtml, footHtml) {
    const ov = document.createElement('div');
    ov.className = 'zn-ov'; ov.setAttribute('data-no-modal-guard', '1');
    ov.innerHTML = '<div class="zn-dlg" role="dialog" aria-modal="true"><div class="zn-hd"><h3>' + esc(title) + '</h3><button type="button" class="zn-x" aria-label="Close">✕</button></div>'
      + '<div class="zn-body">' + bodyHtml + '</div>' + (footHtml ? '<div class="zn-ft">' + footHtml + '</div>' : '') + '</div>';
    document.body.appendChild(ov);
    const close = () => ov.remove();
    ov.querySelector('.zn-x').onclick = close;
    ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
    return { el: ov, close };
  }

  async function renderZones(main) {
    zCss();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    let opts;
    try { opts = await Api.get('/zones/options'); } catch (e) { opts = null; }
    if (!opts || !(opts.centres || []).length) {
      main.innerHTML = '<div style="padding:24px;max-width:1800px;margin:0 auto;"><div class="kt-page-hero"><h2>🎨 Activity zones</h2><p>Track which play areas each child spends time in.</p></div>'
        + '<div class="kt-card" style="text-align:center;color:#64748B;padding:40px;">'
        + (opts && opts.role === 'educator' ? 'You are not assigned to a room yet — ask your director to add you to one.' : 'No centres you can see.') + '</div></div>';
      return;
    }
    zState.opts = opts;
    const isEd = opts.role === 'educator';
    if (zState.centreId && !opts.centres.some(c => c.id === zState.centreId)) zState.centreId = null;
    if (!zState.centreId && opts.centres.length === 1) zState.centreId = opts.centres[0].id;
    // Select a provider first (2026-10-24) — never the first one in the list.
    if (!zState.centreId && window.KT && KT.selectFirst) { KT.selectFirst(main, { items: opts.centres, onPick: function (id) { zState.centreId = id; renderZones(main); } }); return; }
    const roomsHere = (opts.rooms || []).filter(r => r.centre_id === zState.centreId);
    if (zState.roomId && !roomsHere.some(r => String(r.id) === String(zState.roomId))) zState.roomId = '';
    const cid = zState.centreId;
    const roomQ = zState.roomId ? '&room_id=' + zState.roomId : '';
    let zones = { data: [] }, report = { by_zone_id: {}, by_child: {}, total_visits: 0 };
    try {
      [zones, report] = await Promise.all([
        Api.get(`/zones?centre_id=${cid}${roomQ}`),
        Api.get(`/zones/daily-report?centre_id=${cid}${roomQ}&date=${opts.today}`),
      ]);
    } catch (e) { zToast(e.message || 'Could not load zones', 'error'); }
    const byZoneId = report.by_zone_id || {};
    const byChild = report.by_child || {};
    const roomName = (id) => { const r = (opts.rooms || []).find(x => x.id === id); return r ? r.name : ''; };
    const centreName = (opts.centres.find(c => c.id === cid) || {}).name || '';
    const scopeLine = isEd
      ? (roomsHere.map(r => r.name).join(', ') || 'Your rooms') + ' · ' + centreName
      : centreName + (zState.roomId ? ' · ' + roomName(+zState.roomId) : ' · All rooms');

    const picker = isEd
      ? (roomsHere.length > 1 ? '<div class="zn-bar"><button type="button" class="zn-chip' + (zState.roomId ? '' : ' on') + '" data-room="">All my rooms</button>'
          + roomsHere.map(r => '<button type="button" class="zn-chip' + (String(zState.roomId) === String(r.id) ? ' on' : '') + '" data-room="' + r.id + '">' + esc(r.name) + '</button>').join('') + '</div>' : '')
      : '<div class="zn-bar">'
          + (opts.centres.length > 1 ? '<select data-zcentre aria-label="Centre">' + opts.centres.map(c => '<option value="' + c.id + '"' + (c.id === cid ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select>' : '')
          + '<select data-zroom aria-label="Room"><option value="">All rooms</option>' + roomsHere.map(r => '<option value="' + r.id + '"' + (String(zState.roomId) === String(r.id) ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('') + '</select></div>';

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎨 Activity zones</h2>
        <p>${esc(scopeLine)} · ${zDay(report.date || opts.today)} · ${report.total_visits || 0} visit${(report.total_visits === 1) ? '' : 's'} today.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="z-new">+ New zone</button></div>
      </div>
      ${picker}
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Today's activity</h3></div>
        <div class="zn-grid">
          ${(zones.data || []).map(z => `<div class="zn-card" style="background:${esc(z.color)}22;border-color:${esc(z.color)};">
            <div style="font-size:30px;">${esc(zIcon(z.icon))}</div>
            <div style="font-weight:700;color:#0F172A;margin-top:4px;">${esc(z.name)}</div>
            <div class="zn-room">${z.room_id ? esc(roomName(z.room_id)) : 'Whole centre'}</div>
            <div style="color:${esc(z.color)};font-weight:800;font-size:20px;margin-top:4px;">${byZoneId[z.id] || 0}</div>
            <button class="kt-btn kt-btn-primary" data-log="${z.id}" data-room="${z.room_id || ''}" data-name="${esc(z.name)}">+ Log visit</button>
          </div>`).join('') || '<div style="color:#64748B;padding:30px;text-align:center;grid-column:1/-1;">No zones here yet.</div>'}
        </div>
      </div>
      <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">Per-child visits today</h3></div>
        ${Object.keys(byChild).length ? `<table>
          <thead><tr><th>Child</th><th>Visited</th></tr></thead>
          <tbody>${Object.entries(byChild).map(([name, visits]) => `<tr>
            <td><strong>${esc(name)}</strong></td>
            <td>${visits.map(v => `<span class="kt-pill kt-pill-info">${esc(zIcon(v.icon))} ${esc(v.zone)} <span style="opacity:.7">@ ${esc(v.time)}</span></span>`).join(' ')}</td>
          </tr>`).join('')}</tbody>
        </table>` : '<div style="color:#64748B;padding:20px;text-align:center;">No visits logged today.</div>'}
      </div>
    </div>`;

    const c = main.querySelector('[data-zcentre]');
    if (c) c.onchange = () => { zState.centreId = +c.value; zState.roomId = ''; renderZones(document.getElementById('appMain') || main); };
    const r = main.querySelector('[data-zroom]');
    if (r) r.onchange = () => { zState.roomId = r.value; renderZones(document.getElementById('appMain') || main); };
    main.querySelectorAll('.zn-chip[data-room]').forEach(b => b.onclick = () => { zState.roomId = b.dataset.room; renderZones(document.getElementById('appMain') || main); });
    document.getElementById('z-new').onclick = () => openZoneModal(cid, main);
    main.querySelectorAll('button[data-log]').forEach(b => b.onclick = () => openChildPicker(main, cid, b.dataset.log, b.dataset.name, b.dataset.room));
  }

  /* Pick the child — from the zone's room, or (for a whole-centre zone) the rooms in view. */
  async function openChildPicker(main, centreId, zoneId, zoneName, zoneRoom) {
    const room = zoneRoom || zState.roomId || '';
    let kids = [];
    try { kids = (await Api.get(`/zones/options?centre_id=${centreId}${room ? '&room_id=' + room : ''}`)).children || []; }
    catch (e) { zToast(e.message || 'Could not load children', 'error'); return; }
    const d = zDialog('Log a visit to ' + zoneName,
      '<input type="search" data-q placeholder="Search children…" aria-label="Search children" autocomplete="off">'
      + '<div data-list style="margin-top:8px"></div>');
    const list = d.el.querySelector('[data-list]');
    const paint = (q) => {
      const t = String(q || '').toLowerCase();
      const rows = kids.filter(k => !t || (k.first_name + ' ' + k.last_name).toLowerCase().includes(t));
      list.innerHTML = rows.map(k => '<button type="button" class="zn-kid" data-kid="' + k.id + '">'
        + (k.photo_url ? '<img src="' + esc(k.photo_url) + '" alt="">' : '<span class="zn-av">' + esc((k.first_name || '?').charAt(0)) + '</span>')
        + '<span>' + esc(k.first_name + ' ' + (k.last_name || '')) + '</span></button>').join('')
        || '<div style="color:#64748B;padding:16px;text-align:center;">' + (kids.length ? 'No match.' : 'No children in this room.') + '</div>';
    };
    paint('');
    const q = d.el.querySelector('[data-q]');
    q.oninput = () => paint(q.value);
    setTimeout(() => { try { q.focus(); } catch (e) {} }, 50);
    list.onclick = async (e) => {
      const b = e.target.closest('[data-kid]'); if (!b) return;
      b.disabled = true;
      try {
        await Api.post('/zones/visit', { child_id: +b.dataset.kid, zone_id: +zoneId });
        d.close(); zToast('Visit logged', 'success'); renderZones(document.getElementById('appMain') || main);
      } catch (err) { b.disabled = false; zToast(err.message || 'Could not log the visit', 'error'); }
    };
  }

  function openZoneModal(centreId, main) {
    const o = zState.opts || {};
    const rooms = (o.rooms || []).filter(r => r.centre_id === centreId);
    const roomSel = '<select id="z-room">' + (o.can_create_whole_centre ? '<option value="">Whole centre</option>' : '')
      + rooms.map(r => '<option value="' + r.id + '"' + (String(zState.roomId) === String(r.id) ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('') + '</select>';
    const d = zDialog('New activity zone',
      '<label for="z-name">Name</label><input id="z-name" placeholder="Art corner, Reading nook, Sensory…" maxlength="80">'
      + '<label for="z-room">Where</label>' + roomSel
      + '<label for="z-icon">Icon (emoji)</label><input id="z-icon" value="🎨" maxlength="8">'
      + '<label for="z-color">Colour</label><input id="z-color" type="color" value="#A855F7" style="height:38px;padding:3px">',
      '<button type="button" class="kt-btn" data-cancel>Cancel</button><button type="button" class="kt-btn kt-btn-primary" data-save>Save</button>');
    d.el.querySelector('[data-cancel]').onclick = d.close;
    d.el.querySelector('[data-save]').onclick = async () => {
      const name = d.el.querySelector('#z-name').value.trim();
      if (!name) { zToast('Give the zone a name', 'error'); return; }
      const room = d.el.querySelector('#z-room').value;
      try {
        await Api.post('/zones', { centre_id: centreId, room_id: room ? +room : null, name, icon: d.el.querySelector('#z-icon').value, color: d.el.querySelector('#z-color').value });
        d.close(); zToast('Zone added', 'success'); renderZones(main || document.getElementById('appMain'));
      } catch (e) { zToast(e.message || 'Could not add the zone', 'error'); }
    };
  }

  // ============================ Support tickets ============================
  async function renderTickets(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    /* The numbers alongside the list (2026-10-28, Anthony: "for support tickets add some graphs and sla's"):
       GET /tickets/stats, scoped exactly like the list. */
    const [r, st] = await Promise.all([
      Api.get('/tickets').catch(() => ({ data: [] })),
      Api.get('/tickets/stats').catch(() => null),
    ]);
    if (!main.isConnected) { return; }
    const all = r.data || [];
    const slaOf = (st && st.open_sla) || {};
    const me = (tkUser() || {}).id;
    /* All by default (2026-10-23, Anthony: "not showing all of the tickets as default"). */
    let view = 'all';
    let q = '';
    let mine = false;
    const counts = {
      open: all.filter(t => t.status === 'open' || t.status === 'awaiting_user').length,
      done: all.filter(t => t.status === 'resolved' || t.status === 'closed').length,
      all: all.length,
    };
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🎫 Support tickets</h2>
        <p>Ask for help, report a problem or request a change. Track every request to its answer.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="tk-new">+ Raise ticket</button></div>
      </div>
      ${tkDashboard(st)}
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px;">
        <div id="tk-tabs" role="tablist" aria-label="Show" style="display:inline-flex;border:1px solid #CBD5E1;border-radius:9px;overflow:hidden;min-height:32px;">
          ${[['all', 'All', counts.all], ['open', 'Open', counts.open], ['done', 'Resolved', counts.done]].map(v =>
            `<button type="button" role="tab" data-v="${v[0]}" style="border:0;padding:0 14px;font:inherit;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">${v[1]} <span style="opacity:.6;">${v[2]}</span></button>`).join('')}
        </div>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer;">
          <input type="checkbox" id="tk-mine" style="width:15px;height:15px;"> Raised by me</label>
        <input id="tk-q" type="search" placeholder="Search tickets…" style="margin-left:auto;height:32px;min-width:220px;padding:0 11px;border:1px solid #CBD5E1;border-radius:9px;font:inherit;font-size:13.5px;">
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Ticket</th><th>Summary</th><th>Request</th><th>Severity</th><th>Status</th><th>Raised by</th><th>Updated</th></tr></thead>
          <tbody id="tk-rows"></tbody>
        </table>
      </div>
    </div>`;
    const rowsEl = main.querySelector('#tk-rows');
    function paint() {
      main.querySelectorAll('#tk-tabs button').forEach(b => {
        const on = b.dataset.v === view;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.style.background = on ? '#1F6080' : '#fff';
        b.style.color = on ? '#fff' : '#475569';
      });
      const needle = q.toLowerCase();
      const rows = all.filter(t => {
        const isDone = t.status === 'resolved' || t.status === 'closed';
        if (view === 'open' && isDone) return false;
        if (view === 'done' && !isDone) return false;
        if (mine && +t.raised_by_user_id !== +me) return false;
        if (!needle) return true;
        return ('#' + t.id + ' ' + (t.subject || '') + ' ' + (t.body || '') + ' ' + (t.raised_by_name || '') + ' ' + tkCat(t.category, t.request_type))
          .toLowerCase().indexOf(needle) !== -1;
      });
      rowsEl.innerHTML = rows.map(t => `<tr style="cursor:pointer;" data-open="${t.id}"><td style="font-weight:800;color:#475569;white-space:nowrap;">#${t.id}</td>
            <td><strong>${esc(t.subject)}</strong></td>
            <td style="white-space:nowrap;"><span class="kt-pill kt-pill-info">${tkIcon(t.request_type, t.category)} ${esc(tkCat(t.category, t.request_type))}</span></td>
            <td><span class="kt-pill ${tkSev(t.priority).cls}">${esc(tkSev(t.priority).label)}</span></td>
            <td><span class="kt-pill ${tkStatus(t.status).cls}">${esc(tkStatus(t.status).label)}</span>${tkSlaChip(slaOf[t.id])}</td>
            <td>${esc(t.raised_by_name || '—')}</td>
            <td style="white-space:nowrap;">${fmtStamp(t.updated_at || t.created_at)}</td></tr>`).join('')
        || `<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">${all.length ? 'No tickets match.' : 'No tickets yet. Use “Raise ticket” whenever you need a hand.'}</td></tr>`;
      rowsEl.querySelectorAll('tr[data-open]').forEach(tr => tr.onclick = () => openTicket(+tr.dataset.open));
    }
    main.querySelectorAll('#tk-tabs button').forEach(b => b.onclick = () => { view = b.dataset.v; paint(); });
    main.querySelector('#tk-mine').onchange = (e) => { mine = e.target.checked; paint(); };
    main.querySelector('#tk-q').oninput = (e) => { q = e.target.value.trim(); paint(); };
    paint();
    document.getElementById('tk-new').onclick = () => openTicketModal();
  }
  /* ── TICKET DASHBOARD + SLAs (2026-10-28) ────────────────────────────────────────
     Anthony: "for support tickets add some graphs and sla's and etc to the support tickets view - full sweep".
     Five figures (open, breaching SLA, first response, resolution, SLA met), created-vs-resolved by week, the
     commonest requests, how old the open ones are, and the SLA targets themselves - so "breaching" is never a
     mystery. Each open ticket in the list carries its own SLA chip. */
  function tkHrs(h) {
    if (h == null) { return '—'; }
    if (h < 1) { return Math.max(1, Math.round(h * 60)) + 'm'; }
    if (h < 48) { return (Math.round(h * 10) / 10) + 'h'; }
    return Math.round(h / 24) + 'd';
  }
  function tkSlaChip(x) {
    if (!x) { return ''; }
    const m = { breached: ['#FEE2E2', '#B91C1C', 'SLA breached'], soon: ['#FEF3C7', '#92400E', 'SLA due soon'], ok: ['#DCFCE7', '#166534', 'Within SLA'] }[x.state] || null;
    if (!m) { return ''; }
    const due = new Date(x.responded ? x.resolve_due : x.respond_due);
    const tip = (x.responded ? 'Resolve by ' : 'First reply by ') + due.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    return '<span title="' + esc(tip) + '" style="display:inline-block;margin-left:6px;font-size:10.5px;font-weight:800;border-radius:999px;padding:2px 8px;background:' + m[0] + ';color:' + m[1] + ';white-space:nowrap;">' + m[2] + '</span>';
  }
  function tkBars(series, ca, cb) {
    let max = 0; series.forEach(function (x) { max = Math.max(max, x.a || 0, x.b || 0); });
    if (!max) { return '<div style="font-size:13px;color:#64748B;padding:14px 0;">Nothing in this period.</div>'; }
    const W = 600, H = 130, n = series.length, gw = W / n, bw = Math.min(14, gw * 0.3);
    const y = function (v) { return 6 + (H - 8) * (1 - v / max); };
    let svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" height="' + H + '" preserveAspectRatio="none" style="display:block">';
    [0.5, 1].forEach(function (f) { svg += '<line x1="0" x2="' + W + '" y1="' + y(max * f) + '" y2="' + y(max * f) + '" stroke="#EEF2F7"/>'; });
    series.forEach(function (x, i) {
      const cx = gw * i + gw / 2;
      svg += '<g><title>' + esc(x.tip || '') + '</title><rect x="' + (cx - gw / 2) + '" y="0" width="' + gw + '" height="' + H + '" fill="transparent"/>';
      if (x.a) { svg += '<rect x="' + (cx - bw - 1) + '" y="' + y(x.a) + '" width="' + bw + '" height="' + (H - 2 - y(x.a)) + '" rx="3" fill="' + ca + '"/>'; }
      if (x.b) { svg += '<rect x="' + (cx + 1) + '" y="' + y(x.b) + '" width="' + bw + '" height="' + (H - 2 - y(x.b)) + '" rx="3" fill="' + cb + '"/>'; }
      svg += '</g>';
    });
    svg += '<line x1="0" x2="' + W + '" y1="' + (H - 2) + '" y2="' + (H - 2) + '" stroke="#CBD5E1"/></svg>';
    return svg + '<div style="display:flex;margin-top:4px;">' + series.map(function (x) { return '<div style="flex:1;text-align:center;font-size:10px;color:#64748B;font-weight:600;white-space:nowrap;overflow:hidden;">' + esc(x.label) + '</div>'; }).join('') + '</div>';
  }
  function tkDashboard(st) {
    if (!st || !st.tiles) { return ''; }
    const t = st.tiles;
    const tile = function (icon, label, value, sub, tint, ink) {
      return '<div style="background:' + tint + ';border:1px solid rgba(15,23,42,.06);border-radius:14px;padding:12px 14px;min-width:0;">'
        + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + ink + ';opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + icon + ' ' + esc(label) + '</div>'
        + '<div style="font-size:22px;font-weight:900;color:' + ink + ';margin-top:6px;line-height:1.1;">' + value + '</div>'
        + '<div style="font-size:11.5px;color:#64748B;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(sub) + '</div></div>';
    };
    const pct = function (v) { return v == null ? '—' : v + '%'; };
    const tiles = '<div class="tk-dash-tiles" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 12px;" data-kt-nosechead="1">'
      + tile('📬', 'Open', t.open, t.new_30 + ' new · ' + t.resolved_30 + ' resolved in 30 days', '#EFF6FF', '#1D4ED8')
      + tile('⏰', 'Breaching SLA', t.breaching, t.breaching ? 'Open tickets past their target' : 'Every open ticket is on time', t.breaching ? '#FEF2F2' : '#F0FDF4', t.breaching ? '#B91C1C' : '#15803D')
      + tile('💬', 'First response', tkHrs(t.median_first_response_h), 'Median · ' + pct(t.response_sla_pct) + ' within SLA', '#F5F3FF', '#6D28D9')
      + tile('✅', 'Resolution', tkHrs(t.median_resolution_h), 'Median · ' + pct(t.resolve_sla_pct) + ' within SLA', '#ECFDF5', '#047857')
      + tile('🎯', 'SLA met', pct(t.resolve_sla_pct == null ? t.response_sla_pct : Math.round(((t.response_sla_pct || 0) + (t.resolve_sla_pct || 0)) / 2)), t.measured_on || '', '#FFFBEB', '#B45309')
      + '</div>';
    const weekly = (st.weekly || []).map(function (w) { return { label: w.label, a: w.created, b: w.resolved, tip: 'Week of ' + w.label + ': ' + w.created + ' raised, ' + w.resolved + ' resolved' }; });
    const catMax = Math.max.apply(null, (st.categories || []).map(function (c) { return c.count; }).concat([1]));
    const cats = (st.categories || []).map(function (c) {
      return '<div style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:12.5px;"><span style="flex:0 0 120px;color:#334155;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + tkIcon(c.key, c.key) + ' ' + esc(tkCat(c.key, c.key)) + '</span>'
        + '<span style="flex:1;background:#EEF2F7;border-radius:999px;height:9px;overflow:hidden;"><span style="display:block;height:100%;width:' + Math.round(100 * c.count / catMax) + '%;background:linear-gradient(90deg,#38BDF8,#6366F1);border-radius:999px;"></span></span>'
        + '<span style="flex:0 0 auto;font-weight:800;color:#0F172A;min-width:24px;text-align:right;">' + c.count + '</span></div>';
    }).join('') || '<div style="font-size:13px;color:#64748B;">No tickets yet.</div>';
    const ag = st.aging || {}, agTotal = Object.keys(ag).reduce(function (a, k) { return a + ag[k]; }, 0) || 1;
    const agCols = ['#22C55E', '#F59E0B', '#F97316', '#DC2626'];
    const aging = '<div style="display:flex;height:12px;border-radius:999px;overflow:hidden;background:#EEF2F7;margin:6px 0 8px;">' + Object.keys(ag).map(function (k, i) { return ag[k] ? '<span title="' + esc(k + ': ' + ag[k]) + '" style="width:' + (100 * ag[k] / agTotal) + '%;background:' + agCols[i] + ';"></span>' : ''; }).join('') + '</div>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;font-size:11.5px;color:#475569;">' + Object.keys(ag).map(function (k, i) { return '<span style="display:inline-flex;align-items:center;gap:4px;"><i style="width:8px;height:8px;border-radius:2px;background:' + agCols[i] + ';display:inline-block;"></i>' + esc(k) + ' <b>' + ag[k] + '</b></span>'; }).join('') + '</div>';
    const tg = st.targets || {};
    const targets = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:4px;" data-kt-no-filter data-kt-no-export><tr style="color:#64748B;"><td style="padding:3px 0;font-weight:800;">Priority</td><td style="font-weight:800;">First reply</td><td style="font-weight:800;">Resolve</td></tr>'
      + Object.keys(tg).map(function (k) { return '<tr><td style="padding:3px 0;font-weight:700;color:#0F172A;">' + esc(tg[k].label) + '</td><td>' + tkHrs(tg[k].respond_h) + '</td><td>' + tkHrs(tg[k].resolve_h) + '</td></tr>'; }).join('') + '</table>';
    const card = function (title, sub, body) { return '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:12px 14px;min-width:0;"><div style="font-weight:800;font-size:13.5px;color:#0F172A;">' + title + '</div><div style="font-size:11.5px;color:#64748B;margin:1px 0 6px;">' + sub + '</div>' + body + '</div>'; };
    return '<div data-kt-nosechead="1">' + tiles
      + '<div class="tk-dash-row" style="display:grid;grid-template-columns:2fr 1.2fr 1fr;gap:10px;margin:0 0 14px;">'
      + card('Raised vs resolved', 'Last 12 weeks <span style="margin-left:8px;"><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#6366F1;"></i> Raised <i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#22C55E;margin-left:6px;"></i> Resolved</span>', tkBars(weekly, '#6366F1', '#22C55E'))
      + card('Most common requests', 'Last 12 months', cats)
      + card('Open tickets by age', 'How long they have been waiting', aging) + '</div>'
      + '<details style="margin:-6px 0 14px;"><summary style="cursor:pointer;font-size:12.5px;font-weight:700;color:#1F6080;">🎯 SLA targets (clock hours, from when a ticket is raised)</summary><div style="max-width:360px;background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:8px 12px;margin-top:6px;">' + targets + '</div></details>'
      + '<style>@media(max-width:1000px){.tk-dash-tiles{grid-template-columns:repeat(2,minmax(0,1fr)) !important;}.tk-dash-row{grid-template-columns:1fr !important;}}</style>'
      + '</div>';
  }

  /* ── SERVICE DESK (2026-10-21) ───────────────────────────────────────────────────
     Anthony: "raise a ticket should have categories and better input to allowing the user
     to enter in information - mimic jira service desk". Step 1 asks WHAT the person needs,
     as cards; step 2 asks that request type's own questions, then how much it is hurting
     (which sets the priority), takes screenshots by drag, paste or picker, and quietly
     records where they came from. The server keeps the answers as [{label, value}] so the
     ticket reads back exactly as it was asked. */
  const TK_TYPES = [
    { id: 'problem', icon: '🐞', title: 'Report a problem', blurb: 'Something in KiddieTrac is not working the way it should.', group: 'app',
      summary: 'e.g. Photos will not upload on the Today screen',
      fields: [
        { key: 'where', label: 'Where did it happen?', type: 'screen' },
        { key: 'doing', label: 'What were you trying to do?', type: 'textarea', rows: 2, ph: 'e.g. Sign Mila in at drop-off' },
        { key: 'happened', label: 'What happened instead?', type: 'textarea', rows: 3, req: true, ph: 'Any message on screen, exactly as it read, helps most.' },
        { key: 'steps', label: 'Steps to make it happen again (if you know them)', type: 'textarea', rows: 2, ph: '1. Open Today  2. Tap a child  3. …' },
        { key: 'freq', label: 'How often?', type: 'chips', opts: ['Just once', 'Sometimes', 'Every time'] },
        { key: 'who', label: 'Who is affected?', type: 'chips', opts: ['Just me', 'A few people', 'Everyone at our agency'] },
      ] },
    { id: 'question', icon: '❓', title: 'How do I…?', blurb: 'Ask how to do something in KiddieTrac.', group: 'app',
      summary: 'e.g. How do I send an invoice to two parents?',
      fields: [
        { key: 'area', label: 'Which part of KiddieTrac?', type: 'screen' },
        { key: 'question', label: 'Your question', type: 'textarea', rows: 4, req: true },
      ] },
    { id: 'idea', icon: '💡', title: 'Suggest an improvement', blurb: 'An idea that would make your day easier.', group: 'app',
      summary: 'e.g. Let parents choose a pick-up person for the day',
      fields: [
        { key: 'area', label: 'Which part of KiddieTrac?', type: 'screen' },
        { key: 'idea', label: 'What would you like?', type: 'textarea', rows: 3, req: true },
        { key: 'why', label: 'How would it help?', type: 'textarea', rows: 2, ph: 'Who would use it, and what would it save?' },
      ] },
    { id: 'account', icon: '🔐', title: 'Account & sign-in', blurb: 'Signing in, passwords, or who can see what.', group: 'agency',
      summary: 'e.g. New educator needs access to our home',
      fields: [
        { key: 'issue', label: 'What do you need?', type: 'select', req: true, opts: ['I cannot sign in', 'Reset a password', 'Someone needs access', 'Remove someone’s access', 'Wrong role or permissions', 'Change an email or phone number', 'Something else'] },
        { key: 'person', label: 'Who is it for? (leave blank if it is you)', type: 'text', ph: 'Name and email' },
        { key: 'details', label: 'Anything else we should know?', type: 'textarea', rows: 3 },
      ] },
    { id: 'billing', icon: '💳', title: 'Billing & payments', blurb: 'Invoices, payments, refunds, fees and subsidy.', group: 'agency',
      summary: 'e.g. October invoice charged twice',
      fields: [
        { key: 'issue', label: 'What is it about?', type: 'select', req: true, opts: ['A question about an invoice', 'A payment did not go through', 'A refund', 'Fees, CWELCC or subsidy', 'Setting up autopay', 'Something else'] },
        { key: 'ref', label: 'Family, child or invoice number', type: 'text', ph: 'e.g. Hosein family · INV-0042' },
        { key: 'amount', label: 'Amount involved (if any)', type: 'text', ph: '$' },
        { key: 'details', label: 'What happened?', type: 'textarea', rows: 3, req: true },
      ] },
    { id: 'enrollment', icon: '📝', title: 'Enrollment & families', blurb: 'New children, schedule changes, moves and withdrawals.', group: 'agency',
      summary: 'e.g. Change Aria to three days a week from November',
      fields: [
        { key: 'issue', label: 'What do you need?', type: 'select', req: true, opts: ['A new enrollment', 'A change of schedule', 'A move to another provider', 'A withdrawal', 'The waitlist', 'Something else'] },
        { key: 'child', label: 'Child or family', type: 'text', ph: 'Name' },
        { key: 'when', label: 'From when?', type: 'date' },
        { key: 'details', label: 'Details', type: 'textarea', rows: 3, req: true },
      ] },
    { id: 'maintenance', icon: '🛠️', title: 'Maintenance / repair', blurb: 'Something at a home or centre needs fixing.', group: 'agency', staffOnly: true,
      summary: 'e.g. Back gate latch is broken',
      fields: [
        { key: 'centre', label: 'Which home or centre?', type: 'centre' },
        { key: 'location', label: 'Where exactly?', type: 'text', ph: 'e.g. Backyard gate' },
        { key: 'hazard', label: 'Could someone get hurt?', type: 'chips', opts: ['No', 'Yes — it is a hazard now'] },
        { key: 'details', label: 'What needs fixing?', type: 'textarea', rows: 3, req: true },
      ] },
    { id: 'policy', icon: '📋', title: 'Policy & compliance', blurb: 'Licensing, ratios, forms and agency rules.', group: 'agency', staffOnly: true,
      summary: 'e.g. Which form do we need for an off-site walk?',
      fields: [
        { key: 'topic', label: 'Topic', type: 'text', ph: 'e.g. Sleep checks, medication, ratios' },
        { key: 'question', label: 'Your question', type: 'textarea', rows: 4, req: true },
      ] },
    { id: 'other', icon: '💬', title: 'Something else', blurb: 'Anything that does not fit above.', group: 'agency',
      summary: 'A short summary',
      fields: [
        { key: 'details', label: 'How can we help?', type: 'textarea', rows: 5, req: true },
      ] },
  ];
  const TK_IMPACT = [
    { id: 'question', icon: '💬', label: 'Just a question or idea' },
    { id: 'minor', icon: '🙂', label: 'Annoying, but we can work around it' },
    { id: 'slowing', icon: '🐢', label: 'It is slowing us down' },
    { id: 'blocked', icon: '⛔', label: 'We cannot run the day' },
    { id: 'safety', icon: '🚨', label: 'A child’s safety or care is affected' },
  ];

  // The screen somebody was on before opening Support — the most useful thing a problem
  // report can carry, and the thing people least often think to mention.
  try {
    window.addEventListener('hashchange', function (e) {
      const old = String(e.oldURL || '').split('#')[1] || '';
      if (old && !/^tickets/.test(old)) { sessionStorage.setItem('kt_tk_from', old.split('?')[0]); }
    });
  } catch (e) { /* storage blocked: the form just asks */ }

  function tkScreens() {
    const out = [];
    const seen = {};
    document.querySelectorAll('#navLinks a[href^="#"], #appSidebar a[href^="#"]').forEach(function (a) {
      const h = (a.getAttribute('href') || '').slice(1);
      const t = (a.textContent || '').replace(/\s+/g, ' ').trim();
      if (h && t && !seen[h] && h !== 'tickets') { seen[h] = 1; out.push({ hash: h, label: t }); }
    });
    return out;
  }
  function tkUser() { try { return (window.KT && KT.Auth && KT.Auth.user()) || JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function tkIsStaff(u) {
    const roles = window.ktRoles ? window.ktRoles(u) : ((u && u.roles) || []);
    return ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor'].some(function (r) { return roles.indexOf(r) !== -1; });
  }
  function tkContext() {
    const ua = navigator.userAgent || '';
    const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
    const native = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    let from = '';
    try { from = sessionStorage.getItem('kt_tk_from') || ''; } catch (e) {}
    const scr = tkScreens().filter(function (s) { return s.hash === from; })[0];
    return {
      'Screen': scr ? scr.label + ' (#' + from + ')' : (from ? '#' + from : ''),
      'Device': (native ? 'KiddieTrac app · ' : '') + browser + (os ? ' on ' + os : ''),
      'Window': window.innerWidth + '×' + window.innerHeight,
      'Time zone': (Intl.DateTimeFormat().resolvedOptions().timeZone || ''),
    };
  }

  function openTicketModal(preType) {
    const user = tkUser();
    const staff = tkIsStaff(user);
    const types = TK_TYPES.filter(function (t) { return staff || !t.staffOnly; });
    const m = document.createElement('div');
    m.setAttribute('data-no-modal-guard', '1');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';
    m.innerHTML = '<div class="tk-sd" style="background:#fff;border-radius:16px;max-width:760px;width:100%;max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px -20px rgba(15,23,42,.5);"></div>';
    document.body.appendChild(m);
    const box = m.querySelector('.tk-sd');
    const close = function () { m.remove(); document.removeEventListener('paste', onPaste); };
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
    let files = [];
    let onPaste = function () {};

    const css = '<style>'
      + '.tk-sd .tk-card{display:flex;gap:12px;align-items:flex-start;text-align:left;padding:13px 14px;border:1.5px solid #E2E8F0;border-radius:12px;background:#fff;cursor:pointer;font-family:inherit;transition:border-color .12s,box-shadow .12s}'
      + '.tk-sd .tk-card:hover,.tk-sd .tk-card:focus-visible{border-color:#13B7CC;box-shadow:0 4px 14px -8px rgba(19,183,204,.7);outline:none}'
      + '.tk-sd .tk-lbl{display:block;font-size:13px;font-weight:700;color:#0F172A;margin:14px 0 5px}'
      + '.tk-sd .tk-lbl i{font-style:normal;color:#DC2626}'
      + '.tk-sd input[type=text],.tk-sd input[type=date],.tk-sd select,.tk-sd textarea{width:100%;padding:9px 11px;border:1px solid #CBD5E1;border-radius:9px;font:inherit;font-size:14px;box-sizing:border-box;background:#fff}'
      + '.tk-sd textarea{resize:vertical}'
      + '.tk-sd input:focus,.tk-sd select:focus,.tk-sd textarea:focus{outline:2px solid #13B7CC33;border-color:#13B7CC}'
      // !important: the portal's global button polish otherwise strips the padding.
      + '.tk-sd .tk-chip{padding:6px 13px !important;min-height:32px !important;border:1.5px solid #CBD5E1 !important;border-radius:999px !important;background:#fff !important;font:inherit;font-size:13px !important;cursor:pointer;line-height:1.2 !important}'
      + '.tk-sd .tk-chip[aria-pressed=true]{border-color:#1F6080 !important;background:#E0F2FE !important;color:#0C4A6E;font-weight:700}'
      + '.tk-sd .tk-imp{display:flex;gap:9px;align-items:center;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:10px;background:#fff;font:inherit;font-size:13.5px;cursor:pointer;text-align:left}'
      + '.tk-sd .tk-imp[aria-pressed=true]{border-color:#1F6080;background:#F0F9FF;font-weight:700}'
      + '.tk-sd .tk-drop{border:1.5px dashed #94A3B8;border-radius:12px;padding:14px;text-align:center;color:#475569;font-size:13px;background:#F8FAFC;cursor:pointer}'
      + '.tk-sd .tk-drop.over{border-color:#13B7CC;background:#ECFEFF}'
      + '</style>';

    function step1() {
      const groups = [
        { id: 'app', title: 'Get help with KiddieTrac', note: 'Answered by the KiddieTrac team' },
        { id: 'agency', title: 'Ask your agency', note: 'Answered by your agency’s administrators' },
      ];
      box.innerHTML = css
        + '<div style="padding:20px 22px 12px;border-bottom:1px solid #F1F5F9;display:flex;align-items:flex-start;gap:12px;">'
        + '<div style="flex:1;"><div style="font-size:12px;font-weight:800;letter-spacing:.5px;color:#13B7CC;">SUPPORT</div>'
        + '<h3 style="margin:2px 0 0;font-size:20px;color:#0F172A;">What can we help you with?</h3></div>'
        + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm tk-x" aria-label="Close">✕</button></div>'
        + '<div style="overflow-y:auto;padding:6px 22px 20px;">'
        + groups.map(function (g) {
          const list = types.filter(function (t) { return t.group === g.id; });
          if (!list.length) return '';
          return '<div style="margin-top:14px;"><div style="display:flex;align-items:baseline;gap:8px;margin-bottom:8px;">'
            + '<div style="font-size:13px;font-weight:800;color:#334155;">' + esc(g.title) + '</div>'
            + '<div style="font-size:12px;color:#94A3B8;">' + esc(g.note) + '</div></div>'
            + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;">'
            + list.map(function (t) {
              return '<button type="button" class="tk-card" data-type="' + t.id + '">'
                + '<span style="font-size:24px;line-height:1;flex:0 0 auto;">' + t.icon + '</span>'
                + '<span><span style="display:block;font-weight:800;font-size:14px;color:#0F172A;">' + esc(t.title) + '</span>'
                + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;line-height:1.35;">' + esc(t.blurb) + '</span></span></button>';
            }).join('') + '</div></div>';
        }).join('')
        + '</div>';
      box.querySelector('.tk-x').onclick = close;
      box.querySelectorAll('.tk-card').forEach(function (b) { b.onclick = function () { step2(b.dataset.type); }; });
    }

    async function step2(typeId) {
      const t = TK_TYPES.filter(function (x) { return x.id === typeId; })[0];
      if (!t) return step1();
      files = [];
      const screens = tkScreens();
      let from = '';
      try { from = sessionStorage.getItem('kt_tk_from') || ''; } catch (e) {}
      let centres = [];
      if (t.fields.some(function (f) { return f.type === 'centre'; })) {
        try { centres = ((await Api.get('/admin/centres')) || {}).centres || []; } catch (e) {}
        if (!centres.length) { try { centres = ((await Api.get('/director/centres')) || {}).centres || []; } catch (e) {} }
        if (!centres.length && user.centre) { centres = [user.centre]; }
      }
      const field = function (f) {
        const lbl = '<label class="tk-lbl" for="tkf-' + f.key + '">' + esc(f.label) + (f.req ? ' <i>*</i>' : '') + '</label>';
        if (f.type === 'textarea') return lbl + '<textarea id="tkf-' + f.key + '" data-k="' + f.key + '" rows="' + (f.rows || 3) + '" placeholder="' + esc(f.ph || '') + '"></textarea>';
        if (f.type === 'text') return lbl + '<input type="text" id="tkf-' + f.key + '" data-k="' + f.key + '" placeholder="' + esc(f.ph || '') + '">';
        if (f.type === 'date') return lbl + '<input type="date" id="tkf-' + f.key + '" data-k="' + f.key + '" style="max-width:220px;">';
        if (f.type === 'select') return lbl + '<select id="tkf-' + f.key + '" data-k="' + f.key + '"><option value="">Choose…</option>'
          + f.opts.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>';
        if (f.type === 'screen') return lbl + '<select id="tkf-' + f.key + '" data-k="' + f.key + '"><option value="">Not sure / somewhere else</option>'
          + screens.map(function (s) { return '<option value="' + esc(s.label) + '"' + (s.hash === from ? ' selected' : '') + '>' + esc(s.label) + '</option>'; }).join('') + '</select>';
        if (f.type === 'centre') return lbl + (centres.length
          ? '<select id="tkf-' + f.key + '" data-k="' + f.key + '" data-centre="1">' + (centres.length > 1 ? '<option value="">Choose…</option>' : '')
            + centres.map(function (c) { return '<option value="' + (+c.id) + '">' + esc(c.name) + '</option>'; }).join('') + '</select>'
          : '<input type="text" id="tkf-' + f.key + '" data-k="' + f.key + '" placeholder="Name of the home or centre">');
        if (f.type === 'chips') return '<div class="tk-lbl">' + esc(f.label) + '</div><div style="display:flex;flex-wrap:wrap;gap:7px;" data-chips="' + f.key + '">'
          + f.opts.map(function (o) { return '<button type="button" class="tk-chip" aria-pressed="false">' + esc(o) + '</button>'; }).join('') + '</div>';
        return '';
      };
      const ctx = tkContext();
      box.innerHTML = css
        + '<div style="padding:18px 22px 12px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:12px;">'
        + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm tk-back" aria-label="Back">←</button>'
        + '<span style="font-size:24px;">' + t.icon + '</span>'
        + '<div style="flex:1;min-width:0;"><h3 style="margin:0;font-size:18px;color:#0F172A;">' + esc(t.title) + '</h3>'
        + '<div style="font-size:12.5px;color:#64748B;">' + (t.group === 'app' ? 'Goes to the KiddieTrac team' : 'Goes to your agency’s administrators') + '</div></div>'
        + '<button type="button" class="kt-btn kt-btn-secondary kt-btn-sm tk-x" aria-label="Close">✕</button></div>'
        + '<div style="overflow-y:auto;padding:4px 22px 18px;">'
        + '<label class="tk-lbl" for="tk-sub">Summary <i>*</i></label>'
        + '<input type="text" id="tk-sub" maxlength="200" placeholder="' + esc(t.summary) + '">'
        + t.fields.map(field).join('')
        + '<div class="tk-lbl">How is this affecting you?</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:7px;" id="tk-impact">'
        + TK_IMPACT.map(function (i) { return '<button type="button" class="tk-imp" data-imp="' + i.id + '" aria-pressed="' + ((t.group === 'app' && t.id !== 'problem' ? 'question' : 'minor') === i.id) + '"><span style="font-size:17px;">' + i.icon + '</span>' + esc(i.label) + '</button>'; }).join('')
        + '</div>'
        + '<div class="tk-lbl">Screenshots or files</div>'
        + '<div class="tk-drop" tabindex="0" role="button">📎 <strong>Drop files here</strong>, paste a screenshot (Ctrl+V), or <u>choose files</u>'
        + '<div style="font-size:11.5px;color:#94A3B8;margin-top:3px;">Images, PDFs, logs, CSV or zip · up to 10 MB each</div></div>'
        + '<input type="file" id="tk-files" multiple accept="image/*,.pdf,.txt,.log,.csv,.json,.xml,.zip" style="display:none;">'
        + '<div id="tk-file-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;"></div>'
        + '<label class="tk-lbl" for="tk-contact">Best way to reach you</label>'
        + '<input type="text" id="tk-contact" value="' + esc([user.email, user.phone].filter(Boolean).join(' · ')) + '">'
        + '<details style="margin-top:14px;font-size:12.5px;color:#64748B;"><summary style="cursor:pointer;">Sent automatically with your request</summary>'
        + '<div style="margin-top:6px;line-height:1.6;">' + Object.keys(ctx).filter(function (k) { return ctx[k]; }).map(function (k) { return '<b>' + esc(k) + ':</b> ' + esc(ctx[k]); }).join('<br>') + '</div></details>'
        + '</div>'
        + '<div style="border-top:1px solid #F1F5F9;padding:12px 22px;display:flex;align-items:center;gap:8px;background:#FCFDFE;">'
        + '<div id="tk-msg" style="flex:1;font-size:13px;min-width:0;"></div>'
        + '<button type="button" class="kt-btn kt-btn-secondary tk-x2">Cancel</button>'
        + '<button type="button" class="kt-btn kt-btn-primary" id="tk-save">Send request</button></div>';

      box.querySelector('.tk-x').onclick = close;
      box.querySelector('.tk-x2').onclick = close;
      box.querySelector('.tk-back').onclick = function () { document.removeEventListener('paste', onPaste); step1(); };
      box.querySelectorAll('[data-chips]').forEach(function (g) {
        g.querySelectorAll('.tk-chip').forEach(function (c) {
          c.onclick = function () { g.querySelectorAll('.tk-chip').forEach(function (x) { x.setAttribute('aria-pressed', x === c && c.getAttribute('aria-pressed') !== 'true' ? 'true' : 'false'); }); };
        });
      });
      box.querySelectorAll('.tk-imp').forEach(function (b) {
        b.onclick = function () { box.querySelectorAll('.tk-imp').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); };
      });
      // A hazard at a home is a safety matter whatever the impact says.
      const hz = box.querySelector('[data-chips="hazard"]');
      if (hz) {
        hz.addEventListener('click', function () {
          const yes = hz.querySelector('.tk-chip[aria-pressed="true"]');
          if (yes && /Yes/.test(yes.textContent)) { box.querySelector('.tk-imp[data-imp="safety"]').click(); }
        });
      }

      const input = box.querySelector('#tk-files');
      const drop = box.querySelector('.tk-drop');
      const listEl = box.querySelector('#tk-file-list');
      const addFiles = function (list) {
        Array.prototype.forEach.call(list || [], function (f) {
          if (f.size > 10 * 1024 * 1024) { say('“' + f.name + '” is over 10 MB, so it was left out.', '#B45309'); return; }
          files.push(f);
        });
        paintFiles();
      };
      const paintFiles = function () {
        listEl.innerHTML = '';
        files.forEach(function (f, i) {
          const chip = document.createElement('div');
          chip.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #E2E8F0;border-radius:10px;background:#fff;max-width:100%;';
          const isImg = /^image\//.test(f.type || '');
          chip.innerHTML = (isImg ? '<img alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">' : '<span style="font-size:20px;">📄</span>')
            + '<span style="font-size:12.5px;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(f.name) + '</span>'
            + '<button type="button" aria-label="Remove" style="border:0;background:none;cursor:pointer;color:#94A3B8;font-size:15px;">✕</button>';
          if (isImg) { try { chip.querySelector('img').src = URL.createObjectURL(f); } catch (e) {} }
          chip.querySelector('button').onclick = function () { files.splice(i, 1); paintFiles(); };
          listEl.appendChild(chip);
        });
      };
      drop.onclick = function () { input.click(); };
      drop.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } };
      input.onchange = function () { addFiles(input.files); input.value = ''; };
      ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
      ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
      drop.addEventListener('drop', function (e) { addFiles(e.dataTransfer && e.dataTransfer.files); });
      onPaste = function (e) {
        const items = (e.clipboardData && e.clipboardData.files) || [];
        if (items.length) {
          const named = Array.prototype.map.call(items, function (f, n) {
            try { return new File([f], f.name && f.name !== 'image.png' ? f.name : ('screenshot-' + (files.length + n + 1) + '.png'), { type: f.type }); } catch (x) { return f; }
          });
          addFiles(named);
        }
      };
      document.addEventListener('paste', onPaste);

      const msg = box.querySelector('#tk-msg');
      function say(text, color) { msg.style.color = color || '#B91C1C'; msg.textContent = text || ''; }
      setTimeout(function () { const s = box.querySelector('#tk-sub'); if (s) s.focus(); }, 30);

      box.querySelector('#tk-save').onclick = async function () {
        const btn = this;
        const subject = box.querySelector('#tk-sub').value.trim();
        if (!subject) { say('Add a short summary so it can be found again.'); box.querySelector('#tk-sub').focus(); return; }
        const details = [];
        let centreId = null;
        for (const f of t.fields) {
          let v = '';
          if (f.type === 'chips') {
            const on = box.querySelector('[data-chips="' + f.key + '"] .tk-chip[aria-pressed="true"]');
            v = on ? on.textContent : '';
          } else {
            const el = box.querySelector('[data-k="' + f.key + '"]');
            if (!el) continue;
            v = (el.value || '').trim();
            if (el.dataset.centre === '1' && v) { centreId = +v; v = el.options[el.selectedIndex].textContent; }
          }
          if (f.req && !v) { say('Please fill in “' + f.label + '”.'); const el2 = box.querySelector('[data-k="' + f.key + '"]'); if (el2) el2.focus(); return; }
          if (v) details.push({ label: f.label.replace(/\s*\(.*\)\s*$/, '').replace(/\?$/, ''), value: v });
        }
        const contact = box.querySelector('#tk-contact').value.trim();
        if (contact) details.push({ label: 'Reach me at', value: contact });
        const imp = box.querySelector('.tk-imp[aria-pressed="true"]');

        btn.disabled = true; say('Sending…', '#64748B');
        try {
          const res = await Api.post('/tickets', {
            request_type: t.id, subject: subject, details: details, context: tkContext(),
            impact: imp ? imp.dataset.imp : 'minor', centre_id: centreId,
          });
          const id = (res && (res.id || res.ticket_id)) || null;
          let failed = 0;
          if (id && files.length) {
            say('Uploading ' + files.length + ' file' + (files.length === 1 ? '' : 's') + '…', '#64748B');
            for (const f of files) {
              const fd = new FormData();
              fd.append('file', f, f.name);
              try { await Api.post('/tickets/' + id + '/files', fd); } catch (e) { failed++; }
            }
          }
          document.removeEventListener('paste', onPaste);
          box.innerHTML = css + '<div style="padding:36px 26px;text-align:center;">'
            + '<div style="font-size:44px;">✅</div>'
            + '<h3 style="margin:8px 0 4px;font-size:20px;color:#0F172A;">Request #' + id + ' sent</h3>'
            + '<p style="margin:0 auto;max-width:440px;color:#475569;font-size:14px;line-height:1.55;">'
            + (res && res.routed_to === 'kiddietrac' ? 'The KiddieTrac team has it' : 'Your agency’s administrators have it')
            + ' and you will be emailed when someone replies. You can follow it under Support tickets.'
            + (failed ? '<br><span style="color:#B45309;">' + failed + ' file' + (failed === 1 ? '' : 's') + ' could not be attached — add them from the ticket.</span>' : '') + '</p>'
            + '<div style="display:flex;gap:8px;justify-content:center;margin-top:18px;">'
            + '<button type="button" class="kt-btn kt-btn-secondary tk-done">Done</button>'
            + '<button type="button" class="kt-btn kt-btn-primary tk-view">View request</button></div></div>';
          box.querySelector('.tk-done').onclick = function () { close(); refreshTickets(); };
          box.querySelector('.tk-view').onclick = function () { close(); refreshTickets(); if (id) openTicket(id); };
        } catch (e) {
          btn.disabled = false;
          say((e && e.message) || 'Could not send that. Please try again.');
        }
      };
    }

    if (preType) { step2(preType); } else { step1(); }
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
    documentation: 'Help guide feedback', other: 'Something else',
    question: 'How do I…?', feature: 'Suggestion', account: 'Account & sign-in',
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
  function tkCat(v, type) {
    if (type === 'enhancement') return 'Enhancement request (Agency plan)';   // from the Licence page, 2026-10-24
    const t = type && TK_TYPES.filter(x => x.id === type)[0];
    return t ? t.title : (TK_CAT[v] || String(v || '—').replace(/_/g, ' '));
  }
  function tkIcon(type, cat) {
    if (type === 'enhancement') return '✨';
    const t = type && TK_TYPES.filter(x => x.id === type)[0];
    if (t) return t.icon;
    return { technical: '🐞', crash: '💥', billing: '💳', enrollment: '📝', maintenance: '🛠️', policy: '📋', documentation: '📖' }[cat] || '💬';
  }
  function tkSev(v) { return TK_SEV[v] || { label: String(v || '—'), cls: 'kt-pill-info' }; }
  // A pill for the ticket popup. The popup hangs off <body>, outside [data-kt-pretty], where the
  // .kt-pill-* colours live (kt-design-v22p65.css) - so the same tones, inline.
  const TK_POP_TONE = {
    'kt-pill-success': ['#DCFCE7', '#BBF7D0', '#15803D', 'rgba(21,128,61,.16)'],
    'kt-pill-warning': ['#FEF3C7', '#FDE68A', '#92400E', 'rgba(146,64,14,.16)'],
    'kt-pill-danger': ['#FEE2E2', '#FECACA', '#B91C1C', 'rgba(185,28,28,.16)'],
    'kt-pill-info': ['#DBEAFE', '#BFDBFE', '#1E40AF', 'rgba(30,64,175,.16)'],
  };
  function tkPopPill(o) {
    const c = TK_POP_TONE[o.cls] || TK_POP_TONE['kt-pill-info'];
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12.5px;font-weight:700;'
      + 'background:linear-gradient(135deg,' + c[0] + ',' + c[1] + ');color:' + c[2] + ';border:1px solid ' + c[3] + ';">'
      + esc(o.label) + '</span>';
  }

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

  /* Structured answers as a labelled list; plain body for older tickets. The body of a
     new ticket repeats the answers as text for email and search, so it is not shown twice. */
  function tkBodyHtml(t) {
    let details = [];
    let ctx = {};
    try { details = JSON.parse(t.details || '[]') || []; } catch (e) {}
    try { ctx = JSON.parse(t.context || '{}') || {}; } catch (e) {}
    if (!details.length) {
      return t.body ? esc(t.body) : '<em style="color:#94A3B8;">Nothing was written in the description.</em>';
    }
    const imp = TK_IMPACT.filter(i => i.id === t.impact)[0];
    return '<div style="white-space:normal;display:grid;gap:10px;">'
      + details.map(d => '<div><div style="font-size:12px;font-weight:800;color:#64748B;">' + esc(d.label) + '</div>'
        + '<div style="white-space:pre-wrap;">' + esc(d.value) + '</div></div>').join('')
      + (imp ? '<div><div style="font-size:12px;font-weight:800;color:#64748B;">Impact</div><div>' + imp.icon + ' ' + esc(imp.label) + '</div></div>' : '')
      + (Object.keys(ctx).length ? '<div style="font-size:12px;color:#94A3B8;border-top:1px dashed #E2E8F0;padding-top:8px;">Sent from: '
        + Object.keys(ctx).map(k => esc(k) + ' ' + esc(ctx[k])).join(' · ') + '</div>' : '')
      + '</div>';
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
    // Ticket number, status and severity lead the facts (2026-11-01, Anthony: the popup header is the issue
    // title only; the rest stays visible, here).
    const facts = [
      ['Ticket', '#' + esc(String(t.id))],
      ['Status', tkPopPill(st)],
      ['Severity', tkPopPill(sev)],
      ['Raised by', esc(t.raised_by_name || '—')],
      ['Raised', esc(fmtStamp(t.created_at))],
      ['Request', tkIcon(t.request_type, t.category) + ' ' + esc(tkCat(t.category, t.request_type))],
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
          <div style="flex:1;min-width:0;align-self:center;">
            <h3 style="margin:0;font-size:19px;line-height:1.3;color:#0F172A;">${esc(t.subject)}</h3>
          </div>
          <button id="tk-x" class="kt-btn kt-btn-secondary kt-btn-sm" type="button"
            aria-label="Close" style="flex:0 0 auto;">✕</button>
        </div>
      </div>

      <div style="overflow-y:auto;flex:1 1 auto;padding:0 24px 4px;">

        ${done ? `<div style="margin:18px 0 0;padding:14px 16px;background:#ECFDF5;
          border:1px solid #A7F3D0;--kt-edge:#10B981;border-radius:10px;">
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
${tkBodyHtml(t)}</div>
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
          <div style="background:#F8FAFC;border-radius:8px;padding-left:16px;margin-left:5px;">

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
                  white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding-left:10px;">${esc(item.d.note)}</div>` : ''}
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
          } else if (window.KT && KT.openFile) {
            prev.innerHTML = '';
            KT.openFile(fetch(url).then((r) => r.blob()), { title: btn.dataset.name || btn.textContent.trim() || 'Attachment', filename: btn.dataset.name || btn.textContent.trim() || 'attachment', label: 'Ticket file' });
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
