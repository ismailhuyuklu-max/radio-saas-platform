<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';

import {
  Button,
  Drawer,
  Input,
  Select,
  Table,
  Tag,
  Textarea,
  message,
} from 'ant-design-vue';

import {
  getSupportRequest,
  getSupportRequests,
  SUPPORT_STATUS_LABELS,
  type SupportStatus,
  type SupportTicket,
  updateSupportRequest,
} from '#/api/modules/publisherSupport';

const STATUS_TONE: Record<SupportStatus, string> = {
  new: 'blue',
  in_progress: 'orange',
  resolved: 'green',
  closed: 'default',
};

const statusOptions = (Object.keys(SUPPORT_STATUS_LABELS) as SupportStatus[]).map((s) => ({
  label: SUPPORT_STATUS_LABELS[s],
  value: s,
}));

const loading = ref(false);
const rows = ref<SupportTicket[]>([]);
const statusFilter = ref<SupportStatus | undefined>();
const keyword = ref('');

async function load() {
  loading.value = true;
  try {
    rows.value = await getSupportRequests({
      status: statusFilter.value,
      keyword: keyword.value.trim() || undefined,
    });
  } catch (error) {
    console.error(error);
    message.error('Destek talepleri yüklenemedi.');
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(load);

function fmtDate(iso: string) {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('tr-TR');
}

const columns: any[] = [
  { title: 'Tarih', key: 'created_at', width: 150 },
  { title: 'Radyo Adı', key: 'radio_name', width: 150 },
  { title: 'Frekans', key: 'frequency', width: 90 },
  { title: 'Bölge', key: 'region', width: 110 },
  { title: 'İl', key: 'city', width: 100 },
  { title: 'Ad Soyad', key: 'full_name', width: 150 },
  { title: 'Telefon', key: 'phone', width: 130 },
  { title: 'E-posta', key: 'email', width: 180 },
  { title: 'Durum', key: 'status', width: 130 },
  { title: 'İşlem', key: 'action', width: 100, fixed: 'right' },
];

function toneOf(s: string): string {
  return STATUS_TONE[s as SupportStatus] ?? 'default';
}
function labelOf(s: string): string {
  return SUPPORT_STATUS_LABELS[s as SupportStatus] ?? s;
}

// ---- Detay ----
const drawerOpen = ref(false);
const saving = ref(false);
const detail = reactive<{ ticket: SupportTicket | null; status: SupportStatus; note: string }>({
  ticket: null,
  status: 'new',
  note: '',
});

async function openDetail(row: any) {
  drawerOpen.value = true;
  try {
    const fresh = await getSupportRequest(row.id);
    detail.ticket = fresh ?? row;
  } catch {
    detail.ticket = row;
  }
  detail.status = detail.ticket?.status ?? 'new';
  detail.note = detail.ticket?.admin_note ?? '';
}

async function saveDetail() {
  if (!detail.ticket) return;
  saving.value = true;
  try {
    const updated = await updateSupportRequest(detail.ticket.id, {
      status: detail.status,
      admin_note: detail.note,
    });
    message.success('Talep güncellendi.');
    detail.ticket = updated ?? detail.ticket;
    // listeyi yansit
    const idx = rows.value.findIndex((r) => r.id === detail.ticket?.id);
    if (idx >= 0 && detail.ticket) rows.value[idx] = { ...rows.value[idx], ...detail.ticket };
  } catch (error) {
    console.error(error);
    message.error('Talep güncellenemedi.');
  } finally {
    saving.value = false;
  }
}

const counts = computed(() => {
  const c: Record<string, number> = { new: 0, in_progress: 0, resolved: 0, closed: 0 };
  for (const r of rows.value) c[r.status] = (c[r.status] ?? 0) + 1;
  return c;
});
</script>

<template>
  <div class="sup">
    <header class="sup__head">
      <div>
        <h1 class="sup__title">Destek Paneli</h1>
        <p class="sup__sub">Yayıncılardan gelen destek talepleri</p>
      </div>
      <div class="sup__stats">
        <span class="sup__chip sup__chip--blue">Yeni: {{ counts.new }}</span>
        <span class="sup__chip sup__chip--orange">İşlemde: {{ counts.in_progress }}</span>
        <span class="sup__chip sup__chip--green">Çözüldü: {{ counts.resolved }}</span>
      </div>
    </header>

    <div class="sup__filters">
      <Select
        v-model:value="statusFilter"
        :options="statusOptions"
        placeholder="Tüm durumlar"
        allow-clear
        style="width: 180px"
        @change="load"
      />
      <Input
        v-model:value="keyword"
        placeholder="Radyo, ad, e-posta, telefon ara"
        style="width: 280px"
        allow-clear
        @press-enter="load"
      />
      <Button @click="load">Ara</Button>
    </div>

    <Table
      :columns="columns"
      :data-source="rows"
      :loading="loading"
      :pagination="{ pageSize: 20, showSizeChanger: false }"
      row-key="id"
      size="middle"
      :scroll="{ x: 1280 }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'created_at'">{{ fmtDate(record.created_at) }}</template>
        <template v-else-if="column.key === 'radio_name'">{{ record.radio_name || '—' }}</template>
        <template v-else-if="column.key === 'frequency'">{{ record.frequency || '—' }}</template>
        <template v-else-if="column.key === 'region'">{{ record.region || '—' }}</template>
        <template v-else-if="column.key === 'city'">{{ record.city || '—' }}</template>
        <template v-else-if="column.key === 'status'">
          <Tag :color="toneOf(record.status)">{{ labelOf(record.status) }}</Tag>
        </template>
        <template v-else-if="column.key === 'action'">
          <Button type="link" size="small" @click="openDetail(record)">Detay</Button>
        </template>
      </template>
    </Table>

    <Drawer
      v-model:open="drawerOpen"
      title="Destek Talebi Detayı"
      width="520"
      :body-style="{ paddingBottom: '80px' }"
    >
      <template v-if="detail.ticket">
        <section class="sup__card">
          <h3 class="sup__card-title">📻 Radyo Bilgileri</h3>
          <div class="sup__kv"><span>Radyo Adı</span><b>{{ detail.ticket.radio_name || '—' }}</b></div>
          <div class="sup__kv"><span>Frekans</span><b>{{ detail.ticket.frequency || '—' }}</b></div>
          <div class="sup__kv"><span>Bölge</span><b>{{ detail.ticket.region || '—' }}</b></div>
          <div class="sup__kv"><span>İl</span><b>{{ detail.ticket.city || '—' }}</b></div>
        </section>

        <section class="sup__card">
          <h3 class="sup__card-title">👤 İletişim</h3>
          <div class="sup__kv"><span>Ad Soyad</span><b>{{ detail.ticket.full_name }}</b></div>
          <div class="sup__kv"><span>Telefon</span><b>{{ detail.ticket.phone }}</b></div>
          <div class="sup__kv"><span>E-posta</span><b>{{ detail.ticket.email }}</b></div>
          <div class="sup__kv"><span>Tarih</span><b>{{ fmtDate(detail.ticket.created_at) }}</b></div>
        </section>

        <section class="sup__card">
          <h3 class="sup__card-title">💬 Destek Mesajı</h3>
          <p class="sup__msg">{{ detail.ticket.message }}</p>
        </section>

        <section class="sup__card">
          <h3 class="sup__card-title">⚙️ Yönetim</h3>
          <label class="sup__label">Durum</label>
          <Select v-model:value="detail.status" :options="statusOptions" style="width: 100%" />
          <label class="sup__label" style="margin-top: 12px">Admin Notu</label>
          <Textarea v-model:value="detail.note" :rows="4" placeholder="İç not (yayıncı görmez)" />
          <Button type="primary" :loading="saving" block style="margin-top: 16px" @click="saveDetail">
            Kaydet
          </Button>
        </section>
      </template>
    </Drawer>
  </div>
</template>

<style scoped>
.sup {
  padding: 16px;
}
.sup__head {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.sup__title {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
}
.sup__sub {
  margin: 2px 0 0;
  color: var(--c-text-3, #94a3b8);
  font-size: 13px;
}
.sup__stats {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.sup__chip {
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.sup__chip--blue { background: rgba(37, 99, 235, 0.15); color: #60a5fa; }
.sup__chip--orange { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
.sup__chip--green { background: rgba(16, 185, 129, 0.15); color: #34d399; }
.sup__filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.sup__card {
  border: 1px solid var(--c-line, #1e293b);
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 14px;
}
.sup__card-title {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 800;
}
.sup__kv {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 4px 0;
  font-size: 13px;
}
.sup__kv span { color: var(--c-text-3, #94a3b8); }
.sup__msg {
  margin: 0;
  white-space: pre-wrap;
  line-height: 1.6;
  font-size: 14px;
}
.sup__label {
  display: block;
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--c-text-3, #94a3b8);
}
</style>
