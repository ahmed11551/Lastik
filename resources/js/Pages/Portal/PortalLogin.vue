<script setup>
import { ref } from 'vue'
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
  <section class="login">
    <form class="card" @submit.prevent="submit">
      <p class="eyebrow">LASTIK</p>
      <h1>Кабинет клиента</h1>
      <p>Введите телефон — мы создадим защищённый доступ на этом устройстве.</p>
      <label>Телефон<input v-model="phone" data-testid="portal-phone" required placeholder="+79990000000"></label>
      <label>Организация (ID, sandbox)<input v-model="tenantId" data-testid="portal-tenant-id" inputmode="numeric" placeholder="Необязательно"></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button data-testid="portal-login" :disabled="submitting">{{ submitting ? 'Вход…' : 'Войти' }}</button>
    </form>
  </section>
</template>

<style scoped>
.login { align-items: center; display: flex; justify-content: center; min-height: 100vh; padding: 20px; }
.card { background: white; border-radius: 16px; box-shadow: 0 16px 40px #0f172a18; max-width: 400px; padding: 32px; width: 100%; }
.eyebrow { color: #2563eb; font-weight: 800; letter-spacing: .1em; margin: 0; }
h1 { margin: 8px 0; } label { display: grid; font-size: 14px; gap: 6px; margin-top: 16px; }
input { border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; padding: 11px; }
button { background: #2563eb; border: 0; border-radius: 8px; color: white; cursor: pointer; font: inherit; font-weight: 600; margin-top: 22px; padding: 12px; width: 100%; }
.error { color: #b91c1c; }
</style>
