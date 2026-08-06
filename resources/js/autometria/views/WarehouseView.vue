<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Warehouse (Block 2.2)
 * Inventory · Transfer · FIFO lots · Bulk
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import InventoryAdjustmentModal from '@/autometria/components/warehouse/InventoryAdjustmentModal.vue'
import StockTransferModal from '@/autometria/components/warehouse/StockTransferModal.vue'
import FifoLotsPanel from '@/autometria/components/warehouse/FifoLotsPanel.vue'
import SmartPurchasesPanel from '@/autometria/components/warehouse/SmartPurchasesPanel.vue'
import { useBarcodeScanner } from '@/autometria/composables/useBarcodeScanner'
import { parseGs1 } from '@/autometria/utils/gs1Parser'

type StockRow = {
  id: number
  product_id: number
  warehouse_id: number
  sku: string
  oem?: string
  name: string
  category?: string
  warehouse?: string
  cell?: string
  available: number
  reserved: number
  price: number
  status: string
}

const store = useWarehouseStore()
const {
  rows,
  warehouses,
  categories,
  meta,
  loading,
  bulkPending,
  opPending,
  error,
  query,
  category,
  warehouse,
  degraded,
} = storeToRefs(store)

const searchRef = ref<HTMLInputElement | null>(null)
const tableRef = ref<{ clearSelection?: () => void } | null>(null)
const selectedKeys = ref<Array<string | number>>([])
const bulkCategory = ref('')
const bulkAdjustment = ref(1)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

const inventoryOpen = ref(false)
const inventoryRow = ref<StockRow | null>(null)
const transferOpen = ref(false)
const panel = ref<'stock' | 'smart'>('stock')

const STATUS: Record<string, { variant: string; label: string }> = {
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
  { key: 'actions', label: 'Операции', mono: false },
]

const categoryOptions = computed(() => ['all', ...categories.value])
const warehouseOptions = computed(() => ['all', ...warehouses.value.map((w: { name: string }) => w.name)])
const bulkCategoryOptions = computed(() => {
  const fromMeta = categories.value.filter(Boolean)
  const defaults = ['tires', 'disks', 'services', 'parts']
  return [...new Set([...fromMeta, ...defaults])]
})

const stats = computed(() => ({
  skus: meta.value.total || rows.value.length,
  critical: rows.value.filter((r: StockRow) => r.status === 'critical').length,
  low: rows.value.filter((r: StockRow) => r.status === 'low').length,
  reserved: rows.value.reduce((s: number, r: StockRow) => s + Number(r.reserved || 0), 0),
}))

const selectedRows = computed(() =>
  rows.value.filter((r: StockRow) => selectedKeys.value.some((k) => Number(k) === Number(r.id))),
)

const transferSeed = computed(() =>
  selectedRows.value.map((r: StockRow) => ({
    product_id: Number(r.product_id),
    stock_id: Number(r.id),
    sku: r.sku,
    name: r.name,
    available: Number(r.available || 0),
    qty: Math.min(1, Number(r.available || 0)),
  })),
)

const defaultFromWarehouseId = computed(() => {
  const first = selectedRows.value[0]
  return first ? Number(first.warehouse_id) : warehouses.value[0]?.id ?? null
})

// --- WMS Barcode / DataMatrix scanner (Sprint 2): bin + lot ---
const scanner = useBarcodeScanner()
const wmsVideoRef = ref<HTMLVideoElement | null>(null)
const wmsScanOpen = ref(false)
const scannedCell = ref<string | null>(null)
const scannedLot = ref<{ gtin: string | null; batch: string | null; serial: string | null } | null>(null)

async function startWmsScan(): Promise<void> {
  wmsScanOpen.value = true
  await nextTick()
  if (!wmsVideoRef.value) return
  await scanner.start(wmsVideoRef.value, onWmsScan)
  if (!scanner.supported.value && scanner.error.value) {
    toast.warning(scanner.error.value, 'WMS Сканер')
  }
}

function stopWmsScan(): void {
  scanner.stop()
  wmsScanOpen.value = false
}

