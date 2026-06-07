<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';

import { message } from 'ant-design-vue';

import {
  changePassword,
  disableMfa,
  enableMfa,
  getMfaStatus,
  logout,
  setupMfa,
  type MfaStatus,
} from '#/api/modules/auth';
import {
  type PortalActivity,
  type PortalCard,
  type PortalDownload,
  type PortalLink,
  type PortalMediaItem,
  type PortalPlan,
  type PortalSponsorItem,
  PURPOSE_ICONS,
  PURPOSE_LABELS,
  getPortalActivity,
  getPortalAds,
  getPortalDownloads,
  getPortalFeeds,
  getPortalLinks,
  getPortalMe,
  getPortalMedia,
  getPortalSponsors,
} from '#/api/modules/portal';
import {
  CATEGORY_LABELS,
  STATUS_LABELS,
  type SupportCategory,
  type SupportMessage,
  type SupportTicket,
  createSupportTicket,
  getSupportTicket,
  listSupportTickets,
  replySupportTicket,
} from '#/api/modules/support';
import {
  type PartnerApiKey,
  issuePartnerApiKey,
  listPartnerApiKeys,
  revokePartnerApiKey,
} from '#/api/modules/apikeys';

type Tab = 'links' | 'feeds' | 'media' | 'activity' | 'support' | 'apikeys' | 'security';

const loading = ref(true);
const card = ref<PortalCard | null>(null);
const links = ref<PortalLink[]>([]);
const plans = ref<PortalPlan[]>([]);
const mediaItems = ref<PortalMediaItem[]>([]);
const downloadsList = ref<PortalDownload[]>([]);
const sponsorsList = ref<PortalSponsorItem[]>([]);
const adsList = ref<PortalSponsorItem[]>([]);
type MediaSubTab = 'available' | 'downloads' | 'sponsors' | 'ads';
const mediaSubTab = ref<MediaSubTab>('available');
const activity = ref<PortalActivity[]>([]);
const tab = ref<Tab>('links');
const copiedKey = ref<string>('');

const today = computed(() => dayjs().format('DD MMM YYYY'));

const fmtTime = (iso?: string | null) =>
  iso ? dayjs(iso).format('DD.MM.YYYY HH:mm') : '—';
const fmtDuration = (ms?: number) => {
  if (!ms || ms <= 0) return '—';
  const s = Math.round(ms / 1000);
  const m = Math.floor(s / 60);
  const ss = (s % 60).toString().padStart(2, '0');
  return `${m}:${ss}`;
};

async function load() {
  loading.value = true;
  try {
    const [me, ln, ff, mm, ac, tk, dl, sp, ad] = await Promise.all([
      getPortalMe(),
      getPortalLinks(),
      getPortalFeeds(),
      getPortalMedia(),
      getPortalActivity(),
      listSupportTickets(),
      getPortalDownloads(),
      getPortalSponsors(),
      getPortalAds(),
    ]);
    card.value = me?.result ?? null;
    links.value = ln?.result?.links ?? [];
    plans.value = ff?.result?.plans ?? [];
    mediaItems.value = mm?.result?.items ?? [];
    activity.value = ac?.result?.logs ?? [];
    tickets.value = tk?.result?.tickets ?? [];
    downloadsList.value = dl?.result?.downloads ?? [];
    sponsorsList.value = sp?.result?.sponsors ?? [];
    adsList.value = ad?.result?.ads ?? [];
  } catch {
    message.error('Veriler yüklenemedi.');
  } finally {
    loading.value = false;
  }
}

// --- Support tab state ----------------------------------------------------
const tickets = ref<SupportTicket[]>([]);
const newTicket = ref<{ category: SupportCategory; subject: string; body: string }>({
  category: 'technical',
  subject: '',
  body: '',
});
const showNewTicket = ref(false);
const submittingTicket = ref(false);
const openTicketId = ref<string | null>(null);
const openTicketMessages = ref<SupportMessage[]>([]);
const replyBody = ref('');

async function submitNewTicket() {
  if (!newTicket.value.subject.trim() || !newTicket.value.body.trim()) {
    message.warning('Konu ve açıklama gerekli.');
    return;
  }
  submittingTicket.value = true;
  try {
    await createSupportTicket({
      category: newTicket.value.category,
      subject: newTicket.value.subject.trim(),
      body: newTicket.value.body.trim(),
    });
    message.success('Destek talebi oluşturuldu.');
    newTicket.value = { category: 'technical', subject: '', body: '' };
    showNewTicket.value = false;
    const res = await listSupportTickets();
    tickets.value = res?.result?.tickets ?? [];
  } catch {
    message.error('Talep oluşturulamadı.');
  } finally {
    submittingTicket.value = false;
  }
}

async function openTicket(id: string) {
  openTicketId.value = id;
  openTicketMessages.value = [];
  try {
    const res = await getSupportTicket(id);
    openTicketMessages.value = res?.result?.messages ?? [];
  } catch {
    message.error('Talep okunamadı.');
  }
}

