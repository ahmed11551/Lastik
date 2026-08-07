<script setup lang="ts">
/**
 * AUTOMETRIA ERP — ABC/XYZ Analytics View (v1.4.0)
 * Cosmic Navy · PWA mobile-ready
 */
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import AbcXyzMatrixGrid from '@/autometria/components/analytics/AbcXyzMatrixGrid.vue'
import ProductClassificationTable from '@/autometria/components/analytics/ProductClassificationTable.vue'
import {
  useAnalyticsStore,
  type AbcXyzSegment,
} from '@/autometria/stores/analyticsStore'
import { toast } from '@/autometria/api/toast'

const store = useAnalyticsStore()
const {
  matrixData,
  isLoading,
  recalculating,
  selectedCell,
  filters,
  selectedProducts,
  selectedCellMeta,
  error,
} = storeToRefs(store)

const localFrom = ref(filters.value.date_from)
const localTo = ref(filters.value.date_to)

const cells = computed(() => matrixData.value?.cells || {})
const totalProfit = computed(() => Number(matrixData.value?.total_gross_profit || 0))

async function load(): Promise<void> {
  store.setRange(localFrom.value, localTo.value)
  try {
    await store.fetchAbcXyzMatrix()
  } catch {
    toast.error(error.value || 'Не удалось загрузить матрицу ABC/XYZ', 'Аналитика')
  }
}

async function recalculate(): Promise<void> {
  store.setRange(localFrom.value, localTo.value)
  try {
    await store.triggerRecalculate()
    toast.success('Матрица ABC/XYZ пересчитана', 'Аналитика')
  } catch {
    toast.error(error.value || 'Ошибка пересчёта', 'Аналитика')
  }
}

function onSelectCell(segment: AbcXyzSegment): void {
  store.setSelectedCell(segment)
}

watch([localFrom, localTo], () => {
  // debounce-free: user confirms via «Обновить» / auto on blur not needed
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden pb-[max(0.75rem,var(--safe-bottom))]"
    style="background: var(--brand-desk, #090d16); min-height: 100%"
    data-testid="analytics-abc-xyz-view"
  >
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Analytics & AI // ABC · XYZ · матрица номенклатуры
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">ABC/XYZ анализ</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          9 сегментов · вклад в валовую прибыль · вариация спроса
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <DsLoadingBadge v-if="isLoading || recalculating" label="Calculating" />
        <span
          class="border px-2 py-1.5 font-mono text-[11px]"
          style="border-color: #1e293b; border-radius: 4px; color: #a8b3c7"
        >
          Σ {{ totalProfit.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) }} ₽
        </span>
      </div>
    </div>

    <div
      class="flex flex-col gap-2 border p-3 sm:flex-row sm:flex-wrap sm:items-end"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <label class="min-w-0 flex-1 font-mono text-[11px]" style="color: #9ca3af">
        С
        <input
          v-model="localFrom"
          type="date"
          class="ds-input mt-1 h-11 w-full font-mono text-sm sm:h-9 sm:text-xs"
          style="border-radius: 4px; background: #090d16; border-color: #1e293b"
        >
      </label>
      <label class="min-w-0 flex-1 font-mono text-[11px]" style="color: #9ca3af">
        По
        <input
          v-model="localTo"
          type="date"
          class="ds-input mt-1 h-11 w-full font-mono text-sm sm:h-9 sm:text-xs"
          style="border-radius: 4px; background: #090d16; border-color: #1e293b"
        >
      </label>
      <button
        type="button"
        class="h-11 border px-3 font-mono text-[11px] sm:h-9"
        style="border-color: #1e293b; color: #a8b3c7; border-radius: 4px; background: #090d16"
        :disabled="isLoading"
        @click="load"
      >
        Обновить
      </button>
      <button
        type="button"
        class="h-12 border px-4 font-mono text-[11px] font-bold uppercase tracking-wide sm:h-9"
        style="border-color: #f59e0b; color: #090d16; background: #f59e0b; border-radius: 4px"
        :disabled="isLoading || recalculating"
        @click="recalculate"
      >
        {{ recalculating ? 'Пересчёт…' : 'Пересчитать матрицу' }}
      </button>
    </div>

    <div
      v-if="selectedCellMeta"
      class="border px-3 py-2 font-mono text-[11px]"
      style="background: color-mix(in srgb, #1a3c8c 22%, #0f172a); border-color: #1e293b; border-radius: 4px; color: #a8b3c7"
    >
      Выбрано
      <span style="color: #f59e0b">{{ selectedCell }}</span>
      · {{ selectedCellMeta.count }} SKU
      · {{ Number(selectedCellMeta.share_pct || 0).toFixed(1) }}% прибыли
    </div>

    <AbcXyzMatrixGrid
      :cells="cells"
      :selected-cell="selectedCell"
      :total-gross-profit="totalProfit"
      @select="onSelectCell"
    />

    <ProductClassificationTable
      :products="selectedProducts"
      :segment="selectedCell"
      :loading="isLoading"
    />
  </div>
</template>
