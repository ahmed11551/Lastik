/**
 * AUTOMETRIA ERP — POS receipt print types (54-ФЗ)
 */

export type PosReceiptLine = {
  title: string
  qty: number
  price: number
  sum: number
  vat_rate: string
}

export type PosReceipt = {
  organization_name: string
  inn: string
  kkt_address: string
  shift_number: string | number
  receipt_number: string | number
  cashier_name: string
  datetime: string
  items: PosReceiptLine[]
  total: number
  cash_amount?: number
  card_amount?: number
  change?: number
  fn?: string | null
  fd?: string | null
  fpd?: string | null
  qr_payload?: string | null
  paper_mm?: 58 | 80
}

export type PrinterMode = 'escpos' | 'browser' | 'websocket'

export interface PrinterDriver {
  printReceipt(receiptData: PosReceipt): Promise<boolean>
}
