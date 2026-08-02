<script setup lang="ts">
/**
 * Inter-warehouse transfer — multi-line with available check
 */
import { computed, ref, watch } from 'vue'

export type TransferLine = {
  product_id: number
  stock_id?: number
  sku: string
  name: string
  available: number
  qty: number
}

export type WarehouseOpt = { id: number; name: string }

const props = defineProps<{
  open: boolean
  pending?: boolean
  warehouses: WarehouseOpt[]
  seedLines?: TransferLine[]
  defaultFromId?: number | null
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: {
    from_warehouse_id: number
    to_warehouse_id: number
    reason: string
    items: Array<{ product_id: number; qty: number }>
  }): void
}>()

const fromId = ref<number | null>(null)
const toId = ref<number | null>(null)
const reason = ref('')
const lines = ref<TransferLine[]>([])

watch(
  () => props.open,
  (v) => {
    if (!v) return
    fromId.value = props.defaultFromId ?? props.warehouses[0]?.id ?? null
    toId.value = props.warehouses.find((w) => w.id !== fromId.value)?.id ?? null
    reason.value = ''
    lines.value = (props.seedLines || []).map((l) => ({
      ...l,
      qty: l.qty > 0 ? l.qty : Math.min(1, l.available),
    }))
  },
)

const sameWarehouse = computed(
  () => fromId.value != null && toId.value != null && fromId.value === toId.value,
)

const invalidLine = computed(() =>
  lines.value.some((l) => !Number.isFinite(l.qty) || l.qty <= 0 || l.qty > l.available + 0.0001),
)

const canSubmit = computed(
  () =>
    !props.pending
    && fromId.value != null
    && toId.value != null
    && !sameWarehouse.value
    && reason.value.trim().length >= 3
    && lines.value.length > 0
    && !invalidLine.value,
)

function removeLine(idx: number): void {
  lines.value.splice(idx, 1)
}

function close(): void {
  emit('update:open', false)
}

function submit(): void {
  if (!canSubmit.value || fromId.value == null || toId.value == null) return
  emit('confirm', {
    from_warehouse_id: fromId.value,
    to_warehouse_id: toId.value,
    reason: reason.value.trim(),
    items: lines.value.map((l) => ({
      product_id: l.product_id,
      qty: Math.round(Number(l.qty) * 1000) / 1000,
    })),
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
      aria-label="Перемещение между складами"
    >
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 flex max-h-[92vh] w-full max-w-lg flex-col border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 shrink-0 rounded-full bg-[#374151] sm:hidden" />
        <h3 class="text-sm font-semibold text-white">Перемещение между складами</h3>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          Проверка доступного остатка · POST /stock/transfers
        </p>

        <div class="mt-4 grid gap-2 sm:grid-cols-2">
          <label class="block text-[12px]" style="color: #9ca3af">
            Склад-отправитель
            <select
              v-model.number="fromId"
              class="ds-select mt-1 h-11 w-full text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            >
              <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </label>
          <label class="block text-[12px]" style="color: #9ca3af">
            Склад-получатель
            <select
              v-model.number="toId"
              class="ds-select mt-1 h-11 w-full text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            >
              <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </label>
        </div>
        <p v-if="sameWarehouse" class="mt-1 font-mono text-[11px]" style="color: #ef4444">
          Отправитель и получатель должны отличаться
        </p>

        <div class="mt-3 min-h-0 flex-1 overflow-y-auto">
          <div class="mb-1 text-[11px] font-medium uppercase tracking-wider" style="color: #9ca3af">
            Товары к перемещению
          </div>
          <div v-if="!lines.length" class="border p-4 text-center text-sm" style="border-color: #1f2937; border-radius: 4px; color: #6b7280">
            Выберите строки на складе и откройте перемещение
          </div>
          <ul v-else class="space-y-2">
            <li
              v-for="(line, idx) in lines"
              :key="`${line.product_id}-${idx}`"
              class="border p-3"
              style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="truncate font-mono text-[12px] text-white">{{ line.sku }}</div>
                  <div class="truncate text-[12px]" style="color: #9ca3af">{{ line.name }}</div>
                  <div class="mt-0.5 font-mono text-[11px]" style="color: #6b7280">
                    доступно {{ line.available }}
                  </div>
                </div>
                <button
                  type="button"
                  class="shrink-0 border px-2 py-1 font-mono text-[10px]"
                  style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
                  @click="removeLine(idx)"
                >
                  ✕
                </button>
              </div>
              <label class="mt-2 flex items-center gap-2 text-[11px]" style="color: #9ca3af">
                Кол-во
                <input
                  v-model.number="line.qty"
                  type="number"
                  min="0.001"
                  :max="line.available"
                  step="0.001"
                  class="ds-input h-10 w-28 font-mono text-sm"
                  :class="line.qty > line.available ? 'border-red-500' : ''"
                  style="border-radius: 4px; background: #161b22; border-color: #1f2937"
                >
                <span
                  v-if="line.qty > line.available"
                  class="font-mono text-[10px]"
                  style="color: #ef4444"
                >превышает доступно</span>
              </label>
            </li>
          </ul>
        </div>

        <label class="mt-3 block shrink-0 text-[12px]" style="color: #9ca3af">
          Причина
          <input
            v-model="reason"
            type="text"
            class="ds-input mt-1 h-11 w-full text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            placeholder="Перемещение на точку Б…"
          >
        </label>

        <div class="mt-4 flex shrink-0 flex-col gap-2 sm:flex-row">
          <button
            type="button"
            class="h-12 flex-1 border font-mono text-[12px] font-bold uppercase disabled:opacity-50 sm:h-10"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="!canSubmit"
            @click="submit"
          >
            Переместить
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
