<script lang="ts" setup>
interface Props {
  compact?: boolean;
}

withDefaults(defineProps<Props>(), {
  compact: false,
});
</script>

<template>
  <div
    class="aircast-pro-logo"
    :class="{ 'is-compact': compact }"
    aria-label="Aircast Pro"
  >
    <div class="aircast-pro-logo-mark" aria-hidden="true">
      <svg viewBox="0 0 64 64" role="img" focusable="false">
        <defs>
          <linearGradient id="aircast-pro-gradient" x1="0%" x2="100%" y1="0%" y2="100%">
            <stop offset="0%" stop-color="#f8fafc" />
            <stop offset="52%" stop-color="#e11d48" />
            <stop offset="100%" stop-color="#10b981" />
          </linearGradient>
        </defs>
        <circle
          cx="32"
          cy="32"
          r="26"
          fill="rgba(248, 250, 252, 0.04)"
          stroke="url(#aircast-pro-gradient)"
          stroke-width="2.2"
        />
        <path
          d="M18 38c5.5-8.5 11.2-12.7 17.9-12.7 4.7 0 8.9 2 12.6 5.9 1.6 1.7 2.8 3.1 3.5 4.2"
          fill="none"
          stroke="#f8fafc"
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2.8"
        />
        <path
          d="M23 31.4c3.8-5 7.8-7.6 11.8-7.6 3.7 0 7 1.4 9.8 4.2"
          fill="none"
          stroke="#e11d48"
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2.2"
        />
        <path
          d="M27.8 24.8c2.3-2.4 4.8-3.6 7.5-3.6 2.4 0 4.7.8 6.8 2.4"
          fill="none"
          stroke="#10b981"
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
        />
        <circle cx="32" cy="42" r="3.6" fill="#f8fafc" />
      </svg>
    </div>

    <div v-if="!compact" class="aircast-pro-logo-copy">
      <strong>Aircast Pro</strong>
      <small>Haber Y?netim Paneli</small>
    </div>
  </div>
</template>

<style scoped>
.aircast-pro-logo {
  display: inline-flex;
  align-items: center;
  gap: 0.9rem;
  color: #f8fafc;
}

.aircast-pro-logo.is-compact {
  gap: 0.6rem;
}

.aircast-pro-logo-mark {
  display: grid;
  place-items: center;
  width: 3.5rem;
  height: 3.5rem;
  border-radius: 1rem;
  background:
    radial-gradient(circle at top, rgba(225, 29, 72, 0.28), transparent 52%),
    linear-gradient(180deg, rgba(18, 27, 46, 0.96), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(248, 250, 252, 0.08);
  box-shadow:
    0 18px 35px rgba(2, 6, 23, 0.38),
    0 0 24px rgba(255, 255, 255, 0.08);
}

.aircast-pro-logo-mark svg {
  width: 2.2rem;
  height: 2.2rem;
  overflow: visible;
}

.aircast-pro-logo-copy {
  display: flex;
  flex-direction: column;
  gap: 0.14rem;
  line-height: 1;
}

.aircast-pro-logo-copy strong {
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -0.03em;
}

.aircast-pro-logo-copy small {
  color: rgba(248, 250, 252, 0.72);
  font-size: 0.86rem;
  font-weight: 600;
}

@media (max-width: 768px) {
  .aircast-pro-logo-copy strong {
    font-size: 1rem;
  }

  .aircast-pro-logo-copy small {
    font-size: 0.78rem;
  }
}
</style>
