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
      rollupOptions: {
        input: path.resolve(__dirname, 'index.html'),
      },
    },
  }
})
