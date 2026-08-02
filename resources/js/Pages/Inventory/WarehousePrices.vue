<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Warehouse price matrix (Sprint F)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { onMounted, ref, computed } from 'vue'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'
import { apiGet, apiPost } from '@/autometria/api/client'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const whStore = useWarehouseStore()
const warehouses = computed(() => whStore.warehouses)
const selectedWarehouse = ref<number | null>(null)

const rows = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const markupPct = ref<number>(0)

const columns = [
  { key: 'sku', label: 'Артикул' },
  { key: 'name', label: 'Наименование' },
  { key: 'base_price', label: 'Базовая цена' },
  { key: 'price', label: 'Цена на складе' },
]

const editableRows = computed(() =>
  rows.value.map((r) => ({
    ...r,
    base_price: Number(r.base_price || 0).toLocaleString('ru-RU'),
    price: Number(r.price || 0),
  })),
)

async function loadPrices() {
  if (selectedWarehouse.value == null) return
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const [stockPayload, overridePayload] = await Promise.all([
      apiGet('/stock', {
        params: { warehouse_id: selectedWarehouse.value, per_page: 100 },
        silent: true,
      }),
      apiGet('/inventory/warehouse-prices', {
        params: { warehouse_id: selectedWarehouse.value },
        silent: true,
      }),
    ])
    const list = Array.isArray(stockPayload?.data) ? stockPayload.data : []
    const overrides = new Map(
      (Array.isArray(overridePayload?.data) ? overridePayload.data : []).map((p: any) => [
        Number(p.product_id),
        Number(p.price),
      ]),
    )
    rows.value = list.map((s: any) => {
      const productId = Number(s.product_id)
      const catalogPrice = Number(s.price || 0)
      return {
        product_id: productId,
        sku: s.sku,
        name: s.name,
        base_price: catalogPrice,
        price: overrides.has(productId) ? overrides.get(productId)! : catalogPrice,
      }
    })
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки цен'
    rows.value = []
  } finally {
    loading.value = false
  }
}

function applyMarkup() {
  const pct = Number(markupPct.value || 0)
  rows.value = rows.value.map((r) => ({
    ...r,
    price: Math.round(Number(r.base_price) * (1 + pct / 100) * 100) / 100,
  }))
}

function setPrice(idx: number, value: number) {
  if (rows.value[idx]) rows.value[idx].price = Math.max(0, Number(value) || 0)
}

async function savePrices() {
  if (selectedWarehouse.value == null || !rows.value.length) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await apiPost('/inventory/warehouse-prices', {
      warehouse_id: selectedWarehouse.value,
      prices: rows.value.map((r) => ({
        product_id: r.product_id,
        price: Number(r.price),
      })),
    })
    success.value = `Сохранено ${rows.value.length} цен`
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось сохранить цены'
  } finally {
    saving.value = false
  }
}

function exportCsv() {
  const header = ['sku', 'name', 'base_price', 'price']
  const lines = rows.value.map((r) => [r.sku, r.name, r.base_price, r.price].join(','))
  const csv = [header.join(','), ...lines].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `warehouse-prices-${selectedWarehouse.value}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'Цены по складам · AUTOMETRIA'
  }
  if (!warehouses.value.length) whStore.fetchWarehouses()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'Сетка цен по складам',
            'active-nav': 'warehouse_prices',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Склад' }, { label: 'Цены' }],
          }
    "
  >
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <select v-model="selectedWarehouse" class="ds-input h-9" data-testid="price-warehouse" @change="loadPrices">
        <option :value="null">Выберите склад</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <input v-model.number="markupPct" type="number" class="ds-input h-9 w-24" placeholder="Наценка %" data-testid="markup-pct" />
      <button type="button" class="ds-btn ds-btn-sm" data-testid="apply-markup" @click="applyMarkup">Применить наценку</button>
      <button
        type="button"
        class="ds-btn ds-btn-primary ds-btn-sm"
        data-testid="save-prices"
        :disabled="saving || !rows.length"
        @click="savePrices"
      >
        Сохранить
      </button>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-testid="export-prices" :disabled="!rows.length" @click="exportCsv">
        Экспорт CSV
      </button>
    </div>

    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>
    <div v-if="success" class="ds-surface mb-4 p-3" style="color: var(--color-success, #22c55e)">{{ success }}</div>

    <DsTable :columns="columns" :rows="editableRows" density="compact" sticky-header>
      <template #price="{ index }">
        <input
          :value="rows[index]?.price"
          type="number"
          min="0"
          step="0.01"
          class="ds-input h-8 w-28"
          data-testid="price-input"
          @input="setPrice(index, Number(($event.target as HTMLInputElement).value))"
        />
      </template>
    </DsTable>
  </component>
</template>
