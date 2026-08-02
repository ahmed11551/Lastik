import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'

export const DOC_TYPES = ['RECEIPT', 'WRITE_OFF', 'TRANSFER', 'INVENTORY']
export const DOC_STATUSES = ['DRAFT', 'POSTED']

/**
 * Inventory stock documents store (Приход / Списание / Перемещение / Инвентаризация).
 * Backend contract: POST /api/v1/inventory/documents, POST /api/v1/inventory/documents/{id}/post
 */
export const useInventoryDocumentsStore = defineStore('inventoryDocuments', {
  state: () => ({
    documents: [],
    loading: false,
    error: null,
    filters: {
      type: '',
      status: '',
      date_from: '',
      date_to: '',
    },
  }),
  getters: {
    filtered: (s) => s.documents,
  },
  actions: {
    setFilter(key, value) {
      this.filters[key] = value
    },
    async fetchAll() {
      this.loading = true
      this.error = null
      try {
        const params = {}
        if (this.filters.type) params.type = this.filters.type
        if (this.filters.status) params.status = this.filters.status
        if (this.filters.date_from) params.date_from = this.filters.date_from
        if (this.filters.date_to) params.date_to = this.filters.date_to
        const resp = await apiGet('/inventory/documents', { params, silent: true })
        const list = Array.isArray(resp?.data) ? resp.data : Array.isArray(resp) ? resp : []
        this.documents = list.map((d) => ({
          id: d.id,
          number: d.number || `DOC-${d.id}`,
          type: d.type,
          status: d.status,
          warehouse_id: d.warehouse_id ?? d.to_warehouse_id ?? null,
          created_at: d.created_at,
          total: d.total_amount ?? d.total ?? 0,
          items_count: Array.isArray(d.items) ? d.items.length : 0,
        }))
      } catch (e) {
        this.error = e?.response?.data?.message || e?.message || 'Ошибка загрузки документов'
        this.documents = []
      } finally {
        this.loading = false
      }
    },
    async createDraft(payload) {
      const resp = await apiPost('/inventory/documents', { ...payload, status: 'DRAFT' })
      return resp?.data ?? resp
    },
    async postDocument(id) {
      const resp = await apiPost(`/inventory/documents/${id}/post`, {})
      return resp?.data ?? resp
    },
  },
})
