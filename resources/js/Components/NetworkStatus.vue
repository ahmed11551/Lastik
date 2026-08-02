<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { toast } from '@/autometria/api/toast'

const online = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)

function goOffline() {
  online.value = false
  toast.warning('Соединение потеряно — переход в офлайн-режим', 'Сеть')
}

function goOnline() {
  online.value = true
  toast.success('Соединение восстановлено — онлайн', 'Сеть')
}

onMounted(() => {
  window.addEventListener('offline', goOffline)
  window.addEventListener('online', goOnline)
})

onUnmounted(() => {
  window.removeEventListener('offline', goOffline)
  window.removeEventListener('online', goOnline)
})
</script>

<template>
  <div
    class="network-status"
    :class="online ? 'network-status--online' : 'network-status--offline'"
    :title="online ? 'Онлайн' : 'Офлайн'"
    data-testid="network-status"
  >
    <span class="network-status__dot" />
    <span class="network-status__label">{{ online ? 'Онлайн' : 'Офлайн' }}</span>
  </div>
</template>

<style scoped>
.network-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  border: 1px solid var(--color-border, #1f2937);
}
.network-status__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
}
.network-status--online {
  color: #22c55e;
}
.network-status--offline {
  color: #ef4444;
}
</style>
