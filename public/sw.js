/* Minimal installable shell — caches app shell only; API traffic stays network-first. */
const CACHE = 'lastik-shell-v1';
const SHELL = ['/', '/manifest.webmanifest', '/icons/icon-192.svg', '/icons/icon-512.svg'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.pathname.startsWith('/api/')) return;

  event.respondWith(
    caches.match(request).then((cached) =>
      cached ||
      fetch(request).then((response) => {
        const copy = response.clone();
        if (response.ok && url.origin === self.location.origin) {
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => cached)
    )
  );
});
