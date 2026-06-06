<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import { Button, DatePicker, Input, Modal, Popconfirm, Select, Switch, message } from 'ant-design-vue';

import {
  type CalendarSlotItem,
  type ContentPlanItem,
  type PartCode,
  type PlacementResult,
  type PlanStatus,
  type RegionCode,
  type StationItem,
  bulkDeletePlans,
  bulkMovePlans,
  getPlanning,
  getPlanRange,
  getPlanSuggestions,
  getStations,
  REGION_LABELS,
  REGION_LIST,
  savePlanning,
  updatePlanning,
} from '#/api/modules/radioMedia';
import VirtualList from '#/components/ui/VirtualList.vue';
import { extractApiError } from '#/utils/api-error';

const SLOTS = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];

const loading = ref(false);
const selectedDate = ref<Dayjs>(dayjs());
const regionFilter = ref<RegionCode | undefined>();
const calendar = ref<CalendarSlotItem[]>([]);
const stations = ref<StationItem[]>([]);

const regionOptions = REGION_LIST.map((r) => ({ label: REGION_LABELS[r], value: r }));
const partOptions: Array<{ label: string; value: PartCode }> = [
  { label: 'Haber', value: 'news' },
  { label: 'Spor', value: 'sports' },
  { label: 'Ekonomi', value: 'economy' },
  { label: 'Hava Durumu', value: 'weather' },
];
const statusOptions: Array<{ label: string; value: PlanStatus }> = [
  { label: 'Taslak', value: 'draft' },
  { label: 'Yayında', value: 'published' },
  { label: 'Canlı', value: 'running' },
  { label: 'Bekliyor', value: 'paused' },
  { label: 'Arşiv', value: 'archived' },
];

function statusLabel(s: PlanStatus) {
  return statusOptions.find((o) => o.value === s)?.label ?? s;
}
function statusTone(s: PlanStatus) {
  if (s === 'published' || s === 'running') return 'ok';
  if (s === 'draft' || s === 'paused') return 'warn';
  return 'muted';
}
function slotTone(status: string) {
  return status === 'success' ? 'ok' : status === 'warning' ? 'warn' : 'bad';
}

const slotsView = computed(() =>
  SLOTS.map((time) => calendar.value.find((c) => c.slot_time === time) ?? { slot_time: time, status: 'danger' as const, items: [] as ContentPlanItem[] }),
);
const totalPlans = computed(() => calendar.value.reduce((n, s) => n + s.items.length, 0));

async function load() {
  loading.value = true;
  try {
    const res = await getPlanning({
      date: selectedDate.value.format('YYYY-MM-DD'),
      region: regionFilter.value,
    });
    calendar.value = res?.calendar ?? [];
  } catch (error) {
    console.error(error);
    message.error('Takvim verileri alınamadı.');
    calendar.value = [];
  } finally {
    loading.value = false;
  }
}
async function loadStations() {
  try {
    stations.value = await getStations();
  } catch {
    stations.value = [];
  }
}

/* ---- Faz 5: calendar views (day / week / month / list) ---- */
type ViewMode = 'day' | 'week' | 'month' | 'list';
const viewMode = ref<ViewMode>('day');
const viewOptions: Array<{ key: ViewMode; label: string }> = [
  { key: 'day', label: 'Günlük' },
  { key: 'week', label: 'Haftalık' },
  { key: 'month', label: 'Aylık' },
  { key: 'list', label: 'Liste' },
];
const rangePlans = ref<ContentPlanItem[]>([]);
const rangeCounts = ref<Record<string, number>>({});

// The [start, end] window the current non-day view needs.
const rangeWindow = computed<{ start: Dayjs; end: Dayjs }>(() => {
  if (viewMode.value === 'week') {
    const start = selectedDate.value.startOf('week');
    return { start, end: start.add(6, 'day') };
  }
  if (viewMode.value === 'month') {
    const start = selectedDate.value.startOf('month').startOf('week');
    const monthEnd = selectedDate.value.endOf('month').endOf('week');
    return { start, end: monthEnd };
  }
  // list → the current month
  return { start: selectedDate.value.startOf('month'), end: selectedDate.value.endOf('month') };
});

