<script setup lang="ts">
import { DsModal, DsInput, DsButton, DsBadge } from '@/design-system'
import { ref, computed } from 'vue'
import { storeToRefs } from 'pinia'
import { usePosStore } from '@/stores/usePosStore'
import { apiGet } from '@/autometria/api/client'

const store = usePosStore()
const { selectedCustomer, bonusSpend, bonusError, payableAmount, maxBonusSpend } = storeToRefs(store)

const query = ref('')
const results = ref<any[]>([])
const searching = ref(false)
const showRegister = ref(false)
const regName = ref('')
const regPhone = ref('')
const regError = ref('')

const tierLabel: Record<string, string> = {
  BRONZE: 'Бронза',
  SILVER: 'Серебро',
  GOLD: 'Золото',
  PLATINUM: 'Платина',
}

async function search() {
  const q = query.value.trim()
  if (!q) {
    results.value = []
    return
  }
  searching.value = true
  try {
    const payload = await apiGet('/customers', {
      params: { q, phone: q, card: q },
      silent: true,
    })
    const list = Array.isArray(payload?.data) ? payload.data : []
    results.value = list.map((c: any) => ({
      id: c.id,
      name: c.name,
      phone: c.phone,
      discount_card_number: c.discount_card_number ?? null,
      bonus_balance: Number(c.bonus_balance || 0),
      tier: c.tier || '',
    }))
  } catch {
    results.value = []
  } finally {
    searching.value = false
  }
}

function pick(c: any) {
  store.selectCustomer(c)
  results.value = []
  query.value = ''
}

async function register() {
  regError.value = ''
  if (!regName.value.trim() || !regPhone.value.trim()) {
    regError.value = 'Укажите имя и телефон'
    return
  }
  try {
    await store.registerCustomer({ name: regName.value.trim(), phone: regPhone.value.trim() })
    showRegister.value = false
    regName.value = ''
    regPhone.value = ''
  } catch (e: any) {
    regError.value = e?.response?.data?.message || 'Ошибка регистрации'
  }
}

function onBonusInput(e: Event) {
  const v = Number((e.target as HTMLInputElement).value || 0)
  store.setBonusSpend(v)
}

const maxSpend = computed(() => maxBonusSpend.value)
</script>

<template>
  <div class="customer-selector">
    <!-- Selected customer summary -->
    <div v-if="selectedCustomer" class="ds-surface mb-3 p-3">
      <div class="flex items-center justify-between">
        <div>
          <div class="font-semibold">{{ selectedCustomer.name }}</div>
          <div class="text-[11px]" style="color: var(--color-text-secondary)">
            {{ selectedCustomer.phone }}
            <span v-if="selectedCustomer.discount_card_number"> · карта {{ selectedCustomer.discount_card_number }}</span>
          </div>
        </div>
        <div class="text-right">
          <DsBadge v-if="selectedCustomer.tier" class="ds-badge--info">{{ tierLabel[selectedCustomer.tier] || selectedCustomer.tier }}</DsBadge>
          <div class="text-[11px]" style="color: var(--color-text-secondary)">Бонусов</div>
          <div class="font-semibold">{{ Number(selectedCustomer.bonus_balance).toLocaleString('ru-RU') }} ₽</div>
        </div>
      </div>

      <!-- Bonus spend input -->
      <div class="mt-3">
        <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">
          Списать бонусов (макс. {{ maxSpend.toLocaleString('ru-RU') }} ₽)
        </label>
        <DsInput
          :model-value="bonusSpend"
          type="number"
          min="0"
          :max="maxSpend"
          step="1"
          class="mt-1"
          data-testid="bonus-spend"
          @input="onBonusInput"
        />
        <div v-if="bonusError" class="mt-1 text-[11px]" style="color: var(--color-danger)">{{ bonusError }}</div>
        <div class="mt-1 flex justify-between text-[11px]" style="color: var(--color-text-secondary)">
          <span>Итого к оплате:</span>
          <span class="font-semibold" style="color: var(--color-text)">{{ payableAmount.toLocaleString('ru-RU') }} ₽</span>
        </div>
      </div>

      <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm mt-2" data-testid="clear-customer" @click="store.clearCustomer()">
        Сменить клиента
      </button>
    </div>

    <!-- Search box -->
    <div v-else class="flex gap-2">
      <DsInput
        v-model="query"
        placeholder="Телефон / карта / имя…"
        class="flex-1"
        data-testid="customer-search"
        @input="search"
      />
      <DsButton class="ds-btn-sm" data-testid="register-customer" @click="showRegister = true">+ Клиент</DsButton>
    </div>

    <!-- Results -->
    <div v-if="results.length" class="mt-2 space-y-1">
      <button
        v-for="c in results"
        :key="c.id"
        type="button"
        class="ds-surface flex w-full items-center justify-between p-2 text-left"
        data-testid="customer-result"
        @click="pick(c)"
      >
        <div>
          <div class="text-sm font-medium">{{ c.name }}</div>
          <div class="text-[11px]" style="color: var(--color-text-secondary)">{{ c.phone }}</div>
        </div>
        <DsBadge class="ds-badge--info">{{ Number(c.bonus_balance).toLocaleString('ru-RU') }} ₽</DsBadge>
      </button>
    </div>

    <!-- Register modal -->
    <DsModal :show="showRegister" @close="showRegister = false">
      <template #title>Новый клиент</template>
      <div class="space-y-3">
        <div>
          <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Имя</label>
          <DsInput v-model="regName" data-testid="reg-name" class="mt-1" />
        </div>
        <div>
          <label class="text-[11px] uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">Телефон</label>
          <DsInput v-model="regPhone" data-testid="reg-phone" class="mt-1" />
        </div>
        <div v-if="regError" class="text-[11px]" style="color: var(--color-danger)">{{ regError }}</div>
        <DsButton class="ds-btn-primary ds-btn-sm w-full" data-testid="reg-submit" @click="register">Зарегистрировать</DsButton>
      </div>
    </DsModal>
  </div>
</template>
