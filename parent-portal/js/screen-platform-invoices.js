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

  function statTile(label, value, colour) {
    return '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;">'
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
  var INTERVAL_LABEL = { weekly: 'Weekly', biweekly: 'Every 2 weeks', monthly: 'Monthly', quarterly: 'Quarterly', annual: 'Yearly' };

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
        + ' · ' + (noAddr ? '<span style="color:#B45309;">no address on file (Billing plans → Business…)</span>' : esc(x.address)) + '</div></span></label>'
        + '<div data-fields style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:10px;">'
        + '<label><span style="' + L + '">Price before tax (' + esc(cur) + ')</span><input data-f="sub" value="' + ((Number(x.subtotal_cents) || 0) / 100).toFixed(2) + '" style="width:100%;' + F + '"></label>'
        + '<label><span style="' + L + '">Tax %</span><input data-f="bps" value="' + ((Number(x.tax_rate_bps) || 0) / 100).toFixed(2) + '" style="width:100%;' + F + '"></label>'
        + '<label><span style="' + L + '">Tax name</span><input data-f="label" value="' + esc(x.tax_label || '') + '" placeholder="HST" style="width:100%;' + F + '"></label>'
        + '<label><span style="' + L + '">Due date</span><input data-f="due" type="date" value="' + esc(x.due_at || '') + '" style="width:100%;' + F + '"></label>'
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

  function planEditor(host) {
    host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading plans…</div>';

    api('/platform/billing-plans').then(function (data) {
      var rows = data.agencies || [];
      var currencies = data.currencies || ['CAD', 'USD'];
      var intervals = data.intervals || ['weekly', 'biweekly', 'monthly', 'quarterly', 'annual'];
      var INP = 'height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:6px;font-size:13px;';

      var h = '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;'
        + 'padding:16px;margin-bottom:18px;">'
        + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">'
        + '<div><div style="font-weight:600;">Billing plans</div>'
        + '<div style="color:#64748B;font-size:12px;margin-top:2px;max-width:60ch;">'
        + 'What each agency is charged, how often, and in which currency. An agency with no '
        + 'next invoice date is not on recurring billing and will never be raised automatically.'
        + '</div></div>'
        + '<button id="kt-plans-close" class="kt-btn kt-btn-secondary kt-btn-sm">Close</button></div>'
        + '<div style="overflow-x:auto;margin-top:14px;">'
        + '<table data-kt-no-kebab data-kt-no-controls data-kt-no-filter style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="text-align:left;color:#64748B;font-size:11px;text-transform:uppercase;">'
        + '<th style="padding:8px 10px;">Agency</th><th style="padding:8px 10px;">Price</th>'
        + '<th style="padding:8px 10px;">Currency</th><th style="padding:8px 10px;">Every</th>'
        + '<th style="padding:8px 10px;">Tax</th><th style="padding:8px 10px;">Total</th>'
        + '<th style="padding:8px 10px;">Next invoice</th><th style="padding:8px 10px;"></th></tr></thead><tbody>';

      rows.forEach(function (a) {
        var cur = a.plan_currency || 'CAD';
        h += '<tr data-plan="' + esc(a.id) + '" style="border-top:1px solid #F1F5F9;">'
          + '<td style="padding:8px 10px;font-weight:600;">' + esc(a.name)
          + '<div style="font-weight:400;color:#94A3B8;font-size:11px;">'
          + esc(a.billing_status || 'no status') + '</div></td>'
          + '<td style="padding:8px 10px;"><input data-f="amount" value="'
          + esc(((Number(a.plan_amount_cents) || 0) / 100).toFixed(2))
          + '" style="width:86px;' + INP + '"></td>'
          + '<td style="padding:8px 10px;"><select data-f="currency" style="' + INP + '">'
          + currencies.map(function (c) {
              return '<option value="' + esc(c) + '"' + (c === cur ? ' selected' : '') + '>' + esc(c) + '</option>';
            }).join('')
          + '</select></td>'
          + '<td style="padding:8px 10px;"><select data-f="interval" style="' + INP + '">'
          + intervals.map(function (i) {
              return '<option value="' + esc(i) + '"' + (i === a.billing_interval ? ' selected' : '') + '>'
                + esc(INTERVAL_LABEL[i] || (i.charAt(0).toUpperCase() + i.slice(1))) + '</option>';
            }).join('')
          + '</select></td>'
          + '<td style="padding:8px 10px;white-space:nowrap;">'
          + '<input data-f="taxrate" value="' + esc(((Number(a.tax_rate_bps) || 0) / 100).toFixed(2))
          + '" style="width:58px;' + INP + '">% '
          + '<input data-f="taxlabel" placeholder="HST" value="' + esc(a.tax_label || '')
          + '" style="width:66px;' + INP + '"></td>'
          + '<td data-f="total" style="padding:8px 10px;white-space:nowrap;font-weight:600;">'
          + esc(money(a.total_cents, cur)) + '</td>'
          + '<td style="padding:8px 10px;"><input data-f="next" type="date" value="'
          + esc(a.next_invoice_at || '') + '" style="' + INP + '">'
          + (a.next_invoice_at ? ''
              : '<div style="color:#B45309;font-size:11px;margin-top:2px;">not recurring</div>')
          + '</td>'
          + '<td style="padding:8px 10px;text-align:right;">'
          + '<button data-biz="' + esc(a.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm" '
              + 'style="margin-right:6px;">Business\u2026</button>'
          + '<button data-save="' + esc(a.id) + '" class="kt-btn kt-btn-sm">Save</button></td></tr>'

          /* Hidden until asked for. Saved by the row's own Save button above, so the
             price and the address are always written together. */
          + '<tr data-biz-row="' + esc(a.id) + '" style="display:none;">'
          + '<td colspan="8" style="padding:0 10px 14px;background:#F8FAFC;">'
          + '<div style="font-size:11px;font-weight:700;letter-spacing:1px;color:#64748B;'
              + 'text-transform:uppercase;margin:12px 0 3px;">Business details on the invoice</div>'
          + '<div style="font-size:12px;color:#64748B;margin-bottom:10px;">'
          + 'Printed in the Bill To block. Anything left blank is simply omitted \u2014 no '
          + 'placeholder or empty line appears on the invoice.</div>'
          + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;">'
          + bizField(a, 'legal_name', 'Registered name', a.name || '')
          + bizField(a, 'address_line1', 'Address line 1', '123 Main Street')
          + bizField(a, 'address_line2', 'Address line 2', 'Suite 4')
          + bizField(a, 'city', 'City', 'Toronto')
          + bizField(a, 'province', 'Province / State', 'ON')
          + bizField(a, 'postal_code', 'Postal code', 'M5V 1A1')
          + bizField(a, 'country', 'Country', 'Canada')
          + bizField(a, 'tax_registration', 'Their tax registration no.', '12345 6789 RT0001')
          + bizField(a, 'contact_email', 'Billing email', 'accounts@example.com')
          + bizField(a, 'contact_phone', 'Phone', '416 555 0100')
          + bizField(a, 'website', 'Website', 'example.com')
          + '</div></td></tr>';
      });

      h += '</tbody></table></div>'
        + '<div id="kt-auto" style="margin-top:12px;padding:10px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;color:#475569;">Loading automation\u2026</div>'
        + '</div>';
      host.innerHTML = h;
      autoRow(host.querySelector('#kt-auto'));

      /* Label above input. The placeholder carries an EXAMPLE, never a default —
         a greyed-out address that looks filled in is how a placeholder ends up
         printed on somebody's invoice. */
      function bizField(a, key, label, example) {
        var v = a[key] == null ? '' : String(a[key]);
        return '<label style="display:block;">'
          + '<span style="display:block;font-size:11.5px;color:#475569;margin-bottom:3px;">'
          + esc(label) + '</span>'
          + '<input data-biz-f="' + esc(key) + '" value="' + esc(v) + '" '
          + 'placeholder="' + esc(example) + '" '
          + 'style="width:100%;box-sizing:border-box;height:30px;padding:0 8px;'
          + 'border:1px solid #CBD5E1;border-radius:6px;font-size:13px;"></label>';
      }

      function cents(el) {
        return Math.round((parseFloat(String(el.value).replace(/[^0-9.\-]/g, '')) || 0) * 100);
      }

      /* The total updates as the price or rate is typed, so the tax is visible BEFORE
         saving rather than as a surprise on the first invoice. */
      host.querySelectorAll('tr[data-plan]').forEach(function (tr) {
        function recalc() {
          var amt = cents(tr.querySelector('[data-f=amount]'));
          var bps = cents(tr.querySelector('[data-f=taxrate]'));
          tr.querySelector('[data-f=total]').textContent =
            money(amt + Math.round(amt * bps / 10000), tr.querySelector('[data-f=currency]').value);
        }
        tr.querySelectorAll('input,select').forEach(function (el) {
          el.addEventListener('input', recalc);
          el.addEventListener('change', recalc);
        });
      });

      host.querySelectorAll('button[data-biz]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-biz');
          var row = host.querySelector('tr[data-biz-row="' + id + '"]');
          var opening = row.style.display === 'none';
          /* One at a time — every row expanded turns the table into a wall of inputs. */
          host.querySelectorAll('tr[data-biz-row]').forEach(function (r) { r.style.display = 'none'; });
          host.querySelectorAll('button[data-biz]').forEach(function (o) { o.textContent = 'Business\u2026'; });
          if (opening) {
            row.style.display = '';
            b.textContent = 'Hide';
            var first = row.querySelector('input');
            if (first) { first.focus(); }
          }
        });
      });

      var closeBtn = host.querySelector('#kt-plans-close');
      if (closeBtn) { closeBtn.addEventListener('click', function () { host.innerHTML = ''; }); }

      /* Reads whatever the details row holds, open or closed — collapsing it must not
         discard an edit the person has already typed. */
      function bizValues(host, id) {
        var out = {};
        var row = host.querySelector('tr[data-biz-row="' + id + '"]');
        if (!row) { return out; }
        row.querySelectorAll('input[data-biz-f]').forEach(function (el) {
          var v = el.value.trim();
          /* Empty clears the field rather than being skipped, so a wrong line can
             actually be removed from the invoice. */
          out[el.getAttribute('data-biz-f')] = v === '' ? null : v;
        });
        return out;
      }

      host.querySelectorAll('button[data-save]').forEach(function (b) {
        b.addEventListener('click', function () {
          var tr = b.closest('tr');
          var bps = cents(tr.querySelector('[data-f=taxrate]'));
          var label = tr.querySelector('[data-f=taxlabel]').value.trim();
          /* Checked here as well as on the server: an unnamed tax prints as a bare "Tax"
             line on a real invoice, which will not do in a jurisdiction that requires it
             named. Failing at the field is kinder than failing after Save. */
          if (bps > 0 && !label) {
            window.alert('A tax rate needs a name for the invoice — HST, GST, VAT, Sales tax.');
            return;
          }
          b.disabled = true;
          /* Business details ride in the SAME request as the plan. Two separate saves
             would let you write the address and silently lose the price typed above it. */
          api('/platform/billing-plans/' + b.getAttribute('data-save'), 'PUT',
            Object.assign(bizValues(host, b.getAttribute('data-save')), {
            plan_amount_cents: cents(tr.querySelector('[data-f=amount]')),
            plan_currency: tr.querySelector('[data-f=currency]').value,
            billing_interval: tr.querySelector('[data-f=interval]').value,
            tax_rate_bps: bps,
            tax_label: label || null,
            next_invoice_at: tr.querySelector('[data-f=next]').value || null,
          })).then(function () {
            b.disabled = false;
            b.textContent = 'Saved';
            setTimeout(function () { b.textContent = 'Save'; }, 1800);
          }).catch(function (err) {
            b.disabled = false;
            window.alert(err.message);
          });
        });
      });
    }).catch(function (err) {
      host.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + esc(err.message) + '</div>';
    });
  }

  /* Which status group the table is showing. Open = owed and not yet settled, which
     is the only group that represents outstanding work, so it is the default. */
  var TABS = [
    { key: 'open', label: 'Open', match: function (r) { return r.status !== 'paid' && r.status !== 'void'; } },
    { key: 'paid', label: 'Paid', match: function (r) { return r.status === 'paid'; } },
    { key: 'void', label: 'Voided', match: function (r) { return r.status === 'void'; } },
  ];
  var activeTab = 'open';

  function render(main) {
    main.innerHTML = '<div style="padding:22px;color:#64748B;">Loading invoices…</div>';

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
          + statTile(cur + ' billed', money(t.billed_cents, cur))
          + statTile(cur + ' collected', money(t.paid_cents, cur), '#166534')
          + statTile(cur + ' outstanding', money(outstanding, cur), outstanding > 0 ? '#B45309' : '#0F172A')
          + statTile(cur + ' tax billed', money(tax, cur))
          + statTile(cur + ' overdue', String(overdue), overdue > 0 ? '#B91C1C' : '#0F172A')
          + '</div>';
      }

      var byId = {};
      var html = ''
        /* Emoji + h2 + p, and NOT ONE inline style — the shared .kt-page-hero CSS owns
           how a banner looks, and overriding it here is what made this screen the odd
           one out. The controls sit BELOW the banner, as they do everywhere else. */
        + '<div class="kt-hero">'
        + '<h2>\uD83E\uDDFE Invoices</h2>'
        + '<p>What agencies owe KiddieTrac for the platform.</p>'
        + '</div>'
        + '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;">'
        + '<button id="kt-raise" class="kt-btn">Raise invoices due now</button>'
        + '<button id="kt-plans" class="kt-btn kt-btn-secondary">Billing plans</button>'
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
      ['Invoice', 'Agency', 'Period', 'Amount', 'Due', 'Status', ''].forEach(function (h) {
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
          && String(r.due_at) < new Date().toISOString().slice(0, 10);

        html += '<tr data-inv="' + esc(r.id) + '" style="border-top:1px solid #E5E7EB;' + dim + '">'
          + '<td style="padding:11px 14px;font-weight:600;font-family:ui-monospace,monospace;font-size:12.5px;">'
          + esc(r.number) + '</td>'
          + '<td style="padding:11px 14px;">' + esc(r.agency_name || '—') + '</td>'
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
        if (r.status === 'draft') {
          html += '<button data-act="issue" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Issue</button> ';
        }
        /* Email is offered on anything not void, including already-issued invoices —
           resending a copy is the single most common thing anyone needs to do. */
        if (!isVoid) {
          html += '<button data-act="email" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Email invoice</button> ';
        }
        if (r.status !== 'paid' && !isVoid) {
          html += '<button data-act="paid" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Mark paid</button> ';
        }
        if (!isVoid && r.status !== 'paid') {
          html += '<button data-act="void" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Void</button> ';
        }
        /* Edit and Delete are DRAFT-ONLY, and Delete additionally requires that the
           draft was never emailed. Once a customer holds a PDF of an invoice, the row
           behind it stops being ours to rewrite or erase — that is what Void is for.
           The server enforces both rules; this only avoids offering a dead button. */
        if (r.status === 'draft') {
          html += '<button data-act="edit" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-sm">Edit invoice</button> ';
          if (!r.sent_at) {
            html += '<button data-act="delete" data-id="' + esc(r.id) + '" class="kt-btn kt-btn-secondary kt-btn-sm">Delete draft</button>';
          }
        }
        html += '</td></tr>';
        byId[String(r.id)] = r;
      });

      html += '</tbody></table>'
        + '<div style="font-size:12px;color:#94A3B8;margin-top:12px;">'
        + 'Issuing records the date — it does not email anything. Send with '
        + '<code>php artisan platform:send-invoice &lt;number&gt; --to=…</code></div>';

      }

      /* ONE write and ONE wiring pass, whether or not there are rows. The tab bar and the
         header buttons live above the table, so they must be wired even when it is empty —
         otherwise voiding the last open invoice leaves a screen nothing can click out of. */
      main.innerHTML = html;

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
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-id');
          var act = b.getAttribute('data-act');

          /* Void and mark-paid change what is owed, so they are confirmed. Issuing
             only stamps a date and is reversible by voiding. */
          if (act === 'void' && !window.confirm('Void this invoice? It stays on record but stops counting as owed.')) { return; }
          if (act === 'paid' && !window.confirm('Mark this invoice paid in full?')) { return; }

          var row = byId[String(id)] || {};

          if (act === 'email') {
            /* The address is NEVER inferred. It is prefilled from the agency contact so
               this is one keystroke, but a person reads it before a customer is emailed —
               a wrong address here bills the wrong company. */
            var to = window.prompt(
              'Email invoice ' + (row.number || '') + ' to:\n\n'
                + 'The PDF is attached. A draft becomes issued once it is sent.',
              row.agency_email || ''
            );
            if (to === null) { return; }
            to = to.trim();
            if (!to) { window.alert('No address given — nothing was sent.'); return; }
            b.disabled = true;
            api('/platform/invoices/' + id + '/email', 'POST', { to: to })
              .then(function () {
                if (KT.toast) { KT.toast('\u2709', 'Invoice sent', 'Emailed to ' + to, '#16A34A'); }
                render(main);
              })
              .catch(function (err) { b.disabled = false; window.alert(err.message); });
            return;
          }

          if (act === 'edit') {
            var cur = (Number(row.subtotal_cents) || Number(row.amount_cents) || 0) / 100;
            var amt = window.prompt('Amount BEFORE tax for ' + (row.number || 'this invoice')
              + ' (' + (row.currency || 'CAD') + '):', String(cur.toFixed(2)));
            if (amt === null) { return; }
            /* Strip currency symbols and thousands separators before validating —
               typing "$149.00" is normal and must not be rejected as non-numeric. */
            var clean = String(amt).replace(/[^0-9.\-]/g, '');
            if (clean === '' || isNaN(Number(clean)) || Number(clean) < 0) {
              window.alert('That is not a valid amount — nothing was changed.');
              return;
            }
            var dd = window.prompt('Due date (YYYY-MM-DD), or blank for none:', row.due_at || '');
            if (dd === null) { return; }
            dd = dd.trim();
            if (dd && !/^\d{4}-\d{2}-\d{2}$/.test(dd)) {
              window.alert('Due date must look like 2026-09-01 — nothing was changed.');
              return;
            }
            b.disabled = true;
            var rate = window.prompt('Tax rate % (0 for none):',
              String(((Number(row.tax_rate_bps) || 0) / 100).toFixed(2)));
            if (rate === null) { b.disabled = false; return; }
            var bps = Math.round((parseFloat(String(rate).replace(/[^0-9.\-]/g, '')) || 0) * 100);
            var taxLabel = row.tax_label || '';
            if (bps > 0) {
              taxLabel = window.prompt('What is that tax called on the invoice?', taxLabel || 'HST');
              if (taxLabel === null) { b.disabled = false; return; }
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
            if (!window.confirm('Delete draft ' + (row.number || '') + ' permanently?\n\n'
              + 'This removes it entirely. Only a draft that was never emailed can be '
              + 'deleted — anything a customer has seen must be voided instead, so the '
              + 'record of it survives.')) { return; }
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

  if (Shell && Shell.registerScreen) {
    /* platform_admin only, matching the API guard. Registering it for any other role
       would put a menu entry in front of someone the endpoint will 403. */
    Shell.registerScreen('platform_admin:sales-invoices', render);
  }
  KT.PlatformInvoices = { render: render };
})(window);