async function sendReply() {
  if (!openTicketId.value || !replyBody.value.trim()) return;
  try {
    await replySupportTicket(openTicketId.value, replyBody.value.trim());
    replyBody.value = '';
    const res = await getSupportTicket(openTicketId.value);
    openTicketMessages.value = res?.result?.messages ?? [];
    message.success('Yanıt gönderildi.');
  } catch {
    message.error('Yanıt gönderilemedi.');
  }
}

const categoryOptions = (Object.keys(CATEGORY_LABELS) as SupportCategory[]).map((k) => ({
  value: k,
  label: CATEGORY_LABELS[k],
}));

// --- API keys tab state ---------------------------------------------------
const apiKeys = ref<PartnerApiKey[]>([]);
const newApiKeyName = ref('');
const oneTimeApiKey = ref<string | null>(null);
async function loadApiKeys() {
  try {
    const res = await listPartnerApiKeys();
    apiKeys.value = Array.isArray(res?.result?.keys) ? res.result.keys : [];
  } catch (e) {
    apiKeys.value = [];
    message.warning(`API anahtarları alınamadı: ${(e as Error)?.message ?? ''}`.trim());
  }
}
async function issueApiKey() {
  if (!newApiKeyName.value.trim()) {
    message.warning('Anahtar için isim girin.');
    return;
  }
  try {
    const res = await issuePartnerApiKey(newApiKeyName.value.trim());
    oneTimeApiKey.value = res?.result?.one_time_key ?? null;
    newApiKeyName.value = '';
    await loadApiKeys();
    message.success('API anahtarı oluşturuldu — yalnızca bir kez gösterilir.');
  } catch {
    message.error('Anahtar oluşturulamadı.');
  }
}
async function revokeApiKey(id: string) {
  try {
    await revokePartnerApiKey(id);
    await loadApiKeys();
    message.success('Anahtar iptal edildi.');
  } catch {
    message.error('İptal başarısız.');
  }
}
function clearOneTimeApiKey() {
  oneTimeApiKey.value = null;
}

// --- Faz 28: Partner self-service MFA + password ----------------------
const mfaStatus = ref<MfaStatus | null>(null);
const mfaSetupResult = ref<{ secret: string; otpauth_uri: string } | null>(null);
const mfaCode = ref('');
const mfaBusy = ref(false);
const mfaRecoveryCodes = ref<string[]>([]);
const pwCurrent = ref('');
const pwNew = ref('');
const pwBusy = ref(false);

async function loadMfaStatus() {
  try {
    const res = await getMfaStatus();
    mfaStatus.value = res?.result ?? null;
  } catch (e) {
    mfaStatus.value = null;
    message.warning(`MFA durumu alınamadı: ${(e as Error)?.message ?? ''}`.trim());
  }
}
async function startMfaSetup() {
  mfaBusy.value = true;
  try {
    const res = await setupMfa();
    mfaSetupResult.value = res?.result ?? null;
  } catch {
    message.error('MFA başlatılamadı.');
  } finally {
    mfaBusy.value = false;
  }
}
async function confirmMfa() {
  if (!mfaCode.value.trim()) return;
  mfaBusy.value = true;
  try {
    const res = await enableMfa(mfaCode.value.trim());
    mfaRecoveryCodes.value = res?.result?.recovery_codes ?? [];
    mfaSetupResult.value = null;
    mfaCode.value = '';
    await loadMfaStatus();
    message.success('MFA etkinleştirildi.');
  } catch {
    message.error('Kod doğrulanamadı.');
  } finally {
    mfaBusy.value = false;
  }
}
async function turnOffMfa() {
  if (!mfaCode.value.trim()) {
    message.warning('Mevcut MFA kodunuzu girin.');
    return;
  }
  mfaBusy.value = true;
  try {
    await disableMfa(mfaCode.value.trim());
    mfaCode.value = '';
    mfaRecoveryCodes.value = [];
    await loadMfaStatus();
    message.success('MFA kapatıldı.');
  } catch {
    message.error('MFA kapatılamadı.');
  } finally {
    mfaBusy.value = false;
  }
}
async function submitPasswordChange() {
  if (!pwCurrent.value || !pwNew.value) {
    message.warning('Mevcut ve yeni şifre gerekli.');
    return;
  }
  pwBusy.value = true;
  try {
    await changePassword(pwCurrent.value, pwNew.value);
    pwCurrent.value = '';
    pwNew.value = '';
    message.success('Şifre güncellendi.');
  } catch {
    message.error('Şifre değiştirilemedi.');
  } finally {
    pwBusy.value = false;
  }
}

async function copy(text: string, key: string) {
  try {
    await navigator.clipboard.writeText(text);
    copiedKey.value = key;
    setTimeout(() => {
      if (copiedKey.value === key) copiedKey.value = '';
    }, 1400);
    message.success('Kopyalandı');
  } catch {
    message.error('Kopyalanamadı');
  }
}

function downloadMediaUrl(item: PortalMediaItem, format = 'mp3'): string {
  // Backend streams media via /media-stream/content/{id} for any authenticated
  // user; the partner reads it through the same cookie session.
  // Faz 23: ?format=mp3|wav|aac|m3u|pls — ffmpeg transcode for wav/aac,
  // text playlist for m3u/pls.
  return `/api/v1/media-stream/content/${item.id}?format=${format}`;
}
const DOWNLOAD_FORMATS = [
  { key: 'mp3', label: 'MP3' },
  { key: 'wav', label: 'WAV' },
  { key: 'aac', label: 'AAC' },
  { key: 'm3u', label: 'M3U' },
  { key: 'pls', label: 'PLS' },
] as const;

