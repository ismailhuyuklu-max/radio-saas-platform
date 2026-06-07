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
// Faz H5-4
import { focusMainContent } from '#/utils/a11y';

const route = useRoute();

// Wrap authenticated pages in the navigation shell; the login page stays bare.
const useShell = computed(() => {
  void route.fullPath;
  return route.path !== '/login' && getStoredUser() !== null;
});

function handleSkipLink(event: Event) {
  event.preventDefault();
  focusMainContent();
}
</script>

<template>
  <ConfigProvider :locale="trTR">
    <!-- Faz H5-4: Klavye kullanıcıları için skip-to-content. WCAG 2.4.1 -->
    <a href="#main-content" class="skip-link" @click="handleSkipLink">
      İçeriğe geç
    </a>
    <AppShell v-if="useShell">
      <main id="main-content" tabindex="-1">
        <router-view />
      </main>
    </AppShell>
    <main v-else id="main-content" tabindex="-1">
      <router-view />
    </main>
  </ConfigProvider>
</template>

<style>
/* Faz H5-4: skip-link sadece focus aldığında görünür (screen reader'a
   her zaman erişilebilir). top:0 yerine -40px → odaklanınca slide in. */
.skip-link {
  position: absolute;
  top: -48px;
  left: 12px;
  z-index: 9999;
  padding: 8px 16px;
  background: #2563eb;
  color: #fff;
  border-radius: 0 0 8px 8px;
  font-weight: 600;
  text-decoration: none;
  transition: top 120ms ease-out;
}
.skip-link:focus,
.skip-link:focus-visible {
  top: 0;
  outline: 3px solid #fbbf24;
  outline-offset: 0;
}
/* Main odaklanırken outline yok — focus indicator skip-link'in işi. */
#main-content:focus {
  outline: none;
}
</style>
