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
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1100px;margin:0 auto;' });
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
    var loadingEl = Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…');
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

      var c2 = card('Late fees', 'Charged on overdue balances.');
      var g2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;' });
      var lfp = Dom.el('input', { type: 'number', step: '0.01', min: '0', max: '25', value: d.late_fee_percent, style: inputStyle() });
      var lfpW = Dom.el('div', {}); lfpW.appendChild(label('Late fee (%)')); lfpW.appendChild(lfp); g2.appendChild(lfpW); inputs.late_fee_percent = lfp;
      var lfc = Dom.el('input', { type: 'number', step: '0.01', min: '0', value: d.late_fee_cap, style: inputStyle() });
      var lfcW = Dom.el('div', {}); lfcW.appendChild(label('Cap (' + cur + ')')); lfcW.appendChild(lfc); g2.appendChild(lfcW); inputs.late_fee_cap = lfc;
      var lfg = Dom.el('input', { type: 'number', min: '0', max: '60', value: d.late_fee_grace_days, style: inputStyle() });
      var lfgW = Dom.el('div', {}); lfgW.appendChild(label('Grace (days)')); lfgW.appendChild(lfg); g2.appendChild(lfgW); inputs.late_fee_grace_days = lfg;
      c2.appendChild(g2); wrap.appendChild(c2);

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
      c3.appendChild(methodWrap); wrap.appendChild(c3);

      var c4 = card('Autopay', 'Automatically charge saved cards when invoices are due.');
      var apDef = Dom.el('input', { type: 'checkbox' }); if (d.autopay_default) apDef.checked = true;
      var apReq = Dom.el('input', { type: 'checkbox' }); if (d.autopay_required) apReq.checked = true;
      var apDefL = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:10px;' });
      apDefL.appendChild(apDef); apDefL.appendChild(Dom.el('span', {}, 'Enrol new families in autopay by default'));
      var apReqL = Dom.el('label', { style: 'display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-bottom:14px;' });
      apReqL.appendChild(apReq); apReqL.appendChild(Dom.el('span', {}, 'Require autopay (families must keep a card on file)'));
      c4.appendChild(apDefL); c4.appendChild(apReqL);
      var surW = Dom.el('div', { style: 'max-width:240px;' });
      surW.appendChild(label('Card surcharge (%) — optional'));
      var surIn = Dom.el('input', { type: 'number', step: '0.01', min: '0', max: '10', value: d.card_surcharge_percent || 0, style: inputStyle() });
      surW.appendChild(surIn); c4.appendChild(surW);
      inputs.autopay_default = apDef; inputs.autopay_required = apReq; inputs.card_surcharge_percent = surIn;
      wrap.appendChild(c4);

      var msg = Dom.el('div', { style: 'min-height:20px;font-size:13px;margin:6px 0;' });
      wrap.appendChild(msg);
      var saveBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:11px 26px;border-radius:9px;font-weight:700;cursor:pointer;font-size:15px;' }, 'Save billing setup');
      saveBtn.addEventListener('click', function () {
        var payload = {
          default_billing_frequency: inputs.default_billing_frequency.value,
          deposit_amount: parseFloat(inputs.deposit_amount.value) || 0,
          registration_fee: parseFloat(inputs.registration_fee.value) || 0,
          late_fee_percent: parseFloat(inputs.late_fee_percent.value) || 0,
          late_fee_cap: parseFloat(inputs.late_fee_cap.value) || 0,
          late_fee_grace_days: parseInt(inputs.late_fee_grace_days.value, 10) || 0,
          autopay_default: inputs.autopay_default.checked,
          autopay_required: inputs.autopay_required.checked,
          card_surcharge_percent: parseFloat(inputs.card_surcharge_percent.value) || 0,
          accepted_payment_methods: Object.keys(methodChecks).filter(function (k) { return methodChecks[k].checked; }),
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
    var loadingEl = Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…');
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
    var loadingEl = Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…');
    wrap.appendChild(loadingEl);
    Api.get('/admin/billing-reminders').then(function (res) {
      loadingEl.remove();
      var r = (res && res.reminders) || {};
      var globalOn = !!(res && res.global_enabled);
      var ins = {};
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

      wrap.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;margin-bottom:14px;' },
        'Schedule automatic reminders to families. Reminders stay off until you enable them. Days count from each invoice’s due date.'));

      var c1 = card('Invoice reminders', 'Remind families before an invoice is due.');
      c1.appendChild(toggleRow('invoice_enabled', 'Send upcoming-invoice reminders', r.invoice_enabled));
      c1.appendChild(textRow('invoice_days_before', 'Days before due date (comma-separated)', r.invoice_days_before, 'e.g. 7,3,1', '320px'));
      wrap.appendChild(c1);

      var c2 = card('Overdue payment reminders', 'Remind families after an invoice becomes overdue.');
      c2.appendChild(toggleRow('overdue_enabled', 'Send overdue-payment reminders', r.overdue_enabled));
      c2.appendChild(textRow('overdue_days_after', 'Days after due date (comma-separated)', r.overdue_days_after, 'e.g. 1,7,14', '320px'));
      wrap.appendChild(c2);

      var c3 = card('Delivery', '');
      var stW = Dom.el('div', { style: 'max-width:200px;margin-bottom:14px;' });
      stW.appendChild(label('Daily send time'));
      var stIn = Dom.el('input', { type: 'time', value: r.send_time || '09:00', style: inputStyle() });
      ins.send_time = stIn; stW.appendChild(stIn); c3.appendChild(stW);
      c3.appendChild(toggleRow('channel_email', '✉️ Send by email', r.channel_email !== false));
      c3.appendChild(toggleRow('cc_admin', 'Also copy the agency billing contact', r.cc_admin));
      var mW = Dom.el('div', {}); mW.appendChild(label('Custom note added to each reminder (optional)'));
      var mIn = Dom.el('textarea', { rows: '2', style: inputStyle() + 'resize:vertical;font-family:inherit;' });
      mIn.value = r.custom_message || ''; ins.custom_message = mIn; mW.appendChild(mIn); c3.appendChild(mW);
      wrap.appendChild(c3);

      var msg = Dom.el('div', { style: 'min-height:20px;font-size:13px;margin:6px 0;' });
      wrap.appendChild(msg);
      var saveBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:11px 26px;border-radius:9px;font-weight:700;cursor:pointer;font-size:15px;' }, 'Save reminders');
      saveBtn.addEventListener('click', function () {
        var payload = {
          invoice_enabled: ins.invoice_enabled.checked,
          invoice_days_before: ins.invoice_days_before.value,
          overdue_enabled: ins.overdue_enabled.checked,
          overdue_days_after: ins.overdue_days_after.value,
          send_time: ins.send_time.value || '09:00',
          channel_email: ins.channel_email.checked,
          cc_admin: ins.cc_admin.checked,
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
  KT.BillingSetup = { render: render };
})(window);
