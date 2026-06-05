<script lang="ts" setup>
import { computed, h, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import { Page } from '@vben/common-ui';

import {
  Button,
  Card,
  DatePicker,
  Input,
  Select,
  Switch,
  Tag,
  Table,
  message,
} from 'ant-design-vue';
import type { ColumnsType } from 'ant-design-vue/es/table';

import {
  type ContentPlanItem,
  type PlanStatus,
  type PartCode,
  type RegionCode,
  type StationItem,
  PART_LABELS,
  PART_LIST,
  REGION_LABELS,
  REGION_LIST,
  getPlanning,
  getStations,
  savePlanning,
  updatePlanning,
} from '#/api/modules/radioMedia';

const slotTimes = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
const statusOptions: Array<{ label: string; value: PlanStatus }> = [
  { label: 'Taslak', value: 'draft' },
  { label: 'Yayında', value: 'published' },
  { label: 'Canlı', value: 'running' },
  { label: 'Bekliyor', value: 'paused' },
  { label: 'Arşiv', value: 'archived' },
];

const regionOptions = REGION_LIST.map((region) => ({
  label: REGION_LABELS[region],
  value: region,
}));

const partOptions = PART_LIST.map((part) => ({
  label: PART_LABELS[part],
  value: part,
}));

const loading = ref(false);
const saving = ref(false);
const plans = ref<ContentPlanItem[]>([]);
const calendar = ref<Array<{ slot_time: string; status: 'success' | 'warning' | 'danger'; items: ContentPlanItem[] }>>([]);
const stations = ref<StationItem[]>([]);
const editingPlanId = ref<string | null>(null);
const selectedDate = ref<Dayjs>(dayjs());
const selectedRegion = ref<RegionCode>('akdeniz');
const selectedStatus = ref<PlanStatus | undefined>(undefined);

const formState = ref<{
  region_id: RegionCode;
  station_id?: string;
  part_code: PartCode;
  slot_time: string;
  plan_date: string;
  content_title: string;
  content_kind: PartCode;
  status: PlanStatus;
  is_global: boolean;
  notes: string;
}>({
  region_id: 'akdeniz',
  station_id: undefined,
  part_code: 'news',
  slot_time: '08:00',
  plan_date: dayjs().format('YYYY-MM-DD'),
  content_title: 'Sabah Haber Bülteni',
  content_kind: 'news',
  status: 'draft',
  is_global: false,
  notes: '',
});

const columns: ColumnsType<ContentPlanItem> = [
  { title: 'Saat', dataIndex: 'slot_time', key: 'slot_time', width: 90 },
  { title: 'Bölge', dataIndex: 'region_name', key: 'region_name', width: 140 },
  { title: 'İstasyon', dataIndex: 'station_name', key: 'station_name', width: 150 },
  { title: 'Başlık', dataIndex: 'content_title', key: 'content_title', ellipsis: true },
  {
    title: 'Tür',
    dataIndex: 'part_code',
    key: 'part_code',
    width: 110,
    customRender: ({ text }) => PART_LABELS[text as PartCode],
  },
  {
    title: 'Durum',
    dataIndex: 'status',
    key: 'status',
    width: 120,
    customRender: ({ text }) => h(Tag, { color: planStatusColor(text as PlanStatus) }, () => statusText(text as PlanStatus)),
  },
];

function statusText(status: PlanStatus) {
  return {
    draft: 'Taslak',
    published: 'Yayında',
    running: 'Canlı',
    paused: 'Bekliyor',
    archived: 'Arşiv',
  }[status];
}

function planStatusColor(status: PlanStatus) {
  return {
    draft: 'gold',
    published: 'green',
    running: 'blue',
    paused: 'orange',
    archived: 'default',
  }[status];
}

const regionStations = computed(() =>
  stations.value.filter((station) => station.region_code === selectedRegion.value),
);

const planStats = computed(() => ({
  total: plans.value.length,
  live: plans.value.filter((plan) => plan.status === 'running').length,
  published: plans.value.filter((plan) => plan.status === 'published').length,
  draft: plans.value.filter((plan) => plan.status === 'draft').length,
}));

async function loadPlanning() {
  loading.value = true;
  try {
    const response = await getPlanning({
      date: selectedDate.value.format('YYYY-MM-DD'),
      region: selectedRegion.value,
      status: selectedStatus.value,
    });
    plans.value = response.plans;
    calendar.value = response.calendar;
  } catch (error) {
    console.error(error);
    message.error('Takvim verileri alınamadı.');
  } finally {
    loading.value = false;
  }
}

async function loadStations() {
  try {
    stations.value = await getStations({ region: selectedRegion.value });
  } catch (error) {
    console.error(error);
    message.error('İstasyon listesi alınamadı.');
  }
}

async function handleRegionChange(region: RegionCode) {
  selectedRegion.value = region;
  formState.value.region_id = region;
  await Promise.all([loadPlanning(), loadStations()]);
}

function resetForm() {
  editingPlanId.value = null;
  formState.value = {
    region_id: selectedRegion.value,
      station_id: undefined,
    part_code: 'news',
    slot_time: '08:00',
    plan_date: selectedDate.value.format('YYYY-MM-DD'),
    content_title: 'Yeni içerik',
    content_kind: 'news',
    status: 'draft',
    is_global: false,
    notes: '',
  };
}

function fillPlan(plan: ContentPlanItem) {
  editingPlanId.value = plan.id;
  formState.value = {
    region_id: plan.region_code,
      station_id: undefined,
    part_code: plan.part_code,
    slot_time: plan.slot_time,
    plan_date: plan.plan_date,
    content_title: plan.content_title,
    content_kind: plan.content_kind,
    status: plan.status,
    is_global: plan.is_global,
    notes: plan.notes ?? '',
  };
}

async function submitPlan() {
  if (!formState.value.content_title.trim()) {
    message.warning('İçerik başlığı gerekli.');
    return;
  }

  saving.value = true;
  try {
    const base = {
      station_id: formState.value.station_id || null,
      part_code: formState.value.part_code,
      slot_time: formState.value.slot_time,
      plan_date: formState.value.plan_date,
      content_title: formState.value.content_title,
      content_kind: formState.value.content_kind,
      status: formState.value.status,
      is_global: formState.value.is_global,
      target_parts: [formState.value.part_code],
      notes: formState.value.notes ?? '',
      created_by: 'admin',
    };

    if (editingPlanId.value) {
      await updatePlanning(editingPlanId.value, {
        ...base,
        id: editingPlanId.value,
        region_id: formState.value.region_id,
        target_regions: [formState.value.region_id],
      });
      message.success('Plan güncellendi.');
    } else if (formState.value.is_global) {
      // Global plan: her bölge için ayrı bir plan satırı oluştur, çakışanları atla.
      const regions = [...REGION_LIST];
      let created = 0;
      const conflicts: string[] = [];
      for (const region of regions) {
        try {
          await savePlanning({ ...base, region_id: region, target_regions: regions });
          created += 1;
        } catch (error) {
          const msg = extractApiError(error) ?? '';
          if (/çakış|conflict/i.test(msg)) {
            conflicts.push(REGION_LABELS[region]);
          } else {
            throw error;
          }
        }
      }
      if (created > 0) {
        message.success(`${created} bölgede plan oluşturuldu.`);
      }
      if (conflicts.length > 0) {
        message.warning(`Çakışan bölgeler atlandı: ${conflicts.join(', ')}`);
      }
      if (created === 0 && conflicts.length === 0) {
        message.error('Plan oluşturulamadı.');
      }
    } else {
      await savePlanning({
        ...base,
        region_id: formState.value.region_id,
        target_regions: [formState.value.region_id],
      });
      message.success('Plan oluşturuldu.');
    }

    resetForm();
    await loadPlanning();
  } catch (error) {
    console.error(error);
    message.error(extractApiError(error) ?? 'Plan kaydedilemedi.');
  } finally {
    saving.value = false;
  }
}

/**
 * Backend hata gövdesinden anlamlı mesajı çıkarır (örn. 409 çakışma uyarısı).
 * Hem raw fetch (Error.message = JSON gövde) hem de axios benzeri istemciyi kapsar.
 */
function extractApiError(error: unknown): string | null {
  if (error instanceof Error && error.message) {
    try {
      const parsed = JSON.parse(error.message) as { error?: string; message?: string };
      if (parsed?.error || parsed?.message) {
        return String(parsed.error ?? parsed.message);
      }
    } catch {
      // mesaj JSON değil; aşağıdaki kontrollere düş
    }
  }

  const data = (error as { response?: { data?: { error?: string; message?: string } } }).response?.data;
  if (data?.error || data?.message) {
    return String(data.error ?? data.message);
  }

  return null;
}

onMounted(async () => {
  await Promise.all([loadPlanning(), loadStations()]);
});
</script>

<template>
  <Page
    title="Takvim ve İçerik Planlama"
    description="Saat kuşakları, bölgesel planlar ve canlı yayın akışı tek ekranda."
  >
    <div class="operations-page">
      <section class="hero-strip">
        <div class="hero-card">
          <span>Bugün</span>
          <strong>{{ planStats.total }}</strong>
        </div>
        <div class="hero-card is-success">
          <span>Canlı</span>
          <strong>{{ planStats.live }}</strong>
        </div>
        <div class="hero-card is-warning">
          <span>Yayında</span>
          <strong>{{ planStats.published }}</strong>
        </div>
        <div class="hero-card is-danger">
          <span>Taslak</span>
          <strong>{{ planStats.draft }}</strong>
        </div>
      </section>

      <section class="operations-grid">
        <Card :bordered="false" class="surface-card editor-card">
          <div class="card-head">
            <div>
              <p class="eyebrow">İçerik planlama</p>
              <h3>{{ editingPlanId ? 'Planı düzenle' : 'Yeni plan oluştur' }}</h3>
              <p>Takvim kuşağını, bölgeyi ve yayın durumunu tek düzenli formda yönetin.</p>
            </div>
            <Button @click="resetForm">Sıfırla</Button>
          </div>

          <div class="form-grid">
            <div class="form-field">
              <label>Bölge</label>
              <Select
                v-model:value="formState.region_id"
                :options="regionOptions"
                @change="(value) => handleRegionChange(value as RegionCode)"
              />
            </div>
            <div class="form-field">
              <label>İstasyon</label>
              <Select
                v-model:value="formState.station_id"
                :options="regionStations.map((station) => ({ label: `${station.city_name || station.name} • ${station.name}`, value: station.id }))"
                :allow-clear="true"
              />
            </div>
            <div class="form-field">
              <label>Tarih</label>
              <DatePicker v-model:value="selectedDate" class="w-full" @change="loadPlanning" />
            </div>
            <div class="form-field">
              <label>Saat</label>
              <Select v-model:value="formState.slot_time" :options="slotTimes.map((slot) => ({ label: slot, value: slot }))" />
            </div>
            <div class="form-field">
              <label>Başlık</label>
              <Input v-model:value="formState.content_title" placeholder="Sabah Haber Bülteni" />
            </div>
            <div class="form-field">
              <label>İçerik türü</label>
              <Select v-model:value="formState.content_kind" :options="partOptions" />
            </div>
            <div class="form-field">
              <label>Durum</label>
              <Select v-model:value="formState.status" :options="statusOptions" />
            </div>
            <div class="form-field switch-field">
              <label>Tüm bölgeler</label>
              <Switch v-model:checked="formState.is_global" />
            </div>
            <div class="form-field span-2">
              <label>Notlar</label>
                            <Input.TextArea
                :value="formState.notes"
                :rows="3"
                placeholder="Kısa plan notu"
                @update:value="(value) => (formState.notes = String(value ?? ''))"
              />
            </div>
          </div>

          <div class="action-row">
            <Button type="primary" :loading="saving" @click="submitPlan">
              {{ editingPlanId ? 'Planı güncelle' : 'Planı kaydet' }}
            </Button>
          </div>
        </Card>

        <Card :bordered="false" class="surface-card calendar-card">
          <div class="card-head">
            <div>
              <p class="eyebrow">Takvim akışı</p>
              <h3>{{ selectedDate.format('DD MMMM YYYY') }}</h3>
              <p>Bölge seçimine göre saat kuşaklarının canlı durum özeti.</p>
            </div>
            <div class="filters">
              <Select
                v-model:value="selectedRegion"
                :options="regionOptions"
                class="filter-select"
                @change="(value) => handleRegionChange(value as RegionCode)"
              />
              <Select v-model:value="selectedStatus" :options="[{ label: 'Hepsi', value: undefined }, ...statusOptions]" class="filter-select" allow-clear @change="loadPlanning" />
            </div>
          </div>

          <div class="slot-grid">
            <div v-for="slot in calendar" :key="slot.slot_time" class="slot-card" :class="slot.status">
              <div class="slot-top">
                <strong>{{ slot.slot_time }}</strong>
                <Tag :color="slot.status === 'success' ? 'green' : slot.status === 'warning' ? 'gold' : 'red'">
                  {{ slot.status === 'success' ? 'Hazır' : slot.status === 'warning' ? 'Bekliyor' : 'Kritik' }}
                </Tag>
              </div>
              <div v-if="slot.items.length">
                <div v-for="item in slot.items" :key="item.id" class="slot-item" @click="fillPlan(item)">
                  <strong>{{ item.content_title }}</strong>
                  <span>{{ item.region_name }} • {{ item.station_name || 'İstasyon yok' }}</span>
                </div>
              </div>
              <p v-else class="slot-empty">Bu saat için plan yok.</p>
            </div>
          </div>
        </Card>
      </section>

      <section class="table-panel">
        <Table
          :columns="columns"
          :data-source="plans"
          :loading="loading"
          row-key="id"
          :pagination="{ pageSize: 8 }"
          size="middle"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'station_name'">
              {{ record.station_name || record.station_city_name || 'Genel' }}
            </template>
          </template>
        </Table>
      </section>
    </div>
  </Page>
</template>

<style scoped>
.operations-page {
  display: grid;
  gap: 24px;
}

.hero-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}

