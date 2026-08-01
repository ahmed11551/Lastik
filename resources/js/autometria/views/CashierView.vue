<script setup>
/**
 * AUTOMETRIA ERP — Cashier / Shift POS (API-wired)
 */
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsShiftWidget, DsTable } from '@/design-system'
import { useCashierStore, useShiftStore } from '@/autometria/stores/cashierStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const emit = defineEmits(['navigate', 'pay'])

const shiftStore = useShiftStore()
const cashier = useCashierStore()

const {
  open: shiftOpen,
  startedAt: shiftStartedAt,
  revenue: shiftRevenue,
  openingAmount,
  degraded: shiftDegraded,
} = storeToRefs(shiftStore)

const {
  cart,
  selectedPay,
  lastOp,
  checkingOut,
  loading,
  degraded,
  tendered,
} = storeToRefs(cashier)

const columns = [
  { key: 'n', label: '№', width: '44px' },
  { key: 'sku', label: 'Артикул / OEM' },
  { key: 'name', label: 'Наименование', mono: false },
  { key: 'qty', label: 'Кол-во', width: '64px' },
  { key: 'discount', label: 'Скидка %', width: '80px' },
  { key: 'line', label: 'Сумма' },
]

const payMethods = [
  { id: 'cash', label: 'Наличные', hint: 'Cash' },
  { id: 'card', label: 'Карта', hint: 'POS' },
  { id: 'sbp', label: 'СБП', hint: 'QR' },
  { id: 'bank', label: 'Безнал / р/с', hint: 'Invoice' },
]

const quickNav = [
  { id: 'warehouse', label: 'Склад' },
  { id: 'orders', label: 'Заказы' },
  { id: 'new_order', label: 'Новый заказ' },
  { id: 'kpi', label: 'KPI' },
]

const totalDue = computed(() => cashier.totalDue)
const itemsCount = computed(() => cashier.itemsCount)
const cashBalance = computed(() => Number(openingAmount.value || 0) + Number(shiftRevenue.value || 0))

