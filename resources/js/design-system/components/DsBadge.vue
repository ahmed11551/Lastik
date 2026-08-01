<script setup>
/**
 * AUTOMETRIA ERP — B2B status badges
 * Dot + Badge: 6px indicator + label
 * Statuses: Accepted | Negotiation | Rejected | Review + legacy variants
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
  active: { variant: 'active', label: 'Active' },
  pending: { variant: 'pending', label: 'Pending' },
  suspended: { variant: 'suspended', label: 'Suspended' },
  open: { variant: 'open', label: 'Open' },
  closed: { variant: 'closed', label: 'Closed' },
  success: { variant: 'success', label: 'OK' },
  danger: { variant: 'danger', label: 'Error' },
  warning: { variant: 'warning', label: 'Warning' },
  negotiation: { variant: 'negotiation', label: 'In Negotiation' },
  rejected: { variant: 'rejected', label: 'Rejected' },
  review: { variant: 'review', label: 'Under Review' },
  accepted: { variant: 'accepted', label: 'Accepted' },
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
