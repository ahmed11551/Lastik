<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Orders & Sales (Mobile-First + Bulk)
 * Bulk: POST /api/v1/orders/bulk-status
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useOrdersStore } from '@/autometria/stores/ordersStore'
import { CRM_BULK_STATUSES, type CrmBulkStatus } from '@/autometria/api/bulk'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const emit = defineEmits<{
  (e: 'create-order'): void
}>()

const store = useOrdersStore()
const {
  rows,
  meta,
  loading,
  creating,
  bulkPending,
  query,
  status: statusFilter,
  degraded,
  error,
} = storeToRefs(store)

const searchRef = ref<HTMLInputElement | null>(null)
const tableRef = ref<{ clearSelection?: () => void } | null>(null)
const selectedKeys = ref<Array<string | number>>([])
const bulkStatus = ref<CrmBulkStatus>('accepted')
const statusMenuOpen = ref(false)
const createSheetOpen = ref(false)
const draftNote = ref('')
let debounceTimer: ReturnType<typeof setTimeout> | null = null

type FilterId = 'all' | 'in_progress' | 'ready' | 'paid'

const FILTERS: Array<{ id: FilterId; label: string }> = [
  { id: 'all', label: 'Все' },
  { id: 'in_progress', label: 'В работе' },
  { id: 'ready', label: 'Готовы' },
  { id: 'paid', label: 'Оплачены' },
]

const PAYMENT: Record<string, { variant: string; label: string }> = {
  paid: { variant: 'success', label: 'Оплачен' },
  partial: { variant: 'warning', label: 'Частично' },
  debt: { variant: 'danger', label: 'Задолженность' },
}

const FULFILLMENT: Record<string, { variant: string; label: string }> = {
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
  inWork: rows.value.filter((r: { fulfillment?: string }) => r.fulfillment === 'in_progress').length,
  ready: rows.value.filter((r: { fulfillment?: string }) => ['ready', 'assembled'].includes(r.fulfillment || '')).length,
  debt: rows.value.filter((r: { payment?: string }) => r.payment === 'debt').length,
}))

function money(n: number | string | null | undefined): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

function isSelected(id: number | string): boolean {
  return selectedKeys.value.some((k) => Number(k) === Number(id))
}

function toggleCard(id: number | string): void {
  const num = Number(id)
  if (selectedKeys.value.some((k) => Number(k) === num)) {
    selectedKeys.value = selectedKeys.value.filter((k) => Number(k) !== num)
  } else {
    selectedKeys.value = [...selectedKeys.value, num]
  }
}

async function load(opts: Record<string, unknown> = {}): Promise<void> {
  try {
    await store.fetchOrders(opts)
  } catch {
    toast.warning(error.value || 'Заказы недоступны — degraded mode', 'Orders')
  }
}

function scheduleSearch(): void {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => load({ page: 1 }), 280)
}

watch(query, scheduleSearch)
watch(statusFilter, () => load({ page: 1 }))

