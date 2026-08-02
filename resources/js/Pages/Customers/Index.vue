<script setup lang="ts">
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import { DsTable } from '@/design-system'
import { Head } from '@inertiajs/vue3'
import { onMounted, ref, computed } from 'vue'
import { apiGet } from '@/autometria/api/client'

defineProps({
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: { type: [String, Number, Date], default: null },
  shiftRevenue: { type: [Number, String], default: 0 },
})

const customers = ref<any[]>([])
const loading = ref(false)
const error = ref('')
const query = ref('')

const txCustomerId = ref<number | null>(null)
const txList = ref<any[]>([])
const txLoading = ref(false)

const columns = [
  { key: 'name', label: 'Имя' },
  { key: 'phone', label: 'Телефон' },
  { key: 'discount_card_number', label: 'Карта' },
  { key: 'tier', label: 'Уровень' },
  { key: 'bonus_balance', label: 'Бонусы' },
]

const rows = computed(() =>
  customers.value.map((c) => ({
    ...c,
    tier: c.tier || '—',
    bonus_balance: Number(c.bonus_balance || 0).toLocaleString('ru-RU') + ' ₽',
  })),
)

async function loadCustomers() {
  loading.value = true
  error.value = ''
  try {
    const payload = await apiGet('/customers', { params: { q: query.value || undefined }, silent: true })
    customers.value = Array.isArray(payload?.data) ? payload.data : []
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Ошибка загрузки'
    customers.value = []
  } finally {
    loading.value = false
  }
}

async function openHistory(id: number) {
  txCustomerId.value = id
  txLoading.value = true
  txList.value = []
  try {
    const payload = await apiGet('/loyalty/transactions', { params: { customer_id: id }, silent: true })
    txList.value = Array.isArray(payload?.data) ? payload.data : []
  } catch {
    txList.value = []
  } finally {
    txLoading.value = false
  }
}

onMounted(loadCustomers)
</script>

<template>
  <Head title="Клиенты" />

  <AutometriaLayout
    title="Клиенты и лояльность"
    active-nav="customers"
    :current-shift-open="currentShiftOpen"
    :shift-started-at="shiftStartedAt"
    :shift-revenue="shiftRevenue"
    :breadcrumbs="[{ label: 'CRM' }, { label: 'Клиенты' }]"
  >
    <div class="mb-4 flex gap-2">
      <input v-model="query" type="text" class="ds-input h-9 flex-1" placeholder="Поиск по имени / телефону…" data-testid="customer-query" @input="loadCustomers" />
    </div>

    <div v-if="loading" class="ds-surface mb-4 p-3">Загрузка…</div>
    <div v-if="error" class="ds-surface mb-4 p-3" style="color: var(--color-danger)">{{ error }}</div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <DsTable :columns="columns" :rows="rows" density="compact" sticky-header>
          <template #name="{ value, row }">
            <button type="button" class="ds-link" data-testid="open-history" @click="openHistory(row.id)">{{ value }}</button>
          </template>
        </DsTable>
      </div>

      <div class="ds-surface p-3">
        <h3 class="mb-2 font-semibold">История бонусов</h3>
        <div v-if="!txCustomerId" class="text-[12px]" style="color: var(--color-text-secondary)">Выберите клиента</div>
        <div v-else-if="txLoading" class="text-[12px]">Загрузка…</div>
        <div v-else-if="!txList.length" class="text-[12px]" style="color: var(--color-text-secondary)">Нет транзакций</div>
        <ul v-else class="space-y-1 text-[12px]">
          <li v-for="(t, i) in txList" :key="i" class="flex justify-between border-b border-[var(--color-border)] py-1">
            <span>{{ t.type }} · {{ (t.created_at || '').slice(0, 10) }}</span>
            <span :style="{ color: Number(t.amount) >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }">
              {{ Number(t.amount).toLocaleString('ru-RU') }} ₽
            </span>
          </li>
        </ul>
      </div>
    </div>
  </AutometriaLayout>
</template>
