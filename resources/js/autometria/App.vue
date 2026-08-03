<script setup>
/**
 * AUTOMETRIA ERP — primary SPA shell
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import OperationalDashboard from '@/autometria/views/OperationalDashboard.vue'
import BusinessPartnerCrm from '@/autometria/views/BusinessPartnerCrm.vue'
import KpiView from '@/autometria/views/KpiView.vue'
import WarehouseView from '@/autometria/views/WarehouseView.vue'
import CashierView from '@/autometria/views/CashierView.vue'
import OrdersView from '@/autometria/views/OrdersView.vue'
import AuditView from '@/autometria/views/AuditView.vue'
import LoginView from '@/autometria/views/LoginView.vue'
import SystemModuleView from '@/autometria/views/SystemModuleView.vue'
import PosView from '@/views/pos/PosView.vue'
import TvBoardIndex from '@/Pages/TvBoard/Index.vue'
import IntegrationsIndex from '@/Pages/Settings/Integrations/Index.vue'
import InventoryIndex from '@/Pages/Inventory/Index.vue'
import InventoryDocumentForm from '@/Pages/Inventory/DocumentForm.vue'
import RegulatoryMarkingIndex from '@/Pages/Regulatory/Marking/Index.vue'
import RegulatoryEgaisIndex from '@/Pages/Regulatory/Egais/Index.vue'
import BranchesIndex from '@/Pages/Settings/Branches/Index.vue'
import WarehousePrices from '@/Pages/Inventory/WarehousePrices.vue'
import ProductionIndex from '@/Pages/Production/Index.vue'
import AnalyticsDashboard from '@/Pages/Dashboard.vue'
import PurchasesIndex from '@/Pages/Purchases/Index.vue'
import SupplierOrderForm from '@/Pages/Purchases/SupplierOrderForm.vue'
import ReplenishmentPlan from '@/Pages/Purchases/ReplenishmentPlan.vue'
import PayrollPeriodsIndex from '@/Pages/Payroll/PeriodsIndex.vue'
import PayslipView from '@/Pages/Payroll/PayslipView.vue'
import PayrollRules from '@/Pages/Payroll/Rules.vue'
import UsersManagement from '@/design-system/pages/UsersManagement.vue'
import DsToastHost from '@/autometria/components/DsToastHost.vue'
import { getToken } from '@/autometria/api/client'
import { useShiftStore } from '@/autometria/stores/cashierStore'

const VIEW_TITLES = {
  login: 'Вход',
  dashboard: 'Дашборд',
  analytics: 'Аналитика',
  crm: 'Business Partner CRM',
  orders: 'Заказы и продажи',
  new_order: 'Создать заказ',
  customers: 'Покупатели & Импорт',
  vehicles: 'Автомобили',
  warehouse: 'Склад и остатки',
  stock: 'Склад и остатки',
  inventory: 'Инвентаризация',
  inventory_create: 'Складской документ',
  warehouse_prices: 'Цены по складам',
  purchases: 'Закупки',
  purchase_form: 'Заказ поставщику',
  replenishment: 'План пополнения',
  payroll: 'Зарплата',
  payroll_rules: 'Правила зарплаты',
  payslip: 'Расчётный лист',
  production: 'Производство / BOM',
  branches: 'Филиалы',
  regulatory: 'Маркировка (Честный Знак)',
  egais: 'ЕГАИС',
  onec: 'Синхронизация 1С',
  '1c': 'Синхронизация 1С',
  integrations: 'Интеграции 1С / CommerceML',
  cashier: 'Касса и смены',
  shifts: 'Касса и смены',
  pos: 'POS Терминал',
  kpi: 'Выработка & KPI',
  tasks: 'Задачи',
  audit: 'Журнал действий',
  users: 'Пользователи & Устройства',
  tenants: 'Организации & Точки',
  modules: 'Модули',
  tv_display: 'TV-Экран очереди',
  settings: 'Настройки',
}

function normalizeView(raw) {
  if (raw === 'stock') return 'warehouse'
  if (raw === 'shifts') return 'cashier'
  if (raw === '1c' || raw === 'onec') return 'integrations'
  if (String(raw).startsWith('purchase_form')) return 'purchase_form'
  if (String(raw).startsWith('payslip:')) return 'payslip'
  return VIEW_TITLES[raw] ? raw : 'dashboard'
}

function readHash() {
  const raw = location.hash.replace(/^#\/?/, '') || 'dashboard'
  return normalizeView(raw)
}

function purchaseOrderIdFromHash() {
  const raw = location.hash.replace(/^#\/?/, '')
  const m = raw.match(/^purchase_form:(\d+)/)
  return m ? Number(m[1]) : null
}

function payslipIdFromHash() {
  const m = location.hash.replace(/^#\/?/, '').match(/^payslip:(\d+)/)
  return m ? Number(m[1]) : null
}

const view = ref(readHash())
const purchaseOrderId = ref(purchaseOrderIdFromHash())
const payslipId = ref(payslipIdFromHash())
const shiftStore = useShiftStore()
const {
  open: shiftOpen,
  startedAt: shiftStartedAt,
  revenue: shiftRevenue,
} = storeToRefs(shiftStore)

const title = computed(() => VIEW_TITLES[view.value] || 'AUTOMETRIA')
const breadcrumbs = computed(() => {
  if (view.value === 'warehouse') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Склад' }, { label: 'Остатки' }]
  }
  if (view.value === 'inventory' || view.value === 'inventory_create') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Склад' }, { label: 'Документы' }]
  }
  if (view.value === 'warehouse_prices') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Склад' }, { label: 'Цены' }]
  }
  if (view.value === 'production') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Склад' }, { label: 'Производство' }]
  }
  if (view.value === 'branches') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Настройки' }, { label: 'Филиалы' }]
  }
  if (view.value === 'integrations') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Интеграции' }, { label: '1С CommerceML' }]
  }
  if (view.value === 'cashier') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Касса' }, { label: 'Смены' }]
  }
  if (view.value === 'pos') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Касса' }, { label: 'POS Offline' }]
  }
  if (view.value === 'orders' || view.value === 'new_order') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Продажи' }, { label: 'Заказы' }]
  }
  if (view.value === 'audit') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Система' }, { label: 'Аудит' }]
  }
  if (view.value === 'kpi') {
    return [{ label: 'AUTOMETRIA' }, { label: 'Выработка' }, { label: 'KPI' }]
  }
  if (view.value === 'crm') {
    return [{ label: 'Dashboard' }, { label: 'Data' }, { label: 'Business Partner CRM' }]
  }
  return [{ label: 'AUTOMETRIA ERP' }, { label: title.value }]
})

watch(view, (v) => {
  if (v === 'purchase_form' && purchaseOrderId.value) {
    const next = `#/purchase_form:${purchaseOrderId.value}`
    if (location.hash !== next) location.hash = next
    return
  }
  if (v === 'payslip' && payslipId.value) {
    const next = `#/payslip:${payslipId.value}`
    if (location.hash !== next) location.hash = next
    return
  }
  const next = `#/${v}`
  if (v !== 'purchase_form' && location.hash !== next) location.hash = next
  if (v === 'purchase_form' && !purchaseOrderId.value && location.hash !== next) {
    location.hash = next
  }
})

function onHashChange() {
  view.value = readHash()
  purchaseOrderId.value = purchaseOrderIdFromHash()
  payslipId.value = payslipIdFromHash()
}

onMounted(() => {
  window.addEventListener('hashchange', onHashChange)
  document.documentElement.setAttribute('data-theme', 'dark')
  if (!location.hash || location.hash === '#/stock') location.hash = '#/dashboard'
  if (location.hash === '#/shifts') location.hash = '#/cashier'
  if (getToken()) {
    shiftStore.fetchCurrent().catch(() => {})
  }
})

onUnmounted(() => {
  window.removeEventListener('hashchange', onHashChange)
})

function onNavigate(item) {
  const raw = typeof item === 'string' ? item : item?.id
  if (!raw) return
  const id = normalizeView(String(raw))
  if (String(raw).startsWith('purchase_form:')) {
    purchaseOrderId.value = purchaseOrderIdFromHash() || Number(String(raw).split(':')[1]) || null
    view.value = 'purchase_form'
    location.hash = `#/${raw}`
    return
  }
  if (String(raw).startsWith('payslip:')) {
    payslipId.value = payslipIdFromHash() || Number(String(raw).split(':')[1]) || null
    view.value = 'payslip'
    location.hash = `#/${raw}`
    return
  }
  if (id === 'purchase_form') {
    purchaseOrderId.value = null
  }
  if (VIEW_TITLES[id]) {
    view.value = id
    location.hash = `#/${id}`
  }
}

function onOpenShift() {
  shiftStore.openShift(0).catch(() => {})
}

function onCloseShift() {
  shiftStore.closeShift().catch(() => {})
}

function onCashierPay() {
  shiftStore.fetchCurrent().catch(() => {})
}

function openPalette() {
  window.dispatchEvent(new CustomEvent('command-palette:open'))
}

function onLoginSuccess() {
  view.value = 'dashboard'
  shiftStore.fetchCurrent().catch(() => {})
}
</script>

<template>
  <div>
    <LoginView
      v-if="view === 'login'"
      @success="onLoginSuccess"
    />

    <TvBoardIndex
      v-else-if="view === 'tv_display'"
      kiosk
    />

    <AutometriaLayout
      v-else
      spa-mode
      :title="title"
      :active-nav="view"
      :breadcrumbs="breadcrumbs"
      :current-shift-open="shiftOpen"
      :shift-started-at="shiftStartedAt"
      :shift-revenue="shiftRevenue"
      @navigate="onNavigate"
      @open-shift="onOpenShift"
      @close-shift="onCloseShift"
    >
      <template #header-meta>
        <span
          v-if="!getToken()"
          class="ds-badge ds-badge--danger"
        >No token</span>
        <span
          v-else-if="view === 'audit'"
          class="ds-badge ds-badge--warning"
        >Audit</span>
        <span
          v-else-if="view === 'warehouse'"
          class="ds-badge ds-badge--warning"
        >Warehouse</span>
        <span
          v-else-if="view === 'inventory' || view === 'inventory_create'"
          class="ds-badge ds-badge--warning"
        >Inventory</span>
        <span
          v-else-if="view === 'integrations'"
          class="ds-badge ds-badge--warning"
        >1C Sync</span>
        <span
          v-else-if="view === 'cashier'"
          class="ds-badge ds-badge--warning"
        >Cashier</span>
        <span
          v-else-if="view === 'pos'"
          class="ds-badge ds-badge--warning"
        >POS Offline</span>
        <span
          v-else-if="view === 'orders' || view === 'new_order'"
          class="ds-badge ds-badge--warning"
        >Orders</span>
        <span
          v-else-if="view === 'kpi'"
          class="ds-badge ds-badge--warning"
        >KPI</span>
        <span
          v-else
          class="ds-badge ds-badge--warning"
        >Industrial Amber</span>
      </template>

      <OperationalDashboard
        v-if="view === 'dashboard'"
        :shift-open="shiftOpen"
        :shift-revenue="shiftRevenue"
        @navigate="onNavigate"
        @create-order="view = 'new_order'"
      />

      <AnalyticsDashboard
        v-else-if="view === 'analytics'"
        embedded
      />

      <WarehouseView v-else-if="view === 'warehouse'" />

      <InventoryIndex
        v-else-if="view === 'inventory'"
        embedded
        @navigate="onNavigate"
      />

      <InventoryDocumentForm
        v-else-if="view === 'inventory_create'"
        embedded
        @navigate="onNavigate"
      />

      <WarehousePrices
        v-else-if="view === 'warehouse_prices'"
        embedded
      />

      <PurchasesIndex
        v-else-if="view === 'purchases'"
        embedded
        @navigate="onNavigate"
      />

      <SupplierOrderForm
        v-else-if="view === 'purchase_form'"
        embedded
        :order-id="purchaseOrderId"
        @navigate="onNavigate"
      />

      <ReplenishmentPlan
        v-else-if="view === 'replenishment'"
        embedded
        @navigate="onNavigate"
      />

      <PayrollPeriodsIndex
        v-else-if="view === 'payroll'"
        @navigate="onNavigate"
      />

      <PayrollRules v-else-if="view === 'payroll_rules'" />

      <PayslipView
        v-else-if="view === 'payslip'"
        :payslip-id="payslipId"
      />

      <ProductionIndex
        v-else-if="view === 'production'"
        embedded
      />

      <BranchesIndex
        v-else-if="view === 'branches'"
        embedded
      />

      <RegulatoryMarkingIndex
        v-else-if="view === 'regulatory'"
        embedded
      />

      <RegulatoryEgaisIndex
        v-else-if="view === 'egais'"
        embedded
      />

      <IntegrationsIndex
        v-else-if="view === 'integrations'"
        embedded
      />

      <OrdersView
        v-else-if="view === 'orders' || view === 'new_order'"
        @create-order="view = 'orders'"
      />

      <CashierView
        v-else-if="view === 'cashier'"
        @navigate="onNavigate"
        @pay="onCashierPay"
      />

      <PosView v-else-if="view === 'pos'" />

      <KpiView
        v-else-if="view === 'kpi'"
        :shift-open="shiftOpen"
      />

      <AuditView v-else-if="view === 'audit'" />

      <BusinessPartnerCrm
        v-else-if="view === 'crm'"
        @open-palette="openPalette"
      />

      <UsersManagement v-else-if="view === 'users'" />

      <SystemModuleView
        v-else
        :view="view"
        :title="title"
        @navigate="onNavigate"
      />
    </AutometriaLayout>

    <DsToastHost />
  </div>
</template>
