/**
 * AUTOMETRIA ERP — Offline receipt + refund + WMS stock sync (idempotent)
 */
import { apiPost } from '../autometria/api/client'
import { toast } from '../autometria/api/toast'
import { useOfflineStore } from '../stores/useOfflineStore'
import type { LocalReceipt, LocalStockOp } from '../services/offlineDb'
import { classifySyncStatus } from './syncStatus'
import { decimalAbs, decimalAdd, decimalSub, normalizeQuantityString } from '../autometria/utils/decimalString'

let syncing = false
let bound = false

function mapMethod(receipt: LocalReceipt): string {
  if (receipt.payment_type === 'CARD') return 'card'
  if (receipt.payment_type === 'MIXED') return 'cash'
  return 'cash'
}

function httpStatus(err: unknown): number | undefined {
  return (err as { response?: { status?: number } })?.response?.status
}

function httpErrorCode(err: unknown): string | undefined {
  return (err as { response?: { data?: { error?: string } } })?.response?.data?.error
}

function httpMessage(err: unknown, fallback: string): string {
  return (
    (err as { response?: { data?: { message?: string } }; message?: string })?.response?.data
      ?.message ||
    (err as { message?: string })?.message ||
    fallback
  )
}

/**
 * Resolve factual on-hand for inventory-adjust from queued op fields.
 * qty / book_qty / actual_qty stay as decimal strings (no float).
 */
function resolveActualQty(op: LocalStockOp): string {
  const explicit = normalizeQuantityString(op.actual_qty ?? null)
  if (explicit != null) return explicit

  const book = normalizeQuantityString(op.book_qty ?? null) || '0'
  const delta = decimalAbs(normalizeQuantityString(op.qty) || '0')

  if (op.op_type === 'RECEIPT') return decimalAdd(book, delta)
  // WRITE_OFF
  return decimalSub(book, delta)
}

async function syncPendingRefundsInternal(
  offline: ReturnType<typeof useOfflineStore>,
): Promise<{ synced: number; failed: number }> {
  let synced = 0
  let failed = 0
  const pending = await offline.listPendingRefunds()
  for (const refund of pending) {
    if (refund.id == null) continue
    try {
      await apiPost(
        '/pos/refunds',
        {
          order_id: refund.order_id,
          reason: refund.reason || undefined,
          cash_shift_id: refund.shift_id || undefined,
          items: (refund.items || []).map((i) => ({
            order_item_id: i.order_item_id,
            qty: i.qty,
          })),
        },
        {
          headers: { 'X-Idempotency-Key': refund.uuid },
          silent: true,
        },
      )
      await offline.markRefundSynced(refund.id)
      synced += 1
    } catch (err: unknown) {
      const outcome = classifySyncStatus(httpStatus(err))
      if (outcome === 'synced') {
        await offline.markRefundSynced(refund.id)
        synced += 1
        continue
      }
      const msg = httpMessage(err, 'refund sync failed')
      console.error('Refund sync failed:', refund.uuid, err)
      offline.lastSyncError = msg
      if (outcome === 'failed') {
        await offline.markRefundFailed(refund.id, msg)
        failed += 1
      }
    }
  }
  return { synced, failed }
}

/**
 * Flush PENDING_SYNC receipts (+ refunds + stock) to ERP with X-Idempotency-Key = uuid.
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
            customer_id: receipt.customer_id || undefined,
            bonus_spend: receipt.bonus_spend || undefined,
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
        const outcome = classifySyncStatus(httpStatus(err))
        if (outcome === 'synced') {
          await offline.markSynced(receipt.id)
          synced += 1
          continue
        }
        const msg = httpMessage(err, 'sync failed')
        console.error('Sync failed for receipt:', receipt.uuid, err)
        offline.lastSyncError = msg
        if (outcome === 'failed') {
          await offline.markFailed(receipt.id, msg)
          failed += 1
        }
      }
    }

    const refundResult = await syncPendingRefundsInternal(offline)
    synced += refundResult.synced
    failed += refundResult.failed

    const stockResult = await syncPendingStockOps()
    synced += stockResult.synced
    failed += stockResult.failed

    if (synced > 0) {
      toast.success(`Синхронизировано: ${synced}`, 'POS Sync')
    }
  } finally {
    syncing = false
    offline.syncing = false
    await offline.refreshCounts()
  }

  return { synced, failed }
}

export async function syncPendingStockOps(): Promise<{ synced: number; failed: number }> {
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    return { synced: 0, failed: 0 }
  }
  const offline = useOfflineStore()
  let synced = 0
  let failed = 0
  const pending = await offline.listPendingStockOps()
  for (const op of pending) {
    if (op.id == null) continue
    try {
      if (op.op_type === 'TRANSFER') {
        await apiPost(
          '/stock/transfers',
          {
            product_id: op.product_id,
            from_warehouse_id: op.warehouse_id,
            to_warehouse_id: op.to_warehouse_id,
            qty: normalizeQuantityString(op.qty) || '0',
            reason: op.reason || 'offline transfer',
          },
          { headers: { 'X-Idempotency-Key': op.uuid }, silent: true },
        )
      } else if (op.op_type === 'BATCH_MOVE') {
        await apiPost(
          '/wms/batch-move',
          {
            batch_id: op.batch_id,
            to_cell: op.cell_code,
            qty: normalizeQuantityString(op.qty) || '0',
          },
          { headers: { 'X-Idempotency-Key': op.uuid }, silent: true },
        )
      } else {
        // WRITE_OFF | RECEIPT → inventory-adjust with factual actual_qty (string)
        await apiPost(
          '/stock/inventory-adjust',
          {
            warehouse_id: op.warehouse_id,
            product_id: op.product_id,
            actual_qty: resolveActualQty(op),
            reason:
              op.reason ||
              (op.op_type === 'RECEIPT' ? 'offline receipt' : 'offline write-off'),
          },
          { headers: { 'X-Idempotency-Key': op.uuid }, silent: true },
        )
      }
      await offline.markStockOpSynced(op.id)
      synced += 1
    } catch (err: unknown) {
      const status = httpStatus(err)
      const code = httpErrorCode(err)
      // Business 409 (insufficient stock) ≠ idempotent replay — mark failed.
      if (status === 409 && code === 'available_less_than_qty') {
        const msg = httpMessage(err, 'insufficient stock')
        offline.lastSyncError = msg
        await offline.markStockOpFailed(op.id, msg)
        failed += 1
        continue
      }
      const outcome = classifySyncStatus(status)
      if (outcome === 'synced') {
        await offline.markStockOpSynced(op.id)
        synced += 1
        continue
      }
      const msg = httpMessage(err, 'stock op sync failed')
      console.error('Stock op sync failed:', op.uuid, err)
      offline.lastSyncError = msg
      if (outcome === 'failed') {
        await offline.markStockOpFailed(op.id, msg)
        failed += 1
      }
    }
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
