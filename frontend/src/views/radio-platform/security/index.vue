<script lang="ts" setup>
import { onMounted, ref } from 'vue';

import { Button, Input, message } from 'ant-design-vue';

import dayjs from 'dayjs';

import {
  changePassword,
  disableMfa,
  enableMfa,
  getMfaStatus,
  getSessions,
  revokeOtherSessions,
  setupMfa,
  type MfaStatus,
  type SessionInfo,
} from '#/api/modules/auth';
import { extractApiError } from '#/utils/api-error';
import ConnectionBanner from '#/components/ui/ConnectionBanner.vue';

const loading = ref(true);
const busy = ref(false);
const status = ref<MfaStatus>({ enabled: false, pending: false });
// Faz H1-5: backend dayanıklılığı
const healthy = ref(true);

// enrolment state
const stage = ref<'idle' | 'enrolling' | 'recovery'>('idle');
const secret = ref('');
const otpauthUri = ref('');
const qrDataUrl = ref('');
const enrollCode = ref('');
const recoveryCodes = ref<string[]>([]);

// disable state
const disableCode = ref('');

const secretGrouped = () => secret.value.replace(/(.{4})/g, '$1 ').trim();

async function refresh() {
  loading.value = true;
  try {
    const res = await getMfaStatus();
    status.value = res?.result ?? { enabled: false, pending: false };
    healthy.value = !!res?.result;
  } catch (e) {
    healthy.value = false;
    message.warning(extractApiError(e) ?? 'MFA durumu alınamadı.');
  } finally {
    loading.value = false;
  }
}

async function renderQr(uri: string) {
  try {
    // Lazy-load qrcode so it never enters the main bundle.
    const QR = await import('qrcode');
    qrDataUrl.value = await QR.toDataURL(uri, { margin: 1, width: 200 });
  } catch {
    qrDataUrl.value = '';
  }
}

async function startEnrol() {
  busy.value = true;
  try {
    const res = await setupMfa();
    secret.value = res.result.secret;
    otpauthUri.value = res.result.otpauth_uri;
    enrollCode.value = '';
    stage.value = 'enrolling';
    await renderQr(otpauthUri.value);
  } catch (error) {
    message.error(extractApiError(error) ?? 'MFA kurulumu başlatılamadı.');
  } finally {
    busy.value = false;
  }
}

async function confirmEnrol() {
  if (!enrollCode.value.trim()) {
    message.warning('Authenticator kodunu girin.');
    return;
  }
  busy.value = true;
  try {
    const res = await enableMfa(enrollCode.value.trim());
    recoveryCodes.value = res.result.recovery_codes ?? [];
    stage.value = 'recovery';
    message.success('İki adımlı doğrulama etkinleştirildi.');
    await refresh();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Kod doğrulanamadı.');
  } finally {
    busy.value = false;
  }
}

async function turnOff() {
  if (!disableCode.value.trim()) {
    message.warning('Doğrulama kodu gerekli.');
    return;
  }
  busy.value = true;
  try {
    await disableMfa(disableCode.value.trim());
    message.success('İki adımlı doğrulama kapatıldı.');
    disableCode.value = '';
    stage.value = 'idle';
    await refresh();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Kapatılamadı.');
  } finally {
    busy.value = false;
  }
}

function finishRecovery() {
  stage.value = 'idle';
  recoveryCodes.value = [];
  secret.value = '';
  otpauthUri.value = '';
  qrDataUrl.value = '';
}

// --- password change ------------------------------------------------------
const pw = ref({ current: '', next: '', confirm: '' });
const pwBusy = ref(false);
async function submitPassword() {
  if (pw.value.next.length < 6) {
    message.warning('Yeni şifre en az 6 karakter olmalı.');
    return;
  }
  if (pw.value.next !== pw.value.confirm) {
    message.warning('Yeni şifreler eşleşmiyor.');
    return;
  }
  pwBusy.value = true;
  try {
    await changePassword(pw.value.current, pw.value.next);
    message.success('Şifre değiştirildi. Diğer oturumlar kapatıldı.');
    pw.value = { current: '', next: '', confirm: '' };
    await loadSessions();
  } catch (error) {
    message.error(extractApiError(error) ?? 'Şifre değiştirilemedi.');
  } finally {
    pwBusy.value = false;
  }
}

// --- sessions -------------------------------------------------------------
const sessions = ref<SessionInfo[]>([]);
const sessBusy = ref(false);
function fmt(ts: string) {
  return dayjs(ts).format('DD.MM.YYYY HH:mm');
}
async function loadSessions() {
  try {
    const res = await getSessions();
    sessions.value = Array.isArray(res?.result) ? res.result : [];
  } catch (e) {
    sessions.value = [];
    message.warning(extractApiError(e) ?? 'Oturum listesi alınamadı.');
  }
}
async function revokeOthers() {
  sessBusy.value = true;
  try {
    const res = await revokeOtherSessions();
    message.success(`${res.result.revoked} oturum kapatıldı.`);
    await loadSessions();
  } catch (error) {
    message.error(extractApiError(error) ?? 'İşlem başarısız.');
  } finally {
    sessBusy.value = false;
  }
}

