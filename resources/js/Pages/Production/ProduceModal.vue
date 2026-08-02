<script setup lang="ts">
import { DsModal, DsTable } from '@/design-system'
import { ref, computed, watch } from 'vue'
import { apiGet } from '@/autometria/api/client'

const props = defineProps<{
  open: boolean
  warehouseId?: number | null
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: { recipe_id: number; qty: number; warehouse_id: number | null }): void
}>()

const recipes = ref<any[]>([])
const selectedRecipe = ref<any>(null)
const produceQty = ref<number>(1)
const loading = ref(false)
const error = ref('')

async function loadRecipes() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/recipes', {
      params: props.warehouseId ? { warehouse_id: props.warehouseId } : undefined,
      silent: true,
    })
    recipes.value = Array.isArray(payload?.data) ? payload.data : []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить рецептуры'
    recipes.value = []
  } finally {
    loading.value = false
  }
}

watch(
  () => props.open,
  (v) => {
    if (v) {
      selectedRecipe.value = null
      produceQty.value = 1
      void loadRecipes()
    }
  },
)

const requiredRaw = computed(() => {
  if (!selectedRecipe.value) return []
  const scale = Number(produceQty.value || 1) / Math.max(0.001, Number(selectedRecipe.value.yield_quantity || 1))
  return (selectedRecipe.value.items || []).map((i: any) => ({
    name: i.ingredient_name || `ID-${i.ingredient_id}`,
    qty: Math.round(Number(i.quantity) * scale * 1000) / 1000,
    unit: 'ед.',
  }))
})

const columns = [
  { key: 'name', label: 'Сырьё' },
  { key: 'qty', label: 'Требуется' },
  { key: 'unit', label: 'Ед.' },
]

function confirm() {
  if (!selectedRecipe.value) return
  emit('confirm', {
    recipe_id: selectedRecipe.value.id,
    qty: Number(produceQty.value || 1),
    warehouse_id: props.warehouseId ?? null,
  })
  emit('update:open', false)
}
</script>

<template>
  <DsModal :show="open" @close="emit('update:open', false)">
    <div class="space-y-3 p-1">
      <div class="text-[14px] font-medium">Акт производства</div>

      <div v-if="loading" class="text-[12px]" style="color: var(--color-text-secondary)">Загрузка рецептур…</div>
      <div v-if="error" class="text-[12px]" style="color: var(--color-danger)">{{ error }}</div>

      <select
        class="ds-input h-9 w-full"
        data-testid="produce-recipe"
        :value="selectedRecipe?.id ?? ''"
        @change="selectedRecipe = recipes.find((r) => r.id === Number(($event.target as HTMLSelectElement).value)) || null"
      >
        <option value="">Выберите рецептуру</option>
        <option v-for="r in recipes" :key="r.id" :value="r.id">
          {{ r.product_name || r.product_id }} · выход {{ r.yield_quantity }}
        </option>
      </select>

      <div>
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Количество выпуска</label>
        <input v-model.number="produceQty" type="number" min="0.001" step="0.001" class="ds-input mt-1 w-full" data-testid="produce-qty" />
      </div>

      <div v-if="selectedRecipe" class="text-[12px]" style="color: var(--color-text-secondary)">
        Ориентир себестоимости: {{ selectedRecipe.unit_cost }} / ед. (FIFO)
      </div>

      <DsTable v-if="requiredRaw.length" :columns="columns" :rows="requiredRaw" density="compact" />

      <div class="flex justify-end gap-2">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="emit('update:open', false)">Отмена</button>
        <button
          type="button"
          class="ds-btn ds-btn-primary ds-btn-sm"
          data-testid="produce-confirm"
          :disabled="!selectedRecipe"
          @click="confirm"
        >
          Провести
        </button>
      </div>
    </div>
  </DsModal>
</template>
