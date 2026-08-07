/**
 * AUTOMETRIA ERP — Analytics Pinia store (dashboard + ABC/XYZ matrix)
 * v1.4.0 Sprint 1
 */
import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'

export type AbcClass = 'A' | 'B' | 'C'
export type XyzClass = 'X' | 'Y' | 'Z'
export type AbcXyzSegment = `${AbcClass}${XyzClass}`

export type AbcXyzProduct = {
  product_id: number
  product_name: string
  gross_profit: number
  revenue: number
  share_pct: number
  cv: number | null
  abc: AbcClass
  xyz: XyzClass
  segment: AbcXyzSegment
}

export type AbcXyzCell = {
  segment: AbcXyzSegment
  abc: AbcClass
  xyz: XyzClass
  count: number
  gross_profit: number
  share_pct: number
}

export type AbcXyzMatrixData = {
  cells: Record<string, AbcXyzCell>
  matrix: Record<string, AbcXyzProduct[]>
  rows: AbcXyzProduct[]
  total_gross_profit: number
  abc?: Record<string, { abc: string; gross_profit: number }>
  xyz?: Record<string, string>
}

export type AnalyticsFilters = {
  date_from: string
  date_to: string
  warehouse_id: number | null
}

function defaultRange(): { date_from: string; date_to: string } {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 29)
  const fmt = (d: Date) => d.toISOString().slice(0, 10)
  return { date_from: fmt(from), date_to: fmt(to) }
}

const SEGMENTS: AbcXyzSegment[] = ['AX', 'AY', 'AZ', 'BX', 'BY', 'BZ', 'CX', 'CY', 'CZ']

function emptyCells(): Record<string, AbcXyzCell> {
  const cells: Record<string, AbcXyzCell> = {}
  for (const segment of SEGMENTS) {
    cells[segment] = {
      segment,
      abc: segment[0] as AbcClass,
      xyz: segment[1] as XyzClass,
      count: 0,
      gross_profit: 0,
      share_pct: 0,
    }
  }
  return cells
}

function normalizeMatrixPayload(raw: Record<string, unknown> | null | undefined): AbcXyzMatrixData {
  const source = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
  const matrix = (source.matrix && typeof source.matrix === 'object'
    ? source.matrix
    : {}) as Record<string, AbcXyzProduct[]>
  const cellsIn = (source.cells && typeof source.cells === 'object'
    ? source.cells
    : null) as Record<string, AbcXyzCell> | null
  const rows = Array.isArray(source.rows) ? (source.rows as AbcXyzProduct[]) : []
  const total = Number(source.total_gross_profit ?? 0)

  const cells = emptyCells()
  if (cellsIn) {
    for (const key of SEGMENTS) {
      if (cellsIn[key]) cells[key] = { ...cells[key], ...cellsIn[key], segment: key }
    }
  } else {
    for (const key of SEGMENTS) {
      const items = matrix[key] || []
      const profit = items.reduce((s, p) => s + Number(p.gross_profit || 0), 0)
      cells[key] = {
        segment: key,
        abc: key[0] as AbcClass,
        xyz: key[1] as XyzClass,
        count: items.length,
        gross_profit: profit,
        share_pct: total > 0 ? Math.round((profit / total) * 10000) / 100 : 0,
      }
    }
  }

  return {
    cells,
    matrix,
    rows,
    total_gross_profit: total,
    abc: source.abc as AbcXyzMatrixData['abc'],
    xyz: source.xyz as AbcXyzMatrixData['xyz'],
  }
}

