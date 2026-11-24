/* Apple Pay + Google Pay on a parent's invoice (2026-11-03).
 *
 * Anthony: "wire it up for all processors". KT.walletPay.mount(slot, inv, opts) asks the server which wallets this
 * invoice's agency can take (GET /parent/wallet-pay/options) and shows only those:
 *   - Stripe, in a browser: Stripe's Express Checkout Element (Safari shows Apple Pay, Chrome shows Google Pay).
 *   - Stripe, in the iPhone / Android app: the native sheet through the Capacitor Stripe plugin. Apple blocks Apple
 *     Pay JS in the app's web view, so an app build without the plugin shows no wallet button at all.
 *   - Helcim: its hosted checkout (card + Google Pay). It replaces the old card button, which only staff could use.
 *   - Zum Rails: no wallets in their API, so nothing is added; e-Transfer and the rest stay as they are.
 *   - Demo (2026-11-04): a TEST agency with agencies.settings.wallet_demo and no Stripe shows the device's wallet
 *     button and a look-alike wallet sheet; POST /parent/wallet-pay/demo records it (no processor, undone nightly).
 * ONE WALLET PER DEVICE (2026-11-04, Anthony: "For apple users it should only show the apple wallet and same for
 * google users"): an iPhone, iPad or Mac Safari is offered Apple Pay only; everything else Google Pay only - in the
 * demo, in Stripe's browser button and for Helcim (Google Pay only, so not offered on Apple devices).
 * The amount is always the server's. A payment counts only once the server has checked it with the processor.
 */