async function loadRange() {
  const { start, end } = rangeWindow.value;
  try {
    const res = await getPlanRange({
      start: start.format('YYYY-MM-DD'),
      end: end.format('YYYY-MM-DD'),
      region: regionFilter.value,
    });
    rangePlans.value = res?.plans ?? [];
    rangeCounts.value = res?.counts ?? {};
  } catch (error) {
    console.error(error);
    rangePlans.value = [];
    rangeCounts.value = {};
  }
}

async function reload() {
  if (viewMode.value === 'day') await load();
  else await loadRange();
}

function setView(mode: ViewMode) {
  viewMode.value = mode;
  selectMode.value = false;
  selected.value = new Set();
  void reload();
}

const WEEKDAYS = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

// Week view: 7 day columns each holding its plans (sorted by slot).
const weekDays = computed(() => {
  const { start } = rangeWindow.value;
  return Array.from({ length: 7 }, (_, i) => {
    const d = start.add(i, 'day');
    const key = d.format('YYYY-MM-DD');
    const items = rangePlans.value
      .filter((p) => (p.plan_date ?? '').slice(0, 10) === key)
      .sort((a, b) => a.slot_time.localeCompare(b.slot_time));
    return { date: d, key, label: WEEKDAYS[i], items, isToday: d.isSame(dayjs(), 'day') };
  });
});

// Month view: a grid of week-rows × 7 day cells with plan counts.
const monthWeeks = computed(() => {
  const { start, end } = rangeWindow.value;
  const weeks: Array<Array<{ date: Dayjs; key: string; count: number; inMonth: boolean; isToday: boolean }>> = [];
  let cursor = start;
  while (cursor.isBefore(end) || cursor.isSame(end, 'day')) {
    const week = Array.from({ length: 7 }, (_, i) => {
      const d = cursor.add(i, 'day');
      const key = d.format('YYYY-MM-DD');
      return {
        date: d,
        key,
        count: rangeCounts.value[key] ?? 0,
        inMonth: d.isSame(selectedDate.value, 'month'),
        isToday: d.isSame(dayjs(), 'day'),
      };
    });
    weeks.push(week);
    cursor = cursor.add(7, 'day');
  }
  return weeks;
});

// List view: a flat, date-sorted list.
const listPlans = computed(() =>
  [...rangePlans.value].sort(
    (a, b) =>
      (a.plan_date ?? '').localeCompare(b.plan_date ?? '') ||
      a.slot_time.localeCompare(b.slot_time),
  ),
);

function heatLevel(count: number): number {
  if (count === 0) return 0;
  if (count <= 2) return 1;
  if (count <= 5) return 2;
  if (count <= 9) return 3;
  return 4;
}

function jumpToDay(d: Dayjs) {
  selectedDate.value = d;
  setView('day');
}

/* ---- Plan modal ---- */
const modalOpen = ref(false);
const saving = ref(false);
const editingId = ref<string | null>(null);
const form = ref<{
  region_code: RegionCode;
  allRegions: boolean;
  part_code: PartCode;
  slot_time: string;
  content_title: string;
  status: PlanStatus;
  station_id?: string;
}>({
  region_code: 'akdeniz',
  allRegions: false,
  part_code: 'news',
  slot_time: '08:00',
  content_title: '',
  status: 'published',
  station_id: undefined,
});

const regionStations = computed(() =>
  stations.value
    .filter((s) => s.region_code === form.value.region_code)
    .map((s) => ({ label: `${s.city_name || s.name} • ${s.name}`, value: s.id })),
);

