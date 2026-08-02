<script setup lang="ts">
import { ref, onErrorCaptured } from 'vue'

const hasError = ref(false)
const message = ref('')
const stack = ref('')

onErrorCaptured((err: unknown, _instance, info) => {
  hasError.value = true
  message.value = err instanceof Error ? err.message : String(err)
  stack.value = err instanceof Error && err.stack ? err.stack : info || ''
  // Prevent the error from propagating and crashing the whole Inertia app (WSoD).
  return false
})

function reload() {
  window.location.reload()
}
</script>

<template>
  <div v-if="hasError" class="error-boundary" role="alert" data-testid="error-boundary">
    <div class="error-boundary__card">
      <h2 class="error-boundary__title">Произошла ошибка интерфейса</h2>
      <p class="error-boundary__hint">
        Приложение продолжает работу. Данные не потеряны. Попробуйте перезагрузить страницу.
      </p>
      <pre v-if="message" class="error-boundary__msg">{{ message }}</pre>
      <button type="button" class="ds-btn ds-btn-primary ds-btn-sm" data-testid="error-reload" @click="reload">
        Перезагрузить
      </button>
    </div>
    <slot v-if="false" />
  </div>
  <slot v-else />
</template>

<style scoped>
.error-boundary {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface, #0b0d10);
  z-index: 9999;
  padding: 1rem;
}
.error-boundary__card {
  max-width: 520px;
  background: var(--color-bg, #11151a);
  border: 1px solid var(--color-border, #1f2937);
  border-radius: 12px;
  padding: 1.5rem;
}
.error-boundary__title {
  color: var(--color-danger, #ef4444);
  font-size: 1.1rem;
  margin: 0 0 0.5rem;
}
.error-boundary__hint {
  color: var(--color-text-secondary, #9ca3af);
  font-size: 0.85rem;
  margin: 0 0 1rem;
}
.error-boundary__msg {
  background: #0b0d10;
  border: 1px solid var(--color-border, #1f2937);
  border-radius: 6px;
  padding: 0.5rem;
  font-size: 0.72rem;
  color: var(--color-danger, #fca5a5);
  max-height: 160px;
  overflow: auto;
  white-space: pre-wrap;
  margin: 0 0 1rem;
}
</style>
