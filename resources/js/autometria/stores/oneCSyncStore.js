/**
 * AUTOMETRIA ERP — 1C CommerceML sync Pinia store
 * Polling every 3s while any log is pending/processing
 */
import { defineStore } from 'pinia'
import {
  apiFetchOneCCredentials,
  apiFetchOneCLogs,
  apiResetOneCCredentials,
  apiUpdateOneCOptions,
  apiUploadOneCXml,
} from '../api/onec'
import { toast } from '../api/toast'

const POLL_MS = 3000
const ACTIVE = new Set(['pending', 'processing'])

export const useOneCSyncStore = defineStore('onecSync', {
  state: () => ({
    /** @type {import('../types/onec').OneCCredentials | null} */
    credentials: null,
    /** @type {import('../types/onec').OneCSyncLog[]} */
    logs: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    filters: { status: '', channel: '' },
    /** @type {import('../types/onec').OneCUploadProgress} */
    uploadProgress: {
      jobId: null,
      fileName: '',
      fileType: 'auto',
      phase: 'idle',
      percent: 0,
      processedSkus: 0,
      error: null,
    },
    isSyncing: false,
    loading: false,
    mutating: false,
    error: null,
    /** @type {ReturnType<typeof setInterval> | null} */
    _pollTimer: null,
  }),

  getters: {
    /** @returns {import('../types/onec').OneCIntegrationState} */
    integrationState(s) {
      if (s.isSyncing || s.logs.some((l) => ACTIVE.has(String(l.status)))) return 'syncing'
      if (s.logs.some((l) => l.status === 'failed')) return 'error'
      if (s.logs.some((l) => l.status === 'completed')) return 'synced'
      return 'idle'
    },
    exchangeUrl(s) {
      return s.credentials?.exchange_url || `${location.origin}/api/v1/1c/exchange`
    },
  },

  actions: {
    stopPolling() {
      if (this._pollTimer) {
        clearInterval(this._pollTimer)
        this._pollTimer = null
      }
    },

    startPollingIfNeeded() {
      const hasActive =
        this.isSyncing ||
        this.logs.some((l) => ACTIVE.has(String(l.status))) ||
        ACTIVE.has(this.uploadProgress.phase)

      if (!hasActive) {
        this.stopPolling()
        return
      }
      if (this._pollTimer) return

      this._pollTimer = setInterval(async () => {
        try {
          await this.fetchLogs(this.meta.current_page || 1, { silent: true })
          const active = this.logs.some((l) => ACTIVE.has(String(l.status)))
          if (this.uploadProgress.jobId) {
            const job = this.logs.find((l) => Number(l.id) === Number(this.uploadProgress.jobId))
            if (job) {
              this.uploadProgress.phase = /** @type {any} */ (job.status)
              this.uploadProgress.processedSkus = Number(job.objects?.processed || 0)
              this.uploadProgress.percent =
                job.status === 'completed' ? 100 : job.status === 'failed' ? 100 : 70
              if (job.status === 'failed') {
                this.uploadProgress.error = job.error_message || 'Ошибка обработки'
              }
              if (!ACTIVE.has(String(job.status))) {
                this.isSyncing = false
              }
            }
          }
          if (!active && !ACTIVE.has(this.uploadProgress.phase)) {
            this.isSyncing = false
            this.stopPolling()
          }
        } catch {
          /* keep polling */
        }
      }, POLL_MS)
    },

    async fetchCredentials() {
      this.loading = true
      this.error = null
      try {
        this.credentials = await apiFetchOneCCredentials()
        return this.credentials
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        throw e
      } finally {
        this.loading = false
      }
    },

    async resetCredentials() {
      this.mutating = true
      this.error = null
      try {
        this.credentials = await apiResetOneCCredentials()
        toast.success('Новый пароль 1С сгенерирован — скопируйте его сейчас', '1С')
        return this.credentials
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        throw e
      } finally {
        this.mutating = false
      }
    },

    async saveOptions(options) {
      this.mutating = true
      try {
        const opts = await apiUpdateOneCOptions(options)
        if (this.credentials) {
          this.credentials = { ...this.credentials, options: opts }
        }
        toast.success('Опции синхронизации сохранены', '1С')
        return opts
      } finally {
        this.mutating = false
      }
    },

    /**
     * @param {number} page
     * @param {{ silent?: boolean }} [opts]
     */
    async fetchLogs(page = 1, opts = {}) {
      if (!opts.silent) this.loading = true
      this.error = null
      try {
        const res = await apiFetchOneCLogs(page, {
          status: this.filters.status || undefined,
          channel: this.filters.channel || undefined,
        })
        this.logs = res.data
        this.meta = res.meta
        this.isSyncing = res.data.some((l) => ACTIVE.has(String(l.status)))
        this.startPollingIfNeeded()
        return res
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        if (!opts.silent) throw e
        return { data: this.logs, meta: this.meta }
      } finally {
        if (!opts.silent) this.loading = false
      }
    },

    /**
     * @param {File} file
     * @param {'import'|'offers'|'auto'} [type]
     */
    async uploadXmlFile(file, type = 'auto') {
      const name = String(file?.name || '').toLowerCase()
      if (!name.endsWith('.xml')) {
        toast.warning('Принимаются только файлы .xml', '1С')
        return null
      }
      const mime = String(file.type || '')
      if (mime && !['application/xml', 'text/xml', 'application/octet-stream'].includes(mime)) {
        toast.warning('Неверный MIME-тип. Ожидается application/xml или text/xml', '1С')
        return null
      }

      this.isSyncing = true
      this.uploadProgress = {
        jobId: null,
        fileName: file.name,
        fileType: type,
        phase: 'uploading',
        percent: 0,
        processedSkus: 0,
        error: null,
      }

      try {
        const job = await apiUploadOneCXml(file, type, (pct) => {
          this.uploadProgress.percent = pct
        })
        this.uploadProgress.jobId = job.id
        this.uploadProgress.phase = /** @type {any} */ (job.status || 'processing')
        this.uploadProgress.percent = job.status === 'completed' ? 100 : 70
        this.uploadProgress.processedSkus = Number(job.objects?.processed || 0)
        if (job.status === 'failed') {
          this.uploadProgress.error = job.error_message || 'Ошибка'
          this.isSyncing = false
          toast.warning(this.uploadProgress.error, '1С')
        } else if (job.status === 'completed') {
          this.isSyncing = false
          toast.success(`Импорт завершён · SKU ${this.uploadProgress.processedSkus}`, '1С')
        } else {
          toast.info('Файл принят, идёт обработка…', '1С')
        }
        await this.fetchLogs(1, { silent: true })
        this.startPollingIfNeeded()
        return job
      } catch (e) {
        this.uploadProgress.phase = 'failed'
        this.uploadProgress.percent = 100
        this.uploadProgress.error = e.response?.data?.message || e.message
        this.isSyncing = false
        throw e
      }
    },
  },
})
