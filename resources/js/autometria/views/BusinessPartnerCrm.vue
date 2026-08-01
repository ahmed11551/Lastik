<script setup>
/**
 * AUTOMETRIA ERP — Business Partner CRM
 * Layout from Figma User Management reports (Desktop/Large)
 * Tokens: Industrial Amber (not BrixUI purple)
 */
import { computed, ref } from 'vue'
import { DsBadge, DsTable } from '@/design-system'

const emit = defineEmits(['open-palette'])

const query = ref('')
const selectedKeys = ref([1, 2, 4, 7])
const page = ref(1)
const perPage = ref(20)

const STATUS_LABEL = {
  negotiation: 'In Negotiation',
  rejected: 'Rejected',
  review: 'Under Review',
  accepted: 'Accepted',
  prospective: 'Prospective',
}

const rows = [
  { id: 1, name: 'Nisha Kumari', company: 'Hyperlink', price: 15900000, address: '987 Teck way, Seattle WA', status: 'negotiation', date: '12/03/2024', categories: ['B2B', 'Tech'], starred: true },
  { id: 2, name: 'Sophia', company: 'Kritrim', price: 15900000, address: '987 Teck way, Seattle WA', status: 'rejected', date: '12/03/2024', categories: ['Finance'], starred: false },
  { id: 3, name: 'Rudra Pratap', company: 'AroLink', price: 15900000, address: '987 Teck way, Seattle WA', status: 'review', date: '12/03/2024', categories: ['B2B'], starred: true },
  { id: 4, name: 'Aanya Sharma', company: 'Firelog', price: 15900000, address: '987 Teck way, Seattle WA', status: 'accepted', date: '12/03/2024', categories: ['Automation', 'SaaS'], starred: false },
  { id: 5, name: 'Kabir Mehta', company: 'Hyperlink', price: 15900000, address: '987 Teck way, Seattle WA', status: 'prospective', date: '12/03/2024', categories: ['Tech'], starred: false },
  { id: 6, name: 'Ishaan Verma', company: 'Kritrim', price: 15900000, address: '987 Teck way, Seattle WA', status: 'negotiation', date: '12/03/2024', categories: ['B2B', 'Finance'], starred: true },
  { id: 7, name: 'Ananya Rao', company: 'AroLink', price: 15900000, address: '987 Teck way, Seattle WA', status: 'rejected', date: '12/03/2024', categories: ['SaaS'], starred: false },
  { id: 8, name: 'Vihaan Kapoor', company: 'Firelog', price: 15900000, address: '987 Teck way, Seattle WA', status: 'review', date: '12/03/2024', categories: ['Tech'], starred: false },
  { id: 9, name: 'Myra Joshi', company: 'Hyperlink', price: 15900000, address: '987 Teck way, Seattle WA', status: 'accepted', date: '12/03/2024', categories: ['B2B'], starred: true },
  { id: 10, name: 'Arjun Nair', company: 'Kritrim', price: 15900000, address: '987 Teck way, Seattle WA', status: 'prospective', date: '12/03/2024', categories: ['Automation'], starred: false },
  { id: 11, name: 'Diya Patel', company: 'AroLink', price: 15900000, address: '987 Teck way, Seattle WA', status: 'negotiation', date: '12/03/2024', categories: ['Finance'], starred: false },
  { id: 12, name: 'Reyansh Shah', company: 'Firelog', price: 15900000, address: '987 Teck way, Seattle WA', status: 'accepted', date: '12/03/2024', categories: ['B2B', 'Tech'], starred: true },
]

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return rows
  return rows.filter((r) =>
    [r.name, r.company, r.address, STATUS_LABEL[r.status]].join(' ').toLowerCase().includes(q),
  )
})

const selectedCount = computed(() => selectedKeys.value.length)

const columns = [
  { key: 'star', label: '', mono: false, width: '36px', align: 'center' },
  { key: 'name', label: 'Client Name', mono: false },
  { key: 'company', label: 'Company', mono: false },
  { key: 'price', label: 'Listing Price', align: 'right' },
  { key: 'address', label: 'Address', mono: false },
  { key: 'status', label: 'Status', mono: false },
  { key: 'date', label: 'Date', align: 'right' },
  { key: 'categories', label: 'Categories', mono: false },
]

function formatPrice(n) {
  return `$${Number(n).toLocaleString('en-US')}`
}

function statusVariant(s) {
  return (
    {
      negotiation: 'negotiation',
      rejected: 'rejected',
      review: 'review',
      accepted: 'accepted',
      prospective: 'neutral',
    }[s] || 'neutral'
  )
}

