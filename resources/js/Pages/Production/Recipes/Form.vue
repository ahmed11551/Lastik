<script setup lang="ts">
/**
 * AUTOMETRIA ERP — BOM / recipe constructor (Sprint G)
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { ref, computed } from 'vue'
import { apiGet, apiPost } from '@/autometria/api/client'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  embedded: { type: Boolean, default: false },
})

const emit = defineEmits<{ saved: [] }>()

const finishedProduct = ref<any>(null)
const outputQty = ref<number>(1)
const instructions = ref('')
const ingredients = ref<any[]>([])
const productQuery = ref('')
const productResults = ref<any[]>([])
const searching = ref(false)
const saving = ref(false)
const error = ref('')
const pickMode = ref<'finished' | 'ingredient'>('finished')
const ingredientIdx = ref(0)

async function searchProducts() {
  const q = productQuery.value.trim()
  if (!q) {
    productResults.value = []
    return
  }
  searching.value = true
  try {
    const payload = await apiGet('/products', { params: { q }, silent: true })
    const list = Array.isArray(payload?.data) ? payload.data : []
    productResults.value = list.map((p: any) => ({
      id: p.id,
      name: p.name,
      article: p.article || `ID-${p.id}`,
      base_price: Number(p.base_price || 0),
    }))
  } catch {
    productResults.value = []
  } finally {
    searching.value = false
  }
}

function pickFinished(p: any) {
  finishedProduct.value = p
  productQuery.value = ''
  productResults.value = []
  pickMode.value = 'finished'
}

function addIngredient() {
  ingredients.value.push({
    product_id: 0,
    name: '',
    article: '',
    quantity: 1,
    waste_percentage: 0,
  })
}

function pickIngredient(p: any, idx: number) {
  if (ingredients.value[idx]) {
    ingredients.value[idx].product_id = p.id
    ingredients.value[idx].name = p.name
    ingredients.value[idx].article = p.article
  }
  productResults.value = []
  productQuery.value = ''
}

function onPickResult(p: any) {
  if (pickMode.value === 'finished') pickFinished(p)
  else pickIngredient(p, ingredientIdx.value)
}

const columns = [
  { key: 'article', label: 'Артикул' },
  { key: 'name', label: 'Компонент' },
  { key: 'quantity', label: 'Брутто' },
  { key: 'waste_percentage', label: '% потерь' },
]

const canSave = computed(
  () =>
    finishedProduct.value?.id &&
    ingredients.value.length > 0 &&
    ingredients.value.every((i) => i.product_id > 0 && Number(i.quantity) > 0),
)

async function save() {
  if (!canSave.value) {
    error.value = 'Укажите готовый продукт и компоненты'
    return
  }
  saving.value = true
  error.value = ''
  try {
    await apiPost('/recipes', {
      product_id: finishedProduct.value.id,
      yield_quantity: Number(outputQty.value || 1),
      instructions: instructions.value || null,
      items: ingredients.value.map((i) => ({
        ingredient_id: i.product_id,
        quantity: Number(i.quantity),
        waste_percentage: Number(i.waste_percentage || 0),
      })),
    })
    emit('saved')
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось сохранить рецептуру'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <component
    :is="embedded ? 'div' : AutometriaLayout"
    v-bind="
      embedded
        ? { class: 'ds-surface space-y-3 p-3' }
        : {
            title: 'Конструктор BOM',
            'active-nav': 'production',
            'current-shift-open': currentShiftOpen,
            'shift-started-at': shiftStartedAt,
            'shift-revenue': shiftRevenue,
            breadcrumbs: [{ label: 'Производство' }, { label: 'Рецептура' }],
          }
    "
  >
    <div class="space-y-3" :class="embedded ? '' : 'ds-surface p-3'">
      <div class="text-[12px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">
        Готовый продукт
      </div>
      <div v-if="finishedProduct" class="ds-surface p-2 text-[13px]">
        {{ finishedProduct.name }}
        <span class="opacity-60">({{ finishedProduct.article }})</span>
        <button type="button" class="ds-link ml-2" @click="finishedProduct = null">сменить</button>
      </div>
      <div v-else class="flex flex-wrap gap-2">
        <input
          v-model="productQuery"
          class="ds-input h-9 flex-1"
          placeholder="Поиск продукта…"
          data-testid="bom-product-search"
          @input="pickMode = 'finished'; searchProducts()"
        />
      </div>
      <div v-if="productResults.length && pickMode === 'finished'" class="ds-surface max-h-40 overflow-auto p-1">
        <button
          v-for="p in productResults"
          :key="p.id"
          type="button"
          class="block w-full px-2 py-1 text-left text-[13px] hover:underline"
          @click="onPickResult(p)"
        >
          {{ p.name }} · {{ p.article }}
        </button>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Выход (yield)</label>
          <input v-model.number="outputQty" type="number" min="0.001" step="0.001" class="ds-input mt-1 w-full" data-testid="bom-yield" />
        </div>
        <div>
          <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Инструкция</label>
          <input v-model="instructions" class="ds-input mt-1 w-full" data-testid="bom-instructions" />
        </div>
      </div>

      <div class="flex items-center justify-between">
        <div class="text-[12px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Компоненты</div>
        <button type="button" class="ds-btn ds-btn-sm" data-testid="bom-add-ingredient" @click="addIngredient">+ Компонент</button>
      </div>

      <div class="flex flex-wrap gap-2">
        <input
          v-model="productQuery"
          class="ds-input h-9 flex-1"
          placeholder="Поиск компонента для строки…"
          data-testid="bom-ingredient-search"
          @input="pickMode = 'ingredient'; searchProducts()"
        />
        <select v-model.number="ingredientIdx" class="ds-input h-9 w-28">
          <option v-for="(_, idx) in ingredients" :key="idx" :value="idx">Строка {{ idx + 1 }}</option>
        </select>
      </div>
      <div v-if="productResults.length && pickMode === 'ingredient'" class="ds-surface max-h-40 overflow-auto p-1">
        <button
          v-for="p in productResults"
          :key="'i-' + p.id"
          type="button"
          class="block w-full px-2 py-1 text-left text-[13px] hover:underline"
          @click="onPickResult(p)"
        >
          {{ p.name }} · {{ p.article }}
        </button>
      </div>

      <DsTable :columns="columns" :rows="ingredients" density="compact">
        <template #quantity="{ index }">
          <input
            v-model.number="ingredients[index].quantity"
            type="number"
            min="0.001"
            step="0.001"
            class="ds-input h-8 w-24"
            data-testid="bom-qty"
          />
        </template>
        <template #waste_percentage="{ index }">
          <input
            v-model.number="ingredients[index].waste_percentage"
            type="number"
            min="0"
            max="99.999"
            step="0.1"
            class="ds-input h-8 w-20"
            data-testid="bom-waste"
          />
        </template>
      </DsTable>

      <div v-if="error" class="text-[12px]" style="color: var(--color-danger)">{{ error }}</div>
      <button
        type="button"
        class="ds-btn ds-btn-primary ds-btn-sm"
        data-testid="bom-save"
        :disabled="saving || !canSave"
        @click="save"
      >
        Сохранить спецификацию
      </button>
    </div>
  </component>
</template>
