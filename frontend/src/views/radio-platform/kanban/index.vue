<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import { DatePicker, Select, message } from 'ant-design-vue';

import {
  PART_LABELS,
  REGION_LABELS,
  getPlanning,
  updatePlanning,
  type ContentPlanItem,
  type PartCode,
  type PlanStatus,
} from '#/api/modules/radioMedia';
import { extractApiError, isConflictError } from '#/utils/api-error';
import { PART_COLORS } from '#/utils/timeline';
import {
  KANBAN_COLUMNS,
  buildColumns,
  buildStatusPayload,
  columnCounts,
  isNoopStatus,
} from '#/utils/kanban';

const loading = ref(false);
const saving = ref(false);
const selectedDate = ref<Dayjs>(dayjs());
const plans = ref<ContentPlanItem[]>([]);
const partFilter = ref<PartCode | undefined>('news');

const draggingPlan = ref<ContentPlanItem | null>(null);
const dragOverStatus = ref<PlanStatus | null>(null);

const partOptions: Array<{ label: string; value: PartCode }> = (
  Object.keys(PART_LABELS) as PartCode[]
).map((p) => ({ label: PART_LABELS[p], value: p }));

const visiblePlans = computed(() =>
  partFilter.value ? plans.value.filter((p) => p.part_code === partFilter.value) : plans.value,
);

const columns = computed(() => buildColumns(visiblePlans.value));
const counts = computed(() => columnCounts(visiblePlans.value));

function columnPlans(status: PlanStatus): ContentPlanItem[] {
  return columns.value.get(status) ?? [];
}

async function load() {
  loading.value = true;
  try {
    const res = await getPlanning({ date: selectedDate.value.format('YYYY-MM-DD') });
    plans.value = res?.plans ?? [];
  } catch {
    message.error('Plan verisi alınamadı.');
  } finally {
    loading.value = false;
  }
}

function onDragStart(plan: ContentPlanItem, event: DragEvent) {
  draggingPlan.value = plan;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', plan.id);
  }
}

function onDragEnd() {
  draggingPlan.value = null;
  dragOverStatus.value = null;
}

function onDragOver(status: PlanStatus, event: DragEvent) {
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
  dragOverStatus.value = status;
}

function onDragLeave(status: PlanStatus) {
  if (dragOverStatus.value === status) {
    dragOverStatus.value = null;
  }
}

async function onDrop(status: PlanStatus) {
  const plan = draggingPlan.value;
  dragOverStatus.value = null;
  draggingPlan.value = null;
  if (!plan || isNoopStatus(plan, status)) {
    return;
  }

  const previous = plans.value;
  plans.value = plans.value.map((p) => (p.id === plan.id ? { ...p, status } : p));
  saving.value = true;
  try {
    await updatePlanning(plan.id, buildStatusPayload(plan, status));
    message.success(`"${plan.content_title}" → ${statusLabel(status)}`);
    await load();
  } catch (error) {
    plans.value = previous;
    if (isConflictError(error)) {
      message.error('Durum güncellenemedi — çakışma.');
    } else {
      message.error(extractApiError(error) ?? 'Durum güncellenemedi.');
    }
  } finally {
    saving.value = false;
  }
}

function statusLabel(status: PlanStatus): string {
  return KANBAN_COLUMNS.find((c) => c.status === status)?.label ?? status;
}

function onDateChange() {
  void load();
}

onMounted(load);
</script>

