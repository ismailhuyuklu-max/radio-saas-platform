<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import dayjs from 'dayjs';
import { useRouter } from 'vue-router';

import {
  PART_LABELS,
  getPlanning,
  getSponsors,
  getStations,
  type ContentPlanItem,
  type SponsorListItem,
  type StationItem,
} from '#/api/modules/radioMedia';
import ConnectionBanner from '#/components/ui/ConnectionBanner.vue';
import StatCard from '#/components/ui/StatCard.vue';
import {
  NEWS_SLOTS,
  activeRegions,
  computeMetrics,
  currentSlot,
  deriveAlerts,
  livePlans,
  slotToMinutes,
  type OpsAlert,
} from '#/utils/operations';

const router = useRouter();

const loading = ref(true);
const healthy = ref(true);
const lastUpdated = ref(dayjs());
const now = ref(dayjs());

const stations = ref<StationItem[]>([]);
const sponsors = ref<SponsorListItem[]>([]);
const plans = ref<ContentPlanItem[]>([]);

let clockTimer: ReturnType<typeof setInterval> | undefined;
let pollTimer: ReturnType<typeof setInterval> | undefined;

const clock = computed(() => now.value.format('HH:mm:ss'));
const dateLabel = computed(() => now.value.format('DD MMMM YYYY, dddd'));
const slotNow = computed(() => currentSlot(now.value));
const updatedLabel = computed(() => lastUpdated.value.format('HH:mm:ss'));

const metrics = computed(() =>
  computeMetrics(stations.value, plans.value, sponsors.value, now.value),
);
const alerts = computed<OpsAlert[]>(() =>
  deriveAlerts(stations.value, plans.value, sponsors.value, now.value),
);
const live = computed(() => livePlans(plans.value, now.value));

const missedTone = computed(() => (metrics.value.missedBroadcasts > 0 ? 'bad' : 'ok'));
const alertTone = computed(() => {
  if (alerts.value.some((a) => a.severity === 'critical')) return 'bad';
  if (alerts.value.length > 0) return 'warn';
  return 'ok';
});

interface FlowSlot {
  slot: string;
  state: 'live' | 'done' | 'partial' | 'missed' | 'scheduled';
  covered: number;
  expected: number;
}

const flow = computed<FlowSlot[]>(() => {
  const regions = activeRegions(stations.value).length;
  const curr = slotNow.value;
  const currMin = curr ? slotToMinutes(curr) : -1;
  return NEWS_SLOTS.map((slot) => {
    const news = plans.value.filter(
      (p) =>
        (p.slot_time || '').slice(0, 5) === slot &&
        p.part_code === 'news' &&
        (p.status === 'published' || p.status === 'running'),
    );
    const covered = new Set(news.map((p) => p.region_code)).size;
    let state: FlowSlot['state'];
    if (slot === curr) {
      state = 'live';
    } else if (slotToMinutes(slot) < currMin) {
      state = covered === 0 ? 'missed' : covered >= regions ? 'done' : 'partial';
    } else {
      state = 'scheduled';
    }
    return { slot, state, covered, expected: regions };
  });
});

const FLOW_LABELS: Record<FlowSlot['state'], string> = {
  live: 'Canlı',
  done: 'Tamam',
  partial: 'Kısmi',
  missed: 'Kaçırıldı',
  scheduled: 'Planlı',
};

async function refresh() {
  try {
    const [st, sp, pl] = await Promise.allSettled([
      getStations(),
      getSponsors(),
      getPlanning({ date: now.value.format('YYYY-MM-DD') }),
    ]);
    // Faz H1-5: response shape doğrulaması (NOC tipi HTML 200 sızıntısına karşı)
    if (st.status === 'fulfilled' && Array.isArray(st.value)) stations.value = st.value;
    else stations.value = [];
    if (sp.status === 'fulfilled' && Array.isArray(sp.value)) sponsors.value = sp.value;
    else sponsors.value = [];
    if (pl.status === 'fulfilled' && pl.value && typeof pl.value === 'object'
        && Array.isArray((pl.value as { plans?: unknown }).plans)) {
      plans.value = (pl.value as { plans: typeof plans.value }).plans;
    } else {
      plans.value = [];
    }
    healthy.value =
      st.status === 'fulfilled' && Array.isArray(st.value)
      && pl.status === 'fulfilled' && !!pl.value;
    lastUpdated.value = dayjs();
  } catch {
    healthy.value = false;
  } finally {
    loading.value = false;
  }
}

