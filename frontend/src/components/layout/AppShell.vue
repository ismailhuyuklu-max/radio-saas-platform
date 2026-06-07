<script lang="ts" setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { getStoredUser, logout } from '#/api/modules/auth';
import { allows } from '#/utils/rbac';

interface NavItem {
  path: string;
  label: string;
  icon: string;
  perm?: string;
}

const route = useRoute();
const router = useRouter();

const drawerOpen = ref(false);

const allNavGroups: Array<{ title: string; items: NavItem[] }> = [
  {
    title: 'Genel',
    items: [
      { path: '/radio-platform/operations', label: 'Yayın Merkezi', icon: 'activity', perm: 'matrix:view' },
      { path: '/radio-platform/dashboard', label: 'Genel Bakış', icon: 'grid', perm: 'matrix:view' },
      { path: '/radio-platform/media-library', label: 'Medya Kütüphanesi', icon: 'music', perm: 'matrix:view' },
    ],
  },
  {
    title: 'Yayın Yönetimi',
    items: [
      { path: '/radio-platform/traffic-center', label: 'Yayın Trafik Merkezi', icon: 'target', perm: 'plans:write' },
      { path: '/radio-platform/timeline', label: 'Zaman Çizelgesi', icon: 'gantt', perm: 'plans:view' },
      { path: '/radio-platform/kanban', label: 'Haber Akışı', icon: 'kanban', perm: 'plans:view' },
      { path: '/radio-platform/planning', label: 'Planlama', icon: 'calendar', perm: 'plans:view' },
      { path: '/radio-platform/matrix', label: 'Bölgesel Durum', icon: 'map', perm: 'matrix:view' },
      { path: '/radio-platform/stations', label: 'İstasyonlar', icon: 'tower', perm: 'stations:view' },
      { path: '/radio-platform/sponsors', label: 'Sponsorlar', icon: 'megaphone', perm: 'sponsors:view' },
    ],
  },
  {
    title: 'Ticari',
    items: [
      { path: '/radio-platform/ad-traffic', label: 'Reklam Trafik', icon: 'trending', perm: 'ad:view' },
      { path: '/radio-platform/reports', label: 'Raporlar', icon: 'report', perm: 'reports:view' },
    ],
  },
  {
    title: 'Sistem',
    items: [
      { path: '/radio-platform/noc', label: 'Sistem İzleme', icon: 'server', perm: 'monitoring:view' },
      // Security is self-service for every authenticated user (own 2FA).
      { path: '/radio-platform/security', label: 'Güvenlik', icon: 'key' },
      { path: '/radio-platform/access', label: 'Yetki & Erişim', icon: 'shield', perm: 'users:manage' },
    ],
  },
];

// Hide nav items the current roles cannot use (backend still enforces).
const navGroups = computed(() => {
  void route.fullPath; // recompute on navigation (e.g. after login/role change)
  const roles = getStoredUser()?.roles ?? [];
  return allNavGroups
    .map((group) => ({
      title: group.title,
      items: group.items.filter((item) => !item.perm || allows(roles, item.perm)),
    }))
    .filter((group) => group.items.length > 0);
});

// Single-path line icons (24x24, stroked) — no icon dependency.
const ICONS: Record<string, string> = {
  grid: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
  activity: 'M3 12h4l2 6 4-14 2 8h6',
  music: 'M9 18V5l12-2v13 M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M21 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
  gantt: 'M4 5h10 M4 10h14 M4 15h7 M4 20h11',
  target: 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0-18 0 M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0-8 0 M12 12h.01',
  kanban: 'M4 4h4v16H4z M10 4h4v10h-4z M16 4h4v7h-4z',
  trending: 'M3 17l6-6 4 4 8-8 M15 7h6v6',
  map: 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2z M9 4v14 M15 6v14',
  calendar: 'M4 5h16v16H4z M4 9h16 M8 3v4 M16 3v4',
  tower: 'M12 13v8 M8 9a5 5 0 0 1 8 0 M5 6a9 9 0 0 1 14 0 M12 12a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  megaphone: 'M4 10v4h3l7 4V6L7 10z M17 9a4 4 0 0 1 0 6',
  shield: 'M12 3l8 3v6c0 5-3.4 8-8 9-4.6-1-8-4-8-9V6z',
  key: 'M14 7a4 4 0 1 0-3.8 5.3L7 15.5V18h2.5l.5-.5.7-.7.8-.8a4 4 0 0 0 2.5-9z M16 8.5h.01',
  server: 'M4 5h16v5H4z M4 14h16v5H4z M7 7.5h.01 M7 16.5h.01',
  report: 'M6 3h9l5 5v13H6z M14 3v6h6 M12 12v6 M9 15l3 3 3-3',
};

const allItems = computed(() => navGroups.value.flatMap((group) => group.items));

const currentItem = computed(() =>
  allItems.value.find((item) => route.path.startsWith(item.path)),
);

