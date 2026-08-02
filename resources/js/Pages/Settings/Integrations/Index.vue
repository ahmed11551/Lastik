<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Settings / Integrations (1C · CommerceML)
 * Sprint B: credentials, sync mode, exchange logs, manual push/pull.
 */
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsBadge, DsTable } from '@/design-system'
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { apiGet, apiPost, apiPut } from '@/autometria/api/client'
import { toast } from '@/autometria/api/toast'

const props = defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
  /** When true, render without AutometriaLayout (SPA shell already provides chrome). */
  embedded: { type: Boolean, default: false },
})

type Creds = {
  login: string
  password_set: boolean
  password_hint?: string | null
  exchange_url: string
  export_orders_url?: string
  export_offers_url?: string
  json_push_url?: string
  options: {
    update_stocks: boolean
    update_prices: boolean
    create_products: boolean
    sync_mode?: 'manual' | 'auto'
    remote_url?: string | null
  }
}

type SyncLog = {
  id: number
  direction: string
  channel: string
  file_name: string
  status: string
  processed_count: number
  payload_size: number
  errors?: string | null
  details?: Record<string, unknown>
  created_at?: string
}

const loading = ref(false)
const mutating = ref(false)
const creds = ref<Creds | null>(null)
const logs = ref<SyncLog[]>([])
const error = ref('')
const detail = ref<SyncLog | null>(null)
let pollTimer: ReturnType<typeof setInterval> | null = null

const form = reactive({
  update_stocks: true,
  update_prices: true,
  create_products: true,
  sync_mode: 'manual' as 'manual' | 'auto',
  remote_url: '',
})

const columns = [
  { key: 'created_at', label: 'Дата' },
  { key: 'direction', label: 'Напр.' },
  { key: 'file_name', label: 'Пакет' },
  { key: 'status', label: 'Статус' },
  { key: 'payload_size', label: 'Размер' },
  { key: 'processed_count', label: 'Объектов' },
]

const exchangeUrl = computed(() => creds.value?.exchange_url || '—')

async function loadCredentials() {
  const payload = await apiGet('/1c/credentials', { silent: true })
  const data = (payload?.data || payload) as Creds
  creds.value = data
  form.update_stocks = data.options?.update_stocks ?? true
  form.update_prices = data.options?.update_prices ?? true
  form.create_products = data.options?.create_products ?? true
  form.sync_mode = (data.options?.sync_mode as 'manual' | 'auto') || 'manual'
  form.remote_url = data.options?.remote_url || ''
}

