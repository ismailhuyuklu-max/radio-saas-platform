<script lang="ts" setup>
import { computed, h, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  Button,
  Card,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Switch,
  Tag,
  message,
} from 'ant-design-vue';
import type { ColumnsType } from 'ant-design-vue/es/table';

import {
  buildSoleaLink,
  createStation,
  deleteStation,
  generateToken,
  getStations,
  type PartCode,
  type RegionCode,
  type StationItem,
  type StationSavePayload,
  toggleStationStatus,
  updateStation,
} from '#/api/modules/radioMedia';

import RadioBasicTable from '#/components/radio/RadioBasicTable.vue';

const SelectRenderable = Select as unknown as import('vue').Component;
const SwitchRenderable = Switch as unknown as import('vue').Component;

interface StationRow extends StationItem {
  city_name: string;
  is_active: boolean;
  station_token: string;
  stream_token: string;
  selectedCategory: PartCode;
  soleaUrl: string;
}

interface StationFormState {
  name: string;
  region_code: RegionCode;
  city_name: string;
  is_active: boolean;
  selectedCategory: PartCode;
}

const partOptions: Array<{ label: string; value: PartCode }> = [
  { label: 'Haber', value: 'news' },
  { label: 'Spor', value: 'sports' },
  { label: 'Ekonomi', value: 'economy' },
  { label: 'Hava Durumu', value: 'weather' },
];

const regionOptions: Array<{ label: string; value: RegionCode }> = [
  { label: 'Marmara', value: 'marmara' },
  { label: 'Ege', value: 'ege' },
  { label: 'Akdeniz', value: 'akdeniz' },
  { label: 'Karadeniz', value: 'karadeniz' },
  { label: 'İç Anadolu', value: 'ic-anadolu' },
  { label: 'Doğu Anadolu', value: 'dogu-anadolu' },
  { label: 'Güneydoğu Anadolu', value: 'guneydogu-anadolu' },
];

const statusOptions: Array<{ label: string; value: StationItem['status'] }> = [
  { label: 'Yayında', value: 'active' },
  { label: 'Duraklatıldı', value: 'paused' },
  { label: 'Arşivlendi', value: 'archived' },
];

const seedStationRows = ref<StationRow[]>([
  {
    id: 'seed-station-1',
    name: 'Adana FM',
    slug: 'adana-fm',
    region_code: 'akdeniz',
    region_name: 'Akdeniz',
    city_name: 'Adana',
    status: 'active',
    is_active: true,
    station_token: 'seed-token-adana',
    stream_token: 'seed-token-adana',
    selectedCategory: 'news',
    soleaUrl: buildSoleaLink('akdeniz', 'news', 'seed-token-adana'),
  },
]);

const stationRows = ref<StationRow[]>([]);
const searchKeyword = ref('');
const regionFilter = ref<RegionCode | undefined>();
const statusFilter = ref<StationItem['status'] | undefined>();
const modalOpen = ref(false);
const editingStation = ref<StationRow | null>(null);
const selectedCategoryByStationId = ref<Record<string, PartCode>>({});
const stationForm = ref<StationFormState>({
  name: '',
  region_code: 'akdeniz',
  city_name: 'Adana',
  is_active: true,
  selectedCategory: 'news',
});

function regionLabel(region: RegionCode) {
  return (
    {
      marmara: 'Marmara',
      ege: 'Ege',
      akdeniz: 'Akdeniz',
      karadeniz: 'Karadeniz',
      'ic-anadolu': 'İç Anadolu',
      'dogu-anadolu': 'Doğu Anadolu',
      'guneydogu-anadolu': 'Güneydoğu Anadolu',
    }[region] || region
  );
}

function statusLabel(status: StationItem['status']) {
  return {
    active: 'Yayında',
    paused: 'Duraklatıldı',
    archived: 'Arşivlendi',
  }[status];
}

function statusColor(status: StationItem['status']) {
  if (status === 'active') return 'green';
  if (status === 'paused') return 'orange';
  return 'red';
}