export const useAnalyticsStore = defineStore('analytics', {
  state: () => {
    const range = defaultRange()
    return {
      // Dashboard (legacy)
      summary: null as Record<string, unknown> | null,
      cogsBreakdown: [] as unknown[],
      turnover: null as Record<string, unknown> | null,
      salesSeries: [] as unknown[],
      topProducts: [] as unknown[],
      abcXyz: null as AbcXyzMatrixData | null,
      loading: false,
      error: null as string | null,
      dateFrom: range.date_from,
      dateTo: range.date_to,
      warehouseId: null as number | null,

      // v1.4.0 ABC/XYZ matrix UI
      matrixData: null as AbcXyzMatrixData | null,
      isLoading: false,
      selectedCell: 'AX' as AbcXyzSegment | null,
      filters: {
        date_from: range.date_from,
        date_to: range.date_to,
        warehouse_id: null,
      } as AnalyticsFilters,
      recalculating: false,
    }
  },

  getters: {
    hasData: (s) => s.summary != null,
    topProductsByProfit: (s) => (Array.isArray(s.topProducts) ? s.topProducts : []),
    stockValue: (s) => Number(s.turnover?.average_inventory_value ?? 0),
    turnoverDays: (s) => {
      const ratio = Number(s.turnover?.turnover_ratio || 0)
      if (ratio <= 0) return null
      return Math.round(30 / ratio)
    },
    selectedProducts(state): AbcXyzProduct[] {
      if (!state.selectedCell || !state.matrixData) return []
      const fromMatrix = state.matrixData.matrix?.[state.selectedCell]
      if (Array.isArray(fromMatrix) && fromMatrix.length) return fromMatrix
      return (state.matrixData.rows || []).filter((r) => r.segment === state.selectedCell)
    },
    selectedCellMeta(state): AbcXyzCell | null {
      if (!state.selectedCell || !state.matrixData?.cells) return null
      return state.matrixData.cells[state.selectedCell] || null
    },
  },

  actions: {
    setRange(dateFrom: string, dateTo: string) {
      this.dateFrom = dateFrom
      this.dateTo = dateTo
      this.filters.date_from = dateFrom
      this.filters.date_to = dateTo
    },
    setWarehouse(id: number | null) {
      this.warehouseId = id || null
      this.filters.warehouse_id = id || null
    },
    setSelectedCell(cell: AbcXyzSegment | null) {
      this.selectedCell = cell
    },
    params() {
      const params: Record<string, string | number> = {
        from: this.filters.date_from || this.dateFrom,
        to: this.filters.date_to || this.dateTo,
      }
      const wid = this.filters.warehouse_id ?? this.warehouseId
      if (wid) params.warehouse_id = wid
      return params
    },

    async fetchAll() {
      this.loading = true
      this.error = null
      try {
        const params = this.params()
        const [dashboard, series] = await Promise.all([
          apiGet('/analytics/dashboard', { params, silent: true }),
          apiGet('/analytics/sales-series', { params, silent: true }),
        ])
        const data = dashboard?.data ?? dashboard ?? {}
        this.summary = {
          revenue: data.net_revenue ?? data.revenue,
          net_revenue: data.net_revenue ?? data.revenue,
          gross_sales: data.gross_sales,
          refunds_total: data.refunds_total,
          cogs: data.cogs,
          gross_profit: data.gross_profit ?? data.net_profit,
          margin_pct: data.margin_pct,
          avg_check: data.avg_check,
          orders_count: data.orders_count,
          revenue_delta_pct: data.revenue_delta_pct,
        }
        this.cogsBreakdown = Array.isArray(data.top_products) ? data.top_products : []
        this.topProducts = this.cogsBreakdown
        this.turnover = {
          turnover_ratio: data.turnover_rate,
          average_inventory_value: data.stock_value ?? data.average_inventory_value,
          deadstock: data.deadstock || [],
        }
        const normalized = normalizeMatrixPayload(data.abc_xyz)
        this.abcXyz = normalized
        this.matrixData = normalized
        this.salesSeries = Array.isArray(series?.data)
          ? series.data
          : Array.isArray(series)
            ? series
            : []
      } catch (e: any) {
        this.error = e.response?.data?.message || e.message
        this.summary = null
        this.cogsBreakdown = []
        this.topProducts = []
        this.turnover = null
        this.abcXyz = null
        this.matrixData = null
        this.salesSeries = []
        throw e
      } finally {
        this.loading = false
      }
    },

    async fetchAbcXyzMatrix() {
      this.isLoading = true
      this.error = null
      try {
        const data = await apiGet('/analytics/abc-xyz', { params: this.params(), silent: true })
        const normalized = normalizeMatrixPayload(data?.data ?? data)
        this.matrixData = normalized
        this.abcXyz = normalized
        if (this.selectedCell && !normalized.cells[this.selectedCell]) {
          this.selectedCell = 'AX'
        }
        return normalized
      } catch (e: any) {
        this.error = e.response?.data?.message || e.message
        this.matrixData = null
        throw e
      } finally {
        this.isLoading = false
      }
    },

    async triggerRecalculate() {
      this.recalculating = true
      this.isLoading = true
      this.error = null
      try {
        const data = await apiPost('/analytics/abc-xyz/recalculate', this.params(), { silent: true })
        const normalized = normalizeMatrixPayload(data?.data ?? data)
        this.matrixData = normalized
        this.abcXyz = normalized
        return normalized
      } catch (e: any) {
        this.error = e.response?.data?.message || e.message
        throw e
      } finally {
        this.recalculating = false
        this.isLoading = false
      }
    },
  },
})
