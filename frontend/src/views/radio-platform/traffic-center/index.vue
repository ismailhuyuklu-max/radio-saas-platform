<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs, { type Dayjs } from 'dayjs';

import { DatePicker, message } from 'ant-design-vue';

import {
  REGION_LABELS,
  REGION_LIST,
  bulkPlan,
  getStations,
  type BulkPlanResult,
  type RegionCode,
  type StationItem,
} from '#/api/modules/radioMedia';
import {
  CONTENT_TYPES,
  PROVINCES,
  TEMPLATES,
  contentType,
  provincesToRegions,
  type QuickTemplate,
} from '#/utils/traffic';

type Scope = 'all' | 'region' | 'province' | 'station';
interface SlotRow {
  slot_time: string;
  part_code: string;
  content_title: string;
  status: string;
}

const scope = ref<Scope>('all');
const selectedRegions = ref<RegionCode[]>([...REGION_LIST]);
const selectedProvinces = ref<string[]>([]);
const selectedStations = ref<string[]>([]);
const provinceSearch = ref('');
const stations = ref<StationItem[]>([]);

const slots = ref<SlotRow[]>([
  { slot_time: '08:00', part_code: 'news', content_title: 'Sabah Haber Bülteni', status: 'published' },
]);

const startDate = ref<Dayjs>(dayjs());
const repeatDays = ref(7);

const submitting = ref(false);
const result = ref<BulkPlanResult | null>(null);

const SCOPES: Array<{ key: Scope; label: string; icon: string }> = [
  { key: 'all', label: 'Tüm Türkiye', icon: '🇹🇷' },
  { key: 'region', label: 'Bölge', icon: '🗺️' },
  { key: 'province', label: 'İl', icon: '📍' },
  { key: 'station', label: 'Radyo', icon: '📻' },
];

const filteredProvinces = computed(() => {
  const q = provinceSearch.value.trim().toLocaleLowerCase('tr-TR');
  return PROVINCES.filter((p) => !q || p.name.toLocaleLowerCase('tr-TR').includes(q));
});

// resolve the current targeting to region codes / province names / station ids
const resolvedRegions = computed<string[]>(() => {
  if (scope.value === 'all') return [...REGION_LIST];
  if (scope.value === 'region') return selectedRegions.value;
  return [];
});
// İl scope sends real province names → backend writes il-keyed plans and runs
// the il-level conflict engine (not a whole-region fallback).
const resolvedProvinces = computed<string[]>(() =>
  scope.value === 'province' ? selectedProvinces.value : [],
);
const resolvedStationIds = computed<string[]>(() =>
  scope.value === 'station' ? selectedStations.value : [],
);

const targetCount = computed(() => {
  if (scope.value === 'station') return resolvedStationIds.value.length;
  if (scope.value === 'province') return resolvedProvinces.value.length;
  return resolvedRegions.value.length;
});
const estimate = computed(() => targetCount.value * slots.value.length * Math.max(1, repeatDays.value));

const canSubmit = computed(() => targetCount.value > 0 && slots.value.length > 0 && !submitting.value);

function toggleRegion(r: RegionCode) {
  const i = selectedRegions.value.indexOf(r);
  if (i >= 0) selectedRegions.value.splice(i, 1);
  else selectedRegions.value.push(r);
}
function toggleProvince(name: string) {
  const i = selectedProvinces.value.indexOf(name);
  if (i >= 0) selectedProvinces.value.splice(i, 1);
  else selectedProvinces.value.push(name);
}
function toggleStation(id: string) {
  const i = selectedStations.value.indexOf(id);
  if (i >= 0) selectedStations.value.splice(i, 1);
  else selectedStations.value.push(id);
}

function applyTemplate(t: QuickTemplate) {
  slots.value = t.slots.map((s) => ({ ...s }));
  message.info(`${t.label} kuşağı uygulandı.`);
}
function addSlot() {
  slots.value.push({ slot_time: '08:00', part_code: 'news', content_title: 'Yeni Yayın', status: 'published' });
}
function removeSlot(i: number) {
  slots.value.splice(i, 1);
}

