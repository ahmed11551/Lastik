<script setup lang="ts">
/**
 * Deposit / withdrawal — внесение / выемка наличных
 */
import { computed, nextTick, ref, watch } from 'vue'

export type CashOpType = 'deposit' | 'withdrawal'

const props = defineProps<{
  open: boolean
  type: CashOpType
  pending?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: { type: CashOpType; amount: number; reason: string }): void
}>()

const amount = ref(0)
const reason = ref('')
const amountRef = ref<HTMLInputElement | null>(null)

const title = computed(() => (props.type === 'deposit' ? 'Внесение в кассу' : 'Выемка из кассы'))
const accent = computed(() => (props.type === 'deposit' ? '#10B981' : '#F59E0B'))

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    amount.value = 0
    reason.value = ''
    await nextTick()
    amountRef.value?.focus()
  },
)

function close(): void {
  emit('update:open', false)
}

function submit(): void {
  const n = Number(amount.value)
  if (!Number.isFinite(n) || n <= 0) return
  const r = reason.value.trim()
  if (r.length < 2) return
  emit('confirm', {
    type: props.type,
    amount: Math.round(n * 100) / 100,
    reason: r,
  })
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
    >
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 w-full max-w-md border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />
        <h3 class="text-sm font-semibold text-white">{{ title }}</h3>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          Быстрая операция по открытой смене. Укажите сумму и причину.
        </p>

        <label class="mt-4 block text-[12px]" style="color: #9ca3af">
          Сумма, ₽
          <input
            ref="amountRef"
            v-model.number="amount"
            type="number"
            min="0.01"
            step="0.01"
            inputmode="decimal"
            class="ds-input mt-1 h-12 w-full font-mono text-base"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          >
        </label>

        <label class="mt-3 block text-[12px]" style="color: #9ca3af">
          Причина / комментарий
          <input
            v-model="reason"
            type="text"
            class="ds-input mt-1 h-11 w-full text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            :placeholder="type === 'deposit' ? 'Размен, внесение выручки…' : 'Выемка размен, инкассация…'"
            @keydown.enter.prevent="submit"
          >
        </label>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
          <button
            type="button"
            class="h-12 flex-1 border font-mono text-[12px] font-bold uppercase disabled:opacity-50 sm:h-10"
            :style="{ background: accent, color: '#0b0d10', borderColor: accent, borderRadius: '4px' }"
            :disabled="pending || amount <= 0 || reason.trim().length < 2"
            @click="submit"
          >
            Подтвердить
          </button>
          <button
            type="button"
            class="h-12 border font-mono text-[12px] sm:h-10 sm:px-4"
            style="border-color: #1f2937; border-radius: 4px; color: #9ca3af; background: #0b0d10"
            :disabled="pending"
            @click="close"
          >
            Отмена
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
