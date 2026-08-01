<script setup>
/**
 * AUTOMETRIA ERP — High-density data table
 * density: compact 32px | comfortable 48px
 * sticky header + zebra + amber hover border + mono data cols
 */
defineProps({
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
  rowKey: {
    type: String,
    default: 'id',
  },
  maxHeight: {
    type: String,
    default: 'min(70vh, 720px)',
  },
})

function isMono(col) {
  if (col.mono === true) return true
  if (col.mono === false) return false
  const key = String(col.key || '').toLowerCase()
  return /sku|vin|price|qty|amount|code|article|артикул|остат|цена|sum|revenue|date|email/.test(key)
}
</script>

<template>
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
            v-for="col in columns"
            :key="col.key"
            class="whitespace-nowrap"
            :style="col.width ? { width: col.width } : undefined"
          >
            {{ col.label }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="!rows.length">
          <td
            :colspan="columns.length"
            class="text-center font-sans"
            style="color: var(--color-text-secondary); height: var(--ds-row-comfortable)"
          >
            {{ emptyText }}
          </td>
        </tr>
        <tr
          v-for="(row, index) in rows"
          :key="row[rowKey] ?? index"
          class="ds-table__row group"
        >
          <td
            v-for="col in columns"
            :key="col.key"
            :class="isMono(col) ? 'ds-mono font-mono' : 'font-sans'"
          >
            <slot
              :name="col.key"
              :row="row"
              :col="col"
              :value="row[col.key]"
            >
              {{ row[col.key] }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
