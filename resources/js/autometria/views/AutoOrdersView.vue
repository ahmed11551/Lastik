<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Smart auto-orders workspace (v1.4.0 Sprint 3)
 */
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import { useProcurementStore } from '@/autometria/stores/procurementStore'
import PurchaseDraftDetail from './PurchaseDraftDetail.vue'
import PurchaseDraftsModal from './PurchaseDraftsModal.vue'
import SupplierSendModal from './SupplierSendModal.vue'
import { toast } from '@/autometria/api/toast'

const emit = defineEmits<{
  navigate: [item: { id: string }]
}>()

const store = useProcurementStore()
const { purchaseDrafts, activeDraft, isGenerating, error } = storeToRefs(store)

const draftsModalOpen = ref(false)
const sendOpen = ref(false)
const sendDraftId = ref<string | null>(null)

const sendDraft = computed(
  () => purchaseDrafts.value.find((d) => d.id === sendDraftId.value) || activeDraft.value,
)

async function generate() {
  try {
    const created = await store.generateAutoDrafts({ syncRecalc: true })
    toast.success(`Черновиков: ${created.length}`, 'Авто-заказы')
    if (created.length) draftsModalOpen.value = true
  } catch {
    toast.error(error.value || 'Ошибка генерации', 'Авто-заказы')
  }
}

async function onApprove(id: string) {
  try {
    await store.approveDraft(id)
    toast.success('Черновик утверждён (Supplier Order)', 'Авто-заказы')
  } catch (e: any) {
    toast.error(e?.message || error.value || 'Ошибка утверждения', 'Авто-заказы')
  }
}

function onSend(id: string) {
  sendDraftId.value = id
  const d = purchaseDrafts.value.find((x) => x.id === id)
  if (d) store.setActiveDraft(d)
  sendOpen.value = true
}

onMounted(() => {
  void store.fetchSuppliers().catch(() => undefined)
  if (!purchaseDrafts.value.length) {
    void store.fetchForecast().catch(() => undefined)
  }
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden pb-[max(0.75rem,var(--safe-bottom))]"
    style="background: var(--brand-desk, #090d16); min-height: 100%"
    data-testid="auto-orders-view"
  >
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Procurement // умные авто-закупки · отправка поставщикам
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Авто-заказы</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Черновики из ROP · правка qty · email / CSV / PDF / Telegram
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <DsLoadingBadge v-if="isGenerating" label="Generating" />
        <button
          type="button"
          class="h-11 border px-3 font-mono text-[11px] sm:h-9"
          style="border-color: #1e293b; color: #a8b3c7; border-radius: 4px; background: #090d16"
          @click="emit('navigate', { id: 'demand_forecast' })"
        >
          ← Прогноз
        </button>
        <button
          type="button"
          class="h-12 flex-1 border px-4 font-mono text-[11px] font-bold uppercase sm:h-9 sm:flex-none"
          style="border-color: #f59e0b; background: #f59e0b; color: #090d16; border-radius: 4px"
          :disabled="isGenerating"
          @click="generate"
        >
          ⚡ Сгенерировать
        </button>
        <button
          type="button"
          class="h-11 border px-3 font-mono text-[11px] sm:h-9"
          style="border-color: #1e293b; color: #f59e0b; border-radius: 4px; background: #090d16"
          :disabled="!purchaseDrafts.length"
          @click="draftsModalOpen = true"
        >
          Модалка ({{ purchaseDrafts.length }})
        </button>
      </div>
    </div>

    <div v-if="!purchaseDrafts.length" class="border p-8 text-center" style="background: #0f172a; border-color: #1e293b; border-radius: 4px">
      <p class="font-mono text-[12px]" style="color: #9ca3af">
        Черновиков пока нет. Сгенерируйте из прогноза спроса или нажмите «Сгенерировать».
      </p>
    </div>

    <div class="space-y-3">
      <PurchaseDraftDetail
        v-for="d in purchaseDrafts"
        :key="d.id"
        :draft="d"
        @approve="onApprove"
        @send="onSend"
      />
    </div>

    <PurchaseDraftsModal
      :open="draftsModalOpen"
      @close="draftsModalOpen = false"
      @approve="onApprove"
      @send="onSend"
    />

    <SupplierSendModal
      :open="sendOpen"
      :draft="sendDraft"
      @close="sendOpen = false"
      @sent="() => undefined"
    />
  </div>
</template>
