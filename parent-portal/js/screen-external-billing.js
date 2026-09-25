/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Synced accounting (invoices from a connected external system).
   Agency-wide view of every invoice pulled LIVE from the external platform
   via the Integration API. Admins + directors only — parents see
   their own under Billing. Read-only mirror; KiddieTrac never collects
   payment on these. GET /agency/external-invoices.
   Registered for agency_admin / centre_director / platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n, ccy) {
    var v = Number(n) || 0;
    try { return v.toLocaleString('en-CA', { style: 'currency', currency: ccy || 'CAD' }); }
    catch (e) { return '$' + v.toFixed(2); }
  }
  /* A DATE-ONLY STRING IS A DAY, NOT AN INSTANT (2026-09-17).

     `new Date('2026-09-25')` is parsed as UTC midnight, and toLocaleDateString then reads
     it back in the browser's zone — so west of Greenwich every plain date came out a day
     early. The Sanford schedule stores issue 2026-09-20 / due 2026-09-25 and this table
     printed "Sep 19" and "Sep 24": every issue and due date in Accounting was wrong by a
     day, in the direction that makes an invoice look later than it is.

     Anchoring on the LEADING YYYY-MM-DD, not on the whole string being that shape, is
     the part that took two goes. `due_at` comes back as '2026-09-25' but `issued_at` as
     '2026-09-20 00:00:00' — same column meaning, different SQL type — and
     `.replace(' ', 'T')` turned the second into '2026-09-20T00:00:00', which
     kt-tz-global.js parses as UTC midnight: Sep 19 in Toronto. Fixing only the bare form
     corrected the due dates and left every issue date still a day early.

     Both fields here are CALENDAR DAYS, whatever their SQL type, and fmtDate is called on
     nothing else in this file — so the date part is what is read and the time component
     is ignored rather than converted through a timezone it never had.
     See [[kiddietrac-date-only-utc-parse]] — every screen-local fmtDate is a suspect. */
  function fmtDate(s) {
    if (!s) return '—';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s).trim());
    var d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(s);
    return d.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
  }
  /* EVERY STATUS HAS A LABEL, AND NONE OF THEM IS RAW (2026-09-17).

     Anthony: "under accounting section the status is still wrong."

     The map held five entries — paid, void, overdue, partial, scheduled — and anything
     else fell through to `t: status`, printing the DATABASE VALUE straight into the
     badge. Three of the statuses actually in these tables were not in the map, so the
     table showed lowercase `sent`, `draft` and `upcoming` next to properly-cased Paid and
     Overdue. Measured across the three tables this list unions:

       external_invoices : paid, open, void, overdue
       invoices          : draft, sent, paid, void, overdue, partial
       payee_invoices    : upcoming

     DRAFT IS "PENDING", the same word the payment-schedules table now uses. On a schedule
     a draft is not an unfinished document, it is a future instalment waiting its turn, and
     the two screens showing the same invoice must not call it two different things.

     SCHEDULED STAYS SCHEDULED. It is derived, not stored — KT.invoiceStatus turns an
     issued-but-not-yet-due invoice into it — and it answers the question this ledger is
     for: is this owed yet? ([[kiddietrac-invoice-scheduled-status]]).

     The fallback now title-cases whatever it is given, so a status nobody anticipated
     still reads as a label rather than as a column name that leaked. */
  /* The pill comes from KT.invoicePill (kt-polish.js), beside KT.invoiceStatus, so
     Accounting and the payment schedules cannot drift into calling one state two things
     — which they already had, one saying "Partial" where the other said "Partly paid".

     The local map is kept only as a fallback for a cached kt-polish.js that predates the
     helper; it renders the same words in the same colours rather than something older. */
  function statusBadge(status, isOpen, dueAt) {
    if (window.KT && KT.invoicePill) { return KT.invoicePill(status, dueAt); }
    var label = String(status || '\u2014');
    return '<span style="background:#F1F5F9;color:#475569;border:1px solid #CBD5E1;padding:2px 10px;'
      + 'border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;">' + esc(label) + '</span>';
  }

  var state = { page: 1, family_id: 0, search: '', sort: '', dir: 'asc', busy: false, counterparty: '' };

  /* WHAT THIS AGENCY CALLS THE PEOPLE IT PAYS.

     `payee_invoices.kind` stores 'educator' — a stored value, never renamed — but iLearn
     calls them Providers and Test Agency calls them Educators, and the agency already
     chose which in Settings ("Facilities are called: Centres / Rooms / Providers",
     agencies.settings.centre_term). kt-term.js caches that answer in sessionStorage, so
     the label follows the agency with nothing new to configure. (2026-09-17) */
  function payeeLabel() {
    var t = '';
    try { t = sessionStorage.getItem('kt_centre_term') || ''; } catch (e) {}
    return t === 'provider' ? 'Provider' : 'Educator';
  }

  /* AND THE LABEL HAS TO SURVIVE A COLD LOAD.

     kt-term.js fetches the term once per session and caches it; on the first load of a
     session this screen can build its filter row before that answer arrives, and iLearn
     — which is set to "Providers" — read "Educators" until the next navigation. So the
     option is re-labelled after every list load, and the term is fetched here if nothing
     has cached it yet. Cheap: /agency/centre-term is readable by any role and the result
     is shared through the same sessionStorage key kt-term.js uses. */
  async function relabelPayeeOption(container) {
    try {
      var cached = '';
      try { cached = sessionStorage.getItem('kt_centre_term') || ''; } catch (e) {}
      if (!cached) {
        var r = await Api.get('/agency/centre-term');
        if (r && r.term) {
          try { sessionStorage.setItem('kt_centre_term', r.term); } catch (e) {}
        }
      }
      var opt = container.querySelector('#xb-party option[value="educator"]');
      if (opt) { opt.textContent = payeeLabel() + 's'; }
    } catch (e) { /* the default label is already correct for most agencies */ }
  }

  function statCard(label, value, sub, c1, c2, ink, tint) {
    return '<div style="background:' + tint + ';border:1px solid rgba(15,23,42,.06);border-radius:16px;padding:16px 17px;">'
      + '<div style="font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + ink + ';opacity:.72;">' + esc(label) + '</div>'
      + '<div style="font-size:30px;font-weight:900;line-height:1.05;color:' + ink + ';margin-top:6px;">' + value + '</div>'
      + '<div style="font-size:11.5px;color:#64748b;margin-top:3px;">' + esc(sub) + '</div></div>';
  }

  /* EDITING A KIDDIETRAC INVOICE: WHO IT IS FOR, AND WHAT IS ON IT (2026-09-17).

     Anthony: "under accounting section with invoices that are scheduled or overdue or due
     with the edit function and the popup add ability to add a line item to add or deduct
     additional charges with a description and to add optional tax. the pop up should show
     all the info on the parent and their child and if multiple child show this info as
     well."

     A SEPARATE DIALOG FROM openInvoiceEdit(). That one patches `external_invoices` — the
     synced iLearn copy — and says so at the top, because the next sync overwrites it. A
     KiddieTrac invoice is not in that table; it is ours, it has real line items, and
     editing it is authoritative rather than provisional. Pointing the existing dialog at
     both would have meant one form with two meanings.

     THE PEOPLE COME FIRST. The old dialog showed a family name and nothing else, so
     somebody adding a charge could not see whom they were billing or which children it
     covered — and a family with three children looked exactly like a family with one. */
  /* RECORDING MONEY THAT CAME IN OUTSIDE THE RAILS (2026-09-17).

     Anthony: "add the resend invoice and manual paid functions for those parents that
     paid via cash of EFT outside of zum rails etc so a popup comes up to confirm payment
     with reference number and allow for partial payment."

     One dialog for both kinds of invoice: ours posts to /director/invoices/{id}/payments,
     a synced one to /agency/external-invoices/{id}/payments, and from the office's side
     taking $200 in cash is the same act either way.

     THE AMOUNT IS PREFILLED TO THE BALANCE, because paying in full is the common case
     and retyping a figure that is already on screen invites a typo. It stays editable,
     and a smaller figure is accepted without complaint - a family paying half now is
     ordinary, not an error to warn about.

     THE REFERENCE IS WHAT MAKES THIS AUDITABLE six months later: an e-Transfer number or
     a cheque number is how somebody reconciles a bank statement against this row. Asked
     for, never required, because cash has no reference and demanding one would push
     people to invent one. */
  function openRecordPayment(container, inv) {
    var kind = inv.kt_source === 'kiddietrac' ? 'kt' : 'ext';
    var balance = Number(inv.balance_due != null ? inv.balance_due : inv.total) || 0;
    var cur = inv.currency || 'CAD';

    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;'
      + 'display:flex;align-items:flex-start;justify-content:center;padding:32px 18px;overflow:auto;';
    var m = document.createElement('div');
    m.style.cssText = 'background:#fff;border-radius:16px;max-width:480px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.35);';
    ov.appendChild(m); document.body.appendChild(ov);

    var close = function () { document.removeEventListener('keydown', onKey); ov.remove(); };
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (ev) { if (ev.target === ov) { close(); } });

    var fld = 'width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;'
      + 'font-size:14px;box-sizing:border-box;font-family:inherit;';
    var lab = 'display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:10px;';
    var today = (window.KT && KT.agencyToday && KT.agencyToday()) || new Date().toISOString().slice(0, 10);

    m.innerHTML =
      '<div style="padding:16px 22px;border-bottom:1px solid #E5E7EB;display:flex;align-items:flex-start;gap:12px;">'
      + '<div style="flex:1;min-width:0;"><h3 style="margin:0;font-size:17px;color:#0F172A;">Record a payment</h3>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(inv.number || '')
      + ' · ' + esc(inv.family || '') + '</div></div>'
      + '<button type="button" id="rp-x" title="Close" aria-label="Close" style="border:none;background:#F1F5F9;'
      + 'color:#475569;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:17px;line-height:1;">✕</button></div>'

      + '<div style="padding:16px 22px;">'
      + '<div style="display:flex;justify-content:space-between;background:#F8FAFC;border:1px solid #E2E8F0;'
      + 'border-radius:10px;padding:10px 13px;margin-bottom:14px;font-size:13px;color:#475569;">'
      + '<span>Invoice total</span><strong style="color:#0F172A;">' + money(inv.total, cur) + '</strong></div>'
      + '<div style="display:flex;justify-content:space-between;background:#FFF7ED;border:1px solid #FED7AA;'
      + 'border-radius:10px;padding:10px 13px;margin-bottom:16px;font-size:13px;color:#7C2D12;">'
      + '<span>Outstanding</span><strong>' + money(balance, cur) + '</strong></div>'

      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
      + '<label style="' + lab + '">Amount being settled'
      + '<input id="rp-amt" type="number" step="0.01" min="0.01" value="' + balance.toFixed(2) + '" style="' + fld + '"></label>'
      + '<label style="' + lab + '">How it was paid'
      + '<select id="rp-method" style="' + fld + '">'
      + '<option value="e_transfer">e-Transfer / EFT</option>'
      + '<option value="cash">Cash</option>'
      + '<option value="cheque">Cheque</option>'
      + '<option value="bank_transfer">Bank transfer</option>'
      + '<option value="credit_card_offline">Card (taken offline)</option>'
      + '<option value="other">Other</option>'
      + '</select></label></div>'

      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
      + '<label style="' + lab + '">Date received'
      + '<input id="rp-date" type="date" value="' + esc(String(today).slice(0, 10)) + '" style="' + fld + '"></label>'
      + '<label style="' + lab + '">Reference number'
      + '<input id="rp-ref" type="text" maxlength="120" placeholder="e-Transfer / cheque no." style="' + fld + '"></label></div>'

      + '<label style="' + lab + '">Note (optional)'
      + '<input id="rp-note" type="text" maxlength="500" placeholder="Anything worth remembering" style="' + fld + '"></label>'

      /* THE FEE, SHOWN BEFORE IT IS CHARGED. Off by default: a surcharge is a charge,
         and adding one because a rate happens to be configured would bill families
         nobody decided to bill. Hidden entirely when the method costs nothing. */
      + '<label id="rp-surwrap" hidden style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;'
      + 'font-size:13px;color:#334155;line-height:1.5;background:#F8FAFC;border:1px solid #E2E8F0;'
      + 'border-radius:10px;padding:10px 12px;margin:4px 0 10px;">'
      + '<input type="checkbox" id="rp-sur" style="margin-top:2px;">'
      + '<span id="rp-surtext"></span></label>'
      + '<div id="rp-msg" style="font-size:12.5px;min-height:18px;margin:2px 0 8px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;">'
      + '<button type="button" id="rp-cancel" style="padding:9px 16px;border:1px solid #E2E8F0;border-radius:9px;'
      + 'background:#fff;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button type="button" id="rp-save" class="kt-btn kt-btn-primary" style="padding:9px 18px;">Record payment</button>'
      + '</div></div>';

    m.querySelector('#rp-x').addEventListener('click', close);
    m.querySelector('#rp-cancel').addEventListener('click', close);

    /* Says what the payment WILL leave owing, as it is typed. A partial payment is
       allowed; the point is that nobody records one by accident. */
    var amtEl = m.querySelector('#rp-amt');
    var msgEl = m.querySelector('#rp-msg');
    var reflect = function () {
      var a = parseFloat(amtEl.value);
      if (isNaN(a) || a <= 0) { msgEl.textContent = ''; return; }
      var left = Math.round((balance - a) * 100) / 100;
      if (left > 0.005) {
        msgEl.style.color = '#B45309';
        msgEl.textContent = 'Partial payment — ' + money(left, cur) + ' will still be outstanding.';
      } else if (left < -0.005) {
        msgEl.style.color = '#DC2626';
        msgEl.textContent = 'That is ' + money(-left, cur) + ' more than is owed on this invoice.';
      } else {
        msgEl.style.color = '#16A34A';
        msgEl.textContent = 'Pays the invoice in full.';
      }
    };
    amtEl.addEventListener('input', reflect);
    reflect();

    /* The rates are the agency's, fetched once; a failure just leaves the option
       hidden rather than blocking a payment over a preference. */
    var surRates = { card: 0, eft: 0 };
    var CARD = ['credit_card_offline'];
    var EFT = ['e_transfer', 'bank_transfer'];
    var surWrap = m.querySelector('#rp-surwrap');
    var surBox = m.querySelector('#rp-sur');
    var surText = m.querySelector('#rp-surtext');
    var methodEl = m.querySelector('#rp-method');

    function surPercent() {
      var v = methodEl.value;
      if (CARD.indexOf(v) !== -1) { return Number(surRates.card) || 0; }
      if (EFT.indexOf(v) !== -1) { return Number(surRates.eft) || 0; }
      return 0;
    }

    function paintSurcharge() {
      var pct = surPercent();
      var amt = parseFloat(amtEl.value);
      if (!(pct > 0) || isNaN(amt) || amt <= 0) {
        surWrap.hidden = true; surBox.checked = false; return;
      }
      var fee = Math.round(amt * (pct / 100) * 100) / 100;
      surWrap.hidden = false;
      surText.innerHTML = 'Add the <strong>' + pct + '%</strong> service fee of <strong>'
        + money(fee, cur) + '</strong>. Collect <strong>'
        + money(Math.round((amt + fee) * 100) / 100, cur) + '</strong> in total — the fee is '
        + 'added to the invoice and recorded as part of this payment, so the invoice clears.';
    }

    methodEl.addEventListener('change', paintSurcharge);
    amtEl.addEventListener('input', paintSurcharge);
    (async function () {
      try {
        var r = await Api.get('/agency/payment-surcharges');
        surRates = (r && r.rates) || surRates;
        paintSurcharge();
      } catch (e) { /* the option stays hidden */ }
    })();

    m.querySelector('#rp-save').addEventListener('click', async function () {
      var btn = m.querySelector('#rp-save');
      var amt = parseFloat(amtEl.value);
      if (isNaN(amt) || amt <= 0) {
        msgEl.style.color = '#DC2626'; msgEl.textContent = 'Enter the amount received.'; return;
      }
      btn.disabled = true; btn.textContent = 'Recording…';
      try {
        var payload = {
          amount: Math.round(amt * 100) / 100,
          method: m.querySelector('#rp-method').value,
          paid_at: m.querySelector('#rp-date').value || null,
          reference: (m.querySelector('#rp-ref').value || '').trim() || null,
          notes: (m.querySelector('#rp-note').value || '').trim() || null,
          add_surcharge: !surWrap.hidden && surBox.checked,
        };
        var r = await Api.post(kind === 'kt'
          ? '/director/invoices/' + inv.id + '/payments'
          : '/agency/external-invoices/' + inv.id + '/payments', payload);
        close();
        if (KT.Dom && KT.Dom.toast) {
          KT.Dom.toast((r && r.message) || 'Payment recorded', 'success');
        }
        load(container);
      } catch (e) {
        btn.disabled = false; btn.textContent = 'Record payment';
        msgEl.style.color = '#DC2626';
        msgEl.textContent = (e && e.message) || 'Could not record that payment.';
      }
    });
  }

  /* RESEND. The address is fetched and SHOWN, never assumed — an invoice in the wrong
     inbox is worse than one nobody resent ([[never-default-a-recipient]]). */
  async function openResendInvoice(inv) {
    var kind = inv.kt_source === 'kiddietrac' ? 'native' : 'external';
    var to = '';
    try {
      if (inv.family_id) {
        var c = await Api.get('/agency/families/' + inv.family_id + '/billing-contacts');
        to = ((c && c.emails) || []).join(', ');
      }
    } catch (e) { /* an empty box is still usable */ }

    var got = await KT.prompt({
      title: 'Resend ' + (inv.number || 'this invoice'),
      description: to
        ? 'It will be emailed with the invoice attached. Check the address before sending.'
        : 'No billing email is on file for this family, so there is nobody to send to '
          + 'unless you type one.',
      fields: [{ key: 'to', label: 'Send to', value: to, placeholder: 'name@example.com' }],
      okLabel: 'Send',
    });
    var addr = String(got == null ? '' : got).trim();
    if (!addr) { return; }

    try {
      var r = await Api.post('/admin/invoices/' + kind + '/' + inv.id + '/email', { to: addr });
      var failed = r && r.sent === false;
      if (KT.Dom && KT.Dom.toast) {
        KT.Dom.toast(failed ? (r.reason || 'Not sent.') : ((inv.number || 'Invoice') + ' sent to ' + addr),
          failed ? 'error' : 'success');
      }
    } catch (e) {
      if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((e && e.message) || 'Could not send that invoice.', 'error'); }
    }
  }

  async function openKtInvoiceEdit(container, inv) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;'
      + 'display:flex;align-items:flex-start;justify-content:center;padding:28px 16px;overflow:auto;';
    var m = document.createElement('div');
    m.style.cssText = 'background:#fff;border-radius:16px;max-width:680px;width:100%;'
      + 'box-shadow:0 24px 60px rgba(0,0,0,.35);';
    ov.appendChild(m);
    document.body.appendChild(ov);

    var close = function () { ov.remove(); document.removeEventListener('keydown', onKey); };
    function onKey(e) { if (e.key === 'Escape') { finish(); } }
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (ev) { if (ev.target === ov) { finish(); } });

    m.innerHTML = '<div style="padding:40px;text-align:center;color:#94A3B8;">Loading invoice…</div>';

    /* Edits held in a field and not yet written. paint() pushes a flusher per editable
       control; finish() runs them before the dialog goes away, so nothing typed is lost
       to the order the closing happened in. Declared here, above paint(), because paint()
       depends on it. */
    var pendingSaves = [];

    var data;
    try {
      data = await Api.get('/director/invoices/' + inv.id);
    } catch (e) {
      m.innerHTML = '<div style="padding:28px;color:#DC2626;font-size:14px;">'
        + esc((e && e.message) || 'Could not load that invoice.') + '</div>';
      return;
    }

    function paint() {
      var i = (data && data.invoice) || {};
      var fam = (data && data.family) || {};
      var lines = (data && data.lines) || [];
      var gs = (data && data.guardians) || [];
      var kids = (data && data.children) || [];
      var cur = i.currency || 'CAD';

      /* WHO PAYS. Primary first (the server orders them), with the relationship and the
         billing share when the family splits it — an admin adding a charge to a split
         family needs to see that it does not all land on one person. */
      var people = gs.length
        ? gs.map(function (g) {
            var nm = [g.first_name, g.last_name].filter(Boolean).join(' ') || 'Guardian';
            var bits = [];
            if (g.relationship) { bits.push(esc(g.relationship)); }
            if (g.is_primary) { bits.push('primary'); }
            if (!g.can_receive_billing) { bits.push('not billed'); }
            if (g.billing_share_pct != null && Number(g.billing_share_pct) > 0
                && Number(g.billing_share_pct) < 100) {
              bits.push(Number(g.billing_share_pct) + '% share');
            }
            return '<div style="padding:7px 0;border-top:1px solid #F1F5F9;">'
              + '<div style="font-weight:700;color:#0F172A;font-size:13.5px;">' + esc(nm)
              + (bits.length ? ' <span style="font-weight:600;color:#94A3B8;font-size:11.5px;">'
                  + esc(bits.join(' · ')) + '</span>' : '') + '</div>'
              + '<div style="font-size:12px;color:#64748B;">'
              + (g.email ? esc(g.email) : '<span style="color:#CBD5E1;">no email</span>')
              + (g.phone ? ' · ' + esc(g.phone) : '') + '</div></div>';
          }).join('')
        : '<div style="padding:7px 0;border-top:1px solid #F1F5F9;font-size:12.5px;color:#94A3B8;">'
          + 'No guardian on file for this family.</div>';

      /* EVERY child, not just one. A withdrawn child is still shown, greyed — an invoice
         may well cover a child who has since left, and hiding them would make the charge
         look unattached to anybody. */
      var kidCards = kids.length
        ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">'
          + kids.map(function (c) {
              var nm = c.preferred_name || [c.first_name, c.last_name].filter(Boolean).join(' ');
              var gone = String(c.enrollment_status || '').toLowerCase() === 'withdrawn';
              var sub = [c.room_name, c.date_of_birth ? age(c.date_of_birth) : null]
                .filter(Boolean).join(' · ');
              return '<div style="flex:0 1 auto;min-width:130px;padding:8px 11px;border:1px solid '
                + (gone ? '#E2E8F0' : '#DBEAFE') + ';background:' + (gone ? '#F8FAFC' : '#EFF6FF')
                + ';border-radius:10px;">'
                + '<div style="font-weight:700;font-size:13px;color:' + (gone ? '#94A3B8' : '#1E3A8A') + ';">'
                + esc(nm || 'Child') + '</div>'
                + '<div style="font-size:11.5px;color:#64748B;">' + esc(sub || '—')
                + (gone ? ' · withdrawn' : '') + '</div></div>';
            }).join('')
          + '</div>'
        : '<div style="font-size:12.5px;color:#94A3B8;margin-top:6px;">No children on file for this family.</div>';

      var lineRows = lines.map(function (l) {
        var amt = Number(l.amount) || 0;
        return '<tr>'
          /* Editable in place. A description is the sentence a parent reads to work out
             what they are being charged for, and it was the one thing on an invoice that
             could be wrong with no way to fix it short of deleting the line. */
          + '<td style="padding:5px 9px;border-top:1px solid #F1F5F9;">'
          + '<input data-ln-d="' + esc(String(l.id)) + '" value="' + esc(l.description || '') + '" maxlength="200"'
          + ' style="width:100%;padding:5px 7px;border:1px solid transparent;border-radius:7px;font-size:13px;'
          + 'color:#334155;background:transparent;box-sizing:border-box;font-family:inherit;">'
          + (l.tax_rate ? '<span style="font-size:11px;color:#64748B;padding-left:7px;">+' + esc(String(l.tax_rate)) + '% tax</span>' : '')
          + '</td>'
          + '<td style="padding:7px 9px;border-top:1px solid #F1F5F9;text-align:right;font-size:13px;'
          + 'font-variant-numeric:tabular-nums;color:' + (amt < 0 ? '#16A34A' : '#0F172A') + ';font-weight:600;">'
          + money(amt, cur) + '</td>'
          + '<td style="padding:7px 4px;border-top:1px solid #F1F5F9;text-align:right;">'
          + (lines.length > 1
              ? '<button type="button" data-rm-line="' + esc(String(l.id)) + '" title="Remove this line"'
                + ' style="border:none;background:none;color:#B91C1C;cursor:pointer;font-size:12px;font-weight:700;padding:2px 6px;">Remove</button>'
              : '')
          + '</td></tr>';
      }).join('');

      var isVoid = String(i.status || '').toLowerCase() === 'void';
      var isPaid = Number(i.balance_due || 0) <= 0.005 && Number(i.amount_paid || 0) > 0;

      m.innerHTML =
        '<div style="padding:16px 22px;border-bottom:1px solid #E5E7EB;display:flex;align-items:flex-start;gap:12px;">'
        + '<div style="flex:1;min-width:0;">'
        + '<h3 style="margin:0;font-size:17px;color:#0F172A;">✏️ Invoice ' + esc(i.invoice_number || '') + '</h3>'
        + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(fam.family_name || '')
        + (i.due_at ? ' · due ' + fmtDate(i.due_at) : '') + '</div></div>'
        + '<button type="button" id="kie-x" title="Close" aria-label="Close"'
        + ' style="border:none;background:#F1F5F9;color:#475569;width:30px;height:30px;border-radius:50%;'
        + 'cursor:pointer;font-size:17px;line-height:1;flex-shrink:0;">✕</button></div>'

        + '<div style="padding:16px 22px;">'

        // ── the people ──
        + '<div style="border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin-bottom:14px;">'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">Billed to</div>'
        + '<div style="font-weight:800;font-size:15px;color:#0F172A;margin-top:2px;">' + esc(fam.family_name || 'Family') + '</div>'
        + (fam.primary_email || fam.primary_phone
            ? '<div style="font-size:12px;color:#64748B;margin-top:1px;">'
              + esc([fam.primary_email, fam.primary_phone].filter(Boolean).join(' · ')) + '</div>'
            : '')
        + (famAddress(fam) ? '<div style="font-size:12px;color:#64748B;">' + esc(famAddress(fam)) + '</div>' : '')
        + '<div style="margin-top:8px;">' + people + '</div>'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin-top:12px;">'
        + (kids.length === 1 ? 'Child' : 'Children (' + kids.length + ')') + '</div>'
        + kidCards
        + '</div>'

        // ── the lines ──
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin-bottom:4px;">Description</div>'
        + '<textarea id="kie-notes" rows="2" maxlength="1000" placeholder="What this invoice is for"'
        + ' style="width:100%;padding:8px 10px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;'
        + 'box-sizing:border-box;font-family:inherit;resize:vertical;margin-bottom:14px;">'
        + esc(i.notes || '') + '</textarea>'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin-bottom:4px;">Line items</div>'
        + '<table style="width:100%;border-collapse:collapse;"><tbody>' + lineRows + '</tbody></table>'
        + '<div style="display:flex;justify-content:flex-end;gap:18px;padding:10px 9px 0;font-size:13px;color:#475569;">'
        + '<div style="text-align:right;">'
        + '<div>Subtotal <strong style="color:#0F172A;margin-left:6px;">' + money(i.subtotal, cur) + '</strong></div>'
        /* Subsidy and discount are shown whenever they are non-zero, because without
           them the panel does not add up: a CWELCC invoice reads subtotal $1,450.00
           above a balance of $1,015.00 and the missing $435.00 looks like an error. */
        + (Number(i.subsidy_amount) ? '<div>Subsidy <strong style="color:#16A34A;margin-left:6px;">−' + money(i.subsidy_amount, cur) + '</strong></div>' : '')
        + (Number(i.discount_amount) ? '<div>Discount <strong style="color:#16A34A;margin-left:6px;">−' + money(i.discount_amount, cur) + '</strong></div>' : '')
        + (Number(i.tax_amount) ? '<div>Tax <strong style="color:#0F172A;margin-left:6px;">' + money(i.tax_amount, cur) + '</strong></div>' : '')
        + '<div>Total <strong style="color:#0F172A;margin-left:6px;">' + money(i.total, cur) + '</strong></div>'
        + (Number(i.amount_paid) ? '<div>Paid <strong style="color:#16A34A;margin-left:6px;">' + money(i.amount_paid, cur) + '</strong></div>' : '')
        + '<div style="font-size:15px;margin-top:3px;">Balance <strong style="color:#0F172A;margin-left:6px;">' + money(i.balance_due, cur) + '</strong></div>'
        + '</div></div>'

        // ── add a line ──
        + (isVoid
            ? '<div style="margin-top:14px;padding:11px 13px;background:#F8FAFC;border-radius:10px;font-size:12.5px;color:#64748B;">'
              + 'This invoice is void. Raise a new one rather than adding to it.</div>'
            : '<div style="margin-top:14px;border-top:1px solid #E5E7EB;padding-top:14px;">'
              + '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin-bottom:7px;">Add a charge or credit</div>'
              + '<div style="display:grid;grid-template-columns:1fr 120px 96px;gap:9px;align-items:end;">'
              + '<label style="font-size:12px;font-weight:700;color:#334155;">Description'
              + '<input id="kie-desc" type="text" maxlength="200" placeholder="e.g. Late pickup fee (8 Oct)"'
              + ' style="width:100%;margin-top:3px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;box-sizing:border-box;"></label>'
              + '<label style="font-size:12px;font-weight:700;color:#334155;">Amount'
              + '<input id="kie-amt" type="number" step="0.01" placeholder="25.00"'
              + ' style="width:100%;margin-top:3px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;box-sizing:border-box;"></label>'
              + '<label style="font-size:12px;font-weight:700;color:#334155;">Tax %'
              + '<input id="kie-tax" type="number" step="0.01" min="0" max="100" placeholder="optional"'
              + ' style="width:100%;margin-top:3px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;box-sizing:border-box;"></label>'
              + '</div>'
              /* A credit is just a negative amount — one field, not a charge/credit mode
                 that could be left on the wrong setting. Said plainly so nobody has to
                 discover it. */
              + '<div style="font-size:11.5px;color:#64748B;margin-top:6px;line-height:1.5;">'
              + 'Enter a negative amount to take money off (e.g. <strong>-25.00</strong> for a credit). '
              + 'Tax is optional and applies to this line only.'
              + (isPaid ? ' <span style="color:#B45309;">This invoice is fully paid — adding a charge will reopen a balance.</span>' : '')
              + '</div>'
              + '<div style="display:flex;gap:9px;align-items:center;margin-top:10px;">'
              + '<button type="button" id="kie-add" class="kt-btn kt-btn-primary" style="padding:9px 16px;">Add line</button>'
              + '<span id="kie-msg" style="font-size:12.5px;min-height:16px;"></span></div>'
              + '</div>')

        + '<div style="display:flex;justify-content:flex-end;margin-top:16px;">'
        + '<button type="button" id="kie-done" style="padding:9px 18px;border:1px solid #E2E8F0;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Done</button>'
        + '</div></div>';

      m.querySelector('#kie-x').addEventListener('click', finish);
      m.querySelector('#kie-done').addEventListener('click', finish);

      /* Saved on blur rather than behind a Save button: there are now several
         independently editable things in this dialog, and one button that commits all of
         them would make it unclear which had been written when one of them failed. */
      var notesEl = m.querySelector('#kie-notes');
      if (notesEl) {
        var notesWas = notesEl.value;
        var saveNotes = async function () {
          if (notesEl.value === notesWas) { return; }
          try {
            await Api.patch('/director/invoices/' + inv.id + '/notes', { notes: notesEl.value });
            notesWas = notesEl.value;
            dirty = true;
            notesEl.style.borderColor = '#86EFAC';
            setTimeout(function () { notesEl.style.borderColor = '#E2E8F0'; }, 900);
          } catch (e) {
            notesEl.style.borderColor = '#DC2626';
          }
        };
        notesEl.addEventListener('blur', saveNotes);
        /* AND on the way out. Blur alone loses the edit whenever the dialog closes while
           the field still has focus — Escape does exactly that, and so does any close
           path that removes the overlay before the browser gets round to firing blur.
           Saving on close as well makes the outcome the same either way, rather than
           depending on which control the reader happened to reach for. */
        pendingSaves.push(saveNotes);
      }

      m.querySelectorAll('[data-ln-d]').forEach(function (el) {
        var was = el.value;
        el.addEventListener('focus', function () { el.style.borderColor = '#CBD5E1'; el.style.background = '#fff'; });
        var saveLine = async function () {
          el.style.background = 'transparent';
          var v = el.value.trim();
          if (v === was.trim()) { el.style.borderColor = 'transparent'; return; }
          if (!v) { el.value = was; el.style.borderColor = 'transparent'; return; }
          try {
            await Api.patch('/director/invoices/' + inv.id + '/lines/' + el.getAttribute('data-ln-d'),
              { description: v });
            was = v;
            dirty = true;
            el.style.borderColor = '#86EFAC';
            setTimeout(function () { el.style.borderColor = 'transparent'; }, 900);
          } catch (e) {
            el.value = was;
            el.style.borderColor = '#DC2626';
            setTimeout(function () { el.style.borderColor = 'transparent'; }, 1200);
          }
        };
        el.addEventListener('blur', saveLine);
        pendingSaves.push(saveLine);
      });

      m.querySelectorAll('[data-rm-line]').forEach(function (b) {
        b.addEventListener('click', async function () {
          b.disabled = true;
          try {
            await Api.del('/director/invoices/' + inv.id + '/lines/' + b.getAttribute('data-rm-line'));
            data = await Api.get('/director/invoices/' + inv.id);
            dirty = true;
            paint();
          } catch (e) {
            b.disabled = false;
            var msg = m.querySelector('#kie-msg');
            if (msg) { msg.style.color = '#DC2626'; msg.textContent = (e && e.message) || 'Could not remove that line.'; }
          }
        });
      });

      var addBtn = m.querySelector('#kie-add');
      if (addBtn) {
        addBtn.addEventListener('click', async function () {
          var desc = (m.querySelector('#kie-desc').value || '').trim();
          var amt = parseFloat(m.querySelector('#kie-amt').value);
          var tax = parseFloat(m.querySelector('#kie-tax').value);
          var msg = m.querySelector('#kie-msg');
          msg.style.color = '#DC2626';
          if (!desc) { msg.textContent = 'Give the line a description.'; return; }
          if (isNaN(amt) || amt === 0) { msg.textContent = 'Enter an amount (negative to credit).'; return; }

          addBtn.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Adding…';
          try {
            await Api.post('/director/invoices/' + inv.id + '/lines', {
              description: desc,
              amount: amt,
              tax_rate: isNaN(tax) || tax <= 0 ? null : tax,
            });
            data = await Api.get('/director/invoices/' + inv.id);
            dirty = true;
            paint();
          } catch (e) {
            addBtn.disabled = false;
            msg.style.color = '#DC2626';
            msg.textContent = (e && e.message) || 'Could not add that line.';
          }
        });
      }
    }

    /* Edits that are held in a field and not yet written. Flushed before the dialog
       goes away, so nothing typed is lost to the order the closing happened in. */
    /* The list behind the dialog only reloads if something actually changed — a reload
       on plain Close would throw away the reader's page and scroll for nothing. */
    var dirty = false;
    async function finish() {
      for (var n = 0; n < pendingSaves.length; n++) {
        try { await pendingSaves[n](); } catch (e) { /* reported on the field itself */ }
      }
      close();
      if (dirty) { load(container); }
    }

    function age(dob) {
      try {
        var p = String(dob).slice(0, 10).split('-');
        if (p.length !== 3) { return ''; }
        var b = new Date(+p[0], +p[1] - 1, +p[2]);
        var n = new Date();
        var months = (n.getFullYear() - b.getFullYear()) * 12 + (n.getMonth() - b.getMonth());
        if (n.getDate() < b.getDate()) { months--; }
        if (months < 24) { return months + ' mo'; }
        return Math.floor(months / 12) + ' yrs';
      } catch (e) { return ''; }
    }

    function famAddress(f) {
      return [f.address_line1, f.address_line2, f.city, f.province, f.postal_code]
        .filter(Boolean).join(', ');
    }

    paint();
  }

  function openInvoiceEdit(container, inv) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:32px 18px;overflow:auto;';
    var m = document.createElement('div');
    m.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.35);';
    function fld(label, id, val, type) {
      return '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:10px;">' + label
        + '<input id="' + id + '" type="' + (type || 'text') + '" value="' + esc(val == null ? '' : val) + '" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;"></label>';
    }
    m.innerHTML = '<div style="padding:18px 22px;border-bottom:1px solid #E5E7EB;"><h3 style="margin:0;font-size:17px;">✏️ Edit invoice ' + esc(inv.number || '') + '</h3>'
      + '<div style="font-size:12px;color:#B45309;margin-top:2px;">Edits the KiddieTrac copy — the source may overwrite it on the next sync.</div></div>'
      + '<div style="padding:18px 22px;">'
      + fld('Invoice #', 'iv-number', inv.number)
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' + fld('Status', 'iv-status', inv.status) + fld('Issued', 'iv-issued', (inv.issued_at || '').slice(0, 10), 'date') + '</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' + fld('Due', 'iv-due', (inv.due_at || '').slice(0, 10), 'date') + fld('Total', 'iv-total', inv.total, 'number') + '</div>'
      + fld('Amount paid', 'iv-paid', inv.amount_paid, 'number')
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;">Description<textarea id="iv-desc" rows="2" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:14px;box-sizing:border-box;font-family:inherit;">' + esc(inv.description || '') + '</textarea></label>'
      + '<div id="iv-err" style="color:#DC2626;font-size:12.5px;min-height:16px;margin-top:6px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;"><button id="iv-cancel" style="padding:9px 16px;border:1px solid #E2E8F0;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancel</button><button id="iv-save" class="kt-btn kt-btn-primary" style="padding:9px 18px;">Save</button></div></div>';
    ov.appendChild(m); document.body.appendChild(ov);
    var close = function () { ov.remove(); };
    ov.addEventListener('click', function (ev) { if (ev.target === ov) close(); });
    m.querySelector('#iv-cancel').addEventListener('click', close);
    m.querySelector('#iv-save').addEventListener('click', function () {
      var btn = m.querySelector('#iv-save'); btn.disabled = true; btn.textContent = 'Saving…';
      Api.patch('/agency/external-invoices/' + inv.id, {
        number: (m.querySelector('#iv-number').value || '').trim() || null,
        status: (m.querySelector('#iv-status').value || '').trim() || null,
        issued_at: m.querySelector('#iv-issued').value || null,
        due_at: m.querySelector('#iv-due').value || null,
        total: parseFloat(m.querySelector('#iv-total').value) || 0,
        amount_paid: parseFloat(m.querySelector('#iv-paid').value) || 0,
        description: (m.querySelector('#iv-desc').value || '').trim() || null,
      }).then(function () { close(); load(container); if (KT.toast) KT.toast('✅', 'Saved', 'Invoice updated.', '#16A34A'); })
        .catch(function (err) { btn.disabled = false; btn.textContent = 'Save'; m.querySelector('#iv-err').textContent = (err && err.message) || 'Could not save.'; });
    });
  }

  /* The invoice the billing system actually issued, fetched through our API rather
     than opened at its source URL. That URL is signed, self-authenticating and does not
     expire, so handing it to a browser left it in histories and made it forwardable by
     anyone who came across it. Now it stays on the server and every request is checked
     against who is asking. Same arrangement as the iLearn payslips. */
  /* EVERY INVOICE OPENS IN A POPUP (2026-09-17).

     Anthony: "all invoices in the accounting section and viewing should be viewed in a
     popup."

     A KiddieTrac invoice already opened in the portal's own sheet; a synced one was
     handed to `window.open(blobUrl)`, which leaves Accounting, loses the reader's page,
     filter and scroll, and in the Android WebView opens nothing at all. Same list, two
     behaviours, depending on which system happened to raise the row.

     The blob is revoked when the sheet closes rather than on a timer: a timer either
     fires while somebody is still reading or leaves the whole file pinned in memory. */
  function openDocSheet(title, blobUrl, onClose) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10001;'
      + 'display:flex;flex-direction:column;padding:22px 18px;';

    var shell = document.createElement('div');
    shell.style.cssText = 'background:#fff;border-radius:14px;max-width:940px;width:100%;margin:0 auto;'
      + 'flex:1;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.4);';

    var head = document.createElement('div');
    head.style.cssText = 'display:flex;align-items:center;gap:10px;padding:13px 18px;border-bottom:1px solid #E5E7EB;flex-shrink:0;';
    head.innerHTML = '<div style="font-weight:800;font-size:15px;color:#0F172A;flex:1;min-width:0;">'
      + esc(title) + '</div>';

    var x = document.createElement('button');
    x.title = 'Close'; x.setAttribute('aria-label', 'Close'); x.textContent = '\u2715';
    x.style.cssText = 'border:none;background:#F1F5F9;color:#475569;width:30px;height:30px;'
      + 'border-radius:50%;cursor:pointer;font-size:17px;line-height:1;flex-shrink:0;';
    head.appendChild(x);

    var frame = document.createElement('iframe');
    frame.style.cssText = 'flex:1;width:100%;border:0;background:#fff;';
    frame.title = title;
    frame.src = blobUrl;

    shell.appendChild(head); shell.appendChild(frame); ov.appendChild(shell);
    document.body.appendChild(ov);

    function shut() {
      document.removeEventListener('keydown', onKey);
      ov.remove();
      if (typeof onClose === 'function') { onClose(); }
    }
    function onKey(e) { if (e.key === 'Escape') { shut(); } }
    document.addEventListener('keydown', onKey);
    x.addEventListener('click', shut);
    ov.addEventListener('click', function (ev) { if (ev.target === ov) { shut(); } });
  }

  async function openOfficial(id, btn) {
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Opening…';
    try {
      var t; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
      var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
      var h = { Authorization: 'Bearer ' + t };
      // Staff are authorised against their ACTIVE agency, so this has to travel.
      try { var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid; } catch (e) {}
      var r = await fetch(base + '/invoices/external/' + id + '/document', { headers: h });
      if (!r.ok) {
        /* Say what actually happened. A 410 means the billing system DELETED the
           document — nothing here is broken and retrying will never help — and the
           server sends the sentence to show. Swallowing it left "Could not open that
           invoice" standing in for a deleted invoice, a network fault and a
           permission problem alike. */
        var why = '';
        try { why = ((await r.clone().json()) || {}).message || ''; } catch (e) {}
        throw new Error(why || ('HTTP ' + r.status));
      }
      var url = URL.createObjectURL(await r.blob());
      openDocSheet('Invoice ' + (btn.getAttribute('data-num') || ''), url, function () {
        URL.revokeObjectURL(url);
      });
    } catch (e) {
      var msg = (e && e.message && ! /^HTTP \d+$/.test(e.message))
        ? e.message
        : 'Could not open that invoice';
      if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, 'error');
    }
    btn.disabled = false; btn.textContent = label;
  }

  async function load(container) {
    if (state.busy) return;
    state.busy = true;
    var body = container.querySelector('#xb-body');
    if (body) body.innerHTML = '<div style="padding:36px;text-align:center;color:#94A3B8;">Loading…</div>';
    var qs = '?page=' + state.page + '&per_page=20'
      + (state.family_id ? '&family_id=' + state.family_id : '')
      + (state.search ? '&search=' + encodeURIComponent(state.search) : '')
      + (state.sort ? '&sort=' + state.sort + '&dir=' + state.dir : '')
      + (state.status ? '&status=' + encodeURIComponent(state.status) : '')
      + (state.counterparty ? '&counterparty=' + encodeURIComponent(state.counterparty) : '');
    var d;
    try { d = await Api.get('/agency/external-invoices' + qs); }
    catch (e) {
      state.busy = false;
      if (body) body.innerHTML = '<div class="kt-card" style="text-align:center;color:#DC2626;padding:30px;">Could not load: ' + esc(e.message || 'error') + '</div>';
      return;
    }
    state.busy = false;
    renderTable(container, d);
  }

  function renderTable(container, d) {
    var invoices = d.invoices || [];
    var stats = d.stats || {};
    var meta = d.meta || { page: 1, pages: 1, total: 0 };
    var fams = d.families || [];

    // Family filter (rebuild only when empty, so typing search doesn't reset it)
    relabelPayeeOption(container);
    var famSel = container.querySelector('#xb-family');
    if (famSel && !famSel.getAttribute('data-built')) {
      famSel.innerHTML = '<option value="0">All families (' + fams.length + ')</option>'
        + fams.map(function (f) { return '<option value="' + f.id + '">' + esc(f.label) + '</option>'; }).join('');
      famSel.setAttribute('data-built', '1');
      famSel.value = String(state.family_id);
    }

    var statsRow = container.querySelector('#xb-stats');
    if (statsRow) {
      statsRow.innerHTML =
        statCard('Outstanding', money(stats.open_total), (stats.open_count || 0) + ' open invoice' + ((stats.open_count === 1) ? '' : 's'), '#FDBA74', '#F97316', '#C2410C', '#FFF7ED')
        + statCard('Collected', money(stats.paid_total), (stats.paid_count || 0) + ' paid', '#86EFAC', '#16A34A', '#15803D', '#F0FDF4')
        + statCard('Families', String(fams.length), 'with synced invoices', '#93C5FD', '#2563EB', '#1D4ED8', '#EFF6FF')
        + statCard('Invoices', String(meta.total), 'in this view', '#C4B5FD', '#7C3AED', '#6D28D9', '#F5F3FF');
    }

    var body = container.querySelector('#xb-body');
    if (!invoices.length) {
      body.innerHTML = '<div class="kt-card" style="text-align:center;color:#64748B;padding:40px;">No synced invoices' + (state.search || state.family_id ? ' match this filter.' : ' yet. Invoices appear here automatically as the external platform pushes them.') + '</div>';
      return;
    }
    /* One pill per role the billed family's guardians hold. Almost every row reads
       just "Parent"; the point of the column is the row that also reads Educator or
       Admin, so a staff role is tinted and Parent is left plain — the exception is
       what should catch the eye, not the rule. */
    function roleCell(role) {
      var raw = String(role || '').trim();
      if (raw === '') { return '<span style="color:#94A3B8;">—</span>'; }
      return raw.split('·').map(function (r) {
        r = r.trim();
        if (r === '') { return ''; }
        var staff = r.toLowerCase() !== 'parent';
        var bg = staff ? '#EEF2FF' : '#F1F5F9';
        var fg = staff ? '#4338CA' : '#475569';
        return '<span style="display:inline-block;background:' + bg + ';color:' + fg
          + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;'
          + 'margin:1px 3px 1px 0;white-space:nowrap;">' + esc(r) + '</span>';
      }).join('');
    }

    var th = 'text-align:left;padding:10px 12px;font-size:10.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;';
    var td = 'padding:10px 12px;font-size:13px;color:#334155;border-top:1px solid #F1F5F9;vertical-align:middle;';
    var rows = invoices.map(function (i) {
      var acts = '';
      /* A KIDDIETRAC INVOICE IS NOT A PROVIDER ONE, AND ITS BUTTONS DIFFER.

         This list used to be provider invoices only, so every row's actions assumed a
         document at the provider — View and Download fetch /agency/external-invoices/{id}
         and Edit patches the synced copy. A KiddieTrac invoice has none of those: it has
         no provider document, and editing it there would write to a table it is not in.
         It opens in the portal's own viewer instead, which is the same sheet the payment
         schedules and the ledger use. (2026-09-17) */
      /* A PAYEE INVOICE IS IN A THIRD TABLE, and neither set of actions fits it.
         View/Download fetch a provider document it has none of, and Edit patches
         `external_invoices`, which it is not in — it would 404 at best and edit a
         stranger's row at worst. It is listed so the money is visible and the filter
         works; it is managed where it is raised, on My Pay. (2026-09-17) */
      if (i.kt_source === 'payee') {
        return '<tr>'
          + '<td style="' + td + 'font-weight:700;color:#0F172A;">' + esc(i.description || i.family || '—') + '</td>'
          + '<td style="' + td + '">' + roleCell(i.role) + '</td>'
          + '<td style="' + td + 'font-variant-numeric:tabular-nums;">' + esc(i.number || '—')
            + '<div style="font-size:10.5px;color:#94A3B8;font-weight:700;letter-spacing:.3px;">'
            + esc(String(i.counterparty || '').toUpperCase()) + '</div></td>'
          + '<td style="' + td + '">' + statusBadge(i.status, i.is_open, i.due_at) + '</td>'
          + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.issued_at) + '</td>'
          + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.due_at) + '</td>'
          + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;">' + money(i.total, i.currency) + '</td>'
          + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:#16A34A;">' + money(i.amount_paid, i.currency) + '</td>'
          + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:' + (i.is_open ? '#B45309' : '#16A34A') + ';">' + money(i.balance_due, i.currency) + '</td>'
          + '<td style="' + td + 'text-align:right;white-space:nowrap;color:#94A3B8;font-size:12px;">Raised in My Pay</td>'
          + '</tr>';
      }
      if (i.kt_source === 'kiddietrac') {
        return '<tr data-kt-inv="' + esc(String(i.id)) + '" data-num="' + esc(String(i.number || ''))
          + '" data-st="' + esc(String(i.status || '')) + '" data-total="' + esc(String(i.total || 0)) + '">'
          + '<td style="' + td + 'font-weight:700;color:#0F172A;">' + esc(i.family || '—') + '</td>'
          + '<td style="' + td + '">' + roleCell(i.role) + '</td>'
          + '<td style="' + td + 'font-variant-numeric:tabular-nums;">' + esc(i.number || '—')
            + '<div style="font-size:10.5px;color:#94A3B8;font-weight:700;letter-spacing:.3px;">KIDDIETRAC</div></td>'
          + '<td style="' + td + '">' + statusBadge(i.status, i.is_open, i.due_at) + '</td>'
          + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.issued_at) + '</td>'
          + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.due_at) + '</td>'
          + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;">' + money(i.total, i.currency) + '</td>'
          + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:#16A34A;">' + money(i.amount_paid, i.currency) + '</td>'
          + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:' + (i.is_open ? '#B45309' : '#16A34A') + ';">' + money(i.balance_due, i.currency) + '</td>'
          + '<td style="' + td + 'text-align:right;white-space:nowrap;">'
                        + '<button type="button" data-kt-iconized="1" data-inv-view="' + esc(String(i.id)) + '"'
            + ' style="border:none;background:none;cursor:pointer;color:#2563EB;font-weight:600;font-size:12.5px;padding:3px 7px;">View invoice</button>'
            /* A KiddieTrac invoice is OURS — real line items, editable for real, unlike
               the synced copy next door that the source overwrites. So it gets its own
               Edit, opening its own dialog. (2026-09-17) */
            + '<button type="button" class="xb-act" data-act="pay" data-id="' + esc(String(i.id)) + '"'
            + ' style="border:none;background:none;cursor:pointer;color:#15803D;font-weight:600;font-size:12.5px;padding:3px 7px;">Record payment</button>'
            + '<button type="button" class="xb-act" data-act="resend" data-id="' + esc(String(i.id)) + '"'
            + ' style="border:none;background:none;cursor:pointer;color:#7C3AED;font-weight:600;font-size:12.5px;padding:3px 7px;">Resend invoice</button>'
            + '<button type="button" class="xb-act" data-act="ktedit" data-id="' + esc(String(i.id)) + '"'
            + ' style="border:none;background:none;cursor:pointer;color:#334155;font-weight:600;font-size:12.5px;padding:3px 7px;">Edit</button>'
            /* DOWNLOAD AND VOID LIVE HERE NOW (2026-09-17). Anthony: "under accounting you
               need to have download invoice, void and remove the void function under
               payment schedules." Voiding is an accounting act — it withdraws a document
               and moves money off a family's balance — and it belongs where the ledger is
               read, not on the screen for planning instalments. */
            + '<button type="button" class="xb-act" data-act="ktpdf" data-id="' + esc(String(i.id)) + '"'
            + ' data-num="' + esc(String(i.number || '')) + '"'
            + ' style="border:none;background:none;cursor:pointer;color:#0F766E;font-weight:600;font-size:12.5px;padding:3px 7px;">Download invoice</button>'
            + (String(i.status || '').toLowerCase() === 'void' ? ''
              : '<button type="button" class="xb-act" data-act="ktvoid" data-id="' + esc(String(i.id)) + '"'
                + ' data-num="' + esc(String(i.number || '')) + '" data-amt="' + esc(String(i.total || 0)) + '"'
                + ' style="border:none;background:none;cursor:pointer;color:#B91C1C;font-weight:600;font-size:12.5px;padding:3px 7px;">Void invoice</button>')
            + '</td>'
          + '</tr>';
      }
      if (i.has_document || i.pdf_url) {
        acts += '<button type="button" class="xb-act" data-act="view" data-id="' + i.id + '" data-num="' + esc(String(i.number || '')) + '" style="border:none;background:none;cursor:pointer;color:#2563EB;font-weight:600;font-size:12.5px;padding:3px 7px;">👁 View</button>';
        acts += '<button type="button" class="xb-act" data-act="download" data-id="' + i.id + '" data-num="' + esc(String(i.number || '')) + '" style="border:none;background:none;cursor:pointer;color:#0F766E;font-weight:600;font-size:12.5px;padding:3px 7px;">⬇ Download</button>';
      }
      acts += '<button type="button" class="xb-act" data-act="edit" data-id="' + i.id + '" style="border:none;background:none;cursor:pointer;color:#334155;font-weight:600;font-size:12.5px;padding:3px 7px;">✏️ Edit</button>';
      /* VOIDING A SYNCED INVOICE (2026-09-17). Anthony: "for existing invoices that are
         scheduled allow them to be edited, voided etc (these are the ones that came from
         ilearn system)." Edit was already here; cancelling one meant going to iLearn. */
      acts += '<button type="button" class="xb-act" data-act="resend" data-id="' + i.id + '"'
        + ' style="border:none;background:none;cursor:pointer;color:#7C3AED;font-weight:600;font-size:12.5px;padding:3px 7px;">Resend invoice</button>';
      if (String(i.status || '').toLowerCase() !== 'void') {
        acts += '<button type="button" class="xb-act" data-act="pay" data-id="' + i.id + '"'
          + ' style="border:none;background:none;cursor:pointer;color:#15803D;font-weight:600;font-size:12.5px;padding:3px 7px;">Record payment</button>';
      }
      if (String(i.status || '').toLowerCase() !== 'void') {
        acts += '<button type="button" class="xb-act" data-act="extvoid" data-id="' + i.id + '"'
          + ' data-num="' + esc(String(i.number || '')) + '" data-total="' + esc(String(i.total || 0)) + '"'
          + ' data-paid="' + esc(String(i.amount_paid || 0)) + '"'
          + ' style="border:none;background:none;cursor:pointer;color:#B91C1C;font-weight:600;font-size:12.5px;padding:3px 7px;">Void invoice</button>';
      }
      /* Tagged for the bulk bar. `data-ext-inv` marks a SYNCED invoice, distinct from
         `data-kt-inv`, because the two are voided through different endpoints and only
         one of them can be downloaded from here. */
      return '<tr data-ext-inv="' + esc(String(i.id)) + '" data-num="' + esc(String(i.number || ''))
        + '" data-st="' + esc(String(i.status || '')) + '" data-total="' + esc(String(i.total || 0))
        + '" data-paid="' + esc(String(i.amount_paid || 0)) + '">'
        + '<td style="' + td + 'font-weight:700;color:#0F172A;">' + esc(i.family || '—') + '</td>'
        + '<td style="' + td + '">' + roleCell(i.role) + '</td>'
        + '<td style="' + td + 'font-variant-numeric:tabular-nums;">' + esc(i.number || '—') + '</td>'
        + '<td style="' + td + '">' + statusBadge(i.status, i.is_open, i.due_at) + '</td>'
        + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.issued_at) + '</td>'
        + '<td style="' + td + 'white-space:nowrap;color:#64748B;">' + fmtDate(i.due_at) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;">' + money(i.total, i.currency) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;color:#16A34A;">' + money(i.amount_paid, i.currency) + '</td>'
        + '<td style="' + td + 'text-align:right;font-variant-numeric:tabular-nums;font-weight:800;color:' + (i.is_open ? '#B45309' : '#16A34A') + ';">' + money(i.balance_due, i.currency) + '</td>'
        + '<td style="' + td + 'text-align:right;white-space:nowrap;">' + acts + '</td>'
        + '</tr>';
    }).join('');
    // Filled by KT.pagerBar after render — the portal's one numbered pager.
    var pager = '<div id="xb-pager" style="padding:0 12px 10px;"></div>';
    body.innerHTML = '<div class="kt-card" style="padding:0;overflow:hidden;">'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:820px;">'
      + '<thead><tr style="background:#F8FAFC;">'
      + [
          { h: 'Family', k: 'family', a: '' },
          /* Not sortable: it is a property of the family, not of the invoice, and
             sorting a page of mostly-"Parent" by role sorts nothing. */
          { h: 'Role', k: '', a: '' },
          { h: 'Invoice #', k: 'number', a: '' },
          { h: 'Status', k: 'status', a: '' },
          { h: 'Issued', k: 'issued', a: '' },
          { h: 'Due', k: 'due', a: '' },
          { h: 'Total', k: 'total', a: 'text-align:right;' },
          { h: 'Paid', k: '', a: 'text-align:right;' },
          { h: 'Balance', k: 'amount', a: 'text-align:right;' },
          { h: '', k: '', a: 'text-align:right;' }
        ].map(function (c) {
          var active = c.k && state.sort === c.k;
          var arrow = active ? (state.dir === 'desc' ? ' ▼' : ' ▲') : (c.k ? ' <span style="opacity:.28;">↕</span>' : '');
          var cur = c.k ? 'cursor:pointer;user-select:none;' : '';
          return '<th data-sort="' + c.k + '" style="' + th + c.a + cur + (active ? 'color:#2563EB;' : '') + '">' + c.h + arrow + '</th>';
        }).join('')
      + '</tr></thead><tbody>' + rows + '</tbody></table></div>' + pager + '</div>';

    var pg = body.querySelector('#xb-pager');
    if (pg && window.KT && KT.pagerBar) KT.pagerBar(pg, meta.page, meta.pages, function (p) { state.page = p; load(container); });
    body.querySelectorAll('th[data-sort]').forEach(function (thEl) {
      var k = thEl.getAttribute('data-sort');
      if (!k) return;
      thEl.addEventListener('click', function () {
        if (state.sort === k) { state.dir = (state.dir === 'asc' ? 'desc' : 'asc'); }
        else { state.sort = k; state.dir = 'asc'; }
        state.page = 1; load(container);
      });
    });
    // Row actions (collapsed into one kebab by kt-row-actions on desktop).
    // View/Download open the source invoice in the SYSTEM browser (the APK's
    // in-app WebView can't load the external host directly). Edit opens a modal.
    /* The KiddieTrac rows' own button — the portal's invoice sheet, the same one the
       payment schedules and the account ledger open. */
    /* BULK DOWNLOAD AND VOID ON THE SELECTED ROWS (2026-09-17).

       Anthony: "same goes for the accounting section where you can multi select for
       download, void."

       Only KiddieTrac invoices carry the data attributes these read, so a selection that
       includes a synced iLearn invoice or a payee invoice simply skips those and says how
       many it skipped — neither has a document of ours to render, and neither is in the
       table this void writes to.

       Each row goes through the SAME single-invoice endpoint the row menu uses, so the
       guards cannot drift: an invoice that refuses to be voided alone (money still held
       against it) refuses here too, and is counted rather than silently dropped. */
    /* The list repaints on every filter, page and tab change, none of which is a
       hashchange — so the table primitives are asked for explicitly here. */
    if (window.KT && KT.sweepTables) { setTimeout(KT.sweepTables, 0); }

    (function () {
      const table = body.querySelector('table');
      if (!table) { return; }

      /* TWO KINDS OF INVOICE IN ONE LIST. A KiddieTrac row is ours end to end; a synced
         row belongs to the billing system, is voided through a different endpoint, and
         has no document we can draw. A payee invoice is neither and is skipped. `kind`
         keeps them apart so one bulk action can serve both without pretending they are
         the same thing. */
      const pick = function (rows) {
        const mine = [], skipped = [];
        rows.forEach(function (r) {
          const kt = r.getAttribute('data-kt-inv');
          const ext = r.getAttribute('data-ext-inv');
          if (!kt && !ext) { skipped.push(r); return; }
          mine.push({
            kind: kt ? 'kt' : 'ext',
            id: kt || ext,
            num: r.getAttribute('data-num') || 'invoice',
            st: String(r.getAttribute('data-st') || '').toLowerCase(),
            total: Number(r.getAttribute('data-total') || 0),
            paid: Number(r.getAttribute('data-paid') || 0),
          });
        });
        return { mine: mine, skipped: skipped.length };
      };

      const say = function (msg, kind) {
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(msg, kind); }
      };

      table.ktBulkActions = [
        {
          label: 'Download',
          run: async function (rows) {
            const got = pick(rows);
            /* Only a KiddieTrac invoice has a document we can render. A synced one has a
               provider PDF fetched through a different route, and folding the two into one
               loop would make a partial failure unreadable. */
            const docs = got.mine.filter(function (t) { return t.kind === 'kt'; });
            const other = got.skipped + (got.mine.length - docs.length);
            if (!docs.length) {
              say('None of those are KiddieTrac invoices \u2014 only ours can be rendered here.', 'error');
              return;
            }
            let tok = '';
            try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
            const headers = { Authorization: 'Bearer ' + tok };
            try { const ag = sessionStorage.getItem('kt_active_agency_id'); if (ag) { headers['X-Active-Agency-Id'] = ag; } } catch (e) {}
            const base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
            let done = 0, failed = 0;
            for (const t of docs) {
              try {
                const res = await fetch(base + '/invoices/' + t.id + '/pdf', { headers });
                if (!res.ok) { throw new Error('no pdf'); }
                const url = URL.createObjectURL(await res.blob());
                const a = document.createElement('a');
                a.href = url; a.download = t.num + '.pdf';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
                done++;
                // A browser handed ten downloads in one tick blocks all but the first.
                await new Promise(function (r) { setTimeout(r, 350); });
              } catch (e) { failed++; }
            }
            say(done + ' downloaded' + (failed ? ', ' + failed + ' could not be rendered' : '')
              + (other ? ', ' + other + ' skipped' : ''), failed ? 'error' : 'success');
          },
        },
        {
          label: 'Void',
          danger: true,
          run: async function (rows) {
            const got = pick(rows);
            const targets = got.mine.filter(function (t) { return t.st !== 'void' && t.paid <= 0.005; });
            const already = got.mine.filter(function (t) { return t.st === 'void'; }).length;
            const held = got.mine.filter(function (t) { return t.st !== 'void' && t.paid > 0.005; }).length;
            const synced = targets.filter(function (t) { return t.kind === 'ext'; }).length;
            if (!targets.length) {
              say('Nothing to void in that selection.', 'error');
              return;
            }
            const sum = targets.reduce(function (a, t) { return a + t.total; }, 0);
            const ok = await KT.confirm({
              title: 'Void ' + targets.length + ' invoice(s)?',
              description: 'This cancels ' + money(sum) + ' across ' + targets.length + ' invoice(s) for good. '
                + 'Each keeps its number and stays here marked Void, any payment-schedule instalment behind '
                + 'it is withdrawn, and the family is emailed to say it is cancelled. The office is emailed '
                + 'too \u2014 one email per invoice. This cannot be undone.'
                + (synced ? ' ' + synced + ' of them came from the billing system, which stays the '
                  + 'system of record, so the next sync may bring those back.' : '')
                + (held ? ' ' + held + ' have money paid against them and will be skipped.' : '')
                + (got.skipped ? ' ' + got.skipped + ' selected row(s) cannot be voided here and will be skipped.' : '')
                + (already ? ' ' + already + ' are already void.' : ''),
              okLabel: 'Void ' + targets.length,
              tone: 'danger',
            });
            if (!ok) { return; }
            const why = await KT.prompt({
              title: 'Why are these being voided?',
              description: 'Recorded against each invoice in the audit log and sent to admins and '
                + 'directors. Families are not shown this note.',
              fields: [{ key: 'reason', label: 'Reason', placeholder: 'e.g. raised in error, family withdrew' }],
              okLabel: 'Void ' + targets.length,
            });
            if (why == null) { return; }
            let done = 0, refused = 0;
            for (const t of targets) {
              try {
                await Api.post(t.kind === 'kt'
                  ? '/director/invoices/' + t.id + '/void'
                  : '/agency/external-invoices/' + t.id + '/void',
                  { reason: String(why).trim() || null });
                done++;
              } catch (e) { refused++; }
            }
            say(done + ' voided' + (refused ? ', ' + refused + ' refused (money may be held against them)' : ''),
              refused ? 'error' : 'success');
            load(container);
          },
        },
      ];
    })();

    body.querySelectorAll('[data-inv-view]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (window.KT && KT.openInvoiceById) { KT.openInvoiceById(b.getAttribute('data-inv-view')); }
      });
    });
    body.querySelectorAll('.xb-act').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.getAttribute('data-act');
        if (act === 'view' || act === 'download') {
          openOfficial(b.getAttribute('data-id'), b);
          return;
        }
        if (act === 'pay' || act === 'resend') {
          /* Both dialogs need the whole row, not just an id: the payment dialog shows the
             total and the balance, and the resend needs family_id to look up who it would
             go to. `d.invoices` is the payload this table was drawn from. */
          var row = (d.invoices || []).find(function (x) { return String(x.id) === String(b.getAttribute('data-id')); });
          if (!row) { return; }
          if (act === 'pay') { openRecordPayment(container, row); } else { openResendInvoice(row); }
          return;
        }
        if (act === 'extvoid') {
          (async function () {
            var num = b.getAttribute('data-num') || 'this invoice';
            var total = Number(b.getAttribute('data-total') || 0);
            var paid = Number(b.getAttribute('data-paid') || 0);
            if (paid > 0.005) {
              if (KT.Dom && KT.Dom.toast) {
                KT.Dom.toast(money(paid) + ' has been paid against ' + num
                  + '. Refund or reallocate it in the billing system first.', 'error');
              }
              return;
            }
            var ok = await KT.confirm({
              title: 'Void ' + num + '?',
              description: 'This cancels ' + money(total) + '. The family is emailed to say it is '
                + 'cancelled and that they owe nothing, and the office is emailed too. '
                + 'Note that this invoice came from the billing system, which remains the system '
                + 'of record — KiddieTrac’s copy is marked void, and the next sync may bring it back. '
                + 'Cancel it there as well if it should stay cancelled.',
              okLabel: 'Void invoice',
              tone: 'danger',
            });
            if (!ok) { return; }
            var why = await KT.prompt({
              title: 'Why is ' + num + ' being voided?',
              description: 'Recorded in the audit log and sent to admins and directors. The family '
                + 'is not shown this note.',
              fields: [{ key: 'reason', label: 'Reason', placeholder: 'e.g. raised in error, family withdrew' }],
              okLabel: 'Void invoice',
            });
            if (why == null) { return; }
            try {
              var r = await Api.post('/agency/external-invoices/' + b.getAttribute('data-id') + '/void',
                { reason: String(why).trim() || null });
              if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((r && r.message) || (num + ' voided'), 'success'); }
              load(container);
            } catch (e) {
              if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((e && e.message) || 'Could not void that invoice.', 'error'); }
            }
          })();
          return;
        }
        if (act === 'ktpdf') {
          /* Fetched with the caller's token, because the PDF route is authenticated and
             a plain <a href> carries no Authorization header. The object URL is revoked
             straight after; leaving it alive pins the whole file in memory. */
          (async function () {
            var num = b.getAttribute('data-num') || 'invoice';
            var was = b.textContent;
            b.disabled = true; b.textContent = 'Preparing\u2026';
            try {
              var t; try { t = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
              var hh = { Authorization: 'Bearer ' + t };
              try { var ag = sessionStorage.getItem('kt_active_agency_id'); if (ag) hh['X-Active-Agency-Id'] = ag; } catch (e) {}
              var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
              var res = await fetch(base + '/invoices/' + b.getAttribute('data-id') + '/pdf', { headers: hh });
              if (!res.ok) { throw new Error('That invoice could not be rendered as a PDF.'); }
              var url = URL.createObjectURL(await res.blob());
              var a = document.createElement('a');
              a.href = url; a.download = num + '.pdf';
              document.body.appendChild(a); a.click(); a.remove();
              setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
            } catch (e) {
              if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((e && e.message) || 'Could not download that invoice.', 'error'); }
            }
            b.disabled = false; b.textContent = was;
          })();
          return;
        }
        if (act === 'ktvoid') {
          (async function () {
            var num = b.getAttribute('data-num') || 'this invoice';
            var amt = Number(b.getAttribute('data-amt') || 0);
            var ok = await KT.confirm({
              title: 'Void ' + num + '?',
              description: 'This cancels ' + money(amt) + ' for good. The invoice keeps its number and '
                + 'stays here marked Void, any payment-schedule instalment behind it is withdrawn, and '
                + 'the family is emailed to say it is cancelled and that they owe nothing. The office is '
                + 'emailed too. This cannot be undone.',
              okLabel: 'Void invoice',
              tone: 'danger',
            });
            if (!ok) { return; }
            /* The reason goes into the audit row and the staff email, and is deliberately
               NOT shown to the family. Cancelling this prompt cancels the void — the
               confirm asked whether to void, not whether to answer a second question. */
            var why = await KT.prompt({
              title: 'Why is ' + num + ' being voided?',
              description: 'Recorded in the audit log and sent to admins and directors. The family is '
                + 'not shown this note.',
              fields: [{ key: 'reason', label: 'Reason', placeholder: 'e.g. raised in error, family withdrew' }],
              okLabel: 'Void invoice',
            });
            if (why == null) { return; }
            try {
              var r = await Api.post('/director/invoices/' + b.getAttribute('data-id') + '/void',
                { reason: String(why).trim() || null });
              if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((r && r.message) || (num + ' voided'), 'success'); }
              load(container);
            } catch (e) {
              if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((e && e.message) || 'Could not void that invoice.', 'error'); }
            }
          })();
          return;
        }
        if (act === 'ktedit') {
          var ktId = b.getAttribute('data-id');
          var kinv = (d.invoices || []).find(function (x) { return String(x.id) === String(ktId); });
          openKtInvoiceEdit(container, kinv || { id: ktId });
          return;
        }
        if (act === 'edit') {
          var inv = (d.invoices || []).find(function (x) { return String(x.id) === b.getAttribute('data-id'); });
          if (inv) openInvoiceEdit(container, inv);
        }
      });
    });
  }

  async function render(container) {
    container.setAttribute('data-kt-pretty', '1');
    state = { page: 1, family_id: 0, search: '', sort: '', dir: 'asc', busy: false, status: '' };
    container.innerHTML =
      // Left-aligned like every other billing screen; `margin:0 auto` centred it.
      '<div style="padding:24px;max-width:1400px;">'
      + '<div class="kt-page-hero"><h2>🧾 Accounting</h2><p>Invoices and balances for the agency. Read-only — payments and balances update automatically as they change at the source.</p></div>'
      + '<div id="xb-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px;"></div>'
      // Voided invoices are excluded from the default list on purpose — they are not
      // part of what is outstanding — so they need a tab of their own to be reachable.
      + '<div id="xb-tabs" class="kt-subtabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;"></div>'
      + '<div class="kt-card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">'
      +   '<select id="xb-party" style="padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;min-width:180px;background:#fff;">'
      +     '<option value="">Everyone</option>'
      +     '<option value="parent">Parents</option>'
      +     '<option value="educator">' + esc(payeeLabel() + 's') + '</option>'
      +     '<option value="contractor">Contractors</option>'
      +     '<option value="misc">Other</option>'
      +   '</select>'
      +   '<select id="xb-family" style="padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;min-width:220px;background:#fff;"><option value="0">All families</option></select>'
      +   '<input id="xb-search" placeholder="🔍 Search invoice # / description / status…" style="flex:1;min-width:220px;padding:9px 12px;border:1px solid #E2E8F0;border-radius:9px;font-size:13.5px;box-sizing:border-box;">'
      + '</div>'
      + '<div id="xb-body"></div></div>';

    var TABS = [
      /* "Open" is gone (2026-09-17). It filtered on the literal stored status `open`,
         which 129 synced iLearn invoices carry — but since the Scheduled work in
         September the table labels a not-yet-due invoice "Scheduled", so the tab said
         Open while almost every row inside it said Scheduled. Anthony: "there is one
         that shows open (why and remove that open icon)". Nothing becomes unreachable:
         Outstanding is everything except voided, so those rows are still one click away.
         See [[kiddietrac-invoice-scheduled-status]]. */
      { key: '', label: 'Outstanding', hint: 'Everything except voided' },
      { key: 'paid', label: 'Paid' },
      { key: 'overdue', label: 'Overdue' },
      { key: 'void', label: 'Voided', hint: 'Raised, then cancelled' },
    ];
    var tabBar = container.querySelector('#xb-tabs');
    function paintTabs() {
      tabBar.innerHTML = TABS.map(function (t) {
        var on = state.status === t.key;
        return '<button type="button" data-xb-tab="' + t.key + '"' + (t.hint ? ' title="' + esc(t.hint) + '"' : '')
          + ' style="background:none;border:0;border-bottom:2px solid ' + (on ? '#1F6FB2' : 'transparent')
          + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B')
          + ';cursor:pointer;border-radius:8px 8px 0 0;">' + esc(t.label) + '</button>';
      }).join('');
      tabBar.querySelectorAll('[data-xb-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          state.status = b.getAttribute('data-xb-tab');
          state.page = 1;
          paintTabs();
          load(container);
        });
      });
    }
    paintTabs();

    var partySel = container.querySelector('#xb-party');
    if (partySel) {
      partySel.value = state.counterparty || '';
      partySel.addEventListener('change', function () {
        state.counterparty = partySel.value || '';
        /* The family filter only means something for parents — a contractor has no
           family — so it is cleared rather than left applying invisibly. */
        if (state.counterparty && state.counterparty !== 'parent') { state.family_id = 0; }
        state.page = 1;
        load(container);
      });
    }
    var famSel = container.querySelector('#xb-family');
    famSel.addEventListener('change', function () { state.family_id = +famSel.value || 0; state.page = 1; load(container); });
    var searchEl = container.querySelector('#xb-search');
    var t = null;
    searchEl.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { state.search = searchEl.value.trim(); state.page = 1; load(container); }, 300);
    });

    load(container);
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':external-billing', render);
    });
  }
  KT.ExternalBilling = { render: render };
})(window);
