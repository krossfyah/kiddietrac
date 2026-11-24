/* KIDDIETRAC — Platform invoices (Sales → Invoices)
 *
 * What agencies owe KiddieTrac. Backed by /platform/invoices, which is guarded with
 * role:platform_admin — this is the platform's own receivables ledger and no
 * agency-scoped role can read it.
 *
 * Money arrives from the API in CENTS as integers, matching agencies.plan_amount_cents,
 * and is only converted for display. Nothing here does arithmetic on dollars.
 *
 * Void invoices are shown but never counted: the API excludes them from totals, and the
 * row is dimmed so a cancelled invoice cannot be mistaken for money owed.
 */
(function (window) {
  'use strict';

  var KT = window.KT = window.KT || {};
  var Shell = KT.Shell;

  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
    catch (e) { return null; }
  }
  function apiBase() { return (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  function api(path, method, body) {
    var h = { Accept: 'application/json', Authorization: 'Bearer ' + token() };
    if (body) { h['Content-Type'] = 'application/json'; }
    try {
      var aid = sessionStorage.getItem('kt_active_agency_id');
      if (aid) { h['X-Active-Agency-Id'] = aid; }
    } catch (e) {}
    return fetch(apiBase() + path, {
      method: method || 'GET', headers: h,
      body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error(j.message || ('HTTP ' + r.status)); }
        return j;
      });
    });
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /** Cents to a readable amount. Never used for arithmetic — display only. */
  function money(cents, cur) {
    var v = (Number(cents) || 0) / 100;
    try {
      return new Intl.NumberFormat('en-CA', {
        style: 'currency', currency: cur || 'CAD', minimumFractionDigits: 2,
        /* CAD and USD both render as "$". On a screen that now carries both, a bare
           $249.00 is ambiguous by the exchange rate, so the code is always shown. */
        currencyDisplay: 'code',
      }).format(v);
    } catch (e) { return (cur || 'CAD') + ' ' + v.toFixed(2); }
  }

  function fmtDate(d) {
    if (!d) { return '—'; }
    try {
      /* A date-only value (2026-10-31) is a calendar day, not an instant: parsed as one it is
         midnight UTC, which west of Greenwich prints as the day before (2026-10-25). */
      if (/^\d{4}-\d{2}-\d{2}$/.test(String(d))) {
        var q = String(d).split('-');
        return new Date(Date.UTC(+q[0], +q[1] - 1, +q[2], 12)).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
      }
      return new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-CA',
        { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return String(d).slice(0, 10); }
  }

  var STATUS = {
    draft:  { bg: '#FEF3C7', fg: '#92400E', label: 'Draft' },
    issued: { bg: '#DBEAFE', fg: '#1E40AF', label: 'Issued' },
    paid:   { bg: '#DCFCE7', fg: '#166534', label: 'Paid' },
    void:   { bg: '#F3F4F6', fg: '#6B7280', label: 'Void' },
  };

  function badge(s) {
    var c = STATUS[s] || STATUS.draft;
    return '<span style="display:inline-block;background:' + c.bg + ';color:' + c.fg
      + ';padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">'
      + esc(c.label) + '</span>';
  }

  /* LIGHT GRADIENTS (2026-10-25): each tile tinted for what it means - billed blue, collected green, outstanding
     amber, tax violet, overdue rose - and neutral when there is nothing to flag. */
  var TINT = {
    blue: ['linear-gradient(135deg,#EFF6FF 0%,#FFFFFF 70%)', '#BFDBFE'], green: ['linear-gradient(135deg,#ECFDF5 0%,#FFFFFF 70%)', '#A7F3D0'],
    amber: ['linear-gradient(135deg,#FFFBEB 0%,#FFFFFF 70%)', '#FDE68A'], violet: ['linear-gradient(135deg,#F5F3FF 0%,#FFFFFF 70%)', '#DDD6FE'],
    rose: ['linear-gradient(135deg,#FFF1F2 0%,#FFFFFF 70%)', '#FECDD3'], teal: ['linear-gradient(135deg,#ECFEFF 0%,#FFFFFF 70%)', '#A5F3FC'],
  };
  function statTile(label, value, colour, tint) {
    var t = TINT[tint] || TINT.teal;
    return '<div data-kt-keep-brand="1" style="background:' + t[0] + ';border:1px solid ' + t[1] + ';border-radius:12px;padding:14px 16px;">'
      + '<div style="font-size:11px;font-weight:700;color:#6B7280;letter-spacing:.5px;'
      + 'text-transform:uppercase;">' + esc(label) + '</div>'
      + '<div style="font-size:21px;font-weight:700;margin-top:5px;color:' + (colour || '#0F172A')
      + ';font-variant-numeric:tabular-nums;">' + esc(value) + '</div></div>';
  }


  /* Agency billing plans: price, currency, cadence, tax. This is what makes the billing
     recurring — each agency carries its own next_invoice_at, and "Raise invoices due now"
     bills only the agencies whose date has arrived, then advances it by their interval.

     An agency with no next invoice date is deliberately NOT on recurring billing. That is
     how a customer is parked without throwing away their pricing. */
  var INTERVAL_LABEL = { weekly: 'Weekly', biweekly: 'Every 2 weeks', monthly: 'Monthly', quarterly: 'Quarterly', semiannual: 'Semi-annual', annual: 'Yearly' };

  /* AUTOMATIC RAISING (2026-09-29). The nightly platform:billing-run (06:00) raises every
     agency whose next invoice date has arrived, as drafts, then moves the date on by the
     agency's cadence. The switch was only on the Billing settings screen, so from here it
     looked as if nothing was automated. Auto-EMAIL stays on Billing settings: sending to a
     customer is a separate, deliberate choice and is never turned on from here. */
  function autoRow(box) {
    if (!box) { return; }
    api('/platform/billing-automation').then(function (a) {
      box.innerHTML = '<label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;">'
        + '<input type="checkbox" data-auto' + (a.auto_raise ? ' checked' : '') + ' style="margin-top:3px;">'
        + '<span><strong style="color:#0F172A;">Raise invoices automatically</strong><br>'
        + 'Every morning at 6:00, each agency whose next invoice date has arrived gets a draft invoice, and its next date moves on by its cadence (weekly, every 2 weeks, monthly, quarterly or yearly). '
        + (a.auto_email ? 'Drafts are also emailed (set on Billing settings).' : 'Nothing is emailed: you review and send the drafts.') + '</span></label>';
      var cb = box.querySelector('[data-auto]');
      cb.addEventListener('change', function () {
        cb.disabled = true;
        api('/platform/billing-automation', 'PUT', { auto_raise: cb.checked, auto_email: !!a.auto_email && cb.checked })
          .then(function (r) { a.auto_raise = r.auto_raise; a.auto_email = r.auto_email; cb.disabled = false; if (KT.toast) { KT.toast('✓', 'Saved', cb.checked ? 'Invoices will be raised automatically.' : 'Automatic raising is off.', '#16A34A'); } })
          .catch(function (e) { cb.checked = !cb.checked; cb.disabled = false; window.alert(e.message); });
      });
    }).catch(function () { box.textContent = 'Could not load the automation setting.'; });
  }

  /* REVIEW BEFORE RAISING (2026-09-29). Replaces a window.confirm that listed names and
     totals: each agency due is shown with where the invoice goes and the period it covers,
     and the price, tax, due date and a note can be changed (optionally saved as the
     agency's plan) or the agency unticked before anything is created. The server
     recomputes tax and totals; it only takes the inputs. */
  function reviewRaise(p, main) {
    var will = p.would_raise || [], skip = p.skipped || [];
    function taxOf(sub, bps) { return (sub <= 0 || bps <= 0) ? 0 : Math.floor((sub * bps + 5000) / 10000); }
    function cents(v) { var n = Number(String(v).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? NaN : Math.round(n * 100); }
    function dt(d) { if (!d) { return '—'; } var q = String(d).slice(0, 10).split('-'); return new Date(Date.UTC(+q[0], +q[1] - 1, +q[2], 12)).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); }
    var F = 'height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;box-sizing:border-box;';
    var L = 'display:block;font-size:11.5px;color:#475569;font-weight:600;margin-bottom:3px;';

    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:2147483000;padding:16px;';
    var h = '<div role="dialog" aria-modal="true" style="background:#fff;border-radius:14px;width:100%;max-width:760px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;">'
      + '<div style="padding:16px 20px 10px;border-bottom:1px solid #E5E7EB;"><div style="font-size:17px;font-weight:700;color:#0F172A;">'
      + (will.length ? 'Review ' + will.length + ' invoice' + (will.length === 1 ? '' : 's') + ' due' : 'Nothing is due') + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + (will.length ? 'Check each agency, change anything that needs it, untick any to hold back. They are created as drafts: nothing is emailed.' : 'No agency has an invoice date that has arrived.') + '</div></div>'
      + '<div style="overflow:auto;padding:12px 20px;flex:1;">';

    will.forEach(function (x, ix) {
      var cur = x.currency || 'CAD';
      var noAddr = !x.address, noMail = !x.contact_email;
      h += '<div data-row="' + ix + '" style="border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;margin-bottom:10px;">'
        + '<label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;"><input type="checkbox" data-inc checked style="margin-top:3px;">'
        + '<span style="flex:1;min-width:0;"><strong style="font-size:14.5px;color:#0F172A;">' + esc(x.agency_name) + '</strong>'
        + (x.legal_name && x.legal_name !== x.agency_name ? ' <span style="color:#64748B;font-size:12px;">(' + esc(x.legal_name) + ')</span>' : '')
        + ' <span style="display:inline-block;background:#EEF2FF;color:#3730A3;border-radius:9px;padding:0 7px;font-size:11px;font-weight:700;">' + esc(x.interval_label || INTERVAL_LABEL[x.interval] || x.interval) + '</span>'
        + '<div style="font-size:12.5px;color:#475569;margin-top:3px;line-height:1.5;">Period ' + dt(x.period_start) + ' – ' + dt(x.period_end)
        + (x.next_after ? ' · next invoice ' + dt(x.next_after) : '')
        + '<br>Bill to: ' + (noMail ? '<span style="color:#B45309;">no billing email</span>' : esc(x.contact_email))
        + ' · ' + (noAddr ? '<span style="color:#B45309;">no address on file (Billing plans → View)</span>' : esc(x.address)) + '</div></span></label>'
        + '<div data-fields style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:10px;">'
        + '<label><span style="' + L + '">Price before tax (' + esc(cur) + ')</span><input data-f="sub" value="' + ((Number(x.subtotal_cents) || 0) / 100).toFixed(2) + '" style="width:100%;' + F + '"></label>'
        + '<label><span style="' + L + '">Tax %</span><input data-f="bps" value="' + ((Number(x.tax_rate_bps) || 0) / 100).toFixed(2) + '" style="width:100%;' + F + '"></label>'
        + '<label><span style="' + L + '">Tax name</span><input data-f="label" value="' + esc(x.tax_label || '') + '" placeholder="HST" style="width:100%;' + F + '"></label>'
        + '<label><span style="' + L + '">Due date</span><input data-f="due" type="date" value="' + esc(x.due_at || '') + '" style="width:100%;' + F + '"></label>'
        + ((x.extras || []).length ? '<div style="grid-column:1/-1;font-size:12.5px;color:#7C3AED;background:#F5F3FF;border-radius:8px;padding:6px 10px;">Plus extra line items: '
            + x.extras.map(function (e) { return esc(e.description) + ' ' + money(Math.round((Number(e.qty) || 1) * e.unit_cents), cur) + (e.taxable ? ' + tax' : '') + (e.recurring ? '' : ' (this invoice only)'); }).join(' · ') + '</div>' : '')
        + '<label style="grid-column:1/-1;"><span style="' + L + '">Note on the invoice (optional)</span><input data-f="notes" maxlength="1000" placeholder="e.g. Includes 2 extra centres from 15 Sep" style="width:100%;' + F + '"></label>'
        + '</div>'
        + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;">'
        + '<label style="font-size:12.5px;color:#475569;display:flex;gap:6px;align-items:center;cursor:pointer;"><input type="checkbox" data-f="save"> Use this price and tax for future invoices</label>'
        + '<span style="flex:1"></span><span style="font-size:13px;color:#475569;">Total <strong data-total style="color:#0F172A;font-size:14px;"></strong></span></div>'
        + '</div>';
    });

    if (skip.length) {
      h += '<details style="margin-top:4px;"><summary style="cursor:pointer;font-size:13px;color:#475569;font-weight:600;">Not due (' + skip.length + ')</summary>'
        + '<div style="font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.7;">' + skip.map(function (x) { return esc(x.agency_name) + ': ' + esc(x.skip_reason); }).join('<br>') + '</div></details>';
    }
    h += '</div><div style="padding:12px 20px;border-top:1px solid #E5E7EB;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
      + '<span data-sum style="font-size:13px;color:#475569;flex:1;"></span>'
      + '<button class="kt-btn kt-btn-secondary" data-x>' + (will.length ? 'Cancel' : 'Close') + '</button>'
      + (will.length ? '<button class="kt-btn" data-go>Raise invoices</button>' : '') + '</div></div>';
    ov.innerHTML = h;
    document.body.appendChild(ov);

    var go = ov.querySelector('[data-go]'), sumEl = ov.querySelector('[data-sum]');
    function read(row) {
      var f = function (k) { return row.querySelector('[data-f="' + k + '"]'); };
      return { sub: cents(f('sub').value), bps: Math.round(Number(String(f('bps').value).replace(/[^0-9.]/g, '')) * 100), label: f('label').value.trim(), due: f('due').value, notes: f('notes').value.trim(), save: f('save').checked };
    }
    function paint() {
      var n = 0, byCur = {};
      ov.querySelectorAll('[data-row]').forEach(function (row) {
        var x = will[+row.getAttribute('data-row')], v = read(row), on = row.querySelector('[data-inc]').checked;
        var ok = !isNaN(v.sub) && v.sub >= 0 && !isNaN(v.bps);
        var t = ok ? v.sub + taxOf(v.sub, v.bps) : NaN;
        row.querySelector('[data-total]').textContent = ok ? money(t, x.currency) : 'check the amount';
        row.style.opacity = on ? '1' : '.5';
        row.querySelector('[data-fields]').style.pointerEvents = on ? '' : 'none';
        if (on && ok) { n++; byCur[x.currency || 'CAD'] = (byCur[x.currency || 'CAD'] || 0) + t; }
      });
      if (sumEl) { sumEl.textContent = n ? n + ' to raise · ' + Object.keys(byCur).map(function (c) { return money(byCur[c], c); }).join(' + ') : 'None selected'; }
      if (go) { go.disabled = !n; go.textContent = n ? 'Raise ' + n + ' invoice' + (n === 1 ? '' : 's') : 'Raise invoices'; }
    }
    ov.addEventListener('input', paint);
    ov.addEventListener('change', paint);
    paint();
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };

    if (go) {
      go.onclick = function () {
        var items = [], bad = null;
        ov.querySelectorAll('[data-row]').forEach(function (row) {
          if (!row.querySelector('[data-inc]').checked) { return; }
          var x = will[+row.getAttribute('data-row')], v = read(row);
          if (isNaN(v.sub) || v.sub < 0) { bad = bad || x.agency_name + ': the price is not a valid amount.'; }
          else if (isNaN(v.bps) || v.bps < 0 || v.bps > 10000) { bad = bad || x.agency_name + ': the tax rate must be between 0 and 100%.'; }
          else if (v.bps > 0 && !v.label) { bad = bad || x.agency_name + ': give the tax a name (HST, GST, VAT).'; }
          else if (!v.due) { bad = bad || x.agency_name + ': pick a due date.'; }
          items.push({ agency_id: x.agency_id, subtotal_cents: v.sub, tax_rate_bps: v.bps, tax_label: v.label || null, due_at: v.due, notes: v.notes || null, save_to_plan: v.save });
        });
        if (bad) { window.alert(bad); return; }
        go.disabled = true; go.textContent = 'Raising…';
        api('/platform/invoices/raise', 'POST', { commit: true, items: items }).then(function (r) {
          ov.remove();
          if (KT.toast) { KT.toast('✓', 'Invoices raised', (r.count || 0) + ' draft' + (r.count === 1 ? '' : 's') + ' created' + ((r.already_billed || []).length ? ', ' + r.already_billed.length + ' already billed' : ''), '#16A34A'); }
          render(main);
        }).catch(function (e) { go.disabled = false; paint(); window.alert(e.message || 'Could not raise the invoices'); });
      };
    }
  }

  /* BILLING PLANS (2026-11-04, Anthony: "viewing the billing plan should be a popup where everything can be edited and
     saved, the table should be a view only"). The table only shows each agency's plan; View (or a click on the row)
     opens one popup with every field - price, currency, interval, tax, next invoice, business details, recurring and
     BCC - saved together in ONE request, so an address can never be written without the price typed above it. */
  function planEditor(host) {
    host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading plans…</div>';

    api('/platform/billing-plans').then(function (data) {
      var rows = data.agencies || [];
      var TD = 'padding:8px 10px;';

      var h = '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;'
        + 'padding:16px;margin-bottom:18px;">'
        + '<div style="font-weight:600;">Billing plans</div>'
        + '<div style="color:#64748B;font-size:12px;margin-top:2px;max-width:80ch;">'
        + 'Currency, tax, the next invoice date and business details for each agency. Open a plan to change it; when recurring invoices run is under Settings. Agencies <b>billed from licences</b> take their price and billing interval from Platform → Licences; '
        + 'the price here is only for agencies without a licence. With no next invoice date an agency is not on recurring billing and is never raised automatically.'
        + '</div>'
        + '<div style="overflow-x:auto;margin-top:14px;">'
        + '<table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="text-align:left;color:#64748B;font-size:11px;text-transform:uppercase;">'
        + ['Agency', 'Before discounts', 'Discounts / complimentary', 'Every', 'Tax', 'Total', 'Next invoice', 'Bill to', ''].map(function (t) { return '<th style="' + TD + '">' + t + '</th>'; }).join('')
        + '</tr></thead><tbody>';

      rows.forEach(function (a) {
        var cur = a.plan_currency || 'CAD';
        var lm = !!a.licence_managed;
        var bps = Number(a.tax_rate_bps) || 0;
        var pr = planMoney(a);
        h += '<tr data-plan="' + esc(a.id) + '" tabindex="0" title="Open this plan" style="border-top:1px solid #F1F5F9;cursor:pointer;">'
          + '<td style="' + TD + 'font-weight:600;">' + esc(a.name)
          + '<div style="font-weight:400;color:#94A3B8;font-size:11px;">'
          + esc(a.billing_status || 'no status') + (lm ? ' · <span style="color:#1F6080;font-weight:700;">billed from licences</span>' : '')
          + '<br>' + (!(data.recurring || {}).enabled ? '<span style="color:#94A3B8;">recurring off (platform)</span>'
              : a.recurring_skip ? '<span style="color:#B45309;">not recurring: ' + esc(a.recurring_skip) + '</span>'
              : '<span style="color:#047857;font-weight:700;">recurring ✓</span>') + '</div></td>'
          + '<td style="' + TD + 'white-space:nowrap;">' + esc(money(pr.gross, cur)) + '</td>'
          + '<td style="' + TD + 'white-space:nowrap;">' + (pr.off ? '<span style="color:#047857;font-weight:600;">−' + esc(money(pr.off, cur)) + '</span>'
              + (pr.net === 0 ? '<div style="font-size:11px;color:#64748B;">complimentary</div>' : '') : '<span style="color:#94A3B8;">—</span>') + '</td>'
          + '<td style="' + TD + '">' + esc(lm && a.licence_term_months ? a.licence_term_months + ' months' : (function (i) { return INTERVAL_LABEL[i] || i || '—'; })(lm && a.licence_interval ? a.licence_interval : a.billing_interval)) + '</td>'
          + '<td style="' + TD + 'white-space:nowrap;">' + (bps ? esc((bps / 100).toFixed(2)) + '% ' + esc(a.tax_label || '') : '<span style="color:#94A3B8;">None</span>') + '</td>'
          + '<td style="' + TD + 'white-space:nowrap;font-weight:600;">' + esc(money(pr.total, cur)) + '</td>'
          + '<td style="' + TD + 'white-space:nowrap;">' + (a.next_invoice_at ? esc(fmtDate(a.next_invoice_at))
              : '<span style="color:#B45309;font-size:12px;">not recurring</span>') + '</td>'
          + '<td style="' + TD + 'font-size:12px;">' + (a.billing_address_complete ? '<span style="color:#047857;">✓ address on file</span>'
              : '<span style="color:#B45309;">no address on file</span>') + '</td>'
          + '<td style="' + TD + 'text-align:right;"><button type="button" data-view="' + esc(a.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">View</button></td></tr>';
      });

      h += '</tbody></table></div></div>';
      host.innerHTML = h;

      var byId = {};
      rows.forEach(function (a) { byId[String(a.id)] = a; });
      host.querySelectorAll('tr[data-plan]').forEach(function (tr) {
        var open = function () { planPopup(byId[tr.getAttribute('data-plan')], data, host); };
        tr.addEventListener('click', open);
        tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
      });
    }).catch(function (err) {
      host.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + esc(err.message) + '</div>';
    });
  }

  function planMoney(a) {
    var bps = Number(a.tax_rate_bps) || 0;
    var lm = !!a.licence_managed;
    var net = lm ? (Number(a.licence_total_cents) || 0) : (Number(a.plan_amount_cents) || 0);
    var gross = lm ? Math.max(net, Number(a.licence_gross_cents) || 0) : net;
    return { gross: gross, off: gross - net, net: net, total: net + Math.round(net * bps / 10000) };
  }

  /* One agency's plan: everything editable, one Save. */
  function planPopup(a, data, host) {
    var M = KT.Shell && KT.Shell.Modal;
    if (!a || !M) { return; }
    var currencies = data.currencies || ['CAD', 'USD'];
    var intervals = data.intervals || ['weekly', 'biweekly', 'monthly', 'quarterly', 'annual'];
    var cur = a.plan_currency || 'CAD';
    var lm = !!a.licence_managed;
    var I = 'width:100%;box-sizing:border-box;height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;';
    var L = 'display:block;font-size:11.5px;color:#475569;margin-bottom:3px;';
    var H = 'font-size:11px;font-weight:700;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin:16px 0 6px;';
    var G = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;align-items:end;';
    function lab(text, inner) { return '<label style="display:block;"><span style="' + L + '">' + esc(text) + '</span>' + inner + '</label>'; }
    function opt(v, text, sel) { return '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(text) + '</option>'; }
    /* Label above input. The placeholder carries an EXAMPLE, never a default -
       a greyed-out address that looks filled in is how a placeholder ends up
       printed on somebody's invoice. */
    function biz(key, text, example) {
      return lab(text, '<input data-biz-f="' + esc(key) + '" value="' + esc(a[key] == null ? '' : String(a[key])) + '" placeholder="' + esc(example) + '" style="' + I + '">');
    }

    var box = document.createElement('div');
    box.setAttribute('data-kt-nopack', '1');
    box.innerHTML = '<div style="font-size:12.5px;color:#64748B;">' + esc(a.billing_status || 'no status')
      + (lm ? ' · <span style="color:#1F6080;font-weight:700;">billed from licences</span> - the price and its interval come from Platform → Licences' : '') + '</div>'
      + '<div style="' + H + 'margin-top:10px;">Plan</div>'
      + '<div style="' + G + '">'
      + (lm ? lab('Price (from licences)', '<div style="min-height:30px;line-height:30px;font-weight:700;">' + esc(money(planMoney(a).net, cur))
              + (planMoney(a).off ? ' <span style="font-weight:500;color:#64748B;font-size:12px;">(' + esc(money(planMoney(a).gross, cur)) + ' before <span style="color:#047857;">−' + esc(money(planMoney(a).off, cur)) + '</span> discounts / complimentary)</span>' : '') + '</div>'
              + '<input data-f="amount" type="hidden" value="' + esc(((Number(a.plan_amount_cents) || 0) / 100).toFixed(2)) + '">')
            : lab('Price', '<input data-f="amount" inputmode="decimal" value="' + esc(((Number(a.plan_amount_cents) || 0) / 100).toFixed(2)) + '" style="' + I + '">'))
      + lab('Currency', '<select data-f="currency" style="' + I + '">' + currencies.map(function (c) { return opt(c, c, c === cur); }).join('') + '</select>')
      + lab('Every', '<select data-f="interval" style="' + I + '">' + intervals.map(function (i) {
          return opt(i, INTERVAL_LABEL[i] || (i.charAt(0).toUpperCase() + i.slice(1)), i === a.billing_interval); }).join('') + '</select>')
      + lab('Tax %', '<input data-f="taxrate" inputmode="decimal" value="' + esc(((Number(a.tax_rate_bps) || 0) / 100).toFixed(2)) + '" style="' + I + '">')
      + lab('Tax name', '<input data-f="taxlabel" placeholder="HST" value="' + esc(a.tax_label || '') + '" style="' + I + '">')
      + lab('Next invoice (the 1st of a month)', '<input data-f="next" type="date" value="' + esc(a.next_invoice_at || '') + '" style="' + I + '">')
      + '</div>'
      + '<div style="margin-top:8px;font-size:12.5px;color:#64748B;">Invoices are raised on the 1st and due at the end of the month. '
      + (lm ? 'This agency\'s term (monthly, quarterly, semi-annual, yearly or a number of months) is set on <a href="#licences">Platform → Licences</a>.' : 'A start mid-month is prorated to the month end.') + '</div>'
      + '<div style="margin-top:8px;font-size:13px;color:#334155;">Total each invoice: <b data-f="total">' + esc(money(a.total_cents, cur)) + '</b>'
      + '<span data-f="nextnote" style="color:#B45309;font-size:12px;margin-left:8px;"></span></div>'

      + '<div style="' + H + '">Business details on the invoice</div>'
      + '<div style="font-size:12px;color:#64748B;margin-bottom:8px;">Printed in the Bill To block. Anything left blank is simply omitted - no placeholder or empty line appears on the invoice.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">'
      + biz('legal_name', 'Registered name', a.name || '')
      + biz('address_line1', 'Address line 1', '123 Main Street')
      + biz('address_line2', 'Address line 2', 'Suite 4')
      + biz('city', 'City', 'Toronto')
      + biz('province', 'Province / State', 'ON')
      + biz('postal_code', 'Postal code', 'M5V 1A1')
      + biz('country', 'Country', 'Canada')
      + biz('tax_registration', 'Their tax registration no.', '12345 6789 RT0001')
      + biz('contact_email', 'Billing email', 'accounts@example.com')
      + biz('contact_phone', 'Phone', '416 555 0100')
      + biz('website', 'Website', 'example.com')
      + '</div>'

      /* RECURRING + BCC (2026-10-25): whether this agency is invoiced automatically, between which dates, who its
         salesperson is and who is copied on its invoices. */
      + '<div style="' + H + '">Recurring invoices &amp; email copies</div>'
      + '<div style="' + G + '">'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;height:30px;"><input type="checkbox" data-biz-f="recurring_enabled"' + (a.recurring_enabled ? ' checked' : '') + '> Include in recurring invoices'
          + ((data.recurring || {}).scope === 'all' ? ' <span style="color:#94A3B8;font-size:11.5px;">(all agencies are on)</span>' : '') + '</label>'
      + lab('Issue invoices from', '<input type="date" data-biz-f="billing_starts_at" value="' + esc(a.billing_starts_at || '') + '" style="' + I + '">')
      + lab('Issue invoices until (blank = no end)', '<input type="date" data-biz-f="billing_ends_at" value="' + esc(a.billing_ends_at || '') + '" style="' + I + '">')
      + lab('Account salesperson', '<select data-biz-f="account_manager_user_id" style="' + I + '">' + opt('', 'None', false)
          + (data.account_managers || []).map(function (u) { return opt(u.id, u.name || u.email, String(u.id) === String(a.account_manager_user_id)); }).join('') + '</select>')
      + lab('BCC on its invoices', '<select data-biz-f="invoice_bcc" style="' + I + '">'
          + [['default', 'Platform default'], ['none', 'No one'], ['superadmins', 'Superadmins'], ['sales', 'Its salesperson'], ['both', 'Superadmins + salesperson']]
            .map(function (o) { return opt(o[0], o[1], o[0] === (a.invoice_bcc || 'default')); }).join('') + '</select>')
      + '</div>';

    var f = function (k) { return box.querySelector('[data-f="' + k + '"]'); };
    function cents(el) { return Math.round((parseFloat(String(el.value).replace(/[^0-9.\-]/g, '')) || 0) * 100); }
    /* The total updates as the price or rate is typed, so the tax is visible BEFORE
       saving rather than as a surprise on the first invoice. */
    function recalc() {
      var amt = lm ? (Number(a.licence_total_cents) || 0) : cents(f('amount'));
      f('total').textContent = money(amt + Math.round(amt * cents(f('taxrate')) / 10000), f('currency').value);
      f('nextnote').textContent = f('next').value ? '' : 'No next invoice date - not on recurring billing';
    }
    box.querySelectorAll('[data-f]').forEach(function (el) { el.addEventListener('input', recalc); el.addEventListener('change', recalc); });
    recalc();

    M.open({ title: '🏷️ ' + (a.name || 'Billing plan'), body: box, size: 'lg', actions: [{ label: 'Cancel' }, { label: 'Save', style: 'btn-primary', busyLabel: 'Saving…', handler: function () {
      var bps = cents(f('taxrate'));
      var label = f('taxlabel').value.trim();
      /* Checked here as well as on the server: an unnamed tax prints as a bare "Tax"
         line on a real invoice, which will not do in a jurisdiction that requires it named. */
      if (bps > 0 && !label) {
        if (KT.toast) KT.toast('⚠️', 'Name the tax', 'A tax rate needs a name for the invoice - HST, GST, VAT, Sales tax.', '#DC2626');
        f('taxlabel').focus();
        return false;
      }
      var body = {};
      box.querySelectorAll('input[data-biz-f], select[data-biz-f]').forEach(function (el) {
        if (el.type === 'checkbox') { body[el.getAttribute('data-biz-f')] = el.checked; return; }
        var v = el.value.trim();
        /* Empty clears the field rather than being skipped, so a wrong line can
           actually be removed from the invoice. */
        body[el.getAttribute('data-biz-f')] = v === '' ? null : v;
      });
      Object.assign(body, {
        plan_amount_cents: cents(f('amount')),
        plan_currency: f('currency').value,
        billing_interval: f('interval').value,
        tax_rate_bps: bps,
        tax_label: label || null,
        next_invoice_at: f('next').value || null,
      });
      return api('/platform/billing-plans/' + a.id, 'PUT', body).then(function () {
        if (KT.toast) KT.toast('✓', 'Plan saved', a.name || '', '#16A34A');
        planEditor(host);
      }).catch(function (err) {
        if (KT.toast) KT.toast('⚠️', 'Save failed', err.message || '', '#DC2626');
        return false;
      });
    } }] });
  }

  /* Which status group the table is showing. Open = owed and not yet settled, which
     is the only group that represents outstanding work, so it is the default. */
  var TABS = [
    { key: 'open', label: 'Open', match: function (r) { return r.status !== 'paid' && r.status !== 'void'; } },
    { key: 'paid', label: 'Paid', match: function (r) { return r.status === 'paid'; } },
    { key: 'void', label: 'Voided', match: function (r) { return r.status === 'void'; } },
  ];
  var activeTab = 'open';
  /* SUB-TABS (2026-10-25): one row instead of six buttons that each opened a panel above the table. */
  var SECTION = 'invoices';
  var SECTIONS = [['invoices', '🧾 Invoices'], ['payments', '💳 Payments'], ['extras', '➕ Extra line items'], ['plans', '🏷️ Billing plans'], ['fees', '⚙️ Settings'], ['ap', '📤 Expenses (AP)']];
  var PENDING_N = 0;
  function sectionBar() {
    return '<div id="kt-arap-tabs" role="tablist" aria-label="Accounting sections" style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px;">' + SECTIONS.map(function (x) {
      return '<button type="button" role="tab" class="kt-btn kt-btn-sm' + (x[0] === SECTION ? '' : ' kt-btn-secondary') + '" data-sectab="' + x[0] + '" aria-selected="' + (x[0] === SECTION) + '">'
        + esc(x[1]) + (x[0] === 'payments' && PENDING_N ? ' <span style="background:#DC2626;color:#fff;border-radius:999px;padding:0 6px;font-size:11px;margin-left:4px;">' + PENDING_N + '</span>' : '') + '</button>';
    }).join('') + '</div>';
  }
  function wireSections(main) {
    main.querySelectorAll('#kt-arap-tabs [data-sectab]').forEach(function (b) {
      b.addEventListener('click', function () { SECTION = b.getAttribute('data-sectab'); render(main); });
    });
  }
  function heroHtml() {
    return '<div class="kt-hero"><h2>\uD83E\uDDFE Accounting AR/AP</h2><p>KiddieTrac\'s invoices, payments and fees: what agencies owe for the platform, and what KiddieTrac spends.</p></div>';
  }
  function renderSection(main) {
    main.innerHTML = '<div class="kt-section">' + heroHtml() + sectionBar() + '<div id="kt-sec-host"></div></div><span data-pinv-root data-kt-self-live hidden></span>';
    wireSections(main);
    var host = main.querySelector('#kt-sec-host');
    if (SECTION === 'payments') return paymentsSection(host, main);
    if (SECTION === 'extras') return extrasPanel(host);
    if (SECTION === 'plans') return planEditor(host);
    if (SECTION === 'fees') {
      host.innerHTML = '<div id="kt-recur"></div><div id="kt-bm"></div>';
      recurringCard(host.querySelector('#kt-recur'));
      return billMailPanel(host.querySelector('#kt-bm'));
    }
    if (SECTION === 'ap') {
      host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading expenses…</div>';
      var go = function () {
        var fn = KT.Shell && KT.Shell.screenFn && KT.Shell.screenFn('platform_admin:sales-expenses');
        if (!fn) { host.innerHTML = '<div style="color:#64748B;">Expenses could not be loaded here. Open it from Sales → Expenses.</div>'; return; }
        host.innerHTML = '';
        Promise.resolve(fn(host)).then(function () { var h = host.querySelector('.kt-hero, .ki-hero'); if (h) h.remove(); }).catch(function () {});
      };
      if (KT.screenLoader && KT.screenLoader.ensure) KT.screenLoader.ensure(null, 'sales-expenses').then(go); else go();
    }
  }

  function render(main) {
    if (SECTION !== 'invoices') { renderSection(main); return; }
    if (!main.querySelector('[data-pinv-root]')) {
      main.innerHTML = '<div style="padding:22px;color:#64748B;">Loading invoices…</div>';
    }

    api('/platform/billing-emails').then(function (d) { var s = d.settings || {}; SURCHARGE_NOTE = s.surcharge_enabled ? surchargeText(s) : ''; }).catch(function () {});
    api('/platform/invoices').then(function (data) {
      var allRows = data.invoices || [];
      /* Counts come from the FULL set, so a tab still shows how many it holds while
         you are looking at a different one. */
      var tabDef = TABS.filter(function (t) { return t.key === activeTab; })[0] || TABS[0];
      var rows = allRows.filter(tabDef.match);
      /* A LIST now — one entry per currency. See the note at the top of this patch. */
      var totals = data.totals || [];
      if (!Array.isArray(totals)) { totals = [totals]; }
      /* Outstanding is the figure that prompts action, so it is the only one coloured
         when non-zero. Colouring everything would mean nothing stands out. */
      function tilesFor(t) {
        var cur = t.currency || 'CAD';
        var outstanding = Number(t.outstanding_cents) || 0;
        var overdue = Number(t.overdue_count) || 0;
        var tax = Number(t.tax_cents) || 0;
        /* Outstanding is the figure that prompts action, so it is the only one
           coloured when non-zero. Colouring everything would mean nothing stands out. */
        return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));'
          + 'gap:12px;margin-bottom:12px;">'
          + statTile(cur + ' billed', money(t.billed_cents, cur), null, 'blue')
          + statTile(cur + ' collected', money(t.paid_cents, cur), '#166534', 'green')
          + statTile(cur + ' outstanding', money(outstanding, cur), outstanding > 0 ? '#B45309' : '#0F172A', outstanding > 0 ? 'amber' : 'teal')
          + statTile(cur + ' tax billed', money(tax, cur), null, 'violet')
          + statTile(cur + ' overdue', String(overdue), overdue > 0 ? '#B91C1C' : '#0F172A', overdue > 0 ? 'rose' : 'teal')
          + '</div>';
      }

      var byId = {};
      var html = ''
        /* Emoji + h2 + p, and NOT ONE inline style — the shared .kt-page-hero CSS owns
           how a banner looks, and overriding it here is what made this screen the odd
           one out. The controls sit BELOW the banner, as they do everywhere else. */
        + heroHtml()
        + sectionBar()
        + '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;">'
        + '<button id="kt-new" class="kt-btn">＋ New invoice</button>'
        + '<button id="kt-raise" class="kt-btn kt-btn-secondary">Raise invoices due now</button>'
        + '</div>'

        + '<div id="kt-plans-panel"></div>'
        + '<div id="kt-tabs" style="display:flex;gap:6px;margin-bottom:14px;'
            + 'border-bottom:1px solid #E5E7EB;padding-bottom:0;">'
        + TABS.map(function (t) {
            var on = t.key === activeTab;
            var n = allRows.filter(t.match).length;
            return '<button data-tab="' + t.key + '" class="kt-btn kt-btn-sm" '
              + 'style="border:0!important;border-radius:0!important;background:none!important;'
              + 'padding:8px 14px!important;min-height:0!important;font-weight:'
              + (on ? '700' : '500') + ';color:' + (on ? '#1F6080' : '#64748B') + '!important;'
              + 'border-bottom:2px solid ' + (on ? '#1F6080' : 'transparent') + '!important;">'
              + esc(t.label)
              + '<span style="margin-left:6px;font-weight:600;color:#94A3B8;">' + n + '</span>'
              + '</button>';
          }).join('')
        + '</div>'
        + (totals.length
            ? totals.map(tilesFor).join('')
            : '<div style="color:#64748B;font-size:13px;margin-bottom:18px;">Nothing billed yet.</div>');

      if (!rows.length) {
        html += '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:34px;'
          + 'text-align:center;color:#64748B;">'
          + '<div style="font-size:34px;">🧾</div>'
          + '<div style="margin-top:8px;font-weight:600;">'
          + (allRows.length ? 'Nothing ' + esc(tabDef.label.toLowerCase()) : 'No invoices yet')
          + '</div>'
          + '<div style="font-size:13px;margin-top:5px;">'
          + (allRows.length
              ? 'Other tabs have invoices \u2014 this one is empty.'
              : 'Set an agency price under Billing plans, then use Raise invoices due now.')
          + '</div></div>';
      } else {
        html += '<table data-kt-filter-always style="width:100%;background:#fff;border-radius:10px;border-collapse:collapse;'
        + 'overflow:hidden;border:1px solid #E5E7EB;"><thead><tr style="background:#F9FAFB;">';
      ['Invoice', 'Agency', 'Boarded', 'Billing', 'Type', 'Period', 'Amount', 'Due', 'Status', ''].forEach(function (h) {
        html += '<th style="text-align:left;padding:11px 14px;font-size:11px;font-weight:700;'
          + 'color:#6B7280;text-transform:uppercase;letter-spacing:.5px;">' + esc(h) + '</th>';
      });
      html += '</tr></thead><tbody>';

      rows.forEach(function (r) {
        var isVoid = r.status === 'void';
        /* A void invoice is dimmed rather than hidden: the number is spent and the
           record has to stay auditable, but it must not read as money owed. */
        var dim = isVoid ? 'opacity:.55;' : '';
        var overdueRow = r.status !== 'paid' && !isVoid && r.due_at
          && String(r.due_at) < ((window.KT && KT.agencyToday) ? KT.agencyToday() : new Date().toISOString().slice(0, 10));

        html += '<tr data-inv="' + esc(r.id) + '" style="border-top:1px solid #E5E7EB;' + dim + '">'
          + '<td style="padding:11px 14px;font-weight:600;font-family:ui-monospace,monospace;font-size:12.5px;">'
          + esc(r.number) + '</td>'
          // The customer number under the agency (2026-11-11): typed into the table filter, it finds their invoices.
          + '<td style="padding:11px 14px;">' + esc(r.agency_name || '—')
          + (r.customer_number ? '<div style="font-size:11.5px;color:#64748B;font-family:ui-monospace,monospace;margin-top:1px;">' + esc(r.customer_number) + '</div>' : '') + '</td>'
          // When the agency signed with KiddieTrac (2026-10-25).
          + '<td style="padding:11px 14px;font-size:13px;color:#475569;white-space:nowrap;">' + esc(fmtDate(r.boarded_at)) + '</td>'
          // Monthly / Yearly (2026-10-25).
          + '<td style="padding:11px 14px;white-space:nowrap;">' + (r.billing_interval ? '<span style="display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;'
              + (r.billing_interval === 'annual' ? 'color:#6D28D9;background:#EDE9FE;' : 'color:#075985;background:#E0F2FE;') + '">'
              + esc({ monthly: 'Monthly', annual: 'Yearly', quarterly: 'Quarterly', weekly: 'Weekly', biweekly: 'Every 2 weeks' }[r.billing_interval] || r.billing_interval) + '</span>' : '<span style="color:#CBD5E1;">—</span>') + '</td>'
          // How it was raised (2026-10-25): Recurring (the recurring run / a licence renewal), Manual (New invoice) or Prepay.
          + '<td style="padding:11px 14px;white-space:nowrap;"><span style="display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;'
              + ({ recurring: 'color:#047857;background:#D1FAE5;', manual: 'color:#92400E;background:#FEF3C7;', prepay: 'color:#6D28D9;background:#EDE9FE;' }[r.raised] || 'color:#475569;background:#F1F5F9;') + '">'
              + esc({ recurring: '🔁 Recurring', manual: '✍️ Manual', prepay: 'Prepay' }[r.raised] || '—') + '</span></td>'
          + '<td style="padding:11px 14px;font-size:13px;color:#6B7280;">'
          + esc(fmtDate(r.period_start)) + '</td>'
          + '<td style="padding:11px 14px;font-variant-numeric:tabular-nums;">'
          + esc(money(r.amount_cents, r.currency))
          + (Number(r.amount_paid_cents) > 0 && r.status !== 'paid'
              ? '<div style="font-size:11px;color:#166534;">'
                + esc(money(r.amount_paid_cents, r.currency)) + ' paid</div>' : '')
          + '</td>'
          + '<td style="padding:11px 14px;font-size:13px;color:' + (overdueRow ? '#B91C1C' : '#6B7280') + ';">'
          + esc(fmtDate(r.due_at)) + (overdueRow ? ' ⚠' : '') + '</td>'
          + '<td style="padding:11px 14px;">' + badge(r.status) + '</td>'
          + '<td style="padding:11px 14px;text-align:right;white-space:nowrap;">';

        /* On every row including void — being able to see what was cancelled is
           the reason for keeping it. */
        html += '<button data-act="pdf" data-id="' + esc(r.id)
          + '" class="kt-btn kt-btn-secondary kt-btn-sm">View invoice</button> ';
        /* Lines and payments (2026-10-25): add a line, remove an extra, waive late fees, mark a
           payment returned (NSF). */
        html += '<button data-act="manage" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Lines &amp; payments</button> ';
        if (r.status === 'draft') {
          html += '<button data-act="issue" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Issue</button> ';
        }
        /* Email is offered on anything not void, including already-issued invoices —
           resending a copy is the single most common thing anyone needs to do. */
        if (!isVoid) {
          html += '<button data-act="email" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Email invoice</button> ';
        }
        if (r.status !== 'paid' && !isVoid) {
          html += '<button data-act="paid" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Record payment</button> ';
        }
        if (!isVoid && r.status !== 'paid') {
          // Reissue (2026-10-25): void and raise a corrected copy from what is licensed now.
          html += '<button data-act="reissue" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Reissue</button> ';
          html += '<button data-act="void" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Void</button> ';
        }
        /* Edit and Delete are DRAFT-ONLY, and Delete additionally requires that the
           draft was never emailed. Once a customer holds a PDF of an invoice, the row
           behind it stops being ours to rewrite or erase — that is what Void is for.
           The server enforces both rules; this only avoids offering a dead button. */
        if (!isVoid && r.status !== 'paid') {
          html += '<button data-act="editfull" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Edit invoice</button> ';
        }
        if (!isVoid && Number(r.amount_paid_cents) > 0) {
          html += '<button data-act="refund" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Refund…</button> ';
        }
        if (r.status === 'draft') {
          if (!r.sent_at) {
            html += '<button data-act="delete" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Delete draft</button>';
          }
        }
        html += '</td></tr>';
        byId[String(r.id)] = r;
      });

      html += '</tbody></table>'
        + '<div style="font-size:12px;color:#94A3B8;margin-top:12px;">'
        + 'Issue records the date and emails nothing. Email invoice sends the PDF to the address you confirm (and issues a draft).</div>';

      }

      /* ONE write and ONE wiring pass, whether or not there are rows. The tab bar and the
         header buttons live above the table, so they must be wired even when it is empty —
         otherwise voiding the last open invoice leaves a screen nothing can click out of. */
      // In the standard section box, like every other section (2026-10-21).
      if ((data.issuer_missing || []).length) {
        html = '<div style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:10px 14px;font-size:13px;margin:0 0 14px;">'
          + '⚠️ Only you see this: KiddieTrac\'s ' + esc(data.issuer_missing.join(' and ')) + ' is not set, so it is left off every invoice. Add it in Platform settings → Invoice details.</div>' + html;
      }
      main.innerHTML = '<div class="kt-section">' + html + '</div><span data-pinv-root data-kt-self-live hidden></span>';

      wireSections(main);
      api('/platform/invoices/payments').then(function (pd) { var n = (pd.payments || []).length; if (n !== PENDING_N) { PENDING_N = n; var bar = main.querySelector('#kt-arap-tabs'); if (bar) { bar.outerHTML = sectionBar(); wireSections(main); } } }).catch(function () {});
      main.querySelectorAll('#kt-tabs button[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          activeTab = b.getAttribute('data-tab');
          render(main);
        });
      });

      var plansBtn = main.querySelector('#kt-plans');
      var plansHost = main.querySelector('#kt-plans-panel');
      if (plansBtn && plansHost) {
        plansBtn.addEventListener('click', function () {
          if (plansHost.innerHTML) { plansHost.innerHTML = ''; return; }
          planEditor(plansHost);
        });
      }

      var acctPanel = main.querySelector('#kt-acct-panel');
      // Below the button row, full width: inside it, the open panel stretched every button (2026-10-25).
      if (acctPanel && acctPanel.parentNode && acctPanel.parentNode.parentNode) { acctPanel.parentNode.parentNode.insertBefore(acctPanel, acctPanel.parentNode.nextSibling); acctPanel.style.width = '100%'; }
      var paysBtn = main.querySelector('#kt-pays');
      if (paysBtn && acctPanel) paysBtn.addEventListener('click', function () { pendingPanel(acctPanel, main); });
      var mailBtn = main.querySelector('#kt-billmail');
      if (mailBtn && acctPanel) mailBtn.addEventListener('click', function () { billMailPanel(acctPanel); });
      var exBtn = main.querySelector('#kt-extras');
      if (exBtn && acctPanel) exBtn.addEventListener('click', function () { extrasPanel(acctPanel); });
      if (acctPanel) api('/platform/invoices/payments').then(function (d) { var n = (d.payments || []).length; if (n && paysBtn) { paysBtn.textContent = '💳 Payments to confirm (' + n + ')'; paysBtn.className = 'kt-btn'; } }).catch(function () {});

      var newBtn = main.querySelector('#kt-new');
      if (newBtn) newBtn.addEventListener('click', function () { newInvoice(main); });
      var raiseBtn = main.querySelector('#kt-raise');
      if (raiseBtn) {
        raiseBtn.addEventListener('click', function () {
          raiseBtn.disabled = true;
          /* Preview first. This creates financial records, so it does not happen on
             one click — the confirm names exactly who would be billed. */
          api('/platform/invoices/raise', 'POST', { commit: false }).then(function (p) {
            raiseBtn.disabled = false;
            reviewRaise(p, main);
          }).catch(function (err) {
            raiseBtn.disabled = false;
            if (KT.toast) { KT.toast('!', 'Could not raise invoices', err.message, '#DC2626'); }
            else { window.alert(err.message); }
          });
        });
      }

      main.querySelectorAll('button[data-act]').forEach(function (b) {
        b.addEventListener('click', async function () {
          var id = b.getAttribute('data-id');
          var act = b.getAttribute('data-act');

          /* Void and mark-paid change what is owed, so they are confirmed. Issuing
             only stamps a date and is reversible by voiding. KiddieTrac dialogs, not the
             browser's: those blocked the page and looked like a different product. */
          if (act === 'void' && !(await KT.confirm({ title: 'Void this invoice?', description: 'It stays on record but stops counting as owed.', okLabel: 'Void invoice', tone: 'danger' }))) { return; }
          var row = byId[String(id)] || {};

          /* RECORD A PAYMENT (2026-10-25): full, part or early, with how it was paid. A receipt is
             issued, and the agency's billing contacts get it by email (or the paid-in-full email with
             the PAID invoice) unless you untick it. */
          if (act === 'paid') {
            var bal = Math.max(0, (Number(row.amount_cents) || 0) - (Number(row.amount_paid_cents) || 0)) / 100;
            var pr = await KT.prompt({
              title: 'Record a payment on ' + (row.number || 'this invoice'),
              description: 'Enter the whole balance or part of it. A receipt is issued for every payment.'
                + (SURCHARGE_NOTE ? ' ' + SURCHARGE_NOTE + ' Enter the total received; the surcharge is split out as its own line.' : ''),
              fields: [
                { key: 'amount', label: 'Amount received (' + (row.currency || 'CAD') + ')', type: 'number', step: '0.01', value: bal.toFixed(2) },
                { key: 'method', label: 'How it was paid', type: 'select', value: 'etransfer', options: [
                  { value: 'etransfer', label: 'Interac e-Transfer' }, { value: 'eft', label: 'EFT / bank transfer' }, { value: 'card', label: 'Card' },
                  { value: 'pad', label: 'Pre-authorised debit' }, { value: 'cheque', label: 'Cheque' }, { value: 'credit', label: 'Account credit' }, { value: 'other', label: 'Other' }] },
                { key: 'paid_on', label: 'Paid on', type: 'date', value: (KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10)) },
                { key: 'reference', label: 'Reference (optional)', placeholder: 'e.g. e-Transfer confirmation' },
                { key: 'notify', label: 'Email the receipt to the agency', type: 'select', value: '1', options: [{ value: '1', label: 'Yes, email the receipt' }, { value: '0', label: 'No, just record it' }] },
              ],
              okLabel: 'Record payment',
            });
            if (pr === null) { return; }
            var amtNum = Number(String(pr.amount || '').replace(/[^0-9.]/g, ''));
            if (!(amtNum > 0)) { if (KT.toast) KT.toast('⚠️', 'Enter the amount received', '', '#B45309'); return; }
            b.disabled = true;
            api('/platform/invoices/' + id + '/payments', 'POST', { amount: amtNum, method: pr.method, paid_on: pr.paid_on || null, reference: (pr.reference || '').trim() || null, notify: pr.notify === '1' })
              .then(function (r) { if (KT.toast) KT.toast('🧾', r.status === 'paid' ? 'Paid in full' : 'Payment recorded', 'Receipt ' + (r.payment && r.payment.receipt_number || '') + (r.sent ? ' · emailed' : ''), '#16A34A'); render(main); })
              .catch(function (e) { b.disabled = false; if (KT.toast) KT.toast('⚠️', 'Not recorded', e.message, '#DC2626'); });
            return;
          }

          if (act === 'manage') { manageInvoice(id, main); return; }
          if (act === 'editfull') { editInvoice(id, main); return; }
          if (act === 'refund') { refundInvoice(row, main); return; }
          if (act === 'reissue') {
            var rs = await KT.prompt({ title: 'Reissue ' + (row.number || 'this invoice') + '?', description: 'It is voided and a corrected copy is raised for the same period from what the agency is licensed for now: removed licences drop off, the complimentary line is worked out again, other lines and every payment move across.',
              fields: [{ key: 'reason', label: 'Reason (on the new invoice)', placeholder: 'e.g. SMS Bundle removed' }], okLabel: 'Reissue invoice' });
            if (rs === null) { return; }
            b.disabled = true;
            api('/platform/invoices/' + id + '/reissue', 'POST', { reason: (rs.reason || '').trim() || null })
              .then(function (r) { if (KT.toast) KT.toast('🧾', 'Reissued as ' + r.invoice.number, money(r.invoice.amount_cents, row.currency) + ' · use Email invoice to send it', '#16A34A'); render(main); })
              .catch(function (e) { b.disabled = false; window.alert(e.message); });
            return;
          }

          if (act === 'email') {
            /* The address is NEVER inferred. It is prefilled from the agency contact so
               this is one keystroke, but a person reads it before a customer is emailed —
               a wrong address here bills the wrong company. */
            /* SEVERAL ADDRESSES (2026-10-25, Anthony: "allow to add more than one email and save for future"): comma or
               new-line separated, prefilled from the addresses saved on the agency; tick to save them for next time
               (they are also used by the automatic billing emails). */
            var pr = await KT.prompt({
              title: 'Email invoice ' + (row.number || ''),
              description: 'The PDF is attached. Separate several addresses with commas. A draft becomes issued once it is sent.',
              fields: [{ key: 'to', label: 'Send to', type: 'textarea', value: (row.invoice_emails || row.agency_email || '').split(/[,;\s]+/).filter(Boolean).join(', ') },
                { key: 'save', label: 'For future invoices', type: 'select', value: '1', options: [{ value: '1', label: 'Save these addresses for this agency' }, { value: '0', label: 'Just this once' }] }],
              okLabel: 'Send invoice',
            });
            if (pr === null) { return; }
            var list = String(pr.to || '').split(/[,;\s]+/).map(function (x) { return x.trim(); }).filter(Boolean);
            var bad = list.filter(function (x) { return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x); });
            if (!list.length) { window.alert('No address given — nothing was sent.'); return; }
            if (bad.length) { window.alert('Not an email address: ' + bad.join(', ')); return; }
            b.disabled = true;
            api('/platform/invoices/' + id + '/email', 'POST', { to: list.join(','), save: pr.save === '1' })
              .then(function () {
                if (KT.toast) { KT.toast('\u2709', 'Invoice sent', 'Emailed to ' + list.join(', ') + (pr.save === '1' ? ' · saved for next time' : ''), '#16A34A'); }
                render(main);
              })
              .catch(function (err) { b.disabled = false; window.alert(err.message); });
            return;
          }

          if (act === 'edit') {
            var cur = (Number(row.subtotal_cents) || Number(row.amount_cents) || 0) / 100;
            /* One dialog for what used to be four prompts in a row. */
            var ed = await KT.prompt({
              title: 'Edit ' + (row.number || 'this invoice'),
              description: 'The tax and total are recalculated from these.',
              fields: [
                { key: 'amount', label: 'Amount before tax (' + (row.currency || 'CAD') + ')', value: String(cur.toFixed(2)) },
                { key: 'due', label: 'Due date (YYYY-MM-DD), or blank for none', type: 'date', value: row.due_at ? String(row.due_at).slice(0, 10) : '' },
                { key: 'rate', label: 'Tax rate % (0 for none)', value: String(((Number(row.tax_rate_bps) || 0) / 100).toFixed(2)) },
                { key: 'label', label: 'What the tax is called on the invoice', placeholder: 'HST', value: row.tax_label || 'HST' },
              ],
              okLabel: 'Save changes',
            });
            if (ed === null) { return; }
            var amt = ed.amount;
            /* Strip currency symbols and thousands separators before validating —
               typing "$149.00" is normal and must not be rejected as non-numeric. */
            var clean = String(amt).replace(/[^0-9.\-]/g, '');
            if (clean === '' || isNaN(Number(clean)) || Number(clean) < 0) {
              window.alert('That is not a valid amount — nothing was changed.');
              return;
            }
            var dd = String(ed.due || '');
            dd = dd.trim();
            if (dd && !/^\d{4}-\d{2}-\d{2}$/.test(dd)) {
              window.alert('Due date must look like 2026-09-01 — nothing was changed.');
              return;
            }
            b.disabled = true;
            var rate = ed.rate;
            var bps = Math.round((parseFloat(String(rate).replace(/[^0-9.\-]/g, '')) || 0) * 100);
            var taxLabel = row.tax_label || '';
            if (bps > 0) {
              taxLabel = String(ed.label || '');
              taxLabel = taxLabel.trim();
              if (!taxLabel) {
                b.disabled = false;
                window.alert('A tax rate needs a name \u2014 nothing was changed.');
                return;
              }
            }
            /* subtotal_cents, not amount_cents: the server recomputes the tax and the
               total from these, so the three numbers on the invoice cannot disagree. */
            api('/platform/invoices/' + id, 'PATCH', {
              subtotal_cents: Math.round(Number(clean) * 100),
              tax_rate_bps: bps,
              tax_label: bps > 0 ? taxLabel : null,
              due_at: dd || null,
            }).then(function () { render(main); })
              .catch(function (err) { b.disabled = false; window.alert(err.message); });
            return;
          }

          if (act === 'delete') {
            if (!(await KT.confirm({
              title: 'Delete draft ' + (row.number || '') + ' permanently?',
              description: 'This removes it entirely. Only a draft that was never emailed can be '
                + 'deleted — anything a customer has seen must be voided instead, so the '
                + 'record of it survives.',
              okLabel: 'Delete draft', tone: 'danger',
            }))) { return; }
            b.disabled = true;
            api('/platform/invoices/' + id, 'DELETE')
              .then(function () { render(main); })
              .catch(function (err) { b.disabled = false; window.alert(err.message); });
            return;
          }

          b.disabled = true;
          if (act === 'pdf') {
            /* window.open cannot carry an Authorization header, so fetch the bytes
               and open them as a blob instead. */
            fetch(apiBase() + '/platform/invoices/' + id + '/pdf', {
              headers: { Authorization: 'Bearer ' + token(), Accept: 'application/pdf' },
            }).then(function (res) {
              if (!res.ok) { throw new Error('HTTP ' + res.status); }
              return res.blob();
            }).then(function (blob) {
              if (window.KT && KT.openFile) { KT.openFile(blob, { title: 'Invoice ' + id, filename: 'invoice-' + id + '.pdf', label: 'Platform invoice' }); b.disabled = false; return; }
              var url = URL.createObjectURL(blob);
              window.open(url, '_blank');
              /* Released once the tab has had time to read it — holding every blob
                 for the session would leak memory on a long-lived page. */
              setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
              b.disabled = false;
            }).catch(function (err) {
              b.disabled = false;
              if (KT.toast) { KT.toast('!', 'Could not open the PDF', err.message, '#DC2626'); }
              else { window.alert('Could not open the PDF: ' + err.message); }
            });

            return;
          }

          var path = '/platform/invoices/' + id + (act === 'issue' ? '/issue' : act === 'paid' ? '/mark-paid' : '/void');
          api(path, 'POST', act === 'paid' ? {} : null)
            .then(function () { render(main); })
            .catch(function (e) {
              b.disabled = false;
              if (KT.toast) { KT.toast('⚠️', 'Could not update', e.message, '#DC2626'); }
              else { window.alert(e.message); }
            });
        });
      });
    }).catch(function (e) {
      main.innerHTML = '<div style="padding:22px;color:#B91C1C;">Could not load invoices: '
        + esc(e.message) + '</div>';
    });
  }

  /* PAYMENTS (2026-10-25): what agencies reported (to confirm), then the whole ledger - payments, refunds and
     returned payments across every invoice. Refund / NSF actions live on the invoice's Lines & payments. */
  function paymentsSection(host, main) {
    host.innerHTML = '<div data-pend></div><div data-ledger><div style="color:#64748B;font-size:13px;padding:8px 0;">Loading payments…</div></div>';
    pendingPanel(host.querySelector('[data-pend]'), main);
    api('/platform/invoices/payments?all=1').then(function (d) {
      var rows = d.payments || [], M = d.methods || {};
      var ST = { confirmed: ['Received', '#166534', '#DCFCE7'], pending: ['To confirm', '#92400E', '#FEF3C7'], rejected: ['Not received', '#475569', '#F1F5F9'], returned: ['Returned (NSF)', '#991B1B', '#FEE2E2'], refund: ['Refund', '#6D28D9', '#EDE9FE'] };
      var TH = 'text-align:left;padding:8px 10px;font-size:11px;color:#6B7280;text-transform:uppercase;border-bottom:1px solid #E5E7EB;', TD = 'padding:8px 10px;border-bottom:1px solid #F1F5F9;';
      var h = '<div style="background:linear-gradient(180deg,#F5F9FF 0%,#FFFFFF 140px);border:1px solid #DCE7F3;border-radius:10px;padding:14px 16px;"><div style="font-weight:700;margin-bottom:8px;">Payment ledger</div>';
      if (!rows.length) h += '<div style="color:#64748B;font-size:13px;">No payments recorded yet.</div>';
      else {
        h += '<div style="overflow-x:auto;"><table data-kt-paginate="25" data-kt-no-kebab style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>'
          + ['Date', 'Agency', 'Invoice', 'Method', 'Amount', 'Receipt', 'Status'].map(function (t) { return '<th style="' + TH + '">' + t + '</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function (p) {
          var st = ST[p.status] || [p.status, '#475569', '#F1F5F9'];
          var neg = p.status === 'refund' || p.status === 'returned';
          h += '<tr><td style="' + TD + 'white-space:nowrap;">' + esc(fmtDate(p.paid_on)) + '</td><td style="' + TD + '">' + esc(p.agency_name || '') + '</td>'
            + '<td style="' + TD + 'font-family:ui-monospace,monospace;font-size:12px;">' + esc(p.number) + '</td>'
            + '<td style="' + TD + '">' + esc(M[p.method] || p.method) + (p.reference ? '<div style="font-size:11px;color:#94A3B8;">' + esc(p.reference) + '</div>' : '') + '</td>'
            + '<td style="' + TD + 'white-space:nowrap;font-variant-numeric:tabular-nums;' + (neg ? 'color:#B91C1C;' : '') + '">' + (neg ? '−' : '') + esc(money(p.amount_cents, p.currency || p.inv_currency)) + '</td>'
            + '<td style="' + TD + '">' + esc(p.receipt_number || '—') + '</td>'
            + '<td style="' + TD + '"><span style="display:inline-block;font-size:11px;font-weight:800;padding:2px 9px;border-radius:999px;color:' + st[1] + ';background:' + st[2] + ';">' + esc(st[0]) + '</span></td></tr>';
        });
        h += '</tbody></table></div>';
      }
      host.querySelector('[data-ledger]').innerHTML = h + '<div style="font-size:12px;color:#94A3B8;margin-top:8px;">Refunds and returned payments are recorded from an invoice\'s Lines &amp; payments.</div></div>';
    }).catch(function (e) { host.querySelector('[data-ledger]').innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /* PAYMENTS AGENCIES REPORTED (2026-10-25): confirm (receipt + email) or mark not received. */
  function pendingPanel(host, main) {
    /* Always open now: it lives on the Payments tab rather than behind a toggle button. */
    host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading…</div>';
    api('/platform/invoices/payments').then(function (d) {
      var rows = d.payments || [];
      var h = '<div style="background:linear-gradient(180deg,#F5F9FF 0%,#FFFFFF 140px);border:1px solid #DCE7F3;border-radius:10px;padding:14px 16px;margin:0 0 16px;"><div style="font-weight:700;margin-bottom:8px;">Payments to confirm' + (rows.length ? ' (' + rows.length + ')' : '') + '</div>';
      if (!rows.length) h += '<div style="color:#64748B;font-size:13px;">Nothing waiting. When an agency admin reports a payment from their Licence page, it appears here for you to confirm.</div>';
      rows.forEach(function (p) {
        h += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;border-top:1px solid #F1F5F9;font-size:13px;">'
          + '<b>' + esc(p.agency_name || '') + '</b><span>' + esc(p.number) + '</span><span style="font-weight:700;">' + esc(((p.amount_cents || 0) / 100).toFixed(2) + ' ' + (p.currency || 'CAD')) + '</span>'
          + '<span style="color:#64748B;">' + esc((d.methods || {})[p.method] || p.method) + (p.reference ? ' · ' + esc(p.reference) : '') + ' · ' + esc(String(p.paid_on).slice(0, 10)) + (p.reported_by_name ? ' · by ' + esc(p.reported_by_name) : '') + '</span>'
          + '<span style="flex:1"></span><button class="kt-btn kt-btn-sm" data-ok="' + p.id + '">Confirm received</button> <button class="kt-btn kt-btn-secondary kt-btn-sm" data-no="' + p.id + '">Not received</button></div>';
      });
      host.innerHTML = h + '</div>';
      host.querySelectorAll('[data-ok]').forEach(function (b) { b.onclick = function () { b.disabled = true; api('/platform/invoices/payments/' + b.getAttribute('data-ok') + '/confirm', 'POST', { notify: true }).then(function (r) { if (KT.toast) KT.toast('🧾', 'Confirmed', 'Receipt ' + (r.payment && r.payment.receipt_number || '') + (r.sent ? ' emailed' : ''), '#16A34A'); render(main); }).catch(function (e) { b.disabled = false; window.alert(e.message); }); }; });
      host.querySelectorAll('[data-no]').forEach(function (b) { b.onclick = async function () { var why = await KT.prompt({ title: 'Payment not received?', description: 'The agency admin who reported it is told.', fields: [{ key: 'value', label: 'Reason (optional)' }], okLabel: 'Mark not received' }); if (why === null) return; api('/platform/invoices/payments/' + b.getAttribute('data-no') + '/reject', 'POST', { reason: why || '' }).then(function () { render(main); }); }; });
    }).catch(function (e) { host.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /* BILLING EMAILS (2026-10-25): reminder cadence, who receives them, paid / receipt emails,
     prepayment discount, payment terms and instructions (App\Support\PlatformAccounts). */
  function billMailPanel(host) {
    /* Settings tab (was Emails & fees, renamed 2026-11-04), under the Recurring invoices card. */
    api('/platform/billing-emails').then(function (d) {
      var s = d.settings || {};
      var I = 'width:100%;box-sizing:border-box;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;';
      var chk = function (k, label) { return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;"><input type="checkbox" data-bm="' + k + '"' + (s[k] ? ' checked' : '') + '> ' + label + '</label>'; };
      var fld = function (label, inner) { return '<label style="display:block;"><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">' + label + '</span>' + inner + '</label>'; };
      host.innerHTML = '<div style="background:linear-gradient(180deg,#F5F9FF 0%,#FFFFFF 140px);border:1px solid #DCE7F3;border-radius:10px;padding:16px;margin:12px 0 16px;" data-kt-nopack="1">'
        + '<div style="font-weight:700;">Reminders &amp; receipts</div><div style="font-size:12.5px;color:#64748B;margin:2px 0 12px;">Sent from KiddieTrac Billing to each agency\'s billing contacts. A $0 invoice is never chased.</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px;align-items:end;">'
        + chk('reminders_enabled', 'Send due and overdue reminders') + chk('on_due', 'Email on the due date')
        + chk('payment_email', 'Email a receipt for each part payment') + chk('paid_email', 'Email the PAID invoice and receipt when paid in full')
        + fld('“Due soon” emails, days before', '<input data-bm="before_days" style="' + I + '" value="' + esc((s.before_days || []).join(', ')) + '" placeholder="7, 1">')
        + fld('Overdue emails, days after', '<input data-bm="overdue_days" style="' + I + '" value="' + esc((s.overdue_days || []).join(', ')) + '" placeholder="1, 7, 14, 30">')
        + fld('Who receives them', '<select data-bm="recipients" style="' + I + '">' + [['contact_and_admins', 'Billing contact and agency admins'], ['contact', 'Billing contact only'], ['admins', 'Agency admins only']].map(function (o) { return '<option value="' + o[0] + '"' + (s.recipients === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>')
        + fld('Also copy (optional)', '<input data-bm="cc" style="' + I + '" value="' + esc(s.cc || '') + '" placeholder="accounts@kiddietrac.com">')
        + fld('Prepay-the-year discount %', '<input data-bm="prepay_discount_pct" type="number" min="0" max="50" step="0.5" style="' + I + '" value="' + esc(s.prepay_discount_pct) + '">')
        + fld('Prepayment invoice due in (days)', '<input data-bm="prepay_due_days" type="number" min="1" max="90" style="' + I + '" value="' + esc(s.prepay_due_days) + '">')
        + '</div>'
        /* FEES (2026-10-25): late fees, returned payments (NSF) and surcharges by payment method. */
        + '<div style="font-weight:700;margin:16px 0 2px;">Late fees</div><div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">A % of the unpaid balance, added as a line on the same invoice (not taxed). Waive them from Lines &amp; payments.</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;align-items:end;">'
        + chk('late_fee_enabled', 'Charge late fees')
        + fld('Fee % of the unpaid balance', '<input data-bm="late_fee_pct" type="number" min="0" max="10" step="0.25" style="' + I + '" value="' + esc(s.late_fee_pct) + '">')
        + fld('First fee, days after due', '<input data-bm="late_fee_grace_days" type="number" min="0" max="90" style="' + I + '" value="' + esc(s.late_fee_grace_days) + '">')
        + fld('Then every (days)', '<input data-bm="late_fee_repeat_days" type="number" min="7" max="90" style="' + I + '" value="' + esc(s.late_fee_repeat_days) + '">')
        + fld('At most (fees per invoice)', '<input data-bm="late_fee_max" type="number" min="1" max="12" style="' + I + '" value="' + esc(s.late_fee_max) + '">')
        + fld('Minimum fee ($)', '<input data-bm="late_fee_min" type="number" min="0" step="0.01" style="' + I + '" value="' + esc(((Number(s.late_fee_min_cents) || 0) / 100).toFixed(2)) + '">')
        + '</div>'
        + '<div style="font-weight:700;margin:16px 0 2px;">Returned payments (NSF)</div><div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">Mark a payment returned from Lines &amp; payments: it stops counting, the invoice reopens and this fee is added (not taxed).</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;align-items:end;">'
        + chk('nsf_enabled', 'Charge an NSF fee')
        + fld('NSF fee ($)', '<input data-bm="nsf_fee" type="number" min="0" step="0.01" style="' + I + '" value="' + esc(((Number(s.nsf_fee_cents) || 0) / 100).toFixed(2)) + '">')
        + '</div>'
        + '<div style="font-weight:700;margin:16px 0 2px;">Surcharges by payment method</div><div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">Added as a line on the invoice when a payment by that method is recorded or confirmed, split out of the amount received. Card surcharges in Canada are capped at 2.4% and not allowed on debit.</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;align-items:end;">'
        + chk('surcharge_enabled', 'Charge surcharges') + chk('surcharge_taxable', 'Charge tax on the surcharge')
        + '</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:8px 18px;margin-top:8px;max-width:1100px;">'
        + Object.keys(METHOD_NAMES).map(function (k) {
            var sc = (s.surcharges || {})[k] || {};
            var B = 'box-sizing:border-box;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;flex:0 0 78px;width:78px;min-width:78px;';
            return '<div data-kt-nopack="1" style="display:flex;gap:6px;align-items:center;font-size:13px;">'
              + '<span style="flex:0 0 160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#334155;">' + esc(METHOD_NAMES[k]) + '</span>'
              + '<input data-sc="' + k + '" data-scf="pct" data-kt-nofit="1" type="number" min="0" max="10" step="0.1" style="' + B + '" value="' + esc(sc.pct || 0) + '" aria-label="' + esc(METHOD_NAMES[k]) + ' surcharge percent"><span style="color:#64748B;">%&nbsp;+&nbsp;$</span>'
              + '<input data-sc="' + k + '" data-scf="flat" data-kt-nofit="1" type="number" min="0" step="0.01" style="' + B + '" value="' + esc(((Number(sc.flat_cents) || 0) / 100).toFixed(2)) + '" aria-label="' + esc(METHOD_NAMES[k]) + ' flat surcharge"></div>';
          }).join('')
        + '</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-top:12px;">'
        + fld('Payment terms (the invoice PDF; emails and the portal leave out the new-agency prepayment line)', '<textarea data-bm="terms" rows="5" style="' + I + 'padding:8px 9px;">' + esc(s.terms || '') + '</textarea>')
        + fld('How to pay', '<textarea data-bm="payment_instructions" rows="5" style="' + I + 'padding:8px 9px;">' + esc(s.payment_instructions || '') + '</textarea>')
        + '</div><div style="margin-top:14px;position:sticky;bottom:0;background:#fff;padding:10px 0 2px;border-top:1px solid #EEF2F7;"><button class="kt-btn" id="kt-bm-save">Save settings</button></div></div>';
      host.querySelector('#kt-bm-save').onclick = function () {
        var out = {};
        host.querySelectorAll('[data-bm]').forEach(function (n) { var k = n.getAttribute('data-bm'); out[k] = n.type === 'checkbox' ? n.checked : n.value; });
        out.surcharges = {};
        host.querySelectorAll('[data-sc]').forEach(function (n) { var k = n.getAttribute('data-sc'); out.surcharges[k] = out.surcharges[k] || {}; out.surcharges[k][n.getAttribute('data-scf')] = Number(n.value) || 0; });
        api('/platform/billing-emails', 'PUT', out).then(function (r) { var s2 = (r && r.settings) || {}; SURCHARGE_NOTE = s2.surcharge_enabled ? surchargeText(s2) : ''; if (KT.toast) KT.toast('✓', 'Settings saved', '', '#16A34A'); })
          .catch(function (e) { window.alert(e.message); });
      };
    }).catch(function (e) { host.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  var SURCHARGE_NOTE = '';
  var METHOD_NAMES = { card: 'Card', eft: 'EFT / bank transfer', etransfer: 'Interac e-Transfer', pad: 'Pre-authorised debit', cheque: 'Cheque', other: 'Other' };
  function surchargeText(s) {
    var bits = Object.keys(s.surcharges || {}).map(function (k) { var v = s.surcharges[k]; return (METHOD_NAMES[k] || k) + ' ' + (v.pct ? v.pct + '%' : '') + (v.pct && v.flat_cents ? ' + ' : '') + (v.flat_cents ? '$' + (v.flat_cents / 100).toFixed(2) : ''); });
    return bits.length ? 'Surcharges: ' + bits.join(', ') + '.' : '';
  }
  function overlay(title, sub) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:2147483000;padding:16px;';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.innerHTML = '<div role="dialog" aria-modal="true" data-no-modal-guard="1" style="background:#fff;border-radius:14px;width:100%;max-width:820px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;">'
      + '<div style="padding:16px 20px 10px;border-bottom:1px solid #E5E7EB;display:flex;gap:10px;align-items:flex-start;"><div style="flex:1;min-width:0;"><div style="font-size:17px;font-weight:700;color:#0F172A;">' + esc(title) + '</div>'
      + '<div data-sub style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(sub || '') + '</div></div><button type="button" data-x aria-label="Close" title="Close (Esc)" style="width:30px;height:30px;border:0;background:transparent;border-radius:8px;font-size:17px;line-height:30px;color:#64748B;cursor:pointer;">✕</button></div>'
      + '<div data-body style="overflow:auto;padding:14px 20px;flex:1;"><div style="color:#64748B;font-size:13px;">Loading…</div></div></div>';
    document.body.appendChild(ov);
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    return ov;
  }

  /* NEW INVOICE (2026-10-25): a one-off for anything outside a licence renewal - set-up, training,
     hardware. Saved as a draft; Issue or Email invoice on its row when it is right. */
  function newInvoice(main) {
    var ov = overlay('New invoice', 'A one-off invoice, saved as a draft. Nothing is emailed until you send it.');
    var body = ov.querySelector('[data-body]');
    api('/platform/billing-plans').then(function (d) {
      var ags = (d.agencies || []).slice().sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
      var F = 'height:32px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;box-sizing:border-box;';
      var L = 'display:block;font-size:11.5px;color:#475569;font-weight:700;margin-bottom:3px;';
      var today = (KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10));
      var due = new Date(Date.UTC(+today.slice(0, 4), +today.slice(5, 7) - 1, +today.slice(8, 10) + 14, 12)).toISOString().slice(0, 10);
      body.innerHTML = '<div data-kt-nopack="1">'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">'
        + '<label style="grid-column:span 2;"><span style="' + L + '">Agency</span><select data-n="agency" style="' + F + 'width:100%;"><option value="">Choose…</option>'
        + ags.map(function (a) { return '<option value="' + a.id + '">' + esc(a.name) + '</option>'; }).join('') + '</select></label>'
        + '<label><span style="' + L + '">Due date</span><input data-n="due" type="date" value="' + due + '" style="' + F + 'width:100%;"></label>'
        + '<label><span style="' + L + '">Tax %</span><input data-n="rate" type="number" min="0" max="100" step="0.01" value="0" style="' + F + 'width:100%;"></label>'
        + '<label><span style="' + L + '">Tax name</span><input data-n="label" placeholder="HST" style="' + F + 'width:100%;"></label>'
        + '</div>'
        + '<div data-n="who" style="font-size:12.5px;color:#64748B;margin:8px 0 12px;"></div>'
        + '<table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>'
        + ['Description', 'Qty', 'Price', 'Taxed', 'Amount', ''].map(function (t) { return '<th style="text-align:left;padding:6px 6px;font-size:11px;color:#6B7280;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">' + t + '</th>'; }).join('')
        + '</tr></thead><tbody data-n="lines"></tbody></table>'
        + '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-n="addrow" style="margin-top:8px;">＋ Add a line</button>'
        + '<label style="display:block;margin-top:12px;"><span style="' + L + '">Note on the invoice (optional)</span><input data-n="notes" maxlength="1000" placeholder="e.g. Onboarding for the new Maple Street centre" style="' + F + 'width:100%;"></label>'
        + '<div style="display:flex;gap:10px;align-items:center;margin-top:14px;border-top:1px solid #E5E7EB;padding-top:12px;"><span data-n="sum" style="flex:1;font-size:13.5px;color:#334155;"></span>'
        + '<button class="kt-btn" data-n="save">Save draft</button></div></div>';
      var q = function (k) { return body.querySelector('[data-n="' + k + '"]'); };
      var cur = 'CAD';
      function row(desc, unit) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td style="padding:5px 6px;"><input data-l="desc" maxlength="255" value="' + esc(desc || '') + '" placeholder="e.g. Staff training (2 hours)" style="' + F + 'width:100%;min-width:180px;"></td>'
          + '<td style="padding:5px 6px;"><input data-l="qty" type="number" min="0.01" step="0.01" value="1" style="' + F + 'width:70px;"></td>'
          + '<td style="padding:5px 6px;"><input data-l="unit" type="number" step="0.01" value="' + (unit || '') + '" placeholder="0.00" style="' + F + 'width:100px;"></td>'
          + '<td style="padding:5px 6px;"><input data-l="tax" type="checkbox" data-kt-noswitch checked></td>'
          + '<td data-l="amt" style="padding:5px 6px;white-space:nowrap;font-variant-numeric:tabular-nums;"></td>'
          + '<td style="padding:5px 6px;text-align:right;"><button class="kt-btn kt-btn-secondary kt-btn-sm" data-l="rm" title="Remove line">✕</button></td>';
        q('lines').appendChild(tr);
        tr.querySelector('[data-l="rm"]').onclick = function () { if (q('lines').children.length > 1) { tr.remove(); paint(); } };
        paint();
      }
      function lines() {
        return Array.prototype.map.call(q('lines').children, function (tr) {
          var g = function (k) { return tr.querySelector('[data-l="' + k + '"]'); };
          return { tr: tr, description: g('desc').value.trim(), qty: Number(g('qty').value) || 1, unit: g('unit').value === '' ? NaN : Number(g('unit').value), taxable: g('tax').checked };
        });
      }
      function paint() {
        var bps = Math.round((Number(q('rate').value) || 0) * 100), sub = 0, tax = 0;
        lines().forEach(function (l) {
          var c = isNaN(l.unit) ? 0 : Math.round(l.qty * l.unit * 100);
          l.tr.querySelector('[data-l="amt"]').textContent = isNaN(l.unit) ? '—' : money(c, cur);
          sub += c; if (l.taxable && c > 0 && bps > 0) tax += Math.floor((c * bps + 5000) / 10000);
        });
        q('sum').innerHTML = 'Subtotal <b>' + money(sub, cur) + '</b> · ' + (q('label').value || 'Tax') + ' <b>' + money(tax, cur) + '</b> · Total <b style="font-size:15px;">' + money(sub + tax, cur) + '</b>';
      }
      q('agency').onchange = function () {
        var a = ags.filter(function (x) { return String(x.id) === q('agency').value; })[0];
        if (!a) { q('who').textContent = ''; return; }
        cur = a.plan_currency || 'CAD';
        q('rate').value = ((Number(a.tax_rate_bps) || 0) / 100).toFixed(2);
        q('label').value = a.tax_label || (a.tax_rate_bps ? 'HST' : '');
        q('who').innerHTML = 'Bill to: <b>' + esc(a.legal_name || a.name) + '</b>' + (a.contact_email ? ' · ' + esc(a.contact_email) : ' · <span style="color:#B45309;">no billing email</span>')
          + (a.billing_address_complete ? '' : ' · <span style="color:#B45309;">no address on file (Billing plans → View)</span>') + ' · ' + esc(cur);
        paint();
      };
      body.addEventListener('input', paint);
      body.addEventListener('change', paint);
      q('addrow').onclick = function () { row(); };
      row();
      q('save').onclick = function () {
        var aid = Number(q('agency').value);
        var ls = lines().filter(function (l) { return l.description || !isNaN(l.unit); });
        if (!aid) { if (KT.toast) KT.toast('⚠️', 'Choose the agency', '', '#B45309'); return; }
        if (!ls.length || ls.some(function (l) { return !l.description || isNaN(l.unit); })) { if (KT.toast) KT.toast('⚠️', 'Every line needs a description and a price', '', '#B45309'); return; }
        var bps = Math.round((Number(q('rate').value) || 0) * 100);
        if (bps > 0 && !q('label').value.trim()) { if (KT.toast) KT.toast('⚠️', 'A tax rate needs a name (HST, GST)', '', '#B45309'); return; }
        q('save').disabled = true;
        api('/platform/invoices', 'POST', { agency_id: aid, due_at: q('due').value || null, notes: q('notes').value.trim() || null, tax_rate_bps: bps, tax_label: q('label').value.trim() || null,
          lines: ls.map(function (l) { return { description: l.description, qty: l.qty, unit: l.unit, taxable: l.taxable }; }) })
          .then(function (r) { ov.remove(); if (KT.toast) KT.toast('🧾', 'Draft ' + (r.invoice && r.invoice.number || '') + ' created', 'Use Email invoice on its row to send it', '#16A34A'); activeTab = 'open'; render(main); })
          .catch(function (e) { q('save').disabled = false; window.alert(e.message); });
      };
    }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /* RECURRING INVOICES (2026-10-25): on/off for the platform, all agencies or only those ticked, the due-date rule
     (default: end of the month), auto-email and the BCC default. Per agency: the tick and issuing window (Details…). */
  function fmtTime(hm) { var p = String(hm || '06:00').split(':'), h = +p[0], m = p[1] || '00'; return (h % 12 || 12) + ':' + m + (h < 12 ? ' am' : ' pm'); }
  function recurringCard(box) {
    api('/platform/billing-recurring').then(function (r) {
      var I = 'height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;box-sizing:border-box;';
      box.innerHTML = '<div data-kt-nopack="1" style="background:linear-gradient(135deg,#EFF6FF 0%,#FFFFFF 60%);border:1px solid #BFDBFE;border-radius:12px;padding:14px 16px;margin-bottom:16px;">'
        + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><div style="flex:1;min-width:220px;"><div style="font-weight:800;color:#0F172A;">🔁 Recurring invoices</div>'
        + '<div style="font-size:12.5px;color:#475569;">Every day at <b data-r-time>' + esc(fmtTime(r.run_time || '06:00')) + '</b> (Toronto time) each included agency whose renewal or next invoice date has arrived gets an invoice. Agencies left out keep their licence rolling on with no invoice.</div></div>'
        + '<label style="display:flex;gap:8px;align-items:center;font-weight:800;font-size:14px;"><input type="checkbox" data-r="enabled"' + (r.enabled ? ' checked' : '') + '> On</label></div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-top:12px;align-items:end;">'
        + '<label><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">Raise invoices at (Toronto time)</span><input data-r="run_time" type="time" step="900" value="' + esc(r.run_time || '06:00') + '" style="' + I + 'width:100%;"></label>'
        + '<label><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">Which agencies</span><select data-r="scope" style="' + I + 'width:100%;"><option value="all"' + (r.scope === 'all' ? ' selected' : '') + '>All agencies</option><option value="selected"' + (r.scope === 'selected' ? ' selected' : '') + '>Only agencies I tick</option></select></label>'
        + '<label><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">Invoices are due</span><select data-r="due_rule" style="' + I + 'width:100%;"><option value="end_of_month"' + (r.due_rule === 'end_of_month' ? ' selected' : '') + '>At the end of the month</option><option value="days_after"' + (r.due_rule === 'days_after' ? ' selected' : '') + '>A number of days after the invoice date</option><option value="day_of_month"' + (r.due_rule === 'day_of_month' ? ' selected' : '') + '>On a day of the month</option></select></label>'
        + '<label data-show="days_after"><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">Days after</span><input data-r="due_days" type="number" min="0" max="120" value="' + esc(r.due_days) + '" style="' + I + 'width:100%;"></label>'
        + '<label data-show="day_of_month"><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">Day of the month (1–28)</span><input data-r="due_day" type="number" min="1" max="28" value="' + esc(r.due_day) + '" style="' + I + 'width:100%;"></label>'
        + '<label><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">BCC on invoices (default)</span><select data-r="bcc_default" style="' + I + 'width:100%;">' + [['none', 'No one'], ['superadmins', 'Superadmins'], ['sales', "The account's salesperson"], ['both', 'Superadmins + salesperson']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === r.bcc_default ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>'
        + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;height:30px;"><input type="checkbox" data-r="auto_email"' + (r.auto_email ? ' checked' : '') + '> Email them automatically (otherwise drafts)</label>'
        + '</div><div style="display:flex;gap:10px;align-items:center;margin-top:12px;"><button class="kt-btn kt-btn-sm" data-r-save>Save recurring invoices</button><span data-r-note style="font-size:12.5px;color:#475569;"></span></div></div>';
      var q = function (k) { return box.querySelector('[data-r="' + k + '"]'); };
      function vis() {
        box.querySelectorAll('[data-show]').forEach(function (n) { n.style.display = n.getAttribute('data-show') === q('due_rule').value ? '' : 'none'; });
        box.querySelector('[data-r-note]').textContent = 'An invoice raised today would be due ' + (r.example_due ? fmtDate(r.example_due) : '…') + (q('due_rule').value !== r.due_rule ? ' (save to update)' : '') + '.';
      }
      q('due_rule').addEventListener('change', vis); vis();
      box.querySelector('[data-r-save]').onclick = function () {
        var body = { run_time: (q('run_time').value || '06:00').slice(0, 5), enabled: q('enabled').checked, scope: q('scope').value, due_rule: q('due_rule').value, due_days: Number(q('due_days').value || 14), due_day: Number(q('due_day').value || 1), auto_email: q('auto_email').checked, bcc_default: q('bcc_default').value };
        (body.auto_email && !r.auto_email ? KT.confirm({ title: 'Email invoices automatically?', description: 'Invoices will be emailed to agencies as soon as they are raised.', okLabel: 'Turn on' }) : Promise.resolve(true)).then(function (ok) { if (!ok) return;
        api('/platform/billing-recurring', 'PUT', body).then(function () { if (KT.toast) KT.toast('✓', 'Recurring invoices saved', body.enabled ? (body.scope === 'all' ? 'On for every agency' : 'On for the agencies you tick') : 'Off', '#16A34A'); recurringCard(box); })
          .catch(function (e) { window.alert(e.message); });
        });
      };
    }).catch(function (e) { box.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /* EDIT INVOICE (2026-10-25): every line editable - description, quantity, price, taxed - lines added or removed, plus
     due date, tax and note; totals live. For any invoice not paid or void. Offers to email the revised copy. */
  function editInvoice(id, main) {
    var ov = overlay('Edit invoice', 'Loading…');
    var body = ov.querySelector('[data-body]');
    api('/platform/invoices/' + id + '/detail').then(function (d) {
      var inv = d.invoice || {}, cur = inv.currency || 'CAD';
      ov.querySelector('[data-sub]').textContent = (inv.number || '') + ' · ' + (inv.agency_name || '') + (d.customer_number ? ' · ' + d.customer_number : '') + ' · ' + inv.status + ((Number(inv.amount_paid_cents) || 0) ? ' · ' + money(inv.amount_paid_cents, cur) + ' already paid' : '');
      var F = 'height:32px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;box-sizing:border-box;';
      var L = 'display:block;font-size:11.5px;color:#475569;font-weight:700;margin-bottom:3px;';
      body.innerHTML = '<div data-kt-nopack="1">'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">'
        + '<label><span style="' + L + '">Due date</span><input data-e="due" type="date" value="' + esc(String(inv.due_at || '').slice(0, 10)) + '" style="' + F + 'width:100%;"></label>'
        + '<label><span style="' + L + '">Tax %</span><input data-e="rate" type="number" min="0" max="100" step="0.01" value="' + ((Number(inv.tax_rate_bps) || 0) / 100).toFixed(2) + '" style="' + F + 'width:100%;"></label>'
        + '<label><span style="' + L + '">Tax name</span><input data-e="label" value="' + esc(inv.tax_label || '') + '" placeholder="HST" style="' + F + 'width:100%;"></label>'
        + '<label style="grid-column:span 2;"><span style="' + L + '">Reason for the change (kept in the audit log)</span><input data-e="reason" maxlength="200" placeholder="e.g. Corrected the training hours" style="' + F + 'width:100%;"></label>'
        + '</div>'
        + '<table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;"><thead><tr>'
        + ['Description', 'Part #', 'Qty', 'Unit price', 'Taxed', 'Amount', ''].map(function (t) { return '<th style="text-align:left;padding:6px;font-size:11px;color:#6B7280;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">' + t + '</th>'; }).join('')
        + '</tr></thead><tbody data-e="lines"></tbody></table>'
        + '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-e="add" style="margin-top:8px;">＋ Add a line</button>'
        + '<label style="display:block;margin-top:12px;"><span style="' + L + '">Note on the invoice</span><input data-e="notes" maxlength="1000" value="' + esc(inv.notes || '') + '" style="' + F + 'width:100%;"></label>'
        + '<div style="display:flex;gap:10px;align-items:center;margin-top:14px;border-top:1px solid #E5E7EB;padding-top:12px;"><span data-e="sum" style="flex:1;font-size:13.5px;color:#334155;"></span><button class="kt-btn" data-e="save">Save changes</button></div></div>';
      var q = function (k) { return body.querySelector('[data-e="' + k + '"]'); };
      function row(l) {
        var tr = document.createElement('tr');
        if (l.id) tr.setAttribute('data-id', l.id);
        tr.innerHTML = '<td style="padding:5px 6px;"><input data-l="desc" maxlength="255" value="' + esc(l.description || '') + '" title="' + esc(l.description || '') + '" style="' + F + 'width:100%;min-width:280px;"></td>'
          + '<td style="padding:5px 6px;"><input data-l="part" maxlength="20" value="' + esc(l.part_number || '') + '" style="' + F + 'width:128px;font-family:ui-monospace,monospace;font-size:12px;"></td>'
          + '<td style="padding:5px 6px;"><input data-l="qty" type="number" min="0.01" step="0.01" value="' + esc(Number(l.qty || 1)) + '" style="' + F + 'width:70px;"></td>'
          + '<td style="padding:5px 6px;"><input data-l="unit" type="number" step="0.01" value="' + (l.unit_cents != null ? (Number(l.unit_cents) / 100).toFixed(2) : '') + '" style="' + F + 'width:110px;"></td>'
          + '<td style="padding:5px 6px;text-align:center;"><input data-l="tax" type="checkbox" data-kt-noswitch' + (l.taxable ? ' checked' : '') + '></td>'
          + '<td data-l="amt" style="padding:5px 6px;white-space:nowrap;font-variant-numeric:tabular-nums;"></td>'
          + '<td style="padding:5px 6px;text-align:right;"><button class="kt-btn kt-btn-secondary kt-btn-sm" data-l="rm" title="Remove line">✕</button></td>';
        q('lines').appendChild(tr);
        tr.querySelector('[data-l="rm"]').onclick = function () { if (q('lines').children.length > 1) { tr.remove(); paint(); } };
      }
      function lines() {
        return Array.prototype.map.call(q('lines').children, function (tr) {
          var g = function (k) { return tr.querySelector('[data-l="' + k + '"]'); };
          return { tr: tr, id: tr.getAttribute('data-id') ? Number(tr.getAttribute('data-id')) : null, description: g('desc').value.trim(), part_number: g('part').value.trim() || null,
            qty: Number(g('qty').value) || 1, unit: g('unit').value === '' ? NaN : Number(g('unit').value), taxable: g('tax').checked };
        });
      }
      function paint() {
        var bps = Math.round((Number(q('rate').value) || 0) * 100), sub = 0, tax = 0;
        lines().forEach(function (l) { var c = isNaN(l.unit) ? 0 : Math.round(l.qty * l.unit * 100); l.tr.querySelector('[data-l="amt"]').textContent = isNaN(l.unit) ? '—' : money(c, cur); sub += c; if (l.taxable && c > 0 && bps > 0) tax += Math.floor((c * bps + 5000) / 10000); });
        var paid = Number(inv.amount_paid_cents) || 0;
        q('sum').innerHTML = 'Subtotal <b>' + money(sub, cur) + '</b> · ' + esc(q('label').value || 'Tax') + ' <b>' + money(tax, cur) + '</b> · Total <b style="font-size:15px;">' + money(sub + tax, cur) + '</b>' + (paid ? ' · balance <b>' + money(Math.max(0, sub + tax - paid), cur) + '</b>' : '');
      }
      (d.lines || []).forEach(row);
      if (!(d.lines || []).length) row({ description: 'KiddieTrac subscription', qty: 1, unit_cents: inv.subtotal_cents, taxable: (Number(inv.tax_rate_bps) || 0) > 0 });
      paint();
      body.addEventListener('input', paint); body.addEventListener('change', paint);
      q('add').onclick = function () { row({ qty: 1, taxable: (Number(q('rate').value) || 0) > 0 }); paint(); };
      q('save').onclick = function () {
        var ls = lines();
        if (ls.some(function (l) { return !l.description || isNaN(l.unit); })) { if (KT.toast) KT.toast('⚠️', 'Every line needs a description and a price', '', '#B45309'); return; }
        var bps = Math.round((Number(q('rate').value) || 0) * 100);
        if (bps > 0 && !q('label').value.trim()) { if (KT.toast) KT.toast('⚠️', 'A tax rate needs a name (HST, GST)', '', '#B45309'); return; }
        q('save').disabled = true;
        api('/platform/invoices/' + id + '/edit', 'PUT', { lines: ls.map(function (l) { return { id: l.id, description: l.description, part_number: l.part_number, qty: l.qty, unit: l.unit, taxable: l.taxable }; }),
          due_at: q('due').value || null, notes: q('notes').value.trim() || null, tax_rate_bps: bps, tax_label: q('label').value.trim() || null, reason: q('reason').value.trim() || null })
          .then(async function (r) {
            ov.remove(); render(main);
            if (KT.toast) KT.toast('✓', 'Invoice ' + r.invoice.number + ' saved', 'Total ' + money(r.invoice.amount_cents, cur), '#16A34A');
            if (r.invoice.status !== 'draft') {
              var to = await KT.prompt({ title: 'Email the revised invoice?', description: 'Sends the updated PDF. Leave it if you will send it later.', fields: [{ key: 'to', label: 'Send to', type: 'email', value: r.invoice.agency_email || '' }], okLabel: 'Send revised invoice' });
              if (to && String(to).trim()) api('/platform/invoices/' + id + '/email', 'POST', { to: String(to).trim() }).then(function () { if (KT.toast) KT.toast('✉', 'Revised invoice sent', String(to).trim(), '#16A34A'); }).catch(function (e) { window.alert(e.message); });
            }
          })
          .catch(function (e) { q('save').disabled = false; window.alert(e.message); });
      };
    }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /* REFUND (2026-10-25): full or partial, with the amount, a reason for the agency and internal notes; the invoice is
     credited (stays paid) or left owing. Refunds come from the newest payments first. */
  async function refundInvoice(row, main) {
    var cur = row.currency || 'CAD', paid = Number(row.amount_paid_cents) || 0;
    var pr = await KT.prompt({ title: 'Refund ' + (row.number || 'this invoice'), description: money(paid, cur) + ' has been paid on this invoice. Record the refund here, then send the money back (card terminal, e-Transfer or cheque).',
      fields: [
        { key: 'mode', label: 'Refund', type: 'select', value: 'full', options: [{ value: 'full', label: 'Full refund (' + money(paid, cur) + ')' }, { value: 'partial', label: 'Partial refund' }] },
        { key: 'amount', label: 'Amount for a partial refund (' + cur + ')', type: 'number', step: '0.01', value: (paid / 100).toFixed(2) },
        { key: 'reason', label: 'Reason (shown to the agency)', placeholder: 'e.g. Billed twice for October' },
        { key: 'notes', label: 'Internal notes (not shown to the agency)', placeholder: 'Optional' },
        { key: 'credit', label: 'The invoice', type: 'select', value: '1', options: [{ value: '1', label: 'Credit it by the refund (stays paid)' }, { value: '0', label: 'Leave it as it is (the amount is owing again)' }] },
        { key: 'notify', label: 'Tell the agency', type: 'select', value: '1', options: [{ value: '1', label: 'Yes, email the refund receipt' }, { value: '0', label: 'No, just record it' }] },
      ], okLabel: 'Record refund' });
    if (pr === null) return;
    if (!String(pr.reason || '').trim()) { if (KT.toast) KT.toast('⚠️', 'Give a reason for the refund', '', '#B45309'); return; }
    var amt = Number(String(pr.amount || '').replace(/[^0-9.]/g, ''));
    if (pr.mode === 'partial' && !(amt > 0)) { if (KT.toast) KT.toast('⚠️', 'Enter the amount to refund', '', '#B45309'); return; }
    api('/platform/invoices/' + row.id + '/refund', 'POST', { mode: pr.mode, amount: pr.mode === 'partial' ? amt : null, reason: String(pr.reason).trim(), notes: String(pr.notes || '').trim() || null, credit: pr.credit === '1', notify: pr.notify === '1' })
      .then(function (r) { if (KT.toast) KT.toast('↩️', 'Refunded ' + money(r.refunded_cents, cur), (r.receipts || []).join(', ') + (r.sent ? ' · agency emailed' : ''), '#7C3AED'); render(main); })
      .catch(function (e) { window.alert(e.message); });
  }

  /* LINES & PAYMENTS for one invoice (2026-10-25). */
  function manageInvoice(id, main) {
    var ov = overlay('Lines & payments', '');
    var body = ov.querySelector('[data-body]');
    var KIND = { late: 'Late fee', nsf: 'NSF fee', surcharge: 'Surcharge', extra: 'Extra', manual: 'Added', credit: 'Credit' };
    function load() {
      api('/platform/invoices/' + id + '/detail').then(function (d) {
        var inv = d.invoice || {}, cur = inv.currency || 'CAD', open = inv.status !== 'paid' && inv.status !== 'void';
        ov.querySelector('[data-sub]').textContent = (inv.number || '') + ' · ' + (inv.agency_name || '') + (d.customer_number ? ' · ' + d.customer_number : '') + ' · total ' + money(inv.amount_cents, cur) + ' · paid ' + money(inv.amount_paid_cents, cur) + ' · balance ' + money(d.balance_cents, cur) + ' · ' + inv.status;
        var T = 'width:100%;border-collapse:collapse;font-size:13px;', TH = 'text-align:left;padding:7px 8px;font-size:11px;color:#6B7280;text-transform:uppercase;border-bottom:1px solid #E5E7EB;', TD = 'padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;';
        var hasLate = (d.lines || []).some(function (l) { return l.kind === 'late' || l.part_number === 'KT-LATE'; });
        var h = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><b style="flex:1;">Lines</b>'
          + (open && hasLate ? '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-waive>Waive late fees</button>' : '') + '</div>'
          + '<table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="' + T + '"><thead><tr><th style="' + TH + '">Item</th><th style="' + TH + '">Qty</th><th style="' + TH + 'text-align:right;">Amount</th><th style="' + TH + 'text-align:right;">Tax</th><th style="' + TH + '"></th></tr></thead><tbody>';
        if (!(d.lines || []).length) h += '<tr><td colspan="5" style="' + TD + 'color:#64748B;">Billed as one amount (' + money(inv.subtotal_cents, cur) + ' + tax ' + money(inv.tax_cents, cur) + ').</td></tr>';
        (d.lines || []).forEach(function (l) {
          var canRemove = open && ['manual', 'extra', 'nsf'].indexOf(l.kind) !== -1;
          h += '<tr><td style="' + TD + '">' + (l.kind && KIND[l.kind] ? '<span style="font-size:10.5px;font-weight:800;color:#475569;background:#F1F5F9;border-radius:999px;padding:1px 7px;margin-right:6px;">' + KIND[l.kind] + '</span>' : '')
            + esc(l.description) + (l.part_number ? ' <span style="color:#94A3B8;font-size:11px;">' + esc(l.part_number) + '</span>' : '') + '</td>'
            + '<td style="' + TD + '">' + esc(String(Number(l.qty))) + '</td><td style="' + TD + 'text-align:right;">' + money(l.amount_cents, cur) + '</td><td style="' + TD + 'text-align:right;color:#64748B;">' + (Number(l.tax_cents) ? money(l.tax_cents, cur) : '—') + '</td>'
            + '<td style="' + TD + 'text-align:right;">' + (canRemove ? '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-rm="' + l.id + '">' + (l.kind === 'nsf' ? 'Waive' : 'Remove') + '</button>' : '') + '</td></tr>';
        });
        h += '</tbody></table>';
        if (open) {
          var F = 'height:32px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;box-sizing:border-box;';
          h += '<div data-kt-nopack="1" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 18px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px;">'
            + '<input data-nl="description" placeholder="Add a line, e.g. Extra training session" maxlength="255" style="' + F + 'flex:1 1 220px;">'
            + '<input data-nl="qty" type="number" min="0.01" step="0.01" value="1" title="Quantity" style="' + F + 'width:70px;">'
            + '<input data-nl="unit" type="number" step="0.01" placeholder="Price $" style="' + F + 'width:100px;">'
            + '<label style="font-size:12.5px;display:flex;gap:5px;align-items:center;"><input type="checkbox" data-nl="taxable" checked> Taxed</label>'
            + '<button class="kt-btn kt-btn-sm" data-add>Add line</button></div>';
        } else {
          h += '<div style="font-size:12.5px;color:#64748B;margin:10px 0 18px;">This invoice is ' + esc(inv.status) + ', so its lines are fixed.</div>';
        }
        h += '<b>Payments</b><table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="' + T + 'margin-top:6px;"><thead><tr><th style="' + TH + '">Paid on</th><th style="' + TH + '">Method</th><th style="' + TH + 'text-align:right;">Amount</th><th style="' + TH + '">Receipt</th><th style="' + TH + '">Status</th><th style="' + TH + '"></th></tr></thead><tbody>';
        if (!(d.payments || []).length) h += '<tr><td colspan="6" style="' + TD + 'color:#64748B;">No payments yet.</td></tr>';
        (d.payments || []).forEach(function (p) {
          var st = p.status === 'refund' ? '<span style="color:#7C3AED;font-weight:700;">Refund</span>' + (p.note ? ' <span style="color:#94A3B8;">(' + esc(String(p.note).replace(/\s*\[of #\d+\]/, '')) + ')</span>' : '') : p.status === 'returned' ? '<span style="color:#B91C1C;font-weight:700;">Returned</span>' + (p.return_reason ? ' <span style="color:#94A3B8;">(' + esc(p.return_reason) + ')</span>' : '') : esc(p.status);
          h += '<tr><td style="' + TD + '">' + esc(fmtDate(p.paid_on)) + '</td><td style="' + TD + '">' + esc((d.methods || {})[p.method] || p.method) + (p.reference ? ' · ' + esc(p.reference) : '') + '</td>'
            + '<td style="' + TD + 'text-align:right;">' + money(p.amount_cents, p.currency || cur) + '</td><td style="' + TD + '">' + esc(p.receipt_number || '—') + '</td><td style="' + TD + '">' + st + '</td>'
            + '<td style="' + TD + 'text-align:right;white-space:nowrap;">' + (p.status === 'confirmed' ? '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-refund="' + p.id + '" data-amt="' + p.amount_cents + '">Refund…</button> <button class="kt-btn kt-btn-secondary kt-btn-sm" data-ret="' + p.id + '">Returned (NSF)…</button>' : '') + '</td></tr>';
        });
        body.innerHTML = h + '</tbody></table>';

        var add = body.querySelector('[data-add]');
        if (add) add.onclick = function () {
          var g = function (k) { return body.querySelector('[data-nl="' + k + '"]'); };
          var desc = g('description').value.trim(), unit = Number(g('unit').value);
          if (!desc || !g('unit').value || isNaN(unit)) { if (KT.toast) KT.toast('⚠️', 'Enter a description and a price', '', '#B45309'); return; }
          add.disabled = true;
          api('/platform/invoices/' + id + '/lines', 'POST', { description: desc, qty: Number(g('qty').value) || 1, unit: unit, taxable: g('taxable').checked })
            .then(function (r) { if (KT.toast) KT.toast('✓', 'Line added', 'Invoice total ' + money(r.amount_cents, cur), '#16A34A'); load(); render(main); })
            .catch(function (e) { add.disabled = false; window.alert(e.message); });
        };
        body.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = async function () {
          if (!(await KT.confirm({ title: 'Take this line off the invoice?', okLabel: 'Remove line', tone: 'danger' }))) return;
          api('/platform/invoices/' + id + '/lines/' + b.getAttribute('data-rm'), 'DELETE').then(function () { load(); render(main); }).catch(function (e) { window.alert(e.message); });
        }; });
        var wv = body.querySelector('[data-waive]');
        if (wv) wv.onclick = async function () {
          if (!(await KT.confirm({ title: 'Waive every late fee on this invoice?', okLabel: 'Waive late fees' }))) return;
          api('/platform/invoices/' + id + '/waive-late-fees', 'POST', {}).then(function (r) { if (KT.toast) KT.toast('✓', 'Late fees waived', money(r.waived_cents, cur) + ' taken off', '#16A34A'); load(); render(main); }).catch(function (e) { window.alert(e.message); });
        };
        body.querySelectorAll('[data-refund]').forEach(function (b) { b.onclick = async function () {
          var pr = await KT.prompt({ title: 'Refund this payment?', description: 'The agency is emailed a refund receipt. Send the money back yourself (card terminal, e-Transfer or cheque); this records it.',
            fields: [{ key: 'amount', label: 'Amount to refund (' + cur + ')', type: 'number', step: '0.01', value: (Number(b.getAttribute('data-amt')) / 100).toFixed(2) },
              { key: 'reason', label: 'Reason (shown to the agency)', placeholder: 'e.g. Duplicate payment' },
              { key: 'credit', label: 'The invoice', type: 'select', value: '1', options: [{ value: '1', label: 'Credit the invoice by the same amount (stays paid)' }, { value: '0', label: 'Leave the invoice as it is (the amount is owing again)' }] },
              { key: 'notify', label: 'Tell the agency', type: 'select', value: '1', options: [{ value: '1', label: 'Yes, email the refund receipt' }, { value: '0', label: 'No, just record it' }] }],
            okLabel: 'Record refund' });
          if (pr === null) return;
          var amt = Number(String(pr.amount || '').replace(/[^0-9.]/g, ''));
          if (!(amt > 0)) { if (KT.toast) KT.toast('⚠️', 'Enter the amount to refund', '', '#B45309'); return; }
          api('/platform/invoices/payments/' + b.getAttribute('data-refund') + '/refund', 'POST', { amount: amt, reason: (pr.reason || '').trim() || null, credit: pr.credit === '1', notify: pr.notify === '1' })
            .then(function (r) { if (KT.toast) KT.toast('↩️', 'Refund recorded', (r.refund && r.refund.receipt_number || '') + (r.sent ? ' · agency emailed' : ''), '#7C3AED'); load(); render(main); })
            .catch(function (e) { window.alert(e.message); });
        }; });
        body.querySelectorAll('[data-ret]').forEach(function (b) { b.onclick = async function () {
          var pr = await KT.prompt({ title: 'Mark this payment returned?', description: 'For a payment that came back unpaid (NSF, a stopped cheque or a reversed debit). It stops counting and the invoice reopens.',
            fields: [{ key: 'reason', label: 'Reason (shown to the agency)', value: 'NSF' },
              { key: 'fee', label: 'Returned-payment fee', type: 'select', value: d.nsf_enabled ? '1' : '0', options: [{ value: '1', label: 'Add the NSF fee (' + money(d.nsf_fee_cents, cur) + ')' }, { value: '0', label: 'No fee' }] },
              { key: 'notify', label: 'Tell the agency', type: 'select', value: '1', options: [{ value: '1', label: 'Yes, email the updated invoice' }, { value: '0', label: 'No, just record it' }] }],
            okLabel: 'Mark returned' });
          if (pr === null) return;
          api('/platform/invoices/payments/' + b.getAttribute('data-ret') + '/return', 'POST', { reason: (pr.reason || '').trim() || null, charge_fee: pr.fee === '1', notify: pr.notify === '1' })
            .then(function (r) { if (KT.toast) KT.toast('↩️', 'Payment marked returned', (r.fee_cents ? 'NSF fee ' + money(r.fee_cents, cur) + ' added' : 'No fee') + (r.sent ? ' · agency emailed' : ''), '#B45309'); load(); render(main); })
            .catch(function (e) { window.alert(e.message); });
        }; });
      }).catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
    }
    load();
  }

  /* EXTRA LINE ITEMS (2026-10-25, Anthony: "option to add new line items to invoices each month when
     required"): per agency, on every invoice or just the next one, added when the invoice is raised. */
  function extrasPanel(host) {
    /* Always open on the Extra line items tab (2026-10-25). */
    host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading…</div>';
    api('/platform/billing-extras').then(function (d) {
      var F = 'height:32px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;box-sizing:border-box;';
      var h = '<div style="background:linear-gradient(180deg,#F5F9FF 0%,#FFFFFF 140px);border:1px solid #DCE7F3;border-radius:10px;padding:14px 16px;margin:12px 0 16px;" data-kt-nopack="1">'
        + '<div style="font-weight:700;">Extra line items</div><div style="font-size:12.5px;color:#64748B;margin:2px 0 10px;">Added to an agency\'s invoice when it is raised: every invoice, or just the next one. For a one-off on an invoice already raised, use Lines &amp; payments on that invoice.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px;margin-bottom:12px;">'
        + '<select data-ex="agency_id" style="' + F + 'flex:0 1 200px;"><option value="">Agency…</option>' + (d.agencies || []).map(function (a) { return '<option value="' + a.id + '">' + esc(a.name) + '</option>'; }).join('') + '</select>'
        + '<input data-ex="description" maxlength="255" placeholder="e.g. Extra centre, SMS bundle" style="' + F + 'flex:1 1 200px;">'
        + '<input data-ex="qty" type="number" min="0.01" step="0.01" value="1" title="Quantity" style="' + F + 'width:70px;">'
        + '<input data-ex="unit" type="number" step="0.01" placeholder="Price $" style="' + F + 'width:100px;">'
        + '<select data-ex="recurring" style="' + F + '"><option value="1">Every invoice</option><option value="0">Next invoice only</option></select>'
        + '<input data-ex="ends_on" type="date" title="Stop after (optional)" style="' + F + 'width:150px;">'
        + '<label style="font-size:12.5px;display:flex;gap:5px;align-items:center;"><input type="checkbox" data-ex="taxable" checked> Taxed</label>'
        + '<button class="kt-btn kt-btn-sm" data-exadd>Add</button></div>';
      var rows = d.extras || [];
      if (!rows.length) h += '<div style="color:#64748B;font-size:13px;">No extra line items yet.</div>';
      else {
        h += '<table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>'
          + ['Agency', 'Item', 'Amount', 'When', 'Used', ''].map(function (t) { return '<th style="text-align:left;padding:7px 8px;font-size:11px;color:#6B7280;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">' + t + '</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function (x) {
          var TD = 'padding:7px 8px;border-bottom:1px solid #F1F5F9;' + (Number(x.active) ? '' : 'color:#94A3B8;');
          h += '<tr><td style="' + TD + '">' + esc(x.agency_name || '') + '</td><td style="' + TD + '">' + esc(x.description) + (Number(x.taxable) ? '' : ' <span style="font-size:11px;">(not taxed)</span>') + '</td>'
            + '<td style="' + TD + '">' + money(Math.round((Number(x.qty) || 1) * x.unit_cents), x.currency || 'CAD') + (Number(x.qty) !== 1 ? ' <span style="color:#94A3B8;font-size:11px;">(' + Number(x.qty) + ' × ' + money(x.unit_cents, x.currency || 'CAD') + ')</span>' : '') + '</td>'
            + '<td style="' + TD + '">' + (Number(x.recurring) ? 'Every invoice' : 'Next invoice only') + (x.ends_on ? ', until ' + esc(fmtDate(x.ends_on)) : '') + (Number(x.active) ? '' : ' · ' + (Number(x.recurring) ? 'paused' : 'done')) + '</td>'
            + '<td style="' + TD + '">' + (Number(x.applied_count) || 0) + '×</td>'
            + '<td style="' + TD + 'text-align:right;white-space:nowrap;">' + (Number(x.recurring) ? '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-extog="' + x.id + '" data-on="' + (Number(x.active) ? 0 : 1) + '">' + (Number(x.active) ? 'Pause' : 'Resume') + '</button> ' : '')
            + '<button class="kt-btn kt-btn-secondary kt-btn-sm" data-exdel="' + x.id + '">Delete</button></td></tr>';
        });
        h += '</tbody></table>';
      }
      host.innerHTML = h + '</div>';
      var reopen = function () { extrasPanel(host); };
      host.querySelector('[data-exadd]').onclick = function () {
        var g = function (k) { return host.querySelector('[data-ex="' + k + '"]'); };
        var body = { agency_id: Number(g('agency_id').value), description: g('description').value.trim(), qty: Number(g('qty').value) || 1, unit: Number(g('unit').value),
          recurring: g('recurring').value === '1', taxable: g('taxable').checked, ends_on: g('ends_on').value || null };
        if (!body.agency_id || !body.description || !g('unit').value) { if (KT.toast) KT.toast('⚠️', 'Pick the agency, and enter a description and a price', '', '#B45309'); return; }
        api('/platform/billing-extras', 'POST', body).then(function () { if (KT.toast) KT.toast('✓', 'Extra line item added', 'It goes on that agency\'s next invoice', '#16A34A'); reopen(); }).catch(function (e) { window.alert(e.message); });
      };
      host.querySelectorAll('[data-extog]').forEach(function (b) { b.onclick = function () { api('/platform/billing-extras/' + b.getAttribute('data-extog'), 'PATCH', { active: b.getAttribute('data-on') === '1' }).then(reopen).catch(function (e) { window.alert(e.message); }); }; });
      host.querySelectorAll('[data-exdel]').forEach(function (b) { b.onclick = async function () {
        if (!(await KT.confirm({ title: 'Delete this extra line item?', description: 'Invoices it is already on keep it.', okLabel: 'Delete', tone: 'danger' }))) return;
        api('/platform/billing-extras/' + b.getAttribute('data-exdel'), 'DELETE').then(reopen).catch(function (e) { window.alert(e.message); });
      }; });
    }).catch(function (e) { host.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  if (Shell && Shell.registerScreen) {
    /* platform_admin only, matching the API guard. Registering it for any other role
       would put a menu entry in front of someone the endpoint will 403. */
    Shell.registerScreen('platform_admin:sales-invoices', render);
  }
  KT.PlatformInvoices = { render: render };
})(window);
