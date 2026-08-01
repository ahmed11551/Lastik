import { defineStore } from 'pinia'
import { apiGet } from '../api/client'

export const useAuditStore = defineStore('audit', {
  state: () => ({
    rows: [],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    loading: false,
    error: null,
    query: '',
    category: 'all',
    degraded: false,
  }),
  getters: {
    isEmpty: (s) => !s.loading && s.rows.length === 0,
  },
  actions: {
    async fetchLogs(overrides = {}) {
      if (overrides.q !== undefined) this.query = overrides.q
      if (overrides.category !== undefined) this.category = overrides.category
      if (overrides.page !== undefined) this.meta.current_page = overrides.page

      this.loading = true
      this.error = null
      try {
        const params = {
          q: this.query || undefined,
          category: this.category !== 'all' ? this.category : undefined,
          page: this.meta.current_page || 1,
          per_page: this.meta.per_page || 50,
        }
        const payload = await apiGet('/audit-logs', { params, silent: true })
        this.rows = Array.isArray(payload?.data) ? payload.data : []
        this.meta = {
          ...this.meta,
          ...(payload?.meta || {}),
        }
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message || 'Не удалось загрузить аудит'
        this.rows = []
        this.degraded = true
        throw e
      } finally {
        this.loading = false
      }
    },
  },
})