function initials(name) {
  return String(name || '?')
    .split(/\s+/)
    .map((p) => p[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}
</script>

<template>
  <div class="space-y-3">
    <!-- Page title row (Figma header band) -->
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex min-w-0 flex-wrap items-center gap-2">
        <h2 class="text-lg font-semibold" style="color: var(--color-text-primary)">
          Business partner CRM
        </h2>
        <span class="ds-badge ds-badge--warning">New Data</span>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <div class="relative min-w-[200px] flex-1 lg:w-[280px] lg:flex-none">
          <input
            v-model="query"
            class="ds-input pl-9"
            type="search"
            placeholder="Search"
            @keydown.meta.k.prevent="emit('open-palette')"
            @keydown.ctrl.k.prevent="emit('open-palette')"
          >
          <span
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs"
            style="color: var(--color-text-secondary)"
          >⌕</span>
        </div>
        <div class="hidden items-center -space-x-2 sm:flex">
          <span
            v-for="(a, i) in ['NK', 'S', 'RP', 'AS']"
            :key="i"
            class="inline-flex h-7 w-7 items-center justify-center border text-[10px] font-semibold"
            style="
              border-radius: 4px;
              border-color: var(--color-border);
              background: var(--color-surface-elevated);
              color: var(--color-primary);
            "
          >{{ a }}</span>
          <span
            class="inline-flex h-7 items-center px-2 font-mono text-[10px]"
            style="color: var(--color-text-secondary)"
          >+4</span>
        </div>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">Add</button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" style="border-color: var(--color-primary); color: var(--color-primary)">
          Share
        </button>
      </div>
    </div>

    <!-- Toolbar (Figma table controls) -->
    <div class="ds-toolbar">
      <div class="ds-filter-bar">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">Update</button>
        <span
          class="rounded border px-2 py-1 font-mono text-[11px]"
          style="border-color: var(--color-border); color: var(--color-text-secondary); border-radius: 4px"
        >
          {{ selectedCount }} Selected
        </span>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm relative">
          Filter
          <span
            class="ml-1 inline-flex h-4 min-w-4 items-center justify-center rounded px-1 font-mono text-[10px]"
            style="background: var(--color-danger); color: #fff; border-radius: 4px"
          >4</span>
        </button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">Filters</button>
        <span class="font-mono text-xs" style="color: var(--color-text-secondary)">
          {{ filtered.length }} Results
        </span>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="ds-btn ds-btn-primary ds-btn-sm">+ Add New</button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">Import/Export</button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">View</button>
      </div>
    </div>

    <!-- Dense table -->
    <DsTable
      v-model:selected-keys="selectedKeys"
      :columns="columns"
      :rows="filtered"
      density="compact"
      sticky-header
      selectable
      max-height="min(62vh, 640px)"
      empty-text="No partners found"
    >
      <template #bulk-actions="{ count }">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">Update</button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm relative">
          Filter
          <span
            class="ml-1 inline-flex h-4 min-w-4 items-center justify-center rounded px-1 font-mono text-[10px]"
            style="background: var(--color-danger); color: #fff; border-radius: 4px"
          >{{ count }}</span>
        </button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm">Export</button>
      </template>
      <template #star="{ row }">
        <span
          class="text-sm"
          :style="{ color: row.starred ? 'var(--color-primary)' : 'var(--color-text-secondary)' }"
        >★</span>
      </template>
      <template #name="{ row }">
        <div class="flex items-center gap-2 font-sans">
          <span
            class="inline-flex h-7 w-7 shrink-0 items-center justify-center text-[10px] font-semibold"
            style="
              border-radius: 4px;
              background: var(--color-surface-elevated);
              color: var(--color-primary);
            "
          >{{ initials(row.name) }}</span>
          <span class="font-medium">{{ row.name }}</span>
        </div>
      </template>
      <template #company="{ value }">
        <span class="font-sans text-[12px]">{{ value }}</span>
      </template>
      <template #price="{ value }">
        <span class="font-mono tabular-nums">{{ formatPrice(value) }}</span>
      </template>
      <template #address="{ value }">
        <span class="max-w-[180px] truncate font-sans text-[12px]" style="color: var(--color-text-secondary)">
          {{ value }}
        </span>
      </template>
      <template #status="{ value }">
        <DsBadge
          :variant="statusVariant(value)"
          :label="STATUS_LABEL[value] || value"
          :status="value"
          dot
        />
      </template>
      <template #date="{ value }">
        <span class="font-mono tabular-nums">{{ value }}</span>
      </template>
      <template #categories="{ value }">
        <div class="flex flex-wrap gap-1">
          <span
            v-for="tag in value"
            :key="tag"
            class="ds-badge ds-badge--neutral"
          >{{ tag }}</span>
        </div>
      </template>
    </DsTable>

    <!-- Pagination footer -->
    <div
      class="flex flex-wrap items-center justify-between gap-3 border-t pt-3 text-xs"
      style="border-color: var(--color-border); color: var(--color-text-secondary)"
    >
      <div class="flex items-center gap-3 font-mono">
        <span>1–{{ Math.min(perPage, filtered.length) }} of 300</span>
        <label class="flex items-center gap-1">
          Row/Page
          <select v-model.number="perPage" class="ds-select py-1">
            <option :value="12">12</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
          </select>
        </label>
      </div>
      <div class="flex items-center gap-1">
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" :disabled="page <= 1" @click="page--">
          Prev
        </button>
        <button
          v-for="n in 5"
          :key="n"
          type="button"
          class="ds-btn ds-btn-sm font-mono"
          :class="page === n ? 'ds-btn-primary' : 'ds-btn-ghost'"
          @click="page = n"
        >
          {{ n }}
        </button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" @click="page++">
          Next
        </button>
      </div>
      <button type="button" class="sr-only">Select all</button>
    </div>
  </div>
</template>
