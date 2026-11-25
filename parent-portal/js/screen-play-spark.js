/* ============================================================
   PLAY SPARK (2026-11-24) - on-the-spot play ideas.
   Staff: #play-spark. Parents: #play-spark (Play at home).
   Backend: PlaySparkController + App\Support\PlaySpark. Nothing here sends anything to families except
   "We did it", which logs an Activity to the daily record (the family feed shows it as usual).

   LESSON PLANS MERGED IN (2026-11-24, Anthony: "lesson plan and play spark should be merged together - it's the
   same"). One screen, tabs (deep link #play-spark?tab=now|week|plan|library|report, &lib= for the library):
     ✨ Right now       today's spark                                   AI Bundle
     📅 This week       the weekly lesson plan (KT.LessonPlans, what families see)   lesson plans on; staff
     🗓️ Plan the week   AI-drafted weeks (KT.LessonPlansAi)              AI Bundle; directors + admins
     📚 Library         sparks (AI) + 💡 Activity ideas templates (KT.V22p57)        directors + admins for ideas
     📊 Monthly report  Play Spark activities by room                   AI Bundle
   Called Play Spark for everyone. Without the AI Bundle the AI tabs are simply not there (no upsell) - the
   server still refuses every AI endpoint (PlaySparkController::gate, AiLessonPlanController). The old
   #lesson-plans (staff) and #lesson-plans-ai redirect here; parents keep their own #lesson-plans view.
   ============================================================ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;
  var MARK = '/img/play-spark.svg?v=2';

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function toast(msg, kind) { if (Dom && Dom.toast) Dom.toast(msg, kind || 'success'); }
  function errMsg(e) { return (e && e.data && e.data.message) || (e && e.message) || 'Something went wrong. Please try again.'; }

  var CSS = '' +
    '.ps-wrap{padding:16px 20px 40px;}' +
    '.ps-hero{display:flex;align-items:center;gap:12px;margin-bottom:12px;}' +
    '.ps-hero img{width:44px;height:44px;border-radius:24%;flex:none;}' +
    '.ps-hero .ey{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#3D8BFF;background:linear-gradient(90deg,#FF5A4E,#E8A400 35%,#3D8BFF 68%,#22B37C);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;display:inline-block;}' +
    '.ps-hero h1{margin:0;font-size:22px;line-height:1.2;}' +
    '.ps-hero .sub{font-size:13px;color:var(--kt-text-muted);}' +
    '.ps-tabs{display:flex;gap:6px;margin:0 0 14px;flex-wrap:wrap;}' +
    '.ps-tab{height:30px;padding:0 12px;border-radius:15px;border:1px solid var(--kt-border);background:var(--kt-surface);font-weight:600;font-size:13px;cursor:pointer;color:inherit;}' +
    '.ps-tab.on{background:#0A1E55;border-color:#0A1E55;color:#fff;}' +
    '.ps-grid{display:grid;grid-template-columns:minmax(280px,380px) 1fr;gap:16px;align-items:start;}' +
    '@media (max-width:900px){.ps-grid{grid-template-columns:1fr;}}' +
    '.ps-card{background:var(--kt-surface);border:1px solid var(--kt-border);border-radius:14px;padding:14px;}' +
    '.ps-lab{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--kt-text-faint);margin:12px 0 6px;}' +
    '.ps-lab:first-child{margin-top:0;}' +
    '.ps-chips{display:flex;flex-wrap:wrap;gap:6px;}' +
    '.ps-chip{height:28px;padding:0 10px;border-radius:14px;border:1px solid var(--kt-border);background:var(--kt-bg);font-size:12.5px;cursor:pointer;color:inherit;display:inline-flex;align-items:center;gap:6px;}' +
    '.ps-chip.on{background:rgba(29,185,195,.14);border-color:#1DB9C3;color:var(--kt-text);font-weight:700;}' +
    '.ps-chip .x{opacity:.5;font-size:11px;}' +
    '.ps-ctx{font-size:12.5px;color:var(--kt-text-muted);line-height:1.5;}' +
    '.ps-ctx b{color:var(--kt-text);}' +
    '.ps-row{display:flex;gap:6px;align-items:center;}' +
    '.ps-row input,.ps-row select,.ps-in{height:30px;border:1px solid var(--kt-border);border-radius:8px;padding:0 8px;background:var(--kt-surface);color:inherit;font:inherit;font-size:13px;min-width:0;}' +
    '.ps-go{width:100%;height:38px;margin-top:14px;border:0;border-radius:10px;background:linear-gradient(135deg,#12307A,#0A1E55);color:#fff;font-weight:800;font-size:14px;cursor:pointer;}' +
    '.ps-go:disabled{opacity:.6;cursor:wait;}' +
    '.ps-idea h2{margin:0 0 4px;font-size:20px;}' +
    '.ps-meta{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 10px;}' +
    '.ps-pill{font-size:11.5px;font-weight:700;padding:3px 8px;border-radius:10px;background:var(--kt-bg);border:1px solid var(--kt-border);}' +
    '.ps-pill.area{background:rgba(29,185,195,.12);border-color:rgba(29,185,195,.5);}' +
    '.ps-safe{border-radius:12px;padding:10px 12px;margin:10px 0;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.45);}' +
    '.ps-safe.caution{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.6);}' +
    '.ps-safe h4{margin:0 0 6px;font-size:13px;}' +
    '.ps-safe ul{margin:0 0 8px 18px;padding:0;font-size:13px;}' +
    '.ps-safe label{display:flex;gap:8px;align-items:center;font-weight:700;font-size:13px;cursor:pointer;}' +
    '.ps-sec{margin-top:12px;}' +
    '.ps-sec h4{margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--kt-text-faint);}' +
    '.ps-sec ol,.ps-sec ul{margin:0 0 0 18px;padding:0;font-size:13.5px;line-height:1.5;}' +
    '.ps-tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;}' +
    '.ps-tier{border:1px solid var(--kt-border);border-radius:12px;padding:10px 12px;background:var(--kt-bg);}' +
    '.ps-tier .b{font-size:11px;font-weight:800;text-transform:uppercase;color:#1DB9C3;}' +
    '.ps-tier .t{font-weight:800;margin:2px 0 6px;}' +
    '.ps-tier .s{font-size:12.5px;color:var(--kt-text-muted);margin-top:6px;}' +
    '.ps-home{margin-top:12px;padding:10px 12px;border-radius:12px;background:rgba(10,30,85,.05);border:1px dashed var(--kt-border);font-size:13.5px;}' +
    '.ps-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px;}' +
    '.ps-btn{height:32px;padding:0 12px;border-radius:9px;border:1px solid var(--kt-border);background:var(--kt-surface);font-weight:700;font-size:13px;cursor:pointer;color:inherit;}' +
    '.ps-btn.pri{background:#1DB9C3;border-color:#1DB9C3;color:#04252a;}' +
    '.ps-btn:disabled{opacity:.5;cursor:not-allowed;}' +
    '.ps-empty{padding:30px;text-align:center;color:var(--kt-text-muted);}' +
    '.ps-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;}' +
    '.ps-li{cursor:pointer;}' +
    '.ps-li:hover{border-color:#1DB9C3;}' +
    '.ps-bar{height:8px;border-radius:4px;background:#1DB9C3;}' +
    '.ps-kids{display:flex;flex-wrap:wrap;gap:6px;max-height:46vh;overflow:auto;}' +
    '.ps-load{display:flex;align-items:center;gap:10px;padding:30px;justify-content:center;color:var(--kt-text-muted);}';

  function addCss() {
    if (document.getElementById('ps-css')) return;
    var s = document.createElement('style'); s.id = 'ps-css'; s.textContent = CSS; document.head.appendChild(s);
  }

  function hero(sub) {
    return '<div class="ps-hero"><img src="' + MARK + '" alt=""><div><div class="ey">Play Spark</div>' +
      '<h1>' + esc(sub.title) + '</h1><div class="sub">' + esc(sub.sub) + '</div></div></div>';
  }

  function bundleOff(main, e) {
    var m = errMsg(e);
    main.querySelector('[data-ps-body]').innerHTML = '<div class="ps-card ps-empty">' + esc(m) + '</div>';
  }

  /* ── staff ──────────────────────────────────────────────────────────── */
  var S = { tab: '', ctx: null, rooms: [], children: [], roomId: 0, sel: {}, bands: {}, setting: 'either', minutes: 20,
    involvement: 'guided', theme: '', idea: null, ack: false, busy: false, libScope: '', month: '' };

  var WEEK_ROLES = ['agency_admin', 'centre_director', 'educator', 'platform_admin'];   // who plans the week today (legacy allStaff + superadmin)
  var MANAGE_ROLES = ['agency_admin', 'centre_director', 'platform_admin'];          // AI week drafts + activity-idea templates
  function me() { try { return (KT.Auth && KT.Auth.user && KT.Auth.user()) || {}; } catch (e) { return {}; } }
  function myRole() {
    var u = me();
    try { if (Shell && Shell.Roles && Shell.Roles.primaryRoleOf) return Shell.Roles.primaryRoleOf(u) || ''; } catch (e) {}
    return u.primary_role || ((u.roles || [])[0]) || '';
  }
  /* The same answer the server gives (AuthController ai_bundle = AiBundle::on), so a tab is shown exactly when its
     endpoints answer. hidden_screens carries the lesson_plans switch. */
  function aiOn() { return me().ai_bundle === true; }
  function lpOn() { var h = me().hidden_screens; return !(Array.isArray(h) && h.indexOf('lesson-plans') !== -1); }
  function tabsFor() {
    var r = myRole(), ai = aiOn(), lp = lpOn(), canWeek = lp && WEEK_ROLES.indexOf(r) !== -1, manage = MANAGE_ROLES.indexOf(r) !== -1, t = [];
    if (ai) t.push(['now', '✨ Right now']);
    if (canWeek) t.push(['week', '📅 This week']);
    if (ai && manage) t.push(['plan', '🗓️ Plan the week']);
    if (ai || (lp && manage)) t.push(['library', '📚 Library']);
    if (ai) t.push(['report', '📊 Monthly report']);
    return t;
  }
  function libScopes() {
    var s = [];
    if (aiOn()) s.push(['mine', 'My ideas'], ['favourites', '★ Favourites'], ['shared', '📚 Agency library']);
    if (lpOn() && MANAGE_ROLES.indexOf(myRole()) !== -1) s.push(['ideas', '💡 Activity ideas']);
    return s;
  }
  function hashQuery() {
    var q = {}, s = String(location.hash || '').split('?')[1] || '';
    s.split('&').forEach(function (p) { var kv = p.split('='); if (kv[0]) q[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || ''); });
    return q;
  }
  /* Keep the address on the tab you are on (a refresh or a shared link lands there), without a hashchange. */
  function setHash() {
    var h = '#play-spark?tab=' + S.tab + (S.tab === 'library' && S.libScope ? '&lib=' + S.libScope : '');
    try { if (location.hash !== h) history.replaceState(history.state, '', location.pathname + location.search + h); } catch (e) {}
  }
  function needsHere(main, what) {
    var el = main.querySelector('[data-ps-body]');
    if (el) el.innerHTML = '<div class="ps-card ps-empty">' + esc(what) + ' could not open. Refresh the page and try again.</div>';
  }

  async function renderStaff(main) {
    addCss();
    Dom.clear(main);
    var tabs = tabsFor(), has = function (k) { return tabs.some(function (t) { return t[0] === k; }); };
    var q = hashQuery();
    if (q.tab === 'new') q.tab = 'now';
    if (q.tab && has(q.tab)) S.tab = q.tab;
    if (q.lib) S.libScope = q.lib;
    if (!has(S.tab)) S.tab = tabs.length ? tabs[0][0] : '';
    var ai = aiOn();
    var w = document.createElement('div'); w.className = 'ps-wrap'; w.setAttribute('data-kt-noband', '1'); w.setAttribute('data-kt-nosechead', '1'); main.appendChild(w);   // own chips, not tab bands
    w.innerHTML = hero(ai
        ? { title: 'Play ideas and weekly plans', sub: 'One tap. A fresh idea for every age in the room, and the week families see.' }
        : { title: 'Weekly plans and activity ideas', sub: 'Plan the week for every room, and share it with families.' }) +
      (tabs.length > 1 ? '<div class="ps-tabs" role="tablist" data-kt-tabbar="1">' + tabs.map(function (t) {   // the standard band, inside the no-band wrapper (kt-tab-band opt-in, 2026-11-24)
        return '<button type="button" role="tab" aria-selected="' + (S.tab === t[0]) + '" class="ps-tab' + (S.tab === t[0] ? ' on' : '') + '" data-ps-tab="' + t[0] + '">' + t[1] + '</button>';
      }).join('') + '</div>' : '') + '<div data-ps-body></div>';
    w.querySelectorAll('[data-ps-tab]').forEach(function (b) {
      b.addEventListener('click', function () { S.tab = b.getAttribute('data-ps-tab'); setHash(); renderStaff(main); });
    });
    var body = w.querySelector('[data-ps-body]');
    if (!S.tab) { body.innerHTML = '<div class="ps-card ps-empty">Nothing here is switched on for your agency.</div>'; return; }
    setHash();
    if (S.tab === 'week') {
      if (!(KT.LessonPlans && KT.LessonPlans.render)) return needsHere(w, 'The weekly plan');
      return KT.LessonPlans.render(body);
    }
    if (S.tab === 'plan') {
      if (!(KT.LessonPlansAi && KT.LessonPlansAi.list)) return needsHere(w, 'Plan the week');
      return KT.LessonPlansAi.list(body, { inTab: true });
    }
    if (S.tab === 'library' && !ai) return renderLibrary(body, main);   // activity ideas only - no Play Spark context to load
    body.innerHTML = '<div class="ps-load"><div class="spinner"></div></div>';
    try {
      if (!S.ctx || S.tab === 'now') await loadCtx(S.roomId);
    } catch (e) { return bundleOff(w, e); }
    if (S.tab === 'library') return renderLibrary(body, main);
    if (S.tab === 'report') return renderReport(body, main);
    renderNew(body, main);
  }

  async function loadCtx(roomId) {
    var r = await Api.get('/provider/play-spark/context' + (roomId ? '?room_id=' + roomId : ''));
    S.rooms = r.rooms || []; S.ctx = r.context; S.children = r.children || [];
    var newRoom = S.ctx ? S.ctx.room.id : 0;
    if (newRoom !== S.roomId) {
      S.roomId = newRoom; S.sel = {}; S.bands = {};
      ((S.ctx && S.ctx.materials) || []).forEach(function (m) { S.sel[m.name] = true; });
      var b = (S.ctx && S.ctx.bands) || {};
      Object.keys(b).forEach(function (k) { if (b[k] > 0) S.bands[k] = b[k]; });
      if (!Object.keys(S.bands).length) S.bands = { preschool: 0 };
    }
  }

  var BAND_SHORT = { infant: 'Infants', toddler: 'Toddlers', preschool: 'Preschool', school_age: 'School-age' };

  function renderNew(body, main) {
    var c = S.ctx;
    if (!c) { body.innerHTML = '<div class="ps-card ps-empty">You are not placed in a room yet. Ask your director to add you to one.</div>'; return; }
    var who = Object.keys(c.bands).filter(function (k) { return c.bands[k] > 0; })
      .map(function (k) { return c.bands[k] + ' ' + BAND_SHORT[k].toLowerCase(); }).join(', ');
    var gaps = (c.gaps || []).map(function (g) { return g.area ? g.area + ' (' + g.label + ')' : g.label; }).join(', ');
    body.innerHTML = '<div class="ps-grid"><div class="ps-card" data-ps-form>' +
      '<div class="ps-lab">Room</div><div class="ps-row"><select data-ps-room style="flex:1">' +
      S.rooms.map(function (r) { return '<option value="' + r.id + '"' + (r.id === c.room.id ? ' selected' : '') + '>' + esc(r.name) + (r.centre ? ' · ' + esc(r.centre) : '') + '</option>'; }).join('') +
      '</select></div>' +
      '<div class="ps-ctx" style="margin-top:8px">' + (c.present ? '<b>' + c.present + ' signed in now:</b> ' + esc(who) : 'No one is signed in yet. Pick the ages below.') +
      (c.theme ? '<br><b>This week:</b> ' + esc(c.theme) : '') +
      (gaps ? '<br><b>Needs more lately:</b> ' + esc(gaps) + ' <span style="opacity:.7">(' + esc(c.framework.short) + ')</span>' : '') + '</div>' +
      '<div class="ps-lab">Ages</div><div class="ps-chips">' +
      Object.keys(BAND_SHORT).map(function (k) {
        return '<button type="button" class="ps-chip' + (k in S.bands ? ' on' : '') + '" data-ps-band="' + k + '">' + BAND_SHORT[k] + (c.bands[k] ? ' · ' + c.bands[k] : '') + '</button>';
      }).join('') + '</div>' +
      seg('Where', 'setting', [['indoor', '🏠 Indoor'], ['outdoor', '🌳 Outdoor'], ['either', 'Either']]) +
      seg('Time', 'minutes', [[10, '10 min'], [20, '20 min'], [30, '30 min'], [45, '45 min']]) +
      seg('Your involvement', 'involvement', [['low', 'Children lead'], ['guided', 'Guided'], ['hands_on', 'Hands-on']]) +
      '<div class="ps-lab">Materials on hand</div><div class="ps-chips" data-ps-mats>' + matChips() + '</div>' +
      '<div class="ps-row" style="margin-top:8px"><input class="ps-in" data-ps-newmat placeholder="Add a material…" style="flex:1" maxlength="60">' +
      '<button type="button" class="ps-btn" data-ps-addmat>Add</button>' +
      '<button type="button" class="ps-btn" data-ps-scan data-kttip="Snap the materials - no people in the photo. The photo is not kept.">📷 Scan</button>' +
      '<input type="file" accept="image/*" capture="environment" data-ps-file hidden></div>' +
      '<div class="ps-lab">Theme (optional)</div><input class="ps-in" data-ps-theme style="width:100%" maxlength="120" placeholder="' + esc(c.theme || 'e.g. dinosaurs, the water cycle, counting') + '" value="' + esc(S.theme) + '">' +
      '<div data-ps-today></div>' +
      '<button type="button" class="ps-go" data-ps-go>✨ Spark an idea</button>' +
      '</div><div data-ps-out>' + (S.idea ? '' : '<div class="ps-card ps-empty"><img src="' + MARK + '" alt="" style="width:56px;height:56px;border-radius:24%;opacity:.9"><div style="margin-top:10px">Pick the ages and what you have, then tap <b>Spark an idea</b>.</div></div>') + '</div></div>';

    var form = body.querySelector('[data-ps-form]');
    form.querySelector('[data-ps-room]').addEventListener('change', async function (ev) {
      S.idea = null; try { await loadCtx(+ev.target.value); } catch (e) { toast(errMsg(e), 'error'); } renderNew(body, main);
    });
    form.querySelectorAll('[data-ps-band]').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-ps-band');
        if (k in S.bands) { if (Object.keys(S.bands).length > 1) delete S.bands[k]; } else S.bands[k] = c.bands[k] || 0;
        b.classList.toggle('on', k in S.bands);
      });
    });
    form.querySelectorAll('[data-ps-seg]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = b.getAttribute('data-ps-seg'), v = b.getAttribute('data-v');
        S[f] = f === 'minutes' ? +v : v;
        form.querySelectorAll('[data-ps-seg="' + f + '"]').forEach(function (x) { x.classList.toggle('on', x === b); });
      });
    });
    bindMats(form);
    var add = function () {
      var inp = form.querySelector('[data-ps-newmat]'), v = (inp.value || '').trim();
      if (!v) return;
      saveMats([v]).then(function () { inp.value = ''; });
    };
    form.querySelector('[data-ps-addmat]').addEventListener('click', add);
    form.querySelector('[data-ps-newmat]').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    var file = form.querySelector('[data-ps-file]');
    form.querySelector('[data-ps-scan]').addEventListener('click', function () { file.value = ''; file.click(); });
    file.addEventListener('change', function () { if (file.files && file.files[0]) scanPhoto(file.files[0], form.querySelector('[data-ps-scan]')); });
    form.querySelector('[data-ps-theme]').addEventListener('input', function (e) { S.theme = e.target.value; });
    form.querySelector('[data-ps-go]').addEventListener('click', function () { generate(body, main, this); });

    function saveMats(names) {
      return Api.post('/provider/play-spark/materials', { room_id: S.roomId, names: names }).then(function (r) {
        S.ctx.materials = r.context.materials;
        names.forEach(function (n) { S.sel[n.toLowerCase().trim()] = true; });
        form.querySelector('[data-ps-mats]').innerHTML = matChips(); bindMats(form);
        if (r.added) toast(r.added === 1 ? 'Material added' : r.added + ' materials added');
      }).catch(function (e) { toast(errMsg(e), 'error'); });
    }
    S._saveMats = saveMats;
    todaysPlan(form);
    if (S.idea) showIdea(body.querySelector('[data-ps-out]'), S.idea, main);
  }

  /* ── Lesson plan ⇄ spark (the merge) ───────────────────────────────── */
  var DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  var DAY_LABEL = { monday: 'Monday', tuesday: 'Tuesday', wednesday: 'Wednesday', thursday: 'Thursday', friday: 'Friday' };
  function todayIso() { return (KT.agencyToday ? KT.agencyToday() : new Date().toISOString()).slice(0, 10); }
  function mondayOf(iso) {
    var p = String(iso).split('-'), d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    var back = (d.getUTCDay() + 6) % 7; d.setUTCDate(d.getUTCDate() - back);
    return d.toISOString().slice(0, 10);
  }
  function todayKey() {
    var p = todayIso().split('-'), d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    return ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][d.getUTCDay()];
  }
  function canWeek() { return lpOn() && WEEK_ROLES.indexOf(myRole()) !== -1; }

  /* Spark it: today's planned activities for this room as chips; one tap makes it the theme of the spark. */
  function todaysPlan(form) {
    var box = form.querySelector('[data-ps-today]');
    if (!box || !canWeek() || !S.roomId || DAYS.indexOf(todayKey()) === -1) return;
    Api.get('/provider/lesson-plans?room_id=' + S.roomId + '&week_starting=' + mondayOf(todayIso())).then(function (r) {
      var acts = (((r && r.plan) || {}).days || {})[todayKey()] || [];
      acts = acts.filter(function (a) { return a && String(a.title || '').trim(); }).slice(0, 8);
      if (!acts.length || !box.isConnected) return;
      box.innerHTML = '<div class="ps-lab">On today\'s plan · tap to spark it</div><div class="ps-chips">' + acts.map(function (a, i) {
        return '<button type="button" class="ps-chip" data-ps-plan="' + i + '">📅 ' + esc(a.title) + '</button>';
      }).join('') + '</div>';
      box.querySelectorAll('[data-ps-plan]').forEach(function (b) {
        b.addEventListener('click', function () {
          var t = String(acts[+b.getAttribute('data-ps-plan')].title || '').slice(0, 120);
          var inp = form.querySelector('[data-ps-theme]'); if (inp) inp.value = t;
          S.theme = t;
          box.querySelectorAll('[data-ps-plan]').forEach(function (x) { x.classList.toggle('on', x === b); });
        });
      });
    }).catch(function () { /* no plan for this week: nothing to offer */ });
  }

  /* Add to this week: the idea goes onto a day of this room's weekly plan. An existing plan keeps its status (a
     published week stays published, a draft stays a draft); a new one is saved as a DRAFT, so nothing reaches
     families until someone publishes it in This week. Saving a plan sends no notification. */
  function addToWeek(row) {
    var I = row.idea || {};
    var today = todayKey(), week = mondayOf(todayIso());
    var bodyEl = document.createElement('div'); bodyEl.setAttribute('data-kt-noband', '1'); bodyEl.setAttribute('data-kt-nosechead', '1');
    bodyEl.innerHTML = '<div class="ps-ctx" style="margin-bottom:8px">Adds <b>' + esc(row.title) + '</b> to the weekly plan for <b>' +
      esc(((S.ctx && S.ctx.room) || {}).name || 'this room') + '</b>, week of ' + esc(week) + '.</div>' +
      '<div class="ps-lab">Day</div><div class="ps-chips">' + DAYS.map(function (d) {
        return '<button type="button" class="ps-chip' + (d === (DAYS.indexOf(today) !== -1 ? today : 'monday') ? ' on' : '') + '" data-day="' + d + '">' + DAY_LABEL[d] + '</button>';
      }).join('') + '</div>' +
      '<div class="ps-lab">Week</div><div class="ps-chips"><button type="button" class="ps-chip on" data-wk="0">This week</button><button type="button" class="ps-chip" data-wk="7">Next week</button></div>';
    var day = DAYS.indexOf(today) !== -1 ? today : 'monday', plus = 0;
    bodyEl.querySelectorAll('[data-day]').forEach(function (b) {
      b.addEventListener('click', function () { day = b.getAttribute('data-day'); bodyEl.querySelectorAll('[data-day]').forEach(function (x) { x.classList.toggle('on', x === b); }); });
    });
    bodyEl.querySelectorAll('[data-wk]').forEach(function (b) {
      b.addEventListener('click', function () { plus = +b.getAttribute('data-wk'); bodyEl.querySelectorAll('[data-wk]').forEach(function (x) { x.classList.toggle('on', x === b); }); });
    });
    Shell.Modal.open({
      title: '📅 Add to the weekly plan', body: bodyEl,
      actions: [
        { label: 'Cancel' },
        { label: 'Add', primary: true, busyLabel: 'Adding…', onClick: function () {
          var p = week.split('-'), d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + plus)), wk = d.toISOString().slice(0, 10);
          return Api.get('/provider/lesson-plans?room_id=' + S.roomId + '&week_starting=' + wk).then(function (plan) {
            var days = (plan && plan.plan && plan.plan.days) ? plan.plan.days : {};
            DAYS.forEach(function (k) { if (!Array.isArray(days[k])) days[k] = []; });
            var mats = (I.materials || []).slice(0, 12);
            days[day].push({ time: '', title: String(row.title || I.title || '').slice(0, 200), domain: '', play_spark_id: row.id,
              notes: ((I.summary || '') + (mats.length ? ' Materials: ' + mats.join(', ') + '.' : '') + ' (Play Spark)').trim().slice(0, 1000) });
            var own = plan && plan.updated_at && !plan.inherited_from_centre;
            var status = own ? (plan.status || 'published') : 'draft';
            return Api.put('/provider/lesson-plans', { room_id: S.roomId, week_starting: wk, theme: (own && plan.theme) || (plan && plan.theme) || '', status: status, plan: { days: days } })
              .then(function () {
                if (KT.LessonPlans && KT.LessonPlans.focus) KT.LessonPlans.focus({ scope: 'room', roomId: S.roomId, week: wk });
                toast('Added to ' + DAY_LABEL[day] + (status === 'draft' ? ' · saved as a draft' : ''));
              });
          }).catch(function (e) { toast(errMsg(e), 'error'); return false; });
        } }
      ]
    });
  }

  function seg(label, field, opts) {
    return '<div class="ps-lab">' + label + '</div><div class="ps-chips">' + opts.map(function (o) {
      return '<button type="button" class="ps-chip' + (String(S[field]) === String(o[0]) ? ' on' : '') + '" data-ps-seg="' + field + '" data-v="' + o[0] + '">' + o[1] + '</button>';
    }).join('') + '</div>';
  }

  function matChips() {
    var m = (S.ctx && S.ctx.materials) || [];
    if (!m.length) return '<span class="ps-ctx">None saved for this room yet. Add what you have, or scan a photo.</span>';
    return m.map(function (x) {
      return '<span class="ps-chip' + (S.sel[x.name] ? ' on' : '') + '" data-ps-mat="' + esc(x.name) + '" role="button" tabindex="0">' + esc(x.name) +
        '<span class="x" data-ps-delmat="' + x.id + '" data-kttip="Remove from this room" aria-label="Remove">✕</span></span>';
    }).join('');
  }

  function bindMats(form) {
    form.querySelectorAll('[data-ps-mat]').forEach(function (ch) {
      ch.addEventListener('click', function (e) {
        var del = e.target.closest('[data-ps-delmat]');
        var n = ch.getAttribute('data-ps-mat');
        if (del) {
          Api.delete('/provider/play-spark/materials/' + del.getAttribute('data-ps-delmat')).then(function () {
            S.ctx.materials = S.ctx.materials.filter(function (m) { return m.name !== n; });
            delete S.sel[n]; ch.remove(); toast('Removed');
          }).catch(function (er) { toast(errMsg(er), 'error'); });
          return;
        }
        S.sel[n] = !S.sel[n]; ch.classList.toggle('on', !!S.sel[n]);
      });
    });
  }

  /* A photo of the materials: refused here if a face shows, shrunk, read once on the server, never kept. */
  function scanPhoto(f, btn) {
    btn.disabled = true; var old = btn.textContent; btn.textContent = 'Reading…';
    var done = function () { btn.disabled = false; btn.textContent = old; };
    var check = (KT.faceCheck ? KT.faceCheck(f, { who: 'materials' }) : Promise.resolve({ engine: 'none' }));
    check.then(function (r) {
      if (r && (r.engine === 'native' || (r.faces || 0) > 0)) {
        done(); toast('That photo has a person in it. Take one of just the materials.', 'error'); return;
      }
      return shrink(f).then(function (dataUrl) {
        return Api.post('/provider/play-spark/scan', { image: dataUrl });
      }).then(function (res) {
        done();
        var found = (res && res.materials) || [];
        if (!found.length) { toast('No play materials spotted. Try a closer photo.', 'error'); return; }
        return S._saveMats(found);
      });
    }).catch(function (e) { done(); toast(errMsg(e), 'error'); });
  }

  function shrink(f) {
    return new Promise(function (res, rej) {
      var img = new Image(), url = URL.createObjectURL(f);
      img.onload = function () {
        var s = Math.min(1, 1280 / Math.max(img.width, img.height));
        var cv = document.createElement('canvas'); cv.width = Math.round(img.width * s); cv.height = Math.round(img.height * s);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); URL.revokeObjectURL(url);
        res(cv.toDataURL('image/jpeg', 0.82));
      };
      img.onerror = function () { URL.revokeObjectURL(url); rej(new Error('Could not open that photo.')); };
      img.src = url;
    });
  }

  async function generate(body, main, btn) {
    if (S.busy) return;
    S.busy = true; btn.disabled = true; btn.textContent = '✨ Sparking…';
    var out = body.querySelector('[data-ps-out]');
    out.innerHTML = '<div class="ps-card ps-load"><div class="spinner"></div>Putting an idea together for your room…</div>';
    var bands = {}; Object.keys(S.bands).forEach(function (k) { bands[k] = S.bands[k] || 1; });
    try {
      var r = await Api.post('/provider/play-spark/generate', {
        room_id: S.roomId, bands: bands, setting: S.setting, minutes: S.minutes, involvement: S.involvement,
        materials: Object.keys(S.sel).filter(function (k) { return S.sel[k]; }), theme: S.theme
      });
      S.idea = r.idea; S.ack = false;
      showIdea(out, S.idea, main);
    } catch (e) {
      out.innerHTML = '<div class="ps-card ps-empty">' + esc(errMsg(e)) + '</div>';
    } finally { S.busy = false; btn.disabled = false; btn.textContent = '✨ Spark an idea'; }
  }

  function list(arr, tag) {
    return arr && arr.length ? '<' + tag + '>' + arr.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</' + tag + '>' : '';
  }

  /* THE END-RESULT PICTURE (2026-11-24): KT.PlaySparkPic (kt-play-spark-pic.js, loaded with this screen). */
  function Pic() { return KT.PlaySparkPic || null; }
  function ideaPic(el, row, opts) {
    if (!el || !Pic() || !row || !row.id || row.picture === 'off' || row.picture === 'failed') return;
    Pic().idea(el, row.id, row.title, opts);
  }

  function ideaHtml(row, printable) {
    var I = row.idea || {};
    var caution = row.safety_level === 'caution';
    return '<div class="ps-idea"><h2>' + esc(I.title) + '</h2><div style="font-size:14px">' + esc(I.summary) + '</div>' +
      '<div class="ps-meta"><span class="ps-pill">⏱ ' + (I.minutes || 20) + ' min</span><span class="ps-pill">' +
      ({ indoor: '🏠 Indoor', outdoor: '🌳 Outdoor', either: '🏠🌳 Indoor or outdoor' }[I.setting] || '') + '</span>' +
      (I.areas || []).map(function (a) { return '<span class="ps-pill area">' + esc(a) + '</span>'; }).join('') +
      (I.framework ? '<span class="ps-pill">' + esc(I.framework) + '</span>' : '') + '</div>' +
      (printable ? ((Pic() && Pic().cached('i' + row.id)) ? '<div class="ps-pic"><img alt="" style="display:block;width:100%;max-width:720px;height:auto;border:1px solid #d7dde8;border-radius:12px" src="' + Pic().src(Pic().cached('i' + row.id)) + '"></div>' : '')
        : '<div class="ps-pic" data-ps-pic></div>') +
      '<div class="ps-safe' + (caution ? ' caution' : '') + '"><h4>' + (caution ? '⚠️ Safety check - read before you start' : '🛡️ Safety check') + '</h4>' +
      list(I.safety, 'ul') + (printable ? '' : '<label><input type="checkbox" data-ps-ack' + (S.ack ? ' checked' : '') + '> I have read the safety check</label>') + '</div>' +
      (I.materials && I.materials.length ? '<div class="ps-sec"><h4>You need</h4><div class="ps-chips">' + I.materials.map(function (m) { return '<span class="ps-pill">' + esc(m) + '</span>'; }).join('') + '</div></div>' : '') +
      (I.setup && I.setup.length ? '<div class="ps-sec"><h4>Set up</h4>' + list(I.setup, 'ol') + '</div>' : '') +
      '<div class="ps-sec"><h4>Every age joins in</h4><div class="ps-tiers">' + (I.tiers || []).map(function (t) {
        return '<div class="ps-tier"><div class="b">' + esc(t.band_label) + '</div><div class="t">' + esc(t.label) + '</div>' + list(t.steps, 'ol') +
          (t.stretch ? '<div class="s">➕ ' + esc(t.stretch) + '</div>' : '') + '</div>';
      }).join('') + '</div></div>' +
      (I.watch_for && I.watch_for.length ? '<div class="ps-sec"><h4>Watch for</h4>' + list(I.watch_for, 'ul') + '</div>' : '') +
      (I.talk && I.talk.length ? '<div class="ps-sec"><h4>Ask the children</h4>' + list(I.talk, 'ul') + '</div>' : '') +
      (I.home_idea ? '<div class="ps-home">🏡 <b>Try at home:</b> ' + esc(I.home_idea) + '</div>' : '') + '</div>';
  }

  function showIdea(out, row, main) {
    out.innerHTML = '<div class="ps-card">' + ideaHtml(row) + '<div class="ps-acts">' +
      '<button type="button" class="ps-btn pri" data-ps-did' + (S.ack ? '' : ' disabled') + ' data-kttip="Logs it to the daily record for the children who joined in and drafts an observation for each">✅ We did it</button>' +
      '<button type="button" class="ps-btn" data-ps-fav>' + (row.favourite ? '★ Saved' : '☆ Save') + '</button>' +
      (row.mine ? '<button type="button" class="ps-btn" data-ps-share>' + (row.shared ? '📚 In the library' : '📚 Share to library') + '</button>' : '') +
      (canWeek() && S.roomId ? '<button type="button" class="ps-btn" data-ps-week data-kttip="Put this idea on a day of the room\'s weekly lesson plan">📅 Add to this week</button>' : '') +
      '<button type="button" class="ps-btn" data-ps-print>🖨️ Print</button>' +
      (S.tab === 'now' ? '<button type="button" class="ps-btn" data-ps-again>🔄 Another idea</button>' : '') +
      '</div></div>';
    ideaPic(out.querySelector('[data-ps-pic]'), row);
    var wk = out.querySelector('[data-ps-week]');
    if (wk) wk.addEventListener('click', function () { addToWeek(row); });
    var did = out.querySelector('[data-ps-did]');
    var ack = out.querySelector('[data-ps-ack]');
    if (ack) ack.addEventListener('change', function () { S.ack = ack.checked; did.disabled = !ack.checked; });
    did.addEventListener('click', function () { weDidIt(row); });
    var fav = out.querySelector('[data-ps-fav]');
    if (fav) fav.addEventListener('click', function () {
      if (!row.mine) { toast('Only the person who made this idea can save it.', 'error'); return; }
      Api.patch('/provider/play-spark/ideas/' + row.id, { favourite: !row.favourite }).then(function (r) {
        row.favourite = r.idea.favourite; fav.textContent = row.favourite ? '★ Saved' : '☆ Save'; toast(row.favourite ? 'Saved to your favourites' : 'Removed from favourites');
      }).catch(function (e) { toast(errMsg(e), 'error'); });
    });
    var sh = out.querySelector('[data-ps-share]');
    if (sh) sh.addEventListener('click', function () {
      Api.patch('/provider/play-spark/ideas/' + row.id, { shared: !row.shared }).then(function (r) {
        row.shared = r.idea.shared; sh.textContent = row.shared ? '📚 In the library' : '📚 Share to library';
        toast(row.shared ? 'Shared with your agency' : 'Taken out of the library');
      }).catch(function (e) { toast(errMsg(e), 'error'); });
    });
    out.querySelector('[data-ps-print]').addEventListener('click', function () { printIdea(row); });
    var again = out.querySelector('[data-ps-again]');
    if (again) again.addEventListener('click', function () {
      var go = document.querySelector('[data-ps-go]'); if (go) go.click();
    });
  }

  function printIdea(row) {
    // The picture prints too: fetch it first if this screen has not shown it yet (ready only; no waiting at the printer).
    if (row.id && Pic() && !Pic().cached('i' + row.id) && row.picture !== 'off') {
      Api.get('/provider/play-spark/ideas/' + row.id + '/picture').then(function (r) {
        if (r && r.status === 'ready' && r.svg) Pic().remember('i' + row.id, r.svg);
      }).catch(function () {}).then(function () { printIdeaNow(row); });
      return;
    }
    printIdeaNow(row);
  }
  function printIdeaNow(row) {
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(row.title) + '</title><style>' + CSS +
      'body{font-family:Inter,system-ui,sans-serif;color:#0f172a;margin:24px;--kt-border:#d7dde8;--kt-bg:#f6f8fb;--kt-surface:#fff;--kt-text-faint:#64748b;--kt-text-muted:#475569;}' +
      '.ps-hero{border-bottom:3px solid #3D8BFF;border-image:linear-gradient(90deg,#FF5A4E,#E8A400 35%,#3D8BFF 68%,#22B37C) 1;padding-bottom:8px;}</style></head><body>' +
      '<div class="ps-hero"><img src="' + location.origin + MARK + '" alt=""><div><div class="ey">Play Spark</div><div class="sub">On-the-spot play ideas</div></div></div>' +
      ideaHtml(row, true) + '</body></html>';
    if (KT.printDoc) KT.printDoc(html, { title: row.title });
  }

  function weDidIt(row) {
    var kids = S.children || [];
    if (!kids.length) { toast('No children are placed in this room.', 'error'); return; }
    var picked = {};
    kids.forEach(function (k) { if (k.here) picked[k.id] = true; });
    var bodyEl = document.createElement('div'); bodyEl.setAttribute('data-kt-noband', '1'); bodyEl.setAttribute('data-kt-nosechead', '1');
    bodyEl.innerHTML = '<div class="ps-ctx" style="margin-bottom:8px">Who joined in? Signed-in children are ticked.</div>' +
      '<div class="ps-kids">' + kids.map(function (k) {
        return '<button type="button" class="ps-chip' + (picked[k.id] ? ' on' : '') + '" data-k="' + k.id + '">' + esc(k.name) + (k.here ? '' : ' <span style="opacity:.6">(not signed in)</span>') + '</button>';
      }).join('') + '</div>' +
      '<div class="ps-lab">A note (optional)</div><textarea class="ps-in" data-note maxlength="1000" style="width:100%;min-height:70px;padding:6px 8px" placeholder="What did you notice?"></textarea>' +
      '<label class="ps-ctx" style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" data-obs checked> Draft an observation for each child (not shared until you review it)</label>';
    bodyEl.querySelectorAll('[data-k]').forEach(function (b) {
      b.addEventListener('click', function () { var id = +b.getAttribute('data-k'); picked[id] = !picked[id]; b.classList.toggle('on', !!picked[id]); });
    });
    Shell.Modal.open({
      title: '✅ We did it: ' + row.title, body: bodyEl,
      actions: [
        { label: 'Cancel' },
        { label: 'Log it', primary: true, busyLabel: 'Logging…', onClick: function () {
          var ids = Object.keys(picked).filter(function (k) { return picked[k]; }).map(Number);
          if (!ids.length) { toast('Pick at least one child.', 'error'); return false; }
          return Api.post('/provider/play-spark/' + row.id + '/done', {
            room_id: S.roomId, child_ids: ids, note: bodyEl.querySelector('[data-note]').value, safety_ack: true,
            observations: bodyEl.querySelector('[data-obs]').checked
          }).then(function (r) {
            toast('Logged for ' + r.logged + (r.logged === 1 ? ' child' : ' children') + (r.observations ? ' · observations drafted' : ''));
            row.times_done = (row.times_done || 0) + 1;
          }).catch(function (e) { toast(errMsg(e), 'error'); return false; });
        } }
      ]
    });
  }

  async function renderLibrary(body, main) {
    var scopes = libScopes();
    if (!scopes.some(function (t) { return t[0] === S.libScope; })) S.libScope = scopes.length ? scopes[0][0] : '';
    setHash();
    body.innerHTML = (scopes.length > 1 ? '<div class="ps-chips" style="margin-bottom:12px">' + scopes.map(function (t) {
      return '<button type="button" class="ps-chip' + (S.libScope === t[0] ? ' on' : '') + '" data-scope="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>' : '') + '<div data-list><div class="ps-load"><div class="spinner"></div></div></div>';
    body.querySelectorAll('[data-scope]').forEach(function (b) {
      b.addEventListener('click', function () { S.libScope = b.getAttribute('data-scope'); renderLibrary(body, main); });
    });
    var el = body.querySelector('[data-list]');
    /* 💡 Activity ideas: the agency's one-activity templates (were the Curriculum library's "Activity ideas" tab). */
    if (S.libScope === 'ideas') {
      if (KT.V22p57 && KT.V22p57.renderCurriculumIdeas) { el.innerHTML = ''; return KT.V22p57.renderCurriculumIdeas(el); }
      el.innerHTML = '<div class="ps-card ps-empty">Activity ideas could not open. Refresh the page and try again.</div>';
      return;
    }
    if (!S.libScope) { el.innerHTML = '<div class="ps-card ps-empty">Nothing in the library yet.</div>'; return; }
    try {
      var r = await Api.get('/provider/play-spark/ideas?scope=' + S.libScope);
      var rows = r.ideas || [];
      if (!rows.length) { el.innerHTML = '<div class="ps-card ps-empty">' + (S.libScope === 'shared' ? 'Nobody has shared an idea yet. Share a good one from its page.' : 'No ideas here yet. Spark one from Right now.') + '</div>'; return; }
      el.innerHTML = '<div class="ps-list">' + rows.map(function (x, i) {
        var I = x.idea || {};
        return '<div class="ps-card ps-li" data-i="' + i + '"><div class="ps-thumb-wrap" data-ps-thumb="' + i + '"></div><div style="font-weight:800">' + esc(x.title) + (x.favourite ? ' ★' : '') + '</div>' +
          '<div class="ps-ctx" style="margin:4px 0">' + esc((I.summary || '').slice(0, 140)) + '</div>' +
          '<div class="ps-meta" style="margin:0">' + (I.tiers || []).map(function (t) { return '<span class="ps-pill">' + esc(BAND_SHORT[t.band] || t.band) + '</span>'; }).join('') +
          (x.times_done ? '<span class="ps-pill area">Done ' + x.times_done + '×</span>' : '') + (x.mine ? '' : '<span class="ps-pill">by ' + esc(x.by || '') + '</span>') + '</div></div>';
      }).join('') + '</div><div data-open style="margin-top:14px"></div>';
      // Thumbnails: ready ones now; ones being drawn (new, or older ideas being filled in) appear when done.
      el.querySelectorAll('[data-ps-thumb]').forEach(function (t) {
        var row = rows[+t.getAttribute('data-ps-thumb')];
        if (row && (row.picture === 'ready' || row.picture === 'drawing')) ideaPic(t, row, { thumb: true });
      });
      el.querySelectorAll('[data-i]').forEach(function (c) {
        c.addEventListener('click', function () {
          var row = rows[+c.getAttribute('data-i')]; S.ack = false;
          var o = el.querySelector('[data-open]'); showIdea(o, row, main);
          o.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
    } catch (e) { el.innerHTML = '<div class="ps-card ps-empty">' + esc(errMsg(e)) + '</div>'; }
  }

  async function renderReport(body, main) {
    var month = S.month || (KT.agencyToday ? KT.agencyToday() : new Date().toISOString()).slice(0, 7);
    body.innerHTML = '<div class="ps-row" style="margin-bottom:12px;flex-wrap:wrap"><select data-room>' +
      S.rooms.map(function (r) { return '<option value="' + r.id + '"' + (r.id === S.roomId ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('') +
      '</select><input type="month" data-month value="' + month + '"><button type="button" class="ps-btn" data-print>🖨️ Print</button></div><div data-rep><div class="ps-load"><div class="spinner"></div></div></div>';
    var go = async function () {
      var rid = +body.querySelector('[data-room]').value, m = body.querySelector('[data-month]').value;
      S.month = m;
      var el = body.querySelector('[data-rep]');
      try {
        var r = await Api.get('/provider/play-spark/report?room_id=' + rid + '&month=' + encodeURIComponent(m));
        el.innerHTML = reportHtml(r); S._rep = r;
      } catch (e) { el.innerHTML = '<div class="ps-card ps-empty">' + esc(errMsg(e)) + '</div>'; }
    };
    body.querySelector('[data-room]').addEventListener('change', go);
    body.querySelector('[data-month]').addEventListener('change', go);
    body.querySelector('[data-print]').addEventListener('click', function () {
      if (!S._rep || !KT.printDoc) return;
      KT.printDoc('<!doctype html><html><head><meta charset="utf-8"><title>Play report</title><style>' + CSS +
        'body{font-family:Inter,system-ui,sans-serif;color:#0f172a;margin:24px;--kt-border:#d7dde8;--kt-bg:#f6f8fb;--kt-surface:#fff;--kt-text-faint:#64748b;--kt-text-muted:#475569;}</style></head><body>' +
        '<div class="ps-hero"><img src="' + location.origin + MARK + '" alt=""><div><div class="ey">Play Spark</div><h1>Monthly play report</h1></div></div>' + reportHtml(S._rep) + '</body></html>', { title: 'Play report ' + S._rep.month });
    });
    go();
  }

  function reportHtml(r) {
    var areas = r.areas || {}, max = Math.max.apply(null, [1].concat(Object.values(areas)));
    var acts = r.activities || [];
    return '<div class="ps-card"><div style="font-size:18px;font-weight:800">' + esc(r.room) + ' · ' + esc(r.month) + '</div>' +
      '<div class="ps-ctx">' + r.totals.activities + ' Play Spark activities · ' + r.totals.children + ' children joined in · ' + esc(r.framework) + '</div>' +
      (Object.keys(areas).length ? '<div class="ps-sec"><h4>Learning areas</h4>' + Object.keys(areas).map(function (a) {
        return '<div style="display:grid;grid-template-columns:140px 1fr 30px;gap:8px;align-items:center;font-size:13px;margin:4px 0"><span>' + esc(a) +
          '</span><div style="background:var(--kt-bg);border-radius:4px"><div class="ps-bar" style="width:' + Math.round(areas[a] / max * 100) + '%"></div></div><b>' + areas[a] + '</b></div>';
      }).join('') + '</div>' : '') +
      '<div class="ps-sec"><h4>Activities</h4>' + (acts.length ? acts.map(function (x) {
        return '<div style="border-top:1px solid var(--kt-border);padding:8px 0"><b>' + esc(x.title) + '</b> <span class="ps-ctx">· ' + esc(x.date) + ' · ' + x.children + ' children</span>' +
          (x.areas.length ? ' ' + x.areas.map(function (a) { return '<span class="ps-pill area">' + esc(a) + '</span>'; }).join(' ') : '') +
          (x.note ? '<div class="ps-ctx">' + esc(x.note) + '</div>' : '') +
          (x.home_idea ? '<div class="ps-ctx">🏡 ' + esc(x.home_idea) + '</div>' : '') + '</div>';
      }).join('') : '<div class="ps-ctx">No Play Spark activities logged this month.</div>') + '</div></div>';
  }

  /* ── parents: Play at home ─────────────────────────────────────────── */
  async function renderParent(main) {
    addCss();
    Dom.clear(main);
    var w = document.createElement('div'); w.className = 'ps-wrap'; w.setAttribute('data-kt-noband', '1'); w.setAttribute('data-kt-nosechead', '1'); main.appendChild(w);   // own chips, not tab bands
    var month = (KT.agencyToday ? KT.agencyToday() : new Date().toISOString()).slice(0, 7);
    w.innerHTML = hero({ title: 'Play at home', sub: 'What your child played at the centre, and an easy way to try it at home.' }) +
      '<div class="ps-row" style="margin-bottom:12px"><input type="month" data-month value="' + month + '"></div><div data-ps-body></div>';
    var go = async function () {
      var el = w.querySelector('[data-ps-body]');
      el.innerHTML = '<div class="ps-load"><div class="spinner"></div></div>';
      try {
        var r = await Api.get('/parent/play-spark?month=' + encodeURIComponent(w.querySelector('[data-month]').value));
        var a = r.activities || [];
        el.innerHTML = a.length ? '<div class="ps-list">' + a.map(function (x) {
          return '<div class="ps-card"><div class="ps-ctx">' + esc(x.date) + '</div><div style="font-weight:800;margin:2px 0 6px">' + esc(x.title) + '</div>' +
            (x.play_spark_id ? '<div class="ps-pic" data-ps-ppic="' + x.play_spark_id + '" data-t="' + esc(x.title) + '"></div>' : '') +
            (x.areas.length ? '<div class="ps-meta" style="margin:0 0 6px">' + x.areas.map(function (s) { return '<span class="ps-pill area">' + esc(s) + '</span>'; }).join('') + '</div>' : '') +
            (x.home_idea ? '<div class="ps-home" style="margin-top:4px">🏡 <b>Try at home:</b> ' + esc(x.home_idea) + '</div>' : '') + '</div>';
        }).join('') + '</div>' : '<div class="ps-card ps-empty">No Play Spark activities this month yet.</div>';
        // The end-result picture of each one (families only see a picture that is already drawn).
        if (Pic()) el.querySelectorAll('[data-ps-ppic]').forEach(function (p) {
          var id = p.getAttribute('data-ps-ppic');
          Pic().mount(p, function () { return Api.get('/parent/play-spark/picture/' + id); }, { key: 'i' + id, title: p.getAttribute('data-t') });
        });
      } catch (e) { el.innerHTML = '<div class="ps-card ps-empty">' + esc(errMsg(e)) + '</div>'; }
    };
    w.querySelector('[data-month]').addEventListener('change', go);
    go();
  }

  KT.renderPlaySpark = renderStaff;
  /* Open Play Spark on a tab from anywhere (the old #lesson-plans / #lesson-plans-ai redirect through this). */
  KT.openPlaySpark = function (tab, lib) {
    location.hash = '#play-spark?tab=' + encodeURIComponent(tab || 'week') + (lib ? '&lib=' + encodeURIComponent(lib) : '');
  };
  if (Shell && Shell.registerScreen) {
    ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':play-spark', function (main) { return renderStaff(main); });
    });
    Shell.registerScreen('guardian:play-spark', function (main) { return renderParent(main); });
  }
})(window);
