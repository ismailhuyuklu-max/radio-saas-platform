<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import {
  DatePicker,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Select,
  message,
} from 'ant-design-vue';

import {
  PART_LABELS,
  REGION_LABELS,
  REGION_LIST,
  PART_LIST,
  createAdCampaign,
  deleteAdCampaign,
  getAdTraffic,
  updateAdCampaign,
  type AdCampaign,
  type AdTrafficColumnsSummary,
  type AdTrafficSummary,
  type CampaignStatus,
  type PartCode,
  type PricingModel,
  type RegionCode,
} from '#/api/modules/radioMedia';
import StatCard from '#/components/ui/StatCard.vue';
import { extractApiError } from '#/utils/api-error';
import { formatCompact, formatCurrency, formatPercent } from '#/utils/format';

const { RangePicker } = DatePicker;

const loading = ref(false);
const saving = ref(false);
const campaigns = ref<AdCampaign[]>([]);
const summary = ref<AdTrafficSummary | null>(null);
const trafficSummary = ref<AdTrafficColumnsSummary | null>(null);

const MODEL_LABELS: Record<PricingModel, string> = {
  cpm: 'CPM (1000 gösterim)',
  cpp: 'CPP (yayın başına)',
  flat: 'Sabit ücret',
};
const STATUS_LABELS: Record<CampaignStatus, string> = {
  active: 'Aktif',
  paused: 'Duraklatıldı',
  ended: 'Bitti',
  draft: 'Taslak',
};

const modelOptions = (Object.keys(MODEL_LABELS) as PricingModel[]).map((v) => ({
  label: MODEL_LABELS[v],
  value: v,
}));
const statusOptions = (Object.keys(STATUS_LABELS) as CampaignStatus[]).map((v) => ({
  label: STATUS_LABELS[v],
  value: v,
}));
const regionOptions = REGION_LIST.map((r) => ({ label: REGION_LABELS[r], value: r }));
const partOptions = PART_LIST.map((p) => ({ label: PART_LABELS[p], value: p }));

const regionBreakdown = computed(() => {
  const map = summary.value?.revenue_by_region ?? {};
  const entries = REGION_LIST.map((r) => ({ region: r, value: map[r] ?? 0 })).filter(
    (e) => e.value > 0,
  );
  const max = Math.max(1, ...entries.map((e) => e.value));
  return entries
    .sort((a, b) => b.value - a.value)
    .map((e) => ({ ...e, pct: (e.value / max) * 100 }));
});

const modelBreakdown = computed(() => {
  const map = summary.value?.revenue_by_model ?? {};
  return (Object.keys(MODEL_LABELS) as PricingModel[])
    .map((m) => ({ model: m, value: map[m] ?? 0 }))
    .filter((e) => e.value > 0);
});

async function load() {
  loading.value = true;
  try {
    const res = await getAdTraffic();
    campaigns.value = res?.campaigns ?? [];
    summary.value = res?.summary ?? null;
    trafficSummary.value = res?.traffic_summary ?? null;
  } catch (error) {
    message.error(extractApiError(error) ?? 'Reklam verisi alınamadı.');
  } finally {
    loading.value = false;
  }
}

// --- create / edit --------------------------------------------------------

const modalOpen = ref(false);
const editingId = ref<string | null>(null);
const form = ref<{
  advertiser_name: string;
  pricing_model: PricingModel;
  rate: number;
  budget: number;
  spots_per_day: number;
  target_regions: RegionCode[];
  target_parts: PartCode[];
  status: CampaignStatus;
}>(blankForm());
const range = ref<[Dayjs, Dayjs]>([dayjs(), dayjs().add(30, 'day')]);

function blankForm() {
  return {
    advertiser_name: '',
    pricing_model: 'cpm' as PricingModel,
    rate: 50,
    budget: 100000,
    spots_per_day: 4,
    target_regions: ['marmara'] as RegionCode[],
    target_parts: ['news'] as PartCode[],
    status: 'active' as CampaignStatus,
  };
}

