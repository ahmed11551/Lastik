<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Cashier / Shift POS
 * Block 2.1: shift · 2.3: fiscal receipts (54-ФЗ)
 */
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useCashierStore, useShiftStore } from '@/autometria/stores/cashierStore'
import { useFiscalStore } from '@/autometria/stores/fiscalStore'
import { toast } from '@/autometria/api/toast'
import { getStoredUser } from '@/autometria/api/client'
import { toE164Ru } from '@/autometria/utils/fiscalFormat'
import type { FiscalReceipt, PaymentConfirmPayload } from '@/autometria/types/fiscal'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import ShiftStatusWidget from '@/autometria/components/shift/ShiftStatusWidget.vue'
import ShiftOpenModal from '@/autometria/components/shift/ShiftOpenModal.vue'
import ShiftCloseModal from '@/autometria/components/shift/ShiftCloseModal.vue'
import CashOperationModal from '@/autometria/components/shift/CashOperationModal.vue'
import PaymentModal from '@/autometria/components/fiscal/PaymentModal.vue'
import FiscalReceiptModal from '@/autometria/components/fiscal/FiscalReceiptModal.vue'
import FiscalReceiptStatusBadge from '@/autometria/components/fiscal/FiscalReceiptStatusBadge.vue'
import { useBarcodeScanner } from '@/autometria/composables/useBarcodeScanner'
import { parseGs1 } from '@/autometria/utils/gs1Parser'

type CashOpType = 'deposit' | 'withdrawal'

const emit = defineEmits<{
  (e: 'navigate', payload: { id: string }): void
  (e: 'pay', payload: { method: string; amount: number }): void
}>()

const shiftStore = useShiftStore()
const cashier = useCashierStore()
const fiscal = useFiscalStore()

const {
  open: shiftOpen,
  shiftId,
  startedAt: shiftStartedAt,
  revenue: shiftRevenue,
  openingAmount,
  expectedCash,
  totals,
  loading: shiftLoading,
  mutating: shiftMutating,
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
  productQuery,
} = storeToRefs(cashier)

const {
  current: currentReceipt,
  history: fiscalHistory,
  mutating: fiscalMutating,
  polling: fiscalPolling,
} = storeToRefs(fiscal)

const payMode = ref<'single' | 'mixed'>('single')
const mixedCash = ref(0)
const mixedCard = ref(0)
const mixedTransfer = ref(0)

const openModal = ref(false)
const closeModal = ref(false)
const cashOpOpen = ref(false)
const cashOpType = ref<CashOpType>('deposit')
const paymentModalOpen = ref(false)
const receiptModalOpen = ref(false)
const viewingReceipt = ref<FiscalReceipt | null>(null)

const searchRef = ref<HTMLInputElement | null>(null)
const paySectionRef = ref<HTMLElement | null>(null)

// --- Barcode / DataMatrix camera scanner (Sprint 2) ---
const scanner = useBarcodeScanner()
const videoRef = ref<HTMLVideoElement | null>(null)
const scanOpen = ref(false)

async function startScan(): Promise<void> {
  scanOpen.value = true
  await nextTick()
  if (!videoRef.value) return
  await scanner.start(videoRef.value, onScan)
  if (!scanner.supported.value && scanner.error.value) {
    toast.warning(scanner.error.value, 'Сканер')
  }
}

function stopScan(): void {
  scanner.stop()
  scanOpen.value = false
}

function onScan(raw: string): void {
  const parsed = parseGs1(raw)
  const code = parsed.gtin ?? raw.trim()
  if (!code) return
  cashier.addByBarcode(code)
  if (parsed.serial || parsed.batch) {
    toast.info(`Добавлено по ЧЗ: серия ${parsed.serial ?? '—'}, партия ${parsed.batch ?? '—'}`, 'Сканер')
  }
  stopScan()
}


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
  { id: 'bank', label: 'Безнал', hint: 'Invoice' },
] as const

const quickNav = [
  { id: 'warehouse', label: 'Склад' },
  { id: 'wms_cells', label: 'Ячейки' },
  { id: 'tv_display', label: 'TV' },
  { id: 'orders', label: 'Заказы' },
]

const totalDue = computed(() => cashier.totalDue)
const itemsCount = computed(() => cashier.itemsCount)
const cashBalance = computed(() => Number(expectedCash.value || 0))

