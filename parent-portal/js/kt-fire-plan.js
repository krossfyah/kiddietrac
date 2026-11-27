/* ═══════════════════════════════════════════════════════════════════════
   FIRE PLAN (2026-11-28) — the Fire plan tab of the Drill log.

   Anthony: "fire drills should allow educators to draw out a fire plan and submit this with details on the fire
   plan - can you wire this up correctly with a tree fire drill plan". He wanted BOTH:
     1. a drawn floor plan of the home, centre or room (walls, rooms, pen, stamps for exits / extinguisher / smoke
        alarm / meeting spot / first aid / panel / hazard, a green primary route and amber alternate routes, text,
        undo/redo, erase, zoom and pan with a finger, a template to start from, a photo to trace over), saved as
        vector JSON (editable later) plus a PNG snapshot (viewing and print);
     2. a "what we do" decision tree: who, what and where for each step, with branches ("if the primary exit is
        blocked", "if a child is missing"), drawn as a real flow diagram on screen and on paper.
   Plus the plan details (children and ages, staff, exits, date reviewed, notes). A plan belongs to a centre / home
   and optionally a room, and is versioned: a submitted version never changes, editing makes the next one. A
   director of that centre or an agency admin approves it or asks for changes. Server: FirePlanController.

   KT.FirePlan.paintTab(pane, ctx)      the tab (places, review queue) - ctx {centre, again}
   KT.FirePlan.editor(opts)             full-window editor -> Promise<{id, version, status}|null>
                                        opts {centre_id, room_id, base (a plan to edit), draft (own draft id)}
   KT.FirePlan.view(id, opts)           the plan in the house popup (drawing, legend, diagram, details, versions)
   KT.FirePlan.print(id, layout)        KT.printDoc: 'full' (drawing + legend, then tree + details) | 'post'
   KT.FirePlan.templateCard(el)         Settings tab: the steps every new plan starts from (agency admins)
   KT.FirePlan.list(centreId)           GET /drills/fire-plans (cached briefly) - for the drill form
   KT.FirePlan.flowHtml(tree)           the decision tree as a flow diagram (HTML boxes and arrows)

   The editor is its own full-window layer (data-no-modal-guard; it paints the house header band and keeps its ✕
   inside the band) because it opens on top of the Record-a-drill popup, and Shell.Modal has one #modalRoot.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (KT.FirePlan) return;
  var document = window.document;

  function Api() { return KT.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function term(s) { return KT.termText ? KT.termText(s) : s; }
  function said(e, d) { return (e && (e.message || (e.data && e.data.message))) || d; }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function todayLocal(tz) {
    try {
      var p = {}; new Intl.DateTimeFormat('en-CA', { timeZone: tz || undefined, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
      return p.year + '-' + p.month + '-' + p.day;
    } catch (e) { return new Date().toISOString().slice(0, 10); }
  }
  function apiBase() { return (KT.API_BASE || 'https://api.kiddietrac.com/api/v1').replace(/\/$/, ''); }

  /* ── what the drawing can hold ─────────────────────────────────────── */
  var STAMPS = [
    { k: 'exit', e: '🚪', label: 'Exit', ring: '#16A34A' },
    { k: 'ext', e: '🧯', label: 'Fire extinguisher', ring: '#DC2626' },
    { k: 'alarm', e: '🔔', label: 'Smoke alarm', ring: '#D97706' },
    { k: 'meet', e: '🚩', label: 'Meeting spot', ring: '#DC2626' },
    { k: 'aid', e: '🧰', label: 'First-aid kit', ring: '#0D9488' },
    { k: 'panel', e: '⚡', label: 'Electrical panel', ring: '#CA8A04' },
    { k: 'hazard', e: '🔥', label: 'Hazard', ring: '#EA580C' },
  ];
  var STAMP = {}; STAMPS.forEach(function (s) { STAMP[s.k] = s; });
  var ROUTE = { primary: { c: '#16A34A', label: 'Primary route' }, alt: { c: '#F59E0B', label: 'Alternate route' } };
  var EMOJI_FONT = '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Segoe UI Symbol",sans-serif';
  var W0 = 1200, H0 = 800, G0 = 20;

  /* ── CSS (one block, light and dark) ───────────────────────────────── */
  var CSS_ID = 'kt-fireplan-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    var D = 'html.kt-dark ,html[data-theme="dark"] ';
    function dk(sel, body) { return sel.split(',').map(function (x) { return 'html.kt-dark ' + x.trim() + ',html[data-theme="dark"] ' + x.trim(); }).join(',') + '{' + body + '}'; }
    s.textContent = ''
      /* the editor layer */
      + '.fpe{position:fixed;inset:0;z-index:2147481500;background:rgba(15,23,42,.55);display:flex;align-items:stretch;justify-content:center;padding:12px;box-sizing:border-box;}'
      + '.fpe-card{background:#fff;border-radius:14px;width:100%;max-width:none;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,.35);min-width:0;}'
      + '.fpe-head{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:10px 12px 10px 16px;color:#fff;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '.fpe-head h2{margin:0;font-size:16px;font-weight:800;color:#fff;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.fpe-head small{display:block;font-size:12px;font-weight:600;opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.fpe-x{flex:0 0 auto;width:32px;height:32px;border-radius:8px;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:18px;line-height:1;cursor:pointer;}'
      + '.fpe-x:hover{background:rgba(255,255,255,.26);}'
      + '.fpe-steps{flex:0 0 auto;display:flex;gap:4px;padding:6px 10px 0;border-bottom:1px solid #E2E8F0;background:#F8FAFC;overflow:hidden;}'
      + '.fpe-steps button{border:0;background:none;padding:8px 12px;font-size:13px;font-weight:700;color:#64748B;border-bottom:2px solid transparent;cursor:pointer;white-space:nowrap;}'
      + '.fpe-steps button[aria-selected="true"]{color:#0F172A;border-bottom-color:#B91C1C;}'
      + '.fpe-body{flex:1 1 auto;min-height:0;position:relative;display:flex;flex-direction:column;}'
      + '.fpe-pane{flex:1 1 auto;min-height:0;display:none;}'
      + '.fpe-pane.on{display:flex;flex-direction:column;}'
      + '.fpe-scroll{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;padding:14px 16px;}'
      + '.fpe-foot{flex:0 0 auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid #E2E8F0;background:#F8FAFC;}'
      + '.fpe-foot .fpe-note{flex:1;min-width:140px;font-size:12.5px;color:#64748B;}'
      + '.fp-b{height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#0F172A;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;}'
      + '.fp-b:hover{background:#F1F5F9;}.fp-b[disabled]{opacity:.55;cursor:default;}'
      + '.fp-b.p{background:#B91C1C;border-color:#B91C1C;color:#fff;}.fp-b.p:hover{background:#991B1B;}'
      + '.fp-b.ok{background:#047857;border-color:#047857;color:#fff;}'
      /* toolbar */
      + '.fpt{flex:0 0 auto;display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:6px 8px;border-bottom:1px solid #E2E8F0;background:#fff;}'
      + '.fpt-g{display:flex;gap:3px;align-items:center;flex-wrap:wrap;}'
      + '.fpt-sep{width:1px;height:24px;background:#E2E8F0;margin:0 3px;}'
      + '.fpt button{min-width:32px;height:32px;padding:0 6px;border-radius:8px;border:1px solid #E2E8F0;background:#fff;color:#0F172A;font-size:15px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:4px;touch-action:manipulation;}'
      + '.fpt button .fpt-l{font-size:12px;font-weight:700;}'
      + '.fpt button[aria-pressed="true"]{background:#E0F2FE;border-color:#0284C7;box-shadow:inset 0 0 0 1px #0284C7;}'
      + '.fpt button:disabled{opacity:.4;cursor:default;}'
      + '.fpt .fpt-sw{display:inline-block;width:18px;height:5px;border-radius:3px;}'
      + '.fpt input[type=range]{width:90px;}'
      + '.fpt-stamps{flex:0 0 auto;display:none;gap:4px;flex-wrap:wrap;padding:6px 8px;border-bottom:1px solid #E2E8F0;background:#F8FAFC;}'
      + '.fpt-stamps.on{display:flex;}'
      + '.fpt-stamps button{height:30px;padding:0 8px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;color:#0F172A;}'
      + '.fpt-stamps button[aria-pressed="true"]{border-color:#0284C7;background:#E0F2FE;}'
      + '.fpc-wrap{flex:1 1 auto;min-height:0;position:relative;background:#EEF2F7;overflow:hidden;}'
      + '.fpc-wrap canvas{position:absolute;inset:0;width:100%;height:100%;touch-action:none;display:block;}'
      + '.fpc-hint{position:absolute;left:10px;bottom:8px;font-size:11.5px;color:#475569;background:rgba(255,255,255,.88);padding:3px 8px;border-radius:6px;pointer-events:none;max-width:calc(100% - 20px);}'
      + '@media (max-width:640px){.fpe{padding:0;}.fpe-card{border-radius:0;}.fpt button .fpt-l{display:none;}.fpe-steps button{padding:8px 9px;}.fpc-hint{display:none;}'
      /* phones: the portal's phone sheet enlarges buttons; the palette and footer must stay one compact row each */
      + '.fpt-stamps{gap:3px;padding:4px 6px;}.fpt-stamps button{height:34px !important;min-height:0 !important;padding:0 7px !important;font-size:18px !important;}.fpt-stamps button span{display:none;}'
      + '.fpe-foot{flex-wrap:nowrap;gap:6px;}.fpe-foot .fpe-note{display:none;}.fpe-foot .fp-b{flex:1 1 0;min-width:0;padding:0 6px !important;font-size:13px !important;height:36px !important;min-height:0 !important;overflow:hidden;text-overflow:ellipsis;}}'
      /* forms */
      + '.fp-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));gap:10px;}'
      + '.fp-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.fp-i{width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;color:#0F172A;}'
      + 'textarea.fp-i{height:auto;padding:6px 10px;resize:vertical;font-family:inherit;}'
      + '.fp-h{font-weight:800;font-size:15px;color:#0F172A;margin:0 0 2px;}'
      + '.fp-sub{font-size:12.5px;color:#64748B;margin:0 0 10px;}'
      + '.fp-2col{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;}'
      + '@media (max-width:1000px){.fp-2col{grid-template-columns:minmax(0,1fr);}}'
      + '.fp-step{border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;margin-bottom:8px;background:#fff;}'
      + '.fp-step.br{border-left:4px solid #F59E0B;margin-left:22px;background:#FFFBEB;}'
      + '.fp-step-h{display:flex;gap:8px;align-items:baseline;margin-bottom:4px;}'
      + '.fp-n{flex:0 0 auto;width:22px;height:22px;border-radius:50%;background:#1F6080;color:#fff;font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;}'
      + '.fp-step.br .fp-n{background:#B45309;}'
      + '.fp-step-t{font-weight:800;font-size:13.5px;color:#0F172A;}'
      + '.fp-step-hint{font-size:12px;color:#64748B;margin:0 0 6px 30px;}'
      + '.fp-wwh{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) minmax(0,1fr);gap:6px;margin-left:30px;}'
      + '@media (max-width:640px){.fp-wwh{grid-template-columns:minmax(0,1fr);margin-left:0;}.fp-step-hint{margin-left:0;}.fp-step.br{margin-left:8px;}}'
      + '.fp-staff{display:flex;flex-wrap:wrap;gap:6px 14px;}'
      + '.fp-staff label{display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer;color:#0F172A;}'
      /* flow diagram */
      + '.fpf{--fpf-ink:#0F172A;--fpf-mute:#475569;--fpf-node:#fff;--fpf-edge:#1F6080;--fpf-line:#64748B;--fpf-br:#FFFBEB;--fpf-bre:#D97706;--fpf-n:#1F6080;max-width:860px;}'
      + '.fpf-row{display:grid;grid-template-columns:minmax(0,1fr) 40px minmax(0,.85fr);align-items:center;}'
      + '.fpf-row.solo{grid-template-columns:minmax(0,1fr);}'
      + '.fpf-node{position:relative;border:1.5px solid var(--fpf-edge);background:var(--fpf-node);border-radius:10px;padding:8px 10px 8px 40px;color:var(--fpf-ink);min-width:0;}'
      + '.fpf-node .no{position:absolute;left:9px;top:8px;width:22px;height:22px;border-radius:50%;background:var(--fpf-n);color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;}'
      + '.fpf-node b{display:block;font-size:13px;line-height:1.25;}'
      + '.fpf-node .ln{font-size:12px;color:var(--fpf-mute);margin-top:2px;overflow-wrap:anywhere;}'
      + '.fpf-node .ln i{font-style:normal;font-weight:800;color:var(--fpf-ink);}'
      + '.fpf-node .ln.empty{font-style:italic;}'
      + '.fpf-br{border-style:dashed;border-color:var(--fpf-bre);background:var(--fpf-br);padding-left:12px;}'
      + '.fpf-br b{color:var(--fpf-ink);}'
      + '.fpf-h{position:relative;height:2px;background:var(--fpf-bre);margin:0 4px;}'
      + '.fpf-h:after{content:"";position:absolute;right:-2px;top:-5px;border-left:8px solid var(--fpf-bre);border-top:6px solid transparent;border-bottom:6px solid transparent;}'
      + '.fpf-h span{position:absolute;left:0;right:0;top:-17px;text-align:center;font-size:10px;font-weight:800;color:var(--fpf-bre);}'
      + '.fpf-v{height:22px;position:relative;width:calc((100% - 40px) * 1 / 1.85);}'
      + '.fpf-v.solo{width:100%;}'
      + '.fpf-v:before{content:"";position:absolute;left:50%;top:0;bottom:6px;width:2px;margin-left:-1px;background:var(--fpf-line);}'
      + '.fpf-v:after{content:"";position:absolute;left:50%;bottom:0;margin-left:-6px;border-top:8px solid var(--fpf-line);border-left:6px solid transparent;border-right:6px solid transparent;}'
      + '@media (max-width:640px){.fpf-row{grid-template-columns:minmax(0,1fr);}.fpf-h{display:none;}.fpf-row .fpf-br{margin:6px 0 0 18px;}.fpf-row .fpf-br:before{content:"↳ ";color:var(--fpf-bre);font-weight:800;}.fpf-v{width:100%;}}'
      + dk('.fpf', '--fpf-ink:#E2E8F0;--fpf-mute:#94A3B8;--fpf-node:#0F172A;--fpf-edge:#38BDF8;--fpf-line:#94A3B8;--fpf-br:#1C1917;--fpf-bre:#F59E0B;--fpf-n:#0284C7;')
      /* the plan in the popup and tab */
      + '.fpv-img{display:block;width:100%;max-height:62vh;object-fit:contain;background:#fff;border:1px solid #E2E8F0;border-radius:10px;cursor:zoom-in;padding:0;}'
      + '.fpv-none{height:140px;display:flex;align-items:center;justify-content:center;background:#F8FAFC;border:1.5px dashed #CBD5E1;border-radius:10px;color:#64748B;font-size:13px;}'
      + '.fp-leg{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:12px;color:#334155;margin:8px 0 2px;}'
      + '.fp-leg span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;}'
      + '.fp-leg .sw{display:inline-block;width:20px;height:5px;border-radius:3px;}'
      + '.fp-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;}'
      + '.fp-kv{display:grid;grid-template-columns:minmax(120px,auto) 1fr;gap:6px 14px;font-size:13.5px;}'
      + '.fp-kv b{color:#475569;font-weight:700;}'
      + '.fp-sec{font-weight:800;font-size:12px;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin:16px 0 8px;}'
      + '.fp-comment{background:#FEF2F2;border:1px solid #FECACA;color:#7F1D1D;border-radius:10px;padding:8px 12px;font-size:13px;margin-top:10px;white-space:pre-wrap;}'
      + '.fp-vers{display:flex;flex-direction:column;gap:4px;}'
      + '.fp-ver{display:flex;gap:10px;align-items:center;flex-wrap:wrap;text-align:left;border:1px solid #E2E8F0;border-radius:8px;padding:6px 10px;background:#fff;font:inherit;font-size:13px;color:#334155;cursor:pointer;}'
      + '.fp-ver[aria-current="true"]{border-color:#1F6080;box-shadow:inset 0 0 0 1px #1F6080;}'
      + '.fp-thumb{width:64px;height:42px;object-fit:cover;border-radius:6px;border:1px solid #E2E8F0;background:#fff;display:block;}'
      + '.fp-thumb.none{display:flex;align-items:center;justify-content:center;font-size:18px;color:#94A3B8;}'
      + '.fp-place{font-weight:800;color:#0F172A;}'
      + '.fp-q{border:1px solid #FDE68A;background:#FFFBEB;border-radius:10px;padding:10px 12px;margin-bottom:12px;}'
      + dk('.fpe-card,.fpt,.fp-step,.fp-ver,.fpt button,.fpt-stamps button,.fp-b', 'background:#0F172A;color:#E2E8F0;border-color:#334155;')
      + dk('.fpe-steps,.fpe-foot,.fpt-stamps', 'background:#111827;border-color:#334155;')
      + dk('.fp-i', 'background:#0B1220;color:#E2E8F0;border-color:#334155;')
      + dk('.fp-h,.fp-step-t,.fp-place,.fpe-steps button[aria-selected="true"],.fp-staff label', 'color:#F1F5F9;')
      + dk('.fp-l,.fp-kv b,.fp-sec,.fp-leg', 'color:#94A3B8;')
      + dk('.fp-step.br', 'background:#1C1917;')
      + dk('.fp-q', 'background:#1C1917;border-color:#78350F;')
      + dk('.fp-comment', 'background:#2A0F12;border-color:#7F1D1D;color:#FECACA;')
      + dk('.fpc-wrap', 'background:#1E293B;')
      + dk('.fpt button[aria-pressed="true"],.fpt-stamps button[aria-pressed="true"]', 'background:#0C4A6E;border-color:#38BDF8;');
    document.head.appendChild(s);
  }

  /* ── drawing: geometry, rendering, export ─────────────────────────── */
  function emptyDrawing() { return { v: 1, w: W0, h: H0, grid: G0, underlay: null, items: [] }; }

  function wall(x1, y1, x2, y2) { return { t: 'wall', pts: [x1, y1, x2, y2], w: 6, c: '#0F172A' }; }
  function rect(x, y, w, h) { return { t: 'room', x: x, y: y, w: w, h: h, c: '#334155' }; }
  function txt(s, x, y, z) { return { t: 'text', s: s, x: x, y: y, z: z || 18, c: '#0F172A' }; }
  function st(k, x, y) { return { t: 'stamp', k: k, x: x, y: y, s: 34 }; }
  function rt(kind, pts) { return { t: 'route', kind: kind, pts: pts, w: 5 }; }

  /* Templates to start from: a home on one floor, a centre room with a hallway. Grid units of 20. */
  var TEMPLATES = {
    home: { label: 'Home — one floor', items: function () {
      return [
        wall(140, 120, 520, 120), wall(600, 120, 1000, 120), wall(1000, 120, 1000, 680), wall(1000, 680, 640, 680), wall(560, 680, 140, 680), wall(140, 680, 140, 120),
        wall(520, 120, 520, 360), wall(520, 440, 520, 680), wall(140, 400, 300, 400), wall(380, 400, 520, 400), wall(760, 120, 760, 300), wall(760, 380, 760, 420), wall(760, 420, 1000, 420),
        txt('Play room', 330, 260, 20), txt('Living room', 330, 540, 20), txt('Kitchen', 640, 240, 20), txt('Nap room', 880, 260, 20), txt('Hall', 640, 520, 20), txt('Bathroom', 880, 560, 18),
        st('exit', 560, 120), st('exit', 600, 680), st('alarm', 640, 400), st('ext', 700, 180), st('aid', 720, 300), st('panel', 960, 640), st('hazard', 580, 180),
        st('meet', 1120, 140), txt('Meeting spot', 1120, 190, 16),
        rt('primary', [330, 300, 460, 330, 560, 300, 560, 160, 600, 70, 1080, 70, 1110, 120]),
        rt('alt', [330, 560, 480, 600, 600, 640, 600, 740, 1060, 740, 1120, 180]),
      ];
    } },
    room: { label: 'Centre room + hallway', items: function () {
      return [
        rect(160, 140, 560, 420), wall(160, 620, 1040, 620), wall(160, 720, 1040, 720), wall(1040, 620, 1040, 640), wall(1040, 700, 1040, 720),
        txt('Classroom', 440, 330, 22), txt('Hallway', 600, 670, 18), txt('Bathroom', 860, 230, 18), rect(780, 140, 200, 200),
        st('exit', 720, 360), st('exit', 1040, 670), st('exit', 160, 670), st('alarm', 440, 180), st('ext', 640, 600), st('aid', 220, 200), st('panel', 960, 600),
        st('meet', 1140, 400), txt('Meeting spot', 1140, 450, 16),
        rt('primary', [440, 400, 640, 380, 720, 380, 760, 470, 760, 670, 1000, 670, 1100, 660, 1140, 440]),
        rt('alt', [400, 470, 340, 590, 300, 670, 160, 670, 90, 600, 90, 380, 1100, 380]),
      ];
    } },
  };

  function bboxOf(it) {
    if (it.t === 'room') return { x: Math.min(it.x, it.x + it.w), y: Math.min(it.y, it.y + it.h), w: Math.abs(it.w), h: Math.abs(it.h) };
    if (it.t === 'stamp') { var r = it.s * 0.65; return { x: it.x - r, y: it.y - r, w: r * 2, h: r * 2 }; }
    if (it.t === 'text') { var tw = Math.max(20, String(it.s).length * it.z * 0.56); return { x: it.x - tw / 2, y: it.y - it.z * 0.7, w: tw, h: it.z * 1.4 }; }
    var p = it.pts, x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    for (var i = 0; i < p.length; i += 2) { x1 = Math.min(x1, p[i]); x2 = Math.max(x2, p[i]); y1 = Math.min(y1, p[i + 1]); y2 = Math.max(y2, p[i + 1]); }
    return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
  }
  function distSeg(px, py, x1, y1, x2, y2) {
    var dx = x2 - x1, dy = y2 - y1, l = dx * dx + dy * dy, t = l ? ((px - x1) * dx + (py - y1) * dy) / l : 0;
    t = Math.max(0, Math.min(1, t));
    var qx = x1 + t * dx - px, qy = y1 + t * dy - py; return Math.sqrt(qx * qx + qy * qy);
  }
  function hit(it, x, y, tol) {
    if (it.t === 'stamp' || it.t === 'text') { var b = bboxOf(it); return x >= b.x - tol && x <= b.x + b.w + tol && y >= b.y - tol && y <= b.y + b.h + tol; }
    if (it.t === 'room') {
      var r = bboxOf(it);
      return distSeg(x, y, r.x, r.y, r.x + r.w, r.y) < tol || distSeg(x, y, r.x + r.w, r.y, r.x + r.w, r.y + r.h) < tol
        || distSeg(x, y, r.x, r.y + r.h, r.x + r.w, r.y + r.h) < tol || distSeg(x, y, r.x, r.y, r.x, r.y + r.h) < tol;
    }
    var p = it.pts;
    for (var i = 0; i + 3 < p.length; i += 2) if (distSeg(x, y, p[i], p[i + 1], p[i + 2], p[i + 3]) < tol + (it.w || 4) / 2) return true;
    return false;
  }
  function moveItem(it, dx, dy) {
    if (it.pts) { for (var i = 0; i < it.pts.length; i += 2) { it.pts[i] += dx; it.pts[i + 1] += dy; } }
    else { it.x += dx; it.y += dy; }
  }
  // Douglas-Peucker on a flat [x,y,...] list.
  function simplify(p, tol) {
    if (p.length <= 4) return p.slice();
    var n = p.length / 2, keep = new Array(n); keep[0] = keep[n - 1] = true;
    (function rec(a, b) {
      var md = 0, mi = -1;
      for (var i = a + 1; i < b; i++) { var d = distSeg(p[i * 2], p[i * 2 + 1], p[a * 2], p[a * 2 + 1], p[b * 2], p[b * 2 + 1]); if (d > md) { md = d; mi = i; } }
      if (md > tol && mi > 0) { keep[mi] = true; rec(a, mi); rec(mi, b); }
    })(0, n - 1);
    var out = []; for (var i = 0; i < n; i++) if (keep[i]) out.push(Math.round(p[i * 2] * 10) / 10, Math.round(p[i * 2 + 1] * 10) / 10);
    return out;
  }

  /* Draws the plan onto ctx in WORLD units (the caller sets the transform). paper: the world rectangle. */
  function drawScene(ctx, dr, img, opts) {
    opts = opts || {};
    var W = dr.w || W0, H = dr.h || H0, g = dr.grid || G0;
    ctx.fillStyle = '#FFFFFF'; ctx.fillRect(0, 0, W, H);
    if (img && dr.underlay) {
      ctx.save(); ctx.globalAlpha = dr.underlay.o || 0.45;
      try { ctx.drawImage(img, dr.underlay.x, dr.underlay.y, dr.underlay.w, dr.underlay.h); } catch (e) {}
      ctx.restore();
    }
    if (opts.grid !== false) {
      ctx.lineWidth = 1 / (opts.scale || 1);
      for (var x = 0; x <= W; x += g) { ctx.strokeStyle = (x / g) % 5 === 0 ? '#CBD5E1' : '#E8EDF3'; ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke(); }
      for (var y = 0; y <= H; y += g) { ctx.strokeStyle = (y / g) % 5 === 0 ? '#CBD5E1' : '#E8EDF3'; ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke(); }
    }
    ctx.strokeStyle = '#94A3B8'; ctx.lineWidth = 2; ctx.strokeRect(0, 0, W, H);
    var order = { room: 0, wall: 1, pen: 2, route: 3, stamp: 4, text: 5 };
    var items = dr.items.slice().sort(function (a, b) { return order[a.t] - order[b.t]; });
    items.forEach(function (it) { drawItem(ctx, it); });
    if (opts.sel) {
      var b = bboxOf(opts.sel); ctx.save(); ctx.setLineDash([6, 4]); ctx.strokeStyle = '#0284C7'; ctx.lineWidth = 2 / (opts.scale || 1);
      ctx.strokeRect(b.x - 6, b.y - 6, b.w + 12, b.h + 12); ctx.restore();
    }
  }
  function strokePts(ctx, p) { ctx.beginPath(); ctx.moveTo(p[0], p[1]); for (var i = 2; i < p.length; i += 2) ctx.lineTo(p[i], p[i + 1]); ctx.stroke(); }
  function drawItem(ctx, it) {
    ctx.save();
    if (it.t === 'wall') { ctx.strokeStyle = it.c || '#0F172A'; ctx.lineWidth = it.w || 6; ctx.lineCap = 'square'; strokePts(ctx, it.pts); }
    else if (it.t === 'pen') { ctx.strokeStyle = it.c || '#0F172A'; ctx.lineWidth = it.w || 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; strokePts(ctx, it.pts); }
    else if (it.t === 'room') {
      var b = bboxOf(it); ctx.fillStyle = 'rgba(148,163,184,.10)'; ctx.fillRect(b.x, b.y, b.w, b.h);
      ctx.strokeStyle = it.c || '#334155'; ctx.lineWidth = 4; ctx.strokeRect(b.x, b.y, b.w, b.h);
    } else if (it.t === 'route') {
      var R = ROUTE[it.kind] || ROUTE.primary, p = it.pts, n = p.length;
      ctx.strokeStyle = R.c; ctx.lineWidth = it.w || 5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      if (it.kind === 'alt') ctx.setLineDash([16, 10]);
      // stop the line short of the tip so the arrowhead stays sharp
      var ex = p[n - 2], ey = p[n - 1], px = p[n - 4], py = p[n - 3], a = Math.atan2(ey - py, ex - px), L = 18;
      var q = p.slice(); q[n - 2] = ex - Math.cos(a) * L * 0.6; q[n - 1] = ey - Math.sin(a) * L * 0.6;
      strokePts(ctx, q); ctx.setLineDash([]);
      ctx.fillStyle = R.c; ctx.beginPath(); ctx.moveTo(ex, ey);
      ctx.lineTo(ex - Math.cos(a - 0.45) * L, ey - Math.sin(a - 0.45) * L); ctx.lineTo(ex - Math.cos(a + 0.45) * L, ey - Math.sin(a + 0.45) * L); ctx.closePath(); ctx.fill();
      ctx.beginPath(); ctx.arc(p[0], p[1], 5, 0, Math.PI * 2); ctx.fill();
    } else if (it.t === 'stamp') {
      var S = STAMP[it.k] || STAMPS[0], r = it.s * 0.62;
      ctx.fillStyle = '#FFFFFF'; ctx.strokeStyle = S.ring; ctx.lineWidth = 3;
      ctx.beginPath(); ctx.arc(it.x, it.y, r, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.font = Math.round(it.s * 0.72) + 'px ' + EMOJI_FONT; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = '#0F172A';
      ctx.fillText(S.e, it.x, it.y + it.s * 0.04);
    } else if (it.t === 'text') {
      ctx.font = '700 ' + it.z + 'px system-ui,-apple-system,"Segoe UI",Roboto,sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.lineWidth = 5; ctx.strokeStyle = 'rgba(255,255,255,.92)'; ctx.lineJoin = 'round'; ctx.strokeText(it.s, it.x, it.y);
      ctx.fillStyle = it.c || '#0F172A'; ctx.fillText(it.s, it.x, it.y);
    }
    ctx.restore();
  }
  /* The PNG snapshot: the world at 1.5x on white paper, a light grid. */
  function exportPng(dr, img) {
    var k = 1.5, c = document.createElement('canvas'); c.width = Math.round((dr.w || W0) * k); c.height = Math.round((dr.h || H0) * k);
    var x = c.getContext('2d'); x.setTransform(k, 0, 0, k, 0, 0); drawScene(x, dr, img, { scale: k });
    return c.toDataURL('image/png');
  }
  function loadImage(src) { return new Promise(function (res, rej) { var i = new Image(); i.onload = function () { res(i); }; i.onerror = rej; i.src = src; }); }
  function fetchUrl(id, kind) { return apiBase() + '/drills/fire-plans/' + id + '/file/' + kind; }
  var blobCache = {};
  function planImageUrl(id, kind) {
    var key = id + ':' + kind;
    if (!blobCache[key]) blobCache[key] = (KT.fetchFileBlob ? KT.fetchFileBlob(fetchUrl(id, kind)) : Promise.reject(new Error('No viewer')))
      .then(function (b) { return URL.createObjectURL(b); }, function (e) { delete blobCache[key]; throw e; });
    return blobCache[key];
  }
  function blobToDataUrl(url) {
    return fetch(url).then(function (r) { return r.blob(); }).then(function (b) { return new Promise(function (res) { var f = new FileReader(); f.onload = function () { res(f.result); }; f.readAsDataURL(b); }); });
  }
  /* A photo to trace over: shrunk to 1600 px on its long side, JPEG. */
  function shrinkPhoto(file) {
    return new Promise(function (res, rej) {
      var f = new FileReader();
      f.onload = function () {
        loadImage(f.result).then(function (im) {
          var m = 1600, s = Math.min(1, m / Math.max(im.naturalWidth, im.naturalHeight));
          var c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * s); c.height = Math.round(im.naturalHeight * s);
          var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(im, 0, 0, c.width, c.height);
          res({ url: c.toDataURL('image/jpeg', 0.82), w: c.width, h: c.height });
        }, rej);
      };
      f.onerror = rej; f.readAsDataURL(file);
    });
  }

  /* ── the decision tree ─────────────────────────────────────────────── */
  function blankTree(template) {
    var t = template || { steps: [], branches: [] };
    return { steps: (t.steps || []).map(function (s) { return { k: s.k, title: s.title, hint: s.hint || '', who: '', what: '', where: '' }; }),
      branches: (t.branches || []).map(function (b) { return { k: b.k, after: b.after, title: b.title, hint: b.hint || '', who: '', what: '', where: '' }; }) };
  }
  function lines(s) {
    var out = [];
    if (s.who) out.push('<div class="ln"><i>Who:</i> ' + esc(s.who) + '</div>');
    if (s.what) out.push('<div class="ln"><i>What:</i> ' + esc(s.what) + '</div>');
    if (s.where) out.push('<div class="ln"><i>Where:</i> ' + esc(s.where) + '</div>');
    return out.length ? out.join('') : '<div class="ln empty">' + esc(s.hint || 'Not filled in yet') + '</div>';
  }
  /* The tree as a flow diagram: one box per step, arrows down; a branch beside its step, arrow across. */
  function flowHtml(tree) {
    tree = tree || { steps: [], branches: [] };
    var by = {}; (tree.branches || []).forEach(function (b) { (by[b.after] = by[b.after] || []).push(b); });
    var anyBr = (tree.branches || []).length > 0;
    var h = '<div class="fpf" role="list" aria-label="Fire plan steps">';
    (tree.steps || []).forEach(function (s, i) {
      var brs = by[s.k] || [];
      if (i > 0) h += '<div class="fpf-v' + (anyBr ? '' : ' solo') + '" aria-hidden="true"></div>';
      h += '<div class="fpf-row' + (anyBr ? '' : ' solo') + '" role="listitem"><div class="fpf-node"><span class="no">' + (i + 1) + '</span><b>' + esc(s.title) + '</b>' + lines(s) + '</div>';
      if (brs.length) {
        h += '<div class="fpf-h" aria-hidden="true"><span>IF</span></div><div>' + brs.map(function (b) {
          return '<div class="fpf-node fpf-br" style="margin-top:4px;"><b>' + esc(b.title) + '</b>' + lines(b) + '</div>';
        }).join('') + '</div>';
      } else if (anyBr) h += '<div></div><div></div>';
      h += '</div>';
    });
    return h + '</div>';
  }

  /* ── data ──────────────────────────────────────────────────────────── */
  var listCache = { at: 0, key: '', p: null };
  function list(centreId, fresh) {
    var key = String(centreId || '');
    if (!fresh && listCache.p && listCache.key === key && Date.now() - listCache.at < 20000) return listCache.p;
    listCache = { at: Date.now(), key: key, p: Api().get('/drills/fire-plans' + (centreId ? '?centre_id=' + encodeURIComponent(centreId) : '')) };
    listCache.p.catch(function () { listCache.p = null; });
    return listCache.p;
  }
  function forget() { listCache.p = null; }

  var STATUS = {
    approved: ['#ECFDF5', '#047857', '✅ Approved'], current: ['#ECFDF5', '#047857', '✅ Current'], submitted: ['#FFFBEB', '#B45309', '⏳ Waiting for review'],
    changes: ['#FEF2F2', '#B91C1C', '↩️ Changes asked for'], draft: ['#F1F5F9', '#475569', '📝 Draft'], review_due: ['#FEF2F2', '#B91C1C', '📅 Review due'],
    none: ['#F1F5F9', '#475569', 'No plan yet'],
  };
  function chip(k, extra) { var t = STATUS[k] || STATUS.none; return '<span class="fp-chip" data-kt-no-tone style="background:' + t[0] + ';color:' + t[1] + ';">' + esc(extra || t[2]) + '</span>'; }
  function placeLabel(p) { return (p.centre_name || '') + (p.room_name ? ' · ' + p.room_name : ' · ' + term('Whole centre')); }

  /* ═══ THE EDITOR ═══════════════════════════════════════════════════ */
  var openEditor = null;
  function editor(opts) {
    opts = opts || {};
    css();
    if (openEditor) { try { openEditor.focus(); } catch (e) {} return Promise.resolve(null); }
    return Promise.all([list(opts.centre_id || '', true), opts.base ? Api().get('/drills/fire-plans/' + opts.base) : Promise.resolve(null)]).then(function (r) {
      return buildEditor(opts, r[0], r[1]);
    }, function (e) { toast('⚠️', 'Could not open the fire plan', said(e, 'Please try again.'), '#DC2626'); return null; });
  }

  function buildEditor(opts, L, B) {
    return new Promise(function (resolve) {
      var base = B && B.plan;
      var isDraft = !!(base && base.status === 'draft' && base.is_mine);
      var S = {
        centre: String(opts.centre_id || (base && base.centre_id) || ((L.centres || [])[0] || {}).id || ''),
        room: String(opts.room_id != null ? (opts.room_id || '') : ((base && base.room_id) || '')),
        drawing: base && base.drawing && base.drawing.items ? JSON.parse(JSON.stringify(base.drawing)) : emptyDrawing(),
        tree: base && base.tree && (base.tree.steps || []).length ? JSON.parse(JSON.stringify(base.tree)) : blankTree(L.template),
        details: base && base.details ? JSON.parse(JSON.stringify(base.details)) : {},
        title: (base && base.title) || '',
        underlayUrl: null, underlayNew: null, underlayImg: null, keepUnderlay: !!(base && base.has_underlay),
        dirty: false, tab: 'draw',
      };
      if (!S.details.reviewed_on) S.details.reviewed_on = todayLocal(L.timezone);
      if (!S.drawing.items) S.drawing.items = [];

      var root = document.createElement('div');
      root.className = 'fpe'; root.setAttribute('data-no-modal-guard', ''); root.setAttribute('data-kt-own-scroll', ''); root.setAttribute('data-kt-scroll', '');
      root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-label', 'Fire plan');
      var vtxt = base ? (isDraft ? 'Your draft · version ' + base.version : 'New version, from version ' + base.version) : 'New plan';
      root.innerHTML = '<div class="fpe-card">'
        + '<div class="fpe-head"><h2>🗺️ Fire plan<small id="fpe-sub"></small></h2><button type="button" class="fpe-x" aria-label="Close">✕</button></div>'
        + '<div class="fpe-steps" role="tablist">'
        + [['draw', '1 · Drawing'], ['tree', '2 · What we do'], ['details', '3 · Details']].map(function (t) { return '<button type="button" role="tab" data-t="' + t[0] + '" aria-selected="' + (t[0] === 'draw') + '">' + t[1] + '</button>'; }).join('')
        + '</div>'
        + '<div class="fpe-body">'
        + '<div class="fpe-pane on" data-p="draw">'
        + '<div class="fpt" id="fpt"></div><div class="fpt-stamps" id="fpt-stamps"></div>'
        + '<div class="fpc-wrap"><canvas id="fpc" aria-label="Fire plan drawing"></canvas><div class="fpc-hint" id="fpc-hint"></div></div></div>'
        + '<div class="fpe-pane" data-p="tree"><div class="fpe-scroll" data-kt-scroll id="fp-tree"></div></div>'
        + '<div class="fpe-pane" data-p="details"><div class="fpe-scroll" data-kt-scroll id="fp-details"></div></div>'
        + '</div>'
        + '<div class="fpe-foot"><div class="fpe-note">' + esc(vtxt) + '</div>'
        + '<button type="button" class="fp-b" data-a="cancel">Cancel</button>'
        + '<button type="button" class="fp-b" data-a="draft">Save draft</button>'
        + '<button type="button" class="fp-b p" data-a="submit">Submit for review</button></div>'
        + '<input type="file" id="fp-photo" accept="image/jpeg,image/png,image/webp" hidden></div>';
      document.body.appendChild(root);
      openEditor = root;
      var $ = function (sel) { return root.querySelector(sel); };
      function setSub() {
        var c = (L.centres || []).find(function (x) { return String(x.id) === S.centre; });
        var r = (L.rooms || []).find(function (x) { return String(x.id) === S.room; });
        $('#fpe-sub').textContent = (c ? c.name : '') + ' · ' + (r ? r.name : term('Whole centre')) + (S.title ? ' · ' + S.title : '');
      }
      setSub();

      /* ── drawing canvas ── */
      var cv = $('#fpc'), cx = cv.getContext('2d'), wrap = cv.parentNode;
      var view = { s: 1, x: 0, y: 0 }, dpr = 1, tool = 'wall', stamp = 'exit', sel = null;
      var hist = [], fut = [];
      var HINT = { move: 'Drag a shape to move it, or drag the paper to pan. Pinch or scroll to zoom.', wall: 'Drag to draw a wall. It snaps to the grid.', room: 'Drag to draw a room.',
        pen: 'Draw freehand.', stamp: 'Tap to place the stamp.', primary: 'Drag the primary way out, from the room to the meeting spot.', alt: 'Drag an alternate way out.',
        text: 'Tap where the label goes.', erase: 'Tap or drag over what to remove.' };
      function snapshot() { return JSON.stringify({ items: S.drawing.items, underlay: S.drawing.underlay }); }
      function push() { hist.push(snapshot()); if (hist.length > 80) hist.shift(); fut = []; S.dirty = true; syncUndo(); }
      function restore(snap) { var o = JSON.parse(snap); S.drawing.items = o.items; S.drawing.underlay = o.underlay; sel = null; paint(); }
      function undo() { if (!hist.length) return; fut.push(snapshot()); restore(hist.pop()); syncUndo(); }
      function redo() { if (!fut.length) return; hist.push(snapshot()); restore(fut.pop()); syncUndo(); }
      function syncUndo() { var u = $('[data-tool="undo"]'), r = $('[data-tool="redo"]'); if (u) u.disabled = !hist.length; if (r) r.disabled = !fut.length; }
      function resize() {
        var w = wrap.clientWidth, h = wrap.clientHeight; if (!w || !h) return;
        dpr = Math.min(window.devicePixelRatio || 1, 2.5);
        cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); paint();
      }
      function fit() {
        var w = wrap.clientWidth, h = wrap.clientHeight; if (!w || !h) return;
        var s = Math.min(w / S.drawing.w, h / S.drawing.h) * 0.96;
        view = { s: s, x: (w - S.drawing.w * s) / 2, y: (h - S.drawing.h * s) / 2 }; paint();
      }
      var live = null;   // the shape being drawn
      function paint() {
        cx.setTransform(1, 0, 0, 1, 0, 0); cx.clearRect(0, 0, cv.width, cv.height);
        cx.setTransform(dpr * view.s, 0, 0, dpr * view.s, dpr * view.x, dpr * view.y);
        drawScene(cx, S.drawing, S.underlayImg, { scale: view.s, sel: sel });
        if (live) { cx.globalAlpha = 0.85; drawItem(cx, live); cx.globalAlpha = 1; }
      }
      function toWorld(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left - view.x) / view.s, y: (e.clientY - r.top - view.y) / view.s }; }
      function snap(v) { var g = S.drawing.grid || G0; return Math.round(v / g) * g; }
      function zoomAt(px, py, f) {
        var ns = Math.max(0.15, Math.min(8, view.s * f)); f = ns / view.s;
        view.x = px - (px - view.x) * f; view.y = py - (py - view.y) * f; view.s = ns; paint();
      }
      var ptrs = {}, gesture = null, drag = null;
      cv.addEventListener('pointerdown', function (e) {
        cv.setPointerCapture && cv.setPointerCapture(e.pointerId);
        ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
        var ids = Object.keys(ptrs);
        if (ids.length === 2) {   // two fingers: pinch and pan; whatever was being drawn is dropped
          live = null; drag = null;
          var a = ptrs[ids[0]], b = ptrs[ids[1]];
          gesture = { d: Math.hypot(a.x - b.x, a.y - b.y), cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2 }; paint(); return;
        }
        if (ids.length > 2) return;
        var w = toWorld(e), r = cv.getBoundingClientRect();
        var panOnly = e.button === 1 || e.button === 2 || tool === 'move' && !e.shiftKey;
        if (tool === 'move') {
          var hitIt = null; for (var i = S.drawing.items.length - 1; i >= 0; i--) if (hit(S.drawing.items[i], w.x, w.y, 8 / view.s)) { hitIt = S.drawing.items[i]; break; }
          sel = hitIt;
          if (hitIt) { push(); drag = { kind: 'move', it: hitIt, x: w.x, y: w.y }; paint(); return; }
          drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, vx: view.x, vy: view.y }; paint(); return;
        }
        if (panOnly) { drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, vx: view.x, vy: view.y }; return; }
        if (tool === 'wall') live = wall(snap(w.x), snap(w.y), snap(w.x), snap(w.y));
        else if (tool === 'room') { live = rect(snap(w.x), snap(w.y), 0, 0); }
        else if (tool === 'pen') live = { t: 'pen', pts: [w.x, w.y, w.x, w.y], w: 3, c: '#1D4ED8' };
        else if (tool === 'primary' || tool === 'alt') live = rt(tool, [w.x, w.y, w.x, w.y]);
        else if (tool === 'erase') { drag = { kind: 'erase', pushed: false }; eraseAt(w); return; }
        else if (tool === 'stamp') { push(); S.drawing.items.push(st(stamp, Math.round(w.x), Math.round(w.y))); paint(); return; }
        else if (tool === 'text') { addText(w); return; }
        drag = { kind: 'draw', x0: w.x, y0: w.y };
        paint();
      });
      cv.addEventListener('pointermove', function (e) {
        if (!ptrs[e.pointerId]) return;
        ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
        var ids = Object.keys(ptrs);
        if (gesture && ids.length >= 2) {
          var a = ptrs[ids[0]], b = ptrs[ids[1]], d = Math.hypot(a.x - b.x, a.y - b.y), mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2, r = cv.getBoundingClientRect();
          view.x += mx - gesture.cx; view.y += my - gesture.cy;
          if (gesture.d > 10) zoomAt(mx - r.left, my - r.top, d / gesture.d); else paint();
          gesture.d = d; gesture.cx = mx; gesture.cy = my; return;
        }
        if (!drag) return;
        var w = toWorld(e);
        if (drag.kind === 'pan') { view.x = drag.vx + (e.clientX - drag.sx); view.y = drag.vy + (e.clientY - drag.sy); paint(); return; }
        if (drag.kind === 'move') { moveItem(drag.it, w.x - drag.x, w.y - drag.y); drag.x = w.x; drag.y = w.y; paint(); return; }
        if (drag.kind === 'erase') { eraseAt(w); return; }
        if (!live) return;
        if (live.t === 'wall') {
          var x2 = snap(w.x), y2 = snap(w.y), dx = Math.abs(x2 - live.pts[0]), dy = Math.abs(y2 - live.pts[1]);
          if (!e.altKey) { if (dy < dx * 0.27) y2 = live.pts[1]; else if (dx < dy * 0.27) x2 = live.pts[0]; }   // walls run straight unless pulled clearly off
          live.pts[2] = x2; live.pts[3] = y2;
        } else if (live.t === 'room') { live.w = snap(w.x) - live.x; live.h = snap(w.y) - live.y; }
        else { var p = live.pts, lx = p[p.length - 2], ly = p[p.length - 1]; if (Math.hypot(w.x - lx, w.y - ly) * view.s > 2.5) p.push(Math.round(w.x * 10) / 10, Math.round(w.y * 10) / 10); }
        paint();
      });
      function up(e) {
        delete ptrs[e.pointerId];
        if (gesture) { if (Object.keys(ptrs).length < 2) gesture = null; return; }
        if (drag && drag.kind === 'draw' && live) {
          var ok = false;
          if (live.t === 'wall') ok = live.pts[0] !== live.pts[2] || live.pts[1] !== live.pts[3];
          else if (live.t === 'room') { ok = Math.abs(live.w) >= (S.drawing.grid || G0) && Math.abs(live.h) >= (S.drawing.grid || G0); if (ok) { var b = bboxOf(live); live.x = b.x; live.y = b.y; live.w = b.w; live.h = b.h; } }
          else { var p = live.pts; if (p.length >= 6) { p.splice(0, 2); } live.pts = simplify(live.pts, live.t === 'pen' ? 1.2 : 4); ok = live.pts.length >= 4 && bboxOf(live).w + bboxOf(live).h > 12; }
          if (ok) { push(); S.drawing.items.push(live); }
        }
        live = null; drag = null; paint();
      }
      cv.addEventListener('pointerup', up); cv.addEventListener('pointercancel', up);
      cv.addEventListener('contextmenu', function (e) { e.preventDefault(); });
      cv.addEventListener('wheel', function (e) { e.preventDefault(); var r = cv.getBoundingClientRect(); zoomAt(e.clientX - r.left, e.clientY - r.top, e.deltaY < 0 ? 1.12 : 1 / 1.12); }, { passive: false });
      cv.addEventListener('dblclick', function (e) {
        var w = toWorld(e);
        for (var i = S.drawing.items.length - 1; i >= 0; i--) { var it = S.drawing.items[i]; if (it.t === 'text' && hit(it, w.x, w.y, 6 / view.s)) { editText(it); return; } }
      });
      function eraseAt(w) {
        var tol = 10 / view.s, before = S.drawing.items.length;
        var keep = S.drawing.items.filter(function (it) { return !hit(it, w.x, w.y, tol); });
        if (keep.length !== before) { if (!drag || !drag.pushed) { push(); if (drag) drag.pushed = true; } S.drawing.items = keep; sel = null; paint(); }
      }
      function askText(title, value) {
        if (KT.prompt) return KT.prompt({ title: title, okLabel: 'Done', fields: [{ key: 's', label: 'Label', value: value || '', placeholder: 'e.g. Kitchen, Front door, Toddler room' }] })
          .then(function (v) { return v == null ? null : String(typeof v === 'object' ? (v.s || '') : v).trim(); });
        return Promise.resolve((window.prompt(title, value || '') || '').trim() || null);
      }
      function addText(w) { askText('Add a label').then(function (s) { if (!s) return; push(); S.drawing.items.push(txt(s.slice(0, 80), Math.round(w.x), Math.round(w.y), 18)); paint(); }); }
      function editText(it) { askText('Change the label', it.s).then(function (s) { if (s == null) return; push(); if (!s) S.drawing.items.splice(S.drawing.items.indexOf(it), 1); else it.s = s.slice(0, 80); sel = null; paint(); }); }

      /* toolbar */
      var TOOLS = [['move', '✋', 'Move'], ['wall', '🧱', 'Wall'], ['room', '▭', 'Room'], ['pen', '✏️', 'Pen'], ['stamp', '🚪', 'Stamps'],
        ['primary', '<span class="fpt-sw" style="background:#16A34A"></span>➜', 'Primary route'], ['alt', '<span class="fpt-sw" style="background:repeating-linear-gradient(90deg,#F59E0B 0 6px,transparent 6px 9px)"></span>➜', 'Alternate route'],
        ['text', '🔤', 'Text'], ['erase', '🧽', 'Erase']];
      $('#fpt').innerHTML = '<div class="fpt-g">' + TOOLS.map(function (t) {
          return '<button type="button" data-tool="' + t[0] + '" aria-pressed="' + (t[0] === tool) + '" data-kttip="' + esc(t[2]) + '" aria-label="' + esc(t[2]) + '">' + t[1] + '<span class="fpt-l">' + esc(t[2]) + '</span></button>';
        }).join('') + '</div><span class="fpt-sep"></span><div class="fpt-g">'
        + '<button type="button" data-tool="undo" data-kttip="Undo" aria-label="Undo" disabled>↶</button><button type="button" data-tool="redo" data-kttip="Redo" aria-label="Redo" disabled>↷</button>'
        + '<button type="button" data-tool="zout" data-kttip="Zoom out" aria-label="Zoom out">−</button><button type="button" data-tool="zin" data-kttip="Zoom in" aria-label="Zoom in">＋</button>'
        + '<button type="button" data-tool="fit" data-kttip="Fit to screen" aria-label="Fit to screen">⤢</button>'
        + '<span id="fpt-op" hidden><input type="range" min="10" max="100" step="5" aria-label="Photo opacity" data-kttip="Photo opacity"></span>'
        + '<button type="button" data-tool="more" class="kt-ka-kebab" data-kt-inpage aria-haspopup="menu" aria-label="More drawing actions">⋮</button></div>';
      $('#fpt-stamps').innerHTML = STAMPS.map(function (s) { return '<button type="button" data-stamp="' + s.k + '" aria-pressed="' + (s.k === stamp) + '" aria-label="' + esc(s.label) + '" data-kttip="' + esc(s.label) + '">' + s.e + ' <span>' + esc(s.label) + '</span></button>'; }).join('');
      function setTool(t) {
        tool = t; sel = t === 'move' ? sel : null;
        root.querySelectorAll('[data-tool]').forEach(function (b) { if (TOOLS.some(function (x) { return x[0] === b.getAttribute('data-tool'); })) b.setAttribute('aria-pressed', String(b.getAttribute('data-tool') === t)); });
        $('#fpt-stamps').classList.toggle('on', t === 'stamp');
        $('#fpc-hint').textContent = HINT[t] || '';
        cv.style.cursor = t === 'move' ? 'grab' : (t === 'erase' ? 'cell' : 'crosshair');
        setTimeout(resize, 0); paint();
      }
      root.querySelectorAll('[data-stamp]').forEach(function (b) {
        b.addEventListener('click', function () {
          stamp = b.getAttribute('data-stamp');
          root.querySelectorAll('[data-stamp]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
          var sb = $('[data-tool="stamp"]'); if (sb) sb.firstChild.textContent = STAMP[stamp].e;
        });
      });
      function syncOpacity() {
        var o = $('#fpt-op'); o.hidden = !S.drawing.underlay;
        if (S.drawing.underlay) o.querySelector('input').value = Math.round((S.drawing.underlay.o || 0.45) * 100);
      }
      $('#fpt-op input').addEventListener('input', function () { if (S.drawing.underlay) { S.drawing.underlay.o = (+this.value) / 100; S.dirty = true; paint(); } });
      $('#fpt').addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-tool]'); if (!b) return;
        var t = b.getAttribute('data-tool'), r = cv.getBoundingClientRect();
        if (t === 'undo') return undo();
        if (t === 'redo') return redo();
        if (t === 'zin') return zoomAt(r.width / 2, r.height / 2, 1.25);
        if (t === 'zout') return zoomAt(r.width / 2, r.height / 2, 0.8);
        if (t === 'fit') return fit();
        if (t === 'more') return more(b);
        setTool(t);
      });
      function more(anchor) {
        var acts = [];
        var mk = function (label, fn, dis) { var x = document.createElement('button'); x.type = 'button'; x.textContent = label; if (dis) x.disabled = true; x.addEventListener('click', fn); acts.push(x); };
        Object.keys(TEMPLATES).forEach(function (k) { mk('📐 Start from: ' + TEMPLATES[k].label, function () { useTemplate(k); }); });
        mk(S.drawing.underlay ? '🖼️ Replace the photo to trace over' : '🖼️ Add a photo or floor plan to trace over', function () { $('#fp-photo').value = ''; $('#fp-photo').click(); });
        if (S.drawing.underlay) mk('🚫 Remove the photo', function () { push(); S.drawing.underlay = null; S.underlayImg = null; S.underlayNew = null; S.keepUnderlay = false; syncOpacity(); paint(); });
        mk('🗑 Clear the drawing', function () { clearAll(); }, !S.drawing.items.length);
        if (KT.openActionMenu) KT.openActionMenu(anchor, acts);
      }
      function confirmReplace(title) {
        if (!S.drawing.items.length) return Promise.resolve(true);
        return KT.confirm ? KT.confirm({ title: title, description: 'What is drawn now is replaced. You can undo this.', okLabel: 'Replace' }) : Promise.resolve(window.confirm(title));
      }
      function useTemplate(k) {
        confirmReplace('Start from "' + TEMPLATES[k].label + '"?').then(function (ok) {
          if (!ok) return; push(); S.drawing.items = TEMPLATES[k].items(); sel = null; fit();
          toast('📐', 'Template added', 'Move, erase or add to it so it matches your space.', '#0EA5E9');
        });
      }
      function clearAll() {
        (KT.confirm ? KT.confirm({ title: 'Clear the drawing?', description: 'Everything drawn is removed (the photo underneath stays). You can undo this.', okLabel: 'Clear' }) : Promise.resolve(true))
          .then(function (ok) { if (!ok) return; push(); S.drawing.items = []; sel = null; paint(); });
      }
      $('#fp-photo').addEventListener('change', function () {
        var f = this.files && this.files[0]; if (!f) return;
        if (f.size > 25 * 1048576) { toast('⚠️', 'That photo is too big', 'Use a photo under 25 MB.', '#DC2626'); return; }
        shrinkPhoto(f).then(function (p) {
          return loadImage(p.url).then(function (im) {
            push();
            var W = S.drawing.w, H = S.drawing.h, s = Math.min(W / p.w, H / p.h);
            S.drawing.underlay = { x: (W - p.w * s) / 2, y: (H - p.h * s) / 2, w: p.w * s, h: p.h * s, o: 0.45 };
            S.underlayImg = im; S.underlayNew = p.url; S.keepUnderlay = false; syncOpacity(); paint();
            toast('🖼️', 'Photo added underneath', 'Trace the walls and exits over it. The slider sets how faint it is.', '#0EA5E9');
          });
        }).catch(function () { toast('⚠️', 'Could not read that photo', 'Try a JPG or PNG.', '#DC2626'); });
      });
      // An existing plan's traced-over photo comes back from the server.
      if (base && base.has_underlay && S.drawing.underlay) {
        planImageUrl(base.id, 'underlay').then(loadImage).then(function (im) { S.underlayImg = im; paint(); }, function () {});
      }
      var ro = window.ResizeObserver ? new ResizeObserver(function () { resize(); }) : null;
      if (ro) ro.observe(wrap); else window.addEventListener('resize', resize);
      setTool(S.drawing.items.length ? 'move' : 'wall');
      requestAnimationFrame(function () { resize(); fit(); syncUndo(); syncOpacity(); });

      /* ── 2 · What we do ── */
      function paintTree() {
        var el = $('#fp-tree'), t = S.tree, by = {};
        (t.branches || []).forEach(function (b) { (by[b.after] = by[b.after] || []).push(b); });
        var field = function (kind, i, f, label, ph, area) {
          var v = (kind === 's' ? t.steps : t.branches)[i][f] || '';
          return '<div><label class="fp-l">' + label + '</label>' + (area
            ? '<textarea class="fp-i" rows="2" maxlength="600" data-f="' + kind + '|' + i + '|' + f + '" placeholder="' + esc(ph) + '">' + esc(v) + '</textarea>'
            : '<input class="fp-i" maxlength="' + (f === 'who' ? 160 : 200) + '" data-f="' + kind + '|' + i + '|' + f + '" value="' + esc(v) + '" placeholder="' + esc(ph) + '">') + '</div>';
        };
        var card = function (kind, i, s, n) {
          return '<div class="fp-step' + (kind === 'b' ? ' br' : '') + '"><div class="fp-step-h"><span class="fp-n">' + (kind === 'b' ? '↳' : n) + '</span><span class="fp-step-t">' + esc(s.title) + '</span></div>'
            + (s.hint ? '<div class="fp-step-hint">' + esc(s.hint) + '</div>' : '')
            + '<div class="fp-wwh">' + field(kind, i, 'who', 'Who', 'e.g. Maya (lead)') + field(kind, i, 'what', 'What', s.hint ? 'What they do' : '', true) + field(kind, i, 'where', 'Where', 'e.g. Front door, Room 2') + '</div></div>';
        };
        var h = '<div class="fp-2col"><div><div class="fp-h">What we do</div><div class="fp-sub">For each step: who does it, what they do and where. The branches say what changes when something goes wrong.</div>';
        t.steps.forEach(function (s, i) {
          h += card('s', i, s, i + 1);
          (by[s.k] || []).forEach(function (b) { h += card('b', t.branches.indexOf(b), b); });
        });
        h += '</div><div><div class="fp-h">The plan as a diagram</div><div class="fp-sub">This is how it prints.</div><div id="fp-flow">' + flowHtml(t) + '</div></div></div>';
        el.innerHTML = h;
        el.addEventListener('input', function (e) {
          var f = e.target.getAttribute && e.target.getAttribute('data-f'); if (!f) return;
          var p = f.split('|'), arr = p[0] === 's' ? S.tree.steps : S.tree.branches;
          arr[+p[1]][p[2]] = e.target.value; S.dirty = true;
          clearTimeout(paintTree._t); paintTree._t = setTimeout(function () { var fl = $('#fp-flow'); if (fl) fl.innerHTML = flowHtml(S.tree); }, 250);
        });
      }
      paintTree();

      /* ── 3 · Details ── */
      function paintDetails() {
        var el = $('#fp-details'), d = S.details;
        var centreOpts = (L.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === S.centre ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('');
        var locked = isDraft || !!base;   // a new version stays with its place
        el.innerHTML = '<div class="fp-h">Where this plan is for</div><div class="fp-sub">' + (locked ? 'A new version stays with the same place.' : 'A centre or home, and the room if the plan is for one room.') + '</div>'
          + '<div class="fp-g"><div><label class="fp-l">' + esc(term('Centre')) + ' / home</label><select class="fp-i" id="fpd-c"' + (locked ? ' disabled' : '') + '>' + centreOpts + '</select></div>'
          + '<div><label class="fp-l">Room</label><select class="fp-i" id="fpd-r"' + (locked ? ' disabled' : '') + '></select></div>'
          + '<div><label class="fp-l">Name (optional)</label><input class="fp-i" id="fpd-t" maxlength="190" placeholder="e.g. Main floor" value="' + esc(S.title) + '"></div></div>'
          + '<div class="fp-h" style="margin-top:18px;">Plan details</div><div class="fp-sub">What the plan is built around.</div>'
          + '<div class="fp-g"><div><label class="fp-l">Children (usual number)</label><input class="fp-i" type="number" min="0" max="500" id="fpd-n" value="' + esc(d.children_count != null ? d.children_count : '') + '"></div>'
          + '<div><label class="fp-l">Ages</label><input class="fp-i" id="fpd-a" maxlength="200" placeholder="e.g. 2 infants, 3 toddlers, 1 school-age" value="' + esc(d.ages || '') + '"></div>'
          + '<div><label class="fp-l">Exits</label><input class="fp-i" type="number" min="0" max="50" id="fpd-x" value="' + esc(d.exits_count != null ? d.exits_count : '') + '"></div>'
          + '<div><label class="fp-l">Date reviewed</label><input class="fp-i" type="date" id="fpd-d" value="' + esc(d.reviewed_on || '') + '"></div></div>'
          + '<div style="margin-top:12px;"><label class="fp-l">Staff</label><div class="fp-staff" id="fpd-s"></div></div>'
          + '<div class="fp-g" style="margin-top:10px;"><div><label class="fp-l">Other adults (optional)</label><input class="fp-i" id="fpd-o" maxlength="300" placeholder="e.g. Assistant on Tuesdays, a volunteer" value="' + esc(d.staff_other || '') + '"></div></div>'
          + '<div style="margin-top:10px;"><label class="fp-l">Notes</label><textarea class="fp-i" id="fpd-notes" rows="3" maxlength="2000" placeholder="Anything else: a child who needs extra help, a door that sticks, where the keys are">' + esc(d.notes || '') + '</textarea></div>';
        var fillRooms = function () {
          $('#fpd-r').innerHTML = '<option value="">' + esc(term('Whole centre')) + '</option>' + (L.rooms || []).filter(function (r) { return String(r.centre_id) === S.centre; })
            .map(function (r) { return '<option value="' + r.id + '"' + (String(r.id) === S.room ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('');
        };
        var fillStaff = function () {
          var ids = (S.details.staff_ids || []).map(String), people = (L.staff || {})[S.centre] || [];
          $('#fpd-s').innerHTML = people.length ? people.map(function (p) { return '<label><input type="checkbox" data-kt-noswitch value="' + p.id + '"' + (ids.indexOf(String(p.id)) >= 0 ? ' checked' : '') + '> ' + esc(p.name) + '</label>'; }).join('')
            : '<span style="font-size:13px;color:#64748B;">No staff at this ' + esc(term('centre')) + ' yet.</span>';
        };
        fillRooms(); fillStaff();
        $('#fpd-c').addEventListener('change', function () { S.centre = this.value; S.room = ''; S.details.staff_ids = []; fillRooms(); fillStaff(); setSub(); S.dirty = true; });
        $('#fpd-r').addEventListener('change', function () { S.room = this.value; setSub(); S.dirty = true; });
        el.addEventListener('input', function () { S.dirty = true; });
        $('#fpd-t').addEventListener('input', function () { S.title = this.value; setSub(); });
      }
      paintDetails();
      function readDetails() {
        var v = function (id) { var x = $('#' + id); return x ? x.value : ''; };
        S.details = {
          children_count: v('fpd-n') === '' ? null : +v('fpd-n'), ages: v('fpd-a').trim(), exits_count: v('fpd-x') === '' ? null : +v('fpd-x'),
          reviewed_on: v('fpd-d') || null, staff_other: v('fpd-o').trim(), notes: v('fpd-notes').trim(),
          staff_ids: Array.prototype.map.call(root.querySelectorAll('#fpd-s input:checked'), function (x) { return +x.value; }),
        };
      }

      /* tabs */
      root.querySelectorAll('.fpe-steps [data-t]').forEach(function (b) {
        b.addEventListener('click', function () { show(b.getAttribute('data-t')); });
      });
      function show(t) {
        S.tab = t;
        root.querySelectorAll('.fpe-steps [data-t]').forEach(function (x) { x.setAttribute('aria-selected', String(x.getAttribute('data-t') === t)); });
        root.querySelectorAll('.fpe-pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-p') === t); });
        if (t === 'draw') requestAnimationFrame(resize);
        if (t === 'tree') { var fl = $('#fp-flow'); if (fl) fl.innerHTML = flowHtml(S.tree); }
      }

      /* save */
      function finish(result) {
        window.removeEventListener('keydown', onKey, true);
        if (ro) ro.disconnect(); else window.removeEventListener('resize', resize);
        root.remove(); openEditor = null; resolve(result || null);
      }
      function close() {
        if (!S.dirty) return finish(null);
        (KT.confirm ? KT.confirm({ title: 'Close without saving?', description: 'What you changed in this fire plan is lost.', okLabel: 'Close without saving' }) : Promise.resolve(window.confirm('Close without saving?')))
          .then(function (ok) { if (ok) finish(null); });
      }
      function onKey(e) {
        if (e.key === 'Escape' && openEditor === root && !document.querySelector('.kt-cf, .kt-prompt, [data-kt-prompt]')) { e.preventDefault(); e.stopImmediatePropagation(); close(); return; }
        if (openEditor !== root || S.tab !== 'draw' || /^(INPUT|TEXTAREA|SELECT)$/.test((e.target && e.target.tagName) || '')) return;
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); e.stopImmediatePropagation(); if (e.shiftKey) redo(); else undo(); }
        else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); e.stopImmediatePropagation(); redo(); }
        else if ((e.key === 'Delete' || e.key === 'Backspace') && sel) { e.preventDefault(); push(); S.drawing.items.splice(S.drawing.items.indexOf(sel), 1); sel = null; paint(); }
      }
      window.addEventListener('keydown', onKey, true);   // window capture runs before the popup underneath (it closes on Esc)
      $('.fpe-x').addEventListener('click', close);
      $('[data-a="cancel"]').addEventListener('click', close);
      async function save(submit) {
        readDetails();
        if (submit) {
          if (!S.drawing.items.length) { show('draw'); toast('🗺️', 'Draw the plan first', 'At least the walls, the exits and the meeting place.', '#DC2626'); return; }
          var filled = S.tree.steps.filter(function (s) { return (s.who + s.what + s.where).trim(); }).length;
          if (filled < 3) { show('tree'); toast('🧭', 'Fill in the steps', 'Say who does what, and where, for the steps of the plan.', '#DC2626'); return; }
          var ok = KT.confirm ? await KT.confirm({ title: 'Submit this fire plan for review?', description: 'A director or admin reviews it. Once submitted it does not change: editing it later makes a new version.', okLabel: 'Submit' }) : true;
          if (!ok) return;
        }
        var btns = root.querySelectorAll('.fpe-foot .fp-b'); btns.forEach(function (b) { b.disabled = true; });
        try {
          var body = {
            id: isDraft ? base.id : null, based_on_id: base && !isDraft ? base.id : null,
            centre_id: +S.centre, room_id: S.room ? +S.room : null, title: S.title.trim() || null,
            drawing: S.drawing, png: exportPng(S.drawing, S.underlayImg), tree: S.tree, details: S.details, submit: !!submit,
            underlay: S.underlayNew || null, keep_underlay: !S.underlayNew && S.keepUnderlay && !!S.drawing.underlay,
          };
          var r = await Api().post('/drills/fire-plans', body);
          forget();
          toast(submit ? '📨' : '💾', submit ? 'Fire plan sent for review' : 'Draft saved', submit ? 'Version ' + r.version + ' is with a director or admin.' : 'Only you can see it until you submit it.', '#16A34A');
          finish(r);
        } catch (e) {
          btns.forEach(function (b) { b.disabled = false; });
          toast('⚠️', 'Could not save the fire plan', said(e, 'Please try again.'), '#DC2626');
        }
      }
      $('[data-a="draft"]').addEventListener('click', function () { save(false); });
      $('[data-a="submit"]').addEventListener('click', function () { save(true); });
      if (opts.tab) show(opts.tab);
    });
  }

  /* ═══ THE PLAN IN THE HOUSE POPUP ══════════════════════════════════ */
  function legendHtml() {
    return '<div class="fp-leg">' + STAMPS.map(function (s) { return '<span>' + s.e + ' ' + esc(s.label) + '</span>'; }).join('')
      + '<span><i class="sw" style="background:#16A34A"></i> Primary route</span><span><i class="sw" style="background:repeating-linear-gradient(90deg,#F59E0B 0 6px,transparent 6px 9px)"></i> Alternate route</span></div>';
  }
  function detailsKv(p, doc) {
    var d = p.details || {}, staff = (doc && doc.staff) || [];
    if (d.staff_other) staff = staff.concat([d.staff_other]);
    return [['Place', placeLabel(p)], ['Children', d.children_count != null ? String(d.children_count) : ''], ['Ages', d.ages], ['Staff', staff.join(', ')],
      ['Exits', d.exits_count != null ? String(d.exits_count) : ''], ['Date reviewed', day(d.reviewed_on)], ['Notes', d.notes]]
      .filter(function (x) { return x[1] != null && String(x[1]) !== ''; });
  }
  function statusOf(p) { return p.is_current ? 'current' : p.status; }
  function view(id, opts) {
    opts = opts || {};
    css();
    if (!KT.Shell || !KT.Shell.Modal) return;
    return Api().get('/drills/fire-plans/' + id).then(function (r) {
      var p = r.plan, doc = r.doc || {};
      var body = document.createElement('div');
      var who = [p.created_by_name ? 'Prepared by ' + p.created_by_name : '', p.submitted_at ? 'submitted ' + day(p.submitted_at) : '',
        p.reviewed_by_name ? (p.status === 'approved' ? 'approved by ' : 'reviewed by ') + p.reviewed_by_name + ' ' + day(p.reviewed_at) : ''].filter(Boolean).join(' · ');
      body.innerHTML = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;"><b style="font-size:14px;">Version ' + p.version + '</b>' + chip(statusOf(p))
        + '<span style="font-size:12.5px;color:#64748B;">' + esc(who) + '</span></div>'
        + (p.status === 'submitted' && p.is_mine && !p.can_review ? '<div class="fp-comment" style="background:#FFFBEB;border-color:#FDE68A;color:#78350F;">' + 'Waiting for review by another director or an agency admin.' + '</div>' : '')
        + (p.status === 'changes' && p.review_comment ? '<div class="fp-comment"><b>What to change:</b> ' + esc(p.review_comment) + '</div>' : '')
        + (p.status === 'approved' && p.review_comment ? '<div class="fp-comment" style="background:#ECFDF5;border-color:#A7F3D0;color:#064E3B;">' + esc(p.review_comment) + '</div>' : '')
        + '<div class="fp-sec">Drawing</div><div id="fpv-draw"><div class="fpv-none">Loading the drawing…</div></div>' + legendHtml()
        + '<div class="fp-sec">What we do</div>' + flowHtml(p.tree)
        + '<div class="fp-sec">Details</div><div class="fp-kv">' + detailsKv(p, doc).map(function (x) { return '<b>' + esc(x[0]) + '</b><span style="white-space:pre-wrap;overflow-wrap:anywhere;">' + esc(x[1]) + '</span>'; }).join('') + '</div>'
        + ((r.versions || []).length > 1 ? '<div class="fp-sec">Versions</div><div class="fp-vers">' + r.versions.map(function (v) {
            return '<button type="button" class="fp-ver" data-ver="' + v.id + '"' + (v.id === p.id ? ' aria-current="true"' : '') + '><b>v' + v.version + '</b>' + chip(statusOf(v))
              + '<span>' + esc([v.created_by_name, day(v.submitted_at || v.updated_at)].filter(Boolean).join(' · ')) + '</span></button>';
          }).join('') + '</div>' : '');
      if (p.has_png) {
        planImageUrl(p.id, 'png').then(function (u) {
          var el = body.querySelector('#fpv-draw'); if (!el) return;
          el.innerHTML = '<button type="button" class="fpv-img" style="padding:0;"><img src="' + u + '" alt="Fire plan drawing" style="display:block;width:100%;max-height:62vh;object-fit:contain;border-radius:10px;"></button>';
          el.querySelector('button').addEventListener('click', function () { fetch(u).then(function (x) { return x.blob(); }).then(function (b) { if (KT.openFile) KT.openFile(b, { title: 'Fire plan · ' + placeLabel(p), filename: 'fire-plan.png', mime: 'image/png' }); }); });
        }, function () { var el = body.querySelector('#fpv-draw'); if (el) el.innerHTML = '<div class="fpv-none">The drawing could not be loaded.</div>'; });
      } else body.querySelector('#fpv-draw').innerHTML = '<div class="fpv-none">No drawing yet.</div>';
      body.querySelectorAll('[data-ver]').forEach(function (b) { b.addEventListener('click', function () { var v = +b.getAttribute('data-ver'); if (v !== p.id) setTimeout(function () { view(v, opts); }, 30); }); });

      var after = opts.onChange || function () {};
      var acts = [{ label: 'Close' }];
      acts.push({ label: '🖨 Print / PDF', handler: function () { print(p.id, 'full', r); return false; } });
      acts.push({ label: '📌 Post this plan', handler: function () { print(p.id, 'post', r); return false; } });
      if (p.can_review) {
        /* not an async handler: the Modal would show its busy label on this button while the comment prompt is open */
        acts.push({ label: '↩️ Ask for changes', handler: function () { askChanges(); return false; } });
        var askChanges = async function () {
          var v = await KT.prompt({ title: 'Ask for changes · version ' + p.version, description: 'The educator sees your comment with the plan and makes a new version.', okLabel: 'Ask for changes',
            fields: [{ key: 'c', label: 'What needs to change?', type: 'textarea', rows: 4, placeholder: 'e.g. Show the second exit in the toddler room and who carries the infants' }] });
          if (v == null) return false;
          var c = String(typeof v === 'object' ? (v.c || '') : v).trim();
          if (!c) { toast('⚠️', 'Add a comment', 'Say what needs to change.', '#DC2626'); return false; }
          try { await Api().post('/drills/fire-plans/' + p.id + '/review', { decision: 'changes', comment: c }); } catch (e) { toast('⚠️', 'Could not save', said(e, ''), '#DC2626'); return false; }
          forget(); toast('↩️', 'Changes asked for', 'The educator sees your comment.', '#16A34A');
          try { KT.Shell.Modal.close(); } catch (e) {}
          after();
        };
        acts.push({ label: '✅ Approve', primary: true, busyLabel: 'Approving…', handler: async function () {
          try { await Api().post('/drills/fire-plans/' + p.id + '/review', { decision: 'approve' }); } catch (e) { toast('⚠️', 'Could not approve', said(e, ''), '#DC2626'); return false; }
          forget(); toast('✅', 'Fire plan approved', 'It is now the current plan for ' + placeLabel(p) + '.', '#16A34A'); after();
        } });
      } else if (p.can_edit_draft) {
        acts.push({ label: '✏️ Continue the draft', primary: true, handler: function () { setTimeout(function () { editor({ base: p.id }).then(function (x) { if (x) after(); }); }, 30); } });
      } else if (p.status !== 'draft') {
        acts.push({ label: '✏️ Edit (new version)', primary: true, handler: function () { setTimeout(function () { editor({ base: p.id }).then(function (x) { if (x) after(); }); }, 30); } });
      }
      KT.Shell.Modal.open({ title: '🗺️ Fire plan · ' + placeLabel(p), body: body, large: true, actions: acts });
    }, function (e) { toast('⚠️', 'Could not open the fire plan', said(e, 'Please try again.'), '#DC2626'); });
  }

  /* ═══ PRINT / PDF (KT.printDoc, house document) ═════════════════════ */
  var PRINT_CSS = '@page{size:letter portrait;margin:14mm 13mm 16mm;}'
    + '*{box-sizing:border-box;}body{font-family:Helvetica,Arial,"Segoe UI",sans-serif;color:#0F172A;font-size:10.5pt;margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
    + '.hd{display:flex;align-items:center;gap:14px;padding-bottom:6px;}.hd img{max-height:48px;max-width:160px;}'
    + '.ag{flex:1;min-width:0;}.ag b{font-size:12.5pt;display:block;}.ag span{font-size:8.5pt;color:#334155;display:block;margin-top:1px;}'
    + '.tt{text-align:right;}.tt b{font-size:18pt;display:block;line-height:1.05;}.tt span{font-size:9.5pt;font-weight:bold;display:block;margin-top:3px;}'
    + '.rule{border-top:2.5px solid var(--c);margin:0 0 10px;}'
    + '.draw{border:1.5px solid #0F172A;border-radius:4px;padding:4px;text-align:center;}.draw img{max-width:100%;max-height:6.6in;}'
    + '.leg{display:flex;flex-wrap:wrap;gap:5px 16px;margin:9px 0 6px;font-size:9.5pt;}.leg span{display:inline-flex;align-items:center;gap:5px;}'
    + '.leg i{display:inline-block;width:24px;height:5px;border-radius:3px;}'
    + '.mini{display:flex;flex-wrap:wrap;gap:4px 22px;font-size:9.5pt;margin-top:6px;}.mini b{font-weight:bold;}'
    + '.pb{page-break-before:always;break-before:page;}'
    + 'h3{font-size:12pt;margin:0 0 8px;}'
    + '.fpf{max-width:none;}.fpf-row{display:grid;grid-template-columns:minmax(0,1fr) 38px minmax(0,.85fr);align-items:center;}.fpf-row.solo{grid-template-columns:minmax(0,1fr);}'
    + '.fpf-node{position:relative;border:1.5px solid #1F6080;background:#fff;border-radius:7px;padding:3px 8px 3px 31px;page-break-inside:avoid;break-inside:avoid;}'
    + '.fpf-node .no{position:absolute;left:6px;top:4px;width:18px;height:18px;border-radius:50%;background:#1F6080;color:#fff;font-size:9pt;font-weight:bold;display:flex;align-items:center;justify-content:center;}'
    + '.fpf-node b{display:block;font-size:9.5pt;}.fpf-node .ln{font-size:8.2pt;line-height:1.25;color:#334155;margin-top:1px;}.fpf-node .ln i{font-style:normal;font-weight:bold;color:#0F172A;}.fpf-node .ln.empty{font-style:italic;color:#64748B;}'
    + '.fpf-br{border-style:dashed;border-color:#D97706;background:#FFFBEB;padding-left:9px;}'
    + '.fpf-h{position:relative;height:2px;background:#D97706;margin:0 4px;}.fpf-h:after{content:"";position:absolute;right:-2px;top:-5px;border-left:8px solid #D97706;border-top:6px solid transparent;border-bottom:6px solid transparent;}'
    + '.fpf-h span{position:absolute;left:0;right:0;top:-14px;text-align:center;font-size:7.5pt;font-weight:bold;color:#B45309;}'
    + '.fpf-v{height:9px;position:relative;width:calc((100% - 38px) / 1.85);}.fpf-v.solo{width:100%;}'
    + '.fpf-v:before{content:"";position:absolute;left:50%;top:0;bottom:4px;width:2px;margin-left:-1px;background:#475569;}'
    + '.fpf-v:after{content:"";position:absolute;left:50%;bottom:0;margin-left:-5px;border-top:5px solid #475569;border-left:5px solid transparent;border-right:5px solid transparent;}'
    + 'table.kv{width:100%;border-collapse:collapse;margin-top:8px;page-break-inside:avoid;}table.kv td{border:1px solid #0F172A;padding:2px 7px;vertical-align:top;font-size:9pt;}'
    + 'table.kv td.k{width:28%;font-weight:bold;background:#F1F5F9;}'
    + '.sig{display:flex;gap:24px;margin-top:22px;font-size:9.5pt;}.sig div{flex:1;border-top:1px solid #0F172A;padding-top:3px;}'
    + '.ft{margin-top:14px;font-size:7.5pt;color:#64748B;display:flex;justify-content:space-between;}'
    /* the "post this plan" sheet */
    + '.post{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:12px;align-items:start;}'
    + '.big{font-size:24pt;font-weight:900;color:#B91C1C;letter-spacing:.5px;line-height:1;}'
    + '.call{background:#B91C1C;color:#fff;border-radius:6px;padding:7px 10px;font-size:13pt;font-weight:bold;margin:8px 0;}'
    + 'ol.steps{margin:0;padding:0 0 0 20px;font-size:10pt;}ol.steps li{margin:0 0 5px;page-break-inside:avoid;}ol.steps li b{display:block;}ol.steps li span{color:#334155;font-size:9pt;}'
    + '.meet{border:2px solid #B91C1C;border-radius:6px;padding:6px 9px;margin-top:8px;font-size:10.5pt;}';

  function docHead(doc, title, sub) {
    var addr = [doc.address, doc.phone].filter(Boolean).join(' · ');
    return '<div class="hd">' + (doc.logo ? '<img src="' + esc(doc.logo) + '" alt="">' : '')
      + '<div class="ag"><b>' + esc(doc.agency_name || '') + '</b><span>' + esc(doc.centre_name || '') + '</span>' + (addr ? '<span>' + esc(addr) + '</span>' : '') + '</div>'
      + '<div class="tt"><b>' + esc(title) + '</b><span>' + esc(sub) + '</span></div></div><div class="rule"></div>';
  }
  function footer(doc, p) {
    return '<div class="ft"><span>' + esc(placeLabel(p)) + ' · version ' + p.version + (p.status === 'approved' ? ' · approved ' + day(p.reviewed_at) : ' · ' + (STATUS[p.status] || STATUS.none)[2].replace(/^\S+\s/, '')) + '</span>'
      + (doc.white_label ? '' : '<span>Prepared with KiddieTrac</span>') + '</div>';
  }
  function legendPrint() {
    return '<div class="leg">' + STAMPS.map(function (s) { return '<span>' + s.e + ' ' + esc(s.label) + '</span>'; }).join('')
      + '<span><i style="background:#16A34A"></i>Primary route</span><span><i style="background:repeating-linear-gradient(90deg,#F59E0B 0 6px,transparent 6px 9px)"></i>Alternate route</span></div>';
  }
  function buildPrint(r, img, layout) {
    var p = r.plan, doc = r.doc || {}, d = p.details || {};
    var draw = img ? '<div class="draw"><img src="' + img + '" alt="Fire plan drawing"></div>' : '<div class="draw" style="padding:40px;color:#64748B;">No drawing</div>';
    var html;
    if (layout === 'post') {
      var meet = (p.tree.steps || []).filter(function (s) { return /meet/i.test(s.title); })[0];
      html = docHead(doc, 'Fire plan', placeLabel(p))
        + '<div class="post"><div>' + draw + legendPrint() + '</div><div><div class="big">IN CASE OF FIRE</div>'
        + '<div class="call">Get everyone out · Call 911 from outside</div>'
        + (doc.address ? '<div style="font-size:10pt;margin-bottom:8px;"><b>Our address:</b> ' + esc(doc.address) + '</div>' : '')
        + '<ol class="steps">' + (p.tree.steps || []).map(function (s) { var x = [s.who, s.what, s.where].filter(Boolean).join(' · '); return '<li><b>' + esc(s.title) + '</b>' + (x ? '<span>' + esc(x) + '</span>' : '') + '</li>'; }).join('') + '</ol>'
        + (p.tree.branches || []).map(function (b) { var x = [b.who, b.what, b.where].filter(Boolean).join(' · '); return '<div class="meet" style="border-color:#D97706;"><b>' + esc(b.title) + ':</b> ' + esc(x || b.hint) + '</div>'; }).join('')
        + (meet && (meet.where || meet.what) ? '<div class="meet"><b>🚩 Meeting place:</b> ' + esc(meet.where || meet.what) + '</div>' : '')
        + '</div></div>' + footer(doc, p);
      return '<!doctype html><html><head><meta charset="utf-8"><title>Fire plan · ' + esc(placeLabel(p)) + '</title><style>' + PRINT_CSS.replace('size:letter portrait', 'size:letter landscape')
        + ':root{--c:' + esc(doc.color || '#1F6080') + ';}.draw img{max-height:5.6in;}</style></head><body>' + html + '</body></html>';
    }
    var kv = detailsKv(p, doc);
    html = docHead(doc, 'Fire plan', placeLabel(p) + ' · v' + p.version)
      + draw + legendPrint()
      + '<div class="mini">' + [['Exits', d.exits_count], ['Children', d.children_count], ['Ages', d.ages], ['Reviewed', day(d.reviewed_on)]].filter(function (x) { return x[1] != null && x[1] !== ''; })
        .map(function (x) { return '<span><b>' + esc(x[0]) + ':</b> ' + esc(x[1]) + '</span>'; }).join('') + '</div>'
      + footer(doc, p)
      + '<div class="pb"></div>' + docHead(doc, 'What we do', placeLabel(p) + ' · v' + p.version)
      + flowHtml(p.tree)
      + '<table class="kv">' + kv.map(function (x) { return '<tr><td class="k">' + esc(x[0]) + '</td><td>' + esc(x[1]) + '</td></tr>'; }).join('')
      + '<tr><td class="k">Prepared by</td><td>' + esc(doc.prepared_by || '') + (p.submitted_at ? ' · ' + esc(day(p.submitted_at)) : '') + '</td></tr>'
      + (doc.approved_by ? '<tr><td class="k">Approved by</td><td>' + esc(doc.approved_by) + ' · ' + esc(day(p.reviewed_at)) + '</td></tr>' : '') + '</table>'
      + '<div class="sig"><div>Reviewed with staff (signature)</div><div>Date</div></div>'
      + footer(doc, p);
    return '<!doctype html><html><head><meta charset="utf-8"><title>Fire plan · ' + esc(placeLabel(p)) + '</title><style>' + PRINT_CSS + ':root{--c:' + esc(doc.color || '#1F6080') + ';}</style></head><body>' + html + '</body></html>';
  }
  function print(id, layout, have) {
    css();
    var got = have ? Promise.resolve(have) : Api().get('/drills/fire-plans/' + id);
    return got.then(function (r) {
      var img = r.plan.has_png ? planImageUrl(r.plan.id, 'png').then(blobToDataUrl).catch(function () { return null; }) : Promise.resolve(null);
      return img.then(function (src) {
        var html = buildPrint(r, src, layout);
        if (KT.printDoc) KT.printDoc({ html: html }, { title: 'Fire plan' });
        else { var w = window.open('', '_blank'); if (w) { w.document.write(html); w.document.close(); w.print(); } }
      });
    }).catch(function (e) { toast('⚠️', 'Could not print the plan', said(e, 'Please try again.'), '#DC2626'); });
  }

  /* ═══ THE TAB ══════════════════════════════════════════════════════ */
  function paintTab(pane, ctx) {
    css();
    ctx = ctx || {};
    pane.innerHTML = '<div class="dr-card" style="color:#64748B;">Loading fire plans…</div>';
    var again = function () { forget(); paintTab(pane, ctx); if (ctx.again) ctx.again(true); };
    list(ctx.centre || '', true).then(function (L) {
      if (!pane.isConnected) return;
      var places = L.places || [];
      // Centres with no plan at all still get a line, so it is clear what is missing.
      (L.centres || []).forEach(function (c) { if (!places.some(function (p) { return p.centre_id === c.id; })) places.push({ key: c.id + ':0', centre_id: c.id, room_id: null, centre_name: c.name, room_name: '', versions: [], none: true }); });
      var h = '<div class="dr-card"><div class="dr-top" style="display:flex;gap:10px 14px;align-items:flex-start;flex-wrap:wrap;"><div style="flex:1;min-width:220px;"><div class="dr-h">Fire plans</div>'
        + '<div class="dr-sub" style="margin-bottom:0;">A drawn plan of each ' + esc(term('centre')) + ' or room with what everyone does, step by step. A director or admin approves it; the approved version is the current plan. Review each plan at least once a year.</div></div>'
        + '<div class="dr-row" style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="dr-b p" id="fp-new">＋ New fire plan</button></div></div>';
      if ((L.queue || []).length) {
        h += '<div class="fp-q" style="margin-top:12px;"><div style="font-weight:800;font-size:13.5px;margin-bottom:6px;">⏳ Waiting for your review (' + L.queue.length + ')</div>'
          + L.queue.map(function (q, i) {
              return '<div class="dr-it" role="button" tabindex="0" data-q="' + i + '" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr) minmax(0,1fr) 32px;">'
                + '<span class="dr-it-w"><b>' + esc(placeLabel(q)) + '</b> · v' + q.version + '</span><span class="dr-it-b">' + esc(q.created_by_name) + '</span>'
                + '<span class="dr-it-b">' + esc(day(q.submitted_at)) + '</span>'
                + '<button type="button" class="kt-ka-kebab dr-it-k" data-kt-inpage data-qk="' + i + '" aria-label="Actions for this plan" aria-haspopup="menu">⋮</button></div>';
            }).join('') + '</div>';
      }
      h += '<div style="overflow-x:auto;margin-top:12px;" class="fp-tbl"><table class="dr-t"><thead><tr><th></th><th>Place</th><th>Current plan</th><th>Status</th><th>Latest</th><th></th></tr></thead><tbody>';
      places.forEach(function (pl, i) {
        var vs = pl.versions || [], cur = vs.filter(function (v) { return v.is_current; })[0], latest = vs[0];
        var st = pl.none || !vs.length ? 'none' : (cur ? (pl.review_due ? 'review_due' : 'current') : latest.status);
        h += '<tr data-pl="' + i + '" style="cursor:' + (vs.length ? 'pointer' : 'default') + ';"><td style="width:76px;"><span class="fp-thumb none" data-th="' + ((cur || latest || {}).has_png ? (cur || latest).id : '') + '">🗺️</span></td>'
          + '<td><div class="fp-place">' + esc(pl.centre_name) + '</div><div class="dr-d2">' + esc(pl.room_name || term('Whole centre')) + '</div></td>'
          + '<td>' + (cur ? '<b>Version ' + cur.version + '</b><div class="dr-d2">Reviewed ' + esc(day(cur.reviewed_on)) + '</div>' : '<span style="color:#94A3B8;">—</span>') + '</td>'
          + '<td>' + chip(st) + (pl.pending && cur ? ' ' + chip('submitted', '⏳ ' + pl.pending + ' waiting') : '') + '</td>'
          + '<td>' + (latest && (!cur || latest.id !== cur.id) ? '<div style="font-size:13px;">v' + latest.version + ' · ' + esc(latest.created_by_name) + '</div><div class="dr-d2">' + esc(day(latest.submitted_at || latest.updated_at)) + '</div>' : '<span style="color:#94A3B8;">—</span>') + '</td>'
          + '<td style="width:40px;text-align:right;"><button type="button" class="kt-ka-kebab" data-kt-inpage data-plk="' + i + '" aria-label="Actions for this fire plan" aria-haspopup="menu">⋮</button></td></tr>';
      });
      h += '</tbody></table></div></div>';
      pane.innerHTML = h;
      // thumbnails (the PNG snapshot, fetched with the session)
      pane.querySelectorAll('[data-th]').forEach(function (t) {
        var id = t.getAttribute('data-th'); if (!id) return;
        planImageUrl(id, 'png').then(function (u) { var im = document.createElement('img'); im.className = 'fp-thumb'; im.alt = ''; im.src = u; t.replaceWith(im); }, function () {});
      });
      var onChange = function () { again(); };
      pane.querySelector('#fp-new').addEventListener('click', function () { editor({ centre_id: ctx.centre || '' }).then(function (x) { if (x) again(); }); });
      pane.querySelectorAll('[data-pl]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
          if (e.target.closest && e.target.closest('.kt-ka-kebab')) return;
          var pl = places[+tr.getAttribute('data-pl')], vs = pl.versions || [], cur = vs.filter(function (v) { return v.is_current; })[0];
          if (vs.length) view((cur || vs[0]).id, { onChange: onChange });
          else editor({ centre_id: pl.centre_id }).then(function (x) { if (x) again(); });
        });
      });
      pane.querySelectorAll('[data-plk]').forEach(function (k) {
        k.addEventListener('click', function (e) {
          e.stopPropagation();
          var pl = places[+k.getAttribute('data-plk')], vs = pl.versions || [], cur = vs.filter(function (v) { return v.is_current; })[0], latest = vs[0], acts = [];
          var mk = function (label, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', fn); acts.push(b); };
          if (cur) mk('ℹ️ View the current plan', function () { view(cur.id, { onChange: onChange }); });
          if (latest && (!cur || latest.id !== cur.id)) mk('ℹ️ View version ' + latest.version + ' (' + (STATUS[latest.status] || STATUS.none)[2].replace(/^\S+\s/, '').toLowerCase() + ')', function () { view(latest.id, { onChange: onChange }); });
          if (latest && latest.can_edit_draft) mk('✏️ Continue my draft', function () { editor({ base: latest.id }).then(function (x) { if (x) again(); }); });
          else if (vs.length) mk('✏️ Update the plan (new version)', function () { editor({ base: (latest.status === 'changes' ? latest : (cur || latest)).id }).then(function (x) { if (x) again(); }); });
          else mk('✏️ Draw a plan', function () { editor({ centre_id: pl.centre_id, room_id: pl.room_id }).then(function (x) { if (x) again(); }); });
          if (cur || latest) {
            var pid = (cur || latest).id;
            mk('🖨 Print / PDF', function () { print(pid, 'full'); });
            mk('📌 Post this plan', function () { print(pid, 'post'); });
          }
          if (KT.openActionMenu) KT.openActionMenu(k, acts);
        });
      });
      pane.querySelectorAll('[data-q]').forEach(function (row) {
        var q = L.queue[+row.getAttribute('data-q')];
        var open = function () { view(q.id, { onChange: onChange }); };
        row.addEventListener('click', function (e) { if (!(e.target.closest && e.target.closest('.dr-it-k'))) open(); });
        row.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === row) { e.preventDefault(); open(); } });
      });
      pane.querySelectorAll('[data-qk]').forEach(function (k) {
        k.addEventListener('click', function (e) {
          e.stopPropagation();
          var q = L.queue[+k.getAttribute('data-qk')], b = document.createElement('button'); b.type = 'button'; b.textContent = 'ℹ️ View and review';
          b.addEventListener('click', function () { view(q.id, { onChange: onChange }); });
          if (KT.openActionMenu) KT.openActionMenu(k, [b]);
        });
      });
      if (ctx.openPlan) { var id = ctx.openPlan; ctx.openPlan = null; view(id, { onChange: onChange }); }
    }, function (e) { pane.innerHTML = '<div class="dr-card" style="color:#B91C1C;">' + esc(said(e, 'Could not load the fire plans.')) + '</div>'; });
  }

  /* ═══ SETTINGS: THE STEPS EVERY NEW PLAN STARTS FROM ═════════════════ */
  function templateCard(el) {
    css();
    el.innerHTML = '<div class="dr-card" style="color:#64748B;">Loading the fire plan steps…</div>';
    list('', true).then(function (L) {
      if (!L.is_admin) { el.innerHTML = ''; return; }
      var T = JSON.parse(JSON.stringify(L.template || { steps: [], branches: [] }));
      function draw() {
        var stepOpts = function (sel) { return T.steps.map(function (s, i) { return '<option value="' + esc(s.k) + '"' + (s.k === sel ? ' selected' : '') + '>' + (i + 1) + '. ' + esc(s.title) + '</option>'; }).join(''); };
        el.innerHTML = '<div class="dr-card"><div class="dr-h">🗺️ Fire plan steps</div><div class="dr-sub">The "what we do" steps every new fire plan starts from. Educators fill in who, what and where for their ' + esc(term('centre')) + ' or room. Plans already made keep their own steps.</div>'
          + T.steps.map(function (s, i) {
              return '<div class="fp-step"><div class="fp-step-h"><span class="fp-n">' + (i + 1) + '</span><input class="fp-i" data-s="' + i + '|title" value="' + esc(s.title) + '" maxlength="120" aria-label="Step title" style="font-weight:700;">'
                + '<button type="button" class="kt-ka-kebab" data-kt-inpage data-sk="' + i + '" aria-label="Actions for this step" aria-haspopup="menu">⋮</button></div>'
                + '<input class="fp-i" data-s="' + i + '|hint" value="' + esc(s.hint) + '" maxlength="300" placeholder="A hint for the educator (optional)" aria-label="Hint" style="margin-left:30px;width:calc(100% - 30px);"></div>';
            }).join('')
          + '<div style="margin:6px 0 14px;"><button type="button" class="dr-b" id="fpt-add">＋ Add a step</button></div>'
          + '<div class="dr-h" style="font-size:14px;">Branches</div><div class="dr-sub">What changes when something goes wrong, shown beside its step.</div>'
          + T.branches.map(function (b, i) {
              return '<div class="fp-step br"><div class="fp-step-h"><span class="fp-n">↳</span><input class="fp-i" data-b="' + i + '|title" value="' + esc(b.title) + '" maxlength="120" aria-label="Branch title" style="font-weight:700;">'
                + '<button type="button" class="kt-ka-kebab" data-kt-inpage data-bk="' + i + '" aria-label="Actions for this branch" aria-haspopup="menu">⋮</button></div>'
                + '<div class="fp-g" style="margin-left:30px;"><div><label class="fp-l">After step</label><select class="fp-i" data-b="' + i + '|after">' + stepOpts(b.after) + '</select></div>'
                + '<div><label class="fp-l">Hint</label><input class="fp-i" data-b="' + i + '|hint" value="' + esc(b.hint) + '" maxlength="300"></div></div></div>';
            }).join('')
          + '<div style="margin:6px 0 14px;"><button type="button" class="dr-b" id="fpt-addb">＋ Add a branch</button></div>'
          + '<div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="dr-b p" id="fpt-save">Save the steps</button><button type="button" class="dr-b" id="fpt-reset">Reset to the KiddieTrac steps</button></div></div>';
        el.querySelectorAll('[data-s]').forEach(function (x) { x.addEventListener('input', function () { var p = x.getAttribute('data-s').split('|'); T.steps[+p[0]][p[1]] = x.value; }); });
        el.querySelectorAll('[data-b]').forEach(function (x) { x.addEventListener(x.tagName === 'SELECT' ? 'change' : 'input', function () { var p = x.getAttribute('data-b').split('|'); T.branches[+p[0]][p[1]] = x.value; }); });
        el.querySelector('#fpt-add').addEventListener('click', function () { var n = 1; while (T.steps.some(function (s) { return s.k === 's' + n; })) n++; T.steps.push({ k: 's' + n, title: 'New step', hint: '' }); draw(); });
        el.querySelector('#fpt-addb').addEventListener('click', function () { var n = 1; while (T.branches.some(function (s) { return s.k === 'b' + n; })) n++; T.branches.push({ k: 'b' + n, after: (T.steps[0] || {}).k, title: 'If …', hint: '' }); draw(); });
        el.querySelectorAll('[data-sk]').forEach(function (k) {
          k.addEventListener('click', function () {
            var i = +k.getAttribute('data-sk'), acts = [];
            var mk = function (label, fn, dis) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (dis) b.disabled = true; b.addEventListener('click', fn); acts.push(b); };
            mk('⬆️ Move up', function () { var x = T.steps.splice(i, 1)[0]; T.steps.splice(i - 1, 0, x); draw(); }, i === 0);
            mk('⬇️ Move down', function () { var x = T.steps.splice(i, 1)[0]; T.steps.splice(i + 1, 0, x); draw(); }, i === T.steps.length - 1);
            mk('🗑 Remove this step', function () { var k2 = T.steps[i].k; T.steps.splice(i, 1); T.branches = T.branches.filter(function (b) { return b.after !== k2; }); draw(); }, T.steps.length <= 3);
            if (KT.openActionMenu) KT.openActionMenu(k, acts);
          });
        });
        el.querySelectorAll('[data-bk]').forEach(function (k) {
          k.addEventListener('click', function () {
            var i = +k.getAttribute('data-bk'), b = document.createElement('button'); b.type = 'button'; b.textContent = '🗑 Remove this branch';
            b.addEventListener('click', function () { T.branches.splice(i, 1); draw(); });
            if (KT.openActionMenu) KT.openActionMenu(k, [b]);
          });
        });
        el.querySelector('#fpt-save').addEventListener('click', function () {
          Api().post('/drills/fire-plans/template', { tree: T }).then(function (r) { T = r.template; forget(); toast('🗺️', 'Fire plan steps saved', 'New plans start from these steps.', '#16A34A'); draw(); },
            function (e) { toast('⚠️', 'Could not save', said(e, ''), '#DC2626'); });
        });
        el.querySelector('#fpt-reset').addEventListener('click', async function () {
          var ok = KT.confirm ? await KT.confirm({ title: 'Reset to the KiddieTrac steps?', description: 'Your edited steps are replaced by the 11 standard ones. Plans already made keep their own steps.', okLabel: 'Reset' }) : true;
          if (!ok) return;
          Api().post('/drills/fire-plans/template', { reset: true }).then(function (r) { T = r.template; forget(); toast('🗺️', 'Steps reset', '', '#16A34A'); draw(); },
            function (e) { toast('⚠️', 'Could not save', said(e, ''), '#DC2626'); });
        });
      }
      draw();
    }, function () { el.innerHTML = ''; });
  }

  KT.FirePlan = { paintTab: paintTab, editor: editor, view: view, print: print, templateCard: templateCard, list: list, forget: forget, flowHtml: flowHtml,
    _test: { exportPng: exportPng, TEMPLATES: TEMPLATES, emptyDrawing: emptyDrawing, drawScene: drawScene, buildPrint: buildPrint } };
})(window);
