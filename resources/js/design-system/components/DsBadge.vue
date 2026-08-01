<script setup>
/**
 * AUTOMETRIA ERP — B2B status badges
 * Active | Pending | Suspended | Open | Closed + semantic variants
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
      ['success', 'danger', 'warning', 'pending', 'neutral', 'suspended', 'open', 'closed', 'active'].includes(v),
  },
  dot: {
    type: Boolean,
    default: false,
  },
})

const STATUS_MAP = {
  active: { variant: 'active', label: 'Active' },
  pending: { variant: 'pending', label: 'Pending' },
  suspended: { variant: 'suspended', label: 'Suspended' },
  open: { variant: 'open', label: 'Open' },
  closed: { variant: 'closed', label: 'Closed' },
  success: { variant: 'success', label: 'OK' },
  danger: { variant: 'danger', label: 'Error' },
  warning: { variant: 'warning', label: 'Warning' },
  negotiation: { variant: 'warning', label: 'In Negotiation' },
  rejected: { variant: 'danger', label: 'Rejected' },
  review: { variant: 'pending', label: 'Under Review' },
  accepted: { variant: 'success', label: 'Accepted' },
  prospective: { variant: 'neutral', label: 'Prospective' },
  // RU aliases
  активен: { variant: 'active', label: 'Active' },
  ожидание: { variant: 'pending', label: 'Pending' },
  заблокирован: { variant: 'suspended', label: 'Suspended' },
  открыта: { variant: 'open', label: 'Open' },
  закрыта: { variant: 'closed', label: 'Closed' },
}

const resolved = computed(() => {
  const key = String(props.status || '').toLowerCase().trim()
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
    :class="`ds-badge--${resolved.variant}`"
  >
    <span
      v-if="dot"
      class="inline-block h-1.5 w-1.5 rounded-full"
      :style="{ background: 'currentColor' }"
      aria-hidden="true"
    />
    {{ resolved.label }}
  </span>
</template>
