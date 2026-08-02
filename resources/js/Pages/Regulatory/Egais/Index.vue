<script setup lang="ts">
/**
 * AUTOMETRIA ERP — EGAIS unseal acts (Sprint E)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable, DsBadge } from '@/design-system'
import { onMounted, ref } from 'vue'
import { apiPost } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const acts = ref<any[]>([])
const showForm = ref(false)
const saving = ref(false)
const error = ref('')
const form = ref({
  product_id: '' as string | number,
  volume: 0.5,
  fsrar_id: '',
})

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'doc_type', label: 'Тип' },
  { key: 'fsrar_id', label: 'FSRAR' },
  { key: 'product_name', label: 'Продукция' },
  { key: 'volume', label: 'Объём' },
  { key: 'status', label: 'Статус' },
]

async function createUnseal() {
  saving.value = true
  error.value = ''
  try {
    const payload = await apiPost('/regulatory/egais/unseal', {
      product_id: Number(form.value.product_id),
      volume: Number(form.value.volume),
      fsrar_id: String(form.value.fsrar_id),
    })
    const d = payload?.data || payload
    acts.value.unshift({
      id: d.id,
      doc_type: d.doc_type,
      fsrar_id: d.fsrar_id,
      product_name: d.payload?.product_name || '—',
      volume: d.payload?.volume,
      status: d.status,
    })
    toast.success('Акт вскрытия создан (DRAFT)', 'ЕГАИС')
    showForm.value = false
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка создания акта'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'ЕГАИС · AUTOMETRIA'
  }
})
</script>

<template>
  <component
    :is="props.embedded ? 'div' : AutometriaLayout"
    v-bind="props.embedded ? { 'data-testid': 'regulatory-egais' } : {
      title: 'ЕГАИС — акты вскрытия',
      activeNav: 'egais',
      currentShiftOpen: props.currentShiftOpen,
      shiftStartedAt: props.shiftStartedAt,
      shiftRevenue: props.shiftRevenue,
      breadcrumbs: [{ label: 'Регуляторика' }, { label: 'ЕГАИС' }],
    }"
  >
    <div class="mb-4 flex gap-2">
      <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="egais-new" @click="showForm = !showForm">
        + Акт вскрытия
      </button>
    </div>

    <div v-if="showForm" class="ds-surface mb-4 grid gap-2 p-3 lg:grid-cols-4" data-testid="egais-form">
      <input v-model="form.product_id" class="ds-input" placeholder="product_id" data-testid="egais-product-id" />
      <input v-model.number="form.volume" type="number" min="0.001" step="0.001" class="ds-input" placeholder="Объём" />
      <input v-model="form.fsrar_id" class="ds-input" placeholder="FSRAR ID" data-testid="egais-fsrar" />
      <button type="button" class="ds-btn ds-btn-primary" :disabled="saving" @click="createUnseal">Создать DRAFT</button>
    </div>

    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <DsTable :columns="columns" :rows="acts" density="compact" sticky-header>
      <template #status="{ value }">
        <DsBadge class="ds-badge--warning">{{ value }}</DsBadge>
      </template>
    </DsTable>
  </component>
</template>
