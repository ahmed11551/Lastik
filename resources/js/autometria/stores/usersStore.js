import { defineStore } from 'pinia'
import { apiGet, apiPost, apiPut } from '../api/client'
import { toast } from '../api/toast'

export const useUsersStore = defineStore('users', {
  state: () => ({
    rows: [],
    roles: [],
    loading: false,
    error: null,
    degraded: false,
  }),
  actions: {
    async fetchUsers() {
      this.loading = true
      this.error = null
      try {
        const payload = await apiGet('/users', { silent: true })
        this.rows = Array.isArray(payload?.data) ? payload.data : []
        this.roles = Array.isArray(payload?.meta?.roles) ? payload.meta.roles : []
        this.degraded = false
      } catch (e) {
        this.error = e.response?.data?.message || e.message
        this.rows = []
        this.degraded = true
        throw e
      } finally {
        this.loading = false
      }
    },

    async createUser(payload) {
      try {
        const res = await apiPost('/users', payload)
        await this.fetchUsers()
        toast.success('Пользователь создан', 'Users')
        return res.data
      } catch (e) {
        // 422 toast via interceptor
        throw e
      }
    },

    async updateUser(id, payload) {
      try {
        const res = await apiPut(`/users/${id}`, payload)
        await this.fetchUsers()
        toast.success('Пользователь обновлён', 'Users')
        return res.data
      } catch (e) {
        throw e
      }
    },
  },
})
