import { describe, expect, it } from 'vitest';

import { CONTENT_TYPES, PROVINCES, TEMPLATES, contentType, provincesToRegions } from './traffic';

describe('traffic constants', () => {
  it('has all 81 Turkish provinces', () => {
    expect(PROVINCES).toHaveLength(81);
  });
  it('maps every province to one of the 7 regions', () => {
    const regions = new Set(PROVINCES.map((p) => p.region));
    expect(regions.size).toBe(7);
  });
  it('defines 10 colour-coded content types', () => {
    expect(CONTENT_TYPES).toHaveLength(10);
    expect(CONTENT_TYPES.every((c) => /^#/.test(c.color))).toBe(true);
  });
  it('contentType falls back for unknown keys', () => {
    expect(contentType('news').label).toBe('Haber');
    expect(contentType('zzz').label).toBe('zzz');
  });
});

describe('provincesToRegions', () => {
  it('resolves provinces to unique regions', () => {
    expect(provincesToRegions(['İstanbul', 'Bursa']).sort()).toEqual(['marmara']);
    expect(provincesToRegions(['İstanbul', 'İzmir', 'Ankara']).sort()).toEqual(
      ['ege', 'ic-anadolu', 'marmara'],
    );
  });
  it('ignores unknown province names', () => {
    expect(provincesToRegions(['Atlantis'])).toEqual([]);
  });
});

describe('TEMPLATES', () => {
  it('provides quick-plan presets with slots', () => {
    expect(TEMPLATES.length).toBeGreaterThanOrEqual(8);
    expect(TEMPLATES.every((t) => t.slots.length >= 1)).toBe(true);
  });
  it('full-day template has 7 news slots', () => {
    const full = TEMPLATES.find((t) => t.key === 'tamgun');
    expect(full?.slots).toHaveLength(7);
    expect(full?.slots.every((s) => s.part_code === 'news')).toBe(true);
  });
});
