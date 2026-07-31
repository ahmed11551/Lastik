<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
defineOptions({ layout: AppLayout })

const props = defineProps<{
  posts: { id: number; name: string; type: string }[]
  bookings: { id: number; postId: number; customerName: string; phone: string; carModel: string; wheelRadius: string; startTime: string; durationMinutes: number; status: string }[]
}>()

const startHour = 8
const endHour = 20
const timeSlots = ref<string[]>([])
for (let h = startHour; h < endHour; h++) {
  timeSlots.value.push(`${String(h).padStart(2, '0')}:00`, `${String(h).padStart(2, '0')}:30`)
}

function bookingStyle(booking: { startTime: string; durationMinutes: number }) {
  const [hours, minutes] = booking.startTime.split(':').map(Number)
  const startOffsetMinutes = (hours - startHour) * 60 + minutes
  const minuteWidth = 2.5
  return { left: `${startOffsetMinutes * minuteWidth}px`, width: `${booking.durationMinutes * minuteWidth}px` }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">Timeline бронирований</h1>
      <span class="text-xs text-muted-foreground">Перетаскивание карточек = перенос записи</span>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-border bg-card">
      <div class="flex min-w-max">
        <div class="w-48 shrink-0 border-b border-r border-border bg-slate-950/90 p-3 text-xs font-semibold text-slate-400">Пост / Время</div>
        <div class="flex">
          <div v-for="slot in timeSlots" :key="slot" class="w-[75px] shrink-0 border-r border-slate-800/50 p-2 text-center text-xs font-mono text-slate-500">{{ slot }}</div>
        </div>
      </div>
      <div v-for="post in posts" :key="post.id" class="flex border-b border-border last:border-b-0">
        <div class="w-48 shrink-0 border-r border-border bg-slate-950/90 p-3">
          <p class="text-sm font-medium text-slate-200">{{ post.name }}</p>
          <p class="text-xs text-slate-500 uppercase tracking-wider">{{ post.type }}</p>
        </div>
        <div class="flex relative">
          <div v-for="slot in timeSlots" :key="slot" class="w-[75px] shrink-0 border-r border-slate-800/30 h-full hover:bg-emerald-500/10 transition"></div>
          <div v-for="booking in bookings.filter(b => b.postId === post.id)" :key="booking.id" class="absolute top-1.5 bottom-1.5 z-10 rounded-lg px-2.5 py-1.5 text-xs shadow-md transition" :style="bookingStyle(booking)" :class="{
            'bg-sky-950/80 border border-sky-500/50 text-sky-100': booking.status === 'confirmed',
            'bg-amber-950/90 border border-amber-500 text-amber-100 animate-pulse': booking.status === 'in_progress',
            'bg-emerald-950/80 border border-emerald-500/50 text-emerald-100': booking.status === 'completed',
            'bg-rose-950/80 border border-rose-500/50 text-rose-100 line-through decoration-rose-300': booking.status === 'canceled',
            'bg-violet-950/80 border border-violet-500 text-violet-100 animate-pulse': booking.status === 'overdue',
          }">
            <div class="flex items-center justify-between gap-1">
              <span class="truncate font-bold">{{ booking.carModel }}</span>
              <span class="rounded bg-black/40 px-1 font-mono">{{ booking.wheelRadius }}</span>
            </div>
            <div class="mt-0.5 truncate text-[11px] opacity-90">{{ booking.customerName }} • {{ booking.phone }}</div>
            <div class="mt-0.5 text-[10px] opacity-80">{{ booking.startTime }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
