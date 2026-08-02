<script setup lang="ts">
/**
 * POS Marking — scan GS1 DataMatrix (Честный Знак) before cart add.
 * Live verify via POST /api/v1/regulatory/marking/verify
 */
import { nextTick, ref, watch } from 'vue'
import { looksLikeDataMatrix } from '@/services/marking/dataMatrix'
import { apiPost } from '@/autometria/api/client'

const props = defineProps<{
  open: boolean
  productTitle?: string
  productId?: number | null
  pending?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', markingCode: string): void
  (e: 'cancel'): void
}>()

const code = ref('')
const error = ref('')
const validHint = ref('')
const verifying = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

watch(
  () => props.open,
  async (v) => {
    if (!v) return
    code.value = ''
    error.value = ''
    validHint.value = ''
    verifying.value = false
    await nextTick()
    inputRef.value?.focus()
  },
)

function close(): void {
  emit('update:open', false)
  emit('cancel')
}

async function submit(): Promise<void> {
  const c = code.value.trim()
  if (!looksLikeDataMatrix(c)) {
    error.value = 'Неверный формат DataMatrix (ожидаются AI 01 + AI 21)'
    validHint.value = ''
    return
  }

  verifying.value = true
  error.value = ''
  validHint.value = ''
  try {
    const payload = await apiPost('/regulatory/marking/verify', {
      code: c,
      product_id: props.productId || undefined,
    })
    const data = payload?.data || payload
    if (!data?.valid) {
      error.value = payload?.message || 'Марка отклонена'
      return
    }
    validHint.value = `ОК · GTIN ${data.gtin} · ${data.serial} · ${data.chestny_znak || 'VALID'}`
    emit('confirm', c)
    emit('update:open', false)
  } catch (e: any) {
    error.value =
      e?.response?.data?.message ||
      e?.message ||
      'Марка не прошла проверку Честного Знака'
    validHint.value = ''
  } finally {
    verifying.value = false
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
      aria-label="Сканирование марки"
      data-testid="marking-scan-modal"
    >
      <button type="button" class="absolute inset-0 bg-black/75" aria-label="Закрыть" @click="close" />
      <div
        class="relative z-10 w-full max-w-lg border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:rounded sm:border"
        style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
      >
        <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
          Честный Знак · DataMatrix
        </div>
        <h3 class="mt-1 text-sm font-semibold text-white">Сканируйте марку</h3>
        <p class="mt-1 text-xs" style="color: #9ca3af">
          {{ productTitle || 'Маркированный товар' }} — отсканируйте код КИЗ 2D-сканером
        </p>

        <label class="mt-4 block text-[12px]" style="color: #9ca3af">
          Код маркировки
          <input
            ref="inputRef"
            v-model="code"
            type="text"
            autocomplete="off"
            data-testid="marking-code-input"
            class="ds-input mt-1 h-14 w-full font-mono text-sm"
            :style="{
              borderRadius: '4px',
              background: '#161b22',
              borderColor: error ? '#f87171' : validHint ? '#34d399' : '#1f2937',
            }"
            placeholder="01…21…"
            @keydown.enter.prevent="submit"
          >
        </label>
        <p v-if="error" class="mt-2 text-xs" style="color: #fca5a5" data-testid="marking-error">{{ error }}</p>
        <p v-else-if="validHint" class="mt-2 text-xs" style="color: #6ee7b7" data-testid="marking-ok">{{ validHint }}</p>

        <div class="mt-5 flex flex-col gap-2 sm:flex-row-reverse">
          <button
            type="button"
            data-testid="marking-confirm"
            class="h-14 flex-1 border font-mono text-sm font-bold uppercase disabled:opacity-50"
            style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
            :disabled="pending || verifying"
            @click="submit"
          >
            {{ verifying || pending ? 'Проверка…' : 'Подтвердить марку' }}
          </button>
          <button
            type="button"
            class="h-12 border px-4 font-mono text-xs"
            style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
            :disabled="pending || verifying"
            @click="close"
          >
            Esc · Отмена
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
