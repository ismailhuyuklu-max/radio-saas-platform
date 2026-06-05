<script lang="ts" setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import type { RegionCode } from '#/api/modules/radioMedia';

import rawTurkeySvg from '../assets/turkiye-svg-haritasi.svg?raw';

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

const emit = defineEmits<{
  (event: 'select-region', regionCode: RegionCode): void;
}>();

const hoveredRegion = ref<RegionCode | null>(null);
const mapRootRef = ref<HTMLDivElement | null>(null);

const activeRegionCode = computed<RegionCode>(
  () => hoveredRegion.value ?? props.selectedRegionCode,
);

const activeRegionTone = computed(
  () => props.regionStates?.[activeRegionCode.value]?.dominantTone ?? 'danger',
);

const svgMarkup = computed(() => rawTurkeySvg);

function repairMojibake(value: string): string {
  if (!value.includes('\uFFFD')) {
    return value;
  }

  try {
    return decodeURIComponent(escape(value));
  } catch {
    return value;
  }
}

function calculateFontSize(label: string, bbox: DOMRect): number {
  const widthBased = bbox.width / Math.max(1, label.length * 0.64);
  const heightBased = bbox.height * 0.3;
  return Math.max(5.8, Math.min(10.5, widthBased, heightBased));
}

interface LabelPlacement {
  dx: number;
  dy: number;
  fontSize?: number;
  anchor?: 'start' | 'middle' | 'end';
  textScale?: number;
}

const labelPlacements: Partial<Record<string, LabelPlacement>> = {
  adiyaman: { dx: -2, dy: -4, fontSize: 9.6 },
  agri: { dx: 8, dy: -4, fontSize: 9.2 },
  balikesir: { dx: -8, dy: 10, fontSize: 8.8, anchor: 'start' },
  bartin: { dx: 0, dy: -8, fontSize: 8.6 },
  bitlis: { dx: 10, dy: 6, fontSize: 8.7, anchor: 'start' },
  bolu: { dx: 2, dy: -4, fontSize: 8.9 },
  bursa: { dx: 10, dy: 8, fontSize: 8.8, anchor: 'start' },
  canakkale: { dx: -12, dy: -8, fontSize: 8.6, anchor: 'end' },
  cankiri: { dx: 0, dy: -10, fontSize: 8.6 },
  corum: { dx: 10, dy: -6, fontSize: 8.7, anchor: 'start' },
  edirne: { dx: -10, dy: -8, fontSize: 8.5, anchor: 'end' },
  elazig: { dx: 8, dy: 2, fontSize: 9.2 },
  erzincan: { dx: -2, dy: -6, fontSize: 8.7 },
  erzurum: { dx: 12, dy: -4, fontSize: 8.8, anchor: 'start' },
  eskisehir: { dx: -10, dy: -4, fontSize: 8.7, anchor: 'end' },
  gaziantep: { dx: 10, dy: 0, fontSize: 8.6, anchor: 'start' },
  giresun: { dx: 0, dy: -10, fontSize: 8.7 },
  gumushane: { dx: 12, dy: -6, fontSize: 8.5, anchor: 'start' },
  hakkari: { dx: 12, dy: 6, fontSize: 8.6, anchor: 'start' },
  izmir: { dx: 0, dy: -4, fontSize: 10 },
  kirikkale: { dx: 0, dy: -8, fontSize: 8.7 },
  kirklareli: { dx: 0, dy: -8, fontSize: 8.5 },
  kirsehir: { dx: 10, dy: 8, fontSize: 8.7, anchor: 'start' },
  kahramanmaras: { dx: 10, dy: 4, fontSize: 8.4, anchor: 'start' },
  kars: { dx: 6, dy: -6, fontSize: 8.5, anchor: 'start' },
  kocaeli: { dx: 14, dy: 8, fontSize: 8.6, anchor: 'start' },
  sakarya: { dx: 14, dy: 10, fontSize: 8.6, anchor: 'start' },
  samsun: { dx: 0, dy: -8, fontSize: 8.7 },
  siirt: { dx: 10, dy: 8, fontSize: 8.6, anchor: 'start' },
  sivas: { dx: 0, dy: -4, fontSize: 8.8 },
  tekirdag: { dx: 10, dy: 8, fontSize: 8.6, anchor: 'start' },
  trabzon: { dx: 12, dy: -8, fontSize: 8.6, anchor: 'start' },
  van: { dx: 12, dy: 2, fontSize: 8.6, anchor: 'start' },
  usak: { dx: -8, dy: 8, fontSize: 8.8, anchor: 'end' },
  adana: { dx: 8, dy: 6, fontSize: 8.7, anchor: 'start' },
  antalya: { dx: 10, dy: 8, fontSize: 8.6, anchor: 'start' },
  aydin: { dx: -8, dy: 8, fontSize: 8.7, anchor: 'end' },
  mugla: { dx: -10, dy: 10, fontSize: 8.4, anchor: 'end' },
  mus: { dx: 12, dy: 2, fontSize: 8.5, anchor: 'start' },
  bingol: { dx: 10, dy: -4, fontSize: 8.6, anchor: 'start' },
  diyarbakir: { dx: 10, dy: 6, fontSize: 8.5, anchor: 'start' },
  artvin: { dx: 6, dy: -8, fontSize: 8.4, anchor: 'start' },
  ardahan: { dx: 6, dy: -8, fontSize: 8.3, anchor: 'start' },
  igdir: { dx: 12, dy: 0, fontSize: 8.2, anchor: 'start' },
  tokat: { dx: 2, dy: -6, fontSize: 8.7 },
  yozgat: { dx: 0, dy: 6, fontSize: 8.7 },
  kayseri: { dx: 0, dy: 4, fontSize: 8.7 },
  konya: { dx: 0, dy: 8, fontSize: 8.8 },
  ankara: { dx: 0, dy: -6, fontSize: 8.8 },
  burdur: { dx: -4, dy: 6, fontSize: 8.6 },
  nevsehir: { dx: 10, dy: 2, fontSize: 8.6, anchor: 'start' },
  nigde: { dx: 10, dy: 4, fontSize: 8.5, anchor: 'start' },
  osmaniye: { dx: 8, dy: 4, fontSize: 8.6, anchor: 'start' },
  karabuk: { dx: 0, dy: -8, fontSize: 8.4 },
  kastamonu: { dx: 0, dy: -8, fontSize: 8.4 },
};

