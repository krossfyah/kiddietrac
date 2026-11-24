/* ═══════════════════════════════════════════════════════════════════
   Ontario curriculum library (2026-11-03) - #curriculum.

   Ready-made multi-week units for infant, toddler, preschool and kindergarten /
   school-age rooms, built on How Does Learning Happen? (the four foundations).
   Backend: CurriculumUnitController (/curriculum/units...). Library units are
   KiddieTrac's and read-only; directors and admins copy one into the agency and edit
   the copy. "Use this unit" writes DRAFT weekly lesson plans for a room or centre -
   families see nothing until a staff member publishes the week in Lesson plans.

   Drawn by screen-v22p57.js renderCurriculum (the shim bridge for #curriculum), which
   hands over to KT.CurriculumLibrary.render. The old one-activity ideas stay on the
   "Activity ideas" tab for directors and admins (KT.V22p57.renderCurriculumIdeas).
   House rules: full window, kt-tab-band tab strips (role=tablist), Shell.Modal popups
   (navy-teal header, X in the header, one scroll region), works at 390px.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = (w.KT = w.KT || {});

  var FOUND = {
    belonging:  { l: 'Belonging',  c: '#C2410C', bg: '#FFF1E6', i: '🤝' },
    wellbeing:  { l: 'Well-being', c: '#047857', bg: '#E7F7EF', i: '🌱' },
    engagement: { l: 'Engagement', c: '#1D4ED8', bg: '#E8EFFE', i: '🔍' },
    expression: { l: 'Expression', c: '#7E22CE', bg: '#F4EAFD', i: '🎨' },
  };
  var FKEYS = ['belonging', 'wellbeing', 'engagement', 'expression'];
  var AGES = { infant: 'Infant', toddler: 'Toddler', preschool: 'Preschool', school_age: 'Kindergarten / school-age' };
  var AGE_ICON = { infant: '👶', toddler: '🧸', preschool: '🎒', school_age: '🏫' };
  var SEASONS = { any: 'Any time', fall: '🍂 Fall', winter: '❄️ Winter', spring: '🌷 Spring', summer: '☀️ Summer' };
  var DOMAINS = { social_emotional: 'Social/Emotional', physical: 'Physical', language_literacy: 'Language/Literacy',
    cognitive: 'Cognitive', creative_arts: 'Creative Arts', self_care: 'Self Care', outdoor: 'Outdoor' };
  var DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  var DAY_L = { monday: 'Mon', tuesday: 'Tue', wednesday: 'Wed', thursday: 'Thu', friday: 'Fri' };

  var st = { tab: 'library', f: { q: '', age: '', foundation: '', season: '', theme: '' }, units: [], themes: [], canManage: false, loaded: false };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function Api() { return KT.Api; }
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c || '#1F6080'); } catch (e) {} }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
  function phone() { return w.innerWidth <= 768; }
  function role() { try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); return u.primary_role || (u.roles && u.roles[0]) || ''; } catch (e) { return ''; } }
  function setHash(h) { try { history.replaceState(null, '', h); } catch (e) {} }

  /* Mondays from numeric parts (a 'YYYY-MM-DD' string parses as UTC and lands on the previous day here). */
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function mondayISO(s) {
    var p = String(s || '').split('-');
    var d = p.length === 3 ? new Date(+p[0], +p[1] - 1, +p[2]) : null;
    if (!d || isNaN(d.getTime())) {
      var t = (KT.agencyToday ? KT.agencyToday() : ymd(new Date())).split('-');
      d = new Date(+t[0], +t[1] - 1, +t[2]);
    }
    var dow = d.getDay(); d.setDate(d.getDate() + ((dow === 0 ? -6 : 1) - dow));
    return ymd(d);
  }
  function nextMondayISO() { var p = mondayISO().split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2] + 7); return ymd(d); }
  function fmtWeek(s) { var p = String(s).split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2]); return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }

  function chip(k, small) {
    var f = FOUND[k]; if (!f) return '';
    return '<span class="kcl-f' + (small ? ' kcl-f-sm' : '') + '" style="--fc:' + f.c + ';--fb:' + f.bg + '">' + f.i + ' ' + esc(f.l) + '</span>';
  }

  function css() {
    if (document.getElementById('kcl-css')) return;
    var s = document.createElement('style'); s.id = 'kcl-css';
    s.textContent = ''
      + '.kcl{padding:16px 20px 28px;width:100%;box-sizing:border-box}'
      + '.kcl-hero{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 16px;margin:0 0 12px}'
      + '.kcl-hero h2{margin:0;font-size:20px;font-weight:800;color:#0D1B2A}.kcl-hero p{margin:0;color:#64748B;font-size:13px;max-width:760px}'
      + '.kcl-tabs{margin:4px 0 12px}'
      + '.kcl-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 14px}'
      + '.kcl-filters input,.kcl-filters select{height:32px;box-sizing:border-box;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13px;background:#fff;color:#0F172A}'
      + '.kcl-filters input{flex:1 1 220px;min-width:160px;max-width:360px}.kcl-filters select{flex:0 1 auto;max-width:210px}'
      + '.kcl-count{font-size:12.5px;color:#64748B;margin-left:auto}'
      + '.kcl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:14px}'
      + '.kcl-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 15px;display:flex;flex-direction:column;gap:8px;cursor:pointer;text-align:left;font:inherit;color:inherit;box-shadow:0 1px 2px rgba(15,23,42,.04);transition:box-shadow .15s,border-color .15s}'
      + '.kcl-card:hover,.kcl-card:focus-visible{border-color:#7FD3CC;box-shadow:0 6px 18px -10px rgba(31,96,128,.5);outline:none}'
      + '.kcl-card h3{margin:0;font-size:15.5px;font-weight:800;color:#0D1B2A;line-height:1.25}'
      + '.kcl-meta{display:flex;flex-wrap:wrap;gap:5px;font-size:11.5px;color:#475569}'
      + '.kcl-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:#F1F5F9;color:#334155;font-size:11.5px;font-weight:700;white-space:nowrap}'
      + '.kcl-pill-own{background:#E0F2F1;color:#0F766E}'
      + '.kcl-sum{font-size:13px;color:#475569;line-height:1.45;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}'
      + '.kcl-fs{display:flex;flex-wrap:wrap;gap:4px}'
      + '.kcl-f{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;background:var(--fb);color:var(--fc);font-size:11.5px;font-weight:700;white-space:nowrap}'
      + '.kcl-f-sm{padding:1px 6px;font-size:10.5px}'
      + '.kcl-empty{grid-column:1/-1;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;padding:34px 16px;text-align:center;color:#64748B;font-size:13.5px}'
      + '.kcl-btn{height:32px;box-sizing:border-box;padding:0 13px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font:inherit;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}'
      + '.kcl-btn-p{background:linear-gradient(135deg,#1F6080,#2c7894);border:0;color:#fff;box-shadow:0 6px 14px -8px rgba(31,96,128,.85)}'
      + '.kcl-btn-d{border-color:#FCA5A5;color:#B91C1C}.kcl-btn[disabled]{opacity:.6;cursor:progress}'
      + '.kcl-top{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin:0 0 10px}'
      + '.kcl-top h2{margin:0;font-size:21px;font-weight:800;color:#0D1B2A;flex:1 1 320px;min-width:0}'
      + '.kcl-acts{display:flex;flex-wrap:wrap;gap:8px}'
      /* THE UNIT PAGE (2026-11-03, Anthony: "when you select one the presentment of the details is all over the place").
         It was two columns side by side, the right one three times longer than the left, so the left ran out into
         empty space and the week was squeezed into it. Now: About | Goals, then the week across the full width, then
         the supporting notes in balanced columns. */
      + '.kcl-layout{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px;align-items:stretch;margin:0 0 14px}'
      + '.kcl-layout>.kcl-box{margin:0}.kcl-col{display:flex;flex-direction:column;gap:14px;min-width:0}.kcl-col>.kcl-box{margin:0}.kcl-col>.kcl-box:last-child{flex:1}'
      + '.kcl-more{columns:2 340px;column-gap:14px}.kcl-more>.kcl-box{break-inside:avoid;display:inline-block;width:100%;box-sizing:border-box}'
      + '.kcl-foot{font-size:11px;color:#94A3B8;margin:2px 2px 0}'
      + '.kcl-box{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 15px;margin:0 0 14px}'
      + '.kcl-box h3{margin:0 0 8px;font-size:14px;font-weight:800;color:#0D1B2A;display:flex;align-items:center;gap:6px}'
      + '.kcl-box ul{margin:0;padding-left:18px}.kcl-box li{margin:0 0 6px;font-size:13px;color:#334155;line-height:1.45}'
      + '.kcl-goal{display:flex;flex-direction:column;gap:4px;padding:8px 0;border-top:1px solid #F1F5F9}.kcl-goal:first-of-type{border-top:0;padding-top:0}'
      + '.kcl-goal-t{font-size:13px;color:#0F172A;line-height:1.4}.kcl-cont{font-size:11px;color:#64748B}'
      + '.kcl-week-focus{font-size:13px;color:#475569;margin:0 0 10px}'
      + '.kcl-days{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}'
      + '.kcl-week{margin:0 0 14px}'
      + '.kcl-day{background:#F8FAFC;border:1px solid #E7EDF3;border-radius:12px;padding:8px;min-width:0}'
      + '.kcl-day-h{font-size:12px;font-weight:800;color:#1F6080;margin:0 0 6px;text-transform:uppercase;letter-spacing:.4px}'
      + '.kcl-act{display:block;width:100%;text-align:left;background:#fff;border:1px solid #E2E8F0;border-left:4px solid var(--ac,#7FD3CC);border-radius:9px;padding:7px 8px;margin:0 0 7px;font:inherit;cursor:pointer;color:inherit;box-sizing:border-box}'
      + '.kcl-act:hover,.kcl-act:focus-visible{border-color:#7FD3CC;outline:none}'
      + '.kcl-act-t{display:block;font-size:12.5px;font-weight:700;color:#0D1B2A;line-height:1.25;margin:0 0 4px;overflow-wrap:anywhere}'
      + '.kcl-cover{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}.kcl-cover .kcl-f{font-size:12px}'
      + '.kcl-vocab{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;margin:0 0 4px}'
      + '.kcl-vocab span{display:flex;flex-wrap:wrap;align-items:baseline;gap:1px 6px;background:#F8FAFC;border:1px solid #EEF2F6;border-radius:8px;padding:5px 9px;font-size:12.5px;color:#475569;min-width:0}'
      + '.kcl-vocab b{color:#1F6080;font-weight:700;overflow-wrap:anywhere}'
      + '.kcl-note{font-size:12px;color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:8px 10px;margin:0 0 12px}'
      + '.kcl-f-dlg label{display:block;font-size:12px;font-weight:700;color:#475569;margin:10px 0 4px}'
      + '.kcl-f-dlg input,.kcl-f-dlg select,.kcl-f-dlg textarea{width:100%;box-sizing:border-box;padding:6px 10px;min-height:32px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13px;background:#fff}'
      + '.kcl-f-dlg textarea{resize:vertical;line-height:1.4}'
      + '.kcl-wks{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:13px}.kcl-wks label{display:inline-flex;align-items:center;gap:6px;margin:0;font-weight:600;color:#334155}'
      + '.kcl-wks input{width:16px;height:16px;min-height:0}'
      + '.kcl-res li{font-size:13px;margin:0 0 4px}'
      + '.kcl-ed-act{border:1px solid #E2E8F0;border-radius:12px;padding:10px 12px;margin:0 0 10px;background:#FBFDFF}'
      + '.kcl-ed-row{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:10px}'
      + '.kcl-ed-fs{display:flex;flex-wrap:wrap;gap:6px 12px}.kcl-ed-fs label{display:inline-flex;align-items:center;gap:5px;margin:0;font-weight:600;font-size:12.5px;color:#334155}'
      + '.kcl-ed-fs input{width:15px;height:15px;min-height:0}'
      + '@media (max-width:1100px){.kcl-layout{grid-template-columns:minmax(0,1fr)}.kcl-days{grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}}'
      + '@media (max-width:768px){.kcl{padding:12px 12px 24px}.kcl-days{grid-template-columns:minmax(0,1fr)}.kcl-filters select{flex:1 1 calc(50% - 8px);max-width:none}'
      + '.kcl-filters input{max-width:none;flex-basis:100%}.kcl-count{margin-left:0}.kcl-top h2{font-size:18px;flex-basis:100%}.kcl-ed-row{grid-template-columns:minmax(0,1fr)}}';
    document.head.appendChild(s);
  }

  /* ── popups: the house Shell.Modal (gradient header, X in the header, one scroll body) ── */
  function modal(opts) {
    if (KT.Shell && KT.Shell.Modal && KT.Shell.Modal.open) return KT.Shell.Modal.open(opts);
    // Fallback only if the shell is missing (never in the portal).
    var body = typeof opts.body === 'string' ? opts.body : '';
    w.alert(opts.title + '\n\n' + (body ? body.replace(/<[^>]+>/g, '') : ''));
    return null;
  }
  function ask(title, text, okLabel, danger) {
    return new Promise(function (resolve) {
      var done = false;
      modal({ title: title, body: '<p style="margin:0;font-size:14px;color:#334155;line-height:1.5;">' + esc(text) + '</p>',
        onClose: function () { if (!done) resolve(false); },
        actions: [{ label: 'Cancel' }, { label: okLabel || 'OK', primary: !danger, style: danger ? 'btn-danger' : undefined,
          handler: function () { done = true; resolve(true); } }] });
    });
  }

  // ════════════════════════════════ list ════════════════════════════════
  async function render(main) {
    css();
    main.setAttribute('data-kt-pretty', '1');
    var m = /[?&]unit=(\d+)/.exec(location.hash || '');
    if (m) { return preview(main, +m[1]); }
    main.innerHTML = '<div class="kcl"><div style="padding:24px;color:#64748B;">Loading the curriculum library…</div></div>';
    await loadList();
    if (!main.isConnected) return;
    drawList(main);
  }

  async function loadList() {
    try {
      var r = await Api().get('/curriculum/units');
      st.units = r.units || []; st.themes = r.themes || []; st.canManage = !!r.can_manage; st.loaded = true;
    } catch (e) { st.units = []; st.error = (e && e.message) || 'Could not load the library.'; }
  }

  function filtered(own) {
    var f = st.f, q = f.q.trim().toLowerCase();
    return st.units.filter(function (u) {
      if (own ? u.source !== 'agency' : u.source !== 'library') return false;
      if (f.age && u.age_group !== f.age) return false;
      if (f.season && u.season !== f.season) return false;
      if (f.theme && u.theme !== f.theme) return false;
      if (f.foundation && (u.foundations || []).indexOf(f.foundation) === -1) return false;
      if (q && (u.title + ' ' + (u.summary || '') + ' ' + (u.theme || '') + ' ' + (AGES[u.age_group] || '')).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  }

  function drawList(main) {
    var own = st.units.filter(function (u) { return u.source === 'agency'; }).length;
    var tabs = [['library', '📖 Ontario library'], ['own', '🏠 Our units' + (own ? ' (' + own + ')' : '')]];
    if (st.canManage) tabs.push(['ideas', '💡 Activity ideas']);
    if (!st.canManage && st.tab === 'ideas') st.tab = 'library';
    var f = st.f;
    main.innerHTML = '<div class="kcl">'
      + '<div class="kcl-hero"><div>'
      + '<p>Ready-made units built on Ontario\'s <em>How Does Learning Happen?</em> and its four foundations: Belonging, Well-being, Engagement and Expression. Preview a unit, then use it to make draft weekly lesson plans for a room.</p></div></div>'
      + '<div class="kcl-tabs" role="tablist" aria-label="Curriculum library">'
      + tabs.map(function (t) { return '<button type="button" data-kt-inpage role="tab" data-tab="' + t[0] + '" aria-selected="' + (st.tab === t[0]) + '">' + esc(t[1]) + '</button>'; }).join('')
      + '</div><div id="kcl-pane"></div></div>';
    main.querySelectorAll('[data-tab]').forEach(function (b) {
      b.onclick = function () { st.tab = b.getAttribute('data-tab'); drawList(main); };
    });
    var pane = main.querySelector('#kcl-pane');
    if (st.tab === 'ideas') {
      if (KT.V22p57 && KT.V22p57.renderCurriculumIdeas) { KT.V22p57.renderCurriculumIdeas(pane); }
      return;
    }
    var ownTab = st.tab === 'own';
    var opt = function (v, l, cur) { return '<option value="' + esc(v) + '"' + (v === cur ? ' selected' : '') + '>' + esc(l) + '</option>'; };
    pane.innerHTML = '<div class="kcl-filters">'
      + '<input type="search" id="kcl-q" placeholder="Search units, themes, activities…" value="' + esc(f.q) + '" aria-label="Search units">'
      + '<select id="kcl-age" aria-label="Age group">' + opt('', 'All ages', f.age) + Object.keys(AGES).map(function (k) { return opt(k, AGES[k], f.age); }).join('') + '</select>'
      + '<select id="kcl-fd" aria-label="Foundation">' + opt('', 'All foundations', f.foundation) + FKEYS.map(function (k) { return opt(k, FOUND[k].l, f.foundation); }).join('') + '</select>'
      + '<select id="kcl-se" aria-label="Season">' + opt('', 'All seasons', f.season) + Object.keys(SEASONS).map(function (k) { return opt(k, SEASONS[k], f.season); }).join('') + '</select>'
      + '<select id="kcl-th" aria-label="Theme">' + opt('', 'All themes', f.theme) + st.themes.map(function (t) { return opt(t, t, f.theme); }).join('') + '</select>'
      + '<span class="kcl-count" id="kcl-count"></span></div>'
      + '<div class="kcl-grid" id="kcl-grid" data-kt-no-controls data-kt-nocount data-kt-list="1"></div>';
    var paint = function () {
      var list = filtered(ownTab);
      pane.querySelector('#kcl-count').textContent = list.length + ' unit' + (list.length === 1 ? '' : 's');
      var g = pane.querySelector('#kcl-grid');
      if (st.error) { g.innerHTML = '<div class="kcl-empty">' + esc(st.error) + '</div>'; return; }
      g.innerHTML = list.length ? list.map(card).join('')
        : '<div class="kcl-empty">' + (ownTab
          ? (st.units.some(function (u) { return u.source === 'agency'; }) ? 'No units match these filters.' : 'No units of your own yet. ' + (st.canManage ? 'Open a unit in the Ontario library and press <strong>Copy to edit</strong> to make one.' : 'A director or admin can copy a library unit here to adapt it.'))
          : 'No units match these filters.') + '</div>';
      g.querySelectorAll('[data-unit]').forEach(function (b) {
        b.onclick = function () { preview(main, +b.getAttribute('data-unit')); };
      });
    };
    var q = pane.querySelector('#kcl-q');
    q.oninput = function () { f.q = q.value; paint(); };
    [['#kcl-age', 'age'], ['#kcl-fd', 'foundation'], ['#kcl-se', 'season'], ['#kcl-th', 'theme']].forEach(function (p) {
      var s = pane.querySelector(p[0]); s.onchange = function () { f[p[1]] = s.value; paint(); };
    });
    paint();
  }

  function card(u) {
    return '<button type="button" data-kt-inpage class="kcl-card" data-unit="' + u.id + '" aria-label="Preview ' + esc(u.title) + '">'
      + '<h3>' + esc(u.title) + '</h3>'
      + '<div class="kcl-meta"><span class="kcl-pill">' + (AGE_ICON[u.age_group] || '') + ' ' + esc(AGES[u.age_group] || u.age_group) + '</span>'
      + '<span class="kcl-pill">🗓 ' + u.weeks + ' week' + (u.weeks === 1 ? '' : 's') + '</span>'
      + (u.season && u.season !== 'any' ? '<span class="kcl-pill">' + esc(SEASONS[u.season] || u.season) + '</span>' : '')
      + (u.source === 'agency' ? '<span class="kcl-pill kcl-pill-own">🏠 Our copy</span>' : '') + '</div>'
      + '<div class="kcl-sum">' + esc(u.summary || '') + '</div>'
      + '<div class="kcl-fs">' + (u.foundations || []).map(function (k) { return chip(k, true); }).join('') + '</div>'
      + '<div class="kcl-meta" style="margin-top:auto;">' + esc(u.theme || '') + ' · ' + u.activity_count + ' activities' + (u.use_count ? ' · used ' + u.use_count + '×' : '') + '</div>'
      + '</button>';
  }

  // ════════════════════════════════ preview ════════════════════════════════
  async function preview(main, id, week) {
    css();
    main.innerHTML = '<div class="kcl"><div style="padding:24px;color:#64748B;">Loading the unit…</div></div>';
    var r;
    try { r = await Api().get('/curriculum/units/' + id); }
    catch (e) {
      main.innerHTML = '<div class="kcl"><button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" id="kcl-back">← All units</button><div class="kcl-empty" style="margin-top:12px;">' + esc((e && e.message) || 'That unit could not be opened.') + '</div></div>';
      main.querySelector('#kcl-back').onclick = function () { setHash('#curriculum'); render(main); };
      return;
    }
    if (!main.isConnected) return;
    setHash('#curriculum?unit=' + id);
    var u = r.unit, d = u.data || {}, manage = !!r.can_manage;
    st.canManage = manage;
    var wk = Math.min(Math.max(1, week || 1), Math.max(1, (d.schedule || []).length));
    var acts = {}; (d.activities || []).forEach(function (a) { acts[a.id] = a; });
    // How the unit's activities spread over the four foundations.
    var cover = {}; FKEYS.forEach(function (k) { cover[k] = 0; });
    (d.activities || []).forEach(function (a) { (a.foundations || []).forEach(function (k) { if (k in cover) cover[k]++; }); });

    var copies = (r.copies || []);
    var actions = '<button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn kcl-btn-p" id="kcl-use">📅 Use this unit</button>'
      + (manage && u.source === 'library' ? '<button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" id="kcl-copy">⧉ Copy to edit</button>' : '')
      + (manage && u.source === 'agency' ? '<button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" id="kcl-edit">✏️ Edit</button><button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn kcl-btn-d" id="kcl-del">Delete</button>' : '');

    var weekTabs = (d.schedule || []).length > 1
      ? '<div role="tablist" aria-label="Weeks" class="kcl-tabs">' + d.schedule.map(function (s) {
          return '<button type="button" data-kt-inpage role="tab" data-wk="' + s.week + '" aria-selected="' + (s.week === wk) + '">Week ' + s.week + '</button>';
        }).join('') + '</div>' : '';
    var sched = (d.schedule || []).filter(function (s) { return s.week === wk; })[0] || { days: {} };
    var daysHtml = DAYS.map(function (dk) {
      return '<div class="kcl-day"><div class="kcl-day-h">' + DAY_L[dk] + '</div>'
        + ((sched.days || {})[dk] || []).map(function (ref) {
          var a = acts[ref]; if (!a) return '';
          var c = FOUND[(a.foundations || [])[0]] || {};
          return '<button type="button" data-kt-inpage class="kcl-act" data-act="' + esc(a.id) + '" style="--ac:' + (c.c || '#7FD3CC') + '">'
            + '<span class="kcl-act-t">' + esc(a.title) + '</span><span class="kcl-fs">' + (a.foundations || []).map(function (k) { return chip(k, true); }).join('') + '</span></button>';
        }).join('') + '</div>';
    }).join('');

    var list = function (title, icon, arr) {
      return (arr && arr.length) ? '<div class="kcl-box"><h3>' + icon + ' ' + esc(title) + '</h3><ul>' + arr.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>' : '';
    };
    var fr = d.french || {};
    main.innerHTML = '<div class="kcl">'
      + '<div class="kcl-top"><button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" id="kcl-back">← All units</button>'
      + '<h2>' + esc(u.title) + '</h2><div class="kcl-acts">' + actions + '</div></div>'
      + '<div class="kcl-meta" style="margin:0 0 10px;"><span class="kcl-pill">' + (AGE_ICON[u.age_group] || '') + ' ' + esc(AGES[u.age_group] || '') + (d.age_label ? ' · ' + esc(d.age_label) : '') + '</span>'
      + '<span class="kcl-pill">🗓 ' + u.weeks + ' week' + (u.weeks === 1 ? '' : 's') + '</span><span class="kcl-pill">' + esc(SEASONS[u.season] || 'Any time') + '</span>'
      + (u.theme ? '<span class="kcl-pill">' + esc(u.theme) + '</span>' : '')
      + (u.source === 'agency' ? '<span class="kcl-pill kcl-pill-own">🏠 Our copy</span>' : '<span class="kcl-pill">KiddieTrac library · read-only</span>') + '</div>'
      + (copies.length ? '<div class="kcl-note" style="background:#F0FDFA;border-color:#99F6E4;color:#115E59;">Your agency already has ' + (copies.length === 1 ? 'a copy' : copies.length + ' copies') + ' of this unit: '
          + copies.map(function (c) { return '<a href="#curriculum?unit=' + c.id + '" data-open="' + c.id + '" style="color:#0F766E;font-weight:700;">' + esc(c.title) + '</a>'; }).join(', ') + '.</div>' : '')
      + '<div class="kcl-layout"><div class="kcl-col">'
      + '<div class="kcl-box"><h3>🧭 About this unit</h3><p style="margin:0 0 8px;font-size:13.5px;color:#334155;line-height:1.5;">' + esc(d.summary || u.summary || '') + '</p>'
      + ((d.big_questions || []).length ? '<div style="font-size:12px;font-weight:800;color:#475569;margin:8px 0 4px;">Questions to wonder about</div><ul>' + d.big_questions.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '')
      + '<div style="font-size:12px;font-weight:800;color:#475569;margin:10px 0 6px;">Activities by foundation</div><div class="kcl-cover">'
      + FKEYS.map(function (k) { return '<span class="kcl-f" style="--fc:' + FOUND[k].c + ';--fb:' + FOUND[k].bg + '">' + FOUND[k].i + ' ' + FOUND[k].l + ' · ' + cover[k] + '</span>'; }).join('') + '</div></div>'
      + list('Observation prompts', '👀', d.observe) + '</div>'
      + '<div class="kcl-box"><h3>🎯 Goals</h3>' + (d.goals || []).map(function (g) {
          return '<div class="kcl-goal"><div class="kcl-goal-t">' + esc(g.text) + '</div><div class="kcl-fs">' + (g.foundations || []).map(function (k) { return chip(k, true); }).join('') + '</div>'
            + ((g.continuum || []).length ? '<div class="kcl-cont">Continuum: ' + esc(g.continuum.join(' · ')) + '</div>' : '') + '</div>';
        }).join('') + '</div>'
      + '</div>'
      + '<div class="kcl-box kcl-week"><h3>🗓 Weekly plan</h3>' + weekTabs
      + (sched.focus ? '<p class="kcl-week-focus"><strong>Week ' + wk + ':</strong> ' + esc(sched.focus) + '</p>' : '')
      + '<div class="kcl-days">' + daysHtml + '</div>'
      + '<p style="font-size:11.5px;color:#64748B;margin:8px 0 0;">Tap an activity for its materials, steps and safety notes. Times are left for you to place in your day.</p></div>'
      + '<div class="kcl-more">'
      + list('Family engagement', '🏡', d.family)
      + list('Inclusion and additional needs', '🧩', d.inclusion)
      + ((fr.vocab || []).length || (fr.tips || []).length ? '<div class="kcl-box"><h3>🗣️ French and bilingual learners</h3>'
          + ((fr.vocab || []).length ? '<div class="kcl-vocab">' + fr.vocab.map(function (p) { return '<span>' + esc(p[0]) + ' <b lang="fr">' + esc(p[1]) + '</b></span>'; }).join('') + '</div>' : '')
          + ((fr.tips || []).length ? '<ul style="margin-top:10px;">' + fr.tips.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '') + '</div>' : '')
      + list('Safety', '🛡️', d.safety_notes)
      + '</div>'
      + '<p class="kcl-foot">Written for Ontario educators by KiddieTrac, organised around the four foundations of <em>How Does Learning Happen?</em> Adapt it to the children in front of you.</p>'
      + '</div>';

    main.querySelector('#kcl-back').onclick = function () { setHash('#curriculum'); render(main); };
    main.querySelectorAll('[data-wk]').forEach(function (b) { b.onclick = function () { preview(main, id, +b.getAttribute('data-wk')); }; });
    main.querySelectorAll('[data-open]').forEach(function (a) { a.onclick = function (e) { e.preventDefault(); preview(main, +a.getAttribute('data-open')); }; });
    main.querySelectorAll('[data-act]').forEach(function (b) { b.onclick = function () { openActivity(acts[b.getAttribute('data-act')]); }; });
    main.querySelector('#kcl-use').onclick = function () { openUse(main, u, d); };
    var cp = main.querySelector('#kcl-copy');
    if (cp) cp.onclick = async function () {
      cp.disabled = true;
      try {
        var res = await Api().post('/curriculum/units/' + id + '/copy', {});
        toast('⧉', 'Copied', '"' + u.title + '" is now in Our units. Edit it to fit your room.');
        st.loaded = false; await loadList();
        editUnit(main, res.id);
      } catch (e) { cp.disabled = false; toast('⚠️', 'Could not copy', (e && e.message) || '', '#B91C1C'); }
    };
    var ed = main.querySelector('#kcl-edit'); if (ed) ed.onclick = function () { editUnit(main, id); };
    var del = main.querySelector('#kcl-del');
    if (del) del.onclick = async function () {
      if (!(await ask('Delete this unit?', 'Delete your agency\'s copy "' + u.title + '"? Lesson plans already made from it stay as they are. The KiddieTrac library unit is not affected.', 'Delete', true))) return;
      try { await Api().delete('/curriculum/units/' + id); toast('🗑', 'Deleted', u.title); st.tab = 'own'; await loadList(); setHash('#curriculum'); drawList(main); }
      catch (e) { toast('⚠️', 'Could not delete', (e && e.message) || '', '#B91C1C'); }
    };
  }

  function openActivity(a) {
    if (!a) return;
    var body = '<div style="font-size:13.5px;color:#334155;line-height:1.5;">'
      + '<div class="kcl-fs" style="margin:0 0 10px;">' + (a.foundations || []).map(function (k) { return chip(k); }).join('')
      + (a.domain ? '<span class="kcl-pill">' + esc(DOMAINS[a.domain] || a.domain) + '</span>' : '') + '</div>'
      + ((a.materials || []).length ? '<div style="font-weight:800;font-size:12.5px;color:#475569;margin:0 0 4px;">Materials</div><ul style="margin:0 0 10px;padding-left:18px;">' + a.materials.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '')
      + ((a.steps || []).length ? '<div style="font-weight:800;font-size:12.5px;color:#475569;margin:0 0 4px;">Steps</div><ol style="margin:0 0 10px;padding-left:20px;">' + a.steps.map(function (x) { return '<li style="margin:0 0 5px;">' + esc(x) + '</li>'; }).join('') + '</ol>' : '')
      + (a.safety ? '<div class="kcl-note">🛡 ' + esc(a.safety) + '</div>' : '')
      + '</div>';
    modal({ title: a.title, body: body, actions: [{ label: 'Close' }] });
  }

  // ════════════════════════════════ use this unit ════════════════════════════════
  async function targets() {
    var r = role(), rooms = [], centres = [], seen = {};
    try {
      if (r === 'agency_admin' || r === 'platform_admin') {
        var res = await Api().get('/admin/centres');
        (res.centres || []).forEach(function (c) {
          centres.push({ id: c.id, name: c.name });
          (c.rooms || []).forEach(function (rm) { rooms.push({ id: rm.id, name: rm.name, centre_id: c.id, centre_name: c.name }); });
        });
      }
      if (!rooms.length && !centres.length) {
        var b = await Api().get('/provider/bootstrap');
        (b.rooms || []).forEach(function (rm) {
          rooms.push({ id: rm.id, name: rm.name, centre_id: rm.centre_id, centre_name: rm.centre_name });
          if (rm.centre_id && !seen[rm.centre_id]) { seen[rm.centre_id] = 1; centres.push({ id: rm.centre_id, name: rm.centre_name || ('Centre ' + rm.centre_id) }); }
        });
      }
    } catch (e) {}
    return { rooms: rooms, centres: centres };
  }

  async function openUse(main, u, d) {
    var t = await targets();
    if (!t.rooms.length && !t.centres.length) { toast('⚠️', 'No rooms', 'No rooms or centres were found for you to plan for.', '#B91C1C'); return; }
    var many = t.centres.length > 1;
    var opts = '<option value="" selected disabled>Select a room…</option>'
      + t.rooms.map(function (r) { return '<option value="room:' + r.id + '">' + esc((many && r.centre_name ? r.centre_name + ' · ' : '') + r.name) + '</option>'; }).join('')
      + t.centres.map(function (c) { return '<option value="centre:' + c.id + '">🏫 ' + esc(c.name) + ' (whole centre)</option>'; }).join('');
    var weeks = (d.schedule || []).map(function (s) { return s.week; });
    var box = el('<div class="kcl-f-dlg">'
      + '<p style="margin:0 0 4px;font-size:13px;color:#475569;line-height:1.45;">Makes one <strong>draft</strong> weekly lesson plan per unit week, starting from the week you choose. Drafts are for staff only: families see a week once someone publishes it in Lesson plans.</p>'
      + '<label for="kcl-u-t">Room or centre</label><select id="kcl-u-t">' + opts + '</select>'
      + '<label for="kcl-u-w">Start the week of (Monday)</label><input id="kcl-u-w" type="date" value="' + nextMondayISO() + '">'
      + (weeks.length > 1 ? '<label>Which weeks</label><div class="kcl-wks">' + weeks.map(function (n) { return '<label><input type="checkbox" data-w="' + n + '" checked> Week ' + n + '</label>'; }).join('') + '</div>' : '')
      + '<div id="kcl-u-sum" style="font-size:12px;color:#64748B;margin-top:10px;"></div>'
      + '<div id="kcl-u-msg" style="font-size:12.5px;color:#B91C1C;margin-top:8px;min-height:16px;"></div></div>');
    var sum = box.querySelector('#kcl-u-sum'), wIn = box.querySelector('#kcl-u-w');
    var paint = function () {
      var sel = Array.prototype.map.call(box.querySelectorAll('[data-w]:checked'), function (c) { return +c.getAttribute('data-w'); });
      if (!weeks.length || weeks.length === 1) sel = weeks.length ? weeks : [1];
      var m0 = mondayISO(wIn.value), p = m0.split('-');
      var last = new Date(+p[0], +p[1] - 1, +p[2] + 7 * Math.max(0, sel.length - 1));
      sum.textContent = sel.length ? (sel.length + ' week' + (sel.length === 1 ? '' : 's') + ': ' + fmtWeek(m0) + (sel.length > 1 ? ' to the week of ' + fmtWeek(ymd(last)) : '') + '. Weeks that already have activities are left as they are.') : 'Choose at least one week.';
      return sel;
    };
    box.addEventListener('change', paint); wIn.addEventListener('input', paint); paint();
    modal({ title: '📅 Use “' + u.title + '”', body: box, actions: [
      { label: 'Cancel' },
      { label: 'Make draft plans', primary: true, busyLabel: 'Making drafts…', handler: async function () {
        var msg = box.querySelector('#kcl-u-msg'); msg.textContent = '';
        var tv = box.querySelector('#kcl-u-t').value;
        if (!tv) { msg.textContent = 'Select a room or centre first.'; return false; }
        var sel = paint(); if (!sel.length) { msg.textContent = 'Choose at least one week.'; return false; }
        var body = { week_starting: mondayISO(wIn.value), weeks: sel };
        var isC = tv.indexOf('centre:') === 0, tid = +tv.split(':')[1];
        if (isC) body.centre_id = tid; else body.room_id = tid;
        try {
          var res = await Api().post('/curriculum/units/' + u.id + '/use', body);
          setTimeout(function () { showResult(u, res, isC, tid); }, 30);
          return true;
        } catch (e) { msg.textContent = (e && e.message) || 'Could not make the plans.'; return false; }
      } },
    ] });
  }

  function showResult(u, res, isC, tid) {
    var created = res.created || [], skipped = res.skipped || [];
    var box = el('<div class="kcl-res" style="font-size:13.5px;color:#334155;">'
      + (created.length ? '<p style="margin:0 0 6px;"><strong>' + created.length + ' draft week' + (created.length === 1 ? '' : 's') + ' ready</strong> (staff only until published):</p><ul style="margin:0 0 10px;padding-left:18px;">'
        + created.map(function (c) { return '<li>Week of ' + esc(fmtWeek(c.week_starting)) + ' · unit week ' + c.unit_week + ' · ' + c.activities + ' activities</li>'; }).join('') + '</ul>' : '<p style="margin:0 0 8px;">No new weeks were made.</p>')
      + (skipped.length ? '<p style="margin:0 0 6px;color:#92400E;"><strong>Left as they were:</strong></p><ul style="margin:0 0 10px;padding-left:18px;color:#92400E;">'
        + skipped.map(function (s) { return '<li>Week of ' + esc(fmtWeek(s.week_starting)) + ': ' + esc(s.reason) + '</li>'; }).join('') + '</ul>' : '')
      + '<div id="kcl-pics" style="font-size:12px;color:#64748B;"></div></div>');
    var first = created[0] || skipped[0];
    modal({ title: '✓ Draft lesson plans', body: box, actions: [
      { label: 'Stay here' },
      { label: 'Open lesson plans', primary: true, handler: function () {
        try { if (KT.LessonPlans && KT.LessonPlans.focus && first) KT.LessonPlans.focus({ scope: isC ? 'centre' : 'room', centreId: isC ? tid : undefined, roomId: isC ? undefined : tid, week: first.week_starting }); } catch (e) {}
        setTimeout(function () { location.hash = '#lesson-plans'; }, 20);
        return true;
      } },
    ] });
    // Pictures: the lesson-picture system fills them, only where the agency has "Add pictures automatically" on.
    if (created.length && res.pictures && res.pictures.auto) fillPictures(created, isC, tid, box.querySelector('#kcl-pics'));
  }

  async function fillPictures(created, isC, tid, note) {
    var filled = 0;
    for (var i = 0; i < created.length; i++) {
      for (var round = 0; round < 3; round++) {
        if (note && note.isConnected) note.textContent = '🖼 Adding pictures to week ' + (i + 1) + ' of ' + created.length + '…';
        var body = { week_starting: created[i].week_starting }; if (isC) body.centre_id = tid; else body.room_id = tid;
        var r = null;
        try { r = await Api().post('/provider/lesson-plans/fill-images', body); } catch (e) { r = null; }
        if (!r) break;
        filled += r.filled || 0;
        if (r.done || !(r.left > 0) || !(r.filled > 0)) break;
      }
    }
    if (note && note.isConnected) note.textContent = filled ? '🖼 Added ' + filled + ' picture' + (filled === 1 ? '' : 's') + ' (openly licensed, credited). You can change any of them in the planner.' : '';
  }

  // ════════════════════════════════ edit our copy ════════════════════════════════
  async function editUnit(main, id) {
    css();
    var r;
    try { r = await Api().get('/curriculum/units/' + id); } catch (e) { toast('⚠️', 'Could not open', (e && e.message) || '', '#B91C1C'); return; }
    if (r.unit.source !== 'agency') { preview(main, id); return; }
    setHash('#curriculum?unit=' + id);
    var d = JSON.parse(JSON.stringify(r.unit.data || {}));
    d.activities = d.activities || []; d.schedule = d.schedule || [];
    var lines = function (a) { return esc((a || []).join('\n')); };
    var slots = function (aid) {
      var out = [];
      d.schedule.forEach(function (s) { DAYS.forEach(function (dk) { if (((s.days || {})[dk] || []).indexOf(aid) !== -1) out.push('Week ' + s.week + ' ' + DAY_L[dk]); }); });
      return out;
    };
    var actHtml = function (a, i) {
      return '<div class="kcl-ed-act" data-i="' + i + '">'
        + '<div class="kcl-ed-row"><div><label>Activity</label><input data-k="title" value="' + esc(a.title) + '" maxlength="80"></div>'
        + '<div><label>Domain</label><select data-k="domain"><option value="">—</option>' + Object.keys(DOMAINS).map(function (k) { return '<option value="' + k + '"' + (a.domain === k ? ' selected' : '') + '>' + DOMAINS[k] + '</option>'; }).join('') + '</select></div></div>'
        + '<label>Foundations</label><div class="kcl-ed-fs">' + FKEYS.map(function (k) { return '<label><input type="checkbox" data-f="' + k + '"' + ((a.foundations || []).indexOf(k) !== -1 ? ' checked' : '') + '> ' + FOUND[k].i + ' ' + FOUND[k].l + '</label>'; }).join('') + '</div>'
        + '<div class="kcl-ed-row"><div><label>Steps (one per line)</label><textarea data-k="steps" rows="4">' + lines(a.steps) + '</textarea></div>'
        + '<div><label>Materials (one per line)</label><textarea data-k="materials" rows="4">' + lines(a.materials) + '</textarea></div></div>'
        + '<label>Safety note</label><input data-k="safety" value="' + esc(a.safety || '') + '" maxlength="300">'
        + '<div style="display:flex;align-items:center;gap:10px;margin-top:8px;font-size:11.5px;color:#64748B;"><span style="flex:1;">' + (slots(a.id).length ? 'Planned on: ' + esc(slots(a.id).join(', ')) : '<span style="color:#92400E;">Not placed on any day yet.</span>') + '</span>'
        + '<button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" data-place="' + i + '">📌 Place on a day</button><button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn kcl-btn-d" data-rm="' + i + '">Remove</button></div></div>';
    };
    var draw = function () {
      main.innerHTML = '<div class="kcl kcl-f-dlg">'
        + '<div class="kcl-top"><button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" id="kcl-ed-back">← Back</button><h2>✏️ Edit our copy</h2>'
        + '<div class="kcl-acts"><button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn kcl-btn-p" id="kcl-ed-save">💾 Save unit</button></div></div>'
        + '<div class="kcl-layout"><div>'
        + '<div class="kcl-box"><h3>About</h3><label>Title</label><input id="e-title" value="' + esc(d.title || '') + '" maxlength="120">'
        + '<label>Summary</label><textarea id="e-sum" rows="3">' + esc(d.summary || '') + '</textarea>'
        + '<div class="kcl-ed-row"><div><label>Theme</label><input id="e-theme" value="' + esc(d.theme || '') + '" maxlength="60"></div>'
        + '<div><label>Season</label><select id="e-season">' + Object.keys(SEASONS).map(function (k) { return '<option value="' + k + '"' + (d.season === k ? ' selected' : '') + '>' + SEASONS[k] + '</option>'; }).join('') + '</select></div></div>'
        + '<label>Questions to wonder about (one per line)</label><textarea id="e-bq" rows="2">' + lines(d.big_questions) + '</textarea></div>'
        + '<div class="kcl-box"><h3>Activities (' + d.activities.length + ')</h3>' + d.activities.map(actHtml).join('')
        + '<button type="button" data-kt-inpage data-kt-iconized="1" class="kcl-btn" id="kcl-ed-add">+ Add an activity</button></div>'
        + '</div><div>'
        + '<div class="kcl-box"><h3>Goals</h3><label>One goal per line (foundations stay with each line)</label><textarea id="e-goals" rows="6">' + esc((d.goals || []).map(function (g) { return g.text; }).join('\n')) + '</textarea></div>'
        + '<div class="kcl-box"><h3>Observation prompts</h3><textarea id="e-obs" rows="5">' + lines(d.observe) + '</textarea></div>'
        + '<div class="kcl-box"><h3>Family engagement</h3><textarea id="e-fam" rows="4">' + lines(d.family) + '</textarea></div>'
        + '<div class="kcl-box"><h3>Inclusion and additional needs</h3><textarea id="e-inc" rows="4">' + lines(d.inclusion) + '</textarea></div>'
        + '<div class="kcl-box"><h3>French vocabulary</h3><label>One per line: english = français</label><textarea id="e-fr" rows="6">' + esc(((d.french || {}).vocab || []).map(function (p) { return p[0] + ' = ' + p[1]; }).join('\n')) + '</textarea>'
        + '<label>Tips (one per line)</label><textarea id="e-frt" rows="3">' + lines((d.french || {}).tips) + '</textarea></div>'
        + '<div class="kcl-box"><h3>Safety notes</h3><textarea id="e-safe" rows="3">' + lines(d.safety_notes) + '</textarea></div>'
        + '</div></div></div>';
      main.querySelector('#kcl-ed-back').onclick = function () { preview(main, id); };
      main.querySelector('#kcl-ed-save').onclick = save;
      main.querySelector('#kcl-ed-add').onclick = function () {
        collect();
        var n = 1; while (d.activities.some(function (a) { return a.id === 'c' + n; })) n++;
        d.activities.push({ id: 'c' + n, title: 'New activity', domain: '', foundations: [], materials: [], steps: [], safety: '', time_of_day: 'any' });
        draw();
      };
      main.querySelectorAll('[data-rm]').forEach(function (b) {
        b.onclick = function () {
          collect();
          var a = d.activities[+b.getAttribute('data-rm')]; if (!a) return;
          d.activities.splice(+b.getAttribute('data-rm'), 1);
          d.schedule.forEach(function (s) { DAYS.forEach(function (dk) { s.days[dk] = (s.days[dk] || []).filter(function (x) { return x !== a.id; }); }); });
          draw();
        };
      });
      main.querySelectorAll('[data-place]').forEach(function (b) {
        b.onclick = function () { collect(); place(d.activities[+b.getAttribute('data-place')]); };
      });
    };
    var place = function (a) {
      if (!a) return;
      var box = el('<div class="kcl-f-dlg"><p style="margin:0;font-size:13px;color:#475569;">Tick each day that should include <strong>' + esc(a.title) + '</strong>.</p>'
        + d.schedule.map(function (s) {
          return '<label style="margin-top:12px;">Week ' + s.week + '</label><div class="kcl-wks">' + DAYS.map(function (dk) {
            return '<label><input type="checkbox" data-s="' + s.week + ':' + dk + '"' + (((s.days || {})[dk] || []).indexOf(a.id) !== -1 ? ' checked' : '') + '> ' + DAY_L[dk] + '</label>';
          }).join('') + '</div>';
        }).join('') + '</div>');
      modal({ title: '📌 Place on days', body: box, actions: [{ label: 'Cancel' }, { label: 'Done', primary: true, handler: function () {
        box.querySelectorAll('[data-s]').forEach(function (c) {
          var p = c.getAttribute('data-s').split(':'), s = d.schedule.filter(function (x) { return x.week === +p[0]; })[0]; if (!s) return;
          s.days[p[1]] = (s.days[p[1]] || []).filter(function (x) { return x !== a.id; });
          if (c.checked) s.days[p[1]].push(a.id);
        });
        setTimeout(draw, 0);
        return true;
      } }] });
    };
    var split = function (sel) { var t = main.querySelector(sel); return t ? t.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean) : []; };
    var collect = function () {
      if (!main.querySelector('#e-title')) return;
      d.title = main.querySelector('#e-title').value.trim();
      d.summary = main.querySelector('#e-sum').value.trim();
      d.theme = main.querySelector('#e-theme').value.trim();
      d.season = main.querySelector('#e-season').value;
      d.big_questions = split('#e-bq');
      var gl = split('#e-goals'), old = d.goals || [];
      d.goals = gl.map(function (t, i) { return { text: t, foundations: (old[i] || {}).foundations || [], continuum: (old[i] || {}).continuum || [] }; });
      d.observe = split('#e-obs'); d.family = split('#e-fam'); d.inclusion = split('#e-inc'); d.safety_notes = split('#e-safe');
      d.french = { vocab: split('#e-fr').map(function (l) { var p = l.split('='); return [String(p[0] || '').trim(), String(p.slice(1).join('=') || '').trim()]; }).filter(function (p) { return p[0] && p[1]; }), tips: split('#e-frt') };
      main.querySelectorAll('.kcl-ed-act').forEach(function (box) {
        var a = d.activities[+box.getAttribute('data-i')]; if (!a) return;
        a.title = box.querySelector('[data-k="title"]').value.trim();
        a.domain = box.querySelector('[data-k="domain"]').value || null;
        a.steps = box.querySelector('[data-k="steps"]').value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
        a.materials = box.querySelector('[data-k="materials"]').value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
        a.safety = box.querySelector('[data-k="safety"]').value.trim();
        a.foundations = Array.prototype.map.call(box.querySelectorAll('[data-f]:checked'), function (c) { return c.getAttribute('data-f'); });
      });
    };
    var save = async function () {
      collect();
      var btn = main.querySelector('#kcl-ed-save');
      if (!d.title) { toast('⚠️', 'Title needed', 'Give the unit a title.', '#B91C1C'); return; }
      d.activities = d.activities.filter(function (a) { return a.title; });
      btn.disabled = true; btn.textContent = 'Saving…';
      try {
        await Api().put('/curriculum/units/' + id, { unit: d });
        toast('💾', 'Saved', d.title);
        await loadList();
        preview(main, id);
      } catch (e) { btn.disabled = false; btn.textContent = '💾 Save unit'; toast('⚠️', 'Could not save', (e && e.message) || '', '#B91C1C'); }
    };
    draw();
  }

  KT.CurriculumLibrary = { render: render, preview: preview };
})(window);
