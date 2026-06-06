export interface RequestClientOptions {
  baseURL?: string;
  responseReturn?: 'data' | 'response';
}

interface RequestInterceptorConfig {
  method?: string;
  body?: BodyInit | null;
  url: string;
  headers: Record<string, string>;
}

interface RequestInterceptor {
  fulfilled?: (config: RequestInterceptorConfig) => Promise<RequestInterceptorConfig> | RequestInterceptorConfig;
}

interface RequestCallConfig {
  params?: Record<string, unknown>;
  headers?: Record<string, string>;
}

let unauthorizedHandler: (() => void) | null = null;

/** Register a global handler invoked whenever any request returns HTTP 401. */
export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler;
}

/** Manually trigger the registered 401 handler (used by non-RequestClient fetch paths). */
export function notifyUnauthorized() {
  unauthorizedHandler?.();
}

/** Reads the (non-HttpOnly) CSRF token the backend sets at login. */
export function readCsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)radio_csrf=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

function toQueryString(params?: Record<string, unknown>) {
  if (!params) {
    return '';
  }

  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue;
    }
    query.append(key, String(value));
  }

  const queryString = query.toString();
  return queryString ? `?${queryString}` : '';
}

export class RequestClient {
  private readonly baseURL: string;
  private readonly responseReturn: 'data' | 'response';
  private readonly requestInterceptors: RequestInterceptor[] = [];

  public constructor(options: RequestClientOptions = {}) {
    this.baseURL = options.baseURL ?? '';
    this.responseReturn = options.responseReturn ?? 'data';
  }

  public addRequestInterceptor(interceptor: RequestInterceptor) {
    this.requestInterceptors.push(interceptor);
  }

  public async get<T>(url: string, config: RequestCallConfig = {}) {
    return this.request<T>('GET', url, undefined, config);
  }

  public async post<T>(url: string, data?: unknown, config: RequestCallConfig = {}) {
    return this.request<T>('POST', url, data, config);
  }

  public async put<T>(url: string, data?: unknown, config: RequestCallConfig = {}) {
    return this.request<T>('PUT', url, data, config);
  }

  public async delete<T>(url: string, config: RequestCallConfig = {}) {
    return this.request<T>('DELETE', url, undefined, config);
  }

  private resolveUrl(url: string, params?: Record<string, unknown>) {
    const hasAbsoluteBase = /^https?:\/\//i.test(this.baseURL);
    const normalizedPath = url.startsWith('/') ? url : `/${url}`;
    let absoluteUrl: URL;

    if (hasAbsoluteBase) {
      const base = new URL(this.baseURL);
      const basePath = base.pathname.replace(/\/+$/, '');
      absoluteUrl = new URL(
        `${base.origin}${basePath}${normalizedPath}`,
      );
    } else {
      const basePath = this.baseURL ? this.baseURL.replace(/\/+$/, '') : '';
      absoluteUrl = new URL(
        `${window.location.origin}${basePath}${normalizedPath}`,
      );
    }

    const queryString = toQueryString(params);
    return `${absoluteUrl.toString()}${queryString}`;
  }

  private async request<T>(
    method: string,
    url: string,
    data?: unknown,
    config: RequestCallConfig = {},
  ) {
    const requestConfig: RequestInterceptorConfig = {
      method,
      headers: {
        Accept: 'application/json',
        ...(config.headers ?? {}),
      },
      url: this.resolveUrl(url, config.params),
    };

    if (data !== undefined) {
      if (data instanceof FormData || typeof data === 'string' || data instanceof Blob) {
        requestConfig.body = data;
      } else {
        requestConfig.body = JSON.stringify(data);
        requestConfig.headers['Content-Type'] = 'application/json';
      }
    }

    // CSRF: double-submit the token on state-changing requests.
    if (method !== 'GET' && method !== 'HEAD') {
      const csrf = readCsrfToken();
      if (csrf) {
        requestConfig.headers['X-CSRF-Token'] = csrf;
      }
    }

    let finalConfig = requestConfig;
    for (const interceptor of this.requestInterceptors) {
      if (typeof interceptor.fulfilled === 'function') {
        finalConfig = await interceptor.fulfilled(finalConfig);
      }
    }

    const { url: requestUrl, ...requestInit } = finalConfig;
    // Always send the HttpOnly session cookie, including for a cross-origin API base URL.
    const response = await fetch(requestUrl, { ...requestInit, credentials: 'include' });

    if (!response.ok) {
      if (response.status === 401) {
        notifyUnauthorized();
      }
      const errorText = await response.text().catch(() => '');
      throw new Error(errorText || `Request failed with status ${response.status}`);
    }

    if (this.responseReturn === 'response') {
      return response as unknown as T;
    }

    const contentType = response.headers.get('content-type') ?? '';
    if (contentType.includes('application/json')) {
      return (await response.json()) as T;
    }

    return (await response.text()) as unknown as T;
  }
}
