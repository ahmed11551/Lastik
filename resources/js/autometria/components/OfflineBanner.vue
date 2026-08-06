<script setup lang="ts">
/**
 * AUTOMETRIA ERP — Cosmic Navy offline banner (v1.3.0 PWA)
 * Shows when navigator / useOfflineStore reports offline; queue depth from Dexie.
 */
import { computed, onMounted, onUnmounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useOfflineStore } from '@/stores/useOfflineStore'

const offline = useOfflineStore()
const { online, pendingCount, pendingRefundCount, pendingStockCount, failedCount, queueTotal } = storeToRefs(offline)

const visible = computed(() => !online.value)

const message = computed(() => {
  const n = Number(queueTotal.value || 0)
  if (n <= 0) {
    return 'Оффлайн-режим. Очередь пуста — чеки сохранятся локально.'
  }
  const receiptPart = Number(pendingCount.value || 0) + Number(failedCount.value || 0)
  if (receiptPart > 0 && n === receiptPart) {
    const word =
      receiptPart === 1 ? 'чек' : receiptPart >= 2 && receiptPart <= 4 ? 'чека' : 'чеков'
    return `Оффлайн-режим. ${receiptPart} ${word} в очереди`
  }
  return `Оффлайн-режим. ${n} операций в очереди`
})

function syncOnlineFromNavigator(): void {
  offline.setOnline(typeof navigator !== 'undefined' ? navigator.onLine : true)
  void offline.refreshCounts()
}

function onOnline(): void {
  offline.setOnline(true)
  void offline.refreshCounts()
}

function onOffline(): void {
  offline.setOnline(false)
  void offline.refreshCounts()
}

watch(visible, (v) => {
  if (typeof document === 'undefined') return
  document.documentElement.style.setProperty('--offline-banner-h', v ? '40px' : '0px')
})

onMounted(() => {
  syncOnlineFromNavigator()
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
})

onUnmounted(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
  document.documentElement.style.setProperty('--offline-banner-h', '0px')
})
</script>

<template>
  <Transition name="offline-slide">
    <div
      v-if="visible"
      class="offline-banner safe-pt"
      role="status"
      aria-live="polite"
      data-testid="offline-banner"
    >
      <div class="offline-banner__inner">
        <span class="offline-banner__dot" aria-hidden="true" />
        <span class="offline-banner__label">{{ message }}</span>
        <span v-if="queueTotal > 0" class="offline-banner__count">{{ queueTotal }}</span>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.offline-banner {
  position: sticky;
  top: 0;
  z-index: 60;
  width: 100%;
  background: linear-gradient(90deg, #050a1f 0%, #0d1b3d 55%, #1a3c8c 100%);
  border-bottom: 1px solid color-mix(in srgb, #f59e0b 55%, #1e293b);
  box-shadow: 0 4px 18px rgba(5, 10, 31, 0.55);
}

.offline-banner__inner {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  min-height: 2.25rem;
  padding: 0.4rem 0.875rem;
  padding-left: max(0.875rem, var(--safe-left));
  padding-right: max(0.875rem, var(--safe-right));
  font-family: ui-monospace, 'JetBrains Mono', monospace;
  font-size: 11px;
  letter-spacing: 0.04em;
  color: #e8edf5;
}

.offline-banner__dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: #f59e0b;
  box-shadow: 0 0 0 3px color-mix(in srgb, #f59e0b 28%, transparent);
  flex-shrink: 0;
}

.offline-banner__label {
  flex: 1;
  min-width: 0;
  line-height: 1.35;
}

.offline-banner__count {
  flex-shrink: 0;
  min-width: 1.5rem;
  padding: 0.15rem 0.45rem;
  text-align: center;
  border: 1px solid #f59e0b;
  border-radius: 4px;
  color: #f59e0b;
  background: color-mix(in srgb, #090d16 70%, transparent);
  font-weight: 700;
  tabular-nums: true;
  font-variant-numeric: tabular-nums;
}

.offline-slide-enter-active,
.offline-slide-leave-active {
  transition: transform 0.22s ease, opacity 0.22s ease;
}

.offline-slide-enter-from,
.offline-slide-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
