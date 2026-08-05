/**
 * AUTOMETRIA ERP — Reverb / Echo bridge (Sprint 1).
 *
 * No hard dependency on laravel-echo until `npm i laravel-echo pusher-js`.
 * When VITE_REVERB_APP_KEY is set and packages exist, loads Echo dynamically.
 */

let echoInstance = null

export async function createEcho() {
  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key || echoInstance) {
    return echoInstance
  }

  try {
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
      import('laravel-echo'),
      import('pusher-js'),
    ])
    window.Pusher = Pusher
    echoInstance = new Echo({
      broadcaster: 'reverb',
      key,
      wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8081),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8081),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: '/broadcasting/auth',
    })
  } catch (e) {
    console.warn('[realtime] Echo unavailable — install laravel-echo + pusher-js', e)
    echoInstance = null
  }

  return echoInstance
}

export function getEcho() {
  return echoInstance
}

/**
 * @param {number} tenantId
 * @param {{ onStockUpdated?: Function, onReceiptFiscalized?: Function }} handlers
 * @returns {Promise<() => void>} unsubscribe
 */
export async function subscribeTenantRealtime(tenantId, handlers = {}) {
  const echo = await createEcho()
  if (!echo || !tenantId) {
    return () => {}
  }

  const stock = echo.private(`tenant.${tenantId}.stock`)
  const fiscal = echo.private(`tenant.${tenantId}.fiscal`)

  if (handlers.onStockUpdated) {
    stock.listen('.stock.updated', handlers.onStockUpdated)
  }
  if (handlers.onReceiptFiscalized) {
    fiscal.listen('.receipt.fiscalized', handlers.onReceiptFiscalized)
  }

  return () => {
    echo.leave(`tenant.${tenantId}.stock`)
    echo.leave(`tenant.${tenantId}.fiscal`)
  }
}
