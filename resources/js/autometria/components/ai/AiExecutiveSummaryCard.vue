<script setup lang="ts">
/**
 * AUTOMETRIA ERP — AI Executive Summary card (AirLLM Engine)
 */
import { onMounted, ref } from 'vue'
import { fetchDailySummary } from '@/autometria/api/ai'
import { toast } from '@/autometria/api/toast'

const loading = ref(false)
const text = ref('')
const source = ref<'ai' | 'fallback' | string>('')
const model = ref<string | null>(null)
const date = ref('')
const error = ref<string | null>(null)

async function load(forceToday = true) {
  loading.value = true
  error.value = null
  try {
    const params: { date?: string } = {}
    if (forceToday) {
      params.date = new Date().toISOString().slice(0, 10)
    }
    const data = await fetchDailySummary(params)
    text.value = data?.text || 'Нет данных сводки'
    source.value = data?.source || 'fallback'
    model.value = data?.model ?? null
    date.value = data?.date || params.date || ''
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Не удалось получить сводку'
    toast.error(error.value || 'AI summary error', 'AirLLM')
  } finally {
    loading.value = false
  }
}

function regenerate() {
  void load(true)
}

onMounted(() => {
  void load(true)
})
</script>

<template>
  <section
    class="ai-summary-card relative overflow-hidden p-[1px]"
    style="border-radius: 4px; background: linear-gradient(135deg, #f59e0b 0%, #1a3c8c 45%, #0d1b3d 100%)"
    data-testid="ai-executive-summary"
    aria-label="ИИ-сводка за день"
  >
    <div
      class="relative p-4 sm:p-5"
      style="
        background:
          radial-gradient(ellipse at top right, color-mix(in srgb, #f59e0b 12%, transparent), transparent 55%),
          linear-gradient(160deg, #0d1b3d 0%, #090d16 55%, #0f172a 100%);
        border-radius: 3px;
      "
    >
      <div class="pointer-events-none absolute inset-0 opacity-[0.07]" style="
        background-image: linear-gradient(rgba(245, 158, 11, 0.4) 1px, transparent 1px),
          linear-gradient(90deg, rgba(245, 158, 11, 0.4) 1px, transparent 1px);
        background-size: 24px 24px;
      " />

      <div class="relative flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 space-y-2">
          <div
            class="inline-flex max-w-full items-center gap-2 border px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-[0.08em]"
            style="
              border-color: color-mix(in srgb, #f59e0b 45%, #1e293b);
              background: color-mix(in srgb, #f59e0b 10%, #090d16);
              color: #fbbf24;
              border-radius: 4px;
            "
          >
            <span aria-hidden="true">🤖</span>
            <span class="truncate">Local AI (AirLLM Engine)</span>
          </div>

          <h3 class="text-sm font-semibold tracking-tight text-white sm:text-base">
            ИИ-сводка на сегодня
          </h3>
          <p class="font-mono text-[10px]" style="color: #94a3b8">
            <template v-if="date">{{ date }} · </template>
            <span :style="{ color: source === 'ai' ? '#f59e0b' : '#64748b' }">
              {{ source === 'ai' ? `модель ${model || 'AirLLM'}` : 'fallback · метрики без LLM' }}
            </span>
          </p>
        </div>

        <button
          type="button"
          class="h-11 shrink-0 border px-3 font-mono text-[11px] font-bold uppercase tracking-wide sm:h-9"
          style="
            border-color: #f59e0b;
            color: #090d16;
            background: #f59e0b;
            border-radius: 4px;
          "
          :disabled="loading"
          @click="regenerate"
        >
          {{ loading ? 'Генерация…' : 'Перегенерировать отчёт' }}
        </button>
      </div>

      <div class="relative mt-4 min-h-[4.5rem]">
        <p
          v-if="loading && !text"
          class="font-mono text-[12px]"
          style="color: #94a3b8"
        >
          AirLLM формирует исполнительную сводку…
        </p>
        <p
          v-else-if="error && !text"
          class="font-mono text-[12px]"
          style="color: #f87171"
        >
          {{ error }}
        </p>
        <div
          v-else
          class="whitespace-pre-wrap text-[13px] leading-relaxed"
          style="color: #e8edf5"
        >
          {{ text }}
        </div>
      </div>
    </div>
  </section>
</template>
