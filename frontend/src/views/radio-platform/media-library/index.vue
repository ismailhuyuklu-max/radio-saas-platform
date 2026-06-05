<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

import { message } from 'ant-design-vue';

import {
  PART_LABELS,
  REGION_LABELS,
  REGION_LIST,
  getMediaLibrary,
  type MediaLibraryItem,
  type RegionCode,
} from '#/api/modules/radioMedia';

const loading = ref(true);
const items = ref<MediaLibraryItem[]>([]);

// --- filters --------------------------------------------------------------
type TypeKey = 'all' | 'news' | 'sports' | 'economy' | 'weather' | 'sponsor';
const TYPE_TABS: Array<{ key: TypeKey; label: string }> = [
  { key: 'all', label: 'Tümü' },
  { key: 'news', label: 'Haber' },
  { key: 'sports', label: 'Spor' },
  { key: 'economy', label: 'Ekonomi' },
  { key: 'weather', label: 'Hava Durumu' },
  { key: 'sponsor', label: 'Reklamlar' },
];
const activeType = ref<TypeKey>('all');
const regionFilter = ref<RegionCode | ''>('');
const search = ref('');

function typeOf(it: MediaLibraryItem): TypeKey {
  return it.kind === 'sponsor' ? 'sponsor' : (it.part_code as TypeKey);
}
function typeLabel(it: MediaLibraryItem): string {
  return it.kind === 'sponsor'
    ? 'Reklam'
    : (PART_LABELS[it.part_code as keyof typeof PART_LABELS] ?? it.part_code);
}

const filtered = computed(() => {
  const q = search.value.trim().toLocaleLowerCase('tr-TR');
  return items.value.filter((it) => {
    if (activeType.value !== 'all' && typeOf(it) !== activeType.value) return false;
    if (regionFilter.value && it.region_code !== regionFilter.value && !it.is_global) return false;
    if (q && !`${it.title} ${it.region_name}`.toLocaleLowerCase('tr-TR').includes(q)) return false;
    return true;
  });
});

const regionOptions = REGION_LIST.map((r) => ({ label: REGION_LABELS[r], value: r }));

async function load() {
  loading.value = true;
  try {
    const res = await getMediaLibrary();
    items.value = [...(res?.content ?? []), ...(res?.sponsors ?? [])];
  } catch {
    message.error('Medya kütüphanesi yüklenemedi.');
  } finally {
    loading.value = false;
  }
}

// --- player ---------------------------------------------------------------
const audio = ref<HTMLAudioElement | null>(null);
const current = ref<MediaLibraryItem | null>(null);
const playing = ref(false);
const currentTime = ref(0);
const duration = ref(0);
const volume = ref(0.9);
const shuffle = ref(false);
const repeat = ref(false);

function fmt(s: number): string {
  if (!Number.isFinite(s) || s < 0) return '0:00';
  const m = Math.floor(s / 60);
  const sec = Math.floor(s % 60);
  return `${m}:${sec.toString().padStart(2, '0')}`;
}

const progress = computed(() => (duration.value > 0 ? (currentTime.value / duration.value) * 100 : 0));

// --- waveform (Web Audio live visualiser) ---------------------------------
const waveCanvas = ref<HTMLCanvasElement | null>(null);
let audioCtx: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let srcNode: MediaElementAudioSourceNode | null = null;
let raf = 0;

function ensureGraph() {
  const el = audio.value;
  if (!el || audioCtx) return;
  try {
    const AC = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
    audioCtx = new AC();
    srcNode = audioCtx.createMediaElementSource(el);
    analyser = audioCtx.createAnalyser();
    analyser.fftSize = 128;
    srcNode.connect(analyser);
    analyser.connect(audioCtx.destination);
  } catch {
    audioCtx = null;
    analyser = null;
  }
}

function startWave() {
  cancelAnimationFrame(raf);
  const canvas = waveCanvas.value;
  if (!canvas || !analyser) return;
  const ctx2d = canvas.getContext('2d');
  if (!ctx2d) return;
  const bins = analyser.frequencyBinCount;
  const data = new Uint8Array(bins);
  const bars = 56;
  const render = () => {
    raf = requestAnimationFrame(render);
    if (!analyser) return;
    analyser.getByteFrequencyData(data);
    const w = canvas.width;
    const h = canvas.height;
    ctx2d.clearRect(0, 0, w, h);
    const bw = w / bars;
    const stepBin = Math.max(1, Math.floor(bins / bars));
    for (let i = 0; i < bars; i++) {
      const v = (data[i * stepBin] ?? 0) / 255;
      const bh = Math.max(2, v * h);
      ctx2d.fillStyle = `rgba(225,29,72,${0.35 + v * 0.65})`;
      ctx2d.fillRect(i * bw + 1, (h - bh) / 2, bw - 2, bh);
    }
  };
  render();
}

