<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';

import {
  Button,
  Input,
  Modal,
  Popconfirm,
  Select,
  Switch,
  message,
} from 'ant-design-vue';

import {
  buildSoleaLink,
  createStation,
  deleteStation,
  generateToken,
  getStations,
  PART_LABELS,
  PART_LIST,
  REGION_LABELS,
  REGION_LIST,
  type PartCode,
  type RegionCode,
  type StationItem,
  type StationStatus,
  toggleStationStatus,
  updateStation,
} from '#/api/modules/radioMedia';
import {
  provisionPartner,
  rotatePartnerPassword,
  rotatePartnerTokens,
} from '#/api/modules/portal';

const stations = ref<StationItem[]>([]);
const loading = ref(false);
const search = ref('');
const regionFilter = ref<RegionCode | undefined>();
const statusFilter = ref<StationStatus | undefined>();

const regionOptions = REGION_LIST.map((r) => ({ label: REGION_LABELS[r], value: r }));
const statusOptions: Array<{ label: string; value: StationStatus }> = [
  { label: 'Yayında', value: 'active' },
  { label: 'Duraklatıldı', value: 'paused' },
  { label: 'Arşiv', value: 'archived' },
];
const partOptions = PART_LIST.map((p) => ({ label: PART_LABELS[p], value: p }));

// ---- Edit modal ----
const modalOpen = ref(false);
const editingId = ref<string | null>(null);
const saving = ref(false);
const form = ref<{
  name: string;
  region_code: RegionCode;
  city_name: string;
  is_active: boolean;
  national_access: boolean;
}>({
  name: '',
  region_code: 'marmara',
  city_name: '',
  is_active: true,
  national_access: false,
});

// ---- Solea link modal ----
const linkOpen = ref(false);
const linkStation = ref<StationItem | null>(null);
const linkCategory = ref<PartCode>('news');
const linkBusy = ref(false);

const filtered = computed(() => {
  const q = search.value.trim().toLocaleLowerCase('tr-TR');
  return stations.value.filter((s) => {
    const matchQ =
      !q ||
      s.name.toLocaleLowerCase('tr-TR').includes(q) ||
      (s.city_name ?? '').toLocaleLowerCase('tr-TR').includes(q) ||
      s.region_name.toLocaleLowerCase('tr-TR').includes(q);
    const matchR = !regionFilter.value || s.region_code === regionFilter.value;
    const matchS = !statusFilter.value || s.status === statusFilter.value;
    return matchQ && matchR && matchS;
  });
});

const totals = computed(() => ({
  total: stations.value.length,
  active: stations.value.filter((s) => s.is_active ?? s.status === 'active').length,
}));

const linkUrl = computed(() => {
  const s = linkStation.value;
  if (!s) return '';
  const token = s.stream_token || s.station_token || '';
  return buildSoleaLink(s.region_code, linkCategory.value, token || 'token-bekleniyor');
});

function displayStatus(s: StationItem): StationStatus {
  if (s.status === 'archived') return 'archived';
  return (s.is_active ?? s.status === 'active') ? 'active' : 'paused';
}
function statusLabel(s: StationStatus) {
  return s === 'active' ? 'Yayında' : s === 'paused' ? 'Duraklatıldı' : 'Arşiv';
}
function statusTone(s: StationStatus) {
  return s === 'active' ? 'ok' : s === 'paused' ? 'warn' : 'muted';
}