const pageTitle = computed(() => currentItem.value?.label ?? 'AdCast Pro');

const user = computed(() => {
  void route.fullPath;
  return getStoredUser();
});

const initials = computed(() => {
  const name = user.value?.realName || user.value?.username || 'A';
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toLocaleUpperCase('tr-TR'))
    .join('');
});

function isActive(path: string): boolean {
  return route.path.startsWith(path);
}

function go(path: string) {
  drawerOpen.value = false;
  if (!route.path.startsWith(path)) {
    void router.push(path);
  }
}

async function handleLogout() {
  drawerOpen.value = false;
  await logout();
  await router.replace({ path: '/login' });
}

watch(() => route.fullPath, () => {
  drawerOpen.value = false;
});
</script>

<template>
  <div class="app-shell">
    <!-- Sidebar (desktop) / Drawer (mobile) -->
    <aside class="app-sidebar" :class="{ 'is-open': drawerOpen }">
      <div class="app-brand">
        <!-- REBRAND: gerçek PNG logo wordmark (1554×519 — wide format).
             Logo zaten "Ad Cast Pro" yazısını içerdiği için yanına ek başlık
             koymuyoruz; alt-text "Yayın Yönetimi" tek satır olarak kalıyor. -->
        <img
          src="/adcastpro-logo.png"
          alt="AdCast Pro"
          class="app-brand__mark"
        />
        <div class="app-brand__text">
          <span>Yayın Yönetimi</span>
        </div>
      </div>

      <nav class="app-nav">
        <div v-for="group in navGroups" :key="group.title" class="app-nav__group">
          <p class="app-nav__title">{{ group.title }}</p>
          <button
            v-for="item in group.items"
            :key="item.path"
            type="button"
            class="app-nav__item"
            :class="{ 'is-active': isActive(item.path) }"
            @click="go(item.path)"
          >
            <svg class="app-nav__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path :d="ICONS[item.icon]" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>{{ item.label }}</span>
          </button>
        </div>
      </nav>

      <div class="app-sidebar__foot">
        <div class="app-user">
          <span class="app-user__avatar">{{ initials }}</span>
          <div class="app-user__meta">
            <strong>{{ user?.realName || user?.username }}</strong>
            <span>{{ (user?.roles && user.roles[0]) || 'kullanıcı' }}</span>
          </div>
        </div>
        <button type="button" class="app-logout" @click="handleLogout">Çıkış</button>
      </div>
    </aside>

    <!-- Mobile overlay -->
    <div v-if="drawerOpen" class="app-overlay" @click="drawerOpen = false" />

    <!-- Main column -->
    <div class="app-main">
      <header class="app-topbar">
        <button
          type="button"
          class="app-burger"
          aria-label="Menü"
          @click="drawerOpen = !drawerOpen"
        >
          <span /><span /><span />
        </button>
        <h1 class="app-topbar__title">{{ pageTitle }}</h1>
        <button type="button" class="app-topbar__user" :title="user?.realName || ''" @click="handleLogout">
          <span class="app-user__avatar app-user__avatar--sm">{{ initials }}</span>
        </button>
      </header>

      <main class="app-content">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.app-shell {
  /* Faz UX-sidebar: 248 → 220, ekran genişliğinde %11 geri */
  --sidebar-w: 220px;
  min-height: 100vh;
  width: 100%;
}

/* ---------- Mobile first ---------- */
.app-sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  z-index: 60;
  width: min(82vw, 260px);
  transform: translateX(-102%);
  transition: transform 260ms cubic-bezier(0.22, 1, 0.36, 1);
  display: flex;
  flex-direction: column;
  gap: 2px;
  /* Faz UX-sidebar: dış padding minimal, nav alanına maks. yer */
  padding: 10px 8px 8px;
  background: linear-gradient(180deg, #0c1426 0%, #0a1120 100%);
  border-right: 1px solid rgba(148, 163, 184, 0.12);
  box-shadow: 0 30px 80px rgba(2, 6, 23, 0.6);
}

.app-sidebar.is-open {
  transform: translateX(0);
}

.app-overlay {
  position: fixed;
  inset: 0;
  z-index: 55;
  background: rgba(2, 6, 23, 0.6);
  backdrop-filter: blur(2px);
}

/* Faz UX-sidebar: tüm 13 nav item + brand + foot 720p ekrana sığar.
   Yalnız sidebar dokunuldu; sayfa içerikleri ve diğer her şey aynı. */
.app-brand {
  /* REBRAND: wide-format wordmark → column layout (logo üstte, alt-text altta).
     Önceden row + emoji + iki satır text vardı; logo zaten "Ad Cast Pro"
     wordmark'ını içeriyor. */
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 4px 8px 10px;
}

