<script lang="ts" setup>
import { ref } from 'vue';
import dayjs from 'dayjs';

import { message } from 'ant-design-vue';

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
];

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

    <p class="rep__note">
      Dosyalar sunucuda oluşturulur (CSV/XLSX/PDF). Excel dosyaları Türkçe karakterleri
      UTF-8 ile korur.
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

@media (min-width: 768px) {
  .rep__card {
    flex-wrap: nowrap;
  }
}
</style>
