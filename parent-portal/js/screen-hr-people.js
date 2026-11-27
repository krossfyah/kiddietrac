/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR Phase 4, batch 6 (2026-11-27): staff directory + org chart, recognition (kudos), time-off
   approvers + cover while away, and course quizzes. window.KTHrPeople, a lazy dependency of screen-hr.js and
   screen-hr-talent.js (like screen-hr-reports.js).

   Where it draws:
     Human Resources › People          tabs Directory, Org chart        (directory, orgchart: a diagram + print/PDF)
     Human Resources › Educator awards tab Recognition                  (#educator-rewards?tab=kudos)
     Human Resources › HR settings     tab Approvers (admins)           (approvers)
     My HR (everyone on staff)         sections Team directory, Kudos; for directors "Cover while I'm away"
     Courses                           quizEditor (admins), quizInfo/quizTake (the person), from screen-hr-talent.js
   The server scopes every list (active agency; directors and staff their own centres); nothing is filtered here
   except the search box. House patterns: data-kt-pretty tables with plain buttons in the last cell (kt-row-actions
   turns them into the ⋮), KT.Shell.Modal popups, ⋮ inside popups and on feed cards via KT.openActionMenu.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var m = (e && ((e.data && e.data.message) || e.message)) || '';
    return m && !/fetch|network|undefined|null|status \d/i.test(m) ? m : 'That did not work. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function when(s) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); if (!m) return day(s); var h = +m[4], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + h + ':' + m[5] + ' ' + ap; }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function addDays(ymd, n) { var p = ymd.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2] + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function closeModal() { try { var r = document.getElementById('modalRoot'); var x = r && r.querySelector('.modal-close'); if (x) x.click(); } catch (e) {} }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function input(k, v, type, extra) { return '<input class="hr-i" data-f="' + k + '" type="' + (type || 'text') + '" value="' + esc(v == null ? '' : v) + '" style="width:100%;"' + (extra || '') + '>'; }
  function area(k, v, rows) { return '<textarea class="hr-i" data-f="' + k + '" rows="' + (rows || 3) + '" style="width:100%;min-height:' + ((rows || 3) * 22) + 'px;">' + esc(v || '') + '</textarea>'; }
  function options(list, sel, blank) { return (blank ? '<option value="">' + esc(blank) + '</option>' : '') + (list || []).map(function (o) { return '<option value="' + o.id + '"' + (String(sel) === String(o.id) ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join(''); }
  function avatar(p, size) { try { if (KT.avatar) return KT.avatar(p.name, { size: size || 28, photoUrl: p.photo_url, sex: p.sex, userId: p.id }); } catch (e) {} return ''; }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';
  function kebabBtn(key) { return '<button type="button" class="kt-ka-kebab hp-keb" data-keb="' + esc(key) + '" aria-label="Actions" data-kt-iconized="1">⋮</button>'; }
  function menu(anchor, items) {
    var bs = items.filter(Boolean).map(function (it) { var b = document.createElement('button'); b.type = 'button'; b.textContent = it[0]; b.addEventListener('click', function () { it[1](); }); return b; });
    if (KT.openActionMenu) KT.openActionMenu(anchor, bs); else if (bs[0]) bs[0].click();
  }
  function picker(list, chosen, key) {
    return '<input class="hr-i" data-q placeholder="Search names" style="width:100%;margin-bottom:6px;"><div class="hp-pick" data-kt-noswitch>' + (list || []).map(function (p) {
      return '<label data-name="' + esc(String(p.name).toLowerCase()) + '"><input type="checkbox" data-' + key + '="' + p.id + '"' + (chosen && chosen.indexOf(p.id) >= 0 ? ' checked' : '') + '> <span data-no-i18n>' + esc(p.name) + '</span></label>';
    }).join('') + '</div>';
  }
  function wirePicker(box) {
    var q = box.querySelector('[data-q]'); if (!q) return;
    q.addEventListener('input', function () { var s = q.value.trim().toLowerCase(); box.querySelectorAll('[data-name]').forEach(function (l) { l.style.display = !s || l.getAttribute('data-name').indexOf(s) >= 0 ? '' : 'none'; }); });
  }

  var CSS_ID = 'kt-hrp-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '.hp-sec{font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;margin:16px 0 8px;}'
      + '.hp-pick{max-height:240px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;padding:6px 8px;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(200px,100%),1fr));gap:2px 10px;}'
      + '.hp-pick label{display:flex;gap:7px;align-items:center;font-size:13px;padding:3px 0;}'
      + '.hp-who{display:flex;align-items:center;gap:9px;min-width:0;}.hp-who b{font-size:13.5px;}'
      + '.hp-feed{display:grid;gap:8px;}'
      + '.hp-k{position:relative;border:1px solid #E2E8F0;border-radius:12px;background:#fff;padding:10px 40px 10px 12px;}'
      + '.hp-k .hp-kh{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px;}'
      + '.hp-k .hp-km{font-size:13.5px;color:#0F172A;margin:6px 0 4px;line-height:1.5;white-space:pre-wrap;}'
      + '.hp-k .hp-keb{position:absolute;top:6px;right:6px;}'
      + '.hp-tag{display:inline-block;padding:1px 8px;border-radius:999px;background:#ECFEFF;color:#0E7490;border:1px solid #A5F3FC;font-size:11.5px;font-weight:700;margin:0 4px 0 0;}'
      + '.hp-tags{display:flex;flex-wrap:wrap;gap:6px;}.hp-tags label{display:inline-flex;gap:6px;align-items:center;border:1px solid #E2E8F0;border-radius:999px;padding:3px 10px;font-size:12.5px;cursor:pointer;}'
      + '.hp-keb{width:28px;height:28px;border-radius:8px;border:1px solid transparent;background:transparent;cursor:pointer;font-size:16px;line-height:1;color:#475569;}'
      + '.hp-keb:hover{background:#F1F5F9;border-color:#E2E8F0;}'
      + '.hp-tree{list-style:none;margin:0;padding:0;}.hp-tree .hp-tree{margin-left:18px;padding-left:12px;border-left:2px solid #E2E8F0;}'
      + '.hp-node{display:flex;align-items:center;gap:9px;border:1px solid #E2E8F0;border-radius:12px;background:#fff;padding:7px 10px;margin:6px 0;max-width:420px;}'
      + '.hp-node .hp-nt{font-size:12px;color:#64748B;}.hp-node.out{background:#F8FAFC;border-style:dashed;}'
      + '.hp-q{border:1px solid #E2E8F0;border-radius:12px;padding:10px 12px;margin:0 0 8px;background:#fff;}'
      + '.hp-q .hp-qh{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-bottom:6px;}'
      + '.hp-q label.hp-opt{display:flex;gap:8px;align-items:flex-start;font-size:13.5px;padding:4px 0;cursor:pointer;}'
      + '.hp-orow{display:grid;grid-template-columns:auto 1fr auto;gap:6px;align-items:center;margin:4px 0;}'
      + 'html.kt-dark .hp-k,html.kt-dark .hp-node,html.kt-dark .hp-q{background:#0F172A;border-color:#334155;}html.kt-dark .hp-k .hp-km{color:#E2E8F0;}'
      + 'html.kt-dark .hp-node.out{background:#111827;}html.kt-dark .hp-tree .hp-tree{border-color:#334155;}html.kt-dark .hp-sec{color:#5EEAD4;}'
      + 'html.kt-dark .hp-tag{background:#0B2530;border-color:#155E75;color:#A5F3FC;}html.kt-dark .hp-pick,html.kt-dark .hp-tags label{border-color:#334155;}';
    document.head.appendChild(s);
  }
  function goPerson(id) { location.hash = '#hr-people?person=' + id; }

  // ══ DIRECTORY ══════════════════════════════════════════════════════════
  function contact(p) {
    var out = [];
    if (p.email) out.push('<a href="mailto:' + esc(p.email) + '" data-no-i18n>' + esc(p.email) + '</a>');
    if (p.phone) out.push('<a href="tel:' + esc(String(p.phone).replace(/[^0-9+]/g, '')) + '" data-no-i18n>' + esc(p.phone) + '</a>');
    return out.length ? out.join('<br>') : '<span class="hr-sub">Not shared</span>';
  }
  function roleLine(p) { return esc(p.job_title || p.role_label || ''); }
  function nameOf(list, id) { var x = (list || []).filter(function (p) { return p.id === id; })[0]; return x ? x.name : ''; }

  function directoryTable(d, manager) {
    var all = (d.people || []).concat(d.outside || []);
    return '<div class="kt-card"><table data-hrp="directory" style="width:100%;"><thead><tr><th>Name</th><th>Role</th><th>Centres</th><th>Manager</th><th>Contact</th><th></th></tr></thead><tbody>'
      + (d.people || []).map(function (p) {
        return '<tr data-id="' + p.id + '" data-name="' + esc(String(p.name).toLowerCase()) + '"><td><div class="hp-who">' + avatar(p, 30) + '<b data-no-i18n>' + esc(p.name) + '</b>' + (p.id === d.me ? ' <span class="hr-sub">(you)</span>' : '') + '</div></td>'
          + '<td>' + roleLine(p) + '</td><td data-no-i18n>' + esc((p.centres || []).join(', ')) + '</td><td data-no-i18n>' + esc(nameOf(all, p.manager_id)) + '</td><td>' + contact(p) + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-dv="' + p.id + '">View</button>'
          + (manager && p.id !== d.me ? '<button type="button" data-dhr="' + p.id + '">Open HR file</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  function viewPerson(d, p) {
    var all = (d.people || []).concat(d.outside || []);
    var reports = (d.people || []).filter(function (x) { return x.manager_id === p.id; });
    var box = el('<div class="hp-who" style="margin-bottom:10px;">' + avatar(p, 48) + '<div><b data-no-i18n style="font-size:16px;">' + esc(p.name) + '</b><div class="hr-sub">' + roleLine(p) + '</div></div></div>'
      + '<div class="hr-kv"><span>Centres</span><b data-no-i18n>' + esc((p.centres || []).join(', ') || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Manager</span><b data-no-i18n>' + esc(nameOf(all, p.manager_id) || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Reports to them</span><b data-no-i18n>' + esc(reports.map(function (r) { return r.name; }).join(', ') || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Email</span><b>' + (p.email ? '<a href="mailto:' + esc(p.email) + '" data-no-i18n>' + esc(p.email) + '</a>' : 'Not shared') + '</b></div>'
      + '<div class="hr-kv"><span>Phone</span><b>' + (p.phone ? '<a href="tel:' + esc(String(p.phone).replace(/[^0-9+]/g, '')) + '" data-no-i18n>' + esc(p.phone) + '</a>' : 'Not shared') + '</b></div>'
      + '<div class="hr-sub" style="margin-top:8px;">People choose whether colleagues see their email and phone (My HR › Team directory).</div>');
    modal({ title: p.name, body: box, actions: [{ label: 'Close' }].concat(p.id !== d.me ? [{ label: '🙌 Give kudos', primary: true, handler: function () { setTimeout(function () { giveKudos(null, p.id, null); }, 0); } }] : []) });
  }
  function wireDirectory(host, d, manager) {
    var q = host.querySelector('[data-dq]');
    if (q) q.addEventListener('input', function () { var s = q.value.trim().toLowerCase(); host.querySelectorAll('tr[data-name]').forEach(function (r) { r.style.display = !s || r.getAttribute('data-name').indexOf(s) >= 0 ? '' : 'none'; }); });
    host.querySelectorAll('[data-dv]').forEach(function (b) { b.addEventListener('click', function () { var p = d.people.filter(function (x) { return x.id === +b.getAttribute('data-dv'); })[0]; if (p) viewPerson(d, p); }); });
    host.querySelectorAll('[data-dhr]').forEach(function (b) { b.addEventListener('click', function () { goPerson(+b.getAttribute('data-dhr')); }); });
  }
  async function drawDirectory(host) {
    var d; try { d = await Api.get('/hr/directory'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    host.innerHTML = '<div class="hr-note">' + (d.people || []).length + ' people. Email and phone show only when the person chose to share them (My HR › Team directory).</div>'
      + directoryTable(d, true);
    wireDirectory(host, d, true);
  }

  // ══ ORG CHART ══════════════════════════════════════════════════════════
  /* THE DIAGRAM + PRINTABLE CHART (2026-11-27, Anthony: "for org chart there should be a way to generate a printable pdf
     org chart that uses a diagram view with the agency logo and information and details on each person's position").
     A top-down tree drawn from the manager on each person's HR file. GET hr/directory already scopes who the caller may
     see (admins the agency, everyone else their own centres; leavers and outside accounts dropped; contact only when the
     person shared it), so nothing here widens anything: the centre filter only narrows what was sent.
       - teams of three or more people with no reports of their own stack in columns under their manager; a level wider
         than OC.MAXW wraps onto another row off a trunk line, so wide levels stay readable;
       - the centre filter keeps the managers above that centre's people (dashed, "outside this centre") so the line
         upward is never cut; a manager at another centre is dashed too (the server sends only name and job title);
       - ▾ / ▸ under a card folds that branch; zoom − / Fit / +; drag the background sideways to pan;
       - 🖨 Print / Save as PDF: KT.printDoc (the house print path: apps KtPrint, phones the top page, desktop a frame),
         letter landscape, the agency letterhead (Brand logo for white-label agencies), "As of" the agency's today, page
         footer with page numbers (scaled with CSS zoom, not transform: a transformed box is paginated at its unscaled
         size and Chrome cut the bottom of a full page). A chart that would print smaller than OC.MIN_S splits by branch: the top of the tree
         on one page with "Team continues on page N", each big team on its own page with "Reports to X · page M".
     Why not a server PDF: there is no headless Chrome on the host (and web PHP has no proc_open), and dompdf has no
     flexbox / transforms / SVG paths good enough for a diagram; the browser's "Save as PDF" of this page is vector, uses
     exactly the drawing on screen and works in the apps' print sheets too. */
  var EMP = { full_time: 'Full-time', part_time: 'Part-time', casual: 'Casual', student: 'Student', contract: 'Contract' };
  var OC = { W: 230, H: 118, GX: 24, GY: 44, SX: 14, SY: 10, IND: 18, MAXW: 1500, GAP: 56, AW: 980, AH: 588, MIN_S: 0.5 };
  function T(s) { try { return KT.i18n && KT.i18n.t ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function lang() { var l = ''; try { l = (KT.i18n && KT.i18n.locale && KT.i18n.locale()) || ''; } catch (e) {} return !l || /^en/i.test(l) ? 'en-US' : l; }
  function fmtDate(ymd, o) { var m = /^(\d{4})-(\d{2})(?:-(\d{2}))?/.exec(ymd || ''); if (!m) return ''; try { return new Date(Date.UTC(+m[1], +m[2] - 1, +(m[3] || 15), 12)).toLocaleDateString(lang(), Object.assign({ timeZone: 'UTC' }, o)); } catch (e) { return m[3] ? day(ymd) : MON[+m[2] - 1] + ' ' + m[1]; } }
  function tenure(ym, now) {
    var a = /^(\d{4})-(\d{2})/.exec(ym || ''), b = /^(\d{4})-(\d{2})/.exec(now || ''); if (!a || !b) return '';
    var mo = (+b[1] * 12 + +b[2]) - (+a[1] * 12 + +a[2]); if (mo < 0) return T('Starting soon');
    if (mo < 1) return T('New');
    if (mo < 12) return mo + ' ' + T(mo === 1 ? 'month' : 'months');
    var y = Math.floor(mo / 12); return y + ' ' + T(y === 1 ? 'year' : 'years');
  }
  function initialsAv(p, size, color) {
    var nm = String(p.name || '').trim(), ini = (nm.split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join('') || '?').toUpperCase();
    var base = 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:' + Math.round(size * 0.4) + 'px;color:#fff;background:' + color + ';overflow:hidden;position:relative;';
    var src = p.photo_url ? String(p.photo_url) : '';
    if (src) { try { src = new URL(src, location.href).href; } catch (e) {} }
    return '<span style="' + base + '">' + esc(ini) + (src ? '<img src="' + esc(src) + '" alt="" onerror="this.remove()" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">' : '') + '</span>';
  }

  /* The forest for one centre filter (0 = everyone the caller may see). */
  function ocModel(d, centreId) {
    var people = d.people || [], by = {}, show = {}, cid = +centreId || 0;
    people.forEach(function (p) { by[p.id] = p; });
    (d.outside || []).forEach(function (p) { if (!by[p.id]) by[p.id] = Object.assign({ outside: true }, p); });
    people.forEach(function (p) { if (!cid || (p.centre_ids || []).indexOf(cid) >= 0) show[p.id] = 1; });
    Object.keys(show).forEach(function (id) { var m = by[id].manager_id, g = 0; while (m && by[m] && !show[m] && g++ < 25) { show[m] = 2; m = by[m].manager_id; } });
    var kids = {};
    Object.keys(show).forEach(function (id) { var p = by[id]; if (p.manager_id && show[p.manager_id] && +p.manager_id !== +id) (kids[p.manager_id] = kids[p.manager_id] || []).push(p); });
    var seen = {};
    function size(id, g) { return g > 14 ? 0 : (kids[id] || []).reduce(function (s, k) { return s + 1 + size(k.id, g + 1); }, 0); }
    function mk(p, depth) {
      seen[p.id] = 1;
      var ks = (kids[p.id] || []).filter(function (k) { return !seen[k.id]; });
      ks.sort(function (a, b) { return (kids[a.id] ? 0 : 1) - (kids[b.id] ? 0 : 1) || String(a.name).localeCompare(String(b.name), undefined, { numeric: true }); });
      return { p: p, ctx: show[p.id] === 2 || !!p.outside, kids: depth > 14 ? [] : ks.map(function (k) { return mk(k, depth + 1); }) };
    }
    var ids = Object.keys(show).map(Number);
    var roots = ids.filter(function (id) { var p = by[id]; return (!p.manager_id || !show[p.manager_id]) && kids[id]; })
      .sort(function (a, b) { return size(b, 0) - size(a, 0) || String(by[a].name).localeCompare(String(by[b].name)); });
    var trees = roots.map(function (id) { return mk(by[id], 0); });
    var loose = ids.filter(function (id) { return !seen[id] && show[id] === 1; }).map(function (id) { return by[id]; })
      .sort(function (a, b) { return String(a.name).localeCompare(String(b.name), undefined, { numeric: true }); });
    return { trees: trees, loose: loose, by: by, people: ids.filter(function (id) { return show[id] === 1; }).length, managers: Object.keys(kids).length };
  }

  /* Layout: measure() sizes each branch, place() gives every card its x/y and collects the connector segments. */
  function ocVis(n, o) { return o.collapsed && o.collapsed[n.p.id] ? [] : n.kids; }
  function ocCols(c, o) { return o.print ? Math.min(6, Math.max(1, Math.ceil(c / 5))) : (c <= 5 ? 1 : c <= 12 ? 2 : 3); }
  function ocMeasure(n, o) {
    var ks = ocVis(n, o);
    n.mode = 'leaf'; n.w = OC.W; n.h = OC.H;
    if (!ks.length) return;
    ks.forEach(function (k) { ocMeasure(k, o); });
    if (ks.length >= 3 && ks.every(function (k) { return k.mode === 'leaf'; })) {
      var c = Math.min(ocCols(ks.length, o), ks.length), r = Math.ceil(ks.length / c);
      n.mode = 'stack'; n.rows = r; n.bw = c * (OC.IND + OC.W) + (c - 1) * OC.SX;
      n.w = Math.max(OC.W, n.bw); n.h = OC.H + OC.GY + r * OC.H + (r - 1) * OC.SY;
      return;
    }
    var rows = [], cur = [], cw = 0, maxW = o.maxW || OC.MAXW;
    ks.forEach(function (k) {
      var add = (cur.length ? OC.GX : 0) + k.w;
      if (cur.length && cw + add > maxW) { rows.push({ ks: cur, w: cw }); cur = []; cw = 0; add = k.w; }
      cur.push(k); cw += add;
    });
    rows.push({ ks: cur, w: cw });
    rows.forEach(function (r) { r.h = Math.max.apply(null, r.ks.map(function (k) { return k.h; })); });
    n.mode = 'rows'; n.rowsL = rows; n.trunk = rows.length > 1 ? 16 : 0;
    n.w = Math.max(OC.W, n.trunk + Math.max.apply(null, rows.map(function (r) { return r.w; })));
    n.h = OC.H + rows.reduce(function (s, r) { return s + OC.GY + r.h; }, 0);
  }
  function ocPlace(n, x, y, o, out) {
    n.x = x + (n.w - OC.W) / 2; n.y = y; out.cards.push(n);
    var ks = ocVis(n, o); if (!ks.length) return;
    var px = n.x + OC.W / 2, pb = y + OC.H, L = out.lines;
    if (n.mode === 'stack') {
      var left = x + (n.w - n.bw) / 2, top = pb + OC.GY, bus = pb + OC.GY / 2, spines = [];
      ks.forEach(function (k, i) {
        var c = Math.floor(i / n.rows), r = i % n.rows, cx = left + c * (OC.IND + OC.W + OC.SX);
        k.x = cx + OC.IND; k.y = top + r * (OC.H + OC.SY); out.cards.push(k);
        var my = k.y + OC.H / 2, sx = cx + OC.IND / 2;
        L.push([sx, my, k.x, my]);
        spines[c] = { x: sx, y: my };
      });
      var xs = spines.map(function (s) { return s.x; }).concat([px]);
      L.push([px, pb, px, bus]); L.push([Math.min.apply(null, xs), bus, Math.max.apply(null, xs), bus]);
      spines.forEach(function (s) { L.push([s.x, bus, s.x, s.y]); });
      return;
    }
    var ty = pb, tx = x + 6, prev = 0;
    n.rowsL.forEach(function (r, ri) {
      var bus = ty + OC.GY / 2, top = ty + OC.GY, cx = x + n.trunk + (n.w - n.trunk - r.w) / 2, xs = [];
      r.ks.forEach(function (k) { ocPlace(k, cx, top, o, out); var kc = k.x + OC.W / 2; xs.push(kc); L.push([kc, bus, kc, k.y]); cx += k.w + OC.GX; });
      if (ri === 0) { xs.push(px); L.push([px, pb, px, bus]); }
      if (n.trunk) { xs.push(tx); if (ri > 0) L.push([tx, prev, tx, bus]); }
      L.push([Math.min.apply(null, xs), bus, Math.max.apply(null, xs), bus]);
      prev = bus; ty = top + r.h;
    });
  }
  /* items: [{tree}] and [{group:[people]}] (No manager set), packed into rows no wider than maxW. */
  function ocForest(items, o) {
    var out = { cards: [], lines: [], groups: [], w: 0, h: 0 }, maxW = o.maxW || OC.MAXW;
    items.forEach(function (it) {
      if (it.tree) { ocMeasure(it.tree, o); it.w = it.tree.w; it.h = it.tree.h; return; }
      var c = Math.max(1, Math.min(it.group.length, o.print ? 6 : Math.max(1, Math.floor((maxW - 28 + OC.SX) / (OC.W + OC.SX))))), r = Math.ceil(it.group.length / c);
      it.cols = c; it.w = c * (OC.W + OC.SX) - OC.SX + 28; it.h = 34 + r * (OC.H + OC.SY) - OC.SY + 14;
    });
    var rows = [], cur = [], cw = 0;
    items.forEach(function (it) {
      var add = (cur.length ? OC.GAP : 0) + it.w;
      if (cur.length && (cw + add > maxW || it.group)) { rows.push(cur); cur = []; cw = 0; add = it.w; }
      cur.push(it); cw += add;
    });
    if (cur.length) rows.push(cur);
    var y = 6;
    rows.forEach(function (row) {
      var x = 6, rh = 0;
      row.forEach(function (it) {
        if (it.tree) ocPlace(it.tree, x, y + 4, o, out);
        else {
          out.groups.push({ x: x, y: y, w: it.w, h: it.h, title: T('No manager set') });
          it.group.forEach(function (p, i) { out.cards.push({ p: p, loose: true, kids: [], x: x + 14 + (i % it.cols) * (OC.W + OC.SX), y: y + 34 + Math.floor(i / it.cols) * (OC.H + OC.SY) }); });
        }
        x += it.w + OC.GAP; rh = Math.max(rh, it.h + 4);
        out.w = Math.max(out.w, x - OC.GAP + 6);
      });
      y += rh + 40;
    });
    out.h = y - 40 + 14;
    return out;
  }
  function ocCard(n, o, by, today) {
    var p = n.p, mgr = p.manager_id && by[p.manager_id], c = OC.W;
    var lines = [];
    if (n.ctx) {
      lines.push('<div class="oc-l oc-ctx">' + esc(T(p.outside ? 'Outside your centres' : (p.centres || []).length ? 'Outside this centre' : 'Agency-wide')) + '</div>');
    } else {
      var cen = (p.centres || []).length ? p.centres.join(', ') : (p.role === 'agency_admin' ? T('Agency-wide') : '');
      if (cen) lines.push('<div class="oc-l oc-cen" title="' + esc(cen) + '">' + esc(cen) + '</div>');
      var tn = p.since ? tenure(p.since, today) : '';
      var meta = [p.employment_type ? T(EMP[p.employment_type] || p.employment_type) : '', p.since ? T('Since') + ' ' + fmtDate(p.since, { month: 'short', year: 'numeric' }) : '', tn].filter(Boolean).join(' · ');
      if (meta) lines.push('<div class="oc-l" title="' + esc(meta) + '">' + esc(meta) + '</div>');
      if (mgr) lines.push('<div class="oc-l">' + esc(T('Reports to')) + ' <b>' + esc(mgr.name) + '</b>' + (n.fromItem && n.fromItem.page ? ' · ' + esc(T('page')) + ' ' + n.fromItem.page : '') + '</div>');
      else if (n.loose) lines.push('<div class="oc-l oc-ctx">' + esc(T('No manager set')) + '</div>');
      var ct = [p.email, p.phone].filter(Boolean).join(' · ');
      if (ct && !n.cont) lines.push('<div class="oc-l oc-ct" title="' + esc(ct) + '">' + esc(ct) + '</div>');
    }
    if (n.cont) lines.push('<div class="oc-l oc-more">' + esc(T('Team continues on page')) + ' ' + (n.cont.page || '?') + ' →</div>');
    var nk = n.kids ? n.kids.length : 0, folded = o.collapsed && o.collapsed[p.id];
    var title = p.job_title || p.role_label || '';
    return '<div class="oc-card' + (n.ctx ? ' ctx' : '') + '" data-oc-id="' + p.id + '" style="left:' + Math.round(n.x) + 'px;top:' + Math.round(n.y) + 'px;width:' + c + 'px;height:' + OC.H + 'px;">'
      + '<div class="oc-h">' + (o.print ? initialsAv(p, 36, o.color) : (n.ctx ? initialsAv(p, 36, '#94A3B8') : avatar(p, 36))) + '<div class="oc-nm"><b title="' + esc(p.name) + '">' + esc(p.name) + '</b><span title="' + esc(title) + '">' + esc(title) + '</span></div></div>'
      + lines.slice(0, 4).join('')
      + (!o.print && nk ? '<button type="button" class="oc-tog" data-oc-tog="' + p.id + '" data-kt-iconized="1" aria-label="' + esc(T(folded ? 'Show team' : 'Hide team')) + '">' + (folded ? '▸ ' + nk : '▾ ' + nk) + '</button>' : '')
      + '</div>';
  }
  function ocStage(L, o, by, today) {
    var d = L.lines.map(function (s) { return 'M' + Math.round(s[0]) + ' ' + Math.round(s[1]) + 'L' + Math.round(s[2]) + ' ' + Math.round(s[3]); }).join('');
    return '<svg class="oc-svg" width="' + Math.ceil(L.w) + '" height="' + Math.ceil(L.h) + '" style="position:absolute;left:0;top:0;" aria-hidden="true"><path d="' + d + '"/></svg>'
      + L.groups.map(function (g) { return '<div class="oc-grp" style="left:' + g.x + 'px;top:' + g.y + 'px;width:' + g.w + 'px;height:' + g.h + 'px;"><div class="oc-gt">' + esc(g.title) + '</div></div>'; }).join('')
      + L.cards.map(function (n) { return ocCard(n, o, by, today); }).join('');
  }
  var OC_CARD_CSS = '.oc-card{position:absolute;box-sizing:border-box;background:#fff;border:1px solid #CBD5E1;border-top:3px solid var(--oc-c,#1F6080);border-radius:10px;padding:7px 9px 6px;font-size:11.5px;line-height:1.4;color:#334155;text-align:left;}'
    + '.oc-h{display:flex;gap:8px;align-items:center;margin-bottom:4px;}.oc-nm{min-width:0;flex:1;}'
    + '.oc-nm b{display:block;font-size:13px;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
    + '.oc-nm span{display:block;font-weight:700;font-size:11.5px;color:var(--oc-c,#1F6080);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
    + '.oc-l{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.oc-l b{color:#0F172A;}.oc-ctx{color:#64748B;font-style:italic;}.oc-ct{color:#475569;}.oc-cen{font-weight:700;color:#0F172A;}'
    + '.oc-more{font-weight:800;color:var(--oc-c,#1F6080);}'
    + '.oc-card.ctx{background:#F8FAFC;border-style:dashed;border-top-style:dashed;}'
    + '.oc-grp{position:absolute;box-sizing:border-box;border:1.5px dashed #CBD5E1;border-radius:14px;}'
    + '.oc-gt{position:absolute;left:14px;top:9px;font-size:11.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#64748B;}'
    + '.oc-svg{overflow:visible;}.oc-svg path{fill:none;stroke:#94A3B8;stroke-width:1.5;}';
  function ocCss() {
    if (document.getElementById('kt-hroc-css')) return;
    var s = document.createElement('style'); s.id = 'kt-hroc-css';
    s.textContent = OC_CARD_CSS
      + '.oc-vp{position:relative;overflow-x:auto;overflow-y:hidden;border:1px solid #E2E8F0;border-radius:12px;background:#F8FAFC;cursor:grab;touch-action:pan-x pan-y;}'
      + '.oc-vp.drag{cursor:grabbing;user-select:none;}.oc-sz{position:relative;margin:0 auto;}.oc-stage{position:absolute;left:0;top:0;transform-origin:0 0;}'
      + '.oc-card{cursor:pointer;transition:box-shadow .12s;}.oc-card:hover{box-shadow:0 3px 12px rgba(15,23,42,.13);}.oc-card.ctx{cursor:default;}.oc-card.ctx:hover{box-shadow:none;}'
      + '.oc-tog{position:absolute;left:50%;top:calc(100% + 3px);transform:translateX(-50%);height:21px;min-width:38px;padding:0 8px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-size:11px;font-weight:800;color:#334155;cursor:pointer;z-index:2;line-height:19px;}'
      + '.oc-tog:hover{border-color:var(--oc-c,#1F6080);color:var(--oc-c,#1F6080);}'
      + '.oc-zoom{display:inline-flex;gap:4px;align-items:center;}.oc-zoom .hr-b{min-width:30px;padding:0 9px;}.oc-pct{font-size:12px;color:#64748B;min-width:38px;text-align:center;}'
      + 'html.kt-dark .oc-vp{background:#0B1220;border-color:#334155;}html.kt-dark .oc-card{background:#0F172A;border-color:#334155;color:#CBD5E1;}'
      + 'html.kt-dark .oc-nm b,html.kt-dark .oc-l b,html.kt-dark .oc-cen{color:#F1F5F9;}html.kt-dark .oc-card.ctx{background:#111827;}html.kt-dark .oc-ct{color:#94A3B8;}'
      + 'html.kt-dark .oc-tog{background:#0F172A;border-color:#475569;color:#E2E8F0;}html.kt-dark .oc-grp{border-color:#334155;}html.kt-dark .oc-svg path{stroke:#475569;}';
    document.head.appendChild(s);
  }
  function ocCentres(d) {
    var seen = {}, list = [];
    (d.people || []).forEach(function (p) { (p.centre_ids || []).forEach(function (id, i) { if (!seen[id]) { seen[id] = 1; list.push({ id: id, name: (p.centres || [])[i] || '' }); } }); });
    return list.sort(function (a, b) { return String(a.name).localeCompare(String(b.name), undefined, { numeric: true }); });
  }

  /* ── print: pages, split by branch when one page would be too small ── */
  function ocPages(m, o) {
    var queue = m.trees.map(function (t) { return { tree: t }; });
    for (var gi = 0; gi < m.loose.length; gi += 24) queue.push({ group: m.loose.slice(gi, gi + 24) });
    function scale(items) { var L = ocForest(items, o); return Math.min(1, OC.AW / L.w, OC.AH / L.h); }
    function depth(n) { return n.kids.length ? 1 + Math.max.apply(null, n.kids.map(depth)) : 0; }
    function cut(n, D, conts) {
      var c = { p: n.p, ctx: n.ctx, fromItem: n.fromItem, kids: [] };
      if (D <= 0 && n.kids.length) { var ci = { tree: n }; c.cont = ci; conts.push(ci); return c; }
      c.kids = n.kids.map(function (k) { return cut(k, D - 1, conts); });
      return c;
    }
    var pages = [], cur = null;
    for (var i = 0; i < queue.length; i++) {
      var it = queue[i];
      if (cur) { if (scale(cur.concat([it])) >= OC.MIN_S) { cur.push(it); continue; } pages.push(cur); cur = null; }
      if (it.tree && scale([it]) < OC.MIN_S && depth(it.tree) >= 2) {
        // keep the top of the branch here; every team below the cut gets its own place in the queue (the next pages)
        var orig = it.tree, D = depth(orig) - 1, conts, t;
        do { conts = []; t = cut(orig, D, conts); D--; } while (D >= 1 && scale([{ tree: t }]) < OC.MIN_S);
        it.tree = t;
        conts.forEach(function (ci) { ci.tree.fromItem = it; });
        queue.splice.apply(queue, [i + 1, 0].concat(conts));
      }
      cur = [it];
    }
    if (cur) pages.push(cur);
    pages.forEach(function (pg, pi) { pg.forEach(function (it) { it.page = pi + 1; }); });
    return pages;
  }
  function ocPrintHtml(d, centreId, centreName) {
    var doc = d.doc || {}, color = /^#[0-9A-Fa-f]{6}$/.test(doc.color || '') ? doc.color : '#1F6080';
    var o = { print: true, maxW: 1400, collapsed: {}, color: color };
    var m = ocModel(d, centreId), pages = ocPages(m, o), N = pages.length || 1;
    var asOf = fmtDate(d.today || today(), { year: 'numeric', month: 'long', day: 'numeric' });
    var contact = [doc.address, doc.phone, doc.email].filter(Boolean).map(esc).join(' · ');
    var sub = esc(centreName || T('All centres')) + ' · ' + esc(T('As of')) + ' ' + esc(asOf);
    var head = '<div class="hd">' + (doc.logo ? '<img src="' + esc(doc.logo) + '" alt="">' : '')
      + '<div class="ag"><b>' + esc(doc.agency_name || '') + '</b>' + (contact ? '<span>' + contact + '</span>' : '') + '</div>'
      + '<div class="tt"><b>' + esc(T('Organisation chart')) + '</b><span>' + sub + '</span></div></div><div class="rule"></div>';
    var note = esc(m.people + ' ' + T(m.people === 1 ? 'person' : 'people')) + ' · ' + esc(T('Dashed boxes: a manager outside this centre or outside your centres.')) + ' ' + esc(T('Email and phone show only where the person chose to share them.'));
    var body = (pages.length ? pages : [[]]).map(function (pg, i) {
      var L = ocForest(pg, o), s = Math.min(1, OC.AW / Math.max(1, L.w), OC.AH / Math.max(1, L.h));
      var cont = pg.filter(function (it) { return it.tree && it.tree.fromItem; }).map(function (it) { return it.tree.p.name; });
      return '<section class="pg">' + head
        + (cont.length ? '<div class="cn">' + esc(T('Continued')) + ': ' + esc(cont.join(', ')) + '</div>' : '')
        + '<div class="dg">' + (pg.length ? '<div style="position:relative;width:' + Math.ceil(L.w) + 'px;height:' + Math.ceil(L.h) + 'px;zoom:' + s.toFixed(4) + ';">' + ocStage(L, o, m.by, d.today || today()) + '</div>' : '<div style="color:#64748B;padding:40px;">' + esc(T('No one to show yet.')) + '</div>') + '</div>'
        + '<div class="ft"><span>' + (i === 0 ? note : esc(T('Organisation chart')) + ' · ' + esc(doc.agency_name || '')) + '</span>' + (doc.white_label ? '' : '<span>' + esc(T('Prepared with')) + ' KiddieTrac</span>') + '<span>' + esc(T('Page')) + ' ' + (i + 1) + ' ' + esc(T('of')) + ' ' + N + '</span></div></section>';
    }).join('');
    var css = '@page{size:letter landscape;margin:9mm;}*{box-sizing:border-box;}html,body{margin:0;background:#fff;}'
      + 'body{font-family:Helvetica,Arial,"Segoe UI",sans-serif;color:#0F172A;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '.pg{height:7.68in;display:flex;flex-direction:column;overflow:hidden;page-break-after:always;break-after:page;}.pg:last-child{page-break-after:auto;break-after:auto;}'
      + '.hd{display:flex;align-items:center;gap:14px;padding-bottom:6px;}.hd img{max-height:46px;max-width:170px;object-fit:contain;}'
      + '.ag{flex:1;min-width:0;}.ag b{font-size:13pt;display:block;}.ag span{font-size:8.5pt;color:#334155;display:block;margin-top:2px;}'
      + '.tt{text-align:right;}.tt b{font-size:17pt;display:block;line-height:1.05;color:' + color + ';}.tt span{font-size:9pt;font-weight:bold;display:block;margin-top:3px;}'
      + '.rule{border-top:2.5px solid ' + color + ';margin:0 0 8px;}.cn{font-size:9pt;font-weight:bold;color:' + color + ';margin:0 0 6px;}'
      + '.dg{flex:1;display:flex;align-items:flex-start;justify-content:center;min-height:0;}'
      + '.ft{display:flex;justify-content:space-between;gap:16px;font-size:7.5pt;color:#64748B;border-top:1px solid #E2E8F0;padding-top:4px;margin-top:6px;}'
      + ':root{--oc-c:' + color + ';}' + OC_CARD_CSS + '.oc-svg path{stroke:#64748B;}';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(T('Organisation chart') + ' · ' + (doc.agency_name || '') + ' · ' + (d.today || today())) + '</title><style>' + css + '</style></head><body>' + body + '</body></html>';
  }

  /* ── the screen ── */
  function orgChartView(host, d) {
    ocCss();
    var color = (d.doc && /^#[0-9A-Fa-f]{6}$/.test(d.doc.color || '')) ? d.doc.color : '#1F6080';
    var st = { centre: 0, collapsed: {}, scale: 0, user: false };
    var centres = ocCentres(d);
    try { if (d.doc && d.doc.logo) { var im = new Image(); im.src = d.doc.logo; } } catch (e) {}
    host.innerHTML = '<div class="hr-note">' + esc(T('Drawn from the manager on each person\'s HR file (not from teams). A manager at another centre shows by name only.')) + ' ' + esc(T('Press a person to see their details.')) + '</div>'
      + '<div class="hr-bar oc-tools">'
      + (centres.length > 1 ? '<select class="hr-i" data-oc-centre aria-label="' + esc(T('Centre')) + '" style="max-width:260px;"><option value="0">' + esc(T('All centres')) + '</option>' + centres.map(function (c) { return '<option value="' + c.id + '" data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '<span class="oc-zoom"><button type="button" class="hr-b" data-oc-z="-1" data-kt-iconized="1" aria-label="' + esc(T('Zoom out')) + '">−</button><button type="button" class="hr-b" data-oc-fit data-kt-iconized="1">' + esc(T('Fit')) + '</button><button type="button" class="hr-b" data-oc-z="1" data-kt-iconized="1" aria-label="' + esc(T('Zoom in')) + '">+</button><span class="oc-pct" data-oc-pct></span></span>'
      + '<button type="button" class="hr-b" data-oc-all="0" data-kt-iconized="1">' + esc(T('Expand all')) + '</button><button type="button" class="hr-b" data-oc-all="1" data-kt-iconized="1">' + esc(T('Collapse all')) + '</button>'
      + '<button type="button" class="hr-b p" data-oc-print data-kt-iconized="1">🖨 ' + esc(T('Print / Save as PDF')) + '</button>'
      + '<span class="hr-sub" data-oc-count></span></div>'
      + '<div class="oc-vp" data-oc-vp data-kt-nogrid data-no-i18n style="--oc-c:' + color + ';"><div class="oc-sz" data-oc-sz><div class="oc-stage" data-oc-stage></div></div></div>'
      + '<div data-oc-empty></div>';
    var vp = host.querySelector('[data-oc-vp]'), sz = host.querySelector('[data-oc-sz]'), stage = host.querySelector('[data-oc-stage]');
    var L = null, m = null;
    function fit() { var w = Math.max(200, vp.clientWidth - 2); return Math.max(0.2, Math.min(1, w / L.w)); }
    function apply() {
      if (!L) return;
      if (!st.user) st.scale = Math.max(fit(), window.innerWidth < 700 ? 0.5 : 0.35);
      st.scale = Math.max(0.2, Math.min(1.6, st.scale));
      stage.style.transform = 'scale(' + st.scale + ')';
      sz.style.width = Math.ceil(L.w * st.scale) + 'px'; sz.style.height = Math.ceil(L.h * st.scale) + 'px';
      var pct = host.querySelector('[data-oc-pct]'); if (pct) pct.textContent = Math.round(st.scale * 100) + '%';
      // wider than the screen (phones): start with the top of the chart in view, not its left edge
      if (!st.user && vp.scrollWidth > vp.clientWidth && L.cards[0]) vp.scrollLeft = Math.max(0, (L.cards[0].x + OC.W / 2) * st.scale - vp.clientWidth / 2);
    }
    function draw() {
      m = ocModel(d, st.centre);
      var items = m.trees.map(function (t) { return { tree: t }; }).concat(m.loose.length ? [{ group: m.loose }] : []);
      var o = { collapsed: st.collapsed, maxW: OC.MAXW };
      L = ocForest(items, o);
      stage.style.width = Math.ceil(L.w) + 'px'; stage.style.height = Math.ceil(L.h) + 'px';
      stage.innerHTML = items.length ? ocStage(L, o, m.by, d.today || today()) : '';
      vp.style.display = items.length ? '' : 'none';
      host.querySelector('[data-oc-empty]').innerHTML = items.length ? '' : '<div class="kt-card" style="padding:14px;"><span class="hr-sub">' + esc(T('No one to show yet.')) + '</span></div>';
      host.querySelector('[data-oc-count]').textContent = m.people + ' ' + T(m.people === 1 ? 'person' : 'people') + (m.trees.length ? '' : ' · ' + T('No managers are set yet. Set each person\'s manager on their HR file (People › ⋮ › Open HR file).'));
      apply();
    }
    var cs = host.querySelector('[data-oc-centre]');
    if (cs) cs.addEventListener('change', function () { st.centre = +cs.value || 0; st.user = false; draw(); });
    host.querySelectorAll('[data-oc-z]').forEach(function (b) { b.addEventListener('click', function () { st.user = true; st.scale = Math.round((st.scale + (+b.getAttribute('data-oc-z')) * 0.1) * 10) / 10; apply(); }); });
    host.querySelector('[data-oc-fit]').addEventListener('click', function () { st.user = true; st.scale = fit(); apply(); });
    host.querySelectorAll('[data-oc-all]').forEach(function (b) {
      b.addEventListener('click', function () {
        st.collapsed = {};
        if (b.getAttribute('data-oc-all') === '1') { var walk = function (n, dp) { if (n.kids.length && dp >= 1) st.collapsed[n.p.id] = 1; n.kids.forEach(function (k) { walk(k, dp + 1); }); }; m.trees.forEach(function (t) { walk(t, 0); }); }
        st.user = false; draw();
      });
    });
    host.querySelector('[data-oc-print]').addEventListener('click', function () {
      var name = ''; if (cs && +cs.value) { var op = cs.options[cs.selectedIndex]; name = op ? op.textContent : ''; }
      var html = ocPrintHtml(d, st.centre, name);
      if (KT.printDoc) KT.printDoc({ html: html }, { title: T('Organisation chart') });
      else { var w = window.open('', '_blank'); if (w) { w.document.write(html); w.document.close(); w.print(); } }
    });
    stage.addEventListener('click', function (ev) {
      var tg = ev.target.closest && ev.target.closest('[data-oc-tog]');
      if (tg) { ev.stopPropagation(); var id = +tg.getAttribute('data-oc-tog'); if (st.collapsed[id]) delete st.collapsed[id]; else st.collapsed[id] = 1; draw(); return; }
      if (vp.__ocMoved) return;
      var cd = ev.target.closest && ev.target.closest('.oc-card'); if (!cd || cd.classList.contains('ctx')) return;
      var p = (d.people || []).filter(function (x) { return x.id === +cd.getAttribute('data-oc-id'); })[0];
      if (p) viewPerson(d, p);
    });
    // drag the background sideways to pan (mouse / pen; touch scrolls natively)
    var drag = null;
    vp.addEventListener('pointerdown', function (ev) { if (ev.pointerType === 'touch' || ev.button !== 0 || (ev.target.closest && ev.target.closest('button'))) return; drag = { x: ev.clientX, l: vp.scrollLeft, moved: false }; vp.__ocMoved = false; });
    window.addEventListener('pointermove', function (ev) { if (!drag) return; var dx = ev.clientX - drag.x; if (Math.abs(dx) > 4) { drag.moved = true; vp.classList.add('drag'); } if (drag.moved) vp.scrollLeft = drag.l - dx; });
    window.addEventListener('pointerup', function () { if (!drag) return; vp.__ocMoved = drag.moved; vp.classList.remove('drag'); drag = null; setTimeout(function () { vp.__ocMoved = false; }, 0); });
    draw();
    // the tab's width is only final once the screen has settled
    setTimeout(function () { if (host.isConnected && !st.user) apply(); }, 120);
  }
  async function drawOrgChart(host) {
    var d; try { d = await Api.get('/hr/directory'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    orgChartView(host, d);
  }

  // ══ RECOGNITION ════════════════════════════════════════════════════════
  var RK = null;
  function kudosCard(k) {
    return '<div class="hp-k" data-k="' + k.id + '"><div class="hp-kh">' + avatar(k.from, 26) + '<b data-no-i18n>' + esc(k.from.name) + '</b><span aria-hidden="true">→</span>' + avatar(k.to, 26) + '<b data-no-i18n>' + esc(k.to.name) + '</b>'
      + '<span class="hr-sub" style="margin-left:auto;">' + esc(when(k.at)) + '</span></div>'
      + '<div class="hp-km" data-no-i18n>' + esc(k.message) + '</div>'
      + (k.tags && k.tags.length ? '<div>' + k.tags.map(function (t) { return '<span class="hp-tag" data-no-i18n>' + esc(t) + '</span>'; }).join('') + '</div>' : '')
      + (k.can_remove ? kebabBtn('k' + k.id) : '') + '</div>';
  }
  function feedHtml(r) {
    var s = r.settings || {};
    return '<div class="hr-bar">' + (s.enabled ? '<button type="button" class="hr-b p" data-give data-kt-iconized="1">🙌 Give kudos</button>' : '<span class="hr-sub">Recognition is switched off for your agency.</span>')
      + (s.enabled && s.allowance ? '<span class="hr-sub">' + s.left + ' of ' + s.allowance + ' left to give this month</span>' : '')
      + (r.points && r.points.per ? '<span class="hr-sub">Each kudos adds ' + r.points.per + ' points to Educator awards.</span>' : '')
      + (r.can_manage ? '<button type="button" class="hr-b" data-rset data-kt-iconized="1">Recognition settings</button>' : '') + '</div>'
      + '<div class="hp-feed" data-feed>' + ((r.kudos || []).length ? r.kudos.map(kudosCard).join('') : '<div class="kt-card" style="padding:14px;"><span class="hr-sub">No kudos yet. Thank a colleague for something they did well.</span></div>') + '</div>'
      + (r.more ? '<div class="hr-bar" style="margin-top:8px;"><button type="button" class="hr-b" data-more data-kt-iconized="1">Show older</button></div>' : '');
  }
  function wireFeed(host, r, reload) {
    var g = host.querySelector('[data-give]'); if (g) g.addEventListener('click', function () { giveKudos(r, null, reload); });
    var rs = host.querySelector('[data-rset]'); if (rs) rs.addEventListener('click', function () { recognitionSettings(reload); });
    var list = r.kudos || [];
    var wireKeb = function (root) {
      root.querySelectorAll('[data-keb]').forEach(function (b) {
        if (b.__hp) return; b.__hp = 1;
        b.addEventListener('click', function () {
          var id = +b.getAttribute('data-keb').slice(1);
          menu(b, [['Take it down', async function () {
            var ok = await KT.confirm({ title: 'Take this kudos down?', description: 'It leaves the feed and no longer counts toward points.', okLabel: 'Take down' });
            if (!ok) return;
            try { await Api.post('/hr/kudos/' + id + '/remove', {}); saved('Taken down.'); reload(); } catch (e) { fail('Could not take it down', e); }
          }]]);
        });
      });
    };
    wireKeb(host);
    var more = host.querySelector('[data-more]');
    if (more) more.addEventListener('click', async function () {
      var last = list[list.length - 1]; if (!last) return;
      try {
        var x = await Api.get('/hr/kudos?before=' + last.id);
        list = list.concat(x.kudos || []);
        var f = host.querySelector('[data-feed]'); f.insertAdjacentHTML('beforeend', (x.kudos || []).map(kudosCard).join('')); wireKeb(f);
        if (!x.more) more.remove();
      } catch (e) { fail('Could not load more', e); }
    });
  }
  async function drawKudos(host) {
    var r; try { r = RK = await Api.get('/hr/kudos'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    host.innerHTML = '<div class="hr-note">Thank a colleague with a few words and the values it shows. Everyone at your centres sees the team feed. There is no leaderboard.</div>' + feedHtml(r);
    wireFeed(host, r, function () { drawKudos(host); });
  }
  async function giveKudos(r, toId, reload) {
    if (!r) { try { r = await Api.get('/hr/kudos'); } catch (e) { return fail('Could not open it', e); } }
    var s = r.settings || {};
    if (!s.enabled) return toast('ℹ️', 'Recognition is off', 'Your agency has switched recognition off.');
    if (s.allowance && !s.left) return toast('ℹ️', 'None left this month', 'You have given all ' + s.allowance + ' kudos for this month.');
    var box = el('<div class="hr-g">' + field('Who', '<select class="hr-i" data-f="to" style="width:100%;">' + options(r.people, toId, 'Choose a colleague…') + '</select>', true)
      + field('Values it shows (up to 4)', '<div class="hp-tags" data-kt-noswitch>' + (s.tags || []).map(function (t, i) { return '<label><input type="checkbox" data-tag="' + i + '"> <span data-no-i18n>' + esc(t) + '</span></label>'; }).join('') + '</div>', true)
      + field('Your message', area('message', '', 3), true) + '</div>' + ERR);
    modal({ title: '🙌 Give kudos', body: box, actions: [{ label: 'Cancel' }, { label: 'Send kudos', primary: true, busyLabel: 'Sending…', handler: function () {
      var tags = []; box.querySelectorAll('[data-tag]:checked').forEach(function (x) { tags.push(s.tags[+x.getAttribute('data-tag')]); });
      if (!val(box, 'to')) { msg(box, 'Choose who the kudos is for.'); return false; }
      if (tags.length > 4) { msg(box, 'Choose up to 4 values.'); return false; }
      return Api.post('/hr/kudos', { to_user_id: +val(box, 'to'), tags: tags, message: val(box, 'message') || '' })
        .then(function () { toast('🙌', 'Kudos sent', 'They will see it on the team feed and in their bell.', '#16A34A'); if (reload) reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  async function recognitionSettings(reload) {
    var r; try { r = await Api.get('/hr/recognition/settings'); } catch (e) { return fail('Could not load the settings', e); }
    var box = el('<label style="display:flex;gap:7px;align-items:center;font-size:13px;margin-bottom:10px;"><input type="checkbox" data-f="enabled"' + (r.enabled ? ' checked' : '') + '> Staff can give each other kudos</label>'
      + '<div class="hr-g">' + field('Values (one per line)', area('tags', (r.tags || []).join('\n'), 6), true)
      + field('Kudos each person can give a month (0 = no limit)', input('allowance', r.allowance || 0, 'number', ' min="0" max="100"')) + '</div>'
      + '<div class="hr-sub" style="margin-top:8px;">Points: each kudos adds ' + (r.points || 0) + ' points to the person thanked (up to ' + (r.cap || 0) + ' a day). Change that in Educator awards › Award settings. No leaderboard and no prizes are shown on the feed.</div>' + ERR);
    modal({ title: 'Recognition settings', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var tags = String(box.querySelector('[data-f="tags"]').value || '').split(/\n+/).map(function (t) { return t.trim(); }).filter(Boolean);
      return Api.put('/hr/recognition/settings', { enabled: !!val(box, 'enabled'), tags: tags, allowance: +(val(box, 'allowance') || 0) })
        .then(function () { saved('Recognition settings saved.'); if (reload) reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ APPROVERS (HR settings, admins) ════════════════════════════════════
  var STATE = { active: ['#DCFCE7', '#166534', 'In force'], upcoming: ['#E0F2FE', '#075985', 'Coming up'], ended: ['#F1F5F9', '#475569', 'Ended'] };
  function grantRow(g, endBtn) {
    var st = STATE[g.state] || STATE.active;
    var what = g.kind === 'cover' ? 'Covering for <b data-no-i18n>' + esc(g.covering_for ? g.covering_for.name : '') + '</b><div class="hr-sub">' + esc(day(g.starts_on)) + ' to ' + esc(day(g.ends_on)) + ', ends by itself</div>'
      : 'Time off for <span data-no-i18n>' + esc(g.people.map(function (p) { return p.name; }).join(', ')) + '</span>' + (g.types ? '<div class="hr-sub">Only: <span data-no-i18n>' + esc(g.types.join(', ')) + '</span></div>' : '');
    return '<tr data-id="' + g.id + '"><td>' + (g.kind === 'cover' ? 'Cover while away' : 'Named people') + '</td><td><b data-no-i18n>' + esc(g.approver.name) + '</b></td><td>' + what + (g.note ? '<div class="hr-sub" data-no-i18n>' + esc(g.note) + '</div>' : '') + '</td>'
      + '<td>' + pill(st[0], st[1], st[2]) + '</td><td style="text-align:center;white-space:nowrap;">' + (endBtn && g.state !== 'ended' ? '<button type="button" data-gend="' + g.id + '">End now</button>' : '') + '</td></tr>';
  }
  function grantTable(rows, endBtn, key) {
    return '<div class="kt-card">' + (rows.length ? '<table data-hrp="' + key + '" style="width:100%;"><thead><tr><th>Kind</th><th>Approver</th><th>What they approve</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (g) { return grantRow(g, endBtn); }).join('') + '</tbody></table>'
      : '<div style="padding:14px;" class="hr-sub">None yet.</div>') + '</div>';
  }
  function wireEnd(host, reload) {
    host.querySelectorAll('[data-gend]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var ok = await KT.confirm({ title: 'End this now?', description: 'They stop getting these approvals straight away.', okLabel: 'End now' });
        if (!ok) return;
        try { await Api.post('/hr/approvers/' + b.getAttribute('data-gend') + '/end', {}); saved('Ended.'); reload(); } catch (e) { fail('Could not end it', e); }
      });
    });
  }
  async function drawApprovers(host) {
    var d; try { d = await Api.get('/hr/approvers'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { drawApprovers(host); };
    host.innerHTML = '<div class="hr-note">Time off is approved by an agency admin or a director of the person\'s centre. Here you can also let a director or admin approve <b>named people</b> (even at another centre), and set <b>cover</b> while a director is away: the cover gets that director\'s time-off approvals and emails until the last day, then it stops by itself. Nobody ever approves their own time off. Every change is in the audit log.</div>'
      + '<div class="hr-bar"><button type="button" class="hr-b p" data-addp data-kt-iconized="1">Add an approver for named people</button><button type="button" class="hr-b" data-addc data-kt-iconized="1">Set cover for a director</button></div>'
      + grantTable(d.grants || [], true, 'approvers');
    host.querySelector('[data-addp]').addEventListener('click', function () { addPeopleGrant(d, reload); });
    host.querySelector('[data-addc]').addEventListener('click', function () { coverForm(d, null, reload); });
    wireEnd(host, reload);
  }
  function addPeopleGrant(d, reload) {
    var box = el('<div class="hr-g">' + field('Who approves', '<select class="hr-i" data-f="approver" style="width:100%;">' + options(d.managers, null, 'Choose a director or admin…') + '</select>', true) + '</div>'
      + '<div class="hp-sec">Whose time off</div>' + picker(d.people, [], 'pp')
      + '<div class="hp-sec">Leave types</div><div class="hp-tags" data-kt-noswitch>' + (d.types || []).map(function (t) { return '<label><input type="checkbox" data-lt="' + t.id + '"> <span data-no-i18n>' + esc(t.name) + '</span></label>'; }).join('') + '</div>'
      + '<div class="hr-sub" style="margin-top:4px;">Tick none for every type. Requests made without a leave type count as every type only when none are ticked.</div>'
      + '<div class="hr-g" style="margin-top:8px;">' + field('Note (optional)', input('note', '', 'text', ' maxlength="300"'), true) + '</div>' + ERR);
    wirePicker(box);
    modal({ title: 'Approver for named people', body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var people = [], types = [];
      box.querySelectorAll('[data-pp]:checked').forEach(function (x) { people.push(+x.getAttribute('data-pp')); });
      box.querySelectorAll('[data-lt]:checked').forEach(function (x) { types.push(+x.getAttribute('data-lt')); });
      if (!val(box, 'approver')) { msg(box, 'Choose who approves.'); return false; }
      return Api.post('/hr/approvers', { kind: 'people', approver_user_id: +val(box, 'approver'), subject_user_ids: people, leave_type_ids: types.length ? types : null, note: val(box, 'note') })
        .then(function () { saved('They now approve these people\'s time off.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  /* Cover form: admins choose the director who is away (d.managers with director:true); a director sets their own (absentId = me). */
  function coverForm(d, absentId, reload) {
    var dirs = (d.managers || []).filter(function (m) { return m.director; });
    var t = d.today || today();
    var box = el('<div class="hr-g">' + (absentId ? '' : field('Director who is away', '<select class="hr-i" data-f="absent" style="width:100%;">' + options(dirs, null, 'Choose…') + '</select>', true))
      + field('Who covers', '<select class="hr-i" data-f="approver" style="width:100%;">' + options(d.managers, null, 'Choose a director or admin…') + '</select>', true)
      + field('First day', input('from', t, 'date')) + field('Last day', input('to', addDays(t, 6), 'date'))
      + field('Note (optional)', input('note', '', 'text', ' maxlength="300"'), true) + '</div>'
      + '<div class="hr-sub" style="margin-top:6px;">They get the time-off approvals and emails for the centres ' + (absentId ? 'you direct' : 'that director runs') + ', and nothing more. It ends by itself after the last day (up to ' + (d.max_cover_days || 92) + ' days). Nobody approves their own request.</div>' + ERR);
    modal({ title: absentId ? 'Cover while I\'m away' : 'Set cover for a director', body: box, actions: [{ label: 'Cancel' }, { label: 'Save cover', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { approver_user_id: +(val(box, 'approver') || 0), starts_on: val(box, 'from'), ends_on: val(box, 'to'), note: val(box, 'note') };
      if (!o.approver_user_id) { msg(box, 'Choose who covers.'); return false; }
      var p = absentId ? Api.post('/hr/me/cover', o) : Api.post('/hr/approvers', Object.assign({ kind: 'cover', covering_for_user_id: +(val(box, 'absent') || 0) }, o));
      return p.then(function () { saved('Cover saved. It ends by itself after the last day.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ MY HR SECTIONS ═════════════════════════════════════════════════════
  async function mine(b) {
    css();
    var holder = document.createElement('div'); holder.setAttribute('data-hp-mine', '1');
    b.appendChild(holder);
    var dir = null, kd = null, cover = null;
    try { dir = await Api.get('/hr/directory'); } catch (e) {}
    try { kd = await Api.get('/hr/kudos'); } catch (e) {}
    var u = (KT.Auth && KT.Auth.user && KT.Auth.user()) || {};
    var role = u.role_key || u.role || '';
    if (role === 'centre_director' || role === 'agency_admin' || role === 'platform_admin') { try { cover = await Api.get('/hr/me/cover'); } catch (e) {} }
    if (!holder.isConnected) return;
    var h = '';
    if (cover && (cover.can_set || (cover.grants || []).length)) {
      var mineAway = (cover.grants || []).filter(function (g) { return g.covering_for && g.covering_for.id === cover.me; });
      var held = (cover.grants || []).filter(function (g) { return g.approver.id === cover.me && g.kind === 'cover'; });
      h += '<div class="hrt-sec hp-sec">Cover while I\'m away</div><div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>'
        + '<div class="hr-sub" style="margin-bottom:8px;">Away for a while? Name another director or admin: they get your time-off approvals and emails until your last day, then it stops by itself.</div>'
        + mineAway.concat(held).map(function (g) {
          var st = STATE[g.state] || STATE.active;
          return '<div class="hr-kv"><span>' + (g.covering_for && g.covering_for.id === cover.me ? 'Covering for you: <b data-no-i18n>' + esc(g.approver.name) + '</b>' : 'You cover <b data-no-i18n>' + esc(g.covering_for ? g.covering_for.name : '') + '</b>')
            + ' · ' + esc(day(g.starts_on)) + ' to ' + esc(day(g.ends_on)) + '</span><b>' + pill(st[0], st[1], st[2]) + (g.state !== 'ended' ? ' <button type="button" class="hr-b" data-gend="' + g.id + '" data-kt-iconized="1">End now</button>' : '') + '</b></div>';
        }).join('')
        + (cover.can_set ? '<div class="hr-bar" style="margin:10px 0 0;"><button type="button" class="hr-b p" data-cover data-kt-iconized="1">Set cover</button></div>' : '') + '</div>';
    }
    if (dir) {
      h += '<div class="hrt-sec hp-sec">Team directory</div>'
        + '<div class="kt-card" style="padding:10px 14px;margin-bottom:8px;" data-kt-nogrid><div class="hr-bar" style="margin:0;"><b style="font-size:13px;">What colleagues see:</b>'
        + '<label style="display:flex;gap:6px;align-items:center;font-size:13px;"><input type="checkbox" data-share="email"' + (dir.share.email ? ' checked' : '') + '> my email</label>'
        + '<label style="display:flex;gap:6px;align-items:center;font-size:13px;"><input type="checkbox" data-share="phone"' + (dir.share.phone ? ' checked' : '') + '> my phone</label>'
        + '<button type="button" class="hr-b" data-chart data-kt-iconized="1">Org chart</button></div></div>'
        + directoryTable(dir, false);
    }
    if (kd) h += '<div class="hrt-sec hp-sec">Kudos</div>' + feedHtml(kd);
    holder.innerHTML = h;
    var reload = function () { var p = holder.parentNode; holder.remove(); if (p) mine(p); };
    if (dir) {
      wireDirectory(holder, dir, false);
      holder.querySelectorAll('[data-share]').forEach(function (x) {
        x.addEventListener('change', function () {
          var o = { share_email: !!holder.querySelector('[data-share="email"]').checked, share_phone: !!holder.querySelector('[data-share="phone"]').checked };
          Api.put('/hr/me/directory', o).then(function () { saved('Your directory details are updated.'); }, function (e) { x.checked = !x.checked; fail('Could not save', e); });
        });
      });
      var ch = holder.querySelector('[data-chart]');
      if (ch) ch.addEventListener('click', function () { var bx = el(''); bx.setAttribute('data-kt-nogrid', ''); modal({ title: 'Org chart', body: bx, large: true, actions: [{ label: 'Close' }] }); orgChartView(bx, dir); });
    }
    if (kd) wireFeed(holder, kd, reload);
    var cb = holder.querySelector('[data-cover]'); if (cb) cb.addEventListener('click', function () { coverForm(cover, cover.me, reload); });
    wireEnd(holder, reload);
  }

  // ══ QUIZZES ════════════════════════════════════════════════════════════
  async function quizEditor(c, done) {
    css();
    var q; try { q = await Api.get('/hr/courses/' + c.id + '/quiz'); } catch (e) { return fail('Could not load the quiz', e); }
    var qs = (q.questions || []).map(function (x) { return { prompt: x.prompt, kind: x.kind, options: x.options.slice(), correct: x.correct.slice() }; });
    var box = el('<div class="hr-g">' + field('Pass mark (%)', input('pass', q.pass_mark || 80, 'number', ' min="1" max="100"'))
      + field('Tries allowed', '<select class="hr-i" data-f="tries" style="width:100%;"><option value="">No limit</option>' + [1, 2, 3, 4, 5].map(function (n) { return '<option value="' + n + '"' + (q.max_attempts === n ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select>') + '</div>'
      + '<div class="hr-sub" style="margin:6px 0 4px;">With a quiz, people finish the course by passing it; the certificate is issued only then. They see how many they got right, not which. A manager can allow another try.</div>'
      + '<div data-qs></div><div class="hr-bar"><button type="button" class="hr-b" data-addq data-kt-iconized="1">+ Add a question</button></div>' + ERR);
    var list = box.querySelector('[data-qs]');
    var read = function () {
      list.querySelectorAll('[data-qi]').forEach(function (qe) {
        var i = +qe.getAttribute('data-qi'), x = qs[i]; if (!x) return;
        x.prompt = qe.querySelector('[data-qp]').value;
        x.kind = qe.querySelector('[data-qk]').value;
        x.options = []; x.correct = [];
        qe.querySelectorAll('[data-oi]').forEach(function (oe, j) { x.options.push(oe.querySelector('[data-ot]').value); if (oe.querySelector('[data-oc]').checked) x.correct.push(j); });
      });
    };
    var draw = function () {
      list.innerHTML = qs.map(function (x, i) {
        return '<div class="hp-q" data-kt-noswitch data-qi="' + i + '"><div class="hp-qh"><b style="font-size:13px;">Question ' + (i + 1) + '</b><span style="display:flex;gap:6px;align-items:center;"><select class="hr-i" data-qk><option value="single"' + (x.kind !== 'multi' ? ' selected' : '') + '>One right answer</option><option value="multi"' + (x.kind === 'multi' ? ' selected' : '') + '>Several right answers</option></select>'
          + kebabBtn('q' + i) + '</span></div><textarea class="hr-i" data-qp rows="2" style="width:100%;min-height:44px;" placeholder="The question">' + esc(x.prompt || '') + '</textarea>'
          + '<div class="hr-sub" style="margin:6px 0 2px;">Answers (tick the right ' + (x.kind === 'multi' ? 'ones' : 'one') + ')</div>'
          + x.options.map(function (o, j) { return '<div class="hp-orow" data-oi="' + j + '"><input type="' + (x.kind === 'multi' ? 'checkbox' : 'radio') + '" name="qc' + i + '" data-oc aria-label="Right answer"' + (x.correct.indexOf(j) >= 0 ? ' checked' : '') + '><input class="hr-i" data-ot value="' + esc(o) + '" placeholder="Answer ' + (j + 1) + '" style="width:100%;"><button type="button" class="hp-keb" data-odel="' + j + '" aria-label="Remove answer" data-kt-iconized="1">✕</button></div>'; }).join('')
          + (x.options.length < 8 ? '<button type="button" class="hr-link" data-oadd data-kt-iconized="1">+ Add an answer</button>' : '') + '</div>';
      }).join('') || '<div class="hr-sub" style="padding:6px 2px;">No questions yet: the course is finished by marking it complete.</div>';
      list.querySelectorAll('[data-qi]').forEach(function (qe) {
        var i = +qe.getAttribute('data-qi');
        qe.querySelector('[data-qk]').addEventListener('change', function () { read(); if (qs[i].kind === 'single') qs[i].correct = qs[i].correct.slice(0, 1); draw(); });
        var add = qe.querySelector('[data-oadd]'); if (add) add.addEventListener('click', function () { read(); qs[i].options.push(''); draw(); });
        qe.querySelectorAll('[data-odel]').forEach(function (b) { b.addEventListener('click', function () { read(); var j = +b.getAttribute('data-odel'); qs[i].options.splice(j, 1); qs[i].correct = qs[i].correct.filter(function (k) { return k !== j; }).map(function (k) { return k > j ? k - 1 : k; }); draw(); }); });
        qe.querySelector('[data-keb]').addEventListener('click', function (ev) {
          read();
          menu(ev.currentTarget, [i > 0 ? ['Move up', function () { var t = qs[i - 1]; qs[i - 1] = qs[i]; qs[i] = t; draw(); }] : null,
            i < qs.length - 1 ? ['Move down', function () { var t = qs[i + 1]; qs[i + 1] = qs[i]; qs[i] = t; draw(); }] : null,
            ['Remove question', function () { qs.splice(i, 1); draw(); }]]);
        });
      });
    };
    box.querySelector('[data-addq]').addEventListener('click', function () { read(); qs.push({ prompt: '', kind: 'single', options: ['', ''], correct: [] }); draw(); });
    draw();
    modal({ title: 'Quiz: ' + c.title, body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Save quiz', primary: true, busyLabel: 'Saving…', handler: function () {
      read();
      var o = { pass_mark: qs.length ? +(val(box, 'pass') || 0) : null, max_attempts: val(box, 'tries') ? +val(box, 'tries') : null,
        questions: qs.map(function (x) { return { prompt: x.prompt, kind: x.kind, options: x.options, correct: x.correct }; }) };
      return Api.put('/hr/courses/' + c.id + '/quiz', o).then(function (r) { saved(r.questions ? 'Quiz saved: ' + r.questions + ' question' + (r.questions === 1 ? '' : 's') + ', pass mark ' + r.pass_mark + '%.' : 'Quiz removed.'); if (done) done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function quizInfo(assignmentId) { return Api.get('/hr/course-assignments/' + assignmentId + '/quiz').then(function (r) { return r; }, function () { return null; }); }
  function quizSummary(q) {
    return '<div class="hr-sub">Pass mark ' + q.pass_mark + '% · ' + q.questions.length + ' question' + (q.questions.length === 1 ? '' : 's') + ' · '
      + (q.tries_allowed == null ? 'no limit on tries' : (q.tries_allowed - q.tries_used) + ' of ' + q.tries_allowed + ' tries left') + '</div>'
      + (q.last ? '<div class="hr-sub">Last try: ' + q.last.score + '%' + (q.last.passed ? ' (passed)' : '') + '</div>' : '');
  }
  function quizTake(a, q, reload) {
    css();
    var box = el('<div class="hr-note">' + quizSummary(q).replace(/class="hr-sub"/g, '') + '</div>' + q.questions.map(function (x, i) {
      return '<div class="hp-q" data-kt-noswitch data-qid="' + x.id + '"><b style="font-size:13.5px;" data-no-i18n>' + (i + 1) + '. ' + esc(x.prompt) + '</b>' + (x.kind === 'multi' ? '<div class="hr-sub">Choose every right answer.</div>' : '')
        + x.options.map(function (o, j) { return '<label class="hp-opt"><input type="' + (x.kind === 'multi' ? 'checkbox' : 'radio') + '" name="qa' + x.id + '" value="' + j + '"> <span data-no-i18n>' + esc(o) + '</span></label>'; }).join('') + '</div>';
    }).join('') + (a.needs_ack ? '<div class="hr-g" style="margin-top:8px;">' + field('Type your name to confirm you completed and understood it', input('ack', '', 'text', ' placeholder="Your full name"'), true) + '</div>' : '') + ERR);
    modal({ title: 'Quiz: ' + a.title, body: box, large: true, actions: [{ label: 'Close' }, { label: 'Submit answers', primary: true, busyLabel: 'Checking…', handler: function () {
      var ans = {}, missing = 0;
      q.questions.forEach(function (x) { var got = []; box.querySelectorAll('[data-qid="' + x.id + '"] input:checked').forEach(function (i) { got.push(+i.value); }); if (!got.length) missing++; ans[x.id] = got; });
      if (missing) { msg(box, missing === 1 ? 'One question has no answer yet.' : missing + ' questions have no answer yet.'); return false; }
      var ack = box.querySelector('[data-f="ack"]');
      return Api.post('/hr/course-assignments/' + a.id + '/quiz', { answers: ans, name: ack ? ack.value.trim() : '' }).then(function (r) {
        if (r.passed) { toast('🎓', 'Passed: ' + r.score + '%', 'Course complete. Certificate ' + (r.certificate_no || '') + '.', '#16A34A'); if (reload) reload(); return; }
        msg(box, 'You got ' + r.right + ' of ' + r.total + ' right (' + r.score + '%). The pass mark is ' + r.pass_mark + '%.' + (r.tries_left === 0 ? ' You have no tries left: ask your manager for another.' : ' Have another look and try again.'));
        if (reload && r.tries_left === 0) reload();
        return false;
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  window.KTHrPeople = {
    tabs: ['directory', 'orgchart', 'kudos', 'approvers'],
    has: function (t) { return this.tabs.indexOf(t) >= 0; },
    draw: function (tab, host) {
      css();
      if (tab === 'directory') return drawDirectory(host);
      if (tab === 'orgchart') return drawOrgChart(host);
      if (tab === 'kudos') return drawKudos(host);
      if (tab === 'approvers') return drawApprovers(host);
    },
    orgChart: orgChartView, orgChartPrintHtml: ocPrintHtml,
    mine: mine, quizEditor: quizEditor, quizInfo: quizInfo, quizTake: quizTake, quizSummary: quizSummary, giveKudos: giveKudos
  };
})(window);
