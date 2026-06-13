<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

import { Badge, Button, Empty, message, Spin, Table, Tag } from 'ant-design-vue';

import {
  type SyncClient,
  type SyncFilter,
  type SyncStatusCounts,
  listSyncClients,
  STATUS_COLORS,
  STATUS_LABELS,
} from '#/api/modules/syncAdmin';

const loading = ref(false);
const clients = ref<SyncClient[]>([]);
const counts = ref<SyncStatusCounts>({ online: 0, stale: 0, offline: 0, with_error: 0 });
const filter = ref<SyncFilter>('all');
const lastRefresh = ref<string>('');
const errorMsg = ref<string>('');

const total = computed(() => counts.value.online + counts.value.stale + counts.value.offline);

const FILTERS: { value: SyncFilter; label: string; tone: string }[] = [
  { value: 'all', label: 'Tümü', tone: 'default' },
  { value: 'online', label: 'Çevrimiçi', tone: 'success' },
  { value: 'offline', label: 'Çevrimdışı', tone: 'default' },
  { value: 'error', label: 'Hatalı', tone: 'error' },
];

async function fetchData() {
  loading.value = true;
  errorMsg.value = '';
  try {
    const response = await listSyncClients({ filter: filter.value, limit: 200 });
    if (response.code === 0 && response.result) {
      clients.value = response.result.clients ?? [];
      counts.value = response.result.counts ?? { online: 0, stale: 0, offline: 0, with_error: 0 };
      lastRefresh.value = new Date().toLocaleTimeString('tr-TR');
    } else {
      errorMsg.value = response.message ?? 'Veri alınamadı';
    }
  } catch (e) {
    errorMsg.value = e instanceof Error ? e.message : 'Bağlantı hatası';
    message.error(errorMsg.value);
  } finally {
    loading.value = false;
  }
}

function selectFilter(f: SyncFilter) {
  filter.value = f;
  fetchData();
}

