<script setup lang="ts">
/**
 * POS cart panel — lines, discount, total
 */
import { ref } from 'vue'
import type { PosCartLine } from '@/stores/usePosStore'

const props = defineProps<{
  lines: PosCartLine[]
  subtotal: number
  totalDue: number
  discountPercent: number
  promoCode: string
  itemsCount: number
}>()

const emit = defineEmits<{
  (e: 'update:qty', payload: { key: string; qty: number }): void
  (e: 'remove', key: string): void
  (e: 'apply-promo', code: string): void
  (e: 'update:discountPercent', v: number): void
  (e: 'clear'): void
}>()

const promoInput = ref(props.promoCode || '')

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 0 })}`
}
</script>

<template>
  <section
    class="flex h-full min-h-0 flex-col border"
    style="background: #11151a; border-color: #1f2937; border-radius: 4px"
  >
    <div class="flex items-center justify-between gap-2 border-b px-3 py-2" style="border-color: #1f2937">
      <div>
        <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">Чек</div>
        <div class="text-sm font-medium text-white">Корзина · {{ itemsCount }} ед.</div>
      </div>
      <button
        type="button"
        class="h-9 border px-2 font-mono text-[11px]"
        style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
        :disabled="!lines.length"
        @click="emit('clear')"
      >
        Очистить
      </button>
    </div>

    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
      <article
        v-for="row in lines"
        :key="row.key"
        class="border p-3"
        style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="truncate text-[13px] font-medium text-white">{{ row.title }}</div>
            <div class="font-mono text-[11px]" style="color: #6b7280">
              {{ row.sku }} · {{ money(row.price) }}
            </div>
          </div>
          <button
            type="button"
            class="h-8 w-8 shrink-0 border font-mono text-xs"
            style="border-color: #374151; color: #9ca3af; border-radius: 4px"
            aria-label="Удалить"
            @click="emit('remove', row.key)"
          >
            ×
          </button>
        </div>
        <div class="mt-2 flex items-center justify-between gap-2">
          <div class="flex items-center gap-1">
            <button
              type="button"
              class="h-10 w-10 border text-lg text-white"
              style="border-color: #1f2937; border-radius: 4px; background: #161b22"
              @click="emit('update:qty', { key: row.key, qty: row.qty - 1 })"
            >
              −
            </button>
            <input
              :value="row.qty"
              type="number"
              min="0"
              step="1"
              inputmode="numeric"
              class="ds-input h-10 w-16 text-center font-mono text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
              @change="emit('update:qty', { key: row.key, qty: Number(($event.target as HTMLInputElement).value) })"
            >
            <button
              type="button"
              class="h-10 w-10 border text-lg text-white"
              style="border-color: #1f2937; border-radius: 4px; background: #161b22"
              @click="emit('update:qty', { key: row.key, qty: row.qty + 1 })"
            >
              +
            </button>
          </div>
          <div class="font-mono text-[15px] font-bold tabular-nums" style="color: #f59e0b">
            {{ money(row.line) }}
          </div>
        </div>
      </article>

      <p v-if="!lines.length" class="py-10 text-center text-sm" style="color: #9ca3af">
        Отсканируйте штрихкод или выберите товар справа
      </p>
    </div>

    <div class="space-y-2 border-t p-3" style="border-color: #1f2937">
      <div class="flex gap-2">
        <input
          v-model="promoInput"
          type="text"
          class="ds-input h-11 min-w-0 flex-1 font-mono text-sm"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          placeholder="Промокод"
          @keydown.enter.prevent="emit('apply-promo', promoInput)"
        >
        <button
          type="button"
          class="h-11 border px-3 font-mono text-[11px]"
          style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px"
          @click="emit('apply-promo', promoInput)"
        >
          OK
        </button>
      </div>
      <label class="flex items-center justify-between gap-2 text-[12px]" style="color: #9ca3af">
        Скидка %
        <input
          :value="discountPercent"
          type="number"
          min="0"
          max="100"
          class="ds-input h-10 w-24 font-mono text-sm"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          @input="emit('update:discountPercent', Number(($event.target as HTMLInputElement).value) || 0)"
        >
      </label>
      <div class="flex items-end justify-between gap-2 pt-1">
        <div class="font-mono text-[10px] uppercase" style="color: #6b7280">
          Подытог {{ money(subtotal) }}
        </div>
        <div class="text-right">
          <div class="font-mono text-[10px] uppercase" style="color: #9ca3af">Итого к оплате</div>
          <div class="font-mono text-3xl font-bold tabular-nums leading-none" style="color: #f59e0b">
            {{ money(totalDue) }}
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
