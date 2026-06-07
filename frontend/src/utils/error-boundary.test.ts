/**
 * Faz H4-3 — Global error boundary test.
 *
 * Vue + window + promise üç kaynak da merkezi emit() ve dedup mantığına
 * doğru ulaşıyor mu? Handler'ları doğrudan çağırırız — jsdom dispatchEvent
 * pasif listener'ı her zaman tetiklemediği için install() sonrası
 * `handlers.windowError(event)` ile manuel invoke daha güvenilir.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, defineComponent, h } from 'vue';

import {
  _resetErrorBoundaryStateForTest,
  createBoundHandlers,
  installGlobalErrorHandlers,
} from './error-boundary';

vi.mock('ant-design-vue', () => ({
  message: { error: vi.fn() },
}));

describe('error-boundary', () => {
  beforeEach(() => {
    _resetErrorBoundaryStateForTest();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('Vue render error → install ettiğimiz app.config.errorHandler sink\'i çağırır', () => {
    const sink = vi.fn();
    const BadComponent = defineComponent({
      name: 'BadComponent',
      setup() {
        return () => {
          throw new Error('render boom');
        };
      },
    });
    const app = createApp({ render: () => h(BadComponent) });
    installGlobalErrorHandlers(app, { sink, notifyUser: false });

    const container = document.createElement('div');
    app.mount(container);

    expect(sink).toHaveBeenCalledTimes(1);
    const record = sink.mock.calls[0]?.[0];
    expect(record.source).toBe('vue');
    expect(record.message).toBe('render boom');

    app.unmount();
  });

  it('window error handler sink\'e düşer', () => {
    const sink = vi.fn();
    const handlers = createBoundHandlers({ sink, notifyUser: false });

    const event = new ErrorEvent('error', {
      message: 'window boom',
      filename: 'app.js',
      lineno: 12,
      colno: 4,
      error: new Error('window boom'),
    });
    handlers.windowError(event);

    expect(sink).toHaveBeenCalledTimes(1);
    expect(sink.mock.calls[0]?.[0]).toMatchObject({
      source: 'window',
      message: 'window boom',
      line: 12,
      column: 4,
    });
  });

  it('boş ErrorEvent.message (resource load failure) yok sayılır', () => {
    const sink = vi.fn();
    const handlers = createBoundHandlers({ sink, notifyUser: false });
    handlers.windowError(new ErrorEvent('error', { message: '' }));
    expect(sink).not.toHaveBeenCalled();
  });

  it('promise rejection (Error reason) sink\'e düşer', () => {
    const sink = vi.fn();
    const handlers = createBoundHandlers({ sink, notifyUser: false });

    const event = new Event('unhandledrejection') as PromiseRejectionEvent;
    Object.defineProperty(event, 'reason', { value: new Error('promise boom') });
    handlers.promiseError(event);

    expect(sink).toHaveBeenCalledTimes(1);
    expect(sink.mock.calls[0]?.[0]).toMatchObject({
      source: 'promise',
      message: 'promise boom',
    });
  });

  it('promise rejection (string reason) sink\'e düşer', () => {
    const sink = vi.fn();
    const handlers = createBoundHandlers({ sink, notifyUser: false });
    const event = new Event('unhandledrejection') as PromiseRejectionEvent;
    Object.defineProperty(event, 'reason', { value: 'plain string reason' });
    handlers.promiseError(event);
    expect(sink.mock.calls[0]?.[0]?.message).toBe('plain string reason');
  });

  it('aynı mesaj dedupe penceresi içinde tek sefer emit edilir', () => {
    const sink = vi.fn();
    const handlers = createBoundHandlers({ sink, notifyUser: false, dedupeWindowMs: 5000 });

    handlers.windowError(new ErrorEvent('error', { message: 'spam' }));
    handlers.windowError(new ErrorEvent('error', { message: 'spam' }));
    handlers.windowError(new ErrorEvent('error', { message: 'spam' }));

    expect(sink).toHaveBeenCalledTimes(1);
  });

  it('farklı mesajlar dedupe edilmez', () => {
    const sink = vi.fn();
    const handlers = createBoundHandlers({ sink, notifyUser: false });

    handlers.windowError(new ErrorEvent('error', { message: 'first' }));
    handlers.windowError(new ErrorEvent('error', { message: 'second' }));

    expect(sink).toHaveBeenCalledTimes(2);
  });

  it('sink kendisi patlarsa recursion yok, exception sızmaz', () => {
    const sink = vi.fn().mockImplementation(() => {
      throw new Error('sink itself failed');
    });
    const handlers = createBoundHandlers({ sink, notifyUser: false });

    expect(() => {
      handlers.windowError(new ErrorEvent('error', { message: 'trigger' }));
    }).not.toThrow();
    expect(sink).toHaveBeenCalledTimes(1);
  });

  it('install Vue error handler set eder ve aynı handler objesini döner', () => {
    const sink = vi.fn();
    const app = createApp({ render: () => h('div') });
    const handlers = installGlobalErrorHandlers(app, { sink, notifyUser: false });
    expect(typeof handlers.vueError).toBe('function');
    expect(app.config.errorHandler).toBe(handlers.vueError);
  });
});
