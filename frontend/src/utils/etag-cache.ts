/**
 * Faz CTO-21 — Browser-side ETag cache + If-None-Match auto-send.
 *
 * Vben request layer'ı 304'ü hata olarak yorumlayabilir (status >= 400 değil
 * ama Axios validateStatus default 2xx-only). Bizim koşumcular zarflı body
 * bekliyor; 304 alındığında bellek'teki son body'i geri vermek için bu
 * yardımcı modülü interceptor'a bağlıyoruz.
 *
 * Davranış:
 *   - GET istek: URL (cache key) için bellek'te ETag varsa → If-None-Match header
 *   - 304 yanıt: bellek'teki body'i sentetik 200 olarak dön (transparent cache)
 *   - 200 yanıt: response.headers.etag varsa → body + ETag belleğe yaz
 *
 * Bellek: in-memory Map, sayfa refresh'inde sıfırlar (browser HTTP cache zaten
 * disk'te tutar; bu sadece SPA içi cache layer). LRU 200 entry cap.
 */

interface CacheEntry {
  etag: string;
  body: unknown;
  cachedAt: number;
}

const MAX_ENTRIES = 200;
const cache = new Map<string, CacheEntry>();

function makeKey(method: string | undefined, url: string | undefined, params?: unknown): string {
  const m = (method || 'GET').toUpperCase();
  const p = params ? `?${JSON.stringify(params)}` : '';
  return `${m} ${url || ''}${p}`;
}

function evictOldest(): void {
  if (cache.size <= MAX_ENTRIES) return;
  // Map iteration insertion order; ilk eklenen en eski
  const firstKey = cache.keys().next().value;
  if (firstKey !== undefined) cache.delete(firstKey);
}

export const etagCache = {
  /** Request interceptor — If-None-Match varsa ekle */
  getEtag(method: string | undefined, url: string | undefined, params?: unknown): string | null {
    if ((method || 'GET').toUpperCase() !== 'GET') return null;
    const entry = cache.get(makeKey(method, url, params));
    return entry?.etag ?? null;
  },

  /** Response interceptor — 200 + ETag varsa belleğe yaz */
  store(method: string | undefined, url: string | undefined, params: unknown, etag: string, body: unknown): void {
    if ((method || 'GET').toUpperCase() !== 'GET') return;
    cache.set(makeKey(method, url, params), {
      etag,
      body,
      cachedAt: Date.now(),
    });
    evictOldest();
  },

  /** 304 alındığında — bellek'teki body */
  getBody(method: string | undefined, url: string | undefined, params?: unknown): unknown | null {
    const entry = cache.get(makeKey(method, url, params));
    return entry?.body ?? null;
  },

  /** Test izolasyonu için temizle */
  _clear(): void {
    cache.clear();
  },

  /** Debug — cache durumu */
  _size(): number {
    return cache.size;
  },
};
