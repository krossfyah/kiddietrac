/* ============================================================
   KIDDIETRAC v22p5 — Child detail screen
   Hash: #child-detail?id=N  (preserves ?back=centre|list etc.)
   Roles: centre_director, agency_admin
   - Shows full child record (family, room, enrollment, health flags)
   - Edit form (PATCH /api/v1/director/children/{id})
   - Archive (DELETE) — soft delete with confirmation
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmtDate(d) {
    if (!d) return '—';
    try {
      return new Date(d).toLocaleDateString('en-CA',
        { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return d; }
  }

  function parseQuery(hash) {
    var q = (hash || '').split('?')[1] || '';
    var out = {};
    q.split('&').forEach(function (kv) {
      if (!kv) return;
      var parts = kv.split('=');
      out[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1] || '');
    });
    return out;
  }

  function backHash(params) {
    if (params.back === 'centre' && params.centre_id) {
      return '#admin-centres';
    }
    if (params.centre_id) {
      return '#children?centre_id=' + encodeURIComponent(params.centre_id);
    }
    return '#children';
  }

  function row(label, value) {
    var r = Dom.el('div', {
      style: 'display:grid;grid-template-columns:160px 1fr;gap:12px;padding:8px 0;border-bottom:1px solid #F3F4F6;',
    });
    r.appendChild(Dom.el('div', {
      style: 'color:#6B7280;font-size:13px;font-weight:600;',
    }, label));
    var v = Dom.el('div', { style: 'font-size:14px;color:#111827;' });
    if (typeof value === 'string' || typeof value === 'number') {
      v.textContent = value === null || value === '' ? '—' : String(value);
    } else if (value instanceof Node) {
      v.appendChild(value);
    } else {
      v.textContent = '—';
    }
    r.appendChild(v);
    return r;
  }

  function btn(label, style, onClick) {
    var b = Dom.el('button', {
      style: 'border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;' + (style || ''),
    }, label);
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }

  function btnPrimary() {
    return 'background:#1F6080;color:white;';
  }
  function btnSecondary() {
    return 'background:white;color:#374151;border:1px solid #D1D5DB!important;';
  }
  function btnDanger() {
    return 'background:#DC2626;color:white;';
  }

  function showEditModal(child, onSaved) {
    var overlay = Dom.el('div', {
      style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow:auto;',
    });
    var modal = Dom.el('div', {
      style: 'background:white;border-radius:14px;padding:24px;max-width:640px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);',
    });
    modal.appendChild(Dom.el('h3', {
      style: 'margin:0 0 16px;font-size:18px;',
    }, 'Edit ' + (child.display_name || child.full_name)));

    var form = Dom.el('form', {});
    var fields = [
      { name: 'first_name', label: 'First name', value: child.first_name, type: 'text' },
      { name: 'last_name', label: 'Last name', value: child.last_name, type: 'text' },
      { name: 'preferred_name', label: 'Preferred name', value: child.preferred_name, type: 'text' },
      { name: 'pronouns', label: 'Pronouns', value: child.pronouns, type: 'text' },
      { name: 'date_of_birth', label: 'Date of birth', value: child.date_of_birth, type: 'date' },
      { name: 'gender', label: 'Gender', value: child.gender, type: 'select',
        options: ['female', 'male', 'non_binary', 'prefer_not_to_say', 'other'] },
      { name: 'health_card_last4', label: 'Health card (last 4)', value: child.health_card_last4, type: 'text' },
      { name: 'doctor_name', label: 'Doctor name', value: child.doctor_name, type: 'text' },
      { name: 'doctor_phone', label: 'Doctor phone', value: child.doctor_phone, type: 'text' },
      { name: 'medical_notes', label: 'Medical notes', value: child.medical_notes, type: 'textarea' },
      { name: 'dietary_notes', label: 'Dietary notes', value: child.dietary_notes, type: 'textarea' },
      { name: 'cultural_notes', label: 'Cultural notes', value: child.cultural_notes, type: 'textarea' },
    ];
    var inputs = {};
    fields.forEach(function (f) {
      var wrap = Dom.el('div', { style: 'margin-bottom:12px;' });
      wrap.appendChild(Dom.el('label', {
        style: 'display:block;margin-bottom:4px;font-size:12px;font-weight:600;color:#374151;',
      }, f.label));
      var input;
      if (f.type === 'textarea') {
        input = Dom.el('textarea', {
          name: f.name, rows: '2',
          style: 'width:100%;border:1px solid #D1D5DB;border-radius:6px;padding:7px 9px;font-size:13px;font-family:inherit;resize:vertical;',
        });
        input.value = f.value || '';
      } else if (f.type === 'select') {
        input = Dom.el('select', {
          name: f.name,
          style: 'width:100%;border:1px solid #D1D5DB;border-radius:6px;padding:7px 9px;font-size:13px;background:white;',
        });
        f.options.forEach(function (opt) {
          var o = Dom.el('option', { value: opt }, opt.replace(/_/g, ' '));
          if (opt === f.value) o.selected = true;
          input.appendChild(o);
        });
      } else {
        input = Dom.el('input', {
          type: f.type, name: f.name, value: f.value || '',
          style: 'width:100%;border:1px solid #D1D5DB;border-radius:6px;padding:7px 9px;font-size:13px;',
        });
      }
      inputs[f.name] = input;
      wrap.appendChild(input);
      form.appendChild(wrap);
    });

    var actions = Dom.el('div', {
      style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:16px;',
    });
    var cancelB = btn('Cancel', btnSecondary(), function () { overlay.remove(); });
    actions.appendChild(cancelB);
    var saveB = btn('Save changes', btnPrimary());
    actions.appendChild(saveB);
    form.appendChild(actions);

    saveB.addEventListener('click', function (ev) {
      ev.preventDefault();
      var payload = {};
      Object.keys(inputs).forEach(function (k) {
        var v = inputs[k].value;
        if (v === '') v = null;
        payload[k] = v;
      });
      saveB.disabled = true;
      saveB.textContent = 'Saving...';
      Api.patch('/director/children/' + child.id, payload)
        .then(function () {
          overlay.remove();
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Child updated', 'success');
          if (onSaved) onSaved();
        })
        .catch(function (e) {
          saveB.disabled = false;
          saveB.textContent = 'Save changes';
          alert('Save failed: ' + (e.message || 'error'));
        });
    });

    modal.appendChild(form);
    overlay.appendChild(modal);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
  }

  function showArchiveConfirm(child, onArchived) {
    var overlay = Dom.el('div', {
      style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;',
    });
    var modal = Dom.el('div', {
      style: 'background:white;border-radius:14px;padding:24px;max-width:440px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);',
    });
    modal.appendChild(Dom.el('h3', {
      style: 'margin:0 0 8px;font-size:18px;color:#DC2626;',
    }, 'Archive child?'));
    modal.appendChild(Dom.el('p', {
      style: 'margin:0 0 16px;font-size:14px;color:#374151;line-height:1.5;',
    }, 'Archiving ' + (child.display_name || child.full_name) + ' will end any active enrollment and remove the child from active rosters. Historical records (invoices, observations, photos) are preserved. This can be restored by an agency admin.'));
    var actions = Dom.el('div', {
      style: 'display:flex;justify-content:flex-end;gap:8px;',
    });
    actions.appendChild(btn('Cancel', btnSecondary(), function () { overlay.remove(); }));
    var confirmB = btn('Yes, archive', btnDanger());
    confirmB.addEventListener('click', function () {
      confirmB.disabled = true;
      confirmB.textContent = 'Archiving...';
      Api.delete('/director/children/' + child.id)
        .then(function () {
          overlay.remove();
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Child archived', 'success');
          if (onArchived) onArchived();
        })
        .catch(function (e) {
          confirmB.disabled = false;
          confirmB.textContent = 'Yes, archive';
          alert('Archive failed: ' + (e.message || 'error'));
        });
    });
    actions.appendChild(confirmB);
    modal.appendChild(actions);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
  }

  function renderChildDetail(container) {
    Dom.clear(container);
    var params = parseQuery(window.location.hash);
    var childId = params.id;
    if (!childId) {
      container.appendChild(Dom.el('div', {
        style: 'padding:24px;color:#DC2626;',
      }, 'Missing child id. Go back to the children list.'));
      return;
    }

    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var loading = Dom.el('div', {
      style: 'padding:40px;text-align:center;color:#6B7280;',
    }, 'Loading child record...');
    wrap.appendChild(loading);

    Api.get('/director/children/' + childId).then(function (data) {
      Dom.clear(wrap);
      var child = data;

      // Back link
      var backWrap = Dom.el('div', { style: 'margin-bottom:16px;' });
      var backLink = Dom.el('a', {
        href: backHash(params),
        style: 'color:#1F6080;text-decoration:none;font-size:13px;',
      }, '← Back');
      backWrap.appendChild(backLink);
      wrap.appendChild(backWrap);

      // Header
      var header = Dom.el('div', {
        style: 'display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;',
      });
      var headerLeft = Dom.el('div');
      headerLeft.appendChild(Dom.el('h1', {
        style: 'font-size:26px;margin:0 0 4px;color:#111827;',
      }, child.full_name + (child.preferred_name ? '  (' + child.preferred_name + ')' : '')));
      headerLeft.appendChild(Dom.el('div', {
        style: 'color:#6B7280;font-size:14px;',
      }, (child.age && child.age.human ? child.age.human : '—') + '  ·  born ' + fmtDate(child.date_of_birth)));
      header.appendChild(headerLeft);

      var headerActions = Dom.el('div', {
        style: 'display:flex;gap:8px;',
      });
      headerActions.appendChild(btn('✏️ Edit', btnPrimary(), function () {
        showEditModal(child, function () { renderChildDetail(container); });
      }));
      headerActions.appendChild(btn('🚨 Emergency card', btnSecondary(), function () {
        var token = sessionStorage.getItem('kt_token');
        var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        var hdrs = { 'Authorization': 'Bearer ' + token, 'Accept': 'text/html' };
        var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) hdrs['X-Active-Agency-Id'] = aa;
        fetch(apiBase + '/director/children/' + child.id + '/emergency-card', {
          headers: hdrs,
        }).then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        }).then(function (html) {
          var w = window.open('', '_blank');
          if (!w) { alert('Pop-up blocked. Allow pop-ups to view the emergency card.'); return; }
          w.document.open();
          w.document.write(html);
          w.document.close();
        }).catch(function (e) { alert('Could not load emergency card: ' + e.message); });
      }));
      headerActions.appendChild(btn('🗄️ Archive', btnDanger(), function () {
        showArchiveConfirm(child, function () {
          window.location.hash = backHash(params);
        });
      }));
      headerActions.appendChild(btn('📋 Compliance report', btnSecondary(), function () {
        var _e = function (s) { return (s == null ? '' : String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
        var hf = (child.health_flags && child.health_flags.length) ? child.health_flags.map(function (f) { return '<li>' + _e(f.label || f.type || f.note || 'Health flag') + '</li>'; }).join('') : '<li>None on file</li>';
        var w = window.open('', '_blank'); if (!w) { alert('Please allow pop-ups to generate the report.'); return; }
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Compliance Report - ' + _e(child.full_name) + '</title>'
          + '<style>body{font-family:Arial,Helvetica,sans-serif;color:#0a1e2c;max-width:760px;margin:22px auto;padding:0 22px}h1{color:#1F6080;font-size:23px;margin:0 0 2px}.sub{color:#777;font-size:13px;margin-bottom:18px}table{width:100%;border-collapse:collapse;margin-bottom:6px}td{padding:7px 4px;border-bottom:1px solid #eee;font-size:14px;vertical-align:top}td.l{color:#666;width:210px}h2{font-size:14px;color:#1F6080;border-bottom:2px solid #1F6080;padding-bottom:4px;margin:22px 0 6px;text-transform:uppercase;letter-spacing:.5px}ul{margin:6px 0 6px 18px}.foot{margin-top:30px;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:10px}@media print{.noprint{display:none}}</style></head><body>'
          + '<div style="display:flex;justify-content:space-between;align-items:flex-start"><div><h1>Child Compliance Report</h1><div class="sub">Generated ' + _e(new Date().toLocaleString()) + '</div></div>'
          + '<button class="noprint" onclick="window.print()" style="padding:9px 16px;background:#1F6080;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px">Print / Save as PDF</button></div>'
          + '<h2>Child</h2><table>'
          + '<tr><td class="l">Full name</td><td>' + _e(child.full_name) + '</td></tr>'
          + '<tr><td class="l">Preferred name</td><td>' + _e(child.preferred_name || '—') + '</td></tr>'
          + '<tr><td class="l">Date of birth</td><td>' + _e(fmtDate(child.date_of_birth)) + '</td></tr>'
          + '<tr><td class="l">Age</td><td>' + _e(child.age && child.age.human ? child.age.human : '—') + '</td></tr>'
          + '<tr><td class="l">Gender</td><td>' + _e(child.gender ? child.gender.replace(/_/g, ' ') : '—') + '</td></tr>'
          + '<tr><td class="l">Pronouns</td><td>' + _e(child.pronouns || '—') + '</td></tr></table>'
          + '<h2>Enrollment</h2><table>'
          + '<tr><td class="l">Family</td><td>' + _e(child.family ? child.family.family_name : '—') + '</td></tr>'
          + '<tr><td class="l">Room</td><td>' + _e(child.room ? child.room.name : '—') + '</td></tr>'
          + '<tr><td class="l">Status</td><td>' + ((child.enrollment && child.enrollment.end_date) ? 'Withdrawn' : (child.enrollment ? 'Enrolled' : '—')) + '</td></tr>'
          + '<tr><td class="l">Enrolled since</td><td>' + _e(child.enrollment ? fmtDate(child.enrollment.start_date) : '—') + '</td></tr></table>'
          + '<h2>Health &amp; Safety</h2><table>'
          + '<tr><td class="l">Doctor</td><td>' + _e(child.doctor_name ? (child.doctor_name + (child.doctor_phone ? ' · ' + child.doctor_phone : '')) : '—') + '</td></tr>'
          + '<tr><td class="l">Health card (last 4)</td><td>' + _e(child.health_card_last4 ? ('xxxx-xxxx-' + child.health_card_last4) : '—') + '</td></tr></table>'
          + '<h2>Active health flags</h2><ul>' + hf + '</ul>'
          + '<div class="foot">KiddieTrac &middot; Reflects records on file at generation time. Confirm current requirements with your licensing authority.</div>'
          + '</body></html>'); w.document.close();
      }));
      header.appendChild(headerActions);
      wrap.appendChild(header);

      // Detail card
      var card = Dom.el('div', {
        style: 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;',
      });
      card.appendChild(row('Status',
        '' + ((child.enrollment && !child.is_at_centre) ? (child.enrollment.end_date ? 'WITHDRAWN' : 'ENROLLED') : (child.is_at_centre ? 'AT CENTRE NOW' : '—'))));
      card.appendChild(row('Gender', child.gender ? child.gender.replace(/_/g, ' ') : '—'));
      card.appendChild(row('Pronouns', child.pronouns));
      card.appendChild(row('Family', child.family ? child.family.family_name : '—'));
      card.appendChild(row('Room', child.room ? child.room.name : '—'));
      if (child.enrollment) {
        card.appendChild(row('Enrolled since', fmtDate(child.enrollment.start_date)));
        card.appendChild(row('Monthly fee', child.enrollment.monthly_fee ? ('$' + child.enrollment.monthly_fee) : '—'));
      }
      card.appendChild(row('Doctor', child.doctor_name ? (child.doctor_name + (child.doctor_phone ? ' · ' + child.doctor_phone : '')) : '—'));
      card.appendChild(row('Health card', child.health_card_last4 ? ('xxxx-xxxx-' + child.health_card_last4) : '—'));
      card.appendChild(row('Medical notes', child.medical_notes));
      card.appendChild(row('Dietary notes', child.dietary_notes));
      card.appendChild(row('Cultural notes', child.cultural_notes));
      wrap.appendChild(card);

      // #10 — Allergies & health alerts: safety-critical, so shown prominently in
      // a red-bordered banner whenever there's an allergy or health alert on file.
      (function () {
        var items = [];
        if (child.allergies) items.push(['Allergies', child.allergies, true]);
        if (child.health_alerts) items.push(['Health alerts', child.health_alerts, true]);
        if (child.dietary_restrictions) items.push(['Dietary restrictions', child.dietary_restrictions, false]);
        var critical = !!(child.allergies || child.health_alerts);
        var sec = Dom.el('div', { style: 'border-radius:14px;padding:16px 20px;margin-bottom:16px;border:2px solid ' + (critical ? '#EF4444' : '#E5E7EB') + ';background:' + (critical ? '#FEF2F2' : '#F9FAFB') + ';' });
        sec.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + (critical ? '#B91C1C' : '#6B7280') + ';margin-bottom:' + (items.length ? '12px' : '0') + ';' }, (critical ? '⚠️ ' : '') + 'Allergies & health alerts'));
        if (!items.length) {
          sec.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13.5px;' }, 'None on file.'));
        } else {
          items.forEach(function (it) {
            var r = Dom.el('div', { style: 'display:flex;gap:14px;padding:7px 0;border-top:1px solid ' + (critical ? '#FECACA' : '#EEF2F5') + ';' });
            r.appendChild(Dom.el('div', { style: 'flex:0 0 150px;font-size:12.5px;font-weight:700;color:' + (it[2] ? '#B91C1C' : '#6B7280') + ';text-transform:uppercase;letter-spacing:.3px;' }, it[0]));
            r.appendChild(Dom.el('div', { style: 'flex:1;font-size:14px;font-weight:600;color:#0F172A;white-space:pre-wrap;' }, String(it[1])));
            sec.appendChild(r);
          });
        }
        wrap.appendChild(sec);
      })();

      // #10 — Family record: full address + contacts + guardians, right on the
      // child's detail so staff can reach the family without hunting for it.
      (function () {
        var f = child.family;
        if (!f) return;
        var esc2 = function (s) { return String(s == null ? '' : s); };
        var addr = [f.address_line1, f.address_line2, f.city, f.province, f.postal_code].filter(Boolean).join(', ');
        var sec = Dom.el('div', { style: 'background:white;border-radius:14px;padding:16px 20px 18px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;' });
        var head = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;' });
        head.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#6B7280;' }, '👪 Family record'));
        var openLink = Dom.el('a', { href: '#admin-families', style: 'font-size:12.5px;color:#1F6080;text-decoration:none;font-weight:700;' }, 'Open in Families →');
        head.appendChild(openLink);
        sec.appendChild(head);
        var grid = Dom.el('table', { 'data-kt-filtered': '1', style: 'width:100%;border-collapse:collapse;font-size:13.5px;' });
        var addRow = function (label, value) {
          if (!value) return;
          var tr = Dom.el('tr', {});
          tr.appendChild(Dom.el('td', { style: 'padding:5px 14px 5px 0;color:#64748B;white-space:nowrap;vertical-align:top;width:150px;' }, label));
          tr.appendChild(Dom.el('td', { style: 'padding:5px 0;font-weight:600;color:#0F172A;' }, esc2(value)));
          grid.appendChild(tr);
        };
        addRow('Family', f.family_name);
        addRow('Address', addr);
        addRow('Phone', f.primary_phone);
        addRow('Email', f.primary_email);
        addRow('Language', f.preferred_lang);
        sec.appendChild(grid);
        // Guardians / contacts.
        if (child.guardians && child.guardians.length) {
          sec.appendChild(Dom.el('div', { style: 'font-size:11.5px;font-weight:800;color:#6B7280;text-transform:uppercase;letter-spacing:.3px;margin:12px 0 6px;' }, 'Guardians'));
          child.guardians.forEach(function (g) {
            var name = ((g.first_name || '') + ' ' + (g.last_name || '')).trim() || 'Guardian';
            var bits = [];
            if (g.relationship) bits.push(g.relationship);
            if (g.is_primary) bits.push('primary');
            if (g.can_pickup) bits.push('can pick up');
            var gr = Dom.el('div', { style: 'display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px solid #EEF2F5;font-size:13px;' });
            gr.appendChild(Dom.el('div', {}, [
              Dom.el('div', { style: 'font-weight:700;color:#0F172A;' }, name + (bits.length ? ' · ' + bits.join(', ') : '')),
              g.email ? Dom.el('div', { style: 'color:#64748B;font-size:12px;' }, g.email) : Dom.el('span', {}),
            ]));
            sec.appendChild(gr);
          });
        }
        wrap.appendChild(sec);
      })();

      // v22p88: tabbed sections — Overview / Enrollment / Daily reports / Live feed / Attachments
      var TABS = [['overview', '👤 Overview'], ['enrollment', '🏫 Enrollment'], ['daily', '📊 Daily reports'], ['feed', '📸 Live feed'], ['attachments', '📎 Attachments']];
      var activeTab = 'overview';
      var tabbar = Dom.el('div', { style: 'display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin:4px 0 18px;overflow-x:auto;overflow-y:hidden;' });
      var tabContent = Dom.el('div', {});
      function paintTabs() {
        Dom.clear(tabbar);
        TABS.forEach(function (t) {
          var on = activeTab === t[0];
          var b = Dom.el('button', { style: 'padding:10px 16px;border:none;background:transparent;cursor:pointer;font-size:14px;font-weight:600;white-space:nowrap;' + (on ? 'color:#1F6080;border-bottom:2px solid #1F6080;margin-bottom:-1px;' : 'color:#6B7280;') }, t[1]);
          b.addEventListener('click', function () { activeTab = t[0]; paintTabs(); paintTab(); });
          tabbar.appendChild(b);
        });
      }
      function paintTab() {
        Dom.clear(tabContent);
        if (activeTab === 'enrollment') renderEnrollmentTab(tabContent, child);
        else if (activeTab === 'daily') renderDailyTab(tabContent, child);
        else if (activeTab === 'feed') renderFeedTab(tabContent, child);
        else if (activeTab === 'attachments') renderAttachmentsTab(tabContent, child);
        else renderOverviewTab(tabContent, child);
      }
      wrap.appendChild(tabbar);
      wrap.appendChild(tabContent);
      paintTabs();
      paintTab();
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', {
        style: 'padding:24px;color:#DC2626;',
      }, 'Could not load child: ' + (e.message || 'error')));
    });
  }

  function tcard() { return 'background:white;border-radius:14px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;'; }
  function loadingBox(msg) { return Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, msg || 'Loading…'); }
  function emptyBox(msg) { return Dom.el('div', { style: 'padding:30px;text-align:center;color:#6B7280;' }, msg); }
  function fmtDateTime(s) { if (!s) return '—'; try { return new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return s; } }
  function mediaUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + u;
  }
  function detailRow(l, v) {
    var r = Dom.el('div', { style: 'display:grid;grid-template-columns:180px 1fr;gap:10px;padding:6px 0;font-size:14px;border-bottom:1px solid #F3F4F6;' });
    r.appendChild(Dom.el('div', { style: 'color:#6B7280;font-weight:600;' }, l));
    r.appendChild(Dom.el('div', {}, v || '—'));
    return r;
  }

  function renderOverviewTab(c, child) {
    if (child.guardians && child.guardians.length) {
      var g = Dom.el('div', { style: tcard() });
      g.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Guardians'));
      child.guardians.forEach(function (gu) {
        var line = (gu.first_name || '') + ' ' + (gu.last_name || '') + (gu.relationship ? ' · ' + gu.relationship : '') + (gu.is_primary ? ' (primary)' : '') + (gu.email ? '  ·  ' + gu.email : '') + (!gu.can_pickup ? '  ·  cannot pick up' : '');
        g.appendChild(Dom.el('div', { style: 'padding:6px 0;font-size:14px;border-bottom:1px solid #F3F4F6;' }, line));
      });
      c.appendChild(g);
    }
    if (child.health_flags && child.health_flags.length) {
      var h = Dom.el('div', { style: 'background:#FEF3C7;border-radius:14px;padding:20px 24px;margin-bottom:16px;' });
      h.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;color:#92400E;' }, '⚠️ Active health flags'));
      child.health_flags.forEach(function (hf) { h.appendChild(Dom.el('div', { style: 'padding:4px 0;font-size:14px;color:#92400E;' }, (hf.flag_type || 'flag').toUpperCase() + ': ' + (hf.detail || hf.name || ''))); });
      c.appendChild(h);
    }
    if (!c.children.length) c.appendChild(emptyBox('No guardians or health flags on file.'));
  }

  function renderEnrollmentTab(c, child) {
    var card = Dom.el('div', { style: tcard() });
    card.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Current placement'));
    card.appendChild(detailRow('Agency', child.agency ? child.agency.name : '—'));
    card.appendChild(detailRow('Centre', child.centre ? (child.centre.name + (child.centre.city ? ' · ' + child.centre.city : '')) : '—'));
    card.appendChild(detailRow('Room', child.room ? child.room.name : '—'));
    card.appendChild(detailRow('Status', (child.enrollment && child.enrollment.end_date) ? 'Withdrawn' : (child.enrollment ? 'Enrolled' : 'Not enrolled')));
    card.appendChild(detailRow('Enrolled since', child.enrollment ? fmtDate(child.enrollment.start_date) : '—'));
    card.appendChild(detailRow('Monthly fee', (child.enrollment && child.enrollment.monthly_fee) ? ('$' + child.enrollment.monthly_fee) : '—'));
    c.appendChild(card);

    var sc = Dom.el('div', { style: tcard() });
    sc.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, '🏫 School details'));
    if (child.school_name) { sc.appendChild(detailRow('School', child.school_name)); sc.appendChild(detailRow('Grade', child.school_grade)); }
    else sc.appendChild(emptyBox('No school on file (for school-age children, add it via Edit).'));
    c.appendChild(sc);

    var hist = Dom.el('div', { style: tcard() });
    hist.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Enrollment history'));
    var hh = child.enrollment_history || [];
    if (!hh.length) hist.appendChild(emptyBox('No enrollment history.'));
    else hh.forEach(function (e) {
      var line = (e.room_name || 'Room —') + '  ·  ' + fmtDate(e.start_date) + ' → ' + (e.end_date ? fmtDate(e.end_date) : 'present') + (e.monthly_fee ? '  ·  $' + e.monthly_fee + '/mo' : '');
      hist.appendChild(Dom.el('div', { style: 'padding:7px 0;font-size:14px;border-bottom:1px solid #F3F4F6;' }, line));
    });
    c.appendChild(hist);

    // The child's attendance pattern, on their record where it is actually needed.
    // Rendered by the pattern screen itself (KT.AttendancePattern.renderInto) with
    // this child preselected, so there is one editor rather than a read-only copy
    // here and the real thing somewhere else.
    var pat = Dom.el('div', { style: tcard() });
    pat.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:16px;' }, 'Attendance pattern'));
    pat.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-bottom:10px;' },
      'Which days this child normally attends, and when in the day. Drives ratios, room planning and tuition projections.'));
    var patHost = Dom.el('div', {});
    pat.appendChild(patHost);
    c.appendChild(pat);
    try {
      if (window.KT && KT.AttendancePattern && KT.AttendancePattern.renderInto) {
        KT.AttendancePattern.renderInto(patHost, child.id);
      } else {
        // The pattern screen's file has not loaded — say so and offer the screen,
        // rather than leaving an empty card that looks like "no pattern".
        patHost.appendChild(Dom.el('div', { style: 'font-size:13px;color:#64748B;' },
          'Open Attendance pattern from the menu to set this.'));
      }
    } catch (e) {
      patHost.appendChild(Dom.el('div', { style: 'font-size:13px;color:#B45309;' },
        'Could not load the attendance pattern editor.'));
    }
  }

  function renderDailyTab(c, child) {
    c.appendChild(loadingBox());
    Api.get('/director/children/' + child.id + '/daily-events?days=21').then(function (d) {
      Dom.clear(c);
      var all = [].concat((d.checks || []).map(function (x) { return { t: x.occurred_at, type: 'check', label: (x.event_type || '').replace(/_/g, ' '), notes: x.notes }; }),
        (d.events || []).map(function (x) { return { t: x.occurred_at, type: 'event', label: (x.event_type || x.type || 'event').replace(/_/g, ' '), notes: x.notes }; }));
      all.sort(function (a, b) { return (b.t || '').localeCompare(a.t || ''); });
      if (!all.length) { c.appendChild(emptyBox('No daily activity in the last 21 days.')); return; }
      var card = Dom.el('div', { style: tcard() });
      all.forEach(function (e) {
        var r = Dom.el('div', { style: 'display:flex;gap:12px;padding:9px 0;border-bottom:1px solid #F3F4F6;font-size:14px;' });
        r.appendChild(Dom.el('div', { style: 'width:150px;flex-shrink:0;color:#6B7280;font-size:12px;' }, fmtDateTime(e.t)));
        r.appendChild(Dom.el('span', { style: 'flex-shrink:0;' + (e.type === 'check' ? 'background:#DBEAFE;color:#1E40AF;' : 'background:#F3E8FF;color:#7C3AED;') + 'font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;height:fit-content;' }, e.type));
        var txt = Dom.el('div', {});
        txt.appendChild(Dom.el('div', { style: 'font-weight:600;text-transform:capitalize;' }, e.label || '—'));
        if (e.notes) txt.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, e.notes));
        r.appendChild(txt);
        card.appendChild(r);
      });
      c.appendChild(card);
    }).catch(function (e) { Dom.clear(c); c.appendChild(emptyBox('Could not load daily reports: ' + (e.message || 'error'))); });
  }

  function renderFeedTab(c, child) {
    c.appendChild(loadingBox());
    Api.get('/director/children/' + child.id + '/feed').then(function (d) {
      Dom.clear(c);
      var media = d.media || [], obs = d.observations || [];
      if (!media.length && !obs.length) { c.appendChild(emptyBox('No photos or observations yet.')); return; }
      if (media.length) {
        var grid = Dom.el('div', { style: tcard() + 'display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;' });
        media.forEach(function (m) {
          var url = m.url || m.file_url || m.media_url || m.thumbnail_url; if (!url) return;
          var fig = Dom.el('div', {});
          fig.appendChild(Dom.el('img', { src: mediaUrl(url), style: 'width:100%;height:130px;object-fit:cover;border-radius:10px;background:#F3F4F6;' }));
          if (m.caption) fig.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:4px;' }, m.caption));
          fig.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, fmtDate(m.created_at)));
          grid.appendChild(fig);
        });
        c.appendChild(grid);
      }
      if (obs.length) {
        var oc = Dom.el('div', { style: tcard() });
        oc.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:16px;' }, 'Observations'));
        obs.forEach(function (o) {
          var r = Dom.el('div', { style: 'padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:14px;' });
          r.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:12px;' }, fmtDate(o.created_at) + (o.domain ? '  ·  ' + String(o.domain).replace(/_/g, ' ') : '')));
          r.appendChild(Dom.el('div', {}, o.note || o.text || o.title || '—'));
          oc.appendChild(r);
        });
        c.appendChild(oc);
      }
    }).catch(function (e) { Dom.clear(c); c.appendChild(emptyBox('Could not load live feed: ' + (e.message || 'error'))); });
  }

  function renderAttachmentsTab(c, child) {
    c.appendChild(loadingBox());
    Api.get('/director/children/' + child.id + '/documents').then(function (d) {
      Dom.clear(c);
      var docs = d.documents || [];
      if (!docs.length) { c.appendChild(emptyBox('No attachments on file for this child.')); return; }
      var card = Dom.el('div', { style: tcard() });
      docs.forEach(function (doc) {
        var r = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #F3F4F6;font-size:14px;' });
        r.appendChild(Dom.el('span', {}, '📎'));
        var info = Dom.el('div', { style: 'flex:1;min-width:0;' });
        info.appendChild(Dom.el('div', { style: 'font-weight:600;' }, doc.title || 'Document'));
        info.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, (doc.category || '') + '  ·  ' + fmtDate(doc.created_at) + (doc.expires_at ? '  ·  expires ' + fmtDate(doc.expires_at) : '')));
        r.appendChild(info);
        if (doc.file_url) r.appendChild(Dom.el('a', { href: mediaUrl(doc.file_url), target: '_blank', style: 'color:#1F6080;font-size:13px;font-weight:600;text-decoration:none;' }, 'Open ↗'));
        card.appendChild(r);
      });
      c.appendChild(card);
    }).catch(function (e) { Dom.clear(c); c.appendChild(emptyBox('Could not load attachments: ' + (e.message || 'error'))); });
  }

  // Register for both director and agency_admin roles via Shell.
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('centre_director:child-detail', renderChildDetail);
    Shell.registerScreen('agency_admin:child-detail', renderChildDetail);
  }

  // Expose for tests / debug.
  KT.ChildDetail = { render: renderChildDetail };
})(window);