function stopWave() {
  cancelAnimationFrame(raf);
  raf = 0;
}

async function playItem(it: MediaLibraryItem) {
  current.value = it;
  await Promise.resolve();
  const el = audio.value;
  if (!el) return;
  ensureGraph();
  if (audioCtx?.state === 'suspended') {
    void audioCtx.resume();
  }
  el.src = it.url;
  el.volume = volume.value;
  try {
    await el.play();
    playing.value = true;
    startWave();
  } catch {
    message.warning('Oynatma başlatılamadı.');
  }
}

function togglePlay() {
  const el = audio.value;
  if (!el || !current.value) return;
  if (el.paused) {
    if (audioCtx?.state === 'suspended') void audioCtx.resume();
    void el.play();
    playing.value = true;
    startWave();
  } else {
    el.pause();
    playing.value = false;
    stopWave();
  }
}

function onSeek(event: Event) {
  const el = audio.value;
  if (!el || !duration.value) return;
  el.currentTime = (Number((event.target as HTMLInputElement).value) / 100) * duration.value;
}

function onVolume(event: Event) {
  volume.value = Number((event.target as HTMLInputElement).value);
  if (audio.value) audio.value.volume = volume.value;
}

function step(delta: number) {
  const list = filtered.value;
  if (!current.value || list.length === 0) return;
  if (shuffle.value && list.length > 1) {
    let r = current.value;
    let next = list[Math.floor(Math.random() * list.length)];
    let guard = 0;
    while (next && next.id === r.id && next.kind === r.kind && guard < 8) {
      next = list[Math.floor(Math.random() * list.length)];
      guard++;
    }
    if (next) void playItem(next);
    return;
  }
  const idx = list.findIndex((i) => i.id === current.value?.id && i.kind === current.value?.kind);
  const next = list[(idx + delta + list.length) % list.length];
  if (next) void playItem(next);
}

function playAll() {
  const list = filtered.value;
  if (!list.length) return;
  const first = shuffle.value ? list[Math.floor(Math.random() * list.length)] : list[0];
  void playItem(first);
}

function isCurrent(it: MediaLibraryItem): boolean {
  return current.value?.id === it.id && current.value?.kind === it.kind;
}

