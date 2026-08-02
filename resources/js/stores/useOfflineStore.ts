/**
 * AUTOMETRIA ERP — Offline catalog + local receipts (Dexie)
 */
import { defineStore } from 'pinia'
import {
  createReceiptUuid,
  db,
  type CachedProduct,
  type LocalPaymentType,
  type LocalReceipt,
  type LocalReceiptItem,
  type LocalRefund,
  type LocalRefundItem,
} from '../services/offlineDb'

export const useOfflineStore = defineStore('offline', {
  state: () => ({
    online: typeof navigator !== 'undefined' ? navigator.onLine : true,
    pendingCount: 0,
    failedCount: 0,
    pendingRefundCount: 0,
    caching: false,
    lastCacheAt: null as string | null,
    syncing: false,
    lastSyncError: null as string | null,
  }),

  getters: {
    hasPending: (s) => s.pendingCount > 0 || s.pendingRefundCount > 0,
  },

  actions: {
    setOnline(v: boolean) {
      this.online = v
    },

    async refreshCounts() {
      this.pendingCount = await db.localReceipts.where('status').equals('PENDING_SYNC').count()
      this.failedCount = await db.localReceipts.where('status').equals('FAILED').count()
      this.pendingRefundCount = await db.localRefunds.where('status').equals('PENDING_SYNC').count()
    },

    /**
     * Upsert product catalog into IndexedDB for offline scan/search.
     */
    async cacheProducts(products: CachedProduct[]) {
      this.caching = true
      try {
        const rows = (products || []).map((p) => ({
          id: Number(p.product_id || p.id),
          product_id: Number(p.product_id || p.id),
          sku: String(p.sku || ''),
          barcode: String(p.barcode || p.sku || ''),
          oem: p.oem,
          title: String(p.title || ''),
          price: Number(p.price || 0),
          available: p.available ?? null,
          warehouse_id: p.warehouse_id ?? null,
          vat_rate: p.vat_rate || 'none',
          category: p.category || 'all',
          is_marked: Boolean(p.is_marked),
          marking_type: p.marking_type ?? null,
          is_egais: Boolean(p.is_egais),
          updated_at: new Date().toISOString(),
        }))
        await db.cachedProducts.clear()
        if (rows.length) await db.cachedProducts.bulkPut(rows)
        this.lastCacheAt = new Date().toISOString()
        return rows.length
      } finally {
        this.caching = false
      }
    },

    async searchCached(query: string, limit = 40): Promise<CachedProduct[]> {
      const q = String(query || '').trim().toLowerCase()
      if (!q) {
        return db.cachedProducts.limit(limit).toArray()
      }
      const all = await db.cachedProducts.toArray()
      return all
        .filter((p) => {
          const hay = [p.sku, p.barcode, p.oem, p.title, String(p.product_id)]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
          return hay.includes(q)
        })
        .slice(0, limit)
    },

    async findByBarcode(code: string): Promise<CachedProduct | null> {
      const c = String(code || '').trim()
      if (!c) return null
      const byBarcode = await db.cachedProducts.where('barcode').equals(c).first()
      if (byBarcode) return byBarcode
      const bySku = await db.cachedProducts.where('sku').equals(c).first()
      return bySku || null
    },

    async saveLocalReceipt(input: {
      tenant_id: number
      shift_id: number
      cashier_id: number
      items: LocalReceiptItem[]
      total_amount: number
      amount_tendered?: number
      payment_type: LocalPaymentType
      payment_parts?: Array<{ method: string; amount: number }>
      requires_fiscal_marking?: boolean
    }): Promise<LocalReceipt> {
      const receipt: LocalReceipt = {
        uuid: createReceiptUuid(),
        tenant_id: input.tenant_id,
        shift_id: input.shift_id,
        cashier_id: input.cashier_id,
        items: input.items,
        total_amount: input.total_amount,
        amount_tendered: input.amount_tendered,
        payment_type: input.payment_type,
        payment_parts: input.payment_parts,
        requires_fiscal_marking: Boolean(input.requires_fiscal_marking),
        status: 'PENDING_SYNC',
        created_at: new Date().toISOString(),
        synced_at: null,
        last_error: null,
      }
      const id = await db.localReceipts.add(receipt)
      receipt.id = id
      await this.refreshCounts()
      return receipt
    },

    async listPending(): Promise<LocalReceipt[]> {
      return db.localReceipts.where('status').equals('PENDING_SYNC').sortBy('created_at')
    },

    async markSynced(id: number) {
      await db.localReceipts.update(id, {
        status: 'SYNCED',
        synced_at: new Date().toISOString(),
        last_error: null,
      })
      await this.refreshCounts()
    },

    async markFailed(id: number, error: string) {
      await db.localReceipts.update(id, {
        status: 'FAILED',
        last_error: error,
      })
      await this.refreshCounts()
    },

    async retryFailed() {
      const failed = await db.localReceipts.where('status').equals('FAILED').toArray()
      for (const r of failed) {
        if (r.id != null) {
          await db.localReceipts.update(r.id, { status: 'PENDING_SYNC', last_error: null })
        }
      }
      const failedRefunds = await db.localRefunds.where('status').equals('FAILED').toArray()
      for (const r of failedRefunds) {
        if (r.id != null) {
          await db.localRefunds.update(r.id, { status: 'PENDING_SYNC', last_error: null })
        }
      }
      await this.refreshCounts()
    },

    async saveLocalRefund(input: {
      tenant_id: number
      order_id: number
      cashier_id: number
      shift_id?: number | null
      reason?: string | null
      items: LocalRefundItem[]
      total_amount: number
    }): Promise<LocalRefund> {
      const row: LocalRefund = {
        uuid: createReceiptUuid(),
        tenant_id: input.tenant_id,
        order_id: input.order_id,
        shift_id: input.shift_id ?? null,
        cashier_id: input.cashier_id,
        reason: input.reason ?? null,
        items: input.items,
        total_amount: input.total_amount,
        status: 'PENDING_SYNC',
        created_at: new Date().toISOString(),
        synced_at: null,
        last_error: null,
      }
      const id = await db.localRefunds.add(row)
      row.id = id
      await this.refreshCounts()
      return row
    },

    async listPendingRefunds(): Promise<LocalRefund[]> {
      return db.localRefunds.where('status').equals('PENDING_SYNC').sortBy('created_at')
    },

    async markRefundSynced(id: number) {
      await db.localRefunds.update(id, {
        status: 'SYNCED',
        synced_at: new Date().toISOString(),
        last_error: null,
      })
      await this.refreshCounts()
    },

    async markRefundFailed(id: number, error: string) {
      await db.localRefunds.update(id, {
        status: 'FAILED',
        last_error: error,
      })
      await this.refreshCounts()
    },
  },
})
