import { describe, expect, it } from 'vitest';

import { extractApiError, isConflictError } from './api-error';

describe('extractApiError', () => {
  it('unwraps the "error" field from a JSON error body', () => {
    const err = new Error(JSON.stringify({ error: 'slot conflict' }));
    expect(extractApiError(err)).toBe('slot conflict');
  });

  it('falls back to the "message" field when "error" is absent', () => {
    const err = new Error(JSON.stringify({ message: 'not found' }));
    expect(extractApiError(err)).toBe('not found');
  });

  it('prefers "error" over "message" when both exist', () => {
    const err = new Error(JSON.stringify({ error: 'a', message: 'b' }));
    expect(extractApiError(err)).toBe('a');
  });

  it('returns null for a non-JSON error message', () => {
    expect(extractApiError(new Error('plain text boom'))).toBeNull();
  });

  it('returns null for JSON without error/message fields', () => {
    expect(extractApiError(new Error(JSON.stringify({ code: 500 })))).toBeNull();
  });

  it('returns null for non-Error values', () => {
    expect(extractApiError('string')).toBeNull();
    expect(extractApiError(null)).toBeNull();
    expect(extractApiError(undefined)).toBeNull();
    expect(extractApiError({ error: 'x' })).toBeNull();
  });
});

describe('isConflictError', () => {
  it('detects an English conflict message', () => {
    expect(isConflictError(new Error(JSON.stringify({ error: 'Slot CONFLICT' })))).toBe(true);
  });

  it('detects a Turkish conflict message', () => {
    expect(isConflictError(new Error(JSON.stringify({ error: 'Zaman çakışması' })))).toBe(true);
  });

  it('returns false for unrelated errors', () => {
    expect(isConflictError(new Error(JSON.stringify({ error: 'unauthorized' })))).toBe(false);
    expect(isConflictError(new Error('plain'))).toBe(false);
  });
});
