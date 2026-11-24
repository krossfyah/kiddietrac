/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — My pay (2026-07-21; tables 2026-09-05; ledger view 2026-09-06).

   Staff-facing: educators (hourly), home visitors (per visit) and directors see
   what they have earned and what has been issued to them.

   Anthony, 2026-09-05: "create a nice view for the my pay like we have for the
   account ledger where staff can see alot more information on desktop and mobile
   friendly".

   TWO DIFFERENT QUESTIONS, KEPT APART. This screen answers both and must never
   add them together:

     ON RECORD  — payroll_documents. What the agency has actually issued and
                  paid. Authoritative; this is what somebody asking "have I been
                  paid" means, so it leads.
     THIS WEEK  — /me/payslips, computed live from logged hours or visits over the
                  last 12 weeks. What the work is worth, whether or not anything
                  has been issued for it yet.

   The same work appears in both, on different bases, so summing them would double
   count. They are separate sections with their own headings for that reason.

   The look comes from KT.LedgerUI (kt-polish.js), shared with the account ledger
   so the two cannot drift.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* The shared ledger language, with a small local fallback so a load-order
     surprise degrades to something plain rather than a blank screen. */
  var UI = (KT.LedgerUI) || {
    C: { ink: '#0F172A', muted: '#64748B', faint: '#94A3B8', rule: '#E2E8F0', accent: '#2563EB', good: '#16A34A', warn: '#B45309', bad: '#B91C1C', out: '#4338CA' },
    money: function (n) { return '$' + (Number(n) || 0).toFixed(2); },
    money0: function (n) { return '$' + Math.round(Number(n) || 0); },
    kpi: function (l, v) { return '<div class="kt-card" style="padding:14px 16px;"><div>' + esc(l) + '</div><div>' + esc(v) + '</div></div>'; },
    card: function (t, h, inner) { return '<div class="kt-card" style="padding:16px 18px;"><h3>' + esc(t) + '</h3>' + inner + '</div>'; },
    empty: function (t) { return '<div style="padding:26px;text-align:center;color:#94A3B8;">' + esc(t) + '</div>'; },
    grid: function (c) { return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">' + c.join('') + '</div>'; },
    bars: function () { return ''; }
  };
  var C = UI.C;
  var money = UI.money;

  // Date-only: formatted from its parts. Parsed, it renders a day early (see KT.dayLabel).
  function fmt(d) { return (window.KT && KT.dayLabel) ? KT.dayLabel(d, { year: false }) : d; }
  function shortDay(d) {
    // "8 Sep" for a chart axis — the year is noise across a 12-week window.
    if (window.KT && KT.dayLabel) { return KT.dayLabel(d, { year: false }); }
    return String(d || '').slice(5);
  }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  /* One place for the table's look, so the two tables cannot drift apart. Figures
     are right-aligned with tabular numerals — a column of money that does not line
     up defeats the point of using a table. */
  var TH = 'text-align:left;padding:9px 12px;font-size:10.5px;font-weight:800;color:#64748B;'
    + 'text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;background:#F8FAFC;'
    + 'border-bottom:1px solid #E2E8F0;';
  var TD = 'padding:11px 12px;font-size:13px;color:#334155;border-top:1px solid #EEF2F6;vertical-align:top;';
  var NUM = 'text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;';

  function payTable(headers, rows, rightCols) {
    var head = headers.map(function (h, i) {
      return '<th style="' + TH + (rightCols.indexOf(i) !== -1 ? NUM : '') + '">' + h + '</th>';
    }).join('');
    var body = rows.map(function (cells) {
      return '<tr>' + cells.map(function (c, i) {
        return '<td style="' + TD + (rightCols.indexOf(i) !== -1 ? NUM : '') + '">' + c + '</td>';
      }).join('') + '</tr>';
    }).join('');
    /* data-kt-no-filter: kt-table-filter.js attaches to EVERY #appMain table and
       would put a search box and pager above a twelve-row list — noise on a phone,
       which is where educators read this. */
    return '<div style="overflow-x:auto;border:1px solid #E2E8F0;border-radius:12px;background:#fff;">'
      + '<table data-kt-no-filter style="width:100%;border-collapse:collapse;min-width:520px;">'
      + '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
  }

  function ensureCss() {
    if (document.getElementById('kt-mypay-css')) return;
    var s = document.createElement('style'); s.id = 'kt-mypay-css';
    s.textContent =
      '.kt-pay-wrap{padding:18px 14px;max-width:1080px;margin:0 auto;}' +
      '.kt-pay-head h2{margin:0 0 2px;font-size:20px;color:#0D1B2A;}' +
      '.kt-pay-sub{color:#64748B;font-size:13px;margin-bottom:14px;}' +
      '.kt-pay-warn{background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:12px 14px;color:#9A3412;font-size:13px;margin-bottom:14px;}' +
      '.kt-pay-sec{margin-top:16px;}' +
      '.kt-pay-headrow{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;}' +
      '.kt-pay-setup{margin-left:auto;flex:0 0 auto;text-decoration:none;font-size:13px;font-weight:700;' +
        'color:#1F6080;border:1px solid #CBD5E1;border-radius:9px;padding:8px 12px;background:#fff;white-space:nowrap;}' +
      '.kt-pay-setup:hover{border-color:#1F6080;}' +
      '.kt-pay-setupbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#EFF6FF;' +
        'border:1px solid #BFDBFE;border-radius:12px;padding:11px 14px;color:#1E3A5F;font-size:13px;margin-bottom:14px;}' +
      '.kt-pay-setupbar a{margin-left:auto;color:#1D4ED8;font-weight:800;text-decoration:none;white-space:nowrap;}' +
      /* SUB-TABS. The page stacked two tables and a chart into one long scroll, so
         reaching the weekly statements meant scrolling past every issued document.
         Same tab treatment the rest of the portal uses — an underline on the active
         one, nothing that looks like a button. */
      '.kt-pay-tabs{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid #E5E7EB;' +
        'margin:16px 0 0;-webkit-overflow-scrolling:touch;}' +
      '.kt-pay-tab{appearance:none;background:none;border:0;border-bottom:2px solid transparent;' +
        'padding:9px 10px;margin-bottom:-1px;font-size:14px;font-weight:700;color:#6B7280;' +
        'cursor:pointer;white-space:nowrap;flex:0 0 auto;font-family:inherit;}' +
      '.kt-pay-tab[aria-selected="true"]{color:#1F6080;border-bottom-color:#1F6080;}' +
      '.kt-pay-tab .n{color:#94A3B8;font-weight:600;margin-left:5px;font-size:12.5px;}' +
      '.kt-pay-tab[aria-selected="true"] .n{color:#1F6080;}' +
      /* The row action. It must NAME the document: kt-table-export.js strips any
         button whose whole label is a download glyph plus a format word. */
      '.kt-pay-dl{background:#1F6080;color:#fff;border:0;border-radius:8px;padding:7px 12px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .12s;}' +
      '.kt-pay-dl:hover{background:#184e68;}' +
      '.kt-pay-dl:disabled{opacity:.6;cursor:default;}' +
      /* 2026-10-28 redesign: the pay card, tiles, charts, ledger. */
      '.kt-pay-wrap{max-width:1180px;}' +
      '.kt-pay-hero{position:relative;overflow:hidden;border-radius:20px;padding:18px 22px 20px;margin-bottom:14px;color:#fff;' +
        'background:linear-gradient(135deg,#065F46 0%,#047857 35%,#0D9488 70%,#0EA5E9 130%);box-shadow:0 18px 36px -20px rgba(4,120,87,.8);}' +
      '.kt-pay-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 85% -20%,rgba(255,255,255,.22),transparent 45%),' +
        'repeating-linear-gradient(115deg,rgba(255,255,255,.05) 0 2px,transparent 2px 22px);pointer-events:none;}' +
      '.kt-pay-coins{position:absolute;left:46%;bottom:6px;font-size:54px;opacity:.2;line-height:1;pointer-events:none;animation:ktPayFloat 5s ease-in-out infinite;}' +
      '.kt-pay-coins span{font-size:30px;position:relative;left:-6px;top:-28px;}.kt-pay-coins i{font-style:normal;font-size:38px;}' +
      '@keyframes ktPayFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}' +
      '@media(prefers-reduced-motion:reduce){.kt-pay-coins{animation:none}}' +
      '.kt-pay-hero-top{position:relative;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}' +
      '.kt-pay-rate{display:inline-flex;align-items:center;height:28px;padding:0 12px;border-radius:999px;background:rgba(255,255,255,.18);font-size:12.5px;font-weight:800;}' +
      '.kt-pay-hero .kt-pay-setup{margin-left:auto;background:rgba(255,255,255,.95);border-color:transparent;color:#065F46;height:32px;display:inline-flex;align-items:center;padding:0 12px;}' +
      '.kt-pay-hero-row{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-top:12px;}' +
      '.kt-pay-hero-lbl{font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;opacity:.85;}' +
      '.kt-pay-hero-big{font-size:36px;font-weight:900;line-height:1.05;margin-top:2px;}' +
      '.kt-pay-hero-sub{font-size:12.5px;opacity:.9;margin-top:4px;}' +
      '.kt-pay-hero-side{display:flex;gap:10px;flex-wrap:wrap;}' +
      '.kt-pay-hero-side>div{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:8px 12px;min-width:140px;}' +
      '.kt-pay-hero-side span{display:block;font-size:11px;opacity:.85;}.kt-pay-hero-side b{font-size:17px;font-weight:900;}' +
      '.kt-pay-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px;}' +
      '.kt-pay-tile{border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:13px 15px;min-width:0;}' +
      '.kt-pay-tile .top{display:flex;align-items:center;gap:8px;}.kt-pay-tile .ic{width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:14px;flex:0 0 auto;}' +
      '.kt-pay-tile .lb{font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
      '.kt-pay-tile .v{font-size:23px;font-weight:900;margin-top:8px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
      '.kt-pay-tile .s{font-size:11.5px;color:#64748B;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
      '.kt-pay-charts{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:4px;}' +
      '.kt-pay-chart{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:14px 16px;min-width:0;}' +
      '.kt-pay-lbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;}.kt-pay-chips{display:flex;gap:6px;flex-wrap:wrap;flex:1;min-width:0;}' +
      '.kt-pay-chip{height:30px;padding:0 12px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;color:#475569;font-weight:700;font-size:12.5px;cursor:pointer;font-family:inherit;}' +
      '.kt-pay-chip.on{border-color:#10B981;background:#ECFDF5;color:#047857;}' +
      '.kt-pay-btn{height:32px;padding:0 12px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#1F2937;font-weight:700;font-size:12.5px;cursor:pointer;font-family:inherit;}' +
      '.kt-pay-lsum{display:flex;gap:8px;margin-bottom:10px;}.kt-pay-lsum>div{flex:1 1 0;min-width:0;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:12px;padding:9px 11px;}' +
      '.kt-pay-lsum span{display:block;font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;}.kt-pay-lsum b{font-size:17px;font-weight:900;}' +
      '.kt-pay-ledger{background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;}' +
      '.kt-pay-lrow{display:grid;grid-template-columns:110px 26px 1fr auto 34px;gap:10px;align-items:center;padding:10px 14px;border-top:1px solid #F1F5F9;font-size:13.5px;}' +
      '.kt-pay-lrow:first-child{border-top:0;}.kt-pay-lrow:hover{background:#FAFCFF;}.kt-pay-lrow.void{opacity:.55;}.kt-pay-lrow.void .h{text-decoration:line-through;}' +
      '.kt-pay-lrow .d{color:#475569;}.kt-pay-lrow .h{font-weight:700;color:#0F172A;}.kt-pay-lrow .s{font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
      '.kt-pay-lrow .dm{display:none;}.kt-pay-lrow .t{min-width:0;}.kt-pay-lrow .a{text-align:right;font-weight:800;white-space:nowrap;}.kt-pay-lrow .bal{font-size:11px;color:#94A3B8;font-weight:600;}' +
      '@media(max-width:900px){.kt-pay-tiles{grid-template-columns:repeat(2,minmax(0,1fr));}.kt-pay-charts{grid-template-columns:1fr;}}' +
      '@media(max-width:600px){.kt-pay-wrap{padding:14px 12px;}.kt-pay-coins{left:auto;right:14px;top:62px;bottom:auto;font-size:40px;}.kt-pay-coins span{font-size:22px;top:-20px;}.kt-pay-coins i{font-size:28px;}.kt-pay-hero-big{font-size:30px;}.kt-pay-tile .v{font-size:19px;}' +
        '.kt-pay-lrow{grid-template-columns:26px 1fr auto 34px;padding:10px 12px;}.kt-pay-lrow .d{display:none;}.kt-pay-lrow .dm{display:inline;}.kt-pay-hero-side>div{min-width:0;flex:1;}}';
    document.head.appendChild(s);
  }

  /* Fetched with the token rather than linked: these routes are authenticated and a
     plain href cannot carry the header. */
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

  async function openDoc(id, btn) {
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Opening…';
    try {
      var r = await fetch(apiBase() + '/payroll-documents/' + id + '/pdf', { headers: { Authorization: 'Bearer ' + tok() } });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var blob = await r.blob();
      if (window.KT && KT.openFile) { KT.openFile(blob, { title: 'Pay document', filename: 'pay-document.pdf', label: 'Pay' }); }
      else { var url = URL.createObjectURL(blob); window.open(url, '_blank'); setTimeout(function () { URL.revokeObjectURL(url); }, 20000); }
    } catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not open', e.message || '', '#DC2626'); }
    finally { btn.disabled = false; btn.textContent = label; }
  }

  // ── the two sections ──────────────────────────────────────────────────────

  /* ON RECORD — what the agency has issued. The authoritative half, so it leads.

     "Have I been paid?" is answered by these rows, not by the rolling calculation
     below, which is why the KPI strip is built from them alone. Void documents are
     excluded from every total: a voided payslip is not money owed, money paid, or
     money outstanding — counting it anywhere would make the tiles disagree with the
     table underneath them. */
  function recordTotals(rows) {
    var t = { paid: 0, awaiting: 0, onRecord: 0, voided: 0, count: 0, lastPaid: null };
    (rows || []).forEach(function (d) {
      var g = Number(d.gross) || 0;
      var st = String(d.status || 'issued').toLowerCase();
      if (st === 'void') { t.voided += g; return; }
      t.count++;
      t.onRecord += g;
      if (st === 'paid') {
        t.paid += g;
        var when = d.paid_at || d.period_end || d.period_start;
        if (when && (!t.lastPaid || String(when) > String(t.lastPaid))) { t.lastPaid = when; }
      } else {
        t.awaiting += g;
      }
    });
    return t;
  }

  function kpiStrip(t, ytd) {
    return UI.grid([
      UI.kpi('Paid to date', money(t.paid), C.good,
        t.lastPaid ? 'Most recent ' + esc(fmt(t.lastPaid)) : 'Nothing marked paid yet'),
      UI.kpi('Awaiting payment', money(t.awaiting), t.awaiting > 0 ? C.warn : C.muted,
        t.awaiting > 0 ? 'Issued, not yet paid' : 'Nothing outstanding'),
      UI.kpi('This year', money(ytd || 0), C.accent, 'Gross, ' + new Date().getFullYear()),
      /* Paid + Awaiting = On record. Stated on the tile because a reader who cannot
         see how three figures relate has to take them on trust. */
      UI.kpi('On record', money(t.onRecord), C.ink,
        t.count + (t.count === 1 ? ' document' : ' documents')
        + (t.voided > 0 ? ' · ' + money(t.voided) + ' voided, excluded' : '')),
    ]);
  }

  function docsTable(rows) {
    var body = rows.map(function (d) {
      var period = d.period_start
        ? esc(fmt(d.period_start)) + ' – ' + esc(fmt(d.period_end || d.period_start))
        : 'No period';
      var st = String(d.status || 'issued');
      var tone = st === 'paid' ? ['#DCFCE7', '#166534'] : (st === 'void' ? ['#F1F5F9', '#64748B'] : ['#E0F2FE', '#075985']);

      return [
        '<strong style="color:#0D1B2A;">' + esc(d.kind === 'invoice' ? 'Payroll invoice' : 'Payslip') + '</strong>'
          + (d.reference ? '<div style="font-size:11.5px;color:#64748B;">' + esc(d.reference) + '</div>' : '')
          /* iLearn password-protects every payslip it renders, and this document is
             iLearn's own served through us unchanged. Saying so BEFORE the prompt
             appears is the difference between a familiar step and a broken download.
             The hint is the FORMAT, never the password. */
          + (d.pdf_password_hint
              ? '<div style="margin-top:6px;padding:7px 9px;background:#F8FAFC;border:1px solid #E2E8F0;'
                + 'border-radius:8px;font-size:11.5px;color:#475569;line-height:1.45;max-width:280px;">'
                + '🔒 <strong>Password-protected.</strong> ' + esc(d.pdf_password_hint)
                + '<br><span style="color:#64748B;">The same password as the copy emailed to you.</span></div>'
              : ''),
        period,
        (Number(d.rate) > 0
          ? esc(String(d.units)) + ' <span style="color:#94A3B8;">' + esc(d.unit_label || '') + '</span>'
          : '<span style="font-size:11.5px;color:#9A3412;">No pay rate on file</span>'),
        '<strong style="color:#0D1B2A;">' + money(d.gross) + '</strong>'
          // Net only where it differs — an identical pair of figures says nothing.
          + (d.net != null && Number(d.net).toFixed(2) !== Number(d.gross).toFixed(2)
              ? '<div style="font-size:11.5px;color:#64748B;">net ' + money(d.net) + '</div>' : ''),
        '<span style="font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px;'
          + 'background:' + tone[0] + ';color:' + tone[1] + ';">'
          + esc(st.charAt(0).toUpperCase() + st.slice(1)) + '</span>',
        /* The label must NAME the document. kt-table-export.js strips any button whose
           whole label is a download glyph plus a format word ("⬇ PDF"), because that is
           the shape of the legacy per-screen export buttons its bottom bar replaced —
           and it deleted all seven of these, leaving a kebab with an empty menu. */
        // Filled after render with View / Download / Print / Email (docActions) - kt-row-actions makes them a ⋮.
        '<span data-doc-actions="' + d.id + '"></span>',
      ];
    });

    return payTable(['Document', 'Period', 'Units', 'Gross', 'Status', ''], body, [2, 3, 4, 5]);
  }

  // ── render ────────────────────────────────────────────────────────────────
  /* REDESIGN (2026-10-28, Anthony: "for educator and other staff view, the payroll section needs to be redesigned
     properly with better graphs, stats, charts and a ledger" and "payroll hero in educator and staff view needs to be
     unique"). The same two sources, still never added together (see the header note):
       • a PAY CARD of its own - what has been paid this year, the last payment, what is still owed - in a green
         "money" look no other screen uses;
       • four figures, two charts (earned by week from the live calculation; issued vs paid by month from the
         documents), then tabs: Payslips (each with a ⋮: view, download, print, email me), Ledger (every issued
         document and every payment, with what is still owed to you, a statement and a CSV), Weekly. */

  function ymdOf(d) { return d ? String(d).slice(0, 10) : ''; }
  function dl(d) { return d ? esc(fmt(ymdOf(d))) : '—'; }

  function docTitle(d) { return (d.kind === 'invoice' ? 'Payroll invoice' : 'Payslip') + (d.reference ? ' ' + d.reference : ''); }
  function docFile(d) { return (d.kind === 'invoice' ? 'payroll-invoice-' : 'payslip-') + String(d.reference || d.period_end || d.id).replace(/[^A-Za-z0-9]+/g, '-') + '.pdf'; }
  function docUrl(d) { return apiBase() + '/payroll-documents/' + d.id + '/pdf'; }

  function viewDoc(d, thenPrint) {
    if (!(KT.openFile && KT.fetchFileBlob)) { return; }
    var h = KT.openFile(KT.fetchFileBlob(docUrl(d)).then(function (b) { return new Blob([b], { type: 'application/pdf' }); }),
      { title: docTitle(d), label: 'Pay', filename: docFile(d), mime: 'application/pdf' });
    if (thenPrint) {
      // The viewer prints once the pages are drawn - in the apps through their print service (2026-11-06).
      Promise.resolve(h).then(function (v) { if (v && v.print) { v.print(); } });
    }
  }
  function downloadDoc(d) {
    if (!KT.fetchFileBlob) { return; }
    KT.fetchFileBlob(docUrl(d)).then(function (b) {
      var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([b], { type: 'application/pdf' })); a.download = docFile(d);
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
    }).catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Download failed', e.message || '', '#DC2626'); });
  }
  function emailDoc(d) {
    if (KT.toast) KT.toast('✉️', 'Sending…', 'Emailing you a copy of your ' + (d.kind === 'invoice' ? 'payroll invoice' : 'payslip') + '.', '#2C7BA0');
    Api.post('/me/payroll-documents/' + d.id + '/email', {}).then(function (r) {
      if (r && r.sent === false) { throw new Error(r.reason || 'It could not be sent.'); }
      if (KT.toast) KT.toast('✅', 'Sent', 'A copy is on its way to ' + ((r && r.email) || 'your email') + '.', '#16A34A');
    }).catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Could not send it', e.message || 'Please try again.', '#DC2626'); });
  }
  /* Plain buttons: in a table's last cell kt-row-actions collapses them into the portal's ⋮; elsewhere they go to
     KT.openActionMenu. Labels NAME the document - kt-table-export strips a button labelled only "⬇ PDF". */
  function docActions(d) {
    var out = [];
    function item(label, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', function (e) { e.stopPropagation(); fn(); }); out.push(b); }
    item('👁 View ' + (d.kind === 'invoice' ? 'invoice' : 'payslip'), function () { viewDoc(d, false); });
    item('⬇ Download ' + (d.kind === 'invoice' ? 'invoice' : 'payslip'), function () { downloadDoc(d); });
    item('🖨 Print', function () { viewDoc(d, true); });
    item('✉️ Email me a copy', function () { emailDoc(d); });
    return out;
  }
  function kebabFor(d) {
    var k = document.createElement('button');
    k.type = 'button'; k.className = 'kt-ka-kebab'; k.textContent = '⋮'; k.title = 'Payslip actions'; k.setAttribute('aria-label', 'Payslip actions');
    k.addEventListener('click', function (e) { e.stopPropagation(); e.preventDefault(); if (KT.openActionMenu) { KT.openActionMenu(k, docActions(d)); } else { viewDoc(d, false); } });
    return k;
  }

  /* Bars in SVG (no library). series: [{label, tip, a, b}] - b optional (a second bar beside a). */
  function barsSvg(series, opt) {
    opt = opt || {};
    var max = 0; series.forEach(function (s) { max = Math.max(max, s.a || 0, s.b || 0); });
    if (!max) { return '<div style="font-size:13px;color:#64748B;padding:16px 0 6px;">' + esc(opt.empty || 'Nothing to show yet.') + '</div>'; }
    var W = 600, H = opt.h || 150, n = series.length || 1, gw = W / n, two = series.some(function (s) { return s.b != null; });
    var bw = Math.min(two ? 16 : 26, gw * (two ? 0.3 : 0.55));
    var y = function (v) { return 8 + (H - 10) * (1 - v / max); };
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" height="' + H + '" preserveAspectRatio="none" role="img" style="display:block;overflow:visible">';
    [0.25, 0.5, 0.75, 1].forEach(function (f) { var yy = y(max * f); svg += '<line x1="0" x2="' + W + '" y1="' + yy + '" y2="' + yy + '" stroke="#EEF2F7" stroke-width="1"/>'; });
    svg += '<line x1="0" x2="' + W + '" y1="' + (H - 2) + '" y2="' + (H - 2) + '" stroke="#CBD5E1" stroke-width="1"/>';
    series.forEach(function (s, i) {
      var cx = gw * i + gw / 2;
      svg += '<g><title>' + esc(s.tip || s.label) + '</title><rect x="' + (cx - gw / 2) + '" y="0" width="' + gw + '" height="' + H + '" fill="transparent"/>';
      if (two) {
        if (s.a > 0) svg += '<rect x="' + (cx - bw - 1) + '" y="' + y(s.a) + '" width="' + bw + '" height="' + (H - 2 - y(s.a)) + '" rx="3" fill="' + opt.ca + '"/>';
        if (s.b > 0) svg += '<rect x="' + (cx + 1) + '" y="' + y(s.b) + '" width="' + bw + '" height="' + (H - 2 - y(s.b)) + '" rx="3" fill="' + opt.cb + '"/>';
      } else if (s.a > 0) {
        svg += '<rect x="' + (cx - bw / 2) + '" y="' + y(s.a) + '" width="' + bw + '" height="' + (H - 2 - y(s.a)) + '" rx="4" fill="' + opt.ca + '"/>';
      }
      svg += '</g>';
    });
    svg += '</svg>';
    // Labels as text, not SVG - the chart stretches with the card and would stretch the letters.
    var lab = '<div style="display:flex;margin-top:5px;">' + series.map(function (s) {
      return '<div style="flex:1;text-align:center;font-size:10.5px;color:#64748B;font-weight:600;white-space:nowrap;overflow:hidden;">' + esc(s.label) + '</div>';
    }).join('') + '</div>';
    return svg + lab;
  }

  function legend(items) {
    return '<div style="display:flex;gap:12px;font-size:12px;color:#475569;font-weight:600;">' + items.map(function (l) {
      return '<span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:3px;background:' + l[0] + ';"></span>' + esc(l[1]) + '</span>';
    }).join('') + '</div>';
  }

  function chartCard(title, sub, right, body) {
    return '<div class="kt-pay-chart" data-kt-nosechead="1"><div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">'
      + '<div><div style="font-weight:800;font-size:14px;color:#0F172A;">' + title + '</div><div style="font-size:12px;color:#64748B;margin-top:1px;">' + sub + '</div></div>'
      + (right || '') + '</div>' + body + '</div>';
  }

  /* The pay ledger: each issued document is money earned on its period's last day; each payment brings what is owed
     back down on the day it was paid. Voided documents are shown (struck through) and never counted. */
  function ledgerEntries(docs) {
    var e = [];
    docs.forEach(function (d) {
      var st = String(d.status || 'issued').toLowerCase(), g = Number(d.gross) || 0;
      var period = d.period_start ? fmt(ymdOf(d.period_start)) + ' – ' + fmt(ymdOf(d.period_end || d.period_start)) : '';
      var on = ymdOf(d.period_end || d.issued_at || d.period_start);
      if (st === 'void') { e.push({ date: on, kind: 'void', title: docTitle(d) + ' (voided)', detail: period, earned: 0, paid: 0, amt: g, doc: d }); return; }
      e.push({ date: on, kind: 'earned', title: docTitle(d), detail: period + (Number(d.units) ? ' · ' + d.units + ' ' + (d.unit_label || '') : ''), earned: g, paid: 0, doc: d });
      if (st === 'paid') { e.push({ date: ymdOf(d.paid_at || d.period_end || d.period_start), kind: 'paid', title: 'Paid to you', detail: docTitle(d), earned: 0, paid: g, doc: d }); }
    });
    var ord = { earned: 0, void: 1, paid: 2 };
    e.sort(function (a, b) { return a.date < b.date ? -1 : (a.date > b.date ? 1 : ord[a.kind] - ord[b.kind]); });
    var bal = 0; e.forEach(function (x) { bal = Math.round((bal + x.earned - x.paid) * 100) / 100; x.balance = bal; });
    return e;
  }

  function ledgerCsv(rows, year) {
    var q = function (v) { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var out = ['Date,Type,Details,Period,Earned,Paid,Owed to you'];
    rows.forEach(function (x) { out.push([x.date, x.kind, x.title, x.detail || '', x.earned ? x.earned.toFixed(2) : '', x.paid ? x.paid.toFixed(2) : '', x.balance.toFixed(2)].map(q).join(',')); });
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + out.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = 'pay-ledger' + (year && year !== 'all' ? '-' + year : '') + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  }

  function ledgerStatement(rows, year, who, owed) {
    var earned = 0, paid = 0; rows.forEach(function (x) { earned += x.earned; paid += x.paid; });
    var body = rows.map(function (x) {
      return '<tr' + (x.kind === 'void' ? ' style="color:#94A3B8;text-decoration:line-through"' : '') + '><td>' + dl(x.date) + '</td><td>' + esc(x.title) + (x.detail ? '<div class="s">' + esc(x.detail) + '</div>' : '')
        + '</td><td class="r">' + (x.earned ? money(x.earned) : '') + '</td><td class="r">' + (x.paid ? money(x.paid) : '') + '</td><td class="r b">' + money(x.balance) + '</td></tr>';
    }).join('');
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Pay statement</title><style>*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;margin:32px;font-size:12.5px}'
      + '.hd{display:flex;justify-content:space-between;border-bottom:2px solid #047857;padding-bottom:12px;margin-bottom:16px}.t{font-size:20px;font-weight:800;color:#047857}'
      + '.sum{display:flex;gap:10px;margin:0 0 14px}.sum div{flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px}.sum b{display:block;font-size:15px;margin-top:2px}'
      + 'table{width:100%;border-collapse:collapse}th,td{padding:7px 6px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{font-size:10.5px;text-transform:uppercase;color:#64748b}'
      + '.r{text-align:right;white-space:nowrap}.b{font-weight:700}.s{color:#64748b;font-size:11px;margin-top:2px}@media print{body{margin:12mm}}</style></head><body>'
      + '<div class="hd"><div><div class="t">Pay statement</div><div style="color:#64748b">' + esc(who || '') + (year && year !== 'all' ? ' · ' + esc(year) : '') + '</div></div><div style="text-align:right;color:#475569">As of ' + esc(fmt(new Date().toISOString().slice(0, 10))) + '</div></div>'
      + '<div class="sum"><div>Earned<b>' + money(earned) + '</b></div><div>Paid<b>' + money(paid) + '</b></div><div>Still owed to you<b>' + money(owed) + '</b></div></div>'
      + '<table><thead><tr><th>Date</th><th>Details</th><th class="r">Earned</th><th class="r">Paid</th><th class="r">Owed to you</th></tr></thead><tbody>' + (body || '<tr><td colspan="5">Nothing for this period.</td></tr>') + '</tbody></table>'
      + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},350);};</scr' + 'ipt></body></html>';
    var w = KT.docWindow ? KT.docWindow({ title: 'Pay statement', label: 'Statement' }) : window.open('', '_blank');
    if (!w) return; w.document.open(); w.document.write(html); w.document.close();
  }

  function paintLedger(host, docs, who) {
    var all = ledgerEntries(docs);
    var years = []; all.forEach(function (x) { var y = x.date.slice(0, 4); if (y && years.indexOf(y) === -1) years.push(y); });
    years.sort().reverse();
    var sel = 'all';
    var owedNow = all.length ? all[all.length - 1].balance : 0;
    host.innerHTML = '<div class="kt-pay-lbar"><div class="kt-pay-chips" data-kt-noband="1"></div>'
      + '<button type="button" class="kt-pay-btn" data-led="print">🖨 Statement</button><button type="button" class="kt-pay-btn" data-led="csv">⬇ Ledger (CSV)</button></div>'
      + '<div class="kt-pay-lsum" data-kt-nosechead="1"></div><div class="kt-pay-ledger" data-kt-nosechead="1"></div>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin:8px 2px 0;line-height:1.5;">Earned is what each issued payslip or invoice is worth; paid is when your agency marked it paid. Voided documents are shown but never counted.</div>';
    var chips = host.querySelector('.kt-pay-chips'), sum = host.querySelector('.kt-pay-lsum'), list = host.querySelector('.kt-pay-ledger');
    function rows() { return all.filter(function (x) { return sel === 'all' || x.date.slice(0, 4) === sel; }); }
    host.querySelector('[data-led="print"]').addEventListener('click', function () { ledgerStatement(rows(), sel, who, owedNow); });
    host.querySelector('[data-led="csv"]').addEventListener('click', function () { ledgerCsv(rows(), sel); });
    function paint() {
      chips.innerHTML = ['all'].concat(years).map(function (y) { return '<button type="button" class="kt-pay-chip' + (y === sel ? ' on' : '') + '" data-y="' + y + '">' + (y === 'all' ? 'All' : y) + '</button>'; }).join('');
      Array.prototype.forEach.call(chips.querySelectorAll('[data-y]'), function (b) { b.addEventListener('click', function () { sel = b.getAttribute('data-y'); paint(); }); });
      var r = rows(), earned = 0, paid = 0; r.forEach(function (x) { earned += x.earned; paid += x.paid; });
      sum.innerHTML = '<div><span>Earned</span><b style="color:#4338CA">' + money(earned) + '</b></div><div><span>Paid to you</span><b style="color:#15803D">' + money(paid) + '</b></div>'
        + '<div><span>Still owed to you</span><b style="color:' + (owedNow > 0.004 ? '#B45309' : '#15803D') + '">' + money(owedNow) + '</b></div>';
      list.innerHTML = r.length ? '' : '<div style="padding:22px;text-align:center;color:#64748B;font-size:13px;">Nothing for this period.</div>';
      r.slice().reverse().forEach(function (x, i) {
        var icon = x.kind === 'paid' ? '✅' : (x.kind === 'void' ? '🚫' : '🧾');
        var amt = x.kind === 'paid' ? '−' + money(x.paid) : (x.kind === 'void' ? money(x.amt) : '+' + money(x.earned));
        var ink = x.kind === 'paid' ? '#15803D' : (x.kind === 'void' ? '#94A3B8' : '#0F172A');
        var row = document.createElement('div');
        row.className = 'kt-pay-lrow' + (x.kind === 'void' ? ' void' : '');
        row.innerHTML = '<div class="d">' + dl(x.date) + '</div><div class="i">' + icon + '</div><div class="t"><div class="h">' + esc(x.title) + '</div>'
          + '<div class="s"><span class="dm">' + dl(x.date) + (x.detail ? ' · ' : '') + '</span>' + esc(x.detail || '') + '</div></div><div class="a" style="color:' + ink + '">' + amt + '<div class="bal">owed ' + money(x.balance) + '</div></div>';
        if (x.doc && x.kind !== 'void') { row.appendChild(kebabFor(x.doc)); row.style.cursor = 'pointer'; row.addEventListener('click', function () { viewDoc(x.doc, false); }); }
        else { row.appendChild(document.createElement('div')); }
        list.appendChild(row);
      });
    }
    paint();
  }

  async function render(container) {
    ensureCss();
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading pay…</div>';

    var pay = null, docsRes = null, payout = null;
    try {
      var results = await Promise.all([
        Api.get('/me/payslips'),
        Api.get('/auth/me/payroll-documents').catch(function () { return null; }),
        /* Only to know WHETHER anything is on file. Never returns an account number. */
        Api.get('/me/payout-method').catch(function () { return null; }),
      ]);
      pay = results[0]; docsRes = results[1];
      payout = results[2] ? { method: (results[2].payout_method || {}).method || null } : null;
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load pay: ' + esc(e.message) + '</div>';
      return;
    }

    var slips = (pay && pay.payslips) || [];
    var docs = (docsRes && docsRes.data) || [];
    var unit = pay.unit_label || 'hours';
    var perLabel = pay.pay_type === 'per_visit' ? 'visit' : 'hr';
    var rateLine = money(pay.rate) + ' / ' + (pay.pay_type === 'per_visit' ? 'visit' : 'hour');
    var totGross = slips.reduce(function (a, s) { return a + (Number(s.gross) || 0); }, 0);
    var totUnits = slips.reduce(function (a, s) { return a + (Number(s.units) || 0); }, 0);
    var t = recordTotals(docs);
    var year = String(new Date().getFullYear());
    var paidYear = 0, lastPaidAmt = 0, lastPaidOn = null;
    docs.forEach(function (d) {
      if (String(d.status).toLowerCase() !== 'paid') return;
      var when = ymdOf(d.paid_at || d.period_end || d.period_start);
      if (when.slice(0, 4) === year) paidYear += Number(d.gross) || 0;
      if (!lastPaidOn || when > lastPaidOn) { lastPaidOn = when; lastPaidAmt = Number(d.gross) || 0; } else if (when === lastPaidOn) { lastPaidAmt += Number(d.gross) || 0; }
    });
    var weeksWorked = slips.filter(function (s) { return Number(s.units) > 0; }).length;
    var u; try { u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { u = {}; }
    var who = ((u.first_name || '') + ' ' + (u.last_name || '')).trim();

    var html = '<div class="kt-pay-wrap">';

    // ── the pay card: this screen's own look ──
    html += '<div class="kt-pay-hero" data-kt-nosechead="1"><div class="kt-pay-coins" aria-hidden="true">💵<span>🪙</span><i>💰</i></div>'
      + '<div class="kt-pay-hero-top"><span class="kt-pay-rate">' + (pay.rate_set ? '💵 ' + esc(rateLine) : '⚠️ Pay rate not set') + '</span>'
      + '<a class="kt-pay-setup" href="#settings?tab=payroll">🏦 Payout details</a></div>'
      + '<div class="kt-pay-hero-row"><div><div class="kt-pay-hero-lbl">Paid to you in ' + year + '</div><div class="kt-pay-hero-big">' + money(paidYear) + '</div>'
      + '<div class="kt-pay-hero-sub">' + (lastPaidOn ? 'Last paid ' + dl(lastPaidOn) + ' · ' + money(lastPaidAmt) : 'Nothing marked paid yet') + '</div></div>'
      + '<div class="kt-pay-hero-side"><div><span>Awaiting payment</span><b>' + money(t.awaiting) + '</b></div>'
      + '<div><span>Earned, last 12 weeks</span><b>' + money(totGross) + '</b></div></div></div></div>';

    if (payout && !payout.method) {
      html += '<div class="kt-pay-setupbar"><span>💡 Your agency has no payout details for you yet — payroll will not know where to send your pay.</span><a href="#settings?tab=payroll">Add them →</a></div>';
    }
    if (!pay.rate_set) {
      html += '<div class="kt-pay-warn">⚠️ Your pay rate hasn\'t been set yet — amounts show as $0.00 until an administrator sets it.</div>';
    }

    // ── four figures ──
    var tiles = [
      ['💰', 'Paid to date', money(t.paid), t.lastPaid ? 'Most recent ' + dl(t.lastPaid) : 'Nothing marked paid yet', '#F0FDF4', '#15803D', '#86EFAC', '#16A34A'],
      ['⏳', 'Awaiting payment', money(t.awaiting), t.awaiting > 0 ? 'Issued, not yet paid' : 'Nothing outstanding', '#FFF7ED', '#C2410C', '#FDBA74', '#F97316'],
      ['📈', 'Average week', money(weeksWorked ? totGross / weeksWorked : 0), weeksWorked + ' of the last ' + slips.length + ' weeks worked', '#EEF2FF', '#4338CA', '#A5B4FC', '#6366F1'],
      ['⏱️', unit.charAt(0).toUpperCase() + unit.slice(1) + ', 12 weeks', String(Math.round(totUnits * 100) / 100), 'Logged ' + esc(unit) + ' behind the weekly figures', '#F0F9FF', '#0369A1', '#7DD3FC', '#0EA5E9'],
    ];
    html += '<div class="kt-pay-tiles" data-kt-nosechead="1">' + tiles.map(function (x) {
      return '<div class="kt-pay-tile" style="background:' + x[4] + ';"><div class="top"><span class="ic" style="background:linear-gradient(135deg,' + x[6] + ',' + x[7] + ');box-shadow:0 5px 11px -6px ' + x[7] + ';">' + x[0] + '</span>'
        + '<span class="lb" style="color:' + x[5] + ';">' + esc(x[1]) + '</span></div><div class="v" style="color:' + x[5] + ';">' + x[2] + '</div><div class="s">' + x[3] + '</div></div>';
    }).join('') + '</div>';

    // ── charts ──
    /* With no pay rate the money is all $0 while the hours are real: chart the hours then, rather than a chart that
       says "nothing logged" beside a tile showing 500 hours. */
    var byHours = totGross <= 0 && totUnits > 0;
    var weekSeries = slips.slice().reverse().map(function (s) {
      return { label: shortDay(s.period_start), a: byHours ? (Number(s.units) || 0) : (Number(s.gross) || 0), tip: fmt(s.period_start) + ' – ' + fmt(s.period_end) + ': ' + money(s.gross) + ' · ' + s.units + ' ' + unit };
    });
    var months = [], d0 = new Date(); d0.setDate(1);
    for (var k = 5; k >= 0; k--) { var dm = new Date(d0.getFullYear(), d0.getMonth() - k, 1); months.push({ key: dm.getFullYear() + '-' + String(dm.getMonth() + 1).padStart(2, '0'), label: dm.toLocaleDateString(undefined, { month: 'short' }), a: 0, b: 0 }); }
    docs.forEach(function (d) {
      var st = String(d.status).toLowerCase(); if (st === 'void') return;
      var mi = ymdOf(d.period_end || d.period_start).slice(0, 7), mp = ymdOf(d.paid_at || d.period_end).slice(0, 7);
      months.forEach(function (m) { if (m.key === mi) m.a += Number(d.gross) || 0; if (st === 'paid' && m.key === mp) m.b += Number(d.gross) || 0; });
    });
    months.forEach(function (m) { m.tip = m.label + ' — issued ' + money(m.a) + ', paid ' + money(m.b); });
    html += '<div class="kt-pay-charts">'
      + chartCard(byHours ? unit.charAt(0).toUpperCase() + unit.slice(1) + ' by week' : 'Earned by week',
          byHours ? (Math.round(totUnits * 100) / 100) + ' ' + esc(unit) + ' over the last ' + slips.length + ' weeks (no pay rate yet)' : money(totGross) + ' over the last ' + slips.length + ' weeks',
          legend([['#10B981', byHours ? unit.charAt(0).toUpperCase() + unit.slice(1) : 'Earned']]),
          barsSvg(weekSeries, { ca: '#10B981', empty: 'No logged ' + unit + ' in the last 12 weeks.' }))
      + chartCard('Issued and paid by month', 'Your payslips over the last 6 months', legend([['#6366F1', 'Issued'], ['#22C55E', 'Paid']]),
          barsSvg(months, { ca: '#6366F1', cb: '#22C55E', empty: 'No payslips issued in the last 6 months.' }))
      + '</div>';

    // ── tabs ──
    var panes = [];
    if (docs.length) {
      panes.push({ key: 'issued', label: '🧾 Payslips', count: t.count, html: '<div class="kt-pay-sec">' + UI.card('🧾 Issued documents', t.count + ' on record', docsTable(docs)) + '</div>' });
      panes.push({ key: 'ledger', label: '📒 Ledger', count: null, html: '<div class="kt-pay-sec" data-pay-ledger></div>' });
    }
    if (slips.length) {
      panes.push({ key: 'weekly', label: '🗓 Weekly', count: slips.length, html: '<div class="kt-pay-sec">' + UI.card('🗓 Weekly statements', 'Calculated from your logged ' + esc(unit) + ' — not yet a payment',
        payTable(['Week', esc(unit.charAt(0).toUpperCase() + unit.slice(1)), 'Rate', 'Gross', ''], slips.map(function (s) {
          return ['<strong style="color:#0D1B2A;">' + esc(fmt(s.period_start)) + ' – ' + esc(fmt(s.period_end)) + '</strong>', esc(String(s.units)),
            money(s.rate) + '<span style="color:#94A3B8;">/' + perLabel + '</span>', '<strong style="color:#0D1B2A;">' + money(s.gross) + '</strong>',
            '<button data-start="' + esc(s.period_start) + '" class="kt-pay-dl">⬇ Payslip PDF</button>'];
        }), [1, 2, 3, 4])) + '</div>' });
    }
    var saved = 'issued'; try { saved = sessionStorage.getItem('kt_pay_tab') || 'issued'; } catch (e) {}
    if (!panes.some(function (p) { return p.key === saved; })) { saved = panes.length ? panes[0].key : ''; }
    if (!panes.length) {
      html += UI.empty('No pay activity in the last 12 weeks, and nothing issued yet.');
    } else {
      html += '<div class="kt-pay-tabs" role="tablist">' + panes.map(function (p) {
        return '<button type="button" class="kt-pay-tab" role="tab" data-pane="' + p.key + '" aria-selected="' + (p.key === saved ? 'true' : 'false') + '">'
          + p.label + (p.count != null ? '<span class="n">' + p.count + '</span>' : '') + '</button>';
      }).join('') + '</div>';
      html += panes.map(function (p) { return '<div data-pane-body="' + p.key + '"' + (p.key === saved ? '' : ' style="display:none;"') + '>' + p.html + '</div>'; }).join('');
    }
    html += '</div>';
    container.innerHTML = html;

    var tabs = container.querySelectorAll('.kt-pay-tab');
    Array.prototype.forEach.call(tabs, function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-pane');
        try { sessionStorage.setItem('kt_pay_tab', key); } catch (e) {}
        Array.prototype.forEach.call(tabs, function (b) { b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
        Array.prototype.forEach.call(container.querySelectorAll('[data-pane-body]'), function (d) { d.style.display = d.getAttribute('data-pane-body') === key ? '' : 'none'; });
      });
    });
    var led = container.querySelector('[data-pay-ledger]');
    if (led) { paintLedger(led, docs, who); }

    // Issued documents: the last cell's plain buttons become the portal's ⋮ (kt-row-actions).
    container.querySelectorAll('span[data-doc-actions]').forEach(function (sp) {
      var td = sp.parentNode, d = docs.filter(function (x) { return String(x.id) === sp.getAttribute('data-doc-actions'); })[0];
      sp.remove();
      if (d && td) { docActions(d).forEach(function (b) { b.className = 'kt-pay-act'; td.appendChild(b); }); }
    });
    container.querySelectorAll('.kt-pay-dl[data-start]').forEach(function (b) {
      b.addEventListener('click', function () { downloadPdf(b.getAttribute('data-start'), b); });
    });
  }

  /* The hashes each role actually reaches this by — they differ, and they match the
     nav: screen-role-home.js and role-widgets.js send educators and directors to
     #my-hours, screen-home-visitor.js sends home visitors to #my-pay. */
  KT.Shell.registerScreen('educator:my-hours', render);
  KT.Shell.registerScreen('home_visitor:my-pay', render);
  KT.Shell.registerScreen('centre_director:my-hours', render);
})(window);
