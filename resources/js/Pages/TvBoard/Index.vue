<script setup lang="ts">
/**
 * AUTOMETRIA ERP — TV Board (Industrial Precision)
 * Real-time queue columns: queue → in_progress → ready
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsBadge } from '@/design-system'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { apiGet } from '@/autometria/api/client'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  /** When true, skip AutometriaLayout chrome (kiosk / full-bleed TV). */
  kiosk: { type: Boolean, default: false },
  pollMs: { type: Number, default: 15_000 },
})

type TvCard = {
  id: number
  number: string
  status: string
  scenario?: string
  plate?: string | null
  vehicle?: string | null
}

type Columns = {
  queue: TvCard[]
  in_progress: TvCard[]
  ready: TvCard[]
}

const columns = ref<Columns>({ queue: [], in_progress: [], ready: [] })
const locationId = ref<number | null>(null)
const loading = ref(false)
const error = ref('')
const lastUpdated = ref<string | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

const COL_META = [
  { key: 'queue' as const, title: 'Очередь', tone: 'neutral' },
  { key: 'in_progress' as const, title: 'В работе', tone: 'warning' },
  { key: 'ready' as const, title: 'Готово', tone: 'success' },
]

const totalCards = computed(
  () => columns.value.queue.length + columns.value.in_progress.length + columns.value.ready.length,
)

async function loadBoard() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/tv/board', { silent: true })
    const data = payload?.data ?? payload
    columns.value = {
      queue: Array.isArray(data?.columns?.queue) ? data.columns.queue : [],
      in_progress: Array.isArray(data?.columns?.in_progress) ? data.columns.in_progress : [],
      ready: Array.isArray(data?.columns?.ready) ? data.columns.ready : [],
    }
    locationId.value = data?.location_id ?? null
    lastUpdated.value = new Date().toLocaleTimeString('ru-RU')
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    error.value = msg || 'Нет связи с TV Board API'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'TV-Экран очереди · AUTOMETRIA ERP'
  }
  void loadBoard()
  timer = setInterval(() => void loadBoard(), props.pollMs)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div data-testid="tv-board-root">
    <AutometriaLayout
      v-if="!kiosk"
      title="TV-Экран очереди"
      active-nav="tv_display"
      :current-shift-open="currentShiftOpen"
      :shift-started-at="shiftStartedAt"
      :shift-revenue="shiftRevenue"
      :breadcrumbs="[{ label: 'Операции' }, { label: 'TV Board' }]"
    >
      <div class="tv-board" data-testid="tv-board">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-[11px] uppercase tracking-[0.12em]" style="color: var(--color-text-secondary)">
              Industrial Precision · Live
            </div>
            <h1 class="m-0 text-2xl font-semibold" style="color: var(--color-text-primary)">Очередь шиномонтажа</h1>
          </div>
          <div class="flex items-center gap-2 text-[12px]" style="color: var(--color-text-secondary)">
            <DsBadge class="ds-badge--neutral">Точка {{ locationId ?? '—' }}</DsBadge>
            <DsBadge class="ds-badge--warning">{{ totalCards }} заказов</DsBadge>
            <span v-if="lastUpdated">обновлено {{ lastUpdated }}</span>
            <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-testid="tv-refresh" @click="loadBoard">
              Обновить
            </button>
          </div>
        </header>

        <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)" data-testid="tv-error">
          {{ error }}
        </div>
        <div v-if="loading && !totalCards" class="ds-surface mb-4 p-3" style="color: var(--color-text-secondary)">
          Загрузка очереди…
        </div>

        <div class="tv-grid grid gap-3 md:grid-cols-3">
          <section
            v-for="col in COL_META"
            :key="col.key"
            class="ds-surface flex min-h-[420px] flex-col p-3"
            :data-testid="`tv-col-${col.key}`"
          >
            <div class="mb-3 flex items-center justify-between border-b pb-2" style="border-color: var(--color-border)">
              <h2 class="m-0 text-sm font-semibold uppercase tracking-[0.08em]">{{ col.title }}</h2>
              <span
                class="ds-badge"
                :class="{
                  'ds-badge--neutral': col.tone === 'neutral',
                  'ds-badge--warning': col.tone === 'warning',
                  'ds-badge--success': col.tone === 'success',
                }"
              >
                {{ columns[col.key].length }}
              </span>
            </div>

            <div class="flex flex-1 flex-col gap-2 overflow-auto">
              <article
                v-for="card in columns[col.key]"
                :key="card.id"
                class="tv-card rounded border p-3"
                style="border-color: var(--color-border); background: var(--color-bg-elevated, #111827)"
                :data-testid="`tv-card-${card.id}`"
              >
                <div class="flex items-start justify-between gap-2">
                  <div class="text-xl font-semibold tracking-tight" style="color: var(--color-primary)">
                    {{ card.number }}
                  </div>
                  <DsBadge class="ds-badge--neutral">{{ card.status }}</DsBadge>
                </div>
                <div class="mt-2 text-lg font-medium" style="color: var(--color-text-primary)">
                  {{ card.plate || '—' }}
                </div>
                <div class="mt-1 text-[13px]" style="color: var(--color-text-secondary)">
                  {{ card.vehicle || 'Транспорт не указан' }}
                </div>
              </article>

              <div
                v-if="!columns[col.key].length"
                class="flex flex-1 items-center justify-center text-[12px]"
                style="color: var(--color-text-secondary)"
              >
                Пусто
              </div>
            </div>
          </section>
        </div>
      </div>
    </AutometriaLayout>

    <!-- Kiosk / SPA hash route: full-bleed without shell chrome -->
    <div v-else class="tv-kiosk min-h-screen p-4" data-testid="tv-board">
      <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="m-0 text-3xl font-semibold" style="color: var(--color-primary)">Очередь шиномонтажа</h1>
        <div class="flex items-center gap-2 text-[14px]" style="color: var(--color-text-secondary)">
          <DsBadge class="ds-badge--warning">{{ totalCards }}</DsBadge>
          <span v-if="lastUpdated">{{ lastUpdated }}</span>
        </div>
      </header>
      <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>
      <div class="tv-grid grid gap-3 md:grid-cols-3">
        <section
          v-for="col in COL_META"
          :key="col.key"
          class="ds-surface flex min-h-[70vh] flex-col p-4"
          :data-testid="`tv-col-${col.key}`"
        >
          <div class="mb-4 flex items-center justify-between">
            <h2 class="m-0 text-xl font-semibold uppercase tracking-[0.08em]">{{ col.title }}</h2>
            <span class="text-2xl font-semibold" style="color: var(--color-primary)">{{ columns[col.key].length }}</span>
          </div>
          <div class="flex flex-1 flex-col gap-3 overflow-auto">
            <article
              v-for="card in columns[col.key]"
              :key="card.id"
              class="tv-card rounded border p-4"
              style="border-color: var(--color-border); background: var(--color-bg-elevated, #111827)"
            >
              <div class="text-3xl font-semibold" style="color: var(--color-primary)">{{ card.number }}</div>
              <div class="mt-2 text-2xl font-medium">{{ card.plate || '—' }}</div>
              <div class="mt-1 text-base" style="color: var(--color-text-secondary)">{{ card.vehicle || '' }}</div>
            </article>
            <div
              v-if="!columns[col.key].length"
              class="flex flex-1 items-center justify-center text-sm"
              style="color: var(--color-text-secondary)"
            >
              Пусто
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tv-kiosk {
  background: var(--color-bg, #0b0d10);
  color: var(--color-text-primary, #e5e7eb);
}
.tv-card {
  transition: border-color 0.15s ease;
}
</style>
