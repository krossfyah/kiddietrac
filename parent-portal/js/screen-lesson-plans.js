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

  /* Opens read-only. Editing is a deliberate act, not the default state of a
     screen people mostly come to in order to READ. */
  var lpReadOnly = true;
  var lpStatus = 'published';

  /** Weekday name in the AGENCY's timezone — never the device's. A tablet left on
     UTC must not tint Tuesday's column on a Monday evening. */
  function agencyWeekdayKey() {
    try {
      var t = (window.KT && KT.agencyToday) ? KT.agencyToday()
        : new Date().toISOString().slice(0, 10);
      var names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
      return names[new Date(t + 'T12:00:00').getDay()] || null;
    } catch (e) { return null; }
  }

  /** Is the week on screen the one we are actually in? */
  function viewingCurrentWeek() {
    try {
      var t = (window.KT && KT.agencyToday) ? KT.agencyToday()
        : new Date().toISOString().slice(0, 10);
      return String(activeWeek) === String(mondayOf(new Date(t + 'T12:00:00')));
    } catch (e) { return false; }
  }
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

  // Each weekday gets its own accent so the week reads at a glance.
  const DAY_COLORS = {
    monday:    '#2563EB',
    tuesday:   '#0D9488',
    wednesday: '#7C3AED',
    thursday:  '#EA580C',
    friday:    '#16A34A',
  };
  // Fallback palette so an activity with NO HDLH domain (e.g. one added from a
  // curriculum template) still gets a distinct colour instead of a wall of grey.
  const ACTIVITY_PALETTE = ['#60A5FA', '#34D399', '#F472B6', '#FBBF24', '#A78BFA', '#22D3EE', '#FB923C', '#4ADE80'];

  function mondayOf(date) {
    // Timezone-safe. `new Date("YYYY-MM-DD")` parses as UTC midnight, and
    // `.toISOString()` formats back in UTC — so for anyone behind UTC (Eastern)
    // this drifted the Monday into the PREVIOUS week. That mismatch is why a plan
    // saved to week X (via screen-v22p57's local-based _mondayISO) opened the
    // planner on week X-1 with an empty grid — the "Use template does nothing" bug.
    // Parse a date-only string as LOCAL midnight and format with local parts so it
    // agrees with _mondayISO exactly.
    let d;
    if (date instanceof Date) {
      d = new Date(date.getTime());
    } else if (/^\d{4}-\d{2}-\d{2}$/.test(String(date))) {
      const p = String(date).split('-');
      d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    } else {
      d = new Date(date);
    }

    /* AN UNPARSEABLE DATE MUST NOT BECOME THE WEEK. (2026-09-21)

       `<input type="date">` hands back '' the moment it is cleared, and new Date('') is
       an Invalid Date whose getDay() is NaN. Every line below then propagated it and this
       returned the literal string 'NaN-NaN-NaN'.

       That string became activeWeek, and activeWeek is sticky: the loader asked the
       server for week NaN-NaN-NaN (empty grid), the pager could not step off it because
       new Date('NaN-NaN-NaN' + 'T12:00:00') is Invalid too, and every save posted it and
       came back 422. The planner was wedged until the page was reloaded.

       Amna Ahsan hit exactly this on 2026-09-21 at 14:25 — a published save rejected with
       week_starting 'NaN-NaN-NaN' and an empty plan — and only got her lesson plan in at
       23:12, after a reload. Falling back to the current week keeps the screen usable:
       clearing the field now just returns you to this week. */
    if (! date || ! d || isNaN(d.getTime())) {
      /* `! date` as well as the NaN check: new Date(null) is epoch 0, which is a VALID
         date, so a null slipped past and planted the week of 29 Dec 1969. */
      d = new Date();
    }

    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  }

  let activeRoomId = null;
  let activeCentreId = null;
  let activeScope = 'room';        // v22p84: 'room' or 'centre'
  let lastCentres = [];            // v22p84: agency centres (so centre-wide plans work even with 0 rooms)
  let activeWeek = mondayOf(new Date());
  let currentPlan = null;
  let currentTheme = '';
  /* An unsaved week filled from a template or the AI (2026-09-29). renderProvider reloads
     the saved plan, which silently threw these away -- the AI dialog said "Draft ready"
     and the week came back unchanged. Kept per room/centre + week until Save or Discard. */
  let pendingDraft = null;   // { key, theme, days, source }
  function draftKey() {
    return activeScope + ':' + (activeScope === 'centre' ? activeCentreId : activeRoomId) + ':' + activeWeek;
  }

  async function getRooms() {
    const me = getUser();
    const role = (me && (me.primary_role || (me.roles && me.roles[0]))) || '';
    // v21.1: only agency_admin can hit /admin/centres; everyone else uses /provider/bootstrap.
    if (role === 'agency_admin') {
      try {
        const res = await api('GET', '/admin/centres');
        lastCentres = (res.centres || []).map(c => ({ id: c.id, name: c.name }));
        const rooms = [];
        (res.centres || []).forEach(c => {
          (c.rooms || []).forEach(r => rooms.push({ ...r, centre_name: c.name, centre_id: c.id }));
        });
        // Return even when empty — centre-wide plans still work via lastCentres.
        return rooms;
      } catch (e) {}
    }
    try {
      const res = await api('GET', '/provider/bootstrap');
      return res.rooms || [];
    } catch (e) {
      return [];
    }
  }

  /* ── Draft with AI ─────────────────────────────────────────────────────
     The generator speaks HDLH foundations (belonging / wellbeing / engagement /
     expression); the planner stores learning domains. They are different taxonomies, so
     this is a deliberate approximation rather than a lookup — an educator can change any
     of it before saving, which is the point of dropping it into the grid rather than
     saving it for them. */
  var HDLH_TO_DOMAIN = {
    belonging: 'social_emotional',
    wellbeing: 'self_care',
    engagement: 'cognitive',
    expression: 'creative_arts',
  };
  var AI_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

  function aiPlanToDays(plan) {
    var out = { monday: [], tuesday: [], wednesday: [], thursday: [], friday: [] };
    var days = (plan && plan.days) || [];
    days.forEach(function (d, i) {
      // Prefer the day's own name; fall back to position, since a generator that returns
      // five days in order but mislabels one should not lose a day.
      var key = String(d.day || '').toLowerCase().trim();
      if (AI_DAYS.indexOf(key) === -1) { key = AI_DAYS[i] || null; }
      if (!key) { return; }
      (d.activities || []).forEach(function (a) {
        var notes = String(a.description || '');
        if (Array.isArray(a.materials) && a.materials.length) {
          notes += (notes ? ' ' : '') + 'Materials: ' + a.materials.join(', ') + '.';
        }
        out[key].push({
          time: '',                       // the educator places it in the day
          title: String(a.title || '').trim(),
          domain: HDLH_TO_DOMAIN[String(a.hdlh_foundation || '').toLowerCase()] || null,
          notes: notes.trim(),
        });
      });
    });
    return out;
  }

  function openAiDialog(container) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px 22px;box-shadow:0 18px 48px rgba(0,0,0,.28);">'
      + '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">✨ Draft this week with AI</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:0 0 14px;">Five days of activities land in the grid for you to edit. Nothing is saved until you press save.</div>'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Theme</label>'
      + '<input id="kt-ai-theme" type="text" placeholder="e.g. All about me" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;margin-bottom:12px;">'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Age group</label>'
      + '<select id="kt-ai-age" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;background:#fff;margin-bottom:12px;">'
      + '<option value="infant">Infant</option><option value="toddler" selected>Toddler</option>'
      + '<option value="preschool">Preschool</option><option value="school-age">School age</option></select>'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Anything to keep in mind (optional)</label>'
      + '<textarea id="kt-ai-notes" rows="2" placeholder="e.g. Several children are new — emphasise belonging." style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div id="kt-ai-msg" style="font-size:12.5px;color:#64748B;min-height:18px;margin:8px 0 0;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">'
      + '<button id="kt-ai-cancel" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button id="kt-ai-go" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Generate</button>'
      + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { overlay.remove(); } });
    overlay.querySelector('#kt-ai-cancel').addEventListener('click', function () { overlay.remove(); });

    var themeEl = overlay.querySelector('#kt-ai-theme');
    themeEl.value = (currentPlan && currentPlan.theme) || '';
    setTimeout(function () { themeEl.focus(); }, 40);

    overlay.querySelector('#kt-ai-go').addEventListener('click', async function () {
      var theme = themeEl.value.trim();
      var msg = overlay.querySelector('#kt-ai-msg');
      var go = overlay.querySelector('#kt-ai-go');
      if (theme.length < 3) { msg.innerHTML = '<span style="color:#DC2626;">Give it a theme to work from.</span>'; return; }
      go.disabled = true; go.textContent = 'Drafting…';
      msg.textContent = 'Drafting five days — usually 10–20 seconds.';
      try {
        var res = await api('POST', '/director/lesson-plans-ai/generate', {
          centre_id: parseInt(activeCentreId, 10),
          age_group: overlay.querySelector('#kt-ai-age').value,
          theme: theme,
          week_starting: activeWeek,
          starter_notes: overlay.querySelector('#kt-ai-notes').value.trim() || null,
        });
        var days = aiPlanToDays(res && res.plan);
        pendingDraft = { key: draftKey(), theme: theme, days: days, source: 'the AI draft' };
        lpReadOnly = false;
        overlay.remove();
        renderProvider(container);
        if (window.KT && KT.toast) { KT.toast('✨', 'Draft ready', 'Edit anything, then save.'); }
      } catch (e) {
        var detail = (e && e.message) || 'Server error';
        msg.innerHTML = '<span style="color:#DC2626;">Could not draft: ' + esc(detail) + '</span>';
        go.disabled = false; go.textContent = 'Generate';
      }
    });
  }

  async function renderProvider(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading lesson planner…</div>';
    const rooms = await getRooms();

    // v22p84: a plan can target a room OR a whole centre. Prefer the full agency
    // centre list (works even when no rooms exist yet); otherwise derive from rooms.
    let centres = [];
    if (lastCentres && lastCentres.length) {
      centres = lastCentres.slice();
    } else {
      const seenCentre = {};
      rooms.forEach(r => {
        if (r.centre_id != null && !seenCentre[r.centre_id]) {
          seenCentre[r.centre_id] = 1;
          centres.push({ id: r.centre_id, name: r.centre_name || ('Centre ' + r.centre_id) });
        }
      });
    }

    const hasRooms = rooms.length > 0;
    const canCentre = centres.length > 0;
    if (!hasRooms && !canCentre) {
      container.innerHTML = '<div style="padding:32px;color:#DC2626;">No rooms or centres found. Add a centre first.</div>';
      return;
    }
    // Pick a valid scope for what's available.
    if (!hasRooms) activeScope = 'centre';
    if (activeScope === 'centre' && !canCentre) activeScope = 'room';
    if (activeScope === 'room' && !hasRooms) activeScope = 'centre';

    if (hasRooms && (!activeRoomId || !rooms.find(r => r.id == activeRoomId))) activeRoomId = rooms[0].id;
    if (!activeCentreId || !centres.find(c => c.id == activeCentreId)) {
      var _ar = rooms.find(r => r.id == activeRoomId);
      activeCentreId = (_ar && _ar.centre_id) || (centres[0] && centres[0].id) || null;
    }

    // Scope toggle options — only offer what exists.
    var scopeOpts = '';
    if (hasRooms)  scopeOpts += '<option value="room" '   + (activeScope==='room'?'selected':'')   + '>🚪 This room</option>';
    if (canCentre) scopeOpts += '<option value="centre" ' + (activeScope==='centre'?'selected':'') + '>🏫 Whole centre</option>';
    var showScopeToggle = hasRooms && canCentre;

    let plan;
    const loadQs = (activeScope === 'centre') ? ('centre_id=' + activeCentreId) : ('room_id=' + activeRoomId);
    try { plan = await api('GET', '/provider/lesson-plans?' + loadQs + '&week_starting=' + activeWeek); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }

    currentPlan = plan.plan;
    currentTheme = plan.theme || '';
    var lpDraft = pendingDraft && pendingDraft.key === draftKey() ? pendingDraft : null;
    if (pendingDraft && !lpDraft) { pendingDraft = null; }   // moved to another week or room
    if (lpDraft) {
      currentPlan = { days: JSON.parse(JSON.stringify(lpDraft.days)) };
      currentTheme = lpDraft.theme || currentTheme;
      lpReadOnly = false;
    }
    /* Follow the saved state rather than assuming: a week left as a draft must not
       silently republish itself the next time somebody saves. */
    lpStatus = plan.status || 'published';

    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:24px;margin:0;">📚 Lesson Plans</h2>
            <p style="color:#6B7280;font-size:14px;margin:4px 0 0;">Weekly activities organized by HDLH domain</p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            ${showScopeToggle ? `<select id="kt-scope" style="${selectStyle()}" title="Plan for one room or the whole centre">${scopeOpts}</select>` : ''}
            <select id="kt-target" style="${selectStyle()}">
              ${activeScope==='centre'
                ? centres.map(c => `<option value="${c.id}" ${c.id==activeCentreId?'selected':''}>🏫 ${esc(c.name)}</option>`).join('')
                : rooms.map(r => `<option value="${r.id}" ${r.id==activeRoomId?'selected':''}>${esc(r.name)}${r.centre_name?' · '+esc(r.centre_name):''}</option>`).join('')}
            </select>
            <button id="kt-prev-week" style="${navBtnStyle()}">‹</button>
            <input type="date" id="kt-week" value="${activeWeek}" style="${selectStyle()};width:160px;">
            <button id="kt-next-week" style="${navBtnStyle()}">›</button>
            <button id="kt-ai" class="kt-icon-tip" title="Draft with AI" data-kttip="Draft this week with AI" aria-label="Draft with AI" style="height:36px;padding:0 12px;box-sizing:border-box;background:#fff;color:#1F6080;border:1px solid #CFE3EB;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;line-height:1;display:inline-flex;align-items:center;gap:6px;">✨ Draft with AI</button>
            <button id="kt-lp-templates" data-kt-iconized="1" title="Start this week from a template" style="height:36px;padding:0 12px;box-sizing:border-box;background:#fff;color:#1F6080;border:1px solid #CFE3EB;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;line-height:1;display:inline-flex;align-items:center;gap:6px;">📋 Templates</button>
            <button id="kt-lp-savetpl" data-kt-iconized="1" title="Save this week as a template your agency can reuse" style="height:36px;padding:0 12px;box-sizing:border-box;background:#fff;color:#475569;border:1px solid #D1D5DB;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;line-height:1;display:${lpReadOnly ? 'none' : 'inline-flex'};align-items:center;gap:6px;">💾 Save as template</button>
            <select id="kt-lp-status" title="Published plans are visible to families. Draft is staff-only." style="${selectStyle()};display:${lpReadOnly ? 'none' : 'inline-block'};">
              <option value="draft" ${lpStatus === 'draft' ? 'selected' : ''}>Draft</option>
              <option value="published" ${lpStatus === 'published' ? 'selected' : ''}>Published</option>
            </select>
            <button id="kt-lp-edit" style="height:36px;padding:0 14px;box-sizing:border-box;border:1px solid #D1D5DB;background:#fff;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:${lpReadOnly ? 'inline-block' : 'none'};">✏️ Edit</button>
            <button id="kt-save" class="kt-icon-tip" title="Save" data-kttip="Save" aria-label="Save" style="height:36px;width:36px;box-sizing:border-box;background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border:0;padding:0;border-radius:8px;cursor:pointer;font-size:16px;line-height:1;display:inline-flex;align-items:center;justify-content:center;">💾</button>
          </div>
        </div>

        ${lpDraft ? `<div id="kt-lp-draftbar" style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#92400E;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">📋 <span style="flex:1;min-width:220px;"><strong>Filled from ${esc(lpDraft.source)}.</strong> Nothing is saved yet — review it, change anything you like, then press Save.</span><button type="button" id="kt-lp-discard" data-kt-iconized="1" style="height:30px;padding:0 12px;border-radius:8px;border:1px solid #FCD34D;background:#fff;color:#92400E;font-weight:800;font-size:12.5px;cursor:pointer;">Discard</button></div>` : ''}
        ${activeScope==='centre' ? `<div style="background:#EAF3F6;border:1px solid #CFE3EB;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#16526E;">🏫 <strong>Centre-wide plan.</strong> This applies to every room in the centre, and parents see it for any room that doesn't have its own plan this week.</div>` : ''}

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

    if ($('#kt-scope', container)) {
      $('#kt-scope', container).addEventListener('change', (e) => { activeScope = e.target.value; renderProvider(container); });
    }
    $('#kt-target', container).addEventListener('change', (e) => {
      if (activeScope === 'centre') activeCentreId = parseInt(e.target.value, 10);
      else activeRoomId = parseInt(e.target.value, 10);
      renderProvider(container);
    });
    $('#kt-week', container).addEventListener('change', (e) => { activeWeek = mondayOf(e.target.value); renderProvider(container); });
    /* NOON, not midnight. `new Date('2026-09-07')` parses as UTC midnight, which in
       Toronto is Sep 6 at 20:00 — so getDate() answered 6, +7 landed on Sunday the
       13th, and mondayOf() (which reads LOCAL parts) snapped that straight back to
       Sep 7. The forward button could not move the week at all, and back skipped two.
       Parsing at local noon is the same trick viewingCurrentWeek() above already uses,
       and it is immune to both the UTC offset and DST. */
    const weekCursor = () => new Date(activeWeek + 'T12:00:00');
    const stepWeek = (days) => { const d = weekCursor(); d.setDate(d.getDate() + days); activeWeek = mondayOf(d); renderProvider(container); };
    $('#kt-prev-week', container).addEventListener('click', () => stepWeek(-7));
    $('#kt-next-week', container).addEventListener('click', () => stepWeek(7));
    $('#kt-save', container).addEventListener('click', () => save(container));

    /* Edit is a deliberate act. Re-rendering rather than toggling attributes in
       place keeps one render path responsible for both modes. */
    var lpEditBtn = $('#kt-lp-edit', container);
    if (lpEditBtn) {
      lpEditBtn.addEventListener('click', function () {
        lpReadOnly = false;
        renderProvider(container);
      });
    }
    var lpStatusEl = $('#kt-lp-status', container);
    if (lpStatusEl) {
      lpStatusEl.addEventListener('change', function () { lpStatus = lpStatusEl.value; });
    }
    $('#kt-ai', container).addEventListener('click', () => openAiDialog(container));
    $('#kt-lp-templates', container).addEventListener('click', () => openTemplates(container));
    var saveTplBtn = $('#kt-lp-savetpl', container);
    if (saveTplBtn) saveTplBtn.addEventListener('click', () => saveAsTemplate(container));
    var discardBtn = $('#kt-lp-discard', container);
    if (discardBtn) discardBtn.addEventListener('click', () => { pendingDraft = null; lpReadOnly = true; renderProvider(container); });
  }

  /* ═════════ Weekly templates (2026-09-29) ═════════
     Anthony: "add lesson plan templates that educators can choose from optionally for
     each week (allow them to review it first before choosing)". Browse -> preview the
     whole week -> Use this template, which fills the planner as an unsaved draft. */
  const AGE_LABEL = { infant: 'Infant', toddler: 'Toddler', preschool: 'Preschool', school_age: 'School age', mixed: 'Mixed ages' };
  const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  let tplCache = null;

  function tplOverlay() {
    var ov = document.createElement('div');
    ov.className = 'kt-modal-overlay kt-lp-tpl';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px;';
    ov.innerHTML = '<div class="kt-lp-tpl-box" style="background:#fff;border-radius:16px;max-width:1100px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;"></div>';
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
    return ov;
  }
  function planCount(days) {
    return WEEKDAYS.reduce(function (n, d) { return n + ((days && days[d]) || []).length; }, 0);
  }

  async function openTemplates(container) {
    var ov = tplOverlay();
    var box = ov.querySelector('.kt-lp-tpl-box');
    box.innerHTML = '<div style="padding:28px;color:#6B7280;">Loading templates…</div>';
    try { if (!tplCache) tplCache = (await api('GET', '/provider/lesson-plan-templates')).templates || []; }
    catch (e) { box.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load templates: ' + esc(e.message) + '</div>'; return; }
    var age = 'all', q = '';
    function list() {
      var ages = ['all'].concat(Object.keys(AGE_LABEL).filter(function (a) { return tplCache.some(function (t) { return t.age_group === a; }); }));
      var shown = tplCache.filter(function (t) {
        if (age === 'own' && t.source !== 'agency') return false;
        if (age !== 'all' && age !== 'own' && t.age_group !== age) return false;
        var hay = (t.title + ' ' + (t.theme || '') + ' ' + (t.description || '')).toLowerCase();
        return !q || hay.indexOf(q.toLowerCase()) !== -1;
      });
      var chip = function (v, l) {
        var on = v === age;
        return '<button type="button" data-age="' + v + '" style="height:30px;padding:0 12px;border-radius:999px;font-size:12.5px;font-weight:800;cursor:pointer;border:1px solid ' + (on ? '#1F6080;background:#1F6080;color:#fff;' : '#CBD5E1;background:#fff;color:#334155;') + '">' + l + '</button>';
      };
      box.innerHTML =
        '<div style="padding:18px 20px 12px;border-bottom:1px solid #EEF2F6;">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><h3 style="margin:0;font-size:19px;color:#0F172A;">📋 Weekly lesson plan templates</h3>'
        + '<button type="button" data-close style="border:0;background:none;font-size:22px;cursor:pointer;color:#64748B;" aria-label="Close">×</button></div>'
        + '<div style="font-size:13px;color:#64748B;margin:4px 0 12px;">Optional starting points for the week of ' + esc(activeWeek) + '. Open one to review the whole week before you use it.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
        + '<input type="search" data-q placeholder="Search themes…" value="' + esc(q) + '" style="height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;min-width:200px;">'
        + ages.map(function (a) { return chip(a, a === 'all' ? 'All' : AGE_LABEL[a]); }).join('')
        + (tplCache.some(function (t) { return t.source === 'agency'; }) ? chip('own', '🏠 Our agency') : '')
        + '</div></div>'
        + '<div style="padding:16px 20px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;">'
        + (shown.length ? shown.map(function (t) {
            return '<button type="button" data-tpl="' + t.id + '" style="text-align:left;border:1px solid #E2E8F0;background:#fff;border-radius:12px;padding:14px;cursor:pointer;display:flex;flex-direction:column;gap:6px;">'
              + '<div style="display:flex;gap:6px;flex-wrap:wrap;"><span style="font-size:11px;font-weight:800;color:#1F6080;background:#EAF3F6;padding:2px 8px;border-radius:999px;">' + esc(AGE_LABEL[t.age_group] || t.age_group) + '</span>'
              + (t.source === 'agency' ? '<span style="font-size:11px;font-weight:800;color:#92400E;background:#FEF3C7;padding:2px 8px;border-radius:999px;">Our agency</span>' : '') + '</div>'
              + '<div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(t.title) + '</div>'
              + '<div style="font-size:12.5px;color:#64748B;line-height:1.45;">' + esc(t.description || t.theme || '') + '</div>'
              + '<div style="font-size:12px;color:#94A3B8;margin-top:auto;">' + t.activity_count + ' activities · Mon–Fri' + (t.created_by ? ' · by ' + esc(t.created_by) : '') + '</div>'
              + '</button>';
          }).join('') : '<div style="color:#64748B;font-size:14px;">No templates match.</div>')
        + '</div>';
      box.querySelector('[data-close]').onclick = function () { ov.remove(); };
      box.querySelectorAll('[data-age]').forEach(function (b) { b.onclick = function () { age = b.getAttribute('data-age'); list(); }; });
      var qi = box.querySelector('[data-q]');
      qi.oninput = function () { q = qi.value; var pos = qi.selectionStart; list(); var n = box.querySelector('[data-q]'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} };
      box.querySelectorAll('[data-tpl]').forEach(function (b) {
        b.onclick = function () { preview(tplCache.filter(function (t) { return String(t.id) === b.getAttribute('data-tpl'); })[0]); };
      });
    }
    function preview(t) {
      var days = (t.plan && t.plan.days) || {};
      box.innerHTML =
        '<div style="padding:16px 20px;border-bottom:1px solid #EEF2F6;display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">'
        + '<div style="min-width:240px;flex:1;"><button type="button" data-back style="border:0;background:none;color:#1F6080;font-weight:800;cursor:pointer;padding:0;font-size:13px;">‹ All templates</button>'
        + '<h3 style="margin:6px 0 2px;font-size:20px;color:#0F172A;">' + esc(t.title) + '</h3>'
        + '<div style="font-size:13px;color:#64748B;">' + esc(AGE_LABEL[t.age_group] || t.age_group) + ' · ' + t.activity_count + ' activities' + (t.theme && t.theme !== t.title ? ' · Theme: ' + esc(t.theme) : '') + '</div>'
        + (t.description ? '<div style="font-size:13.5px;color:#334155;margin-top:6px;max-width:720px;line-height:1.5;">' + esc(t.description) + '</div>' : '') + '</div>'
        + '<div style="display:flex;gap:8px;align-items:center;">'
        + (t.can_delete ? '<button type="button" data-del style="height:36px;padding:0 12px;border-radius:8px;border:1px solid #FCA5A5;background:#fff;color:#B91C1C;font-weight:700;font-size:13px;cursor:pointer;">Remove template</button>' : '')
        + (lpReadOnly && !pendingDraft && false ? '' : '')
        + '<button type="button" data-use style="height:36px;padding:0 16px;border-radius:8px;border:0;background:#1F6080;color:#fff;font-weight:800;font-size:13.5px;cursor:pointer;">Use this template</button></div></div>'
        + '<div style="padding:16px 20px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;">'
        + WEEKDAYS.map(function (d) {
            return '<div style="border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;"><div style="background:' + (DAY_COLORS[d] || '#1F6080') + ';color:#fff;font-weight:800;font-size:13px;padding:7px 10px;text-transform:capitalize;">' + d + '</div>'
              + '<div style="padding:8px;display:flex;flex-direction:column;gap:8px;">'
              + ((days[d] || []).map(function (a) {
                  var dom = DOMAINS.find(function (x) { return x.v === a.domain; });
                  return '<div style="border-left:4px solid ' + (dom ? dom.c : '#CBD5E1') + ';padding:4px 8px;">'
                    + '<div style="font-size:11.5px;color:#64748B;">' + esc(fmtTime(a.time) || '') + (dom ? ' · ' + esc(dom.l) : '') + '</div>'
                    + '<div style="font-weight:800;font-size:13px;color:#0F172A;">' + esc(a.title) + '</div>'
                    + (a.notes ? '<div style="font-size:12px;color:#475569;line-height:1.45;margin-top:2px;">' + esc(a.notes) + '</div>' : '') + '</div>';
                }).join('') || '<div style="font-size:12px;color:#94A3B8;">Nothing planned</div>')
              + '</div></div>';
          }).join('')
        + '</div>';
      box.querySelector('[data-back]').onclick = list;
      var del = box.querySelector('[data-del]');
      if (del) del.onclick = async function () {
        var ok = window.KT && KT.confirm ? await KT.confirm({ title: 'Remove this template?', description: '"' + t.title + '" will no longer be offered to your agency. Weeks already planned from it are not changed.', okLabel: 'Remove' }) : true;
        if (!ok) return;
        try { await api('DELETE', '/provider/lesson-plan-templates/' + t.id); tplCache = null; ov.remove(); openTemplates(container); }
        catch (e) { if (KT.toast) KT.toast('⚠️', 'Not removed', e.message, '#B91C1C'); }
      };
      box.querySelector('[data-use]').onclick = async function () {
        var existing = planCount(currentPlan && currentPlan.days);
        var mode = 'replace';
        if (existing > 0) {
          mode = await chooseMode(existing);
          if (!mode) return;
        }
        var days = {};
        WEEKDAYS.forEach(function (d) {
          var add = JSON.parse(JSON.stringify(((t.plan && t.plan.days) || {})[d] || []));
          days[d] = mode === 'add' ? (((currentPlan && currentPlan.days && currentPlan.days[d]) || []).concat(add)) : add;
        });
        var theme = mode === 'add' && currentTheme ? currentTheme : (t.theme || t.title);
        pendingDraft = { key: draftKey(), theme: theme, days: days, source: 'the "' + t.title + '" template' };
        lpReadOnly = false;
        ov.remove();
        try { api('POST', '/provider/lesson-plan-templates/' + t.id + '/used', {}); } catch (e) {}
        renderProvider(container);
        if (window.KT && KT.toast) KT.toast('📋', 'Template added', 'Review the week, change anything, then Save.');
      };
    }
    function chooseMode(existing) {
      return new Promise(function (resolve) {
        var m = document.createElement('div');
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;z-index:10001;padding:16px;';
        m.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px;">'
          + '<h3 style="margin:0 0 8px;font-size:17px;color:#0F172A;">This week already has ' + existing + ' activit' + (existing === 1 ? 'y' : 'ies') + '</h3>'
          + '<div style="font-size:13.5px;color:#475569;line-height:1.5;">Replace them with the template, or add the template\'s activities alongside them? Nothing is saved until you press Save.</div>'
          + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;">'
          + '<button type="button" data-m="" style="background:#F1F5F9;color:#334155;border:0;padding:9px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Cancel</button>'
          + '<button type="button" data-m="add" style="background:#fff;color:#1F6080;border:1px solid #CFE3EB;padding:9px 14px;border-radius:8px;font-weight:800;cursor:pointer;">Add to this week</button>'
          + '<button type="button" data-m="replace" style="background:#1F6080;color:#fff;border:0;padding:9px 14px;border-radius:8px;font-weight:800;cursor:pointer;">Replace</button></div></div>';
        document.body.appendChild(m);
        m.querySelectorAll('[data-m]').forEach(function (b) { b.onclick = function () { m.remove(); resolve(b.getAttribute('data-m') || null); }; });
      });
    }
    list();
  }

  function saveAsTemplate(container) {
    var days = (currentPlan && currentPlan.days) || {};
    var n = planCount(days);
    var ov = tplOverlay();
    var box = ov.querySelector('.kt-lp-tpl-box');
    box.style.maxWidth = '460px';
    var fld = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;';
    var lbl = 'display:block;font-size:12px;font-weight:700;color:#374151;margin:10px 0 4px;';
    var theme = ($('#kt-theme', container) || {}).value || currentTheme || '';
    box.innerHTML = '<div style="padding:20px;">'
      + '<h3 style="margin:0 0 6px;font-size:18px;color:#0F172A;">💾 Save this week as a template</h3>'
      + '<div style="font-size:13px;color:#64748B;">' + (n ? n + ' activities. Everyone in your agency will be able to preview it and use it for a future week.' : 'This week has no activities yet.') + '</div>'
      + '<label style="' + lbl + '">Name</label><input data-title maxlength="80" style="' + fld + '" value="' + esc(theme) + '">'
      + '<label style="' + lbl + '">Age group</label><select data-age style="' + fld + '">'
      + Object.keys(AGE_LABEL).map(function (a) { return '<option value="' + a + '"' + (a === 'preschool' ? ' selected' : '') + '>' + AGE_LABEL[a] + '</option>'; }).join('') + '</select>'
      + '<label style="' + lbl + '">Short description (optional)</label><textarea data-desc rows="2" maxlength="300" style="' + fld + 'resize:vertical;"></textarea>'
      + '<div data-err style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:8px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;"><button type="button" data-cancel style="background:#F1F5F9;color:#334155;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button type="button" data-ok style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-weight:800;cursor:pointer;"' + (n ? '' : ' disabled') + '>Save template</button></div></div>';
    box.querySelector('[data-cancel]').onclick = function () { ov.remove(); };
    box.querySelector('[data-ok]').onclick = async function () {
      var title = box.querySelector('[data-title]').value.trim();
      if (!title) { box.querySelector('[data-err]').textContent = 'Give the template a name.'; return; }
      this.disabled = true;
      try {
        await api('POST', '/provider/lesson-plan-templates', { title: title, theme: theme || title, age_group: box.querySelector('[data-age]').value,
          description: box.querySelector('[data-desc]').value.trim() || null, plan: { days: days } });
        tplCache = null;
        ov.remove();
        if (window.KT && KT.toast) KT.toast('💾', 'Template saved', '"' + title + '" is now in 📋 Templates for your agency.');
      } catch (e) { this.disabled = false; box.querySelector('[data-err]').textContent = e.message; }
    };
  }

  /* Planner times were free text, so "10am", "1030am" and "10:00" all appear in real
     plans. toHHMM feeds the native picker; fmtTime is what everything else displays.
     Both mirror App\Support\LessonPlans on the server so the planner, the parent
     summary and the educator recap never disagree about what a time says. */
  function toHHMM(raw) {
    var t = String(raw == null ? '' : raw).toLowerCase().trim();
    if (!t) return '';
    var mer = null, m = t.match(/(a\.?m\.?|p\.?m\.?)\s*$/);
    if (m) { mer = m[1].charAt(0) === 'a' ? 'AM' : 'PM'; t = t.replace(/(a\.?m\.?|p\.?m\.?)\s*$/, '').trim(); }
    t = t.replace(/[ .]/g, '');
    var h, min;
    if (/^\d{1,2}:\d{2}$/.test(t)) { var p = t.split(':'); h = +p[0]; min = +p[1]; }
    else if (/^\d{3,4}$/.test(t)) { h = +t.slice(0, -2); min = +t.slice(-2); }
    else if (/^\d{1,2}$/.test(t)) { h = +t; min = 0; }
    else return '';                       // not a time — leave it to the text input
    if (h > 23 || min > 59) return '';
    if (mer === 'PM' && h < 12) h += 12;
    if (mer === 'AM' && h === 12) h = 0;
    if (mer === null && h < 7) h += 12;   // a childcare 1–6 means the afternoon
    return (h < 10 ? '0' : '') + h + ':' + (min < 10 ? '0' : '') + min;
  }

  function fmtTime(raw) {
    var hhmm = toHHMM(raw);
    if (!hhmm) return String(raw == null ? '' : raw);   // "after lunch" stays as typed
    var p = hhmm.split(':'), h = +p[0], mer = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + p[1] + ' ' + mer;
  }

  function renderGrid() {
    const grid = $('#kt-grid');
    if (!grid) return;
    if (!currentPlan || !currentPlan.days) currentPlan = { days: {} };
    DAYS.forEach(d => { if (!Array.isArray(currentPlan.days[d])) currentPlan.days[d] = []; });

    grid.innerHTML = DAYS.map(day => dayColumn(day, currentPlan.days[day])).join('');

    /* Read-only shows the SAME cards, just not tappable — activityCard() decides that
       from lpReadOnly, so the two modes render from one path and cannot drift apart.
       Only the add button has to be taken away. */
    if (lpReadOnly) {
      grid.querySelectorAll('.kt-add-activity').forEach(function (b) { b.style.display = 'none'; });
    }

    // Adding and editing are the same dialog; -1 means "new".
    grid.querySelectorAll('.kt-add-activity').forEach(b => b.addEventListener('click', () => {
      openActivityDialog(b.dataset.day, -1);
    }));
    grid.querySelectorAll('.kt-activity-tap').forEach(card => {
      const open = () => openActivityDialog(card.dataset.day, parseInt(card.dataset.idx, 10));
      card.addEventListener('click', open);
      // Keyboard parity: the card is role="button", so it must answer to one.
      card.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
  }

  function dayColumn(day, activities) {
    const dc = DAY_COLORS[day] || '#64748B';
    /* Only tint when the week on screen contains today — otherwise every week
       would highlight a Wednesday, which tells the reader nothing. */
    const isToday = viewingCurrentWeek() && agencyWeekdayKey() === day;
    const todayBg = isToday ? 'background:#FFFBEB;box-shadow:0 0 0 2px #FDE68A;' : '';
    return `
      <div style="background:white;border-radius:12px;padding:12px;min-height:240px;box-shadow:0 1px 3px rgba(0,0,0,.04);border-top:4px solid ${dc};${todayBg}">
        <div style="font-weight:800;font-size:14px;color:${dc};margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">${DAY_LABELS[day]}${isToday ? ' <span style="font-size:10px;background:#F59E0B;color:#fff;padding:1px 6px;border-radius:8px;letter-spacing:0;vertical-align:middle;">TODAY</span>' : ''}</div>
        ${activities.map((a, i) => activityCard(day, i, a)).join('')}
        <button class="kt-add-activity" data-day="${day}" style="width:100%;background:#F3F4F6;color:#6B7280;border:1px dashed #D1D5DB;padding:8px;border-radius:8px;font-size:13px;cursor:pointer;margin-top:4px;">+ Add</button>
      </div>
    `;
  }

  /* A SUMMARY, NOT FOUR CONTROLS.

     Every activity used to be a time picker, a title box, a domain select and a notes
     textarea, all live, stacked inside a column one fifth of the screen wide. On a phone
     that is four fiddly targets per activity and five activities per column — the reason
     the weekly menu was moved to a dialog, and the same answer applies here.

     The card now shows what is planned; tapping it while editing opens one dialog with
     room to actually type. Read-only shows the identical card, just not tappable, so the
     two modes cannot drift apart. (Anthony, 2026-09-08) */
  function activityCard(day, idx, a) {
    const domain = DOMAINS.find(d => d.v === a.domain);
    // Domain colour when set; otherwise a distinct palette colour by position so
    // template-added (domain-less) activities are still easy to tell apart.
    const colour = domain ? domain.c : ACTIVITY_PALETTE[idx % ACTIVITY_PALETTE.length];
    const bg = colour + '33';
    const border = colour;
    const title = (a.title || '').trim();
    const when = (a.time || '').trim();
    const notes = (a.notes || '').trim();
    const tappable = !lpReadOnly;
    return `
      <div class="kt-activity${tappable ? ' kt-activity-tap' : ''}" data-day="${day}" data-idx="${idx}"
           ${tappable ? 'role="button" tabindex="0"' : ''}
           style="background:${bg};border-left:3px solid ${border};border-radius:6px;padding:9px 10px;margin-bottom:6px;font-size:12px;${tappable ? 'cursor:pointer;' : ''}">
        <div style="display:flex;align-items:baseline;gap:6px;">
          ${when ? `<span style="font-size:11px;font-weight:800;color:#334155;font-variant-numeric:tabular-nums;white-space:nowrap;">${esc(when)}</span>` : ''}
          <span style="font-weight:700;color:${title ? '#0D1B2A' : '#94A3B8'};font-size:12.5px;line-height:1.3;">${title ? esc(title) : (tappable ? 'Tap to fill in' : '—')}</span>
        </div>
        ${domain ? `<div style="margin-top:4px;"><span style="display:inline-block;background:#fff;border:1px solid ${border};color:#334155;border-radius:999px;padding:1px 8px;font-size:10.5px;font-weight:700;">${esc(domain.l)}</span></div>` : ''}
        ${notes ? `<div style="margin-top:4px;color:#475569;font-size:11.5px;line-height:1.35;">${esc(notes.length > 90 ? notes.slice(0, 90) + '…' : notes)}</div>` : ''}
      </div>
    `;
  }

  /* ONE DIALOG for an activity — new (idx === -1) or existing. Mirrors the weekly
     menu's dish dialog so the two screens behave the same way. */
  function openActivityDialog(day, idx) {
    const isNew = idx < 0;
    const a = isNew ? { time: '', title: '', domain: null, notes: '' }
                    : (currentPlan.days[day][idx] || { time: '', title: '', domain: null, notes: '' });
    const hhmm = toHHMM(a.time);
    // A picker when the value is a clock time (or blank), a text box when it is not, so
    // an existing "after lunch" is not silently discarded by type="time".
    const isTime = hhmm !== '' || !(a.time || '').trim();

    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px;';
    ov.innerHTML = `<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;max-width:440px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 24px 60px rgba(15,23,42,.35);">
      <div style="padding:16px 20px;border-bottom:1px solid #EDF2F7;">
        <div style="font-size:16px;font-weight:800;color:#0D1B2A;">${isNew ? 'Add an activity' : 'Edit activity'} &middot; ${esc(DAY_LABELS[day] || day)}</div>
        <div style="font-size:12.5px;color:#64748B;margin-top:2px;">What is planned, and when.</div>
      </div>
      <div style="padding:16px 20px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">Time</label>
        ${isTime
          ? `<input id="lp-time" type="time" value="${esc(hhmm)}" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">`
          : `<input id="lp-time" type="text" value="${esc(a.time || '')}" placeholder="9:00" title="Not a clock time — clear it to use the time picker." style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">`}
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Activity</label>
        <input id="lp-title" value="${esc(a.title || '')}" placeholder="e.g. Playdough"
               style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Domain</label>
        <select id="lp-domain" style="width:100%;box-sizing:border-box;height:42px;padding:0 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;background:#fff;">
          <option value="">— Domain —</option>
          ${DOMAINS.map(d => `<option value="${d.v}" ${d.v === a.domain ? 'selected' : ''}>${esc(d.l)}</option>`).join('')}
        </select>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Notes</label>
        <textarea id="lp-notes" rows="3" placeholder="Optional"
                  style="display:block;width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;resize:vertical;">${esc(a.notes || '')}</textarea>
      </div>
      <div style="padding:14px 20px;border-top:1px solid #EDF2F7;display:flex;gap:8px;align-items:center;">
        ${isNew ? '' : '<button id="lp-del" style="background:#fff;border:1px solid #FECACA;color:#B91C1C;border-radius:10px;padding:10px 14px;font:inherit;font-size:14px;font-weight:700;cursor:pointer;">Delete</button>'}
        <button id="lp-x" style="margin-left:auto;background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 16px;font:inherit;font-size:14px;color:#475569;cursor:pointer;">Cancel</button>
        <button id="lp-ok" style="background:linear-gradient(135deg,#1F6080,#2c7894);border:0;border-radius:10px;padding:10px 20px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;">Done</button>
      </div>
    </div>`;
    document.body.appendChild(ov);
    try { if (window.KT && KT.pushOverlay) { KT.pushOverlay(ov); } } catch (e) {}

    const close = () => {
      try { if (window.KT && KT.popOverlay) { KT.popOverlay(ov); } } catch (e) {}
      ov.remove();
    };
    const t = ov.querySelector('#lp-title');
    if (t) { t.focus(); t.select(); }
    ov.querySelector('#lp-x').onclick = close;
    ov.addEventListener('click', e => { if (e.target === ov) close(); });
    ov.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    const del = ov.querySelector('#lp-del');
    if (del) {
      del.onclick = () => { currentPlan.days[day].splice(idx, 1); close(); renderGrid(); };
    }
    ov.querySelector('#lp-ok').onclick = () => {
      const next = {
        time: ov.querySelector('#lp-time').value.trim(),
        title: ov.querySelector('#lp-title').value.trim(),
        domain: ov.querySelector('#lp-domain').value || null,
        notes: ov.querySelector('#lp-notes').value.trim(),
      };
      /* An empty new activity is not worth a card. An existing one emptied out is a
         deliberate clear, and Delete is right there for removing it outright. */
      if (isNew && !next.time && !next.title && !next.notes && !next.domain) { close(); return; }
      if (isNew) { currentPlan.days[day].push(next); }
      else { currentPlan.days[day][idx] = next; }
      close();
      renderGrid();
    };
  }

  async function save(container) {
    const status = $('#kt-save-status', container);
    status.style.color = '#6B7280';
    status.textContent = 'Saving…';
    currentTheme = $('#kt-theme', container).value;
    try {
      const payload = { week_starting: activeWeek, theme: currentTheme, plan: currentPlan,
                        status: lpStatus };
      if (activeScope === 'centre') payload.centre_id = activeCentreId;
      else payload.room_id = activeRoomId;
      await api('PUT', '/provider/lesson-plans', payload);
      pendingDraft = null;
      status.style.color = '#16A34A';
      status.textContent = (lpStatus === 'published' ? '✓ Published ' : '✓ Saved as draft ')
        + (activeScope === 'centre' ? '(whole centre) ' : '')
        + 'at ' + new Date().toLocaleTimeString();
      /* Back to reading once it is saved, so the week cannot be edited by accident
         after the person has finished with it. */
      lpReadOnly = true;
      renderProvider(container);
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
          ? '<div style="color:#64748B;font-style:italic;">No plan posted yet for this week.</div>'
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
                ${a.time ? `<span style="color:#6B7280;white-space:nowrap;">${esc(fmtTime(a.time))}</span> ` : ''}
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

  // Point the planner at a specific scope/target/week BEFORE navigating to it.
  // "Use template" saves into one room-or-centre + week, then sends the user here;
  // without this the planner opened on its remembered scope (default: room scope,
  // first room, current week) and showed an empty grid — the plan looked "not
  // saved" even though it was. Called by screen-v22p57.js right before the hash nav.
  function focus(opts) {
    opts = opts || {};
    if (opts.scope === 'centre' || opts.scope === 'room') activeScope = opts.scope;
    if (opts.roomId != null) activeRoomId = parseInt(opts.roomId, 10);
    if (opts.centreId != null) activeCentreId = parseInt(opts.centreId, 10);
    if (opts.week) activeWeek = mondayOf(opts.week);
  }

  window.KT = window.KT || {};
  window.KT.LessonPlans = { render, focus };
})(window);
