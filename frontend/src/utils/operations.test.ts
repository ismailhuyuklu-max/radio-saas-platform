import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';

import type {
  ContentPlanItem,
  SponsorListItem,
  StationItem,
} from '#/api/modules/radioMedia';
import {
  activeRegions,
  computeMetrics,
  countActiveStations,
  countLiveSponsors,
  countMissedBroadcasts,
  countNewsOnAir,
  countOnAir,
  countPendingContent,
  currentSlot,
  deriveAlerts,
  isSponsorLive,
  isStationActive,
  pastSlots,
  slotToMinutes,
} from './operations';

function station(over: Partial<StationItem> = {}): StationItem {
  return {
    id: over.id ?? 's1',
    name: over.name ?? 'Test FM',
    slug: over.slug ?? 'test-fm',
    region_code: over.region_code ?? 'marmara',
    region_name: over.region_name ?? 'Marmara',
    status: over.status ?? 'active',
    is_active: over.is_active,
    ...over,
  };
}

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
    status: over.status ?? 'published',
    is_global: over.is_global ?? false,
    ...over,
  };
}

function sponsor(over: Partial<SponsorListItem> = {}): SponsorListItem {
  return {
    id: over.id ?? 'sp1',
    sponsor_name: over.sponsor_name ?? 'Sponsor',
    region_code: over.region_code ?? 'marmara',
    region_name: over.region_name ?? 'Marmara',
    part_code: over.part_code ?? 'news',
    content_type: over.content_type ?? 'news',
    placement: over.placement ?? 'pre_roll',
    placement_type: over.placement_type ?? 'intro',
    is_global: over.is_global ?? false,
    asset_bucket: over.asset_bucket ?? 'b',
    asset_key: over.asset_key ?? 'k',
    asset_mime: over.asset_mime ?? 'audio/mpeg',
    asset_duration_ms: over.asset_duration_ms ?? 5000,
    priority: over.priority ?? 1,
    is_active: over.is_active ?? true,
    starts_at: over.starts_at,
    ends_at: over.ends_at,
    ...over,
  };
}

describe('isStationActive', () => {
  it('prefers the is_active boolean', () => {
    expect(isStationActive(station({ is_active: true, status: 'archived' }))).toBe(true);
    expect(isStationActive(station({ is_active: false, status: 'active' }))).toBe(false);
  });
  it('falls back to status when is_active is absent', () => {
    expect(isStationActive(station({ is_active: undefined, status: 'active' }))).toBe(true);
    expect(isStationActive(station({ is_active: undefined, status: 'paused' }))).toBe(false);
  });
});

describe('countActiveStations / activeRegions', () => {
  const stations = [
    station({ id: '1', region_code: 'marmara', is_active: true }),
    station({ id: '2', region_code: 'marmara', is_active: true }),
    station({ id: '3', region_code: 'ege', is_active: false }),
    station({ id: '4', region_code: 'akdeniz', status: 'active', is_active: undefined }),
  ];
  it('counts only active stations', () => {
    expect(countActiveStations(stations)).toBe(3);
  });
  it('returns distinct active regions in canonical order', () => {
    expect(activeRegions(stations)).toEqual(['marmara', 'akdeniz']);
  });
});

describe('slot helpers', () => {
  it('slotToMinutes parses HH:MM', () => {
    expect(slotToMinutes('08:00')).toBe(480);
    expect(slotToMinutes('20:30')).toBe(1230);
  });
  it('currentSlot returns null before 08:00', () => {
    expect(currentSlot(dayjs('2026-06-05T06:00:00'))).toBeNull();
  });
  it('currentSlot returns the latest slot at/before now', () => {
    expect(currentSlot(dayjs('2026-06-05T09:30:00'))).toBe('08:00');
    expect(currentSlot(dayjs('2026-06-05T14:00:00'))).toBe('14:00');
    expect(currentSlot(dayjs('2026-06-05T23:00:00'))).toBe('20:00');
  });
  it('pastSlots excludes the current slot', () => {
    expect(pastSlots(dayjs('2026-06-05T11:00:00'))).toEqual(['08:00']);
    expect(pastSlots(dayjs('2026-06-05T06:00:00'))).toEqual([]);
    expect(pastSlots(dayjs('2026-06-05T15:00:00'))).toEqual(['08:00', '10:00', '12:00']);
  });
});

