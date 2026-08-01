import { createApp, computed, ref, watch } from 'vue'
import '../../../css/app.css'
import AutometriaLayout from '../../Layouts/AutometriaLayout.vue'
import UsersManagement from '../pages/UsersManagement.vue'
import { DsTable, DsBadge } from '../index.js'

const page = ref((location.hash.replace('#', '') || 'users'))

watch(page, (p) => {
  const next = `#${p}`
  if (location.hash !== next) {
    location.hash = next
  }
})

window.addEventListener('hashchange', () => {
  page.value = location.hash.replace('#', '') || 'users'
})

document.addEventListener(
  'click',
  (e) => {
    const btn = e.target?.closest?.('button')
    if (!btn) return
    const text = btn.textContent?.replace(/\s+/g, ' ').trim()
    if (text === 'Пользователи и роли') {
      e.preventDefault()
      e.stopPropagation()
      page.value = 'users'
    } else if (text === 'Дашборд') {
      e.preventDefault()
      e.stopPropagation()
      page.value = 'dashboard'
    }
  },
  true,
)

const DemoApp = {
  components: { AutometriaLayout, UsersManagement, DsTable, DsBadge },
  setup() {
    const shiftOpen = ref(true)
    const shiftStartedAt = ref(new Date(Date.now() - 2.5 * 3_600_000).toISOString())
    const shiftRevenue = ref(184500)

    const activeNav = computed(() => (page.value === 'dashboard' ? 'dashboard' : 'users'))
    const title = computed(() =>
      page.value === 'dashboard' ? 'Дашборд' : 'Пользователи и роли',
    )

    const stockColumns = [
      { key: 'sku', label: 'Артикул' },
      { key: 'name', label: 'Название', mono: false },
      { key: 'qty', label: 'Доступно' },
      { key: 'price', label: 'Цена' },
      { key: 'status', label: 'Статус', mono: false },
    ]
    const stockRows = [
      { id: 1, sku: 'R18-205-55', name: 'Шина 205/55 R18', qty: 20, price: '₽4,500', status: 'active' },
      { id: 2, sku: 'R17-225-45', name: 'Шина 225/45 R17', qty: 1, price: '₽3,800', status: 'pending' },
      { id: 3, sku: 'DISK-18-5x112', name: 'Диск 18" 5×112', qty: 8, price: '₽7,200', status: 'active' },
    ]

    return {
      page,
      activeNav,
      title,
      shiftOpen,
      shiftStartedAt,
      shiftRevenue,
      stockColumns,
      stockRows,
    }
  },
  template: `
    <AutometriaLayout
      :title="title"
      :active-nav="activeNav"
      :current-shift-open="shiftOpen"
      :shift-started-at="shiftStartedAt"
      :shift-revenue="shiftRevenue"
      :breadcrumbs="page === 'users'
        ? [{ label: 'Система' }, { label: 'Пользователи и роли' }]
        : [{ label: 'Основное' }, { label: 'Дашборд' }]"
    >
      <template #header-meta>
        <span class="ds-badge ds-badge--warning">{{ page === 'users' ? 'User Management' : 'Live' }}</span>
      </template>

      <UsersManagement v-if="page === 'users'" />

      <div v-else>
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="ds-surface p-4">
            <div class="font-mono text-2xl font-semibold">₽1.2M</div>
            <div class="mt-1 text-[11px] uppercase tracking-[0.08em]" style="color:var(--color-text-secondary)">Оборот за день</div>
          </div>
          <div class="ds-surface p-4">
            <div class="font-mono text-2xl font-semibold">47</div>
            <div class="mt-1 text-[11px] uppercase tracking-[0.08em]" style="color:var(--color-text-secondary)">Открытых заказов</div>
          </div>
          <div class="ds-surface p-4">
            <div class="font-mono text-2xl font-semibold">1,284</div>
            <div class="mt-1 text-[11px] uppercase tracking-[0.08em]" style="color:var(--color-text-secondary)">Позиций на складе</div>
          </div>
          <div class="ds-surface p-4">
            <div class="font-mono text-2xl font-semibold" style="color:var(--color-danger)">12</div>
            <div class="mt-1 text-[11px] uppercase tracking-[0.08em]" style="color:var(--color-text-secondary)">Критичных остатков</div>
          </div>
        </div>
        <DsTable :columns="stockColumns" :rows="stockRows" density="compact" sticky-header>
          <template #status="{ value }">
            <DsBadge :status="value" dot />
          </template>
        </DsTable>
      </div>
    </AutometriaLayout>
  `,
}

createApp(DemoApp).mount('#app')
