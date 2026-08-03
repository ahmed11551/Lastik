<script setup>
import { computed, onMounted, ref } from 'vue'
import { DsTable } from '@/design-system'
import { usePayrollStore } from '@/autometria/stores/usePayrollStore'

const emit = defineEmits(['navigate'])
const store = usePayrollStore()
const createOpen = ref(false)
const form = ref({ name: '', period_from: '', period_to: '' })
const columns = [{ key: 'name', label: 'Период' }, { key: 'period_from', label: 'С' }, { key: 'period_to', label: 'По' }, { key: 'status', label: 'Статус' }, { key: 'total_gross', label: 'Начислено' }, { key: 'total_deductions', label: 'Удержано' }, { key: 'total_net', label: 'К выплате' }, { key: 'actions', label: '' }]
const rows = computed(() => store.periods.map((p) => ({ ...p, total_gross: Number(p.total_gross || 0).toLocaleString('ru-RU'), total_deductions: Number(p.total_deductions || 0).toLocaleString('ru-RU'), total_net: Number(p.total_net || 0).toLocaleString('ru-RU') })))
async function create() { await store.createPeriod(form.value); createOpen.value = false; form.value = { name: '', period_from: '', period_to: '' } }
async function action(id, name) { await store.transition(id, name) }
async function openPayslip(periodId) { const rows = await store.fetchPayslips(periodId); if (rows[0]) emit('navigate', 'payslip:' + rows[0].id) }
onMounted(() => { void store.fetchPeriods() })
</script>

<template>
  <div>
    <div class="ds-toolbar">
      <div class="ds-toolbar__actions">
        <button class="ds-btn ds-btn-ghost ds-btn-sm" @click="store.fetchPeriods()">Обновить</button>
        <button class="ds-btn ds-btn-primary ds-btn-sm" data-testid="payroll-create" @click="createOpen = true">+ Период</button>
      </div>
    </div>
    <div v-if="store.error" class="ds-surface mb-3 p-3 text-red-400">{{ store.error }}</div>
    <form v-if="createOpen" class="ds-surface mb-4 grid gap-3 p-4 md:grid-cols-4" @submit.prevent="create">
      <input v-model="form.name" class="ds-input" placeholder="Название периода" required>
      <input v-model="form.period_from" class="ds-input" type="date" required>
      <input v-model="form.period_to" class="ds-input" type="date" required>
      <div class="flex gap-2"><button class="ds-btn ds-btn-primary" :disabled="store.saving">Создать</button><button type="button" class="ds-btn ds-btn-ghost" @click="createOpen = false">Отмена</button></div>
    </form>
    <DsTable :columns="columns" :rows="rows" :loading="store.loading" empty-text="Расчётных периодов пока нет">
      <template #status="{ value }"><span class="ds-badge">{{ value }}</span></template>
      <template #actions="{ row }">
        <div class="flex gap-1">
          <button v-if="row.status === 'DRAFT'" class="ds-btn ds-btn-sm" data-testid="payroll-calculate" @click="action(row.id, 'calculate')">Рассчитать</button>
          <button v-if="row.status === 'CALCULATED'" class="ds-btn ds-btn-sm" @click="action(row.id, 'approve')">Утвердить</button>
          <button v-if="row.status === 'APPROVED'" class="ds-btn ds-btn-primary ds-btn-sm" @click="action(row.id, 'pay')">Оплатить</button>
          <button v-if="row.status !== 'DRAFT'" class="ds-btn ds-btn-ghost ds-btn-sm" @click="openPayslip(row.id)">Ведомости</button>
        </div>
      </template>
    </DsTable>
  </div>
</template>
