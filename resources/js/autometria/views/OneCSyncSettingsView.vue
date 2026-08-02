<script setup lang="ts">
/**
 * AUTOMETRIA ERP — 1C CommerceML 2.10 sync control panel (Block 2.4)
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge } from '@/design-system'
import { useOneCSyncStore } from '@/autometria/stores/oneCSyncStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'
import OneCSyncStatusBadge from '@/autometria/components/onec/OneCSyncStatusBadge.vue'
import OneCSyncLogsTable from '@/autometria/components/onec/OneCSyncLogsTable.vue'
import type { OneCFileType, OneCSyncOptions } from '@/autometria/types/onec'

const store = useOneCSyncStore()
const {
  credentials,
  logs,
  uploadProgress,
  isSyncing,
  loading,
  mutating,
  integrationState,
  exchangeUrl,
} = storeToRefs(store)

const resetOpen = ref(false)
const revealPassword = ref('')
const dragOver = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)
const options = reactive<OneCSyncOptions>({
  update_stocks: true,
  update_prices: true,
  create_products: true,
})
const optionsDirty = ref(false)

watch(
  credentials,
  (c) => {
    if (!c?.options) return
    options.update_stocks = c.options.update_stocks
    options.update_prices = c.options.update_prices
    options.create_products = c.options.create_products
    optionsDirty.value = false
    if (c.password) revealPassword.value = c.password
  },
  { immediate: true, deep: true },
)

const uploadBarColor = computed(() => {
  if (uploadProgress.value.phase === 'failed') return '#EF4444'
  if (uploadProgress.value.phase === 'completed') return '#10B981'
  return '#F59E0B'
})

const uploadPhaseLabel = computed(() => {
  const map: Record<string, string> = {
    idle: 'Ожидание файла',
    uploading: 'Загрузка…',
    pending: 'В очереди',
    processing: 'Обработка…',
    completed: 'Завершено',
    failed: 'Ошибка',
  }
  return map[uploadProgress.value.phase] || uploadProgress.value.phase
})

function markOptionsDirty(): void {
  optionsDirty.value = true
}

async function copyText(text: string, label: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(`${label} скопирован`, '1С')
  } catch {
    toast.warning('Не удалось скопировать в буфер', '1С')
  }
}

async function saveOptions(): Promise<void> {
  try {
    await store.saveOptions({ ...options })
    optionsDirty.value = false
  } catch {
    toast.warning('Не удалось сохранить опции', '1С')
  }
}

async function confirmReset(): Promise<void> {
  try {
    const res = await store.resetCredentials()
    revealPassword.value = res?.password || ''
    resetOpen.value = false
  } catch {
    toast.warning('Сброс доступа не выполнен', '1С')
  }
}

function detectType(file: File): OneCFileType {
  const n = file.name.toLowerCase()
  if (n.includes('offer')) return 'offers'
  if (n.includes('import')) return 'import'
  return 'auto'
}

function validateXml(file: File): boolean {
  if (!file.name.toLowerCase().endsWith('.xml')) {
    toast.warning('Принимаются только файлы .xml (import.xml / offers.xml)', '1С')
    return false
  }
  const mime = file.type || ''
  if (mime && !['application/xml', 'text/xml', 'application/octet-stream'].includes(mime)) {
    toast.warning('MIME должен быть application/xml или text/xml', '1С')
    return false
  }
  return true
}

async function handleFiles(list: FileList | File[] | null): Promise<void> {
  const file = list?.[0]
  if (!file || !validateXml(file)) return
  try {
    await store.uploadXmlFile(file, detectType(file))
  } catch {
    /* toast via interceptor */
  }
}

function onDrop(e: DragEvent): void {
  e.preventDefault()
  dragOver.value = false
  handleFiles(e.dataTransfer?.files || null)
}

function onFilePick(e: Event): void {
  const input = e.target as HTMLInputElement
  handleFiles(input.files)
  input.value = ''
}

onMounted(async () => {
  try {
    await store.fetchCredentials()
  } catch {
    toast.warning('Учётные данные 1С недоступны', '1С')
  }
  try {
    await store.fetchLogs(1)
  } catch {
    toast.warning('Журнал синхронизаций недоступен', '1С')
  }
})

onUnmounted(() => {
  store.stopPolling()
})
</script>