const rateHint = computed(() => {
  switch (form.value.pricing_model) {
    case 'cpm':
      return '1000 gösterim başına ücret';
    case 'cpp':
      return 'Her yayın (spot) başına ücret';
    default:
      return 'Sabit kampanya — bütçe kadar gelir';
  }
});

function openCreate() {
  editingId.value = null;
  form.value = blankForm();
  range.value = [dayjs(), dayjs().add(30, 'day')];
  modalOpen.value = true;
}

function openEdit(c: AdCampaign) {
  editingId.value = c.id;
  form.value = {
    advertiser_name: c.advertiser_name,
    pricing_model: c.pricing_model,
    rate: c.rate,
    budget: c.budget,
    spots_per_day: c.spots_per_day,
    target_regions: [...c.target_regions],
    target_parts: [...c.target_parts],
    status: c.status,
  };
  range.value = [dayjs(c.starts_at), dayjs(c.ends_at)];
  modalOpen.value = true;
}

async function submit() {
  if (!form.value.advertiser_name.trim()) {
    message.warning('Reklamveren adı gerekli.');
    return;
  }
  if (!form.value.target_regions.length) {
    message.warning('En az bir bölge seçin.');
    return;
  }
  saving.value = true;
  const payload = {
    ...form.value,
    advertiser_name: form.value.advertiser_name.trim(),
    starts_at: range.value[0].format('YYYY-MM-DD'),
    ends_at: range.value[1].format('YYYY-MM-DD'),
  };
  try {
    if (editingId.value) {
      await updateAdCampaign(editingId.value, payload);
      message.success('Kampanya güncellendi.');
    } else {
      await createAdCampaign(payload);
      message.success('Kampanya oluşturuldu.');
    }
    modalOpen.value = false;
    await load();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Kayıt başarısız.');
  } finally {
    saving.value = false;
  }
}

async function remove(c: AdCampaign) {
  try {
    await deleteAdCampaign(c.id);
    message.success('Kampanya silindi.');
    await load();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Silinemedi.');
  }
}

onMounted(load);
</script>