async function copyRecovery() {
  try {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
    message.success('Kurtarma kodları panoya kopyalandı.');
  } catch {
    message.warning('Kopyalanamadı, kodları elle kaydedin.');
  }
}

onMounted(async () => {
  await refresh();
  await loadSessions();
});

async function retryAll(): Promise<void> {
  await refresh();
  await loadSessions();
}
</script>

<template>
  <div class="sec">
    <ConnectionBanner
      v-if="!healthy && !loading"
      message="Güvenlik servisi erişilemiyor"
      detail="MFA durumu ve oturum bilgileri yüklenemedi. Backend / Docker durumunu kontrol edin."
      :busy="loading || busy"
      @retry="retryAll()"
    />
    <header class="sec__head">
      <h1>Güvenlik</h1>
      <p class="sec__sub">Hesabınızı iki adımlı doğrulama (2FA) ile koruyun</p>
    </header>

    <article class="ui-card sec__card">
      <div class="sec__row">
        <div class="sec__icon" :class="status.enabled ? 'is-on' : 'is-off'">🔐</div>
        <div class="sec__info">
          <h2>İki Adımlı Doğrulama (TOTP)</h2>
          <p>
            Google Authenticator, Authy veya 1Password gibi uygulamalarla zaman tabanlı
            tek kullanımlık kod.
          </p>
        </div>
        <span class="sec__badge" :class="status.enabled ? 'is-on' : 'is-off'">
          {{ status.enabled ? 'Aktif' : 'Kapalı' }}
        </span>
      </div>

      <!-- DISABLED → offer enrolment -->
      <div v-if="!status.enabled && stage === 'idle'" class="sec__action">
        <Button type="primary" :loading="busy" @click="startEnrol">Kurulumu Başlat</Button>
      </div>

      <!-- ENROLLING -->
      <div v-if="stage === 'enrolling'" class="sec__enrol">
        <p class="sec__step">1. Authenticator uygulamanızla QR kodu tarayın ya da anahtarı elle girin:</p>
        <div class="sec__qr-wrap">
          <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR kodu" class="sec__qr">
          <div class="sec__manual">
            <span class="sec__manual-label">Kurulum anahtarı</span>
            <code class="sec__secret">{{ secretGrouped() }}</code>
          </div>
        </div>
        <p class="sec__step">2. Uygulamadaki 6 haneli kodu girin:</p>
        <div class="sec__confirm">
          <Input
            v-model:value="enrollCode"
            placeholder="000000"
            inputmode="numeric"
            class="sec__code-input"
            @press-enter="confirmEnrol"
          />
          <Button type="primary" :loading="busy" @click="confirmEnrol">Etkinleştir</Button>
        </div>
      </div>

      <!-- RECOVERY CODES -->
      <div v-if="stage === 'recovery'" class="sec__recovery">
        <p class="sec__warn">
          ⚠️ Bu kurtarma kodlarını güvenli bir yere kaydedin. Telefonunuza erişiminizi
          kaybederseniz giriş yapmanın tek yolu bunlardır. <strong>Tekrar gösterilmez.</strong>
        </p>
        <ul class="sec__codes">
          <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
        </ul>
        <div class="sec__action">
          <Button @click="copyRecovery">Kopyala</Button>
          <Button type="primary" @click="finishRecovery">Kaydettim, Bitir</Button>
        </div>
      </div>

      <!-- ENABLED → offer disable -->
      <div v-if="status.enabled && stage !== 'recovery'" class="sec__disable">
        <p class="sec__step">Kapatmak için authenticator (veya kurtarma) kodunuzu girin:</p>
        <div class="sec__confirm">
          <Input
            v-model:value="disableCode"
            placeholder="000000"
            inputmode="numeric"
            class="sec__code-input"
            @press-enter="turnOff"
          />
          <Button danger :loading="busy" @click="turnOff">2FA'yı Kapat</Button>
        </div>
      </div>
    </article>

    <!-- Password change -->
    <article class="ui-card sec__card">
      <div class="sec__row">
        <div class="sec__icon">🔑</div>
        <div class="sec__info">
          <h2>Şifre Değiştir</h2>
          <p>Yeni şifre belirlediğinizde diğer tüm oturumlar otomatik kapatılır.</p>
        </div>
      </div>
      <div class="sec__pwform">
        <Input v-model:value="pw.current" type="password" placeholder="Mevcut şifre" />
        <Input v-model:value="pw.next" type="password" placeholder="Yeni şifre (min 6)" />
        <Input v-model:value="pw.confirm" type="password" placeholder="Yeni şifre (tekrar)" @press-enter="submitPassword" />
        <Button type="primary" :loading="pwBusy" @click="submitPassword">Şifreyi Güncelle</Button>
      </div>
    </article>

    <!-- Active sessions -->
    <article class="ui-card sec__card">
      <div class="sec__row">
        <div class="sec__icon">💻</div>
        <div class="sec__info">
          <h2>Aktif Oturumlar</h2>
          <p>{{ sessions.length }} açık oturum. Şüpheli erişimde diğerlerini kapatın.</p>
        </div>
        <Button :loading="sessBusy" :disabled="sessions.length < 2" @click="revokeOthers">
          Diğerlerini Kapat
        </Button>
      </div>
      <ul class="sec__sessions">
        <li v-for="s in sessions" :key="s.id">
          <span class="sec__sess-dot" :class="{ 'is-current': s.is_current }" />
          <span class="sec__sess-main">
            {{ s.is_current ? 'Bu oturum' : 'Oturum' }}
            <small>açılış {{ fmt(s.created_at) }} · bitiş {{ fmt(s.expires_at) }}</small>
          </span>
          <span v-if="s.is_current" class="sec__sess-badge">geçerli</span>
        </li>
      </ul>
    </article>
  </div>
