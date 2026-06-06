<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';

import { message } from 'ant-design-vue';

import { logout } from '#/api/modules/auth';
import {
  type PortalActivity,
  type PortalCard,
  type PortalLink,
  type PortalMediaItem,
  type PortalPlan,
  PURPOSE_ICONS,
  PURPOSE_LABELS,
  getPortalActivity,
  getPortalFeeds,
  getPortalLinks,
  getPortalMe,
  getPortalMedia,
} from '#/api/modules/portal';

type Tab = 'links' | 'feeds' | 'media' | 'activity';

const loading = ref(true);
const card = ref<PortalCard | null>(null);
const links = ref<PortalLink[]>([]);
const plans = ref<PortalPlan[]>([]);
const mediaItems = ref<PortalMediaItem[]>([]);
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
    const [me, ln, ff, mm, ac] = await Promise.all([
      getPortalMe(),
      getPortalLinks(),
      getPortalFeeds(),
      getPortalMedia(),
      getPortalActivity(),
    ]);
    card.value = me?.result ?? null;
    links.value = ln?.result?.links ?? [];
    plans.value = ff?.result?.plans ?? [];
    mediaItems.value = mm?.result?.items ?? [];
    activity.value = ac?.result?.logs ?? [];
  } catch {
    message.error('Veriler yüklenemedi.');
  } finally {
    loading.value = false;
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

function downloadMediaUrl(item: PortalMediaItem): string {
  // Backend streams media via /media-stream/content/{id} for any authenticated
  // user; the partner reads it through the same cookie session.
  return `/api/v1/media-stream/content/${item.id}`;
}

async function signOut() {
  await logout();
  window.location.href = '/login';
}

onMounted(load);
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
      <p class="prt__hint">
        Bölgenize ait son içerikleri MP3 olarak indirebilirsiniz.
      </p>
      <div v-if="mediaItems.length" class="prt__media ui-card">
        <div v-for="m in mediaItems" :key="m.id" class="prt__media-row">
          <span class="prt__media-title">{{ m.title }}</span>
          <span class="prt__media-meta">{{ m.part_code }} · {{ fmtDuration(m.source_duration_ms) }}</span>
          <a class="prt__dl" :href="downloadMediaUrl(m)" target="_blank" rel="noopener">İndir</a>
        </div>
      </div>
      <p v-else class="prt__empty">Henüz indirilebilir içerik yok.</p>
    </section>

    <!-- ACTIVITY -->
    <section v-else class="prt__section">
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

    <p v-if="loading" class="prt__loading">Yükleniyor…</p>
  </div>
</template>

<style scoped>
.prt {
  min-height: 100vh;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
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
.prt__dl {
  padding: 5px 12px;
  border-radius: 7px;
  background: var(--c-brand);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
}
.prt__dl:hover {
  filter: brightness(1.08);
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