async function signOut() {
  await logout();
  window.location.href = '/login';
}

onMounted(async () => {
  await load();
  await loadApiKeys();
  await loadMfaStatus();
});
</script>

<template>
  <div class="prt">
    <!-- TOP: profile card -->
    <header class="prt__card ui-card">
      <div class="prt__id">
        <div v-if="card?.logo_url" class="prt__logo">
          <img :src="card.logo_url" :alt="card.name">
        </div>
        <div v-else class="prt__logo prt__logo--ph">📻</div>
        <div class="prt__id-text">
          <h1>
            {{ card?.name ?? 'Yükleniyor…' }}
            <em v-if="card?.frequency" class="prt__freq">{{ card.frequency }}</em>
          </h1>
          <p>
            <span v-if="card?.region_name">{{ card.region_name }}</span>
            <template v-if="card?.region_name && card?.city_name"> · </template>
            <span v-if="card?.city_name">{{ card.city_name }}</span>
            <template v-if="card?.company_name"> · {{ card.company_name }}</template>
          </p>
        </div>
        <button type="button" class="prt__logout" @click="signOut">Çıkış</button>
      </div>
      <div class="prt__meta">
        <span v-if="card?.contact_name"><b>Yetkili</b> {{ card.contact_name }}</span>
        <span v-if="card?.phone"><b>Telefon</b> {{ card.phone }}</span>
        <span v-if="card?.email"><b>E-Posta</b> {{ card.email }}</span>
        <span v-if="card?.website"><b>Web</b> {{ card.website }}</span>
        <span><b>Son Giriş</b> {{ fmtTime(card?.last_login_at) }}</span>
        <span><b>Son Yayın</b> {{ fmtTime(card?.last_broadcast_at) }}</span>
        <span class="prt__status" :class="card?.is_active ? 'is-on' : 'is-off'">
          {{ card?.is_active ? 'AKTİF' : 'PASİF' }}
        </span>
      </div>
    </header>

    <!-- Tabs -->
    <nav class="prt__tabs">
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'links' }"
        @click="tab = 'links'"
      >🔗 Linkler</button>
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'feeds' }"
        @click="tab = 'feeds'"
      >📅 Bugünkü Yayınlar</button>
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'media' }"
        @click="tab = 'media'"
      >📥 İndirme Merkezi</button>
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'activity' }"
        @click="tab = 'activity'"
      >📜 Aktivite</button>
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'support' }"
        @click="tab = 'support'"
      >🎫 Destek</button>
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'apikeys' }"
        @click="tab = 'apikeys'"
      >🔑 API Anahtarları</button>
      <button
        type="button"
        class="prt__tab"
        :class="{ 'is-active': tab === 'security' }"
        @click="tab = 'security'"
      >🔒 Güvenlik</button>
    </nav>

    <!-- LINKS -->
    <section v-if="tab === 'links'" class="prt__section">
      <p class="prt__hint">
        Aşağıdaki 8 yayın linki bu radyoya özeldir. Her formatın yanındaki
        “Kopyala” butonu URL'yi panoya yapıştırır. Linkler iptal edildiğinde
        otomatik olarak çalışmayı durur.
      </p>
      <ul class="prt__links">
        <li v-for="l in links" :key="l.purpose" class="prt__link ui-card">
          <header>
            <span class="prt__purpose-icon">{{ PURPOSE_ICONS[l.purpose] }}</span>
            <h3>{{ PURPOSE_LABELS[l.purpose] }}</h3>
            <span class="prt__purpose-tag">{{ l.purpose }}</span>
          </header>
          <div v-for="(url, fmt) in l.urls" :key="fmt" class="prt__url">
            <span class="prt__fmt">{{ fmt.toUpperCase() }}</span>
            <code>{{ url }}</code>
            <button
              type="button"
              class="prt__copy"
              :class="{ 'is-ok': copiedKey === `${l.purpose}_${fmt}` }"
              @click="copy(url, `${l.purpose}_${fmt}`)"
            >{{ copiedKey === `${l.purpose}_${fmt}` ? '✓' : 'Kopyala' }}</button>
          </div>
        </li>
      </ul>
    </section>

    <!-- TODAY'S BROADCASTS -->
    <section v-else-if="tab === 'feeds'" class="prt__section">
      <p class="prt__hint">{{ today }} için planlanan yayınlar</p>
      <div v-if="plans.length" class="prt__plans ui-card">
        <div v-for="p in plans" :key="p.id" class="prt__plan">
          <span class="prt__plan-slot">{{ p.slot_time.slice(0, 5) }}</span>
          <span class="prt__plan-title">{{ p.content_title }}</span>
          <span class="prt__plan-part">{{ p.part_code }}</span>
          <span class="prt__chip" :class="`is-${p.status}`">{{ p.status }}</span>
        </div>
      </div>
      <p v-else class="prt__empty">Bugün için planlanmış yayın yok.</p>
    </section>

    <!-- DOWNLOAD CENTER -->
    <section v-else-if="tab === 'media'" class="prt__section">
      <nav class="prt__subtabs">
        <button
          type="button"
          class="prt__subtab"
          :class="{ 'is-active': mediaSubTab === 'available' }"
          @click="mediaSubTab = 'available'"
        >📥 Mevcut ({{ mediaItems.length }})</button>
        <button
          type="button"
          class="prt__subtab"
          :class="{ 'is-active': mediaSubTab === 'downloads' }"
          @click="mediaSubTab = 'downloads'"
        >🕓 Son İndirilenler ({{ downloadsList.length }})</button>
        <button
          type="button"
          class="prt__subtab"
          :class="{ 'is-active': mediaSubTab === 'sponsors' }"
          @click="mediaSubTab = 'sponsors'"
        >🎯 Sponsor İçerikleri ({{ sponsorsList.length }})</button>
        <button
          type="button"
          class="prt__subtab"
          :class="{ 'is-active': mediaSubTab === 'ads' }"
          @click="mediaSubTab = 'ads'"
        >📢 Reklam İçerikleri ({{ adsList.length }})</button>
      </nav>

      <template v-if="mediaSubTab === 'available'">
        <p class="prt__hint">
          Bölgenize ait son içerikleri MP3, WAV, AAC, M3U veya PLS olarak indirebilirsiniz.
        </p>
        <div v-if="mediaItems.length" class="prt__media ui-card">
          <div v-for="m in mediaItems" :key="m.id" class="prt__media-row">
            <span class="prt__media-title">{{ m.title }}</span>
            <span class="prt__media-meta">{{ m.part_code }} · {{ fmtDuration(m.source_duration_ms) }}</span>
            <div class="prt__formats">
              <a
                v-for="f in DOWNLOAD_FORMATS"
                :key="f.key"
                class="prt__dl-fmt"
                :href="downloadMediaUrl(m, f.key)"
                target="_blank"
                rel="noopener"
              >{{ f.label }}</a>
            </div>
          </div>
        </div>
        <p v-else class="prt__empty">Henüz indirilebilir içerik yok.</p>
      </template>

      <template v-else-if="mediaSubTab === 'downloads'">
        <p class="prt__hint">Son 30 günde indirdiğiniz dosyalar (audit kayıtlarından).</p>
        <div v-if="downloadsList.length" class="prt__media ui-card">
          <div v-for="d in downloadsList" :key="d.id" class="prt__media-row">
            <span class="prt__media-title">{{ d.entity_type }} · {{ d.entity_id }}</span>
            <span class="prt__media-meta">{{ fmtTime(d.created_at) }}<template v-if="d.ip_address"> · {{ d.ip_address }}</template></span>
            <a
              class="prt__dl-fmt"
              :href="`/api/v1/media-stream/${d.entity_type === 'sponsor' ? 'sponsor' : 'content'}/${d.entity_id}`"
              target="_blank"
              rel="noopener"
            >Tekrar İndir</a>
          </div>
        </div>
        <p v-else class="prt__empty">Henüz indirme yok.</p>
      </template>

      <template v-else-if="mediaSubTab === 'sponsors'">
        <p class="prt__hint">Bölgenize atanmış sponsor takdimleri (yayın öncesi/sonrası).</p>
        <div v-if="sponsorsList.length" class="prt__media ui-card">
          <div v-for="s in sponsorsList" :key="s.id" class="prt__media-row">
            <span class="prt__media-title">{{ s.sponsor_name || '(adsız sponsor)' }}</span>
            <span class="prt__media-meta">
              {{ s.placement_type }} · {{ s.content_type }}
              <template v-if="s.is_global"> · Ulusal</template>
              <template v-else-if="s.region_name"> · {{ s.region_name }}</template>
            </span>
            <a class="prt__dl-fmt" :href="`/api/v1/media-stream/sponsor/${s.id}`" target="_blank" rel="noopener">Dinle</a>
          </div>
        </div>
        <p v-else class="prt__empty">Sponsor içerik bulunamadı.</p>
      </template>

      <template v-else>
        <p class="prt__hint">Bölgenize atanmış reklam spotları.</p>
        <div v-if="adsList.length" class="prt__media ui-card">
          <div v-for="a in adsList" :key="a.id" class="prt__media-row">
            <span class="prt__media-title">{{ a.sponsor_name || '(adsız reklam)' }}</span>
            <span class="prt__media-meta">
              {{ a.content_type }}
              <template v-if="a.is_global"> · Ulusal</template>
              <template v-else-if="a.region_name"> · {{ a.region_name }}</template>
            </span>
            <a class="prt__dl-fmt" :href="`/api/v1/media-stream/sponsor/${a.id}`" target="_blank" rel="noopener">Dinle</a>
          </div>
        </div>
        <p v-else class="prt__empty">Reklam içeriği bulunamadı.</p>
      </template>
    </section>

    <!-- ACTIVITY -->
    <section v-else-if="tab === 'activity'" class="prt__section">
      <p class="prt__hint">Son 100 aktivite kaydı</p>
      <div v-if="activity.length" class="prt__activity ui-card">
        <div v-for="a in activity" :key="a.id" class="prt__act">
          <span class="prt__act-time">{{ fmtTime(a.created_at) }}</span>
          <span class="prt__act-action">{{ a.action }}</span>
          <span class="prt__act-actor">{{ a.actor_username }}</span>
        </div>
      </div>
      <p v-else class="prt__empty">Henüz aktivite yok.</p>
    </section>

    <!-- SUPPORT -->
    <section v-else-if="tab === 'support'" class="prt__section">
      <div class="prt__support-head">
        <p class="prt__hint" style="margin: 0">
          Teknik destek, yayın sorunu, reklam/haber sorunu veya genel talep oluşturun.
        </p>
        <button type="button" class="prt__primary" @click="showNewTicket = !showNewTicket">
          {{ showNewTicket ? 'Vazgeç' : '+ Yeni Talep' }}
        </button>
      </div>

      <div v-if="showNewTicket" class="prt__new-ticket ui-card">
        <label>
          <span>Kategori</span>
          <select v-model="newTicket.category" class="prt__input">
            <option v-for="o in categoryOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </label>
        <label>
          <span>Konu</span>
          <input v-model="newTicket.subject" class="prt__input" maxlength="255" placeholder="Kısa başlık">
        </label>
        <label>
          <span>Açıklama</span>
          <textarea
            v-model="newTicket.body"
            class="prt__input prt__textarea"
            rows="5"
            placeholder="Lütfen sorunu detaylı anlatın"
          />
        </label>
        <button
          type="button"
          class="prt__primary"
          :disabled="submittingTicket"
          @click="submitNewTicket"
        >{{ submittingTicket ? 'Gönderiliyor…' : 'Talebi Gönder' }}</button>
      </div>

      <div v-if="tickets.length" class="prt__tickets ui-card">
        <div v-for="t in tickets" :key="t.id" class="prt__ticket" @click="openTicket(t.id)">
          <span class="prt__ticket-cat">{{ CATEGORY_LABELS[t.category] }}</span>
          <span class="prt__ticket-sub">{{ t.subject }}</span>
          <span class="prt__chip" :class="`is-${t.status}`">{{ STATUS_LABELS[t.status] }}</span>
          <span class="prt__ticket-time">{{ fmtTime(t.created_at) }}</span>
        </div>
      </div>
      <p v-else-if="!showNewTicket" class="prt__empty">Henüz destek talebiniz yok.</p>

      <!-- Open ticket thread -->
      <div v-if="openTicketId" class="prt__thread ui-card">
        <h3>Yazışmalar</h3>
        <div v-if="openTicketMessages.length" class="prt__messages">
          <div
            v-for="m in openTicketMessages"
            :key="m.id"
            class="prt__msg"
            :class="m.author_type === 'admin' ? 'is-admin' : 'is-partner'"
          >
            <span class="prt__msg-from">{{ m.author_type === 'admin' ? '🛠 Destek' : '📻 Siz' }}</span>
            <p>{{ m.body }}</p>
            <small>{{ fmtTime(m.created_at) }}</small>
          </div>
        </div>
        <p v-else class="prt__empty">Bu talepte henüz mesaj yok.</p>
        <div class="prt__reply">
          <textarea
            v-model="replyBody"
            class="prt__input prt__textarea"
            rows="3"
            placeholder="Yanıt yaz…"
          />
          <button type="button" class="prt__primary" @click="sendReply">Gönder</button>
        </div>
      </div>
    </section>

    <!-- API KEYS -->
    <section v-else-if="tab === 'apikeys'" class="prt__section">
      <p class="prt__hint">
        Programatik erişim için API anahtarları. Anahtarı sunucu-tarafı entegrasyonunuzda
        <code>X-API-Key</code> başlığı olarak gönderin. Anahtar yalnızca oluşturma anında
        bir kez gösterilir; kaybederseniz iptal edip yenisini üretin.
      </p>

      <div v-if="oneTimeApiKey" class="prt__creds-once ui-card">
        <p class="prt__warn">⚠ Bu anahtar yalnızca <strong>bir kez</strong> gösterilecek:</p>
        <code class="prt__key">{{ oneTimeApiKey }}</code>
        <button type="button" class="prt__primary" @click="clearOneTimeApiKey">Anladım, kapat</button>
      </div>

      <div class="prt__new-key ui-card">
        <label>
          <span>Anahtar Adı</span>
          <input
            v-model="newApiKeyName"
            class="prt__input"
            placeholder="Örn. Yayın Otomasyonu"
            maxlength="120"
          >
        </label>
        <button type="button" class="prt__primary" @click="issueApiKey">+ Anahtar Oluştur</button>
      </div>

      <div v-if="apiKeys.length" class="prt__keys ui-card">
        <div v-for="k in apiKeys" :key="k.id" class="prt__key-row">
          <div class="prt__key-info">
            <strong>{{ k.name }}</strong>
            <code>{{ k.key_prefix }}…</code>
            <small>
              Oluşturma {{ fmtTime(k.created_at) }}
              <template v-if="k.last_used_at"> · Son kullanım {{ fmtTime(k.last_used_at) }}</template>
              <template v-if="k.last_used_ip"> ({{ k.last_used_ip }})</template>
            </small>
          </div>
          <button type="button" class="prt__revoke" @click="revokeApiKey(k.id)">İptal</button>
        </div>
      </div>
      <p v-else class="prt__empty">Aktif API anahtarınız yok.</p>
    </section>

    <!-- SECURITY (MFA + password) -->
    <section v-else class="prt__section">
      <p class="prt__hint">
        Hesap güvenliği: iki faktörlü kimlik doğrulama (TOTP) ve şifre değişimi.
      </p>

      <div class="prt__new-key ui-card" style="flex-direction: column; align-items: stretch">
        <h3 style="margin: 0; font-size: 14px; font-weight: 800; color: var(--c-text)">
          🔐 İki Faktörlü Kimlik Doğrulama (MFA)
          <span v-if="mfaStatus?.enabled" class="prt__chip is-running" style="margin-left: 8px">Aktif</span>
          <span v-else class="prt__chip is-draft" style="margin-left: 8px">Kapalı</span>
        </h3>

        <template v-if="!mfaStatus?.enabled">
          <p class="prt__hint">
            Google Authenticator / Authy gibi bir TOTP uygulamasıyla telefonunuza bağlayın.
          </p>
          <button
            v-if="!mfaSetupResult"
            type="button"
            class="prt__primary"
            :disabled="mfaBusy"
            @click="startMfaSetup"
          >+ MFA Kur</button>
          <template v-else>
            <p class="prt__hint">
              <strong>Manuel anahtar:</strong>
              <code style="user-select: all">{{ mfaSetupResult.secret }}</code>
            </p>
            <p class="prt__hint">
              <strong>otpauth URI:</strong>
              <code style="user-select: all">{{ mfaSetupResult.otpauth_uri }}</code>
            </p>
            <label>
              <span>Uygulamanızın gösterdiği 6 haneli kodu girin</span>
              <input
                v-model="mfaCode"
                class="prt__input"
                inputmode="numeric"
                maxlength="6"
                placeholder="123456"
              >
            </label>
            <button type="button" class="prt__primary" :disabled="mfaBusy" @click="confirmMfa">
              MFA'yı Etkinleştir
            </button>
          </template>
        </template>

        <template v-else>
          <p class="prt__hint">
            MFA aktif. Kapatmak için uygulamanızın anlık kodunu girin.
          </p>
          <label>
            <span>Anlık MFA Kodu</span>
            <input
              v-model="mfaCode"
              class="prt__input"
              inputmode="numeric"
              maxlength="6"
              placeholder="123456"
            >
          </label>
          <button type="button" class="prt__revoke" :disabled="mfaBusy" @click="turnOffMfa">
            MFA'yı Kapat
          </button>
        </template>

        <div v-if="mfaRecoveryCodes.length" class="prt__creds-once">
          <p class="prt__warn">
            ⚠ Kurtarma kodları yalnızca bir kez gösterilir. Güvenli bir yere kaydedin.
          </p>
          <code v-for="c in mfaRecoveryCodes" :key="c" class="prt__key">{{ c }}</code>
        </div>
      </div>

      <div class="prt__new-key ui-card" style="flex-direction: column; align-items: stretch">
        <h3 style="margin: 0; font-size: 14px; font-weight: 800; color: var(--c-text)">
          🔑 Şifre Değiştir
        </h3>
        <label>
          <span>Mevcut Şifre</span>
          <input v-model="pwCurrent" class="prt__input" type="password" autocomplete="current-password">
        </label>
        <label>
          <span>Yeni Şifre</span>
          <input v-model="pwNew" class="prt__input" type="password" autocomplete="new-password">
        </label>
        <button type="button" class="prt__primary" :disabled="pwBusy" @click="submitPasswordChange">
          Şifreyi Güncelle
        </button>
      </div>
    </section>

    <p v-if="loading" class="prt__loading">Yükleniyor…</p>
  </div>
