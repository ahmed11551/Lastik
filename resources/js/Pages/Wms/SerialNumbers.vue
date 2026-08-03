<script setup lang="ts">
/**
 * AUTOMETRIA ERP — WMS Light serial numbers
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { onMounted, ref } from 'vue'
import { apiGet, apiPost } from '@/autometria/api/client'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const rows = ref<any[]>([])
const loading = ref(false)
const error = ref('')
const status = ref('')

const receiveForm = ref({
  product_id: '',
  stock_batch_id: '',
  serials: '',
})

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'serial', label: 'Серийный №' },
  { key: 'product_name', label: 'Товар' },
  { key: 'stock_batch_id', label: 'Партия' },
  { key: 'status', label: 'Статус' },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/wms/serial-numbers', {
      params: status.value ? { status: status.value } : undefined,
      silent: true,
    })
    rows.value = Array.isArray(payload?.data) ? payload.data : []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки серийников'
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function receive() {
  error.value = ''
  const serials = receiveForm.value.serials
    .split(/[\n,;]+/)
    .map((s) => s.trim())
    .filter(Boolean)
  if (!receiveForm.value.product_id || !receiveForm.value.stock_batch_id || !serials.length) {
    error.value = 'Укажите product_id, stock_batch_id и серийники'
    return
  }
  try {
    await apiPost('/wms/serial-numbers', {
      product_id: Number(receiveForm.value.product_id),
      stock_batch_id: Number(receiveForm.value.stock_batch_id),
      serials,
    })
    receiveForm.value.serials = ''
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось зарегистрировать'
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') document.title = 'Серийники WMS · AUTOMETRIA'
  void load()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'WMS · Серийные номера',
            'active-nav': 'wms_serials',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Склад' }, { label: 'Серийники' }],
          }
    "
  >
    <div class="ds-toolbar mb-4">
      <div class="ds-toolbar__filters flex flex-wrap items-center gap-2">
        <select v-model="status" class="ds-input h-9" data-testid="wms-serial-status" @change="load">
          <option value="">Все статусы</option>
          <option value="IN_STOCK">IN_STOCK</option>
          <option value="SOLD">SOLD</option>
          <option value="WRITTEN_OFF">WRITTEN_OFF</option>
        </select>
        <input v-model="receiveForm.product_id" class="ds-input h-9 w-28" placeholder="product_id">
        <input v-model="receiveForm.stock_batch_id" class="ds-input h-9 w-32" placeholder="batch_id">
        <input
          v-model="receiveForm.serials"
          class="ds-input h-9 min-w-[180px]"
          placeholder="SN1, SN2…"
          data-testid="wms-serial-input"
        >
        <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="wms-serial-receive" @click="receive">
          Принять SN
        </button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="load">Обновить</button>
      </div>
    </div>

    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>
    <DsTable :columns="columns" :rows="rows" density="compact" sticky-header />
  </component>
</template>
