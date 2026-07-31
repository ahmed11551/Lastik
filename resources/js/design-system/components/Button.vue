<template>
  <button
    :class="[
      'ds-btn',
      variant === 'primary' ? 'ds-btn-primary' : variant === 'ghost' ? 'ds-btn-ghost' : ''
    ]"
    v-bind="$attrs"
  >
    <slot />
  </button>
</template>

<script setup>
defineProps({
  variant: { type: String, default: 'primary' }
})
</script>