interface InjectedLabelRecord {
  element: SVGTextElement;
  regionCode: RegionCode;
  baseX: number;
  baseY: number;
  fontSize: number;
  groupBox: DOMRect;
}

function overlapArea(a: DOMRect, b: DOMRect): number {
  const overlapWidth = Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left));
  const overlapHeight = Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top));
  return overlapWidth * overlapHeight;
}

function shiftLabelText(text: SVGTextElement, x: number, y: number) {
  text.setAttribute('x', `${x}`);
  text.setAttribute('y', `${y}`);
}

function stabilizeInjectedLabels(labels: InjectedLabelRecord[]) {
  const regionBuckets = new Map<RegionCode, InjectedLabelRecord[]>();

  labels.forEach((label) => {
    const bucket = regionBuckets.get(label.regionCode);
    if (bucket) {
      bucket.push(label);
    } else {
      regionBuckets.set(label.regionCode, [label]);
    }
  });

  const candidateOffsets = [0, -8, 8, -14, 14, -20, 20, -26, 26];

  regionBuckets.forEach((bucket) => {
    const ordered = [...bucket].sort((left, right) => {
      const leftBox = left.element.getBBox();
      const rightBox = right.element.getBBox();
      return leftBox.y === rightBox.y ? leftBox.x - rightBox.x : leftBox.y - rightBox.y;
    });

    const occupiedBoxes: DOMRect[] = [];

    ordered.forEach((record) => {
      let bestScore = Number.POSITIVE_INFINITY;
      let bestPosition = { x: record.baseX, y: record.baseY };
      const cityBox = record.groupBox;
      const limitTop = cityBox ? cityBox.y + record.fontSize * 0.55 : Number.NEGATIVE_INFINITY;
      const limitBottom = cityBox ? cityBox.y + cityBox.height - record.fontSize * 0.55 : Number.POSITIVE_INFINITY;

      candidateOffsets.forEach((offsetY) => {
        const boundedY = Math.min(limitBottom, Math.max(limitTop, record.baseY + offsetY));
        shiftLabelText(record.element, record.baseX, boundedY);
        const candidateBox = record.element.getBBox();
        const collisionScore = occupiedBoxes.reduce((score, occupied) => score + overlapArea(candidateBox, occupied), 0);
        const movementPenalty = Math.abs(boundedY - record.baseY) * 7;
        const totalScore = collisionScore + movementPenalty;

        if (totalScore < bestScore) {
          bestScore = totalScore;
          bestPosition = { x: record.baseX, y: boundedY };
        }
      });

      shiftLabelText(record.element, bestPosition.x, bestPosition.y);
      occupiedBoxes.push(record.element.getBBox());
    });
  });
}

function extractRegionCode(target: EventTarget | null): RegionCode | null {
  if (!(target instanceof Element)) {
    return null;
  }

  const regionGroup = target.closest('[data-region]');
  const regionCode = regionGroup?.getAttribute('data-region');

  if (
    regionCode === 'marmara' ||
    regionCode === 'ege' ||
    regionCode === 'akdeniz' ||
    regionCode === 'karadeniz' ||
    regionCode === 'ic-anadolu' ||
    regionCode === 'dogu-anadolu' ||
    regionCode === 'guneydogu-anadolu'
  ) {
    return regionCode;
  }

  return null;
}

