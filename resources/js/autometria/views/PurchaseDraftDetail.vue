<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Single purchase draft detail (edit approved_qty + live total)
 */
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useProcurementStore, type PurchaseDraft } from '@/autometria/stores/procurementStore'

const props = defineProps<{
  draft: PurchaseDraft
  compact?: boolean
}>()

const emit = defineEmits<{
  approve: [id: string]
  send: [id: string]
}>()

const store = useProcurementStore()
const { suppliers, approving } = storeToRefs(store)

const total = computed(() =>
  props.draft.lines.reduce((s, l) => s + Number(l.approved_qty || 0) * Number(l.unit_price || 0), 0),
)

const columns = [
  { key: 'sku', label: 'SKU' },
  { key: 'name', label: 'Товар', mono: false },
  { key: 'suggested_qty', label: 'ROP qty' },
  { key: 'approved_qty', label: 'К заказу', mono: false },
  { key: 'unit_price', label: 'Цена' },
  { key: 'line_total', label: 'Сумма' },
]

const rows = computed(() =>
  props.draft.lines.map((l) => ({
    ...l,
    line_total: (Number(l.approved_qty || 0) * Number(l.unit_price || 0)).toFixed(2),
  })),
)

const statusMap: Record<string, { variant: string; label: string }> = {
  draft: { variant: 'warning', label: 'Черновик' },
  approved: { variant: 'info', label: 'Утверждён' },
  sent: { variant: 'success', label: 'Отправлен' },
}

function onQty(productId: number, ev: Event) {
  const v = Number((ev.target as HTMLInputElement).value)
  store.updateLineQty(props.draft.id, productId, v)
}

function onSupplier(ev: Event) {
  store.updateDraftSupplier(props.draft.id, Number((ev.target as HTMLSelectElement).value))
}
</script>

<template>
  <div
    class="border"
    style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    data-testid="purchase-draft-detail"
  >
    <div class="flex flex-col gap-2 border-b p-3 sm:flex-row sm:items-center sm:justify-between" style="border-color: #1e293b">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono text-[12px] font-bold text-white">{{ draft.supplier_name }}</span>
          <DsBadge
            :variant="(statusMap[draft.status] || statusMap.draft).variant"
            :label="(statusMap[draft.status] || statusMap.draft).label"
            :status="draft.status"
            dot
          />
        </div>
        <p class="mt-1 font-mono text-[10px]" style="color: #6b7280">
          {{ draft.warehouse_name }} · {{ draft.lines.length }} SKU
          <span v-if="draft.server_order_id"> · SO #{{ draft.server_order_id }}</span>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <select
          class="ds-select h-11 w-full text-sm sm:h-8 sm:w-auto sm:text-xs"
          style="border-radius: 4px; background: #090d16; border-color: #1e293b"
          :value="draft.supplier_id || ''"
          :disabled="draft.status === 'sent'"
          @change="onSupplier"
        >
          <option value="" disabled>Поставщик…</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
    </div>

    <!-- Mobile lines -->
    <div class="space-y-2 p-3 sm:hidden">
      <div
        v-for="line in draft.lines"
        :key="line.product_id"
        class="border p-2"
        style="background: #090d16; border-color: #1e293b; border-radius: 4px"
      >
        <div class="font-mono text-[11px] text-white">{{ line.sku }}</div>
        <div class="truncate text-[11px]" style="color: #9ca3af">{{ line.name }}</div>
        <div class="mt-2 flex items-center gap-2">
          <label class="font-mono text-[10px]" style="color: #6b7280">qty</label>
          <input
            type="number"
            min="0"
            step="1"
            class="ds-input h-11 flex-1 font-mono text-sm"
            style="border-radius: 4px; background: #0f172a"
            :value="line.approved_qty"
            :disabled="draft.status === 'sent'"
            @input="onQty(line.product_id, $event)"
          >
          <span class="font-mono text-[11px] tabular-nums" style="color: #f59e0b">
            {{ (Number(line.approved_qty) * Number(line.unit_price)).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) }} ₽
          </span>
        </div>
      </div>
    </div>

    <!-- Desktop table -->
    <div v-if="!compact" class="hidden overflow-x-auto p-2 sm:block">
      <DsTable :columns="columns" :rows="rows" density="compact" empty-text="Нет позиций">
        <template #name="{ row }">
          <span class="text-[12px] text-white">{{ row.name }}</span>
        </template>
        <template #approved_qty="{ row }">
          <input
            type="number"
            min="0"
            step="1"
            class="ds-input h-8 w-24 font-mono text-xs"
            style="border-radius: 4px; background: #090d16"
            :value="row.approved_qty"
            :disabled="draft.status === 'sent'"
            @input="onQty(row.product_id, $event)"
          >
        </template>
        <template #unit_price="{ value }">
          <span class="font-mono text-[11px] tabular-nums" style="color: #9ca3af">{{ Number(value).toFixed(2) }}</span>
        </template>
        <template #line_total="{ value }">
          <span class="font-mono text-[12px] tabular-nums text-white">{{ value }}</span>
        </template>
      </DsTable>
    </div>

    <div
      class="flex flex-col gap-2 border-t p-3 sm:flex-row sm:items-center sm:justify-between"
      style="border-color: #1e293b"
    >
      <div class="font-mono text-[13px] font-bold tabular-nums" style="color: #f59e0b">
        Итого: {{ total.toLocaleString('ru-RU', { maximumFractionDigits: 2 }) }} ₽
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          v-if="draft.status === 'draft'"
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] font-bold uppercase sm:h-9 sm:flex-none"
          style="border-color: #38bdf8; color: #090d16; background: #38bdf8; border-radius: 4px"
          :disabled="approving"
          @click="emit('approve', draft.id)"
        >
          {{ approving ? '…' : 'Утвердить' }}
        </button>
        <button
          v-if="draft.status !== 'sent'"
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] font-bold uppercase sm:h-9 sm:flex-none"
          style="border-color: #f59e0b; color: #f59e0b; background: transparent; border-radius: 4px"
          @click="emit('send', draft.id)"
        >
          Отправить поставщику
        </button>
      </div>
    </div>
  </div>
</template>
