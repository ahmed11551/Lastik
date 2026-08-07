/**
 * AUTOMETRIA ERP — PWA composable
 * Registers Service Worker via vite-plugin-pwa, tracks online/offline,
 * persists POS cart drafts to IndexedDB.
 */
import { onMounted, onUnmounted, ref, type Ref } from 'vue'
import { registerSW } from 'virtual:pwa-register'
import { getStoredUser } from '@/autometria/api/client'
import { useShiftStore } from '@/autometria/stores/cashierStore'
import { useOfflineStore } from '@/stores/useOfflineStore'
import { usePosStore } from '@/stores/usePosStore'

export type PwaStatus = {
  online: Ref<boolean>
  swReady: Ref<boolean>
  updateAvailable: Ref<boolean>
  register: () => Promise<ServiceWorkerRegistration | null>
  init: () => void
}

async function persistCartDraft(): Promise<void> {
  try {
    const pos = usePosStore()
    const offline = useOfflineStore()
    if (!pos.cart?.length) return

    const user = getStoredUser() as { id?: number; tenant_id?: number } | null
    const tenantId = Number(user?.tenant_id || 0)
    const cashierId = Number(user?.id || 0)

    let shiftId = 0
    try {
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
      registerSW({
        immediate: true,
        onNeedRefresh() {
          updateAvailable.value = true
        },
        onOfflineReady() {
          swReady.value = true
        },
        onRegisteredSW(_swUrl, reg) {
          registration = reg ?? null
          swReady.value = true
        },
      })

      registration = (await navigator.serviceWorker.getRegistration()) ?? null
      if (registration) {
        swReady.value = true
      }

      return registration
    } catch (e) {
      console.warn('[PWA] SW registration failed, falling back to /sw.legacy.js:', e)
      try {
        registration = await navigator.serviceWorker.register('/sw.legacy.js', { scope: '/' })
        swReady.value = true
        return registration
      } catch (fallbackError) {
        console.warn('[PWA] legacy SW registration failed:', fallbackError)
        return null
      }
    }
  }

  function onOnline() {
    online.value = true
    try {
      useOfflineStore().setOnline(true)
      const user = getStoredUser() as { id?: number } | null
      let shiftId = 0
      try {
        shiftId = Number(useShiftStore().shiftId || 0)
      } catch {
        shiftId = 0
      }
      void usePosStore().restoreCartDraft(Number(user?.id || 0), shiftId)
    } catch {
      /* restore is best-effort on reconnect */
    }
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