function go(path: string) {
  void router.push(path);
}

onMounted(async () => {
  await refresh();
  clockTimer = setInterval(() => {
    now.value = dayjs();
  }, 1000);
  pollTimer = setInterval(() => {
    void refresh();
  }, 30_000);
});

onUnmounted(() => {
  if (clockTimer) clearInterval(clockTimer);
  if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
  <div class="ops">
    <ConnectionBanner
      v-if="!healthy"
      message="Yayın merkezi servislerine ulaşılamıyor"
      detail="İstasyon ve plan verileri eksik olabilir. Backend / Docker durumunu kontrol edin."
      :busy="loading"
      @retry="refresh()"
    />
    <header class="ops__head">
      <div class="ops__title">
        <h1>Yayın Operasyon Merkezi</h1>
        <p class="ops__date">{{ dateLabel }}</p>
      </div>
      <div class="ops__live">
        <span class="ops__clock"><i class="ops__pulse" />{{ clock }}</span>
        <span v-if="slotNow" class="ops__slot">Aktif kuşak · {{ slotNow }}</span>
        <span v-else class="ops__slot ops__slot--off">Kuşak dışı</span>
        <span class="ops__health" :class="healthy ? 'is-ok' : 'is-bad'">
          <i />{{ healthy ? 'Canlı' : 'Bağlantı sorunu' }}
        </span>
      </div>
    </header>

    <!-- Operasyon metrikleri -->
    <section class="ops__kpis">
      <StatCard
        label="Aktif Radyo"
        :value="metrics.activeStations"
        :hint="`${metrics.totalStations} istasyon`"
        tone="info"
        icon="radio"
      />
      <StatCard
        label="Aktif Bölge"
        :value="metrics.activeRegions"
        hint="7 bölgede"
        tone="info"
        icon="map"
      />
      <StatCard
        label="Canlı Yayınlar"
        :value="metrics.onAir"
        hint="şu an yayında"
        :tone="metrics.onAir > 0 ? 'ok' : 'default'"
        icon="pulse"
      />
      <StatCard
        label="Yayında Haber"
        :value="metrics.newsOnAir"
        hint="haber bülteni"
        :tone="metrics.newsOnAir > 0 ? 'ok' : 'default'"
        icon="news"
      />
      <StatCard
        label="Yayında Reklam"
        :value="metrics.liveSponsors"
        hint="aktif sponsor"
        tone="brand"
        icon="megaphone"
      />
      <StatCard
        label="Bekleyen İçerik"
        :value="metrics.pendingContent"
        hint="taslak plan"
        :tone="metrics.pendingContent > 0 ? 'warn' : 'default'"
        icon="clock"
      />
      <StatCard
        label="Kaçırılan Yayın"
        :value="metrics.missedBroadcasts"
        hint="geçmiş slot"
        :tone="missedTone"
        icon="clock"
      />
      <StatCard
        label="Sistem Uyarısı"
        :value="metrics.alertCount"
        hint="aktif uyarı"
        :tone="alertTone"
        icon="pulse"
      />
    </section>

    <section class="ops__body">
      <!-- Şu an yayında -->
      <article class="ui-card ops__panel">
        <div class="ops__panel-head">
          <h2><span class="ops__dot ops__dot--live" />Şu An Yayında</h2>
          <span class="ops__muted">{{ live.length }} yayın</span>
        </div>
        <ul v-if="live.length" class="ops__list">
          <li v-for="item in live" :key="item.id" class="ops__live-row">
            <span class="ops__live-bar" />
            <div class="ops__live-main">
              <strong>{{ item.content_title }}</strong>
              <small>{{ item.region_name }} · {{ PART_LABELS[item.part_code] }}</small>
            </div>
            <span class="ops__badge is-live">{{ item.status === 'running' ? 'CANLI' : 'YAYINDA' }}</span>
          </li>
        </ul>
        <div v-else class="ops__empty">
          {{ slotNow ? 'Bu kuşakta canlı yayın bulunmuyor.' : 'Yayın kuşağı dışındasınız (08:00–20:00).' }}
        </div>

        <!-- Bugünün akışı -->
        <div class="ops__flow-head">Bugünün Haber Akışı</div>
        <div class="ops__flow">
          <div
            v-for="f in flow"
            :key="f.slot"
            class="ops__flow-cell"
            :class="`is-${f.state}`"
            :title="`${f.slot} · ${f.covered}/${f.expected} bölge`"
          >
            <span class="ops__flow-time">{{ f.slot }}</span>
            <span class="ops__flow-state">{{ FLOW_LABELS[f.state] }}</span>
            <span class="ops__flow-cov">{{ f.covered }}/{{ f.expected }}</span>
          </div>
        </div>
      </article>

      <!-- Sistem uyarıları -->
      <article class="ui-card ops__panel">
        <div class="ops__panel-head">
          <h2>Sistem Uyarıları</h2>
          <span class="ops__muted">son güncelleme {{ updatedLabel }}</span>
        </div>
        <ul v-if="alerts.length" class="ops__list">
          <li v-for="alert in alerts" :key="alert.id" class="ops__alert" :class="`is-${alert.severity}`">
            <span class="ops__alert-icon" />
            <div class="ops__alert-main">
              <strong>{{ alert.title }}</strong>
              <small>{{ alert.detail }}</small>
            </div>
          </li>
        </ul>
        <div v-else class="ops__empty ops__empty--ok">
          ✓ Tüm sistemler normal. Aktif uyarı yok.
        </div>

        <div class="ops__quick">
          <button type="button" @click="go('/radio-platform/planning')">Planlamaya git →</button>
          <button type="button" @click="go('/radio-platform/matrix')">Bölgesel durum →</button>
        </div>
      </article>
    </section>
  </div>
</template>

<style scoped>
.ops {
  display: flex;
  flex-direction: column;
  gap: var(--sp-5);
}

.ops__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}

.ops__title h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}

