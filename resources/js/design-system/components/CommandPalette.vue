<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-start justify-center pt-24"
    style="background:rgba(0,0,0,0.6)"
    @click.self="$emit('close')"
  >
    <div
      class="w-full max-w-2xl overflow-hidden"
      style="background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius)"
    >
      <input
        v-model="query"
        class="ds-input"
        placeholder="Поиск по VIN, артикулу, номеру заказа..."
        autofocus
      />
      <div class="max-h-80 overflow-y-auto p-2 text-sm" style="color:var(--color-text-secondary)">
        <div v-if="!query" class="px-2 py-1">Введите запрос</div>
        <div v-else-if="filtered.length === 0" class="px-2 py-1">Ничего не найдено</div>
        <button
          v-for="item in filtered"
          :key="item.id"
          class="flex w-full items-center justify-between rounded px-2 py-2 text-left"
          style="color:var(--color-text-primary)"
          @click="item.action(); $emit('close')"
        >
          <span>{{ item.label }}</span>
          <span class="text-xs" style="color:var(--color-text-secondary)">{{ item.hint }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  modelValue: { type: Boolean, default: false }
})

const emit = defineEmits(['update:modelValue', 'close'])

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v)
})

const query = ref('')

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return []
  return (window.__commandPaletteItems || props.items).filter((i) =>
    i.label.toLowerCase().includes(q)
  )
})
</script>