function onWmsScan(raw: string): void {
  const parsed = parseGs1(raw)
  if (parsed.gtin || parsed.batch || parsed.serial) {
    // DataMatrix партии товара (Честный Знак / GS1)
    scannedLot.value = { gtin: parsed.gtin, batch: parsed.batch, serial: parsed.serial }
    toast.info(
      `Партия: GTIN ${parsed.gtin ?? '—'}, партия ${parsed.batch ?? '—'}, серия ${parsed.serial ?? '—'}`,
      'WMS',
    )
  } else {
    // Иначе считаем ячейкой (bin)
    scannedCell.value = raw.trim()
    toast.info(`Ячейка: ${raw.trim()}`, 'WMS')
  }
  stopWmsScan()
}

function money(n: number | string | null | undefined): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

function selectedIds(): number[] {
  return selectedKeys.value.map(Number).filter((n) => Number.isInteger(n) && n > 0)
}

function clearTableSelection(): void {
  selectedKeys.value = []
  tableRef.value?.clearSelection?.()
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
    await store.fetchStock(opts)
  } catch {
    toast.warning(error.value || 'Склад недоступен — degraded mode', 'Warehouse')
  }
}

function scheduleSearch(): void {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => load({ page: 1 }), 280)
}

watch(query, scheduleSearch)
watch([category, warehouse], () => load({ page: 1 }))