.ops__date {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
  text-transform: capitalize;
}

.ops__live {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.ops__clock {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  border-radius: 12px;
  background: rgba(15, 23, 42, 0.6);
  border: 1px solid var(--c-line);
  font-family: 'Plus Jakarta Sans', 'Inter', monospace;
  font-size: 18px;
  font-weight: 800;
  letter-spacing: 0.02em;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}

.ops__pulse {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  background: var(--c-bad);
  box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.6);
  animation: opsPulse 1.6s infinite;
}

@keyframes opsPulse {
  0% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.55); }
  70% { box-shadow: 0 0 0 8px rgba(225, 29, 72, 0); }
  100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0); }
}

.ops__slot {
  padding: 7px 12px;
  border-radius: 999px;
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-brand);
  background: rgba(225, 29, 72, 0.1);
  border: 1px solid rgba(225, 29, 72, 0.25);
}
.ops__slot--off {
  color: var(--c-text-3);
  background: rgba(148, 163, 184, 0.08);
  border-color: var(--c-line);
}

.ops__health {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 7px 13px;
  border-radius: 999px;
  font-size: var(--t-sm);
  font-weight: 700;
  border: 1px solid var(--c-line);
}
.ops__health i {
  width: 8px;
  height: 8px;
  border-radius: 999px;
}
.ops__health.is-ok {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.1);
}
.ops__health.is-ok i {
  background: var(--c-ok);
  box-shadow: 0 0 8px var(--c-ok);
}
.ops__health.is-bad {
  color: var(--c-bad);
  background: rgba(251, 113, 133, 0.1);
}
.ops__health.is-bad i {
  background: var(--c-bad);
}

.ops__kpis {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--sp-3);
}

.ops__body {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}

.ops__panel {
  padding: var(--sp-4);
}

.ops__panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  margin-bottom: var(--sp-3);
}

.ops__panel-head h2 {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  letter-spacing: -0.01em;
  color: var(--c-text);
}

.ops__dot {
  width: 9px;
  height: 9px;
  border-radius: 999px;
}
.ops__dot--live {
  background: var(--c-bad);
  box-shadow: 0 0 8px var(--c-bad);
  animation: opsPulse 1.6s infinite;
}