function resolveStationToken(row: { station_token?: string; stream_token?: string }) {
  return row.stream_token || row.station_token || 'station-token';
}

function buildStationLink(row: StationRow, category = row.selectedCategory) {
  return buildSoleaLink(row.region_code, category, resolveStationToken(row));
}

function mapStationRow(row: StationItem): StationRow {
  const selectedCategory =
    selectedCategoryByStationId.value[row.id] || 'news';
  const token = resolveStationToken(row);

  return {
    ...row,
    city_name: row.city_name || row.name,
    is_active: row.is_active ?? row.status === 'active',
    selectedCategory,
    station_token: row.station_token || token,
    stream_token: row.stream_token || token,
    soleaUrl: buildSoleaLink(row.region_code, selectedCategory, token),
  };
}

const columns: ColumnsType<StationRow> = [
  {
    title: 'İstasyon Adı',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: 'Bölge',
    dataIndex: 'region_name',
    key: 'region_name',
  },
  {
    title: 'İl',
    dataIndex: 'city_name',
    key: 'city_name',
  },
  {
    title: 'Aktif / Pasif',
    key: 'active',
    width: 170,
    customRender: ({ record }) =>
      h('div', { class: 'station-switch-cell' }, [
        h(SwitchRenderable, {
          checked: record.is_active,
          onChange: (checked: boolean | string) =>
            toggleStationActive(record, checked === true || checked === 'true'),
        }),
        h(Tag, { color: record.is_active ? 'green' : 'orange' }, () =>
          record.is_active ? 'Aktif' : 'Pasif',
        ),
      ]),
  },
  {
    title: 'Durum',
    key: 'status',
    width: 130,
    customRender: ({ record }) =>
      h(Tag, { color: statusColor(record.status) }, () => statusLabel(record.status)),
  },
  {
    title: 'Solea Entegrasyon Linki',
    key: 'soleaUrl',
    width: 420,
    customRender: ({ record }) =>
      h('div', { class: 'solea-link-cell' }, [
        h('div', { class: 'solea-link-row' }, [
          h(SelectRenderable, {
            value: record.selectedCategory,
            style: 'width: 148px',
            options: partOptions,
            onChange: (value: PartCode) => updateStationCategory(record.id, value),
          }),
          h('code', { class: 'solea-link-code' }, record.soleaUrl),
        ]),
        h(Space, null, () => [
          h(
            Button,
            { type: 'link', onClick: () => copyStationLink(record) },
            () => 'Kopyala',
          ),
          h(
            Button,
            { type: 'link', onClick: () => regenerateToken(record) },
            () => 'Token Üret',
          ),
        ]),
      ]),
  },
  {
    title: 'İşlemler',
    key: 'actions',
    width: 170,
    customRender: ({ record }) =>
      h(Space, null, () => [
        h(
          Button,
          { type: 'link', onClick: () => openEdit(record) },
          () => 'Düzenle',
        ),
        h(
          Popconfirm,
          {
            title: 'İstasyonu silmek istiyor musunuz?',
            onConfirm: () => removeStation(record.id),
          },
          {
            default: () => h(Button, { type: 'link', danger: true }, () => 'Sil'),
          },
        ),
      ]),
  },
];

const filteredRows = computed(() =>
  stationRows.value.filter((row) => {
    const keyword = searchKeyword.value.trim().toLowerCase();
    const keywordMatch =
      keyword === '' ||
      row.name.toLowerCase().includes(keyword) ||
      row.slug.toLowerCase().includes(keyword) ||
      row.city_name.toLowerCase().includes(keyword);

    const regionMatch = !regionFilter.value || row.region_code === regionFilter.value;
    const statusMatch = !statusFilter.value || row.status === statusFilter.value;

    return keywordMatch && regionMatch && statusMatch;
  }),
);

const stationTotals = computed(() => ({
  total: stationRows.value.length,
  active: stationRows.value.filter((row) => row.is_active).length,
  paused: stationRows.value.filter((row) => !row.is_active && row.status !== 'archived').length,
  archived: stationRows.value.filter((row) => row.status === 'archived').length,
}));

