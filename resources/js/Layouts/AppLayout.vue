<script setup lang="ts">
import { computed, ref } from 'vue'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import Dropdown from '@/Components/Dropdown.vue'

interface NavItem {
  label: string
  icon: string
  href?: string
  active?: boolean
  children?: { label: string; href: string }[]
}

const baseIconSetups: Record<string, string> = {
  orders: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
  workshop: 'M11.42 15.17l-5.1-3.03a1.72 1.72 0 0 1 0-3.22l5.1-3.03a1.72 1.72 0 0 1 2.14 0l5.1 3.03a1.72 1.72 0 0 1 0 3.22l-5.1 3.03a1.72 1.72 0 0 1-2.14 0zM4.5 10.5l8 4.5',
  warehouse: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
  clients: 'M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
  finance: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  reports: 'M9 19v-6a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-5a2 2 0 0 1-2-2zM9 19a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 19h6M9 12h6m-6 4h6',
  settings: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
}

withDefaults(
  defineProps<{
    title?: string
    currentShiftOpen?: boolean
    shiftStartedAt?: string | null
    tenants?: { id: number; name: string; slug: string }[]
    currentTenant?: { id: number; name: string }
  }>(),
  {
    title: 'Lastik',
    currentShiftOpen: false,
    shiftStartedAt: null,
    tenants: () => [],
    currentTenant: undefined,
  },
)

const sidebarOpen = ref(false)

const navigation: NavItem[] = [
  { label: 'Заказы', icon: baseIconSetups.orders, href: route('orders.index') },
  {
    label: 'Шиномонтаж',
    icon: baseIconSetups.workshop,
    href: '#',
    children: [
      { label: 'Наряды', href: route('workshop.orders.index') },
      { label: 'Планирование', href: route('workshop.schedule.index') },
      { label: 'Сотрудники', href: route('workshop.employees.index') },
    ],
  },
  { label: 'Склад', icon: baseIconSetups.warehouse, href: route('warehouse.index') },
  { label: 'Клиенты', icon: baseIconSetups.clients, href: route('clients.index') },
  { label: 'Финансы', icon: baseIconSetups.finance, href: route('finance.index') },
  { label: 'Отчёты', icon: baseIconSetups.reports, href: route('reports.index') },
  { label: 'Настройки', icon: baseIconSetups.settings, href: route('settings.index') },
]

const selectedTenantId = computed({
  get: () => props.currentTenant?.id ?? '',
  set: (value: number | '') => {
    if (!value) return
    router.post(route('tenant.switch'), { tenant_id: value }, {
      preserveScroll: true,
      preserveState: false,
    })
  },
})

const shiftDurationText = computed(() => {
  if (!props.currentShiftOpen || !props.shiftStartedAt) return '—'
  const start = new Date(props.shiftStartedAt).getTime()
  const now = Date.now()
  const diffMs = now - start
  const hours = Math.floor(diffMs / (1000 * 60 * 60))
  const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60))
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
})
</script>

