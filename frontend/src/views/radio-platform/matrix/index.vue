<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import {
  Button,
  Empty,
  Modal,
  Progress,
  Spin,
  Tag,
  Upload,
  message,
} from 'ant-design-vue';

import TurkeySvgMap, {
  type TurkeyRegionState,
} from '#/components/TurkeySvgMap.vue';
import AircastProLogo from '#/components/brand/AircastProLogo.vue';

import {
  createMatrixKey,
  getFeed,
  getMatrixLive,
  getMatrixStatus,
  normalizeMatrixPayload,
  type MatrixGridCell,
  type MatrixLiveResponse,
  type PartCode,
  type RegionCode,
  uploadMedia,
} from '#/api/modules/radioMedia';

const regionOrder: Array<{ code: RegionCode; label: string }> = [
  { code: 'marmara', label: 'Marmara' },
  { code: 'ege', label: 'Ege' },
  { code: 'akdeniz', label: 'Akdeniz' },
  { code: 'karadeniz', label: 'Karadeniz' },
  { code: 'ic-anadolu', label: 'İç Anadolu' },
  { code: 'dogu-anadolu', label: 'Doğu Anadolu' },
  { code: 'guneydogu-anadolu', label: 'Güneydoğu Anadolu' },
];

const partOrder: Array<{ code: PartCode; label: string }> = [
  { code: 'news', label: 'Haber' },
  { code: 'sports', label: 'Spor' },
  { code: 'economy', label: 'Ekonomi' },
  { code: 'weather', label: 'Hava Durumu' },
];

const matrixCells = ref<MatrixGridCell[]>(normalizeMatrixPayload([]));
const selectedRegionCode = ref<RegionCode>('marmara');
const selectedCell = ref<MatrixGridCell | null>(null);
const modalVisible = ref(false);
const previewUrl = ref('');
const previewMime = ref('audio/mpeg');
const previewLoading = ref(false);
const uploading = ref(false);
const uploadProgress = ref(0);
const currentUploadFile = ref<File | null>(null);
const currentObjectUrl = ref('');
const liveClock = ref(new Date());
let liveClockTimer: number | null = null;
const matrixCellLookup = computed(
  () => new Map(matrixCells.value.map((cell) => [cell.key, cell])),
);

const matrixGrid = computed(() =>
  regionOrder.map((region) => ({
    region,
    cells: partOrder.map((part) => {
      const key = createMatrixKey(region.code, part.code);
      return (
        matrixCellLookup.value.get(key) ?? {
          key,
          regionCode: region.code,
          regionLabel: region.label,
          partCode: part.code,
          partLabel: part.label,
          status: 'danger' as const,
          updatedAt: null,
          hasSponsor: false,
          title: undefined,
          stationSlug: undefined,
        }
      );
    }),
  })),
);

const regionSummaries = computed(() =>
  regionOrder.map((region) => {
    const cells = matrixGrid.value.find((row) => row.region.code === region.code)?.cells ?? [];
    const successCount = cells.filter((cell) => cell.status === 'success').length;
    const warningCount = cells.filter((cell) => cell.status === 'warning').length;
    const dangerCount = cells.filter((cell) => cell.status === 'danger').length;
    const latestCell =
      [...cells]
        .filter((cell) => cell.updatedAt)
        .sort((left, right) => String(right.updatedAt).localeCompare(String(left.updatedAt)))[0] ?? null;

    const dominantTone: MatrixGridCell['status'] =
      successCount >= warningCount && successCount >= dangerCount
        ? 'success'
        : warningCount >= dangerCount
          ? 'warning'
          : 'danger';

    return {
      code: region.code,
      label: region.label,
      cells,
      successCount,
      warningCount,
      dangerCount,
      totalCount: cells.length,
      latestUpdatedAt: latestCell?.updatedAt ?? null,
      dominantTone,
    };
  }),
);

const regionStateMap = computed<Record<RegionCode, TurkeyRegionState>>(() =>
  Object.fromEntries(
    regionSummaries.value.map((region) => [
      region.code,
      {
        dominantTone: region.dominantTone,
        successCount: region.successCount,
        warningCount: region.warningCount,
        dangerCount: region.dangerCount,
        totalCount: region.totalCount,
        latestUpdatedAt: region.latestUpdatedAt,
      },
    ]),
  ) as Record<RegionCode, TurkeyRegionState>,
);

const selectedRegionSummary = computed(
  () =>
    regionSummaries.value.find((region) => region.code === selectedRegionCode.value) ??
    regionSummaries.value[0],
);

const liveOverview = ref<MatrixLiveResponse | null>(null);
const liveOverviewLoading = ref(false);

const liveSlots = computed(() => liveOverview.value?.slots ?? []);
const liveStations = computed(() => liveOverview.value?.active_stations ?? []);

const selectedCellTitle = computed(() => {
  if (!selectedCell.value) {
    return 'Matris hücresi';
  }

  return `${selectedCell.value.regionLabel} / ${selectedCell.value.partLabel}`;
});