.hero-card,
.surface-card,
.table-panel {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 24px;
  background: linear-gradient(180deg, rgba(8, 15, 27, 0.94), rgba(9, 16, 29, 0.92));
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(18px);
}

.hero-card {
  padding: 18px 20px;
  display: grid;
  gap: 8px;
}

.hero-card span {
  color: rgba(226, 232, 240, 0.7);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.hero-card strong {
  color: #f8fafc;
  font-size: 32px;
  line-height: 1;
  font-weight: 800;
}

.hero-card.is-success { background: linear-gradient(180deg, rgba(16, 185, 129, 0.14), rgba(9, 16, 29, 0.92)); }
.hero-card.is-warning { background: linear-gradient(180deg, rgba(245, 158, 11, 0.14), rgba(9, 16, 29, 0.92)); }
.hero-card.is-danger { background: linear-gradient(180deg, rgba(225, 29, 72, 0.14), rgba(9, 16, 29, 0.92)); }

.operations-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(360px, 0.9fr);
  gap: 24px;
}

.surface-card {
  padding: 24px;
  min-width: 0;
}

.card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.card-head h3 {
  margin: 0;
  color: #f8fafc;
  font-size: 24px;
  font-weight: 800;
  letter-spacing: -0.03em;
}

.card-head p {
  margin: 8px 0 0;
  color: rgba(226, 232, 240, 0.76);
  line-height: 1.6;
}

