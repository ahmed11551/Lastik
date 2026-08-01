import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'
import { applyBulkError, applyBulkSuccess, assertBulkIds, normalizeBulkIds } from '../api/bulk'
import { toast } from '../api/toast'
import { getStoredUser } from '../api/client'

export const useOrdersStore = defineStore('orders', {
  state: () => ({
    rows: [],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    loading: false,
    creating: false,
    bulkPending: false,
    error: null,
    query: '',
    status: 'all',
    degraded: false,
  }),
  actions: {
    async fetchOrders(overrides = {}) {
      if (overrides.q !== undefined) this.query = overrides.q
      if (overrides.status !== undefined) this.status = overrides.status
      if (overrides.page !== undefined) this.meta.current_page = overrides.page

      this.loading = true
      this.error = null
      try {
        const payload = await apiGet('/orders', {
          silent: true,
          params: {
            q: this.query || undefined,
            status: this.status !== 'all' ? this.status : undefined,
            page: this.meta.current_page || 1,
            per_page: this.meta.per_page || 50,
          },
        })
        this.rows = Array.isArray(payload?.data) ? payload.data : []
        this.meta = { ...this.meta, ...(payload?.meta || {}) }
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        this.rows = []
        this.degraded = true
        throw e
      } finally {
        this.loading = false
      }
    },

    async createOrder(payload) {
      this.creating = true
      try {
        const user = getStoredUser()
        const body = {
          assigned_seller_id: payload.assigned_seller_id || user?.id,
          customer_id: payload.customer_id || undefined,
          vehicle_id: payload.vehicle_id || undefined,
          scenario: payload.scenario || 'without_installation',
          items: payload.items,
          note: payload.note,
        }
        const res = await apiPost('/orders', body)
        toast.success('Заказ создан', 'Orders')
        await this.fetchOrders({ page: 1 })
        return res
      } finally {
        this.creating = false
      }
    },

    /**
     * POST /orders/bulk-status
     * @param {number[]} ids
     * @param {string} status - CRM alias: accepted|negotiation|rejected|review|prospective
     * @param {{ clearSelection?: () => void }} [opts]
     */
    async bulkStatus(ids, status, opts = {}) {
      const intIds = normalizeBulkIds(ids)
      if (!assertBulkIds(intIds)) return null
      this.bulkPending = true
      try {
        const res = await apiPost(
          '/orders/bulk-status',
          { ids: intIds, status },
          { silent: true },
        )
        await applyBulkSuccess(res, {
          clearSelection: opts.clearSelection,
          refresh: () => this.fetchOrders(),
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
