<script setup lang="ts">
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { Head } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useAnalyticsStore } from '@/autometria/stores/analyticsStore'
import {
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js'
import { Bar, Line } from 'vue-chartjs'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Tooltip,
  Legend,
)

defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: {
    type: [String, Number, Date],
    default: () => new Date(Date.now() - 3_600_000 * 2.5).toISOString(),
  },
  shiftRevenue: { type: [Number, String], default: 0 },
})

const store = useAnalyticsStore()
const { summary, turnover, salesSeries, topProductsByProfit, abcXyz, loading, dateFrom, dateTo, warehouseId } =
  storeToRefs(store)

const money = (v: number | null | undefined) =>
  '₽' + Number(v || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 })

const presets = [
  { key: 'today', label: 'Сегодня', days: 0 },
  { key: 'yesterday', label: 'Вчера', days: 1, offset: 1 },
  { key: '7d', label: '7 дней', days: 6 },
  { key: '30d', label: '30 дней', days: 29 },
] as const

const activePreset = ref<string>('30d')

function applyPreset(key: string) {
  const p = presets.find((x) => x.key === key)
  if (!p) return
  activePreset.value = key
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - p.days)
  if ((p as any).offset) from.setDate(from.getDate() - (p as any).offset)
  const fmt = (d: Date) => d.toISOString().slice(0, 10)
  store.setRange(fmt(from), fmt(to))
  reload()
}

function reload() {
  store.fetchAll().catch(() => {})
}

const summaryCards = computed(() => [
  {
    label: 'Net Revenue',
    value: money(summary.value?.revenue),
    sub: summary.value?.revenue_delta_pct != null ? `${summary.value.revenue_delta_pct}% к прош. периоду` : '—',
  },
  {
    label: 'Gross Profit',
    value: money(summary.value?.gross_profit),
    sub: `Средний чек ${money(summary.value?.avg_check)}`,
  },
  {
    label: 'Маржинальность',
    value: `${summary.value?.margin_pct ?? 0}%`,
    sub: `${summary.value?.orders_count ?? 0} заказов`,
  },
  {
    label: 'Оценка склада (себест.)',
    value: money(store.stockValue),
    sub: 'Текущие остатки',
  },
  {
    label: 'Оборачиваемость',
    value: store.turnoverDays != null ? `${store.turnoverDays} дн.` : '—',
    sub: `Коэфф. ${turnover.value?.turnover_ratio ?? 0}`,
  },
])

const lineData = computed(() => ({
  labels: (salesSeries.value || []).map((r: any) => r.date),
  datasets: [
    {
      label: 'Выручка',
      data: (salesSeries.value || []).map((r: any) => Number(r.revenue) || 0),
      borderColor: '#F59E0B',
      backgroundColor: 'rgba(245,158,11,0.15)',
      tension: 0.3,
      fill: true,
    },
    {
      label: 'Валовая прибыль',
      data: (salesSeries.value || []).map((r: any) => Number(r.gross_profit) || 0),
      borderColor: '#3B82F6',
      backgroundColor: 'rgba(59,130,246,0.1)',
      tension: 0.3,
      fill: true,
    },
  ],
}))

const barData = computed(() => ({
  labels: topProductsByProfit.value.map((r: any) => r.product_name || r.sku || `#${r.product_id}`),
  datasets: [
    {
      label: 'Валовая прибыль',
      data: topProductsByProfit.value.map((r: any) => Number(r.gross_profit) || 0),
      backgroundColor: '#F59E0B',
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { labels: { color: '#9CA3AF' } } },
  scales: {
    x: { ticks: { color: '#9CA3AF' }, grid: { color: 'rgba(255,255,255,0.05)' } },
    y: { ticks: { color: '#9CA3AF' }, grid: { color: 'rgba(255,255,255,0.05)' } },
  },
}

const abcColumns = [
  { key: 'product_name', label: 'Товар' },
  { key: 'sku', label: 'Артикул' },
  { key: 'gross_profit', label: 'Вал. прибыль' },
  { key: 'abc', label: 'Группа' },
  { key: 'xyz', label: 'XYZ' },
]

const abcRows = computed(() => (abcXyz.value?.rows ? abcXyz.value.rows.slice(0, 15) : []))

onMounted(reload)
</script>

<template>
  <Head title="Дашборд" />

  <AutometriaLayout
    title="Аналитика"
    active-nav="dashboard"
    :current-shift-open="currentShiftOpen"
    :shift-started-at="shiftStartedAt"
    :shift-revenue="shiftRevenue"
    :breadcrumbs="[{ label: 'Основное' }, { label: 'Аналитика' }]"
  >
    <template #header-meta>
      <span class="ds-badge ds-badge--success">● Live</span>
    </template>

    <!-- Filter Bar -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button
        v-for="p in presets"
        :key="p.key"
        type="button"
        class="ds-btn ds-btn-sm"
        :class="activePreset === p.key ? 'ds-btn-primary' : 'ds-btn-ghost'"
        :data-testid="`range-${p.key}`"
        @click="applyPreset(p.key)"
      >
        {{ p.label }}
      </button>
      <span class="ml-2 font-mono text-[11px]" style="color: var(--color-text-secondary)">
        {{ dateFrom }} → {{ dateTo }}
      </span>
      <span v-if="loading" class="ds-badge ds-badge--warning">Загрузка…</span>
    </div>

    <!-- Summary Cards -->
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
      <div v-for="c in summaryCards" :key="c.label" class="ds-surface p-4" data-testid="summary-card">
        <div class="font-mono text-2xl font-semibold">{{ c.value }}</div>
        <div class="mt-1 text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">
          {{ c.label }}
        </div>
        <div class="mt-1 text-[11px]" style="color: var(--color-text-secondary)">{{ c.sub }}</div>
      </div>
    </div>

    <div v-if="store.error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">
      Ошибка загрузки аналитики: {{ store.error }}
    </div>

    <!-- Charts -->
    <div class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
      <div class="ds-surface p-4">
        <h2 class="mb-2 text-sm font-semibold">Динамика продаж и прибыли</h2>
        <div style="height: 260px">
          <Line :data="lineData" :options="chartOptions" />
        </div>
      </div>
      <div class="ds-surface p-4">
        <h2 class="mb-2 text-sm font-semibold">Топ-10 товаров по валовой прибыли</h2>
        <div style="height: 260px">
          <Bar :data="barData" :options="chartOptions" />
        </div>
      </div>
    </div>

    <!-- ABC/XYZ -->
    <div class="mb-3 flex items-center justify-between gap-3">
      <h2 class="text-sm font-semibold">Матрица ABC / XYZ</h2>
      <span class="text-[11px]" style="color: var(--color-text-secondary)">Группа A — генераторы прибыли</span>
    </div>
    <DsTable :columns="abcColumns" :rows="abcRows" density="compact" sticky-header />
  </AutometriaLayout>
</template>
