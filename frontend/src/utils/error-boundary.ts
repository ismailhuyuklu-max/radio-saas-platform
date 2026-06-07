/**
 * Faz H4-3 — Global frontend hata sınırı.
 *
 * Üç kaynaktan gelen tüm runtime hatalarını merkezi bir log + UX'e yönlendirir:
 *   1. Vue render/lifecycle errors  → app.config.errorHandler
 *   2. JavaScript uncaught errors   → window.onerror
 *   3. Promise unhandled rejections → window.onunhandledrejection
 *
 * Eski davranış: bir Vue render error'ı console'a düşer ama UI sessizce
 * boş kalırdı. NOC tarzı bir regresyonu önlemek için artık her capture
 * - yapısal log'a düşer (frontend Logger)
 * - kullanıcıya tek satır toast atar (ant-design-vue message)
 * - production'da opsiyonel backend ingest'e POST eder (clientErrorSink)
 *
 * Test edilebilirlik: installGlobalErrorHandlers(app, {sink}) sink override
 * eder — vitest'te side-effect izole tutulur.
 */
import type { App } from 'vue';

import { message } from 'ant-design-vue';

export interface ClientErrorRecord {
  level: 'error' | 'warning';
  source: 'vue' | 'window' | 'promise';
  message: string;
  stack?: string;
  url?: string;
  line?: number;
  column?: number;
  timestamp: string;
  // Vue source'lar için: bileşen adı / hook
  component?: string;
  info?: string;
}

export type ClientErrorSink = (record: ClientErrorRecord) => void;

interface InstallOptions {
  sink?: ClientErrorSink;
  /** Toast göster (default true). Test'te false. */
  notifyUser?: boolean;
  /** Aynı mesaj kısa süre içinde tekrar etmesin (default 5000 ms). */
  dedupeWindowMs?: number;
}

const DEFAULT_DEDUPE_MS = 5000;
const recentSeen = new Map<string, number>();

function shouldEmit(record: ClientErrorRecord, windowMs: number): boolean {
  const key = `${record.source}::${record.message}`;
  const now = Date.now();
  const last = recentSeen.get(key);
  if (last !== undefined && now - last < windowMs) {
    return false;
  }
  recentSeen.set(key, now);
  // Hafıza basıncını engelle — uzun cache.
  if (recentSeen.size > 200) {
    const cutoff = now - windowMs;
    for (const [k, t] of recentSeen) {
      if (t < cutoff) recentSeen.delete(k);
    }
  }
  return true;
}

function defaultSink(record: ClientErrorRecord): void {
  // Yapısal log — DevTools'ta JSON gözüyle okunur.
  // eslint-disable-next-line no-console
  console.error('[client-error]', record);
}

export interface BoundHandlers {
  vueError: (err: unknown, instance: unknown, info: string) => void;
  windowError: (event: ErrorEvent) => void;
  promiseError: (event: PromiseRejectionEvent) => void;
}

/**
 * Test edilebilirlik için handler factory: install içinde de bunu kullanırız,
 * test'lerden de doğrudan çağırabiliriz (jsdom dispatchEvent listener
 * tetiklemesi güvensiz olduğu için).
 */
export function createBoundHandlers(options: InstallOptions = {}): BoundHandlers {
  const sink = options.sink ?? defaultSink;
  const notify = options.notifyUser !== false;
  const dedupeMs = options.dedupeWindowMs ?? DEFAULT_DEDUPE_MS;

  const emit = (record: ClientErrorRecord) => {
    if (!shouldEmit(record, dedupeMs)) return;
    try {
      sink(record);
    } catch {
      // sink kendisi patladıysa sessizce yut — recursion'ı önle.
    }
    if (notify) {
      try {
        message.error('Beklenmeyen bir hata oluştu. Geliştiriciye iletildi.');
      } catch {
        // ant-design-vue henüz mount edilmemişse atla.
      }
    }
  };

  return {
    vueError(err, instance, info) {
      const e = err as Error;
      emit({
        level: 'error',
        source: 'vue',
        message: e?.message ?? String(err),
        stack: e?.stack,
        component:
          (instance as { $options?: { name?: string }; type?: { name?: string } } | null)?.$options
            ?.name ?? (instance as { type?: { name?: string } } | null)?.type?.name,
        info,
        timestamp: new Date().toISOString(),
      });
    },
    windowError(event) {
      if (!event.message) return; // resource load errors atla
      emit({
        level: 'error',
        source: 'window',
        message: event.message,
        stack: event.error?.stack,
        url: event.filename,
        line: event.lineno,
        column: event.colno,
        timestamp: new Date().toISOString(),
      });
    },
    promiseError(event) {
      const reason = event.reason;
      const msg =
        reason instanceof Error
          ? reason.message
          : typeof reason === 'string'
            ? reason
            : (() => {
                try {
                  return JSON.stringify(reason);
                } catch {
                  return String(reason);
                }
              })();
      emit({
        level: 'error',
        source: 'promise',
        message: msg,
        stack: reason instanceof Error ? reason.stack : undefined,
        timestamp: new Date().toISOString(),
      });
    },
  };
}

export function installGlobalErrorHandlers(app: App, options: InstallOptions = {}): BoundHandlers {
  const handlers = createBoundHandlers(options);

  // 1) Vue lifecycle / render errors.
  app.config.errorHandler = handlers.vueError;

  // 2 & 3) Window / promise uncaught.
  if (typeof window !== 'undefined') {
    window.addEventListener('error', handlers.windowError);
    window.addEventListener('unhandledrejection', handlers.promiseError);
  }

  return handlers;
}

/** Test izolasyonu için dedup cache'ini sıfırla. */
export function _resetErrorBoundaryStateForTest(): void {
  recentSeen.clear();
}