async function loadStations() {
  loading.value = true;
  try {
    const res = await getStations();
    stations.value = Array.isArray(res) ? res : [];
  } catch (error) {
    console.error(error);
    stations.value = [];
    message.error('İstasyon listesi alınamadı.');
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editingId.value = null;
  form.value = { name: '', region_code: 'marmara', city_name: '', is_active: true, national_access: false };
  modalOpen.value = true;
}
function openEdit(s: StationItem) {
  editingId.value = s.id;
  form.value = {
    name: s.name,
    region_code: s.region_code,
    city_name: s.city_name ?? '',
    is_active: s.is_active ?? s.status === 'active',
    national_access: s.national_access ?? false,
  };
  modalOpen.value = true;
}

async function saveStation() {
  if (!form.value.name.trim() || !form.value.city_name.trim()) {
    message.warning('İstasyon adı ve il zorunludur.');
    return;
  }
  saving.value = true;
  try {
    const payload = {
      name: form.value.name.trim(),
      region_code: form.value.region_code,
      city_name: form.value.city_name.trim(),
      is_active: form.value.is_active,
      status: (form.value.is_active ? 'active' : 'paused') as StationStatus,
      national_access: form.value.national_access,
    };
    if (editingId.value) {
      await updateStation(editingId.value, payload);
      message.success('İstasyon güncellendi.');
      modalOpen.value = false;
      await loadStations();
    } else {
      const res = await createStation(payload);
      message.success('İstasyon eklendi.');
      modalOpen.value = false;
      await loadStations();
      // Auto-provision returned one-shot credentials → surface them now.
      const p = res?.result?.partner;
      const fresh = res?.result?.station;
      if (p?.one_time_password && fresh) {
        partnerStation.value = fresh;
        partnerCreds.value = {
          username: p.username,
          password: p.one_time_password,
        };
        partnerCopied.value = false;
        partnerOpen.value = true;
      }
    }
  } catch (error) {
    console.error(error);
    message.error('İstasyon kaydedilemedi.');
  } finally {
    saving.value = false;
  }
}

async function removeStation(id: string) {
  try {
    await deleteStation(id);
    message.success('İstasyon kaldırıldı.');
    await loadStations();
  } catch (error) {
    console.error(error);
    message.error('İstasyon silinemedi.');
  }
}

async function toggleActive(s: StationItem, checked: boolean) {
  try {
    await toggleStationStatus(s.id, checked);
    s.is_active = checked;
    s.status = checked ? 'active' : 'paused';
    message.success(checked ? 'İstasyon yayına alındı.' : 'İstasyon pasife alındı.');
  } catch (error) {
    console.error(error);
    message.error('Durum güncellenemedi.');
  }
}

function openLink(s: StationItem) {
  linkStation.value = s;
  linkCategory.value = 'news';
  linkOpen.value = true;
}

// --- Faz 17: Partner-portal management for this station ------------------
const partnerOpen = ref(false);
const partnerStation = ref<StationItem | null>(null);
const partnerBusy = ref(false);
/** One-shot credential surface — wiped when the modal closes. */
const partnerCreds = ref<{ username?: string; password?: string } | null>(null);
const partnerCopied = ref(false);

function openPartner(s: StationItem) {
  partnerStation.value = s;
  partnerCreds.value = null;
  partnerCopied.value = false;
  partnerOpen.value = true;
}
function closePartner() {
  // Wipe the one-shot password from memory the instant the operator closes
  // the dialog. If they need it again they must rotate again.
  partnerCreds.value = null;
  partnerOpen.value = false;
}
async function doProvision() {
  if (!partnerStation.value) return;
  partnerBusy.value = true;
  try {
    const res = await provisionPartner(partnerStation.value.id);
    partnerCreds.value = {
      username: res.result.username,
      password: res.result.one_time_password,
    };
    message.success('Partner kullanıcısı oluşturuldu.');
  } catch (error) {
    message.error((error as Error)?.message ?? 'Oluşturulamadı.');
  } finally {
    partnerBusy.value = false;
  }
}
async function doRotatePassword() {
  if (!partnerStation.value) return;
  partnerBusy.value = true;
  try {
    const res = await rotatePartnerPassword(partnerStation.value.id);
    partnerCreds.value = { password: res.result.one_time_password };
    message.success('Şifre yenilendi.');
  } catch (error) {
    message.error((error as Error)?.message ?? 'Yenilenemedi.');
  } finally {
    partnerBusy.value = false;
  }
}
async function doRotateTokens() {
  if (!partnerStation.value) return;
  partnerBusy.value = true;
  try {
    await rotatePartnerTokens(partnerStation.value.id);
    message.success('Yayın bağlantıları yenilendi (8 anahtar). Eski URL\'ler iptal edildi.');
  } catch (error) {
    message.error((error as Error)?.message ?? 'Anahtar yenilenemedi.');
  } finally {
    partnerBusy.value = false;
  }
}
async function copyCreds() {
  const c = partnerCreds.value;
  if (!c?.password) return;
  const text = c.username ? `${c.username}\n${c.password}` : c.password;
  try {
    await navigator.clipboard.writeText(text);
    partnerCopied.value = true;
    setTimeout(() => (partnerCopied.value = false), 1500);
    message.success('Kopyalandı.');
  } catch {
    message.error('Kopyalanamadı.');
  }
}

async function regenToken() {
  if (!linkStation.value) return;
  linkBusy.value = true;
  try {
    const res = await generateToken(linkStation.value.id);
    const token = res.station_token || res.stream_token || '';
    linkStation.value.stream_token = token;
    linkStation.value.station_token = token;
    message.success('Yeni anahtar üretildi.');
  } catch (error) {
    console.error(error);
    message.error('Anahtar üretilemedi.');
  } finally {
    linkBusy.value = false;
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(linkUrl.value);
    message.success('Yayın bağlantısı kopyalandı.');
  } catch {
    message.error('Kopyalanamadı.');
  }
}

onMounted(loadStations);
</script>

<template>
  <div class="stn">
    <!-- Toolbar -->
    <header class="stn__bar">
      <div class="stn__bar-info">
        <h1 class="stn__title">İstasyonlar</h1>
        <p class="stn__sub">{{ totals.active }} aktif · {{ totals.total }} toplam</p>
      </div>
      <Button type="primary" class="stn__add" @click="openCreate">+ Yeni İstasyon</Button>
    </header>

    <div class="stn__filters ui-card">
      <Input v-model:value="search" placeholder="İstasyon, il veya bölge ara" allow-clear class="stn__search" />
      <Select v-model:value="regionFilter" allow-clear placeholder="Bölge" :options="regionOptions" class="stn__f" />
      <Select v-model:value="statusFilter" allow-clear placeholder="Durum" :options="statusOptions" class="stn__f" />
    </div>

    <!-- Desktop table -->
    <div class="stn__table ui-card">
      <table>
        <thead>
          <tr>
            <th>İstasyon</th>
            <th>Bölge</th>
            <th>İl</th>
            <th>Durum</th>
            <th class="ta-r">İşlemler</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in filtered" :key="s.id">
            <td class="td-name">{{ s.name }}</td>
            <td>{{ s.region_name }}</td>
            <td>{{ s.city_name || '—' }}</td>
            <td>
              <span class="stn__chip" :class="`is-${statusTone(displayStatus(s))}`">{{ statusLabel(displayStatus(s)) }}</span>
            </td>
            <td class="ta-r">
              <div class="stn__actions">
                <Switch
                  size="small"
                  :checked="s.is_active ?? s.status === 'active'"
                  @change="(c: unknown) => toggleActive(s, c === true)"
                />
                <button class="stn__lnk" type="button" @click="openLink(s)">Bağlantı</button>
                <button class="stn__lnk" type="button" @click="openEdit(s)">Düzenle</button>
                <Popconfirm title="İstasyonu silmek istiyor musunuz?" ok-text="Sil" cancel-text="Vazgeç" @confirm="removeStation(s.id)">
                  <button class="stn__lnk is-danger" type="button">Sil</button>
                </Popconfirm>
              </div>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="5" class="stn__empty">Kayıt bulunamadı.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div class="stn__cards">
      <article v-for="s in filtered" :key="s.id" class="stn__card ui-card">
        <div class="stn__card-top">
          <div>
            <strong class="stn__card-name">{{ s.name }}</strong>
            <span class="stn__card-meta">{{ s.region_name }} · {{ s.city_name || '—' }}</span>
          </div>
          <span class="stn__chip" :class="`is-${statusTone(displayStatus(s))}`">{{ statusLabel(displayStatus(s)) }}</span>
        </div>
        <div class="stn__card-foot">
          <label class="stn__switch">
            <Switch
              size="small"
              :checked="s.is_active ?? s.status === 'active'"
              @change="(c: unknown) => toggleActive(s, c === true)"
            />
            <span>{{ (s.is_active ?? s.status === 'active') ? 'Aktif' : 'Pasif' }}</span>
          </label>
          <div class="stn__card-actions">
            <button class="stn__lnk" type="button" @click="openLink(s)">Bağlantı</button>
            <button class="stn__lnk" type="button" @click="openPartner(s)">Panel</button>
            <button class="stn__lnk" type="button" @click="openEdit(s)">Düzenle</button>
            <Popconfirm title="Silinsin mi?" ok-text="Sil" cancel-text="Vazgeç" @confirm="removeStation(s.id)">
              <button class="stn__lnk is-danger" type="button">Sil</button>
            </Popconfirm>
          </div>
        </div>
      </article>
      <p v-if="!filtered.length" class="stn__empty">Kayıt bulunamadı.</p>
    </div>

    <!-- Edit / create modal -->
    <Modal
      v-model:open="modalOpen"
      :title="editingId ? 'İstasyonu Düzenle' : 'Yeni İstasyon'"
      :confirm-loading="saving"
      ok-text="Kaydet"
      cancel-text="Vazgeç"
      @ok="saveStation"
    >
      <div class="stn__form">
        <label>
          <span>İstasyon Adı</span>
          <Input v-model:value="form.name" placeholder="Örn. Akdeniz FM" />
        </label>
        <label>
          <span>Bölge</span>
          <Select v-model:value="form.region_code" :options="regionOptions" />
        </label>
        <label>
          <span>İl</span>
          <Input v-model:value="form.city_name" placeholder="Örn. Antalya" />
        </label>
        <label class="stn__form-row">
          <span>Yayında</span>
          <Switch v-model:checked="form.is_active" />
        </label>
        <label class="stn__form-row">
          <span>
            🌍 Ulusal Erişim
            <em class="stn__hint" style="display: block; font-weight: 400">
              Tüm bölgelerin içeriklerini görür (örn. ulusal yayın ağları).
            </em>
          </span>
          <Switch v-model:checked="form.national_access" />
        </label>
      </div>
    </Modal>

    <!-- Solea link modal -->
    <Modal v-model:open="linkOpen" title="Yayın Bağlantısı" :footer="null">
      <div class="stn__link">
        <p class="stn__link-name">{{ linkStation?.name }}</p>
        <label>
          <span>İçerik türü</span>
          <Select v-model:value="linkCategory" :options="partOptions" style="width: 100%" />
        </label>
        <code class="stn__link-url">{{ linkUrl }}</code>
        <div class="stn__link-actions">
          <Button :loading="linkBusy" @click="regenToken">Anahtar Yenile</Button>
          <Button type="primary" @click="copyLink">Kopyala</Button>
        </div>
      </div>
    </Modal>

    <!-- Partner Portal management modal -->
    <Modal
      v-model:open="partnerOpen"
      title="Partner Radyo Yönetimi"
      :footer="null"
      :after-close="closePartner"
    >
      <div class="stn__partner">
        <p class="stn__link-name">{{ partnerStation?.name }}</p>

        <!-- One-shot credentials surface — visible only after a provision /
             password rotation. Auto-discarded when the modal closes. -->
        <div v-if="partnerCreds" class="stn__creds">
          <p class="stn__warn">
            ⚠ Aşağıdaki bilgi <strong>bir kez</strong> gösterilir. Pencereyi kapatırsanız
            bir daha okunamaz; ihtiyaç olursa <em>Şifre Yenile</em> ile yeni bir
            tek-seferlik şifre üretebilirsiniz.
          </p>
          <div v-if="partnerCreds.username" class="stn__cred-row">
            <span>Kullanıcı Adı</span><code>{{ partnerCreds.username }}</code>
          </div>
          <div class="stn__cred-row">
            <span>Şifre</span><code>{{ partnerCreds.password }}</code>
          </div>
          <Button :type="partnerCopied ? 'default' : 'primary'" block @click="copyCreds">
            {{ partnerCopied ? '✓ Kopyalandı' : 'Kopyala' }}
          </Button>
        </div>

        <p v-if="!partnerStation?.user_id && !partnerCreds" class="stn__hint">
          Bu radyonun henüz partner kullanıcısı yok. “Kullanıcı Oluştur”'a basınca
          otomatik kullanıcı adı + güçlü şifre üretilir ve <strong>bir defa</strong> gösterilir.
        </p>
        <p v-else-if="!partnerCreds" class="stn__hint">
          Radyonun partner kullanıcısı mevcut. Şifre veya yayın linklerini yenileyebilirsiniz.
        </p>

        <div class="stn__partner-actions">
          <Button
            v-if="!partnerStation?.user_id"
            type="primary"
            :loading="partnerBusy"
            @click="doProvision"
          >+ Kullanıcı Oluştur</Button>
          <Button v-else :loading="partnerBusy" @click="doRotatePassword">🔑 Şifre Yenile</Button>
          <Button :loading="partnerBusy" @click="doRotateTokens">🔗 Bağlantıları Yenile</Button>
          <Button type="text" @click="closePartner">Kapat</Button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.stn {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}

.stn__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
}
.stn__title {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.stn__sub {
  margin: 2px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}

.stn__filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--sp-3);
  padding: var(--sp-3);
}
.stn__search {
  flex: 1 1 220px;
}
.stn__f {
  flex: 0 1 160px;
  min-width: 130px;
}

