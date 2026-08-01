<script setup>
/**
 * AUTOMETRIA ERP — Command palette (⌘K)
 * Deep search: VIN, артикулы, заказы, навигация
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  items: {
    type: Array,
    default: () => [],
    /** @type {{ id: string|number, label: string, hint?: string, keywords?: string[], type?: string, action?: Function }[]} */
  },
  placeholder: {
    type: String,
    default: 'Поиск по VIN, артикулу, номеру заказа…',
  },
})

const emit = defineEmits(['update:modelValue', 'close', 'select'])

const query = ref('')
const activeIndex = ref(0)
const inputRef = ref(null)

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const catalog = computed(() => {
  const registered = window.__commandPaletteItems || []
  return [...props.items, ...registered]
})

function normalize(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/[\s\-_/]/g, '')
}

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  const qNorm = normalize(query.value)
  if (!q) {
    return catalog.value.slice(0, 12)
  }

  return catalog.value
    .map((item) => {
      const hay = [item.label, item.hint, item.type, ...(item.keywords || [])]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      const hayNorm = normalize(hay)
      let score = 0
      if (hay.includes(q)) score += 10
      if (hayNorm.includes(qNorm)) score += 8
      // VIN / article heuristics
      if (/^[a-hj-npr-z0-9]{11,17}$/i.test(q.replace(/\s/g, '')) && hayNorm.includes(qNorm)) {
        score += 20
      }
      if (/^[a-z0-9][\w\-.]{2,}$/i.test(q) && hayNorm.includes(qNorm)) {
        score += 5
      }
      return { item, score }
    })
    .filter((x) => x.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, 24)
    .map((x) => x.item)
})

watch(filtered, () => {
  activeIndex.value = 0
})

watch(open, async (v) => {
  if (v) {
    query.value = ''
    activeIndex.value = 0
    await nextTick()
    inputRef.value?.focus()
  }
})

function close() {
  open.value = false
  emit('close')
}

function run(item) {
  if (!item) return
  emit('select', item)
  if (typeof item.action === 'function') {
    item.action()
  }
  close()
}

function onKeydown(e) {
  const isModK = (e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k'
  if (isModK) {
    e.preventDefault()
    open.value = !open.value
    return
  }

  if (!open.value) return

  if (e.key === 'Escape') {
    e.preventDefault()
    close()
  } else if (e.key === 'ArrowDown') {
    e.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, Math.max(filtered.value.length - 1, 0))
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, 0)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    run(filtered.value[activeIndex.value])
  }
}

function onPaletteOpen() {
  open.value = true
}

function onPaletteClose() {
  close()
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('command-palette:open', onPaletteOpen)
  window.addEventListener('command-palette:close', onPaletteClose)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('command-palette:open', onPaletteOpen)
  window.removeEventListener('command-palette:close', onPaletteClose)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
      style="background: rgba(0, 0, 0, 0.6)"
      @click.self="close"
    >
      <div
        class="w-full max-w-2xl overflow-hidden shadow-2xl"
        style="
          background: var(--color-surface);
          border: 1px solid var(--color-border);
          border-radius: var(--radius);
        "
        role="dialog"
        aria-label="Command palette"
      >
        <div
          class="flex items-center gap-2 border-b px-3"
          style="border-color: var(--color-border)"
        >
          <svg
            class="h-4 w-4 shrink-0"
            style="color: var(--color-text-secondary)"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15z"
            />
          </svg>
          <input
            ref="inputRef"
            v-model="query"
            type="search"
            class="w-full border-0 bg-transparent py-3 text-sm outline-none"
            style="color: var(--color-text-primary); font-family: var(--font-ui)"
            :placeholder="placeholder"
            autocomplete="off"
            spellcheck="false"
          >
          <kbd
            class="hidden shrink-0 rounded px-1.5 py-0.5 text-[10px] font-mono sm:inline"
            style="
              color: var(--color-text-secondary);
              border: 1px solid var(--color-border);
              background: var(--color-surface-elevated);
            "
          >ESC</kbd>
        </div>

        <div class="max-h-80 overflow-y-auto py-1">
          <div
            v-if="filtered.length === 0"
            class="px-4 py-6 text-center text-sm"
            style="color: var(--color-text-secondary)"
          >
            Ничего не найдено
          </div>
          <button
            v-for="(item, i) in filtered"
            :key="item.id"
            type="button"
            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm transition-colors"
            :style="{
              color: 'var(--color-text-primary)',
              background: i === activeIndex ? 'var(--color-surface-elevated)' : 'transparent',
            }"
            @mouseenter="activeIndex = i"
            @click="run(item)"
          >
            <span class="min-w-0 truncate">
              <span
                v-if="item.type"
                class="mr-2 text-[10px] uppercase tracking-wider"
                style="color: var(--color-primary)"
              >{{ item.type }}</span>
              {{ item.label }}
            </span>
            <span
              v-if="item.hint"
              class="shrink-0 font-mono text-[11px]"
              style="color: var(--color-text-secondary)"
            >{{ item.hint }}</span>
          </button>
        </div>

        <div
          class="flex items-center justify-between border-t px-4 py-2 text-[11px]"
          style="border-color: var(--color-border); color: var(--color-text-secondary)"
        >
          <span>VIN · артикул · заказ · навигация</span>
          <span class="font-mono">⌘K</span>
        </div>
      </div>
    </div>
  </Teleport>
</template>
