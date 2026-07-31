import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

createInertiaApp({
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, app, props, plugin }) {
    const vueApp = createApp({ render: () => h(app, props) })
    vueApp.use(plugin)
    vueApp.mount(el)
  },
})
