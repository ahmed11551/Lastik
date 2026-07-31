<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  items?: { label: string; href?: string }[]
}>()

const defaultItems = computed(() => props.items ?? [])
</script>

<template>
  <nav v-if="defaultItems.length" class="flex items-center gap-2 text-xs">
    <template v-for="(item, index) in defaultItems" :key="index">
      <span v-if="index > 0" class="text-muted-foreground">/</span>
      <span
        :class="[
          index === defaultItems.length - 1
            ? 'font-medium text-foreground'
            : 'text-muted-foreground hover:text-foreground',
        ]"
      >
        <component
          :is="item.href ? 'a' : 'span'"
          v-bind="item.href ? { href: item.href } : {}"
          class="inline-flex items-center gap-1"
        >
          {{ item.label }}
        </component>
      </span>
    </template>
  </nav>
</template>