async function focusSearch(e: KeyboardEvent): Promise<void> {
  if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k') return
  e.preventDefault()
  e.stopImmediatePropagation()
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

function openCreate(): void {
  createSheetOpen.value = true
}

function closeCreate(): void {
  createSheetOpen.value = false
}

function submitCreateDraft(): void {
  emit('create-order')
  createSheetOpen.value = false
  toast.info(
    draftNote.value
      ? `Черновик: ${draftNote.value}. Создайте позиции через POS или POST /orders`
      : 'Создание заказа: заполните позиции через API POST /orders или кассу POS',
    'Orders',
  )
  draftNote.value = ''
}

function clearSelection(): void {
  selectedKeys.value = []
  tableRef.value?.clearSelection?.()
}

async function applyBulkStatus(status: CrmBulkStatus): Promise<void> {
  statusMenuOpen.value = false
  bulkStatus.value = status
  try {
    await store.bulkStatus(selectedKeys.value, status, { clearSelection })
  } catch {
    /* toast via store */
  }
}

function onDocClick(e: MouseEvent): void {
  if (!statusMenuOpen.value) return
  const el = (e.target as HTMLElement | null)?.closest?.('[data-bulk-status-menu]')
  if (!el) statusMenuOpen.value = false
}

onMounted(() => {
  window.addEventListener('keydown', focusSearch, true)
  document.addEventListener('click', onDocClick)
  load({ page: 1 })
})

onUnmounted(() => {
  window.removeEventListener('keydown', focusSearch, true)
  document.removeEventListener('click', onDocClick)
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden p-3 sm:space-y-4 sm:p-4 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <!-- Header -->
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Orders // /api/v1/orders
        </div>
        <h2 class="truncate text-sm font-medium text-white sm:text-base">Заказы и продажи</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">Наряды · оплата · выдача · VIN</p>
      </div>
      <div class="flex flex-wrap gap-2 font-mono text-[11px]">
        <DsLoadingBadge v-if="loading || creating || bulkPending" label="Fetching" />
        <DsBadge v-if="degraded" status="warning" label="Degraded" variant="warning" dot />
        <span class="border px-2 py-1.5" style="border-color: #1f2937; border-radius: 4px; color: #9ca3af">Всего {{ stats.total }}</span>
        <span class="border px-2 py-1.5" style="border-color: #1f2937; border-radius: 4px; color: #f59e0b">В работе {{ stats.inWork }}</span>
        <span class="hidden border px-2 py-1.5 xs:inline sm:inline" style="border-color: #1f2937; border-radius: 4px; color: #34d399">Готовы {{ stats.ready }}</span>
        <span class="border px-2 py-1.5" style="border-color: #1f2937; border-radius: 4px; color: #ef4444">Долг {{ stats.debt }}</span>
      </div>
    </div>

    <!-- Touch toolbar -->
    <div
      class="flex flex-col gap-2 border p-3 sm:flex-row sm:flex-wrap sm:items-center"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="relative w-full min-w-0 flex-1 sm:min-w-[200px]">
        <input
          ref="searchRef"
          v-model="query"
          class="ds-input h-11 w-full pr-12 font-mono text-sm sm:h-10 sm:text-xs"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          type="search"
          inputmode="search"
          enterkeyhint="search"
          placeholder="VIN / № / телефон…"
          autocomplete="off"
        >
        <kbd
          class="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 font-mono text-[10px] sm:inline"
          style="color: #f59e0b; border: 1px solid #1f2937; border-radius: 4px; padding: 2px 6px; background: #11151a"
        >⌘K</kbd>
      </div>

      <div class="no-scrollbar -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5">
        <button
          v-for="f in FILTERS"
          :key="f.id"
          type="button"
          class="h-11 shrink-0 border px-4 font-mono text-[12px] transition-colors sm:h-9 sm:px-2.5 sm:text-[11px]"
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
        class="h-11 w-full border px-3 font-mono text-[12px] font-bold uppercase tracking-wide sm:ml-auto sm:h-9 sm:w-auto sm:text-[11px]"
        style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
        @click="openCreate"
      >
        Создать заказ
      </button>
    </div>

    <!-- Mobile bulk bar (cards selection) -->
    <div
      v-if="selectedKeys.length"
      class="flex flex-wrap items-center gap-2 border px-3 py-2 sm:hidden"
      style="background: color-mix(in srgb, #1e1b4b 72%, #11151a); border-color: #1f2937; border-radius: 4px"
      data-bulk-status-menu
    >
      <span class="font-mono text-[12px]" style="color: #9ca3af">{{ selectedKeys.length }} выбрано</span>
      <button
        type="button"
        class="ds-btn ds-btn-ghost ds-btn-sm h-10"
        :disabled="bulkPending"
        @click.stop="statusMenuOpen = !statusMenuOpen"
      >
        Сменить статус ▾
      </button>
      <div
        v-if="statusMenuOpen"
        class="w-full space-y-1 border py-1"
        style="background: #11151a; border-color: #1f2937; border-radius: 4px"
      >
        <button
          v-for="s in CRM_BULK_STATUSES"
          :key="s.value"
          type="button"
          class="flex h-11 w-full items-center gap-2 px-3 text-left"
          :disabled="bulkPending"
          @click="applyBulkStatus(s.value)"
        >
          <DsBadge :status="s.value" :label="s.label" dot />
        </button>
      </div>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm h-10 ml-auto" @click="clearSelection">Снять</button>
    </div>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div v-for="n in 6" :key="n" class="h-16 animate-pulse sm:h-8" style="background: #161b22; border-radius: 4px" />
    </div>

    <template v-else>
    <!-- Mobile cards < sm -->
    <div class="space-y-2 sm:hidden">
      <article
        v-for="row in rows"
        :key="row.id"
        class="border p-3 transition-colors"
        :class="isSelected(row.id) ? 'border-indigo-400' : 'border-[#1F2937]'"
        :style="{
          background: isSelected(row.id) ? '#1E1B4B' : '#11151A',
          borderRadius: '4px',
        }"
        @click="toggleCard(row.id)"
      >
        <div class="flex items-start gap-3">
          <input
            type="checkbox"
            class="mt-1 h-5 w-5 accent-[var(--color-primary)]"
            :checked="isSelected(row.id)"
            @click.stop
            @change="toggleCard(row.id)"
          >
          <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex items-center justify-between gap-2">
              <span class="font-mono text-[13px] font-medium text-white">{{ row.number }}</span>
              <span class="font-mono text-[13px] font-bold tabular-nums" style="color: #f59e0b">{{ money(row.total) }}</span>
            </div>
            <div class="truncate text-[13px] text-white">{{ row.client }}</div>
            <div class="truncate font-mono text-[11px]" style="color: #6b7280">{{ row.phone }} · {{ row.date }}</div>
            <div class="truncate font-mono text-[11px]" style="color: #9ca3af">{{ row.vehicle }} · {{ row.vin }}</div>
            <div class="flex flex-wrap gap-1.5 pt-1">
              <DsBadge
                :variant="(PAYMENT[row.payment] || PAYMENT.debt).variant"
                :label="(PAYMENT[row.payment] || PAYMENT.debt).label"
                :status="row.payment === 'paid' ? 'success' : row.payment === 'partial' ? 'warning' : 'danger'"
                dot
              />
              <DsBadge
                :variant="(FULFILLMENT[row.fulfillment] || FULFILLMENT.in_progress).variant"
                :label="(FULFILLMENT[row.fulfillment] || FULFILLMENT.in_progress).label"
                :status="row.fulfillment"
                dot
              />
            </div>
          </div>
        </div>
      </article>
      <p v-if="!rows.length" class="py-8 text-center text-sm" style="color: #9ca3af">Заказы не найдены</p>
    </div>

    <!-- Desktop / tablet table -->
    <div class="hidden min-w-0 sm:block">
      <DsTable
        ref="tableRef"
        v-model:selected-keys="selectedKeys"
        :columns="columns"
        :rows="rows"
        density="compact"
        sticky-header
        selectable
        max-height="min(62vh, 560px)"
        empty-text="Заказы не найдены"
      >
        <template #bulk-actions>
          <div class="relative" data-bulk-status-menu>
            <button
              type="button"
              class="ds-btn ds-btn-ghost ds-btn-sm"
              :disabled="bulkPending"
              @click.stop="statusMenuOpen = !statusMenuOpen"
            >
              Сменить статус
              <span class="opacity-60">▾</span>
            </button>
            <div
              v-if="statusMenuOpen"
              class="absolute left-0 top-full z-20 mt-1 min-w-[160px] border py-1"
              style="background: #11151a; border-color: #1f2937; border-radius: 4px"
            >
              <button
                v-for="s in CRM_BULK_STATUSES"
                :key="s.value"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left font-mono text-[11px] hover:bg-[#1E1B4B]"
                :style="{ color: bulkStatus === s.value ? '#f59e0b' : '#e5e7eb' }"
                :disabled="bulkPending"
                @click="applyBulkStatus(s.value)"
              >
                <DsBadge :status="s.value" :label="s.label" dot />
              </button>
            </div>
          </div>
        </template>

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

    <!-- Bottom sheet: create order (mobile-first; also usable on desktop) -->
    <Teleport to="body">
      <div
        v-if="createSheetOpen"
        class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center"
        role="dialog"
        aria-modal="true"
        aria-label="Создать заказ"
      >
        <button
          type="button"
          class="absolute inset-0 bg-black/60"
          aria-label="Закрыть"
          @click="closeCreate"
        />
        <div
          class="relative z-10 w-full max-w-lg border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
          style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
        >
          <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />
          <h3 class="text-sm font-semibold text-white">Новый заказ / наряд</h3>
          <p class="mt-1 text-xs" style="color: #9ca3af">
            На смартфоне — bottom-sheet. Позиции можно добавить в кассе POS.
          </p>
          <label class="mt-4 block text-[12px]" style="color: #9ca3af">
            Комментарий
            <textarea
              v-model="draftNote"
              rows="3"
              class="ds-input mt-1 w-full resize-none text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
              placeholder="VIN, пожелания клиента…"
            />
          </label>
          <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <button
              type="button"
              class="h-12 flex-1 border font-mono text-[12px] font-bold uppercase sm:h-10"
              style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
              @click="submitCreateDraft"
            >
              Продолжить
            </button>
            <button
              type="button"
              class="h-12 border font-mono text-[12px] sm:h-10 sm:px-4"
              style="border-color: #1f2937; border-radius: 4px; color: #9ca3af; background: #0b0d10"
              @click="closeCreate"
            >
              Отмена
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
