/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Walk & outing live GPS tracking (v2, 2026-08-10)
   ───────────────────────────────────────────────────────────────────
   • Educator picks the checked-in children on the walk (dot selection),
     a destination is required, then shares live GPS.
   • A live Leaflet map that MOVES with the educator — shown to the
     educator, and to parents / admins / directors via the shared viewer.
   • Sharing runs at MODULE scope so it keeps pinging across screens; a
     redesigned floating pill stays visible until Stop.
   Backend: /provider/walks/{eligible-children,start,active,{id}/end},
            /field-trips/{id}/ping, /field-trips/{id}/location,
            /parent/active-walks.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Api = KT.Api;
  var Shell = KT.Shell;
  if (!Api) { return; }

  var PING_EVERY_MS = 12000;
  var state = { tripId: null, watchId: null, pings: 0, lastPingAt: 0, sharing: false, lat: null, lon: null, lastAcc: null, status: '', trail: [], map: null, marker: null, accCircle: null, path: null };

  // ─── Leaflet loader (same CDN the provider map uses) ─────────────────
  var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
  var _leafletP = null;
  function loadLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (_leafletP) return _leafletP;
    _leafletP = new Promise(function (res, rej) {
      if (!document.querySelector('link[data-kt-leaflet]')) {
        var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = LEAFLET_CSS; l.setAttribute('data-kt-leaflet', '1'); document.head.appendChild(l);
      }
      var s = document.createElement('script'); s.src = LEAFLET_JS;
      s.onload = function () { res(window.L); };
      s.onerror = function () { rej(new Error('Could not load the map.')); };
      document.head.appendChild(s);
    });
    return _leafletP;
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, kind) { if (KT.toast) { KT.toast(msg, kind); } }
  function fmtClock(d) { try { return new Date(d).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; } }
  function fmtDist(m) { if (m == null) return '—'; return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : Math.round(m) + ' m'; }
  function fmtSteps(n) { if (n == null) return '—'; return n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n); }
  function fmtDur(min) { if (!min) return '—'; if (min < 60) return min + ' min'; return Math.floor(min / 60) + 'h ' + (min % 60) + 'm'; }

  function apiHost(u) { if (!u) return u; return /^https?:/.test(u) ? u : ('https://api.kiddietrac.com' + u); }
  function avaColour(name) { var h = 0; name = name || '?'; for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0; var hue = Math.abs(h) % 360; return 'hsl(' + hue + ',55%,55%)'; }
  function childDot(c, size) {
    size = size || 46;
    var initials = (c.name || '?').trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
    if (c.photo_url) {
      return '<span class="ktw-dot-img" style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:#e5e7eb center/cover no-repeat url(' + esc(apiHost(c.photo_url)) + ');display:inline-block;"></span>';
    }
    return '<span class="ktw-dot-img" style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:' + avaColour(c.name) + ';color:#fff;font-weight:800;font-size:' + Math.round(size * 0.36) + 'px;display:flex;align-items:center;justify-content:center;">' + esc(initials) + '</span>';
  }

  // ─── Redesigned floating pill (visible on every screen while sharing) ─
  function injectCss() {
    if (document.getElementById('ktw-css')) return;
    var s = document.createElement('style'); s.id = 'ktw-css';
    s.textContent =
      '@keyframes ktwPulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.55)}70%{box-shadow:0 0 0 10px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}' +
      '#ktw-pill{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(78px + env(safe-area-inset-bottom,0px));z-index:99998;display:flex;align-items:center;gap:11px;padding:8px 8px 8px 14px;border-radius:999px;background:rgba(15,23,42,.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);box-shadow:0 10px 30px rgba(0,0,0,.35);color:#fff;font-size:13px;font-weight:700;max-width:94vw;}' +
      '#ktw-pill .ktw-live{display:flex;align-items:center;gap:7px;white-space:nowrap;}' +
      '#ktw-pill .ktw-live b{width:9px;height:9px;border-radius:50%;background:#34D399;animation:ktwPulse 1.7s infinite;}' +
      '#ktw-pill .ktw-sub{opacity:.72;font-weight:600;font-size:11.5px;}' +
      '#ktw-pill button{border:0;cursor:pointer;font-weight:800;border-radius:999px;font-size:12.5px;}' +
      '#ktw-pill .ktw-map{background:rgba(255,255,255,.14);color:#fff;padding:7px 12px;}' +
      '#ktw-pill .ktw-stop{background:#fff;color:#DC2626;padding:7px 14px;}' +
      '.ktw-chip{position:relative;border:2px solid transparent;border-radius:16px;padding:8px 6px 6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:5px;background:#fff;transition:.12s;-webkit-tap-highlight-color:transparent;}' +
      '.ktw-chip .ktw-nm{font-size:11px;font-weight:700;color:#334155;max-width:66px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
      '.ktw-chip.on{border-color:#0E9E6E;background:#ECFDF5;}' +
      '.ktw-chip .ktw-tick{position:absolute;top:2px;right:2px;width:19px;height:19px;border-radius:50%;background:#0E9E6E;color:#fff;font-size:12px;display:none;align-items:center;justify-content:center;font-weight:900;}' +
      '.ktw-chip.on .ktw-tick{display:flex;}' +
      '.leaflet-container{font:inherit;}';
    document.head.appendChild(s);
  }

  function renderPill() {
    injectCss();
    var pill = document.getElementById('ktw-pill');
    if (!state.sharing) { if (pill) pill.remove(); return; }
    if (!pill) { pill = document.createElement('div'); pill.id = 'ktw-pill'; document.body.appendChild(pill); }
    var sub = state.pings > 0
      ? (fmtDist(walkDist()) + (state.lastAcc != null ? ' · ±' + Math.round(state.lastAcc) + 'm' : ''))
      : (state.status || 'Getting your location…');
    pill.innerHTML =
      '<span class="ktw-live"><b></b><span>Live<span class="ktw-sub"> · ' + esc(sub) + '</span></span></span>' +
      '<button class="ktw-map" id="ktw-pill-map">Map</button>' +
      '<button class="ktw-stop" id="ktw-pill-stop">Stop</button>';
    pill.querySelector('#ktw-pill-map').onclick = function () { openMap(state.tripId, 'Your walk'); };
    pill.querySelector('#ktw-pill-stop').onclick = confirmStop;
  }

  // ─── Distance from the local trail (educator's own device) ───────────
  function haversine(a, b) {
    var R = 6371000, dLat = (b[0] - a[0]) * Math.PI / 180, dLon = (b[1] - a[1]) * Math.PI / 180;
    var s = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * Math.PI / 180) * Math.cos(b[0] * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
  }
  function walkDist() { var d = 0; for (var i = 1; i < state.trail.length; i++) { var seg = haversine(state.trail[i - 1], state.trail[i]); if (seg >= 3 && seg <= 250) d += seg; } return d; }

  // ─── Leaflet map helpers ─────────────────────────────────────────────
  function drawMap(container, lat, lon, trail, follow) {
    return loadLeaflet().then(function (L) {
      var m = container._ktmap;
      if (!m) {
        m = L.map(container, { zoomControl: true, attributionControl: false }).setView([lat, lon], 17);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(m);
        container._ktmap = m;
        container._ktmarker = L.circleMarker([lat, lon], { radius: 9, color: '#fff', weight: 3, fillColor: '#0E9E6E', fillOpacity: 1 }).addTo(m);
        container._ktpath = L.polyline([], { color: '#0E9E6E', weight: 4, opacity: .75 }).addTo(m);
        setTimeout(function () { try { m.invalidateSize(); } catch (e) {} }, 200);
      }
      container._ktmarker.setLatLng([lat, lon]);
      if (trail && trail.length) container._ktpath.setLatLngs(trail);
      if (follow) m.panTo([lat, lon], { animate: true, duration: .6 });
      return m;
    });
  }

  /** Mount a self-refreshing live map for viewers (parents/admin/director). */
  function mountLiveMap(container, tripId, onMeta) {
    container.innerHTML = '<div style="padding:26px;text-align:center;color:#64748B;">Loading live map…</div>';
    function tick() {
      Api.get('/field-trips/' + tripId + '/location').then(function (r) {
        if (onMeta) onMeta(r);
        if (!r || !r.latest) {
          if (!container._ktmap) container.innerHTML = '<div style="background:#FFFBEB;color:#92400E;padding:16px;border-radius:12px;line-height:1.5;">No location yet — the educator needs to tap <em>Start walk</em> and allow location.</div>';
          return;
        }
        var lat = parseFloat(r.latest.lat), lon = parseFloat(r.latest.lon);
        var trail = (r.trail || []).map(function (p) { return [parseFloat(p.lat), parseFloat(p.lon)]; });
        if (!container._ktmap) container.innerHTML = '';
        drawMap(container, lat, lon, trail, true);
      }).catch(function () {});
    }
    tick();
    container._kttimer = setInterval(tick, 12000);
    return function stop() { clearInterval(container._kttimer); };
  }

  // ─── GPS sharing lifecycle ───────────────────────────────────────────
  function setStatus(msg) {
    state.status = msg; renderPill();
    var s = document.getElementById('ktw-live-status'); if (s) s.textContent = msg;
    var d = document.getElementById('ktw-live-dist'); if (d) d.textContent = fmtDist(walkDist());
    var st = document.getElementById('ktw-live-steps'); if (st) st.textContent = fmtSteps(Math.round(walkDist() / 0.72));
  }

  function onFix(pos) {
    if (!state.sharing) return;
    state.lat = pos.coords.latitude; state.lon = pos.coords.longitude; state.lastAcc = pos.coords.accuracy; state.lastErr = null;
    state.trail.push([state.lat, state.lon]);
    if (state.trail.length > 400) state.trail.shift();
    // Move the educator's own inline map immediately.
    var mc = document.getElementById('ktw-live-map'); if (mc) drawMap(mc, state.lat, state.lon, state.trail, true);
    var now = Date.now();
    if (now - state.lastPingAt < PING_EVERY_MS) { setStatus('Located · ±' + Math.round(state.lastAcc) + 'm'); return; }
    state.lastPingAt = now;
    Api.post('/field-trips/' + state.tripId + '/ping', {
      lat: +state.lat.toFixed(6), lon: +state.lon.toFixed(6),
      accuracy_m: state.lastAcc != null ? Math.round(state.lastAcc) : null,
      speed_mps: (pos.coords.speed != null && !isNaN(pos.coords.speed)) ? pos.coords.speed : null
    }).then(function () { state.pings++; setStatus('Sharing · ' + state.pings + ' sent'); })
      .catch(function () { setStatus('Fix OK · upload retrying'); });
  }
  function onGeoErr(err) {
    var code = err && err.code;
    if (code === 1) { toast('Location is off. Allow location for KiddieTrac in Settings, then start again.', 'error'); stopSharing(true); setStatus('Permission denied'); return; }
    setStatus(code === 3 ? 'Waiting for GPS signal…' : 'No GPS fix yet');
  }
  function beginWatch() {
    if (!navigator.geolocation) { toast('This device has no GPS.', 'error'); return false; }
    try {
      setStatus('Getting your location…');
      navigator.geolocation.getCurrentPosition(onFix, onGeoErr, { enableHighAccuracy: false, timeout: 20000, maximumAge: 30000 });
      state.watchId = navigator.geolocation.watchPosition(onFix, onGeoErr, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
    } catch (e) { toast('Could not start GPS.', 'error'); return false; }
    return true;
  }
  function ensureNativeLocation() {
    var done, p = new Promise(function (r) { done = r; }), settled = false;
    var fin = function () { if (!settled) { settled = true; done(); } };
    setTimeout(fin, 7000);
    try { var C = window.Capacitor, bio = C && C.Plugins && C.Plugins.KtBio; if (bio && bio.requestLocation) bio.requestLocation().then(fin, fin); else fin(); } catch (e) { fin(); }
    return p;
  }
  function startSharing(tripId) {
    state.tripId = tripId; state.pings = 0; state.lastPingAt = 0; state.sharing = true; state.lastAcc = null; state.trail = []; state.status = 'Starting…';
    renderPill();
    ensureNativeLocation().then(function () { if (!state.sharing) return; if (!beginWatch()) { state.sharing = false; renderPill(); refresh(); return; } renderPill(); });
  }
  function stopSharing(silent) {
    if (state.watchId != null && navigator.geolocation) { try { navigator.geolocation.clearWatch(state.watchId); } catch (e) {} }
    var tid = state.tripId; state.sharing = false; state.watchId = null; renderPill();
    if (tid && !silent) Api.post('/provider/walks/' + tid + '/end', {}).catch(function () {});
    refresh();
  }
  function confirmStop() {
    Promise.resolve(KT.confirm ? KT.confirm('Stop sharing your location and end this walk?') : window.confirm('End this walk?')).then(function (ok) {
      if (ok) { stopSharing(false); toast('Walk ended — sharing stopped.', 'success'); }
    });
  }

  // ─── Shared live-map modal (parents / admins / directors) ────────────
  function openMap(tripId, title) {
    injectCss();
    var old = document.getElementById('ktw-modal'); if (old) { try { old._stop && old._stop(); } catch (e) {} old.remove(); }
    var m = document.createElement('div'); m.id = 'ktw-modal';
    m.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:12px;';
    m.innerHTML =
      '<div style="background:#fff;border-radius:18px;max-width:720px;width:100%;max-height:94vh;overflow:auto;box-shadow:0 24px 60px rgba(0,0,0,.4);">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid #EEF2F7;">' +
          '<div style="font-size:16px;font-weight:800;color:#0F172A;">🚶 ' + esc(title || 'Live location') + '</div>' +
          '<button id="ktw-x" style="background:#F1F5F9;border:0;border-radius:10px;width:34px;height:34px;font-size:18px;cursor:pointer;color:#334155;">×</button>' +
        '</div>' +
        '<div id="ktw-stats" style="display:flex;gap:9px;flex-wrap:wrap;padding:14px 18px 0;"></div>' +
        '<div id="ktw-mapbox" style="height:60vh;min-height:340px;margin:14px 18px 18px;border-radius:14px;overflow:hidden;border:1px solid #E2E8F0;"></div>' +
      '</div>';
    document.body.appendChild(m);
    var close = function () { try { m._stop && m._stop(); } catch (e) {} m.remove(); };
    m.querySelector('#ktw-x').onclick = close;
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
    m._stop = mountLiveMap(m.querySelector('#ktw-mapbox'), tripId, function (r) {
      var s = m.querySelector('#ktw-stats'); if (!s) return;
      var tile = function (bg, br, fg, l, v) { return '<div style="flex:1;min-width:90px;background:' + bg + ';border:1px solid ' + br + ';border-radius:12px;padding:9px 12px;"><div style="font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:' + fg + ';opacity:.85;">' + l + '</div><div style="font-size:16px;font-weight:800;color:' + fg + ';">' + v + '</div></div>'; };
      s.innerHTML =
        tile('#ECFDF5', '#A7F3D0', '#065F46', 'Updated', r && r.latest ? esc(fmtClock(r.latest.recorded_at)) : '—') +
        tile('#EFF6FF', '#BFDBFE', '#1E40AF', 'Distance', fmtDist(r && r.distance_m)) +
        tile('#FEF3F2', '#FECACA', '#B91C1C', 'Steps', fmtSteps(r && r.steps_est)) +
        tile('#FFFBEB', '#FDE68A', '#92400E', 'Time', fmtDur(r && r.duration_min));
    });
  }

  // ─── Educator walk screen ────────────────────────────────────────────
  var _main = null;
  function refresh() { if (_main && document.body.contains(_main)) renderWalkScreen(_main); }

  function renderWalkScreen(main) {
    _main = main; injectCss();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:22px;max-width:640px;margin:0 auto;color:#64748B;">Loading…</div>';
    Api.get('/provider/walks/active').then(function (r) {
      var a = r && r.active;
      if (state.sharing && a) return renderLive(main, a);
      if (a && !state.sharing) return renderResume(main, a);
      return renderStartForm(main);
    }).catch(function (e) {
      main.innerHTML = '<div style="padding:22px;max-width:640px;margin:0 auto;"><div style="background:#FEF2F2;color:#B91C1C;padding:16px;border-radius:12px;">Could not load: ' + esc(e.message || 'error') + '</div></div>';
    });
  }

  function renderLive(main, a) {
    main.innerHTML =
      '<div id="ktw-livewrap" style="padding:18px;max-width:640px;margin:0 auto;">' +
        '<div style="background:linear-gradient(135deg,#065F46,#0E9E6E);color:#fff;border-radius:18px;padding:16px 18px;box-shadow:0 8px 22px rgba(6,95,70,.28);">' +
          '<div style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;opacity:.9;"><span style="width:9px;height:9px;border-radius:50%;background:#A7F3D0;animation:ktwPulse 1.7s infinite;"></span>Live · sharing your location</div>' +
          '<div style="font-size:20px;font-weight:800;margin-top:3px;">' + esc(a.title || 'Walk / outing') + '</div>' +
          (a.destination ? '<div style="opacity:.92;">' + esc(a.destination) + '</div>' : '') +
          '<div style="display:flex;gap:20px;margin-top:12px;">' +
            '<div><div style="opacity:.85;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;">Distance</div><div id="ktw-live-dist" style="font-size:19px;font-weight:800;">' + fmtDist(a.distance_m || 0) + '</div></div>' +
            '<div><div style="opacity:.85;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;">Steps</div><div id="ktw-live-steps" style="font-size:19px;font-weight:800;">' + fmtSteps(a.steps_est || 0) + '</div></div>' +
            '<div><div style="opacity:.85;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;">Children</div><div style="font-size:19px;font-weight:800;">' + (a.children || 0) + '</div></div>' +
          '</div>' +
        '</div>' +
        '<div id="ktw-live-map" style="height:260px;margin-top:12px;border-radius:16px;overflow:hidden;border:1px solid #E2E8F0;background:#EAF2F8;"></div>' +
        '<div style="font-size:12px;color:#64748B;margin-top:6px;"><span id="ktw-live-status">' + esc(state.status || 'Getting your location…') + '</span></div>' +
        '<button id="ktw-stop-btn" style="width:100%;margin-top:12px;background:#DC2626;color:#fff;border:0;border-radius:14px;padding:15px;font-weight:800;font-size:15px;cursor:pointer;">■ Stop &amp; end walk</button>' +
        '<p style="font-size:12px;color:#64748B;line-height:1.6;margin-top:10px;">Parents of the children on this walk see this same live map. Keep the app open — sharing continues as you move around the app.</p>' +
      '</div>';
    main.querySelector('#ktw-stop-btn').onclick = confirmStop;
    var mc = main.querySelector('#ktw-live-map');
    if (state.lat != null) drawMap(mc, state.lat, state.lon, state.trail, true);
    else mc.innerHTML = '<div style="padding:26px;text-align:center;color:#64748B;">Acquiring GPS…</div>';
  }

  function renderResume(main, a) {
    main.innerHTML =
      '<div style="padding:22px;max-width:640px;margin:0 auto;">' +
        '<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:16px;padding:18px;">' +
          '<div style="font-size:16px;font-weight:800;color:#92400E;">A walk is in progress</div>' +
          '<div style="color:#92400E;margin-top:4px;">' + esc(a.title || 'Walk / outing') + (a.destination ? ' · ' + esc(a.destination) : '') + '</div>' +
          '<div style="display:flex;gap:10px;margin-top:14px;">' +
            '<button id="ktw-resume" style="flex:1;background:#065F46;color:#fff;border:0;border-radius:12px;padding:13px;font-weight:800;cursor:pointer;">▶ Resume sharing</button>' +
            '<button id="ktw-viewmap" style="flex:1;background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:12px;padding:13px;font-weight:800;cursor:pointer;">📍 View map</button>' +
            '<button id="ktw-end2" style="flex:0 0 auto;background:#F1F5F9;color:#334155;border:0;border-radius:12px;padding:13px 14px;font-weight:700;cursor:pointer;">End</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    main.querySelector('#ktw-resume').onclick = function () { startSharing(a.id); refresh(); };
    main.querySelector('#ktw-viewmap').onclick = function () { openMap(a.id, a.title || 'Walk'); };
    main.querySelector('#ktw-end2').onclick = function () { Api.post('/provider/walks/' + a.id + '/end', {}).then(function () { toast('Walk ended.', 'success'); refresh(); }); };
  }

  function renderStartForm(main) {
    var selected = {};
    main.innerHTML =
      '<div style="padding:20px;max-width:640px;margin:0 auto;">' +
        '<div style="text-align:center;margin-bottom:8px;"><div style="font-size:48px;line-height:1;">🚶</div>' +
          '<h2 style="margin:8px 0 2px;color:#0F172A;">Start a walk or outing</h2>' +
          '<p style="color:#64748B;margin:0;font-size:13.5px;line-height:1.5;">Pick who\'s coming and share your live location. Parents of the selected children can follow along on a live map.</p></div>' +
        '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:16px;padding:16px;margin-top:14px;">' +
          '<label style="display:block;font-size:13px;font-weight:800;color:#334155;">Where to? <span style="color:#DC2626;">*</span>' +
            '<input id="ktw-dest" placeholder="e.g. Neighbourhood park" style="width:100%;box-sizing:border-box;margin-top:5px;padding:11px;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;"></label>' +
          '<label style="display:block;font-size:13px;font-weight:800;color:#334155;margin-top:12px;">What is it? <span style="font-weight:500;color:#94A3B8;">(optional)</span>' +
            '<input id="ktw-title" placeholder="Walk / outing" style="width:100%;box-sizing:border-box;margin-top:5px;padding:11px;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;"></label>' +
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;">' +
            '<div style="font-size:13px;font-weight:800;color:#334155;">Who\'s coming? <span style="color:#DC2626;">*</span></div>' +
            '<button id="ktw-all" type="button" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;">Select all</button>' +
          '</div>' +
          '<div id="ktw-kids" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:8px;"><div style="grid-column:1/-1;color:#94A3B8;font-size:13px;padding:8px 0;">Loading children…</div></div>' +
          '<button id="ktw-start" disabled style="width:100%;margin-top:16px;background:#CBD5E1;color:#fff;border:0;border-radius:14px;padding:15px;font-weight:800;font-size:15px;cursor:not-allowed;">▶ Start walk &amp; share location</button>' +
          '<p id="ktw-hint" style="font-size:11.5px;color:#94A3B8;line-height:1.5;margin:9px 0 0;text-align:center;">Add a destination and pick at least one child to start.</p>' +
        '</div>' +
      '</div>';

    var destEl = main.querySelector('#ktw-dest'), titleEl = main.querySelector('#ktw-title'), startBtn = main.querySelector('#ktw-start'), kidsEl = main.querySelector('#ktw-kids');
    function validate() {
      var ok = destEl.value.trim() && Object.keys(selected).length > 0;
      startBtn.disabled = !ok;
      startBtn.style.background = ok ? '#065F46' : '#CBD5E1';
      startBtn.style.cursor = ok ? 'pointer' : 'not-allowed';
      var n = Object.keys(selected).length;
      main.querySelector('#ktw-hint').textContent = ok ? (n + ' child' + (n === 1 ? '' : 'ren') + ' selected — ready to go.') : 'Add a destination and pick at least one child to start.';
    }
    destEl.addEventListener('input', validate);

    Api.get('/provider/walks/eligible-children').then(function (r) {
      var kids = (r && r.children) || [];
      if (!kids.length) {
        kidsEl.innerHTML = '<div style="grid-column:1/-1;color:#94A3B8;font-size:13px;padding:8px 0;">No children are checked in right now. Check a child in first, then start the walk.</div>';
        return;
      }
      kidsEl.innerHTML = kids.map(function (c) {
        return '<div class="ktw-chip" data-id="' + c.id + '"><span class="ktw-tick">✓</span>' + childDot(c, 46) + '<span class="ktw-nm">' + esc((c.name || '').split(' ')[0]) + '</span></div>';
      }).join('');
      kidsEl.querySelectorAll('.ktw-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
          var id = chip.getAttribute('data-id');
          if (selected[id]) { delete selected[id]; chip.classList.remove('on'); }
          else { selected[id] = 1; chip.classList.add('on'); }
          validate();
        });
      });
      main.querySelector('#ktw-all').addEventListener('click', function () {
        var chips = kidsEl.querySelectorAll('.ktw-chip'); var allOn = Object.keys(selected).length === chips.length;
        chips.forEach(function (chip) { var id = chip.getAttribute('data-id'); if (allOn) { delete selected[id]; chip.classList.remove('on'); } else { selected[id] = 1; chip.classList.add('on'); } });
        validate();
      });
    }).catch(function () { kidsEl.innerHTML = '<div style="grid-column:1/-1;color:#DC2626;font-size:13px;">Could not load children.</div>'; });

    startBtn.onclick = function () {
      if (startBtn.disabled) return;
      startBtn.disabled = true; startBtn.textContent = 'Starting…';
      Api.post('/provider/walks/start', { title: titleEl.value.trim(), destination: destEl.value.trim(), child_ids: Object.keys(selected).map(Number) })
        .then(function (res) { startSharing(res.id); toast('Walk started · ' + (res.children || 0) + ' child' + (res.children === 1 ? '' : 'ren') + ' — parents can watch', 'success'); refresh(); })
        .catch(function (e) { startBtn.disabled = false; startBtn.textContent = '▶ Start walk & share location'; toast((e && e.message) || 'Could not start walk.', 'error'); });
    };
  }

  // ─── Parent banner — live walks their children are on ────────────────
  function isGuardian() {
    if (/\brole-guardian\b/.test(document.body.className)) return true;
    try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); return ((u.roles || []).indexOf('guardian') !== -1) || u.role === 'guardian'; } catch (e) { return false; }
  }
  function pollParentWalks() {
    if (!isGuardian()) return;
    Api.get('/parent/active-walks').then(function (r) {
      var walks = (r && r.walks) || [], host = document.getElementById('ktw-parent-banner');
      if (!walks.length) { if (host) host.remove(); return; }
      if (!host) { host = document.createElement('div'); host.id = 'ktw-parent-banner'; host.style.cssText = 'position:fixed;left:8px;right:8px;top:calc(8px + env(safe-area-inset-top,0px));z-index:99990;display:flex;flex-direction:column;gap:8px;pointer-events:none;'; document.body.appendChild(host); }
      host.innerHTML = walks.map(function (w) {
        return '<button data-trip="' + w.trip_id + '" data-title="' + esc((w.child_name || 'Your child') + ' — ' + (w.title || 'walk')) + '" style="pointer-events:auto;text-align:left;background:linear-gradient(135deg,#065F46,#0E9E6E);color:#fff;border:0;border-radius:14px;padding:11px 14px;box-shadow:0 8px 22px rgba(6,95,70,.32);display:flex;align-items:center;gap:11px;cursor:pointer;">' +
          '<span style="font-size:24px;">🚶</span><span style="flex:1;min-width:0;"><span style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;opacity:.85;">Live walk in progress</span>' +
          '<span style="display:block;font-weight:800;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(w.child_name || 'Your child') + ' is out' + (w.destination ? ' · ' + esc(w.destination) : '') + '</span></span>' +
          '<span style="background:rgba(255,255,255,.2);border-radius:16px;padding:6px 12px;font-weight:800;font-size:12.5px;white-space:nowrap;">See map ›</span></button>';
      }).join('');
      host.querySelectorAll('button[data-trip]').forEach(function (b) { b.onclick = function () { openMap(+b.getAttribute('data-trip'), b.getAttribute('data-title')); }; });
    }).catch(function () {});
  }

  // ─── Parent "Walks" screen — live + recent walks their children were on ──
  function renderParentWalks(main) {
    main.setAttribute('data-kt-pretty', '1');
    injectCss();
    main.innerHTML = '<div style="padding:20px;max-width:640px;margin:0 auto;color:#64748B;">Loading walks…</div>';
    Api.get('/parent/walks').then(function (r) {
      var walks = (r && r.walks) || [];
      var live = walks.filter(function (w) { return w.status === 'active'; });
      var past = walks.filter(function (w) { return w.status !== 'active'; });
      var row = function (w) {
        var isLive = w.status === 'active';
        return '<div style="border:1px solid ' + (isLive ? '#A7F3D0' : '#E5E7EB') + ';background:' + (isLive ? '#ECFDF5' : '#fff') + ';border-radius:14px;padding:14px;margin-bottom:10px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">' +
          '<div style="flex:1;min-width:0;"><div style="font-weight:800;color:#0F172A;">🚶 ' + esc(w.title || 'Walk') + ' ' + (isLive ? '<span style="font-size:10.5px;font-weight:800;color:#065F46;background:#D1FAE5;border-radius:999px;padding:2px 9px;">● LIVE</span>' : '') + '</div>' +
          '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(w.child_name || '') + ' · ' + esc(w.destination || '') + ' · ' + esc(w.trip_date || '') + '</div>' +
          '<div style="font-size:12px;color:#334155;margin-top:3px;font-weight:700;">' + fmtDist(w.distance_m) + ' · ' + fmtSteps(w.steps_est) + ' steps · ' + fmtDur(w.duration_min) + '</div></div>' +
          (w.has_location ? '<button class="kt-pw-map" data-id="' + w.trip_id + '" data-t="' + esc((w.child_name || '') + ' — ' + (w.title || 'walk')) + '" style="background:' + (isLive ? '#065F46' : '#EFF6FF') + ';color:' + (isLive ? '#fff' : '#1E40AF') + ';border:' + (isLive ? '0' : '1px solid #BFDBFE') + ';border-radius:10px;padding:9px 13px;font-weight:800;font-size:12.5px;cursor:pointer;white-space:nowrap;">📍 ' + (isLive ? 'Live map' : 'View route') + '</button>' : '<span style="font-size:11.5px;color:#94A3B8;">No location</span>') +
          '</div>';
      };
      main.innerHTML = '<div style="padding:18px;max-width:640px;margin:0 auto;">' +
        '<div style="text-align:center;margin-bottom:12px;"><div style="font-size:44px;line-height:1;">🚶</div><h2 style="margin:6px 0 2px;color:#0F172A;">Walks &amp; outings</h2><p style="color:#64748B;margin:0;font-size:13.5px;">Follow your child\'s live location while they\'re out — plus their recent walks.</p></div>' +
        (live.length ? '<div style="font-size:12px;font-weight:800;color:#065F46;text-transform:uppercase;letter-spacing:.5px;margin:6px 2px;">Live now</div>' + live.map(row).join('') : '') +
        (past.length ? '<div style="font-size:12px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin:14px 2px 6px;">Recent (last 30 days)</div>' + past.map(row).join('') : '') +
        (!walks.length ? '<div style="text-align:center;color:#94A3B8;padding:30px;background:#F8FAFC;border-radius:14px;">No walks yet. When an educator takes your child on a walk or outing, it appears here with a live map.</div>' : '') +
        '</div>';
      main.querySelectorAll('.kt-pw-map').forEach(function (b) { b.onclick = function () { openMap(+b.getAttribute('data-id'), b.getAttribute('data-t')); }; });
    }).catch(function (e) { main.innerHTML = '<div style="padding:20px;max-width:640px;margin:0 auto;"><div style="background:#FEF2F2;color:#B91C1C;padding:16px;border-radius:12px;">Could not load walks: ' + esc(e.message || 'error') + '</div></div>'; });
  }

  function init() {
    if (Shell && Shell.registerScreen) {
      ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (role) { Shell.registerScreen(role + ':walk', renderWalkScreen); });
      Shell.registerScreen('guardian:walks', renderParentWalks);
    }
    setTimeout(pollParentWalks, 4000);
    setInterval(pollParentWalks, 45000);
  }

  KT.WalkTracker = { openMap: openMap, mountLiveMap: mountLiveMap, renderWalkScreen: renderWalkScreen, renderParentWalks: renderParentWalks };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window);
