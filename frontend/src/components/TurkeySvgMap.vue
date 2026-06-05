<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { init, registerMap, use, type ComposeOption } from 'echarts/core';
import { MapChart, type MapSeriesOption } from 'echarts/charts';
import { TooltipComponent, type TooltipComponentOption } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

import { REGION_LABELS, type RegionCode } from '#/api/modules/radioMedia';

import rawTurkeySvg from '../assets/turkiye-svg-haritasi.svg?raw';

use([MapChart, TooltipComponent, CanvasRenderer]);

type ECOption = ComposeOption<MapSeriesOption | TooltipComponentOption>;
type ECInstance = ReturnType<typeof init>;
type MapClickEvent = { name?: string };

export interface TurkeyRegionState {
  dominantTone: 'success' | 'warning' | 'danger';
  successCount: number;
  warningCount: number;
  dangerCount: number;
  totalCount: number;
  latestUpdatedAt: string | null;
}

interface Props {
  selectedRegionCode: RegionCode;
  regionStates?: Partial<Record<RegionCode, TurkeyRegionState>>;
}

const props = defineProps<Props>();
const emit = defineEmits<{ (event: 'select-region', regionCode: RegionCode): void }>();

const containerRef = ref<HTMLDivElement | null>(null);
const chart = shallowRef<ECInstance | null>(null);
let resizeObserver: ResizeObserver | null = null;
let lastHovered: RegionCode | null = null;

const REGION_CODES: RegionCode[] = [
  'marmara',
  'ege',
  'akdeniz',
  'karadeniz',
  'ic-anadolu',
  'dogu-anadolu',
  'guneydogu-anadolu',
];
const REGION_SET = new Set<string>(REGION_CODES);

type Tone = 'success' | 'warning' | 'danger';

// `fill` = subtle neutral-dark land tint for non-selected provinces (just a hint
// of status colour); `sel` = bold status colour + glow for the selected region,
// so the active region pops dramatically against calm land.
const TONE: Record<Tone, { fill: string; sel: string; border: string; glow: string; label: string }> = {
  success: { fill: '#152a2a', sel: '#10b981', border: 'rgba(16, 185, 129, 0.6)', glow: 'rgba(16, 185, 129, 0.95)', label: 'Canlı' },
  warning: { fill: '#26241c', sel: '#f59e0b', border: 'rgba(245, 158, 11, 0.6)', glow: 'rgba(245, 158, 11, 0.95)', label: 'Uyarı' },
  danger: { fill: '#241a24', sel: '#f43f5e', border: 'rgba(244, 63, 94, 0.6)', glow: 'rgba(244, 63, 94, 0.95)', label: 'Kritik' },
};

// Parse the bundled SVG once: add a `name` attribute (required by ECharts to treat
// each province group as a selectable region) and build the province -> region map.
const provinceRegion: Record<string, RegionCode> = {};
const provinceList: Array<{ name: string; region: RegionCode }> = [];
const preparedSvg = (() => {
  const doc = new DOMParser().parseFromString(rawTurkeySvg, 'image/svg+xml');
  doc.querySelectorAll('g[data-iladi]').forEach((group) => {
    const name = (group.getAttribute('data-iladi') ?? '').trim();
    const region = group.getAttribute('data-region') ?? '';
    if (!name || !REGION_SET.has(region)) {
      return;
    }
    group.setAttribute('name', name);
    if (!(name in provinceRegion)) {
      provinceRegion[name] = region as RegionCode;
      provinceList.push({ name, region: region as RegionCode });
    }
  });
  const root = doc.documentElement;
  return new XMLSerializer().serializeToString(root);
})();

registerMap('turkiye', { svg: preparedSvg });

const activeTone = computed<Tone>(
  () => props.regionStates?.[props.selectedRegionCode]?.dominantTone ?? 'danger',
);

function toneOf(region: RegionCode): Tone {
  return props.regionStates?.[region]?.dominantTone ?? 'danger';
}

function buildData(active: RegionCode) {
  return provinceList.map((province) => {
    const tone = TONE[toneOf(province.region)];
    const selected = province.region === active;
    return {
      name: province.name,
      itemStyle: {
        areaColor: selected ? tone.sel : tone.fill,
        color: selected ? tone.sel : tone.fill,
        borderColor: selected ? tone.border : 'rgba(148, 163, 184, 0.18)',
        borderWidth: selected ? 1.3 : 0.5,
        shadowColor: selected ? tone.glow : 'transparent',
        shadowBlur: selected ? 16 : 0,
      },
      label: {
        color: selected ? '#ffffff' : '#cdd7e6',
        fontWeight: selected ? 800 : 600,
        fontSize: selected ? 10.5 : 9,
      },
    };
  });
}