const mixedSum = computed(
  () => Number(mixedCash.value || 0) + Number(mixedCard.value || 0) + Number(mixedTransfer.value || 0),
)
const mixedDelta = computed(() => Math.round((mixedSum.value - totalDue.value) * 100) / 100)

const anyModalOpen = computed(
  () =>
    openModal.value ||
    closeModal.value ||
    cashOpOpen.value ||
    paymentModalOpen.value ||
    receiptModalOpen.value,
)

const selectedPayLabel = computed(() => {
  if (payMode.value === 'mixed') return 'Смешанная'
  return payMethods.find((m) => m.id === selectedPay.value)?.label || selectedPay.value
})

function money(n: number | string | null | undefined): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

const opBadge = computed(() => {
  const map: Record<string, { variant: string; label: string }> = {
    pending: { variant: 'pending', label: lastOp.value.label },
    success: { variant: 'success', label: lastOp.value.label },
    danger: { variant: 'danger', label: lastOp.value.label },
    warning: { variant: 'warning', label: lastOp.value.label },
  }
  return map[lastOp.value.status] || map.pending
})

function selectPay(id: string): void {
  payMode.value = 'single'
  cashier.setPay(id)
}

function enableMixed(): void {
  payMode.value = 'mixed'
  const half = Math.floor(totalDue.value / 2)
  mixedCash.value = half
  mixedCard.value = totalDue.value - half
  mixedTransfer.value = 0
  cashier.setPay('cash')
}

function syncMixedTendered(): void {
  tendered.value = mixedSum.value
}

async function focusSearch(): Promise<void> {
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select?.()
}

async function focusPay(): Promise<void> {
  await nextTick()
  paySectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  const first = paySectionRef.value?.querySelector<HTMLButtonElement>('[data-pay-method]')
  first?.focus()
}

function onSearchEnter(): void {
  const q = String(productQuery.value || '').trim()
  if (!q) return
  if (/^\d{8,14}$/.test(q)) {
    cashier.addByBarcode(q)
    return
  }
  toast.info('Введите EAN (8–14 цифр) или выберите товар из каталога', 'Поиск')
}

function onHotkey(e: KeyboardEvent): void {
  if (anyModalOpen.value) return
  const tag = (e.target as HTMLElement | null)?.tagName?.toLowerCase()
  const inEditable = tag === 'textarea' || (tag === 'input' && (e.target as HTMLInputElement).type !== 'search')

  if (e.key === 'F2') {
    e.preventDefault()
    focusSearch()
    return
  }
  if (e.key === 'F4') {
    e.preventDefault()
    focusPay()
  }
  // Enter on search handled by @keydown.enter on input; ignore global Enter in other inputs
  if (e.key === 'Enter' && inEditable && tag !== 'input') {
    /* noop */
  }
}

function requestOpenShift(): void {
  openModal.value = true
}

function requestCloseShift(): void {
  if (!shiftOpen.value) return
  closeModal.value = true
}

function openCashOp(type: CashOpType): void {
  if (!shiftOpen.value) {
    toast.warning('Сначала откройте смену', 'Касса')
    return
  }
  cashOpType.value = type
  cashOpOpen.value = true
}

async function confirmOpen(payload: { opening_amount: number; note?: string }): Promise<void> {
  try {
    await shiftStore.openShift(payload.opening_amount, payload.note)
    openModal.value = false
  } catch {
    toast.warning('Не удалось открыть смену', 'Shift')
  }
}

async function confirmClose(payload: {
  closing_amount: number
  note?: string
  variance: number
}): Promise<void> {
  try {
    const noteParts = [
      payload.note,
      Math.abs(payload.variance) >= 0.01
        ? `variance=${payload.variance}`
        : null,
    ].filter(Boolean)
    await shiftStore.closeShift({
      closing_amount: payload.closing_amount,
      note: noteParts.join('; ') || undefined,
    })
    closeModal.value = false
  } catch {
    toast.warning('Не удалось закрыть смену', 'Shift')
  }
}

async function confirmCashOp(payload: {
  type: CashOpType
  amount: number
  reason: string
}): Promise<void> {
  try {
    await shiftStore.cashMovement(payload)
    cashOpOpen.value = false
  } catch {
    toast.warning('Операция не выполнена', 'Касса')
  }
}

