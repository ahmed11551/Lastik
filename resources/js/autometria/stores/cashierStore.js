import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'
import { toast } from '../api/toast'
import { useOfflineStore } from '../../stores/useOfflineStore'
import { getStoredUser } from '../api/client'

export const useShiftStore = defineStore('shift', {
  state: () => ({
    open: false,
    shiftId: null,
    startedAt: null,
    revenue: 0,
    openingAmount: 0,
    expectedCash: 0,
    totals: {
      cash: 0,
      card: 0,
      transfer: 0,
      deposit: 0,
      inkasso: 0,
      withdrawal: 0,
    },
    loading: false,
    mutating: false,
    error: null,
    degraded: false,
  }),
  getters: {
    cashSales: (s) => Number(s.totals?.cash || 0),
    deposits: (s) => Number(s.totals?.deposit || 0),
    withdrawals: (s) => Number(s.totals?.withdrawal || 0),
    inkasso: (s) => Number(s.totals?.inkasso || 0),
  },
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
        this.expectedCash = Number(d.expected_cash || 0)
        this.totals = {
          cash: Number(d.totals?.cash || 0),
          card: Number(d.totals?.card || 0),
          transfer: Number(d.totals?.transfer || 0),
          deposit: Number(d.totals?.deposit || 0),
          inkasso: Number(d.totals?.inkasso || 0),
          withdrawal: Number(d.totals?.withdrawal || 0),
        }
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        this.degraded = true
        throw e
      } finally {
        this.loading = false
      }
    },

    async openShift(openingAmount = 0, note) {
      this.mutating = true
      try {
        const res = await apiPost('/shifts/open', {
          opening_amount: openingAmount,
          note: note || undefined,
        })
        toast.success('Смена открыта', 'Shift')
        await this.fetchCurrent()
        return res.data
      } finally {
        this.mutating = false
      }
    },

    async closeShift(payload = {}) {
      this.mutating = true
      try {
        const res = await apiPost('/shifts/close', {
          shift_id: this.shiftId || undefined,
          ...payload,
        })
        toast.success('Смена закрыта · Z-отчёт снят', 'Shift')
        await this.fetchCurrent()
        return res.data
      } finally {
        this.mutating = false
      }
    },

    async cashMovement({ type, amount, reason }) {
      this.mutating = true
      try {
        const res = await apiPost('/shifts/movements', {
          type,
          amount,
          reason,
          shift_id: this.shiftId || undefined,
        })
        const label = type === 'deposit' ? 'Внесение' : type === 'withdrawal' ? 'Выемка' : 'Инкассация'
        toast.success(`${label} ₽${Number(amount).toLocaleString('ru-RU')}`, 'Касса')
        await this.fetchCurrent()
        return res.data
      } finally {
        this.mutating = false
      }
    },
  },
})