const selectedCellToneColor = computed(() => {
  const status = selectedCell.value?.status ?? 'danger';
  if (status === 'success') return '#10b981';
  return '#e11d48';
});

const sponsorPreviewItems = computed(() => {
  const sponsored = matrixCells.value.filter((cell) => cell.hasSponsor).length;
  const live = matrixCells.value.filter((cell) => cell.status === 'success').length;
  return [
    { label: 'Sponsorlu içerik', value: String(sponsored), tone: 'success' },
    { label: 'Canlı içerik', value: String(live), tone: 'warning' },
    { label: 'Aktif radyo', value: String(liveStations.value.length), tone: 'success' },
  ];
});

function goToSponsors() {
  window.open('/radio-platform/sponsors', '_blank', 'noopener,noreferrer');
}

const overallTotals = computed(() => ({
  success: matrixCells.value.filter((cell) => cell.status === 'success').length,
  warning: matrixCells.value.filter((cell) => cell.status === 'warning').length,
  danger: matrixCells.value.filter((cell) => cell.status === 'danger').length,
  total: matrixCells.value.length,
}));

const matrixOverviewCards = computed(() => [
  { label: 'Toplam hücre', value: overallTotals.value.total, tone: 'default' },
  { label: 'Canlı', value: overallTotals.value.success, tone: 'success' },
  { label: 'Uyarı', value: overallTotals.value.warning, tone: 'warning' },
  { label: 'Kritik', value: overallTotals.value.danger, tone: 'danger' },
  { label: 'Aktif radyo', value: liveStations.value.length, tone: 'primary' },
  { label: 'Saat kuşağı', value: liveSlots.value.length, tone: 'default' },
] as const);

const formattedClock = computed(() =>
  new Intl.DateTimeFormat('tr-TR', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    weekday: 'long',
    hour: '2-digit',
    minute: '2-digit',
  }).format(liveClock.value),
);

function toneClass(status: MatrixGridCell['status'] | undefined | null) {
  if (status === 'success') return 'tone-success';
  if (status === 'warning') return 'tone-warning';
  return 'tone-danger';
}

function toneTagColor(status: MatrixGridCell['status'] | undefined | null) {
  if (status === 'success') return 'green';
  if (status === 'warning') return 'orange';
  return 'red';
}

function statusText(status: MatrixGridCell['status'] | undefined | null) {
  if (status === 'success') return 'Canlı';
  if (status === 'warning') return 'Uyarı';
  return 'Kritik';
}

function focusRegion(regionCode: RegionCode) {
  selectedRegionCode.value = regionCode;
}

async function loadLiveOverview(regionCode: RegionCode) {
  liveOverviewLoading.value = true;

  try {
    liveOverview.value = await getMatrixLive(regionCode);
  } catch (error) {
    liveOverview.value = null;
    console.warn('Live matrix overview could not be loaded.', error);
  } finally {
    liveOverviewLoading.value = false;
  }
}

function openPrimaryCell(regionCode: RegionCode) {
  const region = matrixGrid.value.find((row) => row.region.code === regionCode);
  if (!region) {
    return;
  }

  const preferred =
    region.cells.find((cell) => cell.status === 'success') ??
    region.cells.find((cell) => cell.status === 'warning') ??
    region.cells[0] ??
    null;

  if (preferred) {
    openCell(preferred);
  }
}

async function loadMatrix() {
  try {
    const response = await getMatrixStatus();
    matrixCells.value = normalizeMatrixPayload(response);
  } catch (error) {
    console.warn('Matrix data could not be loaded.', error);
  }
}

function openCell(cell: MatrixGridCell) {
  if (currentObjectUrl.value) {
    URL.revokeObjectURL(currentObjectUrl.value);
    currentObjectUrl.value = '';
  }

  selectedCell.value = cell;
  previewUrl.value = '';
  previewMime.value = 'audio/mpeg';
  currentUploadFile.value = null;
  uploadProgress.value = 0;
  modalVisible.value = true;
}

function closeModal() {
  modalVisible.value = false;
  selectedCell.value = null;
  previewUrl.value = '';
  previewMime.value = 'audio/mpeg';
  uploadProgress.value = 0;
  currentUploadFile.value = null;

  if (currentObjectUrl.value) {
    URL.revokeObjectURL(currentObjectUrl.value);
    currentObjectUrl.value = '';
  }
}

function isVideoMime(mime: string) {
  return mime.startsWith('video/');
}

function updateLocalPreview(file: File) {
  if (currentObjectUrl.value) {
    URL.revokeObjectURL(currentObjectUrl.value);
  }

  currentObjectUrl.value = URL.createObjectURL(file);
  previewUrl.value = currentObjectUrl.value;
  previewMime.value = file.type || 'audio/mpeg';
  currentUploadFile.value = file;
}

