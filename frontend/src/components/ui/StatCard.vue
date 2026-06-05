<script lang="ts" setup>
interface Props {
  label: string;
  value: string | number;
  hint?: string;
  tone?: 'default' | 'ok' | 'warn' | 'bad' | 'info' | 'brand';
  icon?: string;
}

withDefaults(defineProps<Props>(), { tone: 'default', hint: '', icon: '' });

const ICONS: Record<string, string> = {
  radio: 'M12 13v8 M8 9a5 5 0 0 1 8 0 M5 6a9 9 0 0 1 14 0 M12 12a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  news: 'M4 5h13v14H4z M20 8v9a2 2 0 0 1-2 2 M7 8h7 M7 11h7 M7 14h5',
  megaphone: 'M4 10v4h3l7 4V6L7 10z M17 9a4 4 0 0 1 0 6',
  map: 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2z M9 4v14 M15 6v14',
  pulse: 'M3 12h4l2 6 4-14 2 8h6',
  clock: 'M12 7v5l3 2 M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
};
</script>

<template>
  <div class="stat" :class="`tone-${tone}`">
    <div class="stat__head">
      <span class="stat__label">{{ label }}</span>
      <svg v-if="icon && ICONS[icon]" class="stat__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path :d="ICONS[icon]" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </div>
    <strong class="stat__value">{{ value }}</strong>
    <span v-if="hint" class="stat__hint">{{ hint }}</span>
  </div>
</template>

<style scoped>
.stat {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: var(--sp-4);
  border-radius: var(--r-lg);
  background: var(--c-surface);
  border: 1px solid var(--c-line);
  box-shadow: var(--sh-1);
  overflow: hidden;
}

.stat::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: var(--accent, var(--c-text-3));
  opacity: 0.9;
}

.stat__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.stat__label {
  color: var(--c-text-2);
  font-size: var(--t-xs);
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.stat__icon {
  width: 18px;
  height: 18px;
  color: var(--accent, var(--c-text-3));
  opacity: 0.85;
  flex-shrink: 0;
}

.stat__value {
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: 26px;
  font-weight: 800;
  line-height: 1.05;
  letter-spacing: -0.02em;
  color: var(--c-text);
}

.stat__hint {
  font-size: var(--t-xs);
  color: var(--c-text-3);
}

.tone-default { --accent: #64748b; }
.tone-ok { --accent: var(--c-ok); }
.tone-warn { --accent: var(--c-warn); }
.tone-bad { --accent: var(--c-bad); }
.tone-info { --accent: var(--c-info); }
.tone-brand { --accent: var(--c-brand); }

.tone-ok .stat__value { color: var(--c-ok); }
.tone-warn .stat__value { color: var(--c-warn); }
.tone-bad .stat__value { color: var(--c-bad); }
.tone-info .stat__value { color: var(--c-info); }
.tone-brand .stat__value { color: var(--c-brand); }
</style>
