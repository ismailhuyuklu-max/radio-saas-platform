import { describe, expect, it } from 'vitest';

import { normalizeList, unwrap } from './api-envelope';

describe('api-envelope', () => {
  describe('unwrap', () => {
    it('returns the inner result when wrapped', () => {
      expect(unwrap({ code: 0, result: { foo: 'bar' }, message: 'OK' })).toEqual({ foo: 'bar' });
    });

    it('returns the raw value when unwrapped', () => {
      expect(unwrap({ foo: 'bar' })).toEqual({ foo: 'bar' });
      expect(unwrap([1, 2, 3])).toEqual([1, 2, 3]);
    });

    it('preserves primitives', () => {
      expect(unwrap('plain string')).toBe('plain string');
      expect(unwrap(null)).toBeNull();
      expect(unwrap(undefined)).toBeUndefined();
    });
  });

  describe('normalizeList', () => {
    it('returns the array as-is for plain array responses', () => {
      expect(normalizeList<number>([1, 2, 3])).toEqual([1, 2, 3]);
    });

    it('extracts result when wrapped as Envelope<T[]>', () => {
      expect(normalizeList<number>({ code: 0, result: [1, 2] })).toEqual([1, 2]);
    });

    it('extracts result.<key> for keyed envelopes', () => {
      expect(
        normalizeList<{ id: string }>({ code: 0, result: { users: [{ id: 'a' }] } }, 'users'),
      ).toEqual([{ id: 'a' }]);
    });

    it('extracts unwrapped top-level key (legacy)', () => {
      expect(
        normalizeList<{ id: string }>({ logs: [{ id: 'x' }] }, 'logs'),
      ).toEqual([{ id: 'x' }]);
    });

    it('returns [] for HTML-body / null / undefined', () => {
      expect(normalizeList<number>(null)).toEqual([]);
      expect(normalizeList<number>(undefined)).toEqual([]);
      expect(normalizeList<number>('<html>...</html>')).toEqual([]);
      expect(normalizeList<number>({ code: 1, result: 'oops' })).toEqual([]);
    });
  });
});