async function download(it: MediaLibraryItem, event?: Event) {
  event?.stopPropagation();
  try {
    const res = await fetch(it.url, { credentials: 'include' });
    if (!res.ok) throw new Error('fail');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const safe = it.title.replace(/[^\p{L}\p{N}\-_ ]/gu, '').trim() || 'medya';
    a.download = `${safe}.mp3`;
    document.body.append(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    message.success('İndiriliyor…');
  } catch {
    message.error('İndirilemedi.');
  }
}

function onTimeUpdate() {
  currentTime.value = audio.value?.currentTime ?? 0;
}
function onLoaded() {
  duration.value = audio.value?.duration ?? 0;
}
function onEnded() {
  if (repeat.value && audio.value) {
    audio.value.currentTime = 0;
    void audio.value.play();
    return;
  }
  playing.value = false;
  stopWave();
  step(1);
}

onMounted(load);
onUnmounted(() => {
  stopWave();
  audio.value?.pause();
  void audioCtx?.close();
});
</script>

<template>
  <div class="ml">
    <header class="ml__head">
      <div>
        <h1>Medya Kütüphanesi</h1>
        <p class="ml__sub">
          Haber, spor, ekonomi, hava ve reklam ses dosyalarını dinleyin · {{ items.length }} kayıt
        </p>
      </div>
      <button type="button" class="ml__playall" :disabled="!filtered.length" @click="playAll">
        ▶ Tümünü Çal ({{ filtered.length }})
      </button>
    </header>

    <!-- filters -->
    <div class="ml__filters">
      <div class="ml__tabs">
        <button
          v-for="t in TYPE_TABS"
          :key="t.key"
          type="button"
          class="ml__tab"
          :class="{ 'is-active': activeType === t.key }"
          @click="activeType = t.key"
        >
          {{ t.label }}
        </button>
      </div>
      <div class="ml__filters-right">
        <select v-model="regionFilter" class="ml__select">
          <option value="">Tüm bölgeler</option>
          <option v-for="o in regionOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <input v-model="search" class="ml__search" placeholder="Ara…">
      </div>
    </div>

    <!-- track list -->
    <div class="ml__list ui-card">
      <p v-if="loading" class="ml__empty">Yükleniyor…</p>
      <p v-else-if="!filtered.length" class="ml__empty">Eşleşen medya yok.</p>
      <div
        v-for="it in filtered"
        :key="it.kind + it.id"
        class="ml__row"
        :class="{ 'is-current': isCurrent(it) }"
        role="button"
        tabindex="0"
        @click="playItem(it)"
        @keydown.enter="playItem(it)"
      >
        <span class="ml__row-play">
          <svg v-if="isCurrent(it) && playing" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></svg>
          <svg v-else viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
        </span>
        <span class="ml__row-main">
          <strong>{{ it.title }}</strong>
          <small>
            <span class="ml__chip" :class="`is-${typeOf(it)}`">{{ typeLabel(it) }}</span>
            {{ it.is_global ? 'Tüm bölgeler' : it.region_name }}
            <template v-if="it.slot_time"> · {{ it.slot_time }}</template>
            <template v-if="it.kind === 'sponsor' && it.placement_type"> · {{ it.placement_type === 'intro' ? 'Sunar' : 'Sundu' }}</template>
          </small>
        </span>
        <span v-if="isCurrent(it)" class="ml__row-eq"><i /><i /><i /></span>
        <button type="button" class="ml__dl" title="İndir" @click="download(it, $event)">⤓</button>
      </div>
    </div>

    <!-- sticky player -->
    <div class="ml__player" :class="{ 'is-active': !!current }">
      <div class="ml__player-info">
        <span class="ml__player-art">♪</span>
        <div class="ml__player-meta">
          <strong>{{ current ? current.title : 'Bir parça seçin' }}</strong>
          <small>{{ current ? (current.is_global ? 'Tüm bölgeler' : current.region_name) : '—' }}</small>
        </div>
        <canvas ref="waveCanvas" class="ml__wave" width="160" height="38" />
      </div>

      <div class="ml__player-controls">
        <div class="ml__player-btns">
          <button type="button" class="ml__pbtn" :class="{ 'is-on': shuffle }" title="Karıştır" @click="shuffle = !shuffle">⇄</button>
          <button type="button" class="ml__pbtn" :disabled="!current" aria-label="Önceki" @click="step(-1)">⏮</button>
          <button type="button" class="ml__pbtn ml__pbtn--main" :disabled="!current" aria-label="Oynat/Duraklat" @click="togglePlay">
            {{ playing ? '❚❚' : '▶' }}
          </button>
          <button type="button" class="ml__pbtn" :disabled="!current" aria-label="Sonraki" @click="step(1)">⏭</button>
          <button type="button" class="ml__pbtn" :class="{ 'is-on': repeat }" title="Tekrar" @click="repeat = !repeat">↻</button>
        </div>
        <div class="ml__seek">
          <span class="ml__time">{{ fmt(currentTime) }}</span>
          <input
            type="range"
            class="ml__range"
            min="0"
            max="100"
            step="0.1"
            :value="progress"
            :style="{ '--p': progress + '%' }"
            :disabled="!current"
            @input="onSeek"
          >
          <span class="ml__time">{{ fmt(duration) }}</span>
        </div>
      </div>

      <div class="ml__player-vol">
        <button v-if="current" type="button" class="ml__pbtn" title="İndir" @click="download(current)">⤓</button>
        <span>🔊</span>
        <input type="range" class="ml__range ml__range--vol" min="0" max="1" step="0.01" :value="volume" @input="onVolume">
      </div>
    </div>

    <audio
      ref="audio"
      preload="metadata"
      @timeupdate="onTimeUpdate"
      @loadedmetadata="onLoaded"
      @play="playing = true"
      @pause="playing = false"
      @ended="onEnded"
    />
  </div>
</template>

<style scoped>
.ml {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
  padding-bottom: 104px;
}
.ml__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}
.ml__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.ml__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.ml__playall {
  padding: 9px 16px;
  border: none;
  border-radius: 10px;
  background: var(--c-brand);
  color: #fff;
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
}
.ml__playall:disabled {
  opacity: 0.5;
  cursor: default;
}

.ml__filters {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  flex-wrap: wrap;
}
.ml__tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.ml__tab {
  padding: 7px 14px;
  border: 1px solid var(--c-line);
  border-radius: 999px;
  background: transparent;
  color: var(--c-text-2);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
  transition: all 150ms ease;
}
.ml__tab.is-active {
  background: var(--c-brand);
  border-color: var(--c-brand);
  color: #fff;
}
.ml__filters-right {
  display: flex;
  gap: 8px;
}
.ml__select,
.ml__search {
  padding: 8px 12px;
  border: 1px solid var(--c-line);
  border-radius: 10px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: var(--t-sm);
}
.ml__search {
  width: 160px;
}

