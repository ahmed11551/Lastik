<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Supplier order form (create / confirm / receive)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable, DsBadge } from '@/design-system'
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { apiGet } from '@/autometria/api/client'
import { usePurchasingStore } from '@/autometria/stores/usePurchasingStore'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
  orderId: { type: [Number, String], default: null },
})

const emit = defineEmits<{ navigate: [id: string]; saved: [] }>()

const store = usePurchasingStore()
const whStore = useWarehouseStore()
const { suppliers, currentOrder, saving, error } = storeToRefs(store)

const form = ref({
  supplier_id: null as number | null,
  warehouse_id: null as number | null,
  expected_delivery: '',
  note: '',
})
const items = ref<any[]>([
  { product_id: null, qty: 1, unit_price: 0, received_qty: 0, receive_now: 0 },
])
const products = ref<any[]>([])
const success = ref('')
const localError = ref('')

const columns = [
  { key: 'product_id', label: 'Товар' },
  { key: 'qty', label: 'Кол-во' },
  { key: 'unit_price', label: 'Цена' },
  { key: 'received_qty', label: 'Принято' },
  { key: 'receive_now', label: 'Принять сейчас' },
]

const isDraft = computed(() => !currentOrder.value || currentOrder.value.status === 'DRAFT')
const canConfirm = computed(() => currentOrder.value?.status === 'DRAFT')
const canReceive = computed(() =>
  ['CONFIRMED', 'PARTIALLY_RECEIVED'].includes(String(currentOrder.value?.status || '')),
)

function addLine() {
  items.value.push({
    product_id: products.value[0]?.id || null,
    qty: 1,
    unit_price: Number(products.value[0]?.base_price || 0),
    received_qty: 0,
    receive_now: 0,
  })
}

async function loadProducts() {
  try {
    const payload = await apiGet('/products', { silent: true })
    products.value = Array.isArray(payload?.data) ? payload.data : []
  } catch {
    products.value = []
  }
}

async function saveDraft() {
  localError.value = ''
  success.value = ''
  if (!form.value.supplier_id || !form.value.warehouse_id || !items.value.length) {
    localError.value = 'Укажите поставщика, склад и позиции'
    return
  }
  try {
    await store.createOrder({
      supplier_id: form.value.supplier_id,
      warehouse_id: form.value.warehouse_id,
      expected_delivery: form.value.expected_delivery || null,
      note: form.value.note || null,
      items: items.value.map((i) => ({
        product_id: Number(i.product_id),
        qty: Number(i.qty),
        unit_price: Number(i.unit_price),
      })),
    })
    hydrateFromOrder(currentOrder.value)
    success.value = 'Заказ сохранён (DRAFT)'
    emit('saved')
  } catch {
    localError.value = store.error || 'Ошибка сохранения'
  }
}

async function confirm() {
  if (!currentOrder.value?.id) return
  localError.value = ''
  try {
    await store.confirmOrder(currentOrder.value.id)
    success.value = 'Заказ подтверждён, график поставок создан'
    hydrateFromOrder(currentOrder.value)
  } catch {
    localError.value = store.error || 'Ошибка подтверждения'
  }
}

async function receive() {
  if (!currentOrder.value?.id) return
  const lines = items.value
    .filter((i) => Number(i.receive_now) > 0)
    .map((i) => ({
      product_id: Number(i.product_id),
      qty: Number(i.receive_now),
      cost_price: Number(i.unit_price),
    }))
  if (!lines.length) {
    localError.value = 'Укажите количество к приёмке'
    return
  }
  localError.value = ''
  try {
    await store.receiveOrder(currentOrder.value.id, lines)
    success.value = 'Товар принят на склад'
    hydrateFromOrder(currentOrder.value)
  } catch {
    localError.value = store.error || 'Ошибка приёмки'
  }
}

function hydrateFromOrder(order: any) {
  if (!order) return
  form.value.supplier_id = order.supplier_id
  form.value.warehouse_id = order.warehouse_id
  form.value.expected_delivery = order.expected_delivery || ''
  form.value.note = order.note || ''
  items.value = (order.items || []).map((i: any) => ({
    id: i.id,
    product_id: i.product_id,
    qty: Number(i.qty),
    unit_price: Number(i.unit_price),
    received_qty: Number(i.received_qty || 0),
    receive_now: Math.max(0, Number(i.qty) - Number(i.received_qty || 0)),
  }))
}

