<script setup>
/**
 * B2B Customer Portal — login (Cosmic theme)
 */
import { ref } from 'vue'
import CosmicBackground from '@/Components/UI/CosmicBackground.vue'
import { usePortalStore } from '@/autometria/stores/usePortalStore'

const emit = defineEmits(['authenticated'])
const store = usePortalStore()
const phone = ref('')
const tenantId = ref('')
const error = ref('')
const submitting = ref(false)

async function submit() {
  submitting.value = true
  error.value = ''
  try {
    await store.requestToken({
      phone: phone.value,
      ...(tenantId.value ? { tenant_id: Number(tenantId.value) } : {}),
    })
    emit('authenticated')
  } catch (e) {
    error.value = e.message
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="relative flex min-h-screen items-center justify-center p-5">
    <CosmicBackground />
    <form
      class="relative z-10 w-full max-w-md space-y-4 border p-8"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px; color: #e8edf5"
      @submit.prevent="submit"
    >
      <p
        class="m-0 font-mono text-[10px] font-medium uppercase tracking-[0.16em]"
        style="color: #93c5fd"
      >
        LASTIK · B2B Portal
      </p>
      <h1 class="m-0 text-xl font-semibold text-white">
        Кабинет клиента
      </h1>
      <p class="m-0 text-sm" style="color: #a8b3c7">
        Введите телефон — мы создадим защищённый доступ на этом устройстве.
      </p>
      <label class="grid gap-1.5 text-sm">
        Телефон
        <input
          v-model="phone"
          class="ds-input"
          style="background: #090d16; border-color: #1e293b"
          data-testid="portal-phone"
          required
          placeholder="+79990000000"
        >
      </label>
      <label class="grid gap-1.5 text-sm">
        Организация (ID, sandbox)
        <input
          v-model="tenantId"
          class="ds-input"
          style="background: #090d16; border-color: #1e293b"
          data-testid="portal-tenant-id"
          inputmode="numeric"
          placeholder="Необязательно"
        >
      </label>
      <p
        v-if="error"
        class="text-sm"
        style="color: #fca5a5"
      >
        {{ error }}
      </p>
      <button
        data-testid="portal-login"
        class="w-full border px-3 py-3 font-mono text-[11px] font-bold uppercase tracking-wide disabled:opacity-50"
        style="background: #f59e0b; color: #090d16; border-color: #f59e0b; border-radius: 4px"
        :disabled="submitting"
      >
        {{ submitting ? 'Вход…' : 'Войти' }}
      </button>
    </form>
  </section>
</template>
