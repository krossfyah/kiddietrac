/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR Phase 4, batch 7 (2026-11-27): Human Resources › Health & safety. window.KTHrHs, a lazy dependency
   of screen-hr.js and screen-hr-talent.js (like screen-hr-people.js).

   Tabs (kt-hr-sections.js, section "safety"):
     Risk assessments      risk        hazard / who / likelihood × severity / controls / residual; owner, review date,
                                       sign-off by a director or admin who did not make the last change; starter examples
     Chemicals (WHMIS)     chemicals   product, location, hazard pictograms, safety data sheet (private file), SDS date
                                       (flagged over 3 years), review date
     Safe work procedures  procedures  the Policy library filtered to "Safe work procedure" (KT.policies, screen-policies.js):
                                       staff read and e-sign each version, again when it changes
     H&S duties            duties      first aider, fire warden … with the certificate the duty needs, checked live
     H&S tasks             hstasks     tasks (the ordinary tasks table, category hs) closed only with evidence
   My HR (staff): My H&S duties, My H&S tasks (add evidence, close), Safety data sheets, Safe work procedures; the
   "Report a hazard" tile opens #hazards (photo from the phone camera there).
   The server scopes everything (active agency, directors their own centres). House patterns: tables with plain
   buttons in the last cell (kt-row-actions turns them into the ⋮), KT.Shell.Modal popups, KT.printDoc, KT.viewDocument.
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
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function addYears(ymd, n) { var p = String(ymd || today()).split('-'); var d = new Date(+p[0] + n, +p[1] - 1, +p[2]); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function input(k, v, type, extra) { return '<input class="hr-i" data-f="' + k + '" type="' + (type || 'text') + '" value="' + esc(v == null ? '' : v) + '" style="width:100%;"' + (extra || '') + '>'; }
  function area(k, v, rows) { return '<textarea class="hr-i" data-f="' + k + '" rows="' + (rows || 3) + '" style="width:100%;min-height:' + ((rows || 3) * 22) + 'px;">' + esc(v || '') + '</textarea>'; }
  function options(list, sel, blank) { return (blank != null ? '<option value="">' + esc(blank) + '</option>' : '') + (list || []).map(function (o) { return '<option value="' + o.id + '"' + (String(sel) === String(o.id) ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join(''); }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';
  function user() { try { return (KT.Auth && KT.Auth.user && KT.Auth.user()) || JSON.parse(sessionStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }

  var CSS_ID = 'kt-hrhs-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '.hs-sec{font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;margin:16px 0 8px;}'
      + '.hs-chips{display:flex;gap:4px;flex-wrap:wrap;}'
      + '.hs-chip{display:inline-block;padding:1px 8px;border-radius:999px;background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;font-size:11.5px;font-weight:700;white-space:nowrap;}'
      + '.hs-classes{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(230px,100%),1fr));gap:4px 12px;}'
      + '.hs-classes label{display:flex;gap:7px;align-items:center;font-size:13px;padding:2px 0;cursor:pointer;}'
      + '.hs-line{border:1px solid #E2E8F0;border-radius:12px;padding:10px 12px;margin:0 0 8px;background:#fff;}'
      + '.hs-line .hs-lh{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;}'
      + '.hs-lg{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:8px;}'
      + '.hs-ex{display:inline-block;padding:1px 8px;border-radius:999px;background:#FFFBEB;color:#92400E;border:1px solid #FDE68A;font-size:11.5px;font-weight:700;}'
      + '.hs-x{width:28px;height:28px;border-radius:8px;border:1px solid transparent;background:transparent;cursor:pointer;font-size:15px;color:#475569;}'
      + '.hs-x:hover{background:#F1F5F9;border-color:#E2E8F0;}'
      + '.hs-tabs{display:flex;gap:6px;flex-wrap:wrap;}'
      + '.hs-tabs button{height:30px;padding:0 12px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;color:#475569;}'
      + '.hs-tabs button.on{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + 'html.kt-dark .hs-line{background:#0F172A;border-color:#334155;}html.kt-dark .hs-sec{color:#5EEAD4;}'
      + 'html.kt-dark .hs-tabs button{background:#0F172A;border-color:#334155;color:#E2E8F0;}html.kt-dark .hs-tabs button.on{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + 'html.kt-dark .hs-chip{background:#3B0D0D;border-color:#7F1D1D;color:#FECACA;}html.kt-dark .hs-ex{background:#3A2A06;border-color:#78350F;color:#FDE68A;}';
    document.head.appendChild(s);
  }

  /* The house document viewer, fed a short-lived signed link from an endpoint that checked access. */
  async function openFile(path, label) {
    var r;
    try { r = await Api.get(path); } catch (e) { return fail('Could not open the file', e); }
    if (!r.previewable || !KT.viewDocument) {
      var a = document.createElement('a'); a.href = r.url; a.setAttribute('download', r.name || 'file'); a.setAttribute('data-kt-download', '1');
      document.body.appendChild(a); a.click(); a.remove(); return;
    }
    KT.viewDocument(r.url, { title: r.title || label, filename: r.name, mime: r.mime, label: label });
  }
  function centreSelect(centres, sel, blank) { return '<select class="hr-i" data-f="centre_id" style="width:100%;">' + options(centres, sel, blank) + '</select>'; }
  function filterBar(state, centres, extra) {
    return '<div class="hr-bar">' + ((centres || []).length > 1 ? '<select class="hr-i" data-hsf="centre" style="width:auto;"><option value="">All my centres</option>' + options(centres, state.centre) + '</select>' : '')
      + extra + '</div>';
  }

  // ══ RISK ASSESSMENTS ════════════════════════════════════════════════════
  var BAND = { low: ['#ECFDF5', '#047857', 'Low'], medium: ['#FFFBEB', '#B45309', 'Medium'], high: ['#FEF2F2', '#B91C1C', 'High'], very_high: ['#7F1D1D', '#FFFFFF', 'Very high'] };
  function bandPill(b, score) { var c = BAND[b]; return c ? pill(c[0], c[1], c[2] + (score ? ' · ' + score : '')) : '<span class="hr-sub">—</span>'; }
  function reviewPill(r) {
    if (!r.review_on) return '<span class="hr-sub">No date</span>';
    if (r.review_state === 'overdue') return pill('#FEF2F2', '#B91C1C', 'Overdue · ' + day(r.review_on));
    if (r.review_state === 'soon') return pill('#FFFBEB', '#B45309', 'Due ' + day(r.review_on));
    return '<span>' + esc(day(r.review_on)) + '</span>';
  }
  function statusPill(r) {
    if (r.status === 'archived') return pill('#F1F5F9', '#475569', 'Archived');
    if (r.status === 'signed') return pill('#ECFDF5', '#047857', 'Signed off') + '<div class="hr-sub" data-no-i18n>' + esc(r.signed_by_name || r.signed_name || '') + '</div>';
    return pill('#EFF6FF', '#1D4ED8', 'Draft');
  }
  var RS = { centre: '', archived: false };
  async function drawRisk(host) {
    var q = []; if (RS.centre) q.push('centre_id=' + RS.centre); if (RS.archived) q.push('archived=1');
    var d; try { d = await Api.get('/hr/safety/risk' + (q.length ? '?' + q.join('&') : '')); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { drawRisk(host); };
    var rows = d.rows || [];
    host.innerHTML = '<div class="hr-note">Think through what could go wrong in each activity or area, who it could affect, how likely and how serious it is (1 to 5 each), what you do to control it, and the risk that is left. A director of the centre or an admin signs it off — someone other than the person who made the last change. Any later change sends it back to draft. You get a reminder before each review date.</div>'
      + filterBar(RS, d.centres, '<label class="hr-sub" style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-hsf="arch"' + (RS.archived ? ' checked' : '') + '> Archived</label><span style="flex:1"></span>'
        + '<button type="button" class="hr-b p" data-new data-kt-iconized="1">+ New risk assessment</button>')
      + '<div class="kt-card">' + (rows.length ? '<table data-hshs="risk" style="width:100%;"><thead><tr><th>Risk assessment</th><th>Centre</th><th>Highest risk</th><th>Owner</th><th>Review</th><th>Status</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          var acts = '<button type="button" data-rv="' + r.id + '">View</button><button type="button" data-rp="' + r.id + '">Print</button>';
          if (r.status !== 'archived') {
            acts += '<button type="button" data-re="' + r.id + '">Edit</button>';
            if (r.status === 'draft') acts += '<button type="button" data-rs="' + r.id + '">Sign off</button>';
            acts += '<button type="button" data-rt="' + r.id + '">Create H&amp;S task</button><button type="button" data-ra="' + r.id + '">Archive</button>';
          } else acts += '<button type="button" data-rr="' + r.id + '">Restore</button>';
          return '<tr><td><b data-no-i18n>' + esc(r.title) + '</b>' + (r.is_example ? ' <span class="hs-ex">Example — review for your centre</span>' : '')
            + (r.area ? '<div class="hr-sub" data-no-i18n>' + esc(r.area) + '</div>' : '') + '<div class="hr-sub">' + r.item_count + ' hazard' + (r.item_count === 1 ? '' : 's') + '</div></td>'
            + '<td data-no-i18n>' + esc(r.centre_name) + '</td><td>' + bandPill(r.top_band, r.top_score) + '</td><td data-no-i18n>' + esc(r.owner_name || '—') + '</td>'
            + '<td>' + reviewPill(r) + '</td><td>' + statusPill(r) + '</td><td style="text-align:center;white-space:nowrap;">' + acts + '</td></tr>';
        }).join('') + '</tbody></table>'
        : '<div style="padding:16px;" class="hr-sub">' + (RS.archived ? 'No archived risk assessments.' : 'No risk assessments yet. Press <b>New risk assessment</b> and start from a blank page or one of the examples (outdoor play, field trips, sleep room, kitchen, water play).') + '</div>') + '</div>';
    var byId = {}; rows.forEach(function (r) { byId[r.id] = r; });
    var fc = host.querySelector('[data-hsf="centre"]'); if (fc) fc.addEventListener('change', function () { RS.centre = fc.value; reload(); });
    var fa = host.querySelector('[data-hsf="arch"]'); if (fa) fa.addEventListener('change', function () { RS.archived = fa.checked; reload(); });
    host.querySelector('[data-new]').addEventListener('click', function () { newRisk(d, reload); });
    var on = function (attr, fn) { host.querySelectorAll('[' + attr + ']').forEach(function (b) { b.addEventListener('click', function () { var r = byId[b.getAttribute(attr)]; if (r) fn(r); }); }); };
    on('data-rv', function (r) { viewRisk(r, d, reload); });
    on('data-rp', printRisk);
    on('data-re', function (r) { editRisk(r, d, reload); });
    on('data-rs', function (r) { signRisk(r, d, reload); });
    on('data-rt', function (r) { newTask({ centre_id: r.centre_id, source: 'risk', source_id: r.id, title: 'Risk assessment: ' + r.title }, reload); });
    on('data-ra', function (r) { Api.post('/hr/safety/risk/' + r.id + '/archive', { archived: true }).then(function () { saved('Archived. It stays on file.'); reload(); }, function (e) { fail('Could not archive', e); }); });
    on('data-rr', function (r) { Api.post('/hr/safety/risk/' + r.id + '/archive', { archived: false }).then(function () { saved('Restored.'); reload(); }, function (e) { fail('Could not restore', e); }); });
  }
  function newRisk(d, reload) {
    if (!(d.centres || []).length) { toast('ℹ️', 'No centres', 'You need an open centre to add a risk assessment.'); return; }
    var box = el('<div class="hr-g">' + field('Centre', centreSelect(d.centres, RS.centre || (d.centres[0] && d.centres[0].id)), true)
      + field('Start from', '<select class="hr-i" data-f="tpl" style="width:100%;"><option value="">A blank risk assessment</option>' + (d.templates || []).map(function (t) { return '<option value="' + esc(t.key) + '">Example: ' + esc(t.title) + ' (' + t.lines + ' hazards)</option>'; }).join('') + '</select>', true)
      + field('Title', input('title', '', 'text', ' maxlength="200" placeholder="e.g. Outdoor play"'), true) + '</div>'
      + '<div class="hr-sub" style="margin-top:6px;">The examples are a starting point written for childcare in general, not advice for your centre or a statement of what any rule requires. Each one is marked “Example — review for your centre” until you change it.</div>' + ERR);
    modal({ title: 'New risk assessment', body: box, actions: [{ label: 'Cancel' }, { label: 'Create', primary: true, busyLabel: 'Creating…', handler: function () {
      var o = { centre_id: +(val(box, 'centre_id') || 0), template_key: val(box, 'tpl'), title: val(box, 'title') };
      if (!o.template_key && !o.title) { msg(box, 'Give it a title, or start from an example.'); return false; }
      return Api.post('/hr/safety/risk', o).then(function (r) {
        saved(o.template_key ? 'Example copied. Review each line for your centre, then ask for sign-off.' : 'Created. Add the hazards next.');
        Api.get('/hr/safety/risk/' + r.id).then(function (x) { editRisk(x.row, d, reload); }, function () {});
        reload();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function levelSel(k, v) { return '<select class="hr-i" data-lk="' + k + '" style="width:100%;"><option value="">—</option>' + [1, 2, 3, 4, 5].map(function (n) { return '<option value="' + n + '"' + (+v === n ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select>'; }
  function editRisk(r, d, reload) {
    var items = (r.items || []).map(function (i) { return Object.assign({}, i); });
    var box = el('<div class="hr-g">' + field('Title', input('title', r.title, 'text', ' maxlength="200"')) + field('Activity or area', input('area', r.area || '', 'text', ' maxlength="160"'))
      + field('Owner', '<select class="hr-i" data-f="owner" style="width:100%;">' + options(d.owners, r.owner_user_id, '—') + '</select>') + field('Review by', input('review_on', r.review_on || '', 'date'))
      + field('Notes', area('notes', r.notes, 2), true) + '</div>'
      + '<div class="hr-sub" style="margin:6px 0;">Likelihood and severity: 1 (very unlikely / minor) to 5 (almost certain / very serious). Risk = likelihood × severity. Residual = what is left once your controls are in place.' + (r.status === 'signed' ? ' <b>Saving a change sends this back to draft for a new sign-off.</b>' : '') + '</div>'
      + '<div data-lines></div><div class="hr-bar"><button type="button" class="hr-b" data-addl data-kt-iconized="1">+ Add a hazard</button></div>' + ERR);
    var list = box.querySelector('[data-lines]');
    var read = function () {
      list.querySelectorAll('[data-li]').forEach(function (le) {
        var x = items[+le.getAttribute('data-li')]; if (!x) return;
        ['hazard', 'who', 'controls'].forEach(function (k) { x[k] = le.querySelector('[data-lt="' + k + '"]').value; });
        ['likelihood', 'severity', 'residual_likelihood', 'residual_severity'].forEach(function (k) { var v = le.querySelector('[data-lk="' + k + '"]').value; x[k] = v ? +v : null; });
      });
    };
    var draw = function () {
      list.innerHTML = items.map(function (x, i) {
        return '<div class="hs-line" data-kt-noswitch data-li="' + i + '"><div class="hs-lh"><b style="font-size:13px;">Hazard ' + (i + 1) + '</b><button type="button" class="hs-x" data-ldel="' + i + '" aria-label="Remove hazard" data-kt-iconized="1">✕</button></div>'
          + '<div class="hs-lg"><div style="grid-column:1/-1;"><label class="hr-l">Hazard</label><input class="hr-i" data-lt="hazard" maxlength="300" style="width:100%;" value="' + esc(x.hazard || '') + '"></div>'
          + '<div style="grid-column:1/-1;"><label class="hr-l">Who could be harmed</label><input class="hr-i" data-lt="who" maxlength="300" style="width:100%;" value="' + esc(x.who || '') + '" placeholder="Children, staff, visitors"></div>'
          + '<div><label class="hr-l">Likelihood</label>' + levelSel('likelihood', x.likelihood) + '</div><div><label class="hr-l">Severity</label>' + levelSel('severity', x.severity) + '</div>'
          + '<div style="grid-column:1/-1;"><label class="hr-l">Controls in place</label><textarea class="hr-i" data-lt="controls" rows="2" style="width:100%;min-height:48px;">' + esc(x.controls || '') + '</textarea></div>'
          + '<div><label class="hr-l">Residual likelihood</label>' + levelSel('residual_likelihood', x.residual_likelihood) + '</div><div><label class="hr-l">Residual severity</label>' + levelSel('residual_severity', x.residual_severity) + '</div></div></div>';
      }).join('') || '<div class="hr-sub" style="padding:6px 2px;">No hazards yet.</div>';
      list.querySelectorAll('[data-ldel]').forEach(function (b) { b.addEventListener('click', function () { read(); items.splice(+b.getAttribute('data-ldel'), 1); draw(); }); });
    };
    box.querySelector('[data-addl]').addEventListener('click', function () { read(); items.push({ hazard: '', who: '', likelihood: null, severity: null, controls: '', residual_likelihood: null, residual_severity: null }); draw(); });
    draw();
    modal({ title: 'Edit risk assessment', body: box, large: true, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      read();
      var o = { title: val(box, 'title'), area: val(box, 'area'), owner_user_id: val(box, 'owner') ? +val(box, 'owner') : null, review_on: val(box, 'review_on'), notes: val(box, 'notes'),
        items: items.filter(function (x) { return String(x.hazard || '').trim(); }) };
      if (!o.title) { msg(box, 'Give it a title.'); return false; }
      return Api.put('/hr/safety/risk/' + r.id, o).then(function (res) { saved(res && res.unsigned ? 'Saved. It needs a new sign-off.' : 'Saved.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function linesTable(items, print) {
    var b = print ? ' border="1" cellpadding="4" style="width:100%;border-collapse:collapse;font-size:11.5px;"' : ' style="width:100%;"';
    var sc = function (s, band) { return print ? (s ? s + ' (' + (BAND[band] ? BAND[band][2] : '') + ')' : '') : bandPill(band, s); };
    return '<table' + b + (print ? '' : ' data-kt-no-bulk') + '><thead><tr><th>Hazard</th><th>Who</th><th>L × S</th><th>Risk</th><th>Controls</th><th>Residual</th></tr></thead><tbody>'
      + (items || []).map(function (x) {
        return '<tr><td data-no-i18n>' + esc(x.hazard) + '</td><td data-no-i18n>' + esc(x.who || '') + '</td><td style="white-space:nowrap;">' + (x.likelihood || '—') + ' × ' + (x.severity || '—') + '</td>'
          + '<td>' + sc(x.score, x.band) + '</td><td data-no-i18n style="white-space:pre-wrap;">' + esc(x.controls || '') + '</td>'
          + '<td>' + (x.residual_score ? (x.residual_likelihood + ' × ' + x.residual_severity + ' ') + sc(x.residual_score, x.residual_band) : '—') + '</td></tr>';
      }).join('') + '</tbody></table>';
  }
  function viewRisk(r, d, reload) {
    var box = el('<div class="hr-kv"><span>Centre</span><b data-no-i18n>' + esc(r.centre_name) + '</b></div>'
      + (r.area ? '<div class="hr-kv"><span>Activity or area</span><b data-no-i18n>' + esc(r.area) + '</b></div>' : '')
      + '<div class="hr-kv"><span>Owner</span><b data-no-i18n>' + esc(r.owner_name || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Review by</span><b>' + reviewPill(r) + '</b></div>'
      + '<div class="hr-kv"><span>Status</span><b>' + statusPill(r) + (r.signed_at ? ' <span class="hr-sub">' + esc(day(r.signed_at)) + '</span>' : '') + '</b></div>'
      + (r.prepared_by_name ? '<div class="hr-kv"><span>Last changed by</span><b data-no-i18n>' + esc(r.prepared_by_name) + '</b></div>' : '')
      + (r.is_example ? '<div class="hr-note" style="margin-top:8px;">Example — review for your centre. This is a general starting point, not advice for your centre.</div>' : '')
      + (r.notes ? '<div class="hr-sub" style="margin:8px 0;white-space:pre-wrap;" data-no-i18n>' + esc(r.notes) + '</div>' : '')
      + '<div style="margin-top:10px;" data-kt-nogrid>' + linesTable(r.items, false) + '</div>');
    var acts = [{ label: 'Close' }, { label: 'Print', handler: function () { printRisk(r); } }];
    if (r.status === 'draft') acts.push({ label: 'Sign off', primary: true, handler: function () { setTimeout(function () { signRisk(r, d, reload); }, 0); } });
    modal({ title: r.title, body: box, large: true, actions: acts });
  }
  function signRisk(r, d, reload) {
    if (r.prepared_by && r.prepared_by === d.me) { toast('ℹ️', 'Someone else signs this one', 'You made the last changes, so another director or an admin signs it off.'); return; }
    var box = el('<div class="hr-sub" style="margin-bottom:8px;">You are confirming you have reviewed “' + esc(r.title) + '” at ' + esc(r.centre_name) + ' and its controls. Any later change sends it back to draft.</div>'
      + '<div class="hr-g">' + field('Type your full name to sign', input('name', '', 'text', ' maxlength="190" autocomplete="name"'), true) + '</div>' + ERR);
    modal({ title: 'Sign off risk assessment', body: box, actions: [{ label: 'Cancel' }, { label: 'Sign off', primary: true, busyLabel: 'Signing…', handler: function () {
      var n = val(box, 'name'); if (!n || n.length < 3) { msg(box, 'Type your full name.'); return false; }
      return Api.post('/hr/safety/risk/' + r.id + '/sign', { typed_name: n }).then(function () { saved('Signed off.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function printRisk(r) {
    var body = '<h2 style="margin:0 0 4px;">' + esc(r.title) + '</h2><div style="color:#555;margin:0 0 10px;">' + esc([r.centre_name, r.area].filter(Boolean).join(' · ')) + '</div>'
      + (r.is_example ? '<p style="font-size:12px;color:#92400E;">Example — review for your centre.</p>' : '')
      + '<p style="font-size:12px;">Owner: ' + esc(r.owner_name || '—') + ' · Review by: ' + esc(day(r.review_on) || '—') + ' · Status: ' + (r.status === 'signed' ? 'Signed off by ' + esc(r.signed_name || r.signed_by_name || '') + ' on ' + esc(day(r.signed_at)) : esc(r.status === 'archived' ? 'Archived' : 'Draft')) + '</p>'
      + linesTable(r.items, true)
      + (r.notes ? '<p style="font-size:12px;white-space:pre-wrap;">' + esc(r.notes) + '</p>' : '')
      + '<p style="font-size:11px;color:#555;margin-top:12px;">Likelihood and severity 1-5; risk = likelihood × severity (1-4 low, 5-9 medium, 10-16 high, 20-25 very high).</p>';
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(r.title) + '</title><style>body{font-family:Arial,sans-serif;font-size:13px;color:#111;margin:24px;}th{background:#f1f5f9;text-align:left;}</style></head><body>' + body + '</body></html>';
    if (KT.printDoc) KT.printDoc({ html: html }, { title: 'Risk assessment: ' + r.title });
  }

  // ══ CHEMICALS (WHMIS) ═══════════════════════════════════════════════════
  var FLAG = { sds_missing: 'No SDS on file', sds_old: 'SDS over 3 years old', sds_undated: 'SDS date not recorded', review_overdue: 'Review overdue', review_soon: 'Review due soon' };
  function flagChips(c) { return (c.flags || []).map(function (f) { return '<span class="hs-chip">' + esc(FLAG[f] || f) + '</span>'; }).join(' '); }
  function classList(c, classes) { return (c.hazard_classes || []).map(function (k) { return esc(String((classes || {})[k] || k).replace(/ \(.*\)$/, '')); }).join(', '); }
  var CS = { centre: '', archived: false };
  async function drawChemicals(host) {
    var q = []; if (CS.centre) q.push('centre_id=' + CS.centre); if (CS.archived) q.push('archived=1');
    var d; try { d = await Api.get('/hr/safety/chemicals' + (q.length ? '?' + q.join('&') : '')); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { drawChemicals(host); };
    var rows = d.rows || [];
    var flagged = rows.filter(function (c) { return (c.flags || []).length; }).length;
    host.innerHTML = '<div class="hr-note">Every cleaning product or other hazardous product kept at each centre, where it is stored, its WHMIS hazard pictograms and its safety data sheet (SDS). SDS files are private: staff of the centre can open them from My HR. A sheet whose date is more than ' + (d.sds_max_years || 3) + ' years old is flagged so you can ask the supplier for a current one; you get a reminder before each review date.</div>'
      + filterBar(CS, d.centres, '<label class="hr-sub" style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-hsf="arch"' + (CS.archived ? ' checked' : '') + '> No longer on site</label>'
        + (flagged ? '<span class="hs-chip">' + flagged + ' need attention</span>' : '') + '<span style="flex:1"></span><button type="button" class="hr-b p" data-new data-kt-iconized="1">+ Add a product</button>')
      + '<div class="kt-card">' + (rows.length ? '<table data-hshs="chem" style="width:100%;"><thead><tr><th>Product</th><th>Centre</th><th>Stored</th><th>Hazards</th><th>Safety data sheet</th><th>Review</th><th></th></tr></thead><tbody>'
        + rows.map(function (c) {
          var acts = (c.has_sds ? '<button type="button" data-cs="' + c.id + '">Open SDS</button>' : '') + (c.archived ? '<button type="button" data-cr="' + c.id + '">Back on site</button>'
            : '<button type="button" data-ce="' + c.id + '">Edit</button><button type="button" data-ct="' + c.id + '">Create H&amp;S task</button><button type="button" data-ca="' + c.id + '">No longer on site</button>');
          return '<tr><td><b data-no-i18n>' + esc(c.product) + '</b>' + (c.supplier ? '<div class="hr-sub" data-no-i18n>' + esc(c.supplier) + '</div>' : '') + '</td>'
            + '<td data-no-i18n>' + esc(c.centre_name) + '</td><td data-no-i18n>' + esc(c.location || '—') + (c.quantity ? '<div class="hr-sub">' + esc(c.quantity) + '</div>' : '') + '</td>'
            + '<td style="max-width:220px;">' + (classList(c, d.classes) || '<span class="hr-sub">None recorded</span>') + '</td>'
            + '<td>' + (c.has_sds ? (c.sds_date ? 'Dated ' + esc(day(c.sds_date)) : 'On file') : '') + '<div class="hs-chips" style="margin-top:3px;">' + flagChips(c) + '</div></td>'
            + '<td style="white-space:nowrap;">' + esc(day(c.sds_review_on) || '—') + '</td><td style="text-align:center;white-space:nowrap;">' + acts + '</td></tr>';
        }).join('') + '</tbody></table>'
        : '<div style="padding:16px;" class="hr-sub">' + (CS.archived ? 'Nothing here.' : 'No products yet. Press <b>Add a product</b> for each hazardous product you keep, and attach its safety data sheet.') + '</div>') + '</div>';
    var byId = {}; rows.forEach(function (c) { byId[c.id] = c; });
    var fc = host.querySelector('[data-hsf="centre"]'); if (fc) fc.addEventListener('change', function () { CS.centre = fc.value; reload(); });
    var fa = host.querySelector('[data-hsf="arch"]'); if (fa) fa.addEventListener('change', function () { CS.archived = fa.checked; reload(); });
    host.querySelector('[data-new]').addEventListener('click', function () { editChem(null, d, reload); });
    var on = function (attr, fn) { host.querySelectorAll('[' + attr + ']').forEach(function (b) { b.addEventListener('click', function () { var c = byId[b.getAttribute(attr)]; if (c) fn(c); }); }); };
    on('data-cs', function (c) { openFile('/hr/safety/chemicals/' + c.id + '/sds', 'Safety data sheet'); });
    on('data-ce', function (c) { editChem(c, d, reload); });
    on('data-ct', function (c) { newTask({ centre_id: c.centre_id, source: 'chemical', source_id: c.id, title: 'Get a current safety data sheet: ' + c.product }, reload); });
    on('data-ca', function (c) { Api.post('/hr/safety/chemicals/' + c.id + '/archive', { archived: true }).then(function () { saved('Marked as no longer on site. It stays on file.'); reload(); }, function (e) { fail('Could not save', e); }); });
    on('data-cr', function (c) { Api.post('/hr/safety/chemicals/' + c.id + '/archive', { archived: false }).then(function () { saved('Back on the list.'); reload(); }, function (e) { fail('Could not save', e); }); });
  }
  function editChem(c, d, reload) {
    var isNew = !c; c = c || { hazard_classes: [] };
    var box = el('<div class="hr-g">' + (isNew ? field('Centre', centreSelect(d.centres, CS.centre || (d.centres[0] && d.centres[0].id)), true) : '')
      + field('Product', input('product', c.product || '', 'text', ' maxlength="200"')) + field('Supplier / maker', input('supplier', c.supplier || '', 'text', ' maxlength="200"'))
      + field('Where it is stored', input('location', c.location || '', 'text', ' maxlength="200" placeholder="e.g. Kitchen, locked cupboard"')) + field('Amount kept (optional)', input('quantity', c.quantity || '', 'text', ' maxlength="80"'))
      + '</div><div class="hs-sec">Hazard pictograms on the label or SDS</div><div class="hs-classes" data-kt-noswitch>'
      + Object.keys(d.classes || {}).map(function (k) { return '<label><input type="checkbox" data-cls="' + esc(k) + '"' + ((c.hazard_classes || []).indexOf(k) >= 0 ? ' checked' : '') + '> ' + esc(d.classes[k]) + '</label>'; }).join('') + '</div>'
      + '<div class="hs-sec">Safety data sheet</div><div class="hr-g">'
      + field(c.has_sds ? 'Replace the SDS file (optional)' : 'SDS file (PDF or photo)', '<input class="hr-i" data-f="sds" type="file" accept="application/pdf,image/*,.doc,.docx" style="width:100%;height:auto;padding:5px;">', true)
      + field('Date on the SDS', input('sds_date', c.sds_date || '', 'date')) + field('Review by', input('sds_review_on', c.sds_review_on || (isNew ? addYears(today(), 1) : ''), 'date'))
      + field('Notes (first aid, PPE, how it is used)', area('notes', c.notes, 2), true) + '</div>' + ERR);
    modal({ title: isNew ? 'Add a product' : 'Edit ' + c.product, body: box, large: true, actions: [{ label: 'Cancel' }, { label: isNew ? 'Add' : 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      if (!val(box, 'product')) { msg(box, 'Name the product.'); return false; }
      var fd = new FormData();
      if (isNew) fd.append('centre_id', val(box, 'centre_id') || '');
      ['product', 'supplier', 'location', 'quantity', 'notes', 'sds_date', 'sds_review_on'].forEach(function (k) { fd.append(k, val(box, k) || ''); });
      var cls = []; box.querySelectorAll('[data-cls]:checked').forEach(function (x) { cls.push(x.getAttribute('data-cls')); });
      fd.append('hazard_classes', JSON.stringify(cls));
      var f = box.querySelector('[data-f="sds"]'); if (f && f.files && f.files[0]) fd.append('sds', f.files[0]);
      return Api.postForm(isNew ? '/hr/safety/chemicals' : '/hr/safety/chemicals/' + c.id, fd).then(function () { saved(); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ SAFE WORK PROCEDURES (Policy library, category "Safe work procedure") ══
  var SWP = 'Safe work procedure';
  function drawProcedures(host) {
    host.innerHTML = '<div class="hr-note">Step-by-step ways of doing a job safely (lifting a child, using a cleaning product, a nappy change…). Each one lives in your Policy library under the category “Safe work procedure”: staff open it and sign that they have read it, and they are asked again whenever you publish a new version.</div><div data-swp></div>';
    var box = host.querySelector('[data-swp]');
    if (KT.policies && KT.policies.drawLibrary) return KT.policies.drawLibrary(box, { category: SWP, noun: 'procedure' });
    box.innerHTML = '<div class="kt-card" style="padding:14px;"><a href="#policies">Open the Policy library</a></div>';
  }

  // ══ H&S DUTIES ══════════════════════════════════════════════════════════
  function certPill(c) {
    if (!c || c.state === 'none') return '<span class="hr-sub">Not needed</span>';
    if (c.state === 'ok') return pill('#ECFDF5', '#047857', c.label + (c.expires ? ' · to ' + day(c.expires) : ' · valid'));
    if (c.state === 'soon') return pill('#FFFBEB', '#B45309', c.label + ' expires ' + day(c.expires));
    if (c.state === 'expired') return pill('#FEF2F2', '#B91C1C', c.label + ' expired ' + day(c.expires));
    return pill('#FEF2F2', '#B91C1C', 'No ' + c.label + ' on file');
  }
  var DS = { ended: false };
  async function drawDuties(host) {
    var d; try { d = await Api.get('/hr/safety/duties' + (DS.ended ? '?ended=1' : '')); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { drawDuties(host); };
    var rows = d.rows || [];
    var warn = rows.filter(function (r) { return r.cert && ['soon', 'expired', 'missing'].indexOf(r.cert.state) >= 0; }).length;
    host.innerHTML = '<div class="hr-note">Who is your first aider, fire warden or health &amp; safety representative at each centre. When a duty needs a certificate, it is checked against the person\'s certifications every time: you see when it is about to expire, and the person and their directors get a reminder 30 days before, when it expires, or if there is none on file.</div>'
      + '<div class="hr-bar"><label class="hr-sub" style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-hsf="ended"' + (DS.ended ? ' checked' : '') + '> Ended duties</label>'
      + (warn ? '<span class="hs-chip">' + warn + ' certificate' + (warn === 1 ? '' : 's') + ' to sort out</span>' : '') + '<span style="flex:1"></span><button type="button" class="hr-b p" data-new data-kt-iconized="1">+ Name a duty</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hshs="duties" style="width:100%;"><thead><tr><th>Person</th><th>Duty</th><th>Centre</th><th>Certificate</th><th>Dates</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          return '<tr><td><b data-no-i18n>' + esc(r.name) + '</b></td><td>' + esc(r.duty_label) + (r.notes ? '<div class="hr-sub" data-no-i18n>' + esc(r.notes) + '</div>' : '') + '</td><td data-no-i18n>' + esc(r.centre_name) + '</td>'
            + '<td>' + certPill(r.cert) + '</td><td style="white-space:nowrap;">' + esc(day(r.starts_on) || '—') + (r.ends_on ? ' to ' + esc(day(r.ends_on)) : '') + '</td>'
            + '<td style="text-align:center;white-space:nowrap;">' + (r.ended ? '' : '<button type="button" data-de="' + r.id + '">Edit</button><button type="button" data-dx="' + r.id + '">End duty</button>') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">' + (DS.ended ? 'No ended duties.' : 'No duties named yet. Press <b>Name a duty</b>.') + '</div>') + '</div>';
    var byId = {}; rows.forEach(function (r) { byId[r.id] = r; });
    var fe = host.querySelector('[data-hsf="ended"]'); if (fe) fe.addEventListener('change', function () { DS.ended = fe.checked; reload(); });
    host.querySelector('[data-new]').addEventListener('click', function () { editDuty(null, d, reload); });
    host.querySelectorAll('[data-de]').forEach(function (b) { b.addEventListener('click', function () { editDuty(byId[b.getAttribute('data-de')], d, reload); }); });
    host.querySelectorAll('[data-dx]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var r = byId[b.getAttribute('data-dx')]; if (!r) return;
        var ok = KT.confirm ? await KT.confirm({ title: 'End this duty?', description: r.name + ' stops being ' + r.duty_label + ' today. It stays on file.', okLabel: 'End duty' }) : true;
        if (!ok) return;
        Api.post('/hr/safety/duties/' + r.id + '/end', {}).then(function () { saved('Duty ended.'); reload(); }, function (e) { fail('Could not end it', e); });
      });
    });
  }
  function editDuty(r, d, reload) {
    var isNew = !r; r = r || {};
    var types = d.duty_types || [];
    var certOpts = function (sel) { return '<option value="">No certificate needed</option>' + Object.keys(d.cert_types || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (sel === k ? ' selected' : '') + '>' + esc(d.cert_types[k]) + '</option>'; }).join(''); };
    var firstCert = isNew ? (types[0] && types[0].cert_type) : r.cert_type;
    var box = el('<div class="hr-g">' + field('Person', '<select class="hr-i" data-f="user_id" style="width:100%;">' + options(d.people, r.user_id, 'Choose…') + '</select>')
      + field('Duty', '<select class="hr-i" data-f="duty" style="width:100%;">' + types.map(function (t) { return '<option value="' + esc(t.key) + '"' + (r.duty === t.key ? ' selected' : '') + '>' + esc(t.label) + '</option>'; }).join('') + '</select>')
      + field('Name or detail (optional; required for “Other”)', input('label', r.label || '', 'text', ' maxlength="120" placeholder="e.g. Upstairs, mornings"'))
      + field('Centre', '<select class="hr-i" data-f="centre_id" style="width:100%;">' + (d.is_admin ? '<option value="">Every centre</option>' : '') + options(d.centres, r.centre_id) + '</select>')
      + field('Certificate the duty needs', '<select class="hr-i" data-f="cert_type" style="width:100%;">' + certOpts(firstCert) + '</select>')
      + field('From', input('starts_on', r.starts_on || today(), 'date')) + field('Until (optional)', input('ends_on', r.ends_on || '', 'date'))
      + field('Notes', input('notes', r.notes || '', 'text', ' maxlength="500"'), true) + '</div>' + ERR);
    var dsel = box.querySelector('[data-f="duty"]'), csel = box.querySelector('[data-f="cert_type"]');
    dsel.addEventListener('change', function () { var t = types.filter(function (x) { return x.key === dsel.value; })[0]; if (t) csel.value = t.cert_type || ''; });
    modal({ title: isNew ? 'Name a health & safety duty' : 'Edit duty', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { user_id: +(val(box, 'user_id') || 0), duty: val(box, 'duty'), label: val(box, 'label'), centre_id: val(box, 'centre_id') ? +val(box, 'centre_id') : null,
        cert_type: val(box, 'cert_type'), starts_on: val(box, 'starts_on'), ends_on: val(box, 'ends_on'), notes: val(box, 'notes') };
      if (!o.user_id) { msg(box, 'Choose the person.'); return false; }
      return (isNew ? Api.post('/hr/safety/duties', o) : Api.put('/hr/safety/duties/' + r.id, o)).then(function () { saved(); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ H&S TASKS ═══════════════════════════════════════════════════════════
  function taskStatus(t) {
    if (t.status === 'done') return pill('#ECFDF5', '#047857', 'Done');
    if (t.due_date && t.due_date < today()) return pill('#FEF2F2', '#B91C1C', 'Overdue');
    return pill('#EFF6FF', '#1D4ED8', 'Open');
  }
  function evidenceCell(t) {
    if (t.has_evidence) return '✓ <span data-no-i18n>' + esc(t.evidence_name || 'File') + '</span>' + (t.evidence_by_name ? '<div class="hr-sub" data-no-i18n>' + esc(t.evidence_by_name) + '</div>' : '');
    return t.evidence_required ? '<span class="hs-chip">Needed</span>' : '<span class="hr-sub">Not needed</span>';
  }
  var TS = { status: 'open' };
  var TCTX = null;   // the last /hr/safety/tasks answer (people, centres, links) for the new-task popup
  async function drawTasks(host) {
    var d; try { d = await Api.get('/hr/safety/tasks?status=' + TS.status); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    TCTX = d;
    var reload = function () { drawTasks(host); };
    var rows = d.rows || [];
    host.innerHTML = '<div class="hr-note">Jobs that keep people safe — fix the gate, restock the first aid kit, replace a safety data sheet — given to a named person, with evidence (a photo or a file) needed before it can be closed. They also show in the person\'s own task list, and they cannot be ticked off there without the evidence either.</div>'
      + '<div class="hr-bar"><div class="hs-tabs" role="tablist">' + [['open', 'Open (' + ((d.counts && d.counts.open) || 0) + ')'], ['done', 'Done'], ['all', 'All']].map(function (t) {
        return '<button type="button" role="tab" data-kt-iconized="1" data-ts="' + t[0] + '" class="' + (TS.status === t[0] ? 'on' : '') + '" aria-selected="' + (TS.status === t[0]) + '">' + esc(t[1]) + '</button>';
      }).join('') + '</div><span style="flex:1"></span><button type="button" class="hr-b p" data-new data-kt-iconized="1">+ New H&amp;S task</button></div>'
      + '<div class="kt-card">' + (rows.length ? tasksTable(rows, true) : '<div style="padding:16px;" class="hr-sub">' + (TS.status === 'open' ? 'No open health & safety tasks.' : 'Nothing here yet.') + '</div>') + '</div>';
    host.querySelectorAll('[data-ts]').forEach(function (b) { b.addEventListener('click', function () { TS.status = b.getAttribute('data-ts'); reload(); }); });
    host.querySelector('[data-new]').addEventListener('click', function () { newTask({}, reload); });
    wireTasks(host, rows, reload, true);
  }
  function tasksTable(rows, manager) {
    return '<table data-hshs="tasks" style="width:100%;"><thead><tr><th>Task</th><th>Centre</th>' + (manager ? '<th>Assigned to</th>' : '') + '<th>Due</th><th>Evidence</th><th>Status</th><th></th></tr></thead><tbody>'
      + rows.map(function (t) {
        var acts = '<button type="button" data-tv="' + t.id + '">View</button>';
        if (t.status !== 'done') acts += '<button type="button" data-tu="' + t.id + '">' + (t.has_evidence ? 'Replace evidence' : 'Add evidence') + '</button><button type="button" data-tc="' + t.id + '">Close task</button>';
        if (t.has_evidence) acts += '<button type="button" data-tf="' + t.id + '">Open evidence</button>';
        if (manager && t.status === 'done') acts += '<button type="button" data-tr="' + t.id + '">Reopen</button>';
        if (manager) acts += '<button type="button" data-tx="' + t.id + '">Cancel task</button>';
        return '<tr><td><b data-no-i18n>' + esc(t.title) + '</b>' + (t.description ? '<div class="hr-sub" data-no-i18n>' + esc(t.description) + '</div>' : '') + '</td><td data-no-i18n>' + esc(t.centre_name || '—') + '</td>'
          + (manager ? '<td data-no-i18n>' + esc(t.assigned_to_name) + '</td>' : '') + '<td style="white-space:nowrap;">' + esc(day(t.due_date) || '—') + '</td>'
          + '<td>' + evidenceCell(t) + '</td><td>' + taskStatus(t) + '</td><td style="text-align:center;white-space:nowrap;">' + acts + '</td></tr>';
      }).join('') + '</tbody></table>';
  }
  function wireTasks(host, rows, reload, manager) {
    var byId = {}; rows.forEach(function (t) { byId[t.id] = t; });
    var on = function (attr, fn) { host.querySelectorAll('[' + attr + ']').forEach(function (b) { b.addEventListener('click', function () { var t = byId[b.getAttribute(attr)]; if (t) fn(t); }); }); };
    on('data-tv', function (t) { viewTask(t, reload); });
    on('data-tu', function (t) { addEvidence(t, reload); });
    on('data-tc', function (t) { closeTask(t, reload); });
    on('data-tf', function (t) { openFile('/hr/safety/tasks/' + t.id + '/evidence', 'Evidence'); });
    on('data-tr', function (t) { Api.post('/hr/safety/tasks/' + t.id + '/reopen', {}).then(function () { saved('Reopened.'); reload(); }, function (e) { fail('Could not reopen', e); }); });
    on('data-tx', async function (t) {
      var ok = KT.confirm ? await KT.confirm({ title: 'Cancel this task?', description: '“' + t.title + '” is taken off every list.', okLabel: 'Cancel task' }) : true;
      if (ok) Api.post('/hr/safety/tasks/' + t.id + '/cancel', {}).then(function () { saved('Task cancelled.'); reload(); }, function (e) { fail('Could not cancel', e); });
    });
  }
  function viewTask(t, reload) {
    var box = el('<div class="hr-kv"><span>Centre</span><b data-no-i18n>' + esc(t.centre_name || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Assigned to</span><b data-no-i18n>' + esc(t.assigned_to_name) + '</b></div>'
      + '<div class="hr-kv"><span>Set by</span><b data-no-i18n>' + esc(t.assigned_by_name || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Due</span><b>' + esc(day(t.due_date) || '—') + '</b></div>'
      + '<div class="hr-kv"><span>Status</span><b>' + taskStatus(t) + '</b></div>'
      + '<div class="hr-kv"><span>Evidence</span><b>' + evidenceCell(t) + '</b></div>'
      + (t.evidence_note ? '<div class="hr-sub" style="margin-top:6px;white-space:pre-wrap;" data-no-i18n>' + esc(t.evidence_note) + '</div>' : '')
      + (t.description ? '<div style="margin-top:10px;font-size:13.5px;white-space:pre-wrap;" data-no-i18n>' + esc(t.description) + '</div>' : ''));
    var acts = [{ label: 'Close' }];
    if (t.has_evidence) acts.push({ label: 'Open evidence', handler: function () { openFile('/hr/safety/tasks/' + t.id + '/evidence', 'Evidence'); return false; } });
    if (t.status !== 'done') acts.push({ label: t.has_evidence ? 'Close task' : 'Add evidence', primary: true, handler: function () { setTimeout(function () { (t.has_evidence ? closeTask : addEvidence)(t, reload); }, 0); } });
    modal({ title: t.title, body: box, actions: acts });
  }
  function addEvidence(t, reload) {
    var box = el('<div class="hr-g">' + field('Photo or file', '<input class="hr-i" data-f="file" type="file" accept="image/*,application/pdf,.doc,.docx" capture="environment" style="width:100%;height:auto;padding:5px;">', true)
      + field('Note (optional)', area('note', t.evidence_note || '', 2), true) + '</div><div class="hr-sub" style="margin-top:6px;">On a phone this opens the camera; you can also choose a file.</div>' + ERR);
    modal({ title: 'Evidence: ' + t.title, body: box, actions: [{ label: 'Cancel' }, { label: 'Upload', primary: true, busyLabel: 'Uploading…', handler: function () {
      var f = box.querySelector('[data-f="file"]'); if (!f.files || !f.files[0]) { msg(box, 'Take a photo or choose a file.'); return false; }
      var fd = new FormData(); fd.append('file', f.files[0]); if (val(box, 'note')) fd.append('note', val(box, 'note'));
      return Api.postForm('/hr/safety/tasks/' + t.id + '/evidence', fd).then(function () { saved('Evidence added. You can close the task now.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function closeTask(t, reload) {
    if (t.evidence_required && !t.has_evidence) { toast('ℹ️', 'Evidence first', 'Add a photo or file before closing this task.'); addEvidence(t, reload); return; }
    Api.post('/hr/safety/tasks/' + t.id + '/close', {}).then(function () { toast('✅', 'Task closed', '', '#16A34A'); reload(); }, function (e) { fail('Could not close it', e); });
  }
  async function newTask(pre, reload) {
    var d = TCTX;
    if (!d) { try { d = TCTX = await Api.get('/hr/safety/tasks?status=open'); } catch (e) { return fail('Could not load', e); } }
    var links = [];
    if (pre.source && d.links && d.links[pre.source]) links = d.links[pre.source].filter(function (x) { return x.id === pre.source_id; });
    var box = el('<div class="hr-g">' + field('Task', input('title', pre.title || '', 'text', ' maxlength="200"'), true)
      + field('Centre', centreSelect(d.centres, pre.centre_id || (d.centres[0] && d.centres[0].id))) + field('Assign to', '<select class="hr-i" data-f="assigned_to" style="width:100%;">' + options(d.people, null, 'Choose…') + '</select>')
      + field('Due', input('due_date', '', 'date')) + field('Priority', '<select class="hr-i" data-f="priority" style="width:100%;"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select>')
      + field('Details', area('description', '', 3), true)
      + '<label style="grid-column:1/-1;display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="checkbox" data-f="evidence_required" checked> Evidence needed to close (a photo or file)</label></div>'
      + (links.length ? '<div class="hr-sub" style="margin-top:6px;">Linked to: <b data-no-i18n>' + esc(links[0].title) + '</b></div>' : '') + ERR);
    modal({ title: 'New health & safety task', body: box, actions: [{ label: 'Cancel' }, { label: 'Create', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { title: val(box, 'title'), centre_id: +(val(box, 'centre_id') || 0), assigned_to: +(val(box, 'assigned_to') || 0), due_date: val(box, 'due_date'),
        priority: val(box, 'priority'), description: val(box, 'description'), evidence_required: !!val(box, 'evidence_required') };
      if (links.length) { o.source = pre.source; o.source_id = pre.source_id; }
      if (!o.title) { msg(box, 'Say what needs doing.'); return false; }
      if (!o.assigned_to) { msg(box, 'Choose who does it.'); return false; }
      return Api.post('/hr/safety/tasks', o).then(function () { saved('Task added. They have been told.'); if (reload) reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ MY HR ═══════════════════════════════════════════════════════════════
  async function mine(b) {
    css();
    var holder = document.createElement('div'); holder.setAttribute('data-hs-mine', '1');
    b.appendChild(holder);
    var d; try { d = await Api.get('/hr/me/safety'); } catch (e) { return; }
    if (!holder.isConnected || !d || !d.is_staff) return;
    var reload = function () { var p = holder.parentNode; holder.remove(); if (p) mine(p); };
    var h = '<div class="hrt-sec hs-sec">My H&amp;S duties</div><div class="kt-card" style="padding:10px 14px;" data-kt-nogrid>'
      + ((d.duties || []).length ? d.duties.map(function (r) { return '<div class="hr-kv"><span>' + esc(r.duty_label) + ' · <span data-no-i18n>' + esc(r.centre_name) + '</span></span><b>' + certPill(r.cert) + '</b></div>'; }).join('')
        + ((d.duties || []).some(function (r) { return r.cert && ['soon', 'expired', 'missing'].indexOf(r.cert.state) >= 0; }) ? '<div class="hr-sub" style="margin-top:6px;">Renewed a certificate? Give a copy to your director so it can be added to your record.</div>' : '')
        : '<span class="hr-sub">You have no named health &amp; safety duties.</span>') + '</div>';
    h += '<div class="hrt-sec hs-sec">My H&amp;S tasks</div><div class="kt-card">' + ((d.tasks || []).length ? tasksTable(d.tasks, false) : '<div style="padding:14px;" class="hr-sub">No health &amp; safety tasks for you.</div>') + '</div>';
    h += '<div class="hrt-sec hs-sec">Safety data sheets</div><div class="kt-card">' + ((d.chemicals || []).length
      ? '<table data-hshs="mysds" style="width:100%;"><thead><tr><th>Product</th><th>Stored</th><th>Hazards</th><th></th></tr></thead><tbody>' + d.chemicals.map(function (c) {
        return '<tr><td><b data-no-i18n>' + esc(c.product) + '</b><div class="hr-sub" data-no-i18n>' + esc(c.centre_name) + '</div></td><td data-no-i18n>' + esc(c.location || '—') + '</td><td>' + (classList(c, d.classes) || '—') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;">' + (c.has_sds ? '<button type="button" data-ms="' + c.id + '">Open SDS</button>' : '<span class="hr-sub">No SDS yet</span>') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No products listed for your centre yet.</div>') + '</div>';
    h += '<div class="hrt-sec hs-sec">Safe work procedures</div><div data-myswp></div>';
    holder.innerHTML = h;
    wireTasks(holder, d.tasks || [], reload, false);
    holder.querySelectorAll('[data-ms]').forEach(function (x) { x.addEventListener('click', function () { openFile('/hr/safety/chemicals/' + x.getAttribute('data-ms') + '/sds', 'Safety data sheet'); }); });
    var sw = holder.querySelector('[data-myswp]');
    if (KT.policies && KT.policies.renderMine) KT.policies.renderMine(sw, { category: SWP, embedded: true });
    else sw.innerHTML = '<div class="kt-card" style="padding:14px;"><a href="#my-policies">Open Policies to review</a></div>';
  }

  window.KTHrHs = {
    tabs: ['risk', 'chemicals', 'procedures', 'duties', 'hstasks'],
    has: function (t) { return this.tabs.indexOf(t) >= 0; },
    draw: function (tab, host) {
      css();
      if (tab === 'risk') return drawRisk(host);
      if (tab === 'chemicals') return drawChemicals(host);
      if (tab === 'procedures') return drawProcedures(host);
      if (tab === 'duties') return drawDuties(host);
      if (tab === 'hstasks') return drawTasks(host);
    },
    mine: mine, newTask: newTask
  };
})(window);
