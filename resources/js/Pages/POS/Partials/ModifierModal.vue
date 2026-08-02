<script setup lang="ts">
import { DsModal, DsButton } from '@/design-system'
import { ref, computed } from 'vue'

const props = defineProps<{
  open: boolean
  productName?: string
  groups?: Array<{
    id: string
    label: string
    multiple?: boolean
    options: Array<{ id: string; label: string; price_delta?: number }>
  }>
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'confirm', payload: { optionIds: string[]; totalDelta: number }): void
}>()

const defaultGroups = [
  {
    id: 'size',
    label: 'Размер',
    multiple: false,
    options: [
      { id: 's', label: 'Маленький', price_delta: 0 },
      { id: 'm', label: 'Средний', price_delta: 50 },
      { id: 'l', label: 'Большой', price_delta: 100 },
    ],
  },
  {
    id: 'addons',
    label: 'Добавки',
    multiple: true,
    options: [
      { id: 'cheese', label: 'Сыр', price_delta: 30 },
      { id: 'sauce', label: 'Соус', price_delta: 20 },
      { id: 'bacon', label: 'Бекон', price_delta: 40 },
    ],
  },
  {
    id: 'doneness',
    label: 'Прожарка',
    multiple: false,
    options: [
      { id: 'rare', label: 'С кровью', price_delta: 0 },
      { id: 'medium', label: 'Средне', price_delta: 0 },
      { id: 'well', label: 'Прожаренное', price_delta: 0 },
    ],
  },
]

const groups = computed(() => props.groups ?? defaultGroups)
const selected = ref<Record<string, string[]>>({})

function toggle(groupId: string, optionId: string, multiple: boolean) {
  if (!selected.value[groupId]) selected.value[groupId] = []
  if (multiple) {
    const arr = selected.value[groupId]
    const i = arr.indexOf(optionId)
    if (i >= 0) arr.splice(i, 1)
    else arr.push(optionId)
  } else {
    selected.value[groupId] = [optionId]
  }
}

function isActive(groupId: string, optionId: string) {
  return (selected.value[groupId] || []).includes(optionId)
}

const totalDelta = computed(() => {
  let sum = 0
  for (const g of groups.value) {
    for (const optId of selected.value[g.id] || []) {
      const opt = g.options.find((o) => o.id === optId)
      if (opt) sum += Number(opt.price_delta || 0)
    }
  }
  return sum
})

function confirm() {
  const optionIds: string[] = []
  for (const g of groups.value) optionIds.push(...(selected.value[g.id] || []))
  emit('confirm', { optionIds, totalDelta: totalDelta.value })
  emit('update:open', false)
}
</script>

<template>
  <DsModal :show="open" @close="emit('update:open', false)">
    <template #title>Опции: {{ productName || 'Блюдо' }}</template>
    <div class="space-y-4">
      <div v-for="g in groups" :key="g.id">
        <div class="mb-1 text-[12px] font-semibold uppercase tracking-[0.08em]" style="color: var(--color-text-secondary)">
          {{ g.label }}
        </div>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="opt in g.options"
            :key="opt.id"
            type="button"
            class="ds-btn ds-btn-sm"
            :class="isActive(g.id, opt.id) ? 'ds-btn-primary' : 'ds-btn-ghost'"
            :data-testid="`modifier-${g.id}-${opt.id}`"
            @click="toggle(g.id, opt.id, Boolean(g.multiple))"
          >
            {{ opt.label }}<span v-if="opt.price_delta"> +{{ opt.price_delta }}₽</span>
          </button>
        </div>
      </div>

      <div class="flex items-center justify-between border-t border-[var(--color-border)] pt-2">
        <span class="text-[13px]" style="color: var(--color-text-secondary)">Доплата:</span>
        <span class="font-bold" style="color: var(--color-primary)">+{{ totalDelta }} ₽</span>
      </div>

      <DsButton class="ds-btn-primary ds-btn-sm w-full" data-testid="modifier-confirm" @click="confirm">Добавить в чек</DsButton>
    </div>
  </DsModal>
</template>
