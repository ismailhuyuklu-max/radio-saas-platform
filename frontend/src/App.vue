<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import { ConfigProvider } from 'ant-design-vue';
// Turkish locale powers ant-design-vue modal/Popconfirm/DatePicker/Empty
// strings — without this, defaults like "OK / Cancel / Today / Now / No
// Data" leak through in English.
import trTR from 'ant-design-vue/es/locale/tr_TR';

import { getStoredUser } from '#/api/modules/auth';

import AppShell from '#/components/layout/AppShell.vue';

const route = useRoute();

// Wrap authenticated pages in the navigation shell; the login page stays bare.
const useShell = computed(() => {
  void route.fullPath;
  return route.path !== '/login' && getStoredUser() !== null;
});
</script>

<template>
  <ConfigProvider :locale="trTR">
    <AppShell v-if="useShell">
      <router-view />
    </AppShell>
    <router-view v-else />
  </ConfigProvider>
</template>
