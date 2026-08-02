/**
 * AUTOMETRIA ERP — Fiscal receipts Pinia store (54-ФЗ)
 * Polling every 3s while status === pending
 */
import { defineStore } from 'pinia'
import {
  apiCreateFiscalReceipt,
  apiFetchReceiptById,
  apiFetchReceiptByOrder,
  apiFetchShiftReceipts,
  apiRetryFiscalization,
} from '../api/fiscal'
import { toast } from '../api/toast'

const POLL_MS = 3000
const TERMINAL = new Set(['fiscalized', 'failed', 'refunded'])

export const useFiscalStore = defineStore('fiscal', {
  state: () => ({
    /** @type {import('../types/fiscal').FiscalReceipt | null} */
    current: null,
    /** @type {import('../types/fiscal').FiscalReceipt[]} */
    history: [],
    loading: false,
    mutating: false,
    polling: false,
    error: null,
    /** @type {ReturnType<typeof setInterval> | null} */
    _pollTimer: null,
    /** @type {number | null} */
    _pollReceiptId: null,
  }),

  getters: {
    status: (s) => s.current?.status || null,
    isPending: (s) => s.current?.status === 'pending',
    isFailed: (s) => s.current?.status === 'failed',
    isFiscalized: (s) => s.current?.status === 'fiscalized',
  },

  actions: {
    stopPolling() {
      if (this._pollTimer) {
        clearInterval(this._pollTimer)
        this._pollTimer = null
      }
      this._pollReceiptId = null
      this.polling = false
    },

    /**
     * Poll GET /fiscal-receipts/{id} every 3s until terminal status.
     * @param {number} receiptId
     */
    startPolling(receiptId) {
      this.stopPolling()
      if (!receiptId) return
      this._pollReceiptId = receiptId
      this.polling = true

      this._pollTimer = setInterval(async () => {
        if (!this._pollReceiptId) return
        try {
          const r = await apiFetchReceiptById(this._pollReceiptId)
          if (!r) return
          this.current = r
          this._patchHistory(r)
          if (TERMINAL.has(r.status)) {
            if (r.status === 'fiscalized') {
              toast.success(`Чек пробит · ФД №${r.fiscal_document_number || '—'}`, '54-ФЗ')
            } else if (r.status === 'failed') {
              toast.warning(r.error_message || 'Ошибка ОФД', '54-ФЗ')
            }
            this.stopPolling()
          }
        } catch {
          /* silent — keep polling */
        }
      }, POLL_MS)
    },

    _patchHistory(receipt) {
      if (!receipt?.id) return
      const idx = this.history.findIndex((h) => Number(h.id) === Number(receipt.id))
      if (idx >= 0) this.history[idx] = receipt
      else this.history = [receipt, ...this.history].slice(0, 40)
    },

    /**
     * @param {number} orderId
     */
    async fetchReceiptByOrder(orderId) {
      this.loading = true
      this.error = null
      try {
        const r = await apiFetchReceiptByOrder(orderId)
        this.current = r
        if (r) {
          this._patchHistory(r)
          if (r.status === 'pending') this.startPolling(r.id)
        }
        return r
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        throw e
      } finally {
        this.loading = false
      }
    },

    /**
     * @param {import('../types/fiscal').CreateFiscalReceiptPayload} payload
     */
    async createFiscalReceipt(payload) {
      this.mutating = true
      this.error = null
      try {
        const r = await apiCreateFiscalReceipt(payload)
        this.current = r
        if (r) {
          this._patchHistory(r)
          toast.info(
            r.status === 'pending' ? 'Чек отправлен на фискализацию' : 'Фискальный чек создан',
            '54-ФЗ',
          )
          if (r.status === 'pending') this.startPolling(r.id)
        }
        return r
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        throw e
      } finally {
        this.mutating = false
      }
    },

    /**
     * POST /fiscal-receipts/{id}/retry
     * @param {number} receiptId
     */
    async retryFiscalization(receiptId) {
      this.mutating = true
      this.error = null
      try {
        const r = await apiRetryFiscalization(receiptId)
        if (r) {
          this.current = r
          this._patchHistory(r)
          toast.info('Повторная отправка в ОФД…', '54-ФЗ')
          if (r.status === 'pending') this.startPolling(r.id)
        }
        return r
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        toast.warning(this.error || 'Не удалось повторить', '54-ФЗ')
        throw e
      } finally {
        this.mutating = false
      }
    },

    /**
     * @param {number} shiftId
     */
    async fetchShiftHistory(shiftId) {
      if (!shiftId) {
        this.history = []
        return []
      }
      this.loading = true
      try {
        const list = await apiFetchShiftReceipts(shiftId)
        this.history = list
        const pending = list.find((r) => r.status === 'pending')
        if (pending) {
          this.current = pending
          this.startPolling(pending.id)
        }
        return list
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        return []
      } finally {
        this.loading = false
      }
    },

    setCurrent(receipt) {
      this.current = receipt || null
      if (receipt) this._patchHistory(receipt)
    },

    clearCurrent() {
      this.stopPolling()
      this.current = null
    },
  },
})
