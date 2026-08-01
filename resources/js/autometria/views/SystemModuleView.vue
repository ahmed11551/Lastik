<script setup>
/**
 * AUTOMETRIA ERP — System module screen (Industrial Precision)
 * Replaces migration stubs for secondary nav modules.
 */
import { computed } from 'vue'
import { DsBadge, DsTable } from '@/design-system'

const props = defineProps({
  view: { type: String, required: true },
  title: { type: String, required: true },
})

const emit = defineEmits(['navigate'])

const META = {
  customers: {
    code: 'CRM.Customers',
    blurb: 'Карточки контрагентов, телефоны, история заказов и импорт.',
    endpoints: ['GET /api/v1/customers', 'POST /api/v1/customers/import', 'POST /api/v1/customers/merge'],
  },
  vehicles: {
    code: 'CRM.Vehicles',
    blurb: 'Автомобили, VIN, госномера и привязка к клиентам.',
    endpoints: ['GET /api/v1/vehicles'],
  },
  tasks: {
    code: 'Ops.Tasks',
    blurb: 'Операционные задачи смены: назначение, завершение, отмена.',
    endpoints: ['GET /api/v1/tasks', 'POST /api/v1/tasks', 'POST /api/v1/tasks/{id}/complete'],
  },
  tenants: {
    code: 'Platform.Tenants',
    blurb: 'Организации, точки обслуживания и изоляция локаций.',
    endpoints: ['GET /api/v1/settings', 'GET /api/v1/dictionaries'],
  },
  modules: {
    code: 'Platform.Modules',
    blurb: 'Включение и отключение функциональных модулей тенанта.',
    endpoints: ['GET /api/v1/modules', 'POST /api/v1/modules/{slug}/enable'],
  },
  tv_display: {
    code: 'Ops.TVBoard',
    blurb: 'Публичный TV-экран очереди шиномонтажа.',
    endpoints: ['GET /api/v1/tv/board'],
  },
  settings: {
    code: 'Platform.Settings',
    blurb: 'Системные настройки тенанта и справочники.',
    endpoints: ['GET /api/v1/settings', 'PUT /api/v1/settings'],
  },
  new_order: {
    code: 'Sales.NewOrder',
    blurb: 'Мастер создания заказа. Используйте список заказов или кассу.',
    endpoints: ['POST /api/v1/orders', 'GET /api/v1/products', 'GET /api/v1/customers'],
  },
}

const meta = computed(() => META[props.view] || {
  code: `Module.${props.view}`,
  blurb: 'Системный модуль AUTOMETRIA ERP.',
  endpoints: [],
})

const rows = computed(() =>
  (meta.value.endpoints || []).map((ep, i) => ({
    id: i + 1,
    endpoint: ep,
    status: 'ready',
  })),
)

const columns = [
  { key: 'id', label: '#' },
  { key: 'endpoint', label: 'API endpoint' },
  { key: 'status', label: 'Статус', mono: false },
]

const quick = [
  { id: 'orders', label: 'Заказы' },
  { id: 'cashier', label: 'Касса' },
  { id: 'warehouse', label: 'Склад' },
  { id: 'audit', label: 'Аудит' },
]
</script>

<template>
  <div
    class="-m-4 space-y-4 p-4 lg:-m-6 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <div
      class="border p-4"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div
        class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]"
        style="color: #f59e0b"
      >
        System // {{ meta.code }}
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-sm font-medium text-white sm:text-base">
          {{ title }}
        </h2>
        <DsBadge
          status="open"
          label="Module ready"
          variant="open"
          dot
        />
      </div>
      <p class="mt-2 max-w-2xl text-xs font-medium" style="color: #9ca3af">
        {{ meta.blurb }}
      </p>
    </div>

    <div
      class="border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="mb-2 text-xs font-medium text-white">
        Быстрая навигация
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="n in quick"
          :key="n.id"
          type="button"
          class="border border-[#1F2937] px-2.5 py-1.5 font-mono text-[11px] text-white transition-colors hover:border-amber-500"
          style="background: #0b0d10; border-radius: 4px"
          @click="emit('navigate', { id: n.id })"
        >
          {{ n.label }}
        </button>
      </div>
    </div>

    <div
      class="border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="mb-2 flex items-center justify-between gap-2">
        <span class="text-xs font-medium text-white">Контракт API</span>
        <span class="font-mono text-[10px]" style="color: #6b7280">Laravel /api/v1</span>
      </div>
      <DsTable
        :columns="columns"
        :rows="rows"
        density="compact"
        sticky-header
        max-height="320px"
        empty-text="Нет описанных эндпоинтов"
      >
        <template #id="{ value }">
          <span class="font-mono text-[11px]" style="color: #6b7280">{{ value }}</span>
        </template>
        <template #endpoint="{ value }">
          <span class="font-mono text-[12px] text-white">{{ value }}</span>
        </template>
        <template #status>
          <DsBadge
            status="success"
            label="Mapped"
            variant="success"
            dot
          />
        </template>
      </DsTable>
    </div>
  </div>
</template>
