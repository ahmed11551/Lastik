/**
 * AUTOMETRIA ERP — Procurement / Smart Auto-Orders (v1.4.0 Sprint 2–3)
 */
import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'
import {
  fetchReorderRecommendations,
  recalculateReorderRecommendations,
} from '../api/inventory'

export type ForecastSeverity = 'ok' | 'warn' | 'critical'

export type ForecastRow = {
  id: number
  product_id: number
  warehouse_id: number
  sku: string
  name: string
  warehouse: string
  d_avg: string
  safety_stock: string
  rop: string
  on_hand: string
  suggested_qty: string
  is_dead_stock: boolean
  severity: ForecastSeverity
  lead_time_days: number
  lookback_days: number
  calculated_at?: string | null
  unit_price?: number
}

export type PurchaseDraftLine = {
  product_id: number
  sku: string
  name: string
  suggested_qty: number
  approved_qty: number
  unit_price: number
  warehouse_id: number
  severity?: ForecastSeverity
}

export type PurchaseDraft = {
  id: string
  supplier_id: number | null
  supplier_name: string
  warehouse_id: number
  warehouse_name: string
  status: 'draft' | 'approved' | 'sent'
  note: string
  lines: PurchaseDraftLine[]
  server_order_id?: number | null
  sent_via?: string | null
  created_at: string
}

export type SendMethod = 'email' | 'csv' | 'pdf' | 'telegram'

function uid(prefix = 'draft'): string {
  return `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`
}

function num(v: unknown, fallback = 0): number {
  const n = Number(v)
  return Number.isFinite(n) ? n : fallback
}

