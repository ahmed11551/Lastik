<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Marking codes registry (Sprint E)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable, DsBadge } from '@/design-system'
import { onMounted, ref } from 'vue'
import { apiGet } from '@/autometria/api/client'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const marks = ref<any[]>([])
const loading = ref(false)
const error = ref('')
const filters = ref({ status: '' })

const columns = [
  { key: 'code', label: 'КИЗ (DataMatrix)' },
  { key: 'gtin', label: 'GTIN' },
  { key: 'serial', label: 'Серийный' },
  { key: 'status', label: 'Статус' },
  { key: 'created_at', label: 'Дата' },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/regulatory/marking/codes', {
      params: { status: filters.value.status || undefined },
      silent: true,
    })
    marks.value = Array.isArray(payload?.data) ? payload.data : []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки реестра КИЗ'
    marks.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'Маркировка · AUTOMETRIA'
  }
  void load()
})
</script>

<template>
  <component
    :is="props.embedded ? 'div' : AutometriaLayout"
    v-bind="props.embedded ? { 'data-testid': 'regulatory-marking' } : {
      title: 'Реестр маркируемой продукции и КИЗ',
      activeNav: 'regulatory',
      currentShiftOpen: props.currentShiftOpen,
      shiftStartedAt: props.shiftStartedAt,
      shiftRevenue: props.shiftRevenue,
      breadcrumbs: [{ label: 'Регуляторика' }, { label: 'Маркировка' }],
    }"
  >
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <select v-model="filters.status" class="ds-input h-9" data-testid="filter-status" @change="load">
        <option value="">Все статусы</option>
        <option value="APPLIED">APPLIED</option>
        <option value="SOLD">SOLD</option>
        <option value="WRITTEN_OFF">WRITTEN_OFF</option>
        <option value="EMITTED">EMITTED</option>
      </select>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="load">Обновить</button>
    </div>

    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <DsTable :columns="columns" :rows="marks" density="compact" sticky-header>
      <template #status="{ value }">
        <DsBadge
          :class="
            value === 'APPLIED' || value === 'EMITTED'
              ? 'ds-badge--success'
              : value === 'SOLD'
                ? 'ds-badge--warning'
                : 'ds-badge--danger'
          "
        >
          {{ value }}
        </DsBadge>
      </template>
    </DsTable>
  </component>
</template>
