import { describe, expect, it } from 'vitest';

import type { ContentPlanItem, RegionCode } from '#/api/modules/radioMedia';
import {
  buildGrid,
  buildMovePayload,
  cellKey,
  hasConflict,
  isNoopMove,
  planCardLabel,
  planSlot,
} from './timeline';

function plan(over: Partial<ContentPlanItem> = {}): ContentPlanItem {
  return {
    id: over.id ?? 'p1',
    region_code: over.region_code ?? 'marmara',
    region_name: over.region_name ?? 'Marmara',
    part_code: over.part_code ?? 'news',
    slot_time: over.slot_time ?? '08:00',
    plan_date: over.plan_date ?? '2026-06-05',
    content_title: over.content_title ?? 'Sabah Bülteni',
    content_kind: over.content_kind ?? 'news',
    status: over.status ?? 'published',
    is_global: over.is_global ?? false,
    ...over,
  };
}

const REGIONS: RegionCode[] = ['marmara', 'ege'];
const SLOTS = ['08:00', '10:00'] as const;

describe('planSlot / cellKey', () => {
  it('normalizes slot_time to HH:MM', () => {
    expect(planSlot(plan({ slot_time: '08:00:00' }))).toBe('08:00');
  });
  it('builds a region:slot key', () => {
    expect(cellKey('ege', '10:00')).toBe('ege:10:00');
  });
});

describe('buildGrid', () => {
  it('seeds every cell and buckets plans by region/slot', () => {
    const plans = [
      plan({ id: 'a', region_code: 'marmara', slot_time: '08:00' }),
      plan({ id: 'b', region_code: 'ege', slot_time: '10:00' }),
    ];
    const grid = buildGrid(plans, REGIONS, SLOTS);
    expect(grid.size).toBe(4); // 2 regions x 2 slots
    expect(grid.get('marmara:08:00')?.map((p) => p.id)).toEqual(['a']);
    expect(grid.get('ege:10:00')?.map((p) => p.id)).toEqual(['b']);
    expect(grid.get('ege:08:00')).toEqual([]);
  });
  it('ignores plans outside the given regions/slots', () => {
    const plans = [plan({ region_code: 'akdeniz', slot_time: '12:00' })];
    const grid = buildGrid(plans, REGIONS, SLOTS);
    const total = [...grid.values()].reduce((n, b) => n + b.length, 0);
    expect(total).toBe(0);
  });
});

describe('isNoopMove', () => {
  it('detects same region + slot', () => {
    expect(isNoopMove(plan({ region_code: 'ege', slot_time: '10:00' }), 'ege', '10:00')).toBe(true);
  });
  it('false when region or slot differs', () => {
    expect(isNoopMove(plan({ region_code: 'ege', slot_time: '10:00' }), 'ege', '08:00')).toBe(false);
    expect(isNoopMove(plan({ region_code: 'ege', slot_time: '10:00' }), 'marmara', '10:00')).toBe(false);
  });
});

describe('hasConflict', () => {
  const plans = [
    plan({ id: 'a', region_code: 'marmara', slot_time: '08:00', part_code: 'news' }),
    plan({ id: 'b', region_code: 'marmara', slot_time: '08:00', part_code: 'sports' }),
  ];
  it('flags same region/slot/part', () => {
    expect(hasConflict(plans, 'marmara', '08:00', 'news')).toBe(true);
  });
  it('different part in same cell is not a conflict', () => {
    expect(hasConflict(plans, 'marmara', '08:00', 'weather')).toBe(false);
  });
  it('excludes the moving plan from conflict check', () => {
    expect(hasConflict(plans, 'marmara', '08:00', 'news', 'a')).toBe(false);
  });
  it('empty target cell is never a conflict', () => {
    expect(hasConflict(plans, 'ege', '10:00', 'news')).toBe(false);
  });
});

describe('buildMovePayload', () => {
  it('carries plan identity and applies new region/slot', () => {
    const p = plan({ id: 'x', region_code: 'marmara', slot_time: '08:00', status: 'running' });
    const payload = buildMovePayload(p, 'ege', '12:00');
    expect(payload).toMatchObject({
      id: 'x',
      region_id: 'ege',
      slot_time: '12:00',
      part_code: 'news',
      content_title: 'Sabah Bülteni',
      status: 'running',
      target_regions: ['ege'],
    });
  });
  it('falls back content_kind to part_code', () => {
    const p = plan({ content_kind: undefined as unknown as ContentPlanItem['content_kind'] });
    expect(buildMovePayload(p, 'ege', '10:00').content_kind).toBe('news');
  });
});

describe('planCardLabel', () => {
  it('combines part label and title', () => {
    expect(planCardLabel(plan({ part_code: 'sports', content_title: 'Maç Özeti' }))).toBe(
      'Spor · Maç Özeti',
    );
  });
});
