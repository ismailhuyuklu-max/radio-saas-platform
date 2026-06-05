<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import dayjs from 'dayjs';

import {
  getHealth,
  getMetrics,
  type HealthResponse,
  type MetricsResponse,
} from '#/api/modules/radioMedia';
import { formatBytes, formatPercent } from '#/utils/format';

const health = ref<HealthResponse | null>(null);
const metrics = ref<MetricsResponse | null>(null);
const lastUpdated = ref(dayjs());
const connected = ref(true);

let timer: ReturnType<typeof setInterval> | undefined;

const STATUS_LABELS: Record<string, string> = {
  up: 'Çalışıyor',
  degraded: 'Sorunlu',
  down: 'Erişilemiyor',
};

const overallLabel = computed(() => STATUS_LABELS[health.value?.overall ?? 'up'] ?? '—');

const gauges = computed(() => {
  const m = metrics.value;
  if (!m) return [];
  return [
    { key: 'cpu', label: 'CPU', pct: m.cpu.usage_pct ?? 0, tone: m.cpu.tone, sub: `${m.cpu.cores} çekirdek` },
    {
      key: 'ram',
      label: 'Bellek',
      pct: m.memory.used_pct ?? 0,
      tone: m.memory.tone,
      sub: `${formatBytes((m.memory.used_kb ?? 0) * 1024)} / ${formatBytes((m.memory.total_kb ?? 0) * 1024)}`,
    },
    {
      key: 'disk',
      label: 'Disk',
      pct: m.disk.used_pct ?? 0,
      tone: m.disk.tone,
      sub: `${formatBytes(m.disk.used_bytes)} / ${formatBytes(m.disk.total_bytes)}`,
    },
  ];
});

function ring(pct: number): string {
  // conic-gradient end angle for the radial gauge
  return `${Math.max(0, Math.min(100, pct)) * 3.6}deg`;
}

async function refresh() {
  try {
    const [h, m] = await Promise.allSettled([getHealth(), getMetrics()]);
    if (h.status === 'fulfilled') health.value = h.value;
    if (m.status === 'fulfilled') metrics.value = m.value;
    connected.value = h.status === 'fulfilled' && m.status === 'fulfilled';
    lastUpdated.value = dayjs();
  } catch {
    connected.value = false;
  }
}

onMounted(async () => {
  await refresh();
  timer = setInterval(refresh, 5000);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
});
</script>

<template>
  <div class="noc">
    <header class="noc__head">
      <div>
        <h1>Sistem İzleme Merkezi (NOC)</h1>
        <p class="noc__sub">Servis sağlığı ve kaynak kullanımı · 5 sn'de bir yenilenir</p>
      </div>
      <span class="noc__overall" :class="`is-${health?.overall ?? 'up'}`">
        <i />{{ overallLabel }}
      </span>
    </header>

    <!-- Service health -->
    <section class="noc__services">
      <article
        v-for="svc in health?.services ?? []"
        :key="svc.key"
        class="ui-card noc__svc"
        :class="`is-${svc.status}`"
      >
        <div class="noc__svc-top">
          <span class="noc__svc-dot" />
          <strong>{{ svc.label }}</strong>
          <span class="noc__svc-state">{{ STATUS_LABELS[svc.status] }}</span>
        </div>
        <p class="noc__svc-detail">{{ svc.detail }}</p>
      </article>
    </section>

    <!-- Resource gauges -->
    <section class="noc__gauges">
      <article v-for="g in gauges" :key="g.key" class="ui-card noc__gauge">
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
      </article>

      <article v-if="metrics" class="ui-card noc__load">
        <strong>Yük Ortalaması</strong>
        <div class="noc__load-rows">
          <span><b>{{ metrics.load['1m'] }}</b> 1 dk</span>
          <span><b>{{ metrics.load['5m'] }}</b> 5 dk</span>
          <span><b>{{ metrics.load['15m'] }}</b> 15 dk</span>
        </div>
      </article>
    </section>

    <p class="noc__foot">
      <span :class="connected ? 'is-ok' : 'is-bad'">●</span>
      {{ connected ? 'Bağlı' : 'Bağlantı sorunu' }} · son güncelleme
      {{ lastUpdated.format('HH:mm:ss') }}
    </p>
  </div>