.ml__list {
  padding: 8px;
  display: flex;
  flex-direction: column;
}
.ml__row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  transition: background 120ms ease;
}
.ml__row:hover {
  background: rgba(148, 163, 184, 0.08);
}
.ml__row.is-current {
  background: rgba(225, 29, 72, 0.1);
}
.ml__row-play {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.12);
  color: var(--c-text-2);
}
.ml__row.is-current .ml__row-play {
  background: var(--c-brand);
  color: #fff;
}
.ml__row-play svg {
  width: 16px;
  height: 16px;
}
.ml__row-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ml__row-main strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ml__row-main small {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.ml__chip {
  padding: 1px 7px;
  border-radius: 999px;
  font-size: 9.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--c-text-2);
  background: rgba(148, 163, 184, 0.16);
}
.ml__chip.is-news { color: #3b82f6; background: rgba(59, 130, 246, 0.14); }
.ml__chip.is-sports { color: #22c55e; background: rgba(34, 197, 94, 0.14); }
.ml__chip.is-economy { color: #f59e0b; background: rgba(245, 158, 11, 0.14); }
.ml__chip.is-weather { color: #06b6d4; background: rgba(6, 182, 212, 0.14); }
.ml__chip.is-sponsor { color: var(--c-brand); background: rgba(225, 29, 72, 0.14); }

.ml__row-eq {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 16px;
}
.ml__row-eq i {
  width: 3px;
  background: var(--c-brand);
  border-radius: 2px;
  animation: eq 0.9s ease-in-out infinite;
}
.ml__row-eq i:nth-child(1) { height: 60%; animation-delay: 0s; }
.ml__row-eq i:nth-child(2) { height: 100%; animation-delay: 0.2s; }
.ml__row-eq i:nth-child(3) { height: 40%; animation-delay: 0.4s; }
@keyframes eq { 0%, 100% { transform: scaleY(0.4); } 50% { transform: scaleY(1); } }

.ml__dl {
  flex-shrink: 0;
  width: 30px;
  height: 30px;
  border: 1px solid var(--c-line);
  border-radius: 8px;
  background: transparent;
  color: var(--c-text-3);
  font-size: 15px;
  cursor: pointer;
  opacity: 0;
  transition: opacity 120ms ease, color 120ms ease, border-color 120ms ease;
}
.ml__row:hover .ml__dl,
.ml__row.is-current .ml__dl {
  opacity: 1;
}
.ml__dl:hover {
  color: var(--c-brand);
  border-color: var(--c-brand);
}

.ml__empty {
  padding: 28px 0;
  text-align: center;
  color: var(--c-text-3);
}

/* sticky player */
.ml__player {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 50;
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
  padding: 10px 16px;
  background: rgba(9, 13, 22, 0.96);
  border-top: 1px solid rgba(148, 163, 184, 0.16);
  backdrop-filter: blur(14px);
}
.ml__player-info {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.ml__player-art {
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 10px;
  background: linear-gradient(135deg, #e11d48, #fb7185);
  color: #fff;
  font-size: 18px;
}
.ml__player-meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.ml__player-meta strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ml__player-meta small {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.ml__wave {
  width: 120px;
  height: 38px;
  flex-shrink: 0;
  opacity: 0.9;
}
.ml__player-controls {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ml__player-btns {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
.ml__pbtn {
  border: none;
  background: transparent;
  color: var(--c-text-2);
  font-size: 15px;
  cursor: pointer;
}
.ml__pbtn.is-on {
  color: var(--c-brand);
}
.ml__pbtn--main {
  width: 40px;
  height: 40px;
  border-radius: 999px;
  background: var(--c-brand);
  color: #fff;
  font-size: 15px;
}
.ml__pbtn:disabled {
  opacity: 0.4;
  cursor: default;
}
.ml__seek {
  display: flex;
  align-items: center;
  gap: 10px;
}
.ml__time {
  font-size: 11px;
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
  min-width: 34px;
  text-align: center;
}
.ml__range {
  -webkit-appearance: none;
  appearance: none;
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--c-brand) var(--p, 0%), rgba(148, 163, 184, 0.2) var(--p, 0%));
  cursor: pointer;
}
.ml__range::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 13px;
  height: 13px;
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 0 6px rgba(225, 29, 72, 0.6);
}
.ml__range--vol {
  flex: none;
  width: 90px;
  --p: 0%;
  background: rgba(148, 163, 184, 0.2);
}
.ml__player-vol {
  display: flex;
  align-items: center;
  gap: 8px;
  justify-content: center;
}

@media (min-width: 900px) {
  .ml__player {
    grid-template-columns: minmax(200px, 1fr) minmax(0, 2fr) minmax(160px, 1fr);
    align-items: center;
    gap: 24px;
    padding: 10px 28px;
  }
  .ml__player-vol {
    justify-content: flex-end;
  }
}
</style>
