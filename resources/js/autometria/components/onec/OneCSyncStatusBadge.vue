<script setup lang="ts">
/**
 * Header badge — overall 1C integration state
 */
import { computed } from 'vue'
import type { OneCIntegrationState } from '@/autometria/types/onec'

const props = defineProps<{
  state: OneCIntegrationState
}>()

const meta = computed(() => {
  switch (props.state) {
    case 'syncing':
      return {
        label: 'Идёт обмен…',
        bg: '#172554',
        border: '#3B82F6',
        color: '#93C5FD',
        spin: true,
      }
    case 'error':
      return {
        label: 'Ошибка обмена',
        bg: '#3f0a0a',
        border: '#EF4444',
        color: '#FCA5A5',
        spin: false,
      }
    case 'synced':
      return {
        label: 'Синхронизировано',
        bg: '#052e1a',
        border: '#10B981',
        color: '#6EE7B7',
        spin: false,
      }
    default:
      return {
        label: '1С не настроен',
        bg: '#1f2937',
        border: '#6B7280',
        color: '#D1D5DB',
        spin: false,
      }
  }
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 border px-2 py-1 font-mono text-[11px] leading-none"
    :style="{
      background: meta.bg,
      borderColor: meta.border,
      color: meta.color,
      borderRadius: '4px',
    }"
    :title="meta.label"
  >
    <span
      v-if="meta.spin"
      class="onec-spin inline-block h-2.5 w-2.5 rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <span
      v-else
      class="h-1.5 w-1.5 shrink-0 rounded-full"
      :style="{ background: meta.color }"
      aria-hidden="true"
    />
    {{ meta.label }}
  </span>
</template>

<style scoped>
.onec-spin {
  animation: onec-spin 0.8s linear infinite;
}
@keyframes onec-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