(function (window) {
  'use strict';
  const KT = window.KT = window.KT || {};

  function api() { return KT.Api; }
  function money(n) { return '$' + (Number(n) || 0).toFixed(2); }
  function el(tag, style, text) { const e = document.createElement(tag); if (style) e.style.cssText = style; if (text != null) e.textContent = text; return e; }
  function nicely(e, fallback) { return (e && (e.message || (e.data && (e.data.message || e.data.error)))) || fallback; }

  const loaded = {};
  function loadScript(src) {
    if (!loaded[src]) loaded[src] = new Promise(function (resolve, reject) {
      const s = document.createElement('script');
      s.src = src; s.async = true; s.onload = resolve;
      s.onerror = function () { delete loaded[src]; reject(new Error('Could not load the payment window. Check your connection and try again.')); };
      document.head.appendChild(s);
    });
    return loaded[src];
  }

  function nativeStripe() {
    const cap = window.Capacitor;
    if (!cap || typeof cap.isNativePlatform !== 'function' || !cap.isNativePlatform()) return null;
    return (cap.Plugins && cap.Plugins.Stripe) ? { plugin: cap.Plugins.Stripe, platform: cap.getPlatform() } : false;
  }

  /* 'apple_pay' on an iPhone / iPad (the app or any browser - iPadOS reports itself as a Mac with touch) and in
     Safari on a Mac; 'google_pay' everywhere else. */
  function deviceWallet() {
    const cap = window.Capacitor;
    try { if (cap && typeof cap.getPlatform === 'function' && cap.getPlatform() !== 'web') return cap.getPlatform() === 'ios' ? 'apple_pay' : 'google_pay'; } catch (e) {}
    const ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'apple_pay';
    if (/Macintosh/.test(ua) && /Safari\//.test(ua) && !/Chrome|Chromium|CriOS|Edg\/|OPR\/|Firefox/.test(ua)) return 'apple_pay';
    return 'google_pay';
  }

  const BTN = 'width:100%;height:46px;border:0;border-radius:12px;cursor:pointer;font-size:16px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px;';

  /* ── Stripe: record it once Stripe says it succeeded ───────────────────────────────── */
  async function stripeConfirm(o, piId, say, onPaid) {
    const r = await api().post('/parent/wallet-pay/stripe-confirm', { invoice_id: o.invoice_id, payment_intent_id: piId });
    if (r && r.paid) { onPaid(r); return; }
    // processing / requires_action: the webhook records it when the bank confirms.
    say('Your payment is being processed. It will show on this invoice once your bank confirms it.', true);
  }

  /* ── Stripe in a browser: Express Checkout Element ─────────────────────────────────── */
  async function mountStripeWeb(box, o, say, onPaid) {
    if (o.amount_cents < 50) return false;
    await loadScript('https://js.stripe.com/v3/');
    const stripe = window.Stripe(o.stripe.publishable_key);
    const elements = stripe.elements({ mode: 'payment', amount: o.amount_cents, currency: o.currency.toLowerCase(), paymentMethodTypes: ['card'] });
    const ece = elements.create('expressCheckout', {
      paymentMethods: { applePay: deviceWallet() === 'apple_pay' ? 'auto' : 'never', googlePay: deviceWallet() === 'google_pay' ? 'auto' : 'never', link: 'never', paypal: 'never', amazonPay: 'never', klarna: 'never' },
      buttonHeight: 46,
      buttonType: { applePay: 'pay', googlePay: 'pay' },
    });
    const holder = el('div', 'margin:0 0 10px;');
    box.appendChild(holder);
    return await new Promise(function (resolve) {
      ece.on('ready', function (ev) {
        const any = ev && ev.availablePaymentMethods && (ev.availablePaymentMethods.applePay || ev.availablePaymentMethods.googlePay);
        if (!any) holder.remove();
        resolve(!!any);
      });
      ece.on('loaderror', function () { holder.remove(); resolve(false); });
      ece.on('confirm', async function (ev) {
        say('');
        try {
          const sub = await elements.submit();
          if (sub && sub.error) { say(sub.error.message); return; }
          const wallet = ev && ev.expressPaymentType === 'apple_pay' ? 'apple_pay' : ev && ev.expressPaymentType === 'google_pay' ? 'google_pay' : 'browser';
          const intent = await api().post('/parent/wallet-pay/stripe-intent', { invoice_id: o.invoice_id, wallet: wallet });
          const res = await stripe.confirmPayment({ elements: elements, clientSecret: intent.client_secret, redirect: 'if_required',
            confirmParams: { return_url: window.location.href } });
          if (res.error) { say(res.error.message || 'The payment did not go through. Nothing was charged.'); return; }
          await stripeConfirm(o, intent.payment_intent_id, say, onPaid);
        } catch (e) {
          if (typeof ev.paymentFailed === 'function') { try { ev.paymentFailed(); } catch (_) {} }
          say(nicely(e, 'The payment did not go through. Nothing was charged.'));
        }
      });
      ece.mount(holder);
    });
  }

  /* ── Stripe in the app: the native Apple Pay / Google Pay sheet ────────────────────── */
  async function mountStripeNative(box, o, ns, say, onPaid) {
    const S = ns.plugin, ios = ns.platform === 'ios';
    try { await S.initialize({ publishableKey: o.stripe.publishable_key }); } catch (e) { return false; }
    try { await (ios ? S.isApplePayAvailable() : S.isGooglePayAvailable()); } catch (e) { return false; }   // rejects when unavailable

    const b = el('button', BTN + 'background:#000;color:#fff;margin:0 0 10px;', ios ? 'Pay with  Pay' : 'Pay with Google Pay');
    b.type = 'button';
    b.setAttribute('aria-label', (ios ? 'Apple Pay' : 'Google Pay') + ' ' + money(o.amount));
    box.appendChild(b);
    b.addEventListener('click', async function () {
      say(''); b.disabled = true; const was = b.textContent; b.textContent = 'Opening…';
      try {
        const intent = await api().post('/parent/wallet-pay/stripe-intent', { invoice_id: o.invoice_id, wallet: ios ? 'apple_pay' : 'google_pay' });
        const sheet = {
          paymentIntentClientSecret: intent.client_secret,
          paymentSummaryItems: [{ label: o.label + ' - Invoice ' + o.invoice_number, amount: o.amount }],
          merchantIdentifier: o.stripe.merchant_identifier,
          countryCode: o.country,
          currency: o.currency,
        };
        // Cancel and failure both RESOLVE (plugin 8.3.0): {paymentResult: '...Completed' | '...Canceled' | '...Failed'}.
        let res;
        if (ios) { await S.createApplePay(sheet); res = (await S.presentApplePay()).paymentResult; }
        else { await S.createGooglePay(sheet); res = (await S.presentGooglePay()).paymentResult; }
        if (/Canceled$/.test(res || '')) { say('Payment cancelled. Nothing was charged.'); return; }
        if (!/Completed$/.test(res || '')) { say((ios ? 'Apple Pay' : 'Google Pay') + ' did not go through. Nothing was charged - please use another way to pay below.'); return; }
        await stripeConfirm(o, intent.payment_intent_id, say, onPaid);
      } catch (e) {
        say(nicely(e, 'The payment did not go through. Nothing was charged.'));
      } finally { b.disabled = false; b.textContent = was; }
    });
    return true;
  }

  /* ── Helcim: hosted checkout with card + Google Pay ────────────────────────────────── */
  function mountHelcim(box, o, cardBtn, say, onPaid) {
    const b = el('button', BTN + 'color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);box-shadow:0 12px 24px -12px rgba(31,111,178,.55);',
      'Pay ' + money(o.amount) + ' by card or Google Pay');
    b.type = 'button';
    if (cardBtn && cardBtn.parentNode) { cardBtn.style.display = 'none'; cardBtn.parentNode.insertBefore(b, cardBtn); }
    else box.appendChild(b);
    b.addEventListener('click', async function () {
      say(''); b.disabled = true; const was = b.textContent; b.textContent = 'Opening…';
      let token = null;
      function cleanUp() {
        window.removeEventListener('message', onMsg);
        try { if (typeof window.removeHelcimPayIframe === 'function') window.removeHelcimPayIframe(); } catch (_) {}
        b.disabled = false; b.textContent = was;
      }
      async function onMsg(ev) {
        if (!token || ev.origin !== 'https://secure.helcim.app' || !ev.data || ev.data.eventName !== 'helcim-pay-js-' + token) return;
        if (ev.data.eventStatus === 'ABORTED') { cleanUp(); say('The payment did not go through. Nothing was charged.'); return; }
        if (ev.data.eventStatus === 'HIDE') { cleanUp(); return; }
        if (ev.data.eventStatus !== 'SUCCESS') return;
        window.removeEventListener('message', onMsg);
        try {
          const m = typeof ev.data.eventMessage === 'string' ? JSON.parse(ev.data.eventMessage) : ev.data.eventMessage;
          const inner = (m && m.data) || {};
          const tx = inner.data || inner;
          await api().post('/parent/helcim/complete', { checkout_token: token, raw_data: JSON.stringify(tx), hash: String(inner.hash || '') });
          cleanUp();
          onPaid({ paid: true });
        } catch (e) { cleanUp(); say(nicely(e, 'We could not confirm that payment. Please check with your centre before paying again.')); }
      }
      try {
        await loadScript('https://secure.helcim.app/helcim-pay/services/start.js');
        const r = await api().post('/parent/helcim/checkout', { invoice_id: o.invoice_id });
        token = r.checkout_token;
        window.addEventListener('message', onMsg);
        if (typeof window.appendHelcimPayIframe !== 'function') throw new Error('The payment window could not open. Please try again.');
        window.appendHelcimPayIframe(token, true);   // HelcimPay.js start.js (checked 2026-11-03)
        b.textContent = was;
      } catch (e) { cleanUp(); say(nicely(e, 'The payment window could not open. Please try again.')); }
    });
    return true;
  }

  /* ── Demo (test agency): the wallet buttons and a look-alike sheet, no processor ───────── */
  const APPLE = '<svg viewBox="0 0 17 20" width="17" height="20" aria-hidden="true"><path fill="currentColor" d="M14.1 10.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.4-.9C3.6 4.7 2 5.7 1.1 7.3c-1.8 3.2-.5 7.9 1.3 10.5.9 1.3 1.9 2.7 3.3 2.6 1.3-.1 1.8-.9 3.4-.9s2 .9 3.4.8c1.4 0 2.3-1.3 3.1-2.6 1-1.5 1.4-2.9 1.4-3-.1 0-2.9-1.1-2.9-4.1zM11.6 3c.7-.9 1.2-2 1.1-3.2-1 0-2.3.7-3 1.6-.7.8-1.2 2-1.1 3.1 1.1.1 2.3-.6 3-1.5z"/></svg>';
  const GOOGLE = '<svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M17.6 9.2c0-.6-.1-1.2-.2-1.8H9v3.4h4.8a4.1 4.1 0 0 1-1.8 2.7v2.2h2.9c1.7-1.6 2.7-3.9 2.7-6.5z"/><path fill="#34A853" d="M9 18c2.4 0 4.5-.8 5.9-2.2l-2.9-2.2c-.8.5-1.8.9-3 .9-2.3 0-4.3-1.6-5-3.7H1v2.3A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M4 10.8a5.4 5.4 0 0 1 0-3.5V5H1a9 9 0 0 0 0 8.1l3-2.3z"/><path fill="#EA4335" d="M9 3.6c1.3 0 2.5.5 3.4 1.3l2.6-2.6A9 9 0 0 0 1 5l3 2.3c.7-2.1 2.7-3.7 5-3.7z"/></svg>';

  /* The dummy cards a test wallet holds (Stripe's published test numbers' last four). Each sheet shows four of them in a
     random order, the first one picked; the server knows the same keys (WalletPayController::DEMO_CARDS). */
  const DEMO_CARDS = [
    ['visa', 'Visa', '4242', '#1A1F71', 'VISA'], ['visa_debit', 'Visa Debit', '5556', '#1A1F71', 'VISA'],
    ['mastercard', 'Mastercard', '4444', '#000', 'MC'], ['mc_debit', 'Mastercard Debit', '8210', '#000', 'MC'],
    ['amex', 'American Express', '0005', '#2E77BC', 'AMEX'], ['discover', 'Discover', '1117', '#E55C20', 'DISC'],
    ['jcb', 'JCB', '0505', '#0B4EA2', 'JCB'], ['unionpay', 'UnionPay', '0005', '#D7182A', 'UP'], ['diners', 'Diners Club', '0004', '#0079BE', 'DC'],
  ];
  function cardMark(c) {
    if (c[4] === 'MC') return '<svg viewBox="0 0 34 22" width="34" height="22" aria-hidden="true"><rect width="34" height="22" rx="4" fill="#000"/><circle cx="13.5" cy="11" r="6.5" fill="#EB001B"/><circle cx="20.5" cy="11" r="6.5" fill="#F79E1B" fill-opacity=".9"/></svg>';
    return '<span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:22px;border-radius:4px;background:' + c[3] + ';color:#fff;font:800 9px/1 system-ui,sans-serif;letter-spacing:.3px;font-style:' + (c[4] === 'VISA' ? 'italic' : 'normal') + ';">' + c[4] + '</span>';
  }
  function pickCards() {
    const a = DEMO_CARDS.slice();
    for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); const t = a[i]; a[i] = a[j]; a[j] = t; }
    return a.slice(0, 4);
  }

  function demoSheet(o, wallet, say, onPaid) {
    const apple = wallet === 'apple_pay';
    const cards = pickCards();
    let chosen = cards[0];
    // Above everything: the invoice it pays for is itself a popup (z 2147483060), and this sheet opened UNDER it.
    const scrim = el('div', 'position:fixed;inset:0;z-index:2147483646;background:rgba(0,0,0,.45);display:flex;align-items:flex-end;justify-content:center;');
    scrim.setAttribute('data-kt-no-close', '1');
    scrim.setAttribute('data-no-modal-guard', '1');   // a wallet sheet keeps the wallet's own look, not the popup band
    scrim.setAttribute('data-kt-nochrome', '1');
    const card = el('div', 'width:100%;max-width:460px;background:' + (apple ? '#F2F2F7' : '#fff') + ';color:#111;border-radius:16px 16px 0 0;padding:16px 18px calc(18px + env(safe-area-inset-bottom));font-family:-apple-system,system-ui,Roboto,sans-serif;box-shadow:0 -10px 40px rgba(0,0,0,.3);');
    const head = el('div', 'display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;');
    head.setAttribute('data-kt-own-head', '1');
    const brand = el('div', 'display:flex;align-items:center;gap:6px;font-weight:700;font-size:17px;');
    brand.innerHTML = (apple ? APPLE : GOOGLE) + '<span>Pay</span>' +
      '<span style="margin-left:6px;font-size:11px;font-weight:800;letter-spacing:.4px;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;padding:2px 8px;">TEST</span>';
    const cancel = el('button', 'border:0;background:none;color:' + (apple ? '#007AFF' : '#1a73e8') + ';font-size:16px;cursor:pointer;padding:4px;', 'Cancel');
    cancel.type = 'button';
    head.appendChild(brand); head.appendChild(cancel); card.appendChild(head);
    const row = function (k, v, strong) {
      const r = el('div', 'display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid rgba(0,0,0,.08);font-size:15px;');
      r.appendChild(el('span', 'color:#6b7280;', k)); r.appendChild(el('span', strong ? 'font-weight:800;font-size:17px;' : 'font-weight:600;text-align:right;', v));
      card.appendChild(r);
    };
    // Choose a card: the wallet's own list, one picked.
    const list = el('div', 'background:#fff;border-radius:12px;' + (apple ? '' : 'border:1px solid #dadce0;') + 'margin:0 0 6px;overflow:hidden;');
    list.setAttribute('role', 'radiogroup');
    list.setAttribute('aria-label', 'Test card');
    const accent = apple ? '#007AFF' : '#1a73e8';
    function paint() {
      [].forEach.call(list.children, function (r) {
        const on = r.getAttribute('data-card') === chosen[0];
        r.setAttribute('aria-checked', on ? 'true' : 'false');
        r.querySelector('[data-tick]').innerHTML = on ? '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="' + accent + '"/><path d="M6 10.2l2.6 2.6L14 7.4" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
          : '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><circle cx="10" cy="10" r="8.5" stroke="#c7c7cc" stroke-width="1.5" fill="none"/></svg>';
      });
    }
    cards.forEach(function (c, i) {
      const r = el('button', 'display:flex;align-items:center;gap:12px;width:100%;border:0;background:none;padding:10px 12px;cursor:pointer;text-align:left;font:inherit;color:#111;' + (i ? 'border-top:1px solid rgba(0,0,0,.08);' : ''));
      r.type = 'button';
      r.setAttribute('role', 'radio');
      r.setAttribute('data-card', c[0]);
      r.setAttribute('data-kt-iconized', '1');
      r.innerHTML = cardMark(c) + '<span style="flex:1;min-width:0;"><span style="display:block;font-size:15px;font-weight:600;">' + c[1] + ' \u2022\u2022\u2022\u2022 ' + c[2] + '</span>' +
        '<span style="display:block;font-size:12px;color:#6b7280;">Test card</span></span><span data-tick style="display:inline-flex;"></span>';
      r.addEventListener('click', function () { if (!go.disabled) { chosen = c; paint(); } });
      list.appendChild(r);
    });
    card.appendChild(list);
    row('Pay', o.label);
    row('For', 'Invoice ' + o.invoice_number);
    row('Total', money(o.amount) + ' ' + o.currency, true);
    const go = el('button', 'margin-top:12px;width:100%;height:50px;border:0;border-radius:12px;cursor:pointer;font-size:17px;font-weight:700;color:#fff;background:' + (apple ? '#000' : '#1a73e8') + ';',
      apple ? 'Pay with Face ID' : 'Pay ' + money(o.amount));
    go.type = 'button';
    card.appendChild(go); scrim.appendChild(card); document.body.appendChild(scrim);
    paint();
    function close() { scrim.remove(); }
    cancel.addEventListener('click', function () { close(); say('Payment cancelled. Nothing was charged.'); });
    go.addEventListener('click', async function () {
      go.disabled = true; cancel.disabled = true; go.textContent = apple ? 'Face ID\u2026' : 'Processing\u2026';
      try {
        await new Promise(function (r) { setTimeout(r, 900); });
        const r = await api().post('/parent/wallet-pay/demo', { invoice_id: o.invoice_id, wallet: wallet, card: chosen[0] });
        go.textContent = '\u2713 Done'; go.style.background = '#16A34A';
        await new Promise(function (res) { setTimeout(res, 700); });
        close(); onPaid(r);
      } catch (e) {
        close(); say(nicely(e, 'The payment did not go through. Nothing was charged.'));
      }
    });
  }

  function mountDemo(box, o, say, onPaid) {
    const wrap = el('div', 'display:flex;gap:8px;margin:0 0 10px;');
    const mine = deviceWallet();
    [['apple_pay', '#000', '#fff', APPLE, 'Apple Pay'], ['google_pay', '#fff', '#3c4043', GOOGLE, 'Google Pay']].forEach(function (w) {
      if (!o.demo[w[0]] || w[0] !== mine) return;
      const b = el('button', BTN + 'flex:1;background:' + w[1] + ';color:' + w[2] + ';' + (w[1] === '#fff' ? 'border:1px solid #dadce0;' : ''));
      b.type = 'button';
      b.innerHTML = w[3] + '<span>Pay</span>';
      b.setAttribute('aria-label', w[4] + ' ' + money(o.amount));
      b.addEventListener('click', function () { say(''); demoSheet(o, w[0], say, onPaid); });
      wrap.appendChild(b);
    });
    if (!wrap.children.length) return false;
    box.appendChild(wrap);
    return true;
  }

  /**
   * mount(slot, inv, { cardBtn, say(text, ok), onPaid(result) }) - adds whatever wallet buttons this invoice can use.
   * Resolves to the list of what was shown (empty = nothing; the sheet stays exactly as it was).
   */
  async function mount(slot, inv, opts) {
    opts = opts || {};
    const say = opts.say || function () {};
    const onPaid = opts.onPaid || function () {};
    const shown = [];
    let o;
    try { o = await api().get('/parent/wallet-pay/options?invoice_id=' + encodeURIComponent(inv.id)); }
    catch (e) { return shown; }
    if (!o || !(o.amount > 0)) return shown;

    if (o.stripe) {
      const ns = nativeStripe();
      try {
        if (ns) { if (await mountStripeNative(slot, o, ns, say, onPaid)) shown.push('stripe-native'); }
        else if (ns === null) { if (await mountStripeWeb(slot, o, say, onPaid)) shown.push('stripe-web'); }
        // ns === false: an app build without the plugin; Apple Pay JS cannot run in it, so no button.
      } catch (e) { /* the other ways to pay stay */ }
    }
    if (o.helcim && deviceWallet() === 'google_pay' && mountHelcim(slot, o, opts.cardBtn, say, onPaid)) shown.push('helcim');
    if (o.demo && !o.stripe && mountDemo(slot, o, say, onPaid)) shown.push('demo');
    if (shown.length && (shown.indexOf('stripe-web') >= 0 || shown.indexOf('stripe-native') >= 0) && !o.helcim) {
      slot.appendChild(el('div', 'text-align:center;font-size:12px;color:var(--ink-500);margin:-2px 0 10px;', 'or'));
    }
    return shown;
  }

  KT.walletPay = { mount: mount };
})(window);
