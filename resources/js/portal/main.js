import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '../../css/app.css'
import PortalApp from './PortalApp.vue'

createApp(PortalApp).use(createPinia()).mount('#app')
