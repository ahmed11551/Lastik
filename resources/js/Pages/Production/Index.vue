<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Production journal & BOM (Sprint G)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { onMounted, ref } from 'vue'
import { apiGet, apiPost } from '@/autometria/api/client'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'
import ProduceModal from './ProduceModal.vue'
import RecipeForm from './Recipes/Form.vue'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const whStore = useWarehouseStore()
const tab = ref<'orders' | 'recipes'>('orders')
const orders = ref<any[]>([])
const recipes = ref<any[]>([])
const loading = ref(false)
const error = ref('')
const success = ref('')
const produceOpen = ref(false)
const showRecipeForm = ref(false)
const warehouseId = ref<number | null>(null)

const orderColumns = [
  { key: 'id', label: '№' },
  { key: 'product_name', label: 'Готовая продукция' },
  { key: 'qty', label: 'Кол-во' },
  { key: 'unit_cost', label: 'Себест./ед.' },
  { key: 'total_cost', label: 'Себестоимость' },
  { key: 'warehouse_name', label: 'Склад' },
  { key: 'status', label: 'Статус' },
  { key: 'created_at', label: 'Дата' },
]

const recipeColumns = [
  { key: 'id', label: 'ID' },
  { key: 'product_name', label: 'Продукт' },
  { key: 'yield_quantity', label: 'Выход' },
  { key: 'unit_cost', label: 'Себест.' },
  { key: 'items_count', label: 'Компонентов' },
]

async function loadOrders() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/production/orders', { silent: true })
    orders.value = Array.isArray(payload?.data) ? payload.data : []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки журнала'
    orders.value = []
  } finally {
    loading.value = false
  }
}

async function loadRecipes() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/recipes', {
      params: warehouseId.value ? { warehouse_id: warehouseId.value } : undefined,
      silent: true,
    })
    const list = Array.isArray(payload?.data) ? payload.data : []
    recipes.value = list.map((r: any) => ({
      ...r,
      items_count: Array.isArray(r.items) ? r.items.length : 0,
    }))
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки рецептур'
    recipes.value = []
  } finally {
    loading.value = false
  }
}

async function refresh() {
  if (tab.value === 'orders') await loadOrders()
  else await loadRecipes()
}

async function onProduce(payload: { recipe_id: number; qty: number; warehouse_id: number | null }) {
  error.value = ''
  success.value = ''
  try {
    await apiPost('/production/produce', {
      recipe_id: payload.recipe_id,
      qty: payload.qty,
      warehouse_id: payload.warehouse_id ?? warehouseId.value,
    })
    success.value = 'Производство проведено'
    tab.value = 'orders'
    await loadOrders()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка производства'
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'Производство · AUTOMETRIA'
  }
  if (!whStore.warehouses.length) {
    void whStore.fetchWarehouses().then(() => {
      warehouseId.value = whStore.warehouses[0]?.id ?? null
    })
  } else {
    warehouseId.value = whStore.warehouses[0]?.id ?? null
  }
  void loadOrders()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'Производство и BOM',
            'active-nav': 'production',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Склад' }, { label: 'Производство' }],
          }
    "
  >
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="ds-btn ds-btn-sm"
        :class="tab === 'orders' ? 'ds-btn-primary' : 'ds-btn-ghost'"
        data-testid="tab-orders"
        @click="tab = 'orders'; refresh()"
      >
        Журнал выпуска
      </button>
      <button
        type="button"
        class="ds-btn ds-btn-sm"
        :class="tab === 'recipes' ? 'ds-btn-primary' : 'ds-btn-ghost'"
        data-testid="tab-recipes"
        @click="tab = 'recipes'; refresh()"
      >
        Рецептуры (BOM)
      </button>
      <select v-model="warehouseId" class="ds-input h-9" data-testid="production-warehouse">
        <option :value="null">Склад</option>
        <option v-for="w in whStore.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="open-produce" @click="produceOpen = true">
        Выпуск
      </button>
      <button type="button" class="ds-btn ds-btn-sm" data-testid="open-recipe-form" @click="showRecipeForm = !showRecipeForm">
        {{ showRecipeForm ? 'Скрыть конструктор' : 'Конструктор BOM' }}
      </button>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="refresh">Обновить</button>
    </div>

    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>
    <div v-if="success" class="ds-surface mb-4 p-3" style="color: var(--color-success, #22c55e)">{{ success }}</div>

    <RecipeForm v-if="showRecipeForm" embedded class="mb-4" @saved="tab = 'recipes'; loadRecipes(); showRecipeForm = false" />

    <DsTable
      v-if="tab === 'orders'"
      :columns="orderColumns"
      :rows="orders"
      density="compact"
      sticky-header
    />
    <DsTable
      v-else
      :columns="recipeColumns"
      :rows="recipes"
      density="compact"
      sticky-header
    />

    <ProduceModal v-model:open="produceOpen" :warehouse-id="warehouseId" @confirm="onProduce" />
  </component>
</template>
