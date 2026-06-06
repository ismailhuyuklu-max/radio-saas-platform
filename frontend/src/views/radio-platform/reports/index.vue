<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';

import { message } from 'ant-design-vue';

import {
  type CustomerBreakdownRow,
  type ProvinceBreakdownRow,
  getCustomerBreakdown,
  getProvinceBreakdown,
} from '#/api/modules/radioMedia';
import VirtualList from '#/components/ui/VirtualList.vue';
import { formatCompact, formatCurrency } from '#/utils/format';

interface ReportDef {
  type: string;
  title: string;
  desc: string;
  icon: string;
}

const reports: ReportDef[] = [
  {
    type: 'revenue',
    title: 'Gelir Raporu',
    desc: 'Kampanya bazında gerçekleşen ve projeksiyon gelir, bütçe, gösterim.',
    icon: 'M3 17l6-6 4 4 8-8 M15 7h6v6',
  },
  {
    type: 'broadcast',
    title: 'Yayın Akışı Raporu',
    desc: 'Bugünün planlanan haber/içerik akışı: bölge, saat, tür, durum.',
    icon: 'M4 5h16v16H4z M4 9h16 M8 3v4 M16 3v4',
  },
  {
    type: 'stations',
    title: 'İstasyon Raporu',
    desc: 'Tüm istasyonlar: bölge, şehir, aktiflik durumu.',
    icon: 'M12 13v8 M8 9a5 5 0 0 1 8 0 M5 6a9 9 0 0 1 14 0',
  },
  {
    type: 'province',
    title: 'İl Kırılımı Raporu',
    desc: '81 il bazında plan ve kampanya yoğunluğu.',
    icon: 'M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z M12 10a1 1 0 100-2 1 1 0 000 2z',
  },
  {
    type: 'customer',
    title: 'Müşteri Kırılımı Raporu',
    desc: 'Reklamveren bazında planlanan/yayınlanan spot ve gösterim.',
    icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0z M3 21v-2a6 6 0 0112 0v2',
  },
];

/* ---- Faz 6/7: on-screen breakdowns, virtualized ---- */
type Tab = 'province' | 'customer';
const tab = ref<Tab>('province');
const loadingBreak = ref(false);
const provinceRows = ref<ProvinceBreakdownRow[]>([]);
const customerRows = ref<CustomerBreakdownRow[]>([]);

const STATUS_TR: Record<string, string> = {
  active: 'Aktif',
  paused: 'Duraklatıldı',
  ended: 'Bitti',
  draft: 'Taslak',
};

const provinceMax = computed(() =>
  Math.max(1, ...provinceRows.value.map((r) => r.plan_count)),
);

async function loadBreakdowns() {
  loadingBreak.value = true;
  try {
    const [pv, cu] = await Promise.all([getProvinceBreakdown(), getCustomerBreakdown()]);
    provinceRows.value = pv?.rows ?? [];
    customerRows.value = cu?.rows ?? [];
  } catch {
    message.error('Kırılım verileri alınamadı.');
  } finally {
    loadingBreak.value = false;
  }
}

onMounted(loadBreakdowns);

const formats: Array<{ key: 'csv' | 'xlsx' | 'pdf'; label: string }> = [
  { key: 'csv', label: 'CSV' },
  { key: 'xlsx', label: 'Excel' },
  { key: 'pdf', label: 'PDF' },
];

const busy = ref<string | null>(null);

