<script setup>
/**
 * AUTOMETRIA ERP — Login (Bearer token → /api/v1/auth/login)
 */
import { ref } from 'vue'
import { apiPost, setAuthSession } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const emit = defineEmits(['success'])

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const data = await apiPost('/auth/login', {
      email: email.value,
      password: password.value,
    }, { silent: true })
    setAuthSession({ token: data.token, user: data.user })
    toast.success('Сессия открыта', 'Auth')
    emit('success', data)
  } catch (e) {
    error.value = e.response?.data?.message || 'Неверный логин или пароль'
    toast.error(error.value, '401 / Login')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div
    class="-m-4 flex min-h-[70vh] items-center justify-center p-4 lg:-m-6 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10)"
  >
    <form
      class="w-full max-w-sm space-y-3 border p-5"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
      @submit.prevent="submit"
    >
      <div
        class="font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
        style="color: #f59e0b"
      >
        Auth // Bearer session
      </div>
      <h2 class="text-sm font-medium text-white">
        Вход в AUTOMETRIA
      </h2>
      <p class="text-xs" style="color: #9ca3af">
        Токен Sanctum сохраняется локально для /api/v1
      </p>

      <label class="block space-y-1">
        <span class="font-mono text-[10px]" style="color: #6b7280">Email</span>
        <input
          v-model="email"
          type="email"
          required
          class="ds-input w-full font-mono text-xs"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          autocomplete="username"
        >
      </label>

      <label class="block space-y-1">
        <span class="font-mono text-[10px]" style="color: #6b7280">Password</span>
        <input
          v-model="password"
          type="password"
          required
          class="ds-input w-full font-mono text-xs"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          autocomplete="current-password"
        >
      </label>

      <p
        v-if="error"
        class="text-xs"
        style="color: #ef4444"
      >
        {{ error }}
      </p>

      <div class="flex items-center gap-2 pt-1">
        <button
          type="submit"
          class="border px-3 py-2 font-mono text-[11px] font-bold uppercase tracking-wide disabled:opacity-50"
          style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
          :disabled="loading"
        >
          Войти
        </button>
        <DsLoadingBadge
          v-if="loading"
          label="Auth…"
        />
      </div>
    </form>
  </div>
</template>
