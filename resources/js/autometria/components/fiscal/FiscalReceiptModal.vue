<script setup lang="ts">
/**
 * Fiscal receipt viewer — thermal-tape layout + print 58/80mm
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import type { FiscalReceipt } from '@/autometria/types/fiscal'
import { FISCAL_STATUS_LABEL, VAT_RATE_OPTIONS } from '@/autometria/types/fiscal'
import {
  buildOfdQrPayload,
  resolveFiscalQrSrc,
} from '@/autometria/utils/fiscalFormat'
import FiscalReceiptStatusBadge from './FiscalReceiptStatusBadge.vue'

const props = defineProps<{
  open: boolean
  receipt: FiscalReceipt | null
  pending?: boolean
  /** Paper width for print CSS */
  paperMm?: 58 | 80
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'retry', receiptId: number): void
  (e: 'print'): void
}>()

const paper = ref<58 | 80>(80)
const printRoot = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  (v) => {
    if (v) paper.value = props.paperMm || 80
  },
)

const payload = computed(() => props.receipt?.payload || {})
const items = computed(() => payload.value.items || [])
const total = computed(() => Number(payload.value.total ?? items.value.reduce((s, i) => s + Number(i.sum || 0), 0)))

function itemQty(it: { qty?: number; quantity?: number }): number {
  return Number(it.quantity ?? it.qty ?? 0)
}

const qrSrc = computed(() => (props.receipt ? resolveFiscalQrSrc(props.receipt) : null))
const ofdPayload = computed(() => (props.receipt ? buildOfdQrPayload(props.receipt) : null))

const statusLabel = computed(() => {
  const s = props.receipt?.status
  return s ? FISCAL_STATUS_LABEL[s] : '—'
})

const isPreview = computed(() => {
  const s = props.receipt?.status
  return !s || s === 'pending' || s === 'failed'
})

function vatLabel(code: string): string {
  return VAT_RATE_OPTIONS.find((o) => o.id === code)?.label || code || '—'
}