function buildOption(active: RegionCode): ECOption {
  return {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'item',
      confine: true,
      backgroundColor: 'rgba(8, 14, 26, 0.96)',
      borderColor: 'rgba(148, 163, 184, 0.25)',
      borderWidth: 1,
      padding: [9, 13],
      extraCssText: 'border-radius:12px;box-shadow:0 18px 40px rgba(2,6,23,.5);backdrop-filter:blur(6px);',
      textStyle: { color: '#e8eef7', fontSize: 12 },
      formatter: (params) => {
        const single = Array.isArray(params) ? params[0] : params;
        const name = (single as { name?: string })?.name ?? '';
        const region = provinceRegion[name];
        if (!region) {
          return name;
        }
        const state = props.regionStates?.[region];
        const tone = TONE[state?.dominantTone ?? 'danger'];
        const updated = state?.latestUpdatedAt
          ? new Date(state.latestUpdatedAt).toLocaleString('tr-TR', { dateStyle: 'short', timeStyle: 'short' })
          : '—';
        return (
          `<div style="font-weight:800;font-size:13px;margin-bottom:5px">${name}</div>` +
          `<div style="opacity:.78;margin-bottom:2px">Bölge: <b style="color:#f1f5f9">${REGION_LABELS[region]}</b></div>` +
          `<div style="margin-bottom:4px">Durum: <span style="color:${tone.sel};font-weight:800">${tone.label}</span></div>` +
          `<div style="display:flex;gap:10px;font-size:11px;opacity:.85">` +
          `<span>🟢 ${state?.successCount ?? 0}</span><span>🟡 ${state?.warningCount ?? 0}</span><span>🔴 ${state?.dangerCount ?? 0}</span>` +
          `</div>` +
          `<div style="opacity:.55;font-size:11px;margin-top:4px">Güncelleme: ${updated}</div>`
        );
      },
    },
    labelLayout: { hideOverlap: true },
    series: [
      {
        type: 'map',
        map: 'turkiye',
        roam: true,
        scaleLimit: { min: 1, max: 4 },
        selectedMode: false,
        animationDurationUpdate: 520,
        label: {
          show: true,
          color: '#cdd7e6',
          fontSize: 9,
          fontWeight: 600,
          fontFamily: "'Plus Jakarta Sans','Inter',system-ui,sans-serif",
          textBorderColor: 'rgba(6, 11, 22, 0.92)',
          textBorderWidth: 2.6,
        },
        itemStyle: {
          areaColor: '#16223a',
          color: '#16223a',
          borderColor: 'rgba(148, 163, 184, 0.16)',
          borderWidth: 0.5,
        },
        emphasis: {
          label: { show: true, color: '#ffffff', fontWeight: 800 },
          itemStyle: {
            areaColor: '#2a3a5c',
            color: '#2a3a5c',
            borderColor: 'rgba(255, 255, 255, 0.82)',
            borderWidth: 1.2,
            shadowBlur: 20,
            shadowColor: 'rgba(125, 211, 252, 0.6)',
          },
        },
        data: buildData(active),
      },
    ],
  };
}

function refreshData() {
  chart.value?.setOption({ series: [{ data: buildData(props.selectedRegionCode) }] });
}

function createChart() {
  if (!containerRef.value) {
    return;
  }

  const instance = init(containerRef.value, undefined, { renderer: 'canvas' });
  chart.value = instance;
  instance.setOption(buildOption(props.selectedRegionCode));

  instance.on('click', (event: MapClickEvent) => {
    const region = provinceRegion[event.name ?? ''];
    if (region) {
      emit('select-region', region);
    }
  });

  instance.on('mouseover', (event: MapClickEvent) => {
    const region = provinceRegion[event.name ?? ''];
    if (region && region !== lastHovered) {
      lastHovered = region;
      emit('select-region', region);
    }
  });

  instance.getZr().on('mouseout', () => {
    lastHovered = null;
  });
}

// Roam (zoom/pan) leaves the map translated/scaled; dispose + recreate is the most
// reliable way to snap the view fully back to its default fit.
function resetView() {
  chart.value?.dispose();
  createChart();
}

onMounted(() => {
  createChart();
  if (containerRef.value) {
    resizeObserver = new ResizeObserver(() => chart.value?.resize());
    resizeObserver.observe(containerRef.value);
  }
});