function setRepeat(n: number) {
  repeatDays.value = n;
}

async function submit() {
  if (!canSubmit.value) {
    message.warning('Hedef ve en az bir kuşak seçin.');
    return;
  }
  submitting.value = true;
  result.value = null;
  try {
    const res = await bulkPlan({
      target_regions: resolvedRegions.value,
      target_provinces: resolvedProvinces.value,
      station_ids: resolvedStationIds.value,
      slots: slots.value,
      start_date: startDate.value.format('YYYY-MM-DD'),
      repeat_days: repeatDays.value,
    });
    result.value = res.result;
    message.success(`${res.result.created} plan oluşturuldu.`);
  } catch {
    message.error('Toplu planlama başarısız.');
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    const s = await getStations();
    stations.value = Array.isArray(s) ? s : [];
  } catch {
    /* ignore */
  }
});
</script>

<template>
  <div class="tc">
    <header class="tc__head">
      <div>
        <h1>Yayın Trafik Merkezi</h1>
        <p class="tc__sub">Tüm Türkiye'yi tek işlemde planlayın · hedefle, kuşağı seç, tekrarla</p>
      </div>
    </header>

    <div class="tc__grid">
      <div class="tc__main">
        <!-- 1. Targeting engine -->
        <section class="ui-card tc__card">
          <div class="tc__step"><span>1</span> Hedefleme Motoru</div>
          <div class="tc__scopes">
            <button
              v-for="s in SCOPES"
              :key="s.key"
              type="button"
              class="tc__scope"
              :class="{ 'is-active': scope === s.key }"
              @click="scope = s.key"
            >
              <span>{{ s.icon }}</span>{{ s.label }}
            </button>
          </div>

          <div v-if="scope === 'all'" class="tc__all">
            🇹🇷 <strong>Tüm Türkiye</strong> — 7 bölgenin tamamı tek işlemde planlanacak.
          </div>

          <div v-else-if="scope === 'region'" class="tc__chips">
            <button
              v-for="r in REGION_LIST"
              :key="r"
              type="button"
              class="tc__chip"
              :class="{ 'is-on': selectedRegions.includes(r) }"
              @click="toggleRegion(r)"
            >
              {{ REGION_LABELS[r] }}
            </button>
          </div>

          <div v-else-if="scope === 'province'" class="tc__provinces">
            <input v-model="provinceSearch" class="tc__search" placeholder="İl ara… (81 il)">
            <div class="tc__chips tc__chips--scroll">
              <button
                v-for="p in filteredProvinces"
                :key="p.name"
                type="button"
                class="tc__chip tc__chip--sm"
                :class="{ 'is-on': selectedProvinces.includes(p.name) }"
                @click="toggleProvince(p.name)"
              >
                {{ p.name }}
              </button>
            </div>
            <p class="tc__hint">{{ selectedProvinces.length }} il seçildi → {{ provincesToRegions(selectedProvinces).length }} bölge kapsanıyor</p>
          </div>

          <div v-else class="tc__chips tc__chips--scroll">
            <button
              v-for="st in stations"
              :key="st.id"
              type="button"
              class="tc__chip tc__chip--sm"
              :class="{ 'is-on': selectedStations.includes(st.id) }"
              @click="toggleStation(st.id)"
            >
              {{ st.name }}
            </button>
            <p v-if="!stations.length" class="tc__hint">İstasyon bulunamadı.</p>
          </div>
        </section>

        <!-- 2. Slots / templates -->
        <section class="ui-card tc__card">
          <div class="tc__step"><span>2</span> Yayın Kuşakları</div>
          <p class="tc__label">Hazır şablonlar</p>
          <div class="tc__templates">
            <button v-for="t in TEMPLATES" :key="t.key" type="button" class="tc__tpl" @click="applyTemplate(t)">
              <span class="tc__tpl-icon">{{ t.icon }}</span>{{ t.label }}
            </button>
          </div>

          <p class="tc__label">Kuşaklar ({{ slots.length }})</p>
          <div class="tc__slots">
            <div v-for="(s, i) in slots" :key="i" class="tc__slot" :style="{ '--c': contentType(s.part_code).color }">
              <input v-model="s.slot_time" type="time" class="tc__slot-time">
              <select v-model="s.part_code" class="tc__slot-type">
                <option v-for="ct in CONTENT_TYPES" :key="ct.key" :value="ct.key">{{ ct.label }}</option>
              </select>
              <input v-model="s.content_title" class="tc__slot-title" placeholder="İçerik başlığı">
              <button type="button" class="tc__slot-del" @click="removeSlot(i)">✕</button>
            </div>
            <button type="button" class="tc__add" @click="addSlot">+ Kuşak ekle</button>
          </div>
        </section>
      </div>

      <!-- 3. Schedule + submit -->
      <aside class="tc__side">
        <section class="ui-card tc__card">
          <div class="tc__step"><span>3</span> Tekrar & Tarih</div>
          <label class="tc__field">
            <span>Başlangıç tarihi</span>
            <DatePicker v-model:value="startDate" :allow-clear="false" style="width: 100%" />
          </label>
          <label class="tc__field">
            <span>Tekrar (gün)</span>
            <div class="tc__repeat">
              <button v-for="n in [1, 7, 14, 30]" :key="n" type="button" class="tc__rep" :class="{ 'is-on': repeatDays === n }" @click="setRepeat(n)">{{ n }} gün</button>
            </div>
          </label>

          <div class="tc__summary">
            <div class="tc__sum-row"><span>Hedef</span><strong>{{ targetCount }}</strong></div>
            <div class="tc__sum-row"><span>Kuşak</span><strong>{{ slots.length }}</strong></div>
            <div class="tc__sum-row"><span>Gün</span><strong>{{ repeatDays }}</strong></div>
            <div class="tc__sum-total"><span>Oluşacak plan</span><strong>{{ estimate }}</strong></div>
          </div>

          <button type="button" class="tc__submit" :disabled="!canSubmit" @click="submit">
            {{ submitting ? 'Oluşturuluyor…' : '⚡ Planı Oluştur' }}
          </button>
        </section>

        <section v-if="result" class="ui-card tc__card tc__result">
          <div class="tc__step"><span>✓</span> Sonuç</div>
          <div class="tc__res-row is-ok"><strong>{{ result.created }}</strong> plan oluşturuldu</div>
          <div v-if="result.skipped" class="tc__res-row is-warn"><strong>{{ result.skipped }}</strong> çakışma atlandı</div>
          <ul v-if="result.conflicts.length" class="tc__conflicts">
            <li v-for="(c, i) in result.conflicts.slice(0, 8)" :key="i">{{ c }}</li>
            <li v-if="result.conflicts.length > 8">+{{ result.conflicts.length - 8 }} daha…</li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.tc {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.tc__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.tc__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.tc__grid {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.tc__main {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.tc__card {
  padding: var(--sp-4);
}
.tc__step {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: var(--sp-3);
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.tc__step span {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 999px;
  background: var(--c-brand);
  color: #fff;
  font-size: 13px;
}
.tc__label {
  margin: 14px 0 8px;
  font-size: var(--t-xs);
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--c-text-3);
}

.tc__scopes {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.tc__scope {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 9px 16px;
  border: 1px solid var(--c-line);
  border-radius: 12px;
  background: transparent;
  color: var(--c-text-2);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
}
.tc__scope.is-active {
  background: var(--c-brand);
  border-color: var(--c-brand);
  color: #fff;
}
.tc__all {
  margin-top: 14px;
  padding: 14px;
  border-radius: 12px;
  background: rgba(225, 29, 72, 0.08);
  color: var(--c-text);
  font-size: var(--t-sm);
}
.tc__chips {
  margin-top: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.tc__chips--scroll {
  max-height: 220px;
  overflow-y: auto;
}
.tc__chip {
  padding: 7px 14px;
  border: 1px solid var(--c-line);
  border-radius: 999px;
  background: transparent;
  color: var(--c-text-2);
  font-size: var(--t-sm);
  font-weight: 600;
  cursor: pointer;
}
.tc__chip--sm {
  padding: 5px 11px;
  font-size: var(--t-xs);
}
.tc__chip.is-on {
  background: rgba(225, 29, 72, 0.14);
  border-color: var(--c-brand);
  color: var(--c-brand);
}
.tc__search {
  width: 100%;
  margin-bottom: 10px;
  padding: 8px 12px;
  border: 1px solid var(--c-line);
  border-radius: 10px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: var(--t-sm);
}
.tc__hint {
  margin: 8px 0 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

.tc__templates {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 8px;
}
.tc__tpl {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px solid var(--c-line);
  border-radius: 10px;
  background: transparent;
  color: var(--c-text);
  font-size: var(--t-sm);
  font-weight: 600;
  cursor: pointer;
  text-align: left;
}
.tc__tpl:hover {
  border-color: var(--c-brand);
  background: rgba(225, 29, 72, 0.06);
}
.tc__tpl-icon {
  font-size: 17px;
}

.tc__slots {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.tc__slot {
  display: grid;
  grid-template-columns: 92px 130px 1fr 32px;
  gap: 8px;
  align-items: center;
  padding-left: 10px;
  border-left: 3px solid var(--c, var(--c-brand));
}
.tc__slot-time,
.tc__slot-type,
.tc__slot-title {
  padding: 7px 10px;
  border: 1px solid var(--c-line);
  border-radius: 8px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: var(--t-sm);
}
.tc__slot-del {
  border: none;
  background: transparent;
  color: var(--c-bad);
  cursor: pointer;
  font-size: 15px;
}
.tc__add {
  margin-top: 4px;
  padding: 8px;
  border: 1px dashed var(--c-line);
  border-radius: 8px;
  background: transparent;
  color: var(--c-text-3);
  font-weight: 700;
  cursor: pointer;
}
.tc__add:hover {
  color: var(--c-brand);
  border-color: var(--c-brand);
}

.tc__side {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.tc__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 14px;
}
.tc__field > span {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-2);
}
.tc__repeat {
  display: flex;
  gap: 6px;
}
.tc__rep {
  flex: 1;
  padding: 8px;
  border: 1px solid var(--c-line);
  border-radius: 8px;
  background: transparent;
  color: var(--c-text-2);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
}
.tc__rep.is-on {
  background: var(--c-brand);
  border-color: var(--c-brand);
  color: #fff;
}

.tc__summary {
  margin: 6px 0 14px;
  padding: 12px;
  border-radius: 12px;
  background: rgba(148, 163, 184, 0.06);
}
.tc__sum-row {
  display: flex;
  justify-content: space-between;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  padding: 3px 0;
}
.tc__sum-total {
  display: flex;
  justify-content: space-between;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--c-line);
  font-size: var(--t-base);
  font-weight: 700;
  color: var(--c-text);
}
.tc__sum-total strong {
  color: var(--c-brand);
  font-size: 22px;
}
.tc__submit {
  width: 100%;
  padding: 13px;
  border: none;
  border-radius: 12px;
  background: var(--c-brand);
  color: #fff;
  font-size: var(--t-base);
  font-weight: 800;
  cursor: pointer;
}
.tc__submit:disabled {
  opacity: 0.5;
  cursor: default;
}

.tc__res-row {
  font-size: var(--t-base);
  padding: 4px 0;
  color: var(--c-text);
}
.tc__res-row.is-ok strong {
  color: var(--c-ok);
}
.tc__res-row.is-warn strong {
  color: var(--c-warn);
}
.tc__conflicts {
  margin: 8px 0 0;
  padding: 10px 12px;
  list-style: none;
  border-radius: 10px;
  background: rgba(251, 191, 36, 0.08);
  font-size: var(--t-xs);
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
}

@media (min-width: 1024px) {
  .tc__grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(300px, 1fr);
    align-items: start;
  }
}
</style>