function money(n: number | string | null | undefined): string {
  return `${Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function close(): void {
  emit('update:open', false)
}

function doPrint(): void {
  emit('print')
  window.print()
}

function onKey(e: KeyboardEvent): void {
  if (!props.open) return
  if (e.key === 'Escape') {
    e.preventDefault()
    close()
    return
  }
  if (e.key === 'Enter' || e.key === ' ') {
    const tag = (e.target as HTMLElement | null)?.tagName?.toLowerCase()
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return
    e.preventDefault()
    doPrint()
  }
}

onMounted(() => window.addEventListener('keydown', onKey, true))
onUnmounted(() => window.removeEventListener('keydown', onKey, true))
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && receipt"
      class="fiscal-modal-root fixed inset-0 z-[95] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-label="Фискальный чек"
    >
      <button type="button" class="absolute inset-0 bg-black/70 print:hidden" aria-label="Закрыть" @click="close" />

      <div
        class="relative z-10 flex max-h-[94vh] w-full max-w-md flex-col overflow-hidden sm:rounded"
        style="background: #11151a"
      >
        <div class="flex items-center justify-between gap-2 border-b px-3 py-2 print:hidden" style="border-color: #1f2937">
          <div class="min-w-0">
            <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
              {{ isPreview ? 'Предчек' : 'Фискальный чек' }}
            </div>
            <FiscalReceiptStatusBadge
              class="mt-1"
              :receipt="receipt"
              :pending="pending"
              @retry="(id) => emit('retry', id)"
            />
          </div>
          <div class="flex shrink-0 items-center gap-1">
            <div class="flex border" style="border-color: #1f2937; border-radius: 4px; overflow: hidden">
              <button
                type="button"
                class="h-9 px-2 font-mono text-[11px]"
                :style="{
                  background: paper === 58 ? '#f59e0b' : '#0b0d10',
                  color: paper === 58 ? '#0b0d10' : '#9ca3af',
                }"
                @click="paper = 58"
              >
                58мм
              </button>
              <button
                type="button"
                class="h-9 px-2 font-mono text-[11px]"
                :style="{
                  background: paper === 80 ? '#f59e0b' : '#0b0d10',
                  color: paper === 80 ? '#0b0d10' : '#9ca3af',
                }"
                @click="paper = 80"
              >
                80мм
              </button>
            </div>
            <button
              type="button"
              class="h-9 w-9 border font-mono text-sm"
              style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
              @click="close"
            >
              ✕
            </button>
          </div>
        </div>

        <div class="fiscal-scroll min-h-0 flex-1 overflow-y-auto px-3 py-4 print:overflow-visible print:p-0">
          <!-- Thermal tape -->
          <div
            ref="printRoot"
            class="fiscal-tape mx-auto"
            :class="paper === 58 ? 'fiscal-tape--58' : 'fiscal-tape--80'"
            :data-paper="paper"
          >
            <div class="fiscal-tape__edge fiscal-tape__edge--top" aria-hidden="true" />
            <div class="fiscal-tape__body">
              <div class="text-center font-bold uppercase tracking-wide">
                {{ payload.organization_name || 'AUTOMETRIA' }}
              </div>
              <div class="mt-1 text-center">
                ИНН {{ payload.inn || '—' }}
              </div>
              <div class="mt-0.5 text-center text-[11px] leading-snug">
                {{ payload.settlement_address || 'Адрес расчётов не указан' }}
              </div>

              <div class="fiscal-rule" />

              <div class="flex justify-between gap-2">
                <span>Кассир</span>
                <span class="text-right">{{ payload.cashier_name || '—' }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span>Смена</span>
                <span>{{ payload.shift_number ?? receipt.cash_shift_id ?? '—' }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span>Заказ</span>
                <span>#{{ receipt.order_id || '—' }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span>Статус</span>
                <span>{{ statusLabel }}</span>
              </div>

              <div class="fiscal-rule" />

              <template v-if="items.length">
                <div
                  v-for="(it, idx) in items"
                  :key="idx"
                  class="mb-2"
                >
                  <div class="leading-snug">{{ it.name }}</div>
                  <div class="flex justify-between gap-2 text-[11px]">
                    <span>{{ money(it.price) }} × {{ itemQty(it) }} · {{ vatLabel(it.vat_rate) }}</span>
                    <span class="font-bold">{{ money(it.sum) }}</span>
                  </div>
                </div>
              </template>
              <div v-else class="py-2 text-center text-[11px] opacity-70">
                Позиции в payload отсутствуют
              </div>

              <div class="fiscal-rule" />

              <div class="flex justify-between gap-2 text-base font-bold">
                <span>ИТОГО</span>
                <span>{{ money(total) }}</span>
              </div>

              <div class="fiscal-rule" />

              <div class="text-center font-bold">ФИСКАЛЬНЫЙ БЛОК</div>
              <div class="mt-1 flex justify-between gap-2">
                <span>ФД</span>
                <span>{{ receipt.fiscal_document_number || '—' }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span>ФН</span>
                <span>{{ receipt.fiscal_storage_number || '—' }}</span>
              </div>
              <div class="flex justify-between gap-2">
                <span>ФП</span>
                <span class="break-all text-right">{{ receipt.fiscal_sign || '—' }}</span>
              </div>
              <div v-if="receipt.error_message" class="mt-1 text-[11px]" style="color: #b91c1c">
                {{ receipt.error_message }}
              </div>

              <div v-if="qrSrc || ofdPayload" class="mt-3 flex flex-col items-center gap-1">
                <img
                  v-if="qrSrc"
                  :src="qrSrc"
                  alt="QR код чека"
                  class="fiscal-qr"
                  width="148"
                  height="148"
                >
                <div v-if="ofdPayload" class="break-all px-1 text-center text-[9px] leading-tight opacity-70">
                  {{ ofdPayload }}
                </div>
              </div>

              <div class="mt-3 text-center text-[10px] opacity-60">
                {{ isPreview ? 'ПРЕДЧЕК · НЕ ФИСКАЛЬНЫЙ ДОКУМЕНТ' : 'Чек сформирован в соответствии с 54-ФЗ' }}
              </div>
            </div>
            <div class="fiscal-tape__edge fiscal-tape__edge--bottom" aria-hidden="true" />
          </div>
        </div>

        <div class="flex gap-2 border-t p-3 print:hidden" style="border-color: #1f2937">
          <button
            type="button"
            class="h-14 flex-1 border px-3 font-mono text-sm font-bold uppercase tracking-wide sm:h-12"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            @click="doPrint"
          >
            Распечатать {{ isPreview ? 'предчек' : 'чек' }}
          </button>
          <button
            type="button"
            class="h-14 border px-3 font-mono text-xs sm:h-12"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
            @click="close"
          >
            Закрыть
          </button>
        </div>
        <p class="px-3 pb-3 text-center font-mono text-[10px] print:hidden" style="color: #6b7280">
          Enter / Space — печать · Esc — закрыть
        </p>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.fiscal-tape {
  background: #f8fafc;
  color: #0b0d10;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
  font-size: 12px;
  line-height: 1.35;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
}

.fiscal-tape--58 {
  width: 58mm;
  max-width: 100%;
}

.fiscal-tape--80 {
  width: 80mm;
  max-width: 100%;
}

.fiscal-tape__body {
  padding: 10px 12px 14px;
}

.fiscal-rule {
  border-top: 1px dashed #94a3b8;
  margin: 8px 0;
}

.fiscal-qr {
  image-rendering: pixelated;
  border: 1px solid #e2e8f0;
  background: #fff;
}

/* Perforated edges */
.fiscal-tape__edge {
  height: 10px;
  background:
    radial-gradient(circle at 6px 50%, transparent 4px, #f8fafc 4.5px) 0 0 / 12px 10px repeat-x,
    #11151a;
}

.fiscal-tape__edge--top {
  transform: scaleY(-1);
}

@media print {
  @page {
    size: auto;
    margin: 0;
  }

  :global(body *) {
    visibility: hidden !important;
  }

  .fiscal-modal-root,
  .fiscal-modal-root * {
    visibility: visible !important;
  }

  .fiscal-modal-root {
    position: static !important;
    inset: auto !important;
    display: block !important;
    background: #fff !important;
  }

  .fiscal-scroll {
    overflow: visible !important;
    max-height: none !important;
  }

  .fiscal-tape {
    box-shadow: none !important;
    margin: 0 auto;
  }

  .fiscal-tape--58 {
    width: 58mm !important;
  }

  .fiscal-tape--80 {
    width: 80mm !important;
  }

  .fiscal-tape__edge {
    background: radial-gradient(circle at 6px 50%, transparent 4px, #fff 4.5px) 0 0 / 12px 10px repeat-x;
  }
}
</style>
