<script lang="ts" setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { Button, Input, message } from 'ant-design-vue';

import { login, verifyMfa } from '#/api/modules/auth';

const route = useRoute();
const router = useRouter();

const username = ref('admin');
const password = ref('');
const loading = ref(false);

// MFA second step
const mfaToken = ref<string | null>(null);
const mfaCode = ref('');

function redirectAfterLogin() {
  const redirect = (route.query.redirect as string) || '/radio-platform/operations';
  return router.replace(redirect);
}

async function verifyCode() {
  if (!mfaToken.value || !mfaCode.value.trim()) {
    message.warning('Doğrulama kodu gerekli.');
    return;
  }
  loading.value = true;
  try {
    const response = await verifyMfa(mfaToken.value, mfaCode.value.trim());
    if (response?.code === 0 && response.result && !('mfa_required' in response.result)) {
      message.success('Giriş başarılı.');
      await redirectAfterLogin();
      return;
    }
    message.error(response?.message || 'Doğrulama kodu hatalı.');
  } catch {
    message.error('Doğrulama kodu hatalı veya süresi doldu.');
  } finally {
    loading.value = false;
  }
}

function cancelMfa() {
  mfaToken.value = null;
  mfaCode.value = '';
  password.value = '';
}

async function handleSubmit() {
  if (!username.value.trim() || !password.value) {
    message.warning('Kullanıcı adı ve şifre gerekli.');
    return;
  }

  loading.value = true;
  try {
    const response = await login({ username: username.value.trim(), password: password.value });
    if (response?.code === 0 && response.result && 'mfa_required' in response.result) {
      // Two-factor enabled — switch to the code entry step.
      mfaToken.value = response.result.mfa_token;
      mfaCode.value = '';
      message.info('İki adımlı doğrulama gerekli.');
      return;
    }
    if (response?.code === 0 && response.result) {
      message.success('Giriş başarılı.');
      await redirectAfterLogin();
      return;
    }
    message.error(response?.message || 'Kullanıcı adı veya şifre hatalı.');
  } catch (error) {
    console.error(error);
    // The request layer throws on non-2xx (e.g. 401) with the JSON body as the
    // message; surface the backend's real reason instead of a generic one.
    let reason = 'Giriş başarısız. Sunucuya ulaşılamıyor olabilir.';
    if (error instanceof Error && error.message) {
      try {
        const parsed = JSON.parse(error.message) as { error?: string; message?: string };
        if (parsed?.message || parsed?.error) {
          reason = String(parsed.message ?? parsed.error);
        }
      } catch {
        // message was not JSON (network error) — keep the generic reason.
      }
    }
    message.error(reason);
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-brand">
        <!-- REBRAND: wide-format wordmark logo (1554×519). Logo zaten
             "Ad Cast Pro" yazısını içerdiği için ayrı h1 duplicate olur —
             a11y için heading'i visually-hidden olarak koruyoruz. -->
        <img src="/adcastpro-logo.png" alt="AdCast Pro" class="login-logo" />
        <h1 class="visually-hidden">AdCast Pro</h1>
        <p class="login-tagline">Bölgesel Radyo Yönetim Paneli</p>
      </div>

      <form v-if="!mfaToken" class="login-form" @submit.prevent="handleSubmit">
        <label>
          <span>Kullanıcı Adı</span>
          <Input v-model:value="username" size="large" placeholder="admin" autocomplete="username" />
        </label>
        <label>
          <span>Şifre</span>
          <Input
            v-model:value="password"
            type="password"
            size="large"
            placeholder="••••••"
            autocomplete="current-password"
            @press-enter="handleSubmit"
          />
        </label>
        <Button type="primary" size="large" block :loading="loading" html-type="submit">
          Giriş Yap
        </Button>
      </form>

      <form v-else class="login-form" @submit.prevent="verifyCode">
        <label>
          <span>Doğrulama Kodu</span>
          <Input
            v-model:value="mfaCode"
            size="large"
            placeholder="6 haneli kod"
            inputmode="numeric"
            autocomplete="one-time-code"
            @press-enter="verifyCode"
          />
        </label>
        <Button type="primary" size="large" block :loading="loading" html-type="submit">
          Doğrula
        </Button>
        <Button size="large" block @click="cancelMfa">Geri</Button>
        <p class="login-hint">
          Authenticator uygulamanızdaki 6 haneli kodu girin. Kurtarma kodunuzu da
          kullanabilirsiniz.
        </p>
      </form>

      <p v-if="!mfaToken" class="login-hint">Oturum HttpOnly çerez ile güvenli tutulur.</p>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
  background:
    radial-gradient(circle at 15% 15%, rgba(225, 29, 72, 0.18), transparent 30%),
    radial-gradient(circle at 85% 20%, rgba(59, 130, 246, 0.16), transparent 28%),
    linear-gradient(180deg, #0b1220, #0a0f1c);
}

.login-card {
  width: min(92vw, 440px);
  display: grid;
  gap: 22px;
  padding: 32px;
  border-radius: 22px;
  background: rgba(15, 23, 42, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.18);
  box-shadow: 0 30px 70px rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(14px);
}

.login-brand {
  /* REBRAND: wordmark logo center-aligned, alt-text altta */
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding-top: 4px;
}

.login-logo {
  /* Wide wordmark — doğal aspect ratio (~3:1) ile yükseklik bazlı,
     card içine sığsın diye max-width %100. */
  display: block;
  height: 88px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  filter: drop-shadow(0 6px 18px rgba(225, 29, 72, 0.35));
}

.login-tagline {
  margin: 0;
  color: rgba(226, 232, 240, 0.75);
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.02em;
  text-align: center;
}

/* a11y: heading sayfa hiyerarşisi için var, ama görsel olarak logo zaten
   "AdCast Pro" gösteriyor — screen reader'a sunup ekranda gizliyoruz. */
.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.login-form {
  display: grid;
  gap: 16px;
}

.login-form label {
  display: grid;
  gap: 6px;
}

.login-form label span {
  color: rgba(226, 232, 240, 0.82);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.login-hint {
  margin: 0;
  text-align: center;
  color: rgba(148, 163, 184, 0.8);
  font-size: 12px;
}
</style>
