import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '../../css/app.css'
import App from './App.vue'
import { usePwa } from './composables/usePwa'
import { getToken } from './api/client'
import { isPushSupported, registerPushSubscription } from './pwa/pushManager'
import { bindOfflineSyncListeners } from '../composables/useOfflineSync'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.mount('#app')

// Sprint A / v1.3.0 — PWA registration + offline cart drafts (IndexedDB).
usePwa().init()

// v1.3.0 Sprint 3 — offline queue flush on reconnect (idempotent sync engine).
bindOfflineSyncListeners()

// v1.3.0 Sprint 1 — best-effort Web Push subscribe when already authenticated.
if (typeof window !== 'undefined' && isPushSupported() && getToken()) {
  window.setTimeout(() => {
    void registerPushSubscription({ silent: true }).catch(() => undefined)
  }, 2500)
}
