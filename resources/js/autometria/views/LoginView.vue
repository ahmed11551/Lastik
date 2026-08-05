<script setup>
/**
 * AUTOMETRIA ERP — Login (Bearer → /api/v1/auth/login)
 * 1-Click Demo → POST /api/v1/demo/login (Hermes)
 *
 * Работаешь ТОЛЬКО в resources/js/. Бэкенд не трогать!
 */
import { ref } from 'vue'
import CosmicBackground from '@/Components/UI/CosmicBackground.vue'
import { apiPost, setAuthSession } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const emit = defineEmits(['success'])

const email = ref('')
const password = ref('')
const loading = ref(false)
const demoLoading = ref(false)
const error = ref('')

function pickSession(payload) {
  const root = payload?.data && (payload.data.token || payload.data.user)
    ? payload.data
    : payload
  return {
    token: root?.token || root?.access_token || '',
    user: root?.user || null,
  }
}

function isDemoDisabledError(err) {
  const status = err?.response?.status
  const data = err?.response?.data || {}
  const code = String(data.code || data.error || '').toUpperCase()
  const msg = String(data.message || '')
  if (code.includes('DEMO')) return true
  if (status === 403 && /demo/i.test(msg)) return true
  if (/DEMO_MODE\s*=\s*false/i.test(msg)) return true
  if (/демо[- ]режим отключ/i.test(msg)) return true
  return false
}

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const data = await apiPost('/auth/login', {
      email: email.value,
      password: password.value,
    }, { silent: true })
    const session = pickSession(data)
    setAuthSession(session)
    toast.success('Сессия открыта', 'Auth')
    emit('success', { ...session, landing: 'dashboard' })
  } catch (e) {
    error.value = e.response?.data?.message || 'Неверный логин или пароль'
    toast.error(error.value, '401 / Login')
  } finally {
    loading.value = false
  }
}

/**
 * One-click demo: POST /api/v1/demo/login { email?: admin@demo.local }
 * → Sanctum token → #/dashboard
 */
async function demoOneClick() {
  error.value = ''
  demoLoading.value = true
  try {
    const data = await apiPost(
      '/demo/login',
      { email: 'admin@demo.local' },
      { silent: true },
    )
    const session = pickSession(data)
    if (!session.token) {
      throw Object.assign(new Error('Нет token в ответе'), { response: { data: data } })
    }
    setAuthSession(session)
    toast.success('Демо: admin@demo.local · стенд готов', '1-Click Demo')
    emit('success', { ...session, landing: 'dashboard' })
  } catch (e) {
    if (isDemoDisabledError(e) || e?.response?.status === 403) {
      const msg = 'Демо-режим отключен на данном сервере'
      error.value = msg
      toast.error(msg, 'Demo')
      return
    }
    const status = e.response?.status
    const msg =
      e.response?.data?.message
      || (status === 404
        ? 'Демо-вход недоступен (endpoint /api/v1/demo/login)'
        : 'Не удалось выполнить демо-вход')
    error.value = msg
    toast.error(msg, '1-Click Demo')
  } finally {
    demoLoading.value = false
  }
}
</script>

<template>
  <div class="relative -m-4 flex min-h-[70vh] items-center justify-center p-4 lg:-m-6 lg:p-6">
    <CosmicBackground :fixed="false" />
    <div class="relative z-10 w-full max-w-md space-y-4">
      <form
        class="w-full space-y-3 border p-5"
        style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
        data-testid="login-form"
        @submit.prevent="submit"
      >
        <div
          class="font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
          style="color: #93c5fd"
        >
          Auth // Bearer session
        </div>
        <h2 class="text-sm font-medium text-white">
          Вход в AUTOMETRIA
        </h2>
        <p class="text-xs" style="color: #a8b3c7">
          Токен Sanctum сохраняется локально для /api/v1
        </p>

        <label class="block space-y-1">
          <span class="font-mono text-[10px]" style="color: #64748b">Email</span>
          <input
            v-model="email"
            type="email"
            required
            class="ds-input w-full font-mono text-xs"
            style="border-radius: 4px; background: #090d16; border-color: #1e293b"
            autocomplete="username"
            data-testid="login-email"
          >
        </label>

        <label class="block space-y-1">
          <span class="font-mono text-[10px]" style="color: #64748b">Password</span>
          <input
            v-model="password"
            type="password"
            required
            class="ds-input w-full font-mono text-xs"
            style="border-radius: 4px; background: #090d16; border-color: #1e293b"
            autocomplete="current-password"
            data-testid="login-password"
          >
        </label>

        <p
          v-if="error"
          class="text-xs"
          style="color: #fca5a5"
          data-testid="login-error"
        >
          {{ error }}
        </p>

        <div class="flex items-center gap-2 pt-1">
          <button
            type="submit"
            class="border px-3 py-2 font-mono text-[11px] font-bold uppercase tracking-wide disabled:opacity-50"
            style="background: #f59e0b; color: #090d16; border-color: #f59e0b; border-radius: 4px"
            :disabled="loading || demoLoading"
            data-testid="login-submit"
          >
            Войти
          </button>
          <DsLoadingBadge
            v-if="loading"
            label="Auth…"
          />
        </div>
      </form>

      <!-- 1-Click Demo — POST /api/v1/demo/login -->
      <section
        class="border p-4"
        style="background: #0f172a; border-color: #1a3c8c; border-radius: 4px"
        data-testid="demo-login"
      >
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
          <div>
            <div
              class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
              style="color: #93c5fd"
            >
              Demo // 1-Click
            </div>
            <p class="text-xs text-white">
              Быстрый вход на тестовый стенд без пароля
            </p>
          </div>
          <span
            class="inline-flex max-w-[220px] border px-2 py-1 font-mono text-[10px] leading-snug"
            style="border-color: #1e293b; border-radius: 4px; color: #a8b3c7; background: #090d16"
            data-testid="demo-stand-badge"
          >
            Тестовый стенд: tenant demo · admin@demo.local · 8 заказов · 4 ячейки WMS
          </span>
        </div>

        <button
          type="button"
          class="flex w-full items-center justify-center gap-2 border px-4 py-3.5 font-mono text-[12px] font-bold uppercase tracking-wide shadow-[0_0_24px_rgba(26,60,140,0.35)] transition-opacity disabled:opacity-50"
          style="background: #f59e0b; color: #090d16; border-color: #f59e0b; border-radius: 4px"
          :disabled="loading || demoLoading"
          data-testid="demo-login-one-click"
          @click="demoOneClick"
        >
          <span v-if="!demoLoading">Демо-вход в 1 клик</span>
          <span v-else>Вход в demo…</span>
          <DsLoadingBadge
            v-if="demoLoading"
            label="Demo…"
          />
        </button>
      </section>
    </div>
  </div>
</template>
