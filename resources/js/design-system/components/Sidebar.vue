<script setup>
/**
 * AUTOMETRIA ERP — Industrial Amber sidebar (260px, radius 4px)
 */
defineProps({
  brand: { type: String, default: 'AUTOMETRIA ERP' },
  items: { type: Array, required: true },
  active: { type: String, default: '' },
  footer: { type: String, default: 'v1.0.0' },
  sections: {
    type: Array,
    default: null,
    /** @type {{ title: string, items: { id: string, label: string, href?: string, icon?: string }[] }[] | null} */
  },
})

defineEmits(['select'])
</script>

<template>
  <aside
    class="ds-sidebar flex h-screen shrink-0 flex-col"
    style="width: var(--ds-sidebar-width)"
  >
    <div
      class="border-b px-5 py-4 text-[13px] font-semibold tracking-wide"
      style="border-color: var(--color-border); color: var(--color-text-primary)"
    >
      {{ brand }}
    </div>

    <nav class="flex-1 overflow-y-auto px-2 py-2">
      <template v-if="sections?.length">
        <div
          v-for="section in sections"
          :key="section.title"
          class="mb-2"
        >
          <div
            class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.08em]"
            style="color: var(--color-text-secondary)"
          >
            {{ section.title }}
          </div>
          <button
            v-for="item in section.items"
            :key="item.id"
            type="button"
            class="ds-sidebar__item mb-0.5 flex w-full items-center gap-2.5 px-3 py-2 text-left text-[13px] transition-colors"
            :style="
              active === item.id
                ? {
                    background: 'var(--color-surface-elevated)',
                    color: 'var(--color-primary)',
                    borderLeft: '2px solid var(--color-primary)',
                    paddingLeft: '10px',
                  }
                : { color: 'var(--color-text-secondary)' }
            "
            @click="$emit('select', item)"
          >
            <span class="truncate">{{ item.label }}</span>
          </button>
        </div>
      </template>

      <template v-else>
        <button
          v-for="item in items"
          :key="item.id"
          type="button"
          class="ds-sidebar__item mb-0.5 flex w-full items-center gap-2.5 px-3 py-2 text-left text-[13px] transition-colors"
          :style="
            active === item.id
              ? {
                  background: 'var(--color-surface-elevated)',
                  color: 'var(--color-primary)',
                  borderLeft: '2px solid var(--color-primary)',
                  paddingLeft: '10px',
                }
              : { color: 'var(--color-text-secondary)' }
          "
          @click="$emit('select', item)"
        >
          {{ item.label }}
        </button>
      </template>
    </nav>

    <div
      class="border-t px-4 py-3 font-mono text-[11px]"
      style="border-color: var(--color-border); color: var(--color-text-secondary)"
    >
      {{ footer }}
    </div>
  </aside>
</template>
