/* ═══════════════════════════════════════════════════════════════════════
   Policies (#policies) and Policies to review (#my-policies) — 2026-10-13

   Ontario O. Reg. 137/15 s.6.1 (as amended by O. Reg. 197/26): staff review every required
   policy before working with children and whenever it changes; compliance and
   contraventions are monitored in writing; records kept 3 years. s.58(1.1) from
   2027-01-01: training on every required policy. s.46/49: program statement reviewed yearly.

   #policies (admins; directors for their centres): the library with status chips, upload
   or paste a policy, publish a new version (change summary required — everyone signs
   again), mark reviewed, record training and contraventions, the training matrix (staff ×
   policies, CSV), and "Add the Ontario checklist" (titles only, no policy text).

   #my-policies (educators, home visitors, directors): read the current version in the
   document panel, then "I have read and understand" with a typed full name. A once-a-day
   prompt on arrival when something is waiting.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  function Api() { return KT.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c); } catch (e) {} }
  function apiBase() { return (KT.API_BASE || 'https://api.kiddietrac.com/api/v1').replace(/\/$/, ''); }
  function roles() { try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').roles || []); } catch (e) { return []; } }
  function day(d) {
    if (!d) return '—';
    var m = String(d).slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return String(d);
    return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
  }
  function agencyNowLocal(tz) {
    try {
      var p = {}; new Intl.DateTimeFormat('en-CA', { timeZone: tz || 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
      return p.year + '-' + p.month + '-' + p.day + 'T' + p.hour + ':' + p.minute;
    } catch (e) { return ''; }
  }

  var CSS_ID = 'kt-policies-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '.pl-wrap{padding:20px 24px;max-width:1800px;margin:0 auto;}'
      + '.pl-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.pl-i{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;max-width:100%;}'
      + 'textarea.pl-i{height:auto;padding:8px 10px;line-height:1.5;font-family:inherit;}'
      + '.pl-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;}'
      + '.pl-b.p{background:#1F6FB2;border-color:#1F6FB2;color:#fff;}'
      + '.pl-b:disabled{opacity:.5;cursor:not-allowed;}'
      + '.pl-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap;}'
      + '.pl-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px;}'
      + '.pl-sub{color:#64748B;font-size:12.5px;line-height:1.45;}'
      + '.pl-bar{height:6px;background:#E2E8F0;border-radius:99px;overflow:hidden;width:90px;margin-top:4px;}'
      + '.pl-bar>i{display:block;height:100%;background:#16A34A;}'
      + '.pl-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-bottom:12px;}'
      + '.pl-mx{border-collapse:separate;border-spacing:0;font-size:12.5px;}'
      + '.pl-mx th,.pl-mx td{border-bottom:1px solid #EEF2F6;padding:6px 8px;text-align:center;white-space:nowrap;}'
      + '.pl-mx th{background:#F8FAFC;color:#475569;font-size:11px;font-weight:800;vertical-align:bottom;max-width:120px;white-space:normal;line-height:1.25;}'
      + '.pl-mx td:first-child,.pl-mx th:first-child{position:sticky;left:0;background:#fff;text-align:left;z-index:1;box-shadow:1px 0 0 #E2E8F0;}'
      + '.pl-mx th:first-child{background:#F8FAFC;}'
      + '.pl-c{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:22px;border-radius:6px;font-weight:800;font-size:12px;}'
      + '.pl-sign{border-top:1px dashed #CBD5E1;margin-top:12px;padding-top:12px;}'
      + '[data-kt-pretty] table.pl-mx thead th{text-transform:none !important;letter-spacing:0 !important;white-space:normal !important;min-width:92px;max-width:150px;overflow-wrap:break-word;word-break:normal;font-size:11.5px !important;}'
      + '[data-kt-pretty] table.pl-mx thead th:first-child{min-width:150px;}'
      + '.pl-stats{align-items:stretch !important;}'
      + '@media (max-width:600px){.pl-wrap{padding:12px 0;}.pl-card{padding:12px;}}';
    document.head.appendChild(s);
  }

  var CHIP = {
    current: ['#ECFDF5', '#047857'], review_soon: ['#FFFBEB', '#B45309'], review_overdue: ['#FEF2F2', '#B91C1C'], no_text: ['#F1F5F9', '#475569'],
    required: ['#EEF2FF', '#3730A3'], recommended: ['#F1F5F9', '#475569'], new27: ['#FDF4FF', '#86198F'], centre: ['#F0F9FF', '#0369A1'],
  };
  function chip(kind, text, title) { var c = CHIP[kind] || CHIP.no_text; return '<span class="pl-chip" data-kt-no-tone' + (title ? ' title="' + esc(title) + '"' : '') + ' style="background:' + c[0] + ';color:' + c[1] + ';">' + esc(text) + '</span>'; }
  function regRef(r) { if (!r) return ''; return /^(O\. Reg|CSA)/.test(r) ? r : 'O. Reg. 137/15 ' + r; }
  function statusChip(p) {
    if (p.status === 'no_text') return chip('no_text', 'No text yet');
    if (p.status === 'review_overdue') return chip('review_overdue', 'Review overdue · ' + day(p.next_review_due));
    if (p.status === 'review_soon') return chip('review_soon', 'Review due ' + day(p.next_review_due));
    return chip('current', 'Current · review ' + day(p.next_review_due));
  }

  /* ── viewing a version: the document panel, never a new window ── */
  function openVersion(versionId, title, hasFile, fileName) {
    if (hasFile) {
      return Promise.resolve(KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/policy-versions/' + versionId + '/file'); },
        { title: title, label: 'Policy', filename: fileName || (title + '.pdf') }));
    }
    return Api().get('/policy-versions/' + versionId).then(function (v) {
      var doc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;max-width:780px;margin:0 auto;padding:22px 18px 40px;font-size:15px;}'
        + 'h1{font-size:22px;margin:0 0 4px;}h3{font-size:16px;margin:18px 0 6px;}.m{color:#64748B;font-size:12.5px;margin-bottom:18px;border-bottom:1px solid #E2E8F0;padding-bottom:10px;}ul,ol{padding-left:22px;}</style></head><body>'
        + '<h1>' + esc(v.title) + '</h1><div class="m">' + esc(v.agency_name) + ' · Version ' + esc(v.version) + (v.published_at ? ' · published ' + esc(v.published_at) : '') + '</div>'
        + (v.body_html || '<p><i>No text.</i></p>') + '</body></html>';
      if (KT.viewHtml) KT.viewHtml(doc, { title: v.title, label: 'Policy · version ' + v.version });
      return v;
    });
  }

  function modal(opts) { return KT.Shell.Modal.open(opts); }
  function q(h, sel) { return h && h.backdrop ? h.backdrop.querySelector(sel) : null; }

  /* ═════════════════════════ admin / director: #policies ═════════════════════════ */
  var adm = { centre: '', archived: false, mxCentre: '', mxAll: false, data: null };

  async function renderAdmin(main) {
    css();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="pl-wrap" style="color:#64748B;">Loading policies…</div>';
    var wrap = document.createElement('div'); wrap.className = 'pl-wrap';
    wrap.innerHTML = '<div class="kt-hero" style="background:linear-gradient(135deg,#0B2545 0%,#13315C 55%,#1F6FB2 100%);">'
      + '<div class="kt-hero-greet">📘 COMPLIANCE</div><h1>Policies</h1>'
      + '<div class="kt-hero-sub">Your policies and procedures, who has signed the current version, and who still needs to. Staff review each policy before working with children and again whenever it changes. Everything is kept on file.</div></div>';
    var host = document.createElement('div'); host.style.marginTop = '14px';
    wrap.appendChild(host);
    main.innerHTML = ''; main.appendChild(wrap);
    if (!KT.settingsTabs) { paintLibrary(host); return; }
    KT.settingsTabs(host, 'policies', [
      { key: 'library', label: '📚 Library', paint: paintLibrary },
      { key: 'matrix', label: '🧮 Training matrix', paint: paintMatrix },
      { key: 'contraventions', label: '⚠️ Contraventions', paint: paintContraventions },
    ], { param: 'tab' });
  }

  async function paintLibrary(pane) {
    var qs = [];
    if (adm.centre) qs.push('centre_id=' + adm.centre);
    if (adm.archived) qs.push('archived=1');
    var d;
    try { d = await Api().get('/policies' + (qs.length ? '?' + qs.join('&') : '')); }
    catch (e) { pane.innerHTML = '<div class="kt-card" style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load policies.') + '</div>'; return; }
    adm.data = d;
    var reqd = d.policies.filter(function (p) { return p.required; });
    var noText = reqd.filter(function (p) { return p.status === 'no_text'; }).length;
    var overdue = d.policies.filter(function (p) { return p.status === 'review_overdue'; }).length;
    var signedTotal = 0, staffTotal = 0;
    d.policies.forEach(function (p) { if (p.current_version_id) { signedTotal += p.staff_signed; staffTotal += p.staff_total; } });

    var h = '<div class="pl-row" style="margin-bottom:10px;">'
      + '<select class="pl-i" id="pl-fc"><option value="">' + (d.is_admin ? 'All centres' : 'All my centres') + '</option>'
      + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(adm.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>'
      + '<label class="pl-sub" style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" id="pl-arch"' + (adm.archived ? ' checked' : '') + '> Archived</label>'
      + '<span style="flex:1"></span>'
      + (d.can_seed && d.seed_missing ? '<button class="pl-b" id="pl-seed" title="Adds the policies Ontario licensing asks for, as titles only — you add your own text">🍁 Add the Ontario checklist (' + d.seed_missing + ')</button>' : '')
      + '<button class="pl-b p" id="pl-add">+ Add policy</button></div>';

    h += '<div class="pl-row pl-stats" style="margin-bottom:12px;gap:10px;">'
      + '<div class="pl-card" style="margin:0;flex:1;min-width:150px;"><div class="pl-sub">Required policies</div><div style="font-size:20px;font-weight:800;">' + reqd.length + '</div>' + (noText ? '<div class="pl-sub" style="color:#B45309;">' + noText + ' without text yet</div>' : '') + '</div>'
      + '<div class="pl-card" style="margin:0;flex:1;min-width:150px;"><div class="pl-sub">Sign-offs on current versions</div><div style="font-size:20px;font-weight:800;">' + (staffTotal ? Math.floor(signedTotal * 100 / staffTotal) + '%' : '—') + '</div><div class="pl-sub">' + signedTotal + ' of ' + staffTotal + ' · ' + d.staff_count + ' staff</div></div>'
      + '<div class="pl-card" style="margin:0;flex:1;min-width:150px;"><div class="pl-sub">Reviews overdue</div><div style="font-size:20px;font-weight:800;color:' + (overdue ? '#B91C1C' : '#0F172A') + ';">' + overdue + '</div></div></div>';

    if (!d.policies.length) {
      h += '<div class="kt-card" style="color:#475569;font-size:13.5px;line-height:1.6;">' + (adm.archived ? 'No archived policies.' : 'No policies yet. '
        + (d.can_seed ? 'Press <b>Add the Ontario checklist</b> to list the policies Ontario licensing asks for, then upload or write each one.' : 'Press <b>Add policy</b> to add your first one.')) + '</div>';
    } else {
      h += '<div class="kt-card"><table data-kt-no-bulk><thead><tr><th>Policy</th><th>Version</th><th>Status</th><th>Signed</th><th></th></tr></thead><tbody>'
        + d.policies.map(function (p) {
          var tags = [p.required ? chip('required', 'Required', regRef(p.reg_ref) || 'Required by O. Reg. 137/15') : chip('recommended', 'Recommended')];
          if (p.reg_ref) tags.push('<span class="pl-sub">' + esc(regRef(p.reg_ref)) + '</span>');
          if (p.required_from) tags.push(chip('new27', 'Required from ' + day(p.required_from)));
          if (p.centre_name) tags.push(chip('centre', p.centre_name));
          if (p.contraventions) tags.push(chip('review_overdue', p.contraventions + ' contravention' + (p.contraventions === 1 ? '' : 's')));
          var signed = p.current_version_id
            ? '<div style="font-weight:700;">' + p.staff_signed + ' / ' + p.staff_total + (p.pct_signed != null ? ' · ' + p.pct_signed + '%' : '') + '</div><div class="pl-bar"><i style="width:' + (p.pct_signed || 0) + '%"></i></div>'
            : '<span class="pl-sub">—</span>';
          var btns = '<button class="pl-b" data-act="open" data-id="' + p.id + '" title="Details, versions and sign-offs">Open</button>';
          if (p.current_version_id) btns += '<button class="pl-b" data-act="view" data-id="' + p.id + '" title="Read the current version">View</button>';
          if (p.can_edit && !p.archived) btns += '<button class="pl-b" data-act="publish" data-id="' + p.id + '" title="Upload or write a new version">' + (p.current_version_id ? 'Publish new version' : 'Add text') + '</button>';
          if (p.can_edit && p.current_version_id && !p.archived) btns += '<button class="pl-b" data-act="reviewed" data-id="' + p.id + '" title="Reviewed with no change">Mark reviewed</button>';
          if (p.current_version_id && !p.archived) btns += '<button class="pl-b" data-act="training" data-id="' + p.id + '">Record training</button>';
          if (!p.archived) btns += '<button class="pl-b" data-act="contra" data-id="' + p.id + '">Record contravention</button>';
          if (p.can_edit) btns += '<button class="pl-b" data-act="edit" data-id="' + p.id + '">Edit details</button>';
          if (p.can_edit) btns += '<button class="pl-b" data-act="' + (p.archived ? 'restore' : 'archive') + '" data-id="' + p.id + '">' + (p.archived ? 'Restore' : 'Archive') + '</button>';
          return '<tr><td style="min-width:220px;"><div style="font-weight:700;">' + esc(p.title) + '</div>'
            + (p.purpose ? '<div class="pl-sub">' + esc(p.purpose) + '</div>' : '')
            + '<div class="pl-row" style="gap:5px;margin-top:4px;">' + tags.join('') + '</div></td>'
            + '<td style="white-space:nowrap;">' + (p.version ? 'v' + p.version + '<div class="pl-sub">' + esc((p.published_at || '').replace(/ \d+:\d+ [AP]M$/, '')) + '</div>' : '<span class="pl-sub">—</span>') + '</td>'
            + '<td>' + statusChip(p) + (p.owner_name ? '<div class="pl-sub" style="margin-top:3px;">Owner: ' + esc(p.owner_name) + '</div>' : '') + '</td>'
            + '<td>' + signed + '</td>'
            + '<td style="text-align:center;white-space:nowrap;">' + btns + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    if (d.is_admin) {
      h += '<div class="kt-card" style="margin-top:12px;"><label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="checkbox" id="pl-rem"' + (d.reminders ? ' checked' : '') + '> '
        + 'Weekly reminders (Mondays): staff with policies to sign, and a summary for directors and admins with reviews due in the next 30 days</label></div>';
    }
    pane.innerHTML = h;

    var byId = {}; d.policies.forEach(function (p) { byId[p.id] = p; });
    var fc = pane.querySelector('#pl-fc'); if (fc) fc.addEventListener('change', function () { adm.centre = this.value; paintLibrary(pane); });
    var ar = pane.querySelector('#pl-arch'); if (ar) ar.addEventListener('change', function () { adm.archived = this.checked; paintLibrary(pane); });
    var add = pane.querySelector('#pl-add'); if (add) add.addEventListener('click', function () { editPolicy(null, d, function () { paintLibrary(pane); }); });
    var seed = pane.querySelector('#pl-seed');
    if (seed) seed.addEventListener('click', async function () {
      var ok = KT.confirm ? await KT.confirm({ title: 'Add the Ontario checklist?', description: 'Adds ' + d.seed_missing + ' policies that Ontario licensing (O. Reg. 137/15, as amended) asks for — titles and a one-line purpose only, marked required or recommended. You then upload or write your own text for each. Nothing already in your library is changed.', okLabel: 'Add them' }) : true;
      if (!ok) return;
      try { var r = await Api().post('/policies/seed-ontario', {}); toast('🍁', r.added + ' policies added', 'Upload or write the text for each one.', '#16A34A'); paintLibrary(pane); }
      catch (e) { toast('⚠️', 'Could not add', (e && e.message) || '', '#DC2626'); }
    });
    var rem = pane.querySelector('#pl-rem');
    if (rem) rem.addEventListener('change', function () {
      Api().post('/policies/reminders', { enabled: rem.checked }).then(function () { toast('🔔', rem.checked ? 'Weekly reminders on' : 'Weekly reminders off', '', '#16A34A'); })
        .catch(function (e) { rem.checked = !rem.checked; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    });
    pane.__plById = byId; pane.__plData = d;
    if (pane.__plWired) return;
    pane.__plWired = true;
    pane.addEventListener('click', function (ev) {
      var b = ev.target.closest && ev.target.closest('button[data-act]');
      if (!b || !pane.contains(b)) return;
      var p = (pane.__plById || {})[b.getAttribute('data-id')]; if (!p) return;
      var d = pane.__plData;
      var act = b.getAttribute('data-act'), again = function () { paintLibrary(pane); };
      if (act === 'open') openDetail(p, d, again);
      else if (act === 'view') openVersion(p.current_version_id, p.title + ' (v' + p.version + ')', p.has_file, p.file_name);
      else if (act === 'publish') publishVersion(p, again);
      else if (act === 'reviewed') markReviewed(p, again);
      else if (act === 'training') recordTraining(p, again);
      else if (act === 'contra') recordContravention(p, d, again);
      else if (act === 'edit') editPolicy(p, d, again);
      else if (act === 'archive' || act === 'restore') archivePolicy(p, act === 'archive', again);
    });
  }

  function editPolicy(p, d, done) {
    var isNew = !p;
    var centreOpts = (d.is_admin ? '<option value="">Agency-wide (every centre)</option>' : '')
      + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (p && String(p.centre_id) === String(c.id) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('');
    var body = '<label class="pl-l">Title</label><input class="pl-i" id="pe-t" maxlength="190" style="width:100%;" value="' + esc(p ? p.title : '') + '">'
      + '<label class="pl-l">Purpose (one line)</label><input class="pl-i" id="pe-p" maxlength="500" style="width:100%;" value="' + esc(p ? p.purpose || '' : '') + '">'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0 10px;">'
      + '<div><label class="pl-l">Category</label><select class="pl-i" id="pe-c" style="width:100%;"><option value=""></option>' + (d.categories || []).map(function (c) { return '<option' + (p && p.category === c ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="pl-l">Applies to</label><select class="pl-i" id="pe-ce" style="width:100%;">' + centreOpts + '</select></div>'
      + '<div><label class="pl-l">Review every (months)</label><input class="pl-i" id="pe-f" type="number" min="1" max="60" style="width:100%;" value="' + (p ? p.review_frequency_months : 12) + '"></div>'
      + '<div><label class="pl-l">Owner</label><select class="pl-i" id="pe-o" style="width:100%;"><option value="">—</option>' + (d.owners || []).map(function (o) { return '<option value="' + o.id + '"' + (p && String(p.owner_user_id) === String(o.id) ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join('') + '</select></div>'
      + (p && p.current_version_id ? '<div><label class="pl-l">Next review due</label><input class="pl-i" id="pe-n" type="date" style="width:100%;" value="' + esc(p.next_review_due || '') + '"></div>' : '')
      + '</div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13.5px;cursor:pointer;"><input type="checkbox" id="pe-r"' + (p && p.required ? ' checked' : '') + '> Required (counts in the training matrix)</label>'
      + (isNew ? '<div class="pl-sub" style="margin-top:10px;">After saving, use <b>Add text</b> to upload the document or write it.</div>' : '');
    var h = modal({ title: isNew ? 'Add a policy' : 'Edit policy details', body: body, actions: [
      { label: 'Cancel' },
      { label: isNew ? 'Add policy' : 'Save', primary: true, handler: async function () {
        var t = q(h, '#pe-t').value.trim();
        if (!t) { toast('⚠️', 'Give the policy a title', '', '#DC2626'); return false; }
        var payload = { title: t, purpose: q(h, '#pe-p').value.trim() || null, category: q(h, '#pe-c').value || null,
          centre_id: q(h, '#pe-ce').value ? +q(h, '#pe-ce').value : null, review_frequency_months: +q(h, '#pe-f').value || 12,
          owner_user_id: q(h, '#pe-o').value ? +q(h, '#pe-o').value : null, required: q(h, '#pe-r').checked };
        if (q(h, '#pe-n')) payload.next_review_due = q(h, '#pe-n').value || null;
        if (isNew) await Api().post('/policies', payload); else await Api().patch('/policies/' + p.id, payload);
        toast('📘', isNew ? 'Policy added' : 'Saved', '', '#16A34A'); done();
      } }] });
  }

  function publishVersion(p, done) {
    var first = !p.current_version_id;
    var h;
    var body = '<div class="pl-sub" style="margin-bottom:6px;">' + (first ? 'Upload your policy document or write it here. ' : 'Publishing a new version asks every staff member it applies to to read and sign it again. ')
      + 'Staff are told by bell and email.</div>'
      + '<label class="pl-l">' + (first ? 'Summary' : 'What changed') + ' (required)</label><input class="pl-i" id="pv-s" maxlength="1000" style="width:100%;" placeholder="' + (first ? 'e.g. First version of our sleep policy' : 'e.g. Added counting at every transition') + '">'
      + '<div class="pl-row" style="margin-top:12px;gap:14px;">'
      + '<label style="display:flex;gap:6px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="radio" name="pv-m" value="file" checked> Upload a document</label>'
      + '<label style="display:flex;gap:6px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="radio" name="pv-m" value="text"> Write or paste text</label></div>'
      + '<div id="pv-file" style="margin-top:10px;"><input type="file" id="pv-f" accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.png,.jpg,.jpeg" class="pl-i" style="width:100%;padding-top:4px;"><div class="pl-sub" style="margin-top:4px;">PDF or Word, up to 20 MB.</div></div>'
      + '<div id="pv-text" style="display:none;margin-top:10px;"><textarea class="pl-i" id="pv-t" rows="14" style="width:100%;" placeholder="Paste or type the policy. A blank line starts a new paragraph; lines starting with - become a list; # starts a heading."></textarea></div>';
    h = modal({ title: (first ? 'Add text: ' : 'Publish a new version: ') + p.title, large: true, body: body, actions: [
      { label: 'Cancel' },
      { label: first ? 'Publish' : 'Publish version ' + ((p.version || 0) + 1), primary: true, busyLabel: 'Publishing…', handler: async function () {
        var summary = q(h, '#pv-s').value.trim();
        if (summary.length < 3) { toast('⚠️', 'Say what changed', 'A short summary is required — staff see it.', '#DC2626'); return false; }
        var mode = (q(h, 'input[name="pv-m"]:checked') || {}).value;
        var fd = new FormData(); fd.append('change_summary', summary);
        if (mode === 'file') {
          var f = q(h, '#pv-f').files[0];
          if (!f) { toast('⚠️', 'Choose a file', '', '#DC2626'); return false; }
          if (f.size > 20 * 1024 * 1024) { toast('⚠️', 'That file is over 20 MB', '', '#DC2626'); return false; }
          fd.append('file', f);
        } else {
          var t = q(h, '#pv-t').value.trim();
          if (!t) { toast('⚠️', 'Write or paste the policy text', '', '#DC2626'); return false; }
          fd.append('body_text', t);
        }
        var r = await (Api().postForm ? Api().postForm('/policies/' + p.id + '/versions', fd) : Api().post('/policies/' + p.id + '/versions', fd));
        toast('📘', 'Version ' + r.version + ' published', 'Staff have been asked to read and sign it.', '#16A34A'); done();
      } }] });
    var swap = function () {
      var m = (q(h, 'input[name="pv-m"]:checked') || {}).value;
      q(h, '#pv-file').style.display = m === 'file' ? '' : 'none';
      q(h, '#pv-text').style.display = m === 'text' ? '' : 'none';
    };
    h.backdrop.querySelectorAll('input[name="pv-m"]').forEach(function (r) { r.addEventListener('change', swap); });
    if (!first && p.has_text && !p.has_file) {
      Api().get('/policies/' + p.id).then(function (x) {
        if (x && x.policy && x.policy.current_text && q(h, '#pv-t')) { q(h, '#pv-t').value = x.policy.current_text; q(h, 'input[name="pv-m"][value="text"]').checked = true; swap(); }
      }).catch(function () {});
    }
  }

  async function markReviewed(p, done) {
    var notes = KT.prompt ? await KT.prompt({ title: 'Mark "' + p.title + '" reviewed', description: 'Reviewed and nothing needs to change. Staff sign-offs stand, and the next review is set ' + p.review_frequency_months + ' months from today. (If something changes, publish a new version instead.)', fields: [{ key: 'notes', label: 'Notes (optional)', placeholder: 'e.g. Reviewed at the staff meeting' }], okLabel: 'Mark reviewed' }) : '';
    if (notes === null || notes === undefined || notes === false) return;
    if (notes && typeof notes === 'object') notes = notes.notes || '';
    try { var r = await Api().post('/policies/' + p.id + '/reviewed', { notes: (typeof notes === 'string' ? notes.trim() : '') || null }); toast('✅', 'Reviewed', 'Next review ' + day(r.next_review_due), '#16A34A'); done(); }
    catch (e) { toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); }
  }

  async function archivePolicy(p, on, done) {
    var ok = KT.confirm ? await KT.confirm({ title: (on ? 'Archive ' : 'Restore ') + '"' + p.title + '"?', description: on ? 'It leaves the current list and the training matrix, and staff are no longer asked to sign it. Its versions and sign-offs stay on file.' : 'It returns to the library, and staff who have not signed the current version are asked to.', okLabel: on ? 'Archive' : 'Restore' }) : true;
    if (!ok) return;
    try { await Api().post('/policies/' + p.id + '/archive', { archived: on }); done(); }
    catch (e) { toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); }
  }

  async function recordTraining(p, done) {
    var x;
    try { x = await Api().get('/policies/' + p.id); } catch (e) { toast('⚠️', 'Could not load staff', (e && e.message) || '', '#DC2626'); return; }
    var today = (KT.agencyToday && KT.agencyToday()) || new Date().toISOString().slice(0, 10);
    var body = '<div class="pl-sub">Training on each required policy is expected from January 1, 2027 (O. Reg. 137/15 s.58(1.1)). Record who attended.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0 10px;">'
      + '<div><label class="pl-l">Date</label><input class="pl-i" id="pt-d" type="date" max="' + today + '" value="' + today + '" style="width:100%;"></div>'
      + '<div><label class="pl-l">Given by</label><input class="pl-i" id="pt-b" maxlength="190" style="width:100%;"></div></div>'
      + '<label class="pl-l">Notes</label><input class="pl-i" id="pt-n" maxlength="1000" style="width:100%;">'
      + '<label class="pl-l">Staff trained</label><label style="display:flex;gap:6px;align-items:center;font-size:13px;margin-bottom:6px;cursor:pointer;"><input type="checkbox" id="pt-all"> Everyone</label>'
      + '<div style="max-height:260px;overflow:auto;border:1px solid #E2E8F0;border-radius:8px;padding:6px 10px;">'
      + (x.people.length ? x.people.map(function (s) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;padding:4px 0;cursor:pointer;"><input type="checkbox" class="pt-u" value="' + s.user_id + '"> ' + esc(s.name)
        + (s.trained_on ? ' <span class="pl-sub">(trained ' + day(s.trained_on) + ')</span>' : '') + '</label>'; }).join('') : '<div class="pl-sub">No staff this policy applies to.</div>')
      + '</div>';
    var h = modal({ title: 'Record training: ' + p.title, body: body, actions: [
      { label: 'Cancel' },
      { label: 'Save', primary: true, handler: async function () {
        var ids = Array.prototype.map.call(h.backdrop.querySelectorAll('.pt-u:checked'), function (c) { return +c.value; });
        if (!ids.length) { toast('⚠️', 'Tick who was trained', '', '#DC2626'); return false; }
        await Api().post('/policies/' + p.id + '/training', { user_ids: ids, trained_on: q(h, '#pt-d').value, delivered_by: q(h, '#pt-b').value.trim() || null, notes: q(h, '#pt-n').value.trim() || null });
        toast('🎓', 'Training recorded for ' + ids.length, '', '#16A34A'); done();
      } }] });
    var all = q(h, '#pt-all');
    if (all) all.addEventListener('change', function () { h.backdrop.querySelectorAll('.pt-u').forEach(function (c) { c.checked = all.checked; }); });
  }

  function recordContravention(p, d, done) {
    var centres = p.centre_id ? d.centres.filter(function (c) { return c.id === p.centre_id; }) : d.centres;
    var policies = d.policies.filter(function (x) { return !x.archived; });
    var body = (p ? '' : '<label class="pl-l">Policy</label><select class="pl-i" id="pc-p" style="width:100%;">' + policies.map(function (x) { return '<option value="' + x.id + '">' + esc(x.title) + '</option>'; }).join('') + '</select>')
      + '<div class="pl-sub">' + (p ? 'Policy: <b>' + esc(p.title) + '</b>. ' : '') + 'A written record of a contravention and what was done about it (O. Reg. 137/15 s.6.1).</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0 10px;">'
      + '<div><label class="pl-l">Centre</label><select class="pl-i" id="pc-c" style="width:100%;">' + centres.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="pl-l">When</label><input class="pl-i" id="pc-w" type="datetime-local" value="' + agencyNowLocal(d.timezone) + '" style="width:100%;"></div></div>'
      + '<label class="pl-l">What happened</label><textarea class="pl-i" id="pc-d" rows="4" maxlength="5000" style="width:100%;"></textarea>'
      + '<label class="pl-l">Action taken</label><textarea class="pl-i" id="pc-a" rows="3" maxlength="5000" style="width:100%;"></textarea>'
      + '<label class="pl-l">Staff involved (optional)</label><input class="pl-i" id="pc-s" maxlength="500" style="width:100%;">';
    if (!centres.length) { toast('⚠️', 'No centre to record against', '', '#DC2626'); return; }
    var h = modal({ title: 'Record a contravention', body: body, actions: [
      { label: 'Cancel' },
      { label: 'Record', primary: true, handler: async function () {
        var pid = p ? p.id : +q(h, '#pc-p').value;
        var desc = q(h, '#pc-d').value.trim();
        if (desc.length < 3) { toast('⚠️', 'Describe what happened', '', '#DC2626'); return false; }
        await Api().post('/policies/' + pid + '/contraventions', { centre_id: +q(h, '#pc-c').value, occurred_at: q(h, '#pc-w').value, description: desc,
          action_taken: q(h, '#pc-a').value.trim() || null, staff_involved: q(h, '#pc-s').value.trim() || null });
        toast('📝', 'Contravention recorded', '', '#16A34A'); done();
      } }] });
  }

  async function openDetail(p, d, done) {
    var x;
    try { x = await Api().get('/policies/' + p.id); } catch (e) { toast('⚠️', 'Could not open', (e && e.message) || '', '#DC2626'); return; }
    var st = { signed: ['#ECFDF5', '#047857', 'Signed'], outdated: ['#FFFBEB', '#B45309', 'Signed an older version'], outstanding: ['#FEF2F2', '#B91C1C', 'Not signed'], no_text: ['#F1F5F9', '#475569', '—'] };
    var pol = x.policy;
    var signedN = x.people.filter(function (s) { return s.state === 'signed'; }).length;
    var b = '<div class="pl-row" style="gap:6px;margin-bottom:6px;">' + (pol.required ? chip('required', 'Required') : chip('recommended', 'Recommended'))
      + (pol.reg_ref ? '<span class="pl-sub">' + esc(regRef(pol.reg_ref)) + '</span>' : '') + (pol.required_from ? chip('new27', 'Required from ' + day(pol.required_from)) : '') + '</div>'
      + (pol.purpose ? '<div class="pl-sub" style="font-size:13.5px;">' + esc(pol.purpose) + '</div>' : '')
      + '<div class="pl-sub" style="margin-top:6px;">Reviewed ' + day(pol.last_reviewed_at) + ' · next review ' + day(pol.next_review_due) + ' · every ' + pol.review_frequency_months + ' months</div>'
      + '<div style="font-weight:800;margin:14px 0 6px;">Versions</div>'
      + (x.versions.length ? '<div style="overflow-x:auto;"><table data-kt-no-filter data-kt-no-bulk style="width:100%;font-size:13px;"><thead><tr><th>Version</th><th>Published</th><th>What changed</th><th>Signed</th><th></th></tr></thead><tbody>'
        + x.versions.map(function (v) {
          return '<tr><td>v' + v.version + (v.id === pol.current_version_id ? ' ' + chip('current', 'Current') : '') + '</td><td>' + esc(v.published_at || '') + '<div class="pl-sub">' + esc(v.published_by_name || '') + '</div></td><td>' + esc(v.change_summary) + '</td><td>' + v.signatures + '</td>'
            + '<td style="text-align:center;white-space:nowrap;"><button class="pl-b pd-v" type="button" data-v="' + v.id + '" data-f="' + (v.has_file ? 1 : 0) + '" data-n="' + esc(v.file_name || '') + '" data-ver="' + v.version + '">View</button></td></tr>';
        }).join('') + '</tbody></table></div>' : '<div class="pl-sub">No text yet.</div>')
      + '<div style="font-weight:800;margin:14px 0 6px;">Staff (' + signedN + ' of ' + x.people.length + ' signed the current version)</div>'
      + (x.people.length ? '<div style="overflow-x:auto;"><table data-kt-no-filter data-kt-no-bulk style="width:100%;font-size:13px;"><thead><tr><th>Name</th><th>Centre</th><th>Status</th><th>Trained</th></tr></thead><tbody>'
        + x.people.map(function (s) { var c = st[s.state] || st.no_text; return '<tr><td>' + esc(s.name) + '</td><td>' + esc(s.centres) + '</td><td><span class="pl-chip" data-kt-no-tone style="background:' + c[0] + ';color:' + c[1] + ';">' + c[2] + (s.state === 'outdated' ? ' (v' + s.signed_version + ')' : '') + '</span>' + (s.signed_at ? '<div class="pl-sub">' + esc(s.signed_at) + '</div>' : '') + '</td><td>' + (s.trained_on ? day(s.trained_on) : '—') + '</td></tr>'; }).join('')
        + '</tbody></table></div>' : '<div class="pl-sub">No staff this policy applies to.</div>')
      + '<div style="font-weight:800;margin:14px 0 6px;">Reviews</div>'
      + (x.reviews.length ? x.reviews.map(function (r) { return '<div class="pl-sub" style="padding:3px 0;">' + day(r.reviewed_on) + ' · ' + esc(r.by_name || '') + ' · ' + (r.changed ? 'new version' : 'no change') + (r.notes ? ' — ' + esc(r.notes) : '') + '</div>'; }).join('') : '<div class="pl-sub">None yet.</div>')
      + '<div style="font-weight:800;margin:14px 0 6px;">Training given</div>'
      + (x.trainings.length ? x.trainings.map(function (t) { return '<div class="pl-sub" style="padding:3px 0;">' + day(t.trained_on) + ' · ' + esc(t.staff_name) + (t.delivered_by ? ' · by ' + esc(t.delivered_by) : '') + (t.notes ? ' — ' + esc(t.notes) : '') + '</div>'; }).join('') : '<div class="pl-sub">None recorded.</div>')
      + '<div style="font-weight:800;margin:14px 0 6px;">Contraventions</div>'
      + (x.contraventions.length ? x.contraventions.map(function (c) { return '<div style="padding:6px 0;border-top:1px solid #F1F5F9;font-size:13px;"><b>' + esc(c.occurred_at) + '</b> · ' + esc(c.centre_name) + '<div>' + esc(c.description) + '</div>' + (c.action_taken ? '<div class="pl-sub">Action: ' + esc(c.action_taken) + '</div>' : '') + '<div class="pl-sub">Recorded by ' + esc(c.by_name || '') + '</div></div>'; }).join('') : '<div class="pl-sub">None recorded.</div>');
    var h = modal({ title: pol.title, large: true, body: b, actions: [{ label: 'Close' }] });
    h.backdrop.querySelectorAll('.pd-v').forEach(function (btn) {
      btn.addEventListener('click', function () { openVersion(+btn.getAttribute('data-v'), pol.title + ' (v' + btn.getAttribute('data-ver') + ')', btn.getAttribute('data-f') === '1', btn.getAttribute('data-n')); });
    });
  }

  /* ── training matrix ── */
  async function paintMatrix(pane) {
    var qs = [];
    if (adm.mxCentre) qs.push('centre_id=' + adm.mxCentre);
    if (adm.mxAll) qs.push('all=1');
    var d;
    try { d = await Api().get('/policies/matrix' + (qs.length ? '?' + qs.join('&') : '')); }
    catch (e) { pane.innerHTML = '<div class="kt-card" style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load the matrix.') + '</div>'; return; }
    var cell = {
      signed: ['#ECFDF5', '#047857', '✓', 'Signed'], outdated: ['#FFFBEB', '#B45309', '!', 'Signed an older version'],
      outstanding: ['#FEF2F2', '#B91C1C', '✕', 'Not signed'], na: ['transparent', '#CBD5E1', '·', 'Does not apply'],
    };
    var h = '<div class="pl-row" style="margin-bottom:10px;">'
      + '<select class="pl-i" id="mx-c"><option value="">All centres</option>' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(adm.mxCentre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>'
      + '<label class="pl-sub" style="display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" id="mx-a"' + (adm.mxAll ? ' checked' : '') + '> Include recommended policies</label>'
      + '<span style="flex:1"></span><button class="pl-b" id="mx-csv">⬇ Export CSV</button></div>'
      + '<div class="pl-sub" style="margin-bottom:8px;">' + ['signed', 'outdated', 'outstanding'].map(function (k) { var c = cell[k]; return '<span class="pl-c" style="background:' + c[0] + ';color:' + c[1] + ';margin-right:4px;">' + c[2] + '</span>' + c[3]; }).join(' &nbsp; ')
      + ' &nbsp; 🎓 trained on the policy</div>';
    if (!d.policies.length) {
      h += '<div class="kt-card pl-sub">No published ' + (adm.mxAll ? '' : 'required ') + 'policies yet. Publish a policy in the Library first.</div>';
    } else if (!d.staff.length) {
      h += '<div class="kt-card pl-sub">No staff found for this centre.</div>';
    } else {
      h += '<div class="kt-card kt-no-mcards" style="padding:0;overflow-x:auto;" data-kt-allow-overflow><table class="pl-mx" data-kt-no-filter data-kt-no-bulk data-kt-no-search><thead><tr><th style="min-width:150px;">Staff member</th><th>To sign</th>'
        + d.policies.map(function (p) { return '<th title="' + esc(p.title) + ' (version ' + p.version + ')">' + esc(p.title.length > 34 ? p.title.slice(0, 32) + '…' : p.title) + '<div style="font-weight:600;color:#94A3B8;">v' + p.version + '</div></th>'; }).join('')
        + '</tr></thead><tbody>'
        + d.staff.map(function (s) {
          return '<tr><td><div style="font-weight:700;">' + esc(s.name) + '</div><div class="pl-sub" style="font-size:11.5px;">' + esc(s.centres) + '</div></td>'
            + '<td>' + (s.outstanding ? '<b style="color:#B91C1C;">' + s.outstanding + '</b>' : '<span style="color:#047857;font-weight:700;">0</span>') + '</td>'
            + s.cells.map(function (c, i) {
              var k = cell[c.state] || cell.na, p = d.policies[i];
              var tip = s.name + ' — ' + p.title + ': ' + k[3] + (c.at ? ' ' + c.at : '') + (c.state === 'outdated' ? ' (v' + c.version + ')' : '') + (c.trained_on ? '; trained ' + c.trained_on : '');
              return '<td title="' + esc(tip) + '"><span class="pl-c" style="background:' + k[0] + ';color:' + k[1] + ';">' + k[2] + '</span>' + (c.trained_on ? '<span style="font-size:11px;"> 🎓</span>' : '') + '</td>';
            }).join('') + '</tr>';
        }).join('') + '</tbody></table></div>';
    }
    pane.innerHTML = h;
    var mc = pane.querySelector('#mx-c'); if (mc) mc.addEventListener('change', function () { adm.mxCentre = this.value; paintMatrix(pane); });
    var ma = pane.querySelector('#mx-a'); if (ma) ma.addEventListener('change', function () { adm.mxAll = this.checked; paintMatrix(pane); });
    var csv = pane.querySelector('#mx-csv');
    if (csv) csv.addEventListener('click', function () {
      var url = apiBase() + '/policies/matrix?format=csv' + (qs.length ? '&' + qs.join('&') : '');
      csv.disabled = true;
      KT.fetchFileBlob(url).then(function (blob) {
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'policy-training-matrix.csv';
        document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
      }).catch(function (e) { toast('⚠️', 'Could not export', (e && e.message) || '', '#DC2626'); })
        .then(function () { csv.disabled = false; });
    });
  }

  /* ── contraventions log ── */
  async function paintContraventions(pane) {
    var d, lib;
    try { d = await Api().get('/policy-contraventions'); lib = adm.data || await Api().get('/policies'); adm.data = lib; }
    catch (e) { pane.innerHTML = '<div class="kt-card" style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load.') + '</div>'; return; }
    var h = '<div class="pl-row" style="margin-bottom:10px;"><div class="pl-sub" style="flex:1;min-width:200px;">The written record of contraventions of your policies and the action taken (O. Reg. 137/15 s.6.1). Kept on file.</div>'
      + (lib.policies.length ? '<button class="pl-b p" id="ct-add">+ Record contravention</button>' : '') + '</div>';
    h += d.contraventions.length
      ? '<div class="kt-card"><table data-kt-no-bulk><thead><tr><th>When</th><th>Policy</th><th>Centre</th><th>What happened</th><th>Action taken</th><th>Recorded by</th></tr></thead><tbody>'
        + d.contraventions.map(function (c) { return '<tr><td style="white-space:nowrap;">' + esc(c.occurred_at) + '</td><td>' + esc(c.policy_title) + '</td><td>' + esc(c.centre_name) + '</td><td>' + esc(c.description) + (c.staff_involved ? '<div class="pl-sub">Staff: ' + esc(c.staff_involved) + '</div>' : '') + '</td><td>' + esc(c.action_taken || '') + '</td><td>' + esc(c.by_name || '') + '</td></tr>'; }).join('')
        + '</tbody></table></div>'
      : '<div class="kt-card pl-sub">None recorded.</div>';
    pane.innerHTML = h;
    var add = pane.querySelector('#ct-add');
    if (add) add.addEventListener('click', function () { recordContravention(null, lib, function () { paintContraventions(pane); }); });
  }

  /* ═════════════════════════ staff: #my-policies ═════════════════════════ */
  async function renderMine(main) {
    css();
    main.innerHTML = '<div class="pl-wrap" style="color:#64748B;">Loading…</div>';
    var d;
    try { d = await Api().get('/my-policies'); }
    catch (e) { main.innerHTML = '<div class="pl-wrap" style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load your policies.') + '</div>'; return; }
    var h = '<div class="pl-wrap"><div class="kt-hero" style="background:linear-gradient(135deg,#0B2545 0%,#13315C 55%,#1F6FB2 100%);">'
      + '<div class="kt-hero-greet">📘 POLICIES</div><h1>Policies to review</h1>'
      + '<div class="kt-hero-sub">Read each policy, then sign that you have read and understand it. You are asked again whenever a policy changes.</div></div>';
    if (!d.is_staff) {
      h += '<div class="pl-card pl-sub" style="margin-top:14px;">Policy sign-offs are for staff working with children.</div></div>';
      main.innerHTML = h; return;
    }
    h += '<div style="margin-top:14px;">';
    if (!d.outstanding.length) {
      h += '<div class="pl-card" style="display:flex;gap:10px;align-items:center;"><div style="font-size:24px;">✅</div><div><div style="font-weight:800;">You are all caught up</div><div class="pl-sub">Every policy that applies to you is signed.</div></div></div>';
    } else {
      h += '<div style="font-weight:800;font-size:15px;margin:0 0 8px;">To sign (' + d.outstanding.length + ')</div>';
      d.outstanding.forEach(function (r) {
        var upd = r.previously_signed_version ? '<div style="margin-top:6px;font-size:13px;background:#FFFBEB;color:#92400E;border-radius:8px;padding:7px 10px;"><b>Updated</b> since you signed version ' + r.previously_signed_version + '. What changed: ' + esc(r.change_summary) + '</div>' : '';
        h += '<div class="pl-card" data-v="' + r.version_id + '">'
          + '<div style="display:flex;gap:8px;align-items:flex-start;"><div style="flex:1;min-width:0;"><div style="font-weight:800;font-size:15px;">' + esc(r.title) + '</div>'
          + '<div class="pl-sub">Version ' + r.version + (r.published_at ? ' · ' + esc(r.published_at.replace(/ \d+:\d+ [AP]M$/, '')) : '') + (r.required ? ' · required' : '') + '</div></div></div>'
          + upd
          + '<div class="pl-row" style="margin-top:10px;"><button class="pl-b p mp-read" type="button" data-v="' + r.version_id + '" data-f="' + (r.has_file ? 1 : 0) + '" data-t="' + esc(r.title) + '">📖 Read policy</button>'
          + '<span class="pl-sub mp-read-note">' + (r.viewed ? 'Opened ✓' : 'Open it before signing') + '</span></div>'
          + '<div class="pl-sign"' + (r.viewed ? '' : ' style="opacity:.55;"') + '>'
          + '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;cursor:pointer;line-height:1.45;"><input type="checkbox" class="mp-agree" style="margin-top:3px;"' + (r.viewed ? '' : ' disabled') + '> I have read and understand this policy, and I will follow it.</label>'
          + '<div class="pl-row" style="margin-top:8px;"><input class="pl-i mp-name" placeholder="Type your full name" maxlength="190" autocomplete="name" style="flex:1;min-width:180px;"' + (r.viewed ? '' : ' disabled') + '>'
          + '<button class="pl-b p mp-sign" type="button"' + (r.viewed ? '' : ' disabled') + '>✍️ Sign</button></div>'
          + '<div class="pl-sub mp-msg" style="margin-top:4px;"></div></div></div>';
      });
    }
    if (d.signed.length) {
      h += '<div style="font-weight:800;font-size:15px;margin:16px 0 8px;">Signed (' + d.signed.length + ')</div><div class="pl-card" style="padding:4px 14px;">'
        + d.signed.map(function (s) { return '<div style="display:flex;gap:8px;align-items:center;padding:8px 0;border-top:1px solid #F1F5F9;"><div style="flex:1;min-width:0;"><div style="font-weight:700;font-size:13.5px;">' + esc(s.title) + '</div><div class="pl-sub">Version ' + s.version + ' · signed ' + esc(s.signed_at || '') + '</div></div>'
          + '<button class="pl-b mp-view" type="button" data-v="' + s.version_id + '" data-t="' + esc(s.title) + '">View</button></div>'; }).join('').replace('border-top:1px solid #F1F5F9;', '')
        + '</div>';
    }
    h += '</div></div>';
    main.innerHTML = h;

    var byV = {}; d.outstanding.forEach(function (r) { byV[r.version_id] = r; });
    function enable(card) {
      var sign = card.querySelector('.pl-sign'); if (sign) sign.style.opacity = '';
      card.querySelectorAll('.mp-agree,.mp-name,.mp-sign').forEach(function (el) { el.disabled = false; });
      var n = card.querySelector('.mp-read-note'); if (n) n.textContent = 'Opened ✓';
    }
    main.querySelectorAll('.mp-read').forEach(function (b) {
      b.addEventListener('click', function () {
        var card = b.closest('.pl-card'), r = byV[b.getAttribute('data-v')];
        if (!card || !r) return;
        var pr = openVersion(r.version_id, r.title + ' (v' + r.version + ')', r.has_file, r.title);
        Promise.resolve(pr).then(function () { if (card.isConnected) enable(card); }).catch(function (e) { toast('⚠️', 'Could not open', (e && e.message) || '', '#DC2626'); });
      });
    });
    main.querySelectorAll('.mp-view').forEach(function (b) {
      b.addEventListener('click', function () {
        Api().get('/policy-versions/' + b.getAttribute('data-v')).then(function (v) {
          if (v.has_file) openVersion(v.id, v.title + ' (v' + v.version + ')', true, v.file_name); else openVersion(v.id, v.title, false);
        }).catch(function (e) { toast('⚠️', 'Could not open', (e && e.message) || '', '#DC2626'); });
      });
    });
    main.querySelectorAll('.mp-sign').forEach(function (b) {
      b.addEventListener('click', async function () {
        var card = b.closest('.pl-card'); if (!card) return;
        var agree = card.querySelector('.mp-agree'), name = card.querySelector('.mp-name'), msg = card.querySelector('.mp-msg');
        if (!agree || !name || !msg) return;
        msg.style.color = '#B91C1C';
        if (!agree.checked) { msg.textContent = 'Tick the box to confirm you have read and understand it.'; return; }
        if (name.value.trim().length < 3) { msg.textContent = 'Type your full name to sign.'; name.focus(); return; }
        b.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Signing…';
        try {
          await Api().post('/my-policies/' + card.getAttribute('data-v') + '/acknowledge', { typed_name: name.value.trim(), agree: true });
          toast('✍️', 'Signed', '', '#16A34A');
          renderMine(main);
        } catch (e) {
          b.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not sign.';
          if (e && e.status === 409) setTimeout(function () { renderMine(main); }, 1500);
        }
      });
    });
  }

  /* ═════════════════ arrival prompt: once a day, staff only ═════════════════ */
  function prompt() {
    var r = roles();
    if (!r.some(function (x) { return x === 'educator' || x === 'home_visitor' || x === 'centre_director'; })) return;
    if (!KT.Api || !KT.Api.get || !(sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'))) return;
    var today = (KT.agencyToday && KT.agencyToday()) || new Date().toISOString().slice(0, 10);
    var key = 'kt_policies_prompt_' + today;
    try { if (localStorage.getItem(key)) return; } catch (e) { return; }
    KT.Api.get('/my-policies').then(function (d) {
      if (!d || !d.is_staff || !d.outstanding || !d.outstanding.length) return;
      if (/#my-policies/.test(location.hash)) return;
      try { localStorage.setItem(key, '1'); } catch (e) {}
      var n = d.outstanding.length;
      var back = document.createElement('div');
      back.setAttribute('role', 'dialog'); back.setAttribute('aria-modal', 'true');
      back.style.cssText = 'position:fixed;inset:0;background:rgba(13,27,42,.5);z-index:99998;display:flex;align-items:center;justify-content:center;padding:18px;';
      back.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:400px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(15,23,42,.28);text-align:center;">'
        + '<div style="width:56px;height:56px;border-radius:50%;background:#EFF6FF;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 12px;">📘</div>'
        + '<h3 style="margin:0 0 6px;font-size:18px;font-weight:800;color:#0F172A;">' + (n === 1 ? 'A policy to review' : n + ' policies to review') + '</h3>'
        + '<p style="margin:0;font-size:14px;line-height:1.55;color:#475569;">' + esc(d.outstanding.slice(0, 3).map(function (x) { return x.title; }).join(', ')) + (n > 3 ? ' and ' + (n - 3) + ' more' : '') + '. Please read and sign before working with children.</p>'
        + '<div style="display:flex;gap:8px;margin-top:16px;"><button type="button" class="pp-later" data-kt-iconized="1" style="flex:1;height:38px;border-radius:10px;border:1px solid #CBD5E1;background:#fff;font-weight:700;cursor:pointer;">Later</button>'
        + '<button type="button" class="pp-go" data-kt-iconized="1" style="flex:1;height:38px;border-radius:10px;border:0;background:#1F6FB2;color:#fff;font-weight:800;cursor:pointer;">Review now</button></div></div>';
      function close() { if (back.parentNode) back.parentNode.removeChild(back); }
      back.querySelector('.pp-later').addEventListener('click', close);
      back.querySelector('.pp-go').addEventListener('click', function () { close(); location.hash = '#my-policies'; });
      back.addEventListener('click', function (e) { if (e.target === back) close(); });
      document.body.appendChild(back);
    }).catch(function () {});
  }

  function reg() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) { KT.Shell.registerScreen(r + ':policies', renderAdmin); });
    ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':my-policies', renderMine); });
  }
  reg();
  KT.policies = { openVersion: openVersion, prompt: prompt };
  setTimeout(prompt, 4000);
})(window);
