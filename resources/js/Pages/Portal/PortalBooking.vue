<script setup>
import { onMounted, ref } from 'vue'
import { usePortalStore } from '@/autometria/stores/usePortalStore'

const emit = defineEmits(['done'])
const store = usePortalStore()
const postId = ref('')
const startTime = ref('')
const endTime = ref('')
const error = ref('')
const saving = ref(false)

onMounted(async () => {
  try {
    await store.loadPosts()
  } catch (e) {
    error.value = e.message
  }
})

async function submit() {
  saving.value = true
  error.value = ''
  try {
    await store.book({ post_id: Number(postId.value), start_time: startTime.value, end_time: endTime.value })
    emit('done')
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="page">
    <p class="eyebrow">ОНЛАЙН-ЗАПИСЬ</p>
    <h1>Выберите время</h1>
    <form @submit.prevent="submit">
      <label>Сервисный пост<select v-model="postId" data-testid="portal-post" required><option value="" disabled>Выберите пост</option><option v-for="post in store.posts" :key="post.id" :value="post.id">{{ post.name }}</option></select></label>
      <label>Начало<input v-model="startTime" data-testid="portal-start" type="datetime-local" required></label>
      <label>Окончание<input v-model="endTime" data-testid="portal-end" type="datetime-local" required></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button data-testid="portal-submit-booking" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Подтвердить запись' }}</button>
    </form>
  </section>
</template>

<style scoped>
.page { margin: 0 auto; max-width: 620px; padding: 36px 20px; }.eyebrow { color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .1em; } form { background: white; border: 1px solid #e2e8f0; border-radius: 12px; display: grid; gap: 16px; padding: 24px; } label { display: grid; gap: 7px; font-size: 14px; font-weight: 600; } input, select { border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; padding: 11px; } button { background: #2563eb; border: 0; border-radius: 8px; color: white; cursor: pointer; font: inherit; font-weight: 600; padding: 12px; }.error { color: #b91c1c; }
</style>
