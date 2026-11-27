/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR Phase 4, batch 6 (2026-11-27): staff directory + org chart, recognition (kudos), time-off
   approvers + cover while away, and course quizzes. window.KTHrPeople, a lazy dependency of screen-hr.js and
   screen-hr-talent.js (like screen-hr-reports.js).

   Where it draws:
     Human Resources › People          tabs Directory, Org chart        (directory, orgchart)
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
  function chartHtml(d) {
    var people = d.people || [], out = d.outside || [];
    var by = {}; people.concat(out).forEach(function (p) { by[p.id] = p; });
    var kids = {}; people.forEach(function (p) { if (p.manager_id && by[p.manager_id]) (kids[p.manager_id] = kids[p.manager_id] || []).push(p); });
    var seen = {};
    function node(p, depth) {
      if (seen[p.id] || depth > 12) return '';
      seen[p.id] = 1;
      var ch = (kids[p.id] || []).map(function (c) { return node(c, depth + 1); }).join('');
      return '<li><div class="hp-node' + (p.outside ? ' out' : '') + '">' + (p.outside ? '' : avatar(p, 30)) + '<div><b data-no-i18n style="font-size:13.5px;">' + esc(p.name) + '</b><div class="hp-nt">' + roleLine(p)
        + (p.outside ? ' · another centre' : (kids[p.id] ? ' · ' + kids[p.id].length + ' report' + (kids[p.id].length === 1 ? '' : 's') : '')) + '</div></div></div>'
        + (ch ? '<ul class="hp-tree">' + ch + '</ul>' : '') + '</li>';
    }
    var roots = people.concat(out).filter(function (p) { return (!p.manager_id || !by[p.manager_id]) && kids[p.id]; });
    var tree = roots.map(function (r) { return node(r, 0); }).join('');
    var loose = people.filter(function (p) { return !seen[p.id]; });
    return (tree ? '<ul class="hp-tree">' + tree + '</ul>' : '<div class="hr-sub" style="padding:8px 2px;">No managers are set yet. Set each person\'s manager on their HR file (People › ⋮ › Open HR file).</div>')
      + (loose.length ? '<div class="hp-sec">No manager set</div><div class="hr-sub" style="margin-bottom:6px;">' + loose.map(function (p) { return '<span data-no-i18n>' + esc(p.name) + '</span>'; }).join(' · ') + '</div>' : '');
  }
  async function drawOrgChart(host) {
    var d; try { d = await Api.get('/hr/directory'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    host.innerHTML = '<div class="hr-note">Drawn from the manager on each person\'s HR file (not from teams). A manager at another centre shows by name only.</div><div class="kt-card" style="padding:10px 14px;" data-kt-nogrid>' + chartHtml(d) + '</div>';
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
      if (ch) ch.addEventListener('click', function () { modal({ title: 'Org chart', body: el('<div data-kt-nogrid>' + chartHtml(dir) + '</div>'), large: true, actions: [{ label: 'Close' }] }); });
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
    mine: mine, quizEditor: quizEditor, quizInfo: quizInfo, quizTake: quizTake, quizSummary: quizSummary, giveKudos: giveKudos
  };
})(window);
