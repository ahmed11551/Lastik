<script setup lang="ts">
/**
 * FIFO lots viewer — expandable panel under stock row/card
 */
import { ref, watch } from 'vue'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'

const props = defineProps<{
  productId: number | string
  warehouseId: number | string
  open?: boolean
}>()

const store = useWarehouseStore()
const expanded = ref(Boolean(props.open))
const loading = ref(false)
const lots = ref<Array<{
  id: number
  batch_number: string
  received_date: string
  remaining_qty: number
  cost_price: number
}>>([])
const loaded = ref(false)
const error = ref('')

watch(
  () => props.open,
  (v) => {
    if (v != null) expanded.value = v
  },
)

async function toggle(): Promise<void> {
  expanded.value = !expanded.value
  if (expanded.value && !loaded.value) {
    await loadLots()
  }
}

async function loadLots(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const data = await store.fetchBatches(Number(props.productId), Number(props.warehouseId))
    lots.value = data
    loaded.value = true
  } catch (e: unknown) {
    const err = e as { message?: string }
    error.value = err?.message || 'Не удалось загрузить партии'
    lots.value = []
  } finally {
    loading.value = false
  }
}

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
</script>

<template>
  <div class="mt-1" @click.stop>
    <button
      type="button"
      class="flex h-8 items-center gap-1.5 font-mono text-[11px] transition-colors"
      :style="{ color: expanded ? '#f59e0b' : '#9ca3af' }"
      @click="toggle"
    >
      <span class="inline-block w-3 text-center">{{ expanded ? '▾' : '▸' }}</span>
      Партии на складе (FIFO)
      <span v-if="loaded" class="opacity-70">({{ lots.length }})</span>
    </button>

    <div
      v-if="expanded"
      class="mt-1 overflow-hidden border"
      style="border-color: #1f2937; border-radius: 4px; background: #0b0d10"
    >
      <div v-if="loading" class="px-3 py-2 font-mono text-[11px]" style="color: #6b7280">
        Загрузка партий…
      </div>
      <div v-else-if="error" class="px-3 py-2 font-mono text-[11px]" style="color: #ef4444">
        {{ error }}
      </div>
      <div v-else-if="!lots.length" class="px-3 py-2 font-mono text-[11px]" style="color: #6b7280">
        Нет открытых партий (remaining &gt; 0)
      </div>
      <table v-else class="w-full text-left font-mono text-[11px]">
        <thead>
          <tr style="color: #9ca3af; border-bottom: 1px solid #1f2937">
            <th class="px-2 py-1.5 font-semibold uppercase tracking-wider">Дата прихода</th>
            <th class="px-2 py-1.5 font-semibold uppercase tracking-wider">Партия</th>
            <th class="px-2 py-1.5 text-right font-semibold uppercase tracking-wider">Остаток</th>
            <th class="px-2 py-1.5 text-right font-semibold uppercase tracking-wider">Себест.</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="lot in lots"
            :key="lot.id"
            class="border-t"
            style="border-color: #1f2937; color: #e5e7eb"
          >
            <td class="px-2 py-1.5 tabular-nums">{{ lot.received_date || '—' }}</td>
            <td class="max-w-[120px] truncate px-2 py-1.5" style="color: #9ca3af">{{ lot.batch_number }}</td>
            <td class="px-2 py-1.5 text-right tabular-nums text-white">{{ lot.remaining_qty }}</td>
            <td class="px-2 py-1.5 text-right tabular-nums" style="color: #f59e0b">{{ money(lot.cost_price) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
