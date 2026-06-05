import { describe, expect, it } from 'vitest';

import { formatBytes, formatCompact, formatCurrency, formatNumber, formatPercent } from './format';

describe('formatNumber', () => {
  it('groups thousands with tr separators', () => {
    expect(formatNumber(1234567)).toBe('1.234.567');
  });
  it('handles non-finite input as 0', () => {
    expect(formatNumber(Number.NaN)).toBe('0');
    expect(formatNumber(Infinity)).toBe('0');
  });
});

describe('formatCurrency', () => {
  it('returns a non-empty TRY string containing the grouped amount', () => {
    const out = formatCurrency(1234567);
    expect(out).toContain('1.234.567');
    expect(out.length).toBeGreaterThan(0);
  });
  it('handles zero', () => {
    expect(formatCurrency(0)).toContain('0');
  });
});

describe('formatCompact', () => {
  it('shortens large numbers', () => {
    const out = formatCompact(1_250_000);
    expect(out.length).toBeLessThan('1.250.000'.length);
    expect(out.length).toBeGreaterThan(0);
  });
});

describe('formatPercent', () => {
  it('prefixes with %', () => {
    expect(formatPercent(42.5)).toBe('%42,5');
  });
});

describe('formatBytes', () => {
  it('formats bytes with binary units', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(1024)).toBe('1 KB');
    expect(formatBytes(1024 * 1024)).toBe('1 MB');
    expect(formatBytes(1.5 * 1024 * 1024 * 1024)).toBe('1,5 GB');
  });
});
