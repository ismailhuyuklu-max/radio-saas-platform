<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import { DatePicker, Input, Modal, Select, message } from 'ant-design-vue';

import {
  PART_LABELS,
  REGION_LABELS,
  REGION_LIST,
  getPlanning,
  savePlanning,
  updatePlanning,
  type ContentPlanItem,
  type PartCode,
  type PlanStatus,
  type RegionCode,
} from '#/api/modules/radioMedia';
import { extractApiError, isConflictError } from '#/utils/api-error';
import { NEWS_SLOTS } from '#/utils/operations';
import {
  PART_COLORS,
  STATUS_LABELS,
  buildGrid,
  buildMovePayload,
  cellKey,
  hasConflict,
  isNoopMove,
} from '#/utils/timeline';

const loading = ref(false);
const saving = ref(false);
const selectedDate = ref<Dayjs>(dayjs());
const plans = ref<ContentPlanItem[]>([]);

const draggingPlan = ref<ContentPlanItem | null>(null);
const dragOverKey = ref<string | null>(null);

const partFilter = ref<PartCode | undefined>(undefined);

const partOptions: Array<{ label: string; value: PartCode }> = (
  Object.keys(PART_LABELS) as PartCode[]
).map((p) => ({ label: PART_LABELS[p], value: p }));
const statusOptions: Array<{ label: string; value: PlanStatus }> = (
  Object.keys(STATUS_LABELS) as PlanStatus[]
).map((s) => ({ label: STATUS_LABELS[s], value: s }));

const visiblePlans = computed(() =>
  partFilter.value ? plans.value.filter((p) => p.part_code === partFilter.value) : plans.value,
);

const grid = computed(() => buildGrid(visiblePlans.value, REGION_LIST, NEWS_SLOTS));

function cellPlans(region: RegionCode, slot: string): ContentPlanItem[] {
  return grid.value.get(cellKey(region, slot)) ?? [];
}

const totalPlanned = computed(() => visiblePlans.value.length);

async function load() {
  loading.value = true;
  try {
    const res = await getPlanning({ date: selectedDate.value.format('YYYY-MM-DD') });
    plans.value = Array.isArray(res?.plans) ? res.plans : [];
  } catch (e) {
    plans.value = [];
    message.error(extractApiError(e) ?? 'Plan verisi alınamadı.');
  } finally {
    loading.value = false;
  }
}

// --- drag & drop ----------------------------------------------------------

function onDragStart(plan: ContentPlanItem, event: DragEvent) {
  draggingPlan.value = plan;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', plan.id);
  }
}

function onDragEnd() {
  draggingPlan.value = null;
  dragOverKey.value = null;
}

function onDragOver(region: RegionCode, slot: string, event: DragEvent) {
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
  dragOverKey.value = cellKey(region, slot);
}

function onDragLeave(region: RegionCode, slot: string) {
  if (dragOverKey.value === cellKey(region, slot)) {
    dragOverKey.value = null;
  }
}

async function onDrop(region: RegionCode, slot: string) {
  const plan = draggingPlan.value;
  dragOverKey.value = null;
  draggingPlan.value = null;
  if (!plan || isNoopMove(plan, region, slot)) {
    return;
  }
  if (hasConflict(plans.value, region, slot, plan.part_code, plan.id)) {
    message.warning(
      `${REGION_LABELS[region]} · ${slot} kuşağında zaten ${PART_LABELS[plan.part_code]} planı var.`,
    );
    return;
  }

  // Optimistic move; revert by reloading on failure.
  const previous = plans.value;
  plans.value = plans.value.map((p) =>
    p.id === plan.id
      ? { ...p, region_code: region, region_name: REGION_LABELS[region], slot_time: slot }
      : p,
  );
  saving.value = true;
  try {
    await updatePlanning(plan.id, buildMovePayload(plan, region, slot));
    message.success(`"${plan.content_title}" → ${REGION_LABELS[region]} ${slot}`);
    await load();
  } catch (error) {
    plans.value = previous;
    if (isConflictError(error)) {
      message.error('Zaman çakışması — bu kuşak dolu.');
    } else {
      message.error(extractApiError(error) ?? 'Plan taşınamadı.');
    }
  } finally {
    saving.value = false;
  }
}

// --- quick create ---------------------------------------------------------