/* Table (desktop) */
.stn__table {
  display: none;
  overflow: hidden;
}
.stn__table table {
  width: 100%;
  border-collapse: collapse;
}
.stn__table th {
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
.stn__table td {
  padding: 13px 16px;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  border-bottom: 1px solid var(--c-line);
}
.stn__table tr:last-child td {
  border-bottom: none;
}
.stn__table tbody tr:hover td {
  background: rgba(148, 163, 184, 0.05);
}
.td-name {
  font-weight: 700;
  color: var(--c-text) !important;
}
.ta-r {
  text-align: right;
}

.stn__actions {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  justify-content: flex-end;
}
.stn__lnk {
  border: none;
  background: transparent;
  color: var(--c-info);
  font-size: var(--t-sm);
  font-weight: 700;
  cursor: pointer;
  padding: 2px;
}
.stn__lnk:hover {
  text-decoration: underline;
}
.stn__lnk.is-danger {
  color: var(--c-bad);
}

.stn__chip {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 700;
}
.stn__chip.is-ok {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
}
.stn__chip.is-warn {
  color: var(--c-warn);
  background: rgba(251, 191, 36, 0.12);
}
.stn__chip.is-muted {
  color: var(--c-text-3);
  background: rgba(148, 163, 184, 0.1);
}

.stn__empty {
  padding: 28px;
  text-align: center;
  color: var(--c-text-3);
  font-size: var(--t-sm);
}

/* Cards (mobile) */
.stn__cards {
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.stn__card {
  padding: var(--sp-4);
  display: flex;
  flex-direction: column;
  gap: var(--sp-3);
}
.stn__card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--sp-3);
}
.stn__card-name {
  display: block;
  font-size: 15px;
  font-weight: 800;
  color: var(--c-text);
}
.stn__card-meta {
  display: block;
  margin-top: 3px;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.stn__card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--sp-3);
  padding-top: var(--sp-3);
  border-top: 1px solid var(--c-line);
}
.stn__switch {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: var(--t-sm);
  color: var(--c-text-2);
}
.stn__card-actions {
  display: inline-flex;
  align-items: center;
  gap: 14px;
}

