import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

/**
 * AUTOMETRIA ERP — Vue 3 primary frontend (React Orbital disabled)
 */
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const laravelTarget = env.VITE_LARAVEL_PROXY || 'http://127.0.0.1:8010'

  return {
    plugins: [vue(), tailwindcss()],
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
        '/api/v1': {
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