const modalOpen = ref(false);
const createForm = ref<{
  region: RegionCode;
  slot: string;
  part_code: PartCode;
  content_title: string;
  status: PlanStatus;
}>({
  region: 'marmara',
  slot: '08:00',
  part_code: 'news',
  content_title: '',
  status: 'published',
});

function openCreate(region: RegionCode, slot: string) {
  createForm.value = {
    region,
    slot,
    part_code: 'news',
    content_title: '',
    status: 'published',
  };
  modalOpen.value = true;
}

async function submitCreate() {
  const form = createForm.value;
  if (!form.content_title.trim()) {
    message.warning('İçerik başlığı gerekli.');
    return;
  }
  if (hasConflict(plans.value, form.region, form.slot, form.part_code)) {
    message.warning('Bu kuşakta aynı türde plan zaten var.');
    return;
  }
  saving.value = true;
  try {
    await savePlanning({
      region_id: form.region,
      station_id: null,
      part_code: form.part_code,
      slot_time: form.slot,
      plan_date: selectedDate.value.format('YYYY-MM-DD'),
      content_title: form.content_title.trim(),
      content_kind: form.part_code,
      status: form.status,
      target_regions: [form.region],
      created_by: 'admin',
    });
    message.success('Plan oluşturuldu.');
    modalOpen.value = false;
    await load();
  } catch (error) {
    if (isConflictError(error)) {
      message.error('Zaman çakışması — bu kuşak dolu.');
    } else {
      message.error(extractApiError(error) ?? 'Plan oluşturulamadı.');
    }
  } finally {
    saving.value = false;
  }
}

function onDateChange() {
  void load();
}

onMounted(load);
</script>

<template>
  <div class="tl">
    <header class="tl__head">
      <div>
        <h1>Yayın Zaman Çizelgesi</h1>
        <p class="tl__sub">{{ totalPlanned }} plan · sürükle-bırak ile kuşak değiştir</p>
      </div>
      <div class="tl__controls">
        <DatePicker v-model:value="selectedDate" :allow-clear="false" @change="onDateChange" />
        <Select
          v-model:value="partFilter"
          class="tl__filter"
          allow-clear
          placeholder="Tür filtrele"
          :options="partOptions"
        />
      </div>
    </header>

    <!-- Legend -->
    <div class="tl__legend">
      <span v-for="opt in partOptions" :key="opt.value" class="tl__legend-item">
        <i :style="{ background: PART_COLORS[opt.value] }" />{{ opt.label }}
      </span>
    </div>

    <!-- Scheduler grid -->
    <div class="tl__scroll ui-card">
      <div class="tl__grid" :style="{ '--cols': NEWS_SLOTS.length }">
        <!-- header row -->
        <div class="tl__corner">Bölge \ Saat</div>
        <div v-for="slot in NEWS_SLOTS" :key="`h-${slot}`" class="tl__colhead">{{ slot }}</div>

        <!-- region rows -->
        <template v-for="region in REGION_LIST" :key="region">
          <div class="tl__rowhead">{{ REGION_LABELS[region] }}</div>
          <div
            v-for="slot in NEWS_SLOTS"
            :key="cellKey(region, slot)"
            class="tl__cell"
            :class="{ 'is-over': dragOverKey === cellKey(region, slot) }"
            @dragover="onDragOver(region, slot, $event)"
            @dragleave="onDragLeave(region, slot)"
            @drop="onDrop(region, slot)"
          >
            <div
              v-for="plan in cellPlans(region, slot)"
              :key="plan.id"
              class="tl__card"
              :class="{ 'is-dragging': draggingPlan?.id === plan.id }"
              :style="{ '--accent': PART_COLORS[plan.part_code] }"
              draggable="true"
              :title="`${PART_LABELS[plan.part_code]} · ${STATUS_LABELS[plan.status]}`"
              @dragstart="onDragStart(plan, $event)"
              @dragend="onDragEnd"
            >
              <span class="tl__card-part">{{ PART_LABELS[plan.part_code] }}</span>
              <span class="tl__card-title">{{ plan.content_title }}</span>
              <span class="tl__card-status" :class="`is-${plan.status}`">{{ STATUS_LABELS[plan.status] }}</span>
            </div>
            <button
              v-if="!cellPlans(region, slot).length"
              type="button"
              class="tl__add"
              @click="openCreate(region, slot)"
            >
              +
            </button>
          </div>
        </template>
      </div>
    </div>

    <p class="tl__hint">
      İpucu: Bir plan kartını başka bir hücreye sürükleyerek bölge veya saat kuşağını
      değiştirebilirsin. Boş hücredeki <strong>+</strong> ile yeni plan ekle.
    </p>

    <!-- Quick create modal -->
    <Modal v-model:open="modalOpen" title="Yeni Plan" :confirm-loading="saving" @ok="submitCreate">
      <div class="tl__form">
        <label>
          <span>Bölge</span>
          <Select
            v-model:value="createForm.region"
            :options="REGION_LIST.map((r) => ({ label: REGION_LABELS[r], value: r }))"
          />
        </label>
        <label>
          <span>Saat Kuşağı</span>
          <Select
            v-model:value="createForm.slot"
            :options="NEWS_SLOTS.map((s) => ({ label: s, value: s }))"
          />
        </label>
        <label>
          <span>Tür</span>
          <Select v-model:value="createForm.part_code" :options="partOptions" />
        </label>
        <label>
          <span>İçerik Başlığı</span>
          <Input v-model:value="createForm.content_title" placeholder="örn. Sabah Haber Bülteni" />
        </label>
        <label>
          <span>Durum</span>
          <Select v-model:value="createForm.status" :options="statusOptions" />
        </label>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
