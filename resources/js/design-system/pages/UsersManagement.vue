<script setup>
/**
 * AUTOMETRIA ERP — User & Role Management (API-wired)
 */
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { DsBadge, DsTable } from '@/design-system'
import { useUsersStore } from '@/autometria/stores/usersStore'
import { toast } from '@/autometria/api/toast'
import DsLoadingBadge from '@/autometria/components/DsLoadingBadge.vue'

const store = useUsersStore()
const { rows, roles, loading, degraded } = storeToRefs(store)

const query = ref('')
const roleFilter = ref('all')
const statusFilter = ref('all')
const density = ref('compact')
const showCreate = ref(false)
const form = ref({
  name: '',
  email: '',
  password: '',
  role_id: null,
  phone: '',
})
const saving = ref(false)

const roleOptions = computed(() => {
  const names = roles.value.map((r) => r.name).filter(Boolean)
  if (names.length) return names
  return [...new Set(rows.value.map((u) => u.role).filter(Boolean))]
})

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return rows.value.filter((u) => {
    if (roleFilter.value !== 'all' && u.role !== roleFilter.value) return false
    if (statusFilter.value !== 'all' && String(u.status).toLowerCase() !== statusFilter.value) {
      return false
    }
    if (!q) return true
    return [u.name, u.email, u.role, u.location].join(' ').toLowerCase().includes(q)
  })
})

const columns = [
  { key: 'name', label: 'Пользователь', mono: false },
  { key: 'email', label: 'Email', mono: true },
  { key: 'role', label: 'Роль', mono: false },
  { key: 'location', label: 'Точка', mono: false },
  { key: 'status', label: 'Статус', mono: false },
  { key: 'last_login', label: 'Последний вход', mono: true },
  { key: 'actions', label: '', mono: false },
]

const roleStats = computed(() => {
  const map = {}
  for (const role of roleOptions.value) {
    map[role] = rows.value.filter((u) => u.role === role).length
  }
  return map
})

async function load() {
  try {
    await store.fetchUsers()
    if (!form.value.role_id && roles.value[0]) {
      form.value.role_id = roles.value[0].id
    }
  } catch {
    toast.warning('Пользователи недоступны — degraded mode', 'Users')
  }
}

async function createUser() {
  saving.value = true
  try {
    await store.createUser({ ...form.value })
    showCreate.value = false
    form.value = { name: '', email: '', password: '', role_id: roles.value[0]?.id || null, phone: '' }
  } catch {
    /* toast via interceptor for 422 */
  } finally {
    saving.value = false
  }
}

async function cycleRole(row) {
  if (!roles.value.length) return
  const idx = roles.value.findIndex((r) => r.id === row.role_id)
  const next = roles.value[(idx + 1) % roles.value.length]
  try {
    await store.updateUser(row.id, { role_id: next.id })
  } catch {
    /* toast */
  }
}

onMounted(load)
</script>

<template>
  <div
    class="-m-4 space-y-4 p-4 lg:-m-6 lg:p-6"
    style="background: var(--autometria-bg, #0b0d10); min-height: 100%"
  >
    <div
      class="flex flex-wrap items-center justify-between gap-2 border p-4"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div>
        <div class="font-mono text-[10px] uppercase tracking-[0.14em]" style="color: #f59e0b">
          Users // /api/v1/users
        </div>
        <h2 class="text-sm font-medium text-white">Пользователи & роли</h2>
      </div>
      <div class="flex items-center gap-2">
        <DsLoadingBadge v-if="loading" label="Fetching" />
        <DsBadge v-if="degraded" status="warning" label="Degraded" variant="warning" dot />
      </div>
    </div>

    <div class="mb-1 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div
        v-for="role in roleOptions"
        :key="role"
        class="border p-4"
        style="background: #11151a; border-color: #1f2937; border-radius: 4px"
      >
        <div class="font-mono text-2xl font-semibold tabular-nums text-white">
          {{ roleStats[role] ?? 0 }}
        </div>
        <div class="mt-1 text-[11px] uppercase tracking-[0.08em]" style="color: #9ca3af">
          {{ role }}
        </div>
      </div>
    </div>

    <div
      class="flex flex-wrap items-center gap-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <input
        v-model="query"
        class="ds-input"
        style="width: min(280px, 100%); border-radius: 4px; background: #161b22; border-color: #1f2937"
        type="search"
        placeholder="Поиск имени, email, роли…"
      >
      <select
        v-model="roleFilter"
        class="ds-select"
        style="border-radius: 4px; background: #161b22; border-color: #1f2937"
      >
        <option value="all">Все роли</option>
        <option v-for="role in roleOptions" :key="role" :value="role">{{ role }}</option>
      </select>
      <select
        v-model="statusFilter"
        class="ds-select"
        style="border-radius: 4px; background: #161b22; border-color: #1f2937"
      >
        <option value="all">Все статусы</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
      </select>
      <span class="font-mono text-xs" style="color: #9ca3af">{{ filtered.length }} results</span>
      <button
        type="button"
        class="ml-auto border px-3 py-2 font-mono text-[11px] font-bold uppercase"
        style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
        @click="showCreate = !showCreate"
      >
        + Добавить
      </button>
    </div>

    <form
      v-if="showCreate"
      class="grid gap-2 border p-3 sm:grid-cols-2"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
      @submit.prevent="createUser"
    >
      <input v-model="form.name" required class="ds-input text-xs" style="border-radius: 4px; background: #161b22; border-color: #1f2937" placeholder="Имя">
      <input v-model="form.email" required type="email" class="ds-input text-xs" style="border-radius: 4px; background: #161b22; border-color: #1f2937" placeholder="Email">
      <input v-model="form.password" required type="password" class="ds-input text-xs" style="border-radius: 4px; background: #161b22; border-color: #1f2937" placeholder="Пароль">
      <select v-model="form.role_id" required class="ds-select text-xs" style="border-radius: 4px; background: #161b22; border-color: #1f2937">
        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
      <button
        type="submit"
        class="border px-3 py-2 font-mono text-[11px] font-bold uppercase sm:col-span-2"
        style="background: #f59e0b; color: #0b0d10; border-color: #f59e0b; border-radius: 4px"
        :disabled="saving"
      >
        Создать пользователя
      </button>
    </form>

    <div
      v-if="loading && !rows.length"
      class="space-y-2 border p-3"
      style="background: #11151a; border-color: #1f2937; border-radius: 4px"
    >
      <div v-for="n in 6" :key="n" class="h-8 animate-pulse" style="background: #161b22; border-radius: 4px" />
    </div>

    <DsTable
      v-else
      :columns="columns"
      :rows="filtered"
      :density="density"
      sticky-header
      empty-text="Пользователи не найдены"
    >
      <template #name="{ row }">
        <div class="flex items-center gap-2 font-sans">
          <span
            class="inline-flex h-7 w-7 shrink-0 items-center justify-center text-[11px] font-semibold"
            style="border-radius: 4px; background: #161b22; color: #f59e0b"
          >
            {{ String(row.name || '?').charAt(0).toUpperCase() }}
          </span>
          <span class="font-medium text-white">{{ row.name }}</span>
        </div>
      </template>
      <template #role="{ value }">
        <span class="font-sans text-[12px]">{{ value }}</span>
      </template>
      <template #status="{ value }">
        <DsBadge :status="value" dot />
      </template>
      <template #actions="{ row }">
        <button
          type="button"
          class="font-mono text-[10px] underline-offset-2 hover:underline"
          style="color: #f59e0b"
          @click="cycleRole(row)"
        >
          Сменить роль
        </button>
      </template>
    </DsTable>
  </div>
</template>
