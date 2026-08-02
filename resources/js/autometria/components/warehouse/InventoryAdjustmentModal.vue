<script setup lang="ts">
/**
 * Inventory recount — fact qty, delta, write-off / ingress act
 */
import { computed, nextTick, ref, watch } from 'vue'

export type StockRowLite = {
  id?: number | string
  product_id: number | string
  warehouse_id: number | string
  sku?: string
  name?: string
  available?: number
  reserved?: number
  warehouse?: string
}

const props = defineProps<{
  open: boolean
  pending?: boolean
  row: StockRowLite | null
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: {
    product_id: number
    warehouse_id: number
    stock_id?: number
    actual_qty: number
    reason: string
    book_qty: number
    delta: number
  }): void
}>()

const actualQty = ref(0)
const reason = ref('')
const inputRef = ref<HTMLInputElement | null>(null)

const bookQty = computed(() => {
  const avail = Number(props.row?.available ?? 0)
  const reserved = Number(props.row?.reserved ?? 0)
  // book on-hand ≈ available + reserved (actual)
  return Math.round((avail + reserved) * 1000) / 1000
})

const delta = computed(() => Math.round((Number(actualQty.value || 0) - bookQty.value) * 1000) / 1000)

const actLabel = computed(() => {
  if (Math.abs(delta.value) < 0.0005) return 'Отклонений нет'
  if (delta.value < 0) return `Акт списания ${Math.abs(delta.value)}`
  return `Акт прихода +${delta.value}`
})

const actColor = computed(() => {
  if (Math.abs(delta.value) < 0.0005) return '#10B981'
  if (delta.value < 0) return '#EF4444'
  return '#F59E0B'
})

watch(
  () => props.open,
  async (v) => {
    if (!v || !props.row) return
    actualQty.value = bookQty.value
    reason.value = ''
    await nextTick()
    inputRef.value?.focus()
    inputRef.value?.select?.()
  },
)

function close(): void {
  emit('update:open', false)
}

function submit(): void {
  if (!props.row) return
  const qty = Number(actualQty.value)
  if (!Number.isFinite(qty) || qty < 0) return
  const r = reason.value.trim()
  if (r.length < 3) return
  emit('confirm', {
    product_id: Number(props.row.product_id),
    warehouse_id: Number(props.row.warehouse_id),
    stock_id: props.row.id != null ? Number(props.row.id) : undefined,
    actual_qty: Math.round(qty * 1000) / 1000,
    reason: r,
    book_qty: bookQty.value,
    delta: delta.value,
  })
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && row"
      class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-label="Инвентаризация"
    >
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 max-h-[90vh] w-full max-w-md overflow-y-auto border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />
        <h3 class="text-sm font-semibold text-white">Инвентаризация / переучёт</h3>
        <p class="mt-1 truncate text-xs" style="color: #9ca3af">
          {{ row.sku }} · {{ row.name }} · {{ row.warehouse }}
        </p>

        <dl class="mt-4 space-y-1.5 border p-3 font-mono text-[12px]" style="border-color: #1f2937; border-radius: 4px; background: #0b0d10; color: #9ca3af">
          <div class="flex justify-between gap-2">
            <dt>Учётный остаток (факт склад)</dt>
            <dd class="text-white">{{ bookQty }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt>В резерве</dt>
            <dd class="text-white">{{ Number(row.reserved || 0) }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt>Доступно</dt>
            <dd class="text-white">{{ Number(row.available || 0) }}</dd>
          </div>
        </dl>

        <label class="mt-4 block text-[12px]" style="color: #9ca3af">
          Фактический остаток
          <input
            ref="inputRef"
            v-model.number="actualQty"
            type="number"
            min="0"
            step="0.001"
            inputmode="decimal"
            class="ds-input mt-1 h-12 w-full font-mono text-base"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          >
        </label>

        <div
          class="mt-2 border px-3 py-2 font-mono text-[12px] font-medium"
          :style="{ borderColor: actColor, color: actColor, borderRadius: '4px', background: '#0b0d10' }"
        >
          Отклонение: {{ delta > 0 ? '+' : '' }}{{ delta }} · {{ actLabel }}
        </div>

        <label class="mt-3 block text-[12px]" style="color: #9ca3af">
          Причина / основание акта
          <textarea
            v-model="reason"
            rows="2"
            class="ds-input mt-1 w-full resize-none text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            placeholder="Инвентаризация, порча, пересорт…"
          />
        </label>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
          <button
            type="button"
            class="h-12 flex-1 border font-mono text-[12px] font-bold uppercase disabled:opacity-50 sm:h-10"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="pending || reason.trim().length < 3"
            @click="submit"
          >
            Провести акт
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
