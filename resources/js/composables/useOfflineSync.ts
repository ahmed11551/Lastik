/**
 * AUTOMETRIA ERP — Offline receipt sync worker (idempotent)
 */
import { apiPost } from '../autometria/api/client'
import { toast } from '../autometria/api/toast'
import { useOfflineStore } from '../stores/useOfflineStore'
import type { LocalReceipt } from '../services/offlineDb'

let syncing = false
let bound = false

function mapMethod(receipt: LocalReceipt): string {
  if (receipt.payment_type === 'CARD') return 'card'
  if (receipt.payment_type === 'MIXED') return 'cash'
  return 'cash'
}

/**
 * Flush PENDING_SYNC receipts to ERP with X-Idempotency-Key = uuid.
 */
export async function syncPendingReceipts(): Promise<{ synced: number; failed: number }> {
  if (syncing) return { synced: 0, failed: 0 }
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    return { synced: 0, failed: 0 }
  }

  const offline = useOfflineStore()
  syncing = true
  offline.syncing = true
  offline.lastSyncError = null

  let synced = 0
  let failed = 0

  try {
    const pending = await offline.listPending()
    for (const receipt of pending) {
      if (receipt.id == null) continue
      try {
        await apiPost(
          '/pos/offline-receipts',
          {
            uuid: receipt.uuid,
            shift_id: receipt.shift_id,
            payment_type: receipt.payment_type,
            amount_tendered: receipt.amount_tendered ?? receipt.total_amount,
            total_amount: receipt.total_amount,
            items: (receipt.items || []).map((i) => ({
              product_id: i.product_id,
              qty: i.qty,
              discount: i.discount || 0,
              warehouse_id: i.warehouse_id || undefined,
              vat_rate: i.vat_rate || 'none',
              type: 'product',
              marking_code: i.marking_code || i.markingCode || undefined,
            })),
            method: mapMethod(receipt),
            payment_parts: receipt.payment_parts,
            created_at: receipt.created_at,
          },
          {
            headers: { 'X-Idempotency-Key': receipt.uuid },
            silent: true,
          },
        )
        await offline.markSynced(receipt.id)
        synced += 1
      } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status
        // Already accepted (idempotent replay)
        if (status === 200 || status === 201 || status === 409) {
          await offline.markSynced(receipt.id)
          synced += 1
          continue
        }
        const msg =
          (err as { response?: { data?: { message?: string } }; message?: string })?.response?.data
            ?.message ||
          (err as { message?: string })?.message ||
          'sync failed'
        console.error('Sync failed for receipt:', receipt.uuid, err)
        offline.lastSyncError = msg
        // Keep PENDING_SYNC for transient errors; mark FAILED after hard 4xx (except 409)
        if (status && status >= 400 && status < 500 && status !== 408 && status !== 429) {
          await offline.markFailed(receipt.id, msg)
          failed += 1
        }
      }
    }

    if (synced > 0) {
      toast.success(`Синхронизировано чеков: ${synced}`, 'POS Sync')
    }
  } finally {
    syncing = false
    offline.syncing = false
    await offline.refreshCounts()
  }

  return { synced, failed }
}

/** Bind online/offline listeners once (call from PosView onMounted). */
export function bindOfflineSyncListeners(): () => void {
  if (bound || typeof window === 'undefined') return () => {}
  bound = true

  const offline = useOfflineStore()
  const onOnline = () => {
    offline.setOnline(true)
    toast.info('Сеть восстановлена — синхронизация…', 'POS')
    syncPendingReceipts().catch(() => {})
  }
  const onOffline = () => {
    offline.setOnline(false)
    toast.warning('Нет сети — касса в Offline-режиме', 'POS')
  }

  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  offline.setOnline(navigator.onLine)
  offline.refreshCounts().catch(() => {})

  if (navigator.onLine) {
    syncPendingReceipts().catch(() => {})
  }

  return () => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
    bound = false
  }
}
