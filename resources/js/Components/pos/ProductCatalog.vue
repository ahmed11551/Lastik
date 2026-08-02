<script setup lang="ts">
/**
 * POS catalog tile grid + barcode/SKU search
 */
import { nextTick, ref, watch } from 'vue'
import type { CachedProduct } from '@/services/offlineDb'

const props = defineProps<{
  products: CachedProduct[]
  query: string
  categories: string[]
  activeCategory: string
  loading?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:query', v: string): void
  (e: 'update:activeCategory', v: string): void
  (e: 'add', product: CachedProduct): void
  (e: 'scan', code: string): void
  (e: 'focus-search'): void
}>()

const searchRef = ref<HTMLInputElement | null>(null)

watch(
  () => props.query,
  async () => {
    await nextTick()
  },
)

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

function onEnter(): void {
  const q = String(props.query || '').trim()
  if (!q) return
  if (/^\d{8,14}$/.test(q)) {
    emit('scan', q)
    return
  }
  if (props.products[0]) emit('add', props.products[0])
}

defineExpose({
  focus: async () => {
    await nextTick()
    searchRef.value?.focus()
    searchRef.value?.select?.()
  },
  el: searchRef,
})
</script>

<template>
  <section
    class="flex h-full min-h-0 flex-col border"
    style="background: #11151a; border-color: #1f2937; border-radius: 4px"
  >
    <div class="border-b p-3" style="border-color: #1f2937">
      <div class="mb-2 font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
        Каталог · F2 поиск
      </div>
      <div class="relative">
        <input
          ref="searchRef"
          :value="query"
          type="search"
          inputmode="search"
          autocomplete="off"
          enterkeyhint="done"
          class="ds-input h-12 w-full pr-14 font-mono text-base"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          placeholder="SKU / штрихкод / название…"
          @input="emit('update:query', ($event.target as HTMLInputElement).value)"
          @keydown.enter.prevent="onEnter"
        >
        <kbd
          class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 border px-1.5 font-mono text-[10px]"
          style="color: #f59e0b; border-color: #1f2937; border-radius: 4px; background: #11151a"
        >F2</kbd>
      </div>

      <div class="mt-2 flex flex-wrap gap-1.5">
        <button
          v-for="c in categories"
          :key="c"
          type="button"
          class="h-9 border px-2.5 font-mono text-[11px] capitalize"
          :style="{
            borderRadius: '4px',
            borderColor: activeCategory === c ? '#f59e0b' : '#1f2937',
            color: activeCategory === c ? '#f59e0b' : '#9ca3af',
            background: '#0b0d10',
          }"
          @click="emit('update:activeCategory', c)"
        >
          {{ c === 'all' ? 'Все' : c }}
        </button>
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-3">
      <div v-if="loading" class="py-8 text-center text-sm" style="color: #9ca3af">Загрузка каталога…</div>
      <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <button
          v-for="p in products"
          :key="p.product_id"
          type="button"
          class="min-h-[88px] border p-2.5 text-left transition-colors active:scale-[0.98]"
          style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
          @click="emit('add', p)"
        >
          <div class="line-clamp-2 text-[12px] font-medium leading-snug text-white">{{ p.title }}</div>
          <div class="mt-1 font-mono text-[10px]" style="color: #6b7280">{{ p.sku || p.barcode }}</div>
          <div class="mt-2 font-mono text-[13px] font-bold" style="color: #f59e0b">{{ money(p.price) }}</div>
        </button>
      </div>
      <p v-if="!loading && !products.length" class="py-8 text-center text-sm" style="color: #9ca3af">
        Ничего не найдено
      </p>
    </div>
  </section>
</template>
