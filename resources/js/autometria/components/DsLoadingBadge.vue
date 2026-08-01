<script setup>
defineProps({
  label: { type: String, default: 'Loading' },
  tone: { type: String, default: 'amber' },
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 border px-2 py-1 font-mono text-[10px] uppercase tracking-wide"
    style="border-color: #1f2937; border-radius: 4px; background: #161b22; color: #f59e0b"
  >
    <span
      class="inline-block h-1.5 w-1.5 animate-pulse"
      style="background: #f59e0b; border-radius: 4px"
    />
    {{ label }}
  </span>
</template>
