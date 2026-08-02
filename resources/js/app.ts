import '../css/app.css'
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import ErrorBoundary from './Components/ErrorBoundary.vue'
import NetworkStatus from './Components/NetworkStatus.vue'
import { toast } from './autometria/api/toast'
import { usePwa } from './autometria/composables/usePwa'

createInertiaApp({
  title: (title) => (title ? `${title} · AUTOMETRIA ERP` : 'AUTOMETRIA ERP'),
  resolve: (name) =>
    resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    const vueApp = createApp({
      render: () =>
        h(ErrorBoundary, null, {
          default: () => h(App, props),
        }),
    })
    vueApp.use(plugin)

    // Global Vue error handler — prevents White Screen of Death on the cash desk.
    vueApp.config.errorHandler = (err: unknown) => {
      console.error('[AUTOMETRIA] Vue error:', err)
      toast.warning('Ошибка интерфейса — приложение продолжает работу', 'UI')
    }

    // Global JS error / unhandled rejection guards.
    if (typeof window !== 'undefined') {
      window.addEventListener('error', (e: ErrorEvent) => {
        console.error('[AUTOMETRIA] window error:', e.message)
      })
      window.addEventListener('unhandledrejection', (e: PromiseRejectionEvent) => {
        console.error('[AUTOMETRIA] unhandled rejection:', e.reason)
      })
    }

    vueApp.mount(el)

    // PWA: Service Worker + offline cart draft persistence.
    usePwa().init()

    // Mount a global network-status indicator as a sibling of the app root.
    const nsHost = document.createElement('div')
    nsHost.setAttribute('data-autometria-network-status', 'true')
    nsHost.style.position = 'fixed'
    nsHost.style.bottom = '12px'
    nsHost.style.right = '12px'
    nsHost.style.zIndex = '9998'
    document.body.appendChild(nsHost)
    createApp(NetworkStatus).mount(nsHost)
  },
})