async function download(type: string, format: string) {
  busy.value = `${type}:${format}`;
  try {
    const res = await fetch(`/api/v1/reports/${type}?format=${format}`, {
      credentials: 'include',
      headers: { Accept: 'application/octet-stream' },
    });
    if (!res.ok) {
      message.error('Rapor oluşturulamadı.');
      return;
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `rapor-${type}-${dayjs().format('YYYYMMDD')}.${format}`;
    document.body.append(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    message.success('Rapor indirildi.');
  } catch {
    message.error('İndirme başarısız.');
  } finally {
    busy.value = null;
  }
}
</script>

<template>
  <div class="rep">
    <header class="rep__head">
      <h1>Rapor Merkezi</h1>
      <p class="rep__sub">Raporları CSV, Excel veya PDF formatında indirin</p>
    </header>

    <div class="rep__grid">
      <article v-for="r in reports" :key="r.type" class="ui-card rep__card">
        <div class="rep__icon">
          <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path :d="r.icon" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </div>
        <div class="rep__body">
          <h2>{{ r.title }}</h2>
          <p>{{ r.desc }}</p>
        </div>
        <div class="rep__actions">
          <button
            v-for="f in formats"
            :key="f.key"
            type="button"
            class="rep__btn"
            :disabled="busy === `${r.type}:${f.key}`"
            @click="download(r.type, f.key)"
          >
            {{ busy === `${r.type}:${f.key}` ? '…' : f.label }}
          </button>
        </div>
      </article>
    </div>

    <!-- On-screen breakdowns (virtualized) -->
    <article class="ui-card rep__break">
      <div class="rep__break-head">
        <h2>Kırılım Analizi</h2>
        <div class="rep__tabs">
          <button
            type="button"
            class="rep__tab"
            :class="{ 'is-active': tab === 'province' }"
            @click="tab = 'province'"
          >
            İl ({{ provinceRows.length }})
          </button>
          <button
            type="button"
            class="rep__tab"
            :class="{ 'is-active': tab === 'customer' }"
            @click="tab = 'customer'"
          >
            Müşteri ({{ customerRows.length }})
          </button>
        </div>
      </div>

      <div v-if="loadingBreak" class="rep__empty">Yükleniyor…</div>

      <!-- İl breakdown -->
      <template v-else-if="tab === 'province'">
        <div class="rep__row rep__row--head rep__row--prov">
          <span>İl</span><span>Bölge</span><span class="ta-r">Plan</span><span class="ta-r">Kampanya</span>
        </div>
        <VirtualList
          v-if="provinceRows.length"
          :items="provinceRows"
          :row-height="46"
          :height="420"
          key-field="province"
        >
          <template #default="{ item }">
            <div class="rep__row rep__row--prov">
              <span class="rep__name">{{ item.province }}</span>
              <span class="rep__muted">{{ item.region_name }}</span>
              <span class="ta-r">
                <span class="rep__bar"><i :style="{ width: (item.plan_count / provinceMax) * 100 + '%' }" /></span>
                <b>{{ item.plan_count }}</b>
              </span>
              <span class="ta-r">{{ item.campaign_count }}</span>
            </div>
          </template>
        </VirtualList>
        <p v-else class="rep__empty">Veri yok.</p>
      </template>

      <!-- Müşteri breakdown -->
      <template v-else>
        <div class="rep__row rep__row--head rep__row--cust">
          <span>Reklamveren</span><span>Durum</span><span class="ta-r">Bütçe</span>
          <span class="ta-r">Plan</span><span class="ta-r">Yayın</span><span class="ta-r">Gösterim</span>
        </div>
        <VirtualList
          v-if="customerRows.length"
          :items="customerRows"
          :row-height="46"
          :height="420"
          key-field="advertiser_name"
        >
          <template #default="{ item }">
            <div class="rep__row rep__row--cust">
              <span class="rep__name">{{ item.advertiser_name }}</span>
              <span class="rep__muted">{{ STATUS_TR[item.status] ?? item.status }}</span>
              <span class="ta-r">{{ formatCurrency(item.budget) }}</span>
              <span class="ta-r">{{ item.planned_spots }}</span>
              <span class="ta-r rep__aired">{{ item.aired_spots }}</span>
              <span class="ta-r">{{ formatCompact(item.impressions) }}</span>
            </div>
          </template>
        </VirtualList>
        <p v-else class="rep__empty">Veri yok.</p>
      </template>
    </article>

    <p class="rep__note">
      Dosyalar sunucuda oluşturulur (CSV/XLSX/PDF). Excel dosyaları Türkçe karakterleri
      UTF-8 ile korur. Kırılım tabloları 1000+ satırda akıcı kalması için sanal liste ile
      render edilir.
    </p>
  </div>
</template>

<style scoped>
.rep {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.rep__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.rep__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.rep__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--sp-3);
}
.rep__card {
  padding: var(--sp-4);
  display: flex;
  align-items: center;
  gap: var(--sp-4);
  flex-wrap: wrap;
}
.rep__icon {
  width: 46px;
  height: 46px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 12px;
  background: rgba(225, 29, 72, 0.12);
  color: var(--c-brand);
}
.rep__icon svg {
  width: 24px;
  height: 24px;
}
.rep__body {
  flex: 1;
  min-width: 180px;
}
.rep__body h2 {
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.rep__body p {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.rep__actions {
  display: flex;
  gap: 8px;
}
.rep__btn {
  padding: 8px 16px;
  border: 1px solid var(--c-line);
  border-radius: 10px;
  background: transparent;
  color: var(--c-text);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
  transition: background 150ms ease, border-color 150ms ease;
}
.rep__btn:hover:not(:disabled) {
  background: rgba(225, 29, 72, 0.1);
  border-color: rgba(225, 29, 72, 0.4);
  color: var(--c-brand);
}
.rep__btn:disabled {
  opacity: 0.6;
  cursor: progress;
}
.rep__note {
  margin: 0;
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

/* Breakdown panel */
.rep__break {
  padding: var(--sp-4);
}
.rep__break-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  margin-bottom: var(--sp-3);
  flex-wrap: wrap;
}
.rep__break-head h2 {
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.rep__tabs {
  display: inline-flex;
  padding: 3px;
  gap: 2px;
  border-radius: var(--r-sm);
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
}
.rep__tab {
  border: none;
  background: transparent;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text-3);
  cursor: pointer;
}
.rep__tab.is-active {
  background: var(--c-brand);
  color: #fff;
}
.rep__row {
  display: grid;
  align-items: center;
  gap: var(--sp-3);
  height: 46px;
  padding: 0 10px;
  border-bottom: 1px solid var(--c-line);
  font-size: var(--t-sm);
}
.rep__row--prov {
  grid-template-columns: 1.4fr 1.2fr 1.2fr 0.7fr;
}
.rep__row--cust {
  grid-template-columns: 1.6fr 0.9fr 1fr 0.6fr 0.6fr 0.8fr;
}
.rep__row--head {
  height: 38px;
  border-bottom: 1px solid var(--c-line-strong);
  font-size: var(--t-xs);
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--c-text-3);
}
.ta-r {
  text-align: right;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}
.rep__name {
  font-weight: 700;
  color: var(--c-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rep__muted {
  color: var(--c-text-3);
  font-size: var(--t-xs);
}
.rep__bar {
  display: inline-block;
  width: 70px;
  height: 7px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.14);
  overflow: hidden;
}
.rep__bar i {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--c-info), var(--c-brand));
}
.rep__aired {
  color: var(--c-ok);
  font-weight: 700;
}
.rep__empty {
  padding: 28px 0;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

@media (min-width: 768px) {
  .rep__card {
    flex-wrap: nowrap;
  }
}
</style>
