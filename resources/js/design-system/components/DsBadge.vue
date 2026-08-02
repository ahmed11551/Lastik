<script setup>
/**
 * AUTOMETRIA ERP — B2B status badges (RU-first labels)
 * Dot + Badge: 6px indicator + label
 */
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    default: 'neutral',
  },
  label: {
    type: String,
    default: '',
  },
  variant: {
    type: String,
    default: '',
    validator: (v) =>
      !v ||
      [
        'success',
        'danger',
        'warning',
        'pending',
        'neutral',
        'suspended',
        'open',
        'closed',
        'active',
        'accepted',
        'negotiation',
        'rejected',
        'review',
      ].includes(v),
  },
  /** Colored 6px dot left of label */
  dot: {
    type: Boolean,
    default: false,
  },
})

const STATUS_MAP = {
  // Generic
  active: { variant: 'active', label: 'Активен' },
  pending: { variant: 'pending', label: 'Ожидание' },
  suspended: { variant: 'suspended', label: 'Заблокирован' },
  open: { variant: 'open', label: 'Открыта' },
  closed: { variant: 'closed', label: 'Закрыта' },
  success: { variant: 'success', label: 'OK' },
  danger: { variant: 'danger', label: 'Ошибка' },
  warning: { variant: 'warning', label: 'Внимание' },
  negotiation: { variant: 'negotiation', label: 'Переговоры' },
  rejected: { variant: 'rejected', label: 'Отклонён' },
  review: { variant: 'review', label: 'На проверке' },
  accepted: { variant: 'accepted', label: 'Принят' },
  prospective: { variant: 'neutral', label: 'Потенциальный' },

  // Purchasing / inventory / ops enums
  draft: { variant: 'neutral', label: 'Черновик' },
  confirmed: { variant: 'warning', label: 'Подтверждён' },
  partially_received: { variant: 'warning', label: 'Частично' },
  received: { variant: 'success', label: 'Принят' },
  cancelled: { variant: 'danger', label: 'Отменён' },
  canceled: { variant: 'danger', label: 'Отменён' },
  posted: { variant: 'success', label: 'Проведён' },
  new: { variant: 'neutral', label: 'Новый' },

  // RU aliases
  активен: { variant: 'active', label: 'Активен' },
  ожидание: { variant: 'pending', label: 'Ожидание' },
  заблокирован: { variant: 'suspended', label: 'Заблокирован' },
  открыта: { variant: 'open', label: 'Открыта' },
  закрыта: { variant: 'closed', label: 'Закрыта' },
  черновик: { variant: 'neutral', label: 'Черновик' },
}

const resolved = computed(() => {
  const key = String(props.status || '')
    .toLowerCase()
    .trim()
    .replace(/\s+/g, '_')
  const mapped = STATUS_MAP[key]
  return {
    variant: props.variant || mapped?.variant || 'neutral',
    label: props.label || mapped?.label || props.status || '—',
  }
})
</script>

<template>
  <span
    class="ds-badge"
    :class="[
      `ds-badge--${resolved.variant}`,
      { 'ds-badge--dot': dot },
    ]"
  >
    <span
      v-if="dot"
      class="ds-badge__dot"
      aria-hidden="true"
    />
    <span class="ds-badge__label">{{ resolved.label }}</span>
  </span>
</template>
