<!-- Legacy alias — use DsTable.vue -->
<script setup>
import DsTable from './DsTable.vue'
</script>

<template>
  <DsTable v-bind="$attrs">
    <template
      v-for="(_, name) in $slots"
      #[name]="slotData"
    >
      <slot
        :name="name"
        v-bind="slotData || {}"
      />
    </template>
  </DsTable>
</template>
