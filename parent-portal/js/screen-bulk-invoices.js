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
        + '<input id="pi-name" type="text" value="' + esc(payee && payee.name ? payee.name : '') + '" placeholder="Name" style="' + fld + 'margin-bottom:12px;">'
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
        if (prefill.recurring) {
          m.querySelector('#pi-rec').checked = true;
          m.querySelector('#pi-freq').style.display = 'block';
          if (prefill.frequency) { m.querySelector('#pi-freq').value = prefill.frequency; }
        }
      }
      basis.addEventListener('change', syncBasis); syncBasis();

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
          payee_user_id: (payee && payee.id) || null,
          basis: basis.value,
          period_start: m.querySelector('#pi-from').value || null,
          period_end: m.querySelector('#pi-to').value || null,
          details: (m.querySelector('#pi-details').value || '').trim() || null,
          recurring: rec.checked,
          frequency: rec.checked ? m.querySelector('#pi-freq').value : null,
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
        confirmStep(body);
      });

      /* The review step. Deliberately a full redraw of the same window rather than a
         second dialog on top: stacking one confirmation over another is how people click
         through both without reading either. Back returns to the form with everything
         still in it. */
      function confirmStep(body) {
        var line = function (label, value) {
          return '<tr><td style="padding:6px 14px 6px 0;font-size:12.5px;color:#64748B;white-space:nowrap;vertical-align:top;">'
            + esc(label) + '</td><td style="padding:6px 0;font-size:14px;color:#0F172A;font-weight:600;">' + esc(value) + '</td></tr>';
        };
        var total = body.basis === 'hours' ? (body.hours * body.rate) : body.amount;
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
          +   '<div style="border-top:1px solid #E2E8F0;margin-top:10px;padding-top:10px;display:flex;align-items:baseline;justify-content:space-between;">'
          +     '<span style="font-size:13px;font-weight:700;color:#475569;">Total</span>'
          +     '<span style="font-size:22px;font-weight:800;color:#0F172A;">' + esc(money(total)) + '</span>'
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
          list.forEach(function (iv) {
            var tone = STATUS_TONE[iv.status] || STATUS_TONE.void;
            var r = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #F7F9FB;' });
            var left = Dom.el('div', { style: 'flex:1;min-width:0;' });
            left.appendChild(Dom.el('div', { style: 'font-size:14px;font-weight:700;color:#0F172A;' }, iv.payee_name));
            var meta = (iv.reference || '') + (iv.basis === 'hours' && iv.hours ? ' · ' + iv.hours + 'h × ' + money(iv.rate) : '')
              + (iv.period_start ? ' · ' + String(iv.period_start).slice(0, 10) + (iv.period_end ? ' → ' + String(iv.period_end).slice(0, 10) : '') : '')
              + (iv.recurring ? ' · repeats ' + iv.frequency : '');
            left.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;' }, meta));
            r.appendChild(left);
            r.appendChild(Dom.el('div', { style: 'font-size:15px;font-weight:800;color:#0F172A;white-space:nowrap;' }, money(iv.amount)));
            r.appendChild(Dom.el('span', { style: 'background:' + tone.bg + ';color:' + tone.fg + ';padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap;' }, iv.status));
            // Plain buttons: kt-row-actions.js collapses a row's actions itself.
            var acts = Dom.el('div', { style: 'display:flex;gap:4px;white-space:nowrap;' });
            ['issued', 'paid', 'void'].forEach(function (st) {
              if (iv.status === st) return;
              var b = Dom.el('button', { type: 'button', title: 'Mark ' + st,
                style: 'background:#fff;border:1px solid #E2E8F0;border-radius:7px;padding:5px 9px;font-size:12px;font-weight:700;color:#475569;cursor:pointer;' },
                'Mark ' + st);
              b.addEventListener('click', function () {
                b.disabled = true;
                Api.post('/provider/payee-invoices/' + iv.id + '/status', { status: st }).then(load)
                  .catch(function () { b.disabled = false; });
              });
              acts.appendChild(b);
            });
            r.appendChild(acts);
            rows.appendChild(r);
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
