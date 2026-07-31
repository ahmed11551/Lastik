<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

type ProductOption = {
  id: number
  name: string
  sku?: string
  price: number
  vat_rate: number
  available: number
  unit: string
  type?: string
  category?: string
  radius_modifier?: Record<string, number | string>
}

type CartLine = {
  product_id: number
  name: string
  sku?: string
  price: number
  qty: number
  available: number
  vat_rate: number
  line_total: number
  type?: string
  radius?: string | null
  worker_id?: number | null
  commission_rate?: number | null
}

type FormState = {
  customer_id: number | null
  employee_id: number | null
  items: CartLine[]
  note: string
}

const props = defineProps<{
  customers: { id: number; name: string }[]
  employees: { id: number; name: string }[]
  products: ProductOption[]
  services: ProductOption[]
  flash?: { message?: string; error?: string }
  activeShift?: { id: number; opened_at?: string } | null
}>()

const search = ref('')
const searchResults = ref<ProductOption[]>([])
const searchInput = ref<HTMLInputElement | null>(null)
const stockLimitError = ref<string | null>(null)
const showOpenShiftModal = ref(false)
const openShiftInitialCash = ref(0)
const showCloseShiftModal = ref(false)
const actualCash = ref(0)
const closeShiftError = ref<string | null>(null)

const activeCategory = ref<string | null>(null)
const categoryServices = computed<ProductOption[]>(() => {
  if (!activeCategory.value) return props.services
  return props.services.filter(p => p.category === activeCategory.value)
})
const selectedService = ref<ProductOption | null>(null)
const selectedRadius = ref<string | null>(null)

const form = useForm<FormState>({
  customer_id: null,
  employee_id: null,
  items: [],
  note: '',
})

const totals = computed(() => {
  const subtotal = form.items.reduce((acc, line) => acc + line.line_total, 0)
  const vat = form.items.reduce(
    (acc, line) => acc + line.line_total * (line.vat_rate / 100),
    0,
  )
  const total = subtotal + vat
  return { subtotal, vat, total, count: form.items.length }
})

const stockLimitMessage = computed(() => stockLimitError.value)

const shiftLocked = computed(() => props.activeShift === null || props.activeShift === undefined)

function resolveServicePrice(service: ProductOption, radius: string | null): number {
  const base = Number(service.price || 0)
  if (!radius || !service.radius_modifier) return base
  const value = service.radius_modifier[radius]
  const modifier = typeof value === 'number' ? value : Number(value || 0)
  return Number((base + modifier).toFixed(2))
}

function selectService(service: ProductOption) {
  selectedService.value = service
  selectedRadius.value = null
}

function confirmService() {
  const service = selectedService.value
  if (!service) return
  const radius = selectedRadius.value
  const price = resolveServicePrice(service, radius)
  const line: CartLine = {
    product_id: service.id,
    name: service.name,
    sku: service.sku,
    price,
    qty: 1,
    available: service.available,
    vat_rate: service.vat_rate,
    line_total: price,
    type: 'service',
    radius: radius ?? '',
    worker_id: form.employee_id,
    commission_rate: null,
  }
  form.items = [...form.items, line]
  selectedService.value = null
  selectedRadius.value = null
}

function mapToCartLine(product: ProductOption, qty: number): CartLine {
  const safeQty = Math.min(Math.max(1, qty), product.available)
  return {
    product_id: product.id,
    name: product.name,
    sku: product.sku,
    price: product.price,
    qty: safeQty,
    available: product.available,
    vat_rate: product.vat_rate,
    line_total: Number((product.price * safeQty).toFixed(2)),
    type: product.type,
    radius: null,
    worker_id: form.employee_id,
    commission_rate: null,
  }
}

const addToCart = (product: ProductOption, qty = 1) => {
  stockLimitError.value = null
  const existing = form.items.find((line) => line.product_id === product.id)
  if (existing) {
    const requested = existing.qty + qty
    if (requested > product.available) {
      stockLimitError.value = `Доступно ${product.available} ${product.unit}`
      return
    }
    existing.qty = requested
    existing.line_total = Number((existing.price * requested).toFixed(2))
    return
  }

  if (qty > product.available) {
    stockLimitError.value = `Доступно ${product.available} ${product.unit}`
    return
  }

  form.items = [...form.items, mapToCartLine(product, qty)]
}

const updateLineQty = (index: number, qty: number) => {
  const line = form.items[index]
  const parsed = Number.isFinite(qty) ? Math.round(qty) : 0
  if (parsed < 0) return
  if (parsed > line.available) {
    stockLimitError.value = `Доступно ${line.available} ${line.unit}`
    return
  }
  stockLimitError.value = null
  line.qty = parsed
  line.line_total = Number((line.price * parsed).toFixed(2))
}

