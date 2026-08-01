import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'
import { toast } from '../api/toast'

export const useShiftStore = defineStore('shift', {
  state: () => ({
    open: false,
    shiftId: null,
    startedAt: null,
    revenue: 0,
    openingAmount: 0,
    loading: false,
    error: null,
    degraded: false,
  }),
  actions: {
    async fetchCurrent() {
      this.loading = true
      this.error = null
      try {
        const payload = await apiGet('/shifts/current', { silent: true })
        const d = payload?.data || {}
        this.open = Boolean(d.open)
        this.shiftId = d.id || null
        this.startedAt = d.opened_at || null
        this.revenue = Number(d.revenue || 0)
        this.openingAmount = Number(d.opening_amount || 0)
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        this.degraded = true
        throw e
      } finally {
        this.loading = false
      }
    },

    async openShift(openingAmount = 0) {
      const res = await apiPost('/shifts/open', { opening_amount: openingAmount })
      toast.success('Смена открыта', 'Shift')
      await this.fetchCurrent()
      return res.data
    },

    async closeShift(payload = {}) {
      const res = await apiPost('/shifts/close', {
        shift_id: this.shiftId || undefined,
        ...payload,
      })
      toast.success('Смена закрыта', 'Shift')
      await this.fetchCurrent()
      return res.data
    },
  },
})

export const useCashierStore = defineStore('cashier', {
  state: () => ({
    cart: [],
    catalog: [],
    selectedPay: 'cash',
    tendered: 0,
    lastOp: { status: 'pending', label: 'Ожидает оплаты' },
    loading: false,
    checkingOut: false,
    degraded: false,
    error: null,
  }),
  getters: {
    totalDue: (s) => s.cart.reduce((sum, r) => sum + Number(r.line || 0), 0),
    itemsCount: (s) => s.cart.reduce((sum, r) => sum + Number(r.qty || 0), 0),
  },
  actions: {
    async fetchCatalog() {
      this.loading = true
      try {
        const payload = await apiGet('/stock', { params: { per_page: 50 }, silent: true })
        const rows = Array.isArray(payload?.data) ? payload.data : []
        this.catalog = rows.map((r) => ({
          product_id: r.product_id || r.id,
          stock_id: r.id,
          sku: r.sku,
          oem: r.oem,
          name: r.name,
          price: Number(r.price || 0),
          available: Number(r.available || 0),
          warehouse_id: r.warehouse_id,
        }))
        // Prefer products endpoint for product_id
        try {
          const products = await apiGet('/products', { silent: true })
          const list = Array.isArray(products?.data) ? products.data : []
          if (list.length) {
            this.catalog = list.slice(0, 50).map((p) => ({
              product_id: p.id,
              sku: p.article || `ID-${p.id}`,
              oem: p.external_id || '—',
              name: p.name,
              price: Number(p.base_price || 0),
              available: null,
              warehouse_id: null,
            }))
          }
        } catch {
          /* keep stock catalog */
        }
        this.degraded = false
      } catch (e) {
        this.degraded = true
        this.error = e.message
      } finally {
        this.loading = false
      }
    },

    seedDemoCart() {
      if (this.cart.length) return
      if (this.catalog.length) {
        this.cart = this.catalog.slice(0, 4).map((p, i) => {
          const qty = i === 0 ? 2 : 1
          const discount = i === 2 ? 10 : 0
          const line = Math.round(p.price * qty * (1 - discount / 100))
          return {
            id: i + 1,
            n: i + 1,
            product_id: p.product_id,
            warehouse_id: p.warehouse_id,
            sku: p.sku,
            oem: p.oem,
            name: p.name,
            qty,
            discount,
            line,
          }
        })
        return
      }
      this.cart = []
    },

    setPay(method) {
      this.selectedPay = method
      this.lastOp = { status: 'pending', label: 'Способ выбран' }
    },

    async checkout() {
      if (!this.cart.length) {
        this.lastOp = { status: 'warning', label: 'Корзина пуста' }
        return null
      }
      this.checkingOut = true
      try {
        const items = this.cart.map((r) => ({
          product_id: r.product_id,
          qty: r.qty,
          discount: r.discount || 0,
          warehouse_id: r.warehouse_id || undefined,
          type: 'product',
        }))
        const payload = await apiPost('/pos/checkout', {
          items,
          method: this.selectedPay,
          amount_tendered: this.tendered || this.totalDue,
        })
        const change = payload?.data?.change ?? 0
        this.lastOp = {
          status: 'success',
          label: change > 0 ? `Оплачено · сдача ₽${change}` : 'Оплачено',
        }
        toast.success(this.lastOp.label, 'POS')
        this.cart = []
        return payload.data
      } catch (e) {
        this.lastOp = {
          status: 'danger',
          label: e.response?.data?.message || 'Ошибка оплаты',
        }
        throw e
      } finally {
        this.checkingOut = false
      }
    },
  },
})
