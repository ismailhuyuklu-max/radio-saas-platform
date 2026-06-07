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

// =============================================================================
// Faz CTO-21 — Browser ETag / If-None-Match cache (in-memory, GET-only).
// Backend EtagCache 304 + 0 byte body döner; bu cache, body'i client tarafında
// tutar ve sonraki istekte gerçek 200 gibi presente eder.
// =============================================================================
interface EtagEntry {
  etag: string;
  body: unknown;
}
const ETAG_CACHE_MAX = 200;
const etagStore = new Map<string, EtagEntry>();
function etagKey(method: string, url: string): string {
  return method.toUpperCase() + ' ' + url;
}
function etagPut(method: string, url: string, etag: string, body: unknown) {
  if (method.toUpperCase() !== 'GET') return;
  if (etagStore.size >= ETAG_CACHE_MAX) {
    const first = etagStore.keys().next().value;
    if (first !== undefined) etagStore.delete(first);
  }
  etagStore.set(etagKey(method, url), { etag, body });
}
function etagGet(method: string, url: string): EtagEntry | null {
  return etagStore.get(etagKey(method, url)) ?? null;
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

    // Faz CTO-21: GET için If-None-Match auto-send (ETag cache hit)
    if (method.toUpperCase() === 'GET') {
      const cached = etagGet(method, finalConfig.url);
      if (cached) {
        finalConfig.headers['If-None-Match'] = cached.etag;
      }
    }

    const { url: requestUrl, ...requestInit } = finalConfig;
    // Always send the HttpOnly session cookie, including for a cross-origin API base URL.
    const response = await fetch(requestUrl, { ...requestInit, credentials: 'include' });

    // Faz CTO-21: 304 Not Modified → bellek'teki body'i geri ver (sentetik 200)
    if (response.status === 304 && method.toUpperCase() === 'GET') {
      const cached = etagGet(method, finalConfig.url);
      if (cached) {
        return cached.body as T;
      }
      // Cache hit denmiş ama bellek yok (LRU eviction olmuş olabilir) — yeniden iste
      // (If-None-Match'i kaldırarak sonsuz döngüyü engelle)
      delete finalConfig.headers['If-None-Match'];
      const retry = await fetch(requestUrl, { ...requestInit, credentials: 'include' });
      if (!retry.ok) {
        throw new Error(`Cache miss retry failed (${retry.status})`);
      }
      const retryBody = await retry.json().catch(() => null);
      return retryBody as T;
    }

    if (!response.ok) {
      if (response.status === 401) {
        notifyUnauthorized();
      }
      const errorText = await response.text().catch(() => '');
      throw new Error(errorText || `Request failed with status ${response.status}`);
    }

    // Faz CTO-21: ETag header'ını yakala, body'yi belleğe yaz
    const responseEtag = response.headers.get('etag') || response.headers.get('ETag');

    if (this.responseReturn === 'response') {
      return response as unknown as T;
    }

    const contentType = response.headers.get('content-type') ?? '';
    if (contentType.includes('application/json')) {
      // JSON parse fail durumunda (örn. backend yarısında patladı,
      // truncated body) caller'a açık hata vermek istiyoruz — sessizce
      // `T` yerine `undefined` döndürmek (NOC zaafiyetinin kök sebebi).
      try {
        const body = (await response.json()) as T;
        // Faz CTO-21: ETag varsa belleğe yaz (sadece GET 200)
        if (responseEtag && method.toUpperCase() === 'GET') {
          etagPut(method, finalConfig.url, responseEtag, body);
        }
        return body;
      } catch (e) {
        throw new Error(
          `Sunucudan geçersiz JSON yanıtı (status ${response.status}, content-type ${contentType}): ${(e as Error).message}`,
        );
      }
    }

    // Faz H1-2: content-type JSON DEĞİL ama HTTP 200.
    // Bu, PHP fatal HTML body veya nginx/caddy hata sayfası anlamına
    // gelir; consumer'a güvenli bir T tipi döndürmek için "fulfilled" sayıp
    // sonradan ekranda boşalmasına izin vermek (NOC tipi sessiz çökme)
    // yerine açık hata fırlat. Caller catch eder + UI uygun mesajı gösterir.
    const responseExpectsJson = !!config.headers?.['Accept']?.includes?.('json')
      || true; // varsayılan: tüm API çağrıları JSON bekler
    if (responseExpectsJson) {
      const bodyPreview = (await response.text().catch(() => '')).slice(0, 200);
      throw new Error(
        `Sunucudan beklenmeyen yanıt türü (${contentType || 'boş content-type'}, status ${response.status}). ` +
          `İçerik önizleme: ${bodyPreview.replace(/\s+/g, ' ').slice(0, 120)}`,
      );
    }

    return (await response.text()) as unknown as T;
  }
}
