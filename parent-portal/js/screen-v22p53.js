/* v22p53 — all new screens in one module.
   Lessons learned from v22p51:
     - never use html(`<newline>...`).firstChild (returns text node);
       set main.innerHTML directly
     - use window.KT.API_BASE not KT_API_BASE
     - use Array.isArray(u.roles) not u.primary_role
*/
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

  const apiBase = () => (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
  const userRoles = () => { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles || []; } catch (e) { return []; } };
  const isStaff = () => userRoles().some(r => ['agency_admin', 'centre_director', 'educator', 'platform_admin'].includes(r));

  async function downloadAuthed(path, filename) {
    const tok = sessionStorage.getItem('kt_token');
    const r = await fetch(apiBase() + path, { headers: { Authorization: 'Bearer ' + tok } });
    const blob = await r.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename;
    a.click();
  }

  // ============================ Meal planning ============================
  // Monday (local) of the week containing d, and a local YYYY-MM-DD formatter —
  // shared by the staff editor and the parent view so both page weeks identically.
  function _mondayOf(d) { const x = new Date(d); const dow = (x.getDay() + 6) % 7; x.setDate(x.getDate() - dow); x.setHours(0, 0, 0, 0); return x; }
  function _ymd(d) { const x = new Date(d); return new Date(x.getTime() - x.getTimezoneOffset() * 60000).toISOString().slice(0, 10); }

  async function renderMenu(main) {
    main.innerHTML = '<div style="padding:24px;">Loading menu…</div>';
    // resolve a centre for the user (works for educator/director/admin/platform)
    let centreList = [];
    try { const r0 = await Api.get('/operations/my-centres'); centreList = r0.centres || r0.data || (Array.isArray(r0) ? r0 : []); } catch (e) {}
    if (!centreList.length) { try { const r1 = await Api.get('/director/centres'); centreList = r1.centres || r1.data || (Array.isArray(r1) ? r1 : []); } catch (e) {} }
    if (!centreList.length) { try { const r2 = await Api.get('/admin/centres'); centreList = r2.centres || r2.data || (Array.isArray(r2) ? r2 : []); } catch (e) {} }
    if (!centreList.length) { main.innerHTML = '<div style="padding:24px;color:#64748B;">No centre is assigned to your account yet — ask an administrator to add you to a centre.</div>'; return; }
    // Which one you were last editing. With nine providers, always landing on the
    // alphabetically first one means everybody else is a hunt.
    const CENTRE_KEY = 'kt_menu_centre';
    let cid = (function () {
      const saved = parseInt(localStorage.getItem(CENTRE_KEY) || '', 10);
      return centreList.some(c => +c.id === saved) ? saved : centreList[0].id;
    })();
    const centreNameOf = (id) => {
      const c = centreList.filter(x => +x.id === +id)[0] || centreList[0];
      return c.name || '';
    };
    // "Centre" is called something else in some agencies — providers here.
    const centreWord = (window.KT && KT.centreWord) ? KT.centreWord() : 'Centre';
    const meals = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'];
    const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const thisMonday = _mondayOf(new Date());
    let cur = _mondayOf(new Date());   // the week currently being edited

    // Allergy/dietary reminders for whichever centre is on screen. Fetched per load
    // rather than once: fixed to the first centre, it showed one provider's warnings
    // while you edited another provider's menu, which is the worst possible version.
    async function allergyPanel(forCid) {
      let allergyHtml = '';
      try {
        const ar = await Api.get(`/operations/allergy-alerts?centre_id=${forCid}`);
      const kids = (ar && ar.data) || [];
      if (kids.length) {
        /* THE SEVERITIES IT WAS CHECKING FOR DO NOT EXIST.

           This tested `severity === 'severe' || 'high'`. The values the health record
           actually stores are mild / moderate / anaphylactic — so nothing ever matched,
           and an ANAPHYLACTIC allergy was drawn in the same soft amber as a mild one on
           the very screen where somebody chooses what to feed the child. On this screen,
           of all of them, the worst case has to be the one that stands out.

           Dietary needs are separated from allergies as well: "vegetarian" and "peanuts"
           are different kinds of problem and were being shown identically.
           (Anthony, 2026-09-10) */
        const chip = (t) => {
          const sev = String(t.severity || '').toLowerCase();
          const dietary = t.kind === 'dietary';
          let bg = '#FEF3C7', fg = '#92400E', mark = '';
          if (sev === 'anaphylactic' || sev === 'severe' || sev === 'high') {
            bg = '#FEE2E2'; fg = '#991B1B'; mark = '🚨 ';
          } else if (sev === 'moderate') {
            bg = '#FEF3C7'; fg = '#92400E'; mark = '⚠ ';
          } else if (dietary) {
            bg = '#E0F2FE'; fg = '#075985';
          }
          return `<span style="display:inline-block;background:${bg};color:${fg};padding:1px 8px;border-radius:9px;font-size:11px;font-weight:700;margin:1px 4px 1px 0;">${mark}${esc(t.label)}</span>`;
        };
        allergyHtml = `<div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:12px 14px;margin:12px 0;">
          <div style="font-weight:800;font-size:13px;color:#9A3412;">⚠️ Allergy &amp; dietary reminders <span style="font-weight:600;color:#C2701C;">· ${kids.length} ${kids.length === 1 ? 'child' : 'children'}</span>${
            (() => {
              /* Said out loud rather than left to be counted off the chips below. */
              const ana = kids.filter(k => (k.tags || []).some(t => String(t.severity || '').toLowerCase() === 'anaphylactic')).length;
              return ana ? `<span style="margin-left:8px;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:800;">🚨 ${ana} anaphylactic</span>` : '';
            })()
          }</div>
          <div style="margin-top:8px;display:flex;flex-direction:column;gap:7px;">
            ${kids.map(k => `<div style="font-size:12.5px;color:#7C2D12;"><strong>${esc((k.first_name || '') + ' ' + String(k.last_name || '').charAt(0) + (k.last_name ? '.' : ''))}</strong> — ${(k.tags || []).map(chip).join('') || '<span style="color:#9A3412;">alert on file</span>'}</div>`).join('')}
          </div>
        </div>`;
      }
        /* An empty alert list is not a clean bill of health.
           Nobody in this agency has any allergy or dietary information recorded, so this
           panel simply did not render — and an educator planning a week of meals saw a
           menu editor with no warnings on it, which reads as "nothing to avoid". The
           honest statement is that we have not asked, and that belongs on the screen
           where the food is being chosen. (Anthony, 2026-08-26) */
        allergyHtml += unrecordedPanel(ar);
      } catch (e) {}
      return allergyHtml;
    }

    /**
     * The children this centre has recorded nothing for.
     *
     * Deliberately amber, not red: this is not an emergency, it is an unanswered
     * question — and styling it as an alert next to real allergy alerts would dilute
     * the ones that mean a child could be harmed.
     */
    function unrecordedPanel(ar) {
      const missing = (ar && ar.unrecorded) || [];
      if (!missing.length) { return ''; }
      const names = missing.map(k => esc((k.first_name || '') + ' '
        + String(k.last_name || '').charAt(0) + (k.last_name ? '.' : ''))).join(', ');
      return `<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:12px 14px;margin:12px 0;">
        <div style="font-weight:800;font-size:13px;color:#92400E;">Allergy information not recorded
          <span style="font-weight:600;color:#B45309;">· ${missing.length} ${missing.length === 1 ? 'child' : 'children'}</span></div>
        <div style="margin-top:6px;font-size:12.5px;color:#78350F;line-height:1.55;">
          Nothing has been recorded for ${names}. That is not the same as having no allergies —
          please confirm with their parents before planning around it.
        </div>
      </div>`;
    }

    async function load() {
      const weekStartStr = _ymd(cur);
      main.innerHTML = '<div style="padding:24px;">Loading menu…</div>';
      const [r, allergyHtml] = await Promise.all([
        Api.get(`/operations/menu?centre_id=${cid}&week_start=${weekStartStr}`).catch(() => ({})),
        allergyPanel(cid),
      ]);
      /* A week nobody has created yet opens as PUBLISHED, so an educator who fills
         it in and presses Save has actually submitted it to families. It used to
         default to draft, which meant the menu was saved and then seen by no one.
         Safe: the API refuses to publish a week with no meals. */
      const week = r.data || { status: 'published', notes: '' };
      // Times live on the week, and a week that has not set its own inherits the last
      // week that did — a centre types its timetable once, not every Monday.
      const mealTimes = r.meal_times || {};
      // Only render the days the centre is open (configured per centre; Mon–Fri default).
      const openDays = (r && Array.isArray(r.open_days) && r.open_days.length) ? r.open_days.slice() : [1, 2, 3, 4, 5];
      const cols = openDays.map(dow => ({ dow, label: dayLabels[dow - 1] || ('D' + dow) }));
      const itemsByDayMeal = {};
      (r.items || []).forEach(it => { itemsByDayMeal[`${it.day_of_week}-${it.meal_type}`] = it; });

      // The grid scrolls horizontally inside its own box on mobile; the Meal
      // column stays pinned so you never lose which row you're editing.
      // A menu is planned against real dates, so each day carries its own, and today is
      // tinted — five identical columns of input boxes are otherwise impossible to place.
      const todayStr = _ymd(new Date());
      const dateOf = (dow) => { const d = new Date(cur); d.setDate(d.getDate() + (dow - 1)); return d; };
      const HEAD = 'padding:9px 8px;border-bottom:2px solid #E2E8F0;font-size:12px;color:#475569;';

      let table = `<table data-kt-no-bulk data-kt-no-filter data-kt-no-kebab style="min-width:${150 + cols.length * 116}px;width:100%;border-collapse:collapse;margin-top:12px;border:1px solid #E7EDF3;border-radius:12px;overflow:hidden;">
        <thead><tr style="background:#F1F5F9;"><th style="text-align:left;${HEAD}position:sticky;left:0;background:#F1F5F9;z-index:1;">Meal</th>`;
      cols.forEach(c => {
        const dt = dateOf(c.dow);
        const isToday = _ymd(dt) === todayStr;
        table += `<th class="${isToday ? 'kt-today-cell' : ''}" style="text-align:left;${HEAD}">
          <div style="font-weight:800;color:${isToday ? '#1F6080' : '#0D1B2A'};">${c.label}${isToday ? ' · today' : ''}</div>
          <div style="font-size:11px;font-weight:600;color:#64748B;">${dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</div>
        </th>`;
      });
      table += `</tr></thead><tbody>`;
      // Banded rows. Five meals of identical boxes read as one grey field otherwise, and
      // losing your place mid-row is how Tuesday's lunch ends up under Wednesday.
      /* A ROW YOU CAN FIND AGAIN. Banding on parity alone was nearly invisible and
         said nothing about WHICH meal you were looking at, which is what you need when
         your eye returns to a seven-column grid. Muted on purpose: these sit behind text
         all day and must not compete with the "today" column, which stays the loudest
         thing here. */
      const MEAL_TINT = {
        breakfast: '#FDF7EC', morning_snack: '#F4F9F0', lunch: '#EDF4FA',
        afternoon_snack: '#F6F4FA', dinner: '#F1F5F7',
      };
      const MEAL_EDGE = {
        breakfast: '#DFAE62', morning_snack: '#8FBE72', lunch: '#6F9CBD',
        afternoon_snack: '#9C8FBE', dinner: '#8A9FAC',
      };
      meals.forEach((m, mi) => {
        const band = MEAL_TINT[m] || '#FFFFFF';
        const edge = MEAL_EDGE[m] || '#E5E7EB';
        table += `<tr style="background:${band};">
          <td style="padding:8px 8px 8px 10px;border-bottom:1px solid #EDF2F7;border-left:4px solid ${edge};font-size:12px;position:sticky;left:0;background:${band};z-index:1;">
            <div style="font-weight:700;text-transform:capitalize;color:#0D1B2A;">${m.replace('_', ' ')}</div>
            <!-- A bare clock under a meal name says nothing about what it is for. The
                 aria-label always covered screen readers; this covers everyone else. -->
            <div style="margin-top:5px;font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#94A3B8;">Serves at</div>
            <input data-meal-time="${m}" type="time" value="${esc(mealTimes[m] || '')}" aria-label="${m.replace('_', ' ')} serving time"
                   style="margin-top:2px;width:124px;box-sizing:border-box;padding:3px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11.5px;color:#475569;background:#fff;">
          </td>`;
        cols.forEach(c => {
          const it = itemsByDayMeal[`${c.dow}-${m}`] || {};
          const isToday = _ymd(dateOf(c.dow)) === todayStr;
          /* The inputs stay — hidden — because Save collects from
             input[data-d][data-m][data-f=...] and a hidden input still has .value. The
             dialog writes into these and repaints the button, so there is one source of
             truth and the save path is untouched. */
          table += `<td class="${isToday ? 'kt-today-cell' : ''}" style="padding:6px 4px;border-bottom:1px solid #EDF2F7;min-width:132px;">
            <input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="name" value="${esc(it.name || '')}">
            <input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="allergens" value="${esc(it.allergens || '')}">
            <button type="button" class="mp-cell" data-cd="${c.dow}" data-cm="${m}"
                    aria-label="${it.name ? 'Edit' : 'Add'} ${m.replace('_', ' ')} for ${c.label}"
                    style="width:100%;min-height:48px;box-sizing:border-box;text-align:left;background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:7px 8px;font:inherit;cursor:pointer;">
              <span class="mp-cell-name" style="display:block;font-size:12px;line-height:1.25;color:${it.name ? '#0D1B2A' : '#94A3B8'};font-weight:${it.name ? '600' : '400'};">${it.name ? esc(it.name) : '+ Add'}</span>
              <span class="mp-cell-alg" style="display:block;font-size:11px;line-height:1.2;color:#92400E;margin-top:2px;">${it.allergens ? '\u26A0 ' + esc(it.allergens) : ''}</span>
            </button>
          </td>`;
        });
        table += '</tr>';
      });
      table += '</tbody></table>';

      const isThis = _ymd(cur) === _ymd(thisMonday);
      const navBtn = 'width:30px;height:32px;border:none;background:transparent;border-radius:8px;cursor:pointer;font-size:14px;color:#1F6080;';
      main.innerHTML = `<div style="padding:16px;max-width:1200px;margin:0 auto;">
        <h2 style="margin:0 0 2px;color:#1F6080;font-size:19px;">Weekly menu</h2>
        ${centreList.length > 1
          ? `<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#374151;font-weight:600;margin:4px 0 2px;">${esc(centreWord)}
               <select id="mp-centre" style="height:32px;padding:0 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;max-width:280px;">
                 ${centreList.map(c => `<option value="${c.id}" ${+c.id === +cid ? 'selected' : ''}>${esc(c.name || ('#' + c.id))}</option>`).join('')}
               </select></label>`
          : `<div style="color:#6B7280;font-size:12.5px;">${esc(centreNameOf(cid))}</div>`}
        ${allergyHtml}
        <div style="display:flex;align-items:center;gap:2px;background:#F3F4F6;border-radius:10px;padding:2px;width:fit-content;margin-top:6px;">
          <button id="mp-prev" title="Previous week" style="${navBtn}">◀</button>
          <span style="font-weight:700;font-size:12.5px;color:#111827;min-width:120px;text-align:center;">Week of ${fmtDate(weekStartStr)}</span>
          <button id="mp-next" title="Next week" style="${navBtn}">▶</button>
          ${isThis ? '' : `<button id="mp-today" style="height:28px;border:none;background:#fff;border-radius:7px;padding:0 10px;font-size:12px;cursor:pointer;color:#1F6080;font-weight:600;margin-left:4px;">This week</button>`}
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:14px;">
          ${!r.data ? '<span style="font-size:11px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;background:#F1F5F9;color:#475569;padding:4px 10px;border-radius:20px;" title="Nothing has been saved for this week yet.">Not saved yet</span>' : `<span style="font-size:11px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;padding:4px 10px;border-radius:20px;background:${week.status === 'published' ? '#DCFCE7' : '#F1F5F9'};color:${week.status === 'published' ? '#166534' : '#475569'};">${week.status === 'published' ? 'Published' : (week.status === 'archived' ? 'Archived' : 'Draft')}</span>`}
          <label style="font-size:12.5px;color:#374151;font-weight:600;display:flex;align-items:center;gap:7px;" title="Published weeks are visible to families. Draft is only visible to staff.">${!r.data ? 'Save as' : 'Status'}
            <select id="mp-status" style="height:36px !important;min-height:36px !important;min-width:148px;flex:0 0 auto;padding:0 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;vertical-align:middle;">
              <option value="draft" ${week.status === 'draft' ? 'selected' : ''}>Draft</option>
              <option value="published" ${week.status === 'published' ? 'selected' : ''}>Published</option>
              <option value="archived" ${week.status === 'archived' ? 'selected' : ''}>Archived</option>
            </select>
          </label>
          <button id="mp-edit" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" style="height:36px;flex:0 0 auto;margin-right:6px;white-space:nowrap;">✏️ Edit</button><button id="mp-save" title="Save menu" aria-label="Save menu" style="height:36px;flex:0 0 auto;box-sizing:border-box;background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border:0;padding:0 16px;border-radius:8px;cursor:pointer;font:inherit;font-size:13px;font-weight:700;line-height:1;display:inline-flex;align-items:center;gap:7px;justify-content:center;vertical-align:middle;white-space:nowrap;box-shadow:0 6px 14px -8px rgba(31,96,128,.85);">💾 Save menu</button>
        </div>
        <div id="mp-msg" style="font-size:12.5px;color:#047857;min-height:16px;margin-top:7px;"></div>
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">${table}</div>
        <label style="display:block;margin-top:16px;font-size:12.5px;color:#374151;font-weight:600;">Notes (optional)
          <textarea id="mp-notes" rows="3" style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #E5E7EB;border-radius:6px;margin-top:4px;font-size:13px;">${esc(week.notes || '')}</textarea>
        </label>
      </div>`;

      const go = (delta) => { cur.setDate(cur.getDate() + delta); load(); };
      document.getElementById('mp-prev').onclick = () => go(-7);
      document.getElementById('mp-next').onclick = () => go(7);
      const todayBtn = document.getElementById('mp-today'); if (todayBtn) todayBtn.onclick = () => { cur = _mondayOf(new Date()); load(); };

      const centreSel = document.getElementById('mp-centre');
      if (centreSel) {
        centreSel.onchange = () => {
          cid = parseInt(centreSel.value, 10);
          try { localStorage.setItem(CENTRE_KEY, String(cid)); } catch (e) {}
          load();
        };
      }

      /* The grid is READ far more often than it is changed, and a table made of form
         controls invites an accidental edit — one stray keystroke rewrites the week.
         The inputs stay exactly where they are; only their appearance and readOnly
         flag change, so the save handler and every data-d/data-m attribute below are
         untouched. */
      var _menuEditing = false;
      function applyMenuMode() {
        /* EVERYTHING THAT CAN CHANGE THE WEEK, not just the text inputs. The dish cells
           are BUTTONS (they open a dialog), so they sat outside the old input-only sweep
           and were live before Edit was pressed. The serving times use
           [data-meal-time], which [data-m] never matched, and the status select and
           notes were never locked at all. (Anthony, 2026-09-08) */
        Array.prototype.forEach.call(document.querySelectorAll('button.mp-cell'), function (b) {
          b.disabled = !_menuEditing;
          b.style.cursor = _menuEditing ? 'pointer' : 'default';
          b.style.borderColor = _menuEditing ? '#E5E7EB' : 'transparent';
          b.style.background = _menuEditing ? '#fff' : 'transparent';
          var n = b.querySelector('.mp-cell-name');
          if (n) {
            var has = !!(b.parentElement.querySelector('input[data-f="name"]') || {}).value;
            /* An empty slot in a read-only view is a FACT, not an invitation. */
            if (!has) { n.textContent = _menuEditing ? '+ Add' : '—'; }
          }
        });
        Array.prototype.forEach.call(document.querySelectorAll('input[data-meal-time], #mp-notes'), function (t) {
          t.readOnly = !_menuEditing;
          t.style.border = _menuEditing ? '1px solid #E5E7EB' : '1px solid transparent';
          t.style.background = _menuEditing ? '#fff' : 'transparent';
        });
        var st = document.getElementById('mp-status');
        if (st) { st.disabled = !_menuEditing; st.style.opacity = _menuEditing ? '' : '.65'; }

        Array.prototype.forEach.call(document.querySelectorAll('#mp-grid input, table input[data-m]'), function (i) {
          i.readOnly = !_menuEditing;
          if (_menuEditing) {
            i.style.border = i.getAttribute('data-f') === 'allergens' ? '1px solid #FEF3C7' : '1px solid #E5E7EB';
            i.style.background = '#fff';
            i.style.cursor = '';
          } else {
            i.style.border = '1px solid transparent';
            i.style.background = 'transparent';
            i.style.cursor = 'default';
          }
        });
        var eb = document.getElementById('mp-edit');
        var sb = document.getElementById('mp-save');
        if (eb) { eb.innerHTML = _menuEditing ? '✓ Done' : '✏️ Edit'; }
        if (sb) { sb.style.display = _menuEditing ? '' : 'none'; }
      }
      var _eb = document.getElementById('mp-edit');
      if (_eb) { _eb.onclick = function () { _menuEditing = !_menuEditing; applyMenuMode(); }; }
      applyMenuMode();

      /* EDIT A DISH IN A DIALOG. Two stacked inputs in each of 35 cells is unusable
         on a phone, where a cell is ~132px wide. The dialog writes back into the hidden
         inputs, so Save below still collects exactly what it always did. */
      main.querySelectorAll('button.mp-cell').forEach(btn => {
        btn.onclick = () => {
          const dow = btn.getAttribute('data-cd'), meal = btn.getAttribute('data-cm');
          const nameIn = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="name"]`);
          const algIn  = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="allergens"]`);
          const dayLbl = (cols.find(c => String(c.dow) === String(dow)) || {}).label || '';
          const mealLbl = meal.replace('_', ' ');

          const ov = document.createElement('div');
          ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px;';
          ov.innerHTML = `<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(15,23,42,.35);overflow:hidden;">
            <div style="padding:16px 20px;border-bottom:1px solid #EDF2F7;">
              <div style="font-size:16px;font-weight:800;color:#0D1B2A;text-transform:capitalize;">${esc(mealLbl)} &middot; ${esc(dayLbl)}</div>
              <div style="font-size:12.5px;color:#64748B;margin-top:2px;">What is being served, and anything families need to know about.</div>
            </div>
            <div style="padding:16px 20px;">
              <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">Dish</label>
              <input id="mp-dish" value="${esc(nameIn.value)}" placeholder="e.g. Chicken and rice"
                     style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">
              <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Allergens</label>
              <input id="mp-alg" value="${esc(algIn.value)}" placeholder="e.g. dairy, wheat"
                     style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #FEF3C7;border-radius:9px;font:inherit;font-size:14px;color:#92400E;">
              <div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">Leave the dish empty to clear this slot.</div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid #EDF2F7;display:flex;gap:8px;justify-content:flex-end;">
              <button id="mp-x" style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 16px;font:inherit;font-size:14px;color:#475569;cursor:pointer;">Cancel</button>
              <button id="mp-ok" style="background:linear-gradient(135deg,#1F6080,#2c7894);border:0;border-radius:10px;padding:10px 20px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;">Done</button>
            </div>
          </div>`;
          document.body.appendChild(ov);
          const dish = ov.querySelector('#mp-dish');
          dish.focus(); dish.select();
          const close = () => ov.remove();
          ov.querySelector('#mp-x').onclick = close;
          ov.addEventListener('click', e => { if (e.target === ov) close(); });
          ov.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
          ov.querySelector('#mp-ok').onclick = () => {
            const nm = dish.value.trim(), ag = ov.querySelector('#mp-alg').value.trim();
            nameIn.value = nm; algIn.value = ag;
            const nEl = btn.querySelector('.mp-cell-name'), aEl = btn.querySelector('.mp-cell-alg');
            nEl.textContent = nm || '+ Add';
            nEl.style.color = nm ? '#0D1B2A' : '#94A3B8';
            nEl.style.fontWeight = nm ? '600' : '400';
            aEl.textContent = ag ? '\u26A0 ' + ag : '';
            close();
          };
        };
      });

      document.getElementById('mp-save').onclick = async () => {
        const items = [];
        main.querySelectorAll('input[data-d][data-m][data-f="name"]').forEach(inp => {
          const name = inp.value.trim(); if (!name) return;
          const allergens = main.querySelector(`input[data-d="${inp.dataset.d}"][data-m="${inp.dataset.m}"][data-f="allergens"]`).value.trim();
          items.push({ day_of_week: +inp.dataset.d, meal_type: inp.dataset.m, name, allergens: allergens || null });
        });
        const status = document.getElementById('mp-status').value;
        const msg = document.getElementById('mp-msg');
        msg.textContent = 'Saving…'; msg.style.color = '#6B7280';
        try {
          const meal_times = {};
          main.querySelectorAll('input[data-meal-time]').forEach(t => {
            if (t.value) { meal_times[t.getAttribute('data-meal-time')] = t.value; }
          });
          await Api.post('/operations/menu', { centre_id: cid, week_start: weekStartStr, status, notes: document.getElementById('mp-notes').value, items, meal_times });
          msg.textContent = status === 'published' ? 'Published — families notified.' : 'Saved.'; msg.style.color = '#047857';
          /* Save IS the completion — no second confirmation. Only on success: a failed
             save keeps the grid editable, because the work is still in the boxes and
             dropping to read-only would look like it saved. (Anthony, 2026-09-08) */
          _menuEditing = false; applyMenuMode();
        } catch (e) { msg.textContent = 'Save failed.'; msg.style.color = '#B91C1C'; }
      };
    }
    load();
  }

  // Parent-facing weekly menu — read-only, published weeks only, day-by-day cards
  // with allergen chips and today highlighted; prev/next week navigation.
  async function renderParentMenu(main) {
    const dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const mealOrder = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'];
    const mealMeta = { breakfast: ['🍳', 'Breakfast'], morning_snack: ['🍎', 'Morning snack'], lunch: ['🍽️', 'Lunch'], afternoon_snack: ['🧃', 'Afternoon snack'], dinner: ['🍲', 'Dinner'] };
    const thisMonday = _mondayOf(new Date());
    let cur = _mondayOf(new Date());

    async function load() {
      main.innerHTML = '<div style="padding:24px;color:#64748B;">Loading menu…</div>';
      const weekStartStr = _ymd(cur);
      const r = await Api.get(`/parent/menu?week_start=${weekStartStr}`).catch(() => ({}));
      const centre = r && r.centre; const items = (r && r.items) || [];
      const mealTimes = (r && r.meal_times) || {};
      const openDays = (r && Array.isArray(r.open_days) && r.open_days.length) ? r.open_days : [1, 2, 3, 4, 5];
      const byDay = {}; items.forEach(it => { if (openDays.indexOf(it.day_of_week) === -1) return; (byDay[it.day_of_week] = byDay[it.day_of_week] || []).push(it); });
      const isThisWeek = _ymd(cur) === _ymd(thisMonday);
      const todayDow = ((new Date().getDay() + 6) % 7) + 1;
      const navBtn = 'width:36px;height:36px;border:none;background:transparent;border-radius:8px;cursor:pointer;font-size:15px;color:#0FA3B1;';

      let html = `<div style="padding:14px;max-width:680px;margin:0 auto;">
        <div class="kt-hero" style="background:linear-gradient(135deg,#0FA3B1,#1F6080 75%);color:#fff;border-radius:16px;padding:16px 18px;">
          <div style="font-size:12px;opacity:.9;font-weight:700;letter-spacing:.04em;">🍽️ ON THE MENU</div>
          <h1 style="margin:4px 0 0;font-size:20px;">${esc(centre ? centre.name : 'Weekly menu')}</h1>
        </div>
        <div style="display:flex;align-items:center;justify-content:center;gap:6px;background:#F3F4F6;border-radius:12px;padding:4px;margin:12px 0;">
          <button id="pm-prev" title="Previous week" style="${navBtn}">◀</button>
          <span style="font-weight:800;font-size:13px;color:#111827;min-width:150px;text-align:center;">Week of ${fmtDate(weekStartStr)}</span>
          <button id="pm-next" title="Next week" style="${navBtn}">▶</button>
        </div>`;
      if (!isThisWeek) html += `<div style="text-align:center;margin:-4px 0 10px;"><button id="pm-today" style="border:1px solid #D1D5DB;background:#fff;border-radius:8px;padding:5px 12px;font-size:12.5px;cursor:pointer;">Jump to this week</button></div>`;

      if (!centre) {
        html += `<div style="text-align:center;color:#64748B;padding:40px 16px;">We couldn't find your centre yet.</div>`;
      } else if (!Object.keys(byDay).length) {
        html += `<div style="text-align:center;color:#64748B;padding:44px 16px;"><div style="font-size:42px;">🍽️</div><div style="margin-top:10px;font-size:14px;">No menu published for this week yet.</div></div>`;
      } else {
        for (let d = 1; d <= 7; d++) {
          const dayItems = byDay[d]; if (!dayItems || !dayItems.length) continue;
          const isToday = isThisWeek && d === todayDow;
          const dayDate = new Date(cur); dayDate.setDate(dayDate.getDate() + (d - 1));
          const map = {}; dayItems.forEach(it => { map[it.meal_type] = it; });
          html += `<div style="background:#fff;border:1px solid ${isToday ? '#0FA3B1' : '#EAECEF'};${isToday ? 'box-shadow:0 0 0 2px rgba(15,163,177,.15);' : ''}border-radius:14px;padding:12px 14px;margin-bottom:10px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
              <span style="font-weight:800;font-size:14.5px;color:#0D1B2A;">${dayNames[d - 1]}</span>
              <span style="font-size:11.5px;color:#64748B;">${dayDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>
              ${isToday ? '<span style="margin-left:auto;background:#0FA3B1;color:#fff;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:10px;">TODAY</span>' : ''}
            </div>`;
          mealOrder.forEach(mt => {
            const it = map[mt]; if (!it) return;
            const meta = mealMeta[mt] || ['•', mt];
            html += `<div style="display:flex;gap:10px;padding:7px 0;border-top:1px solid #F3F4F6;">
              <span style="font-size:17px;flex-shrink:0;width:22px;text-align:center;">${meta[0]}</span>
              <div style="min-width:0;flex:1;">
                <div style="font-size:10.5px;color:#64748B;font-weight:700;text-transform:uppercase;letter-spacing:.03em;">${meta[1]}${mealTimes[mt] ? ` · ${esc(mealTimes[mt])}` : ''}</div>
                <div style="font-size:14px;color:#0D1B2A;">${esc(it.name)}</div>
                ${it.allergens ? `<div style="margin-top:3px;"><span style="display:inline-block;background:#FEF3C7;color:#92400E;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:8px;">⚠ ${esc(it.allergens)}</span></div>` : ''}
              </div>
            </div>`;
          });
          html += `</div>`;
        }
        if (r.data && r.data.notes) html += `<div style="background:#F9FAFB;border:1px solid #EAECEF;border-radius:12px;padding:12px 14px;font-size:13px;color:#374151;"><strong>Notes:</strong> ${esc(r.data.notes)}</div>`;
      }
      html += `</div>`;
      main.innerHTML = html;
      const go = (delta) => { cur.setDate(cur.getDate() + delta); load(); };
      document.getElementById('pm-prev').onclick = () => go(-7);
      document.getElementById('pm-next').onclick = () => go(7);
      const t = document.getElementById('pm-today'); if (t) t.onclick = () => { cur = _mondayOf(new Date()); load(); };
    }
    load();
  }

  // ============================ Allergy banner ============================
  async function renderAllergyAlerts(main) {
    main.innerHTML = '<div style="padding:24px;">Loading alerts…</div>';
    const r = await Api.get('/operations/allergy-alerts').catch(() => ({ data: [] }));
    const unrec = (r && r.unrecorded) || [];
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#B91C1C;">⚠ Allergy & dietary alerts</h2>
      <p style="color:#6B7280;font-size:13px;">${r.count || 0} child(ren) with active alerts. Display on the Today screen and in the kitchen.</p>
      ${unrec.length ? `<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:14px 16px;margin:14px 0;">
        <div style="font-weight:800;font-size:14px;color:#92400E;">Nothing recorded for ${unrec.length} enrolled ${unrec.length === 1 ? 'child' : 'children'}</div>
        <div style="margin-top:6px;font-size:13px;color:#78350F;line-height:1.6;">
          An empty list above means no allergies have been <em>entered</em> — not that there are none.
          Confirm with each family and record it on the child&rsquo;s record, even when the answer is none.
        </div>
        <div style="margin-top:9px;font-size:12.5px;color:#92400E;">
          ${unrec.map(k => esc(k.first_name + ' ' + (k.last_name || ''))).join(' · ')}
        </div>
      </div>` : ''}
      <div style="margin-top:18px;">
        ${(r.data || []).map(c => `<div style="border-left:4px solid #B91C1C;background:#FEF2F2;padding:14px 18px;margin-bottom:10px;border-radius:0 8px 8px 0;">
          <strong style="color:#991B1B;font-size:16px;">${esc(c.first_name)} ${esc(c.last_name)}</strong>
          <div style="margin-top:6px;font-size:13px;color:#374151;">
            ${(c.tags || []).map(t => {
              const colors = { allergy: { bg: '#FEE2E2', fg: '#991B1B' }, health: { bg: '#FEF3C7', fg: '#92400E' }, dietary: { bg: '#DBEAFE', fg: '#1E40AF' } };
              const sev = t.severity || 'note';
              const sevDot = sev === 'anaphylactic' ? '🚨' : sev === 'severe' ? '⚠' : '';
              const c = colors[t.kind] || { bg: '#F3F4F6', fg: '#374151' };
              return `<span style="background:${c.bg};color:${c.fg};padding:4px 10px;border-radius:6px;margin-right:6px;margin-bottom:4px;font-size:13px;display:inline-block;font-weight:600;">${sevDot} ${esc((t.kind || '').toUpperCase())}: ${esc(t.label || '')}</span>`;
            }).join('')}
          </div></div>`).join('') || (unrec.length
          ? '<div style="color:#64748B;padding:20px;">No allergy or dietary information has been entered yet — see above.</div>'
          : '<div style="color:#64748B;padding:20px;">No active allergy or dietary alerts.</div>')}
      </div>
    </div>`;
  }

  // ============================ Field trips ============================
  async function renderFieldTrips(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/field-trips').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0;color:#1F6080;">Field trips</h2>
        <button id="ft-new" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:6px;cursor:pointer;">+ Plan trip</button>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Trip</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Date</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th>
          <th></th></tr></thead>
        <tbody>${(r.data || []).map(t => `<tr>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><strong>${esc(t.title)}</strong><div style="color:#6B7280;font-size:12px;">${esc(t.destination)}</div></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(t.trip_date)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${esc(t.centre_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="background:#EFF6FF;color:#1E40AF;padding:2px 8px;border-radius:4px;font-size:12px;">${esc(t.status)}</span></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;white-space:nowrap;"><button data-ft-id="${t.id}" class="kt-act-icon kt-act-info kt-icon-tip" title="Permission slips" data-kttip="Permission slips" aria-label="Permission slips">🔑</button><button data-ft-del="${t.id}" class="kt-act-icon kt-act-danger kt-icon-tip" title="Delete trip" data-kttip="Delete" aria-label="Delete">🗑️</button></td>
        </tr>`).join('') || '<tr><td colspan="5" style="padding:24px;color:#64748B;text-align:center;">No field trips planned.</td></tr>'}
        </tbody></table>
    </div>`;
    document.getElementById('ft-new').onclick = () => openFieldTripModal();
    main.querySelectorAll('button[data-ft-id]').forEach(b => b.onclick = () => openPermissionsModal(+b.getAttribute('data-ft-id'), b));
    main.querySelectorAll('button[data-ft-del]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Delete this field trip? This removes the trip and its permission slips.')) return;
      try {
        await Api.delete('/operations/field-trips/' + b.getAttribute('data-ft-del'));
        if (window.KT && KT.toast) KT.toast('🗑', 'Deleted', 'Field trip removed.', '#0F172A');
        renderFieldTrips(main);
      } catch (e) {
        if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', (e && e.message) || 'Try again.', '#DC2626');
      }
    });
    // Real <table> but renders async — nudge the ⋮ kebab sweep.
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }
  async function openFieldTripModal() {
    /* v22p70: fix field-trip modal response keys */
    const childrenRes = await Api.get('/admin/children').catch(() => ({ children: [] }));
    const centresRes = await Api.get('/director/centres').catch(() => ({ centres: [] }));
    const children = childrenRes.children || childrenRes.data || [];
    const centres = centresRes.centres || centresRes.data || (Array.isArray(centresRes) ? centresRes : []);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:560px;width:92%;max-height:88vh;overflow:auto;">
      <h3 style="margin:0 0 12px;">New field trip</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Centre <select id="ft-centre" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Title <input id="ft-title" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Destination <input id="ft-dest" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Trip date <input id="ft-date" type="date" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Cost per child ($) <input id="ft-cost" type="number" step="0.01" value="0" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Children invited</label>
      <div id="ft-children" style="max-height:200px;overflow:auto;border:1px solid #E5E7EB;border-radius:4px;padding:8px;">
        ${children.map(c => `<label style="display:block;padding:4px 0;font-size:13px;"><input type="checkbox" data-cid="${c.id}"> ${esc(c.first_name)} ${esc(c.last_name)}</label>`).join('') || '<em>No children loaded.</em>'}
      </div>
      <div style="margin-top:18px;text-align:right;">
        <button id="ft-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="ft-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Create + send permission slips</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#ft-cancel').onclick = () => m.remove();
    m.querySelector('#ft-save').onclick = async () => {
      const childIds = Array.from(m.querySelectorAll('input[data-cid]:checked')).map(c => +c.dataset.cid);
      if (!childIds.length) { alert('Pick at least one child'); return; }
      await Api.post('/operations/field-trips', {
        centre_id: +m.querySelector('#ft-centre').value,
        title: m.querySelector('#ft-title').value,
        destination: m.querySelector('#ft-dest').value,
        trip_date: m.querySelector('#ft-date').value,
        cost_per_child: parseFloat(m.querySelector('#ft-cost').value) || 0,
        child_ids: childIds,
      });
      m.remove();
      renderFieldTrips(document.querySelector('main') || document.getElementById('main'));
    };
  }
  async function openPermissionsModal(tripId, btn) {
    // Guard against a silent failure: a rejected fetch used to leave the button
    // looking like it "did nothing". Show a busy state and surface any error.
    const done = (btn && window.KT && KT.busy) ? KT.busy(btn) : function () {};
    let r;
    try {
      r = await Api.get(`/operations/field-trips/${tripId}/permissions`);
    } catch (e) {
      done();
      if (window.KT && KT.toast) KT.toast('⚠️', 'Could not load permissions', e && e.message ? e.message : 'Please try again.', '#DC2626');
      else window.alert('Could not load permissions: ' + (e && e.message ? e.message : 'error'));
      return;
    }
    done();
    r = r || {};
    const counts = r.counts || { approved: 0, denied: 0, pending: 0 };
    r.counts = counts;
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:520px;width:92%;">
      <h3>Permissions</h3>
      <div style="display:flex;gap:10px;margin-bottom:12px;">
        <div style="flex:1;background:#DCFCE7;padding:12px;border-radius:6px;text-align:center;"><div style="font-size:11px;color:#166534;font-weight:600;">APPROVED</div><div style="font-size:22px;font-weight:700;color:#166534;">${r.counts.approved}</div></div>
        <div style="flex:1;background:#FEE2E2;padding:12px;border-radius:6px;text-align:center;"><div style="font-size:11px;color:#B91C1C;font-weight:600;">DENIED</div><div style="font-size:22px;font-weight:700;color:#B91C1C;">${r.counts.denied}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:12px;border-radius:6px;text-align:center;"><div style="font-size:11px;color:#92400E;font-weight:600;">PENDING</div><div style="font-size:22px;font-weight:700;color:#92400E;">${r.counts.pending}</div></div>
      </div>
      <div style="max-height:300px;overflow:auto;">${(r.data || []).map(p => `<div style="padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;display:flex;justify-content:space-between;">
        <span>${esc(p.first_name)} ${esc(p.last_name)}</span>
        <span style="color:${p.status === 'approved' ? '#047857' : p.status === 'denied' ? '#B91C1C' : '#D97706'};font-weight:600;">${p.status}</span></div>`).join('')}</div>
      <div style="margin-top:14px;text-align:right;"><button id="pm-close" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Close</button></div>
    </div>`;
    document.body.appendChild(m);
    m.querySelector('#pm-close').onclick = () => m.remove();
  }

  // ============================ Substitutes ============================
  async function renderSubstitutes(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const [pool, requests] = await Promise.all([
      Api.get('/operations/substitutes').catch(() => ({ data: [] })),
      Api.get('/operations/sub-requests').catch(() => ({ data: [] })),
    ]);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Substitute teachers</h2>
      <p style="color:#6B7280;font-size:14px;">Manage your on-call pool and post open shifts.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;">
        <div>
          <h3 style="font-size:14px;color:#374151;">Pool (${(pool.data || []).length})</h3>
          <table data-kt-filtered="1" style="width:100%;border-collapse:collapse;table-layout:fixed;">
            <thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;">Name</th><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;width:90px;">Priority</th></tr></thead>
            <tbody>${(pool.data || []).map(p => `<tr><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(p.user_name)}</td><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${p.contact_priority}</td></tr>`).join('') || '<tr><td colspan="2" style="padding:14px;color:#64748B;font-size:13px;">No substitutes added yet.</td></tr>'}</tbody>
          </table>
        </div>
        <div>
          <h3 style="font-size:14px;color:#374151;">Recent requests (${(requests.data || []).length})</h3>
          <table data-kt-filtered="1" style="width:100%;border-collapse:collapse;table-layout:fixed;">
            <thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;width:110px;">Date</th><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;width:90px;">Status</th><th style="text-align:left;padding:6px;border-bottom:1px solid #E5E7EB;font-size:11px;color:#6B7280;">Filled by</th></tr></thead>
            <tbody>${(requests.data || []).map(r => `<tr><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${fmtDate(r.shift_date)}</td>
              <td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;"><span style="color:${r.status === 'filled' ? '#047857' : '#D97706'};font-weight:600;">${r.status}</span></td>
              <td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(r.assigned_name || '')}</td></tr>`).join('') || '<tr><td colspan="3" style="padding:14px;color:#64748B;font-size:13px;">No requests yet.</td></tr>'}</tbody>
          </table>
          <button id="sr-new" style="margin-top:12px;background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">+ Post open shift</button>
        </div>
      </div>
    </div>`;
    document.getElementById('sr-new').onclick = () => openSubRequestModal();
  }
  function openSubRequestModal() {
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:440px;width:92%;">
      <h3>New substitute request</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Centre ID <input id="sr-cid" type="number" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Shift date <input id="sr-date" type="date" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Start <input id="sr-start" type="time" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">End <input id="sr-end" type="time" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Reason <input id="sr-reason" placeholder="sick / personal / vacation" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;"></label>
      <div style="margin-top:18px;text-align:right;">
        <button id="sr-cancel" style="background:#F3F4F6;border:0;padding:9px 16px;border-radius:4px;margin-right:8px;cursor:pointer;">Cancel</button>
        <button id="sr-save" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:4px;cursor:pointer;">Post + notify</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#sr-cancel').onclick = () => m.remove();
    m.querySelector('#sr-save').onclick = async () => {
      await Api.post('/operations/sub-requests', {
        centre_id: +m.querySelector('#sr-cid').value,
        shift_date: m.querySelector('#sr-date').value,
        shift_start: m.querySelector('#sr-start').value,
        shift_end: m.querySelector('#sr-end').value,
        reason: m.querySelector('#sr-reason').value,
      });
      m.remove();
      renderSubstitutes(document.querySelector('main') || document.getElementById('main'));
    };
  }

  // ============================ Inspection checklist ============================
  async function renderInspection(main) {
    main.innerHTML = '<div style="padding:24px;">Loading checklist…</div>';
    const r = await Api.get('/operations/inspection');
    const totalDone = r.done; const totalAll = r.total;
    const pct = totalAll ? Math.round((totalDone / totalAll) * 100) : 0;
    let html = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 4px;color:#1F6080;">Inspection prep checklist</h2>
      <p style="color:#6B7280;font-size:14px;">Provincial / municipal inspectors typically cover these items. ${totalDone}/${totalAll} marked complete (${pct}%).</p>
      <div style="background:#E5E7EB;height:10px;border-radius:5px;margin:14px 0;"><div style="background:#10B981;height:100%;width:${pct}%;border-radius:5px;"></div></div>`;
    Object.entries(r.data || {}).forEach(([cat, items]) => {
      html += `<h3 style="margin-top:24px;color:#1F6080;font-size:16px;">${esc(cat)}</h3><table style="width:100%;border-collapse:collapse;"><thead><tr><th style="text-align:left;padding:10px 12px;border-bottom:2px solid #E5E7EB;font-size:12px;color:#6B7280;">Checklist item</th><th style="text-align:left;padding:10px 12px;border-bottom:2px solid #E5E7EB;font-size:12px;color:#6B7280;width:300px;">Status</th></tr></thead><tbody>`;
      items.forEach(it => {
        const bg = it.status === 'pass' ? '#DCFCE7' : it.status === 'fail' ? '#FEE2E2' : it.status === 'na' ? '#F3F4F6' : '#FFFFFF';
        html += `<tr style="background:${bg}"><td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;font-size:13px;">${esc(it.item_text)}</td>
          <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;width:300px;">
            <select data-it-id="${it.id}" style="padding:6px;border:1px solid #E5E7EB;border-radius:4px;font-size:12px;">
              <option value="pending" ${it.status === 'pending' ? 'selected' : ''}>Pending</option>
              <option value="pass" ${it.status === 'pass' ? 'selected' : ''}>✓ Pass</option>
              <option value="fail" ${it.status === 'fail' ? 'selected' : ''}>✗ Fail</option>
              <option value="na" ${it.status === 'na' ? 'selected' : ''}>N/A</option></select></td></tr>`;
      });
      html += '</tbody></table>';
    });
    html += '</div>';
    main.innerHTML = html;
    main.querySelectorAll('select[data-it-id]').forEach(s => s.onchange = async () => {
      await Api.patch(`/operations/inspection/${s.dataset['it-id']}`, { status: s.value });
      s.closest('tr').style.background = s.value === 'pass' ? '#DCFCE7' : s.value === 'fail' ? '#FEE2E2' : s.value === 'na' ? '#F3F4F6' : '#FFFFFF';
    });
  }

  // ============================ CWELCC report ============================
  async function renderCwelcc(main) {
    main.innerHTML = '<div style="padding:24px;">Loading CWELCC report…</div>';
    const month = new Date().toISOString().slice(0, 7);
    const r = await Api.get(`/compliance/cwelcc/monthly?month=${month}`);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <h2 style="margin:0 0 4px;color:#1F6080;">CWELCC subsidy report</h2>
          <div style="color:#6B7280;font-size:14px;">Month: <input id="cw-month" type="month" value="${month}" style="padding:6px;border:1px solid #E5E7EB;border-radius:4px;font-size:14px;"></div>
        </div>
        <div>
          <button id="cw-csv" style="background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-right:8px;cursor:pointer;">⤓ CSV</button>
          <button id="cw-pdf" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;">⤓ PDF</button>
        </div>
      </div>
      <div style="display:flex;gap:14px;margin-top:18px;">
        <div style="flex:1;background:#EFF6FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#1E40AF;font-weight:600;">CHILDREN</div><div style="font-size:24px;font-weight:700;color:#1E40AF;">${r.totals.child_count}</div></div>
        <div style="flex:1;background:#F0FDF4;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#166534;font-weight:600;">GROSS</div><div style="font-size:24px;font-weight:700;color:#166534;">$${(r.totals.gross || 0).toFixed(2)}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#92400E;font-weight:600;">SUBSIDY</div><div style="font-size:24px;font-weight:700;color:#92400E;">$${(r.totals.subsidy || 0).toFixed(2)}</div></div>
        <div style="flex:1;background:#FAE8FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#86198F;font-weight:600;">PARENT</div><div style="font-size:24px;font-weight:700;color:#86198F;">$${(r.totals.parent || 0).toFixed(2)}</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:24px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Child</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Family</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Gross</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Subsidy</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Parent</th>
        </tr></thead>
        <tbody>${(r.data || []).map(row => `<tr>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.child_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.family_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.centre_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.gross_fee.toFixed(2)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.subsidy_amount.toFixed(2)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.parent_portion.toFixed(2)}</td>
        </tr>`).join('') || '<tr><td colspan="6" style="padding:24px;color:#64748B;text-align:center;">No CWELCC-enrolled families for this month.</td></tr>'}</tbody>
      </table>
    </div>`;
    document.getElementById('cw-month').onchange = (e) => { renderCwelcc(main); };
    document.getElementById('cw-csv').onclick = () => downloadAuthed(`/compliance/cwelcc/monthly/csv?month=${document.getElementById('cw-month').value}`, `CWELCC-${month}.csv`);
    document.getElementById('cw-pdf').onclick = () => downloadAuthed(`/compliance/cwelcc/monthly/pdf?month=${document.getElementById('cw-month').value}`, `CWELCC-${month}.pdf`);
  }

  // ============================ Cohort retention ============================
  async function renderRetention(main) {
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/compliance/retention');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Cohort retention</h2>
      <p style="color:#6B7280;font-size:14px;">% of children still enrolled, broken down by enrolment-month cohort. 12-month rolling window.</p>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Cohort</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Enrolled</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Still</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Retention</th>
        </tr></thead>
        <tbody>${(r.data || []).map(c => {
          const pct = c.retention_pct == null ? '—' : c.retention_pct + '%';
          const w = c.retention_pct == null ? 0 : Math.max(2, c.retention_pct);
          const color = c.retention_pct == null ? '#E5E7EB' : c.retention_pct >= 80 ? '#10B981' : c.retention_pct >= 50 ? '#F59E0B' : '#EF4444';
          return `<tr><td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-weight:600;">${c.cohort}</td>
            <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${c.enrolled}</td>
            <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${c.still_enrolled}</td>
            <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;"><div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;background:#E5E7EB;height:12px;border-radius:6px;max-width:240px;"><div style="background:${color};height:100%;width:${w}%;border-radius:6px;"></div></div><span style="color:${color};font-weight:600;min-width:50px;">${pct}</span></div></td></tr>`;
        }).join('')}</tbody></table>
    </div>`;
  }

  // ============================ Enrollment forecast ============================
  async function renderForecast(main) {
    main.innerHTML = '<div style="padding:24px;">Computing forecast…</div>';
    const r = await Api.get('/ai/enrollment-forecast');
    const maxP = Math.max(...r.projection.map(p => p.projected_enrolment), r.current_enrolment);
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Enrolment forecast</h2>
      <p style="color:#6B7280;font-size:14px;">6-month projection using tour pipeline + assumed 30% conversion + 2.5% monthly churn.</p>
      <div style="display:flex;gap:14px;margin-top:18px;">
        <div style="flex:1;background:#EFF6FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#1E40AF;font-weight:600;">CURRENT</div><div style="font-size:24px;font-weight:700;color:#1E40AF;">${r.current_enrolment}</div></div>
        <div style="flex:1;background:#FAE8FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#86198F;font-weight:600;">TOUR PIPELINE (60d)</div><div style="font-size:24px;font-weight:700;color:#86198F;">${r.tour_pipeline_60d}</div></div>
        <div style="flex:1;background:#DCFCE7;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#166534;font-weight:600;">MONTHLY ADDS</div><div style="font-size:24px;font-weight:700;color:#166534;">+${r.monthly_adds_assumption}</div></div>
      </div>
      <h3 style="margin-top:24px;font-size:14px;color:#374151;">Projection</h3>
      <div style="display:flex;align-items:flex-end;gap:14px;height:200px;padding:18px;background:#F9FAFB;border-radius:8px;">
        ${r.projection.map(p => `<div style="flex:1;text-align:center;">
          <div style="background:#1F6080;height:${(p.projected_enrolment / maxP) * 160}px;border-radius:6px 6px 0 0;display:flex;align-items:flex-end;justify-content:center;color:#fff;font-weight:700;padding-bottom:6px;font-size:13px;">${p.projected_enrolment}</div>
          <div style="margin-top:6px;font-size:11px;color:#6B7280;">${p.month}</div></div>`).join('')}
      </div>
    </div>`;
  }

  // ============================ Anomaly detection ============================
  async function renderAnomalies(main) {
    main.innerHTML = '<div style="padding:24px;">Scanning…</div>';
    const r = await Api.get('/ai/attendance-anomalies?days=30');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Attendance anomalies</h2>
      <p style="color:#6B7280;font-size:14px;">${(r.data || []).length} child(ren) with unusual activity patterns in the last ${r.window_days} days.</p>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Child</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Signals</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Obs 30d</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Obs 90d</th>
        </tr></thead>
        <tbody>${(r.data || []).map(a => `<tr>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-weight:600;">${esc(a.child_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;color:#B91C1C;">${a.signals.join(' · ')}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${a.obs_30}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;">${a.obs_90}</td>
        </tr>`).join('') || '<tr><td colspan="4" style="padding:24px;color:#64748B;text-align:center;">No anomalies — all children showing normal activity.</td></tr>'}</tbody></table>
    </div>`;
  }

  // ============================ Cert expiry calendar ============================
  async function renderExpiryCalendar(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/compliance/expiry-calendar?within_days=180');
    const groups = { expired: [], soon: [], upcoming: [], future: [] };
    (r.data || []).forEach(row => groups[row.bucket].push(row));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Renewal calendar</h2>
      <p style="color:#6B7280;font-size:14px;">Certifications + background checks. Combines all sources into a single timeline.</p>
      ${['expired', 'soon', 'upcoming', 'future'].map(b => {
        const titles = { expired: '⛔ Expired', soon: '⚠ Next 30 days', upcoming: 'Next 90 days', future: 'Later' };
        const colors = { expired: '#FEE2E2', soon: '#FEF3C7', upcoming: '#EFF6FF', future: '#F9FAFB' };
        return `<h3 style="margin-top:22px;font-size:15px;">${titles[b]} (${groups[b].length})</h3>
          ${groups[b].length ? `<table style="width:100%;border-collapse:collapse;"><thead><tr><th style="text-align:left;padding:9px 12px;border-bottom:2px solid #E5E7EB;font-size:11px;color:#6B7280;">Name / certification</th><th style="text-align:left;padding:9px 12px;border-bottom:2px solid #E5E7EB;font-size:11px;color:#6B7280;">Expires</th></tr></thead><tbody>${groups[b].map(r => `<tr style="background:${colors[b]};">
            <td style="padding:9px 12px;border-bottom:1px solid #F3F4F6;font-size:13px;width:60%;"><strong>${esc(r.user_name)}</strong> · ${esc(r.type)} <span style="color:#6B7280;font-size:11px;">(${esc(r.source)})</span></td>
            <td style="padding:9px 12px;border-bottom:1px solid #F3F4F6;font-size:13px;">${fmtDate(r.expires_at)} <span style="color:#6B7280;font-size:11px;">(${Math.abs(r.days_until)}d ${r.days_until < 0 ? 'ago' : 'left'})</span></td>
          </tr>`).join('')}</tbody></table>` : '<div style="color:#64748B;padding:8px;font-size:13px;">None.</div>'}`;
      }).join('')}
    </div>`;
  }

  // Expose
  window.KT = window.KT || {};
  window.KT.V22p53 = {
    renderMenu, renderParentMenu, renderAllergyAlerts, renderFieldTrips, renderSubstitutes,
    renderInspection, renderCwelcc, renderRetention, renderForecast,
    renderAnomalies, renderExpiryCalendar,
  };
})(window);
