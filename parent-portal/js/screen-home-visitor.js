/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Home Visitor role (2026-07-20).
   A home visitor is attached to an AGENCY (not one centre). They visit
   families and file home-visit reports, choosing any centre in their
   agency from a dropdown. Screens: home (tile launcher), new report form,
   my reports list. Backend: /home-visits (+ /centres). Reuses the
   .kt-tile-grid markup so the home inherits the circular colour tiles.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;
  var Dom = KT.Dom;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.style.cssText = attrs[k];
      else if (k === 'html') e.innerHTML = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function activeAgency() { return sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || ''; }
  // Editing a home-visit report is restricted to agency admins / directors (+ platform).
  function isReviewerUser() {
    try { var r = (user().roles) || []; return ['agency_admin', 'centre_director', 'platform_admin'].some(function (x) { return r.indexOf(x) !== -1; }); }
    catch (e) { return false; }
  }
  function reportPdf(id, btn) {
    var old = btn ? btn.textContent : null; if (btn) { btn.textContent = '…'; btn.disabled = true; }
    var h = { Authorization: 'Bearer ' + tok() }; if (activeAgency()) h['X-Active-Agency-Id'] = activeAgency();
    return fetch(apiBase() + '/home-visits/' + id + '/pdf', { headers: h })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { var u = URL.createObjectURL(b); var a = document.createElement('a'); a.href = u; a.download = 'home-visit-' + id + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 1500); })
      .catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Download failed', e.message || '', '#DC2626'); })
      .then(function () { if (btn) { btn.textContent = old; btn.disabled = false; } });
  }

  // ── Home: tile launcher (inherits the circular colour tiles) ─────────
  function renderHome(container) {
    clear(container);
    var u = user();
    var wrap = el('div', { class: 'kt-tilehome', style: 'max-width:760px;margin:0 auto;padding:14px 14px 90px;' });
    // Banner intentionally does NOT repeat the name/role — the top bar already
    // shows those. Keep it a clean, action-oriented welcome.
    wrap.appendChild(el('div', { class: 'kt-hero kt-hero-tiles', style: 'background:linear-gradient(135deg,#1F6080,#159FB4);color:#fff;border-radius:18px;padding:20px 24px;margin-bottom:16px;' }, [
      el('div', { style: 'font-size:20px;font-weight:800;' }, ['Home visits']),
      el('div', { style: 'font-size:13.5px;opacity:.9;margin-top:4px;' }, ['Log a home visit or review your reports.']),
    ]));
    // Colorized tracking cards — reports, follow-ups (tasks), visits, pay.
    var stats = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:12px;margin-bottom:16px;' });
    wrap.appendChild(stats);
    // Tinted stat cards — pastel wash + ink-coloured value, matching the parent
    // "Today" tiles (which are clearly tinted, not white). The colour comes from
    // the card's own accent so each card reads as its own colour, like parent's.
    var statCard = function (icon, c1, c2, tint, ink, label, href) {
      var a = el('a', { href: '#' + href, style: 'display:block;text-decoration:none;background:' + tint + ';border:1px solid ' + ink + '22;border-radius:16px;padding:14px 15px;box-shadow:0 1px 3px rgba(15,23,42,.05);' }, [
        el('div', { style: 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:9px;background:linear-gradient(135deg,' + c1 + ',' + c2 + ');box-shadow:0 6px 13px -6px ' + c2 + ';' }, [icon]),
        el('div', { class: 'kt-hv-val', style: 'font-size:22px;font-weight:800;color:' + ink + ';line-height:1;' }, ['—']),
        el('div', { style: 'font-size:12px;color:' + ink + ';opacity:.82;margin-top:4px;font-weight:600;' }, [label]),
      ]);
      return a;
    };
    var cDone = statCard('📋', '#0FA3B1', '#1F6080', '#ECFAFC', '#0E6E7D', 'Reports completed', 'home-visits');
    var cTasks = statCard('✅', '#F59E0B', '#D97706', '#FEF7E8', '#B45309', 'Follow-ups due', 'home-visits');
    var cMonth = statCard('🏡', '#7C3AED', '#9333EA', '#F5F1FE', '#6D28D9', 'Visits this month', 'home-visits');
    var cOpen = statCard('☑️', '#0E9BAF', '#0B7C8C', '#E8FAFC', '#0B6F7E', 'Open tasks', 'my-tasks');
    var cPay = statCard('💵', '#10B981', '#059669', '#ECFDF5', '#047857', "This week's pay", 'my-pay');
    [cDone, cTasks, cMonth, cOpen, cPay].forEach(function (c) { stats.appendChild(c); });
    var setv = function (cardEl, v) { var e = cardEl.querySelector('.kt-hv-val'); if (e) e.textContent = String(v); };
    Api.get('/tasks/mine').then(function (d) { setv(cOpen, (d && d.open_count) || 0); }).catch(function () { setv(cOpen, 0); });
    Api.get('/home-visits').then(function (d) {
      var reps = (d && d.reports) || [];
      var today = new Date().toISOString().slice(0, 10);
      var mo = new Date(); var ym = mo.getFullYear() + '-' + ('0' + (mo.getMonth() + 1)).slice(-2);
      setv(cDone, reps.length);
      setv(cTasks, reps.filter(function (r) { return r.follow_up_date && String(r.follow_up_date).slice(0, 10) <= today; }).length);
      setv(cMonth, reps.filter(function (r) { return String(r.visit_date || '').slice(0, 7) === ym; }).length);
    }).catch(function () { setv(cDone, 0); setv(cTasks, 0); setv(cMonth, 0); });
    Api.get('/me/payslips').then(function (d) {
      var slips = (d && d.payslips) || [];
      setv(cPay, '$' + (slips.length ? (Number(slips[0].gross) || 0) : 0).toFixed(0));
    }).catch(function () { setv(cPay, '$0'); });

    // Class-only markup (NO inline styles) so the tiles inherit the SAME tinted
    // card + coloured icon + accent-bar treatment as the parent/educator launcher
    // (kt-tile-grid nth-child rules). Inline styles here were overriding that and
    // making the tiles look plain white.
    var grid = el('div', { class: 'kt-tile-grid' });
    var tiles = [
      { hash: 'new-home-visit', icon: '📝', label: 'New visit report', sub: 'Log a home visit' },
      { hash: 'home-visits',    icon: '📋', label: 'My reports',       sub: 'View past visits' },
      { hash: 'my-tasks',       icon: '✅', label: 'My tasks',         sub: 'Assigned to you' },
      { hash: 'inspection-forms', icon: '🗂️', label: 'Inspection forms', sub: 'Monthly & quarterly' },
      { hash: 'my-forms',       icon: '✍️', label: 'Forms to sign',   sub: 'Review & sign' },
      { hash: 'chat',           icon: '💬', label: 'Messenger',         sub: 'Chat with families & staff' },
      { hash: 'announcements',  icon: '📢', label: 'Announcements',    sub: 'Agency news' },
      { hash: 'forms',          icon: '📝', label: 'Forms',            sub: 'Documents & agreements' },
      { hash: 'my-pay',         icon: '💵', label: 'My pay',           sub: 'Payslips & downloads' },
      { hash: 'time-off',       icon: '🌴', label: 'Time off',         sub: 'Request time off' },
      { hash: 'notifications',  icon: '🔔', label: 'Notifications',    sub: 'Your alerts' },
      { hash: 'settings',       icon: '⚙️', label: 'Settings',         sub: 'Your account' },
      { hash: 'help',           icon: '📖', label: 'Help',             sub: 'Guides & answers' },
    ];
    tiles.forEach(function (t) {
      var a = el('a', { class: 'kt-tile', href: '#' + t.hash, title: t.sub || t.label, 'aria-label': t.label + ' — ' + (t.sub || t.label) }, [
        el('span', { class: 'kt-tile-icon', 'aria-hidden': 'true' }, [t.icon]),
        el('span', { class: 'kt-tile-label' }, [t.label]),
      ]);
      if (t.sub) a.appendChild(el('span', { class: 'kt-tile-sub' }, [t.sub]));
      if (t.hash === 'inspection-forms') { a.setAttribute('data-hcc', '1'); a.style.display = 'none'; }
      grid.appendChild(a);
    });
    wrap.appendChild(grid);
    container.appendChild(wrap);
    // Inspection forms are limited to specific agencies — reveal the tile only if enabled.
    Api.get('/inspection-forms/schemas').then(function (d) {
      if (d && d.enabled) { var t = grid.querySelector('[data-hcc]'); if (t) t.style.display = ''; }
    }).catch(function () {});
  }

  // ── New report form ──────────────────────────────────────────────────
  var VISIT_TYPES = [['initial', 'Initial visit'], ['routine', 'Routine visit'], ['follow_up', 'Follow-up'], ['assessment', 'Assessment'], ['other', 'Other']];
  var LOCATIONS = [['home', 'Family home'], ['virtual', 'Virtual / phone'], ['community', 'Community'], ['other', 'Other']];

  function field(label, controlHtml, required) {
    var star = required ? ' <span style="color:#DC2626;font-weight:800;">*</span>' : '';
    return el('div', { style: 'margin-bottom:14px;', html: '<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">' + esc(label) + star + '</label>' + controlHtml });
  }
  var IN = 'width:100%;padding:11px 13px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;font-family:inherit;';

  function fmtWhen(iso) { try { return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return iso || ''; } }

  // renderNewReport(container) → create; renderNewReport(container, existing) → edit.
  function renderNewReport(container, existing) {
    clear(container);
    var ex = existing || null;
    var xv = function (f) { return ex && ex[f] != null ? esc(String(ex[f])) : ''; };
    var xsel = function (f, v) { return ex && String(ex[f] == null ? '' : ex[f]) === String(v) ? ' selected' : ''; };
    var box = el('div', { style: 'max-width:640px;margin:0 auto;padding:16px 16px 90px;' });
    box.appendChild(el('h1', { style: 'margin:6px 2px 4px;font-size:22px;font-weight:800;color:#0f172a;' }, [ex ? 'Edit home visit report' : 'New home visit report']));
    box.appendChild(el('div', { style: 'color:#64748b;font-size:13.5px;margin:0 2px 18px;' }, [ex ? 'Update the visit details. Every change is logged with your name and the time.' : 'Choose the centre this family belongs to, then record the visit.']));
    var card = el('div', { style: 'background:#fff;border:1px solid #E7EDF3;border-radius:16px;padding:18px 18px 20px;' });

    var section = function (title) { return el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#0FA3B1;margin:4px 2px 12px;' }, [title]); };
    var grid2 = function (a, b) { var g = el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;' }); g.appendChild(a); g.appendChild(b); return g; };
    var divider = function () { return el('div', { style: 'height:1px;background:#EEF2F6;margin:4px 0 16px;' }); };
    var dateVal = ex ? String(ex.visit_date || '').slice(0, 10) : new Date().toISOString().slice(0, 10);

    card.appendChild(section('Visit details'));
    card.appendChild(field('Centre', '<select id="hv-centre" style="' + IN + 'background:#fff;"><option value="">Loading centres…</option></select>', true));
    card.appendChild(grid2(
      field('Visit date', '<input id="hv-date" type="date" value="' + dateVal + '" style="' + IN + '">', true),
      field('Visit type', '<select id="hv-type" style="' + IN + 'background:#fff;">' + VISIT_TYPES.map(function (t) { var s = ex ? xsel('visit_type', t[0]) : (t[0] === 'routine' ? ' selected' : ''); return '<option value="' + t[0] + '"' + s + '>' + t[1] + '</option>'; }).join('') + '</select>')
    ));
    card.appendChild(grid2(
      field('Location', '<select id="hv-loc" style="' + IN + 'background:#fff;"><option value="">—</option>' + LOCATIONS.map(function (l) { return '<option value="' + l[0] + '"' + xsel('location', l[0]) + '>' + l[1] + '</option>'; }).join('') + '</select>'),
      field('Duration (minutes)', '<input id="hv-dur" type="number" min="0" max="1440" placeholder="e.g. 45" value="' + xv('duration_minutes') + '" style="' + IN + '">')
    ));

    card.appendChild(divider());
    card.appendChild(section('Family'));
    card.appendChild(grid2(
      field('Family name', '<input id="hv-family" type="text" placeholder="e.g. The Baer family" value="' + xv('family_name') + '" style="' + IN + '">'),
      field('Child name (optional)', '<input id="hv-child" type="text" value="' + xv('child_name') + '" style="' + IN + '">')
    ));
    card.appendChild(field('Who was present', '<input id="hv-present" type="text" placeholder="e.g. Mother, child" value="' + xv('present') + '" style="' + IN + '">'));

    card.appendChild(divider());
    card.appendChild(section('Observations'));
    card.appendChild(field('Visit summary', '<textarea id="hv-summary" rows="4" placeholder="What happened during the visit…" style="' + IN + 'resize:vertical;">' + xv('summary') + '</textarea>', true));
    card.appendChild(grid2(
      field('Strengths observed', '<textarea id="hv-strengths" rows="3" style="' + IN + 'resize:vertical;">' + xv('strengths') + '</textarea>'),
      field('Concerns', '<textarea id="hv-concerns" rows="3" style="' + IN + 'resize:vertical;">' + xv('concerns') + '</textarea>')
    ));
    card.appendChild(field('Next steps', '<textarea id="hv-next" rows="2" style="' + IN + 'resize:vertical;">' + xv('next_steps') + '</textarea>'));
    card.appendChild(el('div', { style: 'max-width:220px;' }, [field('Follow-up date (optional)', '<input id="hv-followup" type="date" value="' + (ex ? String(ex.follow_up_date || '').slice(0, 10) : '') + '" style="' + IN + '">')]));

    // Edit mode: a note field that gets stamped into the audit trail.
    if (ex) {
      card.appendChild(divider());
      card.appendChild(field('Add a note (optional)', '<textarea id="hv-note" rows="2" placeholder="A note explaining this change — saved to the report’s activity log." style="' + IN + 'resize:vertical;"></textarea>'));
    }

    var msg = el('div', { id: 'hv-msg', style: 'font-size:13px;min-height:18px;margin:6px 2px;' });
    if (!document.getElementById('hv-form-style')) {
      var st = document.createElement('style'); st.id = 'hv-form-style';
      st.textContent = '@media(max-width:600px){.hv-submit{width:100% !important;}.hv-submit-row{justify-content:stretch !important;}}';
      document.head.appendChild(st);
    }
    // A NEW report or the author's own DRAFT can be "Saved as draft" or submitted.
    // Amending an already-SUBMITTED report (reviewer) is just "Save changes".
    var editingSubmitted = !!(ex && ex.status === 'submitted');
    var saveRow = el('div', { class: 'hv-submit-row', style: 'display:flex;gap:10px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap;' });
    var draftBtn = null;
    if (!editingSubmitted) {
      draftBtn = el('button', { id: 'hv-draft', class: 'hv-submit', type: 'button', style: 'border:1px solid #CBD5E1;border-radius:11px;padding:12px 22px;font-weight:800;font-size:14px;color:#334155;background:#fff;cursor:pointer;' }, ['💾 Save as draft']);
      saveRow.appendChild(draftBtn);
    }
    var save = el('button', { id: 'hv-save', class: 'hv-submit', type: 'button', style: 'border:0;border-radius:11px;padding:12px 30px;font-weight:800;font-size:14px;color:#fff;background:linear-gradient(135deg,#1F6080,#159FB4);cursor:pointer;box-shadow:0 8px 18px -10px rgba(31,96,128,.85);' }, [editingSubmitted ? 'Save changes' : 'Submit report']);
    saveRow.appendChild(save);
    card.appendChild(msg); card.appendChild(saveRow);
    box.appendChild(card);

    // Edit mode: activity / audit log panel.
    if (ex) {
      var hbox = el('div', { style: 'background:#fff;border:1px solid #E7EDF3;border-radius:16px;padding:16px 18px;margin-top:16px;' });
      hbox.appendChild(el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#0FA3B1;margin-bottom:12px;' }, ['Activity log']));
      var hlist = el('div', { id: 'hv-history' });
      hbox.appendChild(hlist);
      box.appendChild(hbox);
      renderHistory(hlist, ex);
    }
    container.appendChild(box);

    Api.get('/home-visits/centres').then(function (d) {
      var sel = box.querySelector('#hv-centre');
      var cs = (d && d.centres) || [];
      sel.innerHTML = '<option value="">Select a centre…</option>' + cs.map(function (c) {
        return '<option value="' + c.id + '"' + (ex && String(ex.centre_id) === String(c.id) ? ' selected' : '') + '>' + esc(c.name) + (c.city ? ' — ' + esc(c.city) : '') + '</option>';
      }).join('');
    }).catch(function () { box.querySelector('#hv-centre').innerHTML = '<option value="">Could not load centres</option>'; });

    function doSave(status, btn) {
      var g = function (id) { var e = box.querySelector('#' + id); return e ? e.value : ''; };
      // Full validation only when SUBMITTING; a draft can be saved incomplete.
      if (status === 'submitted') {
        if (!g('hv-centre')) { msg.textContent = 'Please choose a centre.'; msg.style.color = '#DC2626'; return; }
        if (!g('hv-date')) { msg.textContent = 'Please set the visit date.'; msg.style.color = '#DC2626'; return; }
        if (!g('hv-summary').trim()) { msg.textContent = 'Please add a visit summary.'; msg.style.color = '#DC2626'; return; }
      }
      var btns = [save, draftBtn].filter(Boolean);
      btns.forEach(function (b) { b.disabled = true; });
      var oldTxt = btn.textContent; btn.textContent = 'Saving…'; msg.textContent = '';
      var payload = {
        centre_id: g('hv-centre') ? parseInt(g('hv-centre'), 10) : null,
        visit_date: g('hv-date') || null, visit_type: g('hv-type'), location: g('hv-loc') || null,
        duration_minutes: g('hv-dur') ? parseInt(g('hv-dur'), 10) : null,
        family_name: g('hv-family') || null, child_name: g('hv-child') || null,
        present: g('hv-present') || null, summary: g('hv-summary') || null,
        strengths: g('hv-strengths') || null, concerns: g('hv-concerns') || null,
        next_steps: g('hv-next') || null, follow_up_date: g('hv-followup') || null,
        status: status,
      };
      var done = function () {
        if (KT.toast) KT.toast('✅', status === 'draft' ? 'Draft saved' : (ex ? 'Report updated' : 'Report submitted'), status === 'draft' ? 'Come back any time to finish it.' : 'Your home visit report has been saved.', '#16A34A');
        window.location.hash = '#home-visits';
      };
      var fail = function (e) {
        btns.forEach(function (b) { b.disabled = false; }); btn.textContent = oldTxt;
        msg.textContent = 'Could not save: ' + (e.message || 'error'); msg.style.color = '#DC2626';
      };
      if (ex) {
        payload.note = g('hv-note') || null;
        Api.patch('/home-visits/' + ex.id, payload).then(done).catch(fail);
      } else {
        Api.post('/home-visits', payload).then(done).catch(fail);
      }
    }
    save.addEventListener('click', function () { doSave('submitted', save); });
    if (draftBtn) draftBtn.addEventListener('click', function () { doSave('draft', draftBtn); });
  }

  function renderHistory(hlist, rep) {
    clear(hlist);
    var h = (rep && rep.history) || [];
    hlist.appendChild(el('div', { style: 'display:flex;gap:8px;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:12.5px;color:#475569;' }, [
      el('span', { style: 'font-size:15px;' }, ['🆕']),
      el('div', {}, [el('strong', {}, ['Report created']), rep.created_at ? el('span', { style: 'color:#64748B;' }, [' · ' + fmtWhen(rep.created_at)]) : '']),
    ]));
    if (!h.length) {
      hlist.appendChild(el('div', { style: 'color:#64748B;font-size:12.5px;padding:10px 0;' }, ['No edits yet. Changes you save will be listed here with the date, time and your name.']));
      return;
    }
    h.slice().reverse().forEach(function (e) {
      var row = el('div', { style: 'padding:10px 0;border-bottom:1px solid #F1F5F9;' });
      row.appendChild(el('div', { style: 'font-size:12.5px;color:#0f172a;font-weight:700;' }, [(e.by || 'Someone') + ' ', el('span', { style: 'font-weight:500;color:#64748B;' }, ['· ' + fmtWhen(e.at)])]));
      (e.changes || []).forEach(function (c) {
        row.appendChild(el('div', { style: 'font-size:12px;color:#475569;margin-top:3px;' }, [
          el('strong', {}, [c.field + ': ']),
          el('span', { style: 'color:#B91C1C;text-decoration:line-through;' }, [String(c.from || '—').slice(0, 60)]),
          ' → ',
          el('span', { style: 'color:#047857;' }, [String(c.to || '—').slice(0, 60)]),
        ]));
      });
      if (e.note) row.appendChild(el('div', { style: 'font-size:12.5px;color:#334155;margin-top:5px;background:#F8FAFC;border-radius:8px;padding:7px 10px;' }, ['📝 ' + e.note]));
      hlist.appendChild(row);
    });
  }

  // ── My reports list ──────────────────────────────────────────────────
  function renderReports(container) {
    clear(container);
    var box = el('div', { style: 'max-width:720px;margin:0 auto;padding:16px 16px 90px;' });
    var head = el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:6px 2px 16px;gap:10px;' });
    head.appendChild(el('h1', { style: 'margin:0;font-size:22px;font-weight:800;color:#0f172a;' }, ['My home visits']));
    head.appendChild(el('a', { href: '#new-home-visit', style: 'background:linear-gradient(135deg,#1F6080,#159FB4);color:#fff;text-decoration:none;border-radius:10px;padding:9px 15px;font-weight:800;font-size:13px;' }, ['+ New report']));
    box.appendChild(head);
    var countEl = el('div', { style: 'font-size:12px;color:#64748B;margin:0 2px 10px;font-weight:600;' }, ['']);
    box.appendChild(countEl);
    var list = el('div', { 'data-kt-list': '1' }); box.appendChild(list);
    list.appendChild(el('div', { style: 'text-align:center;color:#64748B;padding:24px;font-size:13px;' }, ['Loading…']));
    container.appendChild(box);

    Api.get('/home-visits').then(function (d) {
      clear(list);
      var reps = (d && d.reports) || [];
      if (!reps.length) {
        countEl.textContent = '';
        list.appendChild(el('div', { style: 'text-align:center;color:#64748b;padding:40px 16px;background:#fff;border:1px dashed #CBD5E1;border-radius:16px;' }, ['No reports yet — tap “New report” to log your first home visit.']));
        return;
      }
      countEl.textContent = 'Showing ' + reps.length + ' report' + (reps.length === 1 ? '' : 's');
      var typeLabel = {}; VISIT_TYPES.forEach(function (t) { typeLabel[t[0]] = t[1]; });
      reps.forEach(function (r) {
        var c = el('div', { style: 'background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:15px;margin-bottom:12px;' });
        var top = el('div', { style: 'display:flex;justify-content:space-between;gap:10px;align-items:flex-start;' });
        top.appendChild(el('div', {}, [
          el('div', { style: 'font-weight:800;font-size:15px;color:#0f172a;' }, [r.family_name || r.child_name || 'Home visit']),
          el('div', { style: 'font-size:12.5px;color:#64748b;margin-top:2px;' }, [(r.centre_name || 'Centre') + ' · ' + (r.visit_date || '')]),
        ]));
        var rightCol = el('div', { style: 'display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex:0 0 auto;' });
        rightCol.appendChild(el('span', { style: 'background:#EEF6FB;color:#1F6080;border-radius:999px;padding:3px 11px;font-size:11px;font-weight:800;text-transform:uppercase;' }, [typeLabel[r.visit_type] || r.visit_type || 'Visit']));
        if (r.status === 'draft') rightCol.appendChild(el('span', { style: 'font-size:11px;font-weight:800;color:#92600A;background:#FEF3C7;border-radius:20px;padding:2px 9px;' }, ['Draft']));
        else if (r.history && r.history.length) rightCol.appendChild(el('span', { style: 'font-size:11.5px;color:#64748B;' }, ['✎ Edited ' + r.history.length + '×']));
        top.appendChild(rightCol);
        c.appendChild(top);
        if (r.summary) c.appendChild(el('div', { style: 'font-size:13.5px;color:#334155;margin-top:9px;line-height:1.5;' }, [r.summary]));
        if (r.next_steps) c.appendChild(el('div', { style: 'font-size:12.5px;color:#475569;margin-top:8px;background:#F8FAFC;border-radius:8px;padding:8px 10px;' }, ['Next steps: ' + r.next_steps]));
        // A PURE action bar (status moved into the header above) so kt-row-actions
        // collapses it into a ⋮ kebab, matching the reviewer table.
        var foot = el('div', { style: 'display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:10px;' });
        // A home visitor may EDIT their own DRAFT; submitted reports are view-only
        // for them (reviewers amend those). Everyone can View (report-style + PDF).
        foot.appendChild(el('a', { href: '#home-visit-view?id=' + r.id, class: 'kt-act-icon kt-act-info kt-icon-tip', title: 'View report', 'data-kttip': 'View report', 'aria-label': 'View report' }, ['ℹ️']));
        if (r.status === 'draft') foot.appendChild(el('a', { href: '#edit-home-visit?id=' + r.id, class: 'kt-act-icon kt-act-edit kt-icon-tip', title: 'Edit draft', 'data-kttip': 'Edit draft', 'aria-label': 'Edit draft' }, ['✏️']));
        c.appendChild(foot);
        list.appendChild(c);
      });
    }).catch(function (e) {
      clear(list); list.appendChild(el('div', { style: 'text-align:center;color:#B91C1C;padding:24px;font-size:13px;' }, ['Could not load reports: ' + (e.message || 'error')]));
    });
  }

  // Edit screen: read ?id= from the hash, fetch the report (with its history), edit.
  function renderEdit(container) {
    clear(container);
    var m = (window.location.hash || '').match(/[?&]id=(\d+)/);
    var id = m ? m[1] : null;
    if (!id) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">No report selected.</div>'; return; }
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading report…</div>';
    Api.get('/home-visits/' + id).then(function (d) {
      renderNewReport(container, (d && d.report) || d);
    }).catch(function (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load report: ' + esc(e.message || 'error') + '</div>';
    });
  }

  // ── Reviewer (agency admin / director) list of ALL agency home-visit reports.
  //    Desktop table with search + sort + status bar; opens/edits any report.
  function renderAdminReports(container) {
    clear(container);
    var canEdit = isReviewerUser();
    var wrap = el('div', { style: 'max-width:1120px;margin:0 auto;padding:6px 16px 90px;' });
    // No own heading — the shell injects the standard "Home visit reports" banner.
    var toolbar = el('div', { style: 'display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:6px 0 12px;' });
    var isDesktop = window.matchMedia && window.matchMedia('(min-width:701px)').matches;
    var search = el('input', { type: 'search', placeholder: '🔍 Filter by home visitor, family, centre…', style: 'flex:1 1 260px;min-width:200px;padding:8px 11px;border:1px solid #CBD5E1;border-radius:9px;font-size:13px;' });
    if (isDesktop) { toolbar.appendChild(search); wrap.appendChild(toolbar); }
    var host = el('div', {});
    var statusBar = el('div', { style: 'margin-top:10px;padding:9px 14px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:10px;font-size:12.5px;color:#475569;display:flex;flex-wrap:wrap;gap:6px 18px;align-items:center;' });
    wrap.appendChild(host); wrap.appendChild(statusBar);
    container.appendChild(wrap);
    host.appendChild(el('div', { style: 'text-align:center;color:#64748B;padding:24px;font-size:13px;' }, ['Loading home-visit reports…']));

    var typeLabel = {}; VISIT_TYPES.forEach(function (t) { typeLabel[t[0]] = t[1]; });
    var all = [];
    var state = { q: '', sortKey: 'visit_date', sortDir: 'desc' };
    var ACC = {
      visit_date: function (r) { return r.visit_date || ''; },
      home_visitor_name: function (r) { return (r.home_visitor_name || '').toLowerCase(); },
      family: function (r) { return (r.family_name || r.child_name || '').toLowerCase(); },
      centre_name: function (r) { return (r.centre_name || '').toLowerCase(); },
      visit_type: function (r) { return r.visit_type || ''; },
      status: function (r) { return r.status || ''; },
    };
    function statusBadge(r) {
      var edited = r.history && r.history.length;
      if (r.status === 'draft') return el('span', { style: 'font-size:10.5px;font-weight:700;color:#92600A;background:#FEF3C7;border-radius:20px;padding:2px 8px;' }, ['Draft']);
      if (edited) return el('span', { style: 'font-size:10.5px;font-weight:700;color:#1D4ED8;background:#DBEAFE;border-radius:20px;padding:2px 8px;', title: r.history.length + ' edit(s)' }, ['Edited']);
      return el('span', { style: 'font-size:10.5px;font-weight:700;color:#047857;background:#D1FAE5;border-radius:20px;padding:2px 8px;' }, ['Submitted']);
    }
    function fmtDate(d) { try { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return d || ''; } }
    function filtered() {
      var out = all.filter(function (r) {
        if (!state.q) return true;
        var hay = ((r.home_visitor_name || '') + ' ' + (r.family_name || '') + ' ' + (r.child_name || '') + ' ' + (r.centre_name || '')).toLowerCase();
        return hay.indexOf(state.q) !== -1;
      });
      var acc = ACC[state.sortKey] || ACC.visit_date;
      out.sort(function (a, b) { var x = acc(a), y = acc(b); return (x < y ? -1 : x > y ? 1 : 0) * (state.sortDir === 'asc' ? 1 : -1); });
      return out;
    }
    function table(rows) {
      if (!rows.length) return el('div', { style: 'padding:26px;text-align:center;color:#64748B;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;' }, ['No home-visit reports match.']);
      var scroller = el('div', { style: 'overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #E7EDF3;border-radius:12px;background:#fff;' });
      var t = el('table', { class: 'kt-table', style: 'width:100%;min-width:680px;border-collapse:collapse;font-size:13px;' });
      var heads = [['Home visitor', 'home_visitor_name'], ['Family / child', 'family'], ['Centre', 'centre_name'], ['Date', 'visit_date'], ['Type', 'visit_type'], ['Status', 'status'], ['', null]];
      var hr = el('tr', { style: 'background:#F1F5F9;' });
      heads.forEach(function (h) {
        var sortable = isDesktop && h[1];
        var arrow = (sortable && state.sortKey === h[1]) ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
        var th = el('th', { style: 'text-align:left;padding:10px 12px;font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;' + (sortable ? 'cursor:pointer;user-select:none;' : '') }, [h[0] + arrow]);
        if (sortable) th.addEventListener('click', function () { if (state.sortKey === h[1]) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; else { state.sortKey = h[1]; state.sortDir = 'asc'; } rerender(); });
        hr.appendChild(th);
      });
      t.appendChild(hr);
      rows.forEach(function (r) {
        var tr = el('tr', { style: 'border-top:1px solid #EEF2F6;' });
        tr.appendChild(el('td', { style: 'padding:10px 12px;font-weight:700;color:#0f172a;' }, [r.home_visitor_name || '—']));
        tr.appendChild(el('td', { style: 'padding:10px 12px;color:#334155;' }, [r.family_name || r.child_name || '—']));
        tr.appendChild(el('td', { style: 'padding:10px 12px;color:#475569;' }, [r.centre_name || '—']));
        tr.appendChild(el('td', { style: 'padding:10px 12px;white-space:nowrap;color:#475569;' }, [fmtDate(r.visit_date)]));
        tr.appendChild(el('td', { style: 'padding:10px 12px;color:#475569;' }, [typeLabel[r.visit_type] || r.visit_type || '—']));
        tr.appendChild(el('td', { style: 'padding:10px 12px;' }, [statusBadge(r)]));
        var act = el('td', { style: 'padding:10px 12px;text-align:right;white-space:nowrap;' });
        act.appendChild(el('a', { href: '#home-visit-view?id=' + r.id, class: 'kt-act-icon kt-act-info kt-icon-tip', title: 'View report', 'data-kttip': 'View report', 'aria-label': 'View report' }, ['ℹ️']));
        // Edit is restricted to agency admins / directors (+ platform).
        if (canEdit) act.appendChild(el('a', { href: '#edit-home-visit?id=' + r.id, class: 'kt-act-icon kt-act-edit kt-icon-tip', title: 'Edit', 'data-kttip': 'Edit', 'aria-label': 'Edit' }, ['✏️']));
        tr.appendChild(act);
        t.appendChild(tr);
      });
      scroller.appendChild(t);
      return scroller;
    }
    function rerender() {
      var rows = filtered();
      clear(host); host.appendChild(table(rows));
      var edited = all.filter(function (r) { return r.history && r.history.length; }).length;
      var drafts = all.filter(function (r) { return r.status === 'draft'; }).length;
      clear(statusBar);
      statusBar.appendChild(el('span', {}, ['Showing ', el('strong', {}, [String(rows.length)]), ' of ' + all.length + ' report' + (all.length === 1 ? '' : 's')]));
      statusBar.appendChild(el('span', {}, ['✎ ' + edited + ' edited']));
      statusBar.appendChild(el('span', {}, ['📝 ' + drafts + ' draft' + (drafts === 1 ? '' : 's')]));
    }
    search.addEventListener('input', function () { state.q = search.value.toLowerCase(); rerender(); });

    Api.get('/home-visits').then(function (d) {
      all = (d && d.reports) || [];
      rerender();
    }).catch(function (e) {
      clear(host); host.appendChild(el('div', { style: 'text-align:center;color:#B91C1C;padding:24px;font-size:13px;' }, ['Could not load reports: ' + (e.message || 'error')]));
    });
  }

  // ── Read-only report-style VIEW of a single report + PDF download. ──
  function renderReportView(container) {
    clear(container);
    var m = (window.location.hash || '').match(/[?&]id=(\d+)/);
    var id = m ? m[1] : null;
    if (!id) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">No report selected.</div>'; return; }
    var wrap = el('div', { style: 'max-width:820px;margin:0 auto;padding:16px 16px 90px;' });
    wrap.appendChild(el('div', { style: 'text-align:center;color:#64748B;padding:24px;font-size:13px;' }, ['Loading report…']));
    container.appendChild(wrap);
    var typeLabel = {}; VISIT_TYPES.forEach(function (t) { typeLabel[t[0]] = t[1]; });
    var fmtDate = function (d) { if (!d) return '—'; try { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }); } catch (e) { return d; } };

    Api.get('/home-visits/' + id).then(function (d) {
      var r = (d && d.report) || d || {};
      var canEdit = isReviewerUser();
      clear(wrap);
      // Top bar: back + PDF (+ Edit for reviewers)
      var bar = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap;' });
      var back = canEdit ? '#home-visit-reports' : '#home-visits';
      bar.appendChild(el('a', { href: back, style: 'font-size:13px;color:#1F6080;text-decoration:none;' }, ['← Back']));
      var btns = el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;' });
      if (canEdit) btns.appendChild(el('a', { href: '#edit-home-visit?id=' + id, style: 'padding:8px 15px;border:1px solid #7C3AED;border-radius:9px;background:#fff;color:#7C3AED;font-size:13px;font-weight:700;text-decoration:none;' }, ['✎ Edit']));
      var pdfBtn = el('button', { style: 'padding:8px 15px;border:none;border-radius:9px;background:#0f8a6c;color:#fff;font-size:13px;font-weight:700;cursor:pointer;' }, ['⬇ Download PDF']);
      pdfBtn.addEventListener('click', function () { reportPdf(id, pdfBtn); });
      btns.appendChild(pdfBtn); bar.appendChild(btns);
      wrap.appendChild(bar);

      // Report-style card
      var card = el('div', { style: 'background:#fff;border:1px solid #E7EDF3;border-radius:16px;padding:24px;box-shadow:0 1px 3px rgba(15,23,42,.05);' });
      card.appendChild(el('div', { style: 'border-left:5px solid #1F6080;padding:2px 0 2px 12px;margin-bottom:14px;' }, [
        el('div', { style: 'font-size:21px;font-weight:800;color:#0f172a;' }, ['Home Visit Report']),
        el('div', { style: 'font-size:13px;color:#64748b;margin-top:2px;' }, [(typeLabel[r.visit_type] || r.visit_type || 'Visit') + ' · ' + fmtDate(r.visit_date)]),
      ]));
      // status chip
      var edited = r.history && r.history.length;
      var chip = r.status === 'draft' ? ['Draft', '#92600A', '#FEF3C7'] : (edited ? ['Edited', '#1D4ED8', '#DBEAFE'] : ['Submitted', '#047857', '#D1FAE5']);
      card.appendChild(el('span', { style: 'display:inline-block;font-size:11px;font-weight:800;color:' + chip[1] + ';background:' + chip[2] + ';border-radius:20px;padding:3px 11px;margin-bottom:12px;' }, [chip[0] + (edited ? ' · ' + r.history.length + '×' : '')]));

      // details grid
      var grid = el('div', { style: 'display:grid;grid-template-columns:auto 1fr;gap:7px 16px;font-size:13.5px;margin-bottom:8px;' });
      var rowD = function (k, v) { grid.appendChild(el('div', { style: 'color:#64748b;white-space:nowrap;' }, [k])); grid.appendChild(el('div', { style: 'color:#0f172a;font-weight:600;' }, [v || '—'])); };
      rowD('Home visitor', r.home_visitor_name);
      rowD('Centre', r.centre_name);
      rowD('Family / child', (r.family_name || '') + (r.child_name ? ' — ' + r.child_name : '') || '—');
      rowD('Who was present', r.present);
      rowD('Location', r.location);
      rowD('Duration', r.duration_minutes != null ? (r.duration_minutes + ' min') : '—');
      rowD('Follow-up date', r.follow_up_date ? fmtDate(r.follow_up_date) : '—');
      card.appendChild(grid);

      var sec = function (title, body) {
        if (!body || !String(body).trim()) body = '—';
        card.appendChild(el('div', { style: 'font-size:13px;font-weight:800;color:#0f172a;border-bottom:1px solid #eef2f6;padding-bottom:4px;margin:16px 0 7px;' }, [title]));
        card.appendChild(el('div', { style: 'font-size:13.5px;color:#243244;line-height:1.6;white-space:pre-wrap;' }, [body]));
      };
      sec('Visit summary', r.summary);
      sec('Provider strengths', r.strengths);
      sec('Concerns / observations', r.concerns);
      sec('Next steps', r.next_steps);

      // audit trail
      if (edited) {
        card.appendChild(el('div', { style: 'font-size:13px;font-weight:800;color:#0f172a;border-bottom:1px solid #eef2f6;padding-bottom:4px;margin:16px 0 7px;' }, ['🕓 Edit history']));
        r.history.slice().reverse().forEach(function (h) {
          var when = h.at ? new Date(h.at).toLocaleString() : '';
          var entry = el('div', { style: 'border-top:1px solid #f1f5f9;padding:8px 0;' });
          entry.appendChild(el('div', { style: 'font-size:12.5px;color:#334155;' }, [el('strong', {}, [h.by || 'Someone']), ' · ' + when + (h.note ? ' · “' + h.note + '”' : '')]));
          (h.changes || []).forEach(function (c) {
            entry.appendChild(el('div', { style: 'font-size:12px;color:#475569;margin-top:3px;padding-left:8px;' }, [
              el('span', { style: 'font-weight:700;color:#334155;' }, [(c.field || '') + ': ']),
              el('span', { style: 'color:#B91C1C;text-decoration:line-through;' }, [String(c.from || '—')]), ' → ',
              el('span', { style: 'color:#047857;font-weight:700;' }, [String(c.to || '—')]),
            ]));
          });
          card.appendChild(entry);
        });
      }
      wrap.appendChild(card);
    }).catch(function (e) {
      clear(wrap); wrap.appendChild(el('div', { style: 'padding:24px;color:#DC2626;' }, ['Could not load the report: ' + (e.message || 'error')]));
    });
  }

  KT.Shell.registerScreen('home_visitor:home', renderHome);
  // Wrap so the shell's ctx arg is never mistaken for an "existing" report.
  KT.Shell.registerScreen('home_visitor:new-home-visit', function (c) { renderNewReport(c); });
  KT.Shell.registerScreen('home_visitor:home-visits', renderReports);
  // Home visitor edits their OWN DRAFT via this screen (submitted reports are
  // reviewer-only — the backend enforces that; the UI only links Edit on drafts).
  KT.Shell.registerScreen('home_visitor:edit-home-visit', renderEdit);
  // Reviewers (director / agency admin) can also open + edit a report.
  KT.Shell.registerScreen('centre_director:edit-home-visit', renderEdit);
  KT.Shell.registerScreen('agency_admin:edit-home-visit', renderEdit);
  KT.Shell.registerScreen('platform_admin:edit-home-visit', renderEdit);
  // Reviewer list of the agency's home-visit reports (sidebar → Home Visitors).
  ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
    KT.Shell.registerScreen(role + ':home-visit-reports', renderAdminReports);
  });
  // Read-only report VIEW (+ PDF) — available to reviewers AND the report's own
  // home visitor. Editing stays gated to reviewers (button hidden otherwise).
  ['home_visitor', 'agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
    KT.Shell.registerScreen(role + ':home-visit-view', renderReportView);
  });
})(window);
