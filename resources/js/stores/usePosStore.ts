/**
 * AUTOMETRIA ERP — POS cart / catalog store
 */
import { defineStore } from 'pinia'
import { apiGet, apiPost, getStoredUser } from '../autometria/api/client'
import { toast } from '../autometria/api/toast'
import { useOfflineStore } from './useOfflineStore'
import type { CachedProduct, LocalPaymentType, LocalReceiptItem } from '../services/offlineDb'

export type PosCartLine = {
  key: string
  product_id: number
  warehouse_id?: number | null
  sku: string
  barcode?: string
  title: string
  qty: number
  price: number
  discount: number
  vat_rate: string
  line: number
  is_marked?: boolean
  marking_code?: string | null
  gtin?: string | null
  serial_number?: string | null
}

function lineSum(price: number, qty: number, discount: number): number {
  return Math.round(price * qty * (1 - Number(discount || 0) / 100) * 100) / 100
}

export const usePosStore = defineStore('pos', {
  state: () => ({
    cart: [] as PosCartLine[],
    catalog: [] as CachedProduct[],
    quickCategories: ['all', 'popular'] as string[],
    activeCategory: 'all',
    searchQuery: '',
    discountPercent: 0,
    promoCode: '',
    checkingOut: false,
    loadingCatalog: false,
    lastOp: { status: 'pending' as string, label: 'Готов к работе' },
    // CRM / Loyalty (Block 4.3)
    selectedCustomer: null as null | {
      id: number
      name: string
      phone: string
      discount_card_number: string | null
      bonus_balance: number
      tier: string | null
    },
    bonusSpend: 0,
    bonusError: '' as string,
    // Block 4.4 — active branch / warehouse for the shift
    activeWarehouseId: null as number | null,
    activeLocationId: null as number | null,
  }),

  getters: {
    itemsCount: (s) => s.cart.reduce((n, r) => n + Number(r.qty || 0), 0),
    subtotal: (s) => s.cart.reduce((n, r) => n + Number(r.line || 0), 0),
    totalDue(s): number {
      const sub = s.cart.reduce((n, r) => n + Number(r.line || 0), 0)
      const d = Math.min(100, Math.max(0, Number(s.discountPercent || 0)))
      return Math.round(sub * (1 - d / 100) * 100) / 100
    },
    /** Итог к оплате с учётом списания бонусов (Block 4.3). */
    payableAmount(s): number {
      const due = (() => {
        const sub = s.cart.reduce((n, r) => n + Number(r.line || 0), 0)
        const d = Math.min(100, Math.max(0, Number(s.discountPercent || 0)))
        return Math.round(sub * (1 - d / 100) * 100) / 100
      })()
      const spend = Math.min(Number(s.bonusSpend || 0), due)
      return Math.round((due - spend) * 100) / 100
    },
    maxBonusSpend(s): number {
      const due = (() => {
        const sub = s.cart.reduce((n, r) => n + Number(r.line || 0), 0)
        const d = Math.min(100, Math.max(0, Number(s.discountPercent || 0)))
        return Math.round(sub * (1 - d / 100) * 100) / 100
      })()
      const balance = Number(s.selectedCustomer?.bonus_balance || 0)
      return Math.min(balance, Math.round(due * 0.5 * 100) / 100)
    },
    filteredCatalog(s): CachedProduct[] {
      const q = String(s.searchQuery || '').trim().toLowerCase()
      let rows = s.catalog
      if (s.activeCategory && s.activeCategory !== 'all') {
        rows = rows.filter((p) => (p.category || 'all') === s.activeCategory)
      }
      if (!q) return rows.slice(0, 60)
      return rows
        .filter((p) => {
          const hay = [p.sku, p.barcode, p.oem, p.title].join(' ').toLowerCase()
          return hay.includes(q)
        })
        .slice(0, 60)
    },
  },

  actions: {
    async loadCatalog() {
      this.loadingCatalog = true
      const offline = useOfflineStore()
      try {
        let rows: CachedProduct[] = []
        try {
          const params: Record<string, unknown> = { per_page: 80 }
          if (this.activeWarehouseId != null) {
            params.warehouse_id = this.activeWarehouseId
          }
          const payload = await apiGet('/stock', { params, silent: true })
          const list = Array.isArray(payload?.data) ? payload.data : []
          rows = list.map((r: Record<string, unknown>) => ({
            id: Number(r.product_id || r.id),
            product_id: Number(r.product_id || r.id),
            sku: String(r.sku || ''),
            barcode: String(r.barcode || r.sku || r.oem || ''),
            oem: r.oem ? String(r.oem) : undefined,
            title: String(r.name || r.title || ''),
            price: Number(r.price || 0),
            available: r.available != null ? Number(r.available) : null,
            warehouse_id: r.warehouse_id != null ? Number(r.warehouse_id) : this.activeWarehouseId,
            vat_rate: 'none',
            category: 'popular',
            is_marked: Boolean(r.is_marked),
            marking_type: r.marking_type ? String(r.marking_type) : null,
            is_egais: Boolean(r.is_egais),
          }))
        } catch {
          /* fallback products */
        }

        if (!rows.length) {
          try {
            const products = await apiGet('/products', { silent: true })
            const list = Array.isArray(products?.data) ? products.data : []
            rows = list.slice(0, 100).map((p: Record<string, unknown>) => ({
              id: Number(p.id),
              product_id: Number(p.id),
              sku: String(p.article || `ID-${p.id}`),
              barcode: String(p.barcode || p.article || p.id),
              oem: p.external_id ? String(p.external_id) : undefined,
              title: String(p.name || ''),
              price: Number(p.base_price || 0),
              available: null,
              warehouse_id: null,
              vat_rate: 'none',
              category: 'popular',
              is_marked: Boolean(p.is_marked),
              marking_type: p.marking_type ? String(p.marking_type) : null,
              is_egais: Boolean(p.is_egais),
            }))
          } catch {
            /* offline only */
          }
        }

        if (rows.length) {
          this.catalog = rows
          await offline.cacheProducts(rows)
        } else {
          this.catalog = await offline.searchCached('', 80)
        }
        this.lastOp = { status: 'success', label: `Каталог ${this.catalog.length}` }
      } finally {
        this.loadingCatalog = false
      }
    },

    /**
     * Add product to cart. Marked goods require marking_code (qty=1 per CIS).
     * @returns 'needs_marking' when caller must open MarkingScanModal
     */
    addProduct(p: CachedProduct, qty = 1, markingCode?: string | null) {
      if (p.is_marked && !markingCode) {
        return { ok: false as const, reason: 'needs_marking' as const, product: p }
      }

      const markKey = markingCode ? `-m-${markingCode.slice(0, 32)}` : ''
      const key = `p-${p.product_id}${markKey}`
      const existing = this.cart.find((r) => r.key === key)

      if (existing && !p.is_marked) {
        existing.qty = Number(existing.qty) + qty
        existing.line = lineSum(existing.price, existing.qty, existing.discount)
      } else if (existing && p.is_marked) {
        // Same CIS already in cart — ignore duplicate scan
        this.lastOp = { status: 'warning', label: 'Марка уже в чеке' }
        toast.warning('Эта марка уже добавлена', 'Честный Знак')
        return { ok: false as const, reason: 'duplicate_mark' as const, product: p }
      } else {
        this.cart.push({
          key,
          product_id: p.product_id,
          warehouse_id: p.warehouse_id,
          sku: p.sku,
          barcode: p.barcode,
          title: p.title,
          qty: p.is_marked ? 1 : qty,
          price: Number(p.price || 0),
          discount: 0,
          vat_rate: p.vat_rate || 'none',
          line: lineSum(Number(p.price || 0), p.is_marked ? 1 : qty, 0),
          is_marked: Boolean(p.is_marked),
          marking_code: markingCode || null,
        })
      }
      this.lastOp = { status: 'success', label: `+ ${p.title}` }
      return { ok: true as const, product: p }
    },

    async addByBarcode(raw: string) {
      const code = String(raw || '').trim()
      if (!/^\d{8,14}$/.test(code) && code.length < 3) {
        return { ok: false as const, reason: 'not_barcode' }
      }

      let product = this.catalog.find((p) => {
        const keys = [p.barcode, p.sku, p.oem, String(p.product_id)].filter(Boolean).map(String)
        return keys.includes(code)
      })

      if (!product) {
        const offline = useOfflineStore()
        product = (await offline.findByBarcode(code)) || undefined
      }

      if (!product) {
        this.lastOp = { status: 'warning', label: `Штрихкод ${code} не найден` }
        toast.warning(`Штрихкод ${code} не найден`, 'POS')
        return { ok: false as const, reason: 'not_found', code }
      }

      if (product.is_marked) {
        return { ok: false as const, reason: 'needs_marking' as const, product }
      }

      this.addProduct(product, 1)
      this.searchQuery = ''
      toast.success(`Добавлено: ${product.title}`, 'Scan')
      return { ok: true as const, product }
    },

    setQty(key: string, qty: number) {
      const row = this.cart.find((r) => r.key === key)
      if (!row) return
      const q = Math.max(0, Math.round(Number(qty) * 1000) / 1000)
      if (q <= 0) {
        this.cart = this.cart.filter((r) => r.key !== key)
        return
      }
      row.qty = q
      row.line = lineSum(row.price, row.qty, row.discount)
    },

    removeLine(key: string) {
      this.cart = this.cart.filter((r) => r.key !== key)
    },

    clearCart() {
      this.cart = []
      this.discountPercent = 0
      this.promoCode = ''
      this.selectedCustomer = null
      this.bonusSpend = 0
      this.bonusError = ''
    },

    /**
     * Restore POS cart from IndexedDB draft (Sprint A offline draft).
     * No-op when cart already has lines or draft is empty.
     */
    async restoreCartDraft(cashierId = 0, shiftId = 0): Promise<boolean> {
      if (this.cart.length > 0) return false

      const offline = useOfflineStore()
      const draft = await offline.loadCartDraft(cashierId, shiftId)
      if (!draft?.items?.length) return false

      const user = getStoredUser() as { tenant_id?: number } | null
      const currentTenant = Number(user?.tenant_id || 0)
      if (currentTenant > 0 && Number(draft.tenant_id || 0) !== currentTenant) {
        await offline.clearCartDraft(cashierId, shiftId)
        this.lastOp = { status: 'warning', label: 'Черновик другого тенанта отклонён' }
        return false
      }

      this.cart = draft.items.map((r, idx) => {
        const qty = Number(r.qty || 0)
        const price = Number(r.price || 0)
        const discount = Number(r.discount || 0)
        const markKey = r.marking_code || r.markingCode
          ? `-m-${String(r.marking_code || r.markingCode).slice(0, 32)}`
          : ''
        return {
          key: `p-${r.product_id}${markKey}-d${idx}`,
          product_id: Number(r.product_id),
          warehouse_id: r.warehouse_id ?? null,
          sku: String(r.sku || ''),
          title: String(r.title || ''),
          qty,
          price,
          discount,
          vat_rate: String(r.vat_rate || 'none'),
          line: lineSum(price, qty, discount),
          is_marked: Boolean(r.is_marked || r.marking_code || r.markingCode),
          marking_code: r.marking_code || r.markingCode || null,
        } satisfies PosCartLine
      })

      if (draft.customer_id != null) {
        this.selectedCustomer = {
          id: Number(draft.customer_id),
          name: `Клиент #${draft.customer_id}`,
          phone: '',
          discount_card_number: null,
          bonus_balance: 0,
          tier: null,
        }
        this.bonusSpend = Number(draft.bonus_spend || 0)
      }

      this.lastOp = { status: 'warning', label: 'Корзина восстановлена из offline-черновика' }
      return true
    },

    /** Block 4.3 — select existing customer from search result. */
    selectCustomer(c: {
      id: number
      name: string
      phone: string
      discount_card_number?: string | null
      bonus_balance?: number
      tier?: string | null
    }) {
      this.selectedCustomer = {
        id: c.id,
        name: c.name,
        phone: c.phone,
        discount_card_number: c.discount_card_number ?? null,
        bonus_balance: Number(c.bonus_balance || 0),
        tier: c.tier ?? null,
      }
      this.bonusSpend = 0
      this.bonusError = ''
    },

    clearCustomer() {
      this.selectedCustomer = null
      this.bonusSpend = 0
      this.bonusError = ''
    },

    /** Block 4.4 — set the active branch/warehouse for the current shift. */
    setActiveWarehouse(warehouseId: number | null, locationId: number | null = null) {
      this.activeWarehouseId = warehouseId
      this.activeLocationId = locationId
      void this.loadCatalog()
    },

    /**
     * Block 4.3 — set bonus redemption with validation:
     * must not exceed available balance nor 50% of the payable amount.
     */
    setBonusSpend(value: number) {
      const requested = Math.max(0, Number(value) || 0)
      const max = this.maxBonusSpend
      this.bonusSpend = Math.min(requested, Number(this.selectedCustomer?.bonus_balance || 0), Math.round(this.totalDue * 0.5 * 100) / 100)
      if (requested > this.bonusSpend) {
        this.bonusError =
          requested > (this.selectedCustomer?.bonus_balance || 0)
            ? 'Превышен баланс бонусов'
            : 'Не более 50% суммы чека'
      } else {
        this.bonusError = ''
      }
      return this.bonusSpend
    },

    /** Block 4.3 — quick register a new customer (name + phone). */
    async registerCustomer(payload: { name: string; phone: string }) {
      const resp = await apiPost('/customers', payload, { silent: true })
      const c = resp?.data ?? resp
      this.selectCustomer({
        id: c.id,
        name: c.name,
        phone: c.phone,
        discount_card_number: c.discount_card_number ?? null,
        bonus_balance: Number(c.bonus_balance || 0),
        tier: c.tier ?? null,
      })
      return c
    },

    applyPromo(code: string) {
      const c = String(code || '').trim().toUpperCase()
      this.promoCode = c
      if (c === 'SALE10') {
        this.discountPercent = 10
        toast.success('Промокод SALE10 · −10%', 'POS')
        return true
      }
      if (!c) {
        this.discountPercent = 0
        return true
      }
      toast.warning('Промокод не найден', 'POS')
      return false
    },

    /**
     * Complete sale: offline-first write, then try online checkout.
     */
    async completeSale(payload: {
      payment_type: LocalPaymentType
      amount_tendered: number
      shift_id: number
      payment_parts?: Array<{ method: string; amount: number }>
      method?: string
    }) {
      if (!this.cart.length) {
        this.lastOp = { status: 'warning', label: 'Корзина пуста' }
        return null
      }

      const user = getStoredUser() as { id?: number; tenant_id?: number } | null
      const tenantId = Number(user?.tenant_id || 0)
      const cashierId = Number(user?.id || 0)
      const offline = useOfflineStore()

      const factor = this.totalDue / Math.max(this.subtotal, 0.01)
      const items: LocalReceiptItem[] = this.cart.map((r) => ({
        product_id: r.product_id,
        warehouse_id: r.warehouse_id,
        title: r.title,
        sku: r.sku,
        qty: r.qty,
        price: Math.round(r.price * factor * 100) / 100,
        discount: r.discount,
        vat_rate: r.vat_rate || 'none',
        markingCode: r.marking_code || null,
        marking_code: r.marking_code || null,
        is_marked: Boolean(r.is_marked),
      }))

      const customerId = this.selectedCustomer?.id ?? null
      const bonusSpend = customerId ? Math.min(this.bonusSpend, this.maxBonusSpend) : 0

      this.checkingOut = true
      try {
        const local = await offline.saveLocalReceipt({
          tenant_id: tenantId,
          shift_id: payload.shift_id,
          cashier_id: cashierId,
          items,
          total_amount: this.payableAmount,
          amount_tendered: payload.amount_tendered,
          payment_type: payload.payment_type,
          payment_parts: payload.payment_parts,
          requires_fiscal_marking: items.some((i) => Boolean(i.marking_code || i.markingCode)),
          customer_id: customerId ?? undefined,
          bonus_spend: bonusSpend || undefined,
        })

        if (offline.online) {
          try {
            const method =
              payload.method ||
              (payload.payment_type === 'CARD'
                ? 'card'
                : payload.payment_type === 'MIXED'
                  ? 'cash'
                  : 'cash')
            await apiPost(
              '/pos/offline-receipts',
              {
                uuid: local.uuid,
                shift_id: payload.shift_id,
                payment_type: payload.payment_type,
                amount_tendered: payload.amount_tendered,
                total_amount: this.payableAmount,
                items: items.map((i) => ({
                  product_id: i.product_id,
                  qty: i.qty,
                  discount: i.discount || 0,
                  warehouse_id: i.warehouse_id || undefined,
                  vat_rate: i.vat_rate,
                  type: 'product',
                  marking_code: i.marking_code || i.markingCode || undefined,
                })),
                method,
                payment_parts: payload.payment_parts,
                customer_id: customerId ?? undefined,
                bonus_spend: bonusSpend || undefined,
              },
              {
                headers: { 'X-Idempotency-Key': local.uuid },
              },
            )
            if (local.id != null) await offline.markSynced(local.id)
            this.lastOp = { status: 'success', label: 'Оплачено · синхронизировано' }
            toast.success('Чек проведён', 'POS')
          } catch (e: unknown) {
            const msg =
              (e as { response?: { data?: { message?: string } }; message?: string })?.response?.data
                ?.message ||
              (e as { message?: string })?.message ||
              'Сеть недоступна'
            this.lastOp = { status: 'warning', label: 'Сохранено офлайн · ожидает sync' }
            toast.warning(`Чек в очереди sync: ${msg}`, 'POS Offline')
          }
        } else {
          this.lastOp = { status: 'warning', label: 'OFFLINE · чек в IndexedDB' }
          toast.info('Нет сети — чек сохранён локально', 'POS Offline')
        }

        const snapshot = {
          uuid: local.uuid,
          total: this.totalDue,
          payment_type: payload.payment_type,
        }
        this.clearCart()
        try {
          await offline.clearCartDraft(cashierId, payload.shift_id)
        } catch {
          /* draft clear is best-effort */
        }
        return snapshot
      } finally {
        this.checkingOut = false
      }
    },
  },
})
