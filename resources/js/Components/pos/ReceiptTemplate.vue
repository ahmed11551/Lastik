<script setup lang="ts">
/**
 * 54-ФЗ thermal receipt template (58/80mm) for browser print
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import type { PosReceipt } from '@/services/printer/types'
import { registerBrowserPrintHost } from '@/services/printer/BrowserDriver'
import { resolveFiscalQrSrc } from '@/autometria/utils/fiscalFormat'

const props = defineProps<{
  receipt?: PosReceipt | null
  paperMm?: 58 | 80
  registerHost?: boolean
}>()

const local = ref<PosReceipt | null>(props.receipt || null)
const paper = computed(() => props.paperMm || local.value?.paper_mm || 80)

watch(
  () => props.receipt,
  (v) => {
    if (v) local.value = v
  },
)

const qrSrc = computed(() => {
  const r = local.value
  if (!r) return null
  if (r.qr_payload) {
    return resolveFiscalQrSrc({
      fiscal_storage_number: r.fn,
      fiscal_document_number: r.fd,
      fiscal_sign: r.fpd,
      payload: { total: r.total },
      fiscalized_at: r.datetime,
      qr_code_url: null,
    }) || null
  }
  return resolveFiscalQrSrc({
    fiscal_storage_number: r.fn,
    fiscal_document_number: r.fd,
    fiscal_sign: r.fpd,
    payload: { total: r.total },
    fiscalized_at: r.datetime,
  })
})

function money(n: number | undefined): string {
  return Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function vatLabel(code: string): string {
  if (code === 'none') return 'Без НДС'
  if (code === '0') return 'НДС 0%'
  return `НДС ${code}%`
}

function doPrint(): void {
  window.print()
}

onMounted(() => {
  if (props.registerHost !== false) {
    registerBrowserPrintHost({
      setReceipt: (r) => {
        local.value = r
      },
      print: doPrint,
    })
  }
})

onUnmounted(() => {
  if (props.registerHost !== false) registerBrowserPrintHost(null)
})

defineExpose({ setReceipt: (r: PosReceipt) => { local.value = r }, print: doPrint })
</script>

<template>
  <div class="receipt-print-root" aria-hidden="true">
    <div
      v-if="local"
      class="receipt-tape"
      :class="paper === 58 ? 'receipt-tape--58' : 'receipt-tape--80'"
    >
      <div class="text-center font-bold uppercase">{{ local.organization_name }}</div>
      <div class="text-center">ИНН {{ local.inn }}</div>
      <div class="text-center text-[11px]">{{ local.kkt_address }}</div>
      <div class="rule" />
      <div class="row"><span>Смена</span><span>{{ local.shift_number }}</span></div>
      <div class="row"><span>Чек</span><span>{{ local.receipt_number }}</span></div>
      <div class="row"><span>Кассир</span><span>{{ local.cashier_name }}</span></div>
      <div class="row"><span>Дата</span><span>{{ local.datetime }}</span></div>
      <div class="rule" />
      <div v-for="(it, i) in local.items" :key="i" class="mb-1">
        <div>{{ it.title }}</div>
        <div class="row text-[11px]">
          <span>{{ money(it.price) }} × {{ it.qty }} · {{ vatLabel(it.vat_rate) }}</span>
          <span class="font-bold">{{ money(it.sum) }}</span>
        </div>
      </div>
      <div class="rule" />
      <div class="row text-base font-bold"><span>ИТОГО</span><span>{{ money(local.total) }}</span></div>
      <div v-if="local.cash_amount != null" class="row"><span>Наличными</span><span>{{ money(local.cash_amount) }}</span></div>
      <div v-if="local.card_amount != null" class="row"><span>Картой</span><span>{{ money(local.card_amount) }}</span></div>
      <div v-if="local.change != null" class="row"><span>Сдача</span><span>{{ money(local.change) }}</span></div>
      <div class="rule" />
      <div class="row"><span>ФН</span><span>{{ local.fn || '—' }}</span></div>
      <div class="row"><span>ФД</span><span>{{ local.fd || '—' }}</span></div>
      <div class="row"><span>ФПД</span><span class="break-all text-right">{{ local.fpd || '—' }}</span></div>
      <div v-if="qrSrc" class="mt-2 flex justify-center">
        <img :src="qrSrc" width="120" height="120" alt="QR ФНС">
      </div>
      <div class="mt-2 text-center text-[10px]">Чек сформирован в соответствии с 54-ФЗ</div>
    </div>
  </div>
</template>

<style scoped>
.receipt-print-root {
  position: fixed;
  left: -9999px;
  top: 0;
  pointer-events: none;
}

.receipt-tape {
  background: #fff;
  color: #000;
  font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 12px;
  line-height: 1.35;
  padding: 10px 12px;
}

.receipt-tape--58 { width: 58mm; }
.receipt-tape--80 { width: 80mm; }

.rule {
  border-top: 1px dashed #94a3b8;
  margin: 8px 0;
}

.row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}

@media print {
  @page { margin: 0; size: auto; }

  :global(body *) {
    visibility: hidden !important;
  }

  .receipt-print-root,
  .receipt-print-root * {
    visibility: visible !important;
  }

  .receipt-print-root {
    position: static !important;
    left: auto !important;
  }

  .receipt-tape {
    margin: 0 auto;
  }
}
</style>
