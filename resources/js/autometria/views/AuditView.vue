<script setup>
/**
 * AUTOMETRIA ERP — Audit Journal (API-wired)
 * Industrial Precision · /api/v1/audit-logs · graceful degrade
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useAuditStore } from '@/autometria/stores/auditStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const store = useAuditStore()
const { rows, meta, loading, error, query, category, degraded } = storeToRefs(store)

const searchRef = ref(null)
let debounceTimer = null

const CATEGORIES = [
  { id: 'all', label: 'Все' },
  { id: 'order', label: 'Заказы' },
  { id: 'payment', label: 'Касса' },
  { id: 'stock', label: 'Склад' },
  { id: 'shift', label: 'Смены' },
  { id: 'user', label: 'Пользователи' },
]

const columns = [
  { key: 'ts', label: 'Время' },
  { key: 'action', label: 'Действие' },
  { key: 'entity', label: 'Объект' },
  { key: 'user', label: 'Пользователь', mono: false },
  { key: 'details', label: 'Детали', mono: false },
  { key: 'severity', label: 'Уровень', mono: false },
]

const stats = computed(() => ({
  total: meta.value.total || rows.value.length,
  critical: rows.value.filter((r) => r.severity === 'danger').length,
  warnings: rows.value.filter((r) => r.severity === 'warning').length,
}))

function badgeFor(severity) {
  if (severity === 'open') return { variant: 'open', label: 'Open', status: 'open' }
  if (severity === 'closed') return { variant: 'closed', label: 'Closed', status: 'closed' }
  const map = {
    info: { variant: 'neutral', label: 'Info', status: 'info' },
    success: { variant: 'success', label: 'OK', status: 'success' },
    warning: { variant: 'warning', label: 'Warning', status: 'warning' },
    danger: { variant: 'danger', label: 'Critical', status: 'danger' },
  }
  return map[severity] || map.info
}

async function load(opts = {}) {
  try {
    await store.fetchLogs(opts)
  } catch {
    toast.warning(error.value || 'Аудит недоступен — degraded mode', 'Audit')
  }
}

function scheduleSearch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    load({ page: 1 })
  }, 280)
}

watch(query, scheduleSearch)
watch(category, () => load({ page: 1 }))

async function focusSearch(e) {
  if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k') return
  e.preventDefault()
  e.stopImmediatePropagation()
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

onMounted(() => {
  window.addEventListener('keydown', focusSearch, true)
  load({ page: 1 })
})

onUnmounted(() => {
  window.removeEventListener('keydown', focusSearch, true)
  clearTimeout(debounceTimer)
})
</script>

<template>
  <div
    class="-m-4 space-y-4 p-4 lg:-m-6 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <div
      class="flex flex-col gap-3 border p-4 sm:flex-row sm:items-center sm:justify-between"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div>
        <div
          class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
          style="color: #f59e0b"
        >
          Audit // Append-only · /api/v1/audit-logs
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">
          Журнал действий
        </h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Неизменяемый аудит · пагинация · фильтры
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2 font-mono text-[11px]">
        <DsLoadingBadge
          v-if="loading"
          label="Fetching"
        />
        <DsBadge
          v-if="degraded"
          status="warning"
          label="Degraded"
          variant="warning"
          dot
        />
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #9ca3af">
          Записей {{ stats.total }}
        </span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #f59e0b">
          Warning {{ stats.warnings }}
        </span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #ef4444">
          Critical {{ stats.critical }}
        </span>
        <DsBadge
          status="closed"
          label="Append-only"
          variant="closed"
          dot
        />
      </div>
    </div>

    <div
      class="flex flex-wrap items-center gap-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="relative min-w-[220px] flex-1">
        <input
          ref="searchRef"
          v-model="query"
          class="ds-input pr-14 font-mono text-xs"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          type="search"
          placeholder="Action / user / entity / IP…"
        >
        <kbd
          class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 font-mono text-[10px]"
          style="
            color: #f59e0b;
            border: 1px solid #1f2937;
            border-radius: 4px;
            padding: 2px 6px;
            background: #11151a;
          "
        >⌘K</kbd>
      </div>

      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="c in CATEGORIES"
          :key="c.id"
          type="button"
          class="border px-2.5 py-1.5 font-mono text-[11px] transition-colors hover:border-amber-500"
          :class="category === c.id ? 'border-amber-500' : 'border-[#1F2937]'"
          :style="{
            background: category === c.id ? '#161b22' : '#0b0d10',
            borderRadius: '4px',
            color: category === c.id ? '#f59e0b' : '#9ca3af',
          }"
          @click="category = c.id"
        >
          {{ c.label }}
        </button>
      </div>
    </div>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div
        v-for="n in 6"
        :key="n"
        class="h-8 animate-pulse"
        style="background: #161b22; border-radius: 4px"
      />
    </div>

    <DsTable
      v-else
      :columns="columns"
      :rows="rows"
      density="compact"
      sticky-header
      max-height="min(62vh, 560px)"
      empty-text="Записи аудита не найдены"
      row-key="id"
    >
      <template #ts="{ value }">
        <span class="font-mono text-[11px] tabular-nums" style="color: #9ca3af">{{ value || '—' }}</span>
      </template>
      <template #action="{ row }">
        <div class="leading-tight">
          <div class="font-mono text-[12px] font-medium text-white">{{ row.action }}</div>
          <div class="font-mono text-[10px]" style="color: #6b7280">{{ row.id }}</div>
        </div>
      </template>
      <template #entity="{ row }">
        <div class="leading-tight">
          <div class="font-mono text-[12px]" style="color: #9ca3af">{{ row.entity }}</div>
          <div class="font-mono text-[10px] tabular-nums" style="color: #6b7280">{{ row.entityId }}</div>
        </div>
      </template>
      <template #user="{ row }">
        <div class="leading-tight">
          <div class="font-sans text-[12px] font-medium text-white">{{ row.user }}</div>
          <div class="font-mono text-[10px]" style="color: #6b7280">{{ row.role }} · {{ row.ip }}</div>
        </div>
      </template>
      <template #details="{ row }">
        <div class="leading-tight">
          <div class="font-sans text-[12px]" style="color: #e5e7eb">{{ row.details }}</div>
          <div
            v-if="row.reason && row.reason !== '—'"
            class="font-mono text-[10px]"
            style="color: #f59e0b"
          >
            reason: {{ row.reason }}
          </div>
        </div>
      </template>
      <template #severity="{ row }">
        <DsBadge
          v-bind="badgeFor(row.severity)"
          dot
        />
      </template>
    </DsTable>

    <div
      v-if="meta.last_page > 1"
      class="flex items-center justify-between gap-2 font-mono text-[11px]"
      style="color: #9ca3af"
    >
      <span>page {{ meta.current_page }} / {{ meta.last_page }}</span>
      <div class="flex gap-2">
        <button
          type="button"
          class="border border-[#1F2937] px-2 py-1 hover:border-amber-500 disabled:opacity-40"
          style="border-radius: 4px; background: #11151a"
          :disabled="meta.current_page <= 1 || loading"
          @click="load({ page: meta.current_page - 1 })"
        >
          Prev
        </button>
        <button
          type="button"
          class="border border-[#1F2937] px-2 py-1 hover:border-amber-500 disabled:opacity-40"
          style="border-radius: 4px; background: #11151a"
          :disabled="meta.current_page >= meta.last_page || loading"
          @click="load({ page: meta.current_page + 1 })"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>