const removeLine = (index: number) => {
  form.items = form.items.filter((_, i) => i !== index)
  stockLimitError.value = null
}

const clearCart = () => {
  form.items = []
  stockLimitError.value = null
}

const submitOrder = () => {
  if (shiftLocked.value) {
    stockLimitError.value = 'Кассовая смена не открыта'
    return
  }
  if (!form.items.length) {
    stockLimitError.value = 'Добавьте позиции в заказ'
    return
  }
  if (form.processing) return
  form.post(route('orders.store'), {
    onSuccess: () => clearCart(),
  })
}

const openPayment = () => {
  if (shiftLocked.value) {
    stockLimitError.value = 'Кассовая смена не открыта'
    return
  }
  if (!form.items.length) {
    stockLimitError.value = 'Добавьте позиции в заказ'
    return
  }
  if (form.processing) return
  router.visit(route('pos.payment.index'), {
    data: {
      items: form.items,
      customer_id: form.customer_id,
      employee_id: form.employee_id,
      note: form.note,
    },
    method: 'get',
  })
}

const submitOpenShift = () => {
  const payload: Record<string, unknown> = {
    opening_amount: Number(openShiftInitialCash.value || 0),
  }
  router.post(route('cash-shifts.store'), payload, {
    onSuccess: () => {
      showOpenShiftModal.value = false
      openShiftInitialCash.value = 0
    },
  })
}

const submitCloseShift = () => {
  closeShiftError.value = null
  router.post(
    route('cash-shifts.close'),
    { actual_cash: Number(actualCash.value || 0) },
    {
      onSuccess: () => {
        showCloseShiftModal.value = false
        actualCash.value = 0
      },
      onError: () => {
        closeShiftError.value = 'Не удалось закрыть смену'
      },
    },
  )
}

function doSearch(value: string) {
  const query = value.trim().toLowerCase()
  if (query.length < 2) {
    searchResults.value = []
    return
  }
  searchResults.value = props.products
    .filter((p) => {
      const hay = `${p.name} ${p.sku ?? ''}`.toLowerCase()
      return hay.includes(query)
    })
    .slice(0, 12)
}

