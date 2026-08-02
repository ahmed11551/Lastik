<script setup lang="ts">
/**
 * 1C sync journal table + error detail modal
 */
import { computed, ref } from 'vue'
import { DsTable } from '@/design-system'
import type { OneCSyncLog } from '@/autometria/types/onec'

const props = defineProps<{
  logs: OneCSyncLog[]
  loading?: boolean
}>()

const emit = defineEmits<{
  (e: 'refresh'): void
}>()

const detailOpen = ref(false)
const detailLog = ref<OneCSyncLog | null>(null)

const columns = [
  { key: 'when', label: 'Дата / время' },
  { key: 'channel', label: 'Источник', mono: false },
  { key: 'file_name', label: 'Файл' },
  { key: 'status', label: 'Статус', mono: false },
  { key: 'objects', label: 'Обработано', mono: false },
  { key: 'actions', label: '', mono: false, width: '120px' },
]

const rows = computed(() =>
  (props.logs || []).map((l) => ({
    ...l,
    when: formatWhen(l.created_at),
    channel_label: channelLabel(l.channel),
    objects_label: objectsLabel(l),
  })),
)

function formatWhen(iso?: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  const local = d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
  const utc = d.toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, ' UTC')
  return `${local}\n${utc}`
}

function channelLabel(ch?: string): string {
  if (ch === 'auto_1c') return 'Автоматический обмен 1С'
  if (ch === 'manual_upload') return 'Ручная загрузка XML'
  return ch || '—'
}

function objectsLabel(l: OneCSyncLog): string {
  const o = l.objects || { categories: 0, products: 0, offers: 0, processed: 0, conflicts: 0, skipped: 0 }
  const parts = [
    `Категорий: ${Number(o.categories || 0).toLocaleString('ru-RU')}`,
    `Товаров: ${Number(o.products || 0).toLocaleString('ru-RU')}`,
    `Офферов: ${Number(o.offers || o.processed || 0).toLocaleString('ru-RU')}`,
  ]
  if (o.conflicts) parts.push(`Конфликтов: ${o.conflicts}`)
  return parts.join(', ')
}

function statusMeta(status: string): { label: string; color: string; border: string; bg: string; spin: boolean } {
  switch (status) {
    case 'completed':
      return { label: 'Успешно', color: '#6EE7B7', border: '#10B981', bg: '#052e1a', spin: false }
    case 'processing':
    case 'pending':
      return {
        label: status === 'pending' ? 'В очереди' : 'В процессе',
        color: '#93C5FD',
        border: '#3B82F6',
        bg: '#172554',
        spin: true,
      }
    case 'failed':
      return { label: 'Ошибка', color: '#FCA5A5', border: '#EF4444', bg: '#3f0a0a', spin: false }
    default:
      return { label: status || '—', color: '#D1D5DB', border: '#6B7280', bg: '#1f2937', spin: false }
  }
}

function openDetail(row: OneCSyncLog): void {
  detailLog.value = row
  detailOpen.value = true
}

function closeDetail(): void {
  detailOpen.value = false
  detailLog.value = null
}

const detailText = computed(() => {
  const l = detailLog.value
  if (!l) return ''
  const parts: string[] = []
  if (l.error_message) parts.push(String(l.error_message))
  if (Array.isArray(l.errors) && l.errors.length) {
    parts.push(JSON.stringify(l.errors, null, 2))
  }
  if (l.summary) parts.push('summary:\n' + JSON.stringify(l.summary, null, 2))
  return parts.join('\n\n') || 'Нет деталей ошибки'
})
</script>

<template>
  <div>
    <div class="mb-2 flex items-center justify-between gap-2">
      <h3 class="text-xs font-medium text-white">Журнал и история синхронизаций</h3>
      <button
        type="button"
        class="h-9 border px-2.5 font-mono text-[11px]"
        style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
        :disabled="loading"
        @click="emit('refresh')"
      >
        Обновить
      </button>
    </div>

    <DsTable
      :columns="columns"
      :rows="rows"
      density="compact"
      sticky-header
      max-height="min(48vh, 420px)"
      empty-text="История пуста — выполните обмен 1С или загрузите XML"
    >
      <template #when="{ value }">
        <pre class="m-0 whitespace-pre-wrap font-mono text-[10px] leading-snug" style="color: #9ca3af">{{ value }}</pre>
      </template>
      <template #channel="{ row }">
        <span class="text-[12px] text-white">{{ row.channel_label }}</span>
      </template>
      <template #file_name="{ value }">
        <span class="font-mono text-[12px] text-white">{{ value || '—' }}</span>
      </template>
      <template #status="{ row }">
        <span
          class="inline-flex items-center gap-1.5 border px-2 py-0.5 font-mono text-[11px]"
          :style="{
            background: statusMeta(String(row.status)).bg,
            borderColor: statusMeta(String(row.status)).border,
            color: statusMeta(String(row.status)).color,
            borderRadius: '4px',
          }"
        >
          <span
            v-if="statusMeta(String(row.status)).spin"
            class="onec-log-spin inline-block h-2.5 w-2.5 rounded-full border-2 border-current border-t-transparent"
          />
          {{ statusMeta(String(row.status)).label }}
        </span>
      </template>
      <template #objects="{ row }">
        <span class="text-[11px]" style="color: #9ca3af">{{ row.objects_label }}</span>
      </template>
      <template #actions="{ row }">
        <button
          v-if="row.status === 'failed'"
          type="button"
          class="h-8 border px-2 font-mono text-[10px] uppercase"
          style="border-color: #EF4444; color: #FCA5A5; border-radius: 4px; background: #1a0a0a"
          @click="openDetail(row)"
        >
          Детали ошибки
        </button>
      </template>
    </DsTable>

    <Teleport to="body">
      <div
        v-if="detailOpen && detailLog"
        class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
        role="dialog"
        aria-modal="true"
        aria-label="Детали ошибки синхронизации"
      >
        <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="closeDetail" />
        <div
          class="relative z-10 max-h-[85vh] w-full max-w-2xl overflow-hidden border-t sm:rounded sm:border"
          style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
        >
          <div class="flex items-center justify-between gap-2 border-b px-4 py-3" style="border-color: #1f2937">
            <div>
              <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">1C // error</div>
              <h4 class="text-sm font-semibold text-white">
                {{ detailLog.file_name || 'Ошибка импорта' }} · #{{ detailLog.id }}
              </h4>
            </div>
            <button
              type="button"
              class="h-9 w-9 border font-mono text-sm"
              style="border-color: #1f2937; color: #9ca3af; border-radius: 4px"
              @click="closeDetail"
            >
              ✕
            </button>
          </div>
          <pre
            class="max-h-[60vh] overflow-auto p-4 font-mono text-[11px] leading-relaxed"
            style="color: #FCA5A5; background: #0b0d10; margin: 0; white-space: pre-wrap; word-break: break-word"
          >{{ detailText }}</pre>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.onec-log-spin {
  animation: onec-log-spin 0.8s linear infinite;
}
@keyframes onec-log-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