<template>
  <div class="ad">
    <header class="ad__head">
      <div>
        <h1>Reklam Trafik Merkezi</h1>
        <p class="ad__sub">Kampanya geliri CPM/CPP/sabit modellerine göre projeksiyon</p>
      </div>
      <button type="button" class="ad__primary" @click="openCreate">+ Yeni Kampanya</button>
    </header>

    <!-- Revenue KPIs -->
    <section v-if="summary" class="ad__kpis">
      <StatCard
        label="Gerçekleşen Gelir"
        :value="formatCurrency(summary.total_delivered_revenue)"
        hint="bugüne kadar"
        tone="ok"
        icon="megaphone"
      />
      <StatCard
        label="Projeksiyon Gelir"
        :value="formatCurrency(summary.total_projected_revenue)"
        hint="kampanya sonu"
        tone="brand"
        icon="pulse"
      />
      <StatCard
        label="Aktif Kampanya"
        :value="summary.active_campaigns"
        :hint="`${summary.campaign_count} toplam`"
        tone="info"
        icon="news"
      />
      <StatCard
        label="Tahmini Gösterim"
        :value="formatCompact(summary.total_projected_impressions)"
        hint="projeksiyon"
        tone="info"
        icon="radio"
      />
      <StatCard
        label="Ortalama CPM"
        :value="formatCurrency(summary.avg_cpm)"
        hint="1000 gösterim"
        tone="default"
        icon="clock"
      />
      <StatCard
        label="Bütçe Kullanımı"
        :value="formatPercent(summary.budget_used_pct)"
        :hint="formatCurrency(summary.total_budget)"
        :tone="summary.budget_used_pct > 90 ? 'warn' : 'default'"
        icon="map"
      />
    </section>

    <section class="ad__body">
      <!-- Region revenue breakdown -->
      <article class="ui-card ad__panel">
        <div class="ad__panel-head"><h2>Bölgeye Göre Gelir</h2></div>
        <ul v-if="regionBreakdown.length" class="ad__bars">
          <li v-for="row in regionBreakdown" :key="row.region" class="ad__bar-row">
            <span class="ad__bar-label">{{ REGION_LABELS[row.region as RegionCode] }}</span>
            <span class="ad__bar-track"><i :style="{ width: row.pct + '%' }" /></span>
            <span class="ad__bar-val">{{ formatCurrency(row.value) }}</span>
          </li>
        </ul>
        <p v-else class="ad__empty">Henüz gelir verisi yok.</p>

        <div v-if="modelBreakdown.length" class="ad__models">
          <span v-for="m in modelBreakdown" :key="m.model" class="ad__model-chip">
            {{ MODEL_LABELS[m.model] }}: <strong>{{ formatCurrency(m.value) }}</strong>
          </span>
        </div>
      </article>

      <!-- Campaign list -->
      <article class="ui-card ad__panel">
        <div class="ad__panel-head">
          <h2>Kampanyalar — Yayın Trafiği</h2>
          <span class="ad__muted">{{ campaigns.length }} kayıt</span>
        </div>

        <!-- Tamamlanan / Kalan / Kaçırılan roll-up -->
        <div v-if="trafficSummary" class="ad__traffic-sum">
          <span class="ad__ts ad__ts--plan">
            <em>{{ formatCompact(trafficSummary.planned) }}</em>Planlanan
          </span>
          <span class="ad__ts ad__ts--aired">
            <em>{{ formatCompact(trafficSummary.aired) }}</em>Tamamlanan
          </span>
          <span class="ad__ts ad__ts--rem">
            <em>{{ formatCompact(trafficSummary.remaining) }}</em>Kalan
          </span>
          <span class="ad__ts ad__ts--miss">
            <em>{{ formatCompact(trafficSummary.missed) }}</em>Kaçırılan
          </span>
          <span class="ad__ts ad__ts--rate">
            <em>{{ formatPercent(trafficSummary.completion_rate * 100) }}</em>Tamamlanma
          </span>
        </div>
        <div v-if="campaigns.length" class="ad__list">
          <div v-for="c in campaigns" :key="c.id" class="ad__camp">
            <div class="ad__camp-main">
              <div class="ad__camp-top">
                <strong>{{ c.advertiser_name }}</strong>
                <span class="ad__status" :class="`is-${c.status}`">{{ STATUS_LABELS[c.status] }}</span>
              </div>
              <div class="ad__camp-meta">
                <span>{{ MODEL_LABELS[c.pricing_model] }}</span>
                <span>·</span>
                <span>{{ c.target_regions.length }} bölge</span>
                <span>·</span>
                <span>{{ c.spots_per_day }} spot/gün</span>
              </div>
              <!-- Reklam trafik kolonları -->
              <div v-if="c.traffic" class="ad__traffic">
                <span class="ad__tcol ad__tcol--plan" title="Planlanan spot">
                  ◷ {{ c.traffic.planned }}
                </span>
                <span class="ad__tcol ad__tcol--aired" title="Tamamlanan (yayınlanan)">
                  ✓ {{ c.traffic.aired }}
                </span>
                <span class="ad__tcol ad__tcol--rem" title="Kalan">
                  ◴ {{ c.traffic.remaining }}
                </span>
                <span
                  v-if="c.traffic.missed > 0"
                  class="ad__tcol ad__tcol--miss"
                  title="Kaçırılan (geçmiş, yayınlanmadı)"
                >
                  ✕ {{ c.traffic.missed }}
                </span>
                <span class="ad__tbar" :title="`Tamamlanma %${Math.round(c.traffic.completion_rate * 100)}`">
                  <i
                    class="ad__tbar-aired"
                    :style="{ width: c.traffic.completion_rate * 100 + '%' }"
                  />
                  <i
                    v-if="c.traffic.planned > 0"
                    class="ad__tbar-miss"
                    :style="{ width: (c.traffic.missed / c.traffic.planned) * 100 + '%' }"
                  />
                </span>
              </div>
            </div>
            <div class="ad__camp-rev">
              <span class="ad__camp-rev-val">
                {{ formatCurrency(c.metrics?.delivered_revenue ?? 0) }}
                <em class="ad__src" :class="c.metrics?.has_actuals ? 'is-real' : 'is-est'">
                  {{ c.metrics?.has_actuals ? 'gerçek' : 'tahmini' }}
                </em>
              </span>
              <span class="ad__camp-rev-sub">/ {{ formatCurrency(c.metrics?.projected_revenue ?? 0) }}</span>
              <span class="ad__camp-bud" :class="{ 'is-over': c.metrics?.over_budget }">
                bütçe {{ formatPercent(c.metrics?.budget_used_pct ?? 0) }}
              </span>
            </div>
            <div class="ad__camp-act">
              <button type="button" class="ad__lnk" @click="openEdit(c)">Düzenle</button>
              <Popconfirm title="Kampanya silinsin mi?" ok-text="Sil" cancel-text="Vazgeç" @confirm="remove(c)">
                <button type="button" class="ad__lnk ad__lnk--danger">Sil</button>
              </Popconfirm>
            </div>
          </div>
        </div>
        <p v-else class="ad__empty">Henüz kampanya yok. “Yeni Kampanya” ile ekleyin.</p>
      </article>
    </section>

    <p class="ad__note">
      Not: Yayın kaydı (airing) varsa gelir <strong>gerçek</strong> verilerden hesaplanır;
      yoksa planlanan spot × bölgesel tahmini erişimden <strong>tahmini</strong> projeksiyon
      kullanılır. Yayın otomasyonu her reklam çalındığında airing kaydı gönderebilir.
    </p>

    <!-- Create / edit modal -->
    <Modal
      v-model:open="modalOpen"
      :title="editingId ? 'Kampanyayı Düzenle' : 'Yeni Kampanya'"
      :confirm-loading="saving"
      ok-text="Kaydet"
      cancel-text="Vazgeç"
      @ok="submit"
    >
      <div class="ad__form">
        <label>
          <span>Reklamveren</span>
          <Input v-model:value="form.advertiser_name" placeholder="örn. Marka A.Ş." />
        </label>
        <label>
          <span>Fiyatlandırma Modeli</span>
          <Select v-model:value="form.pricing_model" :options="modelOptions" />
        </label>
        <div class="ad__form-row">
          <label>
            <span>Ücret ({{ rateHint }})</span>
            <InputNumber v-model:value="form.rate" :min="0" style="width: 100%" />
          </label>
          <label>
            <span>Bütçe (₺)</span>
            <InputNumber v-model:value="form.budget" :min="0" :step="1000" style="width: 100%" />
          </label>
        </div>
        <label>
          <span>Günlük Spot Sayısı</span>
          <InputNumber v-model:value="form.spots_per_day" :min="0" style="width: 100%" />
        </label>
        <label>
          <span>Hedef Bölgeler</span>
          <Select v-model:value="form.target_regions" mode="multiple" :options="regionOptions" />
        </label>
        <label>
          <span>Hedef İçerik Türleri</span>
          <Select v-model:value="form.target_parts" mode="multiple" :options="partOptions" />
        </label>
        <label>
          <span>Kampanya Dönemi</span>
          <RangePicker v-model:value="range" :allow-clear="false" style="width: 100%" />
        </label>
        <label>
          <span>Durum</span>
          <Select v-model:value="form.status" :options="statusOptions" />
        </label>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.ad {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.ad__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}
