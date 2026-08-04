<script setup lang="ts">
/**
 * AUTOMETRIA ERP — WMS Light storage cells (демо: Склад хранения)
 * Работаешь ТОЛЬКО в resources/js/. Бэкенд не трогать!
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsBadge, DsTable } from '@/design-system'
import { computed, onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '@/autometria/api/client'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const whStore = useWarehouseStore()
const rows = ref<any[]>([])
const loading = ref(false)
const error = ref('')
const success = ref('')
const warehouseId = ref<number | null>(null)

const form = reactive({
  code: '',
  zone: '',
  rack: '',
  shelf: '',
  bin: '',
  description: '',
})

const columns = [
  { key: 'code', label: 'Код ячейки' },
  { key: 'warehouse_name', label: 'Склад', mono: false },
  { key: 'zone', label: 'Зона' },
  { key: 'rack', label: 'Стеллаж' },
  { key: 'shelf', label: 'Полка' },
  { key: 'bin', label: 'Бин' },
  { key: 'is_active', label: 'Статус', mono: false },
]

const stats = computed(() => {
  const list = rows.value
  const active = list.filter((r) => r.is_active === 'да' || r.is_active === true).length
  const zones = new Set(list.map((r) => r.zone).filter(Boolean)).size
  return { total: list.length, active, zones }
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/wms/storage-cells', {
      params: warehouseId.value ? { warehouse_id: warehouseId.value } : undefined,
      silent: true,
    })
    rows.value = (Array.isArray(payload?.data) ? payload.data : []).map((r: any) => ({
      ...r,
      is_active: r.is_active ? 'да' : 'нет',
    }))
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки ячеек'
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function createCell() {
  error.value = ''
  success.value = ''
  if (!warehouseId.value || !form.code.trim()) {
    error.value = 'Укажите склад и код ячейки'
    return
  }
  try {
    await apiPost('/wms/storage-cells', {
      warehouse_id: warehouseId.value,
      code: form.code.trim(),
      zone: form.zone || null,
      rack: form.rack || null,
      shelf: form.shelf || null,
      bin: form.bin || null,
      description: form.description || null,
    })
    success.value = 'Ячейка создана'
    form.code = ''
    form.zone = ''
    form.rack = ''
    form.shelf = ''
    form.bin = ''
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось создать ячейку'
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') document.title = 'Склад хранения · AUTOMETRIA'
  if (!whStore.warehouses.length) {
    void whStore.fetchWarehouses().then(() => {
      warehouseId.value = whStore.warehouses[0]?.id ?? null
      void load()
    })
  } else {
    warehouseId.value = whStore.warehouses[0]?.id ?? null
    void load()
  }
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'Склад хранения',
            'active-nav': 'wms_cells',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Склад' }, { label: 'Хранение · ячейки' }],
          }
    "
  >
    <div
      class="mb-4 flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
      data-testid="wms-storage-header"
    >
      <div class="min-w-0">
        <div
          class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
          style="color: #f59e0b"
        >
          WMS Light // ячейки хранения
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Склад хранения</h2>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          Зона · стеллаж · полка · бин — DemoSeeder: 4 ячейки
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2 font-mono text-[11px]">
        <span class="border px-2 py-1.5" style="border-color: #1f2937; border-radius: 4px; color: #9ca3af">
          Ячеек {{ stats.total }}
        </span>
        <span class="border px-2 py-1.5" style="border-color: #1f2937; border-radius: 4px; color: #10b981">
          Активных {{ stats.active }}
        </span>
        <span class="border px-2 py-1.5" style="border-color: #1f2937; border-radius: 4px; color: #f59e0b">
          Зон {{ stats.zones }}
        </span>
      </div>
    </div>

    <div class="ds-toolbar mb-4">
      <div class="ds-toolbar__filters flex flex-wrap items-center gap-2">
        <select
          v-model="warehouseId"
          class="ds-input h-9 min-w-[140px]"
          data-testid="wms-warehouse"
          @change="load"
        >
          <option :value="null">Выберите склад</option>
          <option
            v-for="w in whStore.warehouses"
            :key="w.id"
            :value="w.id"
          >
            {{ w.name }}
          </option>
        </select>
        <input
          v-model="form.code"
          class="ds-input h-9 w-28"
          placeholder="Код"
          data-testid="wms-cell-code"
        >
        <input
          v-model="form.zone"
          class="ds-input h-9 w-20"
          placeholder="Зона"
        >
        <input
          v-model="form.rack"
          class="ds-input h-9 w-20"
          placeholder="Стеллаж"
        >
        <input
          v-model="form.shelf"
          class="ds-input h-9 w-20"
          placeholder="Полка"
        >
        <input
          v-model="form.bin"
          class="ds-input h-9 w-16"
          placeholder="Бин"
        >
        <button
          type="button"
          class="ds-btn ds-btn-primary ds-btn-sm"
          data-testid="wms-cell-create"
          @click="createCell"
        >
          Создать ячейку
        </button>
        <button
          type="button"
          class="ds-btn ds-btn-ghost ds-btn-sm"
          :disabled="loading"
          data-testid="wms-cell-refresh"
          @click="load"
        >
          Обновить
        </button>
      </div>
    </div>

    <div
      v-if="error"
      class="ds-surface mb-4 p-3"
      style="color: var(--color-danger)"
      data-testid="wms-error"
    >
      {{ error }}
    </div>
    <div
      v-if="success"
      class="ds-surface mb-4 p-3"
      style="color: var(--color-success, #22c55e)"
    >
      {{ success }}
    </div>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div
        v-for="n in 5"
        :key="n"
        class="h-8 animate-pulse"
        style="background: #161b22; border-radius: 4px"
      />
    </div>

    <template v-else>
      <DsTable
        :columns="columns"
        :rows="rows"
        density="compact"
        sticky-header
        empty-text="Нет ячеек — запустите DemoSeeder или создайте первую"
      >
        <template #code="{ value }">
          <span class="font-mono text-[12px] font-semibold text-white">{{ value }}</span>
        </template>
        <template #is_active="{ value }">
          <DsBadge
            :variant="value === 'да' ? 'success' : 'neutral'"
            :label="value === 'да' ? 'Активна' : 'Выкл'"
          />
        </template>
      </DsTable>
    </template>
  </component>
</template>
