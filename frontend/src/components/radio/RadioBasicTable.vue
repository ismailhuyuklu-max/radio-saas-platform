<script lang="ts" setup>
import type { PropType } from 'vue';

import { Table, type TableProps } from 'ant-design-vue';

type TableColumn = NonNullable<TableProps['columns']>;

defineProps({
  title: {
    type: String,
    default: '',
  },
  description: {
    type: String,
    default: '',
  },
  columns: {
    type: Array as PropType<TableColumn>,
    required: true,
  },
  dataSource: {
    type: Array as PropType<Record<string, unknown>[]>,
    required: true,
  },
  rowKey: {
    type: String,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  pagination: {
    type: [Boolean, Object] as PropType<TableProps['pagination']>,
    default: () => ({ pageSize: 10 }),
  },
  scroll: {
    type: Object as PropType<TableProps['scroll']>,
    default: () => ({ x: 1000 }),
  },
});
</script>

<template>
  <section class="radio-basic-table">
    <div class="radio-basic-table__chrome">
      <div class="radio-basic-table__rail">
        <div class="radio-basic-table__rail-dot" />
        <div class="radio-basic-table__rail-dot is-green" />
        <div class="radio-basic-table__rail-dot is-blue" />
      </div>
      <div class="radio-basic-table__header">
        <div class="radio-basic-table__copy">
          <p v-if="description" class="radio-basic-table__eyebrow">{{ description }}</p>
          <h3 v-if="title" class="radio-basic-table__title">{{ title }}</h3>
        </div>
        <div class="radio-basic-table__actions">
          <slot name="actions" />
        </div>
      </div>
    </div>

    <div class="radio-basic-table__body">
      <Table
        :columns="columns"
        :data-source="dataSource"
        :row-key="rowKey"
        :loading="loading"
        :pagination="pagination"
        :scroll="scroll"
      />
    </div>
  </section>
</template>

<style scoped>
.radio-basic-table {
  display: grid;
  gap: 14px;
}

.radio-basic-table__chrome {
  display: grid;
  gap: 12px;
  padding: 18px 18px 16px;
  border-radius: 24px;
  background:
    radial-gradient(circle at top left, rgba(225, 29, 72, 0.08), transparent 34%),
    linear-gradient(180deg, rgba(10, 16, 29, 0.92), rgba(8, 15, 27, 0.9));
  border: 1px solid rgba(148, 163, 184, 0.14);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.03),
    0 18px 36px rgba(15, 23, 42, 0.14);
}

.radio-basic-table__rail {
  display: flex;
  align-items: center;
  gap: 8px;
}

.radio-basic-table__rail-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.42);
  box-shadow: 0 0 0 4px rgba(148, 163, 184, 0.04);
}

.radio-basic-table__rail-dot.is-green {
  background: rgba(16, 185, 129, 0.82);
}

.radio-basic-table__rail-dot.is-blue {
  background: rgba(59, 130, 246, 0.82);
}

.radio-basic-table__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  min-width: 0;
}

.radio-basic-table__copy {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.radio-basic-table__eyebrow {
  margin: 0;
  color: rgba(226, 232, 240, 0.72);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.radio-basic-table__title {
  margin: 0;
  color: #f8fafc;
  font-size: clamp(20px, 1.8vw, 24px);
  line-height: 1.08;
  letter-spacing: -0.03em;
}

.radio-basic-table__actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.radio-basic-table__body {
  border-radius: 24px;
  overflow: hidden;
  border: 1px solid rgba(148, 163, 184, 0.14);
  background: rgba(10, 16, 29, 0.82);
}

@media (max-width: 860px) {
  .radio-basic-table__header {
    flex-direction: column;
  }

  .radio-basic-table__actions {
    width: 100%;
  }
}
</style>