function syncRowLink(row: StationRow) {
  row.station_token = resolveStationToken(row);
  row.stream_token = row.station_token;
  row.soleaUrl = buildStationLink(row);
  selectedCategoryByStationId.value[row.id] = row.selectedCategory;
}

function openCreate() {
  editingStation.value = null;
  stationForm.value = {
    name: '',
    region_code: 'akdeniz',
    city_name: 'Adana',
    is_active: true,
    selectedCategory: 'news',
  };
  modalOpen.value = true;
}

function openEdit(row: StationRow) {
  editingStation.value = row;
  stationForm.value = {
    name: row.name,
    region_code: row.region_code,
    city_name: row.city_name,
    is_active: row.is_active,
    selectedCategory: row.selectedCategory,
  };
  modalOpen.value = true;
}

async function loadStations() {
  try {
    const response = await getStations({
      keyword: searchKeyword.value || undefined,
      region: regionFilter.value,
      status: statusFilter.value,
    });

    if (Array.isArray(response) && response.length > 0) {
      stationRows.value = response.map((row) => {
        const mapped = mapStationRow(row);
        selectedCategoryByStationId.value[mapped.id] =
          selectedCategoryByStationId.value[mapped.id] || mapped.selectedCategory;
        syncRowLink(mapped);
        return mapped;
      });
      return;
    }

    stationRows.value = seedStationRows.value.map((row) => {
      syncRowLink(row);
      return row;
    });
  } catch (error) {
    console.warn('Station list could not be fetched, using seed data.', error);
    stationRows.value = seedStationRows.value.map((row) => {
      syncRowLink(row);
      return row;
    });
  }
}

async function saveStation() {
  const payload = stationForm.value;

  if (!payload.name.trim() || !payload.region_code || !payload.city_name.trim()) {
    message.warning('İstasyon adı, bölge ve il zorunludur.');
    return;
  }

  const commonPayload: StationSavePayload = {
    name: payload.name.trim(),
    region_code: payload.region_code,
    city_name: payload.city_name.trim(),
    is_active: payload.is_active,
    status: payload.is_active ? 'active' : 'paused',
  };

  try {
    const saved = editingStation.value
      ? await updateStation(editingStation.value.id, commonPayload)
      : await createStation(commonPayload);

    const stationId = saved.id || editingStation.value?.id || '';
    let streamToken = resolveStationToken(saved);
    if (!streamToken || streamToken === 'station-token') {
      const tokenResponse = await generateToken(stationId);
      streamToken =
        tokenResponse.station_token || tokenResponse.stream_token || `token-${Date.now()}`;
    }

    const nextRow: StationRow = mapStationRow({
      ...saved,
      station_token: streamToken,
      stream_token: streamToken,
      is_active: saved.is_active ?? commonPayload.is_active ?? true,
      status: saved.status ?? (commonPayload.is_active ? 'active' : 'paused'),
      city_name: saved.city_name || commonPayload.city_name,
    });

    selectedCategoryByStationId.value[nextRow.id] = payload.selectedCategory;
    nextRow.selectedCategory = payload.selectedCategory;
    syncRowLink(nextRow);

    const existingIndex = stationRows.value.findIndex((row) => row.id === nextRow.id);
    if (existingIndex >= 0) {
      stationRows.value.splice(existingIndex, 1, nextRow);
    } else {
      stationRows.value = [nextRow, ...stationRows.value];
    }

    modalOpen.value = false;
    editingStation.value = null;
    message.success('İstasyon kaydedildi.');
  } catch (error) {
    console.error(error);
    message.error('İstasyon kaydedilemedi.');
  }
}

async function removeStation(id: string) {
  try {
    await deleteStation(id);
    stationRows.value = stationRows.value.filter((row) => row.id !== id);
    delete selectedCategoryByStationId.value[id];
    message.success('İstasyon kaldırıldı.');
  } catch (error) {
    console.error(error);
    message.error('İstasyon silinemedi.');
  }
}

