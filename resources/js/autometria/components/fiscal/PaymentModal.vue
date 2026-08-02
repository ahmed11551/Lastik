<script setup lang="ts">
/**
 * Payment + 54-ФЗ fiscal options (POS)
 */
import { computed, nextTick, ref, watch } from 'vue'
import {
  TAX_SYSTEM_OPTIONS,
  VAT_RATE_OPTIONS,
  type PaymentConfirmPayload,
  type TaxSystemCode,
  type VatRateCode,
} from '@/autometria/types/fiscal'
import {
  formatRuPhoneMask,
  isValidEmail,
  isValidRuPhone,
} from '@/autometria/utils/fiscalFormat'

const props = defineProps<{
  open: boolean
  pending?: boolean
  total: number
  method: string
  methodLabel?: string
  payMode?: 'single' | 'mixed'
  mixed?: { cash: number; card: number; transfer: number } | null
  defaultVatRate?: VatRateCode
  defaultTaxSystem?: TaxSystemCode
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: PaymentConfirmPayload): void
}>()

const electronic = ref(true)
const buyerEmail = ref('')
const buyerPhone = ref('')
const taxSystem = ref<TaxSystemCode>('usn_income')
const vatRate = ref<VatRateCode>('none')
const confirmRef = ref<HTMLButtonElement | null>(null)

const emailError = ref('')
const phoneError = ref('')

const methodTitle = computed(() => props.methodLabel || props.method)

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    electronic.value = true
    buyerEmail.value = ''
    buyerPhone.value = ''
    taxSystem.value = props.defaultTaxSystem || 'usn_income'
    vatRate.value = props.defaultVatRate || 'none'
    emailError.value = ''
    phoneError.value = ''
    await nextTick()
    confirmRef.value?.focus()
  },
)

function money(n: number): string {
  return `₽${Number(n || 0).toLocaleString('ru-RU')}`
}

function close(): void {
  emit('update:open', false)
}

function onPhoneInput(e: Event): void {
  const el = e.target as HTMLInputElement
  buyerPhone.value = formatRuPhoneMask(el.value)
  phoneError.value = ''
}

function validateFiscal(): boolean {
  emailError.value = ''
  phoneError.value = ''
  if (!electronic.value) return true

  const email = buyerEmail.value.trim()
  const phone = buyerPhone.value.trim()

  if (!email && !phone) {
    emailError.value = 'Укажите email или телефон для электронного чека'
    phoneError.value = emailError.value
    return false
  }
  if (email && !isValidEmail(email)) {
    emailError.value = 'Некорректный email'
    return false
  }
  if (phone && !isValidRuPhone(phone)) {
    phoneError.value = 'Формат: +7 (XXX) XXX-XX-XX'
    return false
  }
  return true
}