<template>
  <div
    class="min-w-0 max-w-full space-y-3 overflow-x-hidden p-3 sm:space-y-4 sm:p-4 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <!-- Header -->
    <div
      class="flex flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div class="min-w-0">
        <div class="mb-1 font-mono text-[10px] font-medium uppercase tracking-[0.14em]" style="color: #f59e0b">
          Integration // 1C CommerceML 2.10
        </div>
        <h2 class="text-sm font-medium text-white sm:text-base">Синхронизация с 1С</h2>
        <p class="mt-1 text-xs font-medium" style="color: #9ca3af">
          Обмен каталогом и остатками · UUID (external_id) · ручной XML
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <DsLoadingBadge v-if="loading || mutating || isSyncing" label="1C" />
        <OneCSyncStatusBadge :state="integrationState" />
        <DsBadge status="open" label="Block 2.4" variant="open" />
      </div>
    </div>

    <!-- A) Connection params -->
    <section class="border p-4" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
      <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 class="text-xs font-medium text-white">Параметры подключения CommerceML 2.10</h3>
          <p class="mt-1 text-[11px]" style="color: #6b7280">
            Укажите URL и Basic Auth в настройках обмена «1С:УТ 11» / «1С:УНФ».
          </p>
        </div>
        <div class="group relative">
          <button
            type="button"
            class="h-8 border px-2 font-mono text-[10px]"
            style="border-color: #1f2937; color: #f59e0b; border-radius: 4px; background: #0b0d10"
            title="Подсказка по настройке 1С"
          >
            Как настроить?
          </button>
          <div
            class="pointer-events-none absolute right-0 z-20 mt-1 hidden w-72 border p-3 text-[11px] leading-relaxed group-hover:block group-focus-within:block"
            style="background: #161b22; border-color: #374151; border-radius: 4px; color: #d1d5db"
          >
            <strong class="text-white">1С:УТ 11 / УНФ</strong><br>
            Администрирование → Обмен данными → Синхронизация с сайтом → CommerceML.<br>
            Адрес сайта: URL обмена ниже.<br>
            Логин/пароль: учётные данные HTTP Basic Auth.<br>
            Режим: выгрузка каталога и остатков (import.xml + offers.xml).
          </div>
        </div>
      </div>

      <label class="block text-[12px]" style="color: #9ca3af">
        Ссылка для настройки 1С
        <div class="mt-1 flex gap-2">
          <input
            :value="exchangeUrl"
            readonly
            class="ds-input h-11 min-w-0 flex-1 font-mono text-sm"
            style="border-radius: 4px; background: #161b22; border-color: #1f2937"
          >
          <button
            type="button"
            class="h-11 shrink-0 border px-3 font-mono text-[11px]"
            style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px; background: #0b0d10"
            @click="copyText(exchangeUrl, 'URL обмена')"
          >
            Скопировать
          </button>
        </div>
      </label>

      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <label class="block text-[12px]" style="color: #9ca3af">
          Логин (HTTP Basic Auth)
          <div class="mt-1 flex gap-2">
            <input
              :value="credentials?.login || '—'"
              readonly
              class="ds-input h-11 min-w-0 flex-1 font-mono text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            >
            <button
              type="button"
              class="h-11 shrink-0 border px-3 font-mono text-[11px]"
              style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
              :disabled="!credentials?.login"
              @click="copyText(credentials?.login || '', 'Логин')"
            >
              Copy
            </button>
          </div>
        </label>

        <label class="block text-[12px]" style="color: #9ca3af">
          Пароль сессии 1С
          <div class="mt-1 flex gap-2">
            <input
              :value="revealPassword || credentials?.password_hint || (credentials?.password_set ? '••••••••••••' : 'не задан')"
              readonly
              class="ds-input h-11 min-w-0 flex-1 font-mono text-sm"
              style="border-radius: 4px; background: #161b22; border-color: #1f2937"
            >
            <button
              v-if="revealPassword"
              type="button"
              class="h-11 shrink-0 border px-3 font-mono text-[11px]"
              style="border-color: #f59e0b; color: #f59e0b; border-radius: 4px; background: #0b0d10"
              @click="copyText(revealPassword, 'Пароль')"
            >
              Copy
            </button>
          </div>
          <span v-if="revealPassword" class="mt-1 block text-[10px]" style="color: #f59e0b">
            Пароль показан один раз после сброса — сохраните его в 1С.
          </span>
        </label>
      </div>

      <button
        type="button"
        class="mt-3 h-11 border px-3 font-mono text-[11px]"
        style="border-color: #EF4444; color: #FCA5A5; border-radius: 4px; background: #1a0a0a"
        :disabled="mutating"
        @click="resetOpen = true"
      >
        Сгенерировать новый пароль / сбросить доступ
      </button>

      <div class="mt-4 space-y-2 border-t pt-4" style="border-color: #1f2937">
        <div class="mb-2 text-[11px] font-medium text-white">Опции синхронизации</div>

        <label class="flex cursor-pointer items-start gap-3 text-[12px] text-white">
          <input v-model="options.update_stocks" type="checkbox" class="mt-0.5" @change="markOptionsDirty">
          <span>
            Автоматически обновлять остатки товаров из offers.xml
            <span class="mt-0.5 block text-[10px]" style="color: #6b7280">Сопоставление по external_id / артикулу</span>
          </span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 text-[12px] text-white">
          <input v-model="options.update_prices" type="checkbox" class="mt-0.5" @change="markOptionsDirty">
          <span>
            Автоматически обновлять цены и прайс-листы
            <span class="mt-0.5 block text-[10px]" style="color: #6b7280">Цены из пакета CommerceML → base_price</span>
          </span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 text-[12px] text-white">
          <input v-model="options.create_products" type="checkbox" class="mt-0.5" @change="markOptionsDirty">
          <span>
            Создавать новые товары при отсутствии совпадения по external_id
            <span class="mt-0.5 block text-[10px]" style="color: #6b7280">Иначе неизвестные SKU пропускаются</span>
          </span>
        </label>

        <button
          type="button"
          class="mt-2 h-10 border px-3 font-mono text-[11px] disabled:opacity-40"
          style="border-color: #f59e0b; color: #0b0d10; background: #f59e0b; border-radius: 4px"
          :disabled="!optionsDirty || mutating"
          @click="saveOptions"
        >
          Сохранить опции
        </button>
      </div>
    </section>

    <!-- B) Manual upload -->
    <section class="border p-4" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
      <h3 class="text-xs font-medium text-white">Ручной импорт файлов 1С</h3>
      <p class="mt-1 text-[11px]" style="color: #6b7280">
        Drag-and-drop для <span class="font-mono text-white">import.xml</span> и
        <span class="font-mono text-white">offers.xml</span> (строго .xml).
      </p>

      <div
        class="mt-3 flex min-h-[140px] cursor-pointer flex-col items-center justify-center border border-dashed px-4 py-6 text-center transition-colors"
        :style="{
          borderColor: dragOver ? '#f59e0b' : '#374151',
          background: dragOver ? '#1a1408' : '#0b0d10',
          borderRadius: '4px',
        }"
        role="button"
        tabindex="0"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop="onDrop"
        @click="fileInputRef?.click()"
        @keydown.enter.prevent="fileInputRef?.click()"
      >
        <div class="font-mono text-[12px] text-white">Перетащите XML сюда или нажмите для выбора</div>
        <div class="mt-1 text-[11px]" style="color: #6b7280">MIME: application/xml · text/xml · до 50 МБ</div>
        <input
          ref="fileInputRef"
          type="file"
          accept=".xml,application/xml,text/xml"
          class="hidden"
          @change="onFilePick"
        >
      </div>

      <div
        v-if="uploadProgress.phase !== 'idle'"
        class="mt-3 border p-3"
        style="background: #0b0d10; border-color: #1f2937; border-radius: 4px"
      >
        <div class="flex flex-wrap items-center justify-between gap-2 font-mono text-[11px]">
          <span class="text-white">{{ uploadProgress.fileName || 'файл' }} · {{ uploadPhaseLabel }}</span>
          <span style="color: #9ca3af">
            SKU: {{ uploadProgress.processedSkus.toLocaleString('ru-RU') }} · {{ uploadProgress.percent }}%
          </span>
        </div>
        <div class="mt-2 h-2 w-full overflow-hidden" style="background: #1f2937; border-radius: 2px">
          <div
            class="h-full transition-all duration-300"
            :style="{ width: `${uploadProgress.percent}%`, background: uploadBarColor }"
          />
        </div>
        <p v-if="uploadProgress.error" class="mt-2 text-[11px]" style="color: #FCA5A5">
          {{ uploadProgress.error }}
        </p>
      </div>
    </section>

    <!-- C) Logs -->
    <section class="border p-4" style="background: #11151a; border-color: #1f2937; border-radius: 4px">
      <OneCSyncLogsTable
        :logs="logs"
        :loading="loading"
        @refresh="store.fetchLogs(1)"
      />
    </section>

    <!-- Reset confirm modal -->
    <Teleport to="body">
      <div
        v-if="resetOpen"
        class="fixed inset-0 z-[90] flex items-end justify-center sm:items-center"
        role="dialog"
        aria-modal="true"
        aria-label="Сброс доступа 1С"
      >
        <button type="button" class="absolute inset-0 bg-black/60" aria-label="Закрыть" @click="resetOpen = false" />
        <div
          class="relative z-10 w-full max-w-md border-t p-4 sm:rounded sm:border"
          style="background: #11151a; border-color: #1f2937; border-radius: 12px 12px 0 0"
        >
          <h3 class="text-sm font-semibold text-white">Сбросить доступ 1С?</h3>
          <p class="mt-2 text-xs" style="color: #9ca3af">
            Будут сгенерированы новый логин и пароль. Текущие учётные данные 1С перестанут работать —
            обновите их в конфигурации обмена.
          </p>
          <div class="mt-4 flex flex-col gap-2 sm:flex-row-reverse">
            <button
              type="button"
              class="h-12 flex-1 border font-mono text-xs font-bold uppercase disabled:opacity-50"
              style="background: #EF4444; color: #fff; border-color: #EF4444; border-radius: 4px"
              :disabled="mutating"
              @click="confirmReset"
            >
              Сгенерировать
            </button>
            <button
              type="button"
              class="h-12 border px-4 font-mono text-xs"
              style="border-color: #1f2937; color: #9ca3af; border-radius: 4px; background: #0b0d10"
              :disabled="mutating"
              @click="resetOpen = false"
            >
              Отмена
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
