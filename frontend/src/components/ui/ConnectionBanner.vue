<script lang="ts" setup>
/**
 * Backend down / unstable banner.
 *
 * NOC sayfasındaki kırmızı şerit pattern'ı tüm üst-seviye dashboards'ta
 * kullanılsın diye ortak bileşen. Frontend, backend HTML hata gövdesi
 * döndürdüğünde (NOC tipi sızıntı), API hatası attığında, veya beklenen
 * shape dönmediğinde bu banner'ı göstermeli.
 *
 * Props:
 *   - message:    çağıran bileşenin gösterdiği özel mesaj (zorunlu)
 *   - detail:     opsiyonel ek satır (ör. Docker Desktop kontrol önerisi)
 *   - busy:       'Tekrar Dene' tıklandıktan sonra true → buton disabled +
 *                 spinner
 * Emits:
 *   - retry:      kullanıcı 'Tekrar Dene' butonuna bastı
 */
defineProps<{
  message: string;
  detail?: string;
  busy?: boolean;
}>();
defineEmits<{ (e: 'retry'): void }>();
</script>

<template>
  <div class="cb">
    <span class="cb__dot" />
    <div class="cb__body">
      <strong>{{ message }}</strong>
      <span v-if="detail">{{ detail }}</span>
    </div>
    <button type="button" class="cb__btn" :disabled="busy" @click="$emit('retry')">
      {{ busy ? 'Deneniyor…' : '↻ Tekrar Dene' }}
    </button>
  </div>
</template>

<style scoped>
.cb {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
  border-radius: 12px;
  background: rgba(251, 113, 133, 0.08);
  border: 1px solid rgba(251, 113, 133, 0.32);
  color: var(--c-text);
  margin-bottom: 12px;
}
.cb__dot {
  width: 12px;
  height: 12px;
  border-radius: 999px;
  background: var(--c-bad);
  box-shadow: 0 0 10px var(--c-bad);
  animation: cb-pulse 1.2s ease-in-out infinite;
  flex-shrink: 0;
}
.cb__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.cb__body strong {
  font-size: 13px;
  font-weight: 800;
  color: var(--c-bad);
}
.cb__body span {
  font-size: 11px;
  color: var(--c-text-2);
  line-height: 1.4;
}
.cb__btn {
  border: 1px solid var(--c-bad);
  background: var(--c-bad);
  color: #fff;
  border-radius: 8px;
  padding: 7px 14px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
}
.cb__btn:disabled {
  opacity: 0.6;
  cursor: progress;
}
.cb__btn:hover:not(:disabled) { filter: brightness(1.08); }
@keyframes cb-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.55; transform: scale(0.85); }
}
</style>
