<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { getStoredUser, logout } from '#/api/modules/auth';

const route = useRoute();
const router = useRouter();

const currentUser = computed(() => {
  // Re-evaluate on every navigation so login/logout reflect immediately.
  void route.fullPath;
  return getStoredUser();
});

const showUserBar = computed(() => route.path !== '/login' && currentUser.value !== null);

async function handleLogout() {
  await logout();
  await router.replace({ path: '/login' });
}
</script>

<template>
  <div v-if="showUserBar" class="app-userbar">
    <span class="app-userbar__name">{{ currentUser?.realName || currentUser?.username }}</span>
    <button class="app-userbar__btn" type="button" @click="handleLogout">Çıkış</button>
  </div>
  <router-view />
</template>

<style scoped>
.app-userbar {
  position: fixed;
  top: 10px;
  right: 14px;
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px 6px 14px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.22);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28);
  backdrop-filter: blur(8px);
}

.app-userbar__name {
  color: #e2e8f0;
  font-size: 13px;
  font-weight: 600;
}

.app-userbar__btn {
  background: #e11d48;
  color: #fff;
  border: none;
  border-radius: 999px;
  padding: 4px 14px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.app-userbar__btn:hover {
  background: #be123c;
}
</style>
