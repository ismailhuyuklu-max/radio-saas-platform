<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import dayjs from 'dayjs';

import { message } from 'ant-design-vue';

import {
  getAuditLogs,
  getHealth,
  getMetrics,
  type AuditLogItem,
  type HealthResponse,
  type MetricsResponse,
} from '#/api/modules/radioMedia';
import { formatBytes, formatPercent } from '#/utils/format';

// ---- Reactive state -------------------------------------------------------
const health = ref<HealthResponse | null>(null);
const metrics = ref<MetricsResponse | null>(null);
const events = ref<AuditLogItem[]>([]);
const lastUpdated = ref(dayjs());
const now = ref(dayjs());
const connected = ref(true);
const paused = ref(false);
const REFRESH_MS = 5000;
const nextRefreshIn = ref(REFRESH_MS / 1000);

let refreshTimer: ReturnType<typeof setInterval> | undefined;
let tickTimer: ReturnType<typeof setInterval> | undefined;

// ---- Constants ------------------------------------------------------------
const STATUS_LABELS: Record<string, string> = {
  up: 'Çalışıyor',
  degraded: 'Sorunlu',
  down: 'Erişilemiyor',
};

// 60 sample sparkline buffer per metric (≈ 5 dakikalık trend).
const SPARK_LEN = 60;
type Spark = number[];
const cpuHist = ref<Spark>([]);
const memHist = ref<Spark>([]);
const diskHist = ref<Spark>([]);
const load1Hist = ref<Spark>([]);

function pushSample(buf: Spark, v: number | null | undefined): void {
  buf.push(typeof v === 'number' && Number.isFinite(v) ? v : 0);
  if (buf.length > SPARK_LEN) buf.shift();
}

// ---- Derived ---------------------------------------------------------------
const overall = computed(() => health.value?.overall ?? 'up');
const overallLabel = computed(() => STATUS_LABELS[overall.value] ?? '—');

const upServices = computed(
  () => (health.value?.services ?? []).filter((s) => s.status === 'up').length,
);
const totalServices = computed(() => (health.value?.services ?? []).length);
const degradedServices = computed(
  () => (health.value?.services ?? []).filter((s) => s.status !== 'up').length,
);

// Recent error events (last 30 days, action=error)
const recentErrors = computed(() => events.value.filter((e) => e.action === 'error').length);

const gauges = computed(() => {
  const m = metrics.value;
  if (!m) return [];
  return [
    {
      key: 'cpu',
      label: 'CPU',
      pct: m.cpu.usage_pct ?? 0,
      tone: m.cpu.tone,
      sub: `${m.cpu.cores} çekirdek`,
      hist: cpuHist.value,
    },
    {
      key: 'ram',
      label: 'Bellek',
      pct: m.memory.used_pct ?? 0,
      tone: m.memory.tone,
      sub: `${formatBytes((m.memory.used_kb ?? 0) * 1024)} / ${formatBytes((m.memory.total_kb ?? 0) * 1024)}`,
      hist: memHist.value,
    },
    {
      key: 'disk',
      label: 'Disk',
      pct: m.disk.used_pct ?? 0,
      tone: m.disk.tone,
      sub: `${formatBytes(m.disk.used_bytes)} / ${formatBytes(m.disk.total_bytes)}`,
      hist: diskHist.value,
    },
  ];
});

