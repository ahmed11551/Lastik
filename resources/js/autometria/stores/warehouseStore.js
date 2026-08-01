import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'
import { applyBulkError, applyBulkSuccess, assertBulkIds, normalizeBulkIds } from '../api/bulk'

export const useWarehouseStore = defineStore('warehouse', {
  state: () => ({
    rows: [],
    warehouses: [],
    categories: [],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    loading: false,
    bulkPending: false,
    error: null,
    query: '',
    category: 'all',
    warehouse: 'all',
    degraded: false,
  }),
  getters: {
    isEmpty: (s) => !s.loading && s.rows.length === 0,
  },
  actions: {
    async fetchWarehouses() {
      try {
        const payload = await apiGet('/warehouses', { silent: true })
        const list = Array.isArray(payload?.data) ? payload.data : []
        this.warehouses = list.map((w) => ({
          id: w.id,
          name: w.name || `WH-${w.id}`,
        }))
      } catch {
        this.warehouses = []
      }
    },

    async fetchStock(overrides = {}) {
      if (overrides.q !== undefined) this.query = overrides.q
      if (overrides.category !== undefined) this.category = overrides.category
      if (overrides.warehouse !== undefined) this.warehouse = overrides.warehouse
      if (overrides.page !== undefined) this.meta.current_page = overrides.page

      this.loading = true
      this.error = null
      try {
        const params = {
          q: this.query || undefined,
          category: this.category !== 'all' ? this.category : undefined,
          warehouse: this.warehouse !== 'all' ? this.warehouse : undefined,
          page: this.meta.current_page || 1,
          per_page: this.meta.per_page || 50,
        }
        const payload = await apiGet('/stock', { params, silent: true })
        this.rows = Array.isArray(payload?.data) ? payload.data : []
        this.meta = { ...this.meta, ...(payload?.meta || {}) }
        if (Array.isArray(payload?.meta?.categories)) {
          this.categories = payload.meta.categories
        }
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message || 'Не удалось загрузить склад'
        this.rows = []
        this.degraded = true
        // Soft fallback: try products list for degraded UI signal
        try {
          await apiGet('/products', { params: { q: this.query || undefined }, silent: true })
        } catch {
          /* ignore */
        }
        throw e
      } finally {
        this.loading = false
      }
    },

    /**
     * POST /stock/bulk-update
     * @param {number[]} ids
     * @param {'update_category'|'adjust_actual'|'adjust_reserved'} action
     * @param {{ category?: string, adjustment?: number }} payload
     * @param {{ clearSelection?: () => void }} [opts]
     */
    async bulkUpdate(ids, action, payload, opts = {}) {
      const intIds = normalizeBulkIds(ids)
      if (!assertBulkIds(intIds)) return null
      this.bulkPending = true
      try {
        const res = await apiPost(
          '/stock/bulk-update',
          { ids: intIds, action, payload },
          { silent: true },
        )
        await applyBulkSuccess(res, {
          clearSelection: opts.clearSelection,
          refresh: () => this.fetchStock(),
        })
        return res
      } catch (e) {
        applyBulkError(e)
        throw e
      } finally {
        this.bulkPending = false
      }
    },
  },
})
