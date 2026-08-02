/**
 * AUTOMETRIA ERP — PWA composable
 * Registers Service Worker, tracks online/offline, persists POS cart drafts to IndexedDB.
 */
import { onMounted, onUnmounted, ref, type Ref } from 'vue'

export type PwaStatus = {
  online: Ref<boolean>
  swReady: Ref<boolean>
  updateAvailable: Ref<boolean>
  register: () => Promise<ServiceWorkerRegistration | null>
  init: () => void
}

async function persistCartDraft(): Promise<void> {
  try {
    const { usePosStore } = await import('@/stores/usePosStore')
    const { useOfflineStore } = await import('@/stores/useOfflineStore')
    const { getStoredUser } = await import('@/autometria/api/client')

    const pos = usePosStore()
    const offline = useOfflineStore()
    if (!pos.cart?.length) return

    const user = getStoredUser() as { id?: number; tenant_id?: number } | null
    const tenantId = Number(user?.tenant_id || 0)
    const cashierId = Number(user?.id || 0)

    let shiftId = 0
    try {
      const { useShiftStore } = await import('@/autometria/stores/cashierStore')
      shiftId = Number(useShiftStore().shiftId || 0)
    } catch {
      shiftId = 0
    }

    await offline.saveCartDraft({
      tenant_id: tenantId || 0,
      shift_id: shiftId || 0,
      cashier_id: cashierId || 0,
      items: pos.cart.map((r) => ({
        product_id: Number(r.product_id),
        warehouse_id: r.warehouse_id ?? null,
        title: String(r.title || ''),
        sku: String(r.sku || ''),
        qty: Number(r.qty || 0),
        price: Number(r.price || 0),
        discount: Number(r.discount || 0),
        vat_rate: String(r.vat_rate || 'none'),
        marking_code: r.marking_code ?? null,
      })),
      total_amount: Number(pos.totalDue || pos.subtotal || 0),
      customer_id: pos.selectedCustomer?.id != null ? Number(pos.selectedCustomer.id) : undefined,
      bonus_spend: pos.bonusSpend != null ? Number(pos.bonusSpend) : undefined,
    })
    offline.setOnline(false)
  } catch (e) {
    console.warn('[PWA] cart draft persist skipped:', e)
  }
}

export function usePwa(): PwaStatus {
  const online = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
  const swReady = ref(false)
  const updateAvailable = ref(false)

  let registration: ServiceWorkerRegistration | null = null

  async function register(): Promise<ServiceWorkerRegistration | null> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
      return null
    }
    try {
      registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' })
      swReady.value = true

      registration.addEventListener('updatefound', () => {
        const worker = registration?.installing
        if (!worker) return
        worker.addEventListener('statechange', () => {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            updateAvailable.value = true
          }
        })
      })

      return registration
    } catch (e) {
      console.warn('[PWA] SW registration failed:', e)
      return null
    }
  }

  function onOnline() {
    online.value = true
    void import('@/stores/useOfflineStore')
      .then(({ useOfflineStore }) => {
        useOfflineStore().setOnline(true)
      })
      .catch(() => undefined)
  }

  function onOffline() {
    online.value = false
    void persistCartDraft()
  }

  function init() {
    if (typeof window === 'undefined') return

    online.value = navigator.onLine
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)

    void register()

    if (!navigator.onLine) {
      void persistCartDraft()
    }
  }

  onMounted(() => {
    // no-op when init() is called explicitly from bootstrap
  })

  onUnmounted(() => {
    if (typeof window === 'undefined') return
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
  })

  return { online, swReady, updateAvailable, register, init }
}
