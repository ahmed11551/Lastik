<script setup lang="ts">
/**
 * Compact fiscal status badge for lists / cashier history
 */
import { computed } from 'vue'
import type { FiscalReceipt, FiscalReceiptStatus } from '@/autometria/types/fiscal'
import { FISCAL_STATUS_LABEL } from '@/autometria/types/fiscal'

const props = defineProps<{
  status?: FiscalReceiptStatus | null
  receipt?: FiscalReceipt | null
  /** Show retry when failed */
  retryable?: boolean
  pending?: boolean
}>()

const emit = defineEmits<{
  (e: 'retry', receiptId: number): void
}>()

const resolvedStatus = computed<FiscalReceiptStatus | null>(() => {
  return props.status || props.receipt?.status || null
})

const label = computed(() => {
  const s = resolvedStatus.value
  if (!s) return '—'
  if (s === 'fiscalized') {
    const fd = props.receipt?.fiscal_document_number
    return fd ? `Чек пробит (ФД №${fd})` : FISCAL_STATUS_LABEL.fiscalized
  }
  return FISCAL_STATUS_LABEL[s] || s
})

const tone = computed(() => {
  switch (resolvedStatus.value) {
    case 'pending':
      return { bg: '#422006', border: '#F59E0B', color: '#FCD34D' }
    case 'fiscalized':
      return { bg: '#052e1a', border: '#10B981', color: '#6EE7B7' }
    case 'failed':
      return { bg: '#3f0a0a', border: '#EF4444', color: '#FCA5A5' }
    case 'refunded':
      return { bg: '#1f2937', border: '#6B7280', color: '#D1D5DB' }
    default:
      return { bg: '#11151a', border: '#374151', color: '#9CA3AF' }
  }
})

function onRetry(): void {
  const id = props.receipt?.id
  if (!id || props.pending) return
  emit('retry', id)
}
</script>

<template>
  <span class="inline-flex max-w-full flex-wrap items-center gap-1.5">
    <span
      class="inline-flex items-center gap-1.5 border px-2 py-1 font-mono text-[11px] leading-none"
      :style="{
        background: tone.bg,
        borderColor: tone.border,
        color: tone.color,
        borderRadius: '4px',
      }"
    >
      <span
        v-if="resolvedStatus === 'pending'"
        class="fiscal-spin inline-block h-2.5 w-2.5 rounded-full border-2 border-current border-t-transparent"
        aria-hidden="true"
      />
      <span
        v-else
        class="h-1.5 w-1.5 shrink-0 rounded-full"
        :style="{ background: tone.color }"
        aria-hidden="true"
      />
      <span class="truncate">{{ label }}</span>
    </span>

    <button
      v-if="retryable !== false && resolvedStatus === 'failed' && receipt?.id"
      type="button"
      class="h-8 border px-2 font-mono text-[11px] font-medium uppercase tracking-wide disabled:opacity-50"
      style="border-color: #EF4444; color: #FCA5A5; background: #1a0a0a; border-radius: 4px"
      :disabled="pending"
      @click.stop="onRetry"
    >
      Повторить
    </button>
  </span>
</template>

<style scoped>
.fiscal-spin {
  animation: fiscal-spin 0.8s linear infinite;
}
@keyframes fiscal-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
