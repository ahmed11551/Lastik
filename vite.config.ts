import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

/** Dev-only: serve icons from public-pwa/ (Vite publicDir). */
function servePublicPwaAssets() {
  const publicRoot = path.resolve(__dirname, 'public-pwa')
  const mime: Record<string, string> = {
    '.js': 'application/javascript; charset=utf-8',
    '.webmanifest': 'application/manifest+json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
  }

  return {
    name: 'serve-public-pwa-assets',
    configureServer(server: { middlewares: { use: Function } }) {
      server.middlewares.use((req: { url?: string }, res: any, next: () => void) => {
        const url = (req.url || '').split('?')[0]
        const allow = url.startsWith('/icons/') || url === '/favicon.ico' || url === '/robots.txt'
        if (!allow) return next()

        const file = path.join(publicRoot, url)
        if (!file.startsWith(publicRoot) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
          return next()
        }

        const ext = path.extname(file)
        res.setHeader('Content-Type', mime[ext] || 'application/octet-stream')
        res.setHeader('Cache-Control', 'no-cache')
        fs.createReadStream(file).pipe(res)
      })
    },
  }
}

/**
 * AUTOMETRIA ERP — Vue 3 + Vite (Inertia Laravel via vite.laravel.config.js)
 * Sprint 1 v1.3.0: vite-plugin-pwa (Workbox) + Cosmic Navy webmanifest
 */
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // Host :8000 is often taken by other local APIs — default Lastik nginx to 8080.
  const laravelTarget = env.VITE_LARAVEL_PROXY || 'http://127.0.0.1:8080'

  return {
    plugins: [
      vue(),
      tailwindcss(),
      servePublicPwaAssets(),
      VitePWA({
        registerType: 'autoUpdate',
        injectRegister: false,
        strategies: 'generateSW',
        filename: 'sw.js',
        manifestFilename: 'manifest.webmanifest',
        includeAssets: ['icons/icon-192.svg', 'icons/icon-512.svg', 'favicon.ico'],
        manifest: {
          name: 'AUTOMETRIA ERP',
          short_name: 'AUTOMETRIA',
          description: 'AUTOMETRIA ERP — Cosmic Navy · склад, касса, KPI, умные закупки',
          start_url: '/',
          scope: '/',
          display: 'standalone',
          orientation: 'any',
          lang: 'ru',
          background_color: '#090d16',
          theme_color: '#0f172a',
          categories: ['business', 'productivity'],
          icons: [
            {
              src: '/icons/icon-192.svg',
              sizes: '192x192',
              type: 'image/svg+xml',
              purpose: 'any',
            },
            {
              src: '/icons/icon-512.svg',
              sizes: '512x512',
              type: 'image/svg+xml',
              purpose: 'any',
            },
            {
              src: '/icons/icon-512.svg',
              sizes: '512x512',
              type: 'image/svg+xml',
              purpose: 'maskable',
            },
          ],
        },
        workbox: {
          // Precache built static shell for offline access.
          globPatterns: ['**/*.{js,css,html,ico,svg,woff2,webmanifest}'],
          navigateFallback: '/index.html',
          navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/horizon/],
          cleanupOutdatedCaches: true,
          clientsClaim: true,
          skipWaiting: true,
          // Cosmic Navy push / notificationclick handlers.
          importScripts: ['sw-push.js'],
          runtimeCaching: [
            {
              // API / Sanctum — never cache (network only).
              urlPattern: ({ url }: { url: URL }) =>
                url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/'),
              handler: 'NetworkOnly',
            },
            {
              urlPattern: ({ request }: { request: Request }) =>
                request.destination === 'image' || request.destination === 'font',
              handler: 'CacheFirst',
              options: {
                cacheName: 'autometria-static-assets',
                expiration: {
                  maxEntries: 120,
                  maxAgeSeconds: 60 * 60 * 24 * 30,
                },
              },
            },
            {
              urlPattern: ({ request }: { request: Request }) =>
                request.destination === 'style' || request.destination === 'script',
              handler: 'StaleWhileRevalidate',
              options: {
                cacheName: 'autometria-static-code',
              },
            },
          ],
        },
        devOptions: {
          enabled: true,
          type: 'module',
          navigateFallback: 'index.html',
        },
      }),
    ],
    // PWA icons / robots only — Laravel public/ stays for PHP & builds.
    publicDir: 'public-pwa',
    resolve: {
      alias: {
        '@': path.resolve(__dirname, 'resources/js'),
      },
    },
    server: {
      host: '127.0.0.1',
      port: 5178,
      strictPort: true,
      open: '/',
      proxy: {
        '/api': {
          target: laravelTarget,
          changeOrigin: true,
        },
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      chunkSizeWarningLimit: 900,
      rollupOptions: {
        input: {
          app: path.resolve(__dirname, 'index.html'),
          portal: path.resolve(__dirname, 'portal.html'),
        },
        output: {
          manualChunks(id) {
            if (id.includes('node_modules')) {
              if (id.includes('chart.js') || id.includes('vue-chartjs')) return 'vendor-charts'
              if (id.includes('dexie')) return 'vendor-dexie'
              if (id.includes('lucide')) return 'vendor-icons'
              if (id.includes('@inertiajs')) return 'vendor-inertia'
              if (id.includes('vue') || id.includes('pinia')) return 'vendor-vue'
              return 'vendor'
            }
          },
        },
      },
    },
  }
})