</template>

<style scoped>
/* Faz PAGE-FIT: viewport-fit. Portal AppShell DIŞINDA (login sonrası
   station_user için bare layout) — 100vh + iç scroll kullanır. */
.prt {
  height: 100dvh;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow: hidden;
  box-sizing: border-box;
  max-width: 1180px;
  margin: 0 auto;
}

/* ---- Profile card ---- */
.prt__card {
  padding: 16px;
}
.prt__id {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.prt__logo {
  flex: 0 0 64px;
  width: 64px;
  height: 64px;
  border-radius: 14px;
  overflow: hidden;
  display: grid;
  place-items: center;
  background: var(--c-surface-2);
  border: 1px solid var(--c-line);
}
.prt__logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.prt__logo--ph {
  font-size: 28px;
}
.prt__id-text {
  flex: 1;
  min-width: 0;
}
.prt__id-text h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.prt__freq {
  font-style: normal;
  padding: 1px 8px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
  background: rgba(225, 29, 72, 0.16);
  color: var(--c-brand);
}
.prt__id-text p {
  margin: 3px 0 0;
  font-size: 13px;
  color: var(--c-text-3);
}
.prt__logout {
  border: 1px solid var(--c-line);
  background: transparent;
  color: var(--c-text-2);
  padding: 6px 14px;
  border-radius: 9px;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
}
.prt__logout:hover {
  border-color: var(--c-bad);
  color: var(--c-bad);
}
.prt__meta {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--c-line);
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  font-size: 12px;
  color: var(--c-text-2);
}
.prt__meta b {
  color: var(--c-text-3);
  font-weight: 700;
  margin-right: 5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-size: 10px;
}
.prt__status {
  margin-left: auto;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 800;
}
.prt__status.is-on {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.14);
}
.prt__status.is-off {
  color: var(--c-bad);
  background: rgba(251, 113, 133, 0.14);
}

