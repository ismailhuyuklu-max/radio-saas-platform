<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import {
  Button,
  Checkbox,
  DatePicker,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Radio,
  Select,
  Steps,
  Switch,
  Upload,
  message,
} from 'ant-design-vue';

import {
  deleteSponsor,
  getSponsors,
  PART_LABELS,
  PART_LIST,
  REGION_LABELS,
  REGION_LIST,
  saveSponsor,
  type PartCode,
  type RegionCode,
  type RenderPlacement,
  type SponsorListItem,
  uploadSponsorAsset,
} from '#/api/modules/radioMedia';

const CheckboxGroup = Checkbox.Group;
const RadioGroup = Radio.Group;
const RangePicker = DatePicker.RangePicker;
const Step = Steps.Step;
const Dragger = Upload.Dragger;

const sponsors = ref<SponsorListItem[]>([]);
const loading = ref(false);
const search = ref('');
const partFilter = ref<PartCode | undefined>();

const partOptions = PART_LIST.map((p) => ({ label: PART_LABELS[p], value: p }));
const regionOptions = REGION_LIST.map((r) => ({ label: REGION_LABELS[r], value: r }));
const placementOptions: Array<{ label: string; value: RenderPlacement }> = [
  { label: 'Sunar (başında)', value: 'pre_roll' },
  { label: 'Sundu (sonunda)', value: 'post_roll' },
];

const filtered = computed(() => {
  const q = search.value.trim().toLocaleLowerCase('tr-TR');
  return sponsors.value.filter((s) => {
    const matchQ = !q || s.sponsor_name.toLocaleLowerCase('tr-TR').includes(q);
    const matchP = !partFilter.value || s.content_type === partFilter.value;
    return matchQ && matchP;
  });
});

function placementLabel(p: string) {
  return p === 'outro' ? 'Sundu' : 'Sunar';
}
function dateRange(s: SponsorListItem) {
  if (!s.starts_at && !s.ends_at) return 'Süresiz';
  const f = (d?: string | null) => (d ? dayjs(d).format('DD.MM.YYYY') : '—');
  return `${f(s.starts_at)} → ${f(s.ends_at)}`;
}

async function loadSponsors() {
  loading.value = true;
  try {
    const res = await getSponsors();
    sponsors.value = Array.isArray(res) ? res : [];
  } catch (error) {
    console.error(error);
    sponsors.value = [];
    message.error('Sponsor listesi alınamadı.');
  } finally {
    loading.value = false;
  }
}

async function removeSponsor(id: string) {
  try {
    await deleteSponsor(id);
    message.success('Reklam silindi.');
    await loadSponsors();
  } catch (error) {
    console.error(error);
    message.error('Reklam silinemedi.');
  }
}

/* ---------------- Wizard ---------------- */
const wizardOpen = ref(false);
const step = ref(0);
const saving = ref(false);
const uploadBusy = ref(false);
const editingId = ref<string | null>(null);

const asset = ref<{ bucket: string; key: string; mime: string } | null>(null);
const fileName = ref('');

const wf = ref<{
  name: string;
  placement: RenderPlacement;
  content: PartCode;
  allRegions: boolean;
  regions: RegionCode[];
  priority: number;
  range: [Dayjs | null, Dayjs | null] | null;
}>({
  name: '',
  placement: 'pre_roll',
  content: 'sports',
  allRegions: true,
  regions: ['akdeniz'],
  priority: 10,
  range: null,
});

// ant RangePicker types reject null entries even with allowEmpty; bridge via a proxy.
const rangeModel = computed<[Dayjs, Dayjs] | undefined>({
  get() {
    const r = wf.value.range;
    return r ? ([r[0], r[1]] as unknown as [Dayjs, Dayjs]) : undefined;
  },
  set(v) {
    wf.value.range = (v ?? null) as [Dayjs | null, Dayjs | null] | null;
  },
});

function resetWizard() {
  step.value = 0;
  editingId.value = null;
  asset.value = null;
  fileName.value = '';
  wf.value = {
    name: '',
    placement: 'pre_roll',
    content: 'sports',
    allRegions: true,
    regions: ['akdeniz'],
    priority: 10,
    range: null,
  };
}

function openCreate() {
  resetWizard();
  wizardOpen.value = true;
}

