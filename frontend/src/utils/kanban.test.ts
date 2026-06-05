import { describe, expect, it } from 'vitest';

import type { ContentPlanItem, PlanStatus } from '#/api/modules/radioMedia';
import {
  KANBAN_COLUMNS,
  KANBAN_STATUSES,
  buildColumns,
  buildStatusPayload,
  columnCounts,
  isNoopStatus,
} from './kanban';

function plan(over: Partial<ContentPlanItem> = {}): ContentPlanItem {
  return {
    id: over.id ?? 'p1',
    region_code: over.region_code ?? 'marmara',
    region_name: over.region_name ?? 'Marmara',
    part_code: over.part_code ?? 'news',
    slot_time: over.slot_time ?? '08:00',
    plan_date: over.plan_date ?? '2026-06-05',
    content_title: over.content_title ?? 'Bülten',
    content_kind: over.content_kind ?? 'news',
    status: over.status ?? 'draft',
    is_global: over.is_global ?? false,
    ...over,
  };
}

describe('KANBAN_COLUMNS', () => {
  it('defines the 5-stage workflow in order', () => {
    expect(KANBAN_STATUSES).toEqual(['draft', 'published', 'running', 'paused', 'archived']);
    expect(KANBAN_COLUMNS.every((c) => c.label.length > 0)).toBe(true);
  });
});

describe('buildColumns', () => {
  it('seeds every column and buckets by status', () => {
    const plans = [
      plan({ id: 'a', status: 'draft' }),
      plan({ id: 'b', status: 'published' }),
      plan({ id: 'c', status: 'published' }),
    ];
    const cols = buildColumns(plans);
    expect(cols.size).toBe(5);
    expect(cols.get('draft')?.map((p) => p.id)).toEqual(['a']);
    expect(cols.get('published')?.map((p) => p.id)).toEqual(['b', 'c']);
    expect(cols.get('running')).toEqual([]);
  });
  it('routes unknown status into draft so nothing vanishes', () => {
    const cols = buildColumns([plan({ id: 'x', status: 'weird' as unknown as PlanStatus })]);
    expect(cols.get('draft')?.map((p) => p.id)).toEqual(['x']);
  });
});

describe('isNoopStatus', () => {
  it('detects same status', () => {
    expect(isNoopStatus(plan({ status: 'running' }), 'running')).toBe(true);
    expect(isNoopStatus(plan({ status: 'running' }), 'paused')).toBe(false);
  });
});

describe('buildStatusPayload', () => {
  it('only changes status, preserving identity and placement', () => {
    const p = plan({ id: 'x', region_code: 'ege', slot_time: '10:00:00', status: 'draft' });
    const payload = buildStatusPayload(p, 'published');
    expect(payload).toMatchObject({
      id: 'x',
      region_id: 'ege',
      slot_time: '10:00',
      status: 'published',
      part_code: 'news',
      target_regions: ['ege'],
    });
  });
});

describe('columnCounts', () => {
  it('counts plans per status', () => {
    const counts = columnCounts([
      plan({ status: 'draft' }),
      plan({ status: 'draft' }),
      plan({ status: 'archived' }),
    ]);
    expect(counts.draft).toBe(2);
    expect(counts.archived).toBe(1);
    expect(counts.running).toBe(0);
  });
});