/* ---- Tabs ---- */
.prt__tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.prt__tab {
  flex: 0 0 auto;
  padding: 9px 16px;
  border: 1px solid var(--c-line);
  background: var(--c-surface);
  color: var(--c-text-2);
  border-radius: 10px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.prt__tab.is-active {
  background: var(--c-brand);
  border-color: var(--c-brand);
  color: #fff;
}

.prt__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.prt__hint {
  margin: 0;
  font-size: 12px;
  color: var(--c-text-3);
}
.prt__empty {
  padding: 32px 16px;
  text-align: center;
  color: var(--c-text-3);
  background: var(--c-surface);
  border: 1px dashed var(--c-line);
  border-radius: 14px;
}

/* ---- Links ---- */
.prt__links {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.prt__link {
  padding: 14px;
}
.prt__link header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}
.prt__link header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
  color: var(--c-text);
}
.prt__purpose-icon {
  font-size: 22px;
}
.prt__purpose-tag {
  margin-left: auto;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(96, 165, 250, 0.14);
  color: var(--c-info);
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.prt__url {
  display: grid;
  grid-template-columns: 44px 1fr auto;
  gap: 8px;
  align-items: center;
  padding: 6px 0;
  border-top: 1px solid var(--c-line);
}
.prt__url:first-of-type {
  border-top: none;
}
.prt__fmt {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--c-text-3);
}
.prt__url code {
  font-size: 11px;
  font-family: 'Fira Code', 'Consolas', monospace;
  color: var(--c-text-2);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prt__copy {
  border: 1px solid var(--c-info);
  background: transparent;
  color: var(--c-info);
  padding: 4px 10px;
  border-radius: 7px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.prt__copy:hover {
  background: rgba(96, 165, 250, 0.1);
}
.prt__copy.is-ok {
  background: var(--c-ok);
  border-color: var(--c-ok);
  color: #042;
}

/* ---- Plans ---- */
.prt__plans {
  padding: 6px 12px;
}
.prt__plan {
  display: grid;
  grid-template-columns: 50px 1fr 70px 80px;
  gap: 10px;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid var(--c-line);
  font-size: 13px;
}
.prt__plan:last-child {
  border-bottom: none;
}
.prt__plan-slot {
  font-weight: 800;
  color: var(--c-info);
  font-variant-numeric: tabular-nums;
}
.prt__plan-title {
  color: var(--c-text);
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prt__plan-part {
  font-size: 11px;
  color: var(--c-text-3);
}
.prt__chip {
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 800;
  text-align: center;
}
.prt__chip.is-published,
.prt__chip.is-running {
  background: rgba(52, 211, 153, 0.14);
  color: var(--c-ok);
}
.prt__chip.is-draft,
.prt__chip.is-paused {
  background: rgba(251, 191, 36, 0.14);
  color: var(--c-warn);
}
.prt__chip.is-archived {
  background: rgba(148, 163, 184, 0.14);
  color: var(--c-text-3);
}

/* ---- Media / activity rows ---- */
.prt__media {
  padding: 6px 12px;
}
.prt__media-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 10px;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid var(--c-line);
  font-size: 13px;
}
.prt__media-row:last-child {
  border-bottom: none;
}
.prt__media-title {
  color: var(--c-text);
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prt__media-meta {
  font-size: 11px;
  color: var(--c-text-3);
}
.prt__subtabs {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.prt__subtab {
  padding: 6px 10px;
  border: 1px solid var(--c-line);
  background: transparent;
  color: var(--c-text-3);
  border-radius: 8px;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}
.prt__subtab.is-active {
  background: var(--c-info);
  border-color: var(--c-info);
  color: #fff;
}

.prt__formats {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}
.prt__dl-fmt {
  padding: 4px 8px;
  border-radius: 6px;
  background: rgba(225, 29, 72, 0.12);
  color: var(--c-brand);
  font-size: 10px;
  font-weight: 800;
  text-decoration: none;
}
.prt__dl-fmt:hover {
  background: var(--c-brand);
  color: #fff;
}

.prt__activity {
  padding: 6px 12px;
}
.prt__act {
  display: grid;
  grid-template-columns: 140px 1fr auto;
  gap: 10px;
  align-items: center;
  padding: 9px 0;
  border-bottom: 1px solid var(--c-line);
  font-size: 12px;
}
.prt__act:last-child {
  border-bottom: none;
}
.prt__act-time {
  color: var(--c-text-3);
  font-variant-numeric: tabular-nums;
}
.prt__act-action {
  color: var(--c-text);
  font-weight: 700;
}
.prt__act-actor {
  font-size: 11px;
  color: var(--c-text-3);
}

.prt__loading {
  text-align: center;
  color: var(--c-text-3);
}

/* ---- Support ---- */
.prt__support-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.prt__primary {
  padding: 8px 16px;
  border: none;
  border-radius: 9px;
  background: var(--c-brand);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.prt__primary:disabled {
  opacity: 0.6;
  cursor: progress;
}
.prt__new-ticket {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.prt__new-ticket label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  color: var(--c-text-2);
}
.prt__input {
  padding: 8px 10px;
  border: 1px solid var(--c-line);
  border-radius: 8px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 13px;
}
.prt__textarea {
  resize: vertical;
  font-family: inherit;
}
.prt__tickets {
  padding: 6px 12px;
}
.prt__ticket {
  display: grid;
  grid-template-columns: 110px 1fr 90px 130px;
  gap: 10px;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid var(--c-line);
  cursor: pointer;
  font-size: 13px;
}
.prt__ticket:last-child {
  border-bottom: none;
}
.prt__ticket:hover {
  background: rgba(96, 165, 250, 0.04);
}
.prt__ticket-cat {
  font-weight: 700;
  color: var(--c-info);
  font-size: 11px;
}
.prt__ticket-sub {
  color: var(--c-text);
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prt__ticket-time {
  font-size: 11px;
  color: var(--c-text-3);
}
.prt__chip.is-open {
  background: rgba(96, 165, 250, 0.14);
  color: var(--c-info);
}
.prt__chip.is-in_progress {
  background: rgba(251, 191, 36, 0.14);
  color: var(--c-warn);
}
.prt__chip.is-resolved,
.prt__chip.is-closed {
  background: rgba(52, 211, 153, 0.14);
  color: var(--c-ok);
}
.prt__thread {
  padding: 14px;
  margin-top: 10px;
}
.prt__thread h3 {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 800;
  color: var(--c-text);
}
.prt__messages {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}
.prt__msg {
  padding: 9px 12px;
  border-radius: 10px;
  background: var(--c-surface-2);
  border-left: 3px solid var(--c-line-strong);
}
.prt__msg.is-admin {
  border-left-color: var(--c-info);
}
.prt__msg.is-partner {
  border-left-color: var(--c-brand);
}
.prt__msg-from {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--c-text-3);
}
.prt__msg p {
  margin: 4px 0 6px;
  font-size: 13px;
  color: var(--c-text);
}
.prt__msg small {
  color: var(--c-text-3);
  font-size: 11px;
}
.prt__reply {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.prt__reply .prt__primary {
  align-self: flex-end;
}
@media (max-width: 480px) {
  .prt__ticket {
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .prt__key-row {
    grid-template-columns: 1fr;
    gap: 4px;
  }
}

/* ---- API keys ---- */
.prt__creds-once {
  padding: 14px;
  border: 1px solid rgba(251, 191, 36, 0.32);
  background: rgba(251, 191, 36, 0.06);
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.prt__warn {
  margin: 0;
  font-size: 12px;
  color: var(--c-warn);
}
.prt__key {
  display: block;
  padding: 10px 12px;
  font-family: 'Fira Code', 'Consolas', monospace;
  font-size: 13px;
  color: var(--c-text);
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  border-radius: 8px;
  word-break: break-all;
  user-select: all;
}
.prt__new-key {
  padding: 14px;
  display: flex;
  align-items: end;
  gap: 10px;
  flex-wrap: wrap;
}
.prt__new-key label {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: var(--c-text-2);
}
.prt__keys {
  padding: 6px 12px;
}
.prt__key-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid var(--c-line);
  align-items: center;
}
.prt__key-row:last-child {
  border-bottom: none;
}
.prt__key-info strong {
  color: var(--c-text);
  margin-right: 8px;
}
.prt__key-info code {
  font-family: 'Fira Code', monospace;
  font-size: 12px;
  color: var(--c-info);
}
.prt__key-info small {
  display: block;
  margin-top: 2px;
  color: var(--c-text-3);
  font-size: 11px;
}
.prt__revoke {
  padding: 6px 12px;
  border: 1px solid var(--c-bad);
  background: transparent;
  color: var(--c-bad);
  font-size: 12px;
  font-weight: 700;
  border-radius: 7px;
  cursor: pointer;
}
.prt__revoke:hover {
  background: rgba(251, 113, 133, 0.1);
}

/* ---- Mobile (≤390px) ---- */
@media (max-width: 480px) {
  .prt {
    padding: 12px;
  }
  .prt__id-text h1 {
    font-size: 17px;
  }
  .prt__meta {
    font-size: 11px;
    gap: 10px;
  }
  .prt__status {
    margin-left: 0;
  }
  .prt__url {
    grid-template-columns: 40px 1fr;
    grid-template-rows: auto auto;
  }
  .prt__copy {
    grid-column: 2;
    justify-self: end;
  }
  .prt__plan,
  .prt__act {
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .prt__plan-part {
    display: none;
  }
}

/* ---- Desktop ---- */
@media (min-width: 900px) {
  .prt__links {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