/* Forms inside modals */
.stn__form,
.stn__link {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
  padding-top: var(--sp-2);
}
.stn__form label,
.stn__link label {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.stn__form label span,
.stn__link label span {
  font-size: var(--t-xs);
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--c-text-2);
}
.stn__form-row {
  flex-direction: row !important;
  align-items: center;
  justify-content: space-between;
}
.stn__link-name {
  margin: 0;
  font-weight: 800;
  font-size: 15px;
  color: var(--c-text);
}
.stn__link-url {
  display: block;
  padding: 10px 12px;
  border-radius: var(--r-sm);
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
  font-size: 12px;
  color: var(--c-info);
  word-break: break-all;
}
.stn__link-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--sp-2);
}

/* Partner-portal management modal */
.stn__partner {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.stn__hint {
  margin: 0;
  font-size: 12px;
  color: var(--c-text-3);
}
.stn__creds {
  padding: 12px;
  border-radius: 12px;
  border: 1px solid rgba(251, 191, 36, 0.32);
  background: rgba(251, 191, 36, 0.06);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.stn__warn {
  margin: 0 0 6px;
  font-size: 12px;
  color: var(--c-warn);
}
.stn__cred-row {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.stn__cred-row span {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--c-text-3);
}
.stn__cred-row code {
  padding: 8px 10px;
  font-family: 'Fira Code', 'Consolas', monospace;
  font-size: 14px;
  color: var(--c-text);
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-radius: 8px;
  word-break: break-all;
  user-select: all;
}
.stn__partner-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

/* Responsive switch: cards on mobile, table on >= 768 */
@media (min-width: 768px) {
  .stn__cards {
    display: none;
  }
  .stn__table {
    display: block;
  }
}
</style>
