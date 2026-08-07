/**
 * AUTOMETRIA ERP — Local Enterprise AI Pack (AirLLM) client
 */
import { api } from '@/autometria/api/client'

/**
 * @param {string} prompt
 * @returns {Promise<{ filters: Record<string, unknown>, interpretation: string, source: string }>}
 */
export async function nlpSearch(prompt) {
  const { data } = await api.post('/ai/nlp-search', { prompt })
  return data?.data ?? data
}

/**
 * @param {{ date?: string }} [params]
 * @returns {Promise<{ date: string, text: string, source: string, model: string|null }>}
 */
export async function fetchDailySummary(params = {}) {
  const { data } = await api.get('/ai/daily-summary', { params })
  return data?.data ?? data
}

/**
 * Map NLP result → SPA view id (+ optional query filters).
 * @param {string} prompt
 * @param {{ filters?: Record<string, unknown>, interpretation?: string }} result
 * @returns {{ view: string, filters: Record<string, unknown>, label: string }}
 */
export function resolveNlpNavigation(prompt, result = {}) {
  const filters = { ...(result.filters || {}) }
  const blob = `${prompt} ${result.interpretation || ''} ${JSON.stringify(filters)}`.toLowerCase()

  if (filters.view && typeof filters.view === 'string') {
    const v = String(filters.view)
    const labels = {
      demand_forecast: 'Прогноз спроса / риск стокаута',
      auto_orders: 'Авто-заказы',
      abc_xyz: 'ABC/XYZ матрица',
      warehouse: 'Склад и остатки',
      orders: 'Заказы и продажи',
      analytics: 'Аналитика',
    }
    return { view: v, filters, label: labels[v] || v }
  }

  if (/стокаут|сток.?аут|риск.*недел|rop|safety.?stock|перезаказ|дефицит/.test(blob)) {
    return { view: 'demand_forecast', filters, label: 'Прогноз спроса / риск стокаута' }
  }
  if (/авто.?заказ|умн.*закуп|поставщик|черновик.*заказ/.test(blob)) {
    return { view: 'auto_orders', filters, label: 'Авто-заказы' }
  }
  if (/abc|xyz|матриц/.test(blob)) {
    return { view: 'abc_xyz', filters, label: 'ABC/XYZ матрица' }
  }
  if (/списан|расход|expense/.test(blob) || filters.type === 'expense') {
    return { view: 'warehouse', filters, label: 'Склад / списания' }
  }
  if (/заказ|продаж|sale/.test(blob) || filters.type === 'sale') {
    return { view: 'orders', filters, label: 'Заказы и продажи' }
  }
  if (/склад|остат|инвентар/.test(blob)) {
    return { view: 'warehouse', filters, label: 'Склад и остатки' }
  }
  if (/касс|смен|pos/.test(blob)) {
    return { view: 'cashier', filters, label: 'Касса и смены' }
  }
  if (/закуп|пополнен/.test(blob)) {
    return { view: 'purchases', filters, label: 'Закупки' }
  }
  if (/аналит|выручк|марж|прибыл|kpi/.test(blob)) {
    return { view: 'analytics', filters, label: 'Аналитика' }
  }
  if (/crm|клиент|партн/.test(blob)) {
    return { view: 'crm', filters, label: 'Business Partner CRM' }
  }

  return { view: 'dashboard', filters, label: 'Дашборд' }
}

export const NLP_FILTERS_KEY = 'autometria.nlp.filters'
