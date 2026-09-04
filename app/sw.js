/**
 * WorkToGo — App Service Worker
 * Lightweight, conservative caching for fast repeat opens on weak internet.
 * Bump CACHE to force a clean refresh of cached assets on the next deploy.
 *
 * Strategy — never serve stale CODE or stale DATA:
 *   • Navigations (HTML)                  → network-first, cached shell offline.
 *   • Same-origin static GET under /app/  → stale-while-revalidate (instant from
 *     cache, refreshed in the background). App assets are ?v=<build>-versioned,
 *     so a new build is a new URL — a cache hit is never stale code.
 *   • API / auth / cross-origin / non-GET → passthrough, never cached.
 *
 * NOTE: FCM uses a SEPARATE worker (pages/firebase-sw.js) registered by push.js;
 * this file does not touch push/messaging.
 */
const CACHE = 'wtg-app-v1';
// Version-agnostic shell entries only (no ?v= query) — an offline landing.
const SHELL = ['/app/', '/app/manifest.json', '/app/assets/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(SHELL).catch(() => {})) // a missing entry must not fail install
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                          // writes always hit the network
  let url;
  try { url = new URL(req.url); } catch (_) { return; }
  if (url.origin !== self.location.origin) return;           // Maps / Cashfree / fonts / cross-origin API → network
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/heart/')) return; // live data + auth → network

  // Navigations: always try the network first (fresh HTML/code); fall back to
  // the cached shell when offline so the app still boots.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/app/').then((r) => r || caches.match(req)))
    );
    return;
  }

  // Same-origin static assets under /app/: stale-while-revalidate.
  if (url.pathname.startsWith('/app/')) {
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((cached) => {
          const network = fetch(req)
            .then((res) => {
              if (res && res.status === 200 && res.type === 'basic') cache.put(req, res.clone());
              return res;
            })
            .catch(() => cached);
          return cached || network;
        })
      )
    );
  }
});