function requestPay(): void {
  if (!shiftOpen.value) {
    lastOp.value = { status: 'danger', label: 'Смена закрыта' }
    toast.warning('Сначала откройте смену', 'Касса')
    return
  }
  if (!cart.value.length) {
    toast.warning('Корзина пуста', 'Касса')
    return
  }
  if (payMode.value === 'mixed') {
    syncMixedTendered()
    if (Math.abs(mixedDelta.value) > 0.009) {
      toast.warning(
        `Смешанная оплата: сумма частей ${money(mixedSum.value)} ≠ к оплате ${money(totalDue.value)}`,
      )
      return
    }
  } else {
    tendered.value = totalDue.value
  }
  paymentModalOpen.value = true
}

async function confirmPayment(payload: PaymentConfirmPayload): Promise<void> {
  if (payMode.value === 'mixed') {
    cashier.setPay('cash')
  } else {
    cashier.setPay(payload.method)
  }

  try {
    const result = await cashier.checkout({
      method: payload.method,
      tendered: payload.tendered,
      vat_rate: payload.fiscal.vat_rate,
    })
    if (!result) return

    paymentModalOpen.value = false
    emit('pay', { method: payload.method, amount: Number(result.total || payload.tendered) })
    await shiftStore.fetchCurrent()
    payMode.value = 'single'

    const orderId = Number(result.order?.id || 0)
    if (payload.fiscal.electronic) {
      await createFiscalAfterPay(result, payload)
    } else if (orderId) {
      // PaymentService may still enqueue a receipt — keep history in sync
      try {
        await fiscal.fetchReceiptByOrder(orderId)
      } catch {
        /* silent */
      }
    }
    if (shiftId.value) {
      try {
        await fiscal.fetchShiftHistory(shiftId.value)
      } catch {
        /* silent */
      }
    }
  } catch {
    /* toast via interceptor */
  }
}

async function createFiscalAfterPay(
  result: {
    order?: { id?: number; payments?: Array<{ id?: number }> }
    shift_id?: number
    total?: number
    cart_snapshot?: Array<Record<string, unknown>>
    vat_rate?: string
  },
  payload: PaymentConfirmPayload,
): Promise<void> {
  const orderId = Number(result.order?.id || 0)
  if (!orderId) {
    toast.warning('Заказ создан, но id недоступен для фискализации', '54-ФЗ')
    return
  }

  const user = getStoredUser() as { name?: string; full_name?: string } | null
  const items = cashier.buildFiscalItems(
    result.cart_snapshot || [],
    payload.fiscal.vat_rate,
  )
  const paymentId = result.order?.payments?.[0]?.id ?? null

  try {
    const receipt = await fiscal.createFiscalReceipt({
      order_id: orderId,
      payment_id: paymentId,
      cash_shift_id: result.shift_id || shiftId.value || null,
      type: 'sell',
      electronic: true,
      buyer_email: payload.fiscal.buyer_email || null,
      buyer_phone: toE164Ru(payload.fiscal.buyer_phone) || payload.fiscal.buyer_phone || null,
      tax_system: payload.fiscal.tax_system,
      vat_rate: payload.fiscal.vat_rate,
      items,
      idempotency_key: `pos-${orderId}-${Date.now()}`,
    })

    if (receipt) {
      // Enrich local preview fields if backend payload is sparse
      if (!receipt.payload) {
        receipt.payload = {
          organization_name: 'AUTOMETRIA',
          cashier_name: user?.full_name || user?.name || 'Кассир',
          shift_number: result.shift_id || shiftId.value || undefined,
          tax_system: payload.fiscal.tax_system,
          buyer_email: payload.fiscal.buyer_email || null,
          buyer_phone: payload.fiscal.buyer_phone || null,
          electronic: true,
          total: Number(result.total || payload.tendered),
          items,
        }
        fiscal.setCurrent(receipt)
      }
      viewingReceipt.value = fiscal.current
      receiptModalOpen.value = true
    }
  } catch {
    toast.warning('Оплата прошла, фискализация недоступна — повторите из истории', '54-ФЗ')
  }
}

function openReceipt(r: FiscalReceipt): void {
  viewingReceipt.value = r
  fiscal.setCurrent(r)
  receiptModalOpen.value = true
  if (r.status === 'pending') fiscal.startPolling(r.id)
}

async function onRetryFiscal(receiptId: number): Promise<void> {
  try {
    const r = await fiscal.retryFiscalization(receiptId)
    if (r) viewingReceipt.value = r
  } catch {
    /* toast in store */
  }
}

