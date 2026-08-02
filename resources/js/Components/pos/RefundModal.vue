<script setup lang="ts">
/**
 * POS RefundModal — возврат прихода (54-ФЗ sell_refund)
 */
import { computed, nextTick, ref, watch } from 'vue'
import { apiGet, apiPost, getStoredUser } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'
import { useOfflineStore } from '@/stores/useOfflineStore'
import type { LocalRefundItem } from '@/services/offlineDb'

export type RefundLine = {
  order_item_id: number
  product_id?: number | null
  title: string
  max_qty: number
  qty: number
  price: number
  marking_code?: string | null
}

const props = defineProps<{
  open: boolean
  pending?: boolean
  shiftId?: number | string | null
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'done', payload: { order_id: number; offline?: boolean }): void
}>()

const offline = useOfflineStore()
const orderIdInput = ref('')
const reason = ref('')
const loading = ref(false)
const submitting = ref(false)
const lines = ref<RefundLine[]>([])
const orderId = ref<number | null>(null)
const orderIdRef = ref<HTMLInputElement | null>(null)

const refundTotal = computed(() =>
  Math.round(
    lines.value.reduce((s, l) => s + Number(l.price || 0) * Number(l.qty || 0), 0) * 100,
  ) / 100,
)

const canSubmit = computed(
  () =>
    !submitting.value &&
    !props.value &&
    orderId.value != null &&
    lines.value.some((l) => Number(l.qty) > 0),
)

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    orderIdInput.value = ''
    reason.value = ''
    lines.value = []
    orderId.value = null
    await nextTick()
    orderIdRef.value?.focus()
  },
)

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

function close(): void {
  emit('update:open', false)
}

async function loadOrder(): Promise<void> {
  const id = Number(orderIdInput.value)
  if (!id) {
    toast.warning('Укажите № заказа', 'Возврат')
    return
  }
  loading.value = true
  try {
    const payload = await apiGet(`/orders/${id}`, { silent: true })
    const order = payload?.order || payload?.data || payload
    const items = payload?.items || order?.order_items || order?.orderItems || []
    orderId.value = Number(order?.id || id)
    lines.value = (Array.isArray(items) ? items : []).map((it: Record<string, unknown>) => {
      const snap = (it.snapshot || {}) as Record<string, unknown>
      const max = Number(it.qty || 0)
      return {
        order_item_id: Number(it.id),
        product_id: it.product_id != null ? Number(it.product_id) : null,
        title: String(snap.name || `Позиция #${it.id}`),
        max_qty: max,
        qty: max,
        price: Number(it.price || 0),
        marking_code: it.marking_code ? String(it.marking_code) : null,
      }
    })
    if (!lines.value.length) {
      toast.warning('В заказе нет позиций', 'Возврат')
    }
  } catch (e: unknown) {
    const msg =
      (e as { response?: { data?: { message?: string } }; message?: string })?.response?.data
        ?.message ||
      (e as { message?: string })?.message ||
      'Заказ не найден'
    toast.error(msg, 'Возврат')
    lines.value = []
    orderId.value = null
  } finally {
    loading.value = false
  }
}

function clampQty(line: RefundLine): void {
  const q = Math.max(0, Math.min(Number(line.max_qty), Number(line.qty) || 0))
  line.qty = Math.round(q * 1000) / 1000
}

