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

  // Which tab is open. Remembered across reloads of the screen so saving a provider
  // does not throw you back to the first one.
  var active = null;

  /* GIFT CARDS (2026-11-02, Anthony: "move the giftcard settings to payment providers as new subtabs"). The gift card
     services educator awards are bought through sit here beside the money providers: ONE 🎁 Gift cards tab with this
     agency's own Giftbit and Tango Card accounts. Gift cards are agency specific (2026-11-02, Anthony: "merge into one
     as this is agency specific"): there is no KiddieTrac account and no fallback, and a superadmin viewing an agency
     sees that agency's tab. Same stores and endpoints as before; screen-educator-points.js draws them
     (KT.educatorGiftCards, a lazy stub until that file loads). Tokens and keys only ever come back masked. Old links
     (?tab=giftcards-kt included) land on ?tab=giftcards. */
  var GIFT = [['giftcards', '🎁 Gift cards']];
  function giftTabs() { return GIFT; }

  /* INTERAC E-TRANSFER (2026-11-11, Anthony: "in payment providers add a new substan to enable interac etransfer and
     ability to prompt parents when they are looking to pay via etransfer instructions on how to do so for the agency -
     use official interac logos"). Not a processor - money goes bank to bank, with no keys - so it is always offered,
     on any plan, beside the gift cards. Same store as before (billing_setup via /admin/billing-setup): on = 'interac' in
     the accepted payment methods with an address. ParentBilling::payInfo hands it to every family screen, invoice and
     invoice email; the Billing screen's sheet (KT.openInteracSheet) is what families see, and the Preview opens it. */
  var INTERAC_LOGO = '/img/interac-logo.svg';
  var ia = null;   // the billing setup, for the tab's dot and its card
  function interacOn(d) { return !!(d && d.interac_email && (d.accepted_payment_methods || []).indexOf('interac') !== -1); }

  var META = {
    zumrails: {
      title: '🏦 Zum Rails',
      blurb: 'Bank payments (EFT) and card payments from families, and refunds. '
        + 'Credentials come from your Zum portal, under Settings → Webhook &amp; API.',
    },
    stripe: {
      title: '💳 Stripe',
      blurb: 'Card payments, saved payment methods, and Apple Pay / Google Pay on invoices. Keys come from your Stripe '
        + 'dashboard, under Developers → API keys.',
    },
    helcim: {
      title: '🍁 Helcim',
      save: 'Helcim',
      blurb: 'Credit and debit cards, refunds and voids. One API token does everything — '
        + 'create it in Helcim under All Tools → Integrations → API Access, with the '
        + 'Payment API permissions enabled. Cards are entered inside Helcim&rsquo;s own '
        + 'window, so a card number never reaches KiddieTrac.',
      /* Helcim has no separate sandbox host: test and live traffic both go to
         api.helcim.com and the TOKEN decides which it is. Saying so here matters,
         because the Mode control above reads as though it routes somewhere safe. */
      modeNote: 'Helcim has no separate sandbox address — a test token and a live token '
        + 'both talk to api.helcim.com. Set this to match the kind of token you pasted; '
        + 'it labels what staff see, it does not keep real money away.',
      test: true,
    },
  };

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    var tm = /[?&]tab=([a-z-]+)/.exec(location.hash);
    if (tm) { active = tm[1] === 'giftcards-kt' ? 'giftcards' : tm[1]; }
    main.innerHTML = '<div style="padding:14px 24px;">'
      + '<div class="kt-page-hero"><h2>💳 Payment providers</h2>'
      + '<p>Your own payment accounts, held per agency. Money you take lands in your account, '
      + 'not somebody else&rsquo;s — so nothing works until your own keys are entered here. '
      + 'Gift card services for educator awards are here too.</p></div>'
      + '<div id="pp-tabs" role="tablist" aria-label="Payment providers" style="display:flex;margin:0 0 16px;"></div>'
      + '<div id="pp-body">Loading…</div></div>';

    await load(main);
  }

  async function load(main) {
    var body = main.querySelector('#pp-body');
    var res;
    try { ia = (await Api.get('/admin/billing-setup')).data || null; } catch (e0) { ia = null; }
    try {
      res = await Api.get('/admin/payment-providers');
    } catch (e) {
      /* The money providers follow the plan (autopay); the gift card tabs do not. A plan without payments still
         reaches its gift card accounts here, as it did under Educator awards. */
      var ga = giftTabs().map(function (t) { return t[0]; });
      if (ga.indexOf(active) === -1 && active !== 'interac') { active = ia ? 'interac' : ga[0]; }
      res = { providers: {} };
      paintTabs(main, [], res);
      if (active === 'interac') { body.innerHTML = ''; body.appendChild(interacCard(main)); return; }
      var gb = document.createElement('div');
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;margin-bottom:12px;font-size:13px;">'
        + esc((e && e.message) || 'Payment accounts are not available.') + '</div>';
      body.appendChild(gb);
      try { await KT.educatorGiftCards('agency', main, gb); } catch (e2) {}
      return;
    }

    var keys = Object.keys(res.providers || {});
    var gifts = giftTabs().map(function (t) { return t[0]; });
    if (keys.indexOf(active) === -1 && gifts.indexOf(active) === -1 && active !== 'interac') { active = keys[0] || 'interac'; }

    paintTabs(main, keys, res);

    body.innerHTML = '';
    if (active === 'interac') { body.appendChild(interacCard(main)); return; }
    if (gifts.indexOf(active) !== -1) {
      try { await KT.educatorGiftCards('agency', main, body); }
      catch (e) { body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load the gift card settings') + '</div>'; }
      return;
    }
    if (!keys.length) {
      body.innerHTML = '<div class="kt-card" style="max-width:680px;color:#64748B;">'
        + 'No payment providers are available.</div>';
      return;
    }
    body.appendChild(card(main, active, res.providers[active], (res.webhook_urls || {})[active]));
  }


  /* One pill per provider, with a dot showing whether that one is ready — so an
     admin can see at a glance which still needs keys without opening each in turn. */
  function paintTabs(main, keys, res) {
    var host = main.querySelector('#pp-tabs');
    if (!host) { return; }
    host.innerHTML = '';

    keys.concat(ia ? ['interac'] : [], giftTabs().map(function (t) { return t[0]; })).forEach(function (key) {
      var isI = key === 'interac';
      var p = isI ? { configured: interacOn(ia) } : (res.providers[key] || {});
      var gift = !isI && !res.providers[key];
      var on = key === active;
      var meta = isI ? { title: 'Interac e-Transfer' } : gift ? { title: giftTabs().filter(function (t) { return t[0] === key; })[0][1] } : (META[key] || { title: key });
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.setAttribute('data-pptab', key);

      var dot = document.createElement('span');
      dot.textContent = gift ? '' : '●';
      dot.style.cssText = gift ? 'display:none;' : 'font-size:10px;color:' + (p.configured ? '#1E8E60' : '#CBD5E1') + ';';
      dot.title = p.configured ? 'Ready' : 'Not configured yet';
      if (isI) { dot.title = p.configured ? 'Families can pay by e-Transfer' : 'Not switched on'; }

      var label = document.createElement('span');
      label.textContent = meta.title || key;

      b.appendChild(dot);
      if (isI) {
        var lg = document.createElement('img');
        lg.src = INTERAC_LOGO; lg.alt = ''; lg.width = 18; lg.height = 18;
        lg.style.cssText = 'width:18px;height:18px;border-radius:4px;vertical-align:-4px;';
        b.appendChild(lg);
      }
      b.appendChild(label);
      b.addEventListener('click', function () {
        if (active === key) { return; }
        active = key;
        load(main);
      });
      host.appendChild(b);
    });
  }
  function card(main, key, p, webhookUrl) {
    var meta = META[key] || { title: key, blurb: '' };
    var box = document.createElement('div');
    box.className = 'kt-card';
    box.style.cssText = 'max-width:680px;';

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
      '<div style="font-size:12.5px;color:#64748B;margin:0 0 10px;">' + meta.blurb + '</div>'
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
      +     '<div style="font-size:12.5px;color:#64748B;">'
      +     (meta.modeNote || 'Use sandbox until you have tested a real payment end to end.')
      +     '</div></div>'
      +   '<select data-pp-mode style="padding:6px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;">'
      +     '<option value="sandbox"' + (p.mode === 'sandbox' ? ' selected' : '') + '>Sandbox</option>'
      +     '<option value="production"' + (p.mode === 'production' ? ' selected' : '') + '>Production</option>'
      +   '</select>'
      + '</div>'
      + rows
      /* STRIPE CONNECTS ITSELF (2026-11-05): saving the keys registers KiddieTrac's webhook on the agency's Stripe
         and keeps its secret (AgencyStripe::connectWebhook), so there is no URL to paste. */
      + (key === 'stripe' && p.configured
          ? '<div data-pp-hook style="margin-top:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:11px 13px;display:flex;align-items:center;gap:12px;">'
            + '<div style="flex:1;min-width:0;"><div style="font-size:12px;font-weight:700;color:#334155;">Payment updates from Stripe</div>'
            + '<div data-pp-hook-state style="font-size:12px;color:' + ((p.fields && p.fields.webhook_secret && p.fields.webhook_secret.set) ? '#1E8E60' : '#B45309') + ';margin-top:3px;">'
            + ((p.fields && p.fields.webhook_secret && p.fields.webhook_secret.set)
                ? '● Connected: a payment finished after a parent closes the app is still recorded.'
                : '○ Not connected yet: payments are recorded only while the parent waits on the screen.')
            + '</div></div>'
            + '<button data-pp-hookbtn class="kt-btn" style="border:1px solid #CBD5E1;background:#fff;flex:0 0 auto;">'
            + ((p.fields && p.fields.webhook_secret && p.fields.webhook_secret.set) ? 'Reconnect' : 'Connect') + '</button></div>'
          : '')
      + (webhookUrl && key !== 'stripe'
          ? '<div style="margin-top:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:11px 13px;">'
            + '<div style="font-size:12px;font-weight:700;color:#334155;">Callback URL for their portal</div>'
            + '<div style="font-size:12px;color:#475569;word-break:break-all;margin-top:3px;">' + esc(webhookUrl) + '</div>'
            + '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">'
            + 'Paste this into the provider, with the same webhook secret you entered above. '
            + 'Payments are only confirmed when they call it — a wrong URL fails quietly at settlement.</div></div>'
          : '')
      + '<div style="display:flex;align-items:center;gap:12px;margin-top:16px;">'
      +   '<button data-pp-save class="kt-btn kt-btn-primary">Save '
      +     esc(meta.save || (key === 'stripe' ? 'Stripe' : key === 'helcim' ? 'Helcim' : 'Zum Rails'))
      +     '</button>'
      +   (meta.test ? '<button data-pp-test class="kt-btn" style="border:1px solid #CBD5E1;background:#fff;">Test connection</button>' : '')
      +   (key === 'stripe' && p.configured ? '<button data-pp-wallet class="kt-btn" style="border:1px solid #CBD5E1;background:#fff;">Turn on Apple Pay &amp; Google Pay</button>' : '')
      +   '<span data-pp-msg style="font-size:13px;"></span>'
      +   '<span style="margin-left:auto;font-size:12px;color:' + (p.configured ? '#1E8E60' : '#94A3B8') + ';">'
      +     (p.configured ? '● Ready' : '○ Not configured yet') + '</span>'
      + '</div>';

    /* TEST, rather than find out from a parent who cannot pay.

       A wrong or under-permissioned API token looks exactly like a right one on this
       screen - it is stored, it shows ••••last4, the dot goes green. "Configured" only
       ever meant "a value is present". This asks Helcim. */
    var testBtn = box.querySelector('[data-pp-test]');
    if (testBtn) {
      testBtn.addEventListener('click', function () {
        var msg = box.querySelector('[data-pp-msg]');
        testBtn.disabled = true;
        msg.style.color = '#64748B';
        msg.textContent = 'Asking Helcim…';

        Api.post('/admin/payment-providers/helcim/test', {}).then(function (r) {
          testBtn.disabled = false;
          if (r && r.ok) {
            msg.style.color = '#1E8E60';
            msg.textContent = '✓ Helcim accepted this token'
              + (r.live ? ' — LIVE, real cards will be charged' : ' — labelled as sandbox')
              + (r.currency ? ' · ' + r.currency : '');
          } else {
            msg.style.color = '#B91C1C';
            msg.textContent = '✗ ' + ((r && r.error) || 'Helcim did not accept that token');
          }
        }).catch(function (e) {
          testBtn.disabled = false;
          msg.style.color = '#B91C1C';
          msg.textContent = (e && e.message) || 'Could not reach Helcim';
        });
      });
    }

    /* APPLE PAY + GOOGLE PAY (2026-11-03): Stripe only shows Apple Pay on a website it has verified, so this
       registers the portal's domains with the agency's own Stripe account. Google Pay needs nothing more. */
    var hookBtn = box.querySelector('[data-pp-hookbtn]');
    if (hookBtn) {
      hookBtn.addEventListener('click', function () {
        var st = box.querySelector('[data-pp-hook-state]');
        hookBtn.disabled = true;
        st.style.color = '#64748B';
        st.textContent = 'Asking Stripe…';
        Api.post('/admin/payment-providers/stripe/webhook', {}).then(function (r) {
          hookBtn.disabled = false;
          st.style.color = '#1E8E60';
          st.textContent = '● Connected: a payment finished after a parent closes the app is still recorded.';
          hookBtn.textContent = 'Reconnect';
        }).catch(function (e) {
          hookBtn.disabled = false;
          st.style.color = '#B91C1C';
          st.textContent = (e && e.message) || 'Could not reach Stripe';
        });
      });
    }

    var walletBtn = box.querySelector('[data-pp-wallet]');
    if (walletBtn) {
      walletBtn.addEventListener('click', function () {
        var msg = box.querySelector('[data-pp-msg]');
        walletBtn.disabled = true;
        msg.style.color = '#64748B';
        msg.textContent = 'Asking Stripe…';
        Api.post('/admin/payment-providers/stripe/wallet-domains', {}).then(function (r) {
          walletBtn.disabled = false;
          var d = (r && r.domains) || [];
          var bad = d.filter(function (x) { return x.error || x.apple_pay !== 'active'; });
          msg.style.color = bad.length ? '#B45309' : '#1E8E60';
          msg.textContent = bad.length
            ? '⚠ ' + bad.map(function (x) { return x.domain + ': ' + (x.error || ('Apple Pay ' + x.apple_pay)); }).join(' · ')
            : '✓ Apple Pay and Google Pay are on for ' + d.map(function (x) { return x.domain; }).join(', ');
        }).catch(function (e) {
          walletBtn.disabled = false;
          msg.style.color = '#B91C1C';
          msg.textContent = (e && e.message) || 'Could not reach Stripe';
        });
      });
    }

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

  function interacCard(main) {
    var d = ia || {};
    var box = document.createElement('div');
    box.className = 'kt-card';
    box.style.cssText = 'max-width:760px;';
    var INP = 'width:100%;box-sizing:border-box;height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;';
    var LBL = 'display:block;font-size:12px;font-weight:700;color:#334155;margin:0 0 4px;';
    var HINT = 'font-size:12px;color:#64748B;margin-top:4px;line-height:1.45;';
    var on = interacOn(d);
    var auto = d.interac_autodeposit;   // true | false | null
    box.innerHTML = ''
      + '<div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">'
      +   '<img src="' + INTERAC_LOGO + '" alt="Interac" width="52" height="52" style="width:52px;height:52px;border-radius:10px;flex:none;">'
      +   '<div style="min-width:0;"><div style="font-weight:800;font-size:17px;color:#0F172A;">Interac e-Transfer</div>'
      +   '<div style="font-size:13px;color:#475569;line-height:1.45;">Families send money from their own bank to your e-Transfer email. There is nothing to connect: KiddieTrac shows them where to send it, how much, the invoice number for the message and your instructions, then you record the payment when it lands.</div></div></div>'
      + '<label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #E2E8F0;border-radius:10px;cursor:pointer;font-weight:700;font-size:14px;color:#0F172A;margin-bottom:14px;">'
      +   '<input type="checkbox" data-ia="on" style="width:18px;height:18px;flex:none;"' + (on ? ' checked' : '') + '>'
      +   '<span>Accept Interac e-Transfer<span style="display:block;font-weight:400;font-size:12.5px;color:#64748B;margin-top:2px;">Families get a “Pay by Interac e-Transfer” button on Billing, and invoices list it under How to pay.</span></span></label>'
      + '<div data-ia-fields style="display:flex;flex-direction:column;gap:12px;max-width:520px;">'
      +   '<div><label style="' + LBL + '">e-Transfer email (where families send money)</label><input data-ia="interac_email" type="email" maxlength="190" placeholder="payments@yourcentre.ca" style="' + INP + '" value="' + esc(d.interac_email || '') + '"></div>'
      +   '<div><label style="' + LBL + '">Recipient name (as their bank will show it)</label><input data-ia="interac_recipient_name" type="text" maxlength="120" placeholder="' + esc(d.agency_display_name || '') + '" style="' + INP + '" value="' + esc(d.interac_recipient_name || '') + '"><div style="' + HINT + '">Leave empty to use ' + esc(d.agency_display_name || 'your agency name') + '.</div></div>'
      + '</div>'
      + '<div style="margin:14px 0 0;"><div style="' + LBL + '">Autodeposit</div>'
      +   '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin:4px 0;cursor:pointer;"><input type="radio" name="ia-auto" value="1"' + (auto === true ? ' checked' : '') + '> <span>Autodeposit is on for this email — families are not asked a security question</span></label>'
      +   '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin:4px 0;cursor:pointer;"><input type="radio" name="ia-auto" value="0"' + (auto === false ? ' checked' : '') + '> <span>No Autodeposit — families need a security question and answer</span></label>'
      +   '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin:4px 0;cursor:pointer;"><input type="radio" name="ia-auto" value=""' + (auto !== true && auto !== false ? ' checked' : '') + '> <span>Don’t say</span></label></div>'
      + '<div data-ia-q style="display:' + (auto === false ? 'flex' : 'none') + ';flex-direction:column;gap:12px;max-width:520px;margin-top:8px;">'
      +   '<div><label style="' + LBL + '">Security question</label><input data-ia="interac_question" type="text" maxlength="120" placeholder="e.g. Your child’s centre" style="' + INP + '" value="' + esc(d.interac_question || '') + '"></div>'
      +   '<div><label data-i18n-ctx="interac" style="' + LBL + '">Answer</label><input data-ia="interac_answer" type="text" maxlength="60" style="' + INP + '" value="' + esc(d.interac_answer || '') + '"><div style="' + HINT + '">Shown to your families with the question, so use one only for e-Transfers.</div></div>'
      + '</div>'
      + '<div style="margin-top:14px;"><label style="' + LBL + '">Your instructions for families (optional)</label>'
      +   '<textarea data-ia="interac_instructions" maxlength="1000" rows="4" style="max-width:520px;width:100%;box-sizing:border-box;min-height:96px;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:14px;" placeholder="e.g. Please send one e-Transfer per invoice. Payments sent after 3 PM on Friday are recorded on Monday.">' + esc(d.interac_instructions || '') + '</textarea>'
      +   '<div style="' + HINT + '">Shown to families when they choose to pay by e-Transfer, under the steps, and under How to pay on invoices and invoice emails. The invoice number for the message is always shown for you.</div></div>'
      + '<label style="display:flex;align-items:flex-start;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-top:14px;line-height:1.4;">'
      +   '<input type="checkbox" data-ia="interac_requests"' + (d.interac_requests !== false ? ' checked' : '') + '>'
      +   '<span>Families can ask for an e-Transfer request<span style="display:block;font-size:12.5px;color:#6B7280;margin-top:2px;">They pick their email or mobile; you send the request from your bank (Request money) and track it under Finance → e-Transfer requests.</span></span></label>'
      + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:16px;">'
      +   '<button type="button" class="kt-btn kt-btn-primary" data-ia-save>Save Interac e-Transfer</button>'
      +   '<button type="button" class="kt-btn" data-ia-preview>👁 Preview what families see</button>'
      +   '<span data-ia-msg style="font-size:13px;"></span></div>'
      + '<div style="font-size:10.5px;color:#94A3B8;margin-top:14px;">Interac and Interac e-Transfer are registered trade-marks of Interac Corp.</div>';

    var q = function (s) { return box.querySelector(s); };
    var radios = box.querySelectorAll('input[name=ia-auto]');
    function autoVal() { var v = null; radios.forEach(function (r) { if (r.checked) { v = r.value === '1' ? true : r.value === '0' ? false : null; } }); return v; }
    radios.forEach(function (r) { r.addEventListener('change', function () { q('[data-ia-q]').style.display = autoVal() === false ? 'flex' : 'none'; }); });
    function draft() {
      var acc = (d.accepted_payment_methods || []).filter(function (m) { return m !== 'interac'; });
      if (q('[data-ia=on]').checked) { acc.push('interac'); }
      return {
        accepted_payment_methods: acc,
        interac_email: q('[data-ia=interac_email]').value.trim() || null,
        interac_recipient_name: q('[data-ia=interac_recipient_name]').value.trim() || null,
        interac_autodeposit: autoVal(),
        interac_question: q('[data-ia=interac_question]').value.trim() || null,
        interac_answer: q('[data-ia=interac_answer]').value.trim() || null,
        interac_instructions: q('[data-ia=interac_instructions]').value.trim() || null,
        interac_requests: q('[data-ia=interac_requests]').checked,
      };
    }
    var msg = q('[data-ia-msg]');
    function say(t, c) { msg.textContent = t; msg.style.color = c || '#334155'; }
    q('[data-ia-save]').addEventListener('click', function () {
      var p = draft(), btn = this;
      if (p.accepted_payment_methods.indexOf('interac') !== -1) {
        if (!p.interac_email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p.interac_email)) { say('Add the e-Transfer email families send money to.', '#B91C1C'); q('[data-ia=interac_email]').focus(); return; }
        if (p.interac_autodeposit === false && (!p.interac_question || !p.interac_answer)) { say('Add the security question and its answer, or choose Autodeposit.', '#B91C1C'); q('[data-ia=interac_question]').focus(); return; }
      }
      btn.disabled = true; say('Saving…');
      Api.patch('/admin/billing-setup', p).then(function () {
        say('✓ Saved', '#1E8E60');
        setTimeout(function () { load(main); }, 700);
      }).catch(function (e) { say((e && e.message) || 'Could not save', '#B91C1C'); }).then(function () { btn.disabled = false; });
    });
    q('[data-ia-preview]').addEventListener('click', function () {
      var p = draft();
      if (!p.interac_email) { say('Add the e-Transfer email first, then preview.', '#B91C1C'); return; }
      parentSheet().then(function () { KT.openInteracSheet({
        agency_name: d.agency_display_name || 'your centre',
        interac: { email: p.interac_email, recipient: p.interac_recipient_name || d.agency_display_name || null,
          autodeposit: p.interac_autodeposit, question: p.interac_autodeposit === false ? p.interac_question : null,
          answer: p.interac_autodeposit === false ? p.interac_answer : null, instructions: p.interac_instructions, requests: false },
      }, [{ number: 'INV-SAMPLE', amount: 125 }]); }).catch(function () { say('Could not open the preview. Reload the page and try again.', '#B91C1C'); });
    });
    return box;
  }

  // screen-parent.js (KT.openInteracSheet) is a lazy script for parents; staff load it the first time they preview.
  function parentSheet() {
    if (KT.openInteracSheet) { return Promise.resolve(); }
    var k = Object.keys(window.__KT_LAZY || {}).filter(function (x) { return /^js\/screen-parent\.js(\?|$)/.test(x); })[0];
    var src = '/' + (k || 'js/screen-parent.js');
    return new Promise(function (ok, no) {
      var el = document.querySelector('script[data-kt-ia-sheet]');
      if (!el) {
        el = document.createElement('script');
        el.src = src; el.setAttribute('data-kt-ia-sheet', '1');
        document.head.appendChild(el);
      }
      el.addEventListener('load', function () { KT.openInteracSheet ? ok() : no(); });
      el.addEventListener('error', no);
    });
  }

  KT.PaymentProviders = { render: render };
  // Agency admins and platform admins only. A centre director runs a site; these keys
  // move the agency's money and belong with whoever owns the bank account.
  ['agency_admin', 'platform_admin'].forEach(function (r) {
    KT.Shell.registerScreen(r + ':payment-providers', render);
  });
})(window);
