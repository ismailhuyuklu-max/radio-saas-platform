import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

// Standalone Vitest config. Mirrors the path aliases declared in vite.config.ts
// so test files resolve the same `#`, `@`, and `@vben/*` imports as the app.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: [
      {
        find: '@vben/common-ui',
        replacement: fileURLToPath(
          new URL('./src/vendor/vben/common-ui.ts', import.meta.url),
        ),
      },
      {
        find: '@vben/request',
        replacement: fileURLToPath(
          new URL('./src/vendor/vben/request.ts', import.meta.url),
        ),
      },
      { find: '#', replacement: fileURLToPath(new URL('./src', import.meta.url)) },
      { find: '@', replacement: fileURLToPath(new URL('./src', import.meta.url)) },
    ],
  },
  test: {
    environment: 'happy-dom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    css: false,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['src/**/*.{ts,vue}'],
      exclude: ['src/**/*.{test,spec}.ts', 'src/test/**', 'src/vendor/**'],
    },
  },
});
