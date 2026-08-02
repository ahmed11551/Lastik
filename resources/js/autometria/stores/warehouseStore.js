import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'
import { applyBulkError, applyBulkSuccess, assertBulkIds, normalizeBulkIds } from '../api/bulk'
import { toast } from '../api/toast'

export const useWarehouseStore = defineStore('warehouse', {
  state: () => ({
    rows: [],
    warehouses: [],
    categories: [],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    loading: false,
    bulkPending: false,
    opPending: false,
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

    async fetchBatches(productId, warehouseId) {
      const payload = await apiGet('/stock/batches', {
        params: { product_id: productId, warehouse_id: warehouseId },
        silent: true,
      })
      return Array.isArray(payload?.data) ? payload.data : []
    },

    /**
     * POST /stock/inventory-adjust
     */
    async inventoryAdjust({ product_id, warehouse_id, stock_id, actual_qty, reason }) {
      this.opPending = true
      try {
        const res = await apiPost(
          '/stock/inventory-adjust',
          { product_id, warehouse_id, stock_id, actual_qty, reason },
          { silent: true },
        )
        const d = res?.data || {}
        toast.success(d.message || 'Инвентаризация проведена', 'Склад')
        await this.fetchStock()
        return res
      } catch (e) {
        applyBulkError(e)
        throw e
      } finally {
        this.opPending = false
      }
    },

    /**
     * POST /stock/transfers — one call per line (backend contract).
     */
    async transferStock({ from_warehouse_id, to_warehouse_id, reason, items }) {
      this.opPending = true
      try {
        let ok = 0
        for (const item of items || []) {
          await apiPost(
            '/stock/transfers',
            {
              product_id: item.product_id,
              from_warehouse_id,
              to_warehouse_id,
              qty: item.qty,
              reason,
            },
            { silent: true },
          )
          ok += 1
        }
        toast.success(`Перемещено позиций: ${ok}`, 'Склад')
        await this.fetchStock()
        return { success: true, count: ok }
      } catch (e) {
        applyBulkError(e)
        throw e
      } finally {
        this.opPending = false
      }
    },

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
