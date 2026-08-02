/**
 * AUTOMETRIA ERP — Fiscal receipts (54-ФЗ / FFD)
 */

export type FiscalReceiptStatus = 'pending' | 'fiscalized' | 'failed' | 'refunded'

export type FiscalReceiptType = 'sell' | 'sell_refund' | 'buy' | 'buy_refund'

/** СНО — система налогообложения (тег 1055) */
export type TaxSystemCode =
  | 'osn'
  | 'usn_income'
  | 'usn_income_outcome'
  | 'esn'
  | 'patent'

/** Ставка НДС позиции */
export type VatRateCode = '20' | '10' | '0' | 'none'

export type FiscalReceiptItem = {
  name: string
  qty?: number
  quantity?: number
  price: number
  sum: number
  vat_rate: VatRateCode
  discount?: number
}

export type FiscalReceiptPayload = {
  organization_name?: string
  inn?: string
  settlement_address?: string
  cashier_name?: string
  shift_number?: string | number
  tax_system?: TaxSystemCode
  buyer_email?: string | null
  buyer_phone?: string | null
  electronic?: boolean
  total?: number
  items?: FiscalReceiptItem[]
  payment_methods?: Array<{ method: string; amount: number }>
}

export type FiscalReceipt = {
  id: number
  tenant_id?: number
  cash_shift_id?: number | null
  order_id?: number | null
  payment_id?: number | null
  type: FiscalReceiptType
  status: FiscalReceiptStatus
  idempotency_key?: string
  fiscal_document_number?: string | null
  fiscal_storage_number?: string | null
  fiscal_sign?: string | null
  qr_code_url?: string | null
  payload?: FiscalReceiptPayload | null
  error_message?: string | null
  attempts?: number
  fiscalized_at?: string | null
  created_at?: string
  updated_at?: string
}

export type CreateFiscalReceiptPayload = {
  order_id: number
  payment_id?: number | null
  cash_shift_id?: number | null
  type?: FiscalReceiptType
  electronic: boolean
  buyer_email?: string | null
  buyer_phone?: string | null
  tax_system: TaxSystemCode
  vat_rate: VatRateCode
  items?: FiscalReceiptItem[]
  idempotency_key?: string
}

export type PaymentFiscalOptions = {
  electronic: boolean
  buyer_email: string
  buyer_phone: string
  tax_system: TaxSystemCode
  vat_rate: VatRateCode
}

export type PaymentConfirmPayload = {
  method: string
  payMode: 'single' | 'mixed'
  mixed?: { cash: number; card: number; transfer: number }
  tendered: number
  fiscal: PaymentFiscalOptions
}

export const TAX_SYSTEM_OPTIONS: Array<{ id: TaxSystemCode; label: string }> = [
  { id: 'osn', label: 'ОСН' },
  { id: 'usn_income', label: 'УСН доход' },
  { id: 'usn_income_outcome', label: 'УСН доход−расход' },
  { id: 'esn', label: 'ЕСХН' },
  { id: 'patent', label: 'Патент' },
]

export const VAT_RATE_OPTIONS: Array<{ id: VatRateCode; label: string }> = [
  { id: '20', label: 'НДС 20%' },
  { id: '10', label: 'НДС 10%' },
  { id: '0', label: 'НДС 0%' },
  { id: 'none', label: 'Без НДС' },
]

export const FISCAL_STATUS_LABEL: Record<FiscalReceiptStatus, string> = {
  pending: 'Фискализируется',
  fiscalized: 'Чек пробит',
  failed: 'Ошибка ОФД',
  refunded: 'Возвратный чек',
}
