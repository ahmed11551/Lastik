<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Replenishment plan dashboard
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { usePurchasingStore } from '@/autometria/stores/usePurchasingStore'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const emit = defineEmits<{ navigate: [id: string] }>()

const store = usePurchasingStore()
const whStore = useWarehouseStore()
const { replenishment, loading, error } = storeToRefs(store)
const warehouseId = ref<number | null>(null)

const columns = [
  { key: 'article', label: 'Артикул' },
  { key: 'name', label: 'Товар' },
  { key: 'available', label: 'Доступно' },
  { key: 'min_stock', label: 'Min' },
  { key: 'max_stock', label: 'Max' },
  { key: 'suggested_qty', label: 'К заказу' },
  { key: 'unit_price', label: 'Цена' },
]

async function reload() {
  if (warehouseId.value == null) return
  await store.fetchReplenishment(warehouseId.value)
}

onMounted(async () => {
  if (typeof document !== 'undefined') document.title = 'План пополнения · AUTOMETRIA'
  if (!whStore.warehouses.length) await whStore.fetchWarehouses()
  warehouseId.value = whStore.warehouses[0]?.id ?? null
  await reload()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'План пополнения',
            'active-nav': 'replenishment',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Закупки' }, { label: 'Пополнение' }],
          }
    "
  >
    <div class="ds-toolbar">
      <div class="ds-toolbar__filters">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="emit('navigate', 'purchases')">← Заказы</button>
        <select v-model="warehouseId" class="ds-input h-9" data-testid="replenish-warehouse" @change="reload">
          <option :value="null">Склад</option>
          <option v-for="w in whStore.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
      </div>
      <div class="ds-toolbar__actions">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" data-testid="replenish-reload" @click="reload">
          Обновить
        </button>
        <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="replenish-to-order" @click="emit('navigate', 'purchase_form')">
          Создать заказ
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
      :rows="replenishment"
      density="compact"
      sticky-header
      empty-text="Всё в норме"
      empty-hint="Нет товаров ниже min_stock на выбранном складе"
    />
  </component>
</template>
