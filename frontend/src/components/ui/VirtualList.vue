<script lang="ts" setup generic="T">
import { computed, ref } from 'vue';

/**
 * Lightweight fixed-height virtual list. Renders only the rows visible in the
 * scroll viewport (plus a small overscan), so 1000+ campaigns / 81 il / 100+
 * radio rows stay smooth. Row height is fixed for O(1) offset math.
 */
const props = withDefaults(
  defineProps<{
    items: T[];
    rowHeight?: number;
    height?: number;
    overscan?: number;
    keyField?: string;
  }>(),
  { rowHeight: 52, height: 480, overscan: 6, keyField: 'id' },
);

const scrollTop = ref(0);

const total = computed(() => props.items.length);
const totalHeight = computed(() => total.value * props.rowHeight);

const startIndex = computed(() =>
  Math.max(0, Math.floor(scrollTop.value / props.rowHeight) - props.overscan),
);
const visibleCount = computed(
  () => Math.ceil(props.height / props.rowHeight) + props.overscan * 2,
);
const endIndex = computed(() => Math.min(total.value, startIndex.value + visibleCount.value));

const visible = computed(() =>
  props.items.slice(startIndex.value, endIndex.value).map((item, i) => ({
    item,
    index: startIndex.value + i,
  })),
);
const offsetY = computed(() => startIndex.value * props.rowHeight);

function onScroll(e: Event) {
  scrollTop.value = (e.target as HTMLElement).scrollTop;
}

function rowKey(item: T, index: number): string | number {
  const rec = item as Record<string, unknown>;
  const k = rec?.[props.keyField];
  return (k as string | number) ?? index;
}
</script>

<template>
  <div class="vlist" :style="{ height: height + 'px' }" @scroll="onScroll">
    <div class="vlist__spacer" :style="{ height: totalHeight + 'px' }">
      <div class="vlist__win" :style="{ transform: `translateY(${offsetY}px)` }">
        <div
          v-for="row in visible"
          :key="rowKey(row.item, row.index)"
          class="vlist__row"
          :style="{ height: rowHeight + 'px' }"
        >
          <slot :item="row.item" :index="row.index" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.vlist {
  overflow-y: auto;
  position: relative;
  contain: strict;
}
.vlist__spacer {
  position: relative;
  width: 100%;
}
.vlist__win {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  will-change: transform;
}
.vlist__row {
  box-sizing: border-box;
}
</style>
