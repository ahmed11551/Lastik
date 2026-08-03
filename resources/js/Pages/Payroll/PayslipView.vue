<script setup>
import { computed, onMounted, watch } from 'vue'
import { usePayrollStore } from '@/autometria/stores/usePayrollStore'

const props = defineProps({ payslipId: { type: Number, default: null } })
const store = usePayrollStore()
const payslip = computed(() => store.currentPayslip)
function money(value) { return Number(value || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }
async function load() { if (props.payslipId) await store.fetchPayslip(props.payslipId) }
onMounted(() => { void load() })
watch(() => props.payslipId, () => { void load() })
</script>

<template>
  <div>
    <div v-if="store.loading" class="ds-skeleton"><div class="ds-skeleton__row" /></div>
    <div v-else-if="payslip" class="space-y-4">
      <div class="ds-surface flex flex-wrap justify-between gap-3 p-4">
        <div><div class="text-sm text-gray-400">Сотрудник</div><strong>{{ payslip.user_name || ('#' + payslip.user_id) }}</strong></div>
        <span class="ds-badge">{{ payslip.status }}</span>
      </div>
      <div class="ds-surface overflow-x-auto">
        <table class="w-full text-sm"><thead><tr><th class="p-3 text-left">Тип</th><th class="p-3 text-left">Статья</th><th class="p-3 text-right">Сумма</th></tr></thead>
          <tbody><tr v-for="item in payslip.items" :key="item.id"><td class="p-3">{{ item.type === 'DEDUCTION' ? 'Удержание' : 'Начисление' }}</td><td class="p-3">{{ item.label }}</td><td class="p-3 text-right">{{ money(item.amount) }}</td></tr></tbody>
        </table>
      </div>
      <div class="ds-surface ml-auto grid max-w-md gap-2 p-4 text-right"><div>Начислено: <strong>{{ money(payslip.gross) }}</strong></div><div>Удержано: <strong>{{ money(payslip.deductions_total) }}</strong></div><div class="text-lg">К выплате: <strong>{{ money(payslip.net) }}</strong></div></div>
    </div>
    <div v-else class="ds-surface p-4">Ведомость не найдена.</div>
  </div>
</template>
