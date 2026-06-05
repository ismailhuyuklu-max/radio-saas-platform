<script lang="ts" setup>
import { onMounted, ref } from 'vue';

import { Button, Input, message } from 'ant-design-vue';

import {
  disableMfa,
  enableMfa,
  getMfaStatus,
  setupMfa,
  type MfaStatus,
} from '#/api/modules/auth';
import { extractApiError } from '#/utils/api-error';

const loading = ref(true);
const busy = ref(false);
const status = ref<MfaStatus>({ enabled: false, pending: false });

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
  } catch {
    // not fatal
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

async function copyRecovery() {
  try {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'));
    message.success('Kurtarma kodları panoya kopyalandı.');
  } catch {
    message.warning('Kopyalanamadı, kodları elle kaydedin.');
  }
}

onMounted(refresh);
</script>

<template>
  <div class="sec">
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
  </div>
</template>

<style scoped>
.sec {
  display: flex;
  flex-direction: column;
  gap: var(--sp-4);
  max-width: 720px;
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
</style>
