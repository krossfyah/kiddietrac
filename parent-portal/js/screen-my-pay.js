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
      '@media(max-width:600px){.kt-pay-wrap{padding:14px 12px;}}';
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
      var blob = await r.blob(); var url = URL.createObjectURL(blob);
      window.open(url, '_blank');
      setTimeout(function () { URL.revokeObjectURL(url); }, 20000);
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
        '<button data-doc="' + d.id + '" class="kt-pay-dl">⬇ '
          + (d.kind === 'invoice' ? 'Invoice PDF' : 'Payslip PDF') + '</button>',
      ];
    });

    return payTable(['Document', 'Period', 'Units', 'Gross', 'Status', ''], body, [2, 3, 4, 5]);
  }

  // ── render ────────────────────────────────────────────────────────────────

  async function render(container) {
    ensureCss();
    container.innerHTML = '<div style="padding:24px;color:#64748B;">Loading pay…</div>';

    /* Both sources up front: the KPI strip is built from the issued documents, so it
       cannot be rendered after the weekly section the way it used to be appended. */
    var pay = null, docsRes = null, payout = null;
    try {
      var results = await Promise.all([
        Api.get('/me/payslips'),
        Api.get('/auth/me/payroll-documents').catch(function () { return null; }),
        /* Only to know WHETHER anything is on file. Never returns an account number —
           the endpoint answers with a hint at most. A failure here must not cost the
           reader their pay page, so it resolves to null and the prompt stays hidden. */
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

    var html = '<div class="kt-pay-wrap">'
      + '<div class="kt-pay-head kt-pay-headrow"><div><h2>💵 My pay</h2>'
      + '<div class="kt-pay-sub">Your rate is ' + esc(rateLine) + '</div></div>'
      /* Straight to the Payroll tab, not to Settings and good luck. The screen that
         shows what you are owed is where you think about how you are paid. */
      + '<a class="kt-pay-setup" href="#settings?tab=payroll">🏦 Payout details</a>'
      + '</div>';

    /* NOTHING ON FILE IS WORTH SAYING OUT LOUD. An educator can read a page of issued
       payslips without ever noticing there is nowhere to send the money — the amounts
       look right either way, and the omission only surfaces on payday. Asked for once,
       here, where pay is already on their mind. */
    if (payout && !payout.method) {
      html += '<div class="kt-pay-setupbar">'
        + '<span>💡 Your agency has no payout details for you yet — payroll will not know where to send your pay.</span>'
        + '<a href="#settings?tab=payroll">Add them →</a></div>';
    }

    if (!pay.rate_set) {
      html += '<div class="kt-pay-warn">⚠️ Your pay rate hasn\'t been set yet — amounts show as $0.00 until an administrator sets it.</div>';
    }

    /* THE KPI STRIP STAYS ABOVE THE TABS, and it is built from issued documents
       alone — "have I been paid" is answered by what the agency issued, never by the
       rolling calculation. It sits outside the tabs because it is the headline
       answer, and it keeps its own heading inside the Issued tab so the two can
       never be read as one running total. */
    if (docs.length) {
      html += kpiStrip(t, docsRes && docsRes.ytd_gross);
    }

    // ── the two halves, as panes ───────────────────────────────────────────
    var panes = [];

    if (docs.length) {
      panes.push({
        key: 'issued',
        label: '🧾 Issued',
        count: t.count,
        html: '<div class="kt-pay-sec">' + UI.card(
          '🧾 Issued documents',
          t.count + ' on record',
          docsTable(docs)
        ) + '</div>',
      });
    }

    // ── THIS WEEK ──────────────────────────────────────────────────────────
    /* Deliberately AFTER the issued documents and separately headed. It is a live
       calculation from logged hours, not a record of payment, and the same work
       appears in both — adding the two together would double count. */
    if (slips.length) {
      var chart = UI.bars(slips.slice().reverse().map(function (s) {
        return { label: shortDay(s.period_start), value: Number(s.gross) || 0 };
      }), { tint: C.accent, height: 128, emptyText: 'No pay activity in the last 12 weeks.' });

      /* The chart travels WITH the weekly statements — it is drawn from those exact
         rows, so separating them would put a picture of one thing beside a table of
         another. */
      var weeklyHtml = '<div class="kt-pay-sec">' + UI.card(
        '📈 Earned by week',
        money(totGross) + ' over ' + slips.length + (slips.length === 1 ? ' week' : ' weeks')
          + ' · ' + (Math.round(totUnits * 100) / 100) + ' ' + esc(unit),
        chart
      ) + '</div>';

      weeklyHtml += '<div class="kt-pay-sec">' + UI.card(
        '🗓 Weekly statements',
        'Calculated from your logged ' + esc(unit) + ' — not yet a payment',
        payTable(
          ['Week', esc(unit.charAt(0).toUpperCase() + unit.slice(1)), 'Rate', 'Gross', ''],
          slips.map(function (s) {
            return [
              '<strong style="color:#0D1B2A;">' + esc(fmt(s.period_start)) + ' – ' + esc(fmt(s.period_end)) + '</strong>',
              esc(String(s.units)),
              money(s.rate) + '<span style="color:#94A3B8;">/' + perLabel + '</span>',
              '<strong style="color:#0D1B2A;">' + money(s.gross) + '</strong>',
              '<button data-start="' + esc(s.period_start) + '" class="kt-pay-dl">⬇ Payslip PDF</button>',
            ];
          }),
          [1, 2, 3, 4]
        )
      ) + '</div>';

      panes.push({ key: 'weekly', label: '🗓 Weekly', count: slips.length, html: weeklyHtml });
    }

    if (!panes.length) {
      html += UI.empty('No pay activity in the last 12 weeks, and nothing issued yet.');
    } else if (panes.length === 1) {
      /* One pane is not a choice. A tab bar with a single tab is furniture that asks
         to be clicked and does nothing. */
      html += panes[0].html;
    } else {
      html += '<div class="kt-pay-tabs" role="tablist">'
        + panes.map(function (p, i) {
            return '<button type="button" class="kt-pay-tab" role="tab" data-pane="' + p.key + '"'
              + ' aria-selected="' + (i === 0 ? 'true' : 'false') + '">'
              + p.label + '<span class="n">' + p.count + '</span></button>';
          }).join('')
        + '</div>';
      html += panes.map(function (p, i) {
        return '<div data-pane-body="' + p.key + '"' + (i === 0 ? '' : ' style="display:none;"') + '>'
          + p.html + '</div>';
      }).join('');
    }

    html += '</div>';
    container.innerHTML = html;

    /* Tab switching. Both panes are in the DOM from the start — the data is already
       loaded, so building them lazily would buy nothing and lose the download buttons
       wired below. */
    var tabs = container.querySelectorAll('.kt-pay-tab');
    Array.prototype.forEach.call(tabs, function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-pane');
        Array.prototype.forEach.call(tabs, function (b) {
          b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
        });
        Array.prototype.forEach.call(container.querySelectorAll('[data-pane-body]'), function (d) {
          d.style.display = d.getAttribute('data-pane-body') === key ? '' : 'none';
        });
      });
    });

    container.querySelectorAll('.kt-pay-dl[data-start]').forEach(function (b) {
      b.addEventListener('click', function () { downloadPdf(b.getAttribute('data-start'), b); });
    });
    container.querySelectorAll('.kt-pay-dl[data-doc]').forEach(function (b) {
      b.addEventListener('click', function () { openDoc(b.getAttribute('data-doc'), b); });
    });
  }

  /* The hashes each role actually reaches this by — they differ, and they match the
     nav: screen-role-home.js and role-widgets.js send educators and directors to
     #my-hours, screen-home-visitor.js sends home visitors to #my-pay. */
  KT.Shell.registerScreen('educator:my-hours', render);
  KT.Shell.registerScreen('home_visitor:my-pay', render);
  KT.Shell.registerScreen('centre_director:my-hours', render);
})(window);
