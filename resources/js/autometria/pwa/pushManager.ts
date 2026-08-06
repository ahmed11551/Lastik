/**
 * AUTOMETRIA ERP — Web Push client manager (Cosmic Navy).
 *
 * Graceful degradation:
 *  - If ServiceWorker / PushManager / Notification are unavailable, every
 *    exported function resolves to a safe no-op state (supported=false) so the
 *    app shell never crashes on browsers without push (e.g. iOS PWA quirks).
 *  - Subscription is best-effort: a failed subscribe is swallowed unless
 *    `silent` is false (then the caller decides).
 *
 * Server contract (see app/Http/Controllers/PushSubscriptionController):
 *  GET  /api/v1/push/vapid-public-key   -> { data: { public_key, configured } }
 *  POST /api/v1/push-subscriptions      -> { endpoint, keys:{p256dh,auth} }
 *  DELETE /api/v1/push-subscriptions    -> { endpoint }
 */

import { getToken } from '../api/client'

const VAPID_ENDPOINT = '/api/v1/push/vapid-public-key'
const SUBSCRIBE_ENDPOINT = '/api/v1/push-subscriptions'

export interface PushSupport {
  supported: boolean
  reason?: string
}

export interface RegisterOptions {
  silent?: boolean
}

/**
 * Feature-detect Web Push on this browser.
 */
export function isPushSupported(): boolean {
  if (typeof window === 'undefined' || typeof navigator === 'undefined') return false
  if (!('serviceWorker' in navigator)) return false
  if (!('PushManager' in window)) return false
  if (typeof Notification === 'undefined') return false
  return true
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const output = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) {
    output[i] = raw.charCodeAt(i)
  }
  return output
}

async function fetchVapidPublicKey(): Promise<string> {
  const token = getToken()
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(VAPID_ENDPOINT, { headers })
  if (!res.ok) throw new Error(`vapid key request failed: ${res.status}`)
  const json = (await res.json()) as { data?: { public_key?: string; configured?: boolean } }
  const key = json.data?.public_key ?? ''
  if (!key || json.data?.configured === false) {
    throw new Error('VAPID public key not configured on server')
  }
  return key
}

async function postSubscription(payload: {
  endpoint: string
  keys: { p256dh: string; auth: string }
  content_encoding?: string
}): Promise<void> {
  const token = getToken()
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  }
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(SUBSCRIBE_ENDPOINT, {
    method: 'POST',
    headers,
    body: JSON.stringify(payload),
  })
  if (!res.ok) throw new Error(`push subscribe failed: ${res.status}`)
}

async function deleteSubscription(endpoint: string): Promise<void> {
  const token = getToken()
  const headers: Record<string, string> = { 'Content-Type': 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(SUBSCRIBE_ENDPOINT, {
    method: 'DELETE',
    headers,
    body: JSON.stringify({ endpoint }),
  })
  if (!res.ok) throw new Error(`push unsubscribe failed: ${res.status}`)
}

/**
 * Register the current browser for Web Push.
 * Returns true on success, false when push is unavailable / denied / failed.
 */
export async function registerPushSubscription(opts: RegisterOptions = {}): Promise<boolean> {
  if (!isPushSupported()) {
    if (!opts.silent) console.warn('[Push] Web Push not supported on this browser.')
    return false
  }

  try {
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') {
      if (!opts.silent) console.warn('[Push] Notification permission not granted.')
      return false
    }

    const registration = await navigator.serviceWorker.getRegistration()
    if (!registration) {
      if (!opts.silent) console.warn('[Push] Service Worker not registered yet.')
      return false
    }

    // Reuse existing subscription if still valid.
    let subscription = await registration.pushManager.getSubscription()
    if (subscription) {
      return true
    }

    const vapidPublicKey = await fetchVapidPublicKey()
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    })

    const raw = subscription.toJSON() as {
      endpoint: string
      keys: { p256dh: string; auth: string }
    }

    await postSubscription({
      endpoint: raw.endpoint,
      keys: { p256dh: raw.keys.p256dh, auth: raw.keys.auth },
      content_encoding: 'aesgcm',
    })

    return true
  } catch (e) {
    if (!opts.silent) console.warn('[Push] register failed:', e)
    return false
  }
}

/**
 * Remove the current browser's push subscription (best-effort).
 */
export async function unregisterPushSubscription(opts: RegisterOptions = {}): Promise<boolean> {
  if (!isPushSupported()) return false
  try {
    const registration = await navigator.serviceWorker.getRegistration()
    if (!registration) return false
    const subscription = await registration.pushManager.getSubscription()
    if (!subscription) return true

    await deleteSubscription(subscription.endpoint)
    await subscription.unsubscribe()
    return true
  } catch (e) {
    if (!opts.silent) console.warn('[Push] unregister failed:', e)
    return false
  }
}

/**
 * Current permission state for UI hints.
 */
export function pushPermission(): NotificationPermission | 'unsupported' {
  if (!isPushSupported()) return 'unsupported'
  return Notification.permission
}
