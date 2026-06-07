import type { RequestClientOptions } from '@vben/request';

import { RequestClient } from '@vben/request';

function createRequestClient(baseURL: string, options?: RequestClientOptions) {
  const client = new RequestClient({
    ...options,
    baseURL,
  });

  client.addRequestInterceptor({
    fulfilled: async (config) => {
      config.headers = {
        ...(config.headers ?? {}),
      };
      const token = localStorage.getItem('accessToken') || localStorage.getItem('token');
      if (token) {
        config.headers.Authorization = `Bearer ${token}`;
      }
      config.headers['Accept-Language'] = navigator.language || 'tr-TR';
      return config;
    },
  });

  // Faz CTO-21: ETag cache mantığı vendor/vben/request.ts içinde
  // entegre edildi (RequestClient.request kendisi If-None-Match yönetir).

  return client;
}

const apiURL = import.meta.env.VITE_GLOB_API_URL || import.meta.env.VITE_API_URL || '/api/v1';

export const requestClient = createRequestClient(apiURL, {
  responseReturn: 'data',
});

export const baseRequestClient = new RequestClient({ baseURL: apiURL });
