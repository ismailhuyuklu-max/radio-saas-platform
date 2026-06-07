<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';

import { Button, Checkbox, Input, Modal, Popconfirm, Switch, message } from 'ant-design-vue';

import {
  type AuditLogItem,
  type UserAdminItem,
  exportAuditLogsCsv,
  getAuditLogs,
  getUsers,
  toggleUserActive,
  updateUserRoles,
} from '#/api/modules/radioMedia';
import { adminResetMfa, adminResetPassword } from '#/api/modules/auth';
import { extractApiError } from '#/utils/api-error';

const CheckboxGroup = Checkbox.Group;

// Roles mirror the backend RBAC policy (RadioSaaS\Service\Rbac).
const ROLE_LABELS: Record<string, string> = {
  super: 'Süper Yönetici',
  radio_manager: 'Radyo Yöneticisi',
  editor: 'Editör',
  viewer: 'İzleyici',
};
const ROLE_HINTS: Record<string, string> = {
  super: 'Tam yetki — kullanıcı ve rol yönetimi dahil',
  radio_manager: 'Tüm yayın operasyonları (istasyon, sponsor, plan)',
  editor: 'İçerik oluşturma/düzenleme (plan, medya)',
  viewer: 'Salt-okunur — panoları ve listeleri görüntüler',
};
const roleOptions = Object.entries(ROLE_LABELS).map(([value, label]) => ({
  label: `${label} — ${ROLE_HINTS[value] ?? ''}`,
  value,
}));

const ACTION_LABELS: Record<string, string> = {
  login: 'Giriş yapıldı',
  create: 'Oluşturuldu',
  update: 'Güncellendi',
  delete: 'Silindi',
  toggle: 'Durum değişti',
  upload_media: 'Medya yüklendi',
  assign_sponsor: 'Sponsor atandı',
  generate_token: 'Token üretildi',
};
const ENTITY_LABELS: Record<string, string> = {
  user: 'Kullanıcı',
  station: 'İstasyon',
  sponsor: 'Sponsor',
  media: 'Medya',
  content_plan: 'Plan',
};

const users = ref<UserAdminItem[]>([]);
const logs = ref<AuditLogItem[]>([]);
const loading = ref(false);
const exporting = ref(false);

const stats = computed(() => ({
  total: users.value.length,
  active: users.value.filter((u) => u.is_active).length,
}));

function roleLabel(r: string) {
  return ROLE_LABELS[r] ?? r;
}
function logText(item: AuditLogItem) {
  return `${ENTITY_LABELS[item.entity_type] ?? item.entity_type} · ${ACTION_LABELS[item.action] ?? item.action}`;
}
function logTime(value: string) {
  return dayjs(value).format('DD MMM HH:mm');
}

async function load() {
  loading.value = true;
  try {
    const [u, l] = await Promise.allSettled([getUsers(), getAuditLogs({ limit: 12 })]);
    if (u.status === 'fulfilled' && Array.isArray(u.value)) users.value = u.value;
    if (l.status === 'fulfilled' && Array.isArray(l.value)) logs.value = l.value;
  } catch (error) {
    console.error(error);
    message.error('Veriler alınamadı.');
  } finally {
    loading.value = false;
  }
}

async function toggleUser(u: UserAdminItem, checked: boolean) {
  try {
    await toggleUserActive(u.id, checked);
    u.is_active = checked;
    message.success(checked ? 'Kullanıcı aktifleştirildi.' : 'Kullanıcı pasife alındı.');
  } catch (error) {
    console.error(error);
    message.error('Durum güncellenemedi.');
  }
}

/* Role edit modal */
const rolesOpen = ref(false);
const savingRoles = ref(false);
const editUser = ref<UserAdminItem | null>(null);
const editRoles = ref<string[]>([]);

function openRoles(u: UserAdminItem) {
  editUser.value = u;
  editRoles.value = [...u.roles];
  rolesOpen.value = true;
}
async function saveRoles() {
  if (!editUser.value) return;
  savingRoles.value = true;
  try {
    await updateUserRoles(editUser.value.id, editRoles.value);
    editUser.value.roles = [...editRoles.value];
    message.success('Roller güncellendi.');
    rolesOpen.value = false;
  } catch (error) {
    console.error(error);
    message.error('Roller güncellenemedi.');
  } finally {
    savingRoles.value = false;
  }
}

