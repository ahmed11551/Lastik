<script setup lang="ts">
/**
 * AUTOMETRIA ERP — POS Terminal (Block 3.1 Offline-First)
 * Layout: status · cart | catalog · action bar · hotkeys F2/F4/Esc
 */
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { getStoredUser } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'
import { useShiftStore } from '@/autometria/stores/cashierStore'
import { useOfflineStore } from '@/stores/useOfflineStore'
import { usePosStore } from '@/stores/usePosStore'
import { bindOfflineSyncListeners, syncPendingReceipts } from '@/composables/useOfflineSync'
import type { CachedProduct } from '@/services/offlineDb'
import type { LocalPaymentType } from '@/services/offlineDb'
import CartPanel from '@/Components/pos/CartPanel.vue'
import ProductCatalog from '@/Components/pos/ProductCatalog.vue'
import PaymentModal from '@/Components/pos/PaymentModal.vue'
import ShiftControlModal from '@/Components/pos/ShiftControlModal.vue'
import ReceiptTemplate from '@/Components/pos/ReceiptTemplate.vue'
import MarkingScanModal from '@/Components/pos/MarkingScanModal.vue'
import BranchSelector from '@/Pages/POS/Partials/BranchSelector.vue'
import RefundModal from '@/Components/pos/RefundModal.vue'
import { createReceiptPrinter } from '@/services/printer/ReceiptPrinterService'
import type { PosReceipt } from '@/services/printer/types'

const shiftStore = useShiftStore()
const pos = usePosStore()
const offline = useOfflineStore()

const {
  open: shiftOpen,
  shiftId,
  startedAt,
  expectedCash,
  mutating: shiftMutating,
} = storeToRefs(shiftStore)

const {
  cart,
  searchQuery,
  activeCategory,
  quickCategories,
  discountPercent,
  promoCode,
  checkingOut,
  loadingCatalog,
  lastOp,
} = storeToRefs(pos)

const { online, pendingCount, failedCount, pendingRefundCount, syncing } = storeToRefs(offline)

const catalogRef = ref<InstanceType<typeof ProductCatalog> | null>(null)
const payOpen = ref(false)
const shiftModalOpen = ref(false)
const shiftMode = ref<'open' | 'close'>('open')
const markingOpen = ref(false)
const markingPending = ref<CachedProduct | null>(null)
const refundOpen = ref(false)

const user = getStoredUser() as { name?: string; full_name?: string } | null
const cashierName = computed(() => user?.full_name || user?.name || 'Кассир')

const shiftAgeHours = computed(() => {
  if (!startedAt.value) return 0
  const t = new Date(startedAt.value).getTime()
  if (Number.isNaN(t)) return 0
  return (Date.now() - t) / 3600000
})

const shiftExpired = computed(() => shiftOpen.value && shiftAgeHours.value >= 24)
const salesBlocked = computed(() => !shiftOpen.value || shiftExpired.value)

const anyModal = computed(
  () => payOpen.value || shiftModalOpen.value || markingOpen.value || refundOpen.value,
)

const filteredProducts = computed(() => pos.filteredCatalog)

let unbindSync: (() => void) | null = null
let scannerBuf = ''
let scannerTimer: ReturnType<typeof setTimeout> | null = null

function focusSearch(): void {
  catalogRef.value?.focus?.()
}

function requestMarking(p: CachedProduct): void {
  markingPending.value = p
  markingOpen.value = true
}

function onCatalogAdd(p: CachedProduct): void {
  const res = pos.addProduct(p)
  if (res && 'reason' in res && res.reason === 'needs_marking') {
    requestMarking(p)
  }
}

function onMarkingConfirm(code: string): void {
  if (!markingPending.value) return
  const res = pos.addProduct(markingPending.value, 1, code)
  if (res?.ok) {
    markingOpen.value = false
    markingPending.value = null
    toast.success('Марка принята (КИЗ проверен)', 'Честный Знак')
    focusSearch()
  } else {
    toast.error('Не удалось добавить позицию с маркой', 'Честный Знак')
  }
}

function onMarkingCancel(): void {
  markingPending.value = null
}