.app-brand__mark {
  /* REBRAND: wordmark + slogan (~3:2 aspect) yükseklik bazlı.
     Sidebar genişliğine sığsın diye max-width %100. Transparent PNG,
     drop-shadow ile brand-red glow korunur. 56px slogan'ı da görünür kılar. */
  display: block;
  height: 56px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  filter: drop-shadow(0 2px 8px rgba(225, 29, 72, 0.35));
}

.app-brand__text {
  display: flex;
  flex-direction: column;
  line-height: 1.1;
  margin-top: 2px;
}

.app-brand__text span {
  color: rgba(148, 163, 184, 0.9);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.app-nav {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
  overflow-y: auto;
  /* Scrollbar gerek kalmazsa görsel olarak yok denecek kadar ince
     (gerek kalırsa diye tamamen kapatmıyoruz). */
  scrollbar-width: thin;
  scrollbar-color: rgba(148, 163, 184, 0.2) transparent;
}
.app-nav::-webkit-scrollbar {
  width: 4px;
}
.app-nav::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.2);
  border-radius: 2px;
}
.app-nav::-webkit-scrollbar-track {
  background: transparent;
}

.app-nav__group {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.app-nav__title {
  margin: 2px 8px 2px;
  color: rgba(148, 163, 184, 0.7);
  font-size: 9px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.app-nav__item {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 5px 9px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: rgba(203, 213, 225, 0.86);
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  text-align: left;
  transition: background 150ms ease, color 150ms ease, border-color 150ms ease;
}

.app-nav__item:hover {
  background: rgba(148, 163, 184, 0.08);
  color: #f1f5f9;
}

.app-nav__item.is-active {
  background: linear-gradient(135deg, rgba(225, 29, 72, 0.18), rgba(225, 29, 72, 0.06));
  border-color: rgba(225, 29, 72, 0.35);
  color: #fff;
  box-shadow: inset 2px 0 0 #e11d48;
}

.app-nav__icon {
  width: 15px;
  height: 15px;
  flex-shrink: 0;
}

.app-sidebar__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding: 6px 4px 2px;
  margin-top: 4px;
  border-top: 1px solid rgba(148, 163, 184, 0.12);
}

.app-user {
  display: flex;
  align-items: center;
  gap: 7px;
  min-width: 0;
}

.app-user__avatar {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  border-radius: 8px;
  background: linear-gradient(135deg, #e11d48, #fb7185);
  color: #fff;
  font-size: 11px;
  font-weight: 800;
}

.app-user__avatar--sm {
  width: 26px;
  height: 26px;
  border-radius: 7px;
  font-size: 11px;
}

.app-user__meta {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}

.app-user__meta strong {
  color: #f1f5f9;
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.app-user__meta span {
  color: rgba(148, 163, 184, 0.85);
  font-size: 10px;
  text-transform: capitalize;
}

.app-logout {
  flex-shrink: 0;
  padding: 4px 10px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.6);
  color: rgba(226, 232, 240, 0.9);
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  transition: background 150ms ease, border-color 150ms ease;
}

.app-logout:hover {
  background: rgba(225, 29, 72, 0.16);
  border-color: rgba(225, 29, 72, 0.5);
  color: #fff;
}

.app-main {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.app-topbar {
  position: sticky;
  top: 0;
  z-index: 40;
  display: flex;
  align-items: center;
  gap: 12px;
  height: 58px;
  padding: 0 14px;
  background: rgba(9, 13, 22, 0.86);
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  backdrop-filter: blur(14px);
}

.app-burger {
  display: inline-flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
  width: 38px;
  height: 38px;
  padding: 0 9px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  border-radius: 11px;
  background: rgba(15, 23, 42, 0.5);
  cursor: pointer;
}

.app-burger span {
  height: 2px;
  border-radius: 2px;
  background: #e2e8f0;
}

.app-topbar__title {
  flex: 1;
  margin: 0;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: 17px;
  font-weight: 800;
  letter-spacing: -0.01em;
  color: #f8fafc;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.app-topbar__user {
  border: none;
  background: transparent;
  padding: 0;
  cursor: pointer;
}

.app-content {
  flex: 1;
  padding: 14px;
  min-width: 0;
}

/* ---------- Tablet ---------- */
@media (min-width: 768px) {
  .app-content {
    padding: 20px;
  }

  .app-topbar {
    height: 62px;
    padding: 0 20px;
  }

  .app-topbar__title {
    font-size: 19px;
  }
}

/* ---------- Desktop ---------- */
@media (min-width: 1024px) {
  .app-shell {
    display: grid;
    grid-template-columns: var(--sidebar-w) 1fr;
  }

  .app-sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    width: var(--sidebar-w);
    transform: none;
    box-shadow: none;
  }

  .app-overlay {
    display: none;
  }

  .app-burger {
    display: none;
  }

  .app-topbar__user {
    display: none;
  }

  .app-content {
    padding: 26px 28px 40px;
  }
}
</style>
