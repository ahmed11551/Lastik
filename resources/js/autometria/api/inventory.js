/**
 * AUTOMETRIA ERP — Smart Purchases (AI reorder recommendations)
 */
import { api } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'

/**
 * @param {{ warehouse_id?: number|string, severity?: string, dead_stock?: boolean, per_page?: number }} params
 */
export async function fetchReorderRecommendations(params = {}) {
  const { data } = await api.get('/inventory/reorder-recommendations', { params })
  return data
}

/**
 * @param {{ warehouse_id?: number, lookback_days?: number, lead_time_days?: number, sync?: boolean }} body
 */
export async function recalculateReorderRecommendations(body = {}) {
  const { data } = await api.post('/inventory/reorder-recommendations/recalculate', body)
  return data
}

export async function runSmartPurchasesRecalc(warehouseId) {
  try {
    const res = await recalculateReorderRecommendations({
      warehouse_id: warehouseId || undefined,
      sync: true,
    })
    toast.success(`Пересчитано: ${res.upserted ?? 0} SKU`, 'Умные закупки')
    return res
  } catch (e) {
    toast.error(e?.response?.data?.message || 'Не удалось пересчитать ROP', 'Умные закупки')
    throw e
  }
}