function openEdit(s: SponsorListItem) {
  resetWizard();
  editingId.value = s.id;
  asset.value = { bucket: s.asset_bucket, key: s.asset_key, mime: s.asset_mime };
  fileName.value = s.asset_key.split('/').pop() ?? 'mevcut dosya';
  wf.value = {
    name: s.sponsor_name,
    placement: s.placement,
    content: (s.content_type || s.part_code) as PartCode,
    allRegions: s.is_global,
    regions: s.is_global ? ['akdeniz'] : [s.region_code],
    priority: s.priority ?? 10,
    range:
      s.starts_at || s.ends_at
        ? [s.starts_at ? dayjs(s.starts_at) : null, s.ends_at ? dayjs(s.ends_at) : null]
        : null,
  };
  step.value = 1;
  wizardOpen.value = true;
}

function onFile(file: File) {
  uploadBusy.value = true;
  fileName.value = file.name;
  uploadSponsorAsset(file)
    .then((a) => {
      asset.value = { bucket: a.asset_bucket, key: a.asset_key, mime: a.asset_mime };
      message.success('Dosya yüklendi.');
    })
    .catch((error) => {
      console.error(error);
      asset.value = null;
      message.error('Dosya yüklenemedi.');
    })
    .finally(() => {
      uploadBusy.value = false;
    });
  return false;
}

const canNext = computed(() => {
  if (step.value === 0) return !!asset.value;
  if (step.value === 1) return wf.value.name.trim() !== '' && (wf.value.allRegions || wf.value.regions.length > 0);
  return true;
});

function next() {
  if (canNext.value && step.value < 2) step.value += 1;
}
function prev() {
  if (step.value > 0) step.value -= 1;
}

async function submit() {
  if (!asset.value) {
    message.warning('Lütfen önce bir reklam dosyası yükleyin.');
    step.value = 0;
    return;
  }
  saving.value = true;
  try {
    const range = wf.value.range;
    await saveSponsor({
      name: wf.value.name.trim(),
      placement: wf.value.placement,
      placement_type: wf.value.placement === 'post_roll' ? 'outro' : 'intro',
      is_global: wf.value.allRegions,
      content_type: wf.value.content,
      target_regions: wf.value.allRegions ? [...REGION_LIST] : wf.value.regions,
      target_parts: [wf.value.content],
      asset_bucket: asset.value.bucket,
      asset_key: asset.value.key,
      asset_mime: asset.value.mime,
      priority: wf.value.priority,
      starts_at: range?.[0] ? range[0].startOf('day').format() : null,
      ends_at: range?.[1] ? range[1].endOf('day').format() : null,
    });
    if (editingId.value) {
      await deleteSponsor(editingId.value);
    }
    message.success(editingId.value ? 'Reklam güncellendi.' : 'Reklam oluşturuldu.');
    wizardOpen.value = false;
    await loadSponsors();
  } catch (error) {
    console.error(error);
    message.error('Reklam kaydedilemedi.');
  } finally {
    saving.value = false;
  }
}

onMounted(loadSponsors);
</script>