async function uploadFile(file: File) {
  if (!selectedCell.value) {
    message.warning('Önce bir matris hücresi seçin.');
    return false;
  }

  const title = file.name.replace(/\.[^.]+$/, '');

  uploading.value = true;
  uploadProgress.value = 0;

  try {
    await uploadMedia({
      file,
      regionId: selectedCell.value.regionCode,
      partCode: selectedCell.value.partCode,
      title,
    });
    uploadProgress.value = 100;

    updateLocalPreview(file);
    message.success('Dosya yükleme tamamlandı.');
    await loadMatrix();
  } catch (error) {
    message.error('Dosya yüklenemedi.');
    console.error(error);
  } finally {
    uploading.value = false;
  }

  return false;
}

const uploadProps = {
  accept: '.mp3,.mp4,audio/*,video/*',
  multiple: false,
  showUploadList: false,
  beforeUpload: async (file: File) => uploadFile(file),
};

watch(
  () => selectedCell.value,
  async (cell) => {
    if (!cell?.stationSlug) {
      previewUrl.value = '';
      previewMime.value = 'audio/mpeg';
      return;
    }

    previewLoading.value = true;
    try {
      const feed = await getFeed(cell.stationSlug, cell.partCode, 'json');
      previewUrl.value = feed.stream.download_url;
      previewMime.value = feed.stream.mime || 'audio/mpeg';
    } catch (error) {
      previewUrl.value = '';
      previewMime.value = 'audio/mpeg';
      console.warn('Presigned feed could not be loaded.', error);
    } finally {
      previewLoading.value = false;
    }
  },
  { immediate: false },
);

onMounted(loadMatrix);
onMounted(() => {
  void loadLiveOverview(selectedRegionCode.value);
});

watch(selectedRegionCode, (regionCode) => {
  void loadLiveOverview(regionCode);
});

onMounted(() => {
  liveClockTimer = window.setInterval(() => {
    liveClock.value = new Date();
  }, 30_000);
});

