<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Умные закупки (ROP / dead stock)
 */
import { computed, onMounted, ref, watch } from 'vue'
import { DsBadge, DsTable } from '@/design-system'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import {
  fetchReorderRecommendations,
  runSmartPurchasesRecalc,
} from '@/autometria/api/inventory'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
}>()

type RecRow = {
  id: number
  sku: string
  name: string
  warehouse: string
  d_avg: string
  safety_stock: string
  rop: string
  on_hand: string
  suggested_qty: string
  is_dead_stock: boolean
  severity: string
  calculated_at?: string | null
}

const loading = ref(false)
const recalcing = ref(false)
const rows = ref<RecRow[]>([])
const criticalCount = ref(0)
const warehouseId = ref<string | number>('all')
const severity = ref('all')
const deadOnly = ref(false)

const SEV: Record<string, { variant: string; label: string }> = {
  ok: { variant: 'success', label: 'OK' },
  warn: { variant: 'warning', label: 'Внимание' },
  critical: { variant: 'danger', label: 'Критично' },
}

const columns = [
  { key: 'sku', label: 'Артикул' },
  { key: 'name', label: 'Номенклатура', mono: false },
  { key: 'warehouse', label: 'Склад', mono: false },
  { key: 'on_hand', label: 'Остаток' },
  { key: 'd_avg', label: 'd_avg' },
  { key: 'safety_stock', label: 'SS' },
  { key: 'rop', label: 'ROP' },
  { key: 'suggested_qty', label: 'Заказать' },
  { key: 'severity', label: 'Статус', mono: false },
]

const warehouseOptions = computed(() => [
  { id: 'all', name: 'Все склады' },
  ...props.warehouses,
])

async function load(): Promise<void> {
  loading.value = true
  try {
    const params: Record<string, string | number | boolean> = { per_page: 100 }
    if (warehouseId.value !== 'all') params.warehouse_id = Number(warehouseId.value)
    if (severity.value !== 'all') params.severity = severity.value
    if (deadOnly.value) params.dead_stock = true
    const res = await fetchReorderRecommendations(params)
    rows.value = res.data || []
    criticalCount.value = Number(res.meta?.critical_count || 0)
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function recalc(): Promise<void> {
  recalcing.value = true
  try {
    const wid = warehouseId.value === 'all' ? undefined : Number(warehouseId.value)
    await runSmartPurchasesRecalc(wid)
    await load()
  } finally {
    recalcing.value = false
  }
}

watch([warehouseId, severity, deadOnly], () => {
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-3">
    <div
      v-if="criticalCount > 0"
      class="flex flex-wrap items-center gap-2 border px-3 py-2 font-mono text-[11px]"
      style="background: color-mix(in srgb, #ef4444 18%, #0f172a); border-color: #7f1d1d; border-radius: 4px; color: #fca5a5"
    >
      <span class="uppercase tracking-wider">Alert</span>
      <span>{{ criticalCount }} SKU ниже критического ROP — требуется закупка</span>
    </div>

    <div
      class="flex flex-col gap-2 border p-3 sm:flex-row sm:flex-wrap sm:items-center"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <select
        v-model="warehouseId"
        class="ds-select h-11 w-full text-sm sm:h-9 sm:w-auto sm:text-xs"
        style="border-radius: 4px; background: #090d16; border-color: #1e293b"
      >
        <option v-for="w in warehouseOptions" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>

      <select
        v-model="severity"
        class="ds-select h-11 w-full text-sm sm:h-9 sm:w-auto sm:text-xs"
        style="border-radius: 4px; background: #090d16; border-color: #1e293b"
      >
        <option value="all">Все статусы</option>
        <option value="critical">Критично</option>
        <option value="warn">Внимание</option>
        <option value="ok">OK</option>
      </select>

      <label class="flex items-center gap-2 font-mono text-[11px]" style="color: #9ca3af">
        <input v-model="deadOnly" type="checkbox" class="accent-[#f59e0b]">
        Только dead stock
      </label>

      <div class="flex flex-1 items-center justify-end gap-2">
        <DsLoadingBadge v-if="loading || recalcing" label="Calculating" />
        <button
          type="button"
          class="h-11 border px-3 font-mono text-[11px] sm:h-9"
          style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px; background: #090d16"
          :disabled="recalcing"
          @click="recalc"
        >
          Пересчитать ROP
        </button>
      </div>
    </div>

    <div class="hidden min-w-0 overflow-x-auto sm:block">
      <DsTable
        :columns="columns"
        :rows="rows"
        density="compact"
        sticky-header
        max-height="min(62vh, 560px)"
        empty-text="Нет рекомендаций — нажмите «Пересчитать ROP»"
      >
        <template #sku="{ value }">
          <span class="font-mono text-[12px] text-white">{{ value }}</span>
        </template>
        <template #name="{ row }">
          <div class="leading-tight">
            <div class="font-sans text-[12px] text-white">{{ row.name }}</div>
            <div v-if="row.is_dead_stock" class="font-mono text-[10px]" style="color: #f59e0b">DEAD STOCK</div>
          </div>
        </template>
        <template #on_hand="{ value }">
          <span class="font-mono text-[12px] tabular-nums text-white">{{ value }}</span>
        </template>
        <template #d_avg="{ value }">
          <span class="font-mono text-[11px] tabular-nums" style="color: #9ca3af">{{ value }}</span>
        </template>
        <template #safety_stock="{ value }">
          <span class="font-mono text-[11px] tabular-nums" style="color: #9ca3af">{{ value }}</span>
        </template>
        <template #rop="{ value }">
          <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #38bdf8">{{ value }}</span>
        </template>
        <template #suggested_qty="{ value }">
          <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #f59e0b">{{ value }}</span>
        </template>
        <template #severity="{ row }">
          <DsBadge
            :variant="(SEV[row.severity] || SEV.ok).variant"
            :label="(SEV[row.severity] || SEV.ok).label"
            :status="row.severity"
            dot
          />
        </template>
      </DsTable>
    </div>

    <div class="space-y-2 sm:hidden">
      <article
        v-for="row in rows"
        :key="row.id"
        class="border p-3"
        style="background: #11151A; border-color: #1F2937; border-radius: 4px"
      >
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="font-mono text-[12px] text-white">{{ row.sku }}</div>
            <div class="font-sans text-[12px]" style="color: #9ca3af">{{ row.name }}</div>
          </div>
          <DsBadge
            :variant="(SEV[row.severity] || SEV.ok).variant"
            :label="(SEV[row.severity] || SEV.ok).label"
            :status="row.severity"
            dot
          />
        </div>
        <div class="mt-2 grid grid-cols-2 gap-1 font-mono text-[11px]" style="color: #a8b3c7">
          <span>Остаток {{ row.on_hand }}</span>
          <span>ROP {{ row.rop }}</span>
          <span>SS {{ row.safety_stock }}</span>
          <span style="color: #f59e0b">Заказать {{ row.suggested_qty }}</span>
        </div>
      </article>
      <p v-if="!rows.length && !loading" class="py-6 text-center font-mono text-[11px]" style="color: #6b7280">
        Нет рекомендаций
      </p>
    </div>
  </div>
</template>
