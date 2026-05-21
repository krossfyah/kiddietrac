/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p74 — multi-currency money formatter
   Fetches the active agency's currency once (cached), exposes:
     KT.formatMoney(cents)       → "$1,234.50" in agency currency
     KT.formatMoneyDollars(n)    → same, from a dollar amount
     KT.currencyCode()           → "CAD" | "USD" | ...
   Falls back to CAD if the endpoint isn't available.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};

  var SYMBOLS = { CAD: '$', USD: '$', GBP: '£', EUR: '€', AUD: '$', NZD: '$' };
  var LOCALES = { CAD: 'en-CA', USD: 'en-US', GBP: 'en-GB', EUR: 'en-IE', AUD: 'en-AU', NZD: 'en-NZ' };

  KT._currency = KT._currency || null;
  var inflight = null;

  function fetchCurrency() {
    if (KT._currency) return Promise.resolve(KT._currency);
    if (inflight) return inflight;
    var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
    var tok = sessionStorage.getItem('kt_token');
    if (!tok) { KT._currency = 'CAD'; return Promise.resolve('CAD'); }
    var headers = { 'Accept': 'application/json', 'Authorization': 'Bearer ' + tok };
    var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) headers['X-Active-Agency-Id'] = aid;
    inflight = fetch(base + '/admin/currency', { headers: headers })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { KT._currency = (d && d.currency) || 'CAD'; return KT._currency; })
      .catch(function () { KT._currency = 'CAD'; return 'CAD'; });
    return inflight;
  }

  function code() { return KT._currency || 'CAD'; }

  function fmtDollars(n) {
    var c = code();
    var loc = LOCALES[c] || 'en-CA';
    try {
      return new Intl.NumberFormat(loc, { style: 'currency', currency: c }).format(Number(n || 0));
    } catch (e) {
      return (SYMBOLS[c] || '$') + Number(n || 0).toFixed(2);
    }
  }
  function fmtCents(cents) { return fmtDollars(Number(cents || 0) / 100); }

  KT.formatMoney = fmtCents;
  KT.formatMoneyDollars = fmtDollars;
  KT.currencyCode = code;
  KT.refreshCurrency = function () { KT._currency = null; inflight = null; return fetchCurrency(); };

  // Warm the cache after login
  if (sessionStorage.getItem('kt_token')) {
    setTimeout(fetchCurrency, 800);
  }
  window.addEventListener('kt:login', function () { KT._currency = null; inflight = null; fetchCurrency(); });
})(window);
