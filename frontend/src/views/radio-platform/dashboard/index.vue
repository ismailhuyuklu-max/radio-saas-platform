<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import { useRouter } from 'vue-router';

import {
  type AuditLogItem,
  type CalendarSlotItem,
  type MatrixGridCell,
  type RegionCode,
  type SponsorListItem,
  type StationItem,
  getAuditLogs,
  getMatrixStatus,
  getPlanning,
  getSponsors,
  getStations,
  normalizeMatrixPayload,
  REGION_LIST,
} from '#/api/modules/radioMedia';
import { getStoredUser } from '#/api/modules/auth';

import ConnectionBanner from '#/components/ui/ConnectionBanner.vue';
import StatCard from '#/components/ui/StatCard.vue';
import TurkeySvgMap, { type TurkeyRegionState } from '#/components/TurkeySvgMap.vue';

const router = useRouter();

const loading = ref(true);
const stations = ref<StationItem[]>([]);
const sponsors = ref<SponsorListItem[]>([]);
const calendar = ref<CalendarSlotItem[]>([]);
const matrixCells = ref<MatrixGridCell[]>([]);
const activity = ref<AuditLogItem[]>([]);
const healthy = ref(true);

const selectedRegion = ref<RegionCode>('marmara');

const today = dayjs();
const greeting = computed(() => {
  const h = Number(today.format('H'));
  if (h < 12) return 'Günaydın';
  if (h < 18) return 'İyi günler';
  return 'İyi akşamlar';
});
const userName = computed(() => getStoredUser()?.realName || getStoredUser()?.username || '');
const dateLabel = computed(() => today.format('DD MMMM YYYY, dddd'));

const activeStations = computed(() => stations.value.filter((s) => s.is_active ?? s.status === 'active').length);
const liveCells = computed(() => matrixCells.value.filter((c) => c.status === 'success').length);
const warnCells = computed(() => matrixCells.value.filter((c) => c.status === 'warning').length);
const todayPlans = computed(() => calendar.value.reduce((sum, s) => sum + s.items.length, 0));

const nextSlot = computed(() => {
  const nowHm = today.format('HH:mm');
  const withItems = calendar.value.filter((s) => s.items.length > 0);
  return withItems.find((s) => s.slot_time >= nowHm) ?? withItems[0] ?? null;
});

const regionStates = computed<Partial<Record<RegionCode, TurkeyRegionState>>>(() => {
  const map: Partial<Record<RegionCode, TurkeyRegionState>> = {};
  for (const region of REGION_LIST) {
    const cells = matrixCells.value.filter((c) => c.regionCode === region);
    const successCount = cells.filter((c) => c.status === 'success').length;
    const warningCount = cells.filter((c) => c.status === 'warning').length;
    const dangerCount = cells.filter((c) => c.status === 'danger').length;
    const dominantTone =
      successCount >= warningCount && successCount >= dangerCount
        ? 'success'
        : warningCount >= dangerCount
          ? 'warning'
          : 'danger';
    map[region] = {
      dominantTone,
      successCount,
      warningCount,
      dangerCount,
      totalCount: cells.length,
      latestUpdatedAt: cells.find((c) => c.updatedAt)?.updatedAt ?? null,
    };
  }
  return map;
});

const ACTION_LABELS: Record<string, string> = {
  login: 'Giriş yapıldı',
  create: 'Oluşturuldu',
  update: 'Güncellendi',
  delete: 'Silindi',
  toggle: 'Durum değişti',
  upload_media: 'Medya yüklendi',
  assign_sponsor: 'Sponsor atandı',
  generate_token: 'Erişim anahtarı üretildi',
};
const ENTITY_LABELS: Record<string, string> = {
  user: 'Kullanıcı',
  station: 'İstasyon',
  sponsor: 'Sponsor',
  media: 'Medya',
  content_plan: 'Plan',
};