function openCreate(slot?: string) {
  editingId.value = null;
  form.value = {
    region_code: regionFilter.value ?? 'akdeniz',
    allRegions: false,
    part_code: 'news',
    slot_time: slot ?? '08:00',
    content_title: '',
    status: 'published',
    station_id: undefined,
  };
  modalOpen.value = true;
}
function openEdit(plan: ContentPlanItem) {
  editingId.value = plan.id;
  form.value = {
    region_code: plan.region_code,
    allRegions: false,
    part_code: plan.part_code,
    slot_time: plan.slot_time.slice(0, 5),
    content_title: plan.content_title,
    status: plan.status,
    station_id: undefined,
  };
  modalOpen.value = true;
}

async function submit() {
  if (!form.value.content_title.trim()) {
    message.warning('İçerik başlığı gerekli.');
    return;
  }
  saving.value = true;
  try {
    const base = {
      part_code: form.value.part_code,
      slot_time: form.value.slot_time,
      plan_date: selectedDate.value.format('YYYY-MM-DD'),
      content_title: form.value.content_title.trim(),
      content_kind: form.value.part_code,
      status: form.value.status,
      station_id: form.value.station_id || null,
      created_by: 'admin',
    };

    if (editingId.value) {
      await updatePlanning(editingId.value, { ...base, id: editingId.value, region_id: form.value.region_code, target_regions: [form.value.region_code] });
      message.success('Plan güncellendi.');
    } else if (form.value.allRegions) {
      const regions = [...REGION_LIST];
      let created = 0;
      const conflicts: string[] = [];
      for (const region of regions) {
        try {
          await savePlanning({ ...base, region_id: region, target_regions: regions });
          created += 1;
        } catch (error) {
          const msg = extractApiError(error) ?? '';
          if (/çakış|conflict/i.test(msg)) conflicts.push(REGION_LABELS[region]);
          else throw error;
        }
      }
      if (created > 0) message.success(`${created} bölgede plan oluşturuldu.`);
      if (conflicts.length) message.warning(`Çakışan bölgeler atlandı: ${conflicts.join(', ')}`);
    } else {
      await savePlanning({ ...base, region_id: form.value.region_code, target_regions: [form.value.region_code] });
      message.success('Plan oluşturuldu.');
    }
    modalOpen.value = false;
    await reload();
  } catch (error) {
    console.error(error);
    message.error(extractApiError(error) ?? 'Plan kaydedilemedi.');
  } finally {
    saving.value = false;
  }
}

/* ---- Faz 4: multi-select + bulk operations ---- */
const selectMode = ref(false);
const selected = ref<Set<string>>(new Set());
const busy = ref(false);

const selectedCount = computed(() => selected.value.size);