export const useCashierStore = defineStore('cashier', {
  state: () => ({
    cart: [],
    catalog: [],
    selectedPay: 'cash',
    tendered: 0,
    productQuery: '',
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
          barcode: r.barcode || r.sku || r.oem,
          name: r.name,
          price: Number(r.price || 0),
          available: Number(r.available || 0),
          warehouse_id: r.warehouse_id,
        }))
        try {
          const products = await apiGet('/products', { silent: true })
          const list = Array.isArray(products?.data) ? products.data : []
          if (list.length) {
            this.catalog = list.slice(0, 80).map((p) => ({
              product_id: p.id,
              sku: p.article || `ID-${p.id}`,
              oem: p.external_id || '—',
              barcode: p.barcode || p.article || String(p.id),
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
            barcode: p.barcode,
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

    /**
     * EAN-8/13 / 8–14 digits barcode latch → add or bump qty in cart.
     */
    addByBarcode(raw) {
      const code = String(raw || '').trim()
      if (!/^\d{8,14}$/.test(code)) {
        return { ok: false, reason: 'not_barcode' }
      }

      const product = this.catalog.find((p) => {
        const keys = [p.barcode, p.sku, p.oem, String(p.product_id)]
          .filter(Boolean)
          .map((k) => String(k).trim())
        return keys.includes(code)
      })

      if (!product) {
        this.lastOp = { status: 'warning', label: `Штрихкод ${code} не найден` }
        toast.warning(`Штрихкод ${code} не найден в каталоге`, 'Scan')
        return { ok: false, reason: 'not_found', code }
      }

      const existing = this.cart.find((r) => Number(r.product_id) === Number(product.product_id))
      if (existing) {
        existing.qty = Number(existing.qty || 0) + 1
        existing.line = Math.round(product.price * existing.qty * (1 - Number(existing.discount || 0) / 100))
      } else {
        const n = this.cart.length + 1
        this.cart.push({
          id: Date.now(),
          n,
          product_id: product.product_id,
          warehouse_id: product.warehouse_id,
          sku: product.sku,
          oem: product.oem,
          barcode: product.barcode,
          name: product.name,
          qty: 1,
          discount: 0,
          line: Math.round(product.price),
        })
      }

      this.productQuery = ''
      this.lastOp = { status: 'success', label: `+ ${product.name}` }
      toast.success(`Добавлено: ${product.name}`, 'Scan')
      return { ok: true, product }
    },

    /**
     * POS checkout. Optional `options.vat_rate` is sent per line for 54-ФЗ.
     * @param {{ method?: string, tendered?: number, vat_rate?: string }} [options]
     */
    async checkout(options = {}) {
      if (!this.cart.length) {
        this.lastOp = { status: 'warning', label: 'Корзина пуста' }
        return null
      }
      this.checkingOut = true
      const vatRate = options.vat_rate || 'none'
      const method = options.method || this.selectedPay
      const tendered = options.tendered ?? this.tendered ?? this.totalDue
      /** Snapshot for fiscal payload before cart clear */
      const cartSnapshot = this.cart.map((r) => ({ ...r }))
      const buildItems = (rows) =>
        rows.map((r) => ({
          product_id: r.product_id,
          qty: r.qty,
          discount: r.discount || 0,
          warehouse_id: r.warehouse_id || undefined,
          type: 'product',
          vat_rate: r.vat_rate || vatRate,
          marking_code: r.marking_code || r.markingCode || undefined,
          gtin: r.gtin || undefined,
          serial_number: r.serial_number || undefined,
        }))
      try {
        const payload = await apiPost('/pos/checkout', {
          items: buildItems(cartSnapshot),
          method,
          amount_tendered: tendered,
        })
        const change = payload?.data?.change ?? 0
        this.lastOp = {
          status: 'success',
          label: change > 0 ? `Оплачено · сдача ₽${change}` : 'Оплачено',
        }
        toast.success(this.lastOp.label, 'POS')
        this.cart = []
        this.selectedPay = method
        return {
          ...payload.data,
          cart_snapshot: cartSnapshot,
          vat_rate: vatRate,
        }
      } catch (e) {
        // Offline-first: queue the receipt locally if network is down or ERP unreachable.
        const offline = useOfflineStore()
        const user = getStoredUser() || {}
        const isNetworkError =
          !navigator.onLine ||
          (e && (e.code === 'NETWORK_ERROR' || e.message?.includes('Network')))
        if (isNetworkError || !navigator.onLine) {
          const saved = await offline.saveLocalReceipt({
            tenant_id: Number(user.tenant_id || 0),
            shift_id: Number(this.shiftId || 0),
            cashier_id: Number(user.id || 0),
            items: buildItems(cartSnapshot).map((i) => ({
              product_id: i.product_id,
              qty: i.qty,
              price: cartSnapshot.find((r) => r.product_id === i.product_id)?.price || 0,
              discount: i.discount,
              vat_rate: i.vat_rate,
              warehouse_id: i.warehouse_id,
              marking_code: i.marking_code || null,
              gtin: i.gtin || null,
              serial_number: i.serial_number || null,
              is_marked: Boolean(i.marking_code),
            })),
            total_amount: Number(this.totalDue || 0),
            amount_tendered: tendered,
            payment_type: method === 'card' ? 'CARD' : method === 'mixed' ? 'MIXED' : 'CASH',
            requires_fiscal_marking: cartSnapshot.some((r) => r.marking_code),
            customer_id: this.selectedCustomer?.id || undefined,
            bonus_spend: this.bonusSpend || undefined,
          })
          this.cart = []
          this.lastOp = {
            status: 'warning',
            label: 'Офлайн — чек в очереди синхронизации',
          }
          toast.warning(this.lastOp.label, 'POS Offline')
          await offline.refreshCounts()
          return {
            offline: true,
            uuid: saved.uuid,
            cart_snapshot: cartSnapshot,
            vat_rate: vatRate,
            total: Number(this.totalDue || 0),
          }
        }
        this.lastOp = {
          status: 'danger',
          label: e.response?.data?.message || 'Ошибка оплаты',
        }
        throw e
      } finally {
        this.checkingOut = false
      }
    },

    /**
     * Build fiscal line items from cart snapshot.
     * @param {Array} cartRows
     * @param {string} vatRate
     */
    buildFiscalItems(cartRows, vatRate = 'none') {
      return (cartRows || []).map((r) => {
        const qty = Number(r.qty || 0)
        const sum = Number(r.line || 0)
        const price = qty > 0 ? Math.round((sum / qty) * 100) / 100 : Number(r.price || 0)
        return {
          name: r.name || r.sku || `ID ${r.product_id}`,
          qty,
          price,
          sum,
          vat_rate: r.vat_rate || vatRate,
          discount: Number(r.discount || 0),
        }
      })
    },
  },
})
