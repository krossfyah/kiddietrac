/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the one "add a card" form (2026-11-06).

   Anthony: "add card in the app for parents can you make this a camera as well to scan the card back and front and auto
   populate - full sweep". He chose the phone's own scan (asked 2026-11-06): the card number lives in Stripe's secure
   frame, which our code may not read or fill, and running our own camera over a card would put full card numbers
   through KiddieTrac (PCI scope). So this uses Stripe's Payment Element - labelled card number / expiry / CVC / postal
   fields with proper autocomplete - which is where the phone's own "Scan Credit Card" (iPhone Safari keyboard bar) and
   "Scan new card" (Android Chrome autofill) appear, when that browser offers them. The card never touches our code.

   Three screens added a card three ways (Wallet, Autopay, Billing settings autopay), all with the old one-line Card
   Element and no camera. They all call this now:

     const f = await KT.cardForm.mount(hostEl, { publishableKey, clientSecret, stripeAccount });
     const pmId = await f.confirm();      // throws Error(message) when Stripe refuses

   The SetupIntent stays exactly as the server made it (card only, off_session).
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (KT.cardForm) return;

  function loadStripe() {
    if (window.Stripe) return Promise.resolve(window.Stripe);
    return new Promise(function (resolve, reject) {
      var s = document.querySelector('script[src^="https://js.stripe.com/v3"]');
      if (!s) { s = document.createElement('script'); s.src = 'https://js.stripe.com/v3/'; document.head.appendChild(s); }
      s.addEventListener('load', function () { resolve(window.Stripe); });
      s.addEventListener('error', function () { reject(new Error('The secure card form could not load. Check your connection and try again.')); });
      setTimeout(function () { if (window.Stripe) resolve(window.Stripe); }, 50);
    });
  }

  // Where the phone can scan a card, say how; nowhere else (a laptop has no card camera).
  function scanTip() {
    var ua = navigator.userAgent || '';
    var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var android = /Android/.test(ua);
    if (ios) return 'Tip: tap <b>Card number</b>, then <b>Scan Credit Card</b> above the keyboard (when your iPhone offers it) to fill it in with the camera.';
    if (android) return 'Tip: tap <b>Card number</b>, then <b>Scan new card</b> (when your phone offers it) to fill it in with the camera.';
    return '';
  }

  // The brand's colours when this is a white-label agency (branding-loader sets these), KiddieTrac's otherwise.
  function cssVar(name, fallback) {
    try { var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim(); return v || fallback; } catch (e) { return fallback; }
  }

  KT.cardForm = {
    mount: function (host, opts) {
      opts = opts || {};
      if (!opts.publishableKey || !opts.clientSecret) return Promise.reject(new Error('Card payments aren’t set up for your centre yet.'));
      host.innerHTML = '';
      var tip = scanTip();
      if (tip) {
        var t = document.createElement('div');
        t.style.cssText = 'display:flex;gap:8px;align-items:flex-start;background:#F0F9FF;border:1px solid #BAE6FD;color:#0C4A6E;'
          + 'border-radius:10px;padding:9px 11px;font-size:12.5px;line-height:1.45;margin-bottom:12px;';
        t.innerHTML = '<span aria-hidden="true" style="font-size:16px;line-height:1.1;">📷</span><span>' + tip + '</span>';
        host.appendChild(t);
      }
      var slot = document.createElement('div');
      slot.style.cssText = 'min-height:180px;';
      host.appendChild(slot);
      return loadStripe().then(function (Stripe) {
        var stripe = opts.stripeAccount ? Stripe(opts.publishableKey, { stripeAccount: opts.stripeAccount }) : Stripe(opts.publishableKey);
        var elements = stripe.elements({
          clientSecret: opts.clientSecret,
          appearance: {
            theme: 'stripe',
            variables: {
              colorPrimary: cssVar('--kt-brand-primary', '#1F6080'),
              borderRadius: '9px',
              fontFamily: 'Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
              fontSizeBase: '15px',
            },
          },
        });
        var pe = elements.create('payment', {
          layout: 'tabs',
          // No Stripe Link sign-up (email + phone + "save my information") inside a centre's form.
          wallets: { applePay: 'never', googlePay: 'never', link: 'never' },
          terms: { card: 'never' },
        });
        pe.mount(slot);
        return {
          element: pe,
          confirm: function () {
            return stripe.confirmSetup({ elements: elements, redirect: 'if_required', confirmParams: { return_url: location.href } })
              .then(function (res) {
                if (res.error) throw new Error(res.error.message || 'The card could not be saved.');
                var pm = res.setupIntent && res.setupIntent.payment_method;
                if (!pm) throw new Error('The card could not be saved. Please try again.');
                return typeof pm === 'string' ? pm : pm.id;
              });
          },
        };
      });
    },
  };
})(window, document);