watch(() => props.selectedRegionCode, refreshData);
watch(() => props.regionStates, refreshData, { deep: true });

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
  resizeObserver = null;
  chart.value?.dispose();
  chart.value = null;
});
</script>

<template>
  <div class="turkey-map-shell" :class="[`tone-${activeTone}`]">
    <div ref="containerRef" class="turkey-echart" />
    <button
      class="map-reset"
      type="button"
      title="Görünümü sıfırla"
      aria-label="Harita görünümünü sıfırla"
      @click="resetView"
    >
      ⤢
    </button>
    <div class="map-legend" aria-hidden="true">
      <span class="map-legend__item"><i class="map-legend__dot is-success" />Canlı</span>
      <span class="map-legend__item"><i class="map-legend__dot is-warning" />Uyarı</span>
      <span class="map-legend__item"><i class="map-legend__dot is-danger" />Kritik</span>
    </div>
  </div>
</template>

<style scoped>
.turkey-map-shell {
  --accent: 244, 63, 94;
  position: relative;
  overflow: hidden;
  border-radius: 28px;
  border: 1px solid rgba(148, 163, 184, 0.14);
  isolation: isolate;
  /* !important guards against the matrix page's global .tone-* flood rule. */
  background:
    radial-gradient(120% 88% at 50% -10%, rgba(56, 189, 248, 0.12), transparent 56%),
    radial-gradient(100% 80% at 50% 120%, rgba(14, 46, 71, 0.4), transparent 62%),
    linear-gradient(180deg, #0b1c30 0%, #0a1626 52%, #07101d 100%) !important;
  box-shadow:
    0 34px 80px rgba(2, 6, 23, 0.55),
    inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.turkey-map-shell.tone-success {
  --accent: 16, 185, 129;
}

.turkey-map-shell.tone-warning {
  --accent: 245, 158, 11;
}

.turkey-map-shell.tone-danger {
  --accent: 244, 63, 94;
}

.turkey-map-shell::before,
.turkey-map-shell::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}

.turkey-map-shell::before {
  background: radial-gradient(58% 50% at 50% 48%, rgba(var(--accent), 0.15), transparent 72%);
  transition: background 320ms ease;
  /* Selected region's status colour gently "breathes" behind the map. */
  animation: shellBreathe 3.6s ease-in-out infinite;
}

.turkey-map-shell::after {
  background: radial-gradient(132% 120% at 50% 50%, transparent 56%, rgba(2, 6, 23, 0.5) 100%);
}

@keyframes shellBreathe {
  0%,
  100% {
    opacity: 0.66;
  }

  50% {
    opacity: 1;
  }
}

.map-reset {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 2;
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(8, 14, 26, 0.7);
  color: rgba(226, 232, 240, 0.85);
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  backdrop-filter: blur(8px);
  transition:
    background 160ms ease,
    border-color 160ms ease,
    transform 160ms ease;
}

.map-reset:hover {
  background: rgba(15, 23, 42, 0.92);
  border-color: rgba(var(--accent), 0.6);
  color: #fff;
  transform: scale(1.06);
}

.map-legend {
  position: absolute;
  left: 16px;
  bottom: 14px;
  z-index: 2;
  display: flex;
  gap: 12px;
  padding: 7px 12px;
  border-radius: 999px;
  background: rgba(8, 14, 26, 0.66);
  border: 1px solid rgba(148, 163, 184, 0.16);
  backdrop-filter: blur(8px);
  pointer-events: none;
}

.map-legend__item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: rgba(226, 232, 240, 0.82);
}

.map-legend__dot {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  box-shadow: 0 0 8px currentColor;
}

.map-legend__dot.is-success {
  background: #10b981;
  color: rgba(16, 185, 129, 0.7);
}

.map-legend__dot.is-warning {
  background: #f59e0b;
  color: rgba(245, 158, 11, 0.7);
}

.map-legend__dot.is-danger {
  background: #f43f5e;
  color: rgba(244, 63, 94, 0.7);
}

@media (prefers-reduced-motion: reduce) {
  .turkey-map-shell::before {
    animation: none;
    opacity: 0.85;
  }
}

@media (max-width: 1100px) {
  .map-legend {
    left: 10px;
    bottom: 10px;
    gap: 8px;
    padding: 5px 9px;
  }

  .map-legend__item {
    font-size: 10px;
  }
}

.turkey-echart {
  position: relative;
  z-index: 1;
  width: 100%;
  aspect-ratio: 1007 / 527;
  min-height: 320px;
}

@media (max-width: 1100px) {
  .turkey-echart {
    min-height: 240px;
  }
}
</style>
