import { config } from '@vue/test-utils';
import { vi } from 'vitest';

// happy-dom lacks matchMedia, which ant-design-vue + responsive composables touch.
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }));
}

// ResizeObserver is used by ant-design-vue overlays; stub it for jsdom-less env.
if (!window.ResizeObserver) {
  window.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
}

// Quieter, deterministic global mounting options can be added here later.
config.global.stubs = {};
