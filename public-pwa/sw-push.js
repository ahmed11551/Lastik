/**
 * AUTOMETRIA ERP — Service Worker push handlers (imported by Workbox generateSW).
 * Cosmic Navy payload: { title, body, icon, badge, data: { url }, tag, actions }
 */
/* eslint-disable no-restricted-globals */

self.addEventListener('push', (event) => {
  let payload = {
    title: 'AUTOMETRIA',
    body: '',
    icon: '/icons/icon-192.svg',
    badge: '/icons/icon-192.svg',
    data: { url: '/' },
    tag: 'autometria',
    renotify: false,
    requireInteraction: false,
    actions: [],
  }

  try {
    if (event.data) {
      const parsed = event.data.json()
      payload = {
        ...payload,
        ...parsed,
        data: { url: '/', ...(parsed.data || {}) },
        actions: parsed.actions || [],
      }
    }
  } catch {
    try {
      const text = event.data ? event.data.text() : ''
      if (text) payload.body = text
    } catch {
      /* ignore malformed payload */
    }
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'AUTOMETRIA', {
      body: payload.body || '',
      icon: payload.icon || '/icons/icon-192.svg',
      badge: payload.badge || '/icons/icon-192.svg',
      data: payload.data || { url: '/' },
      tag: payload.tag || 'autometria',
      renotify: Boolean(payload.renotify),
      requireInteraction: Boolean(payload.requireInteraction),
      actions: Array.isArray(payload.actions) ? payload.actions : [],
      vibrate: [80, 40, 80],
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const action = event.action
  const data = event.notification.data || {}
  const targetUrl = typeof data.url === 'string' && data.url.length > 0 ? data.url : '/'

  if (action && action !== 'open') {
    // Reserved for future action routing.
  }

  event.waitUntil(
    (async () => {
      const allClients = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
      })

      for (const client of allClients) {
        if ('focus' in client) {
          if ('navigate' in client && targetUrl) {
            try {
              await client.navigate(targetUrl)
            } catch {
              /* navigate may fail on opaque origins — still focus */
            }
          }
          return client.focus()
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl)
      }

      return undefined
    })(),
  )
})
