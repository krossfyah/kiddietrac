/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — facility terminology swapper (2026-07-08).
   Each agency chooses whether it calls its facilities "Centres" or "Rooms"
   (GET /agency/centre-term). When set to "room", this swaps the word in UI
   LABELS only — nav items, headings, buttons, tab labels, table HEADERS — using
   whole-word matching. It deliberately does NOT touch table-cell DATA (a
   family's "Centre St" address, a child's name, etc.). It also normalises the
   combined "Centres / Rooms" labels to the single chosen term for both settings.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktTerm) return; window.__ktTerm = true;
  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function agencyId() { try { return sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) { return ''; } }

  var TERM = 'centre', ready = false;
  var STATE = 'Province', ZIP = 'Postal code';   // country-specific address labels
  function chosen(plural) {
    if (TERM === 'room') return plural ? 'Rooms' : 'Room';
    if (TERM === 'provider') return plural ? 'Providers' : 'Provider';
    return plural ? 'Centres' : 'Centre';
  }

  function transform(t) {
    var out = t;
    if (/centre/i.test(out)) {
      // Combined "Centres / Rooms" + "Centre / Room" → the single chosen term.
      out = out.replace(/Centres\s*\/\s*Rooms/g, chosen(true)).replace(/centres\s*\/\s*rooms/g, chosen(true).toLowerCase());
      out = out.replace(/Centre\s*\/\s*Room/g, chosen(false)).replace(/centre\s*\/\s*room/g, chosen(false).toLowerCase());
      if (TERM === 'room' || TERM === 'provider') {
        var _p = chosen(true), _s = chosen(false);
        out = out.replace(/\bCentres\b/g, _p).replace(/\bCentre\b/g, _s)
          .replace(/\bcentres\b/g, _p.toLowerCase()).replace(/\bcentre\b/g, _s.toLowerCase());
      }
    }
    // Country address labels (base is CA: Province / Postal code).
    if (STATE !== 'Province' && /province/i.test(out)) {
      out = out.replace(/\bProvince\b/g, STATE).replace(/\bprovince\b/g, STATE.toLowerCase());
    }
    if (ZIP !== 'Postal code' && /postal/i.test(out)) {
      out = out.replace(/\bPostal code\b/g, ZIP).replace(/\bPostal Code\b/g, ZIP).replace(/\bpostal code\b/g, ZIP.toLowerCase());
    }
    return out;
  }

  // Label-only scopes — NEVER td / user data.
  // Headings/buttons scoped to #appMain; labels + table headers are matched
  // document-wide so address fields inside modal overlays (which render on
  // document.body) also adapt. The transform only ever touches the words
  // Centre/Province/Postal, so a wide selector is safe.
  var SEL = '#navLinks .nav-label,.sidebar-section-label,#appMain h1,#appMain h2,#appMain h3,'
    + '#appMain button,#appMain summary,#appMain .kt-hero-sub,#appMain .kt-page-hero p,label,th';

  function sweep() {
    if (!ready) return;
    document.querySelectorAll(SEL).forEach(function (el) {
      var kids = el.childNodes;
      for (var i = 0; i < kids.length; i++) {
        var n = kids[i];
        if (n.nodeType === 3) {
          var nt = transform(n.nodeValue);
          if (nt !== n.nodeValue) n.nodeValue = nt;
        }
      }
    });
    // Country address-field placeholders (e.g. "ON" province hint) — labels only.
    if (STATE !== 'Province' || ZIP !== 'Postal code') {
      document.querySelectorAll('#appMain input[placeholder]').forEach(function (inp) {
        var p = inp.getAttribute('placeholder'); if (!p) return;
        var np = transform(p); if (np !== p) inp.setAttribute('placeholder', np);
      });
    }
  }

  function load() {
    var t = tok(); if (!t) return;
    try {
      var c = sessionStorage.getItem('kt_centre_term'); if (c) TERM = c;
      var s = sessionStorage.getItem('kt_state_label'); if (s) STATE = s;
      var z = sessionStorage.getItem('kt_zip_label'); if (z) ZIP = z;
      if (c || s || z) { ready = true; sweep(); }
    } catch (e) {}
    fetch(API + '/agency/centre-term', { headers: { 'Authorization': 'Bearer ' + t, 'X-Active-Agency-Id': agencyId() } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        if (d.term) TERM = d.term;
        if (d.state_label) STATE = d.state_label;
        if (d.zip_label) ZIP = d.zip_label;
        try { sessionStorage.setItem('kt_centre_term', TERM); sessionStorage.setItem('kt_state_label', STATE); sessionStorage.setItem('kt_zip_label', ZIP); } catch (e) {}
        ready = true; sweep();
      }).catch(function () {});
  }

  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
  var CLABELS = { CA: ['Province', 'Postal code'], US: ['State', 'ZIP code'], GB: ['County', 'Postcode'], AU: ['State', 'Postcode'], NZ: ['Region', 'Postcode'], IE: ['County', 'Eircode'] };
  window.KT = window.KT || {};
  window.KT.setCentreTerm = function (term) { TERM = term; try { sessionStorage.setItem('kt_centre_term', term); } catch (e) {} ready = true; sweep(); };
  // Render-time helper so JS that builds DOM nodes can use the agency's chosen
  // facility word directly, instead of relying on the (label/th-scoped) DOM sweep.
  // KT.centreWord()      -> 'Centre' | 'Provider' | 'Room'   (capitalised, singular)
  // KT.centreWord(true)  -> plural;  KT.centreWord(false,true) -> lowercase.
  window.KT.centreWord = function (plural, lower) {
    try { var c = sessionStorage.getItem('kt_centre_term'); if (c) TERM = c; } catch (e) {}
    var w = chosen(!!plural);
    return lower ? w.toLowerCase() : w;
  };
  window.KT.setCountry = function (code) {
    var L = CLABELS[code] || CLABELS.CA; STATE = L[0]; ZIP = L[1];
    try { sessionStorage.setItem('kt_country', code); sessionStorage.setItem('kt_state_label', STATE); sessionStorage.setItem('kt_zip_label', ZIP); } catch (e) {}
    ready = true; sweep();
  };
})();
