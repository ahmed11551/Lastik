<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Nested BOM tree preview (Vector 4.A)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { computed, onMounted, ref } from 'vue'
import { apiGet, apiPost } from '@/autometria/api/client'

defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const recipes = ref<any[]>([])
const recipeId = ref<number | null>(null)
const qty = ref(1)
const loading = ref(false)
const error = ref('')
const preview = ref<any>(null)

type FlatNode = { key: string; depth: number; label: string }

function flattenNode(node: any, acc: FlatNode[] = [], prefix = 'r'): FlatNode[] {
  if (!node) return acc
  const waste = node.waste_percentage ? ` (waste ${node.waste_percentage}%)` : ''
  const sf = node.is_semi_finished ? ' [ПФ]' : ''
  const mark = node.is_leaf ? '○' : '●'
  acc.push({
    key: `${prefix}-${node.product_id}-${node.depth ?? 0}-${acc.length}`,
    depth: Number(node.depth || 0),
    label: `${mark} #${node.product_id} ${node.name || '—'} · ${node.qty}${waste}${sf}`,
  })
  for (const [i, child] of (node.children || []).entries()) {
    flattenNode(child, acc, `${prefix}.${i}`)
  }
  return acc
}

const flatTree = computed(() => flattenNode(preview.value?.tree))

async function loadRecipes() {
  try {
    const payload = await apiGet('/recipes', { silent: true })
    recipes.value = Array.isArray(payload?.data) ? payload.data : []
    if (!recipeId.value && recipes.value[0]) {
      recipeId.value = recipes.value[0].id
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось загрузить рецептуры'
  }
}

async function runPreview() {
  error.value = ''
  preview.value = null
  if (!recipeId.value) {
    error.value = 'Выберите рецептуру'
    return
  }
  loading.value = true
  try {
    const payload = await apiPost('/production/nested-preview', {
      recipe_id: recipeId.value,
      qty: Number(qty.value) || 1,
    })
    preview.value = payload?.data ?? null
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка развёртывания BOM'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') document.title = 'Nested BOM · AUTOMETRIA'
  void loadRecipes()
})
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? {}
        : {
            title: 'Nested BOM',
            'active-nav': 'nested_bom',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Склад' }, { label: 'Nested BOM' }],
          }
    "
  >
    <div class="ds-toolbar mb-4">
      <div class="ds-toolbar__filters flex flex-wrap items-center gap-2">
        <select v-model="recipeId" class="ds-input h-9" data-testid="nested-bom-recipe">
          <option :value="null">Рецептура</option>
          <option v-for="r in recipes" :key="r.id" :value="r.id">
            #{{ r.id }} — {{ r.product_name || r.product?.name || '—' }}
          </option>
        </select>
        <input
          v-model.number="qty"
          type="number"
          min="0.001"
          step="0.001"
          class="ds-input h-9 w-28"
          data-testid="nested-bom-qty"
          aria-label="Количество"
        >
        <button
          type="button"
          class="ds-btn ds-btn-primary ds-btn-sm"
          data-testid="nested-bom-preview"
          :disabled="loading"
          @click="runPreview"
        >
          Развернуть
        </button>
      </div>
    </div>

    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>
    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>

    <div v-if="preview" class="grid gap-4 lg:grid-cols-2">
      <div class="ds-surface p-4">
        <h3 class="mb-2 text-sm font-semibold">Дерево потребностей</h3>
        <p class="mb-3 text-xs" style="color: var(--color-text-secondary)">
          {{ preview.product_name }} · qty {{ preview.qty }} · max depth {{ preview.max_bom_depth }}
        </p>
        <div
          v-for="line in flatTree"
          :key="line.key"
          class="font-mono text-xs"
          :style="{ marginLeft: `${line.depth * 12}px` }"
        >
          {{ line.label }}
        </div>
      </div>
      <div class="ds-surface p-4">
        <h3 class="mb-2 text-sm font-semibold">Leaf-ингредиенты (сумма)</h3>
        <ul class="space-y-1 font-mono text-xs">
          <li v-for="leaf in preview.leaves || []" :key="leaf.product_id">
            #{{ leaf.product_id }} {{ leaf.name || '—' }} — {{ leaf.qty }}
          </li>
          <li v-if="!(preview.leaves || []).length" style="color: var(--color-text-secondary)">Нет leaf-строк</li>
        </ul>
      </div>
    </div>
  </component>
</template>
