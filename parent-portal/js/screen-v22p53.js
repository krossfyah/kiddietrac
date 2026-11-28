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
  /* A date-only value ("2026-10-19") is a calendar day: new Date() reads it as UTC midnight and
     showed the day before in every western zone ("Week of Oct 18" for a Monday-19 week, trips a
     day early — 2026-10-24). Formatted from its parts by KT.dayLabel; instants still go through Date. */
  const fmtDate = (s) => { if (!s) return ''; if (/^\d{4}-\d{2}-\d{2}$/.test(String(s)) && window.KT && KT.dayLabel) return KT.dayLabel(String(s)); const d = new Date(s); return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); };
  const userRoles = () => { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles || []; } catch (e) { return []; } };
  const isStaff = () => userRoles().some(r => ['agency_admin', 'centre_director', 'educator', 'platform_admin'].includes(r));

  async function downloadAuthed(path, filename) {
    const tok = sessionStorage.getItem('kt_token');
    /* The active agency goes with it (2026-09-29): without the header a platform admin
       was answered "Select an agency first" and the CSV/PDF buttons saved that error
       message as the file. */
    const headers = { Authorization: 'Bearer ' + tok };
    const aid = sessionStorage.getItem('kt_active_agency_id');
    if (aid) headers['X-Active-Agency-Id'] = aid;
    const r = await fetch(apiBase() + path, { headers });
    if (!r.ok) {
      let msg = 'Download failed (' + r.status + ')';
      try { const j = await r.json(); if (j && j.message) msg = j.message; } catch (e) {}
      if (window.KT && KT.toast) KT.toast('⚠️', 'Could not download', msg, '#B91C1C');
      return;
    }
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

  // ============================ Food photos on the menu ============================
  /* 2026-10-31, Anthony: "with the meal plan can the educators choose pictures for common foods and show this like how
     you have this in the emulator ... or have AI do this on their behalf". A shared, licensed food library
     (App\Support\FoodPhotos, /operations/food-images); a dish gets a confident library match on its own when the agency
     has "Add food photos automatically" on; staff can choose, upload their own, or remove (a removed photo stays
     removed). The AI Bundle helper places dishes the library match cannot. */
  let _foodLib = null;
  function foodLib(force) {
    if (!_foodLib || force) { _foodLib = Api.get('/operations/food-images').catch(() => ({ data: [], auto: false, ai: false, can_manage: false })); }
    return _foodLib;
  }
  function cellThumb(url, w) {
    w = w || 36; const h = Math.round(w * 0.75);
    if (!url) { return `<span class="mp-cell-img" data-w="${w}" style="display:none;"></span>`; }
    return `<span class="mp-cell-img" data-w="${w}" style="flex:0 0 ${w}px;width:${w}px;height:${h}px;border-radius:6px;overflow:hidden;background:#F1F5F9;display:block;"><img src="${esc(url)}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;"></span>`;
  }
  function photoInputs(d, m, it) {
    return `<input type="hidden" data-d="${d}" data-m="${m}" data-f="img" value="${esc(it.image_url || '')}">`
      + `<input type="hidden" data-d="${d}" data-m="${m}" data-f="imgsrc" value="${esc(it.image_source || '')}">`
      + `<input type="hidden" data-d="${d}" data-m="${m}" data-f="foodid" value="${esc(it.food_image_id || '')}">`;
  }
  const _fold = (t) => String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

  /* The library as a searchable grid. Resolves with the chosen food, or null. */
  function openFoodPicker(query) {
    return new Promise((resolve) => {
      const ov = document.createElement('div');
      ov.style.cssText = 'position:fixed;inset:0;z-index:100001;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
      ov.innerHTML = `<div role="dialog" aria-modal="true" aria-label="Choose a food photo" style="background:#fff;border-radius:16px;max-width:680px;width:100%;height:min(720px,calc(100dvh - 32px));display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(15,23,42,.35);overflow:hidden;">
        <div class="kt-dlg-head" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;">
          <div style="font-size:16px;font-weight:800;flex:1;">🍽 Choose a food photo</div>
          <button type="button" id="fp-x" aria-label="Close" style="background:transparent;border:0;color:#fff;font-size:18px;cursor:pointer;width:32px;height:32px;">✕</button>
        </div>
        <div style="padding:12px 16px 8px;">
          <input id="fp-q" type="search" placeholder="Search foods — e.g. pasta, banana, soup" value="${esc(query || '')}" autocomplete="off"
                 style="width:100%;box-sizing:border-box;height:36px;padding:0 12px;border:1px solid #CBD5E1;border-radius:9px;font:inherit;font-size:14px;">
        </div>
        <div id="fp-grid" style="flex:1;overflow:auto;padding:4px 16px 12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px;align-content:start;">
          <div style="grid-column:1/-1;color:#64748B;font-size:13px;padding:20px 0;text-align:center;">Loading photos…</div>
        </div>
        <div style="padding:8px 16px;border-top:1px solid #EDF2F7;font-size:11px;color:#94A3B8;">Photos: Wikimedia Commons, public domain / CC0. Most used by your team first.</div>
      </div>`;
      document.body.appendChild(ov);
      const close = (v) => { ov.remove(); resolve(v || null); };
      ov.querySelector('#fp-x').onclick = () => close(null);
      ov.addEventListener('click', (e) => { if (e.target === ov) close(null); });
      ov.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(null); } });
      const q = ov.querySelector('#fp-q'), grid = ov.querySelector('#fp-grid');
      if (window.innerWidth > 768) { q.focus(); }
      foodLib().then((lib) => {
        const all = (lib.data || []).slice().sort((a, b) => (b.used - a.used) || a.name.localeCompare(b.name));
        const paint = () => {
          const t = _fold(q.value).trim();
          const words = t.split(/\s+/).filter(Boolean);
          let list = all;
          if (words.length) {
            list = all.filter((f) => { const hay = _fold(f.name + ' ' + (f.synonyms || '')); return words.some((w) => hay.indexOf(w) > -1); })
              .sort((a, b) => {
                const sa = words.filter((w) => _fold(a.name + ' ' + a.synonyms).indexOf(w) > -1).length, sb = words.filter((w) => _fold(b.name + ' ' + b.synonyms).indexOf(w) > -1).length;
                return (sb - sa) || (b.used - a.used) || a.name.localeCompare(b.name);
              });
          }
          if (!list.length) { grid.innerHTML = `<div style="grid-column:1/-1;color:#64748B;font-size:13px;padding:20px 0;text-align:center;">No photo for “${esc(q.value)}”. Try another word, or upload your own.</div>`; return; }
          grid.innerHTML = list.map((f) => `<button type="button" data-id="${f.id}" title="${esc(f.name)}${f.credit ? ' — ' + esc(f.credit) : ''}" style="border:1px solid #E2E8F0;background:#fff;border-radius:11px;padding:0;overflow:hidden;cursor:pointer;text-align:left;font:inherit;">
            <span style="display:block;aspect-ratio:4/3;background:#F1F5F9;"><img src="${esc(f.thumb_url)}" alt="${esc(f.name)}" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;"></span>
            <span style="display:block;padding:5px 7px 6px;font-size:11.5px;font-weight:700;color:#0D1B2A;line-height:1.25;">${esc(f.name)}</span></button>`).join('');
        };
        paint();
        let t = null; q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(paint, 120); });
        grid.addEventListener('click', (e) => {
          const b = e.target.closest('button[data-id]'); if (!b) return;
          close(all.find((f) => String(f.id) === b.dataset.id));
        });
      });
    });
  }

  /* The Photo block inside the dish dialog. st = {url, src, fid}; returns {commit()} for Done. */
  function wireDishPhoto(ov, st, dishIn, algIn) {
    const prev = ov.querySelector('#mp-ph-prev'), sugg = ov.querySelector('#mp-ph-sugg'), hint = ov.querySelector('#mp-ph-hint');
    const rm = ov.querySelector('#mp-ph-rm'), file = ov.querySelector('#mp-ph-file'), note = ov.querySelector('#mp-ph-note');
    let lib = { data: [], auto: false, ai: false };
    foodLib().then((l) => { lib = l || lib; suggest(); });
    function paint() {
      prev.innerHTML = st.url ? `<img src="${esc(st.url)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">` : '<span style="font-size:26px;">🍽</span>';
      rm.style.display = st.url ? '' : 'none';
      note.textContent = st.src === 'auto' ? 'Picked for you — change it if it is not right.' : (st.src === 'none' ? 'No photo for this dish.' : '');
    }
    function pickFood(f, how) {
      st.url = f.thumb_url; st.src = how || 'library'; st.fid = f.id; paint();
      hint.innerHTML = '';
      if (f.allergens && !algIn.value.trim()) {
        hint.innerHTML = `Usually contains: <b>${esc(f.allergens)}</b> · <a href="#" id="mp-ph-addalg" style="color:#1F6080;font-weight:700;">Add to allergens</a>`;
        hint.querySelector('#mp-ph-addalg').onclick = (e) => { e.preventDefault(); algIn.value = f.allergens.replace(/\s*\([^)]*\)/g, ''); hint.innerHTML = ''; };
      }
    }
    let seq = 0;
    async function suggest() {
      const name = dishIn.value.trim(); const my = ++seq;
      sugg.innerHTML = '';
      if (!name) return;
      let r = null; try { r = await Api.post('/operations/food-images/match', { names: [name] }); } catch (e) { return; }
      if (my !== seq || !ov.isConnected) return;
      const m = (r && r.matches && r.matches[name]) || { suggestions: [] };
      const list = (m.suggestions || []).filter((f) => !st.url || f.thumb_url !== st.url);
      if (!st.url && st.src !== 'none' && m.best && lib.auto) { pickFood(m.best, 'auto'); }
      if (list.length) {
        sugg.innerHTML = '<div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;margin:10px 0 5px;">Suggested</div>'
          + '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + list.slice(0, 4).map((f) => `<button type="button" data-id="${f.id}" title="${esc(f.name)}" style="border:1px solid #E2E8F0;background:#fff;border-radius:9px;padding:0;overflow:hidden;cursor:pointer;width:72px;">
            <span style="display:block;width:72px;height:54px;"><img src="${esc(f.thumb_url)}" alt="${esc(f.name)}" style="width:100%;height:100%;object-fit:cover;display:block;"></span>
            <span style="display:block;padding:2px 4px;font-size:10px;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(f.name)}</span></button>`).join('') + '</div>';
        sugg.querySelectorAll('button[data-id]').forEach((b) => { b.onclick = () => { pickFood(list.find((f) => String(f.id) === b.dataset.id)); suggest(); }; });
        if (lib.ai && !m.confident) {
          const ab = document.createElement('button'); ab.type = 'button'; ab.textContent = '✨ Not right? Ask AI';
          ab.style.cssText = 'margin-top:8px;height:28px;padding:0 10px;border:1px solid #C4B5FD;background:#F5F3FF;color:#5B21B6;border-radius:8px;font:inherit;font-size:12px;font-weight:700;cursor:pointer;';
          ab.onclick = async () => {
            ab.disabled = true; ab.textContent = '✨ Looking…';
            try {
              const a = await Api.post('/ai/assist/food-photo', { names: [name] });
              const f = a && a.matches && a.matches[name];
              if (f) { pickFood(f, 'library'); ab.textContent = '✨ AI picked ' + f.name; } else { ab.textContent = 'AI found no better photo'; }
            } catch (err) { ab.textContent = (err && err.message) || 'AI is not available right now'; }
          };
          sugg.appendChild(ab);
        }
      } else if (!st.url && lib.ai) {
        sugg.innerHTML = '<button type="button" id="mp-ph-ai" style="margin-top:10px;height:32px;padding:0 12px;border:1px solid #C4B5FD;background:#F5F3FF;color:#5B21B6;border-radius:8px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;">✨ Find a photo with AI</button>';
        sugg.querySelector('#mp-ph-ai').onclick = async (e) => {
          const b = e.currentTarget; b.disabled = true; b.textContent = '✨ Looking…';
          try {
            const a = await Api.post('/ai/assist/food-photo', { names: [name] });
            const f = a && a.matches && a.matches[name];
            if (f) { pickFood(f, 'library'); sugg.innerHTML = ''; } else { b.textContent = 'No good match — choose or upload one'; }
          } catch (err) { b.textContent = (err && err.message) || 'AI is not available right now'; }
        };
      }
    }
    let t = null;
    dishIn.addEventListener('input', () => {
      if (st.src === 'auto') { st.url = ''; st.src = ''; st.fid = ''; paint(); }
      clearTimeout(t); t = setTimeout(suggest, 380);
    });
    ov.querySelector('#mp-ph-pick').onclick = async () => { const f = await openFoodPicker(dishIn.value.trim().split(/\s+/)[0] || ''); if (f) { pickFood(f); suggest(); } };
    ov.querySelector('#mp-ph-up').onclick = () => file.click();
    file.onchange = async () => {
      const f = file.files && file.files[0]; if (!f) return;
      note.textContent = 'Uploading…';
      try {
        const fd = new FormData(); fd.append('photo', f);
        const r = await Api.postForm('/operations/menu/photo', fd);
        st.url = r.thumb_url; st.src = 'upload'; st.fid = ''; paint();
      } catch (e) { note.textContent = (e && e.message) || 'That photo could not be uploaded.'; }
      file.value = '';
    };
    rm.onclick = () => { st.url = ''; st.src = 'none'; st.fid = ''; paint(); suggest(); };
    paint();
    return st;
  }

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
      /* Always "Select a provider" — never the first one, and not the last one used either
         (2026-10-24, Anthony: "doesnt show select a provider as we asked"). One provider = no choice to make. */
      return centreList.length === 1 ? centreList[0].id : null;
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

    function centrePicker() {
      return `<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#374151;font-weight:600;margin:4px 0 2px;">${esc(centreWord)}
               <select id="mp-centre" style="height:36px;padding:0 30px 0 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13.5px;background-color:#fff;max-width:280px;min-width:0;flex:1 1 auto;">
                 <option value="" disabled ${cid ? '' : 'selected'}>Select a ${esc(centreWord.toLowerCase())}…</option>
                 ${centreList.map(c => `<option value="${c.id}" ${+c.id === +cid ? 'selected' : ''}>${esc(c.name || ('#' + c.id))}</option>`).join('')}
               </select></label>`;
    }
    function wireCentrePicker() {
      const centreSel = main.querySelector('#mp-centre');
      if (centreSel) {
        centreSel.onchange = () => {
          cid = parseInt(centreSel.value, 10) || null;
          try { if (cid) localStorage.setItem(CENTRE_KEY, String(cid)); } catch (e) {}
          load();
        };
      }
    }

    let _tools = null;
    async function load() {
      _tools = null;
      const weekStartStr = _ymd(cur);
      if (!cid) {
        main.innerHTML = `<div style="padding:16px;max-width:1200px;margin:0 auto;">
          ${centrePicker()}
          <div style="margin-top:14px;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;padding:22px 16px;text-align:center;color:#64748B;font-size:13.5px;">🍽 Select a ${esc(centreWord.toLowerCase())} to see and edit its weekly menu.</div>
        </div>`;
        wireCentrePicker();
        return;
      }
      main.innerHTML = '<div style="padding:24px;">Loading menu…</div>';
      const [r, allergyHtml] = await Promise.all([
        Api.get(`/operations/menu?centre_id=${cid}&week_start=${weekStartStr}`).catch(() => ({})),
        allergyPanel(cid),
      ]);
      /* LEFT BEFORE IT LOADED (support ticket #105, 2026-09-27). #appMain is a fresh node
         per screen, so if you tap away while the week is loading, `main` is no longer on
         the page: the grid was drawn into a detached node and the document-wide lookup
         for #mp-prev found nothing: "Cannot set properties of null (setting 'onclick')".
         Stop instead; the lookups below also read from `main`, not the whole page. */
      if (!main.isConnected) { return; }
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
          <td style="padding:8px 8px 8px 10px;border-bottom:1px solid #EDF2F7;--kt-edge:${edge};font-size:12px;position:sticky;left:0;background:${band};z-index:1;">
            <div style="font-weight:700;text-transform:capitalize;color:#0D1B2A;">${m.replace('_', ' ')}</div>
            <!-- A bare clock under a meal name says nothing about what it is for. The
                 aria-label always covered screen readers; this covers everyone else. -->
            <div style="margin-top:5px;font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#94A3B8;">Serves at</div>
            <input data-meal-time="${m}" type="time" value="${esc(mealTimes[m] || '')}" aria-label="${m.replace('_', ' ')} serving time"
                   style="margin-top:3px;width:118px;height:32px;box-sizing:border-box;padding:0 8px;border:1px solid #E5E7EB;border-radius:8px;font-size:12.5px;color:#334155;background:#fff;">
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
            <input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="allergens" value="${esc(it.allergens || '')}"><input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="description" value="${esc(it.description || '')}">${photoInputs(c.dow, m, it)}
            <button type="button" class="mp-cell" data-cd="${c.dow}" data-cm="${m}"
                    aria-label="${it.name ? 'Edit' : 'Add'} ${m.replace('_', ' ')} for ${c.label}"
                    style="width:100%;min-height:48px;box-sizing:border-box;text-align:left;background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:7px 8px;font:inherit;cursor:pointer;display:flex;align-items:center;gap:7px;">${cellThumb(it.image_url, 34)}<span style="display:block;min-width:0;flex:1 1 auto;">
              <span class="mp-cell-name" style="display:block;font-size:12px;line-height:1.25;color:${it.name ? '#0D1B2A' : '#94A3B8'};font-weight:${it.name ? '600' : '400'};">${it.name ? esc(it.name) : '+ Add'}</span>
              <span class="mp-cell-alg" style="display:block;font-size:11px;line-height:1.2;color:#92400E;margin-top:2px;">${it.allergens ? '\u26A0 ' + esc(it.allergens) : ''}</span></span>
            </button>
          </td>`;
        });
        table += '</tr>';
      });
      table += '</tbody></table>';

      /* ON A PHONE (2026-10-23, Anthony: "look at weekly menu and you will see the fields are
         not consistent and needs to look better and the buttons"). The seven-column table was
         being re-laid as cards by kt-mobile-tables: a stray coloured box, a giant clock and a
         column of dashes. Here it is drawn for the phone: a card per meal with its serving
         time, and one row per day. Same hidden inputs and .mp-cell buttons, so editing and
         Save work exactly as on a computer. */
      const phone = window.innerWidth <= 768;
      if (phone) {
        let cards = '<div data-kt-nobreathe="1">';
        meals.forEach((m) => {
          const band = MEAL_TINT[m] || '#FFFFFF', edge = MEAL_EDGE[m] || '#E5E7EB';
          cards += `<div style="background:${band};border:1px solid #E7EDF3;--kt-edge:${edge};border-radius:12px;padding:10px 12px;margin-top:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
              <div style="font-weight:800;font-size:15px;color:#0D1B2A;text-transform:capitalize;">${m.replace('_', ' ')}</div>
              <label style="display:flex;align-items:center;gap:6px;font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;white-space:nowrap;">Serves at
                <input data-meal-time="${m}" type="time" value="${esc(mealTimes[m] || '')}" aria-label="${m.replace('_', ' ')} serving time"
                       style="width:112px !important;height:36px !important;min-height:36px !important;box-sizing:border-box;padding:0 8px !important;border:1px solid #E5E7EB;border-radius:8px;font-size:15px !important;color:#334155;background:#fff;flex:0 0 auto;"></label>
            </div>`;
          cols.forEach(c => {
            const it = itemsByDayMeal[`${c.dow}-${m}`] || {};
            const dt = dateOf(c.dow), isToday = _ymd(dt) === todayStr;
            cards += `<div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-top:1px solid rgba(15,23,42,.06);">
              <div style="flex:0 0 64px;font-size:12.5px;font-weight:800;color:${isToday ? '#1F6080' : '#0D1B2A'};line-height:1.2;">${c.label}${isToday ? ' ·' : ''}
                <div style="font-size:11px;font-weight:600;color:#64748B;">${isToday ? 'today' : dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</div></div>
              <div style="flex:1 1 auto;min-width:0;">
                <input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="name" value="${esc(it.name || '')}">
                <input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="allergens" value="${esc(it.allergens || '')}"><input type="hidden" data-d="${c.dow}" data-m="${m}" data-f="description" value="${esc(it.description || '')}">${photoInputs(c.dow, m, it)}
                <button type="button" class="mp-cell" data-cd="${c.dow}" data-cm="${m}" aria-label="${it.name ? 'Edit' : 'Add'} ${m.replace('_', ' ')} for ${c.label}"
                        style="width:100%;min-height:40px;box-sizing:border-box;text-align:left;background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:7px 10px;font:inherit;cursor:pointer;display:flex;align-items:center;gap:9px;">${cellThumb(it.image_url, 44)}<span style="display:block;min-width:0;flex:1 1 auto;">
                  <span class="mp-cell-name" style="display:block;font-size:13.5px;line-height:1.3;color:${it.name ? '#0D1B2A' : '#94A3B8'};font-weight:${it.name ? '600' : '400'};">${it.name ? esc(it.name) : '+ Add'}</span>
                  <span class="mp-cell-alg" style="display:block;font-size:11.5px;line-height:1.2;color:#92400E;margin-top:2px;">${it.allergens ? '\u26A0 ' + esc(it.allergens) : ''}</span></span>
                </button>
              </div>
            </div>`;
          });
          cards += '</div>';
        });
        table = cards + '</div>';
      }

      const isThis = _ymd(cur) === _ymd(thisMonday);
      const navBtn = 'width:30px;height:32px;border:none;background:transparent;border-radius:8px;cursor:pointer;font-size:14px;color:#1F6080;';
      main.innerHTML = `<div style="padding:16px;max-width:1200px;margin:0 auto;">
        ${centreList.length > 1
          ? centrePicker()
          : `<div style="color:#6B7280;font-size:12.5px;">${esc(centreNameOf(cid))}</div>`}
        ${allergyHtml}
        <div data-kt-nobreathe="1" style="display:flex;align-items:center;gap:2px;background:#F3F4F6;border-radius:10px;padding:2px;width:fit-content;height:36px;box-sizing:border-box;margin-top:6px;">
          <button id="mp-prev" title="Previous week" style="${navBtn}">◀</button>
          <span style="font-weight:700;font-size:12.5px;color:#111827;min-width:120px;text-align:center;">Week of ${fmtDate(weekStartStr)}</span>
          <button id="mp-next" title="Next week" style="${navBtn}">▶</button>
          ${isThis ? '' : `<button id="mp-today" style="height:30px;border:none;background:#fff;border-radius:8px;padding:0 11px;font-size:12.5px;cursor:pointer;color:#1F6080;font-weight:700;margin-left:4px;">This week</button>`}
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:10px 12px;align-items:center;margin-top:14px;">
          ${!r.data ? '<span style="font-size:11px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;background:#F1F5F9;color:#475569;padding:4px 10px;border-radius:20px;" title="Nothing has been saved for this week yet.">Not saved yet</span>' : `<span style="font-size:11px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;padding:4px 10px;border-radius:20px;background:${week.status === 'published' ? '#DCFCE7' : '#F1F5F9'};color:${week.status === 'published' ? '#166534' : '#475569'};">${week.status === 'published' ? 'Published' : (week.status === 'archived' ? 'Archived' : 'Draft')}</span>`}
          <label style="font-size:12.5px;color:#374151;font-weight:600;display:flex;align-items:center;gap:7px;white-space:nowrap;" title="Published weeks are visible to families. Draft is only visible to staff.">${!r.data ? 'Save as' : 'Status'}
            <select id="mp-status" style="height:36px !important;min-height:36px !important;width:140px;flex:0 0 auto;padding:0 30px 0 10px !important;line-height:normal !important;border:1px solid #D1D5DB;border-radius:8px;font-size:13.5px !important;background-color:#fff;box-sizing:border-box;vertical-align:middle;">
              <option value="draft" ${week.status === 'draft' ? 'selected' : ''}>Draft</option>
              <option value="published" ${week.status === 'published' ? 'selected' : ''}>Published</option>
              <option value="archived" ${week.status === 'archived' ? 'selected' : ''}>Archived</option>
            </select>
          </label>
          <span style="display:inline-flex;gap:8px;flex:0 0 auto;"><button id="mp-edit" data-kt-iconized="1" style="height:36px !important;min-height:36px !important;flex:0 0 auto;box-sizing:border-box;background:#fff;color:#1F6080;border:1px solid #CBD5E1;padding:0 14px;border-radius:8px;cursor:pointer;font:inherit;font-size:13px;font-weight:700;line-height:1;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">✏️ Edit</button><button id="mp-save" data-kt-always-save="1" title="Save menu" aria-label="Save menu" style="height:36px !important;min-height:36px !important;flex:0 0 auto;box-sizing:border-box;background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border:0;padding:0 16px;border-radius:8px;cursor:pointer;font:inherit;font-size:13px;font-weight:700;line-height:1;display:inline-flex;align-items:center;gap:7px;justify-content:center;vertical-align:middle;white-space:nowrap;box-shadow:0 6px 14px -8px rgba(31,96,128,.85);">💾 Save menu</button><button id="mp-fill" title="Add a photo to every dish that has none" style="height:36px !important;min-height:36px !important;flex:0 0 auto;box-sizing:border-box;background:#fff;color:#1F6080;border:1px solid #CBD5E1;padding:0 12px;border-radius:8px;cursor:pointer;font:inherit;font-size:13px;font-weight:700;line-height:1;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">📷 Fill photos</button></span>
        </div>
        <div id="mp-tools"></div>
        <div id="mp-msg" style="font-size:12.5px;color:#047857;min-height:16px;margin-top:7px;"></div>
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">${table}</div>
        <div id="mp-fg"></div>
        <div style="margin-top:16px;">
          <label for="mp-notes" style="display:block;text-align:left;margin:0 0 5px;font-size:12.5px;color:#374151;font-weight:600;">Notes (optional)</label>
          <textarea id="mp-notes" rows="3" style="display:block;width:100% !important;max-width:none !important;box-sizing:border-box;padding:9px 11px;border:1px solid #E5E7EB;border-radius:8px;margin:0;font-size:13.5px;font-family:inherit;resize:vertical;">${esc(week.notes || '')}</textarea>
        </div>
        <div id="mp-photo-opt" style="margin-top:12px;"></div>
      </div>`;

      const go = (delta) => { cur.setDate(cur.getDate() + delta); load(); };
      main.querySelector('#mp-prev').onclick = () => go(-7);
      main.querySelector('#mp-next').onclick = () => go(7);
      const todayBtn = main.querySelector('#mp-today'); if (todayBtn) todayBtn.onclick = () => { cur = _mondayOf(new Date()); load(); };

      wireCentrePicker();

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
          /* The read-only week is there to be READ (2026-11-02): the global button:disabled rule (opacity .55)
             washed out every dish name and food photo until Edit was pressed. */
          b.style.setProperty('opacity', '1', 'important');
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
        var st = main.querySelector('#mp-status');
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
        var eb = main.querySelector('#mp-edit');
        var sb = main.querySelector('#mp-save');
        if (eb) { eb.innerHTML = _menuEditing ? '✓ Done' : '✏️ Edit'; }
        if (sb) { sb.style.display = _menuEditing ? '' : 'none'; }
        var fb = main.querySelector('#mp-fill');
        if (fb) { fb.style.display = _menuEditing ? '' : 'none'; }
      }
      /* "✓ DONE" NEVER DROPS THE WEEK (2026-11-09, Anthony: "meal plan wasn't saving for educators today at all").
         Done left edit mode with the dishes still drawn in the grid, so a week that was never saved looked saved.
         Done with unsaved changes now saves them, exactly like 💾 Save menu (a failed save stays in edit mode). */
      var _menuSig = function () {
        return Array.prototype.map.call(main.querySelectorAll('input[data-d][data-m], input[data-meal-time], #mp-notes, #mp-status'), function (i) { return i.value; }).join('\u241f');
      };
      var _menuSaved = null, _saveMenu = null;
      var _eb = main.querySelector('#mp-edit');
      if (_eb) {
        _eb.onclick = function () {
          if (_menuEditing && _saveMenu && _menuSig() !== _menuSaved) { _saveMenu(); return; }
          _menuEditing = !_menuEditing; applyMenuMode();
        };
      }
      applyMenuMode();

      /* EDIT A DISH IN A DIALOG. Two stacked inputs in each of 35 cells is unusable
         on a phone, where a cell is ~132px wide. The dialog writes back into the hidden
         inputs, so Save below still collects exactly what it always did. */
      main.querySelectorAll('button.mp-cell').forEach(btn => {
        btn.onclick = () => {
          const dow = btn.getAttribute('data-cd'), meal = btn.getAttribute('data-cm');
          const nameIn = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="name"]`);
          const algIn  = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="allergens"]`);
          const noteIn = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="description"]`);
          const imgIn  = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="img"]`);
          const srcIn  = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="imgsrc"]`);
          const fidIn  = main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="foodid"]`);
          const dayLbl = (cols.find(c => String(c.dow) === String(dow)) || {}).label || '';
          const mealLbl = meal.replace('_', ' ');

          const ov = document.createElement('div');
          ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px;';
          ov.innerHTML = `<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;max-width:440px;width:100%;max-height:calc(100dvh - 32px);overflow:auto;box-shadow:0 24px 60px rgba(15,23,42,.35);">
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
              <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Notes for families</label>
              <textarea id="mp-note" rows="2" maxlength="1000" placeholder="e.g. Whole oats, diced apple, milk - or a swap for a child with allergies"
                     style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;resize:vertical;">${esc(noteIn ? noteIn.value : '')}</textarea>
              <div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">Leave the dish empty to clear this slot.</div>
              <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:14px 0 6px;">Photo</label>
              <div style="display:flex;gap:12px;align-items:center;">
                <div id="mp-ph-prev" style="flex:0 0 96px;width:96px;height:72px;border-radius:10px;background:#F1F5F9;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#94A3B8;"></div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                  <button type="button" id="mp-ph-pick" style="height:32px;padding:0 11px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;border-radius:8px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;">🖼 Choose photo</button>
                  <button type="button" id="mp-ph-up" style="height:32px;padding:0 11px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;border-radius:8px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;">⬆ Upload my own</button>
                  <button type="button" id="mp-ph-rm" style="height:32px;padding:0 11px;border:1px solid #FCA5A5;background:#fff;color:#B91C1C;border-radius:8px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;">Remove</button>
                </div>
              </div>
              <div id="mp-ph-note" style="font-size:11.5px;color:#64748B;margin-top:6px;min-height:14px;"></div>
              <div id="mp-ph-hint" style="font-size:12px;color:#92400E;margin-top:2px;"></div>
              <div id="mp-ph-sugg"></div>
              <input type="file" id="mp-ph-file" accept="image/*" style="display:none;">
            </div>
            <div style="padding:14px 20px;border-top:1px solid #EDF2F7;display:flex;gap:8px;justify-content:flex-end;">
              <button id="mp-x" style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 16px;font:inherit;font-size:14px;color:#475569;cursor:pointer;">Cancel</button>
              <button id="mp-ok" style="background:linear-gradient(135deg,#1F6080,#2c7894);border:0;border-radius:10px;padding:10px 20px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;">Done</button>
            </div>
          </div>`;
          document.body.appendChild(ov);
          const dish = ov.querySelector('#mp-dish');
          const photo = wireDishPhoto(ov, { url: imgIn ? imgIn.value : '', src: srcIn ? srcIn.value : '', fid: fidIn ? fidIn.value : '' }, dish, ov.querySelector('#mp-alg'));
          if (window.innerWidth > 768) { dish.focus(); dish.select(); }   // on a phone the keyboard waits for a tap (2026-10-23)
          const close = () => ov.remove();
          ov.querySelector('#mp-x').onclick = close;
          ov.addEventListener('click', e => { if (e.target === ov) close(); });
          ov.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
          ov.querySelector('#mp-ok').onclick = () => {
            const nm = dish.value.trim(), ag = ov.querySelector('#mp-alg').value.trim();
            nameIn.value = nm; algIn.value = ag;
            if (noteIn) noteIn.value = nm ? ov.querySelector('#mp-note').value.trim() : '';
            if (imgIn) {
              imgIn.value = nm ? (photo.url || '') : ''; srcIn.value = nm ? (photo.src || '') : ''; fidIn.value = nm ? (photo.fid || '') : '';
              const old = btn.querySelector('.mp-cell-img');
              if (old) { old.outerHTML = cellThumb(imgIn.value, +(old.getAttribute('data-w') || 34)); }
            }
            const nEl = btn.querySelector('.mp-cell-name'), aEl = btn.querySelector('.mp-cell-alg');
            nEl.textContent = nm || '+ Add';
            nEl.style.color = nm ? '#0D1B2A' : '#94A3B8';
            nEl.style.fontWeight = nm ? '600' : '400';
            aEl.textContent = ag ? '\u26A0 ' + ag : '';
            close();
            if (_tools) _tools.refresh();
          };
        };
      });

      const fillBtn = main.querySelector('#mp-fill');
      if (fillBtn) {
        fillBtn.onclick = async () => {
          const msg = main.querySelector('#mp-msg');
          const val = (d, m, f) => main.querySelector(`input[data-d="${d}"][data-m="${m}"][data-f="${f}"]`);
          const slots = [];
          main.querySelectorAll('input[data-d][data-m][data-f="name"]').forEach((inp) => {
            const nm = inp.value.trim(); if (!nm) return;
            const img = val(inp.dataset.d, inp.dataset.m, 'img'), src = val(inp.dataset.d, inp.dataset.m, 'imgsrc');
            if (!img || img.value || (src && src.value === 'none')) return;
            slots.push({ d: inp.dataset.d, m: inp.dataset.m, name: nm });
          });
          if (!slots.length) { msg.textContent = 'Every dish already has a photo.'; msg.style.color = '#047857'; return; }
          fillBtn.disabled = true; msg.textContent = 'Finding photos…'; msg.style.color = '#6B7280';
          const names = [...new Set(slots.map((x) => x.name))], found = {};
          try {
            const r = await Api.post('/operations/food-images/match', { names });
            const unsure = [];
            names.forEach((n) => { const x = r && r.matches && r.matches[n]; if (x && x.best) found[n] = x.best; if (!x || !x.confident) unsure.push(n); });
            const lib = await foodLib(), missing = unsure;
            if (missing.length && lib.ai) {
              try { const a = await Api.post('/ai/assist/food-photo', { names: missing.slice(0, 40) }); Object.keys((a && a.matches) || {}).forEach((n) => { if (a.matches[n]) found[n] = a.matches[n]; }); } catch (e) {}
            }
          } catch (e) { msg.textContent = (e && e.message) || 'Could not find photos just now.'; msg.style.color = '#B91C1C'; fillBtn.disabled = false; return; }
          let added = 0;
          slots.forEach((x) => {
            const f = found[x.name]; if (!f) return;
            val(x.d, x.m, 'img').value = f.thumb_url; val(x.d, x.m, 'imgsrc').value = 'auto'; val(x.d, x.m, 'foodid').value = f.id;
            const cell = main.querySelector(`button.mp-cell[data-cd="${x.d}"][data-cm="${x.m}"] .mp-cell-img`);
            if (cell) { cell.outerHTML = cellThumb(f.thumb_url, +(cell.getAttribute('data-w') || 34)); }
            added++;
          });
          const left = slots.length - added;
          msg.textContent = (added ? `Added ${added} photo${added === 1 ? '' : 's'}` : 'No photos found') + (left ? ` · ${left} dish${left === 1 ? '' : 'es'} without a match (choose or upload one)` : '') + (added ? '. Press Save menu to keep them.' : '.');
          msg.style.color = added ? '#047857' : '#92400E';
          fillBtn.disabled = false;
        };
      }
      foodLib().then((lib) => {
        const box = main.querySelector('#mp-photo-opt'); if (!box || !lib || !lib.can_manage) return;
        box.innerHTML = `<label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px;color:#374151;font-weight:600;cursor:pointer;">
          <input type="checkbox" id="mp-auto" ${lib.auto ? 'checked' : ''} style="width:16px;height:16px;"> Add food photos automatically to new dishes</label>
          <div style="font-size:11.5px;color:#94A3B8;margin-top:3px;">A photo is added only when the dish clearly matches one in the food library. Staff can always change or remove it.</div>`;
        box.querySelector('#mp-auto').onchange = async (e) => {
          const on = e.target.checked;
          try { await Api.put('/operations/menu/photo-settings', { auto: on }); foodLib(true); if (KT.toast) KT.toast('📷', 'Food photos', on ? 'Photos are added automatically.' : 'Photos are no longer added automatically.'); }
          catch (err) { e.target.checked = !on; if (KT.toast) KT.toast('⚠️', 'Could not change it', (err && err.message) || '', '#B91C1C'); }
        };
      });

      /* Menu tools (2026-11-03, kt-menu-tools.js): Copy last week, Save as template, Apply a template,
         and the Canada's Food Guide check. They read and fill the same hidden inputs Save collects. */
      const fillCell = (dow, meal, it) => {
        const q = (f) => main.querySelector(`input[data-d="${dow}"][data-m="${meal}"][data-f="${f}"]`);
        const nameIn = q('name'); if (!nameIn) return;
        const nm = it ? String(it.name || '').trim() : '', ag = it ? String(it.allergens || '').trim() : '';
        nameIn.value = nm; const algIn = q('allergens'); if (algIn) algIn.value = ag;
        const noteIn = q('description'); if (noteIn) noteIn.value = nm ? String((it && it.description) || '').trim() : '';
        const imgIn = q('img'), srcIn = q('imgsrc'), fidIn = q('foodid');
        if (imgIn) { imgIn.value = (nm && it.image_url) || ''; srcIn.value = (nm && it.image_source) || ''; fidIn.value = (nm && it.food_image_id) || ''; }
        const btn = main.querySelector(`button.mp-cell[data-cd="${dow}"][data-cm="${meal}"]`); if (!btn) return;
        const old = btn.querySelector('.mp-cell-img');
        if (old && imgIn) { old.outerHTML = cellThumb(imgIn.value, +(old.getAttribute('data-w') || 34)); }
        const nEl = btn.querySelector('.mp-cell-name'), aEl = btn.querySelector('.mp-cell-alg');
        nEl.textContent = nm || (_menuEditing ? '+ Add' : '—'); nEl.style.color = nm ? '#0D1B2A' : '#94A3B8'; nEl.style.fontWeight = nm ? '600' : '400';
        aEl.textContent = ag ? '\u26A0 ' + ag : '';
      };
      const cellsNow = () => {
        const o = {};
        main.querySelectorAll('input[data-d][data-m][data-f="name"]').forEach(i => { o[i.dataset.d + '-' + i.dataset.m] = i.value.trim(); });
        return o;
      };
      _tools = (window.KT && KT.MenuTools) ? KT.MenuTools.mount(main, {
        cid, weekStart: weekStartStr, centres: centreList, openDays,
        isEditing: () => _menuEditing, setEditing: (on) => { _menuEditing = !!on; applyMenuMode(); },
        cells: cellsNow, fillCell, reload: load,
      }) : null;

      _menuSaved = _menuSig();
      _saveMenu = async () => {
        const items = [];
        main.querySelectorAll('input[data-d][data-m][data-f="name"]').forEach(inp => {
          const name = inp.value.trim(); if (!name) return;
          const allergens = main.querySelector(`input[data-d="${inp.dataset.d}"][data-m="${inp.dataset.m}"][data-f="allergens"]`).value.trim();
          const ph = (f) => { const x = main.querySelector(`input[data-d="${inp.dataset.d}"][data-m="${inp.dataset.m}"][data-f="${f}"]`); return x ? x.value : ''; };
          items.push({ day_of_week: +inp.dataset.d, meal_type: inp.dataset.m, name, allergens: allergens || null, description: ph('description').trim() || null,
            image_url: ph('img') || null, image_source: ph('imgsrc') || null, food_image_id: ph('foodid') ? +ph('foodid') : null });
        });
        const status = main.querySelector('#mp-status').value;
        const msg = main.querySelector('#mp-msg');
        msg.textContent = 'Saving…'; msg.style.color = '#6B7280';
        try {
          const meal_times = {};
          main.querySelectorAll('input[data-meal-time]').forEach(t => {
            if (t.value) { meal_times[t.getAttribute('data-meal-time')] = t.value; }
          });
          await Api.post('/operations/menu', { centre_id: cid, week_start: weekStartStr, status, notes: main.querySelector('#mp-notes').value, items, meal_times });
          _menuSaved = _menuSig();
          if (window.KT && KT.dirtySave) KT.dirtySave.saved(main.querySelector('#mp-save'));
          msg.textContent = status === 'published' ? 'Published — families notified.' : 'Saved.'; msg.style.color = '#047857';
          /* Save IS the completion — no second confirmation. Only on success: a failed
             save keeps the grid editable, because the work is still in the boxes and
             dropping to read-only would look like it saved. (Anthony, 2026-09-08) */
          _menuEditing = false; applyMenuMode();
        } catch (e) { msg.textContent = 'Save failed.'; msg.style.color = '#B91C1C'; }
      };
      /* data-kt-always-save: Save menu is on whenever the grid is open for editing. The "no changes" tracker could
         not see the dish grid (see kt-dirty-save.js, 2026-11-09) and kept this greyed all day. */
      main.querySelector('#mp-save').onclick = _saveMenu;
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
      if (!main.isConnected) { return; }   // left the screen while it loaded (ticket #105)
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
                ${it.description ? `<div style="font-size:12.5px;color:#475569;margin-top:2px;line-height:1.4;white-space:pre-wrap;">${esc(it.description)}</div>` : ''}
                ${it.allergens ? `<div style="margin-top:3px;"><span style="display:inline-block;background:#FEF3C7;color:#92400E;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:8px;">⚠ ${esc(it.allergens)}</span></div>` : ''}
              </div>
              ${it.image_url ? `<img src="${esc(it.image_url)}" alt="${esc(it.name)}" loading="lazy" style="width:64px;height:48px;object-fit:cover;border-radius:9px;flex-shrink:0;align-self:center;background:#F1F5F9;">` : ''}
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
        ${(r.data || []).map(c => `<div style="--kt-edge:#B91C1C;background:#FEF2F2;padding:14px 18px;margin-bottom:10px;border-radius:0 8px 8px 0;">
          <strong style="color:#991B1B;font-size:16px;">${esc(c.first_name)} ${esc(c.last_name)}</strong>
          <div style="margin-top:6px;font-size:13px;color:#374151;">
            ${(c.tags || []).map(t => {
              const colors = { allergy: { bg: '#FEE2E2', fg: '#991B1B' }, health: { bg: '#FEF3C7', fg: '#92400E' }, dietary: { bg: '#DBEAFE', fg: '#1E40AF' } };
              const sev = t.severity || 'note';
              const sevDot = (sev === 'anaphylactic' || sev === 'severe') ? '🚨' : '';   // matches the editor's top level (2026-11-27)
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
      <div id="ft-stats" style="margin-top:16px;"></div>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Trip</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Date</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Status</th>
          <th></th></tr></thead>
        <tbody>${(r.data || []).map(t => `<tr data-ft-row="${t.id}" style="cursor:pointer;">
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><strong>${esc(t.title)}</strong><div style="color:#6B7280;font-size:12px;">${esc(t.destination)}</div></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${fmtDate(t.trip_date)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;">${esc(t.centre_name)}</td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;"><span style="background:#EFF6FF;color:#1E40AF;padding:2px 8px;border-radius:4px;font-size:12px;">${esc(t.status)}</span></td>
          <td style="padding:10px 8px;border-bottom:1px solid #F3F4F6;text-align:right;white-space:nowrap;"><button data-ft-view="${t.id}" class="kt-act-icon kt-act-info kt-icon-tip" title="View details" data-kttip="View details" aria-label="View details">👁</button><button data-ft-id="${t.id}" class="kt-act-icon kt-act-info kt-icon-tip" title="Permission slips" data-kttip="Permission slips" aria-label="Permission slips">🔑</button><button data-ft-del="${t.id}" class="kt-act-icon kt-act-danger kt-icon-tip" title="Delete trip" data-kttip="Delete" aria-label="Delete">🗑️</button></td>
        </tr>`).join('') || '<tr><td colspan="5" style="padding:24px;color:#64748B;text-align:center;">No field trips planned.</td></tr>'}
        </tbody></table>
    </div>`;
    if (r.stats) ftStats(main.querySelector('#ft-stats'), r.stats);
    document.getElementById('ft-new').onclick = () => openFieldTripModal();
    main.querySelectorAll('button[data-ft-id]').forEach(b => b.onclick = () => openPermissionsModal(+b.getAttribute('data-ft-id'), b));
    main.querySelectorAll('button[data-ft-view]').forEach(b => b.onclick = () => openTripDetails(+b.getAttribute('data-ft-view'), b));
    // The trip name opens the details too.
    main.querySelectorAll('tr[data-ft-row]').forEach(tr => tr.addEventListener('click', (ev) => { if (ev.target.closest('button')) return; openTripDetails(+tr.getAttribute('data-ft-row')); }));
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
  /* FIELD TRIP STATS (2026-11-09, Anthony: "field trips section add stats, circle graphs etc"). The circle stats
     (KT.statRings, kt-stat-rings.js) for what is coming up and the last 12 months, then trips + walks by month and how
     the children travel. Walk Watch walks are counted apart from trips (the server tells them apart: stats in
     GET /operations/field-trips). Series colours passed the dataviz palette check in light and dark. */
  function ftStats(el, st) {
    if (!el) return;
    if (!document.getElementById('kt-ftv-css')) {
      const css = document.createElement('style'); css.id = 'kt-ftv-css';
      css.textContent = '.kt-ftv{--ftv-trip:#2a78d6;--ftv-walk:#D97706;--ftv-c1:#2a78d6;--ftv-c2:#eb6834;--ftv-c3:#1baf7a;--ftv-c4:#eda100;--ftv-c5:#94A3B8}'
        + 'html.kt-dark .kt-ftv,[data-theme="dark"] .kt-ftv{--ftv-trip:#3987e5;--ftv-walk:#c98500;--ftv-c1:#3987e5;--ftv-c2:#d95926;--ftv-c3:#199e70;--ftv-c4:#c98500;--ftv-c5:#64748B}'
        + '.kt-ftv-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:10px}'
        + '.kt-ftv-card{background:var(--surface,#fff);border:1px solid var(--ink-100,#E5E7EB);border-radius:12px;padding:12px 14px;min-width:0}'
        + '.kt-ftv-h{display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:10px}'
        + '.kt-ftv-h b{font-size:13px;color:var(--ink-700,#334155)}.kt-ftv-h span{font-size:11.5px;color:var(--ink-500,#64748B)}'
        + '.kt-ftv-key{display:inline-flex;align-items:center;gap:5px;margin-left:10px}.kt-ftv-key i{width:10px;height:10px;border-radius:3px;display:inline-block}'
        + '.kt-ftv-bars{display:flex;align-items:flex-end;gap:6px;height:130px;border-bottom:1px solid var(--ink-100,#E5E7EB)}'
        + '.kt-ftv-col{flex:1 1 0;min-width:0;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px;cursor:default}'
        + '.kt-ftv-col .n{font-size:11px;font-weight:700;color:var(--ink-700,#334155);text-align:center;line-height:1;margin-bottom:2px}'
        + '.kt-ftv-col i{display:block;min-height:3px;width:min(100%,28px)}.kt-ftv-col i:first-of-type{border-radius:4px 4px 0 0}'
        + '.kt-ftv-col:hover i{filter:brightness(1.12)}'
        + '.kt-ftv-mon{display:flex;gap:6px;margin-top:5px}.kt-ftv-mon span{flex:1 1 0;min-width:0;text-align:center;font-size:10.5px;color:var(--ink-500,#64748B)}'
        + '.kt-ftv-seg{display:flex;gap:2px;height:14px;border-radius:4px;overflow:hidden}.kt-ftv-seg i{display:block;min-width:4px}'
        + '.kt-ftv-leg{margin-top:9px;display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--ink-700,#334155)}'
        + '.kt-ftv-leg div{display:flex;align-items:center;gap:7px}.kt-ftv-leg i{width:10px;height:10px;border-radius:3px;flex:none}.kt-ftv-leg em{margin-left:auto;font-style:normal;color:var(--ink-500,#64748B)}'
        + '.kt-ftv-fee{margin-top:12px;padding-top:10px;border-top:1px solid var(--ink-100,#E5E7EB);font-size:12.5px;color:var(--ink-500,#64748B)}.kt-ftv-fee b{display:block;font-size:18px;color:var(--ink-900,#0F172A)}'
        + '@media (max-width:900px){.kt-ftv-grid{grid-template-columns:minmax(0,1fr)}.kt-ftv-bars{gap:3px}.kt-ftv-mon{gap:3px}}';
      document.head.appendChild(css);
    }
    const sl = st.slips || {};
    const rings = [
      { label: 'Upcoming trips', value: st.upcoming, color: '#1F6080', sub: st.next ? 'Next: ' + st.next.title + ' · ' + fmtDate(st.next.date) : 'None planned' },
    ];
    if (sl.total) {
      rings.push({ label: 'Slips signed', value: sl.approved, of: sl.total, color: '#16A34A' });
      rings.push({ label: 'Awaiting parents', value: sl.pending, of: sl.total, color: '#D97706' });
    }
    rings.push({ label: 'Past trips', value: st.held_12m, color: '#2a78d6', sub: 'last 12 months' });
    rings.push({ label: 'Walks', value: st.walks_12m, color: '#0F766E', sub: 'Walk Watch · last 12 months' });
    rings.push({ label: 'Children who went', value: st.children_went_12m, of: st.children_total, color: '#7C3AED' });
    el.innerHTML = '';
    el.className = 'kt-ftv';
    if (KT.statRings) el.appendChild(KT.statRings(rings));

    const months = st.months || [];
    const max = Math.max(1, ...months.map(m => m.trips + m.walks));
    const tot = months.reduce((a, m) => ({ t: a.t + m.trips, w: a.w + m.walks }), { t: 0, w: 0 });
    const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');
    const cols = months.map((m) => {
      const tip = m.label + ': ' + plural(m.trips, 'trip') + ', ' + plural(m.walks, 'walk');
      const h = (n) => (n / max * 108).toFixed(1) + 'px';
      return '<div class="kt-ftv-col" data-kttip="' + esc(tip) + '" aria-label="' + esc(tip) + '" role="img">'
        + (m.trips + m.walks ? '<span class="n">' + (m.trips + m.walks) + '</span>' : '')
        + (m.walks ? '<i style="height:' + h(m.walks) + ';background:var(--ftv-walk)"></i>' : '')
        + (m.trips ? '<i style="height:' + h(m.trips) + ';background:var(--ftv-trip)"></i>' : '')
        + '</div>';
    }).join('');
    const tr = (st.transport || []).slice();
    if (tr.length > 4) { const rest = tr.splice(3); tr.push({ label: 'Other', n: rest.reduce((a, x) => a + x.n, 0) }); }
    const trTot = tr.reduce((a, x) => a + x.n, 0);
    const cap = (s) => String(s).charAt(0).toUpperCase() + String(s).slice(1);
    const trCol = (i, x) => x.label === 'Other' || x.label === 'Not set' ? 'var(--ftv-c5)' : 'var(--ftv-c' + (i + 1) + ')';
    const travel = trTot
      ? '<div class="kt-ftv-seg" role="img" aria-label="How children travel">' + tr.map((x, i) => '<i data-kttip="' + esc(cap(x.label) + ': ' + plural(x.n, 'trip')) + '" style="flex:' + x.n + ' 1 0;background:' + trCol(i, x) + '"></i>').join('') + '</div>'
        + '<div class="kt-ftv-leg">' + tr.map((x, i) => '<div><i style="background:' + trCol(i, x) + '"></i>' + esc(cap(x.label)) + '<em>' + x.n + ' · ' + Math.round(x.n * 100 / trTot) + '%</em></div>').join('') + '</div>'
      : '<div style="font-size:12.5px;color:var(--ink-500,#64748B);">No trips in the last 12 months.</div>';
    const money = (n) => '$' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const grid = document.createElement('div');
    grid.className = 'kt-ftv-grid';
    grid.style.marginBottom = '6px';
    grid.innerHTML = '<div class="kt-ftv-card"><div class="kt-ftv-h"><b>Trips and walks by month</b><span>'
        + '<span class="kt-ftv-key"><i style="background:var(--ftv-trip)"></i>Trips ' + tot.t + '</span>'
        + '<span class="kt-ftv-key"><i style="background:var(--ftv-walk)"></i>Walks ' + tot.w + '</span></span></div>'
        + '<div class="kt-ftv-bars">' + cols + '</div>'
        + '<div class="kt-ftv-mon">' + months.map(m => '<span>' + esc(m.label) + '</span>').join('') + '</div></div>'
      + '<div class="kt-ftv-card"><div class="kt-ftv-h"><b>How children travel</b><span>trips, last 12 months</span></div>' + travel
        + '<div class="kt-ftv-fee">Trip fees from signed slips<b>' + money(st.fees_12m) + '</b>last 12 months and upcoming</div></div>';
    el.appendChild(grid);
  }
  /* NEW TRIP (2026-11-28, help-writer bug batch). The form posted whatever was typed: no title,
     destination or date check, and no catch, so a refused save (the server requires all three, and
     every child must be at the trip's centre) left the popup sitting there with nothing said. The
     children list was the whole agency whatever centre was picked, which is how "Some of the
     children picked are not at this centre." happened. Now: the house popup, a centre chosen first
     (never the first in the list), only that centre's children, every field checked before sending,
     and the server's own sentence shown inside the popup when it refuses. */
  async function openFieldTripModal() {
    const childrenRes = await Api.get('/admin/children').catch(() => ({ children: [] }));
    const centresRes = await Api.get('/director/centres').catch(() => ({ centres: [] }));
    const children = childrenRes.children || childrenRes.data || [];
    const centres = centresRes.centres || centresRes.data || (Array.isArray(centresRes) ? centresRes : []);
    const inp = 'width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;box-sizing:border-box;';
    const lab = 'display:block;margin-top:10px;font-size:13px;font-weight:700;color:#334155;';
    const today = (window.KT && KT.agencyToday) ? KT.agencyToday() : '';
    const body = document.createElement('div');
    body.innerHTML = `
      <label style="${lab}">Centre *<select id="ft-centre" style="${inp}">${centres.length === 1 ? '' : '<option value="">— Pick a centre —</option>'}${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></label>
      <label style="${lab}">Title *<input id="ft-title" maxlength="200" placeholder="e.g. Pumpkin patch visit" style="${inp}"></label>
      <label style="${lab}">Destination *<input id="ft-dest" maxlength="255" placeholder="Where you are going" style="${inp}"></label>
      <label style="${lab}">Trip date *<input id="ft-date" type="date"${today ? ` min="${today}"` : ''} style="${inp}"></label>
      <label style="${lab}">Cost per child ($)<input id="ft-cost" type="number" min="0" step="0.01" value="0" style="${inp}"></label>
      <div style="${lab}display:flex;align-items:center;justify-content:space-between;gap:8px;"><span>Children invited *</span><button type="button" id="ft-all" style="background:none;border:0;color:#1F6080;font-weight:700;font-size:12.5px;cursor:pointer;padding:0;">Select all</button></div>
      <div id="ft-children" style="max-height:220px;overflow:auto;border:1px solid #E2E8F0;border-radius:8px;padding:8px;margin-top:4px;"></div>
      <div id="ft-status" role="alert" style="min-height:18px;font-size:13px;color:#B91C1C;margin-top:10px;"></div>`;
    const $ = (s) => body.querySelector(s);
    const status = (t) => { $('#ft-status').textContent = t || ''; };
    const paintChildren = () => {
      const cid = +$('#ft-centre').value;
      const box = $('#ft-children');
      if (!cid) { box.innerHTML = '<em style="color:#64748B;font-size:13px;">Pick a centre to see its children.</em>'; return; }
      const here = children.filter(c => +c.centre_id === cid);
      box.innerHTML = here.map(c => `<label style="display:block;padding:4px 0;font-size:13px;"><input type="checkbox" data-cid="${c.id}"> ${esc(c.first_name)} ${esc(c.last_name)}</label>`).join('')
        || '<em style="color:#64748B;font-size:13px;">No children at this centre.</em>';
    };
    $('#ft-centre').onchange = () => { status(''); paintChildren(); };
    $('#ft-all').onclick = () => {
      const boxes = Array.from(body.querySelectorAll('input[data-cid]'));
      const on = boxes.some(b => !b.checked);
      boxes.forEach(b => { b.checked = on; });
    };
    paintChildren();
    KT.Shell.Modal.open({
      title: '🚐 New field trip',
      body,
      actions: [
        { label: 'Cancel' },
        { label: 'Create + send permission slips', primary: true, busyLabel: 'Sending…', handler: async () => {
          status('');
          const centreId = +$('#ft-centre').value;
          const title = ($('#ft-title').value || '').trim();
          const destination = ($('#ft-dest').value || '').trim();
          const tripDate = $('#ft-date').value;
          const childIds = Array.from(body.querySelectorAll('input[data-cid]:checked')).map(c => +c.dataset.cid);
          if (!centreId) { status('Pick a centre.'); return false; }
          if (!title) { status('Give the trip a title.'); $('#ft-title').focus(); return false; }
          if (!destination) { status('Say where the trip is going.'); $('#ft-dest').focus(); return false; }
          if (!tripDate) { status('Pick the trip date.'); $('#ft-date').focus(); return false; }
          if (!childIds.length) { status('Pick at least one child.'); return false; }
          try {
            await Api.post('/operations/field-trips', {
              centre_id: centreId,
              title,
              destination,
              trip_date: tripDate,
              cost_per_child: parseFloat($('#ft-cost').value) || 0,
              child_ids: childIds,
            });
          } catch (e) {
            status((e && e.message) || 'The trip could not be saved. Try again.');
            return false;
          }
          if (window.KT && KT.toast) KT.toast('🚐', 'Trip created', 'Permission slips are on their way to the families.', '#16A34A');
          renderFieldTrips(document.getElementById('appMain') || document.querySelector('main'));
          return true;
        } },
      ],
    });
  }
  /* TRIP DETAILS (2026-10-24, Anthony: "add to the kebab view which pops up all of the information
     about the trip including map, children, steps, how long, add more detail"). One call
     (GET /operations/field-trips/{id}) carries the trip, every child and their permission slip,
     the GPS route and the distance / estimated steps / duration worked out from it. Times are
     shown in the agency's time zone (KT.fmtTime / KT.parseTs). */
  function ftLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (window._ktFtLeaflet) return window._ktFtLeaflet;
    window._ktFtLeaflet = new Promise((resolve, reject) => {
      if (!document.querySelector('link[data-kt-leaflet]')) {
        const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = '/js/vendor/leaflet-1.9.4/leaflet.css'; css.setAttribute('data-kt-leaflet', '1'); document.head.appendChild(css);
      }
      // Self-hosted, and a failed load is not cached (ticket #246, see kt-walk-tracker.js).
      const sc = document.createElement('script'); sc.src = '/js/vendor/leaflet-1.9.4/leaflet.js';
      sc.onload = () => resolve(window.L); sc.onerror = () => { window._ktFtLeaflet = null; sc.remove(); reject(new Error('map library')); }; document.head.appendChild(sc);
    });
    return window._ktFtLeaflet;
  }
  async function openTripDetails(tripId, btn) {
    const done = (btn && window.KT && KT.busy) ? KT.busy(btn) : function () {};
    let d;
    try { d = await Api.get('/operations/field-trips/' + tripId); }
    catch (e) { done(); if (window.KT && KT.toast) KT.toast('⚠️', 'Could not load the trip', (e && e.message) || 'Please try again.', '#DC2626'); return; }
    done();
    const t = d.trip || {}, kids = d.children || [], c = d.counts || {};
    const tm = (ts) => ts ? ((window.KT && KT.fmtTime) ? KT.fmtTime(ts) : String(ts).slice(11, 16)) : '';
    const clock = (hms) => { if (!hms) return ''; const [h, m] = String(hms).split(':').map(Number); if (isNaN(h)) return hms; return ((h % 12) || 12) + ':' + String(m || 0).padStart(2, '0') + (h < 12 ? ' AM' : ' PM'); };
    const day = (ymd) => ymd ? ((window.KT && KT.dayLabel) ? KT.dayLabel(String(ymd).slice(0, 10)) : String(ymd).slice(0, 10)) : '';
    let mins = d.duration_min;
    if (!mins && t.depart_time && t.return_time) { const a = t.depart_time.split(':'), b = t.return_time.split(':'); mins = (+b[0] * 60 + +b[1]) - (+a[0] * 60 + +a[1]); if (mins < 0) mins = null; }
    const dur = mins ? (mins >= 60 ? Math.floor(mins / 60) + ' h ' + (mins % 60 ? (mins % 60) + ' min' : '') : mins + ' min') : '—';
    const km = d.distance_m != null ? ((d.distance_m) / 1000).toFixed(2) + ' km (' + Math.round(d.distance_m).toLocaleString() + ' m)' : (t.distance_km ? ((Number(t.distance_km) * 1000) / 1000).toFixed(2) + ' km (' + Math.round(Number(t.distance_km) * 1000).toLocaleString() + ' m)' : '—');
    const steps = d.steps_est ? Number(d.steps_est).toLocaleString() : '—';
    const cost = +t.cost_per_child || 0;
    const STATUS = { planned: ['#1E40AF', '#EFF6FF'], active: ['#047857', '#ECFDF5'], in_progress: ['#047857', '#ECFDF5'], completed: ['#334155', '#F1F5F9'], ended: ['#334155', '#F1F5F9'], cancelled: ['#B91C1C', '#FEF2F2'] };
    const st = STATUS[String(t.status || '').toLowerCase()] || ['#1E40AF', '#EFF6FF'];
    const PERM = { approved: ['Approved', '#047857', '#ECFDF5'], denied: ['Declined', '#B91C1C', '#FEF2F2'], pending: ['Waiting', '#B45309', '#FFFBEB'] };
    const tile = (icon, label, value, sub) => `<div style="background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:11px 13px;min-width:0;">
        <div style="font-size:11.5px;font-weight:700;color:#64748B;">${icon} ${label}</div>
        <div style="font-size:20px;font-weight:800;color:#0F172A;margin-top:3px;line-height:1.1;">${value}</div>${sub ? `<div style="font-size:11px;color:#94A3B8;margin-top:2px;">${sub}</div>` : ''}</div>`;
    const departed = d.first_ping ? tm(d.first_ping) : '', returned = d.last_ping && d.ping_count > 1 ? tm(d.last_ping) : '';
    const stepsList = [
      ['📝', 'Planned', t.created_at ? day(t.created_at) + (t.created_by_name ? ' · by ' + esc(t.created_by_name) : '') : '', true],
      ['✉️', 'Permission slips', c.total ? `${c.approved || 0} of ${c.total} approved${t.permission_deadline ? ' · due ' + day(t.permission_deadline) : ''}` : 'None sent', (c.total || 0) > 0],
      ['🚶', 'Departed', departed ? departed + (t.depart_time ? ' (planned ' + clock(t.depart_time) + ')' : '') : (t.depart_time ? 'Planned ' + clock(t.depart_time) : 'Not yet'), !!departed],
      ['🏁', 'Returned', returned ? returned + (t.return_time ? ' (planned ' + clock(t.return_time) + ')' : '') : (t.return_time ? 'Planned ' + clock(t.return_time) : 'Not yet'), !!returned],
    ];
    const m = document.createElement('div');
    m.className = 'kt-ft-details';
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;';
    m.innerHTML = `<div role="dialog" aria-modal="true" style="background:#F8FAFC;border-radius:18px;width:min(1080px,100%);max-height:92vh;overflow:auto;box-shadow:0 24px 60px -20px rgba(15,23,42,.5);">
      <div style="background:linear-gradient(120deg,#0A1E55,#1F6080);color:#fff;padding:18px 22px;border-radius:18px 18px 0 0;display:flex;gap:14px;align-items:flex-start;justify-content:space-between;">
        <div style="min-width:0;">
          <div style="font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:#7DD3FC;">${esc(/walk/i.test(String(t.transport_method || '')) ? 'Walk' : 'Field trip')} · ${esc(t.centre_name || '')}</div>
          <div style="font-size:21px;font-weight:800;margin-top:3px;">${esc(t.title || 'Trip')}</div>
          <div style="font-size:13px;color:#C9D6F2;margin-top:3px;">📍 ${esc(t.destination || 'No destination set')} · 📅 ${day(t.trip_date)}${t.depart_time ? ' · ' + clock(t.depart_time) + (t.return_time ? '–' + clock(t.return_time) : '') : ''}</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex:0 0 auto;">
          <span style="background:${st[1]};color:${st[0]};padding:3px 10px;border-radius:999px;font-size:12px;font-weight:800;text-transform:capitalize;">${esc(String(t.status || 'planned').replace('_', ' '))}</span>
          <button type="button" data-ft-close aria-label="Close" style="background:rgba(255,255,255,.12);color:#fff;border:0;width:32px;height:32px;border-radius:9px;cursor:pointer;font-size:16px;">✕</button>
        </div>
      </div>
      <div style="padding:16px 20px 20px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px;">
          ${tile('🧒', 'Children', (c.approved || 0) + ' / ' + (c.total || 0), (c.pending ? c.pending + ' waiting' : 'all answered') + (c.denied ? ' · ' + c.denied + ' declined' : ''))}
          ${tile('⏱️', 'How long', dur, departed ? 'from GPS' : (t.depart_time ? 'planned' : ''))}
          ${tile('📏', 'Distance', km, d.ping_count ? d.ping_count + ' GPS points' : 'no route recorded')}
          ${tile('👣', 'Steps (est.)', steps, d.steps_est ? 'from distance walked' : '')}
          ${tile('🚌', 'Transport', esc(t.transport_method ? String(t.transport_method).replace('_', ' ') : '—'), t.lead_name ? 'lead: ' + esc(t.lead_name) : '')}
          ${tile('💵', 'Cost', cost ? '$' + cost.toFixed(2) : 'Free', cost ? 'per child · $' + (cost * (c.approved || 0)).toFixed(2) + ' approved' : '')}
        </div>
        <div style="display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:14px;" class="kt-ft-grid">
          <div style="background:#fff;border:1px solid #E6EBF1;border-radius:14px;overflow:hidden;min-height:300px;position:relative;">
            <div id="kt-ft-map" style="height:340px;"></div>
            ${(d.trail || []).length ? '' : '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;color:#64748B;font-size:13.5px;background:#F8FAFC;"><div style="font-size:30px;">🗺️</div>No GPS route recorded for this trip yet.<div style="font-size:12px;color:#94A3B8;">The route appears once staff start the trip on their phone.</div></div>'}
          </div>
          <div style="background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:14px 16px;">
            <div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:10px;">🪜 Trip steps</div>
            ${stepsList.map((x, i) => `<div style="display:flex;gap:10px;position:relative;padding-bottom:${i < stepsList.length - 1 ? 14 : 0}px;">
              ${i < stepsList.length - 1 ? '<span style="position:absolute;left:14px;top:30px;bottom:0;width:2px;background:#E2E8F0;"></span>' : ''}
              <span style="flex:0 0 30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;background:${x[3] ? '#ECFDF5' : '#F1F5F9'};border:2px solid ${x[3] ? '#10B981' : '#CBD5E1'};">${x[0]}</span>
              <div style="min-width:0;"><div style="font-weight:700;font-size:13px;color:#0F172A;">${x[1]}</div><div style="font-size:12px;color:#64748B;">${x[2] || '—'}</div></div></div>`).join('')}
            ${(d.tracked_by || []).length ? `<div style="margin-top:12px;font-size:12px;color:#475569;"><strong>Tracked by:</strong> ${d.tracked_by.map(esc).join(', ')}</div>` : ''}
            ${t.description ? `<div style="margin-top:12px;padding-top:10px;border-top:1px solid #EEF2F6;font-size:12.5px;color:#334155;white-space:pre-wrap;"><strong>Notes</strong><br>${esc(t.description)}</div>` : ''}
          </div>
        </div>
        <div style="background:#fff;border:1px solid #E6EBF1;border-radius:14px;margin-top:14px;overflow:hidden;">
          <div style="padding:12px 16px;font-weight:800;font-size:14px;color:#0F172A;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">🧒 Children on this trip <span style="font-weight:600;font-size:12px;color:#64748B;">${c.approved || 0} approved · ${c.pending || 0} waiting · ${c.denied || 0} declined</span></div>
          ${kids.length ? `<div style="overflow-x:auto;"><table data-kt-filtered="1" data-kt-noexport="1" style="width:100%;border-collapse:collapse;">
            <thead><tr>${['Child', 'Permission', 'Answered by', 'When', 'Signed'].map(h => `<th style="text-align:left;padding:8px 14px;font-size:11px;letter-spacing:.4px;text-transform:uppercase;color:#64748B;background:#F8FAFC;">${h}</th>`).join('')}</tr></thead>
            <tbody>${kids.map(k => { const pm = PERM[k.status] || [k.status || '—', '#475569', '#F1F5F9']; return `<tr>
              <td style="padding:9px 14px;border-top:1px solid #EEF2F6;font-weight:700;font-size:13px;">${esc((k.first_name || '') + ' ' + (k.last_name || ''))}</td>
              <td style="padding:9px 14px;border-top:1px solid #EEF2F6;"><span style="background:${pm[2]};color:${pm[1]};padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;">${pm[0]}</span></td>
              <td style="padding:9px 14px;border-top:1px solid #EEF2F6;font-size:13px;color:#334155;">${esc(k.guardian_name || '—')}</td>
              <td style="padding:9px 14px;border-top:1px solid #EEF2F6;font-size:12.5px;color:#64748B;white-space:nowrap;">${k.responded_at ? day(k.responded_at) + ' ' + tm(k.responded_at) : '—'}</td>
              <td style="padding:9px 14px;border-top:1px solid #EEF2F6;font-size:13px;">${+k.signed ? '✍️ Yes' : '—'}</td></tr>`; }).join('')}</tbody></table></div>`
          : '<div style="padding:4px 16px 16px;color:#64748B;font-size:13px;">No children were invited.</div>'}
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
          <button type="button" data-ft-perm style="background:#fff;color:#1F6080;border:1px solid #CBD5E1;padding:0 14px;height:34px;border-radius:9px;font-weight:700;cursor:pointer;">🔑 Permission slips</button>
          <button type="button" data-ft-close style="background:#1F6080;color:#fff;border:0;padding:0 16px;height:34px;border-radius:9px;font-weight:700;cursor:pointer;">Close</button>
        </div>
      </div></div>
      <style>@media (max-width:760px){.kt-ft-details .kt-ft-grid{grid-template-columns:1fr !important}}</style>`;
    document.body.appendChild(m);
    const close = () => { m.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    m.addEventListener('click', (e) => { if (e.target === m) close(); });
    m.querySelectorAll('[data-ft-close]').forEach(b => b.onclick = close);
    m.querySelector('[data-ft-perm]').onclick = () => { close(); openPermissionsModal(tripId); };
    const trail = d.trail || [];
    if (trail.length) {
      ftLeaflet().then((L) => {
        const el = m.querySelector('#kt-ft-map'); if (!el || !m.isConnected) return;
        const map = L.map(el, { zoomControl: true, attributionControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
        const pts = trail.map(x => [x[0], x[1]]);
        const line = L.polyline(pts, { color: '#1DB9C3', weight: 5, opacity: .9 }).addTo(map);
        const dot = (ll, color, label) => L.circleMarker(ll, { radius: 8, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1 }).addTo(map).bindTooltip(label);
        dot(pts[0], '#10B981', 'Start · ' + tm(trail[0][2]));
        if (pts.length > 1) dot(pts[pts.length - 1], '#EF4444', 'Last point · ' + tm(trail[trail.length - 1][2]));
        map.fitBounds(line.getBounds(), { padding: [24, 24], maxZoom: 17 });
        setTimeout(() => map.invalidateSize(), 120);
      }).catch(() => {});
    }
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
      <div style="max-height:300px;overflow:auto;">${(r.data || []).map(p => `<div style="padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;"><div style="display:flex;justify-content:space-between;">
        <span>${esc(p.first_name)} ${esc(p.last_name)}</span>
        <span style="color:${p.status === 'approved' ? '#047857' : p.status === 'denied' ? '#B91C1C' : '#D97706'};font-weight:600;">${p.status}</span></div>${
        /* The signed slip (2026-11-27): the parent's signature with its stamp - Signed date + time, IP, approximate location. */
        p.signature_data ? `<details style="margin-top:4px;"><summary style="cursor:pointer;color:#1F6080;font-size:12px;font-weight:600;">✍️ View signature</summary>
          <img src="${esc(p.signature_data)}" alt="Signature" style="max-width:220px;max-height:70px;display:block;margin-top:6px;background:#fff;">
          ${window.KT && KT.sigStampHtml ? KT.sigStampHtml(p.signature_stamp) : ''}</details>` : ''}</div>`).join('')}</div>
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
            <tbody>${(requests.data || []).map(r => `<tr><td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${fmtDate(r.shift_date)}${r.centre_name ? `<div style="font-size:11.5px;color:#64748B;">${esc(r.centre_name)}</div>` : ''}</td>
              <td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;"><span style="color:${r.status === 'filled' ? '#047857' : '#D97706'};font-weight:600;">${esc(SUB_STATUS[r.status] || 'Open')}</span></td>
              <td style="padding:8px 6px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(r.assigned_name || '')}</td></tr>`).join('') || '<tr><td colspan="3" style="padding:14px;color:#64748B;font-size:13px;">No requests yet.</td></tr>'}</tbody>
          </table>
          <button id="sr-new" style="margin-top:12px;background:#1F6080;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">+ Post open shift</button>
        </div>
      </div>
    </div>`;
    document.getElementById('sr-new').onclick = () => openSubRequestModal();
  }
  // Status words people read, not the stored values (2026-11-27).
  const SUB_STATUS = { open: 'Open', filled: 'Filled', cancelled: 'Cancelled', canceled: 'Cancelled', expired: 'Expired' };
  async function openSubRequestModal() {
    /* The centres this person may post for (operations/my-centres is scoped: a director gets their own centres,
       an agency admin the agency's). A typed "Centre ID" number used to be asked for here. */
    const mine = await Api.get('/operations/my-centres').catch(() => ({ centres: [] }));
    const centres = (mine && mine.centres) || [];
    if (!centres.length) { if (window.KT && KT.toast) KT.toast('You are not placed at a centre yet, so there is nowhere to post a shift for.', 'info', 7000); return; }
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:24px;border-radius:8px;max-width:440px;width:92%;">
      <h3>New substitute request</h3>
      <label style="display:block;margin-top:10px;font-size:13px;font-weight:600;">Centre <select id="sr-cid" style="width:100%;padding:8px;border:1px solid #E5E7EB;border-radius:4px;">${centres.length > 1 ? '<option value="">Choose a centre…</option>' : ''}${centres.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select></label>
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
    m.querySelector('#sr-save').onclick = async (ev) => {
      /* Check before sending and say what went wrong (2026-11-27): the post had no error handling, so a missing
         field or a refusal failed with nothing on screen. */
      const say = (msg, kind) => { if (window.KT && KT.toast) KT.toast(msg, kind || 'info', 7000); };
      const body = {
        centre_id: +m.querySelector('#sr-cid').value,
        shift_date: m.querySelector('#sr-date').value,
        shift_start: m.querySelector('#sr-start').value,
        shift_end: m.querySelector('#sr-end').value,
        reason: m.querySelector('#sr-reason').value,
      };
      if (!body.centre_id) { say('Choose the centre that needs cover.'); return; }
      if (!body.shift_date) { say('Choose the date of the shift.'); return; }
      if (!body.shift_start || !body.shift_end) { say('Enter the start and end time of the shift.'); return; }
      if (body.shift_end <= body.shift_start) { say('The shift has to end after it starts.'); return; }
      const btn = ev && ev.currentTarget; if (btn) btn.disabled = true;
      try {
        const res = await Api.post('/operations/sub-requests', body);
        m.remove();
        const n = (res && res.notified) || 0;
        say(n ? `Shift posted. ${n} substitute${n === 1 ? '' : 's'} told.` : 'Shift posted. No substitutes in your pool cover this centre yet, so nobody was told.', n ? 'success' : 'info');
        renderSubstitutes(document.querySelector('main') || document.getElementById('main'));
      } catch (e) {
        if (btn) btn.disabled = false;
        say((e && e.message) || 'The shift could not be posted. Please try again.', 'error');
      }
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

  // ============================ Subsidies ============================
  /* SUBSIDIES (2026-09-29). Anthony: "rename CWELLCC subsidies to Subsidies and add
     CWELLCC as a subtab and add provincial subsidy as another subtab". The hash stays
     #cwelcc so links, the launcher and permissions keep working; the nav label is
     "Subsidies". The tab you were on is remembered for the session. */
  async function renderCwelcc(main) {
    let tab = 'cwelcc';
    try { tab = sessionStorage.getItem('kt_subsidy_tab') || 'cwelcc'; } catch (e) {}
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-segmented" id="sub-tabs" data-kt-no-tips style="display:inline-flex;gap:6px;margin:0 0 18px;flex-wrap:wrap;">
        <button type="button" data-sub-tab="cwelcc" data-kt-iconized="1">CWELCC</button>
        <button type="button" data-sub-tab="provincial" data-kt-iconized="1">Provincial subsidy</button>
      </div>
      <div id="sub-pane"></div>
    </div>`;
    const pane = main.querySelector('#sub-pane');
    const paint = (key) => {
      main.querySelectorAll('[data-sub-tab]').forEach((b) => {
        const on = b.getAttribute('data-sub-tab') === key;
        b.style.cssText = 'height:32px;padding:0 16px;border-radius:9px;font-weight:800;font-size:13px;cursor:pointer;'
          + (on ? 'background:#1F6080;color:#fff;border:1px solid #1F6080;' : 'background:#fff;color:#334155;border:1px solid #CBD5E1;');
      });
      try { sessionStorage.setItem('kt_subsidy_tab', key); } catch (e) {}
      if (key === 'provincial') renderProvincialPane(pane); else renderCwelccPane(pane);
    };
    main.querySelectorAll('[data-sub-tab]').forEach((b) => b.addEventListener('click', () => paint(b.getAttribute('data-sub-tab'))));
    paint(tab === 'provincial' ? 'provincial' : 'cwelcc');
  }

  async function renderCwelccPane(pane, monthArg) {
    pane.innerHTML = '<div style="padding:8px 0;color:#64748B;">Loading CWELCC report…</div>';
    const month = monthArg || new Date().toISOString().slice(0, 7);
    const r = await Api.get(`/compliance/cwelcc/monthly?month=${month}`);
    if (!pane.isConnected) return;
    // Ontario since 2025 is the base fee model (CwelccFees): no %, the subsidy is the fee subsidy applied.
    const onBase = r.model === 'on_base_fee';
    const L = onBase ? { gross: 'BASE FEE REVENUE', sub: 'FEE SUBSIDY PAID', par: 'PARENTS PAID', g: 'Base fee', s: 'Fee subsidy', p: 'Parents' }
                     : { gross: 'GROSS', sub: 'SUBSIDY', par: 'PARENT', g: 'Gross', s: 'Subsidy', p: 'Parent' };
    pane.innerHTML = `<div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <h3 style="margin:0 0 4px;color:#1F6080;font-size:16px;">CWELCC monthly report</h3>
          ${onBase ? '<div style="color:#64748B;font-size:12.5px;margin:0 0 4px;">Ontario base fee model: parents pay the base fee, less any fee subsidy. Base fee limits and extra-fee checks are on the OCCMS screen.</div>' : ''}
          <div style="color:#6B7280;font-size:14px;">Month: <input id="cw-month" type="month" value="${month}" style="padding:6px;border:1px solid #E5E7EB;border-radius:4px;font-size:14px;"></div>
        </div>
        <div>
          <button id="cw-csv" style="background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:6px;margin-right:8px;cursor:pointer;">⤓ CSV</button>
          <button id="cw-pdf" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;">⤓ PDF</button>
        </div>
      </div>
      <div style="display:flex;gap:14px;margin-top:18px;">
        <div style="flex:1;background:#EFF6FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#1E40AF;font-weight:600;">CHILDREN</div><div style="font-size:24px;font-weight:700;color:#1E40AF;">${r.totals.child_count}</div></div>
        <div style="flex:1;background:#F0FDF4;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#166534;font-weight:600;">${L.gross}</div><div style="font-size:24px;font-weight:700;color:#166534;">$${(r.totals.gross || 0).toFixed(2)}</div></div>
        <div style="flex:1;background:#FEF3C7;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#92400E;font-weight:600;">${L.sub}</div><div style="font-size:24px;font-weight:700;color:#92400E;">$${(r.totals.subsidy || 0).toFixed(2)}</div></div>
        <div style="flex:1;background:#FAE8FF;padding:16px;border-radius:8px;"><div style="font-size:11px;color:#86198F;font-weight:600;">${L.par}</div><div style="font-size:24px;font-weight:700;color:#86198F;">$${(r.totals.parent || 0).toFixed(2)}</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:24px;">
        <thead><tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Family</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Children</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">Centre</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">${L.g}</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">${L.s}</th>
          <th style="text-align:right;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">${L.p}</th>
        </tr></thead>
        <tbody>${(r.data || []).map(row => `<tr>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;"><div style="font-weight:600;">${esc(row.family_name)}</div>
            <div style="font-size:11.5px;color:#64748B;">Enrolled ${subDay(row.period_from)}${row.period_to ? ' – ' + subDay(row.period_to) : ''}${onBase ? '' : ' · ' + esc(row.subsidy_rate) + '%'}</div></td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.child_name || '—')}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(row.centre_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.gross_fee.toFixed(2)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.subsidy_amount.toFixed(2)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;">$${row.parent_portion.toFixed(2)}</td>
        </tr>`).join('') || '<tr><td colspan="6" style="padding:24px;color:#64748B;text-align:center;">No CWELCC-enrolled families for this month.</td></tr>'}</tbody>
      </table>
    </div>`;
    document.getElementById('cw-month').onchange = (e) => { renderCwelccPane(pane, e.target.value); };
    document.getElementById('cw-csv').onclick = () => downloadAuthed(`/compliance/cwelcc/monthly/csv?month=${document.getElementById('cw-month').value}`, `CWELCC-${month}.csv`);
    document.getElementById('cw-pdf').onclick = () => downloadAuthed(`/compliance/cwelcc/monthly/pdf?month=${document.getElementById('cw-month').value}`, `CWELCC-${month}.pdf`);
  }


  /* ---- Provincial subsidy ----
     Each child's provincial fee subsidy: case number, monthly amount, the dates it
     applies. While it applies, the monthly amount comes off that child's tuition on the
     family's invoice -- invoices already read these records. One subsidy per child at a
     time (the server refuses an overlap and names the one in the way). */
  const subDay = (d) => {
    // A date-only string parsed with new Date() is UTC midnight -- the day BEFORE in
    // Toronto. Read the parts instead.
    if (!d) return '';
    const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return esc(d);
    return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  };
  const money = (n) => '$' + (Number(n) || 0).toFixed(2);
  const todayLocal = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };

  async function renderProvincialPane(pane, opts) {
    opts = opts || {};
    const month = opts.month || (pane.dataset.subMonth || new Date().toISOString().slice(0, 7));
    const all = opts.all != null ? opts.all : pane.dataset.subAll === '1';
    pane.dataset.subMonth = month; pane.dataset.subAll = all ? '1' : '0';
    pane.innerHTML = '<div style="padding:8px 0;color:#64748B;">Loading provincial subsidies…</div>';
    let r;
    try { r = await Api.get(`/compliance/subsidies?month=${month}` + (all ? '&all=1' : '')); }
    catch (e) { if (pane.isConnected) pane.innerHTML = `<div style="padding:12px;color:#B91C1C;">${esc(e.message || 'Could not load subsidies.')}</div>`; return; }
    if (!pane.isConnected) return;
    const rows = r.data || [];
    const chip = (st) => ({ active: ['#DCFCE7', '#166534', 'Active'], upcoming: ['#DBEAFE', '#1E40AF', 'Starts later'], ended: ['#F1F5F9', '#475569', 'Ended'] }[st] || ['#F1F5F9', '#475569', st]);
    pane.innerHTML = `<div>
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div>
          <h3 style="margin:0 0 4px;color:#1F6080;font-size:16px;">Provincial fee subsidy</h3>
          <div style="color:#6B7280;font-size:13px;max-width:720px;line-height:1.5;">While a subsidy applies, its monthly amount comes off that child's tuition on the family's invoice. One subsidy per child at a time.</div>
          <div style="display:flex;gap:12px;align-items:center;margin-top:10px;flex-wrap:wrap;font-size:13px;color:#475569;">
            <label>Month <input id="ps-month" type="month" value="${esc(month)}" style="padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:13px;"></label>
            <label style="display:flex;align-items:center;gap:6px;"><input id="ps-all" type="checkbox" ${all ? 'checked' : ''}> Show every subsidy, including ended</label>
          </div>
        </div>
        <div style="display:flex;gap:8px;">
          <button id="ps-add" data-kt-iconized="1" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">＋ Add subsidy</button>
          <button id="ps-csv" data-kt-iconized="1" style="background:#059669;color:#fff;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">⤓ CSV</button>
        </div>
      </div>
      <div style="display:flex;gap:14px;margin-top:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:180px;background:#EFF6FF;padding:14px 16px;border-radius:8px;"><div style="font-size:11px;color:#1E40AF;font-weight:700;">CHILDREN</div><div style="font-size:22px;font-weight:800;color:#1E40AF;">${(r.totals || {}).children || 0}</div></div>
        <div style="flex:1;min-width:180px;background:#FEF3C7;padding:14px 16px;border-radius:8px;"><div style="font-size:11px;color:#92400E;font-weight:700;">SUBSIDY THIS MONTH</div><div style="font-size:22px;font-weight:800;color:#92400E;">${money((r.totals || {}).monthly)}</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:20px;">
        <thead><tr>
          ${['Child', 'Family', 'Centre', 'Case number', 'Subsidy', 'Tuition', 'From', 'To', 'Status', ''].map((h, i) => `<th style="text-align:${i === 4 || i === 5 ? 'right' : 'left'};padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;">${h}</th>`).join('')}
        </tr></thead>
        <tbody>${rows.map((x) => { const c = chip(x.status); const over = x.monthly_fee != null && x.month_amount > x.monthly_fee; return `<tr>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;font-weight:600;">${esc(x.child_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(x.family_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(x.centre_name)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${esc(x.case_number || '—')}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;${over ? 'color:#B91C1C;' : ''}" ${over ? 'title="More than this child\'s monthly tuition — the invoice caps it at the tuition"' : ''}>${x.amount_basis === 'daily'
            ? `${money(x.daily_amount)}/day<div style="font-size:11.5px;color:#64748B;">${x.month_days} day${x.month_days === 1 ? '' : 's'} · ${money(x.month_amount)}</div>`
            : `${money(x.monthly_amount)}/mo`}${(x.documents || []).length ? `<div style="font-size:11.5px;color:#64748B;">📎 ${x.documents.length}</div>` : ''}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;text-align:right;color:#64748B;">${x.monthly_fee != null ? money(x.monthly_fee) : '—'}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${subDay(x.valid_from)}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;font-size:13px;">${x.valid_to ? subDay(x.valid_to) : 'No end date'}</td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;"><span style="background:${c[0]};color:${c[1]};font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;">${c[2]}</span></td>
          <td style="padding:9px 8px;border-bottom:1px solid #F3F4F6;white-space:nowrap;">
            <button type="button" data-ps-edit="${x.id}">Edit</button>
            ${x.status !== 'ended' ? `<button type="button" data-ps-end="${x.id}">End subsidy</button>` : ''}
            <button type="button" data-ps-remove="${x.id}">Remove</button>
          </td>
        </tr>`; }).join('') || `<tr><td colspan="10" style="padding:24px;color:#64748B;text-align:center;">No provincial subsidies ${all ? 'yet' : 'for this month'}. Use <b>＋ Add subsidy</b> to record one.</td></tr>`}</tbody>
      </table>
    </div>`;
    const byId = (id) => rows.find((x) => String(x.id) === String(id));
    pane.querySelector('#ps-month').onchange = (e) => renderProvincialPane(pane, { month: e.target.value });
    pane.querySelector('#ps-all').onchange = (e) => renderProvincialPane(pane, { all: e.target.checked });
    pane.querySelector('#ps-csv').onclick = () => downloadAuthed(`/compliance/subsidies/csv?month=${pane.dataset.subMonth}`, `Provincial-subsidies-${pane.dataset.subMonth}.csv`);
    pane.querySelector('#ps-add').onclick = () => subsidyDialog(pane, null, r.children || []);
    pane.querySelectorAll('[data-ps-edit]').forEach((b) => b.onclick = () => subsidyDialog(pane, byId(b.getAttribute('data-ps-edit')), r.children || []));
    pane.querySelectorAll('[data-ps-end]').forEach((b) => b.onclick = () => endDialog(pane, byId(b.getAttribute('data-ps-end'))));
    pane.querySelectorAll('[data-ps-remove]').forEach((b) => b.onclick = async () => {
      const x = byId(b.getAttribute('data-ps-remove'));
      const ok = window.KT && KT.confirm ? await KT.confirm({
        title: 'Remove this subsidy?',
        description: `Only for a subsidy entered by mistake. ${x.child_name}'s ${x.amount_basis === 'daily' ? money(x.daily_amount) + '/day' : money(x.monthly_amount) + '/month'} will no longer come off future invoices. To stop a real subsidy, use End subsidy instead so its history is kept.`,
        okLabel: 'Remove',
      }) : true;
      if (!ok) return;
      try { await Api.delete('/compliance/subsidies/' + x.id); renderProvincialPane(pane); }
      catch (e) { if (KT.toast) KT.toast('⚠️', 'Not removed', e.message || '', '#B91C1C'); }
    });
    if (window.KT && KT.enhanceTables) { try { KT.enhanceTables(); } catch (e) {} }
  }

  function subModal(title, inner) {
    const m = document.createElement('div');
    m.className = 'kt-modal-overlay';
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px;';
    m.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:520px;width:100%;padding:22px;max-height:90vh;overflow:auto;">
      <h3 style="margin:0 0 14px;font-size:18px;color:#0F172A;">${esc(title)}</h3>${inner}
      <div id="sm-err" style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:8px;"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;">
        <button type="button" data-sm-cancel style="background:#F1F5F9;color:#334155;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="button" data-sm-ok style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-weight:800;cursor:pointer;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('[data-sm-cancel]').onclick = () => m.remove();
    return m;
  }
  const fld = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;';
  const lbl = 'display:block;font-size:12px;font-weight:700;color:#374151;margin:10px 0 4px;';

  /* Uploads go straight to fetch: multipart must not get a hand-set Content-Type, and
     the active agency travels with it like every other call. */
  async function subUpload(subsidyId, files) {
    const fd = new FormData();
    Array.prototype.forEach.call(files, (f) => fd.append('files[]', f, f.name));
    const headers = { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token'), Accept: 'application/json' };
    const aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) headers['X-Active-Agency-Id'] = aid;
    const r = await fetch(apiBase() + '/compliance/subsidies/' + subsidyId + '/documents', { method: 'POST', headers, body: fd });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.message || (j.errors && Object.values(j.errors)[0] && Object.values(j.errors)[0][0]) || ('Upload failed (' + r.status + ')'));
    return j.documents || [];
  }
  const kb = (n) => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

  function subsidyDialog(pane, x, children) {
    const editing = !!x;
    const basis0 = editing ? (x.amount_basis || 'monthly') : 'monthly';
    const m = subModal(editing ? `Edit subsidy — ${x.child_name}` : 'Add a provincial subsidy', `
      ${editing ? '' : `<label style="${lbl}">Child</label>
        <select id="sm-child" style="${fld}"><option value="">— Choose a child —</option>
          ${children.map((c) => `<option value="${c.id}">${esc(c.name)} · ${esc(c.family_name || '')}${c.centre_name ? ' · ' + esc(c.centre_name) : ''}</option>`).join('')}</select>`}
      <label style="${lbl}">Case number</label>
      <input id="sm-case" maxlength="120" style="${fld}" value="${esc(editing ? (x.case_number || '') : '')}" placeholder="From the subsidy approval letter">
      <label style="${lbl}">The subsidy is paid</label>
      <div class="kt-segmented" data-kt-no-tips style="display:inline-flex;gap:6px;">
        <button type="button" data-sm-basis="monthly" data-kt-iconized="1">Per month</button>
        <button type="button" data-sm-basis="daily" data-kt-iconized="1">Per day</button>
      </div>
      <div id="sm-monthly-wrap">
        <label style="${lbl}">Monthly amount ($)</label>
        <input id="sm-amt" type="number" min="0" step="0.01" style="${fld}" value="${editing && basis0 === 'monthly' ? x.monthly_amount : ''}">
      </div>
      <div id="sm-daily-wrap">
        <label style="${lbl}">Daily amount ($)</label>
        <input id="sm-daily" type="number" min="0" step="0.01" style="${fld}" value="${editing && basis0 === 'daily' ? x.daily_amount : ''}">
        <div style="font-size:12px;color:#64748B;margin-top:4px;line-height:1.45;">Each month this comes off as the daily amount × the days the child is scheduled to attend, not counting days the centre is closed.</div>
      </div>
      <div style="display:flex;gap:10px;">
        <div style="flex:1;"><label style="${lbl}">First day</label><input id="sm-from" type="date" style="${fld}" value="${editing ? x.valid_from : todayLocal().slice(0, 8) + '01'}"></div>
        <div style="flex:1;"><label style="${lbl}">Last day (optional)</label><input id="sm-to" type="date" style="${fld}" value="${editing && x.valid_to ? x.valid_to : ''}"></div>
      </div>
      <label style="${lbl}">Approved on (optional)</label>
      <input id="sm-approved" type="date" style="${fld}" value="${editing && x.approved_at ? x.approved_at : ''}">
      <label style="${lbl}">Documents from the province or government</label>
      <div id="sm-docs"></div>
      <input id="sm-files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.webp,.doc,.docx" style="${fld}padding:6px;">
      <div style="font-size:11.5px;color:#64748B;margin-top:3px;">Approval letter, renewal or notice. PDF, photo or Word, up to 15 MB each. Only staff can open them.</div>
      <label style="${lbl}">Notes (optional)</label>
      <textarea id="sm-notes" rows="2" maxlength="1000" style="${fld}resize:vertical;">${esc(editing ? (x.notes || '') : '')}</textarea>
      <div style="font-size:12px;color:#64748B;margin-top:8px;line-height:1.5;">While it applies, the subsidy comes off the child's tuition on each monthly invoice (never more than the tuition). Invoices already issued are not changed.</div>`);

    let basis = basis0;
    const paintBasis = () => {
      m.querySelectorAll('[data-sm-basis]').forEach((b) => {
        const on = b.getAttribute('data-sm-basis') === basis;
        b.style.cssText = 'height:32px;padding:0 14px;border-radius:9px;font-weight:800;font-size:13px;cursor:pointer;'
          + (on ? 'background:#1F6080;color:#fff;border:1px solid #1F6080;' : 'background:#fff;color:#334155;border:1px solid #CBD5E1;');
      });
      m.querySelector('#sm-monthly-wrap').style.display = basis === 'monthly' ? '' : 'none';
      m.querySelector('#sm-daily-wrap').style.display = basis === 'daily' ? '' : 'none';
    };
    m.querySelectorAll('[data-sm-basis]').forEach((b) => b.addEventListener('click', () => { basis = b.getAttribute('data-sm-basis'); paintBasis(); }));
    paintBasis();

    // Documents already on file (editing): download or remove.
    let docs = editing ? (x.documents || []).slice() : [];
    const docsHost = m.querySelector('#sm-docs');
    const paintDocs = () => {
      docsHost.innerHTML = docs.map((d) => `<div style="display:flex;align-items:center;gap:8px;padding:6px 8px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;margin-bottom:6px;font-size:13px;">
          <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">📎 ${esc(d.name)} <span style="color:#94A3B8;">· ${kb(d.size)}</span></span>
          <button type="button" data-doc-get="${d.id}" style="border:0;background:none;color:#1F6080;font-weight:700;cursor:pointer;">Download</button>
          <button type="button" data-doc-del="${d.id}" style="border:0;background:none;color:#B91C1C;font-weight:700;cursor:pointer;">Remove</button></div>`).join('');
      docsHost.querySelectorAll('[data-doc-get]').forEach((b) => b.onclick = () => {
        const d = docs.find((q) => String(q.id) === b.getAttribute('data-doc-get'));
        downloadAuthed(`/compliance/subsidies/${x.id}/documents/${d.id}`, d.name);
      });
      docsHost.querySelectorAll('[data-doc-del]').forEach((b) => b.onclick = async () => {
        try { docs = (await Api.delete(`/compliance/subsidies/${x.id}/documents/${b.getAttribute('data-doc-del')}`)).documents || []; paintDocs(); renderProvincialPane(pane); }
        catch (e) { m.querySelector('#sm-err').textContent = e.message || 'Could not remove.'; }
      });
    };
    paintDocs();

    m.querySelector('[data-sm-ok]').onclick = async () => {
      const err = m.querySelector('#sm-err');
      const body = {
        case_number: m.querySelector('#sm-case').value.trim() || null,
        amount_basis: basis,
        valid_from: m.querySelector('#sm-from').value,
        valid_to: m.querySelector('#sm-to').value || null,
        approved_at: m.querySelector('#sm-approved').value || null,
        notes: m.querySelector('#sm-notes').value.trim() || null,
      };
      if (basis === 'daily') body.daily_amount = parseFloat(m.querySelector('#sm-daily').value);
      else body.monthly_amount = parseFloat(m.querySelector('#sm-amt').value);
      if (!editing) body.child_id = parseInt(m.querySelector('#sm-child').value, 10) || null;
      if (!editing && !body.child_id) { err.textContent = 'Choose the child.'; return; }
      // $0 is allowed (a subsidy on file at no value); blank is not.
      if (!((basis === 'daily' ? body.daily_amount : body.monthly_amount) >= 0)) { err.textContent = basis === 'daily' ? 'Enter the daily amount.' : 'Enter the monthly amount.'; return; }
      if (!body.valid_from) { err.textContent = 'Enter the first day.'; return; }
      if (body.valid_to && body.valid_to < body.valid_from) { err.textContent = 'The last day cannot be before the first day.'; return; }
      const files = m.querySelector('#sm-files').files;
      for (let i = 0; i < files.length; i++) {
        if (files[i].size > 15 * 1024 * 1024) { err.textContent = files[i].name + ' is over 15 MB.'; return; }
      }
      const ok = m.querySelector('[data-sm-ok]'); ok.disabled = true; ok.textContent = 'Saving…';
      let saved = null;
      try {
        saved = editing ? await Api.patch('/compliance/subsidies/' + x.id, body) : await Api.post('/compliance/subsidies', body);
      } catch (e) {
        ok.disabled = false; ok.textContent = 'Save';
        err.textContent = (e && (e.data && e.data.message)) || e.message || 'Could not save.';
        return;
      }
      const id = editing ? x.id : (saved && saved.data && saved.data.id);
      if (files.length && id) {
        ok.textContent = 'Uploading…';
        try { await subUpload(id, files); }
        catch (e) {
          // The subsidy is saved; say so, and leave the dialog open on it for a retry.
          renderProvincialPane(pane);
          ok.disabled = false; ok.textContent = 'Save';
          err.textContent = 'The subsidy was saved, but the document was not uploaded: ' + (e.message || 'try again') + '. Open it with Edit to add the document.';
          return;
        }
      }
      m.remove();
      renderProvincialPane(pane);
    };
  }

  function endDialog(pane, x) {
    const m = subModal(`End subsidy — ${x.child_name}`, `
      <div style="font-size:13px;color:#475569;line-height:1.5;">${x.amount_basis === 'daily' ? money(x.daily_amount) + '/day' : money(x.monthly_amount) + '/month'}${x.case_number ? ', case ' + esc(x.case_number) : ''}, since ${subDay(x.valid_from)}. Invoices issued after the last day no longer include it.</div>
      <label style="${lbl}">Last day</label>
      <input id="sm-to" type="date" style="${fld}" value="${todayLocal()}" min="${esc(x.valid_from)}">`);
    const ok = m.querySelector('[data-sm-ok]'); ok.textContent = 'End subsidy';
    ok.onclick = async () => {
      const to = m.querySelector('#sm-to').value;
      if (!to) { m.querySelector('#sm-err').textContent = 'Enter the last day.'; return; }
      ok.disabled = true;
      try { await Api.post('/compliance/subsidies/' + x.id + '/end', { valid_to: to }); m.remove(); renderProvincialPane(pane); }
      catch (e) { ok.disabled = false; m.querySelector('#sm-err').textContent = (e && (e.data && e.data.message)) || e.message || 'Could not save.'; }
    };
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
  /* REBUILT 2026-10-16 (Anthony: "anomalies section why isn't it detecting all other
     educators with children?" + "can you add ability to set other activity patterns to
     detect those as well"). The server now runs a set of patterns per agency (attendance
     from check-ins, daily records, observations, long days, missing check-outs) and returns
     a per-centre summary, so a provider whose children all go unrecorded shows up as one.
     "What to detect" switches patterns on/off and sets their numbers (agency admins). */
  async function renderAnomalies(main, opts) {
    opts = opts || {};
    const days = opts.days || 30;
    main.innerHTML = '<div style="padding:24px;">Scanning…</div>';
    let r;
    try { r = await Api.get('/ai/attendance-anomalies?days=' + days); }
    catch (e) { main.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not scan: ' + esc((e && e.message) || e) + '</div>'; return; }
    const rules = r.rules || [];
    const ruleLabel = {}; rules.forEach(x => { ruleLabel[x.key] = x.label; });
    const centres = (r.centres || []).slice().sort((a, b) => (b.flagged / Math.max(1, b.children)) - (a.flagged / Math.max(1, a.children)) || String(a.centre_name).localeCompare(String(b.centre_name)));
    let pick = opts.centre || 0;
    const data = r.data || [];
    const on = rules.filter(x => x.enabled);
    const fill = (txt, p) => String(txt || '').replace(/\{(\w+)\}/g, (_, k) => (p && p[k] != null ? p[k] : k));
    const TH = 'text-align:left;padding:8px;border-bottom:1px solid #E5E7EB;font-size:12px;color:#6B7280;';
    const TD = 'padding:10px 8px;border-bottom:1px solid #F3F4F6;';

    const settingsHtml = () => `<div id="an-settings" hidden style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:14px;padding:16px 18px;margin:14px 0 4px;">
        <div style="font-weight:800;color:#0D1B2A;margin-bottom:2px;">What to detect</div>
        <div style="font-size:12.5px;color:#64748B;margin-bottom:12px;">Switch patterns on or off and set their numbers. Applies to everyone in this agency.</div>
        ${rules.map(x => `<div class="an-rule" data-key="${esc(x.key)}" style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid #E5E7EB;flex-wrap:wrap;">
          <label style="display:flex;gap:8px;align-items:center;min-width:220px;font-weight:700;color:#0D1B2A;cursor:pointer;">
            <input type="checkbox" class="an-on" ${x.enabled ? 'checked' : ''} ${r.can_edit_rules ? '' : 'disabled'}> ${esc(x.label)}</label>
          <div style="flex:1;min-width:240px;">
            <div style="font-size:12.5px;color:#475569;margin-bottom:6px;">${esc(fill(x.help, x.params))}</div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">${Object.keys(x.params).map(p => `<label style="font-size:12px;color:#64748B;display:flex;align-items:center;gap:6px;">${esc(paramLabel(p))}
              <input type="number" min="0" max="1000" step="1" class="an-p" data-p="${esc(p)}" value="${esc(x.params[p])}" ${r.can_edit_rules ? '' : 'disabled'} style="width:76px;height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;"></label>`).join('')}</div>
          </div></div>`).join('')}
        ${r.can_edit_rules
          ? '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;"><button type="button" id="an-reset" class="kt-btn kt-btn-secondary kt-btn-sm">Defaults</button><button type="button" id="an-save" class="kt-btn kt-btn-primary kt-btn-sm">Save and rescan</button></div>'
          : '<div style="font-size:12px;color:#64748B;margin-top:8px;">Only an agency admin can change these.</div>'}
      </div>`;

    const centreChips = () => centres.length > 1 ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 6px;">
        <button type="button" class="an-c" data-c="0" style="${chipStyle(!pick)}">All centres · ${data.length}</button>
        ${centres.map(c => `<button type="button" class="an-c" data-c="${c.centre_id}" title="${c.flagged} of ${c.children} children flagged" style="${chipStyle(pick === c.centre_id, c.flagged && c.flagged === c.children)}">${esc(c.centre_name)} · ${c.flagged}/${c.children}</button>`).join('')}
      </div>` : '';

    const rowsHtml = () => {
      const list = pick ? data.filter(a => a.centre_id === pick) : data;
      if (!list.length) return `<tr><td colspan="5" style="padding:24px;color:#64748B;text-align:center;">${on.length ? 'No anomalies here. Every child matches the patterns being watched.' : 'Every pattern is switched off. Open “What to detect” to turn some on.'}</td></tr>`;
      return list.map(a => `<tr>
          <td style="${TD}font-weight:600;">${esc(a.child_name)}</td>
          <td style="${TD}font-size:13px;color:#475569;">${esc(a.centre_name || '')}</td>
          <td style="${TD}font-size:13px;">${(a.signals || []).map((s, i) => `<div style="color:#B91C1C;margin:1px 0;"><span style="display:inline-block;font-size:11px;font-weight:700;color:#7C2D12;background:#FFEDD5;border-radius:999px;padding:1px 7px;margin-right:6px;">${esc(ruleLabel[(a.signal_rules || [])[i]] || 'Pattern')}</span>${esc(s)}</div>`).join('')}</td>
          <td style="${TD}text-align:right;">${a.days_attended ?? '—'}</td>
          <td style="${TD}text-align:right;">${a.days_with_records ?? '—'} / ${a.obs_window ?? '—'}</td>
        </tr>`).join('');
    };

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div><h2 style="margin:0 0 6px;color:#1F6080;">Attendance anomalies</h2>
          <p style="color:#6B7280;font-size:14px;margin:0;">${data.length} of ${r.children_checked || 0} enrolled children match a pattern in the last ${r.window_days} days. Watching ${on.length} of ${rules.length} patterns.</p></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <select id="an-days" style="height:32px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;">${[14, 30, 60, 90].map(d => `<option value="${d}" ${d === days ? 'selected' : ''}>Last ${d} days</option>`).join('')}</select>
          <button type="button" id="an-cfg" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1">⚙️ What to detect</button>
        </div>
      </div>
      ${settingsHtml()}
      ${centreChips()}
      <table style="width:100%;border-collapse:collapse;margin-top:12px;">
        <thead><tr><th style="${TH}">Child</th><th style="${TH}">Centre / provider</th><th style="${TH}">What we noticed</th>
          <th style="${TH}text-align:right;">Days attended</th><th style="${TH}text-align:right;" title="Days with daily records / observations in the window">Records / obs</th></tr></thead>
        <tbody id="an-rows">${rowsHtml()}</tbody></table>
    </div>`;

    const cfg = main.querySelector('#an-settings');
    main.querySelector('#an-cfg').onclick = () => { cfg.hidden = !cfg.hidden; };
    main.querySelector('#an-days').onchange = (e) => renderAnomalies(main, { days: Number(e.target.value), centre: pick });
    main.querySelectorAll('.an-c').forEach(b => b.onclick = () => {
      pick = Number(b.getAttribute('data-c')) || 0;
      main.querySelectorAll('.an-c').forEach(x => { const c = centres.find(y => y.centre_id === Number(x.getAttribute('data-c'))); x.style.cssText = chipStyle(Number(x.getAttribute('data-c')) === pick, c && c.flagged && c.flagged === c.children); });
      main.querySelector('#an-rows').innerHTML = rowsHtml();
    });
    const collect = (useDefaults) => {
      const out = {};
      main.querySelectorAll('.an-rule').forEach(row => {
        const k = row.getAttribute('data-key'); const def = rules.find(x => x.key === k) || {};
        const params = {};
        row.querySelectorAll('.an-p').forEach(i => { params[i.getAttribute('data-p')] = useDefaults ? (def.defaults || {})[i.getAttribute('data-p')] : Number(i.value); });
        out[k] = { enabled: useDefaults ? undefined : row.querySelector('.an-on').checked, params };
        if (useDefaults) delete out[k].enabled;
      });
      return out;
    };
    const save = async (useDefaults) => {
      try {
        await Api.put('/ai/attendance-anomalies/rules', { rules: collect(useDefaults) });
        if (KT.toast) KT.toast('✅', 'Saved', 'Rescanning with the new patterns.', '#0F766E');
        renderAnomalies(main, { days, centre: pick });
      } catch (e) { if (KT.toast) KT.toast('⚠️', 'Not saved', (e && e.message) || '', '#B91C1C'); }
    };
    const sv = main.querySelector('#an-save'); if (sv) sv.onclick = () => save(false);
    const rs = main.querySelector('#an-reset'); if (rs) rs.onclick = () => save(true);
  }
  function chipStyle(active, all) {
    return 'height:30px;padding:0 12px;border-radius:999px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;'
      + 'border:1px solid ' + (active ? '#1F6080' : (all ? '#FCA5A5' : '#CBD5E1')) + ';'
      + 'background:' + (active ? '#1F6080' : (all ? '#FEF2F2' : '#fff')) + ';color:' + (active ? '#fff' : (all ? '#B91C1C' : '#334155')) + ';';
  }
  function paramLabel(p) {
    return ({ min_days: 'Min. days', pct: '%', min_obs: 'Min. observations', prior: 'Prior observations', min_prior_days: 'Min. prior days', hours: 'Hours' })[p] || p;
  }

  // ============================ Renewals calendar ============================
  /* 2026-11-27: background checks AND staff certificates (CPR, first aid...), named the way people read them, scoped
     by the server (a director sees their own centres' staff only; people who have left are not listed). A failed load
     says so with Try again - it never sits on "Loading…". */
  async function renderExpiryCalendar(main) {
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    let r;
    try {
      r = await Api.get('/compliance/expiry-calendar?within_days=180');
    } catch (e) {
      main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
        <h2 style="margin:0 0 6px;color:#1F6080;">Renewals calendar</h2>
        <div role="alert" style="margin-top:14px;padding:16px;border:1px solid #FECACA;background:#FEF2F2;border-radius:10px;color:#7F1D1D;font-size:14px;">
          <strong>The renewals calendar didn’t load.</strong>
          <div style="margin-top:4px;color:#991B1B;font-size:13px;">${esc((e && e.message) || 'Check your connection, then try again.')}</div>
          <button type="button" id="rn-retry" class="kt-btn" style="margin-top:12px;">Try again</button>
        </div></div>`;
      const b = main.querySelector('#rn-retry');
      if (b) b.onclick = () => renderExpiryCalendar(main);
      return;
    }
    const groups = { expired: [], soon: [], upcoming: [], future: [] };
    ((r && r.data) || []).forEach(row => { (groups[row.bucket] || groups.future).push(row); });
    const SOURCE = { certificate: 'Certificate', background_check: 'Background check' };
    const th = 'text-align:left;padding:9px 12px;border-bottom:2px solid #E5E7EB;font-size:11px;color:#6B7280;';
    const td = 'padding:9px 12px;border-bottom:1px solid #F3F4F6;font-size:13px;';
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <h2 style="margin:0 0 6px;color:#1F6080;">Renewals calendar</h2>
      <p style="color:#6B7280;font-size:14px;margin:0;">Background checks and staff certificates (CPR, first aid and more) that expire in the next 6 months, or already have.</p>
      ${['expired', 'soon', 'upcoming', 'future'].map(b => {
        const titles = { expired: '⛔ Expired', soon: '⚠ Next 30 days', upcoming: 'Next 90 days', future: 'Later' };
        const colors = { expired: '#FEE2E2', soon: '#FEF3C7', upcoming: '#EFF6FF', future: '#F9FAFB' };
        return `<h3 style="margin-top:22px;font-size:15px;"><span>${titles[b]}</span> <span>(${groups[b].length})</span></h3>
          ${groups[b].length ? `<table style="width:100%;border-collapse:collapse;"><thead><tr><th style="${th}">Staff member</th><th style="${th}">What</th><th style="${th}">Expires</th></tr></thead><tbody>${groups[b].map(row => `<tr style="background:${colors[b]};">
            <td style="${td}"><strong>${esc(row.user_name)}</strong></td>
            <td style="${td}">${esc(row.type_label || row.type)} <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;background:#fff;border:1px solid #CBD5E1;color:#475569;font-size:11px;font-weight:700;">${SOURCE[row.source] || ''}</span></td>
            <td style="${td}white-space:nowrap;">${fmtDate(row.expires_at)} <span style="color:#6B7280;font-size:11px;">${row.days_until < 0 ? `${Math.abs(row.days_until)} day${row.days_until === -1 ? '' : 's'} ago` : (row.days_until === 0 ? 'Today' : `${row.days_until} day${row.days_until === 1 ? '' : 's'} left`)}</span></td>
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
