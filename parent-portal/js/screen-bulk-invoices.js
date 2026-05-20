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
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1200px;margin:0 auto;' });
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

    var centresWrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(centresWrap);
    centresWrap.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#9CA3AF;' }, 'Loading centres…'));

    Api.get('/admin/centres').then(function (r) {
      Dom.clear(centresWrap);
      var centres = r.centres || [];
      if (!centres.length) {
        centresWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#6B7280;text-align:center;' }, 'No centres in this agency.'));
        return;
      }
      centres.forEach(function (c) { centresWrap.appendChild(centreRow(c, monthSel, yearSel)); });
    }).catch(function (e) {
      Dom.clear(centresWrap);
      centresWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load centres: ' + (e.message || 'error')));
    });
  }

  function centreRow(centre, monthSel, yearSel) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;' });

    var swatch = Dom.el('div', {
      style: 'width:44px;height:44px;border-radius:10px;background:' + (centre.brand_color || '#1F6080') + ';color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex-shrink:0;',
    }, (centre.name || '?').charAt(0).toUpperCase());
    row.appendChild(swatch);

    var info = Dom.el('div', { style: 'flex:1;min-width:0;' });
    info.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;' }, centre.name));
    info.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, (centre.city || '—') + ' · ' + (centre.enrolled_count || 0) + ' enrolled · ' + (centre.family_count || 0) + ' families'));
    row.appendChild(info);

    var status = Dom.el('div', { style: 'font-size:12px;color:#9CA3AF;min-width:180px;text-align:right;' });
    row.appendChild(status);

    var runBtn = Dom.el('button', { style: 'background:#16A34A;color:white;border:none;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;flex-shrink:0;' }, 'Generate invoices');
    row.appendChild(runBtn);

    runBtn.addEventListener('click', function () {
      var month = parseInt(monthSel.value, 10);
      var year = parseInt(yearSel.value, 10);
      var monthLabel = monthSel.options[monthSel.selectedIndex].text;
      if (!confirm('Generate invoices for ' + monthLabel + ' ' + year + ' at ' + centre.name + '?\n\n' +
                   'This creates one invoice per enrolled family. Existing invoices for this period are NOT overwritten.')) return;
      runBtn.disabled = true; runBtn.textContent = 'Generating…';
      status.textContent = '';
      Api.post('/admin/invoices/generate-batch', { centre_id: centre.id, month: month, year: year })
        .then(function (r) {
          var created = (r.created != null) ? r.created : (r.count != null ? r.count : '?');
          var total = (r.total_amount != null) ? r.total_amount : (r.total != null ? r.total : null);
          status.innerHTML = '<span style="color:#16A34A;font-weight:700;">✓ ' + created + ' invoice' + (created === 1 ? '' : 's') + ' created' + (total != null ? ' · $' + Number(total).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '') + '</span>';
          runBtn.disabled = false; runBtn.textContent = 'Generate again';
        })
        .catch(function (e) {
          status.innerHTML = '<span style="color:#DC2626;">Failed: ' + (e.message || 'error') + '</span>';
          runBtn.disabled = false; runBtn.textContent = 'Generate invoices';
        });
    });

    return row;
  }

  function selectStyle() { return 'background:white;border:1px solid #D1D5DB;border-radius:8px;padding:8px 12px;font-size:13px;color:#374151;cursor:pointer;'; }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:bulk-invoices',  render);
    Shell.registerScreen('platform_admin:bulk-invoices', render);
  }
  KT.BulkInvoices = { render: render };
})(window);
