<script setup>
/**
 * AUTOMETRIA ERP shell — Industrial Amber / High-Density
 * Sidebar 260px · radius 4px · ⌘K · shift · SPA navigation
 */
import { computed, onMounted, ref, watch } from 'vue'
import {
  DsCommandPalette,
  DsSidebar,
  DsShiftWidget,
  useTheme,
} from '@/design-system'
import NlpSearchBar from '@/autometria/components/ai/NlpSearchBar.vue'

const props = defineProps({
  title: { type: String, default: 'Дашборд' },
  breadcrumbs: { type: Array, default: () => [] },
  activeNav: { type: String, default: 'dashboard' },
  currentShiftOpen: { type: Boolean, default: false },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: null },
  commandItems: { type: Array, default: () => [] },
  spaMode: { type: Boolean, default: false },
})

const emit = defineEmits(['navigate', 'open-shift', 'close-shift'])

const { set: setTheme, toggle: toggleTheme } = useTheme()
const paletteOpen = ref(false)
const mobileNav = ref(false)
const themeLabel = ref('Dark')
const nlpSearchRef = ref(null)

onMounted(() => {
  document.documentElement.setAttribute('data-theme', 'dark')
  syncThemeLabel()
})

function syncThemeLabel() {
  const t = document.documentElement.getAttribute('data-theme') || 'dark'
  themeLabel.value = t === 'light' ? 'Light' : 'Dark'
}

function onToggleTheme() {
  toggleTheme()
  if (!document.documentElement.getAttribute('data-theme')) {
    setTheme('dark')
  }
  syncThemeLabel()
}

/** Grouped nav — Industrial Amber */
const sections = [
  {
    id: 'overview',
    title: 'Обзор',
    items: [
      { id: 'dashboard', label: 'Дашборд' },
      { id: 'tasks', label: 'Задачи' },
      { id: 'kpi', label: 'Выработка & KPI' },
    ],
  },
  {
    id: 'analytics-ai',
    title: 'Аналитика & AI',
    items: [
      { id: 'analytics', label: 'Финансовый дашборд' },
      { id: 'abc_xyz', label: 'ABC/XYZ матрица', highlight: true },
      { id: 'demand_forecast', label: 'Прогноз спроса', highlight: true },
    ],
  },
  {
    id: 'sales',
    title: 'Продажи',
    items: [
      { id: 'orders', label: 'Заказы и продажи' },
      { id: 'new_order', label: 'Создать заказ', highlight: true },
      { id: 'crm', label: 'Business Partner CRM' },
      { id: 'customers', label: 'Покупатели & Импорт' },
      { id: 'vehicles', label: 'Автомобили' },
      { id: 'cashier', label: 'Касса и смены' },
      { id: 'pos', label: 'POS Терминал', highlight: true },
      { id: 'tv_display', label: 'TV-Экран очереди' },
    ],
  },
  {
    id: 'warehouse',
    title: 'Склад',
    items: [
      { id: 'warehouse', label: 'Склад и остатки' },
      { id: 'inventory', label: 'Инвентаризация' },
      { id: 'warehouse_prices', label: 'Цены по складам' },
      { id: 'purchases', label: 'Закупки' },
      { id: 'auto_orders', label: 'Авто-заказы', highlight: true },
      { id: 'replenishment', label: 'План пополнения' },
      { id: 'production', label: 'Производство / BOM' },
      { id: 'nested_bom', label: 'Nested BOM' },
      { id: 'wms_cells', label: 'Склад хранения' },
      { id: 'wms_serials', label: 'WMS · Серийники' },
    ],
  },
  {
    id: 'regulatory',
    title: 'Регуляторика',
    items: [
      { id: 'regulatory', label: 'Маркировка (ЧЗ)' },
      { id: 'egais', label: 'ЕГАИС' },
      { id: 'integrations', label: 'Интеграции 1С' },
    ],
  },
  {
    id: 'system',
    title: 'Система',
    items: [
      { id: 'branches', label: 'Филиалы' },
      { id: 'users', label: 'Пользователи & Устройства' },
      { id: 'tenants', label: 'Организации & Точки' },
      { id: 'modules', label: 'Модули' },
      { id: 'payroll', label: 'Зарплата' },
      { id: 'payroll_rules', label: 'Правила зарплаты' },
      { id: 'audit', label: 'Журнал действий' },
    ],
  },
]

const flatItems = computed(() => sections.flatMap((s) => s.items))

watch(
  () => props.activeNav,
  () => {
    mobileNav.value = false
  },
)

