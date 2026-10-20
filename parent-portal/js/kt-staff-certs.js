/* ═══════════════════════════════════════════════════════════════════════
   kt-staff-certs.js — staff certificates: CPR, first aid, RECE … (2026-10-19)

   Anthony: "add ability to add CPR certificates with issue and expiry dates - full system
   and add ability for educators to upload using their view", and "staff certifications
   should also be linked to their user profile record as well".

   Three doors, one renderer, one set of endpoints (/staff-certifications*):
     · #my-certificates — a staff member's own: add one, attach the certificate (a PDF or
       a phone photo), see what is expiring. Their own uploads count at once and show
       "Not yet checked" until a director or admin verifies them.
     · the 🎓 Certifications tab on the user record (KT.StaffCerts.renderList(box, userId)).
     · #certifications — everyone a director/admin manages, by centre and type, with who
       has no current CPR (KT.StaffCerts.renderRoster(main)).
   The server decides who may see or change what; this file only draws it.
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
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c); } catch (e) {} }
  function errText(e) { return (e && (e.message || (e.data && e.data.message))) || 'Something went wrong.'; }

  var ICON = { CPR: '❤️', First_Aid: '⛑️', RECE: '🎓', ECE_Assistant: '🎓', Food_Handler: '🍽️', WHMIS: '⚠️', VSC: '🛡️',
    Vulnerable_Sector_Check: '🛡️', Health_Card: '🩺', Child_Abuse_Training: '🧸', Communicable_Disease: '🧼', Orientation: '🧭', OPIN: '🪪', Other: '📄' };
  function icon(code) { return ICON[code] || '📄'; }

  function statusPill(c) {
    var s = c.status, d = c.days_until_expiry, t;
    if (s === 'expired') t = ['#FEF2F2', '#B91C1C', '#FECACA', 'Expired ' + day(c.expires_at)];
    else if (s === 'expiring_soon') t = ['#FFFBEB', '#B45309', '#FDE68A', d === 0 ? 'Expires today' : 'Expires in ' + d + ' day' + (d === 1 ? '' : 's')];
    else if (s === 'warning') t = ['#FEFCE8', '#854D0E', '#FEF08A', d + ' days left'];
    else if (s === 'no_expiry') t = ['#F1F5F9', '#475569', '#E2E8F0', 'No expiry'];
    else t = ['#ECFDF5', '#047857', '#A7F3D0', 'Valid'];
    return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:'
      + t[0] + ';color:' + t[1] + ';border:1px solid ' + t[2] + ';">' + esc(t[3]) + '</span>';
  }
  function checkedPill(c) {
    return c.verified
      ? '<span data-kt-no-tone title="' + esc(c.verified_by_name ? 'Checked by ' + c.verified_by_name : 'Checked') + '" style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:#EFF6FF;color:#1D4ED8;">✓ Verified</span>'
      : '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:#fff;color:#B45309;border:1px dashed #F59E0B;">Not yet checked</span>';
  }

  /* ── the certificate file: fetched with the token (it is private), shown in the viewer ── */
  function openFile(c) {
    var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
    var h = { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '') };
    try { var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa; } catch (e) {}
    fetch(base + '/staff-certifications/' + c.id + '/document', { headers: h })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (blob) {
        if (KT.viewBlob) KT.viewBlob(blob, { title: c.label + (c.staff_name ? ' — ' + c.staff_name : ''), label: c.document_name || 'Certificate' });
        else window.open(URL.createObjectURL(blob), '_blank');
      })
      .catch(function () { toast('⚠️', 'Could not open the certificate', '', '#DC2626'); });
  }

  /* ── add / edit ──
     opts: { cert?, userId?, staff? [{id,name}] (roster: pick the person), types [{code,label}],
             type? (preselect), onSaved() } */
  var FIELD = 'width:100%;height:36px;padding:0 10px;border:1.5px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;background:#fff;';
  function labelled(text, node, hint) {
    var w = document.createElement('label');
    w.style.cssText = 'display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:12px;';
    w.innerHTML = '<div style="margin-bottom:4px;">' + esc(text) + '</div>';
    w.appendChild(node);
    if (hint) { var h = document.createElement('div'); h.style.cssText = 'font-weight:500;font-size:11.5px;color:#94A3B8;margin-top:4px;'; h.textContent = hint; w.appendChild(h); }
    return w;
  }
  function el(tag, attrs) { var e = document.createElement(tag); for (var k in (attrs || {})) e.setAttribute(k, attrs[k]); return e; }

  function openEditor(opts) {
    var M = KT.Shell && KT.Shell.Modal; if (!M) return;
    var c = opts.cert || null, types = opts.types || [];
    var body = document.createElement('div');

    var fStaff = null;
    if (!c && opts.staff) {
      fStaff = el('select'); fStaff.style.cssText = FIELD;
      fStaff.innerHTML = '<option value="">Choose a staff member…</option>' + opts.staff.map(function (s) {
        return '<option value="' + s.id + '"' + (String(s.id) === String(opts.userId || '') ? ' selected' : '') + '>' + esc(s.name) + '</option>';
      }).join('');
      body.appendChild(labelled('Staff member', fStaff));
    } else if (c && c.staff_name) {
      var who = document.createElement('div');
      who.style.cssText = 'font-size:13px;color:#64748B;margin-bottom:12px;';
      who.innerHTML = 'Staff: <strong style="color:#0F172A;">' + esc(c.staff_name) + '</strong>';
      body.appendChild(who);
    }

    var fType = el('select'); fType.style.cssText = FIELD;
    var pre = c ? c.cert_type : (opts.type || 'CPR');
    var list = types.slice();
    if (c && !list.some(function (t) { return t.code === c.cert_type; })) list.push({ code: c.cert_type, label: c.cert_type.replace(/_/g, ' ') });
    fType.innerHTML = list.map(function (t) { return '<option value="' + esc(t.code) + '"' + (t.code === pre ? ' selected' : '') + '>' + esc(icon(t.code) + '  ' + t.label) + '</option>'; }).join('');
    body.appendChild(labelled('Certificate', fType));

    var fTitle = el('input', { type: 'text', maxlength: '160', placeholder: 'e.g. Emergency First Aid & CPR Level C' });
    fTitle.style.cssText = FIELD; fTitle.value = (c && c.title) || '';
    var titleWrap = labelled('Name on the certificate', fTitle, 'Optional, unless the type is Other.');
    body.appendChild(titleWrap);

    var fBy = el('input', { type: 'text', maxlength: '160', placeholder: 'e.g. Canadian Red Cross, St. John Ambulance' });
    fBy.style.cssText = FIELD; fBy.value = (c && c.certifier) || '';
    body.appendChild(labelled('Issued by', fBy));

    var grid = document.createElement('div');
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:0 12px;';
    var fIssued = el('input', { type: 'date' }); fIssued.style.cssText = FIELD; fIssued.value = (c && c.issued_at) || '';
    var fExp = el('input', { type: 'date' }); fExp.style.cssText = FIELD; fExp.value = (c && c.expires_at) || '';
    grid.appendChild(labelled('Issue date', fIssued));
    var expWrap = labelled('Expiry date', fExp);
    // CPR and first aid run 1–3 years from the issue date: one tap instead of a date picker.
    var quick = document.createElement('div');
    quick.style.cssText = 'display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;';
    [1, 2, 3].forEach(function (y) {
      var b = el('button', { type: 'button', 'data-kt-keep-size': '1', 'data-kt-iconized': '1' });
      b.textContent = '+' + y + ' yr' + (y > 1 ? 's' : '');
      b.style.cssText = 'height:26px;padding:0 10px;border-radius:999px;border:1px solid #CBD5E1;background:#F8FAFC;color:#1F6080;font-size:12px;font-weight:700;cursor:pointer;';
      b.addEventListener('click', function () {
        if (!fIssued.value) { err.textContent = 'Pick the issue date first.'; return; }
        var p = fIssued.value.split('-');
        var d = new Date(Date.UTC(+p[0] + y, +p[1] - 1, +p[2]));
        fExp.value = d.toISOString().slice(0, 10); err.textContent = '';
      });
      quick.appendChild(b);
    });
    expWrap.appendChild(quick);
    grid.appendChild(expWrap);
    body.appendChild(grid);

    var fFile = el('input', { type: 'file', accept: 'application/pdf,image/*,.heic,.heif' });
    fFile.style.cssText = 'font-size:13px;width:100%;';
    var fileHint = 'A PDF, or a photo of the card taken with your phone. Up to 10 MB.';
    var fileWrap = labelled(c && c.has_document ? 'Replace the certificate file' : 'Certificate file', fFile, fileHint);
    var fRemove = null;
    if (c && c.has_document) {
      var cur = document.createElement('div');
      cur.style.cssText = 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:#334155;margin:0 0 6px;font-weight:500;';
      cur.innerHTML = '📎 <span style="overflow-wrap:anywhere;">' + esc(c.document_name || 'Certificate') + '</span>';
      var rm = document.createElement('label'); rm.style.cssText = 'display:inline-flex;gap:5px;align-items:center;color:#B91C1C;font-weight:600;cursor:pointer;';
      fRemove = el('input', { type: 'checkbox' }); rm.appendChild(fRemove); rm.appendChild(document.createTextNode('Remove the file'));
      cur.appendChild(rm);
      fileWrap.insertBefore(cur, fFile);
    }
    body.appendChild(fileWrap);

    var note = document.createElement('div');
    note.style.cssText = 'font-size:11.5px;color:#94A3B8;line-height:1.55;';
    note.textContent = opts.selfService
      ? 'Your director or admin is told, and checks the certificate. It counts straight away.'
      : 'Saved against your name in the audit log.';
    body.appendChild(note);
    var err = document.createElement('div');
    err.style.cssText = 'font-size:12.5px;color:#DC2626;margin-top:10px;font-weight:600;';
    body.appendChild(err);

    function syncTitle() { titleWrap.querySelector('div').textContent = fType.value === 'Other' ? 'Name of the certificate' : 'Name on the certificate'; }
    fType.addEventListener('change', syncTitle); syncTitle();

    M.open({
      title: c ? 'Edit certificate' : 'Add a certificate',
      body: body,
      actions: [
        { label: 'Cancel', style: 'btn-secondary' },
        {
          label: c ? 'Save changes' : 'Add certificate', style: 'btn-primary', busyLabel: 'Saving…',
          handler: async function () {
            err.textContent = '';
            if (fStaff && !fStaff.value) { err.textContent = 'Choose the staff member.'; return false; }
            if (!fIssued.value) { err.textContent = 'The issue date is needed.'; return false; }
            if (fExp.value && fExp.value < fIssued.value) { err.textContent = 'The expiry date cannot be before the issue date.'; return false; }
            if (fType.value === 'Other' && !fTitle.value.trim()) { err.textContent = 'Give the certificate a name.'; return false; }
            var f = fFile.files && fFile.files[0];
            if (f && f.size > 10 * 1024 * 1024) { err.textContent = 'That file is over 10 MB.'; return false; }
            var fd = new FormData();
            var uid = fStaff ? fStaff.value : (opts.userId || '');
            if (!c && uid) fd.append('user_id', uid);
            fd.append('cert_type', fType.value);
            fd.append('title', fTitle.value.trim());
            fd.append('certifier', fBy.value.trim());
            fd.append('issued_at', fIssued.value);
            fd.append('expires_at', fExp.value || '');
            if (f) fd.append('document', f);
            if (fRemove && fRemove.checked && !f) fd.append('remove_document', '1');
            try { await api().postForm('/staff-certifications' + (c ? '/' + c.id : ''), fd); }
            catch (e) { err.textContent = errText(e); return false; }
            toast('🎓', c ? 'Certificate updated' : 'Certificate added', '', '#16A34A');
            if (opts.onSaved) opts.onSaved();
          },
        },
      ],
    });
  }

  async function removeCert(c, onDone) {
    var ok = KT.confirm ? await KT.confirm({ title: 'Remove this certificate?', description: c.label + (c.staff_name ? ' — ' + c.staff_name : '') + '. The file attached to it is deleted too.', okLabel: 'Remove' }) : window.confirm('Remove this certificate?');
    if (!ok) return;
    api().delete('/staff-certifications/' + c.id).then(function () { toast('🗑️', 'Certificate removed', '', '#64748B'); if (onDone) onDone(); })
      .catch(function (e) { toast('⚠️', 'Could not remove', errText(e), '#DC2626'); });
  }
  function verifyCert(c, yes, onDone) {
    api().post('/staff-certifications/' + c.id + '/verify', { verified: yes }).then(function () {
      toast(yes ? '✅' : '↩️', yes ? 'Marked verified' : 'Marked not verified', '', yes ? '#16A34A' : '#64748B'); if (onDone) onDone();
    }).catch(function (e) { toast('⚠️', 'Could not save', errText(e), '#DC2626'); });
  }
  function menuItem(label, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', fn); return b; }

  /* ── one person's certificates (own view, user record) ── */
  function renderList(box, userId, opts) {
    opts = opts || {};
    box.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading…</div>';
    api().get('/staff-certifications' + (userId ? '?user_id=' + encodeURIComponent(userId) : '')).then(function (d) {
      var rows = d.certifications || [], self = !!d.is_self, manage = !!d.can_manage;
      var reload = function () { renderList(box, userId, opts); if (opts.onChange) opts.onChange(); };
      var cpr = rows.filter(function (r) { return r.cert_type === 'CPR' && r.status !== 'expired'; }).length;
      var needs = rows.filter(function (r) { return r.status === 'expired' || r.status === 'expiring_soon'; }).length;
      box.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">'
        + '<div style="font-size:13px;color:#475569;">' + (rows.length ? rows.length + ' on file' + (needs ? ' · <b style="color:#B45309;">' + needs + ' need renewing</b>' : '') : '')
        + (!cpr ? (rows.length ? ' · ' : '') + '<b style="color:#B91C1C;">No current CPR on file</b>' : '') + '</div>'
        + '<button type="button" data-add data-kt-iconized="1" style="height:34px;padding:0 14px;border-radius:9px;border:0;background:#1F6080;color:#fff;font-size:13.5px;font-weight:800;cursor:pointer;">＋ Add certificate</button></div>'
        + (rows.length ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:12px;">' + rows.map(function (c, i) {
            var edge = c.status === 'expired' ? '#DC2626' : c.status === 'expiring_soon' ? '#F59E0B' : c.status === 'warning' ? '#EAB308' : '#10B981';
            return '<div style="position:relative;background:#fff;border:1px solid #E2E8F0;border-left:4px solid ' + edge + ';border-radius:12px;padding:14px 14px 12px;min-width:0;">'
              + '<div style="display:flex;gap:10px;align-items:flex-start;">'
              + '<div style="font-size:24px;line-height:1;flex:0 0 auto;">' + icon(c.cert_type) + '</div>'
              + '<div style="min-width:0;flex:1;"><div style="font-weight:800;font-size:15px;color:#0F172A;overflow-wrap:anywhere;padding-right:30px;">' + esc(c.label) + '</div>'
              + (c.certifier ? '<div style="font-size:12.5px;color:#64748B;overflow-wrap:anywhere;">' + esc(c.certifier) + '</div>' : '') + '</div></div>'
              + '<button type="button" data-kebab="' + i + '" data-kt-keep-size="1" data-kt-iconized="1" class="kt-ka-kebab" aria-label="Actions" style="position:absolute;top:10px;right:8px;width:30px;height:30px;border:0;background:transparent;border-radius:8px;font-size:18px;line-height:1;color:#64748B;cursor:pointer;">⋮</button>'
              + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin:12px 0 10px;font-size:12.5px;">'
              + '<div><div style="color:#94A3B8;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;">Issued</div><div style="color:#0F172A;font-weight:600;">' + day(c.issued_at) + '</div></div>'
              + '<div><div style="color:#94A3B8;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;">Expires</div><div style="color:#0F172A;font-weight:600;">' + (c.expires_at ? day(c.expires_at) : '—') + '</div></div></div>'
              + '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">' + statusPill(c) + checkedPill(c)
              + (c.has_document ? '<button type="button" data-file="' + i + '" data-kt-keep-size="1" data-kt-iconized="1" style="margin-left:auto;height:26px;padding:0 10px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-size:12px;font-weight:700;cursor:pointer;">📎 View</button>'
                : '<span style="margin-left:auto;font-size:12px;color:#94A3B8;">No file attached</span>')
              + '</div></div>';
          }).join('') + '</div>'
          : '<div style="padding:28px 16px;border:1.5px dashed #CBD5E1;border-radius:12px;text-align:center;color:#64748B;font-size:14px;background:#F8FAFC;">'
            + '<div style="font-size:30px;margin-bottom:6px;">❤️</div>No certificates on file yet.<br><span style="font-size:12.5px;">'
            + (self ? 'Add your CPR and first aid certificates — a photo of the card is fine.' : 'Add their CPR, first aid and other certificates here.') + '</span></div>');
      box.querySelector('[data-add]').addEventListener('click', function () {
        openEditor({ userId: userId || null, types: d.types, selfService: self && !manage, onSaved: reload });
      });
      box.querySelectorAll('[data-file]').forEach(function (b) { b.addEventListener('click', function () { openFile(rows[+b.getAttribute('data-file')]); }); });
      box.querySelectorAll('[data-kebab]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.stopPropagation();
          var c = rows[+b.getAttribute('data-kebab')]; if (!KT.openActionMenu) return;
          var acts = [];
          if (c.has_document) acts.push(menuItem('📎 View certificate', function () { openFile(c); }));
          acts.push(menuItem('✏️ Edit', function () { openEditor({ cert: c, userId: userId, types: d.types, selfService: self && !manage, onSaved: reload }); }));
          if (manage && !self) acts.push(menuItem(c.verified ? '↩️ Mark not verified' : '✅ Mark verified', function () { verifyCert(c, !c.verified, reload); }));
          if (manage || !c.verified) acts.push(menuItem('🗑️ Remove', function () { removeCert(c, reload); }));
          KT.openActionMenu(b, acts);
        });
      });
    }).catch(function (e) { box.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + esc(errText(e)) + '</div>'; });
  }

  /* ── #my-certificates ── */
  function renderMine(main) {
    main.innerHTML = '<div style="padding:20px 24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#7F1D1D 0%,#BE123C 55%,#E11D48 100%);"><div class="kt-hero-greet">🎓 STAFF</div><h1>My certificates</h1>'
      + '<div class="kt-hero-sub">Your CPR, first aid and other certificates, with their expiry dates. Add a new one when you renew.</div></div>'
      + '<div data-certs style="margin-top:16px;"></div></div>';
    renderList(main.querySelector('[data-certs]'), null);
  }

  /* ── #certifications — the roster for directors and admins ── */
  var R = { centre: '', type: '', q: '' };
  function renderRoster(main) {
    main.innerHTML = '<div style="padding:20px 24px;max-width:1800px;margin:0 auto;">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#0F172A 0%,#1F6080 60%,#16637A 100%);"><div class="kt-hero-greet">🎓 STAFF</div><h1>Staff certifications</h1>'
      + '<div class="kt-hero-sub">CPR, first aid and every other certificate your staff hold — expiry dates, the certificate itself, and who is missing one.</div></div>'
      + '<div data-body style="margin-top:16px;color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('[data-body]');
    var lastD = null;
    function draw(useCache) {
      var q = [];
      if (R.centre) q.push('centre_id=' + encodeURIComponent(R.centre));
      if (R.type) q.push('type=' + encodeURIComponent(R.type));
      var go = useCache && lastD ? Promise.resolve(lastD) : api().get('/staff-certifications/roster' + (q.length ? '?' + q.join('&') : ''));
      go.then(function (d) {
        lastD = d;
        var all = d.certifications || [], types = d.types || [];
        var rows = R.q ? all.filter(function (c) { return ((c.staff_name || '') + ' ' + c.label + ' ' + (c.certifier || '')).toLowerCase().indexOf(R.q.toLowerCase()) >= 0; }) : all;
        var k = d.counts || {}, miss = (d.missing && d.missing.staff) || [];
        function stat(n, label, col, bg) {
          return '<div style="background:' + bg + ';border-radius:12px;padding:12px 14px;min-width:0;"><div style="font-size:24px;font-weight:800;color:' + col + ';line-height:1.1;">' + n + '</div>'
            + '<div style="font-size:12px;font-weight:700;color:#475569;margin-top:2px;">' + esc(label) + '</div></div>';
        }
        var SEL = 'height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;background:#fff;max-width:100%;';
        body.style.color = '';
        body.innerHTML = '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">'
          + '<select data-f="centre" style="' + SEL + '"><option value="">All centres</option>' + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(R.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>'
          + '<select data-f="type" style="' + SEL + '"><option value="">All certificates</option>' + types.map(function (t) { return '<option value="' + esc(t.code) + '"' + (t.code === R.type ? ' selected' : '') + '>' + esc(t.label) + '</option>'; }).join('') + '</select>'
          + '<input data-f="q" type="search" placeholder="Search staff or certificate" value="' + esc(R.q) + '" style="' + SEL + 'flex:1;min-width:160px;">'
          + '<button type="button" data-add data-kt-iconized="1" style="height:34px;padding:0 14px;border-radius:9px;border:0;background:#1F6080;color:#fff;font-size:13.5px;font-weight:800;cursor:pointer;white-space:nowrap;">＋ Add certificate</button></div>'
          + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr));gap:10px;margin-bottom:14px;">'
          + stat(k.total || 0, 'On file', '#0F172A', '#F1F5F9') + stat(k.expired || 0, 'Expired', '#B91C1C', '#FEF2F2')
          + stat(k.expiring_soon || 0, 'Expiring in 30 days', '#B45309', '#FFFBEB') + stat(k.unverified || 0, 'Not yet checked', '#1D4ED8', '#EFF6FF')
          + stat(miss.length, 'No current ' + ((d.missing && d.missing.label) || 'CPR'), miss.length ? '#B91C1C' : '#047857', miss.length ? '#FFF1F2' : '#ECFDF5') + '</div>'
          + (miss.length ? '<details style="background:#FFF1F2;border:1px solid #FECDD3;border-radius:12px;padding:10px 14px;margin-bottom:14px;"' + (miss.length <= 8 ? ' open' : '') + '>'
              + '<summary style="cursor:pointer;font-weight:800;color:#9F1239;font-size:13.5px;">⚠️ ' + miss.length + ' staff ' + (miss.length === 1 ? 'has' : 'have') + ' no current ' + esc((d.missing && d.missing.label) || 'CPR') + '</summary>'
              + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">' + miss.map(function (m) {
                  return '<button type="button" data-miss="' + m.user_id + '" data-kt-keep-size="1" data-kt-iconized="1" title="Add one for ' + esc(m.name) + '" style="height:28px;padding:0 10px;border-radius:999px;border:1px solid #FDA4AF;background:#fff;color:#9F1239;font-size:12.5px;font-weight:700;cursor:pointer;">＋ ' + esc(m.name) + '</button>';
                }).join('') + '</div></details>' : '')
          + (rows.length ? '<div style="background:#fff;border-radius:14px;overflow-x:auto;box-shadow:0 1px 4px rgba(0,0,0,.05);"><table style="width:100%;border-collapse:collapse;font-size:14px;">'
              + '<thead style="background:#F9FAFB;"><tr>' + ['Staff', 'Certificate', 'Issued', 'Expires', 'Status', 'Checked', ''].map(function (h) { return '<th style="text-align:left;padding:11px 12px;font-size:11.5px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
              + rows.map(function (c, i) {
                  var T = 'padding:10px 12px;color:#374151;vertical-align:middle;';
                  return '<tr style="border-top:1px solid #F3F4F6;">'
                    + '<td style="' + T + '"><button type="button" data-person="' + i + '" data-kt-keep-size="1" data-kt-iconized="1" style="border:0;background:none;padding:0;font:inherit;font-weight:700;color:#1F6080;cursor:pointer;text-align:left;">' + esc(c.staff_name || '—') + '</button></td>'
                    + '<td style="' + T + '"><span style="margin-right:4px;">' + icon(c.cert_type) + '</span><strong>' + esc(c.label) + '</strong>' + (c.has_document ? ' <span title="Certificate attached">📎</span>' : '') + (c.certifier ? '<div style="font-size:11.5px;color:#64748B;">' + esc(c.certifier) + '</div>' : '') + '</td>'
                    + '<td style="' + T + 'white-space:nowrap;">' + day(c.issued_at) + '</td>'
                    + '<td style="' + T + 'white-space:nowrap;">' + (c.expires_at ? day(c.expires_at) : '—') + '</td>'
                    + '<td style="' + T + '">' + statusPill(c) + '</td>'
                    + '<td style="' + T + '">' + checkedPill(c) + '</td>'
                    + '<td style="' + T + 'text-align:right;white-space:nowrap;">'
                    + (c.has_document ? '<button data-r-file="' + i + '" class="kt-act-icon kt-act-info kt-icon-tip" data-kttip="View certificate" aria-label="View certificate">📎</button>' : '')
                    + '<button data-r-edit="' + i + '" class="kt-act-icon kt-act-edit kt-icon-tip" data-kttip="Edit" aria-label="Edit">✏️</button>'
                    + '<button data-r-ver="' + i + '" class="kt-act-icon kt-act-teal kt-icon-tip" data-kttip="' + (c.verified ? 'Mark not verified' : 'Mark verified') + '" aria-label="' + (c.verified ? 'Mark not verified' : 'Mark verified') + '">' + (c.verified ? '↩️' : '✅') + '</button>'
                    + '<button data-r-del="' + i + '" class="kt-act-icon kt-act-danger kt-icon-tip" data-kttip="Remove" aria-label="Remove">🗑️</button>'
                    + '</td></tr>';
                }).join('') + '</tbody></table></div>'
            : '<div style="padding:40px 16px;background:#fff;border-radius:14px;text-align:center;color:#64748B;">' + (all.length ? 'Nothing matches the search.' : 'No certificates on file here yet.') + '</div>');

        body.querySelector('[data-f="centre"]').addEventListener('change', function (e) { R.centre = e.target.value; draw(); });
        body.querySelector('[data-f="type"]').addEventListener('change', function (e) { R.type = e.target.value; draw(); });
        var qi = body.querySelector('[data-f="q"]'), qt = null;
        qi.addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(function () { R.q = qi.value.trim(); draw(true); }, 250); });
        if (R.q) { qi.focus(); try { qi.setSelectionRange(qi.value.length, qi.value.length); } catch (e) {} }
        body.querySelector('[data-add]').addEventListener('click', function () { openEditor({ staff: d.staff, types: types, type: R.type || 'CPR', onSaved: draw }); });
        body.querySelectorAll('[data-miss]').forEach(function (b) {
          b.addEventListener('click', function () { openEditor({ staff: d.staff, userId: b.getAttribute('data-miss'), types: types, type: (d.missing && d.missing.type) || 'CPR', onSaved: draw }); });
        });
        function at(b, a) { return rows[+b.getAttribute(a)]; }
        body.querySelectorAll('[data-r-file]').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); openFile(at(b, 'data-r-file')); }); });
        body.querySelectorAll('[data-r-edit]').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); openEditor({ cert: at(b, 'data-r-edit'), types: types, onSaved: draw }); }); });
        body.querySelectorAll('[data-r-ver]').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); var c = at(b, 'data-r-ver'); verifyCert(c, !c.verified, draw); }); });
        body.querySelectorAll('[data-r-del]').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); removeCert(at(b, 'data-r-del'), draw); }); });
        body.querySelectorAll('[data-person]').forEach(function (b) {
          b.addEventListener('click', function () { var c = at(b, 'data-person'); openPerson(c.user_id, c.staff_name, draw); });
        });
        if (KT.sweepRowActions) setTimeout(KT.sweepRowActions, 0);
      }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;">' + esc(errText(e)) + '</div>'; });
    }
    draw();
  }

  /** Everything one person holds, in a window (from the roster). */
  function openPerson(userId, name, onChange) {
    var M = KT.Shell && KT.Shell.Modal; if (!M) return;
    var box = document.createElement('div');
    M.open({ title: '🎓 ' + (name || 'Certificates'), body: box, large: true, actions: [{ label: 'Close', primary: true }] });
    renderList(box, userId, { onChange: onChange });
  }

  KT.StaffCerts = { renderList: renderList, renderRoster: renderRoster, openEditor: openEditor, openFile: openFile, openPerson: openPerson };
  function reg() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':my-certificates', renderMine); });
  }
  reg();
})(window, document);
