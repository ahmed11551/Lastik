<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
  posts: { id: number; name: string }[]
  bookings: { post_id: number; start_time: string; end_time: string; customer_name: string; customer_phone: string; status: string }[]
}>()

const postId = ref<number | null>(null)
const date = ref(new Date().toISOString().slice(0, 10))
const slots = ref<{ start: string; end: string }[]>([])
const form = ref({
  customer_name: '',
  customer_phone: '',
  start_time: '',
  end_time: '',
})

const bookedByPost = (post: number) => props.bookings.filter(b => b.post_id === post && b.status === 'booked')

async function loadSlots() {
  if (!postId.value) return
  const res = await fetch(`/bookings/slots/${postId.value}?date=${date.value}`)
  const data = await res.json()
  slots.value = data.slots ?? []
}

function selectSlot(start: string, end: string) {
  form.value.start_time = start
  form.value.end_time = end
}

async function submit() {
  if (!postId.value) return
  await fetch('/bookings', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      post_id: postId.value,
      customer_name: form.value.customer_name,
      customer_phone: form.value.customer_phone,
      start_time: form.value.start_time,
      end_time: form.value.end_time,
    }),
  })
  await loadSlots()
  form.value = { customer_name: '', customer_phone: '', start_time: '', end_time: '' }
}
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Онлайн-запись на подъемник</h1>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div class="space-y-4 lg:col-span-4">
        <section class="rounded-2xl border border-border bg-card p-4 space-y-3">
          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Пост</label>
            <select v-model="postId" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm">
              <option :value="null">Выберите пост</option>
              <option v-for="post in posts" :key="post.id" :value="post.id">{{ post.name }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Дата</label>
            <input v-model="date" type="date" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm" />
          </div>
          <button type="button" class="rounded-xl bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" @click="loadSlots">Показать слоты</button>
        </section>

        <section class="rounded-2xl border border-border bg-card p-4 space-y-3">
          <p class="text-sm font-medium">Бронирование</p>
          <input v-model="form.customer_name" placeholder="Имя клиента" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm" />
          <input v-model="form.customer_phone" placeholder="Телефон" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm" />
          <input :value="form.start_time" placeholder="Начало" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm" disabled />
          <input :value="form.end_time" placeholder="Конец" class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm" disabled />
          <button type="button" class="w-full rounded-xl bg-green-600 px-4 py-2 text-sm font-medium text-white" :disabled="!postId || !form.start_time" @click="submit">Забронировать</button>
        </section>
      </div>

      <section class="lg:col-span-8 space-y-3">
        <div class="rounded-2xl border border-border bg-card p-4">
          <p class="text-sm font-medium">Свободные слоты</p>
          <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
            <button v-for="slot in slots" :key="slot.start + slot.end" type="button" class="rounded-lg border border-border px-2 py-2 text-xs hover:bg-accent" @click="selectSlot(slot.start, slot.end)">
              {{ slot.start }} — {{ slot.end }}
            </button>
          </div>
          <div v-if="!slots.length" class="mt-2 text-xs text-muted-foreground">Нет свободных слотов или выберите пост/дату.</div>
        </div>
      </section>
    </div>
  </div>
</template>
