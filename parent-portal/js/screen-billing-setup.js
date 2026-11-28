/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p87 — Billing (tabbed): Setup + Settings in one full-width
   section. "Setup" = defaults, late fees, payment methods, autopay. "Settings"
   = SMS + default language (the former standalone "Billing settings").
   Hash: #billing-setup  ·  agency_admin
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom;

  var METHODS = [
    ['stripe_card', '💳 Credit / debit card'],
    ['stripe_ach', '🏦 Bank transfer (ACH)'],
    ['interac', '✉️ Interac e-Transfer'],
    ['cash', '💵 Cash'],
    ['cheque', '🧾 Cheque'],
    ['manual', '✍️ Other / manual'],
  ];
  var FREQS = [['weekly', 'Weekly'], ['biweekly', 'Bi-weekly'], ['monthly', 'Monthly']];

  function inputStyle() { return 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;'; }
  function label(t) { return Dom.el('label', { style: 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;' }, t); }
  function card(title, sub) {
    var c = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:22px 24px;margin-bottom:16px;' });
    c.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:16px;color:#111827;' }, title));
    if (sub) c.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;margin-bottom:16px;' }, sub));
    return c;
  }

  var activeTab = 'setup';

  function render(container) {
    Dom.clear(container);
    /* LEFT-ALIGNED, AND NO HORIZONTAL PADDING WHEN HOSTED.

       Two separate things pushed this pane out of line with everything else under
       Billing. `margin:0 auto` CENTRED it, so on a wide screen the content drifted
       right while the subtab strip above stayed left. And the pane is rendered INSIDE
       #bs-pane, which already sits in a container with 24px of side padding — so its
       own 24px stacked on top and the content started 48px in, a visible step to the
       right of the tabs naming it.

       Hosted: vertical padding only, and the host's own padding does the aligning.
       Standalone (its own screen, no host): keep the 24px so it does not hug the
       window edge. (Anthony, 2026-09-09) */
    var _hosted = !!(container && container.closest && container.closest('#bs-pane'));
    var wrap = Dom.el('div', { style: 'padding:24px ' + (_hosted ? '0' : '24px') + ';max-width:1100px;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#16637A 70%,#0EA5A0 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">💳 BILLING</div><h1>Billing</h1><div class="kt-hero-sub">Defaults, late fees, payment methods, autopay and communication settings — all in one place.</div>';
    wrap.appendChild(hero);

    // Centre directors get ONLY the Reminders tab — Setup/Settings use
    // agency-admin-only endpoints.
    var _roles = (function () { try { return (JSON.parse(sessionStorage.getItem('kt_user') || '{}').roles) || []; } catch (e) { return []; } })();
    var fullBilling = _roles.indexOf('agency_admin') !== -1 || _roles.indexOf('platform_admin') !== -1;
    if (!fullBilling && activeTab !== 'reminders') activeTab = 'reminders';

    // Tab bar
    var tabs = Dom.el('div', { style: 'display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin:18px 0 20px;overflow-x:auto;overflow-y:hidden;' });
    function tabBtn(key, lbl) {
      var on = activeTab === key;
      var b = Dom.el('button', { style: 'padding:11px 18px;border:none;background:transparent;cursor:pointer;font-size:14px;font-weight:600;' + (on ? 'color:#1F6080;border-bottom:2px solid #1F6080;margin-bottom:-1px;' : 'color:#6B7280;') }, lbl);
      b.addEventListener('click', function () { activeTab = key; render(container); });
      return b;
    }
    if (fullBilling) {
      tabs.appendChild(tabBtn('setup', '⚙️ Setup'));
      tabs.appendChild(tabBtn('settings', '🛠 Settings'));
    }
    tabs.appendChild(tabBtn('reminders', '🔔 Reminders'));
    wrap.appendChild(tabs);

    var content = Dom.el('div', {});
    wrap.appendChild(content);
    if (activeTab === 'reminders') renderRemindersTab(content);
    else if (activeTab === 'settings') renderSettingsTab(content);
    else renderSetupTab(content);
  }

  function renderSetupTab(wrap) {
    var loadingEl = Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…');
    wrap.appendChild(loadingEl);

    Api.get('/admin/billing-setup').then(function (res) {
      loadingEl.remove();
      var d = res.data || {};
      var cur = d.currency || 'CAD';
      var inputs = {};

      var c1 = card('Billing defaults', 'Pre-selected when you create invoices and fee plans.');
      var g1 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;' });
      var fW = Dom.el('div', {}); fW.appendChild(label('Default frequency'));
      var fSel = Dom.el('select', { style: inputStyle() });
      FREQS.forEach(function (f) { var o = Dom.el('option', { value: f[0] }, f[1]); if (d.default_billing_frequency === f[0]) o.selected = true; fSel.appendChild(o); });
      fW.appendChild(fSel); g1.appendChild(fW); inputs.default_billing_frequency = fSel;
      var depW = Dom.el('div', {}); depW.appendChild(label('Deposit (' + cur + ')'));
      var depIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: d.deposit_amount || 0, style: inputStyle() });
      depW.appendChild(depIn); g1.appendChild(depW); inputs.deposit_amount = depIn;
      var regW = Dom.el('div', {}); regW.appendChild(label('Registration fee (' + cur + ')'));
      var regIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: d.registration_fee || 0, style: inputStyle() });
      regW.appendChild(regIn); g1.appendChild(regW); inputs.registration_fee = regIn;
      c1.appendChild(g1); wrap.appendChild(c1);

      /* WHEN A SCHEDULED INVOICE GOES OUT (2026-09-17).

         A payment schedule raises each instalment's invoice as a draft and issues it
         automatically when the day comes. That day used to be the FIRST OF THE MONTH,
         which is not a lead time at all: on a biweekly plan the 9th and the 23rd both
         went out on the 1st, so the family had 8 days' notice on one invoice and 22 on
         the next, while a 4th-of-the-month instalment got 3.

         It is per-agency because how much warning a family gets is an agency's own
         policy, and it is here rather than under Reminders because it governs when the
         invoice itself is raised, not when somebody is chased about it. */
      var c1b = card('Payment schedules', 'When each instalment\u2019s invoice is issued to the family.');
      var leadW = Dom.el('div', { style: 'max-width:260px;' });
      leadW.appendChild(label('Issue invoices this many days before they are due'));
      var leadIn = Dom.el('input', {
        type: 'number', min: '0', max: '60', step: '1',
        value: (d.invoice_issue_lead_days == null ? 5 : d.invoice_issue_lead_days),
        style: inputStyle(),
      });
      leadW.appendChild(leadIn); inputs.invoice_issue_lead_days = leadIn;
      c1b.appendChild(leadW);
      c1b.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;margin-top:8px;line-height:1.5;' },
        'Each instalment stays a pending draft until this many days before its due date, '
        + 'then issues on its own and the family owes it. Set 0 to issue on the due date '
        + 'itself. Due dates never change \u2014 only how much notice the family gets.'));
      /* Due-now window (2026-10-28): what a family sees on their Billing screen. */
      var winW = Dom.el('div', { style: 'max-width:260px;margin-top:14px;' });
      winW.appendChild(label('Show unpaid invoices to families this many days before they are due'));
      var winIn = Dom.el('input', { type: 'number', min: '0', max: '120', step: '1',
        value: (d.due_window_days == null ? 14 : d.due_window_days), style: inputStyle() });
      winW.appendChild(winIn); inputs.due_window_days = winIn;
      c1b.appendChild(winW);
      c1b.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;margin-top:8px;line-height:1.5;' },
        'A family’s Billing screen and “due now” total only include invoices due within this many days. '
        + 'Overdue invoices always show. Later ones appear on their own when they come up.'));
      wrap.appendChild(c1b);

      var c2 = card('Late fees', 'Charged on overdue balances.');
      var g2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;' });
      var lfp = Dom.el('input', { type: 'number', step: '0.01', min: '0', max: '25', value: d.late_fee_percent, style: inputStyle() });
      var lfpW = Dom.el('div', {}); lfpW.appendChild(label('Late fee (%)')); lfpW.appendChild(lfp); g2.appendChild(lfpW); inputs.late_fee_percent = lfp;
      var lfc = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: d.late_fee_cap, style: inputStyle() });
      var lfcW = Dom.el('div', {}); lfcW.appendChild(label('Cap (' + cur + ')')); lfcW.appendChild(lfc); g2.appendChild(lfcW); inputs.late_fee_cap = lfc;
      var lfg = Dom.el('input', { type: 'number', min: '0', max: '60', value: d.late_fee_grace_days, style: inputStyle() });
      var lfgW = Dom.el('div', {}); lfgW.appendChild(label('Grace (days)')); lfgW.appendChild(lfg); g2.appendChild(lfgW); inputs.late_fee_grace_days = lfg;
      c2.appendChild(g2); wrap.appendChild(c2);

      /* LATE PICKUP FEE (2026-11-27): the per-minute rate and grace minutes the Late pickups screen suggests a fee
         from. They could not be set anywhere, so every agency ran on $1.00 a minute after 5 minutes. */
      var c2b = card('Late pickup fee', 'Suggested when staff log a late pickup. A director or admin still approves, waives or declines it.');
      var g2b = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;' });
      var lpr = Dom.el('input', { type: 'number', step: '0.05', min: '0', max: '50', value: (d.late_pickup_per_minute == null ? 1 : d.late_pickup_per_minute), style: inputStyle() });
      var lprW = Dom.el('div', {}); lprW.appendChild(label('Fee per minute (' + cur + ')')); lprW.appendChild(lpr); g2b.appendChild(lprW); inputs.late_pickup_per_minute = lpr;
      var lpg = Dom.el('input', { type: 'number', step: '1', min: '0', max: '120', value: (d.late_pickup_grace_minutes == null ? 5 : d.late_pickup_grace_minutes), style: inputStyle() });
      var lpgW = Dom.el('div', {}); lpgW.appendChild(label('Grace (minutes before the fee starts)')); lpgW.appendChild(lpg); g2b.appendChild(lpgW); inputs.late_pickup_grace_minutes = lpg;
      c2b.appendChild(g2b); wrap.appendChild(c2b);

      var c3 = card('Accepted payment methods', 'Which ways families may pay.');
      var methodWrap = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:8px;' });
      var methodChecks = {};
      var accepted = d.accepted_payment_methods || [];
      METHODS.forEach(function (m) {
        var l = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer;font-size:14px;color:#111827;' });
        var cb = Dom.el('input', { type: 'checkbox' });
        if (accepted.indexOf(m[0]) !== -1) cb.checked = true;
        methodChecks[m[0]] = cb;
        l.appendChild(cb); l.appendChild(Dom.el('span', {}, m[1]));
        methodWrap.appendChild(l);
      });
      c3.appendChild(methodWrap);
      /* Interac e-Transfer details (2026-11-11) live in Payment providers -> Interac e-Transfer: the address, the
         recipient name, Autodeposit or the security question, the agency's instructions and e-Transfer requests. */
      var ieW = Dom.el('div', { style: 'margin-top:14px;font-size:13px;color:#334155;display:flex;align-items:center;gap:10px;flex-wrap:wrap;' });
      ieW.appendChild(Dom.el('img', { src: '/img/interac-logo.svg', alt: 'Interac', width: '28', height: '28', style: 'width:28px;height:28px;border-radius:6px;' }));
      ieW.appendChild(Dom.el('span', {}, d.interac_email ? 'Interac e-Transfers go to ' + d.interac_email + '.' : 'Interac e-Transfer is not set up yet.'));
      var ieA = Dom.el('a', { href: '#payment-providers?tab=interac', style: 'color:#1F6080;font-weight:700;' }, 'Set up in Payment providers →');
      ieW.appendChild(ieA);
      c3.appendChild(ieW);
      /* Card on invoices (2026-10-31, Anthony: "only add the interac for now - if we enable credit/debit add this as a
         configuration setting"). Off by default; shows only once a card processor is live for the agency. */
      var ccCb = Dom.el('input', { type: 'checkbox' }); if (d.invoice_show_card === true) ccCb.checked = true;
      var ccL = Dom.el('label', { style: 'display:flex;align-items:flex-start;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-top:14px;line-height:1.4;max-width:520px;' });
      ccL.appendChild(ccCb);
      ccL.appendChild(Dom.el('span', {}, [Dom.el('span', {}, 'Credit/debit card (shown on invoices)'),
        Dom.el('span', { style: 'display:block;font-size:12.5px;color:#6B7280;margin-top:2px;' }, 'Adds “Pay by credit or debit card in the app” to the How to pay section of invoices and invoice emails. It only appears once your card payments are live. Interac e-Transfer shows once it is set up in Payment providers.')]));
      c3.appendChild(ccL); inputs.invoice_show_card = ccCb;
      /* New invoices as announcements (2026-10-31, Anthony: "when there is a new invoice or payroll make this an
         announcement for each user"). On unless switched off. */
      var anCb = Dom.el('input', { type: 'checkbox' }); if (d.announce_invoices !== false) anCb.checked = true;
      var anL = Dom.el('label', { style: 'display:flex;align-items:flex-start;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-top:14px;line-height:1.4;max-width:520px;' });
      anL.appendChild(anCb);
      anL.appendChild(Dom.el('span', {}, [Dom.el('span', {}, 'Announce new invoices to families'),
        Dom.el('span', { style: 'display:block;font-size:12.5px;color:#6B7280;margin-top:2px;' }, 'Each new invoice also appears in the paying parent’s News, just for them, with their amount (their share when the bill is split) and View invoice / Pay buttons.')]));
      c3.appendChild(anL); inputs.announce_invoices = anCb;
      wrap.appendChild(c3);

      var c4 = card('Autopay', 'Automatically charge saved cards when invoices are due.');
      var apDef = Dom.el('input', { type: 'checkbox' }); if (d.autopay_default) apDef.checked = true;
      var apReq = Dom.el('input', { type: 'checkbox' }); if (d.autopay_required) apReq.checked = true;
      var apDefL = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:10px;' });
      apDefL.appendChild(apDef); apDefL.appendChild(Dom.el('span', {}, 'Enrol new families in autopay by default'));
      var apReqL = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:14px;' });
      apReqL.appendChild(apReq); apReqL.appendChild(Dom.el('span', {}, 'Require autopay (families must keep a card on file)'));
      c4.appendChild(apDefL); c4.appendChild(apReqL);
      inputs.autopay_default = apDef; inputs.autopay_required = apReq;
      wrap.appendChild(c4);

      /* PAYMENT SURCHARGES (2026-09-17).

         Anthony: "a percentage configurator to allow the total invoice to be calculated
         by x percent and comes up as a service fee for debit/credit. Same goes for
         EFT/Interac a configurable option - this can sit in the setting section somewhere
         under payments configuration."

         `card_surcharge_percent` already existed here and was READ BY NOTHING - stored,
         editable, and applied nowhere, the same shape as the feature flags that turned
         out to be decorative. It is wired up now, and the bank rails get their own rate:
         card is typically 2-3% while an Interac e-Transfer is a flat cost well under 1%,
         so one number could never have served both. */
      var c5 = card('Payment surcharges', 'Passed on to families as a service fee when they pay.');
      var g5 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;' });

      var cardW = Dom.el('div', {});
      cardW.appendChild(label('Credit / debit card (%)'));
      var surIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', max: '10',
        value: d.card_surcharge_percent || 0, style: inputStyle() });
      cardW.appendChild(surIn); g5.appendChild(cardW);
      inputs.card_surcharge_percent = surIn;

      var eftW = Dom.el('div', {});
      eftW.appendChild(label('EFT / Interac e-Transfer (%)'));
      var eftIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', max: '10',
        value: d.eft_surcharge_percent || 0, style: inputStyle() });
      eftW.appendChild(eftIn); g5.appendChild(eftW);
      inputs.eft_surcharge_percent = eftIn;

      c5.appendChild(g5);
      c5.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;margin-top:8px;line-height:1.55;' },
        'Charged on the amount being paid, not on the invoice total — a family paying in two '
        + 'instalments is charged the fee on each, which is what the processor bills. Cash and '
        + 'cheque are never surcharged. Set 0 to absorb the cost yourself. The fee is added as a '
        + 'visible line on the invoice, and whoever records the payment confirms it first.'));
      var surPrev = Dom.el('div', { style: 'font-size:12.5px;color:#334155;margin-top:8px;padding:8px 11px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;' });
      c5.appendChild(surPrev);

      /* A worked example, because a percentage on its own does not tell anybody what a
         parent will actually be charged. */
      var showPrev = function () {
        var cp = parseFloat(surIn.value) || 0;
        var ep = parseFloat(eftIn.value) || 0;
        surPrev.innerHTML = 'On a <strong>$500.00</strong> payment: card adds <strong>$'
          + (500 * cp / 100).toFixed(2) + '</strong>, EFT / Interac adds <strong>$'
          + (500 * ep / 100).toFixed(2) + '</strong>.';
      };
      surIn.addEventListener('input', showPrev);
      eftIn.addEventListener('input', showPrev);
      showPrev();
      wrap.appendChild(c5);
      var msg = Dom.el('div', { style: 'min-height:20px;font-size:13px;margin:6px 0;' });
      wrap.appendChild(msg);
      var saveBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:11px 26px;border-radius:9px;font-weight:700;cursor:pointer;font-size:15px;' }, 'Save billing setup');
      saveBtn.addEventListener('click', function () {
        var payload = {
          default_billing_frequency: inputs.default_billing_frequency.value,
          deposit_amount: parseFloat(inputs.deposit_amount.value) || 0,
          registration_fee: parseFloat(inputs.registration_fee.value) || 0,
          /* parseInt, not `|| 0` on a float: 0 is a legitimate setting here (issue on
             the due date), and it must survive rather than be treated as "unset". */
          invoice_issue_lead_days: (function (v) {
            var n = parseInt(v, 10);
            return isNaN(n) ? 5 : Math.max(0, Math.min(60, n));
          })(inputs.invoice_issue_lead_days.value),
          late_fee_percent: parseFloat(inputs.late_fee_percent.value) || 0,
          late_fee_cap: parseFloat(inputs.late_fee_cap.value) || 0,
          late_fee_grace_days: parseInt(inputs.late_fee_grace_days.value, 10) || 0,
          late_pickup_per_minute: (function (v) { var n = parseFloat(v); return isNaN(n) ? 1 : Math.max(0, Math.min(50, n)); })(inputs.late_pickup_per_minute.value),
          late_pickup_grace_minutes: (function (v) { var n = parseInt(v, 10); return isNaN(n) ? 5 : Math.max(0, Math.min(120, n)); })(inputs.late_pickup_grace_minutes.value),
          autopay_default: inputs.autopay_default.checked,
          autopay_required: inputs.autopay_required.checked,
          card_surcharge_percent: parseFloat(inputs.card_surcharge_percent.value) || 0,
          eft_surcharge_percent: parseFloat(inputs.eft_surcharge_percent.value) || 0,
          accepted_payment_methods: Object.keys(methodChecks).filter(function (k) { return methodChecks[k].checked; }),
          invoice_show_card: !!inputs.invoice_show_card.checked,
          announce_invoices: !!inputs.announce_invoices.checked,
          due_window_days: (function (v) { var n = parseInt(v, 10); return isNaN(n) ? 14 : Math.max(0, Math.min(120, n)); })(inputs.due_window_days.value),
        };
        saveBtn.disabled = true; msg.style.color = '#1F6080'; msg.textContent = 'Saving…';
        Api.patch('/admin/billing-setup', payload).then(function () {
          msg.style.color = '#16A34A'; msg.textContent = '✓ Saved.';
          if (Dom.toast) Dom.toast('Billing setup saved', 'success');
        }).catch(function (e) {
          msg.style.color = '#DC2626'; msg.textContent = 'Save failed: ' + (e.message || 'error');
        }).finally(function () { saveBtn.disabled = false; });
      });
      wrap.appendChild(saveBtn);
    }).catch(function (e) {
      loadingEl.textContent = 'Could not load billing setup: ' + (e.message || 'error');
      loadingEl.style.color = '#DC2626';
    });
  }

  function renderSettingsTab(wrap) {
    var loadingEl = Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…');
    wrap.appendChild(loadingEl);
    Api.get('/admin/billing-config').then(function (res) {
      loadingEl.remove();
      var a = res.data || {};
      var c = card('Communication & locale', 'Applies to billing reminders and parent-facing language.');
      var emailL = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:12px;' });
      var emailIn = Dom.el('input', { type: 'checkbox' }); if (a.email_enabled !== false) emailIn.checked = true;
      emailL.appendChild(emailIn); emailL.appendChild(Dom.el('span', {}, '✉️ Enable email for this agency (invoices, reminders, announcements)'));
      c.appendChild(emailL);
      var smsL = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:16px;' });
      var smsIn = Dom.el('input', { type: 'checkbox' }); if (a.sms_enabled) smsIn.checked = true;
      smsL.appendChild(smsIn); smsL.appendChild(Dom.el('span', {}, '📱 Enable SMS for this agency (billing reminders, alerts)'));
      c.appendChild(smsL);
      var locW = Dom.el('div', { style: 'max-width:280px;' });
      locW.appendChild(label('Default language'));
      var locSel = Dom.el('select', { style: inputStyle() });
      [['en', 'English'], ['fr', 'Français'], ['es', 'Español'], ['hi', 'हिन्दी (Hindi)']].forEach(function (o) { var op = Dom.el('option', { value: o[0] }, o[1]); if (a.default_locale === o[0]) op.selected = true; locSel.appendChild(op); });
      locW.appendChild(locSel); c.appendChild(locW);
      wrap.appendChild(c);

      var msg = Dom.el('div', { style: 'min-height:20px;font-size:13px;margin:6px 0;' });
      wrap.appendChild(msg);
      var saveBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:11px 26px;border-radius:9px;font-weight:700;cursor:pointer;font-size:15px;' }, 'Save settings');
      saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true; msg.style.color = '#1F6080'; msg.textContent = 'Saving…';
        Api.patch('/admin/billing-config', {
          late_fee_percent: a.late_fee_percent, late_fee_cap: a.late_fee_cap, late_fee_grace_days: a.late_fee_grace_days,
          sms_enabled: smsIn.checked, email_enabled: emailIn.checked, default_locale: locSel.value,
        }).then(function () {
          msg.style.color = '#16A34A'; msg.textContent = '✓ Saved.';
          if (Dom.toast) Dom.toast('Settings saved', 'success');
        }).catch(function (e) {
          msg.style.color = '#DC2626'; msg.textContent = 'Save failed: ' + (e.message || 'error');
        }).finally(function () { saveBtn.disabled = false; });
      });
      wrap.appendChild(saveBtn);
    }).catch(function (e) {
      loadingEl.textContent = 'Could not load settings: ' + (e.message || 'error');
      loadingEl.style.color = '#DC2626';
    });
  }

  function renderRemindersTab(wrap) {
    var loadingEl = Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…');
    wrap.appendChild(loadingEl);
    Api.get('/admin/billing-reminders').then(function (res) {
      loadingEl.remove();
      var r = (res && res.reminders) || {};
      var globalOn = !!(res && res.global_enabled);
      var ins = {};

      /* The invoice style lives in Branding, with the logo and the colour — an
         invoice's look is the agency's brand, and Anthony asked for it there. It was
         briefly here; two editors for one setting is how the two drift. (2026-09-17) */
      if (!globalOn) {
        wrap.appendChild(Dom.el('div', { style: 'background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:12px 14px;font-size:13px;margin-bottom:14px;' },
          '⏸ Automated reminders are currently OFF platform-wide. Your schedule is saved and will start sending once reminders are enabled for the platform.'));
      }
      function toggleRow(key, text, on) {
        var l = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:12px;' });
        var cb = Dom.el('input', { type: 'checkbox' }); if (on) cb.checked = true;
        ins[key] = cb; l.appendChild(cb); l.appendChild(Dom.el('span', {}, text));
        return l;
      }
      function textRow(key, lbl, val, ph, w) {
        var d = Dom.el('div', { style: 'max-width:' + (w || '220px') + ';' });
        d.appendChild(label(lbl));
        var i = Dom.el('input', { type: 'text', value: (val == null ? '' : String(val)), placeholder: ph || '', style: inputStyle() });
        ins[key] = i; d.appendChild(i); return d;
      }

      /* HOW OFTEN, WHO IS COPIED, HOW MANY TIMES (2026-10-31, Anthony: "when invoice reminders are send cc admin/directors
         for that agency and wire this up in settings where you can setup how often, who gets notified and how many times.
         When invoices are overdue the emails should be sent everyday until paid"). */
      wrap.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;margin-bottom:14px;' },
        'Automatic payment reminders to families. Each guardian gets their own email with what they owe (their share when the bill is split). At most one reminder per invoice per person per day.'));
      function sel(key, lbl, val, opts, w) {
        var d = Dom.el('div', { style: 'max-width:' + (w || '260px') + ';margin-bottom:12px;' });
        d.appendChild(label(lbl));
        var x = Dom.el('select', { style: inputStyle() });
        opts.forEach(function (o) { var op = Dom.el('option', { value: o[0] }, o[1]); if (String(val) === String(o[0])) op.selected = true; x.appendChild(op); });
        ins[key] = x; d.appendChild(x); return d;
      }
      function num(key, lbl, val, min, max, w) {
        var d = Dom.el('div', { style: 'max-width:' + (w || '200px') + ';margin-bottom:12px;' });
        d.appendChild(label(lbl));
        var x = Dom.el('input', { type: 'number', min: String(min), max: String(max), value: String(val == null ? '' : val), style: inputStyle() });
        ins[key] = x; d.appendChild(x); return d;
      }

      var c1 = card('Before the due date', 'A friendly heads-up before an invoice is due.');
      c1.appendChild(toggleRow('invoice_enabled', 'Send reminders before the due date', r.invoice_enabled));
      c1.appendChild(textRow('invoice_days_before', 'Days before the due date (comma-separated)', r.invoice_days_before, 'e.g. 7,3,1', '320px'));
      c1.appendChild(toggleRow('due_today', 'Also remind on the due date itself', r.due_today !== false));
      wrap.appendChild(c1);

      var c2 = card('Overdue', 'Keep reminding until the invoice (or that guardian’s share) is paid.');
      c2.appendChild(toggleRow('overdue_enabled', 'Send overdue reminders', r.overdue_enabled));
      var modeW = sel('overdue_mode', 'How often', r.overdue_mode || 'daily', [
        ['daily', 'Every day until paid'], ['every', 'Every few days'], ['weekly', 'Once a week'], ['days', 'On set days after the due date']]);
      c2.appendChild(modeW);
      var everyW = num('overdue_every', 'Every how many days', r.overdue_every || 3, 1, 60);
      var daysW = textRow('overdue_days_after', 'Days after the due date (comma-separated)', r.overdue_days_after, 'e.g. 1,7,14', '320px');
      c2.appendChild(everyW); c2.appendChild(daysW);
      c2.appendChild(num('overdue_max', 'Stop after this many overdue reminders (0 = until paid)', r.overdue_max || 0, 0, 365, '360px'));
      function showMode() { var m = ins.overdue_mode.value; everyW.style.display = m === 'every' ? '' : 'none'; daysW.style.display = m === 'days' ? '' : 'none'; }
      ins.overdue_mode.addEventListener('change', showMode); showMode();
      wrap.appendChild(c2);

      var c3 = card('Who gets a copy', 'Copies are hidden from the family (BCC), so they never see the office’s addresses.');
      /* STAFF COPIES ON/OFF (2026-11-26, Anthony): one switch for all of it; the choices below only apply while it is on. */
      c3.appendChild(toggleRow('cc_enabled', 'Send staff a copy of each reminder', !!r.cc_enabled));
      var ccBody = Dom.el('div', { style: 'padding-left:4px;border-left:3px solid #E2E8F0;padding-left:12px;margin-left:6px;' });
      c3.appendChild(ccBody);
      var c3a = c3; c3 = ccBody;
      c3.appendChild(toggleRow('cc_admins', 'Agency admins', r.cc_admins !== false));
      c3.appendChild(toggleRow('cc_directors', 'Directors of the invoice’s centre', r.cc_directors !== false));
      c3.appendChild(toggleRow('cc_admin', 'The agency billing contact address', r.cc_admin));
      var staff = (res && res.staff) || [];
      var pickedStaff = (r.cc_staff || []).map(Number);
      var others = staff.filter(function (x) { return x.role === 'Staff'; });
      var staffBoxes = [];
      if (others.length) {
        c3.appendChild(label('Also copy these staff'));
        var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-top:4px;' });
        others.forEach(function (x) {
          var l = Dom.el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:13px;color:#111827;cursor:pointer;padding:5px 8px;border:1px solid #E2E8F0;border-radius:8px;' });
          var cb = Dom.el('input', { type: 'checkbox' }); if (pickedStaff.indexOf(Number(x.user_id)) !== -1) cb.checked = true;
          staffBoxes.push([cb, x.user_id]); l.appendChild(cb); l.appendChild(Dom.el('span', {}, x.name));
          grid.appendChild(l);
        });
        c3.appendChild(grid);
      }
      c3.appendChild(toggleRow('cc_issued', 'Also copy invoices emailed on their own date (instalments)', !!r.cc_issued));
      c3 = c3a;
      function ccShow() {
        var on = ins.cc_enabled.checked;
        ccBody.style.opacity = on ? '' : '.45';
        ccBody.querySelectorAll('input').forEach(function (x) { x.disabled = !on; });
      }
      ins.cc_enabled.addEventListener('change', ccShow); ccShow();
      wrap.appendChild(c3);

      var c4 = card('Delivery', '');
      var stW = Dom.el('div', { style: 'max-width:200px;margin-bottom:14px;' });
      stW.appendChild(label('Daily send time'));
      var stIn = Dom.el('input', { type: 'time', value: r.send_time || '09:00', style: inputStyle() });
      ins.send_time = stIn; stW.appendChild(stIn); c4.appendChild(stW);
      c4.appendChild(toggleRow('channel_email', '✉️ Send by email', r.channel_email !== false));
      var mW = Dom.el('div', {}); mW.appendChild(label('Custom note added to each reminder (optional)'));
      var mIn = Dom.el('textarea', { rows: '2', style: inputStyle() + 'resize:vertical;font-family:inherit;' });
      mIn.value = r.custom_message || ''; ins.custom_message = mIn; mW.appendChild(mIn); c4.appendChild(mW);
      wrap.appendChild(c4);

      var msg = Dom.el('div', { style: 'min-height:20px;font-size:13px;margin:6px 0;' });
      wrap.appendChild(msg);
      var saveBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:9px 22px;border-radius:9px;font-weight:700;cursor:pointer;font-size:14px;' }, 'Save reminders');
      saveBtn.addEventListener('click', function () {
        var payload = {
          invoice_enabled: ins.invoice_enabled.checked,
          invoice_days_before: ins.invoice_days_before.value,
          due_today: ins.due_today.checked,
          overdue_enabled: ins.overdue_enabled.checked,
          overdue_mode: ins.overdue_mode.value,
          overdue_every: Math.max(1, parseInt(ins.overdue_every.value, 10) || 3),
          overdue_days_after: ins.overdue_days_after.value,
          overdue_max: Math.max(0, parseInt(ins.overdue_max.value, 10) || 0),
          cc_enabled: ins.cc_enabled.checked,
          cc_issued: ins.cc_issued.checked,
          cc_admins: ins.cc_admins.checked,
          cc_directors: ins.cc_directors.checked,
          cc_admin: ins.cc_admin.checked,
          cc_staff: staffBoxes.filter(function (x) { return x[0].checked; }).map(function (x) { return Number(x[1]); }),
          send_time: ins.send_time.value || '09:00',
          channel_email: ins.channel_email.checked,
          custom_message: ins.custom_message.value || '',
        };
        saveBtn.disabled = true; msg.style.color = '#1F6080'; msg.textContent = 'Saving…';
        Api.post('/admin/billing-reminders', payload).then(function () {
          msg.style.color = '#16A34A'; msg.textContent = '✓ Saved.';
          if (Dom.toast) Dom.toast('Reminder schedule saved', 'success');
        }).catch(function (e) {
          msg.style.color = '#DC2626'; msg.textContent = 'Save failed: ' + (e.message || 'error');
        }).finally(function () { saveBtn.disabled = false; });
      });
      wrap.appendChild(saveBtn);

      /* WHO IS DUE NOW - read-only: what today's reminders would do with the settings above (as if they were on). */
      var c5 = card('Who is due now', 'Unpaid invoices due in the next 7 days or overdue, and who today’s reminder would go to. Nothing is sent from here.');
      c5.style.marginTop = '18px';
      var pvBtn = Dom.el('button', { style: 'background:#fff;color:#1F6080;border:1px solid #1F6080;padding:7px 14px;border-radius:9px;font-weight:700;cursor:pointer;font-size:13px;' }, '👀 Show who is due now');
      var pvOut = Dom.el('div', { style: 'margin-top:10px;' });
      c5.appendChild(pvBtn); c5.appendChild(pvOut); wrap.appendChild(c5);
      function money(n) { return '$' + Number(n || 0).toFixed(2); }
      function todayLbl(t) { if (!t) return 'nothing today'; var q = t.split(' '); return q[0] === 'before' ? 'reminder (' + q[1] + ' before)' : q[0] === 'due' ? 'due-today reminder' : 'overdue reminder (' + q[1] + ')'; }
      pvBtn.addEventListener('click', function () {
        pvBtn.disabled = true; pvOut.textContent = 'Loading…';
        Api.get('/admin/billing-reminders/preview').then(function (p) {
          pvBtn.disabled = false; Dom.clear(pvOut);
          var rows = (p && p.rows) || [];
          if (!rows.length) { pvOut.appendChild(Dom.el('div', { style: 'color:#16A34A;font-weight:700;' }, '✓ Nothing is due in the next 7 days and nothing is overdue.')); return; }
          var over = rows.filter(function (x) { return x.days_overdue > 0; });
          pvOut.appendChild(Dom.el('div', { style: 'font-size:13px;color:#334155;margin-bottom:8px;' },
            rows.length + ' invoice' + (rows.length === 1 ? '' : 's') + ' · ' + over.length + ' overdue · ' + money(rows.reduce(function (t, x) { return t + x.balance; }, 0)) + ' outstanding'));
          var t = Dom.el('table', { style: 'width:100%;border-collapse:collapse;font-size:13px;' });
          var hd = Dom.el('tr', {});
          ['Family', 'Invoice', 'Balance', 'Due', 'Status', 'Guardians · today’s reminder', 'Last reminder'].forEach(function (h) {
            hd.appendChild(Dom.el('th', { style: 'text-align:left;padding:7px 8px;border-bottom:1px solid #E2E8F0;color:#64748B;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;' }, h));
          });
          t.appendChild(hd);
          rows.forEach(function (x) {
            var tr = Dom.el('tr', {});
            var g = (x.guardians || []).map(function (y) {
              return y.name + (y.pct != null && y.pct < 99.99 ? ' (' + money(y.balance) + ')' : '') + ' · ' + todayLbl(y.today);
            }).join('\n') || (x.no_email ? 'No guardian email on file' : '—');
            var last = (x.guardians || []).map(function (y) { return y.last_reminder; }).filter(Boolean).sort().pop() || 'Never';
            [x.family || '—', x.number || ('#' + x.id), money(x.balance), x.due,
              x.days_overdue > 0 ? x.days_overdue + ' day' + (x.days_overdue === 1 ? '' : 's') + ' overdue' : (x.due_in === 0 ? 'Due today' : 'Due in ' + x.due_in + ' day' + (x.due_in === 1 ? '' : 's')),
              g, last].forEach(function (v, i) {
              tr.appendChild(Dom.el('td', { style: 'padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:top;white-space:' + (i === 5 ? 'pre-line' : 'normal') + ';' + (i === 4 && x.days_overdue > 0 ? 'color:#B91C1C;font-weight:700;' : '') }, String(v)));
            });
            t.appendChild(tr);
          });
          var scroller = Dom.el('div', { style: 'overflow-x:auto;' }); scroller.appendChild(t); pvOut.appendChild(scroller);
          var cc = rows[0] && rows[0].copies && rows[0].copies.length;
          pvOut.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:6px;' },
            (cc ? 'Each reminder is copied (hidden) to ' + cc + ' staff address' + (cc === 1 ? '' : 'es') + '. ' : '') + (globalOn ? '' : 'Reminders are not being sent yet — they are off platform-wide.')));
        }).catch(function (e) { pvBtn.disabled = false; pvOut.textContent = 'Could not load: ' + (e.message || 'error'); });
      });
    }).catch(function (e) {
      loadingEl.textContent = 'Could not load reminders: ' + (e.message || 'error');
      loadingEl.style.color = '#DC2626';
    });
  }

  if (KT.Shell && KT.Shell.registerScreen) {
    ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (role) {
      KT.Shell.registerScreen(role + ':billing-setup', render);
    });
  }
  /* The three panes on their own, for a host that already provides the page and the
     tab bar. Each takes a container and fills it — no hero, no tabs of its own. The
     standalone render() is unchanged, so #billing-setup still works as a page. */
  function paneOnly(fn) {
    return function (container) {
      Dom.clear(container);
      var wrap = Dom.el('div', { style: 'max-width:1100px;' });
      container.appendChild(wrap);
      fn(wrap);
    };
  }

  KT.BillingSetup = {
    render: render,
    renderSetup: paneOnly(renderSetupTab),
    renderSettings: paneOnly(renderSettingsTab),
    renderReminders: paneOnly(renderRemindersTab),
  };
})(window);
