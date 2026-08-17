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
   v20 (2026-08-08): PERFORMANCE. v18/v19 made EVERY launch re-download all ~180
        JS/CSS assets from the network (slow on mobile/APK). But those assets are
        VERSIONED (?v=… in the URL), so a URL is immutable: when a file changes the
        deploy bumps its ?v=, producing a NEW url → guaranteed cache miss → fresh
        fetch. So versioned /js|/css assets are now CACHE-FIRST from a PERSISTENT
        cache (STATIC) that survives deploys — instant launches, zero staleness
        (the old staleness came from cache-first on UNVERSIONED urls). HTML + any
        unversioned asset stay network-first. Net: near-instant repeat launches.
   =================================================================== */
const CACHE = "kt-v481-2026081718";
// Persistent store for ?v= assets. Bumping this NAME force-deletes the old one on
// activate → a one-time flush that re-fetches every versioned asset fresh. Do this
// whenever stale assets need clearing wholesale (e.g. a ?v= bump was missed on a
// changed file, which used to freeze that file forever under cache-first).
const STATIC = "kt-static-v2";
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
      hadController = keys.some(function (k) { return k !== CACHE && k !== STATIC; });   // an OLD shell cache existed → this is an update
      // Delete old SHELL caches, but KEEP the persistent versioned-asset cache so
      // immutable ?v= assets survive the deploy (that's what makes launches fast).
      await Promise.all(keys.filter(function (k) { return k !== CACHE && k !== STATIC; }).map(function (k) { return caches.delete(k); }));
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

  // ── FAST PATH: versioned static assets (cache-first, persistent) ──
  // /js/… or /css/… with a ?v= are immutable per version — a content change ships
  // a new ?v= (new URL). So a cache hit is ALWAYS the right bytes, served instantly
  // with no network round-trip. This is the single biggest mobile/APK speed win.
  if (!isDoc) {
    let u = null;
    try { u = new URL(e.request.url); } catch (err) {}
    const versioned = u && u.origin === self.location.origin
      && (u.pathname.startsWith('/js/') || u.pathname.startsWith('/css/'))
      && /[?&]v=/.test(u.search);
    if (versioned) {
      e.respondWith(
        caches.open(STATIC).then((c) => c.match(e.request).then((hit) => {
          // STALE-WHILE-REVALIDATE (not pure cache-first). Serve the cached bytes
          // instantly for a fast launch, but ALWAYS refetch in the background and
          // update the cache. Pure cache-first assumed every content change bumps
          // ?v=; when a bump was missed the old bytes were served forever (the
          // "some things old, some new" bug). With SWR a missed bump self-heals on
          // the NEXT load — no asset can stay frozen. {cache:'reload'} bypasses the
          // browser HTTP cache so the refetch is genuinely fresh.
          const network = fetch(new Request(e.request, { cache: 'reload' })).then((res) => {
            if (res && res.status === 200) { try { c.put(e.request, res.clone()); } catch (err) {} }
            return res;
          }).catch(() => null);
          return hit || network.then((r) => r || caches.match(e.request));
        }))
      );
      return;
    }
  }

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
      // Fall back to the CACHED COPY of this asset when offline — but never to
      // /dashboard.html. Substituting the HTML shell for a failed image/PDF/font
      // made a blocked request look like a SUCCESSFUL html response: a
      // cross-origin PDF fetch came back as the dashboard page, and pdf.js
      // reported "Invalid PDF structure" instead of a network error.
      .catch(() => caches.match(e.request).then(c => c || new Response('', {
        status: 504, statusText: 'Offline and not cached',
      })))
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
