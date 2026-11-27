/* ═══════════════════════════════════════════════════════════════════════
   screen-hr-file.js - KiddieTrac HR Phase 4, batch 4 (2026-11-27).

   - The HR profile gains three cards: Documents (the person's private files: ID, contract, certificates, sick notes;
     shared with them or managers only; expiry + follow-up dates; bulk upload; preview without downloading),
     Pay and role history (dated changes, future ones apply on their day; pay only for people who may see pay) and
     Leaving (checklist, leave payout, ROE preparation sheet, undo, rehire, "download all their data").
   - HR > Documents: company folders (a new folder is admins-only; everyone / chosen people), read receipts + accept.
   - HR > Leavers: former staff.
   - My HR > "My documents": what is shared with me and the company documents to read / accept.
   Files are private (ProtectedMedia); a signed link is fetched only when someone opens one. The ROE sheet is a
   preparation sheet: KiddieTrac never files with Service Canada. Server: App\Http\Controllers\Api\HrFileController.
   Lazy: a "d" dependency of screen-hr.js and screen-hr-talent.js, never loaded on its own.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Api) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var d = e && e.data;
    if (d && d.errors) { var k = Object.keys(d.errors)[0]; if (k && d.errors[k] && d.errors[k][0]) return d.errors[k][0]; }
    return (d && d.message) || (e && e.message && !/^HTTP \d|fetch|network/i.test(e.message) ? e.message : '') || 'Something went wrong. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function today() { try { if (KT.agencyToday) return KT.agencyToday(); } catch (e) {} return new Date().toISOString().slice(0, 10); }
  function addDays(ymd, n) { var p = ymd.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2] + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function money(n) { var v = Number(n) || 0; return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function opts(map, v, blank) {
    return (blank != null ? '<option value="">' + esc(blank) + '</option>' : '') + Object.keys(map || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (String(v) === String(k) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join('');
  }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function closeModal() { try { var r = document.getElementById('modalRoot'); var x = r && r.querySelector('.modal-close'); if (x) x.click(); } catch (e) {} }
  function later(fn) { setTimeout(fn, 0); }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function input(k, v, type, extra) { return '<input class="hr-i" data-f="' + k + '" type="' + (type || 'text') + '" value="' + esc(v == null ? '' : v) + '" style="width:100%;"' + (extra || '') + '>'; }
  function select(k, inner) { return '<select class="hr-i" data-f="' + k + '" style="width:100%;">' + inner + '</select>'; }
  function check(k, v, label, dis) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;"><input type="checkbox" class="kt-nosw" data-f="' + k + '"' + (v ? ' checked' : '') + (dis ? ' disabled' : '') + '> ' + esc(label) + '</label>'; }
  function kv(k, v) { return '<div class="hr-kv"><span>' + esc(k) + '</span><b data-no-i18n>' + (v == null || v === '' ? '<span class="hr-sub">—</span>' : v) + '</b></div>'; }
  function size(b) { b = +b || 0; return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';

  /* ⋮ inside a popup (the portal's table polish does not reach popups): KT.openActionMenu with detached buttons. */
  function kebabBtn(key) { return '<button type="button" class="kt-ka-kebab hrf-keb" data-keb="' + esc(key) + '" aria-label="Actions" data-kt-iconized="1">⋮</button>'; }
  function menu(anchor, items) {
    var bs = items.filter(Boolean).map(function (it) { var b = document.createElement('button'); b.type = 'button'; b.textContent = it[0]; b.addEventListener('click', function () { it[1](); }); return b; });
    if (KT.openActionMenu) KT.openActionMenu(anchor, bs); else if (bs[0]) bs[0].click();
  }

  var CSS_ID = 'kt-hrf-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.hrf-row{display:flex;gap:10px;align-items:center;padding:8px 2px;border-bottom:1px solid #F1F5F9;}'
      + '.hrf-row:last-child{border-bottom:0;}.hrf-row .t{flex:1;min-width:0;}.hrf-row .t b{font-size:13.5px;word-break:break-word;}'
      + '.hrf-keb{width:30px;height:30px;border-radius:8px;border:1px solid #E2E8F0;background:#fff;cursor:pointer;font-size:16px;line-height:1;color:#334155;flex:0 0 auto;}'
      + '.hrf-sec{font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#0E7490;font-weight:800;margin:14px 0 6px;}'
      + '.hrf-ck{display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px dashed #EEF2F6;font-size:13px;}'
      + '.hrf-ck:last-child{border-bottom:0;}.hrf-ck .t{flex:1;}'
      + '.hrf-pick{max-height:200px;overflow:auto;border:1px solid #E2E8F0;border-radius:8px;padding:6px 9px;}'
      + 'html.kt-dark .hrf-keb{background:#0F172A;border-color:#334155;color:#E2E8F0;}html.kt-dark .hrf-row,html.kt-dark .hrf-ck{border-color:#1E293B;}'
      + 'html.kt-dark .hrf-sec{color:#5EEAD4;}html.kt-dark .hrf-pick{border-color:#334155;}';
    document.head.appendChild(s);
  }

  var VIS = { employee: 'Shared with them', managers: 'Managers only' };
  var FVIS = { admins: 'Agency admins only', everyone: 'Everyone on staff', people: 'Chosen people' };
  var FIELD_LABEL = { pay_rate: 'Pay rate', pay_type: 'Pay type', job_title: 'Job title', employment_type: 'Employment', hours_per_week: 'Hours a week' };

  function expiryPill(d) {
    if (!d.expires_on) return '';
    var t = today(), soon = addDays(t, 30);
    if (d.expires_on < t) return pill('#FEF2F2', '#B91C1C', 'Expired ' + day(d.expires_on));
    if (d.expires_on <= soon) return pill('#FFFBEB', '#B45309', 'Expires ' + day(d.expires_on));
    return pill('#F1F5F9', '#475569', 'Expires ' + day(d.expires_on));
  }

  /* Preview without downloading: the house document viewer, fed a short-lived signed link. */
  async function openDoc(d, download) {
    var r;
    try { r = await Api.get('/hr/documents/' + d.id + '/open'); } catch (e) { return fail('Could not open the document', e); }
    if (download || !r.previewable || !KT.viewDocument) {
      var a = document.createElement('a'); a.href = r.url; a.setAttribute('download', r.name || 'document'); a.setAttribute('data-kt-download', '1');
      document.body.appendChild(a); a.click(); a.remove(); return;
    }
    KT.viewDocument(r.url, { title: r.title || d.title, filename: r.name, mime: r.mime, label: 'HR document' });
  }

  async function saveBlob(path, name) {
    try {
      var b = await KT.fetchFileBlob((KT.API_BASE || '/api/v1') + path), u = URL.createObjectURL(b);
      var a = document.createElement('a'); a.href = u; a.download = name; a.setAttribute('data-kt-download', '1'); document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(u); }, 30000);
    } catch (e) { fail('Could not download', e); }
  }

  // ── a person's documents ─────────────────────────────────────────────────
  function docLine(d, extra) {
    return '<div class="hrf-row"><div class="t"><b data-no-i18n>' + esc(d.title) + '</b> ' + expiryPill(d)
      + '<div class="hr-sub">' + esc(d.type_label) + (d.visibility ? ' · ' + esc(VIS[d.visibility] || '') : '')
      + (d.covers_from ? ' · covers ' + esc(day(d.covers_from)) + (d.covers_to && d.covers_to !== d.covers_from ? ' – ' + esc(day(d.covers_to)) : '') : '')
      + (d.follow_up_on ? ' · follow up ' + esc(day(d.follow_up_on)) : '') + ' · ' + esc(size(d.file_size)) + (d.created_at ? ' · added ' + esc(day(d.created_at)) : '') + '</div></div>'
      + (extra || '') + kebabBtn(d.id) + '</div>';
  }

  async function openPersonDocs(id, name) {
    css();
    var r;
    try { r = await Api.get('/hr/people/' + id + '/documents'); } catch (e) { return fail('Could not open the documents', e); }
    var docs = r.documents || [], can = r.can || {};
    var box = el('<div class="hr-note">🔒 Private to ' + esc(name || 'this person') + ' (when shared with them) and their managers. Sick notes: only them, their centre director and agency admins.'
      + ' Files open from a short-lived link and are never sent by email.</div>'
      + (docs.length ? docs.map(function (d) { return docLine(d); }).join('') : '<div class="hr-sub" style="padding:10px 2px;">No documents yet.</div>')
      + (r.user_record_files ? '<div class="hr-sub" style="margin-top:10px;">' + r.user_record_files + ' more file(s) are on their user record (the signed agreement and older attachments).'
        + (KT.openUserRecord ? ' <button type="button" class="hr-link" data-ur>Open user record</button>' : '') + '</div>' : ''));
    var acts = [{ label: 'Close' }];
    if (can.manage) acts.push({ label: 'Upload files', primary: true, handler: function () { later(function () { openUpload(id, name, r.types); }); } });
    modal({ title: 'Documents' + (name ? ': ' + name : ''), body: box, large: true, actions: acts });
    var ur = box.querySelector('[data-ur]'); if (ur) ur.addEventListener('click', function () { closeModal(); try { KT.openUserRecord(id); } catch (e) {} });
    box.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = docs.filter(function (x) { return String(x.id) === b.getAttribute('data-keb'); })[0]; if (!d) return;
        menu(b, [d.previewable ? ['Preview', function () { openDoc(d); }] : null, ['Download', function () { openDoc(d, true); }],
          can.manage ? ['Edit details', function () { closeModal(); later(function () { openEditDoc(d, r.types, function () { openPersonDocs(id, name); }); }); }] : null,
          can.manage ? ['Remove', function () { removeDoc(d, function () { closeModal(); later(function () { openPersonDocs(id, name); }); }); }] : null]);
      });
    });
  }

  function docFields(d, types, company) {
    var t = d || {};
    return field('Type', select('doc_type', opts(types, t.doc_type || (company ? 'policy' : 'other'))))
      + (company ? '' : field('Who can see it', select('visibility', opts(VIS, t.visibility || 'employee'))))
      + field('Expires on (optional)', input('expires_on', t.expires_on, 'date'))
      + field('Follow up on (optional)', input('follow_up_on', t.follow_up_on, 'date'))
      + field('Follow-up note (optional, never health details)', input('follow_up_note', t.follow_up_note), true)
      + (company ? '<div style="grid-column:1/-1;">' + check('requires_accept', t.requires_accept, 'Ask staff to read and accept it') + '</div>' : '');
  }

  function openUpload(id, name, types, folder) {
    css();
    var company = !!folder;
    var box = el('<div class="hr-g">' + field('Files (up to 20 at once, 15 MB each)', '<input class="hr-i" data-f="files" type="file" multiple accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.txt" style="width:100%;height:auto;padding:5px;">', true)
      + field('Title (when uploading one file)', input('title', ''), true) + docFields(null, types, company) + '</div>'
      + '<div class="hr-sub" style="margin-top:8px;">' + (company ? 'Reminders: the agency admins get an email 7 days before a follow-up date and on the day, and before a document expires.'
        : 'Reminders: their managers get a bell and an email 7 days before the follow-up date and on the day; an expiry date is chased 30 and 7 days before and on the day.') + '</div>' + ERR);
    var back = function () { if (company) folder.reload(); else openPersonDocs(id, name); };
    modal({ title: company ? 'Upload to ' + folder.name : 'Upload documents' + (name ? ': ' + name : ''), body: box, actions: [
      { label: 'Cancel', handler: function () { later(back); } },
      { label: 'Upload', primary: true, busyLabel: 'Uploading…', handler: function () {
        var inp = box.querySelector('[data-f="files"]');
        if (!inp.files || !inp.files.length) { msg(box, 'Choose at least one file.'); return false; }
        var fd = new FormData();
        Array.prototype.forEach.call(inp.files, function (f) { fd.append('files[]', f); });
        ['title', 'doc_type', 'visibility', 'expires_on', 'follow_up_on', 'follow_up_note'].forEach(function (k) { var v = val(box, k); if (v != null) fd.append(k, v); });
        if (company) fd.append('requires_accept', val(box, 'requires_accept') ? '1' : '0');
        return Api.postForm(company ? '/hr/doc-folders/' + folder.id + '/documents' : '/hr/people/' + id + '/documents', fd)
          .then(function (res) { saved(((res && res.ids) || []).length + ' file(s) added.'); later(back); }, function (e) { msg(box, errMsg(e)); return false; });
      } }] });
  }

  function openEditDoc(d, types, back) {
    var company = !d.user_id;
    var box = el('<div class="hr-g">' + field('Title', input('title', d.title), true) + docFields(d, types, company) + '</div>' + ERR);
    modal({ title: 'Edit document', body: box, actions: [{ label: 'Cancel', handler: function () { later(back); } }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = {}; ['title', 'doc_type', 'visibility', 'expires_on', 'follow_up_on', 'follow_up_note'].forEach(function (k) { o[k] = val(box, k); });
      if (company) { o.requires_accept = !!val(box, 'requires_accept'); o.folder_id = d.folder_id; }
      if (!o.title) { msg(box, 'Give it a title.'); return false; }
      return Api.put('/hr/documents/' + d.id, o).then(function () { saved('Document updated.'); later(back); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function removeDoc(d, done) {
    var ok = KT.confirm ? await KT.confirm({ title: 'Remove ' + d.title + '?', description: 'It disappears from the file. The file itself is kept for your records.', okLabel: 'Remove', tone: 'danger' }) : true;
    if (!ok) return;
    try { await Api.post('/hr/documents/' + d.id + '/archive', {}); saved('Document removed.'); done(); } catch (e) { fail('Could not remove it', e); }
  }

  // ── pay + role history ───────────────────────────────────────────────────
  function fmtField(k, v, r) {
    if (v == null || v === '') return '—';
    if (k === 'pay_rate') return money(v);
    if (k === 'employment_type') return (r.employment_types || {})[v] || v;
    if (k === 'pay_type') return (r.pay_types || {})[v] || v;
    if (k === 'hours_per_week') return v + ' h';
    return v;
  }
  function changeText(h, r) {
    return Object.keys(h.changes || {}).map(function (k) {
      var was = h.before && Object.prototype.hasOwnProperty.call(h.before, k) ? fmtField(k, h.before[k], r) + ' → ' : '';
      return '<span class="hr-chip"><b>' + esc(FIELD_LABEL[k] || k) + '</b> <span data-no-i18n>' + esc(was + fmtField(k, h.changes[k], r)) + '</span></span>';
    }).join('');
  }
  var HST = { scheduled: ['#E0F2FE', '#075985', 'Scheduled'], applied: ['#ECFDF5', '#047857', 'Applied'], cancelled: ['#F1F5F9', '#475569', 'Cancelled'] };

  async function openHistory(id, name) {
    css();
    var r;
    try { r = await Api.get('/hr/people/' + id + '/history'); } catch (e) { return fail('Could not open the history', e); }
    var cur = r.current || {}, can = r.can || {};
    var box = el('<div class="hr-pc" style="margin-bottom:10px;"><h4>Now</h4>' + Object.keys(cur).map(function (k) { return kv(FIELD_LABEL[k] || k, esc(fmtField(k, cur[k], r))); }).join('') + '</div>'
      + ((r.changes || []).length ? r.changes.map(function (h) {
        var st = HST[h.status] || HST.applied;
        return '<div class="hrf-row"><div class="t"><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;"><b>' + esc(day(h.effective_on)) + '</b> ' + pill(st[0], st[1], st[2]) + '</div>'
          + '<div style="margin-top:4px;">' + changeText(h, r) + '</div><div class="hr-sub" data-no-i18n>' + esc([h.reason, h.by ? 'by ' + h.by : '', h.cancelled_by ? 'cancelled by ' + h.cancelled_by : ''].filter(Boolean).join(' · ')) + '</div></div>'
          + (h.status === 'scheduled' && can.change ? kebabBtn(h.id) : '') + '</div>';
      }).join('') : '<div class="hr-sub" style="padding:8px 2px;">No changes recorded yet. Changes made from now on are kept here with who made them and why.</div>')
      + (can.pay ? '' : '<div class="hr-sub" style="margin-top:8px;">Pay changes are shown only to people who can see pay.</div>'));
    var acts = [{ label: 'Close' }];
    if (can.change) acts.push({ label: 'Schedule a change', primary: true, handler: function () { later(function () { openChange(id, name, r); }); } });
    modal({ title: 'Pay and role history' + (name ? ': ' + name : ''), body: box, large: true, actions: acts });
    box.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        menu(b, [['Cancel this change', function () {
          Api.post('/hr/pay-changes/' + b.getAttribute('data-keb') + '/cancel', {}).then(function () { saved('Change cancelled.'); closeModal(); later(function () { openHistory(id, name); }); }, function (e) { fail('Could not cancel it', e); });
        }]]);
      });
    });
  }

  function openChange(id, name, r) {
    var cur = r.current || {};
    var box = el('<div class="hr-note">Fill in only what changes. A date today or earlier applies now; a later date applies by itself on that day.</div>'
      + '<div class="hr-g">' + field('Takes effect on', input('effective_on', today(), 'date'))
      + field('Job title', input('job_title', '', 'text', ' placeholder="' + esc(cur.job_title || '') + '"'))
      + field('Employment', select('employment_type', opts(r.employment_types, '', 'No change')))
      + field('Hours a week', input('hours_per_week', '', 'number', ' step="0.25" min="0" max="80" placeholder="' + esc(cur.hours_per_week == null ? '' : cur.hours_per_week) + '"'))
      + (r.can && r.can.pay ? field('Pay rate', input('pay_rate', '', 'number', ' step="0.01" min="0" placeholder="' + esc(cur.pay_rate == null ? '' : cur.pay_rate) + '"'))
        + field('Pay type', select('pay_type', opts(r.pay_types, '', 'No change'))) : '')
      + field('Why (kept with the change)', input('reason', ''), true) + '</div>' + ERR);
    modal({ title: 'Schedule a change' + (name ? ': ' + name : ''), body: box, actions: [{ label: 'Cancel', handler: function () { later(function () { openHistory(id, name); }); } }, { label: 'Save change', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = {}; ['effective_on', 'job_title', 'employment_type', 'hours_per_week', 'pay_rate', 'pay_type', 'reason'].forEach(function (k) { var v = val(box, k); if (v != null) o[k] = v; });
      return Api.post('/hr/people/' + id + '/changes', o).then(function (res) { saved(res && res.applied ? 'Change made.' : 'Change scheduled for ' + day(o.effective_on) + '.'); later(function () { openHistory(id, name); }); },
        function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── leaving ──────────────────────────────────────────────────────────────
  var LST = { open: ['#FFFBEB', '#B45309', 'Leaving'], completed: ['#F1F5F9', '#475569', 'Left'], undone: ['#ECFDF5', '#047857', 'Undone'] };

  async function openLeaving(id, done) {
    css();
    var r;
    try { r = await Api.get('/hr/people/' + id + '/leaving'); } catch (e) { return fail('Could not open the leaving pack', e); }
    var l = r.leaving || {}, can = r.can || {}, per = r.person || {}, pay = r.payout || {};
    var editable = can.edit && (!l.id || l.status === 'open');
    var dis = editable ? '' : ' disabled';
    var h = '<div class="hr-note">The leaving pack keeps the checklist, the leave payout and the Record of Employment figures together. '
      + '<b>KiddieTrac does not file anything with Service Canada</b>: the ROE sheet is for you to enter in ROE Web or your payroll provider.</div>';
    if (l.status) h += '<div style="margin:0 0 8px;">' + pill((LST[l.status] || LST.open)[0], (LST[l.status] || LST.open)[1], (LST[l.status] || LST.open)[2]) + (l.completed_at ? ' <span class="hr-sub">completed ' + esc(day(l.completed_at)) + '</span>' : '') + '</div>';
    h += '<div class="hr-g">' + field('Last day worked', input('last_day', l.last_day || '', 'date', dis)) + field('Notice given on', input('notice_on', l.notice_on || '', 'date', dis))
      + field('Reason (ROE code)', '<select class="hr-i" data-f="reason_code" style="width:100%;"' + dis + '>' + opts(Object.keys(r.reasons || {}).reduce(function (o, k) { o[k] = k + ' - ' + r.reasons[k]; return o; }, {}), l.reason_code || '', 'Choose…') + '</select>')
      + field('Note (optional)', input('reason_note', l.reason_note || '', 'text', dis)) + '</div>';
    h += '<div class="hrf-sec">Checklist</div>' + (r.checklist || []).map(function (c) {
      var extra = '';
      if (c.key === 'access') extra = c.done ? '<span class="hr-sub">done</span>' : (KT.openUserRecord ? '<button type="button" class="hr-link" data-ur>Off-board from their user record</button>' : '');
      if (c.key === 'reports') extra = c.count ? '<span class="hr-sub">' + c.count + ' report to them: choose their new manager when you complete</span>' : '<span class="hr-sub">nobody reports to them</span>';
      if (c.key === 'letter') extra = '<button type="button" class="hr-link" data-go="hr?tab=letters">HR letters</button>';
      return '<div class="hrf-ck"><input type="checkbox" class="kt-nosw" data-ck="' + esc(c.key) + '"' + (c.done ? ' checked' : '') + (c.auto || !editable ? ' disabled' : '') + '>'
        + '<div class="t">' + esc(c.label) + (c.by ? ' <span class="hr-sub" data-no-i18n>· ' + esc(c.by) + (c.at ? ', ' + esc(day(c.at)) : '') + '</span>' : '') + (c.auto ? ' <span class="hr-sub">(ticks itself)</span>' : '') + '</div>' + extra + '</div>';
    }).join('');
    h += '<div class="hrf-sec">Leave payout</div>' + ((pay.lines || []).length ? '<div class="kt-card" style="padding:4px 10px;">' + pay.lines.map(function (p) {
        return '<div class="hrf-row"><div class="t"><b data-no-i18n>' + esc(p.name) + '</b><div class="hr-sub">' + esc(p.balance + ' ' + p.unit + (p.unit === 'days' ? ' (' + p.hours + ' h)' : '')) + '</div></div>'
          + (p.included === false ? '<span class="hr-sub" style="white-space:nowrap;">Not paid out</span>' : can.pay ? '<input class="hr-i" type="number" step="0.01" min="0" data-pv="' + p.leave_type_id + '" value="' + esc(p.value == null ? '' : p.value) + '" style="width:110px;text-align:right;"' + dis + '>' : '') + '</div>';
      }).join('') + (can.pay ? '<div class="hrf-row"><div class="t"><b>Total</b></div><b data-pt data-no-i18n>' + esc(money(pay.total)) + '</b></div>' : '') + '</div>'
      + (can.pay ? '<div class="hr-sub" style="margin-top:4px;">Worked out at ' + esc(money(pay.hourly_rate)) + ' an hour' + (pay.pay_type === 'salary' ? ' (annual salary ÷ 260 days ÷ ' + pay.hours_per_day + ' h)' : '') + '. Change any amount; lump-sum grants are not pro-rated here. Only leave types marked "Paid out when someone leaves" (HR > Leave types; by default vacation and the overtime bank) are valued.</div>' : '')
      : '<div class="hr-sub">No paid leave balance to pay out.</div>');
    if ((r.past || []).length) h += '<div class="hrf-sec">Earlier</div>' + r.past.map(function (p) {
      return '<div class="hr-sub" data-no-i18n>' + esc((p.employment_start ? day(p.employment_start) + ' – ' : '') + day(p.last_day) + (p.rehired_on ? ' · returned ' + day(p.rehired_on) : '') + (p.status === 'undone' ? ' · undone: ' + (p.undo_reason || '') : '')) + '</div>';
    }).join('');
    h += ERR;
    var box = el(h);
    var acts = [{ label: 'Close' }, { label: 'ROE sheet', handler: function () { later(function () { openRoe(id, per.name); }); } }];
    if (can.offboard) acts.push({ label: 'Download all their data', handler: function () { saveBlob('/hr/people/' + id + '/export', 'HR-data-' + (per.name || 'person').replace(/[^A-Za-z0-9]+/g, '-') + '.zip'); return false; } });
    if (can.edit && (l.status === 'open' || l.status === 'completed')) acts.push({ label: 'Undo leaving', handler: function () { later(function () { openUndo(l, per, done); }); } });
    if (can.edit && per.is_leaver && l.status !== 'open') acts.push({ label: 'Rehire', handler: function () { later(function () { openRehire(id, per, done); }); } });
    if (editable) {
      acts.push({ label: 'Save', busyLabel: 'Saving…', handler: function () { return saveLeaving(id, box).then(function (ok) { if (ok) { saved('Leaving saved.'); if (done) done(); } return ok ? undefined : false; }); } });
      acts.push({ label: l.id ? 'Complete leaving' : 'Save and complete', primary: true, handler: function () {
        return saveLeaving(id, box).then(function (lid) { if (!lid) return false; later(function () { openComplete(lid, r, per, done); }); });
      } });
    }
    modal({ title: 'Leaving: ' + (per.name || ''), body: box, large: true, actions: acts });
    var ur = box.querySelector('[data-ur]'); if (ur) ur.addEventListener('click', function () { closeModal(); try { KT.openUserRecord(id); } catch (e) {} });
    box.querySelectorAll('[data-go]').forEach(function (g) { g.addEventListener('click', function () { closeModal(); location.hash = '#' + g.getAttribute('data-go'); }); });
    box.querySelectorAll('[data-pv]').forEach(function (i) { i.addEventListener('input', function () {
      var t = 0; box.querySelectorAll('[data-pv]').forEach(function (x) { t += +x.value || 0; }); var pt = box.querySelector('[data-pt]'); if (pt) pt.textContent = money(t);
    }); });
  }

  function saveLeaving(id, box) {
    var o = { last_day: val(box, 'last_day'), notice_on: val(box, 'notice_on'), reason_code: val(box, 'reason_code'), reason_note: val(box, 'reason_note'), checklist: {}, payout: [] };
    box.querySelectorAll('[data-ck]').forEach(function (c) { if (!c.disabled) o.checklist[c.getAttribute('data-ck')] = { done: c.checked }; });
    box.querySelectorAll('[data-pv]').forEach(function (p) { o.payout.push({ leave_type_id: +p.getAttribute('data-pv'), value: p.value === '' ? null : +p.value }); });
    if (!o.payout.length) delete o.payout;
    return Api.post('/hr/people/' + id + '/leaving', o).then(function (r) { return r.id; }, function (e) { msg(box, errMsg(e)); return false; });
  }

  function openComplete(lid, r, per, done) {
    var box = el('<div class="hr-note">Their HR file becomes Terminated on the last day you entered. This does not close their account: off-board them from their user record (rooms, shifts and sign-in).</div>'
      + ((r.payout && (r.payout.lines || []).length) ? check('pay_out', true, 'Book the leave payout: the paid-out balances go to zero') : '')
      + ((r.reports || []).length ? '<div style="margin-top:10px;">' + field('New manager for ' + r.reports.map(function (x) { return x.name; }).join(', '), select('new_manager_id', opts((r.managers || []).reduce(function (o, m) { o[m.id] = m.name; return o; }, {}), '', 'Nobody for now')), true) + '</div>' : '')
      + ERR);
    modal({ title: 'Complete leaving: ' + (per.name || ''), body: box, actions: [{ label: 'Back', handler: function () { later(function () { openLeaving(per.id, done); }); } }, { label: 'Complete', ctx: 'hr-leaving', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.post('/hr/leavings/' + lid + '/complete', { pay_out: !!val(box, 'pay_out'), new_manager_id: val(box, 'new_manager_id') ? +val(box, 'new_manager_id') : null })
        .then(function () { saved('Leaving completed.'); if (done) done(); later(function () { openLeaving(per.id, done); }); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openUndo(l, per, done) {
    var box = el('<div class="hr-note">For a mistake: their file goes back to Active, any leave payout is returned to their balances, and a closed account is reopened. The leaving stays on record as undone.</div>'
      + field('Why it is being undone', input('reason', ''), true) + ERR);
    modal({ title: 'Undo leaving: ' + (per.name || ''), body: box, actions: [{ label: 'Cancel' }, { label: 'Undo leaving', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.post('/hr/leavings/' + l.id + '/undo', { reason: val(box, 'reason') }).then(function (res) {
        saved(res && res.account_reopened ? 'Undone. Their account is open again.' : 'Leaving undone.'); if (done) done();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  function openRehire(id, per, done) {
    var box = el('<div class="hr-note">A returning employee starts a new employment: their earlier time with you stays on record, and a closed account is reopened.</div>'
      + '<div class="hr-g">' + field('New start date', input('start_date', today(), 'date')) + field('Job title (optional)', input('job_title', '')) + '</div>'
      + '<div style="margin-top:10px;">' + check('zero_balances', true, 'Start their leave balances at zero') + '</div>' + ERR);
    modal({ title: 'Rehire ' + (per.name || ''), body: box, actions: [{ label: 'Cancel' }, { label: 'Rehire', primary: true, busyLabel: 'Saving…', handler: function () {
      return Api.post('/hr/people/' + id + '/rehire', { start_date: val(box, 'start_date'), job_title: val(box, 'job_title'), zero_balances: !!val(box, 'zero_balances') })
        .then(function () { saved('Welcome back. Their new employment has started.'); if (done) done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ── ROE preparation sheet ────────────────────────────────────────────────
  function roeHtml(r, forPrint) {
    var rows = (r.periods || []).map(function (p) {
      return '<tr><td>' + esc(day(p.period_start)) + '</td><td>' + esc(day(p.period_end)) + '</td><td style="text-align:right;">' + esc(p.hours) + '</td>'
        + (r.total_gross != null ? '<td style="text-align:right;">' + esc(p.gross == null ? '' : money(p.gross)) + '</td>' : '') + '</tr>';
    }).join('');
    var t = forPrint ? 'style="width:100%;border-collapse:collapse;font-size:12px;" border="1" cellpadding="4"' : '';
    return '<div class="hr-note" style="background:#FFFBEB;border-color:#FCD34D;color:#92400E;">' + esc(r.notice) + '</div>'
      + '<div class="hr-pc">' + kv('Employee', esc(r.employee.name)) + kv('Employer', esc(r.employer)) + kv('First day worked', esc(day(r.first_day_worked)))
      + kv('Last day for which paid', esc(day(r.last_day_paid))) + kv('Final pay period ending', esc(day(r.final_period_end))) + kv('Pay period type', esc(r.pay_frequency))
      + kv('Reason for issuing (code)', esc(r.reason_code ? r.reason_code + ' - ' + r.reason_label : '')) + kv('Total insurable hours', esc(r.total_hours))
      + (r.total_gross != null ? kv('Total insurable earnings', esc(money(r.total_gross))) : '') + (r.vacation_paid_out != null ? kv('Vacation pay on leaving', esc(money(r.vacation_paid_out))) : '')
      + kv('Figures from', esc(r.source === 'payslips' ? 'Payslips in KiddieTrac' : 'Timesheets (no payslips on file)')) + '</div>'
      + '<div class="kt-card" style="margin-top:10px;overflow:auto;"><table ' + t + ' data-kt-no-kebab><thead><tr><th>From</th><th>To</th><th style="text-align:right;">Hours</th>' + (r.total_gross != null ? '<th style="text-align:right;">Earnings</th>' : '') + '</tr></thead><tbody>'
      + (rows || '<tr><td colspan="4" class="hr-sub">No hours on file.</td></tr>') + '</tbody></table></div>';
  }
  async function openRoe(id, name) {
    css();
    var r;
    try { r = await Api.get('/hr/people/' + id + '/roe'); } catch (e) { return fail('Could not prepare the ROE sheet', e); }
    var box = el(roeHtml(r));
    modal({ title: 'ROE preparation sheet: ' + (name || r.employee.name), body: box, large: true, actions: [{ label: 'Close' },
      { label: 'Download CSV', handler: function () { saveBlob('/hr/people/' + id + '/roe?format=csv', 'ROE-prep-' + (r.employee.name || 'person').replace(/[^A-Za-z0-9]+/g, '-') + '.csv'); return false; } },
      { label: 'Print', primary: true, handler: function () {
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>ROE preparation sheet</title><style>body{font-family:Arial,sans-serif;font-size:13px;color:#111;margin:24px;}'
          + '.hr-kv{display:flex;justify-content:space-between;border-bottom:1px solid #ddd;padding:4px 0;}.hr-note{border:1px solid #d97706;padding:8px;margin:0 0 12px;}.hr-sub{color:#555;}</style></head><body>'
          + '<h2 style="margin:0 0 10px;">ROE preparation sheet</h2>' + roeHtml(r, true) + '</body></html>';
        if (KT.printDoc) KT.printDoc({ html: html }, { title: 'ROE preparation sheet' }); return false;
      } }] });
  }

  // ── the profile cards ────────────────────────────────────────────────────
  async function profile(host, id, p) {
    css();
    var per = (p && p.person) || {}, name = per.name || '';
    var admin = !!(p && p.sin);
    var file = (p && p.file) || {};
    host.innerHTML = '<div class="hr-pf">'
      + '<div class="hr-pc"><h4>Documents</h4><div data-dc><span class="hr-sub">Loading…</span></div><div class="hr-bar" style="margin:8px 0 0;"><button type="button" class="hr-b" data-act="docs" data-kt-iconized="1">Documents</button></div></div>'
      + '<div class="hr-pc"><h4>Pay and role history</h4><div data-hc><span class="hr-sub">Loading…</span></div><div class="hr-bar" style="margin:8px 0 0;"><button type="button" class="hr-b" data-act="hist" data-kt-iconized="1">History</button></div></div>'
      + '<div class="hr-pc"><h4>Leaving</h4>' + (file.status === 'terminated' ? kv('Left', esc(day(file.end_date))) : file.end_date ? kv('Last day', esc(day(file.end_date))) : '<span class="hr-sub">Not leaving.</span>')
      + '<div class="hr-bar" style="margin:8px 0 0;"><button type="button" class="hr-b" data-act="leave" data-kt-iconized="1">' + (file.end_date || file.status === 'terminated' ? 'Leaving pack' : (admin ? 'Start leaving' : 'Leaving pack')) + '</button>'
      + '<button type="button" class="hr-b" data-act="roe" data-kt-iconized="1">ROE sheet</button>'
      + (admin ? '<button type="button" class="hr-b" data-act="export" data-kt-iconized="1">Download all their data</button>' : '') + '</div></div></div>';
    host.querySelectorAll('[data-act]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-act');
        if (a === 'export') return saveBlob('/hr/people/' + id + '/export', 'HR-data-' + name.replace(/[^A-Za-z0-9]+/g, '-') + '.zip');
        closeModal();
        later(function () { if (a === 'docs') openPersonDocs(id, name); else if (a === 'hist') openHistory(id, name); else if (a === 'leave') openLeaving(id); else if (a === 'roe') openRoe(id, name); });
      });
    });
    Api.get('/hr/people/' + id + '/documents').then(function (r) {
      var c = host.querySelector('[data-dc]'); if (!c) return;
      var ds = r.documents || [];
      var exp = ds.filter(function (d) { return d.expires_on && d.expires_on <= addDays(today(), 30); }).length;
      c.innerHTML = kv('On file', esc(ds.length)) + (exp ? kv('Expired or expiring', pill('#FFFBEB', '#B45309', exp)) : '') + ds.slice(0, 3).map(function (d) { return '<div class="hr-sub" data-no-i18n>' + esc(d.title) + '</div>'; }).join('');
    }, function () { var c = host.querySelector('[data-dc]'); if (c) c.closest('.hr-pc').remove(); });
    Api.get('/hr/people/' + id + '/history').then(function (r) {
      var c = host.querySelector('[data-hc]'); if (!c) return;
      var ch = r.changes || [], next = ch.filter(function (h) { return h.status === 'scheduled'; });
      c.innerHTML = (next.length ? kv('Coming up', esc(day(next[next.length - 1].effective_on))) : '') + (ch.filter(function (h) { return h.status === 'applied'; })[0]
        ? kv('Last change', esc(day(ch.filter(function (h) { return h.status === 'applied'; })[0].effective_on))) : '<span class="hr-sub">No changes recorded yet.</span>')
        + (r.current && r.current.hours_per_week != null ? kv('Hours a week', esc(r.current.hours_per_week)) : '');
    }, function () { var c = host.querySelector('[data-hc]'); if (c) c.closest('.hr-pc').remove(); });
  }

  // ── HR > Documents (company folders) ─────────────────────────────────────
  async function drawDocs(host) {
    css();
    var r;
    try { r = await Api.get('/hr/doc-folders'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var can = r.can || {}, fs = r.folders || [];
    var reload = function () { if (host.isConnected) drawDocs(host); };
    var h = '<div class="hr-note">Company documents: the handbook, policies and forms everyone needs. A new folder is seen by agency admins only until you choose who else sees it. '
      + 'Staff find them in My HR, and you can see who has opened or accepted each one.</div>';
    if (can.manage) h += '<div class="hr-bar"><button type="button" class="hr-b p" data-fact="new" data-kt-iconized="1">New folder</button></div>';
    h += fs.length ? fs.map(function (f) {
      return '<div class="kt-card" style="margin-bottom:12px;"><div style="display:flex;align-items:center;gap:8px;padding:10px 12px;flex-wrap:wrap;"><b data-no-i18n style="font-size:14px;">📁 ' + esc(f.name) + '</b>'
        + pill('#F1F5F9', '#475569', FVIS[f.visibility] || f.visibility) + (f.audience != null ? '<span class="hr-sub">' + f.audience + ' can see it</span>' : '')
        + (can.manage ? '<span style="margin-left:auto;display:flex;gap:6px;"><button type="button" class="hr-b" data-fact="up" data-id="' + f.id + '" data-kt-iconized="1">Upload</button><button type="button" class="hr-b" data-fact="edit" data-id="' + f.id + '" data-kt-iconized="1">Folder settings</button></span>' : '') + '</div>'
        + ((f.documents || []).length ? '<table><thead><tr><th>Document</th><th>Expires</th>' + (can.manage ? '<th>Opened</th><th>Accepted</th>' : '<th>You</th>') + '<th></th></tr></thead><tbody>' + f.documents.map(function (d) {
          return '<tr><td><b data-no-i18n>' + esc(d.title) + '</b><div class="hr-sub">' + esc(d.type_label) + (d.requires_accept ? ' · asks to accept' : '') + '</div></td><td>' + (expiryPill(d) || '<span class="hr-sub">—</span>') + '</td>'
            + (can.manage ? '<td>' + esc(d.seen + ' of ' + f.audience) + '</td><td>' + (d.requires_accept ? esc(d.accepted + ' of ' + f.audience) : '<span class="hr-sub">—</span>') + '</td>'
              : '<td>' + (d.accepted_at ? pill('#ECFDF5', '#047857', 'Accepted') : d.read_at ? pill('#F1F5F9', '#475569', 'Read') : pill('#E0F2FE', '#075985', 'To read')) + '</td>')
            + '<td style="text-align:center;white-space:nowrap;">' + (d.previewable ? '<button type="button" data-dact="prev" data-id="' + d.id + '">Preview</button>' : '')
            + '<button type="button" data-dact="dl" data-id="' + d.id + '">Download</button>'
            + (can.manage ? '<button type="button" data-dact="rc" data-id="' + d.id + '">Who has read it</button><button type="button" data-dact="edit" data-id="' + d.id + '">Edit details</button><button type="button" data-dact="rm" data-id="' + d.id + '">Remove</button>'
              : (d.requires_accept && !d.accepted_at ? '<button type="button" data-dact="acc" data-id="' + d.id + '">Read and accept</button>' : '')) + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="hr-sub" style="padding:0 12px 12px;">No documents in this folder yet.</div>') + '</div>';
    }).join('') : '<div class="kt-card"><div class="hr-sub" style="padding:16px;">' + (can.manage ? 'No folders yet. Start with one for your staff handbook.' : 'No company documents are shared with you yet.') + '</div></div>';
    host.innerHTML = h;
    var docOf = function (id) { var out = null; fs.forEach(function (f) { (f.documents || []).forEach(function (d) { if (d.id === +id) out = d; }); }); return out; };
    host.querySelectorAll('[data-fact]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-fact'), f = fs.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0];
        if (a === 'new') openFolder(null, r.staff, reload);
        else if (a === 'edit' && f) openFolder(f, r.staff, reload);
        else if (a === 'up' && f) openUpload(null, null, r.types, { id: f.id, name: f.name, reload: reload });
      });
    });
    host.querySelectorAll('[data-dact]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-dact'), d = docOf(b.getAttribute('data-id')); if (!d) return;
        if (a === 'prev') openDoc(d); else if (a === 'dl') openDoc(d, true);
        else if (a === 'edit') openEditDoc(d, r.types, reload);
        else if (a === 'rm') removeDoc(d, reload);
        else if (a === 'rc') openReceipts(d);
        else if (a === 'acc') acceptDoc(d, reload);
      });
    });
    try { if (KT.enhanceTables) KT.enhanceTables(); } catch (e) {}
  }

  function openFolder(f, staff, done) {
    var chosen = (f && f.people_ids) || [];
    var box = el('<div class="hr-g">' + field('Folder name', input('name', f ? f.name : ''), true) + field('Who can see it', select('visibility', opts(FVIS, f ? f.visibility : 'admins')), true) + '</div>'
      + '<div data-pp style="margin-top:10px;"><label class="hr-l">Chosen people</label><div class="hrf-pick">' + (staff || []).map(function (s) {
        return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;padding:2px 0;"><input type="checkbox" class="kt-nosw" data-pid="' + s.id + '"' + (chosen.indexOf(s.id) >= 0 ? ' checked' : '') + '> <span data-no-i18n>' + esc(s.name) + '</span></label>';
      }).join('') + '</div></div>' + (f ? '<div style="margin-top:10px;"><button type="button" class="hr-link" data-arch>Remove this folder (only when it is empty)</button></div>' : '') + ERR);
    var sync = function () { box.querySelector('[data-pp]').style.display = val(box, 'visibility') === 'people' ? '' : 'none'; };
    box.querySelector('[data-f="visibility"]').addEventListener('change', sync); sync();
    var arch = box.querySelector('[data-arch]');
    if (arch) arch.addEventListener('click', function () { Api.post('/hr/doc-folders/' + f.id + '/archive', {}).then(function () { saved('Folder removed.'); closeModal(); done(); }, function (e) { msg(box, errMsg(e)); }); });
    modal({ title: f ? 'Folder settings' : 'New folder', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { name: val(box, 'name'), visibility: val(box, 'visibility'), people_ids: [] };
      box.querySelectorAll('[data-pid]').forEach(function (c) { if (c.checked) o.people_ids.push(+c.getAttribute('data-pid')); });
      if (!o.name) { msg(box, 'Give the folder a name.'); return false; }
      return (f ? Api.put('/hr/doc-folders/' + f.id, o) : Api.post('/hr/doc-folders', o)).then(function () { saved('Folder saved.'); done(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  async function openReceipts(d) {
    css();
    var r;
    try { r = await Api.get('/hr/documents/' + d.id + '/receipts'); } catch (e) { return fail('Could not load who has read it', e); }
    var ppl = r.people || [];
    var notYet = ppl.filter(function (p) { return d.requires_accept ? !p.accepted_at : !p.read_at; });
    var box = el('<div class="hr-stats"><div class="hr-stat"><b>' + ppl.filter(function (p) { return p.read_at; }).length + '</b><span>Opened</span></div>'
      + (d.requires_accept ? '<div class="hr-stat"><b>' + ppl.filter(function (p) { return p.accepted_at; }).length + '</b><span>Accepted</span></div>' : '')
      + '<div class="hr-stat"><b>' + notYet.length + '</b><span>Not yet</span></div></div>'
      + ppl.map(function (p) {
        return '<div class="hrf-row"><div class="t"><b data-no-i18n>' + esc(p.name) + '</b></div>' + (p.accepted_at ? pill('#ECFDF5', '#047857', 'Accepted ' + day(p.accepted_at))
          : p.read_at ? pill('#F1F5F9', '#475569', 'Opened ' + day(p.read_at)) : pill('#FFFBEB', '#B45309', 'Not yet')) + '</div>';
      }).join(''));
    modal({ title: 'Who has read: ' + d.title, body: box, actions: [{ label: 'Close' }] });
  }

  async function acceptDoc(d, done) {
    var ok = KT.confirm ? await KT.confirm({ title: 'Accept ' + d.title + '?', description: 'You confirm you have read it and accept it. Your agency sees the date you accepted.', okLabel: 'I have read and accept it' }) : true;
    if (!ok) return;
    try { await Api.post('/hr/documents/' + d.id + '/accept', {}); saved('Thank you. Accepted.'); done(); } catch (e) { fail('Could not record that', e); }
  }

  // ── HR > Leavers ─────────────────────────────────────────────────────────
  async function drawLeavers(host) {
    css();
    var r;
    try { r = await Api.get('/hr/leavers'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var rows = r.leavers || [], can = r.can || {};
    var reload = function () { if (host.isConnected) drawLeavers(host); };
    host.innerHTML = '<div class="hr-note">People who have left (their HR file says Terminated or their account was closed). They are off every approver and manager list. '
      + 'Their records are kept: open the leaving pack for the ROE sheet, or rehire a returning employee.</div>'
      + '<div class="kt-card">' + (rows.length ? '<table><thead><tr><th>Name</th><th>Job title</th><th>Started</th><th>Last day</th><th>Reason</th><th>Account</th><th></th></tr></thead><tbody>' + rows.map(function (p) {
        return '<tr><td><b data-no-i18n>' + esc(p.name) + '</b></td><td data-no-i18n>' + esc(p.job_title || '') + '</td><td style="white-space:nowrap;">' + esc(day(p.start_date)) + '</td>'
          + '<td style="white-space:nowrap;">' + esc(day(p.last_day)) + '</td><td data-no-i18n>' + esc(p.reason || '') + '</td><td>' + (p.account_closed ? pill('#F1F5F9', '#475569', 'Closed') : pill('#FFFBEB', '#B45309', 'Still open')) + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-lact="pack" data-id="' + p.id + '">Leaving pack</button><button type="button" data-lact="roe" data-id="' + p.id + '">ROE sheet</button>'
          + '<button type="button" data-lact="docs" data-id="' + p.id + '">Documents</button>'
          + (can.export ? '<button type="button" data-lact="export" data-id="' + p.id + '">Download all their data</button>' : '')
          + (!p.account_closed && KT.openUserRecord ? '<button type="button" data-lact="user" data-id="' + p.id + '">Off-board from user record</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div class="hr-sub" style="padding:16px;">Nobody has left yet.</div>') + '</div>';
    host.querySelectorAll('[data-lact]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-id'), a = b.getAttribute('data-lact'), p = rows.filter(function (x) { return x.id === id; })[0] || {};
        if (a === 'pack') openLeaving(id, reload); else if (a === 'roe') openRoe(id, p.name); else if (a === 'docs') openPersonDocs(id, p.name);
        else if (a === 'export') saveBlob('/hr/people/' + id + '/export', 'HR-data-' + String(p.name || 'person').replace(/[^A-Za-z0-9]+/g, '-') + '.zip');
        else if (a === 'user') { try { KT.openUserRecord(id); } catch (e) {} }
      });
    });
    try { if (KT.enhanceTables) KT.enhanceTables(); } catch (e) {}
  }

  // ── My HR > My documents ─────────────────────────────────────────────────
  async function mine(host) {
    css();
    var wrap = document.createElement('div'); wrap.setAttribute('data-hrf-mine', '1'); host.appendChild(wrap);
    var r;
    try { r = await Api.get('/hr/me/documents'); } catch (e) { wrap.remove(); return; }
    if (!wrap.isConnected) return;
    var docs = r.documents || [], comp = [];
    (r.company || []).forEach(function (f) { (f.documents || []).forEach(function (d) { d.folder = f.name; comp.push(d); }); });
    if (!docs.length && !comp.length) { wrap.remove(); return; }
    var reload = function () { if (wrap.isConnected) { wrap.remove(); mine(host); } };
    wrap.innerHTML = '<div class="hrt-sec">My documents' + (r.to_do ? ' <span class="hr-sub">(' + r.to_do + ' to read)</span>' : '') + '</div><div class="kt-card" style="padding:4px 12px;">'
      + comp.map(function (d) {
        var st = d.accepted_at ? pill('#ECFDF5', '#047857', 'Accepted') : d.read_at && !d.requires_accept ? pill('#F1F5F9', '#475569', 'Read') : pill('#E0F2FE', '#075985', d.requires_accept ? 'Read and accept' : 'To read');
        return '<div class="hrf-row"><div class="t"><b data-no-i18n>' + esc(d.title) + '</b><div class="hr-sub" data-no-i18n>📁 ' + esc(d.folder) + '</div></div>' + st + kebabBtn('c' + d.id) + '</div>';
      }).join('')
      + docs.map(function (d) { return docLine(d); }).join('') + '</div>';
    wrap.querySelectorAll('[data-keb]').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-keb'), isC = k.charAt(0) === 'c', id = +(isC ? k.slice(1) : k);
        var d = (isC ? comp : docs).filter(function (x) { return x.id === id; })[0]; if (!d) return;
        menu(b, [d.previewable ? ['Preview', function () { openDoc(d).then(function () { if (isC && !d.read_at) setTimeout(reload, 800); }); }] : null, ['Download', function () { openDoc(d, true); }],
          isC && d.requires_accept && !d.accepted_at ? ['I have read and accept it', function () { acceptDoc(d, reload); }] : null]);
      });
    });
  }

  window.KTHrFile = {
    tabs: [['docs', 'Documents'], ['leavers', 'Leavers']],
    has: function (tab) { return tab === 'docs' || tab === 'leavers'; },
    draw: function (tab, host) { if (tab === 'docs') return drawDocs(host); if (tab === 'leavers') return drawLeavers(host); },
    profile: profile,
    mine: mine,
    openDoc: openDoc
  };
})(window);
