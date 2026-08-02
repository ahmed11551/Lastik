/* AUTOMETRIA ERP — Service Worker
 * Shell cache + offline navigation fallback. API stays network-first (never cached).
 */
const CACHE = 'autometria-shell-v2';
const SHELL = [
  '/',
  '/index.html',
  '/manifest.webmanifest',
  '/icons/icon-192.svg',
  '/icons/icon-512.svg',
];

const OFFLINE_HTML = `<!doctype html>
<html lang="ru"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>AUTOMETRIA — Offline</title>
<style>
  body{margin:0;font-family:Inter,system-ui,sans-serif;background:#0B0D10;color:#E5E7EB;display:flex;min-height:100vh;align-items:center;justify-content:center}
  .box{max-width:420px;padding:24px;border:1px solid #1F2937;border-radius:4px;background:#111827}
  h1{margin:0 0 8px;font-size:18px;color:#F59E0B}p{margin:0;font-size:13px;color:#9CA3AF;line-height:1.5}
</style></head>
<body><div class="box"><h1>Нет сети</h1>
<p>Касса работает в offline-режиме. Черновик чека сохранён в IndexedDB и будет синхронизирован при восстановлении связи.</p>
</div></body></html>`;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll(SHELL).catch(() => undefined))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Never cache API / Sanctum / Livewire — network only; surface offline error.
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) {
    event.respondWith(
      fetch(request).catch(
        () =>
          new Response(JSON.stringify({ message: 'Offline', code: 'NETWORK_OFFLINE' }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
          }),
      ),
    );
    return;
  }

  // Navigations: network-first, offline shell fallback.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          if (response.ok) {
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          if (cached) return cached;
          const shell = await caches.match('/');
          if (shell) return shell;
          return new Response(OFFLINE_HTML, {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
          });
        }),
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(
      (cached) =>
        cached ||
        fetch(request)
          .then((response) => {
            const copy = response.clone();
            if (response.ok && url.origin === self.location.origin) {
              caches.open(CACHE).then((cache) => cache.put(request, copy));
            }
            return response;
          })
          .catch(() => cached || new Response('', { status: 503 })),
    ),
  );
});