onMounted(async () => {
  if (typeof document !== 'undefined') document.title = 'Заказ поставщику · AUTOMETRIA'
  if (!whStore.warehouses.length) await whStore.fetchWarehouses()
  await store.fetchSuppliers()
  await loadProducts()
  form.value.warehouse_id = whStore.warehouses[0]?.id ?? null

  if (props.orderId) {
    await store.fetchOrders()
    const found = store.orders.find((o: any) => String(o.id) === String(props.orderId))
    if (found) {
      store.currentOrder = found
      hydrateFromOrder(found)
    }
  } else {
    store.currentOrder = null
    if (!items.value.length) addLine()
    // Seed first line product once catalog is loaded
    if (items.value[0] && !items.value[0].product_id && products.value[0]) {
      items.value[0].product_id = products.value[0].id
      items.value[0].unit_price = Number(products.value[0].base_price || 0)
    }
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
            title: 'Заказ поставщику',
            'active-nav': 'purchases',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Закупки' }, { label: 'Форма заказа' }],
          }
    "
  >
    <div class="mb-4 flex flex-wrap gap-2">
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="emit('navigate', 'purchases')">← К списку</button>
    </div>

    <div class="ds-surface mb-4 grid gap-3 p-3 sm:grid-cols-2">
      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Поставщик</label>
        <select v-model="form.supplier_id" class="ds-input mt-1 w-full" data-testid="po-supplier" :disabled="!isDraft">
          <option :value="null">—</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Склад</label>
        <select v-model="form.warehouse_id" class="ds-input mt-1 w-full" data-testid="po-warehouse" :disabled="!isDraft">
          <option :value="null">—</option>
          <option v-for="w in whStore.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Ожидаемая поставка</label>
        <input v-model="form.expected_delivery" type="date" class="ds-input mt-1 w-full" data-testid="po-delivery" :disabled="!isDraft" />
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Статус</label>
        <div class="mt-2" data-testid="po-status">
          <DsBadge :status="String(currentOrder?.status || 'NEW')" dot />
        </div>
      </div>
    </div>

    <div class="mb-2 flex justify-between">
      <div class="text-[12px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Позиции</div>
      <button v-if="isDraft" type="button" class="ds-btn ds-btn-sm" data-testid="po-add-line" @click="addLine">+ Позиция</button>
    </div>

    <DsTable :columns="columns" :rows="items" density="compact">
      <template #product_id="{ row }">
        <select v-model="row.product_id" class="ds-input h-8" :disabled="!isDraft" data-testid="po-product">
          <option :value="null">—</option>
          <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </template>
      <template #qty="{ row }">
        <input v-model.number="row.qty" type="number" min="0.001" step="0.001" class="ds-input h-8 w-24" :disabled="!isDraft" data-testid="po-qty" />
      </template>
      <template #unit_price="{ row }">
        <input v-model.number="row.unit_price" type="number" min="0" step="0.01" class="ds-input h-8 w-28" :disabled="!isDraft" data-testid="po-price" />
      </template>
      <template #received_qty="{ value }">
        <span>{{ value ?? 0 }}</span>
      </template>
      <template #receive_now="{ row }">
        <input
          v-model.number="row.receive_now"
          type="number"
          min="0"
          step="0.001"
          class="ds-input h-8 w-24"
          :disabled="!canReceive"
          data-testid="po-receive-qty"
        />
      </template>
    </DsTable>

    <div v-if="localError || error" class="ds-surface mt-3 p-3" style="color: var(--color-danger)">{{ localError || error }}</div>
    <div v-if="success" class="ds-surface mt-3 p-3" style="color: var(--color-success, #22c55e)">{{ success }}</div>

    <div class="mt-4 flex flex-wrap gap-2">
      <button
        v-if="isDraft && !currentOrder"
        type="button"
        class="ds-btn ds-btn-primary ds-btn-sm"
        data-testid="po-save"
        :disabled="saving"
        @click="saveDraft"
      >
        Сохранить
      </button>
      <button
        v-if="canConfirm"
        type="button"
        class="ds-btn ds-btn-primary ds-btn-sm"
        data-testid="po-confirm"
        :disabled="saving"
        @click="confirm"
      >
        Подтвердить
      </button>
      <button
        v-if="canReceive"
        type="button"
        class="ds-btn ds-btn-primary ds-btn-sm"
        data-testid="po-receive"
        :disabled="saving"
        @click="receive"
      >
        Принять
      </button>
    </div>
  </component>
</template>
