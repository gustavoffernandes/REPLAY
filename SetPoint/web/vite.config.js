import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Em dev, /api é repassado para o PHP (php -S localhost:8000 -t api)
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: { '/api': 'http://localhost:8000' },
  },
})
