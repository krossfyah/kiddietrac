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
  var state = { tripId: null, watchId: null, pings: 0, lastPingAt: 0, sharing: false, lat: null, lon: null, lastAcc: null, status: '', trail: [], map: null, marker: null, accCircle: null, path: null,
    queue: [], flushing: false, lastFixAt: 0, startedAt: 0, nextAskAt: 0, dog: null,
    src: null, srcSince: 0, gotFix: false, nativeOff: false, dist: null, perm: null, nativeTimer: null, told: {} };

  /* SURVIVING A LOST CONNECTION (2026-10-28, Anthony: "with educators on a walk and for whatever reason it loses
     connection can you resume and make sure educator is prompted if walk is over (this needs to be handled better)").
     Before: whether this phone was sharing lived only in memory, so a reload, a crash or Android killing the app in
     the background ended the sharing silently while the walk stayed "live" for parents; a ping that failed offline
     was dropped; and a GPS watch that died in the background was never restarted. Now:
       - this phone remembers the walk it is sharing (localStorage LIVE_KEY) and picks it back up on its own;
       - every fix is QUEUED with the time it was taken and sent in batches - offline fixes wait and go when the
         connection is back, so the route has no hole (/field-trips/{id}/ping takes points[]);
       - a watchdog restarts GPS when fixes stop, and on coming back to the foreground or online;
       - a walk the server says has ended (409) stops sharing here;
       - any screen, not only #walk, asks "your walk is still running - resume or end?" when this phone is not
         sharing it, and a long walk asks "still out?" now and then. walks:nudge-quiet reaches the educator by
         push when the app is closed. */
  var LIVE_KEY = 'kt_walk_live', QUEUE_KEY = 'kt_walk_queue', ASK_KEY = 'kt_walk_resume_asked';
  /* The native watcher's id, kept across page reloads (2026-10-31). The native location service lives in the app, not
     the page: a reload mid-walk (an update, a crash, a refresh) lost the id, nothing could stop that watcher, and its
     "Recording this walk" notification stayed after the walk had ended. A page that boots removes any watcher a
     previous page left behind; sharing (or resuming) starts a fresh one. */
  var BGW_KEY = 'kt_walk_bgw';
  function nativePlugin() { try { var C = window.Capacitor; return (C && C.Plugins && C.Plugins.BackgroundGeolocation) || null; } catch (e) { return null; } }
  /* RESTART-PROOF WALKS (2026-10-31, app 3.12.11+). The app remembers the running walk (walk id + this sign-in) so
     that after a phone restart it can keep recording (location "all the time") or post "Your walk is still active -
     tap to resume tracking". Disarmed when the walk ends here or elsewhere. */
  function nativeWalk(op, trip) {
    try {
      var C = window.Capacitor, b = C && C.Plugins && C.Plugins.KtBio;
      if (!b || typeof b.walkArm !== 'function') return;
      if (op === 'arm') {
        var tok = null, ag = null;
        try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
        try { ag = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id'); } catch (e) {}
        if (!tok || !trip) return;
        b.walkArm({ trip: Number(trip), token: tok, agency: ag ? String(ag) : null, api: (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1', title: '' }).catch(function () {});
      } else {
        b.walkDisarm().catch(function () {});
      }
    } catch (e) {}
  }
  function forgetWatcher(id) {
    var p = nativePlugin(); if (p && id) { try { p.removeWatcher({ id: id }); } catch (e) {} }
    if (!id || lsGet(BGW_KEY) === id) lsSet(BGW_KEY, null);
  }
  var ASK_AFTER_MIN = 90, ASK_EVERY_MIN = 45;
  function lsGet(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function lsSet(k, v) { try { if (v == null) { localStorage.removeItem(k); } else { localStorage.setItem(k, JSON.stringify(v)); } } catch (e) {} }
  function saveQueue() { lsSet(QUEUE_KEY, state.queue.length ? { trip: state.tripId, points: state.queue.slice(-500) } : null); }
  function isProviderRole() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var va = sessionStorage.getItem('kt_view_as');
      if (va) { return ['educator', 'home_visitor', 'centre_director'].indexOf(va) !== -1; }
      return (u.roles || []).some(function (r) { return ['educator', 'home_visitor', 'centre_director', 'agency_admin'].indexOf(r) !== -1; });
    } catch (e) { return false; }
  }

  // ─── Leaflet loader (same CDN the provider map uses) ─────────────────
  /* SELF-HOSTED (2026-11-10, ticket #246): Leaflet 1.9.4 is served from our own domain (js/vendor/leaflet-1.9.4,
     byte-identical to the npm package, checksum-verified). It used to come from unpkg.com, and when that request
     failed on an educator's phone every map in the session was dead. A failed load is no longer cached either:
     the next call tries again. */
  var LEAFLET_CSS = '/js/vendor/leaflet-1.9.4/leaflet.css';
  var LEAFLET_JS = '/js/vendor/leaflet-1.9.4/leaflet.js';
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
      s.onerror = function () { _leafletP = null; s.remove(); rej(new Error('Could not load the map.')); };
      document.head.appendChild(s);
    });
    return _leafletP;
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, kind) { if (KT.toast) { KT.toast(msg, kind); } }
  function fmtClock(d) { try { return new Date(d).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; } }
  function fmtDist(m) { if (m == null) return '—'; return ((m) / 1000).toFixed(2) + ' km (' + Math.round(m).toLocaleString() + ' m)'; }
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
      '#ktw-pill{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(78px + var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)));z-index:99998;display:flex;align-items:center;gap:11px;padding:8px 8px 8px 14px;border-radius:999px;background:rgba(15,23,42,.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);box-shadow:0 10px 30px rgba(0,0,0,.35);color:#fff;font-size:13px;font-weight:700;width:max-content;max-width:calc(100vw - 20px);box-sizing:border-box;}' +
      '#ktw-pill .ktw-live{display:flex;align-items:center;gap:7px;white-space:nowrap;flex:1 1 auto;min-width:0;overflow:hidden;}' +
      '#ktw-pill .ktw-live > span{min-width:0;overflow:hidden;text-overflow:ellipsis;}' +
      '#ktw-pill .ktw-live b{flex:0 0 auto;}' +
      '#ktw-pill .ktw-live b{width:9px;height:9px;border-radius:50%;background:#34D399;animation:ktwPulse 1.7s infinite;}' +
      '#ktw-pill .ktw-sub{opacity:.72;font-weight:600;font-size:11.5px;}' +
      '#ktw-pill button{border:0;cursor:pointer;font-weight:800;border-radius:999px;font-size:12.5px;flex:0 0 auto;white-space:nowrap;}' +
      '@media (max-width:440px){#ktw-pill{gap:7px;padding:6px 6px 6px 11px;font-size:12.5px;}#ktw-pill .ktw-map{padding:7px 10px;}#ktw-pill .ktw-stop{padding:7px 12px;}}' +
      /* While the bar shows, the page keeps room for it at the bottom, so it never covers the last lines. */
      'body.ktw-pill-on #appMain::after{content:"";display:block;height:calc(84px + var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)));}' +
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
    if (!state.sharing) { if (pill) pill.remove(); document.body.classList.remove('ktw-pill-on'); return; }
    if (!pill) { pill = document.createElement('div'); pill.id = 'ktw-pill'; document.body.appendChild(pill); }
    document.body.classList.add('ktw-pill-on');
    var sub = state.pings > 0
      ? (fmtDist(walkDist()) + (state.lastAcc != null ? ' · ±' + Math.round(state.lastAcc) + 'm' : ''))
      : (state.status || 'Getting your location…');
    pill.innerHTML =
      '<img src="/img/walk-watch.svg" alt="" width="20" height="20" style="display:inline-block;vertical-align:middle;border-radius:5px;flex:0 0 auto;margin-right:-4px;">' +
      '<span class="ktw-live"><b></b><span>Live<span class="ktw-sub"> · ' + esc(sub) + '</span></span></span>' +
      '<button class="ktw-map" id="ktw-pill-map">Map</button>' +
      (KT.Headcount ? '<button class="ktw-map" id="ktw-pill-hc" title="Headcount of the children on this walk">\ud83d\udc40 Count</button>' : '') +
      '<button class="ktw-stop" id="ktw-pill-stop">Stop</button>';
    pill.querySelector('#ktw-pill-map').onclick = function () { openMap(state.tripId, 'Your walk'); };
    // Headcount of the children on the walk (2026-10-13, screen-headcounts.js).
    var _hc = pill.querySelector('#ktw-pill-hc');
    if (_hc) _hc.onclick = function () { KT.Headcount.open({ trip_id: state.tripId, context: 'field_trip' }); };
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
    }).catch(function () {
      if (!container._ktmap && container.isConnected) {
        container.innerHTML = '<div style="padding:22px;text-align:center;color:#64748B;font-size:13px;line-height:1.5;">The map could not load on this connection. '
          + 'Your walk and location sharing carry on; the map will try again.</div>';
      }
      return null;
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

  // ─── Completed-walk ROUTE map: the journey out AND back ──────────────
  // A finished walk is one continuous track, so "there" and "back" are
  // indistinguishable on a single-colour line. Split it at the point furthest from
  // the start — the turnaround, i.e. the destination — and draw the outbound leg
  // solid teal and the return leg dashed violet, with start / destination / end
  // pins. A parent can then see where their child actually went and that they came
  // back, not just a total distance.
  function splitLegs(pts) {
    if (pts.length < 3) return { out: pts.slice(), back: [], turnIdx: Math.max(0, pts.length - 1), spreadM: 0 };
    var start = pts[0], best = 0, bestD = -1;
    for (var i = 1; i < pts.length; i++) {
      var d = haversine(start, pts[i]);
      if (d > bestD) { bestD = d; best = i; }
    }
    // slice(best) so the two legs share the turnaround point and the line is unbroken
    return { out: pts.slice(0, best + 1), back: pts.slice(best), turnIdx: best, spreadM: bestD };
  }

  function pin(L, emoji, tint) {
    return L.divIcon({
      className: '', iconSize: [26, 26], iconAnchor: [13, 13],
      html: '<div style="width:26px;height:26px;border-radius:50%;background:' + tint + ';border:2px solid #fff;box-shadow:0 2px 6px rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;font-size:13px;">' + emoji + '</div>',
    });
  }

  function drawRouteMap(container, pts) {
    return loadLeaflet().then(function (L) {
      var legs = splitLegs(pts);
      var m = container._ktmap;
      if (!m) {
        m = L.map(container, { zoomControl: true, attributionControl: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(m);
        container._ktmap = m;
      }
      if (legs.out.length > 1) L.polyline(legs.out, { color: '#0E9E6E', weight: 5, opacity: .85 }).addTo(m);
      if (legs.back.length > 1) L.polyline(legs.back, { color: '#7C3AED', weight: 4, opacity: .85, dashArray: '7,7' }).addTo(m);
      L.marker(pts[0], { icon: pin(L, '🏫', '#0E7C90') }).addTo(m).bindTooltip('Set off from here');
      if (legs.spreadM > 25) L.marker(pts[legs.turnIdx], { icon: pin(L, '📍', '#F97316') }).addTo(m).bindTooltip('Furthest point');
      L.marker(pts[pts.length - 1], { icon: pin(L, '🏁', '#7C3AED') }).addTo(m).bindTooltip('Back here');
      try {
        if (legs.spreadM > 25) {
          m.fitBounds(L.latLngBounds(pts).pad(0.25), { maxZoom: 18 });
        } else {
          // A short pottering-about walk collapses to ~one point. fitBounds would
          // slam to max zoom on a single GPS dot, which renders as a blank beige
          // square (no OSM detail exists at z19 on most streets). Pin a sane
          // neighbourhood zoom instead so the surrounding streets are visible.
          m.setView(pts[0], 17);
        }
      } catch (e) { m.setView(pts[0], 17); }
      setTimeout(function () { try { m.invalidateSize(); } catch (e) {} }, 180);
      return { map: m, legs: legs };
    });
  }

  /** Static route map for a finished walk: fetches once, draws there-and-back. */
  function mountRouteMap(container, tripId) {
    container.innerHTML = '<div style="padding:22px;text-align:center;color:#64748B;font-size:13px;">Loading route…</div>';
    return Api.get('/field-trips/' + tripId + '/location').then(function (r) {
      var raw = (r && r.trail) || [];
      var pts = raw.map(function (p) { return [parseFloat(p.lat), parseFloat(p.lon)]; })
                   .filter(function (p) { return !isNaN(p[0]) && !isNaN(p[1]); });
      if (!pts.length) {
        container.innerHTML = '<div style="background:#F8FAFC;color:#64748B;padding:16px;border-radius:12px;font-size:13px;">No location was recorded for this walk.</div>';
        return null;
      }
      container.innerHTML = '';
      return drawRouteMap(container, pts).then(function (res) {
        var legs = res.legs, times = raw.map(function (p) { return Date.parse(String(p.recorded_at || '').replace(' ', 'T')); });
        var tOut = times[legs.turnIdx] - times[0], tBack = times[times.length - 1] - times[legs.turnIdx];
        var mins = function (ms) { return (!ms || isNaN(ms) || ms < 0) ? null : Math.max(1, Math.round(ms / 60000)) + ' min'; };
        var legend = document.createElement('div');
        legend.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:9px 4px 2px;font-size:12px;color:#475569;';
        legend.innerHTML =
          '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:18px;height:4px;border-radius:2px;background:#0E9E6E;display:inline-block;"></span>There' + (mins(tOut) ? ' · ' + mins(tOut) : '') + '</span>' +
          '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:18px;height:0;border-top:3px dashed #7C3AED;display:inline-block;"></span>Back' + (mins(tBack) ? ' · ' + mins(tBack) : '') + '</span>' +
          (legs.spreadM > 25
            ? '<span style="color:#64748B;">📍 ' + fmtDist(Math.round(legs.spreadM)) + ' from the start at the furthest point</span>'
            : '<span style="color:#64748B;">Stayed close to the centre the whole time</span>');
        if (container.parentNode) container.parentNode.insertBefore(legend, container.nextSibling);
        return res;
      });
    }).catch(function (e) {
      container.innerHTML = '<div style="background:#FEF2F2;color:#B91C1C;padding:14px;border-radius:12px;font-size:13px;">Could not load the route: ' + esc((e && e.message) || 'error') + '</div>';
      return null;
    });
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
    if (!state.gotFix) { state.gotFix = true; gpsDiag('first_fix', { acc: pos.coords && pos.coords.accuracy != null ? Math.round(pos.coords.accuracy) : null }); }
    if (state.nativeTimer) { clearTimeout(state.nativeTimer); state.nativeTimer = null; }
    state.lastFixAt = Date.now();
    state.lat = pos.coords.latitude; state.lon = pos.coords.longitude; state.lastAcc = pos.coords.accuracy; state.lastErr = null;
    state.trail.push([state.lat, state.lon]);
    if (state.trail.length > 400) state.trail.shift();
    // Move the educator's own inline map immediately.
    var mc = document.getElementById('ktw-live-map'); if (mc) drawMap(mc, state.lat, state.lon, state.trail, true);
    var now = Date.now();
    if (now - state.lastPingAt < PING_EVERY_MS) { setStatus('Located · ±' + Math.round(state.lastAcc) + 'm'); return; }
    state.lastPingAt = now;
    // Queued with the time it was taken; flush() sends whatever is waiting, now or when the connection is back.
    state.queue.push({
      lat: +state.lat.toFixed(6), lon: +state.lon.toFixed(6),
      accuracy_m: state.lastAcc != null ? Math.round(state.lastAcc) : null,
      speed_mps: (pos.coords.speed != null && !isNaN(pos.coords.speed)) ? pos.coords.speed : null,
      recorded_at: new Date(now).toISOString()
    });
    if (state.queue.length > 500) { state.queue.splice(0, state.queue.length - 500); }
    saveQueue();
    flush();
    maybeAskStillOut();
  }

  function flush() {
    if (state.flushing || !state.tripId || !state.queue.length) { return Promise.resolve(); }
    if (navigator.onLine === false) { setStatus('Offline · ' + state.queue.length + ' location' + (state.queue.length === 1 ? '' : 's') + ' saved, sending when back online'); return Promise.resolve(); }
    state.flushing = true;
    var batch = state.queue.slice(0, 300), trip = state.tripId;
    return Api.post('/field-trips/' + trip + '/ping', { points: batch }).then(function () {
      state.queue.splice(0, batch.length); saveQueue();
      state.pings += batch.length; state.flushing = false;
      setStatus('Sharing · ' + state.pings + ' sent');
      if (state.queue.length) { return flush(); }
    }, function (e) {
      state.flushing = false;
      var st = e && (e.status || (e.response && e.response.status));
      if (st === 409 || /ended/i.test(String(e && e.message))) { endedElsewhere(); return; }
      setStatus('Connection lost · ' + state.queue.length + ' location' + (state.queue.length === 1 ? '' : 's') + ' saved, retrying');
    });
  }

  /* The centre (or the overnight clean-up) already ended this walk: stop sharing here, quietly. */
  function endedElsewhere() {
    lsSet(LIVE_KEY, null); state.queue = []; saveQueue(); nativeWalk('disarm');
    if (state.sharing) { stopSharing(true); toast('This walk has already ended — location sharing stopped.', 'success'); }
  }

  function restartWatch() {
    if (!state.sharing) { return; }
    if (state.watchId != null && navigator.geolocation) { try { navigator.geolocation.clearWatch(state.watchId); } catch (e) {} state.watchId = null; }
    if (state.bgWatchId && state.bgWatchId !== 'pending') { forgetWatcher(state.bgWatchId); state.bgWatchId = null; }
    beginWatch();
  }
  /* GPS stops when the app is backgrounded on many phones and does not always come back by itself. */
  function watchdog() {
    if (!state.sharing) { return; }
    if (state.lastFixAt && Date.now() - state.lastFixAt > 75000) { gpsDiag(state.gotFix ? 'fixes_stopped' : 'no_fix_75s'); setStatus('GPS paused · restarting'); restartWatch(); }
    flush();
    maybeAskStillOut();
  }

  /* A long walk asks now and then. Never ends anything by itself: only the educator knows the children are back. */
  function maybeAskStillOut() {
    if (!state.sharing || !state.startedAt || document.hidden) { return; }
    var mins = (Date.now() - state.startedAt) / 60000;
    if (mins < ASK_AFTER_MIN || Date.now() < state.nextAskAt) { return; }
    state.nextAskAt = Date.now() + ASK_EVERY_MIN * 60000;
    sheet('🚶 Still on your walk?', 'Your walk has been going for ' + Math.round(mins) + ' minutes. If everyone is back, end it so parents and your director can see the group has returned.',
      [['Still out', null], ['End the walk', function () { stopSharing(false); toast('Walk ended — sharing stopped.', 'success'); }]]);
  }

  /* The prompt card used by this file (same look as Walk auto-detect's). buttons: [[label, fn|null], ...] - last is primary. */
  function sheet(title, text, buttons) {
    if (document.getElementById('ktw-sheet')) { return; }
    var scrim = document.createElement('div'); scrim.id = 'ktw-sheet';
    scrim.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:100001;display:flex;align-items:flex-end;justify-content:center;padding:16px;';
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:18px;max-width:460px;width:100%;padding:20px 22px;box-shadow:0 20px 50px rgba(15,23,42,.3);';
    card.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;"><img src="/img/walk-watch.svg" alt="" width="30" height="30" style="border-radius:8px;flex:0 0 auto;">'
      + '<div style="font-size:15px;font-weight:800;color:#0F172A;">' + esc(title) + '</div></div>'
      + '<div style="color:#475569;font-size:13.5px;line-height:1.5;">' + esc(text) + '</div>'
      + '<div class="ktw-sheet-btns" style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;"></div>';
    var row = card.querySelector('.ktw-sheet-btns');
    buttons.forEach(function (b, i) {
      var primary = i === buttons.length - 1;
      var el = document.createElement('button'); el.type = 'button'; el.textContent = b[0];
      el.style.cssText = 'flex:1;min-width:120px;padding:11px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;'
        + (primary ? 'border:0;background:#065F46;color:#fff;' : 'border:1px solid #CBD5E1;background:#fff;color:#0F172A;');
      el.addEventListener('click', function () { try { document.body.removeChild(scrim); } catch (e) {} if (b[1]) { b[1](); } });
      row.appendChild(el);
    });
    scrim.appendChild(card);
    document.body.appendChild(scrim);
  }

  /* On boot, on coming back to the app and on reconnecting: is a walk of mine still running? */
  function checkActiveWalk() {
    if (!isProviderRole() || document.hidden) { return; }
    Api.get('/provider/walks/active').then(function (r) {
      var a = r && r.active, live = lsGet(LIVE_KEY);
      if (!a) {
        if (live || state.sharing) { endedElsewhere(); }
        return;
      }
      if (state.sharing && state.tripId === a.id) { restartWatchIfStale(); flush(); return; }
      if (live && live.trip === a.id) {
        // This phone was sharing this walk before the app restarted or lost its connection: pick it back up.
        startSharing(a.id, true);
        toast('Walk Watch resumed — still sharing your location for ' + (a.destination || a.title || 'your walk') + '.', 'success');
        return;
      }
      var asked = null; try { asked = sessionStorage.getItem(ASK_KEY); } catch (e) {}
      if (asked === String(a.id)) { return; }
      try { sessionStorage.setItem(ASK_KEY, String(a.id)); } catch (e) {}
      sheet('🚶 Your walk is still running',
        (a.destination ? 'To ' + a.destination + ' · ' : '') + 'started ' + (a.minutes_out != null ? a.minutes_out + ' minutes ago' : 'earlier')
          + '. This phone is not sharing its location. Resume sharing, or end the walk if everyone is back.',
        [['End the walk', function () {
            Api.post('/provider/walks/' + a.id + '/end', {}).then(function () { lsSet(LIVE_KEY, null); toast('Walk ended.', 'success'); refresh(); },
              function (e) { toast((e && e.message) || 'Could not end the walk.', 'error'); });
          }],
         ['Resume sharing', function () { startSharing(a.id, true); refresh(); }]]);
    }).catch(function () { /* offline: try again when the connection is back */ });
  }
  function restartWatchIfStale() { if (state.lastFixAt && Date.now() - state.lastFixAt > 75000) { restartWatch(); } }
  function onGeoErr(err) {
    var code = err && err.code;
    gpsDiag(code === 1 ? 'permission_denied' : 'web_error', { code: code, msg: err && err.message });
    if (code === 1) { toast('Location is off. Allow location for KiddieTrac in Settings, then start again.', 'error'); stopSharing(true); setStatus('Permission denied'); return; }
    setStatus(code === 3 ? 'Waiting for GPS signal…' : 'No GPS fix yet');
  }
  function platformName() { try { var C = window.Capacitor; return (C && C.getPlatform) ? C.getPlatform() : 'web'; } catch (e) { return 'web'; } }
  function iosBgPlugin() {
    var C = window.Capacitor;
    try { if (!(C && C.isNativePlatform && C.isNativePlatform() && C.getPlatform && /^(ios|android)$/.test(C.getPlatform()))) return null; } catch (e) { return null; }
    if (state.nativeOff) return null;
    /* The Google Play build removed the plugin's location service until 3.12.8 (src/play/AndroidManifest.xml): there
       the plugin loads but can never start ("Service not running."). Android uses it only on a build that has the
       service: the sideload build, or a Play build whose KtBio.dist() reports bgWalks (read from the installed
       package, 2026-10-30). The 20-second no-fix fallback to the WebView GPS stays either way. */
    if (C.getPlatform() === 'android' && state.dist !== 'sideload' && state.bgWalks !== true) return null;
    var p = C.Plugins && C.Plugins.BackgroundGeolocation;
    return (p && typeof p.addWatcher === 'function') ? p : null;
  }
  /* Older apps have no KtBio.dist() and are sideload builds (same rule as kt-walk-autodetect.js). */
  function appDist() {
    if (state.dist) return Promise.resolve(state.dist);
    try {
      var bio = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.KtBio;
      if (!bio || typeof bio.dist !== 'function') return Promise.resolve(state.dist = 'sideload');
      return bio.dist().then(function (r) { state.bgWalks = !!(r && r.bgWalks === true); return (state.dist = (r && r.dist) || 'sideload'); }, function () { return (state.dist = 'sideload'); });
    } catch (e) { return Promise.resolve(state.dist = 'sideload'); }
  }
  /* The native watcher failed or stayed silent: use the WebView's GPS for the rest of this walk. */
  function nativeFallback(why, extra) {
    if (!state.sharing || state.nativeOff) return;
    state.nativeOff = true;
    if (state.nativeTimer) { clearTimeout(state.nativeTimer); state.nativeTimer = null; }
    if (state.bgWatchId && state.bgWatchId !== 'pending') forgetWatcher(state.bgWatchId);
    state.bgWatchId = null;
    gpsDiag(why, extra);
    beginWatch();
  }
  /* GPS trouble (and the first fix) reaches the server once per walk and kind, so an empty route explains itself. */
  function gpsDiag(ev, extra) {
    try {
      if (!state.tripId || state.told[ev]) return;
      state.told[ev] = 1;
      var x = extra || {};
      var parts = ['ev=' + ev, 'src=' + (state.src || '-'), 'plat=' + platformName(), 'dist=' + (state.dist || '-') + (state.bgWalks ? '+bg' : ''), 'perm=' + (state.perm == null ? '-' : state.perm),
        'nofix_s=' + (state.srcSince ? Math.round((Date.now() - state.srcSince) / 1000) : '-'), 'vis=' + (document.hidden ? 'hidden' : 'visible')];
      Object.keys(x).forEach(function (k) { if (x[k] != null && x[k] !== '') parts.push(k + '=' + String(x[k]).replace(/\s+/g, ' ').slice(0, 80)); });
      var tok = ''; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
      var ag = ''; try { ag = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
      var h = { 'Content-Type': 'application/json', Accept: 'application/json' };
      if (tok) h.Authorization = 'Bearer ' + tok;
      if (ag) h['X-Active-Agency-Id'] = ag;
      fetch(((KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1') + '/diag/client-error', {
        method: 'POST', headers: h, keepalive: true,
        body: JSON.stringify({ status: 0, kind: 'walk_gps', path: '/field-trips/' + state.tripId, ms: state.startedAt ? Math.max(0, Date.now() - state.startedAt) : null, text: parts.join(' ').slice(0, 300) })
      }).catch(function () {});
    } catch (e) {}
  }
  function beginNativeWatch(plugin) {
    setStatus('Getting your location…');
    /* ONE native watcher at a time (2026-10-31). A resume while already sharing started a second watcher and dropped
       the first's id, so ending the walk stopped only one and the "Recording this walk" notification stayed. */
    if (state.bgWatchId && state.bgWatchId !== 'pending') forgetWatcher(state.bgWatchId);
    var saved = lsGet(BGW_KEY); if (saved) forgetWatcher(saved);
    state.bgWatchId = 'pending';
    state.src = 'native'; state.srcSince = Date.now();
    if (state.nativeTimer) { clearTimeout(state.nativeTimer); }
    state.nativeTimer = setTimeout(function () { state.nativeTimer = null; if (state.sharing && !state.gotFix && state.src === 'native') nativeFallback('native_no_fix_20s'); }, 20000);
    try {
      /* addWatcher hands back the watcher id as a plain value in this Capacitor (a callback method), not a promise
         (2026-10-31: every walk logged "plugin.addWatcher(...).then is not a function"). The native service had
         already started, its id was lost, the walk fell back to web GPS, and the "Recording this walk" notification
         could never be removed. Promise.resolve takes either form. */
      Promise.resolve(plugin.addWatcher({
        // backgroundMessage is what turns on background updates (blue location pill) on iOS.
        backgroundMessage: 'Recording this walk so the centre knows where the group is.',
        backgroundTitle: 'KiddieTrac',
        requestPermissions: true, stale: false, distanceFilter: 10
      }, function (loc, err) {
        if (err) {
          if (err.code === 'NOT_AUTHORIZED') { gpsDiag('native_denied', { msg: err.message }); onGeoErr({ code: 1 }); return; }
          nativeFallback('native_error', { code: err.code, msg: err.message });
          return;
        }
        if (!loc) return;
        onFix({ coords: { latitude: loc.latitude, longitude: loc.longitude, accuracy: loc.accuracy, speed: loc.speed } });
      })).then(function (id) {
        if (state.sharing && state.bgWatchId === 'pending') { state.bgWatchId = id; lsSet(BGW_KEY, id); }
        else { try { plugin.removeWatcher({ id: id }); } catch (e) {} }   // stopped before it started
      }).catch(function (e) { state.bgWatchId = null; nativeFallback('native_start_failed', { msg: e && e.message }); });
    } catch (e) { state.bgWatchId = null; state.nativeOff = true; gpsDiag('native_threw', { msg: e && e.message }); return beginWatch(); }
    return true;
  }
  function beginWatch() {
    var bg = iosBgPlugin(); if (bg) return beginNativeWatch(bg);
    if (!navigator.geolocation) { toast('This device has no GPS.', 'error'); return false; }
    try {
      setStatus('Getting your location…');
      state.src = 'web'; state.srcSince = Date.now();
      navigator.geolocation.getCurrentPosition(onFix, onGeoErr, { enableHighAccuracy: false, timeout: 20000, maximumAge: 30000 });
      state.watchId = navigator.geolocation.watchPosition(onFix, onGeoErr, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
    } catch (e) { toast('Could not start GPS.', 'error'); return false; }
    return true;
  }
  function ensureNativeLocation() {
    var done, p = new Promise(function (r) { done = r; }), settled = false;
    var fin = function () { if (!settled) { settled = true; done(); } };
    setTimeout(fin, 7000);
    var got = function (r) { if (r && typeof r.granted === 'boolean') state.perm = r.granted ? 'granted' : 'denied'; fin(); };
    try { var C = window.Capacitor, bio = C && C.Plugins && C.Plugins.KtBio; if (bio && bio.requestLocation) bio.requestLocation().then(got, fin); else fin(); } catch (e) { fin(); }
    return Promise.all([p, appDist()]);
  }
  function startSharing(tripId, resumed) {
    state.tripId = tripId; state.pings = 0; state.lastPingAt = 0; state.sharing = true; state.lastAcc = null; state.trail = []; state.status = resumed ? 'Resuming…' : 'Starting…';
    state.gotFix = false; state.nativeOff = false; state.told = {}; state.src = null; state.srcSince = 0;
    // Remember it (survives a reload or the app being killed), and pick up any fixes saved while offline.
    var live = lsGet(LIVE_KEY);
    state.startedAt = (live && live.trip === tripId && live.since) ? live.since : Date.now();
    state.nextAskAt = 0;
    lsSet(LIVE_KEY, { trip: tripId, since: state.startedAt });
    nativeWalk('arm', tripId);
    var q = lsGet(QUEUE_KEY); state.queue = (q && q.trip === tripId && q.points) ? q.points : [];
    state.lastFixAt = Date.now();
    if (state.dog) { clearInterval(state.dog); }
    state.dog = setInterval(watchdog, 20000);
    renderPill();
    ensureNativeLocation().then(function () { if (!state.sharing) return; if (!beginWatch()) { state.sharing = false; renderPill(); refresh(); return; } renderPill(); });
  }
  function stopSharing(silent) {
    if (state.watchId != null && navigator.geolocation) { try { navigator.geolocation.clearWatch(state.watchId); } catch (e) {} }
    if (state.bgWatchId && state.bgWatchId !== 'pending') forgetWatcher(state.bgWatchId);
    state.bgWatchId = null;
    if (state.nativeTimer) { clearTimeout(state.nativeTimer); state.nativeTimer = null; }
    if (state.dog) { clearInterval(state.dog); state.dog = null; }
    var tid = state.tripId; state.sharing = false; state.watchId = null; renderPill();
    lsSet(LIVE_KEY, null);
    nativeWalk('disarm');
    if (tid && !silent) {
      // Send what is still queued first, so the route ends where the walk did; then end it.
      var pending = state.queue.slice(0, 300); state.queue = []; saveQueue();
      /* Redraw only AFTER the server has ended the walk (2026-10-31). refresh() ran straight away, so the screen asked
         for the active walk before /end had landed and showed "A walk is in progress - Resume sharing" for a walk
         that was already ended. */
      (pending.length ? Api.post('/field-trips/' + tid + '/ping', { points: pending }).catch(function () {}) : Promise.resolve())
        .then(function () { return Api.post('/provider/walks/' + tid + '/end', {}); }).catch(function () {})
        .then(function () { refresh(); });
    } else { state.queue = []; saveQueue(); refresh(); }
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
          '<div style="font-size:16px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:9px;min-width:0;"><img src="/img/walk-watch.svg" alt="" width="26" height="26" style="display:inline-block;vertical-align:middle;border-radius:7px;flex:0 0 auto;"><span style="min-width:0;"><span style="display:block;font-size:10.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#0E8F99;">Walk Watch</span>' + esc(title || 'Live location') + '</span></div>' +
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
          '<div style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;opacity:.9;"><span style="width:9px;height:9px;border-radius:50%;background:#A7F3D0;animation:ktwPulse 1.7s infinite;"></span><span>Walk Watch</span><span style="opacity:.7;">·</span><span>Live · sharing your location</span></div>' +
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
      '<div id="ktw-form" style="padding:20px;max-width:640px;margin:0 auto;">' +
        '<style>#ktw-form .ktw-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px 18px;max-width:980px}' +
          '#ktw-form .ktw-f{display:flex !important;flex-direction:column;gap:6px;font-size:13px;font-weight:800;color:#334155;min-width:0;margin:0}' +
          '#ktw-form .ktw-f input{width:100% !important;max-width:none !important;box-sizing:border-box;margin:0;padding:11px;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;font-weight:500}' +
          '#ktw-form .ktw-who{display:flex;align-items:center;gap:12px;margin-top:18px}' +
          '#ktw-form .ktw-act{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:18px}' +
          '#ktw-start{min-width:260px;padding:13px 28px;border:0;border-radius:12px;color:#fff;font-weight:800;font-size:15px}' +
          '#appMain #ktw-start:disabled{opacity:1 !important}' +
          '#ktw-hint{font-size:12px;color:#94A3B8;line-height:1.5;margin:0}' +
          '@media(max-width:640px){#ktw-start{width:100%}#ktw-hint{width:100%;text-align:center}}</style>' +
        '<div style="text-align:center;margin-bottom:8px;"><div style="line-height:1;"><img src="/img/walk-watch.svg" alt="" width="56" height="56" style="display:inline-block;vertical-align:middle;border-radius:15px;"></div>' +
          '<div data-kt-nosechead="1" style="margin-top:8px;font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:#0E8F99;">Walk Watch</div>' +
          '<h2 style="margin:2px 0 2px;color:#0F172A;">Start a walk or outing</h2>' +
          '<p style="color:#64748B;margin:0;font-size:13.5px;line-height:1.5;">Pick who\'s coming and share your live location. Parents of the selected children can follow along on a live map.</p></div>' +
        '<div style="background:#fff;border:1px solid #E5E7EB;border-radius:16px;padding:16px;margin-top:14px;">' +
          '<div class="ktw-row">' +
            '<label class="ktw-f"><span>Where to? <span style="color:#DC2626;">*</span></span>' +
              '<input id="ktw-dest" placeholder="e.g. Neighbourhood park"></label>' +
            '<label class="ktw-f"><span>What is it? <span style="font-weight:500;color:#94A3B8;">(optional)</span></span>' +
              '<input id="ktw-title" placeholder="Walk / outing"></label>' +
          '</div>' +
          '<div class="ktw-who">' +
            '<div style="font-size:13px;font-weight:800;color:#334155;">Who\'s coming? <span style="color:#DC2626;">*</span></div>' +
            '<button id="ktw-all" type="button" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;">Select all</button>' +
          '</div>' +
          '<div id="ktw-kids" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:8px;"><div style="grid-column:1/-1;color:#94A3B8;font-size:13px;padding:8px 0;">Loading children…</div></div>' +
          '<div class="ktw-act">' +
            '<button id="ktw-start" disabled style="background:#E2E8F0;color:#475569;cursor:not-allowed;">▶ Start walk &amp; share location</button>' +
            '<p id="ktw-hint">Add a destination and pick at least one child to start.</p>' +
          '</div>' +
        '</div>' +
      '</div>';

    var destEl = main.querySelector('#ktw-dest'), titleEl = main.querySelector('#ktw-title'), startBtn = main.querySelector('#ktw-start'), kidsEl = main.querySelector('#ktw-kids');
    function validate() {
      var ok = destEl.value.trim() && Object.keys(selected).length > 0;
      startBtn.disabled = !ok;
      // Waiting: pale slate with slate words, readable even under the portal's faded disabled look (2026-11-06; white
      // on #CBD5E1 at 55% could not be read).
      startBtn.style.background = ok ? '#065F46' : '#E2E8F0';
      startBtn.style.color = ok ? '#fff' : '#475569';
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
      if (!host) { host = document.createElement('div'); host.id = 'ktw-parent-banner'; host.style.cssText = 'position:fixed;left:8px;right:8px;top:calc(8px + var(--kt-safe-top, env(safe-area-inset-top,0px)));z-index:99990;display:flex;flex-direction:column;gap:8px;pointer-events:none;'; document.body.appendChild(host); }
      host.innerHTML = walks.map(function (w) {
        return '<button data-trip="' + w.trip_id + '" data-title="' + esc((w.child_name || 'Your child') + ' — ' + (w.title || 'walk')) + '" style="pointer-events:auto;text-align:left;background:linear-gradient(135deg,#065F46,#0E9E6E);color:#fff;border:0;border-radius:14px;padding:11px 14px;box-shadow:0 8px 22px rgba(6,95,70,.32);display:flex;align-items:center;gap:11px;cursor:pointer;">' +
          '<img src="/img/walk-watch.svg" alt="" width="30" height="30" style="display:inline-block;vertical-align:middle;border-radius:8px;flex:0 0 auto;box-shadow:0 0 0 2px rgba(255,255,255,.35);"><span style="flex:1;min-width:0;"><span style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;opacity:.85;"><span>Walk Watch</span> · <span>Live now</span></span>' +
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
          '<div style="font-size:12px;color:#334155;margin-top:3px;font-weight:700;">' + fmtDist(w.distance_m) + ' walked · ' + fmtSteps(w.steps_est) + ' steps · ' + fmtDur(w.duration_min) + '</div></div>' +
          (w.has_location ? '<button class="kt-pw-map" data-id="' + w.trip_id + '" data-live="' + (isLive ? '1' : '') + '" data-t="' + esc((w.child_name || '') + ' — ' + (w.title || 'walk')) + '" style="background:' + (isLive ? '#065F46' : '#EFF6FF') + ';color:' + (isLive ? '#fff' : '#1E40AF') + ';border:' + (isLive ? '0' : '1px solid #BFDBFE') + ';border-radius:10px;padding:9px 13px;font-weight:800;font-size:12.5px;cursor:pointer;white-space:nowrap;">📍 ' + (isLive ? 'Live map' : 'View route') + '</button>' : '<span style="font-size:11.5px;color:#94A3B8;">No location</span>') +
          '</div>' +
          // Inline route/live map, expanded in place by the button above — parents
          // asked to SEE the journey out and back, not open a separate modal.
          (w.has_location ? '<div class="kt-pw-routewrap" data-for="' + w.trip_id + '" style="display:none;margin:-4px 0 12px;"><div class="kt-pw-route" style="height:260px;border-radius:12px;overflow:hidden;background:#EEF2F7;"></div></div>' : '');
      };
      main.innerHTML = '<div style="padding:18px;max-width:640px;margin:0 auto;">' +
        '<div style="text-align:center;margin-bottom:12px;"><div style="line-height:1;"><img src="/img/walk-watch.svg" alt="" width="52" height="52" style="display:inline-block;vertical-align:middle;border-radius:14px;"></div><h2 style="margin:6px 0 2px;color:#0F172A;">Walk Watch</h2><p style="color:#64748B;margin:0;font-size:13.5px;">Follow your child\'s live location while they\'re out — plus their recent walks.</p></div>' +
        (live.length ? '<div style="font-size:12px;font-weight:800;color:#065F46;text-transform:uppercase;letter-spacing:.5px;margin:6px 2px;">Live now</div>' + live.map(row).join('') : '') +
        (past.length ? '<div style="font-size:12px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin:14px 2px 6px;">Recent (last 30 days)</div>' + past.map(row).join('') : '') +
        (!walks.length ? '<div style="text-align:center;color:#94A3B8;padding:30px;background:#F8FAFC;border-radius:14px;">No walks yet. When an educator takes your child on a walk or outing, it appears here with a live map.</div>' : '') +
        '</div>';
      main.querySelectorAll('.kt-pw-map').forEach(function (b) {
        b.onclick = function () {
          var id = +b.getAttribute('data-id'), isLive = !!b.getAttribute('data-live');
          var wrap = main.querySelector('.kt-pw-routewrap[data-for="' + id + '"]');
          if (!wrap) { openMap(id, b.getAttribute('data-t')); return; }   // fallback: modal
          var open = wrap.style.display !== 'none';
          if (open) {
            wrap.style.display = 'none';
            b.textContent = '📍 ' + (isLive ? 'Live map' : 'View route');
            if (wrap._ktstop) { wrap._ktstop(); wrap._ktstop = null; }    // stop the 12s live poll
            return;
          }
          wrap.style.display = '';
          b.textContent = '📍 Hide map';
          if (!wrap._ktloaded) {
            wrap._ktloaded = true;
            var host = wrap.querySelector('.kt-pw-route');
            if (isLive) wrap._ktstop = mountLiveMap(host, id);
            else mountRouteMap(host, id);
          }
        };
      });
    }).catch(function (e) { main.innerHTML = '<div style="padding:20px;max-width:640px;margin:0 auto;"><div style="background:#FEF2F2;color:#B91C1C;padding:16px;border-radius:12px;">Could not load walks: ' + esc(e.message || 'error') + '</div></div>'; });
  }

  function init() {
    var orphan = lsGet(BGW_KEY);
    if (orphan) forgetWatcher(orphan);
    if (!lsGet(LIVE_KEY)) nativeWalk('disarm');
    if (Shell && Shell.registerScreen) {
      ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (role) { Shell.registerScreen(role + ':walk', renderWalkScreen); });
      Shell.registerScreen('guardian:walks', renderParentWalks);
    }
    setTimeout(pollParentWalks, 4000);
    setInterval(pollParentWalks, 45000);
    // Educators: resume or ask about a running walk on boot, on return to the app, and on reconnecting.
    setTimeout(checkActiveWalk, 6000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { setTimeout(checkActiveWalk, 800); if (state.sharing) { setTimeout(watchdog, 1500); } } });
    window.addEventListener('online', function () { setTimeout(checkActiveWalk, 800); flush(); });
    window.addEventListener('offline', function () { if (state.sharing) { setStatus('Offline · locations are saved and will send when back online'); } });
  }

  KT.WalkTracker = { openMap: openMap, mountLiveMap: mountLiveMap, mountRouteMap: mountRouteMap, renderWalkScreen: renderWalkScreen, renderParentWalks: renderParentWalks };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window);
