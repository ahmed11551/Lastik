/**
 * AUTOMETRIA ERP — POS Offline-First IndexedDB (Dexie)
 */
import Dexie, { type Table } from 'dexie'

export type LocalPaymentType = 'CASH' | 'CARD' | 'MIXED'

export type LocalReceiptStatus = 'PENDING_SYNC' | 'SYNCED' | 'FAILED'

export type LocalReceiptItem = {
  product_id: number
  variant_id?: number
  warehouse_id?: number | null
  title: string
  sku?: string
  qty: number
  price: number
  discount?: number
  vat_rate: string
  /** Raw GS1 DataMatrix CIS */
  markingCode?: string | null
  marking_code?: string | null
  gtin?: string | null
  serial_number?: string | null
  is_marked?: boolean
}

export interface LocalReceipt {
  id?: number
  uuid: string
  tenant_id: number
  shift_id: number
  cashier_id: number
  items: LocalReceiptItem[]
  total_amount: number
  amount_tendered?: number
  payment_type: LocalPaymentType
  payment_parts?: Array<{ method: string; amount: number }>
  status: LocalReceiptStatus
  /** Offline receipt contains marking CIS → fiscal marking required on sync */
  requires_fiscal_marking?: boolean
  /** Block 4.3 — CRM / Loyalty binding */
  customer_id?: number
  bonus_spend?: number
  created_at: string
  synced_at?: string | null
  last_error?: string | null
}

export type LocalRefundStatus = 'PENDING_SYNC' | 'SYNCED' | 'FAILED'

export type LocalRefundItem = {
  order_item_id: number
  product_id?: number | null
  title?: string
  qty: number
  max_qty?: number
  price?: number
  marking_code?: string | null
}

/** Offline-queued return (sell_refund) until ERP accepts POST /pos/refunds */
export interface LocalRefund {
  id?: number
  uuid: string
  tenant_id: number
  order_id: number
  shift_id?: number | null
  cashier_id: number
  reason?: string | null
  items: LocalRefundItem[]
  total_amount: number
  status: LocalRefundStatus
  created_at: string
  synced_at?: string | null
  last_error?: string | null
}

export interface CachedProduct {
  id: number
  product_id: number
  sku: string
  barcode: string
  oem?: string
  title: string
  price: number
  available?: number | null
  warehouse_id?: number | null
  vat_rate?: string
  category?: string
  updated_at?: string
  is_marked?: boolean
  marking_type?: string | null
  is_egais?: boolean
}

/** In-progress POS cart snapshot persisted on network loss */
export interface CartDraft {
  id?: number
  key: string
  tenant_id: number
  shift_id: number
  cashier_id: number
  items: LocalReceiptItem[]
  total_amount: number
  customer_id?: number
  bonus_spend?: number
  updated_at: string
}

export class PosDatabase extends Dexie {
  localReceipts!: Table<LocalReceipt, number>
  cachedProducts!: Table<CachedProduct, number>
  localRefunds!: Table<LocalRefund, number>
  cartDrafts!: Table<CartDraft, number>

  constructor() {
    super('PosDatabase')
    this.version(1).stores({
      localReceipts: '++id, uuid, status, created_at, shift_id',
      cachedProducts: 'id, product_id, sku, barcode, title',
    })
    // v2: marking / Честный Знак fields live inside JSON rows (no index change required)
    this.version(2).stores({
      localReceipts: '++id, uuid, status, created_at, shift_id',
      cachedProducts: 'id, product_id, sku, barcode, title',
    })
    // v3: Block 3.4 offline refunds queue
    this.version(3).stores({
      localReceipts: '++id, uuid, status, created_at, shift_id',
      cachedProducts: 'id, product_id, sku, barcode, title',
      localRefunds: '++id, uuid, order_id, status, created_at',
    })
    // v4: Sprint A — cart drafts on connection loss
    this.version(4).stores({
      localReceipts: '++id, uuid, status, created_at, shift_id',
      cachedProducts: 'id, product_id, sku, barcode, title',
      localRefunds: '++id, uuid, order_id, status, created_at',
      cartDrafts: '++id, key, updated_at, shift_id',
    })
  }
}

export const db = new PosDatabase()

export function createReceiptUuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `pos-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`
}
