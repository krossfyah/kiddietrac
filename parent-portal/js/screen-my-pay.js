/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — My pay / payslips (2026-07-21; redesigned 2026-07-31).
   Staff-facing: educators (hourly) + home visitors (per visit) see their
   weekly gross-pay statements and download a PDF payslip per week.
   Data + PDF come from /me/payslips (PayController).

   REDESIGN: a gradient summary card (12-week total + units) over a
   responsive grid of per-period cards — stacks cleanly on the phone/APK
   (the old wide min-width:560px table forced horizontal scrolling on mobile).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n) { return '$' + (Number(n) || 0).toFixed(2); }
  // Date-only: formatted from its parts. Parsed, it renders a day early (see KT.dayLabel).
  function fmt(d) { return (window.KT && KT.dayLabel) ? KT.dayLabel(d, { year: false }) : d; }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  function ensureCss() {
    if (document.getElementById('kt-mypay-css')) return;
    var s = document.createElement('style'); s.id = 'kt-mypay-css';
    s.textContent =
      '.kt-pay-wrap{padding:18px 14px;max-width:860px;margin:0 auto;}' +
      '.kt-pay-head h2{margin:0 0 2px;font-size:20px;color:#0D1B2A;}' +
      '.kt-pay-sub{color:#64748B;font-size:13px;margin-bottom:14px;}' +
      '.kt-pay-warn{background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:12px 14px;color:#9A3412;font-size:13px;margin-bottom:14px;}' +
      '.kt-pay-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;background:linear-gradient(135deg,#1F6080 0%,#2C8AAC 60%,#8EC73C 150%);color:#fff;border-radius:18px;padding:20px 22px;margin-bottom:16px;box-shadow:0 12px 26px -14px rgba(31,96,128,.6);}' +
      '.kt-pay-sumlabel{font-size:11px;opacity:.9;font-weight:700;text-transform:uppercase;letter-spacing:.6px;}' +
      '.kt-pay-sumval{font-size:32px;font-weight:800;line-height:1.05;margin-top:5px;}' +
      '.kt-pay-sumright{text-align:right;flex:0 0 auto;}' +
      '.kt-pay-sumunits{font-size:19px;font-weight:800;}' +
      '.kt-pay-sumunitl{font-size:11px;opacity:.9;font-weight:600;margin-top:2px;}' +
      '.kt-pay-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;}' +
      '.kt-pay-card{background:#fff;border:1px solid #E7EDF3;border-radius:15px;padding:15px 16px;box-shadow:0 1px 3px rgba(15,23,42,.05);}' +
      '.kt-pay-card-top{display:flex;align-items:baseline;justify-content:space-between;gap:8px;}' +
      '.kt-pay-period{font-weight:800;font-size:14.5px;color:#0D1B2A;}' +
      '.kt-pay-gross{font-weight:800;font-size:19px;color:#1F6080;white-space:nowrap;}' +
      '.kt-pay-meta{color:#64748B;font-size:13px;margin:7px 0 13px;}' +
      '.kt-pay-dl{width:100%;background:#1F6080;color:#fff;border:0;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:700;cursor:pointer;transition:background .12s;}' +
      '.kt-pay-dl:hover{background:#184e68;}' +
      '.kt-pay-dl:disabled{opacity:.6;cursor:default;}' +
      '.kt-pay-empty{text-align:center;color:#64748B;padding:40px 20px;background:#fff;border:1px dashed #CBD5E1;border-radius:14px;}' +
      '@media(max-width:600px){.kt-pay-wrap{padding:14px 12px;}.kt-pay-summary{padding:17px 18px;border-radius:16px;}.kt-pay-sumval{font-size:27px;}.kt-pay-list{grid-template-columns:1fr;gap:10px;}}';
    document.head.appendChild(s);
  }

  async function downloadPdf(start, btn) {
    var old = btn.textContent; btn.textContent = 'Preparing…'; btn.disabled = true;
    try {
      var r = await fetch(apiBase() + '/me/payslips/' + start + '/pdf', { headers: { Authorization: 'Bearer ' + tok() } });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var blob = await r.blob(); var url = URL.createObjectURL(blob);
      var a = document.createElement('a'); a.href = url; a.download = 'payslip-' + start + '.pdf';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
    } catch (e) { if (KT.toast) KT.toast('⚠️', 'Download failed', e.message || '', '#DC2626'); }
    finally { btn.textContent = old; btn.disabled = false; }
  }

  async function render(container) {
    ensureCss();
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading pay…</div>';
    var d;
    try { d = await Api.get('/me/payslips'); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load pay: ' + esc(e.message) + '</div>'; return; }
    var slips = (d && d.payslips) || [];
    var unit = d.unit_label || 'hours';
    var perLabel = d.pay_type === 'per_visit' ? 'visit' : 'hr';
    var rateLine = money(d.rate) + ' / ' + (d.pay_type === 'per_visit' ? 'visit' : 'hour');
    var totGross = slips.reduce(function (a, s) { return a + (Number(s.gross) || 0); }, 0);
    var totUnits = slips.reduce(function (a, s) { return a + (Number(s.units) || 0); }, 0);

    var html = '<div class="kt-pay-wrap">'
      + '<div class="kt-pay-head"><h2>💵 My pay</h2><div class="kt-pay-sub">Weekly pay statements · rate ' + esc(rateLine) + '</div></div>';
    if (!d.rate_set) {
      html += '<div class="kt-pay-warn">⚠️ Your pay rate hasn\'t been set yet — amounts show as $0.00 until an administrator sets it.</div>';
    }
    // Gradient summary
    html += '<div class="kt-pay-summary">'
      + '<div><div class="kt-pay-sumlabel">Earned · last 12 weeks</div><div class="kt-pay-sumval">' + money(totGross) + '</div></div>'
      + '<div class="kt-pay-sumright"><div class="kt-pay-sumunits">' + (Math.round(totUnits * 100) / 100) + '</div><div class="kt-pay-sumunitl">' + esc(unit) + '</div></div>'
      + '</div>';

    if (!slips.length) {
      html += '<div class="kt-pay-empty">No pay activity in the last 12 weeks.</div></div>';
      container.innerHTML = html;
      renderDocs(container);
      return;
    }
    html += '<div class="kt-pay-list">';
    slips.forEach(function (s) {
      html += '<div class="kt-pay-card">'
        + '<div class="kt-pay-card-top"><div class="kt-pay-period">' + esc(fmt(s.period_start)) + ' – ' + esc(fmt(s.period_end)) + '</div>'
        + '<div class="kt-pay-gross">' + money(s.gross) + '</div></div>'
        + '<div class="kt-pay-meta">' + esc(s.units) + ' ' + esc(s.unit_label || unit) + ' · ' + money(s.rate) + '/' + perLabel + '</div>'
        + '<button data-start="' + esc(s.period_start) + '" class="kt-pay-dl">⬇ Payslip PDF</button>'
        + '</div>';
    });
    html += '</div></div>';
    container.innerHTML = html;
    container.querySelectorAll('.kt-pay-dl[data-start]').forEach(function (b) { b.addEventListener('click', function () { downloadPdf(b.getAttribute('data-start'), b); }); });
    renderDocs(container);
  }

  /* The documents that have actually been ISSUED, as opposed to the rolling 12-week
     calculation above it. The two answer different questions — "what am I owed for the
     work I have logged" and "what has the agency put on the record" — and a staff member
     asking about their pay is usually asking the second one. Module scope, not nested
     inside render(): a declaration inside another function body is invisible to its
     siblings, which is the trap behind three crashes this month. */
  async function renderDocs(container) {
    var host = document.createElement('div');
    host.style.cssText = 'max-width:860px;margin:22px auto 0;padding:0 14px;';
    host.innerHTML = '<div style="color:#94A3B8;font-size:13px;">Loading issued documents…</div>';
    container.appendChild(host);

    var res;
    try { res = await Api.get('/auth/me/payroll-documents'); } catch (e) { host.remove(); return; }
    var rows = (res && res.data) || [];
    if (!rows.length) { host.remove(); return; }

    var head = '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 10px;">'
      + '<h3 style="margin:0;font-size:16px;color:#0D1B2A;">🧾 Issued documents</h3>'
      + '<span style="font-size:12.5px;color:#64748B;">' + rows.length + ' on record'
      + (res.ytd_gross ? ' · <strong style="color:#0D1B2A;">' + money(res.ytd_gross) + '</strong> this year' : '')
      + '</span></div>';

    var cards = rows.map(function (d) {
      var period = d.period_start
        ? esc(fmt(d.period_start)) + ' – ' + esc(fmt(d.period_end || d.period_start))
        : 'No period';
      var tone = d.status === 'paid' ? ['#DCFCE7', '#166534'] : (d.status === 'void' ? ['#F1F5F9', '#64748B'] : ['#E0F2FE', '#075985']);
      return '<div class="kt-pay-card">'
        + '<div class="kt-pay-card-top"><div class="kt-pay-period">' + period + '</div>'
        + '<div class="kt-pay-gross">' + money(d.gross) + '</div></div>'
        + (d.net != null && Number(d.net).toFixed(2) !== Number(d.gross).toFixed(2)
            ? '<div style="font-size:12px;color:#64748B;margin-top:-4px;">net ' + money(d.net) + ' after deductions</div>' : '')
        + '<div class="kt-pay-meta">' + esc(d.kind === 'invoice' ? 'Payroll invoice' : 'Payslip')
        + (d.reference ? ' · ' + esc(d.reference) : '')
        + (Number(d.rate) > 0 ? ' · ' + esc(String(d.units)) + ' ' + esc(d.unit_label || '') : '')
        + '</div>'
        + '<div style="display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;">'
        + '<span style="font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px;background:' + tone[0] + ';color:' + tone[1] + ';">'
        + esc((d.status || 'issued').charAt(0).toUpperCase() + (d.status || 'issued').slice(1)) + '</span>'
        + (Number(d.rate) > 0 ? '' : '<span style="font-size:11.5px;color:#9A3412;">No pay rate on file</span>')
        + '<button data-doc="' + d.id + '" class="kt-pay-dl" style="margin-left:auto;">⬇ PDF</button>'
        + '</div></div>';
    }).join('');

    host.innerHTML = head + '<div class="kt-pay-list">' + cards + '</div>';
    host.querySelectorAll('[data-doc]').forEach(function (b) {
      b.addEventListener('click', function () { openDoc(b.getAttribute('data-doc'), b); });
    });
  }

  /* Fetched with the token rather than linked: the PDF route is authenticated and a
     plain href cannot carry the header. */
  async function openDoc(id, btn) {
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Opening…';
    try {
      var r = await fetch(apiBase() + '/payroll-documents/' + id + '/pdf', {
        headers: { Authorization: 'Bearer ' + tok(), Accept: 'application/pdf' },
      });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var url = URL.createObjectURL(await r.blob());
      window.open(url, '_blank');
      setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
    } catch (e) {
      if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not open that document', 'error');
    }
    btn.disabled = false; btn.textContent = label;
  }

  KT.Shell.registerScreen('educator:my-hours', render);
  KT.Shell.registerScreen('home_visitor:my-pay', render);
  KT.Shell.registerScreen('centre_director:my-hours', render);
  window.KT.MyPay = { render: render };
})(window);
