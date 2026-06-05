import {
  PART_LABELS,
  type ContentPlanItem,
  type PartCode,
  type PlanningSavePayload,
  type PlanStatus,
  type RegionCode,
} from '#/api/modules/radioMedia';

/**
 * Timeline Broadcast Center — pure scheduling helpers.
 *
 * The drag-and-drop grid is region-rows × time-slot-columns. All grid building,
 * conflict detection and move-payload construction lives here as pure functions
 * so the scheduling rules are unit-tested independently of the DOM.
 */

export const PART_COLORS: Record<PartCode, string> = {
  news: '#3b82f6',
  sports: '#22c55e',
  economy: '#f59e0b',
  weather: '#06b6d4',
};

export const STATUS_LABELS: Record<PlanStatus, string> = {
  draft: 'Taslak',
  published: 'Yayında',
  running: 'Canlı',
  paused: 'Duraklatıldı',
  archived: 'Arşiv',
};

export function planSlot(plan: ContentPlanItem): string {
  return (plan.slot_time || '').slice(0, 5);
}

export function cellKey(region: RegionCode, slot: string): string {
  return `${region}:${slot}`;
}

/** Group plans into a region:slot → plans[] map, seeding every cell empty. */
export function buildGrid(
  plans: ContentPlanItem[],
  regions: RegionCode[],
  slots: readonly string[],
): Map<string, ContentPlanItem[]> {
  const grid = new Map<string, ContentPlanItem[]>();
  for (const region of regions) {
    for (const slot of slots) {
      grid.set(cellKey(region, slot), []);
    }
  }
  for (const plan of plans) {
    const key = cellKey(plan.region_code, planSlot(plan));
    const bucket = grid.get(key);
    if (bucket) {
      bucket.push(plan);
    }
  }
  return grid;
}

/** True if the move would leave the plan exactly where it is. */
export function isNoopMove(
  plan: ContentPlanItem,
  region: RegionCode,
  slot: string,
): boolean {
  return plan.region_code === region && planSlot(plan) === slot;
}

/**
 * A conflict exists when the target cell already holds a plan of the same
 * content type (e.g. two news bulletins in one region/slot), ignoring the
 * plan being moved.
 */
export function hasConflict(
  plans: ContentPlanItem[],
  region: RegionCode,
  slot: string,
  partCode: PartCode,
  excludeId?: string,
): boolean {
  return plans.some(
    (plan) =>
      plan.region_code === region &&
      planSlot(plan) === slot &&
      plan.part_code === partCode &&
      plan.id !== excludeId,
  );
}

/** Builds the updatePlanning payload for moving a plan to a new region/slot. */
export function buildMovePayload(
  plan: ContentPlanItem,
  region: RegionCode,
  slot: string,
): PlanningSavePayload & { id: string } {
  return {
    id: plan.id,
    region_id: region,
    station_id: null,
    part_code: plan.part_code,
    slot_time: slot,
    plan_date: plan.plan_date,
    content_title: plan.content_title,
    content_kind: plan.content_kind ?? plan.part_code,
    status: plan.status,
    is_global: plan.is_global,
    target_regions: [region],
  };
}

/** Short human label for a plan card, e.g. "Haber · Sabah Bülteni". */
export function planCardLabel(plan: ContentPlanItem): string {
  return `${PART_LABELS[plan.part_code]} · ${plan.content_title}`;
}
