<script lang="ts" setup>
import { computed, h, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  Button,
  Card,
  Checkbox,
  Input,
  Space,
  Switch,
  Table,
  Tag,
  message,
} from 'ant-design-vue';
import type { ColumnsType } from 'ant-design-vue/es/table';

import {
  type AuditLogItem,
  exportAuditLogsCsv,
  type AuditLogFilters,
  type UserAdminItem,
  getAuditLogs,
  getUsers,
  toggleUserActive,
  updateUserRoles,
} from '#/api/modules/radioMedia';

const roleOptions = [
  { label: 'Super', value: 'super' },
  { label: 'Radyo Yöneticisi', value: 'radio_manager' },
  { label: 'Planlayıcı', value: 'planner' },
  { label: 'Editör', value: 'editor' },
  { label: 'Denetçi', value: 'auditor' },
];

const users = ref<UserAdminItem[]>([]);
const logs = ref<AuditLogItem[]>([]);
const loading = ref(false);
const logLoading = ref(false);
const selectedUserId = ref<string | null>(null);
const selectedRoles = ref<string[]>([]);
const savingRoles = ref(false);
const auditFilters = ref<AuditLogFilters>({
  limit: 50,
  actor_username: '',
  action: '',
  entity_type: '',
  entity_id: '',
  date_from: '',
  date_to: '',
});

const selectedUser = computed(() =>
  users.value.find((user) => user.id === selectedUserId.value) ?? null,
);

const stats = computed(() => ({
  total: users.value.length,
  active: users.value.filter((user) => user.is_active).length,
  super: users.value.filter((user) => user.roles.includes('super')).length,
  editors: users.value.filter((user) => user.roles.some((role) => role !== 'super')).length,
}));

const columns: ColumnsType<UserAdminItem> = [
  { title: 'Kullanıcı', dataIndex: 'username', width: 140 },
  { title: 'Ad', dataIndex: 'real_name', width: 160 },
  {
    title: 'Roller',
    dataIndex: 'roles',
    customRender: ({ text }) =>
      Array.isArray(text)
        ? h(Space, { wrap: true }, () =>
            text.map((role: string) => h(Tag, { color: role === 'super' ? 'red' : 'blue' }, () => role)),
          )
        : null,
  },
  {
    title: 'Durum',
    dataIndex: 'is_active',
    width: 110,
    customRender: ({ record }) =>
      h(Switch, {
        checked: record.is_active,
        checkedChildren: 'Aktif',
        unCheckedChildren: 'Pasif',
        onChange: (...args: unknown[]) => handleToggleUser(record, Boolean(args[0])),
      }),
  },
  {
    title: 'İşlem',
    width: 120,
    customRender: ({ record }) =>
      h(Button, { type: 'link', onClick: () => openUserEditor(record) }, () => 'Düzenle'),
  },
];

const logColumns: ColumnsType<AuditLogItem> = [
  { title: 'Zaman', dataIndex: 'created_at', width: 180 },
  { title: 'Kullanıcı', dataIndex: 'actor_username', width: 140 },
  { title: 'İşlem', dataIndex: 'action', width: 160 },
  { title: 'Hedef', dataIndex: 'entity_type', width: 120 },
  { title: 'Kayıt', dataIndex: 'entity_id', width: 160 },
  {
    title: 'Korelasyon',
    dataIndex: 'payload',
    width: 160,
    customRender: ({ text }) => {
      const payload = (text ?? {}) as Record<string, unknown>;
      return String(payload.correlation_id ?? '-');
    },
  },
  {
    title: 'Detay',
    dataIndex: 'payload',
    customRender: ({ text }) => JSON.stringify(text ?? {}),
  },
];

async function loadUsers() {
  loading.value = true;
  try {
    users.value = await getUsers();
    if (!selectedUserId.value && users.value[0]) {
      openUserEditor(users.value[0]);
    }
  } catch (error) {
    console.error(error);
    message.error('Kullanıcılar alınamadı.');
  } finally {
    loading.value = false;
  }
}

async function loadLogs() {
  logLoading.value = true;
  try {
    logs.value = await getAuditLogs({
      limit: auditFilters.value.limit,
      actor_username: auditFilters.value.actor_username || undefined,
      action: auditFilters.value.action || undefined,
      entity_type: auditFilters.value.entity_type || undefined,
      entity_id: auditFilters.value.entity_id || undefined,
      date_from: auditFilters.value.date_from || undefined,
      date_to: auditFilters.value.date_to || undefined,
    });
  } catch (error) {
    console.error(error);
    message.error('Audit kayıtları alınamadı.');
  } finally {
    logLoading.value = false;
  }
}

