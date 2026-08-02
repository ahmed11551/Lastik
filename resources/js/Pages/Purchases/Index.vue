<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Supplier orders journal
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable, DsBadge } from '@/design-system'
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { usePurchasingStore } from '@/autometria/stores/usePurchasingStore'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const emit = defineEmits<{ navigate: [id: string] }>()

const store = usePurchasingStore()
const { orders, loading, error, statusFilter } = storeToRefs(store)

const statuses = [
  { value: '', label: 'Все' },
  { value: 'DRAFT', label: 'Черновик' },
  { value: 'CONFIRMED', label: 'Подтверждён' },
  { value: 'PARTIALLY_RECEIVED', label: 'Частично' },
  { value: 'RECEIVED', label: 'Принят' },
  { value: 'CANCELLED', label: 'Отменён' },
]

const columns = [
  { key: 'id', label: '№' },
  { key: 'supplier_name', label: 'Поставщик' },
  { key: 'warehouse_name', label: 'Склад' },
  { key: 'status', label: 'Статус' },
  { key: 'total_amount', label: 'Сумма' },
  { key: 'expected_delivery', label: 'Поставка' },
  { key: 'items_count', label: 'Позиций' },
]

const rows = computed(() =>
  orders.value.map((o: any) => ({
    ...o,
    items_count: Array.isArray(o.items) ? o.items.length : 0,
    total_amount: Number(o.total_amount || 0).toLocaleString('ru-RU'),
  })),
)

async function reload() {
  await store.fetchOrders(statusFilter.value || undefined)
}

onMounted(() => {
  if (typeof document !== 'undefined') document.title = 'Закупки · AUTOMETRIA'
  void reload()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'Заказы поставщикам',
            'active-nav': 'purchases',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Закупки' }, { label: 'Заказы' }],
          }
    "
  >
    <div class="ds-toolbar">
      <div class="ds-toolbar__filters">
        <select
          v-model="statusFilter"
          class="ds-input h-9"
          data-testid="purchase-status-filter"
          @change="reload"
        >
          <option v-for="s in statuses" :key="s.value || 'all'" :value="s.value">{{ s.label }}</option>
        </select>
      </div>
      <div class="ds-toolbar__actions">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="reload">
          Обновить
        </button>
        <button
          type="button"
          class="ds-btn ds-btn-sm"
          data-testid="purchase-replenishment"
          @click="emit('navigate', 'replenishment')"
        >
          План пополнения
        </button>
        <button
          type="button"
          class="ds-btn ds-btn-primary ds-btn-sm"
          data-testid="purchase-create"
          @click="emit('navigate', 'purchase_form')"
        >
          + Заказ
        </button>
      </div>
    </div>

    <div v-if="loading" class="ds-skeleton" aria-busy="true" aria-label="Загрузка">
      <div class="ds-skeleton__row" />
      <div class="ds-skeleton__row" />
      <div class="ds-skeleton__row" />
    </div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <DsTable
      v-if="!loading"
      :columns="columns"
      :rows="rows"
      density="compact"
      sticky-header
      empty-text="Заказов пока нет"
      empty-hint="Создайте первый заказ поставщику"
    >
      <template #status="{ value }">
        <DsBadge :status="String(value || '')" dot />
      </template>
      <template #id="{ value }">
        <button
          type="button"
          class="ds-link"
          data-testid="purchase-open"
          @click="emit('navigate', 'purchase_form:' + value)"
        >
          #{{ value }}
        </button>
      </template>
    </DsTable>
  </component>
</template>
