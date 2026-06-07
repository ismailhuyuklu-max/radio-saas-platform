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
// Default: tüm türler. Eskiden 'news' default'tu, boş bir gün için pano
// gereksiz yere boş görünüyordu.
const partFilter = ref<PartCode | undefined>(undefined);
const search = ref('');

const draggingPlan = ref<ContentPlanItem | null>(null);
const dragOverStatus = ref<PlanStatus | null>(null);

const partOptions: Array<{ label: string; value: PartCode }> = (
  Object.keys(PART_LABELS) as PartCode[]
).map((p) => ({ label: PART_LABELS[p], value: p }));

const visiblePlans = computed(() => {
  const q = search.value.trim().toLocaleLowerCase('tr-TR');
  return plans.value.filter((p) => {
    if (partFilter.value && p.part_code !== partFilter.value) return false;
    if (q && !`${p.content_title} ${p.region_name}`.toLocaleLowerCase('tr-TR').includes(q)) return false;
    return true;
  });
});

const isEmpty = computed(() => !loading.value && visiblePlans.value.length === 0);

const columns = computed(() => buildColumns(visiblePlans.value));
const counts = computed(() => columnCounts(visiblePlans.value));

function columnPlans(status: PlanStatus): ContentPlanItem[] {
  return columns.value.get(status) ?? [];
}

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
    <!-- HEADER: title + günlük özet KPI'lar -->
    <header class="kb__head">
      <div class="kb__head-text">
        <h1>Haber Akış Panosu</h1>
        <p class="kb__sub">
          {{ selectedDate.format('DD MMMM YYYY') }}
          <span class="kb__dot">·</span>
          <strong>{{ visiblePlans.length }}</strong> içerik
          <span class="kb__dot">·</span>
          sürükle-bırak ile durum değiştir
        </p>
      </div>

      <!-- Status özeti: küçük kart şeridi -->
      <div class="kb__summary">
        <div
          v-for="col in KANBAN_COLUMNS"
          :key="col.status"
          class="kb__sum"
          :class="`tone-${col.tone}`"
          :title="col.label"
        >
          <span class="kb__sum-n">{{ counts[col.status] }}</span>
          <span class="kb__sum-l">{{ col.label }}</span>
        </div>
      </div>
    </header>

    <!-- TOOLBAR: filtre + arama + tarih -->
    <div class="kb__toolbar ui-card">
      <DatePicker v-model:value="selectedDate" :allow-clear="false" class="kb__pick" @change="onDateChange" />
      <Select
        v-model:value="partFilter"
        class="kb__filter"
        allow-clear
        placeholder="Tüm türler"
        :options="partOptions"
      />
      <input v-model="search" class="kb__search" type="text" placeholder="Başlık veya bölge ara…">
      <span v-if="loading || saving" class="kb__busy">{{ loading ? 'Yükleniyor…' : 'Kaydediliyor…' }}</span>
    </div>

    <!-- EMPTY STATE: bugünkü pano boşsa, kullanışlı bir CTA göster -->
    <div v-if="isEmpty" class="kb__zero ui-card">
      <div class="kb__zero-art">📰</div>
      <h2>Bu gün için içerik yok</h2>
      <p>
        {{ selectedDate.format('DD MMMM YYYY') }} tarihinde
        <template v-if="partFilter"><strong>{{ PART_LABELS[partFilter] }}</strong> türünde </template>
        planlanmış içerik bulunamadı.
      </p>
      <div class="kb__zero-actions">
        <router-link to="/radio-platform/planning" class="kb__btn">Planlamaya Git</router-link>
        <router-link to="/radio-platform/traffic-center" class="kb__btn kb__btn--ghost">Yayın Trafik Merkezi</router-link>
      </div>
    </div>

    <!-- BOARD -->
    <div v-else class="kb__board">
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
            <header class="kb__card-top">
              <span class="kb__card-part">{{ PART_LABELS[plan.part_code] }}</span>
              <span class="kb__card-slot">{{ (plan.slot_time || '').slice(0, 5) }}</span>
            </header>
            <span class="kb__card-title">{{ plan.content_title }}</span>
            <span class="kb__card-meta">📍 {{ REGION_LABELS[plan.region_code] }}</span>
          </article>

          <div v-if="!columnPlans(col.status).length" class="kb__drop">
            <span class="kb__drop-icon">⬇</span>
            <span>Buraya sürükle</span>
          </div>
        </div>
      </section>
    </div>

    <p v-if="!isEmpty" class="kb__hint">
      İpucu: kartı başka bir kolona sürükleyerek durumunu güncelle —
      Taslak → Yayında → Canlı → Duraklatıldı → Arşiv.
    </p>
  </div>
</template>

<style scoped>
/* Faz PAGE-FIT: viewport-fit. Kanban kolonları yatay scroll
   gerektirebilir (5 sütun mobilde sığmaz) — kolon konteyneri
   içinde kendi scroll'una izin verelim. */
.kb {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: calc(100dvh - 72px);
  overflow: hidden;
  box-sizing: border-box;
}
.kb__board,
.kb__cols,
.kb > section:last-of-type,
.kb > div:last-of-type {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
}