function toggleSelectMode() {
  selectMode.value = !selectMode.value;
  if (!selectMode.value) selected.value = new Set();
}
function isSelected(id: string) {
  return selected.value.has(id);
}
function toggleSelect(id: string) {
  const next = new Set(selected.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  selected.value = next;
}
function onItemClick(item: ContentPlanItem) {
  if (selectMode.value) toggleSelect(item.id);
  else openEdit(item);
}
function clearSelection() {
  selected.value = new Set();
}

async function runBulkMove(shift: number, copy = false, targetDate?: string) {
  if (!selected.value.size) return;
  busy.value = true;
  try {
    const res = await bulkMovePlans({
      ids: [...selected.value],
      slot_shift: shift,
      copy,
      target_date: targetDate,
    });
    const r = res?.result;
    const verb = copy ? 'kopyalandı' : 'taşındı';
    message.success(`${r?.written ?? 0} plan ${verb}${r?.skipped ? `, ${r.skipped} çakışma atlandı` : ''}.`);
    clearSelection();
    await reload();
  } catch (error) {
    message.error(extractApiError(error) ?? 'İşlem başarısız.');
  } finally {
    busy.value = false;
  }
}
function bulkShift(shift: number) {
  return runBulkMove(shift, false);
}
function bulkCopyNextDay() {
  return runBulkMove(0, true, selectedDate.value.add(1, 'day').format('YYYY-MM-DD'));
}
async function runBulkDelete() {
  if (!selected.value.size) return;
  busy.value = true;
  try {
    const res = await bulkDeletePlans([...selected.value]);
    message.success(`${res?.result?.deleted ?? 0} plan silindi.`);
    clearSelection();
    await reload();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Silme başarısız.');
  } finally {
    busy.value = false;
  }
}

/* ---- Faz 4: smart placement suggestions ---- */
const suggestOpen = ref(false);
const suggestLoading = ref(false);
const suggestResult = ref<PlacementResult | null>(null);

async function openSuggestions() {
  suggestOpen.value = true;
  suggestLoading.value = true;
  suggestResult.value = null;
  try {
    const res = await getPlanSuggestions({
      date: selectedDate.value.format('YYYY-MM-DD'),
      region: regionFilter.value,
    });
    suggestResult.value = res?.result ?? { suggestions: [], warnings: [] };
  } catch (error) {
    message.error(extractApiError(error) ?? 'Öneriler alınamadı.');
    suggestResult.value = { suggestions: [], warnings: [] };
  } finally {
    suggestLoading.value = false;
  }
}

async function applySuggestion(s: PlacementResult['suggestions'][number]) {
  const region = regionFilter.value ?? 'akdeniz';
  try {
    await savePlanning({
      region_id: region,
      target_regions: [region],
      part_code: s.part_code as PartCode,
      slot_time: s.slot_time,
      plan_date: selectedDate.value.format('YYYY-MM-DD'),
      content_title: s.content_title,
      content_kind: s.part_code as PartCode,
      status: 'published',
      created_by: 'admin',
    });
    message.success(`${s.slot_time} ${s.content_title} eklendi.`);
    if (suggestResult.value) {
      suggestResult.value.suggestions = suggestResult.value.suggestions.filter((x) => x !== s);
    }
    await reload();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Öneri uygulanamadı.');
  }
}

onMounted(() => {
  void load();
  void loadStations();
});
</script>

<template>
  <div class="pln">
    <header class="pln__bar">
      <div>
        <h1 class="pln__title">Planlama</h1>
        <p class="pln__sub">{{ totalPlans }} plan · {{ selectedDate.format('DD MMMM') }}</p>
      </div>
      <div class="pln__actions">
        <Button @click="openSuggestions">💡 Akıllı Öneriler</Button>
        <Button :type="selectMode ? 'default' : 'dashed'" @click="toggleSelectMode">
          {{ selectMode ? 'Seçimi Kapat' : 'Çoklu Seç' }}
        </Button>
        <Button type="primary" @click="openCreate()">+ Yeni Plan</Button>
      </div>
    </header>

    <!-- Bulk operations toolbar (multi-select) -->
    <div v-if="selectMode" class="pln__bulkbar ui-card">
      <span class="pln__bulkcount">{{ selectedCount }} seçili</span>
      <div class="pln__bulkacts">
        <Button size="small" :disabled="!selectedCount || busy" @click="bulkShift(-1)">◄ Önceki kuşak</Button>
        <Button size="small" :disabled="!selectedCount || busy" @click="bulkShift(1)">Sonraki kuşak ►</Button>
        <Button size="small" :disabled="!selectedCount || busy" @click="bulkCopyNextDay">⧉ Ertesi güne kopyala</Button>
        <Popconfirm title="Seçili planlar silinsin mi?" ok-text="Sil" cancel-text="Vazgeç" @confirm="runBulkDelete">
          <Button size="small" danger :disabled="!selectedCount || busy">🗑 Sil</Button>
        </Popconfirm>
        <Button v-if="selectedCount" size="small" type="text" @click="clearSelection">Temizle</Button>
      </div>
    </div>

    <div class="pln__filters ui-card">
      <DatePicker v-model:value="selectedDate" class="pln__date" :allow-clear="false" @change="reload" />
      <Select v-model:value="regionFilter" allow-clear placeholder="Tüm bölgeler" :options="regionOptions" class="pln__f" @change="reload" />
      <div class="pln__views">
        <button
          v-for="opt in viewOptions"
          :key="opt.key"
          type="button"
          class="pln__view"
          :class="{ 'is-active': viewMode === opt.key }"
          @click="setView(opt.key)"
        >
          {{ opt.label }}
        </button>
      </div>
    </div>

    <!-- DAILY -->
    <div v-if="viewMode === 'day'" class="pln__grid">
      <article v-for="slot in slotsView" :key="slot.slot_time" class="pln__slot ui-card">
        <div class="pln__slot-head">
          <span class="pln__slot-time">{{ slot.slot_time }}</span>
          <span class="pln__chip" :class="`is-${slotTone(slot.status)}`">
            {{ slot.items.length ? `${slot.items.length} plan` : 'boş' }}
          </span>
        </div>
        <ul v-if="slot.items.length" class="pln__items">
          <li
            v-for="item in slot.items"
            :key="item.id"
            class="pln__item"
            :class="{ 'is-selected': selectMode && isSelected(item.id) }"
            @click="onItemClick(item)"
          >
            <span v-if="selectMode" class="pln__check" :class="{ 'is-on': isSelected(item.id) }">
              {{ isSelected(item.id) ? '✓' : '' }}
            </span>
            <div class="pln__item-main">
              <strong>{{ item.content_title }}</strong>
              <span>{{ item.region_name }}<template v-if="item.station_name"> · {{ item.station_name }}</template></span>
            </div>
            <span class="pln__chip" :class="`is-${statusTone(item.status)}`">{{ statusLabel(item.status) }}</span>
          </li>
        </ul>
        <button v-else class="pln__add-slot" type="button" @click="openCreate(slot.slot_time)">+ Plan ekle</button>
      </article>
    </div>

    <!-- WEEKLY -->
    <div v-else-if="viewMode === 'week'" class="pln__week">
      <article
        v-for="day in weekDays"
        :key="day.key"
        class="pln__wday ui-card"
        :class="{ 'is-today': day.isToday }"
      >
        <header class="pln__wday-head" @click="jumpToDay(day.date)">
          <span class="pln__wday-name">{{ day.label }}</span>
          <span class="pln__wday-num">{{ day.date.format('DD MMM') }}</span>
          <span class="pln__chip is-muted">{{ day.items.length }}</span>
        </header>
        <ul v-if="day.items.length" class="pln__wday-items">
          <li
            v-for="item in day.items"
            :key="item.id"
            class="pln__wchip"
            :title="`${item.slot_time.slice(0, 5)} · ${item.content_title}`"
            @click="openEdit(item)"
          >
            <b>{{ item.slot_time.slice(0, 5) }}</b> {{ item.content_title }}
          </li>
        </ul>
        <p v-else class="pln__wday-empty">—</p>
      </article>
    </div>

    <!-- MONTHLY -->
    <div v-else-if="viewMode === 'month'" class="pln__month ui-card">
      <div class="pln__mhead">
        <span v-for="d in WEEKDAYS" :key="d">{{ d }}</span>
      </div>
      <div v-for="(week, wi) in monthWeeks" :key="wi" class="pln__mrow">
        <button
          v-for="cell in week"
          :key="cell.key"
          type="button"
          class="pln__mcell"
          :class="[
            `heat-${heatLevel(cell.count)}`,
            { 'is-out': !cell.inMonth, 'is-today': cell.isToday },
          ]"
          @click="jumpToDay(cell.date)"
        >
          <span class="pln__mnum">{{ cell.date.format('D') }}</span>
          <span v-if="cell.count" class="pln__mcount">{{ cell.count }}</span>
        </button>
      </div>
      <div class="pln__legend">
        <span>Az</span>
        <i class="heat-1" /><i class="heat-2" /><i class="heat-3" /><i class="heat-4" />
        <span>Çok</span>
      </div>
    </div>

    <!-- LIST (virtualized for 1000+ rows) -->
    <div v-else class="pln__list ui-card">
      <VirtualList
        v-if="listPlans.length"
        :items="listPlans"
        :row-height="48"
        :height="560"
        key-field="id"
      >
        <template #default="{ item }">
          <button type="button" class="pln__lrow" @click="openEdit(item)">
            <span class="pln__ldate">{{ dayjs(item.plan_date).format('DD MMM') }}</span>
            <span class="pln__lslot">{{ item.slot_time.slice(0, 5) }}</span>
            <span class="pln__ltitle">{{ item.content_title }}</span>
            <span class="pln__lregion">{{ item.region_name }}</span>
            <span class="pln__chip" :class="`is-${statusTone(item.status)}`">{{ statusLabel(item.status) }}</span>
          </button>
        </template>
      </VirtualList>
      <p v-else class="pln__wday-empty">Bu dönem için plan yok.</p>
    </div>

    <!-- Plan modal -->
    <Modal
      v-model:open="modalOpen"
      :title="editingId ? 'Planı Düzenle' : 'Yeni Plan'"
      :confirm-loading="saving"
      ok-text="Kaydet"
      cancel-text="Vazgeç"
      @ok="submit"
    >
      <div class="pln__form">
        <label>
          <span>İçerik Başlığı</span>
          <Input v-model:value="form.content_title" placeholder="Örn. Sabah Haber Bülteni" />
        </label>
        <div class="pln__form-grid">
          <label>
            <span>Saat</span>
            <Select v-model:value="form.slot_time" :options="SLOTS.map((s) => ({ label: s, value: s }))" />
          </label>
          <label>
            <span>İçerik Türü</span>
            <Select v-model:value="form.part_code" :options="partOptions" />
          </label>
        </div>
        <label v-if="!form.allRegions">
          <span>Bölge</span>
          <Select v-model:value="form.region_code" :options="regionOptions" />
        </label>
        <label v-if="!form.allRegions && regionStations.length">
          <span>İstasyon (opsiyonel)</span>
          <Select v-model:value="form.station_id" allow-clear placeholder="Bölge geneli" :options="regionStations" />
        </label>
        <div class="pln__form-grid">
          <label>
            <span>Durum</span>
            <Select v-model:value="form.status" :options="statusOptions" />
          </label>
          <label v-if="!editingId" class="pln__form-row">
            <span>Tüm Bölgeler</span>
            <Switch v-model:checked="form.allRegions" />
          </label>
        </div>
      </div>
    </Modal>

    <!-- Smart placement suggestions -->
    <Modal
      v-model:open="suggestOpen"
      title="💡 Akıllı Yerleştirme Önerileri"
      :footer="null"
      width="560px"
    >
      <div class="pln__sg">
        <p class="pln__sg-meta">
          {{ selectedDate.format('DD MMMM YYYY') }}
          · {{ regionFilter ? REGION_LABELS[regionFilter] : 'Tüm bölgeler' }}
        </p>

        <div v-if="suggestLoading" class="pln__sg-empty">Analiz ediliyor…</div>

        <template v-else-if="suggestResult">
          <div v-if="suggestResult.warnings.length" class="pln__sg-warns">
            <div v-for="(w, i) in suggestResult.warnings" :key="`w${i}`" class="pln__sg-warn">
              ⚠ {{ w.message }}
            </div>
          </div>

          <ul v-if="suggestResult.suggestions.length" class="pln__sg-list">
            <li v-for="(s, i) in suggestResult.suggestions" :key="`s${i}`" class="pln__sg-item">
              <div class="pln__sg-info">
                <strong>{{ s.slot_time }} · {{ s.content_title }}</strong>
                <span>{{ s.reason }}</span>
              </div>
              <Button size="small" type="primary" @click="applySuggestion(s)">Ekle</Button>
            </li>
          </ul>
          <div v-else-if="!suggestResult.warnings.length" class="pln__sg-empty">
            ✓ Bu gün için öneri yok — yayın akışı dengeli görünüyor.
          </div>
        </template>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.pln {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.pln__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
}
.pln__title {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.pln__sub {
  margin: 2px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.pln__filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--sp-3);
  padding: var(--sp-3);
}
.pln__date {
  flex: 1 1 180px;
}
.pln__f {
  flex: 0 1 200px;
  min-width: 160px;
}