async function loadLogs() {
  const payload = await apiGet('/1c/sync-logs', { params: { per_page: 30 }, silent: true })
  logs.value = Array.isArray(payload?.data) ? payload.data : []
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadCredentials(), loadLogs()])
  } catch (e: unknown) {
    error.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

async function saveOptions() {
  mutating.value = true
  try {
    await apiPut('/1c/options', {
      update_stocks: form.update_stocks,
      update_prices: form.update_prices,
      create_products: form.create_products,
      sync_mode: form.sync_mode,
      remote_url: form.remote_url || null,
    })
    toast.success('Настройки интеграции сохранены', '1С')
    await loadCredentials()
  } catch {
    toast.error('Не удалось сохранить настройки', '1С')
  } finally {
    mutating.value = false
  }
}

async function resetCredentials() {
  mutating.value = true
  try {
    const payload = await apiPost('/1c/credentials/reset', {})
    const data = payload?.data || payload
    creds.value = { ...(creds.value as Creds), ...data }
    toast.success(`Новый пароль: ${data.password || '—'} (скопируйте сейчас)`, '1С')
  } catch {
    toast.error('Сброс пароля не выполнен', '1С')
  } finally {
    mutating.value = false
  }
}

async function requestImport() {
  mutating.value = true
  try {
    const payload = await apiPost('/1c/pull', {})
    toast.success(payload?.data?.hint || 'Готово к импорту из 1С', '1С')
    await loadLogs()
  } catch {
    toast.error('Запрос импорта не выполнен', '1С')
  } finally {
    mutating.value = false
  }
}

async function exportOrders() {
  mutating.value = true
  try {
    const payload = await apiPost('/1c/push', {})
    const o = payload?.data?.orders
    toast.success(`Выгружено заказов: ${o?.count ?? 0}, offers: ${payload?.data?.offers?.count ?? 0}`, '1С')
    await loadLogs()
  } catch {
    toast.error('Выгрузка заказов не выполнена', '1С')
  } finally {
    mutating.value = false
  }
}

function formatBytes(n: number): string {
  if (!n) return '0 B'
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / (1024 * 1024)).toFixed(2)} MB`
}

function statusClass(status: string): string {
  if (status === 'Success' || status === 'done') return 'ds-badge--success'
  if (status === 'Error' || status === 'failed') return 'ds-badge--danger'
  return 'ds-badge--warning'
}

onMounted(() => {
  if (typeof document !== 'undefined') {
    document.title = 'Интеграции 1С / CommerceML · AUTOMETRIA'
  }
  void refresh()
  pollTimer = setInterval(() => void loadLogs(), 8000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <component
    :is="props.embedded ? 'div' : AutometriaLayout"
    v-bind="props.embedded ? { 'data-testid': 'integrations-embedded' } : {
      title: 'Интеграции · 1С / CommerceML',
      activeNav: 'integrations',
      currentShiftOpen: props.currentShiftOpen,
      shiftStartedAt: props.shiftStartedAt,
      shiftRevenue: props.shiftRevenue,
      breadcrumbs: [{ label: 'Настройки' }, { label: 'Интеграции' }],
    }"
  >
    <div class="mb-4 flex flex-wrap gap-2">
      <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="1c-pull" :disabled="mutating" @click="requestImport">
        Запросить импорт
      </button>
      <button type="button" class="ds-btn ds-btn-sm" data-testid="1c-push" :disabled="mutating" @click="exportOrders">
        Выгрузить заказы в 1С
      </button>
      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="loading" @click="refresh">Обновить</button>
    </div>

    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <div class="mb-4 grid gap-3 lg:grid-cols-2">
      <section class="ds-surface space-y-3 p-3" data-testid="1c-settings">
        <h2 class="m-0 text-sm font-semibold uppercase tracking-[0.08em]">Настройки обмена</h2>
        <div>
          <div class="text-[11px] uppercase" style="color: var(--color-text-secondary)">Exchange URL</div>
          <code class="text-[12px] break-all">{{ exchangeUrl }}</code>
        </div>
        <div>
          <div class="text-[11px] uppercase" style="color: var(--color-text-secondary)">Login</div>
          <div class="text-[13px]">{{ creds?.login || '—' }}</div>
        </div>
        <div>
          <label class="text-[11px] uppercase" style="color: var(--color-text-secondary)">Режим синхронизации</label>
          <select v-model="form.sync_mode" class="ds-input mt-1 w-full" data-testid="1c-sync-mode">
            <option value="manual">Ручной</option>
            <option value="auto">Авто (cron / 1С)</option>
          </select>
        </div>
        <div>
          <label class="text-[11px] uppercase" style="color: var(--color-text-secondary)">Remote URL (опц.)</label>
          <input v-model="form.remote_url" class="ds-input mt-1 w-full" placeholder="https://1c.example/hs/exchange" data-testid="1c-remote-url" />
        </div>
        <label class="flex items-center gap-2 text-[12px]">
          <input v-model="form.update_stocks" type="checkbox" /> Обновлять остатки
        </label>
        <label class="flex items-center gap-2 text-[12px]">
          <input v-model="form.update_prices" type="checkbox" /> Обновлять цены
        </label>
        <label class="flex items-center gap-2 text-[12px]">
          <input v-model="form.create_products" type="checkbox" /> Создавать товары
        </label>
        <div class="flex gap-2">
          <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" :disabled="mutating" data-testid="1c-save-options" @click="saveOptions">
            Сохранить
          </button>
          <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="mutating" @click="resetCredentials">
            Сбросить пароль
          </button>
        </div>
      </section>

      <section class="ds-surface space-y-2 p-3">
        <h2 class="m-0 text-sm font-semibold uppercase tracking-[0.08em]">Эндпоинты</h2>
        <div class="text-[12px]" style="color: var(--color-text-secondary)">
          <div>Export orders: <code>{{ creds?.export_orders_url || '—' }}</code></div>
          <div class="mt-1">Export offers: <code>{{ creds?.export_offers_url || '—' }}</code></div>
          <div class="mt-1">JSON push: <code>{{ creds?.json_push_url || '—' }}</code></div>
        </div>
        <p class="m-0 text-[12px]" style="color: var(--color-text-secondary)">
          Basic Auth / Sanctum token — по конфигу тенанта. Все пакеты пишутся в OneCSyncLog.
        </p>
      </section>
    </div>

    <section class="ds-surface p-3" data-testid="1c-sync-logs">
      <h2 class="mb-3 mt-0 text-sm font-semibold uppercase tracking-[0.08em]">Логи обмена (OneCSyncLog)</h2>
      <DsTable :columns="columns" :rows="logs" density="compact" sticky-header>
        <template #status="{ value }">
          <DsBadge :class="statusClass(String(value))">{{ value }}</DsBadge>
        </template>
        <template #payload_size="{ value }">
          <span>{{ formatBytes(Number(value || 0)) }}</span>
        </template>
        <template #direction="{ value }">
          <span>{{ value === 'outbound' ? '→ out' : '← in' }}</span>
        </template>
        <template #file_name="{ value, row }">
          <button type="button" class="ds-link" @click="detail = row">{{ value }}</button>
        </template>
      </DsTable>
    </section>

    <div v-if="detail" class="ds-surface mt-4 space-y-2 p-3" data-testid="1c-log-detail">
      <div class="flex items-center justify-between">
        <h3 class="m-0 text-sm font-semibold">Детали #{{ detail.id }}</h3>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="detail = null">Закрыть</button>
      </div>
      <div class="text-[12px]">Статус: {{ detail.status }} · Канал: {{ detail.channel }}</div>
      <pre
        v-if="detail.errors"
        class="overflow-auto rounded p-2 text-[11px]"
        style="background: #111827; color: #fca5a5"
      >{{ detail.errors }}</pre>
      <pre class="overflow-auto rounded p-2 text-[11px]" style="background: #111827; color: #e5e7eb">{{
        JSON.stringify(detail.details || {}, null, 2)
      }}</pre>
    </div>
  </component>
</template>