function money(n) {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

const opBadge = computed(() => {
  const map = {
    pending: { variant: 'pending', label: lastOp.value.label },
    success: { variant: 'success', label: lastOp.value.label },
    danger: { variant: 'danger', label: lastOp.value.label },
    warning: { variant: 'warning', label: lastOp.value.label },
  }
  return map[lastOp.value.status] || map.pending
})

async function onOpenShift() {
  try {
    await shiftStore.openShift(0)
  } catch {
    toast.warning('Не удалось открыть смену', 'Shift')
  }
}

async function onCloseShift() {
  try {
    await shiftStore.closeShift({ closing_amount: cashBalance.value })
  } catch {
    toast.warning('Не удалось закрыть смену', 'Shift')
  }
}

async function confirmPay() {
  if (!shiftOpen.value) {
    lastOp.value = { status: 'danger', label: 'Смена закрыта' }
    return
  }
  tendered.value = totalDue.value
  try {
    const result = await cashier.checkout()
    if (result) {
      emit('pay', { method: selectedPay.value, amount: result.total })
      await shiftStore.fetchCurrent()
    }
  } catch {
    /* toast via interceptor */
  }
}

onMounted(async () => {
  try {
    await shiftStore.fetchCurrent()
  } catch {
    toast.warning('Смена недоступна — degraded mode', 'Shift')
  }
  try {
    await cashier.fetchCatalog()
    cashier.seedDemoCart()
  } catch {
    toast.warning('Каталог POS недоступен', 'Cashier')
  }
})
</script>

<template>
  <div
    class="-m-4 space-y-4 p-4 lg:-m-6 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <div
      class="flex flex-col gap-3 border p-4 sm:flex-row sm:items-center sm:justify-between"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div>
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Cashier // /api/v1/pos/checkout · shifts
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Касса и смены</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">Чек · оплата · смена · баланс кассы</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <DsLoadingBadge v-if="loading || checkingOut" label="POS" />
        <DsBadge v-if="degraded || shiftDegraded" status="warning" label="Degraded" variant="warning" dot />
        <DsBadge :variant="opBadge.variant" :label="opBadge.label" :status="lastOp.status" dot />
        <span class="border px-2 py-1 font-mono text-[11px]" style="border-color: #1f2937; border-radius: 4px; color: #9ca3af">
          Позиций {{ cart.length }} · ед. {{ itemsCount }}
        </span>
      </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.9fr)]">
      <section class="flex min-w-0 flex-col gap-3 border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
        <div class="flex items-center justify-between gap-2 px-1">
          <h3 class="text-xs font-medium text-white">Чек / текущая корзина</h3>
          <DsBadge status="open" label="POS Cart" variant="open" />
        </div>

        <DsTable
          :columns="columns"
          :rows="cart"
          density="compact"
          sticky-header
          max-height="min(52vh, 440px)"
          empty-text="Корзина пуста — загрузите каталог /api/v1/products"
        >
          <template #n="{ value }">
            <span class="font-mono text-[11px] tabular-nums" style="color: #6b7280">{{ value }}</span>
          </template>
          <template #sku="{ row }">
            <div class="leading-tight">
              <div class="font-mono text-[12px] font-medium tabular-nums text-white">{{ row.sku }}</div>
              <div class="font-mono text-[10px] tabular-nums" style="color: #6b7280">OEM {{ row.oem }}</div>
            </div>
          </template>
          <template #name="{ value }">
            <span class="font-sans text-[12px] font-medium text-white">{{ value }}</span>
          </template>
          <template #qty="{ value }">
            <span class="font-mono text-[12px] font-medium tabular-nums text-white">{{ value }}</span>
          </template>
          <template #discount="{ value }">
            <span class="font-mono text-[12px] tabular-nums" style="color: #9ca3af">{{ value }}%</span>
          </template>
          <template #line="{ value }">
            <span class="font-mono text-[12px] font-bold tabular-nums" style="color: #f59e0b">{{ money(value) }}</span>
          </template>
        </DsTable>
      </section>

      <aside class="flex min-w-0 flex-col gap-3">
        <div class="border p-4" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="mb-2 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #9ca3af">
            Итого к оплате
          </div>
          <div class="font-mono text-3xl font-bold tabular-nums leading-none sm:text-4xl" style="color: #f59e0b">
            {{ money(totalDue) }}
          </div>
        </div>

        <div class="border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="mb-2 text-xs font-medium text-white">Способ оплаты</div>
          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="m in payMethods"
              :key="m.id"
              type="button"
              class="border px-3 py-2.5 text-left transition-colors hover:border-amber-500"
              :class="selectedPay === m.id ? 'border-amber-500' : 'border-[#1F2937]'"
              :style="{ background: selectedPay === m.id ? '#161b22' : '#0b0d10', borderRadius: '4px' }"
              @click="cashier.setPay(m.id)"
            >
              <div class="text-xs font-medium text-white">{{ m.label }}</div>
              <div class="font-mono text-[10px]" style="color: #6b7280">{{ m.hint }}</div>
            </button>
          </div>
          <button
            type="button"
            class="mt-3 w-full border px-3 py-2.5 font-mono text-xs font-bold uppercase tracking-wide transition-colors disabled:opacity-50"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="!shiftOpen || checkingOut || !cart.length"
            @click="confirmPay"
          >
            Провести оплату
          </button>
        </div>

        <div class="space-y-3 border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="text-xs font-medium text-white">Смена / кассовый баланс</div>
          <DsShiftWidget
            :open="shiftOpen"
            :started-at="shiftStartedAt"
            :revenue="shiftRevenue"
            @open-shift="onOpenShift"
            @close-shift="onCloseShift"
          />
          <div class="flex flex-wrap gap-2">
            <span class="border px-2 py-1 font-mono text-[11px] tabular-nums" style="border-color: #1f2937; border-radius: 4px; color: #f59e0b">
              Баланс {{ money(cashBalance) }}
            </span>
            <DsBadge
              :status="shiftOpen ? 'open' : 'closed'"
              :label="shiftOpen ? 'Смена открыта' : 'Смена закрыта'"
              :variant="shiftOpen ? 'open' : 'closed'"
              dot
            />
          </div>
        </div>

        <div class="border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="mb-2 text-xs font-medium text-white">Быстрая навигация</div>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="n in quickNav"
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
      </aside>
    </div>
  </div>
</template>