function handlePointerMove(event: PointerEvent) {
  const regionCode = extractRegionCode(event.target);
  if (!regionCode || regionCode === hoveredRegion.value) {
    return;
  }

  hoveredRegion.value = regionCode;
}

function handlePointerLeave() {
  hoveredRegion.value = null;
}

function handleClick(event: MouseEvent) {
  const regionCode = extractRegionCode(event.target);
  if (!regionCode) {
    return;
  }

  emit('select-region', regionCode);
}

function clearInjectedCityLabels(svg: SVGSVGElement) {
  svg.querySelectorAll<SVGTextElement>('.injected-city-label').forEach((label) => {
    label.remove();
  });
}

function injectCityLabels() {
  const root = mapRootRef.value;
  if (!root) {
    return;
  }

  const svg = root.querySelector<SVGSVGElement>('svg');
  if (!svg) {
    return;
  }

  clearInjectedCityLabels(svg);

  const cityGroups = Array.from(svg.querySelectorAll<SVGGElement>('#turkiye > g[data-iladi]'));
  const injectedLabels: InjectedLabelRecord[] = [];

  cityGroups.forEach((cityGroup) => {
    const rawLabel = cityGroup.dataset.iladi?.trim();
    if (!rawLabel) {
      return;
    }

    const label = repairMojibake(rawLabel);
    const bbox = cityGroup.getBBox();
    if (!Number.isFinite(bbox.x) || !Number.isFinite(bbox.y)) {
      return;
    }

    const placement = (labelPlacements[cityGroup.id] ?? {}) as LabelPlacement;
    const fontSize = placement.fontSize ?? calculateFontSize(label, bbox);
    const textLength = Math.max(16, Math.min(bbox.width * 0.84, label.length * fontSize * 0.48));
    const anchor = placement.anchor ?? 'middle';
    const x = bbox.x + bbox.width / 2 + (placement.dx ?? 0);
    const y = bbox.y + bbox.height / 2 + fontSize * 0.04 + (placement.dy ?? 0);

    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.classList.add('injected-city-label');
    if (cityGroup.dataset.region === activeRegionCode.value) {
      text.classList.add('is-active-region');
    }
    text.setAttribute('x', `${x}`);
    text.setAttribute('y', `${y}`);
    text.setAttribute('text-anchor', anchor);
    text.setAttribute('dominant-baseline', 'middle');
    text.setAttribute('font-size', `${fontSize.toFixed(2)}`);
    text.setAttribute('textLength', `${textLength.toFixed(2)}`);
    text.setAttribute('lengthAdjust', 'spacingAndGlyphs');
    text.textContent = label;

    cityGroup.appendChild(text);
    injectedLabels.push({
      element: text,
      regionCode: cityGroup.dataset.region as RegionCode,
      baseX: x,
      baseY: y,
      fontSize,
      groupBox: bbox,
    });
  });

  window.requestAnimationFrame(() => {
    stabilizeInjectedLabels(injectedLabels);
    injectedLabels.forEach((label) => {
      label.element.classList.add('is-visible');
    });
  });
}

function scheduleLabelRefresh() {
  void nextTick(() => {
    injectCityLabels();
  });
}

watch(
  hoveredRegion,
  (regionCode) => {
    if (regionCode) {
      emit('select-region', regionCode);
    }
  },
  { flush: 'post' },
);

watch(
  () => activeRegionCode.value,
  () => {
    scheduleLabelRefresh();
  },
  { immediate: true, flush: 'post' },
);

onMounted(() => {
  scheduleLabelRefresh();
});
</script>

<template>
  <div
    ref="mapRootRef"
    class="turkey-map-shell"
    :class="[`tone-${activeRegionTone}`]"
    :data-active-region="activeRegionCode"
    @click="handleClick"
    @pointermove="handlePointerMove"
    @pointerleave="handlePointerLeave"
  >
    <!-- eslint-disable-next-line vue/no-v-html -- Bundled static SVG asset, not user-controlled HTML. -->
    <div class="turkey-map-stage" v-html="svgMarkup" />
  </div>
</template>