function activityText(item: AuditLogItem) {
  const action = ACTION_LABELS[item.action] ?? item.action;
  const entity = ENTITY_LABELS[item.entity_type] ?? item.entity_type;
  return `${entity} · ${action}`;
}
function timeAgo(value: string) {
  const d = dayjs(value);
  const mins = today.diff(d, 'minute');
  if (mins < 1) return 'az önce';
  if (mins < 60) return `${mins} dk önce`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs} sa önce`;
  return d.format('DD.MM HH:mm');
}
function slotTone(status: string) {
  return status === 'success' ? 'ok' : status === 'warning' ? 'warn' : 'bad';
}
function slotLabel(status: string) {
  return status === 'success' ? 'Hazır' : status === 'warning' ? 'Bekliyor' : 'Eksik';
}

function onSelectRegion(code: RegionCode) {
  selectedRegion.value = code;
}
function openMatrix() {
  void router.push('/radio-platform/matrix');
}

async function load(): Promise<void> {
  loading.value = true;
  try {
    const [st, sp, pl, mx, au] = await Promise.allSettled([
      getStations(),
      getSponsors(),
      getPlanning({ date: today.format('YYYY-MM-DD') }),
      getMatrixStatus(),
      getAuditLogs({ limit: 6 }),
    ]);
    // Faz H1-5: response shape doğrulaması (NOC tipi HTML 200 sızıntısına
    // karşı). fulfilled olsa bile veri Array değilse / nesne değilse
    // varsayılana düş + healthy=false.
    if (st.status === 'fulfilled' && Array.isArray(st.value)) stations.value = st.value;
    else stations.value = [];
    if (sp.status === 'fulfilled' && Array.isArray(sp.value)) sponsors.value = sp.value;
    else sponsors.value = [];
    if (pl.status === 'fulfilled' && pl.value && typeof pl.value === 'object'
        && Array.isArray((pl.value as { calendar?: unknown }).calendar)) {
      calendar.value = (pl.value as { calendar: typeof calendar.value }).calendar;
    } else {
      calendar.value = [];
    }
    if (mx.status === 'fulfilled' && mx.value && typeof mx.value === 'object') {
      matrixCells.value = normalizeMatrixPayload(mx.value);
    } else {
      matrixCells.value = [];
    }
    if (au.status === 'fulfilled' && Array.isArray(au.value)) activity.value = au.value;
    else activity.value = [];

    // Healthy: 4 ana endpoint'in hepsi başarılı + beklenen shape.
    healthy.value =
      st.status === 'fulfilled' && Array.isArray(st.value)
      && mx.status === 'fulfilled' && !!mx.value
      && pl.status === 'fulfilled' && !!pl.value;
  } catch {
    healthy.value = false;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="dash">
    <ConnectionBanner
      v-if="!healthy && !loading"
      message="Bazı sistem servisleri erişilemiyor"
      detail="Genel bakış metrikleri eksik görünebilir. Docker Desktop'ı kontrol edin veya 'Tekrar Dene'."
      :busy="loading"
      @retry="load()"
    />
    <header class="dash__head">
      <div>
        <p class="dash__greet">{{ greeting }}{{ userName ? ', ' + userName : '' }}</p>
        <p class="dash__date">{{ dateLabel }}</p>
      </div>
      <span class="dash__health" :class="healthy ? 'is-ok' : 'is-bad'">
        <i /> {{ healthy ? 'Sistem sağlıklı' : 'Sistem uyarısı' }}
      </span>
    </header>

    <!-- KPI grid -->
    <section class="dash__kpis">
      <StatCard label="Aktif Radyo" :value="activeStations" :hint="`${stations.length} istasyon`" tone="info" icon="radio" />
      <StatCard label="Bölge" :value="REGION_LIST.length" hint="yayın bölgesi" tone="default" icon="map" />
      <StatCard label="Planlı Reklam" :value="sponsors.length" hint="sponsor kaydı" tone="brand" icon="megaphone" />
      <StatCard label="Bugünkü Plan" :value="todayPlans" hint="haber kuşağı" tone="default" icon="news" />
      <StatCard label="Canlı İçerik" :value="liveCells" :hint="`${warnCells} bekliyor`" tone="ok" icon="pulse" />
      <StatCard
        label="Sıradaki Yayın"
        :value="nextSlot ? nextSlot.slot_time : '—'"
        :hint="nextSlot ? (nextSlot.items[0]?.region_name ?? 'planlı') : 'plan yok'"
        tone="warn"
        icon="clock"
      />
    </section>

    <!-- Two-column body -->
    <section class="dash__body">
      <article class="ui-card dash__map">
        <div class="dash__card-head">
          <h2>Bölgesel Yayın Durumu</h2>
          <button type="button" class="dash__link" @click="openMatrix">Detaylı harita →</button>
        </div>
        <TurkeySvgMap
          :selected-region-code="selectedRegion"
          :region-states="regionStates"
          @select-region="onSelectRegion"
        />
      </article>

      <div class="dash__side">
        <article class="ui-card dash__panel">
          <div class="dash__card-head">
            <h2>Bugünün Yayın Akışı</h2>
            <span class="dash__muted">{{ todayPlans }} plan</span>
          </div>
          <ul class="dash__slots">
            <li v-for="slot in calendar" :key="slot.slot_time" class="dash__slot">
              <span class="dash__slot-time">{{ slot.slot_time }}</span>
              <span class="dash__slot-title">
                {{ slot.items[0]?.content_title || 'Plan yok' }}
                <em v-if="slot.items.length > 1">+{{ slot.items.length - 1 }}</em>
              </span>
              <span class="dash__chip" :class="`is-${slotTone(slot.status)}`">{{ slotLabel(slot.status) }}</span>
            </li>
          </ul>
        </article>

        <article class="ui-card dash__panel">
          <div class="dash__card-head">
            <h2>Son Hareketler</h2>
          </div>
          <ul class="dash__activity">
            <li v-for="(item, i) in activity" :key="i" class="dash__act">
              <span class="dash__act-dot" />
              <span class="dash__act-text">
                <strong>{{ activityText(item) }}</strong>
                <small>{{ item.actor_username }}</small>
              </span>
              <span class="dash__act-time">{{ timeAgo(item.created_at) }}</span>
            </li>
            <li v-if="!activity.length" class="dash__empty">Henüz hareket yok.</li>
          </ul>
        </article>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dash {
  display: flex;
  flex-direction: column;
  gap: var(--sp-5);
}

.dash__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}

.dash__greet {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}

.dash__date {
  margin: 2px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
  text-transform: capitalize;
}

.dash__health {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 13px;
  border-radius: 999px;
  font-size: var(--t-sm);
  font-weight: 700;
  border: 1px solid var(--c-line);
}

.dash__health i {
  width: 8px;
  height: 8px;
  border-radius: 999px;
}

.dash__health.is-ok {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.1);
}
.dash__health.is-ok i {
  background: var(--c-ok);
  box-shadow: 0 0 8px var(--c-ok);
}
.dash__health.is-bad {
  color: var(--c-bad);
  background: rgba(251, 113, 133, 0.1);
}
.dash__health.is-bad i {
  background: var(--c-bad);
}

.dash__kpis {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--sp-3);
}

.dash__body {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}

.dash__map,
.dash__panel {
  padding: var(--sp-4);
}

.dash__card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  margin-bottom: var(--sp-3);
}

.dash__card-head h2 {
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  letter-spacing: -0.01em;
  color: var(--c-text);
}

.dash__link {
  border: none;
  background: transparent;
  color: var(--c-brand);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
}
.dash__link:hover {
  text-decoration: underline;
}

.dash__muted {
  font-size: var(--t-sm);
  color: var(--c-text-3);
}

.dash__slots,
.dash__activity {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}

.dash__slot {
  display: grid;
  grid-template-columns: 48px 1fr auto;
  align-items: center;
  gap: var(--sp-3);
  padding: 10px 0;
  border-top: 1px solid var(--c-line);
}
.dash__slot:first-child {
  border-top: none;
}

.dash__slot-time {
  font-weight: 800;
  font-size: var(--t-sm);
  color: var(--c-text);
}

.dash__slot-title {
  font-size: var(--t-sm);
  color: var(--c-text-2);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dash__slot-title em {
  font-style: normal;
  color: var(--c-text-3);
  font-size: var(--t-xs);
}

.dash__chip {
  padding: 3px 9px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 700;
}
.dash__chip.is-ok {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
}
.dash__chip.is-warn {
  color: var(--c-warn);
  background: rgba(251, 191, 36, 0.12);
}
.dash__chip.is-bad {
  color: var(--c-bad);
  background: rgba(251, 113, 133, 0.12);
}

.dash__act {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  padding: 10px 0;
  border-top: 1px solid var(--c-line);
}
.dash__act:first-child {
  border-top: none;
}
.dash__act-dot {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: var(--c-info);
  flex-shrink: 0;
}
.dash__act-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.dash__act-text strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
}
.dash__act-text small {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.dash__act-time {
  font-size: var(--t-xs);
  color: var(--c-text-3);
  white-space: nowrap;
}
.dash__empty {
  padding: 14px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

.dash__side {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}

/* ---------- Tablet ---------- */
@media (min-width: 600px) {
  .dash__kpis {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

/* ---------- Desktop ---------- */
@media (min-width: 1024px) {
  .dash__kpis {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }

  .dash__body {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(320px, 1fr);
    align-items: start;
  }
}
</style>