/* ===== Header: başlık + 5 kolonun anlık özeti ===== */
.kb__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.kb__head-text h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.kb__sub {
  margin: 4px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.kb__sub strong {
  color: var(--c-text);
  font-weight: 700;
}
.kb__dot { opacity: 0.5; margin: 0 4px; }

.kb__summary {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.kb__sum {
  min-width: 80px;
  padding: 8px 14px;
  border-radius: 12px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-top: 3px solid var(--lane, var(--c-text-3));
  display: flex;
  flex-direction: column;
  align-items: center;
  line-height: 1.1;
}
.kb__sum-n {
  font-size: 18px;
  font-weight: 900;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}
.kb__sum-l {
  margin-top: 3px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--c-text-3);
}
.kb__sum.tone-draft     { --lane: #64748b; }
.kb__sum.tone-published { --lane: var(--c-ok); }
.kb__sum.tone-running   { --lane: var(--c-bad); }
.kb__sum.tone-paused    { --lane: var(--c-warn); }
.kb__sum.tone-archived  { --lane: #94a3b8; }

/* ===== Toolbar: tek satırda filtre + arama + tarih ===== */
.kb__toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 10px 14px;
}
.kb__pick { flex: 0 0 auto; }
.kb__filter { min-width: 160px; }
.kb__search {
  flex: 1;
  min-width: 180px;
  padding: 8px 12px;
  border: 1px solid var(--c-line);
  border-radius: 8px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 13px;
}
.kb__search:focus {
  outline: none;
  border-color: var(--c-info);
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.18);
}
.kb__busy {
  font-size: 12px;
  color: var(--c-text-3);
  font-style: italic;
}

/* ===== Empty state: bugün için içerik yok ===== */
.kb__zero {
  padding: 48px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  text-align: center;
}
.kb__zero-art {
  font-size: 52px;
  line-height: 1;
  margin-bottom: 4px;
}
.kb__zero h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--c-text);
}
.kb__zero p {
  margin: 0;
  max-width: 460px;
  color: var(--c-text-3);
  font-size: 13px;
}
.kb__zero-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 8px;
}
.kb__btn {
  padding: 9px 16px;
  border-radius: 9px;
  background: var(--c-brand);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  transition: filter 120ms ease;
}
.kb__btn:hover { filter: brightness(1.08); }
.kb__btn--ghost {
  background: transparent;
  color: var(--c-text);
  border: 1px solid var(--c-line);
}
.kb__btn--ghost:hover {
  border-color: var(--c-brand);
  color: var(--c-brand);
}

/* ===== Board ===== */
.kb__board {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  align-items: stretch;
}
@media (max-width: 1100px) {
  .kb__board {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (max-width: 700px) {
  .kb__board {
    grid-template-columns: 1fr;
  }
}

.kb__col {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(148, 163, 184, 0.04) 0%, transparent 100%);
  border: 1px solid var(--c-line);
  border-top: 3px solid var(--lane, var(--c-text-3));
  min-height: 280px;
  transition: background 150ms ease, border-color 150ms ease;
}
.kb__col.is-over {
  background: rgba(225, 29, 72, 0.08);
  border-color: var(--c-brand);
  box-shadow: 0 0 0 1px var(--c-brand);
}
.kb__col.tone-draft     { --lane: #64748b; }
.kb__col.tone-published { --lane: var(--c-ok); }
.kb__col.tone-running   { --lane: var(--c-bad); }
.kb__col.tone-paused    { --lane: var(--c-warn); }
.kb__col.tone-archived  { --lane: #94a3b8; }

.kb__col-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 8px;
  border-bottom: 1px dashed var(--c-line);
}
.kb__col-dot {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: var(--lane);
  box-shadow: 0 0 0 3px color-mix(in oklab, var(--lane) 20%, transparent);
}
.kb__col-head h2 {
  flex: 1;
  margin: 0;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--c-text);
}
.kb__col-count {
  min-width: 24px;
  text-align: center;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  color: var(--c-text);
  background: rgba(148, 163, 184, 0.18);
}

.kb__col-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 200px;
}

/* ===== Cards ===== */
.kb__card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 11px 12px;
  border-radius: 10px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-left: 3px solid var(--accent, var(--c-brand));
  cursor: grab;
  box-shadow: var(--sh-1);
  transition: transform 120ms ease, opacity 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
}
.kb__card:hover {
  box-shadow: var(--sh-2);
  border-color: var(--c-line-strong);
}
.kb__card:active { cursor: grabbing; }
.kb__card.is-dragging {
  opacity: 0.4;
  transform: scale(0.97) rotate(-1deg);
}
.kb__card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.kb__card-part {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  background: color-mix(in oklab, var(--accent, var(--c-brand)) 14%, transparent);
  color: var(--accent, var(--c-brand));
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.kb__card-slot {
  font-size: 11px;
  font-weight: 800;
  color: var(--c-info);
  font-variant-numeric: tabular-nums;
}
.kb__card-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--c-text);
  line-height: 1.3;
}
.kb__card-meta {
  font-size: 11px;
  color: var(--c-text-3);
}

/* ===== Empty drop zone (boş kolon) ===== */
.kb__drop {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 24px 8px;
  border: 1.5px dashed var(--c-line);
  border-radius: 10px;
  color: var(--c-text-3);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-align: center;
  background: rgba(148, 163, 184, 0.03);
  transition: border-color 150ms ease, color 150ms ease, background 150ms ease;
}
.kb__col.is-over .kb__drop {
  border-color: var(--c-brand);
  color: var(--c-brand);
  background: rgba(225, 29, 72, 0.06);
}
.kb__drop-icon {
  font-size: 18px;
  line-height: 1;
}

.kb__hint {
  margin: 0;
  font-size: 11px;
  color: var(--c-text-3);
  text-align: center;
  padding: 8px;
  border-radius: 8px;
  background: rgba(148, 163, 184, 0.04);
  border: 1px dashed var(--c-line);
}
</style>