.pln__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--sp-3);
}
.pln__slot {
  padding: var(--sp-4);
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.pln__slot-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.pln__slot-time {
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.pln__items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}
.pln__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  padding: 10px 12px;
  border-radius: var(--r-sm);
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
  cursor: pointer;
  transition: border-color 150ms ease;
}
.pln__item:hover {
  border-color: var(--c-line-strong);
}
.pln__item-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.pln__item-main strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pln__item-main span {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.pln__add-slot {
  border: 1px dashed var(--c-line-strong);
  background: transparent;
  border-radius: var(--r-sm);
  padding: 12px;
  color: var(--c-text-3);
  font-size: var(--t-sm);
  font-weight: 600;
  cursor: pointer;
  transition: color 150ms ease, border-color 150ms ease;
}
.pln__add-slot:hover {
  color: var(--c-info);
  border-color: var(--c-info);
}

.pln__chip {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 700;
}
.pln__chip.is-ok {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
}
.pln__chip.is-warn {
  color: var(--c-warn);
  background: rgba(251, 191, 36, 0.12);
}
.pln__chip.is-bad,
.pln__chip.is-muted {
  color: var(--c-text-3);
  background: rgba(148, 163, 184, 0.1);
}

.pln__actions {
  display: flex;
  gap: var(--sp-2);
  flex-wrap: wrap;
}

/* View-mode segmented control */
.pln__views {
  display: inline-flex;
  margin-left: auto;
  padding: 3px;
  gap: 2px;
  border-radius: var(--r-sm);
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
}
.pln__view {
  border: none;
  background: transparent;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text-3);
  cursor: pointer;
  transition: background 150ms ease, color 150ms ease;
}
.pln__view.is-active {
  background: var(--c-brand);
  color: #fff;
}

