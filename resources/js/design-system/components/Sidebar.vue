<script setup>
/**
 * AUTOMETRIA ERP — Industrial Amber sidebar
 * Grouped collapsible sections · amber active tint · compact density
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  brand: { type: String, default: 'AUTOMETRIA ERP' },
  items: { type: Array, required: true },
  active: { type: String, default: '' },
  footer: { type: String, default: 'v1.0.0' },
  sections: {
    type: Array,
    default: null,
    /** @type {{ id?: string, title: string, items: { id: string, label: string, href?: string, highlight?: boolean }[] }[] | null} */
  },
})

defineEmits(['select'])

const STORAGE_KEY = 'autometria_nav_collapsed'

function loadCollapsed() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

const collapsed = ref(loadCollapsed())

const resolvedSections = computed(() => {
  if (props.sections?.length) return props.sections
  return [{ id: 'all', title: 'Меню', items: props.items || [] }]
})

function sectionKey(section, index) {
  return section.id || section.title || String(index)
}

function isCollapsed(section, index) {
  const key = sectionKey(section, index)
  if (Object.prototype.hasOwnProperty.call(collapsed.value, key)) {
    return !!collapsed.value[key]
  }
  // Default: collapse system-heavy groups, keep ops open
  return ['system', 'regulatory'].includes(String(section.id || ''))
}

function sectionContainsActive(section) {
  return (section.items || []).some((item) => item.id === props.active)
}

function isOpen(section, index) {
  if (sectionContainsActive(section)) return true
  return !isCollapsed(section, index)
}

function toggleSection(section, index) {
  const key = sectionKey(section, index)
  const nextOpen = !isOpen(section, index)
  // If active lives here, keep open
  if (sectionContainsActive(section) && !nextOpen) return
  collapsed.value = { ...collapsed.value, [key]: !nextOpen }
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(collapsed.value))
  } catch {
    /* ignore quota */
  }
}

watch(
  () => props.active,
  () => {
    // Ensure active section stays expanded in storage
    resolvedSections.value.forEach((section, index) => {
      if (!sectionContainsActive(section)) return
      const key = sectionKey(section, index)
      if (collapsed.value[key]) {
        collapsed.value = { ...collapsed.value, [key]: false }
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(collapsed.value))
        } catch {
          /* ignore */
        }
      }
    })
  },
  { immediate: true },
)
</script>

<template>
  <aside
    class="ds-sidebar flex h-screen shrink-0 flex-col"
    style="width: var(--ds-sidebar-width)"
  >
    <div class="ds-sidebar__brand">
      <span class="ds-sidebar__brand-mark" aria-hidden="true" />
      <span class="truncate">{{ brand }}</span>
    </div>

    <nav class="ds-sidebar__nav flex-1 overflow-y-auto px-2 py-2">
      <div
        v-for="(section, sIdx) in resolvedSections"
        :key="sectionKey(section, sIdx)"
        class="ds-sidebar__section"
      >
        <button
          type="button"
          class="ds-sidebar__section-btn"
          :aria-expanded="isOpen(section, sIdx)"
          @click="toggleSection(section, sIdx)"
        >
          <span class="truncate">{{ section.title }}</span>
          <span
            class="ds-sidebar__chevron"
            :class="{ 'ds-sidebar__chevron--open': isOpen(section, sIdx) }"
            aria-hidden="true"
          >▾</span>
        </button>

        <div
          v-show="isOpen(section, sIdx)"
          class="ds-sidebar__items"
        >
          <button
            v-for="item in section.items"
            :key="item.id"
            type="button"
            class="ds-sidebar__item"
            :class="{
              'ds-sidebar__item--active': active === item.id,
              'ds-sidebar__item--highlight': item.highlight && active !== item.id,
            }"
            @click="$emit('select', item)"
          >
            <span class="truncate">{{ item.label }}</span>
          </button>
        </div>
      </div>
    </nav>

    <div class="ds-sidebar__footer">
      {{ footer }}
    </div>
  </aside>
</template>
