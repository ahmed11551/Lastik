<script setup lang="ts">
/**
 * AUTOMETRIA ERP — ABC/XYZ 3×3 matrix grid (Cosmic Navy)
 */
import { computed } from 'vue'
import type { AbcXyzCell, AbcXyzSegment } from '@/autometria/stores/analyticsStore'

const props = defineProps<{
  cells: Record<string, AbcXyzCell>
  selectedCell?: AbcXyzSegment | null
  totalGrossProfit?: number
}>()

const emit = defineEmits<{
  select: [segment: AbcXyzSegment]
}>()

const ABC_ROWS = ['A', 'B', 'C'] as const
const XYZ_COLS = ['X', 'Y', 'Z'] as const

const HINT: Record<string, string> = {
  AX: 'Звёзды · высокая прибыль, стабильный спрос',
  AY: 'Дойные · прибыль высокая, спрос умеренно волатилен',
  AZ: 'Прибыльные, но непредсказуемые',
  BX: 'Середняки стабильные',
  BY: 'Середняки переменчивые',
  BZ: 'Середняки нестабильные',
  CX: 'Мелочь стабильная',
  CY: 'Мелочь переменчивая',
  CZ: 'Хвост · низкая маржа / хаотичный спрос',
}

function cellOf(abc: string, xyz: string): AbcXyzCell {
  const key = `${abc}${xyz}` as AbcXyzSegment
  return (
    props.cells?.[key] || {
      segment: key,
      abc: abc as 'A' | 'B' | 'C',
      xyz: xyz as 'X' | 'Y' | 'Z',
      count: 0,
      gross_profit: 0,
      share_pct: 0,
    }
  )
}

function toneClass(segment: string): string {
  if (segment === 'AX' || segment === 'AY') return 'cell--profit'
  if (segment === 'AZ' || segment === 'BX') return 'cell--ok'
  if (segment === 'BY' || segment === 'CX') return 'cell--warn'
  return 'cell--risk' // BZ, CY, CZ
}

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 })}`
}

const grid = computed(() =>
  ABC_ROWS.map((abc) => XYZ_COLS.map((xyz) => cellOf(abc, xyz))),
)
</script>

<template>
  <div class="abc-matrix" data-testid="abc-xyz-matrix">
    <div class="abc-matrix__head">
      <span class="abc-matrix__corner">ABC ↓ / XYZ →</span>
      <span v-for="xyz in XYZ_COLS" :key="xyz" class="abc-matrix__col-label">{{ xyz }}</span>
    </div>

    <div
      v-for="(row, ri) in grid"
      :key="ABC_ROWS[ri]"
      class="abc-matrix__row"
    >
      <div class="abc-matrix__row-label">{{ ABC_ROWS[ri] }}</div>
      <button
        v-for="cell in row"
        :key="cell.segment"
        type="button"
        class="abc-matrix__cell"
        :class="[
          toneClass(cell.segment),
          { 'is-selected': selectedCell === cell.segment },
        ]"
        :title="HINT[cell.segment]"
        @click="emit('select', cell.segment)"
      >
        <div class="abc-matrix__seg">{{ cell.segment }}</div>
        <div class="abc-matrix__count">{{ cell.count }} SKU</div>
        <div class="abc-matrix__share">{{ Number(cell.share_pct || 0).toFixed(1) }}% прибыли</div>
        <div class="abc-matrix__profit">{{ money(cell.gross_profit) }}</div>
      </button>
    </div>
  </div>
</template>

<style scoped>
.abc-matrix {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  width: 100%;
}

.abc-matrix__head,
.abc-matrix__row {
  display: grid;
  grid-template-columns: 2.5rem repeat(3, minmax(0, 1fr));
  gap: 0.4rem;
  align-items: stretch;
}

.abc-matrix__corner,
.abc-matrix__col-label,
.abc-matrix__row-label {
  font-family: ui-monospace, 'JetBrains Mono', monospace;
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #a8b3c7;
  display: flex;
  align-items: center;
  justify-content: center;
}

.abc-matrix__cell {
  text-align: left;
  border: 1px solid #1e293b;
  border-radius: 4px;
  padding: 0.65rem 0.7rem;
  min-height: 4.75rem;
  background: #0f172a;
  color: #e8edf5;
  cursor: pointer;
  transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
}

.abc-matrix__cell:active {
  transform: scale(0.98);
}

.abc-matrix__cell.is-selected {
  border-color: #f59e0b;
  box-shadow: 0 0 0 1px color-mix(in srgb, #f59e0b 55%, transparent);
}

.abc-matrix__seg {
  font-family: ui-monospace, 'JetBrains Mono', monospace;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.06em;
}

.abc-matrix__count {
  margin-top: 0.25rem;
  font-size: 12px;
  color: #e8edf5;
}

.abc-matrix__share {
  margin-top: 0.15rem;
  font-family: ui-monospace, 'JetBrains Mono', monospace;
  font-size: 11px;
  color: #a8b3c7;
}

.abc-matrix__profit {
  margin-top: 0.35rem;
  font-family: ui-monospace, 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 600;
  color: #f59e0b;
}

.cell--profit {
  background: linear-gradient(160deg, color-mix(in srgb, #10b981 22%, #0f172a), #0f172a);
  border-color: color-mix(in srgb, #10b981 45%, #1e293b);
}
.cell--ok {
  background: linear-gradient(160deg, color-mix(in srgb, #1a3c8c 35%, #0f172a), #0f172a);
}
.cell--warn {
  background: linear-gradient(160deg, color-mix(in srgb, #f59e0b 18%, #0f172a), #0f172a);
  border-color: color-mix(in srgb, #f59e0b 35%, #1e293b);
}
.cell--risk {
  background: linear-gradient(160deg, color-mix(in srgb, #ef4444 20%, #0f172a), #0f172a);
  border-color: color-mix(in srgb, #ef4444 40%, #1e293b);
}

@media (max-width: 430px) {
  .abc-matrix__head,
  .abc-matrix__row {
    grid-template-columns: 1.6rem repeat(3, minmax(0, 1fr));
    gap: 0.3rem;
  }
  .abc-matrix__cell {
    min-height: 4.25rem;
    padding: 0.5rem 0.45rem;
  }
  .abc-matrix__profit {
    font-size: 10px;
  }
}
</style>
