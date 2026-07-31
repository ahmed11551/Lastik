<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.6)">
    <div
      class="w-full max-w-xl overflow-hidden"
      style="background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius)"
    >
      <div class="flex items-center justify-between border-b px-4 py-3" style="border-color:var(--color-border)">
        <div class="text-sm font-medium" style="color:var(--color-text-primary)">{{ title }}</div>
        <button class="text-xs" style="color:var(--color-text-secondary)" @click="$emit('close')">ESC</button>
      </div>
      <div class="p-4 text-sm" style="color:var(--color-text-secondary)">
        <slot />
      </div>
      <div class="flex justify-end gap-2 border-t px-4 py-3" style="border-color:var(--color-border)">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true }
})
defineEmits(['close'])
</script>
