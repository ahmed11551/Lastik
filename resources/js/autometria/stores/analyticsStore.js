import { defineStore } from 'pinia'
import { apiGet } from '../api/client'

function defaultRange() {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 29)
  const fmt = (d) => d.toISOString().slice(0, 10)
  return { date_from: fmt(from), date_to: fmt(to) }
}

export const useAnalyticsStore = defineStore('analytics', {
  state: () => ({
    summary: null,
    cogsBreakdown: [],
    turnover: null,
    salesSeries: [],
    topProducts: [],
    abcXyz: null,
    loading: false,
    error: null,
    dateFrom: defaultRange().date_from,
    dateTo: defaultRange().date_to,
    warehouseId: null,
  }),
  getters: {
    hasData: (s) => s.summary != null,
  },
  actions: {
    setRange(dateFrom, dateTo) {
      this.dateFrom = dateFrom
      this.dateTo = dateTo
    },
    setWarehouse(id) {
      this.warehouseId = id || null
    },
    params() {
      const params = {
        from: this.dateFrom,
        to: this.dateTo,
      }
      if (this.warehouseId) params.warehouse_id = this.warehouseId
      return params
    },
    async fetchAll() {
      this.loading = true
      this.error = null
      try {
        const params = this.params()
        const [dashboard, series] = await Promise.all([
          apiGet('/analytics/dashboard', { params, silent: true }),
          apiGet('/analytics/sales-series', { params, silent: true }),
        ])
        const data = dashboard?.data ?? dashboard ?? {}
        this.summary = {
          revenue: data.net_revenue ?? data.revenue,
          net_revenue: data.net_revenue ?? data.revenue,
          gross_sales: data.gross_sales,
          refunds_total: data.refunds_total,
          cogs: data.cogs,
          gross_profit: data.gross_profit ?? data.net_profit,
          margin_pct: data.margin_pct,
          avg_check: data.avg_check,
          orders_count: data.orders_count,
          revenue_delta_pct: data.revenue_delta_pct,
        }
        this.cogsBreakdown = Array.isArray(data.top_products) ? data.top_products : []
        this.topProducts = this.cogsBreakdown
        this.turnover = {
          turnover_ratio: data.turnover_rate,
          average_inventory_value: data.stock_value ?? data.average_inventory_value,
          deadstock: data.deadstock || [],
        }
        this.abcXyz = data.abc_xyz ?? null
        this.salesSeries = Array.isArray(series?.data) ? series.data : Array.isArray(series) ? series : []
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        this.summary = null
        this.cogsBreakdown = []
        this.topProducts = []
        this.turnover = null
        this.abcXyz = null
        this.salesSeries = []
        throw e
      } finally {
        this.loading = false
      }
    },
  },
})