async function toggleStationActive(row: StationRow, checked: boolean) {
  const previous = row.is_active;
  row.is_active = checked;
  row.status = checked ? 'active' : 'paused';

  try {
    const response = await toggleStationStatus(row.id, checked);
    const updated = mapStationRow(response);
    selectedCategoryByStationId.value[updated.id] = row.selectedCategory;
    updated.selectedCategory = row.selectedCategory;
    syncRowLink(updated);
    stationRows.value = stationRows.value.map((item) => (item.id === updated.id ? updated : item));
    message.success(checked ? 'İstasyon yayına alındı.' : 'İstasyon pasife alındı.');
  } catch (error) {
    row.is_active = previous;
    row.status = previous ? 'active' : 'paused';
    console.error(error);
    message.error('Durum güncellenemedi.');
  }
}

async function regenerateToken(row: StationRow) {
  try {
    const response = await generateToken(row.id);
    const nextToken = response.station_token || response.stream_token || `token-${Date.now()}`;
    row.station_token = nextToken;
    row.stream_token = nextToken;
    syncRowLink(row);
    stationRows.value = stationRows.value.map((item) => (item.id === row.id ? { ...row } : item));
    message.success('Yeni token üretildi.');
  } catch (error) {
    console.error(error);
    message.error('Token üretilemedi.');
  }
}

async function ensureStationToken(row: StationRow) {
  const token = resolveStationToken(row);
  if (token && token !== 'station-token') {
    return token;
  }

  const response = await generateToken(row.id);
  const nextToken = response.station_token || response.stream_token || `token-${Date.now()}`;
  row.station_token = nextToken;
  row.stream_token = nextToken;
  syncRowLink(row);
  return nextToken;
}

async function copyStationLink(row: StationRow) {
  try {
    const token = await ensureStationToken(row);
    const link = buildSoleaLink(row.region_code, row.selectedCategory, token);
    row.soleaUrl = link;

    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(link);
      message.success('Link panoya kopyalandı.');
      return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = link;
    textarea.setAttribute('readonly', 'true');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);

    if (!copied) {
      throw new Error('Copy command returned false.');
    }

    message.success('Link panoya kopyalandı.');
  } catch (error) {
    console.error(error);
    message.error('Link kopyalanamadı.');
  }
}

function updateStationCategory(id: string, category: PartCode) {
  const row = stationRows.value.find((item) => item.id === id);
  if (!row) return;
  row.selectedCategory = category;
  selectedCategoryByStationId.value[id] = category;
  syncRowLink(row);
}

onMounted(loadStations);
</script>