describe('isSponsorLive / countLiveSponsors', () => {
  const now = dayjs('2026-06-05T12:00:00');
  it('inactive sponsor is never live', () => {
    expect(isSponsorLive(sponsor({ is_active: false }), now)).toBe(false);
  });
  it('respects the start/end window', () => {
    expect(isSponsorLive(sponsor({ starts_at: '2026-06-06T00:00:00' }), now)).toBe(false);
    expect(isSponsorLive(sponsor({ ends_at: '2026-06-04T00:00:00' }), now)).toBe(false);
    expect(
      isSponsorLive(
        sponsor({ starts_at: '2026-06-01T00:00:00', ends_at: '2026-06-30T00:00:00' }),
        now,
      ),
    ).toBe(true);
  });
  it('open-ended active sponsor is live', () => {
    expect(isSponsorLive(sponsor({ starts_at: undefined, ends_at: undefined }), now)).toBe(true);
  });
  it('counts live sponsors', () => {
    expect(
      countLiveSponsors([sponsor({ is_active: true }), sponsor({ is_active: false })], now),
    ).toBe(1);
  });
});

describe('on-air plans', () => {
  const now = dayjs('2026-06-05T08:30:00'); // current slot 08:00
  const plans = [
    plan({ id: 'a', slot_time: '08:00', status: 'running', part_code: 'news' }),
    plan({ id: 'b', slot_time: '08:00', status: 'published', part_code: 'sports' }),
    plan({ id: 'c', slot_time: '08:00', status: 'draft', part_code: 'news' }),
    plan({ id: 'd', slot_time: '10:00', status: 'running', part_code: 'news' }),
  ];
  it('countOnAir counts running/published at current slot', () => {
    expect(countOnAir(plans, now)).toBe(2);
  });
  it('countNewsOnAir filters to news', () => {
    expect(countNewsOnAir(plans, now)).toBe(1);
  });
  it('countPendingContent counts drafts', () => {
    expect(countPendingContent(plans)).toBe(1);
  });
});

describe('countMissedBroadcasts', () => {
  const stations = [
    station({ id: '1', region_code: 'marmara', is_active: true }),
    station({ id: '2', region_code: 'ege', is_active: true }),
  ];
  const now = dayjs('2026-06-05T11:00:00'); // current 10:00, past = [08:00]

  it('is zero when there are no past slots yet', () => {
    expect(countMissedBroadcasts([], stations, dayjs('2026-06-05T08:30:00'))).toBe(0);
  });
  it('counts expected minus covered for past slots', () => {
    // 1 past slot x 2 active regions = 2 expected; cover marmara only -> 1 missed
    const plans = [plan({ slot_time: '08:00', region_code: 'marmara', status: 'published' })];
    expect(countMissedBroadcasts(plans, stations, now)).toBe(1);
  });
  it('drafts do not count as covered', () => {
    const plans = [plan({ slot_time: '08:00', region_code: 'marmara', status: 'draft' })];
    expect(countMissedBroadcasts(plans, stations, now)).toBe(2);
  });
  it('full coverage yields zero', () => {
    const plans = [
      plan({ slot_time: '08:00', region_code: 'marmara', status: 'published' }),
      plan({ slot_time: '08:00', region_code: 'ege', status: 'running' }),
    ];
    expect(countMissedBroadcasts(plans, stations, now)).toBe(0);
  });
});

describe('deriveAlerts', () => {
  const now = dayjs('2026-06-05T11:00:00');
  it('flags uncovered regions as critical and sorts critical first', () => {
    const alerts = deriveAlerts([station({ region_code: 'marmara', is_active: true })], [], [], now);
    expect(alerts[0].severity).toBe('critical');
    expect(alerts.some((a) => a.id === 'regions-uncovered')).toBe(true);
  });
  it('flags missing plans for today', () => {
    const stations = [station({ region_code: 'marmara', is_active: true })];
    const alerts = deriveAlerts(stations, [], [], now);
    expect(alerts.some((a) => a.id === 'no-plans-today')).toBe(true);
  });
  it('flags inactive stations', () => {
    const stations = [
      station({ id: '1', region_code: 'marmara', is_active: true }),
      station({ id: '2', region_code: 'marmara', is_active: false, name: 'Pasif FM' }),
    ];
    const alerts = deriveAlerts(stations, [plan()], [], now);
    expect(alerts.find((a) => a.id === 'inactive-stations')?.detail).toContain('Pasif FM');
  });
});

describe('computeMetrics', () => {
  it('aggregates a full snapshot', () => {
    const now = dayjs('2026-06-05T08:30:00');
    const stations = [
      station({ id: '1', region_code: 'marmara', is_active: true }),
      station({ id: '2', region_code: 'ege', is_active: false }),
    ];
    const plans = [plan({ slot_time: '08:00', status: 'running', part_code: 'news' })];
    const sponsors = [sponsor({ is_active: true })];
    const m = computeMetrics(stations, plans, sponsors, now);
    expect(m.activeStations).toBe(1);
    expect(m.totalStations).toBe(2);
    expect(m.activeRegions).toBe(1);
    expect(m.onAir).toBe(1);
    expect(m.newsOnAir).toBe(1);
    expect(m.liveSponsors).toBe(1);
    expect(m.alertCount).toBeGreaterThan(0);
  });
});
