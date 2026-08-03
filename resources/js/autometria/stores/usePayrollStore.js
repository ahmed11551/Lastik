import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/client'

export const usePayrollStore = defineStore('payroll', {
  state: () => ({ periods: [], payslips: [], currentPayslip: null, deductions: [], accrualRules: [], loading: false, saving: false, error: '' }),
  actions: {
    async request(action) {
      this.loading = true
      this.error = ''
      try { return await action() } catch (e) { this.error = e?.response?.data?.message || e.message || 'Ошибка зарплатного модуля'; throw e } finally { this.loading = false }
    },
    async fetchPeriods() {
      return this.request(async () => { const r = await apiGet('/payroll-periods', { silent: true }); this.periods = r?.data || []; return this.periods })
    },
    async createPeriod(body) {
      this.saving = true
      try { const r = await apiPost('/payroll-periods', body); await this.fetchPeriods(); return r?.data } finally { this.saving = false }
    },
    async transition(id, action) {
      this.saving = true
      try { const r = await apiPost(`/payroll-periods/${id}/${action}`, {}); await this.fetchPeriods(); return r?.data } finally { this.saving = false }
    },
    async fetchPayslips(periodId) {
      return this.request(async () => { const r = await apiGet('/payslips', { params: periodId ? { period_id: periodId } : undefined, silent: true }); this.payslips = r?.data || []; return this.payslips })
    },
    async fetchPayslip(id) {
      return this.request(async () => { const r = await apiGet(`/payslips/${id}`, { silent: true }); this.currentPayslip = r?.data || null; return this.currentPayslip })
    },
    async fetchRules() {
      return this.request(async () => {
        const [deductions, rules] = await Promise.all([apiGet('/deductions', { silent: true }), apiGet('/accrual-rules', { silent: true })])
        this.deductions = deductions?.data || []; this.accrualRules = rules?.data || []
      })
    },
    async createDeduction(body) { const r = await apiPost('/deductions', body); await this.fetchRules(); return r?.data },
    async createAccrualRule(body) { const r = await apiPost('/accrual-rules', body); await this.fetchRules(); return r?.data },
  },
})