<template>
  <Page
    title="İstasyon Yönetimi"
    description="Bölgeye bağlı istasyonlar ve Solea entegrasyon bağlantıları"
  >
    <div class="radio-crud-shell">
      <section class="radio-hero-card">
        <div class="radio-hero-copy">
          <div class="radio-eyebrow">Station operations paneli</div>
          <h2>Radyoları, aktiflik durumunu ve statik Solea linklerini tek panelde yönetin</h2>
          <p>
            Bölge atamaları, il bilgisi, aktif/pasif switch, token üretimi ve kopyalanabilir Solea bağlantıları
            profesyonel bir kontrol panelinde toplandı.
          </p>
          <div class="radio-kpi-grid">
            <div class="radio-kpi-card">
              <span>Toplam istasyon</span>
              <strong>{{ stationTotals.total }}</strong>
            </div>
            <div class="radio-kpi-card is-success">
              <span>Aktif</span>
              <strong>{{ stationTotals.active }}</strong>
            </div>
            <div class="radio-kpi-card is-warning">
              <span>Pasif</span>
              <strong>{{ stationTotals.paused }}</strong>
            </div>
            <div class="radio-kpi-card is-danger">
              <span>Arşiv</span>
              <strong>{{ stationTotals.archived }}</strong>
            </div>
          </div>
        </div>

        <div class="radio-hero-side">
          <div class="radio-side-panel">
            <p class="radio-section-label">Hızlı durum</p>
            <h3>Entegrasyon hazır</h3>
            <p>
              Tek tıkla kopyalanan statik linkler otomasyon yazılımları için doğrudan kullanıma hazır.
            </p>
            <div class="radio-status-list">
              <div>
                <span>Link biçimi</span>
                <strong>https://api.domain.com/v1/stream/...</strong>
              </div>
              <div>
                <span>Token akışı</span>
                <strong>Otomatik üretim</strong>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div class="radio-banner-strip">
        <div class="radio-banner-item">
          <span>Solea URL</span>
          <strong>Statik ve kopyalanabilir</strong>
        </div>
        <div class="radio-banner-item">
          <span>Token</span>
          <strong>Otomatik üretim akışı</strong>
        </div>
        <div class="radio-banner-item">
          <span>İstasyonlar</span>
          <strong>Bölge bazlı izolasyon</strong>
        </div>
      </div>

      <section class="radio-grid-layout">
        <Card :bordered="false" class="radio-surface-card radio-filter-card">
          <div class="radio-card-head">
            <div>
              <p class="radio-section-label">Filtreler</p>
              <h3>İstasyon arama ve bölgesel süzme</h3>
            </div>
            <Button type="primary" @click="openCreate">
              Yeni İstasyon
            </Button>
          </div>

          <div class="radio-filter-row">
            <Input
              v-model:value="searchKeyword"
              placeholder="İstasyon, il veya slug ara"
              class="radio-filter-input"
              @change="loadStations"
            />
            <Select
              v-model:value="regionFilter"
              allow-clear
              placeholder="Bölge"
              class="radio-filter-select"
              :options="regionOptions"
              @change="loadStations"
            />
            <Select
              v-model:value="statusFilter"
              allow-clear
              placeholder="Durum"
              class="radio-filter-select"
              :options="statusOptions"
              @change="loadStations"
            />
          </div>
        </Card>

        <RadioBasicTable
          title="İstasyon kayıtları"
          description="Liste"
          :columns="columns"
          :data-source="filteredRows"
          row-key="id"
          :pagination="{ pageSize: 10 }"
          :scroll="{ x: 1240 }"
          class="radio-basic-table-shell"
        >
          <template #actions>
            <Tag color="blue">Solea entegrasyon hazır</Tag>
          </template>
        </RadioBasicTable>
      </section>
    </div>

    <Modal
      v-model:open="modalOpen"
      :title="editingStation ? 'İstasyon Düzenle' : 'Yeni İstasyon'"
      :footer="null"
      destroy-on-close
      class="radio-modal"
      :width="980"
    >
      <div class="radio-modal-grid">
        <section class="radio-modal-side">
          <div class="radio-modal-info-box">
            <p class="radio-section-label">İstasyon özeti</p>
            <h3>Statik Solea linki</h3>
            <p>
              İstasyonun bağlandığı bölge, şehir ve varsayılan içerik türü burada yönetilir. Token değiştiğinde
              link otomatik güncellenir.
            </p>
          </div>

          <div class="radio-modal-meta-grid">
            <div>
              <span>Bölge</span>
              <strong>{{ regionLabel(stationForm.region_code) }}</strong>
            </div>
            <div>
              <span>İl</span>
              <strong>{{ stationForm.city_name || 'Seçilmedi' }}</strong>
            </div>
            <div>
              <span>İçerik türü</span>
              <strong>{{ partOptions.find((item) => item.value === stationForm.selectedCategory)?.label || 'Haber' }}</strong>
            </div>
            <div>
              <span>Durum</span>
              <strong>{{ stationForm.is_active ? 'Aktif' : 'Pasif' }}</strong>
            </div>
          </div>
        </section>

        <section class="radio-modal-form">
          <div class="radio-modal-form-head">
            <p class="radio-section-label">İstasyon ayarları</p>
            <h3>{{ editingStation ? 'Düzenle' : 'Yeni istasyon' }}</h3>
          </div>

          <Form layout="vertical">
            <Form.Item label="İstasyon Adı">
              <Input v-model:value="stationForm.name" />
            </Form.Item>
            <Form.Item label="Coğrafi Bölge">
              <Select v-model:value="stationForm.region_code" :options="regionOptions" />
            </Form.Item>
            <Form.Item label="İl">
              <Input v-model:value="stationForm.city_name" />
            </Form.Item>
            <Form.Item label="Aktiflik Durumu">
              <div class="station-switch-row">
                <Switch v-model:checked="stationForm.is_active" />
                <span>{{ stationForm.is_active ? 'Aktif' : 'Pasif' }}</span>
              </div>
            </Form.Item>
            <Form.Item label="Varsayılan İçerik Türü">
              <Select v-model:value="stationForm.selectedCategory" :options="partOptions" />
            </Form.Item>
          </Form>

          <div class="radio-modal-actions">
            <Button @click="modalOpen = false">İptal</Button>
            <Button type="primary" @click="saveStation">Kaydet ve linki güncelle</Button>
          </div>
        </section>
      </div>
    </Modal>
  </Page>