.eyebrow {
  margin: 0;
  color: rgba(226, 232, 240, 0.72);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.form-field {
  display: grid;
  gap: 8px;
}

.form-field label {
  color: #f8fafc;
  font-size: 13px;
  font-weight: 700;
}

.switch-field {
  align-content: center;
}

.span-2 {
  grid-column: span 2;
}

.action-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 18px;
}

.calendar-card {
  padding: 24px;
}

.filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.filter-select {
  min-width: 180px;
}

.slot-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.slot-card {
  padding: 14px;
  border-radius: 18px;
  border: 1px solid rgba(148, 163, 184, 0.14);
  background: rgba(15, 23, 42, 0.66);
  display: grid;
  gap: 10px;
}

.slot-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.slot-top strong {
  color: #f8fafc;
  font-size: 15px;
}

.slot-item {
  display: grid;
  gap: 4px;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(9, 16, 29, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.12);
  cursor: pointer;
}

.slot-item strong {
  color: #f8fafc;
  font-size: 14px;
}

.slot-item span,
.slot-empty {
  color: rgba(226, 232, 240, 0.72);
  font-size: 13px;
}

.table-panel {
  padding: 12px 16px 16px;
  overflow: hidden;
}

:deep(.ant-select-selector),
:deep(.ant-input),
:deep(.ant-input-number),
:deep(.ant-picker),
:deep(.ant-textarea) {
  background: rgba(15, 23, 42, 0.72) !important;
  border-color: rgba(148, 163, 184, 0.18) !important;
  color: #f8fafc !important;
}

:deep(.ant-select-selection-item),
:deep(.ant-select-selection-placeholder),
:deep(.ant-picker-input > input),
:deep(.ant-input),
:deep(.ant-input::placeholder),
:deep(.ant-textarea::placeholder) {
  color: #f8fafc !important;
}

@media (max-width: 1280px) {
  .operations-grid {
    grid-template-columns: 1fr;
  }

  .hero-strip,
  .form-grid,
  .slot-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .hero-strip,
  .form-grid,
  .slot-grid {
    grid-template-columns: 1fr;
  }

  .span-2 {
    grid-column: auto;
  }

  .card-head {
    flex-direction: column;
  }
}
</style>

