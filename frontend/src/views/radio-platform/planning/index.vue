<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import { Button, DatePicker, Input, Modal, Select, Switch, message } from 'ant-design-vue';

import {
  type CalendarSlotItem,
  type ContentPlanItem,
  type PartCode,
  type PlanStatus,
  type RegionCode,
  type StationItem,
  getPlanning,
  getStations,
  REGION_LABELS,
  REGION_LIST,
  savePlanning,
  updatePlanning,
} from '#/api/modules/radioMedia';
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
    await load();
  } catch (error) {
    console.error(error);
    message.error(extractApiError(error) ?? 'Plan kaydedilemedi.');
  } finally {
    saving.value = false;
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
      <Button type="primary" @click="openCreate()">+ Yeni Plan</Button>
    </header>

    <div class="pln__filters ui-card">
      <DatePicker v-model:value="selectedDate" class="pln__date" :allow-clear="false" @change="load" />
      <Select v-model:value="regionFilter" allow-clear placeholder="Tüm bölgeler" :options="regionOptions" class="pln__f" @change="load" />
    </div>

    <div class="pln__grid">
      <article v-for="slot in slotsView" :key="slot.slot_time" class="pln__slot ui-card">
        <div class="pln__slot-head">
          <span class="pln__slot-time">{{ slot.slot_time }}</span>
          <span class="pln__chip" :class="`is-${slotTone(slot.status)}`">
            {{ slot.items.length ? `${slot.items.length} plan` : 'boş' }}
          </span>
        </div>
        <ul v-if="slot.items.length" class="pln__items">
          <li v-for="item in slot.items" :key="item.id" class="pln__item" @click="openEdit(item)">
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
}
@media (min-width: 1280px) {
  .pln__grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
</style>
