<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Send draft to supplier (email / CSV / PDF / Telegram)
 */
import { computed, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import {
  useProcurementStore,
  type PurchaseDraft,
  type SendMethod,
} from '@/autometria/stores/procurementStore'
import { toast } from '@/autometria/api/toast'

const props = defineProps<{
  open: boolean
  draft: PurchaseDraft | null
}>()

const emit = defineEmits<{
  close: []
  sent: [id: string]
}>()

const store = useProcurementStore()
const { sending, suppliers } = storeToRefs(store)

const method = ref<SendMethod>('email')
const preview = ref({ subject: '', body: '' })

const methods: Array<{ id: SendMethod; label: string; hint: string }> = [
  { id: 'email', label: 'Email', hint: 'Mailto + превью письма' },
  { id: 'csv', label: 'CSV', hint: 'Скачать таблицу позиций' },
  { id: 'pdf', label: 'PDF', hint: 'Печать / сохранить как PDF' },
  { id: 'telegram', label: 'Telegram', hint: 'Шеринг + буфер обмена' },
]

const supplierEmail = computed(() => {
  if (!props.draft?.supplier_id) return ''
  return suppliers.value.find((s) => Number(s.id) === Number(props.draft?.supplier_id))?.email || ''
})

watch(
  () => [props.open, props.draft?.id, method.value] as const,
  () => {
    if (props.open && props.draft) {
      preview.value = store.buildEmailPreview(props.draft)
    }
  },
  { immediate: true },
)

async function submit() {
  if (!props.draft) return
  try {
    await store.sendDraftToSupplier(props.draft.id, method.value)
    toast.success(`Отправка: ${method.value}`, 'Поставщик')
    emit('sent', props.draft.id)
    emit('close')
  } catch (e: any) {
    toast.error(e?.message || 'Ошибка отправки', 'Поставщик')
  }
}
</script>

<template>
  <div
    v-if="open && draft"
    class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
    style="background: rgba(9, 13, 22, 0.88); padding: var(--safe-top, 0) var(--safe-right, 0) var(--safe-bottom, 0) var(--safe-left, 0)"
    data-testid="supplier-send-modal"
    @click.self="emit('close')"
  >
    <div
      class="flex max-h-[min(90dvh,720px)] w-full max-w-lg flex-col overflow-hidden border"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px 4px 0 0"
      role="dialog"
      aria-modal="true"
      aria-label="Отправка поставщику"
    >
      <header class="flex items-center justify-between border-b px-4 py-3" style="border-color: #1e293b">
        <div>
          <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
            Supplier send
          </div>
          <h2 class="text-sm font-medium text-white">Отправка поставщику</h2>
          <p class="mt-0.5 font-mono text-[10px]" style="color: #6b7280">{{ draft.supplier_name }}</p>
        </div>
        <button
          type="button"
          class="grid h-11 w-11 place-items-center border sm:h-9 sm:w-9"
          style="border-color: #1e293b; color: #a8b3c7; border-radius: 4px"
          @click="emit('close')"
        >
          ✕
        </button>
      </header>

      <div class="scroll-y-contain flex-1 space-y-3 overflow-y-auto p-4">
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="m in methods"
            :key="m.id"
            type="button"
            class="border p-3 text-left"
            :style="{
              borderColor: method === m.id ? '#f59e0b' : '#1e293b',
              background: method === m.id ? 'color-mix(in srgb, #f59e0b 12%, #090d16)' : '#090d16',
              borderRadius: '4px',
            }"
            @click="method = m.id"
          >
            <div class="font-mono text-[12px] font-bold text-white">{{ m.label }}</div>
            <div class="mt-1 text-[10px]" style="color: #9ca3af">{{ m.hint }}</div>
          </button>
        </div>

        <div
          v-if="method === 'email'"
          class="space-y-2 border p-3"
          style="background: #090d16; border-color: #1e293b; border-radius: 4px"
        >
          <div class="font-mono text-[10px] uppercase" style="color: #6b7280">Превью письма</div>
          <div class="font-mono text-[11px]" style="color: #a8b3c7">
            To: {{ supplierEmail || '— укажите email у поставщика —' }}
          </div>
          <div class="font-mono text-[12px] text-white">{{ preview.subject }}</div>
          <pre
            class="max-h-48 overflow-y-auto whitespace-pre-wrap border p-2 font-mono text-[11px] leading-relaxed"
            style="border-color: #1e293b; color: #e8edf5; background: #0f172a; border-radius: 4px"
          >{{ preview.body }}</pre>
        </div>

        <p v-else class="font-mono text-[11px]" style="color: #9ca3af">
          <template v-if="method === 'csv'">Будет скачан CSV с позициями и количествами.</template>
          <template v-else-if="method === 'pdf'">Откроется окно печати — сохраните как PDF.</template>
          <template v-else>Текст заказа скопируется в буфер и откроется Telegram Share.</template>
        </p>
      </div>

      <footer class="shrink-0 border-t p-3" style="border-color: #1e293b">
        <button
          type="button"
          class="h-12 w-full border font-mono text-[12px] font-bold uppercase tracking-wide"
          style="border-color: #f59e0b; background: #f59e0b; color: #090d16; border-radius: 4px"
          :disabled="sending"
          @click="submit"
        >
          {{ sending ? 'Отправка…' : 'Отправить поставщику' }}
        </button>
      </footer>
    </div>
  </div>
</template>
