/* ===================================================================
   KIDDIETRAC service worker v16
   v11: caching + offline; v14: + Web Push support
   v15: network-first for HTML so deploys show up without a cache trap.
   v16: on SW UPDATE, wipe caches AND reload open windows once, so a deploy
        actually reaches the APK/PWA without the user manually clearing cache
        (the app was holding stale JS: tiles/toasts/etc. didn't update).
   v17 (2026-07-23): cache-bust — force stale clients onto the latest tasks tile,
        chat avatars/card, top-bar tooltips and quick-add fixes.
   v18 (2026-07-27): assets (JS/CSS/img) are now NETWORK-FIRST, not cache-first —
        cache-first was serving stale scripts after a deploy (HTML updated but old
        JS/CSS kept loading → the phone "didn't change"). Always online = always
        fresh; cache is now only an offline fallback.
   v19 (2026-07-27): SW fetch now uses {cache:'reload'} so network-first actually
        bypasses the browser HTTP cache (max-age JS/CSS was being served stale).
   =================================================================== */
const CACHE = 'kt-v134-2026073118';
const ASSETS = ['/', '/index.html', '/dashboard.html', '/manifest.json'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(() => {})));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    var hadController = false;
    try {
      var keys = await caches.keys();
      hadController = keys.some(function (k) { return k !== CACHE; });   // an OLD cache existed → this is an update
      await Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    } catch (e) {}
    try { await self.clients.claim(); } catch (e) {}
    // Only reload on a genuine UPDATE (old cache was present), never on the very
    // first install — otherwise a fresh sign-in would double-load.
    if (hadController) {
      try {
        var cs = await self.clients.matchAll({ type: 'window' });
        cs.forEach(function (c) { try { c.navigate(c.url); } catch (e) {} });
      } catch (e) {}
    }
  })());
});

self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  if (e.request.url.includes('/api/')) return; // Don't cache API

  const accept = e.request.headers.get('accept') || '';
  const isDoc = e.request.mode === 'navigate' || accept.includes('text/html');

  if (isDoc) {
    // Network-first for the app shell (HTML): always try the fresh page so a
    // deploy is picked up immediately; fall back to cache only when offline.
    // {cache:'reload'} BYPASSES the browser HTTP cache — otherwise fetch() can
    // return a stale copy that .htaccess marked max-age, defeating "network-first".
    e.respondWith(
      fetch(new Request(e.request, { cache: 'reload' }))
        .then(res => {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match(e.request).then(c => c || caches.match('/dashboard.html')))
    );
    return;
  }

  // Other GETs (assets = JS/CSS/img): NETWORK-FIRST. The APK/PWA is effectively
  // always online, and cache-first was serving STALE scripts/styles even after a
  // deploy (the HTML updated network-first but the old JS/CSS kept being served
  // from cache → bar/gear/cards rendered with old code). Always try the network,
  // refresh the cache, and fall back to cache only when the network truly fails
  // (offline). This ends the recurring "fix deployed but the phone didn't change".
  e.respondWith(
    fetch(new Request(e.request, { cache: 'reload' }))   // bypass the browser HTTP cache so a stale max-age JS/CSS can't be served — TRUE network-first
      .then(res => {
        try {
          if (res && res.status === 200 && (res.type === 'basic' || res.type === 'default')) {
            const copy = res.clone();
            caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
          }
        } catch (e) {}
        return res;
      })
      .catch(() => caches.match(e.request).then(c => c || caches.match('/dashboard.html')))
  );
});

// --- PUSH -----------------------------------------------------------
self.addEventListener('push', (event) => {
  let payload = { title: 'Kiddietrac', body: 'You have a new notification' };
  try {
    if (event.data) payload = event.data.json();
  } catch (e) {
    try { payload.body = event.data.text(); } catch (_) {}
  }

  const options = {
    body: payload.body || '',
    icon: payload.icon || '/icon-192.png',
    badge: payload.badge || '/icon-192.png',
    tag: payload.tag,
    data: { url: payload.url || '/dashboard.html' },
  };

  event.waitUntil(
    self.registration.showNotification(payload.title || 'Kiddietrac', options)
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/dashboard.html';
  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then((clients) => {
      for (const c of clients) {
        if (c.url.endsWith(url) && 'focus' in c) return c.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