.ad__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.ad__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.ad__primary {
  padding: 10px 16px;
  border: none;
  border-radius: 10px;
  background: var(--c-brand);
  color: #fff;
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
}
.ad__primary:hover {
  filter: brightness(1.08);
}

.ad__kpis {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--sp-3);
}

.ad__body {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.ad__panel {
  padding: var(--sp-4);
}
.ad__panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--sp-3);
}
.ad__panel-head h2 {
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.ad__muted {
  font-size: var(--t-sm);
  color: var(--c-text-3);
}

.ad__bars {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ad__bar-row {
  display: grid;
  grid-template-columns: 110px 1fr auto;
  align-items: center;
  gap: 10px;
}
.ad__bar-label {
  font-size: var(--t-sm);
  color: var(--c-text-2);
  font-weight: 600;
}
.ad__bar-track {
  height: 10px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.12);
  overflow: hidden;
}
.ad__bar-track i {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--c-brand), #fb7185);
}
.ad__bar-val {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}

.ad__models {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: var(--sp-4);
  padding-top: var(--sp-3);
  border-top: 1px solid var(--c-line);
}
.ad__model-chip {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.ad__model-chip strong {
  color: var(--c-text);
}

/* Traffic roll-up strip */
.ad__traffic-sum {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: var(--sp-2);
  margin-bottom: var(--sp-3);
  padding: 10px;
  border-radius: var(--r-sm);
  background: rgba(148, 163, 184, 0.06);
  border: 1px solid var(--c-line);
}
.ad__ts {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  font-size: 10px;
  color: var(--c-text-3);
  text-align: center;
}
.ad__ts em {
  font-style: normal;
  font-size: var(--t-h3);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: var(--c-text);
}
.ad__ts--aired em {
  color: var(--c-ok);
}
.ad__ts--rem em {
  color: var(--c-info);
}
.ad__ts--miss em {
  color: var(--c-bad);
}
.ad__ts--rate em {
  color: var(--c-warn);
}

/* Per-campaign traffic columns */
.ad__traffic {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
  flex-wrap: wrap;
}
.ad__tcol {
  font-size: 11px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  padding: 1px 7px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.12);
  color: var(--c-text-2);
}
.ad__tcol--aired {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.14);
}
.ad__tcol--rem {
  color: var(--c-info);
  background: rgba(96, 165, 250, 0.14);
}
.ad__tcol--miss {
  color: var(--c-bad);
  background: rgba(251, 113, 133, 0.16);
}
.ad__tbar {
  position: relative;
  display: flex;
  height: 6px;
  min-width: 90px;
  flex: 1;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.16);
  overflow: hidden;
}
.ad__tbar-aired {
  height: 100%;
  background: var(--c-ok);
}
.ad__tbar-miss {
  height: 100%;
  background: var(--c-bad);
  opacity: 0.7;
}