<template>
  <div class="spn">
    <header class="spn__bar">
      <div>
        <h1 class="spn__title">Sponsorlar</h1>
        <p class="spn__sub">{{ sponsors.length }} reklam kaydı</p>
      </div>
      <Button type="primary" @click="openCreate">+ Yeni Reklam</Button>
    </header>

    <div class="spn__filters ui-card">
      <Input v-model:value="search" placeholder="Reklam adı ara" allow-clear class="spn__search" />
      <Select v-model:value="partFilter" allow-clear placeholder="İçerik türü" :options="partOptions" class="spn__f" />
    </div>

    <!-- Desktop table -->
    <div class="spn__table ui-card">
      <table>
        <thead>
          <tr>
            <th>Reklam</th>
            <th>İçerik</th>
            <th>Sunum</th>
            <th>Hedef</th>
            <th>Kampanya</th>
            <th class="ta-r">İşlemler</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in filtered" :key="s.id">
            <td class="td-name">{{ s.sponsor_name }}</td>
            <td>{{ PART_LABELS[s.content_type] ?? s.content_type }}</td>
            <td>
              <span class="spn__chip" :class="s.placement_type === 'outro' ? 'is-info' : 'is-ok'">
                {{ placementLabel(s.placement_type) }}
              </span>
            </td>
            <td>
              <span class="spn__chip" :class="s.is_global ? 'is-brand' : 'is-muted'">
                {{ s.is_global ? 'Tüm Bölgeler' : s.region_name }}
              </span>
            </td>
            <td class="spn__date">{{ dateRange(s) }}</td>
            <td class="ta-r">
              <div class="spn__actions">
                <button class="spn__lnk" type="button" @click="openEdit(s)">Düzenle</button>
                <Popconfirm title="Reklamı silmek istiyor musunuz?" ok-text="Sil" cancel-text="Vazgeç" @confirm="removeSponsor(s.id)">
                  <button class="spn__lnk is-danger" type="button">Sil</button>
                </Popconfirm>
              </div>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="6" class="spn__empty">Kayıt bulunamadı.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div class="spn__cards">
      <article v-for="s in filtered" :key="s.id" class="spn__card ui-card">
        <div class="spn__card-top">
          <strong class="spn__card-name">{{ s.sponsor_name }}</strong>
          <span class="spn__chip" :class="s.placement_type === 'outro' ? 'is-info' : 'is-ok'">
            {{ placementLabel(s.placement_type) }}
          </span>
        </div>
        <div class="spn__tags">
          <span class="spn__chip is-muted">{{ PART_LABELS[s.content_type] ?? s.content_type }}</span>
          <span class="spn__chip" :class="s.is_global ? 'is-brand' : 'is-muted'">
            {{ s.is_global ? 'Tüm Bölgeler' : s.region_name }}
          </span>
        </div>
        <div class="spn__card-foot">
          <span class="spn__date">{{ dateRange(s) }}</span>
          <div class="spn__card-actions">
            <button class="spn__lnk" type="button" @click="openEdit(s)">Düzenle</button>
            <Popconfirm title="Silinsin mi?" ok-text="Sil" cancel-text="Vazgeç" @confirm="removeSponsor(s.id)">
              <button class="spn__lnk is-danger" type="button">Sil</button>
            </Popconfirm>
          </div>
        </div>
      </article>
      <p v-if="!filtered.length" class="spn__empty">Kayıt bulunamadı.</p>
    </div>

    <!-- Wizard modal -->
    <Modal
      v-model:open="wizardOpen"
      :title="editingId ? 'Reklamı Düzenle' : 'Yeni Reklam'"
      :width="560"
      :footer="null"
      destroy-on-close
    >
      <Steps :current="step" size="small" class="spn__steps">
        <Step title="Dosya" />
        <Step title="Hedefleme" />
        <Step title="Zamanlama" />
      </Steps>

      <!-- Step 0: file -->
      <div v-show="step === 0" class="spn__step">
        <Dragger :show-upload-list="false" :before-upload="onFile" accept=".mp3,.mp4,audio/*,video/*" class="spn__drop">
          <p class="spn__drop-mark">MP3 / MP4</p>
          <p class="spn__drop-title">{{ uploadBusy ? 'Yükleniyor…' : asset ? 'Dosya hazır' : 'Dosyayı buraya bırakın' }}</p>
          <p class="spn__drop-hint">{{ fileName || 'Reklam ses/video dosyasını seçin' }}</p>
        </Dragger>
      </div>

      <!-- Step 1: targeting -->
      <div v-show="step === 1" class="spn__step spn__form">
        <label>
          <span>Reklam Adı</span>
          <Input v-model:value="wf.name" placeholder="Örn. Gol Spor" />
        </label>
        <label>
          <span>İçerik Türü</span>
          <Select v-model:value="wf.content" :options="partOptions" style="width: 100%" />
        </label>
        <label>
          <span>Sunum</span>
          <RadioGroup v-model:value="wf.placement" :options="placementOptions" />
        </label>
        <label class="spn__form-row">
          <span>Tüm Bölgeler</span>
          <Switch v-model:checked="wf.allRegions" />
        </label>
        <label v-if="!wf.allRegions">
          <span>Hedef Bölgeler</span>
          <CheckboxGroup v-model:value="wf.regions" :options="regionOptions" class="spn__regions" />
        </label>
      </div>

      <!-- Step 2: timing -->
      <div v-show="step === 2" class="spn__step spn__form">
        <label>
          <span>Öncelik</span>
          <InputNumber v-model:value="wf.priority" :min="1" :max="999" style="width: 100%" />
        </label>
        <label>
          <span>Kampanya Tarihleri (opsiyonel)</span>
          <RangePicker v-model:value="rangeModel" :allow-empty="[true, true]" style="width: 100%" />
        </label>
        <p class="spn__hint">Tarih girilmezse reklam süresiz yayınlanır.</p>
      </div>

      <div class="spn__wizard-foot">
        <Button v-if="step > 0" @click="prev">Geri</Button>
        <span class="spn__spacer" />
        <Button v-if="step < 2" type="primary" :disabled="!canNext" @click="next">Devam</Button>
        <Button v-else type="primary" :loading="saving" @click="submit">Kaydet</Button>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
