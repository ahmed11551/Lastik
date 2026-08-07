<script setup>
/**
 * AUTOMETRIA ERP — High-density data table
 * density: compact 32px | comfortable 48px
 * sticky header · zebra · selected indigo · bulk select + toolbar
 */
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  columns: {
    type: Array,
    required: true,
  },
  rows: {
    type: Array,
    required: true,
  },
  density: {
    type: String,
    default: 'compact',
    validator: (v) => ['compact', 'comfortable'].includes(v),
  },
  stickyHeader: {
    type: Boolean,
    default: true,
  },
  zebra: {
    type: Boolean,
    default: true,
  },
  emptyText: {
    type: String,
    default: 'Нет данных',
  },
  emptyHint: {
    type: String,
    default: '',
  },
  rowKey: {
    type: String,
    default: 'id',
  },
  maxHeight: {
    type: String,
    default: 'min(70vh, 720px)',
  },
  /** Enable bulk selection checkboxes */
  selectable: {
    type: Boolean,
    default: false,
  },
  /** Controlled selection (array of row keys) */
  selectedKeys: {
    type: Array,
    default: undefined,
  },
  /** Optional per-row class: (row, index) => string | string[] | Record */
  rowClass: {
    type: Function,
    default: null,
  },
})

const emit = defineEmits(['update:selectedKeys', 'selection-change', 'bulk-action'])

const internalSelected = ref([])
const headerCheckboxRef = ref(null)

const selectedSet = computed(() => {
  const keys = props.selectedKeys !== undefined ? props.selectedKeys : internalSelected.value
  return new Set(keys)
})

const selectedCount = computed(() => selectedSet.value.size)

const allRowKeys = computed(() =>
  props.rows.map((row, i) => row[props.rowKey] ?? i),
)

const allSelected = computed(
  () => props.rows.length > 0 && selectedCount.value === props.rows.length,
)

const someSelected = computed(
  () => selectedCount.value > 0 && selectedCount.value < props.rows.length,
)

watch(
  [allSelected, someSelected, () => props.selectable],
  async () => {
    if (!props.selectable) return
    await nextTick()
    if (headerCheckboxRef.value) {
      headerCheckboxRef.value.indeterminate = someSelected.value && !allSelected.value
    }
  },
  { immediate: true },
)

function setSelection(keys) {
  const next = [...keys]
  if (props.selectedKeys === undefined) {
    internalSelected.value = next
  }
  emit('update:selectedKeys', next)
  emit('selection-change', next)
}

function rowKeyOf(row, index) {
  return row[props.rowKey] ?? index
}

function isSelected(row, index) {
  return selectedSet.value.has(rowKeyOf(row, index))
}

function toggleRow(row, index) {
  const key = rowKeyOf(row, index)
  const next = new Set(selectedSet.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  setSelection([...next])
}

function toggleAll() {
  if (allSelected.value) {
    setSelection([])
  } else {
    setSelection(allRowKeys.value)
  }
}

function clearSelection() {
  setSelection([])
}

function isMono(col) {
  if (col.mono === true) return true
  if (col.mono === false) return false
  const key = String(col.key || '').toLowerCase()
  return /sku|vin|price|qty|amount|code|article|артикул|остат|цена|sum|revenue|date|email|listing/.test(key)
}

function isRightAlign(col) {
  if (col.align === 'right') return true
  if (col.align === 'left' || col.align === 'center') return false
  const key = String(col.key || '').toLowerCase()
  return /sku|vin|price|qty|amount|sum|revenue|date|listing|цена|остат|дата/.test(key)
}

function cellAlign(col) {
  if (col.align === 'center') return 'text-center'
  if (isRightAlign(col)) return 'text-right'
  return 'text-left'
}

const visibleColumns = computed(() => props.columns)
const colSpan = computed(() => visibleColumns.value.length + (props.selectable ? 1 : 0))

defineExpose({ clearSelection, selectedCount })
</script>

<template>
  <div class="relative">
    <!-- Bulk actions toolbar -->
    <Transition name="ds-bulk">
      <div
        v-if="selectable && selectedCount > 0"
        class="ds-bulk-toolbar mb-2 flex flex-wrap items-center gap-2 rounded border px-3 py-2"
        role="toolbar"
        aria-label="Bulk actions"
      >
        <span class="font-mono text-[12px]" style="color: var(--color-text-secondary)">
          {{ selectedCount }} выбрано
        </span>
        <slot
          name="bulk-actions"
          :selected-keys="[...selectedSet]"
          :count="selectedCount"
          :clear="clearSelection"
        >
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm"
            @click="emit('bulk-action', { action: 'update', keys: [...selectedSet] })"
          >
            Update
          </button>
          <button
            type="button"
            class="ds-btn ds-btn-ghost ds-btn-sm"
            @click="emit('bulk-action', { action: 'export', keys: [...selectedSet] })"
          >
            Export
          </button>
        </slot>
        <button
          type="button"
          class="ds-btn ds-btn-ghost ds-btn-sm ml-auto"
          @click="clearSelection"
        >
          Снять
        </button>
      </div>
    </Transition>

    <div
      class="ds-surface overflow-auto"
      :style="{ maxHeight }"
    >
      <table
        :class="[
          'ds-table w-full',
          density === 'compact' ? 'ds-table--compact' : 'ds-table--comfortable',
          { 'ds-table--zebra': zebra },
        ]"
      >
        <thead :class="{ 'ds-table__sticky': stickyHeader }">
          <tr>
            <th
              v-if="selectable"
              class="ds-table__th ds-table__th--select"
              style="width: 40px"
            >
              <input
                ref="headerCheckboxRef"
                type="checkbox"
                class="ds-table__checkbox"
                :checked="allSelected"
                :aria-label="allSelected ? 'Снять выделение' : 'Выбрать все'"
                @change="toggleAll"
              >
            </th>
            <th
              v-for="col in visibleColumns"
              :key="col.key"
              class="ds-table__th whitespace-nowrap"
              :class="cellAlign(col)"
              :style="col.width ? { width: col.width } : undefined"
            >
              {{ col.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!rows.length">
            <td
              :colspan="colSpan"
              class="ds-table__empty"
            >
              <div class="ds-empty">
                <div class="ds-empty__title">{{ emptyText }}</div>
                <div
                  v-if="emptyHint"
                  class="ds-empty__hint"
                >
                  {{ emptyHint }}
                </div>
              </div>
            </td>
          </tr>
          <tr
            v-for="(row, index) in rows"
            :key="rowKeyOf(row, index)"
            class="ds-table__row group"
            :class="[
              { 'ds-table__row--selected': selectable && isSelected(row, index) },
              rowClass ? rowClass(row, index) : null,
            ]"
          >
            <td
              v-if="selectable"
              class="ds-table__td ds-table__td--select"
            >
              <input
                type="checkbox"
                class="ds-table__checkbox"
                :checked="isSelected(row, index)"
                :aria-label="`Выбрать строку ${rowKeyOf(row, index)}`"
                @change="toggleRow(row, index)"
                @click.stop
              >
            </td>
            <td
              v-for="col in visibleColumns"
              :key="col.key"
              class="ds-table__td"
              :class="[
                isMono(col) ? 'ds-mono font-mono tabular-nums' : 'font-sans',
                cellAlign(col),
              ]"
            >
              <slot
                :name="col.key"
                :row="row"
                :col="col"
                :value="row[col.key]"
                :index="index"
              >
                {{ row[col.key] }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.ds-bulk-enter-active,
.ds-bulk-leave-active {
  transition: opacity 120ms ease, transform 120ms ease;
}
.ds-bulk-enter-from,
.ds-bulk-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
