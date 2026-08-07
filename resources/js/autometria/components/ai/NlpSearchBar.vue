<script setup lang="ts">
/**
 * AUTOMETRIA ERP — NLP Search Bar (AirLLM / Local Enterprise AI Pack)
 */
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import {
  nlpSearch,
  resolveNlpNavigation,
  NLP_FILTERS_KEY,
} from '@/autometria/api/ai'
import { toast } from '@/autometria/api/toast'

const emit = defineEmits<{
  navigate: [item: { id: string; filters?: Record<string, unknown> }]
}>()

const prompt = ref('')
const loading = ref(false)
const open = ref(false)
const error = ref<string | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)

type ResultState = {
  interpretation: string
  source: string
  filters: Record<string, unknown>
  nav: { view: string; filters: Record<string, unknown>; label: string }
} | null

const result = ref<ResultState>(null)

const filterEntries = computed(() => {
  const f = result.value?.filters || {}
  return Object.entries(f).filter(([, v]) => v !== null && v !== undefined && v !== '')
})

const sourceLabel = computed(() => {
  const s = result.value?.source
  if (s === 'ai') return 'AirLLM'
  if (s === 'fallback') return 'Heuristic'
  return s || '—'
})

async function submit() {
  const q = prompt.value.trim()
  if (!q || loading.value) return

  loading.value = true
  error.value = null
  open.value = true
  result.value = null

  try {
    const data = await nlpSearch(q)
    const nav = resolveNlpNavigation(q, data)
    result.value = {
      interpretation: data?.interpretation || q,
      source: data?.source || 'fallback',
      filters: data?.filters || {},
      nav,
    }

    // Instant jump when intent is clear (non-dashboard target)
    if (nav.view !== 'dashboard') {
      applyAndGo(nav)
    }
  } catch (e: any) {
    error.value = e?.response?.data?.error || e?.response?.data?.message || e?.message || 'Ошибка NLP'
    toast.error(error.value || 'NLP недоступен', 'AI Search')
  } finally {
    loading.value = false
  }
}

function applyAndGo(nav: { view: string; filters: Record<string, unknown>; label: string }) {
  try {
    sessionStorage.setItem(
      NLP_FILTERS_KEY,
      JSON.stringify({ view: nav.view, filters: nav.filters, at: Date.now() }),
    )
  } catch {
    /* ignore */
  }
  emit('navigate', { id: nav.view, filters: nav.filters })
  open.value = false
  toast.success(nav.label, 'AI → навигация')
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
  if (e.key === 'Enter') {
    e.preventDefault()
    void submit()
  }
}

function onDocClick(e: MouseEvent) {
  const t = e.target as Node
  if (panelRef.value && !panelRef.value.contains(t) && inputRef.value && !inputRef.value.contains(t)) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
})

async function focusInput() {
  open.value = true
  await nextTick()
  inputRef.value?.focus()
}

defineExpose({ focusInput })
</script>

<template>
  <div class="relative min-w-0 flex-1 sm:min-w-[200px] md:min-w-[280px] lg:min-w-[340px]" data-testid="nlp-search-bar">
    <div class="relative">
      <span
        class="pointer-events-none absolute left-2.5 top-1/2 z-[1] -translate-y-1/2 font-mono text-[13px]"
        style="color: #f59e0b"
        aria-hidden="true"
        title="Local Enterprise AI"
      >✦</span>
      <input
        ref="inputRef"
        v-model="prompt"
        type="search"
        class="ds-input h-9 w-full py-1 pl-8 pr-10 text-[12px] font-normal sm:text-[13px]"
        style="
          border-radius: 4px;
          background: color-mix(in srgb, #090d16 80%, #0d1b3d);
          border-color: color-mix(in srgb, #f59e0b 35%, #1e293b);
          color: #e8edf5;
        "
        placeholder="Спросите ERP (например: 'Товары с риском стокаута на этой неделе')..."
        aria-label="AI NLP поиск ERP"
        autocomplete="off"
        :disabled="loading"
        @focus="open = true"
        @keydown="onKeydown"
      >
      <button
        type="button"
        class="absolute right-1 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded font-mono text-[11px]"
        style="color: #f59e0b"
        :disabled="loading || !prompt.trim()"
        :aria-busy="loading"
        title="Спросить AI"
        @click="submit"
      >
        {{ loading ? '…' : '↵' }}
      </button>
    </div>

    <div
      v-if="open && (result || error || loading)"
      ref="panelRef"
      class="absolute right-0 z-50 mt-1.5 w-[min(100vw-1.5rem,420px)] overflow-hidden border shadow-2xl"
      style="
        background: #0f172a;
        border-color: #1e293b;
        border-radius: 4px;
        max-height: min(50vh, calc(100dvh - var(--safe-top, 0px) - var(--safe-bottom, 0px) - 5rem));
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.45), 0 0 0 1px color-mix(in srgb, #f59e0b 20%, transparent);
      "
      role="listbox"
      aria-label="Результаты NLP поиска"
    >
      <div
        class="flex items-center justify-between gap-2 border-b px-3 py-2"
        style="border-color: #1e293b; background: linear-gradient(90deg, #0d1b3d, #0f172a)"
      >
        <span class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
          AirLLM · NLP
        </span>
        <span
          v-if="result"
          class="rounded border px-1.5 py-0.5 font-mono text-[9px] uppercase"
          :style="{
            borderColor: result.source === 'ai' ? '#f59e0b' : '#334155',
            color: result.source === 'ai' ? '#f59e0b' : '#94a3b8',
          }"
        >{{ sourceLabel }}</span>
      </div>

      <div class="max-h-[min(50vh,320px)] space-y-2 overflow-y-auto p-3">
        <p v-if="loading" class="font-mono text-[11px]" style="color: #94a3b8">
          Разбор запроса…
        </p>
        <p v-else-if="error" class="font-mono text-[11px]" style="color: #f87171">
          {{ error }}
        </p>
        <template v-else-if="result">
          <p class="text-[12px] leading-relaxed" style="color: #e8edf5">
            {{ result.interpretation }}
          </p>
          <div v-if="filterEntries.length" class="flex flex-wrap gap-1.5">
            <span
              v-for="[k, v] in filterEntries"
              :key="k"
              class="border px-1.5 py-0.5 font-mono text-[10px]"
              style="border-color: #1e293b; border-radius: 4px; color: #a8b3c7; background: #090d16"
            >
              {{ k }}={{ v }}
            </span>
          </div>
          <button
            type="button"
            class="mt-1 flex h-10 w-full items-center justify-between border px-3 font-mono text-[11px] font-bold uppercase tracking-wide"
            style="border-color: #f59e0b; background: color-mix(in srgb, #f59e0b 14%, #090d16); color: #f59e0b; border-radius: 4px"
            @click="applyAndGo(result.nav)"
          >
            <span>→ {{ result.nav.label }}</span>
            <span class="opacity-70">открыть</span>
          </button>
        </template>
      </div>
    </div>
  </div>
</template>
