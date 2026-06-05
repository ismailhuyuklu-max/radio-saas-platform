<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

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
  <AppShell v-if="useShell">
    <router-view />
  </AppShell>
  <router-view v-else />
</template>
