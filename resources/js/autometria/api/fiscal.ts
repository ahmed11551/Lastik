/**
 * AUTOMETRIA ERP — Fiscal receipts API (/api/v1/fiscal-receipts)
 */
import { apiGet, apiPost } from './client'
import type { CreateFiscalReceiptPayload, FiscalReceipt } from '../types/fiscal'

function unwrapOne(payload: unknown): FiscalReceipt | null {
  if (!payload || typeof payload !== 'object') return null
  const p = payload as { data?: FiscalReceipt | FiscalReceipt[] }
  if (Array.isArray(p.data)) return p.data[0] || null
  return p.data || (payload as FiscalReceipt)
}

function unwrapList(payload: unknown): FiscalReceipt[] {
  if (!payload || typeof payload !== 'object') return []
  const p = payload as { data?: FiscalReceipt[] }
  return Array.isArray(p.data) ? p.data : []
}

export async function apiFetchReceiptByOrder(orderId: number): Promise<FiscalReceipt | null> {
  const payload = await apiGet('/fiscal-receipts', {
    params: { order_id: orderId },
    silent: true,
  })
  return unwrapOne(payload)
}

export async function apiFetchReceiptById(id: number): Promise<FiscalReceipt | null> {
  const payload = await apiGet(`/fiscal-receipts/${id}`, { silent: true })
  return unwrapOne(payload)
}

export async function apiFetchShiftReceipts(shiftId: number): Promise<FiscalReceipt[]> {
  const payload = await apiGet('/fiscal-receipts', {
    params: { cash_shift_id: shiftId },
    silent: true,
  })
  return unwrapList(payload)
}

export async function apiCreateFiscalReceipt(
  body: CreateFiscalReceiptPayload,
): Promise<FiscalReceipt | null> {
  const payload = await apiPost('/fiscal-receipts', body)
  return unwrapOne(payload)
}

export async function apiRetryFiscalization(receiptId: number): Promise<FiscalReceipt | null> {
  const payload = await apiPost(`/fiscal-receipts/${receiptId}/retry`, {})
  return unwrapOne(payload)
}
