<script lang="ts" setup>
// REBRAND: Aircast Pro → AdCast Pro
// PNG wordmark logo (frontend/public/adcastpro-logo.png — 1554×519, 344 KB,
// transparent RGBA). Logo zaten "Ad Cast Pro" yazısını içerdiği için yanına
// ayrı text duplicate olur — kaldırıldı. compact prop API uyumluluğu için
// korunuyor (yükseklik ölçeği etkiler).

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

// size > 0 verilirse onu kullan; yoksa compact ise 32px, değilse 56px
const resolvedHeight = (): number => {
  if (props.size > 0) return props.size;
  return props.compact ? 32 : 56;
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
      height="80"
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
