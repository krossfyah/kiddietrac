/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Zum Connect (2026-09-03)

   A parent adds a card or bank profile inside Zum's own SDK. The card number is
   typed into their frame and never reaches this portal or its server, which is
   the whole reason cards could not be offered here before: Zum's direct card API
   takes a raw PAN, and Connect does not.

   Zum enabled card onboarding on our account on 2026-09-02. Every earlier attempt
   came back "Card Onboarding is not available", which was an account setting on
   their side rather than anything wrong with the payload.

   The flow:
     1. ask our API for a short-lived token   (GET  /parent/zum/connect-token)
     2. load Zum's SDK from THEIR CDN, once
     3. ZumRailsSDK.init({token, …}) opens their UI
     4. onSuccess hands back a Zum userId
     5. post it to our API                    (POST /parent/zum/connect-complete)

   Step 5 is not a formality: the server re-reads that id from Zum and refuses it
   unless the email matches the signed-in account. A page can post any id it likes,
   and an unchecked write would attach a stranger's card to this parent.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (KT.zumConnect) { return; }

  var sdkPromise = null;

  /* `Api` is NOT a window property in this portal — it is a script-scoped binding,
     and KT.Api is the handle other modules use (see kt-forms-to-sign.js). Resolved
     at call time, not captured at load: this file is deferred and there is no
     guarantee KT.Api exists when it parses. */
  function api() {
    return (window.KT && KT.Api) || window.Api || null;
  }

  /* The agency's branding, as branding-loader.js cached it. */
  function branding() {
    try {
      var raw = localStorage.getItem('kt_branding');
      var b = raw ? JSON.parse(raw) : null;
      return (b && b.branding) || b || {};
    } catch (e) { return {}; }
  }

  /* Zum wants a hex WITHOUT the '#'. Anything we cannot read is left to them. */
  function hex(v) {
    var m = String(v || '').trim().replace(/^#/, '');
    return /^[0-9a-f]{6}$/i.test(m) ? m : null;
  }

  function pageLanguage() {
    var l = (document.documentElement.lang || localStorage.getItem('kt_locale') || 'en');
    return String(l).toLowerCase().indexOf('fr') === 0 ? 'fr' : 'en';
  }

  /* A header of OURS above their form.

     Their form is a cross-origin iframe and cannot be touched — no logo of ours
     can go inside it, and Zum offers no logo option. What we can do is say, on our
     side of the boundary, whose form this is: a parent handed a bare third-party
     payment form mid-flow has every reason to hesitate, and naming both parties is
     both the honest answer and the reassuring one.

     If Zum renames their wrapper this does nothing at all, rather than breaking a
     payment to add a picture. */
  function brandTheDialog() {
    try {
      var wrap = document.getElementById('pageModalSDK');
      if (!wrap || wrap.querySelector('.kt-zum-brand')) { return; }

      var b = branding();
      var bar = document.createElement('div');
      bar.className = 'kt-zum-brand';
      bar.style.cssText = 'display:flex;align-items:center;gap:10px;padding:11px 14px;'
        + 'background:#fff;border-bottom:1px solid #E2E8F0;border-radius:12px 12px 0 0;'
        + 'font:600 12.5px/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
        + 'color:#475569;';

      if (b.logo_url) {
        var img = document.createElement('img');
        img.src = b.logo_url;
        img.alt = b.product_name || 'Logo';
        img.style.cssText = 'height:22px;width:auto;flex:0 0 auto;';
        // A broken logo must not leave a torn icon on a payment screen.
        img.onerror = function () { img.remove(); };
        bar.appendChild(img);
      }

      var txt = document.createElement('div');
      txt.style.cssText = 'flex:1;min-width:0;';
      txt.innerHTML = '<div style="color:#0F172A;font-weight:700;">'
        + (b.product_name ? String(b.product_name).replace(/[<>&]/g, '') : 'Your centre')
        + '</div><div style="font-weight:500;color:#64748B;">Card details go straight to our '
        + 'payment provider — we never see them.</div>';
      bar.appendChild(txt);

      wrap.insertBefore(bar, wrap.firstChild);
    } catch (e) { /* branding is never worth a failed payment */ }
  }

  /* Loaded from Zum's CDN, and only when a parent actually asks to add a method —
     a payment SDK on every page load is a third party watching every screen. */
  function loadSdk(src) {
    if (window.ZumRailsSDK) { return Promise.resolve(true); }
    if (sdkPromise) { return sdkPromise; }
    sdkPromise = new Promise(function (resolve) {
      var s = document.createElement('script');
      s.id = 'zumrailssdk';
      s.src = src;
      s.async = true;
      s.onload = function () { resolve(!!window.ZumRailsSDK); };
      s.onerror = function () { sdkPromise = null; resolve(false); };
      document.head.appendChild(s);
    });
    return sdkPromise;
  }

  /**
   * Open Zum's onboarding UI.
   * @param {object} opts { onSaved, onError, allow }
   * @returns {Promise<boolean>} true once a method was saved AND accepted by us.
   */
  KT.zumConnect = async function (opts) {
    opts = opts || {};
    var fail = function (m) {
      if (opts.onError) { opts.onError(m); }
      else if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(m, 'error'); }
      return false;
    };

    var A = api();
    if (!A) { return fail('The payment form could not start. Please reload the page.'); }

    var cfg;
    try {
      cfg = await A.get('/parent/zum/connect-token');
    } catch (e) {
      return fail((e && e.message) || 'We could not start the secure payment form.');
    }
    if (!cfg || !cfg.token || !cfg.sdk) {
      return fail('We could not start the secure payment form. Please try again shortly.');
    }

    var ok = await loadSdk(cfg.sdk);
    if (!ok || !window.ZumRailsSDK) {
      return fail('The secure payment form could not be loaded. Check your connection and try again.');
    }

    return new Promise(function (resolve) {
      var settled = false;
      var done = function (v) { if (!settled) { settled = true; resolve(v); } };

      var b = branding();
      /* Zum's own customisation is colours and language only — there is no logo
         field, which is why the header above is ours rather than theirs. */
      var initOpts = {
        token: cfg.token,
        language: pageLanguage(),
      };
      var fg = hex(b.primary_color);
      var bg = hex(b.background_color);
      if (fg) { initOpts.foregroundColor = fg; }
      if (bg) { initOpts.backgroundColor = bg; }

      window.ZumRailsSDK.init(Object.assign(initOpts, {

        onError: function (err) {
          // Their message is about their form; ours is about what to do next.
          try { console.warn('[zum-connect]', err); } catch (e) {}
          fail('That could not be completed. Nothing has been saved or charged.');
          done(false);
        },

        /* Closing the window is a normal thing to do and not an error — say
           nothing, and leave the page exactly as it was. */
        onButtonClose: function () { done(false); },

        onSuccess: async function (data) {
          var zumUserId = data && (data.userId || data.UserId);
          if (!zumUserId) {
            fail('That payment method could not be saved. Please try again.');
            return done(false);
          }
          try {
            /* The server verifies this id against Zum before trusting it — see the
               note at the top. A success here means it was accepted, not merely
               reported. */
            var r = await A.post('/parent/zum/connect-complete', {
              zum_user_id: String(zumUserId),
            });
            if (opts.onSaved) { opts.onSaved(r); }
            else if (KT.Dom && KT.Dom.toast) {
              KT.Dom.toast((r && r.message) || 'Your payment method is saved.', 'success');
            }
            done(true);
          } catch (e) {
            fail((e && e.message) || 'We could not confirm that payment method.');
            done(false);
          }
        },
      }));

      /* Their wrapper appears a tick after init, and again if they re-render it.
         Watched briefly rather than guessed at with one timeout. */
      var tries = 0;
      var brandTimer = setInterval(function () {
        brandTheDialog();
        if (++tries > 40 || !document.getElementById('pageModalSDK')) {
          if (tries > 40) { clearInterval(brandTimer); }
        }
      }, 150);
      setTimeout(function () { clearInterval(brandTimer); }, 8000);
    });
  };
})(window);
