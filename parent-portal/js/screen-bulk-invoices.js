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


    function money(n) {
      var v = Number(n) || 0;
      try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(v); }
      catch (e) { return '$' + v.toFixed(2); }
    }

    var STATUS_TONE = {
      upcoming: { bg: '#FEF3C7', fg: '#92400E' },
      issued:   { bg: '#E0F2FE', fg: '#075985' },
      paid:     { bg: '#DCFCE7', fg: '#166534' },
      void:     { bg: '#F3F4F6', fg: '#475569' },
    };

    /* One dialog for all three kinds — the question is the same shape every time, and
       three near-identical dialogs drift apart the first time one gets fixed. */
    function openGenerate(kind, payee, onDone, prefill) {
      var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;' });
      var m = Dom.el('div', { style: 'background:#fff;border-radius:14px;max-width:460px;width:100%;padding:20px 22px;max-height:90vh;overflow-y:auto;box-shadow:0 18px 48px rgba(0,0,0,.28);' });
      ov.appendChild(m);
      var label = kind === 'educator' ? 'educator' : (kind === 'parent' ? 'family' : 'contractor');
      var fld = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;';

      m.innerHTML = '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">🧾 Generate an invoice</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin:0 0 14px;">For a ' + label + '. Nothing is sent — this records what is owed.</div>'
        + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Who</label>'
        + '<select id="pi-who" style="' + fld + 'background:#fff;margin-bottom:6px;"><option value="">Loading…</option></select>'
        // Kept as a fallback: a contractor being paid for the first time is not on any
        // list yet, and refusing to invoice them until somebody creates a supplier record
        // would make this screen useless on the day it is most needed.
        + '<input id="pi-name" type="text" value="' + esc(payee && payee.name ? payee.name : '') + '" placeholder="…or type a name" style="' + fld + 'margin-bottom:12px;">'
        + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Based on</label>'
        + '<select id="pi-basis" style="' + fld + 'background:#fff;margin-bottom:12px;">'
        +   '<option value="amount">A set amount</option>'
        +   '<option value="hours"' + (kind === 'educator' ? ' selected' : '') + '>Hours worked × a rate</option>'
        + '</select>'
        + '<div id="pi-amount-wrap"><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Amount</label>'
        +   '<input id="pi-amount" type="number" min="0" step="0.01" placeholder="0.00" style="' + fld + 'margin-bottom:12px;"></div>'
        + '<div id="pi-hours-wrap" style="display:none;">'
        +   '<div style="display:flex;gap:8px;margin-bottom:6px;">'
        +     '<div style="flex:1;"><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Hours</label>'
        +       '<input id="pi-hours" type="number" min="0" step="0.25" placeholder="0" style="' + fld + '"></div>'
        +     '<div style="flex:1;"><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Rate / hour</label>'
        +       '<input id="pi-rate" type="number" min="0" step="0.01" placeholder="0.00" style="' + fld + '"></div>'
        +   '</div>'
        +   (payee && payee.id ? '<button id="pi-fetch" type="button" style="background:#EAF3F6;color:#1F6080;border:1px solid #CFE3EB;padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;margin-bottom:8px;">Use hours from the timesheet</button>' : '')
        +   '<div id="pi-calc" style="font-size:13px;font-weight:800;color:#0F172A;margin-bottom:10px;"></div>'
        + '</div>'
        + '<div style="display:flex;gap:8px;margin-bottom:12px;">'
        +   '<div style="flex:1;"><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Period from</label>'
        +     '<input id="pi-from" type="date" style="' + fld + '"></div>'
        +   '<div style="flex:1;"><label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">to</label>'
        +     '<input id="pi-to" type="date" style="' + fld + '"></div>'
        + '</div>'
        + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Details</label>'
        + '<textarea id="pi-details" rows="2" placeholder="What this covers" style="' + fld + 'font-family:inherit;resize:vertical;margin-bottom:12px;"></textarea>'
        + '<div style="font-size:12px;font-weight:800;letter-spacing:.6px;color:#64748B;text-transform:uppercase;margin:4px 0 6px;">Extra line items</div>'
        + '<div id="pi-lines"></div>'
        + '<button id="pi-addline" type="button" style="background:#EAF3F6;color:#1F6080;border:1px solid #CFE3EB;padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;margin-bottom:12px;">+ Add a line</button>'
        + '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:6px;">'
        +   '<input id="pi-tax" type="checkbox" style="width:16px;height:16px;">'
        +   '<span style="font-size:14px;font-weight:600;color:#334155;">Apply tax</span></label>'
        + '<div id="pi-tax-wrap" style="display:none;gap:8px;margin-bottom:12px;">'
        +   '<input id="pi-taxrate" type="number" min="0" max="100" step="0.01" placeholder="agency default" style="' + fld + 'flex:1;min-width:0;">'
        +   '<input id="pi-taxlabel" type="text" placeholder="HST" style="' + fld + 'flex:0 0 90px;">'
        + '</div>'
        + '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px;">'
        +   '<input id="pi-rec" type="checkbox" style="width:16px;height:16px;">'
        +   '<span style="font-size:14px;font-weight:600;color:#334155;">Repeat this on a schedule</span></label>'
        + '<select id="pi-freq" style="' + fld + 'background:#fff;display:none;margin-bottom:12px;">'
        +   '<option value="weekly">Every week</option><option value="biweekly">Every two weeks</option>'
        +   '<option value="monthly" selected>Every month</option></select>'
        + '<div id="pi-err" style="color:#DC2626;font-size:12.5px;min-height:17px;"></div>'
        + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
        +   '<button id="pi-cancel" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>'
        +   '<button id="pi-save" style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Generate</button>'
        + '</div>';
      document.body.appendChild(ov);
      ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
      m.querySelector('#pi-cancel').addEventListener('click', function () { ov.remove(); });

      // Populate the selector from whichever list this section is about.
      var whoSel = m.querySelector('#pi-who');
      var whoMap = {};
      (function loadPayees() {
        var url = kind === 'educator' ? '/provider/team-contacts'
          : (kind === 'parent' ? '/admin/families' : '/admin/suppliers');
        Api.get(url).then(function (r) {
          var list = [];
          if (kind === 'educator') {
            list = ((r && r.contacts) || []).filter(function (p) {
              return p.role === 'Educator' || p.role === 'Home visitor' || p.role === 'Director';
            }).map(function (p) { return { id: p.id, name: p.name }; });
          } else if (kind === 'parent') {
            list = ((r && (r.data || r.families)) || []).map(function (f) {
              return { id: f.id, name: f.family_name || ('Family #' + f.id) };
            });
          } else {
            list = ((r && (r.suppliers || r.data)) || []).map(function (x) { return { id: x.id, name: x.name }; });
          }
          whoSel.innerHTML = '<option value="">' + (list.length ? 'Choose…' : 'Nobody on file — type a name below') + '</option>'
            + list.map(function (p) {
                whoMap[String(p.id)] = p;
                return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
              }).join('');
          if (payee && payee.id) { whoSel.value = String(payee.id); }
          else if (prefill && prefill.payee_pick) { whoSel.value = String(prefill.payee_pick); }
        }).catch(function () {
          whoSel.innerHTML = '<option value="">Could not load the list — type a name below</option>';
        });
      })();
      // Choosing from the list fills the name box, so one field is the source of truth.
      whoSel.addEventListener('change', function () {
        var p = whoMap[whoSel.value];
        if (p) { m.querySelector('#pi-name').value = p.name; }
      });

      var basis = m.querySelector('#pi-basis');
      function syncBasis() {
        var h = basis.value === 'hours';
        m.querySelector('#pi-hours-wrap').style.display = h ? 'block' : 'none';
        m.querySelector('#pi-amount-wrap').style.display = h ? 'none' : 'block';
      }
      // Coming back from the review step: put everything back as it was typed.
      if (prefill) {
        basis.value = prefill.basis || 'amount';
        if (prefill.amount) { m.querySelector('#pi-amount').value = prefill.amount; }
        if (prefill.hours) { m.querySelector('#pi-hours').value = prefill.hours; }
        if (prefill.rate) { m.querySelector('#pi-rate').value = prefill.rate; }
        if (prefill.period_start) { m.querySelector('#pi-from').value = prefill.period_start; }
        if (prefill.period_end) { m.querySelector('#pi-to').value = prefill.period_end; }
        if (prefill.details) { m.querySelector('#pi-details').value = prefill.details; }
        if (prefill.tax_applied) {
          taxOn.checked = true;
          m.querySelector('#pi-tax-wrap').style.display = 'flex';
          if (prefill.tax_rate != null) { m.querySelector('#pi-taxrate').value = prefill.tax_rate; }
          if (prefill.tax_label) { m.querySelector('#pi-taxlabel').value = prefill.tax_label; }
        }
        (prefill.lines || []).forEach(function (l) { addLineRow(l); });
        if (prefill.recurring) {
          m.querySelector('#pi-rec').checked = true;
          m.querySelector('#pi-freq').style.display = 'block';
          if (prefill.frequency) { m.querySelector('#pi-freq').value = prefill.frequency; }
        }
      }
      basis.addEventListener('change', syncBasis); syncBasis();

      // Line items. A repeater rather than one free-text box, because "MISC $12.50
      // parking" typed into a notes field is not something anybody can total later.
      var LINE_CATS = ['misc', 'expense', 'mileage', 'supplies', 'adjustment'];
      var linesWrap = m.querySelector('#pi-lines');
      function addLineRow(seed) {
        var row = Dom.el('div', { style: 'display:flex;gap:6px;margin-bottom:6px;' });
        row.innerHTML = '<select class="pl-cat" style="' + fld + 'flex:0 0 110px;background:#fff;">'
          +   LINE_CATS.map(function (c) { return '<option value="' + c + '">' + c.charAt(0).toUpperCase() + c.slice(1) + '</option>'; }).join('')
          + '</select>'
          + '<input class="pl-desc" type="text" placeholder="What it is for" style="' + fld + 'flex:1;min-width:0;">'
          + '<input class="pl-amt" type="number" step="0.01" placeholder="0.00" style="' + fld + 'flex:0 0 92px;text-align:right;">'
          + '<button type="button" class="pl-del" title="Remove" style="flex:0 0 auto;background:#fff;border:1px solid #E2E8F0;color:#C0453B;border-radius:8px;padding:0 10px;font-size:15px;cursor:pointer;">×</button>';
        linesWrap.appendChild(row);
        if (seed) {
          row.querySelector('.pl-cat').value = seed.category || 'misc';
          row.querySelector('.pl-desc').value = seed.description || '';
          row.querySelector('.pl-amt').value = seed.amount;
        }
        row.querySelector('.pl-del').addEventListener('click', function () { row.remove(); });
      }
      m.querySelector('#pi-addline').addEventListener('click', function () { addLineRow(); });

      function collectLines() {
        var out = [];
        linesWrap.querySelectorAll('.pl-amt').forEach(function (a, i) {
          var amt = parseFloat(a.value);
          var desc = (linesWrap.querySelectorAll('.pl-desc')[i].value || '').trim();
          // A line with no amount is an empty row somebody added and abandoned, not a
          // zero-value charge — skipped rather than rejected.
          if (! isFinite(amt) || amt === 0 || ! desc) { return; }
          out.push({
            category: linesWrap.querySelectorAll('.pl-cat')[i].value,
            description: desc,
            amount: amt,
          });
        });
        return out;
      }

      var taxOn = m.querySelector('#pi-tax');
      taxOn.addEventListener('change', function () {
        m.querySelector('#pi-tax-wrap').style.display = taxOn.checked ? 'flex' : 'none';
      });

      var rec = m.querySelector('#pi-rec');
      rec.addEventListener('change', function () { m.querySelector('#pi-freq').style.display = rec.checked ? 'block' : 'none'; });

      // A preview of the SERVER's arithmetic, not the input to it — hours and rate are
      // what get sent, and the server multiplies them again.
      function recalc() {
        var h = parseFloat(m.querySelector('#pi-hours').value) || 0;
        var r = parseFloat(m.querySelector('#pi-rate').value) || 0;
        m.querySelector('#pi-calc').textContent = (h && r) ? (h + ' h × ' + money(r) + ' = ' + money(h * r)) : '';
      }
      ['#pi-hours', '#pi-rate'].forEach(function (sel) { m.querySelector(sel).addEventListener('input', recalc); });

      var fetchBtn = m.querySelector('#pi-fetch');
      if (fetchBtn) {
        fetchBtn.addEventListener('click', function () {
          var f = m.querySelector('#pi-from').value, t = m.querySelector('#pi-to').value;
          if (!f || !t) { m.querySelector('#pi-err').textContent = 'Set the period first, then pull the hours.'; return; }
          fetchBtn.disabled = true; fetchBtn.textContent = 'Reading timesheet…';
          Api.get('/provider/payee-invoices/hours?user_id=' + payee.id + '&from=' + f + '&to=' + t).then(function (r) {
            m.querySelector('#pi-hours').value = r.hours || 0;
            recalc();
            fetchBtn.disabled = false; fetchBtn.textContent = 'Use hours from the timesheet';
            if (!r.hours) { m.querySelector('#pi-err').textContent = 'No completed shifts in that period.'; }
          }).catch(function () { fetchBtn.disabled = false; fetchBtn.textContent = 'Use hours from the timesheet'; });
        });
      }

      m.querySelector('#pi-save').addEventListener('click', function () {
        var err = m.querySelector('#pi-err');
        var body = {
          kind: kind,
          payee_name: (m.querySelector('#pi-name').value || '').trim(),
          // The chosen row wins over anything typed: a picked person is a person, a typed
          // name is a string.
          payee_pick: whoSel.value || null,
          payee_user_id: (kind === 'educator' && whoSel.value) ? parseInt(whoSel.value, 10) : ((payee && payee.id) || null),
          payee_family_id: (kind === 'parent' && whoSel.value) ? parseInt(whoSel.value, 10) : null,
          basis: basis.value,
          period_start: m.querySelector('#pi-from').value || null,
          period_end: m.querySelector('#pi-to').value || null,
          details: (m.querySelector('#pi-details').value || '').trim() || null,
          recurring: rec.checked,
          frequency: rec.checked ? m.querySelector('#pi-freq').value : null,
          lines: collectLines(),
          tax_applied: taxOn.checked,
          // Blank means "use the agency default" — the server fills it in, which is the
          // whole point of having a default.
          tax_rate: (taxOn.checked && m.querySelector('#pi-taxrate').value !== '')
            ? parseFloat(m.querySelector('#pi-taxrate').value) : null,
          tax_label: (taxOn.checked && (m.querySelector('#pi-taxlabel').value || '').trim())
            ? m.querySelector('#pi-taxlabel').value.trim() : null,
        };
        if (!body.payee_name) { err.textContent = 'Who is this for?'; return; }
        if (basis.value === 'hours') {
          body.hours = parseFloat(m.querySelector('#pi-hours').value) || 0;
          body.rate = parseFloat(m.querySelector('#pi-rate').value) || 0;
          if (!body.hours || !body.rate) { err.textContent = 'Hours and a rate are both needed.'; return; }
        } else {
          body.amount = parseFloat(m.querySelector('#pi-amount').value) || 0;
          if (!body.amount) { err.textContent = 'Enter an amount.'; return; }
        }
        // Nothing is written yet. An invoice is a financial record with somebody's name
        // on it, and the last thing between a typo and a wrong bill should be a person
        // reading it back — not a validator agreeing the shape is plausible.
        if (body.recurring) { scheduleStep(body); } else { confirmStep(body); }
      });

      /* A schedule is N decisions taken in advance, not one. This lists the months it
         would cover with the amount against each, editable, because the month that
         differs is exactly the one somebody wanted to change — and each month becomes its
         own invoice, so voiding March does not disturb April. */
      function scheduleStep(body) {
        var per = body.basis === 'hours' ? (body.hours * body.rate) : body.amount;
        var step = body.frequency === 'weekly' ? 7 : (body.frequency === 'biweekly' ? 14 : 0);
        var start = body.period_start ? new Date(body.period_start + 'T00:00:00') : new Date();
        var occurrences = body.frequency === 'monthly' ? 12 : (body.frequency === 'biweekly' ? 13 : 12);

        var rows = [];
        for (var i = 0; i < occurrences; i++) {
          var d = new Date(start.getTime());
          if (step) { d.setDate(d.getDate() + step * i); } else { d.setMonth(d.getMonth() + i); }
          rows.push({
            date: d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2),
            label: d.toLocaleDateString('en-CA', { month: 'long', year: 'numeric' })
                 + (step ? ' · ' + d.toLocaleDateString('en-CA', { day: 'numeric', month: 'short' }) : ''),
            amount: per,
            on: i < (body.frequency === 'monthly' ? 12 : occurrences),
          });
        }

        m.innerHTML = '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">Confirm each period</div>'
          + '<div style="font-size:12.5px;color:#64748B;margin:0 0 12px;">' + esc(body.payee_name)
          + ' · repeats ' + esc(body.frequency) + '. Change any amount that differs, or untick a period to skip it. '
          + 'Each one becomes its own invoice.</div>'
          + '<div style="max-height:44vh;overflow-y:auto;border:1px solid #E2E8F0;border-radius:11px;">'
          +   '<table style="width:100%;border-collapse:collapse;">'
          +   rows.map(function (r, i) {
                return '<tr style="border-bottom:1px solid #F1F5F9;">'
                  + '<td style="padding:8px 10px;width:28px;"><input type="checkbox" class="pi-on" data-i="' + i + '" checked style="width:16px;height:16px;"></td>'
                  + '<td style="padding:8px 4px;font-size:13.5px;color:#0F172A;">' + esc(r.label) + '</td>'
                  + '<td style="padding:8px 10px;text-align:right;"><input type="number" class="pi-amt" data-i="' + i + '" min="0" step="0.01" value="' + r.amount.toFixed(2) + '" style="width:110px;box-sizing:border-box;padding:6px 8px;border:1px solid #D6DEE7;border-radius:7px;font-size:13px;text-align:right;"></td>'
                  + '</tr>';
              }).join('')
          +   '</table></div>'
          + '<div style="display:flex;justify-content:space-between;align-items:baseline;margin:10px 2px 0;">'
          +   '<span style="font-size:13px;font-weight:700;color:#475569;">Total across the schedule</span>'
          +   '<span id="pi-stotal" style="font-size:20px;font-weight:800;color:#0F172A;"></span></div>'
          + '<div id="pi-serr" style="color:#DC2626;font-size:12.5px;min-height:17px;margin-top:6px;"></div>'
          + '<div style="display:flex;justify-content:space-between;gap:8px;">'
          +   '<button id="pi-sback" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">← Back to edit</button>'
          +   '<button id="pi-screate" style="background:#16A34A;color:#fff;border:0;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Create these invoices</button>'
          + '</div>';

        function chosen() {
          var out = [];
          m.querySelectorAll('.pi-on').forEach(function (cb) {
            if (! cb.checked) return;
            var i = cb.getAttribute('data-i');
            var amt = parseFloat(m.querySelector('.pi-amt[data-i="' + i + '"]').value) || 0;
            if (amt > 0) { out.push({ date: rows[i].date, label: rows[i].label, amount: amt }); }
          });
          return out;
        }
        function retotal() {
          var list = chosen();
          var sum = list.reduce(function (a, b) { return a + b.amount; }, 0);
          m.querySelector('#pi-stotal').textContent = money(sum) + '  (' + list.length + ')';
        }
        m.querySelectorAll('.pi-on, .pi-amt').forEach(function (el) {
          el.addEventListener('change', retotal); el.addEventListener('input', retotal);
        });
        retotal();

        m.querySelector('#pi-sback').addEventListener('click', function () {
          ov.remove();
          openGenerate(kind, payee, onDone, body);
        });

        m.querySelector('#pi-screate').addEventListener('click', function () {
          var list = chosen();
          var err = m.querySelector('#pi-serr');
          if (! list.length) { err.textContent = 'Nothing ticked — there is nothing to create.'; return; }
          var b = m.querySelector('#pi-screate');
          b.disabled = true;
          var made = 0, failed = 0;

          // One at a time, and each one flat: the per-period amount was agreed on the
          // screen above, so re-deriving it from hours here could disagree with what was
          // confirmed. Recurrence is NOT set on the rows — the whole schedule has already
          // been written out, and leaving it on would generate the same months again.
          function next(i) {
            if (i >= list.length) {
              ov.remove();
              toast('🧾', 'Schedule created', made + ' invoice' + (made === 1 ? '' : 's')
                + (failed ? ', ' + failed + ' failed' : '') + ' for ' + body.payee_name, failed ? '#F59E0B' : '#16A34A');
              if (onDone) onDone();
              return;
            }
            b.textContent = 'Creating ' + (i + 1) + ' of ' + list.length + '…';
            var one = {
              kind: body.kind, payee_name: body.payee_name,
              payee_user_id: body.payee_user_id || null, payee_family_id: body.payee_family_id || null,
              basis: 'amount', amount: list[i].amount,
              period_start: list[i].date, period_end: list[i].date,
              details: (body.details ? body.details + ' — ' : '') + list[i].label,
              recurring: false,
            };
            Api.post('/provider/payee-invoices', one)
              .then(function () { made++; })
              .catch(function () { failed++; })
              .then(function () { next(i + 1); });
          }
          next(0);
        });
      }

      /* The review step. Deliberately a full redraw of the same window rather than a
         second dialog on top: stacking one confirmation over another is how people click
         through both without reading either. Back returns to the form with everything
         still in it. */
      function confirmStep(body) {
        var line = function (label, value) {
          return '<tr><td style="padding:6px 14px 6px 0;font-size:12.5px;color:#64748B;white-space:nowrap;vertical-align:top;">'
            + esc(label) + '</td><td style="padding:6px 0;font-size:14px;color:#0F172A;font-weight:600;">' + esc(value) + '</td></tr>';
        };
        var core = body.basis === 'hours' ? (body.hours * body.rate) : body.amount;
        var lineSum = (body.lines || []).reduce(function (a, l) { return a + l.amount; }, 0);
        var subtotal = core + lineSum;
        // Only previewable when a rate was actually typed. With the agency default the
        // server decides, and inventing a number here that it might not agree with is
        // worse than saying so.
        var knownRate = body.tax_applied && body.tax_rate != null;
        var taxPreview = knownRate ? Math.round(subtotal * (body.tax_rate / 100) * 100) / 100 : null;
        var total = subtotal + (taxPreview || 0);
        var kindWord = body.kind === 'educator' ? 'Educator' : (body.kind === 'parent' ? 'Family' : 'Contractor');

        m.innerHTML = '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">Check this before it is created</div>'
          + '<div style="font-size:12.5px;color:#64748B;margin:0 0 14px;">Nothing has been written yet. This creates one invoice.</div>'
          + '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:11px;padding:14px 16px;margin-bottom:8px;">'
          +   '<table style="width:100%;border-collapse:collapse;">'
          +     line(kindWord, body.payee_name)
          +     (body.basis === 'hours'
                  ? line('Hours', body.hours + ' h') + line('Rate', money(body.rate) + ' / hour')
                  : line('Amount', money(body.amount)))
          +     (body.period_start || body.period_end
                  ? line('Period', (body.period_start || '…') + ' → ' + (body.period_end || '…')) : '')
          +     (body.details ? line('Details', body.details) : '')
          +     line('Repeats', body.recurring ? ('yes — ' + body.frequency) : 'no, one-off')
          +   '</table>'
          +   ((body.lines && body.lines.length)
                ? '<div style="border-top:1px solid #E2E8F0;margin-top:10px;padding-top:8px;">'
                  + body.lines.map(function (l) {
                      return '<div style="display:flex;justify-content:space-between;font-size:13px;color:#334155;padding:2px 0;">'
                        + '<span>' + esc(l.category) + ' — ' + esc(l.description) + '</span>'
                        + '<span style="font-weight:700;">' + esc(money(l.amount)) + '</span></div>';
                    }).join('')
                  + '</div>' : '')
          +   '<div style="border-top:1px solid #E2E8F0;margin-top:10px;padding-top:8px;display:flex;justify-content:space-between;font-size:13px;color:#475569;">'
          +     '<span>Subtotal</span><span style="font-weight:700;">' + esc(money(subtotal)) + '</span></div>'
          +   (body.tax_applied
                ? '<div style="display:flex;justify-content:space-between;font-size:13px;color:#475569;padding-top:3px;">'
                  + '<span>' + esc(body.tax_label || 'Tax') + (knownRate ? ' ' + body.tax_rate + '%' : ' (agency default)') + '</span>'
                  + '<span style="font-weight:700;">' + (knownRate ? esc(money(taxPreview)) : 'calculated on save') + '</span></div>'
                : '<div style="font-size:12.5px;color:#64748B;padding-top:3px;">No tax applied.</div>')
          +   '<div style="border-top:1px solid #E2E8F0;margin-top:10px;padding-top:10px;display:flex;align-items:baseline;justify-content:space-between;">'
          +     '<span style="font-size:13px;font-weight:700;color:#475569;">Total</span>'
          +     '<span style="font-size:22px;font-weight:800;color:#0F172A;">'
          +       (body.tax_applied && ! knownRate ? esc(money(subtotal)) + ' + tax' : esc(money(total))) + '</span>'
          +   '</div>'
          + '</div>'
          + (body.basis === 'hours'
              ? '<div style="font-size:12px;color:#64748B;margin:0 0 10px;">The total is recalculated on the server from the hours and rate above, so what is stored can always be checked back against the timesheet.</div>'
              : '')
          + (body.recurring
              ? '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:9px;padding:10px 12px;font-size:12.5px;margin:0 0 10px;">This repeats ' + esc(body.frequency) + ' until you void it.</div>'
              : '')
          + '<div id="pi-cerr" style="color:#DC2626;font-size:12.5px;min-height:17px;"></div>'
          + '<div style="display:flex;justify-content:space-between;gap:8px;">'
          +   '<button id="pi-back" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">← Back to edit</button>'
          +   '<button id="pi-confirm" style="background:#16A34A;color:#fff;border:0;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Create this invoice</button>'
          + '</div>';

        m.querySelector('#pi-back').addEventListener('click', function () {
          ov.remove();
          openGenerate(kind, payee, onDone, body);
        });

        m.querySelector('#pi-confirm').addEventListener('click', function () {
          var b = m.querySelector('#pi-confirm');
          b.disabled = true; b.textContent = 'Creating…';
          Api.post('/provider/payee-invoices', body).then(function (r) {
            ov.remove();
            toast('🧾', 'Invoice created', body.payee_name + ' · ' + money(r.amount), '#16A34A');
            if (onDone) onDone();
          }).catch(function (e) {
            b.disabled = false; b.textContent = 'Create this invoice';
            m.querySelector('#pi-cerr').textContent = (e && e.message) || 'Could not create.';
          });
        });
      }
    }

    /* The ledger under each section. Status is the first question anybody asks of an
       invoice list, so it is the filter rather than a column to squint at. */
    function invoiceTable(kind) {
      var box = Dom.el('div', { style: 'margin-top:16px;background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
      var head = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid #F3F4F6;' });
      head.appendChild(Dom.el('div', { style: 'font-size:11.5px;font-weight:800;letter-spacing:.6px;color:#64748B;text-transform:uppercase;' }, 'Invoices'));
      var filter = Dom.el('select', { style: 'margin-left:auto;padding:6px 9px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;background:#fff;' });
      ['all', 'upcoming', 'issued', 'paid', 'void'].forEach(function (v) {
        filter.appendChild(Dom.el('option', { value: v }, v === 'all' ? 'All statuses' : v.charAt(0).toUpperCase() + v.slice(1)));
      });
      head.appendChild(filter);
      box.appendChild(head);
      var rows = Dom.el('div', {});
      box.appendChild(rows);

      function load() {
        Dom.clear(rows);
        rows.appendChild(Dom.el('div', { style: 'padding:18px;color:#64748B;font-size:13px;' }, 'Loading…'));
        Api.get('/provider/payee-invoices?kind=' + kind + '&status=' + filter.value).then(function (d) {
          Dom.clear(rows);
          var list = d.invoices || [];
          var t = d.totals || {};
          var summary = Object.keys(t).map(function (k) { return k + ': ' + t[k].count + ' (' + money(t[k].total) + ')'; }).join('  ·  ');
          if (summary) {
            rows.appendChild(Dom.el('div', { style: 'padding:9px 18px;font-size:12.5px;color:#475569;background:#F8FAFC;border-bottom:1px solid #F3F4F6;' }, summary));
          }
          if (!list.length) {
            rows.appendChild(Dom.el('div', { style: 'padding:22px 18px;color:#64748B;font-size:13px;' }, 'No invoices yet.'));
            return;
          }
          // A table, not a stack of divs: kt-row-actions.js collapses the last cell of a
          // table into the standard kebab, so this is what earns one.
          var th = 'text-align:left;padding:9px 12px;font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
          var td = 'padding:10px 12px;font-size:13.5px;color:#0F172A;border-top:1px solid #F4F7FA;vertical-align:middle;';
          var tbl = Dom.el('div', { style: 'overflow-x:auto;' });

          tbl.innerHTML = '<table style="width:100%;border-collapse:collapse;">'
            + '<thead style="background:#FAFBFC;"><tr>'
            +   '<th style="' + th + '">Invoice #</th>'
            +   '<th style="' + th + '">Issued</th>'
            +   '<th style="' + th + '">Name</th>'
            +   '<th style="' + th + '">Rec ID</th>'
            +   '<th style="' + th + '">Detail</th>'
            +   '<th style="' + th + 'text-align:right;">Amount</th>'
            +   '<th style="' + th + '">Status</th>'
            +   '<th style="' + th + 'width:56px;"></th>'
            + '</tr></thead><tbody>'
            + list.map(function (iv) {
                var tone = STATUS_TONE[iv.status] || STATUS_TONE.void;
                var meta = [
                  (iv.basis === 'hours' && iv.hours) ? (iv.hours + 'h \u00d7 ' + money(iv.rate)) : '',
                  iv.period_start ? String(iv.period_start).slice(0, 10) : '',
                  iv.recurring ? ('repeats ' + iv.frequency) : '',
                ].filter(Boolean).join(' \u00b7 ') || '\u2014';
                // The record id is the payee's own: a family number for a parent bill, a
                // user number otherwise. "Which family is this?" is answered by the id
                // somebody can search on, not by a name two families may share.
                var recId = iv.payee_family_id ? ('F-' + iv.payee_family_id)
                  : (iv.payee_user_id ? ('U-' + iv.payee_user_id) : '\u2014');
                var issued = String(iv.created_at || iv.period_start || '').slice(0, 10) || '\u2014';
                var live = iv.status !== 'void';
                return '<tr>'
                  + '<td style="' + td + 'font-weight:700;white-space:nowrap;">' + esc(iv.reference || ('#' + iv.id)) + '</td>'
                  + '<td style="' + td + 'white-space:nowrap;">' + esc(issued) + '</td>'
                  + '<td style="' + td + 'font-weight:700;">' + esc(iv.payee_name) + '</td>'
                  + '<td style="' + td + 'color:#64748B;font-size:12.5px;white-space:nowrap;">' + esc(recId) + '</td>'
                  + '<td style="' + td + 'color:#64748B;font-size:12.5px;">' + esc(meta) + '</td>'
                  + '<td style="' + td + 'text-align:right;font-weight:800;white-space:nowrap;">' + esc(money(iv.amount)) + '</td>'
                  + '<td style="' + td + '"><span style="background:' + tone.bg + ';color:' + tone.fg + ';padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:800;">' + esc(iv.status) + '</span></td>'
                  + '<td style="' + td + 'text-align:right;white-space:nowrap;">'
                  +   '<button class="iv-act" data-act="view" data-id="' + iv.id + '" type="button" title="View invoice">View</button>'
                  +   (live ? '<button class="iv-act" data-act="send" data-id="' + iv.id + '" type="button" title="Email this invoice">Resend</button>' : '')
                  +   (live && iv.status !== 'issued' ? '<button class="iv-act" data-act="issued" data-id="' + iv.id + '" type="button" title="Mark issued">Mark issued</button>' : '')
                  +   (live && iv.status !== 'paid' ? '<button class="iv-act" data-act="paid" data-id="' + iv.id + '" type="button" title="Mark paid">Mark paid</button>' : '')
                  +   (live ? '<button class="iv-act" data-act="void" data-id="' + iv.id + '" type="button" title="Void this invoice">Void</button>' : '')
                  + '</td></tr>';
              }).join('')
            + '</tbody></table>';
          rows.appendChild(tbl);

          var byId = {};
          list.forEach(function (iv) { byId[String(iv.id)] = iv; });
          tbl.querySelectorAll('.iv-act').forEach(function (b) {
            b.addEventListener('click', function () {
              var id = b.getAttribute('data-id'), act = b.getAttribute('data-act');
              if (act === 'view') { return viewInvoice(byId[id]); }
              if (act === 'send') { return resendInvoice(byId[id], load); }
              b.disabled = true;
              Api.post('/provider/payee-invoices/' + id + '/status', { status: act })
                .then(load).catch(function () { b.disabled = false; });
            });
          });
        }).catch(function (e) {
          Dom.clear(rows);
          rows.appendChild(Dom.el('div', { style: 'padding:18px;color:#DC2626;font-size:13px;' }, 'Could not load: ' + (e.message || 'error')));
        });
      }
      filter.addEventListener('change', load);
      load();
      box.kt_reload = load;
      return box;
    }

    /* Shows the actual PDF, not a summary the screen assembles. Those were two
       different artefacts, so "view the invoice" and "what did we send them" could
       disagree without anyone noticing.

       Fetched as a blob rather than pointed at with an iframe src: the endpoint needs a
       bearer token and an iframe cannot send one. The object URL is revoked when the
       window closes, or the browser holds the whole file until the tab is. */
    function viewInvoice(iv) {
      if (! iv) { return; }
      var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;' });
      var m = Dom.el('div', { style: 'background:#fff;border-radius:14px;max-width:820px;width:100%;height:86vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 18px 48px rgba(0,0,0,.32);' });
      ov.appendChild(m);
      m.innerHTML = '<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #E2E8F0;flex:0 0 auto;">'
        +   '<div style="font-size:15px;font-weight:800;color:#0D1B2A;">Invoice ' + esc(iv.reference || ('#' + iv.id)) + '</div>'
        +   '<div style="font-size:12.5px;color:#64748B;">' + esc(iv.payee_name) + ' · ' + esc(money(iv.amount)) + '</div>'
        +   '<button id="iv-dl" style="margin-left:auto;background:#fff;border:1px solid #D1D5DB;color:#374151;padding:7px 13px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;">Open in a new tab</button>'
        +   '<button id="iv-close" style="background:#1F6080;color:#fff;border:0;padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;">Close</button>'
        + '</div>'
        + '<div id="iv-doc" style="flex:1 1 auto;min-height:0;background:#F1F5F9;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:13px;">Loading the invoice…</div>';
      document.body.appendChild(ov);

      var objUrl = null;
      function shut() {
        if (objUrl) { try { URL.revokeObjectURL(objUrl); } catch (e) {} }
        ov.remove();
      }
      ov.addEventListener('click', function (e) { if (e.target === ov) shut(); });
      m.querySelector('#iv-close').addEventListener('click', shut);

      var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      fetch(base + '/provider/payee-invoices/' + iv.id + '/pdf', { headers: { Authorization: 'Bearer ' + tok } })
        .then(function (r) { if (! r.ok) { throw new Error('HTTP ' + r.status); } return r.blob(); })
        .then(function (blob) {
          objUrl = URL.createObjectURL(blob);
          var host = m.querySelector('#iv-doc');
          host.innerHTML = '';
          host.style.display = 'block';
          var frame = Dom.el('iframe', { src: objUrl, style: 'width:100%;height:100%;border:0;display:block;' });
          host.appendChild(frame);
          m.querySelector('#iv-dl').addEventListener('click', function () { window.open(objUrl, '_blank'); });
        })
        .catch(function (e) {
          m.querySelector('#iv-doc').textContent = 'Could not load the invoice: ' + ((e && e.message) || 'error');
        });
    }

    /* Sending tells somebody they owe money, so it asks first and shows where it is going.
       The address can be overridden for a contractor with no account on the system. */
    function resendInvoice(iv, onDone) {
      if (! iv) { return; }
      var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;' });
      var m = Dom.el('div', { style: 'background:#fff;border-radius:14px;max-width:400px;width:100%;padding:20px 22px;box-shadow:0 18px 48px rgba(0,0,0,.28);' });
      ov.appendChild(m);
      m.innerHTML = '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">Email this invoice</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin:0 0 12px;">' + esc(iv.payee_name) + ' \u00b7 ' + esc(money(iv.amount))
        + '. Leave blank to use the address on file.</div>'
        + '<input id="iv-email" type="email" placeholder="Send to a different address (optional)" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;">'
        + (iv.status === 'upcoming' ? '<div style="font-size:12.5px;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:9px;padding:9px 11px;margin-top:10px;">Sending this marks it <strong>issued</strong>.</div>' : '')
        + '<div id="iv-serr" style="color:#DC2626;font-size:12.5px;min-height:17px;margin-top:6px;"></div>'
        + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
        +   '<button id="iv-cancel" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>'
        +   '<button id="iv-send" style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Send</button></div>';
      document.body.appendChild(ov);
      ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
      m.querySelector('#iv-cancel').addEventListener('click', function () { ov.remove(); });
      m.querySelector('#iv-send').addEventListener('click', function () {
        var b = m.querySelector('#iv-send');
        b.disabled = true; b.textContent = 'Sending\u2026';
        var to = (m.querySelector('#iv-email').value || '').trim();
        Api.post('/provider/payee-invoices/' + iv.id + '/send', to ? { email: to } : {}).then(function (r) {
          ov.remove();
          toast('\u2709\ufe0f', 'Invoice sent', 'To ' + (r.sent_to || 'the address on file'), '#16A34A');
          if (onDone) { onDone(); }
        }).catch(function (e) {
          b.disabled = false; b.textContent = 'Send';
          m.querySelector('#iv-serr').textContent = (e && e.message) || 'Could not send.';
        });
      });
    }

    function generateBar(kind, payee) {
      var bar = Dom.el('div', { style: 'display:flex;justify-content:flex-end;margin-top:12px;' });
      var b = Dom.el('button', {
        style: 'background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;',
      }, '🧾 Generate an invoice');
      bar.appendChild(b);
      return { bar: bar, button: b };
    }

    // ── Sections ────────────────────────────────────────────────────────
    // Parents is billing OUT; educators and contractors are money going the other way.
    // Keeping them in one undivided list is how somebody invoices a contractor by mistake.
    var SECTIONS = [
      { key: 'parents', label: '👪 Parents', hint: 'Raise fee invoices for families, by centre.' },
      { key: 'educators', label: '🧑‍🏫 Educators', hint: 'Paid from recorded hours — run in Payroll.' },
      { key: 'contractors', label: '🧾 Contractors & suppliers', hint: 'Bills you receive, handled in Expenses.' },
    ];
    var active = 'parents';

    var tabs = Dom.el('div', { class: 'kt-subtabs', style: 'display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;' });
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
      if (active === 'parents') {
        pane.appendChild(centresWrap);
        var pTable = invoiceTable('parent');
        var pGen = generateBar('parent', null);
        pGen.button.addEventListener('click', function () {
          openGenerate('parent', null, function () { pTable.kt_reload(); });
        });
        pane.appendChild(pGen.bar);
        pane.appendChild(pTable);
        return;
      }

      if (active === 'educators') {
        var wrap = Dom.el('div', {});
        wrap.appendChild(note('Educators are paid from their recorded hours',
          'There is no invoice run for educators — their pay comes from clock-ins and manual entries on the timesheet, '
          + 'which Payroll turns into a payslip. Running it here would mean keying the same hours twice.',
          'Open Payroll', '#payroll'));
        var eTable = invoiceTable('educator');
        var eGen = generateBar('educator', null);
        eGen.button.addEventListener('click', function () {
          openGenerate('educator', null, function () { eTable.kt_reload(); });
        });
        var list = Dom.el('div', { style: 'margin-top:14px;background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
        list.appendChild(Dom.el('div', { style: 'padding:12px 18px;font-size:11.5px;font-weight:800;letter-spacing:.6px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #F3F4F6;' }, 'Who would be paid'));
        wrap.appendChild(list);
        wrap.appendChild(eGen.bar);
        wrap.appendChild(eTable);
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
            // Per person, because "invoice this educator" is the actual job — a single
            // button at the top would mean picking the name twice.
            var gb = Dom.el('button', { type: 'button', title: 'Generate an invoice',
              style: 'background:#fff;border:1px solid #CFE3EB;color:#1F6080;border-radius:8px;padding:5px 10px;font-size:12px;font-weight:700;cursor:pointer;' }, 'Invoice');
            gb.addEventListener('click', function () {
              openGenerate('educator', { id: p.id, name: p.name }, function () { eTable.kt_reload(); });
            });
            row.appendChild(gb);
            list.appendChild(row);
          });
        }).catch(function () {
          list.appendChild(Dom.el('div', { style: 'padding:18px;color:#DC2626;font-size:13px;' }, 'Could not load the team list.'));
        });
        return;
      }

      // Contractors: the accounts-payable module exists but has nothing in it. Say that
      // plainly rather than showing an empty table that looks broken.
      pane.appendChild(note('Contractors and suppliers',
        'Bills you receive — cleaners, food, maintenance, contracted staff — can be recorded here, or managed in full '
        + 'in Expenses where a supplier is raised against a purchase order and paid.',
        'Open Expenses', '#expenses'));
      var cTable = invoiceTable('contractor');
      var cGen = generateBar('contractor', null);
      cGen.button.addEventListener('click', function () {
        openGenerate('contractor', null, function () { cTable.kt_reload(); });
      });
      pane.appendChild(cGen.bar);
      pane.appendChild(cTable);
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

  // This file never had an esc(); the generate dialog interpolates a payee name into
  // markup, so it needs one. A name is user-supplied text going into innerHTML.
  function esc(v) {
    return v == null ? '' : String(v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
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
