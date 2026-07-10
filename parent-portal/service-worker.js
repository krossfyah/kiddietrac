/* ===================================================================
   KIDDIETRAC service worker v15
   v11: caching + offline; v14: + Web Push support
   v15: network-first for HTML so deploys show up without a cache trap
        (the old cache-first served a stale dashboard.html forever).
   =================================================================== */
const CACHE = 'kt-v22p98g-rt5';
const ASSETS = ['/', '/index.html', '/dashboard.html', '/manifest.json'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(() => {})));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return;
  if (e.request.url.includes('/api/')) return; // Don't cache API

  const accept = e.request.headers.get('accept') || '';
  const isDoc = e.request.mode === 'navigate' || accept.includes('text/html');

  if (isDoc) {
    // Network-first for the app shell (HTML): always try the fresh page so a
    // deploy is picked up immediately; fall back to cache only when offline.
    e.respondWith(
      fetch(e.request)
        .then(res => {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match(e.request).then(c => c || caches.match('/dashboard.html')))
    );
    return;
  }

  // Other GETs (assets): cache-first, fall back to network.
  e.respondWith(
    caches.match(e.request).then(cached =>
      cached || fetch(e.request).catch(() => caches.match('/dashboard.html'))
    )
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
