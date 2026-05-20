import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// SPA dev server. En prod el build queda en items-v2/dist/ servido por el panel.
// Durante desarrollo el panel corre en :8001 — proxy a esa app para
// /API/* (REST canónicos), /assets/* (imágenes) y /a_* (endpoints legacy
// que vamos llamando hasta que migremos todo a /API/v1/*).
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/API':    { target: 'http://localhost:8001', changeOrigin: true, cookieDomainRewrite: '' },
      '/assets': { target: 'http://localhost:8001', changeOrigin: true },
      '/a_':     { target: 'http://localhost:8001', changeOrigin: true, cookieDomainRewrite: '' },
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  base: '/items-v2/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
});
