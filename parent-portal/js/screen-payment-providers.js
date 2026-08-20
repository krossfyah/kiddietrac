/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Payment providers

   Each agency banks its own money, so each agency holds its own keys. Nothing here
   falls back to a platform default: an agency that has not entered credentials cannot
   take payments, which is the correct behaviour rather than a gap.

   Secrets are never sent to this screen. A stored secret shows only as ••••last4, and
   an empty box means "leave it as it is" — so saving the form after changing a URL
   cannot silently wipe a key nobody could see.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var META = {
    zumrails: {
      title: '🏦 Zum Rails',
      blurb: 'Bank payments (EFT) and card payments from families, and refunds. '
        + 'Credentials come from your Zum portal, under Settings → Webhook &amp; API.',
    },
    stripe: {
      title: '💳 Stripe',
      blurb: 'Card payments and saved payment methods. Keys come from your Stripe '
        + 'dashboard, under Developers → API keys.',
    },
  };

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:900px;">'
      + '<div class="kt-page-hero"><h2>💳 Payment providers</h2>'
      + '<p>Your own payment accounts, held per agency. Money you take lands in your account, '
      + 'not somebody else&rsquo;s — so nothing works until your own keys are entered here.</p></div>'
      + '<div id="pp-body">Loading…</div></div>';

    await load(main);
  }

  async function load(main) {
    var body = main.querySelector('#pp-body');
    var res;
    try {
      res = await Api.get('/admin/payment-providers');
    } catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#B91C1C;">'
        + esc((e && e.message) || 'Could not load')
        + '<div style="color:#64748B;font-size:12.5px;margin-top:6px;">'
        + 'Payment settings are limited to agency administrators.</div></div>';
      return;
    }

    body.innerHTML = '';
    Object.keys(res.providers || {}).forEach(function (key) {
      body.appendChild(card(main, key, res.providers[key], (res.webhook_urls || {})[key]));
    });
  }

  function card(main, key, p, webhookUrl) {
    var meta = META[key] || { title: key, blurb: '' };
    var box = document.createElement('div');
    box.className = 'kt-card';
    box.style.cssText = 'max-width:680px;margin-bottom:16px;';

    var rows = Object.keys(p.fields || {}).map(function (fk) {
      var f = p.fields[fk];
      var placeholder = f.secret
        ? (f.set ? f.hint + ' — leave blank to keep it' : 'Not set')
        : '';
      return '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin:12px 0 4px;">'
        + esc(f.label) + (f.secret ? ' <span style="color:#94A3B8;font-weight:600;">(kept private)</span>' : '')
        + '</label>'
        + '<input data-pp-field="' + esc(fk) + '"'
        + ' type="' + (f.secret ? 'password' : 'text') + '"'
        + ' autocomplete="new-password"'
        + ' value="' + esc(f.secret ? '' : (f.value || '')) + '"'
        + ' placeholder="' + esc(placeholder) + '"'
        + ' style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #E2E8F0;'
        + 'border-radius:9px;font-size:13px;">';
    }).join('');

    box.innerHTML =
      '<div class="kt-card-header"><h3 class="kt-card-title">' + meta.title + '</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:-4px 0 8px;">' + meta.blurb + '</div>'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;'
      +   'padding:10px 0;border-bottom:1px solid #F1F5F9;">'
      +   '<div><div style="font-size:14px;font-weight:600;color:#334155;">Enabled</div>'
      +     '<div style="font-size:12.5px;color:#64748B;">Off means no payment can be taken or sent '
      +     'through this provider, whatever is stored below.</div></div>'
      +   '<input data-pp-enabled type="checkbox"' + (p.enabled ? ' checked' : '') + '>'
      + '</div>'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;'
      +   'padding:10px 0;border-bottom:1px solid #F1F5F9;">'
      +   '<div><div style="font-size:14px;font-weight:600;color:#334155;">Mode</div>'
      +     '<div style="font-size:12.5px;color:#64748B;">Use sandbox until you have tested a real '
      +     'payment end to end.</div></div>'
      +   '<select data-pp-mode style="padding:6px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;">'
      +     '<option value="sandbox"' + (p.mode === 'sandbox' ? ' selected' : '') + '>Sandbox</option>'
      +     '<option value="production"' + (p.mode === 'production' ? ' selected' : '') + '>Production</option>'
      +   '</select>'
      + '</div>'
      + rows
      + (webhookUrl
          ? '<div style="margin-top:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:11px 13px;">'
            + '<div style="font-size:12px;font-weight:700;color:#334155;">Callback URL for their portal</div>'
            + '<div style="font-size:12px;color:#475569;word-break:break-all;margin-top:3px;">' + esc(webhookUrl) + '</div>'
            + '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">'
            + 'Paste this into the provider, with the same webhook secret you entered above. '
            + 'Payments are only confirmed when they call it — a wrong URL fails quietly at settlement.</div></div>'
          : '')
      + '<div style="display:flex;align-items:center;gap:12px;margin-top:16px;">'
      +   '<button data-pp-save class="kt-btn kt-btn-primary">Save ' + esc(key === 'stripe' ? 'Stripe' : 'Zum Rails') + '</button>'
      +   '<span data-pp-msg style="font-size:13px;"></span>'
      +   '<span style="margin-left:auto;font-size:12px;color:' + (p.configured ? '#1E8E60' : '#94A3B8') + ';">'
      +     (p.configured ? '● Ready' : '○ Not configured yet') + '</span>'
      + '</div>';

    box.querySelector('[data-pp-save]').addEventListener('click', function () {
      var btn = box.querySelector('[data-pp-save]');
      var msg = box.querySelector('[data-pp-msg]');
      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Saving…';

      var payload = {
        enabled: box.querySelector('[data-pp-enabled]').checked,
        mode: box.querySelector('[data-pp-mode]').value,
      };
      box.querySelectorAll('[data-pp-field]').forEach(function (inp) {
        // A blank secret is omitted rather than sent: the server treats blank as
        // "unchanged", and sending it keeps that meaning explicit at both ends.
        payload[inp.getAttribute('data-pp-field')] = inp.value;
      });

      Api.post('/admin/payment-providers/' + key, payload).then(function () {
        btn.disabled = false;
        msg.style.color = '#1E8E60';
        msg.textContent = '✓ Saved';
        // Reloaded so the ready indicator and the ••••last4 hints reflect what is
        // actually stored, rather than what was typed.
        setTimeout(function () { load(main); }, 700);
      }).catch(function (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not save';
      });
    });

    return box;
  }

  KT.PaymentProviders = { render: render };
  // Agency admins and platform admins only. A centre director runs a site; these keys
  // move the agency's money and belong with whoever owns the bank account.
  ['agency_admin', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':payment-providers', render);
  });
})(window);