/* Admin password reset modal */
const pwOpen = ref(false);
const pwSaving = ref(false);
const pwUser = ref<UserAdminItem | null>(null);
const pwValue = ref('');
function openResetPassword(u: UserAdminItem) {
  pwUser.value = u;
  pwValue.value = '';
  pwOpen.value = true;
}
async function saveResetPassword() {
  if (!pwUser.value) return;
  if (pwValue.value.length < 6) {
    message.warning('Şifre en az 6 karakter olmalı.');
    return;
  }
  pwSaving.value = true;
  try {
    await adminResetPassword(pwUser.value.id, pwValue.value);
    message.success(`${pwUser.value.username} için şifre sıfırlandı.`);
    pwOpen.value = false;
  } catch (error) {
    message.error(extractApiError(error) ?? 'Şifre sıfırlanamadı.');
  } finally {
    pwSaving.value = false;
  }
}

async function resetUserMfa(u: UserAdminItem) {
  try {
    await adminResetMfa(u.id);
    message.success(`${u.username} için 2FA sıfırlandı.`);
    await load();
  } catch (error) {
    message.error(extractApiError(error) ?? '2FA sıfırlanamadı.');
  }
}

async function exportCsv() {
  exporting.value = true;
  try {
    const csv = await exportAuditLogsCsv({ limit: 500 });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'denetim-kayitlari.csv';
    a.click();
    URL.revokeObjectURL(url);
    message.success('CSV indirildi.');
  } catch (error) {
    console.error(error);
    message.error('CSV indirilemedi.');
  } finally {
    exporting.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="acc">
    <header class="acc__bar">
      <div>
        <h1 class="acc__title">Yetki & Erişim</h1>
        <p class="acc__sub">{{ stats.active }} aktif · {{ stats.total }} kullanıcı</p>
      </div>
    </header>

    <section class="acc__section">
      <h2 class="acc__h2">Kullanıcılar</h2>

      <!-- Desktop table -->
      <div class="acc__table ui-card">
        <table>
          <thead>
            <tr>
              <th>Kullanıcı</th>
              <th>Ad</th>
              <th>Roller</th>
              <th>Durum</th>
              <th class="ta-r">İşlemler</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id">
              <td class="td-name">{{ u.username }}</td>
              <td>{{ u.real_name }}</td>
              <td>
                <span v-for="r in u.roles" :key="r" class="acc__role" :class="{ 'is-super': r === 'super' }">{{ roleLabel(r) }}</span>
              </td>
              <td>
                <Switch :checked="u.is_active" checked-children="Aktif" un-checked-children="Pasif" @change="(c: unknown) => toggleUser(u, c === true)" />
              </td>
              <td class="ta-r">
                <button class="acc__lnk" type="button" @click="openRoles(u)">Roller</button>
                <button class="acc__lnk" type="button" @click="openResetPassword(u)">Şifre</button>
                <Popconfirm title="Bu kullanıcının 2FA'sı sıfırlansın mı?" ok-text="Sıfırla" cancel-text="Vazgeç" @confirm="resetUserMfa(u)">
                  <button class="acc__lnk acc__lnk--warn" type="button">2FA Sıfırla</button>
                </Popconfirm>
              </td>
            </tr>
            <tr v-if="!users.length">
              <td colspan="5" class="acc__empty">Kullanıcı bulunamadı.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <div class="acc__cards">
        <article v-for="u in users" :key="u.id" class="acc__card ui-card">
          <div class="acc__card-top">
            <div>
              <strong>{{ u.real_name }}</strong>
              <span>@{{ u.username }}</span>
            </div>
            <Switch :checked="u.is_active" checked-children="Aktif" un-checked-children="Pasif" @change="(c: unknown) => toggleUser(u, c === true)" />
          </div>
          <div class="acc__roles">
            <span v-for="r in u.roles" :key="r" class="acc__role" :class="{ 'is-super': r === 'super' }">{{ roleLabel(r) }}</span>
          </div>
          <div class="acc__card-actions">
            <button class="acc__lnk" type="button" @click="openRoles(u)">Roller</button>
            <button class="acc__lnk" type="button" @click="openResetPassword(u)">Şifre</button>
            <Popconfirm title="2FA sıfırlansın mı?" ok-text="Sıfırla" cancel-text="Vazgeç" @confirm="resetUserMfa(u)">
              <button class="acc__lnk acc__lnk--warn" type="button">2FA Sıfırla</button>
            </Popconfirm>
          </div>
        </article>
        <p v-if="!users.length" class="acc__empty">Kullanıcı bulunamadı.</p>
      </div>
    </section>

    <section class="acc__section">
      <div class="acc__h2row">
        <h2 class="acc__h2">Denetim Kayıtları</h2>
        <Button :loading="exporting" @click="exportCsv">CSV indir</Button>
      </div>
      <div class="ui-card acc__logs">
        <ul>
          <li v-for="(item, i) in logs" :key="i" class="acc__log">
            <span class="acc__log-dot" />
            <span class="acc__log-text">
              <strong>{{ logText(item) }}</strong>
              <small>{{ item.actor_username }}</small>
            </span>
            <span class="acc__log-time">{{ logTime(item.created_at) }}</span>
          </li>
          <li v-if="!logs.length" class="acc__empty">Kayıt yok.</li>
        </ul>
      </div>
    </section>

    <!-- Role edit modal -->
    <Modal v-model:open="rolesOpen" title="Rolleri Düzenle" :confirm-loading="savingRoles" ok-text="Kaydet" cancel-text="Vazgeç" @ok="saveRoles">
      <p class="acc__modal-user">{{ editUser?.real_name }} <span>@{{ editUser?.username }}</span></p>
      <CheckboxGroup v-model:value="editRoles" :options="roleOptions" class="acc__role-checks" />
    </Modal>

    <!-- Admin password reset modal -->
    <Modal v-model:open="pwOpen" title="Şifre Sıfırla" :confirm-loading="pwSaving" ok-text="Sıfırla" cancel-text="Vazgeç" @ok="saveResetPassword">
      <p class="acc__modal-user">{{ pwUser?.real_name }} <span>@{{ pwUser?.username }}</span></p>
      <Input v-model:value="pwValue" type="password" placeholder="Yeni şifre (min 6 karakter)" @press-enter="saveResetPassword" />
    </Modal>
  </div>
</template>

<style scoped>
/* Faz PAGE-FIT: viewport-fit. */
.acc {
  display: flex;
  flex-direction: column;
  gap: 10px;
  height: calc(100dvh - 72px);
  overflow: hidden;
  box-sizing: border-box;
}
.acc__table,
.acc__cards,
.acc > section:last-of-type,
.acc > div:last-of-type {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}
.acc__title {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.acc__sub {
  margin: 2px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.acc__card-actions {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}
.acc__lnk--warn {
  color: var(--c-warn) !important;
}
.acc__section {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.acc__h2 {
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.acc__h2row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
}

.acc__table {
  display: none;
  overflow: hidden;
}
.acc__table table {
  width: 100%;
  border-collapse: collapse;
}
.acc__table th {
  text-align: left;
  padding: 13px 16px;
  font-size: var(--t-xs);
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--c-text-2);
  border-bottom: 1px solid var(--c-line);
  background: var(--c-surface-2);
}
.acc__table td {
  padding: 13px 16px;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  border-bottom: 1px solid var(--c-line);
}
.acc__table tr:last-child td {
  border-bottom: none;
}
.td-name {
  font-weight: 700;
  color: var(--c-text) !important;
}
.ta-r {
  text-align: right;
}
.acc__lnk {
  border: none;
  background: transparent;
  color: var(--c-info);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
  padding: 2px;
}
.acc__lnk:hover {
  text-decoration: underline;
}

.acc__role {
  display: inline-block;
  margin: 2px 6px 2px 0;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-info);
  background: rgba(96, 165, 250, 0.12);
}
.acc__role.is-super {
  color: var(--c-brand);
  background: rgba(225, 29, 72, 0.12);
}

.acc__empty {
  padding: 24px;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

.acc__cards {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.acc__card {
  padding: var(--sp-4);
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.acc__card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
}
.acc__card-top strong {
  display: block;
  font-size: 15px;
  font-weight: 800;
  color: var(--c-text);
}
.acc__card-top span {
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.acc__roles {
  display: flex;
  flex-wrap: wrap;
}

.acc__logs ul {
  list-style: none;
  margin: 0;
  padding: var(--sp-2) var(--sp-4);
}
.acc__log {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  padding: 10px 0;
  border-top: 1px solid var(--c-line);
}
.acc__log:first-child {
  border-top: none;
}
.acc__log-dot {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: var(--c-info);
  flex-shrink: 0;
}
.acc__log-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.acc__log-text strong {
  font-size: var(--t-sm);
  font-weight: 700;
  color: var(--c-text);
}
.acc__log-text small {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}
.acc__log-time {
  font-size: var(--t-xs);
  color: var(--c-text-3);
  white-space: nowrap;
}

.acc__modal-user {
  margin: 0 0 var(--sp-4);
  font-weight: 800;
  color: var(--c-text);
}
.acc__modal-user span {
  font-weight: 500;
  color: var(--c-text-3);
}
.acc__role-checks {
  display: grid !important;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

@media (min-width: 768px) {
  .acc__cards {
    display: none;
  }
  .acc__table {
    display: block;
  }
}
</style>
