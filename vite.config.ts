import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

function servePublicPwaAssets() {
  const publicRoot = path.resolve(__dirname, 'public')
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
        const allow =
          url === '/sw.js' ||
          url === '/manifest.webmanifest' ||
          url.startsWith('/icons/')
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
 */
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // Host :8000 is often taken by other local APIs — default Lastik nginx to 8080.
  const laravelTarget = env.VITE_LARAVEL_PROXY || 'http://127.0.0.1:8080'

  return {
    plugins: [vue(), tailwindcss(), servePublicPwaAssets()],
    publicDir: false,
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
