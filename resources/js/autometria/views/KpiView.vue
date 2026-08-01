<script setup>
/**
 * AUTOMETRIA ERP — KPI Dashboard (API-wired)
 */
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useKpiStore } from '@/autometria/stores/kpiStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

defineProps({
  shiftOpen: { type: Boolean, default: true },
})

const store = useKpiStore()
const { cards, rows: detailRows, loading, degraded } = storeToRefs(store)

const roleFilter = ref('all')
const period = ref('shift')

const filteredRows = computed(() => {
  if (roleFilter.value === 'all') return detailRows.value
  return detailRows.value
})

const totals = computed(() => {
  const kpi = filteredRows.value.reduce((s, r) => s + Number(r.kpi || 0), 0)
  const jobs = filteredRows.value.reduce((s, r) => s + Number(r.jobs || 0), 0)
  return { kpi, jobs, people: filteredRows.value.length }
})

const columns = [
  { key: 'employee', label: 'Сотрудник', mono: false },
  { key: 'role', label: 'Роль', mono: false },
  { key: 'jobs', label: 'Операции' },
  { key: 'base', label: 'База ₽' },
  { key: 'rate', label: 'Ставка' },
  { key: 'kpi', label: 'KPI ₽' },
  { key: 'status', label: 'Статус', mono: false },
  { key: 'ts', label: 'Обновлено' },
]

function sparkPoints(values) {
  const list = Array.isArray(values) && values.length ? values : [0, 0]
  const w = 96
  const h = 28
  const min = Math.min(...list)
  const max = Math.max(...list)
  const span = max - min || 1
  return list
    .map((v, i) => {
      const x = list.length === 1 ? 0 : (i / (list.length - 1)) * w
      const y = h - ((v - min) / span) * (h - 2) - 1
      return `${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')
}

function money(n) {
  return Number(n || 0).toLocaleString('ru-RU')
}

onMounted(async () => {
  try {
    await store.fetchSummary()
  } catch {
    toast.warning('KPI недоступен — degraded mode', 'KPI')
  }
})
</script>

<template>
  <div
    class="kpi-screen -m-4 space-y-4 p-4 lg:-m-6 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <!-- Local page band (shift/⌘K live in AutometriaLayout header) -->
    <div
      class="flex flex-col gap-3 border p-4 sm:flex-row sm:items-center sm:justify-between"
      style="
        background: var(--autometria-surface, #11151a);
        border-color: #1f2937;
        border-radius: 4px;
      "
    >
      <div>
        <div
          class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
          style="color: #f59e0b"
        >
          KPI Dashboard // /api/v1/kpi/summary
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">
          Выработка &amp; расчёт KPI начислений
        </h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Процент с продаж · мастерский % с шиномонтажа · нагрузка смены
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <DsLoadingBadge v-if="loading" label="Fetching" />
        <DsBadge v-if="degraded" status="warning" label="Degraded" variant="warning" dot />
        <div
          class="inline-flex border p-0.5"
          style="border-color: #1f2937; border-radius: 4px; background: #161b22"
        >
          <button
            v-for="p in [
              { id: 'shift', label: 'Смена' },
              { id: 'day', label: 'День' },
              { id: 'week', label: 'Неделя' },
            ]"
            :key="p.id"
            type="button"
            class="px-3 py-1.5 text-xs font-medium transition-colors"
            :style="
              period === p.id
                ? { background: '#F59E0B', color: '#0B0D10', borderRadius: '4px' }
                : { color: '#9CA3AF', borderRadius: '4px' }
            "
            @click="period = p.id"
          >
            {{ p.label }}
          </button>
        </div>

        <select
          v-model="roleFilter"
          class="ds-select text-xs"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
        >
          <option value="all">Все роли</option>
          <option value="manager">Менеджеры</option>
          <option value="master">Мастера</option>
          <option value="other">Прочие</option>
        </select>
      </div>
    </div>

    <!-- 4 KPI cards + sparklines -->
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
      <article
        v-for="card in cards"
        :key="card.id"
        class="border p-4"
        style="
          background: var(--autometria-surface, #11151a);
          border-color: #1f2937;
          border-radius: 4px;
        "
      >
        <div class="flex items-start justify-between gap-2">
          <span class="text-xs font-medium" style="color: #9ca3af">{{ card.label }}</span>
          <span
            class="font-mono text-[11px] font-medium tabular-nums"
            :style="{ color: card.deltaPositive ? '#10B981' : '#EF4444' }"
          >
            Δ {{ card.delta }}
          </span>
        </div>

        <div
          class="mt-2 font-mono text-2xl font-bold tabular-nums tracking-tight sm:text-3xl"
          :style="{ color: card.accent ? '#F59E0B' : '#FFFFFF' }"
        >
          {{ card.value }}
        </div>

        <svg
          class="mt-3 block w-full"
          viewBox="0 0 96 28"
          preserveAspectRatio="none"
          height="28"
          aria-hidden="true"
        >
          <polyline
            fill="none"
            :stroke="card.accent ? '#F59E0B' : '#6B7280'"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            :points="sparkPoints(card.spark)"
          />
        </svg>
      </article>
    </div>

    <!-- Summary strip -->
    <div
      class="grid grid-cols-3 gap-3 border p-3"
      style="background: #161b22; border-color: #1f2937; border-radius: 4px"
    >
      <div>
        <div class="text-xs font-medium" style="color: #9ca3af">Начислено KPI</div>
        <div class="mt-1 font-mono text-xl font-bold tabular-nums" style="color: #f59e0b">
          ₽{{ money(totals.kpi) }}
        </div>
      </div>
      <div>
        <div class="text-xs font-medium" style="color: #9ca3af">Операций</div>
        <div class="mt-1 font-mono text-xl font-bold tabular-nums text-white">
          {{ totals.jobs }}
        </div>
      </div>
      <div>
        <div class="text-xs font-medium" style="color: #9ca3af">Сотрудников</div>
        <div class="mt-1 font-mono text-xl font-bold tabular-nums text-white">
          {{ totals.people }}
        </div>
      </div>
    </div>

    <!-- Detail table compact 32px -->
    <div>
      <div class="mb-2 flex items-center justify-between gap-2">
        <h3 class="text-sm font-medium text-white">Детализация начислений</h3>
        <span class="font-mono text-[11px]" style="color: #9ca3af">row-height: 32px · compact</span>
      </div>

      <DsTable
        :columns="columns"
        :rows="filteredRows"
        density="compact"
        sticky-header
        max-height="min(48vh, 420px)"
        empty-text="Нет начислений за период"
      >
        <template #employee="{ value }">
          <span class="font-sans text-[12px] font-medium text-white">{{ value }}</span>
        </template>
        <template #role="{ value }">
          <span class="font-sans text-[12px]" style="color: #9ca3af">{{ value }}</span>
        </template>
        <template #jobs="{ value }">
          <span class="font-mono tabular-nums">{{ value }}</span>
        </template>
        <template #base="{ value }">
          <span class="font-mono tabular-nums">{{ money(value) }}</span>
        </template>
        <template #rate="{ value }">
          <span class="font-mono tabular-nums" style="color: #f59e0b">{{ value }}</span>
        </template>
        <template #kpi="{ value }">
          <span class="font-mono font-medium tabular-nums text-white">{{ money(value) }}</span>
        </template>
        <template #status="{ value }">
          <DsBadge
            :status="value"
            dot
          />
        </template>
        <template #ts="{ value }">
          <span class="font-mono tabular-nums" style="color: #9ca3af">{{ value }}</span>
        </template>
      </DsTable>
    </div>
  </div>
</template>
