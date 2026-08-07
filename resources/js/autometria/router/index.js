/**
 * AUTOMETRIA ERP — SPA hash route table (no vue-router runtime).
 * Used by App.vue normalizeView / AutometriaLayout nav.
 *
 * Analytics & AI + Procurement routes for v1.4.0+.
 */

/** @typedef {{ id: string, label: string, section?: string, highlight?: boolean }} RouteItem */

/** @type {RouteItem[]} */
export const analyticsAiRoutes = [
  { id: 'analytics', label: 'Аналитика (дашборд)', section: 'analytics-ai' },
  { id: 'abc_xyz', label: 'ABC/XYZ матрица', section: 'analytics-ai', highlight: true },
  { id: 'demand_forecast', label: 'Прогноз спроса', section: 'analytics-ai', highlight: true },
]

/** @type {RouteItem[]} */
export const procurementRoutes = [
  { id: 'auto_orders', label: 'Авто-заказы', section: 'warehouse', highlight: true },
]

/** Nested hash paths written to location.hash */
export const nestedHashByView = {
  demand_forecast: 'analytics/demand_forecast',
  auto_orders: 'procurement/auto_orders',
}

/** @type {Record<string, string>} */
export const analyticsAiTitles = {
  analytics: 'Аналитика',
  abc_xyz: 'ABC/XYZ анализ',
  demand_forecast: 'Прогноз спроса',
  auto_orders: 'Авто-заказы',
}

/**
 * @param {string} raw
 * @returns {boolean}
 */
export function isAnalyticsAiRoute(raw) {
  return raw === 'analytics' || raw === 'abc_xyz' || raw === 'demand_forecast'
    || raw === 'analytics/demand_forecast'
}

/**
 * @param {string} raw
 * @returns {boolean}
 */
export function isProcurementRoute(raw) {
  return raw === 'auto_orders' || raw === 'procurement/auto_orders'
}

export default {
  analyticsAiRoutes,
  procurementRoutes,
  nestedHashByView,
  analyticsAiTitles,
  isAnalyticsAiRoute,
  isProcurementRoute,
}