function onRefundDone(): void {
  toast.success('Возврат обработан', 'POS')
}

function requestPay(): void {
  if (salesBlocked.value) {
    if (!shiftOpen.value) {
      toast.warning('Откройте смену', 'POS')
      shiftMode.value = 'open'
      shiftModalOpen.value = true
      return
    }
    if (shiftExpired.value) {
      toast.warning('Смена > 24ч — закройте смену (Z-отчёт)', '54-ФЗ')
      shiftMode.value = 'close'
      shiftModalOpen.value = true
      return
    }
  }
  if (!cart.value.length) {
    toast.warning('Корзина пуста', 'POS')
    return
  }
  payOpen.value = true
}

async function onPayConfirm(payload: {
  payment_type: LocalPaymentType
  method: string
  amount_tendered: number
  payment_parts?: Array<{ method: string; amount: number }>
}): Promise<void> {
  if (!shiftId.value) {
    toast.warning('Нет id смены', 'POS')
    return
  }
  try {
    const cartSnapshot = cart.value.map((r) => ({
      title: r.title,
      qty: r.qty,
      price: r.price,
      sum: r.line,
      vat_rate: r.vat_rate || 'none',
    }))
    const totalSnapshot = pos.totalDue
    const res = await pos.completeSale({
      ...payload,
      shift_id: Number(shiftId.value),
    })
    if (res) {
      payOpen.value = false
      await shiftStore.fetchCurrent().catch(() => {})
      const printer = createReceiptPrinter()
      const receipt: PosReceipt = {
        organization_name: 'AUTOMETRIA',
        inn: '—',
        kkt_address: 'Адрес расчётов',
        shift_number: shiftId.value || '—',
        receipt_number: res.uuid?.slice(0, 8) || '—',
        cashier_name: cashierName.value,
        datetime: new Date().toLocaleString('ru-RU'),
        items: cartSnapshot,
        total: Number(res.total || totalSnapshot),
        cash_amount: payload.payment_type === 'CARD' ? 0 : Number(payload.amount_tendered || totalSnapshot),
        card_amount: payload.payment_type === 'CASH' ? 0 : Number(res.total || totalSnapshot),
        change: Math.max(0, Number(payload.amount_tendered || 0) - Number(res.total || totalSnapshot)),
        paper_mm: 80,
      }
      await printer.printReceipt(receipt).catch(() => false)
      focusSearch()
    }
  } catch {
    /* toasts handled */
  }
}

async function onShiftConfirm(payload: {
  opening_amount?: number
  closing_amount?: number
  note?: string
}): Promise<void> {
  try {
    if (shiftMode.value === 'open') {
      await shiftStore.openShift(payload.opening_amount || 0, payload.note)
    } else {
      await shiftStore.closeShift({
        closing_amount: payload.closing_amount,
        note: payload.note,
      })
    }
    shiftModalOpen.value = false
  } catch {
    toast.warning('Операция со сменой не выполнена', 'Shift')
  }
}

function onHotkey(e: KeyboardEvent): void {
  if (anyModal.value) {
    if (e.key === 'Escape') {
      e.preventDefault()
      payOpen.value = false
      shiftModalOpen.value = false
      markingOpen.value = false
      refundOpen.value = false
    }
    return
  }

  if (e.key === 'F2') {
    e.preventDefault()
    focusSearch()
    return
  }
  if (e.key === 'F4') {
    e.preventDefault()
    requestPay()
    return
  }
  if (e.key === 'Escape') {
    e.preventDefault()
    pos.clearCart()
    return
  }

  // Wedge scanner: rapid digit bursts ending with Enter
  const tag = (e.target as HTMLElement | null)?.tagName?.toLowerCase()
  const inSearch = tag === 'input' || tag === 'textarea'
  if (!inSearch && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
    scannerBuf += e.key
    if (scannerTimer) clearTimeout(scannerTimer)
    scannerTimer = setTimeout(() => {
      scannerBuf = ''
    }, 80)
  }
  if (!inSearch && e.key === 'Enter' && scannerBuf.length >= 8) {
    e.preventDefault()
    const code = scannerBuf
    scannerBuf = ''
    void (async () => {
      const res = await pos.addByBarcode(code)
      if (res && 'reason' in res && res.reason === 'needs_marking' && res.product) {
        requestMarking(res.product)
      }
    })()
  }
}

