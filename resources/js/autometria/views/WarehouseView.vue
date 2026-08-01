<script setup>
/**
 * AUTOMETRIA ERP — Warehouse / Stock balances (API-wired)
 * Industrial Precision · /api/v1/stock · ⌘K search
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const store = useWarehouseStore()
const {
  rows,
  warehouses,
  categories,
  meta,
  loading,
  error,
  query,
  category,
  warehouse,
  degraded,
} = storeToRefs(store)

const searchRef = ref(null)
let debounceTimer = null

const STATUS = {
  ok: { variant: 'success', label: 'В наличии' },
  low: { variant: 'warning', label: 'Низкий остаток' },
  critical: { variant: 'danger', label: 'Дефицит / 0' },
}

const columns = [
  { key: 'sku', label: 'Артикул / OEM' },
  { key: 'name', label: 'Наименование', mono: false },
  { key: 'category', label: 'Категория / Группа', mono: false },
  { key: 'cell', label: 'Ячейка / Стеллаж' },
  { key: 'available', label: 'В наличии' },
  { key: 'reserved', label: 'В резерве' },
  { key: 'price', label: 'Цена' },
  { key: 'status', label: 'Статус остатка', mono: false },
]

const categoryOptions = computed(() => ['all', ...categories.value])
const warehouseOptions = computed(() => ['all', ...warehouses.value.map((w) => w.name)])

const stats = computed(() => ({
  skus: meta.value.total || rows.value.length,
  critical: rows.value.filter((r) => r.status === 'critical').length,
  low: rows.value.filter((r) => r.status === 'low').length,
  reserved: rows.value.reduce((s, r) => s + Number(r.reserved || 0), 0),
}))

function money(n) {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

async function load(opts = {}) {
  try {
    await store.fetchStock(opts)
  } catch {
    toast.warning(error.value || 'Склад недоступен — degraded mode', 'Warehouse')
  }
}

function scheduleSearch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => load({ page: 1 }), 280)
}

watch(query, scheduleSearch)
watch([category, warehouse], () => load({ page: 1 }))

async function focusSearch(e) {
  if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k') return
  e.preventDefault()
  e.stopImmediatePropagation()
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

onMounted(async () => {
  window.addEventListener('keydown', focusSearch, true)
  await store.fetchWarehouses()
  await load({ page: 1 })
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
          Warehouse // /api/v1/stock
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">
          Склад и остатки
        </h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Факт − резерв = доступно · OEM · розница
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
          SKU {{ stats.skus }}
        </span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #f59e0b">
          Низкий {{ stats.low }}
        </span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #ef4444">
          Дефицит {{ stats.critical }}
        </span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #6b7280">
          Резерв {{ stats.reserved }}
        </span>
      </div>
    </div>

    <div
      class="flex flex-wrap items-center gap-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="relative min-w-[240px] flex-1">
        <input
          ref="searchRef"
          v-model="query"
          class="ds-input pr-14 font-mono text-xs"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          type="search"
          placeholder="Артикул / OEM / наименование…"
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

      <select
        v-model="category"
        class="ds-select text-xs"
        style="border-radius: 4px; background: #161b22; border-color: #1f2937"
      >
        <option
          v-for="c in categoryOptions"
          :key="c"
          :value="c"
        >
          {{ c === 'all' ? 'Все категории' : c }}
        </option>
      </select>

      <select
        v-model="warehouse"
        class="ds-select text-xs"
        style="border-radius: 4px; background: #161b22; border-color: #1f2937"
      >
        <option
          v-for="w in warehouseOptions"
          :key="w"
          :value="w"
        >
          {{ w === 'all' ? 'Все склады' : w }}
        </option>
      </select>

      <span class="font-mono text-xs" style="color: #9ca3af">
        {{ rows.length }} results
      </span>
    </div>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div
        v-for="n in 8"
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
      empty-text="Номенклатура не найдена"
    >
      <template #sku="{ row }">
        <div class="leading-tight">
          <div class="font-mono text-[12px] font-medium tabular-nums text-white">{{ row.sku }}</div>
          <div class="font-mono text-[10px] tabular-nums" style="color: #6b7280">OEM {{ row.oem }}</div>
        </div>
      </template>
      <template #name="{ value }">
        <span class="font-sans text-[12px] font-medium text-white">{{ value }}</span>
      </template>
      <template #category="{ value }">
        <span class="font-sans text-[12px]" style="color: #9ca3af">{{ value }}</span>
      </template>
      <template #cell="{ row }">
        <div class="leading-tight">
          <div class="font-mono text-[12px] tabular-nums" style="color: #9ca3af">{{ row.cell }}</div>
          <div class="font-mono text-[10px]" style="color: #6b7280">{{ row.warehouse }}</div>
        </div>
      </template>
      <template #available="{ value }">
        <span class="font-mono text-[12px] font-bold tabular-nums text-white">{{ value }}</span>
      </template>
      <template #reserved="{ value }">
        <span class="font-mono text-[12px] tabular-nums" style="color: #6b7280">{{ value }}</span>
      </template>
      <template #price="{ value }">
        <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #f59e0b">
          {{ money(value) }}
        </span>
      </template>
      <template #status="{ row }">
        <DsBadge
          :variant="(STATUS[row.status] || STATUS.ok).variant"
          :label="(STATUS[row.status] || STATUS.ok).label"
          :status="row.status"
          dot
        />
      </template>
    </DsTable>
  </div>
</template>
