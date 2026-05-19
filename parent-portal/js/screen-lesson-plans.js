/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Lesson plans (weekly grid)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }

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
  function $$(s, r) { return (r || document).querySelectorAll(s); }

  const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  const DAY_LABELS = { monday: 'Mon', tuesday: 'Tue', wednesday: 'Wed', thursday: 'Thu', friday: 'Fri' };
  const DOMAINS = [
    { v: 'social_emotional',  l: '💛 Social/Emotional',   c: '#FCD34D' },
    { v: 'physical',          l: '🏃 Physical',           c: '#FB923C' },
    { v: 'language_literacy', l: '📚 Language/Literacy',  c: '#60A5FA' },
    { v: 'cognitive',         l: '🧠 Cognitive',          c: '#A78BFA' },
    { v: 'creative_arts',     l: '🎨 Creative Arts',      c: '#F472B6' },
    { v: 'self_care',         l: '🧼 Self Care',          c: '#34D399' },
    { v: 'outdoor',           l: '🌳 Outdoor',            c: '#86EFAC' },
  ];

  function mondayOf(date) {
    const d = new Date(date);
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    return d.toISOString().split('T')[0];
  }

  let activeRoomId = null;
  let activeWeek = mondayOf(new Date());
  let currentPlan = null;
  let currentTheme = '';

  async function getRooms() {
    const me = getUser();
    const role = (me && (me.primary_role || (me.roles && me.roles[0]))) || '';
    // v21.1: only agency_admin can hit /admin/centres; everyone else uses /provider/bootstrap.
    if (role === 'agency_admin') {
      try {
        const res = await api('GET', '/admin/centres');
        const rooms = [];
        (res.centres || []).forEach(c => {
          (c.rooms || []).forEach(r => rooms.push({ ...r, centre_name: c.name }));
        });
        if (rooms.length > 0) return rooms;
      } catch (e) {}
    }
    try {
      const res = await api('GET', '/provider/bootstrap');
      return res.rooms || [];
    } catch (e) {
      return [];
    }
  }

  async function renderProvider(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading lesson planner…</div>';
    const rooms = await getRooms();
    if (rooms.length === 0) {
      container.innerHTML = '<div style="padding:32px;color:#DC2626;">No rooms found. Add a room first.</div>';
      return;
    }
    if (!activeRoomId || !rooms.find(r => r.id == activeRoomId)) activeRoomId = rooms[0].id;

    let plan;
    try { plan = await api('GET', '/provider/lesson-plans?room_id=' + activeRoomId + '&week_starting=' + activeWeek); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }

    currentPlan = plan.plan;
    currentTheme = plan.theme || '';

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📚 Lesson Plans</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">Weekly activities organized by HDLH domain</p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="kt-room" style="${selectStyle()}">
              ${rooms.map(r => `<option value="${r.id}" ${r.id==activeRoomId?'selected':''}>${esc(r.name)}${r.centre_name?' · '+esc(r.centre_name):''}</option>`).join('')}
            </select>
            <button id="kt-prev-week" style="${navBtnStyle()}">‹</button>
            <input type="date" id="kt-week" value="${activeWeek}" style="${selectStyle()};width:160px;">
            <button id="kt-next-week" style="${navBtnStyle()}">›</button>
            <button id="kt-save" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">💾 Save</button>
          </div>
        </div>

        <div style="background:white;border-radius:14px;padding:14px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
          <label style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#6B7280;">Weekly theme</label>
          <input id="kt-theme" placeholder="e.g. 'Spring & New Beginnings'" value="${esc(currentTheme)}" style="width:100%;padding:8px 0;border:none;outline:none;font-size:18px;font-weight:600;color:#111827;">
        </div>

        <div id="kt-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;"></div>

        <div id="kt-save-status" style="margin-top:12px;font-size:14px;min-height:20px;"></div>

        <div style="margin-top:24px;padding:14px;background:white;border-radius:12px;font-size:12px;color:#6B7280;">
          <strong style="color:#111827;">HDLH domain legend:</strong>
          <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:6px;">
            ${DOMAINS.map(d => `<span style="display:inline-flex;align-items:center;gap:4px;"><span style="display:inline-block;width:12px;height:12px;background:${d.c};border-radius:3px;"></span>${esc(d.l)}</span>`).join('')}
          </div>
        </div>
      </div>
    `;

    renderGrid();

    $('#kt-room', container).addEventListener('change', (e) => { activeRoomId = parseInt(e.target.value, 10); renderProvider(container); });
    $('#kt-week', container).addEventListener('change', (e) => { activeWeek = mondayOf(e.target.value); renderProvider(container); });
    $('#kt-prev-week', container).addEventListener('click', () => { const d = new Date(activeWeek); d.setDate(d.getDate()-7); activeWeek = mondayOf(d); renderProvider(container); });
    $('#kt-next-week', container).addEventListener('click', () => { const d = new Date(activeWeek); d.setDate(d.getDate()+7); activeWeek = mondayOf(d); renderProvider(container); });
    $('#kt-save', container).addEventListener('click', () => save(container));
  }

  function renderGrid() {
    const grid = $('#kt-grid');
    if (!grid) return;
    if (!currentPlan || !currentPlan.days) currentPlan = { days: {} };
    DAYS.forEach(d => { if (!Array.isArray(currentPlan.days[d])) currentPlan.days[d] = []; });

    grid.innerHTML = DAYS.map(day => dayColumn(day, currentPlan.days[day])).join('');

    grid.querySelectorAll('.kt-add-activity').forEach(b => b.addEventListener('click', () => {
      const day = b.dataset.day;
      currentPlan.days[day].push({ time: '', title: '', domain: null, notes: '' });
      renderGrid();
    }));
    grid.querySelectorAll('.kt-activity input, .kt-activity select, .kt-activity textarea').forEach(el => {
      el.addEventListener('change', (e) => {
        const day = e.target.closest('.kt-activity').dataset.day;
        const idx = parseInt(e.target.closest('.kt-activity').dataset.idx, 10);
        const field = e.target.dataset.field;
        currentPlan.days[day][idx][field] = e.target.value;
      });
    });
    grid.querySelectorAll('.kt-del-activity').forEach(b => b.addEventListener('click', () => {
      const day = b.dataset.day;
      const idx = parseInt(b.dataset.idx, 10);
      currentPlan.days[day].splice(idx, 1);
      renderGrid();
    }));
  }

  function dayColumn(day, activities) {
    return `
      <div style="background:white;border-radius:12px;padding:12px;min-height:240px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="font-weight:700;font-size:14px;color:#111827;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">${DAY_LABELS[day]}</div>
        ${activities.map((a, i) => activityCard(day, i, a)).join('')}
        <button class="kt-add-activity" data-day="${day}" style="width:100%;background:#F3F4F6;color:#6B7280;border:1px dashed #D1D5DB;padding:8px;border-radius:8px;font-size:13px;cursor:pointer;margin-top:4px;">+ Add</button>
      </div>
    `;
  }

  function activityCard(day, idx, a) {
    const domain = DOMAINS.find(d => d.v === a.domain);
    const bg = domain ? domain.c + '40' : '#F3F4F6';
    const border = domain ? domain.c : '#D1D5DB';
    return `
      <div class="kt-activity" data-day="${day}" data-idx="${idx}" style="background:${bg};border-left:3px solid ${border};border-radius:6px;padding:8px;margin-bottom:6px;font-size:12px;">
        <div style="display:flex;gap:4px;margin-bottom:4px;">
          <input data-field="time" placeholder="9:00" value="${esc(a.time||'')}" style="width:60px;padding:3px 6px;border:1px solid #D1D5DB;border-radius:4px;font-size:11px;">
          <button class="kt-del-activity" data-day="${day}" data-idx="${idx}" title="Delete" style="margin-left:auto;background:transparent;border:none;color:#9CA3AF;cursor:pointer;font-size:14px;line-height:1;">×</button>
        </div>
        <input data-field="title" placeholder="Activity name" value="${esc(a.title||'')}" style="width:100%;padding:4px;border:1px solid #D1D5DB;border-radius:4px;font-size:12px;margin-bottom:4px;font-weight:600;">
        <select data-field="domain" style="width:100%;padding:3px;border:1px solid #D1D5DB;border-radius:4px;font-size:11px;margin-bottom:4px;">
          <option value="">— Domain —</option>
          ${DOMAINS.map(d => `<option value="${d.v}" ${d.v===a.domain?'selected':''}>${esc(d.l)}</option>`).join('')}
        </select>
        <textarea data-field="notes" placeholder="Notes (optional)" rows="2" style="width:100%;padding:4px;border:1px solid #D1D5DB;border-radius:4px;font-size:11px;font-family:inherit;resize:vertical;">${esc(a.notes||'')}</textarea>
      </div>
    `;
  }

  async function save(container) {
    const status = $('#kt-save-status', container);
    status.style.color = '#6B7280';
    status.textContent = 'Saving…';
    currentTheme = $('#kt-theme', container).value;
    try {
      await api('PUT', '/provider/lesson-plans', {
        room_id: activeRoomId,
        week_starting: activeWeek,
        theme: currentTheme,
        plan: currentPlan,
      });
      status.style.color = '#16A34A';
      status.textContent = '✓ Saved at ' + new Date().toLocaleTimeString();
    } catch (e) {
      status.style.color = '#DC2626';
      status.textContent = '✗ ' + e.message;
    }
  }

  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';

    // Find user's children
    let kids = [];
    try {
      const me = await api('GET', '/parent/children');
      kids = me.children || [];
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }

    if (kids.length === 0) {
      container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">No children found on your account.</div>';
      return;
    }

    container.innerHTML = '<div style="padding:24px;max-width:1800px;"><h2 style="font-size:24px;margin:0 0 16px;">📚 This Week\'s Activities</h2><div id="kt-kids-plans"></div></div>';
    const mount = $('#kt-kids-plans', container);

    for (const child of kids) {
      try {
        const plan = await api('GET', '/parent/lesson-plan/' + child.id);
        mount.insertAdjacentHTML('beforeend', parentPlanCard(child, plan));
      } catch (e) {
        mount.insertAdjacentHTML('beforeend', `<div style="background:white;border-radius:12px;padding:18px;margin-bottom:12px;color:#6B7280;">${esc(child.first_name)}: ${esc(e.message)}</div>`);
      }
    }
  }

  function parentPlanCard(child, plan) {
    const days = plan.plan && plan.plan.days ? plan.plan.days : {};
    const empty = !DAYS.some(d => (days[d] || []).length > 0);
    return `
      <div style="background:white;border-radius:14px;padding:20px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
          <div style="font-weight:700;font-size:18px;">${esc(child.first_name)} ${esc(child.last_name||'')}</div>
          <div style="font-size:13px;color:#6B7280;">Week of ${esc(plan.week_starting)}</div>
        </div>
        ${plan.theme ? `<div style="font-size:14px;color:#1F6080;margin-bottom:12px;"><em>Theme:</em> <strong>${esc(plan.theme)}</strong></div>` : ''}
        ${empty
          ? '<div style="color:#9CA3AF;font-style:italic;">No plan posted yet for this week.</div>'
          : `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
              ${DAYS.map(d => parentDayCard(d, days[d] || [])).join('')}
            </div>`
        }
      </div>
    `;
  }

  function parentDayCard(day, activities) {
    return `
      <div style="background:#F9FAFB;border-radius:8px;padding:10px;">
        <div style="font-weight:700;font-size:12px;color:#6B7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">${DAY_LABELS[day]}</div>
        ${activities.length === 0
          ? '<div style="font-size:12px;color:#D1D5DB;">—</div>'
          : activities.map(a => {
              const dom = DOMAINS.find(d => d.v === a.domain);
              const bg = dom ? dom.c + '40' : '#FFF';
              return `<div style="background:${bg};border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:12px;">
                ${a.time ? `<span style="color:#6B7280;">${esc(a.time)}</span> ` : ''}
                <strong>${esc(a.title||'')}</strong>
              </div>`;
            }).join('')
        }
      </div>
    `;
  }

  function selectStyle() { return 'padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:white;'; }
  function navBtnStyle() { return 'background:white;border:1px solid #D1D5DB;width:36px;height:36px;border-radius:8px;font-size:18px;font-weight:700;cursor:pointer;color:#6B7280;'; }

  function render(container) {
    const role = getRole();
    if (role === 'guardian') renderParent(container);
    else renderProvider(container);
  }

  window.KT = window.KT || {};
  window.KT.LessonPlans = { render };
})(window);