watch(search, (value) => doSearch(value))

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'F2') {
    event.preventDefault()
    searchInput.value?.focus()
  }
  if (event.key === 'F9') {
    event.preventDefault()
    if (!shiftLocked.value) {
      submitOrder()
    } else {
      stockLimitError.value = 'Кассовая смена не открыта'
    }
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="space-y-6">
    <div v-if="shiftLocked" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      ⚠️ Кассовая смена не открыта. Откройте смену перед проведением заказов.
      <button
        type="button"
        class="ml-3 rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-800"
        @click="showOpenShiftModal = true"
      >
        Открыть смену
      </button>
    </div>

    <div class="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
      <div>
        <h1 class="text-xl font-semibold">Новый заказ</h1>
        <p class="text-xs text-muted-foreground">F2 — поиск позиций, F9 — оплата</p>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="rounded-xl border border-border px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
          :disabled="!form.items.length"
          @click="clearCart"
        >
          Очистить
        </button>
        <button
          type="button"
          class="rounded-xl bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
          :disabled="form.processing || !form.items.length || shiftLocked"
          @click="submitOrder"
        >
          Сохранить заказ
        </button>
        <button
          type="button"
          class="rounded-xl bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
          :disabled="form.processing || !form.items.length || shiftLocked"
          @click="openPayment"
        >
          Оплатить (F9)
        </button>
        <button
          v-if="!shiftLocked"
          type="button"
          class="rounded-xl border border-border px-3 py-2 text-xs hover:bg-accent hover:text-accent-foreground"
          @click="showCloseShiftModal = true"
        >
          Закрыть смену
        </button>
      </div>
    </div>

    <div v-if="flash?.message" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
      {{ flash.message }}
    </div>
    <div v-if="flash?.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
      {{ flash.error }}
    </div>
    <div v-if="stockLimitMessage" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      {{ stockLimitMessage }}
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div class="space-y-5 lg:col-span-4">
        <section class="rounded-2xl border border-border bg-card p-4">
          <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Поиск позиции</label>
          <input
            ref="searchInput"
            v-model="search"
            type="search"
            placeholder="Шина, диск, услуга..."
            class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm outline-none transition-colors focus:border-primary focus:ring-2 focus:ring-primary/20"
          />
          <div v-if="searchResults.length" class="mt-2 max-h-64 space-y-1 overflow-y-auto rounded-xl border border-border">
            <button
              v-for="item in searchResults"
              :key="item.id"
              type="button"
              class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-accent hover:text-accent-foreground"
              @click="addToCart(item, 1)"
            >
              <div class="min-w-0">
                <p class="truncate font-medium">{{ item.name }}</p>
                <p class="truncate text-xs text-muted-foreground">
                  {{ item.sku ?? 'Без артикула' }} · Доступно: {{ item.available }} {{ item.unit }}
                </p>
              </div>
              <div class="shrink-0 text-right">
                <p class="text-sm font-medium">{{ item.price.toFixed(2) }} ₽</p>
                <p class="text-xs text-muted-foreground">× {{ item.vat_rate }}% НДС</p>
              </div>
            </button>
          </div>
        </section>

        <section class="rounded-2xl border border-border bg-card p-4 space-y-3">
          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Услуги по категориям</label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="category in [{key:'installation',label:'Снятие/Установка'},{key:'balancing',label:'Балансировка'},{key:'repair',label:'Ремонт'}]"
                :key="category.key"
                type="button"
                class="rounded-lg border border-border px-2 py-1 text-xs hover:bg-accent"
                :class="activeCategory === category.key ? 'bg-primary text-primary-foreground border-primary' : ''"
                @click="activeCategory = activeCategory === category.key ? null : category.key"
              >
                {{ category.label }}
              </button>
            </div>
          </div>

          <div v-if="categoryServices.length" class="space-y-1">
            <button
              v-for="service in categoryServices"
              :key="service.id"
              type="button"
              class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-accent"
              :class="selectedService?.id === service.id ? 'border border-primary bg-accent/40' : 'border border-border'"
              @click="selectService(service)"
            >
              <div>
                <p class="text-sm font-medium">{{ service.name }}</p>
                <p class="text-xs text-muted-foreground">{{ service.category }} · {{ service.price.toFixed(2) }} ₽</p>
              </div>
            </button>
          </div>
          <div v-else class="text-xs text-muted-foreground">Нет услуг в выбранной категории.</div>
        </section>

        <section v-if="selectedService" class="rounded-2xl border border-border bg-card p-4 space-y-3">
          <div>
            <p class="text-sm font-medium">Услуга: {{ selectedService.name }}</p>
            <p class="text-xs text-muted-foreground">Базовая цена: {{ selectedService.price.toFixed(2) }} ₽</p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Радиус колеса</label>
            <select
              v-model="selectedRadius"
              class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
            >
              <option value="">Без изменения цены</option>
              <option v-for="radius in Object.keys(selectedService.radius_modifier || {})" :key="radius" :value="radius">
                {{ radius }} (+{{ selectedService.radius_modifier?.[radius] ?? 0 }} ₽)
              </option>
            </select>
            <p class="mt-1 text-xs text-muted-foreground">
              Итого по услуге: {{ resolveServicePrice(selectedService, selectedRadius).toFixed(2) }} ₽
            </p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Мастер-исполнитель</label>
            <select
              v-model="form.employee_id"
              class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
            >
              <option :value="null">Выберите мастера</option>
              <option v-for="master in employees" :key="master.id" :value="master.id">
                {{ master.name }}
              </option>
            </select>
          </div>
          <button
            type="button"
            class="w-full rounded-xl bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
            :disabled="!form.employee_id"
            @click="confirmService"
          >
            Добавить услугу в чек
          </button>
        </section>

        <section class="rounded-2xl border border-border bg-card p-4 space-y-4">
          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Клиент</label>
            <select
              v-model="form.customer_id"
              class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
            >
              <option :value="null">Без клиента</option>
              <option v-for="client in customers" :key="client.id" :value="client.id">
                {{ client.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Мастер KPI</label>
            <select
              v-model="form.employee_id"
              class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
            >
              <option :value="null">Не выбран</option>
              <option v-for="master in employees" :key="master.id" :value="master.id">
                {{ master.name }}
              </option>
            </select>
            <p class="mt-1 text-xs text-muted-foreground">
              Привязка мастера нужна для учёта сдельной оплаты по услугам.
            </p>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Примечание</label>
            <textarea
              v-model="form.note"
              rows="3"
              class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
              placeholder="Комментарий к заказу..."
            />
          </div>
        </section>
      </div>

      <section class="space-y-3 lg:col-span-8">
        <div class="rounded-2xl border border-border bg-card">
          <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <div>
              <h2 class="text-sm font-medium">Корзина</h2>
              <p class="text-xs text-muted-foreground">{{ totals.count }} позиций</p>
            </div>
            <span class="text-xs text-muted-foreground">F9 — оплатить</span>
          </div>

          <div v-if="!form.items.length" class="px-4 py-10 text-center text-xs text-muted-foreground">
            Добавьте позиции из поиска слева.
          </div>

          <div v-else class="divide-y divide-border">
            <div
              v-for="(line, index) in form.items"
              :key="line.product_id + '-' + index"
              class="flex flex-col gap-3 px-4 py-3 md:flex-row md:items-center md:justify-between"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium">{{ line.name }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ line.sku ?? 'Без артикула' }} · Доступно: {{ line.available }} {{ line.unit }}
                </p>
                <p v-if="line.type === 'service'" class="text-xs text-muted-foreground">
                  Услуга{{ line.radius ? ` · ${line.radius}` : '' }}
                </p>
              </div>

              <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                  <button
                    type="button"
                    class="rounded-lg border border-border px-2 py-1 text-xs hover:bg-accent hover:text-accent-foreground"
                    :disabled="line.qty <= 1"
                    @click="updateLineQty(index, line.qty - 1)"
                  >
                    −
                  </button>
                  <input
                    :value="line.qty"
                    type="number"
                    min="0"
                    :max="line.available"
                    class="h-8 w-14 rounded-lg border border-border bg-background px-2 text-center text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                    @change="updateLineQty(index, Number(($event.target as HTMLInputElement).value))"
                  />
                  <button
                    type="button"
                    class="rounded-lg border border-border px-2 py-1 text-xs hover:bg-accent hover:text-accent-foreground"
                    :disabled="line.qty >= line.available"
                    @click="updateLineQty(index, line.qty + 1)"
                  >
                    +
                  </button>
                </div>

                <div class="text-right text-sm">
                  <p class="font-medium">{{ line.line_total.toFixed(2) }} ₽</p>
                  <p class="text-xs text-muted-foreground">{{ line.price.toFixed(2) }} ₽ × {{ line.qty }}</p>
                </div>

                <button
                  type="button"
                  class="rounded-lg p-1.5 text-red-600 hover:bg-red-50"
                  @click="removeLine(index)"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <div v-if="form.items.length" class="border-t border-border px-4 py-4 space-y-1">
            <div class="flex items-center justify-between text-xs text-muted-foreground">
              <span>Подытог</span>
              <span>{{ totals.subtotal.toFixed(2) }} ₽</span>
            </div>
            <div class="flex items-center justify-between text-xs text-muted-foreground">
              <span>НДС</span>
              <span>{{ totals.vat.toFixed(2) }} ₽</span>
            </div>
            <div class="flex items-center justify-between text-base font-semibold">
              <span>Итого</span>
              <span>{{ totals.total.toFixed(2) }} ₽</span>
            </div>
          </div>
        </div>
      </section>
    </div>

    <div v-if="showOpenShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div class="w-full max-w-md rounded-2xl border border-border bg-background p-5 shadow-xl">
        <h3 class="text-base font-semibold">Открыть кассовую смену</h3>
        <p class="mt-1 text-xs text-muted-foreground">Укажите начальный остаток наличности.</p>
        <label class="mt-3 mb-1.5 block text-xs font-medium text-muted-foreground">Начальный остаток, ₽</label>
        <input
          v-model="openShiftInitialCash"
          type="number"
          class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
        />
        <div class="mt-4 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-xl border border-border px-3 py-2 text-sm hover:bg-accent"
            @click="showOpenShiftModal = false"
          >
            Отмена
          </button>
          <button
            type="button"
            class="rounded-xl bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
            :disabled="form.processing"
            @click="submitOpenShift"
          >
            Открыть смену
          </button>
        </div>
      </div>
    </div>

    <div v-if="showCloseShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div class="w-full max-w-md rounded-2xl border border-border bg-background p-5 shadow-xl">
        <h3 class="text-base font-semibold">Z-отчет и закрытие смены</h3>
        <p class="mt-1 text-xs text-muted-foreground">Введите фактический остаток наличности в кассе.</p>
        <label class="mt-3 mb-1.5 block text-xs font-medium text-muted-foreground">Фактические наличные, ₽</label>
        <input
          v-model="actualCash"
          type="number"
          class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm"
        />
        <div v-if="closeShiftError" class="mt-2 text-xs text-red-600">{{ closeShiftError }}</div>
        <div class="mt-4 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-xl border border-border px-3 py-2 text-sm hover:bg-accent"
            @click="showCloseShiftModal = false"
          >
            Отмена
          </button>
          <button
            type="button"
            class="rounded-xl bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
            :disabled="form.processing"
            @click="submitCloseShift"
          >
            Закрыть смену
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
