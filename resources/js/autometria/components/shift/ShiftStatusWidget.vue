<script setup lang="ts">
/**
 * Cashier shift status — green / red / amber (>24h Z-report)
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = withDefaults(
  defineProps<{
    open?: boolean
    startedAt?: string | number | Date | null
    revenue?: number | string | null
    loading?: boolean
  }>(),
  {
    open: false,
    startedAt: null,
    revenue: null,
    loading: false,
  },
)

const emit = defineEmits<{
  (e: 'open-shift'): void
  (e: 'close-shift'): void
}>()

const nowTick = ref(Date.now())
let timer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  timer = setInterval(() => {
    nowTick.value = Date.now()
  }, 30_000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})

const elapsedMs = computed(() => {
  void nowTick.value
  if (!props.open || !props.startedAt) return 0
  const start = new Date(props.startedAt).getTime()
  if (Number.isNaN(start)) return 0
  return Math.max(0, Date.now() - start)
})

const durationText = computed(() => {
  if (!props.open) return null
  const hours = Math.floor(elapsedMs.value / 3_600_000)
  const minutes = Math.floor((elapsedMs.value % 3_600_000) / 60_000)
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
})

const over24h = computed(() => props.open && elapsedMs.value >= 24 * 3_600_000)

type Tone = 'green' | 'red' | 'amber'

const tone = computed<Tone>(() => {
  if (!props.open) return 'red'
  if (over24h.value) return 'amber'
  return 'green'
})

const statusLabel = computed(() => {
  if (!props.open) return 'Смена не открыта'
  if (over24h.value) return 'Смена > 24ч — Требуется Z-отчет'
  return `Смена открыта (${durationText.value})`
})

const toneColor = computed(() => {
  if (tone.value === 'green') return '#10B981'
  if (tone.value === 'amber') return '#F59E0B'
  return '#EF4444'
})

const revenueText = computed(() => {
  if (props.revenue === null || props.revenue === undefined || props.revenue === '') return null
  const n = typeof props.revenue === 'number' ? props.revenue : Number(String(props.revenue).replace(/\s/g, ''))
  if (Number.isNaN(n)) return null
  return `₽${n.toLocaleString('ru-RU')}`
})
</script>

<template>
  <div
    class="flex w-full flex-col gap-2 border p-3 sm:flex-row sm:items-center sm:justify-between"
    :style="{ borderColor: toneColor, background: '#11151A', borderRadius: '4px' }"
  >
    <div class="flex min-w-0 items-center gap-2.5">
      <span
        class="inline-flex h-2.5 w-2.5 shrink-0 rounded-full"
        :class="open && !over24h ? 'animate-pulse' : ''"
        :style="{ background: toneColor, boxShadow: `0 0 8px ${toneColor}` }"
        aria-hidden="true"
      />
      <div class="min-w-0 leading-tight">
        <p class="text-[13px] font-medium" :style="{ color: toneColor }">
          {{ statusLabel }}
        </p>
        <p
          v-if="open && revenueText"
          class="font-mono text-[11px] tabular-nums"
          style="color: #9ca3af"
        >
          Выручка смены {{ revenueText }}
        </p>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        v-if="!open"
        type="button"
        class="h-11 min-w-[140px] border px-3 font-mono text-[12px] font-bold uppercase tracking-wide disabled:opacity-50 sm:h-9"
        style="background: #10B981; color: #0b0d10; border-color: #10B981; border-radius: 4px"
        :disabled="loading"
        @click="emit('open-shift')"
      >
        Открыть смену
      </button>
      <button
        v-else
        type="button"
        class="h-11 min-w-[180px] border px-3 font-mono text-[12px] font-bold uppercase tracking-wide disabled:opacity-50 sm:h-9"
        :style="{
          background: over24h ? '#F59E0B' : 'transparent',
          color: over24h ? '#0b0d10' : '#EF4444',
          borderColor: over24h ? '#F59E0B' : '#EF4444',
          borderRadius: '4px',
        }"
        :disabled="loading"
        @click="emit('close-shift')"
      >
        Закрыть смену (Z-отчет)
      </button>
    </div>
  </div>
</template>
