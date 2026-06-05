import { fileURLToPath, URL } from 'node:url';

import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

function canonicalDevHostPlugin() {
  return {
    name: 'canonical-dev-host',
    configureServer(server: { middlewares: { use: (handler: (request: { headers: { host?: string }; url?: string }, response: { end: () => void; setHeader: (name: string, value: string) => void; statusCode: number }, next: () => void) => void) => void } }) {
      server.middlewares.use((request, response, next) => {
        if (request.headers.host?.startsWith('127.0.0.1:')) {
          response.statusCode = 307;
          response.setHeader('Location', `http://localhost:3000${request.url ?? '/'}`);
          response.end();
          return;
        }

        next();
      });
    },
  };
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  let proxyEntries: Array<[string, string]> = [['/api/v1', 'http://localhost:8080']];
  const rawProxy = process.env.VITE_PROXY || env.VITE_PROXY;

  if (rawProxy) {
    try {
      const parsed = JSON.parse(rawProxy) as unknown;
      if (
        Array.isArray(parsed) &&
        parsed.every((entry) => Array.isArray(entry) && entry.length >= 2)
      ) {
        proxyEntries = parsed.map((entry) => [String(entry[0]), String(entry[1])]);
      }
    } catch (error) {
      console.warn('VITE_PROXY could not be parsed, falling back to localhost:8080.', error);
    }
  }

  const proxy = Object.fromEntries(
    proxyEntries.map(([prefix, target]: [string, string]) => [
      prefix,
      {
        target,
        changeOrigin: true,
        secure: false,
      },
    ]),
  );

  return {
    plugins: [canonicalDevHostPlugin(), vue()],
    resolve: {
      alias: [
        {
          find: '@vben/common-ui',
          replacement: fileURLToPath(new URL('./src/vendor/vben/common-ui.ts', import.meta.url)),
        },
        {
          find: '@vben/request',
          replacement: fileURLToPath(new URL('./src/vendor/vben/request.ts', import.meta.url)),
        },
        {
          find: '#',
          replacement: fileURLToPath(new URL('./src', import.meta.url)),
        },
        {
          find: '@',
          replacement: fileURLToPath(new URL('./src', import.meta.url)),
        },
      ],
    },
    server: {
      host: '0.0.0.0',
      port: 3000,
      strictPort: true,
      proxy,
    },
    build: {
      outDir: 'dist',
      sourcemap: true,
      target: 'es2022',
      chunkSizeWarningLimit: 1600,
    },
  };
});