onMounted(async () => {
  window.addEventListener('keydown', onHotkey, true)
  unbindSync = bindOfflineSyncListeners()
  try {
    await shiftStore.fetchCurrent()
  } catch {
    toast.warning('Смена недоступна', 'Shift')
  }
  await pos.loadCatalog()

  try {
    const user = getStoredUser() as { id?: number } | null
    const cashierId = Number(user?.id || 0)
    const restored = await pos.restoreCartDraft(cashierId, Number(shiftId.value || 0))
    if (restored) {
      toast.info('Черновик корзины восстановлен', 'POS Offline')
    }
  } catch {
    /* draft restore is best-effort */
  }

  await nextTick()
  focusSearch()
})

onUnmounted(() => {
  window.removeEventListener('keydown', onHotkey, true)
  unbindSync?.()
  if (scannerTimer) clearTimeout(scannerTimer)
})
</script>

<template>
  <div
    class="flex min-h-[calc(100vh-4rem)] flex-col gap-2 p-2 sm:gap-3 sm:p-3 lg:p-4"
    style="background: var(--brand-desk, var(--autometria-bg, #090d16))"
  >
    <!-- 1. Status bar -->
    <header
      class="flex flex-wrap items-center justify-between gap-2 border px-3 py-2"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div class="flex flex-wrap items-center gap-2 font-mono text-[11px]" style="color: #9ca3af">
        <span class="text-white">
          Shift {{ shiftOpen ? `#${shiftId || '—'}` : 'закрыта' }}
        </span>
        <span>·</span>
        <span>Cashier: {{ cashierName }}</span>
        <span class="hidden sm:inline">·</span>
        <div class="min-w-[12rem] max-w-xs">
          <BranchSelector />
        </div>
        <span
          v-if="shiftExpired"
          class="border px-1.5 py-0.5"
          style="border-color: #EF4444; color: #FCA5A5; border-radius: 4px"
        >
          &gt;24ч · Z-отчёт
        </span>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span
          class="inline-flex items-center gap-1.5 border px-2 py-1 font-mono text-[11px]"
          :style="{
            borderRadius: '4px',
            borderColor: online ? '#10B981' : '#EF4444',
            color: online ? '#6EE7B7' : '#FCA5A5',
            background: online ? '#052e1a' : '#3f0a0a',
          }"
        >
          <span
            class="h-2 w-2 rounded-full"
            :style="{ background: online ? '#10B981' : '#EF4444' }"
          />
          {{ online ? 'ONLINE' : 'OFFLINE' }}
        </span>
        <span
          v-if="pendingCount || pendingRefundCount || failedCount || syncing"
          class="border px-2 py-1 font-mono text-[10px]"
          style="border-color: #1f2937; color: #f59e0b; border-radius: 4px"
        >
          {{ syncing ? 'Sync…' : `Queue ${pendingCount + pendingRefundCount}` }}
          <span v-if="failedCount"> · fail {{ failedCount }}</span>
        </span>
        <button
          type="button"
          class="h-9 border px-2 font-mono text-[11px]"
          style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
          @click="syncPendingReceipts()"
        >
          Sync
        </button>
        <button
          type="button"
          class="h-9 border px-2 font-mono text-[11px]"
          style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px"
          @click="
            shiftMode = shiftOpen ? 'close' : 'open';
            shiftModalOpen = true
          "
        >
          {{ shiftOpen ? 'Z-отчёт' : 'Открыть смену' }}
        </button>
        <span class="hidden font-mono text-[10px] sm:inline" style="color: #6b7280">{{ lastOp.label }}</span>
      </div>
    </header>

    <!-- Block sales banner -->
    <div
      v-if="salesBlocked"
      class="border px-3 py-2 text-sm"
      style="background: #1a1408; border-color: #78350f; border-radius: 4px; color: #fcd34d"
    >
      <template v-if="!shiftOpen">
        Продажи заблокированы — откройте кассовую смену.
      </template>
      <template v-else>
        Смена открыта {{ shiftAgeHours.toFixed(1) }} ч (&gt;24ч по 54-ФЗ). Закройте смену и снимите Z-отчёт.
      </template>
    </div>

    <!-- 2–3. Main grid -->
    <div class="grid min-h-0 flex-1 gap-2 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.15fr)] lg:gap-3">
      <CartPanel
        class="min-h-[320px] lg:min-h-0"
        :lines="cart"
        :subtotal="pos.subtotal"
        :total-due="pos.totalDue"
        :discount-percent="discountPercent"
        :promo-code="promoCode"
        :items-count="pos.itemsCount"
        @update:qty="({ key, qty }) => pos.setQty(key, qty)"
        @remove="pos.removeLine"
        @apply-promo="pos.applyPromo"
        @update:discount-percent="(v) => (discountPercent = v)"
        @clear="pos.clearCart()"
      />

      <ProductCatalog
        ref="catalogRef"
        class="min-h-[320px] lg:min-h-0"
        :products="filteredProducts"
        :query="searchQuery"
        :categories="quickCategories"
        :active-category="activeCategory"
        :loading="loadingCatalog"
        @update:query="(v) => (searchQuery = v)"
        @update:active-category="(v) => (activeCategory = v)"
        @add="onCatalogAdd"
        @scan="async (code) => {
          const res = await pos.addByBarcode(code)
          if (res && 'reason' in res && res.reason === 'needs_marking' && res.product) {
            requestMarking(res.product)
          }
        }"
      />
    </div>

    <!-- 4. Action bar -->
    <footer
      class="flex flex-wrap items-center gap-2 border p-2 sm:p-3"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <button
        type="button"
        class="h-12 min-w-[120px] flex-1 border px-3 font-mono text-xs sm:flex-none"
        style="border-color: #1e293b; color: #fff; border-radius: 4px; background: #090d16"
        @click="focusSearch"
      >
        <kbd class="mr-1" style="color: #f59e0b">F2</kbd> Поиск
      </button>
      <button
        type="button"
        class="h-12 min-w-[140px] flex-[2] border px-3 font-mono text-xs font-bold uppercase disabled:opacity-40 sm:flex-none"
        style="background: #f59e0b; color: #090d16; border-color: #f59e0b; border-radius: 4px"
        :disabled="checkingOut || !cart.length"
        @click="requestPay"
      >
        <kbd class="mr-1">F4</kbd> Оплата
      </button>
      <button
        type="button"
        class="h-12 min-w-[120px] flex-1 border px-3 font-mono text-xs sm:flex-none"
        style="border-color: #ef4444; color: #fca5a5; border-radius: 4px; background: #090d16"
        data-testid="pos-refund-open"
        :disabled="!shiftOpen"
        @click="refundOpen = true"
      >
        Возврат
      </button>
      <button
        type="button"
        class="h-12 min-w-[100px] flex-1 border px-3 font-mono text-xs sm:flex-none"
        style="border-color: #1e293b; color: #a8b3c7; border-radius: 4px; background: #090d16"
        @click="pos.clearCart()"
      >
        <kbd class="mr-1">Esc</kbd> Отмена
      </button>
    </footer>

    <PaymentModal
      v-model:open="payOpen"
      :pending="checkingOut"
      :total="pos.totalDue"
      @confirm="onPayConfirm"
    />
    <ShiftControlModal
      v-model:open="shiftModalOpen"
      :mode="shiftMode"
      :pending="shiftMutating"
      :expected-cash="expectedCash"
      :shift-age-hours="shiftAgeHours"
      @confirm="onShiftConfirm"
    />
    <MarkingScanModal
      v-model:open="markingOpen"
      :product-title="markingPending?.title"
      :product-id="markingPending?.id ?? null"
      @confirm="onMarkingConfirm"
      @cancel="onMarkingCancel"
    />
    <RefundModal
      v-model:open="refundOpen"
      :shift-id="shiftId"
      @done="onRefundDone"
    />
    <ReceiptTemplate :register-host="true" />
  </div>
</template>
