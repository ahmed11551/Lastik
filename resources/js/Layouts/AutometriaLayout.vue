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

/** Ported from React Orbital Sidebar — Industrial Amber labels */
const sections = [
  {
    title: 'Системное меню',
    items: [
      { id: 'dashboard', label: 'Дашборд' },
      { id: 'crm', label: 'Business Partner CRM' },
      { id: 'orders', label: 'Заказы и продажи' },
      { id: 'new_order', label: 'Создать заказ', highlight: true },
      { id: 'customers', label: 'Покупатели & Импорт' },
      { id: 'vehicles', label: 'Автомобили' },
      { id: 'warehouse', label: 'Склад и остатки' },
      { id: 'cashier', label: 'Касса и смены' },
      { id: 'kpi', label: 'Выработка & KPI' },
      { id: 'tasks', label: 'Задачи' },
      { id: 'audit', label: 'Журнал действий' },
      { id: 'users', label: 'Пользователи & Устройства' },
      { id: 'tenants', label: 'Организации & Точки' },
      { id: 'modules', label: 'Модули' },
      { id: 'tv_display', label: 'TV-Экран очереди' },
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
</script>

<template>
  <div
    class="flex min-h-screen overflow-hidden"
    style="background: var(--autometria-bg, #0B0D10); color: var(--color-text-primary); font-family: var(--font-ui)"
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
        class="sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b px-4 lg:px-6"
        style="
          border-color: var(--color-border);
          background: color-mix(in srgb, var(--color-surface) 88%, transparent);
          backdrop-filter: blur(10px);
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
              class="mb-0.5 flex flex-wrap items-center gap-1 text-[11px]"
              style="color: var(--color-text-secondary)"
            >
              <template
                v-for="(crumb, i) in breadcrumbs"
                :key="crumb.href || crumb.label"
              >
                <span v-if="i > 0">/</span>
                <span>{{ crumb.label }}</span>
              </template>
            </nav>
            <h1 class="truncate text-sm font-semibold">
              {{ title }}
            </h1>
          </div>
          <slot name="header-meta" />
        </div>

        <div class="flex shrink-0 items-center gap-2">
          <DsShiftWidget
            :open="currentShiftOpen"
            :started-at="shiftStartedAt"
            :revenue="shiftRevenue"
            @open-shift="emit('open-shift')"
            @close-shift="emit('close-shift')"
          />
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm"
            :title="`Тема: ${themeLabel}`"
            @click="onToggleTheme"
          >
            {{ themeLabel }}
          </button>
          <button
            type="button"
            class="ds-btn ds-btn-primary ds-btn-sm font-mono"
            title="Command palette (⌘K)"
            @click="paletteOpen = true"
          >
            ⌘K
          </button>
        </div>
      </header>

      <main class="min-h-0 flex-1 overflow-y-auto p-4 lg:p-6" style="background: var(--autometria-bg, #0b0d10)">
        <slot />
      </main>
    </div>

    <DsCommandPalette
      v-model="paletteOpen"
      :items="defaultCommands"
    />
  </div>
</template>
