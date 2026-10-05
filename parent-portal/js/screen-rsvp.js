/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Engagement → Event RSVPs  (agency admin)

   Templates hold the questions, the invite email, an image and the address that hears
   about sign-ups. An event is made from a template and keeps its own copy of the
   questions, so either can be edited at any time without disturbing past answers.
   Invite people by pasting or uploading a CSV, or by picking families and staff; each
   gets a personal RSVP link and an unsubscribe link. Reports show who was sent, who
   signed up and who opted out, with times in the agency's timezone, and export to CSV.

   Backend: RsvpController (/admin/rsvp/*). Public page: /rsvp.html.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var Api;
  var state = { tab: 'events', eventId: null, reportEvent: null, view: 'all', templates: [], isPlatform: false, agencies: [] };
  var TYPES = [['text', 'Short answer'], ['textarea', 'Paragraph'], ['email', 'Email'], ['tel', 'Phone'], ['select', 'Dropdown'], ['checkbox', 'Checkbox']];
  var VIEWS = [['all', 'Everyone'], ['sent', 'Sent'], ['registered', 'Signed up'], ['opted_out', 'Opted out'], ['no_response', 'No response'], ['not_sent', 'Not sent']];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  function isEmail(v) { return /^[^@\s,;<>]+@[^@\s,;<>]+\.[^@\s,;<>]+$/.test(String(v || '').trim()); }
  function statusPill(s) {
    var c = { open: ['#DCFCE7', '#166534', 'Open'], draft: ['#F1F5F9', '#475569', 'Draft'], closed: ['#FEE2E2', '#991B1B', 'Closed'] }[s] || ['#F1F5F9', '#475569', s];
    return '<span class="rv-pill" style="background:' + c[0] + ';color:' + c[1] + '">' + esc(c[2]) + '</span>';
  }
  function sendPill(s, label) {
    var c = { sent: ['#DCFCE7', '#166534'], queued: ['#E0F2FE', '#075985'], pending: ['#F1F5F9', '#475569'], suppressed: ['#FEF3C7', '#92400E'],
      failed: ['#FEE2E2', '#991B1B'], skipped: ['#F1F5F9', '#64748B'], not_invited: ['#EDE9FE', '#5B21B6'] }[s] || ['#F1F5F9', '#475569'];
    return '<span class="rv-pill" style="background:' + c[0] + ';color:' + c[1] + '">' + esc(label || s) + '</span>';
  }

  async function download(path, filename) {
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var headers = { Authorization: 'Bearer ' + tok };
    var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) { headers['X-Active-Agency-Id'] = aid; }
    var base = (KT.API_BASE || 'https://api.kiddietrac.com/api/v1');
    var r = await fetch(base + path, { headers: headers });
    if (!r.ok) { var msg = 'Download failed (' + r.status + ')'; try { var j = await r.json(); if (j.message) { msg = j.message; } } catch (e) {} toast(msg, 'error'); return; }
    var blob = await r.blob(), a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { try { URL.revokeObjectURL(a.href); } catch (e) {} }, 30000);
  }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); toast('Link copied', 'success'); }
    catch (e) { await KT.prompt({ title: 'Copy this link', description: 'Select the link and copy it.', value: text, okLabel: 'Done' }); }
  }

  var CSS = '<style id="rv-css">'
    + '.rv-tabs{display:flex;gap:6px;margin:0 0 14px;flex-wrap:wrap}.rv-tabs button{border:1px solid #CBD5E1;background:#fff;border-radius:9px;padding:5px 14px;height:32px;font-weight:700;font-size:13px;cursor:pointer;color:#334155}'
    + '.rv-tabs button.on{background:var(--kt-brand,#1F6080);border-color:var(--kt-brand,#1F6080);color:#fff}'
    + '.rv-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}.rv-bar select,.rv-bar input{padding:4px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;height:32px}'
    + '.rv-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700;white-space:nowrap}'
    + '.rv-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:12px 0}'
    + '.rv-stat{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:9px 12px}.rv-stat b{display:block;font-size:19px;color:#0F172A}.rv-stat span{font-size:11.5px;color:#64748B;font-weight:700}'
    + '.rv-head{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap}'
    + '.rv-head img{width:180px;max-width:100%;border-radius:10px;border:1px solid #E2E8F0}.rv-head h3{margin:0 0 4px;font-size:18px}.rv-sub{font-size:13px;color:#475569;white-space:pre-line;margin:2px 0}'
    + '.rv-link{display:flex;gap:6px;align-items:center;margin-top:8px;flex-wrap:wrap}.rv-link code{background:#F1F5F9;border-radius:6px;padding:4px 8px;font-size:12px;word-break:break-all}'
    + '.rv-chips{display:flex;gap:6px;flex-wrap:wrap}.rv-chips button{border:1px solid #CBD5E1;background:#fff;border-radius:16px;padding:3px 12px;height:28px;font-size:12.5px;font-weight:700;cursor:pointer;color:#334155}.rv-chips button.on{background:#0F172A;color:#fff;border-color:#0F172A}'
    + '.rv-ov{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:1000000;padding:16px}'
    + '.rv-dlg{background:#fff;border-radius:16px;width:100%;max-width:720px;max-height:92vh;overflow:auto;padding:18px 20px;box-shadow:0 24px 60px rgba(0,0,0,.25)}'
    + '.rv-dlg h3{margin:0 0 12px;font-size:17px}.rv-f{display:grid;grid-template-columns:1fr 1fr;gap:10px}.rv-f .full{grid-column:1/-1}'
    + '.rv-f label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px}'
    + '.rv-f input:not([type=checkbox]),.rv-f select,.rv-f textarea,.rv-q input:not([type=checkbox]),.rv-q select{width:100%;box-sizing:border-box;padding:5px 9px;min-height:32px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit}'
    + '.rv-f textarea{min-height:90px}.rv-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;flex-wrap:wrap}'
    + '.rv-hint{font-size:12px;color:#64748B;margin-top:3px;line-height:1.45}'
    + '.rv-q{border:1px solid #E2E8F0;border-radius:10px;padding:8px;margin-bottom:6px;display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto auto;gap:6px;align-items:center;background:#FBFDFF}'
    + '.rv-q .more{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:6px}.rv-q .req{display:flex;gap:4px;align-items:center;font-size:12px;font-weight:700;white-space:nowrap}'
    + '.rv-q .mv{display:flex;gap:2px}.rv-q .mv button{width:28px;height:28px;border:1px solid #CBD5E1;background:#fff;border-radius:7px;cursor:pointer;font-size:12px;padding:0}'
    + '.rv-q.locked{background:#F8FAFC}'
    /* One compact size for every toolbar button (the icon sweep and the add-button sweep otherwise leave 31px and 39px side by side). */
    + '#rv-body .rv-bar .kt-btn,#rv-body .rv-link .kt-btn{height:31px;min-height:0;min-width:31px;padding:0 12px !important;font-size:12.5px !important;font-weight:700;line-height:1;border:1px solid #CBD5E1;background:#fff;color:#334155;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;gap:4px;box-shadow:none;text-decoration:none}'
    + '#rv-body .rv-bar .kt-btn.kt-hero-iconbtn,#rv-body .rv-link .kt-btn.kt-hero-iconbtn{width:31px;padding:0}'
    + '#rv-body .rv-bar .kt-btn-primary{background:var(--kt-brand,#1F6080);border-color:var(--kt-brand,#1F6080);color:#fff}'
    + '#rv-body .rv-bar .kt-btn:disabled{opacity:.5;cursor:default}'
    + '.rv-pick{max-height:300px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px}.rv-pick label{display:flex;gap:8px;align-items:center;padding:6px 10px;border-bottom:1px solid #F1F5F9;font-size:13px;cursor:pointer}.rv-pick label small{color:#64748B}'
        + '@media (max-width:640px){.rv-f{grid-template-columns:minmax(0,1fr)}.rv-q{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.rv-q .more{grid-template-columns:minmax(0,1fr)}.rv-head img{width:100%}}'
    + '</style>';

  /* A dialog of our own (wide forms); a drag that ends on the backdrop does not close it. */
  function dialog(title, bodyHtml, onSave, saveLabel, opts) {
    opts = opts || {};
    var ov = document.createElement('div');
    ov.className = 'rv-ov';
    ov.innerHTML = '<div class="rv-dlg" role="dialog" aria-modal="true" style="' + (opts.width ? 'max-width:' + opts.width + 'px' : '') + '"><h3>' + esc(title) + '</h3>' + bodyHtml
      + '<div class="rv-actions"><button type="button" class="kt-btn" data-x>Cancel</button>' + (onSave ? '<button type="button" class="kt-btn kt-btn-primary" data-ok>' + esc(saveLabel || 'Save') + '</button>' : '') + '</div></div>';
    document.body.appendChild(ov);
    var down = null;
    ov.addEventListener('mousedown', function (e) { down = e.target; });
    ov.addEventListener('click', function (e) { if (e.target === ov && down === ov) { ov.remove(); } });
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    var ok = ov.querySelector('[data-ok]');
    if (ok) {
      ok.onclick = async function () {
        ok.disabled = true;
        try { var keep = await onSave(ov.querySelector('.rv-dlg')); if (keep !== false) { ov.remove(); } } catch (e) { toast((e && e.message) || 'Could not save', 'error'); }
        ok.disabled = false;
      };
    }
    return ov;
  }
  function field(k, label, v, o) {
    o = o || {};
    var cls = o.full ? ' class="full"' : '';
    var hint = o.hint ? '<div class="rv-hint">' + o.hint + '</div>' : '';
    if (o.select) {
      return '<div' + cls + '><label>' + esc(label) + '</label><select data-k="' + k + '">' + o.select.map(function (x) {
        return '<option value="' + esc(x[0]) + '"' + (String(x[0]) === String(v == null ? '' : v) ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select>' + hint + '</div>';
    }
    if (o.area) { return '<div' + cls + '><label>' + esc(label) + '</label><textarea data-k="' + k + '" maxlength="' + (o.max || 4000) + '"' + (o.rows ? ' rows="' + o.rows + '"' : '') + '>' + esc(v || '') + '</textarea>' + hint + '</div>'; }
    return '<div' + cls + '><label>' + esc(label) + '</label><input data-k="' + k + '" type="' + (o.type || 'text') + '" value="' + esc(v == null ? '' : v) + '"'
      + (o.ph ? ' placeholder="' + esc(o.ph) + '"' : '') + (o.min ? ' min="' + o.min + '"' : '') + '>' + hint + '</div>';
  }
  function vals(root) {
    var o = {};
    root.querySelectorAll('[data-k]').forEach(function (el) { o[el.getAttribute('data-k')] = el.type === 'checkbox' ? el.checked : el.value.trim(); });
    return o;
  }
  var PLACEHOLDER_HINT = 'You can use {{first_name}}, {{name}}, {{event}}, {{date}}, {{location}} and {{agency}}. The RSVP button, the event details and an unsubscribe link are added for you.';

  /* ── image picker (upload → preview) ── */
  function imageBlock(url) {
    return '<div class="full"><label>Image (shown at the top of the email and the RSVP page)</label><div data-img style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
      + '<img data-imgprev src="' + esc(url || '') + '" alt="" style="max-width:220px;max-height:110px;border-radius:8px;border:1px solid #E2E8F0;' + (url ? '' : 'display:none') + '">'
      + '<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-imgfile style="display:none">'
      + '<button type="button" class="kt-btn" data-imgup>' + (url ? 'Replace image' : 'Upload image') + '</button>'
      + '<button type="button" class="kt-btn" data-imgclear' + (url ? '' : ' style="display:none"') + '>Remove image</button>'
      + '<input type="hidden" data-k="image_url" value="' + esc(url || '') + '"></div><div class="rv-hint">JPG, PNG, GIF or WebP, up to 5 MB. A wide image (about 1200×500) looks best.</div></div>';
  }
  function wireImage(root) {
    var up = root.querySelector('[data-imgup]'); if (!up) { return; }
    var file = root.querySelector('[data-imgfile]'), prev = root.querySelector('[data-imgprev]'), hid = root.querySelector('[data-k="image_url"]'), clr = root.querySelector('[data-imgclear]');
    up.onclick = function () { file.click(); };
    clr.onclick = function () { hid.value = ''; prev.style.display = 'none'; clr.style.display = 'none'; up.textContent = 'Upload image'; };
    file.onchange = async function () {
      var f = file.files && file.files[0]; if (!f) { return; }
      if (f.size > 5 * 1024 * 1024) { toast('That image is larger than 5 MB', 'error'); return; }
      var fd = new FormData(); fd.append('image', f);
      up.disabled = true; up.textContent = 'Uploading…';
      try {
        var r = await Api.postForm('/admin/rsvp/image', fd);
        hid.value = r.url; prev.src = r.url; prev.style.display = ''; clr.style.display = ''; up.textContent = 'Replace image';
      } catch (e) { toast(e.message || 'Upload failed', 'error'); up.textContent = hid.value ? 'Replace image' : 'Upload image'; }
      up.disabled = false; file.value = '';
    };
  }

  /* ── questions builder ── */
  function qRow(q) {
    var locked = q.key === 'email';
    return '<div class="rv-q' + (locked ? ' locked' : '') + '" data-q data-key="' + esc(q.key || '') + '">'
      + '<input data-ql placeholder="Question" value="' + esc(q.label || '') + '" maxlength="160">'
      + '<select data-qt' + (locked ? ' disabled' : '') + '>' + TYPES.map(function (t) { return '<option value="' + t[0] + '"' + (t[0] === (q.type || 'text') ? ' selected' : '') + '>' + t[1] + '</option>'; }).join('') + '</select>'
      + '<label class="req" title="' + (locked ? 'Email is always required' : 'Required') + '"><input type="checkbox" data-qr' + (q.required || locked ? ' checked' : '') + (locked ? ' disabled' : '') + '> Required</label>'
      + '<div class="mv"><button type="button" data-up title="Move up">↑</button><button type="button" data-down title="Move down">↓</button>'
      + (locked ? '' : '<button type="button" data-del title="Remove question">✕</button>') + '</div>'
      + '<div class="more"><input data-qo placeholder="Choices, separated by commas" value="' + esc((q.options || []).join(', ')) + '" style="' + (q.type === 'select' ? '' : 'display:none') + '">'
      + '<input data-qp placeholder="Placeholder (optional)" value="' + esc(q.placeholder || '') + '" maxlength="160">'
      + '<input data-qd placeholder="Pre-filled answer (optional)" value="' + esc(q.default || '') + '" maxlength="160">'
      + '<input data-qh placeholder="Help text under the question (optional)" value="' + esc(q.help || '') + '" maxlength="300"></div></div>';
  }
  function questionsBlock(fields) {
    var list = (fields && fields.length) ? fields : [{ key: 'full_name', label: 'Full name', type: 'text', required: true }, { key: 'email', label: 'Email', type: 'email', required: true }];
    if (!list.some(function (f) { return f.key === 'email'; })) { list.splice(1, 0, { key: 'email', label: 'Email', type: 'email', required: true }); }
    return '<div class="full"><label>Questions on the RSVP form</label><div data-qs>' + list.map(qRow).join('') + '</div>'
      + '<button type="button" class="kt-btn" data-qadd>＋ Add question</button><div class="rv-hint">Email is always asked and always required. Changing questions later never alters answers already given.</div></div>';
  }
  function wireQuestions(root) {
    var box = root.querySelector('[data-qs]'); if (!box) { return; }
    root.querySelector('[data-qadd]').onclick = function () {
      var d = document.createElement('div'); d.innerHTML = qRow({ label: '', type: 'text' }); box.appendChild(d.firstChild);
      box.lastChild.querySelector('[data-ql]').focus();
    };
    box.addEventListener('click', function (e) {
      var row = e.target.closest('[data-q]'); if (!row) { return; }
      if (e.target.closest('[data-del]')) { row.remove(); }
      else if (e.target.closest('[data-up]') && row.previousElementSibling) { box.insertBefore(row, row.previousElementSibling); }
      else if (e.target.closest('[data-down]') && row.nextElementSibling) { box.insertBefore(row.nextElementSibling, row); }
    });
    box.addEventListener('change', function (e) {
      if (!e.target.matches('[data-qt]')) { return; }
      e.target.closest('[data-q]').querySelector('[data-qo]').style.display = e.target.value === 'select' ? '' : 'none';
    });
  }
  function readQuestions(root) {
    var out = [];
    root.querySelectorAll('[data-q]').forEach(function (row) {
      var label = row.querySelector('[data-ql]').value.trim(); if (!label) { return; }
      var key = row.getAttribute('data-key') || '';
      out.push({ key: key, label: label, type: key === 'email' ? 'email' : row.querySelector('[data-qt]').value, required: key === 'email' ? true : row.querySelector('[data-qr]').checked,
        options: row.querySelector('[data-qo]').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean),
        placeholder: row.querySelector('[data-qp]').value.trim(), default: row.querySelector('[data-qd]').value.trim(), help: row.querySelector('[data-qh]').value.trim() });
    });
    var bad = out.filter(function (q) { return q.type === 'select' && !q.options.length; })[0];
    if (bad) { throw new Error('Add the choices for “' + bad.label + '”.'); }
    return out;
  }

  /* ── render ── */
  async function render(main) {
    Api = KT.Api;
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = CSS + '<div data-kt-self-live style="padding:14px 24px;max-width:1300px;">'
      + '<div class="kt-page-hero"><h2>🎟️ Event RSVPs</h2><p>Invite people to an event, collect their RSVPs, and see who was sent, who signed up and who opted out.</p></div>'
      + '<div class="rv-tabs"><button data-tab="events">Events</button><button data-tab="templates">Templates</button><button data-tab="reports">Reports</button></div>'
      + '<div id="rv-body">Loading…</div></div>';
    main.querySelector('.rv-tabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) { return; }
      state.tab = b.getAttribute('data-tab'); state.eventId = null; paint(main);
    });
    await paint(main);
  }

  async function paint(main) {
    if (!main.isConnected) { return; }
    main.querySelectorAll('.rv-tabs button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === state.tab); });
    var body = main.querySelector('#rv-body');
    try {
      var t = await Api.get('/admin/rsvp/templates');
      state.templates = t.templates || []; state.isPlatform = !!t.is_platform; state.agencies = t.agencies || [];
    } catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    if (state.tab === 'templates') { return paintTemplates(main, body); }
    if (state.tab === 'reports') { return paintReports(main, body); }
    if (state.eventId) { return paintEvent(main, body, state.eventId); }
    return paintEvents(main, body);
  }

  /* ── Events ── */
  async function paintEvents(main, body) {
    var d = await Api.get('/admin/rsvp/events');
    var rows = d.events || [];
    body.innerHTML = '<div class="rv-bar"><button class="kt-btn kt-btn-primary" data-new>＋ New event</button></div>'
      + (!state.templates.length ? '<div class="kt-card">Start with a template — it holds the questions and the invitation email. <button class="kt-btn" data-gotpl>Create a template</button></div>' : '')
      + (rows.length ? '<div class="kt-card"><table><thead><tr><th>Event</th><th>When</th><th>Status</th><th>Invited</th><th>Sent</th><th>Signed up</th><th>Opted out</th><th></th></tr></thead><tbody>'
        + rows.map(function (e) {
          var s = e.stats || {};
          return '<tr><td><a href="#" data-open="' + e.id + '" style="font-weight:700">' + esc(e.title) + '</a></td><td>' + esc(e.when || '—') + '</td><td>' + statusPill(e.status) + '</td>'
            + '<td>' + (s.invited || 0) + '</td><td>' + (s.sent || 0) + '</td><td>' + (s.registered || 0) + '</td><td>' + (s.opted_out || 0) + '</td>'
            + '<td style="text-align:center;white-space:nowrap"><button type="button" class="kt-btn" data-open="' + e.id + '" title="Open">Open</button>'
            + '<button type="button" class="kt-btn" data-copy="' + esc(e.public_url) + '" title="Copy the shareable RSVP link">Copy link</button>'
            + '<button type="button" class="kt-btn" data-report="' + e.id + '" title="Report">Report</button>'
            + '<button type="button" class="kt-btn" data-del="' + e.id + '" data-title="' + esc(e.title) + '" title="Delete event">Delete</button></td></tr>';
        }).join('') + '</tbody></table></div>'
        : (state.templates.length ? '<div class="kt-card" style="color:#64748B">No events yet. Create one from a template to get a shareable RSVP link.</div>' : ''));
    var nb = body.querySelector('[data-new]'); if (nb) { nb.onclick = function () { eventDialog(main, null); }; }
    var gt = body.querySelector('[data-gotpl]'); if (gt) { gt.onclick = function () { state.tab = 'templates'; paint(main); }; }
    body.onclick = async function (e) {
      var b;
      if ((b = e.target.closest('[data-open]'))) { e.preventDefault(); state.eventId = +b.getAttribute('data-open'); paint(main); }
      else if ((b = e.target.closest('[data-copy]'))) { copy(b.getAttribute('data-copy')); }
      else if ((b = e.target.closest('[data-report]'))) { state.tab = 'reports'; state.reportEvent = +b.getAttribute('data-report'); state.view = 'all'; paint(main); }
      else if ((b = e.target.closest('[data-del]'))) {
        var ok = await KT.confirm({ title: 'Delete “' + b.getAttribute('data-title') + '”?', description: 'Its RSVP link stops working and anything not yet sent is held back. The report is no longer available.', okLabel: 'Delete event', tone: 'danger' });
        if (!ok) { return; }
        try { await Api.delete('/admin/rsvp/events/' + b.getAttribute('data-del')); toast('Event deleted', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not delete', 'error'); }
      }
    };
  }

  function eventDialog(main, ev) {
    var tpls = state.templates;
    if (!ev && !tpls.length) { toast('Create a template first', 'info'); return; }
    var first = tpls[0] || {};
    var html = '<div class="rv-f">'
      + (ev ? '' : field('template_id', 'Template', first.id, { select: tpls.map(function (t) { return [t.id, t.name]; }), full: true }))
      + field('title', 'Event title', ev ? ev.title : '', { full: true, ph: 'e.g. Engaged Citizens Town Hall' })
      + field('starts_local', 'Starts', ev ? ev.starts_local : '', { type: 'datetime-local' })
      + field('ends_local', 'Ends', ev ? ev.ends_local : '', { type: 'datetime-local' })
      + field('location', 'Where', ev ? ev.location : '', { area: true, full: true, rows: 2, max: 250 })
      + field('description', 'About the event (shown on the RSVP page)', ev ? ev.description : '', { area: true, full: true })
      + field('notify_email', 'Send sign-ups and opt-outs to', ev ? ev.notify_email : (first.notify_email || ''), { type: 'email', ph: 'events@yourorganisation.ca',
        hint: 'Gets every sign-up and opt-out, and is the Reply-To on invitations. Registrants get their own copy.' })
      + field('capacity', 'Capacity (optional)', ev ? ev.capacity : '', { type: 'number', min: 1, ph: 'No limit' })
      + field('status', 'Status', ev ? ev.status : 'open', { select: [['open', 'Open — taking RSVPs'], ['draft', 'Draft — link not live yet'], ['closed', 'Closed — no more RSVPs']] })
      + (ev ? '<div class="full" style="font-weight:800;margin-top:6px">Invitation email</div>' + imageBlock(ev.image_url)
        + field('email_subject', 'Subject', ev.email_subject, { full: true })
        + field('email_body', 'Message', ev.email_body, { area: true, full: true, max: 8000, hint: PLACEHOLDER_HINT })
        + field('confirmation_message', 'Confirmation message (sent to each registrant)', ev.confirmation_message, { area: true, full: true })
        : '<div class="full rv-hint">The image, invitation email and questions come from the template. You can change them for this event afterwards.</div>')
      + '</div>';
    var ov = dialog(ev ? 'Edit event' : 'New event', html, async function (root) {
      var v = vals(root);
      if (!v.title) { throw new Error('Give the event a title.'); }
      if (!isEmail(v.notify_email)) { throw new Error('Enter the address that should receive sign-ups.'); }
      v.capacity = v.capacity ? +v.capacity : null;
      ['starts_local', 'ends_local'].forEach(function (k) { if (!v[k]) { v[k] = null; } });
      var r = await Api.post('/admin/rsvp/events' + (ev ? '/' + ev.id : ''), v);
      toast(ev ? 'Event saved' : 'Event created', 'success');
      state.tab = 'events'; state.eventId = ev ? ev.id : r.id; paint(main);
    }, ev ? 'Save event' : 'Create event');
    var sel = ov.querySelector('[data-k="template_id"]');
    if (sel) {
      sel.onchange = function () {
        var t = tpls.filter(function (x) { return String(x.id) === sel.value; })[0];
        var n = ov.querySelector('[data-k="notify_email"]'); if (t && n && t.notify_email) { n.value = t.notify_email; }
      };
    }
    wireImage(ov);
  }

  async function paintEvent(main, body, id) {
    var d, rep;
    try { d = await Api.get('/admin/rsvp/events/' + id); rep = await Api.get('/admin/rsvp/events/' + id + '/report?view=all'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    if (!main.isConnected) { return; }
    var ev = d.event, s = ev.stats || {};
    var stat = function (n, l) { return '<div class="rv-stat"><b>' + (n || 0) + '</b><span>' + l + '</span></div>'; };
    body.innerHTML = '<div class="rv-bar"><button class="kt-btn" data-back>← All events</button><span style="flex:1"></span><button class="kt-btn" data-refresh title="Refresh">Refresh</button></div>'
      + '<div class="rv-head">' + (ev.image_url ? '<img src="' + esc(ev.image_url) + '" alt="">' : '')
      + '<div style="flex:1;min-width:240px"><h3>' + esc(ev.title) + ' ' + statusPill(ev.status) + '</h3>'
      + (ev.when ? '<div class="rv-sub">📅 ' + esc(ev.when) + '</div>' : '') + (ev.location ? '<div class="rv-sub">📍 ' + esc(ev.location) + '</div>' : '')
      + '<div class="rv-sub">✉️ Sign-ups go to <strong>' + esc(ev.notify_email) + '</strong></div>'
      + '<div class="rv-link"><code>' + esc(ev.public_url) + '</code><button class="kt-btn" data-copy="' + esc(ev.public_url) + '">Copy link</button>'
      + '<a class="kt-btn" href="' + esc(ev.public_url) + '" target="_blank" rel="noopener">Preview page</a></div></div></div>'
      + '<div class="rv-stats">' + stat(s.invited, 'Invited') + stat(s.sent, 'Sent') + stat(s.pending + s.queued, 'Waiting to send') + stat(s.not_delivered, 'Not delivered')
      + stat(s.opened, 'Opened link') + stat(s.registered, 'Signed up') + stat(s.opted_out, 'Opted out') + stat(s.no_response, 'No response') + '</div>'
      + '<div class="rv-bar"><button class="kt-btn kt-btn-primary" data-add>＋ Add invitees</button>'
      + '<button class="kt-btn" data-send' + (s.pending ? '' : ' disabled') + '>Send invitations' + (s.pending ? ' (' + s.pending + ')' : '') + '</button>'
      + '<button class="kt-btn" data-test>Send a test</button><button class="kt-btn" data-edit>Edit event</button><button class="kt-btn" data-qs>Questions</button>'
      + '<button class="kt-btn" data-report>Full report</button></div>'
      + (s.queued ? '<div class="rv-hint" style="margin:-4px 0 10px">' + s.queued + ' invitation(s) are going out now — they are sent within about a minute. Press Refresh to see the result.</div>' : '')
      + inviteTable(rep.rows || []);
    body.onclick = async function (e) {
      var b = e.target.closest('button,a[data-copy]'); if (!b) { return; }
      if (b.hasAttribute('data-back')) { state.eventId = null; paint(main); }
      else if (b.hasAttribute('data-refresh')) { paint(main); }
      else if (b.hasAttribute('data-copy')) { copy(b.getAttribute('data-copy')); }
      else if (b.hasAttribute('data-edit')) { eventDialog(main, ev); }
      else if (b.hasAttribute('data-qs')) { questionsDialog(main, ev); }
      else if (b.hasAttribute('data-add')) { inviteDialog(main, ev); }
      else if (b.hasAttribute('data-report')) { state.tab = 'reports'; state.reportEvent = ev.id; state.view = 'all'; paint(main); }
      else if (b.hasAttribute('data-send')) { sendPending(main, ev, s.pending); }
      else if (b.hasAttribute('data-test')) { sendTest(ev); }
      else if (b.hasAttribute('data-resend')) {
        var ok = await KT.confirm({ title: 'Send the invitation again?', description: 'This sends ' + b.getAttribute('data-email') + ' another copy of the invitation.', okLabel: 'Send again' });
        if (!ok) { return; }
        try { var r = await Api.post('/admin/rsvp/events/' + ev.id + '/send', { invite_ids: [+b.getAttribute('data-resend')] }); toast(r.queued ? 'Invitation queued' : 'Nothing to send', r.queued ? 'success' : 'info'); paint(main); }
        catch (err) { toast(err.message || 'Could not send', 'error'); }
      } else if (b.hasAttribute('data-remove')) {
        try { await Api.delete('/admin/rsvp/invites/' + b.getAttribute('data-remove')); toast('Removed from the list', 'success'); paint(main); }
        catch (err) { toast(err.message || 'Could not remove', 'error'); }
      }
    };
  }

  function inviteTable(rows) {
    if (!rows.length) { return '<div class="kt-card" style="color:#64748B">Nobody on the list yet. Add invitees, or share the link — people who use it appear here too.</div>'; }
    return '<div class="kt-card"><table><thead><tr><th>Name</th><th>Email</th><th>Invitation</th><th>Sent</th><th>Signed up</th><th>Opted out</th><th></th></tr></thead><tbody>'
      + rows.map(function (r) {
        var acts = '';
        if (r.status !== 'not_invited' && !r.registered_at && !r.opted_out_at && (r.status === 'sent' || r.status === 'failed' || r.status === 'suppressed')) {
          acts += '<button type="button" class="kt-btn" data-resend="' + r.id + '" data-email="' + esc(r.email) + '" title="Send the invitation again">Resend</button>';
        }
        if (r.can_remove) { acts += '<button type="button" class="kt-btn" data-remove="' + r.id + '" title="Remove from the list">Remove</button>'; }
        return '<tr><td>' + esc(r.name || '—') + '</td><td>' + esc(r.email) + '</td><td>' + sendPill(r.status, r.status_label)
          + (r.note ? '<div class="rv-hint" title="' + esc(r.note) + '">' + esc(r.note.length > 70 ? r.note.slice(0, 70) + '…' : r.note) + '</div>' : '') + '</td>'
          + '<td>' + esc(r.sent_at || '—') + '</td><td>' + esc(r.registered_at || '—') + '</td><td>' + esc(r.opted_out_at || '—') + '</td>'
          + '<td style="text-align:center;white-space:nowrap">' + acts + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  async function sendPending(main, ev, n) {
    var ok = await KT.confirm({ title: 'Send ' + n + ' invitation' + (n === 1 ? '' : 's') + '?', description: 'Everyone on the list who has not been sent one yet gets their personal RSVP link. Anyone who has opted out is skipped.', okLabel: 'Send invitations' });
    if (!ok) { return; }
    try { var r = await Api.post('/admin/rsvp/events/' + ev.id + '/send', {}); toast(r.queued + ' invitation(s) on their way', 'success'); paint(main); }
    catch (e) { toast(e.message || 'Could not send', 'error'); }
  }

  async function sendTest(ev) {
    var to = await KT.prompt({ title: 'Send a test invitation', description: 'Sends one copy of this event’s invitation, marked [Test], to the address you enter. Its buttons open the shareable page, so nobody is signed up by it.', placeholder: 'you@example.com', type: 'email', okLabel: 'Send test' });
    if (to === null) { return; }
    to = String(to || '').trim();
    if (!isEmail(to)) { toast('Enter a valid email address', 'error'); return; }
    try {
      var r = await Api.post('/admin/rsvp/events/' + ev.id + '/test', { to: to });
      if (r.sent) { toast('Test sent to ' + to, 'success'); }
      else { toast('Not sent (' + r.status + '): ' + (r.reason || ''), 'warning'); }
    } catch (e) { toast(e.message || 'Could not send', 'error'); }
  }

  function questionsDialog(main, ev) {
    var ov = dialog('Questions — ' + ev.title, '<div class="rv-f">' + questionsBlock(ev.fields) + '</div>', async function (root) {
      var fields = readQuestions(root);
      await Api.post('/admin/rsvp/events/' + ev.id, { title: ev.title, starts_local: ev.starts_local || null, ends_local: ev.ends_local || null, location: ev.location,
        description: ev.description, notify_email: ev.notify_email, status: ev.status, capacity: ev.capacity, fields: fields });
      toast('Questions saved', 'success'); paint(main);
    }, 'Save questions', { width: 820 });
    wireQuestions(ov);
  }

  /* ── Add invitees: paste / CSV, or pick families and staff ── */
  function parseList(text) {
    var out = [], lines = String(text || '').split(/\r?\n/), header = null;
    lines.forEach(function (line, i) {
      if (!line.trim()) { return; }
      var cells = csvCells(line);
      if (i === 0 && !cells.some(isEmail) && cells.some(function (c) { return /mail/i.test(c); })) { header = cells.map(function (c) { return c.toLowerCase(); }); return; }
      var email = '', name = '';
      if (header) {
        var ei = header.findIndex(function (h) { return /mail/.test(h); });
        var ni = header.findIndex(function (h) { return /^(full )?name$/.test(h.trim()); });
        var fi = header.findIndex(function (h) { return /first/.test(h); }), li = header.findIndex(function (h) { return /last|surname/.test(h); });
        email = cells[ei] || '';
        name = ni >= 0 ? cells[ni] : [fi >= 0 ? cells[fi] : '', li >= 0 ? cells[li] : ''].join(' ');
      } else {
        // "Jane Doe <jane@x.ca>", "Jane Doe, jane@x.ca", or just "jane@x.ca"
        var m = line.match(/<([^>]+)>/);
        email = m ? m[1] : (cells.filter(isEmail)[0] || '');
        name = m ? line.replace(/<[^>]+>/, '') : cells.filter(function (c) { return !isEmail(c); }).join(' ');
      }
      out.push({ email: String(email).trim(), name: String(name || '').replace(/["']/g, '').trim() });
    });
    return out;
  }
  function csvCells(line) {
    var out = [], cur = '', q = false;
    for (var i = 0; i < line.length; i++) {
      var c = line[i];
      if (c === '"') { if (q && line[i + 1] === '"') { cur += '"'; i++; } else { q = !q; } }
      else if ((c === ',' || c === ';' || c === '\t') && !q) { out.push(cur.trim()); cur = ''; }
      else { cur += c; }
    }
    out.push(cur.trim());
    return out;
  }

  async function inviteDialog(main, ev) {
    var contacts = [];
    var html = '<div class="rv-tabs" data-itabs><button data-it="paste" class="on">Paste or upload</button><button data-it="pick">Families &amp; staff</button></div>'
      + '<div data-pane="paste"><div class="rv-f"><div class="full"><label>One person per line</label>'
      + '<textarea data-paste rows="8" placeholder="Jane Doe, jane@example.com&#10;sam@example.com&#10;Or paste a CSV with an Email column"></textarea>'
      + '<div class="rv-hint">Accepts “Name, email”, “Name &lt;email&gt;”, a bare email, or a CSV with a header row (Email, Name / First name, Last name).</div></div>'
      + '<div class="full"><input type="file" accept=".csv,text/csv,text/plain" data-csv style="display:none"><button type="button" class="kt-btn" data-csvbtn>Upload a CSV file</button> <span data-pcount class="rv-hint"></span></div></div></div>'
      + '<div data-pane="pick" style="display:none"><div class="rv-bar"><input data-search placeholder="Search names or emails" style="flex:1;min-width:160px">'
      + '<select data-kind><option value="">Everyone</option><option value="guardian">Families</option><option value="staff">Staff</option></select>'
      + '<button type="button" class="kt-btn" data-all>Select shown</button><button type="button" class="kt-btn" data-none>Clear</button></div>'
      + '<div class="rv-pick" data-list>Loading…</div><div class="rv-hint" data-scount></div></div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-weight:700;font-size:13px"><input type="checkbox" data-sendnow' + (ev.status === 'open' ? ' checked' : ' disabled') + '> Send their invitations now'
      + (ev.status === 'open' ? '' : ' <span class="rv-hint">(open the event first)</span>') + '</label>';
    var picked = {};
    var ov = dialog('Add invitees — ' + ev.title, html, async function (root) {
      var list = parseList(root.querySelector('[data-paste]').value).map(function (p) { return { email: p.email, name: p.name, source: csvLoaded ? 'csv' : 'manual' }; });
      Object.keys(picked).forEach(function (k) { var c = picked[k]; list.push({ source: c.kind, user_id: c.user_id, email: c.email, name: c.name }); });
      if (!list.length) { throw new Error('Add at least one person.'); }
      var r = await Api.post('/admin/rsvp/events/' + ev.id + '/invites', { recipients: list, send: root.querySelector('[data-sendnow]').checked });
      var msg = r.added + ' added' + (r.queued ? ', ' + r.queued + ' invitation(s) on their way' : '') + (r.already_invited ? ' · ' + r.already_invited + ' already on the list' : '')
        + (r.opted_out ? ' · ' + r.opted_out + ' opted out (won’t be sent)' : '') + (r.invalid_count ? ' · ' + r.invalid_count + ' not valid: ' + r.invalid.slice(0, 5).join(', ') : '');
      toast(msg, r.invalid_count ? 'warning' : 'success');
      paint(main);
    }, 'Add invitees', { width: 760 });
    var csvLoaded = false;
    var ta = ov.querySelector('[data-paste]'), pc = ov.querySelector('[data-pcount]');
    var count = function () { var l = parseList(ta.value); var ok = l.filter(function (p) { return isEmail(p.email); }).length; pc.textContent = l.length ? ok + ' valid email' + (ok === 1 ? '' : 's') + (l.length - ok ? ' · ' + (l.length - ok) + ' line(s) without one' : '') : ''; };
    ta.addEventListener('input', count);
    var file = ov.querySelector('[data-csv]');
    ov.querySelector('[data-csvbtn]').onclick = function () { file.click(); };
    file.onchange = function () {
      var f = file.files && file.files[0]; if (!f) { return; }
      var rd = new FileReader(); rd.onload = function () { ta.value = String(rd.result || '').replace(/^﻿/, ''); csvLoaded = true; count(); }; rd.readAsText(f);
    };
    ov.querySelector('[data-itabs]').onclick = async function (e) {
      var b = e.target.closest('[data-it]'); if (!b) { return; }
      ov.querySelectorAll('[data-it]').forEach(function (x) { x.classList.toggle('on', x === b); });
      ov.querySelectorAll('[data-pane]').forEach(function (p) { p.style.display = p.getAttribute('data-pane') === b.getAttribute('data-it') ? '' : 'none'; });
      if (b.getAttribute('data-it') === 'pick' && !contacts.length) {
        try { contacts = (await Api.get('/admin/rsvp/contacts')).contacts || []; } catch (err) { ov.querySelector('[data-list]').textContent = err.message || 'Could not load contacts'; return; }
        drawPick();
      }
    };
    var shown = [];
    function drawPick() {
      var q = ov.querySelector('[data-search]').value.trim().toLowerCase(), kind = ov.querySelector('[data-kind]').value;
      shown = contacts.filter(function (c) { return (!kind || c.kind === kind) && (!q || (c.name + ' ' + c.email + ' ' + c.detail).toLowerCase().indexOf(q) >= 0); });
      var box = ov.querySelector('[data-list]');
      box.innerHTML = shown.length ? shown.slice(0, 500).map(function (c) {
        return '<label><input type="checkbox" data-c="' + esc(c.key) + '"' + (picked[c.key] ? ' checked' : '') + '><span><strong>' + esc(c.name || c.email) + '</strong> <small>' + esc(c.email) + (c.detail ? ' · ' + esc(c.detail) : '') + '</small></span></label>';
      }).join('') : '<div style="padding:10px;color:#64748B">Nobody matches.</div>';
      ov.querySelector('[data-scount]').textContent = Object.keys(picked).length + ' selected';
    }
    ov.querySelector('[data-search]').addEventListener('input', drawPick);
    ov.querySelector('[data-kind]').addEventListener('change', drawPick);
    ov.querySelector('[data-list]').addEventListener('change', function (e) {
      var k = e.target.getAttribute('data-c'); if (!k) { return; }
      var c = contacts.filter(function (x) { return x.key === k; })[0];
      if (e.target.checked && c) { picked[k] = c; } else { delete picked[k]; }
      ov.querySelector('[data-scount]').textContent = Object.keys(picked).length + ' selected';
    });
    ov.querySelector('[data-all]').onclick = function () { shown.slice(0, 500).forEach(function (c) { picked[c.key] = c; }); drawPick(); };
    ov.querySelector('[data-none]').onclick = function () { picked = {}; drawPick(); };
  }

  /* ── Templates ── */
  function paintTemplates(main, body) {
    var rows = state.templates;
    body.innerHTML = '<div class="rv-bar"><button class="kt-btn kt-btn-primary" data-new>＋ New template</button></div>'
      + (rows.length ? '<div class="kt-card"><table><thead><tr><th>Template</th><th>Questions</th><th>Sign-ups go to</th><th>Events</th><th></th></tr></thead><tbody>'
        + rows.map(function (t) {
          return '<tr><td><strong>' + esc(t.name) + '</strong>' + (t.description ? '<div class="rv-hint">' + esc(t.description) + '</div>' : '') + '</td>'
            + '<td>' + esc(t.fields.map(function (f) { return f.label; }).join(', ')) + '</td><td>' + esc(t.notify_email || '—') + '</td><td>' + t.events + '</td>'
            + '<td style="text-align:center;white-space:nowrap"><button type="button" class="kt-btn" data-edit="' + t.id + '" title="Edit">Edit</button>'
            + '<button type="button" class="kt-btn" data-dup="' + t.id + '" title="Duplicate">Duplicate</button>'
            + (state.isPlatform ? '<button type="button" class="kt-btn" data-copyto="' + t.id + '" title="Copy to another agency">Copy to agency</button>' : '')
            + '<button type="button" class="kt-btn" data-del="' + t.id + '" title="Delete template">Delete</button></td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="kt-card" style="color:#64748B">No templates yet. A template holds the RSVP questions, the invitation email, an image and where sign-ups are sent.</div>');
    body.querySelector('[data-new]').onclick = function () { templateDialog(main, null); };
    body.onclick = async function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      var id = +(b.getAttribute('data-edit') || b.getAttribute('data-dup') || b.getAttribute('data-copyto') || b.getAttribute('data-del') || 0);
      var t = rows.filter(function (x) { return x.id === id; })[0]; if (!t) { return; }
      if (b.hasAttribute('data-edit')) { templateDialog(main, t); }
      else if (b.hasAttribute('data-dup')) {
        try { await Api.post('/admin/rsvp/templates/' + id + '/copy', {}); toast('Template duplicated', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not copy', 'error'); }
      } else if (b.hasAttribute('data-copyto')) {
        copyToAgency(main, t);
      } else if (b.hasAttribute('data-del')) {
        var ok = await KT.confirm({ title: 'Delete “' + t.name + '”?', description: 'Events already made from it keep their own questions and email, so nothing about them changes.', okLabel: 'Delete template', tone: 'danger' });
        if (!ok) { return; }
        try { await Api.delete('/admin/rsvp/templates/' + id); toast('Template deleted', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not delete', 'error'); }
      }
    };
  }

  function copyToAgency(main, t) {
    var cur = String(sessionStorage.getItem('kt_active_agency_id') || '');
    var opts = state.agencies.filter(function (a) { return String(a.id) !== cur; }).map(function (a) { return [a.id, a.name]; });
    if (!opts.length) { toast('There is no other agency to copy to', 'info'); return; }
    dialog('Copy “' + t.name + '” to another agency', '<div class="rv-f">' + field('agency_id', 'Agency', opts[0][0], { select: opts, full: true,
      hint: 'The copy keeps the questions, email and image. Its sign-up address is left blank for that agency to set.' }) + '</div>', async function (root) {
      var v = vals(root);
      await Api.post('/admin/rsvp/templates/' + t.id + '/copy', { agency_id: +v.agency_id });
      toast('Copied to ' + (state.agencies.filter(function (a) { return String(a.id) === String(v.agency_id); })[0] || {}).name, 'success');
    }, 'Copy template');
  }

  function templateDialog(main, t) {
    t = t || {};
    var html = '<div class="rv-f">'
      + field('name', 'Template name', t.name, { full: true, ph: 'e.g. Community information session' })
      + field('description', 'Notes for your team (optional)', t.description, { full: true })
      + imageBlock(t.image_url)
      + field('email_subject', 'Invitation subject', t.email_subject || 'You’re invited: {{event}}', { full: true })
      + field('email_body', 'Invitation message', t.email_body || 'Hi {{first_name}},\n\nYou’re invited to {{event}}. Please let us know you’re coming by pressing RSVP below.\n\nWe hope to see you there.', { area: true, full: true, max: 8000, hint: PLACEHOLDER_HINT })
      + field('confirmation_message', 'Confirmation message (sent to each registrant with a copy of their answers)', t.confirmation_message || 'Thank you for registering for {{event}}. We look forward to seeing you.', { area: true, full: true })
      + field('notify_email', 'Send sign-ups and opt-outs to', t.notify_email, { type: 'email', full: true, ph: 'events@yourorganisation.ca', hint: 'Each event starts with this address and can change it.' })
      + questionsBlock(t.fields) + '</div>';
    var ov = dialog(t.id ? 'Edit template' : 'New template', html, async function (root) {
      var v = vals(root);
      if (!v.name) { throw new Error('Give the template a name.'); }
      if (v.notify_email && !isEmail(v.notify_email)) { throw new Error('The sign-up address is not a valid email.'); }
      v.fields = readQuestions(root);
      if (!v.notify_email) { v.notify_email = null; }
      await Api.post('/admin/rsvp/templates' + (t.id ? '/' + t.id : ''), v);
      toast('Template saved', 'success'); paint(main);
    }, 'Save template', { width: 820 });
    wireImage(ov); wireQuestions(ov);
  }

  /* ── Reports ── */
  async function paintReports(main, body) {
    var d = await Api.get('/admin/rsvp/events');
    var evs = d.events || [];
    if (!evs.length) { body.innerHTML = '<div class="kt-card" style="color:#64748B">No events yet.</div>' + await optOutsHtml(); wireOptOuts(main, body); return; }
    if (!state.reportEvent || !evs.some(function (e) { return e.id === state.reportEvent; })) { state.reportEvent = evs[0].id; }
    var rep = await Api.get('/admin/rsvp/events/' + state.reportEvent + '/report?view=' + encodeURIComponent(state.view));
    if (!main.isConnected) { return; }
    var s = rep.stats || {}, cols = rep.columns || [], rows = rep.rows || [];
    var counts = { all: null, sent: s.sent, registered: s.registered, opted_out: s.opted_out, no_response: s.no_response, not_sent: (s.pending || 0) + (s.queued || 0) + (s.not_delivered || 0) };
    body.innerHTML = '<div class="rv-bar"><select data-ev>' + evs.map(function (e) { return '<option value="' + e.id + '"' + (e.id === state.reportEvent ? ' selected' : '') + '>' + esc(e.title) + '</option>'; }).join('') + '</select>'
      + '<button class="kt-btn" data-csv title="Download this view as a CSV file">⬇ CSV</button><span class="rv-hint">Times are ' + esc(rep.tz) + '.</span></div>'
      + '<div class="rv-chips" style="margin-bottom:10px">' + VIEWS.map(function (v) { return '<button data-view="' + v[0] + '" class="' + (v[0] === state.view ? 'on' : '') + '">' + v[1] + (counts[v[0]] != null ? ' · ' + counts[v[0]] : '') + '</button>'; }).join('') + '</div>'
      + (rows.length ? '<div class="kt-card" style="overflow:auto"><table><thead><tr><th>Name</th><th>Email</th><th>Invitation</th><th>Sent</th><th>Opened link</th><th>Signed up</th><th>Opted out</th>'
        + cols.filter(function (c) { return c.key !== 'email'; }).map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r) {
          return '<tr><td>' + esc(r.name || '—') + '</td><td>' + esc(r.email) + '</td><td>' + sendPill(r.status, r.status_label) + '</td><td>' + esc(r.sent_at || '—') + '</td><td>' + esc(r.opened_at || '—') + '</td>'
            + '<td>' + esc(r.registered_at || '—') + (r.updated_response_at ? '<div class="rv-hint">updated ' + esc(r.updated_response_at) + '</div>' : '') + '</td><td>' + esc(r.opted_out_at || '—') + '</td>'
            + cols.filter(function (c) { return c.key !== 'email'; }).map(function (c) { return '<td style="white-space:pre-line;max-width:320px">' + esc(r.answers[c.key] || '') + '</td>'; }).join('') + '</tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="kt-card" style="color:#64748B">Nobody in this view.</div>')
      + await optOutsHtml();
    body.querySelector('[data-ev]').onchange = function () { state.reportEvent = +this.value; state.view = 'all'; paint(main); };
    body.querySelector('.rv-chips').onclick = function (e) { var b = e.target.closest('[data-view]'); if (!b) { return; } state.view = b.getAttribute('data-view'); paint(main); };
    body.querySelector('[data-csv]').onclick = function () {
      var ev = evs.filter(function (e) { return e.id === state.reportEvent; })[0] || {};
      download('/admin/rsvp/events/' + state.reportEvent + '/report.csv?view=' + encodeURIComponent(state.view), 'rsvp-' + String(ev.title || 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + state.view + '.csv');
    };
    wireOptOuts(main, body);
  }

  async function optOutsHtml() {
    var d;
    try { d = await Api.get('/admin/rsvp/optouts'); } catch (e) { return ''; }
    var rows = d.optouts || [];
    return '<h3 style="margin:22px 0 4px;font-size:15px">Unsubscribed from all event invitations</h3><div class="rv-hint" style="margin-bottom:8px">Nobody here is sent another invitation by your agency, whichever event it is for.</div>'
      + (rows.length ? '<div class="kt-card" data-optouts><table><thead><tr><th>Email</th><th>When</th><th>From the invitation for</th><th></th></tr></thead><tbody>'
        + rows.map(function (o) {
          return '<tr><td>' + esc(o.email) + '</td><td>' + esc(o.at || '') + '</td><td>' + esc(o.event || '—') + '</td>'
            + '<td style="text-align:center"><button type="button" class="kt-btn" data-resub="' + o.id + '" data-email="' + esc(o.email) + '" title="Resubscribe">Resubscribe</button></td></tr>';
        }).join('') + '</tbody></table></div>' : '<div class="kt-card" style="color:#64748B">Nobody has unsubscribed.</div>');
  }
  function wireOptOuts(main, body) {
    var box = body.querySelector('[data-optouts]'); if (!box) { return; }
    box.onclick = async function (e) {
      var b = e.target.closest('[data-resub]'); if (!b) { return; }
      var ok = await KT.confirm({ title: 'Resubscribe ' + b.getAttribute('data-email') + '?', description: 'Only do this when the person has asked to receive event invitations again.', okLabel: 'Resubscribe' });
      if (!ok) { return; }
      try { await Api.delete('/admin/rsvp/optouts/' + b.getAttribute('data-resub')); toast('Resubscribed', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not update', 'error'); }
    };
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    ['agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':rsvp', render); });
  }
  reg(0);
})(window);