async function submit(): Promise<void> {
  if (!canSubmit.value || orderId.value == null) return
  const selected = lines.value.filter((l) => Number(l.qty) > 0)
  if (!selected.length) return

  submitting.value = true
  try {
    const user = getStoredUser() as { id?: number; tenant_id?: number } | null
    const body = {
      order_id: orderId.value,
      reason: reason.value.trim() || undefined,
      cash_shift_id: props.shiftId ? Number(props.shiftId) : undefined,
      items: selected.map((l) => ({
        order_item_id: l.order_item_id,
        qty: Number(l.qty),
      })),
    }

    if (!offline.online) {
      const items: LocalRefundItem[] = selected.map((l) => ({
        order_item_id: l.order_item_id,
        product_id: l.product_id,
        title: l.title,
        qty: Number(l.qty),
        max_qty: l.max_qty,
        price: l.price,
        marking_code: l.marking_code,
      }))
      await offline.saveLocalRefund({
        tenant_id: Number(user?.tenant_id || 0),
        order_id: orderId.value,
        cashier_id: Number(user?.id || 0),
        shift_id: props.shiftId ? Number(props.shiftId) : null,
        reason: reason.value.trim() || null,
        items,
        total_amount: refundTotal.value,
      })
      toast.info('Нет сети — возврат в очереди sync', 'POS Offline')
      emit('done', { order_id: orderId.value, offline: true })
      close()
      return
    }

    await apiPost('/pos/refunds', body)
    toast.success(`Возврат оформлен · ${money(refundTotal.value)}`, '54-ФЗ')
    emit('done', { order_id: orderId.value, offline: false })
    close()
  } catch (e: unknown) {
    const msg =
      (e as { response?: { data?: { message?: string } }; message?: string })?.response?.data
        ?.message ||
      (e as { message?: string })?.message ||
      'Ошибка возврата'
    toast.error(msg, 'Возврат')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[96] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-label="Возврат"
      data-testid="refund-modal"
    >
      <button type="button" class="absolute inset-0 bg-black/75" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 flex max-h-[92vh] w-full max-w-xl flex-col border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
          54-ФЗ · Возврат прихода
        </div>
        <h3 class="mt-1 text-sm font-semibold text-white">Оформление возврата</h3>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          Укажите заказ, отметьте позиции и количество к возврату.
        </p>

        <div class="mt-4 flex gap-2">
          <input
            ref="orderIdRef"
            v-model="orderIdInput"
            type="number"
            min="1"
            data-testid="refund-order-id"
            class="ds-input h-12 flex-1 font-mono"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            placeholder="№ заказа"
            @keydown.enter.prevent="loadOrder"
          >
          <button
            type="button"
            data-testid="refund-load-order"
            class="h-12 border px-3 font-mono text-xs"
            style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px; background: #0b0d10"
            :disabled="loading"
            @click="loadOrder"
          >
            {{ loading ? '…' : 'Загрузить' }}
          </button>
        </div>

        <label class="mt-3 block text-[12px]" style="color: #9ca3af">
          Причина
          <input
            v-model="reason"
            type="text"
            data-testid="refund-reason"
            class="ds-input mt-1 h-11 w-full font-mono text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            placeholder="Брак / отказ клиента…"
          >
        </label>

        <div class="mt-4 min-h-0 flex-1 overflow-y-auto">
          <div v-if="!lines.length" class="py-6 text-center text-sm" style="color: #6b7280">
            Загрузите заказ для выбора позиций
          </div>
          <div v-else class="space-y-2">
            <div
              v-for="line in lines"
              :key="line.order_item_id"
              class="border p-3"
              style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
              :data-testid="`refund-line-${line.order_item_id}`"
            >
              <div class="text-sm text-white">{{ line.title }}</div>
              <div class="mt-1 font-mono text-[10px]" style="color: #6b7280">
                #{{ line.order_item_id }} · {{ money(line.price) }}
                <span v-if="line.marking_code"> · КИЗ</span>
              </div>
              <label class="mt-2 flex items-center gap-2 text-[12px]" style="color: #9ca3af">
                Кол-во (макс {{ line.max_qty }})
                <input
                  v-model.number="line.qty"
                  type="number"
                  min="0"
                  :max="line.max_qty"
                  step="0.001"
                  class="ds-input h-10 w-28 font-mono"
                  style="border-radius: 4px; background: #161b22; border-color: #1f2937"
                  @change="clampQty(line)"
                >
              </label>
            </div>
          </div>
        </div>

        <div class="mt-4 flex items-center justify-between font-mono text-sm">
          <span style="color: #9ca3af">Итого возврат</span>
          <span class="font-bold" style="color: #f59e0b" data-testid="refund-total">{{ money(refundTotal) }}</span>
        </div>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row-reverse">
          <button
            type="button"
            data-testid="refund-confirm"
            class="h-14 flex-1 border font-mono text-sm font-bold uppercase disabled:opacity-50"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="!canSubmit"
            @click="submit"
          >
            {{ submitting ? 'Проведение…' : 'Подтвердить возврат' }}
          </button>
          <button
            type="button"
            class="h-12 border px-4 font-mono text-xs"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
            :disabled="submitting"
            @click="close"
          >
            Esc · Отмена
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
