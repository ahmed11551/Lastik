import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '../../css/app.css'
import App from './App.vue'
import { usePwa } from './composables/usePwa'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.mount('#app')

// Sprint A — PWA registration + offline cart drafts (IndexedDB).
usePwa().init()