async function applyLogFilters() {
  await loadLogs();
}

function resetLogFilters() {
  auditFilters.value = {
    limit: 50,
    actor_username: '',
    action: '',
    entity_type: '',
    entity_id: '',
    date_from: '',
    date_to: '',
  };
  void loadLogs();
}

async function downloadLogCsv() {
  try {
    const csv = await exportAuditLogsCsv({
      limit: auditFilters.value.limit,
      actor_username: auditFilters.value.actor_username || undefined,
      action: auditFilters.value.action || undefined,
      entity_type: auditFilters.value.entity_type || undefined,
      entity_id: auditFilters.value.entity_id || undefined,
      date_from: auditFilters.value.date_from || undefined,
      date_to: auditFilters.value.date_to || undefined,
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'audit-logs.csv';
    anchor.click();
    URL.revokeObjectURL(url);
    message.success('Audit CSV indirildi.');
  } catch (error) {
    console.error(error);
    message.error('CSV indirilemedi.');
  }
}

function openUserEditor(user: UserAdminItem) {
  selectedUserId.value = user.id;
  selectedRoles.value = [...user.roles];
}

async function saveRoles() {
  if (!selectedUserId.value) {
    return;
  }

  savingRoles.value = true;
  try {
    await updateUserRoles(selectedUserId.value, selectedRoles.value);
    message.success('Roller güncellendi.');
    await loadUsers();
    await loadLogs();
  } catch (error) {
    console.error(error);
    message.error('Rol güncellenemedi.');
  } finally {
    savingRoles.value = false;
  }
}

async function handleToggleUser(user: UserAdminItem, checked: boolean) {
  try {
    await toggleUserActive(user.id, checked);
    message.success(checked ? 'Kullanıcı aktif edildi.' : 'Kullanıcı pasif edildi.');
    await loadUsers();
    await loadLogs();
  } catch (error) {
    console.error(error);
    message.error('Durum güncellenemedi.');
  }
}

onMounted(async () => {
  await Promise.all([loadUsers(), loadLogs()]);
});
</script>

<template>
  <Page
    title="Yetkilendirme ve Denetim"
    description="Kullanıcı rolleri, aktif durum ve üretim audit kayıtları tek ekranda."
  >
    <div class="access-page">
      <section class="hero-strip">
        <div class="hero-card">
          <span>Toplam kullanıcı</span>
          <strong>{{ stats.total }}</strong>
        </div>
        <div class="hero-card is-success">
          <span>Aktif</span>
          <strong>{{ stats.active }}</strong>
        </div>
        <div class="hero-card is-warning">
          <span>Super</span>
          <strong>{{ stats.super }}</strong>
        </div>
        <div class="hero-card is-danger">
          <span>Diğer roller</span>
          <strong>{{ stats.editors }}</strong>
        </div>
      </section>

      <section class="access-grid">
        <Card :bordered="false" class="surface-card access-table-card">
          <div class="card-head">
            <div>
              <p class="eyebrow">Kullanıcı yönetimi</p>
              <h3>Roller ve durum</h3>
              <p>Aktif/pasif durum ve rol atamaları tek tabloda yönetilir.</p>
            </div>
          </div>

          <Table
            :columns="columns"
            :data-source="users"
            :loading="loading"
            row-key="id"
            :pagination="{ pageSize: 8 }"
            size="middle"
          />
        </Card>

        <Card :bordered="false" class="surface-card access-editor-card">
          <div class="card-head">
            <div>
              <p class="eyebrow">Rol editörü</p>
              <h3>{{ selectedUser?.username || 'Kullanıcı seçin' }}</h3>
              <p>{{ selectedUser?.real_name || 'Tablodan bir kullanıcı seçerek rollerini yönetin.' }}</p>
            </div>
          </div>

          <div class="editor-info">
            <div>
              <span>Kullanıcı</span>
              <strong>{{ selectedUser?.username || '-' }}</strong>
            </div>
            <div>
              <span>Durum</span>
              <strong>{{ selectedUser?.is_active ? 'Aktif' : 'Pasif' }}</strong>
            </div>
          </div>

          <div class="role-box">
            <Checkbox.Group v-model:value="selectedRoles" :options="roleOptions" class="role-grid" />
          </div>

          <div class="action-row">
            <Button type="primary" :loading="savingRoles" @click="saveRoles">
              Rolleri kaydet
            </Button>
          </div>
        </Card>
      </section>

      <section class="audit-panel">
        <Card :bordered="false" class="surface-card">
          <div class="card-head">
            <div>
              <p class="eyebrow">Üretim denetimi</p>
              <h3>Audit log</h3>
              <p>Login, sponsor, istasyon, planlama ve yayın akışı aksiyonları.</p>
            </div>
            <Space>
              <Button @click="resetLogFilters">Temizle</Button>
              <Button type="primary" @click="downloadLogCsv">CSV indir</Button>
            </Space>
          </div>

          <div class="log-filter-grid">
            <Input v-model:value="auditFilters.actor_username" placeholder="Kullanıcı" />
            <Input v-model:value="auditFilters.action" placeholder="İşlem" />
            <Input v-model:value="auditFilters.entity_type" placeholder="Hedef" />
            <Input v-model:value="auditFilters.entity_id" placeholder="Kayıt" />
            <Input v-model:value="auditFilters.date_from" placeholder="Başlangıç (YYYY-MM-DD)" />
            <Input v-model:value="auditFilters.date_to" placeholder="Bitiş (YYYY-MM-DD)" />
            <Button type="primary" :loading="logLoading" @click="applyLogFilters">Filtrele</Button>
          </div>

          <Table
            :columns="logColumns"
            :data-source="logs"
            row-key="id"
            :loading="logLoading"
            :pagination="{ pageSize: 6 }"
            size="middle"
          />
        </Card>
      </section>
    </div>
  </Page>
</template>

<style scoped>
.access-page {
  display: grid;
  gap: 24px;
}

.log-filter-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.hero-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}

.hero-card,
.surface-card {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 24px;
  background: linear-gradient(180deg, rgba(8, 15, 27, 0.94), rgba(9, 16, 29, 0.92));
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(18px);
}

.hero-card {
  padding: 18px 20px;
  display: grid;
  gap: 8px;
}

.hero-card span {
  color: rgba(226, 232, 240, 0.7);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.hero-card strong {
  color: #f8fafc;
  font-size: 32px;
  line-height: 1;
  font-weight: 800;
}

.hero-card.is-success { background: linear-gradient(180deg, rgba(16, 185, 129, 0.14), rgba(9, 16, 29, 0.92)); }
.hero-card.is-warning { background: linear-gradient(180deg, rgba(245, 158, 11, 0.14), rgba(9, 16, 29, 0.92)); }
.hero-card.is-danger { background: linear-gradient(180deg, rgba(225, 29, 72, 0.14), rgba(9, 16, 29, 0.92)); }

.access-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(360px, 0.8fr);
  gap: 24px;
}

.surface-card {
  padding: 24px;
  min-width: 0;
}

.card-head {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 18px;
}

.card-head h3 {
  margin: 0;
  color: #f8fafc;
  font-size: 24px;
  font-weight: 800;
  letter-spacing: -0.03em;
}

.card-head p {
  margin: 0;
  color: rgba(226, 232, 240, 0.76);
  line-height: 1.6;
}

.eyebrow {
  margin: 0;
  color: rgba(226, 232, 240, 0.72);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.editor-info {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.editor-info > div {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border-radius: 18px;
  background: rgba(15, 23, 42, 0.66);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.editor-info span {
  color: rgba(226, 232, 240, 0.68);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.editor-info strong {
  color: #f8fafc;
  font-size: 16px;
  font-weight: 800;
}

.role-box {
  padding: 16px;
  border-radius: 18px;
  border: 1px solid rgba(148, 163, 184, 0.12);
  background: rgba(15, 23, 42, 0.5);
}

.role-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 14px;
}

.action-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 18px;
}

.audit-panel .surface-card {
  padding-bottom: 18px;
}

:deep(.ant-table),
:deep(.ant-table-thead > tr > th),
:deep(.ant-table-tbody > tr > td),
:deep(.ant-table-cell) {
  background: transparent !important;
  color: #f8fafc !important;
}

:deep(.ant-table-thead > tr > th) {
  border-bottom-color: rgba(148, 163, 184, 0.18) !important;
}

:deep(.ant-table-tbody > tr > td) {
  border-bottom-color: rgba(148, 163, 184, 0.12) !important;
}

@media (max-width: 1280px) {
  .access-grid,
  .hero-strip {
    grid-template-columns: 1fr;
  }

  .log-filter-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .editor-info,
  .role-grid {
    grid-template-columns: 1fr;
  }

  .log-filter-grid {
    grid-template-columns: 1fr;
  }
}
</style>

