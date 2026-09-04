/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Account ledgers.
   Every account the agency has, with both directions of its money, and the
   statement behind each one.
   Hash: #account-ledgers  ·  agency_admin / platform_admin

   The finance screens each answered half the question: Accounting shows what
   families owe, Payroll shows what staff are paid, and nobody could ask "what is
   the position of this person, whatever their role". A parent who also works here
   has both, and until now they lived on screens that never met.

   The two directions are NEVER netted. An educator's pay is not a credit against
   their child's fees — showing it as one would be wrong in every direction that
   matters — so "Owed" and "Paid out" stay separate columns and separate totals.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var state = { page: 1, per_page: 25, search: '', role: '', only: '', sort: 'outstanding', dir: 'desc', busy: false };

  function money(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(n); }
    catch (e) { return '$' + n.toFixed(2); }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* TWO KINDS OF VALUE, and one of them must not go near a timezone.

     issued_at / due_at / due_date are DATE columns: a wall-clock day that means the
     same day everywhere. KT.Fmt.date parses "2026-08-05" as UTC midnight and then
     renders it in the agency zone — which names 4 August. So a bare date is built
     from its own numbers and never converted.

     Anything carrying a time is an instant stored in UTC and DOES belong in the
     agency's zone, which is exactly what KT.Fmt.date is for. */
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  /* An instant is first reduced to the agency's calendar DAY, then handed to the
     same formatter as a date column. Letting toLocaleDateString render the whole
     label instead put "Aug 31, 2026" (en-CA, month first) directly above
     "11 Sep 2026" on the same panel — both correct, neither consistent. */
  function fmtDate(v) {
    if (!v) return '—';
    var s = String(v);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) {
      try {
        var d = KT.Fmt.parse(s);
        if (!d) return s.slice(0, 10);
        var z = (KT.tz ? KT.tz() : null);
        // en-CA always formats as YYYY-MM-DD, which is what makes this round-trip safe.
        s = d.toLocaleDateString('en-CA', z ? { timeZone: z } : undefined);
      } catch (e) { return s.slice(0, 10); }
    }
    var p = s.split('-');
    if (p.length !== 3 || !MON[Number(p[1]) - 1]) return s.slice(0, 10);

    return Number(p[2]) + ' ' + MON[Number(p[1]) - 1] + ' ' + p[0];
  }

  /* A staff role is tinted, "Parent" is left plain and "Contractor" is marked
     differently again — the column exists for the person who is more than one
     thing, so the exception has to be what catches the eye. */
  function rolePills(roles) {
    if (!roles || !roles.length) return '<span style="color:#94A3B8;">—</span>';
    return roles.map(function (r) {
      var kind = r === 'Parent' ? 'parent' : (r === 'Contractor' ? 'contractor' : 'staff');
      var bg = kind === 'parent' ? '#F1F5F9' : (kind === 'contractor' ? '#FEF3C7' : '#EEF2FF');
      var fg = kind === 'parent' ? '#475569' : (kind === 'contractor' ? '#92400E' : '#4338CA');
      return '<span style="display:inline-block;background:' + bg + ';color:' + fg
        + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin:1px 3px 1px 0;white-space:nowrap;">'
        + esc(r) + '</span>';
    }).join('');
  }

  function chip(text, bg, fg) {
    return '<span style="display:inline-block;background:' + bg + ';color:' + fg
      + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin:0 5px 0 0;white-space:nowrap;">'
      + esc(text) + '</span>';
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
        var a = document.createElement('a');
        a.href = url; a.download = f.name;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 800);
      })
      .catch(function (e) {
        if (Dom.toast) Dom.toast('Could not build the PDF: ' + ((e && e.message) || 'error'), 'error');
      })
      .then(function () {
        if (btn) { btn.disabled = false; btn.textContent = label; }
      });
  }

  function statCard(label, value, tint) {
    return '<div class="kt-card" style="padding:16px 18px;">'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;">' + esc(label) + '</div>'
      + '<div style="font-size:22px;font-weight:800;margin-top:6px;color:' + tint + ';">' + esc(value) + '</div>'
      + '</div>';
  }

  function render(container) {
    Dom.clear(container);
    /* The house table treatment — card, sortable headers, filter bar, row count —
       comes from data-kt-pretty plus a plain table in a .kt-card. */
    try { container.setAttribute('data-kt-pretty', '1'); } catch (e) {}

    var wrap = Dom.el('div', {});
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#155E75 60%,#0E7490 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💰 FINANCE</div><h1>Account ledgers</h1>'
      + '<div class="kt-hero-sub">Every account and its position — what each person owes, and what the agency has paid them.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div', { id: 'al-body' });
    wrap.appendChild(body);
    body.innerHTML = '<div style="padding:40px;text-align:center;color:#94A3B8;">Loading…</div>';

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
      body.innerHTML = '<div class="kt-card" style="padding:24px;color:#B91C1C;">Could not load the ledgers: '
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
      + statCard('Accounts', String(t.accounts || 0), '#0F172A')
      + statCard('Billed', money(t.billed), '#0F172A')
      + statCard('Collected', money(t.paid), '#16A34A')
      + statCard('Outstanding', money(t.outstanding), '#B45309')
      + statCard('Voided', money(t.voided), '#94A3B8')
      + statCard('Paid out', money(t.paid_out), '#4338CA')
      + '</div>';

    var roleOpts = ['<option value="">All roles</option>'].concat((d.roles || []).map(function (r) {
      return '<option value="' + esc(r) + '"' + (state.role === r ? ' selected' : '') + '>' + esc(r) + '</option>';
    })).join('');

    var controls = '<div class="kt-card" style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">'
      + '<input id="al-search" type="search" placeholder="Search name or email…" value="' + esc(state.search) + '" '
      + 'style="flex:1 1 240px;min-width:180px;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">'
      + '<select id="al-role" style="padding:8px 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">' + roleOpts + '</select>'
      + '<select id="al-only" style="padding:8px 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">'
      + '<option value="">Everyone</option>'
      + '<option value="owing"' + (state.only === 'owing' ? ' selected' : '') + '>Owing money</option>'
      + '<option value="paid_out"' + (state.only === 'paid_out' ? ' selected' : '') + '>Paid by us</option>'
      + '</select>'
      + '<span style="margin-left:auto;font-size:12.5px;color:#64748B;">' + (meta.total || 0) + ' account(s)</span>'
      + '</div>';

    var th = 'text-align:left;padding:10px 12px;font-size:10.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
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
        + (active ? 'color:#2563EB;' : '') + '">' + c.h + arrow + '</th>';
    }).join('');

    var rows = accounts.map(function (a) {
      /* Plain labelled buttons in the LAST cell. kt-row-actions.js collapses them
         into the ⋮ kebab on desktop and leaves them as buttons on a phone — a menu
         built here would end up as a kebab inside a kebab.

         A contractor has no login, so there is no statement to open and nowhere to
         send one. The row says so rather than offering buttons that cannot work. */
      var act = a.user_id
        ? '<button type="button" class="al-open" data-id="' + a.user_id + '" title="Open statement">📄 View statement</button>'
          + '<button type="button" class="al-mail" data-id="' + a.user_id
            + '" data-name="' + esc(a.name) + '" data-email="' + esc(a.email || '')
            + '" title="Email this statement">✉️ Email statement</button>'
        : '<span style="color:#94A3B8;font-size:12px;">no account</span>';
      return '<tr>'
        + '<td style="' + td + 'font-weight:700;color:#0F172A;">' + esc(a.name)
        + (a.email ? '<div style="font-weight:400;font-size:11.5px;color:#64748B;">' + esc(a.email) + '</div>' : '')
        + '</td>'
        + '<td style="' + td + '">' + rolePills(a.roles) + '</td>'
        + '<td style="' + td + 'color:#64748B;">' + esc(a.agency || '—') + '</td>'
        + '<td style="' + td + 'color:#64748B;">' + esc(a.account || '—') + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;">' + money(a.billed) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:#16A34A;">' + money(a.paid) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:'
        + (Number(a.outstanding) > 0 ? '#B45309' : '#16A34A') + ';">' + money(a.outstanding) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:#4338CA;">' + money(a.paid_out) + '</td>'
        + '<td style="' + td + 'text-align:right;white-space:nowrap;">' + act + '</td>'
        + '</tr>';
    }).join('') || '<tr><td colspan="9" style="' + td + 'text-align:center;color:#94A3B8;padding:34px;">No accounts match that.</td></tr>';

    var pager = (meta.pages > 1)
      ? '<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px;font-size:13px;color:#475569;">'
        + '<button id="al-prev"' + (meta.page <= 1 ? ' disabled' : '') + ' style="padding:6px 12px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:pointer;">‹ Prev</button>'
        + '<span>Page ' + meta.page + ' of ' + meta.pages + '</span>'
        + '<button id="al-next"' + (meta.page >= meta.pages ? ' disabled' : '') + ' style="padding:6px 12px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:pointer;">Next ›</button>'
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

    body.querySelectorAll('.al-open').forEach(function (b) {
      b.addEventListener('click', function () { openStatement(b.getAttribute('data-id')); });
    });
    body.querySelectorAll('.al-mail').forEach(function (b) {
      b.addEventListener('click', function () {
        openEmailDialog(b.getAttribute('data-id'), b.getAttribute('data-name'), b.getAttribute('data-email'));
      });
    });

    /* Collapse the row buttons into the ⋮ NOW rather than on the next sweep. This
       screen re-renders in place on every filter, sort and page change, and until the
       sweep caught up the rows showed two raw buttons where every other table shows a
       kebab. */
    try { if (KT.sweepRowActions) KT.sweepRowActions(); } catch (e) {}
  }

  /* ── THE STATEMENT ──────────────────────────────────────────────────
     Three questions, three panes, because they are asked at different moments:
     what is owed right now, what is coming, and how the account got here. Opening
     on whichever of them has something to say beats opening on an empty one. */
  var TABS = [
    { k: 'open', label: 'Outstanding' },
    { k: 'upcoming', label: 'Coming up' },
    { k: 'history', label: 'History' }
  ];

  function openStatement(userId) {
    var body = Dom.el('div', { style: 'width:100%;' });
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#94A3B8;">Loading statement…</div>';
    Shell.Modal.open({ title: 'Account statement', body: body, large: true, actions: [{ label: 'Close' }] });

    Api.get('/admin/account-ledgers/' + userId).then(function (d) {
      if (!body.isConnected) return;               // closed while it was loading
      paintStatement(body, d || {}, userId);
    }).catch(function (e) {
      if (!body.isConnected) return;
      body.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load that statement: '
        + esc((e && e.message) || 'error') + '</div>';
    });
  }

  function paintStatement(body, d, userId) {
    var a = d.account || {}, sm = d.summary || {};
    var openItems = d.open_items || [], upcoming = d.upcoming || [], entries = d.entries || [];
    var tab = openItems.length ? 'open' : (upcoming.length ? 'upcoming' : 'history');

    var bal = Number(sm.balance) || 0;
    var balWord = bal > 0.005 ? 'Balance outstanding' : (bal < -0.005 ? 'Credit on account' : 'Nothing outstanding');
    var balTint = bal > 0.005 ? '#B45309' : '#16A34A';

    var who = '<div style="margin-bottom:14px;">'
      + '<div style="font-size:17px;font-weight:800;color:#0F172A;">' + esc(a.name || '') + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">'
      + esc(a.email || 'no email on file') + ' · ' + esc(a.agency || '') + '</div>'
      + '<div style="margin-top:7px;">' + rolePills(a.roles)
      + (a.status && a.status !== 'active' ? chip(a.status, '#FEE2E2', '#B91C1C') : '')
      + '</div>'
      + ((a.children || []).length
        ? '<div style="margin-top:7px;font-size:12.5px;color:#475569;">Children: '
          + (a.children || []).map(function (c) { return esc(c.name); }).join(', ') + '</div>'
        : '')
      + '</div>';

    /* One number is the answer and the rest is context, so they are not given equal
       weight — the tile grid that suits the list would bury the balance here. */
    var position = '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;margin-bottom:14px;">'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;">' + balWord + '</div>'
      + '<div style="font-size:30px;font-weight:800;color:' + balTint + ';margin-top:2px;font-variant-numeric:tabular-nums;">'
      + money(Math.abs(bal)) + '</div>'
      + '<div style="margin-top:9px;font-size:12.5px;color:#475569;line-height:1.75;">'
      + (Number(sm.overdue) > 0.005
        ? '<div style="color:#B91C1C;font-weight:700;">' + money(sm.overdue) + ' of this is past its due date.</div>' : '')
      + (sm.last_payment
        ? '<div>Last payment: <strong>' + money(sm.last_payment.amount) + '</strong> on '
          + fmtDate(sm.last_payment.date) + '.</div>' : '')
      + (sm.next_due
        ? '<div>Next due: <strong>' + (sm.next_due.amount != null ? money(sm.next_due.amount) : 'amount set on issue')
          + '</strong> on ' + fmtDate(sm.next_due.date) + '.</div>' : '')
      + '</div></div>';

    var tiles = [
      ['Invoiced', money(sm.billed), '#0F172A'],
      ['Received', money(sm.credited), '#16A34A'],
      ['Balance', money(sm.balance), balTint]
    ];
    if (Number(sm.refunded) > 0.005) tiles.push(['Refunded', money(sm.refunded), '#B45309']);
    if (Number(sm.voided) > 0.005) tiles.push(['Voided', money(sm.voided), '#94A3B8']);
    if (Number(sm.overpaid) > 0.005) tiles.push(['Overpaid', money(sm.overpaid), '#B45309']);
    if (Number(sm.paid_out) > 0.005) tiles.push(['Paid out', money(sm.paid_out), '#4338CA']);

    var grid = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:8px;margin-bottom:14px;">'
      + tiles.map(function (t) {
        return '<div style="border:1px solid #EEF2F6;border-radius:10px;padding:10px 12px;">'
          + '<div style="font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;">' + t[0] + '</div>'
          + '<div style="font-size:15px;font-weight:800;color:' + t[2] + ';margin-top:3px;font-variant-numeric:tabular-nums;">' + t[1] + '</div>'
          + '</div>';
      }).join('')
      + '</div>';

    var counts = { open: openItems.length, upcoming: upcoming.length, history: entries.length };

    function tabBar(active) {
      return '<div style="display:flex;gap:4px;border-bottom:1px solid #E2E8F0;margin-bottom:10px;flex-wrap:wrap;">'
        + TABS.map(function (t) {
          var on = t.k === active;
          return '<button type="button" data-tab="' + t.k + '" style="border:none;background:none;cursor:pointer;'
            + 'padding:8px 12px;font-size:12.5px;font-weight:700;border-bottom:2px solid '
            + (on ? '#2563EB' : 'transparent') + ';color:' + (on ? '#2563EB' : '#64748B') + ';">'
            + t.label + ' <span style="opacity:.55;font-weight:600;">' + counts[t.k] + '</span></button>';
        }).join('')
        + '</div>';
    }

    function note(text) {
      return '<div style="padding:26px;text-align:center;color:#94A3B8;font-size:13px;">' + esc(text) + '</div>';
    }

    function table(headers, rows, rightAligned, widths) {
      var thS = 'text-align:left;padding:7px 8px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
      var tdS = 'padding:9px 8px;font-size:12.5px;color:#334155;border-top:1px solid #F1F5F9;vertical-align:top;';
      /* table-layout:fixed with explicit widths — the description column is the one
         that must wrap, and without this the amounts get squeezed off the edge. */
      return '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">'
        + '<thead><tr style="background:#F8FAFC;">'
        + headers.map(function (h, i) {
          return '<th style="' + thS + (rightAligned[i] ? 'text-align:right;' : '')
            + (widths && widths[i] ? 'width:' + widths[i] + ';' : '') + '">' + h + '</th>';
        }).join('')
        + '</tr></thead><tbody>'
        + rows.map(function (cells) {
          return '<tr>' + cells.map(function (c, i) {
            return '<td style="' + tdS
              + (rightAligned[i] ? 'text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;' : 'word-break:break-word;')
              + '">' + c + '</td>';
          }).join('') + '</tr>';
        }).join('')
        + '</tbody></table>';
    }

    function paneOpen() {
      if (!openItems.length) { return note('Nothing outstanding on this account.'); }
      return table(['Invoice', 'Due', 'Amount'], openItems.map(function (o) {
        var late = o.days_overdue > 0
          ? '<div style="color:#B91C1C;font-weight:700;font-size:11.5px;">' + o.days_overdue + ' days late</div>' : '';
        return [
          '<strong>' + esc(o.reference) + '</strong>'
            + '<div style="color:#64748B;font-size:11.5px;">issued ' + fmtDate(o.issued_at) + ' · ' + esc(o.status) + '</div>',
          fmtDate(o.due_at) + late,
          '<strong>' + money(o.outstanding) + '</strong>'
            + (Math.abs(Number(o.total) - Number(o.outstanding)) > 0.005
              ? '<div style="color:#64748B;font-size:11.5px;">of ' + money(o.total) + '</div>' : '')
        ];
      }), [0, 0, 1], ['auto', '32%', '22%']);
    }

    function paneUpcoming() {
      if (!upcoming.length) {
        return note('Nothing scheduled — this account has no payment plan and no recurring billing.');
      }
      return table(['What', 'When', 'Amount'], upcoming.map(function (u) {
        return [
          esc(u.description) + '<div style="color:#64748B;font-size:11.5px;">' + esc(u.detail || '') + '</div>',
          u.overdue
            ? '<span style="color:#B91C1C;font-weight:700;">' + fmtDate(u.date) + '</span>'
              + '<div style="color:#B91C1C;font-size:11.5px;">missed</div>'
            : fmtDate(u.date) + '<div style="color:#94A3B8;font-size:11.5px;">in ' + u.days + ' day(s)</div>',
          u.amount != null ? '<strong>' + money(u.amount) + '</strong>' : '<span style="color:#94A3B8;">—</span>'
        ];
      }), [0, 0, 1], ['auto', '30%', '20%']);
    }

    var KIND_TINT = {
      invoice: ['#EEF2FF', '#4338CA'], receipt: ['#DCFCE7', '#166534'],
      refund: ['#FEF3C7', '#92400E'], void: ['#F1F5F9', '#64748B'],
      payroll: ['#EDE9FE', '#5B21B6'], payee_invoice: ['#EDE9FE', '#5B21B6']
    };

    function paneHistory() {
      if (!entries.length) { return note('Nothing on this account yet.'); }
      var items = entries.slice().reverse();          // newest first, the way it is read
      return table(['Date', 'Detail', 'Amount', 'Balance'], items.map(function (e) {
        var amount;
        if (e.kind === 'void') {
          amount = '<span style="color:#94A3B8;text-decoration:line-through;">' + money(e.original || 0) + '</span>';
        } else if (Number(e.credit) > 0.005) {
          amount = '<span style="color:#16A34A;">−' + money(e.credit) + '</span>';
        } else if (Number(e.debit) > 0.005) {
          amount = money(e.debit);
        } else if (e.direction === 'paid_out') {
          amount = '<span style="color:#4338CA;">' + money(e.net || 0) + '</span>';
        } else {
          amount = '<span style="color:#94A3B8;">—</span>';
        }
        var tint = KIND_TINT[e.kind] || ['#F1F5F9', '#475569'];
        return [
          '<span style="color:#64748B;">' + fmtDate(e.date) + '</span>',
          chip(String(e.kind || '').replace('_', ' '), tint[0], tint[1]) + esc(e.description || '')
            + (e.note ? '<div style="color:#64748B;font-size:11.5px;margin-top:3px;">' + esc(e.note) + '</div>' : ''),
          amount,
          e.running_balance != null
            ? '<strong>' + money(e.running_balance) + '</strong>'
            : '<span style="color:#CBD5E1;">—</span>'
        ];
      }), [0, 0, 1, 1], ['15%', 'auto', '17%', '17%']);
    }

    function draw(active) {
      var pane = active === 'open' ? paneOpen() : (active === 'upcoming' ? paneUpcoming() : paneHistory());
      body.innerHTML = who + position + grid + tabBar(active)
        + '<div style="max-height:44vh;overflow:auto;">' + pane + '</div>'
        + '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #F1F5F9;font-size:11.5px;color:#94A3B8;line-height:1.6;">'
        + 'Money paid BY the agency is listed on the account but never nets against fees owed — '
        + 'the balance tracks only what is owed. Statement as at ' + fmtDate(sm.as_at) + '.'
        + '</div>'
        + '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" id="al-send" style="padding:8px 14px;border:1px solid #2563EB;background:#2563EB;color:#fff;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">✉️ Email this statement</button>'
        + '<button type="button" id="al-pdf" style="padding:8px 14px;border:1px solid #CBD5E1;background:#fff;color:#334155;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">⤓ Download PDF</button>'
        + '</div>';

      body.querySelectorAll('button[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { draw(b.getAttribute('data-tab')); });
      });
      var send = body.querySelector('#al-send');
      if (send) send.addEventListener('click', function () { openEmailDialog(userId, a.name, a.email); });
      var pdf = body.querySelector('#al-pdf');
      if (pdf) pdf.addEventListener('click', function () { downloadStatementPdf(userId, pdf); });
    }

    draw(tab);
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
      + 'Sends <strong>' + esc(name || 'this account') + '</strong>’s full statement — balance, '
      + 'outstanding invoices, what is coming up and the account history — in your agency’s branding, '
      + 'with a PDF copy attached.</p>'
      + '<label for="al-to" style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#64748B;margin-bottom:4px;">Send to</label>'
      + '<input id="al-to" type="email" value="' + esc(email || '') + '" placeholder="name@example.com" '
      + 'style="width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;box-sizing:border-box;">'
      + (email ? '' : '<div style="margin-top:5px;font-size:12px;color:#B45309;">This account has no email on file — enter one to send to.</div>')
      + '<label for="al-note" style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#64748B;margin:14px 0 4px;">Add a note (optional)</label>'
      + '<textarea id="al-note" rows="3" placeholder="Appears at the top of the statement." '
      + 'style="width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;box-sizing:border-box;resize:vertical;font-family:inherit;"></textarea>'
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
            var out = wrap.querySelector('#al-result');
            if (!to) {
              out.innerHTML = '<span style="color:#B91C1C;">Enter an address to send to.</span>';
              return false;                            // keeps the dialog open
            }
            out.innerHTML = '<span style="color:#64748B;">Sending…</span>';

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
                out.innerHTML = '<span style="color:#B91C1C;">' + esc(why) + '</span>';
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
        render(container);
      });
    });
  }
  KT.AccountLedgers = { render: render, statement: openStatement };
})(window);
