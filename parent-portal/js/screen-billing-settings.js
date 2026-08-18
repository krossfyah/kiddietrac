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

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>🧾 Billing settings</h2><p>Loading…</p></div></div>';

    var data = await Api.get('/admin/billing-settings').catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (data.__err) {
      main.innerHTML = '<div style="padding:14px 24px;max-width:840px;"><div class="kt-page-hero"><h2>🧾 Billing settings</h2></div>'
        + '<div class="kt-card" style="max-width:680px;"><div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:14px;font-size:13px;">This page is available to <b>agency administrators</b>.</div></div></div>';
      return;
    }

    var b = data.billing || {};

    main.innerHTML = '<div style="padding:14px 24px;max-width:840px;">'
      + '<div class="kt-page-hero"><h2>🧾 Billing settings</h2><p>Defaults used when raising invoices for ' + esc(data.agency_name || 'your agency') + '.</p></div>'
      + '<div class="kt-card" style="max-width:680px;">'
      + '<div class="kt-card-header"><h3 class="kt-card-title">🧾 Tax</h3></div>'
      + '<p style="color:#64748B;font-size:12.5px;margin:0 0 12px;">Set once here and it fills in on every invoice, where it can still be changed or switched off for a payee who is not charged tax. Tax applies to the invoice subtotal — the base charge plus any line items.</p>'

      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">'
      +   '<div style="flex:1;min-width:120px;"><label for="b_rate" style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Rate</label>'
      +     '<div style="display:flex;align-items:center;gap:6px;">'
      +       '<input id="b_rate" type="number" min="0" max="100" step="0.01" value="' + esc(String(b.tax_rate != null ? b.tax_rate : 0)) + '" style="' + INP + 'width:100%;text-align:right;">'
      +       '<span style="font-size:14px;color:#64748B;">%</span></div></div>'
      +   '<div style="flex:1;min-width:120px;"><label for="b_label" style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Called</label>'
      +     '<input id="b_label" type="text" value="' + esc(b.tax_label || 'Tax') + '" placeholder="HST" style="' + INP + 'width:100%;">'
      +     '<div style="font-size:11.5px;color:#94A3B8;margin-top:3px;">Appears on the invoice, e.g. HST or GST.</div></div>'
      + '</div>'

      + '<label style="display:flex;align-items:flex-start;gap:12px;padding:14px 0 4px;cursor:pointer;">'
      +   '<input id="b_on" type="checkbox" ' + (b.tax_default_on ? 'checked' : '') + ' style="margin-top:3px;width:17px;height:17px;flex-shrink:0;">'
      +   '<span><span style="font-size:14px;color:#334155;font-weight:600;">Tick "Apply tax" by default on new invoices</span>'
      +   '<span style="display:block;font-size:12.5px;color:#64748B;margin-top:2px;">Leave off if your agency is not registered to charge tax — a rate should not sit waiting to be applied.</span></span></label>'

      + '<div id="b-preview" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;margin-top:12px;font-size:13px;color:#475569;"></div>'

      + '<div style="display:flex;align-items:center;gap:14px;justify-content:flex-end;margin-top:16px;">'
      + '<span id="b-status" style="font-size:13px;color:#1E8E60;"></span>'
      + '<button id="b-save" style="font-size:14px;font-weight:700;padding:10px 20px;border:0;border-radius:10px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;cursor:pointer;">Save changes</button></div>'
      + '</div></div>';

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
