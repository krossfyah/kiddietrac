/* ═══════════════════════════════════════════════════════════════════════
   Drill log (#drills) — 2026-10-09

   Fire, severe weather, lockdown, vehicle and shelter drills: record one, see a month of
   them, and see which drills are still owed (or were missed) under the agency's province or
   state — Ohio: fire monthly, weather monthly March–September, lockdown quarterly; Ontario
   and the other provinces: fire monthly. Admins see every centre, directors and educators
   their own. Times are in the agency's timezone.

   ONTARIO (2026-10-13, O. Reg. 137/15 s.68 / s.68.2): a "Drill schedule" card per centre —
   last missing-child drill, next due (every 3 months), and the fire drill (monthly, or 3 a
   term for a school-based program). Missing child is a drill type there, with its own
   fields (area, scenario, who led it, time to locate, 911 / parent call simulated).

   THREE TABS (2026-11-09, Anthony: "drill log section add a subtab to show the settings and move those settings
   there, the centre map should be a kebab that can view the map in the table for each educator and configure each
   educator to set the fire drill renewal dates and expiry dates, the fire drill table right now is hard to read"):
     🚒 Drills     what is owed, record a drill, the month's drills (one line per drill; the full record opens
                   from its ⋮ → View details)
     👩‍🏫 Educators each person's training dates, fire drill day/time and drill map; ⋮ → view / add / replace / remove
                   map, set schedule, set dates (management)
     ⚙️ Settings   the centre fire drill rule (Ontario), notifications (who hears, educator reminders, admin notices)
   The banner and the tab strip are drawn once; a tab or a save repaints the tab body only.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  /* `local` is already the agency's wall-clock time ("2026-10-10 09:30"): show it as written, never through a zone
     conversion (2026-10-28 — the table polisher read it as UTC and a 9:30 drill showed at 2:30 a.m.). */
  function loc() { try { return sessionStorage.getItem('kt_spelling') === 'us' ? 'en-US' : 'en-CA'; } catch (e) { return 'en-CA'; } }
  function whenParts(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(v || ''));
    if (!m) return [String(v || ''), ''];
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));
    return [d.toLocaleDateString(loc(), { timeZone: 'UTC', weekday: 'short', month: 'short', day: 'numeric' }),
      d.toLocaleTimeString(loc(), { timeZone: 'UTC', hour: 'numeric', minute: '2-digit' })];
  }
  function whenLocal(v) { var p = whenParts(v); return p[0] + (p[1] ? ', ' + p[1] : ''); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  // A bare YYYY-MM-DD is a calendar day, formatted from its own parts (never new Date()).
  function day(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function mmss(sec) { return Math.floor(sec / 60) + 'm ' + (sec % 60) + 's'; }
  var CAD = { ok: ['#ECFDF5', '#047857', 'On track'], due: ['#F1F5F9', '#475569', 'Due'], due_soon: ['#FFFBEB', '#B45309', 'Due soon'], overdue: ['#FEF2F2', '#B91C1C', 'Overdue'] };
  // Each drill type has its own colour and sign, so a month of drills can be read at a glance.
  var TYPE = { fire: ['🔥', '#FEF2F2', '#B91C1C'], missing_child: ['🧒', '#EFF6FF', '#1D4ED8'], weather: ['⛈️', '#F0F9FF', '#0369A1'],
    lockdown: ['🔒', '#F5F3FF', '#6D28D9'], vehicle_exit: ['🚐', '#FFF7ED', '#C2410C'], shelter: ['🏠', '#F0FDF4', '#15803D'],
    disaster: ['🌋', '#FEFCE8', '#A16207'], other: ['📋', '#F1F5F9', '#475569'] };
  var STAFF = { ok: ['#ECFDF5', '#047857', 'Current'], due_soon: ['#FFFBEB', '#B45309', 'Expires soon'], expired: ['#FEF2F2', '#B91C1C', 'Expired'], none: ['#F1F5F9', '#475569', 'No dates yet'] };
  function chip(t, extra) { return '<span class="dr-chip" data-kt-no-tone style="background:' + t[0] + ';color:' + t[1] + ';' + (extra || '') + '">' + esc(t[2]) + '</span>'; }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function role() { try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').roles || []); } catch (e) { return []; } }
  var CSS_ID = 'kt-drills-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.dr-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-top:14px;}'
      + '.dr-card:first-child{margin-top:0;}'
      + '.dr-h{font-weight:800;font-size:15px;margin-bottom:2px;color:#0F172A;}'
      + '.dr-sub{font-size:12.5px;color:#64748B;margin-bottom:8px;}'
      + '.dr-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.dr-i{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;}'
      + '.dr-b{height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.dr-b.p{background:#B91C1C;border-color:#B91C1C;color:#fff;}'
      + '.dr-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;}'
      + '.dr-tabs{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:14px 0 14px;padding:0 0 2px;}'
      + '.dr-tabs button{background:none;border:0;border-bottom:2px solid transparent;padding:9px 13px;font-size:13.5px;font-weight:700;color:#64748B;cursor:pointer;border-radius:8px 8px 0 0;}'
      + '.dr-tabs button[aria-selected="true"]{border-bottom-color:#B91C1C;color:#0F172A;}'
      /* The drills table: one line per drill, a strong date, a coloured type, the result as a number. */
      + '.dr-t{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px;}'
      + '.dr-t th{background:#F8FAFC;color:#475569;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;padding:10px 12px;text-align:left;border-bottom:1px solid #E2E8F0;white-space:nowrap;}'
      + '.dr-t td{padding:11px 12px;border-bottom:1px solid #F1F5F9;vertical-align:middle;}'
      + '.dr-t tbody tr:nth-child(even) td{background:#FBFCFE;}'
      + '.dr-t tbody tr:hover td{background:#F1F5F9;}'
      + '.dr-d1{font-weight:800;color:#0F172A;white-space:nowrap;}.dr-d2{font-size:12px;color:#64748B;white-space:nowrap;}'
      + '.dr-note{font-size:12px;color:#64748B;margin-top:3px;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.dr-num{font-weight:800;font-variant-numeric:tabular-nums;color:#0F172A;}'
      + '.dr-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(230px,100%),1fr));gap:10px;}'
      + '.dr-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.dr-ck{display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer;height:32px;}'
      + '.dr-maps{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));gap:12px;margin-top:8px;}'
      + '.dr-map{border:1px solid #E2E8F0;border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:8px;min-width:0;}'
      + '.dr-map-img{display:block;width:100%;height:190px;object-fit:contain;background:#F8FAFC;border-radius:8px;cursor:zoom-in;border:0;padding:0;}'
      + '.dr-map-none{height:120px;display:flex;align-items:center;justify-content:center;text-align:center;background:#F8FAFC;border:1.5px dashed #CBD5E1;border-radius:8px;color:#64748B;font-size:12.5px;padding:10px;}'
      + '.dr-kv{display:grid;grid-template-columns:minmax(120px,auto) 1fr;gap:6px 14px;font-size:13.5px;}'
      + '.dr-kv b{color:#475569;font-weight:700;}'
      /* The Drills tab: one block per centre, a row per drill it must run, its recorded drills listed under it. */
      + '.dr-top{display:flex;gap:10px 14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:4px;}'
      + '.dr-ctr{border:1px solid #E2E8F0;border-radius:10px;margin-top:12px;overflow:hidden;}'
      + '.dr-ctr-h{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;padding:9px 14px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;font-weight:800;font-size:14px;color:#0F172A;}'
      + '.dr-ctr-h span{font-weight:600;font-size:12.5px;color:#64748B;}'
      + '.dr-grp+.dr-grp{border-top:1px solid #EEF2F7;}'
      + '.dr-req{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1.7fr) 96px 92px 32px;gap:6px 14px;align-items:center;padding:10px 14px 6px;}'
      + '.dr-req .dr-b{justify-self:end;white-space:nowrap;}.dr-req-off{background:#F8FAFC;padding-bottom:10px;}.dr-req-off .dr-mid{color:#64748B;}'
      + '.dr-mid{font-size:13px;color:#334155;min-width:0;}'
      + '.dr-log{padding:0 14px 10px 14px;}.dr-log:empty{display:none;}'
      + '.dr-it{display:grid;grid-template-columns:150px minmax(0,1.4fr) minmax(0,1fr) 92px minmax(0,1fr) 32px;gap:4px 12px;align-items:center;width:100%;box-sizing:border-box;text-align:left;background:#fff;border:1px solid #E2E8F0;border-radius:8px;padding:8px 10px;margin-top:6px;font:inherit;font-size:13px;color:#334155;cursor:pointer;}'
      + '.dr-it:hover{filter:brightness(.97);}'
      + '.dr-it b{color:#0F172A;}'
      + '.dr-it .dr-note{display:block;margin-top:1px;}'
      + '.dr-it-w,.dr-it-b{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.dr-it:focus-visible{outline:2px solid #1F6080;outline-offset:1px;}'
      + '.dr-it-k{justify-self:end;}'
      + '.dr-none{font-size:12.5px;color:#94A3B8;padding:2px 0;}'
      + '@media (max-width:640px){.dr-req{grid-template-columns:minmax(0,1fr) auto auto;}.dr-req .dr-mid{grid-column:1/-1;}'
      + '.dr-it{display:flex;flex-wrap:wrap;gap:3px 12px;}.dr-it-d{flex:1 1 100%;}.dr-it-k{margin-left:auto;}}';
    document.head.appendChild(s);
  }

  var state = { month: null, centre: '', tab: 'drills' };

  function render(main) {
    css();
    var isAdmin = role().some(function (r) { return r === 'agency_admin' || r === 'platform_admin'; });
    main.innerHTML = '<div class="kt-section">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#450A0A 0%,#991B1B 55%,#DC2626 100%);">'
      + '<div class="kt-hero-greet">🚒 SAFETY</div><h1>Drill log</h1>'
      + '<div class="kt-hero-sub" id="dr-sub">Record each drill as soon as it is done.</div></div>'
      + '<div class="dr-tabs kt-subtabs" role="tablist">'
      + [['drills', '🚒 Drills'], ['educators', '👩‍🏫 Educators'], ['settings', '⚙️ Settings']].map(function (t) {
          return '<button type="button" role="tab" data-tab="' + t[0] + '" aria-selected="' + (state.tab === t[0]) + '">' + t[1] + '</button>';
        }).join('') + '</div>'
      + '<div id="dr-pane" style="color:#64748B;">Loading drills…</div></div>';
    main.querySelectorAll('.dr-tabs [data-tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        state.tab = b.getAttribute('data-tab');
        main.querySelectorAll('.dr-tabs [data-tab]').forEach(function (x) { x.setAttribute('aria-selected', String(x === b)); });
        /* Every tab draws from the same /drills answer, so a tab click paints the one already here (2026-11-10: the
           click re-fetched and the old tab stayed on screen until the answer came back). Saves still call again(). */
        if (lastD && lastD.main === main) paint(main, isAdmin, lastD.d); else load(main, isAdmin);
      });
    });
    load(main, isAdmin);
  }

  var gen = 0;
  var lastD = null;   // the last /drills answer and the screen it was drawn on
  async function load(main, isAdmin) {
    var pane = main.querySelector('#dr-pane'); if (!pane) return;
    var my = ++gen;
    var q = [];
    if (state.month) q.push('month=' + state.month);
    if (state.centre) q.push('centre_id=' + state.centre);
    var d;
    try { d = await Api.get('/drills' + (q.length ? '?' + q.join('&') : '')); }
    catch (e) { if (my === gen) pane.innerHTML = '<div style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load drills.') + '</div>'; return; }
    if (my !== gen || !pane.isConnected) return;
    state.month = d.month;
    var sub = main.querySelector('#dr-sub');
    if (sub) sub.textContent = d.jurisdiction.label + ' rules: ' + (((d.rules || []).concat(d.ontario ? ['Missing child drill — at least every 3 months (from Jan 1, 2027)'] : [])).join(' · ') || 'fire drills monthly')
      + '. Record each drill as soon as it is done; times are in ' + d.timezone + '.';
    lastD = { main: main, d: d };
    paint(main, isAdmin, d);
  }

  function paint(main, isAdmin, d) {
    var pane = main.querySelector('#dr-pane'); if (!pane) return;
    pane.style.color = '';
    var again = function () { load(main, isAdmin); };
    if (state.tab === 'educators') paintEducators(pane, d, again);
    else if (state.tab === 'settings') paintSettings(pane, d, isAdmin, again);
    else paintDrills(pane, d, again);
  }

  // Older centre-wide maps (no user_id), by centre; an educator's own map comes on their row (e.map).
  function mapsByCentre(d) { var m = {}; (d.maps || []).forEach(function (x) { if (x.user_id == null) m[x.centre_id] = x; }); return m; }
  function showMap(m, title) {
    if (!m) return;
    if (KT.openFile) KT.openFile(m.url, { title: title, filename: m.file_name, mime: m.mime });
    else if (KT.viewDocument) KT.viewDocument(m.url, { title: title, mime: m.mime, image: m.is_image });
  }
  function openMap(d, centreId) {
    var c = (d.centres || []).find(function (x) { return String(x.id) === String(centreId); }) || {};
    showMap(mapsByCentre(d)[centreId], 'Centre map · ' + (c.name || ''));
  }

  /* ── 🚒 DRILLS ─────────────────────────────────────────────────────── */
  /* ONE CARD, NOT THREE (2026-11-10, Anthony: "there is a table at the bottom that shows educator submitted a drill why
     dont you combine that to the above table"). Each centre lists the drills it must run - last done and by whom, next
     due, status - and right under each one the drills recorded in the chosen month. The form opens in a popup. */
  function paintDrills(pane, d, again) {
    var rows = d.drills || [];
    var month = monthName(d.month);
    // The drills this centre must run, plus any switched off for it (greyed, with why - DrillCadence::off).
    function reqsOf(c) {
      var out = [], off = c.off || {};
      if (d.ontario) ['fire', 'missing_child'].forEach(function (k) { var x = (c.cadence || {})[k]; if (x) out.push({ type: k, cad: x }); else if (off[k]) out.push({ type: k, off: off[k] }); });
      else (c.rules || []).forEach(function (r) { out.push({ type: r.type, rule: r }); });
      Object.keys(off).forEach(function (k) { if (!out.some(function (q) { return q.type === k; })) out.push({ type: k, off: off[k] }); });
      return out;
    }
    function lastLine(c, type, cad) {
      var lt = (c.latest || {})[type];
      if (lt) return 'Last ' + whenLocal(lt.local) + (lt.by ? ' · ' + lt.by : '');
      return cad && cad.last_date ? 'Last ' + day(cad.last_date) : 'None on record';
    }
    function reqRow(c, q) {
      var t = TYPE[q.type] || TYPE.other, name, rule, next, st, warn = false;
      var kebab = c.can_manage ? '<button type="button" class="kt-ka-kebab" data-kt-inpage data-req="' + c.centre_id + '|' + esc(q.type) + '|' + (q.off ? 'off' : 'on') + '" aria-label="Actions for this drill" aria-haspopup="menu">⋮</button>' : '<span></span>';
      if (q.off) {
        var o = q.off;
        return '<div class="dr-req dr-req-off"><div style="min-width:0;">' + chip(['#F1F5F9', '#64748B', t[0] + ' ' + (o.label || q.type)]) + '</div>'
          + '<div class="dr-mid"><div>Not required here' + (o.reason ? ' — ' + esc(o.reason) : '') + '</div>'
          + '<div style="color:#64748B;font-size:12.5px;">' + esc(['Marked by ' + (o.by_name || 'an admin'), o.at ? 'on ' + o.at : ''].join(' ').trim()) + '</div></div>'
          + '<div>' + chip(['#F1F5F9', '#64748B', 'Not required']) + '</div><span></span>' + kebab + '</div>';
      }
      if (q.cad) {
        var x = q.cad;
        name = x.label; rule = x.rule; st = CAD[x.state] || CAD.due; warn = x.state === 'overdue';
        next = (x.count != null ? x.count + ' of ' + x.needed + ' this term · ' : '')
          + (x.state === 'overdue' && x.missed_month ? 'None in ' + x.missed_month
            : (x.state === 'overdue' ? 'Was due by ' : (x.never ? 'First one due by ' : 'Next due by ')) + day(x.due_on));
      } else if (q.rule) {
        var r = q.rule;
        name = r.label.split(' — ')[0]; rule = (r.label.split(' — ')[1] || '') + (r.optional ? ' (if you transport children)' : '');
        if (r.previous.missed) { st = ['#FEF2F2', '#B91C1C', 'Missed']; warn = true; next = 'None in ' + r.previous.period; }
        else if (!r.current.owed) { st = ['#F1F5F9', '#64748B', 'Not due']; next = 'Not due ' + r.current.period; }
        else if (r.current.done_at) { st = ['#ECFDF5', '#047857', 'Done']; next = 'Done for ' + r.current.period; }
        else { st = ['#FFFBEB', '#B45309', 'Due']; next = 'Due ' + r.current.period; }
      } else {
        name = (d.types || {})[q.type] || q.type; rule = 'Recorded here; not a required drill'; next = ''; st = null;
      }
      return '<div class="dr-req"><div style="min-width:0;">' + chip([t[1], t[2], t[0] + ' ' + name]) + (rule ? '<div class="dr-d2" style="margin-top:4px;white-space:normal;">' + esc(rule) + '</div>' : '') + '</div>'
        + '<div class="dr-mid"><div>' + esc(lastLine(c, q.type, q.cad)) + '</div>' + (next ? '<div style="color:' + (warn ? '#B91C1C' : '#64748B') + ';font-size:12.5px;">' + esc(next) + '</div>' : '') + '</div>'
        + '<div>' + (st ? chip(st) : '') + '</div>'
        + '<button type="button" class="dr-b" data-rec="' + c.centre_id + '|' + esc(q.type) + '" style="height:28px;font-size:12px;">＋ Record</button>' + kebab + '</div>';
    }
    function itemLine(r) {
      var p = whenParts(r.local), mc = r.type === 'missing_child', tt = TYPE[r.type] || TYPE.other;
      var secs = mc ? r.located_seconds : r.evacuation_seconds;
      var staffN = String(r.staff_present || '').split(/[,;\n]+/).map(function (x) { return x.trim(); }).filter(Boolean).length;
      var people = (r.children_count != null ? '🧒 ' + esc(r.children_count) : '') + (staffN ? (r.children_count != null ? ' &nbsp; ' : '') + '👩‍🏫 ' + staffN : '');
      var note = mc ? (r.scenario || r.area || r.notes) : r.notes;
      return '<div class="dr-it" role="button" tabindex="0" data-view="' + rows.indexOf(r) + '" title="View details" style="background:' + tt[1] + ';border-color:' + tt[2] + '40;">'
        + '<span class="dr-it-d"><b>' + esc(p[0]) + '</b> ' + esc(p[1]) + '</span>'
        + '<span class="dr-it-w">' + esc(r.room_name || (KT.termText ? KT.termText('Whole centre') : 'Whole centre')) + (note ? '<span class="dr-note">' + esc(note) + '</span>' : '') + '</span>'
        + '<span class="dr-it-r">' + (secs != null ? '<b class="dr-num">' + esc(mmss(secs)) + '</b> ' + (mc ? 'to locate' : 'to evacuate') : '<span style="color:#94A3B8;">No time</span>') + '</span>'
        + '<span class="dr-it-p">' + (people || '') + '</span>'
        + '<span class="dr-it-b">' + esc(r.recorded_by_name || '') + '</span>'
        + '<button type="button" class="kt-ka-kebab dr-it-k" data-kt-inpage data-menu="' + rows.indexOf(r) + '" aria-label="Actions for this drill" aria-haspopup="menu">⋮</button></div>';
    }

    var h = '<div class="dr-card"><div class="dr-top"><div style="flex:1;min-width:220px;"><div class="dr-h">Drills</div>'
      + '<div class="dr-sub" style="margin-bottom:0;">' + (d.ontario ? 'Missing-child drills at least every 3 months, fire drills monthly. Keep records at least 12 months — KiddieTrac keeps them. ' : '')
      + 'Under each drill: the ones recorded in ' + esc(month) + '. Open one for everything recorded.</div></div>'
      + '<div class="dr-row"><input class="dr-i" id="dr-m" type="month" value="' + esc(d.month) + '" aria-label="Month">'
      + '<select class="dr-i" id="dr-fc" aria-label="Centre"><option value="">' + esc(KT.termText ? KT.termText('All my centres') : 'All my centres') + '</option>' + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>'
      + '<button type="button" class="dr-b p" data-rec="">＋ Record drill</button></div></div>';
    (d.status || []).forEach(function (c) {
      var mine = rows.filter(function (r) { return String(r.centre_id) === String(c.centre_id); });
      var reqs = reqsOf(c), seen = {};
      reqs.forEach(function (q) { seen[q.type] = 1; });
      mine.forEach(function (r) { if (!seen[r.type]) { seen[r.type] = 1; reqs.push({ type: r.type }); } });
      h += '<div class="dr-ctr"><div class="dr-ctr-h">' + esc(c.centre_name) + '<span>' + mine.length + ' recorded in ' + esc(month) + '</span></div>';
      if (!reqs.length) h += '<div class="dr-req"><span class="dr-none">No drill rules for this jurisdiction yet.</span></div>';
      reqs.forEach(function (q) {
        var list = mine.filter(function (r) { return r.type === q.type; });
        h += '<div class="dr-grp">' + reqRow(c, q) + '<div class="dr-log">'
          + list.map(itemLine).join('') + '</div></div>';
      });
      h += '</div>';
    });
    if (!(d.status || []).length) h += '<div class="dr-none" style="margin-top:10px;">No centres to show.</div>';
    h += '</div>';
    pane.innerHTML = h;

    pane.querySelector('#dr-m').addEventListener('change', function () { state.month = this.value; again(); });
    pane.querySelector('#dr-fc').addEventListener('change', function () { state.centre = this.value; again(); });
    pane.querySelectorAll('[data-rec]').forEach(function (b) {
      b.addEventListener('click', function () { var v = b.getAttribute('data-rec').split('|'); recordDrill(d, again, v[0] || state.centre, v[1] || ''); });
    });
    pane.querySelectorAll('[data-view]').forEach(function (line) {
      var open = function () { drillDetails(rows[+line.getAttribute('data-view')], d, again); };
      line.addEventListener('click', function (e) { if (!(e.target.closest && e.target.closest('.dr-it-k'))) open(); });
      line.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === line) { e.preventDefault(); open(); } });
    });
    /* ⋮ ON EACH REQUIRED DRILL (2026-11-10, Anthony: "I want to be able to remove any of those that may or may not be
       applicable"). Admins and the centre's director: mark it not required here (a reason is kept and audited), or make
       it required again. Switched off, it also stops its reminders and missed-drill notices (DrillCadence::off). */
    pane.querySelectorAll('[data-req]').forEach(function (k) {
      k.addEventListener('click', function (e) {
        e.stopPropagation();
        var v = k.getAttribute('data-req').split('|'), cid = +v[0], type = v[1], isOff = v[2] === 'off';
        var label = ((d.types || {})[type] || type) + ' drill';
        var b = document.createElement('button'); b.type = 'button';
        b.textContent = isOff ? '✅ Make required again' : '🚫 Not required here…';
        b.addEventListener('click', async function () {
          var reason = null;
          if (!isOff) {
            var ans = await KT.prompt({ title: 'Not required here · ' + label,
              description: 'It comes off this list and its reminders and missed-drill notices stop. The reason is kept with the drill records and in the audit log; you can make it required again at any time.',
              okLabel: 'Mark not required',
              fields: [{ key: 'reason', label: 'Why is this drill not required here?', type: 'textarea', rows: 3, placeholder: 'e.g. No children in care during evening hours' }] });
            if (ans == null) return;
            reason = String(typeof ans === 'object' ? (ans.reason || '') : ans).trim();
            if (!reason) { toast('⚠️', 'Add a reason', 'Say why this drill is not required here.', '#DC2626'); return; }
          } else {
            var ok = KT.confirm ? await KT.confirm({ title: 'Make the ' + label.toLowerCase() + ' required again?', description: 'It goes back on this list and its reminders start again.', okLabel: 'Make required' }) : true;
            if (!ok) return;
          }
          try {
            await Api.post('/drills/requirement', { centre_id: cid, type: type, required: isOff, reason: reason });
            toast(isOff ? '✅' : '🚫', isOff ? label + ' is required again' : label + ' marked not required', '', '#16A34A'); again();
          } catch (x) { toast('⚠️', 'Could not save', (x && x.message) || '', '#DC2626'); }
        });
        if (KT.openActionMenu) KT.openActionMenu(k, [b]);
      });
    });

    /* ⋮ ON EACH DRILL (2026-11-10, Anthony: "if there is a drill logged for an educator how do you remove it? add kebab
       actions"). The house menu (KT.openActionMenu); Remove only for someone the server lets remove it NOW, otherwise
       a greyed line says how long the record is kept. */
    pane.querySelectorAll('[data-menu]').forEach(function (k) {
      k.addEventListener('click', function (e) {
        e.stopPropagation();
        var r = rows[+k.getAttribute('data-menu')], acts = [];
        var view = document.createElement('button'); view.type = 'button'; view.textContent = '👁 View details';
        view.addEventListener('click', function () { drillDetails(r, d, again); }); acts.push(view);
        if (r.may_remove) {
          var rm = document.createElement('button'); rm.type = 'button';
          if (r.can_delete) { rm.textContent = '🗑 Remove drill'; rm.addEventListener('click', function () { removeDrill(r, again); }); }
          else { rm.textContent = '🔒 Kept until ' + (r.kept_until || 'a year after the drill'); rm.disabled = true; rm.title = 'A drill can be removed within 24 hours of entering it, or once it is a year old.'; }
          acts.push(rm);
        }
        if (KT.openActionMenu) KT.openActionMenu(k, acts);
      });
    });
  }

  // Remove one drill (menu or details popup). False = not removed, so a popup stays open.
  async function removeDrill(r, again) {
    var ok = KT.confirm ? await KT.confirm({ title: 'Remove this drill?', description: (r.type_label || 'Drill') + ' drill on ' + whenLocal(r.local) + (r.recorded_by_name ? ', recorded by ' + r.recorded_by_name : '') + '. It is taken off the log; the removal is recorded in the audit log.', okLabel: 'Remove' }) : true;
    if (!ok) return false;
    try { await Api.delete('/drills/' + r.id); } catch (e) { toast('⚠️', 'Could not remove', (e && e.message) || '', '#DC2626'); return false; }
    toast('🗑', 'Drill removed', '', '#16A34A'); if (again) again();
    return true;
  }

  // Record a drill, in a popup. Centre and type come in from the row's ＋ Record; "now" is the AGENCY's clock.
  function recordDrill(d, again, centreId, type) {
    if (!KT.Shell || !KT.Shell.Modal) return;
    var local = '';
    try {
      var parts = {}; new Intl.DateTimeFormat('en-CA', { timeZone: d.timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date()).forEach(function (x) { parts[x.type] = x.value; });
      local = parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
    } catch (e) { local = ''; }
    var body = document.createElement('div');
    body.innerHTML = '<div class="dr-g">'
      + '<div><label class="dr-l">Centre</label><select class="dr-i" id="dr-c" style="width:100%;">' + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(centreId) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="dr-l">Room (optional)</label><select class="dr-i" id="dr-r" style="width:100%;"></select></div>'
      + '<div><label class="dr-l">Type</label><select class="dr-i" id="dr-t" style="width:100%;">' + Object.keys(d.types || {}).map(function (k) { return '<option value="' + k + '"' + (k === type ? ' selected' : '') + '>' + esc(d.types[k]) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="dr-l">When</label><input class="dr-i" id="dr-at" type="datetime-local" value="' + local + '" style="width:100%;"></div>'
      + '<div id="dr-s-w"><label class="dr-l">Evacuation time (seconds)</label><input class="dr-i" id="dr-s" type="number" min="0" style="width:100%;"></div>'
      + '<div><label class="dr-l">Children present</label><input class="dr-i" id="dr-k" type="number" min="0" style="width:100%;"></div>'
      + '</div>'
      + '<div class="dr-g" style="margin-top:10px;"><div><label class="dr-l">Staff present</label><input class="dr-i" id="dr-st" maxlength="500" placeholder="Names" style="width:100%;"></div>'
      + '<div><label class="dr-l">Notes</label><input class="dr-i" id="dr-n" maxlength="2000" placeholder="Exit used, anything to fix" style="width:100%;"></div></div>'
      // Missing-child drill (Ontario s.68.2) — shown only for that type.
      + '<div id="dr-mc" hidden><div class="dr-g" style="margin-top:10px;">'
      + '<div><label class="dr-l">Room(s) / area</label><input class="dr-i" id="dr-area" maxlength="255" placeholder="e.g. Toddler room, playground" style="width:100%;"></div>'
      + '<div><label class="dr-l">Led by</label><input class="dr-i" id="dr-led" maxlength="160" placeholder="Name" style="width:100%;"></div>'
      + '<div><label class="dr-l">Time until child located (seconds)</label><input class="dr-i" id="dr-loc" type="number" min="0" style="width:100%;"></div>'
      + '</div><div class="dr-g" style="margin-top:10px;"><div><label class="dr-l">Scenario</label><input class="dr-i" id="dr-scn" maxlength="2000" placeholder="What was practised" style="width:100%;"></div>'
      + '<div><label class="dr-l">What to improve</label><input class="dr-i" id="dr-imp" maxlength="2000" placeholder="Lessons learned" style="width:100%;"></div></div>'
      + '<div class="dr-row" style="margin-top:6px;"><label class="dr-ck"><input type="checkbox" id="dr-911"> 911 call simulated</label><label class="dr-ck"><input type="checkbox" id="dr-pc"> Parent call simulated</label></div></div>'
      + '<div id="dr-msg" style="font-size:13px;color:#B91C1C;margin-top:8px;"></div>';
    var $ = function (id) { return body.querySelector('#' + id); };
    function fillRooms() {
      var cid = $('dr-c').value;
      $('dr-r').innerHTML = '<option value="">' + esc(KT.termText ? KT.termText('Whole centre') : 'Whole centre') + '</option>' + (d.rooms || []).filter(function (r) { return String(r.centre_id) === String(cid); }).map(function (r) { return '<option value="' + r.id + '">' + esc(r.name) + '</option>'; }).join('');
    }
    $('dr-c').addEventListener('change', fillRooms); fillRooms();
    function typeFields() { var mc = $('dr-t').value === 'missing_child'; $('dr-mc').hidden = !mc; $('dr-s-w').style.display = mc ? 'none' : ''; }
    $('dr-t').addEventListener('change', typeFields); typeFields();
    KT.Shell.Modal.open({ title: '🚒 Record a drill', body: body, large: true, actions: [
      { label: 'Cancel' },
      { label: 'Record drill', primary: true, busyLabel: 'Saving…', handler: async function () {
        var mc = $('dr-t').value === 'missing_child'; $('dr-msg').textContent = '';
        try {
          await Api.post('/drills', { centre_id: +$('dr-c').value, room_id: $('dr-r').value ? +$('dr-r').value : null, type: $('dr-t').value, drilled_at: $('dr-at').value,
            evacuation_seconds: mc || $('dr-s').value === '' ? null : +$('dr-s').value, children_count: $('dr-k').value === '' ? null : +$('dr-k').value,
            staff_present: $('dr-st').value.trim() || null, notes: $('dr-n').value.trim() || null,
            area: mc ? ($('dr-area').value.trim() || null) : null, led_by: mc ? ($('dr-led').value.trim() || null) : null,
            located_seconds: mc && $('dr-loc').value !== '' ? +$('dr-loc').value : null, scenario: mc ? ($('dr-scn').value.trim() || null) : null,
            improvements: mc ? ($('dr-imp').value.trim() || null) : null, simulated_911: mc ? $('dr-911').checked : null, simulated_parent_call: mc ? $('dr-pc').checked : null });
        } catch (e) { $('dr-msg').textContent = (e && e.message) || 'Could not save.'; return false; }
        toast('🚒', 'Drill recorded', '', '#16A34A'); again();
      } },
    ] });
  }
  function monthName(ym) { var m = /^(\d{4})-(\d{2})$/.exec(ym || ''); return m ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][+m[2] - 1] + ' ' + m[1] : (ym || ''); }

  // Everything recorded for one drill, in a popup (the table keeps to one line per drill).
  function drillDetails(r, d, again) {
    if (!r || !KT.Shell.Modal) return;
    var t = TYPE[r.type] || TYPE.other, mc = r.type === 'missing_child';
    var yn = function (v) { return v ? 'Yes' : (v === 0 || v === false ? 'No' : ''); };
    var kv = [['When', whenLocal(r.local)], ['Centre', r.centre_name], ['Room', r.room_name],
      [mc ? 'Child located in' : 'Evacuation time', (mc ? r.located_seconds : r.evacuation_seconds) != null ? mmss(mc ? r.located_seconds : r.evacuation_seconds) : ''],
      ['Children present', r.children_count != null ? String(r.children_count) : ''], ['Staff present', r.staff_present],
      ['Area', r.area], ['Scenario', r.scenario], ['Led by', r.led_by], ['911 call simulated', yn(r.simulated_911)], ['Parent call simulated', yn(r.simulated_parent_call)],
      ['What to improve', r.improvements], ['Notes', r.notes], ['Recorded by', r.recorded_by_name]]
      .filter(function (x) { return x[1] != null && String(x[1]) !== ''; });
    if (r.may_remove && !r.can_delete && r.kept_until) kv.push(['Kept until', r.kept_until + ' (drill records are kept 12 months)']);
    var body = document.createElement('div');
    body.innerHTML = '<div style="margin-bottom:12px;">' + chip([t[1], t[2], t[0] + ' ' + (r.type_label || r.type) + ' drill']) + '</div>'
      + '<div class="dr-kv">' + kv.map(function (x) { return '<b>' + esc(x[0]) + '</b><span style="white-space:pre-wrap;overflow-wrap:anywhere;">' + esc(x[1]) + '</span>'; }).join('') + '</div>';
    var acts = [{ label: 'Close' }];
    // Remove lives here now that each drill is one line on the Drills tab (the server keeps 12 months and says so).
    if (r.can_delete) acts.unshift({ label: '🗑 Remove', busyLabel: 'Removing…', handler: async function () { return (await removeDrill(r, again)) ? undefined : false; } });
    // The map of the person who recorded it (their own, else their centre's).
    var by = (d.educators || []).find(function (e) { return e.user_id === r.recorded_by && e.map; });
    if (by) acts.unshift({ label: '🗺️ Drill map', handler: function () { setTimeout(function () { showMap(by.map, 'Drill map · ' + by.name.split(' ')[0]); }, 50); } });
    else if (mapsByCentre(d)[r.centre_id]) acts.unshift({ label: '🗺️ Centre map', handler: function () { setTimeout(function () { openMap(d, r.centre_id); }, 50); } });
    KT.Shell.Modal.open({ title: (r.type_label || 'Drill') + ' drill · ' + whenParts(r.local)[0], body: body, actions: acts });
  }

  /* ── 👩‍🏫 EDUCATORS ────────────────────────────────────────────────── */
  function paintEducators(pane, d, again) {
    var list = d.educators || [];
    var h = '<div class="dr-card"><div class="dr-h">Educators</div>'
      + '<div class="dr-sub">' + (d.can_manage ? 'Each person’s fire drill training dates, when they run their fire drill, and their drill map. Set them from each row’s ⋮.' : 'Your fire drill training dates, when you run your fire drill, and your drill map. A director keeps these up to date.') + '</div>';
    if (!list.length) {
      h += '<div style="color:#64748B;font-size:13px;padding:6px 0;">No educators at ' + (state.centre ? 'this centre' : 'your centres') + ' yet.</div></div>';
      pane.innerHTML = h; return;
    }
    var none = '<span style="color:#94A3B8;">—</span>';
    h += '<div style="overflow-x:auto;"><table class="dr-t"><thead><tr><th>Name</th><th>Centre</th><th>Training</th><th>Status</th><th>Fire drill schedule</th><th>Map</th><th></th></tr></thead><tbody>'
      + list.map(function (e, i) {
          var m = e.map;
          var acts = (m ? '<button type="button" class="dr-b" data-emap="' + i + '" style="height:28px;font-size:12px;">🗺️ View ' + (m.own ? 'map' : 'centre map') + '</button>' : '');
          if (e.can_edit) {
            acts += '<button type="button" class="dr-b" data-eup="' + i + '" style="height:28px;font-size:12px;">' + (m && m.own ? '🔄 Replace map' : '⬆️ Add a map') + '</button>'
              + (m && m.own ? '<button type="button" class="dr-b" data-erm="' + m.id + '" style="height:28px;font-size:12px;">🗑 Remove map</button>' : '')
              + '<button type="button" class="dr-b" data-esched="' + i + '" style="height:28px;font-size:12px;">🔥 Set fire drill schedule</button>'
              + '<button type="button" class="dr-b" data-edates="' + i + '" style="height:28px;font-size:12px;">📅 Set training dates</button>';
          }
          return '<tr><td><div style="font-weight:800;color:#0F172A;">' + esc(e.name) + '</div><div class="dr-d2">' + esc(e.role) + '</div></td>'
            + '<td>' + esc(e.centre_name) + '</td>'
            + '<td>' + (e.renewed_on || e.expires_on
                ? '<div class="dr-d2">Renewed ' + (e.renewed_on ? '<b style="color:#0F172A;">' + esc(day(e.renewed_on)) + '</b>' : '—') + '</div>'
                  + '<div class="dr-d2">Expires ' + (e.expires_on ? '<b style="color:#0F172A;">' + esc(day(e.expires_on)) + '</b>' : '—') + '</div>'
                : none) + (e.note ? '<div class="dr-note" title="' + esc(e.note) + '">' + esc(e.note) + '</div>' : '') + '</td>'
            + '<td>' + chip(STAFF[e.state] || STAFF.none) + '</td>'
            + '<td>' + (e.schedule ? '<div style="font-weight:700;font-size:13px;">' + esc(e.schedule.label) + '</div>'
                + '<div class="dr-d2">' + (e.next_label ? 'Next: ' + esc(e.next_label) : 'No upcoming date') + '</div>' : none) + '</td>'
            + '<td>' + (m ? '<span class="dr-d2">' + (m.own ? '🗺️ Own map' : '🏫 Centre map') + '</span>' : none) + '</td>'
            + '<td style="text-align:center;white-space:nowrap;">' + acts + '</td></tr>';
        }).join('') + '</tbody></table></div>'
      + '<input type="file" id="dr-map-file" accept="image/jpeg,image/png,image/webp,application/pdf" hidden></div>';
    pane.innerHTML = h;
    var first = function (e) { return String(e.name || '').split(' ')[0]; };

    pane.querySelectorAll('[data-emap]').forEach(function (b) {
      b.addEventListener('click', function () { var e = list[+b.getAttribute('data-emap')]; showMap(e.map, (e.map.own ? 'Drill map · ' + first(e) : 'Centre map · ' + e.centre_name)); });
    });

    /* EACH EDUCATOR'S MAP (2026-11-09, Anthony: "drill log in settings remove the centres maps ... allow to be added
       from the educators kebab by management as optional"). A director of their centre or an admin adds or replaces
       it; a replaced or removed map is kept in the records (DrillController::uploadMap / removeMap). */
    var mapFile = pane.querySelector('#dr-map-file'), mapFor = null;
    pane.querySelectorAll('[data-eup]').forEach(function (b) {
      b.addEventListener('click', function () { mapFor = list[+b.getAttribute('data-eup')]; mapFile.value = ''; mapFile.click(); });
    });
    mapFile.addEventListener('change', async function () {
      var f = mapFile.files && mapFile.files[0]; if (!f || !mapFor) return;
      if (f.size > 15 * 1024 * 1024) { toast('⚠️', 'That file is too big', 'The map must be 15 MB or smaller.', '#DC2626'); return; }
      var fd = new FormData(); fd.append('user_id', mapFor.user_id); fd.append('file', f);
      toast('🗺️', 'Uploading the map…', '', '#0EA5E9');
      try { await Api.postForm('/drills/maps', fd); toast('🗺️', 'Map saved for ' + first(mapFor), '', '#16A34A'); again(); }
      catch (x) { toast('⚠️', 'Could not upload the map', (x && x.message) || '', '#DC2626'); }
    });
    pane.querySelectorAll('[data-erm]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var ok = KT.confirm ? await KT.confirm({ title: 'Remove this drill map?', description: 'It comes off the Drill log. A copy is kept in the records.', okLabel: 'Remove' }) : true;
        if (!ok) return;
        try { await Api.delete('/drills/maps/' + b.getAttribute('data-erm')); toast('🗺️', 'Map removed', '', '#16A34A'); again(); }
        catch (x) { toast('⚠️', 'Could not remove', (x && x.message) || '', '#DC2626'); }
      });
    });

    /* EACH EDUCATOR'S FIRE DRILL SCHEDULE (2026-11-09, Anthony: "the fire drill schedule needs to be configurable to set
       a specific time/day for each educator"). Weekly, monthly (Nth or last weekday), every 2 or 3 months, or one date.
       Reminders and missed-drill notices follow Settings → Notifications (drills:educator-schedule). */
    pane.querySelectorAll('[data-esched]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var e = list[+b.getAttribute('data-esched')], s = e.schedule || {}, o = d.schedule_options || {};
        var opts = function (obj) { return Object.keys(obj || {}).map(function (k) { return { value: k, label: obj[k] }; }); };
        var v = await KT.prompt({ title: 'Fire drill schedule · ' + first(e),
          description: 'When ' + first(e) + ' runs their fire drill (agency time). Only the questions for the chosen repeat are shown.',
          okLabel: 'Save schedule',
          fields: [
            { key: 'repeat', label: 'Repeats', type: 'select', value: s.repeat || 'monthly', options: [{ value: '', label: 'No schedule' }].concat(opts(o.repeats)) },
            { key: 'week', label: 'Week of the month', type: 'select', value: String(s.week || 1), options: opts(o.weeks), showWhen: { field: 'repeat', in: ['monthly', 'every_2_months', 'quarterly'] } },
            { key: 'weekday', label: 'Day of the week', type: 'select', value: String(s.weekday || 2), options: opts(o.weekdays), showWhen: { field: 'repeat', in: ['weekly', 'monthly', 'every_2_months', 'quarterly'] } },
            { key: 'date', label: 'Date (once) · first month (every 2 or 3 months)', type: 'date', value: s.date || '', showWhen: { field: 'repeat', in: ['every_2_months', 'quarterly', 'once'] } },
            { key: 'time', label: 'Time', type: 'time', value: s.time || '10:00', showWhen: { field: 'repeat', in: ['weekly', 'monthly', 'every_2_months', 'quarterly', 'once'] } },
          ] });
        if (!v) return;
        try {
          await Api.post('/drills/staff-schedule', { user_id: e.user_id, repeat: v.repeat || null, week: v.week ? +v.week : null, weekday: v.weekday ? +v.weekday : null,
            date: v.date || null, time: v.time || null });
          toast('🔥', v.repeat ? 'Fire drill schedule saved for ' + first(e) : 'Schedule cleared for ' + first(e), '', '#16A34A'); again();
        } catch (x) { toast('⚠️', 'Could not save', (x && x.message) || '', '#DC2626'); }
      });
    });

    pane.querySelectorAll('[data-edates]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var e = list[+b.getAttribute('data-edates')];
        var v = await KT.prompt({ title: 'Fire drill training · ' + e.name, description: 'When ' + first(e) + ' last renewed their fire drill training, and when it expires.', okLabel: 'Save dates',
          fields: [
            { key: 'renewed_on', label: 'Renewed on', type: 'date', value: e.renewed_on || '' },
            { key: 'expires_on', label: 'Expires on', type: 'date', value: e.expires_on || '' },
            { key: 'note', label: 'Note (optional)', placeholder: 'e.g. Fire warden course, certificate on file', value: e.note || '' },
          ] });
        if (!v) return;
        try {
          await Api.post('/drills/staff-dates', { user_id: e.user_id, renewed_on: v.renewed_on || null, expires_on: v.expires_on || null, note: (v.note || '').trim() || null });
          toast('📅', 'Dates saved for ' + first(e), '', '#16A34A'); again();
        } catch (x) { toast('⚠️', 'Could not save', (x && x.message) || '', '#DC2626'); }
      });
    });
  }

  /* ── ⚙️ SETTINGS ─────────────────────────────────────────────────── */
  function paintSettings(pane, d, isAdmin, again) {
    var h = '';
    // Ontario: the fire drill schedule per centre (monthly, or 3 a term for a school-based program).
    var cads = d.ontario ? (d.status || []).filter(function (c) { return c.can_manage; }) : [];
    if (cads.length) {
      h += '<div class="dr-card"><div class="dr-h">🔥 Centre fire drill rule</div><div class="dr-sub">How often each centre must run a fire drill. Each educator’s own day and time is set on the Educators tab.</div>'
        + cads.map(function (c) {
            return '<div class="dr-row" style="justify-content:space-between;padding:8px 0;border-top:1px solid #F1F5F9;"><div style="font-weight:700;font-size:13.5px;">' + esc(c.centre_name) + '</div>'
              + '<select class="dr-i" data-cad="' + c.centre_id + '" aria-label="Fire drills at ' + esc(c.centre_name) + '" style="width:auto;max-width:300px;">'
              + Object.keys(d.fire_cadences || {}).map(function (k) { return '<option value="' + k + '"' + (k === c.fire_cadence ? ' selected' : '') + '>' + esc(d.fire_cadences[k]) + '</option>'; }).join('') + '</select></div>';
          }).join('') + '</div>';
    }
    /* NOTIFICATIONS (2026-11-09, Anthony: "settings should include notifications to be sent to admin staff (optional
       with settings) including educators (optional with settings)"). Who hears about drills at all (the same per-role
       switch as Settings → Notification settings), each educator's reminder before their drill, and what directors
       and admins are told. Agency admins change these (POST /drills/notify-settings). */
    var n = d.notify || null;
    if ((d.is_admin != null ? d.is_admin : isAdmin) && n) {
      var ck = function (id, on, text) { return '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;cursor:pointer;padding:5px 0;"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + ' style="margin-top:3px;"> <span>' + text + '</span></label>'; };
      var sec = function (t) { return '<div style="font-weight:800;font-size:12px;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 2px;">' + t + '</div>'; };
      h += '<div class="dr-card"><div class="dr-h">🔔 Notifications</div><div class="dr-sub">Bell, phone and email. Each one is optional.</div>'
        + sec('Who hears about drills')
        + Object.keys(n.role_labels || {}).map(function (r) { return ck('dr-nr-' + r, n.roles && n.roles[r], esc(n.role_labels[r]) + ' <span style="color:#64748B;">— drill recorded, due and scheduled notices</span>'); }).join('')
        + sec('Educators')
        + ck('dr-n-er', n.educator_reminder, 'Remind each educator before their scheduled fire drill')
        + '<div class="dr-row" style="padding:2px 0 4px 26px;"><span style="font-size:13px;color:#475569;">When:</span><select class="dr-i" id="dr-n-lead" aria-label="When to remind educators" style="width:auto;"' + (n.educator_reminder ? '' : ' disabled') + '>'
        + Object.keys(n.leads || {}).map(function (k) { return '<option value="' + k + '"' + (k === n.educator_lead ? ' selected' : '') + '>' + esc(n.leads[k]) + '</option>'; }).join('') + '</select></div>'
        + sec('Directors & admins')
        + ck('dr-n-au', n.admin_upcoming, 'Tell their centre’s directors and the agency admins the morning of each educator’s scheduled drill')
        + ck('dr-n-am', n.admin_missed, 'Tell them when a scheduled fire drill was not recorded (2 hours after it was due)')
        + ck('dr-rem', d.reminders, 'Email directors on weekday mornings when a drill is still owed or was missed')
        + (d.ontario ? ck('dr-crem', d.cadence_reminders, 'Ontario schedule reminders: tell directors when a missing-child drill is due within 14 days or overdue, or a fire drill is overdue — once per change, admins on anything overdue') : '')
        + '</div>';
    }
    if (!h) h = '<div class="dr-card" style="color:#64748B;font-size:13px;">Nothing to set up here for your centres.</div>';
    pane.innerHTML = h;

    pane.querySelectorAll('[data-cad]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        Api.post('/drills/cadence', { centre_id: +sel.getAttribute('data-cad'), fire: sel.value }).then(function () { toast('🚒', 'Fire drill rule saved', '', '#16A34A'); again(); })
          .catch(function (e) { toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); again(); });
      });
    });
    var saveNotify = function (body, input, undo) {
      Api.post('/drills/notify-settings', body).then(function (r) { if (r && r.notify) d.notify = r.notify; toast('🔔', 'Notifications saved', '', '#16A34A'); })
        .catch(function (e) { undo(); toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    };
    Object.keys((n && n.role_labels) || {}).forEach(function (r) {
      var x = pane.querySelector('#dr-nr-' + r); if (!x) return;
      x.addEventListener('change', function () { var roles = {}; roles[r] = x.checked; saveNotify({ roles: roles }, x, function () { x.checked = !x.checked; }); });
    });
    [['dr-n-er', 'educator_reminder'], ['dr-n-au', 'admin_upcoming'], ['dr-n-am', 'admin_missed']].forEach(function (p) {
      var x = pane.querySelector('#' + p[0]); if (!x) return;
      x.addEventListener('change', function () {
        var body = {}; body[p[1]] = x.checked;
        if (p[0] === 'dr-n-er') { var l = pane.querySelector('#dr-n-lead'); if (l) l.disabled = !x.checked; }
        saveNotify(body, x, function () { x.checked = !x.checked; });
      });
    });
    var lead = pane.querySelector('#dr-n-lead');
    if (lead) lead.addEventListener('change', function () { var was = (d.notify || {}).educator_lead; saveNotify({ educator_lead: lead.value }, lead, function () { lead.value = was; }); });
    var crem = pane.querySelector('#dr-crem');
    if (crem) crem.addEventListener('change', function () {
      Api.post('/drills/cadence-reminders', { enabled: crem.checked }).then(function () { toast('🔔', crem.checked ? 'Schedule reminders on' : 'Schedule reminders off', '', '#16A34A'); })
        .catch(function (e) { crem.checked = !crem.checked; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    });
    var rem = pane.querySelector('#dr-rem');
    if (rem) rem.addEventListener('change', function () {
      Api.post('/drills/reminders', { enabled: rem.checked }).then(function () { toast('🔔', rem.checked ? 'Drill reminders on' : 'Drill reminders off', '', '#16A34A'); })
        .catch(function (e) { rem.checked = !rem.checked; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    });
  }

  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) { KT.Shell.registerScreen(r + ':drills', render); });
})(window);
