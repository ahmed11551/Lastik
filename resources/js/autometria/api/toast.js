/**
 * AUTOMETRIA ERP — toast bus (Industrial Precision)
 */
import { reactive } from 'vue'

const state = reactive({
  items: [],
})

let seq = 0

export function useToast() {
  function push(payload) {
    const id = ++seq
    const item = {
      id,
      tone: payload.tone || 'warning',
      title: payload.title || 'Уведомление',
      message: payload.message || '',
      ttl: payload.ttl ?? 5200,
    }
    state.items.push(item)
    if (item.ttl > 0) {
      setTimeout(() => dismiss(id), item.ttl)
    }
    return id
  }

  function dismiss(id) {
    const i = state.items.findIndex((t) => t.id === id)
    if (i >= 0) state.items.splice(i, 1)
  }

  function error(message, title = 'Ошибка') {
    return push({ tone: 'danger', title, message })
  }

  function warning(message, title = 'Внимание') {
    return push({ tone: 'warning', title, message })
  }

  function success(message, title = 'OK') {
    return push({ tone: 'success', title, message })
  }

  function info(message, title = 'Инфо') {
    return push({ tone: 'info', title, message })
  }

  return { state, push, dismiss, error, warning, success, info }
}

export const toast = useToast()
