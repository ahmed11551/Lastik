<script setup>
/**
 * Investor / operational dashboard — live Gross Profit KPIs (Block 4.1)
 */
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useAnalyticsStore } from '../stores/analyticsStore'

const props = defineProps({
  shiftOpen: { type: Boolean, default: false },
  shiftRevenue: { type: [Number, String], default: 0 },
  locationName: { type: String, default: 'Все точки' },
})

const emit = defineEmits(['navigate', 'create-order'])

const store = useAnalyticsStore()
const { summary, cogsBreakdown, turnover, salesSeries, loading, error, dateFrom, dateTo } =
  storeToRefs(store)

const localFrom = ref(dateFrom.value)
const localTo = ref(dateTo.value)

onMounted(() => {
  store.fetchAll().catch(() => {})
})

watch([localFrom, localTo], ([from, to]) => {
  if (!from || !to) return
  store.setRange(from, to)
  store.fetchAll().catch(() => {})
})

function formatMoney(n) {
  const v = Number(n || 0)
  return `${v.toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`
}

function formatPct(n) {
  if (n == null || Number.isNaN(Number(n))) return '—'
  const v = Number(n)
  const sign = v > 0 ? '+' : ''
  return `${sign}${v.toLocaleString('ru-RU', { maximumFractionDigits: 1 })}%`
}

const kpis = computed(() => {
  const s = summary.value || {}
  return [
    {
      id: 'revenue',
      label: 'Выручка (нетто)',
      value: formatMoney(s.revenue),
      hint:
        s.revenue_delta_pct != null
          ? `Δ к пред. периоду ${formatPct(s.revenue_delta_pct)}`
          : 'Оплаченные продажи − возвраты',
      tone: 'success',
    },
    {
      id: 'cogs',
      label: 'COGS (FIFO)',
      value: formatMoney(s.cogs),
      hint: 'Себестоимость по партиям',
      tone: 'warning',
    },
    {
      id: 'gross',
      label: 'Валовая прибыль',
      value: formatMoney(s.gross_profit),
      hint: `Маржа ${s.margin_pct != null ? `${Number(s.margin_pct).toLocaleString('ru-RU')}%` : '—'}`,
      tone: 'primary',
    },
    {
      id: 'cash',
      label: 'Текущая касса',
      value: formatMoney(props.shiftRevenue),
      hint: props.shiftOpen ? 'Смена открыта' : 'Смена закрыта',
      tone: 'neutral',
    },
  ]
})

const metaLine = computed(() => {
  const s = summary.value
  if (!s) return 'Загрузка метрик…'
  return `${s.orders_count ?? 0} заказов · средний чек ${formatMoney(s.avg_check)} · оборачиваемость ${turnover.value?.turnover_ratio ?? '—'}`
})

const cogsColumns = [
  { key: 'sku', label: 'Артикул' },
  { key: 'product_name', label: 'Товар' },
  { key: 'qty_sold', label: 'Кол-во' },
  { key: 'revenue', label: 'Выручка' },
  { key: 'cogs', label: 'COGS' },
  { key: 'gross_profit', label: 'Прибыль' },
  { key: 'margin_pct', label: 'Маржа' },
]

const cogsRows = computed(() =>
  (cogsBreakdown.value || [])
    .slice()
    .sort((a, b) => (b.gross_profit || 0) - (a.gross_profit || 0))
    .slice(0, 12)
    .map((r) => ({
      id: r.product_id,
      sku: r.sku || '—',
      product_name: r.product_name,
      qty_sold: Number(r.qty_sold || 0).toLocaleString('ru-RU'),
      revenue: formatMoney(r.revenue),
      cogs: formatMoney(r.cogs),
      gross_profit: formatMoney(r.gross_profit),
      margin_pct: `${Number(r.margin_pct || 0).toLocaleString('ru-RU')}%`,
    })),
)

const sparkMax = computed(() => {
  const vals = (salesSeries.value || []).map((d) => Math.abs(Number(d.gross_profit || 0)))
  return Math.max(1, ...vals)
})

const deadstockCount = computed(() => (turnover.value?.deadstock || []).length)
</script>

