/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p42 — Bulk invoice generation
   Hash: #bulk-invoices
   Visible to agency_admin + platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#16A34A 0%,#1F6080 60%,#0F172A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💸 BILLING</div><h1>Bulk invoice run</h1><div class="kt-hero-sub">Generate monthly invoices for every enrolled family at a centre in one click. Sibling discounts + CWELCC subsidy are applied automatically.</div>';
    wrap.appendChild(hero);

    var picker = Dom.el('div', { style: 'background:white;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin:18px 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;' });
    wrap.appendChild(picker);

    picker.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;font-weight:600;' }, 'Billing period'));
    var now = new Date();
    var monthSel = Dom.el('select', { style: selectStyle() });
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    monthNames.forEach(function (m, i) {
      var opt = Dom.el('option', { value: String(i + 1) }, m);
      if (i === now.getMonth()) opt.selected = true;
      monthSel.appendChild(opt);
    });
    var yearSel = Dom.el('select', { style: selectStyle() });
    for (var y = now.getFullYear() - 1; y <= now.getFullYear() + 1; y++) {
      var opt = Dom.el('option', { value: String(y) }, String(y));
      if (y === now.getFullYear()) opt.selected = true;
      yearSel.appendChild(opt);
    }
    picker.appendChild(monthSel);
    picker.appendChild(yearSel);

    // v22p47: export all invoices as CSV (no per-centre filter on the
    // existing endpoint, so this dumps the agency-wide list)
    var csvBtn = Dom.el('button', { style: 'background:white;color:#16A34A;border:1px solid #16A34A;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;margin-left:auto;' }, '⤓ Export invoices CSV');
    csvBtn.addEventListener('click', function () {
      var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var token = sessionStorage.getItem('kt_token');
      var activeAgencyId = sessionStorage.getItem('kt_active_agency_id') || '';
      var headers = { 'Authorization': 'Bearer ' + token, 'Accept': 'text/csv' };
      if (activeAgencyId) headers['X-Active-Agency-Id'] = activeAgencyId;
      csvBtn.disabled = true; csvBtn.textContent = 'Preparing…';
      fetch(apiBase + '/director/invoices?format=csv', { headers: headers })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
        .then(function (blob) {
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a'); a.href = url; a.download = 'invoices-' + (new Date()).toISOString().slice(0,10) + '.csv';
          document.body.appendChild(a); a.click();
          setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
        })
        .catch(function (e) { alert('CSV failed: ' + e.message); })
        .finally(function () { csvBtn.disabled = false; csvBtn.textContent = '⤓ Export invoices CSV'; });
    });
    picker.appendChild(csvBtn);

    var centresWrap = Dom.el('div', { 'data-kt-list': '1', style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(centresWrap);
    centresWrap.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, 'Loading centres…'));

    // ── Sections ────────────────────────────────────────────────────────
    // Parents is billing OUT; educators and contractors are money going the other way.
    // Keeping them in one undivided list is how somebody invoices a contractor by mistake.
    var SECTIONS = [
      { key: 'parents', label: '👪 Parents', hint: 'Raise fee invoices for families, by centre.' },
      { key: 'educators', label: '🧑‍🏫 Educators', hint: 'Paid from recorded hours — run in Payroll.' },
      { key: 'contractors', label: '🧾 Contractors & suppliers', hint: 'Bills you receive, handled in Expenses.' },
    ];
    var active = 'parents';

    var tabs = Dom.el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;' });
    var pane = Dom.el('div', {});

    function paintTabs() {
      Dom.clear(tabs);
      SECTIONS.forEach(function (sec) {
        var on = active === sec.key;
        var b = Dom.el('button', {
          type: 'button',
          style: 'background:none;border:0;border-bottom:2px solid ' + (on ? '#1F6FB2' : 'transparent')
            + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B')
            + ';cursor:pointer;border-radius:8px 8px 0 0;',
        }, sec.label);
        b.addEventListener('click', function () { active = sec.key; paintTabs(); paintPane(); });
        tabs.appendChild(b);
      });
    }

    function note(title, body, action, hash) {
      var box = Dom.el('div', { style: 'background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:18px 20px;' });
      box.appendChild(Dom.el('div', { style: 'font-size:15px;font-weight:800;color:#0F172A;margin-bottom:5px;' }, title));
      box.appendChild(Dom.el('div', { style: 'font-size:13.5px;color:#475569;line-height:1.55;' }, body));
      if (action) {
        var a = Dom.el('button', {
          style: 'margin-top:12px;background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;',
        }, action);
        a.addEventListener('click', function () { window.location.hash = hash; });
        box.appendChild(a);
      }
      return box;
    }

    function paintPane() {
      Dom.clear(pane);
      if (active === 'parents') { pane.appendChild(centresWrap); return; }

      if (active === 'educators') {
        var wrap = Dom.el('div', {});
        wrap.appendChild(note('Educators are paid from their recorded hours',
          'There is no invoice run for educators — their pay comes from clock-ins and manual entries on the timesheet, '
          + 'which Payroll turns into a payslip. Running it here would mean keying the same hours twice.',
          'Open Payroll', '#payroll'));
        var list = Dom.el('div', { style: 'margin-top:14px;background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
        list.appendChild(Dom.el('div', { style: 'padding:12px 18px;font-size:11.5px;font-weight:800;letter-spacing:.6px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #F3F4F6;' }, 'Who would be paid'));
        wrap.appendChild(list);
        pane.appendChild(wrap);
        Api.get('/provider/team-contacts').then(function (r) {
          var people = ((r && r.contacts) || []).filter(function (p) {
            return p.role === 'Educator' || p.role === 'Home visitor';
          });
          if (! people.length) {
            list.appendChild(Dom.el('div', { style: 'padding:18px;color:#64748B;font-size:13px;' }, 'No educators on file yet.'));
            return;
          }
          people.forEach(function (p) {
            var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #F7F9FB;' });
            var pic = p.photo_url || '';
            if (pic && !/^https?:\/\//.test(pic)) {
              pic = (((window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, ''))
                + (pic.charAt(0) === '/' ? pic : '/' + pic);
            }
            var av = Dom.el('span', { style: 'flex-shrink:0;display:inline-flex;' });
            if (pic && window.KT && KT.avatar) { av.innerHTML = KT.avatar(p.name, { size: 34, photoUrl: pic }); }
            else { av.innerHTML = '<span style="width:34px;height:34px;border-radius:50%;background:#1F6080;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;">' + String(p.name || '?').charAt(0).toUpperCase() + '</span>'; }
            row.appendChild(av);
            row.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:600;color:#0F172A;' }, p.name));
            row.appendChild(Dom.el('div', { style: 'margin-left:auto;font-size:12.5px;color:#64748B;' }, p.role));
            list.appendChild(row);
          });
        }).catch(function () {
          list.appendChild(Dom.el('div', { style: 'padding:18px;color:#DC2626;font-size:13px;' }, 'Could not load the team list.'));
        });
        return;
      }

      // Contractors: the accounts-payable module exists but has nothing in it. Say that
      // plainly rather than showing an empty table that looks broken.
      pane.appendChild(note('No suppliers or contractors set up yet',
        'Bills you receive — cleaners, food, maintenance, contracted staff — are handled in Expenses, where a supplier '
        + 'is raised against a purchase order and paid. Nothing has been added yet, so there is nothing to run here.',
        'Open Expenses', '#expenses'));
    }

    Api.get('/admin/centres').then(function (r) {
      Dom.clear(centresWrap);
      var centres = r.centres || [];
      if (!centres.length) {
        centresWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#6B7280;text-align:center;' }, 'No centres in this agency.'));
        return;
      }
      centres.forEach(function (c) { centresWrap.appendChild(centreRow(c, monthSel, yearSel)); });
      // The centre list is the Parents pane; the tabs decide which pane is on screen.
      if (centresWrap.parentNode) { centresWrap.parentNode.removeChild(centresWrap); }
      wrap.appendChild(tabs);
      wrap.appendChild(pane);
      paintTabs();
      paintPane();
      // Collapse each row's action buttons into the app's standard ⋮ kebab now.
      try { if (KT.sweepRowActions) KT.sweepRowActions(); } catch (e) {}
    }).catch(function (e) {
      Dom.clear(centresWrap);
      centresWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load centres: ' + (e.message || 'error')));
    });
  }

  function apiBase() { return (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function toast(icon, title, body, color) { if (window.KT && KT.toast) KT.toast(icon, title, body, color); }
  function period(monthSel, yearSel) {
    return { month: parseInt(monthSel.value, 10), year: parseInt(yearSel.value, 10), label: monthSel.options[monthSel.selectedIndex].text };
  }

  function centreRow(centre, monthSel, yearSel) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;' });

    // The provider's own face where there is one. /admin/centres has returned
    // provider_photo_url all along; this row just never asked for it, so an agency whose
    // centres ARE its providers saw a wall of coloured initials. Relative paths are
    // absolutised or they resolve against the SPA host and 404 into the fallback, which
    // looks identical to "no photo on file" — see CONVENTIONS.md.
    var pic = centre.provider_photo_url || centre.logo_url || '';
    if (pic && !/^https?:\/\//.test(pic)) {
      pic = (((window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1')
        .replace(/\/api\/v1\/?$/, '')) + (pic.charAt(0) === '/' ? pic : '/' + pic);
    }
    var swatch;
    if (pic && window.KT && KT.avatar) {
      swatch = Dom.el('span', { style: 'flex-shrink:0;display:inline-flex;' });
      swatch.innerHTML = KT.avatar(centre.name || '?', { size: 44, photoUrl: pic });
    } else {
      swatch = Dom.el('div', {
        style: 'width:44px;height:44px;border-radius:10px;background:' + (centre.brand_color || '#1F6080') + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex-shrink:0;',
      }, (centre.name || '?').charAt(0).toUpperCase());
    }
    row.appendChild(swatch);

    var info = Dom.el('div', { style: 'flex:1;min-width:0;' });
    info.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;' }, centre.name));
    info.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, (centre.city || '—') + ' · ' + (centre.enrolled_count || 0) + ' enrolled · ' + (centre.family_count || 0) + ' families'));
    row.appendChild(info);

    var status = Dom.el('div', { style: 'font-size:12px;color:#64748B;min-width:140px;text-align:right;' });
    row.appendChild(status);

    function doGenerate() {
      var p = period(monthSel, yearSel);
      KT.confirm('Generate invoices for ' + p.label + ' ' + p.year + ' at ' + centre.name + '?\n\n' +
        'This creates one invoice per enrolled family. Existing invoices for this period are NOT overwritten.').then(function (ok) {
        if (!ok) return;
        status.innerHTML = '<span style="color:#64748B;">Generating…</span>';
        Api.post('/admin/invoices/generate-batch', { centre_id: centre.id, month: p.month, year: p.year })
          .then(function (r) {
            var created = (r.created != null) ? r.created : (r.count != null ? r.count : '?');
            var total = (r.total_amount != null) ? r.total_amount : (r.total != null ? r.total : null);
            status.innerHTML = '<span style="color:#16A34A;font-weight:700;">✓ ' + created + ' created' + (total != null ? ' · $' + Number(total).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '') + '</span>';
            toast('🧾', 'Invoices generated', created + ' invoice' + (created === 1 ? '' : 's') + ' created for ' + centre.name + '.', '#16A34A');
          })
          .catch(function (e) {
            status.innerHTML = '<span style="color:#DC2626;">Failed</span>';
            toast('⚠️', 'Generation failed', (e && e.message) || 'Please try again.', '#DC2626');
          });
      });
    }

    function doEmail() {
      var p = period(monthSel, yearSel);
      KT.confirm('Email every family at ' + centre.name + ' their ' + p.label + ' ' + p.year + ' invoice (PDF)?').then(function (ok) {
        if (!ok) return;
        status.innerHTML = '<span style="color:#64748B;">Emailing…</span>';
        Api.post('/admin/invoices/email-batch', { centre_id: centre.id, month: p.month, year: p.year })
          .then(function (r) {
            status.innerHTML = '<span style="color:#1F6080;font-weight:700;">✉ ' + (r.emailed || 0) + ' emailed</span>';
            toast('✉️', 'Invoices emailed', (r.emailed || 0) + ' of ' + (r.total || 0) + ' invoice' + ((r.total === 1) ? '' : 's') + ' emailed' + (r.skipped ? ' · ' + r.skipped + ' skipped (no email on file)' : '') + '.', '#16A34A');
          })
          .catch(function (e) {
            status.innerHTML = '<span style="color:#DC2626;">Email failed</span>';
            toast('⚠️', 'Email failed', (e && e.message) || 'Please try again.', '#DC2626');
          });
      });
    }

    // Standard ⋮ kebab: a pure bar of action controls as the row's LAST child, so
    // kt-row-actions collapses Generate / View / Email into the app's usual menu.
    var actBtnStyle = 'display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;color:#374151;cursor:pointer;font-size:15px;line-height:1;padding:0;';
    var actions = Dom.el('div', { style: 'display:flex;align-items:center;gap:6px;flex-shrink:0;' });
    function actBtn(icon, label, fn) {
      var b = Dom.el('button', { type: 'button', class: 'kt-act-icon', title: label, 'aria-label': label, style: actBtnStyle }, [icon]);
      b.addEventListener('click', fn);
      return b;
    }
    actions.appendChild(actBtn('🧾', 'Generate invoices', doGenerate));
    actions.appendChild(actBtn('👁️', 'View invoices', function () { openInvoiceList(centre, monthSel, yearSel); }));
    actions.appendChild(actBtn('✉️', 'Email invoices to families', doEmail));
    row.appendChild(actions);

    return row;
  }

  // "View invoices" — a modal listing the centre's invoices for the chosen period,
  // each openable as the branded white-label invoice (View), plus an Edit link to
  // the full invoice detail.
  function openInvoiceList(centre, monthSel, yearSel) {
    var p = period(monthSel, yearSel);
    var m = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;' });
    var panel = Dom.el('div', { style: 'background:#fff;border-radius:16px;max-width:640px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);' });
    panel.appendChild(Dom.el('div', { style: 'padding:18px 20px;border-bottom:1px solid #EEF1F5;position:sticky;top:0;background:#fff;display:flex;justify-content:space-between;align-items:center;gap:10px;' }, [
      Dom.el('div', {}, [
        Dom.el('div', { style: 'font-weight:800;font-size:16px;color:#0F172A;' }, centre.name + ' — invoices'),
        Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:2px;' }, p.label + ' ' + p.year),
      ]),
      (function () { var x = Dom.el('button', { type: 'button', 'aria-label': 'Close', style: 'background:#F1F5F9;border:0;border-radius:50%;width:32px;height:32px;font-size:18px;cursor:pointer;' }, ['×']); x.addEventListener('click', function () { m.remove(); }); return x; })(),
    ]));
    var listBox = Dom.el('div', { style: 'padding:14px 18px 20px;' });
    listBox.appendChild(Dom.el('div', { style: 'color:#64748B;font-size:13px;text-align:center;padding:20px;' }, 'Loading invoices…'));
    panel.appendChild(listBox);
    m.appendChild(panel);
    m.addEventListener('click', function (e) { if (e.target === m) m.remove(); });
    document.body.appendChild(m);

    Api.get('/admin/invoices/by-centre?centre_id=' + centre.id + '&month=' + p.month + '&year=' + p.year).then(function (d) {
      Dom.clear(listBox);
      var invoices = d.invoices || [];
      if (!invoices.length) {
        listBox.appendChild(Dom.el('div', { style: 'text-align:center;color:#64748B;padding:24px;background:#F9FAFB;border:1px dashed #CBD5E1;border-radius:12px;' }, 'No invoices for this period yet. Use Generate first.'));
        return;
      }
      listBox.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:8px;' }, 'Showing ' + invoices.length + ' invoice' + (invoices.length === 1 ? '' : 's')));
      var money = function (n) { return '$' + (Number(n) || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
      invoices.forEach(function (inv) {
        var paid = inv.status === 'paid';
        var r = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:11px 4px;border-bottom:1px solid #F1F5F9;' });
        r.appendChild(Dom.el('div', { style: 'flex:1;min-width:0;' }, [
          Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#0F172A;' }, (inv.invoice_number || ('Invoice #' + inv.id))),
          Dom.el('div', { style: 'font-size:12px;color:#64748B;' }, (inv.family_name || 'Family') + ' · ' + (inv.status || '')),
        ]));
        r.appendChild(Dom.el('div', { style: 'text-align:right;font-weight:800;font-size:14px;color:' + (paid ? '#16A34A' : '#1F6080') + ';white-space:nowrap;' }, money(inv.balance_due)));
        var view = Dom.el('button', { type: 'button', class: 'kt-act-icon', title: 'View invoice', 'aria-label': 'View invoice', style: 'display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:pointer;font-size:14px;' }, ['👁️']);
        view.addEventListener('click', function () { viewInvoicePreview(inv.id); });
        r.appendChild(view);
        listBox.appendChild(r);
      });
    }).catch(function (e) {
      Dom.clear(listBox);
      listBox.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:20px;text-align:center;' }, 'Could not load invoices: ' + (e.message || 'error')));
    });
  }

  // Open the branded white-label invoice HTML (needs the auth header, so fetch it
  // then write it into a new window — a plain link would 401).
  function viewInvoicePreview(invoiceId) {
    var w = window.open('', '_blank');
    if (!w) { toast('⚠️', 'Pop-up blocked', 'Allow pop-ups to view the invoice.', '#B45309'); return; }
    w.document.write('<p style="font-family:sans-serif;padding:20px;color:#64748B;">Loading invoice…</p>');
    fetch(apiBase() + '/invoices/' + invoiceId + '/preview', { headers: { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) { w.document.open(); w.document.write(html); w.document.close(); })
      .catch(function (e) { try { w.document.body.innerHTML = '<p style="font-family:sans-serif;padding:20px;color:#DC2626;">Could not load invoice (' + (e.message || 'error') + ').</p>'; } catch (x) {} });
  }

  function selectStyle() { return 'background:white;border:1px solid #D1D5DB;border-radius:8px;padding:8px 12px;font-size:13px;color:#374151;cursor:pointer;'; }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:bulk-invoices',  render);
    Shell.registerScreen('platform_admin:bulk-invoices', render);
  }
  KT.BulkInvoices = { render: render };
})(window);
