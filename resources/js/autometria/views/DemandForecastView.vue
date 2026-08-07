<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Demand Forecast / stockout risk (v1.4.0)
 */
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import { useProcurementStore } from '@/autometria/stores/procurementStore'
import { toast } from '@/autometria/api/toast'

const emit = defineEmits<{
  'open-drafts': []
  navigate: [item: { id: string }]
}>()

const store = useProcurementStore()
const { forecastList, loadingForecast, isGenerating, criticalCount, error } = storeToRefs(store)

const severityFilter = ref<'all' | 'warn' | 'critical'>('all')

const SEV: Record<string, { variant: string; label: string }> = {
  ok: { variant: 'success', label: 'OK' },
  warn: { variant: 'warning', label: 'Риск' },
  critical: { variant: 'danger', label: 'Стокаут' },
}

const columns = [
  { key: 'sku', label: 'Артикул' },
  { key: 'name', label: 'Номенклатура', mono: false },
  { key: 'warehouse', label: 'Склад', mono: false },
  { key: 'on_hand', label: 'Остаток' },
  { key: 'safety_stock', label: 'Safety Stock' },
  { key: 'rop', label: 'ROP' },
  { key: 'suggested_qty', label: 'К заказу' },
  { key: 'severity', label: 'Риск', mono: false },
]

const rows = computed(() => {
  let list = forecastList.value || []
  if (severityFilter.value !== 'all') {
    list = list.filter((r) => r.severity === severityFilter.value)
  }
  return list.map((r) => ({
    ...r,
    _tone: r.severity === 'critical' ? 'critical' : r.severity === 'warn' ? 'warn' : 'ok',
  }))
})

async function reload() {
  try {
    await store.fetchForecast(
      severityFilter.value === 'all' ? {} : { severity: severityFilter.value },
    )
  } catch {
    toast.error(error.value || 'Не удалось загрузить прогноз', 'Прогноз спроса')
  }
}

async function generateDrafts() {
  try {
    const created = await store.generateAutoDrafts({ syncRecalc: true })
    toast.success(`Создано черновиков: ${created.length}`, 'Авто-закупки')
    emit('open-drafts')
    emit('navigate', { id: 'auto_orders' })
  } catch {
    toast.error(error.value || 'Нет данных для черновиков', 'Авто-закупки')
  }
}

onMounted(() => {
  void reload()
  void store.fetchSuppliers().catch(() => undefined)
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden pb-[max(0.75rem,var(--safe-bottom))]"
    style="background: var(--brand-desk, #090d16); min-height: 100%"
    data-testid="demand-forecast-view"
  >
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Analytics & AI // прогноз спроса · ROP · Safety Stock
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Прогноз спроса</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Риск стокаута · точки перезаказа · авто-черновики закупок
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <DsLoadingBadge v-if="loadingForecast || isGenerating" label="Forecasting" />
        <span
          class="border px-2 py-1.5 font-mono text-[11px]"
          style="border-color: #1e293b; border-radius: 4px; color: #ef4444"
        >
          Critical {{ criticalCount }}
        </span>
      </div>
    </div>

    <div
      class="flex flex-col gap-2 border p-3 sm:flex-row sm:flex-wrap sm:items-center"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <select
        v-model="severityFilter"
        class="ds-select h-11 w-full text-sm sm:h-9 sm:w-auto sm:text-xs"
        style="border-radius: 4px; background: #090d16; border-color: #1e293b"
        @change="reload"
      >
        <option value="all">Все риски</option>
        <option value="critical">Стокаут</option>
        <option value="warn">Риск</option>
      </select>
      <button
        type="button"
        class="h-11 border px-3 font-mono text-[11px] sm:h-9"
        style="border-color: #1e293b; color: #a8b3c7; border-radius: 4px; background: #090d16"
        :disabled="loadingForecast"
        @click="reload"
      >
        Обновить
      </button>
      <button
        type="button"
        class="h-12 flex-1 border px-4 font-mono text-[11px] font-bold uppercase tracking-wide sm:h-9 sm:flex-none"
        style="border-color: #f59e0b; color: #090d16; background: #f59e0b; border-radius: 4px"
        :disabled="isGenerating || loadingForecast"
        @click="generateDrafts"
      >
        {{ isGenerating ? 'Генерация…' : '⚡ Сгенерировать черновики заказов' }}
      </button>
    </div>

    <!-- Mobile -->
    <div class="scroll-y-contain max-h-[min(62vh,560px)] space-y-2 sm:hidden">
      <article
        v-for="row in rows"
        :key="row.id"
        class="border p-3"
        :style="{
          background: row._tone === 'critical'
            ? 'color-mix(in srgb, #ef4444 14%, #11151A)'
            : row._tone === 'warn'
              ? 'color-mix(in srgb, #f59e0b 12%, #11151A)'
              : '#11151A',
          borderColor: row._tone === 'critical' ? '#7f1d1d' : row._tone === 'warn' ? '#92400e' : '#1F2937',
          borderRadius: '4px',
        }"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="font-mono text-[12px] text-white">{{ row.sku }}</div>
            <div class="truncate text-[12px]" style="color: #9ca3af">{{ row.name }}</div>
          </div>
          <DsBadge
            :variant="(SEV[row.severity] || SEV.ok).variant"
            :label="(SEV[row.severity] || SEV.ok).label"
            :status="row.severity"
            dot
          />
        </div>
        <div class="mt-2 grid grid-cols-2 gap-1 font-mono text-[11px]" style="color: #a8b3c7">
          <span>Остаток {{ row.on_hand }}</span>
          <span>ROP {{ row.rop }}</span>
          <span>SS {{ row.safety_stock }}</span>
          <span style="color: #f59e0b">Заказать {{ row.suggested_qty }}</span>
        </div>
      </article>
      <p v-if="!rows.length && !loadingForecast" class="py-8 text-center font-mono text-[11px]" style="color: #6b7280">
        Нет данных — пересчитайте ROP или снимите фильтр
      </p>
    </div>

    <!-- Desktop -->
    <div class="hidden min-w-0 overflow-x-auto sm:block">
      <DsTable
        :columns="columns"
        :rows="rows"
        density="compact"
        sticky-header
        max-height="min(62vh, 560px)"
        empty-text="Нет позиций прогноза"
        :row-class="(row) => row._tone === 'critical'
          ? 'ds-table__row--stockout-critical'
          : row._tone === 'warn'
            ? 'ds-table__row--stockout-warn'
            : null"
      >
        <template #sku="{ row }">
          <span
            class="font-mono text-[12px]"
            :style="{ color: row._tone === 'critical' ? '#fca5a5' : '#fff' }"
          >{{ row.sku }}</span>
        </template>
        <template #name="{ row }">
          <span class="font-sans text-[12px] text-white">{{ row.name }}</span>
        </template>
        <template #on_hand="{ value }">
          <span class="font-mono text-[12px] tabular-nums text-white">{{ value }}</span>
        </template>
        <template #safety_stock="{ value }">
          <span class="font-mono text-[11px] tabular-nums" style="color: #9ca3af">{{ value }}</span>
        </template>
        <template #rop="{ value }">
          <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #38bdf8">{{ value }}</span>
        </template>
        <template #suggested_qty="{ value }">
          <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #f59e0b">{{ value }}</span>
        </template>
        <template #severity="{ row }">
          <DsBadge
            :variant="(SEV[row.severity] || SEV.ok).variant"
            :label="(SEV[row.severity] || SEV.ok).label"
            :status="row.severity"
            dot
          />
        </template>
      </DsTable>
    </div>
  </div>
</template>