/* Faz PAGE-FIT: viewport-fit, sponsor tablosu içeride scroll. */
.spn {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: calc(100dvh - 72px);
  overflow: hidden;
  box-sizing: border-box;
}
.spn__table {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}
.spn__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
}
.spn__title {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.spn__sub {
  margin: 2px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.spn__filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--sp-3);
  padding: var(--sp-3);
}
.spn__search {
  flex: 1 1 200px;
}
.spn__f {
  flex: 0 1 170px;
  min-width: 140px;
}

.spn__table {
  display: none;
  overflow: hidden;
}
.spn__table table {
  width: 100%;
  border-collapse: collapse;
}
.spn__table th {
  text-align: left;
  padding: 13px 16px;
  font-size: var(--t-xs);
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--c-text-2);
  border-bottom: 1px solid var(--c-line);
  background: var(--c-surface-2);
}
.spn__table td {
  padding: 13px 16px;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  border-bottom: 1px solid var(--c-line);
}
.spn__table tr:last-child td {
  border-bottom: none;
}
.spn__table tbody tr:hover td {
  background: rgba(148, 163, 184, 0.05);
}
.td-name {
  font-weight: 700;
  color: var(--c-text) !important;
}
.ta-r {
  text-align: right;
}
.spn__date {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

.spn__actions,
.spn__card-actions {
  display: inline-flex;
  align-items: center;
  gap: 14px;
  justify-content: flex-end;
}
.spn__lnk {
  border: none;
  background: transparent;
  color: var(--c-info);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
  padding: 2px;
}
.spn__lnk:hover {
  text-decoration: underline;
}
.spn__lnk.is-danger {
  color: var(--c-bad);
}

.spn__chip {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 700;
}
.spn__chip.is-ok {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
}
.spn__chip.is-info {
  color: var(--c-info);
  background: rgba(96, 165, 250, 0.12);
}
.spn__chip.is-brand {
  color: var(--c-brand);
  background: rgba(225, 29, 72, 0.12);
}
.spn__chip.is-muted {
  color: var(--c-text-2);
  background: rgba(148, 163, 184, 0.1);
}

.spn__empty {
  padding: 28px;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

.spn__cards {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.spn__card {
  padding: var(--sp-4);
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.spn__card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
}
.spn__card-name {
  font-size: 15px;
  font-weight: 800;
  color: var(--c-text);
}
.spn__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.spn__card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  padding-top: var(--sp-3);
  border-top: 1px solid var(--c-line);
}

/* Wizard */
.spn__steps {
  margin-bottom: var(--sp-5);
}
.spn__step {
  min-height: 200px;
}
.spn__form {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.spn__form label {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.spn__form label span {
  font-size: var(--t-xs);
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--c-text-2);
}
.spn__form-row {
  flex-direction: row !important;
  align-items: center;
  justify-content: space-between;
}
.spn__regions {
  display: grid !important;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
}
.spn__hint {
  margin: 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.spn__drop {
  min-height: 200px;
  display: grid;
  place-items: center;
}
.spn__drop-mark {
  margin: 0 0 8px;
  font-weight: 800;
  font-size: var(--t-xs);
  letter-spacing: 0.1em;
  color: var(--c-info);
}
.spn__drop-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: var(--c-text);
}
.spn__drop-hint {
  margin: 4px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.spn__wizard-foot {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  margin-top: var(--sp-5);
  padding-top: var(--sp-4);
  border-top: 1px solid var(--c-line);
}
.spn__spacer {
  flex: 1;
}

@media (min-width: 768px) {
  .spn__cards {
    display: none;
  }
  .spn__table {
    display: block;
  }
}
</style>
