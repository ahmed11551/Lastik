<script setup lang="ts">
/**
 * Open shift — float / разменная монета
 */
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  pending?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: { opening_amount: number; note?: string }): void
}>()

const amount = ref(0)
const note = ref('')
const inputRef = ref<HTMLInputElement | null>(null)

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    amount.value = 0
    note.value = ''
    await nextTick()
    inputRef.value?.focus()
    inputRef.value?.select?.()
  },
)

function close(): void {
  emit('update:open', false)
}

function submit(): void {
  const n = Number(amount.value)
  if (!Number.isFinite(n) || n < 0) return
  emit('confirm', {
    opening_amount: Math.round(n * 100) / 100,
    note: note.value.trim() || undefined,
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
      aria-label="Открыть смену"
    >
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 w-full max-w-md border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />
        <h3 class="text-sm font-semibold text-white">Открыть смену</h3>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          Укажите начальную сумму в кассе (разменная монета).
        </p>

        <label class="mt-4 block text-[12px]" style="color: #9ca3af">
          Начальная сумма, ₽
          <input
            ref="inputRef"
            v-model.number="amount"
            type="number"
            min="0"
            step="0.01"
            inputmode="decimal"
            class="ds-input mt-1 h-12 w-full font-mono text-base"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            @keydown.enter.prevent="submit"
          >
        </label>

        <label class="mt-3 block text-[12px]" style="color: #9ca3af">
          Комментарий (опц.)
          <input
            v-model="note"
            type="text"
            class="ds-input mt-1 h-11 w-full text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            placeholder="Размен на начало дня"
          >
        </label>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
          <button
            type="button"
            class="h-12 flex-1 border font-mono text-[12px] font-bold uppercase disabled:opacity-50 sm:h-10"
            style="background: #10B981; color: #0b0d10; border-color: #10B981; border-radius: 4px"
            :disabled="pending"
            @click="submit"
          >
            Открыть
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