.ad__list {
  display: flex;
  flex-direction: column;
}
.ad__camp {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: var(--sp-3);
  align-items: center;
  padding: 12px 0;
  border-top: 1px solid var(--c-line);
}
.ad__camp:first-child {
  border-top: none;
}
.ad__camp-top {
  display: flex;
  align-items: center;
  gap: 9px;
}
.ad__camp-top strong {
  font-size: var(--t-base);
  font-weight: 700;
  color: var(--c-text);
}
.ad__camp-meta {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 3px;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.ad__camp-rev {
  text-align: right;
  display: flex;
  flex-direction: column;
}
.ad__camp-rev-val {
  font-size: var(--t-base);
  font-weight: 800;
  color: var(--c-ok);
  font-variant-numeric: tabular-nums;
}
.ad__camp-rev-sub {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.ad__src {
  font-style: normal;
  font-size: 9px;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 1px 6px;
  border-radius: 999px;
  vertical-align: middle;
}
.ad__src.is-real {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.14);
}
.ad__src.is-est {
  color: var(--c-text-3);
  background: rgba(148, 163, 184, 0.14);
}
.ad__camp-bud {
  font-size: 10px;
  color: var(--c-text-3);
}
.ad__camp-bud.is-over {
  color: var(--c-bad);
  font-weight: 700;
}
.ad__camp-act {
  display: flex;
  gap: 10px;
}
.ad__lnk {
  border: none;
  background: transparent;
  color: var(--c-brand);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
}
.ad__lnk--danger {
  color: var(--c-bad);
}
.ad__lnk:hover {
  text-decoration: underline;
}

.ad__status {
  padding: 2px 9px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 800;
}
.ad__status.is-active {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
}
.ad__status.is-paused {
  color: var(--c-warn);
  background: rgba(251, 191, 36, 0.12);
}
.ad__status.is-ended,
.ad__status.is-draft {
  color: var(--c-text-3);
  background: rgba(148, 163, 184, 0.12);
}

.ad__empty {
  padding: 18px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}
.ad__note {
  margin: 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

.ad__form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ad__form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.ad__form label {
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.ad__form label span {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-2);
}

@media (min-width: 600px) {
  .ad__kpis {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (min-width: 1024px) {
  .ad__kpis {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }
  .ad__body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
    align-items: start;
  }
}
</style>