onMounted(async () => {
  window.addEventListener('keydown', onHotkey, true)
  try {
    await shiftStore.fetchCurrent()
    if (shiftId.value) await fiscal.fetchShiftHistory(shiftId.value)
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

onUnmounted(() => {
  window.removeEventListener('keydown', onHotkey, true)
  fiscal.stopPolling()
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden p-3 sm:space-y-4 sm:p-4 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Cashier // демо-стенд · смена · 54-ФЗ · F2/F4
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Касса и смены</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Чек · фискализация · Z-отчёт · сканер · demo: 8 заказов
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-for="n in quickNav"
          :key="n.id"
          type="button"
          class="hidden border px-2 py-1.5 font-mono text-[10px] uppercase tracking-wide sm:inline-flex"
          style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
          @click="emit('navigate', { id: n.id })"
        >
          {{ n.label }}
        </button>
        <DsLoadingBadge v-if="loading || checkingOut || shiftLoading || fiscalPolling" label="POS" />
        <DsBadge v-if="degraded || shiftDegraded" status="warning" label="Degraded" variant="warning" dot />
        <DsBadge :variant="opBadge.variant" :label="opBadge.label" :status="lastOp.status" dot />
        <FiscalReceiptStatusBadge
          v-if="currentReceipt"
          :receipt="currentReceipt"
          :pending="fiscalMutating"
          @retry="onRetryFiscal"
        />
        <span class="border px-2 py-1.5 font-mono text-[11px]" style="border-color: #1f2937; border-radius: 4px; color: #9ca3af">
          Позиций {{ cart.length }} · ед. {{ itemsCount }}
        </span>
      </div>
    </div>

    <ShiftStatusWidget
      :open="shiftOpen"
      :started-at="shiftStartedAt"
      :revenue="shiftRevenue"
      :loading="shiftMutating || shiftLoading"
      @open-shift="requestOpenShift"
      @close-shift="requestCloseShift"
    />

    <!-- Product search / scanner latch -->
    <div
      class="flex flex-col gap-2 border p-3 sm:flex-row sm:items-center"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="relative min-w-0 flex-1">
        <input
          ref="searchRef"
          v-model="productQuery"
          type="search"
          inputmode="numeric"
          enterkeyhint="done"
          autocomplete="off"
          class="ds-input h-12 w-full pr-16 font-mono text-base sm:h-10 sm:text-sm"
          style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          placeholder="F2 · штрихкод EAN / артикул…"
          @keydown.enter.prevent="onSearchEnter"
        >
        <kbd
          class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 border px-1.5 font-mono text-[10px]"
          style="color: #f59e0b; border-color: #1f2937; border-radius: 4px; background: #11151a"
        >F2</kbd>
      </div>
      <div class="flex gap-2">
        <button
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] sm:h-9 sm:flex-none"
          style="border-color: #10B981; color: #10B981; border-radius: 4px; background: #0b0d10"
          :disabled="!shiftOpen"
          @click="openCashOp('deposit')"
        >
          Внесение
        </button>
        <button
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] sm:h-9 sm:flex-none"
          style="border-color: #F59E0B; color: #F59E0B; border-radius: 4px; background: #0b0d10"
          :disabled="!shiftOpen"
          @click="openCashOp('withdrawal')"
        >
          Выемка
        </button>
        <button
          type="button"
          class="h-11 flex-1 border px-3 font-mono text-[11px] sm:h-9 sm:flex-none"
          style="border-color: #60A5FA; color: #60A5FA; border-radius: 4px; background: #0b0d10"
          :disabled="!shiftOpen"
          @click="startScan()"
        >
          📷 Сканировать
        </button>
      </div>
    </div>

    <div class="grid gap-3 md:gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.9fr)]">
      <section class="flex min-w-0 flex-col gap-3 border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
        <div class="flex items-center justify-between gap-2 px-1">
          <h3 class="text-xs font-medium text-white">Чек / текущая корзина</h3>
          <DsBadge status="open" label="POS Cart" variant="open" />
        </div>

        <div class="space-y-2 md:hidden">
          <article
            v-for="row in cart"
            :key="row.id"
            class="border p-3"
            style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <div class="truncate font-sans text-[13px] font-medium text-white">{{ row.name }}</div>
                <div class="font-mono text-[11px]" style="color: #6b7280">{{ row.sku }} · ×{{ row.qty }}</div>
              </div>
              <div class="shrink-0 font-mono text-[13px] font-bold tabular-nums" style="color: #f59e0b">
                {{ money(row.line) }}
              </div>
            </div>
          </article>
          <p v-if="!cart.length" class="py-6 text-center text-sm" style="color: #9ca3af">
            Корзина пуста · отсканируйте EAN (Enter)
          </p>
        </div>

        <div class="hidden min-w-0 md:block">
          <DsTable
            :columns="columns"
            :rows="cart"
            density="compact"
            sticky-header
            max-height="min(52vh, 440px)"
            empty-text="Корзина пуста — F2 + EAN / каталог"
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
        </div>
      </section>

      <aside class="flex min-w-0 flex-col gap-3">
        <div class="border p-4" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="mb-2 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #9ca3af">
            Итого к оплате
          </div>
          <div class="font-mono text-3xl font-bold tabular-nums leading-none sm:text-4xl" style="color: #f59e0b">
            {{ money(totalDue) }}
          </div>
          <div class="mt-2 font-mono text-[11px]" style="color: #6b7280">
            Ожид. нал. в ящике {{ money(cashBalance) }}
          </div>
        </div>

        <div
          ref="paySectionRef"
          class="border p-3"
          style="background: #11151a; border-color: #1f2937; border-radius: 4px"
          data-pay-section
        >
          <div class="mb-2 flex items-center justify-between gap-2">
            <div class="text-xs font-medium text-white">
              Способ оплаты
              <kbd class="ml-1 border px-1 font-mono text-[10px]" style="border-color: #1f2937; color: #f59e0b; border-radius: 4px">F4</kbd>
            </div>
            <button
              type="button"
              class="h-9 border px-2.5 font-mono text-[11px]"
              :style="{
                borderRadius: '4px',
                borderColor: payMode === 'mixed' ? '#f59e0b' : '#1f2937',
                color: payMode === 'mixed' ? '#f59e0b' : '#9ca3af',
                background: '#0b0d10',
              }"
              @click="enableMixed"
            >
              Смешанная
            </button>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="m in payMethods"
              :key="m.id"
              type="button"
              data-pay-method
              class="min-h-[64px] border px-3 py-3 text-left transition-colors active:scale-[0.98] sm:min-h-[56px]"
              :class="payMode === 'single' && selectedPay === m.id ? 'border-amber-500' : 'border-[#1F2937]'"
              :style="{
                background: payMode === 'single' && selectedPay === m.id ? '#161b22' : '#0b0d10',
                borderRadius: '4px',
              }"
              @click="selectPay(m.id)"
            >
              <div class="text-sm font-medium text-white sm:text-xs">{{ m.label }}</div>
              <div class="font-mono text-[11px]" style="color: #6b7280">{{ m.hint }}</div>
            </button>
          </div>

          <div
            v-if="payMode === 'mixed'"
            class="mt-3 flex flex-col gap-2 md:grid md:grid-cols-3"
          >
            <label class="block text-[11px]" style="color: #9ca3af">
              Наличные
              <input
                v-model.number="mixedCash"
                type="number"
                inputmode="decimal"
                class="ds-input mt-1 h-12 w-full font-mono text-base md:h-10 md:text-sm"
                style="border-radius: 4px; background: #161b22; border-color: #1f2937"
                @input="syncMixedTendered"
              >
            </label>
            <label class="block text-[11px]" style="color: #9ca3af">
              Карта / эквайринг
              <input
                v-model.number="mixedCard"
                type="number"
                inputmode="decimal"
                class="ds-input mt-1 h-12 w-full font-mono text-base md:h-10 md:text-sm"
                style="border-radius: 4px; background: #161b22; border-color: #1f2937"
                @input="syncMixedTendered"
              >
            </label>
            <label class="block text-[11px]" style="color: #9ca3af">
              Перевод
              <input
                v-model.number="mixedTransfer"
                type="number"
                inputmode="decimal"
                class="ds-input mt-1 h-12 w-full font-mono text-base md:h-10 md:text-sm"
                style="border-radius: 4px; background: #161b22; border-color: #1f2937"
                @input="syncMixedTendered"
              >
            </label>
            <div class="font-mono text-[11px] md:col-span-3" :style="{ color: Math.abs(mixedDelta) < 0.01 ? '#10b981' : '#f59e0b' }">
              Части: {{ money(mixedSum) }}
              <span v-if="Math.abs(mixedDelta) >= 0.01"> · дельта {{ money(mixedDelta) }}</span>
            </div>
          </div>

          <button
            type="button"
            class="mt-3 h-14 w-full border px-3 font-mono text-sm font-bold uppercase tracking-wide transition-colors disabled:opacity-50 sm:h-11 sm:text-xs"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="!shiftOpen || checkingOut || !cart.length"
            @click="requestPay"
          >
            Провести оплату
          </button>
        </div>

        <!-- Fiscal receipts history (current shift) -->
        <div class="border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="mb-2 flex items-center justify-between gap-2">
            <div class="text-xs font-medium text-white">Чеки смены (54-ФЗ)</div>
            <span class="font-mono text-[10px]" style="color: #6b7280">
              {{ fiscalHistory.length }} шт.
            </span>
          </div>
          <div v-if="!fiscalHistory.length" class="py-3 text-center text-[12px]" style="color: #6b7280">
            История пуста — чеки появятся после оплаты с фискализацией
          </div>
          <ul v-else class="max-h-48 space-y-2 overflow-y-auto">
            <li
              v-for="r in fiscalHistory"
              :key="r.id"
              class="flex cursor-pointer flex-col gap-1.5 border p-2 transition-colors hover:border-amber-500"
              style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
              @click="openReceipt(r)"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="font-mono text-[11px] text-white">
                  #{{ r.order_id || r.id }}
                  <span style="color: #6b7280">· {{ r.type }}</span>
                </span>
                <span class="font-mono text-[11px]" style="color: #f59e0b">
                  {{ money(r.payload?.total) }}
                </span>
              </div>
              <FiscalReceiptStatusBadge
                :receipt="r"
                :pending="fiscalMutating"
                @retry="onRetryFiscal"
              />
            </li>
          </ul>
        </div>

        <div class="border p-3" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
          <div class="mb-2 text-xs font-medium text-white">Быстрая навигация</div>
          <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
            <button
              v-for="n in quickNav"
              :key="n.id"
              type="button"
              class="h-11 border border-[#1F2937] px-2.5 font-mono text-[12px] text-white transition-colors hover:border-amber-500 sm:h-9 sm:text-[11px]"
              style="background: #0b0d10; border-radius: 4px"
              @click="emit('navigate', { id: n.id })"
            >
              {{ n.label }}
            </button>
          </div>
        </div>
      </aside>
    </div>

    <ShiftOpenModal
      v-model:open="openModal"
      :pending="shiftMutating"
      @confirm="confirmOpen"
    />
    <ShiftCloseModal
      v-model:open="closeModal"
      :pending="shiftMutating"
      :expected-cash="expectedCash"
      :opening-amount="openingAmount"
      :cash-sales="totals.cash"
      :deposits="totals.deposit"
      :withdrawals="totals.withdrawal"
      :inkasso="totals.inkasso"
      @confirm="confirmClose"
    />
    <CashOperationModal
      v-model:open="cashOpOpen"
      :type="cashOpType"
      :pending="shiftMutating"
      @confirm="confirmCashOp"
    />
    <PaymentModal
      v-model:open="paymentModalOpen"
      :pending="checkingOut || fiscalMutating"
      :total="totalDue"
      :method="selectedPay"
      :method-label="selectedPayLabel"
      :pay-mode="payMode"
      :mixed="payMode === 'mixed' ? { cash: mixedCash, card: mixedCard, transfer: mixedTransfer } : null"
      @confirm="confirmPayment"
    />
    <FiscalReceiptModal
      v-model:open="receiptModalOpen"
      :receipt="viewingReceipt || currentReceipt"
      :pending="fiscalMutating"
      @retry="onRetryFiscal"
    />

    <!-- Barcode / DataMatrix camera scanner overlay (Sprint 2) -->
    <div
      v-if="scanOpen"
      class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-black/85 p-4"
    >
      <div class="flex w-full max-w-md items-center justify-between">
        <span class="font-mono text-[11px] uppercase tracking-wide" style="color: #60A5FA">
          Сканер · наведите на штрихкод / DataMatrix
        </span>
        <button
          type="button"
          class="border px-3 py-1.5 font-mono text-[11px]"
          style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
          @click="stopScan()"
        >
          Закрыть
        </button>
      </div>
      <video
        ref="videoRef"
        playsinline
        muted
        class="aspect-square w-full max-w-md rounded border"
        style="border-color: #f59e0b; background: #0b0d10"
      />
      <p v-if="scanner.error.value" class="font-mono text-[11px]" style="color: #F87171">
        {{ scanner.error.value }}
      </p>
      <p class="font-mono text-[10px]" style="color: #9ca3af">
        Нативный BarcodeDetector → fallback ZXing. Если камера недоступна — введите код вручную.
      </p>
    </div>
  </div>
</template>
