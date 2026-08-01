import { defineStore } from 'pinia'
import { apiGet } from '../api/client'

export const useKpiStore = defineStore('kpi', {
  state: () => ({
    cards: [],
    rows: [],
    loading: false,
    error: null,
    degraded: false,
  }),
  actions: {
    async fetchSummary() {
      this.loading = true
      this.error = null
      try {
        const payload = await apiGet('/kpi/summary', { silent: true })
        this.cards = payload?.data?.cards || []
        this.rows = payload?.data?.rows || []
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        this.cards = []
        this.rows = []
        this.degraded = true
        throw e
      } finally {
        this.loading = false
      }
    },
  },
})
