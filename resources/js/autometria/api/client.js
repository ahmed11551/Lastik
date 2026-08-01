/**
 * AUTOMETRIA ERP — Axios client for Laravel /api/v1
 * Bearer token · Accept JSON · error interceptors → toast
 */
import axios from 'axios'
import { toast } from './toast'

const TOKEN_KEY = 'autometria_token'
const USER_KEY = 'autometria_user'

export function getToken() {
  try {
    return localStorage.getItem(TOKEN_KEY) || ''
  } catch {
    return ''
  }
}

export function setAuthSession({ token, user }) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
  } catch {
    /* private mode */
  }
}

export function clearAuthSession() {
  try {
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
  } catch {
    /* ignore */
  }
}

export function getStoredUser() {
  try {
    const raw = localStorage.getItem(USER_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function resolveBaseURL() {
  const envBase = import.meta.env.VITE_API_BASE_URL
  if (envBase) return envBase.replace(/\/$/, '')
  return '/api/v1'
}

export const api = axios.create({
  baseURL: resolveBaseURL(),
  timeout: 20000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers = config.headers || {}
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

function firstValidationError(errors) {
  if (!errors || typeof errors !== 'object') return ''
  const key = Object.keys(errors)[0]
  const val = key ? errors[key] : null
  if (Array.isArray(val) && val[0]) return String(val[0])
  if (typeof val === 'string') return val
  return ''
}

function redirectToLogin() {
  clearAuthSession()
  const hash = location.hash.replace(/^#\/?/, '')
  if (hash === 'login') return
  location.hash = '#/login'
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data
    const silent = Boolean(error.config?.silent)

    if (!error.response) {
      if (!silent) {
        toast.error('Нет связи с Laravel backend (/api/v1). UI продолжит работу в degraded mode.', 'Сеть')
      }
      return Promise.reject(error)
    }

    if (status === 401) {
      if (!silent) toast.warning('Сессия истекла. Требуется вход.', '401 Unauthorized')
      redirectToLogin()
      return Promise.reject(error)
    }

    if (status === 403) {
      if (!silent) {
        toast.error(data?.message || 'Недостаточно прав для операции.', '403 Forbidden')
      }
      return Promise.reject(error)
    }

    if (status === 422) {
      const detail = firstValidationError(data?.errors) || data?.message || 'Ошибка валидации / domain exception'
      if (!silent) toast.warning(detail, data?.code || '422 Unprocessable')
      return Promise.reject(error)
    }

    if (status >= 500) {
      if (!silent) toast.error(data?.message || 'Внутренняя ошибка сервера.', `HTTP ${status}`)
      return Promise.reject(error)
    }

    if (!silent) {
      toast.error(data?.message || error.message || 'Ошибка запроса', `HTTP ${status || '?'}`)
    }
    return Promise.reject(error)
  },
)

export async function apiGet(url, config = {}) {
  const res = await api.get(url, config)
  return res.data
}

export async function apiPost(url, body, config = {}) {
  const res = await api.post(url, body, config)
  return res.data
}

export async function apiPut(url, body, config = {}) {
  const res = await api.put(url, body, config)
  return res.data
}

export default api
