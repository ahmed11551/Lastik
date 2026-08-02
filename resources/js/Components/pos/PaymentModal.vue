<script setup lang="ts">
/**
 * POS payment modal — cash / card / mixed + change calc
 */
import { computed, nextTick, ref, watch } from 'vue'
import type { LocalPaymentType } from '@/services/offlineDb'

const props = defineProps<{
  open: boolean
  pending?: boolean
  total: number
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: {
    payment_type: LocalPaymentType
    method: string
    amount_tendered: number
    payment_parts?: Array<{ method: string; amount: number }>
  }): void
}>()

const payType = ref<LocalPaymentType>('CASH')
const tendered = ref(0)
const mixedCash = ref(0)
const mixedCard = ref(0)
const tenderRef = ref<HTMLInputElement | null>(null)

const change = computed(() => {
  if (payType.value !== 'CASH') return 0
  return Math.max(0, Math.round((Number(tendered.value || 0) - props.total) * 100) / 100)
})

const mixedSum = computed(
  () => Math.round((Number(mixedCash.value || 0) + Number(mixedCard.value || 0)) * 100) / 100,
)
const mixedOk = computed(() => Math.abs(mixedSum.value - props.total) < 0.01)

const canConfirm = computed(() => {
  if (props.pending) return false
  if (payType.value === 'CASH') return Number(tendered.value || 0) + 0.001 >= props.total
  if (payType.value === 'CARD') return true
  return mixedOk.value
})

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    payType.value = 'CASH'
    tendered.value = props.total
    mixedCash.value = Math.floor(props.total / 2)
    mixedCard.value = Math.round((props.total - mixedCash.value) * 100) / 100
    await nextTick()
    tenderRef.value?.focus()
    tenderRef.value?.select?.()
  },
)

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

function close(): void {
  emit('update:open', false)
}

function selectType(t: LocalPaymentType): void {
  payType.value = t
  if (t === 'CASH') tendered.value = props.total
}

function submit(): void {
  if (!canConfirm.value) return
  if (payType.value === 'CASH') {
    emit('confirm', {
      payment_type: 'CASH',
      method: 'cash',
      amount_tendered: Number(tendered.value || 0),
    })
    return
  }
  if (payType.value === 'CARD') {
    emit('confirm', {
      payment_type: 'CARD',
      method: 'card',
      amount_tendered: props.total,
    })
    return
  }
  emit('confirm', {
    payment_type: 'MIXED',
    method: 'cash',
    amount_tendered: mixedSum.value,
    payment_parts: [
      { method: 'cash', amount: Number(mixedCash.value || 0) },
      { method: 'card', amount: Number(mixedCard.value || 0) },
    ],
  })
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[95] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-label="Оплата"
    >
      <button type="button" class="absolute inset-0 bg-black/70" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 w-full max-w-lg border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">F4 · Payment</div>
            <h3 class="mt-1 text-sm font-semibold text-white">Оплата</h3>
            <p class="mt-1 font-mono text-2xl font-bold" style="color: #f59e0b">{{ money(total) }}</p>
          </div>
          <button
            type="button"
            class="h-10 w-10 border"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
            @click="close"
          >
            ✕
          </button>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-2">
          <button
            v-for="t in ([
              { id: 'CASH', label: 'Наличные' },
              { id: 'CARD', label: 'Карта' },
              { id: 'MIXED', label: 'Смешанная' },
            ] as const)"
            :key="t.id"
            type="button"
            class="min-h-[56px] border px-2 py-3 text-sm font-medium"
            :style="{
              borderRadius: '4px',
              borderColor: payType === t.id ? '#f59e0b' : '#1f2937',
              background: payType === t.id ? '#161b22' : '#0b0d10',
              color: '#fff',
            }"
            @click="selectType(t.id)"
          >
            {{ t.label }}
          </button>
        </div>

        <div v-if="payType === 'CASH'" class="mt-4 space-y-2">
          <label class="block text-[12px]" style="color: #9ca3af">
            Внесено наличными
            <input
              ref="tenderRef"
              v-model.number="tendered"
              type="number"
              min="0"
              step="0.01"
              inputmode="decimal"
              class="ds-input mt-1 h-14 w-full font-mono text-xl"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
              @keydown.enter.prevent="submit"
            >
          </label>
          <div class="font-mono text-sm" :style="{ color: change > 0 ? '#10B981' : '#9ca3af' }">
            Сдача: {{ money(change) }}
          </div>
        </div>

        <div v-else-if="payType === 'MIXED'" class="mt-4 grid grid-cols-2 gap-2">
          <label class="block text-[12px]" style="color: #9ca3af">
            Наличные
            <input
              v-model.number="mixedCash"
              type="number"
              min="0"
              step="0.01"
              class="ds-input mt-1 h-12 w-full font-mono"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            >
          </label>
          <label class="block text-[12px]" style="color: #9ca3af">
            Карта
            <input
              v-model.number="mixedCard"
              type="number"
              min="0"
              step="0.01"
              class="ds-input mt-1 h-12 w-full font-mono"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            >
          </label>
          <div
            class="col-span-2 font-mono text-[11px]"
            :style="{ color: mixedOk ? '#10B981' : '#f59e0b' }"
          >
            Части: {{ money(mixedSum) }}
            <span v-if="!mixedOk"> · должно быть {{ money(total) }}</span>
          </div>
        </div>

        <div v-else class="mt-4 text-sm" style="color: #9ca3af">
          Оплата картой на сумму {{ money(total) }}. Подтвердите на терминале.
        </div>

        <div class="mt-5 flex flex-col gap-2 sm:flex-row-reverse">
          <button
            type="button"
            class="h-14 flex-1 border font-mono text-sm font-bold uppercase disabled:opacity-50"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="!canConfirm"
            @click="submit"
          >
            {{ pending ? 'Проведение…' : 'Подтвердить оплату' }}
          </button>
          <button
            type="button"
            class="h-12 border px-4 font-mono text-xs"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
            :disabled="pending"
            @click="close"
          >
            Esc · Отмена
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