.ops__muted {
  font-size: var(--t-sm);
  color: var(--c-text-3);
}

.ops__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}

.ops__live-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 0;
  border-top: 1px solid var(--c-line);
}
.ops__live-row:first-child {
  border-top: none;
}
.ops__live-bar {
  width: 3px;
  align-self: stretch;
  border-radius: 3px;
  background: var(--c-ok);
}
.ops__live-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ops__live-main strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ops__live-main small {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

.ops__badge {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.06em;
}
.ops__badge.is-live {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.14);
}

.ops__empty {
  padding: 18px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}
.ops__empty--ok {
  color: var(--c-ok);
}

.ops__flow-head {
  margin: var(--sp-4) 0 10px;
  font-size: var(--t-xs);
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--c-text-3);
}

.ops__flow {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 6px;
}

.ops__flow-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  padding: 9px 4px;
  border-radius: 10px;
  border: 1px solid var(--c-line);
  background: rgba(148, 163, 184, 0.05);
}
.ops__flow-time {
  font-size: 12px;
  font-weight: 800;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}
.ops__flow-state {
  font-size: 10px;
  font-weight: 700;
}
.ops__flow-cov {
  font-size: 10px;
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
}
.ops__flow-cell.is-live {
  border-color: rgba(225, 29, 72, 0.5);
  background: rgba(225, 29, 72, 0.12);
}
.ops__flow-cell.is-live .ops__flow-state {
  color: var(--c-brand);
}
.ops__flow-cell.is-done {
  border-color: rgba(52, 211, 153, 0.4);
  background: rgba(52, 211, 153, 0.1);
}
.ops__flow-cell.is-done .ops__flow-state {
  color: var(--c-ok);
}
.ops__flow-cell.is-partial {
  border-color: rgba(251, 191, 36, 0.4);
  background: rgba(251, 191, 36, 0.1);
}
.ops__flow-cell.is-partial .ops__flow-state {
  color: var(--c-warn);
}
.ops__flow-cell.is-missed {
  border-color: rgba(251, 113, 133, 0.4);
  background: rgba(251, 113, 133, 0.1);
}
.ops__flow-cell.is-missed .ops__flow-state {
  color: var(--c-bad);
}
.ops__flow-cell.is-scheduled .ops__flow-state {
  color: var(--c-text-3);
}

.ops__alert {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  padding: 11px 0;
  border-top: 1px solid var(--c-line);
}
.ops__alert:first-child {
  border-top: none;
}
.ops__alert-icon {
  width: 8px;
  height: 8px;
  margin-top: 5px;
  border-radius: 999px;
  flex-shrink: 0;
}
.ops__alert.is-critical .ops__alert-icon {
  background: var(--c-bad);
  box-shadow: 0 0 8px var(--c-bad);
}
.ops__alert.is-warning .ops__alert-icon {
  background: var(--c-warn);
}
.ops__alert.is-info .ops__alert-icon {
  background: var(--c-info);
}
.ops__alert-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ops__alert-main strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
}
.ops__alert-main small {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

.ops__quick {
  display: flex;
  gap: 10px;
  margin-top: var(--sp-4);
  padding-top: var(--sp-3);
  border-top: 1px solid var(--c-line);
  flex-wrap: wrap;
}
.ops__quick button {
  padding: 8px 14px;
  border: 1px solid var(--c-line);
  border-radius: 10px;
  background: transparent;
  color: var(--c-brand);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
  transition: background 150ms ease, border-color 150ms ease;
}
.ops__quick button:hover {
  background: rgba(225, 29, 72, 0.08);
  border-color: rgba(225, 29, 72, 0.4);
}

/* ---------- Tablet ---------- */
@media (min-width: 600px) {
  .ops__kpis {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

/* ---------- Desktop ---------- */
@media (min-width: 1024px) {
  .ops__kpis {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
  .ops__body {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, 1fr);
    align-items: start;
  }
}

@media (min-width: 1400px) {
  .ops__kpis {
    grid-template-columns: repeat(8, minmax(0, 1fr));
  }
}
</style>