<template>
  <div class="kb">
    <header class="kb__head">
      <div>
        <h1>Haber Akış Panosu</h1>
        <p class="kb__sub">{{ visiblePlans.length }} içerik · sürükle-bırak ile durum değiştir</p>
      </div>
      <div class="kb__controls">
        <DatePicker v-model:value="selectedDate" :allow-clear="false" @change="onDateChange" />
        <Select
          v-model:value="partFilter"
          class="kb__filter"
          allow-clear
          placeholder="Tüm türler"
          :options="partOptions"
        />
      </div>
    </header>

    <div class="kb__board">
      <section
        v-for="col in KANBAN_COLUMNS"
        :key="col.status"
        class="kb__col"
        :class="[`tone-${col.tone}`, { 'is-over': dragOverStatus === col.status }]"
        @dragover="onDragOver(col.status, $event)"
        @dragleave="onDragLeave(col.status)"
        @drop="onDrop(col.status)"
      >
        <header class="kb__col-head">
          <span class="kb__col-dot" />
          <h2>{{ col.label }}</h2>
          <span class="kb__col-count">{{ counts[col.status] }}</span>
        </header>

        <div class="kb__col-body">
          <article
            v-for="plan in columnPlans(col.status)"
            :key="plan.id"
            class="kb__card"
            :class="{ 'is-dragging': draggingPlan?.id === plan.id }"
            :style="{ '--accent': PART_COLORS[plan.part_code] }"
            draggable="true"
            @dragstart="onDragStart(plan, $event)"
            @dragend="onDragEnd"
          >
            <span class="kb__card-part">{{ PART_LABELS[plan.part_code] }}</span>
            <span class="kb__card-title">{{ plan.content_title }}</span>
            <span class="kb__card-meta">{{ REGION_LABELS[plan.region_code] }} · {{ (plan.slot_time || '').slice(0, 5) }}</span>
          </article>

          <p v-if="!columnPlans(col.status).length" class="kb__empty">—</p>
        </div>
      </section>
    </div>

    <p class="kb__hint">
      Bir içerik kartını başka bir kolona sürükleyerek iş akışı durumunu güncelle
      (Taslak → Yayında → Canlı → Arşiv).
    </p>
  </div>
</template>

<style scoped>
.kb {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}

.kb__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}

.kb__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}

.kb__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}

.kb__controls {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.kb__filter {
  min-width: 150px;
}

.kb__board {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(240px, 1fr);
  gap: var(--sp-3);
  overflow-x: auto;
  padding-bottom: 6px;
  align-items: start;
}

.kb__col {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 180px;
  padding: var(--sp-3);
  border-radius: var(--r-lg);
  background: rgba(148, 163, 184, 0.04);
  border: 1px solid var(--c-line);
  border-top: 3px solid var(--lane, var(--c-text-3));
  transition: background 120ms ease, border-color 120ms ease;
}
.kb__col.is-over {
  background: rgba(225, 29, 72, 0.08);
  border-color: var(--c-brand);
}

.kb__col.tone-draft {
  --lane: #64748b;
}
.kb__col.tone-published {
  --lane: var(--c-ok);
}
.kb__col.tone-running {
  --lane: var(--c-bad);
}
.kb__col.tone-paused {
  --lane: var(--c-warn);
}
.kb__col.tone-archived {
  --lane: #94a3b8;
}

.kb__col-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.kb__col-dot {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  background: var(--lane);
}
.kb__col-head h2 {
  flex: 1;
  margin: 0;
  font-size: var(--t-sm);
  font-weight: 800;
  letter-spacing: -0.01em;
  color: var(--c-text);
}
.kb__col-count {
  min-width: 22px;
  text-align: center;
  padding: 1px 7px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 800;
  color: var(--c-text-2);
  background: rgba(148, 163, 184, 0.14);
}

.kb__col-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 60px;
}

.kb__card {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 10px 11px;
  border-radius: 10px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-left: 3px solid var(--accent, var(--c-brand));
  cursor: grab;
  box-shadow: var(--sh-1);
  transition: transform 120ms ease, opacity 120ms ease, box-shadow 120ms ease;
}
.kb__card:hover {
  box-shadow: var(--sh-2);
}
.kb__card:active {
  cursor: grabbing;
}
.kb__card.is-dragging {
  opacity: 0.4;
  transform: scale(0.97);
}

.kb__card-part {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--accent, var(--c-brand));
}
.kb__card-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--c-text);
  line-height: 1.25;
}
.kb__card-meta {
  font-size: 11px;
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
}

.kb__empty {
  margin: 0;
  padding: 14px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

.kb__hint {
  margin: 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
</style>