onBeforeUnmount(() => {
  if (currentObjectUrl.value) {
    URL.revokeObjectURL(currentObjectUrl.value);
  }

  if (liveClockTimer) {
    window.clearInterval(liveClockTimer);
    liveClockTimer = null;
  }
});
</script>
<template>
  <div class="matrix-page">
    <div class="grid grid-cols-12 gap-6 matrix-shell">
      <section class="col-span-8 matrix-map-panel">
        <div class="panel-head">
          <div class="panel-brand">
            <AircastProLogo compact />
            <div class="panel-brand-copy">
              <strong>Aircast Pro</strong>
              <span>Haber Yönetim Paneli</span>
            </div>
            <div class="panel-brand-clock">
              <span>Güncellenen zaman</span>
              <strong>{{ formattedClock }}</strong>
            </div>
          </div>
          <div class="panel-head-actions">
            <Tag :color="toneTagColor(selectedRegionSummary?.dominantTone)">
              {{ statusText(selectedRegionSummary?.dominantTone) }}
            </Tag>
            <Button type="primary" @click="openPrimaryCell(selectedRegionCode)">
              Seçili bölgenin hücresini aç
            </Button>
          </div>
        </div>

        <div class="matrix-overview-strip">
          <div
            v-for="card in matrixOverviewCards"
            :key="card.label"
            class="matrix-overview-card"
            :class="[`is-${card.tone}`]"
          >
            <span>{{ card.label }}</span>
            <strong>{{ card.value }}</strong>
          </div>
        </div>

        <TurkeySvgMap
          class="turkey-map"
          :selected-region-code="selectedRegionCode"
          :region-states="regionStateMap"
          @select-region="focusRegion"
        />
      </section>

      <aside class="col-span-4 matrix-rail-panel">
        <div class="panel-head">
          <div>
            <p class="section-label">Bölgesel kontrol merkezi</p>
            <h3>{{ selectedRegionSummary?.label }}</h3>
            <p class="rail-subtitle">
              Canlı yayın sağlığı, sponsorluk durumu ve son güncelleme tek bakışta.
            </p>
          </div>
          <Tag :color="toneTagColor(selectedRegionSummary?.dominantTone)">
            {{ statusText(selectedRegionSummary?.dominantTone) }}
          </Tag>
        </div>

        <div class="rail-summary">
          <div class="rail-summary-item">
            <span>Toplam</span>
            <strong>{{ selectedRegionSummary?.totalCount ?? 0 }}</strong>
          </div>
          <div class="rail-summary-item is-success">
            <span>Canlı</span>
            <strong>{{ selectedRegionSummary?.successCount ?? 0 }}</strong>
          </div>
          <div class="rail-summary-item is-warning">
            <span>Uyarı</span>
            <strong>{{ selectedRegionSummary?.warningCount ?? 0 }}</strong>
          </div>
          <div class="rail-summary-item is-danger">
            <span>Kritik</span>
            <strong>{{ selectedRegionSummary?.dangerCount ?? 0 }}</strong>
          </div>
        </div>

        <div class="rail-mini-type-strip">
          <div
            v-for="part in partOrder"
            :key="part.code"
            class="rail-mini-type"
          >
            <span>{{ part.label }}</span>
          </div>
        </div>

        <div class="rail-region-list">
          <div
            v-for="region in regionSummaries"
            :key="region.code"
            class="rail-region-item"
            :class="toneClass(region.dominantTone)"
          >
            <div class="rail-region-item-main">
              <strong>{{ region.label }}</strong>
              <span>
                {{ region.successCount }} canlı · {{ region.warningCount }} uyarı ·
                {{ region.dangerCount }} kritik
              </span>
            </div>
            <Tag :color="toneTagColor(region.dominantTone)">
              {{ statusText(region.dominantTone) }}
            </Tag>
          </div>
        </div>

        <div class="rail-footer">
          <span>Son güncelleme</span>
          <strong>{{ selectedRegionSummary?.latestUpdatedAt || 'Veri yok' }}</strong>
        </div>

        <Spin :spinning="liveOverviewLoading">
          <div class="rail-live-section">
            <div class="rail-live-head">
              <p class="section-label">Saat kuşakları</p>
              <h4>08.00 - 20.00 haber akışı</h4>
            </div>

            <div v-if="liveSlots.length" class="rail-slot-list">
              <div
                v-for="slot in liveSlots"
                :key="slot.time"
                class="rail-slot-item"
                :class="toneClass(slot.status)"
              >
                <div class="rail-slot-top">
                  <strong>{{ slot.time }}</strong>
                  <Tag :color="toneTagColor(slot.status)">
                    {{ statusText(slot.status) }}
                  </Tag>
                </div>
                <span>{{ slot.part_label }}</span>
                <small>{{ slot.station_count }} aktif radyo</small>
              </div>
            </div>

            <Empty
              v-else
              :image="Empty.PRESENTED_IMAGE_SIMPLE"
              description="Saat kuşağı verisi bekleniyor"
            />
          </div>

          <div class="rail-live-section">
            <div class="rail-live-head">
              <p class="section-label">Aktif radyolar</p>
              <h4>Yayın logları</h4>
            </div>

            <div v-if="liveStations.length" class="rail-station-list">
              <div
                v-for="station in liveStations"
                :key="station.id"
                class="rail-station-item"
              >
                <div class="rail-station-main">
                  <strong>{{ station.city_name || station.name }}</strong>
                  <span>{{ station.region_name }}</span>
                </div>
                <small>{{ station.updated_at || 'Log yok' }}</small>
                <code>{{ station.feed_url || 'Feed bekleniyor' }}</code>
              </div>
            </div>

            <Empty
              v-else
              :image="Empty.PRESENTED_IMAGE_SIMPLE"
              description="Aktif radyo bulunamadı"
            />
          </div>
        </Spin>
      </aside>

      <section class="col-span-12 matrix-sponsor-panel">
        <div class="panel-head">
          <div>
            <p class="section-label">Sponsorluk alanı</p>
            <h3>Harita ile uyumlu sponsor yönetimi</h3>
          </div>
          <Button type="primary" @click="goToSponsors">
            Sponsor ekranını aç
          </Button>
        </div>

        <div class="sponsor-preview-layout">
          <div class="sponsor-preview-copy">
            <p>
              Reklam yükleme, hedefleme ve render kuyruğu burada da aynı koyu
              tema içinde çalışır. Ayrıntılı sponsor yönetimine doğrudan
              geçebilirsiniz.
            </p>

            <div class="sponsor-preview-grid">
              <div
                v-for="item in sponsorPreviewItems"
                :key="item.label"
                class="sponsor-preview-card"
                :class="`is-${item.tone}`"
              >
                <span>{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </div>
            </div>
          </div>

          <div class="sponsor-preview-rail">
            <div class="sponsor-preview-rail-box">
              <span>Panel görünümü</span>
              <strong>Matrix ile aynı tema</strong>
            </div>
            <div class="sponsor-preview-rail-box">
              <span>Yükleme altyapısı</span>
              <strong>MinIO + parçalı yükleme</strong>
            </div>
            <div class="sponsor-preview-rail-box">
              <span>Reklam yerleşimi</span>
              <strong>Ön reklam / son reklam</strong>
            </div>
          </div>
        </div>
      </section>

      <section class="col-span-12 matrix-grid-panel">
        <div class="panel-head">
          <div>
            <h3>7 x 4 yayın sağlığı görünümü</h3>
          </div>
          <div class="matrix-head-note">
            Hücreye tıklayın, önizleme ve chunk upload modalı açılır.
          </div>
        </div>

        <div class="matrix-table">
          <div class="matrix-table-head">
            <div class="matrix-table-corner">Bölge / İçerik</div>
            <div
              v-for="part in partOrder"
              :key="`matrix-head-${part.code}`"
              class="matrix-table-head-cell"
            >
              {{ part.label }}
            </div>
          </div>

          <div
            v-for="row in matrixGrid"
            :key="`matrix-row-${row.region.code}`"
            class="matrix-table-row"
            :class="{ 'is-selected': row.region.code === selectedRegionCode }"
            @click="focusRegion(row.region.code)"
          >
            <button type="button" class="matrix-table-row-label">
              <span>{{ row.region.label }}</span>
              <small>{{ regionStateMap[row.region.code]?.successCount ?? 0 }} canlı</small>
            </button>

            <button
              v-for="cell in row.cells"
              :key="cell.key"
              type="button"
              class="matrix-cell-card"
              :class="[
                toneClass(cell.status),
                { 'is-active': cell.regionCode === selectedRegionCode },
              ]"
              @click.stop="openCell(cell)"
            >
              <div class="matrix-cell-top">
                <span class="wave-mark" aria-hidden="true">≋</span>
                <Tag :color="toneTagColor(cell.status)">
                  {{ statusText(cell.status) }}
                </Tag>
              </div>
              <div class="matrix-cell-title">{{ cell.partLabel }}</div>
              <div class="matrix-cell-meta">
                <span>{{ cell.updatedAt || 'Yükleme yok' }}</span>
                <span>{{ cell.hasSponsor ? 'Sponsorlu' : 'Temel akış' }}</span>
              </div>
            </button>
          </div>
        </div>
      </section>
    </div>

    <Modal
      v-model:open="modalVisible"
      :title="selectedCellTitle"
      :width="1080"
      destroy-on-close
      class="matrix-upload-modal"
      @cancel="closeModal"
    >
      <div class="modal-grid">
        <div class="modal-preview">
          <div class="modal-preview-head">
            <Tag :color="toneTagColor(selectedCell?.status)">
              {{ selectedCell?.partLabel }}
            </Tag>
            <span>{{ selectedCell?.updatedAt || 'Yükleme yok' }}</span>
          </div>
          <div class="preview-status-text">
            {{ statusText(selectedCell?.status) }}
          </div>

          <Spin :spinning="previewLoading">
            <audio
              v-if="previewUrl && !isVideoMime(previewMime)"
              :key="previewUrl"
              :src="previewUrl"
              class="radio-player"
              controls
            />
            <video
              v-else-if="previewUrl"
              :key="previewUrl"
              :src="previewUrl"
              class="radio-player"
              controls
            />
            <Empty v-else description="Henüz önizleme yok" />
          </Spin>

          <div class="preview-hint">
            <strong>{{ selectedCell?.title || selectedCell?.stationSlug || 'Kaynak medya' }}</strong>
            <span>Presigned medya akışı veya yüklenen yerel önizleme burada görünür.</span>
          </div>
        </div>

        <div class="modal-upload">
          <Upload.Dragger v-bind="uploadProps" class="upload-zone">
            <p class="ant-upload-drag-icon">+</p>
            <p class="ant-upload-text">MP3 veya MP4 dosyasını sürükleyip bırakın ya da tıklayın.</p>
            <p class="ant-upload-hint">
              Chunk upload otomatik başlar, yükleme sonrası canlı önizleme yenilenir.
            </p>
          </Upload.Dragger>

          <div class="upload-progress">
            <div class="upload-progress-label">
              <span>Yükleme durumu</span>
              <strong>%{{ uploadProgress }}</strong>
            </div>

            <div class="upload-progress-meter">
              <Progress
                type="circle"
                :percent="uploadProgress"
                :stroke-color="selectedCellToneColor"
                :width="128"
              >
                <template #format="{ percent }">
                  <span class="progress-percent">{{ percent }}%</span>
                </template>
              </Progress>
            </div>

            <Button
              type="primary"
              :loading="uploading"
              @click="() => currentUploadFile && uploadFile(currentUploadFile)"
            >
              Son seçilen dosyayı yeniden yükle
            </Button>
          </div>
        </div>
      </div>
    </Modal>
  </div>