/* Weekly view */
.pln__week {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--sp-3);
}
.pln__wday {
  padding: var(--sp-3);
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}
.pln__wday.is-today {
  border-color: var(--c-brand);
}
.pln__wday-head {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}
.pln__wday-name {
  font-weight: 800;
  color: var(--c-text);
}
.pln__wday-num {
  font-size: var(--t-xs);
  color: var(--c-text-3);
  margin-right: auto;
}
.pln__wday-items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.pln__wchip {
  font-size: var(--t-xs);
  padding: 5px 8px;
  border-radius: 7px;
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
  color: var(--c-text-2);
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pln__wchip b {
  color: var(--c-info);
}
.pln__wchip:hover {
  border-color: var(--c-line-strong);
}
.pln__wday-empty {
  margin: 0;
  color: var(--c-text-3);
  text-align: center;
  font-size: var(--t-sm);
}

/* Monthly heatmap */
.pln__month {
  padding: var(--sp-3);
}
.pln__mhead,
.pln__mrow {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
.pln__mhead {
  margin-bottom: 6px;
}
.pln__mhead span {
  text-align: center;
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-3);
}
.pln__mrow {
  margin-bottom: 4px;
}
.pln__mcell {
  position: relative;
  aspect-ratio: 1 / 1;
  border: 1px solid var(--c-line);
  border-radius: 8px;
  background: var(--c-surface-2);
  cursor: pointer;
  display: flex;
  align-items: flex-start;
  justify-content: flex-start;
  padding: 5px;
  transition: transform 120ms ease;
}
.pln__mcell:hover {
  transform: scale(1.04);
  border-color: var(--c-line-strong);
}
.pln__mcell.is-out {
  opacity: 0.38;
}
.pln__mcell.is-today {
  border-color: var(--c-brand);
}
.pln__mnum {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-2);
}
.pln__mcount {
  position: absolute;
  right: 5px;
  bottom: 4px;
  font-size: 10px;
  font-weight: 800;
  color: var(--c-text);
}
.pln__mcell.heat-1 {
  background: rgba(96, 165, 250, 0.14);
}
.pln__mcell.heat-2 {
  background: rgba(96, 165, 250, 0.26);
}
.pln__mcell.heat-3 {
  background: rgba(225, 29, 72, 0.28);
}
.pln__mcell.heat-4 {
  background: rgba(225, 29, 72, 0.46);
}
.pln__legend {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: var(--sp-3);
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.pln__legend i {
  width: 16px;
  height: 12px;
  border-radius: 3px;
}
.pln__legend .heat-1 {
  background: rgba(96, 165, 250, 0.14);
}
.pln__legend .heat-2 {
  background: rgba(96, 165, 250, 0.26);
}
.pln__legend .heat-3 {
  background: rgba(225, 29, 72, 0.28);
}
.pln__legend .heat-4 {
  background: rgba(225, 29, 72, 0.46);
}

/* List view */
.pln__list {
  padding: var(--sp-2);
}
.pln__list-rows {
  display: flex;
  flex-direction: column;
}
.pln__lrow {
  display: grid;
  grid-template-columns: 64px 50px 1fr auto auto;
  align-items: center;
  gap: var(--sp-3);
  width: 100%;
  height: 100%;
  text-align: left;
  border: none;
  background: transparent;
  border-bottom: 1px solid var(--c-line);
  padding: 0 10px;
  cursor: pointer;
}
.pln__lrow:hover {
  background: var(--c-surface-2);
}
.pln__ldate {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-3);
}
.pln__lslot {
  font-size: var(--t-sm);
  font-weight: 800;
  color: var(--c-info);
  font-variant-numeric: tabular-nums;
}
.pln__ltitle {
  font-size: var(--t-sm);
  font-weight: 600;
  color: var(--c-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pln__lregion {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

/* Bulk toolbar */
.pln__bulkbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  padding: 10px var(--sp-3);
  flex-wrap: wrap;
}
.pln__bulkcount {
  font-size: var(--t-sm);
  font-weight: 800;
  color: var(--c-info);
}
.pln__bulkacts {
  display: flex;
  gap: var(--sp-2);
  flex-wrap: wrap;
}

/* Selection checkbox on item */
.pln__check {
  flex: 0 0 18px;
  width: 18px;
  height: 18px;
  border-radius: 5px;
  border: 1.5px solid var(--c-line-strong);
  display: grid;
  place-items: center;
  font-size: 12px;
  font-weight: 900;
  color: #fff;
  margin-right: 4px;
}
.pln__check.is-on {
  background: var(--c-brand);
  border-color: var(--c-brand);
}
.pln__item.is-selected {
  border-color: var(--c-brand);
  background: rgba(225, 29, 72, 0.08);
}

/* Suggestions modal */
.pln__sg {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.pln__sg-meta {
  margin: 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.pln__sg-warns {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.pln__sg-warn {
  padding: 8px 12px;
  border-radius: var(--r-sm);
  background: rgba(251, 191, 36, 0.1);
  border: 1px solid rgba(251, 191, 36, 0.28);
  color: var(--c-warn);
  font-size: var(--t-sm);
}
.pln__sg-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}
.pln__sg-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  padding: 10px 12px;
  border-radius: var(--r-sm);
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
}
.pln__sg-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.pln__sg-info strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
}
.pln__sg-info span {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.pln__sg-empty {
  padding: 18px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

.pln__form {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
  padding-top: var(--sp-2);
}
.pln__form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--sp-3);
}
.pln__form label {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.pln__form label span {
  font-size: var(--t-xs);
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--c-text-2);
}
.pln__form-row {
  flex-direction: row !important;
  align-items: center;
  justify-content: space-between;
}

@media (min-width: 768px) {
  .pln__grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .pln__week {
    grid-template-columns: repeat(7, 1fr);
    align-items: start;
  }
}
@media (min-width: 1280px) {
  .pln__grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
</style>