function submit(): void {
  if (props.pending) return
  if (!validateFiscal()) return

  emit('confirm', {
    method: props.method,
    payMode: props.payMode || 'single',
    mixed: props.mixed || undefined,
    tendered: props.total,
    fiscal: {
      electronic: electronic.value,
      buyer_email: buyerEmail.value.trim(),
      buyer_phone: buyerPhone.value.trim(),
      tax_system: taxSystem.value,
      vat_rate: vatRate.value,
    },
  })
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-label="Оплата и параметры 54-ФЗ"
    >
      <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 max-h-[92vh] w-full max-w-lg overflow-y-auto border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#374151] sm:hidden" />

        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
              Payment // 54-ФЗ
            </div>
            <h3 class="mt-1 text-sm font-semibold text-white">Оплата и фискализация</h3>
            <p class="mt-1 text-xs" style="color: #9ca3af">
              {{ methodTitle }} · к оплате
              <span class="font-mono font-bold" style="color: #f59e0b">{{ money(total) }}</span>
            </p>
          </div>
          <button
            type="button"
            class="h-10 w-10 border font-mono text-sm"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
            aria-label="Закрыть"
            @click="close"
          >
            ✕
          </button>
        </div>

        <div
          v-if="payMode === 'mixed' && mixed"
          class="mt-3 grid grid-cols-3 gap-2 border p-2 font-mono text-[11px]"
          style="background: #0b0d10; border-color: #1f2937; border-radius: 4px; color: #9ca3af"
        >
          <div>Нал {{ money(mixed.cash) }}</div>
          <div>Карта {{ money(mixed.card) }}</div>
          <div>Перевод {{ money(mixed.transfer) }}</div>
        </div>

        <!-- Electronic receipt toggle -->
        <div
          class="mt-4 flex items-center justify-between gap-3 border p-3"
          style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
        >
          <div class="min-w-0">
            <div class="text-sm font-medium text-white">Выдать электронный чек (54-ФЗ)</div>
            <div class="mt-0.5 text-[11px]" style="color: #6b7280">
              Отправка в ОФД · email / SMS покупателю
            </div>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="electronic"
            class="relative h-8 w-14 shrink-0 border transition-colors"
            :style="{
              borderRadius: '4px',
              borderColor: electronic ? '#f59e0b' : '#374151',
              background: electronic ? '#78350f' : '#161b22',
            }"
            @click="electronic = !electronic"
          >
            <span
              class="absolute top-0.5 h-6 w-6 transition-transform"
              :style="{
                left: '2px',
                transform: electronic ? 'translateX(24px)' : 'translateX(0)',
                background: electronic ? '#f59e0b' : '#6b7280',
                borderRadius: '2px',
              }"
            />
          </button>
        </div>

        <div v-if="electronic" class="mt-3 space-y-3">
          <label class="block text-[12px]" style="color: #9ca3af">
            Email покупателя
            <input
              v-model="buyerEmail"
              type="email"
              inputmode="email"
              autocomplete="email"
              class="ds-input mt-1 h-12 w-full text-base sm:h-11 sm:text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
              :class="{ 'border-red-500': emailError }"
              placeholder="client@example.ru"
              @input="emailError = ''"
            >
            <span v-if="emailError" class="mt-1 block text-[11px]" style="color: #ef4444">{{ emailError }}</span>
          </label>

          <label class="block text-[12px]" style="color: #9ca3af">
            Телефон покупателя
            <input
              :value="buyerPhone"
              type="tel"
              inputmode="tel"
              autocomplete="tel"
              class="ds-input mt-1 h-12 w-full font-mono text-base sm:h-11 sm:text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
              :class="{ 'border-red-500': phoneError }"
              placeholder="+7 (999) 123-45-67"
              @input="onPhoneInput"
            >
            <span v-if="phoneError" class="mt-1 block text-[11px]" style="color: #ef4444">{{ phoneError }}</span>
          </label>

          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-[12px]" style="color: #9ca3af">
              Система налогообложения (СНО)
              <select
                v-model="taxSystem"
                class="ds-input mt-1 h-12 w-full text-sm sm:h-11"
                style="border-radius: 4px; background: #161b22; border-color: #1f2937; color: #fff"
              >
                <option v-for="o in TAX_SYSTEM_OPTIONS" :key="o.id" :value="o.id">{{ o.label }}</option>
              </select>
            </label>

            <label class="block text-[12px]" style="color: #9ca3af">
              Ставка НДС (позиции)
              <select
                v-model="vatRate"
                class="ds-input mt-1 h-12 w-full text-sm sm:h-11"
                style="border-radius: 4px; background: #161b22; border-color: #1f2937; color: #fff"
              >
                <option v-for="o in VAT_RATE_OPTIONS" :key="o.id" :value="o.id">{{ o.label }}</option>
              </select>
            </label>
          </div>
        </div>

        <div class="mt-5 flex flex-col gap-2 sm:flex-row-reverse">
          <button
            ref="confirmRef"
            type="button"
            class="h-14 flex-1 border px-3 font-mono text-sm font-bold uppercase tracking-wide disabled:opacity-50 sm:h-12"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="pending"
            @click="submit"
          >
            {{ pending ? 'Проведение…' : 'Подтвердить оплату' }}
          </button>
          <button
            type="button"
            class="h-12 border px-4 font-mono text-xs sm:h-12"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
            :disabled="pending"
            @click="close"
          >
            Отмена
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
