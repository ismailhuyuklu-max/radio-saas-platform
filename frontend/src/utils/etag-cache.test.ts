/**
 * Faz CTO-21 — etag-cache unit tests.
 */
import { beforeEach, describe, expect, it } from 'vitest';

import { etagCache } from './etag-cache';

describe('etagCache', () => {
  beforeEach(() => etagCache._clear());

  it('GET için ETag kaydeder ve döner', () => {
    etagCache.store('GET', '/api/v1/stations', undefined, '"abc123"', { hello: 'world' });
    expect(etagCache.getEtag('GET', '/api/v1/stations')).toBe('"abc123"');
    expect(etagCache.getBody('GET', '/api/v1/stations')).toEqual({ hello: 'world' });
  });

  it('non-GET istek ETag store etmez', () => {
    etagCache.store('POST', '/api/v1/plans', undefined, '"xx"', { id: 1 });
    expect(etagCache.getEtag('POST', '/api/v1/plans')).toBeNull();
  });

  it('params değişirse cache miss', () => {
    etagCache.store('GET', '/api/v1/audit/logs', { limit: 10 }, '"e1"', ['log1']);
    expect(etagCache.getEtag('GET', '/api/v1/audit/logs', { limit: 10 })).toBe('"e1"');
    expect(etagCache.getEtag('GET', '/api/v1/audit/logs', { limit: 20 })).toBeNull();
  });

  it('LRU 200 cap — fazla entry oldest eviction', () => {
    for (let i = 0; i < 210; i++) {
      etagCache.store('GET', `/url-${i}`, undefined, `"${i}"`, { i });
    }
    expect(etagCache._size()).toBeLessThanOrEqual(200);
    // İlk eklenen artık yok
    expect(etagCache.getEtag('GET', '/url-0')).toBeNull();
    // Son eklenen var
    expect(etagCache.getEtag('GET', '/url-209')).toBe('"209"');
  });

  it('method case-insensitive', () => {
    etagCache.store('get', '/x', undefined, '"y"', null);
    expect(etagCache.getEtag('GET', '/x')).toBe('"y"');
  });
});
