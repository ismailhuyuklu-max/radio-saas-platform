import dayjs, { type Dayjs } from 'dayjs';

import {
  PART_LABELS,
  REGION_LABELS,
  REGION_LIST,
  type ContentPlanItem,
  type RegionCode,
  type SponsorListItem,
  type StationItem,
} from '#/api/modules/radioMedia';

/**
 * Broadcast Operations Center — pure derivation helpers.
 *
 * Every function takes plain data (and an explicit `now` where time matters) so
 * the live-ops metrics are deterministic and unit-testable. The view layer only
 * renders what these return — no business logic lives in the component.
 */

/** News bulletins air every 2h from 08:00 to 20:00 (platform requirement). */
export const NEWS_SLOTS = [
  '08:00',
  '10:00',
  '12:00',
  '14:00',
  '16:00',
  '18:00',
  '20:00',
] as const;

/** Plan statuses that mean the content is currently on air. */
const ON_AIR_STATUSES = new Set(['running', 'published']);
/** Plan statuses that count as "this broadcast went out" for coverage. */
const AIRED_STATUSES = new Set(['running', 'published', 'paused', 'archived']);

export type AlertSeverity = 'critical' | 'warning' | 'info';

export interface OpsAlert {
  id: string;
  severity: AlertSeverity;
  title: string;
  detail: string;
}

export interface OpsMetrics {
  activeStations: number;
  totalStations: number;
  activeRegions: number;
  onAir: number;
  newsOnAir: number;
  liveSponsors: number;
  pendingContent: number;
  missedBroadcasts: number;
  alertCount: number;
}

// --- stations -------------------------------------------------------------

export function isStationActive(station: StationItem): boolean {
  if (typeof station.is_active === 'boolean') {
    return station.is_active;
  }
  return station.status === 'active';
}

export function countActiveStations(stations: StationItem[]): number {
  return stations.filter(isStationActive).length;
}

/** Distinct regions (in canonical order) that have at least one active station. */
export function activeRegions(stations: StationItem[]): RegionCode[] {
  const set = new Set<RegionCode>();
  for (const station of stations) {
    if (isStationActive(station)) {
      set.add(station.region_code);
    }
  }
  return REGION_LIST.filter((region) => set.has(region));
}

// --- sponsors -------------------------------------------------------------

export function isSponsorLive(sponsor: SponsorListItem, now: Dayjs): boolean {
  if (!sponsor.is_active) {
    return false;
  }
  if (sponsor.starts_at && now.isBefore(dayjs(sponsor.starts_at))) {
    return false;
  }
  if (sponsor.ends_at && now.isAfter(dayjs(sponsor.ends_at))) {
    return false;
  }
  return true;
}

export function countLiveSponsors(sponsors: SponsorListItem[], now: Dayjs): number {
  return sponsors.filter((sponsor) => isSponsorLive(sponsor, now)).length;
}

// --- slots / time ---------------------------------------------------------

export function slotToMinutes(slot: string): number {
  const [h, m] = slot.split(':').map(Number);
  return (h || 0) * 60 + (m || 0);
}

function planSlot(plan: ContentPlanItem): string {
  return (plan.slot_time || '').slice(0, 5);
}

/** The most recent news slot at or before `now` (today), or null before 08:00. */
export function currentSlot(now: Dayjs): string | null {
  const mins = now.hour() * 60 + now.minute();
  let current: string | null = null;
  for (const slot of NEWS_SLOTS) {
    if (slotToMinutes(slot) <= mins) {
      current = slot;
    }
  }
  return current;
}

/** Slots strictly before the current one — i.e. broadcasts that should be done. */
export function pastSlots(now: Dayjs): string[] {
  const current = currentSlot(now);
  if (!current) {
    return [];
  }
  const currentMins = slotToMinutes(current);
  return NEWS_SLOTS.filter((slot) => slotToMinutes(slot) < currentMins);
}

// --- live plans -----------------------------------------------------------

export function livePlans(plans: ContentPlanItem[], now: Dayjs): ContentPlanItem[] {
  const current = currentSlot(now);
  if (!current) {
    return [];
  }
  return plans.filter(
    (plan) => planSlot(plan) === current && ON_AIR_STATUSES.has(plan.status),
  );
}

export function countOnAir(plans: ContentPlanItem[], now: Dayjs): number {
  return livePlans(plans, now).length;
}

export function countNewsOnAir(plans: ContentPlanItem[], now: Dayjs): number {
  return livePlans(plans, now).filter((plan) => plan.part_code === 'news').length;
}

/** Drafts that haven't been published yet. */
export function countPendingContent(plans: ContentPlanItem[]): number {
  return plans.filter((plan) => plan.status === 'draft').length;
}

