<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Purchase drafts modal (list of supplier drafts)
 */
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useProcurementStore } from '@/autometria/stores/procurementStore'
import PurchaseDraftDetail from './PurchaseDraftDetail.vue'

defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
  approve: [id: string]
  send: [id: string]
}>()

const store = useProcurementStore()
const { purchaseDrafts, activeDraft } = storeToRefs(store)

const drafts = computed(() => purchaseDrafts.value || [])

function selectDraft(id: string) {
  const d = drafts.value.find((x) => x.id === id)
  if (d) store.setActiveDraft(d)
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center"
    style="background: rgba(9, 13, 22, 0.82); padding: var(--safe-top, 0) var(--safe-right, 0) var(--safe-bottom, 0) var(--safe-left, 0)"
    data-testid="purchase-drafts-modal"
    @click.self="emit('close')"
  >
    <div
      class="flex max-h-[min(92dvh,860px)] w-full max-w-4xl flex-col overflow-hidden border shadow-2xl"
      style="background: #0f172a; border-color: #1e293b; border-radius: 4px 4px 0 0"
      role="dialog"
      aria-modal="true"
      aria-label="Черновики авто-заказов"
    >
      <header
        class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3"
        style="border-color: #1e293b"
      >
        <div>
          <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
            Procurement // auto drafts
          </div>
          <h2 class="text-sm font-medium text-white">Черновики заказов поставщикам</h2>
        </div>
        <button
          type="button"
          class="grid h-11 w-11 place-items-center border font-mono text-sm sm:h-9 sm:w-9"
          style="border-color: #1e293b; color: #a8b3c7; border-radius: 4px"
          aria-label="Закрыть"
          @click="emit('close')"
        >
          ✕
        </button>
      </header>

      <div class="scroll-y-contain flex-1 space-y-3 overflow-y-auto p-3 sm:p-4">
        <p v-if="!drafts.length" class="py-10 text-center font-mono text-[11px]" style="color: #6b7280">
          Нет черновиков — сгенерируйте из прогноза спроса
        </p>

        <div v-if="drafts.length > 1" class="flex flex-wrap gap-2">
          <button
            v-for="d in drafts"
            :key="d.id"
            type="button"
            class="h-10 border px-3 font-mono text-[10px] sm:h-8"
            :style="{
              borderColor: activeDraft?.id === d.id ? '#f59e0b' : '#1e293b',
              color: activeDraft?.id === d.id ? '#f59e0b' : '#a8b3c7',
              background: '#090d16',
              borderRadius: '4px',
            }"
            @click="selectDraft(d.id)"
          >
            {{ d.supplier_name.slice(0, 18) }} · {{ d.lines.length }}
          </button>
        </div>

        <PurchaseDraftDetail
          v-for="d in drafts"
          v-show="!activeDraft || activeDraft.id === d.id || drafts.length === 1"
          :key="d.id"
          :draft="d"
          @approve="emit('approve', $event)"
          @send="emit('send', $event)"
        />
      </div>
    </div>
  </div>
</template>
