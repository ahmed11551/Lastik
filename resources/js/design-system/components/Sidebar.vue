<template>
  <aside class="flex h-screen w-64 flex-col border-r" style="background:var(--color-surface);border-color:var(--color-border)">
    <div class="px-4 py-3 text-sm font-semibold" style="color:var(--color-text-secondary)">
      {{ brand }}
    </div>
    <nav class="flex-1 overflow-y-auto px-2">
      <button
        v-for="item in items"
        :key="item.id"
        :class="['w-full text-left px-3 py-2 text-sm rounded-md', active === item.id ? 'font-medium' : '']"
        :style="active === item.id ? { background: 'var(--color-surface-elevated)', color: 'var(--color-text-primary)' } : { color: 'var(--color-text-secondary)' }"
        @click="$emit('select', item)"
      >
        {{ item.label }}
      </button>
    </nav>
    <div class="border-t px-4 py-3 text-xs" style="border-color:var(--color-border);color:var(--color-text-secondary)">
      {{ footer }}
    </div>
  </aside>
</template>

<script setup>
defineProps({
  brand: { type: String, default: 'AUTOMETRIA ERP' },
  items: { type: Array, required: true },
  active: { type: String, required: true },
  footer: { type: String, default: 'v1.0.0' }
})
defineEmits(['select'])
</script>
