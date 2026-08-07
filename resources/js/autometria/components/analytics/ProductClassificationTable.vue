<script setup lang="ts">
/**
 * AUTOMETRIA ERP — products of selected ABC/XYZ cell
 */
import { computed } from 'vue'
import { DsTable } from '@/design-system'
import type { AbcXyzProduct } from '@/autometria/stores/analyticsStore'

const props = defineProps<{
  products: AbcXyzProduct[]
  segment?: string | null
  loading?: boolean
}>()

const columns = [
  { key: 'product_name', label: 'Наименование', mono: false },
  { key: 'revenue', label: 'Выручка' },
  { key: 'share_pct', label: 'Доля (%)' },
  { key: 'cv', label: 'Коэф. вариации' },
  { key: 'segment', label: 'Группа', mono: false },
]

const rows = computed(() =>
  (props.products || []).map((p, i) => ({
    id: p.product_id || i,
    product_name: p.product_name || `ID ${p.product_id}`,
    revenue: Number(p.revenue ?? p.gross_profit ?? 0),
    share_pct: Number(p.share_pct ?? 0),
    cv: p.cv,
    segment: p.segment || `${p.abc || ''}${p.xyz || ''}`,
  })),
)

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 })}`
}
</script>

<template>
  <div class="classify-table border p-3" style="background: #0f172a; border-color: #1e293b; border-radius: 4px">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <div>
        <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
          Classification // {{ segment || '—' }}
        </div>
        <h3 class="text-sm font-medium text-white">
          Товары сегмента
          <span class="font-mono text-[12px]" style="color: #a8b3c7">· {{ rows.length }}</span>
        </h3>
      </div>
      <span v-if="loading" class="font-mono text-[11px]" style="color: #f59e0b">Loading…</span>
    </div>

    <!-- Mobile cards -->
    <div class="scroll-y-contain max-h-[min(48vh,420px)] space-y-2 sm:hidden">
      <article
        v-for="row in rows"
        :key="row.id"
        class="border p-3"
        style="background: #090d16; border-color: #1e293b; border-radius: 4px"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="truncate text-[13px] font-medium text-white">{{ row.product_name }}</div>
            <div class="mt-1 font-mono text-[11px]" style="color: #a8b3c7">
              {{ row.segment }} · доля {{ row.share_pct.toFixed(1) }}%
            </div>
          </div>
          <div class="shrink-0 text-right font-mono text-[12px] font-bold" style="color: #f59e0b">
            {{ money(row.revenue) }}
          </div>
        </div>
        <div class="mt-2 font-mono text-[11px]" style="color: #9ca3af">
          CV {{ row.cv == null ? '—' : `${Number(row.cv).toFixed(1)}%` }}
        </div>
      </article>
      <p v-if="!rows.length" class="py-6 text-center font-mono text-[11px]" style="color: #6b7280">
        В ячейке нет товаров за выбранный период
      </p>
    </div>

    <!-- Desktop -->
    <div class="hidden min-w-0 sm:block">
      <DsTable
        :columns="columns"
        :rows="rows"
        density="compact"
        sticky-header
        max-height="min(48vh, 420px)"
        empty-text="В ячейке нет товаров за выбранный период"
      >
        <template #product_name="{ value }">
          <span class="font-sans text-[12px] text-white">{{ value }}</span>
        </template>
        <template #revenue="{ value }">
          <span class="font-mono text-[12px] tabular-nums" style="color: #f59e0b">{{ money(value) }}</span>
        </template>
        <template #share_pct="{ value }">
          <span class="font-mono text-[12px] tabular-nums text-white">{{ Number(value).toFixed(1) }}%</span>
        </template>
        <template #cv="{ value }">
          <span class="font-mono text-[12px] tabular-nums" style="color: #a8b3c7">
            {{ value == null ? '—' : `${Number(value).toFixed(1)}%` }}
          </span>
        </template>
        <template #segment="{ value }">
          <span
            class="inline-block border px-1.5 py-0.5 font-mono text-[10px]"
            style="border-color: #1e293b; border-radius: 4px; color: #f59e0b; background: #090d16"
          >{{ value }}</span>
        </template>
      </DsTable>
    </div>
  </div>
</template>
