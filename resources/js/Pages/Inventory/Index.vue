<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Inventory documents list (Sprint D)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useInventoryDocumentsStore, DOC_TYPES, DOC_STATUSES } from '@/autometria/stores/useInventoryDocumentsStore'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const emit = defineEmits<{ navigate: [id: string] }>()

const store = useInventoryDocumentsStore()
const { documents, loading, error, filters } = storeToRefs(store)

const typeLabel: Record<string, string> = {
  RECEIPT: 'Приход',
  WRITE_OFF: 'Списание',
  TRANSFER: 'Перемещение',
  INVENTORY: 'Инвентаризация',
}

const statusClass: Record<string, string> = {
  DRAFT: 'ds-badge--warning',
  POSTED: 'ds-badge--success',
}

const columns = [
  { key: 'number', label: '№ документа' },
  { key: 'type', label: 'Тип' },
  { key: 'status', label: 'Статус' },
  { key: 'warehouse_id', label: 'Склад' },
  { key: 'items_count', label: 'Позиций' },
  { key: 'total', label: 'Сумма' },
  { key: 'created_at', label: 'Дата' },
]

const rows = computed(() =>
  documents.value.map((d: any) => ({
    ...d,
    type: typeLabel[d.type] ?? d.type,
    status: d.status,
    total: '₽' + Number(d.total || 0).toLocaleString('ru-RU'),
    created_at: d.created_at ? String(d.created_at).slice(0, 10) : '—',
  })),
)

function applyFilters() {
  void store.fetchAll()
}

function goCreate() {
  if (props.embedded) {
    emit('navigate', 'inventory_create')
    return
  }
  location.hash = '#/inventory_create'
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'Складские документы · AUTOMETRIA'
  }
  void store.fetchAll()
})
</script>

<template>
  <component
    :is="props.embedded ? 'div' : AutometriaLayout"
    v-bind="props.embedded ? { 'data-testid': 'inventory-embedded' } : {
      title: 'Складские документы',
      activeNav: 'inventory',
      currentShiftOpen: props.currentShiftOpen,
      shiftStartedAt: props.shiftStartedAt,
      shiftRevenue: props.shiftRevenue,
      breadcrumbs: [{ label: 'Склад' }, { label: 'Документы' }],
    }"
  >
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <select v-model="filters.type" class="ds-input h-9" data-testid="filter-type" @change="applyFilters">
        <option value="">Все типы</option>
        <option v-for="t in DOC_TYPES" :key="t" :value="t">{{ typeLabel[t] }}</option>
      </select>
      <select v-model="filters.status" class="ds-input h-9" data-testid="filter-status" @change="applyFilters">
        <option value="">Все статусы</option>
        <option v-for="s in DOC_STATUSES" :key="s" :value="s">{{ s }}</option>
      </select>
      <input v-model="filters.date_from" type="date" class="ds-input h-9" data-testid="filter-date-from" @change="applyFilters" />
      <input v-model="filters.date_to" type="date" class="ds-input h-9" data-testid="filter-date-to" @change="applyFilters" />
      <button type="button" class="ds-btn ds-btn-primary ds-btn-sm ml-auto" data-testid="create-doc" @click="goCreate">
        + Создать документ
      </button>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="store.fetchAll()">
        Обновить
      </button>
    </div>

    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <DsTable :columns="columns" :rows="rows" density="compact" sticky-header>
      <template #status="{ value }">
        <span class="ds-badge" :class="statusClass[value] || 'ds-badge--neutral'">{{ value }}</span>
      </template>
    </DsTable>
  </component>
</template>