</template>
<style scoped>
.grid {
  display: grid;
}

.grid-cols-12 {
  grid-template-columns: repeat(12, minmax(0, 1fr));
}

.gap-6 {
  gap: 24px;
}

.col-span-4 {
  grid-column: span 4 / span 4;
}

.col-span-8 {
  grid-column: span 8 / span 8;
}

.col-span-12 {
  grid-column: span 12 / span 12;
}

.matrix-page {
  display: block;
  width: 100%;
}

.matrix-shell {
  width: 100%;
}

.matrix-map-panel,
.matrix-rail-panel,
.matrix-grid-panel {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 28px;
  background:
    linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
  box-shadow: 0 28px 60px rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(18px);
}

.matrix-map-panel,
.matrix-rail-panel {
  padding: 16px;
}

.matrix-grid-panel {
  padding: 24px;
}

.panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.panel-brand {
  display: flex;
  align-items: center;
  gap: 16px;
  min-width: 0;
}

.panel-brand-copy {
  min-width: 0;
}

.panel-brand-clock {
  display: flex;
  flex-direction: column;
  gap: 3px;
  margin-left: auto;
  padding-left: 16px;
  border-left: 1px solid rgba(148, 163, 184, 0.14);
  min-width: 0;
}

.panel-head h2,
.panel-head h3 {
  margin: 0;
  color: #f8fafc;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
}

