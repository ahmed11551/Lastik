<script setup>
/**
 * AUTOMETRIA ERP — full-screen tenant / organization picker
 */
import CosmicBackground from '@/Components/UI/CosmicBackground.vue'

defineProps({
  tenants: {
    type: Array,
    default: () => [],
    /** @type {{ id: number|string, name: string, slug?: string }[]} */
  },
  currentId: { type: [Number, String], default: null },
  title: { type: String, default: 'Выберите организацию' },
  subtitle: {
    type: String,
    default: 'Рабочее пространство AUTOMETRIA привязано к tenant.',
  },
})

const emit = defineEmits(['select'])
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center p-4 text-[var(--color-text-primary,#e5e7eb)]">
    <CosmicBackground />
    <div class="relative z-10 w-full max-w-md space-y-4">
      <header class="space-y-2 text-center">
        <p
          class="font-mono text-[10px] font-medium uppercase tracking-[0.16em]"
          style="color: #93c5fd"
        >
          AUTOMETRIA · Tenant
        </p>
        <h1 class="text-xl font-semibold tracking-tight text-white">
          {{ title }}
        </h1>
        <p class="text-sm" style="color: #a8b3c7">
          {{ subtitle }}
        </p>
      </header>

      <ul
        class="divide-y overflow-hidden border"
        style="background: #0f172a; border-color: #1e293b; border-radius: 4px"
        role="listbox"
        aria-label="Организации"
      >
        <li
          v-for="tenant in tenants"
          :key="tenant.id"
        >
          <button
            type="button"
            class="tenant-option flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm transition-colors"
            :class="{ 'tenant-option--active': currentId === tenant.id }"
            role="option"
            :aria-selected="currentId === tenant.id"
            @click="emit('select', tenant)"
          >
            <span class="min-w-0">
              <span class="block truncate font-medium text-white">{{ tenant.name }}</span>
              <span
                v-if="tenant.slug"
                class="block truncate font-mono text-[11px]"
                style="color: #a8b3c7"
              >{{ tenant.slug }}</span>
            </span>
            <span
              v-if="currentId === tenant.id"
              class="shrink-0 font-mono text-[10px] uppercase tracking-wide"
              style="color: #93c5fd"
            >active</span>
          </button>
        </li>
        <li
          v-if="!tenants.length"
          class="px-4 py-6 text-center text-sm"
          style="color: #a8b3c7"
        >
          Нет доступных организаций
        </li>
      </ul>

      <slot />
    </div>
  </div>
</template>

<style scoped>
.tenant-option:hover {
  background: color-mix(in srgb, #1a3c8c 18%, transparent);
}

.tenant-option:focus-visible {
  outline: 2px solid #1a3c8c;
  outline-offset: -2px;
}

.tenant-option--active {
  background: color-mix(in srgb, #1a3c8c 22%, transparent);
}
</style>
