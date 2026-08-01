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
  function fmt(d) { try { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); } catch (e) { return d; } }
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
    container.querySelectorAll('.kt-pay-dl').forEach(function (b) { b.addEventListener('click', function () { downloadPdf(b.getAttribute('data-start'), b); }); });
  }

  KT.Shell.registerScreen('educator:my-hours', render);
  KT.Shell.registerScreen('home_visitor:my-pay', render);
  KT.Shell.registerScreen('centre_director:my-hours', render);
  window.KT.MyPay = { render: render };
})(window);
