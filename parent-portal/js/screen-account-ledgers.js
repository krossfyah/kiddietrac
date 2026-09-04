/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Account ledgers.
   Every account the agency has, with both directions of its money, and the
   full ledger behind each one.
   Hash: #account-ledgers  ·  agency_admin / platform_admin

   The finance screens each answered half the question: Accounting shows what
   families owe, Payroll shows what staff are paid, and nobody could ask "what is
   the position of this person, whatever their role". A parent who also works here
   has both, and until now they lived on screens that never met.

   TWO VIEWS, ONE SCREEN. The list is the agency's whole position; picking a row
   drills into that account IN PLACE, full width, with everything on the page at
   once. It used to open in a modal, which is the wrong container for a ledger —
   680px of dialog for a document people read across, with the detail hidden behind
   tabs. A ledger is scanned, not read top to bottom, so the shape of the money
   comes first (charts), then the summary, then every line.

   The two directions are NEVER netted. An educator's pay is not a credit against
   their child's fees — showing it as one would be wrong in every direction that
   matters — so "Owed" and "Paid out" stay separate columns and separate totals.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var state = {
    page: 1, per_page: 25, search: '', role: '', only: '',
    sort: 'outstanding', dir: 'desc', busy: false,
    viewing: null,            // user id when drilled into one account
    viewingName: null,        // carried from the row, so the banner has words at once
    histFilter: 'all'
  };

  // ── semantic colours. Separate from the screen's accent on purpose: these
  //    encode what a number MEANS, and must not drift with the brand. ──────
  var C = {
    ink: '#0F172A', muted: '#64748B', faint: '#94A3B8', line: '#EEF2F6', rule: '#E2E8F0',
    accent: '#2563EB', good: '#16A34A', warn: '#B45309', bad: '#B91C1C', out: '#4338CA'
  };

  function money(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(n); }
    catch (e) { return '$' + n.toFixed(2); }
  }
  function money0(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD', maximumFractionDigits: 0 }).format(n); }
    catch (e) { return '$' + Math.round(n); }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* TWO KINDS OF VALUE, and one of them must not go near a timezone.

     issued_at / due_at / due_date are DATE columns: a wall-clock day that means the
     same day everywhere. KT.Fmt.date parses "2026-08-05" as UTC midnight and then
     renders it in the agency zone — which names 4 August. So a bare date is reduced
     from its own numbers and never converted.

     Anything carrying a time is an instant stored in UTC and DOES belong in the
     agency's zone. It is converted to the agency's calendar day first, then formatted
     by the same path, so one format appears on the page. */
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function ymd(v) {
    if (!v) return null;
    var s = String(v);
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    try {
      var d = KT.Fmt.parse(s);
      if (!d) return s.slice(0, 10);
      var z = (KT.tz ? KT.tz() : null);
      // en-CA always formats as YYYY-MM-DD, which is what makes this round-trip safe.
      return d.toLocaleDateString('en-CA', z ? { timeZone: z } : undefined);
    } catch (e) { return s.slice(0, 10); }
  }
  function fmtDate(v) {
    var s = ymd(v);
    if (!s) return '—';
    var p = s.split('-');
    if (p.length !== 3 || !MON[Number(p[1]) - 1]) return s.slice(0, 10);

    return Number(p[2]) + ' ' + MON[Number(p[1]) - 1] + ' ' + p[0];
  }
  function fmtMonth(key) {                       // "2026-08" → "Aug 26"
    var p = String(key).split('-');
    return (MON[Number(p[1]) - 1] || '?') + ' ' + String(p[0]).slice(2);
  }

  /* A staff role is tinted, "Parent" is left plain and "Contractor" is marked
     differently again — the column exists for the person who is more than one
     thing, so the exception has to be what catches the eye. */
  function rolePills(roles) {
    if (!roles || !roles.length) return '<span style="color:' + C.faint + ';">—</span>';
    return roles.map(function (r) {
      var kind = r === 'Parent' ? 'parent' : (r === 'Contractor' ? 'contractor' : 'staff');
      var bg = kind === 'parent' ? '#F1F5F9' : (kind === 'contractor' ? '#FEF3C7' : '#EEF2FF');
      var fg = kind === 'parent' ? '#475569' : (kind === 'contractor' ? '#92400E' : '#4338CA');
      return chip(r, bg, fg);
    }).join('');
  }

  function chip(text, bg, fg) {
    return '<span style="display:inline-block;background:' + bg + ';color:' + fg
      + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin:1px 5px 1px 0;white-space:nowrap;">'
      + esc(text) + '</span>';
  }

  function statCard(label, value, tint) {
    return '<div class="kt-card" style="padding:16px 18px;">'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + C.muted + ';">' + esc(label) + '</div>'
      + '<div style="font-size:22px;font-weight:800;margin-top:6px;color:' + tint + ';">' + esc(value) + '</div>'
      + '</div>';
  }

  /* A KPI is a number AND its shape: a figure with no sub-line makes the reader work
     out for themselves whether it is good news. Every tile says what it means. */
  function kpi(label, value, tint, sub) {
    /* min-width:0 — a grid child defaults to min-width:auto, so anything wide inside
       widens the TRACK instead of scrolling in its own box. That is the bug that once
       widened the whole portal from one screen. */
    return '<div class="kt-card" style="min-width:0;padding:14px 16px;border-left:3px solid ' + tint + ';">'
      + '<div style="font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + C.muted + ';">' + esc(label) + '</div>'
      + '<div style="font-size:20px;font-weight:800;margin-top:5px;color:' + tint + ';font-variant-numeric:tabular-nums;">' + esc(value) + '</div>'
      + (sub ? '<div style="font-size:11.5px;color:' + C.muted + ';margin-top:3px;">' + sub + '</div>' : '')
      + '</div>';
  }

  function card(title, hint, inner) {
    // min-width:0 for the same reason as kpi() — these hold the wide tables.
    return '<div class="kt-card" style="min-width:0;padding:16px 18px;">'
      + '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;">'
      + '<h3 style="margin:0;font-size:14px;font-weight:800;color:' + C.ink + ';">' + esc(title) + '</h3>'
      + (hint ? '<span style="font-size:11.5px;color:' + C.muted + ';">' + hint + '</span>' : '')
      + '</div>' + inner + '</div>';
  }

  function empty(text) {
    return '<div style="padding:26px;text-align:center;color:' + C.faint + ';font-size:13px;">' + esc(text) + '</div>';
  }

  // ═════════════════════════════════════════════════════════════════════
  //  CHARTS — hand-rolled inline SVG, the house pattern. No library: this
  //  screen is not worth 200KB on a boot that already parses 162 files, and
  //  three chart types is less code than the loader would be.
  // ═════════════════════════════════════════════════════════════════════

  /** Balance over time. The one chart that answers "is this getting better or worse". */
  function balanceChart(entries) {
    var pts = [];
    for (var i = 0; i < entries.length; i++) {
      var e = entries[i];
      if (e.running_balance == null) continue;
      pts.push({ d: ymd(e.date), v: Number(e.running_balance) });
    }
    if (pts.length < 2) return empty('Not enough history yet to draw a balance line.');

    var W = 1000, H = 190, L = 8, R = 8, T = 14, B = 26;
    var vals = pts.map(function (p) { return p.v; });
    var max = Math.max.apply(null, vals.concat([0]));
    var min = Math.min.apply(null, vals.concat([0]));
    if (max === min) { max = min + 1; }
    var span = max - min;

    var x = function (i) { return L + (i * (W - L - R)) / Math.max(pts.length - 1, 1); };
    var y = function (v) { return T + (H - T - B) * (1 - (v - min) / span); };

    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ',' + y(p.v).toFixed(1); }).join(' ');
    var zeroY = y(0);
    var area = line + ' L' + x(pts.length - 1).toFixed(1) + ',' + zeroY.toFixed(1)
      + ' L' + x(0).toFixed(1) + ',' + zeroY.toFixed(1) + ' Z';

    var last = pts[pts.length - 1];
    var tint = last.v > 0.005 ? C.warn : C.good;

    return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" '
      + 'style="width:100%;height:190px;display:block;overflow:visible;">'
      + '<line x1="' + L + '" y1="' + zeroY.toFixed(1) + '" x2="' + (W - R) + '" y2="' + zeroY.toFixed(1)
      + '" stroke="' + C.rule + '" stroke-width="1"/>'
      + '<path d="' + area + '" fill="' + tint + '" opacity="0.10"/>'
      + '<path d="' + line + '" stroke="' + tint + '" stroke-width="2" fill="none" '
      + 'stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'
      /* The endpoint is emphasised because it is the number on the tile above —
         the chart's job is to show how the reader arrived at it. */
      + '<circle cx="' + x(pts.length - 1).toFixed(1) + '" cy="' + y(last.v).toFixed(1) + '" r="4" fill="' + tint + '"/>'
      + '</svg>'
      + '<div style="display:flex;justify-content:space-between;font-size:11px;color:' + C.muted + ';margin-top:6px;">'
      + '<span>' + fmtDate(pts[0].d) + '</span>'
      + '<span style="color:' + tint + ';font-weight:700;">' + money(last.v) + '</span>'
      + '<span>' + fmtDate(last.d) + '</span>'
      + '</div>';
  }

  /** Invoiced against received, by month. Where the gap opens up. */
  function monthlyChart(entries) {
    var by = {};
    entries.forEach(function (e) {
      if (e.direction !== 'owed') return;
      var k = (ymd(e.date) || '').slice(0, 7);
      if (!k) return;
      if (!by[k]) by[k] = { inv: 0, rec: 0 };
      by[k].inv += Number(e.debit) || 0;
      by[k].rec += Number(e.credit) || 0;
    });
    var keys = Object.keys(by).sort().slice(-12);
    if (!keys.length) return empty('No invoices or payments to chart.');

    var max = 0;
    keys.forEach(function (k) { max = Math.max(max, by[k].inv, by[k].rec); });
    if (max <= 0) max = 1;

    var H = 150;
    var bars = keys.map(function (k) {
      var m = by[k];
      var hi = Math.max((m.inv / max) * H, m.inv > 0 ? 2 : 0);
      var hr = Math.max((m.rec / max) * H, m.rec > 0 ? 2 : 0);
      return '<div style="flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;">'
        + '<div style="height:' + H + 'px;display:flex;align-items:flex-end;gap:3px;width:100%;justify-content:center;" '
        + 'title="' + esc(fmtMonth(k)) + ' — invoiced ' + money(m.inv) + ', received ' + money(m.rec) + '">'
        + '<div style="width:42%;max-width:16px;height:' + hi.toFixed(1) + 'px;background:' + C.accent + ';opacity:.85;border-radius:3px 3px 0 0;"></div>'
        + '<div style="width:42%;max-width:16px;height:' + hr.toFixed(1) + 'px;background:' + C.good + ';opacity:.85;border-radius:3px 3px 0 0;"></div>'
        + '</div>'
        + '<div style="font-size:10px;color:' + C.muted + ';white-space:nowrap;">' + esc(fmtMonth(k)) + '</div>'
        + '</div>';
    }).join('');

    return '<div style="display:flex;align-items:flex-end;gap:6px;border-bottom:1px solid ' + C.rule + ';padding-bottom:6px;">'
      + bars + '</div>'
      + '<div style="display:flex;gap:16px;margin-top:10px;font-size:11.5px;color:' + C.muted + ';">'
      + '<span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + C.accent + ';margin-right:5px;"></span>Invoiced</span>'
      + '<span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + C.good + ';margin-right:5px;"></span>Received</span>'
      + '</div>';
  }

  /** How old the unpaid money is. The chart that decides who gets chased. */
  function ageingChart(openItems) {
    var buckets = [
      { k: 'Not yet due', min: -1e9, max: 0, tint: C.muted },
      { k: '1–30 days', min: 1, max: 30, tint: '#CA8A04' },
      { k: '31–60 days', min: 31, max: 60, tint: C.warn },
      { k: '61–90 days', min: 61, max: 90, tint: '#C2410C' },
      { k: 'Over 90 days', min: 91, max: 1e9, tint: C.bad }
    ];
    buckets.forEach(function (b) { b.total = 0; b.n = 0; });
    (openItems || []).forEach(function (o) {
      var d = Number(o.days_overdue) || 0;
      for (var i = 0; i < buckets.length; i++) {
        if (d >= buckets[i].min && d <= buckets[i].max) {
          buckets[i].total += Number(o.outstanding) || 0;
          buckets[i].n++;
          break;
        }
      }
    });
    var max = Math.max.apply(null, buckets.map(function (b) { return b.total; }).concat([0]));
    if (max <= 0) return empty('Nothing outstanding to age.');

    return buckets.map(function (b) {
      var pct = (b.total / max) * 100;
      return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:7px;">'
        + '<div style="width:92px;flex:0 0 92px;font-size:11.5px;color:' + C.muted + ';">' + esc(b.k) + '</div>'
        + '<div style="flex:1 1 auto;background:#F1F5F9;border-radius:4px;height:16px;overflow:hidden;">'
        + '<div style="width:' + pct.toFixed(1) + '%;height:100%;background:' + b.tint + ';opacity:' + (b.total > 0 ? '.9' : '0') + ';border-radius:4px;"></div>'
        + '</div>'
        + '<div style="width:104px;flex:0 0 104px;text-align:right;font-size:12px;font-weight:700;color:'
        + (b.total > 0 ? C.ink : C.faint) + ';font-variant-numeric:tabular-nums;">' + money(b.total) + '</div>'
        + '<div style="width:34px;flex:0 0 34px;text-align:right;font-size:11px;color:' + C.faint + ';">'
        + (b.n || '') + '</div>'
        + '</div>';
    }).join('');
  }

  /** Where the billing went. A donut is overkill for four slices; a stacked rail reads faster. */
  function compositionBar(sm) {
    var parts = [
      { k: 'Collected', v: Number(sm.credited) || 0, tint: C.good },
      { k: 'Outstanding', v: Number(sm.balance) > 0 ? Number(sm.balance) : 0, tint: C.warn },
      { k: 'Voided', v: Number(sm.voided) || 0, tint: C.faint }
    ].filter(function (p) { return p.v > 0.005; });
    var total = parts.reduce(function (a, p) { return a + p.v; }, 0);
    if (total <= 0) return empty('Nothing billed on this account.');

    return '<div style="display:flex;height:22px;border-radius:6px;overflow:hidden;background:#F1F5F9;">'
      + parts.map(function (p) {
        return '<div title="' + esc(p.k) + ' ' + money(p.v) + '" style="width:' + ((p.v / total) * 100).toFixed(2)
          + '%;background:' + p.tint + ';"></div>';
      }).join('')
      + '</div>'
      + '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;font-size:11.5px;color:' + C.muted + ';">'
      + parts.map(function (p) {
        return '<span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + p.tint
          + ';margin-right:5px;"></span>' + esc(p.k) + ' <strong style="color:' + C.ink + ';">' + money0(p.v)
          + '</strong> · ' + Math.round((p.v / total) * 100) + '%</span>';
      }).join('')
      + '</div>';
  }

  // ═════════════════════════════════════════════════════════════════════
  //  TABLE HELPER
  // ═════════════════════════════════════════════════════════════════════
  function table(headers, rows, right, widths) {
    var thS = 'text-align:left;padding:8px 10px;font-size:10px;font-weight:800;color:' + C.muted
      + ';text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;background:#F8FAFC;';
    var tdS = 'padding:9px 10px;font-size:12.5px;color:#334155;border-top:1px solid ' + C.line + ';vertical-align:top;';
    return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">'
      + '<thead><tr>' + headers.map(function (h, i) {
        return '<th style="' + thS + (right[i] ? 'text-align:right;' : '')
          + (widths && widths[i] ? 'width:' + widths[i] + ';' : '') + '">' + h + '</th>';
      }).join('') + '</tr></thead><tbody>'
      + rows.map(function (cells) {
        return '<tr>' + cells.map(function (c, i) {
          return '<td style="' + tdS
            + (right[i] ? 'text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;' : '')
            + '">' + c + '</td>';
        }).join('') + '</tr>';
      }).join('')
      + '</tbody></table></div>';
  }

  // ═════════════════════════════════════════════════════════════════════
  //  SCREEN
  // ═════════════════════════════════════════════════════════════════════
  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', {});
    container.appendChild(wrap);

    if (state.viewing) {
      /* The list's auto table furniture must NOT follow us in here. data-kt-pretty
         gives every table on the container a filter box and a select column, which is
         right for one list and wrong for four tables on a page that brings its own
         controls — the history table ended up with two filters and a checkbox column
         no bulk action reads. The attribute is set on the container, not the table,
         so leaving the list does not clear it. */
      try { container.removeAttribute('data-kt-pretty'); } catch (e) {}

      /* The banner is painted NOW, not after the fetch. The shell adds a page header
         of its own to any screen that has no .kt-hero when it sweeps, so a view that
         renders its hero asynchronously gets a second banner above its first — which
         is exactly what happened here. The name comes from the row that was clicked,
         so there is something real on screen while the ledger loads. */
      wrap.innerHTML = accountHero(state.viewingName, null)
        + '<div id="al-detail"><div style="padding:40px;text-align:center;color:' + C.faint + ';">Loading ledger…</div></div>';
      var backEarly = wrap.querySelector('#al-back');
      if (backEarly) backEarly.addEventListener('click', function () { backToList(container); });

      loadAccount(container, wrap);
      return;
    }

    /* The house table treatment — card, sortable headers, filter bar, row count —
       comes from data-kt-pretty plus a plain table in a .kt-card. Only the LIST wants
       it; the account view brings its own controls and would end up with a search box
       over every one of its four tables. */
    try { container.setAttribute('data-kt-pretty', '1'); } catch (e) {}

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#155E75 60%,#0E7490 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💰 FINANCE</div><h1>Account ledgers</h1>'
      + '<div class="kt-hero-sub">Every account and its position — what each person owes, and what the agency has paid them.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div', { id: 'al-body' });
    wrap.appendChild(body);
    body.innerHTML = '<div style="padding:40px;text-align:center;color:' + C.faint + ';">Loading…</div>';

    load(container);
  }

  function load(container) {
    if (state.busy) return;
    state.busy = true;
    var body = container.querySelector('#al-body');
    var qs = '?page=' + state.page + '&per_page=' + state.per_page
      + '&sort=' + encodeURIComponent(state.sort) + '&dir=' + state.dir
      + (state.search ? '&search=' + encodeURIComponent(state.search) : '')
      + (state.role ? '&role=' + encodeURIComponent(state.role) : '')
      + (state.only ? '&only=' + state.only : '');

    Api.get('/admin/account-ledgers' + qs).then(function (d) {
      state.busy = false;
      if (!body || !body.isConnected) return;       // navigated away mid-fetch
      paint(container, body, d || {});
    }).catch(function (e) {
      state.busy = false;
      if (!body || !body.isConnected) return;
      body.innerHTML = '<div class="kt-card" style="padding:24px;color:' + C.bad + ';">Could not load the ledgers: '
        + esc((e && e.message) || 'error') + '</div>';
    });
  }

  function paint(container, body, d) {
    var accounts = d.accounts || [];
    var t = d.totals || {};
    var meta = d.meta || { page: 1, pages: 1, total: 0 };

    /* Billed − Outstanding = Collected, and Voided stands apart: an invoice issued
       and then withdrawn is history, neither revenue nor debt. */
    var stats = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin:14px 0 16px;">'
      + statCard('Accounts', String(t.accounts || 0), C.ink)
      + statCard('Billed', money(t.billed), C.ink)
      + statCard('Collected', money(t.paid), C.good)
      + statCard('Outstanding', money(t.outstanding), C.warn)
      + statCard('Voided', money(t.voided), C.faint)
      + statCard('Paid out', money(t.paid_out), C.out)
      + '</div>';

    var roleOpts = ['<option value="">All roles</option>'].concat((d.roles || []).map(function (r) {
      return '<option value="' + esc(r) + '"' + (state.role === r ? ' selected' : '') + '>' + esc(r) + '</option>';
    })).join('');

    var controls = '<div class="kt-card" style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      + '<input id="al-search" type="search" placeholder="Search name or email…" value="' + esc(state.search) + '" '
      + 'style="flex:1 1 240px;min-width:180px;padding:8px 12px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;">'
      + '<select id="al-role" style="padding:8px 10px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;">' + roleOpts + '</select>'
      + '<select id="al-only" style="padding:8px 10px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;">'
      + '<option value="">Everyone</option>'
      + '<option value="owing"' + (state.only === 'owing' ? ' selected' : '') + '>Owing money</option>'
      + '<option value="paid_out"' + (state.only === 'paid_out' ? ' selected' : '') + '>Paid by us</option>'
      + '</select>'
      + '<span style="margin-left:auto;font-size:12.5px;color:' + C.muted + ';">' + (meta.total || 0) + ' account(s)</span>'
      + '</div>';

    var th = 'text-align:left;padding:10px 12px;font-size:10.5px;font-weight:800;color:' + C.muted
      + ';text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
    var td = 'padding:10px 12px;font-size:13px;color:#334155;border-top:1px solid #F1F5F9;vertical-align:middle;';

    var cols = [
      { h: 'Account', k: 'name', a: '' },
      { h: 'Role', k: '', a: '' },
      { h: 'Agency', k: '', a: '' },
      { h: 'Status', k: '', a: '' },
      { h: 'Billed', k: 'billed', a: 'text-align:right;' },
      { h: 'Collected', k: 'paid', a: 'text-align:right;' },
      { h: 'Outstanding', k: 'outstanding', a: 'text-align:right;' },
      { h: 'Paid out', k: 'paid_out', a: 'text-align:right;' },
      { h: '', k: '', a: 'text-align:right;' }
    ];

    var head = cols.map(function (c) {
      var active = c.k && state.sort === c.k;
      var arrow = active ? (state.dir === 'desc' ? ' ▼' : ' ▲') : (c.k ? ' <span style="opacity:.28;">↕</span>' : '');
      return '<th data-sort="' + c.k + '" style="' + th + c.a + (c.k ? 'cursor:pointer;user-select:none;' : '')
        + (active ? 'color:' + C.accent + ';' : '') + '">' + c.h + arrow + '</th>';
    }).join('');

    var rows = accounts.map(function (a) {
      /* Plain labelled buttons in the LAST cell. kt-row-actions.js collapses them
         into the ⋮ kebab on desktop and leaves them as buttons on a phone — a menu
         built here would end up as a kebab inside a kebab.

         A contractor has no login, so there is no ledger to open and nowhere to send
         one. The row says so rather than offering buttons that cannot work. */
      var act = a.user_id
        ? '<button type="button" class="al-open" data-id="' + a.user_id + '" data-name="' + esc(a.name) + '" title="Open ledger">📄 Open ledger</button>'
          + '<button type="button" class="al-mail" data-id="' + a.user_id
            + '" data-name="' + esc(a.name) + '" data-email="' + esc(a.email || '')
            + '" title="Email this statement">✉️ Email statement</button>'
        : '<span style="color:' + C.faint + ';font-size:12px;">no account</span>';
      /* The name is the way in as well as the kebab — a row people intend to open
         should not make them find a menu first. */
      var nameCell = a.user_id
        ? '<button type="button" class="al-name" data-id="' + a.user_id + '" data-name="' + esc(a.name) + '" style="border:0;background:none;padding:0;'
          + 'font:inherit;font-weight:700;color:' + C.accent + ';cursor:pointer;text-align:left;">' + esc(a.name) + '</button>'
        : '<span style="font-weight:700;color:' + C.ink + ';">' + esc(a.name) + '</span>';

      return '<tr>'
        + '<td style="' + td + '">' + nameCell
        + (a.email ? '<div style="font-weight:400;font-size:11.5px;color:' + C.muted + ';">' + esc(a.email) + '</div>' : '')
        + '</td>'
        + '<td style="' + td + '">' + rolePills(a.roles) + '</td>'
        + '<td style="' + td + 'color:' + C.muted + ';">' + esc(a.agency || '—') + '</td>'
        + '<td style="' + td + 'color:' + C.muted + ';">' + esc(a.account || '—') + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;">' + money(a.billed) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:' + C.good + ';">' + money(a.paid) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:'
        + (Number(a.outstanding) > 0 ? C.warn : C.good) + ';">' + money(a.outstanding) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:' + C.out + ';">' + money(a.paid_out) + '</td>'
        + '<td style="' + td + 'text-align:right;white-space:nowrap;">' + act + '</td>'
        + '</tr>';
    }).join('') || '<tr><td colspan="9" style="' + td + 'text-align:center;color:' + C.faint + ';padding:34px;">No accounts match that.</td></tr>';

    var pager = (meta.pages > 1)
      ? '<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px;font-size:13px;color:#475569;">'
        + '<button id="al-prev"' + (meta.page <= 1 ? ' disabled' : '') + ' style="padding:6px 12px;border:1px solid ' + C.rule + ';border-radius:8px;background:#fff;cursor:pointer;">‹ Prev</button>'
        + '<span>Page ' + meta.page + ' of ' + meta.pages + '</span>'
        + '<button id="al-next"' + (meta.page >= meta.pages ? ' disabled' : '') + ' style="padding:6px 12px;border:1px solid ' + C.rule + ';border-radius:8px;background:#fff;cursor:pointer;">Next ›</button>'
        + '</div>'
      : '';

    body.innerHTML = stats + controls
      + '<div class="kt-card" style="padding:0;overflow:hidden;">'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:900px;">'
      + '<thead><tr style="background:#F8FAFC;">' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>'
      + pager + '</div>';

    wire(container, body);
  }

  function wire(container, body) {
    var s = body.querySelector('#al-search');
    if (s) {
      var t = null;
      s.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { state.search = s.value.trim(); state.page = 1; load(container); }, 280);
      });
    }
    var r = body.querySelector('#al-role');
    if (r) r.addEventListener('change', function () { state.role = r.value; state.page = 1; load(container); });
    var o = body.querySelector('#al-only');
    if (o) o.addEventListener('change', function () { state.only = o.value; state.page = 1; load(container); });

    var prev = body.querySelector('#al-prev');
    if (prev) prev.addEventListener('click', function () { if (state.page > 1) { state.page--; load(container); } });
    var next = body.querySelector('#al-next');
    if (next) next.addEventListener('click', function () { state.page++; load(container); });

    body.querySelectorAll('th[data-sort]').forEach(function (thEl) {
      var k = thEl.getAttribute('data-sort');
      if (!k) return;
      thEl.addEventListener('click', function () {
        if (state.sort === k) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
        else { state.sort = k; state.dir = 'desc'; }
        state.page = 1;
        load(container);
      });
    });

    body.querySelectorAll('.al-open, .al-name').forEach(function (b) {
      b.addEventListener('click', function () {
        openAccount(container, b.getAttribute('data-id'), b.getAttribute('data-name'));
      });
    });
    body.querySelectorAll('.al-mail').forEach(function (b) {
      b.addEventListener('click', function () {
        openEmailDialog(b.getAttribute('data-id'), b.getAttribute('data-name'), b.getAttribute('data-email'));
      });
    });

    /* Collapse the row buttons into the ⋮ NOW rather than on the next sweep. This
       screen re-renders in place on every filter, sort and page change, and until the
       sweep caught up the rows showed raw buttons where every other table shows a
       kebab. */
    try { if (KT.sweepRowActions) KT.sweepRowActions(); } catch (e) {}
  }

  // ═════════════════════════════════════════════════════════════════════
  //  ONE ACCOUNT, IN FULL
  // ═════════════════════════════════════════════════════════════════════
  /* align-self:flex-start — .kt-hero is a flex COLUMN, so a bare button stretches to
     the full width of the banner and reads as a title bar rather than a way back. */
  function accountHero(name, sub) {
    return '<div class="kt-hero" style="background:linear-gradient(135deg,#1F6080 0%,#155E75 60%,#0E7490 100%);">'
      + '<button type="button" id="al-back" style="align-self:flex-start;border:0;background:rgba(255,255,255,.16);color:#fff;'
      + 'padding:5px 12px;border-radius:999px;font-size:12px;font-weight:700;cursor:pointer;margin-bottom:10px;">‹ All accounts</button>'
      + '<h1 style="margin:0;">' + esc(name || 'Account ledger') + '</h1>'
      + '<div class="kt-hero-sub">' + esc(sub || 'Loading this account…') + '</div>'
      + '</div>';
  }

  function openAccount(container, userId, name) {
    state.viewing = userId;
    state.viewingName = name || null;
    state.histFilter = 'all';
    render(container);
    try { window.scrollTo(0, 0); } catch (e) {}
  }

  function backToList(container) {
    state.viewing = null;
    render(container);
  }

  function loadAccount(container, wrap) {
    var id = state.viewing;
    Api.get('/admin/account-ledgers/' + id).then(function (d) {
      if (!wrap.isConnected || state.viewing !== id) return;   // navigated on mid-fetch
      paintAccount(container, wrap, d || {});
    }).catch(function (e) {
      if (!wrap.isConnected) return;
      wrap.innerHTML = '<div class="kt-card" style="padding:24px;color:' + C.bad + ';">Could not load that ledger: '
        + esc((e && e.message) || 'error') + '</div>';
    });
  }

  var fitTop = null;                          // the account view's one resize handler

  var KIND_TINT = {
    invoice: ['#EEF2FF', '#4338CA'], receipt: ['#DCFCE7', '#166534'],
    refund: ['#FEF3C7', '#92400E'], void: ['#F1F5F9', '#64748B'],
    payroll: ['#EDE9FE', '#5B21B6'], payee_invoice: ['#EDE9FE', '#5B21B6']
  };

  var HIST_FILTERS = [
    { k: 'all', label: 'Everything' },
    { k: 'invoice', label: 'Invoices' },
    { k: 'receipt', label: 'Payments' },
    { k: 'refund', label: 'Refunds' },
    { k: 'void', label: 'Voids' },
    { k: 'paid_out', label: 'Paid out' }
  ];

  function paintAccount(container, wrap, d) {
    var a = d.account || {}, sm = d.summary || {};
    var openItems = d.open_items || [], upcoming = d.upcoming || [], entries = d.entries || [];
    var owed = entries.filter(function (e) { return e.direction === 'owed'; });
    var out = entries.filter(function (e) { return e.direction === 'paid_out'; });

    var bal = Number(sm.balance) || 0;
    var balWord = bal > 0.005 ? 'Balance outstanding' : (bal < -0.005 ? 'Credit on account' : 'Nothing outstanding');
    var balTint = bal > 0.005 ? C.warn : C.good;

    var contact = a.contact || {};

    // ── identity: everything we hold about how to reach them ──────────
    var idBits = [];
    if (contact.email) idBits.push(['Email', esc(contact.email)]);
    if (contact.phone) idBits.push(['Phone', esc(contact.phone)]);
    if ((contact.address || []).length) idBits.push(['Address', contact.address.map(esc).join('<br>')]);
    if ((a.children || []).length) {
      idBits.push(['Children', a.children.map(function (c) {
        return esc(c.name) + (c.status && c.status !== 'enrolled'
          ? ' <span style="color:' + C.muted + ';font-size:11px;">(' + esc(c.status) + ')</span>' : '');
      }).join('<br>')]);
    }
    if ((a.families || []).length) {
      idBits.push(['Family', a.families.map(function (f) {
        return esc(f.family_name || ('#' + f.id)) + (f.centre ? ' <span style="color:' + C.muted + ';font-size:11px;">· ' + esc(f.centre) + '</span>' : '');
      }).join('<br>')]);
    }
    idBits.push(['Account status', a.status && String(a.status).toLowerCase() !== 'active'
      ? chip(a.status, '#FEF3C7', '#92400E')
      : chip('Active', '#DCFCE7', '#166534')]);

    var identity = card('Account details', null,
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;">'
      + idBits.map(function (b) {
        return '<div><div style="font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:'
          + C.faint + ';margin-bottom:3px;">' + b[0] + '</div>'
          + '<div style="font-size:13px;color:#334155;line-height:1.6;">' + b[1] + '</div></div>';
      }).join('')
      + '</div>');

    // ── the position, then every figure behind it ─────────────────────
    var position = '<div class="kt-card" style="min-width:0;padding:18px 20px;border-left:4px solid ' + balTint + ';">'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + C.muted + ';">' + balWord + '</div>'
      + '<div style="font-size:34px;font-weight:800;color:' + balTint + ';margin-top:2px;font-variant-numeric:tabular-nums;">'
      + money(Math.abs(bal)) + '</div>'
      + '<div style="margin-top:8px;font-size:12.5px;color:#475569;line-height:1.8;">'
      + (Number(sm.overdue) > 0.005
        ? '<div style="color:' + C.bad + ';font-weight:700;">' + money(sm.overdue) + ' of this is past its due date.</div>' : '')
      + (sm.last_payment
        ? '<div>Last payment: <strong>' + money(sm.last_payment.amount) + '</strong> on ' + fmtDate(sm.last_payment.date) + '.</div>' : '')
      + (sm.next_due
        ? '<div>Next due: <strong>' + (sm.next_due.amount != null ? money(sm.next_due.amount) : 'amount set on issue')
          + '</strong> on ' + fmtDate(sm.next_due.date) + '.</div>' : '')
      + '<div style="color:' + C.faint + ';">As at ' + fmtDate(sm.as_at) + '.</div>'
      + '</div></div>';

    var tiles = [
      kpi('Invoiced', money(sm.billed), C.ink, owed.filter(function (e) { return e.kind === 'invoice'; }).length + ' invoice(s)'),
      kpi('Received', money(sm.credited), C.good, owed.filter(function (e) { return e.kind === 'receipt'; }).length + ' payment(s)'),
      kpi('Outstanding', money(bal > 0 ? bal : 0), C.warn, sm.open_count + ' still open'),
      kpi('Overdue', money(sm.overdue), Number(sm.overdue) > 0.005 ? C.bad : C.faint,
        Number(sm.overdue) > 0.005 ? 'past the due date' : 'nothing late')
    ];
    if (Number(sm.upcoming_total) > 0.005 || sm.upcoming_count) {
      tiles.push(kpi('Scheduled', money(sm.upcoming_total), C.accent, sm.upcoming_count + ' to come'));
    }
    if (Number(sm.refunded) > 0.005) tiles.push(kpi('Refunded', money(sm.refunded), C.warn, 'returned to them'));
    if (Number(sm.voided) > 0.005) tiles.push(kpi('Voided', money(sm.voided), C.faint, 'issued then withdrawn'));
    if (Number(sm.overpaid) > 0.005) {
      tiles.push(kpi('Overpaid', money(sm.overpaid), C.warn, 'received above what was billed'));
    }
    if (Number(sm.paid_out) > 0.005) tiles.push(kpi('Paid out', money(sm.paid_out), C.out, sm.payslips + ' document(s)'));

    var kpis = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(158px,1fr));gap:10px;">'
      + tiles.join('') + '</div>';

    /* An overpayment is the one figure here nobody can act on without being told what
       it means, so it says so rather than sitting as an unexplained tile. */
    var overpaidNote = Number(sm.overpaid) > 0.005
      ? '<div class="kt-card" style="padding:12px 16px;border-left:3px solid ' + C.warn + ';font-size:12.5px;color:#475569;line-height:1.6;">'
        + '<strong style="color:' + C.ink + ';">' + money(sm.overpaid) + ' more was received than was invoiced.</strong> '
        + 'Shown on the receipt lines below and never netted into the balance — whether that is a credit this '
        + 'account can draw on or a payment recorded twice at source is an accounting decision, not one this screen makes.'
        + '</div>'
      : '';

    // ── charts ────────────────────────────────────────────────────────
    var charts = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;">'
      + card('Balance over time', 'every charge and payment, in order', balanceChart(owed))
      + card('Invoiced against received', 'by month, last 12', monthlyChart(owed))
      + card('How old the unpaid money is', money(bal > 0 ? bal : 0) + ' outstanding', ageingChart(openItems))
      + card('Where the billing went', money(sm.billed) + ' invoiced', compositionBar(sm))
      + '</div>';

    // ── outstanding ───────────────────────────────────────────────────
    var openTable = openItems.length
      ? table(['Invoice', 'Issued', 'Due', 'Invoiced', 'Outstanding'], openItems.map(function (o) {
        var late = o.days_overdue > 0
          ? '<div style="color:' + C.bad + ';font-weight:700;font-size:11.5px;">' + o.days_overdue + ' days late</div>' : '';
        return [
          '<strong>' + esc(o.reference) + '</strong>'
            + '<div style="color:' + C.muted + ';font-size:11.5px;">' + esc(o.status) + '</div>',
          fmtDate(o.issued_at),
          fmtDate(o.due_at) + late,
          money(o.total),
          '<strong>' + money(o.outstanding) + '</strong>'
        ];
      }), [0, 0, 0, 1, 1], ['auto', '14%', '18%', '13%', '14%'])
      : empty('Nothing outstanding on this account.');

    // ── coming up ─────────────────────────────────────────────────────
    var upTable = upcoming.length
      ? table(['What', 'When', 'Amount'], upcoming.map(function (u) {
        return [
          esc(u.description) + '<div style="color:' + C.muted + ';font-size:11.5px;">' + esc(u.detail || '') + '</div>',
          u.overdue
            ? '<span style="color:' + C.bad + ';font-weight:700;">' + fmtDate(u.date) + '</span>'
              + '<div style="color:' + C.bad + ';font-size:11.5px;">missed</div>'
            : fmtDate(u.date) + '<div style="color:' + C.faint + ';font-size:11.5px;">in ' + u.days + ' day(s)</div>',
          u.amount != null ? '<strong>' + money(u.amount) + '</strong>' : '<span style="color:' + C.faint + ';">—</span>'
        ];
      }), [0, 0, 1], ['auto', '26%', '16%'])
      : empty('Nothing scheduled — no payment plan and no recurring billing on this account.');

    // ── paid out, only when there is any ──────────────────────────────
    var outTable = out.length
      ? card('Paid to this account', money(sm.paid_out) + ' across ' + sm.payslips + ' document(s)',
        '<div style="font-size:12px;color:' + C.muted + ';margin-bottom:8px;">'
        + 'Kept separate from the balance above — money paid to someone is not a credit against fees they owe.</div>'
        + table(['Date', 'Detail', 'Reference', 'Gross', 'Net'], out.map(function (e) {
          return [
            fmtDate(e.date),
            esc(e.description || ''),
            esc(e.reference || ''),
            e.gross != null ? money(e.gross) : '<span style="color:' + C.faint + ';">—</span>',
            '<strong style="color:' + C.out + ';">' + money(e.net || 0) + '</strong>'
          ];
        }), [0, 0, 0, 1, 1], ['15%', 'auto', '18%', '12%', '12%']))
      : '';

    /* The banner is already on the page; only its words were not known until now. */
    var h1 = wrap.querySelector('.kt-hero h1');
    if (h1) h1.textContent = a.name || '';
    var hsub = wrap.querySelector('.kt-hero .kt-hero-sub');
    if (hsub) {
      hsub.textContent = ((a.roles || []).join(' · ') || 'No role on file')
        + ' · ' + (a.agency || '') + ' · account #' + a.user_id;
    }

    var host = wrap.querySelector('#al-detail') || wrap;
    host.innerHTML = '<div style="display:grid;gap:12px;margin-top:14px;min-width:0;">'
      + '<div style="display:grid;grid-template-columns:minmax(260px,1fr) minmax(300px,2fr);gap:12px;" id="al-top">'
      + position + identity + '</div>'
      + kpis
      + overpaidNote
      + charts
      + card('Still outstanding', openItems.length + ' invoice(s)', openTable)
      + card('Coming up', upcoming.length + ' scheduled', upTable)
      + '<div id="al-hist" style="min-width:0;"></div>'
      + outTable
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;padding-bottom:8px;">'
      + '<button type="button" id="al-send" style="padding:9px 16px;border:1px solid ' + C.accent + ';background:' + C.accent + ';color:#fff;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">✉️ Email this statement</button>'
      + '<button type="button" id="al-pdf" style="padding:9px 16px;border:1px solid #CBD5E1;background:#fff;color:#334155;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">⤓ Download PDF</button>'
      + '</div>'
      + '</div>';

    /* The grid above collapses on anything narrow. Done here rather than in a media
       query because this screen ships no stylesheet of its own, and an inline style
       that a media query cannot reach is exactly the mobile trap to avoid.

       The previous listener is removed first. A handler left bound to a node a newer
       render already discarded is the shape of half the stale-timer bugs on this
       platform — it survives the screen it belongs to and fires forever. */
    var top = host.querySelector('#al-top');
    if (fitTop) { window.removeEventListener('resize', fitTop); }
    fitTop = function () {
      if (!top || !top.isConnected) { window.removeEventListener('resize', fitTop); return; }
      top.style.gridTemplateColumns = (window.innerWidth < 900) ? '1fr' : 'minmax(260px,1fr) minmax(300px,2fr)';
    };
    fitTop();
    window.addEventListener('resize', fitTop);

    drawHistory(host, entries);

    var send = host.querySelector('#al-send');
    if (send) send.addEventListener('click', function () { openEmailDialog(a.user_id, a.name, contact.email || a.email); });
    var pdf = host.querySelector('#al-pdf');
    if (pdf) pdf.addEventListener('click', function () { downloadStatementPdf(a.user_id, pdf); });
  }

  /* History is redrawn on its own so the filter does not rebuild the charts —
     re-running the whole account paint on every chip press would flash the page. */
  function drawHistory(wrap, entries) {
    var host = wrap.querySelector('#al-hist');
    if (!host) return;

    var f = state.histFilter;
    var rows = entries.filter(function (e) {
      if (f === 'all') return true;
      if (f === 'paid_out') return e.direction === 'paid_out';
      return e.kind === f;
    }).slice().reverse();                          // newest first, the way it is read

    var chips = HIST_FILTERS.map(function (x) {
      var on = state.histFilter === x.k;
      return '<button type="button" data-hf="' + x.k + '" style="border:1px solid '
        + (on ? C.accent : C.rule) + ';background:' + (on ? C.accent : '#fff') + ';color:' + (on ? '#fff' : '#475569')
        + ';padding:5px 11px;border-radius:999px;font-size:12px;font-weight:700;cursor:pointer;">' + x.label + '</button>';
    }).join('');

    var body = rows.length
      ? table(['Date', 'Type', 'Detail', 'Charge', 'Payment', 'Balance'], rows.map(function (e) {
        var tint = KIND_TINT[e.kind] || ['#F1F5F9', '#475569'];
        var charge, payment;
        if (e.kind === 'void') {
          charge = '<span style="color:' + C.faint + ';text-decoration:line-through;">' + money(e.original || 0) + '</span>';
          payment = '';
        } else if (e.direction === 'paid_out') {
          charge = '';
          payment = '<span style="color:' + C.out + ';">' + money(e.net || 0) + '</span>';
        } else {
          charge = Number(e.debit) > 0.005 ? money(e.debit) : '';
          payment = Number(e.credit) > 0.005 ? '<span style="color:' + C.good + ';">' + money(e.credit) + '</span>' : '';
        }
        return [
          '<span style="white-space:nowrap;color:' + C.muted + ';">' + fmtDate(e.date) + '</span>',
          chip(String(e.kind || '').replace('_', ' '), tint[0], tint[1]),
          esc(e.description || '')
            + (e.reference ? '<div style="color:' + C.faint + ';font-size:11px;">' + esc(e.reference) + '</div>' : '')
            + (e.note ? '<div style="color:' + C.muted + ';font-size:11.5px;margin-top:2px;">' + esc(e.note) + '</div>' : ''),
          charge,
          payment,
          e.running_balance != null
            ? '<strong>' + money(e.running_balance) + '</strong>'
            : '<span style="color:#CBD5E1;">—</span>'
        ];
      }), [0, 0, 0, 1, 1, 1], ['13%', '11%', 'auto', '12%', '12%', '13%'])
      : empty('Nothing matches that filter.');

    host.innerHTML = card('Account history',
      rows.length + ' of ' + entries.length + ' entries',
      '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">' + chips + '</div>'
      + '<div style="max-height:620px;overflow:auto;">' + body + '</div>');

    host.querySelectorAll('button[data-hf]').forEach(function (b) {
      b.addEventListener('click', function () {
        state.histFilter = b.getAttribute('data-hf');
        drawHistory(wrap, entries);
      });
    });
  }

  /* The PDF comes back as bytes, so it cannot go through Api (which parses every
     response as JSON). Token and active agency are attached by hand — the same shape
     the invoice CSV export uses. */
  function downloadStatementPdf(userId, btn) {
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var headers = { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token'), Accept: 'application/pdf' };
    var agencyId = sessionStorage.getItem('kt_active_agency_id');
    if (agencyId) headers['X-Active-Agency-Id'] = agencyId;

    var label = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = 'Preparing…'; }

    return fetch(base + '/admin/account-ledgers/' + userId + '/statement.pdf', { headers: headers })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        /* The filename the server chose — it carries the person and the date, which is
           what makes the file findable in a downloads folder months later. */
        var cd = r.headers.get('Content-Disposition') || '';
        var m = /filename="([^"]+)"/.exec(cd);
        return r.blob().then(function (b) { return { blob: b, name: m ? m[1] : 'account-statement.pdf' }; });
      })
      .then(function (f) {
        var url = URL.createObjectURL(f.blob);
        var el = document.createElement('a');
        el.href = url; el.download = f.name;
        document.body.appendChild(el); el.click();
        setTimeout(function () { URL.revokeObjectURL(url); el.remove(); }, 800);
      })
      .catch(function (e) {
        if (Dom.toast) Dom.toast('Could not build the PDF: ' + ((e && e.message) || 'error'), 'error');
      })
      .then(function () {
        if (btn) { btn.disabled = false; btn.textContent = label; }
      });
  }

  /* ── EMAILING IT OUT ────────────────────────────────────────────────
     This sends real mail to a real person, so the dialog states the address before
     anything happens and the button says what it does. The recipient is editable
     because a statement routinely goes to a bookkeeper rather than the parent — and
     every send is audited with the address it actually went to.

     The modal's own action contract: returning false keeps it open, throwing keeps
     it open AND toasts the reason. Both are used, because "you left the address
     blank" and "this agency has email switched off" are different answers and an
     admin who sees neither concludes the button is broken. */
  function openEmailDialog(userId, name, email) {
    var wrap = Dom.el('div', { style: 'width:100%;' });
    wrap.innerHTML =
      '<p style="margin:0 0 14px;font-size:13.5px;color:#334155;line-height:1.65;">'
      + 'Sends <strong>' + esc(name || 'this account') + '</strong>’s statement — the balance, what is '
      + 'outstanding and what is coming up — in your agency’s branding, with the full ledger attached as a PDF.</p>'
      + '<label for="al-to" style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:' + C.muted + ';margin-bottom:4px;">Send to</label>'
      + '<input id="al-to" type="email" value="' + esc(email || '') + '" placeholder="name@example.com" '
      + 'style="width:100%;padding:8px 12px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;box-sizing:border-box;">'
      + (email ? '' : '<div style="margin-top:5px;font-size:12px;color:' + C.warn + ';">This account has no email on file — enter one to send to.</div>')
      + '<label for="al-note" style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:' + C.muted + ';margin:14px 0 4px;">Add a note (optional)</label>'
      + '<textarea id="al-note" rows="3" placeholder="Appears at the top of the statement." '
      + 'style="width:100%;padding:8px 12px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;box-sizing:border-box;resize:vertical;font-family:inherit;"></textarea>'
      + '<label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;color:#334155;cursor:pointer;">'
      + '<input id="al-cc" type="checkbox" style="width:16px;height:16px;"> Copy me on it</label>'
      + '<div id="al-result" style="margin-top:12px;font-size:13px;"></div>';

    Shell.Modal.open({
      title: 'Email account statement',
      body: wrap,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Send statement',
          style: 'btn-primary',
          busyLabel: 'Sending…',
          handler: function () {
            var to = (wrap.querySelector('#al-to').value || '').trim();
            var res = wrap.querySelector('#al-result');
            if (!to) {
              res.innerHTML = '<span style="color:' + C.bad + ';">Enter an address to send to.</span>';
              return false;                            // keeps the dialog open
            }
            res.innerHTML = '<span style="color:' + C.muted + ';">Sending…</span>';

            return Api.post('/admin/account-ledgers/' + userId + '/email', {
              to: to,
              message: (wrap.querySelector('#al-note').value || '').trim(),
              copy_me: wrap.querySelector('#al-cc').checked
            }).then(function (r) {
              if (Dom.toast) Dom.toast('Statement emailed to ' + r.to);
              return true;                             // closes
            }).catch(function (e) {
              /* The agency's own email switch, a missing address and a refusing mail
                 server all arrive here, and the API says which. */
              var why = (e && e.data && e.data.reason) || (e && e.message) || 'It could not be sent.';
              if (wrap.isConnected) {
                res.innerHTML = '<span style="color:' + C.bad + ';">' + esc(why) + '</span>';
              }
              throw new Error(why);
            });
          }
        }
      ]
    });

    setTimeout(function () {
      var f = wrap.querySelector('#al-to');
      if (f) { f.focus(); f.select(); }
    }, 60);
  }

  // ── register ────────────────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin'].forEach(function (role) {
      Shell.registerScreen(role + ':account-ledgers', function (container) {
        state.page = 1;
        state.viewing = null;                    // always land on the list
        render(container);
      });
    });
  }
  KT.AccountLedgers = { render: render, open: openAccount };
})(window);