<style>
.turkey-map-shell {
  /* Status colour for the selected region glow + ambiance (default: danger/rose). */
  --accent: 244, 63, 94;
  position: relative;
  overflow: hidden;
  border-radius: 28px;
  border: 1px solid rgba(148, 163, 184, 0.14);
  isolation: isolate;
  /* Premium opaque deep-water base — wins over any inherited tone-* flood.
     Sea and the lake cut-outs read as water rather than flat dark. */
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

/* Soft tone ambiance + edge vignette, behind the map silhouette. */
.turkey-map-shell::before,
.turkey-map-shell::after {
  content: "";
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}

.turkey-map-shell::before {
  background: radial-gradient(58% 50% at 50% 46%, rgba(var(--accent), 0.12), transparent 72%);
  transition: background 320ms ease;
}

.turkey-map-shell::after {
  background: radial-gradient(132% 120% at 50% 50%, transparent 56%, rgba(2, 6, 23, 0.55) 100%);
}

.turkey-map-stage {
  position: relative;
  z-index: 1;
  width: 100%;
  min-height: 0;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 10px 12px 14px;
  box-sizing: border-box;
  line-height: 0;
}

.turkey-map-stage svg {
  display: block;
  width: 102%;
  height: auto;
  max-width: 100%;
  overflow: visible;
  transform: translateY(-1px) scale(1.02);
  transform-origin: top center;
}

/* Float the whole Turkey silhouette above the water: soft depth shadow below
   plus a faint coastline rim-light where land meets the sea. */
.turkey-map-shell svg #turkiye {
  filter:
    drop-shadow(0 12px 26px rgba(0, 0, 0, 0.55))
    drop-shadow(0 0 1.5px rgba(125, 211, 252, 0.35));
}

.turkey-map-shell svg #turkiye > g[data-region] {
  cursor: pointer;
  transform-box: fill-box;
  transform-origin: center;
  transition:
    transform 180ms ease,
    filter 180ms ease,
    opacity 180ms ease;
}

.turkey-map-shell svg #turkiye > g[data-region] path {
  fill: #1b2740;
  stroke: rgba(148, 163, 184, 0.20);
  stroke-width: 0.85;
  stroke-linejoin: round;
  transition:
    fill 200ms ease,
    stroke 200ms ease,
    filter 200ms ease,
    opacity 200ms ease;
}

.turkey-map-shell svg #turkiye > g[data-region]:hover {
  transform: scale(1.015);
}

.turkey-map-shell svg #turkiye > g[data-region]:hover path {
  fill: #27375a;
  stroke: rgba(255, 255, 255, 0.82);
  filter: drop-shadow(0 0 9px rgba(var(--accent), 0.55));
}

.turkey-map-shell[data-active-region='marmara'] svg #turkiye > g[data-region='marmara'] path,
.turkey-map-shell[data-active-region='ege'] svg #turkiye > g[data-region='ege'] path,
.turkey-map-shell[data-active-region='akdeniz'] svg #turkiye > g[data-region='akdeniz'] path,
.turkey-map-shell[data-active-region='karadeniz'] svg #turkiye > g[data-region='karadeniz'] path,
.turkey-map-shell[data-active-region='ic-anadolu'] svg #turkiye > g[data-region='ic-anadolu'] path,
.turkey-map-shell[data-active-region='dogu-anadolu'] svg #turkiye > g[data-region='dogu-anadolu'] path,
.turkey-map-shell[data-active-region='guneydogu-anadolu'] svg #turkiye > g[data-region='guneydogu-anadolu'] path {
  fill: rgba(var(--accent), 0.18);
  stroke: rgba(var(--accent), 0.98);
  stroke-width: 1.2;
  animation: turkeyRegionPulse 2.6s ease-in-out infinite;
}

/* Selected region "breathes" in its status colour. */
@keyframes turkeyRegionPulse {
  0%,
  100% {
    filter:
      drop-shadow(0 0 7px rgba(var(--accent), 0.55))
      drop-shadow(0 0 18px rgba(var(--accent), 0.22));
  }

  50% {
    filter:
      drop-shadow(0 0 14px rgba(var(--accent), 0.9))
      drop-shadow(0 0 34px rgba(var(--accent), 0.42));
  }
}

@media (prefers-reduced-motion: reduce) {
  .turkey-map-shell svg #turkiye > g[data-region] path {
    animation: none !important;
    filter: drop-shadow(0 0 10px rgba(var(--accent), 0.6));
  }
}

.turkey-map-shell svg #turkiye > g[data-region] text {
  fill: #e8eef7;
  stroke: rgba(6, 11, 22, 0.92);
  stroke-width: 2.6px;
  paint-order: stroke fill;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.004em;
  text-rendering: geometricPrecision;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  pointer-events: none;
  opacity: 0.8;
  transition:
    opacity 0.35s ease-in-out,
    filter 0.35s ease-in-out,
    transform 0.35s ease-in-out;
}

.turkey-map-shell svg #turkiye > g[data-region] text.is-visible {
  filter: none;
}

.turkey-map-shell svg #turkiye > g[data-region] text.is-active-region {
  fill: #ffffff;
  opacity: 1;
  stroke-width: 2.9px;
}

@media (max-width: 1100px) {
  .turkey-map-stage {
    padding: 8px 8px 10px;
  }

  .turkey-map-shell svg #turkiye > g[data-region] text {
    font-size: 10px;
    stroke-width: 2.4px;
  }
}
</style>