// ---- Sparkline SVG path generator (small, fast) ----------------------------
function sparkPath(values: Spark, width = 160, height = 28): string {
  if (values.length < 2) {
    return `M0 ${height / 2} L${width} ${height / 2}`;
  }
  const max = Math.max(100, ...values); // cap at 100% but allow load to exceed
  const stepX = width / Math.max(1, values.length - 1);
  return values
    .map((v, i) => {
      const x = i * stepX;
      const y = height - (v / max) * (height - 2) - 1;
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`;
    })
    .join(' ');
}

function sparkArea(values: Spark, width = 160, height = 28): string {
  if (values.length < 2) return '';
  const path = sparkPath(values, width, height);
  return `${path} L${width} ${height} L0 ${height} Z`;
}

function ring(pct: number): string {
  return `${Math.max(0, Math.min(100, pct)) * 3.6}deg`;
}

// ---- Event log formatting --------------------------------------------------
function eventIcon(action: string): string {
  if (action.startsWith('error')) return '⛔';
  if (action.startsWith('media_render')) return '🎙';
  if (action.startsWith('media_download')) return '⤓';
  if (action === 'login') return '🔓';
  if (action === 'logout') return '🚪';
  if (action.startsWith('partner_')) return '📻';
  if (action.startsWith('plan_') || action.includes('plan')) return '📅';
  if (action.startsWith('access_denied')) return '🛡';
  return '·';
}

function eventTone(action: string): string {
  if (action.startsWith('error') || action.includes('failed') || action.includes('denied')) return 'bad';
  if (action.startsWith('media_render')) return 'info';
  if (action.startsWith('partner_provision') || action === 'login') return 'ok';
  return 'muted';
}

function timeAgo(ts: string): string {
  const diff = now.value.diff(dayjs(ts), 'second');
  if (diff < 5) return 'şimdi';
  if (diff < 60) return `${diff} sn önce`;
  if (diff < 3600) return `${Math.floor(diff / 60)} dk önce`;
  if (diff < 86_400) return `${Math.floor(diff / 3600)} sa önce`;
  return dayjs(ts).format('DD MMM HH:mm');
}

// ---- Render queue summary (parsed from the 'Render Kuyruğu' service detail)
const renderQueueInfo = computed(() => {
  const svc = (health.value?.services ?? []).find(
    (s) => s.key === 'render_queue' || s.label.toLowerCase().includes('render'),
  );
  if (!svc) return null;
  // detail looks like: "0 bekleyen · 0 hatalı"
  const m = (svc.detail ?? '').match(/(\d+)\s+bekleyen[^·]*·\s*(\d+)\s+hatalı/i);
  if (!m) return { detail: svc.detail, pending: null, failed: null };
  return {
    detail: svc.detail,
    pending: Number(m[1]),
    failed: Number(m[2]),
  };
});

// ---- Data loaders ---------------------------------------------------------
async function refresh(silent = false): Promise<void> {
  try {
    const [h, m, ev] = await Promise.allSettled([
      getHealth(),
      getMetrics(),
      getAuditLogs({ limit: 25 }),
    ]);
    const healthOk =
      h.status === 'fulfilled' &&
      h.value !== null &&
      typeof h.value === 'object' &&
      Array.isArray((h.value as HealthResponse).services);
    const metricsOk =
      m.status === 'fulfilled' &&
      m.value !== null &&
      typeof m.value === 'object' &&
      !!(m.value as MetricsResponse).cpu;

    if (healthOk) health.value = h.value as HealthResponse;
    if (metricsOk) {
      const mv = m.value as MetricsResponse;
      metrics.value = mv;
      pushSample(cpuHist.value, mv.cpu.usage_pct ?? 0);
      pushSample(memHist.value, mv.memory.used_pct ?? 0);
      pushSample(diskHist.value, mv.disk.used_pct ?? 0);
      pushSample(load1Hist.value, mv.load['1m']);
    }
    if (ev.status === 'fulfilled') {
      // /audit/logs döner: AuditLogItem[] (array). Eski versiyonlarda
      // {logs: [...]} olabilir — her ikisini destekle. HTML hata gövdesi
      // gelirse (backend down) array değildir ve atılır.
      const raw = ev.value as AuditLogItem[] | { logs?: AuditLogItem[] } | null;
      const list = Array.isArray(raw) ? raw : (raw?.logs ?? []);
      events.value = list.slice(0, 20);
    }
    connected.value = healthOk && metricsOk;
    lastUpdated.value = dayjs();
    nextRefreshIn.value = REFRESH_MS / 1000;
    if (!silent) {
      /* no toast on auto refresh */
    }
  } catch {
    connected.value = false;
  }
}

// ---- Controls -------------------------------------------------------------
function manualRefresh(): void {
  void refresh(false);
  message.info('Yenilendi');
}

function togglePause(): void {
  paused.value = !paused.value;
  if (paused.value) {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = undefined;
  } else {
    startAutoRefresh();
    void refresh(true);
  }
}

const wallRoot = ref<HTMLElement | null>(null);
const isFullscreen = ref(false);
async function toggleFullscreen(): Promise<void> {
  const el = wallRoot.value;
  if (!el) return;
  if (!document.fullscreenElement) {
    await el.requestFullscreen?.();
    isFullscreen.value = true;
  } else {
    await document.exitFullscreen?.();
    isFullscreen.value = false;
  }
}
function onFsChange(): void {
  isFullscreen.value = !!document.fullscreenElement;
}

function startAutoRefresh(): void {
  if (refreshTimer) clearInterval(refreshTimer);
  refreshTimer = setInterval(() => {
    if (!paused.value) void refresh(true);
  }, REFRESH_MS);
}

onMounted(async () => {
  await refresh(false);
  startAutoRefresh();
  // 1Hz tick — drives the live clock + auto-refresh countdown.
  tickTimer = setInterval(() => {
    now.value = dayjs();
    if (!paused.value && nextRefreshIn.value > 0) nextRefreshIn.value--;
  }, 1000);
  document.addEventListener('fullscreenchange', onFsChange);
});
onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer);
  if (tickTimer) clearInterval(tickTimer);
  document.removeEventListener('fullscreenchange', onFsChange);
});
</script>

<template>
  <div ref="wallRoot" class="noc" :class="{ 'is-wall': isFullscreen }">
    <!-- ========== HEADER ========== -->
    <header class="noc__head">
      <div class="noc__head-text">
        <h1>Sistem İzleme Merkezi <span class="noc__chip">NOC</span></h1>
        <p class="noc__sub">
          Servis sağlığı + kaynak kullanımı + olay akışı
          <span class="noc__dot">·</span>
          <span class="noc__refresh">
            {{ paused ? 'Duraklatıldı' : `${nextRefreshIn} sn'de yenilenecek` }}
          </span>
        </p>
      </div>
      <div class="noc__head-right">
        <!-- Live wall clock -->
        <div class="noc__clock" :title="now.format('DD MMMM YYYY, dddd')">
          <span class="noc__clock-time">{{ now.format('HH:mm:ss') }}</span>
          <span class="noc__clock-date">{{ now.format('DD.MM.YYYY') }}</span>
        </div>
        <!-- Overall status pill -->
        <span class="noc__overall" :class="`is-${overall}`">
          <i />{{ overallLabel }}
        </span>
        <!-- Controls -->
        <div class="noc__ctrl">
          <button type="button" class="noc__btn" title="Şimdi yenile" @click="manualRefresh">↻</button>
          <button
            type="button"
            class="noc__btn"
            :class="{ 'is-on': paused }"
            :title="paused ? 'Devam ettir' : 'Otomatik yenilemeyi duraklat'"
            @click="togglePause"
          >
            {{ paused ? '▶' : '❚❚' }}
          </button>
          <button
            type="button"
            class="noc__btn"
            :title="isFullscreen ? 'Tam ekrandan çık' : 'Tam ekran (duvar modu)'"
            @click="toggleFullscreen"
          >
            {{ isFullscreen ? '⤡' : '⛶' }}
          </button>
        </div>
      </div>
    </header>

    <!-- ========== BACKEND DOWN BANNER ========== -->
    <div v-if="!connected" class="noc__banner">
      <span class="noc__banner-dot" />
      <div>
        <strong>Backend erişilemiyor</strong>
        <span>
          /monitoring/health ve /monitoring/metrics 5xx döndü.
          PostgreSQL bağlantısı veya PHP servisi düşmüş olabilir.
          Docker Desktop'ı kontrol edin — postgres + php-fpm container'larının ayakta olması gerekir.
        </span>
      </div>
      <button type="button" class="noc__btn" @click="manualRefresh">↻ Tekrar Dene</button>
    </div>

    <!-- ========== KPI STRIP ========== -->
    <section class="noc__kpis">
      <article class="noc__kpi" :class="`is-${overall}`">
        <span class="noc__kpi-v">{{ upServices }}/{{ totalServices }}</span>
        <span class="noc__kpi-l">Aktif Servis</span>
      </article>
      <article class="noc__kpi" :class="degradedServices === 0 ? 'is-ok' : 'is-warn'">
        <span class="noc__kpi-v">{{ degradedServices }}</span>
        <span class="noc__kpi-l">Sorunlu Servis</span>
      </article>
      <article class="noc__kpi" :class="renderQueueInfo?.failed && renderQueueInfo.failed > 0 ? 'is-bad' : 'is-ok'">
        <span class="noc__kpi-v">{{ renderQueueInfo?.pending ?? '—' }}</span>
        <span class="noc__kpi-l">Render Bekleyen</span>
      </article>
      <article class="noc__kpi" :class="recentErrors > 0 ? 'is-bad' : 'is-ok'">
        <span class="noc__kpi-v">{{ recentErrors }}</span>
        <span class="noc__kpi-l">Son Hata Kaydı</span>
      </article>
    </section>

    <!-- ========== SERVICES + EVENT LOG side by side ========== -->
    <div class="noc__row">
      <!-- Services -->
      <section class="noc__panel ui-card">
        <header class="noc__panel-head">
          <h2>Servis Sağlığı</h2>
          <span class="noc__panel-sub">{{ totalServices }} bileşen</span>
        </header>
        <div class="noc__services">
          <article
            v-for="svc in health?.services ?? []"
            :key="svc.key"
            class="noc__svc"
            :class="`is-${svc.status}`"
          >
            <div class="noc__svc-top">
              <span class="noc__svc-dot" />
              <strong>{{ svc.label }}</strong>
              <span class="noc__svc-state">{{ STATUS_LABELS[svc.status] }}</span>
            </div>
            <p class="noc__svc-detail">{{ svc.detail }}</p>
          </article>
        </div>
      </section>

      <!-- Event log -->
      <section class="noc__panel ui-card">
        <header class="noc__panel-head">
          <h2>Olay Akışı</h2>
          <span class="noc__panel-sub">son {{ events.length }} kayıt</span>
        </header>
        <ul v-if="events.length" class="noc__events">
          <li
            v-for="ev in events"
            :key="ev.id"
            class="noc__event"
            :class="`tone-${eventTone(ev.action)}`"
          >
            <span class="noc__event-icon">{{ eventIcon(ev.action) }}</span>
            <span class="noc__event-body">
              <span class="noc__event-action">{{ ev.action }}</span>
              <span class="noc__event-meta">
                {{ ev.actor_username }}
                <template v-if="ev.entity_type"> · {{ ev.entity_type }}</template>
                <template v-if="ev.ip_address"> · {{ ev.ip_address }}</template>
              </span>
            </span>
            <span class="noc__event-time" :title="ev.created_at">{{ timeAgo(ev.created_at) }}</span>
          </li>
        </ul>
        <p v-else class="noc__panel-empty">Olay yok.</p>
      </section>
    </div>

    <!-- ========== RESOURCE GAUGES + SPARKLINES ========== -->
    <section class="noc__gauges">
      <article v-for="g in gauges" :key="g.key" class="ui-card noc__gauge">
        <div class="noc__gauge-top">
          <div
            class="noc__ring"
            :class="`tone-${g.tone}`"
            :style="{ '--end': ring(g.pct) }"
          >
            <span class="noc__ring-val">{{ formatPercent(g.pct) }}</span>
          </div>
          <div class="noc__gauge-meta">
            <strong>{{ g.label }}</strong>
            <span>{{ g.sub }}</span>
          </div>
        </div>
        <svg class="noc__spark" :class="`tone-${g.tone}`" viewBox="0 0 160 28" preserveAspectRatio="none">
          <path class="noc__spark-fill" :d="sparkArea(g.hist)" />
          <path class="noc__spark-line" :d="sparkPath(g.hist)" />
        </svg>
        <span class="noc__spark-cap">son 5 dk</span>
      </article>

      <article v-if="metrics" class="ui-card noc__gauge noc__load">
        <div class="noc__gauge-top">
          <div class="noc__load-mark">⟁</div>
          <div class="noc__gauge-meta">
            <strong>Yük Ortalaması</strong>
            <span>1 / 5 / 15 dakika</span>
          </div>
        </div>
        <div class="noc__load-rows">
          <span><b>{{ metrics.load['1m'].toFixed(2) }}</b><em>1 dk</em></span>
          <span><b>{{ metrics.load['5m'].toFixed(2) }}</b><em>5 dk</em></span>
          <span><b>{{ metrics.load['15m'].toFixed(2) }}</b><em>15 dk</em></span>
        </div>
        <svg class="noc__spark tone-info" viewBox="0 0 160 28" preserveAspectRatio="none">
          <path class="noc__spark-fill" :d="sparkArea(load1Hist)" />
          <path class="noc__spark-line" :d="sparkPath(load1Hist)" />
        </svg>
      </article>
    </section>

    <!-- ========== FOOTER ========== -->
    <footer class="noc__foot">
      <span :class="connected ? 'is-ok' : 'is-bad'">●</span>
      {{ connected ? 'Bağlı' : 'Bağlantı sorunu' }}
      <span class="noc__dot">·</span>
      son güncelleme {{ lastUpdated.format('HH:mm:ss') }}
      <span class="noc__dot">·</span>
      5 sn otomatik yenileme
      <template v-if="paused"> <span class="noc__dot">·</span> <span style="color: var(--c-warn)">duraklatıldı</span></template>
    </footer>
  </div>
</template>

<style scoped>
.noc {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* ===== Header ===== */
.noc__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.noc__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.noc__chip {
  font-size: 10px;
  font-weight: 900;
  letter-spacing: 0.16em;
  padding: 2px 8px;
  border-radius: 4px;
  background: var(--c-brand);
  color: #fff;
  vertical-align: middle;
}
.noc__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.noc__refresh {
  font-variant-numeric: tabular-nums;
  color: var(--c-info);
  font-weight: 600;
}
.noc__dot { opacity: 0.5; margin: 0 4px; }

.noc__head-right {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

/* Live clock — operations wall vibe */
.noc__clock {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  line-height: 1.05;
  padding: 6px 12px;
  border-radius: 10px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  font-variant-numeric: tabular-nums;
}
.noc__clock-time {
  font-family: 'Fira Code', 'JetBrains Mono', Consolas, monospace;
  font-size: 18px;
  font-weight: 700;
  color: var(--c-text);
  letter-spacing: 0.04em;
}
.noc__clock-date {
  font-size: 10px;
  color: var(--c-text-3);
  margin-top: 1px;
}

/* Overall status pill */
.noc__overall {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  border-radius: 999px;
  font-size: var(--t-sm);
  font-weight: 800;
  border: 1px solid var(--c-line);
}
.noc__overall i {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  animation: noc-pulse 2s ease-in-out infinite;
}
.noc__overall.is-up { color: var(--c-ok); background: rgba(52, 211, 153, 0.12); border-color: rgba(52, 211, 153, 0.32); }
.noc__overall.is-up i { background: var(--c-ok); box-shadow: 0 0 8px var(--c-ok); }
.noc__overall.is-degraded { color: var(--c-warn); background: rgba(251, 191, 36, 0.12); border-color: rgba(251, 191, 36, 0.32); }
.noc__overall.is-degraded i { background: var(--c-warn); box-shadow: 0 0 8px var(--c-warn); }
.noc__overall.is-down { color: var(--c-bad); background: rgba(251, 113, 133, 0.12); border-color: rgba(251, 113, 133, 0.32); }
.noc__overall.is-down i { background: var(--c-bad); box-shadow: 0 0 12px var(--c-bad); animation-duration: 0.8s; }

@keyframes noc-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.55; transform: scale(0.85); }
}

/* Control buttons */
.noc__ctrl {
  display: flex;
  gap: 4px;
  padding: 3px;
  border-radius: 10px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
}
.noc__btn {
  width: 32px;
  height: 32px;
  border-radius: 7px;
  border: none;
  background: transparent;
  color: var(--c-text-2);
  font-size: 14px;
  cursor: pointer;
  transition: background 120ms ease, color 120ms ease;
}
.noc__btn:hover { background: var(--c-surface-2); color: var(--c-text); }
.noc__btn.is-on { background: var(--c-brand); color: #fff; }

/* ===== Backend-down banner ===== */
.noc__banner {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(251, 113, 133, 0.08);
  border: 1px solid rgba(251, 113, 133, 0.32);
  color: var(--c-text);
}
.noc__banner-dot {
  width: 14px;
  height: 14px;
  border-radius: 999px;
  background: var(--c-bad);
  box-shadow: 0 0 12px var(--c-bad);
  animation: noc-pulse 0.8s ease-in-out infinite;
  flex-shrink: 0;
}
.noc__banner > div {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.noc__banner strong {
  font-size: 13px;
  font-weight: 800;
  color: var(--c-bad);
}
.noc__banner span {
  font-size: 12px;
  color: var(--c-text-2);
  line-height: 1.45;
}
.noc__banner .noc__btn {
  background: var(--c-bad);
  color: #fff;
  width: auto;
  padding: 0 12px;
  border-radius: 8px;
  font-weight: 700;
  font-size: 12px;
  white-space: nowrap;
}
.noc__banner .noc__btn:hover { background: #e11d48; }

/* ===== KPI strip ===== */
.noc__kpis {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}
.noc__kpi {
  padding: 14px 16px;
  border-radius: 12px;
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-left: 4px solid var(--c-text-3);
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.noc__kpi.is-up, .noc__kpi.is-ok { border-left-color: var(--c-ok); }
.noc__kpi.is-warn, .noc__kpi.is-degraded { border-left-color: var(--c-warn); }
.noc__kpi.is-bad, .noc__kpi.is-down { border-left-color: var(--c-bad); }
.noc__kpi-v {
  font-size: 22px;
  font-weight: 900;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.noc__kpi-l {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--c-text-3);
}

/* ===== Two-pane row: services + event log ===== */
.noc__row {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 12px;
  align-items: start;
}
@media (max-width: 1024px) {
  .noc__row { grid-template-columns: 1fr; }
}

.noc__panel {
  padding: 14px 16px;
}
.noc__panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 1px dashed var(--c-line);
}
.noc__panel-head h2 {
  margin: 0;
  font-size: 14px;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--c-text);
}
.noc__panel-sub {
  font-size: 11px;
  color: var(--c-text-3);
}
.noc__panel-empty {
  margin: 0;
  padding: 18px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

/* ===== Services ===== */
.noc__services {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
@media (max-width: 640px) {
  .noc__services { grid-template-columns: 1fr; }
}
.noc__svc {
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
  border-left: 3px solid var(--c-text-3);
}
.noc__svc.is-up { border-left-color: var(--c-ok); }
.noc__svc.is-degraded { border-left-color: var(--c-warn); }
.noc__svc.is-down { border-left-color: var(--c-bad); }
.noc__svc-top {
  display: flex;
  align-items: center;
  gap: 8px;
}
.noc__svc-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--c-text-3);
  flex-shrink: 0;
}
.noc__svc.is-up .noc__svc-dot { background: var(--c-ok); box-shadow: 0 0 6px var(--c-ok); animation: noc-pulse 2s ease-in-out infinite; }
.noc__svc.is-degraded .noc__svc-dot { background: var(--c-warn); box-shadow: 0 0 6px var(--c-warn); }
.noc__svc.is-down .noc__svc-dot { background: var(--c-bad); box-shadow: 0 0 8px var(--c-bad); animation: noc-pulse 0.8s ease-in-out infinite; }
.noc__svc-top strong {
  flex: 1;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--c-text);
}
.noc__svc-state {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--c-text-3);
}
.noc__svc-detail {
  margin: 6px 0 0;
  font-size: 11px;
  color: var(--c-text-2);
  font-variant-numeric: tabular-nums;
}

/* ===== Event log ===== */
.noc__events {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 280px;
  overflow-y: auto;
}
.noc__event {
  display: grid;
  grid-template-columns: 22px 1fr auto;
  align-items: center;
  gap: 8px;
  padding: 7px 8px;
  border-radius: 7px;
  border-left: 2px solid transparent;
  font-size: 12px;
}
.noc__event.tone-ok { border-left-color: var(--c-ok); background: rgba(52, 211, 153, 0.04); }
.noc__event.tone-info { border-left-color: var(--c-info); background: rgba(96, 165, 250, 0.04); }
.noc__event.tone-bad { border-left-color: var(--c-bad); background: rgba(251, 113, 133, 0.06); }
.noc__event.tone-muted { border-left-color: rgba(148, 163, 184, 0.3); }

.noc__event-icon {
  text-align: center;
  font-size: 14px;
  line-height: 1;
}
.noc__event-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.noc__event-action {
  font-family: 'Fira Code', Consolas, monospace;
  font-size: 11px;
  font-weight: 700;
  color: var(--c-text);
}
.noc__event-meta {
  font-size: 10px;
  color: var(--c-text-3);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.noc__event-time {
  font-size: 10px;
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

/* ===== Resource gauges + sparkline ===== */
.noc__gauges {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}
@media (max-width: 900px) {
  .noc__gauges { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .noc__kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 480px) {
  .noc__gauges, .noc__kpis { grid-template-columns: 1fr; }
}

.noc__gauge {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.noc__gauge-top {
  display: flex;
  align-items: center;
  gap: 12px;
}
.noc__ring {
  --end: 0deg;
  position: relative;
  width: 64px;
  height: 64px;
  flex-shrink: 0;
  border-radius: 999px;
  display: grid;
  place-items: center;
  background: conic-gradient(var(--ring-c, var(--c-ok)) var(--end), rgba(148, 163, 184, 0.14) 0);
}
.noc__ring::before {
  content: '';
  position: absolute;
  width: 48px;
  height: 48px;
  border-radius: 999px;
  background: var(--c-surface);
}
.noc__ring-val {
  position: relative;
  font-size: 13px;
  font-weight: 800;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}
.noc__ring.tone-ok { --ring-c: var(--c-ok); }
.noc__ring.tone-warning { --ring-c: var(--c-warn); }
.noc__ring.tone-critical { --ring-c: var(--c-bad); }

.noc__load-mark {
  width: 64px;
  height: 64px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  border-radius: 999px;
  background: rgba(96, 165, 250, 0.14);
  color: var(--c-info);
  font-size: 26px;
  font-weight: 900;
}

.noc__gauge-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.noc__gauge-meta strong {
  font-size: 13px;
  font-weight: 800;
  color: var(--c-text);
}
.noc__gauge-meta span {
  font-size: 10px;
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
}

.noc__load-rows {
  display: flex;
  gap: 10px;
  justify-content: space-between;
}
.noc__load-rows span {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  padding: 4px 0;
  border-radius: 6px;
  background: rgba(148, 163, 184, 0.06);
}
.noc__load-rows b {
  font-size: 14px;
  font-weight: 900;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}
.noc__load-rows em {
  font-style: normal;
  font-size: 9px;
  color: var(--c-text-3);
  letter-spacing: 0.04em;
}

/* ===== Sparkline ===== */
.noc__spark {
  width: 100%;
  height: 28px;
  display: block;
}
.noc__spark-fill {
  fill: var(--spark-c, var(--c-info));
  opacity: 0.14;
}
.noc__spark-line {
  fill: none;
  stroke: var(--spark-c, var(--c-info));
  stroke-width: 1.2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.noc__spark.tone-ok { --spark-c: var(--c-ok); }
.noc__spark.tone-warning { --spark-c: var(--c-warn); }
.noc__spark.tone-critical { --spark-c: var(--c-bad); }
.noc__spark.tone-info { --spark-c: var(--c-info); }
.noc__spark-cap {
  font-size: 9px;
  color: var(--c-text-3);
  text-align: right;
  letter-spacing: 0.04em;
}

/* ===== Footer ===== */
.noc__foot {
  margin: 0;
  font-size: 11px;
  color: var(--c-text-3);
  padding: 8px 12px;
  border-radius: 8px;
  background: rgba(148, 163, 184, 0.04);
  border: 1px solid var(--c-line);
}
.noc__foot .is-ok { color: var(--c-ok); }
.noc__foot .is-bad { color: var(--c-bad); }

/* ===== Wall mode (fullscreen presentation) ===== */
.noc.is-wall {
  background: #050a18;
  padding: 24px;
  height: 100vh;
  overflow-y: auto;
}
.noc.is-wall .noc__head h1 { font-size: 28px; }
.noc.is-wall .noc__clock-time { font-size: 28px; }
.noc.is-wall .noc__kpi-v { font-size: 32px; }
</style>
