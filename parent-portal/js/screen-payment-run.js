/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Finance → Payment run  (agency admin)

   Charge every auto-pay family's open invoices on their saved card in one go: preview who
   will be charged and how much, untick anyone to hold back, run it, and see each run's
   results (manual runs and the 03:00 nightly). Families without a card on file are listed
   separately so nobody is assumed paid. Backend: PaymentRunController (/admin/payment-runs*).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(v) { var n = Number(v || 0); return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function day(d) { if (!d) { return '—'; } var p = String(d).slice(0, 10).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2], 12)).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); }
  // Agency timezone (kt-tz.js); run timestamps are UTC from the server.
  function when(ts) { return KT.fmtDateTime ? KT.fmtDateTime(ts) : String(ts || '').slice(0, 16); }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  var RES = { charged: ['Charged', '#DCFCE7', '#166534'], failed: ['Failed', '#FEE2E2', '#991B1B'], skipped: ['Not charged', '#F1F5F9', '#475569'] };
  var ST = { completed: ['Completed', '#DCFCE7', '#166534'], partial: ['Some failed', '#FEF3C7', '#92400E'], failed: ['Failed', '#FEE2E2', '#991B1B'], no_provider: ['No processor', '#F1F5F9', '#475569'] };
  function pill(m) { return '<span class="pr-pill" style="background:' + m[1] + ';color:' + m[2] + '">' + m[0] + '</span>'; }

  var CSS = '<style id="pr-css">'
    + '.pr-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;margin-bottom:12px}.pr-card h3{margin:0 0 8px;font-size:15px;color:#0F172A}'
    + '.pr-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700;white-space:nowrap}'
    + '.pr-note{border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.5;margin-bottom:12px}'
    + '.pr-t{width:100%;border-collapse:collapse;font-size:13px}.pr-t th{text-align:left;font-size:11.5px;color:#64748B;font-weight:700;padding:6px 8px;border-bottom:1px solid #E2E8F0}'
    + '.pr-t td{padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:middle}.pr-t td.n,.pr-t th.n{text-align:right;white-space:nowrap}'
    + '.pr-go{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}.pr-go strong{font-size:15px}'
    + '.pr-row{cursor:pointer}.pr-row:hover td{background:#F8FAFC}'
    + '@media (max-width:700px){.pr-t .hm{display:none}}'
    + '</style>';

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = CSS + '<div style="padding:14px 24px;max-width:1100px;"><div class="kt-page-hero"><h2>💳 Payment run</h2>'
      + '<p>Charge every auto-pay family\'s open invoices on their saved card in one go. Check the list, untick anyone to hold back, then run it. Auto-pay also runs by itself every night at 3:00.</p></div>'
      + '<div id="pr-prev">Loading…</div><div id="pr-hist"></div></div>';
    await Promise.all([preview(main), history(main)]);
  }

  async function preview(main) {
    var box = main.querySelector('#pr-prev'), d;
    try { d = await KT.Api.get('/admin/payment-runs/preview'); }
    catch (e) { box.innerHTML = '<div class="pr-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    var cs = d.candidates || [], nc = d.not_covered || [];
    var html = d.provider ? '' : '<div class="pr-note" style="background:#FEF3C7;border:1px solid #FDE68A;color:#78350F">⚠️ ' + esc(d.provider_message) + '</div>';
    html += '<div class="pr-card"><h3>Ready to charge (' + cs.length + ')</h3>';
    if (!cs.length) {
      html += '<div style="color:#64748B;font-size:13.5px;">No auto-pay family has an open balance right now.' + (nc.length ? ' The families below owe money but have no card on file for auto-pay.' : '') + '</div>';
    } else {
      html += '<div style="overflow-x:auto"><table class="pr-t" data-kt-no-kebab data-kt-no-controls data-kt-no-filter><thead><tr><th style="width:28px"><input type="checkbox" data-all checked aria-label="Select all"></th><th>Family</th><th>Invoice</th><th class="hm">Due</th><th class="hm">Card</th><th class="n">Amount</th></tr></thead><tbody>'
        + cs.map(function (c) {
          return '<tr><td><input type="checkbox" data-inv="' + c.invoice_id + '" data-amt="' + c.amount + '" checked aria-label="Charge ' + esc(c.family) + '"></td><td><strong>' + esc(c.family) + '</strong><div style="font-size:11.5px;color:#64748B">' + esc(c.centre || '') + '</div></td>'
            + '<td>' + esc(c.invoice_number || ('#' + c.invoice_id)) + (c.status === 'overdue' ? ' ' + pill(['Overdue', '#FEE2E2', '#991B1B']) : '') + '</td><td class="hm">' + day(c.due_date) + '</td><td class="hm">' + esc(c.card || '—') + '</td><td class="n">' + money(c.amount) + '</td></tr>';
        }).join('') + '</tbody></table></div>'
        + '<div class="pr-go"><span>Selected: <strong data-sum></strong></span><span style="flex:1"></span>'
        + '<button class="kt-btn kt-btn-primary" data-run' + (d.provider ? '' : ' disabled title="Connect a card processor first"') + '>Charge selected</button></div>';
    }
    html += '</div>';
    if (nc.length) {
      html += '<div class="pr-card"><h3>Not covered by auto-pay (' + nc.length + ')</h3><div style="font-size:12.5px;color:#64748B;margin-bottom:6px">These families owe money but have no auto-pay card on file. Send a reminder or record their payment on the invoice.</div>'
        + '<div style="overflow-x:auto"><table class="pr-t" data-kt-no-kebab data-kt-no-controls data-kt-no-filter><thead><tr><th>Family</th><th class="n">Invoices</th><th class="hm">Oldest due</th><th class="n">Owing</th></tr></thead><tbody>'
        + nc.map(function (f) { return '<tr><td>' + esc(f.family_name) + '</td><td class="n">' + f.invoices + '</td><td class="hm">' + day(f.oldest_due) + '</td><td class="n">' + money(f.balance) + '</td></tr>'; }).join('')
        + '</tbody></table></div></div>';
    }
    box.innerHTML = html;

    var sumEl = box.querySelector('[data-sum]'), runBtn = box.querySelector('[data-run]');
    function picked() { return Array.prototype.filter.call(box.querySelectorAll('[data-inv]'), function (i) { return i.checked; }); }
    function sum() {
      if (!sumEl) { return; }
      var p = picked(), t = p.reduce(function (a, i) { return a + Number(i.getAttribute('data-amt')); }, 0);
      sumEl.textContent = p.length + ' invoice' + (p.length === 1 ? '' : 's') + ' · ' + money(t);
      if (runBtn && d.provider) { runBtn.disabled = !p.length; }
    }
    box.querySelectorAll('[data-inv]').forEach(function (i) { i.addEventListener('change', sum); });
    var all = box.querySelector('[data-all]');
    if (all) { all.addEventListener('change', function () { box.querySelectorAll('[data-inv]').forEach(function (i) { i.checked = all.checked; }); sum(); }); }
    sum();
    if (runBtn) {
      runBtn.addEventListener('click', async function () {
        var p = picked();
        if (!p.length) { return; }
        var t = p.reduce(function (a, i) { return a + Number(i.getAttribute('data-amt')); }, 0);
        var ok = KT.confirm ? await KT.confirm({ title: 'Charge ' + p.length + ' invoice' + (p.length === 1 ? '' : 's') + '?', description: money(t) + ' will be charged to the families\' saved cards now. This cannot be undone from here (refunds go through Refunds).', okLabel: 'Charge ' + money(t) }) : true;
        if (!ok) { return; }
        runBtn.disabled = true; runBtn.textContent = 'Charging…';
        try {
          var r = await KT.Api.post('/admin/payment-runs', { invoice_ids: p.map(function (i) { return +i.getAttribute('data-inv'); }) });
          var s = r.summary || {};
          toast(s.succeeded + ' charged (' + money(s.charged) + ')' + (s.failed ? ', ' + s.failed + ' failed' : ''), s.failed ? 'warning' : 'success');
          await Promise.all([preview(main), history(main)]);
          if (r.run_id) { showRun(r.run_id); }
        } catch (e) { toast(e.message || 'The run failed', 'error'); runBtn.disabled = false; runBtn.textContent = 'Charge selected'; }
      });
    }
  }

  async function history(main) {
    var box = main.querySelector('#pr-hist'), d;
    try { d = await KT.Api.get('/admin/payment-runs'); } catch (e) { box.innerHTML = ''; return; }
    var rs = d.runs || [];
    box.innerHTML = '<div class="pr-card"><h3>Past runs</h3>' + (rs.length
      ? '<div style="overflow-x:auto"><table class="pr-t" data-kt-no-kebab data-kt-no-controls data-kt-no-filter><thead><tr><th>When</th><th>Type</th><th>Result</th><th class="n hm">Charged</th><th class="n hm">Failed</th><th class="n">Collected</th></tr></thead><tbody>'
        + rs.map(function (r) {
          var who = r.source === 'nightly' ? 'Nightly (automatic)' : 'Manual' + (r.first_name ? ' · ' + esc(r.first_name + ' ' + (r.last_name || '')) : '');
          return '<tr class="pr-row" data-run="' + r.id + '"><td>' + esc(when(r.created_at)) + '</td><td>' + who + '</td><td>' + pill(ST[r.status] || ST.completed) + '</td><td class="n hm">' + r.succeeded + '</td><td class="n hm">' + r.failed + '</td><td class="n">' + money(r.total_charged) + '</td></tr>';
        }).join('') + '</tbody></table></div>'
      : '<div style="color:#64748B;font-size:13.5px;">No runs yet.</div>') + '</div>';
    box.querySelectorAll('[data-run]').forEach(function (tr) { tr.addEventListener('click', function () { showRun(+tr.getAttribute('data-run')); }); });
  }

  async function showRun(id) {
    var d;
    try { d = await KT.Api.get('/admin/payment-runs/' + id); } catch (e) { toast(e.message || 'Could not load the run', 'error'); return; }
    var r = d.run, res = r.results || [];
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:1000000;padding:16px';
    ov.innerHTML = '<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;width:100%;max-width:720px;max-height:90vh;overflow:auto;padding:18px 20px">'
      + '<div style="display:flex;gap:10px;align-items:center;margin-bottom:10px"><h3 style="margin:0;font-size:16px;flex:1">Run of ' + esc(when(r.created_at)) + '</h3>' + pill(ST[r.status] || ST.completed) + '</div>'
      + '<div style="font-size:13px;color:#475569;margin-bottom:10px">' + r.succeeded + ' charged · ' + r.failed + ' failed' + (r.skipped ? ' · ' + r.skipped + ' not charged' : '') + ' · ' + money(r.total_charged) + ' collected</div>'
      + (res.length ? '<div style="overflow-x:auto"><table class="pr-t" data-kt-no-kebab data-kt-no-controls data-kt-no-filter><thead><tr><th>Family</th><th>Invoice</th><th class="n">Amount</th><th>Result</th></tr></thead><tbody>'
        + res.map(function (x) { return '<tr><td>' + esc(x.family) + '</td><td>' + esc(x.invoice_number || ('#' + x.invoice_id)) + '</td><td class="n">' + money(x.amount) + '</td><td>' + pill(RES[x.result] || RES.skipped) + (x.result !== 'charged' && x.message ? '<div style="font-size:11.5px;color:#64748B;margin-top:2px">' + esc(x.message) + '</div>' : '') + '</td></tr>'; }).join('')
        + '</tbody></table></div>' : '<div style="color:#64748B">Nothing was due.</div>')
      + '<div style="display:flex;justify-content:flex-end;margin-top:14px"><button class="kt-btn" data-x>Close</button></div></div>';
    document.body.appendChild(ov);
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    ['agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':payment-run', render); });
  }
  reg(0);
})(window);
