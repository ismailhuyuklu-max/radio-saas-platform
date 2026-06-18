<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';

import { Button, Input, Modal, Select, message } from 'ant-design-vue';

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
  closed: 'muted',
};

const statusOptions = (Object.keys(SUPPORT_STATUS_LABELS) as SupportStatus[]).map((s) => ({
  label: SUPPORT_STATUS_LABELS[s],
  value: s,
}));

function toneOf(s: string): string {
  return STATUS_TONE[s as SupportStatus] ?? 'muted';
}
function labelOf(s: string): string {
  return SUPPORT_STATUS_LABELS[s as SupportStatus] ?? s;
}

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

// ---- Detay (Modal) ----
const detailOpen = ref(false);
const saving = ref(false);
const detail = reactive<{ ticket: SupportTicket | null; status: SupportStatus; note: string }>({
  ticket: null,
  status: 'new',
  note: '',
});

async function openDetail(row: SupportTicket) {
  detail.ticket = row;
  detail.status = row.status;
  detail.note = row.admin_note ?? '';
  detailOpen.value = true;
  try {
    const fresh = await getSupportRequest(row.id);
    if (fresh) {
      detail.ticket = fresh;
      detail.status = fresh.status;
      detail.note = fresh.admin_note ?? '';
    }
  } catch {
    // satırdaki veriyle devam
  }
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
    if (updated) {
      const idx = rows.value.findIndex((r) => r.id === updated.id);
      if (idx >= 0) rows.value[idx] = updated;
    }
    detailOpen.value = false;
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
      <Button :loading="loading" @click="load">Ara</Button>
    </div>

    <div class="sup__tablewrap">
      <table class="sup__table">
        <thead>
          <tr>
            <th>Tarih</th><th>Radyo Adı</th><th>Frekans</th><th>Bölge</th><th>İl</th>
            <th>Ad Soyad</th><th>Telefon</th><th>E-posta</th><th>Durum</th><th>İşlem</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td>{{ fmtDate(r.created_at) }}</td>
            <td>{{ r.radio_name || '—' }}</td>
            <td>{{ r.frequency || '—' }}</td>
            <td>{{ r.region || '—' }}</td>
            <td>{{ r.city || '—' }}</td>
            <td>{{ r.full_name }}</td>
            <td>{{ r.phone }}</td>
            <td>{{ r.email }}</td>
            <td><span class="sup__pill" :class="`sup__pill--${toneOf(r.status)}`">{{ labelOf(r.status) }}</span></td>
            <td><button class="sup__link" type="button" @click="openDetail(r)">Detay</button></td>
          </tr>
          <tr v-if="!loading && rows.length === 0">
            <td colspan="10" class="sup__empty">Henüz destek talebi yok.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal v-model:open="detailOpen" title="Destek Talebi Detayı" :footer="null" :width="560">
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
          <textarea v-model="detail.note" class="sup__textarea" rows="4" placeholder="İç not (yayıncı görmez)"></textarea>
          <Button type="primary" :loading="saving" block style="margin-top: 16px" @click="saveDetail">
            Kaydet
          </Button>
        </section>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.sup { padding: 16px; }
.sup__head {
  display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
  justify-content: space-between; margin-bottom: 16px;
}
.sup__title { margin: 0; font-size: 20px; font-weight: 800; }
.sup__sub { margin: 2px 0 0; color: var(--c-text-3, #94a3b8); font-size: 13px; }
.sup__stats { display: flex; gap: 8px; flex-wrap: wrap; }
.sup__chip { padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.sup__chip--blue { background: rgba(37, 99, 235, 0.15); color: #60a5fa; }
.sup__chip--orange { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
.sup__chip--green { background: rgba(16, 185, 129, 0.15); color: #34d399; }
.sup__filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.sup__tablewrap {
  overflow-x: auto; border: 1px solid var(--c-line, #1e293b); border-radius: 12px;
}
.sup__table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1100px; }
.sup__table th {
  text-align: left; padding: 11px 12px; font-weight: 700; font-size: 12px;
  color: var(--c-text-3, #94a3b8); border-bottom: 1px solid var(--c-line, #1e293b);
  white-space: nowrap;
}
.sup__table td {
  padding: 11px 12px; border-bottom: 1px solid var(--c-line, #1e293b);
  color: var(--c-text, #e2e8f0); white-space: nowrap;
}
.sup__table tbody tr:hover { background: rgba(255, 255, 255, 0.03); }
.sup__empty { text-align: center; color: var(--c-text-3, #94a3b8); padding: 28px; }
.sup__pill { padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
.sup__pill--blue { background: rgba(37, 99, 235, 0.16); color: #60a5fa; }
.sup__pill--orange { background: rgba(245, 158, 11, 0.16); color: #fbbf24; }
.sup__pill--green { background: rgba(16, 185, 129, 0.16); color: #34d399; }
.sup__pill--muted { background: rgba(148, 163, 184, 0.16); color: #94a3b8; }
.sup__link { background: none; border: 0; color: #60a5fa; cursor: pointer; font-weight: 600; padding: 0; }
.sup__card { border: 1px solid var(--c-line, #1e293b); border-radius: 12px; padding: 14px; margin-bottom: 14px; }
.sup__card-title { margin: 0 0 10px; font-size: 13px; font-weight: 800; }
.sup__kv { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 13px; }
.sup__kv span { color: var(--c-text-3, #94a3b8); }
.sup__msg { margin: 0; white-space: pre-wrap; line-height: 1.6; font-size: 14px; }
.sup__label { display: block; margin-bottom: 6px; font-size: 12px; color: var(--c-text-3, #94a3b8); }
.sup__textarea {
  width: 100%; background: var(--c-surface-2, #0b1220); color: var(--c-text, #e2e8f0);
  border: 1px solid var(--c-line, #1e293b); border-radius: 8px; padding: 8px 10px;
  font-family: inherit; font-size: 13px; resize: vertical;
}
</style>
