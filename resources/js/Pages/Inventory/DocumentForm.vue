<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Create / post inventory document (Sprint D)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useInventoryDocumentsStore, DOC_TYPES } from '@/autometria/stores/useInventoryDocumentsStore'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'
import { apiGet } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const emit = defineEmits<{ navigate: [id: string] }>()

const store = useInventoryDocumentsStore()
const whStore = useWarehouseStore()
const warehouses = computed(() => whStore.warehouses)

const docType = ref<string>('RECEIPT')
const fromWarehouseId = ref<number | null>(null)
const toWarehouseId = ref<number | null>(null)
const lines = ref<Array<{ product_id: number; sku: string; name: string; qty: number; price: number }>>([])
const productQuery = ref('')
const suggestions = ref<Array<{ id: number; article?: string; name: string; base_price?: number }>>([])
const saving = ref(false)
const error = ref<string | null>(null)

const typeLabel: Record<string, string> = {
  RECEIPT: 'Приход (оприходование)',
  WRITE_OFF: 'Списание',
  TRANSFER: 'Перемещение',
  INVENTORY: 'Инвентаризация (факт)',
}

const lineColumns = [
  { key: 'sku', label: 'Артикул' },
  { key: 'name', label: 'Наименование' },
  { key: 'qty', label: 'Кол-во' },
  { key: 'price', label: 'Себест.' },
  { key: '_actions', label: '' },
]

const showTarget = computed(() => docType.value === 'TRANSFER')

async function searchProducts() {
  const q = productQuery.value.trim()
  if (q.length < 1) {
    suggestions.value = []
    return
  }
  try {
    const payload = await apiGet('/products', { params: { q }, silent: true })
    const list = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []
    suggestions.value = list.slice(0, 12)
  } catch {
    suggestions.value = []
  }
}

function addProduct(p: { id: number; article?: string; name: string; base_price?: number }) {
  if (lines.value.some((l) => l.product_id === p.id)) {
    toast.warning('Товар уже в документе', 'Склад')
    return
  }
  lines.value.push({
    product_id: p.id,
    sku: p.article || String(p.id),
    name: p.name,
    qty: 1,
    price: Number(p.base_price || 0),
  })
  productQuery.value = ''
  suggestions.value = []
}

function removeLine(idx: number) {
  lines.value.splice(idx, 1)
}

function goBack() {
  if (props.embedded) {
    emit('navigate', 'inventory')
    return
  }
  location.hash = '#/inventory'
}

async function saveDraft() {
  if (!fromWarehouseId.value) {
    error.value = 'Укажите склад'
    return
  }
  if (!lines.value.length || lines.value.some((l) => !l.product_id)) {
    error.value = 'Добавьте товары с корректным product_id'
    return
  }
  saving.value = true
  error.value = null
  try {
    await store.createDraft({
      type: docType.value,
      from_warehouse_id: fromWarehouseId.value,
      to_warehouse_id: showTarget.value ? toWarehouseId.value : null,
      items: lines.value.map((l) => ({
        product_id: l.product_id,
        sku: l.sku,
        name: l.name,
        qty: Number(l.qty),
        price: Number(l.price),
      })),
    })
    toast.success('Черновик сохранён', 'Склад')
    goBack()
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

async function postDocument() {
  if (!fromWarehouseId.value) {
    error.value = 'Укажите склад'
    return
  }
  if (showTarget.value && !toWarehouseId.value) {
    error.value = 'Укажите склад-получатель'
    return
  }
  if (!lines.value.length) {
    error.value = 'Добавьте позиции'
    return
  }
  saving.value = true
  error.value = null
  try {
    const created = await store.createDraft({
      type: docType.value,
      from_warehouse_id: fromWarehouseId.value,
      to_warehouse_id: showTarget.value ? toWarehouseId.value : null,
      items: lines.value.map((l) => ({
        product_id: l.product_id,
        sku: l.sku,
        name: l.name,
        qty: Number(l.qty),
        price: Number(l.price),
      })),
    })
    const id = created?.id ?? created?.data?.id
    if (id) await store.postDocument(id)
    toast.success('Документ проведён', 'Склад')
    goBack()
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Ошибка проведения'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  if (typeof document !== 'undefined') {
    document.title = 'Создание складского документа · AUTOMETRIA'
  }
  if (!warehouses.value.length) {
    await whStore.fetchWarehouses?.()
  }
  if (warehouses.value.length && !fromWarehouseId.value) {
    fromWarehouseId.value = warehouses.value[0].id
  }
})
</script>

<template>
  <component
    :is="props.embedded ? 'div' : AutometriaLayout"
    v-bind="props.embedded ? { 'data-testid': 'inventory-form-embedded' } : {
      title: 'Создание документа',
      activeNav: 'inventory',
      currentShiftOpen: props.currentShiftOpen,
      shiftStartedAt: props.shiftStartedAt,
      shiftRevenue: props.shiftRevenue,
      breadcrumbs: [{ label: 'Склад' }, { label: 'Документы' }, { label: 'Создание' }],
    }"
  >
    <div class="mb-3">
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="goBack">← К списку</button>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
      <div class="ds-surface p-3">
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Тип документа</label>
        <select v-model="docType" class="ds-input mt-1 h-9 w-full" data-testid="doc-type">
          <option v-for="t in DOC_TYPES" :key="t" :value="t">{{ typeLabel[t] || t }}</option>
        </select>
      </div>
      <div class="ds-surface p-3">
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">
          {{ showTarget ? 'Склад отправитель' : 'Склад' }}
        </label>
        <select v-model="fromWarehouseId" class="ds-input mt-1 h-9 w-full" data-testid="from-warehouse">
          <option :value="null">—</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
      </div>
      <div v-if="showTarget" class="ds-surface p-3">
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Склад получатель</label>
        <select v-model="toWarehouseId" class="ds-input mt-1 h-9 w-full" data-testid="to-warehouse">
          <option :value="null">—</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
      </div>
    </div>

    <div class="ds-surface mb-4 p-3">
      <div class="relative flex gap-2">
        <input
          v-model="productQuery"
          type="text"
          class="ds-input h-9 flex-1"
          placeholder="Поиск товара (артикул / название)…"
          data-testid="product-query"
          @input="searchProducts"
        />
      </div>
      <ul v-if="suggestions.length" class="mt-2 space-y-1" data-testid="product-suggestions">
        <li v-for="p in suggestions" :key="p.id">
          <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm w-full justify-start" @click="addProduct(p)">
            {{ p.article || p.id }} — {{ p.name }}
          </button>
        </li>
      </ul>

      <DsTable :columns="lineColumns" :rows="lines" density="compact" class="mt-3">
        <template #qty="{ index }">
          <input v-model.number="lines[index].qty" type="number" min="0.001" step="0.001" class="ds-input h-8 w-24" />
        </template>
        <template #price="{ index }">
          <input v-model.number="lines[index].price" type="number" min="0" step="0.01" class="ds-input h-8 w-24" />
        </template>
        <template #_actions="{ index }">
          <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="removeLine(index)">×</button>
        </template>
      </DsTable>
    </div>

    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <div class="flex gap-2">
      <button type="button" class="ds-btn ds-btn-ghost" data-testid="save-draft" :disabled="saving" @click="saveDraft">
        Сохранить черновик
      </button>
      <button type="button" class="ds-btn ds-btn-primary" data-testid="post-document" :disabled="saving" @click="postDocument">
        Провести документ
      </button>
    </div>
  </component>
</template>