<template>
  <div class="min-h-screen bg-background text-foreground transition-colors duration-200">
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      @click="sidebarOpen = false"
    />

    <aside
      :class="[
        'fixed inset-y-0 left-0 z-50 w-64 border-r border-border bg-sidebar transition-transform duration-200 ease-in-out lg:translate-x-0',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full',
      ]"
    >
      <div class="flex h-16 items-center justify-between gap-3 border-b border-border px-4">
        <a :href="route('dashboard')" class="flex items-center gap-2">
          <ApplicationLogo class="h-8 w-8 shrink-0" />
          <span class="text-sm font-semibold tracking-tight">{{ title }}</span>
        </a>
        <button
          class="rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-accent-foreground lg:hidden"
          @click="sidebarOpen = false"
        >
          <span class="sr-only">Закрыть меню</span>
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <nav class="space-y-1 px-3 py-4">
        <template v-for="item in navigation" :key="item.label">
          <div v-if="item.children" class="space-y-1">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
            >
              <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              <span>{{ item.label }}</span>
              <svg class="ml-auto h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div class="ml-4 space-y-1 border-l border-border pl-3">
              <a
                v-for="child in item.children"
                :key="child.href"
                :href="child.href"
                class="block rounded-md px-3 py-1.5 text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
              >
                {{ child.label }}
              </a>
            </div>
          </div>

          <a
            v-else
            :href="item.href"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
          >
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
            </svg>
            <span>{{ item.label }}</span>
          </a>
        </template>
      </nav>

      <div class="absolute bottom-0 left-0 right-0 border-t border-border bg-sidebar/95 px-4 py-3">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span
              :class="[
                'inline-flex h-2 w-2 rounded-full',
                currentShiftOpen ? 'bg-green-500 animate-pulse' : 'bg-red-500',
              ]"
            />
            <div class="text-xs">
              <p class="font-medium">
                {{ currentShiftOpen ? 'Касса открыта' : 'Касса закрыта' }}
              </p>
              <p v-if="currentShiftOpen" class="text-muted-foreground">
                Время: {{ shiftDurationText }}
              </p>
            </div>
          </div>
          <div class="flex gap-1">
            <a
              v-if="!currentShiftOpen"
              :href="route('pos.shift.open')"
              method="post"
              as="button"
              class="rounded-md bg-green-600 px-2 py-1 text-xs font-medium text-white hover:bg-green-700"
            >
              Открыть
            </a>
            <a
              v-else
              :href="route('pos.shift.close')"
              method="post"
              as="button"
              class="rounded-md bg-red-600 px-2 py-1 text-xs font-medium text-white hover:bg-red-700"
            >
              Закрыть
            </a>
          </div>
        </div>
      </div>
    </aside>

    <div class="lg:pl-64">
      <header class="sticky top-0 z-30 h-16 border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
        <div class="flex h-full items-center justify-between px-4 lg:px-8">
          <div class="flex items-center gap-3">
            <button
              class="rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-accent-foreground lg:hidden"
              @click="sidebarOpen = !sidebarOpen"
            >
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
            <Breadcrumbs />
          </div>

          <div class="flex items-center gap-2 lg:gap-4">
            <Dropdown v-if="tenants.length > 1">
              <template #trigger>
                <button class="flex items-center gap-2 rounded-md border border-border px-2.5 py-1.5 text-xs hover:bg-accent hover:text-accent-foreground">
                  <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5" />
                  </svg>
                  <span class="max-w-[100px] truncate">{{ currentTenant?.name ?? 'Tenant' }}</span>
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
              </template>
              <template #content>
                <div class="w-56">
                  <div class="border-b border-border px-3 py-2">
                    <p class="text-xs font-medium text-muted-foreground">Переключение филиала</p>
                  </div>
                  <div class="max-h-64 overflow-y-auto py-1">
                    <button
                      v-for="tenant in tenants"
                      :key="tenant.id"
                      :class="[
                        'w-full text-left px-3 py-2 text-sm transition-colors hover:bg-accent hover:text-accent-foreground',
                        currentTenant?.id === tenant.id ? 'bg-accent font-medium text-accent-foreground' : '',
                      ]"
                      @click="selectedTenantId = tenant.id"
                    >
                      {{ tenant.name }}
                    </button>
                  </div>
                </div>
              </template>
            </Dropdown>

            <Dropdown>
              <template #trigger>
                <button class="flex items-center gap-2 rounded-md hover:bg-accent hover:text-accent-foreground p-1 transition-colors">
                  <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                    {{ $page.props.auth.user.name?.charAt(0).toUpperCase() ?? 'U' }}
                  </div>
                  <span class="hidden text-sm lg:block">{{ $page.props.auth.user.name }}</span>
                  <svg class="h-4 w-4 hidden lg:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
              </template>
              <template #content>
                <div class="w-48">
                  <div class="border-b border-border px-3 py-2">
                    <p class="text-sm font-medium">{{ $page.props.auth.user.name }}</p>
                    <p class="text-xs text-muted-foreground">{{ $page.props.auth.user.email }}</p>
                  </div>
                  <div class="py-1">
                    <a :href="route('profile.edit')" class="block px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground">
                      Профиль
                    </a>
                    <a :href="route('logout')" method="post" as="button" class="block w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-accent hover:text-accent-foreground">
                      Выйти
                    </a>
                  </div>
                </div>
              </template>
            </Dropdown>
          </div>
        </div>
      </header>

      <main class="py-6">
        <slot />
      </main>
    </div>
  </div>
</template>
