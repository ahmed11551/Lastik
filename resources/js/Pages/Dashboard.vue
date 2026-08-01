<script setup>
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'

defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: {
    type: [String, Number, Date],
    default: () => new Date(Date.now() - 3_600_000 * 2.5).toISOString(),
  },
  shiftRevenue: { type: [Number, String], default: 184500 },
})

const density = ref('compact')

const columns = [
  { key: 'sku', label: 'Артикул' },
  { key: 'name', label: 'Название', mono: false },
  { key: 'warehouse', label: 'Склад', mono: false },
  { key: 'qty', label: 'Доступно' },
  { key: 'price', label: 'Цена' },
  { key: 'status', label: 'Статус', mono: false },
]

const rows = [
  {
    id: 1,
    sku: 'R18-205-55',
    name: 'Шина 205/55 R18',
    warehouse: 'Основной',
    qty: 20,
    price: '₽4,500',
    status: 'OK',
  },
  {
    id: 2,
    sku: 'R17-225-45',
    name: 'Шина 225/45 R17',
    warehouse: 'Основной',
    qty: 1,
    price: '₽3,800',
    status: 'LOW',
  },
  {
    id: 3,
    sku: 'DISK-18-5x112',
    name: 'Диск 18" 5×112',
    warehouse: 'Основной',
    qty: 8,
    price: '₽7,200',
    status: 'OK',
  },
]
</script>

<template>
  <Head title="Дашборд" />

  <AutometriaLayout
    title="Дашборд"
    active-nav="dashboard"
    :current-shift-open="currentShiftOpen"
    :shift-started-at="shiftStartedAt"
    :shift-revenue="shiftRevenue"
    :breadcrumbs="[{ label: 'Основное' }, { label: 'Дашборд' }]"
  >
    <template #header-meta>
      <span class="ds-badge ds-badge--success">● Live</span>
    </template>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div class="ds-surface p-4">
        <div class="font-mono text-2xl font-semibold">₽1.2M</div>
        <div
          class="mt-1 text-[11px] uppercase tracking-[0.08em]"
          style="color: var(--color-text-secondary)"
        >
          Оборот за день
        </div>
      </div>
      <div class="ds-surface p-4">
        <div class="font-mono text-2xl font-semibold">47</div>
        <div
          class="mt-1 text-[11px] uppercase tracking-[0.08em]"
          style="color: var(--color-text-secondary)"
        >
          Открытых заказов
        </div>
      </div>
      <div class="ds-surface p-4">
        <div class="font-mono text-2xl font-semibold">1,284</div>
        <div
          class="mt-1 text-[11px] uppercase tracking-[0.08em]"
          style="color: var(--color-text-secondary)"
        >
          Позиций на складе
        </div>
      </div>
      <div class="ds-surface p-4">
        <div
          class="font-mono text-2xl font-semibold"
          style="color: var(--color-danger)"
        >
          12
        </div>
        <div
          class="mt-1 text-[11px] uppercase tracking-[0.08em]"
          style="color: var(--color-text-secondary)"
        >
          Критичных остатков
        </div>
      </div>
    </div>

    <div class="mb-3 flex items-center justify-between gap-3">
      <h2 class="text-sm font-semibold">Остатки склада</h2>
      <div class="flex gap-1">
        <button
          type="button"
          class="ds-btn ds-btn-sm"
          :class="density === 'compact' ? 'ds-btn-primary' : 'ds-btn-ghost'"
          @click="density = 'compact'"
        >
          Compact 32
        </button>
        <button
          type="button"
          class="ds-btn ds-btn-sm"
          :class="density === 'comfortable' ? 'ds-btn-primary' : 'ds-btn-ghost'"
          @click="density = 'comfortable'"
        >
          Comfortable 48
        </button>
      </div>
    </div>

    <DsTable
      :columns="columns"
      :rows="rows"
      :density="density"
      sticky-header
    >
      <template #status="{ value }">
        <span
          class="ds-badge"
          :class="value === 'OK' ? 'ds-badge--success' : 'ds-badge--warning'"
        >
          {{ value }}
        </span>
      </template>
    </DsTable>
  </AutometriaLayout>
</template>
