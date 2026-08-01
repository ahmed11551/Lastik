/**
 * Shared helpers for DsTable bulk toolbar → /api/v1 bulk endpoints
 * Backend: POST /orders/bulk-status · POST /stock/bulk-update
 */
import { toast } from './toast'

export type BulkSuccessResponse = {
  success?: boolean
  updated_count?: number
  message?: string
  action?: string
}

export type BulkClearOpts = {
  clearSelection?: () => void
  refresh?: () => Promise<unknown> | void
}

export async function applyBulkSuccess(
  res: BulkSuccessResponse | null | undefined,
  { clearSelection, refresh }: BulkClearOpts = {},
): Promise<boolean> {
  if (!res?.success) {
    toast.error(res?.message || 'Операция не выполнена')
    return false
  }
  toast.success(res.message || `Успешно обновлено ${res.updated_count ?? 0} записей`)
  clearSelection?.()
  await refresh?.()
  return true
}

export function applyBulkError(e: unknown): void {
  const err = e as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } }
  if (err?.response?.status === 422) {
    const errs = err.response.data?.errors
    toast.error(errs?.ids?.[0] || err.response.data?.message || 'Ошибка валидации')
    return
  }
  if (!err?.response) {
    toast.error('Нет связи с сервером')
    return
  }
  toast.error(err.response?.data?.message || 'Ошибка сервера')
}

/** Normalize selection to positive integers before POST. */
export function normalizeBulkIds(ids: Array<string | number | null | undefined>): number[] {
  return (ids || [])
    .map((id) => Number(id))
    .filter((n) => Number.isInteger(n) && n > 0)
}

export function assertBulkIds(ids: number[]): boolean {
  if (!ids.length) {
    toast.warning('Не выбрана ни одна запись')
    return false
  }
  return true
}

export const CRM_BULK_STATUSES = [
  { value: 'accepted', label: 'Accepted' },
  { value: 'negotiation', label: 'Negotiation' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'review', label: 'Review' },
  { value: 'prospective', label: 'Prospective' },
] as const

export type CrmBulkStatus = (typeof CRM_BULK_STATUSES)[number]['value']
