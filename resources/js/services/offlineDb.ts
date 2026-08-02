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
}

export class PosDatabase extends Dexie {
  localReceipts!: Table<LocalReceipt, number>
  cachedProducts!: Table<CachedProduct, number>

  constructor() {
    super('PosDatabase')
    this.version(1).stores({
      localReceipts: '++id, uuid, status, created_at, shift_id',
      cachedProducts: 'id, product_id, sku, barcode, title',
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