/* Faz PAGE-FIT: viewport-fit. Gantt scroll iki yönlü olabilir. */
.tl {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: calc(100dvh - 72px);
  overflow: hidden;
  box-sizing: border-box;
}
.tl__body,
.tl__gantt,
.tl > section:last-of-type,
.tl > div:last-of-type {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
}

.tl__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}

.tl__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}

.tl__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}

.tl__controls {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.tl__filter {
  min-width: 150px;
}

.tl__legend {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}
.tl__legend-item {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  font-weight: 600;
}
.tl__legend-item i {
  width: 11px;
  height: 11px;
  border-radius: 3px;
}

.tl__scroll {
  padding: var(--sp-3);
  overflow-x: auto;
}

.tl__grid {
  display: grid;
  grid-template-columns: 120px repeat(var(--cols), minmax(120px, 1fr));
  gap: 6px;
  min-width: 760px;
}

.tl__corner {
  position: sticky;
  left: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-3);
  background: var(--c-surface);
}

.tl__colhead {
  text-align: center;
  padding: 8px 0;
  font-size: var(--t-sm);
  font-weight: 800;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}

.tl__rowhead {
  position: sticky;
  left: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  padding: 0 8px;
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text-2);
  background: var(--c-surface);
  border-right: 1px solid var(--c-line);
}

.tl__cell {
  min-height: 74px;
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 5px;
  border-radius: 10px;
  border: 1px dashed transparent;
  background: rgba(148, 163, 184, 0.04);
  transition: background 120ms ease, border-color 120ms ease;
}
.tl__cell.is-over {
  border-color: var(--c-brand);
  background: rgba(225, 29, 72, 0.1);
}

.tl__card {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 7px 9px;
  border-radius: 8px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-left: 3px solid var(--accent, var(--c-brand));
  cursor: grab;
  box-shadow: var(--sh-1);
  transition: transform 120ms ease, opacity 120ms ease;
}
.tl__card:active {
  cursor: grabbing;
}
.tl__card.is-dragging {
  opacity: 0.4;
  transform: scale(0.97);
}

.tl__card-part {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--accent, var(--c-brand));
}
.tl__card-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--c-text);
  line-height: 1.2;
}
.tl__card-status {
  font-size: 10px;
  font-weight: 700;
}
.tl__card-status.is-running {
  color: var(--c-bad);
}
.tl__card-status.is-published {
  color: var(--c-ok);
}
.tl__card-status.is-draft {
  color: var(--c-text-3);
}
.tl__card-status.is-paused,
.tl__card-status.is-archived {
  color: var(--c-warn);
}

.tl__add {
  margin: auto;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  border: 1px dashed var(--c-line);
  background: transparent;
  color: var(--c-text-3);
  font-size: 16px;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
  opacity: 0;
  transition: opacity 120ms ease, color 120ms ease, border-color 120ms ease;
}
.tl__cell:hover .tl__add {
  opacity: 1;
}
.tl__add:hover {
  color: var(--c-brand);
  border-color: var(--c-brand);
}

.tl__hint {
  margin: 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.tl__hint strong {
  color: var(--c-text-2);
}

.tl__form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.tl__form label {
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.tl__form label span {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-2);
}
</style>
