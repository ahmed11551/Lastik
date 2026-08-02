<script setup lang="ts">
/**
 * Close shift — Z-report: fact vs system cash, variance
 */
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  pending?: boolean
  expectedCash?: number
  openingAmount?: number
  cashSales?: number
  deposits?: number
  withdrawals?: number
  inkasso?: number
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: { closing_amount: number; note?: string; variance: number }): void
}>()

const actualCash = ref(0)
const note = ref('')
const inputRef = ref<HTMLInputElement | null>(null)

const systemBalance = computed(() => Math.round(Number(props.expectedCash || 0) * 100) / 100)

const variance = computed(() => {
  const fact = Number(actualCash.value || 0)
  return Math.round((fact - systemBalance.value) * 100) / 100
})

const varianceLabel = computed(() => {
  if (Math.abs(variance.value) < 0.009) return 'Схождение'
  if (variance.value > 0) return `Излишек ${formatMoney(variance.value)}`
  return `Недостача ${formatMoney(Math.abs(variance.value))}`
})

const varianceColor = computed(() => {
  if (Math.abs(variance.value) < 0.009) return '#10B981'
  if (variance.value > 0) return '#F59E0B'
  return '#EF4444'
})

function formatMoney(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    actualCash.value = systemBalance.value
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
  const n = Number(actualCash.value)
  if (!Number.isFinite(n) || n < 0) return
  emit('confirm', {
    closing_amount: Math.round(n * 100) / 100,
    note: note.value.trim() || undefined,
    variance: variance.value,
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
      aria-label="Z-отчёт — закрытие смены"
    >
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 max-h-[90vh] w-full max-w-md overflow-y-auto border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />
        <h3 class="text-sm font-semibold text-white">Закрыть смену · Z-отчёт</h3>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          Сверьте фактические наличные в ящике с системным балансом.
        </p>

        <dl class="mt-4 space-y-1.5 border p-3 font-mono text-[12px]" style="border-color: #1f2937; border-radius: 4px; background: #0b0d10; color: #9ca3af">
          <div class="flex justify-between gap-2">
            <dt>Размен (открытие)</dt>
            <dd class="text-white">{{ formatMoney(openingAmount || 0) }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt>Наличные продажи</dt>
            <dd class="text-white">{{ formatMoney(cashSales || 0) }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt>Внесения</dt>
            <dd class="text-white">{{ formatMoney(deposits || 0) }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt>Выемки</dt>
            <dd class="text-white">−{{ formatMoney(withdrawals || 0) }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt>Инкассация</dt>
            <dd class="text-white">−{{ formatMoney(inkasso || 0) }}</dd>
          </div>
          <div class="flex justify-between gap-2 border-t pt-1.5 font-semibold" style="border-color: #1f2937">
            <dt style="color: #f59e0b">Системный баланс</dt>
            <dd style="color: #f59e0b">{{ formatMoney(systemBalance) }}</dd>
          </div>
        </dl>

        <label class="mt-4 block text-[12px]" style="color: #9ca3af">
          Фактически в ящике, ₽
          <input
            ref="inputRef"
            v-model.number="actualCash"
            type="number"
            min="0"
            step="0.01"
            inputmode="decimal"
            class="ds-input mt-1 h-12 w-full font-mono text-base"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            @keydown.enter.prevent="submit"
          >
        </label>

        <div
          class="mt-2 border px-3 py-2 font-mono text-[12px] font-medium"
          :style="{ borderColor: varianceColor, color: varianceColor, borderRadius: '4px', background: '#0b0d10' }"
        >
          Расхождение: {{ varianceLabel }}
        </div>

        <label class="mt-3 block text-[12px]" style="color: #9ca3af">
          Комментарий к Z-отчёту
          <textarea
            v-model="note"
            rows="2"
            class="ds-input mt-1 w-full resize-none text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            placeholder="Причина расхождения (если есть)"
          />
        </label>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
          <button
            type="button"
            class="h-12 flex-1 border font-mono text-[12px] font-bold uppercase disabled:opacity-50 sm:h-10"
            style="background: #EF4444; color: #fff; border-color: #EF4444; border-radius: 4px"
            :disabled="pending"
            @click="submit"
          >
            Снять Z-отчёт
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