</template>

<style scoped>
.radio-crud-shell {
  display: grid;
  gap: 24px;
}

.radio-hero-card {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.65fr);
  gap: 24px;
  padding: 28px;
  border-radius: 28px;
  overflow: hidden;
  color: #fff;
  background:
    radial-gradient(circle at top left, rgba(225, 29, 72, 0.22), transparent 34%),
    radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.12), transparent 30%),
    linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
}

.radio-hero-copy h2,
.radio-hero-side h3,
.radio-card-head h3,
.radio-modal-form-head h3,
.radio-modal-info-box h3 {
  margin: 0;
  line-height: 1.1;
}

.radio-hero-copy h2 {
  color: #f8fafc;
  font-size: 30px;
  margin-top: 8px;
  max-width: 820px;
}

.radio-hero-copy p,
.radio-hero-side p,
.radio-side-panel p,
.radio-modal-info-box p {
  margin: 0;
  color: rgba(226, 232, 240, 0.8);
  line-height: 1.65;
}

.radio-eyebrow,
.radio-section-label {
  margin: 0 0 10px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(226, 232, 240, 0.72);
}

.radio-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 22px;
}

.radio-kpi-card {
  display: grid;
  gap: 6px;
  padding: 16px;
  border-radius: 18px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.14);
  backdrop-filter: blur(14px);
}

.radio-kpi-card span {
  font-size: 12px;
  color: rgba(226, 232, 240, 0.7);
}

.radio-kpi-card strong {
  font-size: 26px;
}

.radio-kpi-card.is-success {
  background: rgba(22, 163, 74, 0.18);
}

.radio-kpi-card.is-warning,
.radio-kpi-card.is-danger {
  background: rgba(225, 29, 72, 0.14);
}

.radio-hero-side {
  display: grid;
}

.radio-side-panel,
.radio-modal-info-box {
  display: grid;
  gap: 12px;
  padding: 22px;
  border-radius: 24px;
  background: rgba(13, 22, 39, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.12);
  backdrop-filter: blur(14px);
}

.radio-side-panel h3,
.radio-modal-info-box h3 {
  color: #fff;
  font-size: 24px;
}

.radio-status-list {
  display: grid;
  gap: 12px;
}

.radio-status-list div,
.radio-modal-meta-grid div {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border-radius: 18px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.radio-status-list span,
.radio-modal-meta-grid span {
  font-size: 12px;
  color: rgba(226, 232, 240, 0.72);
}

.radio-status-list strong,
.radio-modal-meta-grid strong {
  color: #fff;
  font-size: 13px;
  word-break: break-word;
}

.radio-grid-layout {
  display: grid;
  grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.58fr);
  gap: 20px;
}

.radio-banner-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.radio-banner-item {
  display: grid;
  gap: 8px;
  padding: 18px 20px;
  border-radius: 20px;
  background: linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
  color: #fff;
  border: 1px solid rgba(148, 163, 184, 0.16);
  box-shadow: 0 16px 38px rgba(15, 23, 42, 0.16);
}

.radio-banner-item span {
  font-size: 12px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(226, 232, 240, 0.7);
}

.radio-banner-item strong {
  font-size: 18px;
}

.radio-surface-card {
  border-radius: 24px;
  box-shadow: 0 20px 48px rgba(15, 23, 42, 0.08);
}