</template>

<style scoped>
/* Faz PAGE-FIT: viewport-fit (security ekranı zaten kısa, scroll'a
   nadiren gerek olur). */
.sec {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-width: 720px;
  height: calc(100dvh - 72px);
  overflow-y: auto;
  box-sizing: border-box;
}
.sec__head h1 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: var(--t-h1);
  font-weight: 800;
  letter-spacing: -0.02em;
  color: var(--c-text);
}
.sec__sub {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.sec__card {
  padding: var(--sp-4);
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
}
.sec__row {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
}
.sec__icon {
  font-size: 26px;
  width: 48px;
  height: 48px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  background: rgba(148, 163, 184, 0.1);
}
.sec__icon.is-on {
  background: rgba(52, 211, 153, 0.14);
}
.sec__info {
  flex: 1;
}
.sec__info h2 {
  margin: 0;
  font-size: var(--t-h2);
  font-weight: 800;
  color: var(--c-text);
}
.sec__info p {
  margin: 3px 0 0;
  font-size: var(--t-sm);
  color: var(--c-text-3);
}
.sec__badge {
  padding: 4px 12px;
  border-radius: 999px;
  font-size: var(--t-xs);
  font-weight: 800;
}
.sec__badge.is-on {
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.14);
}
.sec__badge.is-off {
  color: var(--c-text-3);
  background: rgba(148, 163, 184, 0.12);
}

.sec__action {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.sec__enrol,
.sec__disable,
.sec__recovery {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-top: var(--sp-3);
  border-top: 1px solid var(--c-line);
}
.sec__step {
  margin: 0;
  font-size: var(--t-sm);
  color: var(--c-text-2);
  font-weight: 600;
}
.sec__qr-wrap {
  display: flex;
  gap: var(--sp-4);
  align-items: center;
  flex-wrap: wrap;
}
.sec__qr {
  width: 168px;
  height: 168px;
  border-radius: 12px;
  background: #fff;
  padding: 8px;
}
.sec__manual {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.sec__manual-label {
  font-size: var(--t-xs);
  font-weight: 700;
  color: var(--c-text-3);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.sec__secret {
  font-family: 'Plus Jakarta Sans', monospace;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: 0.12em;
  color: var(--c-text);
  background: rgba(148, 163, 184, 0.1);
  padding: 8px 12px;
  border-radius: 8px;
}
.sec__confirm {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}
.sec__code-input {
  max-width: 160px;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.2em;
}
.sec__warn {
  margin: 0;
  font-size: var(--t-sm);
  color: var(--c-warn);
  line-height: 1.5;
}
.sec__codes {
  list-style: none;
  margin: 0;
  padding: 14px;
  border-radius: 12px;
  background: rgba(148, 163, 184, 0.08);
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
}
.sec__codes li {
  font-family: monospace;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: var(--c-text);
}

.sec__pwform {
  display: grid;
  gap: 10px;
  max-width: 360px;
}

.sec__sessions {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}
.sec__sessions li {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 10px 0;
  border-top: 1px solid var(--c-line);
}
.sec__sessions li:first-child {
  border-top: none;
}
.sec__sess-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--c-text-3);
  flex-shrink: 0;
}
.sec__sess-dot.is-current {
  background: var(--c-ok);
  box-shadow: 0 0 8px var(--c-ok);
}
.sec__sess-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  font-size: var(--t-sm);
  color: var(--c-text);
  font-weight: 600;
}
.sec__sess-main small {
  font-size: var(--t-xs);
  color: var(--c-text-3);
  font-weight: 400;
}
.sec__sess-badge {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--c-ok);
  background: rgba(52, 211, 153, 0.12);
  padding: 2px 9px;
  border-radius: 999px;
}
</style>
