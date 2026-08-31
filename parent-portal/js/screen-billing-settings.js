/* ============================================================
   KIDDIETRAC — Billing settings (agency)
   GET/POST /admin/billing-settings (agencies.settings JSON → "billing").

   The tax default lives here rather than on every invoice because a rate typed forty
   times is a rate eventually typed wrong, and 1.3% instead of 13% is not a mistake
   anybody catches by reading a total.
   ============================================================ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var Api = KT.Api, Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var INP = 'box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;';

  /* Tax, tuition plans and sibling discounts all answer "what do we charge and how".
     Three sidebar entries meant remembering three places to set fees up; as tabs they
     read as one job. The other two screens are HOSTED, not reimplemented — a second copy
     of a fee-plan editor drifts from the first the moment either is fixed. */
  /* The base set. render() copies this and may append role-specific tabs, so the
     module-level list is never mutated across visits. */
  var BASE_TABS = [
    { key: 'tax', label: '🧾 Tax' },
    { key: 'tuition', label: '💵 Tuition plans', global: 'FeePlans' },
    { key: 'siblings', label: '👨‍👩‍👧 Sibling discounts', global: 'SiblingDiscountsScreen' },
    // Hosted PANES, not the whole screen. Hosting the page put a second "Billing" banner
    // and a second row of tabs inside this one; its three panes belong beside these tabs,
    // not nested under one of them.
    { key: 'defaults', label: '⚙️ Defaults & fees', global: 'BillingSetup', fn: 'renderSetup' },
    { key: 'comms', label: '🛠 Billing comms', global: 'BillingSetup', fn: 'renderSettings' },
    { key: 'reminders', label: '🔔 Reminders', global: 'BillingSetup', fn: 'renderReminders' },
  ];

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');

    /* Appended per render, not baked into TABS, because TABS is module-level and
       would otherwise accumulate a duplicate tab on every visit. */
    var TABS = BASE_TABS.slice();
    var isPlatform = false;
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = [].concat(u.roles || [], u.role || []);
      isPlatform = roles.indexOf('platform_admin') !== -1;
    } catch (e) {}
    if (isPlatform) {
      // Every other tab in this strip carries an icon; this one did not, so it read as
      // a different kind of thing sitting at the end of the row.
      TABS.push({ key: 'platform', label: '🏦 Platform billing', local: 'platform' });
    }
    main.innerHTML = '<div style="padding:14px 24px;max-width:1000px;">'
      + '<div class="kt-page-hero"><h2>🧾 Billing</h2><p>What you charge, how it is worked out, and how families are reminded.</p></div>'
      + '<div id="bs-tabs" class="kt-subtabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;"></div>'
      + '<div id="bs-pane"></div></div>';

    var tabBar = main.querySelector('#bs-tabs');
    var pane = main.querySelector('#bs-pane');
    var active = 'tax';

    function paintTabs() {
      tabBar.innerHTML = TABS.map(function (t) {
        var on = active === t.key;
        return '<button type="button" data-bs-tab="' + t.key + '" style="background:none;border:0;border-bottom:2px solid '
          + (on ? '#1F6FB2' : 'transparent') + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:'
          + (on ? '#0F172A' : '#64748B') + ';cursor:pointer;border-radius:8px 8px 0 0;">' + t.label + '</button>';
      }).join('');
      tabBar.querySelectorAll('[data-bs-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          active = b.getAttribute('data-bs-tab');
          try { sessionStorage.setItem('kt_bs_tab', active); } catch (e) {}
          paintTabs(); paintPane();
        });
      });
    }

    function paintPane() {
      pane.innerHTML = '';
      var tab = TABS.filter(function (t) { return t.key === active; })[0];
      if (tab.local === 'platform') { return renderPlatform(pane); }
      if (! tab.global) { return renderTax(pane); }
      var mod = KT[tab.global];
      var fn = mod && (tab.fn ? mod[tab.fn] : mod.render);
      if (typeof fn === 'function') {
        // Hosted in this pane. Each screen renders into whatever container it is given.
        try {
          fn(pane);
          // A screen written to stand alone brings its own banner. This page already has
          // one, and two of them is what made this look broken. Stripped generically so
          // any screen can be hosted without being rewritten first.
          pane.querySelectorAll('.kt-hero, .kt-page-hero').forEach(function (h) { h.remove(); });
        } catch (e) {
          pane.innerHTML = '<div class="kt-card" style="color:#DC2626;">Could not load this section.</div>';
        }
      } else {
        pane.innerHTML = '<div class="kt-card" style="color:#64748B;">That section is not available for your role.</div>';
      }
    }

    try { active = sessionStorage.getItem('kt_bs_tab') || 'tax'; } catch (e) {}
    if (! TABS.some(function (t) { return t.key === active; })) { active = 'tax'; }
    paintTabs();
    paintPane();
  }


  /* Platform-admin only: the nightly billing run. Agency admins never see this — it
     governs what KiddieTrac bills THEM, not what they bill families. */
  async function renderPlatform(main) {
    var I = 'height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;width:100%;box-sizing:border-box;';
    main.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading…</div>';

    var d = await Api.get('/platform/billing-automation')
      .catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (d.__err) {
      main.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + d.__err + '</div>';
      return;
    }

    var blocked = !d.issuer_ready;
    main.innerHTML =
      '<div style="max-width:640px;">'
      + '<div style="font-weight:600;margin-bottom:2px;">Automatic billing</div>'
      + '<div style="color:#64748B;font-size:12.5px;margin-bottom:14px;">'
      + 'Runs nightly at 06:00. With both off, nothing happens on its own and you raise '
      + 'invoices by hand from Reseller → Invoices.</div>'

      + '<label style="display:flex;gap:10px;align-items:flex-start;margin-bottom:12px;cursor:pointer;">'
      + '<input type="checkbox" id="pb-raise"' + (d.auto_raise ? ' checked' : '') + ' style="margin-top:3px;">'
      + '<span><strong>Raise invoices automatically</strong>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">'
      + 'Creates drafts for any agency whose billing date has arrived. Nothing is sent.</span></span></label>'

      + '<label style="display:flex;gap:10px;align-items:flex-start;margin-bottom:6px;cursor:pointer;">'
      + '<input type="checkbox" id="pb-email"' + (d.auto_email ? ' checked' : '') + ' style="margin-top:3px;">'
      + '<span><strong>Email them to the agency automatically</strong>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">'
      + 'Issues each invoice and emails it to the agency contact with the PDF attached, '
      + 'unattended. An agency with no usable contact address is left as a draft.</span></span></label>'

      /* Stated, not hidden — the server enforces this whatever the switch says. */
      + (blocked
          ? '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;'
            + 'border-radius:8px;padding:11px 13px;font-size:12.5px;margin:10px 0 16px;">'
            + '<strong>Auto-email cannot run yet.</strong> The invoice is missing your '
            + (d.issuer_missing || []).join(' and ')
            + ', so every PDF would go out stamped INCOMPLETE. Fill these in and it will arm.'
            + '</div>'
          : '<div style="background:#DCFCE7;border:1px solid #BBF7D0;color:#166534;'
            + 'border-radius:8px;padding:11px 13px;font-size:12.5px;margin:10px 0 16px;">'
            + 'Issuer details are set — auto-email can run.</div>')

      + '<div style="font-weight:600;margin:18px 0 2px;">Your business details</div>'
      + '<div style="color:#64748B;font-size:12.5px;margin-bottom:10px;">'
      + 'Printed on every invoice KiddieTrac issues. A tax invoice must carry the '
      + 'registration number of the business charging the tax.</div>'
      + '<label style="display:block;font-size:12.5px;color:#475569;margin-bottom:4px;">Business address</label>'
      + '<textarea id="pb-addr" rows="3" placeholder="123 Main Street, Suite 4&#10;Toronto, ON  M5V 1A1&#10;Canada" '
      + 'style="' + I + 'height:auto;padding:8px 10px;font-family:inherit;">'
      + (d.issuer && d.issuer.address ? String(d.issuer.address).replace(/</g, '&lt;') : '') + '</textarea>'
      + '<label style="display:block;font-size:12.5px;color:#475569;margin:12px 0 4px;">GST/HST registration number</label>'
      + '<input id="pb-tax" placeholder="12345 6789 RT0001" style="' + I + '" value="'
      + (d.issuer && d.issuer.tax_id ? String(d.issuer.tax_id).replace(/"/g, '&quot;') : '') + '">'

      + '<div style="margin-top:18px;"><button id="pb-save" class="kt-btn">Save</button>'
      + '<span id="pb-msg" style="margin-left:10px;font-size:13px;color:#166534;"></span></div>'
      + '</div>';

    main.querySelector('#pb-save').addEventListener('click', function () {
      var btn = this;
      var msg = main.querySelector('#pb-msg');
      btn.disabled = true; msg.style.color = '#166534'; msg.textContent = '';
      Api.put('/platform/billing-automation', {
        auto_raise: main.querySelector('#pb-raise').checked,
        auto_email: main.querySelector('#pb-email').checked,
        issuer_address: main.querySelector('#pb-addr').value.trim(),
        issuer_tax_id: main.querySelector('#pb-tax').value.trim(),
      }).then(function () {
        /* Re-render rather than just flashing "Saved": enabling auto-email may have been
           downgraded by the issuer gate, and the banner has to reflect what is actually true. */
        renderPlatform(main);
      }).catch(function (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not save';
      });
    });
  }

  async function renderTax(main) {
    main.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">Loading…</div>';

    var data = await Api.get('/admin/billing-settings').catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (data.__err) {
      main.innerHTML = '<div class="kt-card" style="max-width:680px;"><div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:14px;font-size:13px;">This section is available to <b>agency administrators</b>.</div></div>';
      return;
    }

    var b = data.billing || {};

    main.innerHTML = '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🧾 Tax</h3></div>'
      + '<p style="color:#64748B;font-size:12.5px;margin:0 0 12px;">Set once here and it fills in on every invoice, where it can still be changed or switched off for a payee who is not charged tax. Tax applies to the invoice subtotal — the base charge plus any line items.</p>'

      // A GRID aligned to the start, not a flex aligned to the end. The two columns are
      // different heights — only one carries a hint — and bottom-aligning them put the
      // inputs 21px out of line with each other.
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;align-items:start;">'

      +   '<div><label for="b_rate" style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Rate</label>'
      // The % sits INSIDE the field rather than beside it, so the suffix cannot steal
      // width from one input and leave the other wider.
      +     '<div style="position:relative;">'
      +       '<input id="b_rate" type="number" min="0" max="100" step="0.01" value="' + esc(String(b.tax_rate != null ? b.tax_rate : 0)) + '" style="' + INP + 'width:100%;text-align:right;padding-right:30px;">'
      +       '<span style="position:absolute;right:11px;top:50%;transform:translateY(-50%);font-size:13px;color:#94A3B8;pointer-events:none;">%</span>'
      +     '</div>'
      +     '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;min-height:15px;">Percentage of the invoice subtotal.</div></div>'

      +   '<div><label for="b_label" style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Called</label>'
      +     '<input id="b_label" type="text" value="' + esc(b.tax_label || 'Tax') + '" placeholder="HST" style="' + INP + 'width:100%;">'
      +     '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;min-height:15px;">Appears on the invoice, e.g. HST or GST.</div></div>'
      + '</div>'

      + '<label style="display:flex;align-items:flex-start;gap:12px;padding:14px 0 4px;cursor:pointer;">'
      +   '<input id="b_on" type="checkbox" ' + (b.tax_default_on ? 'checked' : '') + ' style="margin-top:3px;width:17px;height:17px;flex-shrink:0;">'
      +   '<span><span style="font-size:14px;color:#334155;font-weight:600;">Tick "Apply tax" by default on new invoices</span>'
      +   '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">Leave off if your agency is not registered to charge tax — a rate should not sit waiting to be applied.</span></span></label>'

      + '<div id="b-preview" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;margin-top:12px;font-size:13px;color:#475569;"></div>'

      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;margin-top:16px;">'
      + '<span id="b-status" style="font-size:13px;color:#1E8E60;"></span>'
      + '<button id="b-save" style="font-size:14px;font-weight:700;padding:10px 20px;border:0;border-radius:10px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;cursor:pointer;">Save changes</button></div>'
      + '</div>';

    // A worked example, because a percentage is easier to mistype than to misread.
    function preview() {
      var r = parseFloat(main.querySelector('#b_rate').value) || 0;
      var lbl = (main.querySelector('#b_label').value || 'Tax').trim();
      var tax = Math.round(100 * r) / 100;
      main.querySelector('#b-preview').innerHTML =
        'On a $100.00 invoice: subtotal <strong>$100.00</strong>, ' + esc(lbl) + ' ' + esc(String(r)) + '% '
        + '<strong>$' + tax.toFixed(2) + '</strong>, total <strong>$' + (100 + tax).toFixed(2) + '</strong>.'
        + (r > 30 ? '<div style="color:#92400E;margin-top:4px;">That is unusually high — check it is not a typo.</div>' : '');
    }
    ['#b_rate', '#b_label'].forEach(function (sel) {
      main.querySelector(sel).addEventListener('input', preview);
    });
    preview();

    var btn = main.querySelector('#b-save');
    btn.onclick = function () {
      var body = {
        tax_rate: parseFloat(main.querySelector('#b_rate').value) || 0,
        tax_label: (main.querySelector('#b_label').value || 'Tax').trim(),
        tax_default_on: main.querySelector('#b_on').checked,
      };
      var st = main.querySelector('#b-status');
      btn.disabled = true; btn.textContent = 'Saving…'; st.textContent = '';
      Api.post('/admin/billing-settings', body).then(function () {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#1E8E60'; st.textContent = '✓ Saved';
        setTimeout(function () { st.textContent = ''; }, 2600);
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Billing settings saved', 'success');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save changes';
        st.style.color = '#BE4038'; st.textContent = 'Save failed: ' + ((e && e.message) || 'error');
      });
    };
  }

  KT.BillingSettings = { render: render };

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':billing-settings', render);
  });
})(window);