async function focusSearch(e: KeyboardEvent): Promise<void> {
  if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k') return
  e.preventDefault()
  e.stopImmediatePropagation()
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

async function applyCategory(): Promise<void> {
  const ids = selectedIds()
  const cat = String(bulkCategory.value || '').trim()
  if (!ids.length) {
    toast.warning('Не выбрана ни одна запись')
    return
  }
  if (!cat) {
    toast.warning('Укажите категорию')
    return
  }
  try {
    await store.bulkUpdate(ids, 'update_category', { category: cat }, {
      clearSelection: clearTableSelection,
    })
  } catch {
    /* toast via store */
  }
}

async function applyAdjustment(delta?: number): Promise<void> {
  const ids = selectedIds()
  if (!ids.length) {
    toast.warning('Не выбрана ни одна запись')
    return
  }
  const adjustment = Number.isFinite(Number(delta)) ? Number(delta) : Number(bulkAdjustment.value)
  if (!Number.isInteger(adjustment) || adjustment === 0) {
    toast.warning('Укажите целое ненулевое изменение остатка')
    return
  }
  try {
    await store.bulkUpdate(ids, 'adjust_actual', { adjustment }, {
      clearSelection: clearTableSelection,
    })
  } catch {
    /* toast via store */
  }
}

function focusScan(): void {
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

function openInventory(row: StockRow): void {
  inventoryRow.value = row
  inventoryOpen.value = true
}

function openInventoryFromSelection(): void {
  const row = selectedRows.value[0]
  if (!row) {
    toast.warning('Выберите одну позицию для инвентаризации')
    return
  }
  openInventory(row)
}

function openTransfer(): void {
  if (!selectedRows.value.length) {
    toast.warning('Выберите товары для перемещения')
    return
  }
  if (warehouses.value.length < 2) {
    toast.warning('Нужно минимум два склада')
    return
  }
  transferOpen.value = true
}

async function confirmInventory(payload: {
  product_id: number
  warehouse_id: number
  stock_id?: number
  actual_qty: number
  reason: string
}): Promise<void> {
  try {
    await store.inventoryAdjust(payload)
    inventoryOpen.value = false
    clearTableSelection()
  } catch {
    /* toast via store */
  }
}

async function confirmTransfer(payload: {
  from_warehouse_id: number
  to_warehouse_id: number
  reason: string
  items: Array<{ product_id: number; qty: number }>
}): Promise<void> {
  try {
    await store.transferStock(payload)
    transferOpen.value = false
    clearTableSelection()
  } catch {
    /* toast via store */
  }
}

onMounted(async () => {
  window.addEventListener('keydown', focusSearch, true)
  await store.fetchWarehouses()
  await load({ page: 1 })
  if (!bulkCategory.value && bulkCategoryOptions.value[0]) {
    bulkCategory.value = bulkCategoryOptions.value[0]
  }
})

onUnmounted(() => {
  window.removeEventListener('keydown', focusSearch, true)
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden p-3 sm:space-y-4 sm:p-4 lg:p-6"
    style="background: var(--brand-desk, var(--autometria-bg, #090d16)); min-height: 100%"
  >
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Warehouse // блок 2.2 · FIFO · перемещения
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Склад и остатки</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Инвентаризация · перемещения · партии FIFO · умные закупки
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2 font-mono text-[11px]">
        <div class="flex border" style="border-color: #1e293b; border-radius: 4px; overflow: hidden">
          <button
            type="button"
            class="px-3 py-1.5"
            :style="panel === 'stock'
              ? { background: '#1a3c8c', color: '#fff' }
              : { background: '#090d16', color: '#9ca3af' }"
            @click="panel = 'stock'"
          >
            Остатки
          </button>
          <button
            type="button"
            class="px-3 py-1.5"
            :style="panel === 'smart'
              ? { background: '#1a3c8c', color: '#fff' }
              : { background: '#090d16', color: '#9ca3af' }"
            @click="panel = 'smart'"
          >
            Умные закупки
          </button>
        </div>
        <DsLoadingBadge v-if="loading || bulkPending || opPending" label="Fetching" />
        <DsBadge v-if="degraded" status="warning" label="Degraded" variant="warning" dot />
        <span class="border px-2 py-1.5" style="border-color: #1e293b; border-radius: 4px; color: #a8b3c7">SKU {{ stats.skus }}</span>
        <span class="border px-2 py-1.5" style="border-color: #1e293b; border-radius: 4px; color: #f59e0b">Низкий {{ stats.low }}</span>
        <span class="border px-2 py-1.5" style="border-color: #1e293b; border-radius: 4px; color: #ef4444">Дефицит {{ stats.critical }}</span>
      </div>
    </div>

    <SmartPurchasesPanel v-if="panel === 'smart'" :warehouses="warehouses" />

    <template v-if="panel === 'stock'">
    <div
      class="sticky top-0 z-10 flex flex-col gap-2 border p-3 sm:flex-row sm:flex-wrap sm:items-center"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div class="relative w-full min-w-0 flex-1">
        <input
          ref="searchRef"
          v-model="query"
          class="ds-input h-12 w-full pr-20 font-mono text-base sm:h-10 sm:text-xs"
          style="border-radius: 4px; background: #090d16; border-color: #1e293b"
          type="search"
          inputmode="search"
          enterkeyhint="search"
          autocomplete="off"
          placeholder="Скан / артикул / OEM…"
        >
        <button
          type="button"
          class="absolute right-1.5 top-1/2 h-9 -translate-y-1/2 border px-2.5 font-mono text-[11px] sm:h-8"
          style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px; background: #0f172a"
          title="Фокус на ввод артикула"
          @click="focusScan"
        >
          ⌁ Scan
        </button>
      </div>

      <select
        v-model="category"
        class="ds-select h-11 w-full text-sm sm:h-9 sm:w-auto sm:text-xs"
        style="border-radius: 4px; background: #090d16; border-color: #1e293b"
      >
        <option v-for="c in categoryOptions" :key="c" :value="c">
          {{ c === 'all' ? 'Все категории' : c }}
        </option>
      </select>

      <select
        v-model="warehouse"
        class="ds-select h-11 w-full text-sm sm:h-9 sm:w-auto sm:text-xs"
        style="border-radius: 4px; background: #090d16; border-color: #1e293b"
      >
        <option v-for="w in warehouseOptions" :key="w" :value="w">
          {{ w === 'all' ? 'Все склады' : w }}
        </option>
      </select>

      <div class="flex w-full gap-2 sm:w-auto">
        <button
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] sm:h-9 sm:flex-none"
          style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px; background: #090d16"
          :disabled="!selectedKeys.length || opPending"
          @click="openInventoryFromSelection"
        >
          Инвентаризация
        </button>
        <button
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] sm:h-9 sm:flex-none"
          style="border-color: #10B981; color: #10B981; border-radius: 4px; background: #090d16"
          :disabled="!selectedKeys.length || opPending"
          @click="openTransfer"
        >
          Перемещение
        </button>
        <button
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] sm:h-9 sm:flex-none"
          style="border-color: #60A5FA; color: #60A5FA; border-radius: 4px; background: #090d16"
          @click="startWmsScan()"
        >
          📷 Сканировать
        </button>
      </div>
    </div>

    <!-- Mobile bulk -->
    <div
      v-if="selectedKeys.length"
      class="flex flex-col gap-2 border px-3 py-2 sm:hidden"
      style="background: color-mix(in srgb, #1a3c8c 28%, #0f172a); border-color: #1e293b; border-radius: 4px"
    >
      <span class="font-mono text-[12px]" style="color: #9ca3af">{{ selectedKeys.length }} выбрано</span>
      <div class="flex gap-2">
        <button type="button" class="ds-btn ds-btn-ghost h-11 flex-1" :disabled="opPending" @click="openInventoryFromSelection">Инвент.</button>
        <button type="button" class="ds-btn ds-btn-ghost h-11 flex-1" :disabled="opPending" @click="openTransfer">Перемещ.</button>
        <button type="button" class="ds-btn ds-btn-ghost h-11" @click="clearTableSelection">Снять</button>
      </div>
    </div>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div v-for="n in 6" :key="n" class="h-16 animate-pulse sm:h-8" style="background: #161b22; border-radius: 4px" />
    </div>

    <template v-else>
      <!-- Mobile cards + FIFO -->
      <div class="space-y-2 sm:hidden">
        <article
          v-for="row in rows"
          :key="row.id"
          class="border p-3"
          :class="isSelected(row.id) ? 'border-indigo-400' : 'border-[#1F2937]'"
          :style="{
            background: isSelected(row.id) ? '#1E1B4B' : '#11151A',
            borderRadius: '4px',
          }"
        >
          <div class="flex items-start gap-3" @click="toggleCard(row.id)">
            <input
              type="checkbox"
              class="mt-1 h-5 w-5 accent-[var(--color-primary)]"
              :checked="isSelected(row.id)"
              @click.stop
              @change="toggleCard(row.id)"
            >
            <div class="min-w-0 flex-1 space-y-1">
              <div class="flex items-center justify-between gap-2">
                <span class="truncate font-mono text-[13px] font-medium text-white">{{ row.sku }}</span>
                <span class="shrink-0 font-mono text-[13px] font-bold tabular-nums" style="color: #f59e0b">{{ money(row.price) }}</span>
              </div>
              <div class="truncate text-[13px] text-white">{{ row.name }}</div>
              <div class="font-mono text-[11px]" style="color: #6b7280">OEM {{ row.oem }} · {{ row.cell }} · {{ row.warehouse }}</div>
              <div class="flex flex-wrap items-center gap-2 pt-1">
                <span class="font-mono text-[12px] text-white">в наличии {{ row.available }}</span>
                <span class="font-mono text-[11px]" style="color: #6b7280">резерв {{ row.reserved }}</span>
                <DsBadge
                  :variant="(STATUS[row.status] || STATUS.ok).variant"
                  :label="(STATUS[row.status] || STATUS.ok).label"
                  :status="row.status"
                  dot
                />
              </div>
              <button
                type="button"
                class="mt-1 h-9 border px-2 font-mono text-[11px]"
                style="border-color: #1e293b; color: #f59e0b; border-radius: 4px"
                @click.stop="openInventory(row)"
              >
                Переучёт
              </button>
              <FifoLotsPanel :product-id="row.product_id" :warehouse-id="row.warehouse_id" />
            </div>
          </div>
        </article>
        <p v-if="!rows.length" class="py-8 text-center text-sm" style="color: #9ca3af">Номенклатура не найдена</p>
      </div>

      <!-- Desktop table -->
      <div class="hidden min-w-0 overflow-x-auto sm:block">
        <DsTable
          ref="tableRef"
          v-model:selected-keys="selectedKeys"
          :columns="columns"
          :rows="rows"
          density="compact"
          sticky-header
          selectable
          max-height="min(62vh, 560px)"
          empty-text="Номенклатура не найдена"
        >
          <template #bulk-actions>
            <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="opPending" @click="openInventoryFromSelection">
              Инвентаризация
            </button>
            <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="opPending" @click="openTransfer">
              Перемещение
            </button>
            <label class="flex flex-wrap items-center gap-1.5 font-mono text-[11px]" style="color: #9ca3af">
              Категория
              <select
                v-model="bulkCategory"
                class="ds-select py-1 text-xs"
                style="border-radius: 4px; background: #090d16; border-color: #1e293b; min-width: 110px"
                :disabled="bulkPending"
              >
                <option v-for="c in bulkCategoryOptions" :key="c" :value="c">{{ c }}</option>
              </select>
              <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="bulkPending" @click="applyCategory">
                Применить
              </button>
            </label>
            <label class="flex flex-wrap items-center gap-1.5 font-mono text-[11px]" style="color: #9ca3af">
              +/- остаток
              <input
                v-model.number="bulkAdjustment"
                type="number"
                step="1"
                class="ds-input w-16 py-1 text-center font-mono text-xs"
                style="border-radius: 4px; background: #090d16; border-color: #1e293b"
                :disabled="bulkPending"
              >
              <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="bulkPending" @click="applyAdjustment(Math.abs(Number(bulkAdjustment) || 1))">+</button>
              <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="bulkPending" @click="applyAdjustment(-Math.abs(Number(bulkAdjustment) || 1))">−</button>
            </label>
          </template>

          <template #sku="{ row }">
            <div class="leading-tight">
              <div class="font-mono text-[12px] font-medium tabular-nums text-white">{{ row.sku }}</div>
              <div class="font-mono text-[10px] tabular-nums" style="color: #6b7280">OEM {{ row.oem }}</div>
              <FifoLotsPanel :product-id="row.product_id" :warehouse-id="row.warehouse_id" />
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
            <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #f59e0b">{{ money(value) }}</span>
          </template>
          <template #status="{ row }">
            <DsBadge
              :variant="(STATUS[row.status] || STATUS.ok).variant"
              :label="(STATUS[row.status] || STATUS.ok).label"
              :status="row.status"
              dot
            />
          </template>
          <template #actions="{ row }">
            <button
              type="button"
              class="ds-btn ds-btn-ghost ds-btn-sm"
              @click="openInventory(row)"
            >
              Переучёт
            </button>
          </template>
        </DsTable>
      </div>
    </template>
    </template>

    <InventoryAdjustmentModal
      v-model:open="inventoryOpen"
      :row="inventoryRow"
      :pending="opPending"
      @confirm="confirmInventory"
    />
    <StockTransferModal
      v-model:open="transferOpen"
      :warehouses="warehouses"
      :seed-lines="transferSeed"
      :default-from-id="defaultFromWarehouseId"
      :pending="opPending"
      @confirm="confirmTransfer"
    />

    <!-- WMS Barcode / DataMatrix scanner overlay (Sprint 2) -->
    <div
      v-if="wmsScanOpen"
      class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-black/85 p-4"
    >
      <div class="flex w-full max-w-md items-center justify-between">
        <span class="font-mono text-[11px] uppercase tracking-wide" style="color: #60A5FA">
          WMS · ячейка или DataMatrix партии
        </span>
        <button
          type="button"
          class="border px-3 py-1.5 font-mono text-[11px]"
          style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
          @click="stopWmsScan()"
        >
          Закрыть
        </button>
      </div>
      <video
        ref="wmsVideoRef"
        playsinline
        muted
        class="aspect-square w-full max-w-md rounded border"
        style="border-color: #f59e0b; background: #0b0d10"
      />
      <div v-if="scannedCell" class="font-mono text-[12px]" style="color: #10B981">
        Ячейка: {{ scannedCell }}
      </div>
      <div v-if="scannedLot" class="font-mono text-[12px]" style="color: #F59E0B">
        Партия: GTIN {{ scannedLot.gtin || '—' }} · {{ scannedLot.batch || '—' }} · {{ scannedLot.serial || '—' }}
      </div>
      <p v-if="scanner.error.value" class="font-mono text-[11px]" style="color: #F87171">
        {{ scanner.error.value }}
      </p>
    </div>
  </div>
</template>
