import {
  type ContentPlanItem,
  type PlanningSavePayload,
  type PlanStatus,
} from '#/api/modules/radioMedia';
import { STATUS_LABELS } from '#/utils/timeline';

/**
 * News / content Kanban — pure board helpers.
 *
 * Columns map to the existing `PlanStatus` workflow, so the board reuses the
 * plan `status` field and `updatePlanning` — no backend change needed. Drag a
 * card to a column to advance its workflow state.
 */

export interface KanbanColumn {
  status: PlanStatus;
  label: string;
  tone: 'draft' | 'published' | 'running' | 'paused' | 'archived';
}

/** Ordered workflow lanes, left → right. */
export const KANBAN_COLUMNS: KanbanColumn[] = [
  { status: 'draft', label: STATUS_LABELS.draft, tone: 'draft' },
  { status: 'published', label: STATUS_LABELS.published, tone: 'published' },
  { status: 'running', label: STATUS_LABELS.running, tone: 'running' },
  { status: 'paused', label: STATUS_LABELS.paused, tone: 'paused' },
  { status: 'archived', label: STATUS_LABELS.archived, tone: 'archived' },
];

export const KANBAN_STATUSES: PlanStatus[] = KANBAN_COLUMNS.map((c) => c.status);

/** Group plans into status → plans[] buckets, seeding every column. */
export function buildColumns(
  plans: ContentPlanItem[],
): Map<PlanStatus, ContentPlanItem[]> {
  const columns = new Map<PlanStatus, ContentPlanItem[]>();
  for (const status of KANBAN_STATUSES) {
    columns.set(status, []);
  }
  for (const plan of plans) {
    const bucket = columns.get(plan.status);
    if (bucket) {
      bucket.push(plan);
    } else {
      // Unknown status — surface it under draft so nothing silently vanishes.
      columns.get('draft')?.push(plan);
    }
  }
  return columns;
}

export function isNoopStatus(plan: ContentPlanItem, status: PlanStatus): boolean {
  return plan.status === status;
}

/** Builds the updatePlanning payload that only advances a plan's status. */
export function buildStatusPayload(
  plan: ContentPlanItem,
  status: PlanStatus,
): PlanningSavePayload & { id: string } {
  return {
    id: plan.id,
    region_id: plan.region_code,
    station_id: null,
    part_code: plan.part_code,
    slot_time: (plan.slot_time || '').slice(0, 5),
    plan_date: plan.plan_date,
    content_title: plan.content_title,
    content_kind: plan.content_kind ?? plan.part_code,
    status,
    is_global: plan.is_global,
    target_regions: [plan.region_code],
  };
}

/** Count of plans per column, for the lane header badges. */
export function columnCounts(
  plans: ContentPlanItem[],
): Record<PlanStatus, number> {
  const counts = Object.fromEntries(
    KANBAN_STATUSES.map((s) => [s, 0]),
  ) as Record<PlanStatus, number>;
  for (const plan of plans) {
    if (counts[plan.status] !== undefined) {
      counts[plan.status] += 1;
    } else {
      counts.draft += 1;
    }
  }
  return counts;
}