function relativeTime(iso: string | null): string {
  if (!iso) return '—';
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return iso;
  const diff = Math.max(0, Date.now() - then) / 1000;
  if (diff < 60) return 'şimdi';
  if (diff < 3600) return `${Math.floor(diff / 60)} dk önce`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} sa önce`;
  return `${Math.floor(diff / 86400)} gün önce`;
}

const columns = [
  { title: 'Radyo', dataIndex: 'radio_name', key: 'radio_name', width: 200 },
  { title: 'Kullanıcı', dataIndex: 'username', key: 'username', width: 140 },
  { title: 'Bölge / Şehir', key: 'location', width: 160 },
  { title: 'Durum', key: 'connection_status', width: 110 },
  { title: 'Son Bağlantı', key: 'last_seen_at', width: 130 },
  { title: 'Son Eşitleme', key: 'last_sync_at', width: 130 },
  { title: 'Versiyon', dataIndex: 'client_version', key: 'client_version', width: 100 },
  { title: 'OS', dataIndex: 'os', key: 'os', width: 140 },
  { title: 'IP', dataIndex: 'last_seen_ip', key: 'last_seen_ip', width: 140 },
  { title: 'Disk', key: 'disk_free_gb', width: 90 },
  { title: 'Hata', key: 'last_error', width: 240 },
];

let intervalId: number | undefined;

onMounted(() => {
  fetchData();
  // 30s'de bir auto-refresh — sync client'lar bu interval'da heartbeat yollar
  intervalId = window.setInterval(fetchData, 30_000);
});

onUnmounted(() => {
  if (intervalId !== undefined) window.clearInterval(intervalId);
});
</script>

<template>
  <div class="sync-admin page-fit">
    <header class="sync-admin__hero">
      <div>
        <h1>Eşitleme İstemcisi İzleme</h1>
        <p>AdCast Pro Windows Masaüstü İstemcisi — radyo otomasyon bağlantı durumu</p>
      </div>
      <div class="sync-admin__refresh">
        <span class="dim">Son yenileme: {{ lastRefresh || '—' }}</span>
        <Button size="small" :loading="loading" @click="fetchData">Yenile</Button>
      </div>
    </header>

    <!-- KPI'ler -->
    <section class="sync-admin__kpi">
      <button
        v-for="f in FILTERS"
        :key="f.value"
        class="kpi"
        :class="{ 'kpi--active': filter === f.value }"
        @click="selectFilter(f.value)"
      >
        <span class="kpi__label">{{ f.label }}</span>
        <span class="kpi__value">
          {{
            f.value === 'all'
              ? total
              : f.value === 'error'
                ? counts.with_error
                : f.value === 'online'
                  ? counts.online
                  : counts.offline
          }}
        </span>
      </button>
    </section>

    <!-- Tablo -->
    <section class="sync-admin__table">
      <Spin :spinning="loading">
        <Empty v-if="!loading && clients.length === 0" description="Henüz hiç eşitleme istemcisi kaydı yok" />
        <Table
          v-else
          :columns="columns"
          :data-source="clients"
          row-key="id"
          size="small"
          :pagination="{ pageSize: 50, showSizeChanger: false }"
          :scroll="{ x: 1500 }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'connection_status'">
              <Badge
                :status="STATUS_COLORS[record.connection_status as keyof typeof STATUS_COLORS] as 'success' | 'warning' | 'default' | 'error'"
                :text="STATUS_LABELS[record.connection_status as keyof typeof STATUS_LABELS]"
              />
            </template>
            <template v-else-if="column.key === 'location'">
              <span v-if="record.radio_region">
                {{ record.radio_region }}<span v-if="record.radio_province"> · {{ record.radio_province }}</span>
              </span>
              <span v-else class="dim">—</span>
            </template>
            <template v-else-if="column.key === 'last_seen_at'">
              <span :title="record.last_seen_at">{{ relativeTime(record.last_seen_at) }}</span>
            </template>
            <template v-else-if="column.key === 'last_sync_at'">
              <span :title="record.last_sync_at ?? ''">{{ relativeTime(record.last_sync_at) }}</span>
            </template>
            <template v-else-if="column.key === 'disk_free_gb'">
              <Tag :color="record.disk_free_gb < 5 ? 'red' : record.disk_free_gb < 20 ? 'orange' : 'green'">
                {{ record.disk_free_gb }} GB
              </Tag>
            </template>
            <template v-else-if="column.key === 'last_error'">
              <span v-if="record.last_error" class="err" :title="record.last_error_at ?? ''">
                {{ record.last_error }}
              </span>
              <span v-else class="dim">—</span>
            </template>
          </template>
        </Table>
      </Spin>
    </section>
  </div>
</template>

<style scoped>
.sync-admin {
  display: grid;
  gap: 16px;
  padding: 16px;
}

.sync-admin__hero {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  flex-wrap: wrap;
}

.sync-admin__hero h1 {
  margin: 0;
  font-size: var(--t-h1);
  color: var(--c-fg);
}

.sync-admin__hero p {
  margin: 4px 0 0;
  color: var(--c-muted);
  font-size: 13px;
}

.sync-admin__refresh {
  display: flex;
  align-items: center;
  gap: 12px;
}

.sync-admin__kpi {
  display: grid;
  grid-template-columns: repeat(4, minmax(120px, 1fr));
  gap: 12px;
}

.kpi {
  border: 1px solid var(--c-border);
  border-radius: 10px;
  padding: 12px 14px;
  background: var(--c-card);
  cursor: pointer;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 4px;
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
}

.kpi:hover {
  border-color: var(--c-brand);
}

.kpi--active {
  border-color: var(--c-brand);
  box-shadow: 0 0 0 2px rgba(225, 29, 72, 0.18);
}

.kpi__label {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--c-muted);
}

.kpi__value {
  font-size: 28px;
  font-weight: 800;
  color: var(--c-fg);
}

.sync-admin__table {
  border: 1px solid var(--c-border);
  border-radius: 10px;
  background: var(--c-card);
  overflow: hidden;
}

.dim {
  color: var(--c-muted);
  font-size: 12px;
}

.err {
  color: #ef4444;
  font-size: 12px;
}
</style>