export const useProcurementStore = defineStore('procurement', {
  state: () => ({
    forecastList: [] as ForecastRow[],
    purchaseDrafts: [] as PurchaseDraft[],
    activeDraft: null as PurchaseDraft | null,
    isGenerating: false,
    loadingForecast: false,
    approving: false,
    sending: false,
    error: null as string | null,
    criticalCount: 0,
    suppliers: [] as Array<{ id: number; name: string; email?: string | null; phone?: string | null }>,
    defaultSupplierId: null as number | null,
    warehouseFilter: null as number | null,
  }),

  getters: {
    stockoutRiskRows(state): ForecastRow[] {
      return state.forecastList.filter(
        (r) => r.severity === 'critical' || r.severity === 'warn' || num(r.suggested_qty) > 0,
      )
    },
    activeDraftTotal(state): number {
      const d = state.activeDraft
      if (!d) return 0
      return d.lines.reduce((s, l) => s + num(l.approved_qty) * num(l.unit_price), 0)
    },
    draftTotal: () => (draft: PurchaseDraft | null | undefined) => {
      if (!draft) return 0
      return draft.lines.reduce((s, l) => s + num(l.approved_qty) * num(l.unit_price), 0)
    },
  },

  actions: {
    setActiveDraft(draft: PurchaseDraft | null) {
      this.activeDraft = draft
    },

    setDefaultSupplier(id: number | null) {
      this.defaultSupplierId = id
    },

    async fetchSuppliers() {
      const payload = await apiGet('/suppliers', { silent: true })
      this.suppliers = Array.isArray(payload?.data) ? payload.data : []
      if (!this.defaultSupplierId && this.suppliers[0]) {
        this.defaultSupplierId = Number(this.suppliers[0].id)
      }
      return this.suppliers
    },

    async fetchForecast(params: { warehouse_id?: number; severity?: string } = {}) {
      this.loadingForecast = true
      this.error = null
      try {
        const res = await fetchReorderRecommendations({
          warehouse_id: params.warehouse_id ?? this.warehouseFilter ?? undefined,
          severity: params.severity,
          per_page: 100,
        })
        this.forecastList = Array.isArray(res?.data) ? res.data : []
        this.criticalCount = Number(res?.meta?.critical_count || 0)
        return this.forecastList
      } catch (e: any) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка загрузки прогноза'
        this.forecastList = []
        throw e
      } finally {
        this.loadingForecast = false
      }
    },

    /**
     * Build local purchase drafts from ROP recommendations (group by warehouse + supplier).
     */
    async generateAutoDrafts(opts: { warehouse_id?: number; supplier_id?: number; syncRecalc?: boolean } = {}) {
      this.isGenerating = true
      this.error = null
      try {
        if (opts.syncRecalc !== false) {
          await recalculateReorderRecommendations({
            warehouse_id: opts.warehouse_id ?? this.warehouseFilter ?? undefined,
            sync: true,
          })
        }

        await Promise.all([this.fetchForecast({ warehouse_id: opts.warehouse_id }), this.fetchSuppliers()])

        const supplierId = opts.supplier_id ?? this.defaultSupplierId ?? this.suppliers[0]?.id ?? null
        const supplier = this.suppliers.find((s) => Number(s.id) === Number(supplierId))
        const supplierName = supplier?.name || (supplierId ? `Поставщик #${supplierId}` : 'Без поставщика')

        const candidates = this.forecastList.filter(
          (r) => (r.severity === 'critical' || r.severity === 'warn') && num(r.suggested_qty) > 0,
        )

        if (!candidates.length) {
          this.error = 'Нет позиций с риском стокаута для черновиков'
          return []
        }

        const byWh = new Map<string, ForecastRow[]>()
        for (const row of candidates) {
          const key = String(row.warehouse_id || '0')
          if (!byWh.has(key)) byWh.set(key, [])
          byWh.get(key)!.push(row)
        }

        const created: PurchaseDraft[] = []
        const now = new Date().toISOString()

        for (const [whKey, rows] of byWh.entries()) {
          const warehouseId = Number(whKey) || Number(rows[0]?.warehouse_id) || 0
          const warehouseName = rows[0]?.warehouse || `Склад #${warehouseId}`
          const draft: PurchaseDraft = {
            id: uid('po'),
            supplier_id: supplierId ? Number(supplierId) : null,
            supplier_name: supplierName,
            warehouse_id: warehouseId,
            warehouse_name: warehouseName,
            status: 'draft',
            note: `Авто-черновик ROP · ${rows.length} SKU · ${now.slice(0, 10)}`,
            lines: rows.map((r) => ({
              product_id: Number(r.product_id),
              sku: r.sku || '',
              name: r.name || '',
              suggested_qty: num(r.suggested_qty),
              approved_qty: Math.max(num(r.suggested_qty), 0),
              unit_price: num(r.unit_price, 0),
              warehouse_id: Number(r.warehouse_id),
              severity: r.severity,
            })),
            server_order_id: null,
            sent_via: null,
            created_at: now,
          }
          created.push(draft)
        }

        this.purchaseDrafts = [...created, ...this.purchaseDrafts]
        this.activeDraft = created[0] || null
        return created
      } catch (e: any) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка генерации черновиков'
        throw e
      } finally {
        this.isGenerating = false
      }
    },

    updateLineQty(draftId: string, productId: number, qty: number) {
      const draft = this.purchaseDrafts.find((d) => d.id === draftId)
      if (!draft) return
      const line = draft.lines.find((l) => l.product_id === productId)
      if (!line) return
      line.approved_qty = Math.max(0, num(qty))
      if (this.activeDraft?.id === draftId) {
        this.activeDraft = { ...draft, lines: [...draft.lines] }
      }
    },

    updateDraftSupplier(draftId: string, supplierId: number) {
      const draft = this.purchaseDrafts.find((d) => d.id === draftId)
      if (!draft) return
      const supplier = this.suppliers.find((s) => Number(s.id) === Number(supplierId))
      draft.supplier_id = Number(supplierId)
      draft.supplier_name = supplier?.name || `Поставщик #${supplierId}`
      if (this.activeDraft?.id === draftId) this.activeDraft = { ...draft }
    },

    /**
     * Persist draft as SupplierOrder (DRAFT) on server and mark approved locally.
     */
    async approveDraft(id: string) {
      const draft = this.purchaseDrafts.find((d) => d.id === id)
      if (!draft) throw new Error('Черновик не найден')
      if (!draft.supplier_id) throw new Error('Выберите поставщика')
      if (!draft.warehouse_id) throw new Error('Не указан склад')

      const items = draft.lines
        .filter((l) => num(l.approved_qty) > 0)
        .map((l) => ({
          product_id: l.product_id,
          qty: num(l.approved_qty),
          unit_price: num(l.unit_price),
        }))

      if (!items.length) throw new Error('Нет позиций с количеством > 0')

      this.approving = true
      this.error = null
      try {
        const payload = await apiPost('/supplier-orders', {
          supplier_id: draft.supplier_id,
          warehouse_id: draft.warehouse_id,
          note: draft.note,
          items,
        })
        const order = payload?.data
        draft.status = 'approved'
        draft.server_order_id = order?.id != null ? Number(order.id) : null
        if (this.activeDraft?.id === id) this.activeDraft = { ...draft }
        return draft
      } catch (e: any) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка утверждения'
        throw e
      } finally {
        this.approving = false
      }
    },

    buildEmailPreview(draft: PurchaseDraft): { subject: string; body: string } {
      const total = draft.lines.reduce((s, l) => s + num(l.approved_qty) * num(l.unit_price), 0)
      const lines = draft.lines
        .filter((l) => num(l.approved_qty) > 0)
        .map(
          (l) =>
            `• ${l.sku || l.product_id} ${l.name} — ${num(l.approved_qty)} × ${num(l.unit_price).toFixed(2)} ₽`,
        )
        .join('\n')
      return {
        subject: `Заказ поставщику ${draft.supplier_name} · ${draft.warehouse_name}`,
        body: [
          `Здравствуйте, ${draft.supplier_name}!`,
          '',
          `Просим подтвердить поставку на склад «${draft.warehouse_name}».`,
          '',
          lines || '— нет позиций —',
          '',
          `Итого: ${total.toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ₽`,
          draft.note ? `\nПримечание: ${draft.note}` : '',
          '',
          '— AUTOMETRIA ERP',
        ].join('\n'),
      }
    },

    buildCsv(draft: PurchaseDraft): string {
      const header = 'sku,name,product_id,qty,unit_price,line_total'
      const rows = draft.lines
        .filter((l) => num(l.approved_qty) > 0)
        .map((l) => {
          const qty = num(l.approved_qty)
          const price = num(l.unit_price)
          const esc = (s: string) => `"${String(s).replace(/"/g, '""')}"`
          return [esc(l.sku), esc(l.name), l.product_id, qty, price, (qty * price).toFixed(2)].join(',')
        })
      return [header, ...rows].join('\n')
    },

    downloadBlob(filename: string, content: string, mime: string) {
      const blob = new Blob([content], { type: mime })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      a.click()
      URL.revokeObjectURL(url)
    },

    /**
     * Send / export draft to supplier channel.
     */
    async sendDraftToSupplier(id: string, method: SendMethod) {
      const draft = this.purchaseDrafts.find((d) => d.id === id)
      if (!draft) throw new Error('Черновик не найден')

      this.sending = true
      this.error = null
      try {
        if (method === 'csv') {
          this.downloadBlob(
            `order_${draft.id}.csv`,
            this.buildCsv(draft),
            'text/csv;charset=utf-8',
          )
        } else if (method === 'pdf') {
          // Lightweight printable HTML (browser → Save as PDF)
          const { subject, body } = this.buildEmailPreview(draft)
          const html = `<!doctype html><html><head><meta charset="utf-8"><title>${subject}</title>
            <style>body{font-family:ui-monospace,monospace;background:#090d16;color:#e8edf5;padding:24px}
            h1{color:#f59e0b;font-size:16px} pre{white-space:pre-wrap;line-height:1.45}</style></head>
            <body><h1>${subject}</h1><pre>${body.replace(/[<>&]/g, (c) => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]||c))}</pre>
            <script>window.onload=()=>window.print()<\/script></body></html>`
          const w = window.open('', '_blank', 'noopener,noreferrer')
          if (w) {
            w.document.write(html)
            w.document.close()
          } else {
            this.downloadBlob(`order_${draft.id}.html`, html, 'text/html;charset=utf-8')
          }
        } else if (method === 'email') {
          const { subject, body } = this.buildEmailPreview(draft)
          const supplier = this.suppliers.find((s) => Number(s.id) === Number(draft.supplier_id))
          const to = supplier?.email || ''
          const mailto = `mailto:${encodeURIComponent(to)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
          window.location.href = mailto
        } else if (method === 'telegram') {
          const { body } = this.buildEmailPreview(draft)
          try {
            await navigator.clipboard.writeText(body)
          } catch {
            /* ignore */
          }
          const tg = `https://t.me/share/url?url=${encodeURIComponent('https://autometria.local')}&text=${encodeURIComponent(body.slice(0, 1500))}`
          window.open(tg, '_blank', 'noopener,noreferrer')
        }

        // Confirm on server when already approved
        if (draft.server_order_id && draft.status === 'approved') {
          try {
            await apiPost(`/supplier-orders/${draft.server_order_id}/confirm`, {})
          } catch {
            /* confirm optional if already confirmed */
          }
        }

        draft.status = 'sent'
        draft.sent_via = method
        if (this.activeDraft?.id === id) this.activeDraft = { ...draft }
        return draft
      } catch (e: any) {
        this.error = e?.response?.data?.message || e.message || 'Ошибка отправки'
        throw e
      } finally {
        this.sending = false
      }
    },
  },
})