function go(item) {
  emit('navigate', item)
  if (props.spaMode) {
    mobileNav.value = false
    return
  }
  const href = item.href || `/#/${item.id}`
  if (href.startsWith('/#') || href.startsWith('#')) {
    location.hash = href.replace(/^\//, '')
  } else if (item.href) {
    window.location.href = item.href
  }
}

const defaultCommands = computed(() => [
  ...flatItems.value.map((item) => ({
    id: `nav-${item.id}`,
    type: 'Навигация',
    label: item.label,
    hint: item.id,
    keywords: [item.id, item.label],
    action: () => go(item),
  })),
  {
    id: 'vin-demo',
    type: 'VIN',
    label: 'XTA219000Y0123456',
    hint: 'Демо VIN',
    keywords: ['vin', 'xta219000y0123456'],
  },
  {
    id: 'sku-demo',
    type: 'Артикул',
    label: 'R18-205-55',
    hint: 'Шина 205/55 R18',
    keywords: ['артикул', 'r18-205-55', 'sku'],
  },
  ...props.commandItems,
])

function openQuickSearch() {
  paletteOpen.value = true
}

function onNlpNavigate(item) {
  go(typeof item === 'string' ? { id: item } : item)
}
</script>

<template>
  <div
    class="flex min-h-screen overflow-hidden scroll-lock-outer"
    style="background: var(--autometria-bg, #090d16); color: var(--color-text-primary); font-family: var(--font-ui)"
  >
    <div
      v-if="mobileNav"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      @click="mobileNav = false"
    />

    <div
      :class="[
        'fixed inset-y-0 left-0 z-50 transition-transform lg:static lg:translate-x-0',
        mobileNav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
      ]"
    >
      <DsSidebar
        :sections="sections"
        :items="flatItems"
        :active="activeNav"
        footer="AUTOMETRIA · ONLINE"
        @select="go"
      />
    </div>

    <div class="flex min-w-0 flex-1 flex-col">
      <header
        class="safe-header sticky top-0 z-30 flex min-h-14 items-center justify-between gap-3 border-b px-4 lg:px-6"
        style="
          border-color: var(--color-border);
          background: color-mix(in srgb, var(--ds-header-bg, var(--brand-navy, #0d1b3d)) 92%, transparent);
          backdrop-filter: blur(10px);
          top: var(--offline-banner-h, 0px);
        "
      >
        <div class="flex min-w-0 items-center gap-3">
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm lg:hidden"
            @click="mobileNav = !mobileNav"
          >
            Меню
          </button>
          <div class="min-w-0">
            <nav
              v-if="breadcrumbs.length"
              class="mb-0.5 flex flex-wrap items-center gap-1 text-[12px] font-normal"
              style="color: var(--color-text-secondary)"
              aria-label="Breadcrumb"
            >
              <template
                v-for="(crumb, i) in breadcrumbs"
                :key="crumb.href || crumb.label"
              >
                <span v-if="i > 0" style="opacity: 0.55">/</span>
                <span>{{ crumb.label }}</span>
              </template>
            </nav>
            <h1 class="truncate text-[15px] font-semibold tracking-[-0.01em]" style="color: var(--color-text-primary)">
              {{ title }}
            </h1>
          </div>
          <slot name="header-meta" />
        </div>

        <div class="flex min-w-0 flex-1 items-center justify-end gap-2">
          <NlpSearchBar
            ref="nlpSearchRef"
            class="max-w-xl"
            @navigate="onNlpNavigate"
          />
          <DsShiftWidget
            :open="currentShiftOpen"
            :started-at="shiftStartedAt"
            :revenue="shiftRevenue"
            @open-shift="emit('open-shift')"
            @close-shift="emit('close-shift')"
          />
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm hidden text-[13px] font-normal sm:inline-flex"
            :title="`Тема: ${themeLabel}`"
            @click="onToggleTheme"
          >
            {{ themeLabel }}
          </button>
          <button
            type="button"
            class="ds-btn ds-btn-primary ds-btn-sm font-mono"
            title="Command palette (⌘K)"
            @click="openQuickSearch"
          >
            ⌘K
          </button>
        </div>
      </header>

      <main
        class="scroll-y-contain min-h-0 flex-1 overflow-x-hidden overflow-y-auto p-3 pb-[max(0.75rem,var(--safe-bottom))] sm:p-4 lg:p-6"
        style="background: var(--brand-desk, var(--autometria-bg, #090d16))"
      >
        <slot />
      </main>
    </div>

    <DsCommandPalette
      v-model="paletteOpen"
      :items="defaultCommands"
    />
  </div>
</template>
