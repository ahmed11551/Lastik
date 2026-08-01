<script setup>
import AutometriaLayout from '@/Layouts/AutometriaLayout.vue'
import UsersManagement from '@/design-system/pages/UsersManagement.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
  users: { type: Array, default: () => [] },
  roles: { type: Array, default: () => [] },
  currentShiftOpen: { type: Boolean, default: true },
  shiftStartedAt: {
    type: [String, Number, Date],
    default: () => new Date(Date.now() - 3_600_000 * 3).toISOString(),
  },
  shiftRevenue: { type: [Number, String], default: 184500 },
})
</script>

<template>
  <Head title="Пользователи и роли" />

  <AutometriaLayout
    title="Пользователи и роли"
    active-nav="users"
    :current-shift-open="currentShiftOpen"
    :shift-started-at="shiftStartedAt"
    :shift-revenue="shiftRevenue"
    :breadcrumbs="[
      { label: 'Система', href: '/settings' },
      { label: 'Пользователи и роли' },
    ]"
  >
    <template #header-meta>
      <span class="ds-badge ds-badge--warning">User Management</span>
    </template>

    <UsersManagement
      :users="users"
      :roles="roles"
    />
  </AutometriaLayout>
</template>
