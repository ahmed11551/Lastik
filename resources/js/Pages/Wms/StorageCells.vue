<script setup lang="ts">
/**
 * AUTOMETRIA ERP — WMS Light storage cells
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { onMounted, reactive, ref } from 'vue'
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
  { key: 'id', label: 'ID' },
  { key: 'code', label: 'Код' },
  { key: 'warehouse_name', label: 'Склад' },
  { key: 'zone', label: 'Зона' },
  { key: 'rack', label: 'Стеллаж' },
  { key: 'shelf', label: 'Полка' },
  { key: 'bin', label: 'Бин' },
  { key: 'is_active', label: 'Активна' },
]

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
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось создать ячейку'
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') document.title = 'Ячейки WMS · AUTOMETRIA'
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
            title: 'WMS · Ячейки',
            'active-nav': 'wms_cells',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Склад' }, { label: 'Ячейки' }],
          }
    "
  >
    <div class="ds-toolbar mb-4">
      <div class="ds-toolbar__filters flex flex-wrap items-center gap-2">
        <select v-model="warehouseId" class="ds-input h-9" data-testid="wms-warehouse" @change="load">
          <option :value="null">Склад</option>
          <option v-for="w in whStore.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
        <input v-model="form.code" class="ds-input h-9 w-28" placeholder="Код" data-testid="wms-cell-code">
        <input v-model="form.zone" class="ds-input h-9 w-20" placeholder="Зона">
        <input v-model="form.rack" class="ds-input h-9 w-20" placeholder="Стеллаж">
        <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="wms-cell-create" @click="createCell">
          Создать
        </button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="load">Обновить</button>
      </div>
    </div>

    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>
    <div v-if="success" class="ds-surface mb-4 p-3" style="color: var(--color-success, #22c55e)">{{ success }}</div>

    <DsTable :columns="columns" :rows="rows" density="compact" sticky-header />
  </component>
</template>
