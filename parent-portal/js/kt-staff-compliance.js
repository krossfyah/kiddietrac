/* ═══════════════════════════════════════════════════════════════════════
   kt-staff-compliance.js — staff training, checks and household residents (2026-10-10)

   Roadmap Phase 1 ("go ahead"), driven by the agency's jurisdiction profile:
     · Compliance screen card: every staff member's deadlines (counted from hire date),
       required background checks, registry number and training hours — for directors/admins.
     · Staff window: hire date + registry number (editable by directors/admins), each item's
       status, the training-hours ledger with approve / not approve.
     · #my-training: a staff member's own list and training-hours log.
     · #household: adults living in a home daycare, with their background checks.
   A jurisdiction with no such rules (Ontario today) shows none of this: the menu entries are
   hidden until /my-compliance says the agency has rules (body.kt-jur-staff).
   ═══════════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  function api() { return KT.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function day(d) {
    if (!d) return '—';
    var m = String(d).slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return String(d);
    return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
  }
  var TONE = {
    done: ['#ECFDF5', '#047857', 'Done'], current: ['#ECFDF5', '#047857', 'Current'], due: ['#FFFBEB', '#B45309', 'Due'],
    overdue: ['#FEF2F2', '#B91C1C', 'Overdue'], expired: ['#FEF2F2', '#B91C1C', 'Expired'], missing: ['#FEF2F2', '#B91C1C', 'Not on file'],
    needs_hire_date: ['#F1F5F9', '#475569', 'Set hire date'], pending: ['#FFFBEB', '#B45309', 'Waiting for review'],
    approved: ['#ECFDF5', '#047857', 'Approved'], rejected: ['#F1F5F9', '#64748B', 'Not approved'],
  };
  function pill(st) { var t = TONE[st] || TONE.due; return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + t[0] + ';color:' + t[1] + ';">' + t[2] + '</span>'; }
  var INPUT = 'height:32px;box-sizing:border-box;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;';
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;';
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c); } catch (e) {} }

  /* ── menu visibility ── */
  (function () {
    var st = document.createElement('style');
    st.textContent = 'body:not(.kt-jur-staff) [data-hash="my-training"], body:not(.kt-jur-staff) [href="#my-training"],'
      + 'body:not(.kt-jur-household) [data-hash="household"], body:not(.kt-jur-household) [href="#household"] { display:none !important; }';
    (document.head || document.documentElement).appendChild(st);
  })();
  var probed = false;
  function probe() {
    if (probed || !KT.Api || !KT.Api.get) return;
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (!tok) return;
    probed = true;
    KT.Api.get('/my-compliance').then(function (d) {
      if (d && d.enabled) document.body.classList.add('kt-jur-staff');
    }).catch(function () {});
    KT.Api.get('/household').then(function (d) {
      if (d && d.note) document.body.classList.add('kt-jur-household');
    }).catch(function () {});
  }
  setTimeout(probe, 2500); setTimeout(probe, 8000);

  /* ── an item list (deadlines, checks, registry) ── */
  function itemsTable(items) {
    if (!items || !items.length) return '<div style="color:#64748B;font-size:13px;">Nothing required here.</div>';
    return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="text-align:left;color:#64748B;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">'
      + '<th style="padding:6px 8px;">Requirement</th><th style="padding:6px 8px;">Rule</th><th style="padding:6px 8px;">Status</th><th style="padding:6px 8px;">Date</th></tr></thead><tbody>'
      + items.map(function (i) {
          var when = i.status === 'done' || i.status === 'expired' ? (i.expires ? 'Valid to ' + day(i.expires) : (i.date ? 'On ' + day(i.date) : ''))
            : (i.deadline ? 'Due by ' + day(i.deadline) : (i.value || ''));
          return '<tr style="border-top:1px solid #F1F5F9;"><td style="padding:7px 8px;font-weight:600;color:#0F172A;">' + esc(i.label) + '</td>'
            + '<td style="padding:7px 8px;color:#64748B;">' + esc(i.rule || (i.kind === 'registry' ? (i.value ? i.value : 'Add the number') : '')) + '</td>'
            + '<td style="padding:7px 8px;">' + pill(i.status) + '</td><td style="padding:7px 8px;color:#334155;white-space:nowrap;">' + esc(when) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
  }
  function pdBar(pd) {
    if (!pd) return '';
    var pct = pd.required ? Math.min(100, Math.round(pd.approved / pd.required * 100)) : 100;
    var col = pd.status === 'done' ? '#16A34A' : (pd.status === 'overdue' ? '#DC2626' : '#F59E0B');
    return '<div style="font-size:13px;color:#334155;margin-bottom:4px;"><b>' + pd.approved + '</b> of ' + pd.required + ' training hours approved'
      + (pd.pending ? ' · ' + pd.pending + ' waiting for review' : '') + ' <span style="color:#94A3B8;">(' + day(pd.from) + ' – ' + day(pd.to) + ')</span></div>'
      + '<div style="height:8px;background:#F1F5F9;border-radius:999px;overflow:hidden;"><div style="height:100%;width:' + pct + '%;background:' + col + ';"></div></div>';
  }

  /* ── training-hours ledger (list + add form), for one person ── */
  function renderPd(box, userId, opts) {
    opts = opts || {};
    box.innerHTML = '<div style="color:#64748B;font-size:13px;">Loading…</div>';
    api().get('/pd-hours' + (userId ? '?user_id=' + userId : '')).then(function (d) {
      var rows = d.entries || [], manage = !!d.can_manage, self = !userId;
      box.innerHTML = (d.pd ? '<div style="margin-bottom:12px;">' + pdBar(d.pd) + '</div>' : '')
        + '<form data-add style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;align-items:end;margin-bottom:12px;">'
        + '<label style="font-size:11.5px;font-weight:700;color:#334155;">Date<input type="date" name="taken_on" required style="' + INPUT + 'width:100%;margin-top:3px;"></label>'
        + '<label style="font-size:11.5px;font-weight:700;color:#334155;grid-column:span 2;">Training<input name="title" required maxlength="190" placeholder="e.g. Child abuse prevention" style="' + INPUT + 'width:100%;margin-top:3px;"></label>'
        + '<label style="font-size:11.5px;font-weight:700;color:#334155;">Given by<input name="provider" maxlength="190" placeholder="Optional" style="' + INPUT + 'width:100%;margin-top:3px;"></label>'
        + '<label style="font-size:11.5px;font-weight:700;color:#334155;">Hours<input name="hours" type="number" min="0.25" max="100" step="0.25" required style="' + INPUT + 'width:100%;margin-top:3px;"></label>'
        + '<label style="font-size:11.5px;font-weight:700;color:#334155;">Certificate<input name="certificate" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" style="font-size:12px;width:100%;margin-top:3px;"></label>'
        + '<button type="submit" style="' + BTN + 'background:#1F6080;color:#fff;border:0;">Add hours</button></form>'
        + '<div data-msg style="font-size:12px;min-height:14px;margin:-6px 0 6px;"></div>'
        + (self ? '<div style="font-size:11.5px;color:#94A3B8;margin:-4px 0 8px;">Hours you add are checked by your director or admin before they count.</div>' : '')
        + (rows.length ? '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;"><tbody>' + rows.map(function (r) {
            return '<tr style="border-top:1px solid #F1F5F9;"><td style="padding:7px 8px;white-space:nowrap;color:#334155;">' + day(r.taken_on) + '</td>'
              + '<td style="padding:7px 8px;"><b style="color:#0F172A;">' + esc(r.title) + '</b>' + (r.provider ? '<div style="font-size:12px;color:#64748B;">' + esc(r.provider) + '</div>' : '') + '</td>'
              + '<td style="padding:7px 8px;white-space:nowrap;">' + esc(r.hours) + ' h</td><td style="padding:7px 8px;">' + pill(r.status) + '</td>'
              + '<td style="padding:7px 8px;text-align:right;white-space:nowrap;">'
              + (r.certificate_name ? '<button type="button" data-cert="' + r.id + '" style="' + BTN + 'background:#fff;color:#1F6080;border:1px solid #CBD5E1;">Certificate</button> ' : '')
              + (manage && !self && r.status === 'pending' ? '<button type="button" data-ok="' + r.id + '" style="' + BTN + 'background:#16A34A;color:#fff;border:0;">Approve</button> <button type="button" data-no="' + r.id + '" style="' + BTN + 'background:#fff;color:#B91C1C;border:1px solid #FECACA;">Not approve</button> ' : '')
              + ((manage && !self) || r.status === 'pending' ? '<button type="button" data-del="' + r.id + '" title="Remove" style="' + BTN + 'background:#fff;color:#64748B;border:1px solid #E2E8F0;">✕</button>' : '')
              + '</td></tr>';
          }).join('') + '</tbody></table></div>' : '<div style="color:#64748B;font-size:13px;">No training hours logged yet.</div>');
      var msg = box.querySelector('[data-msg]');
      box.querySelector('[data-add]').addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(e.target);
        if (userId) fd.append('user_id', userId);
        if (!fd.get('certificate') || !fd.get('certificate').size) fd.delete('certificate');
        msg.style.color = '#64748B'; msg.textContent = 'Saving…';
        api().postForm('/pd-hours', fd).then(function (r) {
          toast('🎓', 'Training hours added', r && r.status === 'pending' ? 'Waiting for your director to approve.' : 'Approved.', '#16A34A');
          renderPd(box, userId, opts); if (opts.onChange) opts.onChange();
        }).catch(function (er) { msg.style.color = '#B91C1C'; msg.textContent = (er && er.message) || 'Could not save.'; });
      });
      box.querySelectorAll('[data-ok],[data-no]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-ok') || b.getAttribute('data-no');
          api().post('/pd-hours/' + id + '/review', { approve: !!b.getAttribute('data-ok') }).then(function () { renderPd(box, userId, opts); if (opts.onChange) opts.onChange(); })
            .catch(function (er) { toast('⚠️', 'Could not save', (er && er.message) || '', '#DC2626'); });
        });
      });
      box.querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', async function () {
          if (KT.confirm && !(await KT.confirm({ title: 'Remove these training hours?', description: 'The entry is removed from the log.', okLabel: 'Remove' }))) return;
          api().delete('/pd-hours/' + b.getAttribute('data-del')).then(function () { renderPd(box, userId, opts); if (opts.onChange) opts.onChange(); })
            .catch(function (er) { toast('⚠️', 'Could not remove', (er && er.message) || '', '#DC2626'); });
        });
      });
      box.querySelectorAll('[data-cert]').forEach(function (b) {
        b.addEventListener('click', function () {
          var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
          var h = { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '') };
          var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa;
          fetch(base + '/pd-hours/' + b.getAttribute('data-cert') + '/certificate', { headers: h }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
            .then(function (blob) { if (KT.viewBlob) KT.viewBlob(blob, { title: 'Training certificate', label: 'Certificate' }); else window.open(URL.createObjectURL(blob), '_blank'); })
            .catch(function () { toast('⚠️', 'Could not open the certificate', '', '#DC2626'); });
        });
      });
    }).catch(function (e) { box.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + esc((e && e.message) || 'Could not load.') + '</div>'; });
  }

  /* ── one staff member, in a window (directors/admins) ── */
  function openStaff(userId, onChange) {
    var M = KT.Shell && KT.Shell.Modal; if (!M) return;
    var box = document.createElement('div');
    box.innerHTML = '<div style="color:#64748B;">Loading…</div>';
    M.open({ title: '🎓 Training & checks', body: box, large: true, actions: [{ label: 'Close', primary: true }] });
    function draw() {
      api().get('/admin/staff-compliance/' + userId).then(function (c) {
        box.innerHTML = '<div style="font-size:16px;font-weight:800;color:#0F172A;margin-bottom:10px;">' + esc(c.name) + '</div>'
          + '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:14px;">'
          + '<label style="font-size:11.5px;font-weight:700;color:#334155;">Hire date<br><input type="date" data-hire value="' + esc(c.hire_date || '') + '"' + (c.can_manage ? '' : ' disabled') + ' style="' + INPUT + 'margin-top:3px;"></label>'
          + (c.registry ? '<label style="font-size:11.5px;font-weight:700;color:#334155;">' + esc(c.registry) + '<br><input data-reg maxlength="40" value="' + esc(c.registry_number || '') + '"' + (c.can_manage ? '' : ' disabled') + ' style="' + INPUT + 'margin-top:3px;width:200px;"></label>' : '')
          + (c.can_manage ? '<button type="button" data-savehr style="' + BTN + 'background:#1F6080;color:#fff;border:0;">Save</button>' : '')
          + '<span style="font-size:12px;color:#94A3B8;">Deadlines are counted from the hire date.</span></div>'
          + '<div style="font-weight:800;font-size:13px;color:#334155;margin:4px 0 6px;">Requirements (' + esc(c.jurisdiction) + ')</div>'
          + itemsTable(c.items)
          + '<div style="font-size:12px;color:#64748B;margin:6px 0 14px;">Add certificates and background checks on the staff record (Staff → the person → Certifications / Background checks); this list updates from them.</div>'
          + (c.pd ? '<div style="font-weight:800;font-size:13px;color:#334155;margin:4px 0 6px;">Training hours</div><div data-pd></div>' : '');
        var save = box.querySelector('[data-savehr]');
        if (save) save.onclick = function () {
          var body = { hire_date: box.querySelector('[data-hire]').value || null };
          var reg = box.querySelector('[data-reg]'); if (reg) body.registry_number = reg.value || null;
          api().patch('/admin/staff-compliance/' + userId + '/hr', body).then(function () { toast('✅', 'Saved', '', '#16A34A'); draw(); if (onChange) onChange(); })
            .catch(function (e) { toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
        };
        var pd = box.querySelector('[data-pd]'); if (pd) renderPd(pd, userId, { onChange: onChange });
      }).catch(function (e) { box.innerHTML = '<div style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load.') + '</div>'; });
    }
    draw();
  }

  /* ── the card on the Compliance screen ── */
  KT.renderStaffComplianceCard = function (host) {
    api().get('/admin/staff-compliance').then(function (d) {
      if (!d || !d.enabled) { host.innerHTML = ''; return; }
      var staff = d.staff || [];
      var attention = staff.filter(function (s) { return s.attention; }).length;
      host.innerHTML = '<div style="background:#fff;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-top:16px;">'
        + '<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;"><h3 style="margin:0;font-size:14px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:1px;">🎓 Staff training &amp; checks</h3>'
        + '<span style="font-size:12px;color:#64748B;">' + esc(d.jurisdiction) + ' rules · ' + (attention ? attention + ' of ' + staff.length + ' need attention' : 'everyone is up to date') + '</span></div>'
        + '<div style="overflow-x:auto;margin-top:10px;"><table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="text-align:left;color:#64748B;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">'
        + '<th style="padding:6px 8px;">Staff</th><th style="padding:6px 8px;">Hired</th><th style="padding:6px 8px;">Needs attention</th>' + (d.pd ? '<th style="padding:6px 8px;min-width:160px;">Training hours</th>' : '') + '<th></th></tr></thead><tbody>'
        + staff.map(function (s) {
            var c = s.counts || {}, parts = [];
            if (c.overdue) parts.push(pill('overdue').replace('Overdue', c.overdue + ' overdue'));
            if (c.expired) parts.push(pill('expired').replace('Expired', c.expired + ' expired'));
            if (c.missing) parts.push(pill('missing').replace('Not on file', c.missing + ' not on file'));
            if (c.needs_hire_date) parts.push(pill('needs_hire_date'));
            if (c.due) parts.push(pill('due').replace('Due', c.due + ' due'));
            return '<tr style="border-top:1px solid #F1F5F9;"><td style="padding:7px 8px;font-weight:600;color:#0F172A;">' + esc(s.name) + '</td>'
              + '<td style="padding:7px 8px;color:#334155;white-space:nowrap;">' + (s.hire_date ? day(s.hire_date) : '<span style="color:#94A3B8;">—</span>') + '</td>'
              + '<td style="padding:7px 8px;">' + (parts.length ? parts.join(' ') : pill('done')) + '</td>'
              + (d.pd ? '<td style="padding:7px 8px;">' + (s.pd ? pdBar(s.pd).replace(/<div style="font-size:13px[^>]*>[\s\S]*?<\/div>/, '<div style="font-size:12px;color:#334155;margin-bottom:3px;">' + s.pd.approved + ' / ' + s.pd.required + ' h</div>') : '') + '</td>' : '')
              + '<td style="padding:7px 8px;text-align:right;"><button type="button" data-open="' + s.user_id + '" style="' + BTN + 'background:#fff;color:#1F6080;border:1px solid #CBD5E1;">Open</button></td></tr>';
          }).join('') + '</tbody></table></div></div>';
      host.querySelectorAll('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () { openStaff(+b.getAttribute('data-open'), function () { KT.renderStaffComplianceCard(host); }); });
      });
    }).catch(function () { host.innerHTML = ''; });
  };

  /* ── #my-training ── */
  function renderMine(main) {
    main.innerHTML = '<div style="padding:20px 24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);"><div class="kt-hero-greet">🎓 STAFF</div><h1>My training &amp; checks</h1>'
      + '<div class="kt-hero-sub">What your licensing rules ask of you, and your training hours for the year.</div></div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr));gap:16px;margin-top:16px;align-items:start;">'
      + '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px;"><div style="font-weight:800;font-size:14px;margin-bottom:8px;">Requirements</div><div data-items style="color:#64748B;font-size:13px;">Loading…</div></div>'
      + '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px;"><div style="font-weight:800;font-size:14px;margin-bottom:8px;">Training hours</div><div data-pd></div></div>'
      + '</div></div>';
    api().get('/my-compliance').then(function (c) {
      var box = main.querySelector('[data-items]');
      // The hire date only matters where deadlines count from it (an enabled jurisdiction).
      box.innerHTML = (c.hire_date ? '<div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">Hired ' + day(c.hire_date) + '</div>'
          : (c.enabled ? '<div style="font-size:12.5px;color:#B45309;margin-bottom:8px;">Your hire date isn\'t on file yet — ask your director to add it.</div>' : ''))
        + itemsTable(c.items);
      if (!c.pd) main.querySelector('[data-pd]').innerHTML = '<div style="color:#64748B;font-size:13px;">Your agency\'s rules don\'t set yearly training hours, but you can still keep a log here.</div><div data-pd2 style="margin-top:8px;"></div>';
      renderPd(c.pd ? main.querySelector('[data-pd]') : main.querySelector('[data-pd2]'), null);
    }).catch(function (e) { main.querySelector('[data-items]').textContent = (e && e.message) || 'Could not load.'; });
  }

  /* ── #household ── */
  function renderHousehold(main) {
    main.innerHTML = '<div style="padding:20px 24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#1E1B4B 0%,#4338CA 60%,#6366F1 100%);"><div class="kt-hero-greet">🏠 HOMES</div><h1>Household residents</h1>'
      + '<div class="kt-hero-sub">Adults who live in a home where child care is provided, and their background checks.</div></div><div data-body style="margin-top:16px;color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('[data-body]');
    function draw() {
      api().get('/household').then(function (d) {
        var types = d.check_types || [];
        var typeOpts = types.map(function (t) { return '<option value="' + esc(t.code) + '">' + esc(t.label) + '</option>'; }).join('');
        var label = {}; types.forEach(function (t) { label[t.code] = t.label; });
        body.innerHTML = (d.note ? '<div style="background:#EEF2FF;border-radius:10px;padding:10px 12px;font-size:13px;color:#3730A3;margin-bottom:12px;">' + esc(d.note) + '</div>' : '')
          + (d.homes || []).map(function (h) {
              return '<div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px;margin-bottom:14px;">'
                + '<div style="font-weight:800;font-size:15px;color:#0F172A;margin-bottom:8px;">' + esc(h.name) + '</div>'
                + (h.residents.length ? '' : '<div style="color:#64748B;font-size:13px;margin-bottom:8px;">No adults recorded yet.</div>')
                + h.residents.map(function (r) {
                    return '<div style="border-top:1px solid #F1F5F9;padding:10px 0;">'
                      + '<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;"><b>' + esc(r.full_name) + '</b><span style="font-size:12.5px;color:#64748B;">' + esc(r.relationship || '') + (r.date_of_birth ? ' · born ' + day(r.date_of_birth) : '') + (r.moved_in_on ? ' · moved in ' + day(r.moved_in_on) : '') + '</span>'
                      + '<button type="button" data-rdel="' + r.id + '" style="margin-left:auto;' + BTN + 'background:#fff;color:#64748B;border:1px solid #E2E8F0;">Remove</button></div>'
                      + (r.checks.length ? '<div style="margin-top:6px;">' + r.checks.map(function (k) {
                          return '<div style="display:flex;gap:8px;align-items:center;font-size:12.5px;padding:3px 0;flex-wrap:wrap;"><span style="font-weight:600;">' + esc(label[k.check_type] || k.check_type) + '</span>'
                            + pill(k.status) + '<span style="color:#64748B;">' + (k.issued_at ? day(k.issued_at) : '') + (k.expires_at ? ' → ' + day(k.expires_at) : '') + (k.reference ? ' · ' + esc(k.reference) : '') + '</span>'
                            + '<button type="button" data-kdel="' + k.id + '" title="Remove" style="border:0;background:none;color:#94A3B8;cursor:pointer;">✕</button></div>';
                        }).join('') + '</div>' : '<div style="font-size:12.5px;color:#B45309;margin-top:4px;">No background checks on file.</div>')
                      + '<form data-kadd="' + r.id + '" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;align-items:center;">'
                      + '<select name="check_type" style="' + INPUT + '">' + typeOpts + '</select>'
                      + '<input name="reference" placeholder="Reference" style="' + INPUT + 'width:130px;">'
                      + '<input name="issued_at" type="date" title="Date done" style="' + INPUT + '">'
                      + '<input name="expires_at" type="date" title="Renew by (filled from the rules if left blank)" style="' + INPUT + '">'
                      + '<button type="submit" style="' + BTN + 'background:#fff;color:#1F6080;border:1px solid #CBD5E1;">Add check</button></form></div>';
                  }).join('')
                + '<form data-radd="' + h.centre_id + '" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px dashed #E2E8F0;">'
                + '<input name="full_name" required placeholder="Full name" style="' + INPUT + 'width:180px;">'
                + '<input name="relationship" placeholder="Relationship (e.g. spouse)" style="' + INPUT + 'width:180px;">'
                + '<input name="date_of_birth" type="date" title="Date of birth" style="' + INPUT + '">'
                + '<input name="moved_in_on" type="date" title="Moved in" style="' + INPUT + '">'
                + '<button type="submit" style="' + BTN + 'background:#1F6080;color:#fff;border:0;">Add adult</button></form></div>';
            }).join('');
        function formJson(f) { var o = {}; new FormData(f).forEach(function (v, k) { if (v !== '') o[k] = v; }); return o; }
        body.querySelectorAll('[data-radd]').forEach(function (f) {
          f.addEventListener('submit', function (e) { e.preventDefault(); api().post('/household/' + f.getAttribute('data-radd') + '/residents', formJson(f)).then(draw).catch(function (er) { toast('⚠️', 'Could not save', (er && er.message) || '', '#DC2626'); }); });
        });
        body.querySelectorAll('[data-kadd]').forEach(function (f) {
          f.addEventListener('submit', function (e) { e.preventDefault(); api().post('/household-residents/' + f.getAttribute('data-kadd') + '/checks', formJson(f)).then(draw).catch(function (er) { toast('⚠️', 'Could not save', (er && er.message) || '', '#DC2626'); }); });
        });
        body.querySelectorAll('[data-rdel]').forEach(function (b) {
          b.addEventListener('click', async function () {
            if (KT.confirm && !(await KT.confirm({ title: 'Remove this adult?', description: 'They are kept in the record as removed.', okLabel: 'Remove' }))) return;
            api().delete('/household-residents/' + b.getAttribute('data-rdel')).then(draw);
          });
        });
        body.querySelectorAll('[data-kdel]').forEach(function (b) {
          b.addEventListener('click', async function () {
            if (KT.confirm && !(await KT.confirm({ title: 'Remove this check?', okLabel: 'Remove' }))) return;
            api().delete('/household-resident-checks/' + b.getAttribute('data-kdel')).then(draw);
          });
        });
      }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;">' + esc((e && e.message) || 'Could not load.') + '</div>'; });
    }
    draw();
  }

  KT.StaffCompliance = { openStaff: openStaff, renderPd: renderPd };
  function reg() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':my-training', renderMine); });
    ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':household', renderHousehold); });
  }
  reg();
})(window, document);
