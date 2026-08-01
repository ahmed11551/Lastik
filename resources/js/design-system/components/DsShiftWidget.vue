<script setup>
/**
 * AUTOMETRIA ERP — Cash shift status widget (header)
 * Open/Closed · duration · revenue
 */
import { computed } from 'vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  startedAt: { type: [String, Number, Date], default: null },
  revenue: { type: [Number, String], default: null },
  currency: { type: String, default: '₽' },
  labelOpen: { type: String, default: 'Смена открыта' },
  labelClosed: { type: String, default: 'Смена закрыта' },
  showActions: { type: Boolean, default: true },
})

defineEmits(['open-shift', 'close-shift'])

const durationText = computed(() => {
  if (!props.open || !props.startedAt) return null
  const start = new Date(props.startedAt).getTime()
  if (Number.isNaN(start)) return null
  const diffMs = Math.max(0, Date.now() - start)
  const hours = Math.floor(diffMs / 3_600_000)
  const minutes = Math.floor((diffMs % 3_600_000) / 60_000)
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
})

const revenueText = computed(() => {
  if (props.revenue === null || props.revenue === undefined || props.revenue === '') return null
  const n = typeof props.revenue === 'number' ? props.revenue : Number(String(props.revenue).replace(/\s/g, ''))
  if (Number.isNaN(n)) return String(props.revenue)
  return `${props.currency}${n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`
})
</script>

<template>
  <div
    class="inline-flex items-center gap-2 border px-2.5 py-1.5"
    style="
      border-color: var(--color-border);
      background: var(--color-surface);
      border-radius: 4px;
    "
  >
    <span
      class="inline-flex h-2 w-2 shrink-0 rounded-full"
      :class="open ? 'animate-pulse' : ''"
      :style="{
        background: open ? 'var(--color-success)' : 'var(--color-danger)',
        boxShadow: open
          ? '0 0 6px var(--color-success)'
          : '0 0 6px var(--color-danger)',
      }"
      aria-hidden="true"
    />
    <div class="min-w-0 leading-tight">
      <p class="text-xs font-medium" style="color: var(--color-text-primary)">
        {{ open ? labelOpen : labelClosed }}
      </p>
      <p
        v-if="open && (durationText || revenueText)"
        class="font-mono text-[10px] tabular-nums"
        style="color: var(--color-text-secondary)"
      >
        <span v-if="durationText">{{ durationText }}</span>
        <span v-if="durationText && revenueText"> · </span>
        <span v-if="revenueText">{{ revenueText }}</span>
      </p>
    </div>
    <div v-if="showActions" class="ml-1 flex gap-1">
      <button
        v-if="!open"
        type="button"
        class="ds-btn ds-btn-sm"
        style="
          background: var(--color-success);
          color: #0b0d10;
          border-color: var(--color-success);
          border-radius: 4px;
        "
        @click="$emit('open-shift')"
      >
        Открыть
      </button>
      <button
        v-else
        type="button"
        class="ds-btn ds-btn-sm ds-btn-ghost"
        style="color: var(--color-danger); border-color: var(--color-danger); border-radius: 4px"
        @click="$emit('close-shift')"
      >
        Закрыть
      </button>
    </div>
  </div>
</template>
