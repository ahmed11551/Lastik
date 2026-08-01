<script setup>
/**
 * Industrial Precision toast stack
 */
import { toast } from '@/autometria/api/toast'

const TONE = {
  danger: { border: '#EF4444', accent: '#EF4444' },
  warning: { border: '#F59E0B', accent: '#F59E0B' },
  success: { border: '#10B981', accent: '#10B981' },
  info: { border: '#1F2937', accent: '#9CA3AF' },
}
</script>

<template>
  <div
    class="pointer-events-none fixed bottom-4 right-4 z-[80] flex w-[min(100vw-2rem,360px)] flex-col gap-2"
    aria-live="polite"
  >
    <div
      v-for="item in toast.state.items"
      :key="item.id"
      class="pointer-events-auto border px-3 py-2.5 shadow-none"
      :style="{
        background: '#11151A',
        borderColor: TONE[item.tone]?.border || '#1F2937',
        borderRadius: '4px',
      }"
    >
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <div
            class="font-mono text-[10px] font-medium uppercase tracking-[0.12em]"
            :style="{ color: TONE[item.tone]?.accent || '#F59E0B' }"
          >
            {{ item.title }}
          </div>
          <p class="mt-1 text-xs font-medium text-white">
            {{ item.message }}
          </p>
        </div>
        <button
          type="button"
          class="shrink-0 font-mono text-[10px]"
          style="color: #6b7280"
          @click="toast.dismiss(item.id)"
        >
          ✕
        </button>
      </div>
    </div>
  </div>
</template>
