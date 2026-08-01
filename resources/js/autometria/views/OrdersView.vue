<script setup>
/**
 * AUTOMETRIA ERP — Orders & Sales (API-wired)
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useOrdersStore } from '@/autometria/stores/ordersStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const emit = defineEmits(['create-order'])

const store = useOrdersStore()
const { rows, meta, loading, creating, query, status: statusFilter, degraded, error } = storeToRefs(store)

const searchRef = ref(null)
let debounceTimer = null

const FILTERS = [
  { id: 'all', label: 'Все' },
  { id: 'in_progress', label: 'В работе' },
  { id: 'ready', label: 'Готовы' },
  { id: 'paid', label: 'Оплачены' },
]

const PAYMENT = {
  paid: { variant: 'success', label: 'Оплачен' },
  partial: { variant: 'warning', label: 'Частично' },
  debt: { variant: 'danger', label: 'Задолженность' },
}

const FULFILLMENT = {
  in_progress: { variant: 'pending', label: 'В работе' },
  assembled: { variant: 'warning', label: 'Собран' },
  ready: { variant: 'open', label: 'Готов к выдаче' },
  done: { variant: 'success', label: 'Выполнен' },
}

const columns = [
  { key: 'number', label: '№ Заказа / Дата' },
  { key: 'client', label: 'Клиент / Контрагент', mono: false },
  { key: 'vehicle', label: 'Автомобиль / VIN' },
  { key: 'items', label: 'Позиций' },
  { key: 'total', label: 'Сумма заказа' },
  { key: 'payment', label: 'Оплата', mono: false },
  { key: 'fulfillment', label: 'Статус исполнения', mono: false },
]

const stats = computed(() => ({
  total: meta.value.total || rows.value.length,
  inWork: rows.value.filter((r) => r.fulfillment === 'in_progress').length,
  ready: rows.value.filter((r) => ['ready', 'assembled'].includes(r.fulfillment)).length,
  debt: rows.value.filter((r) => r.payment === 'debt').length,
}))

function money(n) {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

async function load(opts = {}) {
  try {
    await store.fetchOrders(opts)
  } catch {
    toast.warning(error.value || 'Заказы недоступны — degraded mode', 'Orders')
  }
}

function scheduleSearch() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => load({ page: 1 }), 280)
}

watch(query, scheduleSearch)
watch(statusFilter, () => load({ page: 1 }))

async function focusSearch(e) {
  if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k') return
  e.preventDefault()
  e.stopImmediatePropagation()
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

function onCreate() {
  emit('create-order')
  toast.info('Создание заказа: заполните позиции через API POST /orders или кассу POS', 'Orders')
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
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Orders // /api/v1/orders
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Заказы и продажи</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">Наряды · оплата · выдача · VIN</p>
      </div>
      <div class="flex flex-wrap gap-2 font-mono text-[11px]">
        <DsLoadingBadge v-if="loading || creating" label="Fetching" />
        <DsBadge v-if="degraded" status="warning" label="Degraded" variant="warning" dot />
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #9ca3af">Всего {{ stats.total }}</span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #f59e0b">В работе {{ stats.inWork }}</span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #34d399">Готовы {{ stats.ready }}</span>
        <span class="border px-2 py-1" style="border-color: #1f2937; border-radius: 4px; color: #ef4444">Долг {{ stats.debt }}</span>
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
          placeholder="VIN / № заказа / телефон…"
        >
        <kbd
          class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 font-mono text-[10px]"
          style="color: #f59e0b; border: 1px solid #1f2937; border-radius: 4px; padding: 2px 6px; background: #11151a"
        >⌘K</kbd>
      </div>

      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="f in FILTERS"
          :key="f.id"
          type="button"
          class="border px-2.5 py-1.5 font-mono text-[11px] transition-colors hover:border-amber-500"
          :class="statusFilter === f.id ? 'border-amber-500 text-white' : 'border-[#1F2937]'"
          :style="{
            background: statusFilter === f.id ? '#161b22' : '#0b0d10',
            borderRadius: '4px',
            color: statusFilter === f.id ? '#f59e0b' : '#9ca3af',
          }"
          @click="statusFilter = f.id"
        >
          {{ f.label }}
        </button>
      </div>

      <button
        type="button"
        class="ml-auto border px-3 py-2 font-mono text-[11px] font-bold uppercase tracking-wide"
        style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
        @click="onCreate"
      >
        Создать заказ
      </button>
    </div>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div v-for="n in 8" :key="n" class="h-8 animate-pulse" style="background: #161b22; border-radius: 4px" />
    </div>

    <DsTable
      v-else
      :columns="columns"
      :rows="rows"
      density="compact"
      sticky-header
      max-height="min(62vh, 560px)"
      empty-text="Заказы не найдены"
    >
      <template #number="{ row }">
        <div class="leading-tight">
          <div class="font-mono text-[12px] font-medium tabular-nums text-white">{{ row.number }}</div>
          <div class="font-mono text-[10px] tabular-nums" style="color: #6b7280">{{ row.date }}</div>
        </div>
      </template>
      <template #client="{ row }">
        <div class="leading-tight">
          <div class="font-sans text-[12px] font-medium text-white">{{ row.client }}</div>
          <div class="font-mono text-[10px]" style="color: #6b7280">{{ row.phone }}</div>
        </div>
      </template>
      <template #vehicle="{ row }">
        <div class="leading-tight">
          <div class="font-mono text-[12px]" style="color: #9ca3af">{{ row.vehicle }}</div>
          <div class="font-mono text-[10px] tabular-nums" style="color: #6b7280">{{ row.vin }}</div>
        </div>
      </template>
      <template #items="{ value }">
        <span class="font-mono text-[12px] tabular-nums text-white">{{ value }}</span>
      </template>
      <template #total="{ value }">
        <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #f59e0b">{{ money(value) }}</span>
      </template>
      <template #payment="{ row }">
        <DsBadge
          :variant="(PAYMENT[row.payment] || PAYMENT.debt).variant"
          :label="(PAYMENT[row.payment] || PAYMENT.debt).label"
          :status="row.payment === 'paid' ? 'success' : row.payment === 'partial' ? 'warning' : 'danger'"
          dot
        />
      </template>
      <template #fulfillment="{ row }">
        <DsBadge
          :variant="(FULFILLMENT[row.fulfillment] || FULFILLMENT.in_progress).variant"
          :label="(FULFILLMENT[row.fulfillment] || FULFILLMENT.in_progress).label"
          :status="row.fulfillment"
          dot
        />
      </template>
    </DsTable>
  </div>
</template>