<template>
  <div class="space-y-4">
    <div class="ds-surface p-5">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <div
            class="mb-1.5 flex items-center gap-2 font-mono text-[11px] font-semibold uppercase tracking-[0.12em]"
            style="color: var(--color-primary)"
          >
            <span
              class="inline-block h-2 w-2 animate-pulse"
              style="background: var(--color-primary); border-radius: 4px; box-shadow: 0 0 8px var(--color-primary)"
            />
            Investor deck // Gross Profit
          </div>
          <h2 class="text-lg font-semibold" style="color: var(--color-text-primary)">
            Сводка:
            <span class="font-mono" style="color: var(--color-primary)">{{ locationName }}</span>
          </h2>
          <p class="mt-1 text-xs" style="color: var(--color-text-secondary)">
            {{ metaLine }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <input
            v-model="localFrom"
            type="date"
            class="ds-input font-mono text-xs"
            aria-label="Дата с"
          >
          <input
            v-model="localTo"
            type="date"
            class="ds-input font-mono text-xs"
            aria-label="Дата по"
          >
          <button
            type="button"
            class="ds-btn ds-btn-primary font-mono text-xs"
            @click="emit('create-order')"
          >
            + Новый заказ
          </button>
        </div>
      </div>
      <p
        v-if="error"
        class="mt-3 font-mono text-xs"
        style="color: var(--color-danger)"
      >
        {{ error }}
      </p>
      <p
        v-else-if="loading"
        class="mt-3 font-mono text-xs"
        style="color: var(--color-text-secondary)"
      >
        Загрузка аналитики…
      </p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div
        v-for="kpi in kpis"
        :key="kpi.id"
        class="ds-surface p-4 transition-colors"
        style="border-radius: 4px"
      >
        <div
          class="mb-2 font-mono text-[10px] uppercase tracking-[0.1em]"
          style="color: var(--color-text-secondary)"
        >
          {{ kpi.label }}
        </div>
        <div class="font-mono text-2xl font-semibold tabular-nums" style="color: var(--color-text-primary)">
          {{ kpi.value }}
        </div>
        <div
          class="mt-2 font-mono text-[11px]"
          :style="{
            color:
              kpi.tone === 'success'
                ? 'var(--color-success)'
                : kpi.tone === 'warning'
                  ? 'var(--color-warning)'
                  : kpi.tone === 'primary'
                    ? 'var(--color-primary)'
                    : 'var(--color-text-secondary)',
          }"
        >
          {{ kpi.hint }}
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="space-y-3 lg:col-span-2">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold">COGS / маржа по SKU</h3>
          <DsBadge
            status="open"
            label="FIFO"
            dot
          />
        </div>
        <DsTable
          :columns="cogsColumns"
          :rows="cogsRows"
          density="compact"
          sticky-header
          empty-text="Нет продаж за период"
        />
      </div>

      <div class="space-y-4">
        <div class="ds-surface p-4">
          <h3 class="mb-3 text-sm font-semibold">Валовая прибыль по дням</h3>
          <div class="flex h-16 items-end gap-0.5">
            <div
              v-for="point in salesSeries"
              :key="point.date"
              class="flex-1 rounded-sm"
              :title="`${point.date}: ${formatMoney(point.gross_profit)}`"
              :style="{
                height: `${Math.max(4, (Math.abs(Number(point.gross_profit || 0)) / sparkMax) * 100)}%`,
                background:
                  Number(point.gross_profit || 0) >= 0
                    ? 'var(--color-primary)'
                    : 'var(--color-danger)',
                opacity: 0.85,
              }"
            />
          </div>
          <p class="mt-3 font-mono text-[11px]" style="color: var(--color-text-secondary)">
            Склад (текущий): {{ formatMoney(turnover?.average_inventory_value) }}
            · неликвиды: {{ deadstockCount }}
          </p>
        </div>

        <div class="ds-surface p-4">
          <h3 class="mb-3 text-sm font-semibold">Навигация</h3>
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm w-full"
            @click="emit('navigate', { id: 'orders' })"
          >
            Заказы
          </button>
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm mt-2 w-full"
            @click="emit('navigate', { id: 'warehouse' })"
          >
            Склад &amp; партии
          </button>
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm mt-2 w-full"
            @click="emit('navigate', { id: 'kpi' })"
          >
            Выработка &amp; KPI
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
