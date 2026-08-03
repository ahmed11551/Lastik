import { defineStore } from 'pinia'

const TOKEN_KEY = 'autometria_portal_token'

async function request(path, options = {}) {
  const token = localStorage.getItem(TOKEN_KEY)
  const response = await fetch(`/api/v1/portal${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers || {}),
    },
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(payload.message || 'Не удалось выполнить запрос')
  return payload
}

export const usePortalStore = defineStore('portal', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    customer: null,
    bookings: [],
    posts: [],
    loading: false,
    error: '',
  }),
  actions: {
    async requestToken(payload) {
      const result = await request('/auth/request-token', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      this.token = result.token
      localStorage.setItem(TOKEN_KEY, result.token)
      this.customer = result.customer
      return result
    },
    async loadDashboard() {
      this.loading = true
      this.error = ''
      try {
        const [me, bookings] = await Promise.all([request('/me'), request('/bookings')])
        this.customer = me.data
        this.bookings = bookings.data || []
      } catch (error) {
        this.error = error.message
        throw error
      } finally {
        this.loading = false
      }
    },
    async loadPosts() {
      const result = await request('/posts')
      this.posts = result.data || []
    },
    async book(payload) {
      const result = await request('/bookings', { method: 'POST', body: JSON.stringify(payload) })
      this.bookings.unshift(result.data)
      return result.data
    },
    async cancel(id) {
      const result = await request(`/bookings/${id}`, { method: 'DELETE' })
      this.bookings = this.bookings.map((booking) => (booking.id === id ? result.data : booking))
    },
    logout() {
      localStorage.removeItem(TOKEN_KEY)
      this.$reset()
    },
  },
})
