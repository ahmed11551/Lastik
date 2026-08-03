<script setup>
import { onMounted, reactive } from 'vue'
import { usePayrollStore } from '@/autometria/stores/usePayrollStore'

const store = usePayrollStore()
const deduction = reactive({ name: '', type: 'FIXED', value: 0 })
const accrual = reactive({ name: '', type: 'KPI_PERCENT', value: 0 })
async function addDeduction() { await store.createDeduction({ ...deduction }); Object.assign(deduction, { name: '', type: 'FIXED', value: 0 }) }
async function addAccrual() { await store.createAccrualRule({ ...accrual }); Object.assign(accrual, { name: '', type: 'KPI_PERCENT', value: 0 }) }
onMounted(() => { void store.fetchRules() })
</script>

<template>
  <div class="grid gap-5 lg:grid-cols-2">
    <section class="ds-surface p-4"><h2 class="mb-3 text-lg font-semibold">Правила начислений</h2>
      <form class="mb-4 grid gap-2" @submit.prevent="addAccrual"><input v-model="accrual.name" class="ds-input" placeholder="Название" required><select v-model="accrual.type" class="ds-input"><option value="KPI_PERCENT">KPI, %</option><option value="FIXED">Фиксированное</option><option value="BONUS">Бонус из выработки</option></select><input v-model.number="accrual.value" class="ds-input" type="number" min="0" step="0.01" placeholder="Значение"><button class="ds-btn ds-btn-primary">Добавить</button></form>
      <div v-for="rule in store.accrualRules" :key="rule.id" class="flex justify-between border-t p-2"><span>{{ rule.name }} · {{ rule.type }}</span><strong>{{ rule.value }}</strong></div>
    </section>
    <section class="ds-surface p-4"><h2 class="mb-3 text-lg font-semibold">Удержания</h2>
      <form class="mb-4 grid gap-2" @submit.prevent="addDeduction"><input v-model="deduction.name" class="ds-input" placeholder="Название" required><select v-model="deduction.type" class="ds-input"><option value="FIXED">Фиксированное</option><option value="PERCENT">Процент</option></select><input v-model.number="deduction.value" class="ds-input" type="number" min="0" step="0.01" placeholder="Значение"><button class="ds-btn ds-btn-primary">Добавить</button></form>
      <div v-for="item in store.deductions" :key="item.id" class="flex justify-between border-t p-2"><span>{{ item.name }} · {{ item.type }}</span><strong>{{ item.value }}</strong></div>
    </section>
  </div>
</template>