.radio-surface-card :deep(.ant-card-body) {
  padding: 24px;
}

.radio-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.radio-card-head h3 {
  color: #f8fafc;
  font-size: 22px;
}

.radio-filter-row {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) repeat(2, minmax(180px, 0.7fr));
  gap: 12px;
}

.radio-filter-input,
.radio-filter-select {
  width: 100%;
}

.radio-table :deep(.ant-table) {
  border-radius: 18px;
  overflow: hidden;
}

.radio-basic-table-shell {
  background: linear-gradient(180deg, rgba(18, 27, 46, 0.94), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.radio-table-row-meta {
  display: grid;
  gap: 4px;
}

.solea-link-cell {
  display: grid;
  gap: 10px;
}

.solea-link-row {
  display: grid;
  grid-template-columns: 148px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
}

.solea-link-code {
  display: block;
  padding: 10px 12px;
  border-radius: 14px;
  font-size: 12px;
  line-height: 1.45;
  color: #f8fafc;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.16);
  overflow-wrap: anywhere;
}

.station-switch-cell {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.radio-modal :deep(.ant-modal-content) {
  border-radius: 28px;
  overflow: hidden;
  background: rgba(9, 16, 29, 0.98);
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.radio-modal-grid {
  display: grid;
  grid-template-columns: minmax(0, 350px) minmax(0, 1fr);
  gap: 20px;
}

.radio-modal-side,
.radio-modal-form {
  display: grid;
  gap: 18px;
}

.radio-modal-info-box {
  background: linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
}

.radio-modal-meta-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.radio-modal-meta-grid div {
  background: rgba(15, 23, 42, 0.72);
  border-color: rgba(148, 163, 184, 0.12);
}

.radio-modal-form {
  padding: 20px;
  border-radius: 24px;
  background: linear-gradient(180deg, rgba(18, 27, 46, 0.94), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.radio-modal-form-head h3 {
  color: #f8fafc;
  font-size: 24px;
}

.radio-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.station-switch-row {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  color: #f8fafc;
}

.radio-filter-card {
  background: linear-gradient(180deg, rgba(18, 27, 46, 0.94), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.radio-filter-card :deep(.ant-input),
.radio-filter-card :deep(.ant-select-selector),
.radio-filter-card :deep(.ant-select-selection-item),
.radio-filter-card :deep(.ant-select-selection-placeholder),
.radio-modal-form :deep(.ant-input),
.radio-modal-form :deep(.ant-select-selector),
.radio-modal-form :deep(.ant-select-selection-item),
.radio-modal-form :deep(.ant-select-selection-placeholder) {
  background: rgba(15, 23, 42, 0.72) !important;
  color: #f8fafc !important;
  border-color: rgba(148, 163, 184, 0.18) !important;
}

.radio-filter-card :deep(.ant-input::placeholder),
.radio-filter-card :deep(.ant-select-selection-placeholder),
.radio-modal-form :deep(.ant-input::placeholder),
.radio-modal-form :deep(.ant-select-selection-placeholder) {
  color: rgba(148, 163, 184, 0.78) !important;
}

.radio-filter-card :deep(.ant-form-item-label > label),
.radio-modal-form :deep(.ant-form-item-label > label) {
  color: #f8fafc !important;
}

:deep(.ant-card-head-title) {
  font-weight: 700;
}

:deep(.ant-tag) {
  border-radius: 999px;
  padding: 3px 10px;
}

@media (max-width: 1280px) {
  .radio-hero-card,
  .radio-banner-strip,
  .radio-grid-layout,
  .radio-modal-grid {
    grid-template-columns: 1fr;
  }

  .radio-kpi-grid,
  .radio-filter-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 860px) {
  .radio-kpi-grid,
  .radio-banner-strip,
  .radio-filter-row,
  .radio-modal-meta-grid {
    grid-template-columns: 1fr;
  }

  .radio-card-head,
  .radio-modal-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .solea-link-row {
    grid-template-columns: 1fr;
  }
}
</style>