/**
 * Past news slots × active regions that have no aired news plan.
 * Represents broadcasts that should have gone out but didn't.
 */
export function countMissedBroadcasts(
  plans: ContentPlanItem[],
  stations: StationItem[],
  now: Dayjs,
): number {
  const past = pastSlots(now);
  const regions = activeRegions(stations);
  if (past.length === 0 || regions.length === 0) {
    return 0;
  }

  const regionSet = new Set(regions);
  const covered = new Set<string>();
  for (const plan of plans) {
    if (plan.part_code !== 'news' || !AIRED_STATUSES.has(plan.status)) {
      continue;
    }
    const slot = planSlot(plan);
    if (past.includes(slot) && regionSet.has(plan.region_code)) {
      covered.add(`${plan.region_code}:${slot}`);
    }
  }

  const expected = past.length * regions.length;
  return Math.max(0, expected - covered.size);
}

// --- alerts ---------------------------------------------------------------

/** Builds the System Alerts feed from the current operational snapshot. */
export function deriveAlerts(
  stations: StationItem[],
  plans: ContentPlanItem[],
  sponsors: SponsorListItem[],
  now: Dayjs,
): OpsAlert[] {
  const alerts: OpsAlert[] = [];

  // Regions with zero active stations — broadcast blackout.
  const covered = new Set(activeRegions(stations));
  const uncovered = REGION_LIST.filter((region) => !covered.has(region));
  if (uncovered.length > 0) {
    alerts.push({
      id: 'regions-uncovered',
      severity: 'critical',
      title: `${uncovered.length} bölgede aktif istasyon yok`,
      detail: uncovered.map((region) => REGION_LABELS[region]).join(', '),
    });
  }

  // Missed broadcasts.
  const missed = countMissedBroadcasts(plans, stations, now);
  if (missed > 0) {
    alerts.push({
      id: 'missed-broadcasts',
      severity: missed >= 5 ? 'critical' : 'warning',
      title: `${missed} kaçırılan haber yayını`,
      detail: 'Geçmiş slotlarda yayınlanmamış haber bülteni var.',
    });
  }

  // Inactive stations.
  const inactive = stations.filter((station) => !isStationActive(station));
  if (inactive.length > 0) {
    alerts.push({
      id: 'inactive-stations',
      severity: 'warning',
      title: `${inactive.length} pasif istasyon`,
      detail: inactive
        .slice(0, 4)
        .map((station) => station.name)
        .join(', '),
    });
  }

  // No plan created for today.
  if (plans.length === 0) {
    alerts.push({
      id: 'no-plans-today',
      severity: 'warning',
      title: 'Bugün için plan yok',
      detail: 'Yayın akışı planlanmamış görünüyor.',
    });
  }

  // Pending drafts waiting to be published.
  const pending = countPendingContent(plans);
  if (pending > 0) {
    alerts.push({
      id: 'pending-drafts',
      severity: 'info',
      title: `${pending} taslak içerik bekliyor`,
      detail: 'Yayınlanmayı bekleyen taslak planlar var.',
    });
  }

  // No live sponsor coverage despite active sponsors existing.
  const liveSponsors = countLiveSponsors(sponsors, now);
  if (sponsors.length > 0 && liveSponsors === 0) {
    alerts.push({
      id: 'no-live-sponsors',
      severity: 'info',
      title: 'Yayında aktif reklam yok',
      detail: 'Tanımlı sponsorların hiçbiri şu an yayın penceresinde değil.',
    });
  }

  const order: Record<AlertSeverity, number> = { critical: 0, warning: 1, info: 2 };
  return alerts.sort((a, b) => order[a.severity] - order[b.severity]);
}

// --- aggregate ------------------------------------------------------------

export function computeMetrics(
  stations: StationItem[],
  plans: ContentPlanItem[],
  sponsors: SponsorListItem[],
  now: Dayjs,
): OpsMetrics {
  return {
    activeStations: countActiveStations(stations),
    totalStations: stations.length,
    activeRegions: activeRegions(stations).length,
    onAir: countOnAir(plans, now),
    newsOnAir: countNewsOnAir(plans, now),
    liveSponsors: countLiveSponsors(sponsors, now),
    pendingContent: countPendingContent(plans),
    missedBroadcasts: countMissedBroadcasts(plans, stations, now),
    alertCount: deriveAlerts(stations, plans, sponsors, now).length,
  };
}

/** Human label for a slot, e.g. "08:00 Haber". */
export function slotLabel(slot: string): string {
  return `${slot} ${PART_LABELS.news}`;
}
