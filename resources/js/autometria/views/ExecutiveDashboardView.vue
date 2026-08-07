<script setup>
/**
 * AUTOMETRIA ERP — Executive Dashboard (v1.4.0 + Local Enterprise AI Pack)
 */
import AiExecutiveSummaryCard from '@/autometria/components/ai/AiExecutiveSummaryCard.vue'
import OperationalDashboard from '@/autometria/views/OperationalDashboard.vue'

defineProps({
  shiftOpen: { type: Boolean, default: false },
  shiftRevenue: { type: [Number, String], default: 0 },
  locationName: { type: String, default: 'Все точки' },
})

const emit = defineEmits(['navigate', 'create-order'])
</script>

<template>
  <div class="space-y-4" data-testid="executive-dashboard">
    <AiExecutiveSummaryCard />

    <OperationalDashboard
      :shift-open="shiftOpen"
      :shift-revenue="shiftRevenue"
      :location-name="locationName"
      @navigate="emit('navigate', $event)"
      @create-order="emit('create-order')"
    />
  </div>
</template>
