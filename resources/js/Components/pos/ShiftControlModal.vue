<script setup lang="ts">
/**
 * POS shift open / close control (54-ФЗ 24h aware)
 */
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  mode: 'open' | 'close'
  pending?: boolean
  expectedCash?: number
  shiftAgeHours?: number
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: { opening_amount?: number; closing_amount?: number; note?: string }): void
}>()

const amount = ref(0)
const note = ref('')
const amountRef = ref<HTMLInputElement | null>(null)

const title = computed(() =>
  props.mode === 'open' ? 'Открытие смены' : 'Закрытие смены · Z-отчёт',
)

const expired = computed(() => Number(props.shiftAgeHours || 0) >= 24)

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    amount.value = props.mode === 'close' ? Number(props.expectedCash || 0) : 0
    note.value = expired.value ? 'Авто: смена > 24ч (54-ФЗ)' : ''
    await nextTick()
    amountRef.value?.focus()
  },
)

function close(): void {
  emit('update:open', false)
}

function submit(): void {
  const n = Number(amount.value)
  if (!Number.isFinite(n) || n < 0) return
  if (props.mode === 'open') {
    emit('confirm', { opening_amount: n, note: note.value || undefined })
  } else {
    emit('confirm', { closing_amount: n, note: note.value || undefined })
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[95] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
    >
      <button type="button" class="absolute inset-0 bg-black/70" @click="close" />
      <div
        class="relative z-10 w-full max-w-md border-t p-4 sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <h3 class="text-sm font-semibold text-white">{{ title }}</h3>
        <p v-if="expired && mode === 'close'" class="mt-2 text-xs" style="color: #f59e0b">
          Смена открыта более 24 часов (54-ФЗ). Требуется Z-отчёт перед новыми продажами.
        </p>
        <p v-else class="mt-1 text-xs" style="color: #9ca3af">
          {{ mode === 'open' ? 'Укажите стартовый остаток в ящике.' : `Ожидаемая наличность: ₽${Number(expectedCash || 0).toLocaleString('ru-RU')}` }}
        </p>

        <label class="mt-4 block text-[12px]" style="color: #9ca3af">
          Сумма, ₽
          <input
            ref="amountRef"
            v-model.number="amount"
            type="number"
            min="0"
            step="0.01"
            class="ds-input mt-1 h-12 w-full font-mono text-base"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            @keydown.enter.prevent="submit"
          >
        </label>
        <label class="mt-3 block text-[12px]" style="color: #9ca3af">
          Комментарий
          <input
            v-model="note"
            type="text"
            class="ds-input mt-1 h-11 w-full text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          >
        </label>

        <div class="mt-4 flex gap-2">
          <button
            type="button"
            class="h-12 flex-1 border font-mono text-xs font-bold uppercase disabled:opacity-50"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="pending"
            @click="submit"
          >
            {{ pending ? '…' : 'Подтвердить' }}
          </button>
          <button
            type="button"
            class="h-12 border px-4 font-mono text-xs"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
            @click="close"
          >
            Отмена
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
