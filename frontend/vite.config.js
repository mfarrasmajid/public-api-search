import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Required for hot reload to work through the nginx proxy in Docker.
    watch: { usePolling: true },
    hmr: { clientPort: Number(process.env.VITE_HMR_CLIENT_PORT || 8080) },
    proxy: {
      // Only used when hitting Vite directly (http://localhost:5173).
      // Through nginx (http://localhost:8080) the /api prefix is routed
      // to the Laravel container instead.
      '/api': {
        target: process.env.VITE_PROXY_TARGET || 'http://nginx',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
})
