/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p90 — Provider map
   Hash: #provider-map
   A reference map of every provider (centre) in the agency, plotted from
   their address. Uses Leaflet + OpenStreetMap (no API key) and geocodes
   addresses via Nominatim, caching each result in localStorage so the map
   loads instantly on repeat visits.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;

  var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
  var GEO_CACHE_PREFIX = 'kt_geo_v1_';

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  // ── Dynamic Leaflet loader ─────────────────────────────────────────
  var _leafletPromise = null;
  function loadLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (_leafletPromise) return _leafletPromise;
    _leafletPromise = new Promise(function (resolve, reject) {
      if (!document.querySelector('link[data-kt-leaflet]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet'; link.href = LEAFLET_CSS; link.setAttribute('data-kt-leaflet', '1');
        document.head.appendChild(link);
      }
      var s = document.createElement('script');
      s.src = LEAFLET_JS;
      s.onload = function () { resolve(window.L); };
      s.onerror = function () { reject(new Error('Could not load the map library.')); };
      document.head.appendChild(s);
    });
    return _leafletPromise;
  }

  // ── Geocoding (Nominatim) with localStorage cache ──────────────────
  function addressString(c) {
    var parts = [c.address_line1, c.address_line2, c.city, c.province, c.postal_code, 'Canada'];
    return parts.filter(function (p) { return p && String(p).trim(); }).join(', ');
  }
  function cacheKey(addr) {
    // Cheap stable hash of the address string.
    var h = 0; for (var i = 0; i < addr.length; i++) { h = (h * 31 + addr.charCodeAt(i)) | 0; }
    return GEO_CACHE_PREFIX + (h >>> 0).toString(36);
  }
  function readCache(addr) {
    try { var v = localStorage.getItem(cacheKey(addr)); return v ? JSON.parse(v) : null; } catch (e) { return null; }
  }
  function writeCache(addr, val) {
    try { localStorage.setItem(cacheKey(addr), JSON.stringify(val)); } catch (e) { /* quota — ignore */ }
  }
  async function geocode(addr) {
    var cached = readCache(addr);
    if (cached) return cached;
    var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ca&q=' + encodeURIComponent(addr);
    var res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!res.ok) throw new Error('geocode HTTP ' + res.status);
    var data = await res.json();
    var hit = (data && data[0]) ? { lat: parseFloat(data[0].lat), lon: parseFloat(data[0].lon) } : null;
    if (hit) writeCache(addr, hit);
    return hit;
  }

  // Deterministic colour per provider so its map pin + list row match.
  var PM_PALETTE = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D', '#B45309', '#6D28D9'];
  function providerColor(c) {
    var s = String((c && (c.id != null ? c.id : c.name)) || '');
    var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return PM_PALETTE[h % PM_PALETTE.length];
  }
  // A colour-coded teardrop pin (Leaflet divIcon) instead of the identical blue default.
  function coloredPin(L, color) {
    return L.divIcon({
      className: 'kt-pm-pin',
      html: '<div style="width:22px;height:22px;border-radius:50% 50% 50% 0;background:' + color + ';'
        + 'transform:rotate(-45deg);border:2.5px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.45);"></div>',
      iconSize: [22, 22], iconAnchor: [11, 22], popupAnchor: [0, -20],
    });
  }

  // ── Render ─────────────────────────────────────────────────────────
  async function render(container) {
    container.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.style.cssText = 'padding:24px;max-width:1800px;margin:0 auto;';
    container.appendChild(wrap);

    wrap.insertAdjacentHTML('beforeend',
      '<div class="kt-hero" style="background:linear-gradient(135deg,#1F6080 0%,#3BBBBE 60%,#7C3AED 100%);">' +
        '<div class="kt-hero-greet">🗺️ REFERENCE</div><h1>Provider map</h1>' +
        '<div class="kt-hero-sub">Every provider (centre) in your agency, plotted by address.</div>' +
      '</div>');

    var statusBar = document.createElement('div');
    statusBar.style.cssText = 'margin:16px 0;color:#6B7280;font-size:13px;';
    statusBar.textContent = 'Loading providers…';
    wrap.appendChild(statusBar);

    var layout = document.createElement('div');
    layout.style.cssText = 'display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start;';
    wrap.appendChild(layout);

    // A stable grid column wraps the list, so a globally-injected search/sort
    // toolbar lands INSIDE this column rather than becoming a 3rd grid item
    // (which was pushing the map into the wrong cell).
    var listCol = document.createElement('div');
    listCol.id = 'kt-pm-listcol';
    layout.appendChild(listCol);
    // The globally-injected search/sort toolbar was cramped in this narrow (320px)
    // column — the search box grabbed the whole first row and the Sort dropdown
    // wrapped to a squished second row. Stack them as full-width, aligned rows.
    if (!document.getElementById('kt-pm-ctrl-style')) {
      var pmStyle = document.createElement('style');
      pmStyle.id = 'kt-pm-ctrl-style';
      pmStyle.textContent =
        '#kt-pm-listcol .kt-list-controls{flex-direction:column !important;align-items:stretch !important;gap:8px !important;margin:0 0 12px !important;flex-wrap:nowrap !important;}'
        + '#kt-pm-listcol .kt-list-controls > *{width:100% !important;box-sizing:border-box;}'
        + '#kt-pm-listcol .kt-list-controls select{flex:1 1 auto !important;min-width:0;}';
      document.head.appendChild(pmStyle);
    }
    var listPanel = document.createElement('div');
    listPanel.style.cssText = 'background:white;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);max-height:640px;overflow:auto;';
    listPanel.setAttribute('data-kt-list', '1');
    listCol.appendChild(listPanel);

    var mapPanel = document.createElement('div');
    mapPanel.style.cssText = 'position:relative;background:#E8EEF2;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden;';
    var mapEl = document.createElement('div');
    mapEl.style.cssText = 'width:100%;height:640px;';
    mapPanel.appendChild(mapEl);
    layout.appendChild(mapPanel);

    // Responsive: stack on narrow screens
    if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
      layout.style.gridTemplateColumns = '1fr';
      mapEl.style.height = '420px';
    }

    var centres, L;
    try {
      var resp = await Api.get('/admin/centres');
      centres = (resp && resp.centres) || (Array.isArray(resp) ? resp : (resp && resp.data) || []);
      L = await loadLeaflet();
    } catch (e) {
      statusBar.style.color = '#DC2626';
      statusBar.textContent = 'Could not load the map: ' + (e.message || 'error');
      return;
    }
    centres = centres.filter(function (c) { return c && String(c.status || 'active') !== 'archived'; });

    if (!centres.length) {
      statusBar.textContent = 'No providers to map yet.';
      return;
    }

    var map = L.map(mapEl, { scrollWheelZoom: true }).setView([43.9, -80.1], 9); // Dufferin County-ish default
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    // Recalculate size once the grid layout has settled (avoids a squished map).
    setTimeout(function () { try { map.invalidateSize(); } catch (e) {} }, 250);

    var bounds = [];
    var located = 0, failed = [];

    // Geocode sequentially to respect Nominatim's usage policy (cached hits are
    // instant; only un-cached addresses incur the ~1s spacing).
    for (var i = 0; i < centres.length; i++) {
      var c = centres[i];
      var addr = addressString(c);
      statusBar.textContent = 'Locating providers… (' + (i + 1) + '/' + centres.length + ')';

      var rowId = 'kt-pm-row-' + c.id;
      var pcolor = providerColor(c);
      listPanel.insertAdjacentHTML('beforeend',
        '<div id="' + rowId + '" data-cid="' + c.id + '" style="padding:12px 14px 12px 12px;border-bottom:1px solid #F3F4F6;border-left:4px solid ' + pcolor + ';cursor:pointer;">' +
          '<div style="display:flex;align-items:center;gap:7px;">' +
            '<span style="width:10px;height:10px;border-radius:50%;background:' + pcolor + ';flex-shrink:0;"></span>' +
            '<span style="font-weight:700;font-size:14px;color:#111827;">' + esc(c.name) + '</span>' +
          '</div>' +
          '<div style="font-size:12px;color:#6B7280;margin-top:2px;">' + esc(addr || 'No address on file') + '</div>' +
          '<div style="font-size:11px;color:#64748B;margin-top:3px;" class="kt-pm-meta">…</div>' +
        '</div>');

      var coords = null;
      if (c.latitude != null && c.longitude != null) {
        // Server-persisted coordinates → instant, no geocoding / no rate-limit wait.
        coords = { lat: Number(c.latitude), lon: Number(c.longitude) };
      } else if (addr) {
        var wasCached = !!readCache(addr);
        try { coords = await geocode(addr); } catch (e) { coords = null; }
        if (!wasCached) await sleep(1100); // be polite to Nominatim
        // Persist so it's instant for EVERYONE next time (not just this browser).
        if (coords) { try { Api.post('/admin/centres/' + c.id + '/coords', { latitude: coords.lat, longitude: coords.lon }); } catch (e) {} }
      }

      var rowEl = document.getElementById(rowId);
      var meta = rowEl ? rowEl.querySelector('.kt-pm-meta') : null;
      if (coords) {
        located++;
        bounds.push([coords.lat, coords.lon]);
        var popup =
          '<div style="font-family:Inter,system-ui,sans-serif;min-width:180px;">' +
            '<div style="font-weight:800;font-size:14px;color:#111827;">' + esc(c.name) + '</div>' +
            '<div style="font-size:12px;color:#6B7280;margin-top:3px;">' + esc(addr) + '</div>' +
            (c.phone ? '<div style="font-size:12px;color:#6B7280;margin-top:3px;">📞 ' + esc(c.phone) + '</div>' : '') +
            (c.date_of_birth ? '<div style="font-size:12px;color:#6B7280;margin-top:3px;">🎂 Provider DOB: ' + esc(c.date_of_birth) + '</div>' : '') +
            (c.license_capacity ? '<div style="font-size:12px;color:#6B7280;margin-top:3px;">Capacity: ' + esc(c.license_capacity) + '</div>' : '') +
          '</div>';
        var marker = L.marker([coords.lat, coords.lon], { icon: coloredPin(L, pcolor) }).addTo(map).bindPopup(popup);
        if (meta) meta.textContent = (c.license_capacity ? ('Capacity ' + c.license_capacity + ' · ') : '') + 'Located';
        (function (m, el) {
          if (el) el.addEventListener('click', function () { map.setView(m.getLatLng(), 14); m.openPopup(); });
        })(marker, rowEl);
      } else {
        failed.push(c.name);
        if (meta) { meta.textContent = addr ? 'Could not locate this address' : 'No address on file'; meta.style.color = '#DC2626'; }
      }
    }

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
    }
    setTimeout(function () { map.invalidateSize(); }, 200);

    var summary = located + ' of ' + centres.length + ' providers located';
    if (failed.length) summary += ' · ' + failed.length + ' missing/invalid address';
    statusBar.style.color = failed.length ? '#92400E' : '#16A34A';
    statusBar.textContent = summary;
  }

  KT.ProviderMap = { render: render };
})(window);
