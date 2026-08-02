/**
 * AUTOMETRIA ERP — Purchasing / Supplier orders (Sprint 2.1)
 */
import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'

export const usePurchasingStore = defineStore('purchasing', {
  state: () => ({
    suppliers: [],
    orders: [],
    replenishment: [],
    currentOrder: null,
    loading: false,
    saving: false,
    error: '',
    statusFilter: '',
  }),

  actions: {
    async fetchSuppliers() {
      const payload = await apiGet('/suppliers', { silent: true })
      this.suppliers = Array.isArray(payload?.data) ? payload.data : []
      return this.suppliers
    },

    async createSupplier(body) {
      const payload = await apiPost('/suppliers', body)
      await this.fetchSuppliers()
      return payload?.data
    },

    async fetchOrders(status = this.statusFilter) {
      this.loading = true
      this.error = ''
      try {
        const payload = await apiGet('/supplier-orders', {
          params: status ? { status } : undefined,
          silent: true,
        })
        this.orders = Array.isArray(payload?.data) ? payload.data : []
      } catch (e) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка загрузки заказов'
        this.orders = []
      } finally {
        this.loading = false
      }
      return this.orders
    },

    async createOrder(body) {
      this.saving = true
      this.error = ''
      try {
        const payload = await apiPost('/supplier-orders', body)
        this.currentOrder = payload?.data || null
        await this.fetchOrders()
        return this.currentOrder
      } catch (e) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка создания заказа'
        throw e
      } finally {
        this.saving = false
      }
    },

    async confirmOrder(id) {
      this.saving = true
      this.error = ''
      try {
        const payload = await apiPost(`/supplier-orders/${id}/confirm`, {})
        this.currentOrder = payload?.data || null
        await this.fetchOrders()
        return this.currentOrder
      } catch (e) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка подтверждения'
        throw e
      } finally {
        this.saving = false
      }
    },

    async receiveOrder(id, items) {
      this.saving = true
      this.error = ''
      try {
        const payload = await apiPost(`/supplier-orders/${id}/receive`, { items })
        this.currentOrder = payload?.data || null
        await this.fetchOrders()
        return this.currentOrder
      } catch (e) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка приёмки'
        throw e
      } finally {
        this.saving = false
      }
    },

    async fetchReplenishment(warehouseId) {
      this.loading = true
      this.error = ''
      try {
        const payload = await apiGet('/purchases/replenishment-plan', {
          params: { warehouse_id: warehouseId },
          silent: true,
        })
        this.replenishment = Array.isArray(payload?.data) ? payload.data : []
      } catch (e) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка плана пополнения'
        this.replenishment = []
      } finally {
        this.loading = false
      }
      return this.replenishment
    },
  },
})
