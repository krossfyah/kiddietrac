/* ═══════════════════════════════════════════════════════════════════
   kt-mini-map.js — a small map of one address (2026-08-27)

   The provider map already plots every provider with Leaflet and OpenStreetMap. A family
   record needs the same thing for one address: where they live, at a glance, when you are
   arranging a home visit or working out whose route a child is on.

   Extracted rather than copied out of screen-provider-map.js so the geocoding cache and
   the Nominatim etiquette live in one place. Nominatim is a free service run on donated
   hardware with a one-request-per-second policy, so every lookup is cached in
   localStorage and a miss is only ever fetched once.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.MiniMap) { return; }

  var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
  var CACHE_KEY = 'kt_geocode_v1';
  var _leaflet = null;

  function loadLeaflet() {
    if (_leaflet) { return _leaflet; }
    _leaflet = new Promise(function (resolve, reject) {
      if (w.L) { resolve(w.L); return; }
      if (!d.querySelector('link[data-kt-leaflet]')) {
        var css = d.createElement('link');
        css.rel = 'stylesheet'; css.href = LEAFLET_CSS;
        css.setAttribute('data-kt-leaflet', '1');
        d.head.appendChild(css);
      }
      var s = d.createElement('script');
      s.src = LEAFLET_JS;
      s.onload = function () { resolve(w.L); };
      s.onerror = function () { reject(new Error('Could not load the map library')); };
      d.head.appendChild(s);
    });
    return _leaflet;
  }

  function cacheRead(addr) {
    try {
      var c = JSON.parse(w.localStorage.getItem(CACHE_KEY) || '{}');
      return c[addr] || null;
    } catch (e) { return null; }
  }
  function cacheWrite(addr, coords) {
    try {
      var c = JSON.parse(w.localStorage.getItem(CACHE_KEY) || '{}');
      c[addr] = coords;
      /* Bounded: this is a convenience cache, not a database, and an unbounded one
         eventually trips the storage quota and takes unrelated features with it. */
      var keys = Object.keys(c);
      if (keys.length > 300) { keys.slice(0, keys.length - 300).forEach(function (k) { delete c[k]; }); }
      w.localStorage.setItem(CACHE_KEY, JSON.stringify(c));
    } catch (e) { /* a full quota must not break the map */ }
  }

  async function geocode(addr) {
    var key = String(addr || '').trim();
    if (!key) { return null; }

    var hit = cacheRead(key);
    // null is cached too — an address that cannot be found should not be looked up
    // again on every render.
    if (hit !== null) { return hit; }

    var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ca&q='
      + encodeURIComponent(key);
    try {
      var res = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!res.ok) { return null; }
      var data = await res.json();
      var out = (data && data[0])
        ? { lat: parseFloat(data[0].lat), lon: parseFloat(data[0].lon) } : null;
      cacheWrite(key, out);
      return out;
    } catch (e) { return null; }
  }

  /**
   * Draw a map of one address into `host`.
   *
   * Renders nothing at all when there is no address or it cannot be found — an empty
   * grey rectangle over "we don't know where this is" is worse than no map.
   */
  async function render(host, address, opts) {
    if (!host) { return; }
    opts = opts || {};
    var addr = String(address || '').trim();
    if (!addr) { host.innerHTML = ''; return; }

    host.innerHTML = '<div style="font-size:12.5px;color:#94A3B8;padding:6px 0;">Locating…</div>';

    var coords = await geocode(addr);
    if (!coords) {
      host.innerHTML = '<div style="font-size:12.5px;color:#94A3B8;padding:6px 0;">'
        + 'Could not place this address on a map.</div>';
      return;
    }

    var L;
    try { L = await loadLeaflet(); }
    catch (e) { host.innerHTML = ''; return; }

    host.innerHTML = '';
    var box = d.createElement('div');
    box.style.cssText = 'height:' + (opts.height || 190) + 'px;border-radius:10px;'
      + 'overflow:hidden;border:1px solid #E5E7EB;';
    host.appendChild(box);

    var map = L.map(box, {
      zoomControl: true, scrollWheelZoom: false, attributionControl: true,
    }).setView([coords.lat, coords.lon], opts.zoom || 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    L.marker([coords.lat, coords.lon]).addTo(map)
      .bindPopup(String(opts.label || addr));

    /* Leaflet measures its container on creation, and this one is usually inside a modal
       that is still opening — without the nudge the tiles lay out against a zero-height
       box and the map renders as a grey band. */
    setTimeout(function () { try { map.invalidateSize(); } catch (e) {} }, 120);

    var link = d.createElement('a');
    link.href = 'https://www.openstreetmap.org/?mlat=' + coords.lat + '&mlon=' + coords.lon + '#map=16/'
      + coords.lat + '/' + coords.lon;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Open in maps →';
    link.style.cssText = 'display:inline-block;margin-top:6px;font-size:12.5px;color:#1F6080;'
      + 'font-weight:700;text-decoration:none;';
    host.appendChild(link);
  }

  KT.MiniMap = { render: render, geocode: geocode };
})(window, document);
