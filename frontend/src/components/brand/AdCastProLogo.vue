<script lang="ts" setup>
// REBRAND: Aircast Pro → AdCast Pro
// PNG gerçek logo (frontend/public/adcastpro-logo.png — 344 KB yüksek çözünürlük)
// Vite tree-shake yapamaz public dosyaları, ama nginx 30d immutable cache eder.

interface Props {
  compact?: boolean;
  /** Logo height in pixels (mark only — text auto-scales) */
  size?: number;
}

withDefaults(defineProps<Props>(), {
  compact: false,
  size: 56,
});
</script>

<template>
  <div
    class="adcast-pro-logo"
    :class="{ 'is-compact': compact }"
    aria-label="AdCast Pro"
  >
    <img
      src="/adcastpro-logo.png"
      alt="AdCast Pro"
      class="adcast-pro-logo-mark"
      :style="{ height: `${size}px`, width: 'auto' }"
      loading="eager"
      decoding="async"
      width="120"
      height="120"
    />

    <div v-if="!compact" class="adcast-pro-logo-copy">
      <strong>AdCast Pro</strong>
      <small>Yayın Yönetim Paneli</small>
    </div>
  </div>
</template>

<style scoped>
.adcast-pro-logo {
  display: inline-flex;
  align-items: center;
  gap: 0.7rem;
  color: #f8fafc;
}

.adcast-pro-logo.is-compact {
  gap: 0.5rem;
}

.adcast-pro-logo-mark {
  display: block;
  object-fit: contain;
  /* Soft glow — koyu temada PNG'nin etrafında ince bir aura */
  filter: drop-shadow(0 4px 12px rgba(225, 29, 72, 0.25));
}

.adcast-pro-logo-copy {
  display: flex;
  flex-direction: column;
  gap: 0.14rem;
  line-height: 1;
}

.adcast-pro-logo-copy strong {
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.adcast-pro-logo-copy small {
  color: rgba(248, 250, 252, 0.72);
  font-size: 0.78rem;
  font-weight: 600;
}

@media (max-width: 768px) {
  .adcast-pro-logo-copy strong {
    font-size: 0.95rem;
  }

  .adcast-pro-logo-copy small {
    font-size: 0.72rem;
  }
}
</style>