.panel-head h2 {
  font-size: clamp(24px, 2.2vw, 32px);
}

.panel-head h3 {
  font-size: 20px;
  letter-spacing: -0.02em;
}

.panel-head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.panel-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.panel-brand-copy {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.panel-brand-copy strong {
  color: #f8fafc;
  font-size: 18px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.03em;
}

.panel-brand-copy span {
  color: #94a3b8;
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
}

.panel-brand-clock span {
  color: #94a3b8;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  line-height: 1;
}

.panel-brand-clock strong {
  color: #f8fafc;
  font-size: 14px;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.15;
  white-space: nowrap;
}

@media (max-width: 768px) {
  .panel-brand {
    flex-wrap: wrap;
  }

  .panel-brand-clock {
    margin-left: 0;
    padding-left: 0;
    border-left: 0;
    width: 100%;
    padding-top: 8px;
  }
}

.section-label {
  margin: 0 0 8px;
  color: #e11d48;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.22em;
  text-transform: uppercase;
}

.rail-subtitle {
  margin: 8px 0 0;
  color: rgba(148, 163, 184, 0.88);
  font-size: 12px;
  line-height: 1.55;
  max-width: 280px;
}

.turkey-map {
  margin-top: 0;
}

.matrix-overview-strip {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}

.matrix-overview-card {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border-radius: 16px;
  background: rgba(13, 22, 39, 0.76);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.matrix-overview-card span {
  color: #94a3b8;
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.matrix-overview-card strong {
  color: #f8fafc;
  font-size: 20px;
  font-weight: 800;
  line-height: 1.1;
}

.matrix-overview-card.is-success strong {
  color: #10b981;
}

.matrix-overview-card.is-warning strong,
.matrix-overview-card.is-danger strong {
  color: #e11d48;
}

.matrix-overview-card.is-primary strong {
  color: #3b82f6;
}

.rail-summary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}

.rail-summary-item {
  padding: 12px 14px;
  border-radius: 14px;
  background: rgba(13, 22, 39, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.rail-summary-item span {
  display: block;
  color: #94a3b8;
  font-size: 10px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.rail-summary-item strong {
  display: block;
  color: #f8fafc;
  font-size: 17px;
  margin-top: 4px;
  font-weight: 800;
}

.rail-summary-item.is-success strong {
  color: #10b981;
}

.rail-summary-item.is-warning strong {
  color: #e11d48;
}

.rail-summary-item.is-danger strong {
  color: #e11d48;
}

.rail-mini-type-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin-top: 4px;
}

.rail-mini-type {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 42px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.12);
  background: rgba(15, 23, 42, 0.72);
}

.rail-mini-type span {
  color: #f8fafc;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-align: center;
  line-height: 1.1;
}

.rail-region-list {
  display: grid;
  gap: 8px;
  margin-top: 10px;
}

.rail-region-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 16px;
  background: rgba(13, 22, 39, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.rail-region-item-main {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.rail-region-item-main strong {
  color: #f8fafc;
  font-size: 14px;
  font-weight: 800;
}

.rail-region-item-main span {
  color: #94a3b8;
  font-size: 11px;
  line-height: 1.4;
}

.rail-region-item.tone-success {
  border-color: rgba(16, 185, 129, 0.18);
}

.rail-region-item.tone-warning,
.rail-region-item.tone-danger {
  border-color: rgba(225, 29, 72, 0.18);
}

.rail-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 10px;
  padding: 12px 14px;
  border-radius: 16px;
  background: rgba(15, 23, 42, 0.75);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.rail-footer span {
  color: #94a3b8;
}

.rail-footer strong {
  color: #f8fafc;
}

.rail-live-section {
  display: grid;
  gap: 10px;
  padding: 14px;
  border-radius: 18px;
  background: rgba(13, 22, 39, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.rail-live-head h4 {
  margin: 4px 0 0;
  color: #f8fafc;
  font-size: 15px;
  line-height: 1.2;
}

.rail-live-head .section-label {
  margin-bottom: 0;
}

.rail-slot-list,
.rail-station-list {
  display: grid;
  gap: 8px;
}

.rail-slot-item,
.rail-station-item {
  display: grid;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 14px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.rail-slot-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.rail-slot-item strong {
  color: #f8fafc;
  font-size: 14px;
}

.rail-slot-item span,
.rail-slot-item small,
.rail-station-item span,
.rail-station-item small {
  color: rgba(148, 163, 184, 0.9);
  font-size: 11px;
}

.rail-station-main {
  display: grid;
  gap: 3px;
}

.rail-station-main strong {
  color: #f8fafc;
  font-size: 14px;
}

.rail-station-item code {
  display: block;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(9, 16, 29, 0.88);
  color: #f8fafc;
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.matrix-sponsor-panel {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 28px;
  background:
    linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
  box-shadow: 0 28px 60px rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(18px);
  padding: 20px 24px 24px;
  display: grid;
  gap: 16px;
}

.sponsor-preview-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.6fr);
  gap: 16px;
}

.sponsor-preview-copy p {
  margin: 0 0 16px;
  color: rgba(226, 232, 240, 0.8);
  line-height: 1.65;
}

.sponsor-preview-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.sponsor-preview-card,
.sponsor-preview-rail-box {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border-radius: 18px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.sponsor-preview-card span,
.sponsor-preview-rail-box span {
  color: #94a3b8;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
}

.sponsor-preview-card strong,
.sponsor-preview-rail-box strong {
  color: #f8fafc;
  font-size: 15px;
  font-weight: 800;
}

.sponsor-preview-card.is-success strong {
  color: #10b981;
}

.sponsor-preview-card.is-warning strong {
  color: #e11d48;
}

.sponsor-preview-rail {
  display: grid;
  gap: 12px;
}

.matrix-head-note {
  padding: 8px 12px;
  border-radius: 999px;
  background: rgba(225, 29, 72, 0.12);
  color: #fecdd3;
  white-space: nowrap;
}

.rail-detail-card :deep(.ant-progress-circle) {
  display: block;
}

.matrix-table {
  display: grid;
  gap: 14px;
  overflow-x: auto;
}

.matrix-table-head,
.matrix-table-row {
  display: grid;
  grid-template-columns: 200px repeat(4, minmax(170px, 1fr));
  gap: 12px;
}

.matrix-table-corner,
.matrix-table-head-cell,
.matrix-table-row-label,
.matrix-cell-card {
  border-radius: 18px;
}

.matrix-table-corner,
.matrix-table-head-cell,
.matrix-table-row-label {
  padding: 16px;
  background: linear-gradient(135deg, #121b2e, #090d16);
  color: #fff;
  font-weight: 700;
}

.matrix-table-row {
  cursor: pointer;
}

.matrix-table-row.is-selected .matrix-table-row-label {
  box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.28) inset;
}

.matrix-table-row-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 118px;
  text-align: left;
}

.matrix-table-row-label small {
  color: rgba(226, 232, 240, 0.72);
  font-weight: 600;
}

.matrix-cell-card {
  min-height: 118px;
  padding: 16px;
  border: 0;
  text-align: left;
  cursor: pointer;
  color: #fff;
  transition:
    transform 0.18s ease,
    box-shadow 0.18s ease,
    filter 0.18s ease;
}

.matrix-cell-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 18px 34px rgba(15, 23, 42, 0.18);
}

.matrix-cell-card.is-active {
  outline: 1px solid rgba(255, 255, 255, 0.18);
}

.tone-success {
  background: linear-gradient(135deg, #059669, #10b981);
}

.tone-warning {
  background: linear-gradient(135deg, #991b1b, #e11d48);
}

.tone-danger {
  background: linear-gradient(135deg, #7f1d1d, #e11d48);
}

.matrix-cell-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}

.wave-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: rgba(248, 250, 252, 0.14);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.14);
}

.matrix-cell-title {
  margin-bottom: 8px;
  font-size: 15px;
  font-weight: 800;
}

.matrix-cell-meta {
  display: grid;
  gap: 4px;
  font-size: 12px;
  opacity: 0.92;
}

.modal-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.9fr);
  gap: 20px;
}

.matrix-upload-modal :deep(*) {
  color: #f8fafc !important;
}

:global(.matrix-upload-modal),
:global(.matrix-upload-modal *) {
  color: #f8fafc !important;
}

:global(.matrix-upload-modal .ant-upload-text),
:global(.matrix-upload-modal .ant-upload-hint),
:global(.matrix-upload-modal .ant-upload-drag-icon),
:global(.matrix-upload-modal .ant-upload-drag-icon *),
:global(.matrix-upload-modal .ant-empty-description),
:global(.matrix-upload-modal .ant-empty-description *),
:global(.matrix-upload-modal .preview-hint),
:global(.matrix-upload-modal .preview-hint *),
:global(.matrix-upload-modal .upload-progress-label),
:global(.matrix-upload-modal .upload-progress-label *),
:global(.matrix-upload-modal .upload-progress-text),
:global(.matrix-upload-modal .progress-percent),
:global(.matrix-upload-modal .preview-status-text),
:global(.matrix-upload-modal .modal-preview-head),
:global(.matrix-upload-modal .modal-preview-head *),
:global(.matrix-upload-modal .preview-hint),
:global(.matrix-upload-modal .preview-hint *),
:global(.matrix-upload-modal .upload-progress),
:global(.matrix-upload-modal .upload-progress *),
:global(.matrix-upload-modal .upload-zone),
:global(.matrix-upload-modal .upload-zone *),
:global(.matrix-upload-modal .modal-upload),
:global(.matrix-upload-modal .modal-upload *),
:global(.matrix-upload-modal .ant-tag),
:global(.matrix-upload-modal .ant-tag *),
:global(.matrix-upload-modal .ant-modal-title),
:global(.matrix-upload-modal .ant-modal-header),
:global(.matrix-upload-modal .ant-modal-body) {
  color: #f8fafc !important;
}

:global(.matrix-upload-modal .ant-empty-image svg),
:global(.matrix-upload-modal .ant-empty-image svg *) {
  fill: rgba(248, 250, 252, 0.72) !important;
  stroke: rgba(248, 250, 252, 0.72) !important;
}

.matrix-upload-modal :deep(.ant-modal-content),
.matrix-upload-modal :deep(.ant-modal-header),
.matrix-upload-modal :deep(.ant-modal-body),
.matrix-upload-modal :deep(.ant-modal-title),
.matrix-upload-modal :deep(.ant-modal-close),
.matrix-upload-modal :deep(.ant-upload),
.matrix-upload-modal :deep(.ant-upload-wrapper),
.matrix-upload-modal :deep(.ant-upload-list),
.matrix-upload-modal :deep(.ant-upload-list-item),
.matrix-upload-modal :deep(.ant-upload-text),
.matrix-upload-modal :deep(.ant-upload-hint),
.matrix-upload-modal :deep(.ant-progress),
.matrix-upload-modal :deep(.ant-progress-text),
.matrix-upload-modal :deep(.ant-empty),
.matrix-upload-modal :deep(.ant-empty-description),
.matrix-upload-modal :deep(.ant-tag),
.matrix-upload-modal :deep(.ant-tag *) {
  color: #f8fafc !important;
}

.matrix-upload-modal :deep(.ant-upload-drag) {
  background:
    radial-gradient(circle at top right, rgba(225, 29, 72, 0.08), transparent 30%),
    linear-gradient(180deg, rgba(18, 27, 46, 0.96), rgba(9, 13, 22, 0.98)) !important;
  border-color: rgba(248, 250, 252, 0.16) !important;
}

.matrix-upload-modal :deep(.ant-btn-default),
.matrix-upload-modal :deep(.ant-btn-dashed),
.matrix-upload-modal :deep(.ant-btn-ghost) {
  color: #090d16 !important;
  background: #f8fafc !important;
  border-color: rgba(248, 250, 252, 0.9) !important;
}

.matrix-upload-modal :deep(.ant-btn-default:hover),
.matrix-upload-modal :deep(.ant-btn-dashed:hover),
.matrix-upload-modal :deep(.ant-btn-ghost:hover) {
  color: #090d16 !important;
  background: #ffffff !important;
  border-color: #ffffff !important;
}

.matrix-upload-modal :deep(.ant-btn-primary) {
  color: #ffffff !important;
}

.matrix-upload-modal :deep(.ant-empty-img-default-ellipsis),
.matrix-upload-modal :deep(.ant-empty-img-default-path-1),
.matrix-upload-modal :deep(.ant-empty-img-default-path-2),
.matrix-upload-modal :deep(.ant-empty-img-default-path-3) {
  fill: rgba(248, 250, 252, 0.62) !important;
}

.modal-preview,
.modal-upload {
  display: grid;
  gap: 16px;
}

.modal-preview-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.preview-status-text {
  margin-top: -4px;
  color: #f8fafc;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  opacity: 0.92;
}

.radio-player {
  width: 100%;
  border-radius: 14px;
}

.preview-hint {
  display: grid;
  gap: 4px;
  padding: 14px;
  border-radius: 14px;
  background: rgba(18, 27, 46, 0.92);
  color: #f8fafc;
}

.upload-zone {
  border-radius: 18px;
}

.upload-progress {
  display: grid;
  gap: 12px;
}

.upload-progress-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.upload-progress-meter {
  display: grid;
  place-items: center;
  padding: 10px 0 4px;
}

.progress-percent {
  color: #f8fafc;
  font-size: 17px;
  font-weight: 800;
}

@media (max-width: 1400px) {
  .col-span-8,
  .col-span-4 {
    grid-column: span 12 / span 12;
  }

  .sponsor-preview-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 1100px) {
  .modal-grid {
    grid-template-columns: 1fr;
  }

  .matrix-overview-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .matrix-table-head,
  .matrix-table-row {
    grid-template-columns: 140px repeat(4, minmax(140px, 1fr));
  }
}

@media (max-width: 860px) {
  .matrix-overview-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .rail-summary {
    grid-template-columns: 1fr;
  }

  .panel-head,
  .panel-head-actions {
    flex-direction: column;
    align-items: flex-start;
  }

  .rail-footer {
    flex-direction: column;
    align-items: flex-start;
  }

  .rail-mini-type-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .rail-region-item {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>




