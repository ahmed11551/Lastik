<!-- Legacy alias — use DsCommandPalette.vue -->
<script setup>
import DsCommandPalette from './DsCommandPalette.vue'
</script>

<template>
  <DsCommandPalette v-bind="$attrs">
    <template
      v-for="(_, name) in $slots"
      #[name]="slotData"
    >
      <slot
        :name="name"
        v-bind="slotData || {}"
      />
    </template>
  </DsCommandPalette>
</template>
