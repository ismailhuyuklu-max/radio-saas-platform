<script lang="ts" setup>
// REBRAND: Aircast Pro → AdCast Pro
// PNG wordmark logo + "Planla. Yayınla. Raporla." sloganı
// (frontend/public/adcastpro-logo.png — 1536×1024 ~3:2, 2.2 MB, transparent
// RGBA). Logo zaten "AdCast Pro" + slogan içerdiği için ek text duplicate
// olur — kaldırıldı. compact prop API uyumluluğu için korunuyor (boyut etkiler).

interface Props {
  /** Compact mode: küçük yükseklik (matrix gibi yoğun ekranlarda) */
  compact?: boolean;
  /** Logo height in pixels — overrides compact */
  size?: number;
}

const props = withDefaults(defineProps<Props>(), {
  compact: false,
  size: 0,
});

// size > 0 verilirse onu kullan; yoksa compact ise 48px, değilse 80px.
// Slogan'lı logo, 48px altında okunmaz.
const resolvedHeight = (): number => {
  if (props.size > 0) return props.size;
  return props.compact ? 48 : 80;
};
</script>

<template>
  <div class="adcast-pro-logo" :class="{ 'is-compact': compact }" aria-label="AdCast Pro">
    <img
      src="/adcastpro-logo.png"
      alt="AdCast Pro"
      class="adcast-pro-logo-mark"
      :style="{ height: `${resolvedHeight()}px` }"
      loading="eager"
      decoding="async"
      width="240"
      height="160"
    />
  </div>
</template>

<style scoped>
.adcast-pro-logo {
  display: inline-flex;
  align-items: center;
  color: #f8fafc;
}

.adcast-pro-logo-mark {
  /* Wide wordmark — yükseklik bazlı, genişlik aspect-ratio'ya göre auto.
     Transparent PNG, drop-shadow ile brand-red glow. */
  display: block;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  filter: drop-shadow(0 4px 14px rgba(225, 29, 72, 0.3));
}
</style>
