// WorkToGo Rider — service worker (S6). Installable PWA shell + offline-tolerant.
// Shell assets are cache-first; API calls are network-first (fall back to cache
// only if offline). Bump CACHE on shell changes.
const CACHE = 'wtg-rider-v1';
const SHELL = ['./', './index.html', './rider.js', './manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()).catch(() => {}));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // never cache writes (accept/pickup/deliver)
  if (req.url.includes('/api/')) {
    // live rider data: network-first, cache fallback when offline
    e.respondWith(fetch(req).catch(() => caches.match(req)));
    return;
  }
  // app shell: cache-first
  e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
});