</template>

<style scoped>
.noc {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.noc__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}
.noc__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.noc__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
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
}
.noc__overall.is-up {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
}
.noc__overall.is-up i {
  background: var(--c-ok);
  box-shadow: 0 0 8px var(--c-ok);
}
.noc__overall.is-degraded {
  color: var(--c-warn);
  background: rgba(251, 191, 36, 0.12);
}
.noc__overall.is-degraded i {
  background: var(--c-warn);
}
.noc__overall.is-down {
  color: var(--c-bad);
  background: rgba(251, 113, 133, 0.12);
}
.noc__overall.is-down i {
  background: var(--c-bad);
}

.noc__services {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--sp-3);
}
.noc__svc {
  padding: var(--sp-4);
  border-left: 3px solid var(--c-text-3);
}
.noc__svc.is-up {
  border-left-color: var(--c-ok);
}
.noc__svc.is-degraded {
  border-left-color: var(--c-warn);
}
.noc__svc.is-down {
  border-left-color: var(--c-bad);
}
.noc__svc-top {
  display: flex;
  align-items: center;
  gap: 9px;
}
.noc__svc-dot {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  background: var(--c-text-3);
}
.noc__svc.is-up .noc__svc-dot {
  background: var(--c-ok);
  box-shadow: 0 0 8px var(--c-ok);
}
.noc__svc.is-degraded .noc__svc-dot {
  background: var(--c-warn);
}
.noc__svc.is-down .noc__svc-dot {
  background: var(--c-bad);
}
.noc__svc-top strong {
  flex: 1;
  font-size: var(--t-base);
  font-weight: 700;
  color: var(--c-text);
}
.noc__svc-state {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-3);
}
.noc__svc-detail {
  margin: 8px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  font-variant-numeric: tabular-nums;
}

.noc__gauges {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--sp-3);
}
.noc__gauge {
  padding: var(--sp-4);
  display: flex;
  align-items: center;
  gap: var(--sp-4);
}
.noc__ring {
  --end: 0deg;
  width: 84px;
  height: 84px;
  flex-shrink: 0;
  border-radius: 999px;
  display: grid;
  place-items: center;
  background: conic-gradient(var(--ring-c, var(--c-ok)) var(--end), rgba(148, 163, 184, 0.14) 0);
}
.noc__ring::before {
  content: '';
  position: absolute;
  width: 64px;
  height: 64px;
  border-radius: 999px;
  background: var(--c-surface);
}
.noc__ring-val {
  position: relative;
  font-size: 15px;
  font-weight: 800;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}
.noc__ring.tone-ok {
  --ring-c: var(--c-ok);
}
.noc__ring.tone-warning {
  --ring-c: var(--c-warn);
}
.noc__ring.tone-critical {
  --ring-c: var(--c-bad);
}
.noc__gauge-meta {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.noc__gauge-meta strong {
  font-size: var(--t-base);
  font-weight: 700;
  color: var(--c-text);
}
.noc__gauge-meta span {
  font-size: var(--t-xs);
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
}

.noc__load {
  padding: var(--sp-4);
  display: flex;
  flex-direction: column;
  gap: 12px;
  justify-content: center;
}
.noc__load > strong {
  font-size: var(--t-base);
  font-weight: 700;
  color: var(--c-text);
}
.noc__load-rows {
  display: flex;
  gap: 18px;
}
.noc__load-rows span {
  display: flex;
  flex-direction: column;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.noc__load-rows b {
  font-size: 20px;
  font-weight: 800;
  color: var(--c-text);
  font-variant-numeric: tabular-nums;
}

.noc__foot {
  margin: 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.noc__foot .is-ok {
  color: var(--c-ok);
}
.noc__foot .is-bad {
  color: var(--c-bad);
}

@media (min-width: 1024px) {
  .noc__services {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
  .noc__gauges {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>
