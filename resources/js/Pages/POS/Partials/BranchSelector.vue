<script setup lang="ts">
import { DsBadge } from '@/design-system'
import { ref, computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { usePosStore } from '@/stores/usePosStore'
import { useWarehouseStore } from '@/autometria/stores/warehouseStore'
import { apiGet } from '@/autometria/api/client'

const pos = usePosStore()
const whStore = useWarehouseStore()
const { activeWarehouseId, activeLocationId } = storeToRefs(pos)

const warehouses = computed(() => whStore.warehouses)
const selectedId = ref<number | null>(activeWarehouseId.value)
const stockByWarehouse = ref<Record<number, number>>({})
const loadingStock = ref(false)

const currentStock = computed(() => (selectedId.value != null ? stockByWarehouse.value[selectedId.value] ?? null : null))
const neighborStock = computed(() =>
  warehouses.value
    .filter((w) => w.id !== selectedId.value)
    .map((w) => ({ id: w.id, name: w.name, qty: stockByWarehouse.value[w.id] ?? null })),
)

async function loadStock() {
  loadingStock.value = true
  try {
    const ids = warehouses.value.map((w) => w.id)
    await Promise.all(
      ids.map(async (id) => {
        try {
          const payload = await apiGet('/stock', { params: { warehouse_id: id, per_page: 1 }, silent: true })
          const list = Array.isArray(payload?.data) ? payload.data : []
          stockByWarehouse.value[id] = (list[0]?.available as number) ?? 0
        } catch {
          stockByWarehouse.value[id] = 0
        }
      }),
    )
  } finally {
    loadingStock.value = false
  }
}

function apply() {
  const wh = warehouses.value.find((w) => w.id === selectedId.value)
  pos.setActiveWarehouse(selectedId.value, wh?.location_id ?? null)
}

onMounted(() => {
  if (!warehouses.value.length) whStore.fetchWarehouses()
  loadStock()
})
</script>

<template>
  <div class="branch-selector">
    <div class="flex items-center gap-2">
      <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Склад</label>
      <select v-model="selectedId" class="ds-input h-9 flex-1" data-testid="branch-select" @change="apply">
        <option :value="null">— не выбран —</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
    </div>

    <div v-if="loadingStock" class="mt-2 text-[11px]" style="color: var(--color-text-secondary)">Остатки…</div>

    <div v-if="selectedId != null && !loadingStock" class="mt-2 space-y-1">
      <div class="ds-surface flex items-center justify-between p-2">
        <span class="text-[12px]">Текущий склад</span>
        <span :class="currentStock != null && currentStock > 0 ? 'ds-badge ds-badge--success' : 'ds-badge ds-badge--warning'">
          {{ currentStock != null ? currentStock + ' шт' : 'нет данных' }}
        </span>
      </div>

      <div v-if="neighborStock.length" class="mt-1">
        <div class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Соседние склады</div>
        <div v-for="n in neighborStock" :key="n.id" class="ds-surface flex items-center justify-between p-2 text-[12px]">
          <span>{{ n.name }}</span>
          <span>{{ n.qty != null ? n.qty + ' шт' : '—' }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
