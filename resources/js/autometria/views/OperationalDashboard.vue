<script setup>
/**
 * Operational Dashboard — ported from React DashboardView → Industrial Amber
 */
import { computed } from 'vue'
import { DsBadge, DsTable } from '@/design-system'

const props = defineProps({
  shiftOpen: { type: Boolean, default: false },
  shiftRevenue: { type: [Number, String], default: 0 },
  locationName: { type: String, default: 'Все точки' },
})

const emit = defineEmits(['navigate', 'create-order'])

const kpis = computed(() => [
  {
    id: 'revenue',
    label: 'Выручка за день',
    value: '0 ₽',
    hint: 'По оплаченным заказам',
    tone: 'success',
  },
  {
    id: 'orders',
    label: 'Активные заказы',
    value: '0',
    hint: 'В работе и на шиномонтаже',
    tone: 'primary',
  },
  {
    id: 'reserve',
    label: 'Товар в резерве',
    value: '0 шт',
    hint: 'Закреплено за заказами',
    tone: 'neutral',
  },
  {
    id: 'cash',
    label: 'Текущая касса',
    value: `${Number(props.shiftRevenue || 0).toLocaleString('ru-RU')} ₽`,
    hint: props.shiftOpen ? 'Смена открыта' : 'Смена закрыта',
    tone: 'warning',
  },
])

const orderColumns = [
  { key: 'number', label: 'Заказ' },
  { key: 'vin', label: 'VIN / Госномер' },
  { key: 'sku', label: 'Артикул' },
  { key: 'status', label: 'Статус', mono: false },
  { key: 'amount', label: 'Сумма' },
]

const orderRows = [
  {
    id: 1,
    number: 'ORD-1042',
    vin: 'A123BC777',
    sku: 'R18-205-55',
    status: 'pending',
    amount: '₽12,400',
  },
  {
    id: 2,
    number: 'ORD-1041',
    vin: 'XTA219000Y0123456',
    sku: 'DISK-18-5x112',
    status: 'active',
    amount: '₽7,200',
  },
  {
    id: 3,
    number: 'ORD-1039',
    vin: 'K456MP199',
    sku: 'R17-225-45',
    status: 'open',
    amount: '₽3,800',
  },
]
</script>

<template>
  <div class="space-y-4">
    <div class="ds-surface p-5">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <div
            class="mb-1.5 flex items-center gap-2 font-mono text-[11px] font-semibold uppercase tracking-[0.12em]"
            style="color: var(--color-primary)"
          >
            <span
              class="inline-block h-2 w-2 animate-pulse"
              style="background: var(--color-primary); border-radius: 4px; box-shadow: 0 0 8px var(--color-primary)"
            />
            Операционный центр // Command Deck
          </div>
          <h2 class="text-lg font-semibold" style="color: var(--color-text-primary)">
            Сводка по локации:
            <span class="font-mono" style="color: var(--color-primary)">{{ locationName }}</span>
          </h2>
          <p class="mt-1 text-xs" style="color: var(--color-text-secondary)">
            Ядро AUTOMETRIA ERP • Кассовая смена и 1С-склад
          </p>
        </div>
        <button
          type="button"
          class="ds-btn ds-btn-primary font-mono text-xs"
          @click="emit('create-order')"
        >
          + Новый заказ покупателя
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div
        v-for="kpi in kpis"
        :key="kpi.id"
        class="ds-surface p-4 transition-colors"
        style="border-radius: 4px"
      >
        <div
          class="mb-2 font-mono text-[10px] uppercase tracking-[0.1em]"
          style="color: var(--color-text-secondary)"
        >
          {{ kpi.label }}
        </div>
        <div class="font-mono text-2xl font-semibold tabular-nums" style="color: var(--color-text-primary)">
          {{ kpi.value }}
        </div>
        <div
          class="mt-2 font-mono text-[11px]"
          :style="{
            color:
              kpi.tone === 'success'
                ? 'var(--color-success)'
                : kpi.tone === 'warning'
                  ? 'var(--color-warning)'
                  : kpi.tone === 'primary'
                    ? 'var(--color-primary)'
                    : 'var(--color-text-secondary)',
          }"
        >
          {{ kpi.hint }}
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="space-y-3 lg:col-span-2">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold">Текущие заказы · шиномонтаж / выдача</h3>
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm"
            @click="emit('navigate', { id: 'orders' })"
          >
            Все заказы
          </button>
        </div>
        <DsTable
          :columns="orderColumns"
          :rows="orderRows"
          density="compact"
          sticky-header
        >
          <template #status="{ value }">
            <DsBadge
              :status="value"
              dot
            />
          </template>
        </DsTable>
      </div>

      <div class="ds-surface p-4">
        <h3 class="mb-3 text-sm font-semibold">Задачи</h3>
        <div class="font-mono text-3xl font-semibold tabular-nums">0</div>
        <p class="mt-2 text-xs" style="color: var(--color-success)">
          Срочных задач нет
        </p>
        <button
          type="button"
          class="ds-btn ds-btn-ghost ds-btn-sm mt-4 w-full"
          @click="emit('navigate', { id: 'tasks' })"
        >
          Открыть задачи
        </button>
        <button
          type="button"
          class="ds-btn ds-btn-ghost ds-btn-sm mt-2 w-full"
          @click="emit('navigate', { id: 'stock' })"
        >
          Склад &amp; 1С
        </button>
      </div>
    </div>
  </div>
</template>
