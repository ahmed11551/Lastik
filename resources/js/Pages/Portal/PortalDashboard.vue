<script setup>
import { onMounted } from 'vue'
import { usePortalStore } from '@/autometria/stores/usePortalStore'

const emit = defineEmits(['book'])
const store = usePortalStore()

onMounted(async () => {
  try {
    await store.loadDashboard()
  } catch {
    location.hash = '#/login'
  }
})

async function cancel(id) {
  await store.cancel(id)
}
</script>

<template>
  <section class="page">
    <div class="intro">
      <div><p class="eyebrow">ПРОФИЛЬ</p><h1>Здравствуйте, {{ store.customer?.name || 'клиент' }}</h1></div>
      <button data-testid="portal-start-booking" @click="emit('book')">Записаться на сервис</button>
    </div>
    <p v-if="store.error" class="error">{{ store.error }}</p>
    <h2>Мои записи</h2>
    <p v-if="store.loading">Загрузка…</p>
    <p v-else-if="!store.bookings.length">Активных записей пока нет.</p>
    <article v-for="booking in store.bookings" :key="booking.id" class="booking" data-testid="portal-booking">
      <div><strong>{{ booking.post?.name || 'Сервисный пост' }}</strong><br><span>{{ new Date(booking.start_time).toLocaleString('ru-RU') }}</span></div>
      <div><span class="status">{{ booking.status }}</span><button v-if="booking.status !== 'cancelled'" data-testid="portal-cancel" @click="cancel(booking.id)">Отменить</button></div>
    </article>
  </section>
</template>

<style scoped>
.page { margin: 0 auto; max-width: 860px; padding: 36px 20px; }.intro { align-items: center; display: flex; justify-content: space-between; gap: 20px; }.eyebrow { color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .1em; margin: 0; } h1 { margin: 5px 0; } h2 { margin-top: 36px; }.intro button { background: #2563eb; border: 0; border-radius: 8px; color: white; cursor: pointer; font: inherit; padding: 12px 16px; }.booking { align-items: center; background: white; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; margin-top: 10px; padding: 16px; }.booking span { color: #64748b; }.booking button { color: #b91c1c; cursor: pointer; margin-left: 12px; }.status { text-transform: uppercase; }.error { color: #b91c1c; }
</style>
