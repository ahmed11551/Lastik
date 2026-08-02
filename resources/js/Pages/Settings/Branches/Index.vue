<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Branches admin (Sprint F)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable, DsBadge } from '@/design-system'
import { onMounted, ref } from 'vue'
import { apiGet, apiPost } from '@/autometria/api/client'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const whStore = useWarehouseStore()
const branches = ref<any[]>([])
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const showForm = ref(false)
const form = ref({
  id: null as number | null,
  name: '',
  code: '',
  address: '',
  default_warehouse_id: null as number | null,
  is_active: true,
})

const columns = [
  { key: 'code', label: 'Код' },
  { key: 'name', label: 'Название' },
  { key: 'address', label: 'Адрес' },
  { key: 'default_warehouse_id', label: 'Склад по умолч.' },
  { key: 'is_active', label: 'Статус' },
]

async function loadBranches() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/branches', { silent: true })
    branches.value = Array.isArray(payload?.data) ? payload.data : []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки филиалов'
    branches.value = []
  } finally {
    loading.value = false
  }
}

function openCreate() {
  form.value = {
    id: null,
    name: '',
    code: '',
    address: '',
    default_warehouse_id: null,
    is_active: true,
  }
  showForm.value = true
}

async function save() {
  if (!form.value.name.trim() || !form.value.code.trim()) {
    error.value = 'Укажите название и код филиала'
    return
  }
  saving.value = true
  error.value = ''
  try {
    await apiPost('/branches', {
      id: form.value.id,
      name: form.value.name,
      code: form.value.code,
      address: form.value.address || null,
      default_warehouse_id: form.value.default_warehouse_id,
      is_active: form.value.is_active,
    })
    showForm.value = false
    await loadBranches()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось сохранить филиал'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'Филиалы · AUTOMETRIA'
  }
  if (!whStore.warehouses.length) whStore.fetchWarehouses()
  loadBranches()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'Управление филиалами',
            'active-nav': 'branches',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Настройки' }, { label: 'Филиалы' }],
          }
    "
  >
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="create-branch" @click="openCreate">
        + Филиал
      </button>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="loadBranches">Обновить</button>
    </div>

    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <DsTable :columns="columns" :rows="branches" density="compact" sticky-header>
      <template #is_active="{ value }">
        <DsBadge :class="value ? 'ds-badge--success' : 'ds-badge--neutral'">{{ value ? 'Активен' : 'Выключен' }}</DsBadge>
      </template>
      <template #name="{ value, row }">
        <button
          type="button"
          class="ds-link"
          data-testid="edit-branch"
          @click="form = { id: row.id, name: row.name, code: row.code, address: row.address || '', default_warehouse_id: row.default_warehouse_id, is_active: !!row.is_active }; showForm = true"
        >
          {{ value }}
        </button>
      </template>
    </DsTable>

    <div v-if="showForm" class="ds-surface mt-4 space-y-3 p-3">
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Название</label>
          <input v-model="form.name" class="ds-input mt-1 w-full" data-testid="branch-name" />
        </div>
        <div>
          <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Код</label>
          <input v-model="form.code" class="ds-input mt-1 w-full" data-testid="branch-code" placeholder="CTR" />
        </div>
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Адрес</label>
        <input v-model="form.address" class="ds-input mt-1 w-full" data-testid="branch-address" />
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Склад по умолчанию</label>
        <select v-model="form.default_warehouse_id" class="ds-input mt-1 w-full" data-testid="branch-warehouse">
          <option :value="null">—</option>
          <option v-for="w in whStore.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
      </div>
      <label class="flex items-center gap-2 text-[12px]">
        <input v-model="form.is_active" type="checkbox" data-testid="branch-active" /> Активен
      </label>
      <div class="flex gap-2">
        <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="branch-save" :disabled="saving" @click="save">
          Сохранить
        </button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="showForm = false">Отмена</button>
      </div>
    </div>
  </component>
</template>
